<?php
// report.php — Unified Reports (DPR + DAR + MPT + MOM + MA + CHECKLIST)
// ✅ Role scope:
//    - Director / VP / GM  => all sites + all employees
//    - Manager             => only sites managed by the manager (all employees on those sites)
//    - Others (PE/TL/etc)  => only own reports
// ✅ Filters: Report Type, Site, Date range, Search
// ✅ Stats (same as today-tasks.php): Total Projects, DPR Completed (today by you), DPR Pending, Latest DPR Time
// ✅ View modal supports all report types listed above

session_start();

// if (!isset($_SESSION['success'])) {
//     $_SESSION['success'] = '';
// }

require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId     = (int)$_SESSION['employee_id'];
$designationRaw = (string)($_SESSION['designation'] ?? '');
$designation    = strtolower(trim($designationRaw));

// Allowed (expanded)
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

function fmtTime($ts){
  if (!$ts) return '—';
  $t = strtotime($ts);
  return $t ? date('h:i A', $t) : '—';
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

function monthName(int $m): string {
  $names = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
  return $names[$m] ?? 'Month';
}

function roleScope(string $designationLower): string {
  if (in_array($designationLower, ['director','vice president','general manager'], true)) return 'all';
  if ($designationLower === 'manager') return 'manager';
  return 'self';
}

$scope = roleScope($designation);

// Map per report type (table + columns)
function typeMeta(string $type): array {
  // return: [table, noCol, dateCol, preparedExpr, extra1Expr, extra2Expr, createdCol]
  // NOTE: we join sites s, clients c, employees e in every query
  switch ($type) {
    case 'dpr':
      return [
        'table' => 'dpr_reports',
        'no'    => 'r.dpr_no',
        'date'  => 'r.dpr_date',
        'prep'  => 'COALESCE(NULLIF(r.prepared_by,""), e.full_name)',
        'x1'    => 'r.weather',          // extra1
        'x2'    => 'r.site_condition',   // extra2
        'm1'    => 'NULL',               // mpt_month
        'm2'    => 'NULL',               // mpt_year
        'created' => 'r.created_at',
      ];
    case 'dar':
      return [
        'table' => 'dar_reports',
        'no'    => 'r.dar_no',
        'date'  => 'r.dar_date',
        'prep'  => 'COALESCE(NULLIF(r.prepared_by,""), e.full_name)',
        'x1'    => 'r.division',
        'x2'    => 'r.incharge',
        'm1'    => 'NULL',
        'm2'    => 'NULL',
        'created' => 'r.created_at',
      ];
    case 'mpt':
      return [
        'table' => 'mpt_reports',
        'no'    => 'r.mpt_no',
        'date'  => 'r.mpt_date',
        'prep'  => 'COALESCE(NULLIF(r.prepared_by,""), e.full_name)',
        'x1'    => 'NULL',
        'x2'    => 'NULL',
        'm1'    => 'r.mpt_month',
        'm2'    => 'r.mpt_year',
        'created' => 'r.created_at',
      ];
    case 'mom':
      return [
        'table' => 'mom_reports',
        'no'    => 'r.mom_no',
        'date'  => 'r.mom_date',
        'prep'  => 'COALESCE(NULLIF(r.prepared_by,""), e.full_name)',
        'x1'    => 'r.meeting_time',     // time
        'x2'    => 'r.meeting_held_at',  // place
        'm1'    => 'NULL',
        'm2'    => 'NULL',
        'created' => 'r.created_at',
      ];
    case 'ma':
      return [
        'table' => 'ma_reports',
        'no'    => 'r.ma_no',
        'date'  => 'r.ma_date',
        'prep'  => 'e.full_name',        // no prepared_by col in ma_reports
        'x1'    => 'r.meeting_number',
        'x2'    => 'r.facilitator',
        'm1'    => 'NULL',
        'm2'    => 'NULL',
        'created' => 'r.created_at',
      ];
    case 'checklist':
      return [
        'table' => 'checklist_reports',
        'no'    => 'r.doc_no',
        'date'  => 'r.checklist_date',
        'prep'  => 'e.full_name',
        'x1'    => 'r.project_engineer',
        'x2'    => 'r.pmc_lead',
        'm1'    => 'NULL',
        'm2'    => 'NULL',
        'created' => 'r.created_at',
      ];
    default:
      return [];
  }
}

// Build WHERE (prepared)
function buildWhere(string $type, string $scope, int $employeeId, int $filterSiteId, string $fromDate, string $toDate, string $search): array {
  // returns: [whereSql, types, params]
  $meta = typeMeta($type);
  if (!$meta) return [" WHERE 1=1 ", "", []];

  $where = " WHERE 1=1 ";
  $types = "";
  $params = [];

  // scope
  if ($scope === 'self') {
    $where .= " AND r.employee_id = ? ";
    $types .= "i";
    $params[] = $employeeId;
  } elseif ($scope === 'manager') {
    $where .= " AND r.site_id IN (SELECT id FROM sites WHERE manager_employee_id = ?) ";
    $types .= "i";
    $params[] = $employeeId;
  }

  // site filter
  if ($filterSiteId > 0) {
    $where .= " AND r.site_id = ? ";
    $types .= "i";
    $params[] = $filterSiteId;
  }

  // date
  $dateCol = $meta['date'];
  if ($fromDate !== '') {
    $where .= " AND $dateCol >= ? ";
    $types .= "s";
    $params[] = $fromDate;
  }
  if ($toDate !== '') {
    $where .= " AND $dateCol <= ? ";
    $types .= "s";
    $params[] = $toDate;
  }

  // search
  if ($search !== '') {
    $noCol   = $meta['no'];
    $prepCol = $meta['prep'];

    $where .= " AND (
      $noCol LIKE ?
      OR s.project_name LIKE ?
      OR s.project_location LIKE ?
      OR c.client_name LIKE ?
      OR $prepCol LIKE ?
    ) ";

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

// ---------------- GET SITES FOR DROPDOWN (and for stats) ----------------
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
$filterType   = strtolower(trim((string)($_GET['type'] ?? 'all'))); // all | dpr | dar | mpt | mom | ma | checklist
$filterSiteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$fromDate     = trim((string)($_GET['from'] ?? ''));
$toDate       = trim((string)($_GET['to'] ?? ''));
$search       = trim((string)($_GET['q'] ?? ''));

$validTypes = ['all','dpr','dar','mpt','mom','ma','checklist'];
if (!in_array($filterType, $validTypes, true)) $filterType = 'all';
if (!validYmd($fromDate)) $fromDate = '';
if (!validYmd($toDate))   $toDate = '';

// If user is manager/self, ensure site filter is within allowed sites
if ($filterSiteId > 0 && $scope !== 'all') {
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $filterSiteId) { $okSite = true; break; }
  }
  if (!$okSite) $filterSiteId = 0;
}

