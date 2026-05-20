<?php
// edit-employee.php
// TEK-C add/edit/delete page style
// Fixed: activity_logs unknown column user_id fatal error

session_start();

require_once 'includes/db-config.php';
// Keep this include only if other pages need it. This page will NOT call logActivity() directly.
if (file_exists('includes/activity-logger.php')) {
    require_once 'includes/activity-logger.php';
}

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

$success = '';
$error = '';
$validation_errors = [];
$emp = null;

/* ---------------- HELPERS ---------------- */

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function nullIfEmpty($v) {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function cleanDate($v) {
    $v = trim((string)$v);
    return ($v === '' || $v === '0000-00-00') ? null : $v;
}

function oldInput($key, $fallback = '') {
    return isset($_POST[$key]) ? (string)$_POST[$key] : (string)$fallback;
}

function selected($a, $b) {
    return (string)$a === (string)$b ? 'selected' : '';
}

/**
 * Safe activity logger.
 * This avoids fatal error when activity_logs table columns differ.
 */
function safeActivityLog(
    $conn,
    $action_type,
    $module,
    $description,
    $module_id = null,
    $module_name = null,
    $old_data = null,
    $new_data = null
) {
    if (!$conn) {
        return false;
    }

    try {
        $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'activity_logs'");

        if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
            return false;
        }

        $columnsResult = mysqli_query($conn, "SHOW COLUMNS FROM activity_logs");

        if (!$columnsResult) {
            return false;
        }

        $existingColumns = [];

        while ($row = mysqli_fetch_assoc($columnsResult)) {
            $existingColumns[] = $row['Field'];
        }

        if (empty($existingColumns)) {
            return false;
        }

        $employee_id = $_SESSION['employee_id'] ?? null;
        $admin_id = $_SESSION['admin_id'] ?? null;

        $current_user_id = $employee_id ?: ($admin_id ?: null);

        $current_user_name =
            $_SESSION['employee_name']
            ?? $_SESSION['admin_name']
            ?? $_SESSION['username']
            ?? 'System';

        $current_user_role =
            $_SESSION['role']
            ?? $_SESSION['user_role']
            ?? $_SESSION['designation']
            ?? 'User';

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now = date('Y-m-d H:i:s');

        $dataMap = [
            'user_id' => $current_user_id,
            'employee_id' => $employee_id ?: $current_user_id,
            'admin_id' => $admin_id ?: null,
            'created_by' => $current_user_id,
            'performed_by' => $current_user_id,

            'user_name' => $current_user_name,
            'employee_name' => $current_user_name,
            'admin_name' => $current_user_name,
            'created_by_name' => $current_user_name,

            'user_role' => $current_user_role,
            'role' => $current_user_role,

            'action_type' => $action_type,
            'action' => $action_type,
            'activity_type' => $action_type,

            'module' => $module,
            'module_id' => $module_id,
            'module_name' => $module_name,

            'description' => $description,
            'details' => $description,
            'remarks' => $description,

            'old_data' => $old_data,
            'new_data' => $new_data,

            'ip_address' => $ip_address,
            'user_agent' => $user_agent,

            'created_at' => $now,
            'updated_at' => $now,
            'log_time' => $now,
            'logged_at' => $now
        ];

        $insertColumns = [];
        $insertValues = [];
        $types = '';

        foreach ($dataMap as $column => $value) {
            if (!in_array($column, $existingColumns, true)) {
                continue;
            }

            $insertColumns[] = $column;
            $insertValues[] = $value;

            if (
                $column === 'user_id' ||
                $column === 'employee_id' ||
                $column === 'admin_id' ||
                $column === 'created_by' ||
                $column === 'performed_by' ||
                $column === 'module_id'
            ) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
        }

        if (empty($insertColumns)) {
            return false;
        }

        $columnSql = implode(', ', array_map(function ($col) {
            return '`' . str_replace('`', '', $col) . '`';
        }, $insertColumns));

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));

        $sql = "INSERT INTO activity_logs ($columnSql) VALUES ($placeholders)";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return false;
        }

        $bindParams = [];
        $bindParams[] = $types;

        foreach ($insertValues as $key => $value) {
            $bindParams[] = &$insertValues[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindParams);

        $ok = mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);

        return $ok;

    } catch (Throwable $e) {
        error_log("safeActivityLog error: " . $e->getMessage());
        return false;
    }
}

function detectAdminFsPath(): string {
    if (is_dir(__DIR__ . '/uploads')) {
        return realpath(__DIR__) ?: __DIR__;
    }

    $p2 = realpath(__DIR__ . '/../admin');

    if ($p2 && is_dir($p2)) {
        return $p2;
    }

    $p1 = realpath(__DIR__ . '/admin');

    if ($p1 && is_dir($p1)) {
        return $p1;
    }

    $fallback = __DIR__ . '/../admin';

    if (!is_dir($fallback)) {
        @mkdir($fallback, 0777, true);
    }

    return realpath($fallback) ?: $fallback;
}

function isRunningInsideAdmin(): bool {
    if (basename(__DIR__) === 'admin') {
        return true;
    }

    if (is_dir(__DIR__ . '/uploads')) {
        return true;
    }

    return false;
}

function fileUrl($path, $insideAdmin = false) {
    $p = trim((string)$path);

    if ($p === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $p)) {
        return $p;
    }

    $p = ltrim($p, '/');

    if (stripos($p, 'uploads/') === 0) {
        $p = 'admin/' . $p;
    }

    if (stripos($p, 'employees/') === 0) {
        $p = 'admin/uploads/' . $p;
    }

    if ($insideAdmin) {
        if (stripos($p, 'admin/') === 0) {
            return substr($p, 6);
        }

        return $p;
    }

    if (stripos($p, 'admin/') === 0) {
        return '../' . $p;
    }

    return '../admin/' . $p;
}

function dbPathToFs($dbPath, $adminFsBase): string {
    $p = trim((string)$dbPath);
    $p = ltrim($p, '/');

    if (stripos($p, 'admin/') === 0) {
        $p = substr($p, 6);
    }

    return rtrim($adminFsBase, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p);
}

