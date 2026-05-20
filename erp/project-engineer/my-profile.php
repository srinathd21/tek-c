<?php
// admin/my-profile.php
session_start();
require_once __DIR__ . '/includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---- auth ----
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}
$employeeId = (int)$_SESSION['employee_id'];

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
  $columnEsc = mysqli_real_escape_string($conn, $column);
  $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
  if (!$res) return false;
  $ok = mysqli_num_rows($res) > 0;
  mysqli_free_result($res);
  return $ok;
}

function logProfileActivity($conn, int $employeeId, string $activityType, string $description, $referenceId = null): bool {
  if (!$conn || !tableExists($conn, 'activity_logs')) return false;

  $employeeName = $_SESSION['employee_name'] ?? $_SESSION['name'] ?? 'System';
  $username = $_SESSION['username'] ?? '';
  $designation = $_SESSION['designation'] ?? '';
  $department = $_SESSION['department'] ?? '';
  $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

  $map = [
    'employee_id'   => ['i', $employeeId],
    'employee_name' => ['s', $employeeName],
    'username'      => ['s', $username],
    'designation'   => ['s', $designation],
    'department'    => ['s', $department],
    'activity_type' => ['s', $activityType],
    'module'        => ['s', 'my_profile'],
    'description'   => ['s', $description],
    'reference_id'  => ['i', $referenceId],
    'ip_address'    => ['s', $ipAddress],
  ];

  $columns = [];
  $types = '';
  $values = [];

  foreach ($map as $column => $pair) {
    if (columnExists($conn, 'activity_logs', $column)) {
      $columns[] = "`$column`";
      $types .= $pair[0];
      $values[] = $pair[1];
    }
  }

  if (!$columns) return false;

  $placeholders = implode(',', array_fill(0, count($columns), '?'));
  $sql = "INSERT INTO activity_logs (" . implode(',', $columns) . ") VALUES ($placeholders)";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return false;

  mysqli_stmt_bind_param($st, $types, ...$values);
  $ok = mysqli_stmt_execute($st);
  mysqli_stmt_close($st);

  return $ok;
}


$success = '';
$error   = '';

// ---- fetch profile ----
function fetchEmployee(mysqli $conn, int $employeeId): ?array {
  $sql = "SELECT
            id, full_name, employee_code, photo, date_of_birth, gender, blood_group,
            mobile_number, email, current_address,
            emergency_contact_name, emergency_contact_phone,
            date_of_joining, department, designation, work_location, site_name, employee_status, username
          FROM employees
          WHERE id = ?
          LIMIT 1";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return null;
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res) ?: null;
  mysqli_stmt_close($st);
  return $row;
}

/**
 * DB PATH format (recommended):
 *   uploads/employees/photos/filename.jpg
 *
 * Browser URL for this page:
 *   ../admin/uploads/employees/photos/filename.jpg
 */
function photoUrlFromDb(?string $dbPath): string {
  $dbPath = trim((string)$dbPath);
  if ($dbPath === '') return '';

  $dbPath = str_replace('\\', '/', $dbPath);
  $dbPath = ltrim($dbPath, '/');

  // Normalize legacy values
  if (strpos($dbPath, '../admin/') === 0) {
    $dbPath = substr($dbPath, strlen('../admin/'));
  } elseif (strpos($dbPath, 'admin/') === 0) {
    $dbPath = substr($dbPath, strlen('admin/'));
  }

  // Must end up as uploads/...
  if (strpos($dbPath, 'uploads/') !== 0) {
    return '';
  }

  return '../admin/' . $dbPath;
}

/**
 * Physical file path check (server-side)
 * Since this file is admin/my-profile.php and image is in admin/uploads/...
 */
function photoAbsPathFromDb(?string $dbPath): string {
  $dbPath = trim((string)$dbPath);
  if ($dbPath === '') return '';

  $dbPath = str_replace('\\', '/', $dbPath);
  $dbPath = ltrim($dbPath, '/');

  if (strpos($dbPath, '../admin/') === 0) {
    $dbPath = substr($dbPath, strlen('../admin/'));
  } elseif (strpos($dbPath, 'admin/') === 0) {
    $dbPath = substr($dbPath, strlen('admin/'));
  }

  if (strpos($dbPath, 'uploads/') !== 0) return '';

  return __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $dbPath);
}

