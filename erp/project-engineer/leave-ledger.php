<?php
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

// Get current user from session
$current_employee_id = (int)($_SESSION['employee_id'] ?? 0);
$current_employee_name = (string)($_SESSION['employee_name'] ?? '');
$current_employee_role = (string)($_SESSION['user_role'] ?? $_SESSION['designation'] ?? '');

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

// Determine user role and permissions
$is_hr = in_array(strtolower(trim($employee['designation'])), ['hr', 'human resources', 'hr manager'], true);
$is_director = in_array(strtolower(trim($employee['designation'])), ['director', 'vice president', 'general manager'], true);
$is_manager = in_array(strtolower(trim($employee['designation'])), ['manager', 'team lead', 'project manager'], true);
$has_full_access = $is_hr || $is_director; // HR and Director have full access to view all employees

// Get selected employee for HR/Director view
$selected_employee_id = $current_employee_id;
$selected_employee_name = $employee['full_name'];
$selected_employee_code = $employee['employee_code'];

if ($has_full_access && isset($_GET['employee_id']) && $_GET['employee_id'] > 0) {
    $selected_employee_id = (int)$_GET['employee_id'];
    $emp_stmt = mysqli_prepare($conn, "SELECT full_name, employee_code, designation FROM employees WHERE id = ?");
    mysqli_stmt_bind_param($emp_stmt, "i", $selected_employee_id);
    mysqli_stmt_execute($emp_stmt);
    $emp_res = mysqli_stmt_get_result($emp_stmt);
    $selected_emp = mysqli_fetch_assoc($emp_res);
    if ($selected_emp) {
        $selected_employee_name = $selected_emp['full_name'];
        $selected_employee_code = $selected_emp['employee_code'];
    }
    mysqli_stmt_close($emp_stmt);
}

// Get current year
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$available_years = [];
for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++) {
    $available_years[] = $y;
}

// Define leave quotas (annual entitlement)
$leave_quotas = [
    'SL' => ['name' => 'Sick Leave', 'quota' => 12, 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.1)'],
    'CL' => ['name' => 'Casual Leave', 'quota' => 12, 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.1)'],
    'EL' => ['name' => 'Earned Leave', 'quota' => 15, 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.1)'],
    'LOP' => ['name' => 'Loss of Pay', 'quota' => 0, 'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.1)'],
    'OD' => ['name' => 'Official Duty', 'quota' => 0, 'color' => '#8b5cf6', 'bg' => 'rgba(139,92,246,0.1)']
];

// Calculate used leaves from approved requests for selected employee and year
$used_leaves_query = "
    SELECT 
        leave_type,
        SUM(total_days) as used_days
    FROM leave_requests
    WHERE employee_id = ?
      AND status = 'Approved'
      AND YEAR(from_date) = ?
    GROUP BY leave_type
";

$used_stmt = mysqli_prepare($conn, $used_leaves_query);
mysqli_stmt_bind_param($used_stmt, "ii", $selected_employee_id, $current_year);
mysqli_stmt_execute($used_stmt);
$used_res = mysqli_stmt_get_result($used_stmt);
$used_leaves = [];
while ($row = mysqli_fetch_assoc($used_res)) {
    $used_leaves[$row['leave_type']] = (float)$row['used_days'];
}
mysqli_stmt_close($used_stmt);

// Calculate pending leaves (not yet approved) for the current year
$pending_leaves_query = "
    SELECT 
        leave_type,
        SUM(total_days) as pending_days
    FROM leave_requests
    WHERE employee_id = ?
      AND status = 'Pending'
      AND YEAR(from_date) = ?
    GROUP BY leave_type
";

$pending_stmt = mysqli_prepare($conn, $pending_leaves_query);
mysqli_stmt_bind_param($pending_stmt, "ii", $selected_employee_id, $current_year);
mysqli_stmt_execute($pending_stmt);
$pending_res = mysqli_stmt_get_result($pending_stmt);
$pending_leaves = [];
while ($row = mysqli_fetch_assoc($pending_res)) {
    $pending_leaves[$row['leave_type']] = (float)$row['pending_days'];
}
mysqli_stmt_close($pending_stmt);

// Get all leave requests for the selected employee (for transaction history)
$requests_query = "
    SELECT 
        lr.*,
        e.full_name as approved_by_name,
        re.full_name as rejected_by_name
    FROM leave_requests lr
    LEFT JOIN employees e ON lr.approved_by = e.id
    LEFT JOIN employees re ON lr.rejected_by = re.id
    WHERE lr.employee_id = ?
    ORDER BY lr.created_at DESC
    LIMIT 100
