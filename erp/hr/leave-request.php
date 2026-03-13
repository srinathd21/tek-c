<?php
// hr/leave-request.php - Leave Request Management (Combined View)
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
$action = $_GET['action'] ?? 'list'; // list, apply, view, edit, cancel

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

// Check if user is HR/Admin/Manager for approval capabilities
$designation = strtolower(trim($employee['designation'] ?? ''));
$department = strtolower(trim($employee['department'] ?? ''));
$isHrOrAdmin = ($designation === 'hr' || $department === 'hr' || $designation === 'administrator' || $designation === 'admin' || $designation === 'director');
$isManager = ($designation === 'manager' || $designation === 'team lead' || $designation === 'project manager');

// Get reporting manager details
$reporting_manager = null;
if (!empty($employee['reporting_to'])) {
    $manager_stmt = mysqli_prepare($conn, "SELECT id, full_name, email, mobile_number, designation FROM employees WHERE id = ?");
    mysqli_stmt_bind_param($manager_stmt, "i", $employee['reporting_to']);
    mysqli_stmt_execute($manager_stmt);
    $manager_res = mysqli_stmt_get_result($manager_stmt);
    $reporting_manager = mysqli_fetch_assoc($manager_res);
    mysqli_stmt_close($manager_stmt);
}

// Get leave balance
$leave_balance_query = "
    SELECT 
        SUM(CASE WHEN leave_type = 'CL' AND status = 'Approved' THEN total_days ELSE 0 END) as cl_taken,
        SUM(CASE WHEN leave_type = 'PL' AND status = 'Approved' THEN total_days ELSE 0 END) as pl_taken,
        SUM(CASE WHEN leave_type = 'SL' AND status = 'Approved' THEN total_days ELSE 0 END) as sl_taken,
        SUM(CASE WHEN leave_type = 'LWP' AND status = 'Approved' THEN total_days ELSE 0 END) as lwp_taken
    FROM leave_requests 
    WHERE employee_id = ? AND YEAR(from_date) = YEAR(CURDATE())
";
$balance_stmt = mysqli_prepare($conn, $leave_balance_query);
mysqli_stmt_bind_param($balance_stmt, "i", $current_employee_id);
mysqli_stmt_execute($balance_stmt);
$balance_res = mysqli_stmt_get_result($balance_stmt);
$leave_taken = mysqli_fetch_assoc($balance_res);
mysqli_stmt_close($balance_stmt);

// Default leave quotas (adjust as per company policy)
$leave_quotas = [
    'CL' => ['name' => 'Casual Leave', 'quota' => 12, 'taken' => (float)($leave_taken['cl_taken'] ?? 0), 'color' => 'success', 'icon' => 'bi-umbrella'],
    'PL' => ['name' => 'Privilege Leave', 'quota' => 15, 'taken' => (float)($leave_taken['pl_taken'] ?? 0), 'color' => 'primary', 'icon' => 'bi-suitcase-lg'],
    'SL' => ['name' => 'Sick Leave', 'quota' => 10, 'taken' => (float)($leave_taken['sl_taken'] ?? 0), 'color' => 'warning', 'icon' => 'bi-hospital'],
    'LWP' => ['name' => 'Leave Without Pay', 'quota' => 0, 'taken' => (float)($leave_taken['lwp_taken'] ?? 0), 'color' => 'secondary', 'icon' => 'bi-clock']
];

foreach ($leave_quotas as $type => &$quota) {
    $quota['balance'] = $quota['quota'] > 0 ? max(0, $quota['quota'] - $quota['taken']) : 999;
    $quota['percentage'] = $quota['quota'] > 0 ? round(($quota['taken'] / $quota['quota']) * 100) : 0;
}

