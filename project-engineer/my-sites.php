<?php
// my-sites.php (Project Engineer) — show ONLY sites assigned to logged-in Project Engineer
// + ✅ Reports button (opens today-tasks.php filtered by site)
// ✅ Mobile: table -> cards (like report.php)
// Reads from: sites + clients + employees + site_project_engineers

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$sites   = [];

// ---------- Auth (Project Engineer only) ----------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// allow PE Grade 1/2 (and optionally Sr. Engineer)
$allowed = [
  'project engineer grade 1',
  'project engineer grade 2',
  'sr. engineer',
];
if (!in_array($designation, $allowed, true)) {
  header("Location: index.php");
  exit;
}

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function projectStatusBadge($start, $end){
  $today = date('Y-m-d');
  $start = trim((string)$start);
  $end   = trim((string)$end);

  if ($end !== '' && $end !== '0000-00-00' && $end < $today) {
    return ['Completed', 'status-red', 'bi-check2-circle'];
  }
  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['Upcoming', 'status-yellow', 'bi-clock'];
  }
  return ['Ongoing', 'status-green', 'bi-lightning'];
}

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

// ---------- Detect optional Team Lead column ----------
$hasTeamLead = hasColumn($conn, 'sites', 'team_lead_employee_id');

$tlSelect = $hasTeamLead ? "
    tl.full_name AS team_lead_name,
    tl.employee_code AS team_lead_code,
" : "
    '' AS team_lead_name,
    '' AS team_lead_code,
";

$tlJoin = $hasTeamLead ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id" : "";

