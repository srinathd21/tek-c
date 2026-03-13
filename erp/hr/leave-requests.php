<?php
// hr/leave-requests.php - HR Panel for Managing Leave Requests
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH (Multiple Roles) ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];

// Get current employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
if (!$emp_stmt) {
    die("Error preparing employee query: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$current_employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die("Employee not found.");
}

// Define role-based permissions
$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department = strtolower(trim($current_employee['department'] ?? ''));

// Check user roles
$isAdmin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director');
$isHr = ($designation === 'hr' || $department === 'hr');
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'project engineer grade 1', 'project engineer grade 2']);

// Get reporting employees if manager
$reporting_employees = [];
if ($isManager && !$isHr && !$isAdmin) {
    $reporting_stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE reporting_to = ?");
    mysqli_stmt_bind_param($reporting_stmt, "i", $current_employee_id);
    mysqli_stmt_execute($reporting_stmt);
    $reporting_res = mysqli_stmt_get_result($reporting_stmt);
    while ($row = mysqli_fetch_assoc($reporting_res)) {
        $reporting_employees[] = $row['id'];
    }
}

// Check if user has any approval permission
$canApprove = ($isAdmin || $isHr || $isManager);

if (!$canApprove) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- HANDLE APPROVAL/REJECTION ----------------
$action_message = '';
$action_message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_action'])) {
    $leave_id = (int)$_POST['leave_id'];
    $action = $_POST['leave_action']; // approve, reject
    $remarks = trim($_POST['remarks'] ?? '');
    
    if ($action === 'reject' && empty($remarks)) {
        $action_message = "Rejection reason is required.";
        $action_message_type = "danger";
    } else {
        // Get leave details before update
        $get_stmt = mysqli_prepare($conn, "
            SELECT lr.*, e.full_name, e.employee_code, e.reporting_to 
            FROM leave_requests lr 
            JOIN employees e ON lr.employee_id = e.id 
            WHERE lr.id = ?
        ");
        if ($get_stmt) {
            mysqli_stmt_bind_param($get_stmt, "i", $leave_id);
            mysqli_stmt_execute($get_stmt);
            $get_res = mysqli_stmt_get_result($get_stmt);
            $leave_data = mysqli_fetch_assoc($get_res);
            mysqli_stmt_close($get_stmt);
            
            if ($leave_data) {
                // Check if user has permission to approve/reject this specific leave
                $hasPermission = false;
                
                if ($isAdmin || $isHr) {
                    // Admin/HR can approve any leave
                    $hasPermission = true;
                } elseif ($isManager) {
                    // Manager can only approve leaves of their reporting employees
                    if ($leave_data['reporting_to'] == $current_employee_id) {
                        $hasPermission = true;
                    } else {
                        // Check if employee is in reporting list
                        $reporting_check = in_array($leave_data['employee_id'], $reporting_employees);
                        if ($reporting_check) {
                            $hasPermission = true;
                        }
                    }
                }
                
                if (!$hasPermission) {
                    $action_message = "You don't have permission to process this leave request.";
                    $action_message_type = "danger";
                } else {
                    if ($action === 'approve') {
                        // Check if already approved/rejected
                        if ($leave_data['status'] !== 'Pending') {
                            $action_message = "This leave request is already {$leave_data['status']}.";
                            $action_message_type = "warning";
                        } else {
                            $update_stmt = mysqli_prepare($conn, "
                                UPDATE leave_requests 
                                SET status = 'Approved', approved_by = ?, approved_at = NOW(), approver_remarks = ? 
                                WHERE id = ?
                            ");
                            if ($update_stmt) {
                                mysqli_stmt_bind_param($update_stmt, "isi", $current_employee_id, $remarks, $leave_id);
                                
                                $log_action = 'APPROVE';
                                $log_desc = "Approved leave request for {$leave_data['full_name']} ({$leave_data['total_days']} days)";
                            }
                        }
                    } else {
                        if ($leave_data['status'] !== 'Pending') {
                            $action_message = "This leave request is already {$leave_data['status']}.";
                            $action_message_type = "warning";
                        } else {
                            $update_stmt = mysqli_prepare($conn, "
                                UPDATE leave_requests 
                                SET status = 'Rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? 
                                WHERE id = ?
                            ");
                            if ($update_stmt) {
                                mysqli_stmt_bind_param($update_stmt, "isi", $current_employee_id, $remarks, $leave_id);
                                
                                $log_action = 'REJECT';
                                $log_desc = "Rejected leave request for {$leave_data['full_name']} ({$leave_data['total_days']} days)";
                            }
                        }
                    }
                    
                    if (isset($update_stmt) && $update_stmt) {
                        if (mysqli_stmt_execute($update_stmt)) {
                            logActivity(
                                $conn,
                                $log_action,
                                'leave',
                                $log_desc,
                                $leave_id,
                                null,
                                json_encode(['status' => $action === 'approve' ? 'Approved' : 'Rejected']),
                                json_encode(['remarks' => $remarks, 'approver_role' => $designation])
                            );
                            
                            $action_message = "Leave request {$action}d successfully.";
                            $action_message_type = "success";
                            
                            // TODO: Send email notification to employee
                            
                        } else {
                            $action_message = "Failed to {$action} leave request: " . mysqli_error($conn);
                            $action_message_type = "danger";
                        }
                        mysqli_stmt_close($update_stmt);
                    }
                }
            }
        }
    }
}

// ---------------- HANDLE BULK ACTIONS ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selected_ids = $_POST['selected_ids'] ?? [];
    $bulk_action = $_POST['bulk_action']; // approve_selected, reject_selected
    
    if (!empty($selected_ids)) {
        // Verify permissions for each selected leave
        $ids_string = implode(',', array_map('intval', $selected_ids));
        
        // Get all selected leaves with their reporting info
        $verify_query = "
            SELECT lr.id, lr.employee_id, lr.status, e.reporting_to
            FROM leave_requests lr
            JOIN employees e ON lr.employee_id = e.id
            WHERE lr.id IN ({$ids_string})
        ";
        $verify_result = mysqli_query($conn, $verify_query);
        
        $valid_ids = [];
        $invalid_ids = [];
        
        while ($row = mysqli_fetch_assoc($verify_result)) {
            if ($row['status'] !== 'Pending') {
                $invalid_ids[] = $row['id']; // Already processed
                continue;
            }
            
            $hasPermission = false;
            if ($isAdmin || $isHr) {
                $hasPermission = true;
            } elseif ($isManager) {
                if ($row['reporting_to'] == $current_employee_id || in_array($row['employee_id'], $reporting_employees)) {
                    $hasPermission = true;
                }
            }
            
            if ($hasPermission) {
                $valid_ids[] = $row['id'];
            } else {
                $invalid_ids[] = $row['id'];
            }
        }
        
        if (empty($valid_ids)) {
            $action_message = "No valid leave requests selected for bulk action.";
            $action_message_type = "warning";
        } else {
            $remarks = mysqli_real_escape_string($conn, trim($_POST['bulk_remarks'] ?? ''));
            
            if ($bulk_action === 'reject_selected' && empty($remarks)) {
                $action_message = "Rejection reason is required for bulk reject.";
                $action_message_type = "danger";
            } else {
                $valid_ids_string = implode(',', $valid_ids);
                
                if ($bulk_action === 'approve_selected') {
                    $update_query = "
                        UPDATE leave_requests 
                        SET status = 'Approved', approved_by = {$current_employee_id}, approved_at = NOW(), approver_remarks = '{$remarks}' 
                        WHERE id IN ({$valid_ids_string}) AND status = 'Pending'
                    ";
                    $log_action = 'APPROVE';
                    $log_desc = "Bulk approved " . count($valid_ids) . " leave requests";
                } else {
                    $update_query = "
                        UPDATE leave_requests 
                        SET status = 'Rejected', rejected_by = {$current_employee_id}, rejected_at = NOW(), rejection_reason = '{$remarks}' 
                        WHERE id IN ({$valid_ids_string}) AND status = 'Pending'
                    ";
                    $log_action = 'REJECT';
                    $log_desc = "Bulk rejected " . count($valid_ids) . " leave requests";
                }
                
                if (mysqli_query($conn, $update_query)) {
                    $affected = mysqli_affected_rows($conn);
                    
                    logActivity(
                        $conn,
                        $log_action,
                        'leave',
                        $log_desc,
                        null,
                        null,
                        null,
                        json_encode(['count' => $affected, 'ids' => $valid_ids, 'skipped' => count($invalid_ids)])
                    );
                    
                    $message_parts = [];
                    if ($affected > 0) {
                        $message_parts[] = "Successfully processed {$affected} leave requests.";
                    }
                    if (!empty($invalid_ids)) {
                        $message_parts[] = "Skipped " . count($invalid_ids) . " requests (no permission or already processed).";
                    }
                    
                    $action_message = implode(' ', $message_parts);
                    $action_message_type = "success";
                } else {
                    $action_message = "Failed to process bulk action: " . mysqli_error($conn);
                    $action_message_type = "danger";
                }
            }
        }
    } else {
        $action_message = "No leave requests selected.";
        $action_message_type = "warning";
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'pending';
$employee_filter = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query with permission restrictions
$query = "
    SELECT lr.*, 
           e.full_name, 
           e.employee_code, 
           e.designation, 
           e.department,
           e.photo as employee_photo,
           e.reporting_to,
           m.full_name as approver_name,
           r.full_name as rejector_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN employees m ON lr.approved_by = m.id
    LEFT JOIN employees r ON lr.rejected_by = r.id
    WHERE 1=1
";

// Add permission restrictions based on role
if (!$isAdmin && !$isHr) {
    if ($isManager) {
        // Managers can see leaves of their reporting employees
        if (!empty($reporting_employees)) {
            $reporting_ids = implode(',', $reporting_employees);
            $query .= " AND (e.reporting_to = {$current_employee_id} OR e.id IN ({$reporting_ids}) OR lr.employee_id IN ({$reporting_ids}))";
        } else {
            $query .= " AND e.reporting_to = {$current_employee_id}";
        }
    }
}

// Apply filters
$conditions = [];

if ($status_filter !== 'all') {
    $conditions[] = "lr.status = '" . mysqli_real_escape_string($conn, ucfirst($status_filter)) . "'";
}

if ($employee_filter > 0) {
    $conditions[] = "lr.employee_id = " . (int)$employee_filter;
}

if (!empty($date_from)) {
    $conditions[] = "lr.from_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}

if (!empty($date_to)) {
    $conditions[] = "lr.to_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}

if (!empty($search)) {
    $search_term = mysqli_real_escape_string($conn, $search);
    $conditions[] = "(e.full_name LIKE '%{$search_term}%' OR e.employee_code LIKE '%{$search_term}%' OR lr.reason LIKE '%{$search_term}%')";
}

// Add conditions to query
if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

$query .= " ORDER BY lr.created_at DESC";

// Debug: Log the query
error_log("Leave Requests Query: " . $query);

// Execute query
$leave_requests = [];
$result = mysqli_query($conn, $query);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $leave_requests[] = $row;
    }
    mysqli_free_result($result);
} else {
    // Log error for debugging
    error_log("MySQL Error: " . mysqli_error($conn) . " in query: " . $query);
    $action_message = "Database error occurred. Please check error logs.";
    $action_message_type = "danger";
}

// Get statistics with permission restrictions
$stats_condition = "";
if (!$isAdmin && !$isHr) {
    if ($isManager) {
        if (!empty($reporting_employees)) {
            $reporting_ids = implode(',', $reporting_employees);
            $stats_condition = " AND (e.reporting_to = {$current_employee_id} OR e.id IN ({$reporting_ids}))";
        } else {
            $stats_condition = " AND e.reporting_to = {$current_employee_id}";
        }
    }
}

$stats_query = "
    SELECT 
        SUM(CASE WHEN lr.status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN lr.status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN lr.status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
        SUM(CASE WHEN lr.status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN lr.status = 'Pending' THEN lr.total_days ELSE 0 END) as pending_days,
        SUM(CASE WHEN lr.status = 'Approved' THEN lr.total_days ELSE 0 END) as approved_days
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    WHERE YEAR(lr.created_at) = YEAR(CURDATE()) {$stats_condition}
";
$stats_result = mysqli_query($conn, $stats_query);
if ($stats_result) {
    $stats = mysqli_fetch_assoc($stats_result);
} else {
    $stats = [
        'pending_count' => 0,
        'approved_count' => 0,
        'rejected_count' => 0,
        'cancelled_count' => 0,
        'pending_days' => 0,
        'approved_days' => 0
    ];
}

// Get employees list for filter (restricted based on role)
$employees_query = "
    SELECT id, full_name, employee_code 
    FROM employees 
    WHERE employee_status = 'active'
";

if (!$isAdmin && !$isHr && $isManager) {
    if (!empty($reporting_employees)) {
        $reporting_ids = implode(',', $reporting_employees);
        $employees_query .= " AND (reporting_to = {$current_employee_id} OR id IN ({$reporting_ids}))";
    } else {
        $employees_query .= " AND reporting_to = {$current_employee_id}";
    }
}

$employees_query .= " ORDER BY full_name";
$employees_result = mysqli_query($conn, $employees_query);
$employees = [];
if ($employees_result) {
    while ($row = mysqli_fetch_assoc($employees_result)) {
        $employees[] = $row;
    }
}

// Get pending count for badge
$pending_count = $stats['pending_count'] ?? 0;

// Get user role display name
$user_role = 'User';
if ($isAdmin) $user_role = 'Administrator';
elseif ($isHr) $user_role = 'HR';
elseif ($isManager) $user_role = 'Manager';

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

function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $w) {
        $initials .= strtoupper(substr($w, 0, 1));
    }
    return substr($initials, 0, 2);
}

function getApproverInfo($request) {
    if (!empty($request['approved_by'])) {
        return '<i class="bi bi-check-circle-fill text-success me-1"></i> by ' . e($request['approver_name'] ?? 'Unknown');
    } elseif (!empty($request['rejected_by'])) {
        return '<i class="bi bi-x-circle-fill text-danger me-1"></i> by ' . e($request['rejector_name'] ?? 'Unknown');
    }
    return '';
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
$userRoleBadge = $isAdmin ? 'bg-danger' : ($isHr ? 'bg-info' : 'bg-warning');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leave Requests Management - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px; }
        .panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 8px 24px rgba(17,24,39,.06); padding:20px; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }

        .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
            padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; cursor:pointer; transition: all 0.2s; }
        .stat-card:hover{ transform: translateY(-2px); box-shadow: 0 12px 30px rgba(0,0,0,0.1); }
        .stat-card.active{ border:2px solid #3b82f6; background:#eff6ff; }
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

        .action-btn{ width:32px; height:32px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; 
            display:inline-flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; margin:0 2px; }
        .action-btn:hover{ background:#f3f4f6; color:#374151; }
        .action-btn.approve:hover{ background:#d1fae5; color:#065f46; border-color:#065f46; }
        .action-btn.reject:hover{ background:#fee2e2; color:#991b1b; border-color:#991b1b; }

        .employee-avatar{ width:40px; height:40px; border-radius:50%; background:#e5e7eb; display:flex; align-items:center; justify-content:center; font-weight:800; color:#4b5563; }
        .employee-avatar img{ width:40px; height:40px; border-radius:50%; object-fit:cover; }

        .days-badge{ background:#e6f7ff; color:#0050b3; padding:4px 8px; border-radius:20px; font-weight:700; font-size:12px; }

        .bulk-action-bar{ background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:12px; margin-bottom:16px; display:none; align-items:center; gap:12px; }
        .bulk-action-bar.show{ display:flex; }

        .nav-tabs .nav-link{ font-weight:700; color:#6b7280; border:none; padding:10px 20px; }
        .nav-tabs .nav-link.active{ color:#3b82f6; border-bottom:3px solid #3b82f6; background:none; }
        .nav-tabs .nav-link .badge{ margin-left:6px; }

        .leave-card{ background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:12px; }
        .leave-card:hover{ box-shadow:0 4px 12px rgba(0,0,0,0.05); }

        .role-badge{ font-size:11px; padding:4px 8px; border-radius:20px; font-weight:700; }
        .role-admin{ background:#fee2e2; color:#991b1b; }
        .role-hr{ background:#dbeafe; color:#1e40af; }
        .role-manager{ background:#fef3c7; color:#92400e; }

        @media (max-width: 768px) {
            .content-scroll{ padding:12px; }
            .bulk-action-bar{ flex-wrap:wrap; }
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

                <!-- Page Header with Role Badge -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h1 class="h3 fw-bold mb-1">
                            Leave Requests Management
                            <?php if ($pending_count > 0): ?>
                                <span class="badge bg-warning text-dark ms-2"><?= $pending_count ?> Pending</span>
                            <?php endif; ?>
                        </h1>
                        <div class="d-flex align-items-center gap-2">
                            <p class="text-muted mb-0">Review and manage employee leave requests</p>
                            <span class="role-badge <?= $userRoleBadge ?>">
                                <i class="bi bi-shield-check me-1"></i> <?= $user_role ?>
                            </span>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($isAdmin || $isHr): ?>
                        <button class="btn btn-outline-primary" onclick="exportToExcel()">
                            <i class="bi bi-file-excel"></i> Export
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
                </div>

                <!-- Action Messages -->
                <?php if (!empty($action_message)): ?>
                    <div class="alert alert-<?= $action_message_type ?> alert-dismissible fade show mb-3" role="alert">
                        <i class="bi bi-<?= $action_message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>-fill me-2"></i>
                        <?= e($action_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Row -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card <?= $status_filter === 'pending' ? 'active' : '' ?>" onclick="window.location.href='?status=pending'">
                            <div class="stat-ic orange"><i class="bi bi-clock"></i></div>
                            <div>
                                <div class="stat-label">Pending</div>
                                <div class="stat-value"><?= (int)($stats['pending_count'] ?? 0) ?></div>
                                <small><?= (int)($stats['pending_days'] ?? 0) ?> days</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card <?= $status_filter === 'approved' ? 'active' : '' ?>" onclick="window.location.href='?status=approved'">
                            <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="stat-label">Approved</div>
                                <div class="stat-value"><?= (int)($stats['approved_count'] ?? 0) ?></div>
                                <small><?= (int)($stats['approved_days'] ?? 0) ?> days</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card <?= $status_filter === 'rejected' ? 'active' : '' ?>" onclick="window.location.href='?status=rejected'">
                            <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                            <div>
                                <div class="stat-label">Rejected</div>
                                <div class="stat-value"><?= (int)($stats['rejected_count'] ?? 0) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card <?= $status_filter === 'all' ? 'active' : '' ?>" onclick="window.location.href='?status=all'">
                            <div class="stat-ic purple"><i class="bi bi-calendar-check"></i></div>
                            <div>
                                <div class="stat-label">Total</div>
                                <div class="stat-value">
                                    <?= (int)($stats['pending_count'] ?? 0) + (int)($stats['approved_count'] ?? 0) + (int)($stats['rejected_count'] ?? 0) + (int)($stats['cancelled_count'] ?? 0) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="filter-card">
                    <form method="GET" action="" id="filterForm">
                        <div class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Status</label>
                                <select name="status" class="form-select" onchange="this.form.submit()">
                                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Requests</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Employee</label>
                                <select name="employee_id" class="form-select select2" onchange="this.form.submit()">
                                    <option value="0">All Employees</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>" <?= $employee_filter == $emp['id'] ? 'selected' : '' ?>>
                                            <?= e($emp['full_name']) ?> (<?= e($emp['employee_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold">From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>" onchange="this.form.submit()">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold">To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>" onchange="this.form.submit()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Name, Code, Reason..." value="<?= e($search) ?>">
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Bulk Action Bar (Only for pending status) -->
                <?php if ($status_filter === 'pending' && !empty($leave_requests)): ?>
                <div class="bulk-action-bar" id="bulkActionBar">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAllCheckbox">
                        <label class="form-check-label fw-bold" for="selectAllCheckbox">
                            Select All (<span id="selectedCount">0</span> selected)
                        </label>
                    </div>
                    <div class="ms-auto d-flex gap-2">
                        <button class="btn btn-success" onclick="bulkApprove()">
                            <i class="bi bi-check-all"></i> Approve Selected
                        </button>
                        <button class="btn btn-danger" onclick="bulkReject()">
                            <i class="bi bi-x-circle"></i> Reject Selected
                        </button>
                        <button class="btn btn-outline-secondary" onclick="clearSelection()">
                            <i class="bi bi-x"></i> Clear
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Leave Requests Table/Cards -->
                <div class="panel">
                    <div class="panel-header">
                        <h5 class="panel-title">
                            <i class="bi bi-list-ul me-2"></i>
                            Leave Requests
                            <span class="badge bg-secondary ms-2"><?= count($leave_requests) ?></span>
                        </h5>
                    </div>

                    <!-- Desktop Table View -->
                    <div class="table-responsive d-none d-lg-block">
                        <table class="table align-middle" id="leaveTable">
                            <thead>
                                <tr>
                                    <?php if ($status_filter === 'pending'): ?>
                                        <th style="width:40px">
                                            <input class="form-check-input" type="checkbox" id="selectAllHeader">
                                        </th>
                                    <?php endif; ?>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>Period</th>
                                    <th>Days</th>
                                    <th>Reason</th>
                                    <th>Applied On</th>
                                    <th>Status / Approver</th>
                                    <?php if ($status_filter === 'pending'): ?>
                                    <th style="width:120px">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($leave_requests)): ?>
                                    <tr>
                                        <td colspan="<?= $status_filter === 'pending' ? '9' : '8' ?>" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                            No leave requests found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($leave_requests as $request): ?>
                                        <tr>
                                            <?php if ($status_filter === 'pending'): ?>
                                                <td>
                                                    <input class="form-check-input row-select" type="checkbox" value="<?= $request['id'] ?>">
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="employee-avatar">
                                                        <?php if (!empty($request['employee_photo'])): ?>
                                                            <img src="<?= e($request['employee_photo']) ?>" alt="<?= e($request['full_name']) ?>">
                                                        <?php else: ?>
                                                            <?= getInitials($request['full_name']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold"><?= e($request['full_name']) ?></div>
                                                        <small class="text-muted"><?= e($request['employee_code']) ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark"><?= e($request['leave_type']) ?></span>
                                            </td>
                                            <td>
                                                <?= safeDate($request['from_date']) ?><br>
                                                <small class="text-muted">to <?= safeDate($request['to_date']) ?></small>
                                            </td>
                                            <td>
                                                <span class="days-badge">
                                                    <i class="bi bi-calendar"></i> <?= $request['total_days'] ?> days
                                                </span>
                                            </td>
                                            <td>
                                                <div data-bs-toggle="tooltip" title="<?= e($request['reason']) ?>">
                                                    <?= e(substr($request['reason'], 0, 30)) ?>...
                                                </div>
                                            </td>
                                            <td>
                                                <?= safeDateTime($request['applied_at'] ?? $request['created_at']) ?>
                                            </td>
                                            <td>
                                                <?= getStatusBadge($request['status']) ?>
                                                <?php if ($request['status'] !== 'Pending'): ?>
                                                    <div class="small text-muted mt-1">
                                                        <?= getApproverInfo($request) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($status_filter === 'pending'): ?>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <button class="action-btn" onclick="viewDetails(<?= $request['id'] ?>)" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <?php 
                                                    // Check if user can approve this specific request
                                                    $canApproveThis = false;
                                                    if ($isAdmin || $isHr) {
                                                        $canApproveThis = true;
                                                    } elseif ($isManager) {
                                                        if ($request['reporting_to'] == $current_employee_id) {
                                                            $canApproveThis = true;
                                                        }
                                                    }
                                                    
                                                    if ($canApproveThis): 
                                                    ?>
                                                        <button class="action-btn approve" onclick="openApproveModal(<?= $request['id'] ?>, '<?= e($request['full_name']) ?>')" title="Approve">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                        <button class="action-btn reject" onclick="openRejectModal(<?= $request['id'] ?>, '<?= e($request['full_name']) ?>')" title="Reject">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View -->
                    <div class="d-block d-lg-none">
                        <?php if (empty($leave_requests)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No leave requests found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($leave_requests as $request): ?>
                                <div class="leave-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="employee-avatar" style="width:32px;height:32px;">
                                                <?php if (!empty($request['employee_photo'])): ?>
                                                    <img src="<?= e($request['employee_photo']) ?>" alt="<?= e($request['full_name']) ?>" style="width:32px;height:32px;">
                                                <?php else: ?>
                                                    <?= getInitials($request['full_name']) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?= e($request['full_name']) ?></div>
                                                <small class="text-muted"><?= e($request['employee_code']) ?></small>
                                            </div>
                                        </div>
                                        <?= getStatusBadge($request['status']) ?>
                                    </div>
                                    
                                    <div class="row g-2 mb-2">
                                        <div class="col-6">
                                            <small class="text-muted">Type:</small>
                                            <div><?= e($request['leave_type']) ?></div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Days:</small>
                                            <div><span class="days-badge"><?= $request['total_days'] ?> days</span></div>
                                        </div>
                                        <div class="col-12">
                                            <small class="text-muted">Period:</small>
                                            <div><?= safeDate($request['from_date']) ?> - <?= safeDate($request['to_date']) ?></div>
                                        </div>
                                        <div class="col-12">
                                            <small class="text-muted">Reason:</small>
                                            <div><?= e(substr($request['reason'], 0, 50)) ?>...</div>
                                        </div>
                                        <?php if ($request['status'] !== 'Pending'): ?>
                                        <div class="col-12">
                                            <small class="text-muted">Approver:</small>
                                            <div><?= getApproverInfo($request) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($status_filter === 'pending'): ?>
                                    <div class="d-flex gap-2 justify-content-end mt-2">
                                        <button class="btn btn-sm btn-outline-secondary" onclick="viewDetails(<?= $request['id'] ?>)">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <?php 
                                        $canApproveThis = false;
                                        if ($isAdmin || $isHr) {
                                            $canApproveThis = true;
                                        } elseif ($isManager) {
                                            if ($request['reporting_to'] == $current_employee_id) {
                                                $canApproveThis = true;
                                            }
                                        }
                                        
                                        if ($canApproveThis): 
                                        ?>
                                            <button class="btn btn-sm btn-success" onclick="openApproveModal(<?= $request['id'] ?>, '<?= e($request['full_name']) ?>')">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="openRejectModal(<?= $request['id'] ?>, '<?= e($request['full_name']) ?>')">
                                                <i class="bi bi-x-lg"></i> Reject
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
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

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="leave_id" id="approve_leave_id">
                <input type="hidden" name="leave_action" value="approve">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                        Approve Leave Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve leave request for <strong id="approve_employee_name"></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="2" placeholder="Add any remarks..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        You are approving as <strong><?= $user_role ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg"></i> Confirm Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="leave_id" id="reject_leave_id">
                <input type="hidden" name="leave_action" value="reject">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-x-circle-fill text-danger me-2"></i>
                        Reject Leave Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject leave request for <strong id="reject_employee_name"></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold required">Rejection Reason</label>
                        <textarea name="remarks" class="form-control" rows="3" required placeholder="Please provide reason for rejection..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        You are rejecting as <strong><?= $user_role ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg"></i> Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Reject Modal -->
<div class="modal fade" id="bulkRejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="bulk_action" value="reject_selected">
                <div id="bulkSelectedIds"></div>
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-x-circle-fill text-danger me-2"></i>
                        Bulk Reject Leave Requests
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject <span id="bulkCount"></span> selected leave requests?</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold required">Rejection Reason</label>
                        <textarea name="bulk_remarks" class="form-control" rows="3" required placeholder="Please provide reason for rejection..."></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        You can only reject requests you have permission for.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg"></i> Confirm Bulk Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    <?php if (!empty($leave_requests)): ?>
    $('#leaveTable').DataTable({
        pageLength: 25,
        ordering: true,
        searching: false,
        info: true,
        paging: true,
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ requests",
            infoEmpty: "No requests to show",
            infoFiltered: "(filtered from _MAX_ total requests)"
        }
    });
    <?php endif; ?>

    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Bulk selection functionality
    <?php if ($status_filter === 'pending' && !empty($leave_requests)): ?>
    const selectAllHeader = document.getElementById('selectAllHeader');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const rowCheckboxes = document.querySelectorAll('.row-select');
    const bulkActionBar = document.getElementById('bulkActionBar');
    const selectedCountSpan = document.getElementById('selectedCount');

    function updateBulkSelection() {
        const checked = document.querySelectorAll('.row-select:checked');
        selectedCountSpan.textContent = checked.length;
        
        if (checked.length > 0) {
            bulkActionBar.classList.add('show');
        } else {
            bulkActionBar.classList.remove('show');
        }

        // Update select all checkbox
        if (selectAllHeader) {
            selectAllHeader.checked = checked.length === rowCheckboxes.length;
            selectAllHeader.indeterminate = checked.length > 0 && checked.length < rowCheckboxes.length;
        }
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = checked.length === rowCheckboxes.length;
            selectAllCheckbox.indeterminate = checked.length > 0 && checked.length < rowCheckboxes.length;
        }
    }

    // Select all functionality
    if (selectAllHeader) {
        selectAllHeader.addEventListener('change', function() {
            rowCheckboxes.forEach(cb => {
                cb.checked = selectAllHeader.checked;
            });
            updateBulkSelection();
        });
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            rowCheckboxes.forEach(cb => {
                cb.checked = selectAllCheckbox.checked;
            });
            updateBulkSelection();
        });
    }

    // Individual checkbox changes
    if (rowCheckboxes.length > 0) {
        rowCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkSelection);
        });
    }
    <?php endif; ?>

    // Auto-submit filter form on search input change with debounce
    let searchTimeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });
    }
});

// View Details
function viewDetails(id) {
    window.location.href = 'leave-details.php?id=' + id;
}

// Open Approve Modal
function openApproveModal(id, employeeName) {
    document.getElementById('approve_leave_id').value = id;
    document.getElementById('approve_employee_name').textContent = employeeName;
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

// Open Reject Modal
function openRejectModal(id, employeeName) {
    document.getElementById('reject_leave_id').value = id;
    document.getElementById('reject_employee_name').textContent = employeeName;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

// Bulk Approve
function bulkApprove() {
    const selected = document.querySelectorAll('.row-select:checked');
    if (selected.length === 0) {
        alert('Please select at least one leave request.');
        return;
    }

    if (confirm(`Are you sure you want to approve ${selected.length} selected leave requests?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'bulk_action';
        actionInput.value = 'approve_selected';
        form.appendChild(actionInput);

        selected.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }
}

// Bulk Reject
function bulkReject() {
    const selected = document.querySelectorAll('.row-select:checked');
    if (selected.length === 0) {
        alert('Please select at least one leave request.');
        return;
    }

    // Populate bulk reject modal
    const idsContainer = document.getElementById('bulkSelectedIds');
    idsContainer.innerHTML = '';
    
    selected.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = cb.value;
        idsContainer.appendChild(input);
    });

    document.getElementById('bulkCount').textContent = selected.length;
    new bootstrap.Modal(document.getElementById('bulkRejectModal')).show();
}

// Clear Selection
function clearSelection() {
    document.querySelectorAll('.row-select').forEach(cb => {
        cb.checked = false;
    });
    updateBulkSelection();
}

// Export to Excel
function exportToExcel() {
    const rows = document.querySelectorAll('#leaveTable tbody tr');
    const csv = [];
    
    // Headers
    const headers = ['Employee', 'Leave Type', 'From Date', 'To Date', 'Days', 'Reason', 'Applied On', 'Status'];
    csv.push(headers.join(','));
    
    // Data rows
    rows.forEach(row => {
        if (row.cells.length >= 8) {
            const startIdx = <?= $status_filter === 'pending' ? '1' : '0' ?>;
            const employee = row.cells[startIdx]?.innerText.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim() || '';
            const leaveType = row.cells[startIdx + 1]?.innerText.trim() || '';
            const period = row.cells[startIdx + 2]?.innerText.trim() || '';
            const days = row.cells[startIdx + 3]?.innerText.trim() || '';
            const reason = row.cells[startIdx + 4]?.innerText.trim() || '';
            const appliedOn = row.cells[startIdx + 5]?.innerText.trim() || '';
            const status = row.cells[startIdx + 6]?.innerText.trim() || '';

            const fromDate = period.split('to')[0]?.trim() || '';
            const toDate = period.split('to')[1]?.trim() || '';

            const rowData = [
                '"' + employee.replace(/"/g, '""') + '"',
                '"' + leaveType.replace(/"/g, '""') + '"',
                '"' + fromDate.replace(/"/g, '""') + '"',
                '"' + toDate.replace(/"/g, '""') + '"',
                '"' + days.replace(/"/g, '""') + '"',
                '"' + reason.replace(/"/g, '""') + '"',
                '"' + appliedOn.replace(/"/g, '""') + '"',
                '"' + status.replace(/"/g, '""') + '"'
            ];
            csv.push(rowData.join(','));
        }
    });
    
    const csvString = csv.join('\n');
    const blob = new Blob(["\uFEFF" + csvString], { type: 'text/csv;charset=utf-8;' });
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