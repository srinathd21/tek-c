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

// Get employee details and role
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$employee) {
    die("Employee not found or inactive.");
}

// Determine if user is a manager
$is_manager = in_array(strtolower(trim($employee['designation'])), ['manager', 'team lead', 'project manager', 'general manager', 'director'], true);

if (!$is_manager) {
    header("Location: attendance.php");
    exit;
}

// Process form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Approve regularization request
    if ($action === 'approve') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Get request details
        $req_query = "SELECT * FROM attendance_regularization WHERE id = ? AND status = 'Pending'";
        $req_stmt = mysqli_prepare($conn, $req_query);
        mysqli_stmt_bind_param($req_stmt, "i", $request_id);
        mysqli_stmt_execute($req_stmt);
        $req_res = mysqli_stmt_get_result($req_stmt);
        $request = mysqli_fetch_assoc($req_res);
        mysqli_stmt_close($req_stmt);
        
        if ($request) {
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                $attendance_date = $request['attendance_date'];
                $employee_id = $request['employee_id'];
                $request_type = $request['request_type'];
                $requested_punch_in = $request['requested_punch_in'];
                $requested_punch_out = $request['requested_punch_out'];
                
                // Check if attendance record exists for this date
                $check_attendance = "SELECT id, punch_in_time, punch_out_time, total_hours FROM attendance 
                                     WHERE employee_id = ? AND attendance_date = ?";
                $check_stmt = mysqli_prepare($conn, $check_attendance);
                mysqli_stmt_bind_param($check_stmt, "is", $employee_id, $attendance_date);
                mysqli_stmt_execute($check_stmt);
                $attendance_res = mysqli_stmt_get_result($check_stmt);
                $attendance = mysqli_fetch_assoc($attendance_res);
                mysqli_stmt_close($check_stmt);
                
                $current_punch_in = $attendance ? $attendance['punch_in_time'] : null;
                $current_punch_out = $attendance ? $attendance['punch_out_time'] : null;
                
                // Determine new punch times based on request type
                $new_punch_in = $current_punch_in;
                $new_punch_out = $current_punch_out;
                $update_attendance = false;
                
                switch ($request_type) {
                    case 'punch_in':
                        if (!empty($requested_punch_in)) {
                            $new_punch_in = $attendance_date . ' ' . $requested_punch_in;
                            $update_attendance = true;
                        }
                        break;
                    case 'punch_out':
                        if (!empty($requested_punch_out)) {
                            $new_punch_out = $attendance_date . ' ' . $requested_punch_out;
                            $update_attendance = true;
                        }
                        break;
                    case 'both':
                    case 'full_day':
                    case 'incorrect':
                        if (!empty($requested_punch_in)) {
                            $new_punch_in = $attendance_date . ' ' . $requested_punch_in;
                        }
                        if (!empty($requested_punch_out)) {
                            $new_punch_out = $attendance_date . ' ' . $requested_punch_out;
                        }
                        $update_attendance = true;
                        break;
                }
                
                if ($update_attendance) {
                    if ($attendance) {
                        // Update existing attendance record
                        $total_hours = null;
                        if ($new_punch_in && $new_punch_out) {
                            $in_time = strtotime($new_punch_in);
                            $out_time = strtotime($new_punch_out);
                            $diff_seconds = $out_time - $in_time;
                            $total_hours = round($diff_seconds / 3600, 2);
                        }
                        
                        $update_att_query = "UPDATE attendance SET 
                                            punch_in_time = ?, punch_out_time = ?, total_hours = ?,
                                            updated_at = NOW()
                                            WHERE employee_id = ? AND attendance_date = ?";
                        $update_stmt = mysqli_prepare($conn, $update_att_query);
                        mysqli_stmt_bind_param($update_stmt, "ssdis", 
                            $new_punch_in, $new_punch_out, $total_hours, $employee_id, $attendance_date);
                        mysqli_stmt_execute($update_stmt);
                        mysqli_stmt_close($update_stmt);
                    } else {
                        // Create new attendance record
                        $total_hours = null;
                        if ($new_punch_in && $new_punch_out) {
                            $in_time = strtotime($new_punch_in);
                            $out_time = strtotime($new_punch_out);
                            $diff_seconds = $out_time - $in_time;
                            $total_hours = round($diff_seconds / 3600, 2);
                        }
                        
                        $insert_att_query = "INSERT INTO attendance (
                            employee_id, attendance_date, punch_in_time, punch_out_time, total_hours,
                            status, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, 'present', NOW(), NOW())";
                        $insert_stmt = mysqli_prepare($conn, $insert_att_query);
                        mysqli_stmt_bind_param($insert_stmt, "isssd", 
                            $employee_id, $attendance_date, $new_punch_in, $new_punch_out, $total_hours);
                        mysqli_stmt_execute($insert_stmt);
                        mysqli_stmt_close($insert_stmt);
                    }
                }
                
                // Update regularization request status
                $update_reg_query = "UPDATE attendance_regularization SET 
                                    status = 'Approved', 
                                    approved_by = ?, 
                                    approved_by_name = ?,
                                    approved_at = NOW(),
                                    remarks = ?,
                                    updated_at = NOW()
                                    WHERE id = ?";
                $update_reg_stmt = mysqli_prepare($conn, $update_reg_query);
                mysqli_stmt_bind_param($update_reg_stmt, "issi", 
                    $current_employee_id, $current_employee_name, $remarks, $request_id);
                mysqli_stmt_execute($update_reg_stmt);
                mysqli_stmt_close($update_reg_stmt);
                
                // Commit transaction
                mysqli_commit($conn);
                
                $message = "Regularization request approved and attendance updated successfully.";
                $message_type = "success";
                
                // Log activity
                logActivity($conn, 'UPDATE', 'attendance_regularization', 
                    "Approved regularization request #{$request['request_no']} for {$attendance_date}", 
                    $request_id, $request['request_no']);
                    
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = "Failed to approve request: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Request not found or already processed.";
            $message_type = "danger";
        }
    }
    
    // Reject regularization request
    elseif ($action === 'reject') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        
        if (empty($rejection_reason)) {
            $message = "Please provide a reason for rejection.";
            $message_type = "danger";
        } else {
            $update_query = "UPDATE attendance_regularization SET 
                            status = 'Rejected', 
                            approved_by = ?, 
                            approved_by_name = ?,
                            approved_at = NOW(),
                            remarks = ?,
                            updated_at = NOW()
                            WHERE id = ? AND status = 'Pending'";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "issi", 
                $current_employee_id, $current_employee_name, $rejection_reason, $request_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $message = "Regularization request rejected.";
                $message_type = "success";
                
                // Get request details for logging
                $req_query = "SELECT request_no, attendance_date FROM attendance_regularization WHERE id = ?";
                $req_stmt = mysqli_prepare($conn, $req_query);
                mysqli_stmt_bind_param($req_stmt, "i", $request_id);
                mysqli_stmt_execute($req_stmt);
                $req_res = mysqli_stmt_get_result($req_stmt);
                $req_data = mysqli_fetch_assoc($req_res);
                mysqli_stmt_close($req_stmt);
                
                logActivity($conn, 'UPDATE', 'attendance_regularization', 
                    "Rejected regularization request #{$req_data['request_no']} for {$req_data['attendance_date']}. Reason: $rejection_reason", 
                    $request_id, $req_data['request_no']);
            } else {
                $message = "Failed to reject request: " . mysqli_error($conn);
                $message_type = "danger";
            }
            mysqli_stmt_close($update_stmt);
        }
    }
}

