<?php
// ma.php — Meeting Agenda (MA) submit
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

// ---------------- Create MA Table if Not Exists ----------------
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS ma_reports (
  id INT(11) NOT NULL AUTO_INCREMENT,
  site_id INT(11) NOT NULL,
  employee_id INT(11) NOT NULL,

  ma_no VARCHAR(80) NOT NULL,
  ma_date DATE NOT NULL,

  facilitator VARCHAR(150) DEFAULT NULL,
  meeting_date_place VARCHAR(190) DEFAULT NULL,
  meeting_taken_by VARCHAR(150) DEFAULT NULL,

  meeting_number VARCHAR(60) DEFAULT NULL,
  meeting_start_time VARCHAR(20) DEFAULT NULL,
  meeting_end_time VARCHAR(20) DEFAULT NULL,

  objectives_json LONGTEXT DEFAULT NULL,
  attendees_json LONGTEXT DEFAULT NULL,
  discussions_json LONGTEXT DEFAULT NULL,
  actions_json LONGTEXT DEFAULT NULL,

  next_meeting_date DATE DEFAULT NULL,
  next_meeting_start_time VARCHAR(20) DEFAULT NULL,
  next_meeting_end_time VARCHAR(20) DEFAULT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),

  PRIMARY KEY (id),
  KEY idx_ma_site (site_id),
  KEY idx_ma_employee (employee_id),
  KEY idx_ma_date (ma_date),

  CONSTRAINT fk_ma_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_ma_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
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
      SELECT s.id, s.project_name, s.project_location, c.client_name
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

// ---------------- Default MA No ----------------
$todayYmd = date('Y-m-d');
$defaultMaNo = '';

if ($siteId > 0) {
  $seq = 1;
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM ma_reports WHERE site_id=?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $siteId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    $seq = ((int)($row['cnt'] ?? 0)) + 1;
  }
  $defaultMaNo = 'MA-' . $siteId . '-' . date('Ymd') . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

// ---------------- SUBMIT ----------------
$success = '';
$error = '';

