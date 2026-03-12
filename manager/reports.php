<?php
session_start();
require_once 'includes/db-config.php';


$loggedEmployeeId   = (int)($_SESSION['employee_id'] ?? 0);
$loggedDesignation  = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$isManager          = ($loggedDesignation === 'manager');

$success = '';
$error   = '';
$type    = (isset($_GET['type']) && strtolower($_GET['type']) === 'dar') ? 'dar' : 'dpr';
$siteId  = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;

if (isset($_GET['msg'])) {
  if ($_GET['msg'] === 'ok' && $success === '') $success = "Status updated successfully.";
  if ($_GET['msg'] === 'err' && $error === '') $error = "Status update failed.";
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- DPR Editable Status Update (Manager only) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_dpr_editable'])) {
  if (!$isManager) {
    $error = "Only managers can change DPR editable status.";
  } else {
    $dprIdToUpdate = (int)($_POST['dpr_id'] ?? 0);
    $newStatus     = (int)($_POST['editable_status'] ?? 0); // 1 or 2
    $returnType    = (isset($_POST['return_type']) && strtolower($_POST['return_type']) === 'dar') ? 'dar' : 'dpr';
    $returnSiteId  = (int)($_POST['return_site_id'] ?? 0);

    if ($dprIdToUpdate <= 0 || !in_array($newStatus, [1,2], true)) {
      $error = "Invalid DPR status update request.";
    } elseif (!managerCanControlDpr($conn, $dprIdToUpdate, $loggedEmployeeId)) {
      $error = "You do not have permission to update this DPR.";
    } else {
      $stUpd = mysqli_prepare($conn, "UPDATE dpr_reports SET editable_status = ? WHERE id = ? LIMIT 1");
      if (!$stUpd) {
        $error = "DB Error: " . mysqli_error($conn);
      } else {
        mysqli_stmt_bind_param($stUpd, "ii", $newStatus, $dprIdToUpdate);
if (mysqli_stmt_execute($stUpd) && mysqli_stmt_affected_rows($stUpd) >= 0) {
          $success = ($newStatus === 1)
            ? "DPR edit enabled successfully."
            : "DPR edit disabled successfully.";
        } else {
          $error = "Failed to update DPR editable status: " . mysqli_stmt_error($stUpd);
        }
        mysqli_stmt_close($stUpd);
      }
    }

    // Redirect back to same page to avoid form resubmit
    if ($returnSiteId > 0) {
      $msg = ($error !== '') ? 'err' : 'ok';
      header("Location: reports.php?site_id=".$returnSiteId."&type=".$returnType."&msg=".$msg);
      exit;
    }
  }
}

// ---------------- Helpers ----------------

function managerCanControlDpr(mysqli $conn, int $dprId, int $managerEmployeeId): bool {
  if ($dprId <= 0 || $managerEmployeeId <= 0) return false;

  $sql = "
    SELECT d.id
    FROM dpr_reports d
    INNER JOIN sites s ON s.id = d.site_id
    WHERE d.id = ?
      AND s.manager_employee_id = ?
    LIMIT 1
  ";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return false;

  mysqli_stmt_bind_param($st, "ii", $dprId, $managerEmployeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $ok = (bool)mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);

  return $ok;
}


function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($d, $dash='—'){
  $d = trim((string)$d);
  if ($d === '' || $d === '0000-00-00') return $dash;
  return e($d);
}

function jsonCount($json){
  if ($json === null) return 0;
  $json = trim((string)$json);
  if ($json === '' || strtolower($json) === 'null') return 0;
  $arr = json_decode($json, true);
  if (!is_array($arr)) return 0;
  return count($arr);
}

function statusBadge($label, $class, $icon){
  return '<span class="status-badge '.e($class).'"><i class="bi '.e($icon).'" style="font-size:11px;"></i> '.e($label).'</span>';
}

function monthKey($dateStr){
  $d = trim((string)$dateStr);
  if ($d === '' || $d === '0000-00-00') return '';
  return substr($d, 0, 7);
}

function yearKey($dateStr){
  $d = trim((string)$dateStr);
  if ($d === '' || $d === '0000-00-00') return '';
  return substr($d, 0, 4);
}

