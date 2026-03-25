<?php
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

// Get current user from session
$current_employee_id = (int)($_SESSION['employee_id'] ?? 0);
$current_employee_name = (string)($_SESSION['employee_name'] ?? '');

if ($current_employee_id <= 0) {
    header("Location: ../login.php");
    exit;
}

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// Get employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$employee) {
    die("Employee not found or inactive.");
}

// Get filter parameters
$selected_month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : (int)date('Y');
$status_filter = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : 'all';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch attendance data for the selected month/year
$attendance_query = "
    SELECT a.*, 
           s.project_name as site_name,
           o.location_name as office_name
    FROM attendance a
    LEFT JOIN sites s ON a.punch_in_site_id = s.id OR a.punch_out_site_id = s.id
    LEFT JOIN office_locations o ON a.punch_in_office_id = o.id OR a.punch_out_office_id = o.id
    WHERE a.employee_id = ?
      AND YEAR(a.attendance_date) = ?
      AND MONTH(a.attendance_date) = ?
    ORDER BY a.attendance_date DESC
";

$attendance_stmt = mysqli_prepare($conn, $attendance_query);
mysqli_stmt_bind_param($attendance_stmt, "iii", $current_employee_id, $selected_year, $selected_month);
mysqli_stmt_execute($attendance_stmt);
$attendance_res = mysqli_stmt_get_result($attendance_stmt);
$attendance_records = mysqli_fetch_all($attendance_res, MYSQLI_ASSOC);
mysqli_stmt_close($attendance_stmt);

// Convert attendance records to associative array by date for quick lookup
$attendance_by_date = [];
foreach ($attendance_records as $record) {
    $attendance_by_date[$record['attendance_date']] = $record;
}

// Fetch holidays for the selected month/year
$holiday_query = "
    SELECT * FROM holidays 
    WHERE YEAR(holiday_date) = ? 
      AND MONTH(holiday_date) = ?
    ORDER BY holiday_date
";
$holiday_stmt = mysqli_prepare($conn, $holiday_query);
mysqli_stmt_bind_param($holiday_stmt, "ii", $selected_year, $selected_month);
mysqli_stmt_execute($holiday_stmt);
$holiday_res = mysqli_stmt_get_result($holiday_stmt);
$holidays = mysqli_fetch_all($holiday_res, MYSQLI_ASSOC);
mysqli_stmt_close($holiday_stmt);

$holiday_dates = [];
foreach ($holidays as $holiday) {
    $holiday_dates[$holiday['holiday_date']] = $holiday['holiday_name'];
}

// Fetch leave requests for the employee in this month
$leave_query = "
    SELECT * FROM leave_requests 
    WHERE employee_id = ? 
      AND status = 'Approved'
      AND (
        (YEAR(from_date) = ? AND MONTH(from_date) = ?)
        OR (YEAR(to_date) = ? AND MONTH(to_date) = ?)
        OR (YEAR(from_date) < ? AND YEAR(to_date) > ?)
      )
    ORDER BY from_date DESC
";
$leave_stmt = mysqli_prepare($conn, $leave_query);
mysqli_stmt_bind_param($leave_stmt, "iiiiiii", 
    $current_employee_id, $selected_year, $selected_month, 
    $selected_year, $selected_month, $selected_year, $selected_year);
mysqli_stmt_execute($leave_stmt);
$leave_res = mysqli_stmt_get_result($leave_stmt);
$leave_requests = mysqli_fetch_all($leave_res, MYSQLI_ASSOC);
mysqli_stmt_close($leave_stmt);

// Build array of dates in the month with leave status
$leave_dates = [];
foreach ($leave_requests as $leave) {
    $start = new DateTime($leave['from_date']);
    $end = new DateTime($leave['to_date']);
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start, $interval, $end->modify('+1 day'));
    
    foreach ($date_range as $date) {
        $date_str = $date->format('Y-m-d');
        if (substr($date_str, 0, 7) == "$selected_year-" . str_pad($selected_month, 2, '0', STR_PAD_LEFT)) {
            $leave_dates[$date_str] = $leave['leave_type'];
        }
    }
}

