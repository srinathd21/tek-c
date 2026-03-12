<?php
// dpr.php — DPR submit (Project Engineer / Team Lead / Manager allowed)
// Updates per request:
// 1) Hide client contact details in UI
// 2) Schedule Start/End not editable (readonly display + hidden submit values)
// 3) Work Progress: when duration + start date entered -> auto-fill end date
// 4) Report Distribute To default includes "Client - {client_name}"

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

function calcDurations($startYmd, $endYmd){
  // returns [total, elapsed, balance]
  // total = inclusive days between start and end
  // elapsed = days passed since start till today (inclusive-ish), clamped to [0,total]
  // balance = total - elapsed, clamped >=0
  $startYmd = trim((string)$startYmd);
  $endYmd   = trim((string)$endYmd);

  $total = null; $elapsed = null; $balance = null;

  if ($startYmd !== '' && $endYmd !== '' && $startYmd !== '0000-00-00' && $endYmd !== '0000-00-00') {
    $s = DateTime::createFromFormat('Y-m-d', $startYmd);
    $e = DateTime::createFromFormat('Y-m-d', $endYmd);
    if ($s && $e) {
      if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }

      $diff = $s->diff($e)->days; // non-inclusive
      $total = $diff + 1;

      $today = new DateTime(date('Y-m-d'));
      if ($today < $s) $elapsed = 0;
      else {
        $elapsed = $s->diff($today)->days + 1;
        if ($elapsed > $total) $elapsed = $total;
      }
      $balance = $total - $elapsed;
      if ($balance < 0) $balance = 0;
    }
  }
  return [$total, $elapsed, $balance];
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

// ---------------- Create DPR Table if Not Exists ----------------
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS dpr_reports (
  id INT(11) NOT NULL AUTO_INCREMENT,
  site_id INT(11) NOT NULL,
  employee_id INT(11) NOT NULL,
  dpr_no VARCHAR(50) NOT NULL,
  dpr_date DATE NOT NULL,

  schedule_start DATE NULL,
  schedule_end DATE NULL,
  schedule_projected DATE NULL,
  duration_total INT NULL,
  duration_elapsed INT NULL,
  duration_balance INT NULL,

  weather VARCHAR(20) NOT NULL,
  site_condition VARCHAR(20) NOT NULL,

  manpower_json LONGTEXT NULL,
  machinery_json LONGTEXT NULL,
  material_json LONGTEXT NULL,
  work_progress_json LONGTEXT NULL,
  constraints_json LONGTEXT NULL,

  report_distribute_to LONGTEXT NOT NULL,
  prepared_by VARCHAR(150) NOT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  KEY idx_dpr_site (site_id),
  KEY idx_dpr_employee (employee_id),
  CONSTRAINT fk_dpr_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_dpr_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
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
        c.client_name, c.client_type, c.company_name,
        c.mobile_number AS client_mobile, c.email AS client_email, c.state
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

    // Default distribute: Client + Manager + Directors/VP/GM
    if ($site) {
      $parts = [];

      // Client first
      $clientName = trim((string)($site['client_name'] ?? ''));
      if ($clientName !== '') {
        $parts[] = 'Client - ' . $clientName;
      } else {
        $parts[] = 'Client';
      }

      // Manager
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
            $parts[] = $mLabel;
          }
          mysqli_stmt_close($stm);
        }
      }

      // Directors/VP/GM
      $dir = [];
      $q = "SELECT full_name, email, designation
            FROM employees
            WHERE employee_status='active'
              AND designation IN ('Director','Vice President','General Manager')
            ORDER BY designation ASC, full_name ASC";
      $r = mysqli_query($conn, $q);
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

// ---------------- Default DPR No ----------------
$todayYmd = date('Y-m-d');
$defaultDprNo = '';

if ($siteId > 0) {
  $seq = 1;
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM dpr_reports WHERE site_id=?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $siteId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    $seq = ((int)($row['cnt'] ?? 0)) + 1;
  }
  $defaultDprNo = 'DPR-' . $siteId . '-' . date('Ymd') . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

