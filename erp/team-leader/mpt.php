<?php
// mpt.php — Monthly Planned Tracker (MPT) submit (same DPR page style)
// - Same layout/panels/styles as DPR page
// - Site access: Manager => managed sites, Others => site_project_engineers
// - Saves MPT rows (A-D sections) as JSON in mpt_reports.items_json

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

function monthName(int $m): string {
  $names = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
  return $names[$m] ?? 'Month';
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, designation FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$preparedBy = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');

// ---------------- Create MPT Table if Not Exists ----------------
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS mpt_reports (
  id INT(11) NOT NULL AUTO_INCREMENT,
  site_id INT(11) NOT NULL,
  employee_id INT(11) NOT NULL,

  mpt_no VARCHAR(60) NOT NULL,
  mpt_date DATE NOT NULL,
  mpt_month TINYINT NOT NULL,
  mpt_year SMALLINT NOT NULL,

  project_handover_date DATE NULL,
  items_json LONGTEXT NULL,

  prepared_by VARCHAR(150) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),

  PRIMARY KEY (id),
  KEY idx_mpt_site (site_id),
  KEY idx_mpt_employee (employee_id),
  KEY idx_mpt_month (mpt_month),
  KEY idx_mpt_year (mpt_year),
  CONSTRAINT fk_mpt_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpt_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ---------------- Assigned Sites ----------------
$sites = [];

if ($designation === 'manager') {
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name, s.expected_completion_date
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
    SELECT s.id, s.project_name, s.project_location, c.client_name, s.expected_completion_date
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
        s.id, s.project_name, s.project_type, s.project_location, s.scope_of_work,
        s.start_date, s.expected_completion_date,
        c.client_name, c.client_type, c.company_name
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

// ---------------- Month/Year Defaults ----------------
$nowMonth = (int)date('n');
$nowYear  = (int)date('Y');

$formMonth = isset($_GET['m']) ? (int)$_GET['m'] : $nowMonth;
$formYear  = isset($_GET['y']) ? (int)$_GET['y'] : $nowYear;
if ($formMonth < 1 || $formMonth > 12) $formMonth = $nowMonth;
if ($formYear < 2000 || $formYear > 2100) $formYear = $nowYear;

// ---------------- Default MPT No ----------------
$todayYmd = date('Y-m-d');
$defaultMptNo = '';
if ($siteId > 0) {
  $seq = 1;
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM mpt_reports WHERE site_id=? AND mpt_month=? AND mpt_year=?");
  if ($st) {
    mysqli_stmt_bind_param($st, "iii", $siteId, $formMonth, $formYear);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    $seq = ((int)($row['cnt'] ?? 0)) + 1;
  }
  $defaultMptNo = 'MPT-' . $siteId . '-' . date('Ym') . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

// Project Hand Over default (use expected completion date if available)
$defaultHandOver = ($site && !empty($site['expected_completion_date']) && $site['expected_completion_date'] !== '0000-00-00')
  ? $site['expected_completion_date']
  : '';

// ---------------- SUBMIT ----------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_mpt'])) {

  $site_id = (int)($_POST['site_id'] ?? 0);
  $mpt_no  = trim((string)($_POST['mpt_no'] ?? ''));
  $mpt_date = trim((string)($_POST['mpt_date'] ?? ''));
  $mpt_month = (int)($_POST['mpt_month'] ?? 0);
  $mpt_year  = (int)($_POST['mpt_year'] ?? 0);
  $handover  = trim((string)($_POST['project_handover_date'] ?? ''));

  // Validate site assigned
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $site_id) { $okSite = true; break; }
  }
  if (!$okSite) $error = "Invalid site selection.";

  if ($error === '' && $site_id <= 0) $error = "Please choose a site.";
  if ($error === '' && $mpt_no === '') $error = "MPT No is required.";
  if ($error === '' && $mpt_date === '') $error = "MPT Date is required.";
  if ($error === '' && ($mpt_month < 1 || $mpt_month > 12)) $error = "Invalid month.";
  if ($error === '' && ($mpt_year < 2000 || $mpt_year > 2100)) $error = "Invalid year.";

  // Collect Section Rows (A-D)
  $sections = [
    'A' => 'DESIGN DELIVERABLE',
    'B' => 'VENDOR FINALIZATION',
    'C' => 'SITE WORKS',
    'D' => 'CLIENT DECISIONS',
  ];

  $items = [];
  foreach ($sections as $code => $title) {
    $task = $_POST[$code.'_task'] ?? [];
    $res  = $_POST[$code.'_responsible'] ?? [];
    $date = $_POST[$code.'_plan_date'] ?? [];
    $pln  = $_POST[$code.'_pct_planned'] ?? [];
    $act  = $_POST[$code.'_pct_actual'] ?? [];
    $sta  = $_POST[$code.'_status'] ?? [];
    $rem  = $_POST[$code.'_remarks'] ?? [];

    $rows = [];
    $max = max(count($task), count($res), count($date), count($pln), count($act), count($sta), count($rem));
    for ($i=0; $i<$max; $i++){
      $rows[] = [
        'section' => $code,
        'section_title' => $title,
        'planned_task' => $task[$i] ?? '',
        'responsible_by' => $res[$i] ?? '',
        'planned_completion_date' => $date[$i] ?? '',
        'pct_planned' => $pln[$i] ?? '',
        'pct_actual' => $act[$i] ?? '',
        'status' => $sta[$i] ?? '',
        'remarks' => $rem[$i] ?? '',
      ];
    }
    $rows = jsonCleanRows($rows);
    $items = array_merge($items, $rows);
  }

  if ($error === '' && empty($items)) {
    $error = "Please enter at least one planned task in any section.";
  }

  if ($error === '') {
    $items_json = json_encode($items, JSON_UNESCAPED_UNICODE);

    $handVal = ymdOrNull($handover);
    $ins = mysqli_prepare($conn, "
      INSERT INTO mpt_reports
      (site_id, employee_id, mpt_no, mpt_date, mpt_month, mpt_year, project_handover_date, items_json, prepared_by)
      VALUES (?,?,?,?,?,?,?,?,?)
    ");
    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "iissiiiss",
        $site_id, $employeeId, $mpt_no, $mpt_date, $mpt_month, $mpt_year, $handVal, $items_json, $preparedBy
      );
      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to save MPT: " . mysqli_stmt_error($ins);
      } else {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        header("Location: mpt.php?site_id=".$site_id."&m=".$mpt_month."&y=".$mpt_year."&saved=1&mpt_id=".$newId);
        exit;
      }
      mysqli_stmt_close($ins);
    }
  }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "MPT submitted successfully.";
}