// Generate all dates in the selected month
$first_day = new DateTime("$selected_year-$selected_month-01");
$last_day = new DateTime($first_day->format('Y-m-t'));
$interval = new DateInterval('P1D');
$date_range = new DatePeriod($first_day, $interval, $last_day->modify('+1 day'));

$complete_attendance = [];
foreach ($date_range as $date) {
    $date_str = $date->format('Y-m-d');
    $day_of_week = $date->format('l');
    
    // Check if it's a Sunday (weekly off) - you can modify this based on company policy
    $is_weekly_off = ($day_of_week === 'Sunday');
    
    // Check if it's a holiday
    $is_holiday = isset($holiday_dates[$date_str]);
    $holiday_name = $is_holiday ? $holiday_dates[$date_str] : null;
    
    // Check if on leave
    $is_leave = isset($leave_dates[$date_str]);
    $leave_type = $is_leave ? $leave_dates[$date_str] : null;
    
    // Get attendance record if exists
    $record = isset($attendance_by_date[$date_str]) ? $attendance_by_date[$date_str] : null;
    
    // Determine status
    if ($record) {
        $status = $record['status'];
        $punch_in_time = $record['punch_in_time'];
        $punch_out_time = $record['punch_out_time'];
        $total_hours = $record['total_hours'];
        $punch_in_type = $record['punch_in_type'];
        $site_name = $record['site_name'];
        $office_name = $record['office_name'];
        $late_minutes = $record['late_minutes'];
        $overtime_minutes = $record['overtime_minutes'];
    } elseif ($is_weekly_off) {
        $status = 'weekly-off';
        $punch_in_time = null;
        $punch_out_time = null;
        $total_hours = 0;
        $punch_in_type = null;
        $site_name = null;
        $office_name = null;
        $late_minutes = 0;
        $overtime_minutes = 0;
    } elseif ($is_holiday) {
        $status = 'holiday';
        $punch_in_time = null;
        $punch_out_time = null;
        $total_hours = 0;
        $punch_in_type = null;
        $site_name = null;
        $office_name = null;
        $late_minutes = 0;
        $overtime_minutes = 0;
    } elseif ($is_leave) {
        $status = 'leave';
        $punch_in_time = null;
        $punch_out_time = null;
        $total_hours = 0;
        $punch_in_type = null;
        $site_name = null;
        $office_name = null;
        $late_minutes = 0;
        $overtime_minutes = 0;
    } else {
        $status = 'absent';
        $punch_in_time = null;
        $punch_out_time = null;
        $total_hours = 0;
        $punch_in_type = null;
        $site_name = null;
        $office_name = null;
        $late_minutes = 0;
        $overtime_minutes = 0;
    }
    
    $complete_attendance[] = [
        'attendance_date' => $date_str,
        'day_of_week' => $day_of_week,
        'status' => $status,
        'punch_in_time' => $punch_in_time,
        'punch_out_time' => $punch_out_time,
        'total_hours' => $total_hours,
        'punch_in_type' => $punch_in_type,
        'site_name' => $site_name,
        'office_name' => $office_name,
        'late_minutes' => $late_minutes,
        'overtime_minutes' => $overtime_minutes,
        'is_weekly_off' => $is_weekly_off,
        'is_holiday' => $is_holiday,
        'holiday_name' => $holiday_name,
        'is_leave' => $is_leave,
        'leave_type' => $leave_type
    ];
}

