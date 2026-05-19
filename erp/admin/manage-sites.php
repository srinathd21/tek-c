<?php

session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

// OPTIONAL auth
// if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

// Set current user in session for logging (replace with your login system)
if (!isset($_SESSION['user_id'])) {
  $_SESSION['user_id']   = 1;
  $_SESSION['user_name'] = 'Admin User';
  $_SESSION['user_role'] = 'Administrator';
}

$sites = [];
$success = '';
$error = '';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// -------------------- Helpers --------------------
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

function projectStatusBadge($start, $end, $deleted_at = null){
  if (!empty($deleted_at)) return ['Deleted', 'status-gray', 'bi-trash'];

  $today = date('Y-m-d');
  $start = trim((string)$start);
  $end   = trim((string)$end);

  if ($end !== '' && $end !== '0000-00-00' && $end < $today) return ['Completed', 'status-red', 'bi-check2-circle'];
  if ($start !== '' && $start !== '0000-00-00' && $start > $today) return ['Upcoming', 'status-yellow', 'bi-clock'];
  return ['Ongoing', 'status-green', 'bi-lightning'];
}

// Parses "name|designation||name|designation"
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

// -------------------- Inputs --------------------
$show_trash = (isset($_GET['show_trash']) && $_GET['show_trash'] === '1');

// -------------------- Detect optional team_lead_employee_id column --------------------
$hasTeamLeadCol = false;
$chk = mysqli_query($conn, "SHOW COLUMNS FROM sites LIKE 'team_lead_employee_id'");
if ($chk) {
  $hasTeamLeadCol = (mysqli_num_rows($chk) > 0);
  mysqli_free_result($chk);
}

// -------------------- POST actions (PRG + Flash) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action  = (string)$_POST['action'];
  $site_id = isset($_POST['site_id']) ? (int)$_POST['site_id'] : 0;
  $current_user = (int)($_SESSION['user_id'] ?? 1);

  if ($site_id <= 0) {
    $_SESSION['flash_error'] = "Invalid site selected.";
    header("Location: manage-sites.php" . ($show_trash ? "?show_trash=1" : ""));
    exit;
  }

  // Fetch site details for logging
  $site_data = null;
  $site_name = 'Unknown';
  $q = mysqli_prepare($conn, "SELECT id, project_name, project_code, contract_document, deleted_at FROM sites WHERE id=? LIMIT 1");
  if ($q) {
    mysqli_stmt_bind_param($q, "i", $site_id);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    $site_data = $r ? mysqli_fetch_assoc($r) : null;
    mysqli_stmt_close($q);
  }
  if ($site_data && isset($site_data['project_name'])) $site_name = (string)$site_data['project_name'];

  // Soft delete
  if ($action === 'soft_delete') {
    $stmt = mysqli_prepare($conn, "UPDATE sites SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    if (!$stmt) {
      $_SESSION['flash_error'] = "Database error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param($stmt, "ii", $current_user, $site_id);
      if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
        $_SESSION['flash_success'] = "Site moved to trash successfully!";
        logActivity($conn, 'SOFT_DELETE', 'sites', "Soft deleted site: $site_name", $site_id, $site_name, json_encode($site_data), null);
      } else {
        $_SESSION['flash_error'] = "Unable to delete. It may already be deleted.";
      }
      mysqli_stmt_close($stmt);
    }
    header("Location: manage-sites.php");
    exit;
  }

  // Restore
  if ($action === 'restore') {
    $stmt = mysqli_prepare($conn, "UPDATE sites SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1");
    if (!$stmt) {
      $_SESSION['flash_error'] = "Database error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param($stmt, "i", $site_id);
      if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
        $_SESSION['flash_success'] = "Site restored successfully!";
        logActivity($conn, 'RESTORE', 'sites', "Restored site: $site_name", $site_id, $site_name, null, json_encode(['restored_at' => date('Y-m-d H:i:s')]));
      } else {
        $_SESSION['flash_error'] = "Unable to restore. It may already be active.";
      }
      mysqli_stmt_close($stmt);
    }
    header("Location: manage-sites.php?show_trash=1");
    exit;
  }

  // Permanent delete
  if ($action === 'permanent_delete') {
    if (empty($site_data['deleted_at'])) {
      $_SESSION['flash_error'] = "Please move the site to trash before permanent delete.";
      header("Location: manage-sites.php");
      exit;
    }

    if (!empty($site_data['contract_document']) && is_string($site_data['contract_document']) && file_exists($site_data['contract_document'])) {
      @unlink($site_data['contract_document']);
    }

    mysqli_query($conn, "DELETE FROM site_project_engineers WHERE site_id = " . (int)$site_id);

    $stmt = mysqli_prepare($conn, "DELETE FROM sites WHERE id = ? LIMIT 1");
    if (!$stmt) {
      $_SESSION['flash_error'] = "Database error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param($stmt, "i", $site_id);
      if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {
        $_SESSION['flash_success'] = "Site permanently deleted!";
        logActivity($conn, 'DELETE', 'sites', "Permanently deleted site: $site_name", $site_id, $site_name, json_encode($site_data), null);
      } else {
        $_SESSION['flash_error'] = "Unable to permanently delete site.";
      }
      mysqli_stmt_close($stmt);
    }

    header("Location: manage-sites.php?show_trash=1");
    exit;
  }

  $_SESSION['flash_error'] = "Unknown action.";
  header("Location: manage-sites.php" . ($show_trash ? "?show_trash=1" : ""));
  exit;
}

