<?php
// hr/my-attendance.php - View My Attendance Records
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];
$is_admin = false;

// Get employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$employee) {
    die("Employee not found.");
}

// Check if admin (for potential admin view)
$designation = strtolower(trim($employee['designation'] ?? ''));
$department = strtolower(trim($employee['department'] ?? ''));
$is_admin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director');

// ---------------- DATE FILTERS ----------------
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$view = isset($_GET['view']) ? $_GET['view'] : 'month'; // month, list

// Validate month/year
if ($month < 1 || $month > 12) $month = (int)date('m');
if ($year < 2020 || $year > 2100) $year = (int)date('Y');

// ---------------- FETCH ATTENDANCE RECORDS ----------------
$attendance_records = [];

if ($view === 'month') {
    // Get attendance for the selected month
    $start_date = "$year-$month-01";
    $end_date = date('Y-m-t', strtotime($start_date));
    
    $query = "
        SELECT * FROM attendance 
        WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?
        ORDER BY attendance_date DESC
    ";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "iss", $current_employee_id, $start_date, $end_date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $attendance_records[] = $row;
    }
    mysqli_stmt_close($stmt);
} else {
    // List view - last 30 days
    $query = "
        SELECT * FROM attendance 
        WHERE employee_id = ? 
        ORDER BY attendance_date DESC
        LIMIT 30
    ";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $current_employee_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $attendance_records[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// ---------------- FETCH MONTHLY SUMMARY ----------------
$summary_query = "
    SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN status = 'holiday' THEN 1 ELSE 0 END) as holidays,
        SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) as leaves,
        SUM(total_hours) as total_hours,
        AVG(total_hours) as avg_hours
    FROM attendance 
    WHERE employee_id = ? 
    AND MONTH(attendance_date) = ? 
    AND YEAR(attendance_date) = ?
";

$stmt = mysqli_prepare($conn, $summary_query);
mysqli_stmt_bind_param($stmt, "iii", $current_employee_id, $month, $year);
mysqli_stmt_execute($stmt);
$summary_result = mysqli_stmt_get_result($stmt);
$summary = mysqli_fetch_assoc($summary_result);
mysqli_stmt_close($stmt);

// ---------------- FETCH HOLIDAYS FOR THE MONTH ----------------
$holidays = [];
$holiday_query = "
    SELECT * FROM holidays 
    WHERE MONTH(holiday_date) = ? AND YEAR(holiday_date) = ?
    ORDER BY holiday_date
";
$stmt = mysqli_prepare($conn, $holiday_query);
mysqli_stmt_bind_param($stmt, "ii", $month, $year);
mysqli_stmt_execute($stmt);
$holiday_result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($holiday_result)) {
    $holidays[] = $row;
}
mysqli_stmt_close($stmt);

// ---------------- FETCH LEAVE REQUESTS ----------------
$leave_requests = [];
$leave_query = "
    SELECT * FROM leave_requests 
    WHERE employee_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
";
$stmt = mysqli_prepare($conn, $leave_query);
mysqli_stmt_bind_param($stmt, "i", $current_employee_id);
mysqli_stmt_execute($stmt);
$leave_result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($leave_result)) {
    $leave_requests[] = $row;
}
mysqli_stmt_close($stmt);

// ---------------- HELPERS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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

