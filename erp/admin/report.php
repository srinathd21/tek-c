<?php
// report.php — Unified Reports (DPR + DAR) with Stats + View Modal
// ✅ Shows ALL reports based on role scope:
//    - Director / VP / GM  => all sites + all employees
//    - Manager             => only sites managed by the manager (all employees on those sites)
//    - Others (PE/TL/etc)  => only own reports
// ✅ Filters: Report Type, Site, Date range, Search
// ✅ Stats cards: Total, DPR, DAR, Today, This Month
// ✅ View modal supports DPR + DAR
// ✅ Print/Mail buttons (you must create print/mail pages for DAR if not existing)
//
// Existing pages used for DPR:
//   - report-print.php?view=ID
//   - send-dpr-mail.php?view=ID
//
// Suggested pages for DAR (create these similarly):
//   - report-dar-print.php?view=ID
//   - send-dar-mail.php?view=ID

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
$designationRaw = (string)($_SESSION['designation'] ?? '');
$designation = strtolower(trim($designationRaw));

// Allowed (expanded for "all reports")
$allowed = [
  'project engineer grade 1',
  'project engineer grade 2',
  'sr. engineer',
  'team lead',
  'manager',
  'director',
  'vice president',
  'general manager',
];

if (!in_array($designation, $allowed, true)) {
  header("Location: index.php");
  exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function decodeJsonRows($json){
  $json = (string)$json;
  if (trim($json) === '') return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}

function validYmd($d){
  if ($d === '') return true;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
}

// Scope by role
function roleScope(string $designationLower): string {
  if (in_array($designationLower, ['director','vice president','general manager'], true)) return 'all';
  if ($designationLower === 'manager') return 'manager';
  return 'self';
}

// Build WHERE for DPR/DAR with correct columns
function buildWhere(string $type, string $scope, int $employeeId, int $filterSiteId, string $fromDate, string $toDate, string $search): array {
  // returns: [whereSql, types, params]
  // table alias is always r; site alias s
  $where = " WHERE 1=1 ";
  $types = "";
  $params = [];

  // scope
  if ($scope === 'self') {
    $where .= " AND r.employee_id = ? ";
    $types .= "i";
    $params[] = $employeeId;
  } elseif ($scope === 'manager') {
    // manager sees reports from sites they manage
    $where .= " AND r.site_id IN (SELECT id FROM sites WHERE manager_employee_id = ?) ";
    $types .= "i";
    $params[] = $employeeId;
  } // all => no filter

  // site filter
  if ($filterSiteId > 0) {
    $where .= " AND r.site_id = ? ";
    $types .= "i";
    $params[] = $filterSiteId;
  }

  // date filter
  if ($fromDate !== '') {
    $col = ($type === 'dpr') ? 'r.dpr_date' : 'r.dar_date';
    $where .= " AND $col >= ? ";
    $types .= "s";
    $params[] = $fromDate;
  }
  if ($toDate !== '') {
    $col = ($type === 'dpr') ? 'r.dpr_date' : 'r.dar_date';
    $where .= " AND $col <= ? ";
    $types .= "s";
    $params[] = $toDate;
  }

  // search
  if ($search !== '') {
    $noCol = ($type === 'dpr') ? 'r.dpr_no' : 'r.dar_no';
    $where .= " AND ($noCol LIKE ? OR s.project_name LIKE ? OR s.project_location LIKE ? OR c.client_name LIKE ? OR r.prepared_by LIKE ?) ";
    $like = "%".$search."%";
    $types .= "sssss";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
  }

  return [$where, $types, $params];
}

// ---------------- EMPLOYEE INFO ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, designation FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$employeeName = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');

$scope = roleScope($designation);

// ---------------- GET SITES FOR DROPDOWN ----------------
$sites = [];

if ($scope === 'all') {
  $q = "SELECT s.id, s.project_name, s.project_location
        FROM sites s
        ORDER BY s.created_at DESC";
  $r = mysqli_query($conn, $q);
  if ($r) { $sites = mysqli_fetch_all($r, MYSQLI_ASSOC); mysqli_free_result($r); }
} elseif ($scope === 'manager') {
  $q = "SELECT s.id, s.project_name, s.project_location
        FROM sites s
        WHERE s.manager_employee_id = ?
        ORDER BY s.created_at DESC";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
} else {
  $q = "SELECT DISTINCT s.id, s.project_name, s.project_location
        FROM site_project_engineers spe
        INNER JOIN sites s ON s.id = spe.site_id
        WHERE spe.employee_id = ?
        ORDER BY s.created_at DESC";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
}

// ---------------- FILTERS ----------------
$filterType  = strtolower(trim((string)($_GET['type'] ?? 'all'))); // all | dpr | dar
$filterSiteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate   = trim((string)($_GET['to'] ?? ''));
$search   = trim((string)($_GET['q'] ?? ''));

if (!in_array($filterType, ['all','dpr','dar'], true)) $filterType = 'all';
if (!validYmd($fromDate)) $fromDate = '';
if (!validYmd($toDate)) $toDate = '';

// If user is manager/self, ensure site filter is within allowed sites
if ($filterSiteId > 0 && $scope !== 'all') {
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $filterSiteId) { $okSite = true; break; }
  }
  if (!$okSite) $filterSiteId = 0;
}

