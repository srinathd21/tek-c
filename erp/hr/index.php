<?php
// hr/index.php (HR DASHBOARD) — Fully Dynamic (DB-driven)
// ✅ UPDATED:
// 1) MOBILE cards view for "Recently Joined Employees" (top 5) + keep table for desktop
// 2) MOBILE cards view for "Recent Profile Updates" (top 5) (already list-like, but improved spacing)
// 3) Small responsive tweaks for charts (better heights on mobile)
// 4) Safer queries unchanged; logic unchanged

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH (HR) ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$designation = trim((string)($_SESSION['designation'] ?? ''));
$department  = trim((string)($_SESSION['department'] ?? ''));

$isHr = (strtolower($designation) === 'hr') || (strtolower($department) === 'hr');
if (!$isHr) {
  $fallback = $_SESSION['role_redirect'] ?? '../login.php';
  header("Location: " . $fallback);
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

function safeDateTime($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function statusBadgeClass($status){
  $s = strtolower(trim((string)$status));
  if ($s === 'active') return ['Active', 'ontrack'];
  if ($s === 'inactive') return ['Inactive', 'atrisk'];
  if ($s === 'resigned') return ['Resigned', 'delayed'];
  return ['Unknown', 'atrisk'];
}

function initials($name){
  $name = trim((string)$name);
  if ($name === '') return 'U';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return (count($parts) > 1) ? ($first.$last) : $first;
}

// ---------------- DATES ----------------
$todayYmd = date('Y-m-d');
$from7    = date('Y-m-d', strtotime('-6 days'));
$from30   = date('Y-m-d', strtotime('-30 days'));

// ---------------- STATS ----------------
$totalEmployees  = 0;
$activeEmployees = 0;
$newJoiners30    = 0;
$incompleteCount = 0;

// Total employees
$st = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM employees");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  $totalEmployees = (int)($row['c'] ?? 0);
  mysqli_stmt_close($st);
}

// Active employees
$st = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM employees WHERE LOWER(employee_status) = 'active'");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  $activeEmployees = (int)($row['c'] ?? 0);
  mysqli_stmt_close($st);
}

