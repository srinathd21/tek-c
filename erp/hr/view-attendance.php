<?php
// hr/view-attendance.php - View Detailed Attendance Record
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH (HR) ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$designation = trim((string)($_SESSION['designation'] ?? ''));
$department  = trim((string)($_SESSION['department'] ?? ''));

$isHr = (strtolower($designation) === 'hr') || (strtolower($department) === 'hr');
if (!$isHr) {
  $fallback = $_SESSION['role_redirect'] ?? '../login.php';
  header("Location: " . $fallback);
  exit;
}

// ---------------- GET ATTENDANCE ID ----------------
$attendanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attendanceId <= 0) {
  header("Location: attendance.php?error=invalid");
  exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateTime($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function formatTime($v, $dash='—'){
  if (!$v || $v === '00:00:00') return $dash;
  return date('h:i A', strtotime($v));
}

function formatSecondsToTime($seconds) {
  $hours = floor($seconds / 3600);
  $minutes = floor(($seconds % 3600) / 60);
  $secs = $seconds % 60;
  return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
}

function statusBadgeClass($status){
  $s = strtolower(trim((string)$status));
  if ($s === 'present') return ['Present', 'ontrack'];
  if ($s === 'absent') return ['Absent', 'atrisk'];
  if ($s === 'half-day') return ['Half Day', 'delayed'];
  if ($s === 'late') return ['Late', 'atrisk'];
  if ($s === 'holiday') return ['Holiday', 'ontrack'];
  if ($s === 'leave') return ['On Leave', 'delayed'];
  if ($s === 'vacation') return ['Vacation', 'ontrack'];
  return [$status ?? 'Unknown', 'atrisk'];
}

// ---------------- FETCH ATTENDANCE DETAILS ----------------
$query = "
  SELECT 
    a.*,
    e.full_name,
    e.employee_code,
    e.department,
    e.designation,
    e.photo,
    e.date_of_joining,
    e.mobile_number,
    e.email,
    e.reporting_manager,
    s_in.id as site_in_id,
    s_in.project_name as site_in_name,
    s_in.project_code as site_in_code,
    s_in.project_location as site_in_location,
    s_out.id as site_out_id,
    s_out.project_name as site_out_name,
    s_out.project_code as site_out_code,
    s_out.project_location as site_out_location,
    o_in.id as office_in_id,
    o_in.location_name as office_in_name,
    o_in.address as office_in_address,
    o_out.id as office_out_id,
    o_out.location_name as office_out_name,
    o_out.address as office_out_address,
    vac_approved.full_name as approved_by_name,
    man.full_name as manager_name
  FROM attendance a
  INNER JOIN employees e ON a.employee_id = e.id
  LEFT JOIN sites s_in ON a.punch_in_site_id = s_in.id
  LEFT JOIN sites s_out ON a.punch_out_site_id = s_out.id
  LEFT JOIN office_locations o_in ON a.punch_in_office_id = o_in.id
  LEFT JOIN office_locations o_out ON a.punch_out_office_id = o_out.id
  LEFT JOIN employees vac_approved ON a.vacation_approved_by = vac_approved.id
  LEFT JOIN employees man ON e.reporting_to = man.id
  WHERE a.id = ?
";

$attendance = null;
$st = mysqli_prepare($conn, $query);
if ($st) {
  mysqli_stmt_bind_param($st, "i", $attendanceId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $attendance = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}

if (!$attendance) {
  header("Location: attendance.php?error=notfound");
  exit;
}

// ---------------- CALCULATE ADDITIONAL METRICS ----------------
$punchIn = $attendance['punch_in_time'] ? strtotime($attendance['punch_in_time']) : null;
$punchOut = $attendance['punch_out_time'] ? strtotime($attendance['punch_out_time']) : null;

$duration = null;
if ($punchIn && $punchOut) {
  $duration = $punchOut - $punchIn;
}

$expectedWorkHours = 9 * 3600; // 9 hours expected (configurable)
$overtime = null;
$shortfall = null;

if ($duration) {
  if ($duration > $expectedWorkHours) {
    $overtime = $duration - $expectedWorkHours;
  } else {
    $shortfall = $expectedWorkHours - $duration;
  }
}

// ---------------- FETCH ATTENDANCE HISTORY FOR THIS EMPLOYEE ----------------
$historyQuery = "
  SELECT 
    id,
    attendance_date,
    punch_in_time,
    punch_out_time,
    total_hours,
    status
  FROM attendance
  WHERE employee_id = ? AND id != ?
  ORDER BY attendance_date DESC
  LIMIT 5
";

$attendanceHistory = [];
$st = mysqli_prepare($conn, $historyQuery);
if ($st) {
  $empId = (int)$attendance['employee_id'];
  mysqli_stmt_bind_param($st, "ii", $empId, $attendanceId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $attendanceHistory = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

$loggedName = $_SESSION['employee_name'] ?? 'HR';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>View Attendance - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:20px; margin-bottom:20px; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }

    .employee-header{ display:flex; align-items:center; gap:20px; padding:20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: var(--radius); color:white; margin-bottom:20px; }
    .employee-avatar-lg{ width:80px; height:80px; border-radius:50%; background: rgba(255,255,255,0.2); border:3px solid rgba(255,255,255,0.5); 
      display:grid; place-items:center; font-weight:900; font-size:32px; color:white; overflow:hidden; }
    .employee-avatar-lg img{ width:100%; height:100%; object-fit:cover; }

    .info-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:16px; margin-bottom:20px; }
    .info-card{ background:#f9fafb; border-radius:12px; padding:16px; border:1px solid var(--border); }
    .info-label{ font-size:12px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; }
    .info-value{ font-size:18px; font-weight:900; color:#1f2937; }
    .info-sub{ font-size:13px; font-weight:600; color:#6b7280; margin-top:4px; }

    .detail-row{ display:flex; padding:12px 0; border-bottom:1px solid var(--border); }
    .detail-row:last-child{ border-bottom:0; }
    .detail-label{ width:140px; font-weight:800; color:#4b5563; }
    .detail-value{ flex:1; font-weight:650; color:#374151; }

    .badge-pill{ border-radius:999px; padding:8px 16px; font-weight:900; font-size:14px; border:1px solid transparent; display:inline-flex; align-items:center; gap:8px; }
    .badge-pill .mini-dot{ width:8px; height:8px; border-radius:50%; background: currentColor; opacity:.9; }
    .ontrack{ color: var(--green); background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.18); }
    .atrisk{ color: var(--red); background: rgba(235,87,87,.12); border-color: rgba(235,87,87,.18); }
    .delayed{ color:#b7791f; background: rgba(242,201,76,.20); border-color: rgba(242,201,76,.28); }

    .timeline{ position:relative; padding-left:30px; }
    .timeline::before{ content:''; position:absolute; left:8px; top:8px; bottom:8px; width:2px; background: var(--border); }
    .timeline-item{ position:relative; padding-bottom:20px; }
    .timeline-item:last-child{ padding-bottom:0; }
    .timeline-dot{ position:absolute; left:-30px; top:4px; width:16px; height:16px; border-radius:50%; background: var(--blue); border:2px solid white; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
    .timeline-time{ font-weight:800; color:#374151; margin-bottom:2px; }
    .timeline-location{ font-size:13px; color:#6b7280; }
    .timeline-location i{ margin-right:4px; }

    .photo-preview{ max-width:100%; max-height:200px; border-radius:8px; border:1px solid var(--border); }

    .action-btn{ padding:8px 16px; border-radius:10px; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
    .action-btn-primary{ background: var(--blue); color:white; border:1px solid var(--blue); }
    .action-btn-outline{ background: white; color:#374151; border:1px solid var(--border); }

    .history-table td{ padding:12px 8px; }

    @media print {
      .no-print, .sidebar, .topbar, .sidebar-footer, footer { display: none !important; }
      .main { margin-left: 0 !important; }
      .content-scroll { padding: 0 !important; }
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
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <a href="attendance.php" class="text-decoration-none text-muted me-2">
                <i class="bi bi-arrow-left"></i> Back to Attendance
              </a>
              <h1 class="h3 mb-0 mt-2" style="font-weight:900;">Attendance Details</h1>
            </div>
            <div class="no-print">
              <button onclick="window.print()" class="btn btn-outline-secondary me-2" style="font-weight:800;">
                <i class="bi bi-printer"></i> Print
              </button>
              <a href="attendance-edit.php?id=<?php echo $attendanceId; ?>" class="btn btn-primary" style="font-weight:800;">
                <i class="bi bi-pencil-square"></i> Edit
              </a>
            </div>
          </div>

          <!-- Employee Header -->
          <div class="employee-header">
            <div class="employee-avatar-lg">
              <?php if (!empty($attendance['photo'])): ?>
                <img src="../admin/<?php echo e($attendance['photo']); ?>" alt="<?php echo e($attendance['full_name']); ?>">
              <?php else: ?>
                <?php 
                  $name = $attendance['full_name'] ?? 'U';
                  $parts = preg_split('/\s+/', $name);
                  $initial = strtoupper(substr($parts[0] ?? 'U', 0, 1));
                  if (count($parts) > 1) $initial .= strtoupper(substr(end($parts), 0, 1));
                  echo e($initial);
                ?>
              <?php endif; ?>
            </div>
            <div>
              <h2 class="h3 mb-1" style="font-weight:900;"><?php echo e($attendance['full_name'] ?? ''); ?></h2>
              <p class="mb-0 opacity-75">
                <span class="badge bg-white text-dark me-2"><?php echo e($attendance['employee_code'] ?? ''); ?></span>
                <?php echo e($attendance['designation'] ?? ''); ?> • <?php echo e($attendance['department'] ?? ''); ?>
              </p>
              <p class="mb-0 mt-2">
                <i class="bi bi-calendar3"></i> Joined: <?php echo safeDate($attendance['date_of_joining'] ?? ''); ?>
                <?php if (!empty($attendance['manager_name'])): ?>
                  • Reports to: <?php echo e($attendance['manager_name']); ?>
                <?php endif; ?>
              </p>
            </div>
            <div class="ms-auto">
              <span class="badge-pill <?php 
                [$label, $class] = statusBadgeClass($attendance['status'] ?? '');
                echo e($class);
              ?>">
                <span class="mini-dot"></span> <?php echo e($label); ?>
              </span>
            </div>
          </div>

          <!-- Quick Stats Grid -->
          <div class="info-grid">
            <div class="info-card">
              <div class="info-label">Attendance Date</div>
              <div class="info-value"><?php echo safeDate($attendance['attendance_date'] ?? ''); ?></div>
              <div class="info-sub">
                <?php 
                  $dayOfWeek = date('l', strtotime($attendance['attendance_date']));
                  echo $dayOfWeek;
                ?>
              </div>
            </div>

            <div class="info-card">
              <div class="info-label">Total Hours</div>
              <div class="info-value">
                <?php 
                  if ($attendance['total_hours'] > 0) {
                    echo number_format($attendance['total_hours'], 2) . ' hrs';
                  } else {
                    echo '—';
                  }
                ?>
              </div>
              <?php if ($duration): ?>
                <div class="info-sub"><?php echo formatSecondsToTime($duration); ?> (HH:MM:SS)</div>
              <?php endif; ?>
            </div>

            <div class="info-card">
              <div class="info-label">Working Period</div>
              <div class="info-value">
                <?php 
                  if ($punchIn && $punchOut) {
                    echo date('h:i A', $punchIn) . ' — ' . date('h:i A', $punchOut);
                  } elseif ($punchIn) {
                    echo 'Punched In Only';
                  } else {
                    echo 'No Punches';
                  }
                ?>
              </div>
              <?php if ($overtime): ?>
                <div class="info-sub text-success">Overtime: +<?php echo formatSecondsToTime($overtime); ?></div>
              <?php elseif ($shortfall): ?>
                <div class="info-sub text-warning">Shortfall: -<?php echo formatSecondsToTime($shortfall); ?></div>
              <?php endif; ?>
            </div>

            <div class="info-card">
              <div class="info-label">Penalties</div>
              <div class="info-value">
                <?php if ($attendance['late_minutes'] > 0 || $attendance['early_exit_minutes'] > 0): ?>
                  <?php if ($attendance['late_minutes'] > 0): ?>
                    <span class="text-danger">Late <?php echo $attendance['late_minutes']; ?> min</span>
                  <?php endif; ?>
                  <?php if ($attendance['early_exit_minutes'] > 0): ?>
                    <?php if ($attendance['late_minutes'] > 0): ?> • <?php endif; ?>
                    <span class="text-warning">Early <?php echo $attendance['early_exit_minutes']; ?> min</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-success">No penalties</span>
                <?php endif; ?>
              </div>
              <?php if ($attendance['overtime_minutes'] > 0): ?>
                <div class="info-sub text-success">OT: <?php echo $attendance['overtime_minutes']; ?> min</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="row g-4">
            <!-- Left Column - Punch Details -->
            <div class="col-12 col-lg-6">
              <div class="panel h-100">
                <div class="panel-header">
                  <h3 class="panel-title">
                    <i class="bi bi-clock-history me-2"></i>Punch Timeline
                  </h3>
                </div>

                <div class="timeline">
                  <!-- Punch In -->
                  <div class="timeline-item">
                    <div class="timeline-dot" style="background: var(--green);"></div>
                    <div class="timeline-time">
                      <span class="fw-bold">Punch In</span>
                      <span class="text-muted ms-2"><?php echo safeDateTime($attendance['punch_in_time'] ?? ''); ?></span>
                    </div>
                    <div class="timeline-location">
                      <i class="bi bi-geo-alt"></i> 
                      <?php 
                        if ($attendance['punch_in_type'] === 'site' && !empty($attendance['site_in_name'])) {
                          echo e($attendance['site_in_name']) . ' (Site)';
                          if (!empty($attendance['site_in_location'])) {
                            echo '<br><small>' . e($attendance['site_in_location']) . '</small>';
                          }
                        } elseif ($attendance['punch_in_type'] === 'office' && !empty($attendance['office_in_name'])) {
                          echo e($attendance['office_in_name']) . ' (Office)';
                          if (!empty($attendance['office_in_address'])) {
                            echo '<br><small>' . e($attendance['office_in_address']) . '</small>';
                          }
                        } elseif ($attendance['punch_in_type'] === 'remote') {
                          echo 'Remote Work';
                        } else {
                          echo e($attendance['punch_in_location'] ?? 'Location not recorded');
                        }
                      ?>
                    </div>
                    <?php if (!empty($attendance['punch_in_latitude']) && !empty($attendance['punch_in_longitude'])): ?>
                      <div class="timeline-location mt-1">
                        <i class="bi bi-crosshair"></i> 
                        <?php echo e($attendance['punch_in_latitude']); ?>, <?php echo e($attendance['punch_in_longitude']); ?>
                      </div>
                    <?php endif; ?>
                  </div>

                  <!-- Punch Out -->
                  <?php if (!empty($attendance['punch_out_time'])): ?>
                  <div class="timeline-item">
                    <div class="timeline-dot" style="background: var(--red);"></div>
                    <div class="timeline-time">
                      <span class="fw-bold">Punch Out</span>
                      <span class="text-muted ms-2"><?php echo safeDateTime($attendance['punch_out_time'] ?? ''); ?></span>
                    </div>
                    <div class="timeline-location">
                      <i class="bi bi-geo-alt"></i> 
                      <?php 
                        if ($attendance['punch_out_type'] === 'site' && !empty($attendance['site_out_name'])) {
                          echo e($attendance['site_out_name']) . ' (Site)';
                          if (!empty($attendance['site_out_location'])) {
                            echo '<br><small>' . e($attendance['site_out_location']) . '</small>';
                          }
                        } elseif ($attendance['punch_out_type'] === 'office' && !empty($attendance['office_out_name'])) {
                          echo e($attendance['office_out_name']) . ' (Office)';
                          if (!empty($attendance['office_out_address'])) {
                            echo '<br><small>' . e($attendance['office_out_address']) . '</small>';
                          }
                        } elseif ($attendance['punch_out_type'] === 'remote') {
                          echo 'Remote Work';
                        } else {
                          echo e($attendance['punch_out_location'] ?? 'Location not recorded');
                        }
                      ?>
                    </div>
                    <?php if (!empty($attendance['punch_out_latitude']) && !empty($attendance['punch_out_longitude'])): ?>
                      <div class="timeline-location mt-1">
                        <i class="bi bi-crosshair"></i> 
                        <?php echo e($attendance['punch_out_latitude']); ?>, <?php echo e($attendance['punch_out_longitude']); ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>

                <!-- Punch Photos -->
                <?php if (!empty($attendance['punch_in_photo']) || !empty($attendance['punch_out_photo'])): ?>
                <div class="row g-3 mt-3">
                  <?php if (!empty($attendance['punch_in_photo'])): ?>
                  <div class="col-6">
                    <label class="info-label">Punch In Photo</label>
                    <img src="../<?php echo e($attendance['punch_in_photo']); ?>" class="photo-preview" alt="Punch In">
                  </div>
                  <?php endif; ?>
                  <?php if (!empty($attendance['punch_out_photo'])): ?>
                  <div class="col-6">
                    <label class="info-label">Punch Out Photo</label>
                    <img src="../<?php echo e($attendance['punch_out_photo']); ?>" class="photo-preview" alt="Punch Out">
                  </div>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Right Column - Additional Details -->
            <div class="col-12 col-lg-6">
              <div class="panel h-100">
                <div class="panel-header">
                  <h3 class="panel-title">
                    <i class="bi bi-info-circle me-2"></i>Additional Information
                  </h3>
                </div>

                <div class="detail-row">
                  <div class="detail-label">Employee ID</div>
                  <div class="detail-value"><?php echo e($attendance['employee_code'] ?? '—'); ?></div>
                </div>

                <div class="detail-row">
                  <div class="detail-label">Department</div>
                  <div class="detail-value"><?php echo e($attendance['department'] ?? '—'); ?></div>
                </div>

                <div class="detail-row">
                  <div class="detail-label">Designation</div>
                  <div class="detail-value"><?php echo e($attendance['designation'] ?? '—'); ?></div>
                </div>

                <div class="detail-row">
                  <div class="detail-label">Contact</div>
                  <div class="detail-value">
                    <?php if (!empty($attendance['mobile_number'])): ?>
                      <i class="bi bi-telephone"></i> <?php echo e($attendance['mobile_number']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($attendance['email'])): ?>
                      <i class="bi bi-envelope"></i> <?php echo e($attendance['email']); ?>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="detail-row">
                  <div class="detail-label">Reporting Manager</div>
                  <div class="detail-value"><?php echo e($attendance['reporting_manager'] ?? '—'); ?></div>
                </div>

                <?php if (!empty($attendance['remarks'])): ?>
                <div class="detail-row">
                  <div class="detail-label">Remarks</div>
                  <div class="detail-value"><?php echo e($attendance['remarks']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($attendance['is_vacation']): ?>
                <div class="detail-row">
                  <div class="detail-label">Vacation Details</div>
                  <div class="detail-value">
                    <div>Approved by: <?php echo e($attendance['approved_by_name'] ?? '—'); ?></div>
                    <div>Approved at: <?php echo safeDateTime($attendance['vacation_approved_at'] ?? ''); ?></div>
                    <div>Reason: <?php echo e($attendance['vacation_reason'] ?? '—'); ?></div>
                  </div>
                </div>
                <?php endif; ?>

                <div class="detail-row">
                  <div class="detail-label">Record Created</div>
                  <div class="detail-value"><?php echo safeDateTime($attendance['created_at'] ?? ''); ?></div>
                </div>

                <div class="detail-row">
                  <div class="detail-label">Last Updated</div>
                  <div class="detail-value"><?php echo safeDateTime($attendance['updated_at'] ?? ''); ?></div>
                </div>
              </div>

              <!-- Recent Attendance History -->
              <?php if (!empty($attendanceHistory)): ?>
              <div class="panel mt-4">
                <div class="panel-header">
                  <h3 class="panel-title">
                    <i class="bi bi-clock me-2"></i>Recent Attendance History
                  </h3>
                  <a href="attendance.php?employee_id=<?php echo $attendance['employee_id']; ?>" class="text-decoration-none">View All</a>
                </div>

                <div class="table-responsive">
                  <table class="table table-sm history-table">
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Punch In</th>
                        <th>Punch Out</th>
                        <th>Hours</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($attendanceHistory as $history): ?>
                        <?php [$hLabel, $hClass] = statusBadgeClass($history['status'] ?? ''); ?>
                        <tr>
                          <td><?php echo safeDate($history['attendance_date'] ?? ''); ?></td>
                          <td><?php echo $history['punch_in_time'] ? date('h:i A', strtotime($history['punch_in_time'])) : '—'; ?></td>
                          <td><?php echo $history['punch_out_time'] ? date('h:i A', strtotime($history['punch_out_time'])) : '—'; ?></td>
                          <td><?php echo $history['total_hours'] ? number_format($history['total_hours'], 1) . 'h' : '—'; ?></td>
                          <td><span class="badge-pill <?php echo e($hClass); ?>" style="padding:4px 8px;"><?php echo e($hLabel); ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <?php endif; ?>
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
    // Print functionality
    document.addEventListener('keydown', function(e) {
      if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        window.print();
      }
    });
  </script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
  mysqli_close($conn);
}
?>