<?php
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
  die("Database connection failed.");
}

$success = '';
$error = '';

function e($v){
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($v, $format='d M Y', $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date($format, $ts) : e($v);
}

function showMoney($v, $dash='—'){
  if ($v === null) return $dash;
  $v = trim((string)$v);
  if ($v === '') return $dash;
  if (!is_numeric($v)) return e($v);

  $num = (float)$v;
  if ($num >= 10000000) return number_format($num / 10000000, 2) . 'Cr';
  if ($num >= 100000) return number_format($num / 100000, 2) . 'L';
  return number_format($num, 2);
}

function initials($name){
  $name = trim((string)$name);
  if ($name === '') return 'NA';
  $parts = preg_split('/\s+/', $name);
  $a = strtoupper(substr($parts[0] ?? 'N', 0, 1));
  $b = strtoupper(substr($parts[count($parts)-1] ?? 'A', 0, 1));
  return $a . $b;
}

function projectStatusBadge($start, $end){
  $today = date('Y-m-d');
  $start = trim((string)$start);
  $end = trim((string)$end);

  if ($end !== '' && $end !== '0000-00-00' && $end < $today) {
    return ['Completed', 'ontrack'];
  }

  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['Upcoming', 'joined'];
  }

  return ['Ongoing', 'progressing'];
}

function bindParams(mysqli_stmt $stmt, string $types, array $values){
  if ($types === '') return true;
  $refs = [];
  $refs[] = $types;
  foreach ($values as $k => $v) {
    $refs[] = &$values[$k];
  }
  return call_user_func_array([$stmt, 'bind_param'], $refs);
}

$current_employee_id = (int)($_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? 0);
$current_employee_name = (string)($_SESSION['employee_name'] ?? $_SESSION['user_name'] ?? '');

// Detect optional team lead column
$hasTeamLeadCol = false;
$chk = mysqli_query($conn, "SHOW COLUMNS FROM sites LIKE 'team_lead_employee_id'");
if ($chk) {
  $hasTeamLeadCol = mysqli_num_rows($chk) > 0;
  mysqli_free_result($chk);
}

$teamLeadSelect = $hasTeamLeadCol ? "s.team_lead_employee_id," : "NULL AS team_lead_employee_id,";
$teamLeadJoin = $hasTeamLeadCol ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id" : "LEFT JOIN employees tl ON 1=0";

$employeeFilterParts = [
  "s.manager_employee_id = ?",
  "EXISTS (SELECT 1 FROM site_project_engineers spe_self WHERE spe_self.site_id = s.id AND spe_self.employee_id = ?)"
];

$filterTypes = "ii";
$filterValues = [$current_employee_id, $current_employee_id];

if ($hasTeamLeadCol) {
  $employeeFilterParts[] = "s.team_lead_employee_id = ?";
  $filterTypes .= "i";
  $filterValues[] = $current_employee_id;
}

$employeeProjectFilter = "(" . implode(" OR ", $employeeFilterParts) . ")";

/* ---------------- My Projects ---------------- */
$projects = [];

$sqlProjects = "
  SELECT
    s.*,
    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.state AS client_state,

    $teamLeadSelect

    m.full_name AS manager_name,
    m.designation AS manager_designation,

    tl.full_name AS team_lead_name,
    tl.designation AS team_lead_designation,

    GROUP_CONCAT(
      DISTINCT CONCAT(
        COALESCE(pe.full_name,''),'|',
        COALESCE(pe.designation,'')
      )
      ORDER BY pe.full_name
      SEPARATOR '||'
    ) AS engineers_concat

  FROM sites s
  INNER JOIN clients c ON c.id = s.client_id
  LEFT JOIN employees m ON m.id = s.manager_employee_id
  $teamLeadJoin
  LEFT JOIN site_project_engineers spe ON spe.site_id = s.id
  LEFT JOIN employees pe ON pe.id = spe.employee_id
  WHERE s.deleted_at IS NULL
    AND $employeeProjectFilter
  GROUP BY s.id
  ORDER BY s.created_at DESC
";

