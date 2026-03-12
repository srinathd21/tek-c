<?php
// dashboard.php — Dynamic TEK-C Dashboard (DB-driven on every refresh)

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

  // simple "risk" heuristic: if close to end date (<7 days), mark At Risk
  if ($end !== '') {
    $d1 = new DateTime($today);
    $d2 = new DateTime($end);
    $diff = (int)$d1->diff($d2)->format('%r%a'); // days to end (can be negative)
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
    // keep only numbers
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

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, designation, department FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$employeeName = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');

// ---------------- Scope: Sites visible to this user ----------------
$hasTeamLeadCol = hasColumn($conn, 'sites', 'team_lead_employee_id');

$sites = [];
$sitesSql = "";
$bindTypes = "";
$bindVals = [];

if ($designation === 'manager') {
  $sitesSql = "
    SELECT
      s.id, s.project_name, s.project_location, s.project_type,
      s.start_date, s.expected_completion_date,
      c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.manager_employee_id = ?
    ORDER BY s.created_at DESC
  ";
  $bindTypes = "i";
  $bindVals = [$employeeId];
} elseif ($designation === 'team lead' && $hasTeamLeadCol) {
  $sitesSql = "
    SELECT
      s.id, s.project_name, s.project_location, s.project_type,
      s.start_date, s.expected_completion_date,
      c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.team_lead_employee_id = ?
    ORDER BY s.created_at DESC
  ";
  $bindTypes = "i";
  $bindVals = [$employeeId];
} else {
  // default: engineer scope (site_project_engineers)
  $sitesSql = "
    SELECT
      s.id, s.project_name, s.project_location, s.project_type,
      s.start_date, s.expected_completion_date,
      c.client_name
    FROM site_project_engineers spe
    INNER JOIN sites s ON s.id = spe.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE spe.employee_id = ?
    ORDER BY s.created_at DESC
  ";
  $bindTypes = "i";
  $bindVals = [$employeeId];
}

// If admin-like users should see everything:
if (in_array($designation, ['director','vice president','general manager','hr','accountant'], true)) {
  $sitesSql = "
    SELECT
      s.id, s.project_name, s.project_location, s.project_type,
      s.start_date, s.expected_completion_date,
      c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
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

// ---------------- Stats: Active Projects ----------------
$today = date('Y-m-d');
$activeProjects = 0;
foreach ($sites as $s) {
  $sd = safeYmd($s['start_date'] ?? '');
  $ed = safeYmd($s['expected_completion_date'] ?? '');
  // active if started and not ended (or end missing)
  $started = ($sd === '' || $sd <= $today);
  $notEnded = ($ed === '' || $ed >= $today);
  if ($started && $notEnded) $activeProjects++;
}

// ---------------- Today's task: DPR pending/completed (for this employee) ----------------
$todayDprBySite = []; // site_id => latest dpr row today (by me)
$latestMyDprCreatedAt = null;

$myCompleted = 0;
$myPending = 0;

if (!empty($siteIds)) {
  $ph = implode(',', array_fill(0, count($siteIds), '?'));
  $types = str_repeat('i', count($siteIds)) . "is"; // site ids + employee_id + date
  $params = array_merge($siteIds, [$employeeId, $todayYmd]);

  $sql = "
    SELECT id, site_id, dpr_no, created_at
    FROM dpr_reports
    WHERE site_id IN ($ph) AND employee_id = ? AND dpr_date = ?
    ORDER BY created_at DESC
  ";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$st], $bind));
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

// ---------------- Team stats: Workers + Alerts from today's latest DPR per site ----------------
$onSiteWorkers = 0;   // summed manpower qty from today's DPRs (latest per site)
$alerts = 0;          // open constraints from today's DPRs (latest per site)
$teamDprToday = 0;    // total DPR rows today across your sites (all employees)

if (!empty($siteIds)) {
  $ph = implode(',', array_fill(0, count($siteIds), '?'));
  $types = str_repeat('i', count($siteIds)) . "s";
  $params = array_merge($siteIds, [$todayYmd]);

  // count all DPR rows today
  $sql = "SELECT COUNT(*) AS cnt FROM dpr_reports WHERE site_id IN ($ph) AND dpr_date = ?";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$st], $bind));
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    $teamDprToday = (int)($row['cnt'] ?? 0);
    mysqli_stmt_close($st);
  }

  // get all today's DPR rows, newest first; take latest per site for manpower/constraints aggregation
  $sql = "
    SELECT site_id, manpower_json, constraints_json
    FROM dpr_reports
    WHERE site_id IN ($ph) AND dpr_date = ?
    ORDER BY created_at DESC
  ";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$st], $bind));
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