// -------------------- Flash messages --------------------
$success = (string)($_SESSION['flash_success'] ?? '');
$error   = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// -------------------- Fetch sites (Active or Trash) --------------------
$teamLeadSelect = $hasTeamLeadCol ? "s.team_lead_employee_id," : "NULL AS team_lead_employee_id,";
$teamLeadJoin   = $hasTeamLeadCol ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id" : "LEFT JOIN employees tl ON 1=0";

$deleted_filter = $show_trash ? "WHERE s.deleted_at IS NOT NULL" : "WHERE s.deleted_at IS NULL";

$sql = "
  SELECT
    s.*,
    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.client_type,
    c.state AS client_state,

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
    ) AS engineers_concat,

    dby.full_name AS deleted_by_name

  FROM sites s
  INNER JOIN clients c ON c.id = s.client_id
  LEFT JOIN employees m ON m.id = s.manager_employee_id
  $teamLeadJoin
  LEFT JOIN site_project_engineers spe ON spe.site_id = s.id
  LEFT JOIN employees pe ON pe.id = spe.employee_id
  LEFT JOIN employees dby ON dby.id = s.deleted_by
  $deleted_filter
  GROUP BY s.id
  ORDER BY s.created_at DESC
";

$res = mysqli_query($conn, $sql);
if ($res) {
  $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_free_result($res);
} else {
  $error = "Error fetching sites: " . mysqli_error($conn);
}

// -------------------- Stats (Active view only) --------------------
$total_sites = 0;
$ongoing = 0; $upcoming = 0; $completed = 0;
$today = date('Y-m-d');

if (!$show_trash) {
  foreach ($sites as $s) {
    $total_sites++;
    $start = $s['start_date'] ?? '';
    $end   = $s['expected_completion_date'] ?? '';
    if (!empty($end) && $end !== '0000-00-00' && $end < $today) $completed++;
    elseif (!empty($start) && $start !== '0000-00-00' && $start > $today) $upcoming++;
    else $ongoing++;
  }
}