$stmtProjects = mysqli_prepare($conn, $sqlProjects);
if ($stmtProjects) {
  bindParams($stmtProjects, $filterTypes, $filterValues);
  mysqli_stmt_execute($stmtProjects);
  $res = mysqli_stmt_get_result($stmtProjects);
  if ($res) $projects = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($stmtProjects);
} else {
  $error = "Error fetching projects: " . mysqli_error($conn);
}

/* ---------------- Stats ---------------- */
$total_projects = count($projects);
$ongoing = 0;
$upcoming = 0;
$completed = 0;
$alerts = 0;
$upcoming_tasks = 0;

$today = date('Y-m-d');
$next30 = date('Y-m-d', strtotime('+30 days'));

foreach ($projects as $p) {
  $start = $p['start_date'] ?? '';
  $end = $p['expected_completion_date'] ?? '';

  if (!empty($end) && $end !== '0000-00-00' && $end < $today) {
    $completed++;
  } elseif (!empty($start) && $start !== '0000-00-00' && $start > $today) {
    $upcoming++;
  } else {
    $ongoing++;
  }

  if (!empty($end) && $end !== '0000-00-00' && $end >= $today && $end <= $next30) {
    $upcoming_tasks++;
  }

  if (!empty($end) && $end !== '0000-00-00' && $end < $today) {
    $alerts++;
  }
}

/* ---------------- Team Members under My Projects ---------------- */
$team_members = [];
$sqlTeam = "
  SELECT DISTINCT
    e.id,
    e.full_name,
    e.employee_code,
    e.department,
    e.designation,
    e.date_of_joining,
    e.employee_status
  FROM employees e
  WHERE e.id IN (
    SELECT s.manager_employee_id
    FROM sites s
    WHERE s.deleted_at IS NULL
      AND s.manager_employee_id IS NOT NULL
      AND $employeeProjectFilter

    " . ($hasTeamLeadCol ? "
    UNION
    SELECT s.team_lead_employee_id
    FROM sites s
    WHERE s.deleted_at IS NULL
      AND s.team_lead_employee_id IS NOT NULL
      AND $employeeProjectFilter
    " : "") . "

    UNION
    SELECT spe.employee_id
    FROM site_project_engineers spe
    INNER JOIN sites s ON s.id = spe.site_id
    WHERE s.deleted_at IS NULL
      AND $employeeProjectFilter
  )
  ORDER BY e.date_of_joining DESC, e.id DESC
  LIMIT 5
";

$teamTypes = $filterTypes . ($hasTeamLeadCol ? $filterTypes : '') . $filterTypes;
$teamValues = array_merge($filterValues, ($hasTeamLeadCol ? $filterValues : []), $filterValues);

$stmtTeam = mysqli_prepare($conn, $sqlTeam);
if ($stmtTeam) {
  bindParams($stmtTeam, $teamTypes, $teamValues);
  mysqli_stmt_execute($stmtTeam);
  $resTeam = mysqli_stmt_get_result($stmtTeam);
  if ($resTeam) $team_members = mysqli_fetch_all($resTeam, MYSQLI_ASSOC);
  mysqli_stmt_close($stmtTeam);
}

$employee_count = count($team_members);

/* ---------------- Recent Projects ---------------- */
$recent_projects = array_slice($projects, 0, 5);
$ongoing_projects = [];
foreach ($projects as $p) {
  [$lbl] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');
  if ($lbl === 'Ongoing') $ongoing_projects[] = $p;
}
$ongoing_projects = array_slice($ongoing_projects, 0, 5);

/* ---------------- Recent Activity ---------------- */
$recent_activity = [];
$projectIds = array_map(fn($p) => (int)$p['id'], $projects);