";

$requests_stmt = mysqli_prepare($conn, $requests_query);
mysqli_stmt_bind_param($requests_stmt, "i", $selected_employee_id);
mysqli_stmt_execute($requests_stmt);
$requests_res = mysqli_stmt_get_result($requests_stmt);
$leave_requests = mysqli_fetch_all($requests_res, MYSQLI_ASSOC);
mysqli_stmt_close($requests_stmt);

// Fetch all employees for HR/Director dropdown
$all_employees = [];
if ($has_full_access) {
    $emp_list_query = "SELECT id, full_name, employee_code, designation FROM employees WHERE employee_status = 'active' ORDER BY full_name";
    $emp_list_stmt = mysqli_prepare($conn, $emp_list_query);
    mysqli_stmt_execute($emp_list_stmt);
    $emp_list_res = mysqli_stmt_get_result($emp_list_stmt);
    $all_employees = mysqli_fetch_all($emp_list_res, MYSQLI_ASSOC);
    mysqli_stmt_close($emp_list_stmt);
}

// Helper functions
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') return '—';
    return date('d M Y', strtotime($date));
}
function formatDateTime($date) {
    if (empty($date) || $date === '0000-00-00 00:00:00') return '—';
    return date('d M Y, h:i A', strtotime($date));
}
function getStatusBadge($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'approved') {
        return '<span class="status-badge status-green"><i class="bi bi-check2-circle"></i> Approved</span>';
    } elseif ($s === 'pending') {
        return '<span class="status-badge status-yellow"><i class="bi bi-hourglass-split"></i> Pending</span>';
    } elseif ($s === 'rejected') {
        return '<span class="status-badge status-red"><i class="bi bi-x-circle"></i> Rejected</span>';
    }
    return '<span class="status-badge status-gray">' . e($status) . '</span>';
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
    <title>Leave Ledger - TEK-C</title>

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
        --purple: #7c3aed;
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
    .secondary-btn {
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
        border: 0;
    }

    .primary-btn {
        background: #111827;
        color: #fff;
    }

    .primary-btn:hover {
        background: #020617;
        color: #fff;
    }

    .secondary-btn {
        border: 1px solid var(--border);
        background: #fff;
        color: #334155;
    }

    .secondary-btn:hover {
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
    }

    .panel-header,
    .sec-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .sec-head {
        justify-content: flex-start;
        padding-bottom: 10px;
        border-bottom: 1px solid #eef2f7;
    }

    .sec-ic {
        width: 34px;
        height: 34px;
        border-radius: 11px;
        display: grid;
        place-items: center;
        background: #eff6ff;
        color: #2563eb;
        flex: 0 0 auto;
        font-size: 15px;
    }

    .sec-title,
    .panel-title {
        font-weight: 900;
        font-size: 14px;
        color: var(--text);
        margin: 0;
    }

    .sec-sub,
    .panel-subtitle {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
    }

    .filter-bar {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 13px;
        margin-bottom: 14px;
    }

    .filter-label {
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
        width: 100%;
    }

    .filter-select:focus,
    .filter-input:focus,
    .form-select:focus,
    .form-control:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
        outline: none;
    }

    .badge-pill,
    .role-badge,
    .status-badge {
        border-radius: 999px;
        padding: 5px 8px;
        font-weight: 900;
        font-size: 10px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid transparent;
        text-decoration: none;
        white-space: nowrap;
    }

    .role-badge {
        color: #475569;
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .badge-pill {
        color: #475569;
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .status-green {
        color: #15803d;
        background: #dcfce7;
        border-color: #bbf7d0;
    }

    .status-yellow {
        color: #a16207;
        background: #fef3c7;
        border-color: #fde68a;
    }

    .status-red {
        color: #b91c1c;
        background: #fee2e2;
        border-color: #fecaca;
    }

    .status-gray {
        color: #475569;
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .balance-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 12px 13px;
        min-height: 126px;
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .balance-card h6 {
        color: var(--muted);
        font-weight: 900;
        font-size: 10.5px;
        text-transform: uppercase;
        margin: 0;
        line-height: 1.25;
    }

    .balance-value {
        font-size: 24px;
        font-weight: 950;
        color: #111827;
        line-height: 1;
    }

    .balance-label {
        color: #64748b;
        font-size: 10.5px;
        font-weight: 750;
    }

    .used-info {
        border-top: 1px solid #eef2f7;
        margin-top: 5px;
        padding-top: 7px;
        color: #64748b;
        font-size: 10.5px;
        font-weight: 800;
    }

    .pending-info {
        color: #a16207;
        font-size: 10.5px;
        font-weight: 850;
    }

    .summary-box {
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 13px;
        padding: 12px;
        text-align: center;
    }

    .summary-label {
        color: #64748b;
        font-size: 10.5px;
        font-weight: 900;
        text-transform: uppercase;
    }

    .summary-value {
        color: #111827;
        font-size: 24px;
        font-weight: 950;
        line-height: 1;
    }

    .progress {
        height: 8px;
        border-radius: 999px;
        background: #e5e7eb;
    }

    .progress-bar {
        background: #111827;
        border-radius: 999px;
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

    .reason-cell {
        max-width: 250px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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

    .alert {
        border-radius: var(--radius);
        border: 0;
        box-shadow: var(--shadow);
        margin-bottom: 14px;
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
            display: grid;
            grid-template-columns: 104px minmax(0, 1fr);
            gap: 12px;
            align-items: start;
            text-align: left !important;
        }

        .compact-table tbody td::before {
            content: attr(data-label);
            font-size: 10px;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            line-height: 1.35;
        }

        .compact-table tbody td:first-child {
            display: block;
        }

        .compact-table tbody td:first-child::before {
            display: none;
        }

        .reason-cell {
            max-width: none;
            white-space: normal;
            text-align: left;
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

        .page-heading>.d-flex {
            width: 100%;
        }

        .primary-btn,
        .secondary-btn {
            width: 100%;
        }

        .panel {
            padding: 12px;
        }

        .filter-bar .primary-btn,
        .filter-bar .secondary-btn {
            width: 100%;
        }

        .balance-card {
            min-height: 118px;
        }

        .summary-value {
            font-size: 20px;
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

                    <!-- Page Header -->
                    <div class="page-heading">
                        <div>
                            <h1>Leave Ledger</h1>
                            <p>
                                <?php if ($has_full_access): ?>
                                View leave balances for all employees
                                <?php else: ?>
                                View your leave balance and request history
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="role-badge">
                                <i class="bi bi-<?php echo $has_full_access ? 'shield-check' : 'person-badge'; ?>"></i>
                                <?php echo $has_full_access ? 'HR/Director Access' : 'Employee View'; ?>
                            </span>
                            <a href="apply-leave.php" class="secondary-btn">
                                <i class="bi bi-calendar-plus"></i> Apply Leave
                            </a>
                            <a href="my-leave-history.php" class="secondary-btn">
                                <i class="bi bi-clock-history"></i> Leave History
                            </a>
                        </div>
                    </div>

                    <!-- Flash Messages -->
                    <?php if ($flash_success): ?>
                    <div class="alert alert-success border-0 rounded-3 shadow-sm mb-4" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo e($flash_success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($flash_error): ?>
                    <div class="alert alert-danger border-0 rounded-3 shadow-sm mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($flash_error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Filter Bar matching history page -->
                    <div class="filter-bar">
                        <form method="GET" class="row g-3 align-items-end">
                            <?php if ($has_full_access): ?>
                            <div class="col-md-4">
                                <div class="filter-label">Select Employee</div>
                                <select name="employee_id" class="filter-select" onchange="this.form.submit()">
                                    <option value="">-- Select Employee --</option>
                                    <?php foreach ($all_employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"
                                        <?php echo $selected_employee_id == $emp['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($emp['full_name']); ?> (<?php echo e($emp['employee_code']); ?>) -
                                        <?php echo e($emp['designation']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="<?php echo $has_full_access ? 'col-md-3' : 'col-md-4'; ?>">
                                <div class="filter-label">Year</div>
                                <select name="year" class="filter-select" onchange="this.form.submit()">
                                    <?php foreach ($available_years as $year): ?>
                                    <option value="<?php echo $year; ?>"
                                        <?php echo $current_year == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($has_full_access): ?>
                            <div class="col-md-5">
                                <div class="filter-label">&nbsp;</div>
                                <a href="apply-leave.php?employee_id=<?php echo $selected_employee_id; ?>"
                                    class="primary-btn">
                                    <i class="bi bi-plus-circle"></i> Apply Leave for Employee
                                </a>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Employee Info Panel -->
                    <div class="panel mb-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h5 class="fw-bold mb-0" style="font-weight:1000;">
                                    <?php echo e($selected_employee_name); ?></h5>
                                <p class="text-muted small mb-0" style="font-weight:800;">
                                    <?php echo e($selected_employee_code); ?> • Leave Balance for
                                    <?php echo $current_year; ?></p>
                            </div>
                            <div>
                                <span class="badge-pill">
                                    <i class="bi bi-calendar3"></i> Last updated: <?php echo date('d M Y'); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Leave Balance Cards - Matching stat-card style -->
                    <div class="row g-3 mb-4">
                        <?php foreach ($leave_quotas as $code => $type): 
                        $quota = $type['quota'];
                        $used = $used_leaves[$code] ?? 0;
                        $pending = $pending_leaves[$code] ?? 0;
                        $remaining = max(0, $quota - $used);
                    ?>
                        <div class="col-6 col-md-4 col-lg-2">
                            <div class="balance-card">
                                <h6><?php echo e($type['name']); ?> (<?php echo e($code); ?>)</h6>
                                <div class="balance-value" style="color: <?php echo $type['color']; ?>;">
                                    <?php echo number_format($remaining, 1); ?>
                                </div>
                                <div class="balance-label">days available</div>
                                <?php if ($quota > 0): ?>
                                <div class="used-info">
                                    Used: <?php echo number_format($used, 1); ?> /
                                    <?php echo number_format($quota, 1); ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($pending > 0): ?>
                                <div class="pending-info">
                                    <i class="bi bi-hourglass-split"></i> Pending:
                                    <?php echo number_format($pending, 1); ?> days
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Leave Summary Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="panel">
                                <div class="sec-head">
                                    <div class="sec-ic"><i class="bi bi-pie-chart"></i></div>
                                    <div>
                                        <p class="sec-title mb-0">Leave Summary</p>
                                        <p class="sec-sub mb-0"><?php echo $current_year; ?> overview</p>
                                    </div>
                                </div>

                                <?php 
                            $total_quota = 0;
                            $total_used = 0;
                            $total_pending = 0;
                            foreach ($leave_quotas as $code => $type):
                                if ($type['quota'] > 0):
                                    $total_quota += $type['quota'];
                                    $total_used += ($used_leaves[$code] ?? 0);
                                    $total_pending += ($pending_leaves[$code] ?? 0);
                                endif;
                            endforeach;
                            $total_remaining = max(0, $total_quota - $total_used);
                            $utilization = $total_quota > 0 ? round(($total_used / $total_quota) * 100, 1) : 0;
                            ?>

                                <div class="row g-3 mb-3">
                                    <div class="col-6">
                                        <div class="summary-box">
                                            <div class="summary-label">Total Quota</div>
                                            <div class="summary-value"><?php echo number_format($total_quota, 1); ?>
                                            </div>
                                            <div class="text-muted small">days</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="summary-box">
                                            <div class="summary-label">Used This Year</div>
                                            <div class="summary-value"><?php echo number_format($total_used, 1); ?>
                                            </div>
                                            <div class="text-muted small">days</div>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <div class="d-flex justify-content-between small fw-bold mb-1">
                                        <span>Utilization Rate</span>
                                        <span><?php echo $utilization; ?>%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: <?php echo $utilization; ?>%"></div>
                                    </div>
                                    <div class="text-muted small mt-3">
                                        <i class="bi bi-calendar-check"></i> Remaining:
                                        <?php echo number_format($total_remaining, 1); ?> days
                                        <?php if ($total_pending > 0): ?>
                                        <br><span class="text-warning"><i class="bi bi-hourglass-split"></i> Pending
                                            approval: <?php echo number_format($total_pending, 1); ?> days</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="panel">
                                <div class="sec-head">
                                    <div class="sec-ic"><i class="bi bi-table"></i></div>
                                    <div>
                                        <p class="sec-title mb-0">Leave Policy</p>
                                        <p class="sec-sub mb-0">Annual entitlement breakdown</p>
                                    </div>
                                </div>

                                <div class="compact-table-wrap">
                                    <table class="table compact-table align-middle">
                                        <thead>
                                            <tr>
                                                <th style="font-size:11px;">Leave Type</th>
                                                <th style="font-size:11px;">Annual Quota</th>
                                                <th style="font-size:11px;">Used</th>
                                                <th style="font-size:11px;">Remaining</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($leave_quotas as $code => $type): ?>
                                            <?php if ($type['quota'] > 0): ?>
                                            <?php $used = $used_leaves[$code] ?? 0; ?>
                                            <tr>
                                                <td data-label="Leave Type" style="font-weight:800;">
                                                    <?php echo e($type['name']); ?> (<?php echo e($code); ?>)</td>
                                                <td data-label="Annual Quota">
                                                    <?php echo number_format($type['quota'], 1); ?></td>
                                                <td data-label="Used"><?php echo number_format($used, 1); ?></td>
                                                <td data-label="Remaining">
                                                    <?php echo number_format(max(0, $type['quota'] - $used), 1); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Leave Requests History Panel -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-journal-bookmark-fill"></i></div>
                            <div>
                                <p class="sec-title mb-0">Leave Request History</p>
                                <p class="sec-sub mb-0">Recent leave applications (last 100 records)</p>
                            </div>
                            <span class="badge-pill ms-auto"><?php echo count($leave_requests); ?> requests</span>
                        </div>

                        <div class="compact-table-wrap">
                            <table id="requestsTable" class="table compact-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Applied On</th>
                                        <th>Leave Type</th>
                                        <th>Period</th>
                                        <th>Days</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Approved/Rejected By</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leave_requests as $req): ?>
                                    <tr>
                                        <td data-label="Applied On">
                                            <small><?php echo formatDateTime($req['applied_at'] ?? $req['created_at']); ?></small>
                                        </td>
                                        <td data-label="Leave Type">
                                            <span class="fw-bold"><?php echo e($req['leave_type']); ?></span>
                                            <br>
                                            <small class="text-muted">
                                                <?php 
                                                $leave_names = ['SL' => 'Sick', 'CL' => 'Casual', 'EL' => 'Earned', 'LOP' => 'Loss of Pay', 'OD' => 'Official Duty'];
                                                echo $leave_names[$req['leave_type']] ?? $req['leave_type'];
                                                ?>
                                            </small>
                                        </td>
                                        <td data-label="Period">
                                            <?php echo formatDate($req['from_date']); ?><br>
                                            <small>→ <?php echo formatDate($req['to_date']); ?></small>
                                        </td>
                                        <td data-label="Days"><span
                                                class="fw-bold"><?php echo e($req['total_days']); ?></span> day(s)</td>
                                        <td data-label="Reason"><small
                                                class="reason-cell"><?php echo e(substr($req['reason'], 0, 50)); ?><?php echo strlen($req['reason']) > 50 ? '...' : ''; ?></small>
                                        </td>
                                        <td data-label="Status"><?php echo getStatusBadge($req['status']); ?></td>
                                        <td data-label="Approved/Rejected By">
                                            <?php if ($req['status'] == 'Approved' && $req['approved_by_name']): ?>
                                            <small><?php echo e($req['approved_by_name']); ?></small>
                                            <br><small
                                                class="text-muted"><?php echo formatDate($req['approved_at']); ?></small>
                                            <?php elseif ($req['status'] == 'Rejected' && $req['rejected_by_name']): ?>
                                            <small><?php echo e($req['rejected_by_name']); ?></small>
                                            <br><small
                                                class="text-muted"><?php echo formatDate($req['rejected_at']); ?></small>
                                            <?php else: ?>
                                            —
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Remarks">
                                            <small>
                                                <?php 
                                                if ($req['status'] == 'Approved' && $req['approver_remarks']) {
                                                    echo e($req['approver_remarks']);
                                                } elseif ($req['status'] == 'Rejected' && $req['rejection_reason']) {
                                                    echo '<span class="text-danger">' . e($req['rejection_reason']) . '</span>';
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($leave_requests)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                            No leave requests found.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Info Section -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="panel">
                                <div class="sec-head">
                                    <div class="sec-ic"><i class="bi bi-info-circle"></i></div>
                                    <div>
                                        <p class="sec-title mb-0">Leave Balance Explanation</p>
                                        <p class="sec-sub mb-0">How balances are calculated</p>
                                    </div>
                                </div>
                                <p class="small text-muted mb-0" style="font-weight:800;">Leave balances are calculated
                                    based on approved leave requests. Pending leaves are shown separately and will be
                                    deducted from your balance upon approval. Annual quotas are reset at the beginning
                                    of each calendar year.</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="panel">
                                <div class="sec-head">
                                    <div class="sec-ic"><i class="bi bi-question-circle"></i></div>
                                    <div>
                                        <p class="sec-title mb-0">Need Help?</p>
                                        <p class="sec-sub mb-0">Support information</p>
                                    </div>
                                </div>
                                <p class="small text-muted mb-0" style="font-weight:800;">For any discrepancies in leave
                                    balance, please contact HR department or your reporting manager. All leave requests
                                    are logged for transparency.</p>
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
        const balanceCards = document.querySelectorAll('.balance-card');
        balanceCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(12px)';
            setTimeout(() => {
                card.style.transition = 'all 0.25s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 60);
        });
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