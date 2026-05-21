<?php
// company-settings.php - Company Details + Divisions Management
// Roles allowed: Admin, Manager, Team Lead

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
// if (empty($_SESSION['employee_id'])) {
//   header("Location: ../login.php");
//   exit;
// }

$employeeId = (int)($_SESSION['employee_id'] ?? 0);
// $designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
// $role = strtolower(trim((string)($_SESSION['role'] ?? '')));

// $allowed = ['admin', 'manager', 'team lead'];
// if (!in_array($designation, $allowed, true) && !in_array($role, $allowed, true)) {
//   header("Location: index.php");
//   exit;
// }

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tableExists(mysqli $conn, string $table): bool {
  $safe = mysqli_real_escape_string($conn, $table);
  $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
  return $res && mysqli_num_rows($res) > 0;
}

function ensureCompanyDivisionsTable(mysqli $conn): void {
  $sql = "
    CREATE TABLE IF NOT EXISTS company_divisions (
      id INT(11) NOT NULL AUTO_INCREMENT,
      company_id INT(11) NOT NULL DEFAULT 1,
      division_name VARCHAR(150) NOT NULL,
      division_code VARCHAR(50) DEFAULT NULL,
      division_head VARCHAR(150) DEFAULT NULL,
      division_phone VARCHAR(50) DEFAULT NULL,
      division_email VARCHAR(150) DEFAULT NULL,
      division_address TEXT DEFAULT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_company_id (company_id),
      KEY idx_is_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ";
  mysqli_query($conn, $sql);
}

ensureCompanyDivisionsTable($conn);

// ---------------- Get Employee Name ----------------
$empName = $_SESSION['employee_name'] ?? '';

// ---------------- Fetch Company Details ----------------
$company = null;
$query = "SELECT * FROM company_details WHERE id = 1 LIMIT 1";
$result = mysqli_query($conn, $query);
if ($result && mysqli_num_rows($result) > 0) {
  $company = mysqli_fetch_assoc($result);
}

// ---------------- Toast ----------------
$toast_message = '';
$toast_type = '';

// ---------------- Handle Company Form Submission ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company'])) {

  $company_name = trim($_POST['company_name'] ?? '');
  $company_address = trim($_POST['company_address'] ?? '');
  $company_phone = trim($_POST['company_phone'] ?? '');
  $company_email = trim($_POST['company_email'] ?? '');
  $company_website = trim($_POST['company_website'] ?? '');
  $gst_number = trim($_POST['gst_number'] ?? '');
  $pan_number = trim($_POST['pan_number'] ?? '');
  $ceo_name = trim($_POST['ceo_name'] ?? '');
  $ceo_designation = trim($_POST['ceo_designation'] ?? '');
  $established_date = trim($_POST['established_date'] ?? '');

  if ($established_date === '') {
    $established_date = null;
  }

  $errors = [];

  if ($company_name === '') $errors[] = "Company name is required.";
  if ($company_address === '') $errors[] = "Company address is required.";
  if ($company_phone === '') $errors[] = "Company phone is required.";
  if ($company_email === '') $errors[] = "Company email is required.";
  if ($ceo_name === '') $errors[] = "CEO name is required.";

  if ($company_email !== '' && !filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format.";
  }

  if ($company_website !== '' && !filter_var($company_website, FILTER_VALIDATE_URL)) {
    $errors[] = "Invalid website URL format.";
  }

  if (empty($errors)) {
    if ($company) {
      $stmt = mysqli_prepare($conn, "
        UPDATE company_details SET
          company_name = ?,
          company_address = ?,
          company_phone = ?,
          company_email = ?,
          company_website = ?,
          gst_number = ?,
          pan_number = ?,
          ceo_name = ?,
          ceo_designation = ?,
          established_date = ?,
          updated_by = ?
        WHERE id = 1
      ");

      if ($stmt) {
        mysqli_stmt_bind_param(
          $stmt,
          "ssssssssssi",
          $company_name,
          $company_address,
          $company_phone,
          $company_email,
          $company_website,
          $gst_number,
          $pan_number,
          $ceo_name,
          $ceo_designation,
          $established_date,
          $employeeId
        );
      }
    } else {
      $stmt = mysqli_prepare($conn, "
        INSERT INTO company_details (
          id,
          company_name,
          company_address,
          company_phone,
          company_email,
          company_website,
          gst_number,
          pan_number,
          ceo_name,
          ceo_designation,
          established_date,
          updated_by
        ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");

      if ($stmt) {
        mysqli_stmt_bind_param(
          $stmt,
          "ssssssssssi",
          $company_name,
          $company_address,
          $company_phone,
          $company_email,
          $company_website,
          $gst_number,
          $pan_number,
          $ceo_name,
          $ceo_designation,
          $established_date,
          $employeeId
        );
      }
    }

    if (!isset($stmt) || !$stmt) {
      $toast_message = "Database error: " . mysqli_error($conn);
      $toast_type = "error";
    } elseif (mysqli_stmt_execute($stmt)) {
      $toast_message = "Company details updated successfully!";
      $toast_type = "success";

      $result = mysqli_query($conn, "SELECT * FROM company_details WHERE id = 1 LIMIT 1");
      $company = $result ? mysqli_fetch_assoc($result) : null;
    } else {
      $toast_message = "Error updating company details: " . mysqli_stmt_error($stmt);
      $toast_type = "error";
    }

    if (isset($stmt) && $stmt) mysqli_stmt_close($stmt);
  } else {
    $toast_message = implode("<br>", $errors);
    $toast_type = "error";
  }
}

// ---------------- Handle Division Add / Update ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_division'])) {
  $division_id = (int)($_POST['division_id'] ?? 0);
  $division_name = trim($_POST['division_name'] ?? '');
  $division_code = trim($_POST['division_code'] ?? '');
  $division_head = trim($_POST['division_head'] ?? '');
  $division_phone = trim($_POST['division_phone'] ?? '');
  $division_email = trim($_POST['division_email'] ?? '');
  $division_address = trim($_POST['division_address'] ?? '');
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  $errors = [];

  if ($division_name === '') $errors[] = "Division name is required.";
  if ($division_email !== '' && !filter_var($division_email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid division email format.";
  }

  if (empty($errors)) {
    if ($division_id > 0) {
      $stmt = mysqli_prepare($conn, "
        UPDATE company_divisions SET
          division_name = ?,
          division_code = ?,
          division_head = ?,
          division_phone = ?,
          division_email = ?,
          division_address = ?,
          is_active = ?
        WHERE id = ? AND company_id = 1
      ");

      if ($stmt) {
        mysqli_stmt_bind_param(
          $stmt,
          "ssssssii",
          $division_name,
          $division_code,
          $division_head,
          $division_phone,
          $division_email,
          $division_address,
          $is_active,
          $division_id
        );
      }
    } else {
      $stmt = mysqli_prepare($conn, "
        INSERT INTO company_divisions (
          company_id,
          division_name,
          division_code,
          division_head,
          division_phone,
          division_email,
          division_address,
          is_active
        ) VALUES (1, ?, ?, ?, ?, ?, ?, ?)
      ");

      if ($stmt) {
        mysqli_stmt_bind_param(
          $stmt,
          "ssssssi",
          $division_name,
          $division_code,
          $division_head,
          $division_phone,
          $division_email,
          $division_address,
          $is_active
        );
      }
    }

    if (!isset($stmt) || !$stmt) {
      $toast_message = "Database error: " . mysqli_error($conn);
      $toast_type = "error";
    } elseif (mysqli_stmt_execute($stmt)) {
      $toast_message = ($division_id > 0) ? "Division updated successfully!" : "Division added successfully!";
      $toast_type = "success";
    } else {
      $toast_message = "Error saving division: " . mysqli_stmt_error($stmt);
      $toast_type = "error";
    }

    if (isset($stmt) && $stmt) mysqli_stmt_close($stmt);
  } else {
    $toast_message = implode("<br>", $errors);
    $toast_type = "error";
  }
}

// ---------------- Handle Division Delete ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_division'])) {
  $division_id = (int)($_POST['division_id'] ?? 0);

  if ($division_id > 0) {
    $stmt = mysqli_prepare($conn, "DELETE FROM company_divisions WHERE id = ? AND company_id = 1");
    if ($stmt) {
      mysqli_stmt_bind_param($stmt, "i", $division_id);
      if (mysqli_stmt_execute($stmt)) {
        $toast_message = "Division deleted successfully!";
        $toast_type = "success";
      } else {
        $toast_message = "Error deleting division: " . mysqli_stmt_error($stmt);
        $toast_type = "error";
      }
      mysqli_stmt_close($stmt);
    } else {
      $toast_message = "Database error: " . mysqli_error($conn);
      $toast_type = "error";
    }
  }
}

// ---------------- Handle Logo Upload ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo']) && isset($_FILES['company_logo'])) {
  $target_dir = "uploads/company/";
  if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
  }

  $file_extension = strtolower(pathinfo($_FILES["company_logo"]["name"], PATHINFO_EXTENSION));
  $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

  if ($_FILES['company_logo']['error'] !== UPLOAD_ERR_OK) {
    $toast_message = "Logo upload error.";
    $toast_type = "error";
  } elseif (!in_array($file_extension, $allowed_extensions, true)) {
    $toast_message = "Invalid file type. Allowed: JPG, JPEG, PNG, GIF, WEBP";
    $toast_type = "error";
  } else {
    $new_filename = "company_logo_" . time() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($_FILES["company_logo"]["tmp_name"], $target_file)) {
      $stmt = mysqli_prepare($conn, "UPDATE company_details SET logo_path = ? WHERE id = 1");
      if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $target_file);
        if (mysqli_stmt_execute($stmt)) {
          $toast_message = "Logo uploaded successfully!";
          $toast_type = "success";

          $result = mysqli_query($conn, "SELECT * FROM company_details WHERE id = 1 LIMIT 1");
          $company = $result ? mysqli_fetch_assoc($result) : null;
        } else {
          $toast_message = "Error saving logo: " . mysqli_stmt_error($stmt);
          $toast_type = "error";
        }
        mysqli_stmt_close($stmt);
      }
    } else {
      $toast_message = "Error uploading logo.";
      $toast_type = "error";
    }
  }
}