// Get holidays
$holidays = [];
$holiday_stmt = mysqli_prepare($conn, "
    SELECT * FROM holidays 
    WHERE YEAR(holiday_date) = YEAR(CURDATE()) 
    ORDER BY holiday_date
");
mysqli_stmt_execute($holiday_stmt);
$holiday_res = mysqli_stmt_get_result($holiday_stmt);
while ($row = mysqli_fetch_assoc($holiday_res)) {
    $holidays[] = $row;
}
mysqli_stmt_close($holiday_stmt);

// ---------------- HANDLE LEAVE APPLICATION ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply') {
    $leave_type = $_POST['leave_type'] ?? '';
    $from_date = $_POST['from_date'] ?? '';
    $to_date = $_POST['to_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $contact_during_leave = trim($_POST['contact_during_leave'] ?? '');
    $handover_to = trim($_POST['handover_to'] ?? '');
    $half_day_dates = $_POST['half_day'] ?? [];

    $errors = [];

    // Validation
    if (empty($leave_type)) {
        $errors[] = "Please select leave type.";
    }

    if (empty($from_date)) {
        $errors[] = "From date is required.";
    }

    if (empty($to_date)) {
        $errors[] = "To date is required.";
    }

    if (empty($reason)) {
        $errors[] = "Reason for leave is required.";
    }

    // Date validation
    if (!empty($from_date) && !empty($to_date)) {
        $from_ts = strtotime($from_date);
        $to_ts = strtotime($to_date);
        $today_ts = strtotime(date('Y-m-d'));

        if ($from_ts < $today_ts) {
            $errors[] = "From date cannot be in the past.";
        }

        if ($to_ts < $from_ts) {
            $errors[] = "To date must be on or after from date.";
        }
    }

    // Calculate total days
    $total_days = 0;
    $selected_dates = [];

    if (empty($errors) && !empty($from_date) && !empty($to_date)) {
        $current = strtotime($from_date);
        $end = strtotime($to_date);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $day_of_week = date('w', $current);
            
            // Skip Sundays (adjust based on company policy)
            if ($day_of_week != 0) {
                $is_half_day = in_array($date, $half_day_dates);
                $selected_dates[] = [
                    'date' => $date,
                    'half_day' => $is_half_day ? 'HD' : null
                ];
                $total_days += $is_half_day ? 0.5 : 1;
            }
            
            $current = strtotime('+1 day', $current);
        }
    }

    // Check leave balance
    if (!empty($leave_type) && isset($leave_quotas[$leave_type])) {
        $quota = $leave_quotas[$leave_type];
        if ($quota['quota'] > 0 && ($quota['taken'] + $total_days) > $quota['quota']) {
            $errors[] = "Insufficient leave balance. Available: {$quota['balance']} days, Requested: {$total_days} days.";
        }
    }

    // Check for overlapping leave requests
    if (empty($errors) && !empty($from_date) && !empty($to_date)) {
        $overlap_query = "
            SELECT * FROM leave_requests 
            WHERE employee_id = ? 
            AND status IN ('Pending', 'Approved')
            AND (
                (from_date BETWEEN ? AND ?) OR
                (to_date BETWEEN ? AND ?) OR
                (? BETWEEN from_date AND to_date) OR
                (? BETWEEN from_date AND to_date)
            )
        ";
        $overlap_stmt = mysqli_prepare($conn, $overlap_query);
        mysqli_stmt_bind_param($overlap_stmt, "issssss", 
            $current_employee_id, 
            $from_date, $to_date,
            $from_date, $to_date,
            $from_date, $to_date
        );
        mysqli_stmt_execute($overlap_stmt);
        $overlap_res = mysqli_stmt_get_result($overlap_stmt);
        
        if (mysqli_num_rows($overlap_res) > 0) {
            $errors[] = "You already have a pending or approved leave request for this period.";
        }
        mysqli_stmt_close($overlap_stmt);
    }

    // If no errors, insert leave request
    if (empty($errors)) {
        $request_no = 'LEAVE-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $insert_stmt = mysqli_prepare($conn, "
            INSERT INTO leave_requests 
            (employee_id, leave_type, from_date, to_date, total_days, reason, 
             contact_during_leave, handover_to, selected_dates_json, status, applied_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        
        $selected_dates_json = json_encode($selected_dates);
        
        mysqli_stmt_bind_param($insert_stmt, "isssdssss",
            $current_employee_id,
            $leave_type,
            $from_date,
            $to_date,
            $total_days,
            $reason,
            $contact_during_leave,
            $handover_to,
            $selected_dates_json
        );

        if (mysqli_stmt_execute($insert_stmt)) {
            $leave_id = mysqli_insert_id($conn);
            
            logActivity(
                $conn,
                'CREATE',
                'leave',
                "Applied for {$total_days} days {$leave_type} leave",
                $leave_id,
                null,
                null,
                json_encode([
                    'leave_type' => $leave_type,
                    'from_date' => $from_date,
                    'to_date' => $to_date,
                    'total_days' => $total_days
                ])
            );

            $_SESSION['flash_success'] = "Leave application submitted successfully.";
            header("Location: leave-request.php?action=list");
            exit;
        } else {
            $error_message = "Failed to submit leave application: " . mysqli_error($conn);
        }
        mysqli_stmt_close($insert_stmt);
    }
}

// ---------------- HANDLE LEAVE CANCELLATION ----------------
if (isset($_GET['cancel']) && isset($_GET['id'])) {
    $leave_id = (int)$_GET['id'];
    
    // Verify ownership
    $check_stmt = mysqli_prepare($conn, "SELECT * FROM leave_requests WHERE id = ? AND employee_id = ? AND status = 'Pending'");
    mysqli_stmt_bind_param($check_stmt, "ii", $leave_id, $current_employee_id);
    mysqli_stmt_execute($check_stmt);
    $check_res = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_res) > 0) {
        $leave = mysqli_fetch_assoc($check_res);
        
        $update_stmt = mysqli_prepare($conn, "UPDATE leave_requests SET status = 'Cancelled' WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "i", $leave_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'leave',
                "Cancelled leave request",
                $leave_id,
                null,
                null,
                json_encode(['status' => 'Cancelled'])
            );
            $_SESSION['flash_success'] = "Leave request cancelled successfully.";
        } else {
            $_SESSION['flash_error'] = "Failed to cancel leave request.";
        }
        mysqli_stmt_close($update_stmt);
    } else {
        $_SESSION['flash_error'] = "Invalid leave request or you don't have permission to cancel it.";
    }
    mysqli_stmt_close($check_stmt);
    
    header("Location: leave-request.php?action=list");
    exit;
}

