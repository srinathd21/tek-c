<?php
// add-employee.php (HR can add employees + uploads stored in ../admin/uploads/*)
session_start();
require_once 'includes/db-config.php';

$success = '';
$error = '';
$validation_errors = [];

// Get database connection
$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

/**
 * ------------------------------------------------------------
 * AUTH: Allow HR and Admin to add employees
 * - HR if designation/department contains HR
 * - Admin if designation is admin/director/VP/GM
 * ------------------------------------------------------------
 */
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = (int)$_SESSION['employee_id'];
$designation = trim((string)($_SESSION['designation'] ?? ''));
$department  = trim((string)($_SESSION['department'] ?? ''));

function roleKeyFromDesignation(string $designation, string $department = ''): string {
    $d = strtolower(trim($designation));
    $dept = strtolower(trim($department));

    if (
        str_contains($d, 'admin') ||
        str_contains($d, 'administrator') ||
        str_contains($d, 'director') ||
        str_contains($d, 'vice president') ||
        str_contains($d, 'general manager')
    ) return 'admin';

    if (str_contains($d, 'hr') || str_contains($dept, 'hr') || str_contains($dept, 'human resource')) {
        return 'hr';
    }

    return 'other';
}

$currentRoleKey = roleKeyFromDesignation($designation, $department);
$isHr = ($currentRoleKey === 'hr');
$isAdmin = ($currentRoleKey === 'admin');

if (!in_array($currentRoleKey, ['hr', 'admin'], true)) {
    $fallback = $_SESSION['role_redirect'] ?? '../login.php';
    header("Location: " . $fallback);
    exit;
}

// Helpers
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function createNotificationCurrentDb(
    $conn,
    int $employeeId,
    string $title,
    string $message,
    string $module,
    int $referenceId,
    string $link,
    string $type = 'employee'
): bool {
    if ($employeeId <= 0 || !$conn || !tableExists($conn, 'notifications')) {
        return false;
    }

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $map = [
        'employee_id'  => ['i', $employeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', $type],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'priority'     => ['s', 'normal'],
        'is_read'      => ['i', 0],
        'created_at'   => ['raw', 'NOW()'],
    ];

    foreach ($map as $column => $pair) {
        if (columnExists($conn, 'notifications', $column)) {
            $columns[] = "`$column`";
            if ($pair[0] === 'raw') {
                $placeholders[] = $pair[1];
            } else {
                $placeholders[] = '?';
                $types .= $pair[0];
                $values[] = $pair[1];
            }
        }
    }

    if (!$columns) return false;

    $sql = "INSERT INTO notifications (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    if ($values) {
        mysqli_stmt_bind_param($stmt, $types, ...$values);
    }

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function logEmployeeActivity($conn, int $actorId, string $activityType, string $description, int $referenceId, array $newData = []): bool {
    if (!$conn || !tableExists($conn, 'activity_logs')) return false;

    $newJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;
    $employeeName = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'System';
    $username = $_SESSION['username'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $department = $_SESSION['department'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $map = [
        'employee_id'   => ['i', $actorId],
        'employee_name' => ['s', $employeeName],
        'username'      => ['s', $username],
        'designation'   => ['s', $designation],
        'department'    => ['s', $department],
        'activity_type' => ['s', $activityType],
        'module'        => ['s', 'employees'],
        'description'   => ['s', $description],
        'reference_id'  => ['i', $referenceId],
        'new_data'      => ['s', $newJson],
        'ip_address'    => ['s', $ipAddress],
    ];

    $cols = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (columnExists($conn, 'activity_logs', $column)) {
            $cols[] = "`$column`";
            $types .= $pair[0];
            $values[] = $pair[1];
        }
    }

    if (!$cols) return false;

    $sql = "INSERT INTO activity_logs (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function getAdminEmployees($conn): array {
    $admins = [];
    $sql = "SELECT id, full_name
            FROM employees
            WHERE employee_status = 'active'
              AND (
                    LOWER(COALESCE(designation,'')) LIKE '%admin%'
                 OR LOWER(COALESCE(designation,'')) LIKE '%administrator%'
                 OR LOWER(COALESCE(designation,'')) LIKE '%director%'
                 OR LOWER(COALESCE(designation,'')) LIKE '%vice president%'
                 OR LOWER(COALESCE(designation,'')) LIKE '%general manager%'
              )
            ORDER BY full_name";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $admins[] = $row;
        }
        mysqli_free_result($res);
    }
    return $admins;
}


