<?php
/**
 * manage-sites.php (TEK-C style like manage-employees.php) — UPDATED + COMPLETE
 * ✅ Added MOBILE cards view (like manage-employees.php) + Desktop DataTable
 * ✅ PRG (Post/Redirect/Get) + Flash messages
 * ✅ Soft delete / restore / permanent delete
 * ✅ Activity logging for all actions
 * ✅ Avoid fatal error if sites.team_lead_employee_id doesn't exist
 * ✅ Team Lead fallback from engineers with designation = 'Team Lead'
 */

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
  <title>Manage Sites - TEK-C</title>

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

    .btn-add{ background: var(--blue); color:#fff; border:none; padding:10px 16px; border-radius:12px; font-weight:800; font-size:13px; display:inline-flex; align-items:center; gap:8px; text-decoration:none; white-space:nowrap; box-shadow:0 8px 18px rgba(45,156,219,.18); }
    .btn-add:hover{ background:#2a8bc9; color:#fff; }
    .btn-trash{ background:#ef4444; color:#fff; border:none; padding:10px 16px; border-radius:12px; font-weight:800; font-size:13px; display:inline-flex; align-items:center; gap:8px; text-decoration:none; white-space:nowrap; box-shadow:0 8px 18px rgba(239,68,68,.18); }
    .btn-trash:hover{ background:#dc2626; color:#fff; }
    .btn-export{ background:#10b981; color:#fff; border:none; padding:10px 16px; border-radius:12px; font-weight:800; font-size:13px; display:inline-flex; align-items:center; gap:8px; white-space:nowrap; box-shadow:0 8px 18px rgba(16,185,129,.18); }
    .btn-export:hover{ background:#0da271; color:#fff; }

    .status-badge{ padding:3px 8px; border-radius:20px; font-size:10px; font-weight:900; letter-spacing:.3px; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; text-transform:uppercase; }
    .status-green{ background: rgba(16,185,129,.12); color:#10b981; border:1px solid rgba(16,185,129,.22); }
    .status-yellow{ background: rgba(245,158,11,.12); color:#f59e0b; border:1px solid rgba(245,158,11,.22); }
    .status-red{ background: rgba(239,68,68,.12); color:#ef4444; border:1px solid rgba(239,68,68,.22); }
    .status-gray{ background: rgba(107,114,128,.12); color:#6b7280; border:1px solid rgba(107,114,128,.22); }

    /* ✅ MOBILE CARDS (updated) */
    .site-card{ border:1px solid var(--border); border-radius:16px; background:var(--surface); box-shadow:var(--shadow); padding:12px; }
    .site-top{ display:flex; gap:10px; align-items:flex-start; justify-content:space-between; }
    .site-main{ flex:1 1 auto; }
    .site-title{ font-weight:1000; font-size:14px; color:#111827; line-height:1.25; }
    .site-sub{ font-size:12px; color:#6b7280; font-weight:800; margin-top:2px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .site-kv{ margin-top:10px; display:grid; gap:8px; }
    .site-row{ display:flex; gap:10px; }
    .site-key{ flex:0 0 92px; color:#6b7280; font-weight:1000; font-size:12px; }
    .site-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; word-break:break-word; }
    .site-actions{ margin-top:10px; display:flex; gap:8px; }
    .site-actions a, .site-actions button{ flex:1 1 auto; border-radius:12px; justify-content:center; font-weight:900; }
    .pill{ display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; border:1px solid #e5e7eb; background:#f9fafb; font-weight:900; font-size:12px; }
    .pill .muted{ color:#6b7280; font-weight:900; }

    @media (max-width: 991.98px){ .content-scroll{ padding:18px; } }
    @media (max-width: 768px){
      .content-scroll{ padding:12px 10px 12px!important; }
      .container-fluid.maxw{ padding-left:6px!important; padding-right:6px!important; }
      .panel{ padding:12px!important; border-radius:14px; }
    }

    /* Desktop table */
    .table-responsive{ overflow-x:hidden!important; }
    table.dataTable{ width:100%!important; }
    .table thead th{ font-size:11px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; padding:10px 10px!important; white-space:normal!important; }
    .table td{ vertical-align:top; border-color:var(--border); font-weight:650; color:#374151; padding:10px 10px!important; white-space:normal!important; word-break:break-word; }

    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border:1px solid var(--border); border-radius:10px; padding:7px 10px; font-weight:650; outline:none;
    }
    div.dataTables_wrapper .dataTables_filter input:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45,156,219,.1);
    }
    th.actions-col, td.actions-col{ width:160px!important; white-space:nowrap!important; }
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
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1"><?php echo $show_trash ? 'Trash - Sites' : 'Manage Sites'; ?></h1>
            <p class="text-muted mb-0">View and manage all site/project records</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="add-site.php" class="btn-add">
              <i class="bi bi-plus-circle"></i> Add Site
            </a>

            <?php if ($show_trash): ?>
              <a href="manage-sites.php" class="btn-add" style="background:#6b7280;">
                <i class="bi bi-archive"></i> Active Sites
              </a>
            <?php else: ?>
              <a href="manage-sites.php?show_trash=1" class="btn-trash">
                <i class="bi bi-trash"></i> Trash (<?php echo (int)$trashCount; ?>)
              </a>
            <?php endif; ?>

            <button class="btn-export" data-bs-toggle="modal" data-bs-target="#exportModal">
              <i class="bi bi-download"></i> Export
            </button>
          </div>
        </div>

        <!-- Alerts -->
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

        <!-- Stats (Active view only) -->
        <?php if (!$show_trash): ?>
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-geo-alt-fill"></i></div>
                <div>
                  <div class="stat-label">Total Sites</div>
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
        <?php else: ?>
          <div class="alert alert-warning mb-3" role="alert" style="box-shadow:none;">
            <i class="bi bi-exclamation-triangle me-2"></i>
            You are viewing deleted sites. Use restore or permanent delete.
          </div>
        <?php endif; ?>

        <!-- ✅ MOBILE VIEW: Site Cards -->
        <div class="d-block d-md-none mb-4">
          <?php if (empty($sites)): ?>
            <div class="panel text-muted" style="font-weight:900;">No sites found.</div>
          <?php else: ?>
            <div class="d-grid gap-3">
              <?php foreach ($sites as $s): ?>
                <?php
                  $is_deleted = !empty($s['deleted_at']);
                  [$stLabel, $stClass, $stIcon] = projectStatusBadge(
                    $s['start_date'] ?? '',
                    $s['expected_completion_date'] ?? '',
                    $s['deleted_at'] ?? null
                  );

                  $clientName = trim((string)($s['client_name'] ?? ''));
                  $company    = trim((string)($s['company_name'] ?? ''));
                  $clientLine = $company !== '' ? ($clientName . ' • ' . $company) : $clientName;

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

                  $mgrTxt = $managerName !== '' ? ($managerName . ($managerDesg ? " • $managerDesg" : '')) : 'Not assigned';
                  $tlTxt  = 'Not assigned';
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
                <div class="site-card">
                  <div class="site-top">
                    <div class="site-main">
                      <div class="site-title"><?php echo e($s['project_name'] ?? ''); ?></div>
                      <div class="site-sub">
                        <span><i class="bi bi-pin-map"></i> <?php echo e($s['project_location'] ?? ''); ?></span>
                        <span>•</span>
                        <span><i class="bi bi-kanban"></i> <?php echo e($s['project_type'] ?? ''); ?></span>
                      </div>
                      <div class="site-sub mt-1">
                        <span><i class="bi bi-file-earmark-text"></i> Agreement: <?php echo e($s['agreement_number'] ?? '—'); ?></span>
                      </div>
                    </div>

                    <span class="status-badge <?php echo e($stClass); ?>">
                      <i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?>
                    </span>
                  </div>

                  <div class="site-kv">
                    <div class="site-row">
                      <div class="site-key">Client</div>
                      <div class="site-val"><?php echo e($clientLine); ?></div>
                    </div>

                    <div class="site-row">
                      <div class="site-key">Value</div>
                      <div class="site-val">
                        ₹ <?php echo e(showMoney($s['contract_value'] ?? '')); ?>
                        <span class="muted" style="font-weight:900;color:#6b7280;"> (PMC: ₹ <?php echo e(showMoney($s['pmc_charges'] ?? '')); ?>)</span>
                      </div>
                    </div>

                    <div class="site-row">
                      <div class="site-key">Dates</div>
                      <div class="site-val">
                        <span class="pill"><i class="bi bi-calendar-event"></i> <span class="muted">Start:</span> <?php echo e(safeDate($s['start_date'] ?? '')); ?></span>
                        <span class="pill"><i class="bi bi-calendar-check"></i> <span class="muted">End:</span> <?php echo e(safeDate($s['expected_completion_date'] ?? '')); ?></span>
                      </div>
                    </div>

                    <div class="site-row">
                      <div class="site-key">Manager</div>
                      <div class="site-val"><?php echo e($mgrTxt); ?></div>
                    </div>

                    <div class="site-row">
                      <div class="site-key">Team Lead</div>
                      <div class="site-val"><?php echo e($tlTxt); ?></div>
                    </div>

                    <div class="site-row">
                      <div class="site-key">Engineers</div>
                      <div class="site-val"><?php echo e($engTxt); ?></div>
                    </div>

                    <?php if ($show_trash): ?>
                      <div class="site-row">
                        <div class="site-key">Deleted</div>
                        <div class="site-val">
                          <?php echo e($s['deleted_by_name'] ?? 'Unknown'); ?> • <?php echo e(safeDate($s['deleted_at'] ?? '')); ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="site-actions">
                    <?php if ($is_deleted): ?>
                      <form method="POST" style="margin:0;flex:1 1 auto;" onsubmit="return confirm('Restore this site?');">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                          <i class="bi bi-arrow-counterclockwise"></i> Restore
                        </button>
                      </form>

                      <form method="POST" style="margin:0;flex:1 1 auto;" onsubmit="return confirm('Permanently delete this site? This cannot be undone.');">
                        <input type="hidden" name="action" value="permanent_delete">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                          <i class="bi bi-trash"></i> Delete
                        </button>
                      </form>
                    <?php else: ?>
                      <a href="view-site.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-eye"></i> View
                      </a>
                      <a href="view-client.php?id=<?php echo (int)$s['client_id']; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-person"></i> Client
                      </a>
                      <?php if (!empty($s['contract_document'])): ?>
                        <a href="<?php echo e($s['contract_document']); ?>" class="btn btn-outline-success btn-sm" target="_blank" rel="noopener">
                          <i class="bi bi-file-earmark-arrow-down"></i> Contract
                        </a>
                      <?php endif; ?>
                      <form method="POST" style="margin:0;flex:1 1 auto;" onsubmit="return confirm('Move this site to trash?');">
                        <input type="hidden" name="action" value="soft_delete">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                          <i class="bi bi-trash"></i> Trash
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- ✅ DESKTOP VIEW: Table -->
        <div class="panel mb-4 d-none d-md-block">
          <div class="panel-header">
            <h3 class="panel-title"><?php echo $show_trash ? 'Deleted Sites' : 'Sites Directory'; ?></h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table id="sitesTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Site / Project</th>
                  <th>Client</th>
                  <th>Location / Type</th>
                  <th>Value</th>
                  <th>Start / End</th>
                  <th>Status</th>
                  <th>Team</th>
                  <?php if ($show_trash): ?><th>Deleted By / Date</th><?php endif; ?>
                  <th class="text-end actions-col">Actions</th>
                </tr>
              </thead>

              <tbody>
              <?php foreach ($sites as $s): ?>
                <?php
                  $is_deleted = !empty($s['deleted_at']);

                  [$stLabel, $stClass, $stIcon] = projectStatusBadge(
                    $s['start_date'] ?? '',
                    $s['expected_completion_date'] ?? '',
                    $s['deleted_at'] ?? null
                  );

                  $clientName = trim((string)($s['client_name'] ?? ''));
                  $company    = trim((string)($s['company_name'] ?? ''));
                  $clientLine = $company !== '' ? ($clientName . ' • ' . $company) : $clientName;

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
                ?>
                <tr>
                  <td>
                    <div style="font-weight:900;"><?php echo e($s['project_name'] ?? ''); ?></div>
                    <div class="site-sub"><i class="bi bi-file-earmark-text"></i> Agreement: <?php echo e($s['agreement_number'] ?? '—'); ?></div>
                  </td>

                  <td>
                    <div style="font-weight:900;"><?php echo e($clientLine); ?></div>
                    <?php if (!empty($s['client_state'])): ?><div class="contact-info"><i class="bi bi-geo-alt"></i> <?php echo e($s['client_state']); ?></div><?php endif; ?>
                    <?php if (!empty($s['client_mobile'])): ?><div class="contact-info"><i class="bi bi-telephone"></i> <?php echo e($s['client_mobile']); ?></div><?php endif; ?>
                    <?php if (!empty($s['client_email'])): ?><div class="contact-info"><i class="bi bi-envelope"></i> <?php echo e($s['client_email']); ?></div><?php endif; ?>
                  </td>

                  <td>
                    <div style="font-weight:900;"><?php echo e($s['project_type'] ?? ''); ?></div>
                    <div class="site-sub"><i class="bi bi-pin-map"></i> <?php echo e($s['project_location'] ?? ''); ?></div>
                  </td>

                  <td>
                    <div style="font-weight:900;">₹ <?php echo e(showMoney($s['contract_value'] ?? '')); ?></div>
                    <div class="site-sub">PMC: ₹ <?php echo e(showMoney($s['pmc_charges'] ?? '')); ?></div>
                  </td>

                  <td>
                    <div class="site-sub" style="font-weight:900;">Start: <?php echo e(safeDate($s['start_date'] ?? '')); ?></div>
                    <div class="site-sub">End: <?php echo e(safeDate($s['expected_completion_date'] ?? '')); ?></div>
                  </td>

                  <td>
                    <span class="status-badge <?php echo e($stClass); ?>">
                      <i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?>
                    </span>
                  </td>

                  <td>
                    <?php
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
                    <div class="site-sub"><b>Manager:</b> <?php echo e($mgrTxt); ?></div>
                    <div class="site-sub"><b>Team Lead:</b> <?php echo e($tlTxt); ?></div>
                    <div class="site-sub"><b>Engineers:</b> <?php echo e($engTxt); ?></div>
                  </td>

                  <?php if ($show_trash): ?>
                    <td>
                      <div class="site-sub"><i class="bi bi-person"></i> <?php echo e($s['deleted_by_name'] ?? 'Unknown'); ?></div>
                      <div class="site-sub"><i class="bi bi-clock"></i> <?php echo e(safeDate($s['deleted_at'] ?? '')); ?></div>
                    </td>
                  <?php endif; ?>

                  <td class="text-end actions-col">
                    <?php if ($is_deleted): ?>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this site?');">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-outline-primary btn-sm" style="font-weight:900;">
                          <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                      </form>

                      <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this site? This cannot be undone.');">
                        <input type="hidden" name="action" value="permanent_delete">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" style="font-weight:900;">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    <?php else: ?>
                      <a href="view-site.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-outline-primary btn-sm" style="font-weight:900;">
                        <i class="bi bi-eye"></i>
                      </a>
                      <a href="edit-site.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-outline-secondary btn-sm" style="font-weight:900;">
                        <i class="bi bi-pencil"></i>
                      </a>
                      <a href="view-client.php?id=<?php echo (int)$s['client_id']; ?>" class="btn btn-outline-secondary btn-sm" style="font-weight:900;">
                        <i class="bi bi-person"></i>
                      </a>
                      <?php if (!empty($s['contract_document'])): ?>
                        <a href="<?php echo e($s['contract_document']); ?>" class="btn btn-outline-success btn-sm" target="_blank" rel="noopener" style="font-weight:900;">
                          <i class="bi bi-file-earmark-arrow-down"></i>
                        </a>
                      <?php endif; ?>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Move this site to trash?');">
                        <input type="hidden" name="action" value="soft_delete">
                        <input type="hidden" name="site_id" value="<?php echo (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" style="font-weight:900;">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="text-end mb-3">
          <a href="activity-logs.php?module=sites" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clock-history"></i> View Site Activity Logs
          </a>
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
        <h5 class="modal-title fw-bold" id="exportModalLabel">Export Sites</h5>
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

<!-- JS -->
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
    const isTrash = <?php echo $show_trash ? 'true' : 'false'; ?>;
    const actionsIndex = isTrash ? 8 : 7;

    $('#sitesTable').DataTable({
      responsive: true,
      autoWidth: false,
      scrollX: false,
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      order: [[0, 'asc']],
      columnDefs: [
        { targets: [actionsIndex], orderable: false, searchable: false }
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
      $('.dataTables_filter input').trigger('focus');
    }, 400);
  });
})();
</script>

</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
?>