function handleImageUploadToAdmin($field_name, $subdir, $adminFsBase) {
    if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
        return [
            'success' => true,
            'path' => ''
        ];
    }

    $file = $_FILES[$field_name];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'error' => 'File upload error: ' . (int)($file['error'] ?? -1)
        ];
    }

    $size = (int)($file['size'] ?? 0);

    if ($size <= 0) {
        return [
            'success' => false,
            'error' => 'Invalid file size'
        ];
    }

    if ($size > 10 * 1024 * 1024) {
        return [
            'success' => false,
            'error' => 'File size too large. Maximum 10MB allowed'
        ];
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if (!in_array($ext, $allowed, true)) {
        return [
            'success' => false,
            'error' => 'Only image files are allowed: jpg, jpeg, png, webp, gif'
        ];
    }

    $tmp = (string)($file['tmp_name'] ?? '');

    if (@getimagesize($tmp) === false) {
        return [
            'success' => false,
            'error' => 'Invalid image file'
        ];
    }

    $subdir = trim((string)$subdir, "/") . "/";

    $uploadFsDir =
        rtrim($adminFsBase, "/\\") .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $subdir);

    if (!is_dir($uploadFsDir) && !@mkdir($uploadFsDir, 0777, true)) {
        return [
            'success' => false,
            'error' => 'Failed to create upload directory'
        ];
    }

    try {
        $random = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $random = uniqid();
    }

    $new_file_name = 'img_' . $random . '_' . time() . '.' . $ext;

    $targetFsPath =
        rtrim($uploadFsDir, "/\\") .
        DIRECTORY_SEPARATOR .
        $new_file_name;

    if (!move_uploaded_file($tmp, $targetFsPath)) {
        return [
            'success' => false,
            'error' => 'Failed to save uploaded file'
        ];
    }

    return [
        'success' => true,
        'path' => 'admin/uploads/' . $subdir . $new_file_name
    ];
}

function handleFileUploadToAdmin($field_name, $subdir, $adminFsBase) {
    if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
        return [
            'success' => true,
            'path' => ''
        ];
    }

    $file = $_FILES[$field_name];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'error' => 'File upload error: ' . (int)($file['error'] ?? -1)
        ];
    }

    $size = (int)($file['size'] ?? 0);

    if ($size <= 0) {
        return [
            'success' => false,
            'error' => 'Invalid file size'
        ];
    }

    if ($size > 10 * 1024 * 1024) {
        return [
            'success' => false,
            'error' => 'File size too large. Maximum 10MB allowed'
        ];
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];

    if (!in_array($ext, $allowed, true)) {
        return [
            'success' => false,
            'error' => 'Invalid file type. Allowed: jpg, jpeg, png, webp, gif, pdf'
        ];
    }

    $tmp = (string)($file['tmp_name'] ?? '');

    if ($ext !== 'pdf' && @getimagesize($tmp) === false) {
        return [
            'success' => false,
            'error' => 'Invalid image file'
        ];
    }

    $subdir = trim((string)$subdir, "/") . "/";

    $uploadFsDir =
        rtrim($adminFsBase, "/\\") .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $subdir);

    if (!is_dir($uploadFsDir) && !@mkdir($uploadFsDir, 0777, true)) {
        return [
            'success' => false,
            'error' => 'Failed to create upload directory'
        ];
    }

    try {
        $random = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $random = uniqid();
    }

    $new_file_name = 'file_' . $random . '_' . time() . '.' . $ext;

    $targetFsPath =
        rtrim($uploadFsDir, "/\\") .
        DIRECTORY_SEPARATOR .
        $new_file_name;

    if (!move_uploaded_file($tmp, $targetFsPath)) {
        return [
            'success' => false,
            'error' => 'Failed to save uploaded file'
        ];
    }

    return [
        'success' => true,
        'path' => 'admin/uploads/' . $subdir . $new_file_name
    ];
}

/* ---------------- SETUP ---------------- */

$adminFsBase = detectAdminFsPath();
$insideAdmin = isRunningInsideAdmin();

/* ---------------- GET EMPLOYEE ---------------- */

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    die("Invalid employee ID.");
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM employees
     WHERE id = ?
     LIMIT 1"
);

if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);
$emp = mysqli_fetch_assoc($res);

mysqli_stmt_close($stmt);

if (!$emp) {
    die("Employee not found.");
}

/* ---------------- OPTIONS ---------------- */

$departments = [
    'PM',
    'CM',
    'IFM',
    'QS',
    'HR',
    'ACCOUNTS'
];

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

$genders = [
    'Male',
    'Female',
    'Other'
];

$statuses = [
    'active',
    'inactive',
    'resigned'
];