// Departments and designations arrays (match your ENUM values)
$departments = ['PM', 'CM', 'IFM', 'QS', 'HR', 'ACCOUNTS'];
$designations = [
    'Vice President',
    'General Manager',
    'Director',
    'Manager',
    'QS Manager',
    'QS Engineer',
    'QS & Contracts',
    'Team Lead',
    'Sr. Engineer',
    'Project Engineer Grade 1',
    'Project Engineer Grade 2',
    'HR',
    'Accountant'
];
$genders = ['Male', 'Female', 'Other'];
$blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$employee_statuses = ['active', 'inactive', 'resigned'];

/**
 * ------------------------------------------------------------
 * ✅ Upload base: ../admin (filesystem)
 * Save physically in: ../admin/uploads/...
 * Save DB path as: admin/uploads/...
 * ------------------------------------------------------------
 */
function getAdminFsBase(): string {
    // EXACT requirement: store in ../admin
    $adminDir = __DIR__ . '/../admin';

    if (!is_dir($adminDir)) {
        @mkdir($adminDir, 0777, true);
    }

    $real = realpath($adminDir);
    return $real ?: $adminDir;
}

$adminFsBase  = getAdminFsBase();   // filesystem path to ../admin
$adminWebBase = 'admin';            // DB/web path prefix

/**
 * ✅ Handle file uploads into ../admin/uploads/<subdir>/
 * Returns: ['success'=>true, 'path'=>'admin/uploads/.../file.png'] OR error
 */
function handleFileUpload($field_name, $subdir, $adminFsBase, $adminWebBase) {
    if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
        return ['success' => false, 'error' => 'No file selected'];
    }

    $file = $_FILES[$field_name];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $code = (int)($file['error'] ?? -1);
        return ['success' => false, 'error' => 'File upload error: ' . $code];
    }

    // Validate size (max 5MB)
    $file_size = (int)($file['size'] ?? 0);
    if ($file_size <= 0) return ['success' => false, 'error' => 'Invalid file size'];
    if ($file_size > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File size too large (max 5MB)'];
    }

    // Validate extension
    $file_name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        return ['success' => false, 'error' => 'Only image files are allowed (jpg, jpeg, png, gif, webp)'];
    }

    // Must be real image
    $tmp = (string)($file['tmp_name'] ?? '');
    $imgInfo = @getimagesize($tmp);
    if ($imgInfo === false) {
        return ['success' => false, 'error' => 'Invalid image file'];
    }

    // Normalize subdir
    $subdir = trim((string)$subdir);
    $subdir = trim($subdir, "/") . "/";

    // Filesystem target: ../admin/uploads/<subdir>
    $uploadFsDir = rtrim($adminFsBase, "/\\") .
        DIRECTORY_SEPARATOR . 'uploads' .
        DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);

    if (!is_dir($uploadFsDir)) {
        if (!@mkdir($uploadFsDir, 0777, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory'];
        }
    }

    // Unique filename
    try {
        $rand = bin2hex(random_bytes(6));
    } catch (Throwable $t) {
        $rand = uniqid();
    }
    $new_file_name = 'file_' . $rand . '_' . time() . '.' . $ext;

    $targetFsPath = rtrim($uploadFsDir, "/\\") . DIRECTORY_SEPARATOR . $new_file_name;

    if (!move_uploaded_file($tmp, $targetFsPath)) {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }

    // DB/web path: admin/uploads/<subdir>/<file>
    $webPath = rtrim($adminWebBase, "/") . '/uploads/' . $subdir . $new_file_name;

    return ['success' => true, 'path' => $webPath];
}

// Function to get existing employees for reporting manager dropdown
function getExistingEmployees($conn) {
    $employees = [];
    $result = mysqli_query($conn, "SELECT id, full_name, employee_code, designation FROM employees WHERE employee_status = 'active' ORDER BY full_name");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $employees[] = $row;
        }
        mysqli_free_result($result);
    }
    return $employees;
}

