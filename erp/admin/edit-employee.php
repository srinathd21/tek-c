<?php
// edit-employee.php
// Same style as your add-client page (panel sections, modern UI)
// Business name: AS Electricals
// Updates employees table (based on your SQL dump)
// - Photo upload optional (replaces old photo if new uploaded)
// - Passbook photo upload optional (replaces old if new uploaded)
// - Password optional: if blank, keep existing password

session_start();
require_once 'includes/db-config.php';

// OPTIONAL auth
// if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error = '';
$validation_errors = [];
$emp = null;

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function showVal($v, $dash=''){
  $v = (string)$v;
  return $v === '' ? $dash : $v;
}

function handleUpload($field, $targetDir, &$errMsg, $maxMB = 10, $allowedExt = ['jpg','jpeg','png','webp','pdf']){
  if (empty($_FILES[$field]['name'])) return ''; // no new upload

  $uploadDir = 'uploads/' . trim($targetDir, '/').'/';
  if (!file_exists($uploadDir)) { @mkdir($uploadDir, 0777, true); }

  $file = $_FILES[$field];
  if ($file['error'] !== UPLOAD_ERR_OK) { $errMsg = "File upload error (".$field."): ".$file['error']; return false; }

  $fileName = basename($file['name']);
  $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

  if (!in_array($ext, $allowedExt, true)) {
    $errMsg = "Invalid file type for ".$field.". Allowed: ".implode(', ', $allowedExt);
    return false;
  }

  if (($file['size'] ?? 0) > ($maxMB * 1024 * 1024)) {
    $errMsg = "File too large for ".$field." (max ".$maxMB."MB).";
    return false;
  }

  $newName = uniqid($field . '_', true) . '_' . time() . '.' . $ext;
  $targetPath = $uploadDir . $newName;

  if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    $errMsg = "Failed to save uploaded file for ".$field.".";
    return false;
  }

  return $targetPath;
}

// ---------- Get employee ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  die("Invalid employee ID.");
}

$stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? LIMIT 1");
if (!$stmt) { die("Database error: " . mysqli_error($conn)); }
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$emp = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$emp) { die("Employee not found."); }

