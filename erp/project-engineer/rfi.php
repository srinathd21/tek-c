<?php
// rfi.php — Request For Information (RFI) submit
// Assumptions:
// - You already have tables: rfi_reports, sites, clients, employees, site_project_engineers
// - No CREATE/ALTER statements included (as requested)
// - This file follows a similar access pattern to dpr.php
//
// Suggested rfi_reports columns (for reference only, not executed here):
// id, site_id, employee_id, rfi_no, issue_date, required_response_date,
// subject, architect_consultant, contractor, raised_by_name, raised_by_designation,
// drawing_no, drawing_date, specification_clause, boq_item_ref, site_instruction_ref,
// query_description, attachments_json, impacts_json, impact_brief, proposed_solution,
// response_date, response_by, response_decision, response_remarks,
// report_distribute_to, prepared_by, status, created_at, updated_at

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId  = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

$allowed = [
  'project engineer grade 1',
  'project engineer grade 2',
  'sr. engineer',
  'team lead',
  'manager',
];
if (!in_array($designation, $allowed, true)) {
  header("Location: index.php");
  exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ymdOrNull($v){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return null;
  return $v;
}
function valOrNull($v){
  $v = trim((string)$v);
  return $v === '' ? null : $v;
}
function generateNextRfiNo(mysqli $conn, int $siteId): string {
  $seq = 1;
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM rfi_reports WHERE site_id=?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $siteId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    $seq = ((int)($row['cnt'] ?? 0)) + 1;
  }
  return 'RFI-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, designation FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$preparedBy = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');
$preparedDesignation = $empRow['designation'] ?? ($_SESSION['designation'] ?? '');

// ---------------- Assigned Sites ----------------
$sites = [];

if ($designation === 'manager') {
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.manager_employee_id = ?
    ORDER BY s.created_at DESC
  ";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
} else {
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name
    FROM site_project_engineers spe
    INNER JOIN sites s ON s.id = spe.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE spe.employee_id = ?
    ORDER BY s.created_at DESC
  ";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
}

// ---------------- Selected Site ----------------
$siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$site = null;
$defaultDistribute = '';

if ($siteId > 0) {
  $isAllowedSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $siteId) { $isAllowedSite = true; break; }
  }

  if ($isAllowedSite) {
    $sql = "
      SELECT
        s.id, s.client_id, s.manager_employee_id,
        s.project_name, s.project_type, s.project_location, s.scope_of_work,
        s.start_date, s.expected_completion_date,
        c.client_name, c.company_name, c.state
      FROM sites s
      INNER JOIN clients c ON c.id = s.client_id
      WHERE s.id = ?
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
      mysqli_stmt_bind_param($st, "i", $siteId);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $site = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
    }

    if ($site) {
      $parts = [];
      $clientName = trim((string)($site['client_name'] ?? ''));
      $parts[] = $clientName !== '' ? ('Client - ' . $clientName) : 'Client';

      if (!empty($site['manager_employee_id'])) {
        $mid = (int)$site['manager_employee_id'];
        $stm = mysqli_prepare($conn, "SELECT full_name, email FROM employees WHERE id=? LIMIT 1");
        if ($stm) {
          mysqli_stmt_bind_param($stm, "i", $mid);
          mysqli_stmt_execute($stm);
          $rr = mysqli_stmt_get_result($stm);
          if ($m = mysqli_fetch_assoc($rr)) {
            $mLabel = trim((string)($m['email'] ?? ''));
            if ($mLabel === '') $mLabel = trim((string)($m['full_name'] ?? 'Manager'));
            if ($mLabel !== '') $parts[] = $mLabel;
          }
          mysqli_stmt_close($stm);
        }
      }

      $dir = [];
      $q2 = "SELECT full_name, email, designation
             FROM employees
             WHERE employee_status='active'
               AND designation IN ('Director','Vice President','General Manager')
             ORDER BY designation ASC, full_name ASC";
      $r = mysqli_query($conn, $q2);
      if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
          $label = trim((string)($row['email'] ?? ''));
          if ($label === '') $label = trim((string)($row['full_name'] ?? ''));
          if ($label !== '') $dir[] = $label;
        }
        mysqli_free_result($r);
      }
      foreach ($dir as $d) $parts[] = $d;

      $parts = array_values(array_unique(array_filter($parts, fn($x)=>trim((string)$x) !== '')));
      $defaultDistribute = implode(', ', $parts);
    }
  }
}

