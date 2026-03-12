<?php
// add-site.php  (AS Electricals)
// - Adds a "Site/Project" record linked to a Client (select client)
// - Stores Project + PMC + Agreement/WO + Document + Communication fields in `sites` table
//
// REQUIRED TABLE (create once if you don't have it):
/*
CREATE TABLE `sites` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `client_id` INT(10) UNSIGNED NOT NULL,

  `project_name` VARCHAR(200) NOT NULL,
  `project_type` ENUM('Residential','Commercial','Industrial','Infrastructure') NOT NULL,
  `project_location` VARCHAR(255) NOT NULL,
  `scope_of_work` VARCHAR(255) NOT NULL,
  `contract_value` DECIMAL(15,2) NOT NULL,
  `start_date` DATE NOT NULL,
  `expected_completion_date` DATE NOT NULL,
  `boq_details` TEXT NOT NULL,

  `pmc_charges` DECIMAL(15,2) NOT NULL,

  `agreement_number` VARCHAR(100) NOT NULL,
  `agreement_date` DATE NOT NULL,
  `work_order_date` DATE NOT NULL,
  `contract_document` VARCHAR(255) NOT NULL,

  `authorized_signatory_name` VARCHAR(150) NOT NULL,
  `authorized_signatory_contact` VARCHAR(15) NOT NULL,
  `contact_person_designation` VARCHAR(100) NOT NULL,
  `contact_person_email` VARCHAR(190) NOT NULL,
  `approval_authority` VARCHAR(150) NOT NULL,
  `site_in_charge_client_side` VARCHAR(150) DEFAULT NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_sites_client_id` (`client_id`),
  CONSTRAINT `fk_sites_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
*/

session_start();
require_once 'includes/db-config.php';

$success = '';
$error = '';
$validation_errors = [];

$conn = get_db_connection();
if (!$conn) {
  die("Database connection failed.");
}

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// OPTIONS
$project_types = ['Residential', 'Commercial', 'Industrial', 'Infrastructure'];
$scope_of_work_options = ['Civil', 'Interior', 'MEP', 'Turnkey', 'BOQ', 'PMC'];

// ---------- Fetch clients for dropdown ----------
$clients = [];
$resC = mysqli_query($conn, "SELECT id, client_name, company_name, mobile_number FROM clients ORDER BY client_name ASC");
if ($resC) {
  $clients = mysqli_fetch_all($resC, MYSQLI_ASSOC);
  mysqli_free_result($resC);
}

// ---------- Upload handler ----------
function handleSiteDocUpload($field_name, $target_dir) {
  $upload_dir = 'uploads/' . $target_dir;
  if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

  $file = $_FILES[$field_name];
  $file_name = basename($file['name']);
  $file_tmp = $file['tmp_name'];
  $file_size = $file['size'];
  $file_error = $file['error'];

  $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
  $new_file_name = uniqid('site_contract_', true) . '_' . time() . '.' . $file_ext;
  $target_path = $upload_dir . $new_file_name;

  if ($file_error !== UPLOAD_ERR_OK) return ['success' => false, 'error' => 'File upload error: ' . $file_error];
  if ($file_size > 10 * 1024 * 1024) return ['success' => false, 'error' => 'File size too large (max 10MB)'];

  $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];
  if (!in_array($file_ext, $allowed_extensions, true)) {
    return ['success' => false, 'error' => 'Allowed file types: PDF, DOC, DOCX, JPG, PNG, WebP'];
  }

  if (move_uploaded_file($file_tmp, $target_path)) return ['success' => true, 'path' => $target_path];
  return ['success' => false, 'error' => 'Failed to move uploaded file'];
}