// Trash badge count (always show real deleted count)
$trashCount = 0;
$trashRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM sites WHERE deleted_at IS NOT NULL");
if ($trashRes) {
  $row = mysqli_fetch_assoc($trashRes);
  $trashCount = (int)($row['c'] ?? 0);
  mysqli_free_result($trashRes);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo $show_trash ? 'Trash - Sites' : 'Manage Sites'; ?> - TEK-C</title>

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
    }

    body{ background:var(--page-bg); }
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:16px; }
    .projects-wrapper{ width:100%; }

    .page-heading{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .page-heading h1{ font-size:19px; font-weight:900; color:var(--text); margin:0; }
    .page-heading p{ margin:3px 0 0; color:var(--muted); font-size:12px; font-weight:600; }

    .primary-btn{ border:0; background:#111827; color:#fff; height:36px; padding:0 14px; border-radius:11px; font-size:12px; font-weight:900; display:inline-flex; align-items:center; gap:7px; text-decoration:none; white-space:nowrap; }
    .primary-btn:hover{ background:#020617; color:#fff; }
    .trash-btn{ background:#ef4444; }
    .trash-btn:hover{ background:#dc2626; }
    .restore-view-btn{ background:#6b7280; }
    .restore-view-btn:hover{ background:#4b5563; }
    .export-btn{ background:#10b981; }
    .export-btn:hover{ background:#059669; }

    .alert{ border:0; border-radius:var(--radius); box-shadow:var(--shadow); font-size:13px; font-weight:700; }

    .stat-card{ background:var(--card-bg); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:12px 13px; min-height:78px; display:flex; align-items:center; gap:11px; }
    .stat-ic{ width:38px; height:38px; border-radius:12px; display:grid; place-items:center; color:#fff; font-size:17px; }
    .blue{ background:#2f80ed; }
    .orange{ background:#f2994a; }
    .green{ background:#27ae60; }
    .red{ background:#eb5757; }
    .gray{ background:#6b7280; }
    .stat-label{ color:var(--muted); font-weight:800; font-size:10.5px; text-transform:uppercase; }
    .stat-value{ font-size:24px; font-weight:950; line-height:1.05; }

    .panel{ background:var(--card-bg); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:13px; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:12px; }
    .panel-title{ font-weight:900; font-size:14px; margin:0; color:var(--text); }
    .panel-subtitle{ color:var(--muted); font-size:11px; font-weight:700; margin-top:2px; }

    .filter-bar{ display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
    .search-box{ position:relative; flex:1 1 260px; max-width:430px; }
    .search-box i{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; }
    .search-box input{ width:100%; height:36px; border:1px solid var(--border); border-radius:11px; background:#fff; padding:0 12px 0 34px; font-size:12px; font-weight:700; color:var(--text); outline:none; }
    .search-box input:focus{ border-color:#bfdbfe; box-shadow:0 0 0 3px rgba(59,130,246,.10); }
    .filter-select{ height:36px; border:1px solid var(--border); border-radius:11px; background:#fff; padding:0 42px 0 12px; font-size:12px; font-weight:800; min-width:145px; }

    .compact-table-wrap{ width:100%; border:1px solid var(--border); border-radius:13px; overflow:hidden; background:#fff; }
    .compact-table{ width:100%; margin:0; table-layout:auto; }
    .compact-table thead th{ background:var(--soft); color:#64748b; font-size:10px; text-transform:uppercase; font-weight:900; border-bottom:1px solid var(--border)!important; padding:8px 9px; white-space:nowrap; }
    .compact-table tbody td{ padding:8px 9px; vertical-align:middle; border-color:#eef2f7; color:#334155; font-weight:700; font-size:11.5px; }
    .compact-table tbody tr:hover{ background:#fbfdff; }

    .table-title-cell{ display:flex; align-items:center; gap:8px; min-width:220px; }
    .table-icon{ width:26px; height:26px; border-radius:8px; display:grid; place-items:center; background:#eff6ff; color:#2563eb; font-size:13px; flex:0 0 auto; }
    .table-primary-text{ color:#111827; font-size:11.5px; font-weight:900; }
    .table-secondary-text{ color:#64748b; font-size:10px; font-weight:700; margin-top:1px; line-height:1.4; }

    .badge-pill{ border-radius:999px; padding:5px 8px; font-weight:900; font-size:10px; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
    .mini-dot{ width:6px; height:6px; border-radius:50%; background:currentColor; }
    .ontrack{ color:#15803d; background:#dcfce7; }
    .progressing{ color:#2563eb; background:#dbeafe; }
    .pending{ color:#6d28d9; background:#ede9fe; }
    .deleted{ color:#6b7280; background:#f3f4f6; }
    .completed{ color:#b91c1c; background:#fee2e2; }

    .team-text{ font-size:10px; color:#64748b; line-height:1.5; min-width:170px; }
    .action-group{ display:flex; justify-content:flex-end; gap:5px; flex-wrap:nowrap; }
    .action-btn{ width:27px; height:27px; border-radius:9px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; text-decoration:none; padding:0; }
    .view-btn{ color:#475569; background:#f8fafc; }
    .edit-btn{ color:#2563eb; background:#eff6ff; }
    .client-btn{ color:#7c3aed; background:#f5f3ff; }
    .file-btn{ color:#059669; background:#ecfdf5; }
    .delete-btn{ color:#dc2626; background:#fef2f2; }
    .restore-btn{ color:#2563eb; background:#eff6ff; }

    .pagination-wrap{ display:flex; align-items:center; justify-content:space-between; padding-top:12px; gap:10px; flex-wrap:wrap; }
    .pagination-info{ color:var(--muted); font-size:11px; font-weight:700; }
    .logs-link{ color:#475569; font-size:12px; font-weight:900; text-decoration:none; }
    .logs-link:hover{ color:#111827; }

    .empty-state{ border:1px dashed var(--border); background:#fbfdff; border-radius:13px; padding:24px; text-align:center; color:#64748b; font-weight:800; }

    @media(max-width:1199px){
      .compact-table thead{ display:none; }
      .compact-table, .compact-table tbody, .compact-table tr, .compact-table td{ display:block; width:100%; }
      .compact-table tbody tr{ border-bottom:1px solid var(--border); padding:10px; }
      .compact-table tbody td{ border:0; display:flex; justify-content:space-between; gap:12px; padding:7px 0!important; }
      .compact-table tbody td::before{ content:attr(data-label); font-size:10px; font-weight:900; color:#64748b; text-transform:uppercase; flex:0 0 95px; }
      .compact-table tbody td:first-child{ display:block; }
      .compact-table tbody td:first-child::before{ display:none; }
      .action-group{ justify-content:flex-start; }
      .table-title-cell{ min-width:0; }
    }

    @media(max-width:768px){
      .content-scroll{ padding:12px 10px 12px!important; }
      .container-fluid.projects-wrapper{ padding-left:6px!important; padding-right:6px!important; }
      .page-heading{ align-items:flex-start; flex-direction:column; }
      .panel{ padding:12px!important; border-radius:14px; }
      .stat-card{ min-height:70px; }
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
            <h1><?php echo $show_trash ? 'Trash - Sites' : 'Manage Sites'; ?></h1>
            <p><?php echo $show_trash ? 'Restore or permanently delete removed site records' : 'Manage all ongoing, completed and upcoming site/project records'; ?></p>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <a href="add-site.php" class="primary-btn"><i class="bi bi-plus-circle"></i> Add Site</a>

            <?php if ($show_trash): ?>
              <a href="manage-sites.php" class="primary-btn restore-view-btn"><i class="bi bi-archive"></i> Active Sites</a>
            <?php else: ?>
              <a href="manage-sites.php?show_trash=1" class="primary-btn trash-btn"><i class="bi bi-trash"></i> Trash (<?php echo (int)$trashCount; ?>)</a>
            <?php endif; ?>

            <button class="primary-btn export-btn" data-bs-toggle="modal" data-bs-target="#exportModal"><i class="bi bi-download"></i> Export</button>
          </div>
        </div>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!$show_trash): ?>
          <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-ic blue"><i class="bi bi-folder2-open"></i></div><div><div class="stat-label">Total Sites</div><div class="stat-value"><?php echo (int)$total_sites; ?></div></div></div></div>
            <div class="col-12 col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-ic green"><i class="bi bi-lightning-fill"></i></div><div><div class="stat-label">Ongoing</div><div class="stat-value"><?php echo (int)$ongoing; ?></div></div></div></div>
            <div class="col-12 col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-ic orange"><i class="bi bi-clock-fill"></i></div><div><div class="stat-label">Upcoming</div><div class="stat-value"><?php echo (int)$upcoming; ?></div></div></div></div>
            <div class="col-12 col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-ic red"><i class="bi bi-check-circle-fill"></i></div><div><div class="stat-label">Completed</div><div class="stat-value"><?php echo (int)$completed; ?></div></div></div></div>
          </div>
        <?php else: ?>
          <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-ic gray"><i class="bi bi-trash3-fill"></i></div><div><div class="stat-label">Deleted Sites</div><div class="stat-value"><?php echo count($sites); ?></div></div></div></div>
            <div class="col-12 col-xl-9"><div class="alert alert-warning mb-0" style="box-shadow:none;"><i class="bi bi-exclamation-triangle me-2"></i>You are viewing deleted sites. Use restore or permanent delete.</div></div>
          </div>
        <?php endif; ?>

        <div class="panel mb-4">
          <div class="panel-header">
            <div>
              <h3 class="panel-title"><?php echo $show_trash ? 'Deleted Sites' : 'Sites Directory'; ?></h3>
              <div class="panel-subtitle"><?php echo $show_trash ? 'Trash records with restore and delete actions' : 'Compact responsive site/project directory'; ?></div>
            </div>
          </div>

          <div class="filter-bar">
            <div class="search-box">
              <i class="bi bi-search"></i>
              <input type="text" id="siteSearch" placeholder="Search project, client, manager, engineer or location...">
            </div>

            <div class="d-flex gap-2 flex-wrap">
              <select class="filter-select" id="statusFilter">
                <option value="">All Status</option>
                <?php if ($show_trash): ?>
                  <option value="deleted">Deleted</option>
                <?php else: ?>
                  <option value="ongoing">Ongoing</option>
                  <option value="completed">Completed</option>
                  <option value="upcoming">Upcoming</option>
                <?php endif; ?>
              </select>

              <select class="filter-select" id="typeFilter">
                <option value="">All Types</option>
                <option value="residential">Residential</option>
                <option value="commercial">Commercial</option>
                <option value="industrial">Industrial</option>
                <option value="infrastructure">Infrastructure</option>
              </select>
            </div>
          </div>

          <?php if (empty($sites)): ?>
            <div class="empty-state">
              <i class="bi bi-inbox fs-4 d-block mb-2"></i>
              No sites found.
            </div>
          <?php else: ?>
            <div class="compact-table-wrap">
              <table class="table compact-table align-middle" id="sitesTable">
                <thead>
                  <tr>
                    <th>Site / Project</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Timeline</th>
                    <th>Value</th>
                    <th>Team</th>
                    <?php if ($show_trash): ?><th>Deleted</th><?php endif; ?>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($sites as $s): ?>
                  <?php
                    $is_deleted = !empty($s['deleted_at']);
                    [$stLabel, $oldStClass, $stIcon] = projectStatusBadge($s['start_date'] ?? '', $s['expected_completion_date'] ?? '', $s['deleted_at'] ?? null);
                    $statusKey = strtolower($stLabel);
                    $badgeClass = $statusKey === 'ongoing' ? 'progressing' : ($statusKey === 'upcoming' ? 'pending' : ($statusKey === 'completed' ? 'completed' : 'deleted'));

                    $clientName = trim((string)($s['client_name'] ?? ''));
                    $company = trim((string)($s['company_name'] ?? ''));
                    $clientLine = $company !== '' ? ($clientName . ' — ' . $company) : $clientName;

                    $managerName = trim((string)($s['manager_name'] ?? ''));
                    $managerDesg = trim((string)($s['manager_designation'] ?? ''));
                    $teamLeadName = trim((string)($s['team_lead_name'] ?? ''));
                    $teamLeadDesg = trim((string)($s['team_lead_designation'] ?? ''));

                    $engineers = parseMembersConcat($s['engineers_concat'] ?? '');
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

                    $mgrTxt = $managerName !== '' ? ($managerName . ($managerDesg ? " • $managerDesg" : '')) : 'Not Assigned';
                    $tlTxt = 'Not Assigned';
                    if ($teamLeadName !== '') $tlTxt = $teamLeadName . ($teamLeadDesg ? " • $teamLeadDesg" : '');
                    elseif (!empty($fallbackTeamLeads)) {
                      $tmp = [];
                      foreach ($fallbackTeamLeads as $tl) $tmp[] = $tl['name'] . (!empty($tl['designation']) ? " • {$tl['designation']}" : '');
                      $tlTxt = implode(', ', $tmp);
                    }

                    $engTxt = 'None';
                    if (!empty($engineerOnly)) {
                      $tmp = [];
                      $max = 2;
                      for ($i=0; $i<min($max, count($engineerOnly)); $i++) {
                        $tmp[] = $engineerOnly[$i]['name'];
                      }
                      $more = count($engineerOnly) - $max;
                      if ($more > 0) $tmp[] = "+$more more";
                      $engTxt = implode(', ', $tmp);
                    }
                  ?>
                  <tr data-status="<?php echo e($statusKey); ?>" data-type="<?php echo e(strtolower((string)($s['project_type'] ?? ''))); ?>">
                    <td data-label="Project">
                      <div class="table-title-cell">
                        <div class="table-icon"><i class="bi bi-building"></i></div>
                        <div>
                          <div class="table-primary-text"><?php echo e($s['project_name'] ?? ''); ?></div>
                          <div class="table-secondary-text">
                            <?php echo e($s['agreement_number'] ?? '—'); ?> • <?php echo e($s['project_type'] ?? ''); ?> • <?php echo e($s['project_location'] ?? ''); ?>
                          </div>
                        </div>
                      </div>
                    </td>

                    <td data-label="Client">
                      <div class="table-primary-text"><?php echo e($clientName); ?></div>
                      <div class="table-secondary-text"><?php echo e($company !== '' ? $company : ($s['client_state'] ?? '')); ?></div>
                      <?php if (!empty($s['client_mobile'])): ?><div class="table-secondary-text"><i class="bi bi-telephone"></i> <?php echo e($s['client_mobile']); ?></div><?php endif; ?>
                    </td>

                    <td data-label="Status">
                      <span class="badge-pill <?php echo e($badgeClass); ?>"><span class="mini-dot"></span><?php echo e($stLabel); ?></span>
                    </td>

                    <td data-label="Timeline">
                      <div class="table-primary-text"><?php echo e(safeDate($s['start_date'] ?? '')); ?></div>
                      <div class="table-secondary-text">to <?php echo e(safeDate($s['expected_completion_date'] ?? '')); ?></div>
                    </td>

                    <td data-label="Value">
                      <div class="table-primary-text">₹ <?php echo e(showMoney($s['contract_value'] ?? '')); ?></div>
                      <div class="table-secondary-text">PMC: ₹ <?php echo e(showMoney($s['pmc_charges'] ?? '')); ?></div>
                    </td>

                    <td data-label="Team">
                      <div class="team-text">
                        <div><b>Manager:</b> <?php echo e($mgrTxt); ?></div>
                        <div><b>Team Lead:</b> <?php echo e($tlTxt); ?></div>
                        <div><b>Engineers:</b> <?php echo e($engTxt); ?></div>
                      </div>
                    </td>

                    <?php if ($show_trash): ?>
                      <td data-label="Deleted">
                        <div class="table-primary-text"><?php echo e($s['deleted_by_name'] ?? 'Unknown'); ?></div>
                        <div class="table-secondary-text"><?php echo e(safeDate($s['deleted_at'] ?? '')); ?></div>
                      </td>
                    <?php endif; ?>

                    <td data-label="Actions">
                      <div class="action-group">
                        <?php if ($is_deleted): ?>
                          <form method="POST" style="margin:0;" onsubmit="return confirm('Restore this site?');">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                            <button type="submit" class="action-btn restore-btn" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                          </form>

                          <form method="POST" style="margin:0;" onsubmit="return confirm('Permanently delete this site? This cannot be undone.');">
                            <input type="hidden" name="action" value="permanent_delete">
                            <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                            <button type="submit" class="action-btn delete-btn" title="Permanent Delete"><i class="bi bi-trash"></i></button>
                          </form>
                        <?php else: ?>
                          <a href="view-site.php?id=<?php echo (int)$s['id']; ?>" class="action-btn view-btn" title="View"><i class="bi bi-eye"></i></a>
                          <a href="edit-site.php?id=<?php echo (int)$s['id']; ?>" class="action-btn edit-btn" title="Edit"><i class="bi bi-pencil-square"></i></a>
                          <a href="view-client.php?id=<?php echo (int)$s['client_id']; ?>" class="action-btn client-btn" title="Client"><i class="bi bi-person"></i></a>
                          <?php if (!empty($s['contract_document'])): ?>
                            <a href="<?php echo e($s['contract_document']); ?>" target="_blank" rel="noopener" class="action-btn file-btn" title="Contract"><i class="bi bi-file-earmark-arrow-down"></i></a>
                          <?php endif; ?>
                          <form method="POST" style="margin:0;" onsubmit="return confirm('Move this site to trash?');">
                            <input type="hidden" name="action" value="soft_delete">
                            <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                            <button type="submit" class="action-btn delete-btn" title="Move to Trash"><i class="bi bi-trash"></i></button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="pagination-wrap">
              <div class="pagination-info" id="recordInfo">Showing <?php echo count($sites); ?> site records</div>
              <a href="activity-logs.php?module=sites" class="logs-link"><i class="bi bi-clock-history"></i> View Site Activity Logs</a>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="exportModalLabel">Export Sites</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="export-sites.php">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Export Format *</label>
              <select class="form-select" name="export_format" required>
                <option value="csv">CSV</option>
                <option value="excel">Excel</option>
                <option value="pdf">PDF</option>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="apply_filters" name="apply_filters" value="1" checked>
                <label class="form-check-label" for="apply_filters">Apply Current Filters</label>
                <div class="form-text">Include current search/filter criteria in export when export-sites.php supports it.</div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-download me-2"></i> Export</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const searchInput = document.getElementById('siteSearch');
  const statusFilter = document.getElementById('statusFilter');
  const typeFilter = document.getElementById('typeFilter');
  const tableRows = document.querySelectorAll('#sitesTable tbody tr');
  const recordInfo = document.getElementById('recordInfo');

  function filterSites() {
    const searchValue = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const statusValue = (statusFilter ? statusFilter.value : '').toLowerCase().trim();
    const typeValue = (typeFilter ? typeFilter.value : '').toLowerCase().trim();
    let visible = 0;

    tableRows.forEach(function (row) {
      const rowText = row.innerText.toLowerCase();
      const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
      const rowType = (row.getAttribute('data-type') || '').toLowerCase();

      const matchesSearch = !searchValue || rowText.includes(searchValue);
      const matchesStatus = !statusValue || rowStatus === statusValue;
      const matchesType = !typeValue || rowType === typeValue;

      const show = matchesSearch && matchesStatus && matchesType;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    if (recordInfo) {
      recordInfo.textContent = 'Showing ' + visible + ' of ' + tableRows.length + ' site records';
    }
  }

  if (searchInput) searchInput.addEventListener('input', filterSites);
  if (statusFilter) statusFilter.addEventListener('change', filterSites);
  if (typeFilter) typeFilter.addEventListener('change', filterSites);
});
</script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
?>
