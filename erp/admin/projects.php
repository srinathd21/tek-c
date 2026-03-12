<?php
session_start();
require_once 'includes/db-config.php';

$success = '';
$error = '';
$projects = [];

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function showMoney($v, $dash='—'){
  if ($v === null) return $dash;
  $v = trim((string)$v);
  if ($v === '') return $dash;
  if (!is_numeric($v)) return e($v);
  return number_format((float)$v, 2);
}

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
    return ['Completed', 'status-active', 'bi-check-circle-fill'];
  }
  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['Upcoming', 'status-inactive', 'bi-clock-fill'];
  }
  return ['Ongoing', 'status-active', 'bi-lightning-fill'];
}

function parseMembersConcat($str){
  $str = trim((string)$str);
  if ($str === '') return [];
  $items = explode('||', $str);
  $out = [];
  foreach ($items as $it){
    $it = trim($it);
    if ($it === '') continue;
    $parts = explode('|', $it);
    $out[] = [
      'name' => trim($parts[0] ?? ''),
      'designation' => trim($parts[1] ?? '')
    ];
  }
  return $out;
}

// ---------- Detect optional team_lead_employee_id column ----------
$hasTeamLeadCol = false;
$chk = mysqli_query($conn, "SHOW COLUMNS FROM sites LIKE 'team_lead_employee_id'");
if ($chk) {
  $hasTeamLeadCol = (mysqli_num_rows($chk) > 0);
  mysqli_free_result($chk);
}

// ---------- Fetch all projects (sites + clients + assignments) ----------
$teamLeadSelect = $hasTeamLeadCol ? "s.team_lead_employee_id," : "NULL AS team_lead_employee_id,";

$teamLeadJoin   = $hasTeamLeadCol
  ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id"
  : "LEFT JOIN employees tl ON 1=0";

$sql = "
  SELECT
    s.*,
    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.state AS client_state,
    c.client_type,

    $teamLeadSelect

    m.full_name   AS manager_name,
    m.designation AS manager_designation,

    tl.full_name   AS team_lead_name,
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

  GROUP BY s.id
  ORDER BY s.created_at DESC
";

$result = mysqli_query($conn, $sql);
if ($result) {
  $projects = mysqli_fetch_all($result, MYSQLI_ASSOC);
  mysqli_free_result($result);
} else {
  $error = "Error fetching projects: " . mysqli_error($conn);
}

// ---------- Stats ----------
$total_projects = count($projects);
$ongoing = 0; $upcoming = 0; $completed = 0;
$today = date('Y-m-d');