if (!empty($projectIds)) {
  $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
  $types = str_repeat('i', count($projectIds));

  $sqlActivity = "
    SELECT
      employee_name,
      activity_type,
      module,
      description,
      reference_id,
      created_at
    FROM activity_logs
    WHERE reference_id IN ($placeholders)
      AND (
        UPPER(module) IN ('PROJECT', 'PROJECTS', 'SITE', 'SITES')
        OR module LIKE '%project%'
        OR module LIKE '%site%'
      )
    ORDER BY created_at DESC
    LIMIT 6
  ";

  $stmtAct = mysqli_prepare($conn, $sqlActivity);
  if ($stmtAct) {
    bindParams($stmtAct, $types, $projectIds);
    mysqli_stmt_execute($stmtAct);
    $resAct = mysqli_stmt_get_result($stmtAct);
    if ($resAct) $recent_activity = mysqli_fetch_all($resAct, MYSQLI_ASSOC);
    mysqli_stmt_close($stmtAct);
  }
}

/* ---------------- Charts ---------------- */
$barCompleted = [$completed, $ongoing, $upcoming];
$barPending = [$alerts, $upcoming_tasks, max(0, $total_projects - $completed)];

$deptCounts = [];
foreach ($team_members as $tm) {
  $dept = trim((string)($tm['department'] ?? 'Other'));
  if ($dept === '') $dept = 'Other';
  $deptCounts[$dept] = ($deptCounts[$dept] ?? 0) + 1;
}

if (empty($deptCounts)) {
  $deptCounts = ['Projects' => max(1, $total_projects)];
}

