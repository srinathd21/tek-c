<?php
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

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

// Today's attendance
$att_stmt = mysqli_prepare($conn, "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
mysqli_stmt_bind_param($att_stmt, "is", $current_employee_id, $today);
mysqli_stmt_execute($att_stmt);
$att_res = mysqli_stmt_get_result($att_stmt);
$attendance = mysqli_fetch_assoc($att_res);
mysqli_stmt_close($att_stmt);

// Assigned sites count + data
$sites_query = "
  SELECT s.*
  FROM sites s
  JOIN site_project_engineers spe ON s.id = spe.site_id
  WHERE spe.employee_id = ? AND s.deleted_at IS NULL
";
$sites_stmt = mysqli_prepare($conn, $sites_query);
mysqli_stmt_bind_param($sites_stmt, "i", $current_employee_id);
mysqli_stmt_execute($sites_stmt);
$sites_res = mysqli_stmt_get_result($sites_stmt);
$assigned_sites = mysqli_fetch_all($sites_res, MYSQLI_ASSOC);
mysqli_stmt_close($sites_stmt);

// Can punch office?
$can_punch_office = in_array(strtolower(trim((string)$employee['designation'])), [
  'manager', 'team lead', 'director', 'vice president', 'general manager'
], true);

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
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:1000; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-label{ color:#4b5563; font-weight:800; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:1000; line-height:1; margin-top:2px; }

    .table-responsive { overflow-x: hidden !important; }
    table.dataTable { width:100% !important; }
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:900;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: normal !important;
    }
    .table td{
      vertical-align: top; border-color: var(--border);
      font-weight:800; color:#111827;
      padding: 10px 10px !important;
      white-space: normal !important;
      word-break: break-word;
    }

    .btn-action{
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 8px 12px;
      color: #374151;
      font-size: 13px;
      font-weight: 1000;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
    }
    .btn-action:hover { background: #f9fafb; color: var(--blue); }

    .proj-title{ font-weight:1000; font-size:13px; color:#111827; margin-bottom:2px; line-height:1.2; }
    .proj-sub{ font-size:11px; color:#6b7280; font-weight:800; line-height:1.25; }

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
    .status-white{ background: rgba(255,255,255,0.2); color:white; border:1px solid rgba(255,255,255,0.3); }

    .alert { border-radius: 16px; border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

    .punch-card-compact{
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border-radius: 16px;
      padding: 16px 20px;
      color: white;
      margin-bottom: 20px;
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
    }
    .punch-time-compact { font-size: 28px; font-weight: 1000; line-height: 1.2; font-family: 'Courier New', monospace; letter-spacing: 1px; }
    .punch-date-compact { font-size: 12px; opacity: 0.9; font-weight: 700; }
    .punch-greeting-compact { font-size: 14px; font-weight: 800; margin-bottom: 5px; }

    .punch-btn-compact{
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.4);
      color: white;
      padding: 9px 14px;
      border-radius: 999px;
      font-weight: 900;
      font-size: 12px;
      backdrop-filter: blur(5px);
      transition: all 0.3s;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .punch-btn-compact:hover { background: white; color: #667eea; border-color: white; }

    .employee-avatar-small{
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 1000;
      font-size: 20px;
      overflow: hidden;
      flex: 0 0 auto;
    }
    .employee-avatar-small img{ width:100%; height:100%; object-fit:cover; }

    .history-hours{
      background: #f3f4f6;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 900;
      color: #111827;
    }

    /* ✅ Mobile cards for Recent History */
    .r-card{
      border:1px solid var(--border);
      border-radius:16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding:12px;
    }
    .r-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .r-kv{ margin-top:10px; display:grid; gap:8px; }
    .r-row{ display:flex; gap:10px; align-items:flex-start; }
    .r-key{ flex:0 0 90px; color:#6b7280; font-weight:1000; font-size:12px; }
    .r-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; }
    .r-badges{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .r-chip{
      border:1px solid var(--border);
      background:#fff;
      border-radius:999px;
      padding:4px 10px;
      font-weight:900;
      font-size:11px;
      color:#111827;
      display:inline-flex;
      align-items:center;
      gap:6px;
    }

    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
    }
    @media (max-width: 991.98px){
      .main{ margin-left:0 !important; width:100% !important; max-width:100% !important; }
      .sidebar{ position:fixed !important; transform: translateX(-100%); z-index:1040 !important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
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

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Punch In/Out</h1>
            <p class="text-muted mb-0">Mark your daily attendance with location validation</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="my-attendance.php" class="btn-action">
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
            <div class="panel d-flex align-items-center gap-2 p-2">
              <div class="employee-avatar-small">
                <?php
                if (!empty($employee['photo'])) {
                  echo '<img src="../admin/' . e($employee['photo']) . '" alt="' . e($employee['full_name']) . '">';
                } else {
                  echo strtoupper(substr((string)$employee['full_name'], 0, 1));
                }
                ?>
              </div>
              <div>
                <h5 class="fw-bold mb-0 proj-title"><?php echo e($employee['full_name']); ?></h5>
                <p class="proj-sub mb-0">
                  <span class="badge bg-light text-dark me-1"><?php echo e($employee['employee_code'] ?? ''); ?></span>
                  <span class="badge bg-primary"><?php echo e($employee['designation'] ?? ''); ?></span>
                </p>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="panel d-flex align-items-center justify-content-between p-2">
              <div>
                <div class="proj-sub"><i class="bi bi-geo-alt"></i> Assigned Sites</div>
                <div class="fw-bold" style="font-size:20px;"><?php echo (int)count($assigned_sites); ?></div>
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
            <div class="stat-card p-2" style="height:70px;">
              <div class="stat-ic blue" style="width:36px;height:36px;font-size:16px;"><i class="bi bi-calendar-check"></i></div>
              <div>
                <div class="stat-label" style="font-size:11px;">Days</div>
                <div class="stat-value" style="font-size:20px;"><?php echo (int)$days_present; ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card p-2" style="height:70px;">
              <div class="stat-ic green" style="width:36px;height:36px;font-size:16px;"><i class="bi bi-clock-history"></i></div>
              <div>
                <div class="stat-label" style="font-size:11px;">Hours</div>
                <div class="stat-value" style="font-size:20px;"><?php echo e($total_hours_month); ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card p-2" style="height:70px;">
              <div class="stat-ic yellow" style="width:36px;height:36px;font-size:16px;"><i class="bi bi-stopwatch"></i></div>
              <div>
                <div class="stat-label" style="font-size:11px;">Avg</div>
                <div class="stat-value" style="font-size:20px;"><?php echo e($avg_hours); ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card p-2" style="height:70px;">
              <div class="stat-ic red" style="width:36px;height:36px;font-size:16px;"><i class="bi bi-trophy"></i></div>
              <div>
                <div class="stat-label" style="font-size:11px;">Streak</div>
                <div class="stat-value" style="font-size:20px;"><?php echo (int)$streak; ?></div>
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

          <!-- ✅ MOBILE: Cards -->
          <div class="d-block d-md-none">
            <div class="d-grid gap-3">
              <?php if (empty($recent_history)): ?>
                <div class="text-muted fw-bold">No attendance records found.</div>
              <?php else: ?>
                <?php foreach ($recent_history as $hist): ?>
                  <?php
                    $pIn  = safeTimeOnly($hist['punch_in_time'] ?? '', '—');
                    $pOut = !empty($hist['punch_out_time']) ? safeTimeOnly($hist['punch_out_time'], '—') : '—';
                    $hrs  = $hist['total_hours'] ?? '';
                    $atype = (string)($hist['punch_in_type'] ?? '');
                    $locIcon = ($atype === 'site') ? 'building' : 'briefcase';

                    $status_class = 'status-green';
                    $status_text  = 'On Time';
                    // NOTE: original logic compares to 09:15:00; if your DB stores DATETIME, this is still okay.
                    if (!empty($hist['punch_in_time']) && strtotime($hist['punch_in_time']) > strtotime(date('Y-m-d') . ' 09:15:00')) {
                      $status_class = 'status-yellow';
                      $status_text  = 'Late';
                    }
                  ?>
                  <div class="r-card">
                    <div class="r-top">
                      <div>
                        <div class="proj-title"><?php echo e(date('d M Y', strtotime($hist['attendance_date']))); ?></div>
                        <div class="proj-sub">Punch In: <?php echo e($pIn); ?> • Punch Out: <?php echo e($pOut); ?></div>
                      </div>
                      <span class="status-badge <?php echo e($status_class); ?>" style="font-size:9px;padding:3px 8px;">
                        <?php echo e($status_text); ?>
                      </span>
                    </div>

                    <div class="r-badges">
                      <span class="r-chip">
                        <i class="bi bi-<?php echo e($locIcon); ?>"></i>
                        <?php echo e(ucfirst($atype !== '' ? $atype : '—')); ?>
                      </span>

                      <?php if ($hrs !== ''): ?>
                        <span class="r-chip">
                          <i class="bi bi-clock"></i> <?php echo e($hrs); ?>h
                        </span>
                      <?php else: ?>
                        <span class="r-chip"><i class="bi bi-clock"></i> —</span>
                      <?php endif; ?>
                    </div>

                    <div class="r-kv">
                      <div class="r-row">
                        <div class="r-key">Status</div>
                        <div class="r-val"><?php echo e($hist['status'] ?? '—'); ?></div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- ✅ DESKTOP: DataTable -->
          <div class="d-none d-md-block">
            <div class="table-responsive">
              <table id="historyTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
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
                    <td><div class="proj-title"><?php echo e(date('d M', strtotime($hist['attendance_date']))); ?></div></td>
                    <td><div class="proj-title"><?php echo e(safeTimeOnly($hist['punch_in_time'] ?? '', '—')); ?></div></td>
                    <td><div class="proj-title"><?php echo !empty($hist['punch_out_time']) ? e(safeTimeOnly($hist['punch_out_time'])) : '—'; ?></div></td>
                    <td>
                      <?php if (!empty($hist['total_hours'])): ?>
                        <span class="history-hours"><?php echo e($hist['total_hours']); ?>h</span>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                      <?php $atype = (string)($hist['punch_in_type'] ?? ''); ?>
                      <span class="badge bg-light text-dark" style="font-size:10px;">
                        <i class="bi bi-<?php echo ($atype === 'site') ? 'building' : 'briefcase'; ?>"></i>
                        <?php echo e($atype !== '' ? ucfirst($atype) : '—'); ?>
                      </span>
                    </td>
                    <td>
                      <span class="status-badge <?php echo e($status_class); ?>" style="padding:2px 8px;font-size:9px;">
                        <?php echo e($status_text); ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
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
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
  // Init DataTable ONLY on md+ screens (table is hidden on mobile)
  function initHistoryTable() {
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    const tbl = document.getElementById('historyTable');
    if (!tbl) return;

    if (isDesktop) {
      if (!$.fn.DataTable.isDataTable('#historyTable')) {
        $('#historyTable').DataTable({
          responsive: true,
          autoWidth: false,
          pageLength: 5,
          lengthMenu: [[5, 10, 25, -1], [5, 10, 25, 'All']],
          order: [[0, 'desc']],
          language: {
            zeroRecords: "No attendance records found",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "No entries to show",
            lengthMenu: "Show _MENU_",
            search: "Search:"
          }
        });
      }
    } else {
      if ($.fn.DataTable.isDataTable('#historyTable')) {
        $('#historyTable').DataTable().destroy();
      }
    }
  }

  $(function () {
    initHistoryTable();
    window.addEventListener('resize', initHistoryTable);
  });

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