// ---------------- FETCH LEAVE REQUESTS FOR LIST VIEW ----------------
$leave_requests = [];
$filter = $_GET['filter'] ?? 'all';

if ($action === 'list') {
    $query = "SELECT * FROM leave_requests WHERE employee_id = ?";
    $params = [$current_employee_id];
    $types = "i";
    
    if ($filter === 'pending') {
        $query .= " AND status = 'Pending'";
    } elseif ($filter === 'approved') {
        $query .= " AND status = 'Approved'";
    } elseif ($filter === 'rejected') {
        $query .= " AND status = 'Rejected'";
    } elseif ($filter === 'cancelled') {
        $query .= " AND status = 'Cancelled'";
    }
    
    $query .= " ORDER BY applied_at DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $leave_requests[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// ---------------- FETCH SINGLE LEAVE REQUEST FOR VIEW ----------------
$leave_detail = null;
if ($action === 'view' && isset($_GET['id'])) {
    $leave_id = (int)$_GET['id'];
    
    $detail_stmt = mysqli_prepare($conn, "
        SELECT lr.*, e.full_name, e.employee_code, e.designation, e.department 
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        WHERE lr.id = ? AND lr.employee_id = ?
    ");
    mysqli_stmt_bind_param($detail_stmt, "ii", $leave_id, $current_employee_id);
    mysqli_stmt_execute($detail_stmt);
    $detail_res = mysqli_stmt_get_result($detail_stmt);
    $leave_detail = mysqli_fetch_assoc($detail_res);
    mysqli_stmt_close($detail_stmt);
    
    if (!$leave_detail) {
        $_SESSION['flash_error'] = "Leave request not found.";
        header("Location: leave-request.php?action=list");
        exit;
    }
}

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
        case 'Approved':
            return '<span class="badge bg-success px-3 py-2"><i class="bi bi-check-circle"></i> Approved</span>';
        case 'Rejected':
            return '<span class="badge bg-danger px-3 py-2"><i class="bi bi-x-circle"></i> Rejected</span>';
        case 'Pending':
            return '<span class="badge bg-warning text-dark px-3 py-2"><i class="bi bi-clock"></i> Pending</span>';
        case 'Cancelled':
            return '<span class="badge bg-secondary px-3 py-2"><i class="bi bi-x"></i> Cancelled</span>';
        default:
            return '<span class="badge bg-light text-dark px-3 py-2">' . e($status) . '</span>';
    }
}

