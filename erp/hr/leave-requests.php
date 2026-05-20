<?php
// leave-requests.php - Leave Requests Management
// TEK-C compact table section style

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- AUTH ---------------- */

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = (int)$_SESSION['employee_id'];

/* ---------------- HELPERS ---------------- */

function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($v, $dash = '—'){

    $v = trim((string)$v);

    if ($v === '' || $v === '0000-00-00') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateTime($v, $dash = '—'){

    $v = trim((string)$v);

    if ($v === '' || $v === '0000-00-00 00:00:00') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function getInitials($name){

    $name = trim((string)$name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name);

    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last  = strtoupper(substr(end($parts) ?: '', 0, 1));

    return count($parts) > 1
        ? $first . $last
        : $first;
}

function fileUrl($path){

    $p = trim((string)$path);

    if ($p === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $p)) {
        return $p;
    }

    if (stripos($p, '../admin/uploads/') === 0) {
        return $p;
    }

    if (stripos($p, 'admin/uploads/') === 0) {
        return '../' . $p;
    }

    if (stripos($p, '/admin/uploads/') === 0) {
        return '..' . $p;
    }

    if (stripos($p, 'uploads/') === 0) {
        return '../admin/' . $p;
    }

    if (stripos($p, '/uploads/') === 0) {
        return '../admin' . $p;
    }

    if (stripos($p, 'employees/') === 0) {
        return '../admin/uploads/' . $p;
    }

    if (stripos($p, '/employees/') === 0) {
        return '../admin/uploads' . $p;
    }

    return '../admin/uploads/' . ltrim($p, '/');
}

function leaveStatusBadge($status){

    $status = trim((string)$status);

    if ($status === 'Approved') {
        return ['Approved', 'ontrack', 'approved'];
    }

    if ($status === 'Rejected') {
        return ['Rejected', 'danger', 'rejected'];
    }

    if ($status === 'Pending') {
        return ['Pending', 'warning', 'pending'];
    }

    if ($status === 'Cancelled') {
        return ['Cancelled', 'muted', 'cancelled'];
    }

    return [$status ?: 'Unknown', 'muted', strtolower($status ?: 'unknown')];
}

function approverInfo($request){

    if (!empty($request['approved_by'])) {
        return 'Approved by ' . ($request['approver_name'] ?? 'Unknown');
    }

    if (!empty($request['rejected_by'])) {
        return 'Rejected by ' . ($request['rejector_name'] ?? 'Unknown');
    }

    return '';
}

function logActivityLocal(
    $conn,
    $activity_type,
    $module,
    $description,
    $reference_id = null
){

    $employee_id = $_SESSION['employee_id'] ?? null;
    $employee_name = $_SESSION['employee_name'] ?? '';
    $username = $_SESSION['username'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $department = $_SESSION['department'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO activity_logs
        (
            employee_id,
            employee_name,
            username,
            designation,
            department,
            activity_type,
            module,
            description,
            reference_id,
            ip_address
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            "isssssssis",
            $employee_id,
            $employee_name,
            $username,
            $designation,
            $department,
            $activity_type,
            $module,
            $description,
            $reference_id,
            $ip
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/* ---------------- CURRENT EMPLOYEE ---------------- */

$current_employee = null;

$emp_stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM employees
     WHERE id = ?
     AND employee_status = 'active'
     LIMIT 1"
);

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

/* ---------------- ROLE PERMISSIONS ---------------- */

$designation = strtolower(trim((string)($current_employee['designation'] ?? '')));
$department  = strtolower(trim((string)($current_employee['department'] ?? '')));

$isAdmin =
    $designation === 'administrator' ||
    $designation === 'admin' ||
    $designation === 'director';

$isHr =
    $designation === 'hr' ||
    $department === 'hr';

$isManager =
    in_array(
        $designation,
        [
            'manager',
            'team lead',
            'project manager',
            'project engineer grade 1',
            'project engineer grade 2'
        ],
        true
    );

$reporting_employees = [];

if ($isManager && !$isHr && !$isAdmin) {

    $reporting_stmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM employees
         WHERE reporting_to = ?"
    );

    if ($reporting_stmt) {

        mysqli_stmt_bind_param(
            $reporting_stmt,
            "i",
            $current_employee_id
        );

        mysqli_stmt_execute($reporting_stmt);

        $reporting_res = mysqli_stmt_get_result($reporting_stmt);

        while ($row = mysqli_fetch_assoc($reporting_res)) {
            $reporting_employees[] = (int)$row['id'];
        }

        mysqli_stmt_close($reporting_stmt);
    }
}

$canApprove =
    $isAdmin ||
    $isHr ||
    $isManager;

if (!$canApprove) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

$user_role = 'User';

if ($isAdmin) {
    $user_role = 'Administrator';
} elseif ($isHr) {
    $user_role = 'HR';
} elseif ($isManager) {
    $user_role = 'Manager';
}

$userRoleClass =
    $isAdmin
    ? 'role-admin'
    : ($isHr ? 'role-hr' : 'role-manager');

/* ---------------- ACTION MESSAGES ---------------- */

$action_message = '';
$action_message_type = '';

/* ---------------- APPROVE / REJECT ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_action'])) {

    $leave_id = (int)($_POST['leave_id'] ?? 0);
    $action = trim((string)($_POST['leave_action'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($leave_id <= 0) {

        $action_message = "Invalid leave request selected.";
        $action_message_type = "danger";

    } elseif ($action === 'reject' && $remarks === '') {

        $action_message = "Rejection reason is required.";
        $action_message_type = "danger";

    } else {

        $get_stmt = mysqli_prepare(
            $conn,
            "SELECT
                lr.*,
                e.full_name,
                e.employee_code,
                e.reporting_to
             FROM leave_requests lr
             JOIN employees e
             ON lr.employee_id = e.id
             WHERE lr.id = ?
             LIMIT 1"
        );

        if ($get_stmt) {

            mysqli_stmt_bind_param($get_stmt, "i", $leave_id);
            mysqli_stmt_execute($get_stmt);

            $get_res = mysqli_stmt_get_result($get_stmt);
            $leave_data = mysqli_fetch_assoc($get_res);

            mysqli_stmt_close($get_stmt);

            if (!$leave_data) {

                $action_message = "Leave request not found.";
                $action_message_type = "danger";

            } else {

                $hasPermission = false;

                if ($isAdmin || $isHr) {

                    $hasPermission = true;

                } elseif ($isManager) {

                    if ((int)$leave_data['reporting_to'] === $current_employee_id) {
                        $hasPermission = true;
                    }

                    if (in_array((int)$leave_data['employee_id'], $reporting_employees, true)) {
                        $hasPermission = true;
                    }
                }

                if (!$hasPermission) {

                    $action_message = "You don't have permission to process this leave request.";
                    $action_message_type = "danger";

                } elseif ($leave_data['status'] !== 'Pending') {

                    $action_message = "This leave request is already " . e($leave_data['status']) . ".";
                    $action_message_type = "warning";

                } else {

                    $update_stmt = null;
                    $log_action = '';
                    $log_desc = '';

                    if ($action === 'approve') {

                        $update_stmt = mysqli_prepare(
                            $conn,
                            "UPDATE leave_requests
                             SET
                                status = 'Approved',
                                approved_by = ?,
                                approved_at = NOW(),
                                approver_remarks = ?
                             WHERE id = ?
                             AND status = 'Pending'"
                        );

                        if ($update_stmt) {

                            mysqli_stmt_bind_param(
                                $update_stmt,
                                "isi",
                                $current_employee_id,
                                $remarks,
                                $leave_id
                            );

                            $log_action = 'APPROVE';
                            $log_desc =
                                "Approved leave request for " .
                                $leave_data['full_name'] .
                                " (" .
                                $leave_data['total_days'] .
                                " days)";
                        }

                    } elseif ($action === 'reject') {

                        $update_stmt = mysqli_prepare(
                            $conn,
                            "UPDATE leave_requests
                             SET
                                status = 'Rejected',
                                rejected_by = ?,
                                rejected_at = NOW(),
                                rejection_reason = ?
                             WHERE id = ?
                             AND status = 'Pending'"
                        );

                        if ($update_stmt) {

                            mysqli_stmt_bind_param(
                                $update_stmt,
                                "isi",
                                $current_employee_id,
                                $remarks,
                                $leave_id
                            );

                            $log_action = 'REJECT';
                            $log_desc =
                                "Rejected leave request for " .
                                $leave_data['full_name'] .
                                " (" .
                                $leave_data['total_days'] .
                                " days)";
                        }
                    }

                    if ($update_stmt) {

                        if (mysqli_stmt_execute($update_stmt)) {

                            logActivityLocal(
                                $conn,
                                $log_action,
                                'LEAVE',
                                $log_desc,
                                $leave_id
                            );

                            $action_message =
                                $action === 'approve'
                                ? "Leave request approved successfully."
                                : "Leave request rejected successfully.";

                            $action_message_type = "success";

                        } else {

                            $action_message =
                                "Failed to process leave request: " .
                                mysqli_stmt_error($update_stmt);

                            $action_message_type = "danger";
                        }

                        mysqli_stmt_close($update_stmt);
                    }
                }
            }
        }
    }
}

/* ---------------- BULK ACTIONS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {

    $selected_ids = $_POST['selected_ids'] ?? [];
    $bulk_action = trim((string)($_POST['bulk_action'] ?? ''));
    $bulk_remarks = trim((string)($_POST['bulk_remarks'] ?? ''));

    $selected_ids =
        array_values(
            array_filter(
                array_map('intval', (array)$selected_ids),
                fn($id) => $id > 0
            )
        );

    if (empty($selected_ids)) {

        $action_message = "No leave requests selected.";
        $action_message_type = "warning";

    } elseif ($bulk_action === 'reject_selected' && $bulk_remarks === '') {

        $action_message = "Rejection reason is required for bulk reject.";
        $action_message_type = "danger";

    } else {

        $ids_string =
            implode(',', $selected_ids);

        $verify_query = "
            SELECT
                lr.id,
                lr.employee_id,
                lr.status,
                e.reporting_to
            FROM leave_requests lr
            JOIN employees e
            ON lr.employee_id = e.id
            WHERE lr.id IN ({$ids_string})
        ";

        $verify_result =
            mysqli_query($conn, $verify_query);

        $valid_ids = [];
        $invalid_ids = [];

        if ($verify_result) {

            while ($row = mysqli_fetch_assoc($verify_result)) {

                if ($row['status'] !== 'Pending') {
                    $invalid_ids[] = (int)$row['id'];
                    continue;
                }

                $hasPermission = false;

                if ($isAdmin || $isHr) {

                    $hasPermission = true;

                } elseif ($isManager) {

                    if ((int)$row['reporting_to'] === $current_employee_id) {
                        $hasPermission = true;
                    }

                    if (in_array((int)$row['employee_id'], $reporting_employees, true)) {
                        $hasPermission = true;
                    }
                }

                if ($hasPermission) {
                    $valid_ids[] = (int)$row['id'];
                } else {
                    $invalid_ids[] = (int)$row['id'];
                }
            }
        }

        if (empty($valid_ids)) {

            $action_message = "No valid leave requests selected for bulk action.";
            $action_message_type = "warning";

        } else {

            $valid_ids_string =
                implode(',', $valid_ids);

            $safe_remarks =
                mysqli_real_escape_string($conn, $bulk_remarks);

            if ($bulk_action === 'approve_selected') {

                $update_query = "
                    UPDATE leave_requests
                    SET
                        status = 'Approved',
                        approved_by = {$current_employee_id},
                        approved_at = NOW(),
                        approver_remarks = '{$safe_remarks}'
                    WHERE id IN ({$valid_ids_string})
                    AND status = 'Pending'
                ";

                $log_action = 'APPROVE';
                $log_desc =
                    "Bulk approved " .
                    count($valid_ids) .
                    " leave requests";

            } else {

                $update_query = "
                    UPDATE leave_requests
                    SET
                        status = 'Rejected',
                        rejected_by = {$current_employee_id},
                        rejected_at = NOW(),
                        rejection_reason = '{$safe_remarks}'
                    WHERE id IN ({$valid_ids_string})
                    AND status = 'Pending'
                ";

                $log_action = 'REJECT';
                $log_desc =
                    "Bulk rejected " .
                    count($valid_ids) .
                    " leave requests";
            }

            if (mysqli_query($conn, $update_query)) {

                $affected =
                    mysqli_affected_rows($conn);

                logActivityLocal(
                    $conn,
                    $log_action,
                    'LEAVE',
                    $log_desc,
                    null
                );

                $message_parts = [];

                if ($affected > 0) {
                    $message_parts[] =
                        "Successfully processed {$affected} leave requests.";
                }

                if (!empty($invalid_ids)) {
                    $message_parts[] =
                        "Skipped " .
                        count($invalid_ids) .
                        " requests.";
                }

                $action_message =
                    implode(' ', $message_parts);

                $action_message_type =
                    "success";

            } else {

                $action_message =
                    "Failed to process bulk action: " .
                    mysqli_error($conn);

                $action_message_type =
                    "danger";
            }
        }
    }
}

/* ---------------- FILTERS ---------------- */

$status_filter =
    strtolower(trim((string)($_GET['status'] ?? 'pending')));

$allowed_statuses =
    ['pending', 'approved', 'rejected', 'cancelled', 'all'];

if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'pending';
}

$employee_filter =
    isset($_GET['employee_id'])
    ? (int)$_GET['employee_id']
    : 0;

$date_from =
    trim((string)($_GET['date_from'] ?? ''));

$date_to =
    trim((string)($_GET['date_to'] ?? ''));

$search =
    trim((string)($_GET['search'] ?? ''));

/* ---------------- EMPLOYEE FILTER LIST ---------------- */

$employees_query = "
    SELECT
        id,
        full_name,
        employee_code
    FROM employees
    WHERE employee_status = 'active'
";

if (!$isAdmin && !$isHr && $isManager) {

    if (!empty($reporting_employees)) {

        $reporting_ids =
            implode(',', $reporting_employees);

        $employees_query .= "
            AND (
                reporting_to = {$current_employee_id}
                OR id IN ({$reporting_ids})
            )
        ";

    } else {

        $employees_query .= "
            AND reporting_to = {$current_employee_id}
        ";
    }
}

$employees_query .= "
    ORDER BY full_name ASC
";

$employees_result =
    mysqli_query($conn, $employees_query);

$employees = [];

if ($employees_result) {

    while ($row = mysqli_fetch_assoc($employees_result)) {
        $employees[] = $row;
    }

    mysqli_free_result($employees_result);
}

/* ---------------- MAIN QUERY ---------------- */

$query = "
    SELECT
        lr.*,
        e.full_name,
        e.employee_code,
        e.designation,
        e.department,
        e.photo AS employee_photo,
        e.reporting_to,
        m.full_name AS approver_name,
        r.full_name AS rejector_name
    FROM leave_requests lr
    JOIN employees e
    ON lr.employee_id = e.id
    LEFT JOIN employees m
    ON lr.approved_by = m.id
    LEFT JOIN employees r
    ON lr.rejected_by = r.id
    WHERE 1 = 1
";

if (!$isAdmin && !$isHr) {

    if ($isManager) {

        if (!empty($reporting_employees)) {

            $reporting_ids =
                implode(',', $reporting_employees);

            $query .= "
                AND (
                    e.reporting_to = {$current_employee_id}
                    OR e.id IN ({$reporting_ids})
                    OR lr.employee_id IN ({$reporting_ids})
                )
            ";

        } else {

            $query .= "
                AND e.reporting_to = {$current_employee_id}
            ";
        }
    }
}

$conditions = [];

if ($status_filter !== 'all') {
    $conditions[] =
        "lr.status = '" .
        mysqli_real_escape_string($conn, ucfirst($status_filter)) .
        "'";
}

if ($employee_filter > 0) {
    $conditions[] =
        "lr.employee_id = " .
        (int)$employee_filter;
}

if ($date_from !== '') {
    $conditions[] =
        "lr.from_date >= '" .
        mysqli_real_escape_string($conn, $date_from) .
        "'";
}

if ($date_to !== '') {
    $conditions[] =
        "lr.to_date <= '" .
        mysqli_real_escape_string($conn, $date_to) .
        "'";
}

if ($search !== '') {

    $search_term =
        mysqli_real_escape_string($conn, $search);

    $conditions[] =
        "(
            e.full_name LIKE '%{$search_term}%'
            OR e.employee_code LIKE '%{$search_term}%'
            OR e.department LIKE '%{$search_term}%'
            OR e.designation LIKE '%{$search_term}%'
            OR lr.leave_type LIKE '%{$search_term}%'
            OR lr.reason LIKE '%{$search_term}%'
        )";
}

if (!empty($conditions)) {
    $query .= "
        AND " .
        implode(" AND ", $conditions);
}

$query .= "
    ORDER BY lr.created_at DESC
";

$leave_requests = [];

$result =
    mysqli_query($conn, $query);

if ($result) {

    while ($row = mysqli_fetch_assoc($result)) {
        $leave_requests[] = $row;
    }

    mysqli_free_result($result);

} else {

    $action_message =
        "Database error occurred: " .
        mysqli_error($conn);

    $action_message_type =
        "danger";
}

/* ---------------- STATS ---------------- */

$stats_condition = "";

if (!$isAdmin && !$isHr) {

    if ($isManager) {

        if (!empty($reporting_employees)) {

            $reporting_ids =
                implode(',', $reporting_employees);

            $stats_condition = "
                AND (
                    e.reporting_to = {$current_employee_id}
                    OR e.id IN ({$reporting_ids})
                )
            ";

        } else {

            $stats_condition = "
                AND e.reporting_to = {$current_employee_id}
            ";
        }
    }
}

$stats_query = "
    SELECT
        SUM(CASE WHEN lr.status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN lr.status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN lr.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN lr.status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        SUM(CASE WHEN lr.status = 'Pending' THEN lr.total_days ELSE 0 END) AS pending_days,
        SUM(CASE WHEN lr.status = 'Approved' THEN lr.total_days ELSE 0 END) AS approved_days
    FROM leave_requests lr
    JOIN employees e
    ON lr.employee_id = e.id
    WHERE YEAR(lr.created_at) = YEAR(CURDATE())
    {$stats_condition}
";

$stats_result =
    mysqli_query($conn, $stats_query);

$stats = [
    'pending_count' => 0,
    'approved_count' => 0,
    'rejected_count' => 0,
    'cancelled_count' => 0,
    'pending_days' => 0,
    'approved_days' => 0
];

if ($stats_result) {

    $rowStats =
        mysqli_fetch_assoc($stats_result);

    if ($rowStats) {
        $stats = array_merge($stats, $rowStats);
    }

    mysqli_free_result($stats_result);
}

$pending_count =
    (int)($stats['pending_count'] ?? 0);

$total_count =
    (int)($stats['pending_count'] ?? 0) +
    (int)($stats['approved_count'] ?? 0) +
    (int)($stats['rejected_count'] ?? 0) +
    (int)($stats['cancelled_count'] ?? 0);

$loggedName =
    $_SESSION['employee_name'] ??
    $current_employee['full_name'];

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8" />

<meta
name="viewport"
content="width=device-width, initial-scale=1"
/>

<title>Leave Requests Management - TEK-C</title>

<link
rel="apple-touch-icon"
sizes="180x180"
href="assets/fav/apple-touch-icon.png"
>

<link
rel="icon"
type="image/png"
sizes="32x32"
href="assets/fav/favicon-32x32.png"
>

<link
rel="icon"
type="image/png"
sizes="16x16"
href="assets/fav/favicon-16x16.png"
>

<link
rel="manifest"
href="assets/fav/site.webmanifest"
>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
/>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
rel="stylesheet"
/>

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

body{
    background:var(--page-bg);
}

.content-scroll{
    flex:1 1 auto;
    overflow:auto;
    padding:16px;
}

.leave-wrapper{
    width:100%;
}

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

.primary-btn{
    border:0;
    background:#111827;
    color:#fff;
    height:36px;
    padding:0 14px;
    border-radius:11px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:7px;
    text-decoration:none;
    white-space:nowrap;
}

.primary-btn:hover{
    background:#020617;
    color:#fff;
}

.export-btn{
    background:#10b981;
}

.export-btn:hover{
    background:#059669;
}

.print-btn{
    background:#475569;
}

.print-btn:hover{
    background:#334155;
}

.role-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    border-radius:999px;
    padding:5px 9px;
    font-size:10px;
    font-weight:900;
    margin-left:8px;
}

.role-admin{
    background:#fee2e2;
    color:#991b1b;
}

.role-hr{
    background:#dbeafe;
    color:#1e40af;
}

.role-manager{
    background:#fef3c7;
    color:#92400e;
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
    text-decoration:none;
    color:inherit;
}

.stat-card:hover{
    color:inherit;
    transform:translateY(-1px);
}

.stat-card.active{
    border-color:#93c5fd;
    background:#eff6ff;
}

.stat-ic{
    width:38px;
    height:38px;
    border-radius:12px;
    display:grid;
    place-items:center;
    color:#fff;
    font-size:17px;
}

.blue{
    background:#2f80ed;
}

.green{
    background:#27ae60;
}

.orange{
    background:#f2994a;
}

.red{
    background:#eb5757;
}

.purple{
    background:#8e44ad;
}

.stat-label{
    color:var(--muted);
    font-weight:800;
    font-size:10.5px;
    text-transform:uppercase;
}

.stat-value{
    font-size:24px;
    font-weight:950;
}

.stat-small{
    color:var(--muted);
    font-size:10px;
    font-weight:700;
    margin-top:2px;
}

.panel{
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:13px;
}

.panel-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:12px;
    gap:10px;
}

