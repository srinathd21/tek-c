<?php
// add-employee.php
// TEK-C add/edit/delete reference style
// Stores employee uploads physically in ../admin/uploads/*
// Saves DB paths as admin/uploads/*
// Adds activity_logs entry after successful CREATE

session_start();
require_once 'includes/db-config.php';

$success = '';
$error = '';
$validation_errors = [];

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- AUTH ---------------- */

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$session_designation = trim((string)($_SESSION['designation'] ?? ''));
$session_department  = trim((string)($_SESSION['department'] ?? ''));

$isHr =
    strtolower($session_designation) === 'hr' ||
    strtolower($session_department) === 'hr';

if (!$isHr) {
    $fallback = $_SESSION['role_redirect'] ?? '../login.php';
    header("Location: " . $fallback);
    exit;
}

/* ---------------- HELPERS ---------------- */

function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function nullIfEmpty($v){
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function logActivity(
    $conn,
    $activity_type,
    $module,
    $description,
    $reference_id = null
){

    $employee_id =
        $_SESSION['employee_id'] ?? null;

    $employee_name =
        $_SESSION['employee_name'] ?? '';

    $username =
        $_SESSION['username'] ?? '';

    $designation =
        $_SESSION['designation'] ?? '';

    $department =
        $_SESSION['department'] ?? '';

    $ip =
        $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO activity_logs
        (
            employee_id,
            employee_name,
            username,
            designation,
            department,
            activity_type,
            module,
            description,
            reference_id,
            ip_address
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            "isssssssis",
            $employee_id,
            $employee_name,
            $username,
            $designation,
            $department,
            $activity_type,
            $module,
            $description,
            $reference_id,
            $ip
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
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
    'Accountant',
    'employee'
];

$genders = [
    'Male',
    'Female',
    'Other'
];

$blood_groups = [
    'A+',
    'A-',
    'B+',
    'B-',
    'AB+',
    'AB-',
    'O+',
    'O-'
];

$employee_statuses = [
    'active',
    'inactive',
    'resigned'
];

/* ---------------- ADMIN UPLOAD PATHS ---------------- */

function getAdminFsBase(){

    $adminDir =
        __DIR__ . '/../admin';

    if (!is_dir($adminDir)) {
        @mkdir($adminDir, 0777, true);
    }

    $real =
        realpath($adminDir);

    return $real ?: $adminDir;
}

$adminFsBase =
    getAdminFsBase();

$adminWebBase =
    'admin';

/* ---------------- UPLOAD HANDLER ---------------- */

function handleFileUpload(
    $field_name,
    $subdir,
    $adminFsBase,
    $adminWebBase
){

    if (
        empty($_FILES[$field_name]) ||
        empty($_FILES[$field_name]['name'])
    ) {
        return [
            'success' => false,
            'error' => 'No file selected'
        ];
    }

    $file =
        $_FILES[$field_name];

    if (
        !isset($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        $code =
            (int)($file['error'] ?? -1);

        return [
            'success' => false,
            'error' => 'File upload error: ' . $code
        ];
    }

    $file_size =
        (int)($file['size'] ?? 0);

    if ($file_size <= 0) {
        return [
            'success' => false,
            'error' => 'Invalid file size'
        ];
    }

    if ($file_size > 5 * 1024 * 1024) {
        return [
            'success' => false,
            'error' => 'File size too large. Max 5MB allowed'
        ];
    }

    $file_name =
        (string)($file['name'] ?? '');

    $file_ext =
        strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    $allowed_extensions =
        ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($file_ext, $allowed_extensions, true)) {
        return [
            'success' => false,
            'error' => 'Allowed image types: JPG, JPEG, PNG, GIF, WebP'
        ];
    }

    $tmp =
        (string)($file['tmp_name'] ?? '');

    $imgInfo =
        @getimagesize($tmp);

    if ($imgInfo === false) {
        return [
            'success' => false,
            'error' => 'Invalid image file'
        ];
    }

    $subdir =
        trim((string)$subdir, "/") . "/";

    $uploadFsDir =
        rtrim($adminFsBase, "/\\") .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $subdir);

    if (!is_dir($uploadFsDir)) {
        if (!@mkdir($uploadFsDir, 0777, true)) {
            return [
                'success' => false,
                'error' => 'Failed to create upload directory'
            ];
        }
    }

    try {
        $rand =
            bin2hex(random_bytes(6));
    } catch (Throwable $t) {
        $rand =
            uniqid();
    }

    $new_file_name =
        'file_' .
        $rand .
        '_' .
        time() .
        '.' .
        $file_ext;

    $targetFsPath =
        rtrim($uploadFsDir, "/\\") .
        DIRECTORY_SEPARATOR .
        $new_file_name;

    if (!move_uploaded_file($tmp, $targetFsPath)) {
        return [
            'success' => false,
            'error' => 'Failed to move uploaded file'
        ];
    }

    $webPath =
        rtrim($adminWebBase, "/") .
        '/uploads/' .
        $subdir .
        $new_file_name;

    return [
        'success' => true,
        'path' => $webPath,
        'fs_path' => $targetFsPath
    ];
}

/* ---------------- FETCH ACTIVE EMPLOYEES FOR REPORTING MANAGER ---------------- */

$existing_employees = [];

$resEmp = mysqli_query(
    $conn,
    "SELECT id, full_name, employee_code, designation
     FROM employees
     WHERE employee_status = 'active'
     ORDER BY full_name ASC"
);

if ($resEmp) {

    $existing_employees =
        mysqli_fetch_all($resEmp, MYSQLI_ASSOC);

    mysqli_free_result($resEmp);
}

/* ---------------- HANDLE POST ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---------- IDENTITY ---------- */

    $full_name =
        trim($_POST['full_name'] ?? '');

    $employee_code =
        trim($_POST['employee_code'] ?? '');

    $date_of_birth =
        trim($_POST['date_of_birth'] ?? '');

    $gender =
        trim($_POST['gender'] ?? '');

    $blood_group =
        trim($_POST['blood_group'] ?? '');

    /* ---------- CONTACT ---------- */

    $mobile_number =
        trim($_POST['mobile_number'] ?? '');

    $email =
        trim($_POST['email'] ?? '');

    $current_address =
        trim($_POST['current_address'] ?? '');

    $emergency_contact_name =
        trim($_POST['emergency_contact_name'] ?? '');

    $emergency_contact_phone =
        trim($_POST['emergency_contact_phone'] ?? '');

    /* ---------- EMPLOYMENT ---------- */

    $date_of_joining =
        trim($_POST['date_of_joining'] ?? '');

    $department =
        trim($_POST['department'] ?? '');

    $designationSel =
        trim($_POST['designation'] ?? '');

    $reporting_to =
        (int)($_POST['reporting_to'] ?? 0);

    $reporting_manager =
        '';

    $work_location =
        trim($_POST['work_location'] ?? '');

    $site_name =
        trim($_POST['site_name'] ?? '');

    $employee_status =
        trim($_POST['employee_status'] ?? 'active');

    /* ---------- LOGIN ---------- */

    $username =
        trim($_POST['username'] ?? '');

    $password =
        trim($_POST['password'] ?? '');

    /* ---------- DOCUMENTS ---------- */

    $aadhar_card_number =
        trim($_POST['aadhar_card_number'] ?? '');

    $pancard_number =
        strtoupper(trim($_POST['pancard_number'] ?? ''));

    $bank_account_number =
        trim($_POST['bank_account_number'] ?? '');

    $ifsc_code =
        strtoupper(trim($_POST['ifsc_code'] ?? ''));

    /* ---------- VALIDATION ---------- */

    if ($full_name === '') {
        $validation_errors[] = "Full name is required";
    }

    if ($employee_code === '') {
        $validation_errors[] = "Employee code is required";
    }

    if ($mobile_number === '') {
        $validation_errors[] = "Mobile number is required";
    }

    if (
        $mobile_number !== '' &&
        !preg_match('/^[0-9]{10}$/', $mobile_number)
    ) {
        $validation_errors[] = "Mobile number must be 10 digits";
    }

    if (
        $email !== '' &&
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $validation_errors[] = "Invalid email format";
    }

    if ($date_of_joining === '') {
        $validation_errors[] = "Date of joining is required";
    }

    if ($department === '') {
        $validation_errors[] = "Department is required";
    }

    if (
        $department !== '' &&
        !in_array($department, $departments, true)
    ) {
        $validation_errors[] = "Invalid department selected";
    }

    if ($designationSel === '') {
        $validation_errors[] = "Designation is required";
    }

    if (
        $designationSel !== '' &&
        !in_array($designationSel, $designations, true)
    ) {
        $validation_errors[] = "Invalid designation selected";
    }

    if (
        $gender !== '' &&
        !in_array($gender, $genders, true)
    ) {
        $validation_errors[] = "Invalid gender selected";
    }

    if (
        $blood_group !== '' &&
        !in_array($blood_group, $blood_groups, true)
    ) {
        $validation_errors[] = "Invalid blood group selected";
    }

    if (
        $employee_status !== '' &&
        !in_array($employee_status, $employee_statuses, true)
    ) {
        $validation_errors[] = "Invalid employee status selected";
    }

    if ($username === '') {
        $validation_errors[] = "Username is required";
    }

    if ($password === '') {
        $validation_errors[] = "Password is required";
    }

    if (
        $aadhar_card_number !== '' &&
        !preg_match('/^[0-9]{12}$/', $aadhar_card_number)
    ) {
        $validation_errors[] = "Aadhar number must be 12 digits";
    }

    if (
        $pancard_number !== '' &&
        !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pancard_number)
    ) {
        $validation_errors[] = "PAN card number must be in format: ABCDE1234F";
    }

    if (
        $emergency_contact_phone !== '' &&
        !preg_match('/^[0-9]{10,15}$/', $emergency_contact_phone)
    ) {
        $validation_errors[] = "Emergency contact phone must be 10 to 15 digits";
    }

    /* ---------- REPORTING MANAGER CHECK ---------- */

    $reporting_to_db =
        null;

    if ($reporting_to > 0) {

        $stmtRM = mysqli_prepare(
            $conn,
            "SELECT id, full_name FROM employees WHERE id = ? LIMIT 1"
        );

        if ($stmtRM) {

            mysqli_stmt_bind_param($stmtRM, "i", $reporting_to);
            mysqli_stmt_execute($stmtRM);
            mysqli_stmt_bind_result($stmtRM, $rm_id, $rm_name);

            if (mysqli_stmt_fetch($stmtRM)) {
                $reporting_to_db =
                    (int)$rm_id;
                $reporting_manager =
                    $rm_name;
            } else {
                $validation_errors[] = "Selected reporting manager not found";
            }

            mysqli_stmt_close($stmtRM);
        }
    }

    /* ---------- DUPLICATE CHECKS ---------- */

    if ($employee_code !== '') {

        $stmtCheck = mysqli_prepare(
            $conn,
            "SELECT id FROM employees WHERE employee_code = ? LIMIT 1"
        );

        if ($stmtCheck) {

            mysqli_stmt_bind_param($stmtCheck, "s", $employee_code);
            mysqli_stmt_execute($stmtCheck);
            mysqli_stmt_store_result($stmtCheck);

            if (mysqli_stmt_num_rows($stmtCheck) > 0) {
                $validation_errors[] = "Employee code already exists";
            }

            mysqli_stmt_close($stmtCheck);
        }
    }

    if ($username !== '') {

        $stmtCheck = mysqli_prepare(
            $conn,
            "SELECT id FROM employees WHERE username = ? LIMIT 1"
        );

        if ($stmtCheck) {

            mysqli_stmt_bind_param($stmtCheck, "s", $username);
            mysqli_stmt_execute($stmtCheck);
            mysqli_stmt_store_result($stmtCheck);

            if (mysqli_stmt_num_rows($stmtCheck) > 0) {
                $validation_errors[] = "Username already exists. Please choose a different username.";
            }

            mysqli_stmt_close($stmtCheck);
        }
    }

    /* ---------- UPLOADS ---------- */

    $photo =
        '';

    $photo_fs =
        '';

    $passbook_photo =
        '';

    $passbook_fs =
        '';

    if (!empty($_FILES['photo']['name'])) {

        $upload =
            handleFileUpload(
                'photo',
                'employees/photos',
                $adminFsBase,
                $adminWebBase
            );

        if (!empty($upload['success'])) {

            $photo =
                $upload['path'];

            $photo_fs =
                $upload['fs_path'] ?? '';

        } else {

            $validation_errors[] =
                $upload['error'] ?? 'Photo upload failed';
        }
    }

    if (!empty($_FILES['passbook_photo']['name'])) {

        $upload =
            handleFileUpload(
                'passbook_photo',
                'employees/passbook',
                $adminFsBase,
                $adminWebBase
            );

        if (!empty($upload['success'])) {

            $passbook_photo =
                $upload['path'];

            $passbook_fs =
                $upload['fs_path'] ?? '';

        } else {

            $validation_errors[] =
                $upload['error'] ?? 'Passbook upload failed';
        }
    }

    /* ---------- INSERT ---------- */

    if (empty($validation_errors)) {

        $hashed_password =
            password_hash($password, PASSWORD_DEFAULT);

        $date_of_birth_db =
            nullIfEmpty($date_of_birth);

        $gender_db =
            nullIfEmpty($gender);

        $blood_group_db =
            nullIfEmpty($blood_group);

        $email_db =
            nullIfEmpty($email);

        $current_address_db =
            nullIfEmpty($current_address);

        $emergency_contact_name_db =
            nullIfEmpty($emergency_contact_name);

        $emergency_contact_phone_db =
            nullIfEmpty($emergency_contact_phone);

        $reporting_manager_db =
            nullIfEmpty($reporting_manager);

        $work_location_db =
            nullIfEmpty($work_location);

        $site_name_db =
            nullIfEmpty($site_name);

        $aadhar_card_number_db =
            nullIfEmpty($aadhar_card_number);

        $pancard_number_db =
            nullIfEmpty($pancard_number);

        $bank_account_number_db =
            nullIfEmpty($bank_account_number);

        $ifsc_code_db =
            nullIfEmpty($ifsc_code);

        $sql = "INSERT INTO employees
        (
            full_name,
            employee_code,
            photo,
            date_of_birth,
            gender,
            blood_group,
            mobile_number,
            email,
            current_address,
            emergency_contact_name,
            emergency_contact_phone,
            date_of_joining,
            department,
            designation,
            reporting_to,
            reporting_manager,
            work_location,
            site_name,
            employee_status,
            username,
            password,
            aadhar_card_number,
            pancard_number,
            bank_account_number,
            ifsc_code,
            passbook_photo
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )";

        $stmt =
            mysqli_prepare($conn, $sql);

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssssssssisssssssssss",
                $full_name,
                $employee_code,
                $photo,
                $date_of_birth_db,
                $gender_db,
                $blood_group_db,
                $mobile_number,
                $email_db,
                $current_address_db,
                $emergency_contact_name_db,
                $emergency_contact_phone_db,
                $date_of_joining,
                $department,
                $designationSel,
                $reporting_to_db,
                $reporting_manager_db,
                $work_location_db,
                $site_name_db,
                $employee_status,
                $username,
                $hashed_password,
                $aadhar_card_number_db,
                $pancard_number_db,
                $bank_account_number_db,
                $ifsc_code_db,
                $passbook_photo
            );

            if (mysqli_stmt_execute($stmt)) {

                $new_employee_id =
                    mysqli_insert_id($conn);

                logActivity(
                    $conn,
                    'CREATE',
                    'EMPLOYEE',
                    'Created new employee: ' . $full_name,
                    $new_employee_id
                );

                $success =
                    "Employee added successfully!";

                $_POST = [];

            } else {

                if (!empty($photo_fs) && file_exists($photo_fs)) {
                    @unlink($photo_fs);
                }

                if (!empty($passbook_fs) && file_exists($passbook_fs)) {
                    @unlink($passbook_fs);
                }

                $error =
                    "Error adding employee: " .
                    mysqli_stmt_error($stmt);
            }

            mysqli_stmt_close($stmt);

        } else {

            if (!empty($photo_fs) && file_exists($photo_fs)) {
                @unlink($photo_fs);
            }

            if (!empty($passbook_fs) && file_exists($passbook_fs)) {
                @unlink($passbook_fs);
            }

            $error =
                "Database error: " .
                mysqli_error($conn);
        }

    } else {

        if (!empty($photo_fs) && file_exists($photo_fs)) {
            @unlink($photo_fs);
        }

        if (!empty($passbook_fs) && file_exists($passbook_fs)) {
            @unlink($passbook_fs);
        }
    }
}
?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8" />

