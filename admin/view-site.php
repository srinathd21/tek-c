<?php
// view-site.php (TEK-C) + Assign Team (role-filtered) + Google Maps Location
// ✅ Manager (single, only Managers)
// ✅ Team Lead (single, only Team Leads)  [works even if sites.team_lead_employee_id column NOT present]
// ✅ Project Engineers (multiple, only PE Grade 1/2)
// ✅ If team already assigned -> SHOW ONLY (read-only). To change -> click "Edit Team" (same page ?edit_team=1)
// ✅ Google Maps integration to show site location

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$site   = null;
$error  = '';
$success = '';

// Dropdown lists
$managers = [];
$team_leads = [];
$project_engineers = [];

// Assigned team
$assigned_engineers = [];          // PE Grade 1/2 IDs only
$assigned_engineers_rows = [];     // PE rows for display chips
$assigned_team_lead_id = null;     // used only if sites.team_lead_employee_id does NOT exist

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function showVal($v, $dash='—'){
  $v = trim((string)$v);
  return $v === '' ? $dash : e($v);
}

function showDateVal($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function initials($name){
  $name = trim((string)$name);
  if ($name === '') return 'S';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'S', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return ($last && count($parts) > 1) ? ($first.$last) : $first;
}

function fileLinkBtn($path){
  $path = trim((string)$path);
  if ($path === '') return '—';
  $safe = e($path);
  return '<a class="btn btn-sm btn-outline-primary" href="'.$safe.'" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-arrow-down"></i> View / Download
          </a>';
}

// Format location coordinates
function formatCoordinates($lat, $lng) {
  if (!$lat || !$lng) return 'Not set';
  return number_format($lat, 6) . '°, ' . number_format($lng, 6) . '°';
}

// ---------- Detect optional column sites.team_lead_employee_id ----------
$hasTeamLeadCol = false;
$chk = mysqli_query($conn, "SHOW COLUMNS FROM sites LIKE 'team_lead_employee_id'");
if ($chk) {
  $hasTeamLeadCol = (mysqli_num_rows($chk) > 0);
  mysqli_free_result($chk);
}

// ---------- Fetch site ID + edit mode flag ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_team = isset($_GET['edit_team']) && $_GET['edit_team'] === '1';

if ($id <= 0) {
  $error = "Invalid site ID.";
} else {

  // -------------------- SAVE TEAM (only when edit mode or when team not assigned) --------------------
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_team'])) {

    $manager_id = (isset($_POST['manager_employee_id']) && $_POST['manager_employee_id'] !== '')
      ? (int)$_POST['manager_employee_id'] : null;

    $team_lead_id = (isset($_POST['team_lead_employee_id']) && $_POST['team_lead_employee_id'] !== '')
      ? (int)$_POST['team_lead_employee_id'] : null;

    $engineers = $_POST['project_engineers'] ?? [];
    if (!is_array($engineers)) $engineers = [];

    $engineer_ids = [];
    foreach ($engineers as $eid) {
      $eid = (int)$eid;
      if ($eid > 0) $engineer_ids[] = $eid;
    }
    $engineer_ids = array_values(array_unique($engineer_ids));

    // prevent TL also being in engineers list
    if ($team_lead_id !== null) {
      $engineer_ids = array_values(array_filter($engineer_ids, function($x) use ($team_lead_id){ return (int)$x !== (int)$team_lead_id; }));
    }

    // Validate manager = Manager
    if ($manager_id !== null) {
      $chk = mysqli_prepare($conn, "SELECT id FROM employees WHERE id=? AND employee_status='active' AND designation='Manager' LIMIT 1");
      if ($chk) {
        mysqli_stmt_bind_param($chk, "i", $manager_id);
        mysqli_stmt_execute($chk);
        $r = mysqli_stmt_get_result($chk);
        if (!mysqli_fetch_assoc($r)) $error = "Selected Manager is invalid.";
        mysqli_stmt_close($chk);
      }
    }

    // Validate team lead = Team Lead
    if ($error === '' && $team_lead_id !== null) {
      $chk = mysqli_prepare($conn, "SELECT id FROM employees WHERE id=? AND employee_status='active' AND designation='Team Lead' LIMIT 1");
      if ($chk) {
        mysqli_stmt_bind_param($chk, "i", $team_lead_id);
        mysqli_stmt_execute($chk);
        $r = mysqli_stmt_get_result($chk);
        if (!mysqli_fetch_assoc($r)) $error = "Selected Team Lead is invalid.";
        mysqli_stmt_close($chk);
      }
    }

    // Validate engineers = PE Grade 1/2
    if ($error === '' && !empty($engineer_ids)) {
      $placeholders = implode(',', array_fill(0, count($engineer_ids), '?'));
      $types = str_repeat('i', count($engineer_ids));

      $sql = "SELECT id FROM employees
              WHERE employee_status='active'
              AND designation IN ('Project Engineer Grade 1','Project Engineer Grade 2')
              AND id IN ($placeholders)";

      $stmtChk = mysqli_prepare($conn, $sql);
      if ($stmtChk) {
        mysqli_stmt_bind_param($stmtChk, $types, ...$engineer_ids);
        mysqli_stmt_execute($stmtChk);
        $resChk = mysqli_stmt_get_result($stmtChk);
        $found = [];
        while ($row = mysqli_fetch_assoc($resChk)) $found[] = (int)$row['id'];
        mysqli_stmt_close($stmtChk);

        sort($found);
        $temp = $engineer_ids; sort($temp);
        if ($found !== $temp) $error = "One or more selected Project Engineers are invalid.";
      }
    }

    // Save transaction
    if ($error === '') {
      mysqli_begin_transaction($conn);
      try {
        // Update manager (+ team lead if column exists)
        if ($hasTeamLeadCol) {
          if ($manager_id === null && $team_lead_id === null) {
            $up = mysqli_prepare($conn, "UPDATE sites SET manager_employee_id=NULL, team_lead_employee_id=NULL WHERE id=? LIMIT 1");
            if (!$up) throw new Exception("DB error: ".mysqli_error($conn));
            mysqli_stmt_bind_param($up, "i", $id);
          } elseif ($manager_id === null && $team_lead_id !== null) {
            $up = mysqli_prepare($conn, "UPDATE sites SET manager_employee_id=NULL, team_lead_employee_id=? WHERE id=? LIMIT 1");
            if (!$up) throw new Exception("DB error: ".mysqli_error($conn));
            mysqli_stmt_bind_param($up, "ii", $team_lead_id, $id);
          } elseif ($manager_id !== null && $team_lead_id === null) {
            $up = mysqli_prepare($conn, "UPDATE sites SET manager_employee_id=?, team_lead_employee_id=NULL WHERE id=? LIMIT 1");
            if (!$up) throw new Exception("DB error: ".mysqli_error($conn));
            mysqli_stmt_bind_param($up, "ii", $manager_id, $id);
          } else {
            $up = mysqli_prepare($conn, "UPDATE sites SET manager_employee_id=?, team_lead_employee_id=? WHERE id=? LIMIT 1");
            if (!$up) throw new Exception("DB error: ".mysqli_error($conn));
            mysqli_stmt_bind_param($up, "iii", $manager_id, $team_lead_id, $id);
          }
        } else {
          // No team_lead_employee_id column -> update manager only
          if ($manager_id === null) {
            $up = mysqli_prepare($conn, "UPDATE sites SET manager_employee_id=NULL WHERE id=? LIMIT 1");
            if (!$up) throw new Exception("DB error: ".mysqli_error($conn));
            mysqli_stmt_bind_param($up, "i", $id);
          } else {
            $up = mysqli_prepare($conn, "UPDATE sites SET manager_employee_id=? WHERE id=? LIMIT 1");
            if (!$up) throw new Exception("DB error: ".mysqli_error($conn));
            mysqli_stmt_bind_param($up, "ii", $manager_id, $id);
          }
        }

        if (!mysqli_stmt_execute($up)) throw new Exception("Failed updating team: ".mysqli_stmt_error($up));
        mysqli_stmt_close($up);

        // Reset mapping table (we will store PE always; and TL also here if column missing)
        $del = mysqli_prepare($conn, "DELETE FROM site_project_engineers WHERE site_id=?");
        if (!$del) throw new Exception("DB error: ".mysqli_error($conn));
        mysqli_stmt_bind_param($del, "i", $id);
        if (!mysqli_stmt_execute($del)) throw new Exception("Failed clearing engineers: ".mysqli_stmt_error($del));
        mysqli_stmt_close($del);

        // Insert team lead into mapping if NO TL column
        if (!$hasTeamLeadCol && $team_lead_id !== null) {
          $insTL = mysqli_prepare($conn, "INSERT INTO site_project_engineers (site_id, employee_id) VALUES (?, ?)");
          if (!$insTL) throw new Exception("DB error: ".mysqli_error($conn));
          mysqli_stmt_bind_param($insTL, "ii", $id, $team_lead_id);
          if (!mysqli_stmt_execute($insTL)) throw new Exception("Failed assigning Team Lead: ".mysqli_stmt_error($insTL));
          mysqli_stmt_close($insTL);
        }

        // Insert engineers
        if (!empty($engineer_ids)) {
          $ins = mysqli_prepare($conn, "INSERT INTO site_project_engineers (site_id, employee_id) VALUES (?, ?)");
          if (!$ins) throw new Exception("DB error: ".mysqli_error($conn));
          foreach ($engineer_ids as $eid) {
            mysqli_stmt_bind_param($ins, "ii", $id, $eid);
            if (!mysqli_stmt_execute($ins)) throw new Exception("Failed assigning engineer: ".mysqli_stmt_error($ins));
          }
          mysqli_stmt_close($ins);
        }

        mysqli_commit($conn);
        $success = "Team assigned successfully!";

        // After save -> return to view mode (hide form)
        header("Location: view-site.php?id=".$id);
        exit;

      } catch (Exception $ex) {
        mysqli_rollback($conn);
        $error = $ex->getMessage();
      }
    }
  }

  // -------------------- FETCH SITE (safe even if TL column missing) --------------------
  $selectTL = $hasTeamLeadCol ? "s.team_lead_employee_id," : "NULL AS team_lead_employee_id,";
  $joinTL   = $hasTeamLeadCol ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id" : "LEFT JOIN employees tl ON 1=0";

  $sql = "
    SELECT
      s.*,
      c.client_name,
      c.client_type,
      c.company_name,
      c.state,
      c.mobile_number AS client_mobile,
      c.email AS client_email,

      m.full_name AS manager_name,
      m.employee_code AS manager_code,

      $selectTL
      tl.full_name AS team_lead_name,
      tl.employee_code AS team_lead_code

    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    LEFT JOIN employees m ON m.id = s.manager_employee_id
    $joinTL
    WHERE s.id = ?
    LIMIT 1
  ";

  $stmt = mysqli_prepare($conn, $sql);
  if (!$stmt) {
    $error = "Database error: " . mysqli_error($conn);
  } else {
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $site = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    if (!$site) $error = "Site not found.";
  }

  // -------------------- DROPDOWNS + ASSIGNMENTS --------------------
  if ($site) {

    // Managers only
    $st = mysqli_prepare($conn, "SELECT id, full_name, employee_code FROM employees
                                 WHERE employee_status='active' AND designation='Manager'
                                 ORDER BY full_name ASC");
    if ($st) {
      mysqli_stmt_execute($st);
      $r = mysqli_stmt_get_result($st);
      $managers = mysqli_fetch_all($r, MYSQLI_ASSOC);
      mysqli_stmt_close($st);
    }

    // Team Lead only
    $st = mysqli_prepare($conn, "SELECT id, full_name, employee_code FROM employees
                                 WHERE employee_status='active' AND designation='Team Lead'
                                 ORDER BY full_name ASC");
    if ($st) {
      mysqli_stmt_execute($st);
      $r = mysqli_stmt_get_result($st);
      $team_leads = mysqli_fetch_all($r, MYSQLI_ASSOC);
      mysqli_stmt_close($st);
    }

    // Project Engineers only
    $st = mysqli_prepare($conn, "SELECT id, full_name, employee_code, designation FROM employees
                                 WHERE employee_status='active'
                                 AND designation IN ('Project Engineer Grade 1','Project Engineer Grade 2')
                                 ORDER BY full_name ASC");
    if ($st) {
      mysqli_stmt_execute($st);
      $r = mysqli_stmt_get_result($st);
      $project_engineers = mysqli_fetch_all($r, MYSQLI_ASSOC);
      mysqli_stmt_close($st);
    }

    // Assigned: Team Lead (fallback via mapping if no column)
    if (!$hasTeamLeadCol) {
      $tlq = mysqli_prepare($conn, "
        SELECT e.id, e.full_name, e.employee_code
        FROM site_project_engineers spe
        INNER JOIN employees e ON e.id = spe.employee_id
        WHERE spe.site_id=? AND e.employee_status='active' AND e.designation='Team Lead'
        LIMIT 1
      ");
      if ($tlq) {
        mysqli_stmt_bind_param($tlq, "i", $id);
        mysqli_stmt_execute($tlq);
        $rr = mysqli_stmt_get_result($tlq);
        if ($row = mysqli_fetch_assoc($rr)) {
          $assigned_team_lead_id = (int)$row['id'];
          $site['team_lead_employee_id'] = (int)$row['id'];
          $site['team_lead_name'] = $row['full_name'];
          $site['team_lead_code'] = $row['employee_code'];
        }
        mysqli_stmt_close($tlq);
      }
    }

    // Assigned engineers (PE Grade 1/2 only)
    $eng = mysqli_prepare($conn, "
      SELECT e.id, e.full_name, e.employee_code, e.designation
      FROM site_project_engineers spe
      INNER JOIN employees e ON e.id = spe.employee_id
      WHERE spe.site_id=?
        AND e.employee_status='active'
        AND e.designation IN ('Project Engineer Grade 1','Project Engineer Grade 2')
      ORDER BY e.full_name ASC
    ");
    if ($eng) {
      mysqli_stmt_bind_param($eng, "i", $id);
      mysqli_stmt_execute($eng);
      $rr = mysqli_stmt_get_result($eng);
      while ($row = mysqli_fetch_assoc($rr)) {
        $assigned_engineers[] = (int)$row['id'];
        $assigned_engineers_rows[] = $row;
      }
      mysqli_stmt_close($eng);
    }
  }
}

// Determine if team already assigned
$isAssigned = false;
if ($site) {
  $mgrAssigned = !empty($site['manager_employee_id']);
  $tlAssigned  = !empty($site['team_lead_employee_id']);
  $peAssigned  = !empty($assigned_engineers);

  $isAssigned = ($mgrAssigned || $tlAssigned || $peAssigned);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>View Site - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Google Maps -->
  <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE&libraries=places,geometry"></script>

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{
      background:#fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding: 18px;
      margin-bottom: 16px;
    }

    .btn-back{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 14px;
      color:#111827;
      font-weight: 900;
      display:inline-flex;
      align-items:center;
      gap:8px;
      text-decoration:none;
    }
    .btn-back:hover{ background:#f9fafb; color: var(--blue); border-color: rgba(45,156,219,.25); }

    .btn-edit{
      background: var(--blue);
      color:#fff;
      border:none;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 900;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
      text-decoration:none;
    }
    .btn-edit:hover{ background:#2a8bc9; color:#fff; }

    .btn-edit-secondary{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 14px;
      color:#111827;
      font-weight: 900;
      display:inline-flex;
      align-items:center;
      gap:8px;
      text-decoration:none;
    }
    .btn-edit-secondary:hover{ background:#f9fafb; color: var(--blue); border-color: rgba(45,156,219,.25); }

    .client-hero{
      display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;
    }
    .client-id{ display:flex; gap:14px; align-items:center; min-width:260px; }

    .site-avatar{
      width:54px;height:54px;border-radius: 16px;
      background: linear-gradient(135deg, rgba(45,156,219,.95), rgba(99,102,241,.95));
      color:#fff; display:flex; align-items:center; justify-content:center;
      font-weight:1000; letter-spacing:.5px; flex:0 0 auto;
    }

    .hero-title{ margin:0; font-weight:1000; color:#111827; font-size:18px; line-height:1.2; }
    .hero-sub{ margin:4px 0 0; color:#6b7280; font-weight:700; font-size:13px; }

    .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      font-size:12px; font-weight:900;
      border:1px solid #e5e7eb;
      background:#f9fafb;
      color:#111827;
      white-space:nowrap;
    }
    .chip-primary{
      border-color: rgba(45,156,219,.22);
      background: rgba(45,156,219,.08);
      color: var(--blue);
    }

    .section-card{
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      overflow:hidden;
      background:#fff;
      margin-bottom: 14px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
    }
    .section-head{
      padding: 14px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      background:#f9fafb;
      border-bottom: 1px solid #eef2f7;
    }
    .section-head .left{ display:flex; align-items:center; gap:10px; }
    .sec-ic{
      width:36px;height:36px;border-radius: 12px;
      display:flex; align-items:center; justify-content:center;
      background: rgba(45,156,219,.12);
      color: var(--blue);
      font-size: 16px;
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:700; font-size:12px; }

    .form-panel { padding: 16px; }
    .form-label { font-weight: 900; color:#374151; font-size: 13px; }
    .form-select {
      border:2px solid #e5e7eb;
      border-radius: 12px;
      padding: 11px 12px;
      font-weight: 700;
      font-size: 14px;
    }

    .btn-save{
      background: var(--blue);
      color:#fff;
      border:none;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 1000;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
    }
    .btn-save:hover{ background:#2a8bc9; color:#fff; }

    .kv-row{
      display:grid;
      grid-template-columns: 160px 1fr;
      gap:12px;
      padding: 12px 16px;
      border-bottom: 1px solid #eef2f7;
      align-items:start;
    }
    .kv-row:last-child{ border-bottom:none; }
    .kv-k{
      color:#6b7280;
      font-weight:900;
      font-size:12px;
      text-transform: uppercase;
      letter-spacing:.35px;
    }
    .kv-v{
      text-align:right;
      color:#111827;
      font-weight:800;
      font-size:13px;
      word-break: break-word;
    }

    /* Map styles */
    #locationMap { 
      height: 300px; 
      width: 100%; 
      border-radius: 12px; 
      margin-top: 10px; 
      margin-bottom: 10px;
      border: 1px solid #e5e7eb;
    }
    .location-badge {
      background: #f3f4f6;
      padding: 4px 8px;
      border-radius: 8px;
      font-size: 11px;
      color: #4b5563;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .coordinates-info {
      background: #f9fafb;
      padding: 12px;
      border-radius: 12px;
      margin-top: 10px;
    }
    
    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .kv-row{ grid-template-columns: 150px 1fr; }
      .kv-v{ text-align:left; }
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
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">View Site</h1>
            <p class="text-muted mb-0">Site details, team assignments & location</p>
          </div>
          <div class="d-flex gap-2">
            <a href="manage-sites.php" class="btn-back">
              <i class="bi bi-arrow-left"></i> Back
            </a>
            <?php if ($site): ?>
              <a href="edit-site.php?id=<?php echo (int)$site['id']; ?>" class="btn-edit">
                <i class="bi bi-pencil"></i> Edit Site
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($site): ?>

          <!-- Summary -->
          <div class="panel">
            <div class="client-hero">
              <div class="client-id">
                <div class="site-avatar"><?php echo e(initials($site['project_name'] ?? 'Site')); ?></div>
                <div>
                  <p class="hero-title"><?php echo showVal($site['project_name']); ?></p>
                  <p class="hero-sub">
                    <?php echo showVal($site['client_name']); ?>
                    <?php if (!empty($site['state'])): ?> • <?php echo e($site['state']); ?><?php endif; ?>
                  </p>

                  <div class="d-flex flex-wrap gap-2 mt-2">
                    <span class="chip chip-primary"><i class="bi bi-person-badge"></i> Manager:
                      <strong class="ms-1"><?php echo showVal($site['manager_name'] ?? ''); ?></strong>
                    </span>
                    <span class="chip"><i class="bi bi-person-check"></i> Team Lead:
                      <strong class="ms-1"><?php echo showVal($site['team_lead_name'] ?? ''); ?></strong>
                    </span>
                    <span class="chip"><i class="bi bi-people"></i> Engineers:
                      <strong class="ms-1"><?php echo (int)count($assigned_engineers); ?></strong>
                    </span>
                  </div>
                </div>
              </div>

              <div class="d-flex flex-wrap gap-2">
                <?php if (!empty($site['client_email'])): ?>
                  <a class="btn btn-sm btn-outline-dark" href="mailto:<?php echo e($site['client_email']); ?>"><i class="bi bi-envelope"></i> Email</a>
                <?php endif; ?>
                <?php if (!empty($site['client_mobile'])): ?>
                  <a class="btn btn-sm btn-outline-dark" href="tel:<?php echo e($site['client_mobile']); ?>"><i class="bi bi-telephone"></i> Call</a>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- TEAM SECTION -->
          <div class="section-card" id="team">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-people"></i></div>
                <div>
                  <p class="sec-title mb-0">Site Team</p>
                  <p class="sec-sub">
                    <?php if ($isAssigned && !$edit_team): ?>
                      Team already assigned (view only)
                    <?php else: ?>
                      Assign Manager → Team Lead → Project Engineers
                    <?php endif; ?>
                  </p>
                </div>
              </div>

              <?php if ($isAssigned && !$edit_team): ?>
                <a href="view-site.php?id=<?php echo (int)$site['id']; ?>&edit_team=1#team" class="btn-edit-secondary">
                  <i class="bi bi-pencil-square"></i> Edit Team
                </a>
              <?php elseif ($edit_team): ?>
                <a href="view-site.php?id=<?php echo (int)$site['id']; ?>#team" class="btn-edit-secondary">
                  <i class="bi bi-x-circle"></i> Cancel
                </a>
              <?php endif; ?>
            </div>

            <?php if ($isAssigned && !$edit_team): ?>
              <!-- ✅ VIEW ONLY -->
              <div class="kv">
                <div class="kv-row">
                  <div class="kv-k">Manager</div>
                  <div class="kv-v">
                    <?php echo showVal($site['manager_name'] ?? ''); ?>
                    <?php if (!empty($site['manager_code'])): ?>
                      <span class="text-muted" style="font-weight:800;">(<?php echo e($site['manager_code']); ?>)</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="kv-row">
                  <div class="kv-k">Team Lead</div>
                  <div class="kv-v">
                    <?php echo showVal($site['team_lead_name'] ?? ''); ?>
                    <?php if (!empty($site['team_lead_code'])): ?>
                      <span class="text-muted" style="font-weight:800;">(<?php echo e($site['team_lead_code']); ?>)</span>
                    <?php endif; ?>
                    <?php if (!$hasTeamLeadCol): ?>
                      <div class="text-muted mt-1" style="font-weight:700; font-size:12px;">
                        (Stored via mapping table because <b>team_lead_employee_id</b> column not found)
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="kv-row">
                  <div class="kv-k">Project Engineers</div>
                  <div class="kv-v">
                    <?php if (!empty($assigned_engineers_rows)): ?>
                      <?php foreach ($assigned_engineers_rows as $row): ?>
                        <div>
                          <?php echo e($row['full_name']); ?>
                          <span class="text-muted" style="font-weight:800;">(<?php echo e($row['designation']); ?>)</span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </div>
                </div>
              </div>

            <?php else: ?>
              <!-- ✅ SHOW ASSIGN FORM ONLY IF NOT ASSIGNED OR edit_team=1 -->
              <div class="form-panel">
                <?php if ($isAssigned && $edit_team): ?>
                  <div class="alert alert-warning mb-3" role="alert" style="border-radius:14px;">
                    <i class="bi bi-info-circle me-2"></i>
                    You are editing the existing team. Click <b>Cancel</b> to return to view mode.
                  </div>
                <?php endif; ?>

                <form method="POST" class="row g-3">
                  <input type="hidden" name="assign_team" value="1">

                  <div class="col-md-4">
                    <label class="form-label">Manager (only Managers)</label>
                    <select name="manager_employee_id" class="form-select">
                      <option value="">-- Select Manager --</option>
                      <?php foreach ($managers as $m): ?>
                        <?php
                          $eid = (int)$m['id'];
                          $selected = (!empty($site['manager_employee_id']) && (int)$site['manager_employee_id'] === $eid) ? 'selected' : '';
                        ?>
                        <option value="<?php echo $eid; ?>" <?php echo $selected; ?>>
                          <?php echo e($m['full_name']); ?> (<?php echo e($m['employee_code']); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="text-muted mt-1" style="font-weight:700; font-size:12px;">
                      Leave empty to unassign manager.
                    </div>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label">Team Lead (only Team Leads)</label>
                    <select name="team_lead_employee_id" class="form-select">
                      <option value="">-- Select Team Lead --</option>
                      <?php
                        $currentTL = !empty($site['team_lead_employee_id']) ? (int)$site['team_lead_employee_id'] : 0;
                      ?>
                      <?php foreach ($team_leads as $t): ?>
                        <?php
                          $eid = (int)$t['id'];
                          $selected = ($currentTL === $eid) ? 'selected' : '';
                        ?>
                        <option value="<?php echo $eid; ?>" <?php echo $selected; ?>>
                          <?php echo e($t['full_name']); ?> (<?php echo e($t['employee_code']); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="text-muted mt-1" style="font-weight:700; font-size:12px;">
                      Leave empty to unassign team lead.
                      <?php if (!$hasTeamLeadCol): ?>
                        <br><span class="text-muted">(Saved via mapping table because TL column is missing.)</span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label">Project Engineers (only Grade 1/2)</label>
                    <select name="project_engineers[]" class="form-select" multiple size="8">
                      <?php foreach ($project_engineers as $p): ?>
                        <?php
                          $eid = (int)$p['id'];
                          $isSel = in_array($eid, $assigned_engineers, true) ? 'selected' : '';
                          $meta = trim(($p['employee_code'] ?? '').' • '.($p['designation'] ?? ''));
                        ?>
                        <option value="<?php echo $eid; ?>" <?php echo $isSel; ?>>
                          <?php echo e($p['full_name']); ?> (<?php echo e($meta); ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="text-muted mt-1" style="font-weight:700; font-size:12px;">
                      Hold <b>Ctrl</b>/<b>Cmd</b> to select multiple.
                    </div>
                  </div>

                  <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn-save">
                      <i class="bi bi-check2-circle"></i> Save Team
                    </button>
                  </div>

                </form>
              </div>
            <?php endif; ?>

          </div>

          <!-- LOCATION SECTION (NEW) -->
          <div class="section-card" id="location">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-geo-alt-fill"></i></div>
                <div>
                  <p class="sec-title mb-0">Site Location & Geo-fence</p>
                  <p class="sec-sub">Physical location and attendance radius</p>
                </div>
              </div>
            </div>

            <div class="panel-body p-3">
              <?php if (!empty($site['latitude']) && !empty($site['longitude'])): ?>
                <!-- Map Container -->
                <div id="locationMap"></div>
                
                <!-- Coordinates Info -->
                <div class="coordinates-info">
                  <div class="row g-3">
                    <div class="col-md-3">
                      <div class="location-badge">
                        <i class="bi bi-crosshair"></i>
                        Latitude: <strong><?php echo number_format($site['latitude'], 6); ?>°</strong>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <div class="location-badge">
                        <i class="bi bi-crosshair"></i>
                        Longitude: <strong><?php echo number_format($site['longitude'], 6); ?>°</strong>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <div class="location-badge">
                        <i class="bi bi-circle"></i>
                        Radius: <strong><?php echo (int)($site['location_radius'] ?? 100); ?> meters</strong>
                      </div>
                    </div>
                    <div class="col-md-3">
                      <div class="location-badge">
                        <i class="bi bi-geo"></i>
                        Place ID: <strong><?php echo showVal($site['place_id'] ?? ''); ?></strong>
                      </div>
                    </div>
                    <?php if (!empty($site['location_address'])): ?>
                      <div class="col-12">
                        <div class="location-badge w-100">
                          <i class="bi bi-pin-map"></i>
                          <strong>Address:</strong> <?php echo e($site['location_address']); ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <script>
                  function initMap() {
                    const siteLat = <?php echo $site['latitude']; ?>;
                    const siteLng = <?php echo $site['longitude']; ?>;
                    const siteRadius = <?php echo (int)($site['location_radius'] ?? 100); ?>;
                    
                    const siteLocation = { lat: siteLat, lng: siteLng };
                    
                    const map = new google.maps.Map(document.getElementById('locationMap'), {
                      center: siteLocation,
                      zoom: 16,
                      mapTypeId: google.maps.MapTypeId.ROADMAP,
                      mapTypeControl: true,
                      streetViewControl: true,
                      fullscreenControl: true
                    });
                    
                    // Add marker
                    const marker = new google.maps.Marker({
                      position: siteLocation,
                      map: map,
                      title: '<?php echo e($site['project_name']); ?>',
                      animation: google.maps.Animation.DROP
                    });
                    
                    // Add info window
                    const infoWindow = new google.maps.InfoWindow({
                      content: `
                        <div style="padding: 8px;">
                          <h6 style="margin: 0 0 5px; font-weight: bold;"><?php echo e($site['project_name']); ?></h6>
                          <p style="margin: 0; font-size: 12px;">
                            <i class="bi bi-geo-alt"></i> <?php echo e($site['location_address'] ?? 'No address'); ?><br>
                            <strong>Radius:</strong> <?php echo (int)($site['location_radius'] ?? 100); ?>m
                          </p>
                        </div>
                      `
                    });
                    
                    marker.addListener('click', function() {
                      infoWindow.open(map, marker);
                    });
                    
                    // Add circle for geo-fence
                    const circle = new google.maps.Circle({
                      map: map,
                      center: siteLocation,
                      radius: siteRadius,
                      fillColor: '#43e97b',
                      fillOpacity: 0.1,
                      strokeColor: '#43e97b',
                      strokeOpacity: 0.5,
                      strokeWeight: 2
                    });
                  }
                  
                  // Initialize map when page loads
                  window.onload = initMap;
                </script>
              <?php else: ?>
                <div class="alert alert-info mb-0">
                  <i class="bi bi-info-circle me-2"></i>
                  No location coordinates set for this site. 
                  <a href="edit-site.php?id=<?php echo (int)$site['id']; ?>" class="alert-link">Add location now</a>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Project Details Section -->
          <div class="section-card">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-briefcase"></i></div>
                <div>
                  <p class="sec-title mb-0">Project Details</p>
                  <p class="sec-sub">Scope and timeline</p>
                </div>
              </div>
            </div>
            <div class="kv">
              <div class="kv-row">
                <div class="kv-k">Project Type</div>
                <div class="kv-v"><span class="badge bg-light text-dark"><?php echo showVal($site['project_type']); ?></span></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Scope of Work</div>
                <div class="kv-v">
                  <?php 
                  $scopes = explode(', ', $site['scope_of_work']);
                  foreach ($scopes as $scope): 
                  ?>
                    <span class="badge bg-light text-dark me-1"><?php echo e($scope); ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Contract Value</div>
                <div class="kv-v">₹ <?php echo number_format((float)$site['contract_value'], 2); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">PMC Charges</div>
                <div class="kv-v">₹ <?php echo number_format((float)$site['pmc_charges'], 2); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Start Date</div>
                <div class="kv-v"><?php echo showDateVal($site['start_date']); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Expected Completion</div>
                <div class="kv-v"><?php echo showDateVal($site['expected_completion_date']); ?></div>
              </div>
            </div>
          </div>

          <!-- Contract & Agreement Section -->
          <div class="section-card">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-file-earmark-text"></i></div>
                <div>
                  <p class="sec-title mb-0">Contract & Agreement</p>
                  <p class="sec-sub">Legal documents and dates</p>
                </div>
              </div>
            </div>
            <div class="kv">
              <div class="kv-row">
                <div class="kv-k">Agreement Number</div>
                <div class="kv-v"><?php echo showVal($site['agreement_number']); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Agreement Date</div>
                <div class="kv-v"><?php echo showDateVal($site['agreement_date']); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Work Order Date</div>
                <div class="kv-v"><?php echo showDateVal($site['work_order_date']); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Contract Document</div>
                <div class="kv-v"><?php echo fileLinkBtn($site['contract_document']); ?></div>
              </div>
            </div>
          </div>

          <!-- Client Communication Section -->
          <div class="section-card">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-person-check"></i></div>
                <div>
                  <p class="sec-title mb-0">Client Communication</p>
                  <p class="sec-sub">Contact persons and authorities</p>
                </div>
              </div>
            </div>
            <div class="kv">
              <div class="kv-row">
                <div class="kv-k">Authorized Signatory</div>
                <div class="kv-v"><?php echo showVal($site['authorized_signatory_name']); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Signatory Contact</div>
                <div class="kv-v">
                  <?php if (!empty($site['authorized_signatory_contact'])): ?>
                    <a href="tel:<?php echo e($site['authorized_signatory_contact']); ?>">
                      <?php echo e($site['authorized_signatory_contact']); ?>
                    </a>
                  <?php else: ?>—<?php endif; ?>
                </div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Contact Person</div>
                <div class="kv-v"><?php echo showVal($site['contact_person_designation']); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Contact Email</div>
                <div class="kv-v">
                  <?php if (!empty($site['contact_person_email'])): ?>
                    <a href="mailto:<?php echo e($site['contact_person_email']); ?>">
                      <?php echo e($site['contact_person_email']); ?>
                    </a>
                  <?php else: ?>—<?php endif; ?>
                </div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Approval Authority</div>
                <div class="kv-v"><?php echo showVal($site['approval_authority']); ?></div>
              </div>
              <div class="kv-row">
                <div class="kv-k">Site In-charge (Client)</div>
                <div class="kv-v"><?php echo showVal($site['site_in_charge_client_side'] ?? ''); ?></div>
              </div>
            </div>
          </div>

        <?php endif; ?>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>
</body>
</html>

<?php
if (isset($conn)) { mysqli_close($conn); }
?>