function getStatusBadge($status) {
    switch($status) {
        case 'present':
            return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Present</span>';
        case 'absent':
            return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Absent</span>';
        case 'half-day':
            return '<span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Half Day</span>';
        case 'late':
            return '<span class="badge bg-info"><i class="bi bi-exclamation-triangle"></i> Late</span>';
        case 'holiday':
            return '<span class="badge bg-primary"><i class="bi bi-calendar-heart"></i> Holiday</span>';
        case 'leave':
            return '<span class="badge bg-secondary"><i class="bi bi-calendar-x"></i> Leave</span>';
        default:
            return '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    }
}

function getPunchTypeIcon($type) {
    switch($type) {
        case 'site':
            return '<i class="bi bi-building text-primary" title="Site"></i>';
        case 'office':
            return '<i class="bi bi-briefcase text-success" title="Office"></i>';
        case 'remote':
            return '<i class="bi bi-house-door text-warning" title="Remote"></i>';
        default:
            return '<i class="bi bi-question-circle text-secondary"></i>';
    }
}

// Month names for dropdown
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Get today's punch status
$today_stmt = mysqli_prepare($conn, "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = CURDATE()");
mysqli_stmt_bind_param($today_stmt, "i", $current_employee_id);
mysqli_stmt_execute($today_stmt);
$today_result = mysqli_stmt_get_result($today_stmt);
$today_attendance = mysqli_fetch_assoc($today_result);
mysqli_stmt_close($today_stmt);

$loggedName = $_SESSION['employee_name'] ?? $employee['full_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>My Attendance - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px; }
        .panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 8px 24px rgba(17,24,39,.06); padding:20px; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }

        .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
            padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
        .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
        .stat-ic.blue{ background: var(--blue); }
        .stat-ic.green{ background: var(--green); }
        .stat-ic.orange{ background: var(--orange); }
        .stat-ic.purple{ background: #8e44ad; }
        .stat-ic.red{ background: #e74c3c; }
        .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
        .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

        .filter-card{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:20px; }

        .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid #e5e7eb!important; }
        .table td{ vertical-align:middle; border-color:#e5e7eb; font-weight:600; color:#374151; padding:14px 8px; }

        .today-card{ background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius:16px; padding:20px; margin-bottom:20px; }
        .today-card .btn-outline-light:hover{ background:rgba(255,255,255,0.2); color:white; }

        .attendance-summary{ display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr)); gap:12px; margin-bottom:20px; }
        .summary-item{ background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:12px; text-align:center; }
        .summary-item .label{ font-size:12px; color:#6b7280; font-weight:700; }
        .summary-item .value{ font-size:24px; font-weight:900; color:#1f2937; line-height:1.2; }

        .holiday-badge{ background:#e6f7ff; border:1px solid #91d5ff; color:#0050b3; padding:4px 8px; border-radius:20px; font-size:12px; }

        .action-btn{ width:32px; height:32px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; 
            display:inline-flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; margin:0 2px; }
        .action-btn:hover{ background:#f3f4f6; color:#374151; }

        .progress{ height:8px; border-radius:4px; }
        .hours-badge{ background:#e8f5e9; color:#2e7d32; padding:4px 8px; border-radius:20px; font-size:12px; font-weight:700; }

        @media (max-width: 768px) {
            .content-scroll{ padding:12px; }
            .stat-card{ margin-bottom:12px; }
            .attendance-summary{ grid-template-columns:repeat(2,1fr); }
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content-scroll">
            <div class="container-fluid maxw">

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h1 class="h3 fw-bold mb-1">My Attendance</h1>
                        <p class="text-muted mb-0">View your attendance records and summary</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="punchin.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> 
                            <?= $today_attendance && !$today_attendance['punch_out_time'] ? 'Punch Out' : 'Punch In' ?>
                        </a>
                        <a href="leave-request.php" class="btn btn-outline-primary">
                            <i class="bi bi-calendar-plus"></i> Request Leave
                        </a>
                    </div>
                </div>

                <!-- Flash Messages -->
                <?php if (isset($_SESSION['flash_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                        <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['flash_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                        <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Today's Status Card -->
                <?php if ($today_attendance): ?>
                    <div class="today-card">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h5 class="fw-bold mb-2">Today's Attendance</h5>
                                <div class="d-flex gap-4 flex-wrap">
                                    <div>
                                        <small class="opacity-75">Punch In</small>
                                        <div class="fw-bold fs-5">
                                            <?= $today_attendance['punch_in_time'] ? date('h:i A', strtotime($today_attendance['punch_in_time'])) : '—' ?>
                                        </div>
                                        <small><?= e($today_attendance['punch_in_location']) ?></small>
                                    </div>
                                    <?php if ($today_attendance['punch_out_time']): ?>
                                        <div>
                                            <small class="opacity-75">Punch Out</small>
                                            <div class="fw-bold fs-5">
                                                <?= date('h:i A', strtotime($today_attendance['punch_out_time'])) ?>
                                            </div>
                                            <small><?= e($today_attendance['punch_out_location']) ?></small>
                                        </div>
                                        <div>
                                            <small class="opacity-75">Total Hours</small>
                                            <div class="fw-bold fs-5"><?= number_format($today_attendance['total_hours'], 2) ?>h</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6 text-md-end mt-3 mt-md-0">
                                <?php if (!$today_attendance['punch_out_time']): ?>
                                    <a href="punchin.php?action=out" class="btn btn-outline-light btn-lg">
                                        <i class="bi bi-box-arrow-right"></i> Punch Out Now
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark p-3">
                                        <i class="bi bi-check-circle-fill text-success"></i> 
                                        Completed for today
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Filter Card -->
                <div class="filter-card">
                    <form method="GET" action="" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">View</label>
                            <select name="view" class="form-select" onchange="this.form.submit()">
                                <option value="month" <?= $view === 'month' ? 'selected' : '' ?>>Monthly View</option>
                                <option value="list" <?= $view === 'list' ? 'selected' : '' ?>>Recent List (30 days)</option>
                            </select>
                        </div>
                        <?php if ($view === 'month'): ?>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Month</label>
                            <select name="month" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($month_names as $m_num => $m_name): ?>
                                    <option value="<?= $m_num ?>" <?= $month == $m_num ? 'selected' : '' ?>>
                                        <?= $m_name ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Year</label>
                            <select name="year" class="form-select" onchange="this.form.submit()">
                                <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>>
                                        <?= $y ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel"></i> Apply Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Statistics Row -->
                <?php if ($view === 'month' && $summary): ?>
                <div class="attendance-summary">
                    <div class="summary-item">
                        <div class="label">Total Days</div>
                        <div class="value"><?= (int)($summary['total_days'] ?? 0) ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Present</div>
                        <div class="value text-success"><?= (int)($summary['present_days'] ?? 0) ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Absent</div>
                        <div class="value text-danger"><?= (int)($summary['absent_days'] ?? 0) ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Half Days</div>
                        <div class="value text-warning"><?= (int)($summary['half_days'] ?? 0) ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Late</div>
                        <div class="value text-info"><?= (int)($summary['late_days'] ?? 0) ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Leave</div>
                        <div class="value text-secondary"><?= (int)($summary['leaves'] ?? 0) ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Total Hours</div>
                        <div class="value"><?= number_format($summary['total_hours'] ?? 0, 1) ?>h</div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Avg/Day</div>
                        <div class="value"><?= number_format($summary['avg_hours'] ?? 0, 1) ?>h</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Holidays in Month -->
                <?php if (!empty($holidays) && $view === 'month'): ?>
                    <div class="alert alert-info d-flex align-items-center mb-3">
                        <i class="bi bi-calendar-heart me-3 fs-4"></i>
                        <div>
                            <strong>Holidays this month:</strong>
                            <?php 
                            $holiday_names = array_map(function($h) {
                                return $h['holiday_name'] . ' (' . date('d M', strtotime($h['holiday_date'])) . ')';
                            }, $holidays);
                            echo implode(' • ', $holiday_names);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Attendance Records Table -->
                <div class="panel">
                    <div class="panel-header">
                        <h5 class="panel-title">
                            <i class="bi bi-calendar-check me-2"></i>
                            Attendance Records
                            <?php if ($view === 'month'): ?>
                                <span class="badge bg-secondary ms-2"><?= $month_names[$month] ?> <?= $year ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary ms-2">Last 30 Days</span>
                            <?php endif; ?>
                        </h5>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary" onclick="exportTableToCSV()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle" id="attendanceTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Punch In</th>
                                    <th>Punch Out</th>
                                    <th>Total Hours</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($attendance_records)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                                            No attendance records found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($attendance_records as $record): ?>
                                        <tr>
                                            <td>
                                                <span class="fw-bold"><?= date('d M Y', strtotime($record['attendance_date'])) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?= date('D', strtotime($record['attendance_date'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($record['punch_in_time']): ?>
                                                    <span class="fw-bold"><?= date('h:i A', strtotime($record['punch_in_time'])) ?></span>
                                                    <br>
                                                    <small class="text-muted"><?= getPunchTypeIcon($record['punch_in_type']) ?> <?= e($record['punch_in_type'] ?? '') ?></small>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($record['punch_out_time']): ?>
                                                    <?= date('h:i A', strtotime($record['punch_out_time'])) ?>
                                                <?php else: ?>
                                                    <span class="text-warning">Not punched out</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($record['total_hours'] > 0): ?>
                                                    <span class="hours-badge">
                                                        <i class="bi bi-clock"></i> <?= number_format($record['total_hours'], 2) ?>h
                                                    </span>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= getPunchTypeIcon($record['punch_in_type']) ?>
                                                <?= ucfirst($record['punch_in_type'] ?? '—') ?>
                                            </td>
                                            <td>
                                                <div data-bs-toggle="tooltip" title="<?= e($record['punch_in_location']) ?>">
                                                    <?= e(substr($record['punch_in_location'] ?? '', 0, 30)) ?>...
                                                </div>
                                            </td>
                                            <td>
                                                <?= getStatusBadge($record['status'] ?? 'present') ?>
                                                <?php if ($record['late_minutes'] > 0): ?>
                                                    <br><small class="text-danger">Late by <?= $record['late_minutes'] ?>m</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="attendance-details.php?id=<?= $record['id'] ?>" class="action-btn" title="View Details">
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

                <!-- Recent Leave Requests -->
                <?php if (!empty($leave_requests)): ?>
                <div class="panel mt-3">
                    <div class="panel-header">
                        <h5 class="panel-title">
                            <i class="bi bi-calendar-plus me-2"></i>
                            Recent Leave Requests
                        </h5>
                        <a href="my-leaves.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Request Date</th>
                                    <th>Type</th>
                                    <th>Period</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_requests as $leave): ?>
                                    <tr>
                                        <td><?= safeDate($leave['applied_at'] ?? $leave['created_at']) ?></td>
                                        <td><?= e($leave['leave_type']) ?></td>
                                        <td><?= safeDate($leave['from_date']) ?> - <?= safeDate($leave['to_date']) ?></td>
                                        <td><span class="badge bg-info"><?= $leave['total_days'] ?> days</span></td>
                                        <td>
                                            <?php 
                                            $status = $leave['status'] ?? 'Pending';
                                            $badge_class = $status === 'Approved' ? 'success' : ($status === 'Rejected' ? 'danger' : 'warning');
                                            ?>
                                            <span class="badge bg-<?= $badge_class ?>"><?= $status ?></span>
                                        </td>
                                        <td>
                                            <a href="leave-details.php?id=<?= $leave['id'] ?>" class="action-btn">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(document).ready(function() {
    <?php if (!empty($attendance_records)): ?>
    $('#attendanceTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            infoEmpty: "No records to show",
            infoFiltered: "(filtered from _MAX_ total records)"
        }
    });
    <?php endif; ?>

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Export to CSV
function exportTableToCSV() {
    const rows = document.querySelectorAll('#attendanceTable tbody tr');
    const csv = [];
    
    // Headers
    const headers = ['Date', 'Day', 'Punch In', 'Punch Out', 'Total Hours', 'Type', 'Location', 'Status'];
    csv.push(headers.join(','));
    
    // Data rows
    rows.forEach(row => {
        if (row.cells.length === 9) {
            const rowData = [
                '"' + row.cells[0].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[1].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[2].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[3].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[4].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[5].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[6].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[7].innerText.replace(/"/g, '""') + '"'
            ];
            csv.push(rowData.join(','));
        }
    });
    
    const csvString = csv.join('\n');
    const blob = new Blob([csvString], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'my_attendance_<?= date('Y-m-d') ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>