// Fetch pending regularization requests for employees under this manager
$requests_query = "
    SELECT 
        r.*,
        e.full_name as employee_name,
        e.employee_code,
        e.designation,
        e.department,
        s.project_name as site_name
    FROM attendance_regularization r
    LEFT JOIN employees e ON r.employee_id = e.id
    LEFT JOIN site_project_engineers spe ON e.id = spe.employee_id
    LEFT JOIN sites s ON spe.site_id = s.id AND s.manager_employee_id = ?
    WHERE r.status = 'Pending' 
      AND r.manager_id = ?
    GROUP BY r.id
    ORDER BY r.created_at ASC
";

$requests_stmt = mysqli_prepare($conn, $requests_query);
mysqli_stmt_bind_param($requests_stmt, "ii", $current_employee_id, $current_employee_id);
mysqli_stmt_execute($requests_stmt);
$requests_res = mysqli_stmt_get_result($requests_stmt);
$pending_requests = mysqli_fetch_all($requests_res, MYSQLI_ASSOC);
mysqli_stmt_close($requests_stmt);

// Fetch processed requests (approved/rejected) for history
$history_query = "
    SELECT 
        r.*,
        e.full_name as employee_name,
        e.employee_code,
        e.designation
    FROM attendance_regularization r
    LEFT JOIN employees e ON r.employee_id = e.id
    WHERE r.status IN ('Approved', 'Rejected')
      AND r.manager_id = ?
    ORDER BY r.updated_at DESC
    LIMIT 50
";

$history_stmt = mysqli_prepare($conn, $history_query);
mysqli_stmt_bind_param($history_stmt, "i", $current_employee_id);
mysqli_stmt_execute($history_stmt);
$history_res = mysqli_stmt_get_result($history_stmt);
$processed_requests = mysqli_fetch_all($history_res, MYSQLI_ASSOC);
mysqli_stmt_close($history_stmt);