// ---------------- SUBMIT ----------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dpr'])) {

  $site_id = (int)($_POST['site_id'] ?? 0);
  $dpr_no  = trim((string)($_POST['dpr_no'] ?? ''));
  $dpr_date = trim((string)($_POST['dpr_date'] ?? ''));

  // schedule_start/end are no longer editable in UI
  $schedule_start     = trim((string)($_POST['schedule_start'] ?? ''));
  $schedule_end       = trim((string)($_POST['schedule_end'] ?? ''));
  $schedule_projected = trim((string)($_POST['schedule_projected'] ?? ''));

  $weather = trim((string)($_POST['weather'] ?? ''));
  $site_condition = trim((string)($_POST['site_condition'] ?? ''));
  $report_distribute_to = trim((string)($_POST['report_distribute_to'] ?? ''));

  // Validate site assigned
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $site_id) { $okSite = true; break; }
  }
  if (!$okSite) $error = "Invalid site selection.";

  if ($error === '' && $site_id <= 0) $error = "Please choose a site.";
  if ($error === '' && $dpr_no === '') $error = "DPR No is required.";
  if ($error === '' && $dpr_date === '') $error = "DPR Date is required.";
  if ($error === '' && $weather === '') $error = "Weather is required.";
  if ($error === '' && $site_condition === '') $error = "Site Condition is required.";
  if ($error === '' && $report_distribute_to === '') $error = "Report Distribute To is required.";

  // Server-side durations (always recalc)
  [$duration_total, $duration_elapsed, $duration_balance] = calcDurations($schedule_start, $schedule_end);

  // Collect rows (dynamic)
  $manpowerRows = [];
  $agency   = $_POST['mp_agency'] ?? [];
  $cat      = $_POST['mp_category'] ?? [];
  $unit     = $_POST['mp_unit'] ?? [];
  $qty      = $_POST['mp_qty'] ?? [];
  $remark   = $_POST['mp_remark'] ?? [];
  $max = max(count($agency), count($cat), count($unit), count($qty), count($remark));
  for ($i=0; $i<$max; $i++){
    $manpowerRows[] = [
      'agency' => $agency[$i] ?? '',
      'category' => $cat[$i] ?? '',
      'unit' => $unit[$i] ?? '',
      'qty' => $qty[$i] ?? '',
      'remark' => $remark[$i] ?? '',
    ];
  }
  $manpowerRows = jsonCleanRows($manpowerRows);

  $machRows = [];
  $eq   = $_POST['mc_equipment'] ?? [];
  $mcu  = $_POST['mc_unit'] ?? [];
  $mcq  = $_POST['mc_qty'] ?? [];
  $mcr  = $_POST['mc_remark'] ?? [];
  $max = max(count($eq), count($mcu), count($mcq), count($mcr));
  for ($i=0; $i<$max; $i++){
    $machRows[] = [
      'equipment' => $eq[$i] ?? '',
      'unit' => $mcu[$i] ?? '',
      'qty' => $mcq[$i] ?? '',
      'remark' => $mcr[$i] ?? '',
    ];
  }
  $machRows = jsonCleanRows($machRows);

  $matRows = [];
  $vd = $_POST['mt_vendor'] ?? [];
  $mt = $_POST['mt_material'] ?? [];
  $mtu= $_POST['mt_unit'] ?? [];
  $mtq= $_POST['mt_qty'] ?? [];
  $mtr= $_POST['mt_remark'] ?? [];
  $max = max(count($vd), count($mt), count($mtu), count($mtq), count($mtr));
  for ($i=0; $i<$max; $i++){
    $matRows[] = [
      'vendor' => $vd[$i] ?? '',
      'material' => $mt[$i] ?? '',
      'unit' => $mtu[$i] ?? '',
      'qty' => $mtq[$i] ?? '',
      'remark' => $mtr[$i] ?? '',
    ];
  }
  $matRows = jsonCleanRows($matRows);

  $wpRows = [];
  $task = $_POST['wp_task'] ?? [];
  $wpd  = $_POST['wp_duration'] ?? [];
  $wps  = $_POST['wp_start'] ?? [];
  $wpe  = $_POST['wp_end'] ?? [];
  $wst  = $_POST['wp_status'] ?? [];
  $wrs  = $_POST['wp_reasons'] ?? [];
  $max = max(count($task), count($wpd), count($wps), count($wpe), count($wst), count($wrs));
  for ($i=0; $i<$max; $i++){
    $wpRows[] = [
      'task' => $task[$i] ?? '',
      'duration' => $wpd[$i] ?? '',
      'start' => $wps[$i] ?? '',
      'end' => $wpe[$i] ?? '',
      'status' => $wst[$i] ?? '',
      'reasons' => $wrs[$i] ?? '',
    ];
  }
  $wpRows = jsonCleanRows($wpRows);

  if ($error === '' && empty($wpRows)) {
    $error = "Please enter at least one Work Progress task.";
  }

  $consRows = [];
  $issue = $_POST['cs_issue'] ?? [];
  $cst   = $_POST['cs_status'] ?? [];
  $csd   = $_POST['cs_date'] ?? [];
  $csr   = $_POST['cs_remark'] ?? [];
  $max = max(count($issue), count($cst), count($csd), count($csr));
  for ($i=0; $i<$max; $i++){
    $consRows[] = [
      'issue' => $issue[$i] ?? '',
      'status' => $cst[$i] ?? '',
      'date' => $csd[$i] ?? '',
      'remark' => $csr[$i] ?? '',
    ];
  }
  $consRows = jsonCleanRows($consRows);

  if ($error === '') {
    $manpower_json = !empty($manpowerRows) ? json_encode($manpowerRows, JSON_UNESCAPED_UNICODE) : null;
    $machinery_json = !empty($machRows) ? json_encode($machRows, JSON_UNESCAPED_UNICODE) : null;
    $material_json  = !empty($matRows) ? json_encode($matRows, JSON_UNESCAPED_UNICODE) : null;
    $work_progress_json = !empty($wpRows) ? json_encode($wpRows, JSON_UNESCAPED_UNICODE) : null;
    $constraints_json = !empty($consRows) ? json_encode($consRows, JSON_UNESCAPED_UNICODE) : null;

    $schS = ymdOrNull($schedule_start);
    $schE = ymdOrNull($schedule_end);
    $schP = ymdOrNull($schedule_projected);

    $ins = mysqli_prepare($conn, "
      INSERT INTO dpr_reports
      (site_id, employee_id, dpr_no, dpr_date,
       schedule_start, schedule_end, schedule_projected,
       duration_total, duration_elapsed, duration_balance,
       weather, site_condition,
       manpower_json, machinery_json, material_json, work_progress_json, constraints_json,
       report_distribute_to, prepared_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "iisssssiiisssssssss",
        $site_id, $employeeId, $dpr_no, $dpr_date,
        $schS, $schE, $schP,
        $duration_total, $duration_elapsed, $duration_balance,
        $weather, $site_condition,
        $manpower_json, $machinery_json, $material_json, $work_progress_json, $constraints_json,
        $report_distribute_to, $preparedBy
      );
      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to save DPR: " . mysqli_stmt_error($ins);
      } else {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        header("Location: dpr.php?site_id=".$site_id."&saved=1&dpr_id=".$newId);
        exit;
      }
      mysqli_stmt_close($ins);
    }
  }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "DPR submitted successfully.";
}