$formMaDate = $todayYmd;
$formMaNo   = $defaultMaNo;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ma'])) {

  $site_id = (int)($_POST['site_id'] ?? 0);

  // Validate site assigned
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $site_id) { $okSite = true; break; }
  }
  if (!$okSite) $error = "Invalid site selection.";

  $ma_no   = trim((string)($_POST['ma_no'] ?? ''));
  $ma_date = trim((string)($_POST['ma_date'] ?? ''));

  $facilitator = trim((string)($_POST['facilitator'] ?? ''));
  $meeting_date_place = trim((string)($_POST['meeting_date_place'] ?? ''));
  $meeting_taken_by = trim((string)($_POST['meeting_taken_by'] ?? ''));

  $meeting_number = trim((string)($_POST['meeting_number'] ?? ''));
  $meeting_start_time = trim((string)($_POST['meeting_start_time'] ?? ''));
  $meeting_end_time = trim((string)($_POST['meeting_end_time'] ?? ''));

  // Objectives (3)
  $obj = $_POST['objective'] ?? [];
  $objRows = [];
  for ($i=0; $i<max(3, count($obj)); $i++){
    $objRows[] = ['text' => $obj[$i] ?? ''];
  }
  $objRows = jsonCleanRows($objRows);

  // Requested attendees (10)
  $att_name = $_POST['att_name'] ?? [];
  $att_firm = $_POST['att_firm'] ?? [];
  $attRows = [];
  $max = max(10, count($att_name), count($att_firm));
  for ($i=0; $i<$max; $i++){
    $attRows[] = [
      'name' => $att_name[$i] ?? '',
      'firm' => $att_firm[$i] ?? '',
    ];
  }
  $attRows = jsonCleanRows($attRows);

  // Discussions (6)
  $disc_topic = $_POST['disc_topic'] ?? [];
  $discRows = [];
  $max = max(6, count($disc_topic));
  for ($i=0; $i<$max; $i++){
    $discRows[] = [
      'topic' => $disc_topic[$i] ?? '',
    ];
  }
  $discRows = jsonCleanRows($discRows);

  // Actions (dynamic)
  $ac_desc = $_POST['ac_desc'] ?? [];
  $ac_person = $_POST['ac_person'] ?? [];
  $ac_due = $_POST['ac_due'] ?? [];
  $acRows = [];
  $max = max(count($ac_desc), count($ac_person), count($ac_due));
  for ($i=0; $i<$max; $i++){
    $acRows[] = [
      'description' => $ac_desc[$i] ?? '',
      'person' => $ac_person[$i] ?? '',
      'due' => $ac_due[$i] ?? '',
    ];
  }
  $acRows = jsonCleanRows($acRows);

  // Next meeting
  $next_meeting_date = trim((string)($_POST['next_meeting_date'] ?? ''));
  $next_meeting_start_time = trim((string)($_POST['next_meeting_start_time'] ?? ''));
  $next_meeting_end_time = trim((string)($_POST['next_meeting_end_time'] ?? ''));

  if ($error === '' && $site_id <= 0) $error = "Please choose a site.";
  if ($error === '' && $ma_no === '') $error = "MA No is required.";
  if ($error === '' && $ma_date === '') $error = "MA Date is required.";

  // Optional discipline: at least 1 objective
  if ($error === '' && empty($objRows)) $error = "Please enter at least one Meeting Objective.";

  if ($error === '') {
    $ins = mysqli_prepare($conn, "
      INSERT INTO ma_reports
      (site_id, employee_id, ma_no, ma_date,
       facilitator, meeting_date_place, meeting_taken_by,
       meeting_number, meeting_start_time, meeting_end_time,
       objectives_json, attendees_json, discussions_json, actions_json,
       next_meeting_date, next_meeting_start_time, next_meeting_end_time)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      $d = ymdOrNull($ma_date);
      $nmd = ymdOrNull($next_meeting_date);

      $objJson = !empty($objRows) ? json_encode($objRows, JSON_UNESCAPED_UNICODE) : null;
      $attJson = !empty($attRows) ? json_encode($attRows, JSON_UNESCAPED_UNICODE) : null;
      $discJson = !empty($discRows) ? json_encode($discRows, JSON_UNESCAPED_UNICODE) : null;
      $actJson = !empty($acRows) ? json_encode($acRows, JSON_UNESCAPED_UNICODE) : null;

      mysqli_stmt_bind_param(
        $ins,
        "iisssssssssssssss",
        $site_id, $employeeId, $ma_no, $d,
        $facilitator, $meeting_date_place, $meeting_taken_by,
        $meeting_number, $meeting_start_time, $meeting_end_time,
        $objJson, $attJson, $discJson, $actJson,
        $nmd, $next_meeting_start_time, $next_meeting_end_time
      );

      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to save MA: " . mysqli_stmt_error($ins);
      } else {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        header("Location: ma.php?site_id=".$site_id."&saved=1&ma_id=".$newId);
        exit;
      }
      mysqli_stmt_close($ins);
    }
  }

  // Keep posted values
  $formMaDate = $ma_date ?: $todayYmd;
  $formMaNo   = $ma_no ?: $defaultMaNo;
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "Meeting Agenda submitted successfully.";
}