// ---------------- STATS (same as today-tasks.php) ----------------
$todayYmd = date('Y-m-d');

$todayDprBySite = []; // site_id => latest DPR row today for that site (by me)
$latestTodayDprCreatedAt = null;

$st = mysqli_prepare($conn, "
  SELECT id, site_id, dpr_no, created_at
  FROM dpr_reports
  WHERE employee_id = ? AND dpr_date = ?
  ORDER BY created_at DESC
");
if ($st) {
  mysqli_stmt_bind_param($st, "is", $employeeId, $todayYmd);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    $sid = (int)$row['site_id'];
    if (!isset($todayDprBySite[$sid])) {
      $todayDprBySite[$sid] = $row;
    }
    if ($latestTodayDprCreatedAt === null && !empty($row['created_at'])) {
      $latestTodayDprCreatedAt = $row['created_at'];
    }
  }
  mysqli_stmt_close($st);
}

$totalProjects  = count($sites);
$completedCount = 0;
$pendingCount   = 0;

foreach ($sites as $s) {
  $sid = (int)$s['id'];
  if (isset($todayDprBySite[$sid])) $completedCount++;
  else $pendingCount++;
}

$latestDprTime = fmtTime($latestTodayDprCreatedAt);

// ---------------- FETCH REPORTS (UNION) ----------------
$reports = [];
$parts = [];
$types = "";
$params = [];

$want = [
  'dpr'       => ($filterType === 'all' || $filterType === 'dpr'),
  'dar'       => ($filterType === 'all' || $filterType === 'dar'),
  'mpt'       => ($filterType === 'all' || $filterType === 'mpt'),
  'mom'       => ($filterType === 'all' || $filterType === 'mom'),
  'ma'        => ($filterType === 'all' || $filterType === 'ma'),
  'checklist' => ($filterType === 'all' || $filterType === 'checklist'),
];

function buildSelect(string $type, string $scope, int $employeeId, int $filterSiteId, string $fromDate, string $toDate, string $search, &$outTypes, &$outParams): string {
  $meta = typeMeta($type);
  [$w, $t, $p] = buildWhere($type, $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search);

  $outTypes  = $t;
  $outParams = $p;

  $label = strtoupper($type);
  if ($type === 'checklist') $label = 'CHECKLIST';

  // Standard columns across all selects
  return "
    SELECT
      '{$label}' AS report_type,
      r.id       AS report_id,
      r.site_id,
      r.employee_id,
      {$meta['no']}   AS report_no,
      {$meta['date']} AS report_date,
      {$meta['prep']} AS prepared_by,
      {$meta['x1']}   AS extra_1,
      {$meta['x2']}   AS extra_2,
      {$meta['m1']}   AS mpt_month,
      {$meta['m2']}   AS mpt_year,
      s.project_name,
      s.project_location,
      s.project_type,
      c.client_name,
      {$meta['created']} AS created_at
    FROM {$meta['table']} r
    INNER JOIN sites s   ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    INNER JOIN employees e ON e.id = r.employee_id
    $w
  ";
}

