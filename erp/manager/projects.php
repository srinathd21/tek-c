<?php
// projects.php (TEK-C style)
// ✅ Shows assigned Manager + Team Lead + Project Engineers (name + designation)
// ✅ UI modified like reference compact table
// ✅ Mobile view converts table rows into cards
// ✅ PHP functions / query logic kept same

session_start();
require_once 'includes/db-config.php';

$success = '';
$error = '';
$projects = [];

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

// ---------- Fetch all projects ----------
$teamLeadSelect = $hasTeamLeadCol ? "s.team_lead_employee_id," : "NULL AS team_lead_employee_id,";

$teamLeadJoin = $hasTeamLeadCol
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

  INNER JOIN clients c
  ON c.id = s.client_id

  LEFT JOIN employees m
  ON m.id = s.manager_employee_id

  $teamLeadJoin

  LEFT JOIN site_project_engineers spe
  ON spe.site_id = s.id

  LEFT JOIN employees pe
  ON pe.id = spe.employee_id

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
$ongoing = 0;
$upcoming = 0;
$completed = 0;
$today = date('Y-m-d');

foreach ($projects as $p) {
  $start = $p['start_date'] ?? '';
  $end   = $p['expected_completion_date'] ?? '';

  if (!empty($end) && $end !== '0000-00-00' && $end < $today) {
    $completed++;
  } elseif (!empty($start) && $start !== '0000-00-00' && $start > $today) {
    $upcoming++;
  } else {
    $ongoing++;
  }
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

<link href="assets/css/layout-styles.css" rel="stylesheet" />
<link href="assets/css/topbar.css" rel="stylesheet" />
<link href="assets/css/footer.css" rel="stylesheet" />

<style>
:root{
  --page-bg:#f5f7fb;
  --card-bg:#ffffff;
  --border:#e5e7eb;
  --text:#111827;
  --muted:#6b7280;
  --soft:#f8fafc;
  --shadow:0 10px 26px rgba(15,23,42,.055);
  --radius:15px;
  --blue:#2f80ed;
}

body{
  background:var(--page-bg);
}

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

.primary-btn{
  border:0;
  background:#111827;
  color:#fff;
  height:36px;
  padding:0 14px;
  border-radius:11px;
  font-size:12px;
  font-weight:900;
  display:inline-flex;
  align-items:center;
  gap:7px;
  text-decoration:none;
  white-space:nowrap;
}

.primary-btn:hover{
  background:#020617;
  color:#fff;
}

.export-btn{
  background:#10b981;
}

.export-btn:hover{
  background:#059669;
}

.stat-card{
  background:var(--card-bg);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:12px 13px;
  min-height:78px;
  display:flex;
  align-items:center;
  gap:11px;
}

.stat-ic{
  width:38px;
  height:38px;
  border-radius:12px;
  display:grid;
  place-items:center;
  color:#fff;
  font-size:17px;
}

.blue{
  background:#2f80ed;
}

.orange{
  background:#f2994a;
}

.green{
  background:#27ae60;
}

.red{
  background:#eb5757;
}

.stat-label{
  color:var(--muted);
  font-weight:800;
  font-size:10.5px;
  text-transform:uppercase;
}

.stat-value{
  font-size:24px;
  font-weight:950;
  color:var(--text);
}

.panel{
  background:var(--card-bg);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:13px;
}

.panel-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:12px;
}

.panel-title{
  font-weight:900;
  font-size:14px;
  margin:0;
  color:var(--text);
}

.panel-subtitle{
  color:var(--muted);
  font-size:11px;
  font-weight:700;
  margin-top:2px;
}

.filter-bar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:12px;
}

.search-box{
  position:relative;
  flex:1 1 260px;
  max-width:430px;
}

.search-box i{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  color:#94a3b8;
  font-size:13px;
}

.search-box input{
  width:100%;
  height:36px;
  border:1px solid var(--border);
  border-radius:11px;
  background:#fff;
  padding:0 12px 0 34px;
  font-size:12px;
  font-weight:700;
  color:var(--text);
  outline:none;
}

.search-box input:focus{
  border-color:#bfdbfe;
  box-shadow:0 0 0 3px rgba(59,130,246,.10);
}