// ---------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // SELECT CLIENT
  $client_id = (int)($_POST['client_id'] ?? 0);

  // PROJECT DETAILS
  $project_name = trim($_POST['project_name'] ?? '');
  $project_type = trim($_POST['project_type'] ?? '');
  $project_location = trim($_POST['project_location'] ?? '');
  $scope_of_work = $_POST['scope_of_work'] ?? [];
  $contract_value = trim($_POST['contract_value'] ?? '');
  $start_date = trim($_POST['start_date'] ?? '');
  $expected_completion_date = trim($_POST['expected_completion_date'] ?? '');
  $boq_details = trim($_POST['boq_details'] ?? '');

  if (!is_array($scope_of_work)) $scope_of_work = [];
  $scope_of_work = array_values(array_intersect($scope_of_work, $scope_of_work_options));
  $scope_of_work_csv = implode(', ', $scope_of_work);

  // PMC CHARGES
  $pmc_charges = trim($_POST['pmc_charges'] ?? '');

  // AGREEMENT / WORK ORDER
  $agreement_number = trim($_POST['agreement_number'] ?? '');
  $agreement_date = trim($_POST['agreement_date'] ?? '');
  $work_order_date = trim($_POST['work_order_date'] ?? '');

  // COMMUNICATION
  $authorized_signatory_name = trim($_POST['authorized_signatory_name'] ?? '');
  $authorized_signatory_contact = trim($_POST['authorized_signatory_contact'] ?? '');
  $contact_person_designation = trim($_POST['contact_person_designation'] ?? '');
  $contact_person_email = trim($_POST['contact_person_email'] ?? '');
  $approval_authority = trim($_POST['approval_authority'] ?? '');
  $site_in_charge_client_side = trim($_POST['site_in_charge_client_side'] ?? ''); // optional

  // ---------- Validation ----------
  if ($client_id <= 0) $validation_errors[] = "Please select a client";

  if ($project_name === '') $validation_errors[] = "Project name is required";
  if ($project_type === '') $validation_errors[] = "Project type is required";
  if ($project_type !== '' && !in_array($project_type, $project_types, true)) $validation_errors[] = "Invalid project type selected";
  if ($project_location === '') $validation_errors[] = "Project location is required";
  if (empty($scope_of_work)) $validation_errors[] = "Scope of work is required (select at least one)";
  if ($contract_value === '') $validation_errors[] = "Contract value is required";
  if ($contract_value !== '' && !preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $contract_value)) $validation_errors[] = "Contract value must be a valid number";
  if ($start_date === '') $validation_errors[] = "Start date is required";
  if ($expected_completion_date === '') $validation_errors[] = "Expected completion date is required";
  if ($boq_details === '') $validation_errors[] = "BOQ details is required";

  if ($start_date !== '' && $expected_completion_date !== '' && strtotime($expected_completion_date) < strtotime($start_date)) {
    $validation_errors[] = "Expected completion date cannot be earlier than start date";
  }

  if ($pmc_charges === '') $validation_errors[] = "PMC charges is required";
  if ($pmc_charges !== '' && !preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $pmc_charges)) $validation_errors[] = "PMC charges must be a valid number";

  if ($agreement_number === '') $validation_errors[] = "Agreement / Contract number is required";
  if ($agreement_date === '') $validation_errors[] = "Agreement date is required";
  if ($work_order_date === '') $validation_errors[] = "Work order date is required";

  if ($authorized_signatory_name === '') $validation_errors[] = "Authorized signatory name is required";
  if ($authorized_signatory_contact === '') $validation_errors[] = "Authorized signatory contact details is required";
  if ($authorized_signatory_contact !== '' && !preg_match('/^[0-9]{10,15}$/', $authorized_signatory_contact)) {
    $validation_errors[] = "Authorized signatory contact must be 10 to 15 digits";
  }
  if ($contact_person_designation === '') $validation_errors[] = "Contact person designation is required";
  if ($contact_person_email === '') $validation_errors[] = "Contact person email is required";
  if ($contact_person_email !== '' && !filter_var($contact_person_email, FILTER_VALIDATE_EMAIL)) {
    $validation_errors[] = "Invalid Contact person email format";
  }
  if ($approval_authority === '') $validation_errors[] = "Approval authority is required";

  // Contract document (required)
  $contract_document_path = '';
  if (!empty($_FILES['contract_document']['name'])) {
    $upload = handleSiteDocUpload('contract_document', 'sites/contracts/');
    if ($upload['success']) $contract_document_path = $upload['path'];
    else $validation_errors[] = $upload['error'];
  } else {
    $validation_errors[] = "Contract document is required";
  }

  // ---------- Insert ----------
  if (empty($validation_errors)) {
    // Ensure client exists
    $chk = mysqli_prepare($conn, "SELECT id FROM clients WHERE id = ? LIMIT 1");
    if ($chk) {
      mysqli_stmt_bind_param($chk, "i", $client_id);
      mysqli_stmt_execute($chk);
      mysqli_stmt_store_result($chk);
      if (mysqli_stmt_num_rows($chk) === 0) $validation_errors[] = "Selected client not found";
      mysqli_stmt_close($chk);
    }

    if (empty($validation_errors)) {
      $sql = "INSERT INTO sites (
          client_id,
          project_name, project_type, project_location, scope_of_work,
          contract_value, start_date, expected_completion_date, boq_details,
          pmc_charges,
          agreement_number, agreement_date, work_order_date, contract_document,
          authorized_signatory_name, authorized_signatory_contact,
          contact_person_designation, contact_person_email,
          approval_authority, site_in_charge_client_side
        ) VALUES (
          ?,
          ?, ?, ?, ?,
          ?, ?, ?, ?,
          ?,
          ?, ?, ?, ?,
          ?, ?,
          ?, ?,
          ?, ?
        )";

      $stmt = mysqli_prepare($conn, $sql);
      if ($stmt) {
        mysqli_stmt_bind_param(
          $stmt,
          "issss" . "dsss" . "dssss" . "ssssss",
          $client_id,
          $project_name, $project_type, $project_location, $scope_of_work_csv,
          $contract_value, $start_date, $expected_completion_date, $boq_details,
          $pmc_charges,
          $agreement_number, $agreement_date, $work_order_date, $contract_document_path,
          $authorized_signatory_name, $authorized_signatory_contact,
          $contact_person_designation, $contact_person_email,
          $approval_authority, $site_in_charge_client_side
        );

        if (mysqli_stmt_execute($stmt)) {
          $success = "Site / Project added successfully!";
          $_POST = [];
        } else {
          // cleanup uploaded file if DB insert failed
          if (!empty($contract_document_path) && file_exists($contract_document_path)) @unlink($contract_document_path);
          $error = "Error adding site: " . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
      } else {
        if (!empty($contract_document_path) && file_exists($contract_document_path)) @unlink($contract_document_path);
        $error = "Database error: " . mysqli_error($conn);
      }
    } else {
      // cleanup if validation added errors after upload
      if (!empty($contract_document_path) && file_exists($contract_document_path)) @unlink($contract_document_path);
    }
  } else {
    // cleanup upload if validation failed
    if (!empty($contract_document_path) && file_exists($contract_document_path)) @unlink($contract_document_path);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Add Site - AS Electricals</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Your Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .form-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 25px; margin-bottom: 30px; }
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
    .checkbox-grid { display:flex; flex-wrap:wrap; gap:12px; }
    .checkbox-pill { border: 1px solid #e2e8f0; padding: 10px 12px; border-radius: 12px; background:#fff; display:flex; align-items:center; gap:10px; }
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
            <h1 class="h3 fw-bold text-dark mb-1">Add New Site / Project</h1>
            <p class="text-muted mb-0">Select client and enter project + agreement details</p>
          </div>
          <a href="manage-sites.php" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Sites
          </a>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <strong>Success!</strong> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Error!</strong> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="siteForm" novalidate>

          <!-- Select Client -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon"><i class="bi bi-person-lines-fill"></i></div>
              <div>
                <h3 class="section-title">Select Client</h3>
   
              </div>
            </div>

            <div class="row g-4">
              <div class="col-12">
                <label for="client_id" class="form-label required-label">Client</label>
                <select class="form-select" id="client_id" name="client_id" required>
                  <option value="">Select client</option>
                  <?php foreach ($clients as $c): ?>
                    <?php
                      $label = $c['client_name'];
                      if (!empty($c['company_name'])) $label .= " — " . $c['company_name'];
                      if (!empty($c['mobile_number'])) $label .= " (" . $c['mobile_number'] . ")";
                    ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)($_POST['client_id'] ?? 0) === (int)$c['id']) ? 'selected' : ''; ?>>
                      <?php echo e($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-helper"><i class="bi bi-info-circle"></i> Client must exist in Client Master</div>
              </div>
            </div>
          </div>

          <!-- Project Details -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon" style="background:#4facfe;"><i class="bi bi-briefcase"></i></div>
              <div>
                <h3 class="section-title">Project Details</h3>
                <p class="section-subtitle">Scope and timeline</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <label for="project_name" class="form-label required-label">Project Name</label>
                <input type="text" class="form-control" id="project_name" name="project_name"
                       value="<?php echo e($_POST['project_name'] ?? ''); ?>" placeholder="Enter project name" required>
              </div>

              <div class="col-md-6">
                <label for="project_type" class="form-label required-label">Project Type</label>
                <select class="form-select" id="project_type" name="project_type" required>
                  <option value="">Select project type</option>
                  <?php foreach ($project_types as $pt): ?>
                    <option value="<?php echo e($pt); ?>" <?php echo (($_POST['project_type'] ?? '') === $pt) ? 'selected' : ''; ?>>
                      <?php echo e($pt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12">
                <label for="project_location" class="form-label required-label">Project Location</label>
                <input type="text" class="form-control" id="project_location" name="project_location"
                       value="<?php echo e($_POST['project_location'] ?? ''); ?>" placeholder="City / Area / Landmark" required>
              </div>

              <div class="col-12">
                <label class="form-label required-label">Scope of Work</label>
                <div class="checkbox-grid">
                  <?php
                    $selected_scopes = $_POST['scope_of_work'] ?? [];
                    if (!is_array($selected_scopes)) $selected_scopes = [];
                    foreach ($scope_of_work_options as $opt):
                      $checked = in_array($opt, $selected_scopes, true) ? 'checked' : '';
                  ?>
                    <label class="checkbox-pill">
                      <input class="form-check-input" type="checkbox" name="scope_of_work[]"
                             value="<?php echo e($opt); ?>" <?php echo $checked; ?>>
                      <span style="font-weight:700;color:#374151;"><?php echo e($opt); ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="form-helper"><i class="bi bi-check2-square"></i> Select at least one</div>
              </div>

              <div class="col-md-6">
                <label for="contract_value" class="form-label required-label">Contract Value</label>
                <input type="text" class="form-control" id="contract_value" name="contract_value"
                       value="<?php echo e($_POST['contract_value'] ?? ''); ?>" placeholder="2500000" required>
                <div class="form-helper"><i class="bi bi-currency-rupee"></i> Numbers only (decimals allowed)</div>
              </div>

              <div class="col-md-6">
                <label for="pmc_charges" class="form-label required-label">PMC Charges</label>
                <input type="text" class="form-control" id="pmc_charges" name="pmc_charges"
                       value="<?php echo e($_POST['pmc_charges'] ?? ''); ?>" placeholder="50000" required>
                <div class="form-helper"><i class="bi bi-currency-rupee"></i> Numbers only (decimals allowed)</div>
              </div>

              <div class="col-md-6">
                <label for="start_date" class="form-label required-label">Start Date</label>
                <input type="date" class="form-control" id="start_date" name="start_date"
                       value="<?php echo e($_POST['start_date'] ?? ''); ?>" required>
              </div>

              <div class="col-md-6">
                <label for="expected_completion_date" class="form-label required-label">Expected Completion Date</label>
                <input type="date" class="form-control" id="expected_completion_date" name="expected_completion_date"
                       value="<?php echo e($_POST['expected_completion_date'] ?? ''); ?>" required>
              </div>

              <div class="col-12">
                <label for="boq_details" class="form-label required-label">BOQ Details</label>
                <textarea class="form-control" id="boq_details" name="boq_details" rows="3"
                          placeholder="Enter BOQ details / notes" required><?php echo e($_POST['boq_details'] ?? ''); ?></textarea>
              </div>
            </div>
          </div>

          <!-- Contract & Agreement -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon" style="background:#fa709a;"><i class="bi bi-file-earmark-text"></i></div>
              <div>
                <h3 class="section-title">Contract & Agreement Details</h3>
                <p class="section-subtitle">Agreement numbers, dates, and document upload</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <label for="agreement_number" class="form-label required-label">Agreement / Contract Number</label>
                <input type="text" class="form-control" id="agreement_number" name="agreement_number"
                       value="<?php echo e($_POST['agreement_number'] ?? ''); ?>" placeholder="AGR-2026-001" required>
              </div>

              <div class="col-md-6">
                <label for="agreement_date" class="form-label required-label">Agreement Date</label>
                <input type="date" class="form-control" id="agreement_date" name="agreement_date"
                       value="<?php echo e($_POST['agreement_date'] ?? ''); ?>" required>
              </div>

              <div class="col-md-6">
                <label for="work_order_date" class="form-label required-label">Work Order Date</label>
                <input type="date" class="form-control" id="work_order_date" name="work_order_date"
                       value="<?php echo e($_POST['work_order_date'] ?? ''); ?>" required>
              </div>

              <div class="col-md-6">
                <label for="contract_document" class="form-label required-label">Contract Document (Upload)</label>
                <div class="file-upload-container" onclick="document.getElementById('contract_document').click()">
                  <div class="file-upload-icon"><i class="bi bi-upload"></i></div>
                  <div class="file-upload-text">Click to upload contract document</div>
                  <div class="file-upload-subtext">PDF, DOC, DOCX, JPG, PNG, WebP (Max 10MB)</div>
                  <input type="file" class="d-none" id="contract_document" name="contract_document"
                         accept=".pdf,.doc,.docx,image/*" required>
                </div>
                <div class="form-helper"><i class="bi bi-paperclip"></i> This file is mandatory</div>
              </div>
            </div>
          </div>

          <!-- Client Communication & Control -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon" style="background:#43e97b;"><i class="bi bi-person-check"></i></div>
              <div>
                <h3 class="section-title">Client Communication & Control</h3>
                <p class="section-subtitle">Authorized signatory and approval contacts</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <label for="authorized_signatory_name" class="form-label required-label">Authorized Signatory Name</label>
                <input type="text" class="form-control" id="authorized_signatory_name" name="authorized_signatory_name"
                       value="<?php echo e($_POST['authorized_signatory_name'] ?? ''); ?>" placeholder="Enter name" required>
              </div>

              <div class="col-md-6">
                <label for="authorized_signatory_contact" class="form-label required-label">Authorized Signatory Contact Details</label>
                <input type="tel" class="form-control" id="authorized_signatory_contact" name="authorized_signatory_contact"
                       value="<?php echo e($_POST['authorized_signatory_contact'] ?? ''); ?>" placeholder="9876543210" required>
                <div class="form-helper"><i class="bi bi-phone"></i> 10 to 15 digits</div>
              </div>

              <div class="col-md-6">
                <label for="contact_person_designation" class="form-label required-label">Contact Person Designation</label>
                <input type="text" class="form-control" id="contact_person_designation" name="contact_person_designation"
                       value="<?php echo e($_POST['contact_person_designation'] ?? ''); ?>" placeholder="Manager / Engineer" required>
              </div>

              <div class="col-md-6">
                <label for="contact_person_email" class="form-label required-label">Contact Person Email Address</label>
                <input type="email" class="form-control" id="contact_person_email" name="contact_person_email"
                       value="<?php echo e($_POST['contact_person_email'] ?? ''); ?>" placeholder="person@domain.com" required>
              </div>

              <div class="col-md-6">
                <label for="approval_authority" class="form-label required-label">Approval Authority</label>
                <input type="text" class="form-control" id="approval_authority" name="approval_authority"
                       value="<?php echo e($_POST['approval_authority'] ?? ''); ?>" placeholder="Name / Department who approves" required>
              </div>

              <div class="col-md-6">
                <label for="site_in_charge_client_side" class="form-label">
                  Site-in-charge (Client side) <span class="optional-badge">(Optional)</span>
                </label>
                <input type="text" class="form-control" id="site_in_charge_client_side" name="site_in_charge_client_side"
                       value="<?php echo e($_POST['site_in_charge_client_side'] ?? ''); ?>" placeholder="Enter site-in-charge name (optional)">
              </div>
            </div>
          </div>

          <!-- Submit -->
          <div class="text-center mt-5">
            <button type="submit" class="btn-submit">
              <i class="bi bi-plus-circle"></i> Add Site / Project
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
  const form = document.getElementById('siteForm');
  const setInvalid = (el) => el && el.classList.add('is-invalid');

  // Number-only fields (allow decimals)
  function onlyNumberDot(el){
    if (!el) return;
    el.addEventListener('input', function(){
      this.value = this.value.replace(/[^0-9.]/g,'');
    });
  }
  onlyNumberDot(document.getElementById('contract_value'));
  onlyNumberDot(document.getElementById('pmc_charges'));

  // Contact digits only
  const authMob = document.getElementById('authorized_signatory_contact');
  if (authMob) authMob.addEventListener('input', function(){
    this.value = this.value.replace(/\D/g,'').slice(0,15);
  });

  // Submit validation
  form.addEventListener('submit', function(e) {
    let valid = true;
    const errorMessages = [];

    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

    // Required fields
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
      if (field.type === 'checkbox') return;
      if (!String(field.value || '').trim()) {
        valid = false;
        setInvalid(field);
        const label = form.querySelector(`label[for="${field.id}"]`);
        const fieldName = label ? label.textContent.replace(' *', '').trim() : field.name;
        errorMessages.push(`${fieldName} is required`);
      }
    });

    // Scope of work at least one
    const scopeChecks = form.querySelectorAll('input[name="scope_of_work[]"]');
    const scopeSelected = Array.from(scopeChecks).some(cb => cb.checked);
    if (!scopeSelected) {
      valid = false;
      errorMessages.push('Scope of work is required (select at least one)');
    }

    // Email validation
    const cpEmail = document.getElementById('contact_person_email');
    if (cpEmail && cpEmail.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cpEmail.value)) {
      valid = false; setInvalid(cpEmail); errorMessages.push('Invalid Contact person email format');
    }

    // Contract value numeric
    const cv = document.getElementById('contract_value');
    if (cv && cv.value && !/^[0-9]+(\.[0-9]{1,2})?$/.test(cv.value)) {
      valid = false; setInvalid(cv); errorMessages.push('Contract value must be a valid number');
    }
    const pmc = document.getElementById('pmc_charges');
    if (pmc && pmc.value && !/^[0-9]+(\.[0-9]{1,2})?$/.test(pmc.value)) {
      valid = false; setInvalid(pmc); errorMessages.push('PMC charges must be a valid number');
    }

    // Dates logic
    const sd = document.getElementById('start_date');
    const ed = document.getElementById('expected_completion_date');
    if (sd && ed && sd.value && ed.value) {
      if (new Date(ed.value) < new Date(sd.value)) {
        valid = false; setInvalid(ed); errorMessages.push('Expected completion date cannot be earlier than start date');
      }
    }

    // Contact digits validation
    if (authMob && authMob.value && !/^[0-9]{10,15}$/.test(authMob.value)) {
      valid = false; setInvalid(authMob); errorMessages.push('Authorized signatory contact must be 10 to 15 digits');
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
            <ul class="mb-0 mt-2 ps-3">
              ${errorMessages.map(msg => `<li>${msg}</li>`).join('')}
            </ul>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
if (isset($conn)) mysqli_close($conn);
?>