// Helper functions
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function formatTime($time) {
    if (empty($time)) return '—';
    $ts = strtotime($time);
    return $ts ? date('h:i A', $ts) : $time;
}
function formatDateTime($datetime) {
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    return $ts ? date('d M Y, h:i A', $ts) : $datetime;
}
function getRequestTypeText($type) {
    $types = [
        'punch_in' => 'Missing Punch In',
        'punch_out' => 'Missing Punch Out',
        'both' => 'Both Punches Missing',
        'full_day' => 'Full Day Correction',
        'incorrect' => 'Incorrect Time Entry'
    ];
    return $types[$type] ?? ucfirst($type);
}
function getStatusBadge($status) {
    if ($status == 'Approved') {
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>';
    } elseif ($status == 'Rejected') {
        return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span>';
    }
    return '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Pending</span>';
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
    <title>Attendance Regularization Requests - TEK-C</title>
    
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
        
        .request-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
        }
        .request-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-color: #d1d5db;
        }
        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .request-employee {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .employee-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 18px;
        }
        .request-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin: 16px 0;
            padding: 12px;
            background: #f9fafb;
            border-radius: 12px;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-size: 11px;
            font-weight: 800;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .detail-value {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin-top: 4px;
        }
        .detail-value.old {
            color: #dc2626;
            text-decoration: line-through;
        }
        .detail-value.new {
            color: #10b981;
        }
        .reason-text {
            background: #fef3c7;
            padding: 12px;
            border-radius: 12px;
            font-size: 13px;
            color: #92400e;
            margin: 12px 0;
        }
        .btn-approve {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 700;
            transition: all 0.2s;
        }
        .btn-approve:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .btn-reject {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 700;
            transition: all 0.2s;
        }
        .btn-reject:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        
        .table-responsive { overflow-x: hidden !important; }
        table.dataTable { width: 100% !important; }
        .table thead th { font-size: 12px; color: #6b7280; font-weight: 900; border-bottom: 1px solid var(--border) !important; padding: 12px 10px !important; }
        .table td { vertical-align: middle; border-color: var(--border); font-weight: 600; color: #374151; padding: 12px 10px !important; }
        
        .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 1000; display: inline-flex; align-items: center; gap: 6px; }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px !important; }
            .request-details { grid-template-columns: 1fr; }
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
                        <h1 class="h3 fw-bold text-dark mb-1">Attendance Regularization Requests</h1>
                        <p class="text-muted mb-0">Review and process employee attendance correction requests</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="attendance.php" class="btn-action">
                            <i class="bi bi-box-arrow-in-right"></i> Punch In/Out
                        </a>
                        <a href="manage-regulations.php" class="btn-action">
                            <i class="bi bi-gear"></i> Manage Regulations
                        </a>
                    </div>
                </div>
                
                <!-- Flash Messages -->
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
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Pending Requests Section -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-clock-history me-2 text-warning"></i> Pending Requests
                        </h3>
                        <span class="badge bg-warning text-dark"><?php echo count($pending_requests); ?> pending</span>
                    </div>
                    
                    <?php if (empty($pending_requests)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle fs-1 text-success"></i>
                            <p class="mt-2 mb-0">No pending regularization requests.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_requests as $request): ?>
                            <div class="request-card">
                                <div class="request-header">
                                    <div class="request-employee">
                                        <div class="employee-avatar">
                                            <?php echo strtoupper(substr($request['employee_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <h5 class="fw-bold mb-0"><?php echo e($request['employee_name']); ?></h5>
                                            <p class="text-muted small mb-0">
                                                <?php echo e($request['employee_code']); ?> • <?php echo e($request['designation']); ?> • <?php echo e($request['department']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge bg-info"><?php echo e(getRequestTypeText($request['request_type'])); ?></span>
                                    </div>
                                </div>
                                
                                <div class="request-details">
                                    <div class="detail-item">
                                        <span class="detail-label">Request Date</span>
                                        <span class="detail-value"><?php echo e(date('d M Y', strtotime($request['attendance_date']))); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Current Punch In</span>
                                        <span class="detail-value old"><?php echo e(formatTime($request['current_punch_in'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Current Punch Out</span>
                                        <span class="detail-value old"><?php echo e(formatTime($request['current_punch_out'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Requested Punch In</span>
                                        <span class="detail-value new"><?php echo e(formatTime($request['requested_punch_in'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Requested Punch Out</span>
                                        <span class="detail-value new"><?php echo e(formatTime($request['requested_punch_out'])); ?></span>
                                    </div>
                                </div>
                                
                                <div class="reason-text">
                                    <i class="bi bi-chat-dots me-2"></i>
                                    <strong>Reason:</strong> <?php echo e($request['reason']); ?>
                                </div>
                                
                                <?php if ($request['supporting_document']): ?>
                                    <div class="mb-3">
                                        <a href="<?php echo e($request['supporting_document']); ?>" target="_blank" class="btn-action btn-sm">
                                            <i class="bi bi-file-earmark-pdf"></i> View Supporting Document
                                        </a>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="d-flex gap-2 justify-content-end">
                                    <button class="btn-approve" onclick="showApproveModal(<?php echo htmlspecialchars(json_encode($request)); ?>)">
                                        <i class="bi bi-check-lg"></i> Approve
                                    </button>
                                    <button class="btn-reject" onclick="showRejectModal(<?php echo $request['id']; ?>, '<?php echo e($request['employee_name']); ?>', '<?php echo e(date('d M Y', strtotime($request['attendance_date']))); ?>')">
                                        <i class="bi bi-x-lg"></i> Reject
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Processed Requests History -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-check2-circle me-2 text-success"></i> Processed Requests
                        </h3>
                        <span class="badge bg-light text-dark"><?php echo count($processed_requests); ?> processed</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table id="historyTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Request No.</th>
                                    <th>Employee</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Requested Times</th>
                                    <th>Status</th>
                                    <th>Processed On</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($processed_requests as $req): ?>
                                    <tr>
                                        <td><span class="fw-bold"><?php echo e($req['request_no']); ?></span></td>
                                        <td>
                                            <?php echo e($req['employee_name']); ?><br>
                                            <small class="text-muted"><?php echo e($req['employee_code']); ?></small>
                                        </td>
                                        <td><?php echo e(date('d M Y', strtotime($req['attendance_date']))); ?></td>
                                        <td><?php echo e(getRequestTypeText($req['request_type'])); ?></td>
                                        <td>
                                            <small>In: <?php echo e(formatTime($req['requested_punch_in'])); ?></small><br>
                                            <small>Out: <?php echo e(formatTime($req['requested_punch_out'])); ?></small>
                                        </td>
                                        <td><?php echo getStatusBadge($req['status']); ?></td>
                                        <td>
                                            <?php echo e(formatDateTime($req['approved_at'])); ?><br>
                                            <small class="text-muted">by <?php echo e($req['approved_by_name']); ?></small>
                                        </td>
                                        <td><small><?php echo e($req['remarks'] ?: '—'); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($processed_requests)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                            No processed requests found.
                                        </td>
                                    </tr>
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

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle text-success"></i> Approve Regularization</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" id="approve_request_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <p>You are about to approve the regularization request for:</p>
                        <div class="bg-light p-3 rounded">
                            <p class="mb-1"><strong>Employee:</strong> <span id="approve_employee_name"></span></p>
                            <p class="mb-1"><strong>Date:</strong> <span id="approve_date"></span></p>
                            <p class="mb-0"><strong>Request Type:</strong> <span id="approve_type"></span></p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Add any remarks..."></textarea>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Upon approval, the attendance record will be updated with the requested times.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-approve">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> Reject Regularization</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="reject_request_id">
                <div class="modal-body">
                    <p>You are about to reject the regularization request for:</p>
                    <div class="bg-light p-3 rounded mb-3">
                        <p class="mb-1"><strong>Employee:</strong> <span id="reject_employee_name"></span></p>
                        <p class="mb-0"><strong>Date:</strong> <span id="reject_date"></span></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Please provide reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-reject">Confirm Rejection</button>
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
    // Initialize DataTable for history
    $(document).ready(function() {
        if ($('#historyTable tbody tr').length > 1) {
            $('#historyTable').DataTable({
                responsive: true,
                autoWidth: false,
                pageLength: 10,
                order: [[6, 'desc']],
                language: {
                    zeroRecords: "No processed requests found",
                    info: "Showing _START_ to _END_ of _TOTAL_ requests",
                    infoEmpty: "No requests to show",
                    lengthMenu: "Show _MENU_",
                    search: "Search:"
                }
            });
        }
    });
    
    function showApproveModal(request) {
        document.getElementById('approve_request_id').value = request.id;
        document.getElementById('approve_employee_name').textContent = request.employee_name;
        document.getElementById('approve_date').textContent = new Date(request.attendance_date).toLocaleDateString('en-US', {day: 'numeric', month: 'short', year: 'numeric'});
        
        let typeText = '';
        switch(request.request_type) {
            case 'punch_in': typeText = 'Missing Punch In'; break;
            case 'punch_out': typeText = 'Missing Punch Out'; break;
            case 'both': typeText = 'Both Punches Missing'; break;
            case 'full_day': typeText = 'Full Day Correction'; break;
            case 'incorrect': typeText = 'Incorrect Time Entry'; break;
        }
        document.getElementById('approve_type').textContent = typeText;
        
        new bootstrap.Modal(document.getElementById('approveModal')).show();
    }
    
    function showRejectModal(id, employeeName, date) {
        document.getElementById('reject_request_id').value = id;
        document.getElementById('reject_employee_name').textContent = employeeName;
        document.getElementById('reject_date').textContent = date;
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    }
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