// ---------------- Ongoing Projects table (top 8 active) ----------------
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
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$st], $bind));
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
  $types = str_repeat('i', count($siteIds)) . "ss"; // sites + start + end
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
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) $bind[] = &$params[$k];
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$st], $bind));
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

// UI helper
function activityInitial($name){
  $name = trim((string)$name);
  if ($name === '') return '👷';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
  return $first;
}

$latestMyDprTime = $latestMyDprCreatedAt ? date('h:i A', strtotime($latestMyDprCreatedAt)) : '—';
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

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.orange{ background: var(--orange); }
    .stat-ic.green{ background: var(--green); }
    .stat-ic.red{ background: var(--red); }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; }
    .table td{ vertical-align:middle; border-color: var(--border); font-weight:650; color:#374151; padding-top:14px; padding-bottom:14px; }

    .badge-pill{ border-radius:999px; padding:8px 12px; font-weight:900; font-size:12px; border:1px solid transparent; display:inline-flex; align-items:center; gap:8px; }
    .badge-pill .mini-dot{ width:8px; height:8px; border-radius:50%; background: currentColor; opacity:.9; }

    .ontrack{ color: var(--green); background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.18); }
    .atrisk{ color: var(--red); background: rgba(235,87,87,.12); border-color: rgba(235,87,87,.18); }
    .delayed{ color:#b7791f; background: rgba(242,201,76,.20); border-color: rgba(242,201,76,.28); }

    .muted-link{ color:#6b7280; font-weight:800; text-decoration:none; }
    .muted-link:hover{ color:#374151; }

    .activity-item{ display:flex; gap:12px; padding:12px 0; border-top:1px solid var(--border); }
    .activity-item:first-child{ border-top:0; padding-top:6px; }
    .activity-avatar{ width:42px; height:42px; border-radius:50%;
      background: linear-gradient(135deg, var(--yellow), #ffd66b);
      display:grid; place-items:center; font-weight:900; color:#1f2937; flex:0 0 auto; }
    .activity-title{ font-weight:850; margin:0; color:#1f2937; font-size:14px; }
    .activity-sub{ margin:2px 0 0; color:#6b7280; font-weight:650; font-size:12px; }

    .chart-wrap{ height:190px; }
    .donut-wrap{ height:240px; }

    .legend{ display:flex; flex-wrap:wrap; gap:18px 26px; padding:6px 2px 4px; align-items:center; }
    .legend-item{ display:flex; align-items:center; gap:8px; font-weight:800; color:#374151; }
    .legend-dot{ width:10px; height:10px; border-radius:50%; background:#999; }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
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

          <!-- Stats (dynamic) -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-folder2"></i></div>
                <div>
                  <div class="stat-label">Active Projects</div>
                  <div class="stat-value"><?php echo (int)$activeProjects; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-clock-history"></i></div>
                <div>
                  <div class="stat-label">Today DPR Pending</div>
                  <div class="stat-value"><?php echo (int)$myPending; ?></div>
                  <div style="font-size:12px; color:#6b7280; font-weight:800;">Completed: <?php echo (int)$myCompleted; ?> (<?php echo (int)$completionPct; ?>%)</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-people-fill"></i></div>
                <div>
                  <div class="stat-label">On-Site Workers (Today)</div>
                  <div class="stat-value"><?php echo (int)$onSiteWorkers; ?></div>
                  <div style="font-size:12px; color:#6b7280; font-weight:800;">From today’s latest DPR/site</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                  <div class="stat-label">Alerts (Today)</div>
                  <div class="stat-value"><?php echo (int)$alerts; ?></div>
                  <div style="font-size:12px; color:#6b7280; font-weight:800;">Open constraints from DPR</div>
                </div>
              </div>
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

                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th style="min-width:240px;">Project Name</th>
                        <th style="min-width:140px;">Status</th>
                        <th style="min-width:130px;">Start Date</th>
                        <th style="min-width:130px;">End Date</th>
                        <th class="text-end" style="width:60px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($ongoingRows)): ?>
                        <tr>
                          <td colspan="5" class="text-muted" style="font-weight:800;">No active projects found in your scope.</td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($ongoingRows as $p): ?>
                          <?php
                            [$label, $cls, $icon] = projectHealthBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');
                          ?>
                          <tr>
                            <td>
                              <div style="font-weight:900; color:#111827;"><?php echo e($p['project_name'] ?? ''); ?></div>
                              <div style="font-size:12px; color:#6b7280; font-weight:800;">
                                <i class="bi bi-geo-alt"></i> <?php echo e($p['project_location'] ?? ''); ?>
                                &nbsp;•&nbsp; <i class="bi bi-person-badge"></i> <?php echo e($p['client_name'] ?? ''); ?>
                              </div>
                            </td>
                            <td>
                              <span class="badge-pill <?php echo e($cls); ?>">
                                <span class="mini-dot"></span> <?php echo e($label); ?>
                              </span>
                            </td>
                            <td><?php echo e(fmtDate($p['start_date'] ?? '')); ?></td>
                            <td><?php echo e(fmtDate($p['expected_completion_date'] ?? '')); ?></td>
                            <td class="text-end">
                              <a class="muted-link" href="dpr.php?site_id=<?php echo (int)$p['id']; ?>" title="Open">
                                <i class="bi bi-box-arrow-up-right"></i>
                              </a>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
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
                <div style="margin-top:8px; font-size:12px; color:#6b7280; font-weight:800;">
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
                  <a class="muted-link" href="today-tasks.php" style="font-size:12px;">View today tasks</a>
                </div>

                <?php if (empty($recent)): ?>
                  <div class="text-muted" style="font-weight:800; padding:6px 0;">No recent DPR activity found.</div>
                <?php else: ?>
                  <?php foreach ($recent as $r): ?>
                    <div class="activity-item">
                      <div class="activity-avatar"><?php echo e(activityInitial($r['prepared_by'] ?? '')); ?></div>
                      <div class="flex-grow-1">
                        <p class="activity-title mb-0">
                          <?php echo e($r['prepared_by'] ?? ''); ?>
                          <span class="text-muted" style="font-weight:700;">submitted</span>
                          <?php echo e($r['dpr_no'] ?? 'DPR'); ?>
                          <span class="text-muted" style="font-weight:700;">for</span>
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

                <div style="margin-top:6px; font-size:12px; color:#6b7280; font-weight:800;">
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

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- TEK-C Custom JavaScript -->
  <script src="assets/js/sidebar-toggle.js"></script>

  <script>
    // Dynamic chart data from PHP
    const BAR_LABELS = <?php echo json_encode($barLabels, JSON_UNESCAPED_UNICODE); ?>;
    const BAR_VALUES = <?php echo json_encode($barValues, JSON_UNESCAPED_UNICODE); ?>;

    const DONUT_LABELS = <?php echo json_encode($donutLabels, JSON_UNESCAPED_UNICODE); ?>;
    const DONUT_VALUES = <?php echo json_encode($donutValues, JSON_UNESCAPED_UNICODE); ?>;

    document.addEventListener('DOMContentLoaded', function () {
      Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      Chart.defaults.color = "#6b7280";

      // Bar Chart: DPR count last 7 days (team scope)
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
              y: {
                beginAtZero: true,
                grid: { color: "rgba(233,236,239,1)" },
                border: { display: false },
                ticks: { stepSize: 1 }
              }
            }
          }
        });
      }

      // Donut Chart: My completed vs pending today
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

      // OPTIONAL: auto-refresh every 60 seconds for "realtime" feel
      // setTimeout(() => window.location.reload(), 60000);
    });
  </script>

</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>