// Apply filters
$filtered_records = array_filter($complete_attendance, function($record) use ($status_filter, $search_term) {
    // Status filter
    if ($status_filter !== 'all') {
        if ($status_filter === 'present' && $record['status'] !== 'present') return false;
        if ($status_filter === 'absent' && $record['status'] !== 'absent') return false;
        if ($status_filter === 'late' && $record['status'] !== 'late') return false;
        if ($status_filter === 'half-day' && $record['status'] !== 'half-day') return false;
        if ($status_filter === 'leave' && $record['status'] !== 'leave') return false;
        if ($status_filter === 'holiday' && $record['status'] !== 'holiday') return false;
        if ($status_filter === 'weekly-off' && $record['status'] !== 'weekly-off') return false;
    }
    
    // Search term filter
    if (!empty($search_term)) {
        $search_lower = strtolower($search_term);
        $date_match = strpos(strtolower($record['attendance_date']), $search_lower) !== false;
        $site_match = strpos(strtolower($record['site_name'] ?? ''), $search_lower) !== false;
        $office_match = strpos(strtolower($record['office_name'] ?? ''), $search_lower) !== false;
        $type_match = strpos(strtolower($record['punch_in_type'] ?? ''), $search_lower) !== false;
        $status_match = strpos(strtolower($record['status']), $search_lower) !== false;
        if (!$date_match && !$site_match && !$office_match && !$type_match && !$status_match) {
            return false;
        }
    }
    
    return true;
});

// Calculate monthly summary
$monthly_summary = [
    'total_days' => 0,
    'present_days' => 0,
    'absent_days' => 0,
    'late_days' => 0,
    'half_days' => 0,
    'leave_days' => 0,
    'holiday_days' => 0,
    'weekly_off_days' => 0,
    'total_hours' => 0,
    'overtime_hours' => 0
];

foreach ($complete_attendance as $record) {
    $monthly_summary['total_days']++;
    switch ($record['status']) {
        case 'present':
            $monthly_summary['present_days']++;
            break;
        case 'absent':
            $monthly_summary['absent_days']++;
            break;
        case 'late':
            $monthly_summary['late_days']++;
            $monthly_summary['present_days']++;
            break;
        case 'half-day':
            $monthly_summary['half_days']++;
            break;
        case 'leave':
            $monthly_summary['leave_days']++;
            break;
        case 'holiday':
            $monthly_summary['holiday_days']++;
            break;
        case 'weekly-off':
            $monthly_summary['weekly_off_days']++;
            break;
    }
    $monthly_summary['total_hours'] += (float)($record['total_hours'] ?? 0);
    $monthly_summary['overtime_hours'] += (float)($record['overtime_minutes'] ?? 0) / 60;
}

// Fetch all leave requests for the employee (for display)
$all_leave_query = "
    SELECT * FROM leave_requests 
    WHERE employee_id = ? 
    ORDER BY from_date DESC 
    LIMIT 20
";
$all_leave_stmt = mysqli_prepare($conn, $all_leave_query);
mysqli_stmt_bind_param($all_leave_stmt, "i", $current_employee_id);
mysqli_stmt_execute($all_leave_stmt);
$all_leave_res = mysqli_stmt_get_result($all_leave_stmt);
$all_leave_requests = mysqli_fetch_all($all_leave_res, MYSQLI_ASSOC);
mysqli_stmt_close($all_leave_stmt);

// Fetch employee regulations
$reg_query = "
    SELECT * FROM employee_regulations 
    WHERE employee_id = ? 
      AND status = 'Active'
      AND (expiry_date IS NULL OR expiry_date >= CURDATE())
    ORDER BY effective_date DESC
";
$reg_stmt = mysqli_prepare($conn, $reg_query);
mysqli_stmt_bind_param($reg_stmt, "i", $current_employee_id);
mysqli_stmt_execute($reg_stmt);
$reg_res = mysqli_stmt_get_result($reg_stmt);
$employee_regulations = mysqli_fetch_all($reg_res, MYSQLI_ASSOC);
mysqli_stmt_close($reg_stmt);