/**
 * Fix for your error: showMoney() is missing in your project.
 * This safely formats numeric money values.
 */
function showMoney($value, $dash='—') {
  if ($value === null) return $dash;
  $v = trim((string)$value);
  if ($v === '') return $dash;

  // remove commas/spaces
  $v = str_replace([',', ' '], '', $v);

  if (!is_numeric($v)) return $dash;

  // 0 should show as 0
  $num = (float)$v;
  return number_format($num, 2);
}

// ---------------- Decide view ----------------
// If site_id is NOT provided => show Projects List
$showProjects = ($siteId <= 0);

$today     = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear  = date('Y');

// ---------------- View 1: Projects list ----------------
$projects = [];
if ($showProjects) {
  $sqlP = "
    SELECT
      s.id,
      s.project_name,
      s.project_type,
      s.project_location,
      s.start_date,
      s.expected_completion_date,
      s.contract_value,
      s.pmc_charges,
      s.created_at,

      c.client_name,
      c.company_name,
      c.state AS client_state,
      c.email AS client_email,
      c.mobile_number AS client_mobile

    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    ORDER BY s.created_at DESC
  ";

  $resP = mysqli_query($conn, $sqlP);
  if ($resP) {
    $projects = mysqli_fetch_all($resP, MYSQLI_ASSOC);
    mysqli_free_result($resP);
  } else {
    $error = "Error fetching projects: " . mysqli_error($conn);
  }
}

// ---------------- View 2: Reports list (filtered by site_id) ----------------
$rows = [];
$siteInfo = null;

if (!$showProjects) {

  // fetch selected site info for header
  $sqlSite = "
    SELECT s.id, s.project_name, s.project_location, s.project_type,
           c.client_name, c.company_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.id = ?
    LIMIT 1
  ";
  $st = mysqli_prepare($conn, $sqlSite);
  mysqli_stmt_bind_param($st, "i", $siteId);
  mysqli_stmt_execute($st);
  $rs = mysqli_stmt_get_result($st);
  $siteInfo = $rs ? mysqli_fetch_assoc($rs) : null;
  mysqli_stmt_close($st);

  if (!$siteInfo) {
    $error = "Project not found.";
    $showProjects = true;
  } else {

    if ($type === 'dpr') {
      $sql = "
        SELECT
          d.id,
          d.site_id,
          d.employee_id,
          d.dpr_no,
          d.dpr_date,
          d.weather,
          d.site_condition,
          d.manpower_json,
          d.machinery_json,
          d.material_json,
          d.work_progress_json,
          d.constraints_json,
          d.report_distribute_to,
          d.prepared_by,
          d.created_at,
          d.editable_status,
          s.project_name,
          s.project_location,
          s.project_type,

          c.client_name,
          c.company_name,
          c.mobile_number AS client_mobile,
          c.email AS client_email,
          c.state AS client_state,
          c.client_type,

          emp.full_name AS employee_name,
          emp.designation AS employee_designation,
          s.manager_employee_id AS site_manager_employee_id

        FROM dpr_reports d
        INNER JOIN sites s ON s.id = d.site_id
        INNER JOIN clients c ON c.id = s.client_id
        LEFT JOIN employees emp ON emp.id = d.employee_id
        WHERE d.site_id = ?
        ORDER BY d.dpr_date DESC, d.id DESC
      ";
    } else {
      $sql = "
        SELECT
          r.id,
          r.site_id,
          r.employee_id,
          r.dar_no,
          r.dar_date,
          r.division,
          r.incharge,
          r.activities_json,
          r.report_distribute_to,
          r.prepared_by,
          r.created_at,

          s.project_name,
          s.project_location,
          s.project_type,

          c.client_name,
          c.company_name,
          c.mobile_number AS client_mobile,
          c.email AS client_email,
          c.state AS client_state,
          c.client_type,

          emp.full_name AS employee_name,
          emp.designation AS employee_designation

        FROM dar_reports r
        INNER JOIN sites s ON s.id = r.site_id
        INNER JOIN clients c ON c.id = s.client_id
        LEFT JOIN employees emp ON emp.id = r.employee_id
        WHERE r.site_id = ?
        ORDER BY r.dar_date DESC, r.id DESC
      ";
    }

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $siteId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result) {
      $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
      mysqli_free_result($result);
    } else {
      $error = "Error fetching reports.";
    }
    mysqli_stmt_close($stmt);
  }
}

