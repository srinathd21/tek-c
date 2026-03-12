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
 * ✅ AUTH: Allow HR to add employees
 * - HR if designation == 'HR' OR department == 'HR'
 * ------------------------------------------------------------
 */
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}
$designation = trim((string)($_SESSION['designation'] ?? ''));
$department  = trim((string)($_SESSION['department'] ?? ''));

$isHr = (strtolower($designation) === 'hr') || (strtolower($department) === 'hr');
if (!$isHr) {
    $fallback = $_SESSION['role_redirect'] ?? '../login.php';
    header("Location: " . $fallback);
    exit;
}

// Helpers
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .form-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 25px; margin-bottom: 30px; }
    .section-header { display: flex; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #f0f4f8; }
    .section-icon { width: 48px; height: 48px; border-radius: 12px; background: var(--blue); display: flex; align-items: center; justify-content: center; margin-right: 15px; font-size: 20px; color: white; }
    .section-title { font-size: 18px; font-weight: 800; color: #2d3748; margin: 0; }
    .section-subtitle { font-size: 14px; color: #718096; margin-top: 4px; }
    .form-label { font-weight: 700; color: #4a5568; margin-bottom: 8px; font-size: 14px; }
    .required-label::after { content: " *"; color: #e53e3e; font-weight: 900; }
    .form-control, .form-select { border: 2px solid #e2e8f0; border-radius: 10px; padding: 12px 15px; font-size: 14px; transition: all 0.3s; }
    .form-control:focus, .form-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1); }
    .file-upload-container { border: 2px dashed #cbd5e0; border-radius: 12px; padding: 20px; text-align: center; background: #f8fafc; cursor: pointer; transition: all 0.3s; margin-top: 5px; }
    .file-upload-container:hover { border-color: var(--blue); background: #f0f4ff; }
    .file-upload-icon { font-size: 40px; color: #a0aec0; margin-bottom: 10px; }
    .file-preview { width: 120px; height: 120px; border-radius: 12px; overflow: hidden; margin: 10px auto; border: 3px solid #e2e8f0; background: white; position: relative; }
    .file-preview img { width: 100%; height: 100%; object-fit: cover; }
    .file-remove { position: absolute; top: -8px; right: -8px; width: 28px; height: 28px; background: #e53e3e; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer; border: 2px solid white; }
    .btn-back { background: transparent; border: 1px solid var(--border); border-radius: 10px; padding: 8px 16px; color: #4a5568; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .btn-back:hover { background: var(--bg); color: var(--blue); border-color: var(--blue); }
    .btn-submit { background: var(--blue); color: white; border: none; padding: 14px 35px; border-radius: 12px; font-weight: 800; font-size: 15px; display: inline-flex; align-items: center; gap: 10px; box-shadow: 0 8px 20px rgba(45, 156, 219, 0.2); transition: all 0.3s; }
    .btn-submit:hover { background: #2a8bc9; transform: translateY(-2px); box-shadow: 0 12px 25px rgba(45, 156, 219, 0.3); color: white; }
    .form-section-heading { font-size: 16px; font-weight: 800; color: #4a5568; margin: 20px 0 15px; padding-left: 12px; border-left: 4px solid var(--blue); }
    .alert { border-radius: var(--radius); border: none; box-shadow: var(--shadow); margin-bottom: 20px; }
    .optional-badge { font-size: 11px; color: #718096; font-weight: 600; margin-left: 5px; }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid maxw">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Add New Employee</h1>
                        <p class="text-muted mb-0">Complete essential details to register a new team member</p>
                    </div>
                    <a href="employees.php" class="btn-back">
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

                <!-- FORM (Same structure as your original, kept unchanged except upload storage) -->
                <form method="POST" enctype="multipart/form-data" id="employeeForm" novalidate>

                    <!-- Identity -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-person-badge"></i></div>
                            <div>
                                <h3 class="section-title">Identity Details</h3>
                                <p class="section-subtitle">Basic personal information</p>
                            </div>
                        </div>

                        <div class="row g-4">
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
                            <div class="section-icon" style="background:#f5576c;"><i class="bi bi-telephone"></i></div>
                            <div>
                                <h3 class="section-title">Contact Details</h3>
                                <p class="section-subtitle">Communication information</p>
                            </div>
                        </div>

                        <div class="row g-4">
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
                            <div class="section-icon" style="background:#4facfe;"><i class="bi bi-briefcase"></i></div>
                            <div>
                                <h3 class="section-title">Employment Details</h3>
                                <p class="section-subtitle">Professional information</p>
                            </div>
                        </div>

                        <div class="row g-4">
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
                            <div class="section-icon" style="background:#fa709a;"><i class="bi bi-key"></i></div>
                            <div>
                                <h3 class="section-title">Login Credentials</h3>
                                <p class="section-subtitle">System access credentials</p>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="username" class="form-label required-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username"
                                       value="<?php echo e($_POST['username'] ?? ''); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="password" class="form-label required-label">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword"><i class="bi bi-eye"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" id="generatePasswordBtn"><i class="bi bi-shuffle"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Compliance -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon" style="background:#30cfd0;"><i class="bi bi-shield-check"></i></div>
                            <div>
                                <h3 class="section-title">Compliance Documents <span class="optional-badge">(Optional)</span></h3>
                                <p class="section-subtitle">Official documents (can be added later)</p>
                            </div>
                        </div>

                        <div class="row g-4">
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
                            <div class="section-icon" style="background:#43e97b;"><i class="bi bi-bank"></i></div>
                            <div>
                                <h3 class="section-title">Banking Information <span class="optional-badge">(Optional)</span></h3>
                                <p class="section-subtitle">Salary details (can be added later)</p>
                            </div>
                        </div>

                        <div class="row g-4">
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
                                    <div class="file-upload-subtext">Max 5MB</div>
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
                    <div class="text-center mt-5">
                        <button type="submit" class="btn-submit">
                            <i class="bi bi-person-plus"></i> Add Employee to System
                        </button>
                        <p class="text-muted mt-3" style="font-size: 14px;">
                            <i class="bi bi-info-circle me-1"></i>
                            Fields marked with * are required. Other fields can be added later.
                        </p>
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

    // File preview
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

    // Toggle password
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;
            this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        });
    }

    // Generate password
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

    // Auto-fill username from employee code
    const employeeCodeInput = document.getElementById('employee_code');
    const usernameInput = document.getElementById('username');
    if (employeeCodeInput && usernameInput) {
        employeeCodeInput.addEventListener('blur', function() {
            if (!usernameInput.value && this.value) {
                usernameInput.value = this.value.toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9]/g, '');
            }
        });
    }

    // Real-time format helpers
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


