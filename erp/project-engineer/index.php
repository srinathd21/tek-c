<?php
// dashboard.php — Dynamic TEK-C Dashboard (DB-driven on every refresh)
// ✅ UPDATED:
// 1) Added "Quick Actions" panel (buttons)
// 2) Made ALL stats cards clickable and redirect to concerned pages
// 3) Kept mobile cards for Ongoing Projects + charts + recent activity

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
$sessionDesignation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return false;
  mysqli_stmt_bind_param($st, "ss", $table, $column);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $ok = (bool)mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
  return $ok;
}

function safeYmd($v){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return '';
  return $v;
}

function fmtDate($ymd){
  $ymd = safeYmd($ymd);
  if ($ymd === '') return '—';
  $ts = strtotime($ymd);
  return $ts ? date('d M Y', $ts) : e($ymd);
}

function projectHealthBadge($start, $end){
  $today = date('Y-m-d');
  $start = safeYmd($start);
  $end   = safeYmd($end);

  if ($start !== '' && $start > $today) {
    return ['Upcoming', 'delayed', 'bi-clock'];
  }
  if ($end !== '' && $end < $today) {
    return ['Delayed', 'delayed', 'bi-exclamation-triangle-fill'];
  }

  if ($end !== '') {
    $d1 = new DateTime($today);
    $d2 = new DateTime($end);
    $diff = (int)$d1->diff($d2)->format('%r%a');
    if ($diff >= 0 && $diff <= 7) {
      return ['At Risk', 'atrisk', 'bi-exclamation-circle-fill'];
    }
  }
  return ['On Track', 'ontrack', 'bi-check2-circle'];
}

function jsonDecodeSafe($s){
  if (!is_string($s) || trim($s) === '') return [];
  $d = json_decode($s, true);
  return is_array($d) ? $d : [];
}

function sumManpowerQtyFromJson($manpowerJson): int {
  $rows = jsonDecodeSafe($manpowerJson);
  $sum = 0;
  foreach ($rows as $r) {
    $qty = $r['qty'] ?? '';
    $n = preg_replace('/[^0-9.]/', '', (string)$qty);
    if ($n === '') continue;
    $val = (float)$n;
    if ($val > 0) $sum += (int)round($val);
  }
  return $sum;
}

function countOpenConstraintsFromJson($constraintsJson): int {
  $rows = jsonDecodeSafe($constraintsJson);
  $open = 0;
  foreach ($rows as $r) {
    $issue = trim((string)($r['issue'] ?? ''));
    $status = strtolower(trim((string)($r['status'] ?? '')));
    if ($issue === '') continue;
    if ($status === '' || $status === 'open') $open++;
  }
  return $open;
}

function activityInitial($name){
  $name = trim((string)$name);
  if ($name === '') return '👷';
  $parts = preg_split('/\s+/', $name);
  return strtoupper(substr($parts[0] ?? 'U', 0, 1));
}

function roleKeyFromEmployee(array $emp): string {
  $designation = strtolower(trim((string)($emp['designation'] ?? '')));
  $department  = strtolower(trim((string)($emp['department'] ?? '')));

  if (
    str_contains($designation, 'director') ||
    str_contains($designation, 'admin') ||
    str_contains($designation, 'administrator') ||
    str_contains($designation, 'vice president') ||
    str_contains($designation, 'general manager')
  ) return 'admin';

  if (
    str_contains($designation, 'hr') ||
    str_contains($department, 'hr') ||
    str_contains($department, 'human resource')
  ) return 'hr';

  if (str_contains($designation, 'manager')) return 'manager';

  if (
    str_contains($designation, 'team lead') ||
    str_contains($designation, 'tl') ||
    str_contains($designation, 'lead')
  ) return 'tl';

  if (
    str_contains($designation, 'project engineer') ||
    str_contains($designation, 'engineer')
  ) return 'project_engineer';

  return 'employee';
}

function isAdminLikeRole(string $roleKey): bool {
  return in_array($roleKey, ['admin', 'hr'], true);
}


// Helper: bind dynamic IN (...) params safely
function stmtBindDynamic(mysqli_stmt $st, string $types, array &$params): void {
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $v) {
    $bind[] = &$params[$k];
  }
  call_user_func_array('mysqli_stmt_bind_param', array_merge([$st], $bind));
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, username, photo, designation, department FROM employees WHERE id=? AND employee_status = 'active' LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$employeeName = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');
$designation = strtolower(trim((string)($empRow['designation'] ?? $sessionDesignation)));
$roleKey = roleKeyFromEmployee($empRow ?: ['designation' => $designation]);
$isAdminScope = isAdminLikeRole($roleKey);

// ---------------- Scope: Sites visible to this user ----------------
$hasTeamLeadCol = hasColumn($conn, 'sites', 'team_lead_employee_id');

$sites = [];
$sitesSql = "";
$bindTypes = "";
$bindVals = [];

