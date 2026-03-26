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

  PRIMARY KEY (id),
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
        c.client_name
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
// $todayYmd = date('Y-m-d');
// $defaultMomNo = '';
// if ($siteId > 0) {
//   $seq = 1;
//   $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM mom_reports WHERE site_id=? AND mom_date=?");
//   if ($st) {
//     mysqli_stmt_bind_param($st, "is", $siteId, $todayYmd);
//     mysqli_stmt_execute($st);
//     $res = mysqli_stmt_get_result($st);
//     $row = mysqli_fetch_assoc($res);
//     mysqli_stmt_close($st);
//     $seq = ((int)($row['cnt'] ?? 0)) + 1;
//   }
//   $defaultMomNo = 'MOM-' . $siteId . '-' . date('Ymd') . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
// }

$todayYmd = date('Y-m-d');
$defaultMomNo = '';

if ($siteId > 0) {
  // Get the count for today's date for this site
  $seq = 1;
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM mom_reports WHERE site_id=? ");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $siteId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    $seq = ((int)($row['cnt'] ?? 0)) + 1;
  }
  
  // Option 1: Format like CHK-SITEID-DATE-SEQ (commented out version)
  // $defaultDocNo = 'CHK-' . $siteId . '-' . date('Ymd') . '-' . str_pad($seq, 2, '0', STR_PAD_LEFT);
  
  // Option 2: Simple format with site ID and sequence (FIXED)
  $defaultMomNo =   $defaultDarNo = '#' . str_pad($seq, 2, '0', STR_PAD_LEFT);

  
}

