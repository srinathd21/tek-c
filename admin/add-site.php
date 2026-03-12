<?php


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

  // LOCATION FIELDS (NEW)
  $latitude = trim($_POST['latitude'] ?? '');
  $longitude = trim($_POST['longitude'] ?? '');
  $location_radius = (int)($_POST['location_radius'] ?? 100);
  $location_address = trim($_POST['location_address'] ?? '');
  $place_id = trim($_POST['place_id'] ?? '');

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

  // LOCATION VALIDATION (NEW)
  if ($latitude !== '' && !is_numeric($latitude)) {
    $validation_errors[] = "Latitude must be a valid number";
  }
  if ($longitude !== '' && !is_numeric($longitude)) {
    $validation_errors[] = "Longitude must be a valid number";
  }
  if ($location_radius < 10 || $location_radius > 1000) {
    $validation_errors[] = "Location radius must be between 10 and 1000 meters";
  }

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
        approval_authority, site_in_charge_client_side,
        latitude, longitude, location_radius, location_address, place_id
    ) VALUES (
        ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?,
        ?, ?, ?, ?,
        ?, ?,
        ?, ?,
        ?, ?,
        ?, ?, ?, ?, ?
    )";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            "issssdsdsdssssssssssddiss",
            $client_id,                      // i
            $project_name,                   // s
            $project_type,                   // s
            $project_location,               // s
            $scope_of_work_csv,              // s
            $contract_value,                 // d
            $start_date,                     // s
            $expected_completion_date,       // s
            $boq_details,                    // s
            $pmc_charges,                    // d
            $agreement_number,               // s
            $agreement_date,                 // s
            $work_order_date,                // s
            $contract_document_path,         // s
            $authorized_signatory_name,      // s
            $authorized_signatory_contact,   // s
            $contact_person_designation,     // s
            $contact_person_email,           // s
            $approval_authority,             // s
            $site_in_charge_client_side,     // s
            $latitude,                       // d
            $longitude,                      // d
            $location_radius,                // i
            $location_address,               // s
            $place_id                        // s
        );

        if (mysqli_stmt_execute($stmt)) {
            $success = "Site / Project added successfully!";
            $_POST = [];
        } else {
            if (!empty($contract_document_path) && file_exists($contract_document_path)) {
                @unlink($contract_document_path);
            }
            $error = "Error adding site: " . mysqli_stmt_error($stmt);
        }

        mysqli_stmt_close($stmt);

    } else {
        if (!empty($contract_document_path) && file_exists($contract_document_path)) {
            @unlink($contract_document_path);
        }
        $error = "Database error: " . mysqli_error($conn);
    }

} else {
    if (!empty($contract_document_path) && file_exists($contract_document_path)) {
        @unlink($contract_document_path);
    }
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

  <!-- Google Maps -->
  <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE&libraries=places,geometry&callback=initMap" async defer></script>

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
    
    /* Map styles */
    #locationMap { height: 350px; border-radius: 12px; border: 2px solid #e2e8f0; margin-top: 15px; margin-bottom: 15px; }
    .location-search-container { position: relative; margin-bottom: 15px; }
    .location-search-input { width: 100%; padding: 12px 45px 12px 15px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
    .location-search-input:focus { border-color: var(--blue); outline: none; box-shadow: 0 0 0 3px rgba(45,156,219,.1); }
    .location-search-icon { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #718096; }
    .get-location-btn { background: #fff; border: 2px solid #e2e8f0; border-radius: 10px; padding: 10px 15px; font-weight: 700; color: #4a5568; transition: all 0.3s; width: 100%; margin-bottom: 10px; }
    .get-location-btn:hover { background: var(--blue); color: #fff; border-color: var(--blue); }
    .get-location-btn i { margin-right: 8px; }
    .coordinates-row { background: #f8fafc; padding: 15px; border-radius: 12px; margin-top: 15px; }
    .suggestion-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #e2e8f0; }
    .suggestion-item:hover { background: #f0f4ff; }
    .suggestions-container { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 2px solid #e2e8f0; border-radius: 10px; max-height: 250px; overflow-y: auto; z-index: 1000; display: none; }
    
    @media (max-width: 768px) {
      .content-scroll { padding: 18px; }
      .form-panel { padding: 20px; }
      .section-header { flex-direction: column; text-align: center; }
      .section-icon { margin-right: 0; margin-bottom: 15px; }
    }
    @media (max-width: 768px) {
  .content-scroll {
    padding: 12px 10px 12px !important;   /* was 22px */
  }

  .container-fluid.maxw {
    padding-left: 6px !important;
    padding-right: 6px !important;
  }

  .panel {
    padding: 12px !important;
    margin-bottom: 12px;
    border-radius: 14px;
  }

  .sec-head {
    padding: 10px !important;
    border-radius: 12px;
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
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Add New Site / Project</h1>
            <p class="text-muted mb-0">Select client and enter project + agreement details with Google Maps location</p>
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
                <label for="project_location" class="form-label required-label">Project Location (City/Area)</label>
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

          <!-- Site Location with Google Maps -->
          <div class="form-panel">
            <div class="section-header">
              <div class="section-icon" style="background:#43e97b;"><i class="bi bi-geo-alt-fill"></i></div>
              <div>
                <h3 class="section-title">Site Location & Geo-fence</h3>
                <p class="section-subtitle">Search and select exact location using Google Maps</p>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-md-6">
                <button type="button" class="get-location-btn" onclick="getCurrentLocation()">
                  <i class="bi bi-crosshair2"></i> Use My Current Location
                </button>
              </div>
              <div class="col-md-6">
                <button type="button" class="get-location-btn" onclick="searchWithGoogle()">
                  <i class="bi bi-google"></i> Search on Google Maps
                </button>
              </div>

              <div class="col-12">
                <div class="location-search-container">
                  <input type="text" class="location-search-input" id="locationSearch" 
                         placeholder="Search for a place or address...">
                  <i class="bi bi-search location-search-icon"></i>
                  <div id="suggestions" class="suggestions-container"></div>
                </div>
              </div>

              <div class="col-12">
                <div id="locationMap"></div>
              </div>

              <div class="coordinates-row">
                <div class="row g-3">
                  <div class="col-md-3">
                    <label for="latitude" class="form-label">
                      Latitude
                    </label>
                    <input type="text" class="form-control" id="latitude" name="latitude" 
                           value="<?php echo e($_POST['latitude'] ?? ''); ?>" placeholder="12.9716" readonly>
                  </div>

                  <div class="col-md-3">
                    <label for="longitude" class="form-label">
                      Longitude
                    </label>
                    <input type="text" class="form-control" id="longitude" name="longitude" 
                           value="<?php echo e($_POST['longitude'] ?? ''); ?>" placeholder="77.5946" readonly>
                  </div>

                  <div class="col-md-3">
                    <label for="location_radius" class="form-label">
                      Geo-fence Radius (meters)
                    </label>
                    <input type="number" class="form-control" id="location_radius" name="location_radius" 
                           value="<?php echo e($_POST['location_radius'] ?? '100'); ?>" min="10" max="1000" step="10">
                    <div class="form-helper"><i class="bi bi-circle"></i> Punch-in radius</div>
                  </div>

                  <div class="col-md-3">
                    <label for="place_id" class="form-label">
                      Place ID
                    </label>
                    <input type="text" class="form-control" id="place_id" name="place_id" 
                           value="<?php echo e($_POST['place_id'] ?? ''); ?>" placeholder="Google Place ID" readonly>
                  </div>

                  <div class="col-12">
                    <label for="location_address" class="form-label">
                      Full Address
                    </label>
                    <textarea class="form-control" id="location_address" name="location_address" 
                              rows="2" placeholder="Complete site address"><?php echo e($_POST['location_address'] ?? ''); ?></textarea>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <div class="form-helper">
                  <i class="bi bi-info-circle"></i> 
                  Location coordinates are required for attendance system. Employees can only punch in when within the geo-fence radius.
                </div>
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
              <div class="section-icon" style="background:#fa709a;"><i class="bi bi-person-check"></i></div>
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
let map;
let marker;
let circle;
let geocoder;
let placesService;
let autocompleteService;
let infoWindow;

// Initialize Google Maps
function initMap() {
  // Default coordinates (India)
  const defaultLat = 20.5937;
  const defaultLng = 78.9629;
  
  // Get saved coordinates if any
  const savedLat = parseFloat(document.getElementById('latitude').value) || defaultLat;
  const savedLng = parseFloat(document.getElementById('longitude').value) || defaultLng;
  const savedRadius = parseInt(document.getElementById('location_radius').value) || 100;
  
  // Initialize services
  geocoder = new google.maps.Geocoder();
  
  // Initialize map
  map = new google.maps.Map(document.getElementById('locationMap'), {
    center: { lat: savedLat, lng: savedLng },
    zoom: 15,
    mapTypeControl: true,
    streetViewControl: true,
    fullscreenControl: true,
    mapTypeId: google.maps.MapTypeId.ROADMAP
  });
  
  // Initialize Places service
  placesService = new google.maps.places.PlacesService(map);
  autocompleteService = new google.maps.places.AutocompleteService();
  
  // Add marker if coordinates exist
  if (document.getElementById('latitude').value) {
    updateMarker(savedLat, savedLng, savedRadius);
  }
  
  // Add click event to map
  map.addListener('click', function(e) {
    updateMarker(e.latLng.lat(), e.latLng.lng());
    reverseGeocode(e.latLng.lat(), e.latLng.lng());
  });
  
  // Setup search input
  setupSearch();
}

// Update marker on map
function updateMarker(lat, lng, radius) {
  const position = { lat: parseFloat(lat), lng: parseFloat(lng) };
  
  // Remove existing marker and circle
  if (marker) marker.setMap(null);
  if (circle) circle.setMap(null);
  
  // Add new marker
  marker = new google.maps.Marker({
    position: position,
    map: map,
    draggable: true,
    animation: google.maps.Animation.DROP,
    title: 'Site Location'
  });
  
  // Make marker draggable
  marker.addListener('dragend', function() {
    const pos = marker.getPosition();
    updateFormFields(pos.lat(), pos.lng());
    reverseGeocode(pos.lat(), pos.lng());
    updateCircle(pos.lat(), pos.lng());
  });
  
  // Get radius value
  const radiusVal = radius || parseInt(document.getElementById('location_radius').value) || 100;
  
  // Add circle for geo-fence
  circle = new google.maps.Circle({
    map: map,
    center: position,
    radius: radiusVal,
    fillColor: '#43e97b',
    fillOpacity: 0.1,
    strokeColor: '#43e97b',
    strokeOpacity: 0.5,
    strokeWeight: 2
  });
  
  // Update form fields
  updateFormFields(lat, lng);
  
  // Center map
  map.setCenter(position);
}

// Update form fields
function updateFormFields(lat, lng) {
  document.getElementById('latitude').value = lat.toFixed(6);
  document.getElementById('longitude').value = lng.toFixed(6);
}

// Update circle radius
function updateCircle(lat, lng) {
  if (circle) {
    circle.setCenter({ lat: parseFloat(lat), lng: parseFloat(lng) });
  }
}

// Reverse geocoding to get address from coordinates
function reverseGeocode(lat, lng) {
  geocoder.geocode({ location: { lat: parseFloat(lat), lng: parseFloat(lng) } }, function(results, status) {
    if (status === 'OK') {
      if (results[0]) {
        document.getElementById('location_address').value = results[0].formatted_address;
        
        // Get place ID
        if (results[0].place_id) {
          document.getElementById('place_id').value = results[0].place_id;
        }
      }
    }
  });
}

// Get current location
function getCurrentLocation() {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      function(position) {
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;
        updateMarker(lat, lng);
        reverseGeocode(lat, lng);
      },
      function(error) {
        let errorMsg = 'Unable to get your location. ';
        switch(error.code) {
          case error.PERMISSION_DENIED:
            errorMsg += 'Please enable location services.';
            break;
          case error.POSITION_UNAVAILABLE:
            errorMsg += 'Location information unavailable.';
            break;
          case error.TIMEOUT:
            errorMsg += 'Location request timed out.';
            break;
        }
        alert(errorMsg);
      }
    );
  } else {
    alert('Geolocation is not supported by your browser');
  }
}

// Search with Google
function searchWithGoogle() {
  const input = document.getElementById('locationSearch');
  input.focus();
  input.placeholder = 'Type to search...';
}

// Setup search with autocomplete
function setupSearch() {
  const searchInput = document.getElementById('locationSearch');
  const suggestionsDiv = document.getElementById('suggestions');
  
  searchInput.addEventListener('input', function() {
    const query = this.value;
    if (query.length < 3) {
      suggestionsDiv.style.display = 'none';
      return;
    }
    
    autocompleteService.getPlacePredictions({
      input: query,
      componentRestrictions: { country: 'in' } // Restrict to India
    }, function(predictions, status) {
      if (status === google.maps.places.PlacesServiceStatus.OK && predictions) {
        suggestionsDiv.innerHTML = '';
        predictions.forEach(function(prediction) {
          const div = document.createElement('div');
          div.className = 'suggestion-item';
          div.innerHTML = `<i class="bi bi-geo-alt me-2"></i> ${prediction.description}`;
          div.onclick = function() {
            selectPlace(prediction.place_id, prediction.description);
            suggestionsDiv.style.display = 'none';
            searchInput.value = prediction.description;
          };
          suggestionsDiv.appendChild(div);
        });
        suggestionsDiv.style.display = 'block';
      } else {
        suggestionsDiv.style.display = 'none';
      }
    });
  });
  
  // Hide suggestions when clicking outside
  document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
      suggestionsDiv.style.display = 'none';
    }
  });
}

// Select place from autocomplete
function selectPlace(placeId, description) {
  placesService.getDetails({ placeId: placeId }, function(place, status) {
    if (status === google.maps.places.PlacesServiceStatus.OK) {
      const lat = place.geometry.location.lat();
      const lng = place.geometry.location.lng();
      
      updateMarker(lat, lng);
      document.getElementById('location_address').value = place.formatted_address || description;
      document.getElementById('place_id').value = placeId;
      
      // Get additional details if available
      if (place.address_components) {
        // You can extract city, state, etc. here if needed
      }
    }
  });
}

// Update circle when radius changes
document.getElementById('location_radius').addEventListener('input', function() {
  const lat = parseFloat(document.getElementById('latitude').value);
  const lng = parseFloat(document.getElementById('longitude').value);
  const radius = parseInt(this.value) || 100;
  
  if (lat && lng && circle) {
    circle.setRadius(radius);
  }
});

// Form validation (keeping your existing validation)
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