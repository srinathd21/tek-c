<?php
// mom.php — Minutes of Meeting submit (Project Engineer / Team Lead / Manager allowed)

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

function jsonCleanRows(array $rows): array {
  $out = [];
  foreach ($rows as $r) {
    $has = false;
    foreach ($r as $v) {
      if (trim((string)$v) !== '') { $has = true; break; }
    }
    if ($has) $out[] = $r;
  }
  return $out;
}

function ymdOrNull($v){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return null;
  return $v;
}

function fmtDate($ymd) {
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '—';
  $ts = strtotime($ymd);
  return $ts ? date('d M Y', $ts) : $ymd;
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

// ---------------- Create MOM Table if Not Exists ----------------
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS mom_reports (
  id INT(11) NOT NULL AUTO_INCREMENT,
  site_id INT(11) NOT NULL,
  employee_id INT(11) NOT NULL,

  mom_no VARCHAR(80) NOT NULL,
  mom_date DATE NOT NULL,

  architects VARCHAR(190) NULL,

  meeting_conducted_by VARCHAR(190) NOT NULL,
  meeting_held_at VARCHAR(190) NOT NULL,
  meeting_time VARCHAR(20) NOT NULL,

  agenda_json LONGTEXT NULL,
  attendees_json LONGTEXT NULL,
  minutes_json LONGTEXT NULL,
  amended_json LONGTEXT NULL,

  mom_shared_to VARCHAR(190) NOT NULL,
  mom_copy_to LONGTEXT NULL,

  mom_shared_by VARCHAR(190) NOT NULL,
  mom_shared_on DATE NOT NULL,

  next_meeting_date DATE NULL,
  next_meeting_place VARCHAR(190) NULL,

  prepared_by VARCHAR(150) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),

  PRIMARY KEY (id),
  UNIQUE KEY uk_mom_no_site (site_id, mom_no),
  KEY idx_mom_site (site_id),
  KEY idx_mom_employee (employee_id),
  KEY idx_mom_date (mom_date),

  CONSTRAINT fk_mom_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_mom_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

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
} elseif ($designation === 'team lead') {
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.team_lead_employee_id = ?
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

if ($siteId > 0) {
  $isAllowedSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $siteId) { $isAllowedSite = true; break; }
  }

  if ($isAllowedSite) {
    $sql = "
      SELECT
        s.id, s.client_id,
        s.project_name, s.project_location, s.project_type, s.scope_of_work,
        s.start_date, s.expected_completion_date,
        c.client_name, c.company_name
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
  }
}

// ---------------- Default MOM No ----------------
$todayYmd = date('Y-m-d');
$defaultMomNo = '';
if ($siteId > 0) {
  $seq = 1;
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM mom_reports WHERE site_id=?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $siteId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    $seq = ((int)($row['cnt'] ?? 0)) + 1;
  }
  $defaultMomNo = 'MOM-' . $siteId . '-' . date('Ymd') . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

// ---------------- Get MOM for editing ----------------
$editMode = false;
$editData = null;
$momId = isset($_GET['mom_id']) ? (int)$_GET['mom_id'] : 0;

