<?php
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

// ✅ Real session (remove hard defaults in production)
$current_employee_id   = (int)($_SESSION['employee_id'] ?? 0);
$current_employee_name = (string)($_SESSION['employee_name'] ?? '');

if ($current_employee_id <= 0) {
  header("Location: ../login.php");
  exit;
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

function columnExists($conn, string $table, string $column): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $columnEsc = mysqli_real_escape_string($conn, $column);
  $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
  if (!$res) return false;
  $ok = mysqli_num_rows($res) > 0;
  mysqli_free_result($res);
  return $ok;
}

function roleKeyFromDesignation(string $designation): string {
  $d = strtolower(trim($designation));
  if (str_contains($d, 'director') || str_contains($d, 'admin') || str_contains($d, 'vice president') || str_contains($d, 'general manager')) return 'admin';
  if (str_contains($d, 'hr') || str_contains($d, 'human resource')) return 'hr';
  if (str_contains($d, 'manager')) return 'manager';
  if (str_contains($d, 'team lead') || str_contains($d, 'tl') || str_contains($d, 'lead')) return 'tl';
  if (str_contains($d, 'project engineer') || str_contains($d, 'engineer')) return 'project_engineer';
  return 'employee';
}


$today = date('Y-m-d');
$current_time = date('h:i A');

// Employee
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$employee) {
  die("Employee not found or inactive.");
}

$currentRoleKey = roleKeyFromDesignation((string)($employee['designation'] ?? ''));

