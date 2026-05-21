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

// ---------------- SCHEMA / ROLE HELPERS ----------------
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

function roleKeyFromEmployee(array $emp): string {
    $designation = strtolower(trim((string)($emp['designation'] ?? '')));
    $department  = strtolower(trim((string)($emp['department'] ?? '')));

    if (
        str_contains($designation, 'director') ||
        str_contains($designation, 'admin') ||
        str_contains($designation, 'administrator') ||
        str_contains($designation, 'vice president') ||
        str_contains($designation, 'general manager')
    ) return 'admin';

    if (
        str_contains($designation, 'hr') ||
        str_contains($department, 'hr') ||
        str_contains($department, 'human resource')
    ) return 'hr';

    if (str_contains($designation, 'manager')) return 'manager';

    if (
        str_contains($designation, 'team lead') ||
        str_contains($designation, 'tl') ||
        str_contains($designation, 'lead')
    ) return 'tl';

    return 'employee';
}

function sendNotification($conn, int $employeeId, string $title, string $message, string $module, int $referenceId, string $link = ''): bool {
    if ($employeeId <= 0 || !tableExists($conn, 'notifications')) return false;

    $cols = [];
    $vals = [];
    $types = '';

    $map = [
        'employee_id'  => ['i', $employeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', 'leave'],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'is_read'      => ['i', 0],
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
    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES ($placeholders)");
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$vals);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function logActivitySafe($conn, string $activityType, string $module, string $description, $referenceId = null, $referenceName = null, $oldData = null, $newData = null): bool {
    if (!$conn || !tableExists($conn, 'activity_logs')) return false;

    if (is_array($oldData) || is_object($oldData)) $oldData = json_encode($oldData, JSON_UNESCAPED_UNICODE);
    if (is_array($newData) || is_object($newData)) $newData = json_encode($newData, JSON_UNESCAPED_UNICODE);

    $employeeId   = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
    $employeeName = $_SESSION['employee_name'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'System';
    $username     = $_SESSION['username'] ?? $_SESSION['user_name'] ?? '';
    $designation  = $_SESSION['designation'] ?? $_SESSION['user_role'] ?? '';
    $department   = $_SESSION['department'] ?? '';
    $ipAddress    = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $cols = [];
    $vals = [];
    $types = '';

    $map = [
        'employee_id'    => ['i', $employeeId],
        'employee_name'  => ['s', $employeeName],
        'username'       => ['s', $username],
        'designation'    => ['s', $designation],
        'department'     => ['s', $department],
        'activity_type'  => ['s', $activityType],
        'action_type'    => ['s', $activityType],
        'module'         => ['s', $module],
        'description'    => ['s', $description],
        'reference_id'   => ['i', $referenceId],
        'reference_name' => ['s', $referenceName],
        'module_id'      => ['i', $referenceId],
        'module_name'    => ['s', $referenceName],
        'old_data'       => ['s', $oldData],
        'new_data'       => ['s', $newData],
        'ip_address'     => ['s', $ipAddress],
    ];

    foreach ($map as $col => $pair) {
        if (columnExists($conn, 'activity_logs', $col)) {
            $cols[] = "`$col`";
            $types .= $pair[0];
            $vals[] = $pair[1];
        }
    }

    if (!$cols) return false;

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $stmt = mysqli_prepare($conn, "INSERT INTO activity_logs (" . implode(',', $cols) . ") VALUES ($placeholders)");
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$vals);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function canProcessLeave(array $leave, int $currentEmployeeId, string $roleKey): bool {
    if ($currentEmployeeId <= 0) return false;

    // Current DB workflow: leave_requests.approver_id is the assigned approver.
    if (isset($leave['approver_id']) && (int)$leave['approver_id'] === $currentEmployeeId) {
        return true;
    }

    // HR/Admin can process all as final fallback.
    if (in_array($roleKey, ['admin', 'hr'], true)) {
        return true;
    }

    return false;
}

// Define role-based permissions using current employee table.
$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department = strtolower(trim($current_employee['department'] ?? ''));
$currentRoleKey = roleKeyFromEmployee($current_employee ?: []);

$isAdmin = ($currentRoleKey === 'admin');
$isHr = ($currentRoleKey === 'hr');
$isManager = ($currentRoleKey === 'manager');
$isTl = ($currentRoleKey === 'tl');

$canApprove = ($isAdmin || $isHr || $isManager || $isTl);

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
                $hasPermission = canProcessLeave($leave_data, (int)$current_employee_id, $currentRoleKey);

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
                            logActivitySafe(
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
            SELECT lr.*, e.reporting_to
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
            
            $hasPermission = canProcessLeave($row, (int)$current_employee_id, $currentRoleKey);

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
                    
                    logActivitySafe(
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

// Add permission restrictions based on role.
// Current DB: leave_requests.approver_id stores assigned approver.
if (!$isAdmin && !$isHr) {
    $query .= " AND lr.approver_id = " . (int)$current_employee_id;
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
    $stats_condition = " AND lr.approver_id = " . (int)$current_employee_id;
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

if (!$isAdmin && !$isHr) {
    $employees_query .= " AND id IN (
        SELECT DISTINCT employee_id
        FROM leave_requests
        WHERE approver_id = " . (int)$current_employee_id . "
    )";
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
elseif ($isTl) $user_role = 'Team Lead';
elseif ($isManager) $user_role = 'Manager';

// ---------------- HELPERS ----------------

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
            return '<span class="badge-pill ontrack"><span class="mini-dot"></span> Approved</span>';
        case 'Rejected':
            return '<span class="badge-pill atrisk"><span class="mini-dot"></span> Rejected</span>';
        case 'Pending':
            return '<span class="badge-pill pending"><span class="mini-dot"></span> Pending</span>';
        case 'Cancelled':
            return '<span class="badge-pill neutral"><span class="mini-dot"></span> Cancelled</span>';
        default:
            return '<span class="badge-pill neutral">' . e($status) . '</span>';
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
$userRoleBadge = $isAdmin ? 'atrisk' : ($isHr ? 'progressing' : ($isTl ? 'pending' : 'neutral'));
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
    .success-btn,
    .danger-btn {
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

    .success-btn {
        background: #16a34a;
        color: #fff;
    }

    .success-btn:hover {
        background: #15803d;
        color: #fff;
    }

    .danger-btn {
        background: #dc2626;
        color: #fff;
    }

    .danger-btn:hover {
        background: #b91c1c;
        color: #fff;
    }

    .panel,
    .filter-card {
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
        gap: 12px;
        margin-bottom: 12px;
    }

    .panel-title {
        font-weight: 900;
        font-size: 14px;
        margin: 0;
        color: var(--text);
    }

    .panel-subtitle {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
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
        cursor: pointer;
        transition: .15s ease;
    }

    .stat-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 32px rgba(15, 23, 42, .09);
    }

    .stat-card.active {
        border-color: #93c5fd;
        background: #eff6ff;
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

    .blue {
        background: #2f80ed;
    }

    .orange {
        background: #f2994a;
    }

    .green {
        background: #27ae60;
    }

    .red {
        background: #eb5757;
    }

    .purple {
        background: #8b5cf6;
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

    .form-label {
        font-size: 11px;
        font-weight: 900;
        color: #475569;
        text-transform: uppercase;
        margin-bottom: 6px;
    }

    .form-control,
    .form-select {
        min-height: 38px;
        border: 1px solid var(--border);
        border-radius: 11px;
        font-size: 12px;
        font-weight: 800;
        color: #111827;
        padding: 8px 11px;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .badge-pill {
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

    .mini-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    .ontrack {
        color: #15803d;
        background: #dcfce7;
        border-color: #bbf7d0;
    }

    .progressing {
        color: #2563eb;
        background: #dbeafe;
        border-color: #bfdbfe;
    }

    .pending {
        color: #6d28d9;
        background: #ede9fe;
        border-color: #ddd6fe;
    }

    .atrisk {
        color: #b91c1c;
        background: #fee2e2;
        border-color: #fecaca;
    }

    .neutral {
        color: #475569;
        background: #f1f5f9;
        border-color: #e2e8f0;
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

    .employee-cell {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 190px;
    }

    .employee-avatar {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        background: #111827;
        display: grid;
        place-items: center;
        font-weight: 950;
        font-size: 12px;
        color: #fff;
        overflow: hidden;
        flex: 0 0 auto;
    }

    .employee-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .table-primary-text {
        color: #111827;
        font-size: 11.5px;
        font-weight: 900;
    }

    .table-secondary-text {
        color: #64748b;
        font-size: 10px;
        font-weight: 700;
        margin-top: 1px;
    }

    .days-badge {
        background: #eff6ff;
        color: #2563eb;
        border: 1px solid #bfdbfe;
        padding: 4px 8px;
        border-radius: 999px;
        font-weight: 900;
        font-size: 10px;
        display: inline-flex;
        gap: 5px;
        align-items: center;
    }

    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        text-decoration: none;
        transition: .15s ease;
    }

    .action-btn:hover {
        background: #f8fafc;
        color: #111827;
    }

    .action-btn.approve:hover {
        background: #dcfce7;
        color: #15803d;
        border-color: #86efac;
    }

    .action-btn.reject:hover {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .bulk-action-bar {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 13px;
        box-shadow: var(--shadow);
        padding: 10px 12px;
        margin-bottom: 14px;
        display: none;
        align-items: center;
        gap: 12px;
    }

    .bulk-action-bar.show {
        display: flex;
    }

    .leave-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        box-shadow: var(--shadow);
        padding: 12px;
        margin-bottom: 12px;
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

    .modal-content {
        border: 1px solid var(--border);
        border-radius: 16px;
        box-shadow: 0 24px 55px rgba(15, 23, 42, .18);
    }

    .modal-header {
        border-bottom: 1px solid #eef2f7;
    }

    .modal-title {
        font-size: 15px;
        font-weight: 950;
        color: #111827;
    }

    .view-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .view-detail-card {
        border: 1px solid #eef2f7;
        background: #f8fafc;
        border-radius: 13px;
        padding: 10px;
    }

    .view-detail-card.full {
        grid-column: 1 / -1;
    }

    .view-detail-label {
        font-size: 10px;
        font-weight: 900;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .view-detail-value {
        font-size: 12px;
        font-weight: 850;
        color: #111827;
        word-break: break-word;
        line-height: 1.45;
    }

    .selected-date-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 5px 8px;
        margin: 2px;
        background: #eff6ff;
        color: #2563eb;
        border: 1px solid #bfdbfe;
        font-size: 10px;
        font-weight: 900;
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
            flex: 0 0 105px;
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
        .filter-card {
            padding: 12px;
        }

        .bulk-action-bar {
            flex-wrap: wrap;
        }

        .primary-btn,
        .secondary-btn,
        .success-btn,
        .danger-btn {
            width: 100%;
        }

        .view-detail-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main" aria-label="Main">
            <?php include 'includes/topbar.php'; ?>

            <div class="content-scroll">
                <div class="container-fluid projects-wrapper px-0">

                    <!-- Page Header -->
                    <div class="page-heading">
                        <div>
                            <h1>
                                Leave Requests Management
                                <?php if ($pending_count > 0): ?>
                                <span class="badge-pill pending ms-2">
                                    <span class="mini-dot"></span><?= $pending_count ?> Pending
                                </span>
                                <?php endif; ?>
                            </h1>
                            <p>Review and manage leave requests using current DB workflow: approver_id based approval.
                            </p>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge-pill <?= $userRoleBadge ?>">
                                <i class="bi bi-shield-check"></i>
                                <?= e($user_role) ?>
                            </span>

                            <?php if ($isAdmin || $isHr): ?>
                            <button class="secondary-btn" onclick="exportToExcel()">
                                <i class="bi bi-file-excel"></i>
                                Export
                            </button>
                            <?php endif; ?>

                            <button class="primary-btn" onclick="window.print()">
                                <i class="bi bi-printer"></i>
                                Print
                            </button>
                        </div>
                    </div>

                    <!-- Action Messages -->
                    <?php if (!empty($action_message)): ?>
                    <div class="alert alert-<?= $action_message_type ?> alert-dismissible fade show mb-3" role="alert">
                        <i
                            class="bi bi-<?= $action_message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>-fill me-2"></i>
                        <?= e($action_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Statistics Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="stat-card <?= $status_filter === 'pending' ? 'active' : '' ?>"
                                onclick="window.location.href='?status=pending'">
                                <div class="stat-ic orange"><i class="bi bi-clock"></i></div>
                                <div>
                                    <div class="stat-label">Pending</div>
                                    <div class="stat-value"><?= (int)($stats['pending_count'] ?? 0) ?></div>
                                    <small><?= (int)($stats['pending_days'] ?? 0) ?> days</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card <?= $status_filter === 'approved' ? 'active' : '' ?>"
                                onclick="window.location.href='?status=approved'">
                                <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                                <div>
                                    <div class="stat-label">Approved</div>
                                    <div class="stat-value"><?= (int)($stats['approved_count'] ?? 0) ?></div>
                                    <small><?= (int)($stats['approved_days'] ?? 0) ?> days</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card <?= $status_filter === 'rejected' ? 'active' : '' ?>"
                                onclick="window.location.href='?status=rejected'">
                                <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                                <div>
                                    <div class="stat-label">Rejected</div>
                                    <div class="stat-value"><?= (int)($stats['rejected_count'] ?? 0) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card <?= $status_filter === 'all' ? 'active' : '' ?>"
                                onclick="window.location.href='?status=all'">
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
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select" onchange="this.form.submit()">
                                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>
                                            Pending</option>
                                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>
                                            Approved</option>
                                        <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>
                                            Rejected</option>
                                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All
                                            Requests</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Employee</label>
                                    <select name="employee_id" class="form-select" onchange="this.form.submit()">
                                        <option value="0">All Employees</option>
                                        <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>"
                                            <?= $employee_filter == $emp['id'] ? 'selected' : '' ?>>
                                            <?= e($emp['full_name']) ?> (<?= e($emp['employee_code']) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">From Date</label>
                                    <input type="date" name="date_from" class="form-control"
                                        value="<?= e($date_from) ?>" onchange="this.form.submit()">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">To Date</label>
                                    <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>"
                                        onchange="this.form.submit()">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="search" class="form-control"
                                        placeholder="Name, Code, Reason..." value="<?= e($search) ?>">
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
                            <button class="success-btn" onclick="bulkApprove()">
                                <i class="bi bi-check-all"></i> Approve Selected
                            </button>
                            <button class="danger-btn" onclick="bulkReject()">
                                <i class="bi bi-x-circle"></i> Reject Selected
                            </button>
                            <button class="secondary-btn" onclick="clearSelection()">
                                <i class="bi bi-x"></i> Clear
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Leave Requests Table/Cards -->
                    <div class="panel">
                        <div class="panel-header">
                            <div>
                                <h5 class="panel-title">
                                    <i class="bi bi-list-ul me-2"></i>
                                    Leave Requests
                                </h5>
                                <div class="panel-subtitle">Filtered requests assigned by approver_id</div>
                            </div>
                            <span class="badge-pill neutral"><?= count($leave_requests) ?> Records</span>
                        </div>

                        <!-- Desktop Table View -->
                        <div class="compact-table-wrap">
                            <table class="table compact-table align-middle mb-0" id="leaveTable">
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
                                        <th style="width:120px">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($leave_requests)): ?>
                                    <tr>
                                        <td colspan="<?= $status_filter === 'pending' ? '9' : '8' ?>">
                                            <div class="empty-state"><i class="bi bi-inbox"></i>No leave requests found.
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($leave_requests as $request): ?>
                                    <tr>
                                        <?php if ($status_filter === 'pending'): ?>
                                        <td data-label="Select">
                                            <input class="form-check-input row-select" type="checkbox"
                                                value="<?= $request['id'] ?>">
                                        </td>
                                        <?php endif; ?>
                                        <td data-label="Employee">
                                            <div class="employee-cell">
                                                <div class="employee-avatar">
                                                    <?php if (!empty($request['employee_photo'])): ?>
                                                    <img src="<?= e($request['employee_photo']) ?>"
                                                        alt="<?= e($request['full_name']) ?>">
                                                    <?php else: ?>
                                                    <?= getInitials($request['full_name']) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="table-primary-text"><?= e($request['full_name']) ?>
                                                    </div>
                                                    <div class="table-secondary-text">
                                                        <?= e($request['employee_code']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Leave Type">
                                            <span class="badge-pill neutral"><?= e($request['leave_type']) ?></span>
                                        </td>
                                        <td data-label="Period">
                                            <?= safeDate($request['from_date']) ?><br>
                                            <small class="text-muted">to <?= safeDate($request['to_date']) ?></small>
                                        </td>
                                        <td data-label="Days">
                                            <span class="days-badge">
                                                <i class="bi bi-calendar"></i> <?= $request['total_days'] ?> days
                                            </span>
                                        </td>
                                        <td data-label="Reason">
                                            <div class="table-secondary-text" data-bs-toggle="tooltip"
                                                title="<?= e($request['reason']) ?>">
                                                <?= e(substr($request['reason'], 0, 30)) ?>...
                                            </div>
                                        </td>
                                        <td data-label="Applied On">
                                            <?= safeDateTime($request['applied_at'] ?? $request['created_at']) ?>
                                        </td>
                                        <td data-label="Status / Approver">
                                            <?= getStatusBadge($request['status']) ?>
                                            <?php if ($request['status'] !== 'Pending'): ?>
                                            <div class="small text-muted mt-1">
                                                <?= getApproverInfo($request) ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="d-flex gap-1 flex-wrap">
                                                <button type="button" class="action-btn"
                                                    onclick="openViewModal(<?= (int)$request['id'] ?>)"
                                                    title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>

                                                <?php
                                                    $canApproveThis = canProcessLeave($request, (int)$current_employee_id, $currentRoleKey);
                                                    if ($request['status'] === 'Pending' && $canApproveThis):
                                                    ?>
                                                <button type="button" class="action-btn approve"
                                                    onclick="openApproveModal(<?= (int)$request['id'] ?>, '<?= e($request['full_name']) ?>')"
                                                    title="Approve">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                                <button type="button" class="action-btn reject"
                                                    onclick="openRejectModal(<?= (int)$request['id'] ?>, '<?= e($request['full_name']) ?>')"
                                                    title="Reject">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
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


    <!-- View Leave Request Modal -->
    <div class="modal fade" id="viewLeaveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-eye-fill text-primary me-2"></i>
                        Leave Request Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">
                        <div>
                            <h6 class="fw-black mb-1" id="viewEmployeeName" style="font-weight:950;">—</h6>
                            <div class="table-secondary-text" id="viewEmployeeMeta">—</div>
                        </div>
                        <span class="badge-pill neutral" id="viewStatusBadge">—</span>
                    </div>

                    <div class="view-detail-grid">
                        <div class="view-detail-card">
                            <div class="view-detail-label">Request ID</div>
                            <div class="view-detail-value" id="viewRequestId">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">Leave Type</div>
                            <div class="view-detail-value" id="viewLeaveType">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">From Date</div>
                            <div class="view-detail-value" id="viewFromDate">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">To Date</div>
                            <div class="view-detail-value" id="viewToDate">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">Total Days</div>
                            <div class="view-detail-value" id="viewTotalDays">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">Applied On</div>
                            <div class="view-detail-value" id="viewAppliedOn">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">Contact During Leave</div>
                            <div class="view-detail-value" id="viewContact">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">Handover To</div>
                            <div class="view-detail-value" id="viewHandover">—</div>
                        </div>

                        <div class="view-detail-card full">
                            <div class="view-detail-label">Reason</div>
                            <div class="view-detail-value" id="viewReason">—</div>
                        </div>

                        <div class="view-detail-card full">
                            <div class="view-detail-label">Selected Dates</div>
                            <div class="view-detail-value" id="viewSelectedDates">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">Approved By</div>
                            <div class="view-detail-value" id="viewApprovedBy">—</div>
                        </div>

                        <div class="view-detail-card">
                            <div class="view-detail-label">Rejected By</div>
                            <div class="view-detail-value" id="viewRejectedBy">—</div>
                        </div>

                        <div class="view-detail-card full">
                            <div class="view-detail-label">Approver / Rejection Remarks</div>
                            <div class="view-detail-value" id="viewRemarks">—</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
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
                        <p>Are you sure you want to approve leave request for <strong
                                id="approve_employee_name"></strong>?</p>

                        <div class="mb-3">
                            <label class="form-label">Remarks (Optional)</label>
                            <textarea name="remarks" class="form-control" rows="2"
                                placeholder="Add any remarks..."></textarea>
                        </div>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            You are approving as <strong><?= $user_role ?></strong>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="success-btn">
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
                        <p>Are you sure you want to reject leave request for <strong
                                id="reject_employee_name"></strong>?</p>

                        <div class="mb-3">
                            <label class="form-label fw-bold required">Rejection Reason</label>
                            <textarea name="remarks" class="form-control" rows="3" required
                                placeholder="Please provide reason for rejection..."></textarea>
                        </div>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            You are rejecting as <strong><?= $user_role ?></strong>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="danger-btn">
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
                            <textarea name="bulk_remarks" class="form-control" rows="3" required
                                placeholder="Please provide reason for rejection..."></textarea>
                        </div>

                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            You can only reject requests you have permission for.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="danger-btn">
                            <i class="bi bi-x-lg"></i> Confirm Bulk Rejection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new bootstrap.Tooltip(el);
            });
        }

        <?php if ($status_filter === 'pending' && !empty($leave_requests)): ?>
        const selectAllHeader = document.getElementById('selectAllHeader');
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const rowCheckboxes = document.querySelectorAll('.row-select');
        const bulkActionBar = document.getElementById('bulkActionBar');
        const selectedCountSpan = document.getElementById('selectedCount');

        window.updateBulkSelection = function() {
            const checked = document.querySelectorAll('.row-select:checked');
            if (selectedCountSpan) selectedCountSpan.textContent = checked.length;

            if (bulkActionBar) {
                if (checked.length > 0) bulkActionBar.classList.add('show');
                else bulkActionBar.classList.remove('show');
            }

            if (selectAllHeader) {
                selectAllHeader.checked = checked.length === rowCheckboxes.length && rowCheckboxes.length >
                    0;
                selectAllHeader.indeterminate = checked.length > 0 && checked.length < rowCheckboxes.length;
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checked.length === rowCheckboxes.length && rowCheckboxes
                    .length > 0;
                selectAllCheckbox.indeterminate = checked.length > 0 && checked.length < rowCheckboxes
                    .length;
            }
        };

        if (selectAllHeader) {
            selectAllHeader.addEventListener('change', function() {
                rowCheckboxes.forEach(cb => cb.checked = selectAllHeader.checked);
                updateBulkSelection();
            });
        }

        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                rowCheckboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
                updateBulkSelection();
            });
        }

        rowCheckboxes.forEach(cb => cb.addEventListener('change', updateBulkSelection));
        <?php endif; ?>

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

    const leaveRequestDetails = <?php
$leaveViewData = [];
foreach ($leave_requests as $lr) {
    $selectedDates = [];
    $selectedJson = trim((string)($lr['selected_dates_json'] ?? ''));
    if ($selectedJson !== '') {
        $decoded = json_decode($selectedJson, true);
        if (is_array($decoded) && !empty($decoded['dates']) && is_array($decoded['dates'])) {
            foreach ($decoded['dates'] as $dateRow) {
                if (!is_array($dateRow)) continue;
                $selectedDates[] = [
                    'date' => safeDate($dateRow['date'] ?? ''),
                    'day_name' => (string)($dateRow['day_name'] ?? ''),
                    'half_day' => (string)($dateRow['half_day'] ?? '')
                ];
            }
        }
    }

    $leaveViewData[(int)$lr['id']] = [
        'id' => (int)$lr['id'],
        'full_name' => (string)($lr['full_name'] ?? ''),
        'employee_code' => (string)($lr['employee_code'] ?? ''),
        'designation' => (string)($lr['designation'] ?? ''),
        'department' => (string)($lr['department'] ?? ''),
        'leave_type' => (string)($lr['leave_type'] ?? ''),
        'from_date' => safeDate($lr['from_date'] ?? ''),
        'to_date' => safeDate($lr['to_date'] ?? ''),
        'total_days' => (string)($lr['total_days'] ?? ''),
        'reason' => (string)($lr['reason'] ?? ''),
        'contact_during_leave' => (string)($lr['contact_during_leave'] ?? ''),
        'handover_to' => (string)($lr['handover_to'] ?? ''),
        'status' => (string)($lr['status'] ?? ''),
        'applied_on' => safeDateTime($lr['applied_at'] ?? $lr['created_at'] ?? ''),
        'approved_by' => (string)($lr['approver_name'] ?? ''),
        'approved_at' => safeDateTime($lr['approved_at'] ?? ''),
        'approver_remarks' => (string)($lr['approver_remarks'] ?? ''),
        'rejected_by' => (string)($lr['rejector_name'] ?? ''),
        'rejected_at' => safeDateTime($lr['rejected_at'] ?? ''),
        'rejection_reason' => (string)($lr['rejection_reason'] ?? ''),
        'selected_dates' => $selectedDates
    ];
}
echo json_encode($leaveViewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value && String(value).trim() !== '' ? value : '—';
    }

    function openViewModal(id) {
        const data = leaveRequestDetails[id];
        if (!data) {
            alert('Leave request details not found.');
            return;
        }

        setText('viewEmployeeName', data.full_name);
        setText('viewEmployeeMeta',
            `${data.employee_code || '—'} • ${data.designation || '—'} • ${data.department || '—'}`);
        setText('viewRequestId', '#' + data.id);
        setText('viewLeaveType', data.leave_type);
        setText('viewFromDate', data.from_date);
        setText('viewToDate', data.to_date);
        setText('viewTotalDays', `${data.total_days || '0'} day(s)`);
        setText('viewAppliedOn', data.applied_on);
        setText('viewContact', data.contact_during_leave);
        setText('viewHandover', data.handover_to);
        setText('viewReason', data.reason);
        setText('viewApprovedBy', data.approved_by ?
            `${data.approved_by}${data.approved_at && data.approved_at !== '—' ? ' • ' + data.approved_at : ''}` :
            '—');
        setText('viewRejectedBy', data.rejected_by ?
            `${data.rejected_by}${data.rejected_at && data.rejected_at !== '—' ? ' • ' + data.rejected_at : ''}` :
            '—');

        const remarks = data.status === 'Rejected' ?
            data.rejection_reason :
            (data.approver_remarks || data.rejection_reason || '');
        setText('viewRemarks', remarks);

        const badge = document.getElementById('viewStatusBadge');
        if (badge) {
            const status = (data.status || '').toLowerCase();
            badge.className = 'badge-pill ' + (status === 'approved' ? 'ontrack' : status === 'rejected' ? 'atrisk' :
                status === 'pending' ? 'pending' : 'neutral');
            badge.innerHTML = `<span class="mini-dot"></span>${data.status || '—'}`;
        }

        const selectedDatesEl = document.getElementById('viewSelectedDates');
        if (selectedDatesEl) {
            if (data.selected_dates && data.selected_dates.length) {
                selectedDatesEl.innerHTML = data.selected_dates.map(function(row) {
                    const half = row.half_day ? ` • ${row.half_day}` : '';
                    return `<span class="selected-date-chip"><i class="bi bi-calendar-day"></i>${row.date}${row.day_name ? ' • ' + row.day_name : ''}${half}</span>`;
                }).join('');
            } else {
                selectedDatesEl.textContent = '—';
            }
        }

        new bootstrap.Modal(document.getElementById('viewLeaveModal')).show();
    }

    function openApproveModal(id, employeeName) {
        document.getElementById('approve_leave_id').value = id;
        document.getElementById('approve_employee_name').textContent = employeeName;
        new bootstrap.Modal(document.getElementById('approveModal')).show();
    }

    function openRejectModal(id, employeeName) {
        document.getElementById('reject_leave_id').value = id;
        document.getElementById('reject_employee_name').textContent = employeeName;
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    }

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

    function bulkReject() {
        const selected = document.querySelectorAll('.row-select:checked');
        if (selected.length === 0) {
            alert('Please select at least one leave request.');
            return;
        }

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

    function clearSelection() {
        document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
        if (typeof updateBulkSelection === 'function') updateBulkSelection();
    }

    function exportToExcel() {
        const rows = document.querySelectorAll('#leaveTable tbody tr');
        const csv = [];
        const headers = ['Employee', 'Leave Type', 'From Date', 'To Date', 'Days', 'Reason', 'Applied On', 'Status'];
        csv.push(headers.join(','));

        rows.forEach(row => {
            if (row.cells.length >= 8) {
                const startIdx = <?= $status_filter === 'pending' ? '1' : '0' ?>;
                const employee = row.cells[startIdx]?.innerText.replace(/\n/g, ' ').replace(/\s+/g, ' ')
                .trim() || '';
                const leaveType = row.cells[startIdx + 1]?.innerText.trim() || '';
                const period = row.cells[startIdx + 2]?.innerText.trim() || '';
                const days = row.cells[startIdx + 3]?.innerText.trim() || '';
                const reason = row.cells[startIdx + 4]?.innerText.trim() || '';
                const appliedOn = row.cells[startIdx + 5]?.innerText.trim() || '';
                const status = row.cells[startIdx + 6]?.innerText.trim() || '';

                const fromDate = period.split('to')[0]?.trim() || '';
                const toDate = period.split('to')[1]?.trim() || '';

                csv.push([
                    '"' + employee.replace(/"/g, '""') + '"',
                    '"' + leaveType.replace(/"/g, '""') + '"',
                    '"' + fromDate.replace(/"/g, '""') + '"',
                    '"' + toDate.replace(/"/g, '""') + '"',
                    '"' + days.replace(/"/g, '""') + '"',
                    '"' + reason.replace(/"/g, '""') + '"',
                    '"' + appliedOn.replace(/"/g, '""') + '"',
                    '"' + status.replace(/"/g, '""') + '"'
                ].join(','));
            }
        });

        const blob = new Blob(["\uFEFF" + csv.join('\n')], {
            type: 'text/csv;charset=utf-8;'
        });
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