// Recent MA
$recent = [];
$st = mysqli_prepare($conn, "
  SELECT r.id, r.ma_no, r.ma_date, s.project_name
  FROM ma_reports r
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

// Defaults
$pmcName = "M/s. UKB Construction Management Pvt Ltd";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Meeting Agenda (MA) - TEK-C</title>

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
    .btn-addrow{ border-radius: 12px; font-weight: 900; }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
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
            <h1 class="h-title">Meeting Agenda (MA)</h1>
            <p class="h-sub">Prepare and submit agenda before meeting</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill d-inline-flex align-items-center gap-2" style="border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-weight:900;font-size:12px;">
              <i class="bi bi-person"></i> <?php echo e($preparedBy); ?>
            </span>
            <span class="badge-pill d-inline-flex align-items-center gap-2" style="border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-weight:900;font-size:12px;">
              <i class="bi bi-award"></i> <?php echo e($empRow['designation'] ?? ($_SESSION['designation'] ?? '')); ?>
            </span>
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
              <p class="sec-sub mb-0">Choose site for Meeting Agenda</p>
            </div>
          </div>

          <div class="grid-2">
            <div>
              <label class="form-label">My Assigned Sites <span class="text-danger">*</span></label>
              <select class="form-select" id="sitePicker">
                <option value="">-- Select Site --</option>
                <?php foreach ($sites as $s): ?>
                  <?php $sid = (int)$s['id']; ?>
                  <option value="<?php echo $sid; ?>" <?php echo ($sid === $siteId ? 'selected' : ''); ?>>
                    <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?> (<?php echo e($s['client_name']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="small-muted mt-1">Selecting a site will load project details.</div>
            </div>

            <div class="d-flex align-items-end justify-content-end">
              <a class="btn btn-outline-secondary" href="ma.php" style="border-radius:12px; font-weight:900;">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </a>
            </div>
          </div>
        </div>

        <!-- FORM -->
        <form method="POST" autocomplete="off">
          <input type="hidden" name="submit_ma" value="1">
          <input type="hidden" name="site_id" value="<?php echo (int)$siteId; ?>">

          <!-- HEADER -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-journal-text"></i></div>
              <div>
                <p class="sec-title mb-0">MA Header</p>
                <p class="sec-sub mb-0">Project / Client / PMC / Date</p>
              </div>
            </div>

            <?php if (!$site): ?>
              <div class="text-muted" style="font-weight:800;">Please select a site above to load details.</div>
            <?php else: ?>
              <div class="grid-3">
                <div>
                  <label class="form-label">Project</label>
                  <input class="form-control" value="<?php echo e($site['project_name']); ?>" readonly>
                </div>
                <div>
                  <label class="form-label">Client</label>
                  <input class="form-control" value="<?php echo e($site['client_name']); ?>" readonly>
                </div>
                <div>
                  <label class="form-label">PMC</label>
                  <input class="form-control" value="<?php echo e($pmcName); ?>" readonly>
                </div>
              </div>

              <div class="grid-3 mt-2">
                <div>
                  <label class="form-label">MA No <span class="text-danger">*</span></label>
                  <input class="form-control" name="ma_no" value="<?php echo e($formMaNo); ?>" required>
                </div>
                <div>
                  <label class="form-label">Date <span class="text-danger">*</span></label>
                  <input type="date" class="form-control" name="ma_date" value="<?php echo e($formMaDate); ?>" required>
                </div>
                <div>
                  <label class="form-label">Prepared By</label>
                  <input class="form-control" value="<?php echo e($preparedBy); ?>" readonly>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- I. Meeting Schedule -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-calendar-event"></i></div>
              <div>
                <p class="sec-title mb-0">I. Meeting Schedule</p>
                <p class="sec-sub mb-0">Schedule details</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">Facilitator</label>
                <input class="form-control" name="facilitator" placeholder="e.g. PMC Lead / Manager">
              </div>
              <div>
                <label class="form-label">Meeting Number</label>
                <input class="form-control" name="meeting_number" placeholder="e.g. MA-01">
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">Meeting Date/Place</label>
                <input class="form-control" name="meeting_date_place" placeholder="e.g. 2026-02-13 / Site Office">
              </div>
              <div>
                <label class="form-label">Meeting Taken By</label>
                <input class="form-control" name="meeting_taken_by" value="<?php echo e($preparedBy); ?>" placeholder="Name">
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">Meeting Start Time</label>
                <input class="form-control" name="meeting_start_time" placeholder="e.g. 11:00 AM">
              </div>
              <div>
                <label class="form-label">Meeting End Time</label>
                <input class="form-control" name="meeting_end_time" placeholder="e.g. 12:00 PM">
              </div>
            </div>
          </div>

          <!-- II. Meeting Objectives -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-bullseye"></i></div>
              <div>
                <p class="sec-title mb-0">II. Meeting Objectives</p>
                <p class="sec-sub mb-0">Enter up to 3 objectives</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">1</label>
                <input class="form-control" name="objective[]" placeholder="Objective 1">
              </div>
              <div>
                <label class="form-label">2</label>
                <input class="form-control" name="objective[]" placeholder="Objective 2">
              </div>
            </div>
            <div class="mt-2">
              <label class="form-label">3</label>
              <input class="form-control" name="objective[]" placeholder="Objective 3">
            </div>
          </div>

          <!-- III. Requested Attendees -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-people"></i></div>
              <div>
                <p class="sec-title mb-0">III. Requested Attendees</p>
                <p class="sec-sub mb-0">Add up to 10 attendees</p>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr><th style="width:80px;">Sl.No.</th><th>Name</th><th>Firm</th></tr>
                </thead>
                <tbody>
                  <?php for ($i=1; $i<=10; $i++): ?>
                  <tr>
                    <td style="font-weight:900;"><?php echo $i; ?></td>
                    <td><input class="form-control" name="att_name[]" placeholder="Name"></td>
                    <td><input class="form-control" name="att_firm[]" placeholder="Firm"></td>
                  </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- IV. Discussions/Decisions Items -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-chat-left-text"></i></div>
              <div>
                <p class="sec-title mb-0">IV. Discussions/Decisions Items</p>
                <p class="sec-sub mb-0">Topics to discuss</p>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr><th style="width:80px;">Sl.No.</th><th>Topics</th></tr>
                </thead>
                <tbody>
                  <?php for ($i=1; $i<=6; $i++): ?>
                  <tr>
                    <td style="font-weight:900;"><?php echo $i; ?></td>
                    <td><input class="form-control" name="disc_topic[]" placeholder="Topic"></td>
                  </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- V. Action Assignments -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-list-task"></i></div>
                <div>
                  <p class="sec-title mb-0">V. Action Assignments</p>
                  <p class="sec-sub mb-0">Add action items</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addAction">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width:80px;">Sl.No.</th>
                    <th>Descriptions</th>
                    <th style="width:220px;">Person Responsible</th>
                    <th style="width:160px;">Due Date</th>
                    <th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="actionBody">
                  <tr>
                    <td class="slno" style="font-weight:900;">1</td>
                    <td><input class="form-control" name="ac_desc[]" placeholder="Action description"></td>
                    <td><input class="form-control" name="ac_person[]" placeholder="Responsible person"></td>
                    <td><input type="date" class="form-control" name="ac_due[]"></td>
                    <td class="text-center">
                      <button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="small-muted mt-2">Use “Add Row” to add multiple action items.</div>
          </div>

          <!-- VI. Next Meeting -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-arrow-repeat"></i></div>
              <div>
                <p class="sec-title mb-0">VI. Next Meeting</p>
                <p class="sec-sub mb-0">Next schedule</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">Meeting Date</label>
                <input type="date" class="form-control" name="next_meeting_date">
              </div>
              <div>
                <label class="form-label">Meeting Start Time</label>
                <input class="form-control" name="next_meeting_start_time" placeholder="e.g. 11:00 AM">
              </div>
              <div>
                <label class="form-label">Meeting End Time</label>
                <input class="form-control" name="next_meeting_end_time" placeholder="e.g. 12:00 PM">
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek" <?php echo ($siteId<=0 ? 'disabled' : ''); ?>>
                <i class="bi bi-check2-circle"></i> Submit MA
              </button>
            </div>

            <?php if ($siteId<=0): ?>
              <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to enable submit.</div>
            <?php endif; ?>
          </div>
        </form>

        <!-- RECENT MA -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Recent MA</p>
              <p class="sec-sub mb-0">Your last submissions</p>
            </div>
          </div>

          <?php if (empty($recent)): ?>
            <div class="text-muted" style="font-weight:800;">No MA submitted yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr><th>MA No</th><th>Date</th><th>Project</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo e($r['ma_no']); ?></td>
                      <td><?php echo e($r['ma_date']); ?></td>
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
      window.location.href = v ? ('ma.php?site_id=' + encodeURIComponent(v)) : 'ma.php';
    });
  }

  // Action row add/remove
  const body = document.getElementById('actionBody');

  function renumber(){
    if (!body) return;
    let n = 1;
    body.querySelectorAll('tr').forEach(tr => {
      const td = tr.querySelector('.slno');
      if (td) td.textContent = String(n++);
    });
  }

  document.getElementById('addAction')?.addEventListener('click', function(){
    if (!body) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="slno" style="font-weight:900;"></td>
      <td><input class="form-control" name="ac_desc[]" placeholder="Action description"></td>
      <td><input class="form-control" name="ac_person[]" placeholder="Responsible person"></td>
      <td><input type="date" class="form-control" name="ac_due[]"></td>
      <td class="text-center">
        <button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button>
      </td>
    `;
    body.appendChild(tr);
    renumber();
  });

  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('.delRow');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;

    // keep at least 1 row
    if (body && body.querySelectorAll('tr').length <= 1) {
      tr.querySelectorAll('input').forEach(i => i.value = '');
      return;
    }
    tr.remove();
    renumber();
  });

  renumber();
});
</script>

</body>
</html>