$emp = fetchEmployee($conn, $employeeId);
if (!$emp) {
  mysqli_close($conn);
  die("Employee not found.");
}

// ---- handle profile update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
  $full_name = trim($_POST['full_name'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $mobile    = trim($_POST['mobile_number'] ?? '');
  $dob       = trim($_POST['date_of_birth'] ?? '');
  $gender    = trim($_POST['gender'] ?? '');
  $blood     = trim($_POST['blood_group'] ?? '');
  $address   = trim($_POST['current_address'] ?? '');
  $emgName   = trim($_POST['emergency_contact_name'] ?? '');
  $emgPhone  = trim($_POST['emergency_contact_phone'] ?? '');

  if ($full_name === '') {
    $error = "Full name is required.";
  } else {
    $dobVal    = ($dob === '' || $dob === '0000-00-00') ? null : $dob;
    $genderVal = ($gender === '') ? null : $gender;
    $bloodVal  = ($blood === '') ? null : $blood;

    $sql = "UPDATE employees
            SET full_name = ?,
                email = ?,
                mobile_number = ?,
                date_of_birth = ?,
                gender = ?,
                blood_group = ?,
                current_address = ?,
                emergency_contact_name = ?,
                emergency_contact_phone = ?
            WHERE id = ?";

    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $st,
        "sssssssssi",
        $full_name,
        $email,
        $mobile,
        $dobVal,
        $genderVal,
        $bloodVal,
        $address,
        $emgName,
        $emgPhone,
        $employeeId
      );

      if (mysqli_stmt_execute($st)) {
        $success = "Profile updated successfully.";

        // update session for topbar
        $_SESSION['employee_name']  = $full_name;
        $_SESSION['employee_email'] = $email;

        logProfileActivity($conn, $employeeId, 'UPDATE', 'Updated profile details', $employeeId);

        $emp = fetchEmployee($conn, $employeeId);
      } else {
        $error = "Failed to update profile.";
      }
      mysqli_stmt_close($st);
    }
  }
}

// ---- handle photo upload ----
// Physical store: admin/uploads/employees/photos/
// DB store: uploads/employees/photos/filename.ext
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_photo') {

  if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $error = "Please select a valid photo.";
  } else {
    $fileTmp  = $_FILES['photo']['tmp_name'];
    $fileSize = (int)($_FILES['photo']['size'] ?? 0);

    // Validate type
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = @mime_content_type($fileTmp);

    if (!isset($allowed[$mime])) {
      $error = "Only JPG, PNG, WEBP images are allowed.";
    } elseif ($fileSize > 2 * 1024 * 1024) {
      $error = "Photo size must be <= 2MB.";
    } else {
      $ext = $allowed[$mime];

      // This file is inside admin/, so store in admin/uploads/...
      $uploadDir = __DIR__ . '/uploads/employees/photos/';
      if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
          $error = "Failed to create photo upload directory.";
        }
      }

      if (!$error) {
        $newFile  = 'photo_' . $employeeId . '_' . uniqid('', true) . '.' . $ext;
        $destPath = $uploadDir . $newFile;

        if (!move_uploaded_file($fileTmp, $destPath)) {
          $error = "Failed to upload photo.";
        } else {
          // Delete old photo if exists
          $oldAbs = photoAbsPathFromDb($emp['photo'] ?? '');
          if ($oldAbs !== '' && is_file($oldAbs)) {
            @unlink($oldAbs);
          }

          // ✅ Store DB path only (as requested)
          $dbPath = 'uploads/employees/photos/' . $newFile;

          $st = mysqli_prepare($conn, "UPDATE employees SET photo = ? WHERE id = ?");
          if (!$st) {
            $error = "DB Error: " . mysqli_error($conn);
          } else {
            mysqli_stmt_bind_param($st, "si", $dbPath, $employeeId);
            if (mysqli_stmt_execute($st)) {
              $success = "Profile photo updated.";

              // Session also store DB path
              $_SESSION['employee_photo'] = $dbPath;

              logProfileActivity($conn, $employeeId, 'UPDATE', 'Updated profile photo', $employeeId);

              $emp = fetchEmployee($conn, $employeeId);
            } else {
              $error = "Failed to update photo in database.";
            }
            mysqli_stmt_close($st);
          }
        }
      }
    }
  }
}