foreach ($projects as $p) {
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
  <title>Projects - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

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
      font-size: 11px;
      letter-spacing: .15px;
      color:#6b7280;
      font-weight: 800;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: normal !important;
    }
    .table td{
      vertical-align: top;
      border-color: var(--border);
      font-weight: 650;
      color:#374151;
      padding: 10px 10px !important;
      white-space: normal !important;
      word-break: break-word;
    }

    .btn-add {
      background: var(--blue);
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
      text-decoration:none;
      white-space: nowrap;
    }
    .btn-add:hover { background: #2a8bc9; color: white; box-shadow: 0 12px 24px rgba(45, 156, 219, 0.25); }

    .btn-export {
      background: #10b981;
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(16, 185, 129, 0.18);
      white-space: nowrap;
    }
    .btn-export:hover { background: #0da271; color: white; box-shadow: 0 12px 24px rgba(16, 185, 129, 0.25); }

    .btn-action {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 5px 8px;
      color: var(--muted);
      font-size: 12px;
      margin-left: 4px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
    }
    .btn-action:hover { background: var(--bg); color: var(--blue); }

    .status-badge {
      padding: 3px 8px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space: nowrap;
    }
    .status-active {
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.2);
    }
    .status-inactive {
      background: rgba(245, 158, 11, 0.1);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .project-title{ font-weight:800; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
    .project-sub{ font-size:11px; color:#6b7280; font-weight:600; line-height:1.2; }

    .contact-info {
      font-size: 11px;
      color: #6b7280;
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 2px;
      line-height: 1.2;
    }
    .contact-info i{ font-size: 11px; }

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

    th.actions-col, td.actions-col { width: 120px !important; }

    /* ✅ Team display */
    .team-cell{ display:flex; flex-direction:column; gap:6px; }
    .team-line{ display:flex; flex-wrap:wrap; gap:6px; align-items:center; font-size: 11px; line-height:1.2; }
    .team-tag{ font-size: 10px; font-weight: 900; color:#6b7280; text-transform: uppercase; letter-spacing: .25px; white-space:nowrap; }
    .name-pill{
      display:inline-flex; align-items:center; gap:6px;
      padding: 4px 8px;
      border-radius: 999px;
      border:1px solid #e5e7eb;
      background:#f9fafb;
      font-weight: 800;
      color:#111827;
      white-space:nowrap;
    }
    .name-pill .desg{ color:#6b7280; font-weight:800; }

    /* ✅ MOBILE: cards instead of table */
    .proj-card{
      border:1px solid var(--border);
      border-radius:16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding:12px;
      overflow:hidden;
    }
    .proj-card .top{
      display:flex; align-items:flex-start; justify-content:space-between; gap:10px;
    }
    .proj-card .title{ font-weight:1000; color:#111827; font-size:14px; margin:0; }
    .proj-card .sub{ color:#6b7280; font-weight:800; font-size:12px; margin-top:4px; }
    .proj-card .kv{ margin-top:10px; display:grid; gap:8px; }
    .proj-card .rowkv{ display:flex; gap:10px; align-items:flex-start; }
    .proj-card .key{ flex:0 0 86px; color:#6b7280; font-weight:1000; font-size:12px; }
    .proj-card .val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; }
    .proj-card .actions{ margin-top:10px; display:flex; gap:8px; }
    .proj-card .actions a{ flex:1 1 auto; justify-content:center; border-radius:12px; font-weight:900; }

    @media (max-width: 991.98px){ .content-scroll{ padding:18px; } }
    @media (max-width: 768px){
      .content-scroll{ padding:12px 10px 12px !important; }
      .container-fluid.maxw{ padding-left:6px !important; padding-right:6px !important; }
      .panel{ padding:12px !important; border-radius:14px; }
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

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Projects</h1>
            <p class="text-muted mb-0">View and manage all project records (sites table)</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="add-site.php" class="btn-add">
              <i class="bi bi-plus-circle"></i> Add Project
            </a>
            <button class="btn-export" data-bs-toggle="modal" data-bs-target="#exportModal">
              <i class="bi bi-download"></i> Export
            </button>
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
              <div class="stat-ic blue"><i class="bi bi-kanban-fill"></i></div>
              <div>
                <div class="stat-label">Total Projects</div>
                <div class="stat-value"><?php echo (int)$total_projects; ?></div>
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

        <!-- ✅ MOBILE: Project Cards -->
        <div class="d-block d-md-none mb-4">
          <?php if (empty($projects)): ?>
            <div class="panel text-muted" style="font-weight:900;">No projects found.</div>
          <?php else: ?>
            <div class="d-grid gap-3">
              <?php foreach ($projects as $p): ?>
                <?php
                  [$stLabel, $stClass, $stIcon] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');

                  $clientDisplay = trim((string)($p['client_name'] ?? ''));
                  $company = trim((string)($p['company_name'] ?? ''));
                  $clientLine = $company !== '' ? ($clientDisplay . ' • ' . $company) : $clientDisplay;

                  $managerName = trim((string)($p['manager_name'] ?? ''));
                  $managerDesg = trim((string)($p['manager_designation'] ?? ''));

                  $teamLeadName = trim((string)($p['team_lead_name'] ?? ''));
                  $teamLeadDesg = trim((string)($p['team_lead_designation'] ?? ''));

                  $engineers = parseMembersConcat($p['engineers_concat'] ?? '');

                  $fallbackTeamLeads = [];
                  if ($teamLeadName === '') {
                    foreach ($engineers as $eng) {
                      if (strcasecmp($eng['designation'] ?? '', 'Team Lead') === 0) $fallbackTeamLeads[] = $eng;
                    }
                  }

                  $engineerOnly = [];
                  foreach ($engineers as $eng) {
                    if ($teamLeadName === '' && strcasecmp($eng['designation'] ?? '', 'Team Lead') === 0) continue;
                    $engineerOnly[] = $eng;
                  }

                  // Compact team strings
                  $mgrTxt = $managerName !== '' ? ($managerName . ($managerDesg ? " • $managerDesg" : '')) : 'Not assigned';
                  $tlTxt = 'Not assigned';
                  if ($teamLeadName !== '') $tlTxt = $teamLeadName . ($teamLeadDesg ? " • $teamLeadDesg" : '');
                  elseif (!empty($fallbackTeamLeads)) {
                    $tmp = [];
                    foreach ($fallbackTeamLeads as $tl) $tmp[] = $tl['name'] . (!empty($tl['designation']) ? " • {$tl['designation']}" : '');
                    $tlTxt = implode(', ', $tmp);
                  }

                  $engTxt = 'None';
                  if (!empty($engineerOnly)) {
                    $tmp = [];
                    $max = 3;
                    for ($i=0; $i<min($max, count($engineerOnly)); $i++){
                      $tmp[] = $engineerOnly[$i]['name'] . (!empty($engineerOnly[$i]['designation']) ? " • {$engineerOnly[$i]['designation']}" : '');
                    }
                    $more = count($engineerOnly) - $max;
                    if ($more > 0) $tmp[] = "+$more more";
                    $engTxt = implode(', ', $tmp);
                  }
                ?>
                <div class="proj-card">
                  <div class="top">
                    <div style="flex:1 1 auto;">
                      <div class="title"><?php echo e($p['project_name'] ?? ''); ?></div>
                      <div class="sub">
                        <i class="bi bi-pin-map"></i> <?php echo e($p['project_location'] ?? ''); ?>
                        &nbsp;•&nbsp; <i class="bi bi-kanban"></i> <?php echo e($p['project_type'] ?? ''); ?>
                      </div>
                    </div>
                    <span class="status-badge <?php echo e($stClass); ?>">
                      <i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?>
                    </span>
                  </div>

                  <div class="kv">
                    <div class="rowkv">
                      <div class="key">Client</div>
                      <div class="val"><?php echo e($clientLine); ?></div>
                    </div>
                    <div class="rowkv">
                      <div class="key">Value</div>
                      <div class="val">₹ <?php echo e(showMoney($p['contract_value'] ?? '')); ?> <span style="color:#6b7280;font-weight:800;">(PMC: ₹ <?php echo e(showMoney($p['pmc_charges'] ?? '')); ?>)</span></div>
                    </div>
                    <div class="rowkv">
                      <div class="key">Manager</div>
                      <div class="val"><?php echo e($mgrTxt); ?></div>
                    </div>
                    <div class="rowkv">
                      <div class="key">Team Lead</div>
                      <div class="val"><?php echo e($tlTxt); ?></div>
                    </div>
                    <div class="rowkv">
                      <div class="key">Engineers</div>
                      <div class="val"><?php echo e($engTxt); ?></div>
                    </div>
                  </div>

                  <div class="actions">
                    <a href="view-site.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-primary">
                      <i class="bi bi-eye"></i> View
                    </a>
                    <a href="view-client.php?id=<?php echo (int)$p['client_id']; ?>" class="btn btn-outline-secondary">
                      <i class="bi bi-person"></i> Client
                    </a>
                    <?php if (!empty($p['contract_document'])): ?>
                      <a href="<?php echo e($p['contract_document']); ?>" class="btn btn-outline-success" target="_blank" rel="noopener">
                        <i class="bi bi-file-earmark-arrow-down"></i> Contract
                      </a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="panel mt-3 text-muted" style="font-weight:900;">
              <i class="bi bi-info-circle"></i> Tip: Use search in desktop/table view for faster filtering.
            </div>
          <?php endif; ?>
        </div>

        <!-- ✅ DESKTOP: Table -->
        <div class="panel mb-4 d-none d-md-block">
          <div class="panel-header">
            <h3 class="panel-title">Project Directory</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table id="projectsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Project</th>
                  <th>Client</th>
                  <th>Type / Location</th>
                  <th>Value</th>
                  <th>Status</th>
                  <th>Team (Name • Designation)</th>
                  <th class="text-end actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($projects as $p): ?>
                  <?php
                    [$stLabel, $stClass, $stIcon] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');

                    $clientDisplay = trim((string)($p['client_name'] ?? ''));
                    $company = trim((string)($p['company_name'] ?? ''));
                    $clientLine = $company !== '' ? ($clientDisplay . ' • ' . $company) : $clientDisplay;

                    $managerName = trim((string)($p['manager_name'] ?? ''));
                    $managerDesg = trim((string)($p['manager_designation'] ?? ''));

                    $teamLeadName = trim((string)($p['team_lead_name'] ?? ''));
                    $teamLeadDesg = trim((string)($p['team_lead_designation'] ?? ''));

                    $engineers = parseMembersConcat($p['engineers_concat'] ?? '');

                    $fallbackTeamLeads = [];
                    if ($teamLeadName === '') {
                      foreach ($engineers as $eng) {
                        if (strcasecmp($eng['designation'] ?? '', 'Team Lead') === 0) $fallbackTeamLeads[] = $eng;
                      }
                    }

                    $engineerOnly = [];
                    foreach ($engineers as $eng) {
                      if ($teamLeadName === '' && strcasecmp($eng['designation'] ?? '', 'Team Lead') === 0) continue;
                      $engineerOnly[] = $eng;
                    }
                  ?>
                  <tr>
                    <td>
                      <div class="project-title"><?php echo e($p['project_name'] ?? ''); ?></div>
                      <div class="project-sub">
                        <i class="bi bi-file-earmark-text"></i>
                        Agreement: <?php echo e($p['agreement_number'] ?? '—'); ?>
                      </div>
                    </td>

                    <td>
                      <div class="project-title"><?php echo e($clientLine); ?></div>
                      <?php if (!empty($p['client_state'])): ?>
                        <div class="contact-info"><i class="bi bi-geo-alt"></i> <?php echo e($p['client_state']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($p['client_mobile'])): ?>
                        <div class="contact-info"><i class="bi bi-telephone"></i> <?php echo e($p['client_mobile']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($p['client_email'])): ?>
                        <div class="contact-info"><i class="bi bi-envelope"></i> <?php echo e($p['client_email']); ?></div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div class="project-title"><?php echo e($p['project_type'] ?? ''); ?></div>
                      <div class="project-sub">
                        <i class="bi bi-pin-map"></i> <?php echo e($p['project_location'] ?? ''); ?>
                      </div>
                    </td>

                    <td>
                      <div class="project-title">₹ <?php echo e(showMoney($p['contract_value'] ?? '')); ?></div>
                      <div class="project-sub">PMC: ₹ <?php echo e(showMoney($p['pmc_charges'] ?? '')); ?></div>
                    </td>

                    <td>
                      <span class="status-badge <?php echo e($stClass); ?>">
                        <i class="bi <?php echo e($stIcon); ?>" style="font-size: 11px;"></i>
                        <?php echo e($stLabel); ?>
                      </span>
                    </td>

                    <td>
                      <div class="team-cell">

                        <div class="team-line">
                          <span class="team-tag">Manager:</span>
                          <?php if ($managerName !== ''): ?>
                            <span class="name-pill">
                              <?php echo e($managerName); ?>
                              <?php if ($managerDesg !== ''): ?><span class="desg">• <?php echo e($managerDesg); ?></span><?php endif; ?>
                            </span>
                          <?php else: ?>
                            <span class="name-pill"><span class="desg">Not assigned</span></span>
                          <?php endif; ?>
                        </div>

                        <div class="team-line">
                          <span class="team-tag">Team Lead:</span>
                          <?php if ($teamLeadName !== ''): ?>
                            <span class="name-pill">
                              <?php echo e($teamLeadName); ?>
                              <?php if ($teamLeadDesg !== ''): ?><span class="desg">• <?php echo e($teamLeadDesg); ?></span><?php endif; ?>
                            </span>
                          <?php elseif (!empty($fallbackTeamLeads)): ?>
                            <?php foreach ($fallbackTeamLeads as $tl): ?>
                              <span class="name-pill">
                                <?php echo e($tl['name']); ?>
                                <?php if (!empty($tl['designation'])): ?><span class="desg">• <?php echo e($tl['designation']); ?></span><?php endif; ?>
                              </span>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <span class="name-pill"><span class="desg">Not assigned</span></span>
                          <?php endif; ?>
                        </div>

                        <div class="team-line">
                          <span class="team-tag">Engineers:</span>
                          <?php if (!empty($engineerOnly)): ?>
                            <?php
                              $maxShow = 3;
                              $count = 0;
                              foreach ($engineerOnly as $eng):
                                $count++;
                                if ($count > $maxShow) break;
                            ?>
                              <span class="name-pill">
                                <?php echo e($eng['name']); ?>
                                <?php if (!empty($eng['designation'])): ?><span class="desg">• <?php echo e($eng['designation']); ?></span><?php endif; ?>
                              </span>
                            <?php endforeach; ?>

                            <?php if (count($engineerOnly) > $maxShow): ?>
                              <span class="name-pill"><span class="desg">+<?php echo (int)(count($engineerOnly) - $maxShow); ?> more</span></span>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="name-pill"><span class="desg">None</span></span>
                          <?php endif; ?>
                        </div>

                      </div>
                    </td>

                    <td class="text-end actions-col">
                      <a href="view-site.php?id=<?php echo (int)$p['id']; ?>" class="btn-action" title="View Project">
                        <i class="bi bi-eye"></i>
                      </a>
                      <a href="view-client.php?id=<?php echo (int)$p['client_id']; ?>" class="btn-action" title="View Client">
                        <i class="bi bi-person"></i>
                      </a>
                      <?php if (!empty($p['contract_document'])): ?>
                        <a href="<?php echo e($p['contract_document']); ?>" class="btn-action" target="_blank" rel="noopener" title="Contract">
                          <i class="bi bi-file-earmark-arrow-down"></i>
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

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="exportModalLabel">Export Projects</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="export-sites.php">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Export Format *</label>
              <select class="form-control" name="export_format" required>
                <option value="csv">CSV (Excel)</option>
                <option value="pdf">PDF Document</option>
                <option value="excel">Excel File</option>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="apply_filters" name="apply_filters" value="1" checked>
                <label class="form-check-label" for="apply_filters">Apply Current Filters</label>
                <div class="form-text">Include current search/filter criteria in export</div>
              </div>
            </div>
            <div class="col-12">
              <div class="alert alert-warning mb-0" role="alert" style="box-shadow:none;">
                <i class="bi bi-info-circle me-2"></i>
                Create <b>export-sites.php</b> if you want export to work.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-export">
            <i class="bi bi-download me-2"></i> Export
          </button>
        </div>
      </form>
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
(function () {
  $(function () {
    $('#projectsTable').DataTable({
      responsive: true,
      autoWidth: false,
      scrollX: false,
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      order: [[0, 'asc']],
      columnDefs: [
        { targets: [6], orderable: false, searchable: false }
      ],
      language: {
        zeroRecords: "No matching projects found",
        info: "Showing _START_ to _END_ of _TOTAL_ projects",
        infoEmpty: "No projects to show",
        lengthMenu: "Show _MENU_",
        search: "Search:"
      }
    });

    setTimeout(function() {
      $('.dataTables_filter input').focus();
    }, 400);
  });
})();
</script>

</body>
</html>
<?php
try {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) { }
?>