<meta
name="viewport"
content="width=device-width, initial-scale=1"
/>

<title>Add Employee - TEK-C</title>

<link
rel="apple-touch-icon"
sizes="180x180"
href="assets/fav/apple-touch-icon.png"
>

<link
rel="icon"
type="image/png"
sizes="32x32"
href="assets/fav/favicon-32x32.png"
>

<link
rel="icon"
type="image/png"
sizes="16x16"
href="assets/fav/favicon-16x16.png"
>

<link
rel="manifest"
href="assets/fav/site.webmanifest"
>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
/>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
rel="stylesheet"
/>

<link href="assets/css/layout-styles.css" rel="stylesheet" />
<link href="assets/css/topbar.css" rel="stylesheet" />
<link href="assets/css/footer.css" rel="stylesheet" />

<style>

.content-scroll{
    flex:1 1 auto;
    overflow:auto;
    padding:22px 22px 14px;
}

.form-panel{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:18px;
    margin-bottom:18px;
}

.section-header{
    display:flex;
    align-items:center;
    margin-bottom:25px;
    padding-bottom:15px;
    border-bottom:2px solid #f0f4f8;
}

.section-icon{
    width:38px;
    height:38px;
    border-radius:12px;
    background:var(--blue);
    display:flex;
    align-items:center;
    justify-content:center;
    margin-right:15px;
    font-size:20px;
    color:#fff;
}

