<?php
// admin/attendance.php
// Admin Attendance View - employees attendance only, no punch in / punch out

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($date, $format = 'd M Y') {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($date));
}

function safeTime($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '--:--';
    }
    return date('h:i A', strtotime($datetime));
}

function getInitials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }
    return strtoupper(substr($name, 0, 1));
}

function getStatusBadge($status) {
    $status = strtolower(trim((string)$status));
    switch ($status) {
        case 'present':
            return '<span class="status-badge status-present"><i class="bi bi-check-circle"></i> Present</span>';
        case 'late':
            return '<span class="status-badge status-late"><i class="bi bi-clock-history"></i> Late</span>';
        case 'half-day':
        case 'half day':
            return '<span class="status-badge status-half"><i class="bi bi-hourglass-split"></i> Half Day</span>';
        case 'absent':
            return '<span class="status-badge status-absent"><i class="bi bi-x-circle"></i> Absent</span>';
        default:
            return '<span class="status-badge status-neutral"><i class="bi bi-dash-circle"></i> ' . e($status ?: 'No Record') . '</span>';
    }
}

// ---------------- AUTH: ADMIN ONLY ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = (int)$_SESSION['employee_id'];
$current_employee = null;

$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
if ($emp_stmt) {
    mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
    mysqli_stmt_execute($emp_stmt);
    $emp_res = mysqli_stmt_get_result($emp_stmt);
    $current_employee = mysqli_fetch_assoc($emp_res);
    mysqli_stmt_close($emp_stmt);
}

if (!$current_employee) {
    die("Employee not found.");
}

$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department = strtolower(trim($current_employee['department'] ?? ''));
$isAdmin = in_array($designation, ['admin', 'administrator', 'director'], true) || $department === 'admin';

if (!$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access attendance management.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- FILTERS ----------------
$today = date('Y-m-d');
$filter_date = $_GET['date'] ?? $today;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $filter_date = $today;
}

$filter_employee = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$filter_status = strtolower(trim($_GET['status'] ?? 'all'));
$search = trim($_GET['search'] ?? '');

$monthStart = date('Y-m-01', strtotime($filter_date));
$monthEnd = date('Y-m-t', strtotime($filter_date));

// ---------------- EMPLOYEE LIST ----------------
$employees = [];
$emp_res = mysqli_query($conn, "
    SELECT id, full_name, employee_code, designation, department
    FROM employees
    WHERE employee_status = 'active'
    ORDER BY full_name
");
if ($emp_res) {
    $employees = mysqli_fetch_all($emp_res, MYSQLI_ASSOC);
}

// ---------------- DAILY ATTENDANCE ----------------
$where = "WHERE e.employee_status = 'active'";
$params = [];
$types = "";

if ($filter_employee > 0) {
    $where .= " AND e.id = ?";
    $params[] = $filter_employee;
    $types .= "i";
}

if ($search !== '') {
    $where .= " AND (
        e.full_name LIKE ?
        OR e.employee_code LIKE ?
        OR e.designation LIKE ?
        OR e.department LIKE ?
    )";
    $searchLike = '%' . $search . '%';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= "ssss";
}