// ---------------- Stats ----------------
if ($showProjects) {
  $total = count($projects);

  // project stats: ongoing/upcoming/completed based on start/end
  $ongoing = 0; $upcoming = 0; $completed = 0;
  foreach ($projects as $p) {
    $start = $p['start_date'] ?? '';
    $end   = $p['expected_completion_date'] ?? '';

    if (!empty($end) && $end !== '0000-00-00' && $end < $today) $completed++;
    elseif (!empty($start) && $start !== '0000-00-00' && $start > $today) $upcoming++;
    else $ongoing++;
  }
} else {
  $total = count($rows);
  $cntToday = 0; $cntMonth = 0; $cntYear = 0;
  foreach ($rows as $r) {
    $d = ($type === 'dpr') ? ($r['dpr_date'] ?? '') : ($r['dar_date'] ?? '');
    if ($d === $today) $cntToday++;
    if (monthKey($d) === $thisMonth) $cntMonth++;
    if (yearKey($d) === $thisYear) $cntYear++;
  }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reports - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

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
      padding: 5px 10px;
      color: var(--muted);
      font-size: 12px;
      margin-left: 4px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
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
    .status-neutral {
      background: rgba(59, 130, 246, 0.10);
      color: #2563eb;
      border: 1px solid rgba(59, 130, 246, 0.2);
    }

    .title{ font-weight:800; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
    .sub{ font-size:11px; color:#6b7280; font-weight:600; line-height:1.2; }

    .tab-pill{
      display:inline-flex; gap:8px; background:#fff; border:1px solid var(--border);
      padding:6px; border-radius: 14px; box-shadow: var(--shadow);
    }
    .tab-pill a{
      text-decoration:none; font-weight:900; font-size:12px; padding:8px 12px; border-radius:12px;
      color:#6b7280; display:inline-flex; gap:8px; align-items:center;
    }
    .tab-pill a.active{ background: var(--blue); color: #fff; }
    .chip{
      display:inline-flex; align-items:center; gap:6px;
      border:1px solid #e5e7eb; background:#f9fafb; border-radius:999px;
      padding:4px 8px; font-weight:800; font-size:11px; color:#111827;
      white-space:nowrap;
    }
    th.actions-col, td.actions-col { width: 170px !important; }

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

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <?php if ($showProjects): ?>
              <h1 class="h3 fw-bold text-dark mb-1">Projects</h1>
              <p class="text-muted mb-0">Select a project to view DPR / DAR reports</p>
            <?php else: ?>
              <h1 class="h3 fw-bold text-dark mb-1">Reports</h1>
              <p class="text-muted mb-0">
                Project: <b><?php echo e($siteInfo['project_name'] ?? ''); ?></b>
                • <?php echo e(($siteInfo['project_type'] ?? '').' • '.($siteInfo['project_location'] ?? '')); ?>
              </p>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center gap-2">
            <?php if (!$showProjects): ?>
              <div class="tab-pill">
                <a href="reports.php?site_id=<?php echo (int)$siteId; ?>&type=dpr" class="<?php echo ($type==='dpr') ? 'active' : ''; ?>">
                  <i class="bi bi-journal-text"></i> DPR
                </a>
                <a href="reports.php?site_id=<?php echo (int)$siteId; ?>&type=dar" class="<?php echo ($type==='dar') ? 'active' : ''; ?>">
                  <i class="bi bi-clipboard-check"></i> DAR
                </a>
              </div>

              <a href="reports.php" class="btn-action" title="Back to Projects">
                <i class="bi bi-arrow-left"></i> Projects
              </a>

              <button class="btn-export" data-bs-toggle="modal" data-bs-target="#exportModal">
                <i class="bi bi-download"></i> Export
              </button>
            <?php endif; ?>
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
          <?php if ($showProjects): ?>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-collection-fill"></i></div>
                <div>
                  <div class="stat-label">Total Projects</div>
                  <div class="stat-value"><?php echo (int)$total; ?></div>
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
          <?php else: ?>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-collection-fill"></i></div>
                <div>
                  <div class="stat-label">Total <?php echo strtoupper($type); ?></div>
                  <div class="stat-value"><?php echo (int)$total; ?></div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-calendar-check-fill"></i></div>
                <div>
                  <div class="stat-label">Today (<?php echo e($today); ?>)</div>
                  <div class="stat-value"><?php echo (int)$cntToday; ?></div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic yellow"><i class="bi bi-calendar2-month-fill"></i></div>
                <div>
                  <div class="stat-label">This Month</div>
                  <div class="stat-value"><?php echo (int)$cntMonth; ?></div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-calendar3"></i></div>
                <div>
                  <div class="stat-label">This Year</div>
                  <div class="stat-value"><?php echo (int)$cntYear; ?></div>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Table -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">
              <?php echo $showProjects ? 'Project Directory' : strtoupper($type).' Directory'; ?>
            </h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <?php if ($showProjects): ?>

              <table id="mainTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                <thead>
                  <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>Type / Location</th>
                    <th>Value</th>
                    <th>Schedule</th>
                    <th class="text-end actions-col">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($projects as $p): ?>
                    <?php
                      $clientName = trim((string)($p['client_name'] ?? ''));
                      $company    = trim((string)($p['company_name'] ?? ''));
                      $clientLine = ($company !== '') ? ($clientName.' • '.$company) : $clientName;
                    ?>
                    <tr>
                      <td>
                        <div class="title"><?php echo e($p['project_name'] ?? ''); ?></div>
                        <div class="sub">
                          <i class="bi bi-hash"></i> ID: <?php echo (int)$p['id']; ?>
                        </div>
                      </td>

                      <td>
                        <div class="title"><?php echo e($clientLine); ?></div>
                        <div class="sub">
                          <?php if (!empty($p['client_state'])): ?>
                            <span class="me-2"><i class="bi bi-geo-alt"></i> <?php echo e($p['client_state']); ?></span>
                          <?php endif; ?>
                          <?php if (!empty($p['client_email'])): ?>
                            <span class="me-2"><i class="bi bi-envelope"></i> <?php echo e($p['client_email']); ?></span>
                          <?php endif; ?>
                        </div>
                      </td>

                      <td>
                        <div class="title"><?php echo e($p['project_type'] ?? ''); ?></div>
                        <div class="sub">
                          <i class="bi bi-pin-map"></i> <?php echo e($p['project_location'] ?? ''); ?>
                        </div>
                      </td>

                      <td>
                        <div class="title">₹ <?php echo e(showMoney($p['contract_value'] ?? '')); ?></div>
                        <div class="sub">PMC: ₹ <?php echo e(showMoney($p['pmc_charges'] ?? '')); ?></div>
                      </td>

                      <td>
                        <div class="chip"><i class="bi bi-calendar3"></i> <?php echo safeDate($p['start_date'] ?? ''); ?></div>
                        <div class="mt-1 chip"><i class="bi bi-calendar-check"></i> <?php echo safeDate($p['expected_completion_date'] ?? ''); ?></div>
                      </td>

                      <td class="text-end actions-col">
                        <a href="reports.php?site_id=<?php echo (int)$p['id']; ?>&type=dpr" class="btn-action" title="View Reports">
                          <i class="bi bi-folder2-open"></i> Reports
                        </a>
                        <a href="view-site.php?id=<?php echo (int)$p['id']; ?>" class="btn-action" title="View Project">
                          <i class="bi bi-building"></i>
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

            <?php else: ?>

              <table id="mainTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                <thead>
                  <?php if ($type === 'dpr'): ?>
                    <tr>
                      <th>DPR</th>
                      <th>Conditions</th>
                      <th>Quick Counts</th>
                      <th>Prepared</th>
                      <th class="text-end actions-col">Actions</th>
                    </tr>
                  <?php else: ?>
                    <tr>
                      <th>DAR</th>
                      <th>Division / Incharge</th>
                      <th>Activities</th>
                      <th>Prepared</th>
                      <th class="text-end actions-col">Actions</th>
                    </tr>
                  <?php endif; ?>
                </thead>

                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <?php if ($type === 'dpr'): ?>
                      <?php
                        $editableStatus = (int)($r['editable_status'] ?? 2);

                        // Owner can edit only when manager enabled
                        $canEditThisDpr = ($editableStatus === 1 && $loggedEmployeeId > 0 && (int)$r['employee_id'] === $loggedEmployeeId);

                        // Manager can enable/disable only for DPRs of sites he manages (checked again on POST)
                        // $canManageEditable = $isManager;


                        $canManageEditable = $isManager && ((int)($r['site_manager_employee_id'] ?? 0) === $loggedEmployeeId);

                        $dprDate = $r['dpr_date'] ?? '';
                        $weather = trim((string)($r['weather'] ?? ''));
                        $siteCond = trim((string)($r['site_condition'] ?? ''));

                        $mp  = jsonCount($r['manpower_json'] ?? null);
                        $mac = jsonCount($r['machinery_json'] ?? null);
                        $mat = jsonCount($r['material_json'] ?? null);
                        $wrk = jsonCount($r['work_progress_json'] ?? null);
                        $con = jsonCount($r['constraints_json'] ?? null);

                        $empName = trim((string)($r['employee_name'] ?? ''));
                        $empDesg = trim((string)($r['employee_designation'] ?? ''));

                        $badge = statusBadge('DPR', 'status-neutral', 'bi-journal-text');
                        if ($dprDate === $today) $badge = statusBadge('TODAY', 'status-active', 'bi-calendar-check-fill');
                      ?>
                      <tr>
                        <td>
                          <div class="title"><?php echo e($r['dpr_no'] ?? ''); ?></div>
                          <div class="sub">
                            <?php echo $badge; ?>
                            <span class="ms-2"><i class="bi bi-calendar3"></i> <?php echo safeDate($dprDate); ?></span>
                          </div>
                        </td>

                        <td>
                          <div class="chip"><i class="bi bi-cloud-sun"></i> Weather: <?php echo e($weather !== '' ? $weather : '—'); ?></div>
                          <div class="mt-1 chip"><i class="bi bi-geo"></i> Site: <?php echo e($siteCond !== '' ? $siteCond : '—'); ?></div>
                        </td>

                        <td>
                          <div class="chip"><i class="bi bi-people"></i> Manpower: <?php echo (int)$mp; ?></div>
                          <div class="mt-1 chip"><i class="bi bi-truck"></i> Machinery: <?php echo (int)$mac; ?></div>
                          <div class="mt-1 chip"><i class="bi bi-box-seam"></i> Material: <?php echo (int)$mat; ?></div>
                          <div class="mt-1 chip"><i class="bi bi-list-task"></i> Progress: <?php echo (int)$wrk; ?></div>
                          <?php if ($con > 0): ?>
                            <div class="mt-1 chip"><i class="bi bi-exclamation-circle"></i> Constraints: <?php echo (int)$con; ?></div>
                          <?php endif; ?>
                        </td>

                        <td>
                          <div class="title"><?php echo e($r['prepared_by'] ?? ($empName !== '' ? $empName : '—')); ?></div>
                          <div class="sub">
                            <?php if ($empName !== ''): ?>
                              <i class="bi bi-person-badge"></i> <?php echo e($empName); ?>
                              <?php if ($empDesg !== ''): ?> • <?php echo e($empDesg); ?><?php endif; ?>
                            <?php else: ?>
                              <i class="bi bi-person"></i> —
                            <?php endif; ?>
                          </div>
                        </td>

<td class="text-end actions-col">
  <a href="view-dpr.php?id=<?php echo (int)$r['id']; ?>" class="btn-action" title="View DPR">
    <i class="bi bi-eye"></i>
  </a>

  <?php if ($canEditThisDpr): ?>
    <a href="edit-dpr.php?id=<?php echo (int)$r['id']; ?>" class="btn-action" title="Edit DPR">
      <i class="bi bi-pencil-square"></i>
    </a>
  <?php endif; ?>

  <?php if ($canManageEditable): ?>
    <form method="POST" class="d-inline-flex align-items-center gap-1 ms-1">
      <input type="hidden" name="toggle_dpr_editable" value="1">
      <input type="hidden" name="dpr_id" value="<?php echo (int)$r['id']; ?>">
      <input type="hidden" name="return_site_id" value="<?php echo (int)$siteId; ?>">
      <input type="hidden" name="return_type" value="dpr">

      <select name="editable_status"
              class="form-select form-select-sm"
              style="width:auto; min-width:120px;"
              onchange="this.form.submit()"
              title="Change DPR edit status">
        <option value="1" <?php echo ($editableStatus === 1 ? 'selected' : ''); ?>>Rejected</option>
        <option value="2" <?php echo ($editableStatus === 2 ? 'selected' : ''); ?>>Approved</option>
      </select>
    </form>
  <?php endif; ?>
</td>
                      </tr>

                    <?php else: ?>
                      <?php
                        $darDate = $r['dar_date'] ?? '';
                        $division = trim((string)($r['division'] ?? ''));
                        $incharge = trim((string)($r['incharge'] ?? ''));
                        $actCount = jsonCount($r['activities_json'] ?? null);

                        $empName = trim((string)($r['employee_name'] ?? ''));
                        $empDesg = trim((string)($r['employee_designation'] ?? ''));

                        $badge = statusBadge('DAR', 'status-neutral', 'bi-clipboard-check');
                        if ($darDate === $today) $badge = statusBadge('TODAY', 'status-active', 'bi-calendar-check-fill');
                      ?>
                      <tr>
                        <td>
                          <div class="title"><?php echo e($r['dar_no'] ?? ''); ?></div>
                          <div class="sub">
                            <?php echo $badge; ?>
                            <span class="ms-2"><i class="bi bi-calendar3"></i> <?php echo safeDate($darDate); ?></span>
                          </div>
                        </td>

                        <td>
                          <div class="chip"><i class="bi bi-diagram-3"></i> Division: <?php echo e($division !== '' ? $division : '—'); ?></div>
                          <div class="mt-1 chip"><i class="bi bi-person-check"></i> Incharge: <?php echo e($incharge !== '' ? $incharge : '—'); ?></div>
                        </td>

                        <td>
                          <div class="chip"><i class="bi bi-list-check"></i> Activities: <?php echo (int)$actCount; ?></div>
                          <?php if (!empty($r['report_distribute_to'])): ?>
                            <div class="mt-1 sub"><i class="bi bi-send"></i> <?php echo e($r['report_distribute_to']); ?></div>
                          <?php endif; ?>
                        </td>

                        <td>
                          <div class="title"><?php echo e($r['prepared_by'] ?? ($empName !== '' ? $empName : '—')); ?></div>
                          <div class="sub">
                            <?php if ($empName !== ''): ?>
                              <i class="bi bi-person-badge"></i> <?php echo e($empName); ?>
                              <?php if ($empDesg !== ''): ?> • <?php echo e($empDesg); ?><?php endif; ?>
                            <?php else: ?>
                              <i class="bi bi-person"></i> —
                            <?php endif; ?>
                          </div>
                        </td>

                        <td class="text-end actions-col">
                          <a href="view-dar.php?id=<?php echo (int)$r['id']; ?>" class="btn-action" title="View DAR">
                            <i class="bi bi-eye"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </tbody>
              </table>

            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<?php if (!$showProjects): ?>
<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="exportModalLabel">Export Reports</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form method="POST" action="export-reports.php">
        <div class="modal-body">
          <input type="hidden" name="type" value="<?php echo e($type); ?>" />
          <input type="hidden" name="site_id" value="<?php echo (int)$siteId; ?>" />
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
<?php endif; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<!-- TEK-C Custom JS -->
<script src="assets/js/sidebar-toggle.js"></script>

<script>
(function () {
  $(function () {
    $('#mainTable').DataTable({
      responsive: true,
      autoWidth: false,
      scrollX: false,
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      order: [[0, 'desc']],
      columnDefs: [
        { targets: [<?php echo $showProjects ? 5 : 4; ?>], orderable: false, searchable: false }
      ],
      language: {
        zeroRecords: "No matching records found",
        info: "Showing _START_ to _END_ of _TOTAL_ records",
        infoEmpty: "No records to show",
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
if (isset($conn)) { mysqli_close($conn); }
?>