.section-title{
    font-size:14px;
    font-weight:900;
    color:#2d3748;
    margin:0;
}

.section-subtitle{
    font-size:11px;
    color:#64748b;
    font-weight:700;
    margin-top:4px;
}

.form-label{
    font-weight:700;
    color:#4a5568;
    margin-bottom:8px;
    font-size:14px;
}

.required-label::after{
    content:" *";
    color:#e53e3e;
    font-weight:900;
}

.optional-badge{
    font-size:11px;
    color:#718096;
    font-weight:600;
    margin-left:5px;
}

.form-control,
.form-select{
    height:40px;
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:0 12px;
    font-size:12px;
    font-weight:700;
    transition:all .3s;
}

textarea.form-control{
    height:auto;
    padding:10px 12px;
}

.form-control:focus,
.form-select:focus{
    border-color:var(--blue);
    box-shadow:0 0 0 3px rgba(45,156,219,.1);
}

.form-control.is-invalid,
.form-select.is-invalid{
    border-color:#fc8181;
    background:#fff5f5;
}

.form-helper{
    font-size:12px;
    color:#718096;
    margin-top:5px;
    display:flex;
    align-items:center;
    gap:6px;
}

.btn-back{
    background:transparent;
    border:1px solid var(--border);
    border-radius:10px;
    padding:8px 16px;
    color:#4a5568;
    font-weight:700;
    display:flex;
    align-items:center;
    gap:6px;
    text-decoration:none;
}