if ($roleKey === 'manager') {
  $sitesSql = "
    SELECT
      s.id, s.project_name, s.project_location, s.project_type,
      s.start_date, s.expected_completion_date,
      c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.manager_employee_id = ?
      AND s.deleted_at IS NULL
    ORDER BY s.created_at DESC
  ";
  $bindTypes = "i";
  $bindVals = [$employeeId];
} elseif ($roleKey === 'tl' && $hasTeamLeadCol) {
  $sitesSql = "
    SELECT
      s.id, s.project_name, s.project_location, s.project_type,
      s.start_date, s.expected_completion_date,
      c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.team_lead_employee_id = ?
      AND s.deleted_at IS NULL
    ORDER BY s.created_at DESC
  ";
  $bindTypes = "i";
  $bindVals = [$employeeId];
} else {
  $sitesSql = "
    SELECT
      s.id, s.project_name, s.project_location, s.project_type,
      s.start_date, s.expected_completion_date,
      c.client_name
    FROM site_project_engineers spe
    INNER JOIN sites s ON s.id = spe.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE spe.employee_id = ?
      AND s.deleted_at IS NULL
    ORDER BY s.created_at DESC
  ";
  $bindTypes = "i";
  $bindVals = [$employeeId];
}

// Admin-like users: all sites
if ($isAdminScope) {
  $sitesSql = "
    SELECT
      s.id, s.project_name, s.project_location, s.project_type,
      s.start_date, s.expected_completion_date,
      c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.deleted_at IS NULL
    ORDER BY s.created_at DESC
  ";
  $bindTypes = "";
  $bindVals = [];
}

if ($sitesSql !== '') {
  $st = mysqli_prepare($conn, $sitesSql);
  if ($st) {
    if ($bindTypes !== '') {
      mysqli_stmt_bind_param($st, $bindTypes, ...$bindVals);
    }
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
}

$siteIds = array_values(array_unique(array_map(fn($x)=>(int)$x['id'], $sites)));
$todayYmd = date('Y-m-d');
$today = $todayYmd;

// ---------------- Stats: Active Projects ----------------
$activeProjects = 0;
foreach ($sites as $s) {
  $sd = safeYmd($s['start_date'] ?? '');
  $ed = safeYmd($s['expected_completion_date'] ?? '');
  $started = ($sd === '' || $sd <= $today);
  $notEnded = ($ed === '' || $ed >= $today);
  if ($started && $notEnded) $activeProjects++;
}

// ---------------- Approvals / Requests pending for this user ----------------
// leave_requests uses approver_id in current DB. Do NOT use leave_requests.manager_id.
$pendingLeaveApprovals = 0;
$pendingAttendanceRegularizations = 0;

if ($isAdminScope) {
  $sql = "SELECT COUNT(*) AS cnt FROM leave_requests WHERE status = 'Pending'";
  $res = mysqli_query($conn, $sql);
  if ($res) {
    $row = mysqli_fetch_assoc($res);
    $pendingLeaveApprovals = (int)($row['cnt'] ?? 0);
    mysqli_free_result($res);
  }

  $sql = "SELECT COUNT(*) AS cnt FROM attendance_regularization WHERE status = 'Pending'";
  $res = mysqli_query($conn, $sql);
  if ($res) {
    $row = mysqli_fetch_assoc($res);
    $pendingAttendanceRegularizations = (int)($row['cnt'] ?? 0);
    mysqli_free_result($res);
  }
} else {
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM leave_requests WHERE status = 'Pending' AND approver_id = ?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    $pendingLeaveApprovals = (int)($row['cnt'] ?? 0);
    mysqli_stmt_close($st);
  }

  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM attendance_regularization WHERE status = 'Pending' AND manager_id = ?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    $pendingAttendanceRegularizations = (int)($row['cnt'] ?? 0);
    mysqli_stmt_close($st);
  }
}


// ---------------- Today's DPR pending/completed (Me) ----------------
$todayDprBySite = [];
$latestMyDprCreatedAt = null;

$myCompleted = 0;
$myPending = 0;

if (!empty($siteIds)) {
  $ph = implode(',', array_fill(0, count($siteIds), '?'));
  $types = str_repeat('i', count($siteIds)) . "is";
  $params = array_merge($siteIds, [$employeeId, $todayYmd]);

  $sql = "
    SELECT id, site_id, dpr_no, created_at
    FROM dpr_reports
    WHERE site_id IN ($ph) AND employee_id = ? AND dpr_date = ?
    ORDER BY created_at DESC
  ";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    stmtBindDynamic($st, $types, $params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($res)) {
      $sid = (int)$row['site_id'];
      if (!isset($todayDprBySite[$sid])) $todayDprBySite[$sid] = $row;
      if ($latestMyDprCreatedAt === null && !empty($row['created_at'])) $latestMyDprCreatedAt = $row['created_at'];
    }
    mysqli_stmt_close($st);
  }

  foreach ($siteIds as $sid) {
    if (isset($todayDprBySite[(int)$sid])) $myCompleted++;
    else $myPending++;
  }
}

$completionPct = (count($siteIds) > 0) ? (int)round(($myCompleted / count($siteIds)) * 100) : 0;