// ---------------- STATS ----------------
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$stats = [
  'total' => 0,
  'dpr' => 0,
  'dar' => 0,
  'today' => 0,
  'month' => 0,
];

function countReports(mysqli $conn, string $type, string $scope, int $employeeId, int $filterSiteId, string $fromDate, string $toDate, string $search, ?string $extraDateEquals = null, ?array $extraDateBetween = null): int {
  [$where, $types, $params] = buildWhere($type, $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search);

  // extra filters (today / month)
  if ($extraDateEquals !== null) {
    $col = ($type === 'dpr') ? 'r.dpr_date' : 'r.dar_date';
    $where .= " AND $col = ? ";
    $types .= "s";
    $params[] = $extraDateEquals;
  }
  if (is_array($extraDateBetween) && count($extraDateBetween) === 2) {
    $col = ($type === 'dpr') ? 'r.dpr_date' : 'r.dar_date';
    $where .= " AND $col >= ? AND $col <= ? ";
    $types .= "ss";
    $params[] = $extraDateBetween[0];
    $params[] = $extraDateBetween[1];
  }

  if ($type === 'dpr') {
    $sql = "SELECT COUNT(*) AS cnt
            FROM dpr_reports r
            INNER JOIN sites s ON s.id = r.site_id
            INNER JOIN clients c ON c.id = s.client_id
            $where";
  } else {
    $sql = "SELECT COUNT(*) AS cnt
            FROM dar_reports r
            INNER JOIN sites s ON s.id = r.site_id
            INNER JOIN clients c ON c.id = s.client_id
            $where";
  }

  $st = mysqli_prepare($conn, $sql);
  if (!$st) return 0;
  if ($types !== '') mysqli_stmt_bind_param($st, $types, ...$params);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
  return (int)($row['cnt'] ?? 0);
}

$stats['dpr'] = countReports($conn, 'dpr', $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search);
$stats['dar'] = countReports($conn, 'dar', $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search);
$stats['total'] = $stats['dpr'] + $stats['dar'];

$todayDpr = countReports($conn, 'dpr', $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search, $today, null);
$todayDar = countReports($conn, 'dar', $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search, $today, null);
$stats['today'] = $todayDpr + $todayDar;

$monthDpr = countReports($conn, 'dpr', $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search, null, [$monthStart, $today]);
$monthDar = countReports($conn, 'dar', $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search, null, [$monthStart, $today]);
$stats['month'] = $monthDpr + $monthDar;

// ---------------- FETCH REPORTS (UNION DPR + DAR) ----------------
$dprSql = "";
$darSql = "";
$dprTypes = "";
$darTypes = "";
$dprParams = [];
$darParams = [];

$wantDpr = ($filterType === 'all' || $filterType === 'dpr');
$wantDar = ($filterType === 'all' || $filterType === 'dar');