$statusWhere = "";
if ($filter_status !== 'all' && in_array($filter_status, ['present', 'late', 'half-day', 'absent', 'not-marked'], true)) {
    if ($filter_status === 'not-marked') {
        $statusWhere = " AND a.id IS NULL";
    } elseif ($filter_status === 'absent') {
        $statusWhere = " AND (a.status = 'absent' OR a.id IS NULL)";
    } else {
        $statusWhere = " AND a.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
}

$daily_sql = "
    SELECT
        e.id AS employee_id,
        e.full_name,
        e.employee_code,
        e.designation,
        e.department,
        e.photo,
        a.id AS attendance_id,
        a.attendance_date,
        a.punch_in_time,
        a.punch_out_time,
        a.punch_in_type,
        a.punch_in_location,
        a.punch_out_location,
        a.total_hours,
        a.status,
        a.is_vacation,
        s.project_name AS site_name,
        o.location_name AS office_name
    FROM employees e
    LEFT JOIN attendance a
        ON a.employee_id = e.id
        AND a.attendance_date = ?
    LEFT JOIN sites s ON a.punch_in_site_id = s.id
    LEFT JOIN office_locations o ON a.punch_in_office_id = o.id
    $where
    $statusWhere
    ORDER BY e.full_name ASC
";

$daily_params = array_merge([$filter_date], $params);
$daily_types = "s" . $types;

$daily_attendance = [];
$stmt = mysqli_prepare($conn, $daily_sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $daily_types, ...$daily_params);
    mysqli_stmt_execute($stmt);
    $daily_res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($daily_res)) {
        $daily_attendance[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// ---------------- MONTHLY SUMMARY ----------------
$summary_where = "WHERE e.employee_status = 'active'";
$summary_params = [$monthStart, $monthEnd];
$summary_types = "ss";

if ($filter_employee > 0) {
    $summary_where .= " AND e.id = ?";
    $summary_params[] = $filter_employee;
    $summary_types .= "i";
}

if ($search !== '') {
    $summary_where .= " AND (
        e.full_name LIKE ?
        OR e.employee_code LIKE ?
        OR e.designation LIKE ?
        OR e.department LIKE ?
    )";
    $searchLike = '%' . $search . '%';
    $summary_params[] = $searchLike;
    $summary_params[] = $searchLike;
    $summary_params[] = $searchLike;
    $summary_params[] = $searchLike;
    $summary_types .= "ssss";
}

$summary_sql = "
    SELECT
        e.id,
        e.full_name,
        e.employee_code,
        e.designation,
        e.department,
        COUNT(CASE WHEN a.status = 'present' THEN 1 END) AS present_days,
        COUNT(CASE WHEN a.status = 'late' THEN 1 END) AS late_days,
        COUNT(CASE WHEN a.status IN ('half-day', 'half day') THEN 1 END) AS half_days,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) AS absent_days,
        COUNT(CASE WHEN a.is_vacation = 1 THEN 1 END) AS vacation_days,
        COALESCE(SUM(a.total_hours), 0) AS total_hours
    FROM employees e
    LEFT JOIN attendance a
        ON e.id = a.employee_id
        AND a.attendance_date BETWEEN ? AND ?
    $summary_where
    GROUP BY e.id
    ORDER BY e.full_name ASC
";

$monthly_summary = [];
$summary_stmt = mysqli_prepare($conn, $summary_sql);
if ($summary_stmt) {
    mysqli_stmt_bind_param($summary_stmt, $summary_types, ...$summary_params);
    mysqli_stmt_execute($summary_stmt);
    $summary_res = mysqli_stmt_get_result($summary_stmt);
    while ($row = mysqli_fetch_assoc($summary_res)) {
        $monthly_summary[] = $row;
    }
    mysqli_stmt_close($summary_stmt);
}

// ---------------- STATS ----------------
$totalEmployees = count($employees);
$presentToday = 0;
$workingNow = 0;
$lateToday = 0;
$notMarked = 0;

foreach ($daily_attendance as $att) {
    if (!empty($att['attendance_id'])) {
        if (in_array(($att['status'] ?? ''), ['present', 'late'], true)) {
            $presentToday++;
        }

        if (!empty($att['punch_in_time']) && empty($att['punch_out_time'])) {
            $workingNow++;
        }

        if (($att['status'] ?? '') === 'late') {
            $lateToday++;
        } elseif (!empty($att['punch_in_time']) && strtotime($att['punch_in_time']) > strtotime($filter_date . ' 09:15:00')) {
            $lateToday++;
        }
    } else {
        $notMarked++;
    }
}