// ---- handle password change ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
  $current = (string)($_POST['current_password'] ?? '');
  $new1    = (string)($_POST['new_password'] ?? '');
  $new2    = (string)($_POST['confirm_password'] ?? '');

  if ($current === '' || $new1 === '' || $new2 === '') {
    $error = "Please fill all password fields.";
  } elseif ($new1 !== $new2) {
    $error = "New password and confirm password do not match.";
  } elseif (strlen($new1) < 6) {
    $error = "New password must be at least 6 characters.";
  } else {
    $st = mysqli_prepare($conn, "SELECT password FROM employees WHERE id = ? LIMIT 1");
    if (!$st) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param($st, "i", $employeeId);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $row = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);

      $hash = $row['password'] ?? '';
      if (!$hash || !password_verify($current, $hash)) {
        $error = "Current password is incorrect.";
      } else {
        $newHash = password_hash($new1, PASSWORD_BCRYPT);

        $st2 = mysqli_prepare($conn, "UPDATE employees SET password = ? WHERE id = ?");
        if (!$st2) {
          $error = "DB Error: " . mysqli_error($conn);
        } else {
          mysqli_stmt_bind_param($st2, "si", $newHash, $employeeId);
          if (mysqli_stmt_execute($st2)) {
            $success = "Password changed successfully.";
            logProfileActivity($conn, $employeeId, 'UPDATE', 'Changed account password', $employeeId);
          } else {
            $error = "Failed to change password.";
          }
          mysqli_stmt_close($st2);
        }
      }
    }
  }
}