// ---------------- SUBMIT ----------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_mom'])) {

  $site_id = (int)($_POST['site_id'] ?? 0);

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
    $agendaRows[] = ['item' => $ag[$i] ?? ''];
  }
  $agendaRows = jsonCleanRows($agendaRows);

  // Attendees rows
  $attRows = [];
  $stk = $_POST['att_stakeholder'] ?? [];
  $nm  = $_POST['att_name'] ?? [];
  $des = $_POST['att_designation'] ?? [];
  $frm = $_POST['att_firm'] ?? [];
  $max = max(count($stk), count($nm), count($des), count($frm));
  for ($i=0; $i<$max; $i++){
    $attRows[] = [
      'stakeholder' => $stk[$i] ?? '',
      'name' => $nm[$i] ?? '',
      'designation' => $des[$i] ?? '',
      'firm' => $frm[$i] ?? '',
    ];
  }
  $attRows = jsonCleanRows($attRows);

  // Minutes rows
  $minRows = [];
  $disc = $_POST['min_discussion'] ?? [];
  $resp = $_POST['min_responsible'] ?? [];
  $dead = $_POST['min_deadline'] ?? [];
  $max = max(count($disc), count($resp), count($dead));
  for ($i=0; $i<$max; $i++){
    $minRows[] = [
      'discussion' => $disc[$i] ?? '',
      'responsible_by' => $resp[$i] ?? '',
      'deadline' => $dead[$i] ?? '',
    ];
  }
  $minRows = jsonCleanRows($minRows);

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
    $amdRows[] = [
      'discussion' => $adisc[$i] ?? '',
      'responsible_by' => $aresp[$i] ?? '',
      'deadline' => $adead[$i] ?? '',
    ];
  }
  $amdRows = jsonCleanRows($amdRows);

  if ($error === '') {
    $agenda_json   = !empty($agendaRows) ? json_encode($agendaRows, JSON_UNESCAPED_UNICODE) : null;
    $attendees_json= !empty($attRows) ? json_encode($attRows, JSON_UNESCAPED_UNICODE) : null;
    $minutes_json  = !empty($minRows) ? json_encode($minRows, JSON_UNESCAPED_UNICODE) : null;
    $amended_json  = !empty($amdRows) ? json_encode($amdRows, JSON_UNESCAPED_UNICODE) : null;

    $nmd = ymdOrNull($next_meeting_date);
    $mso = ymdOrNull($mom_shared_on);

    $ins = mysqli_prepare($conn, "
      INSERT INTO   
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

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "MOM submitted successfully.";
}

// Recent MOMs
$recent = [];
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

// ---------------- Form Defaults ----------------
$formSiteId = $siteId;
$formMomNo  = $defaultMomNo;
$formMomDate = date('Y-m-d');

$defaultPmc = "M/s. UKB Construction Management Pvt Ltd";
$defaultConductedBy = $preparedBy;
$defaultHeldAt = $site ? ($site['project_location'] ?? '') : '';
$defaultTime = "";
$defaultSharedTo = "All Attendees";
$defaultSharedBy = $preparedBy;
$defaultSharedOn = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>MOM - TEK-C</title>

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
    @media (max-width: 992px){
      .grid-2, .grid-3{ grid-template-columns: 1fr; }
    }

    .table thead th{
      font-size: 12px;
      color:#6b7280;
      font-weight: 900;
      border-bottom:1px solid #e5e7eb !important;
      background:#f9fafb;
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
    .btn-addrow{
      border-radius: 12px;
      font-weight: 900;
    }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
    }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
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
            <h1 class="h-title">Minutes of Meeting (MOM)</h1>
            <p class="h-sub">Create and submit MOM for the selected project site</p>
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
              <select class="form-select" id="sitePicker">
                <option value="">-- Select Site --</option>
                <?php foreach ($sites as $s): ?>
                  <?php $sid = (int)$s['id']; ?>
                  <option value="<?php echo $sid; ?>" <?php echo ($sid === $formSiteId ? 'selected' : ''); ?>>
                    <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?> (<?php echo e($s['client_name']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="small-muted mt-1">Selecting a site will load project details.</div>
            </div>

            <div class="d-flex align-items-end justify-content-end">
              <a class="btn btn-outline-secondary" href="mom.php" style="border-radius:12px; font-weight:900;">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </a>
            </div>
          </div>
        </div>

        <!-- MOM FORM -->
        <form method="POST" autocomplete="off">
          <input type="hidden" name="submit_mom" value="1">
          <input type="hidden" name="site_id" value="<?php echo (int)$formSiteId; ?>">

          <!-- PROJECT INFORMATION -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-building"></i></div>
              <div>
                <p class="sec-title mb-0">Project Information</p>
                <p class="sec-sub mb-0">Auto-filled from selected site</p>
              </div>
            </div>

            <?php if (!$site): ?>
              <div class="text-muted" style="font-weight:800;">Please select a site above to load project information.</div>
            <?php else: ?>
              <div class="grid-2">
                <div>
                  <div class="small-muted">Project</div>
                  <div style="font-weight:1000;"><?php echo e($site['project_name']); ?></div>
                </div>
                <div>
                  <div class="small-muted">PMC</div>
                  <div style="font-weight:1000;"><?php echo e($defaultPmc); ?></div>
                </div>
              </div>

              <hr style="border-color:#eef2f7;">

              <div class="grid-2">
                <div>
                  <div class="small-muted">Client</div>
                  <div style="font-weight:1000;"><?php echo e($site['client_name']); ?></div>
                </div>
                <div>
                  <label class="form-label">Architects</label>
                  <input class="form-control" name="architects" placeholder="Enter architects (if any)">
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
                <label class="form-label">MOM No <span class="text-danger">*</span></label>
                <input class="form-control" name="mom_no" value="<?php echo e($formMomNo); ?>" required>
              </div>
              <div>
                <label class="form-label">Meeting Date <span class="text-danger">*</span></label>
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
                <label class="form-label">Meeting Conducted by <span class="text-danger">*</span></label>
                <input class="form-control" name="meeting_conducted_by" value="<?php echo e($defaultConductedBy); ?>" required>
              </div>
              <div>
                <label class="form-label">Meeting Held at <span class="text-danger">*</span></label>
                <input class="form-control" name="meeting_held_at" value="<?php echo e($defaultHeldAt); ?>" required>
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">Time <span class="text-danger">*</span></label>
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
                  <?php for ($i=1; $i<=12; $i++): ?>
                  <tr>
                    <td style="font-weight:1000;"><?php echo $i; ?></td>
                    <td><input class="form-control" name="min_discussion[]"></td>
                    <td><input class="form-control" name="min_responsible[]"></td>
                    <td><input class="form-control" name="min_deadline[]" placeholder="e.g. ASAP / 2026-02-20"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                  </tr>
                  <?php endfor; ?>
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
                <label class="form-label">Attendees <span class="text-danger">*</span></label>
                <input class="form-control" name="mom_shared_to" value="<?php echo e($defaultSharedTo); ?>" required>
              </div>
              <div>
                <label class="form-label">Copy to</label>
                <input class="form-control" name="mom_copy_to" placeholder="Comma separated (optional)">
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
                <label class="form-label">Shared by <span class="text-danger">*</span></label>
                <input class="form-control" name="mom_shared_by" value="<?php echo e($defaultSharedBy); ?>" required>
              </div>
              <div>
                <label class="form-label">Shared on <span class="text-danger">*</span></label>
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
                  <tr>
                    <td style="font-weight:1000;">1</td>
                    <td><input class="form-control" name="amd_discussion[]"></td>
                    <td><input class="form-control" name="amd_responsible[]"></td>
                    <td><input class="form-control" name="amd_deadline[]"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                  </tr>
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
                <input type="date" class="form-control" name="next_meeting_date">
              </div>
              <div>
                <label class="form-label">Place</label>
                <input class="form-control" name="next_meeting_place" placeholder="Enter meeting place">
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek" <?php echo ($formSiteId<=0 ? 'disabled' : ''); ?>>
                <i class="bi bi-check2-circle"></i> Submit MOM
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
                  <tr><th>MOM No</th><th>Date</th><th>Project</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo e($r['mom_no']); ?></td>
                      <td><?php echo e($r['mom_date']); ?></td>
                      <td><?php echo e($r['project_name']); ?></td>
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

  // Site change -> reload
  var picker = document.getElementById('sitePicker');
  if (picker) {
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
      const firstCell = tr.querySelector('td');
      if (firstCell && firstCell.dataset && firstCell.dataset.autonum === "1") {
        firstCell.textContent = String(idx + 1);
      }
    });
  }

  function addRow(tbodyId, html, autonum){
    const tb = document.getElementById(tbodyId);
    if (!tb) return;
    const tr = document.createElement('tr');
    tr.innerHTML = html;

    // Mark first cell for autonumbering
    if (autonum) {
      const first = tr.querySelector('td');
      if (first) first.dataset.autonum = "1";
    }

    tb.appendChild(tr);

    if (autonum) renumberTbody(tbodyId);
  }

  document.getElementById('addAgenda')?.addEventListener('click', function(){
    addRow('agendaBody', `
      <td data-autonum="1" style="font-weight:1000;"></td>
      <td><input class="form-control" name="agenda_item[]"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
    `, true);
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
    `, false);
  });

  document.getElementById('addMinute')?.addEventListener('click', function(){
    addRow('minutesBody', `
      <td data-autonum="1" style="font-weight:1000;"></td>
      <td><input class="form-control" name="min_discussion[]"></td>
      <td><input class="form-control" name="min_responsible[]"></td>
      <td><input class="form-control" name="min_deadline[]"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
    `, true);
  });

  document.getElementById('addAmended')?.addEventListener('click', function(){
    addRow('amendedBody', `
      <td data-autonum="1" style="font-weight:1000;"></td>
      <td><input class="form-control" name="amd_discussion[]"></td>
      <td><input class="form-control" name="amd_responsible[]"></td>
      <td><input class="form-control" name="amd_deadline[]"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
    `, true);
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
      tr.querySelectorAll('input,select,textarea').forEach(el => el.value = '');
      return;
    }

    tr.remove();

    // Renumber where needed
    if (tb.id === 'agendaBody') renumberTbody('agendaBody');
    if (tb.id === 'minutesBody') renumberTbody('minutesBody');
    if (tb.id === 'amendedBody') renumberTbody('amendedBody');
  });

  // Initial renumber for sections that already have numbers
  // Agenda first two rows already numbered (static), so only needed if user deletes.
});
</script>

</body>
</html>