// ---------- Fetch sites assigned to this Project Engineer ----------
$sql = "
  SELECT
    s.id,
    s.project_name,
    s.project_type,
    s.project_location,
    s.scope_of_work,
    s.start_date,
    s.expected_completion_date,
    s.created_at,

    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.state AS client_state,
    c.client_type,

    m.full_name AS manager_name,
    m.employee_code AS manager_code,

    $tlSelect

    eng.engineer_names

  FROM site_project_engineers spe
  INNER JOIN sites s ON s.id = spe.site_id
  INNER JOIN clients c ON c.id = s.client_id
  LEFT JOIN employees m ON m.id = s.manager_employee_id
  $tlJoin

  LEFT JOIN (
    SELECT
      spe2.site_id,
      GROUP_CONCAT(DISTINCT e.full_name ORDER BY e.full_name SEPARATOR ', ') AS engineer_names
    FROM site_project_engineers spe2
    INNER JOIN employees e ON e.id = spe2.employee_id
    GROUP BY spe2.site_id
  ) eng ON eng.site_id = s.id

  WHERE spe.employee_id = ?
  ORDER BY s.created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
  $error = "Database error: " . mysqli_error($conn);
} else {
  mysqli_stmt_bind_param($stmt, "i", $empId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($stmt);
}

// ---------- Stats ----------
$total_sites = count($sites);
$ongoing = 0; $upcoming = 0; $completed = 0;
$today = date('Y-m-d');

foreach ($sites as $p) {
  $start = $p['start_date'] ?? '';
  $end   = $p['expected_completion_date'] ?? '';
  if (!empty($end) && $end !== '0000-00-00' && $end < $today) $completed++;
  elseif (!empty($start) && $start !== '0000-00-00' && $start > $today) $upcoming++;
  else $ongoing++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>My Sites - TEK-C</title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- DataTables (Bootstrap 5 + Responsive) -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

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
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table-responsive { overflow-x: hidden !important; }
    table.dataTable { width:100% !important; }
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:800;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: normal !important;
    }
    .table td{
      vertical-align: top; border-color: var(--border);
      font-weight:650; color:#374151;
      padding: 10px 10px !important;
      white-space: normal !important;
      word-break: break-word;
    }

    .btn-action {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 10px;
      color: var(--muted);
      font-size: 12px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      font-weight: 900;
    }
    .btn-action:hover { background: var(--bg); color: var(--blue); }
    .btn-action.reports{
      border-color: rgba(45,156,219,.25);
    }

    .proj-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
    .proj-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.25; }

    .status-badge{
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 1000;
      letter-spacing: .3px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space: nowrap;
      text-transform: uppercase;
      border:1px solid transparent;
    }
    .status-green{ background: rgba(16,185,129,.12); color:#10b981; border-color: rgba(16,185,129,.22); }
    .status-yellow{ background: rgba(245,158,11,.12); color:#f59e0b; border-color: rgba(245,158,11,.22); }
    .status-red{ background: rgba(239,68,68,.12); color:#ef4444; border-color: rgba(239,68,68,.22); }

    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 10px;
      font-weight: 650;
      outline: none;
    }
    div.dataTables_wrapper .dataTables_filter input:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
    }
    .dataTables_paginate .pagination .page-link{
      border-radius: 10px;
      margin: 0 3px;
      font-weight: 750;
    }
    th.actions-col, td.actions-col { width: 160px !important; }

    .team-block b{ color:#111827; }
    .team-block .line{ margin-bottom:4px; }
    .team-block .muted{ color:#6b7280; font-weight:800; }

    /* ---------- Mobile Cards ---------- */
    .site-card{
      border:1px solid var(--border);
      border-radius: 16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding: 12px;
    }
    .site-card .top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }
    .site-card .title{
      font-weight:1000;
      color:#111827;
      font-size: 14px;
      line-height:1.2;
      margin:0;
    }
    .site-card .meta{
      margin-top:6px;
      display:flex;
      flex-wrap:wrap;
      gap:8px 10px;
      color:#6b7280;
      font-weight:800;
      font-size:12px;
    }
    .site-kv{ margin-top:10px; display:grid; gap:8px; }
    .site-row{ display:flex; gap:10px; align-items:flex-start; }
    .site-key{
      flex:0 0 92px;
      color:#6b7280;
      font-weight:1000;
      font-size:12px;
    }
    .site-val{
      flex:1 1 auto;
      font-weight:900;
      color:#111827;
      font-size:12.5px;
      line-height:1.3;
      word-break: break-word;
    }
    .site-actions{
      margin-top:12px;
      display:grid;
      gap:8px;
    }
    .site-actions a{ width:100%; border-radius:12px; justify-content:center; }

    @media (max-width: 991.98px){
      .main{
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
      }
      .sidebar{
        position: fixed !important;
        transform: translateX(-100%);
        z-index: 1040 !important;
      }
      .sidebar.open, .sidebar.active, .sidebar.show{
        transform: translateX(0) !important;
      }
    }
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
      .sec-head { padding: 10px !important; border-radius: 12px; }
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

        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">My Sites</h1>
            <p class="text-muted mb-0">Sites where you are assigned as Project Engineer</p>
          </div>
        </div>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-geo-alt-fill"></i></div>
              <div>
                <div class="stat-label">Total</div>
                <div class="stat-value"><?php echo (int)$total_sites; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-lightning-fill"></i></div>
              <div>
                <div class="stat-label">Ongoing</div>
                <div class="stat-value"><?php echo (int)$ongoing; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-clock-fill"></i></div>
              <div>
                <div class="stat-label">Upcoming</div>
                <div class="stat-value"><?php echo (int)$upcoming; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-check2-circle"></i></div>
              <div>
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?php echo (int)$completed; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Directory -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">Site Directory</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <!-- MOBILE: Cards -->
          <div class="d-block d-md-none">
            <div class="d-grid gap-3">
              <?php if (empty($sites)): ?>
                <div class="text-muted fw-bold">No matching sites found.</div>
              <?php else: ?>
                <?php foreach ($sites as $p): ?>
                  <?php
                    [$stLabel, $stClass, $stIcon] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');

                    $clientName = trim((string)($p['client_name'] ?? ''));
                    $company    = trim((string)($p['company_name'] ?? ''));
                    $clientLine = $company !== '' ? ($clientName . ' • ' . $company) : $clientName;

                    $mgrName = trim((string)($p['manager_name'] ?? ''));
                    $mgrCode = trim((string)($p['manager_code'] ?? ''));

                    $tlName  = trim((string)($p['team_lead_name'] ?? ''));
                    $tlCode  = trim((string)($p['team_lead_code'] ?? ''));

                    $engineers = trim((string)($p['engineer_names'] ?? ''));

                    $siteId = (int)$p['id'];

                    $loc = trim((string)($p['project_location'] ?? ''));
                    $type = trim((string)($p['project_type'] ?? ''));
                    $scope = trim((string)($p['scope_of_work'] ?? ''));
                  ?>

                  <div class="site-card">
                    <div class="top">
                      <div style="flex:1 1 auto;">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                          <h4 class="title"><?php echo e($p['project_name'] ?? ''); ?></h4>
                          <span class="status-badge <?php echo e($stClass); ?>">
                            <i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?>
                          </span>
                        </div>

                        <div class="meta">
                          <?php if ($loc !== ''): ?>
                            <span><i class="bi bi-geo-alt"></i> <?php echo e($loc); ?></span>
                          <?php endif; ?>
                          <?php if ($type !== ''): ?>
                            <span><i class="bi bi-kanban"></i> <?php echo e($type); ?></span>
                          <?php endif; ?>
                        </div>

                        <?php if ($scope !== ''): ?>
                          <div class="proj-sub mt-1"><i class="bi bi-tools"></i> <?php echo e($scope); ?></div>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div class="site-kv">
                      <div class="site-row">
                        <div class="site-key">Client</div>
                        <div class="site-val"><?php echo e($clientLine); ?></div>
                      </div>

                      <div class="site-row">
                        <div class="site-key">Dates</div>
                        <div class="site-val">
                          Start: <?php echo e(safeDate($p['start_date'] ?? '')); ?><br>
                          End: <?php echo e(safeDate($p['expected_completion_date'] ?? '')); ?>
                        </div>
                      </div>

                      <div class="site-row">
                        <div class="site-key">Manager</div>
                        <div class="site-val">
                          <?php echo $mgrName !== '' ? e($mgrName) : '—'; ?>
                          <?php if ($mgrCode !== ''): ?> <span class="proj-sub">(<?php echo e($mgrCode); ?>)</span><?php endif; ?>
                        </div>
                      </div>

                      <div class="site-row">
                        <div class="site-key">Team Lead</div>
                        <div class="site-val">
                          <?php echo $tlName !== '' ? e($tlName) : '—'; ?>
                          <?php if ($tlCode !== ''): ?> <span class="proj-sub">(<?php echo e($tlCode); ?>)</span><?php endif; ?>
                          <?php if (!$hasTeamLead): ?>
                            <div class="proj-sub"><i class="bi bi-info-circle"></i> TL column not available</div>
                          <?php endif; ?>
                        </div>
                      </div>

                      <div class="site-row">
                        <div class="site-key">Engineers</div>
                        <div class="site-val"><?php echo $engineers !== '' ? e($engineers) : '—'; ?></div>
                      </div>

                      <?php if (!empty($p['client_mobile']) || !empty($p['client_email']) || !empty($p['client_state'])): ?>
                        <div class="site-row">
                          <div class="site-key">Contact</div>
                          <div class="site-val">
                            <?php if (!empty($p['client_state'])): ?>
                              <div><i class="bi bi-pin-map"></i> <?php echo e($p['client_state']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p['client_mobile'])): ?>
                              <div><i class="bi bi-telephone"></i> <?php echo e($p['client_mobile']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p['client_email'])): ?>
                              <div><i class="bi bi-envelope"></i> <?php echo e($p['client_email']); ?></div>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="site-actions">
                      <a href="view-site.php?id=<?php echo $siteId; ?>" class="btn-action" title="View Site">
                        <i class="bi bi-eye"></i> View Site
                      </a>

                      <a href="today-tasks.php?site_id=<?php echo $siteId; ?>" class="btn-action reports" title="Reports / Today Tasks">
                        <i class="bi bi-clipboard-data"></i> Reports
                      </a>
                    </div>
                  </div>

                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div class="proj-sub mt-3">
              <i class="bi bi-info-circle"></i>
              Reports button will open Today Tasks filtered by the selected site.
            </div>
          </div>

          <!-- DESKTOP/TABLET: DataTable -->
          <div class="d-none d-md-block">
            <div class="table-responsive">
              <table id="mySitesTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                <thead>
                  <tr>
                    <th>Site</th>
                    <th>Client</th>
                    <th>Management</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th class="text-end actions-col">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($sites as $p): ?>
                  <?php
                    [$stLabel, $stClass, $stIcon] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');

                    $clientName = trim((string)($p['client_name'] ?? ''));
                    $company    = trim((string)($p['company_name'] ?? ''));
                    $clientLine = $company !== '' ? ($clientName . ' • ' . $company) : $clientName;

                    $mgrName = trim((string)($p['manager_name'] ?? ''));
                    $mgrCode = trim((string)($p['manager_code'] ?? ''));

                    $tlName  = trim((string)($p['team_lead_name'] ?? ''));
                    $tlCode  = trim((string)($p['team_lead_code'] ?? ''));

                    $engineers = trim((string)($p['engineer_names'] ?? ''));

                    $siteId = (int)$p['id'];
                  ?>
                  <tr>
                    <td>
                      <div class="proj-title"><?php echo e($p['project_name'] ?? ''); ?></div>
                      <div class="proj-sub">
                        <i class="bi bi-geo-alt"></i> <?php echo e($p['project_location'] ?? ''); ?>
                        &nbsp;•&nbsp; <i class="bi bi-kanban"></i> <?php echo e($p['project_type'] ?? ''); ?>
                      </div>
                      <?php if (!empty($p['scope_of_work'])): ?>
                        <div class="proj-sub" title="<?php echo e($p['scope_of_work']); ?>">
                          <i class="bi bi-tools"></i> <?php echo e($p['scope_of_work']); ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div class="proj-title"><?php echo e($clientLine); ?></div>
                      <?php if (!empty($p['client_state'])): ?>
                        <div class="proj-sub"><i class="bi bi-pin-map"></i> <?php echo e($p['client_state']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($p['client_mobile'])): ?>
                        <div class="proj-sub"><i class="bi bi-telephone"></i> <?php echo e($p['client_mobile']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($p['client_email'])): ?>
                        <div class="proj-sub"><i class="bi bi-envelope"></i> <?php echo e($p['client_email']); ?></div>
                      <?php endif; ?>
                    </td>

                    <!-- Management -->
                    <td class="team-block">
                      <div class="line">
                        <span class="muted">Manager:</span>
                        <b><?php echo $mgrName !== '' ? e($mgrName) : '—'; ?></b>
                        <?php if ($mgrCode !== ''): ?>
                          <span class="muted">(<?php echo e($mgrCode); ?>)</span>
                        <?php endif; ?>
                      </div>

                      <div class="line">
                        <span class="muted">Team Lead:</span>
                        <b><?php echo $tlName !== '' ? e($tlName) : '—'; ?></b>
                        <?php if ($tlCode !== ''): ?>
                          <span class="muted">(<?php echo e($tlCode); ?>)</span>
                        <?php endif; ?>
                        <?php if (!$hasTeamLead): ?>
                          <div class="proj-sub"><i class="bi bi-info-circle"></i> TL column not available</div>
                        <?php endif; ?>
                      </div>

                      <div class="line">
                        <span class="muted">Engineers:</span>
                        <b><?php echo $engineers !== '' ? e($engineers) : '—'; ?></b>
                      </div>
                    </td>

                    <td>
                      <div class="proj-title">Start: <?php echo e(safeDate($p['start_date'] ?? '')); ?></div>
                      <div class="proj-sub">End: <?php echo e(safeDate($p['expected_completion_date'] ?? '')); ?></div>
                    </td>

                    <td>
                      <span class="status-badge <?php echo e($stClass); ?>">
                        <i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?>
                      </span>
                    </td>

                    <td class="text-end actions-col">
                      <!-- View -->
                      <a href="view-site.php?id=<?php echo $siteId; ?>" class="btn-action" title="View Site">
                        <i class="bi bi-eye"></i>
                      </a>

                      <!-- Reports -->
                      <a href="today-tasks.php?site_id=<?php echo $siteId; ?>" class="btn-action reports" title="Reports / Today Tasks">
                        <i class="bi bi-clipboard-data"></i>
                        <span class="d-none d-lg-inline">Reports</span>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="proj-sub mt-2">
              <i class="bi bi-info-circle"></i>
              Reports button will open Today Tasks filtered by the selected site.
            </div>
          </div>

        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
  // Init DataTable ONLY on md+ screens (table is hidden on mobile)
  function initMySitesTable() {
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    const tbl = document.getElementById('mySitesTable');
    if (!tbl) return;

    if (isDesktop) {
      if (!$.fn.DataTable.isDataTable('#mySitesTable')) {
        $('#mySitesTable').DataTable({
          responsive: true,
          autoWidth: false,
          scrollX: false,
          pageLength: 10,
          lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
          order: [[0, 'asc']],
          columnDefs: [
            { targets: [5], orderable: false, searchable: false } // Action
          ],
          language: {
            zeroRecords: "No matching sites found",
            info: "Showing _START_ to _END_ of _TOTAL_ sites",
            infoEmpty: "No sites to show",
            lengthMenu: "Show _MENU_",
            search: "Search:"
          }
        });

        setTimeout(function() {
          $('.dataTables_filter input').focus();
        }, 400);
      }
    } else {
      // if resized from desktop to mobile, destroy datatable cleanly
      if ($.fn.DataTable.isDataTable('#mySitesTable')) {
        $('#mySitesTable').DataTable().destroy();
      }
    }
  }

  $(function () {
    initMySitesTable();
    window.addEventListener('resize', initMySitesTable);
  });
</script>

</body>
</html>