// Recent MPTs
$recent = [];
$st = mysqli_prepare($conn, "
  SELECT r.id, r.mpt_no, r.mpt_date, r.mpt_month, r.mpt_year, s.project_name
  FROM mpt_reports r
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
$formMptNo  = $defaultMptNo;
$formMptDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>MPT - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <!-- SAME DPR PAGE STYLES -->
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
            <h1 class="h-title">Monthly Planned Tracker (MPT)</h1>
            <p class="h-sub">Submit your MPT for the selected project site</p>
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
              <p class="sec-sub mb-0">Choose the site to prepare MPT</p>
            </div>
          </div>

          <div class="grid-3">
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

            <div>
              <label class="form-label">Month</label>
              <select class="form-select" id="monthPicker">
                <?php for ($m=1; $m<=12; $m++): ?>
                  <option value="<?php echo $m; ?>" <?php echo ($m === $formMonth ? 'selected' : ''); ?>>
                    <?php echo e(monthName($m)); ?>
                  </option>
                <?php endfor; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Year</label>
              <select class="form-select" id="yearPicker">
                <?php for ($y=(int)date('Y')-2; $y<=(int)date('Y')+3; $y++): ?>
                  <option value="<?php echo $y; ?>" <?php echo ($y === $formYear ? 'selected' : ''); ?>>
                    <?php echo $y; ?>
                  </option>
                <?php endfor; ?>
              </select>
            </div>
          </div>

          <div class="d-flex align-items-end justify-content-end mt-3">
            <a class="btn btn-outline-secondary" href="mpt.php" style="border-radius:12px; font-weight:900;">
              <i class="bi bi-arrow-clockwise"></i> Reset
            </a>
          </div>
        </div>

        <!-- PROJECT DETAILS -->
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
              <div><div class="small-muted">Expected Completion</div><div style="font-weight:900;"><?php echo e($site['expected_completion_date']); ?></div></div>
            </div>

            <div class="mt-2">
              <div class="small-muted">Scope of Work</div>
              <div style="font-weight:850;"><?php echo e($site['scope_of_work']); ?></div>
            </div>
          <?php endif; ?>
        </div>

        <!-- MPT FORM -->
        <form method="POST" autocomplete="off">
          <input type="hidden" name="submit_mpt" value="1">
          <input type="hidden" name="site_id" value="<?php echo (int)$formSiteId; ?>">

          <!-- MPT HEADER -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-file-text"></i></div>
              <div>
                <p class="sec-title mb-0">MPT Header</p>
                <p class="sec-sub mb-0">Month: <?php echo e(monthName($formMonth)); ?> • <?php echo (int)$formYear; ?></p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">MPT No <span class="text-danger">*</span></label>
                <input class="form-control" name="mpt_no" value="<?php echo e($formMptNo); ?>" required>
              </div>
              <div>
                <label class="form-label">MPT Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="mpt_date" value="<?php echo e($formMptDate); ?>" required>
              </div>
              <div>
                <label class="form-label">Project Hand Over</label>
                <input type="date" class="form-control" name="project_handover_date" value="<?php echo e($defaultHandOver); ?>">
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">Month</label>
                <input class="form-control" value="<?php echo e(monthName($formMonth)); ?>" readonly>
                <input type="hidden" name="mpt_month" value="<?php echo (int)$formMonth; ?>">
              </div>
              <div>
                <label class="form-label">Year</label>
                <input class="form-control" value="<?php echo (int)$formYear; ?>" readonly>
                <input type="hidden" name="mpt_year" value="<?php echo (int)$formYear; ?>">
              </div>
            </div>
          </div>

          <?php
          function renderMptSection($code, $title){
            ?>
            <div class="panel">
              <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div class="sec-head mb-0" style="flex:1;">
                  <div class="sec-ic"><i class="bi bi-list-check"></i></div>
                  <div>
                    <p class="sec-title mb-0"><?php echo e($code . ' — ' . $title); ?></p>
                    <p class="sec-sub mb-0">Add as many rows as needed</p>
                  </div>
                </div>
                <button type="button" class="btn btn-outline-primary btn-addrow" data-add="<?php echo e($code); ?>">
                  <i class="bi bi-plus-circle"></i> Add Row
                </button>
              </div>

              <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                  <thead>
                    <tr>
                      <th style="width:70px;">SL.NO</th>
                      <th>PLANNED TASK</th>
                      <th style="width:160px;">RESPONSIBLE BY</th>
                      <th style="width:190px;">PLANNED COMPLETION DATE</th>
                      <th style="width:130px;">% PLANNED</th>
                      <th style="width:130px;">% ACTUAL</th>
                      <th style="width:150px;">STATUS</th>
                      <th>REMARKS</th>
                      <th style="width:70px;">Del</th>
                    </tr>
                  </thead>
                  <tbody id="secBody-<?php echo e($code); ?>">
                    <tr>
                      <td style="font-weight:1000; text-align:center;">1</td>
                      <td><input class="form-control" name="<?php echo e($code); ?>_task[]"></td>
                      <td><input class="form-control" name="<?php echo e($code); ?>_responsible[]" placeholder="e.g. MYVN / UKB"></td>
                      <td><input type="date" class="form-control" name="<?php echo e($code); ?>_plan_date[]"></td>
                      <td><input class="form-control" name="<?php echo e($code); ?>_pct_planned[]" placeholder="e.g. 100"></td>
                      <td><input class="form-control" name="<?php echo e($code); ?>_pct_actual[]" placeholder="e.g. 25"></td>
                      <td>
                        <select class="form-select" name="<?php echo e($code); ?>_status[]">
                          <option value="">-- Select --</option>
                          <option value="ON TRACK">ON TRACK</option>
                          <option value="DONE">DONE</option>
                          <option value="DELAY">DELAY</option>
                        </select>
                      </td>
                      <td><input class="form-control" name="<?php echo e($code); ?>_remarks[]"></td>
                      <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger delRow">
                          <i class="bi bi-trash"></i>
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <?php
          }

          renderMptSection('A', 'DESIGN DELIVERABLE');
          renderMptSection('B', 'VENDOR FINALIZATION');
          renderMptSection('C', 'SITE WORKS');
          renderMptSection('D', 'CLIENT DECISIONS');
          ?>

          <!-- SUBMIT -->
          <div class="panel">
            <div class="d-flex justify-content-end">
              <button type="submit" class="btn-primary-tek" <?php echo ($formSiteId<=0 ? 'disabled' : ''); ?>>
                <i class="bi bi-check2-circle"></i> Submit MPT
              </button>
            </div>

            <?php if ($formSiteId<=0): ?>
              <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to enable submit.</div>
            <?php endif; ?>
          </div>
        </form>

        <!-- RECENT MPT -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Recent MPT</p>
              <p class="sec-sub mb-0">Your last submissions</p>
            </div>
          </div>

          <?php if (empty($recent)): ?>
            <div class="text-muted" style="font-weight:800;">No MPT submitted yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>MPT No</th>
                    <th>Date</th>
                    <th>Month</th>
                    <th>Project</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo e($r['mpt_no']); ?></td>
                      <td><?php echo e($r['mpt_date']); ?></td>
                      <td><?php echo e(monthName((int)$r['mpt_month'])); ?> <?php echo (int)$r['mpt_year']; ?></td>
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
  // Reload with site + month + year (same approach as DPR page)
  document.addEventListener('DOMContentLoaded', function(){
    const sitePicker  = document.getElementById('sitePicker');
    const monthPicker = document.getElementById('monthPicker');
    const yearPicker  = document.getElementById('yearPicker');

    function reload(){
      const sid = sitePicker ? (sitePicker.value || '') : '';
      const m = monthPicker ? (monthPicker.value || '') : '';
      const y = yearPicker ? (yearPicker.value || '') : '';

      let url = 'mpt.php';
      const params = [];
      if (sid) params.push('site_id=' + encodeURIComponent(sid));
      if (m) params.push('m=' + encodeURIComponent(m));
      if (y) params.push('y=' + encodeURIComponent(y));
      if (params.length) url += '?' + params.join('&');

      window.location.href = url;
    }

    if (sitePicker) sitePicker.addEventListener('change', reload);
    if (monthPicker) monthPicker.addEventListener('change', reload);
    if (yearPicker) yearPicker.addEventListener('change', reload);

    function addRow(sectionCode){
      const tb = document.getElementById('secBody-' + sectionCode);
      if (!tb) return;

      const nextSl = tb.querySelectorAll('tr').length + 1;

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="font-weight:1000; text-align:center;">${nextSl}</td>
        <td><input class="form-control" name="${sectionCode}_task[]"></td>
        <td><input class="form-control" name="${sectionCode}_responsible[]" placeholder="e.g. MYVN / UKB"></td>
        <td><input type="date" class="form-control" name="${sectionCode}_plan_date[]"></td>
        <td><input class="form-control" name="${sectionCode}_pct_planned[]" placeholder="e.g. 100"></td>
        <td><input class="form-control" name="${sectionCode}_pct_actual[]" placeholder="e.g. 25"></td>
        <td>
          <select class="form-select" name="${sectionCode}_status[]">
            <option value="">-- Select --</option>
            <option value="ON TRACK">ON TRACK</option>
            <option value="DONE">DONE</option>
            <option value="DELAY">DELAY</option>
          </select>
        </td>
        <td><input class="form-control" name="${sectionCode}_remarks[]"></td>
        <td class="text-center">
          <button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button>
        </td>
      `;
      tb.appendChild(tr);
    }

    function renumber(sectionCode){
      const tb = document.getElementById('secBody-' + sectionCode);
      if (!tb) return;
      Array.from(tb.querySelectorAll('tr')).forEach((row, idx) => {
        const td = row.querySelector('td');
        if (td) td.textContent = String(idx + 1);
      });
    }

    // Add row buttons
    document.addEventListener('click', function(ev){
      const addBtn = ev.target.closest('[data-add]');
      if (addBtn) {
        addRow(addBtn.getAttribute('data-add'));
        return;
      }

      // Delete row (event delegation, keep at least 1 row)
      const delBtn = ev.target.closest('.delRow');
      if (!delBtn) return;

      const tr = delBtn.closest('tr');
      const tb = tr ? tr.parentNode : null;
      if (!tr || !tb) return;

      // Find sectionCode by tbody id "secBody-X"
      const tbId = tb.id || '';
      const sectionCode = tbId.startsWith('secBody-') ? tbId.replace('secBody-','') : '';

      if (tb.querySelectorAll('tr').length <= 1) {
        tr.querySelectorAll('input,select,textarea').forEach(el => el.value = '');
      } else {
        tr.remove();
      }
      if (sectionCode) renumber(sectionCode);
    });
  });
</script>

</body>
</html>