// ---------- Options ----------
$departments = ['PM','CM','IFM','QS','HR','ACCOUNTS'];
$designations = [
  'Vice President',
  'General Manager',
  'Director',              // ✅ ADDED DIRECTOR
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
$genders = ['Male','Female','Other'];
$statuses = ['active','inactive','resigned'];

// ---------- Handle POST (Update) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // BASIC
  $full_name        = trim($_POST['full_name'] ?? '');
  $employee_code    = trim($_POST['employee_code'] ?? '');
  $date_of_birth    = trim($_POST['date_of_birth'] ?? '');
  $gender           = trim($_POST['gender'] ?? '');
  $blood_group      = trim($_POST['blood_group'] ?? '');
  $mobile_number    = trim($_POST['mobile_number'] ?? '');
  $email            = trim($_POST['email'] ?? '');
  $current_address  = trim($_POST['current_address'] ?? '');

  // EMERGENCY
  $emg_name  = trim($_POST['emergency_contact_name'] ?? '');
  $emg_phone = trim($_POST['emergency_contact_phone'] ?? '');

  // EMPLOYMENT
  $date_of_joining  = trim($_POST['date_of_joining'] ?? '');
  $department       = trim($_POST['department'] ?? '');
  $designation      = trim($_POST['designation'] ?? '');
  $reporting_manager= trim($_POST['reporting_manager'] ?? '');
  $work_location    = trim($_POST['work_location'] ?? '');
  $site_name        = trim($_POST['site_name'] ?? '');
  $employee_status  = trim($_POST['employee_status'] ?? '');

  // LOGIN
  $username = trim($_POST['username'] ?? '');
  $new_password = trim($_POST['password'] ?? ''); // optional

  // KYC + BANK
  $aadhar = trim($_POST['aadhar_card_number'] ?? '');
  $pan    = trim($_POST['pancard_number'] ?? '');
  $bank   = trim($_POST['bank_account_number'] ?? '');
  $ifsc   = trim($_POST['ifsc_code'] ?? '');

  // VALIDATION (required fields)
  if ($full_name === '') $validation_errors[] = "Full name is required";
  if ($employee_code === '') $validation_errors[] = "Employee code is required";
  if ($username === '') $validation_errors[] = "Username is required";

  // Optional validation
  if ($mobile_number !== '' && !preg_match('/^[0-9]{10,15}$/', $mobile_number)) $validation_errors[] = "Mobile number must be 10 to 15 digits";
  if ($emg_phone !== '' && !preg_match('/^[0-9]{10,15}$/', $emg_phone)) $validation_errors[] = "Emergency phone must be 10 to 15 digits";
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = "Invalid email format";

  if ($gender !== '' && !in_array($gender, $genders, true)) $validation_errors[] = "Invalid gender selected";
  if ($department !== '' && !in_array($department, $departments, true)) $validation_errors[] = "Invalid department selected";

  // ✅ Designation validation now includes Director (because it's in $designations array)
  if ($designation !== '' && !in_array($designation, $designations, true)) $validation_errors[] = "Invalid designation selected";

  if ($employee_status !== '' && !in_array($employee_status, $statuses, true)) $validation_errors[] = "Invalid status selected";

  // Unique employee_code / username checks (excluding self)
  $dup_sql = "SELECT id FROM employees WHERE (employee_code = ? OR username = ?) AND id <> ? LIMIT 1";
  $dup_stmt = mysqli_prepare($conn, $dup_sql);
  if ($dup_stmt) {
    mysqli_stmt_bind_param($dup_stmt, "ssi", $employee_code, $username, $id);
    mysqli_stmt_execute($dup_stmt);
    mysqli_stmt_store_result($dup_stmt);
    if (mysqli_stmt_num_rows($dup_stmt) > 0) $validation_errors[] = "Employee code or username already exists";
    mysqli_stmt_close($dup_stmt);
  }

  // Password rule if provided
  $hashed_password = '';
  if ($new_password !== '') {
    if (strlen($new_password) < 8) $validation_errors[] = "Password must be at least 8 characters";
    else $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
  }

  // Uploads (optional)
  $uploadErr = '';
  $new_photo_path = handleUpload('photo', 'employees/photos', $uploadErr, 10, ['jpg','jpeg','png','webp']);
  if ($new_photo_path === false) $validation_errors[] = $uploadErr;

  $uploadErr2 = '';
  $new_passbook_path = handleUpload('passbook_photo', 'employees/passbook', $uploadErr2, 10, ['jpg','jpeg','png','webp','pdf']);
  if ($new_passbook_path === false) $validation_errors[] = $uploadErr2;

  if (empty($validation_errors)) {

    // Keep old paths if no new upload
    $final_photo = ($new_photo_path !== '') ? $new_photo_path : ($emp['photo'] ?? '');
    $final_passbook = ($new_passbook_path !== '') ? $new_passbook_path : ($emp['passbook_photo'] ?? '');

    // If new file uploaded, delete old file (best-effort)
    if ($new_photo_path !== '' && !empty($emp['photo']) && $emp['photo'] !== $new_photo_path && file_exists($emp['photo'])) {
      @unlink($emp['photo']);
    }
    if ($new_passbook_path !== '' && !empty($emp['passbook_photo']) && $emp['passbook_photo'] !== $new_passbook_path && file_exists($emp['passbook_photo'])) {
      @unlink($emp['passbook_photo']);
    }

    // Build SQL (conditional password update)
    if ($hashed_password !== '') {
      $sql = "UPDATE employees SET
        full_name=?, employee_code=?, photo=?, date_of_birth=?, gender=?, blood_group=?, mobile_number=?, email=?, current_address=?,
        emergency_contact_name=?, emergency_contact_phone=?,
        date_of_joining=?, department=?, designation=?, reporting_manager=?, work_location=?, site_name=?, employee_status=?,
        username=?, password=?,
        aadhar_card_number=?, pancard_number=?, bank_account_number=?, ifsc_code=?, passbook_photo=?
        WHERE id=? LIMIT 1";
      $stmtU = mysqli_prepare($conn, $sql);
      if ($stmtU) {
        mysqli_stmt_bind_param($stmtU, "sssssssssssssssssssssssssi",
          $full_name, $employee_code, $final_photo, $date_of_birth, $gender, $blood_group, $mobile_number, $email, $current_address,
          $emg_name, $emg_phone,
          $date_of_joining, $department, $designation, $reporting_manager, $work_location, $site_name, $employee_status,
          $username, $hashed_password,
          $aadhar, $pan, $bank, $ifsc, $final_passbook,
          $id
        );
      }
    } else {
      $sql = "UPDATE employees SET
        full_name=?, employee_code=?, photo=?, date_of_birth=?, gender=?, blood_group=?, mobile_number=?, email=?, current_address=?,
        emergency_contact_name=?, emergency_contact_phone=?,
        date_of_joining=?, department=?, designation=?, reporting_manager=?, work_location=?, site_name=?, employee_status=?,
        username=?,
        aadhar_card_number=?, pancard_number=?, bank_account_number=?, ifsc_code=?, passbook_photo=?
        WHERE id=? LIMIT 1";
      $stmtU = mysqli_prepare($conn, $sql);
      if ($stmtU) {
        mysqli_stmt_bind_param($stmtU, "ssssssssssssssssssssssssi",
          $full_name, $employee_code, $final_photo, $date_of_birth, $gender, $blood_group, $mobile_number, $email, $current_address,
          $emg_name, $emg_phone,
          $date_of_joining, $department, $designation, $reporting_manager, $work_location, $site_name, $employee_status,
          $username,
          $aadhar, $pan, $bank, $ifsc, $final_passbook,
          $id
        );
      }
    }

    if (!isset($stmtU) || !$stmtU) {
      $error = "Database error: " . mysqli_error($conn);
    } else {
      if (mysqli_stmt_execute($stmtU)) {
        $success = "Employee updated successfully!";
        mysqli_stmt_close($stmtU);

        // refresh $emp
        $stmtR = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtR, "i", $id);
        mysqli_stmt_execute($stmtR);
        $resR = mysqli_stmt_get_result($stmtR);
        $emp = mysqli_fetch_assoc($resR);
        mysqli_stmt_close($stmtR);
      } else {
        $error = "Error updating employee: " . mysqli_stmt_error($stmtU);
        mysqli_stmt_close($stmtU);
      }
    }
  }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Employee - AS Electricals</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .form-panel { background:#fff; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 25px; margin-bottom: 30px; }
    .section-header { display:flex; align-items:center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #f0f4f8; }
    .section-icon { width: 48px; height: 48px; border-radius: 12px; background: var(--blue); display:flex; align-items:center; justify-content:center; margin-right: 15px; font-size: 20px; color: #fff; }
    .section-title { font-size: 18px; font-weight: 800; color: #2d3748; margin: 0; }
    .section-subtitle { font-size: 14px; color: #718096; margin-top: 4px; }
    .form-label { font-weight: 700; color:#4a5568; margin-bottom:8px; font-size: 14px; }
    .required-label::after { content:" *"; color:#e53e3e; font-weight:900; }
    .optional-badge { font-size: 11px; color:#718096; font-weight: 600; margin-left: 5px; }
    .form-control, .form-select { border:2px solid #e2e8f0; border-radius:10px; padding: 12px 15px; font-size: 14px; transition: all .3s; }
    .form-control:focus, .form-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(45,156,219,.1); }
    .form-control.is-invalid, .form-select.is-invalid { border-color:#fc8181; background:#fff5f5; }
    .form-helper { font-size:12px; color:#718096; margin-top:5px; display:flex; align-items:center; gap:6px; }
    .btn-back { background: transparent; border: 1px solid var(--border); border-radius: 10px; padding: 8px 16px; color: #4a5568; font-weight: 700; display:flex; align-items:center; gap:6px; text-decoration: none; }
    .btn-back:hover { background: var(--bg); color: var(--blue); border-color: var(--blue); }
    .btn-submit { background: var(--blue); color:#fff; border:none; padding: 14px 35px; border-radius: 12px; font-weight: 800; font-size: 15px; display:flex; align-items:center; gap:10px; box-shadow: 0 8px 20px rgba(45,156,219,.2); transition: all .3s; margin: 40px auto; }
    .btn-submit:hover { background:#2a8bc9; transform: translateY(-2px); box-shadow: 0 12px 25px rgba(45,156,219,.3); color:#fff; }
    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }
    .file-upload-container { border: 2px dashed #cbd5e0; border-radius: 12px; padding: 20px; text-align: center; background: #f8fafc; cursor: pointer; transition: all 0.3s; margin-top: 5px; }
    .file-upload-container:hover { border-color: var(--blue); background: #f0f4ff; }
    .file-upload-icon { font-size: 40px; color: #a0aec0; margin-bottom: 10px; }
    .file-upload-text { font-size: 14px; color: #718096; margin-bottom: 5px; }
    .file-upload-subtext { font-size: 12px; color: #a0aec0; }

    .preview-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 10px; border:1px solid var(--border);
      border-radius:12px; background:#fff; font-weight:800; color:#374151;
      margin-top:10px;
    }

    @media (max-width: 768px) {
      .content-scroll { padding: 18px; }
      .form-panel { padding: 20px; }
      .section-header { flex-direction: column; text-align: center; }
      .section-icon { margin-right: 0; margin-bottom: 15px; }
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
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Edit Employee</h1>
            <p class="text-muted mb-0">AS Electricals • Update employee details</p>
          </div>
          <div class="d-flex gap-2">
            <a href="manage-employees.php" class="btn-back">
              <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="view-employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn-back">
              <i class="bi bi-eye"></i> View
            </a>
          </div>
        </div>

        <!-- Alerts -->
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

          <!-- Section 1: Basic -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon"><i class="bi bi-person"></i></div>
              <div>
                <h3 class="section-title">Basic Information</h3>
                <p class="section-subtitle">Personal details and contact</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <label class="form-label required-label" for="full_name">Full Name</label>
                <input type="text" class="form-control" id="full_name" name="full_name"
                       value="<?php echo e($emp['full_name'] ?? ''); ?>" required>
              </div>

              <div class="col-md-6">
                <label class="form-label required-label" for="employee_code">Employee Code</label>
                <input type="text" class="form-control" id="employee_code" name="employee_code"
                       value="<?php echo e($emp['employee_code'] ?? ''); ?>" required>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="date_of_birth">Date of Birth <span class="optional-badge">(Optional)</span></label>
                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                       value="<?php echo e(($emp['date_of_birth'] ?? '') !== '0000-00-00' ? ($emp['date_of_birth'] ?? '') : ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="gender">Gender <span class="optional-badge">(Optional)</span></label>
                <select class="form-select" id="gender" name="gender">
                  <option value="">Select</option>
                  <?php foreach ($genders as $g): ?>
                    <option value="<?php echo e($g); ?>" <?php echo (($emp['gender'] ?? '') === $g) ? 'selected' : ''; ?>>
                      <?php echo e($g); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="blood_group">Blood Group <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="blood_group" name="blood_group"
                       value="<?php echo e($emp['blood_group'] ?? ''); ?>" placeholder="A+, O-, etc">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="mobile_number">Mobile Number <span class="optional-badge">(Optional)</span></label>
                <input type="tel" class="form-control" id="mobile_number" name="mobile_number"
                       value="<?php echo e($emp['mobile_number'] ?? ''); ?>" placeholder="10-15 digits">
                <div class="form-helper"><i class="bi bi-phone"></i> Digits only</div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="email">Email <span class="optional-badge">(Optional)</span></label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?php echo e($emp['email'] ?? ''); ?>" placeholder="name@domain.com">
              </div>

              <div class="col-12">
                <label class="form-label" for="current_address">Current Address <span class="optional-badge">(Optional)</span></label>
                <textarea class="form-control" id="current_address" name="current_address" rows="2"><?php echo e($emp['current_address'] ?? ''); ?></textarea>
              </div>

              <!-- Photo upload -->
              <div class="col-md-6">
                <label class="form-label" for="photo">Employee Photo <span class="optional-badge">(Optional)</span></label>
                <div class="file-upload-container" onclick="document.getElementById('photo').click()">
                  <div class="file-upload-icon"><i class="bi bi-upload"></i></div>
                  <div class="file-upload-text">Click to upload employee photo</div>
                  <div class="file-upload-subtext">JPG, PNG, WebP (Max 10MB)</div>
                  <input type="file" class="d-none" id="photo" name="photo" accept="image/*">
                </div>
                <?php if (!empty($emp['photo'])): ?>
                  <div class="preview-pill">
                    <i class="bi bi-image"></i>
                    <a href="<?php echo e($emp['photo']); ?>" target="_blank" rel="noopener" style="text-decoration:none;">
                      View current photo
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Section 2: Emergency -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon" style="background:#30cfd0;"><i class="bi bi-life-preserver"></i></div>
              <div>
                <h3 class="section-title">Emergency Contact</h3>
                <p class="section-subtitle">In case of emergency</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <label class="form-label" for="emergency_contact_name">Emergency Contact Name <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name"
                       value="<?php echo e($emp['emergency_contact_name'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="emergency_contact_phone">Emergency Contact Phone <span class="optional-badge">(Optional)</span></label>
                <input type="tel" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone"
                       value="<?php echo e($emp['emergency_contact_phone'] ?? ''); ?>" placeholder="10-15 digits">
              </div>
            </div>
          </div>

          <!-- Section 3: Employment -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon" style="background:#4facfe;"><i class="bi bi-briefcase"></i></div>
              <div>
                <h3 class="section-title">Employment Details</h3>
                <p class="section-subtitle">Role, reporting and location</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <label class="form-label" for="date_of_joining">Date of Joining <span class="optional-badge">(Optional)</span></label>
                <input type="date" class="form-control" id="date_of_joining" name="date_of_joining"
                       value="<?php echo e(($emp['date_of_joining'] ?? '') !== '0000-00-00' ? ($emp['date_of_joining'] ?? '') : ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="department">Department <span class="optional-badge">(Optional)</span></label>
                <select class="form-select" id="department" name="department">
                  <option value="">Select</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?php echo e($d); ?>" <?php echo (($emp['department'] ?? '') === $d) ? 'selected' : ''; ?>>
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
                    <option value="<?php echo e($dsg); ?>" <?php echo (($emp['designation'] ?? '') === $dsg) ? 'selected' : ''; ?>>
                      <?php echo e($dsg); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="reporting_manager">Reporting Manager <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="reporting_manager" name="reporting_manager"
                       value="<?php echo e($emp['reporting_manager'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="work_location">Work Location <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="work_location" name="work_location"
                       value="<?php echo e($emp['work_location'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="site_name">Site Name <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="site_name" name="site_name"
                       value="<?php echo e($emp['site_name'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="employee_status">Employee Status <span class="optional-badge">(Optional)</span></label>
                <select class="form-select" id="employee_status" name="employee_status">
                  <option value="">Select</option>
                  <?php foreach ($statuses as $st): ?>
                    <option value="<?php echo e($st); ?>" <?php echo (($emp['employee_status'] ?? '') === $st) ? 'selected' : ''; ?>>
                      <?php echo e(ucfirst($st)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <!-- Section 4: Login -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon" style="background:#fa709a;"><i class="bi bi-shield-lock"></i></div>
              <div>
                <h3 class="section-title">Login Details</h3>
                <p class="section-subtitle">Username and password</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <label class="form-label required-label" for="username">Username</label>
                <input type="text" class="form-control" id="username" name="username"
                       value="<?php echo e($emp['username'] ?? ''); ?>" required>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="password">New Password <span class="optional-badge">(Leave blank to keep current)</span></label>
                <div class="input-group">
                  <input type="password" class="form-control" id="password" name="password"
                         placeholder="Min 8 characters (optional)">
                  <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                    <i class="bi bi-eye"></i>
                  </button>
                  <button class="btn btn-outline-secondary" type="button" id="generatePasswordBtn">
                    <i class="bi bi-shuffle"></i>
                  </button>
                </div>
                <div class="form-helper"><i class="bi bi-info-circle"></i> Only update if you enter a new password</div>
              </div>
            </div>
          </div>

          <!-- Section 5: KYC + Bank -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon" style="background:#43e97b;"><i class="bi bi-credit-card-2-front"></i></div>
              <div>
                <h3 class="section-title">KYC & Bank</h3>
                <p class="section-subtitle">Identity and payment details</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <label class="form-label" for="aadhar_card_number">Aadhaar Number <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="aadhar_card_number" name="aadhar_card_number"
                       value="<?php echo e($emp['aadhar_card_number'] ?? ''); ?>" placeholder="Digits only">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="pancard_number">PAN Card Number <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="pancard_number" name="pancard_number"
                       value="<?php echo e($emp['pancard_number'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="bank_account_number">Bank Account Number <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="bank_account_number" name="bank_account_number"
                       value="<?php echo e($emp['bank_account_number'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label" for="ifsc_code">IFSC Code <span class="optional-badge">(Optional)</span></label>
                <input type="text" class="form-control" id="ifsc_code" name="ifsc_code"
                       value="<?php echo e($emp['ifsc_code'] ?? ''); ?>">
              </div>

              <!-- passbook upload -->
              <div class="col-md-6">
                <label class="form-label" for="passbook_photo">Passbook Photo <span class="optional-badge">(Optional)</span></label>
                <div class="file-upload-container" onclick="document.getElementById('passbook_photo').click()">
                  <div class="file-upload-icon"><i class="bi bi-upload"></i></div>
                  <div class="file-upload-text">Click to upload passbook photo</div>
                  <div class="file-upload-subtext">JPG, PNG, WebP, PDF (Max 10MB)</div>
                  <input type="file" class="d-none" id="passbook_photo" name="passbook_photo" accept=".pdf,image/*">
                </div>
                <?php if (!empty($emp['passbook_photo'])): ?>
                  <div class="preview-pill">
                    <i class="bi bi-file-earmark-arrow-down"></i>
                    <a href="<?php echo e($emp['passbook_photo']); ?>" target="_blank" rel="noopener" style="text-decoration:none;">
                      View current passbook file
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Submit -->
          <div class="text-center mt-5">
            <button type="submit" class="btn-submit">
              <i class="bi bi-save"></i> Update Employee
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
  const setInvalid = (el) => el && el.classList.add('is-invalid');

  // Password toggle + generator
  const passwordInput = document.getElementById('password');
  const togglePassword = document.getElementById('togglePassword');
  const generatePasswordBtn = document.getElementById('generatePasswordBtn');

  if (togglePassword && passwordInput) {
    togglePassword.addEventListener('click', function() {
      const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
      passwordInput.setAttribute('type', type);
      this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });
  }

  if (generatePasswordBtn && passwordInput) {
    generatePasswordBtn.addEventListener('click', function() {
      const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
      let pwd = '';
      for (let i = 0; i < 12; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
      passwordInput.value = pwd;
      passwordInput.setAttribute('type', 'text');
      if (togglePassword) togglePassword.innerHTML = '<i class="bi bi-eye-slash"></i>';
    });
  }

  // Digits-only fields
  const mob = document.getElementById('mobile_number');
  const emg = document.getElementById('emergency_contact_phone');
  const aad = document.getElementById('aadhar_card_number');

  if (mob) mob.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,15); });
  if (emg) emg.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,15); });
  if (aad) aad.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,20); });

  // Submit validation (simple)
  form.addEventListener('submit', function(e) {
    let valid = true;
    const errors = [];

    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

    // required fields
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
      if (!String(field.value || '').trim()) {
        valid = false;
        setInvalid(field);
        const label = form.querySelector(`label[for="${field.id}"]`);
        const name = label ? label.textContent.replace(' *','').trim() : field.name;
        errors.push(name + " is required");
      }
    });

    // if password entered, enforce min length
    if (passwordInput && passwordInput.value && passwordInput.value.length < 8) {
      valid = false;
      setInvalid(passwordInput);
      errors.push("Password must be at least 8 characters");
    }

    // email format
    const email = document.getElementById('email');
    if (email && email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
      valid = false;
      setInvalid(email);
      errors.push("Invalid email format");
    }

    if (!valid) {
      e.preventDefault();

      const alertDiv = document.createElement('div');
      alertDiv.className = 'alert alert-danger alert-dismissible fade show';
      alertDiv.innerHTML = `
        <div class="d-flex align-items-center">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          <div>
            <strong>Form Validation Error</strong>
            <ul class="mb-0 mt-2 ps-3">${errors.map(x => `<li>${x}</li>`).join('')}</ul>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      `;

      const container = document.querySelector('.container-fluid.maxw');
      const headerBlock = container.children[0];
      container.insertBefore(alertDiv, headerBlock.nextSibling);

      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  });
});
</script>

</body>
</html>

<?php
if (isset($conn)) { mysqli_close($conn); }
?>