/* ---------------- POST ACTIONS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim($_POST['action'] ?? 'update_employee');

    if ($action === 'delete_employee') {

        $delete_id = (int)($_POST['employee_id'] ?? 0);

        if ($delete_id !== $id || $delete_id <= 0) {
            $validation_errors[] = "Invalid employee selected";
        }

        if (empty($validation_errors)) {

            $stmtD = mysqli_prepare(
                $conn,
                "UPDATE employees
                 SET employee_status = 'inactive'
                 WHERE id = ?
                 LIMIT 1"
            );

            if (!$stmtD) {
                $error = "Database error: " . mysqli_error($conn);
            } else {

                mysqli_stmt_bind_param($stmtD, "i", $id);

                if (mysqli_stmt_execute($stmtD)) {

                    safeActivityLog(
                        $conn,
                        'SOFT_DELETE',
                        'employee',
                        "Marked employee inactive: " . ($emp['employee_code'] ?? $id),
                        $id,
                        $emp['employee_code'] ?? null,
                        null,
                        json_encode([
                            'employee_id' => $id,
                            'employee_code' => $emp['employee_code'] ?? '',
                            'full_name' => $emp['full_name'] ?? ''
                        ])
                    );

                    $success = "Employee marked as inactive successfully!";

                    $stmtR = mysqli_prepare(
                        $conn,
                        "SELECT *
                         FROM employees
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($stmtR) {
                        mysqli_stmt_bind_param($stmtR, "i", $id);
                        mysqli_stmt_execute($stmtR);

                        $resR = mysqli_stmt_get_result($stmtR);
                        $emp = mysqli_fetch_assoc($resR);

                        mysqli_stmt_close($stmtR);
                    }

                } else {
                    $error = "Error deleting employee: " . mysqli_stmt_error($stmtD);
                }

                mysqli_stmt_close($stmtD);
            }
        }

    } else {

        $full_name = trim($_POST['full_name'] ?? '');
        $employee_code = trim($_POST['employee_code'] ?? '');
        $date_of_birth = cleanDate($_POST['date_of_birth'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $blood_group = trim($_POST['blood_group'] ?? '');
        $mobile_number = trim($_POST['mobile_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $current_address = trim($_POST['current_address'] ?? '');
        $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
        $date_of_joining = cleanDate($_POST['date_of_joining'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $reporting_manager = trim($_POST['reporting_manager'] ?? '');
        $work_location = trim($_POST['work_location'] ?? '');
        $site_name = trim($_POST['site_name'] ?? '');
        $employee_status = trim($_POST['employee_status'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $new_password = trim($_POST['password'] ?? '');
        $aadhar_card_number = trim($_POST['aadhar_card_number'] ?? '');
        $pancard_number = strtoupper(trim($_POST['pancard_number'] ?? ''));
        $bank_account_number = trim($_POST['bank_account_number'] ?? '');
        $ifsc_code = strtoupper(trim($_POST['ifsc_code'] ?? ''));

        if ($full_name === '') {
            $validation_errors[] = "Full name is required";
        }

        if ($employee_code === '') {
            $validation_errors[] = "Employee code is required";
        }

        if ($username === '') {
            $validation_errors[] = "Username is required";
        }

        if ($mobile_number !== '' && !preg_match('/^[0-9]{10,15}$/', $mobile_number)) {
            $validation_errors[] = "Mobile number must be 10 to 15 digits";
        }

        if ($emergency_contact_phone !== '' && !preg_match('/^[0-9]{10,15}$/', $emergency_contact_phone)) {
            $validation_errors[] = "Emergency phone must be 10 to 15 digits";
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validation_errors[] = "Invalid email format";
        }

        if ($gender !== '' && !in_array($gender, $genders, true)) {
            $validation_errors[] = "Invalid gender selected";
        }

        if ($department !== '' && !in_array($department, $departments, true)) {
            $validation_errors[] = "Invalid department selected";
        }

        if ($designation !== '' && !in_array($designation, $designations, true)) {
            $validation_errors[] = "Invalid designation selected";
        }

        if ($employee_status !== '' && !in_array($employee_status, $statuses, true)) {
            $validation_errors[] = "Invalid employee status selected";
        }

        if ($aadhar_card_number !== '' && !preg_match('/^[0-9]{12}$/', $aadhar_card_number)) {
            $validation_errors[] = "Aadhaar number must be 12 digits";
        }

        if ($pancard_number !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pancard_number)) {
            $validation_errors[] = "Invalid PAN card format";
        }

        if ($ifsc_code !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc_code)) {
            $validation_errors[] = "Invalid IFSC code format";
        }

        $dup_stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM employees
             WHERE (employee_code = ? OR username = ?)
             AND id <> ?
             LIMIT 1"
        );

        if ($dup_stmt) {

            mysqli_stmt_bind_param(
                $dup_stmt,
                "ssi",
                $employee_code,
                $username,
                $id
            );

            mysqli_stmt_execute($dup_stmt);
            mysqli_stmt_store_result($dup_stmt);

            if (mysqli_stmt_num_rows($dup_stmt) > 0) {
                $validation_errors[] = "Employee code or username already exists";
            }

            mysqli_stmt_close($dup_stmt);
        }

        $hashed_password = '';

        if ($new_password !== '') {
            if (strlen($new_password) < 8) {
                $validation_errors[] = "Password must be at least 8 characters";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            }
        }

        $photoUp = handleImageUploadToAdmin(
            'photo',
            'employees/photos',
            $adminFsBase
        );

        if (empty($photoUp['success'])) {
            $validation_errors[] = $photoUp['error'] ?? 'Photo upload failed';
        }

        $new_photo_path = $photoUp['path'] ?? '';

        $passbookUp = handleFileUploadToAdmin(
            'passbook_photo',
            'employees/passbook',
            $adminFsBase
        );

        if (empty($passbookUp['success'])) {
            $validation_errors[] = $passbookUp['error'] ?? 'Passbook upload failed';
        }

        $new_passbook_path = $passbookUp['path'] ?? '';

        if (empty($validation_errors)) {

            $final_photo =
                $new_photo_path !== ''
                ? $new_photo_path
                : ($emp['photo'] ?? '');

            $final_passbook =
                $new_passbook_path !== ''
                ? $new_passbook_path
                : ($emp['passbook_photo'] ?? '');

            if (
                $new_photo_path !== '' &&
                !empty($emp['photo']) &&
                $emp['photo'] !== $new_photo_path
            ) {
                $oldFs = dbPathToFs($emp['photo'], $adminFsBase);

                if (@is_file($oldFs)) {
                    @unlink($oldFs);
                }
            }

            if (
                $new_passbook_path !== '' &&
                !empty($emp['passbook_photo']) &&
                $emp['passbook_photo'] !== $new_passbook_path
            ) {
                $oldFs2 = dbPathToFs($emp['passbook_photo'], $adminFsBase);

                if (@is_file($oldFs2)) {
                    @unlink($oldFs2);
                }
            }

            $before_data = [
                'full_name' => $emp['full_name'] ?? '',
                'employee_code' => $emp['employee_code'] ?? '',
                'department' => $emp['department'] ?? '',
                'designation' => $emp['designation'] ?? '',
                'employee_status' => $emp['employee_status'] ?? '',
                'username' => $emp['username'] ?? ''
            ];

            if ($hashed_password !== '') {

                $sql = "
                    UPDATE employees
                    SET
                        full_name = ?,
                        employee_code = ?,
                        photo = ?,
                        date_of_birth = ?,
                        gender = ?,
                        blood_group = ?,
                        mobile_number = ?,
                        email = ?,
                        current_address = ?,
                        emergency_contact_name = ?,
                        emergency_contact_phone = ?,
                        date_of_joining = ?,
                        department = ?,
                        designation = ?,
                        reporting_manager = ?,
                        work_location = ?,
                        site_name = ?,
                        employee_status = ?,
                        username = ?,
                        password = ?,
                        aadhar_card_number = ?,
                        pancard_number = ?,
                        bank_account_number = ?,
                        ifsc_code = ?,
                        passbook_photo = ?
                    WHERE id = ?
                    LIMIT 1
                ";

                $stmtU = mysqli_prepare($conn, $sql);

                if ($stmtU) {
                    mysqli_stmt_bind_param(
                        $stmtU,
                        "sssssssssssssssssssssssssi",
                        $full_name,
                        $employee_code,
                        $final_photo,
                        $date_of_birth,
                        $gender,
                        $blood_group,
                        $mobile_number,
                        $email,
                        $current_address,
                        $emergency_contact_name,
                        $emergency_contact_phone,
                        $date_of_joining,
                        $department,
                        $designation,
                        $reporting_manager,
                        $work_location,
                        $site_name,
                        $employee_status,
                        $username,
                        $hashed_password,
                        $aadhar_card_number,
                        $pancard_number,
                        $bank_account_number,
                        $ifsc_code,
                        $final_passbook,
                        $id
                    );
                }

            } else {

                $sql = "
                    UPDATE employees
                    SET
                        full_name = ?,
                        employee_code = ?,
                        photo = ?,
                        date_of_birth = ?,
                        gender = ?,
                        blood_group = ?,
                        mobile_number = ?,
                        email = ?,
                        current_address = ?,
                        emergency_contact_name = ?,
                        emergency_contact_phone = ?,
                        date_of_joining = ?,
                        department = ?,
                        designation = ?,
                        reporting_manager = ?,
                        work_location = ?,
                        site_name = ?,
                        employee_status = ?,
                        username = ?,
                        aadhar_card_number = ?,
                        pancard_number = ?,
                        bank_account_number = ?,
                        ifsc_code = ?,
                        passbook_photo = ?
                    WHERE id = ?
                    LIMIT 1
                ";

                $stmtU = mysqli_prepare($conn, $sql);

                if ($stmtU) {
                    mysqli_stmt_bind_param(
                        $stmtU,
                        "ssssssssssssssssssssssssi",
                        $full_name,
                        $employee_code,
                        $final_photo,
                        $date_of_birth,
                        $gender,
                        $blood_group,
                        $mobile_number,
                        $email,
                        $current_address,
                        $emergency_contact_name,
                        $emergency_contact_phone,
                        $date_of_joining,
                        $department,
                        $designation,
                        $reporting_manager,
                        $work_location,
                        $site_name,
                        $employee_status,
                        $username,
                        $aadhar_card_number,
                        $pancard_number,
                        $bank_account_number,
                        $ifsc_code,
                        $final_passbook,
                        $id
                    );
                }
            }

            if (!isset($stmtU) || !$stmtU) {

                $error = "Database error: " . mysqli_error($conn);

            } else {

                if (mysqli_stmt_execute($stmtU)) {

                    mysqli_stmt_close($stmtU);

                    safeActivityLog(
                        $conn,
                        'UPDATE',
                        'employee',
                        "Updated employee: {$employee_code}",
                        $id,
                        $employee_code,
                        json_encode($before_data),
                        json_encode([
                            'full_name' => $full_name,
                            'employee_code' => $employee_code,
                            'department' => $department,
                            'designation' => $designation,
                            'employee_status' => $employee_status,
                            'username' => $username,
                            'password_changed' => $hashed_password !== ''
                        ])
                    );

                    $success = "Employee updated successfully!";

                    $stmtR = mysqli_prepare(
                        $conn,
                        "SELECT *
                         FROM employees
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($stmtR) {
                        mysqli_stmt_bind_param($stmtR, "i", $id);
                        mysqli_stmt_execute($stmtR);

                        $resR = mysqli_stmt_get_result($stmtR);
                        $emp = mysqli_fetch_assoc($resR);

                        mysqli_stmt_close($stmtR);
                    }

                } else {
                    $error = "Error updating employee: " . mysqli_stmt_error($stmtU);
                    mysqli_stmt_close($stmtU);
                }
            }
        }
    }
}

$photoUrl = fileUrl($emp['photo'] ?? '', $insideAdmin);
$passbookUrl = fileUrl($emp['passbook_photo'] ?? '', $insideAdmin);

?>

<!doctype html>
<html lang="en">

<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />

<title>Edit Employee - TEK-C</title>

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
    --shadow:0 10px 26px rgba(15,23,42,.055);
    --radius:15px;
}

body{
    background:var(--page-bg);
}

.content-scroll{
    flex:1 1 auto;
    overflow:auto;
    padding:16px;
}

.employee-wrapper{
    width:100%;
}

.page-heading{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
}

.page-heading h1{
    font-size:19px;
    font-weight:900;
    color:var(--text);
    margin:0;
}

.page-heading p{
    margin:3px 0 0;
    color:var(--muted);
    font-size:12px;
    font-weight:600;
}

.primary-btn{
    border:0;
    background:#111827;
    color:#fff;
    height:36px;
    padding:0 14px;
    border-radius:11px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:7px;
    text-decoration:none;
    white-space:nowrap;
}

.primary-btn:hover{
    background:#020617;
    color:#fff;
}

.back-btn{
    background:#fff;
    color:#475569;
    border:1px solid var(--border);
}

.back-btn:hover{
    background:#f8fafc;
    color:#111827;
}

.view-btn{
    background:#2f80ed;
}

.view-btn:hover{
    background:#2563eb;
}

.delete-top-btn{
    background:#ef4444;
}

.delete-top-btn:hover{
    background:#dc2626;
}

.form-panel{
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:0 8px 20px rgba(15,23,42,.04);
    padding:16px;
    margin-bottom:16px;
}

.section-header{
    display:flex;
    align-items:center;
    margin-bottom:18px;
    padding-bottom:12px;
    border-bottom:2px solid #f0f4f8;
}

.section-icon{
    width:36px;
    height:36px;
    border-radius:12px;
    background:#111827;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-right:12px;
    font-size:18px;
    color:#fff;
}

.section-title{
    font-size:13px;
    font-weight:900;
    color:#2d3748;
    margin:0;
}

.section-subtitle{
    font-size:11px;
    color:#64748b;
    font-weight:700;
    margin-top:3px;
}

.form-label{
    font-weight:800;
    font-size:12px;
    color:#4b5563;
    margin-bottom:6px;
}

.required-label::after{
    content:" *";
    color:#ef4444;
}

.optional-badge{
    font-size:10px;
    color:#718096;
    font-weight:600;
    margin-left:5px;
}

.form-control,
.form-select{
    border:1px solid #e5e7eb;
    border-radius:10px;
    font-size:12px;
    font-weight:700;
}

.form-control:not(textarea),
.form-select{
    height:40px;
}

textarea.form-control{
    padding:10px 12px;
}

.form-control:focus,
.form-select:focus{
    border-color:#2f80ed;
    box-shadow:0 0 0 3px rgba(47,128,237,.10);
}

.form-control.is-invalid,
.form-select.is-invalid{
    border-color:#ef4444;
    background:#fff5f5;
}

.form-helper{
    font-size:10.5px;
    color:#718096;
    margin-top:5px;
    display:flex;
    align-items:center;
    gap:5px;
    font-weight:700;
}

.alert{
    border-radius:var(--radius);
    border:none;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    font-size:12px;
    font-weight:700;
}

.current-card{
    display:flex;
    align-items:center;
    gap:12px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:var(--shadow);
    padding:13px;
    margin-bottom:14px;
}

.employee-avatar{
    width:52px;
    height:52px;
    border-radius:15px;
    display:grid;
    place-items:center;
    overflow:hidden;
    background:#eff6ff;
    color:#2563eb;
    font-size:17px;
    font-weight:950;
    flex:0 0 auto;
}

.employee-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.employee-name{
    color:#111827;
    font-size:15px;
    font-weight:950;
}

.employee-meta{
    color:#64748b;
    font-size:11px;
    font-weight:800;
    margin-top:2px;
}

.status-pill{
    border-radius:999px;
    padding:5px 9px;
    font-weight:900;
    font-size:10px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    white-space:nowrap;
}

.status-active{
    color:#15803d;
    background:#dcfce7;
}

.status-inactive{
    color:#b45309;
    background:#fef3c7;
}

.status-resigned{
    color:#b91c1c;
    background:#fee2e2;
}

.file-upload-box{
    border:1.8px dashed #cbd5e1;
    border-radius:14px;
    background:#f8fafc;
    padding:16px;
    text-align:center;
    cursor:pointer;
    transition:.2s;
}

.file-upload-box:hover{
    border-color:#2f80ed;
    background:#eff6ff;
}

.file-upload-icon{
    font-size:28px;
    color:#94a3b8;
    margin-bottom:6px;
}

.file-upload-text{
    font-size:12px;
    font-weight:900;
    color:#334155;
}

.file-upload-subtext{
    font-size:10.5px;
    font-weight:700;
    color:#94a3b8;
    margin-top:2px;
}

.preview-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:7px 10px;
    border:1px solid var(--border);
    border-radius:999px;
    background:#fff;
    font-weight:900;
    color:#374151;
    font-size:11px;
    margin-top:10px;
}

.preview-pill a{
    text-decoration:none;
    color:#2563eb;
}

.submit-bar{
    position:sticky;
    bottom:0;
    background:rgba(245,247,251,.94);
    backdrop-filter:blur(10px);
    border-top:1px solid var(--border);
    padding:12px 0 2px;
    z-index:10;
}

.submit-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:16px;
    box-shadow:var(--shadow);
    padding:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.submit-info{
    color:#64748b;
    font-size:11px;
    font-weight:800;
}

.submit-btn{
    border:0;
    background:#111827;
    color:#fff;
    height:40px;
    padding:0 18px;
    border-radius:12px;
    font-size:12px;
    font-weight:950;
    display:inline-flex;
    align-items:center;
    gap:8px;
}

.submit-btn:hover{
    background:#020617;
    color:#fff;
}

.danger-outline{
    border:1px solid #fecaca;
    background:#fff;
    color:#b91c1c;
    height:40px;
    padding:0 16px;
    border-radius:12px;
    font-size:12px;
    font-weight:950;
    display:inline-flex;
    align-items:center;
    gap:8px;
}

.danger-outline:hover{
    background:#fee2e2;
    color:#991b1b;
}

.modal-content{
    border:0;
    border-radius:var(--radius);
    box-shadow:var(--shadow);
}

.modal-title{
    font-size:16px;
    font-weight:900;
}

@media(max-width:768px){
    .content-scroll{
        padding:12px 10px 12px!important;
    }

    .form-panel{
        padding:12px!important;
        margin-bottom:12px;
        border-radius:14px;
    }

    .page-heading{
        align-items:flex-start;
        flex-direction:column;
    }

    .section-header{
        align-items:flex-start;
    }

    .submit-card{
        align-items:stretch;
        flex-direction:column;
    }

    .submit-btn,
    .danger-outline{
        width:100%;
        justify-content:center;
    }
}
</style>
</head>

<body>

<div class="app">

<?php include 'includes/sidebar.php'; ?>

<main class="main" aria-label="Main">

<?php include 'includes/topbar.php'; ?>

<div id="contentScroll" class="content-scroll">

<div class="container-fluid employee-wrapper px-0">

<div class="page-heading">

<div>
<h1>Edit Employee</h1>
<p>Update employee details, login, KYC, bank and documents</p>
</div>

<div class="d-flex gap-2 flex-wrap">

<a href="manage-employees.php" class="primary-btn back-btn">
<i class="bi bi-arrow-left"></i>
Back
</a>

<a href="view-employee.php?id=<?php echo (int)$emp['id']; ?>" class="primary-btn view-btn">
<i class="bi bi-eye"></i>
View
</a>

<button type="button" class="primary-btn delete-top-btn" data-bs-toggle="modal" data-bs-target="#deleteModal">
<i class="bi bi-trash"></i>
Delete
</button>

</div>

</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
<i class="bi bi-check-circle-fill me-2"></i>
<strong>Success!</strong>
<?php echo e($success); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="bi bi-exclamation-triangle-fill me-2"></i>
<strong>Error!</strong>
<?php echo e($error); ?>
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

<div class="current-card">

<div class="employee-avatar">
<?php if (!empty($photoUrl)): ?>
<img
src="<?php echo e($photoUrl); ?>"
alt="<?php echo e($emp['full_name'] ?? 'Employee'); ?>"
onerror="this.style.display='none'; this.parentNode.innerHTML='<?php echo e(strtoupper(substr((string)($emp['full_name'] ?? 'E'), 0, 1))); ?>';"
>
<?php else: ?>
<?php echo e(strtoupper(substr((string)($emp['full_name'] ?? 'E'), 0, 1))); ?>
<?php endif; ?>
</div>

<div class="flex-grow-1">
<div class="employee-name"><?php echo e($emp['full_name'] ?? ''); ?></div>
<div class="employee-meta">
<i class="bi bi-hash"></i>
<?php echo e($emp['employee_code'] ?? ''); ?>
•
<?php echo e($emp['department'] ?? 'No Department'); ?>
•
<?php echo e($emp['designation'] ?? 'No Designation'); ?>
</div>
</div>

<?php
$statusClass = 'status-inactive';

if (($emp['employee_status'] ?? '') === 'active') {
    $statusClass = 'status-active';
} elseif (($emp['employee_status'] ?? '') === 'resigned') {
    $statusClass = 'status-resigned';
}
?>

<span class="status-pill <?php echo e($statusClass); ?>">
<i class="bi bi-circle-fill" style="font-size:7px;"></i>
<?php echo e(ucfirst($emp['employee_status'] ?? 'inactive')); ?>
</span>

</div>

<form method="POST" enctype="multipart/form-data" id="employeeForm" novalidate>

<input type="hidden" name="action" value="update_employee">

<div class="form-panel">

<div class="section-header">
<div class="section-icon"><i class="bi bi-person"></i></div>
<div>
<h3 class="section-title">Basic Information</h3>
<p class="section-subtitle">Personal details and contact information</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-6">
<label class="form-label required-label" for="full_name">Full Name</label>
<input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo e(oldInput('full_name', $emp['full_name'] ?? '')); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label required-label" for="employee_code">Employee Code</label>
<input type="text" class="form-control" id="employee_code" name="employee_code" value="<?php echo e(oldInput('employee_code', $emp['employee_code'] ?? '')); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label" for="date_of_birth">Date of Birth <span class="optional-badge">(Optional)</span></label>
<input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?php echo e(oldInput('date_of_birth', (($emp['date_of_birth'] ?? '') !== '0000-00-00' ? ($emp['date_of_birth'] ?? '') : ''))); ?>">
</div>

<div class="col-md-6">
<label class="form-label" for="gender">Gender <span class="optional-badge">(Optional)</span></label>
<select class="form-select" id="gender" name="gender">
<option value="">Select</option>
<?php foreach ($genders as $g): ?>
<option value="<?php echo e($g); ?>" <?php echo selected(oldInput('gender', $emp['gender'] ?? ''), $g); ?>>
<?php echo e($g); ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label" for="blood_group">Blood Group <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="blood_group" name="blood_group" value="<?php echo e(oldInput('blood_group', $emp['blood_group'] ?? '')); ?>" placeholder="A+, O-, etc.">
</div>

<div class="col-md-6">
<label class="form-label" for="mobile_number">Mobile Number <span class="optional-badge">(Optional)</span></label>
<input type="tel" class="form-control" id="mobile_number" name="mobile_number" value="<?php echo e(oldInput('mobile_number', $emp['mobile_number'] ?? '')); ?>" placeholder="10-15 digits">
<div class="form-helper"><i class="bi bi-phone"></i> Digits only</div>
</div>

<div class="col-md-6">
<label class="form-label" for="email">Email <span class="optional-badge">(Optional)</span></label>
<input type="email" class="form-control" id="email" name="email" value="<?php echo e(oldInput('email', $emp['email'] ?? '')); ?>" placeholder="name@domain.com">
</div>

<div class="col-12">
<label class="form-label" for="current_address">Current Address <span class="optional-badge">(Optional)</span></label>
<textarea class="form-control" id="current_address" name="current_address" rows="2"><?php echo e(oldInput('current_address', $emp['current_address'] ?? '')); ?></textarea>
</div>

<div class="col-md-6">
<label class="form-label" for="photo">Employee Photo <span class="optional-badge">(Optional)</span></label>

<div class="file-upload-box" onclick="document.getElementById('photo').click()">
<div class="file-upload-icon"><i class="bi bi-upload"></i></div>
<div class="file-upload-text">Click to upload employee photo</div>
<div class="file-upload-subtext">JPG, PNG, WebP, GIF • Max 10MB</div>
<input type="file" class="d-none" id="photo" name="photo" accept="image/*">
</div>

<?php if (!empty($photoUrl)): ?>
<div class="preview-pill">
<i class="bi bi-image"></i>
<a href="<?php echo e($photoUrl); ?>" target="_blank" rel="noopener">View current photo</a>
</div>
<?php endif; ?>

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#0ea5e9;"><i class="bi bi-life-preserver"></i></div>
<div>
<h3 class="section-title">Emergency Contact</h3>
<p class="section-subtitle">Contact person in case of emergency</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-6">
<label class="form-label" for="emergency_contact_name">Emergency Contact Name <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo e(oldInput('emergency_contact_name', $emp['emergency_contact_name'] ?? '')); ?>">
</div>

<div class="col-md-6">
<label class="form-label" for="emergency_contact_phone">Emergency Contact Phone <span class="optional-badge">(Optional)</span></label>
<input type="tel" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" value="<?php echo e(oldInput('emergency_contact_phone', $emp['emergency_contact_phone'] ?? '')); ?>" placeholder="10-15 digits">
</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#2563eb;"><i class="bi bi-briefcase"></i></div>
<div>
<h3 class="section-title">Employment Details</h3>
<p class="section-subtitle">Department, role, reporting and location</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-6">
<label class="form-label" for="date_of_joining">Date of Joining <span class="optional-badge">(Optional)</span></label>
<input type="date" class="form-control" id="date_of_joining" name="date_of_joining" value="<?php echo e(oldInput('date_of_joining', (($emp['date_of_joining'] ?? '') !== '0000-00-00' ? ($emp['date_of_joining'] ?? '') : ''))); ?>">
</div>

<div class="col-md-6">
<label class="form-label" for="department">Department <span class="optional-badge">(Optional)</span></label>
<select class="form-select" id="department" name="department">
<option value="">Select</option>
<?php foreach ($departments as $d): ?>
<option value="<?php echo e($d); ?>" <?php echo selected(oldInput('department', $emp['department'] ?? ''), $d); ?>>
<?php echo e($d); ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label" for="designation">Designation <span class="optional-badge">(Optional)</span></label>
<select class="form-select" id="designation" name="designation">
<option value="">Select</option>
<?php foreach ($designations as $dsg): ?>
<option value="<?php echo e($dsg); ?>" <?php echo selected(oldInput('designation', $emp['designation'] ?? ''), $dsg); ?>>
<?php echo e($dsg); ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label" for="reporting_manager">Reporting Manager <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="reporting_manager" name="reporting_manager" value="<?php echo e(oldInput('reporting_manager', $emp['reporting_manager'] ?? '')); ?>">
</div>

<div class="col-md-6">
<label class="form-label" for="work_location">Work Location <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="work_location" name="work_location" value="<?php echo e(oldInput('work_location', $emp['work_location'] ?? '')); ?>">
</div>

<div class="col-md-6">
<label class="form-label" for="site_name">Site Name <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo e(oldInput('site_name', $emp['site_name'] ?? '')); ?>">
</div>

<div class="col-md-6">
<label class="form-label" for="employee_status">Employee Status <span class="optional-badge">(Optional)</span></label>
<select class="form-select" id="employee_status" name="employee_status">
<option value="">Select</option>
<?php foreach ($statuses as $st): ?>
<option value="<?php echo e($st); ?>" <?php echo selected(oldInput('employee_status', $emp['employee_status'] ?? ''), $st); ?>>
<?php echo e(ucfirst($st)); ?>
</option>
<?php endforeach; ?>
</select>
</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#db2777;"><i class="bi bi-shield-lock"></i></div>
<div>
<h3 class="section-title">Login Details</h3>
<p class="section-subtitle">Username and optional password reset</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-6">
<label class="form-label required-label" for="username">Username</label>
<input type="text" class="form-control" id="username" name="username" value="<?php echo e(oldInput('username', $emp['username'] ?? '')); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label" for="password">New Password <span class="optional-badge">(Leave blank to keep current)</span></label>

<div class="input-group">
<input type="password" class="form-control" id="password" name="password" placeholder="Min 8 characters">
<button class="btn btn-outline-secondary" type="button" id="togglePassword"><i class="bi bi-eye"></i></button>
<button class="btn btn-outline-secondary" type="button" id="generatePasswordBtn"><i class="bi bi-shuffle"></i></button>
</div>

<div class="form-helper">
<i class="bi bi-info-circle"></i>
Password changes only when a new password is entered
</div>

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#16a34a;"><i class="bi bi-credit-card-2-front"></i></div>
<div>
<h3 class="section-title">KYC & Bank Details</h3>
<p class="section-subtitle">Identity, bank and passbook information</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-6">
<label class="form-label" for="aadhar_card_number">Aadhaar Number <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="aadhar_card_number" name="aadhar_card_number" value="<?php echo e(oldInput('aadhar_card_number', $emp['aadhar_card_number'] ?? '')); ?>" placeholder="12 digits">
</div>

<div class="col-md-6">
<label class="form-label" for="pancard_number">PAN Card Number <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="pancard_number" name="pancard_number" value="<?php echo e(oldInput('pancard_number', $emp['pancard_number'] ?? '')); ?>" placeholder="ABCDE1234F">
</div>

<div class="col-md-6">
<label class="form-label" for="bank_account_number">Bank Account Number <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="bank_account_number" name="bank_account_number" value="<?php echo e(oldInput('bank_account_number', $emp['bank_account_number'] ?? '')); ?>">
</div>

<div class="col-md-6">
<label class="form-label" for="ifsc_code">IFSC Code <span class="optional-badge">(Optional)</span></label>
<input type="text" class="form-control" id="ifsc_code" name="ifsc_code" value="<?php echo e(oldInput('ifsc_code', $emp['ifsc_code'] ?? '')); ?>" placeholder="ABCD0123456">
</div>

<div class="col-md-6">
<label class="form-label" for="passbook_photo">Passbook File <span class="optional-badge">(Optional)</span></label>

<div class="file-upload-box" onclick="document.getElementById('passbook_photo').click()">
<div class="file-upload-icon"><i class="bi bi-upload"></i></div>
<div class="file-upload-text">Click to upload passbook file</div>
<div class="file-upload-subtext">JPG, PNG, WebP, GIF, PDF • Max 10MB</div>
<input type="file" class="d-none" id="passbook_photo" name="passbook_photo" accept=".pdf,image/*">
</div>

<?php if (!empty($passbookUrl)): ?>
<div class="preview-pill">
<i class="bi bi-file-earmark-arrow-down"></i>
<a href="<?php echo e($passbookUrl); ?>" target="_blank" rel="noopener">View current passbook file</a>
</div>
<?php endif; ?>

</div>

</div>

</div>

<div class="submit-bar">

<div class="submit-card">

<div class="submit-info">
<i class="bi bi-info-circle me-1"></i>
Review all changes before updating this employee record.
</div>

<div class="d-flex gap-2 flex-wrap">

<button type="button" class="danger-outline" data-bs-toggle="modal" data-bs-target="#deleteModal">
<i class="bi bi-trash"></i>
Delete
</button>

<button type="submit" class="submit-btn">
<i class="bi bi-save"></i>
Update Employee
</button>

</div>

</div>

</div>

</form>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input type="hidden" name="action" value="delete_employee">
<input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">

<div class="modal-header">
<h5 class="modal-title">Delete Employee</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<p>
Are you sure you want to delete
<strong><?php echo e($emp['full_name'] ?? 'this employee'); ?></strong>?
</p>

<div class="alert alert-warning mb-0" style="box-shadow:none;">
<i class="bi bi-exclamation-triangle me-2"></i>
This will not permanently remove the record. It will mark the employee as <strong>inactive</strong>.
</div>

</div>

<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-danger">
<i class="bi bi-trash me-1"></i>
Mark Inactive
</button>
</div>

</form>

</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){

    const form = document.getElementById('employeeForm');
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    const generatePasswordBtn = document.getElementById('generatePasswordBtn');

    function setInvalid(el){
        if (el) {
            el.classList.add('is-invalid');
        }
    }

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function(){

            const type =
                passwordInput.getAttribute('type') === 'password'
                ? 'text'
                : 'password';

            passwordInput.setAttribute('type', type);

            this.innerHTML =
                type === 'password'
                ? '<i class="bi bi-eye"></i>'
                : '<i class="bi bi-eye-slash"></i>';
        });
    }

    if (generatePasswordBtn && passwordInput) {
        generatePasswordBtn.addEventListener('click', function(){

            const chars =
                'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';

            let password = '';

            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }

            passwordInput.value = password;
            passwordInput.setAttribute('type', 'text');

            if (togglePassword) {
                togglePassword.innerHTML = '<i class="bi bi-eye-slash"></i>';
            }
        });
    }

    const mobile = document.getElementById('mobile_number');
    const emergencyPhone = document.getElementById('emergency_contact_phone');
    const aadhaar = document.getElementById('aadhar_card_number');
    const pan = document.getElementById('pancard_number');
    const ifsc = document.getElementById('ifsc_code');

    if (mobile) {
        mobile.addEventListener('input', function(){
            this.value = this.value.replace(/\D/g, '').slice(0, 15);
        });
    }

    if (emergencyPhone) {
        emergencyPhone.addEventListener('input', function(){
            this.value = this.value.replace(/\D/g, '').slice(0, 15);
        });
    }

    if (aadhaar) {
        aadhaar.addEventListener('input', function(){
            this.value = this.value.replace(/\D/g, '').slice(0, 12);
        });
    }

    if (pan) {
        pan.addEventListener('input', function(){
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
        });
    }

    if (ifsc) {
        ifsc.addEventListener('input', function(){
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 11);
        });
    }

    if (form) {
        form.addEventListener('submit', function(e){

            let valid = true;
            const errors = [];

            form.querySelectorAll('.is-invalid').forEach(function(el){
                el.classList.remove('is-invalid');
            });

            form.querySelectorAll('[required]').forEach(function(field){

                if (!String(field.value || '').trim()) {

                    valid = false;
                    setInvalid(field);

                    const label = form.querySelector('label[for="' + field.id + '"]');

                    const name =
                        label
                        ? label.textContent.replace('*', '').trim()
                        : field.name;

                    errors.push(name + ' is required');
                }
            });

            if (passwordInput && passwordInput.value && passwordInput.value.length < 8) {
                valid = false;
                setInvalid(passwordInput);
                errors.push('Password must be at least 8 characters');
            }

            const email = document.getElementById('email');

            if (email && email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                valid = false;
                setInvalid(email);
                errors.push('Invalid email format');
            }

            if (mobile && mobile.value && !/^[0-9]{10,15}$/.test(mobile.value)) {
                valid = false;
                setInvalid(mobile);
                errors.push('Mobile number must be 10 to 15 digits');
            }

            if (emergencyPhone && emergencyPhone.value && !/^[0-9]{10,15}$/.test(emergencyPhone.value)) {
                valid = false;
                setInvalid(emergencyPhone);
                errors.push('Emergency phone must be 10 to 15 digits');
            }

            if (aadhaar && aadhaar.value && !/^[0-9]{12}$/.test(aadhaar.value)) {
                valid = false;
                setInvalid(aadhaar);
                errors.push('Aadhaar number must be 12 digits');
            }

            if (pan && pan.value && !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan.value)) {
                valid = false;
                setInvalid(pan);
                errors.push('Invalid PAN card format');
            }

            if (ifsc && ifsc.value && !/^[A-Z]{4}0[A-Z0-9]{6}$/.test(ifsc.value)) {
                valid = false;
                setInvalid(ifsc);
                errors.push('Invalid IFSC code format');
            }

            if (!valid) {

                e.preventDefault();

                const oldClientAlert = document.getElementById('clientValidationAlert');

                if (oldClientAlert) {
                    oldClientAlert.remove();
                }

                const alertDiv = document.createElement('div');

                alertDiv.id = 'clientValidationAlert';
                alertDiv.className = 'alert alert-danger alert-dismissible fade show';

                alertDiv.innerHTML =
                    '<div class="d-flex align-items-start">' +
                    '<i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>' +
                    '<div>' +
                    '<strong>Form Validation Error</strong>' +
                    '<ul class="mb-0 mt-2 ps-3">' +
                    errors.map(function(x){ return '<li>' + x + '</li>'; }).join('') +
                    '</ul>' +
                    '</div>' +
                    '</div>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

                const container = document.querySelector('.employee-wrapper');
                const currentCard = document.querySelector('.current-card');

                if (container && currentCard) {
                    container.insertBefore(alertDiv, currentCard);
                }

                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
        });
    }
});
</script>

</body>
</html>

<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>