$loggedName = $_SESSION['employee_name'] ?? ($current_employee['full_name'] ?? 'Admin');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Employee Attendance - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        :root {
            --page-bg: #f5f7fb;
            --card-bg: #ffffff;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #64748b;
            --soft: #f8fafc;
            --shadow: 0 10px 26px rgba(15, 23, 42, .055);
            --radius: 15px;
        }

        body { background: var(--page-bg); }

        .content-scroll {
            flex: 1 1 auto;
            overflow: auto;
            padding: 16px;
        }

        .attendance-wrapper { width: 100%; }

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

        .primary-btn {
            border: 0;
            background: #111827;
            color: #fff;
            height: 36px;
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

        .primary-btn:hover { background: #020617; color: #fff; }

        .secondary-btn {
            border: 1px solid var(--border) !important;
            background: #fff !important;
            color: #334155 !important;
            height: 36px;
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

        .secondary-btn:hover {
            border-color: #cbd5e1 !important;
            background: #f8fafc !important;
            color: #111827 !important;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 12px 13px;
            min-height: 78px;
            display: flex;
            align-items: center;
            gap: 11px;
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

        .blue { background: #2f80ed; }
        .green { background: #27ae60; }
        .orange { background: #f2994a; }
        .red { background: #eb5757; }
        .gray { background: #64748b; }

        .stat-label {
            color: var(--muted);
            font-weight: 800;
            font-size: 10.5px;
            text-transform: uppercase;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 950;
            color: #111827;
            line-height: 1;
        }

        .panel {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 13px;
            margin-bottom: 14px;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            gap: 10px;
        }

        .panel-title {
            font-weight: 900;
            font-size: 14px;
            margin: 0;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .panel-title i {
            color: #2563eb;
            font-size: 14px;
        }

        .panel-subtitle {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            margin-top: 2px;
        }

        .filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .search-box {
            position: relative;
            flex: 1 1 260px;
            max-width: 430px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 13px;
        }

        .search-box input,
        .filter-select,
        .filter-date {
            height: 36px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: #fff;
            font-size: 12px;
            font-weight: 800;
            color: var(--text);
            outline: none;
        }

        .search-box input {
            width: 100%;
            padding: 0 12px 0 34px;
            font-weight: 700;
        }

        .filter-select {
            padding: 0 42px 0 12px;
            min-width: 150px;
        }

        .filter-date {
            padding: 0 12px;
            min-width: 150px;
        }

        .search-box input:focus,
        .filter-select:focus,
        .filter-date:focus {
            border-color: #bfdbfe;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
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
            padding: 8px 9px !important;
            white-space: nowrap;
        }

        .compact-table tbody td {
            padding: 9px !important;
            vertical-align: middle;
            border-color: #eef2f7;
            color: #334155;
            font-weight: 700;
            font-size: 11.5px;
        }

        .compact-table tbody tr:hover { background: #fbfdff; }

        .employee-cell {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .employee-avatar {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: #eff6ff;
            color: #2563eb;
            font-weight: 950;
            font-size: 12px;
            flex: 0 0 auto;
            overflow: hidden;
        }

        .employee-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .employee-name {
            color: #111827;
            font-weight: 900;
            font-size: 12px;
        }

        .employee-meta {
            color: #64748b;
            font-size: 10px;
            font-weight: 700;
            margin-top: 1px;
        }

        .status-badge,
        .attendance-badge,
        .location-badge {
            border-radius: 999px;
            padding: 5px 8px;
            font-weight: 900;
            font-size: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
            white-space: nowrap;
            text-transform: uppercase;
        }

        .status-present,
        .badge-present {
            color: #15803d;
            background: #dcfce7;
            border-color: #bbf7d0;
        }

        .status-late,
        .badge-late {
            color: #b45309;
            background: #ffedd5;
            border-color: #fed7aa;
        }

        .status-half,
        .badge-half {
            color: #6d28d9;
            background: #ede9fe;
            border-color: #ddd6fe;
        }

        .status-absent,
        .badge-absent {
            color: #dc2626;
            background: #fee2e2;
            border-color: #fecaca;
        }

        .status-neutral,
        .badge-vacation {
            color: #475569;
            background: #f1f5f9;
            border-color: #e2e8f0;
        }

        .location-badge {
            color: #475569;
            background: #f8fafc;
            border-color: #e2e8f0;
            text-transform: none;
        }

        .action-btn {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: #fff;
            display: inline-grid;
            place-items: center;
            text-decoration: none;
            color: #475569;
        }

        .action-btn:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #111827;
        }

        .empty-state {
            text-align: center;
            color: #64748b;
            padding: 30px 12px;
            font-size: 12px;
            font-weight: 900;
        }

        .empty-state i {
            font-size: 34px;
            display: block;
            margin-bottom: 8px;
            opacity: .45;
        }

        .pagination-info {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            padding-top: 10px;
        }

        .alert {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
            margin-bottom: 14px;
        }

        .dataTables_wrapper .row {
            margin-left: 0 !important;
            margin-right: 0 !important;
        }

        .dataTables_filter input,
        .dataTables_length select {
            border: 1px solid var(--border) !important;
            border-radius: 9px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
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
            .compact-table-wrap {
                border: 0;
                border-radius: 0;
                overflow: visible;
                background: transparent;
            }

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
                background: #fff;
                border: 1px solid var(--border);
                border-radius: 14px;
                box-shadow: 0 8px 22px rgba(15, 23, 42, .045);
                padding: 12px;
                margin-bottom: 12px;
                overflow: hidden;
            }

            .compact-table tbody td {
                border: 0 !important;
                display: grid !important;
                grid-template-columns: 95px minmax(0, 1fr);
                column-gap: 10px;
                align-items: flex-start;
                padding: 8px 0 !important;
                text-align: left !important;
            }

            .compact-table tbody td::before {
                content: attr(data-label);
                color: #64748b;
                font-size: 10px;
                font-weight: 950;
                text-transform: uppercase;
                line-height: 1.25;
                padding-top: 2px;
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

            .filter-bar {
                align-items: stretch;
            }

            .search-box,
            .filter-select,
            .filter-date,
            .primary-btn,
            .secondary-btn {
                width: 100%;
                max-width: none;
            }

            .panel {
                padding: 12px;
            }

            .compact-table tbody td {
                grid-template-columns: 86px minmax(0, 1fr);
            }
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid attendance-wrapper px-0">

                <div class="page-heading">
                    <div>
                        <h1>Employee Attendance</h1>
                        <p>Admin view for employee attendance records and working hours</p>
                    </div>
                    <!-- <div class="d-flex gap-2 flex-wrap">
                        <button class="secondary-btn" data-bs-toggle="modal" data-bs-target="#attendanceReportModal">
                            <i class="bi bi-download"></i>
                            Export Report
                        </button>
                    </div> -->
                </div>

                <?php if (!empty($_SESSION['flash_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-people"></i></div>
                            <div>
                                <div class="stat-label">Total Employees</div>
                                <div class="stat-value"><?php echo (int)$totalEmployees; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                            <div>
                                <div class="stat-label">Present</div>
                                <div class="stat-value"><?php echo (int)$presentToday; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic orange"><i class="bi bi-clock-history"></i></div>
                            <div>
                                <div class="stat-label">Late</div>
                                <div class="stat-value"><?php echo (int)$lateToday; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-dash-circle"></i></div>
                            <div>
                                <div class="stat-label">Not Marked</div>
                                <div class="stat-value"><?php echo (int)$notMarked; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">
                                <i class="bi bi-calendar-check"></i>
                                Employees Attendance
                            </h3>
                            <div class="panel-subtitle">
                                Showing attendance for <?php echo e(safeDate($filter_date)); ?>
                            </div>
                        </div>
                        <span class="location-badge">
                            <i class="bi bi-person-badge"></i>
                            Admin
                        </span>
                    </div>

                    <form method="GET" class="filter-bar">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" placeholder="Search employee, code, designation..." value="<?php echo e($search); ?>">
                        </div>

                        <input type="date" name="date" class="filter-date" value="<?php echo e($filter_date); ?>">

                        <select class="filter-select" name="employee_id">
                            <option value="0">All Employees</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int)$emp['id']; ?>" <?php echo $filter_employee === (int)$emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($emp['full_name']); ?><?php echo !empty($emp['employee_code']) ? ' - ' . e($emp['employee_code']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select class="filter-select" name="status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="present" <?php echo $filter_status === 'present' ? 'selected' : ''; ?>>Present</option>
                            <option value="late" <?php echo $filter_status === 'late' ? 'selected' : ''; ?>>Late</option>
                            <option value="half-day" <?php echo $filter_status === 'half-day' ? 'selected' : ''; ?>>Half Day</option>
                            <option value="absent" <?php echo $filter_status === 'absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="not-marked" <?php echo $filter_status === 'not-marked' ? 'selected' : ''; ?>>Not Marked</option>
                        </select>

                        <button type="submit" class="primary-btn">
                            <i class="bi bi-funnel"></i>
                            Filter
                        </button>

                        <a href="attendance.php" class="secondary-btn">
                            <i class="bi bi-x-circle"></i>
                            Clear
                        </a>
                    </form>

                    <div class="compact-table-wrap">
                        <table class="table compact-table align-middle" id="dailyAttendanceTable">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Punch In</th>
                                    <th>Punch Out</th>
                                    <th>Total Hours</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($daily_attendance)): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No attendance records found.
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($daily_attendance as $att): ?>
                                        <?php
                                            $hasRecord = !empty($att['attendance_id']);
                                            $displayStatus = $hasRecord ? ($att['status'] ?: 'present') : 'not marked';
                                            $locationName = '-';

                                            if ($hasRecord) {
                                                if (($att['punch_in_type'] ?? '') === 'site') {
                                                    $locationName = $att['site_name'] ?: 'Site';
                                                } elseif (($att['punch_in_type'] ?? '') === 'office') {
                                                    $locationName = $att['office_name'] ?: 'Office';
                                                } elseif (!empty($att['punch_in_location'])) {
                                                    $locationName = $att['punch_in_location'];
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td data-label="Employee">
                                                <div class="employee-cell">
                                                    <div class="employee-avatar">
                                                        <?php if (!empty($att['photo'])): ?>
                                                            <img src="../<?php echo e($att['photo']); ?>" alt="Photo">
                                                        <?php else: ?>
                                                            <?php echo e(getInitials($att['full_name'])); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="employee-name"><?php echo e($att['full_name']); ?></div>
                                                        <div class="employee-meta"><?php echo e($att['employee_code']); ?> • <?php echo e($att['designation']); ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td data-label="Department">
                                                <?php echo e($att['department'] ?: '-'); ?>
                                            </td>

                                            <td data-label="Punch In">
                                                <div class="employee-name"><?php echo $hasRecord ? e(safeTime($att['punch_in_time'])) : '--:--'; ?></div>
                                                <?php if ($hasRecord): ?>
                                                    <div class="employee-meta"><?php echo e(safeDate($att['punch_in_time'])); ?></div>
                                                <?php endif; ?>
                                            </td>

                                            <td data-label="Punch Out">
                                                <div class="employee-name"><?php echo $hasRecord ? e(safeTime($att['punch_out_time'])) : '--:--'; ?></div>
                                                <?php if ($hasRecord && empty($att['punch_out_time'])): ?>
                                                    <span class="attendance-badge badge-late">Working</span>
                                                <?php endif; ?>
                                            </td>

                                            <td data-label="Total Hours">
                                                <strong>
                                                    <?php
                                                        if ($hasRecord && $att['total_hours'] !== null && $att['total_hours'] !== '') {
                                                            echo e(number_format((float)$att['total_hours'], 2)) . ' hrs';
                                                        } else {
                                                            echo '0.00 hrs';
                                                        }
                                                    ?>
                                                </strong>
                                            </td>

                                            <td data-label="Location">
                                                <span class="location-badge">
                                                    <i class="bi bi-geo-alt"></i>
                                                    <?php echo e($locationName); ?>
                                                </span>
                                            </td>

                                            <td data-label="Status">
                                                <?php
                                                    if (!$hasRecord) {
                                                        echo '<span class="status-badge status-neutral"><i class="bi bi-dash-circle"></i> Not Marked</span>';
                                                    } else {
                                                        echo getStatusBadge($displayStatus);
                                                    }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-info">
                        Showing <?php echo count($daily_attendance); ?> employee attendance row(s)
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">
                                <i class="bi bi-calendar3"></i>
                                Monthly Attendance Summary
                            </h3>
                            <div class="panel-subtitle">
                                <?php echo e(date('F Y', strtotime($monthStart))); ?>
                            </div>
                        </div>
                    </div>

                    <div class="compact-table-wrap">
                        <table class="table compact-table align-middle" id="summaryTable">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Present</th>
                                    <th>Late</th>
                                    <th>Half Day</th>
                                    <th>Absent</th>
                                    <th>Vacation</th>
                                    <th>Total Hours</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($monthly_summary)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No monthly summary found.
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($monthly_summary as $summary): ?>
                                        <tr>
                                            <td data-label="Employee">
                                                <div class="employee-cell">
                                                    <div class="employee-avatar"><?php echo e(getInitials($summary['full_name'])); ?></div>
                                                    <div>
                                                        <div class="employee-name"><?php echo e($summary['full_name']); ?></div>
                                                        <div class="employee-meta"><?php echo e($summary['employee_code']); ?> • <?php echo e($summary['designation']); ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td data-label="Present"><span class="attendance-badge badge-present"><?php echo (int)($summary['present_days'] ?? 0); ?></span></td>
                                            <td data-label="Late"><span class="attendance-badge badge-late"><?php echo (int)($summary['late_days'] ?? 0); ?></span></td>
                                            <td data-label="Half Day"><span class="attendance-badge badge-half"><?php echo (int)($summary['half_days'] ?? 0); ?></span></td>
                                            <td data-label="Absent"><span class="attendance-badge badge-absent"><?php echo (int)($summary['absent_days'] ?? 0); ?></span></td>
                                            <td data-label="Vacation"><span class="attendance-badge badge-vacation"><?php echo (int)($summary['vacation_days'] ?? 0); ?></span></td>
                                            <td data-label="Total Hours"><strong><?php echo e(number_format((float)($summary['total_hours'] ?? 0), 2)); ?> hrs</strong></td>
                                            <td data-label="Actions">
                                                <a href="employee-attendance.php?id=<?php echo (int)$summary['id']; ?>&date=<?php echo e($filter_date); ?>" class="action-btn" title="View">
                                                    <i class="bi bi-calendar-week"></i>
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

<!-- Export Report Modal -->
<div class="modal fade" id="attendanceReportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="export-attendance.php">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Export Attendance Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Report Type</label>
                        <select class="form-control" name="report_type" required>
                            <option value="daily">Daily Report</option>
                            <option value="monthly">Monthly Summary</option>
                            <option value="employee">Employee Wise Report</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Format</label>
                        <select class="form-control" name="format" required>
                            <option value="excel">Excel (CSV)</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="from_date" value="<?php echo e($monthStart); ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="to_date" value="<?php echo e($monthEnd); ?>">
                        </div>
                    </div>

                    <input type="hidden" name="employee_id" value="<?php echo (int)$filter_employee; ?>">
                </div>

                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="primary-btn">
                        <i class="bi bi-download"></i>
                        Export
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(document).ready(function() {
    $('#dailyAttendanceTable').DataTable({
        responsive: true,
        autoWidth: false,
        pageLength: 25,
        order: [[0, 'asc']],
        language: {
            searchPlaceholder: "Search attendance...",
            zeroRecords: "No matching attendance records found"
        }
    });

    $('#summaryTable').DataTable({
        responsive: true,
        autoWidth: false,
        pageLength: 25,
        order: [[0, 'asc']],
        language: {
            searchPlaceholder: "Search summary...",
            zeroRecords: "No matching summary found"
        }
    });
});
</script>
</body>
</html>