// ---- Build image URL for display ----
$photoSrc = '';
$photoAbs = photoAbsPathFromDb($emp['photo'] ?? '');
if ($photoAbs !== '' && is_file($photoAbs)) {
  $photoSrc = photoUrlFromDb($emp['photo'] ?? '');
  if ($photoSrc !== '') {
    $photoSrc .= '?v=' . urlencode((string)@filemtime($photoAbs)); // cache bust
  }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>My Profile - TEK-C</title>

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
      --red:#eb5757;
    }

    body{ background:var(--page-bg); }

    .content-scroll{
      flex:1 1 auto;
      overflow:auto;
      padding:16px;
    }

    .projects-wrapper{ width:100%; }

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

    .primary-btn,.secondary-btn,.success-btn,.dark-btn{
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
    }

    .primary-btn,.dark-btn{
      background:#111827;
      color:#fff;
    }

    .primary-btn:hover,.dark-btn:hover{
      background:#020617;
      color:#fff;
    }

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

    .success-btn{
      background:#16a34a;
      color:#fff;
    }

    .success-btn:hover{
      background:#15803d;
      color:#fff;
    }

    .panel{
      background:var(--card-bg);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:13px;
      margin-bottom:14px;
      height:auto;
    }

    .panel-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:12px;
    }

    .panel-title{
      font-weight:900;
      font-size:14px;
      margin:0;
      color:var(--text);
    }

    .panel-subtitle{
      color:var(--muted);
      font-size:11px;
      font-weight:700;
      margin-top:2px;
    }

    .profile-layout-row{
      align-items:flex-start;
    }

    .profile-layout-row > [class*="col-"]{
      align-self:flex-start;
    }

    .profile-card{
      display:flex;
      align-items:center;
      gap:12px;
      padding:12px;
      border:1px solid #eef2f7;
      border-radius:14px;
      background:#f8fafc;
      margin-bottom:13px;
    }

    .avatar-lg{
      width:82px;
      height:82px;
      border-radius:22px;
      overflow:hidden;
      border:1px solid var(--border);
      background:#111827;
      color:#fff;
      display:flex;
      align-items:center;
      justify-content:center;
      box-shadow:var(--shadow);
      font-size:24px;
      font-weight:950;
      flex:0 0 auto;
    }

    .avatar-lg img{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
    }

    .profile-main-name{
      font-size:17px;
      font-weight:950;
      color:#111827;
      line-height:1.2;
    }

    .muted{
      color:#64748b;
      font-weight:700;
      font-size:12px;
    }

    .chip{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:5px 8px;
      border-radius:999px;
      border:1px solid #e2e8f0;
      background:#fff;
      font-weight:900;
      color:#475569;
      font-size:10.5px;
    }

    .info-list{
      border:1px solid #eef2f7;
      background:#fff;
      border-radius:13px;
      padding:10px;
      display:grid;
      gap:8px;
    }

    .info-row{
      display:flex;
      justify-content:space-between;
      gap:10px;
      border-bottom:1px dashed #eef2f7;
      padding-bottom:7px;
    }

    .info-row:last-child{
      border-bottom:0;
      padding-bottom:0;
    }

    .info-key{
      color:#64748b;
      font-size:10.5px;
      font-weight:900;
      text-transform:uppercase;
    }

    .info-val{
      color:#111827;
      font-size:11.5px;
      font-weight:850;
      text-align:right;
      word-break:break-word;
    }

    .form-label{
      font-size:11px;
      font-weight:900;
      color:#475569;
      text-transform:uppercase;
      margin-bottom:6px;
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

    .file-note{
      color:#64748b;
      font-size:10.5px;
      font-weight:750;
      margin-top:7px;
    }

    .alert{
      border-radius:14px;
      border:1px solid transparent;
      box-shadow:var(--shadow);
      font-size:12px;
      font-weight:850;
    }

    .alert-success{
      background:#dcfce7;
      border-color:#bbf7d0;
      color:#166534;
    }

    .alert-danger{
      background:#fee2e2;
      border-color:#fecaca;
      color:#991b1b;
    }

    .password-panel{
      min-height:auto;
    }

    .section-divider{
      margin:13px 0;
      border-color:#eef2f7;
      opacity:1;
    }

    @media(max-width:991.98px){
      .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
      .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
      .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0)!important; }
    }

    @media(max-width:768px){
      .content-scroll{ padding:12px 10px!important; }
      .container-fluid.projects-wrapper{ padding-left:0!important; padding-right:0!important; }
      .page-heading{ align-items:flex-start; flex-direction:column; }
      .panel{ padding:12px; }
      .profile-card{ align-items:flex-start; flex-direction:column; }
      .primary-btn,.secondary-btn,.success-btn,.dark-btn{ width:100%; }
      .info-row{ flex-direction:column; gap:3px; }
      .info-val{ text-align:left; }
    }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div class="content-scroll">
      <div class="container-fluid projects-wrapper px-0">

        <div class="page-heading">
          <div>
            <h1>My Profile</h1>
            <p>Update your details, photo, and password</p>
          </div>

          <a href="download-my-profile-pdf.php" class="secondary-btn">
            <i class="bi bi-download"></i> Download PDF
          </a>
        </div>

         
        </div>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <div class="row g-3 profile-layout-row">
          <!-- Left -->
          <div class="col-12 col-lg-4">
            <div class="panel">
              <div class="profile-card">
                <div class="avatar-lg">
                  <?php if ($photoSrc !== ''): ?>
                    <img src="<?php echo e($photoSrc); ?>" alt="<?php echo e($emp['full_name']); ?>"
                         onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=&quot;fw-bold&quot;><?php echo e(strtoupper(substr($emp['full_name'],0,1))); ?></span>';"/>
                  <?php else: ?>
                    <span class="fw-bold"><?php echo e(strtoupper(substr($emp['full_name'], 0, 1))); ?></span>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="profile-main-name"><?php echo e($emp['full_name'] ?? ''); ?></div>
                  <div class="muted"><?php echo e(($emp['email'] ?? '') ?: 'No email set'); ?></div>
                  <div class="mt-2 d-flex flex-wrap gap-2">
                    <span class="chip"><i class="bi bi-person-badge"></i><?php echo e($emp['designation'] ?? ''); ?></span>
                    <span class="chip"><i class="bi bi-hash"></i><?php echo e($emp['employee_code'] ?? ''); ?></span>
                  </div>
                </div>
              </div>

              <hr class="section-divider">

              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_photo">
                <label class="form-label">Update Photo</label>
                <input class="form-control mb-2" type="file" name="photo" accept="image/png,image/jpeg,image/webp" required>
                <button class="primary-btn w-100" type="submit">
                  <i class="bi bi-upload me-1"></i>Upload Photo
                </button>
                <div class="file-note">Max 2MB • JPG/PNG/WEBP</div>
              </form>

              <hr class="section-divider">

              <div class="info-list">
                <div class="info-row">
                  <div class="info-key">Username</div>
                  <div class="info-val"><?php echo e($emp['username'] ?? ''); ?></div>
                </div>
                <div class="info-row">
                  <div class="info-key">Mobile</div>
                  <div class="info-val"><?php echo e($emp['mobile_number'] ?? ''); ?></div>
                </div>
                <div class="info-row">
                  <div class="info-key">Work Location</div>
                  <div class="info-val"><?php echo e($emp['work_location'] ?? ''); ?></div>
                </div>
                <div class="info-row">
                  <div class="info-key">Site</div>
                  <div class="info-val"><?php echo e($emp['site_name'] ?? ''); ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Right -->
          <div class="col-12 col-lg-8">
            <!-- Profile update -->
            <div class="panel mb-3">
              <div class="panel-header">
                <div>
                  <h3 class="panel-title">Profile Details</h3>
                  <div class="panel-subtitle">Personal and emergency contact information</div>
                </div>
              </div>

              <form method="post">
                <input type="hidden" name="action" value="update_profile">

                <div class="row g-3">
                  <div class="col-12 col-md-6">
                    <label class="form-label">Full Name</label>
                    <input type="text" class="form-control" name="full_name"
                           value="<?php echo e($emp['full_name'] ?? ''); ?>" required>
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email"
                           value="<?php echo e($emp['email'] ?? ''); ?>" placeholder="name@example.com">
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label">Mobile Number</label>
                    <input type="text" class="form-control" name="mobile_number"
                           value="<?php echo e($emp['mobile_number'] ?? ''); ?>" maxlength="15">
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" class="form-control" name="date_of_birth"
                           value="<?php echo e(($emp['date_of_birth'] ?? '') !== '0000-00-00' ? ($emp['date_of_birth'] ?? '') : ''); ?>">
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="gender">
                      <?php $g = (string)($emp['gender'] ?? ''); ?>
                      <option value="" <?php echo $g===''?'selected':''; ?>>Select</option>
                      <option value="Male" <?php echo $g==='Male'?'selected':''; ?>>Male</option>
                      <option value="Female" <?php echo $g==='Female'?'selected':''; ?>>Female</option>
                      <option value="Other" <?php echo $g==='Other'?'selected':''; ?>>Other</option>
                    </select>
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label">Blood Group</label>
                    <input type="text" class="form-control" name="blood_group"
                           value="<?php echo e($emp['blood_group'] ?? ''); ?>" placeholder="A+, O+, ...">
                  </div>

                  <div class="col-12">
                    <label class="form-label">Current Address</label>
                    <textarea class="form-control" name="current_address" rows="3"
                              placeholder="Enter address"><?php echo e($emp['current_address'] ?? ''); ?></textarea>
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" class="form-control" name="emergency_contact_name"
                           value="<?php echo e($emp['emergency_contact_name'] ?? ''); ?>">
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label">Emergency Contact Phone</label>
                    <input type="text" class="form-control" name="emergency_contact_phone"
                           value="<?php echo e($emp['emergency_contact_phone'] ?? ''); ?>" maxlength="15">
                  </div>
                </div>

                <div class="mt-3 d-flex justify-content-end gap-2 flex-wrap">
                  <button class="primary-btn" type="submit">
                    <i class="bi bi-save me-1"></i>Save Changes
                  </button>
                </div>
              </form>
            </div>

            <!-- Password change -->
            <div class="panel password-panel">
              <div class="panel-header">
                <div>
                  <h3 class="panel-title">Change Password</h3>
                  <div class="panel-subtitle">Use at least 6 characters</div>
                </div>
              </div>
              <form method="post">
                <input type="hidden" name="action" value="change_password">

                <div class="row g-3">
                  <div class="col-12 col-md-4">
                    <label class="form-label">Current Password</label>
                    <input type="password" class="form-control" name="current_password" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">New Password</label>
                    <input type="password" class="form-control" name="new_password" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" name="confirm_password" required>
                  </div>
                </div>

                <div class="mt-3 d-flex justify-content-end gap-2 flex-wrap">
                  <button class="dark-btn" type="submit">
                    <i class="bi bi-shield-lock me-1"></i>Update Password
                  </button>
                </div>

                
              </form>
            </div>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const photoInput = document.querySelector('input[name="photo"]');
    const avatar = document.querySelector('.avatar-lg');

    if (photoInput && avatar) {
      photoInput.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (!file || !file.type.startsWith('image/')) return;

        const reader = new FileReader();
        reader.onload = function (e) {
          avatar.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
        };
        reader.readAsDataURL(file);
      });
    }
  });
</script>

</body>
</html>