.btn-back:hover{
    background:var(--bg);
    color:var(--blue);
    border-color:var(--blue);
}

.btn-submit{
    background:#111827;
    color:#fff;
    border:none;
    height:42px;
    padding:0 18px;
    border-radius:12px;
    font-weight:800;
    font-size:15px;
    display:flex;
    align-items:center;
    gap:10px;
    box-shadow:0 8px 20px rgba(45,156,219,.2);
    transition:all .3s;
    margin:40px auto;
}

.btn-submit:hover{
    background:#2a8bc9;
    transform:translateY(-2px);
    box-shadow:0 12px 25px rgba(45,156,219,.3);
    color:#fff;
}

.alert{
    border-radius:var(--radius);
    border:none;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.file-upload-container{
    border:2px dashed #cbd5e0;
    border-radius:12px;
    padding:14px;
    text-align:center;
    background:#fafcff;
    cursor:pointer;
    transition:all .3s;
    margin-top:5px;
}

.file-upload-container:hover{
    border-color:var(--blue);
    background:#f0f4ff;
}

.file-upload-icon{
    font-size:34px;
    color:#a0aec0;
    margin-bottom:8px;
}

.file-upload-text{
    font-size:11px;
    color:#64748b;
    font-weight:700;
    margin-bottom:5px;
}

.file-upload-subtext{
    font-size:12px;
    color:#a0aec0;
}

.file-preview{
    width:120px;
    height:120px;
    border-radius:12px;
    overflow:hidden;
    margin:10px auto;
    border:3px solid #e2e8f0;
    background:white;
    position:relative;
}

.file-preview img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.file-remove{
    position:absolute;
    top:5px;
    right:5px;
    width:26px;
    height:26px;
    background:#e53e3e;
    color:white;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:14px;
    cursor:pointer;
    border:2px solid white;
}

.form-section-heading{
    font-size:13px;
    font-weight:900;
    color:#4a5568;
    margin:12px 0 4px;
    padding-left:10px;
    border-left:4px solid var(--blue);
}

@media(max-width:768px){

    .content-scroll{
        padding:12px 10px 12px!important;
    }

    .container-fluid.maxw{
        padding-left:6px!important;
        padding-right:6px!important;
    }

    .form-panel{
        padding:12px!important;
        margin-bottom:12px;
        border-radius:14px;
    }

    .section-header{
        padding:10px!important;
        border-radius:12px;
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

<div class="container-fluid maxw">

<!-- Header -->

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

<div>

<h1 class="h3 fw-bold text-dark mb-1">
Add New Employee
</h1>

<p class="text-muted mb-0">
Complete employee details and create login access
</p>

</div>

<a href="manage-employees.php" class="btn-back">
<i class="bi bi-arrow-left"></i>
Back to Employees
</a>

</div>

<!-- Alerts -->

<?php if ($success): ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">

<i class="bi bi-check-circle-fill me-2"></i>

<strong>Success!</strong>
<?php echo e($success); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
aria-label="Close"
></button>

</div>

<?php endif; ?>

<?php if ($error): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<strong>Error!</strong>
<?php echo e($error); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
aria-label="Close"
></button>

</div>

<?php endif; ?>

<?php if (!empty($validation_errors)): ?>

<div class="alert alert-warning alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<strong>Please fix the following errors:</strong>

<ul class="mb-0 mt-2 ps-3">

<?php foreach ($validation_errors as $err): ?>

<li>
<?php echo e($err); ?>
</li>

<?php endforeach; ?>

</ul>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
aria-label="Close"
></button>

</div>

<?php endif; ?>

<form
method="POST"
enctype="multipart/form-data"
id="employeeForm"
novalidate
>

<!-- Identity Details -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon">
<i class="bi bi-person-badge"></i>
</div>

<div>

<h3 class="section-title">
Identity Details
</h3>

<p class="section-subtitle">
Basic personal information
</p>

</div>

</div>

<div class="row g-4">

<div class="col-md-6">

<label for="full_name" class="form-label required-label">
Full Name
</label>

<input
type="text"
class="form-control"
id="full_name"
name="full_name"
value="<?php echo e($_POST['full_name'] ?? ''); ?>"
placeholder="Enter full name"
required
>

</div>

<div class="col-md-6">

<label for="employee_code" class="form-label required-label">
Employee Code
</label>

<input
type="text"
class="form-control"
id="employee_code"
name="employee_code"
value="<?php echo e($_POST['employee_code'] ?? ''); ?>"
placeholder="EMP001"
required
>

</div>

<div class="col-md-6">

<label for="date_of_birth" class="form-label">
Date of Birth
<span class="optional-badge">(Optional)</span>
</label>

<input
type="date"
class="form-control"
id="date_of_birth"
name="date_of_birth"
value="<?php echo e($_POST['date_of_birth'] ?? ''); ?>"
>

</div>

<div class="col-md-6">

<label for="gender" class="form-label">
Gender
<span class="optional-badge">(Optional)</span>
</label>

<select
class="form-select"
id="gender"
name="gender"
>

<option value="">
Select Gender
</option>

<?php foreach ($genders as $g): ?>

<option
value="<?php echo e($g); ?>"
<?php echo (($_POST['gender'] ?? '') === $g) ? 'selected' : ''; ?>
>
<?php echo e($g); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label for="blood_group" class="form-label">
Blood Group
<span class="optional-badge">(Optional)</span>
</label>

<select
class="form-select"
id="blood_group"
name="blood_group"
>

<option value="">
Select Blood Group
</option>

<?php foreach ($blood_groups as $bg): ?>

<option
value="<?php echo e($bg); ?>"
<?php echo (($_POST['blood_group'] ?? '') === $bg) ? 'selected' : ''; ?>
>
<?php echo e($bg); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label for="photo" class="form-label">
Profile Photo
<span class="optional-badge">(Optional)</span>
</label>

<div
class="file-upload-container"
onclick="document.getElementById('photo').click()"
id="photoUploadContainer"
>

<div class="file-upload-icon">
<i class="bi bi-person-square"></i>
</div>

<div class="file-upload-text">
Click to upload photo
</div>

<div class="file-upload-subtext">
JPG, PNG, GIF, WebP. Max 5MB
</div>

<input
type="file"
class="d-none"
id="photo"
name="photo"
accept="image/*"
>

</div>

<div class="file-preview d-none" id="photoPreview">

<img src="" alt="Photo Preview">

<div class="file-remove" onclick="removeFile('photo')">
<i class="bi bi-x"></i>
</div>

</div>

</div>

</div>

</div>

<!-- Contact Details -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#f5576c;">
<i class="bi bi-telephone"></i>
</div>

<div>

<h3 class="section-title">
Contact Details
</h3>

<p class="section-subtitle">
Communication and emergency contact
</p>

</div>

</div>

<div class="row g-4">

<div class="col-md-6">

<label for="mobile_number" class="form-label required-label">
Mobile Number
</label>

<input
type="tel"
class="form-control"
id="mobile_number"
name="mobile_number"
value="<?php echo e($_POST['mobile_number'] ?? ''); ?>"
placeholder="9876543210"
required
>

<div class="form-helper">
<i class="bi bi-phone"></i>
10 digits only
</div>

</div>

<div class="col-md-6">

<label for="email" class="form-label">
Email Address
<span class="optional-badge">(Optional)</span>
</label>

<input
type="email"
class="form-control"
id="email"
name="email"
value="<?php echo e($_POST['email'] ?? ''); ?>"
placeholder="employee@example.com"
>

</div>

<div class="col-12">

<label for="current_address" class="form-label">
Current Address
<span class="optional-badge">(Optional)</span>
</label>

<textarea
class="form-control"
id="current_address"
name="current_address"
rows="2"
placeholder="Enter current address"
><?php echo e($_POST['current_address'] ?? ''); ?></textarea>

</div>

<div class="col-12">

<h5 class="form-section-heading">
Emergency Contact
<span class="optional-badge">(Optional)</span>
</h5>

</div>

<div class="col-md-6">

<label for="emergency_contact_name" class="form-label">
Emergency Contact Name
</label>

<input
type="text"
class="form-control"
id="emergency_contact_name"
name="emergency_contact_name"
value="<?php echo e($_POST['emergency_contact_name'] ?? ''); ?>"
placeholder="Contact person name"
>

</div>

<div class="col-md-6">

<label for="emergency_contact_phone" class="form-label">
Emergency Contact Phone
</label>

<input
type="tel"
class="form-control"
id="emergency_contact_phone"
name="emergency_contact_phone"
value="<?php echo e($_POST['emergency_contact_phone'] ?? ''); ?>"
placeholder="9876543210"
>

</div>

</div>

</div>

<!-- Employment Details -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#4facfe;">
<i class="bi bi-briefcase"></i>
</div>

<div>

<h3 class="section-title">
Employment Details
</h3>

<p class="section-subtitle">
Department, designation, reporting and location
</p>

</div>

</div>

<div class="row g-4">

<div class="col-md-6">

<label for="date_of_joining" class="form-label required-label">
Date of Joining
</label>

<input
type="date"
class="form-control"
id="date_of_joining"
name="date_of_joining"
value="<?php echo e($_POST['date_of_joining'] ?? ''); ?>"
required
>

</div>

<div class="col-md-6">

<label for="department" class="form-label required-label">
Department
</label>

<select
class="form-select"
id="department"
name="department"
required
>

<option value="">
Select Department
</option>

<?php foreach ($departments as $dept): ?>

<option
value="<?php echo e($dept); ?>"
<?php echo (($_POST['department'] ?? '') === $dept) ? 'selected' : ''; ?>
>
<?php echo e($dept); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label for="designation" class="form-label required-label">
Designation
</label>

<select
class="form-select"
id="designation"
name="designation"
required
>

<option value="">
Select Designation
</option>

<?php foreach ($designations as $des): ?>

<option
value="<?php echo e($des); ?>"
<?php echo (($_POST['designation'] ?? '') === $des) ? 'selected' : ''; ?>
>
<?php echo e($des); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label for="employee_status" class="form-label">
Employee Status
</label>

<select
class="form-select"
id="employee_status"
name="employee_status"
>

<?php foreach ($employee_statuses as $status): ?>

<option
value="<?php echo e($status); ?>"
<?php echo (($_POST['employee_status'] ?? 'active') === $status) ? 'selected' : ''; ?>
>
<?php echo e(ucfirst($status)); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label for="reporting_to" class="form-label">
Reporting Manager
<span class="optional-badge">(Optional)</span>
</label>

<select
class="form-select"
id="reporting_to"
name="reporting_to"
>

<option value="">
Select Reporting Manager
</option>

<?php foreach ($existing_employees as $emp): ?>

<option
value="<?php echo (int)$emp['id']; ?>"
<?php echo ((int)($_POST['reporting_to'] ?? 0) === (int)$emp['id']) ? 'selected' : ''; ?>
>
<?php echo e($emp['full_name'] . ' - ' . $emp['designation']); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label for="work_location" class="form-label">
Work Location
<span class="optional-badge">(Optional)</span>
</label>

<input
type="text"
class="form-control"
id="work_location"
name="work_location"
value="<?php echo e($_POST['work_location'] ?? ''); ?>"
placeholder="Office / City / Site location"
>

</div>

<div class="col-12">

<label for="site_name" class="form-label">
Site / Project Name
<span class="optional-badge">(Optional)</span>
</label>

<input
type="text"
class="form-control"
id="site_name"
name="site_name"
value="<?php echo e($_POST['site_name'] ?? ''); ?>"
placeholder="Assigned site / project"
>

</div>

</div>

</div>

<!-- Login Credentials -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#fa709a;">
<i class="bi bi-key"></i>
</div>

<div>

<h3 class="section-title">
Login Credentials
</h3>

<p class="section-subtitle">
Username and password for system access
</p>

</div>

</div>

<div class="row g-4">

<div class="col-md-6">

<label for="username" class="form-label required-label">
Username
</label>

<input
type="text"
class="form-control"
id="username"
name="username"
value="<?php echo e($_POST['username'] ?? ''); ?>"
placeholder="username"
required
>

</div>

<div class="col-md-6">

<label for="password" class="form-label required-label">
Password
</label>

<div class="input-group">

<input
type="password"
class="form-control"
id="password"
name="password"
placeholder="Enter password"
required
>

<button
class="btn btn-outline-secondary"
type="button"
id="togglePassword"
>
<i class="bi bi-eye"></i>
</button>

<button
class="btn btn-outline-secondary"
type="button"
id="generatePasswordBtn"
>
<i class="bi bi-shuffle"></i>
</button>

</div>

<div class="form-helper">
<i class="bi bi-shield-lock"></i>
Use a strong password
</div>

</div>

</div>

</div>

<!-- Compliance Documents -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#30cfd0;">
<i class="bi bi-shield-check"></i>
</div>

<div>

<h3 class="section-title">
Compliance Documents
</h3>

<p class="section-subtitle">
Aadhar and PAN details
</p>

</div>

</div>

<div class="row g-4">

<div class="col-md-6">

<label for="aadhar_card_number" class="form-label">
Aadhar Card Number
<span class="optional-badge">(Optional)</span>
</label>

<input
type="text"
class="form-control"
id="aadhar_card_number"
name="aadhar_card_number"
value="<?php echo e($_POST['aadhar_card_number'] ?? ''); ?>"
placeholder="12 digit Aadhar number"
>

</div>

<div class="col-md-6">

<label for="pancard_number" class="form-label">
PAN Card Number
<span class="optional-badge">(Optional)</span>
</label>

<input
type="text"
class="form-control"
id="pancard_number"
name="pancard_number"
value="<?php echo e($_POST['pancard_number'] ?? ''); ?>"
placeholder="ABCDE1234F"
>

</div>

</div>

</div>

<!-- Banking Information -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#43e97b;">
<i class="bi bi-bank"></i>
</div>

<div>

<h3 class="section-title">
Banking Information
</h3>

<p class="section-subtitle">
Salary account and passbook details
</p>

</div>

</div>

<div class="row g-4">

<div class="col-md-6">

<label for="bank_account_number" class="form-label">
Bank Account Number
<span class="optional-badge">(Optional)</span>
</label>

<input
type="text"
class="form-control"
id="bank_account_number"
name="bank_account_number"
value="<?php echo e($_POST['bank_account_number'] ?? ''); ?>"
placeholder="Account number"
>

</div>

<div class="col-md-6">

<label for="ifsc_code" class="form-label">
IFSC Code
<span class="optional-badge">(Optional)</span>
</label>

<input
type="text"
class="form-control"
id="ifsc_code"
name="ifsc_code"
value="<?php echo e($_POST['ifsc_code'] ?? ''); ?>"
placeholder="IFSC code"
>

</div>

<div class="col-12">

<label for="passbook_photo" class="form-label">
Passbook / Cancelled Cheque
<span class="optional-badge">(Optional)</span>
</label>

<div
class="file-upload-container"
onclick="document.getElementById('passbook_photo').click()"
id="passbook_photoUploadContainer"
>

<div class="file-upload-icon">
<i class="bi bi-file-image"></i>
</div>

<div class="file-upload-text">
Click to upload passbook or cheque
</div>

<div class="file-upload-subtext">
JPG, PNG, GIF, WebP. Max 5MB
</div>

<input
type="file"
class="d-none"
id="passbook_photo"
name="passbook_photo"
accept="image/*"
>

</div>

<div class="file-preview d-none" id="passbook_photoPreview">

<img src="" alt="Passbook Preview">

<div class="file-remove" onclick="removeFile('passbook_photo')">
<i class="bi bi-x"></i>
</div>

</div>

</div>

</div>

</div>

<!-- Submit -->

<div>

<a href="manage-employees.php" class="btn btn-light border">
Cancel
</a>

<button type="submit" class="btn-submit">
<i class="bi bi-person-plus"></i>
Add Employee
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

document.addEventListener('DOMContentLoaded', function(){

    const fileInputs = [
        'photo',
        'passbook_photo'
    ];

    fileInputs.forEach(function(inputId){

        const input =
            document.getElementById(inputId);

        const container =
            document.getElementById(inputId + 'UploadContainer');

        const preview =
            document.getElementById(inputId + 'Preview');

        if (!input || !container || !preview) {
            return;
        }

        const previewImg =
            preview.querySelector('img');

        input.addEventListener('change', function(){

            if (this.files && this.files[0]) {

                const reader =
                    new FileReader();

                reader.onload = function(ev){

                    previewImg.src =
                        ev.target.result;

                    preview.classList.remove('d-none');
                    container.classList.add('d-none');
                };

                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    window.removeFile = function(inputId){

        const input =
            document.getElementById(inputId);

        const container =
            document.getElementById(inputId + 'UploadContainer');

        const preview =
            document.getElementById(inputId + 'Preview');

        if (!input || !container || !preview) {
            return;
        }

        input.value = '';
        preview.classList.add('d-none');
        container.classList.remove('d-none');
    };

    const togglePassword =
        document.getElementById('togglePassword');

    const passwordInput =
        document.getElementById('password');

    if (togglePassword && passwordInput) {

        togglePassword.addEventListener('click', function(){

            const type =
                passwordInput.type === 'password'
                ? 'text'
                : 'password';

            passwordInput.type =
                type;

            this.innerHTML =
                type === 'password'
                ? '<i class="bi bi-eye"></i>'
                : '<i class="bi bi-eye-slash"></i>';
        });
    }

    const generatePasswordBtn =
        document.getElementById('generatePasswordBtn');

    if (generatePasswordBtn && passwordInput) {

        generatePasswordBtn.addEventListener('click', function(){

            const chars =
                'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';

            let pwd = '';

            for (let i = 0; i < 12; i++) {
                pwd += chars.charAt(
                    Math.floor(Math.random() * chars.length)
                );
            }

            passwordInput.value =
                pwd;

            passwordInput.type =
                'text';

            if (togglePassword) {
                togglePassword.innerHTML =
                    '<i class="bi bi-eye-slash"></i>';
            }
        });
    }

    const employeeCodeInput =
        document.getElementById('employee_code');

    const usernameInput =
        document.getElementById('username');

    if (employeeCodeInput && usernameInput) {

        employeeCodeInput.addEventListener('blur', function(){

            if (!usernameInput.value && this.value) {

                usernameInput.value =
                    this.value
                    .toLowerCase()
                    .replace(/\s+/g, '')
                    .replace(/[^a-z0-9]/g, '');
            }
        });
    }

    const mobileInput =
        document.getElementById('mobile_number');

    if (mobileInput) {
        mobileInput.addEventListener('input', function(){
            this.value =
                this.value.replace(/\D/g, '').slice(0, 10);
        });
    }

    const emergencyPhone =
        document.getElementById('emergency_contact_phone');

    if (emergencyPhone) {
        emergencyPhone.addEventListener('input', function(){
            this.value =
                this.value.replace(/\D/g, '').slice(0, 15);
        });
    }

    const aadharInput =
        document.getElementById('aadhar_card_number');

    if (aadharInput) {
        aadharInput.addEventListener('input', function(){
            this.value =
                this.value.replace(/\D/g, '').slice(0, 12);
        });
    }

    const panInput =
        document.getElementById('pancard_number');

    if (panInput) {
        panInput.addEventListener('input', function(){
            this.value =
                this.value
                .toUpperCase()
                .replace(/[^A-Z0-9]/g, '')
                .slice(0, 10);
        });
    }

    const bankInput =
        document.getElementById('bank_account_number');

    if (bankInput) {
        bankInput.addEventListener('input', function(){
            this.value =
                this.value.replace(/\D/g, '');
        });
    }

    const ifscInput =
        document.getElementById('ifsc_code');

    if (ifscInput) {
        ifscInput.addEventListener('input', function(){
            this.value =
                this.value
                .toUpperCase()
                .replace(/[^A-Z0-9]/g, '')
                .slice(0, 11);
        });
    }

    const form =
        document.getElementById('employeeForm');

    const setInvalid =
        (el) => el && el.classList.add('is-invalid');

    if (form) {

        form.addEventListener('submit', function(e){

            let valid =
                true;

            const errorMessages =
                [];

            form.querySelectorAll('.is-invalid').forEach(function(el){
                el.classList.remove('is-invalid');
            });

            const requiredFields =
                form.querySelectorAll('[required]');

            requiredFields.forEach(function(field){

                if (!String(field.value || '').trim()) {

                    valid =
                        false;

                    setInvalid(field);

                    const label =
                        form.querySelector('label[for="' + field.id + '"]');

                    const fieldName =
                        label
                        ? label.textContent.replace('*', '').trim()
                        : field.name;

                    errorMessages.push(
                        fieldName + ' is required'
                    );
                }
            });

            const mobile =
                document.getElementById('mobile_number');

            if (
                mobile &&
                mobile.value &&
                !/^[0-9]{10}$/.test(mobile.value)
            ) {
                valid = false;
                setInvalid(mobile);
                errorMessages.push('Mobile number must be 10 digits');
            }

            const email =
                document.getElementById('email');

            if (
                email &&
                email.value &&
                !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)
            ) {
                valid = false;
                setInvalid(email);
                errorMessages.push('Invalid email format');
            }

            const aadhar =
                document.getElementById('aadhar_card_number');

            if (
                aadhar &&
                aadhar.value &&
                !/^[0-9]{12}$/.test(aadhar.value)
            ) {
                valid = false;
                setInvalid(aadhar);
                errorMessages.push('Aadhar number must be 12 digits');
            }

            const pan =
                document.getElementById('pancard_number');

            if (
                pan &&
                pan.value &&
                !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan.value)
            ) {
                valid = false;
                setInvalid(pan);
                errorMessages.push('PAN card number must be in format: ABCDE1234F');
            }

            if (!valid) {

                e.preventDefault();

                const alertDiv =
                    document.createElement('div');

                alertDiv.className =
                    'alert alert-danger alert-dismissible fade show';

                alertDiv.innerHTML =
                    `
                    <div class="d-flex align-items-start">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div>
                            <strong>Form Validation Error</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                ${errorMessages.map(function(msg){
                                    return '<li>' + msg + '</li>';
                                }).join('')}
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;

                const container =
                    document.querySelector('.container-fluid.maxw');

                const headerBlock =
                    container.children[0];

                container.insertBefore(
                    alertDiv,
                    headerBlock.nextSibling
                );

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
if (isset($conn)) {
    mysqli_close($conn);
}
?>