// ---------------- Today Pending Tasks (ALL report types) ----------------
$reportTypes = [
  ['table' => 'dpr_reports',       'dateField' => 'dpr_date'],
  ['table' => 'dar_reports',       'dateField' => 'dar_date'],
  ['table' => 'checklist_reports', 'dateField' => 'checklist_date'],
  ['table' => 'ma_reports',        'dateField' => 'ma_date'],
  ['table' => 'mom_reports',       'dateField' => 'mom_date'],
  ['table' => 'mpt_reports',       'dateField' => 'mpt_date'],
];

$totalProjects = count($sites);
$totalTasksAll = $totalProjects * count($reportTypes);
$completedAll  = 0;

if (!empty($siteIds)) {
  $phSites = implode(',', array_fill(0, count($siteIds), '?'));

  foreach ($reportTypes as $rt) {
    $types  = str_repeat('i', count($siteIds)) . "is";
    $params = array_merge($siteIds, [$employeeId, $todayYmd]);

    $sql = "
      SELECT site_id
      FROM {$rt['table']}
      WHERE site_id IN ($phSites)
        AND employee_id = ?
        AND {$rt['dateField']} = ?
      GROUP BY site_id
    ";

    $st = mysqli_prepare($conn, $sql);
    if ($st) {
      stmtBindDynamic($st, $types, $params);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      while (mysqli_fetch_assoc($res)) $completedAll++;
      mysqli_stmt_close($st);
    }
  }
}
$pendingAll = max(0, $totalTasksAll - $completedAll);

// ---------------- Team stats: Workers + Alerts from today's latest DPR per site ----------------
$onSiteWorkers = 0;
$alerts = 0;
$teamDprToday = 0;

if (!empty($siteIds)) {
  $ph = implode(',', array_fill(0, count($siteIds), '?'));
  $types = str_repeat('i', count($siteIds)) . "s";
  $params = array_merge($siteIds, [$todayYmd]);

  $sql = "SELECT COUNT(*) AS cnt FROM dpr_reports WHERE site_id IN ($ph) AND dpr_date = ?";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    stmtBindDynamic($st, $types, $params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    $teamDprToday = (int)($row['cnt'] ?? 0);
    mysqli_stmt_close($st);
  }

  $sql = "
    SELECT site_id, manpower_json, constraints_json
    FROM dpr_reports
    WHERE site_id IN ($ph) AND dpr_date = ?
    ORDER BY created_at DESC
  ";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    stmtBindDynamic($st, $types, $params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);

    $seen = [];
    while ($r = mysqli_fetch_assoc($res)) {
      $sid = (int)$r['site_id'];
      if (isset($seen[$sid])) continue;
      $seen[$sid] = true;

      $onSiteWorkers += sumManpowerQtyFromJson($r['manpower_json'] ?? '');
      $alerts += countOpenConstraintsFromJson($r['constraints_json'] ?? '');
    }
    mysqli_stmt_close($st);
  }
}

// ---------------- Ongoing Projects (top 8 active) ----------------
$ongoingRows = [];
foreach ($sites as $s) {
  $sd = safeYmd($s['start_date'] ?? '');
  $ed = safeYmd($s['expected_completion_date'] ?? '');
  $started = ($sd === '' || $sd <= $today);
  $notEnded = ($ed === '' || $ed >= $today);
  if ($started && $notEnded) $ongoingRows[] = $s;
}
$ongoingRows = array_slice($ongoingRows, 0, 8);

// ---------------- Recent Activity (latest DPRs in scope) ----------------
$recent = [];
if (!empty($siteIds)) {
  $ph = implode(',', array_fill(0, count($siteIds), '?'));
  $types = str_repeat('i', count($siteIds));
  $params = $siteIds;

  $sql = "
    SELECT r.created_at, r.dpr_no, r.dpr_date, r.prepared_by, s.project_name
    FROM dpr_reports r
    INNER JOIN sites s ON s.id = r.site_id
    WHERE r.site_id IN ($ph)
    ORDER BY r.created_at DESC
    LIMIT 8
  ";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    stmtBindDynamic($st, $types, $params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $recent = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
}

// ---------------- Chart 1: DPR count last 7 days (team, scope sites) ----------------
$barLabels = [];
$barValues = [];
$days = 7;

$mapCounts = [];
for ($i = $days-1; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-$i day"));
  $mapCounts[$d] = 0;
}

if (!empty($siteIds)) {
  $startDate = date('Y-m-d', strtotime('-'.($days-1).' day'));
  $endDate = $todayYmd;

  $ph = implode(',', array_fill(0, count($siteIds), '?'));
  $types = str_repeat('i', count($siteIds)) . "ss";
  $params = array_merge($siteIds, [$startDate, $endDate]);

  $sql = "
    SELECT dpr_date, COUNT(*) AS cnt
    FROM dpr_reports
    WHERE site_id IN ($ph)
      AND dpr_date BETWEEN ? AND ?
    GROUP BY dpr_date
    ORDER BY dpr_date ASC
  ";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    stmtBindDynamic($st, $types, $params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($res)) {
      $d = (string)($row['dpr_date'] ?? '');
      if (isset($mapCounts[$d])) $mapCounts[$d] = (int)$row['cnt'];
    }
    mysqli_stmt_close($st);
  }
}

foreach ($mapCounts as $d => $cnt) {
  $barLabels[] = date('D', strtotime($d));
  $barValues[] = $cnt;
}

