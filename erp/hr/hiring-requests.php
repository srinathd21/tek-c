<?php
// hr/hiring-requests.php - Hiring Request Management (TEK-C Style)
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

function hrTableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function hrColumnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function logHiringActivityCurrentDb($conn, int $employeeId, string $activityType, string $description, int $referenceId, array $newData = []): bool {
    if (!$conn || !hrTableExists($conn, 'activity_logs')) return false;

    $newJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;
    $employeeName = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'System';
    $username = $_SESSION['username'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $department = $_SESSION['department'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $map = [
        'employee_id'   => ['i', $employeeId],
        'employee_name' => ['s', $employeeName],
        'username'      => ['s', $username],
        'designation'   => ['s', $designation],
        'department'    => ['s', $department],
        'activity_type' => ['s', $activityType],
        'module'        => ['s', 'hiring_request'],
        'description'   => ['s', $description],
        'reference_id'  => ['i', $referenceId],
        'new_data'      => ['s', $newJson],
        'ip_address'    => ['s', $ipAddress],
    ];

    $cols = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (hrColumnExists($conn, 'activity_logs', $column)) {
            $cols[] = "`$column`";
            $types .= $pair[0];
            $values[] = $pair[1];
        }
    }

    if (!$cols) return false;

    $sql = "INSERT INTO activity_logs (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function createNotificationCurrentDb($conn, int $employeeId, string $title, string $message, string $module, int $referenceId, string $link, string $type = 'hiring'): bool {
    if ($employeeId <= 0 || !$conn || !hrTableExists($conn, 'notifications')) return false;

    $map = [
        'employee_id'  => ['i', $employeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', $type],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'priority'     => ['s', 'normal'],
        'is_read'      => ['i', 0],
        'created_at'   => ['raw', 'NOW()'],
    ];

    $cols = [];
    $placeholders = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (hrColumnExists($conn, 'notifications', $column)) {
            $cols[] = "`$column`";
            if ($pair[0] === 'raw') {
                $placeholders[] = $pair[1];
            } else {
                $placeholders[] = '?';
                $types .= $pair[0];
                $values[] = $pair[1];
            }
        }
    }

    if (!$cols) return false;

    $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    if ($values) {
        mysqli_stmt_bind_param($stmt, $types, ...$values);
    }

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function getHiringRequestBeforeAction($conn, int $requestId): ?array {
    $stmt = mysqli_prepare($conn, "
        SELECT id, request_no, position_title, requested_by, requested_by_name, status
        FROM hiring_requests
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, "i", $requestId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function notifyHiringRequestManager($conn, array $request, int $actorId, string $status, string $remarks = ''): void {
    $managerId = (int)($request['requested_by'] ?? 0);
    if ($managerId <= 0 || $managerId === $actorId) {
        return;
    }

    $requestNo = (string)($request['request_no'] ?? '');
    $positionTitle = (string)($request['position_title'] ?? '');

    $title = $status === 'Approved'
        ? 'Hiring request approved'
        : 'Hiring request rejected';

    $message = 'Your hiring request ' . $requestNo . ' for ' . $positionTitle . ' has been ' . strtolower($status) . ' by HR.';
    if ($remarks !== '') {
        $message .= ' Remarks: ' . $remarks;
    }

    createNotificationCurrentDb(
        $conn,
        $managerId,
        $title,
        $message,
        'hiring_request',
        (int)$request['id'],
        'view-hiring-request.php?id=' . (int)$request['id'],
        'hiring'
    );
}


// ---------------- AUTH (HR/Manager) ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];

// Get current employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$current_employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die("Employee not found.");
}

// Check permissions
$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department = strtolower(trim($current_employee['department'] ?? ''));

$isHr = ($designation === 'hr' || $department === 'hr');
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'director', 'administrator']);
$isAdmin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director');

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- HANDLE APPROVAL/REJECTION (HR/Admin only) ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($isHr || $isAdmin)) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($request_id <= 0) {
        $message = "Invalid request selected.";
        $messageType = "danger";
    } else {
        $requestBeforeAction = getHiringRequestBeforeAction($conn, $request_id);

        if (!$requestBeforeAction) {
            $message = "Hiring request not found.";
            $messageType = "danger";
        } elseif (($requestBeforeAction['status'] ?? '') !== 'Pending') {
            $message = "Only pending hiring requests can be processed.";
            $messageType = "danger";
        } elseif ($_POST['action'] === 'approve') {
            $update_stmt = mysqli_prepare($conn, "
                UPDATE hiring_requests
                SET status = 'Approved',
                    approved_by = ?,
                    approved_by_name = ?,
                    approved_at = NOW(),
                    approver_remarks = ?
                WHERE id = ?
                  AND status = 'Pending'
            ");

            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, "issi", $current_employee_id, $current_employee['full_name'], $remarks, $request_id);

                if (mysqli_stmt_execute($update_stmt)) {
                    logHiringActivityCurrentDb(
                        $conn,
                        (int)$current_employee_id,
                        'UPDATE',
                        "Approved hiring request ID: {$request_id}",
                        $request_id,
                        ['remarks' => $remarks, 'status' => 'Approved']
                    );

                    notifyHiringRequestManager($conn, $requestBeforeAction, (int)$current_employee_id, 'Approved', $remarks);

                    $message = "Hiring request approved successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error approving request: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            } else {
                $message = "Error preparing approval: " . mysqli_error($conn);
                $messageType = "danger";
            }
        } elseif ($_POST['action'] === 'reject') {
            $update_stmt = mysqli_prepare($conn, "
                UPDATE hiring_requests
                SET status = 'Rejected',
                    rejected_by = ?,
                    rejected_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
                  AND status = 'Pending'
            ");

            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, "isi", $current_employee_id, $remarks, $request_id);

                if (mysqli_stmt_execute($update_stmt)) {
                    logHiringActivityCurrentDb(
                        $conn,
                        (int)$current_employee_id,
                        'UPDATE',
                        "Rejected hiring request ID: {$request_id}",
                        $request_id,
                        ['reason' => $remarks, 'status' => 'Rejected']
                    );

                    notifyHiringRequestManager($conn, $requestBeforeAction, (int)$current_employee_id, 'Rejected', $remarks);

                    $message = "Hiring request rejected successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error rejecting request: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            } else {
                $message = "Error preparing rejection: " . mysqli_error($conn);
                $messageType = "danger";
            }
        }
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'all';
$department_filter = $_GET['department'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT h.*, 
           COUNT(c.id) as candidates_count,
           SUM(CASE WHEN c.status IN ('Selected', 'Offered', 'Joined') THEN 1 ELSE 0 END) as selected_count,
           SUM(CASE WHEN c.status = 'Joined' THEN 1 ELSE 0 END) as joined_count
    FROM hiring_requests h
    LEFT JOIN candidates c ON h.id = c.hiring_request_id
    WHERE 1=1
";

// Managers see only their own requests, HR sees all
if (!$isHr && !$isAdmin && $isManager) {
    $query .= " AND h.requested_by = {$current_employee_id}";
}

if ($status_filter !== 'all') {
    $status_filter = mysqli_real_escape_string($conn, ucfirst($status_filter));
    $query .= " AND h.status = '{$status_filter}'";
}

if (!empty($department_filter)) {
    $department_filter = mysqli_real_escape_string($conn, $department_filter);
    $query .= " AND h.department = '{$department_filter}'";
}

if (!empty($priority_filter)) {
    $priority_filter = mysqli_real_escape_string($conn, $priority_filter);
    $query .= " AND h.priority = '{$priority_filter}'";
}

if (!empty($search)) {
    $search_term = mysqli_real_escape_string($conn, $search);
    $query .= " AND (h.request_no LIKE '%{$search_term}%' 
                     OR h.position_title LIKE '%{$search_term}%'
                     OR h.designation LIKE '%{$search_term}%'
                     OR h.requested_by_name LIKE '%{$search_term}%')";
}

$query .= " GROUP BY h.id ORDER BY 
    CASE h.priority 
        WHEN 'Urgent' THEN 1 
        WHEN 'High' THEN 2 
        WHEN 'Medium' THEN 3 
        WHEN 'Low' THEN 4 
    END, 
    h.created_at DESC";

$requests_result = mysqli_query($conn, $query);
$requests = [];
if ($requests_result) {
    while ($row = mysqli_fetch_assoc($requests_result)) {
        $requests[] = $row;
    }
    mysqli_free_result($requests_result);
}

// Get counts for dashboard
$stats_query = "
    SELECT 
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(vacancies) as total_vacancies
    FROM hiring_requests
";
if (!$isHr && !$isAdmin && $isManager) {
    $stats_query .= " WHERE requested_by = {$current_employee_id}";
}
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// ---------------- HELPER FUNCTIONS ----------------
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($date, $format = 'd M Y')
{
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($date));
}

