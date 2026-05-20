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

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEsc = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function employeeRoleKey(array $empRow): string {
    $designation = strtolower(trim((string)($empRow['designation'] ?? '')));
    $department  = strtolower(trim((string)($empRow['department'] ?? '')));
    $userRole    = strtolower(trim((string)($_SESSION['user_role'] ?? '')));

    if (
        str_contains($designation, 'admin') ||
        str_contains($designation, 'administrator') ||
        str_contains($department, 'admin') ||
        str_contains($userRole, 'admin')
    ) {
        return 'admin';
    }

    if (
        str_contains($designation, 'hr') ||
        str_contains($designation, 'human resource') ||
        str_contains($department, 'hr') ||
        str_contains($department, 'human resource') ||
        str_contains($userRole, 'hr')
    ) {
        return 'hr';
    }

    if (
        str_contains($designation, 'manager') ||
        str_contains($designation, 'project manager') ||
        str_contains($userRole, 'manager')
    ) {
        return 'manager';
    }

    if (
        str_contains($designation, 'team lead') ||
        str_contains($designation, 'tl') ||
        str_contains($designation, 'lead') ||
        str_contains($userRole, 'team lead') ||
        str_contains($userRole, 'tl')
    ) {
        return 'tl';
    }

    return 'employee';
}

function canAccessRegularizationRequest(array $request, int $currentEmployeeId, string $roleKey): bool {
    if ($currentEmployeeId <= 0) return false;

    if (in_array($roleKey, ['admin', 'hr'], true)) {
        return true;
    }

    return (int)($request['manager_id'] ?? 0) === $currentEmployeeId;
}