// Helper functions
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safeTimeOnly($v, $dash = '—') {
    if (empty($v)) return $dash;
    $ts = strtotime($v);
    return $ts ? date('h:i A', $ts) : $dash;
}
function getStatusBadge($status, $punch_in_time = null, $is_holiday = false, $is_weekly_off = false, $holiday_name = null) {
    if ($is_holiday) {
        $badge_class = 'status-info';
        $badge_text = $holiday_name ?: 'Holiday';
        return '<span class="status-badge ' . $badge_class . '" title="' . e($badge_text) . '"><i class="bi bi-calendar-heart"></i> ' . e($badge_text) . '</span>';
    }
    
    if ($is_weekly_off) {
        return '<span class="status-badge status-info"><i class="bi bi-calendar-week"></i> Weekly Off</span>';
    }
    
    if ($status === 'leave') {
        return '<span class="status-badge status-purple"><i class="bi bi-suit-heart"></i> On Leave</span>';
    }
    
    if ($status === 'absent') {
        return '<span class="status-badge status-red"><i class="bi bi-x-circle"></i> Absent</span>';
    }
    
    if ($status === 'half-day') {
        return '<span class="status-badge status-orange"><i class="bi bi-hourglass-split"></i> Half Day</span>';
    }
    
    if ($status === 'present' || $status === 'late') {
        $is_late = $punch_in_time && strtotime($punch_in_time) > strtotime(date('Y-m-d', strtotime($punch_in_time)) . ' 09:15:00');
        if ($is_late) {
            return '<span class="status-badge status-yellow"><i class="bi bi-clock"></i> Late In</span>';
        }
        return '<span class="status-badge status-green"><i class="bi bi-check-circle"></i> Present</span>';
    }
    
    return '<span class="status-badge status-secondary">' . ucfirst($status) . '</span>';
}

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Attendance - TEK-C</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />
    
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    
    <style>
        .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px 22px 14px; }
        .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow); padding: 16px; height: 100%; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .panel-title { font-weight: 1000; font-size: 18px; color: #1f2937; margin: 0; }
        
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 14px 16px; display: flex; align-items: center; gap: 14px; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-ic { width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center; color: #fff; font-size: 22px; flex: 0 0 auto; }
        .stat-ic.blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .stat-ic.green { background: linear-gradient(135deg, #10b981, #059669); }
        .stat-ic.yellow { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-ic.purple { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        .stat-ic.red { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .stat-ic.info { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .stat-label { color: #6b7280; font-weight: 800; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 28px; font-weight: 1000; line-height: 1; margin-top: 4px; color: #111827; }
        
        .filter-bar { background: #f9fafb; border-radius: 16px; padding: 12px 16px; margin-bottom: 20px; border: 1px solid var(--border); }
        .filter-select, .filter-input { border-radius: 12px; border: 1px solid var(--border); padding: 8px 12px; font-size: 13px; font-weight: 500; background: white; }
        
        .table-responsive { overflow-x: hidden !important; }
        table.dataTable { width: 100% !important; }
        .table thead th { font-size: 12px; color: #6b7280; font-weight: 900; border-bottom: 1px solid var(--border) !important; padding: 12px 10px !important; }
        .table td { vertical-align: middle; border-color: var(--border); font-weight: 600; color: #374151; padding: 12px 10px !important; }
        
        .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 1000; letter-spacing: 0.3px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; border: 1px solid transparent; }
        .status-green { background: rgba(16, 185, 129, 0.12); color: #10b981; border-color: rgba(16, 185, 129, 0.22); }
        .status-yellow { background: rgba(245, 158, 11, 0.12); color: #f59e0b; border-color: rgba(245, 158, 11, 0.22); }
        .status-red { background: rgba(239, 68, 68, 0.12); color: #ef4444; border-color: rgba(239, 68, 68, 0.22); }
        .status-orange { background: rgba(249, 115, 22, 0.12); color: #f97316; border-color: rgba(249, 115, 22, 0.22); }
        .status-purple { background: rgba(139, 92, 246, 0.12); color: #8b5cf6; border-color: rgba(139, 92, 246, 0.22); }
        .status-info { background: rgba(6, 182, 212, 0.12); color: #06b6d4; border-color: rgba(6, 182, 212, 0.22); }
        
        .leave-badge { padding: 4px 10px; border-radius: 999px; font-size: 10px; font-weight: 900; display: inline-flex; align-items: center; gap: 4px; }
        .leave-pending { background: #fef3c7; color: #d97706; }
        .leave-approved { background: #d1fae5; color: #059669; }
        .leave-rejected { background: #fee2e2; color: #dc2626; }
        
        .reg-card { border: 1px solid var(--border); border-radius: 12px; padding: 12px; margin-bottom: 12px; background: #fefce8; }
        .reg-card h6 { font-size: 13px; font-weight: 900; margin-bottom: 6px; }
        .reg-card p { font-size: 11px; margin-bottom: 0; color: #6b7280; }
        
        .btn-action { background: transparent; border: 1px solid var(--border); border-radius: 12px; padding: 8px 16px; color: #374151; font-size: 13px; font-weight: 1000; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-action:hover { background: #f9fafb; color: var(--blue); border-color: var(--blue); }
        
        .r-card { border: 1px solid var(--border); border-radius: 16px; background: var(--surface); padding: 12px; margin-bottom: 12px; }
        .r-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .r-kv { margin-top: 12px; display: grid; gap: 8px; }
        .r-row { display: flex; gap: 10px; align-items: flex-start; }
        .r-key { flex: 0 0 85px; color: #6b7280; font-weight: 800; font-size: 11px; text-transform: uppercase; }
        .r-val { flex: 1 1 auto; font-weight: 700; color: #111827; font-size: 13px; }
        .r-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        
        .attendance-date-past { opacity: 0.8; }
        .attendance-date-today { background: rgba(59, 130, 246, 0.05); border-left: 3px solid var(--blue); }
        
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px !important; }
            .stat-value { font-size: 22px; }
            .stat-ic { width: 40px; height: 40px; font-size: 18px; }
        }
        @media (max-width: 991.98px) {
            .main { margin-left: 0 !important; width: 100% !important; }
            .sidebar { position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
            .sidebar.open { transform: translateX(0) !important; }
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
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">My Attendance</h1>
                        <p class="text-muted mb-0">Complete attendance record for <?php echo date('F Y', strtotime("$selected_year-$selected_month-01")); ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="punchin.php" class="btn-action">
                            <i class="bi bi-box-arrow-in-right"></i> Punch In/Out
                        </a>
                        <a href="apply-leave.php" class="btn-action">
                            <i class="bi bi-calendar-plus"></i> Apply Leave
                        </a>
                        <a href="my-leaves.php" class="btn-action">
                            <i class="bi bi-list-check"></i> My Leaves
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
                
                <!-- Month Selector -->
                <div class="filter-bar">
                    <form method="GET" class="row g-3 align-items-end" id="filterForm">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted">Month</label>
                            <select name="month" class="form-select filter-select" onchange="this.form.submit()">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $selected_month == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted">Year</label>
                            <select name="year" class="form-select filter-select" onchange="this.form.submit()">
                                <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $selected_year == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted">Status</label>
                            <select name="status" class="form-select filter-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="present" <?php echo $status_filter == 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="late" <?php echo $status_filter == 'late' ? 'selected' : ''; ?>>Late</option>
                                <option value="absent" <?php echo $status_filter == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                <option value="half-day" <?php echo $status_filter == 'half-day' ? 'selected' : ''; ?>>Half Day</option>
                                <option value="leave" <?php echo $status_filter == 'leave' ? 'selected' : ''; ?>>Leave</option>
                                <option value="holiday" <?php echo $status_filter == 'holiday' ? 'selected' : ''; ?>>Holiday</option>
                                <option value="weekly-off" <?php echo $status_filter == 'weekly-off' ? 'selected' : ''; ?>>Weekly Off</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted">Search</label>
                            <input type="text" name="search" class="form-control filter-input" placeholder="Date, site, type..." value="<?php echo e($search_term); ?>" onkeyup="if(event.key === 'Enter') this.form.submit()">
                        </div>
                        <div class="col-md-auto">
                            <a href="my-attendance.php?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="btn-action">Reset</a>
                        </div>
                    </form>
                </div>
                
                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-calendar-week"></i></div>
                            <div>
                                <div class="stat-label">Total Days</div>
                                <div class="stat-value"><?php echo $monthly_summary['total_days']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check-lg"></i></div>
                            <div>
                                <div class="stat-label">Present</div>
                                <div class="stat-value"><?php echo $monthly_summary['present_days']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-clock"></i></div>
                            <div>
                                <div class="stat-label">Late</div>
                                <div class="stat-value"><?php echo $monthly_summary['late_days']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-x-lg"></i></div>
                            <div>
                                <div class="stat-label">Absent</div>
                                <div class="stat-value"><?php echo $monthly_summary['absent_days']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-suit-heart"></i></div>
                            <div>
                                <div class="stat-label">Leave</div>
                                <div class="stat-value"><?php echo $monthly_summary['leave_days']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic info"><i class="bi bi-calendar-heart"></i></div>
                            <div>
                                <div class="stat-label">Holiday/Off</div>
                                <div class="stat-value"><?php echo $monthly_summary['holiday_days'] + $monthly_summary['weekly_off_days']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Stats Row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="panel">
                            <div class="panel-header">
                                <h3 class="panel-title">Monthly Summary</h3>
                            </div>
                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="text-center p-2 bg-light rounded-3">
                                        <div class="text-muted small fw-bold">Total Hours</div>
                                        <div class="h3 fw-bold mb-0"><?php echo number_format($monthly_summary['total_hours'], 1); ?></div>
                                        <div class="text-muted small">hours</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center p-2 bg-light rounded-3">
                                        <div class="text-muted small fw-bold">Overtime</div>
                                        <div class="h3 fw-bold mb-0"><?php echo number_format($monthly_summary['overtime_hours'], 1); ?></div>
                                        <div class="text-muted small">hours</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="mt-2">
                                        <div class="d-flex justify-content-between small fw-bold mb-1">
                                            <span>Attendance Rate</span>
                                            <span><?php 
                                                $working_days = $monthly_summary['total_days'] - $monthly_summary['weekly_off_days'] - $monthly_summary['holiday_days'];
                                                $attended_days = $monthly_summary['present_days'] + $monthly_summary['late_days'];
                                                $attendance_rate = $working_days > 0 ? round($attended_days / $working_days * 100, 1) : 0;
                                                echo $attendance_rate . '%';
                                            ?></span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo $attendance_rate; ?>%"></div>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            Working days: <?php echo $working_days; ?> | Attended: <?php echo $attended_days; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="panel">
                            <div class="panel-header">
                                <h3 class="panel-title">Active Regulations</h3>
                            </div>
                            <?php if (empty($employee_regulations)): ?>
                                <p class="text-muted small mb-0">No active regulations assigned.</p>
                            <?php else: ?>
                                <?php foreach ($employee_regulations as $reg): ?>
                                    <div class="reg-card">
                                        <h6><i class="bi bi-shield-check me-1 text-primary"></i> <?php echo e($reg['regulation_type']); ?></h6>
                                        <p>Effective: <?php echo e(date('d M Y', strtotime($reg['effective_date']))); ?>
                                        <?php if ($reg['expiry_date']): ?> → <?php echo e(date('d M Y', strtotime($reg['expiry_date']))); ?><?php endif; ?></p>
                                        <p class="small"><?php echo e($reg['description']); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Leave Requests -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">Recent Leave Requests</h3>
                        <a href="my-leaves.php" class="btn-action btn-sm">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Leave Type</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                    <th>Applied On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_leave_requests)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">No leave requests found.</td></tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($all_leave_requests, 0, 5) as $leave): ?>
                                        <tr>
                                            <td><?php echo e($leave['leave_type']); ?></td>
                                            <td><?php echo e(date('d M Y', strtotime($leave['from_date']))); ?></td>
                                            <td><?php echo e(date('d M Y', strtotime($leave['to_date']))); ?></td>
                                            <td><?php echo e($leave['total_days']); ?></td>
                                            <td>
                                                <span class="leave-badge leave-<?php echo strtolower($leave['status']); ?>">
                                                    <i class="bi bi-<?php echo $leave['status'] == 'Approved' ? 'check-circle' : ($leave['status'] == 'Rejected' ? 'x-circle' : 'hourglass-split'); ?>"></i>
                                                    <?php echo e($leave['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo e(date('d M Y', strtotime($leave['applied_at'] ?? $leave['created_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-block d-md-none">
                        <?php foreach (array_slice($all_leave_requests, 0, 5) as $leave): ?>
                            <div class="r-card">
                                <div class="r-top">
                                    <div class="fw-bold"><?php echo e($leave['leave_type']); ?> Leave</div>
                                    <span class="leave-badge leave-<?php echo strtolower($leave['status']); ?>"><?php echo e($leave['status']); ?></span>
                                </div>
                                <div class="r-kv">
                                    <div class="r-row"><div class="r-key">Period</div><div class="r-val"><?php echo e(date('d M', strtotime($leave['from_date']))); ?> - <?php echo e(date('d M Y', strtotime($leave['to_date']))); ?></div></div>
                                    <div class="r-row"><div class="r-key">Days</div><div class="r-val"><?php echo e($leave['total_days']); ?> day(s)</div></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Attendance Records Table -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Daily Attendance Records</h3>
                        <span class="badge bg-light text-dark"><?php echo count($filtered_records); ?> records</span>
                    </div>
                    
                    <!-- Desktop Table -->
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table id="attendanceTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Day</th>
                                        <th>Punch In</th>
                                        <th>Punch Out</th>
                                        <th>Hours</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $today = date('Y-m-d');
                                    foreach ($filtered_records as $record): 
                                        $is_today = $record['attendance_date'] == $today;
                                        $row_class = $is_today ? 'attendance-date-today' : '';
                                    ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td><strong><?php echo e(date('d M Y', strtotime($record['attendance_date']))); ?></strong></td>
                                            <td><?php echo e(date('D', strtotime($record['attendance_date']))); ?></td>
                                            <td><?php echo safeTimeOnly($record['punch_in_time'] ?? '', '—'); ?></td>
                                            <td><?php echo safeTimeOnly($record['punch_out_time'] ?? '', '—'); ?></td>
                                            <td>
                                                <?php if ($record['total_hours'] > 0): ?>
                                                    <span class="badge bg-light text-dark"><?php echo e($record['total_hours']); ?>h</span>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ($record['punch_in_type'] == 'site' && !empty($record['site_name'])) {
                                                    echo '<i class="bi bi-building"></i> ' . e($record['site_name']);
                                                } elseif ($record['punch_in_type'] == 'office' && !empty($record['office_name'])) {
                                                    echo '<i class="bi bi-briefcase"></i> ' . e($record['office_name']);
                                                } elseif ($record['punch_in_type'] == 'remote') {
                                                    echo '<i class="bi bi-wifi"></i> Remote';
                                                } else {
                                                    echo '<i class="bi bi-geo-alt"></i> —';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php echo getStatusBadge($record['status'], $record['punch_in_time'], $record['is_holiday'], $record['is_weekly_off'], $record['holiday_name']); ?>
                                                <?php if ($record['is_leave'] && $record['leave_type']): ?>
                                                    <span class="small text-muted ms-1">(<?php echo e($record['leave_type']); ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($filtered_records)): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-4">No attendance records found for the selected period.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Mobile Cards -->
                    <div class="d-block d-md-none">
                        <?php if (empty($filtered_records)): ?>
                            <div class="text-center text-muted py-4">No attendance records found for the selected period.</div>
                        <?php else: ?>
                            <?php foreach ($filtered_records as $record): 
                                $is_today = $record['attendance_date'] == date('Y-m-d');
                                $card_class = $is_today ? 'border-primary' : '';
                            ?>
                                <div class="r-card <?php echo $card_class; ?>">
                                    <div class="r-top">
                                        <div>
                                            <div class="fw-bold"><?php echo e(date('d M Y', strtotime($record['attendance_date']))); ?></div>
                                            <div class="small text-muted"><?php echo e(date('l', strtotime($record['attendance_date']))); ?></div>
                                        </div>
                                        <?php echo getStatusBadge($record['status'], $record['punch_in_time'], $record['is_holiday'], $record['is_weekly_off'], $record['holiday_name']); ?>
                                    </div>
                                    <div class="r-kv">
                                        <div class="r-row"><div class="r-key">Punch In</div><div class="r-val"><?php echo safeTimeOnly($record['punch_in_time'] ?? '', '—'); ?></div></div>
                                        <div class="r-row"><div class="r-key">Punch Out</div><div class="r-val"><?php echo safeTimeOnly($record['punch_out_time'] ?? '', '—'); ?></div></div>
                                        <div class="r-row"><div class="r-key">Duration</div><div class="r-val"><?php echo $record['total_hours'] ? e($record['total_hours']) . ' hours' : '—'; ?></div></div>
                                        <div class="r-row"><div class="r-key">Location</div><div class="r-val">
                                            <?php
                                            if ($record['punch_in_type'] == 'site' && !empty($record['site_name'])) {
                                                echo e($record['site_name']);
                                            } elseif ($record['punch_in_type'] == 'office' && !empty($record['office_name'])) {
                                                echo e($record['office_name']);
                                            } elseif ($record['punch_in_type'] == 'remote') {
                                                echo 'Remote';
                                            } else {
                                                echo '—';
                                            }
                                            ?>
                                        </div></div>
                                        <?php if ($record['late_minutes'] > 0 && $record['status'] !== 'leave' && $record['status'] !== 'absent'): ?>
                                            <div class="r-row"><div class="r-key">Late By</div><div class="r-val text-warning"><?php echo e($record['late_minutes']); ?> mins</div></div>
                                        <?php endif; ?>
                                        <?php if ($record['is_leave'] && $record['leave_type']): ?>
                                            <div class="r-row"><div class="r-key">Leave Type</div><div class="r-val"><?php echo e($record['leave_type']); ?></div></div>
                                        <?php endif; ?>
                                        <?php if ($record['is_holiday'] && $record['holiday_name']): ?>
                                            <div class="r-row"><div class="r-key">Holiday</div><div class="r-val"><?php echo e($record['holiday_name']); ?></div></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="r-badges">
                                        <?php if ($record['punch_in_type']): ?>
                                            <span class="badge bg-light"><i class="bi bi-<?php echo $record['punch_in_type'] == 'site' ? 'building' : ($record['punch_in_type'] == 'office' ? 'briefcase' : 'wifi'); ?>"></i> <?php echo ucfirst($record['punch_in_type']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($record['overtime_minutes'] > 0): ?>
                                            <span class="badge bg-success"><i class="bi bi-clock-history"></i> OT: <?php echo round($record['overtime_minutes'] / 60, 1); ?>h</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    $(document).ready(function() {
        // Initialize DataTable only on desktop
        if (window.matchMedia('(min-width: 768px)').matches && $('#attendanceTable tbody tr').length > 0) {
            $('#attendanceTable').DataTable({
                responsive: true,
                autoWidth: false,
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
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
    });
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