if ($wantDpr) {
  [$w, $t, $p] = buildWhere('dpr', $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search);
  $dprTypes = $t;
  $dprParams = $p;

  $dprSql = "
    SELECT
      'DPR' AS report_type,
      r.id AS report_id,
      r.site_id,
      r.employee_id,
      r.dpr_no AS report_no,
      r.dpr_date AS report_date,
      r.prepared_by,
      r.weather,
      r.site_condition,
      s.project_name, s.project_location, s.project_type,
      c.client_name
    FROM dpr_reports r
    INNER JOIN sites s ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    $w
  ";
}

if ($wantDar) {
  [$w, $t, $p] = buildWhere('dar', $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search);
  $darTypes = $t;
  $darParams = $p;

  $darSql = "
    SELECT
      'DAR' AS report_type,
      r.id AS report_id,
      r.site_id,
      r.employee_id,
      r.dar_no AS report_no,
      r.dar_date AS report_date,
      r.prepared_by,
      NULL AS weather,
      NULL AS site_condition,
      s.project_name, s.project_location, s.project_type,
      c.client_name
    FROM dar_reports r
    INNER JOIN sites s ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    $w
  ";
}

$reports = [];
if ($wantDpr && $wantDar) {
  $sql = "($dprSql) UNION ALL ($darSql) ORDER BY report_date DESC, report_type ASC, report_id DESC";
  $types = $dprTypes . $darTypes;
  $params = array_merge($dprParams, $darParams);

  $st = mysqli_prepare($conn, $sql);
  if (!$st) { die("SQL Error: " . mysqli_error($conn)); }
  if ($types !== '') mysqli_stmt_bind_param($st, $types, ...$params);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $reports = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);

} elseif ($wantDpr) {
  $sql = $dprSql . " ORDER BY r.created_at DESC";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) { die("SQL Error: " . mysqli_error($conn)); }
  if ($dprTypes !== '') mysqli_stmt_bind_param($st, $dprTypes, ...$dprParams);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $reports = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);

} elseif ($wantDar) {
  $sql = $darSql . " ORDER BY r.created_at DESC";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) { die("SQL Error: " . mysqli_error($conn)); }
  if ($darTypes !== '') mysqli_stmt_bind_param($st, $darTypes, ...$darParams);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $reports = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- VIEW SINGLE REPORT (MODAL) ----------------
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewType = strtoupper(trim((string)($_GET['view_type'] ?? ''))); // DPR | DAR
if (!in_array($viewType, ['DPR','DAR'], true)) $viewType = '';

$viewRow = null;

$mpRows = $mcRows = $mtRows = $wpRows = $csRows = [];
$darActRows = [];