function getStatusBadge($status) {
    $status = trim((string)$status);
    $map = [
        'Pending'     => ['pending', 'bi-clock'],
        'Approved'    => ['ontrack', 'bi-check-circle'],
        'In Progress' => ['progressing', 'bi-gear'],
        'Rejected'    => ['atrisk', 'bi-x-circle'],
        'Closed'      => ['ontrack', 'bi-check2-circle'],
        'Cancelled'   => ['neutral', 'bi-x']
    ];
    $item = $map[$status] ?? ['neutral', 'bi-info-circle'];
    return "<span class='badge-pill {$item[0]}'><i class='bi {$item[1]}'></i> " . e($status ?: '—') . "</span>";
}

function getPriorityBadge($priority) {
    $priority = trim((string)$priority);
    $map = [
        'Urgent' => ['atrisk', 'bi-exclamation-triangle-fill'],
        'High'   => ['warning', 'bi-arrow-up-circle-fill'],
        'Medium' => ['progressing', 'bi-dash-circle-fill'],
        'Low'    => ['neutral', 'bi-arrow-down-circle-fill']
    ];
    $item = $map[$priority] ?? ['neutral', 'bi-info-circle'];
    return "<span class='badge-pill {$item[0]}'><i class='bi {$item[1]}'></i> " . e($priority ?: '—') . "</span>";
}