if ($momId > 0 && $siteId > 0) {
  $st = mysqli_prepare($conn, "
    SELECT * FROM mom_reports 
    WHERE id = ? AND site_id = ? AND employee_id = ?
  ");
  if ($st) {
    mysqli_stmt_bind_param($st, "iii", $momId, $siteId, $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $editData = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    
    if ($editData) {
      $editMode = true;
      
      // Decode JSON data
      $agendaRows = json_decode($editData['agenda_json'], true) ?? [];
      $attendeesRows = json_decode($editData['attendees_json'], true) ?? [];
      $minutesRows = json_decode($editData['minutes_json'], true) ?? [];
      $amendedRows = json_decode($editData['amended_json'], true) ?? [];
    }
  }
}

// ---------------- SUBMIT ----------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_mom'])) {

  $site_id = (int)($_POST['site_id'] ?? 0);
  $mom_id = isset($_POST['mom_id']) ? (int)$_POST['mom_id'] : 0;

  // Validate site assigned
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $site_id) { $okSite = true; break; }
  }
  if (!$okSite) $error = "Invalid site selection.";

  $mom_no   = trim((string)($_POST['mom_no'] ?? ''));
  $mom_date = trim((string)($_POST['mom_date'] ?? ''));

  $architects = trim((string)($_POST['architects'] ?? ''));

  $meeting_conducted_by = trim((string)($_POST['meeting_conducted_by'] ?? ''));
  $meeting_held_at      = trim((string)($_POST['meeting_held_at'] ?? ''));
  $meeting_time         = trim((string)($_POST['meeting_time'] ?? ''));

  $mom_shared_to = trim((string)($_POST['mom_shared_to'] ?? ''));
  $mom_copy_to   = trim((string)($_POST['mom_copy_to'] ?? ''));

  $mom_shared_by = trim((string)($_POST['mom_shared_by'] ?? ''));
  $mom_shared_on = trim((string)($_POST['mom_shared_on'] ?? ''));

  $next_meeting_date  = trim((string)($_POST['next_meeting_date'] ?? ''));
  $next_meeting_place = trim((string)($_POST['next_meeting_place'] ?? ''));

  if ($error === '' && $site_id <= 0) $error = "Please choose a site.";
  if ($error === '' && $mom_no === '') $error = "MOM No is required.";
  if ($error === '' && $mom_date === '') $error = "Meeting Date is required.";
  if ($error === '' && $meeting_conducted_by === '') $error = "Meeting Conducted by is required.";
  if ($error === '' && $meeting_held_at === '') $error = "Meeting Held at is required.";
  if ($error === '' && $meeting_time === '') $error = "Meeting Time is required.";
  if ($error === '' && $mom_shared_to === '') $error = "MOM Shared To is required.";
  if ($error === '' && $mom_shared_by === '') $error = "MOM Shared By is required.";
  if ($error === '' && $mom_shared_on === '') $error = "MOM Shared On is required.";

  // Agenda rows
  $agendaRows = [];
  $ag = $_POST['agenda_item'] ?? [];
  $max = count($ag);
  for ($i=0; $i<$max; $i++){
    if (trim($ag[$i] ?? '') !== '') {
      $agendaRows[] = ['item' => $ag[$i]];
    }
  }

  // Attendees rows
  $attRows = [];
  $stk = $_POST['att_stakeholder'] ?? [];
  $nm  = $_POST['att_name'] ?? [];
  $des = $_POST['att_designation'] ?? [];
  $frm = $_POST['att_firm'] ?? [];
  $max = max(count($stk), count($nm), count($des), count($frm));
  for ($i=0; $i<$max; $i++){
    if (trim($stk[$i] ?? '') !== '' || trim($nm[$i] ?? '') !== '') {
      $attRows[] = [
        'stakeholder' => $stk[$i] ?? '',
        'name' => $nm[$i] ?? '',
        'designation' => $des[$i] ?? '',
        'firm' => $frm[$i] ?? '',
      ];
    }
  }

  // Minutes rows
  $minRows = [];
  $disc = $_POST['min_discussion'] ?? [];
  $resp = $_POST['min_responsible'] ?? [];
  $dead = $_POST['min_deadline'] ?? [];
  $max = max(count($disc), count($resp), count($dead));
  for ($i=0; $i<$max; $i++){
    if (trim($disc[$i] ?? '') !== '') {
      $minRows[] = [
        'discussion' => $disc[$i] ?? '',
        'responsible_by' => $resp[$i] ?? '',
        'deadline' => $dead[$i] ?? '',
      ];
    }
  }

  if ($error === '' && empty($minRows)) {
    $error = "Please enter at least one Minutes of Discussion row.";
  }

  // Amended rows
  $amdRows = [];
  $adisc = $_POST['amd_discussion'] ?? [];
  $aresp = $_POST['amd_responsible'] ?? [];
  $adead = $_POST['amd_deadline'] ?? [];
  $max = max(count($adisc), count($aresp), count($adead));
  for ($i=0; $i<$max; $i++){
    if (trim($adisc[$i] ?? '') !== '') {
      $amdRows[] = [
        'discussion' => $adisc[$i] ?? '',
        'responsible_by' => $aresp[$i] ?? '',
        'deadline' => $adead[$i] ?? '',
      ];
    }
  }

  if ($error === '') {
    $agenda_json   = !empty($agendaRows) ? json_encode($agendaRows, JSON_UNESCAPED_UNICODE) : null;
    $attendees_json= !empty($attRows) ? json_encode($attRows, JSON_UNESCAPED_UNICODE) : null;
    $minutes_json  = !empty($minRows) ? json_encode($minRows, JSON_UNESCAPED_UNICODE) : null;
    $amended_json  = !empty($amdRows) ? json_encode($amdRows, JSON_UNESCAPED_UNICODE) : null;

    $nmd = ymdOrNull($next_meeting_date);
    $mso = ymdOrNull($mom_shared_on);

    if ($mom_id > 0 && $editMode) {
      // Update existing MOM
      $upd = mysqli_prepare($conn, "
        UPDATE mom_reports SET
          mom_no = ?, mom_date = ?,
          architects = ?,
          meeting_conducted_by = ?, meeting_held_at = ?, meeting_time = ?,
          agenda_json = ?, attendees_json = ?, minutes_json = ?, amended_json = ?,
          mom_shared_to = ?, mom_copy_to = ?,
          mom_shared_by = ?, mom_shared_on = ?,
          next_meeting_date = ?, next_meeting_place = ?
        WHERE id = ? AND site_id = ? AND employee_id = ?
      ");
      if (!$upd) {
        $error = "DB Error: " . mysqli_error($conn);
      } else {
        mysqli_stmt_bind_param(
          $upd,
          "sssssssssssssssiiii",
          $mom_no, $mom_date,
          $architects,
          $meeting_conducted_by, $meeting_held_at, $meeting_time,
          $agenda_json, $attendees_json, $minutes_json, $amended_json,
          $mom_shared_to, $mom_copy_to,
          $mom_shared_by, $mso,
          $nmd, $next_meeting_place,
          $mom_id, $site_id, $employeeId
        );
        if (!mysqli_stmt_execute($upd)) {
          $error = "Failed to update MOM: " . mysqli_stmt_error($upd);
        } else {
          mysqli_stmt_close($upd);
          header("Location: mom.php?site_id=".$site_id."&saved=1&mom_id=".$mom_id);
          exit;
        }
        mysqli_stmt_close($upd);
      }
    } else {
      // Insert new MOM
      $ins = mysqli_prepare($conn, "
        INSERT INTO mom_reports
        (site_id, employee_id, mom_no, mom_date,
         architects,
         meeting_conducted_by, meeting_held_at, meeting_time,
         agenda_json, attendees_json, minutes_json, amended_json,
         mom_shared_to, mom_copy_to,
         mom_shared_by, mom_shared_on,
         next_meeting_date, next_meeting_place,
         prepared_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      if (!$ins) {
        $error = "DB Error: " . mysqli_error($conn);
      } else {
        mysqli_stmt_bind_param(
          $ins,
          "iisssssssssssssssss",
          $site_id, $employeeId, $mom_no, $mom_date,
          $architects,
          $meeting_conducted_by, $meeting_held_at, $meeting_time,
          $agenda_json, $attendees_json, $minutes_json, $amended_json,
          $mom_shared_to, $mom_copy_to,
          $mom_shared_by, $mso,
          $nmd, $next_meeting_place,
          $preparedBy
        );
        if (!mysqli_stmt_execute($ins)) {
          $error = "Failed to save MOM: " . mysqli_stmt_error($ins);
        } else {
          $newId = mysqli_insert_id($conn);
          mysqli_stmt_close($ins);
          header("Location: mom.php?site_id=".$site_id."&saved=1&mom_id=".$newId);
          exit;
        }
        mysqli_stmt_close($ins);
      }
    }
  }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = $editMode ? "MOM updated successfully." : "MOM submitted successfully.";
}

// Recent MOMs
$recent = [];
if ($siteId > 0) {
  $st = mysqli_prepare($conn, "
    SELECT r.id, r.mom_no, r.mom_date, s.project_name
    FROM mom_reports r
    INNER JOIN sites s ON s.id = r.site_id
    WHERE r.employee_id = ? AND r.site_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
  ");
  if ($st) {
    mysqli_stmt_bind_param($st, "ii", $employeeId, $siteId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $recent = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
} else {
  $st = mysqli_prepare($conn, "
    SELECT r.id, r.mom_no, r.mom_date, s.project_name
    FROM mom_reports r
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
}

// ---------------- Form Defaults ----------------
$formSiteId = $siteId;
$formMomNo  = $editMode ? $editData['mom_no'] : $defaultMomNo;
$formMomDate = $editMode ? $editData['mom_date'] : date('Y-m-d');

$defaultPmc = "M/s. UKB Construction Management Pvt Ltd";
$defaultConductedBy = $editMode ? $editData['meeting_conducted_by'] : $preparedBy;
$defaultHeldAt = $editMode ? $editData['meeting_held_at'] : ($site ? ($site['project_location'] ?? '') : '');
$defaultTime = $editMode ? $editData['meeting_time'] : "";
$defaultArchitects = $editMode ? $editData['architects'] : "";
$defaultSharedTo = $editMode ? $editData['mom_shared_to'] : "All Attendees";
$defaultCopyTo = $editMode ? $editData['mom_copy_to'] : "";
$defaultSharedBy = $editMode ? $editData['mom_shared_by'] : $preparedBy;
$defaultSharedOn = $editMode ? $editData['mom_shared_on'] : date('Y-m-d');
$defaultNextDate = $editMode ? $editData['next_meeting_date'] : "";
$defaultNextPlace = $editMode ? $editData['next_meeting_place'] : "";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo $editMode ? 'Edit' : 'Create'; ?> MOM - TEK-C</title>

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
    @media (max-width: 992px){
      .grid-2, .grid-3{ grid-template-columns: 1fr; }
    }

    .table thead th{
      font-size: 12px;
      color:#6b7280;
      font-weight: 900;
      border-bottom:1px solid #e5e7eb !important;
      background:#f9fafb;
      white-space: nowrap;
    }
    .table td{
      vertical-align: middle;
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
    .btn-primary-tek:disabled{ opacity:0.6; cursor:not-allowed; }
    
    .btn-addrow{
      border-radius: 12px;
      font-weight: 900;
      padding: 8px 16px;
    }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
    }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
    
    .info-box{
      background:#f8fafc;
      border-radius: 12px;
      padding:12px;
      border:1px solid #eef2f7;
    }
    
    .required-field::after{
      content: " *";
      color: #dc3545;
      font-weight: 900;
    }
    
    .action-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      border: 1px solid #e5e7eb;
      background: #fff;
      color: #6b7280;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin: 0 2px;
      text-decoration: none;
    }
    .action-btn:hover {
      background: #f3f4f6;
      color: #374151;
    }
    
    .view-mom-link {
      color: var(--blue);
      text-decoration: none;
      font-weight: 800;
    }
    .view-mom-link:hover {
      text-decoration: underline;
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
            <h1 class="h-title"><?php echo $editMode ? 'Edit' : 'Minutes of Meeting'; ?> (MOM)</h1>
            <p class="h-sub"><?php echo $editMode ? 'Update existing' : 'Create new'; ?> MOM for the selected project site</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($preparedBy); ?></span>
            <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($empRow['designation'] ?? ($_SESSION['designation'] ?? '')); ?></span>
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
              <p class="sec-sub mb-0">Choose the site to prepare MOM</p>
            </div>
          </div>

          <div class="grid-2">
            <div>
              <label class="form-label">My Assigned Sites <span class="text-danger">*</span></label>
              <select class="form-select" id="sitePicker" <?php echo $editMode ? 'disabled' : ''; ?>>
                <option value="">-- Select Site --</option>
                <?php foreach ($sites as $s): ?>
                  <?php $sid = (int)$s['id']; ?>
                  <option value="<?php echo $sid; ?>" <?php echo ($sid === $formSiteId ? 'selected' : ''); ?>>
                    <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?> (<?php echo e($s['client_name']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($editMode): ?>
                <input type="hidden" name="site_id" value="<?php echo $formSiteId; ?>">
                <div class="small-muted mt-1">Site cannot be changed while editing</div>
              <?php else: ?>
                <div class="small-muted mt-1">Selecting a site will load project details.</div>
              <?php endif; ?>
            </div>

            <div class="d-flex align-items-end justify-content-end">
              <a class="btn btn-outline-secondary" href="mom.php" style="border-radius:12px; font-weight:900;">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </a>
            </div>
          </div>
        </div>

        <!-- MOM FORM -->
        <form method="POST" autocomplete="off" id="momForm">
          <input type="hidden" name="submit_mom" value="1">
          <input type="hidden" name="site_id" value="<?php echo (int)$formSiteId; ?>">
          <?php if ($editMode): ?>
            <input type="hidden" name="mom_id" value="<?php echo $momId; ?>">
          <?php endif; ?>

          <!-- PROJECT INFORMATION -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-building"></i></div>
              <div>
                <p class="sec-title mb-0">Project Information</p>
                <p class="sec-sub mb-0">Auto-filled from selected site</p>
              </div>
            </div>

            <?php if (!$site && !$editMode): ?>
              <div class="text-muted" style="font-weight:800;">Please select a site above to load project information.</div>
            <?php else: ?>
              <div class="grid-2">
                <div class="info-box">
                  <div class="small-muted">Project</div>
                  <div style="font-weight:1000; font-size:16px;"><?php echo e($site['project_name'] ?? ($editData ? 'Site info loaded' : '')); ?></div>
                </div>
                <div class="info-box">
                  <div class="small-muted">PMC</div>
                  <div style="font-weight:1000;"><?php echo e($defaultPmc); ?></div>
                </div>
              </div>

              <hr style="border-color:#eef2f7;">

              <div class="grid-2">
                <div class="info-box">
                  <div class="small-muted">Client</div>
                  <div style="font-weight:1000;"><?php echo e($site['client_name'] ?? ''); ?></div>
                </div>
                <div>
                  <label class="form-label">Architects</label>
                  <input class="form-control" name="architects" placeholder="Enter architects (if any)" value="<?php echo e($defaultArchitects); ?>">
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- MOM HEADER -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-file-earmark-text"></i></div>
              <div>
                <p class="sec-title mb-0">MOM Header</p>
                <p class="sec-sub mb-0">MOM No + Meeting Date</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label required-field">MOM No</label>
                <input class="form-control" name="mom_no" value="<?php echo e($formMomNo); ?>" required <?php echo $editMode ? 'readonly' : ''; ?>>
              </div>
              <div>
                <label class="form-label required-field">Meeting Date</label>
                <input type="date" class="form-control" name="mom_date" value="<?php echo e($formMomDate); ?>" required>
              </div>
              <div>
                <label class="form-label">Prepared By</label>
                <input class="form-control" value="<?php echo e($preparedBy); ?>" readonly>
              </div>
            </div>
          </div>

          <!-- MEETING INFORMATION -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-person-video3"></i></div>
              <div>
                <p class="sec-title mb-0">Meeting Information</p>
                <p class="sec-sub mb-0">Basic meeting details</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label required-field">Meeting Conducted by</label>
                <input class="form-control" name="meeting_conducted_by" value="<?php echo e($defaultConductedBy); ?>" required>
              </div>
              <div>
                <label class="form-label required-field">Meeting Held at</label>
                <input class="form-control" name="meeting_held_at" value="<?php echo e($defaultHeldAt); ?>" required>
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label required-field">Time</label>
                <input class="form-control" name="meeting_time" placeholder="e.g. 10:30 AM" value="<?php echo e($defaultTime); ?>" required>
              </div>
              <div class="small-muted d-flex align-items-end">
                Tip: you can type any format like “10:30 AM” or “15:00”.
              </div>
            </div>
          </div>

          <!-- MEETING AGENDA -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-list-ol"></i></div>
                <div>
                  <p class="sec-title mb-0">Meeting Agenda</p>
                  <p class="sec-sub mb-0">Add agenda points</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addAgenda">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr><th style="width:90px;">#</th><th>Agenda Item</th><th style="width:70px;">Del</th></tr>
                </thead>
                <tbody id="agendaBody">
                  <?php if ($editMode && !empty($agendaRows)): ?>
                    <?php foreach ($agendaRows as $index => $row): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo $index + 1; ?></td>
                      <td><input class="form-control" name="agenda_item[]" value="<?php echo e($row['item'] ?? ''); ?>"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td style="font-weight:1000;">1</td>
                      <td><input class="form-control" name="agenda_item[]"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                    <tr>
                      <td style="font-weight:1000;">2</td>
                      <td><input class="form-control" name="agenda_item[]"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- MEETING ATTENDEES -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-people"></i></div>
                <div>
                  <p class="sec-title mb-0">Meeting Attendees</p>
                  <p class="sec-sub mb-0">Stakeholders list</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addAttendee">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width:160px;">Stakeholders</th>
                    <th>Name</th>
                    <th style="width:180px;">Designation</th>
                    <th style="width:260px;">Firm</th>
                    <th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="attendeeBody">
                  <?php if ($editMode && !empty($attendeesRows)): ?>
                    <?php foreach ($attendeesRows as $row): ?>
                    <tr>
                      <td>
                        <select class="form-select" name="att_stakeholder[]">
                          <option value="">-- Select --</option>
                          <option value="Client" <?php echo ($row['stakeholder'] == 'Client') ? 'selected' : ''; ?>>Client</option>
                          <option value="PMC" <?php echo ($row['stakeholder'] == 'PMC') ? 'selected' : ''; ?>>PMC</option>
                          <option value="Architect" <?php echo ($row['stakeholder'] == 'Architect') ? 'selected' : ''; ?>>Architect</option>
                          <option value="Contractor" <?php echo ($row['stakeholder'] == 'Contractor') ? 'selected' : ''; ?>>Contractor</option>
                          <option value="Vendor" <?php echo ($row['stakeholder'] == 'Vendor') ? 'selected' : ''; ?>>Vendor</option>
                          <option value="Other" <?php echo ($row['stakeholder'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                      </td>
                      <td><input class="form-control" name="att_name[]" value="<?php echo e($row['name'] ?? ''); ?>"></td>
                      <td><input class="form-control" name="att_designation[]" value="<?php echo e($row['designation'] ?? ''); ?>"></td>
                      <td><input class="form-control" name="att_firm[]" value="<?php echo e($row['firm'] ?? ''); ?>" placeholder="e.g. M/s. UKB Construction Management Pvt Ltd"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td>
                        <select class="form-select" name="att_stakeholder[]">
                          <option value="">-- Select --</option>
                          <option value="Client">Client</option>
                          <option value="PMC">PMC</option>
                          <option value="Architect">Architect</option>
                          <option value="Contractor">Contractor</option>
                          <option value="Vendor">Vendor</option>
                          <option value="Other">Other</option>
                        </select>
                      </td>
                      <td><input class="form-control" name="att_name[]"></td>
                      <td><input class="form-control" name="att_designation[]"></td>
                      <td><input class="form-control" name="att_firm[]" placeholder="e.g. M/s. UKB Construction Management Pvt Ltd"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="small-muted mt-2">
              You can enter multiple PMC entries if needed (as in your format).
            </div>
          </div>

          <!-- MINUTES OF DISCUSSIONS -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-journal-text"></i></div>
                <div>
                  <p class="sec-title mb-0">Minutes of Discussions</p>
                  <p class="sec-sub mb-0">Enter at least one row <span class="text-danger">*</span></p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addMinute">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width:90px;">Sl.No.</th>
                    <th>Discussions / Decisions</th>
                    <th style="width:200px;">Responsible by</th>
                    <th style="width:160px;">Deadline</th>
                    <th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="minutesBody">
                  <?php if ($editMode && !empty($minutesRows)): ?>
                    <?php foreach ($minutesRows as $index => $row): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo $index + 1; ?></td>
                      <td><input class="form-control" name="min_discussion[]" value="<?php echo e($row['discussion'] ?? ''); ?>"></td>
                      <td><input class="form-control" name="min_responsible[]" value="<?php echo e($row['responsible_by'] ?? ''); ?>"></td>
                      <td><input class="form-control" name="min_deadline[]" value="<?php echo e($row['deadline'] ?? ''); ?>" placeholder="e.g. ASAP / 2026-02-20"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <?php for ($i=1; $i<=4; $i++): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo $i; ?></td>
                      <td><input class="form-control" name="min_discussion[]"></td>
                      <td><input class="form-control" name="min_responsible[]"></td>
                      <td><input class="form-control" name="min_deadline[]" placeholder="e.g. ASAP / 2026-02-20"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                    <?php endfor; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- MOM SHARED TO -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-send"></i></div>
              <div>
                <p class="sec-title mb-0">MOM Shared To</p>
                <p class="sec-sub mb-0">Attendees / Copy to</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label required-field">Attendees</label>
                <input class="form-control" name="mom_shared_to" value="<?php echo e($defaultSharedTo); ?>" required>
              </div>
              <div>
                <label class="form-label">Copy to</label>
                <input class="form-control" name="mom_copy_to" value="<?php echo e($defaultCopyTo); ?>" placeholder="Comma separated (optional)">
              </div>
            </div>
          </div>

          <!-- MOM SHARED BY -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-person-check"></i></div>
              <div>
                <p class="sec-title mb-0">MOM Shared By</p>
                <p class="sec-sub mb-0">Sender details</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label required-field">Shared by</label>
                <input class="form-control" name="mom_shared_by" value="<?php echo e($defaultSharedBy); ?>" required>
              </div>
              <div>
                <label class="form-label required-field">Shared on</label>
                <input type="date" class="form-control" name="mom_shared_on" value="<?php echo e($defaultSharedOn); ?>" required>
              </div>
            </div>
          </div>

          <!-- MOM SHORT-FORMS -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-info-circle"></i></div>
              <div>
                <p class="sec-title mb-0">MOM Short-Forms</p>
                <p class="sec-sub mb-0">Reference</p>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead><tr><th style="width:140px;">Code</th><th>Meaning</th></tr></thead>
                <tbody>
                  <tr><td style="font-weight:1000;">INFO</td><td>Information</td></tr>
                  <tr><td style="font-weight:1000;">IMM</td><td>Immediately</td></tr>
                  <tr><td style="font-weight:1000;">ASAP</td><td>As Soon As Possible</td></tr>
                  <tr><td style="font-weight:1000;">TBF</td><td>To be Followed</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- AMENDED POINTS -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-pencil-square"></i></div>
                <div>
                  <p class="sec-title mb-0">Amended Points (If missed points)</p>
                  <p class="sec-sub mb-0">Optional</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addAmended">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width:90px;">Sl.No.</th>
                    <th>Discussions / Decisions</th>
                    <th style="width:200px;">Responsible by</th>
                    <th style="width:160px;">Deadline</th>
                    <th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="amendedBody">
                  <?php if ($editMode && !empty($amendedRows)): ?>
                    <?php foreach ($amendedRows as $index => $row): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo $index + 1; ?></td>
                      <td><input class="form-control" name="amd_discussion[]" value="<?php echo e($row['discussion'] ?? ''); ?>"></td>
                      <td><input class="form-control" name="amd_responsible[]" value="<?php echo e($row['responsible_by'] ?? ''); ?>"></td>
                      <td><input class="form-control" name="amd_deadline[]" value="<?php echo e($row['deadline'] ?? ''); ?>"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td style="font-weight:1000;">1</td>
                      <td><input class="form-control" name="amd_discussion[]"></td>
                      <td><input class="form-control" name="amd_responsible[]"></td>
                      <td><input class="form-control" name="amd_deadline[]"></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- NEXT MEETING -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-calendar-event"></i></div>
              <div>
                <p class="sec-title mb-0">Next Meeting Date & Place</p>
                <p class="sec-sub mb-0">Optional</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="next_meeting_date" value="<?php echo e($defaultNextDate); ?>">
              </div>
              <div>
                <label class="form-label">Place</label>
                <input class="form-control" name="next_meeting_place" placeholder="Enter meeting place" value="<?php echo e($defaultNextPlace); ?>">
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3 gap-2">
              <?php if ($editMode): ?>
                <a href="mom.php?site_id=<?php echo $formSiteId; ?>" class="btn btn-outline-secondary" style="border-radius:12px; font-weight:900; padding:10px 16px;">
                  Cancel
                </a>
              <?php endif; ?>
              <button type="submit" class="btn-primary-tek" <?php echo ($formSiteId<=0 ? 'disabled' : ''); ?>>
                <i class="bi bi-check2-circle"></i> <?php echo $editMode ? 'Update MOM' : 'Submit MOM'; ?>
              </button>
            </div>

            <?php if ($formSiteId<=0): ?>
              <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to enable submit.</div>
            <?php endif; ?>
          </div>
        </form>

        <!-- RECENT MOM -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Recent MOM</p>
              <p class="sec-sub mb-0">Your last submissions</p>
            </div>
          </div>

          <?php if (empty($recent)): ?>
            <div class="text-muted" style="font-weight:800;">No MOM submitted yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr><th>MOM No</th><th>Date</th><th>Project</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo e($r['mom_no']); ?></td>
                      <td><?php echo e($r['mom_date']); ?></td>
                      <td><?php echo e($r['project_name']); ?></td>
                      <td>
                        <div class="d-flex gap-1">
                          <a href="view-mom.php?id=<?php echo $r['id']; ?>" class="action-btn" title="View MOM" target="_blank">
                            <i class="bi bi-eye"></i>
                          </a>
                          <a href="mom.php?site_id=<?php echo $formSiteId ?: $siteId; ?>&mom_id=<?php echo $r['id']; ?>" class="action-btn" title="Edit MOM">
                            <i class="bi bi-pencil"></i>
                          </a>
                        </div>
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

<!-- View MOM Modal -->
<div class="modal fade" id="viewMomModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" style="font-weight: 900;">Minutes of Meeting Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="viewMomContent">
        <div class="text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="font-weight: 800;">Close</button>
        <button type="button" class="btn btn-primary" id="printMomBtn" style="font-weight: 800;">
          <i class="bi bi-printer"></i> Print
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){

  // Site change -> reload
  var picker = document.getElementById('sitePicker');
  if (picker && !picker.disabled) {
    picker.addEventListener('change', function(){
      var v = picker.value || '';
      window.location.href = v ? ('mom.php?site_id=' + encodeURIComponent(v)) : 'mom.php';
    });
  }

  function renumberTbody(tbodyId){
    const tb = document.getElementById(tbodyId);
    if (!tb) return;
    const rows = tb.querySelectorAll('tr');
    rows.forEach((tr, idx) => {
      const firstCell = tr.querySelector('td:first-child');
      if (firstCell) {
        firstCell.textContent = String(idx + 1);
      }
    });
  }

  function addRow(tbodyId, html){
    const tb = document.getElementById(tbodyId);
    if (!tb) return;
    const tr = document.createElement('tr');
    tr.innerHTML = html;
    tb.appendChild(tr);
    
    // Renumber if needed
    if (tbodyId === 'agendaBody' || tbodyId === 'minutesBody' || tbodyId === 'amendedBody') {
      renumberTbody(tbodyId);
    }
  }

  document.getElementById('addAgenda')?.addEventListener('click', function(){
    addRow('agendaBody', `
      <td style="font-weight:1000;"></td>
      <td><input class="form-control" name="agenda_item[]"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
    `);
  });

  document.getElementById('addAttendee')?.addEventListener('click', function(){
    addRow('attendeeBody', `
      <td>
        <select class="form-select" name="att_stakeholder[]">
          <option value="">-- Select --</option>
          <option value="Client">Client</option>
          <option value="PMC">PMC</option>
          <option value="Architect">Architect</option>
          <option value="Contractor">Contractor</option>
          <option value="Vendor">Vendor</option>
          <option value="Other">Other</option>
        </select>
      </td>
      <td><input class="form-control" name="att_name[]"></td>
      <td><input class="form-control" name="att_designation[]"></td>
      <td><input class="form-control" name="att_firm[]"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
    `);
  });

  document.getElementById('addMinute')?.addEventListener('click', function(){
    addRow('minutesBody', `
      <td style="font-weight:1000;"></td>
      <td><input class="form-control" name="min_discussion[]"></td>
      <td><input class="form-control" name="min_responsible[]"></td>
      <td><input class="form-control" name="min_deadline[]"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
    `);
  });

  document.getElementById('addAmended')?.addEventListener('click', function(){
    addRow('amendedBody', `
      <td style="font-weight:1000;"></td>
      <td><input class="form-control" name="amd_discussion[]"></td>
      <td><input class="form-control" name="amd_responsible[]"></td>
      <td><input class="form-control" name="amd_deadline[]"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
    `);
  });

  // Delete row (event delegation)
  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('.delRow');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;

    const tb = tr.parentNode;
    if (!tb) return;

    // Keep at least one row in each section: if only one row, just clear inputs
    if (tb.querySelectorAll('tr').length <= 1) {
      tr.querySelectorAll('input,select,textarea').forEach(el => {
        if (el.tagName === 'SELECT') el.selectedIndex = 0;
        else el.value = '';
      });
      return;
    }

    tr.remove();

    // Renumber where needed
    if (tb.id === 'agendaBody' || tb.id === 'minutesBody' || tb.id === 'amendedBody') {
      renumberTbody(tb.id);
    }
  });

  // View MOM function
  window.viewMOM = function(momId) {
    const modal = new bootstrap.Modal(document.getElementById('viewMomModal'));
    const contentDiv = document.getElementById('viewMomContent');
    
    contentDiv.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    modal.show();
    
    fetch('ajax/get-mom-details.php?id=' + momId)
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          contentDiv.innerHTML = formatMOMDetails(data.mom);
        } else {
          contentDiv.innerHTML = '<div class="alert alert-danger">Failed to load MOM details</div>';
        }
      })
      .catch(error => {
        console.error('Error:', error);
        contentDiv.innerHTML = '<div class="alert alert-danger">Error loading MOM details</div>';
      });
  };

  // Format MOM details HTML
  function formatMOMDetails(mom) {
    let agendaHtml = '';
    if (mom.agenda_json) {
      const agenda = JSON.parse(mom.agenda_json);
      agenda.forEach((item, idx) => {
        agendaHtml += `<li class="list-group-item">${idx + 1}. ${escapeHtml(item.item || '')}</li>`;
      });
    }

    let attendeesHtml = '';
    if (mom.attendees_json) {
      const attendees = JSON.parse(mom.attendees_json);
      attendees.forEach(att => {
        attendeesHtml += `
          <tr>
            <td>${escapeHtml(att.stakeholder || '')}</td>
            <td>${escapeHtml(att.name || '')}</td>
            <td>${escapeHtml(att.designation || '')}</td>
            <td>${escapeHtml(att.firm || '')}</td>
          </tr>
        `;
      });
    }

    let minutesHtml = '';
    if (mom.minutes_json) {
      const minutes = JSON.parse(mom.minutes_json);
      minutes.forEach((min, idx) => {
        minutesHtml += `
          <tr>
            <td>${idx + 1}</td>
            <td>${escapeHtml(min.discussion || '')}</td>
            <td>${escapeHtml(min.responsible_by || '')}</td>
            <td>${escapeHtml(min.deadline || '')}</td>
          </tr>
        `;
      });
    }

    let amendedHtml = '';
    if (mom.amended_json) {
      const amended = JSON.parse(mom.amended_json);
      amended.forEach((amd, idx) => {
        amendedHtml += `
          <tr>
            <td>${idx + 1}</td>
            <td>${escapeHtml(amd.discussion || '')}</td>
            <td>${escapeHtml(amd.responsible_by || '')}</td>
            <td>${escapeHtml(amd.deadline || '')}</td>
          </tr>
        `;
      });
    }

    return `
      <div class="container-fluid">
        <div class="row mb-4">
          <div class="col-12">
            <h4 class="mb-3" style="font-weight: 900;">${escapeHtml(mom.mom_no)}</h4>
            <p><strong>Meeting Date:</strong> ${formatDate(mom.mom_date)}</p>
          </div>
        </div>

        <div class="row mb-4">
          <div class="col-md-6">
            <div class="card">
              <div class="card-header bg-light" style="font-weight: 900;">Project Information</div>
              <div class="card-body">
                <p><strong>Project:</strong> ${escapeHtml(mom.project_name)}</p>
                <p><strong>Location:</strong> ${escapeHtml(mom.project_location || '')}</p>
                <p><strong>Client:</strong> ${escapeHtml(mom.client_name || '')}</p>
                <p><strong>Architects:</strong> ${escapeHtml(mom.architects || '—')}</p>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card">
              <div class="card-header bg-light" style="font-weight: 900;">Meeting Information</div>
              <div class="card-body">
                <p><strong>Conducted by:</strong> ${escapeHtml(mom.meeting_conducted_by)}</p>
                <p><strong>Held at:</strong> ${escapeHtml(mom.meeting_held_at)}</p>
                <p><strong>Time:</strong> ${escapeHtml(mom.meeting_time)}</p>
              </div>
            </div>
          </div>
        </div>

        <div class="row mb-4">
          <div class="col-12">
            <div class="card">
              <div class="card-header bg-light" style="font-weight: 900;">Agenda</div>
              <div class="card-body">
                ${agendaHtml ? `<ul class="list-group">${agendaHtml}</ul>` : '<p class="text-muted">No agenda items</p>'}
              </div>
            </div>
          </div>
        </div>

        <div class="row mb-4">
          <div class="col-12">
            <div class="card">
              <div class="card-header bg-light" style="font-weight: 900;">Attendees</div>
              <div class="card-body">
                <table class="table table-sm table-bordered">
                  <thead>
                    <tr>
                      <th>Stakeholder</th>
                      <th>Name</th>
                      <th>Designation</th>
                      <th>Firm</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${attendeesHtml || '<tr><td colspan="4" class="text-muted">No attendees</td></tr>'}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="row mb-4">
          <div class="col-12">
            <div class="card">
              <div class="card-header bg-light" style="font-weight: 900;">Minutes of Discussions</div>
              <div class="card-body">
                <table class="table table-sm table-bordered">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Discussion</th>
                      <th>Responsible</th>
                      <th>Deadline</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${minutesHtml || '<tr><td colspan="4" class="text-muted">No minutes recorded</td></tr>'}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        ${amendedHtml ? `
        <div class="row mb-4">
          <div class="col-12">
            <div class="card">
              <div class="card-header bg-light" style="font-weight: 900;">Amended Points</div>
              <div class="card-body">
                <table class="table table-sm table-bordered">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Discussion</th>
                      <th>Responsible</th>
                      <th>Deadline</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${amendedHtml}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        ` : ''}

        <div class="row">
          <div class="col-md-6">
            <div class="card">
              <div class="card-header bg-light" style="font-weight: 900;">Distribution</div>
              <div class="card-body">
                <p><strong>Shared to:</strong> ${escapeHtml(mom.mom_shared_to)}</p>
                <p><strong>Copy to:</strong> ${escapeHtml(mom.mom_copy_to || '—')}</p>
                <p><strong>Shared by:</strong> ${escapeHtml(mom.mom_shared_by)} on ${formatDate(mom.mom_shared_on)}</p>
                <p><strong>Prepared by:</strong> ${escapeHtml(mom.prepared_by)}</p>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card">
              <div class="card-header bg-light" style="font-weight: 900;">Next Meeting</div>
              <div class="card-body">
                <p><strong>Date:</strong> ${mom.next_meeting_date ? formatDate(mom.next_meeting_date) : '—'}</p>
                <p><strong>Place:</strong> ${escapeHtml(mom.next_meeting_place || '—')}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function formatDate(dateStr) {
    if (!dateStr || dateStr === '0000-00-00') return '—';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '—';
    return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  // Print button in modal
  document.getElementById('printMomBtn')?.addEventListener('click', function() {
    const printContent = document.getElementById('viewMomContent').innerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
      <html>
        <head>
          <title>MOM Details</title>
          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
          <style>
            body { padding: 20px; }
            .card { margin-bottom: 20px; }
          </style>
        </head>
        <body>
          ${printContent}
        </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.print();
  });

  // Initial renumbering
  renumberTbody('agendaBody');
  renumberTbody('minutesBody');
  renumberTbody('amendedBody');
});
</script>

</body>
</html>