// Recent DPRs
$recent = [];
$st = mysqli_prepare($conn, "
  SELECT r.id, r.dpr_no, r.dpr_date, s.project_name
  FROM dpr_reports r
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
$formDprNo  = $defaultDprNo;
$formDprDate = date('Y-m-d');

$projectStart = ($site && !empty($site['start_date']) && $site['start_date'] !== '0000-00-00') ? $site['start_date'] : '';
$projectEnd   = ($site && !empty($site['expected_completion_date']) && $site['expected_completion_date'] !== '0000-00-00') ? $site['expected_completion_date'] : '';

$defaultScheduleStart = $projectStart !== '' ? $projectStart : date('Y-m-d');
$defaultScheduleEnd   = $projectEnd !== '' ? $projectEnd : '';
$defaultProjected     = date('Y-m-d');

[$defTotal, $defElapsed, $defBalance] = calcDurations($defaultScheduleStart, $defaultScheduleEnd);

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
  <title>DPR - TEK-C</title>

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
            <h1 class="h-title">Daily Progress Report (DPR)</h1>
            <p class="h-sub">Submit your DPR for the selected project site</p>
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
              <p class="sec-sub mb-0">Choose the site to prepare DPR</p>
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
              <div class="small-muted mt-1">Selecting a site will load project & client details.</div>
            </div>

            <div class="d-flex align-items-end justify-content-end">
              <a class="btn btn-outline-secondary" href="dpr.php" style="border-radius:12px; font-weight:900;">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </a>
            </div>
          </div>
        </div>

        <!-- PROJECT DETAILS (Client contact details hidden) -->
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
              <div><div class="small-muted">Project</div><div style="font-weight:1000;"><?php echo e($site['project_name']); ?></div></div>
              <div><div class="small-muted">Client</div><div style="font-weight:1000;"><?php echo e($site['client_name']); ?></div></div>
              <div><div class="small-muted">Location</div><div style="font-weight:1000;"><?php echo e($site['project_location']); ?></div></div>
            </div>

            <hr style="border-color:#eef2f7;">

            <div class="grid-3">
              <div><div class="small-muted">Project Type</div><div style="font-weight:900;"><?php echo e($site['project_type']); ?></div></div>
              <div><div class="small-muted">Project Start</div><div style="font-weight:900;"><?php echo e($site['start_date']); ?></div></div>
              <div><div class="small-muted">Project End</div><div style="font-weight:900;"><?php echo e($site['expected_completion_date']); ?></div></div>
            </div>

            <div class="mt-2">
              <div class="small-muted">Scope of Work</div>
              <div style="font-weight:850;"><?php echo e($site['scope_of_work']); ?></div>
            </div>
          <?php endif; ?>
        </div>

        <!-- DPR FORM -->
        <form method="POST" autocomplete="off">
          <input type="hidden" name="submit_dpr" value="1">
          <input type="hidden" name="site_id" value="<?php echo (int)$formSiteId; ?>">

          <!-- DPR HEADER -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-file-text"></i></div>
              <div>
                <p class="sec-title mb-0">DPR Header</p>
                <p class="sec-sub mb-0">Schedule Start/End locked from project dates</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">DPR No <span class="text-danger">*</span></label>
                <input class="form-control" name="dpr_no" value="<?php echo e($formDprNo); ?>" required>
              </div>
              <div>
                <label class="form-label">DPR Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="dpr_date" value="<?php echo e($formDprDate); ?>" required>
              </div>
              <div>
                <label class="form-label">Prepared By</label>
                <input class="form-control" value="<?php echo e($preparedBy); ?>" readonly>
              </div>
            </div>

            <hr style="border-color:#eef2f7;">

            <!-- Schedule Start/End NOT editable -->
            <div class="grid-3">
              <div>
                <label class="form-label">Schedule Start</label>
                <input type="text" class="form-control" value="<?php echo e($defaultScheduleStart); ?>" readonly>
                <input type="hidden" id="schedule_start" name="schedule_start" value="<?php echo e($defaultScheduleStart); ?>">
              </div>
              <div>
                <label class="form-label">Schedule End</label>
                <input type="text" class="form-control" value="<?php echo e($defaultScheduleEnd); ?>" readonly>
                <input type="hidden" id="schedule_end" name="schedule_end" value="<?php echo e($defaultScheduleEnd); ?>">
              </div>
              <div>
                <label class="form-label">Projected (Default Today)</label>
                <input type="date" class="form-control" id="schedule_projected" name="schedule_projected" value="<?php echo e($defaultProjected); ?>">
              </div>
            </div>

            <div class="grid-3 mt-2">
              <div>
                <label class="form-label">Duration Total (days)</label>
                <input type="number" class="form-control" id="duration_total" name="duration_total" value="<?php echo e((string)($defTotal ?? '')); ?>" readonly>
              </div>
              <div>
                <label class="form-label">Elapsed (days)</label>
                <input type="number" class="form-control" id="duration_elapsed" name="duration_elapsed" value="<?php echo e((string)($defElapsed ?? '')); ?>" readonly>
              </div>
              <div>
                <label class="form-label">Balance (days)</label>
                <input type="number" class="form-control" id="duration_balance" name="duration_balance" value="<?php echo e((string)($defBalance ?? '')); ?>" readonly>
              </div>
            </div>

            <div class="small-muted mt-2">
              Total/Elapsed/Balance are auto-calculated based on locked Schedule Start/End and today’s date.
            </div>
          </div>

          <!-- SITE -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-cloud-sun"></i></div>
              <div>
                <p class="sec-title mb-0">Site</p>
                <p class="sec-sub mb-0">Weather and site conditions</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">Weather <span class="text-danger">*</span></label>
                <select class="form-select" name="weather" required>
                  <option value="">-- Select --</option>
                  <option value="Normal">Normal</option>
                  <option value="Rainy">Rainy</option>
                </select>
              </div>
              <div>
                <label class="form-label">Site Condition <span class="text-danger">*</span></label>
                <select class="form-select" name="site_condition" required>
                  <option value="">-- Select --</option>
                  <option value="Normal">Normal</option>
                  <option value="Slushy">Slushy</option>
                </select>
              </div>
            </div>
          </div>

          <!-- MANPOWER -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-people"></i></div>
                <div>
                  <p class="sec-title mb-0">Manpower</p>
                  <p class="sec-sub mb-0">Add as many rows as needed</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addManpower">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Agency</th><th>Category</th><th style="width:120px;">Unit</th><th style="width:110px;">Qty</th><th>Remark</th><th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="manpowerBody">
                  <tr>
                    <td><input class="form-control" name="mp_agency[]"></td>
                    <td><input class="form-control" name="mp_category[]"></td>
                    <td><input class="form-control" name="mp_unit[]"></td>
                    <td><input class="form-control" name="mp_qty[]"></td>
                    <td><input class="form-control" name="mp_remark[]"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- MACHINERY -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-truck"></i></div>
                <div>
                  <p class="sec-title mb-0">Machineries</p>
                  <p class="sec-sub mb-0">Add as many rows as needed</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addMachinery">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Type of Equipment</th><th style="width:120px;">Unit</th><th style="width:110px;">Qty</th><th>Remark</th><th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="machineryBody">
                  <tr>
                    <td><input class="form-control" name="mc_equipment[]"></td>
                    <td><input class="form-control" name="mc_unit[]"></td>
                    <td><input class="form-control" name="mc_qty[]"></td>
                    <td><input class="form-control" name="mc_remark[]"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- MATERIAL -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-box-seam"></i></div>
                <div>
                  <p class="sec-title mb-0">Material</p>
                  <p class="sec-sub mb-0">Add as many rows as needed</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addMaterial">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Vendor</th><th>Material</th><th style="width:120px;">Unit</th><th style="width:110px;">Qty</th><th>Remark</th><th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="materialBody">
                  <tr>
                    <td><input class="form-control" name="mt_vendor[]"></td>
                    <td><input class="form-control" name="mt_material[]"></td>
                    <td><input class="form-control" name="mt_unit[]"></td>
                    <td><input class="form-control" name="mt_qty[]"></td>
                    <td><input class="form-control" name="mt_remark[]"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- WORK PROGRESS -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-list-check"></i></div>
              <div>
                <p class="sec-title mb-0">Work Progress</p>
                <p class="sec-sub mb-0">Enter at least one task <span class="text-danger">*</span></p>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Task</th>
                    <th style="width:120px;">Duration (days)</th>
                    <th style="width:140px;">Start</th>
                    <th style="width:140px;">End (Auto)</th>
                    <th style="width:160px;">Status</th>
                    <th>Reasons</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i=0; $i<4; $i++): ?>
                  <tr class="wp-row">
                    <td><input class="form-control" name="wp_task[]"></td>
                    <td><input class="form-control wp-duration" inputmode="numeric" name="wp_duration[]" placeholder="e.g. 3"></td>
                    <td><input type="date" class="form-control wp-start" name="wp_start[]"></td>
                    <td><input type="date" class="form-control wp-end" name="wp_end[]" readonly></td>
                    <td>
                      <select class="form-select" name="wp_status[]">
                        <option value="">-- Select --</option>
                        <option value="In Control">In Control</option>
                        <option value="Delay">Delay</option>
                      </select>
                    </td>
                    <td><input class="form-control" name="wp_reasons[]"></td>
                  </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
            <div class="small-muted mt-2">
              End date auto-fills when Duration + Start date are entered.
            </div>
          </div>

          <!-- CONSTRAINTS -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                  <p class="sec-title mb-0">Constraints</p>
                  <p class="sec-sub mb-0">Add as many rows as needed</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addConstraint">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Issue</th><th style="width:160px;">Status</th><th style="width:160px;">Date</th><th>Remark</th><th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="constraintBody">
                  <tr>
                    <td><input class="form-control" name="cs_issue[]"></td>
                    <td>
                      <select class="form-select" name="cs_status[]">
                        <option value="">-- Select --</option>
                        <option value="Open">Open</option>
                        <option value="Closed">Closed</option>
                      </select>
                    </td>
                    <td><input type="date" class="form-control" name="cs_date[]"></td>
                    <td><input class="form-control" name="cs_remark[]"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- REPORT BY -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-send"></i></div>
              <div>
                <p class="sec-title mb-0">Report by</p>
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
                <input class="form-control" value="<?php echo e($preparedBy); ?>" readonly>
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek" <?php echo ($formSiteId<=0 ? 'disabled' : ''); ?>>
                <i class="bi bi-check2-circle"></i> Submit DPR
              </button>
            </div>

            <?php if ($formSiteId<=0): ?>
              <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to enable submit.</div>
            <?php endif; ?>
          </div>
        </form>

        <!-- RECENT DPR -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Recent DPR</p>
              <p class="sec-sub mb-0">Your last submissions</p>
            </div>
          </div>

          <?php if (empty($recent)): ?>
            <div class="text-muted" style="font-weight:800;">No DPR submitted yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr><th>DPR No</th><th>Date</th><th>Project</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo e($r['dpr_no']); ?></td>
                      <td><?php echo e($r['dpr_date']); ?></td>
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
  // Site change -> reload
  document.addEventListener('DOMContentLoaded', function(){
    var picker = document.getElementById('sitePicker');
    if (picker) {
      picker.addEventListener('change', function(){
        var v = picker.value || '';
        window.location.href = v ? ('dpr.php?site_id=' + encodeURIComponent(v)) : 'dpr.php';
      });
    }

    // Duration calc (schedule_start/end locked, values present in hidden inputs)
    const sEl = document.getElementById('schedule_start');
    const eEl = document.getElementById('schedule_end');
    const tEl = document.getElementById('duration_total');
    const elEl = document.getElementById('duration_elapsed');
    const bEl = document.getElementById('duration_balance');

    function parseYmd(v){
      if (!v) return null;
      const parts = v.split('-');
      if (parts.length !== 3) return null;
      return new Date(parts[0], parts[1]-1, parts[2]);
    }
    function daysBetweenInclusive(a,b){
      const ms = 24*60*60*1000;
      const diff = Math.floor((b - a) / ms);
      return diff + 1;
    }
    function clamp(n, min, max){ return Math.max(min, Math.min(max, n)); }

    function recalcHeader(){
      const sd = parseYmd(sEl ? sEl.value : '');
      const ed = parseYmd(eEl ? eEl.value : '');
      if (!sd || !ed) {
        tEl.value = '';
        elEl.value = '';
        bEl.value = '';
        return;
      }

      let start = sd, end = ed;
      if (end < start) { const tmp = start; start = end; end = tmp; }

      const total = daysBetweenInclusive(start, end);
      const today = new Date();
      today.setHours(0,0,0,0);

      let elapsed = 0;
      if (today < start) elapsed = 0;
      else {
        const el = daysBetweenInclusive(start, today);
        elapsed = clamp(el, 0, total);
      }
      const balance = Math.max(0, total - elapsed);

      tEl.value = total;
      elEl.value = elapsed;
      bEl.value = balance;
    }
    recalcHeader();

    // Work Progress: end = start + (duration-1) days
    function addDaysYmd(startYmd, daysToAdd){
      const d = parseYmd(startYmd);
      if (!d) return '';
      d.setDate(d.getDate() + daysToAdd);
      const yyyy = d.getFullYear();
      const mm = String(d.getMonth()+1).padStart(2,'0');
      const dd = String(d.getDate()).padStart(2,'0');
      return `${yyyy}-${mm}-${dd}`;
    }

    function wpRecalcRow(tr){
      const durEl = tr.querySelector('.wp-duration');
      const stEl  = tr.querySelector('.wp-start');
      const endEl = tr.querySelector('.wp-end');
      if (!durEl || !stEl || !endEl) return;

      const dur = parseInt((durEl.value || '').trim(), 10);
      const st  = (stEl.value || '').trim();

      if (!st || !Number.isFinite(dur) || dur <= 0) {
        endEl.value = '';
        return;
      }

      // inclusive duration: 1 day => same end date as start
      const end = addDaysYmd(st, dur - 1);
      endEl.value = end;
    }

    document.querySelectorAll('tr.wp-row').forEach(function(tr){
      const durEl = tr.querySelector('.wp-duration');
      const stEl  = tr.querySelector('.wp-start');
      if (durEl) durEl.addEventListener('input', function(){ wpRecalcRow(tr); });
      if (stEl)  stEl.addEventListener('change', function(){ wpRecalcRow(tr); });
    });

    // Dynamic row adders
    function addRow(tbodyId, rowHtml){
      const tb = document.getElementById(tbodyId);
      if (!tb) return;
      const tr = document.createElement('tr');
      tr.innerHTML = rowHtml;
      tb.appendChild(tr);
    }

    document.getElementById('addManpower')?.addEventListener('click', function(){
      addRow('manpowerBody', `
        <td><input class="form-control" name="mp_agency[]"></td>
        <td><input class="form-control" name="mp_category[]"></td>
        <td><input class="form-control" name="mp_unit[]"></td>
        <td><input class="form-control" name="mp_qty[]"></td>
        <td><input class="form-control" name="mp_remark[]"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
      `);
    });

    document.getElementById('addMachinery')?.addEventListener('click', function(){
      addRow('machineryBody', `
        <td><input class="form-control" name="mc_equipment[]"></td>
        <td><input class="form-control" name="mc_unit[]"></td>
        <td><input class="form-control" name="mc_qty[]"></td>
        <td><input class="form-control" name="mc_remark[]"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
      `);
    });

    document.getElementById('addMaterial')?.addEventListener('click', function(){
      addRow('materialBody', `
        <td><input class="form-control" name="mt_vendor[]"></td>
        <td><input class="form-control" name="mt_material[]"></td>
        <td><input class="form-control" name="mt_unit[]"></td>
        <td><input class="form-control" name="mt_qty[]"></td>
        <td><input class="form-control" name="mt_remark[]"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
      `);
    });

    document.getElementById('addConstraint')?.addEventListener('click', function(){
      addRow('constraintBody', `
        <td><input class="form-control" name="cs_issue[]"></td>
        <td>
          <select class="form-select" name="cs_status[]">
            <option value="">-- Select --</option>
            <option value="Open">Open</option>
            <option value="Closed">Closed</option>
          </select>
        </td>
        <td><input type="date" class="form-control" name="cs_date[]"></td>
        <td><input class="form-control" name="cs_remark[]"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button></td>
      `);
    });

    // Delete row (event delegation)
    document.addEventListener('click', function(ev){
      const btn = ev.target.closest('.delRow');
      if (!btn) return;
      const tr = btn.closest('tr');
      if (tr && tr.parentNode) {
        const tb = tr.parentNode;
        if (tb.querySelectorAll('tr').length <= 1) {
          tr.querySelectorAll('input,select,textarea').forEach(el => el.value = '');
          return;
        }
        tr.remove();
      }
    });
  });
</script>

</body>
</html>