.filter-select{
  height:36px;
  border:1px solid var(--border);
  border-radius:11px;
  background:#fff;
  padding:0 42px 0 12px;
  font-size:12px;
  font-weight:800;
  min-width:145px;
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
  white-space:nowrap;
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

.table-title-cell{
  display:flex;
  align-items:center;
  gap:8px;
}

.table-icon{
  width:26px;
  height:26px;
  border-radius:8px;
  display:grid;
  place-items:center;
  background:#eff6ff;
  color:#2563eb;
  font-size:13px;
  flex:0 0 auto;
}

.table-primary-text{
  color:#111827;
  font-size:11.5px;
  font-weight:900;
}

.table-secondary-text{
  color:#64748b;
  font-size:10px;
  font-weight:700;
  margin-top:1px;
  line-height:1.4;
}

.badge-pill{
  border-radius:999px;
  padding:5px 8px;
  font-weight:900;
  font-size:10px;
  display:inline-flex;
  align-items:center;
  gap:6px;
  white-space:nowrap;
}

.mini-dot{
  width:6px;
  height:6px;
  border-radius:50%;
  background:currentColor;
}

.status-active{
  color:#15803d;
  background:#dcfce7;
}

.status-inactive{
  color:#6d28d9;
  background:#ede9fe;
}

.status-completed{
  color:#15803d;
  background:#dcfce7;
}

.status-ongoing{
  color:#2563eb;
  background:#dbeafe;
}

.status-upcoming{
  color:#6d28d9;
  background:#ede9fe;
}

.action-group{
  display:flex;
  justify-content:flex-end;
  gap:5px;
}

.action-btn{
  width:27px;
  height:27px;
  border-radius:9px;
  border:1px solid var(--border);
  background:#fff;
  display:grid;
  place-items:center;
  text-decoration:none;
  font-size:12px;
}

.view-btn{
  color:#475569;
  background:#f8fafc;
}

.client-btn{
  color:#0f766e;
  background:#ecfdf5;
}

.file-btn{
  color:#dc2626;
  background:#fef2f2;
}

.pagination-wrap{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding-top:12px;
}

.pagination-info{
  color:var(--muted);
  font-size:11px;
  font-weight:700;
}

.team-text{
  font-size:10px;
  color:#64748b;
  line-height:1.55;
  min-width:220px;
}

.team-chip{
  display:inline-flex;
  align-items:center;
  gap:4px;
  padding:3px 6px;
  border-radius:999px;
  background:#f8fafc;
  border:1px solid #e5e7eb;
  margin:2px 2px 2px 0;
  color:#111827;
  font-weight:800;
}

.team-chip span{
  color:#64748b;
  font-weight:800;
}

.empty-row{
  text-align:center;
  padding:24px!important;
  color:#64748b!important;
  font-weight:800!important;
}

.alert{
  border-radius:var(--radius);
  border:0;
  box-shadow:var(--shadow);
}

@media(max-width:1199px){

  .compact-table thead{
    display:none;
  }

  .compact-table,
  .compact-table tbody,
  .compact-table tr,
  .compact-table td{
    display:block;
    width:100%;
  }

  .compact-table-wrap{
    border:0;
    background:transparent;
    overflow:visible;
  }

  .compact-table tbody tr{
    border:1px solid var(--border);
    border-radius:15px;
    padding:10px;
    margin-bottom:12px;
    background:#fff;
    box-shadow:var(--shadow);
  }

  .compact-table tbody td{
    border:0;
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
    padding:7px 4px;
  }

  .compact-table tbody td::before{
    content:attr(data-label);
    font-size:10px;
    font-weight:900;
    color:#64748b;
    text-transform:uppercase;
    flex:0 0 95px;
  }

  .compact-table tbody td:first-child{
    display:block;
    padding-bottom:10px;
    border-bottom:1px dashed #e5e7eb;
    margin-bottom:5px;
  }

  .compact-table tbody td:first-child::before{
    display:none;
  }

  .table-title-cell{
    align-items:flex-start;
  }

  .action-group{
    justify-content:flex-start;
  }

  .team-text{
    min-width:0;
    width:100%;
  }

  .pagination-wrap{
    display:block;
  }
}

@media(max-width:575px){

  .content-scroll{
    padding:12px;
  }

  .page-heading{
    align-items:flex-start;
    flex-direction:column;
  }

  .page-heading .d-flex{
    width:100%;
  }

  .primary-btn{
    flex:1;
    justify-content:center;
  }

  .filter-bar{
    display:block;
  }

  .search-box{
    max-width:100%;
    margin-bottom:8px;
  }

  .filter-select{
    width:100%;
  }

  .stat-card{
    min-height:72px;
  }
}
</style>

</head>

<body>

<div class="app">

<?php include 'includes/sidebar.php'; ?>

<main class="main" aria-label="Main">

<?php include 'includes/topbar.php'; ?>

<div class="content-scroll">

<div class="container-fluid projects-wrapper px-0">

<!-- PAGE HEADING -->
<div class="page-heading">

  <div>
    <h1>Projects</h1>
    <p>Manage all ongoing, completed and upcoming projects</p>
  </div>

  <div class="d-flex gap-2">
    <a href="add-site.php" class="primary-btn">
      <i class="bi bi-plus-circle"></i>
      Add Project
    </a>

    <button class="primary-btn export-btn" data-bs-toggle="modal" data-bs-target="#exportModal">
      <i class="bi bi-download"></i>
      Export
    </button>
  </div>

</div>

<!-- ALERTS -->
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

<!-- STATS -->
<div class="row g-3 mb-3">

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-ic blue">
        <i class="bi bi-folder2-open"></i>
      </div>
      <div>
        <div class="stat-label">Total Projects</div>
        <div class="stat-value"><?php echo (int)$total_projects; ?></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-ic green">
        <i class="bi bi-lightning-fill"></i>
      </div>
      <div>
        <div class="stat-label">Ongoing</div>
        <div class="stat-value"><?php echo (int)$ongoing; ?></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-ic orange">
        <i class="bi bi-clock-fill"></i>
      </div>
      <div>
        <div class="stat-label">Upcoming</div>
        <div class="stat-value"><?php echo (int)$upcoming; ?></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-ic red">
        <i class="bi bi-check-circle-fill"></i>
      </div>
      <div>
        <div class="stat-label">Completed</div>
        <div class="stat-value"><?php echo (int)$completed; ?></div>
      </div>
    </div>
  </div>

</div>

<!-- PANEL -->
<div class="panel">

  <div class="panel-header">
    <div>
      <h3 class="panel-title">All Projects</h3>
      <div class="panel-subtitle">Compact responsive project directory</div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <div class="filter-bar">

    <div class="search-box">
      <i class="bi bi-search"></i>
      <input
        type="text"
        id="projectSearch"
        placeholder="Search project, client, manager or location..."
      >
    </div>

    <div>
      <select class="filter-select" id="statusFilter">
        <option value="">All Status</option>
        <option value="ongoing">Ongoing</option>
        <option value="completed">Completed</option>
        <option value="upcoming">Upcoming</option>
      </select>
    </div>

  </div>

  <!-- TABLE -->
  <div class="compact-table-wrap">

    <table class="table compact-table align-middle" id="projectsTable">

      <thead>
        <tr>
          <th>Project</th>
          <th>Client</th>
          <th>Status</th>
          <th>Timeline</th>
          <th>Value</th>
          <th>Team</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>

      <tbody>

      <?php if (empty($projects)): ?>

        <tr>
          <td colspan="7" class="empty-row">
            No project records found.
          </td>
        </tr>

      <?php else: ?>

        <?php foreach ($projects as $p): ?>

          <?php
            [$stLabel, $stClass, $stIcon] =
              projectStatusBadge(
                $p['start_date'] ?? '',
                $p['expected_completion_date'] ?? ''
              );

            $statusText = strtolower($stLabel);

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
                if (strcasecmp($eng['designation'] ?? '', 'Team Lead') === 0) {
                  $fallbackTeamLeads[] = $eng;
                }
              }
            }

            $engineerOnly = [];
            foreach ($engineers as $eng) {
              if ($teamLeadName === '') {
                if (strcasecmp($eng['designation'] ?? '', 'Team Lead') === 0) continue;
              }
              $engineerOnly[] = $eng;
            }

            $startDate = !empty($p['start_date']) && $p['start_date'] !== '0000-00-00'
              ? date('d M Y', strtotime($p['start_date']))
              : '—';

            $endDate = !empty($p['expected_completion_date']) && $p['expected_completion_date'] !== '0000-00-00'
              ? date('d M Y', strtotime($p['expected_completion_date']))
              : '—';
          ?>

          <tr data-status="<?php echo e($statusText); ?>">

            <!-- PROJECT -->
            <td data-label="Project">
              <div class="table-title-cell">
                <div class="table-icon">
                  <i class="bi bi-building"></i>
                </div>

                <div>
                  <div class="table-primary-text">
                    <?php echo e($p['project_name'] ?? ''); ?>
                  </div>

                  <div class="table-secondary-text">
                    <?php echo e($p['agreement_number'] ?? '—'); ?>
                    •
                    <?php echo e($p['project_type'] ?? ''); ?>
                    •
                    <?php echo e($p['project_location'] ?? ''); ?>
                  </div>
                </div>
              </div>
            </td>

            <!-- CLIENT -->
            <td data-label="Client">
              <div class="table-primary-text">
                <?php echo e($clientLine); ?>
              </div>

              <div class="table-secondary-text">
                <?php if (!empty($p['client_state'])): ?>
                  <i class="bi bi-geo-alt"></i>
                  <?php echo e($p['client_state']); ?>
                <?php endif; ?>

                <?php if (!empty($p['client_mobile'])): ?>
                  <br>
                  <i class="bi bi-telephone"></i>
                  <?php echo e($p['client_mobile']); ?>
                <?php endif; ?>

                <?php if (!empty($p['client_email'])): ?>
                  <br>
                  <i class="bi bi-envelope"></i>
                  <?php echo e($p['client_email']); ?>
                <?php endif; ?>
              </div>
            </td>

            <!-- STATUS -->
            <td data-label="Status">
              <span class="badge-pill status-<?php echo e($statusText); ?>">
                <span class="mini-dot"></span>
                <?php echo e($stLabel); ?>
              </span>
            </td>

            <!-- TIMELINE -->
            <td data-label="Timeline">
              <div class="table-primary-text">
                <?php echo e($startDate); ?>
              </div>
              <div class="table-secondary-text">
                to <?php echo e($endDate); ?>
              </div>
            </td>

            <!-- VALUE -->
            <td data-label="Value">
              <div class="table-primary-text">
                ₹ <?php echo e(showMoney($p['contract_value'] ?? '')); ?>
              </div>
              <div class="table-secondary-text">
                PMC: ₹ <?php echo e(showMoney($p['pmc_charges'] ?? '')); ?>
              </div>
            </td>

            <!-- TEAM -->
            <td data-label="Team">
              <div class="team-text">

                <div>
                  <b>Manager:</b>
                  <?php if ($managerName !== ''): ?>
                    <span class="team-chip">
                      <?php echo e($managerName); ?>
                      <?php if ($managerDesg !== ''): ?>
                        <span>• <?php echo e($managerDesg); ?></span>
                      <?php endif; ?>
                    </span>
                  <?php else: ?>
                    <span class="team-chip"><span>Not assigned</span></span>
                  <?php endif; ?>
                </div>

                <div>
                  <b>Team Lead:</b>

                  <?php if ($teamLeadName !== ''): ?>
                    <span class="team-chip">
                      <?php echo e($teamLeadName); ?>
                      <?php if ($teamLeadDesg !== ''): ?>
                        <span>• <?php echo e($teamLeadDesg); ?></span>
                      <?php endif; ?>
                    </span>

                  <?php elseif (!empty($fallbackTeamLeads)): ?>
                    <?php foreach ($fallbackTeamLeads as $tl): ?>
                      <span class="team-chip">
                        <?php echo e($tl['name']); ?>
                        <?php if (!empty($tl['designation'])): ?>
                          <span>• <?php echo e($tl['designation']); ?></span>
                        <?php endif; ?>
                      </span>
                    <?php endforeach; ?>

                  <?php else: ?>
                    <span class="team-chip"><span>Not assigned</span></span>
                  <?php endif; ?>
                </div>

                <div>
                  <b>Engineers:</b>

                  <?php if (!empty($engineerOnly)): ?>
                    <?php
                      $maxShow = 2;
                      $count = 0;
                      foreach ($engineerOnly as $eng):
                        $count++;
                        if ($count > $maxShow) break;
                    ?>
                      <span class="team-chip">
                        <?php echo e($eng['name']); ?>
                        <?php if (!empty($eng['designation'])): ?>
                          <span>• <?php echo e($eng['designation']); ?></span>
                        <?php endif; ?>
                      </span>
                    <?php endforeach; ?>

                    <?php if (count($engineerOnly) > $maxShow): ?>
                      <span class="team-chip">
                        <span>+<?php echo (int)(count($engineerOnly) - $maxShow); ?> more</span>
                      </span>
                    <?php endif; ?>

                  <?php else: ?>
                    <span class="team-chip"><span>None</span></span>
                  <?php endif; ?>
                </div>

              </div>
            </td>

            <!-- ACTIONS -->
            <td data-label="Actions">
              <div class="action-group">

                <a
                  href="view-site.php?id=<?php echo (int)$p['id']; ?>"
                  class="action-btn view-btn"
                  title="View Project"
                >
                  <i class="bi bi-eye"></i>
                </a>

                <a
                  href="view-client.php?id=<?php echo (int)$p['client_id']; ?>"
                  class="action-btn client-btn"
                  title="View Client"
                >
                  <i class="bi bi-person"></i>
                </a>

                <?php if (!empty($p['contract_document'])): ?>
                  <a
                    href="<?php echo e($p['contract_document']); ?>"
                    target="_blank"
                    rel="noopener"
                    class="action-btn file-btn"
                    title="Contract"
                  >
                    <i class="bi bi-file-earmark-arrow-down"></i>
                  </a>
                <?php endif; ?>

              </div>
            </td>

          </tr>

        <?php endforeach; ?>

      <?php endif; ?>

      </tbody>

    </table>

  </div>

  <!-- PAGINATION INFO -->
  <div class="pagination-wrap">
    <div class="pagination-info" id="recordsInfo">
      Showing <?php echo count($projects); ?> project records
    </div>
  </div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<!-- EXPORT MODAL -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">

  <div class="modal-dialog">

    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="exportModalLabel">
          Export Projects
        </h5>

        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form method="POST" action="export-sites.php">

        <div class="modal-body">

          <div class="mb-3">
            <label class="form-label">Export Format *</label>
            <select class="form-select" name="export_format" required>
              <option value="csv">CSV</option>
              <option value="excel">Excel</option>
              <option value="pdf">PDF</option>
            </select>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="apply_filters" name="apply_filters" value="1" checked>
            <label class="form-check-label" for="apply_filters">
              Apply Current Filters
            </label>
            <div class="form-text">
              Include current search/filter criteria in export
            </div>
          </div>

        </div>

        <div class="modal-footer">

          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            Cancel
          </button>

          <button type="submit" class="btn btn-success">
            <i class="bi bi-download me-1"></i>
            Export
          </button>

        </div>

      </form>

    </div>

  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

  const searchInput = document.getElementById('projectSearch');
  const statusFilter = document.getElementById('statusFilter');
  const tableRows = document.querySelectorAll('#projectsTable tbody tr[data-status]');
  const recordsInfo = document.getElementById('recordsInfo');

  function filterProjects() {

    const searchValue = searchInput.value.toLowerCase().trim();
    const statusValue = statusFilter.value.toLowerCase().trim();

    let visibleCount = 0;

    tableRows.forEach(function (row) {

      const rowText = row.innerText.toLowerCase();
      const rowStatus = row.getAttribute('data-status') || '';

      const matchesSearch = rowText.includes(searchValue);
      const matchesStatus = !statusValue || rowStatus === statusValue;

      if (matchesSearch && matchesStatus) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }

    });

    recordsInfo.textContent = 'Showing ' + visibleCount + ' project records';
  }

  searchInput.addEventListener('input', filterProjects);
  statusFilter.addEventListener('change', filterProjects);

});
</script>

</body>
</html>

<?php
if (isset($conn)) {
  mysqli_close($conn);
}
?>