if ($viewId > 0 && $viewType === 'DPR') {
  // DPR view (same as your original, but scope-safe)
  $scopeCond = "";
  $types = "i";
  $params = [$viewId];

  if ($scope === 'self') {
    $scopeCond = " AND r.employee_id = ? ";
    $types .= "i";
    $params[] = $employeeId;
  } elseif ($scope === 'manager') {
    $scopeCond = " AND r.site_id IN (SELECT id FROM sites WHERE manager_employee_id = ?) ";
    $types .= "i";
    $params[] = $employeeId;
  }

  $sqlOne = "
    SELECT
      r.*,
      s.project_name, s.project_location, s.project_type, s.start_date, s.expected_completion_date,
      s.scope_of_work, s.authorized_signatory_name, s.authorized_signatory_contact,
      s.approval_authority,
      c.client_name,
      c.mobile_number AS client_mobile,
      c.email AS client_email
    FROM dpr_reports r
    INNER JOIN sites s ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE r.id = ?
    $scopeCond
    LIMIT 1
  ";
  $st = mysqli_prepare($conn, $sqlOne);
  if ($st) {
    mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $viewRow = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
  }

  if ($viewRow) {
    $mpRows = decodeJsonRows($viewRow['manpower_json'] ?? '');
    $mcRows = decodeJsonRows($viewRow['machinery_json'] ?? '');
    $mtRows = decodeJsonRows($viewRow['material_json'] ?? '');
    $wpRows = decodeJsonRows($viewRow['work_progress_json'] ?? '');
    $csRows = decodeJsonRows($viewRow['constraints_json'] ?? '');
  }

} elseif ($viewId > 0 && $viewType === 'DAR') {
  // DAR view
  $scopeCond = "";
  $types = "i";
  $params = [$viewId];

  if ($scope === 'self') {
    $scopeCond = " AND r.employee_id = ? ";
    $types .= "i";
    $params[] = $employeeId;
  } elseif ($scope === 'manager') {
    $scopeCond = " AND r.site_id IN (SELECT id FROM sites WHERE manager_employee_id = ?) ";
    $types .= "i";
    $params[] = $employeeId;
  }

  $sqlOne = "
    SELECT
      r.*,
      s.project_name, s.project_location, s.project_type,
      c.client_name
    FROM dar_reports r
    INNER JOIN sites s ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE r.id = ?
    $scopeCond
    LIMIT 1
  ";
  $st = mysqli_prepare($conn, $sqlOne);
  if ($st) {
    mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $viewRow = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
  }

  if ($viewRow) {
    $darActRows = decodeJsonRows($viewRow['activities_json'] ?? '');
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reports - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

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

    .stat-grid{ display:grid; grid-template-columns: repeat(5, 1fr); gap:12px; }
    @media (max-width: 1200px){ .stat-grid{ grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 576px){ .stat-grid{ grid-template-columns: 1fr; } }
    .stat-card{
      border:1px solid #e5e7eb;
      border-radius: 16px;
      background:#fff;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding:14px;
      display:flex;
      gap:12px;
      align-items:center;
      min-height:88px;
    }
    .stat-ic{
      width:44px;height:44px;border-radius:14px;
      display:grid;place-items:center;
      color:#fff;
      flex:0 0 auto;
      background: var(--blue);
    }
    .stat-label{ color:#6b7280; font-weight:900; font-size:12px; }
    .stat-value{ font-size:28px; font-weight:1000; line-height:1; color:#111827; }

    .form-label{ font-weight:900; color:#374151; font-size:13px; }
    .form-control, .form-select{
      border:2px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 12px;
      font-weight: 750;
      font-size: 14px;
    }
    .btn-tek{
      background: var(--blue);
      border:none;
      border-radius: 12px;
      padding: 10px 14px;
      font-weight: 1000;
      color:#fff;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
    }
    .btn-tek:hover{ background:#2a8bc9; color:#fff; }

    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:900;
      border-bottom:1px solid #e5e7eb!important;
      white-space: nowrap;
    }
    .table td{
      font-weight:800; color:#111827;
      vertical-align: top;
      word-break: break-word;
    }
    .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#f9fafb;
      font-weight:900; font-size:12px;
    }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
    .badge-type{
      font-weight:1000;
      border-radius:999px;
      padding:6px 10px;
      border:1px solid #e5e7eb;
      background:#fff;
      display:inline-flex;
      align-items:center;
      gap:8px;
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
            <h1 class="h-title">Reports</h1>
            <p class="h-sub">
              <?php if ($scope === 'all'): ?>
                Viewing all reports (All Sites • All Employees)
              <?php elseif ($scope === 'manager'): ?>
                Viewing all reports for your managed sites
              <?php else: ?>
                Viewing your submitted reports
              <?php endif; ?>
            </p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="chip"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
            <span class="chip"><i class="bi bi-award"></i> <?php echo e($designationRaw); ?></span>
          </div>
        </div>

        <!-- STATS -->
        <div class="panel">
          <div class="stat-grid">
            <div class="stat-card">
              <div class="stat-ic"><i class="bi bi-files"></i></div>
              <div>
                <div class="stat-label">Total Reports</div>
                <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
              </div>
            </div>

            <div class="stat-card">
              <div class="stat-ic" style="background: var(--green);"><i class="bi bi-journal-text"></i></div>
              <div>
                <div class="stat-label">DPR</div>
                <div class="stat-value"><?php echo (int)$stats['dpr']; ?></div>
              </div>
            </div>

            <div class="stat-card">
              <div class="stat-ic" style="background: var(--orange);"><i class="bi bi-clipboard-check"></i></div>
              <div>
                <div class="stat-label">DAR</div>
                <div class="stat-value"><?php echo (int)$stats['dar']; ?></div>
              </div>
            </div>

            <div class="stat-card">
              <div class="stat-ic" style="background: var(--red);"><i class="bi bi-calendar-day"></i></div>
              <div>
                <div class="stat-label">Today</div>
                <div class="stat-value"><?php echo (int)$stats['today']; ?></div>
              </div>
            </div>

            <div class="stat-card">
              <div class="stat-ic" style="background: #6b7280;"><i class="bi bi-calendar2-month"></i></div>
              <div>
                <div class="stat-label">This Month</div>
                <div class="stat-value"><?php echo (int)$stats['month']; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <div class="panel">
          <form class="row g-3" method="GET" action="report.php">

            <div class="col-md-2">
              <label class="form-label">Report Type</label>
              <select class="form-select" name="type">
                <option value="all" <?php echo $filterType==='all'?'selected':''; ?>>All</option>
                <option value="dpr" <?php echo $filterType==='dpr'?'selected':''; ?>>DPR</option>
                <option value="dar" <?php echo $filterType==='dar'?'selected':''; ?>>DAR</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label">Site</label>
              <select class="form-select" name="site_id">
                <option value="0">All Sites</option>
                <?php foreach ($sites as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === $filterSiteId) ? 'selected' : ''; ?>>
                    <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2">
              <label class="form-label">From</label>
              <input type="date" class="form-control" name="from" value="<?php echo e($fromDate); ?>">
            </div>

            <div class="col-md-2">
              <label class="form-label">To</label>
              <input type="date" class="form-control" name="to" value="<?php echo e($toDate); ?>">
            </div>

            <div class="col-md-2">
              <label class="form-label">Search</label>
              <input type="text" class="form-control" name="q" placeholder="No / Project / Client" value="<?php echo e($search); ?>">
            </div>

            <div class="col-12 d-flex justify-content-end gap-2">
              <a href="report.php" class="btn btn-outline-secondary" style="border-radius:12px; font-weight:900;">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </a>
              <button type="submit" class="btn-tek">
                <i class="bi bi-funnel"></i> Apply
              </button>
            </div>
          </form>
        </div>

        <!-- Table -->
        <div class="panel">
          <div class="table-responsive">
            <table id="reportTable" class="table table-bordered align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Type</th>
                  <th>Report No</th>
                  <th>Date</th>
                  <th>Project</th>
                  <th>Client</th>
                  <th>Weather / Condition</th>
                  <th>Prepared By</th>
                  <th class="text-center" style="width:260px;">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($reports as $r): ?>
                <?php
                  $rtype = (string)($r['report_type'] ?? '');
                  $rid   = (int)($r['report_id'] ?? 0);
                  $w = trim((string)($r['weather'] ?? ''));
                  $cnd = trim((string)($r['site_condition'] ?? ''));
                  $weatherText = ($w !== '' || $cnd !== '') ? ($w . ($w!=='' && $cnd!=='' ? ' / ' : '') . $cnd) : '—';
                ?>
                <tr>
                  <td>
                    <span class="badge-type">
                      <?php if ($rtype === 'DPR'): ?>
                        <i class="bi bi-journal-text" style="color: var(--green);"></i> DPR
                      <?php else: ?>
                        <i class="bi bi-clipboard-check" style="color: var(--orange);"></i> DAR
                      <?php endif; ?>
                    </span>
                  </td>

                  <td style="font-weight:1000;"><?php echo e($r['report_no']); ?></td>

                  <td><?php echo e(safeDate($r['report_date'])); ?></td>

                  <td>
                    <div style="font-weight:1000;"><?php echo e($r['project_name']); ?></div>
                    <div class="small-muted">
                      <i class="bi bi-geo-alt"></i> <?php echo e($r['project_location']); ?> • <?php echo e($r['project_type']); ?>
                    </div>
                  </td>

                  <td><?php echo e($r['client_name']); ?></td>

                  <td><?php echo e($weatherText); ?></td>

                  <td><?php echo e($r['prepared_by']); ?></td>

                  <td class="text-center">
                    <!-- View -->
                    <a class="btn btn-sm btn-outline-primary"
                       style="border-radius:10px; font-weight:900;"
                       href="report.php?<?php
                         $qs = $_GET;
                         $qs['view'] = $rid;
                         $qs['view_type'] = $rtype;
                         echo e(http_build_query($qs));
                       ?>">
                      <i class="bi bi-eye"></i> View
                    </a>

                    <?php if ($rtype === 'DPR'): ?>
                      <a class="btn btn-sm btn-outline-dark"
                         style="border-radius:10px; font-weight:900;"
                         href="report-print.php?view=<?php echo $rid; ?>"
                         target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print
                      </a>
                      <a class="btn btn-sm btn-outline-success"
                         style="border-radius:10px; font-weight:900;"
                         href="send-dpr-mail.php?view=<?php echo $rid; ?>"
                         onclick="return confirm('Send this DPR PDF to distribution emails?');">
                        <i class="bi bi-envelope"></i> Mail
                      </a>
                    <?php else: ?>
                      <a class="btn btn-sm btn-outline-dark"
                         style="border-radius:10px; font-weight:900;"
                         href="report-dar-print.php?view=<?php echo $rid; ?>"
                         target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print
                      </a>
                      <a class="btn btn-sm btn-outline-success"
                         style="border-radius:10px; font-weight:900;"
                         href="send-dar-mail.php?view=<?php echo $rid; ?>"
                         onclick="return confirm('Send this DAR PDF to distribution emails?');">
                        <i class="bi bi-envelope"></i> Mail
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:16px;">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Report View</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <?php if (!$viewRow): ?>
          <div class="text-muted fw-bold">No report selected.</div>

        <?php else: ?>

          <?php if ($viewType === 'DPR'): ?>

            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
              <div>
                <div class="fw-bold fs-5"><?php echo e($viewRow['dpr_no']); ?></div>
                <div class="text-muted fw-bold"><?php echo e(safeDate($viewRow['dpr_date'])); ?> • <?php echo e($viewRow['project_name']); ?></div>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary"
                   href="report-print.php?view=<?php echo (int)$viewRow['id']; ?>"
                   target="_blank" rel="noopener"
                   style="border-radius:12px; font-weight:900;">
                  <i class="bi bi-printer"></i> Print
                </a>
                <a class="btn btn-outline-success"
                   href="send-dpr-mail.php?view=<?php echo (int)$viewRow['id']; ?>"
                   style="border-radius:12px; font-weight:900;"
                   onclick="return confirm('Send this DPR PDF to distribution emails?');">
                  <i class="bi bi-envelope"></i> Send Mail
                </a>
              </div>
            </div>

            <hr>

            <div class="row g-3">
              <div class="col-md-4">
                <div class="small-muted">Client</div>
                <div class="fw-bold"><?php echo e($viewRow['client_name']); ?></div>
                <div class="small-muted"><?php echo e($viewRow['client_mobile'] ?? ''); ?></div>
                <div class="small-muted"><?php echo e($viewRow['client_email'] ?? ''); ?></div>
              </div>
              <div class="col-md-4">
                <div class="small-muted">Location</div>
                <div class="fw-bold"><?php echo e($viewRow['project_location']); ?></div>
                <div class="small-muted">Type: <?php echo e($viewRow['project_type']); ?></div>
              </div>
              <div class="col-md-4">
                <div class="small-muted">Project Dates</div>
                <div class="fw-bold">Start: <?php echo e(safeDate($viewRow['start_date'])); ?></div>
                <div class="fw-bold">End: <?php echo e(safeDate($viewRow['expected_completion_date'])); ?></div>
              </div>

              <div class="col-md-4">
                <div class="small-muted">Schedule</div>
                <div class="fw-bold">Start: <?php echo e(safeDate($viewRow['schedule_start'])); ?></div>
                <div class="fw-bold">End: <?php echo e(safeDate($viewRow['schedule_end'])); ?></div>
                <div class="fw-bold">Projected: <?php echo e(safeDate($viewRow['schedule_projected'])); ?></div>
              </div>

              <div class="col-md-4">
                <div class="small-muted">Duration (Total / Elapsed / Balance)</div>
                <div class="fw-bold">
                  <?php echo e((string)($viewRow['duration_total'] ?? '—')); ?> /
                  <?php echo e((string)($viewRow['duration_elapsed'] ?? '—')); ?> /
                  <?php echo e((string)($viewRow['duration_balance'] ?? '—')); ?>
                </div>
              </div>

              <div class="col-md-4">
                <div class="small-muted">Site</div>
                <div class="fw-bold">Weather: <?php echo e($viewRow['weather']); ?></div>
                <div class="fw-bold">Condition: <?php echo e($viewRow['site_condition']); ?></div>
              </div>
            </div>

            <hr>

            <h6 class="fw-bold">Work Progress</h6>
            <?php if (empty($wpRows)): ?>
              <div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th>Task</th><th>Duration</th><th>Start</th><th>End</th><th>Status</th><th>Reasons</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($wpRows as $rr): ?>
                    <tr>
                      <td><?php echo e($rr['task'] ?? ''); ?></td>
                      <td><?php echo e($rr['duration'] ?? ''); ?></td>
                      <td><?php echo e($rr['start'] ?? ''); ?></td>
                      <td><?php echo e($rr['end'] ?? ''); ?></td>
                      <td><?php echo e($rr['status'] ?? ''); ?></td>
                      <td><?php echo e($rr['reasons'] ?? ''); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold">Manpower</h6>
            <?php if (empty($mpRows)): ?>
              <div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th>Agency</th><th>Category</th><th>Unit</th><th>Qty</th><th>Remark</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($mpRows as $rr): ?>
                    <tr>
                      <td><?php echo e($rr['agency'] ?? ''); ?></td>
                      <td><?php echo e($rr['category'] ?? ''); ?></td>
                      <td><?php echo e($rr['unit'] ?? ''); ?></td>
                      <td><?php echo e($rr['qty'] ?? ''); ?></td>
                      <td><?php echo e($rr['remark'] ?? ''); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold">Machineries</h6>
            <?php if (empty($mcRows)): ?>
              <div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th>Equipment</th><th>Unit</th><th>Qty</th><th>Remark</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($mcRows as $rr): ?>
                    <tr>
                      <td><?php echo e($rr['equipment'] ?? ''); ?></td>
                      <td><?php echo e($rr['unit'] ?? ''); ?></td>
                      <td><?php echo e($rr['qty'] ?? ''); ?></td>
                      <td><?php echo e($rr['remark'] ?? ''); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold">Material</h6>
            <?php if (empty($mtRows)): ?>
              <div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th>Vendor</th><th>Material</th><th>Unit</th><th>Qty</th><th>Remark</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($mtRows as $rr): ?>
                    <tr>
                      <td><?php echo e($rr['vendor'] ?? ''); ?></td>
                      <td><?php echo e($rr['material'] ?? ''); ?></td>
                      <td><?php echo e($rr['unit'] ?? ''); ?></td>
                      <td><?php echo e($rr['qty'] ?? ''); ?></td>
                      <td><?php echo e($rr['remark'] ?? ''); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold">Constraints</h6>
            <?php if (empty($csRows)): ?>
              <div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-2">
                <table class="table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th>Issue</th><th>Status</th><th>Date</th><th>Remark</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($csRows as $rr): ?>
                    <tr>
                      <td><?php echo e($rr['issue'] ?? ''); ?></td>
                      <td><?php echo e($rr['status'] ?? ''); ?></td>
                      <td><?php echo e($rr['date'] ?? ''); ?></td>
                      <td><?php echo e($rr['remark'] ?? ''); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <hr>
            <div class="small-muted">
              <b>Report Distribute To:</b> <?php echo e($viewRow['report_distribute_to'] ?? ''); ?><br>
              <b>Prepared By:</b> <?php echo e($viewRow['prepared_by'] ?? ''); ?>
            </div>

          <?php else: /* DAR */ ?>

            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
              <div>
                <div class="fw-bold fs-5"><?php echo e($viewRow['dar_no']); ?></div>
                <div class="text-muted fw-bold"><?php echo e(safeDate($viewRow['dar_date'])); ?> • <?php echo e($viewRow['project_name']); ?></div>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary"
                   href="report-dar-print.php?view=<?php echo (int)$viewRow['id']; ?>"
                   target="_blank" rel="noopener"
                   style="border-radius:12px; font-weight:900;">
                  <i class="bi bi-printer"></i> Print
                </a>
                <a class="btn btn-outline-success"
                   href="send-dar-mail.php?view=<?php echo (int)$viewRow['id']; ?>"
                   style="border-radius:12px; font-weight:900;"
                   onclick="return confirm('Send this DAR PDF to distribution emails?');">
                  <i class="bi bi-envelope"></i> Send Mail
                </a>
              </div>
            </div>

            <hr>

            <div class="row g-3 mb-2">
              <div class="col-md-4">
                <div class="small-muted">Division</div>
                <div class="fw-bold"><?php echo e($viewRow['division'] ?? '—'); ?></div>
              </div>
              <div class="col-md-4">
                <div class="small-muted">Incharge</div>
                <div class="fw-bold"><?php echo e($viewRow['incharge'] ?? '—'); ?></div>
              </div>
              <div class="col-md-4">
                <div class="small-muted">Client</div>
                <div class="fw-bold"><?php echo e($viewRow['client_name'] ?? '—'); ?></div>
              </div>
              <div class="col-md-6">
                <div class="small-muted">Project</div>
                <div class="fw-bold"><?php echo e($viewRow['project_name'] ?? '—'); ?></div>
                <div class="small-muted"><i class="bi bi-geo-alt"></i> <?php echo e($viewRow['project_location'] ?? ''); ?></div>
              </div>
              <div class="col-md-6">
                <div class="small-muted">Prepared By</div>
                <div class="fw-bold"><?php echo e($viewRow['prepared_by'] ?? '—'); ?></div>
              </div>
            </div>

            <h6 class="fw-bold mt-3">Activity</h6>
            <?php if (empty($darActRows)): ?>
              <div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th style="width:80px;">SL NO</th>
                      <th>Planned</th>
                      <th style="width:160px;">Achieved</th>
                      <th>Planned For Tomorrow</th>
                      <th>Remarks</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php $i=1; foreach ($darActRows as $rr): ?>
                    <tr>
                      <td class="text-center" style="font-weight:1000;"><?php echo $i++; ?></td>
                      <td><?php echo nl2br(e($rr['planned'] ?? '')); ?></td>
                      <td class="text-center" style="font-weight:1000;"><?php echo e($rr['achieved'] ?? ''); ?></td>
                      <td><?php echo nl2br(e($rr['tomorrow'] ?? '')); ?></td>
                      <td><?php echo nl2br(e($rr['remarks'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <hr>
            <div class="small-muted">
              <b>Report Distribute To:</b> <?php echo e($viewRow['report_distribute_to'] ?? ''); ?>
            </div>

          <?php endif; ?>

        <?php endif; ?>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:12px; font-weight:900;">
          Close
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
  $(function(){
    $('#reportTable').DataTable({
      responsive: true,
      pageLength: 10,
      order: [[2,'desc']], // Date desc
      columnDefs: [{ targets:[7], orderable:false, searchable:false }],
      language: {
        zeroRecords: "No reports found",
        info: "Showing _START_ to _END_ of _TOTAL_ reports",
        infoEmpty: "No reports to show",
        lengthMenu: "Show _MENU_",
        search: "Search:"
      }
    });
  });

  document.addEventListener('DOMContentLoaded', function(){
    const hasView = "<?php echo $viewRow ? '1' : '0'; ?>";
    if (hasView === '1') {
      const modal = new bootstrap.Modal(document.getElementById('viewModal'));
      modal.show();
    }
  });
</script>

</body>
</html>

<?php
// ✅ SAFE CLOSE
try {
  if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
  }
} catch (Throwable $e) { }
?>