$loggedName = $_SESSION['employee_name'] ?? $employee['full_name'];
$current_date = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leave Request - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

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

        .form-label{ font-weight:800; font-size:13px; color:#4b5563; margin-bottom:6px; }
        .form-control, .form-select{ border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font-weight:500; }
        .form-control:focus, .form-select:focus{ border-color: #3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }

        .leave-balance-card{ background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:16px; }
        .balance-item{ display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #e5e7eb; }
        .balance-item:last-child{ border-bottom:0; }
        .balance-label{ font-weight:700; color:#374151; }
        .balance-value{ font-weight:800; }
        .progress{ height:8px; border-radius:4px; }

        .info-card{ background:#eef2ff; border-radius:12px; padding:16px; margin-bottom:20px; }
        .info-icon{ width:40px; height:40px; border-radius:10px; background:#3b82f6; color:white; display:grid; place-items:center; font-size:20px; margin-bottom:12px; }

        .filter-card{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:20px; }

        .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid #e5e7eb!important; }
        .table td{ vertical-align:middle; border-color:#e5e7eb; font-weight:600; color:#374151; padding:14px 8px; }

        .action-btn{ width:32px; height:32px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; 
            display:inline-flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; margin:0 2px; }
        .action-btn:hover{ background:#f3f4f6; color:#374151; }

        .detail-row{ display:flex; padding:12px 0; border-bottom:1px solid #e5e7eb; }
        .detail-label{ width:180px; font-weight:800; color:#6b7280; }
        .detail-value{ flex:1; font-weight:700; color:#1f2937; }

        .date-range{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin:16px 0; }
        .day-selector{ display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }

        .required::after{ content:" *"; color:#ef4444; font-weight:800; }

        .holiday-badge{ background:#e6f7ff; border:1px solid #91d5ff; color:#0050b3; padding:4px 10px; border-radius:20px; font-size:12px; display:inline-block; margin:2px; }

        .nav-tabs .nav-link{ font-weight:700; color:#6b7280; border:none; padding:10px 20px; }
        .nav-tabs .nav-link.active{ color:#3b82f6; border-bottom:3px solid #3b82f6; background:none; }

        @media (max-width: 768px) {
            .content-scroll{ padding:12px; }
            .detail-row{ flex-direction:column; }
            .detail-label{ width:100%; margin-bottom:5px; }
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
                        <h1 class="h3 fw-bold mb-1">
                            <?php if ($action === 'apply'): ?>
                                Apply for Leave
                            <?php elseif ($action === 'view' && $leave_detail): ?>
                                Leave Request Details
                            <?php else: ?>
                                Leave Requests
                            <?php endif; ?>
                        </h1>
                        <p class="text-muted mb-0">
                            <?php if ($action === 'apply'): ?>
                                Submit a new leave request for approval
                            <?php elseif ($action === 'view' && $leave_detail): ?>
                                View details of your leave request
                            <?php else: ?>
                                View and manage your leave requests
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($action !== 'list'): ?>
                            <a href="leave-request.php?action=list" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to List
                            </a>
                        <?php endif; ?>
                        <?php if ($action === 'list'): ?>
                            <a href="leave-request.php?action=apply" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> New Leave Request
                            </a>
                        <?php endif; ?>
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

                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Please fix the following errors:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'apply'): ?>
                    <!-- Apply Leave Form -->
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-calendar-plus me-2"></i>Leave Application Form
                                    </h5>
                                </div>

                                <form method="POST" action="leave-request.php" id="leaveForm">
                                    <input type="hidden" name="action" value="apply">
                                    
                                    <div class="mb-3">
                                        <label class="form-label required">Leave Type</label>
                                        <select name="leave_type" id="leave_type" class="form-select" required>
                                            <option value="">Select Leave Type</option>
                                            <?php foreach ($leave_quotas as $code => $quota): ?>
                                                <option value="<?= $code ?>" <?= (isset($_POST['leave_type']) && $_POST['leave_type'] === $code) ? 'selected' : '' ?>
                                                        data-balance="<?= $quota['balance'] ?>">
                                                    <?= e($quota['name']) ?> (<?= $quota['balance'] >= 999 ? 'Unlimited' : $quota['balance'] . ' days available' ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label required">From Date</label>
                                            <input type="date" name="from_date" id="from_date" 
                                                   class="form-control"
                                                   value="<?= e($_POST['from_date'] ?? '') ?>" 
                                                   min="<?= $current_date ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label required">To Date</label>
                                            <input type="date" name="to_date" id="to_date" 
                                                   class="form-control"
                                                   value="<?= e($_POST['to_date'] ?? '') ?>" 
                                                   min="<?= $current_date ?>" required>
                                        </div>
                                    </div>

                                    <div id="halfDayContainer" class="date-range" style="display:none;">
                                        <label class="form-label fw-bold">Select Half Days (if any)</label>
                                        <p class="text-muted small mb-2">Check the dates you want to mark as half day</p>
                                        <div id="halfDayList" class="day-selector"></div>
                                    </div>

                                    <div class="alert alert-info mb-3" id="totalDaysDisplay" style="display:none;">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <span id="totalDaysText">Total: 0 days</span>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label required">Reason for Leave</label>
                                        <textarea name="reason" id="reason" rows="3" 
                                                  class="form-control" required><?= e($_POST['reason'] ?? '') ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Contact Number During Leave</label>
                                        <input type="text" name="contact_during_leave" 
                                               class="form-control" 
                                               value="<?= e($_POST['contact_during_leave'] ?? $employee['mobile_number'] ?? '') ?>"
                                               placeholder="Mobile number where you can be reached">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Work Handover To</label>
                                        <input type="text" name="handover_to" 
                                               class="form-control" 
                                               value="<?= e($_POST['handover_to'] ?? '') ?>"
                                               placeholder="Name of colleague handling your work">
                                    </div>

                                    <hr>

                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary px-4 py-2">
                                            <i class="bi bi-send me-2"></i>Submit Application
                                        </button>
                                        <a href="leave-request.php?action=list" class="btn btn-outline-secondary px-4 py-2">
                                            Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="info-card">
                                <div class="info-icon">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <h6 class="fw-bold mb-2">Employee Information</h6>
                                <div class="mb-1"><strong>Name:</strong> <?= e($employee['full_name']) ?></div>
                                <div class="mb-1"><strong>Code:</strong> <?= e($employee['employee_code']) ?></div>
                                <?php if ($reporting_manager): ?>
                                    <div class="mt-2 pt-2 border-top">
                                        <strong>Reporting To:</strong><br>
                                        <?= e($reporting_manager['full_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="panel">
                                <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart me-2"></i>Leave Balance</h6>
                                <?php foreach ($leave_quotas as $code => $quota): ?>
                                    <?php if ($quota['quota'] > 0): ?>
                                        <div class="balance-item">
                                            <div class="w-100">
                                                <div class="d-flex justify-content-between">
                                                    <span><i class="bi <?= $quota['icon'] ?> me-1"></i> <?= e($quota['name']) ?></span>
                                                    <span class="fw-bold"><?= number_format($quota['balance'], 1) ?>/<?= $quota['quota'] ?></span>
                                                </div>
                                                <div class="progress mt-1">
                                                    <div class="progress-bar bg-<?= $quota['color'] ?>" 
                                                         style="width: <?= $quota['percentage'] ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                <?php elseif ($action === 'view' && $leave_detail): ?>
                    <!-- Leave Details View -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-file-text me-2"></i>Leave Request Details
                                    </h5>
                                    <?= getStatusBadge($leave_detail['status']) ?>
                                </div>

                                <div class="detail-row">
                                    <div class="detail-label">Request ID:</div>
                                    <div class="detail-value">#<?= $leave_detail['id'] ?></div>
                                </div>

                                <div class="detail-row">
                                    <div class="detail-label">Leave Type:</div>
                                    <div class="detail-value">
                                        <?php 
                                        $type = $leave_detail['leave_type'];
                                        echo isset($leave_quotas[$type]) ? $leave_quotas[$type]['name'] : $type;
                                        ?>
                                    </div>
                                </div>

                                <div class="detail-row">
                                    <div class="detail-label">Period:</div>
                                    <div class="detail-value">
                                        <?= safeDate($leave_detail['from_date']) ?> to <?= safeDate($leave_detail['to_date']) ?>
                                        <span class="badge bg-info ms-2"><?= $leave_detail['total_days'] ?> days</span>
                                    </div>
                                </div>

                                <?php if (!empty($leave_detail['selected_dates_json'])): 
                                    $dates = json_decode($leave_detail['selected_dates_json'], true);
                                    if (!empty($dates)):
                                ?>
                                <div class="detail-row">
                                    <div class="detail-label">Selected Dates:</div>
                                    <div class="detail-value">
                                        <?php foreach ($dates as $d): ?>
                                            <span class="badge <?= isset($d['half_day']) ? 'bg-warning text-dark' : 'bg-info' ?> me-1">
                                                <?= date('d M', strtotime($d['date'])) ?>
                                                <?= isset($d['half_day']) ? '(HD)' : '' ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; endif; ?>

                                <div class="detail-row">
                                    <div class="detail-label">Reason:</div>
                                    <div class="detail-value"><?= nl2br(e($leave_detail['reason'])) ?></div>
                                </div>

                                <?php if (!empty($leave_detail['contact_during_leave'])): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Contact During Leave:</div>
                                    <div class="detail-value"><?= e($leave_detail['contact_during_leave']) ?></div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($leave_detail['handover_to'])): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Handover To:</div>
                                    <div class="detail-value"><?= e($leave_detail['handover_to']) ?></div>
                                </div>
                                <?php endif; ?>

                                <div class="detail-row">
                                    <div class="detail-label">Applied On:</div>
                                    <div class="detail-value"><?= safeDateTime($leave_detail['applied_at'] ?? $leave_detail['created_at']) ?></div>
                                </div>

                                <?php if ($leave_detail['status'] === 'Approved' && !empty($leave_detail['approved_at'])): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Approved On:</div>
                                    <div class="detail-value"><?= safeDateTime($leave_detail['approved_at']) ?></div>
                                </div>
                                <?php endif; ?>

                                <?php if ($leave_detail['status'] === 'Rejected' && !empty($leave_detail['rejection_reason'])): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Rejection Reason:</div>
                                    <div class="detail-value text-danger"><?= nl2br(e($leave_detail['rejection_reason'])) ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="panel">
                                <h6 class="fw-bold mb-3"><i class="bi bi-gear me-2"></i>Actions</h6>
                                
                                <?php if ($leave_detail['status'] === 'Pending'): ?>
                                    <a href="?cancel=1&id=<?= $leave_detail['id'] ?>" 
                                       class="btn btn-outline-danger w-100 mb-2"
                                       onclick="return confirm('Are you sure you want to cancel this leave request?')">
                                        <i class="bi bi-x-circle"></i> Cancel Request
                                    </a>
                                <?php endif; ?>
                                
                                <a href="leave-request.php?action=list" class="btn btn-outline-secondary w-100">
                                    <i class="bi bi-arrow-left"></i> Back to List
                                </a>
                            </div>

                            <?php if (!empty($holidays)): ?>
                            <div class="panel mt-3">
                                <h6 class="fw-bold mb-3"><i class="bi bi-calendar-heart me-2"></i>Upcoming Holidays</h6>
                                <?php 
                                $upcoming = array_filter($holidays, function($h) {
                                    return strtotime($h['holiday_date']) >= strtotime(date('Y-m-d'));
                                });
                                $upcoming = array_slice($upcoming, 0, 5);
                                ?>
                                <?php foreach ($upcoming as $holiday): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span><?= e($holiday['holiday_name']) ?></span>
                                        <small class="text-muted"><?= date('d M', strtotime($holiday['holiday_date'])) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- List View -->
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <!-- Filter Tabs -->
                            <div class="filter-card">
                                <ul class="nav nav-tabs">
                                    <li class="nav-item">
                                        <a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" 
                                           href="?action=list&filter=all">All</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= $filter === 'pending' ? 'active' : '' ?>" 
                                           href="?action=list&filter=pending">Pending</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= $filter === 'approved' ? 'active' : '' ?>" 
                                           href="?action=list&filter=approved">Approved</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= $filter === 'rejected' ? 'active' : '' ?>" 
                                           href="?action=list&filter=rejected">Rejected</a>
                                    </li>
                                </ul>
                            </div>

                            <!-- Leave Requests Table -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-list-ul me-2"></i>Leave Requests
                                        <span class="badge bg-secondary ms-2"><?= count($leave_requests) ?></span>
                                    </h5>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="exportTableToCSV()">
                                        <i class="bi bi-download"></i> Export
                                    </button>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle" id="leaveTable">
                                        <thead>
                                            <tr>
                                                <th>Applied On</th>
                                                <th>Leave Type</th>
                                                <th>Period</th>
                                                <th>Days</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($leave_requests)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">
                                                        <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                                                        No leave requests found.
                                                        <a href="?action=apply" class="d-block mt-2">Apply for leave</a>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($leave_requests as $request): ?>
                                                    <tr>
                                                        <td><?= safeDate($request['applied_at'] ?? $request['created_at']) ?></td>
                                                        <td>
                                                            <?php 
                                                            $type = $request['leave_type'];
                                                            $icon = $leave_quotas[$type]['icon'] ?? 'bi-calendar';
                                                            ?>
                                                            <i class="bi <?= $icon ?> me-1"></i>
                                                            <?= e($type) ?>
                                                        </td>
                                                        <td>
                                                            <?= safeDate($request['from_date']) ?><br>
                                                            <small>to <?= safeDate($request['to_date']) ?></small>
                                                        </td>
                                                        <td><span class="badge bg-info"><?= $request['total_days'] ?> days</span></td>
                                                        <td><?= getStatusBadge($request['status']) ?></td>
                                                        <td>
                                                            <a href="?action=view&id=<?= $request['id'] ?>" class="action-btn" title="View Details">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                            <?php if ($request['status'] === 'Pending'): ?>
                                                                <a href="?cancel=1&id=<?= $request['id'] ?>" 
                                                                   class="action-btn text-danger" 
                                                                   title="Cancel Request"
                                                                   onclick="return confirm('Are you sure you want to cancel this leave request?')">
                                                                    <i class="bi bi-x-circle"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <!-- Leave Balance Summary -->
                            <div class="panel mb-4">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-pie-chart me-2"></i>Leave Balance
                                    </h5>
                                </div>
                                <?php foreach ($leave_quotas as $code => $quota): ?>
                                    <?php if ($quota['quota'] > 0): ?>
                                        <div class="balance-item">
                                            <div class="w-100">
                                                <div class="d-flex justify-content-between">
                                                    <span><i class="bi <?= $quota['icon'] ?> me-1"></i> <?= e($quota['name']) ?></span>
                                                    <span class="fw-bold"><?= number_format($quota['balance'], 1) ?>/<?= $quota['quota'] ?></span>
                                                </div>
                                                <div class="progress mt-1">
                                                    <div class="progress-bar bg-<?= $quota['color'] ?>" 
                                                         style="width: <?= $quota['percentage'] ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <!-- Quick Stats -->
                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <div class="stat-card">
                                        <div class="stat-ic blue"><i class="bi bi-clock"></i></div>
                                        <div>
                                            <div class="stat-label">Pending</div>
                                            <div class="stat-value">
                                                <?= count(array_filter($leave_requests, function($r) { return $r['status'] === 'Pending'; })) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-card">
                                        <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                                        <div>
                                            <div class="stat-label">Approved</div>
                                            <div class="stat-value">
                                                <?= count(array_filter($leave_requests, function($r) { return $r['status'] === 'Approved'; })) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Apply Button -->
                            <a href="?action=apply" class="btn btn-primary w-100 py-3 mb-3">
                                <i class="bi bi-plus-circle fs-5 me-2"></i>Apply for Leave
                            </a>
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
    <?php if (!empty($leave_requests) && $action === 'list'): ?>
    $('#leaveTable').DataTable({
        pageLength: 10,
        order: [[0, 'desc']],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ requests",
            infoEmpty: "No requests to show",
            infoFiltered: "(filtered from _MAX_ total requests)"
        }
    });
    <?php endif; ?>
});

// Leave Application Form Logic
document.addEventListener('DOMContentLoaded', function() {
    const fromDate = document.getElementById('from_date');
    const toDate = document.getElementById('to_date');
    const leaveType = document.getElementById('leave_type');
    const halfDayContainer = document.getElementById('halfDayContainer');
    const halfDayList = document.getElementById('halfDayList');
    const totalDaysDisplay = document.getElementById('totalDaysDisplay');
    const totalDaysText = document.getElementById('totalDaysText');

    if (!fromDate || !toDate) return;

    function calculateWorkingDays(start, end) {
        const startDate = new Date(start);
        const endDate = new Date(end);
        const days = [];
        
        let currentDate = new Date(startDate);
        
        while (currentDate <= endDate) {
            const dayOfWeek = currentDate.getDay();
            if (dayOfWeek !== 0) {
                days.push(new Date(currentDate));
            }
            currentDate.setDate(currentDate.getDate() + 1);
        }
        
        return days;
    }

    function updateDateRange() {
        if (!fromDate.value || !toDate.value) {
            halfDayContainer.style.display = 'none';
            totalDaysDisplay.style.display = 'none';
            return;
        }

        const start = new Date(fromDate.value);
        const end = new Date(toDate.value);
        
        if (end < start) {
            alert('To date cannot be before from date');
            toDate.value = fromDate.value;
            return;
        }

        const workingDays = calculateWorkingDays(fromDate.value, toDate.value);
        
        if (workingDays.length === 0) {
            halfDayContainer.style.display = 'none';
            totalDaysDisplay.style.display = 'none';
            return;
        }

        let html = '';
        workingDays.forEach(date => {
            const dateStr = date.toISOString().split('T')[0];
            const formattedDate = date.toLocaleDateString('en-US', { 
                weekday: 'short', 
                day: 'numeric', 
                month: 'short' 
            });
            
            html += `
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="half_day[]" 
                           value="${dateStr}" id="half_${dateStr}">
                    <label class="form-check-label" for="half_${dateStr}">
                        ${formattedDate}
                    </label>
                </div>
            `;
        });

        halfDayList.innerHTML = html;
        halfDayContainer.style.display = 'block';
        calculateTotalDays();
    }

    function calculateTotalDays() {
        const checkboxes = document.querySelectorAll('input[name="half_day[]"]');
        let total = 0;
        
        checkboxes.forEach(cb => {
            total += cb.checked ? 0.5 : 1;
        });

        totalDaysText.textContent = `Total: ${total.toFixed(1)} days`;
        totalDaysDisplay.style.display = 'block';

        const selectedType = leaveType.options[leaveType.selectedIndex];
        if (selectedType && selectedType.value) {
            const balance = parseFloat(selectedType.dataset.balance || 0);
            if (balance < 999 && total > balance) {
                totalDaysDisplay.className = 'alert alert-danger mb-3';
                totalDaysText.innerHTML += ` <i class="bi bi-exclamation-triangle"></i> Exceeds balance by ${(total - balance).toFixed(1)} days`;
            } else {
                totalDaysDisplay.className = 'alert alert-info mb-3';
            }
        }
    }

    fromDate.addEventListener('change', updateDateRange);
    toDate.addEventListener('change', updateDateRange);
    
    halfDayList.addEventListener('change', function(e) {
        if (e.target.name === 'half_day[]') {
            calculateTotalDays();
        }
    });

    const form = document.getElementById('leaveForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const selectedType = leaveType.value;
            if (!selectedType) {
                e.preventDefault();
                alert('Please select leave type');
                return;
            }

            if (!fromDate.value || !toDate.value) {
                e.preventDefault();
                alert('Please select date range');
                return;
            }

            const reason = document.getElementById('reason').value.trim();
            if (!reason) {
                e.preventDefault();
                alert('Please provide reason for leave');
                return;
            }

            const selectedOption = leaveType.options[leaveType.selectedIndex];
            const balance = parseFloat(selectedOption.dataset.balance || 0);
            
            let total = 0;
            document.querySelectorAll('input[name="half_day[]"]').forEach(cb => {
                total += cb.checked ? 0.5 : 1;
            });

            if (balance < 999 && total > balance) {
                e.preventDefault();
                alert(`Insufficient leave balance. Required: ${total.toFixed(1)} days, Available: ${balance.toFixed(1)} days`);
                return;
            }
        });
    }

    <?php if (isset($_POST['from_date']) && isset($_POST['to_date'])): ?>
    setTimeout(updateDateRange, 100);
    <?php endif; ?>
});

function exportTableToCSV() {
    const rows = document.querySelectorAll('#leaveTable tbody tr');
    const csv = [];
    
    const headers = ['Applied On', 'Leave Type', 'From Date', 'To Date', 'Days', 'Status'];
    csv.push(headers.join(','));
    
    rows.forEach(row => {
        if (row.cells.length === 6) {
            const rowData = [
                '"' + row.cells[0].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[1].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[2].innerText.split('to')[0].replace(/"/g, '""').trim() + '"',
                '"' + (row.cells[2].innerText.split('to')[1] || '').replace(/"/g, '""').trim() + '"',
                '"' + row.cells[3].innerText.replace(/"/g, '""') + '"',
                '"' + row.cells[4].innerText.replace(/"/g, '""') + '"'
            ];
            csv.push(rowData.join(','));
        }
    });
    
    const csvString = csv.join('\n');
    const blob = new Blob([csvString], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'leave_requests_<?= date('Y-m-d') ?>.csv';
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