// New joiners last 30 days
$st = mysqli_prepare($conn, "
  SELECT COUNT(*) AS c
  FROM employees
  WHERE date_of_joining IS NOT NULL
    AND date_of_joining <> ''
    AND date_of_joining <> '0000-00-00'
    AND date_of_joining >= ?
");
if ($st) {
  mysqli_stmt_bind_param($st, "s", $from30);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  $newJoiners30 = (int)($row['c'] ?? 0);
  mysqli_stmt_close($st);
}

// Incomplete profile count
$st = mysqli_prepare($conn, "
  SELECT COUNT(*) AS c
  FROM employees
  WHERE
    (photo IS NULL OR photo = '')
    OR (aadhar_card_number IS NULL OR aadhar_card_number = '')
    OR (pancard_number IS NULL OR pancard_number = '')
    OR (bank_account_number IS NULL OR bank_account_number = '')
    OR (ifsc_code IS NULL OR ifsc_code = '')
");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  $incompleteCount = (int)($row['c'] ?? 0);
  mysqli_stmt_close($st);
}

// ---------------- RECENTLY JOINED (Top 5) ----------------
$recentJoiners = [];
$st = mysqli_prepare($conn, "
  SELECT id, full_name, employee_code, department, designation, employee_status, date_of_joining
  FROM employees
  WHERE date_of_joining IS NOT NULL
    AND date_of_joining <> ''
    AND date_of_joining <> '0000-00-00'
  ORDER BY date_of_joining DESC
  LIMIT 5
");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $recentJoiners = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- BAR CHART: New joiners last 7 days ----------------
$barLabels = [];
$barMap = [];
for ($i=0; $i<7; $i++) {
  $d = date('Y-m-d', strtotime("-".(6-$i)." days"));
  $barLabels[] = date('D', strtotime($d));
  $barMap[$d] = 0;
}

$st = mysqli_prepare($conn, "
  SELECT date_of_joining AS d, COUNT(*) AS cnt
  FROM employees
  WHERE date_of_joining BETWEEN ? AND ?
  GROUP BY date_of_joining
");
if ($st) {
  mysqli_stmt_bind_param($st, "ss", $from7, $todayYmd);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($r = mysqli_fetch_assoc($res)) {
    $d = $r['d'] ?? '';
    if (isset($barMap[$d])) $barMap[$d] = (int)$r['cnt'];
  }
  mysqli_stmt_close($st);
}
$barValues = array_values($barMap);

// ---------------- DONUT: Status split ----------------
$statusActive = 0;
$statusInactive = 0;
$statusResigned = 0;

$st = mysqli_prepare($conn, "
  SELECT
    SUM(CASE WHEN LOWER(employee_status) = 'active' THEN 1 ELSE 0 END) AS a,
    SUM(CASE WHEN LOWER(employee_status) = 'inactive' THEN 1 ELSE 0 END) AS i,
    SUM(CASE WHEN LOWER(employee_status) = 'resigned' THEN 1 ELSE 0 END) AS r
  FROM employees
");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  $statusActive   = (int)($row['a'] ?? 0);
  $statusInactive = (int)($row['i'] ?? 0);
  $statusResigned = (int)($row['r'] ?? 0);
  mysqli_stmt_close($st);
}

// ---------------- RECENT UPDATES (Top 5) ----------------
$recentUpdates = [];
$st = mysqli_prepare($conn, "
  SELECT id, full_name, employee_code, updated_at, department, designation
  FROM employees
  ORDER BY updated_at DESC
  LIMIT 5
");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $recentUpdates = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

$loggedName = $_SESSION['employee_name'] ?? 'HR';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>HR Dashboard - TEK-C</title>

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
    .activity-avatar{ width:42px; height:42px; border-radius:50%; background: linear-gradient(135deg, var(--yellow), #ffd66b);
      display:grid; place-items:center; font-weight:900; color:#1f2937; flex:0 0 auto; }
    .activity-title{ font-weight:850; margin:0; color:#1f2937; font-size:14px; }
    .activity-sub{ margin:2px 0 0; color:#6b7280; font-weight:650; font-size:12px; }

    .chart-wrap{ height:190px; }
    .donut-wrap{ height:240px; }
    @media (max-width: 768px){
      .chart-wrap{ height:170px; }
      .donut-wrap{ height:210px; }
    }

    .legend{ display:flex; flex-wrap:wrap; gap:18px 26px; padding:6px 2px 4px; align-items:center; }
    .legend-item{ display:flex; align-items:center; gap:8px; font-weight:800; color:#374151; }
    .legend-dot{ width:10px; height:10px; border-radius:50%; background:#999; }

    /* ✅ MOBILE Cards */
    .emp-card{
      border:1px solid var(--border);
      border-radius:16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding:12px;
    }
    .emp-top{ display:flex; gap:10px; align-items:flex-start; justify-content:space-between; }
    .emp-main{ flex:1 1 auto; }
    .emp-name{ font-weight:1000; font-size:14px; color:#111827; line-height:1.25; }
    .emp-sub{ font-size:12px; color:#6b7280; font-weight:800; margin-top:2px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .emp-kv{ margin-top:10px; display:grid; gap:8px; }
    .emp-row{ display:flex; gap:10px; }
    .emp-key{ flex:0 0 92px; color:#6b7280; font-weight:1000; font-size:12px; }
    .emp-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; }
    .emp-actions{ margin-top:10px; display:flex; gap:8px; }
    .emp-actions a{ flex:1 1 auto; border-radius:12px; font-weight:900; justify-content:center; }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
    }
    @media (max-width: 768px){
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
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

          <!-- Stats -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-people-fill"></i></div>
                <div>
                  <div class="stat-label">Total Employees</div>
                  <div class="stat-value"><?php echo (int)$totalEmployees; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-person-check-fill"></i></div>
                <div>
                  <div class="stat-label">Active Employees</div>
                  <div class="stat-value"><?php echo (int)$activeEmployees; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-calendar-plus"></i></div>
                <div>
                  <div class="stat-label">New Joiners</div>
                  <div class="stat-value"><?php echo (int)$newJoiners30; ?></div>
                  <div class="activity-sub">Last 30 days</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                  <div class="stat-label">Incomplete Profiles</div>
                  <div class="stat-value"><?php echo (int)$incompleteCount; ?></div>
                  <div class="activity-sub">Photo / KYC / Bank</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Middle row -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Recently Joined Employees</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>

                <!-- ✅ MOBILE CARDS -->
                <div class="d-block d-md-none">
                  <?php if (empty($recentJoiners)): ?>
                    <div class="text-muted" style="font-weight:900;">No joiners found.</div>
                  <?php else: ?>
                    <div class="d-grid gap-3">
                      <?php foreach ($recentJoiners as $emp): ?>
                        <?php
                          [$label, $cls] = statusBadgeClass($emp['employee_status'] ?? '');
                          $name = $emp['full_name'] ?? '';
                          $code = $emp['employee_code'] ?? '';
                          $dept = $emp['department'] ?? '';
                          $desg = $emp['designation'] ?? '';
                          $join = $emp['date_of_joining'] ?? '';
                          $id   = (int)($emp['id'] ?? 0);
                        ?>
                        <div class="emp-card">
                          <div class="emp-top">
                            <div class="emp-main">
                              <div class="emp-name"><?php echo e($name); ?></div>
                              <div class="emp-sub">
                                <span><i class="bi bi-hash"></i> <?php echo e($code); ?></span>
                                <span>•</span>
                                <span><i class="bi bi-building"></i> <?php echo e($dept); ?></span>
                              </div>
                            </div>
                            <span class="badge-pill <?php echo e($cls); ?>" style="padding:6px 10px;font-size:11px;">
                              <span class="mini-dot"></span> <?php echo e($label); ?>
                            </span>
                          </div>

                          <div class="emp-kv">
                            <div class="emp-row">
                              <div class="emp-key">Role</div>
                              <div class="emp-val"><?php echo e($desg); ?></div>
                            </div>
                            <div class="emp-row">
                              <div class="emp-key">Joining</div>
                              <div class="emp-val"><?php echo e(safeDate($join)); ?></div>
                            </div>
                          </div>

                          <div class="emp-actions">
                            <a class="btn btn-outline-primary btn-sm" href="../view-employee.php?id=<?php echo (int)$id; ?>">
                              <i class="bi bi-eye"></i> View
                            </a>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- ✅ DESKTOP TABLE -->
                <div class="table-responsive d-none d-md-block">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th style="min-width:220px;">Employee</th>
                        <th style="min-width:140px;">Dept / Role</th>
                        <th style="min-width:140px;">Status</th>
                        <th style="min-width:130px;">Joining Date</th>
                        <th class="text-end" style="width:60px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($recentJoiners)): ?>
                        <tr><td colspan="5" class="text-muted" style="font-weight:800;">No joiners found.</td></tr>
                      <?php else: ?>
                        <?php foreach ($recentJoiners as $emp): ?>
                          <?php
                            [$label, $cls] = statusBadgeClass($emp['employee_status'] ?? '');
                            $name = $emp['full_name'] ?? '';
                            $code = $emp['employee_code'] ?? '';
                            $dept = $emp['department'] ?? '';
                            $desg = $emp['designation'] ?? '';
                            $join = $emp['date_of_joining'] ?? '';
                            $id   = (int)($emp['id'] ?? 0);
                          ?>
                          <tr>
                            <td>
                              <div style="font-weight:900;"><?php echo e($name); ?></div>
                              <div class="activity-sub">Code: <?php echo e($code); ?></div>
                            </td>
                            <td>
                              <div style="font-weight:850;"><?php echo e($dept); ?></div>
                              <div class="activity-sub"><?php echo e($desg); ?></div>
                            </td>
                            <td>
                              <span class="badge-pill <?php echo e($cls); ?>">
                                <span class="mini-dot"></span> <?php echo e($label); ?>
                              </span>
                            </td>
                            <td><?php echo e(safeDate($join)); ?></td>
                            <td class="text-end">
                              <a class="muted-link" href="../view-employee.php?id=<?php echo (int)$id; ?>" title="View">
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
                  <h3 class="panel-title">New Joiners</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>
                <div class="chart-wrap">
                  <canvas id="barChart"></canvas>
                </div>
                <div class="activity-sub mt-2">Last 7 days</div>
              </div>
            </div>
          </div>

          <!-- Bottom row -->
          <div class="row g-3 mb-4">
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Recent Profile Updates</h3>
                  <a class="muted-link" href="manage-employees.php" style="font-size:12px;">Manage employees</a>
                </div>

                <?php if (empty($recentUpdates)): ?>
                  <div class="text-muted" style="font-weight:800;">No updates found.</div>
                <?php else: ?>
                  <?php foreach ($recentUpdates as $u): ?>
                    <?php
                      $name = $u['full_name'] ?? 'User';
                      $initial = initials($name);
                      $when = safeDateTime($u['updated_at'] ?? '');
                      $dept = $u['department'] ?? '';
                      $desg = $u['designation'] ?? '';
                      $code = $u['employee_code'] ?? '';
                      $id   = (int)($u['id'] ?? 0);
                    ?>
                    <div class="activity-item">
                      <div class="activity-avatar"><?php echo e($initial); ?></div>
                      <div class="flex-grow-1">
                        <p class="activity-title mb-0">
                          <a class="muted-link" href="../view-employee.php?id=<?php echo (int)$id; ?>" style="font-weight:900;">
                            <?php echo e($name); ?>
                          </a>
                          <span class="text-muted" style="font-weight:700;">(<?php echo e($code); ?>)</span>
                        </p>
                        <p class="activity-sub">
                          <?php echo e($dept); ?> • <?php echo e($desg); ?> • Updated: <?php echo e($when); ?>
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
                  <h3 class="panel-title">Employee Status</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>

                <div class="donut-wrap">
                  <canvas id="donutChart"></canvas>
                </div>

                <div class="legend">
                  <div class="legend-item"><span class="legend-dot" style="background: rgba(39,174,96,.95);"></span> Active</div>
                  <div class="legend-item"><span class="legend-dot" style="background: rgba(235,87,87,.95);"></span> Inactive</div>
                  <div class="legend-item"><span class="legend-dot" style="background: rgba(242,201,76,.95);"></span> Resigned</div>
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
    document.addEventListener('DOMContentLoaded', function() {
      Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      Chart.defaults.color = "#6b7280";

      const barCtx = document.getElementById("barChart");
      if (barCtx) {
        new Chart(barCtx, {
          type: "bar",
          data: {
            labels: <?php echo json_encode($barLabels); ?>,
            datasets: [{
              label: "New Joiners",
              data: <?php echo json_encode($barValues); ?>,
              backgroundColor: "rgba(107,114,128,.85)",
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
              y: { grid: { color: "rgba(233,236,239,1)" }, border: { display: false }, ticks: { stepSize: 2 } }
            }
          }
        });
      }

      const donutCtx = document.getElementById("donutChart");
      if (donutCtx) {
        new Chart(donutCtx, {
          type: "doughnut",
          data: {
            labels: ["Active","Inactive","Resigned"],
            datasets: [{
              data: <?php echo json_encode([(int)$statusActive, (int)$statusInactive, (int)$statusResigned]); ?>,
              backgroundColor: [
                "rgba(39,174,96,.95)",
                "rgba(235,87,87,.95)",
                "rgba(242,201,76,.95)"
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

              ctx.save();
              ctx.fillStyle = "#374151";
              ctx.textAlign = "center";
              ctx.textBaseline = "middle";

              ctx.font = "800 12px " + Chart.defaults.font.family;
              ctx.fillText("Status", x, y - 8);
              ctx.font = "900 12px " + Chart.defaults.font.family;
              ctx.fillText("Split", x, y + 12);
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
if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
?>