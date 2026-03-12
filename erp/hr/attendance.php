<?php
// hr/attendance.php - Attendance Management Page
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

function getInitials($name){
  $name = trim((string)$name);
  if ($name === '') return 'U';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return (count($parts) > 1) ? ($first.$last) : $first;
}

// ---------------- FILTERS ----------------
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selectedEmployee = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$selectedStatus = isset($_GET['status']) ? $_GET['status'] : '';

// Month names
$months = [
  1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
  5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
  9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Year range (current year - 2 to current year + 1)
$currentYear = (int)date('Y');
$years = range($currentYear - 2, $currentYear + 1);

// ---------------- FETCH EMPLOYEES FOR DROPDOWN ----------------
$employees = [];
$st = mysqli_prepare($conn, "SELECT id, full_name, employee_code FROM employees WHERE LOWER(employee_status) = 'active' ORDER BY full_name ASC");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    $employees[] = $row;
  }
  mysqli_stmt_close($st);
}

// ---------------- FETCH ATTENDANCE SUMMARY FOR SELECTED MONTH ----------------
$summaryStats = [
  'total_days' => 0,
  'present' => 0,
  'absent' => 0,
  'half_day' => 0,
  'late' => 0,
  'leave' => 0,
  'holiday' => 0
];

$attendanceRecords = [];
$totalEmployees = count($employees);
$presentToday = 0;
$onLeaveToday = 0;
$absentToday = 0;

// Get today's date for quick stats
$today = date('Y-m-d');