function sendNotification(
    $conn,
    int $toEmployeeId,
    string $title,
    string $message,
    string $module = 'attendance_regularization',
    ?int $referenceId = null,
    string $link = ''
): bool {
    if ($toEmployeeId <= 0 || !tableExists($conn, 'notifications')) return false;

    $cols = [];
    $vals = [];
    $types = '';

    $map = [
        'employee_id'  => ['i', $toEmployeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', 'attendance'],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'is_read'      => ['i', 0]
    ];

    foreach ($map as $col => $pair) {
        if (columnExists($conn, 'notifications', $col)) {
            $cols[] = "`$col`";
            $types .= $pair[0];
            $vals[] = $pair[1];
        }
    }

    if (!$cols) return false;

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$vals);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

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
    return $types[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

function getStatusBadge($status) {
    if ($status == 'Approved') {
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>';
    } elseif ($status == 'Rejected') {
        return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span>';
    }
    return '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Pending</span>';
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

// Determine approver access for TL / Manager / HR / Admin use cases
$currentRoleKey = employeeRoleKey($employee ?: []);
$is_admin_user = in_array($currentRoleKey, ['admin', 'hr'], true);
$is_approver_user = in_array($currentRoleKey, ['tl', 'manager', 'admin', 'hr'], true);

if (!$is_approver_user) {
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
        if ($is_admin_user) {
            $req_query = "SELECT * FROM attendance_regularization WHERE id = ? AND status = 'Pending'";
            $req_stmt = mysqli_prepare($conn, $req_query);
            mysqli_stmt_bind_param($req_stmt, "i", $request_id);
        } else {
            $req_query = "SELECT * FROM attendance_regularization WHERE id = ? AND status = 'Pending' AND manager_id = ?";
            $req_stmt = mysqli_prepare($conn, $req_query);
            mysqli_stmt_bind_param($req_stmt, "ii", $request_id, $current_employee_id);
        }
        mysqli_stmt_execute($req_stmt);
        $req_res = mysqli_stmt_get_result($req_stmt);
        $request = mysqli_fetch_assoc($req_res);
        mysqli_stmt_close($req_stmt);
        
        if ($request && canAccessRegularizationRequest($request, $current_employee_id, $currentRoleKey)) {
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

                sendNotification(
                    $conn,
                    (int)$employee_id,
                    "Attendance regularization approved",
                    "Your attendance regularization request #{$request['request_no']} for {$attendance_date} has been approved by {$current_employee_name}.",
                    "attendance_regularization",
                    $request_id,
                    "attendance-regularization.php"
                );
                    
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

// Fetch pending regularization requests.
// TL / Manager: only requests assigned to them through manager_id.
// Admin / HR: all pending requests, including admin-routed Manager/HR requests.
if ($is_admin_user) {
    $requests_query = "
        SELECT 
            r.*,
            e.full_name AS employee_name,
            e.employee_code,
            e.designation,
            e.department,
            approver.full_name AS routed_to_name
        FROM attendance_regularization r
        LEFT JOIN employees e ON r.employee_id = e.id
        LEFT JOIN employees approver ON approver.id = r.manager_id
        WHERE r.status = 'Pending'
        ORDER BY r.created_at ASC
    ";

    $requests_stmt = mysqli_prepare($conn, $requests_query);
} else {
    $requests_query = "
        SELECT 
            r.*,
            e.full_name AS employee_name,
            e.employee_code,
            e.designation,
            e.department,
            approver.full_name AS routed_to_name
        FROM attendance_regularization r
        LEFT JOIN employees e ON r.employee_id = e.id
        LEFT JOIN employees approver ON approver.id = r.manager_id
        WHERE r.status = 'Pending'
          AND r.manager_id = ?
        ORDER BY r.created_at ASC
    ";

    $requests_stmt = mysqli_prepare($conn, $requests_query);
    mysqli_stmt_bind_param($requests_stmt, "i", $current_employee_id);
}

mysqli_stmt_execute($requests_stmt);
$requests_res = mysqli_stmt_get_result($requests_stmt);
$pending_requests = mysqli_fetch_all($requests_res, MYSQLI_ASSOC);
mysqli_stmt_close($requests_stmt);

// Fetch processed requests history.
// TL / Manager: requests processed by them or assigned to them.
// Admin / HR: all processed requests.
if ($is_admin_user) {
    $history_query = "
        SELECT 
            r.*,
            e.full_name AS employee_name,
            e.employee_code,
            e.designation,
            approver.full_name AS routed_to_name
        FROM attendance_regularization r
        LEFT JOIN employees e ON r.employee_id = e.id
        LEFT JOIN employees approver ON approver.id = r.manager_id
        WHERE r.status IN ('Approved', 'Rejected')
        ORDER BY r.updated_at DESC
        LIMIT 50
    ";

    $history_stmt = mysqli_prepare($conn, $history_query);
} else {
    $history_query = "
        SELECT 
            r.*,
            e.full_name AS employee_name,
            e.employee_code,
            e.designation,
            approver.full_name AS routed_to_name
        FROM attendance_regularization r
        LEFT JOIN employees e ON r.employee_id = e.id
        LEFT JOIN employees approver ON approver.id = r.manager_id
        WHERE r.status IN ('Approved', 'Rejected')
          AND (r.manager_id = ? OR r.approved_by = ?)
        ORDER BY r.updated_at DESC
        LIMIT 50
    ";

    $history_stmt = mysqli_prepare($conn, $history_query);
    mysqli_stmt_bind_param($history_stmt, "ii", $current_employee_id, $current_employee_id);
}

mysqli_stmt_execute($history_stmt);
$history_res = mysqli_stmt_get_result($history_stmt);
$processed_requests = mysqli_fetch_all($history_res, MYSQLI_ASSOC);
mysqli_stmt_close($history_stmt);

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
    
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    
    <style>
        :root{
            --page-bg:#f5f7fb;
            --card-bg:#ffffff;
            --border:#e5e7eb;
            --text:#111827;
            --muted:#6b7280;
            --soft:#f8fafc;
            --shadow:0 10px 26px rgba(15,23,42,.055);
            --radius:15px;
        }

        body{ background:var(--page-bg); }

        .content-scroll{
            flex:1 1 auto;
            overflow:auto;
            padding:16px;
        }

        .projects-wrapper{ width:100%; }

        .page-heading{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .page-heading h1{
            font-size:19px;
            font-weight:900;
            color:var(--text);
            margin:0;
        }

        .page-heading p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:12px;
            font-weight:600;
        }

        .primary-btn,
        .secondary-btn,
        .danger-btn,
        .success-btn{
            min-height:36px;
            padding:0 14px;
            border-radius:11px;
            font-size:12px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            text-decoration:none;
            white-space:nowrap;
            border:0;
        }

        .primary-btn{
            background:#111827;
            color:#fff;
        }

        .primary-btn:hover{
            background:#020617;
            color:#fff;
        }

        .secondary-btn{
            border:1px solid var(--border);
            background:#fff;
            color:#334155;
        }

        .secondary-btn:hover{
            border-color:#cbd5e1;
            background:#f8fafc;
            color:#111827;
        }

        .success-btn{
            background:#16a34a;
            color:#fff;
        }

        .success-btn:hover{
            background:#15803d;
            color:#fff;
        }

        .danger-btn{
            background:#dc2626;
            color:#fff;
        }

        .danger-btn:hover{
            background:#b91c1c;
            color:#fff;
        }

        .stat-card{
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:12px 13px;
            min-height:78px;
            display:flex;
            align-items:center;
            gap:11px;
        }

        .stat-ic{
            width:38px;
            height:38px;
            border-radius:12px;
            display:grid;
            place-items:center;
            color:#fff;
            font-size:17px;
            flex:0 0 auto;
        }

        .blue{background:#2f80ed;}
        .orange{background:#f2994a;}
        .green{background:#27ae60;}
        .red{background:#eb5757;}
        .gray{background:#64748b;}

        .stat-label{
            color:var(--muted);
            font-weight:800;
            font-size:10.5px;
            text-transform:uppercase;
        }

        .stat-value{
            font-size:24px;
            font-weight:950;
            color:var(--text);
            line-height:1;
        }

        .panel{
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:13px;
            margin-bottom:14px;
        }

        .panel-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:12px;
        }

        .panel-title{
            font-weight:900;
            font-size:14px;
            margin:0;
            color:var(--text);
        }

        .panel-subtitle{
            color:var(--muted);
            font-size:11px;
            font-weight:700;
            margin-top:2px;
        }

        .badge-pill{
            border-radius:999px;
            padding:5px 8px;
            font-weight:900;
            font-size:10px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            border:1px solid transparent;
            text-decoration:none;
            white-space:nowrap;
        }

        .mini-dot{
            width:6px;
            height:6px;
            border-radius:50%;
            background:currentColor;
        }

        .ontrack{color:#15803d;background:#dcfce7;border-color:#bbf7d0;}
        .progressing{color:#2563eb;background:#dbeafe;border-color:#bfdbfe;}
        .pending{color:#6d28d9;background:#ede9fe;border-color:#ddd6fe;}
        .atrisk{color:#b91c1c;background:#fee2e2;border-color:#fecaca;}
        .neutral{color:#475569;background:#f1f5f9;border-color:#e2e8f0;}

        .request-card{
            border:1px solid var(--border);
            border-radius:14px;
            background:#fff;
            padding:12px;
            margin-bottom:12px;
            transition:.15s ease;
        }

        .request-card:hover{
            border-color:#cbd5e1;
            box-shadow:0 12px 28px rgba(15,23,42,.07);
        }

        .request-header{
            display:flex;
            justify-content:space-between;
            gap:12px;
            align-items:flex-start;
            margin-bottom:11px;
        }

        .request-employee{
            display:flex;
            align-items:center;
            gap:10px;
            min-width:0;
        }

        .employee-avatar{
            width:38px;
            height:38px;
            border-radius:12px;
            background:#111827;
            color:#fff;
            display:grid;
            place-items:center;
            font-size:14px;
            font-weight:950;
            flex:0 0 auto;
        }

        .employee-name{
            font-size:13px;
            font-weight:950;
            margin:0;
            color:#111827;
        }

        .employee-meta{
            color:#64748b;
            font-size:10.5px;
            font-weight:750;
            margin:2px 0 0;
            line-height:1.35;
        }

        .request-details{
            display:grid;
            grid-template-columns:repeat(5, minmax(0, 1fr));
            gap:8px;
            background:#f8fafc;
            border:1px solid #eef2f7;
            border-radius:12px;
            padding:10px;
            margin:10px 0;
        }

        .detail-item{
            min-width:0;
        }

        .detail-label{
            display:block;
            color:#64748b;
            font-size:9.5px;
            font-weight:900;
            text-transform:uppercase;
            margin-bottom:3px;
        }

        .detail-value{
            color:#111827;
            font-size:11.5px;
            font-weight:900;
            word-break:break-word;
        }

        .detail-value.old{
            color:#dc2626;
            text-decoration:line-through;
        }

        .detail-value.new{
            color:#15803d;
        }

        .reason-text{
            border:1px solid #fde68a;
            background:#fffbeb;
            color:#92400e;
            border-radius:12px;
            padding:9px 10px;
            font-size:11.5px;
            font-weight:750;
            line-height:1.45;
            margin:10px 0;
        }

        .action-row{
            display:flex;
            justify-content:flex-end;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
        }

        .compact-table-wrap{
            width:100%;
            border:1px solid var(--border);
            border-radius:13px;
            overflow:hidden;
            background:#fff;
        }

        .compact-table{
            width:100%;
            margin:0;
            table-layout:auto;
        }

        .compact-table thead th{
            background:var(--soft);
            color:#64748b;
            font-size:10px;
            text-transform:uppercase;
            font-weight:900;
            border-bottom:1px solid var(--border)!important;
            padding:8px 9px;
        }

        .compact-table tbody td{
            padding:8px 9px;
            vertical-align:middle;
            border-color:#eef2f7;
            color:#334155;
            font-weight:700;
            font-size:11.5px;
        }

        .compact-table tbody tr:hover{
            background:#fbfdff;
        }

        .table-primary-text{
            color:#111827;
            font-size:11.5px;
            font-weight:900;
        }

        .table-secondary-text{
            color:#64748b;
            font-size:10px;
            font-weight:700;
            margin-top:1px;
        }

        .empty-state{
            text-align:center;
            padding:30px 12px;
            color:#64748b;
            font-size:12px;
            font-weight:900;
        }

        .empty-state i{
            display:block;
            font-size:34px;
            opacity:.45;
            margin-bottom:8px;
        }

        .modal-content{
            border:1px solid var(--border);
            border-radius:16px;
            box-shadow:0 24px 55px rgba(15,23,42,.18);
        }

        .modal-header{
            border-bottom:1px solid #eef2f7;
        }

        .modal-title{
            font-size:15px;
            font-weight:950;
            color:#111827;
        }

        .form-label{
            font-size:11px;
            font-weight:900;
            color:#475569;
            text-transform:uppercase;
            margin-bottom:6px;
        }

        .form-control{
            border:1px solid var(--border);
            border-radius:11px;
            font-size:12px;
            font-weight:800;
            padding:8px 11px;
        }

        @media(max-width:991.98px){
            .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
            .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
            .sidebar.open, .sidebar.active, .sidebar.show{ transform:translateX(0)!important; }
            .request-details{ grid-template-columns:repeat(2, minmax(0, 1fr)); }
        }

        @media(max-width:1199px){
            .compact-table thead{ display:none; }
            .compact-table,
            .compact-table tbody,
            .compact-table tr,
            .compact-table td{
                display:block;
                width:100%;
            }
            .compact-table tbody tr{
                border-bottom:1px solid var(--border);
                padding:10px;
            }
            .compact-table tbody td{
                border:0;
                display:flex;
                justify-content:space-between;
                gap:12px;
            }
            .compact-table tbody td::before{
                content:attr(data-label);
                font-size:10px;
                font-weight:900;
                color:#64748b;
                text-transform:uppercase;
                flex:0 0 105px;
            }
        }

        @media(max-width:768px){
            .content-scroll{ padding:12px 10px 12px!important; }
            .page-heading{ align-items:flex-start; flex-direction:column; }
            .panel{ padding:12px; }
            .request-header{ flex-direction:column; }
            .request-details{ grid-template-columns:1fr; }
            .action-row{ justify-content:stretch; }
            .success-btn,.danger-btn,.secondary-btn,.primary-btn{ width:100%; }
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
                        <p class="text-muted mb-0">TL / Manager / Admin approval page for attendance correction requests</p>
                        <span class="badge bg-dark mt-2">Current Role: <?php echo e(strtoupper($currentRoleKey)); ?></span>
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
                        <div>
                            <h3 class="panel-title">
                                <i class="bi bi-clock-history me-2 text-warning"></i> Pending Requests
                            </h3>
                            <div class="panel-subtitle">Requests waiting for your approval</div>
                        </div>
                        <span class="badge-pill pending">
                            <span class="mini-dot"></span>
                            <?php echo count($pending_requests); ?> Pending
                        </span>
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
                                            <h5 class="employee-name"><?php echo e($request['employee_name']); ?></h5>
                                            <p class="employee-meta">
                                                <?php echo e($request['employee_code']); ?> • <?php echo e($request['designation']); ?> • <?php echo e($request['department']); ?>
                                                <?php if ($is_admin_user && !empty($request['routed_to_name'])): ?>
                                                    • Routed to: <?php echo e($request['routed_to_name']); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge-pill progressing"><i class="bi bi-pencil-square"></i><?php echo e(getRequestTypeText($request['request_type'])); ?></span>
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
                                        <a href="<?php echo e($request['supporting_document']); ?>" target="_blank" class="secondary-btn">
                                            <i class="bi bi-file-earmark-pdf"></i> View Supporting Document
                                        </a>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="action-row">
                                    <button class="success-btn" onclick="showApproveModal(<?php echo htmlspecialchars(json_encode($request)); ?>)">
                                        <i class="bi bi-check-lg"></i> Approve
                                    </button>
                                    <button class="danger-btn" onclick="showRejectModal(<?php echo $request['id']; ?>, '<?php echo e($request['employee_name']); ?>', '<?php echo e(date('d M Y', strtotime($request['attendance_date']))); ?>')">
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
                                        <td data-label="Request No."><span class="table-primary-text"><?php echo e($req['request_no']); ?></span></td>
                                        <td data-label="Employee">
                                            <span class="table-primary-text"><?php echo e($req['employee_name']); ?></span><br>
                                            <small class="text-muted"><?php echo e($req['employee_code']); ?></small>
                                            <?php if ($is_admin_user && !empty($req['routed_to_name'])): ?>
                                                <br><small class="text-muted">Routed to: <?php echo e($req['routed_to_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Date"><?php echo e(date('d M Y', strtotime($req['attendance_date']))); ?></td>
                                        <td data-label="Type"><?php echo e(getRequestTypeText($req['request_type'])); ?></td>
                                        <td data-label="Requested Times">
                                            <small>In: <?php echo e(formatTime($req['requested_punch_in'])); ?></small><br>
                                            <small>Out: <?php echo e(formatTime($req['requested_punch_out'])); ?></small>
                                        </td>
                                        <td data-label="Status"><?php echo getStatusBadge($req['status']); ?></td>
                                        <td data-label="Processed On">
                                            <?php echo e(formatDateTime($req['approved_at'])); ?><br>
                                            <small class="text-muted">by <?php echo e($req['approved_by_name']); ?></small>
                                        </td>
                                        <td data-label="Remarks"><small><?php echo e($req['remarks'] ?: '—'); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($processed_requests)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No processed requests found.
                                            </div>
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
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="success-btn">Confirm Approval</button>
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
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="danger-btn">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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