// ---------------- SUBMIT ----------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rfi'])) {
  $site_id = (int)($_POST['site_id'] ?? 0);

  // Validate site assignment
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $site_id) { $okSite = true; break; }
  }
  if (!$okSite) $error = "Invalid site selection.";

  // Main fields
  $project_name = trim((string)($_POST['project_name'] ?? '')); // display copy (optional)
  $project_location = trim((string)($_POST['project_location'] ?? '')); // display copy (optional)
  $client_name = trim((string)($_POST['client_name'] ?? '')); // display copy (optional)

  $architect_consultant = trim((string)($_POST['architect_consultant'] ?? ''));
  $contractor = trim((string)($_POST['contractor'] ?? ''));
  $rfi_no = trim((string)($_POST['rfi_no'] ?? ''));
  $issue_date = trim((string)($_POST['issue_date'] ?? ''));
  $required_response_date = trim((string)($_POST['required_response_date'] ?? ''));
  $raised_by_name = trim((string)($_POST['raised_by_name'] ?? ''));
  $raised_by_designation = trim((string)($_POST['raised_by_designation'] ?? ''));

  $subject = trim((string)($_POST['subject'] ?? ''));

  $drawing_no = trim((string)($_POST['drawing_no'] ?? ''));
  $drawing_date = trim((string)($_POST['drawing_date'] ?? ''));
  $specification_clause = trim((string)($_POST['specification_clause'] ?? ''));
  $boq_item_ref = trim((string)($_POST['boq_item_ref'] ?? ''));
  $site_instruction_ref = trim((string)($_POST['site_instruction_ref'] ?? ''));

  $query_description = trim((string)($_POST['query_description'] ?? ''));

  // Attachments checkboxes
  $attachments = [
    'site_photograph'   => !empty($_POST['att_site_photograph']) ? 1 : 0,
    'markedup_drawing'  => !empty($_POST['att_markedup_drawing']) ? 1 : 0,
    'sketch'            => !empty($_POST['att_sketch']) ? 1 : 0,
    'calculation_sheet' => !empty($_POST['att_calculation_sheet']) ? 1 : 0,
  ];

  // Impacts checkboxes
  $impacts = [
    'work_stoppage'     => !empty($_POST['impact_work_stoppage']) ? 1 : 0,
    'delay_in_schedule' => !empty($_POST['impact_delay_schedule']) ? 1 : 0,
    'cost_impact'       => !empty($_POST['impact_cost']) ? 1 : 0,
    'quality_risk'      => !empty($_POST['impact_quality']) ? 1 : 0,
    'safety_risk'       => !empty($_POST['impact_safety']) ? 1 : 0,
  ];
  $impact_brief = trim((string)($_POST['impact_brief'] ?? ''));

  $proposed_solution = trim((string)($_POST['proposed_solution'] ?? ''));

  // Response section (optional if filling later)
  $response_date = trim((string)($_POST['response_date'] ?? ''));
  $response_by = trim((string)($_POST['response_by'] ?? ''));
  $response_decision = trim((string)($_POST['response_decision'] ?? ''));
  $response_remarks = trim((string)($_POST['response_remarks'] ?? ''));

  $report_distribute_to = trim((string)($_POST['report_distribute_to'] ?? ''));
  $prepared_by = trim((string)($_POST['prepared_by'] ?? $preparedBy));

  // Validations
  if ($error === '' && $site_id <= 0) $error = "Please choose a site.";
  if ($error === '' && $rfi_no === '') $error = "RFI No is required.";
  if ($error === '' && $issue_date === '') $error = "Date of Issue is required.";
  if ($error === '' && $required_response_date === '') $error = "Required Response Date is required.";
  if ($error === '' && $raised_by_name === '') $error = "Raised By (Name) is required.";
  if ($error === '' && $raised_by_designation === '') $error = "Raised By (Designation) is required.";
  if ($error === '' && $subject === '') $error = "Subject is required.";
  if ($error === '' && $query_description === '') $error = "Description of Query is required.";
  if ($error === '' && $report_distribute_to === '') $error = "Report Distribute To is required.";

  // Optional business rule: required response date should not be before issue date
  if ($error === '' && $issue_date !== '' && $required_response_date !== '') {
    if (strtotime($required_response_date) < strtotime($issue_date)) {
      $error = "Required Response Date cannot be earlier than Date of Issue.";
    }
  }

  if ($error === '') {
    $attachments_json = json_encode($attachments, JSON_UNESCAPED_UNICODE);
    $impacts_json = json_encode($impacts, JSON_UNESCAPED_UNICODE);

    $issue_date_db = ymdOrNull($issue_date);
    $required_response_date_db = ymdOrNull($required_response_date);
    $drawing_date_db = ymdOrNull($drawing_date);
    $response_date_db = ymdOrNull($response_date);

    $status = ($response_decision !== '') ? 'Responded' : 'Open';

    // NOTE: project/client display copies are not inserted below; site_id is the source of truth.
    // Add these columns if you want to store snapshots.
    $ins = mysqli_prepare($conn, "
      INSERT INTO rfi_reports
      (
        site_id, employee_id, rfi_no, issue_date, required_response_date,
        subject, architect_consultant, contractor, raised_by_name, raised_by_designation,
        drawing_no, drawing_date, specification_clause, boq_item_ref, site_instruction_ref,
        query_description, attachments_json, impacts_json, impact_brief, proposed_solution,
        response_date, response_by, response_decision, response_remarks,
        report_distribute_to, prepared_by, status
      )
      VALUES
      (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "iisssssssssssssssssssssssss",
        $site_id, $employeeId, $rfi_no, $issue_date_db, $required_response_date_db,
        $subject, $architect_consultant, $contractor, $raised_by_name, $raised_by_designation,
        $drawing_no, $drawing_date_db, $specification_clause, $boq_item_ref, $site_instruction_ref,
        $query_description, $attachments_json, $impacts_json, $impact_brief, $proposed_solution,
        $response_date_db, $response_by, $response_decision, $response_remarks,
        $report_distribute_to, $prepared_by, $status
      );

      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to save RFI: " . mysqli_stmt_error($ins);
      } else {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        header("Location: rfi.php?site_id=".$site_id."&saved=1&rfi_id=".$newId);
        exit;
      }
      mysqli_stmt_close($ins);
    }
  }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "RFI submitted successfully.";
}