$deptLabels = array_keys($deptCounts);
$deptData = array_values($deptCounts);
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
    :root {
      --dash-bg: #f5f7fb;
      --dash-card: #ffffff;
      --dash-border: #e5e7eb;
      --dash-text: #111827;
      --dash-muted: #6b7280;
      --dash-soft: #f9fafb;
      --dash-shadow: 0 12px 30px rgba(15, 23, 42, .06);
      --dash-radius: 16px;
    }

    body { background: var(--dash-bg); }

    .content-scroll {
      flex: 1 1 auto;
      overflow: auto;
      padding: 18px;
    }

    .dashboard-wrapper {
      max-width: 1500px;
      margin: 0 auto;
    }

    .dashboard-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 16px;
    }

    .dashboard-heading h1 {
      font-size: 20px;
      font-weight: 900;
      color: var(--dash-text);
      margin: 0;
    }

    .dashboard-heading p {
      margin: 3px 0 0;
      color: var(--dash-muted);
      font-size: 12px;
      font-weight: 600;
    }

    .dash-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .primary-btn {
      border: 0;
      background: #111827;
      color: #fff;
      height: 36px;
      padding: 0 14px;
      border-radius: 11px;
      font-size: 12px;
      font-weight: 900;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      text-decoration: none;
      white-space: nowrap;
    }

    .primary-btn:hover {
      background: #020617;
      color: #fff;
    }

    .panel {
      background: var(--dash-card);
      border: 1px solid var(--dash-border);
      border-radius: var(--dash-radius);
      box-shadow: var(--dash-shadow);
      padding: 14px;
      height: 100%;
    }

    .panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 10px;
    }

    .panel-title {
      font-weight: 900;
      font-size: 15px;
      color: var(--dash-text);
      margin: 0;
    }

    .panel-subtitle {
      color: var(--dash-muted);
      font-size: 11px;
      font-weight: 700;
      margin-top: 2px;
    }

    .panel-menu {
      width: 31px;
      height: 31px;
      border-radius: 10px;
      border: 1px solid var(--dash-border);
      background: #fff;
      display: grid;
      place-items: center;
      color: var(--dash-muted);
      text-decoration: none;
      transition: .2s ease;
    }

    .panel-menu:hover {
      background: var(--dash-soft);
      color: var(--dash-text);
    }

    .stat-card {
      background: var(--dash-card);
      border: 1px solid var(--dash-border);
      border-radius: var(--dash-radius);
      box-shadow: var(--dash-shadow);
      padding: 13px 14px;
      min-height: 82px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .stat-ic {
      width: 40px;
      height: 40px;
      border-radius: 13px;
      display: grid;
      place-items: center;
      color: #fff;
      font-size: 18px;
      flex: 0 0 auto;
    }

    .stat-ic.blue { background: var(--blue, #2f80ed); }
    .stat-ic.orange { background: var(--orange, #f2994a); }
    .stat-ic.green { background: var(--green, #27ae60); }
    .stat-ic.red { background: var(--red, #eb5757); }

    .stat-label {
      color: var(--dash-muted);
      font-weight: 800;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .3px;
    }

    .stat-value {
      font-size: 25px;
      font-weight: 950;
      line-height: 1;
      margin-top: 3px;
      color: var(--dash-text);
    }

    .compact-table-wrap {
      border: 1px solid var(--dash-border);
      border-radius: 14px;
      overflow: hidden;
      background: #fff;
    }

    .compact-table {
      margin: 0;
      font-size: 12px;
      min-width: 680px;
    }

    .compact-table thead th {
      background: #f8fafc;
      color: #64748b;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: .45px;
      font-weight: 900;
      border-bottom: 1px solid var(--dash-border) !important;
      padding: 9px 11px;
      white-space: nowrap;
    }

    .compact-table tbody td {
      padding: 9px 11px;
      vertical-align: middle;
      border-color: #eef2f7;
      color: #334155;
      font-weight: 700;
      white-space: nowrap;
    }

    .compact-table tbody tr:hover { background: #fbfdff; }

    .table-title-cell {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
    }

    .table-icon {
      width: 28px;
      height: 28px;
      border-radius: 9px;
      display: grid;
      place-items: center;
      background: #eff6ff;
      color: #2563eb;
      font-size: 14px;
      flex: 0 0 auto;
    }

    .table-primary-text {
      color: #111827;
      font-size: 12px;
      font-weight: 900;
      line-height: 1.2;
    }

    .table-secondary-text {
      color: #64748b;
      font-size: 10.5px;
      font-weight: 700;
      margin-top: 1px;
      line-height: 1.2;
    }

    .badge-pill {
      border-radius: 999px;
      padding: 5px 8px;
      font-weight: 900;
      font-size: 10.5px;
      border: 1px solid transparent;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      line-height: 1;
    }

    .badge-pill .mini-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: currentColor;
    }

    .ontrack {
      color: #15803d;
      background: #dcfce7;
      border-color: #bbf7d0;
    }

    .progressing {
      color: #2563eb;
      background: #dbeafe;
      border-color: #bfdbfe;
    }

    .atrisk {
      color: #b91c1c;
      background: #fee2e2;
      border-color: #fecaca;
    }

    .delayed {
      color: #a16207;
      background: #fef3c7;
      border-color: #fde68a;
    }

    .joined {
      color: #6d28d9;
      background: #ede9fe;
      border-color: #ddd6fe;
    }

    .muted-link {
      color: #64748b;
      font-weight: 900;
      text-decoration: none;
      font-size: 12px;
    }

    .muted-link:hover { color: #111827; }

    .avatar-mini {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 11px;
      font-weight: 950;
      color: #fff;
      background: linear-gradient(135deg, #334155, #64748b);
      flex: 0 0 auto;
    }

    .activity-item {
      display: flex;
      gap: 10px;
      padding: 10px 0;
      border-top: 1px solid var(--dash-border);
    }

    .activity-item:first-child {
      border-top: 0;
      padding-top: 2px;
    }

    .activity-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--yellow, #f2c94c), #ffd66b);
      display: grid;
      place-items: center;
      font-weight: 900;
      color: #1f2937;
      flex: 0 0 auto;
      font-size: 15px;
    }

    .activity-title {
      font-weight: 850;
      margin: 0;
      color: #1f2937;
      font-size: 12.5px;
      line-height: 1.35;
    }

    .activity-sub {
      margin: 2px 0 0;
      color: #6b7280;
      font-weight: 650;
      font-size: 11px;
    }

    .chart-wrap { height: 188px; }
    .donut-wrap { height: 220px; }

    .legend {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 16px;
      padding: 4px 2px 0;
      align-items: center;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 7px;
      font-weight: 800;
      color: #374151;
      font-size: 11.5px;
    }

    .legend-dot {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: #999;
    }

    .empty-state {
      padding: 22px;
      text-align: center;
      color: var(--dash-muted);
      font-size: 12px;
      font-weight: 800;
    }

    @media (max-width: 991.98px) {
      .content-scroll { padding: 14px; }
      .dashboard-heading {
        align-items: flex-start;
        flex-direction: column;
      }
      .compact-table { min-width: 720px; }
    }

    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .dashboard-wrapper {
        padding-left: 6px !important;
        padding-right: 6px !important;
      }
      .panel {
        padding: 12px !important;
        border-radius: 14px;
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
        <div class="container-fluid dashboard-wrapper">

          <div class="dashboard-heading">
            <div>
              <h1>Dashboard</h1>
              <p>
                Compact overview of projects assigned to
                <?php echo $current_employee_name !== '' ? e($current_employee_name) : 'this employee'; ?>.
              </p>
            </div>

            <div class="dash-actions">
              <a href="projects.php" class="primary-btn">
                <i class="bi bi-folder2-open"></i> My Projects
              </a>
              
            </div>
          </div>

          <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>
              <?php echo e($error); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>

          <!-- Stats -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-folder2"></i></div>
                <div>
                  <div class="stat-label">My Active Projects</div>
                  <div class="stat-value"><?php echo (int)$total_projects; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-clock-history"></i></div>
                <div>
                  <div class="stat-label">Due in 30 Days</div>
                  <div class="stat-value"><?php echo (int)$upcoming_tasks; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-people-fill"></i></div>
                <div>
                  <div class="stat-label">My Team Members</div>
                  <div class="stat-value"><?php echo (int)$employee_count; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                  <div class="stat-label">Overdue / Alerts</div>
                  <div class="stat-value"><?php echo (int)$alerts; ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Main row -->
          <div class="row g-3 mb-3">

            <!-- Ongoing Projects -->
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Ongoing Projects</h3>
                    <div class="panel-subtitle">Current project status and timelines under this employee</div>
                  </div>
                  <a class="panel-menu" href="projects.php" aria-label="View all">
                    <i class="bi bi-box-arrow-up-right"></i>
                  </a>
                </div>

                <div class="table-responsive compact-table-wrap">
                  <table class="table compact-table align-middle">
                    <thead>
                      <tr>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Manager</th>
                        <th class="text-end">Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($ongoing_projects)): ?>
                        <tr>
                          <td colspan="6">
                            <div class="empty-state">No ongoing projects assigned.</div>
                          </td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($ongoing_projects as $p): ?>
                          <?php [$stLabel, $stClass] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? ''); ?>
                          <tr>
                            <td>
                              <div class="table-title-cell">
                                <div class="table-icon"><i class="bi bi-building"></i></div>
                                <div>
                                  <div class="table-primary-text"><?php echo e($p['project_name'] ?? ''); ?></div>
                                  <div class="table-secondary-text">
                                    <?php echo e($p['project_type'] ?? ''); ?> • <?php echo e($p['project_location'] ?? ''); ?>
                                  </div>
                                </div>
                              </div>
                            </td>
                            <td>
                              <span class="badge-pill <?php echo e($stClass); ?>">
                                <span class="mini-dot"></span> <?php echo e($stLabel); ?>
                              </span>
                            </td>
                            <td><?php echo e(safeDate($p['start_date'] ?? '')); ?></td>
                            <td><?php echo e(safeDate($p['expected_completion_date'] ?? '')); ?></td>
                            <td><?php echo !empty($p['manager_name']) ? e($p['manager_name']) : 'Not Assigned'; ?></td>
                            <td class="text-end">
                              <a class="muted-link" href="view-site.php?id=<?php echo (int)$p['id']; ?>">
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

            <!-- Progress Overview -->
            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Project Overview</h3>
                    <div class="panel-subtitle">Completed, ongoing and upcoming</div>
                  </div>
                  <button class="panel-menu" type="button" aria-label="More">
                    <i class="bi bi-three-dots"></i>
                  </button>
                </div>

                <div class="chart-wrap">
                  <canvas id="barChart"></canvas>
                </div>
              </div>
            </div>

          </div>

          <!-- New tables row -->
          <div class="row g-3 mb-3">

            <!-- Team Members -->
            <div class="col-12 col-xl-6">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Team Members</h3>
                    <div class="panel-subtitle">Employees linked with your projects</div>
                  </div>
                  <a class="muted-link" href="projects.php">View Projects</a>
                </div>

                <div class="table-responsive compact-table-wrap">
                  <table class="table compact-table align-middle">
                    <thead>
                      <tr>
                        <th>Employee</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($team_members)): ?>
                        <tr>
                          <td colspan="4">
                            <div class="empty-state">No team members found for assigned projects.</div>
                          </td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($team_members as $tm): ?>
                          <?php
                            $status = trim((string)($tm['employee_status'] ?? 'active'));
                            $statusClass = strtolower($status) === 'active' ? 'ontrack' : 'delayed';
                          ?>
                          <tr>
                            <td>
                              <div class="table-title-cell">
                                <div class="avatar-mini"><?php echo e(initials($tm['full_name'] ?? '')); ?></div>
                                <div>
                                  <div class="table-primary-text"><?php echo e($tm['full_name'] ?? ''); ?></div>
                                  <div class="table-secondary-text"><?php echo e($tm['employee_code'] ?? ''); ?></div>
                                </div>
                              </div>
                            </td>
                            <td><?php echo e($tm['designation'] ?? ''); ?></td>
                            <td><?php echo e(safeDate($tm['date_of_joining'] ?? '')); ?></td>
                            <td>
                              <span class="badge-pill <?php echo e($statusClass); ?>">
                                <span class="mini-dot"></span> <?php echo e($status ?: '—'); ?>
                              </span>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>

            <!-- Recent Projects -->
            <div class="col-12 col-xl-6">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Recent Projects</h3>
                    <div class="panel-subtitle">Newest records assigned to this employee</div>
                  </div>
                  <a class="muted-link" href="projects.php">View All</a>
                </div>

                <div class="table-responsive compact-table-wrap">
                  <table class="table compact-table align-middle">
                    <thead>
                      <tr>
                        <th>Project</th>
                        <th>Client</th>
                        <th>Created</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($recent_projects)): ?>
                        <tr>
                          <td colspan="4">
                            <div class="empty-state">No projects assigned.</div>
                          </td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($recent_projects as $p): ?>
                          <?php [$stLabel, $stClass] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? ''); ?>
                          <tr>
                            <td>
                              <div class="table-title-cell">
                                <div class="table-icon"><i class="bi bi-house-gear"></i></div>
                                <div>
                                  <div class="table-primary-text"><?php echo e($p['project_name'] ?? ''); ?></div>
                                  <div class="table-secondary-text"><?php echo e($p['project_type'] ?? ''); ?></div>
                                </div>
                              </div>
                            </td>
                            <td><?php echo e($p['client_name'] ?? ''); ?></td>
                            <td><?php echo e(safeDate($p['created_at'] ?? '')); ?></td>
                            <td>
                              <span class="badge-pill <?php echo e($stClass); ?>">
                                <span class="mini-dot"></span> <?php echo e($stLabel); ?>
                              </span>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>

          </div>

          <!-- Bottom row -->
          <div class="row g-3 mb-4">

            <!-- Recent Activity -->
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Recent Activity</h3>
                    <div class="panel-subtitle">Latest updates from assigned projects</div>
                  </div>
                  <a class="muted-link" href="activity-logs.php?module=PROJECT">View All</a>
                </div>

                <?php if (empty($recent_activity)): ?>
                  <div class="empty-state">No recent project activity found.</div>
                <?php else: ?>
                  <?php foreach ($recent_activity as $act): ?>
                    <div class="activity-item">
                      <div class="activity-avatar">
                        <?php echo e(substr((string)($act['activity_type'] ?? 'A'), 0, 1)); ?>
                      </div>
                      <div class="flex-grow-1">
                        <p class="activity-title">
                          <?php echo e($act['employee_name'] ?: 'System'); ?>
                          <span class="text-muted" style="font-weight:700;">
                            <?php echo e(strtolower($act['activity_type'] ?? 'updated')); ?>
                          </span>
                          <?php echo e($act['module'] ?? 'Project'); ?>
                        </p>
                        <p class="activity-sub">
                          <?php echo e($act['description'] ?? ''); ?>
                          <?php if (!empty($act['created_at'])): ?>
                            • <?php echo e(safeDate($act['created_at'], 'd M Y h:i A')); ?>
                          <?php endif; ?>
                        </p>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- Team Performance -->
            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Team Split</h3>
                    <div class="panel-subtitle">Department-wise employees</div>
                  </div>
                  <button class="panel-menu" type="button" aria-label="More">
                    <i class="bi bi-three-dots"></i>
                  </button>
                </div>

                <div class="donut-wrap">
                  <canvas id="donutChart"></canvas>
                </div>

                <div class="legend" id="deptLegend"></div>
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
    document.addEventListener('DOMContentLoaded', function () {
      const yearElement = document.getElementById("year");
      if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
      }

      const overviewLabels = ["Completed", "Ongoing", "Upcoming"];
      const overviewValues = <?php echo json_encode([$completed, $ongoing, $upcoming]); ?>;

      const deptLabels = <?php echo json_encode($deptLabels); ?>;
      const deptValues = <?php echo json_encode($deptData); ?>;

      if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = "#64748b";

        const barCtx = document.getElementById("barChart");

        if (barCtx) {
          new Chart(barCtx, {
            type: "bar",
            data: {
              labels: overviewLabels,
              datasets: [
                {
                  label: "Projects",
                  data: overviewValues,
                  backgroundColor: "rgba(100,116,139,.82)",
                  borderRadius: 8,
                  barThickness: 24
                }
              ]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: {
                  titleFont: { size: 12, weight: 'bold' },
                  bodyFont: { size: 11 }
                }
              },
              scales: {
                x: {
                  grid: { display: false },
                  ticks: { font: { size: 10, weight: 800 } }
                },
                y: {
                  beginAtZero: true,
                  grid: { color: "rgba(226,232,240,1)" },
                  border: { display: false },
                  ticks: {
                    precision: 0,
                    font: { size: 10, weight: 700 }
                  }
                }
              }
            }
          });
        }

        const donutCtx = document.getElementById("donutChart");
        const donutColors = [
          "rgba(242,201,76,.95)",
          "rgba(242,153,74,.95)",
          "rgba(156,163,175,.95)",
          "rgba(107,114,128,.95)",
          "rgba(47,128,237,.85)",
          "rgba(39,174,96,.85)"
        ];

        if (donutCtx) {
          new Chart(donutCtx, {
            type: "doughnut",
            data: {
              labels: deptLabels,
              datasets: [{
                data: deptValues,
                backgroundColor: deptLabels.map(function(_, i) {
                  return donutColors[i % donutColors.length];
                }),
                borderWidth: 0,
                hoverOffset: 8
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              cutout: "68%",
              plugins: {
                legend: { display: false },
                tooltip: {
                  titleFont: { size: 12, weight: 'bold' },
                  bodyFont: { size: 11 }
                }
              }
            },
            plugins: [{
              id: "centerText",
              afterDraw(chart) {
                const ctx = chart.ctx;
                const meta = chart.getDatasetMeta(0);
                if (!meta || !meta.data || !meta.data.length) return;

                const x = meta.data[0].x;
                const y = meta.data[0].y;

                ctx.save();
                ctx.fillStyle = "#334155";
                ctx.textAlign = "center";
                ctx.textBaseline = "middle";
                ctx.font = "800 12px " + Chart.defaults.font.family;
                ctx.fillText("Team", x, y - 7);
                ctx.font = "900 12px " + Chart.defaults.font.family;
                ctx.fillText("Split", x, y + 11);
                ctx.restore();
              }
            }]
          });
        }

        const legend = document.getElementById('deptLegend');
        if (legend) {
          legend.innerHTML = deptLabels.map(function(label, i) {
            return `
              <div class="legend-item">
                <span class="legend-dot" style="background:${donutColors[i % donutColors.length]};"></span>
                ${label}
              </div>
            `;
          }).join('');
        }
      }
    });
  </script>

</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
  mysqli_close($conn);
}
?>