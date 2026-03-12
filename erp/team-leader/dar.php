<?php
// dar.php — Daily Activity Report (DAR) submit (Simplified “Only Enough” version)
// ✅ Matches your DAR format: SL NO + Planned + Achieved + Planned for Tomorrow + Remarks
// ✅ Header fields: Division, Incharge, DAR No, Date
// ✅ Site selection + Project details kept (same style as DPR pages)
// ✅ Client contact hidden
// ✅ Default "Report Distribute To" includes: "Client - {client_name}"
// ✅ Dynamic rows (Add/Delete)
// ✅ Recent DAR list

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

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

function hasColumn(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return false;
  mysqli_stmt_bind_param($st, "ss", $table, $col);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $ok = (bool)mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
  return $ok;
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

// ---------------- Create DAR Table if Not Exists ----------------
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS dar_reports (
  id INT(11) NOT NULL AUTO_INCREMENT,
  site_id INT(11) NOT NULL,
  employee_id INT(11) NOT NULL,
  dar_no VARCHAR(50) NOT NULL,
  dar_date DATE NOT NULL,

  division VARCHAR(120) NULL,
  incharge VARCHAR(120) NULL,

  activities_json LONGTEXT NULL,

  report_distribute_to LONGTEXT NOT NULL,
  prepared_by VARCHAR(150) NOT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  KEY idx_dar_site (site_id),
  KEY idx_dar_employee (employee_id),
  KEY idx_dar_date (dar_date),
  CONSTRAINT fk_dar_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_dar_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Add missing columns safely (if you already had older table)
if (!hasColumn($conn, 'dar_reports', 'division')) {
  @mysqli_query($conn, "ALTER TABLE dar_reports ADD COLUMN division VARCHAR(120) NULL AFTER dar_date");
}
if (!hasColumn($conn, 'dar_reports', 'incharge')) {
  @mysqli_query($conn, "ALTER TABLE dar_reports ADD COLUMN incharge VARCHAR(120) NULL AFTER division");
}

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
        c.client_name,
        c.mobile_number AS client_mobile, c.email AS client_email
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

      $clientName = trim((string)($site['client_name'] ?? ''));
      $parts[] = ($clientName !== '') ? ('Client - ' . $clientName) : 'Client';

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

// ---------------- Default DAR No ----------------
$todayYmd = date('Y-m-d');
$defaultDarNo = '';

if ($siteId > 0) {
  $seq = 1;
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM dar_reports WHERE site_id=?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $siteId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    $seq = ((int)($row['cnt'] ?? 0)) + 1;
  }
  $defaultDarNo = 'DAR-' . $siteId . '-' . date('Ymd') . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

// ---------------- SUBMIT ----------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dar'])) {

  $site_id = (int)($_POST['site_id'] ?? 0);
  $dar_no  = trim((string)($_POST['dar_no'] ?? ''));
  $dar_date = trim((string)($_POST['dar_date'] ?? ''));

  $division = trim((string)($_POST['division'] ?? ''));
  $incharge = trim((string)($_POST['incharge'] ?? ''));

  $report_distribute_to = trim((string)($_POST['report_distribute_to'] ?? ''));

  // Validate site assigned
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $site_id) { $okSite = true; break; }
  }
  if (!$okSite) $error = "Invalid site selection.";

  if ($error === '' && $site_id <= 0) $error = "Please choose a site.";
  if ($error === '' && $dar_no === '') $error = "DAR No is required.";
  if ($error === '' && $dar_date === '') $error = "DAR Date is required.";
  if ($error === '' && $report_distribute_to === '') $error = "Report Distribute To is required.";

  // Activity rows (Planned / Achieved / Planned Tomorrow / Remarks)
  $rows = [];
  $planned = $_POST['ac_planned'] ?? [];
  $achieved = $_POST['ac_achieved'] ?? [];
  $tomorrow = $_POST['ac_tomorrow'] ?? [];
  $remarks = $_POST['ac_remarks'] ?? [];

  $max = max(count($planned), count($achieved), count($tomorrow), count($remarks));
  for ($i=0; $i<$max; $i++){
    $rows[] = [
      'planned' => $planned[$i] ?? '',
      'achieved' => $achieved[$i] ?? '',
      'tomorrow' => $tomorrow[$i] ?? '',
      'remarks' => $remarks[$i] ?? '',
    ];
  }
  $rows = jsonCleanRows($rows);

  if ($error === '' && empty($rows)) {
    $error = "Please enter at least one activity row.";
  }

  if ($error === '') {
    $activities_json = !empty($rows) ? json_encode($rows, JSON_UNESCAPED_UNICODE) : null;
    $darD = ymdOrNull($dar_date);

    $ins = mysqli_prepare($conn, "
      INSERT INTO dar_reports
      (site_id, employee_id, dar_no, dar_date, division, incharge, activities_json, report_distribute_to, prepared_by)
      VALUES (?,?,?,?,?,?,?,?,?)
    ");
    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "iisssssss",
        $site_id, $employeeId, $dar_no, $darD, $division, $incharge,
        $activities_json, $report_distribute_to, $preparedBy
      );
      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to save DAR: " . mysqli_stmt_error($ins);
      } else {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        header("Location: dar.php?site_id=".$site_id."&saved=1&dar_id=".$newId);
        exit;
      }
      mysqli_stmt_close($ins);
    }
  }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "DAR submitted successfully.";
}