foreach (['dpr','dar','mpt','mom','ma','checklist'] as $tkey) {
  if (!$want[$tkey]) continue;
  $t = ""; $p = [];
  $sel = buildSelect($tkey, $scope, $employeeId, $filterSiteId, $fromDate, $toDate, $search, $t, $p);
  $parts[] = "($sel)";
  $types  .= $t;
  $params  = array_merge($params, $p);
}

if (!empty($parts)) {
  // Order by report_date, then created_at, then report_id
  $sql = implode(" UNION ALL ", $parts) . " ORDER BY report_date DESC, created_at DESC, report_id DESC";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) { die("SQL Error: " . mysqli_error($conn)); }
  if ($types !== '') mysqli_stmt_bind_param($st, $types, ...$params);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $reports = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- VIEW SINGLE REPORT (MODAL) ----------------
$viewId   = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewType = strtoupper(trim((string)($_GET['view_type'] ?? ''))); // DPR|DAR|MPT|MOM|MA|CHECKLIST
$allowedView = ['DPR','DAR','MPT','MOM','MA','CHECKLIST'];
if (!in_array($viewType, $allowedView, true)) $viewType = '';

$viewRow = null;

// DPR JSON
$mpRows = $mcRows = $mtRows = $wpRows = $csRows = [];
// DAR JSON
$darActRows = [];
// MPT JSON
$mptItems = [];
// MOM JSON
$momAgenda = $momAtt = $momMin = $momAmend = [];
// MA JSON
$maObjectives = $maAtt = $maDisc = $maActions = [];
// CHECKLIST JSON
$chkItems = [];

function scopeCond(string $scope, int $employeeId, string &$types, array &$params): string {
  $cond = "";
  if ($scope === 'self') {
    $cond = " AND r.employee_id = ? ";
    $types .= "i";
    $params[] = $employeeId;
  } elseif ($scope === 'manager') {
    $cond = " AND r.site_id IN (SELECT id FROM sites WHERE manager_employee_id = ?) ";
    $types .= "i";
    $params[] = $employeeId;
  }
  return $cond;
}