// Today's attendance stats
$st = mysqli_prepare($conn, "
  SELECT 
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_today,
    SUM(CASE WHEN status IN ('leave', 'vacation') THEN 1 ELSE 0 END) as leave_today,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_today
  FROM attendance 
  WHERE attendance_date = ?
");
if ($st) {
  mysqli_stmt_bind_param($st, "s", $today);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  $presentToday = (int)($row['present_today'] ?? 0);
  $onLeaveToday = (int)($row['leave_today'] ?? 0);
  $absentToday = (int)($row['absent_today'] ?? 0);
  mysqli_stmt_close($st);
}

// Build query for attendance records
$query = "
  SELECT 
    a.*,
    e.full_name,
    e.employee_code,
    e.department,
    e.designation,
    e.photo,
    s.project_name as site_name,
    o.location_name as office_name
  FROM attendance a
  INNER JOIN employees e ON a.employee_id = e.id
  LEFT JOIN sites s ON a.punch_in_site_id = s.id
  LEFT JOIN office_locations o ON a.punch_in_office_id = o.id
  WHERE MONTH(a.attendance_date) = ? AND YEAR(a.attendance_date) = ?
";

$params = [$selectedMonth, $selectedYear];
$types = "ii";

if ($selectedEmployee > 0) {
  $query .= " AND a.employee_id = ?";
  $params[] = $selectedEmployee;
  $types .= "i";
}

if (!empty($selectedStatus)) {
  $query .= " AND a.status = ?";
  $params[] = $selectedStatus;
  $types .= "s";
}

$query .= " ORDER BY a.attendance_date DESC, e.full_name ASC";

$st = mysqli_prepare($conn, $query);
if ($st) {
  mysqli_stmt_bind_param($st, $types, ...$params);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $attendanceRecords = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// Calculate summary stats from fetched records
foreach ($attendanceRecords as $record) {
  $summaryStats['total_days']++;
  switch ($record['status']) {
    case 'present':
      $summaryStats['present']++;
      break;
    case 'absent':
      $summaryStats['absent']++;
      break;
    case 'half-day':
      $summaryStats['half_day']++;
      break;
    case 'late':
      $summaryStats['late']++;
      break;
    case 'leave':
    case 'vacation':
      $summaryStats['leave']++;
      break;
    case 'holiday':
      $summaryStats['holiday']++;
      break;
  }
}

$loggedName = $_SESSION['employee_name'] ?? 'HR';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Attendance Management - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:100%; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: var(--green); }
    .stat-ic.orange{ background: var(--orange); }
    .stat-ic.red{ background: var(--red); }
    .stat-ic.purple{ background: #8e44ad; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .filter-card{ background: #f9fafb; border-radius: var(--radius); padding:16px; margin-bottom:20px; border:1px solid var(--border); }

    .badge-pill{ border-radius:999px; padding:8px 12px; font-weight:900; font-size:12px; border:1px solid transparent; display:inline-flex; align-items:center; gap:8px; }
    .badge-pill .mini-dot{ width:8px; height:8px; border-radius:50%; background: currentColor; opacity:.9; }
    .ontrack{ color: var(--green); background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.18); }
    .atrisk{ color: var(--red); background: rgba(235,87,87,.12); border-color: rgba(235,87,87,.18); }
    .delayed{ color:#b7791f; background: rgba(242,201,76,.20); border-color: rgba(242,201,76,.28); }

    .employee-avatar-sm{ width:36px; height:36px; border-radius:50%; background: linear-gradient(135deg, var(--yellow), #ffd66b);
      display:grid; place-items:center; font-weight:900; color:#1f2937; font-size:14px; }
    .employee-avatar-sm img{ width:100%; height:100%; border-radius:50%; object-fit:cover; }

    .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; }
    .table td{ vertical-align:middle; border-color: var(--border); font-weight:650; color:#374151; padding-top:12px; padding-bottom:12px; }

    .action-btn{ width:32px; height:32px; border-radius:8px; border:1px solid var(--border); background:#fff; 
      display:inline-flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; }
    .action-btn:hover{ background:#f3f4f6; color:#374151; }

    .punch-detail{ font-size:12px; color:#6b7280; margin:2px 0; }
    .punch-detail i{ font-size:11px; margin-right:4px; }

    .summary-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:20px; }
    .summary-item{ background:#f9fafb; border-radius:12px; padding:12px; text-align:center; border:1px solid var(--border); }
    .summary-value{ font-size:28px; font-weight:900; line-height:1; color:#1f2937; }
    .summary-label{ font-size:12px; font-weight:700; color:#6b7280; margin-top:4px; }

    @media (max-width: 767.98px){
      .stat-value{ font-size:24px; }
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
            <h1 class="h3 mb-0" style="font-weight:900;">Attendance Management</h1>
            <div>
              <a href="attendance-export.php?month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>" class="btn btn-outline-secondary me-2" style="font-weight:800;">
                <i class="bi bi-download"></i> Export
              </a>
              <a href="attendance-regularization.php" class="btn btn-primary" style="font-weight:800;">
                <i class="bi bi-clock-history"></i> Regularization
              </a>
            </div>
          </div>

          <!-- Today's Stats -->
          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-people-fill"></i></div>
                <div>
                  <div class="stat-label">Total Employees</div>
                  <div class="stat-value"><?php echo (int)$totalEmployees; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                  <div class="stat-label">Present Today</div>
                  <div class="stat-value"><?php echo (int)$presentToday; ?></div>
                  <div class="activity-sub"><?php echo safeDate($today); ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-calendar2-x"></i></div>
                <div>
                  <div class="stat-label">On Leave</div>
                  <div class="stat-value"><?php echo (int)$onLeaveToday; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-x-circle-fill"></i></div>
                <div>
                  <div class="stat-label">Absent Today</div>
                  <div class="stat-value"><?php echo (int)$absentToday; ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Filter Card -->
          <div class="filter-card">
            <form method="GET" action="" class="row g-3 align-items-end">
              <div class="col-12 col-md-3">
                <label class="form-label fw-bold">Month</label>
                <select name="month" class="form-select">
                  <?php foreach ($months as $num => $name): ?>
                    <option value="<?php echo $num; ?>" <?php echo $selectedMonth == $num ? 'selected' : ''; ?>>
                      <?php echo $name; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 col-md-2">
                <label class="form-label fw-bold">Year</label>
                <select name="year" class="form-select">
                  <?php foreach ($years as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo $selectedYear == $year ? 'selected' : ''; ?>>
                      <?php echo $year; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label fw-bold">Employee</label>
                <select name="employee_id" class="form-select select2">
                  <option value="0">All Employees</option>
                  <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $selectedEmployee == $emp['id'] ? 'selected' : ''; ?>>
                      <?php echo e($emp['full_name']); ?> (<?php echo e($emp['employee_code']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 col-md-2">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select">
                  <option value="">All Status</option>
                  <option value="present" <?php echo $selectedStatus == 'present' ? 'selected' : ''; ?>>Present</option>
                  <option value="absent" <?php echo $selectedStatus == 'absent' ? 'selected' : ''; ?>>Absent</option>
                  <option value="half-day" <?php echo $selectedStatus == 'half-day' ? 'selected' : ''; ?>>Half Day</option>
                  <option value="late" <?php echo $selectedStatus == 'late' ? 'selected' : ''; ?>>Late</option>
                  <option value="leave" <?php echo $selectedStatus == 'leave' ? 'selected' : ''; ?>>Leave</option>
                  <option value="holiday" <?php echo $selectedStatus == 'holiday' ? 'selected' : ''; ?>>Holiday</option>
                </select>
              </div>

              <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100" style="font-weight:800;">
                  <i class="bi bi-funnel"></i> Apply Filters
                </button>
              </div>
            </form>
          </div>

          <!-- Monthly Summary -->
          <?php if ($summaryStats['total_days'] > 0): ?>
          <div class="summary-grid">
            <div class="summary-item">
              <div class="summary-value"><?php echo $summaryStats['present']; ?></div>
              <div class="summary-label">Present</div>
            </div>
            <div class="summary-item">
              <div class="summary-value"><?php echo $summaryStats['absent']; ?></div>
              <div class="summary-label">Absent</div>
            </div>
            <div class="summary-item">
              <div class="summary-value"><?php echo $summaryStats['half_day']; ?></div>
              <div class="summary-label">Half Days</div>
            </div>
            <div class="summary-item">
              <div class="summary-value"><?php echo $summaryStats['late']; ?></div>
              <div class="summary-label">Late</div>
            </div>
            <div class="summary-item">
              <div class="summary-value"><?php echo $summaryStats['leave']; ?></div>
              <div class="summary-label">Leaves</div>
            </div>
            <div class="summary-item">
              <div class="summary-value"><?php echo $summaryStats['holiday']; ?></div>
              <div class="summary-label">Holidays</div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Attendance Records -->
          <div class="panel">
            <div class="panel-header">
              <h3 class="panel-title">
                Attendance Records - <?php echo $months[$selectedMonth]; ?> <?php echo $selectedYear; ?>
                <?php if ($selectedEmployee > 0): ?>
                  <?php 
                    $empName = '';
                    foreach ($employees as $emp) {
                      if ($emp['id'] == $selectedEmployee) {
                        $empName = $emp['full_name'];
                        break;
                      }
                    }
                  ?>
                  <span class="text-muted" style="font-size:14px;">(<?php echo e($empName); ?>)</span>
                <?php endif; ?>
              </h3>
              <span class="badge bg-secondary"><?php echo count($attendanceRecords); ?> records</span>
            </div>

            <div class="table-responsive">
              <table class="table align-middle" id="attendanceTable">
                <thead>
                  <tr>
                    <th style="min-width:200px;">Employee</th>
                    <th style="min-width:120px;">Date</th>
                    <th style="min-width:150px;">Punch In</th>
                    <th style="min-width:150px;">Punch Out</th>
                    <th style="min-width:80px;">Hours</th>
                    <th style="min-width:120px;">Status</th>
                    <th style="min-width:100px;">Location</th>
                    <th style="width:60px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($attendanceRecords)): ?>
                    <tr>
                      <td colspan="8" class="text-center text-muted py-4" style="font-weight:800;">
                        No attendance records found for the selected period.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($attendanceRecords as $record): ?>
                      <?php 
                        [$statusLabel, $statusClass] = statusBadgeClass($record['status'] ?? '');
                        $punchInTime = $record['punch_in_time'] ? date('h:i A', strtotime($record['punch_in_time'])) : '—';
                        $punchOutTime = $record['punch_out_time'] ? date('h:i A', strtotime($record['punch_out_time'])) : '—';
                        
                        $location = '';
                        if ($record['punch_in_type'] === 'site' && !empty($record['site_name'])) {
                          $location = '<i class="bi bi-building"></i> ' . e($record['site_name']);
                        } elseif ($record['punch_in_type'] === 'office' && !empty($record['office_name'])) {
                          $location = '<i class="bi bi-briefcase"></i> ' . e($record['office_name']);
                        } elseif ($record['punch_in_type'] === 'remote') {
                          $location = '<i class="bi bi-house"></i> Remote';
                        } else {
                          $location = '<i class="bi bi-geo-alt"></i> ' . e($record['punch_in_location'] ?? '—');
                        }
                        
                        $avatar = '';
                        if (!empty($record['photo'])) {
                          $avatar = '<img src="../admin/' . e($record['photo']) . '" alt="' . e($record['full_name']) . '">';
                        } else {
                          $avatar = getInitials($record['full_name'] ?? '');
                        }
                      ?>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <div class="employee-avatar-sm">
                              <?php if (!empty($record['photo'])): ?>
                                <img src="../admin/<?php echo e($record['photo']); ?>" alt="<?php echo e($record['full_name']); ?>">
                              <?php else: ?>
                                <?php echo getInitials($record['full_name'] ?? ''); ?>
                              <?php endif; ?>
                            </div>
                            <div>
                              <div style="font-weight:900;"><?php echo e($record['full_name'] ?? ''); ?></div>
                              <div class="activity-sub"><?php echo e($record['employee_code'] ?? ''); ?> • <?php echo e($record['department'] ?? ''); ?></div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <div style="font-weight:800;"><?php echo safeDate($record['attendance_date'] ?? ''); ?></div>
                        </td>
                        <td>
                          <div style="font-weight:800;"><?php echo $punchInTime; ?></div>
                          <?php if ($record['punch_in_time'] && $record['late_minutes'] > 0): ?>
                            <div class="punch-detail"><span class="text-danger">Late by <?php echo $record['late_minutes']; ?> min</span></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div style="font-weight:800;"><?php echo $punchOutTime; ?></div>
                          <?php if ($record['punch_out_time'] && $record['early_exit_minutes'] > 0): ?>
                            <div class="punch-detail"><span class="text-warning">Early by <?php echo $record['early_exit_minutes']; ?> min</span></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($record['total_hours'] > 0): ?>
                            <span style="font-weight:800;"><?php echo number_format($record['total_hours'], 2); ?> hrs</span>
                          <?php else: ?>
                            <span class="text-muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="badge-pill <?php echo e($statusClass); ?>">
                            <span class="mini-dot"></span> <?php echo e($statusLabel); ?>
                          </span>
                        </td>
                        <td>
                          <div class="punch-detail"><?php echo $location; ?></div>
                        </td>
                        <td>
                          <a href="view-attendance.php?id=<?php echo (int)$record['id']; ?>" class="action-btn" title="View Details">
                            <i class="bi bi-eye"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </div>

      <?php include 'includes/footer.php'; ?>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="assets/js/sidebar-toggle.js"></script>

  <script>
    $(document).ready(function() {
      // Initialize Select2
      $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Select employee'
      });

      // Initialize DataTable
      <?php if (!empty($attendanceRecords)): ?>
      $('#attendanceTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']], // Sort by date descending
        language: {
          search: "Search records:",
          lengthMenu: "Show _MENU_ entries per page",
          info: "Showing _START_ to _END_ of _TOTAL_ records",
          infoEmpty: "No records available",
          infoFiltered: "(filtered from _MAX_ total records)"
        },
        columnDefs: [
          { orderable: false, targets: [7] } // Disable sorting on action column
        ]
      });
      <?php endif; ?>

      // Auto-submit form when month/year/employee/status changes? 
      // We'll keep the submit button for clarity, but you could add:
      // $('select[name="month"], select[name="year"], select[name="employee_id"], select[name="status"]').change(function() {
      //   $(this).closest('form').submit();
      // });
    });
  </script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
  mysqli_close($conn);
}
?>