// Today's attendance
$att_stmt = mysqli_prepare($conn, "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
mysqli_stmt_bind_param($att_stmt, "is", $current_employee_id, $today);
mysqli_stmt_execute($att_stmt);
$att_res = mysqli_stmt_get_result($att_stmt);
$attendance = mysqli_fetch_assoc($att_res);
mysqli_stmt_close($att_stmt);

// Assigned sites count + data.
// PE: site_project_engineers.employee_id
// TL: sites.team_lead_employee_id OR fallback site_project_engineers
$hasTeamLeadCol = columnExists($conn, 'sites', 'team_lead_employee_id');

if ($currentRoleKey === 'tl' && $hasTeamLeadCol) {
  $sites_query = "
    SELECT DISTINCT s.*
    FROM sites s
    LEFT JOIN site_project_engineers spe ON s.id = spe.site_id
    WHERE (s.team_lead_employee_id = ? OR spe.employee_id = ?)
      AND s.deleted_at IS NULL
    ORDER BY s.project_name ASC
  ";
  $sites_stmt = mysqli_prepare($conn, $sites_query);
  mysqli_stmt_bind_param($sites_stmt, "ii", $current_employee_id, $current_employee_id);
} else {
  $sites_query = "
    SELECT DISTINCT s.*
    FROM sites s
    JOIN site_project_engineers spe ON s.id = spe.site_id
    WHERE spe.employee_id = ?
      AND s.deleted_at IS NULL
    ORDER BY s.project_name ASC
  ";
  $sites_stmt = mysqli_prepare($conn, $sites_query);
  mysqli_stmt_bind_param($sites_stmt, "i", $current_employee_id);
}

mysqli_stmt_execute($sites_stmt);
$sites_res = mysqli_stmt_get_result($sites_stmt);
$assigned_sites = mysqli_fetch_all($sites_res, MYSQLI_ASSOC);
mysqli_stmt_close($sites_stmt);

// Can punch office?
$can_punch_office = in_array($currentRoleKey, ['admin', 'hr', 'manager', 'tl'], true);

// Recent attendance
$history_query = "
  SELECT * FROM attendance
  WHERE employee_id = ?
  ORDER BY attendance_date DESC
  LIMIT 10
";
$history_stmt = mysqli_prepare($conn, $history_query);
mysqli_stmt_bind_param($history_stmt, "i", $current_employee_id);
mysqli_stmt_execute($history_stmt);
$history_res = mysqli_stmt_get_result($history_stmt);
$recent_history = mysqli_fetch_all($history_res, MYSQLI_ASSOC);
mysqli_stmt_close($history_stmt);

// Stats
$month_stmt = mysqli_prepare($conn, "
  SELECT COUNT(*) AS days
  FROM attendance
  WHERE employee_id = ?
    AND MONTH(attendance_date) = MONTH(CURDATE())
    AND YEAR(attendance_date) = YEAR(CURDATE())
    AND status = 'present'
");
mysqli_stmt_bind_param($month_stmt, "i", $current_employee_id);
mysqli_stmt_execute($month_stmt);
$month_res = mysqli_stmt_get_result($month_stmt);
$month_row = mysqli_fetch_assoc($month_res);
$days_present = (int)($month_row['days'] ?? 0);
mysqli_stmt_close($month_stmt);

// Total hours (decimal hours)
$hours_stmt = mysqli_prepare($conn, "
  SELECT SUM(COALESCE(total_hours,0)) AS total
  FROM attendance
  WHERE employee_id = ?
    AND MONTH(attendance_date) = MONTH(CURDATE())
    AND YEAR(attendance_date) = YEAR(CURDATE())
");
mysqli_stmt_bind_param($hours_stmt, "i", $current_employee_id);
mysqli_stmt_execute($hours_stmt);
$hours_res = mysqli_stmt_get_result($hours_stmt);
$hours_row = mysqli_fetch_assoc($hours_res);
$total_hours_month = round((float)($hours_row['total'] ?? 0), 1);
mysqli_stmt_close($hours_stmt);

// Avg hours
$avg_stmt = mysqli_prepare($conn, "
  SELECT AVG(COALESCE(total_hours,0)) AS avg_hours
  FROM attendance
  WHERE employee_id = ?
    AND MONTH(attendance_date) = MONTH(CURDATE())
    AND YEAR(attendance_date) = YEAR(CURDATE())
    AND total_hours IS NOT NULL
");
mysqli_stmt_bind_param($avg_stmt, "i", $current_employee_id);
mysqli_stmt_execute($avg_stmt);
$avg_res = mysqli_stmt_get_result($avg_stmt);
$avg_row = mysqli_fetch_assoc($avg_res);
$avg_hours = round((float)($avg_row['avg_hours'] ?? 0), 1);
mysqli_stmt_close($avg_stmt);

// Streak (consecutive days with records)
$streak = 0;
$check_date = date('Y-m-d');
while (true) {
  $st_stmt = mysqli_prepare($conn, "SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
  mysqli_stmt_bind_param($st_stmt, "is", $current_employee_id, $check_date);
  mysqli_stmt_execute($st_stmt);
  $st_res = mysqli_stmt_get_result($st_stmt);
  $exists = mysqli_fetch_assoc($st_res);
  mysqli_stmt_close($st_stmt);

  if ($exists) {
    $streak++;
    $check_date = date('Y-m-d', strtotime($check_date . ' -1 day'));
  } else {
    break;
  }
}

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safeTimeOnly($v, $dash='—'){
  if (empty($v)) return $dash;
  $ts = strtotime($v);
  return $ts ? date('h:i A', $ts) : $dash;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Punch In/Out - TEK-C</title>

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
      --orange:#f2994a;
      --green:#27ae60;
      --red:#eb5757;
      --purple:#7c3aed;
    }

    body{ background:var(--page-bg); }

    .content-scroll{ flex:1 1 auto; overflow:auto; padding:16px; }
    .projects-wrapper{ width:100%; }

    .page-heading{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }

    .page-heading h1{ font-size:19px; font-weight:900; color:var(--text); margin:0; }
    .page-heading p{ margin:3px 0 0; color:var(--muted); font-size:12px; font-weight:600; }

    .primary-btn,.secondary-btn,.btn-action{
      min-height:36px;
      padding:0 14px;
      border-radius:11px;
      font-size:12px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:7px;
      text-decoration:none;
      white-space:nowrap;
    }

    .primary-btn{
      border:0;
      background:#111827;
      color:#fff;
    }

    .primary-btn:hover{ background:#020617; color:#fff; }

    .secondary-btn,.btn-action{
      border:1px solid var(--border);
      background:#fff;
      color:#334155;
    }

    .secondary-btn:hover,.btn-action:hover{
      border-color:#cbd5e1;
      background:#f8fafc;
      color:#111827;
    }

    .panel{
      background:var(--card-bg);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:13px;
      margin-bottom:14px;
      height:auto;
    }

    .panel-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:12px;
    }

    .panel-title{ font-weight:900; font-size:14px; color:var(--text); margin:0; }
    .panel-subtitle{ color:var(--muted); font-size:11px; font-weight:700; margin-top:2px; }

    .panel-menu{
      width:34px;
      height:34px;
      border-radius:11px;
      border:1px solid var(--border);
      background:#fff;
      display:grid;
      place-items:center;
      color:#64748b;
      flex:0 0 auto;
    }

    .stat-card{
      background:#fff;
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:12px 13px;
      min-height:78px;
      display:flex;
      align-items:center;
      gap:11px;
      transition:.15s ease;
    }

    .stat-card:hover{
      transform:translateY(-1px);
      box-shadow:0 14px 32px rgba(15,23,42,.09);
    }

    .stat-ic{
      width:38px;
      height:38px;
      border-radius:12px;
      display:grid;
      place-items:center;
      color:#fff;
      font-size:17px;
      flex:0 0 auto;
    }

    .stat-ic.blue{ background:var(--blue); }
    .stat-ic.green{ background:var(--green); }
    .stat-ic.yellow{ background:var(--orange); }
    .stat-ic.red{ background:var(--red); }

    .stat-label{
      color:#64748b;
      font-weight:800;
      font-size:10.5px;
      text-transform:uppercase;
    }

    .stat-value{
      font-size:24px;
      font-weight:950;
      line-height:1;
      color:#111827;
    }

    .employee-summary{
      display:flex;
      align-items:center;
      gap:10px;
    }

    .employee-avatar-small{
      width:42px;
      height:42px;
      border-radius:13px;
      background:#111827;
      display:flex;
      align-items:center;
      justify-content:center;
      color:#fff;
      font-weight:950;
      font-size:17px;
      overflow:hidden;
      flex:0 0 auto;
    }

    .employee-avatar-small img{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
    }

    .employee-name{
      font-size:13px;
      font-weight:950;
      color:#111827;
      margin:0;
      line-height:1.25;
    }

    .employee-meta{
      font-size:10.5px;
      color:#64748b;
      font-weight:800;
      margin-top:3px;
      display:flex;
      gap:6px;
      flex-wrap:wrap;
    }

    .mini-chip,.r-chip,.history-hours{
      border:1px solid #e2e8f0;
      background:#f8fafc;
      border-radius:999px;
      padding:4px 8px;
      font-weight:900;
      font-size:10px;
      color:#334155;
      display:inline-flex;
      align-items:center;
      gap:5px;
    }

    .punch-card-compact{
      background:linear-gradient(135deg, #111827 0%, #334155 100%);
      border-radius:16px;
      padding:16px 18px;
      color:#fff;
      margin-bottom:14px;
      box-shadow:var(--shadow);
      position:relative;
      overflow:hidden;
    }

    .punch-card-compact::after{
      content:"";
      position:absolute;
      width:170px;
      height:170px;
      border-radius:50%;
      background:rgba(255,255,255,.08);
      right:-55px;
      top:-65px;
    }

    .punch-time-compact{
      font-size:28px;
      font-weight:950;
      line-height:1.2;
      font-family:'Courier New', monospace;
      letter-spacing:.5px;
      position:relative;
      z-index:1;
    }

    .punch-date-compact{
      font-size:11.5px;
      opacity:.9;
      font-weight:750;
      position:relative;
      z-index:1;
    }

    .punch-greeting-compact{
      font-size:13px;
      font-weight:850;
      margin-bottom:5px;
      position:relative;
      z-index:1;
    }

    .punch-btn-compact{
      background:rgba(255,255,255,.16);
      border:1px solid rgba(255,255,255,.32);
      color:#fff;
      padding:9px 14px;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:6px;
      position:relative;
      z-index:1;
    }

    .punch-btn-compact:hover{
      background:#fff;
      color:#111827;
      border-color:#fff;
    }

    .status-badge{
      padding:5px 8px;
      border-radius:999px;
      font-size:10px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      gap:5px;
      white-space:nowrap;
      text-transform:uppercase;
      border:1px solid transparent;
    }

    .status-green{ background:#dcfce7; color:#15803d; border-color:#bbf7d0; }
    .status-yellow{ background:#fef3c7; color:#b45309; border-color:#fde68a; }
    .status-white{ background:rgba(255,255,255,.16); color:#fff; border-color:rgba(255,255,255,.28); }

    .compact-table-wrap{
      width:100%;
      border:1px solid var(--border);
      border-radius:13px;
      overflow:hidden;
      background:#fff;
    }

    .compact-table{ width:100%; margin:0; table-layout:auto; }

    .compact-table thead th{
      background:var(--soft);
      color:#64748b;
      font-size:10px;
      text-transform:uppercase;
      font-weight:900;
      border-bottom:1px solid var(--border)!important;
      padding:8px 9px;
    }

    .compact-table tbody td{
      padding:8px 9px;
      vertical-align:middle;
      border-color:#eef2f7;
      color:#334155;
      font-weight:700;
      font-size:11.5px;
    }

    .compact-table tbody tr:hover{ background:#fbfdff; }

    .proj-title{ font-weight:950; font-size:12px; color:#111827; margin:0; line-height:1.25; }
    .proj-sub{ font-size:10.5px; color:#64748b; font-weight:750; line-height:1.35; }

    .r-card{
      border:1px solid var(--border);
      border-radius:14px;
      background:#fff;
      box-shadow:var(--shadow);
      padding:12px;
      margin-bottom:12px;
    }

    .r-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .r-kv{ margin-top:10px; display:grid; gap:8px; }
    .r-row{ display:flex; gap:10px; align-items:flex-start; }
    .r-key{ flex:0 0 90px; color:#64748b; font-weight:900; font-size:10px; text-transform:uppercase; }
    .r-val{ flex:1 1 auto; font-weight:800; color:#111827; font-size:12px; line-height:1.25; }
    .r-badges{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }

    .empty-state{
      text-align:center;
      padding:28px 12px;
      color:#64748b;
      font-size:12px;
      font-weight:900;
    }

    .empty-state i{
      display:block;
      font-size:32px;
      opacity:.45;
      margin-bottom:8px;
    }

    .alert{
      border-radius:14px;
      border:1px solid transparent;
      box-shadow:var(--shadow);
      font-size:12px;
      font-weight:850;
      margin-bottom:14px;
    }

    .alert-success{ background:#dcfce7; border-color:#bbf7d0; color:#166534; }
    .alert-danger{ background:#fee2e2; border-color:#fecaca; color:#991b1b; }

    @media(max-width:991.98px){
      .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
      .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
      .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0)!important; }
    }

    @media(max-width:1199px){
      .compact-table thead{ display:none; }
      .compact-table,.compact-table tbody,.compact-table tr,.compact-table td{
        display:block;
        width:100%;
      }
      .compact-table tbody tr{
        border-bottom:1px solid var(--border);
        padding:10px;
      }
      .compact-table tbody td{
        border:0;
        display:flex;
        justify-content:space-between;
        gap:12px;
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
      }
      .compact-table tbody td:first-child::before{
        display:none;
      }
    }

    @media(max-width:768px){
      .content-scroll{ padding:12px 10px!important; }
      .container-fluid.projects-wrapper{ padding-left:0!important; padding-right:0!important; }
      .page-heading{ align-items:flex-start; flex-direction:column; }
      .panel{ padding:12px; }
      .primary-btn,.secondary-btn,.btn-action{ width:100%; }
      .punch-time-compact{ font-size:24px; }
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
            <h1>Punch In/Out</h1>
            <p>Mark your daily attendance with location validation</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="my-attendance.php" class="secondary-btn">
              <i class="bi bi-calendar-check"></i> My Attendance
            </a>
          </div>
        </div>

        <?php if ($flash_success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo e($flash_success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($flash_error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($flash_error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Employee Row -->
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <div class="panel employee-summary">
              <div class="employee-avatar-small">
                <?php
                if (!empty($employee['photo'])) {
                  $photoPath = ltrim(str_replace('\\', '/', (string)$employee['photo']), '/');
                  if (strpos($photoPath, '../admin/') === 0) { $photoPath = substr($photoPath, 9); }
                  if (strpos($photoPath, 'admin/') === 0) { $photoPath = substr($photoPath, 6); }
                  echo '<img src="../admin/' . e($photoPath) . '" alt="' . e($employee['full_name']) . '">';
                } else {
                  echo strtoupper(substr((string)$employee['full_name'], 0, 1));
                }
                ?>
              </div>
              <div>
                <h5 class="employee-name"><?php echo e($employee['full_name']); ?></h5>
                <p class="employee-meta">
                  <span class="mini-chip"><?php echo e($employee['employee_code'] ?? ''); ?></span>
                  <span class="mini-chip"><?php echo e($employee['designation'] ?? ''); ?></span>
                </p>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="panel d-flex align-items-center justify-content-between">
              <div>
                <div class="proj-sub"><i class="bi bi-geo-alt"></i> Assigned Sites</div>
                <div class="fw-bold" ><?php echo (int)count($assigned_sites); ?></div>
              </div>
              <div style="width:10px;height:10px;background:var(--blue);border-radius:50%;"></div>
            </div>
          </div>
        </div>

        <!-- Punch Card -->
        <div class="row mb-3">
          <div class="col-12">
            <div class="punch-card-compact">
              <div class="row align-items-center">
                <div class="col-md-8">
                  <div class="punch-greeting-compact">
                    👋 Good <?php echo (date('H') < 12) ? 'Morning' : ((date('H') < 17) ? 'Afternoon' : 'Evening'); ?>,
                    <?php echo e(explode(' ', (string)$employee['full_name'])[0] ?? ''); ?>!
                  </div>
                  <div class="d-flex align-items-baseline gap-2">
                    <div class="punch-time-compact" id="currentTime"><?php echo e($current_time); ?></div>
                    <div class="punch-date-compact" id="currentDate"><?php echo e(date('D, d M Y')); ?></div>
                  </div>
                  <?php if ($can_punch_office): ?>
                    <div class="punch-date-compact mt-2"><i class="bi bi-shield-check"></i> Office punching enabled for your role</div>
                  <?php endif; ?>
                </div>

                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                  <?php if (!$attendance): ?>
                    <a href="punch-action.php?action=in" class="punch-btn-compact">
                      <i class="bi bi-box-arrow-in-right"></i> Punch In
                    </a>
                  <?php elseif ($attendance && empty($attendance['punch_out_time'])): ?>
                    <span class="status-badge status-white me-2" style="font-size:9px;">
                      <i class="bi bi-check-circle-fill"></i>
                      In: <?php echo e(safeTimeOnly($attendance['punch_in_time'] ?? '')); ?>
                    </span>
                    <a href="punch-action.php?action=out" class="punch-btn-compact">
                      <i class="bi bi-box-arrow-right"></i> Punch Out
                    </a>
                  <?php else: ?>
                    <span class="status-badge status-white me-2" style="font-size:9px;">
                      <i class="bi bi-check-circle-fill"></i> Done
                    </span>
                    <span class="status-badge status-white" style="font-size:9px;">
                      <i class="bi bi-clock-fill"></i> <?php echo e($attendance['total_hours'] ?? '0'); ?>h
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Stats -->
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-ic blue" ><i class="bi bi-calendar-check"></i></div>
              <div>
                <div class="stat-label" >Days</div>
                <div class="stat-value" ><?php echo (int)$days_present; ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-ic green" ><i class="bi bi-clock-history"></i></div>
              <div>
                <div class="stat-label" >Hours</div>
                <div class="stat-value" ><?php echo e($total_hours_month); ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-ic yellow" ><i class="bi bi-stopwatch"></i></div>
              <div>
                <div class="stat-label" >Avg</div>
                <div class="stat-value" ><?php echo e($avg_hours); ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-ic red" ><i class="bi bi-trophy"></i></div>
              <div>
                <div class="stat-label" >Streak</div>
                <div class="stat-value" ><?php echo (int)$streak; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent History -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">Recent Attendance</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>
          <!-- Recent Attendance Table -->
          <div class="compact-table-wrap">
              <table id="historyTable" class="table compact-table align-middle mb-0">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Punch In</th>
                    <th>Punch Out</th>
                    <th>Hours</th>
                    <th>Location</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_history as $hist): ?>
                  <?php
                    $status_class = 'status-green';
                    $status_text = 'On Time';
                    if (!empty($hist['punch_in_time']) && strtotime($hist['punch_in_time']) > strtotime(date('Y-m-d') . ' 09:15:00')) {
                      $status_class = 'status-yellow';
                      $status_text = 'Late';
                    }
                  ?>
                  <tr>
                    <td data-label="Date"><div class="proj-title"><?php echo e(date('d M Y', strtotime($hist['attendance_date']))); ?></div></td>
                    <td data-label="Punch In"><div class="proj-title"><?php echo e(safeTimeOnly($hist['punch_in_time'] ?? '', '—')); ?></div></td>
                    <td data-label="Punch Out"><div class="proj-title"><?php echo !empty($hist['punch_out_time']) ? e(safeTimeOnly($hist['punch_out_time'])) : '—'; ?></div></td>
                    <td data-label="Hours">
                      <?php if (!empty($hist['total_hours'])): ?>
                        <span class="history-hours"><?php echo e($hist['total_hours']); ?>h</span>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="Location">
                      <?php $atype = (string)($hist['punch_in_type'] ?? ''); ?>
                      <span class="mini-chip">
                        <i class="bi bi-<?php echo ($atype === 'site') ? 'building' : 'briefcase'; ?>"></i>
                        <?php echo e($atype !== '' ? ucfirst($atype) : '—'); ?>
                      </span>
                    </td>
                    <td data-label="Status">
                      <span class="status-badge <?php echo e($status_class); ?>">
                        <?php echo e($status_text); ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($recent_history)): ?>
                  <tr>
                    <td colspan="6">
                      <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        No attendance records found.
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
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
  function updateTime() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', {
      hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true
    });
    const dateStr = now.toLocaleDateString('en-US', {
      weekday:'short', day:'numeric', month:'short', year:'numeric'
    });
    const t = document.getElementById('currentTime');
    const d = document.getElementById('currentDate');
    if (t) t.textContent = timeStr;
    if (d) d.textContent = dateStr;
  }

  document.addEventListener('DOMContentLoaded', updateTime);
  setInterval(updateTime, 1000);
</script>
</body>
</html>

<?php
try {
  if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
  }
} catch (Throwable $e) { }
?>