if ($viewId > 0 && $viewType !== '') {

  $typesOne = "i";
  $paramsOne = [$viewId];
  $cond = scopeCond($scope, $employeeId, $typesOne, $paramsOne);

  if ($viewType === 'DPR') {
    $sqlOne = "
      SELECT r.*, s.project_name, s.project_location, s.project_type, s.start_date, s.expected_completion_date,
             c.client_name, c.mobile_number AS client_mobile, c.email AS client_email
      FROM dpr_reports r
      INNER JOIN sites s ON s.id = r.site_id
      INNER JOIN clients c ON c.id = s.client_id
      WHERE r.id = ? $cond
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sqlOne);
    if ($st) {
      mysqli_stmt_bind_param($st, $typesOne, ...$paramsOne);
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

  } elseif ($viewType === 'DAR') {

    $sqlOne = "
      SELECT r.*, s.project_name, s.project_location, s.project_type, c.client_name
      FROM dar_reports r
      INNER JOIN sites s ON s.id = r.site_id
      INNER JOIN clients c ON c.id = s.client_id
      WHERE r.id = ? $cond
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sqlOne);
    if ($st) {
      mysqli_stmt_bind_param($st, $typesOne, ...$paramsOne);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $viewRow = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
    }
    if ($viewRow) {
      $darActRows = decodeJsonRows($viewRow['activities_json'] ?? '');
    }

  } elseif ($viewType === 'MPT') {

    $sqlOne = "
      SELECT r.*, s.project_name, s.project_location, s.project_type, c.client_name
      FROM mpt_reports r
      INNER JOIN sites s ON s.id = r.site_id
      INNER JOIN clients c ON c.id = s.client_id
      WHERE r.id = ? $cond
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sqlOne);
    if ($st) {
      mysqli_stmt_bind_param($st, $typesOne, ...$paramsOne);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $viewRow = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
    }
    if ($viewRow) {
      $mptItems = decodeJsonRows($viewRow['items_json'] ?? '');
    }

  } elseif ($viewType === 'MOM') {

    $sqlOne = "
      SELECT r.*, s.project_name, s.project_location, s.project_type, c.client_name
      FROM mom_reports r
      INNER JOIN sites s ON s.id = r.site_id
      INNER JOIN clients c ON c.id = s.client_id
      WHERE r.id = ? $cond
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sqlOne);
    if ($st) {
      mysqli_stmt_bind_param($st, $typesOne, ...$paramsOne);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $viewRow = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
    }
    if ($viewRow) {
      $momAgenda = decodeJsonRows($viewRow['agenda_json'] ?? '');
      $momAtt    = decodeJsonRows($viewRow['attendees_json'] ?? '');
      $momMin    = decodeJsonRows($viewRow['minutes_json'] ?? '');
      $momAmend  = decodeJsonRows($viewRow['amended_json'] ?? '');
    }

  } elseif ($viewType === 'MA') {

    $sqlOne = "
      SELECT r.*, s.project_name, s.project_location, s.project_type, c.client_name
      FROM ma_reports r
      INNER JOIN sites s ON s.id = r.site_id
      INNER JOIN clients c ON c.id = s.client_id
      WHERE r.id = ? $cond
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sqlOne);
    if ($st) {
      mysqli_stmt_bind_param($st, $typesOne, ...$paramsOne);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $viewRow = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
    }
    if ($viewRow) {
      $maObjectives = decodeJsonRows($viewRow['objectives_json'] ?? '');
      $maAtt        = decodeJsonRows($viewRow['attendees_json'] ?? '');
      $maDisc       = decodeJsonRows($viewRow['discussions_json'] ?? '');
      $maActions    = decodeJsonRows($viewRow['actions_json'] ?? '');
    }

  } elseif ($viewType === 'CHECKLIST') {

    $sqlOne = "
      SELECT r.*, s.project_name, s.project_location, s.project_type, c.client_name
      FROM checklist_reports r
      INNER JOIN sites s ON s.id = r.site_id
      INNER JOIN clients c ON c.id = s.client_id
      WHERE r.id = ? $cond
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sqlOne);
    if ($st) {
      mysqli_stmt_bind_param($st, $typesOne, ...$paramsOne);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $viewRow = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
    }
    if ($viewRow) {
      $chkItems = decodeJsonRows($viewRow['checklist_json'] ?? '');
    }
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

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px; color:#111827;
    }

    /* Stats (same as today-tasks) */
    .stat-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding:14px 16px;
      height:90px;
      display:flex;
      align-items:center;
      gap:14px;
    }
    .stat-ic{
      width:46px; height:46px;
      border-radius:14px;
      display:grid; place-items:center;
      color:#fff; font-size:20px;
      flex:0 0 auto;
    }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

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
    .section-pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      border:1px solid #e5e7eb;
      background:#f9fafb;
      border-radius:999px;
      padding:6px 10px;
      font-weight:1000;
      font-size:12px;
      margin:0 8px 8px 0;
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
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
            <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($designationRaw); ?></span>
          </div>
        </div>
