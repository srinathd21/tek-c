<?php
session_start();
require_once 'includes/db-config.php';

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

$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$allowed_per_page = [10, 25, 50, 100];
if (!in_array($records_per_page, $allowed_per_page, true)) {
    $records_per_page = 10;
}

$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Fetch attendance data for the selected month/year
$attendance_query = "
    SELECT a.*,
           COALESCE(si.project_name, so.project_name) AS site_name,
           COALESCE(oi.location_name, oo.location_name) AS office_name
    FROM attendance a
    LEFT JOIN sites si ON a.punch_in_site_id = si.id
    LEFT JOIN sites so ON a.punch_out_site_id = so.id
    LEFT JOIN office_locations oi ON a.punch_in_office_id = oi.id
    LEFT JOIN office_locations oo ON a.punch_out_office_id = oo.id
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
$month_start = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end = date('Y-m-t', strtotime($month_start));

$leave_query = "
    SELECT * FROM leave_requests
    WHERE employee_id = ?
      AND status = 'Approved'
      AND from_date <= ?
      AND to_date >= ?
    ORDER BY from_date DESC
";
$leave_stmt = mysqli_prepare($conn, $leave_query);
mysqli_stmt_bind_param($leave_stmt, "iss",
    $current_employee_id, $month_end, $month_start);
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

$filtered_records = array_values($filtered_records);

$total_filtered_records = count($filtered_records);
$total_pages = (int)ceil($total_filtered_records / $records_per_page);

if ($total_pages < 1) {
    $total_pages = 1;
}

if ($current_page > $total_pages) {
    $current_page = $total_pages;
}

$pagination_offset = ($current_page - 1) * $records_per_page;
$paginated_records = array_slice($filtered_records, $pagination_offset, $records_per_page);
$pagination_start = $total_filtered_records > 0 ? $pagination_offset + 1 : 0;
$pagination_end = min($pagination_offset + $records_per_page, $total_filtered_records);

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

// Fetch employee regulations if table exists
$employee_regulations = [];
$reg_table_res = mysqli_query($conn, "SHOW TABLES LIKE 'employee_regulations'");
$has_employee_regulations = $reg_table_res && mysqli_num_rows($reg_table_res) > 0;
if ($reg_table_res) mysqli_free_result($reg_table_res);

if ($has_employee_regulations) {
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
}

// Helper functions
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safeTimeOnly($v, $dash = '—') {
    if (empty($v)) return $dash;
    $ts = strtotime($v);
    return $ts ? date('h:i A', $ts) : $dash;
}

