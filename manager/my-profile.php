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

$emp = fetchEmployee($conn, $employeeId);
if (!$emp) {
  mysqli_close($conn);
  die("Employee not found.");
}

/**
 * ✅ Convert DB photo path into a WORKING browser URL
 * We will store DB path as: admin/uploads/employees/photos/xxx.png
 * But some old rows may store: uploads/employees/photos/xxx.png
 *
 * Return: "/admin/uploads/...."
 */
function photoUrlFromDb(?string $dbPath): string {
  $dbPath = trim((string)$dbPath);
  if ($dbPath === '') return '';

  $dbPath = ltrim($dbPath, '/'); // normalize

  // If old value doesn't include "admin/", prefix it
  if (strpos($dbPath, 'admin/') !== 0) {
    $dbPath = 'admin/' . $dbPath;
  }

  // Always use absolute URL so it works on any page depth
  return '/' . $dbPath;
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

        $emp = fetchEmployee($conn, $employeeId);
      } else {
        $error = "Failed to update profile.";
      }
      mysqli_stmt_close($st);
    }
  }
}

// ---- handle photo upload (✅ store DB path with admin/) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_photo') {

  if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $error = "Please select a valid photo.";
  } else {
    $fileTmp  = $_FILES['photo']['tmp_name'];
    $fileSize = (int)($_FILES['photo']['size'] ?? 0);

    // Validate type
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($fileTmp);

    if (!isset($allowed[$mime])) {
      $error = "Only JPG, PNG, WEBP images are allowed.";
    } elseif ($fileSize > 2 * 1024 * 1024) {
      $error = "Photo size must be <= 2MB.";
    } else {
      $ext = $allowed[$mime];

      // ✅ Physical folder inside admin/
      $uploadDir = __DIR__ . '/uploads/employees/photos/';
      if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
      }

      $newFile  = 'photo_' . uniqid() . '_' . time() . '.' . $ext;
      $destPath = $uploadDir . $newFile;

      if (!move_uploaded_file($fileTmp, $destPath)) {
        $error = "Failed to upload photo.";
      } else {

        // ✅ DB PATH includes admin/
        // Stored in DB: admin/uploads/employees/photos/xxx.png
        $dbPath = 'admin/uploads/employees/photos/' . $newFile;

        $st = mysqli_prepare($conn, "UPDATE employees SET photo = ? WHERE id = ?");
        if (!$st) {
          $error = "DB Error: " . mysqli_error($conn);
        } else {
          mysqli_stmt_bind_param($st, "si", $dbPath, $employeeId);
          if (mysqli_stmt_execute($st)) {
            $success = "Profile photo updated.";

            // Update session for topbar
            $_SESSION['employee_photo'] = $dbPath;

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
$photoSrc = photoUrlFromDb($emp['photo'] ?? '');

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
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px; }
    .panel-title{ font-weight:900; font-size:18px; margin:0; color:#111827; }
    .muted{ color:#6b7280; font-weight:650; }
    .avatar-lg{
      width:90px;height:90px;border-radius:22px; overflow:hidden;
      border:1px solid var(--border); background:#fff;
      display:flex; align-items:center; justify-content:center;
      box-shadow: var(--shadow);
    }
    .avatar-lg img{ width:100%;height:100%;object-fit:cover; }
    .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px; border:1px solid var(--border);
      background:#fff; font-weight:750; color:#374151; font-size:12px;
    }
    .form-control, .form-select{
      border-radius: 12px;
      border:1px solid var(--border);
      padding: 10px 12px;
      font-weight: 650;
    }
    .btn-tek{
      border-radius: 12px; font-weight: 800;
      padding: 10px 14px;
    }
    .alert{ border-radius: var(--radius); border:none; box-shadow: var(--shadow); }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div class="content-scroll">
      <div class="container-fluid maxw">

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">My Profile</h1>
            <div class="muted">Update your details, photo, and password</div>
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

        <div class="row g-3">
          <!-- Left -->
          <div class="col-12 col-lg-4">
            <div class="panel">
              <div class="d-flex align-items-center gap-3 mb-3">
                <div class="avatar-lg">
                  <?php if ($photoSrc): ?>
                    <img src="<?php echo e($photoSrc); ?>" alt="<?php echo e($emp['full_name']); ?>"
                         onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=&quot;fw-bold&quot;><?php echo e(strtoupper(substr($emp['full_name'],0,1))); ?></span>';"/>
                  <?php else: ?>
                    <span class="fw-bold"><?php echo e(strtoupper(substr($emp['full_name'], 0, 1))); ?></span>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="fw-bold fs-5"><?php echo e($emp['full_name'] ?? ''); ?></div>
                  <div class="muted"><?php echo e(($emp['email'] ?? '') ?: 'No email set'); ?></div>
                  <div class="mt-2 d-flex flex-wrap gap-2">
                    <span class="chip"><i class="bi bi-person-badge"></i><?php echo e($emp['designation'] ?? ''); ?></span>
                    <span class="chip"><i class="bi bi-hash"></i><?php echo e($emp['employee_code'] ?? ''); ?></span>
                  </div>
                </div>
              </div>

              <hr class="my-3">

              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_photo">
                <label class="form-label fw-bold">Update Photo</label>
                <input class="form-control mb-2" type="file" name="photo" accept="image/png,image/jpeg,image/webp" required>
                <button class="btn btn-primary btn-tek w-100" type="submit">
                  <i class="bi bi-upload me-1"></i>Upload Photo
                </button>
                <div class="muted mt-2" style="font-size:12px;">
                  Max 2MB • JPG/PNG/WEBP
                </div>
              </form>

              <hr class="my-3">

              <div class="muted" style="font-size:13px;">
                <div class="mb-1"><b>Username:</b> <?php echo e($emp['username'] ?? ''); ?></div>
                <div class="mb-1"><b>Mobile:</b> <?php echo e($emp['mobile_number'] ?? ''); ?></div>
                <div class="mb-1"><b>Work Location:</b> <?php echo e($emp['work_location'] ?? ''); ?></div>
                <div class="mb-1"><b>Site:</b> <?php echo e($emp['site_name'] ?? ''); ?></div>
              </div>
            </div>
          </div>

          <!-- Right -->
          <div class="col-12 col-lg-8">
            <!-- Profile update -->
            <div class="panel mb-3">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <h3 class="panel-title">Profile Details</h3>
              </div>

              <form method="post">
                <input type="hidden" name="action" value="update_profile">

                <div class="row g-3">
                  <div class="col-12 col-md-6">
                    <label class="form-label fw-bold">Full Name</label>
                    <input type="text" class="form-control" name="full_name"
                           value="<?php echo e($emp['full_name'] ?? ''); ?>" required>
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label fw-bold">Email</label>
                    <input type="email" class="form-control" name="email"
                           value="<?php echo e($emp['email'] ?? ''); ?>" placeholder="name@example.com">
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label fw-bold">Mobile Number</label>
                    <input type="text" class="form-control" name="mobile_number"
                           value="<?php echo e($emp['mobile_number'] ?? ''); ?>" maxlength="15">
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label fw-bold">Date of Birth</label>
                    <input type="date" class="form-control" name="date_of_birth"
                           value="<?php echo e(($emp['date_of_birth'] ?? '') !== '0000-00-00' ? ($emp['date_of_birth'] ?? '') : ''); ?>">
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label fw-bold">Gender</label>
                    <select class="form-select" name="gender">
                      <?php $g = (string)($emp['gender'] ?? ''); ?>
                      <option value="" <?php echo $g===''?'selected':''; ?>>Select</option>
                      <option value="Male" <?php echo $g==='Male'?'selected':''; ?>>Male</option>
                      <option value="Female" <?php echo $g==='Female'?'selected':''; ?>>Female</option>
                      <option value="Other" <?php echo $g==='Other'?'selected':''; ?>>Other</option>
                    </select>
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label fw-bold">Blood Group</label>
                    <input type="text" class="form-control" name="blood_group"
                           value="<?php echo e($emp['blood_group'] ?? ''); ?>" placeholder="A+, O+, ...">
                  </div>

                  <div class="col-12">
                    <label class="form-label fw-bold">Current Address</label>
                    <textarea class="form-control" name="current_address" rows="3"
                              placeholder="Enter address"><?php echo e($emp['current_address'] ?? ''); ?></textarea>
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label fw-bold">Emergency Contact Name</label>
                    <input type="text" class="form-control" name="emergency_contact_name"
                           value="<?php echo e($emp['emergency_contact_name'] ?? ''); ?>">
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label fw-bold">Emergency Contact Phone</label>
                    <input type="text" class="form-control" name="emergency_contact_phone"
                           value="<?php echo e($emp['emergency_contact_phone'] ?? ''); ?>" maxlength="15">
                  </div>
                </div>

                <div class="mt-3 d-flex justify-content-end">
                  <button class="btn btn-primary btn-tek" type="submit">
                    <i class="bi bi-save me-1"></i>Save Changes
                  </button>
                </div>
              </form>
            </div>

            <!-- Password change -->
            <div class="panel">
              <h3 class="panel-title mb-2">Change Password</h3>
              <form method="post">
                <input type="hidden" name="action" value="change_password">

                <div class="row g-3">
                  <div class="col-12 col-md-4">
                    <label class="form-label fw-bold">Current Password</label>
                    <input type="password" class="form-control" name="current_password" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label fw-bold">New Password</label>
                    <input type="password" class="form-control" name="new_password" required>
                  </div>
                  <div class="col-12 col-md-4">
                    <label class="form-label fw-bold">Confirm Password</label>
                    <input type="password" class="form-control" name="confirm_password" required>
                  </div>
                </div>

                <div class="mt-3 d-flex justify-content-end">
                  <button class="btn btn-dark btn-tek" type="submit">
                    <i class="bi bi-shield-lock me-1"></i>Update Password
                  </button>
                </div>

                <div class="muted mt-2" style="font-size:12px;">
                  Tip: Use at least 6 characters.
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

</body>
</html>

<?php mysqli_close($conn); ?>