$existing_employees = getExistingEmployees($conn);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect all form data with default empty strings
    $full_name = trim($_POST['full_name'] ?? '');
    $employee_code = trim($_POST['employee_code'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $blood_group = trim($_POST['blood_group'] ?? '');

    $mobile_number = trim($_POST['mobile_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $current_address = trim($_POST['current_address'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');

    $date_of_joining = trim($_POST['date_of_joining'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $designationSel = trim($_POST['designation'] ?? '');
    $reporting_manager = trim($_POST['reporting_manager'] ?? '');
    $work_location = trim($_POST['work_location'] ?? '');
    $site_name = trim($_POST['site_name'] ?? '');
    $employee_status = trim($_POST['employee_status'] ?? 'active');

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $aadhar_card_number = trim($_POST['aadhar_card_number'] ?? '');
    $pancard_number = trim($_POST['pancard_number'] ?? '');

    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $ifsc_code = trim($_POST['ifsc_code'] ?? '');

    // Validate required fields (ONLY ESSENTIAL ONES)
    if (empty($full_name)) $validation_errors[] = "Full name is required";
    if (empty($employee_code)) $validation_errors[] = "Employee code is required";
    if (empty($mobile_number)) $validation_errors[] = "Mobile number is required";
    if (empty($date_of_joining)) $validation_errors[] = "Date of joining is required";
    if (empty($department)) $validation_errors[] = "Department is required";
    if (empty($designationSel)) $validation_errors[] = "Designation is required";
    if (empty($username)) $validation_errors[] = "Username is required";
    if (empty($password)) $validation_errors[] = "Password is required";

    // Validate mobile number format
    if (!empty($mobile_number) && !preg_match('/^[0-9]{10}$/', $mobile_number)) {
        $validation_errors[] = "Mobile number must be 10 digits";
    }

    // Validate email if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = "Invalid email format";
    }

    // Validate Aadhar number if provided
    if (!empty($aadhar_card_number) && !preg_match('/^[0-9]{12}$/', $aadhar_card_number)) {
        $validation_errors[] = "Aadhar number must be 12 digits";
    }

    // Validate PAN card number if provided
    if (!empty($pancard_number) && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pancard_number)) {
        $validation_errors[] = "PAN card number must be in format: ABCDE1234F";
    }

    // Validate designation matches allowed list
    if (!empty($designationSel) && !in_array($designationSel, $designations, true)) {
        $validation_errors[] = "Invalid designation selected";
    }

    // Check if employee code already exists
    if (!empty($employee_code)) {
        $check_stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE employee_code = ? LIMIT 1");
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "s", $employee_code);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $validation_errors[] = "Employee code already exists";
            }
            mysqli_stmt_close($check_stmt);
        }
    }

    // Check if username already exists
    if (!empty($username)) {
        $check_stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE username = ? LIMIT 1");
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "s", $username);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $validation_errors[] = "Username already exists. Please choose a different username.";
            }
            mysqli_stmt_close($check_stmt);
        }
    }

    // Handle file uploads (optional) - now stored in ../admin/uploads/...
    $photo = '';
    $passbook_photo = '';

    if (!empty($_FILES['photo']['name'])) {
        $photo_upload = handleFileUpload('photo', 'employees/photos', $adminFsBase, $adminWebBase);
        if (!empty($photo_upload['success'])) {
            $photo = $photo_upload['path']; // ✅ admin/uploads/...
        } else {
            $validation_errors[] = $photo_upload['error'] ?? 'Photo upload failed';
        }
    }

    if (!empty($_FILES['passbook_photo']['name'])) {
        $passbook_upload = handleFileUpload('passbook_photo', 'employees/passbook', $adminFsBase, $adminWebBase);
        if (!empty($passbook_upload['success'])) {
            $passbook_photo = $passbook_upload['path']; // ✅ admin/uploads/...
        } else {
            $validation_errors[] = $passbook_upload['error'] ?? 'Passbook upload failed';
        }
    }

    // If no validation errors, insert into database
    if (empty($validation_errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO employees (
            full_name, employee_code, photo, date_of_birth, gender, blood_group,
            mobile_number, email, current_address, emergency_contact_name, emergency_contact_phone,
            date_of_joining, department, designation, reporting_manager, work_location, site_name, employee_status,
            username, password,
            aadhar_card_number, pancard_number,
            bank_account_number, ifsc_code, passbook_photo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sssssssssssssssssssssssss",
                $full_name, $employee_code, $photo, $date_of_birth, $gender, $blood_group,
                $mobile_number, $email, $current_address, $emergency_contact_name, $emergency_contact_phone,
                $date_of_joining, $department, $designationSel, $reporting_manager, $work_location, $site_name, $employee_status,
                $username, $hashed_password,
                $aadhar_card_number, $pancard_number,
                $bank_account_number, $ifsc_code, $passbook_photo
            );

            if (mysqli_stmt_execute($stmt)) {
                $new_employee_id = (int)mysqli_insert_id($conn);

                logEmployeeActivity(
                    $conn,
                    $current_employee_id,
                    'CREATE',
                    'Added new employee: ' . $full_name . ' (' . $employee_code . ')',
                    $new_employee_id,
                    [
                        'employee_id' => $new_employee_id,
                        'full_name' => $full_name,
                        'employee_code' => $employee_code,
                        'department' => $department,
                        'designation' => $designationSel,
                        'employee_status' => $employee_status,
                        'created_by_role' => $currentRoleKey
                    ]
                );

                // When HR adds employee, notify all active Admin users.
                if ($isHr) {
                    $admins = getAdminEmployees($conn);
                    foreach ($admins as $admin) {
                        $adminId = (int)$admin['id'];
                        if ($adminId <= 0 || $adminId === $current_employee_id) {
                            continue;
                        }

                        createNotificationCurrentDb(
                            $conn,
                            $adminId,
                            'New employee added',
                            'HR added new employee ' . $full_name . ' (' . $employee_code . ').',
                            'employees',
                            $new_employee_id,
                            'employees.php',
                            'employee'
                        );
                    }
                }

                $success = "Employee added successfully!";
                $_POST = [];
            } else {
                $error = "Error adding employee: " . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = "Database error: " . mysqli_error($conn);
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Add Employee - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
    :root{
        --page-bg:#f5f7fb;
        --card-bg:#ffffff;
        --border:#e5e7eb;
        --text:#111827;
        --muted:#6b7280;
        --soft:#f8fafc;
        --shadow:0 10px 26px rgba(15,23,42,.055);
        --radius:15px;
        --blue:#2f80ed;
        --green:#27ae60;
        --orange:#f2994a;
        --red:#eb5757;
        --purple:#7c3aed;
    }

    body{background:var(--page-bg);}
    .content-scroll{flex:1 1 auto;overflow:auto;padding:16px;}
    .projects-wrapper{width:100%;}

    .page-heading{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
        margin-bottom:14px;
    }

    .page-heading h1{
        font-size:19px;
        font-weight:950;
        color:var(--text);
        margin:0;
    }

    .page-heading p{
        margin:3px 0 0;
        color:var(--muted);
        font-size:12px;
        font-weight:650;
    }

    .primary-btn,.secondary-btn,.submit-btn{
        min-height:36px;
        padding:0 14px;
        border-radius:11px;
        font-size:12px;
        font-weight:900;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:7px;
        text-decoration:none;
        white-space:nowrap;
        border:0;
        line-height:1;
    }

    .primary-btn,.submit-btn{background:#111827;color:#fff;}
    .primary-btn:hover,.submit-btn:hover{background:#020617;color:#fff;}

    .secondary-btn{
        border:1px solid var(--border);
        background:#fff;
        color:#334155;
    }

    .secondary-btn:hover{
        border-color:#cbd5e1;
        background:#f8fafc;
        color:#111827;
    }

    .badge-pill{
        border-radius:999px;
        padding:5px 8px;
        font-weight:900;
        font-size:10px;
        display:inline-flex;
        align-items:center;
        gap:6px;
        border:1px solid transparent;
        text-decoration:none;
        white-space:nowrap;
    }

    .badge-role{
        color:#2563eb;
        background:#dbeafe;
        border-color:#bfdbfe;
    }

    .form-panel{
        background:var(--card-bg);
        border:1px solid var(--border);
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        padding:13px;
        margin-bottom:14px;
    }

    .section-header{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        margin-bottom:12px;
        padding-bottom:10px;
        border-bottom:1px solid #eef2f7;
    }

    .section-header-left{
        display:flex;
        align-items:center;
        gap:10px;
    }

    .section-icon{
        width:38px;
        height:38px;
        border-radius:12px;
        background:#111827;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:16px;
        color:white;
        flex:0 0 auto;
    }

    .section-icon.blue{background:var(--blue);}
    .section-icon.red{background:var(--red);}
    .section-icon.green{background:var(--green);}
    .section-icon.orange{background:var(--orange);}
    .section-icon.purple{background:var(--purple);}

    .section-title{
        font-size:14px;
        font-weight:950;
        color:#111827;
        margin:0;
    }

    .section-subtitle{
        font-size:11px;
        color:#64748b;
        margin-top:2px;
        font-weight:700;
    }

    .form-label{
        font-size:11px;
        font-weight:900;
        color:#475569;
        text-transform:uppercase;
        margin-bottom:6px;
    }

    .required-label::after{
        content:" *";
        color:var(--red);
        font-weight:950;
    }

    .form-control,.form-select{
        min-height:38px;
        border:1px solid var(--border);
        border-radius:11px;
        font-size:12px;
        font-weight:800;
        color:#111827;
        padding:8px 11px;
        background:#fff;
    }

    .form-control:focus,.form-select:focus{
        border-color:#bfdbfe;
        box-shadow:0 0 0 3px rgba(59,130,246,.10);
    }

    textarea.form-control{min-height:78px;}

    .optional-badge{
        font-size:10px;
        color:#94a3b8;
        font-weight:800;
        text-transform:none;
        margin-left:4px;
    }

    .form-section-heading{
        font-size:12px;
        font-weight:950;
        color:#111827;
        margin:12px 0 4px;
        padding-left:10px;
        border-left:4px solid var(--blue);
    }

    .file-upload-container{
        border:1.5px dashed #cbd5e1;
        border-radius:14px;
        padding:18px;
        text-align:center;
        background:#f8fafc;
        cursor:pointer;
        transition:.15s ease;
        margin-top:5px;
    }

    .file-upload-container:hover{
        border-color:#93c5fd;
        background:#eff6ff;
    }

    .file-upload-icon{
        font-size:30px;
        color:#94a3b8;
        margin-bottom:8px;
    }

    .file-upload-text{
        color:#475569;
        font-weight:900;
        font-size:12px;
    }

    .file-upload-subtext{
        color:#94a3b8;
        font-weight:700;
        font-size:10.5px;
        margin-top:2px;
    }

    .file-preview{
        width:120px;
        height:120px;
        border-radius:14px;
        overflow:hidden;
        margin:10px auto 0;
        border:1px solid var(--border);
        background:white;
        position:relative;
        box-shadow:var(--shadow);
    }

    .file-preview img{
        width:100%;
        height:100%;
        object-fit:cover;
    }

    .file-remove{
        position:absolute;
        top:6px;
        right:6px;
        width:26px;
        height:26px;
        background:#dc2626;
        color:white;
        border-radius:999px;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:14px;
        cursor:pointer;
        border:2px solid white;
    }

    .input-group .form-control{
        border-top-right-radius:0;
        border-bottom-right-radius:0;
    }

    .input-group .icon-btn{
        width:40px;
        min-height:38px;
        border:1px solid var(--border);
        background:#fff;
        color:#334155;
        display:inline-flex;
        align-items:center;
        justify-content:center;
    }

    .input-group .icon-btn:hover{
        background:#f8fafc;
        color:#111827;
    }

    .alert{
        border-radius:14px;
        border:1px solid transparent;
        box-shadow:var(--shadow);
        margin-bottom:14px;
        font-size:12px;
        font-weight:850;
    }

    .alert-success{background:#dcfce7;border-color:#bbf7d0;color:#166534;}
    .alert-danger{background:#fee2e2;border-color:#fecaca;color:#991b1b;}
    .alert-warning{background:#fffbeb;border-color:#fde68a;color:#92400e;}

    .submit-strip{
        background:#fff;
        border:1px solid var(--border);
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        padding:13px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        margin:14px 0;
    }

    .submit-note{
        color:#64748b;
        font-size:11px;
        font-weight:750;
        margin:0;
    }

    @media(max-width:991.98px){
        .main{margin-left:0!important;width:100%!important;max-width:100%!important;}
        .sidebar{position:fixed!important;transform:translateX(-100%);z-index:1040!important;}
        .sidebar.open,.sidebar.active,.sidebar.show{transform:translateX(0)!important;}
    }

    @media(max-width:768px){
        .content-scroll{padding:12px 10px!important;}
        .container-fluid.projects-wrapper{padding-left:0!important;padding-right:0!important;}
        .page-heading,.submit-strip{flex-direction:column;align-items:flex-start;}
        .form-panel{padding:12px;}
        .section-header{align-items:flex-start;}
        .primary-btn,.secondary-btn,.submit-btn{width:100%;}
    }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid projects-wrapper px-0">
                <div class="page-heading">
                    <div>
                        <div class="d-flex gap-2 align-items-center flex-wrap mb-1">
                            <h1>Add New Employee</h1>
                            <span class="badge-pill badge-role">
                                <i class="bi bi-shield-check"></i>
                                <?php echo strtoupper($currentRoleKey); ?>
                            </span>
                        </div>
                        <p>Register a new employee, upload documents, and create system login credentials.</p>
                    </div>

                    <a href="employees.php" class="secondary-btn">
                        <i class="bi bi-arrow-left"></i> Back to Directory
                    </a>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <strong>Success!</strong> <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Error!</strong> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($validation_errors)): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Please fix the following errors:</strong>
                        <ul class="mb-0 mt-2 ps-3">
                            <?php foreach ($validation_errors as $err): ?>
                                <li><?php echo e($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="employeeForm" novalidate>
                    <!-- Identity -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-header-left">
                                <div class="section-icon blue"><i class="bi bi-person-badge"></i></div>
                                <div>
                                    <h3 class="section-title">Identity Details</h3>
                                    <p class="section-subtitle">Basic personal information</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label required-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name"
                                       value="<?php echo e($_POST['full_name'] ?? ''); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="employee_code" class="form-label required-label">Employee Code</label>
                                <input type="text" class="form-control" id="employee_code" name="employee_code"
                                       value="<?php echo e($_POST['employee_code'] ?? ''); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="date_of_birth" class="form-label">Date of Birth <span class="optional-badge">(Optional)</span></label>
                                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                                       value="<?php echo e($_POST['date_of_birth'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="gender" class="form-label">Gender <span class="optional-badge">(Optional)</span></label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="">Select Gender</option>
                                    <?php foreach ($genders as $g): ?>
                                        <option value="<?php echo e($g); ?>" <?php echo (($_POST['gender'] ?? '') === $g) ? 'selected' : ''; ?>>
                                            <?php echo e($g); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="blood_group" class="form-label">Blood Group <span class="optional-badge">(Optional)</span></label>
                                <select class="form-select" id="blood_group" name="blood_group">
                                    <option value="">Select Blood Group</option>
                                    <?php foreach ($blood_groups as $bg): ?>
                                        <option value="<?php echo e($bg); ?>" <?php echo (($_POST['blood_group'] ?? '') === $bg) ? 'selected' : ''; ?>>
                                            <?php echo e($bg); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="photo" class="form-label">Profile Photo <span class="optional-badge">(Optional)</span></label>
                                <div class="file-upload-container" onclick="document.getElementById('photo').click()" id="photoUploadContainer">
                                    <div class="file-upload-icon"><i class="bi bi-person-square"></i></div>
                                    <div class="file-upload-text">Click to upload photo</div>
                                    <div class="file-upload-subtext">JPG, PNG, WebP (Max 5MB)</div>
                                    <input type="file" class="d-none" id="photo" name="photo" accept="image/*">
                                </div>
                                <div class="file-preview d-none" id="photoPreview">
                                    <img src="" alt="Photo Preview">
                                    <div class="file-remove" onclick="removeFile('photo')"><i class="bi bi-x"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-header-left">
                                <div class="section-icon red"><i class="bi bi-telephone"></i></div>
                                <div>
                                    <h3 class="section-title">Contact Details</h3>
                                    <p class="section-subtitle">Communication information</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="mobile_number" class="form-label required-label">Mobile Number</label>
                                <input type="tel" class="form-control" id="mobile_number" name="mobile_number"
                                       value="<?php echo e($_POST['mobile_number'] ?? ''); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address <span class="optional-badge">(Optional)</span></label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo e($_POST['email'] ?? ''); ?>">
                            </div>

                            <div class="col-12">
                                <label for="current_address" class="form-label">Current Address <span class="optional-badge">(Optional)</span></label>
                                <textarea class="form-control" id="current_address" name="current_address" rows="2"><?php echo e($_POST['current_address'] ?? ''); ?></textarea>
                            </div>

                            <h5 class="form-section-heading">Emergency Contact <span class="optional-badge">(Optional)</span></h5>
                            <div class="col-md-6">
                                <label for="emergency_contact_name" class="form-label">Contact Name</label>
                                <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name"
                                       value="<?php echo e($_POST['emergency_contact_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="emergency_contact_phone" class="form-label">Contact Phone</label>
                                <input type="tel" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone"
                                       value="<?php echo e($_POST['emergency_contact_phone'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Employment -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-header-left">
                                <div class="section-icon orange"><i class="bi bi-briefcase"></i></div>
                                <div>
                                    <h3 class="section-title">Employment Details</h3>
                                    <p class="section-subtitle">Professional information</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="date_of_joining" class="form-label required-label">Date of Joining</label>
                                <input type="date" class="form-control" id="date_of_joining" name="date_of_joining"
                                       value="<?php echo e($_POST['date_of_joining'] ?? ''); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="department" class="form-label required-label">Department</label>
                                <select class="form-select" id="department" name="department" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo e($dept); ?>" <?php echo (($_POST['department'] ?? '') === $dept) ? 'selected' : ''; ?>>
                                            <?php echo e($dept); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="designation" class="form-label required-label">Designation</label>
                                <select class="form-select" id="designation" name="designation" required>
                                    <option value="">Select Designation</option>
                                    <?php foreach ($designations as $des): ?>
                                        <option value="<?php echo e($des); ?>" <?php echo (($_POST['designation'] ?? '') === $des) ? 'selected' : ''; ?>>
                                            <?php echo e($des); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="employee_status" class="form-label">Employment Status <span class="optional-badge">(Optional)</span></label>
                                <select class="form-select" id="employee_status" name="employee_status">
                                    <?php foreach ($employee_statuses as $status): ?>
                                        <option value="<?php echo e($status); ?>" <?php echo (($_POST['employee_status'] ?? 'active') === $status) ? 'selected' : ''; ?>>
                                            <?php echo e(ucfirst($status)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <h5 class="form-section-heading">Reporting & Location <span class="optional-badge">(Optional)</span></h5>

                            <div class="col-md-6">
                                <label for="reporting_manager" class="form-label">Reporting Manager</label>
                                <select class="form-select" id="reporting_manager" name="reporting_manager">
                                    <option value="">Select Reporting Manager</option>
                                    <?php foreach ($existing_employees as $emp): ?>
                                        <option value="<?php echo e($emp['full_name']); ?>" <?php echo (($_POST['reporting_manager'] ?? '') === $emp['full_name']) ? 'selected' : ''; ?>>
                                            <?php echo e($emp['full_name'] . ' - ' . $emp['designation']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="work_location" class="form-label">Work Location</label>
                                <input type="text" class="form-control" id="work_location" name="work_location"
                                       value="<?php echo e($_POST['work_location'] ?? ''); ?>">
                            </div>

                            <div class="col-md-12">
                                <label for="site_name" class="form-label">Site/Project Name</label>
                                <input type="text" class="form-control" id="site_name" name="site_name"
                                       value="<?php echo e($_POST['site_name'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Login -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-header-left">
                                <div class="section-icon purple"><i class="bi bi-key"></i></div>
                                <div>
                                    <h3 class="section-title">Login Credentials</h3>
                                    <p class="section-subtitle">System access credentials</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="username" class="form-label required-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username"
                                       value="<?php echo e($_POST['username'] ?? ''); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="password" class="form-label required-label">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <button class="icon-btn" type="button" id="togglePassword" title="Show/Hide"><i class="bi bi-eye"></i></button>
                                    <button class="icon-btn" type="button" id="generatePasswordBtn" title="Generate"><i class="bi bi-shuffle"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Compliance -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-header-left">
                                <div class="section-icon blue"><i class="bi bi-shield-check"></i></div>
                                <div>
                                    <h3 class="section-title">Compliance Documents <span class="optional-badge">(Optional)</span></h3>
                                    <p class="section-subtitle">Official documents can be added later</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="aadhar_card_number" class="form-label">Aadhar Card Number</label>
                                <input type="text" class="form-control" id="aadhar_card_number" name="aadhar_card_number"
                                       value="<?php echo e($_POST['aadhar_card_number'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="pancard_number" class="form-label">PAN Card Number</label>
                                <input type="text" class="form-control" id="pancard_number" name="pancard_number"
                                       value="<?php echo e($_POST['pancard_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Bank -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-header-left">
                                <div class="section-icon green"><i class="bi bi-bank"></i></div>
                                <div>
                                    <h3 class="section-title">Banking Information <span class="optional-badge">(Optional)</span></h3>
                                    <p class="section-subtitle">Salary details can be added later</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="bank_account_number" class="form-label">Bank Account Number</label>
                                <input type="text" class="form-control" id="bank_account_number" name="bank_account_number"
                                       value="<?php echo e($_POST['bank_account_number'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="ifsc_code" class="form-label">IFSC Code</label>
                                <input type="text" class="form-control" id="ifsc_code" name="ifsc_code"
                                       value="<?php echo e($_POST['ifsc_code'] ?? ''); ?>">
                            </div>

                            <div class="col-12">
                                <label for="passbook_photo" class="form-label">Passbook/Cancelled Cheque</label>
                                <div class="file-upload-container" onclick="document.getElementById('passbook_photo').click()" id="passbook_photoUploadContainer">
                                    <div class="file-upload-icon"><i class="bi bi-file-image"></i></div>
                                    <div class="file-upload-text">Upload passbook or cheque</div>
                                    <div class="file-upload-subtext">JPG, PNG, WebP (Max 5MB)</div>
                                    <input type="file" class="d-none" id="passbook_photo" name="passbook_photo" accept="image/*">
                                </div>
                                <div class="file-preview d-none" id="passbook_photoPreview">
                                    <img src="" alt="Passbook Preview">
                                    <div class="file-remove" onclick="removeFile('passbook_photo')"><i class="bi bi-x"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="submit-strip">
                        <p class="submit-note">
                            <i class="bi bi-info-circle me-1"></i>
                            Fields marked with * are required. Other fields can be added later.
                            <?php if ($isHr): ?>
                                Admin users will be notified after employee creation.
                            <?php endif; ?>
                        </p>

                        <button type="submit" class="submit-btn" id="addEmployeeBtn">
                            <i class="bi bi-person-plus"></i> Add Employee
                        </button>
                    </div>
                </form>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('employeeForm');
    const submitBtn = document.getElementById('addEmployeeBtn');

    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Adding...';
        });
    }

    const fileInputs = ['photo', 'passbook_photo'];

    fileInputs.forEach(inputId => {
        const input = document.getElementById(inputId);
        const container = document.getElementById(inputId + 'UploadContainer');
        const preview = document.getElementById(inputId + 'Preview');
        if (!input || !container || !preview) return;
        const previewImg = preview.querySelector('img');
        if (!previewImg) return;

        input.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    previewImg.src = ev.target.result;
                    preview.classList.remove('d-none');
                    container.classList.add('d-none');
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    window.removeFile = function(inputId) {
        const input = document.getElementById(inputId);
        const container = document.getElementById(inputId + 'UploadContainer');
        const preview = document.getElementById(inputId + 'Preview');
        if (!input || !container || !preview) return;
        input.value = '';
        preview.classList.add('d-none');
        container.classList.remove('d-none');
    };

    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;
            this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        });
    }

    const generatePasswordBtn = document.getElementById('generatePasswordBtn');
    if (generatePasswordBtn && passwordInput) {
        generatePasswordBtn.addEventListener('click', function() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
            let pwd = '';
            for (let i = 0; i < 12; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
            passwordInput.value = pwd;
            passwordInput.type = 'text';
            if (togglePassword) togglePassword.innerHTML = '<i class="bi bi-eye-slash"></i>';
        });
    }

    const employeeCodeInput = document.getElementById('employee_code');
    const usernameInput = document.getElementById('username');
    if (employeeCodeInput && usernameInput) {
        employeeCodeInput.addEventListener('blur', function() {
            if (!usernameInput.value && this.value) {
                usernameInput.value = this.value.toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9]/g, '');
            }
        });
    }

    const pancardInput = document.getElementById('pancard_number');
    if (pancardInput) pancardInput.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });

    const mobileInput = document.getElementById('mobile_number');
    if (mobileInput) mobileInput.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,10); });

    const aadharInput = document.getElementById('aadhar_card_number');
    if (aadharInput) aadharInput.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,12); });

    const bankInput = document.getElementById('bank_account_number');
    if (bankInput) bankInput.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,''); });
});
</script>

</body>
</html>