function buildPageUrl($page, $overrides = []) {
    $params = $_GET;
    $params['page'] = max(1, (int)$page);

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'my-attendance.php?' . http_build_query($params);
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

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
    :root {
        --page-bg: #f5f7fb;
        --card-bg: #ffffff;
        --border: #e5e7eb;
        --text: #111827;
        --muted: #6b7280;
        --soft: #f8fafc;
        --shadow: 0 10px 26px rgba(15, 23, 42, .055);
        --radius: 15px;
        --blue: #2f80ed;
        --orange: #f2994a;
        --green: #27ae60;
        --red: #eb5757;
        --purple: #8b5cf6;
        --cyan: #06b6d4;
    }

    body {
        background: var(--page-bg);
    }

    .content-scroll {
        flex: 1 1 auto;
        overflow: auto;
        padding: 16px;
    }

    .projects-wrapper {
        width: 100%;
    }

    .page-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .page-heading h1 {
        font-size: 19px;
        font-weight: 900;
        color: var(--text);
        margin: 0;
    }

    .page-heading p {
        margin: 3px 0 0;
        color: var(--muted);
        font-size: 12px;
        font-weight: 600;
    }

    .primary-btn,
    .secondary-btn,
    .btn-action {
        min-height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
    }

    .primary-btn {
        border: 0;
        background: #111827;
        color: #fff;
    }

    .primary-btn:hover {
        background: #020617;
        color: #fff;
    }

    .secondary-btn,
    .btn-action {
        border: 1px solid var(--border);
        background: #fff;
        color: #334155;
    }

    .secondary-btn:hover,
    .btn-action:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #111827;
    }

    .panel {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 13px;
        margin-bottom: 14px;
        height: 100%;
    }

    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .panel-title {
        font-weight: 900;
        font-size: 14px;
        color: var(--text);
        margin: 0;
    }

    .panel-subtitle {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
    }

    .filter-bar {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 13px;
        margin-bottom: 14px;
    }

    .form-label {
        font-size: 11px;
        font-weight: 900;
        color: #475569;
        text-transform: uppercase;
        margin-bottom: 6px;
    }

    .filter-select,
    .filter-input,
    .form-select,
    .form-control {
        min-height: 38px;
        border: 1px solid var(--border);
        border-radius: 11px;
        font-size: 12px;
        font-weight: 800;
        color: #111827;
        padding: 8px 11px;
        background: #fff;
    }

    .filter-select:focus,
    .filter-input:focus,
    .form-select:focus,
    .form-control:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .stat-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 12px 13px;
        min-height: 78px;
        display: flex;
        align-items: center;
        gap: 11px;
        transition: .15s ease;
    }

    .stat-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 32px rgba(15, 23, 42, .09);
    }

    .stat-ic {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        color: #fff;
        font-size: 17px;
        flex: 0 0 auto;
    }

    .stat-ic.blue {
        background: var(--blue);
    }

    .stat-ic.green {
        background: var(--green);
    }

    .stat-ic.yellow {
        background: var(--orange);
    }

    .stat-ic.purple {
        background: var(--purple);
    }

    .stat-ic.red {
        background: var(--red);
    }

    .stat-ic.info {
        background: var(--cyan);
    }

    .stat-label {
        color: var(--muted);
        font-weight: 800;
        font-size: 10.5px;
        text-transform: uppercase;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 950;
        color: var(--text);
        line-height: 1;
    }

    .summary-box {
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 13px;
        padding: 12px;
        text-align: center;
        min-height: 86px;
    }

    .summary-label {
        color: #64748b;
        font-size: 10.5px;
        font-weight: 900;
        text-transform: uppercase;
    }

    .summary-value {
        color: #111827;
        font-size: 25px;
        font-weight: 950;
        line-height: 1;
        margin-top: 4px;
    }

    .summary-unit {
        color: #64748b;
        font-size: 10.5px;
        font-weight: 800;
        margin-top: 3px;
    }

    .status-badge,
    .leave-badge,
    .badge-pill {
        padding: 5px 8px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
        border: 1px solid transparent;
    }

    .status-green,
    .leave-approved {
        background: #dcfce7;
        color: #15803d;
        border-color: #bbf7d0;
    }

    .status-yellow,
    .leave-pending {
        background: #fef3c7;
        color: #b45309;
        border-color: #fde68a;
    }

    .status-red,
    .leave-rejected {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .status-orange {
        background: #ffedd5;
        color: #c2410c;
        border-color: #fed7aa;
    }

    .status-purple {
        background: #ede9fe;
        color: #6d28d9;
        border-color: #ddd6fe;
    }

    .status-info {
        background: #cffafe;
        color: #0e7490;
        border-color: #a5f3fc;
    }

    .status-secondary {
        background: #f1f5f9;
        color: #475569;
        border-color: #e2e8f0;
    }

    .reg-card {
        border: 1px solid #fde68a;
        border-radius: 13px;
        padding: 11px;
        margin-bottom: 10px;
        background: #fffbeb;
    }

    .reg-card h6 {
        font-size: 12px;
        font-weight: 950;
        margin-bottom: 5px;
        color: #111827;
    }

    .reg-card p {
        font-size: 10.5px;
        margin-bottom: 0;
        color: #64748b;
        font-weight: 750;
    }

    .compact-table-wrap {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 13px;
        overflow: hidden;
        background: #fff;
    }

    .compact-table {
        width: 100%;
        margin: 0;
        table-layout: auto;
    }

    .compact-table thead th {
        background: var(--soft);
        color: #64748b;
        font-size: 10px;
        text-transform: uppercase;
        font-weight: 900;
        border-bottom: 1px solid var(--border) !important;
        padding: 8px 9px;
    }

    .compact-table tbody td {
        padding: 8px 9px;
        vertical-align: middle;
        border-color: #eef2f7;
        color: #334155;
        font-weight: 700;
        font-size: 11.5px;
    }

    .compact-table tbody tr:hover {
        background: #fbfdff;
    }

    .table-primary-text {
        color: #111827;
        font-size: 11.5px;
        font-weight: 950;
    }

    .table-secondary-text {
        color: #64748b;
        font-size: 10px;
        font-weight: 700;
        margin-top: 2px;
    }

    .attendance-date-today {
        background: #eff6ff !important;
        box-shadow: inset 3px 0 0 #2f80ed;
    }

    .r-card {
        border: 1px solid var(--border);
        border-radius: 14px;
        background: #fff;
        box-shadow: var(--shadow);
        padding: 12px;
        margin-bottom: 12px;
    }

    .r-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }

    .r-kv {
        margin-top: 12px;
        display: grid;
        gap: 8px;
    }

    .r-row {
        display: flex;
        gap: 10px;
        align-items: flex-start;
    }

    .r-key {
        flex: 0 0 85px;
        color: #64748b;
        font-weight: 900;
        font-size: 10px;
        text-transform: uppercase;
    }

    .r-val {
        flex: 1 1 auto;
        font-weight: 800;
        color: #111827;
        font-size: 12px;
    }

    .r-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .empty-state {
        text-align: center;
        padding: 30px 12px;
        color: #64748b;
        font-size: 12px;
        font-weight: 900;
    }

    .empty-state i {
        display: block;
        font-size: 34px;
        opacity: .45;
        margin-bottom: 8px;
    }


    .pagination-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 12px;
    }

    .pagination-info {
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
    }

    .pagination-controls {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .page-btn {
        min-width: 34px;
        height: 34px;
        padding: 0 10px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #fff;
        color: #334155;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 900;
    }

    .page-btn:hover {
        background: #f8fafc;
        color: #111827;
        border-color: #cbd5e1;
    }

    .page-btn.active {
        background: #111827;
        border-color: #111827;
        color: #fff;
    }

    .page-btn.disabled {
        opacity: .45;
        pointer-events: none;
    }

    @media(max-width:991.98px) {
        .main {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        .sidebar {
            position: fixed !important;
            transform: translateX(-100%);
            z-index: 1040 !important;
        }

        .sidebar.open,
        .sidebar.active,
        .sidebar.show {
            transform: translateX(0) !important;
        }
    }

    @media(max-width:1199px) {
        .compact-table thead {
            display: none;
        }

        .compact-table,
        .compact-table tbody,
        .compact-table tr,
        .compact-table td {
            display: block;
            width: 100%;
        }

        .compact-table tbody tr {
            border-bottom: 1px solid var(--border);
            padding: 10px;
        }

        .compact-table tbody td {
            border: 0;
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        .compact-table tbody td::before {
            content: attr(data-label);
            font-size: 10px;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            flex: 0 0 100px;
        }

        .compact-table tbody td:first-child {
            display: block;
        }

        .compact-table tbody td:first-child::before {
            display: none;
        }
    }

    @media(max-width:768px) {
        .content-scroll {
            padding: 12px 10px !important;
        }

        .page-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .panel,
        .filter-bar {
            padding: 12px;
        }

        .primary-btn,
        .secondary-btn,
        .btn-action {
            width: 100%;
        }

        .stat-value {
            font-size: 22px;
        }
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

                    <!-- Header -->
                    <div class="page-heading">
                        <div>
                            <h1>My Attendance</h1>
                            <p>Complete attendance record for
                                <?php echo date('F Y', strtotime("$selected_year-$selected_month-01")); ?></p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="punchin.php" class="primary-btn">
                                <i class="bi bi-box-arrow-in-right"></i> Punch In/Out
                            </a>
                            <a href="apply-leave.php" class="secondary-btn">
                                <i class="bi bi-calendar-plus"></i> Apply Leave
                            </a>
                            <a href="my-leave-history.php" class="secondary-btn">
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
                                <label class="form-label">Month</label>
                                <select name="month" class="form-select filter-select"
                                    onchange="this.form.page.value=1; this.form.submit()">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>"
                                        <?php echo $selected_month == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Year</label>
                                <select name="year" class="form-select filter-select"
                                    onchange="this.form.page.value=1; this.form.submit()">
                                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>"
                                        <?php echo $selected_year == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select filter-select"
                                    onchange="this.form.page.value=1; this.form.submit()">
                                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All
                                        Status</option>
                                    <option value="present"
                                        <?php echo $status_filter == 'present' ? 'selected' : ''; ?>>Present</option>
                                    <option value="late" <?php echo $status_filter == 'late' ? 'selected' : ''; ?>>Late
                                    </option>
                                    <option value="absent" <?php echo $status_filter == 'absent' ? 'selected' : ''; ?>>
                                        Absent</option>
                                    <option value="half-day"
                                        <?php echo $status_filter == 'half-day' ? 'selected' : ''; ?>>Half Day</option>
                                    <option value="leave" <?php echo $status_filter == 'leave' ? 'selected' : ''; ?>>
                                        Leave</option>
                                    <option value="holiday"
                                        <?php echo $status_filter == 'holiday' ? 'selected' : ''; ?>>Holiday</option>
                                    <option value="weekly-off"
                                        <?php echo $status_filter == 'weekly-off' ? 'selected' : ''; ?>>Weekly Off
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control filter-input"
                                    placeholder="Date, site, type..." value="<?php echo e($search_term); ?>"
                                    onkeyup="if(event.key === 'Enter') { this.form.page.value=1; this.form.submit(); }">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Per Page</label>
                                <select name="per_page" class="form-select filter-select"
                                    onchange="this.form.page.value=1; this.form.submit()">
                                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                                    <option value="<?php echo $size; ?>"
                                        <?php echo $records_per_page == $size ? 'selected' : ''; ?>>
                                        <?php echo $size; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" name="page" value="<?php echo (int)$current_page; ?>">
                            <div class="col-md-auto">
                                <a href="my-attendance.php?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>&per_page=<?php echo (int)$records_per_page; ?>"
                                    class="secondary-btn"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
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
                                    <div class="stat-value">
                                        <?php echo $monthly_summary['holiday_days'] + $monthly_summary['weekly_off_days']; ?>
                                    </div>
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
                                        <div class="summary-box">
                                            <div class="summary-label">Total Hours</div>
                                            <div class="summary-value">
                                                <?php echo number_format($monthly_summary['total_hours'], 1); ?></div>
                                            <div class="summary-unit">hours</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="summary-box">
                                            <div class="summary-label">Overtime</div>
                                            <div class="summary-value">
                                                <?php echo number_format($monthly_summary['overtime_hours'], 1); ?>
                                            </div>
                                            <div class="summary-unit">hours</div>
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
                                                <div class="progress-bar bg-success"
                                                    style="width: <?php echo $attendance_rate; ?>%"></div>
                                            </div>
                                            <div class="text-muted small mt-2">
                                                Working days: <?php echo $working_days; ?> | Attended:
                                                <?php echo $attended_days; ?>
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
                                    <h6><i class="bi bi-shield-check me-1 text-primary"></i>
                                        <?php echo e($reg['regulation_type']); ?></h6>
                                    <p>Effective: <?php echo e(date('d M Y', strtotime($reg['effective_date']))); ?>
                                        <?php if ($reg['expiry_date']): ?> →
                                        <?php echo e(date('d M Y', strtotime($reg['expiry_date']))); ?><?php endif; ?>
                                    </p>
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
                            <a href="my-leave-history.php" class="btn-action btn-sm">View All <i
                                    class="bi bi-arrow-right"></i></a>
                        </div>
                        <div class="compact-table-wrap d-none d-md-block">
                            <table class="table compact-table mb-0">
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
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state"><i class="bi bi-inbox"></i>No leave requests found.
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach (array_slice($all_leave_requests, 0, 5) as $leave): ?>
                                    <tr>
                                        <td data-label="Leave Type"><span
                                                class="table-primary-text"><?php echo e($leave['leave_type']); ?></span>
                                        </td>
                                        <td data-label="From">
                                            <?php echo e(date('d M Y', strtotime($leave['from_date']))); ?></td>
                                        <td data-label="To">
                                            <?php echo e(date('d M Y', strtotime($leave['to_date']))); ?></td>
                                        <td data-label="Days"><?php echo e($leave['total_days']); ?></td>
                                        <td data-label="Status">
                                            <span class="leave-badge leave-<?php echo strtolower($leave['status']); ?>">
                                                <i
                                                    class="bi bi-<?php echo $leave['status'] == 'Approved' ? 'check-circle' : ($leave['status'] == 'Rejected' ? 'x-circle' : 'hourglass-split'); ?>"></i>
                                                <?php echo e($leave['status']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Applied">
                                            <?php echo e(date('d M Y', strtotime($leave['applied_at'] ?? $leave['created_at']))); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-block d-md-none">
                            <?php if (empty($all_leave_requests)): ?>
                            <div class="empty-state"><i class="bi bi-inbox"></i>No leave requests found.</div>
                            <?php else: ?>
                            <?php foreach (array_slice($all_leave_requests, 0, 5) as $leave): ?>
                            <div class="r-card">
                                <div class="r-top">
                                    <div class="fw-bold"><?php echo e($leave['leave_type']); ?> Leave</div>
                                    <span
                                        class="leave-badge leave-<?php echo strtolower($leave['status']); ?>"><?php echo e($leave['status']); ?></span>
                                </div>
                                <div class="r-kv">
                                    <div class="r-row">
                                        <div class="r-key">Period</div>
                                        <div class="r-val"><?php echo e(date('d M', strtotime($leave['from_date']))); ?>
                                            - <?php echo e(date('d M Y', strtotime($leave['to_date']))); ?></div>
                                    </div>
                                    <div class="r-row">
                                        <div class="r-key">Days</div>
                                        <div class="r-val"><?php echo e($leave['total_days']); ?> day(s)</div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Attendance Records Table -->
                    <div class="panel">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">Daily Attendance Records</h3>
                                <div class="panel-subtitle">Filtered records for the selected month</div>
                            </div>
                            <span class="badge-pill status-secondary"><?php echo (int)$total_filtered_records; ?>
                                records</span>
                        </div>

                        <div class="compact-table-wrap">
                            <table id="attendanceTable" class="table compact-table align-middle mb-0">
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
                                foreach ($paginated_records as $record):
                                    $is_today = $record['attendance_date'] == $today;
                                    $row_class = $is_today ? 'attendance-date-today' : '';
                                ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td data-label="Date">
                                            <span
                                                class="table-primary-text"><?php echo e(date('d M Y', strtotime($record['attendance_date']))); ?></span>
                                        </td>
                                        <td data-label="Day">
                                            <?php echo e(date('D', strtotime($record['attendance_date']))); ?></td>
                                        <td data-label="Punch In">
                                            <?php echo safeTimeOnly($record['punch_in_time'] ?? '', '—'); ?></td>
                                        <td data-label="Punch Out">
                                            <?php echo safeTimeOnly($record['punch_out_time'] ?? '', '—'); ?></td>
                                        <td data-label="Hours">
                                            <?php if ($record['total_hours'] > 0): ?>
                                            <span
                                                class="badge-pill status-secondary"><?php echo e($record['total_hours']); ?>h</span>
                                            <?php else: ?>
                                            —
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Location">
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
                                        <td data-label="Status">
                                            <?php echo getStatusBadge($record['status'], $record['punch_in_time'], $record['is_holiday'], $record['is_weekly_off'], $record['holiday_name']); ?>
                                            <?php if ($record['is_leave'] && $record['leave_type']): ?>
                                            <span
                                                class="table-secondary-text d-inline-block ms-1">(<?php echo e($record['leave_type']); ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($paginated_records)): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No attendance records found for the selected period.
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination-wrap">
                            <div class="pagination-info">
                                Showing <?php echo (int)$pagination_start; ?> to <?php echo (int)$pagination_end; ?>
                                of <?php echo (int)$total_filtered_records; ?> records
                            </div>

                            <div class="pagination-controls">
                                <a class="page-btn <?php echo $current_page <= 1 ? 'disabled' : ''; ?>"
                                    href="<?php echo e(buildPageUrl(1)); ?>" title="First page">
                                    <i class="bi bi-chevron-double-left"></i>
                                </a>

                                <a class="page-btn <?php echo $current_page <= 1 ? 'disabled' : ''; ?>"
                                    href="<?php echo e(buildPageUrl($current_page - 1)); ?>" title="Previous page">
                                    <i class="bi bi-chevron-left"></i>
                                </a>

                                <?php
                                $page_window_start = max(1, $current_page - 2);
                                $page_window_end = min($total_pages, $current_page + 2);

                                if ($page_window_start > 1) {
                                    echo '<span class="page-btn disabled">...</span>';
                                }

                                for ($p = $page_window_start; $p <= $page_window_end; $p++):
                            ?>
                                <a class="page-btn <?php echo $p === $current_page ? 'active' : ''; ?>"
                                    href="<?php echo e(buildPageUrl($p)); ?>">
                                    <?php echo (int)$p; ?>
                                </a>
                                <?php endfor; ?>

                                <?php if ($page_window_end < $total_pages): ?>
                                <span class="page-btn disabled">...</span>
                                <?php endif; ?>

                                <a class="page-btn <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>"
                                    href="<?php echo e(buildPageUrl($current_page + 1)); ?>" title="Next page">
                                    <i class="bi bi-chevron-right"></i>
                                </a>

                                <a class="page-btn <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>"
                                    href="<?php echo e(buildPageUrl($total_pages)); ?>" title="Last page">
                                    <i class="bi bi-chevron-double-right"></i>
                                </a>
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
    document.addEventListener('DOMContentLoaded', function() {
        const yearElement = document.getElementById("year");
        if (yearElement) {
            yearElement.textContent = new Date().getFullYear();
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