function getProgressIndicator($vacancies, $selected, $joined) {
    $filled = $joined;
    $in_progress = $selected - $joined;
    $remaining = $vacancies - $selected;
    
    $filled_width = $vacancies > 0 ? round(($filled / $vacancies) * 100) : 0;
    $progress_width = $vacancies > 0 ? round(($in_progress / $vacancies) * 100) : 0;
    
    $html = '<div class="d-flex align-items-center gap-2">';
    $html .= '<div class="flex-grow-1">';
    $html .= '<div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">';
    if ($filled > 0) {
        $html .= '<div style="height: 6px; background: #10b981; width: ' . $filled_width . '%; float: left;"></div>';
    }
    if ($in_progress > 0) {
        $html .= '<div style="height: 6px; background: #f59e0b; width: ' . $progress_width . '%; float: left;"></div>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<span class="fw-900" style="font-size:12px;">' . $filled . '/' . $vacancies . '</span>';
    $html .= '</div>';
    
    return $html;
}

$departments = ['PM', 'CM', 'IFM', 'QS', 'HR', 'ACCOUNTS'];
$priorities = ['Urgent', 'High', 'Medium', 'Low'];

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hiring Requests - TEK-C Hiring</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    

    <!-- TEK-C Custom Styles -->
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
            --blue:#2f80ed;
            --green:#27ae60;
            --orange:#f2994a;
            --red:#eb5757;
            --purple:#7c3aed;
        }
        body{background:var(--page-bg);}
        .content-scroll{flex:1 1 auto;overflow:auto;padding:16px;}
        .projects-wrapper{width:100%;}
        .page-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;}
        .page-heading h1{font-size:19px;font-weight:950;color:var(--text);margin:0;}
        .page-heading p{margin:3px 0 0;color:var(--muted);font-size:12px;font-weight:650;}
        .primary-btn,.secondary-btn,.btn-action,.success-btn,.danger-btn{min-height:36px;padding:0 14px;border-radius:11px;font-size:12px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:7px;text-decoration:none;white-space:nowrap;line-height:1;border:0;}
        .primary-btn{background:#111827;color:#fff;}
        .primary-btn:hover{background:#020617;color:#fff;}
        .success-btn{background:#16a34a;color:#fff;}
        .success-btn:hover{background:#15803d;color:#fff;}
        .danger-btn{background:#dc2626;color:#fff;}
        .danger-btn:hover{background:#b91c1c;color:#fff;}
        .secondary-btn,.btn-action{border:1px solid var(--border);background:#fff;color:#334155;}
        .secondary-btn:hover,.btn-action:hover{border-color:#cbd5e1;background:#f8fafc;color:#111827;}
        .panel,.filter-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:13px;margin-bottom:14px;height:auto;}
        .panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;}
        .panel-title{font-weight:950;font-size:14px;color:var(--text);margin:0;display:flex;align-items:center;gap:8px;}
        .panel-title i{color:var(--blue);font-size:16px;}
        .panel-subtitle{color:var(--muted);font-size:11px;font-weight:700;margin-top:2px;}
        .panel-menu{width:34px;height:34px;border-radius:11px;border:1px solid var(--border);background:#fff;display:grid;place-items:center;color:#64748b;flex:0 0 auto;}
        .stat-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:12px 13px;min-height:78px;display:flex;align-items:center;gap:11px;transition:.15s ease;}
        .stat-card:hover{transform:translateY(-1px);box-shadow:0 14px 32px rgba(15,23,42,.09);}
        .stat-ic{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:17px;flex:0 0 auto;}
        .stat-ic.blue{background:var(--blue);}
        .stat-ic.green{background:var(--green);}
        .stat-ic.yellow{background:var(--orange);}
        .stat-ic.purple{background:var(--purple);}
        .stat-ic.red{background:var(--red);}
        .stat-label{color:#64748b;font-weight:850;font-size:10.5px;text-transform:uppercase;}
        .stat-value{font-size:24px;font-weight:950;line-height:1;color:#111827;margin-top:2px;}
        .form-label{font-size:11px;font-weight:900;color:#475569;text-transform:uppercase;margin-bottom:6px;}
        .form-control,.form-select{min-height:38px;border:1px solid var(--border);border-radius:11px;font-size:12px;font-weight:800;color:#111827;padding:8px 11px;background:#fff;}
        .form-control:focus,.form-select:focus{border-color:#bfdbfe;box-shadow:0 0 0 3px rgba(59,130,246,.10);}
        textarea.form-control{min-height:86px;}
        .badge-pill{border-radius:999px;padding:5px 8px;font-weight:900;font-size:10px;display:inline-flex;align-items:center;gap:6px;border:1px solid transparent;text-decoration:none;white-space:nowrap;}
        .ontrack{color:#15803d;background:#dcfce7;border-color:#bbf7d0;}
        .progressing{color:#2563eb;background:#dbeafe;border-color:#bfdbfe;}
        .pending{color:#6d28d9;background:#ede9fe;border-color:#ddd6fe;}
        .atrisk{color:#b91c1c;background:#fee2e2;border-color:#fecaca;}
        .neutral{color:#475569;background:#f1f5f9;border-color:#e2e8f0;}
        .warning{color:#b45309;background:#ffedd5;border-color:#fed7aa;}
        .compact-table-wrap{width:100%;border:1px solid var(--border);border-radius:13px;overflow:hidden;background:#fff;}
        .compact-table{width:100%;margin:0;table-layout:auto;}
        .compact-table thead th{background:var(--soft);color:#64748b;font-size:10px;text-transform:uppercase;font-weight:900;border-bottom:1px solid var(--border)!important;padding:8px 9px;white-space:nowrap;}
        .compact-table tbody td{padding:8px 9px;vertical-align:middle;border-color:#eef2f7;color:#334155;font-weight:700;font-size:11.5px;}
        .compact-table tbody tr:hover{background:#fbfdff;}
        .request-no{font-weight:950;font-size:12px;color:#2563eb;}
        .position-title{font-weight:950;font-size:12px;color:#111827;margin-bottom:2px;line-height:1.25;}
        .designation-text,.request-date{font-size:10.5px;color:#64748b;font-weight:750;}
        .requester-name{font-weight:900;font-size:11.5px;color:#111827;}
        .progress{height:7px;background-color:#e5e7eb;border-radius:999px;overflow:hidden;}
        .progress-bar-filled{height:7px;background:#16a34a;float:left;}
        .progress-bar-progress{height:7px;background:#f59e0b;float:left;}
        .btn-action{min-height:31px;min-width:31px;padding:0 9px;border-radius:10px;margin:0 2px;}
        .btn-action.view:hover,.btn-action.people:hover{color:#2563eb;border-color:#bfdbfe;background:#eff6ff;}
        .btn-action.success:hover{color:#15803d;border-color:#86efac;background:#dcfce7;}
        .btn-action.danger:hover{color:#b91c1c;border-color:#fecaca;background:#fee2e2;}
        .actions-col{width:170px;white-space:nowrap!important;}
        .empty-state{text-align:center;padding:30px 12px;color:#64748b;font-size:12px;font-weight:900;}
        .empty-state i{display:block;font-size:34px;opacity:.45;margin-bottom:8px;}
        .modal-content{border:1px solid var(--border);border-radius:16px;box-shadow:0 24px 55px rgba(15,23,42,.18);}
        .modal-header{border-bottom:1px solid #eef2f7;padding:14px 16px;}
        .modal-title{font-size:15px;font-weight:950;color:#111827;}
        .modal-body{padding:16px;}
        .modal-footer{border-top:1px solid #eef2f7;padding:14px 16px;}
        .required:after{content:" *";color:#ef4444;}
        .alert{border-radius:14px;border:1px solid transparent;box-shadow:var(--shadow);margin-bottom:14px;font-size:12px;font-weight:850;}
        .alert-success{background:#dcfce7;border-color:#bbf7d0;color:#166534;}
        .alert-danger{background:#fee2e2;border-color:#fecaca;color:#991b1b;}
        .alert-warning{background:#fffbeb;border-color:#fde68a;color:#92400e;}
        @media(max-width:991.98px){
            .main{margin-left:0!important;width:100%!important;max-width:100%!important;}
            .sidebar{position:fixed!important;transform:translateX(-100%);z-index:1040!important;}
            .sidebar.open,.sidebar.active,.sidebar.show{transform:translateX(0)!important;}
        }
        @media(max-width:1199px){
            .compact-table thead{display:none;}
            .compact-table,.compact-table tbody,.compact-table tr,.compact-table td{display:block;width:100%;}
            .compact-table tbody tr{border-bottom:1px solid var(--border);padding:10px;}
            .compact-table tbody td{border:0;display:flex;justify-content:space-between;gap:12px;}
            .compact-table tbody td::before{content:attr(data-label);font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;flex:0 0 110px;}
            .compact-table tbody td:first-child{display:block;}
            .compact-table tbody td:first-child::before{display:none;}
            .actions-col{width:auto!important;}
        }
        @media(max-width:768px){
            .content-scroll{padding:12px 10px!important;}
            .container-fluid.projects-wrapper{padding-left:0!important;padding-right:0!important;}
            .page-heading{align-items:flex-start;flex-direction:column;}
            .panel,.filter-card{padding:12px;}
            .primary-btn,.secondary-btn,.success-btn,.danger-btn{width:100%;}
            .modal-footer{flex-direction:column-reverse;align-items:stretch;}
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

                <!-- Flash Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
                        <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="page-heading">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h1>Hiring Requests</h1>
                            <span class="badge-pill progressing">
                                <i class="bi bi-person-badge"></i>
                                <?php echo $isHr ? 'HR' : ($isAdmin ? 'Admin' : 'Manager'); ?>
                            </span>
                        </div>
                        <p>
                            <?php if ($isHr || $isAdmin): ?>
                                Review, approve, reject, and track recruitment requests.
                            <?php else: ?>
                                Track your hiring requests and recruitment progress.
                            <?php endif; ?>
                        </p>
                    </div>

                    <a href="new-hiring-request.php" class="primary-btn">
                        <i class="bi bi-plus-circle"></i>
                        New Request
                    </a>
                </div>

                <!-- Stats Cards -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-clock"></i></div>
                            <div>
                                <div class="stat-label">Pending</div>
                                <div class="stat-value"><?php echo (int)($stats['pending'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="stat-label">Approved</div>
                                <div class="stat-value"><?php echo (int)($stats['approved'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-gear"></i></div>
                            <div>
                                <div class="stat-label">In Progress</div>
                                <div class="stat-value"><?php echo (int)($stats['in_progress'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                            <div>
                                <div class="stat-label">Closed</div>
                                <div class="stat-value"><?php echo (int)($stats['closed'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                            <div>
                                <div class="stat-label">Rejected</div>
                                <div class="stat-value"><?php echo (int)($stats['rejected'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-people"></i></div>
                            <div>
                                <div class="stat-label">Vacancies</div>
                                <div class="stat-value"><?php echo (int)($stats['total_vacancies'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="filter-card">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-12 col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="in progress" <?php echo $status_filter === 'in progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept; ?>" <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                        <?php echo $dept; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="">All Priorities</option>
                                <?php foreach ($priorities as $p): ?>
                                    <option value="<?php echo $p; ?>" <?php echo $priority_filter === $p ? 'selected' : ''; ?>>
                                        <?php echo $p; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Request No., Position, Requester..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-12 col-md-2 d-flex gap-2">
                            <button type="submit" class="primary-btn w-100">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                            <a href="hiring-requests.php" class="secondary-btn">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Requests Table -->
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title"><i class="bi bi-briefcase"></i> Hiring Requests</h3>
                            <div class="panel-subtitle">Showing <?php echo count($requests); ?> request(s)</div>
                        </div>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <div class="compact-table-wrap">
                        <table id="requestsTable" class="table compact-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Request No.</th>
                                    <th>Position</th>
                                    <th>Department</th>
                                    <th>Vacancies</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Requested By</th>
                                    <th>Progress</th>
                                    <th class="text-end actions-col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($requests)): ?>
                                    
                                <?php else: ?>
                                    <?php foreach ($requests as $row): ?>
                                        <tr>
                                            <td data-label="Request No.">
                                                <span class="request-no"><?php echo e($row['request_no']); ?></span>
                                            </td>
                                            <td data-label="Position">
                                                <div class="position-title"><?php echo e($row['position_title']); ?></div>
                                                <div class="designation-text"><?php echo e($row['designation']); ?></div>
                                            </td>
                                            <td data-label="Department"><?php echo e($row['department']); ?></td>
                                            <td data-label="Vacancies" class="text-center fw-900"><?php echo (int)$row['vacancies']; ?></td>
                                            <td data-label="Priority"><?php echo getPriorityBadge($row['priority']); ?></td>
                                            <td data-label="Status"><?php echo getStatusBadge($row['status']); ?></td>
                                            <td data-label="Requested By">
                                                <div class="requester-name"><?php echo e($row['requested_by_name']); ?></div>
                                                <div class="request-date">
                                                    <i class="bi bi-calendar"></i> <?php echo safeDate($row['requested_date']); ?>
                                                </div>
                                            </td>
                                            <td data-label="Progress" style="min-width: 150px;">
                                                <?php 
                                                $filled = (int)$row['joined_count'];
                                                $selected = (int)$row['selected_count'];
                                                $vacancies = (int)$row['vacancies'];
                                                ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="flex-grow-1">
                                                        <div class="progress">
                                                            <?php if ($filled > 0): ?>
                                                                <div class="progress-bar-filled" style="width: <?php echo $vacancies > 0 ? ($filled/$vacancies)*100 : 0; ?>%"></div>
                                                            <?php endif; ?>
                                                            <?php if ($selected - $filled > 0): ?>
                                                                <div class="progress-bar-progress" style="width: <?php echo $vacancies > 0 ? (($selected-$filled)/$vacancies)*100 : 0; ?>%"></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <span class="fw-900" style="font-size:12px;">
                                                        <?php echo $filled; ?>/<?php echo $vacancies; ?>
                                                    </span>
                                                </div>
                                                <div class="d-flex gap-2 mt-1">
                                                    <small class="text-success">
                                                        <i class="bi bi-check-circle-fill" style="font-size:8px;"></i> <?php echo $selected; ?> selected
                                                    </small>
                                                    <?php if ($filled > 0): ?>
                                                        <small class="text-primary">
                                                            <i class="bi bi-person-check-fill" style="font-size:8px;"></i> <?php echo $filled; ?> joined
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="Actions" class="text-end actions-col">
                                                <a href="view-hiring-request.php?id=<?php echo $row['id']; ?>" class="btn-action view" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                
                                                <?php if ($row['status'] === 'Pending' && ($isHr || $isAdmin)): ?>
                                                    <button class="btn-action success" 
                                                            onclick="openApproveModal(<?php echo $row['id']; ?>, '<?php echo e($row['request_no']); ?>')" 
                                                            title="Approve">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                    <button class="btn-action danger" 
                                                            onclick="openRejectModal(<?php echo $row['id']; ?>, '<?php echo e($row['request_no']); ?>')" 
                                                            title="Reject">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <a href="candidates.php?hiring_id=<?php echo $row['id']; ?>" class="btn-action people" title="View Candidates">
                                                    <i class="bi bi-people"></i>
                                                </a>
                                                
                                                <?php if ($row['status'] === 'Approved' || $row['status'] === 'In Progress'): ?>
                                                    <a href="candidates.php?hiring_id=<?php echo $row['id']; ?>&add=1" class="btn-action success" title="Add Candidate">
                                                        <i class="bi bi-person-plus"></i>
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
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" id="approve_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Approve Hiring Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p class="mb-3">Are you sure you want to approve <strong id="approve_no"></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2" placeholder="Add any remarks..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="success-btn">Approve Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="reject_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Reject Hiring Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p class="mb-3">Are you sure you want to reject <strong id="reject_no"></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label required">Reason for Rejection</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Please provide reason for rejection" required></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="danger-btn">Reject Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
function openApproveModal(id, requestNo) {
    const approveId = document.getElementById('approve_id');
    const approveNo = document.getElementById('approve_no');

    if (approveId) approveId.value = id;
    if (approveNo) approveNo.textContent = requestNo;

    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function openRejectModal(id, requestNo) {
    const rejectId = document.getElementById('reject_id');
    const rejectNo = document.getElementById('reject_no');

    if (rejectId) rejectId.value = id;
    if (rejectNo) rejectNo.textContent = requestNo;

    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    const yearElement = document.getElementById('year');
    if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
    }
});
</script>

</body>
</html>