.panel-title{
    font-weight:900;
    font-size:14px;
    margin:0;
}

.panel-subtitle{
    color:var(--muted);
    font-size:11px;
    font-weight:700;
    margin-top:2px;
}

.filter-bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:12px;
}

.search-box{
    position:relative;
    flex:1 1 260px;
    max-width:430px;
}

.search-box i{
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    color:#94a3b8;
    font-size:13px;
}

.search-box input{
    width:100%;
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 12px 0 34px;
    font-size:12px;
    font-weight:700;
    color:var(--text);
    outline:none;
}

.search-box input:focus{
    border-color:#bfdbfe;
    box-shadow:0 0 0 3px rgba(59,130,246,.10);
}

.filter-form{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.filter-select,
.filter-input{
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 32px 0 12px;
    font-size:12px;
    font-weight:800;
    min-width:130px;
}

.filter-input{
    padding-right:12px;
}

.filter-employee{
    min-width:210px;
}

.filter-submit{
    height:36px;
    border:0;
    border-radius:11px;
    background:#111827;
    color:#fff;
    padding:0 14px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:7px;
}

.bulk-action-bar{
    display:none;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    background:#fff;
    border:1px solid var(--border);
    border-radius:13px;
    padding:10px;
    margin-bottom:12px;
}

.bulk-action-bar.show{
    display:flex;
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

.table-title-cell{
    display:flex;
    align-items:center;
    gap:8px;
}

.employee-avatar{
    width:30px;
    height:30px;
    border-radius:9px;
    display:grid;
    place-items:center;
    overflow:hidden;
    background:#eff6ff;
    color:#2563eb;
    font-size:11px;
    font-weight:950;
    flex:0 0 auto;
}

.employee-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
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

.badge-pill{
    border-radius:999px;
    padding:5px 8px;
    font-weight:900;
    font-size:10px;
    display:inline-flex;
    align-items:center;
    gap:6px;
}

.mini-dot{
    width:6px;
    height:6px;
    border-radius:50%;
    background:currentColor;
}

.ontrack{
    color:#15803d;
    background:#dcfce7;
}

.warning{
    color:#b45309;
    background:#fef3c7;
}

.danger{
    color:#b91c1c;
    background:#fee2e2;
}

.muted{
    color:#475569;
    background:#f1f5f9;
}

.days-badge{
    border-radius:999px;
    padding:5px 8px;
    color:#1d4ed8;
    background:#dbeafe;
    font-weight:900;
    font-size:10px;
    display:inline-flex;
    align-items:center;
    gap:5px;
}

.reason-text{
    max-width:260px;
    color:#475569;
    font-size:10.5px;
    line-height:1.45;
}

.action-group{
    display:flex;
    justify-content:flex-end;
    gap:5px;
}

.action-btn{
    width:27px;
    height:27px;
    border-radius:9px;
    border:1px solid var(--border);
    background:#fff;
    display:grid;
    place-items:center;
    text-decoration:none;
    cursor:pointer;
}

.view-btn{
    color:#475569;
    background:#f8fafc;
}

.approve-btn{
    color:#15803d;
    background:#dcfce7;
}

.reject-btn{
    color:#b91c1c;
    background:#fee2e2;
}

.pagination-wrap{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding-top:12px;
}

.pagination-info{
    color:var(--muted);
    font-size:11px;
    font-weight:700;
}

.empty-state{
    text-align:center;
    padding:28px 12px;
    color:#64748b;
    font-weight:800;
    font-size:12px;
}

.alert{
    border-radius:var(--radius);
    border:0;
    box-shadow:var(--shadow);
    font-size:12px;
    font-weight:700;
}

@media(max-width:1199px){

    .compact-table thead{
        display:none;
    }

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
        flex:0 0 95px;
    }

    .compact-table tbody td:first-child{
        display:block;
    }

    .compact-table tbody td:first-child::before{
        display:none;
    }

    .action-group{
        justify-content:flex-start;
    }

    .page-heading{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:768px){

    .content-scroll{
        padding:12px 10px 12px!important;
    }

    .container-fluid.maxw{
        padding-left:6px!important;
        padding-right:6px!important;
    }

    .panel{
        padding:12px!important;
        margin-bottom:12px;
        border-radius:14px;
    }

    .filter-form{
        width:100%;
    }

    .filter-select,
    .filter-input,
    .filter-employee,
    .filter-submit{
        width:100%;
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

<div class="container-fluid leave-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Leave Requests

<?php if ($pending_count > 0): ?>

<span class="badge bg-warning text-dark ms-2">
<?php echo (int)$pending_count; ?>
Pending
</span>

<?php endif; ?>

<span class="role-badge <?php echo e($userRoleClass); ?>">
<i class="bi bi-shield-check"></i>
<?php echo e($user_role); ?>
</span>

</h1>

<p>
Review, approve, reject and track employee leave requests
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<button
class="primary-btn print-btn"
onclick="window.print()"
>
<i class="bi bi-printer"></i>
Print
</button>

<button
class="primary-btn export-btn"
data-bs-toggle="modal"
data-bs-target="#exportModal"
>
<i class="bi bi-download"></i>
Export
</button>

</div>

</div>

<!-- ACTION MESSAGE -->

<?php if ($action_message !== ''): ?>

<div class="alert alert-<?php echo e($action_message_type); ?> alert-dismissible fade show" role="alert">

<i class="bi bi-<?php echo $action_message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>

<?php echo e($action_message); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<!-- STATS -->

<div class="row g-3 mb-3">

<div class="col-12 col-sm-6 col-xl-3">

<a
href="?status=pending"
class="stat-card <?php echo $status_filter === 'pending' ? 'active' : ''; ?>"
>

<div class="stat-ic orange">
<i class="bi bi-clock-fill"></i>
</div>

<div>

<div class="stat-label">
Pending
</div>

<div class="stat-value">
<?php echo (int)($stats['pending_count'] ?? 0); ?>
</div>

<div class="stat-small">
<?php echo (float)($stats['pending_days'] ?? 0); ?>
days
</div>

</div>

</a>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<a
href="?status=approved"
class="stat-card <?php echo $status_filter === 'approved' ? 'active' : ''; ?>"
>

<div class="stat-ic green">
<i class="bi bi-check-circle-fill"></i>
</div>

<div>

<div class="stat-label">
Approved
</div>

<div class="stat-value">
<?php echo (int)($stats['approved_count'] ?? 0); ?>
</div>

<div class="stat-small">
<?php echo (float)($stats['approved_days'] ?? 0); ?>
days
</div>

</div>

</a>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<a
href="?status=rejected"
class="stat-card <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>"
>

<div class="stat-ic red">
<i class="bi bi-x-circle-fill"></i>
</div>

<div>

<div class="stat-label">
Rejected
</div>

<div class="stat-value">
<?php echo (int)($stats['rejected_count'] ?? 0); ?>
</div>

</div>

</a>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<a
href="?status=all"
class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>"
>

<div class="stat-ic purple">
<i class="bi bi-calendar-check-fill"></i>
</div>

<div>

<div class="stat-label">
Total Requests
</div>

<div class="stat-value">
<?php echo (int)$total_count; ?>
</div>

</div>

</a>

</div>

</div>

<!-- BULK ACTION BAR -->

<?php if ($status_filter === 'pending' && !empty($leave_requests)): ?>

<div class="bulk-action-bar" id="bulkActionBar">

<div class="form-check">

<input
class="form-check-input"
type="checkbox"
id="selectAllCheckbox"
>

<label
class="form-check-label fw-bold"
for="selectAllCheckbox"
>
Select All
(<span id="selectedCount">0</span> selected)
</label>

</div>

<div class="d-flex gap-2 flex-wrap">

<button
type="button"
class="btn btn-sm btn-success"
onclick="bulkApprove()"
>
<i class="bi bi-check-all"></i>
Approve Selected
</button>

<button
type="button"
class="btn btn-sm btn-danger"
onclick="bulkReject()"
>
<i class="bi bi-x-circle"></i>
Reject Selected
</button>

<button
type="button"
class="btn btn-sm btn-outline-secondary"
onclick="clearSelection()"
>
<i class="bi bi-x"></i>
Clear
</button>

</div>

</div>

<?php endif; ?>

<!-- PANEL -->

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Leave Requests
</h3>

<div class="panel-subtitle">
Compact responsive leave request directory
</div>

</div>

<span class="badge bg-secondary">
<?php echo count($leave_requests); ?>
records
</span>

</div>

<!-- FILTER BAR -->

<div class="filter-bar">

<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="quickSearch"
placeholder="Search employee, leave type, date, reason or status..."
>

</div>

<form
method="GET"
action=""
id="filterForm"
class="filter-form"
>

<select
name="status"
class="filter-select"
>

<option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>
Pending
</option>

<option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>
Approved
</option>

<option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>
Rejected
</option>

<option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>
Cancelled
</option>

<option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>
All
</option>

</select>

<select
name="employee_id"
class="filter-select filter-employee"
>

<option value="0">
All Employees
</option>

<?php foreach ($employees as $emp): ?>

<option
value="<?php echo (int)$emp['id']; ?>"
<?php echo (int)$employee_filter === (int)$emp['id'] ? 'selected' : ''; ?>
>
<?php echo e($emp['full_name']); ?>
(<?php echo e($emp['employee_code']); ?>)
</option>

<?php endforeach; ?>

</select>

<input
type="date"
name="date_from"
class="filter-input"
value="<?php echo e($date_from); ?>"
>

<input
type="date"
name="date_to"
class="filter-input"
value="<?php echo e($date_to); ?>"
>

<input
type="hidden"
name="search"
id="serverSearch"
value="<?php echo e($search); ?>"
>

<button
type="submit"
class="filter-submit"
>
<i class="bi bi-funnel"></i>
Apply
</button>

</form>

</div>

<!-- TABLE -->

<div class="compact-table-wrap">

<table
class="table compact-table align-middle"
id="leaveTable"
>

<thead>

<tr>

<?php if ($status_filter === 'pending'): ?>

<th style="width:36px;">
<input
class="form-check-input"
type="checkbox"
id="selectAllHeader"
>
</th>

<?php endif; ?>

<th>Employee</th>
<th>Leave Type</th>
<th>Period</th>
<th>Days</th>
<th>Reason</th>
<th>Applied</th>
<th>Status</th>

<?php if ($status_filter === 'pending'): ?>

<th class="text-end">Actions</th>

<?php else: ?>

<th class="text-end">View</th>

<?php endif; ?>

</tr>

</thead>

<tbody>

<?php if (empty($leave_requests)): ?>

<tr class="no-record-row">

<td colspan="<?php echo $status_filter === 'pending' ? 9 : 8; ?>">

<div class="empty-state">

<i class="bi bi-inbox me-1"></i>
No leave requests found.

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($leave_requests as $request): ?>

<?php

[$statusLabel, $statusClass, $statusKey] =
    leaveStatusBadge($request['status'] ?? '');

$employeeName =
    trim((string)($request['full_name'] ?? ''));

$initials =
    getInitials($employeeName);

$photoSrc =
    fileUrl($request['employee_photo'] ?? '');

$approverText =
    approverInfo($request);

$canApproveThis =
    false;

if ($isAdmin || $isHr) {
    $canApproveThis = true;
} elseif ($isManager) {
    if ((int)($request['reporting_to'] ?? 0) === $current_employee_id) {
        $canApproveThis = true;
    }
    if (in_array((int)($request['employee_id'] ?? 0), $reporting_employees, true)) {
        $canApproveThis = true;
    }
}

?>

<tr
data-status="<?php echo e($statusKey); ?>"
>

<?php if ($status_filter === 'pending'): ?>

<td data-label="Select">

<input
class="form-check-input row-select"
type="checkbox"
value="<?php echo (int)$request['id']; ?>"
>

</td>

<?php endif; ?>

<!-- EMPLOYEE -->

<td data-label="Employee">

<div class="table-title-cell">

<div class="employee-avatar">

<?php if ($photoSrc !== ''): ?>

<img
src="<?php echo e($photoSrc); ?>"
alt="<?php echo e($employeeName); ?>"
onerror="this.style.display='none'; this.parentNode.innerHTML='<?php echo e($initials); ?>';"
>

<?php else: ?>

<?php echo e($initials); ?>

<?php endif; ?>

</div>

<div>

<div class="table-primary-text">
<?php echo e($employeeName); ?>
</div>

<div class="table-secondary-text">

<?php echo e($request['employee_code'] ?? ''); ?>

<?php if (!empty($request['department'])): ?>

•
<?php echo e($request['department']); ?>

<?php endif; ?>

</div>

</div>

</div>

</td>

<!-- LEAVE TYPE -->

<td data-label="Leave Type">

<div class="table-primary-text">
<?php echo e($request['leave_type'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<?php echo e($request['designation'] ?? ''); ?>
</div>

</td>

<!-- PERIOD -->

<td data-label="Period">

<div class="table-primary-text">
<?php echo e(safeDate($request['from_date'] ?? '')); ?>
</div>

<div class="table-secondary-text">
to
<?php echo e(safeDate($request['to_date'] ?? '')); ?>
</div>

</td>

<!-- DAYS -->

<td data-label="Days">

<span class="days-badge">

<i class="bi bi-calendar"></i>

<?php echo e($request['total_days'] ?? '0'); ?>

days

</span>

</td>

<!-- REASON -->

<td data-label="Reason">

<div
class="reason-text"
title="<?php echo e($request['reason'] ?? ''); ?>"
>

<?php

$reason =
    trim((string)($request['reason'] ?? ''));

echo e(
    strlen($reason) > 70
    ? substr($reason, 0, 70) . '...'
    : $reason
);

?>

</div>

<?php if (!empty($request['contact_during_leave'])): ?>

<div class="table-secondary-text">
Contact:
<?php echo e($request['contact_during_leave']); ?>
</div>

<?php endif; ?>

</td>

<!-- APPLIED -->

<td data-label="Applied">

<div class="table-primary-text">
<?php echo e(safeDateTime($request['applied_at'] ?? $request['created_at'] ?? '')); ?>
</div>

<?php if (!empty($request['handover_to'])): ?>

<div class="table-secondary-text">
Handover:
<?php echo e($request['handover_to']); ?>
</div>

<?php endif; ?>

</td>

<!-- STATUS -->

<td data-label="Status">

<span class="badge-pill <?php echo e($statusClass); ?>">

<span class="mini-dot"></span>

<?php echo e($statusLabel); ?>

</span>

<?php if ($approverText !== ''): ?>

<div class="table-secondary-text mt-1">
<?php echo e($approverText); ?>
</div>

<?php endif; ?>

</td>

<!-- ACTIONS -->

<td data-label="Actions">

<div class="action-group">

<a
href="leave-details.php?id=<?php echo (int)$request['id']; ?>"
class="action-btn view-btn"
title="View Details"
>
<i class="bi bi-eye"></i>
</a>

<?php if ($status_filter === 'pending' && $canApproveThis): ?>

<button
type="button"
class="action-btn approve-btn"
onclick="openApproveModal(<?php echo (int)$request['id']; ?>, '<?php echo e(addslashes($employeeName)); ?>')"
title="Approve"
>
<i class="bi bi-check-lg"></i>
</button>

<button
type="button"
class="action-btn reject-btn"
onclick="openRejectModal(<?php echo (int)$request['id']; ?>, '<?php echo e(addslashes($employeeName)); ?>')"
title="Reject"
>
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

<!-- PAGINATION INFO -->

<div class="pagination-wrap">

<div class="pagination-info" id="recordInfo">

Showing
<?php echo count($leave_requests); ?>
leave request records

</div>

</div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<!-- APPROVE MODAL -->

<div
class="modal fade"
id="approveModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form
method="POST"
action=""
>

<input
type="hidden"
name="leave_id"
id="approve_leave_id"
>

<input
type="hidden"
name="leave_action"
value="approve"
>

<div class="modal-header">

<h5 class="modal-title fw-bold">

<i class="bi bi-check-circle-fill text-success me-2"></i>
Approve Leave Request

</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Approve leave request for
<strong id="approve_employee_name"></strong>?
</p>

<div class="mb-3">

<label class="form-label fw-bold">
Remarks
<span class="text-muted">(Optional)</span>
</label>

<textarea
name="remarks"
class="form-control"
rows="2"
placeholder="Add remarks..."
></textarea>

</div>

<div class="alert alert-info mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>

You are approving as
<strong><?php echo e($user_role); ?></strong>.

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-outline-secondary"
data-bs-dismiss="modal"
>
Cancel
</button>

<button
type="submit"
class="btn btn-success"
>
<i class="bi bi-check-lg"></i>
Confirm Approval
</button>

</div>

</form>

</div>

</div>

</div>

<!-- REJECT MODAL -->

<div
class="modal fade"
id="rejectModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form
method="POST"
action=""
>

<input
type="hidden"
name="leave_id"
id="reject_leave_id"
>

<input
type="hidden"
name="leave_action"
value="reject"
>

<div class="modal-header">

<h5 class="modal-title fw-bold">

<i class="bi bi-x-circle-fill text-danger me-2"></i>
Reject Leave Request

</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Reject leave request for
<strong id="reject_employee_name"></strong>?
</p>

<div class="mb-3">

<label class="form-label fw-bold">
Rejection Reason
<span class="text-danger">*</span>
</label>

<textarea
name="remarks"
class="form-control"
rows="3"
required
placeholder="Please provide reason for rejection..."
></textarea>

</div>

<div class="alert alert-info mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>

You are rejecting as
<strong><?php echo e($user_role); ?></strong>.

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-outline-secondary"
data-bs-dismiss="modal"
>
Cancel
</button>

<button
type="submit"
class="btn btn-danger"
>
<i class="bi bi-x-lg"></i>
Confirm Rejection
</button>

</div>

</form>

</div>

</div>

</div>

<!-- BULK REJECT MODAL -->

<div
class="modal fade"
id="bulkRejectModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form
method="POST"
action=""
>

<input
type="hidden"
name="bulk_action"
value="reject_selected"
>

<div id="bulkSelectedIds"></div>

<div class="modal-header">

<h5 class="modal-title fw-bold">

<i class="bi bi-x-circle-fill text-danger me-2"></i>
Bulk Reject Leave Requests

</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Reject
<strong id="bulkCount"></strong>
selected leave requests?
</p>

<div class="mb-3">

<label class="form-label fw-bold">
Rejection Reason
<span class="text-danger">*</span>
</label>

<textarea
name="bulk_remarks"
class="form-control"
rows="3"
required
placeholder="Please provide reason for rejection..."
></textarea>

</div>

<div class="alert alert-warning mb-0" style="box-shadow:none;">

<i class="bi bi-exclamation-triangle me-2"></i>

Only requests you have permission for will be processed.

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-outline-secondary"
data-bs-dismiss="modal"
>
Cancel
</button>

<button
type="submit"
class="btn btn-danger"
>
<i class="bi bi-x-lg"></i>
Confirm Bulk Rejection
</button>

</div>

</form>

</div>

</div>

</div>

<!-- EXPORT MODAL -->

<div
class="modal fade"
id="exportModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">

<h5 class="modal-title fw-bold">
Export Leave Requests
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<div class="mb-3">

<label class="form-label">
Export Format
</label>

<select
class="form-select"
id="exportFormat"
>

<option value="csv">
CSV
</option>

</select>

</div>

<div class="alert alert-info mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>

This export downloads the currently displayed table rows as CSV.

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-secondary"
data-bs-dismiss="modal"
>
Cancel
</button>

<button
type="button"
class="btn btn-success"
onclick="exportToCSV()"
>
<i class="bi bi-download me-1"></i>
Export
</button>

</div>

</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>

let updateBulkSelection = function(){};

document.addEventListener('DOMContentLoaded', function(){

    const quickSearch =
        document.getElementById('quickSearch');

    const serverSearch =
        document.getElementById('serverSearch');

    const tableRows =
        document.querySelectorAll('#leaveTable tbody tr:not(.no-record-row)');

    const recordInfo =
        document.getElementById('recordInfo');

    function filterRows(){

        const searchValue =
            quickSearch.value.toLowerCase().trim();

        let visibleCount =
            0;

        tableRows.forEach(function(row){

            const rowText =
                row.innerText.toLowerCase();

            const matches =
                rowText.includes(searchValue);

            row.style.display =
                matches
                ? ''
                : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        if (recordInfo) {
            recordInfo.textContent =
                'Showing ' + visibleCount + ' leave request records';
        }

        if (serverSearch) {
            serverSearch.value =
                searchValue;
        }
    }

    if (quickSearch) {

        quickSearch.value =
            serverSearch ? serverSearch.value : '';

        quickSearch.addEventListener('input', filterRows);
    }

    const selectAllHeader =
        document.getElementById('selectAllHeader');

    const selectAllCheckbox =
        document.getElementById('selectAllCheckbox');

    const rowCheckboxes =
        document.querySelectorAll('.row-select');

    const bulkActionBar =
        document.getElementById('bulkActionBar');

    const selectedCountSpan =
        document.getElementById('selectedCount');

    updateBulkSelection = function(){

        const checked =
            document.querySelectorAll('.row-select:checked');

        if (selectedCountSpan) {
            selectedCountSpan.textContent =
                checked.length;
        }

        if (bulkActionBar) {
            if (checked.length > 0) {
                bulkActionBar.classList.add('show');
            } else {
                bulkActionBar.classList.remove('show');
            }
        }

        if (selectAllHeader) {
            selectAllHeader.checked =
                checked.length === rowCheckboxes.length &&
                rowCheckboxes.length > 0;

            selectAllHeader.indeterminate =
                checked.length > 0 &&
                checked.length < rowCheckboxes.length;
        }

        if (selectAllCheckbox) {
            selectAllCheckbox.checked =
                checked.length === rowCheckboxes.length &&
                rowCheckboxes.length > 0;

            selectAllCheckbox.indeterminate =
                checked.length > 0 &&
                checked.length < rowCheckboxes.length;
        }
    };

    if (selectAllHeader) {
        selectAllHeader.addEventListener('change', function(){
            rowCheckboxes.forEach(function(cb){
                cb.checked = selectAllHeader.checked;
            });
            updateBulkSelection();
        });
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function(){
            rowCheckboxes.forEach(function(cb){
                cb.checked = selectAllCheckbox.checked;
            });
            updateBulkSelection();
        });
    }

    rowCheckboxes.forEach(function(cb){
        cb.addEventListener('change', updateBulkSelection);
    });
});

function openApproveModal(id, employeeName){

    document.getElementById('approve_leave_id').value =
        id;

    document.getElementById('approve_employee_name').textContent =
        employeeName;

    new bootstrap.Modal(
        document.getElementById('approveModal')
    ).show();
}

function openRejectModal(id, employeeName){

    document.getElementById('reject_leave_id').value =
        id;

    document.getElementById('reject_employee_name').textContent =
        employeeName;

    new bootstrap.Modal(
        document.getElementById('rejectModal')
    ).show();
}

function bulkApprove(){

    const selected =
        document.querySelectorAll('.row-select:checked');

    if (selected.length === 0) {
        alert('Please select at least one leave request.');
        return;
    }

    if (!confirm('Approve ' + selected.length + ' selected leave requests?')) {
        return;
    }

    const form =
        document.createElement('form');

    form.method =
        'POST';

    form.action =
        '';

    const actionInput =
        document.createElement('input');

    actionInput.type =
        'hidden';

    actionInput.name =
        'bulk_action';

    actionInput.value =
        'approve_selected';

    form.appendChild(actionInput);

    selected.forEach(function(cb){

        const input =
            document.createElement('input');

        input.type =
            'hidden';

        input.name =
            'selected_ids[]';

        input.value =
            cb.value;

        form.appendChild(input);
    });

    document.body.appendChild(form);

    form.submit();
}

function bulkReject(){

    const selected =
        document.querySelectorAll('.row-select:checked');

    if (selected.length === 0) {
        alert('Please select at least one leave request.');
        return;
    }

    const idsContainer =
        document.getElementById('bulkSelectedIds');

    idsContainer.innerHTML =
        '';

    selected.forEach(function(cb){

        const input =
            document.createElement('input');

        input.type =
            'hidden';

        input.name =
            'selected_ids[]';

        input.value =
            cb.value;

        idsContainer.appendChild(input);
    });

    document.getElementById('bulkCount').textContent =
        selected.length;

    new bootstrap.Modal(
        document.getElementById('bulkRejectModal')
    ).show();
}

function clearSelection(){

    document.querySelectorAll('.row-select').forEach(function(cb){
        cb.checked = false;
    });

    updateBulkSelection();
}

function exportToCSV(){

    const rows =
        document.querySelectorAll('#leaveTable tbody tr:not(.no-record-row)');

    const csv =
        [];

    const headers = [
        'Employee',
        'Leave Type',
        'Period',
        'Days',
        'Reason',
        'Applied',
        'Status'
    ];

    csv.push(headers.join(','));

    rows.forEach(function(row){

        if (row.style.display === 'none') {
            return;
        }

        const cells =
            row.querySelectorAll('td');

        if (!cells.length) {
            return;
        }

        let offset =
            <?php echo $status_filter === 'pending' ? '1' : '0'; ?>;

        const employee =
            cells[offset]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const leaveType =
            cells[offset + 1]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const period =
            cells[offset + 2]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const days =
            cells[offset + 3]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const reason =
            cells[offset + 4]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const applied =
            cells[offset + 5]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const status =
            cells[offset + 6]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const rowData = [
            employee,
            leaveType,
            period,
            days,
            reason,
            applied,
            status
        ].map(function(value){
            return '"' + String(value).replace(/"/g, '""') + '"';
        });

        csv.push(rowData.join(','));
    });

    const csvString =
        csv.join('\n');

    const blob =
        new Blob(
            ["\uFEFF" + csvString],
            { type: 'text/csv;charset=utf-8;' }
        );

    const url =
        window.URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href =
        url;

    a.download =
        'leave_requests_<?php echo date('Y-m-d'); ?>.csv';

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