// ---------------- Refresh Company Details ----------------
$result = mysqli_query($conn, "SELECT * FROM company_details WHERE id = 1 LIMIT 1");
if ($result && mysqli_num_rows($result) > 0) {
  $company = mysqli_fetch_assoc($result);
}

// ---------------- Division edit is handled with Bootstrap modal on this same page ----------------
$editDivision = null;

// ---------------- Fetch Divisions ----------------
$divisions = [];
$resDiv = mysqli_query($conn, "SELECT * FROM company_divisions WHERE company_id = 1 ORDER BY is_active DESC, division_name ASC");
if ($resDiv) {
  $divisions = mysqli_fetch_all($resDiv, MYSQLI_ASSOC);
  mysqli_free_result($resDiv);
}

$activeDivisions = 0;
foreach ($divisions as $d) {
  if ((int)($d['is_active'] ?? 0) === 1) $activeDivisions++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Company Settings - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding:16px;
      margin-bottom:14px;
    }
    .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .h-title{ margin:0; font-weight:1000; color:#111827; }
    .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

    .form-label{ font-weight:900; color:#374151; font-size:13px; }
    .form-control, .form-select{
      border:2px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 12px;
      font-weight: 750;
      font-size: 14px;
    }
    .form-control:focus, .form-select:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45,156,219,.1);
    }

    .sec-head{
      display:flex; align-items:center; gap:10px;
      padding: 10px 12px;
      border-radius: 14px;
      background:#f9fafb;
      border:1px solid #eef2f7;
      margin-bottom:10px;
    }
    .sec-ic{
      width:34px;height:34px;border-radius: 12px;
      display:grid;place-items:center;
      background: rgba(45,156,219,.12);
      color: var(--blue);
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

    .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; }
    .grid-4{ display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:12px; }
    @media (max-width: 992px){
      .grid-2, .grid-3, .grid-4{ grid-template-columns: 1fr; }
    }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
      color:#111827;
      text-decoration:none;
    }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

    .btn-primary-tek{
      background: var(--blue);
      border:none;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 1000;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
      color:#fff;
      text-decoration:none;
    }
    .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }
    .btn-outline-tek{
      border:2px solid var(--blue);
      background: transparent;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 1000;
      color: var(--blue);
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
    }
    .btn-outline-tek:hover{ background: var(--blue); color:#fff; }

    .btn-icon{
      width:34px;
      height:34px;
      border-radius:10px;
      border:1px solid #e5e7eb;
      background:#fff;
      color:#374151;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      text-decoration:none;
      font-weight:1000;
    }
    .btn-icon:hover{ color:var(--blue); background:#f9fafb; }
    .btn-icon.danger:hover{ color:#dc2626; background:#fff5f5; }

    .logo-preview{
      width: 120px;
      height: 120px;
      border: 2px solid #e5e7eb;
      border-radius: 16px;
      object-fit: cover;
      background: #f9fafb;
    }
    .info-card{
      background: #f9fafb;
      border-radius: 14px;
      padding: 12px;
      border: 1px solid #eef2f7;
    }
    .info-label{
      font-weight: 900;
      color: #6b7280;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .info-value{
      font-weight: 1000;
      color: #111827;
      font-size: 15px;
      margin-top: 4px;
      word-break:break-word;
    }
    .division-card{
      border:1px solid #eef2f7;
      border-radius:14px;
      background:#fff;
      padding:12px;
      height:100%;
      box-shadow: 0 8px 18px rgba(17,24,39,.04);
    }
    .division-title{
      font-weight:1000;
      color:#111827;
      font-size:15px;
      margin:0;
      line-height:1.25;
    }
    .status-badge{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:4px 9px;
      border-radius:999px;
      font-size:11px;
      font-weight:1000;
      white-space:nowrap;
    }
    .status-active{ background:rgba(16,185,129,.12); color:#059669; }
    .status-inactive{ background:rgba(107,114,128,.12); color:#4b5563; }
    .table thead th{
      background:#f9fafb;
      color:#6b7280;
      font-size:12px;
      font-weight:1000;
      white-space:nowrap;
    }
    .table td{
      vertical-align:middle;
      font-weight:800;
      color:#111827;
      font-size:13px;
    }

    .modal .btn-primary-tek,
    .modal .btn-outline-tek{ border-width:0; }
    .modal .btn-outline-tek{ border:2px solid var(--blue); background:#fff; }
    .js-edit-division{ cursor:pointer; }
    .fw-black{ font-weight:1000; }
    .modal-backdrop{ z-index:1050; }
    .modal{ z-index:1060; }

    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
      .sec-head { padding: 10px !important; border-radius: 12px; }
    }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div class="content-scroll">
      <div class="container-fluid maxw">

        <div class="title-row mb-3">
          <div>
            <h1 class="h-title">Company Settings</h1>
            <p class="h-sub">Manage company details, logo, and divisions</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-building"></i> Company Profile</span>
            <span class="badge-pill"><i class="bi bi-diagram-3"></i> <?php echo (int)count($divisions); ?> Division(s)</span>
            <span class="badge-pill"><i class="bi bi-check2-circle"></i> <?php echo (int)$activeDivisions; ?> Active</span>
          </div>
        </div>

        <!-- COMPANY INFO PANEL -->
        <form method="POST" id="companyForm">
          <input type="hidden" name="update_company" value="1">

          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-building"></i></div>
              <div>
                <p class="sec-title mb-0">Basic Information</p>
                <p class="sec-sub mb-0">Company name, contact details, and registration</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="company_name"
                       value="<?php echo e($company['company_name'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="form-label">Company Phone <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="company_phone"
                       value="<?php echo e($company['company_phone'] ?? ''); ?>" required>
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">Company Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" name="company_email"
                       value="<?php echo e($company['company_email'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="form-label">Company Website</label>
                <input type="url" class="form-control" name="company_website"
                       value="<?php echo e($company['company_website'] ?? ''); ?>" placeholder="https://">
              </div>
            </div>

            <div class="mt-2">
              <label class="form-label">Company Address <span class="text-danger">*</span></label>
              <textarea class="form-control" name="company_address" rows="2" required><?php echo e($company['company_address'] ?? ''); ?></textarea>
            </div>
          </div>

          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-file-earmark-text"></i></div>
              <div>
                <p class="sec-title mb-0">Registration Details</p>
                <p class="sec-sub mb-0">GST, PAN, and other identifiers</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">GST Number</label>
                <input type="text" class="form-control" name="gst_number"
                       value="<?php echo e($company['gst_number'] ?? ''); ?>" placeholder="27ABCDE1234F1Z5">
              </div>
              <div>
                <label class="form-label">PAN Number</label>
                <input type="text" class="form-control" name="pan_number"
                       value="<?php echo e($company['pan_number'] ?? ''); ?>" placeholder="ABCDE1234F">
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-person-badge"></i></div>
              <div>
                <p class="sec-title mb-0">Leadership Information</p>
                <p class="sec-sub mb-0">CEO details and establishment</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">CEO Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="ceo_name"
                       value="<?php echo e($company['ceo_name'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="form-label">CEO Designation</label>
                <input type="text" class="form-control" name="ceo_designation"
                       value="<?php echo e($company['ceo_designation'] ?? 'Chief Executive Officer'); ?>">
              </div>
              <div>
                <label class="form-label">Established Date</label>
                <input type="date" class="form-control" name="established_date"
                       value="<?php echo e($company['established_date'] ?? ''); ?>">
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek">
                <i class="bi bi-save"></i> Save Company Details
              </button>
            </div>
          </div>
        </form>
        <!-- DIVISION ACTION PANEL -->
        <div class="panel" id="divisionFormPanel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-diagram-3"></i></div>
            <div class="flex-grow-1">
              <p class="sec-title mb-0">Divisions Management</p>
              <p class="sec-sub mb-0">Add and edit company divisions using the popup form</p>
            </div>
            <button type="button" class="btn-primary-tek js-add-division" data-bs-toggle="modal" data-bs-target="#divisionModal">
              <i class="bi bi-plus-circle"></i> Add Division
            </button>
          </div>
        </div>

        <!-- DIVISIONS LIST PANEL -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-list-check"></i></div>
            <div>
              <p class="sec-title mb-0">Company Divisions</p>
              <p class="sec-sub mb-0">Manage all divisions linked to this company</p>
            </div>
          </div>

          <?php if (empty($divisions)): ?>
            <div class="alert alert-warning mb-0" style="border-radius:14px; border:none;">
              <i class="bi bi-info-circle me-2"></i>No divisions added yet.
            </div>
          <?php else: ?>
            <!-- Mobile Cards -->
            <div class="d-block d-md-none">
              <div class="d-grid gap-3">
                <?php foreach ($divisions as $d): ?>
                  <div class="division-card">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                      <div>
                        <p class="division-title"><?php echo e($d['division_name']); ?></p>
                        <?php if (!empty($d['division_code'])): ?>
                          <div class="small-muted mt-1">Code: <?php echo e($d['division_code']); ?></div>
                        <?php endif; ?>
                      </div>
                      <?php if ((int)$d['is_active'] === 1): ?>
                        <span class="status-badge status-active"><i class="bi bi-check-circle"></i> Active</span>
                      <?php else: ?>
                        <span class="status-badge status-inactive"><i class="bi bi-pause-circle"></i> Inactive</span>
                      <?php endif; ?>
                    </div>

                    <div class="mt-2 small-muted">
                      <?php if (!empty($d['division_head'])): ?>
                        <div><i class="bi bi-person-badge me-1"></i><?php echo e($d['division_head']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($d['division_phone'])): ?>
                        <div><i class="bi bi-telephone me-1"></i><?php echo e($d['division_phone']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($d['division_email'])): ?>
                        <div><i class="bi bi-envelope me-1"></i><?php echo e($d['division_email']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($d['division_address'])): ?>
                        <div class="mt-1"><i class="bi bi-geo-alt me-1"></i><?php echo e($d['division_address']); ?></div>
                      <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                      <button type="button"
                              class="btn-icon js-edit-division"
                              title="Edit"
                              data-bs-toggle="modal"
                              data-bs-target="#divisionModal"
                              data-id="<?php echo (int)$d['id']; ?>"
                              data-name="<?php echo e($d['division_name']); ?>"
                              data-code="<?php echo e($d['division_code']); ?>"
                              data-head="<?php echo e($d['division_head']); ?>"
                              data-phone="<?php echo e($d['division_phone']); ?>"
                              data-email="<?php echo e($d['division_email']); ?>"
                              data-address="<?php echo e($d['division_address']); ?>"
                              data-active="<?php echo (int)$d['is_active']; ?>">
                        <i class="bi bi-pencil"></i>
                      </button>
                      <form method="POST" onsubmit="return confirm('Delete this division?');">
                        <input type="hidden" name="delete_division" value="1">
                        <input type="hidden" name="division_id" value="<?php echo (int)$d['id']; ?>">
                        <button type="submit" class="btn-icon danger" title="Delete">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Desktop Table -->
            <div class="d-none d-md-block table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width:60px;">#</th>
                    <th>Division</th>
                    <th>Head</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th class="text-end" style="width:120px;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $i = 1; foreach ($divisions as $d): ?>
                    <tr>
                      <td><?php echo $i++; ?></td>
                      <td>
                        <strong><?php echo e($d['division_name']); ?></strong>
                        <?php if (!empty($d['division_code'])): ?>
                          <div class="small-muted">Code: <?php echo e($d['division_code']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($d['division_address'])): ?>
                          <div class="small-muted"><?php echo e($d['division_address']); ?></div>
                        <?php endif; ?>
                      </td>
                      <td><?php echo e($d['division_head'] ?: '—'); ?></td>
                      <td>
                        <?php if (!empty($d['division_phone'])): ?>
                          <div><i class="bi bi-telephone me-1"></i><?php echo e($d['division_phone']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($d['division_email'])): ?>
                          <div class="small-muted"><i class="bi bi-envelope me-1"></i><?php echo e($d['division_email']); ?></div>
                        <?php endif; ?>
                        <?php if (empty($d['division_phone']) && empty($d['division_email'])): ?>
                          <span class="small-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ((int)$d['is_active'] === 1): ?>
                          <span class="status-badge status-active"><i class="bi bi-check-circle"></i> Active</span>
                        <?php else: ?>
                          <span class="status-badge status-inactive"><i class="bi bi-pause-circle"></i> Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end">
                        <div class="d-flex justify-content-end gap-2">
                          <button type="button"
                              class="btn-icon js-edit-division"
                              title="Edit"
                              data-bs-toggle="modal"
                              data-bs-target="#divisionModal"
                              data-id="<?php echo (int)$d['id']; ?>"
                              data-name="<?php echo e($d['division_name']); ?>"
                              data-code="<?php echo e($d['division_code']); ?>"
                              data-head="<?php echo e($d['division_head']); ?>"
                              data-phone="<?php echo e($d['division_phone']); ?>"
                              data-email="<?php echo e($d['division_email']); ?>"
                              data-address="<?php echo e($d['division_address']); ?>"
                              data-active="<?php echo (int)$d['is_active']; ?>">
                        <i class="bi bi-pencil"></i>
                      </button>
                          <form method="POST" onsubmit="return confirm('Delete this division?');">
                            <input type="hidden" name="delete_division" value="1">
                            <input type="hidden" name="division_id" value="<?php echo (int)$d['id']; ?>">
                            <button type="submit" class="btn-icon danger" title="Delete">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>


        <!-- DIVISION MODAL -->
        <div class="modal fade" id="divisionModal" tabindex="-1" aria-labelledby="divisionModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius:18px; border:0; overflow:hidden;">
              <form method="POST" id="divisionForm">
                <input type="hidden" name="save_division" value="1">
                <input type="hidden" name="division_id" id="modal_division_id" value="0">

                <div class="modal-header" style="background:#f9fafb; border-bottom:1px solid #eef2f7;">
                  <div>
                    <h5 class="modal-title fw-black" id="divisionModalLabel" style="font-weight:1000;">Add Division</h5>
                    <div class="small-muted">Fill division details and save</div>
                  </div>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                  <div class="grid-3">
                    <div>
                      <label class="form-label">Division Name <span class="text-danger">*</span></label>
                      <input type="text" class="form-control" name="division_name" id="modal_division_name"
                             placeholder="Example: PMC / QS / HR" required>
                    </div>
                    <div>
                      <label class="form-label">Division Code</label>
                      <input type="text" class="form-control" name="division_code" id="modal_division_code"
                             placeholder="Example: PMC">
                    </div>
                    <div>
                      <label class="form-label">Division Head</label>
                      <input type="text" class="form-control" name="division_head" id="modal_division_head"
                             placeholder="Division head name">
                    </div>
                  </div>

                  <div class="grid-3 mt-2">
                    <div>
                      <label class="form-label">Division Phone</label>
                      <input type="text" class="form-control" name="division_phone" id="modal_division_phone"
                             placeholder="Phone number">
                    </div>
                    <div>
                      <label class="form-label">Division Email</label>
                      <input type="email" class="form-control" name="division_email" id="modal_division_email"
                             placeholder="division@example.com">
                    </div>
                    <div class="d-flex align-items-end">
                      <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="modal_is_active" value="1" checked>
                        <label class="form-check-label fw-bold" for="modal_is_active">Active Division</label>
                      </div>
                    </div>
                  </div>

                  <div class="mt-2">
                    <label class="form-label">Division Address / Notes</label>
                    <textarea class="form-control" name="division_address" id="modal_division_address" rows="2"
                              placeholder="Optional address or notes"></textarea>
                  </div>
                </div>

                <div class="modal-footer" style="border-top:1px solid #eef2f7;">
                  <button type="button" class="btn-outline-tek" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cancel
                  </button>
                  <button type="submit" class="btn-primary-tek" id="divisionModalSubmit">
                    <i class="bi bi-save"></i> Save Division
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- LOGO UPLOAD PANEL -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-image"></i></div>
            <div>
              <p class="sec-title mb-0">Company Logo</p>
              <p class="sec-sub mb-0">Upload company logo (JPG, PNG, GIF, WEBP)</p>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-3 text-center">
              <?php if (!empty($company['logo_path']) && file_exists($company['logo_path'])): ?>
                <img src="<?php echo e($company['logo_path']); ?>" class="logo-preview" alt="Company Logo">
              <?php else: ?>
                <div class="logo-preview d-flex align-items-center justify-content-center bg-light mx-auto">
                  <i class="bi bi-building" style="font-size: 40px; color: #ccc;"></i>
                </div>
              <?php endif; ?>
            </div>
            <div class="col-md-9">
              <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="upload_logo" value="1">
                <div class="mb-3">
                  <label class="form-label">Select Logo Image</label>
                  <input type="file" class="form-control" name="company_logo" accept="image/*" required>
                </div>
                <button type="submit" class="btn-outline-tek">
                  <i class="bi bi-upload"></i> Upload Logo
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- PREVIEW PANEL -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-eye"></i></div>
            <div>
              <p class="sec-title mb-0">Company Information Preview</p>
              <p class="sec-sub mb-0">How company details and divisions will appear</p>
            </div>
          </div>

          <div class="info-card">
            <div class="row g-3">
              <div class="col-md-8">
                <div class="info-label">Company Name</div>
                <div class="info-value"><?php echo e($company['company_name'] ?? 'Not set'); ?></div>

                <div class="row mt-3">
                  <div class="col-md-6">
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?php echo e($company['company_phone'] ?? 'Not set'); ?></div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo e($company['company_email'] ?? 'Not set'); ?></div>
                  </div>
                </div>

                <div class="mt-3">
                  <div class="info-label">Address</div>
                  <div class="info-value"><?php echo e($company['company_address'] ?? 'Not set'); ?></div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="info-label">CEO</div>
                <div class="info-value"><?php echo e($company['ceo_name'] ?? 'Not set'); ?></div>
                <div class="info-label mt-2">Designation</div>
                <div class="info-value"><?php echo e($company['ceo_designation'] ?? 'Not set'); ?></div>
                <?php if (!empty($company['gst_number'])): ?>
                  <div class="info-label mt-2">GST</div>
                  <div class="info-value"><?php echo e($company['gst_number']); ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if (!empty($divisions)): ?>
            <div class="mt-3">
              <div class="info-label mb-2">Active Divisions</div>
              <div class="row g-2">
                <?php foreach ($divisions as $d): ?>
                  <?php if ((int)$d['is_active'] !== 1) continue; ?>
                  <div class="col-md-4">
                    <div class="division-card">
                      <p class="division-title"><?php echo e($d['division_name']); ?></p>
                      <?php if (!empty($d['division_head'])): ?>
                        <div class="small-muted mt-1"><i class="bi bi-person-badge me-1"></i><?php echo e($d['division_head']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($d['division_email'])): ?>
                        <div class="small-muted"><i class="bi bi-envelope me-1"></i><?php echo e($d['division_email']); ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- LAST UPDATED INFO -->
        <?php if (!empty($company['updated_at'])): ?>
          <div class="text-end small-muted">
            Last updated: <?php echo date('d M Y h:i A', strtotime($company['updated_at'])); ?>
            <?php if (!empty($company['updated_by'])): ?>
              <?php
                $updatedBy = (int)$company['updated_by'];
                $updater = mysqli_query($conn, "SELECT full_name FROM employees WHERE id = {$updatedBy} LIMIT 1");
                if ($updater && $u = mysqli_fetch_assoc($updater)) {
                  echo " by " . e($u['full_name']);
                }
              ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
toastr.options = {
  "closeButton": true,
  "progressBar": true,
  "positionClass": "toast-top-right",
  "timeOut": "5000",
  "extendedTimeOut": "2000",
  "showMethod": "slideDown",
  "hideMethod": "slideUp",
  "tapToDismiss": false
};

<?php if (!empty($toast_message)): ?>
  <?php if ($toast_type === 'success'): ?>
    toastr.success('<?php echo addslashes($toast_message); ?>', 'Success');
  <?php else: ?>
    toastr.error('<?php echo addslashes($toast_message); ?>', 'Error');
  <?php endif; ?>
<?php endif; ?>

document.getElementById('companyForm')?.addEventListener('submit', function(e) {
  const companyName = document.querySelector('[name="company_name"]').value.trim();
  const companyPhone = document.querySelector('[name="company_phone"]').value.trim();
  const companyEmail = document.querySelector('[name="company_email"]').value.trim();
  const companyAddress = document.querySelector('[name="company_address"]').value.trim();
  const ceoName = document.querySelector('[name="ceo_name"]').value.trim();

  let errors = [];

  if (!companyName) errors.push('Company name is required');
  if (!companyPhone) errors.push('Company phone is required');
  if (!companyEmail) errors.push('Company email is required');
  if (!companyAddress) errors.push('Company address is required');
  if (!ceoName) errors.push('CEO name is required');

  if (companyEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(companyEmail)) {
    errors.push('Invalid email format');
  }

  if (errors.length > 0) {
    e.preventDefault();
    toastr.error(errors.join('<br>'), 'Validation Error');
  }
});

const divisionModalEl = document.getElementById('divisionModal');

function resetDivisionModal() {
  document.getElementById('divisionModalLabel').textContent = 'Add Division';
  document.getElementById('divisionModalSubmit').innerHTML = '<i class="bi bi-save"></i> Save Division';
  document.getElementById('modal_division_id').value = '0';
  document.getElementById('modal_division_name').value = '';
  document.getElementById('modal_division_code').value = '';
  document.getElementById('modal_division_head').value = '';
  document.getElementById('modal_division_phone').value = '';
  document.getElementById('modal_division_email').value = '';
  document.getElementById('modal_division_address').value = '';
  document.getElementById('modal_is_active').checked = true;
}

document.querySelectorAll('.js-add-division').forEach(function(btn) {
  btn.addEventListener('click', resetDivisionModal);
});

document.querySelectorAll('.js-edit-division').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('divisionModalLabel').textContent = 'Edit Division';
    document.getElementById('divisionModalSubmit').innerHTML = '<i class="bi bi-save"></i> Update Division';

    document.getElementById('modal_division_id').value = this.dataset.id || '0';
    document.getElementById('modal_division_name').value = this.dataset.name || '';
    document.getElementById('modal_division_code').value = this.dataset.code || '';
    document.getElementById('modal_division_head').value = this.dataset.head || '';
    document.getElementById('modal_division_phone').value = this.dataset.phone || '';
    document.getElementById('modal_division_email').value = this.dataset.email || '';
    document.getElementById('modal_division_address').value = this.dataset.address || '';
    document.getElementById('modal_is_active').checked = (String(this.dataset.active || '1') === '1');
  });
});

document.getElementById('divisionForm')?.addEventListener('submit', function(e) {
  const divisionName = document.getElementById('modal_division_name').value.trim();
  const divisionEmail = document.getElementById('modal_division_email').value.trim();

  let errors = [];

  if (!divisionName) errors.push('Division name is required');
  if (divisionEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(divisionEmail)) {
    errors.push('Invalid division email format');
  }

  if (errors.length > 0) {
    e.preventDefault();
    toastr.error(errors.join('<br>'), 'Validation Error');
  }
});
</script>

</body>
</html>
<?php
try {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) {}
?>