// Recent DARs
$recent = [];
$st = mysqli_prepare($conn, "
  SELECT r.id, r.dar_no, r.dar_date, s.project_name
  FROM dar_reports r
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
$formDarNo  = $defaultDarNo;
$formDarDate = date('Y-m-d');

// default Division & Incharge (edit if you want different)
$defaultDivision = "QS Division";
$defaultIncharge = $preparedBy;

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
  <title>DAR - TEK-C</title>

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
      white-space: nowrap;
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
            <h1 class="h-title">Daily Activity Report (DAR)</h1>
            <p class="h-sub">Simple DAR format (Planned / Achieved / Tomorrow / Remarks)</p>
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
              <p class="sec-sub mb-0">Choose the site to prepare DAR</p>
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
              <a class="btn btn-outline-secondary" href="dar.php" style="border-radius:12px; font-weight:900;">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </a>
            </div>
          </div>
        </div>

        <!-- PROJECT DETAILS (Client contact hidden) -->
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

        <!-- DAR FORM -->
        <form method="POST" autocomplete="off">
          <input type="hidden" name="submit_dar" value="1">
          <input type="hidden" name="site_id" value="<?php echo (int)$formSiteId; ?>">

          <!-- DAR HEADER (like your image) -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-file-earmark-text"></i></div>
              <div>
                <p class="sec-title mb-0">DAR Header</p>
                <p class="sec-sub mb-0">Division / Incharge / DAR No / Date</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">Division</label>
                <input class="form-control" name="division" value="<?php echo e($defaultDivision); ?>" placeholder="e.g. QS Division">
              </div>
              <div>
                <label class="form-label">Incharge</label>
                <input class="form-control" name="incharge" value="<?php echo e($defaultIncharge); ?>" placeholder="e.g. Mr. Poovendiran">
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">DAR No <span class="text-danger">*</span></label>
                <input class="form-control" name="dar_no" value="<?php echo e($formDarNo); ?>" required>
              </div>
              <div>
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="dar_date" value="<?php echo e($formDarDate); ?>" required>
              </div>
            </div>
          </div>

          <!-- ACTIVITY TABLE (matches your sample image structure) -->
          <div class="panel">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
              <div class="sec-head mb-0" style="flex:1;">
                <div class="sec-ic"><i class="bi bi-list-check"></i></div>
                <div>
                  <p class="sec-title mb-0">Activity <span class="text-danger">*</span></p>
                  <p class="sec-sub mb-0">Planned / Achieved / Planned for Tomorrow / Remarks</p>
                </div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-addrow" id="addActivity">
                <i class="bi bi-plus-circle"></i> Add Row
              </button>
            </div>

            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width:80px;">SL NO</th>
                    <th>Planned</th>
                    <th style="width:160px;">Achieved</th>
                    <th style="min-width:240px;">Planned For Tomorrow</th>
                    <th style="min-width:220px;">Remarks</th>
                    <th style="width:70px;">Del</th>
                  </tr>
                </thead>
                <tbody id="activityBody">
                  <?php for ($i=0; $i<6; $i++): ?>
                  <tr class="ac-row">
                    <td class="text-center" style="font-weight:1000;"><span class="slno"><?php echo $i+1; ?></span></td>
                    <td><textarea class="form-control" name="ac_planned[]" rows="2" placeholder="Write planned activity..."></textarea></td>
                    <td>
                      <select class="form-select" name="ac_achieved[]">
                        <option value="">-- Select --</option>
                        <option value="COMPLETE">COMPLETE</option>
                        <option value="WIP">WIP</option>
                        <option value="PENDING">PENDING</option>
                      </select>
                    </td>
                    <td><textarea class="form-control" name="ac_tomorrow[]" rows="2" placeholder="Tomorrow plan..."></textarea></td>
                    <td><textarea class="form-control" name="ac_remarks[]" rows="2" placeholder="Remarks..."></textarea></td>
                    <td class="text-center">
                      <button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button>
                    </td>
                  </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>

            <div class="small-muted mt-2">
              Note: At least one row must be filled.
            </div>
          </div>

          <!-- REPORT DISTRIBUTION -->
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
                <input class="form-control" value="<?php echo e($preparedBy); ?>" readonly>
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek" <?php echo ($formSiteId<=0 ? 'disabled' : ''); ?>>
                <i class="bi bi-check2-circle"></i> Submit DAR
              </button>
            </div>

            <?php if ($formSiteId<=0): ?>
              <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to enable submit.</div>
            <?php endif; ?>
          </div>
        </form>

        <!-- RECENT DAR -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Recent DAR</p>
              <p class="sec-sub mb-0">Your last submissions</p>
            </div>
          </div>

          <?php if (empty($recent)): ?>
            <div class="text-muted" style="font-weight:800;">No DAR submitted yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr><th>DAR No</th><th>Date</th><th>Project</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo e($r['dar_no']); ?></td>
                      <td><?php echo e($r['dar_date']); ?></td>
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

  // Site change -> reload with site_id
  var picker = document.getElementById('sitePicker');
  if (picker) {
    picker.addEventListener('change', function(){
      var v = picker.value || '';
      window.location.href = v ? ('dar.php?site_id=' + encodeURIComponent(v)) : 'dar.php';
    });
  }

  function renumber(){
    document.querySelectorAll('#activityBody tr').forEach(function(tr, idx){
      var sl = tr.querySelector('.slno');
      if (sl) sl.textContent = String(idx + 1);
    });
  }

  function addRow(){
    const tb = document.getElementById('activityBody');
    if (!tb) return;
    const tr = document.createElement('tr');
    tr.className = 'ac-row';
    tr.innerHTML = `
      <td class="text-center" style="font-weight:1000;"><span class="slno"></span></td>
      <td><textarea class="form-control" name="ac_planned[]" rows="2" placeholder="Write planned activity..."></textarea></td>
      <td>
        <select class="form-select" name="ac_achieved[]">
          <option value="">-- Select --</option>
          <option value="COMPLETE">COMPLETE</option>
          <option value="WIP">WIP</option>
          <option value="PENDING">PENDING</option>
        </select>
      </td>
      <td><textarea class="form-control" name="ac_tomorrow[]" rows="2" placeholder="Tomorrow plan..."></textarea></td>
      <td><textarea class="form-control" name="ac_remarks[]" rows="2" placeholder="Remarks..."></textarea></td>
      <td class="text-center">
        <button type="button" class="btn btn-sm btn-outline-danger delRow"><i class="bi bi-trash"></i></button>
      </td>
    `;
    tb.appendChild(tr);
    renumber();
  }

  document.getElementById('addActivity')?.addEventListener('click', addRow);

  // Delete row (event delegation)
  document.addEventListener('click', function(ev){
    const btn = ev.target.closest('.delRow');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;

    const tb = tr.parentNode;
    const rows = tb ? tb.querySelectorAll('tr') : [];
    if (rows.length <= 1) {
      tr.querySelectorAll('input,select,textarea').forEach(el => el.value = '');
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

<?php
if (isset($conn)) { mysqli_close($conn); }
?>