// ---------------- Recent RFIs ----------------
$recent = [];
$st = mysqli_prepare($conn, "
  SELECT r.id, r.rfi_no, r.issue_date, r.subject, r.status, s.project_name
  FROM rfi_reports r
  INNER JOIN sites s ON s.id = r.site_id
  WHERE r.employee_id = ?
  ORDER BY r.created_at DESC
  LIMIT 10
");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $recent = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- Form Defaults ----------------
$formSiteId = $siteId;
$formIssueDate = date('Y-m-d');
$formRequiredResponseDate = date('Y-m-d', strtotime('+3 days'));
$formRfiNo = ($formSiteId > 0) ? generateNextRfiNo($conn, $formSiteId) : '';
if ($defaultDistribute === '' && $site) {
  $cn = trim((string)($site['client_name'] ?? ''));
  $defaultDistribute = $cn !== '' ? ('Client - '.$cn.', Manager, Director') : "Client, Manager, Director";
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>RFI - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

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
    }
    .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
    }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

    .check-grid{
      display:grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap:10px 14px;
    }
    @media (max-width: 768px){
      .check-grid{ grid-template-columns: 1fr; }
    }
    .check-item{
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:10px 12px;
      background:#fff;
      display:flex;
      align-items:center;
      gap:10px;
      font-weight:800;
    }

    .table thead th{
      font-size: 12px;
      color:#6b7280;
      font-weight: 900;
      border-bottom:1px solid #e5e7eb !important;
      background:#f9fafb;
    }

    @media (max-width: 991.98px){
      .main{
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
      }
      .sidebar{
        position: fixed !important;
        transform: translateX(-100%);
        z-index: 1040 !important;
      }
      .sidebar.open, .sidebar.active, .sidebar.show{
        transform: translateX(0) !important;
      }
    }
    /* Reduce side space for cards on mobile */
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

        <div class="title-row mb-3">
          <div>
            <h1 class="h-title">Request For Information (RFI)</h1>
            <p class="h-sub">Create and submit project RFI for consultant / architect response</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($preparedBy); ?></span>
            <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($preparedDesignation); ?></span>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- SITE PICKER -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-geo-alt"></i></div>
            <div>
              <p class="sec-title mb-0">Project Selection</p>
              <p class="sec-sub mb-0">Choose site to auto-fill project details</p>
            </div>
          </div>

          <div class="grid-2">
            <div>
              <label class="form-label">My Assigned Sites <span class="text-danger">*</span></label>
              <select class="form-select" id="sitePicker">
                <option value="">-- Select Site --</option>
                <?php foreach ($sites as $s): ?>
                  <?php $sid = (int)$s['id']; ?>
                  <option value="<?php echo $sid; ?>" <?php echo ($sid === $formSiteId ? 'selected' : ''); ?>>
                    <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?> (<?php echo e($s['client_name']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="small-muted mt-1">Selecting a site will load project details and default RFI number.</div>
            </div>

            <div class="d-flex align-items-end justify-content-end">
              <a class="btn btn-outline-secondary" href="rfi.php" style="border-radius:12px; font-weight:900;">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </a>
            </div>
          </div>
        </div>

        <!-- PROJECT DETAILS PREVIEW -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-building"></i></div>
            <div>
              <p class="sec-title mb-0">Project Details</p>
              <p class="sec-sub mb-0">Auto-filled from selected site</p>
            </div>
          </div>

          <?php if (!$site): ?>
            <div class="text-muted" style="font-weight:800;">Please select a site above to load project details.</div>
          <?php else: ?>
            <div class="grid-3">
              <div><div class="small-muted">Project Name</div><div style="font-weight:1000;"><?php echo e($site['project_name']); ?></div></div>
              <div><div class="small-muted">Project Location</div><div style="font-weight:1000;"><?php echo e($site['project_location']); ?></div></div>
              <div><div class="small-muted">Client Name</div><div style="font-weight:1000;"><?php echo e($site['client_name']); ?></div></div>
            </div>
          <?php endif; ?>
        </div>

        <!-- RFI FORM -->
        <form method="POST" autocomplete="off">
          <input type="hidden" name="submit_rfi" value="1">
          <input type="hidden" name="site_id" value="<?php echo (int)$formSiteId; ?>">

          <!-- PROJECT DETAILS SECTION -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-diagram-3"></i></div>
              <div>
                <p class="sec-title mb-0">🏗 Project Details</p>
                <p class="sec-sub mb-0">Basic RFI header information</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">Project Name</label>
                <input class="form-control" name="project_name" value="<?php echo e($site['project_name'] ?? ''); ?>" readonly>
              </div>
              <div>
                <label class="form-label">Project Location</label>
                <input class="form-control" name="project_location" value="<?php echo e($site['project_location'] ?? ''); ?>" readonly>
              </div>
              <div>
                <label class="form-label">Client Name</label>
                <input class="form-control" name="client_name" value="<?php echo e($site['client_name'] ?? ''); ?>" readonly>
              </div>
            </div>

            <div class="grid-3 mt-2">
              <div>
                <label class="form-label">Architect / Consultant</label>
                <input class="form-control" name="architect_consultant" placeholder="Enter architect / consultant name">
              </div>
              <div>
                <label class="form-label">Contractor</label>
                <input class="form-control" name="contractor" placeholder="Enter contractor name/company">
              </div>
              <div>
                <label class="form-label">RFI No <span class="text-danger">*</span></label>
                <input class="form-control" name="rfi_no" value="<?php echo e($formRfiNo); ?>" required>
              </div>
            </div>

            <div class="grid-3 mt-2">
              <div>
                <label class="form-label">Date of Issue <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="issue_date" value="<?php echo e($formIssueDate); ?>" required>
              </div>
              <div>
                <label class="form-label">Required Response Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="required_response_date" value="<?php echo e($formRequiredResponseDate); ?>" required>
              </div>
              <div></div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">Raised By (Name) <span class="text-danger">*</span></label>
                <input class="form-control" name="raised_by_name" value="<?php echo e($preparedBy); ?>" required>
              </div>
              <div>
                <label class="form-label">Raised By (Designation) <span class="text-danger">*</span></label>
                <input class="form-control" name="raised_by_designation" value="<?php echo e($preparedDesignation); ?>" required>
              </div>
            </div>
          </div>

          <!-- SUBJECT -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-pin-angle"></i></div>
              <div>
                <p class="sec-title mb-0">📌 Subject</p>
                <p class="sec-sub mb-0">Short clear title (example: Clarification on footing size at Grid B3)</p>
              </div>
            </div>

            <label class="form-label">Subject <span class="text-danger">*</span></label>
            <input class="form-control" name="subject" placeholder="Enter short clear RFI subject" required>
          </div>

          <!-- REFERENCE DOCUMENTS -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-journal-text"></i></div>
              <div>
                <p class="sec-title mb-0">📖 Reference Documents</p>
                <p class="sec-sub mb-0">Mention drawing/specification/BOQ references</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">Drawing No</label>
                <input class="form-control" name="drawing_no" placeholder="e.g. S-104">
              </div>
              <div>
                <label class="form-label">Drawing Date</label>
                <input type="date" class="form-control" name="drawing_date">
              </div>
              <div>
                <label class="form-label">Specification Clause</label>
                <input class="form-control" name="specification_clause" placeholder="e.g. Clause 4.2.1">
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">BOQ Item Ref</label>
                <input class="form-control" name="boq_item_ref" placeholder="Enter BOQ reference">
              </div>
              <div>
                <label class="form-label">Site Instruction Ref (if any)</label>
                <input class="form-control" name="site_instruction_ref" placeholder="Enter SI reference">
              </div>
            </div>
          </div>

          <!-- DESCRIPTION OF QUERY -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-question-circle"></i></div>
              <div>
                <p class="sec-title mb-0">❓ Description of Query</p>
                <p class="sec-sub mb-0">Explain clearly and technically</p>
              </div>
            </div>

            <label class="form-label">Description <span class="text-danger">*</span></label>
            <textarea class="form-control" name="query_description" rows="7" required placeholder="Explain the issue, discrepancy, and exact clarification required..."></textarea>
          </div>

          <!-- ATTACHMENTS -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-paperclip"></i></div>
              <div>
                <p class="sec-title mb-0">📷 Attachments (If Applicable)</p>
                <p class="sec-sub mb-0">Tick the attachments being submitted with this RFI</p>
              </div>
            </div>

            <div class="check-grid">
              <label class="check-item"><input type="checkbox" name="att_site_photograph" value="1"> Site Photograph</label>
              <label class="check-item"><input type="checkbox" name="att_markedup_drawing" value="1"> Marked-up Drawing</label>
              <label class="check-item"><input type="checkbox" name="att_sketch" value="1"> Sketch</label>
              <label class="check-item"><input type="checkbox" name="att_calculation_sheet" value="1"> Calculation Sheet</label>
            </div>
            <div class="small-muted mt-2">This version stores selected attachment types only. Add file upload fields if you want actual files.</div>
          </div>

          <!-- IMPACT -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-hourglass-split"></i></div>
              <div>
                <p class="sec-title mb-0">⏳ Impact If Not Clarified</p>
                <p class="sec-sub mb-0">Select applicable impact categories and explain briefly</p>
              </div>
            </div>

            <div class="check-grid">
              <label class="check-item"><input type="checkbox" name="impact_work_stoppage" value="1"> Work Stoppage</label>
              <label class="check-item"><input type="checkbox" name="impact_delay_schedule" value="1"> Delay in Schedule</label>
              <label class="check-item"><input type="checkbox" name="impact_cost" value="1"> Cost Impact</label>
              <label class="check-item"><input type="checkbox" name="impact_quality" value="1"> Quality Risk</label>
              <label class="check-item"><input type="checkbox" name="impact_safety" value="1"> Safety Risk</label>
            </div>

            <div class="mt-2">
              <label class="form-label">Explain impact briefly</label>
              <textarea class="form-control" name="impact_brief" rows="3" placeholder="Explain likely delay / cost / quality / safety impact..."></textarea>
            </div>
          </div>

          <!-- PROPOSED SOLUTION -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-tools"></i></div>
              <div>
                <p class="sec-title mb-0">🛠 Proposed Solution (Optional – by Contractor / PMC)</p>
                <p class="sec-sub mb-0">Enter recommendation if any</p>
              </div>
            </div>

            <textarea class="form-control" name="proposed_solution" rows="4" placeholder="If any recommendation is proposed by contractor/PMC, mention here..."></textarea>
          </div>

          <!-- RESPONSE SECTION -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-reply"></i></div>
              <div>
                <p class="sec-title mb-0">📩 Response Section (To be filled by Consultant / Architect)</p>
                <p class="sec-sub mb-0">Optional during creation; can be updated later if you build edit flow</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">Response Date</label>
                <input type="date" class="form-control" name="response_date">
              </div>
              <div>
                <label class="form-label">Response By</label>
                <input class="form-control" name="response_by" placeholder="Name / company">
              </div>
              <div>
                <label class="form-label">Decision</label>
                <select class="form-select" name="response_decision">
                  <option value="">-- Select --</option>
                  <option value="Approved">Approved</option>
                  <option value="Approved with Comments">Approved with Comments</option>
                  <option value="Not Approved">Not Approved</option>
                  <option value="Revised Drawing to be Issued">Revised Drawing to be Issued</option>
                </select>
              </div>
            </div>

            <div class="mt-2">
              <label class="form-label">Response Remarks</label>
              <textarea class="form-control" name="response_remarks" rows="4" placeholder="Consultant / architect remarks..."></textarea>
            </div>
          </div>

          <!-- DISTRIBUTION -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-send"></i></div>
              <div>
                <p class="sec-title mb-0">Report Distribution</p>
                <p class="sec-sub mb-0">Default includes Client + Manager + Director (editable)</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">Report Distribute To <span class="text-danger">*</span></label>
                <textarea class="form-control" name="report_distribute_to" rows="2" required><?php echo e($defaultDistribute); ?></textarea>
                <div class="small-muted mt-1">You can edit this list.</div>
              </div>
              <div>
                <label class="form-label">Prepared By</label>
                <input class="form-control" name="prepared_by" value="<?php echo e($preparedBy); ?>" readonly>
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek" <?php echo ($formSiteId<=0 ? 'disabled' : ''); ?>>
                <i class="bi bi-check2-circle"></i> Submit RFI
              </button>
            </div>

            <?php if ($formSiteId<=0): ?>
              <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to enable submit.</div>
            <?php endif; ?>
          </div>
        </form>

        <!-- RECENT RFIs -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Recent RFI</p>
              <p class="sec-sub mb-0">Your last RFI submissions</p>
            </div>
          </div>

          <?php if (empty($recent)): ?>
            <div class="text-muted" style="font-weight:800;">No RFI submitted yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>RFI No</th>
                    <th>Issue Date</th>
                    <th>Project</th>
                    <th>Subject</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo e($r['rfi_no']); ?></td>
                      <td><?php echo e($r['issue_date']); ?></td>
                      <td><?php echo e($r['project_name']); ?></td>
                      <td><?php echo e($r['subject']); ?></td>
                      <td>
                        <span class="badge text-bg-<?php echo (strtolower((string)$r['status']) === 'responded' ? 'success' : 'warning'); ?>">
                          <?php echo e($r['status']); ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var picker = document.getElementById('sitePicker');
  if (picker) {
    picker.addEventListener('change', function(){
      var v = picker.value || '';
      window.location.href = v ? ('rfi.php?site_id=' + encodeURIComponent(v)) : 'rfi.php';
    });
  }
});
</script>
</body>
</html>