<?php if (isset($_SESSION['success']) && $_SESSION['success']): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
    <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($_SESSION['success']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php 
  unset($_SESSION['success']); // Clear after displaying
endif; 
?>

        <!-- STATS (same as today-tasks.php) -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-building"></i></div>
              <div>
                <div class="stat-label">Total Projects</div>
                <div class="stat-value"><?php echo (int)$totalProjects; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
              <div>
                <div class="stat-label">DPR Completed</div>
                <div class="stat-value"><?php echo (int)$completedCount; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-hourglass-split"></i></div>
              <div>
                <div class="stat-label">DPR Pending</div>
                <div class="stat-value"><?php echo (int)$pendingCount; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-clock"></i></div>
              <div>
                <div class="stat-label">Latest DPR Time</div>
                <div class="stat-value" style="font-size:22px;"><?php echo e($latestDprTime); ?></div>
                <div class="small-muted">Today (<?php echo e($todayYmd); ?>)</div>
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
                <option value="mpt" <?php echo $filterType==='mpt'?'selected':''; ?>>MPT</option>
                <option value="mom" <?php echo $filterType==='mom'?'selected':''; ?>>MOM</option>
                <option value="ma" <?php echo $filterType==='ma'?'selected':''; ?>>MA</option>
                <option value="checklist" <?php echo $filterType==='checklist'?'selected':''; ?>>Checklist</option>
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
              <input type="text" class="form-control" name="q" placeholder="No / Project / Client / Name" value="<?php echo e($search); ?>">
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
                  <th>Info</th>
                  <th>Prepared By</th>
                  <th class="text-center" style="width:260px;">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($reports as $r): ?>
                <?php
                  $rtype = (string)($r['report_type'] ?? '');
                  $rid   = (int)($r['report_id'] ?? 0);

                  $extra = '—';
                  if ($rtype === 'DPR') {
                    $w  = trim((string)($r['extra_1'] ?? ''));
                    $sc = trim((string)($r['extra_2'] ?? ''));
                    $extra = ($w!=='' || $sc!=='') ? ($w . (($w!=='' && $sc!=='') ? ' / ' : '') . $sc) : '—';
                  } elseif ($rtype === 'DAR') {
                    $div = trim((string)($r['extra_1'] ?? ''));
                    $inc = trim((string)($r['extra_2'] ?? ''));
                    $extra = ($div!=='' || $inc!=='') ? ($div . (($div!=='' && $inc!=='') ? ' / ' : '') . $inc) : '—';
                  } elseif ($rtype === 'MPT') {
                    $mm = (int)($r['mpt_month'] ?? 0);
                    $yy = (int)($r['mpt_year'] ?? 0);
                    $extra = ($mm>=1 && $mm<=12 && $yy>0) ? (monthName($mm)." ".$yy) : '—';
                  } elseif ($rtype === 'MOM') {
                    $t = trim((string)($r['extra_1'] ?? ''));
                    $p = trim((string)($r['extra_2'] ?? ''));
                    $extra = ($t!=='' || $p!=='') ? ($t . (($t!=='' && $p!=='') ? ' @ ' : '') . $p) : '—';
                  } elseif ($rtype === 'MA') {
                    $mn = trim((string)($r['extra_1'] ?? ''));
                    $fc = trim((string)($r['extra_2'] ?? ''));
                    $extra = ($mn!=='' || $fc!=='') ? ("Meeting: ".$mn . ($fc!=='' ? (" / ".$fc) : "")) : '—';
                  } elseif ($rtype === 'CHECKLIST') {
                    $pe  = trim((string)($r['extra_1'] ?? ''));
                    $pmc = trim((string)($r['extra_2'] ?? ''));
                    $extra = ($pe!=='' || $pmc!=='') ? ($pe . (($pe!=='' && $pmc!=='') ? " / " : "") . $pmc) : '—';
                  }
                ?>
                <tr>
                  <td>
                    <span class="badge-type">
                      <?php if ($rtype === 'DPR'): ?>
                        <i class="bi bi-journal-text" style="color:#10b981;"></i> DPR
                      <?php elseif ($rtype === 'DAR'): ?>
                        <i class="bi bi-clipboard-check" style="color:#f59e0b;"></i> DAR
                      <?php elseif ($rtype === 'MPT'): ?>
                        <i class="bi bi-calendar2-check" style="color:#8b5cf6;"></i> MPT
                      <?php elseif ($rtype === 'MOM'): ?>
                        <i class="bi bi-people" style="color:#0ea5e9;"></i> MOM
                      <?php elseif ($rtype === 'MA'): ?>
                        <i class="bi bi-card-checklist" style="color:#64748b;"></i> MA
                      <?php else: ?>
                        <i class="bi bi-check2-square" style="color:#ef4444;"></i> CHECKLIST
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
                  <td><?php echo e($extra); ?></td>
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

                    <!-- Optional Print/Mail placeholders (you can create pages similarly) -->
                    <?php if ($rtype === 'DPR'): ?>
                      <a class="btn btn-sm btn-outline-dark" style="border-radius:10px; font-weight:900;"
                         href="report-print.php?view=<?php echo $rid; ?>" target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print
                      </a>
                    <?php elseif ($rtype === 'DAR'): ?>
                      <a class="btn btn-sm btn-outline-dark" style="border-radius:10px; font-weight:900;"
                         href="report-dar-print.php?view=<?php echo $rid; ?>" target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print
                      </a>
                    <?php elseif ($rtype === 'MPT'): ?>
                      <a class="btn btn-sm btn-outline-dark" style="border-radius:10px; font-weight:900;"
                         href="report-mpt-print.php?view=<?php echo $rid; ?>" target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print
                      </a>
                    <?php elseif ($rtype === 'MOM'): ?>
                      <a class="btn btn-sm btn-outline-dark" style="border-radius:10px; font-weight:900;"
                         href="report-mom-print.php?view=<?php echo $rid; ?>" target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print
                      </a>
                    <?php elseif ($rtype === 'MA'): ?>
                      <a class="btn btn-sm btn-outline-dark" style="border-radius:10px; font-weight:900;"
                         href="report-ma-print.php?view=<?php echo $rid; ?>" target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print
                      </a>
                    <?php else: /* CHECKLIST */ ?>
                      <a class="btn btn-sm btn-outline-dark" style="border-radius:10px; font-weight:900;"
                         href="report-checklist-print.php?view=<?php echo $rid; ?>" target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print
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
                  <thead><tr><th>Task</th><th>Duration</th><th>Start</th><th>End</th><th>Status</th><th>Reasons</th></tr></thead>
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
                  <thead><tr><th>Agency</th><th>Category</th><th>Unit</th><th>Qty</th><th>Remark</th></tr></thead>
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
                  <thead><tr><th>Equipment</th><th>Unit</th><th>Qty</th><th>Remark</th></tr></thead>
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
                  <thead><tr><th>Vendor</th><th>Material</th><th>Unit</th><th>Qty</th><th>Remark</th></tr></thead>
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
                  <thead><tr><th>Issue</th><th>Status</th><th>Date</th><th>Remark</th></tr></thead>
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

          <?php elseif ($viewType === 'DAR'): ?>

            <div class="fw-bold fs-5"><?php echo e($viewRow['dar_no']); ?></div>
            <div class="text-muted fw-bold"><?php echo e(safeDate($viewRow['dar_date'])); ?> • <?php echo e($viewRow['project_name']); ?></div>
            <hr>

            <div class="row g-3 mb-2">
              <div class="col-md-4"><div class="small-muted">Division</div><div class="fw-bold"><?php echo e($viewRow['division'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Incharge</div><div class="fw-bold"><?php echo e($viewRow['incharge'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Client</div><div class="fw-bold"><?php echo e($viewRow['client_name'] ?? '—'); ?></div></div>
            </div>

            <h6 class="fw-bold mt-3">Activity</h6>
            <?php if (empty($darActRows)): ?>
              <div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-bordered align-middle">
                  <thead><tr><th style="width:80px;">SL NO</th><th>Planned</th><th style="width:160px;">Achieved</th><th>Planned For Tomorrow</th><th>Remarks</th></tr></thead>
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
              <b>Report Distribute To:</b> <?php echo e($viewRow['report_distribute_to'] ?? ''); ?><br>
              <b>Prepared By:</b> <?php echo e($viewRow['prepared_by'] ?? ''); ?>
            </div>

          <?php elseif ($viewType === 'MPT'): ?>

            <?php
              $mm = (int)($viewRow['mpt_month'] ?? 0);
              $yy = (int)($viewRow['mpt_year'] ?? 0);
              $mptLabel = ($mm>=1 && $mm<=12 && $yy>0) ? (monthName($mm).' '.$yy) : '—';
              $handover = safeDate($viewRow['project_handover_date'] ?? '', '—');

              $sections = [
                'A' => 'DESIGN DELIVERABLE',
                'B' => 'VENDOR FINALIZATION',
                'C' => 'SITE WORKS',
                'D' => 'CLIENT DECISIONS',
              ];
              $bySection = ['A'=>[], 'B'=>[], 'C'=>[], 'D'=>[]];
              foreach ($mptItems as $it) {
                $sec = strtoupper(trim((string)($it['section'] ?? '')));
                if (!isset($bySection[$sec])) $sec = 'A';
                $bySection[$sec][] = $it;
              }
            ?>

            <div class="fw-bold fs-5"><?php echo e($viewRow['mpt_no']); ?></div>
            <div class="text-muted fw-bold"><?php echo e(safeDate($viewRow['mpt_date'])); ?> • <?php echo e($viewRow['project_name']); ?> • <?php echo e($mptLabel); ?></div>
            <hr>

            <div class="row g-3 mb-2">
              <div class="col-md-4"><div class="small-muted">Client</div><div class="fw-bold"><?php echo e($viewRow['client_name'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Project Hand Over</div><div class="fw-bold"><?php echo e($handover); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Prepared By</div><div class="fw-bold"><?php echo e($viewRow['prepared_by'] ?? '—'); ?></div></div>
            </div>

            <hr>

            <?php foreach ($sections as $code => $title): ?>
              <div class="section-pill"><i class="bi bi-list-check"></i> <?php echo e($code.' — '.$title); ?></div>

              <?php if (empty($bySection[$code])): ?>
                <div class="text-muted fw-bold mb-3">—</div>
              <?php else: ?>
                <div class="table-responsive mb-3">
                  <table class="table table-bordered align-middle">
                    <thead>
                      <tr>
                        <th style="width:80px;">SL.NO</th>
                        <th>PLANNED TASK</th>
                        <th style="width:160px;">RESPONSIBLE BY</th>
                        <th style="width:170px;">PLANNED DATE</th>
                        <th style="width:120px;">% PLANNED</th>
                        <th style="width:120px;">% ACTUAL</th>
                        <th style="width:120px;">STATUS</th>
                        <th>REMARKS</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php $i=1; foreach ($bySection[$code] as $it): ?>
                        <tr>
                          <td class="text-center" style="font-weight:1000;"><?php echo $i++; ?></td>
                          <td><?php echo e($it['planned_task'] ?? ''); ?></td>
                          <td><?php echo e($it['responsible_by'] ?? ''); ?></td>
                          <td><?php echo e($it['planned_completion_date'] ?? ''); ?></td>
                          <td><?php echo e($it['pct_planned'] ?? ''); ?></td>
                          <td><?php echo e($it['pct_actual'] ?? ''); ?></td>
                          <td style="font-weight:1000;"><?php echo e($it['status'] ?? ''); ?></td>
                          <td><?php echo e($it['remarks'] ?? ''); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>

          <?php elseif ($viewType === 'MOM'): ?>

            <div class="fw-bold fs-5"><?php echo e($viewRow['mom_no']); ?></div>
            <div class="text-muted fw-bold"><?php echo e(safeDate($viewRow['mom_date'])); ?> • <?php echo e($viewRow['project_name']); ?></div>
            <hr>

            <div class="row g-3 mb-2">
              <div class="col-md-4"><div class="small-muted">Architects</div><div class="fw-bold"><?php echo e($viewRow['architects'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Meeting Conducted By</div><div class="fw-bold"><?php echo e($viewRow['meeting_conducted_by'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Time / Place</div><div class="fw-bold"><?php echo e($viewRow['meeting_time'] ?? '—'); ?> • <?php echo e($viewRow['meeting_held_at'] ?? '—'); ?></div></div>
            </div>

            <h6 class="fw-bold mt-3">Agenda</h6>
            <?php if (empty($momAgenda)): ?><div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <ul class="mb-3">
                <?php foreach ($momAgenda as $a): ?>
                  <li style="font-weight:800;"><?php echo e($a['item'] ?? ''); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <h6 class="fw-bold">Attendees</h6>
            <?php if (empty($momAtt)): ?><div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead><tr><th>Stakeholder</th><th>Name</th><th>Designation</th><th>Firm</th></tr></thead>
                  <tbody>
                    <?php foreach ($momAtt as $a): ?>
                      <tr>
                        <td><?php echo e($a['stakeholder'] ?? ''); ?></td>
                        <td><?php echo e($a['name'] ?? ''); ?></td>
                        <td><?php echo e($a['designation'] ?? ''); ?></td>
                        <td><?php echo e($a['firm'] ?? ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold">Minutes</h6>
            <?php if (empty($momMin)): ?><div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead><tr><th>Discussion</th><th>Responsible</th><th>Deadline</th></tr></thead>
                  <tbody>
                    <?php foreach ($momMin as $m): ?>
                      <tr>
                        <td><?php echo e($m['discussion'] ?? ''); ?></td>
                        <td><?php echo e($m['responsible_by'] ?? ''); ?></td>
                        <td><?php echo e($m['deadline'] ?? ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold">Amended / Addendum</h6>
            <?php if (empty($momAmend)): ?><div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead><tr><th>Discussion</th><th>Responsible</th><th>Deadline</th></tr></thead>
                  <tbody>
                    <?php foreach ($momAmend as $m): ?>
                      <tr>
                        <td><?php echo e($m['discussion'] ?? ''); ?></td>
                        <td><?php echo e($m['responsible_by'] ?? ''); ?></td>
                        <td><?php echo e($m['deadline'] ?? ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <hr>
            <div class="small-muted">
              <b>Shared To:</b> <?php echo e($viewRow['mom_shared_to'] ?? ''); ?><br>
              <b>Copy To:</b> <?php echo e($viewRow['mom_copy_to'] ?? ''); ?><br>
              <b>Shared By:</b> <?php echo e($viewRow['mom_shared_by'] ?? ''); ?> • <b>On:</b> <?php echo e(safeDate($viewRow['mom_shared_on'] ?? '')); ?><br>
              <b>Next Meeting:</b> <?php echo e(safeDate($viewRow['next_meeting_date'] ?? '')); ?> • <?php echo e($viewRow['next_meeting_place'] ?? '—'); ?><br>
              <b>Prepared By:</b> <?php echo e($viewRow['prepared_by'] ?? ''); ?>
            </div>

          <?php elseif ($viewType === 'MA'): ?>

            <div class="fw-bold fs-5"><?php echo e($viewRow['ma_no']); ?></div>
            <div class="text-muted fw-bold"><?php echo e(safeDate($viewRow['ma_date'])); ?> • <?php echo e($viewRow['project_name']); ?></div>
            <hr>

            <div class="row g-3 mb-2">
              <div class="col-md-4"><div class="small-muted">Facilitator</div><div class="fw-bold"><?php echo e($viewRow['facilitator'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Meeting No</div><div class="fw-bold"><?php echo e($viewRow['meeting_number'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Time</div><div class="fw-bold"><?php echo e($viewRow['meeting_start_time'] ?? '—'); ?> - <?php echo e($viewRow['meeting_end_time'] ?? '—'); ?></div></div>
              <div class="col-md-6"><div class="small-muted">Meeting Date/Place</div><div class="fw-bold"><?php echo e($viewRow['meeting_date_place'] ?? '—'); ?></div></div>
              <div class="col-md-6"><div class="small-muted">Meeting Taken By</div><div class="fw-bold"><?php echo e($viewRow['meeting_taken_by'] ?? '—'); ?></div></div>
            </div>

            <h6 class="fw-bold mt-3">Objectives</h6>
            <?php if (empty($maObjectives)): ?><div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <ol class="mb-3">
                <?php foreach ($maObjectives as $o): ?>
                  <li style="font-weight:800;"><?php echo e($o['objective'] ?? $o['item'] ?? ''); ?></li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>

            <h6 class="fw-bold">Attendees</h6>
            <?php if (empty($maAtt)): ?><div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead><tr><th>SL</th><th>Name</th><th>Firm</th></tr></thead>
                  <tbody>
                    <?php $i=1; foreach ($maAtt as $a): ?>
                      <tr>
                        <td class="text-center" style="font-weight:1000;"><?php echo $i++; ?></td>
                        <td><?php echo e($a['name'] ?? ''); ?></td>
                        <td><?php echo e($a['firm'] ?? ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold">Discussions / Decisions</h6>
            <?php if (empty($maDisc)): ?><div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead><tr><th>SL</th><th>Topic</th></tr></thead>
                  <tbody>
                    <?php $i=1; foreach ($maDisc as $d): ?>
                      <tr>
                        <td class="text-center" style="font-weight:1000;"><?php echo $i++; ?></td>
                        <td><?php echo e($d['topic'] ?? $d['discussion'] ?? ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold">Action Assignments</h6>
            <?php if (empty($maActions)): ?><div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <div class="table-responsive mb-3">
                <table class="table table-bordered align-middle">
                  <thead><tr><th>SL</th><th>Description</th><th>Responsible</th><th>Due Date</th></tr></thead>
                  <tbody>
                    <?php $i=1; foreach ($maActions as $a): ?>
                      <tr>
                        <td class="text-center" style="font-weight:1000;"><?php echo $i++; ?></td>
                        <td><?php echo e($a['description'] ?? ''); ?></td>
                        <td><?php echo e($a['person_responsible'] ?? ''); ?></td>
                        <td><?php echo e($a['due_date'] ?? ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <hr>
            <div class="small-muted">
              <b>Next Meeting:</b> <?php echo e(safeDate($viewRow['next_meeting_date'] ?? '')); ?> •
              <?php echo e($viewRow['next_meeting_start_time'] ?? '—'); ?> - <?php echo e($viewRow['next_meeting_end_time'] ?? '—'); ?>
            </div>

          <?php else: /* CHECKLIST */ ?>

            <div class="fw-bold fs-5"><?php echo e($viewRow['doc_no']); ?></div>
            <div class="text-muted fw-bold"><?php echo e(safeDate($viewRow['checklist_date'])); ?> • <?php echo e($viewRow['project_name']); ?></div>
            <hr>

            <div class="row g-3 mb-2">
              <div class="col-md-4"><div class="small-muted">Project Engineer</div><div class="fw-bold"><?php echo e($viewRow['project_engineer'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">PMC Lead</div><div class="fw-bold"><?php echo e($viewRow['pmc_lead'] ?? '—'); ?></div></div>
              <div class="col-md-4"><div class="small-muted">Client</div><div class="fw-bold"><?php echo e($viewRow['client_name'] ?? '—'); ?></div></div>
            </div>

            <h6 class="fw-bold mt-3">Checklist Items</h6>
            <?php if (empty($chkItems)): ?>
              <div class="text-muted fw-bold">—</div>
            <?php else: ?>
              <?php
                $grouped = [];
                foreach ($chkItems as $it) {
                  $sec = (string)($it['section'] ?? 'General');
                  if (!isset($grouped[$sec])) $grouped[$sec] = [];
                  $grouped[$sec][] = $it;
                }
              ?>
              <?php foreach ($grouped as $sec => $items): ?>
                <div class="section-pill"><i class="bi bi-check2-square"></i> <?php echo e($sec); ?></div>
                <div class="table-responsive mb-3">
                  <table class="table table-bordered align-middle">
                    <thead><tr><th style="width:80px;">Done</th><th>Item</th></tr></thead>
                    <tbody>
                      <?php foreach ($items as $it): ?>
                        <tr>
                          <td class="text-center" style="font-weight:1000;">
                            <?php echo ((int)($it['checked'] ?? 0) === 1) ? '✓' : '—'; ?>
                          </td>
                          <td><?php echo e($it['label'] ?? ''); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

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