// ---------------- Chart 2: Today DPR status (Me) ----------------
$donutLabels = ['Completed', 'Pending'];
$donutValues = [$myCompleted, $myPending];
$latestMyDprTime = $latestMyDprCreatedAt ? date('h:i A', strtotime($latestMyDprCreatedAt)) : '—';

// ---------------- ROUTES (stats redirects + quick actions) ----------------
$routes = [
  // Stats redirects
  'active_projects' => 'my-sites.php',
  'dpr_pending'     => 'dpr.php?mode=pending&date='.$todayYmd,
  'today_tasks'     => 'today-tasks.php',
  'workers_alerts'  => 'report.php?tab=alerts&date='.$todayYmd,
  'leave_approvals' => 'leave-requests.php?status=pending',
  'reg_approvals'   => 'emp-regulation.php',

  // Quick actions - Modified as requested
  'qa_punch_in'     => 'punchin.php',
  'qa_today_tasks'  => 'today-tasks.php',
  'qa_reports'      => 'report.php',
  'qa_projects'     => 'my-sites.php',
  'qa_apply_leave'  => 'apply-leave.php',
  'qa_leave_req'    => 'leave-requests.php',
  'qa_emp_reg'      => 'emp-regulation.php',
  'qa_my_att'       => 'my-attendance.php',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TEK-C Dashboard</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    :root{
      --page-bg:#f5f7fb;
      --surface:#ffffff;
      --border:#e5e7eb;
      --text:#111827;
      --muted:#6b7280;
      --soft:#f8fafc;
      --shadow:0 10px 26px rgba(15,23,42,.055);
      --radius:15px;

      --blue:#2f80ed;
      --orange:#f2994a;
      --green:#27ae60;
      --red:#eb5757;
      --purple:#7c3aed;
      --yellow:#f2c94c;
    }

    body{ background:var(--page-bg); }

    .content-scroll{
      flex:1 1 auto;
      overflow:auto;
      padding:16px;
    }

    .projects-wrapper{
      width:100%;
    }

    .page-heading{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }

    .page-heading h1{
      font-size:19px;
      font-weight:900;
      color:var(--text);
      margin:0;
    }

    .page-heading p{
      margin:3px 0 0;
      color:var(--muted);
      font-size:12px;
      font-weight:600;
    }

    .primary-btn,
    .secondary-btn{
      min-height:36px;
      padding:0 14px;
      border-radius:11px;
      font-size:12px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:7px;
      text-decoration:none;
      white-space:nowrap;
    }

    .primary-btn{
      border:0;
      background:#111827;
      color:#fff;
    }

    .primary-btn:hover{
      background:#020617;
      color:#fff;
    }

    .secondary-btn{
      border:1px solid var(--border);
      background:#fff;
      color:#334155;
    }

    .secondary-btn:hover{
      border-color:#cbd5e1;
      background:#f8fafc;
      color:#111827;
    }

    .panel{
      background:var(--surface);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:13px;
      height:100%;
    }

    .panel-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:12px;
    }

    .panel-title{
      font-weight:900;
      font-size:14px;
      color:var(--text);
      margin:0;
    }

    .panel-subtitle{
      color:var(--muted);
      font-size:11px;
      font-weight:700;
      margin-top:2px;
    }

    .panel-menu{
      width:34px;
      height:34px;
      border-radius:11px;
      border:1px solid var(--border);
      background:#fff;
      display:grid;
      place-items:center;
      color:#64748b;
      flex:0 0 auto;
    }

    .panel-menu:hover{
      background:#f8fafc;
      color:#111827;
    }

    .stat-link{
      text-decoration:none;
      color:inherit;
      display:block;
    }

    .stat-card{
      background:var(--surface);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:12px 13px;
      min-height:86px;
      display:flex;
      align-items:center;
      gap:11px;
      transition:.15s ease;
    }

    .stat-link:hover .stat-card{
      border-color:#bfdbfe;
      box-shadow:0 14px 32px rgba(15,23,42,.09);
      transform:translateY(-1px);
    }

    .stat-ic{
      width:40px;
      height:40px;
      border-radius:12px;
      display:grid;
      place-items:center;
      color:#fff;
      font-size:18px;
      flex:0 0 auto;
    }

    .stat-ic.blue{ background:var(--blue); }
    .stat-ic.orange{ background:var(--orange); }
    .stat-ic.green{ background:var(--green); }
    .stat-ic.red{ background:var(--red); }
    .stat-ic.purple{ background:var(--purple); }

    .stat-label{
      color:var(--muted);
      font-weight:800;
      font-size:10.5px;
      text-transform:uppercase;
      letter-spacing:.2px;
    }

    .stat-value{
      font-size:25px;
      font-weight:950;
      line-height:1;
      margin-top:3px;
      color:var(--text);
    }

    .stat-hint{
      font-size:10.5px;
      color:#64748b;
      font-weight:800;
      margin-top:4px;
    }

    .qa-grid{
      display:grid;
      grid-template-columns:repeat(6, minmax(0, 1fr));
      gap:10px;
    }

    .qa-btn{
      text-decoration:none;
      border:1px solid var(--border);
      background:#fff;
      border-radius:14px;
      padding:11px;
      box-shadow:0 8px 18px rgba(15,23,42,.04);
      display:flex;
      align-items:center;
      gap:10px;
      color:#111827;
      font-weight:900;
      min-height:58px;
      transition:.15s ease;
    }

    .qa-btn:hover{
      background:#f8fafc;
      color:#111827;
      border-color:#cbd5e1;
      transform:translateY(-1px);
    }

    .qa-ic{
      width:36px;
      height:36px;
      border-radius:12px;
      display:grid;
      place-items:center;
      color:#fff;
      font-size:17px;
      flex:0 0 auto;
    }

    .qa-txt{
      display:flex;
      flex-direction:column;
      gap:2px;
      min-width:0;
    }

    .qa-title{
      font-size:12.5px;
      line-height:1.15;
      font-weight:950;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    .qa-sub{
      font-size:10.5px;
      font-weight:800;
      color:#64748b;
      line-height:1.15;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    .compact-table-wrap{
      width:100%;
      border:1px solid var(--border);
      border-radius:13px;
      overflow:hidden;
      background:#fff;
    }

    .compact-table{
      width:100%;
      margin:0;
      table-layout:auto;
    }

    .compact-table thead th{
      background:var(--soft);
      color:#64748b;
      font-size:10px;
      text-transform:uppercase;
      font-weight:900;
      border-bottom:1px solid var(--border)!important;
      padding:8px 9px;
    }

    .compact-table tbody td{
      padding:8px 9px;
      vertical-align:middle;
      border-color:#eef2f7;
      color:#334155;
      font-weight:700;
      font-size:11.5px;
    }

    .compact-table tbody tr:hover{
      background:#fbfdff;
    }

    .table-primary-text{
      color:#111827;
      font-size:11.5px;
      font-weight:950;
    }

    .table-secondary-text{
      color:#64748b;
      font-size:10px;
      font-weight:700;
      margin-top:2px;
    }

    .badge-pill{
      border-radius:999px;
      padding:5px 8px;
      font-weight:900;
      font-size:10px;
      border:1px solid transparent;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space:nowrap;
    }

    .badge-pill .mini-dot{
      width:6px;
      height:6px;
      border-radius:50%;
      background:currentColor;
    }

    .ontrack{ color:#15803d; background:#dcfce7; border-color:#bbf7d0; }
    .atrisk{ color:#b91c1c; background:#fee2e2; border-color:#fecaca; }
    .delayed{ color:#b45309; background:#ffedd5; border-color:#fed7aa; }
    .neutral{ color:#475569; background:#f1f5f9; border-color:#e2e8f0; }
    .pending{ color:#6d28d9; background:#ede9fe; border-color:#ddd6fe; }

    .muted-link{
      color:#64748b;
      font-weight:900;
      text-decoration:none;
      font-size:12px;
    }

    .muted-link:hover{
      color:#111827;
    }

    .activity-item{
      display:flex;
      gap:10px;
      padding:10px 0;
      border-top:1px solid #eef2f7;
    }

    .activity-item:first-child{
      border-top:0;
      padding-top:2px;
    }

    .activity-avatar{
      width:36px;
      height:36px;
      border-radius:12px;
      background:#111827;
      display:grid;
      place-items:center;
      font-weight:950;
      color:#fff;
      flex:0 0 auto;
      font-size:13px;
    }

    .activity-title{
      font-weight:900;
      margin:0;
      color:#111827;
      font-size:12px;
      line-height:1.35;
    }

    .activity-sub{
      margin:3px 0 0;
      color:#64748b;
      font-weight:700;
      font-size:10.5px;
    }

    .chart-wrap{
      height:190px;
    }

    .donut-wrap{
      height:230px;
    }

    .legend{
      display:flex;
      flex-wrap:wrap;
      gap:12px 20px;
      padding:6px 2px 4px;
      align-items:center;
    }

    .legend-item{
      display:flex;
      align-items:center;
      gap:7px;
      font-weight:800;
      color:#475569;
      font-size:11px;
    }

    .legend-dot{
      width:9px;
      height:9px;
      border-radius:50%;
      background:#999;
    }

    .p-card{
      border:1px solid var(--border);
      border-radius:14px;
      background:#fff;
      box-shadow:var(--shadow);
      padding:12px;
    }

    .p-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }

    .p-title{
      font-weight:950;
      color:#111827;
      font-size:13px;
      line-height:1.25;
      margin:0;
    }

    .p-sub{
      color:#64748b;
      font-weight:750;
      font-size:10.5px;
      margin-top:5px;
      line-height:1.35;
    }

    .p-kv{
      margin-top:10px;
      display:grid;
      gap:7px;
    }

    .p-row{
      display:flex;
      gap:10px;
      align-items:flex-start;
    }

    .p-key{
      flex:0 0 72px;
      color:#64748b;
      font-weight:900;
      font-size:10.5px;
      text-transform:uppercase;
    }

    .p-val{
      flex:1 1 auto;
      font-weight:900;
      color:#111827;
      font-size:11.5px;
      line-height:1.3;
    }

    .empty-state{
      text-align:center;
      padding:26px 12px;
      color:#64748b;
      font-size:12px;
      font-weight:900;
    }

    .empty-state i{
      display:block;
      font-size:32px;
      margin-bottom:8px;
      opacity:.45;
    }


    @media(max-width:1199px){
      .compact-table thead{ display:none; }
      .compact-table,
      .compact-table tbody,
      .compact-table tr,
      .compact-table td{
        display:block;
        width:100%;
      }
      .compact-table tbody tr{
        border-bottom:1px solid var(--border);
        padding:10px;
      }
      .compact-table tbody td{
        border:0;
        display:flex;
        justify-content:space-between;
        gap:12px;
      }
      .compact-table tbody td::before{
        content:attr(data-label);
        font-size:10px;
        font-weight:900;
        color:#64748b;
        text-transform:uppercase;
        flex:0 0 92px;
      }
      .compact-table tbody td:first-child{
        display:block;
      }
      .compact-table tbody td:first-child::before{
        display:none;
      }
    }

    @media(max-width:1199.98px){
      .qa-grid{
        grid-template-columns:repeat(3, minmax(0, 1fr));
      }
    }

    @media(max-width:991.98px){
      .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
      .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
      .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0)!important; }
      .qa-grid{ grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }

    @media(max-width:768px){
      .content-scroll{ padding:12px 10px 12px!important; }
      .container-fluid.projects-wrapper{ padding-left:0!important; padding-right:0!important; }
      .page-heading{ align-items:flex-start; flex-direction:column; }
      .panel{ padding:12px!important; margin-bottom:12px; border-radius:14px; }
      .stat-card{ min-height:78px; }
      .stat-value{ font-size:22px; }
      .qa-btn{ padding:10px; }
      .qa-grid{ gap:8px; }
    }
  </style>
</head>
<body>
  <div class="app">

    <?php include 'includes/sidebar.php'; ?>

    <main class="main" aria-label="Main">
      <?php include 'includes/topbar.php'; ?>

      <div id="contentScroll" class="content-scroll">
        <div class="container-fluid projects-wrapper px-0">

          <div class="page-heading">
            <div>
              <h1>Dashboard</h1>
              <p>Overview of your projects, reports, attendance and approvals.</p>
            </div>

            <div class="d-flex gap-2 flex-wrap">
              <span class="badge-pill neutral">
                <i class="bi bi-person-badge"></i>
                <?php echo e(strtoupper(str_replace('_', ' ', $roleKey))); ?>
              </span>

              <a href="<?php echo e($routes['qa_projects']); ?>" class="secondary-btn">
                <i class="bi bi-folder2"></i>
                Projects
              </a>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="panel mb-3">
            <div class="panel-header">
              <div>
                <h3 class="panel-title">Quick Actions</h3>
                <div class="panel-subtitle">
                  Role: <?php echo e(strtoupper(str_replace('_', ' ', $roleKey))); ?> • Leave approvals use approver_id
                </div>
              </div>
              <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
            </div>

            <div class="qa-grid">
              <a class="qa-btn" href="<?php echo e($routes['qa_punch_in']); ?>">
                <div class="qa-ic" style="background:#2d9cdb;"><i class="bi bi-fingerprint"></i></div>
                <div class="qa-txt">
                  <div class="qa-title">Punch In</div>
                  <div class="qa-sub">Mark attendance</div>
                </div>
              </a>

              <a class="qa-btn" href="<?php echo e($routes['qa_my_att']); ?>">
                <div class="qa-ic" style="background:#6366f1;"><i class="bi bi-calendar-check"></i></div>
                <div class="qa-txt">
                  <div class="qa-title">My Attendance</div>
                  <div class="qa-sub">Attendance profile</div>
                </div>
              </a>

              <a class="qa-btn" href="<?php echo e($routes['qa_apply_leave']); ?>">
                <div class="qa-ic" style="background:#10b981;"><i class="bi bi-calendar-plus"></i></div>
                <div class="qa-txt">
                  <div class="qa-title">Apply Leave</div>
                  <div class="qa-sub">Create request</div>
                </div>
              </a>

              <a class="qa-btn" href="<?php echo e($routes['qa_leave_req']); ?>">
                <div class="qa-ic" style="background:#ef4444;"><i class="bi bi-calendar2-x"></i></div>
                <div class="qa-txt">
                  <div class="qa-title">Leave Requests</div>
                  <div class="qa-sub"><?php echo (int)$pendingLeaveApprovals; ?> pending</div>
                </div>
              </a>

              <a class="qa-btn" href="<?php echo e($routes['qa_emp_reg']); ?>">
                <div class="qa-ic" style="background:#f59e0b;"><i class="bi bi-clock-history"></i></div>
                <div class="qa-txt">
                  <div class="qa-title">Emp Regulations</div>
                  <div class="qa-sub"><?php echo (int)$pendingAttendanceRegularizations; ?> pending</div>
                </div>
              </a>

              <a class="qa-btn" href="<?php echo e($routes['qa_projects']); ?>">
                <div class="qa-ic" style="background:#7c3aed;"><i class="bi bi-folder2"></i></div>
                <div class="qa-txt">
                  <div class="qa-title">Projects</div>
                  <div class="qa-sub">My sites</div>
                </div>
              </a>
            </div>
          </div>

          <!-- Stats (Now ALL clickable) -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
              <a class="stat-link" href="<?php echo e($routes['active_projects']); ?>">
                <div class="stat-card">
                  <div class="stat-ic blue"><i class="bi bi-folder2"></i></div>
                  <div>
                    <div class="stat-label">Active Projects</div>
                    <div class="stat-value"><?php echo (int)$activeProjects; ?></div>
                    <div class="stat-hint">Tap to view projects</div>
                  </div>
                </div>
              </a>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <a class="stat-link" href="<?php echo e($routes['dpr_pending']); ?>">
                <div class="stat-card">
                  <div class="stat-ic orange"><i class="bi bi-clock-history"></i></div>
                  <div>
                    <div class="stat-label">Today DPR Pending</div>
                    <div class="stat-value"><?php echo (int)$myPending; ?></div>
                    <div class="stat-hint">Completed: <?php echo (int)$myCompleted; ?> (<?php echo (int)$completionPct; ?>%)</div>
                  </div>
                </div>
              </a>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <a class="stat-link" href="<?php echo e($routes['leave_approvals']); ?>">
                <div class="stat-card">
                  <div class="stat-ic red"><i class="bi bi-calendar2-x"></i></div>
                  <div>
                    <div class="stat-label">Leave Approvals</div>
                    <div class="stat-value"><?php echo (int)$pendingLeaveApprovals; ?></div>
                    <div class="stat-hint">Pending for your approval</div>
                  </div>
                </div>
              </a>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <a class="stat-link" href="<?php echo e($routes['reg_approvals']); ?>">
                <div class="stat-card">
                  <div class="stat-ic green"><i class="bi bi-clock-history"></i></div>
                  <div>
                    <div class="stat-label">Attendance Requests</div>
                    <div class="stat-value"><?php echo (int)$pendingAttendanceRegularizations; ?></div>
                    <div class="stat-hint">Pending regularization approvals</div>
                  </div>
                </div>
              </a>
            </div>
          </div>

          <!-- Middle row -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Ongoing Projects</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>

                <!-- MOBILE: Cards - Open DPR button removed -->
                <div class="d-block d-md-none">
                  <?php if (empty($ongoingRows)): ?>
                    <div class="empty-state"><i class="bi bi-inbox"></i>No active projects found in your scope.</div>
                  <?php else: ?>
                    <div class="d-grid gap-3">
                      <?php foreach ($ongoingRows as $p): ?>
                        <?php [$label, $cls, $icon] = projectHealthBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? ''); ?>
                        <div class="p-card">
                          <div class="p-top">
                            <div style="flex:1 1 auto;">
                              <div class="p-title"><?php echo e($p['project_name'] ?? ''); ?></div>
                              <div class="p-sub">
                                <i class="bi bi-geo-alt"></i> <?php echo e($p['project_location'] ?? ''); ?>
                                &nbsp;•&nbsp; <i class="bi bi-person-badge"></i> <?php echo e($p['client_name'] ?? ''); ?>
                              </div>
                            </div>
                            <span class="badge-pill <?php echo e($cls); ?>">
                              <span class="mini-dot"></span> <?php echo e($label); ?>
                            </span>
                          </div>

                          <div class="p-kv">
                            <div class="p-row">
                              <div class="p-key">Start</div>
                              <div class="p-val"><?php echo e(fmtDate($p['start_date'] ?? '')); ?></div>
                            </div>
                            <div class="p-row">
                              <div class="p-key">End</div>
                              <div class="p-val"><?php echo e(fmtDate($p['expected_completion_date'] ?? '')); ?></div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- DESKTOP: Table - Open DPR link/icon removed -->
                <div class="d-none d-md-block">
                  <div class="compact-table-wrap">
                    <table class="table compact-table align-middle mb-0">
                      <thead>
                        <tr>
                          <th>Project Name</th>
                          <th>Status</th>
                          <th>Start Date</th>
                          <th>End Date</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($ongoingRows)): ?>
                          <tr>
                            <td colspan="4"><div class="empty-state"><i class="bi bi-inbox"></i>No active projects found in your scope.</div></td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($ongoingRows as $p): ?>
                            <?php [$label, $cls, $icon] = projectHealthBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? ''); ?>
                            <tr>
                              <td data-label="Project">
                                <div class="table-primary-text"><?php echo e($p['project_name'] ?? ''); ?></div>
                                <div class="table-secondary-text">
                                  <i class="bi bi-geo-alt"></i> <?php echo e($p['project_location'] ?? ''); ?>
                                  &nbsp;•&nbsp; <i class="bi bi-person-badge"></i> <?php echo e($p['client_name'] ?? ''); ?>
                                </div>
                              </td>
                              <td data-label="Status">
                                <span class="badge-pill <?php echo e($cls); ?>">
                                  <span class="mini-dot"></span> <?php echo e($label); ?>
                                </span>
                              </td>
                              <td data-label="Start"><?php echo e(fmtDate($p['start_date'] ?? '')); ?></td>
                              <td data-label="End"><?php echo e(fmtDate($p['expected_completion_date'] ?? '')); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>

              </div>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">DPR Overview (Last 7 Days)</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>
                <div class="chart-wrap">
                  <canvas id="barChart"></canvas>
                </div>
                <div style="margin-top:8px; font-size:12px; color:#6b7280; font-weight:900;">
                  Today total DPRs on your sites: <?php echo (int)$teamDprToday; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Bottom row -->
          <div class="row g-3 mb-4">
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Recent Activity</h3>
                  <a class="muted-link" href="report.php" style="font-size:12px;">Open reports</a>
                </div>

                <?php if (empty($recent)): ?>
                  <div class="text-muted" style="font-weight:900; padding:6px 0;">No recent DPR activity found.</div>
                <?php else: ?>
                  <?php foreach ($recent as $r): ?>
                    <div class="activity-item">
                      <div class="activity-avatar"><?php echo e(activityInitial($r['prepared_by'] ?? '')); ?></div>
                      <div class="flex-grow-1">
                        <p class="activity-title mb-0">
                          <?php echo e($r['prepared_by'] ?? ''); ?>
                          <span class="text-muted" style="font-weight:800;">submitted</span>
                          <?php echo e($r['dpr_no'] ?? 'DPR'); ?>
                          <span class="text-muted" style="font-weight:800;">for</span>
                          <?php echo e($r['project_name'] ?? ''); ?>
                        </p>
                        <p class="activity-sub">
                          DPR Date: <?php echo e(fmtDate($r['dpr_date'] ?? '')); ?>
                          &nbsp;•&nbsp; Created: <?php echo e($r['created_at'] ? date('d M Y, h:i A', strtotime($r['created_at'])) : '—'); ?>
                        </p>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Today DPR Status (Me)</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>

                <div class="donut-wrap">
                  <canvas id="donutChart"></canvas>
                </div>

                <div class="legend">
                  <div class="legend-item"><span class="legend-dot" style="background: rgba(39,174,96,.95);"></span> Completed</div>
                  <div class="legend-item"><span class="legend-dot" style="background: rgba(242,153,74,.95);"></span> Pending</div>
                </div>

                <div style="margin-top:6px; font-size:12px; color:#6b7280; font-weight:900;">
                  Latest my DPR time: <?php echo e($latestMyDprTime); ?>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <?php include 'includes/footer.php'; ?>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/sidebar-toggle.js"></script>

  <script>
    const BAR_LABELS = <?php echo json_encode($barLabels, JSON_UNESCAPED_UNICODE); ?>;
    const BAR_VALUES = <?php echo json_encode($barValues, JSON_UNESCAPED_UNICODE); ?>;

    const DONUT_LABELS = <?php echo json_encode($donutLabels, JSON_UNESCAPED_UNICODE); ?>;
    const DONUT_VALUES = <?php echo json_encode($donutValues, JSON_UNESCAPED_UNICODE); ?>;

    document.addEventListener('DOMContentLoaded', function () {
      Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      Chart.defaults.color = "#6b7280";

      const barCtx = document.getElementById("barChart");
      if (barCtx) {
        new Chart(barCtx, {
          type: "bar",
          data: {
            labels: BAR_LABELS,
            datasets: [{
              label: "DPRs",
              data: BAR_VALUES,
              backgroundColor: "rgba(242,201,76,.95)",
              borderRadius: 10,
              barThickness: 18
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
              x: { grid: { display: false }, ticks: { font: { weight: 700 } } },
              y: { beginAtZero: true, grid: { color: "rgba(233,236,239,1)" }, border: { display: false }, ticks: { stepSize: 1 } }
            }
          }
        });
      }

      const donutCtx = document.getElementById("donutChart");
      if (donutCtx) {
        new Chart(donutCtx, {
          type: "doughnut",
          data: {
            labels: DONUT_LABELS,
            datasets: [{
              data: DONUT_VALUES,
              backgroundColor: [
                "rgba(39,174,96,.95)",
                "rgba(242,153,74,.95)"
              ],
              borderWidth: 0,
              hoverOffset: 8
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "68%",
            plugins: { legend: { display: false } }
          },
          plugins: [{
            id: "centerText",
            afterDraw(chart){
              const { ctx } = chart;
              const meta = chart.getDatasetMeta(0);
              if(!meta?.data?.length) return;
              const x = meta.data[0].x;
              const y = meta.data[0].y;

              const total = DONUT_VALUES.reduce((a,b)=>a+b,0) || 0;
              const pct = total ? Math.round((DONUT_VALUES[0] / total) * 100) : 0;

              ctx.save();
              ctx.fillStyle = "#374151";
              ctx.textAlign = "center";
              ctx.textBaseline = "middle";
              ctx.font = "900 18px " + Chart.defaults.font.family;
              ctx.fillText(pct + "%", x, y - 6);
              ctx.font = "800 12px " + Chart.defaults.font.family;
              ctx.fillText("Completed", x, y + 14);
              ctx.restore();
            }
          }]
        });
      }
    });
  </script>

</body>
</html>

<?php
try { if (isset($conn) && $conn instanceof mysqli) $conn->close(); } catch (Throwable $e) { }
?>