<?php
// hr/leave-request.php
// TEK-C table section page reference UI style
// Combined: list, apply, view, cancel
// Fixed: duplicate helpers removed + safe activity logging

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
$action = $_GET['action'] ?? 'list';
$current_date = date('Y-m-d');

/* ---------------- HELPERS ---------------- */

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function oldInput($key, $fallback = '') {
    return isset($_POST[$key]) ? (string)$_POST[$key] : (string)$fallback;
}

function selected($a, $b) {
    return (string)$a === (string)$b ? 'selected' : '';
}

function safeDate($v, $dash = '—') {
    $v = trim((string)$v);

    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateTime($v, $dash = '—') {
    $v = trim((string)$v);

    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function initials($name) {
    $name = trim((string)$name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name);

    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last = strtoupper(substr(end($parts) ?: '', 0, 1));

    return count($parts) > 1 ? $first . $last : $first;
}

function getStatusBadge($status) {
    switch ($status) {
        case 'Approved':
            return '<span class="badge-pill ontrack"><span class="mini-dot"></span>Approved</span>';

        case 'Rejected':
            return '<span class="badge-pill danger"><span class="mini-dot"></span>Rejected</span>';

        case 'Pending':
            return '<span class="badge-pill warning"><span class="mini-dot"></span>Pending</span>';

        case 'Cancelled':
            return '<span class="badge-pill muted"><span class="mini-dot"></span>Cancelled</span>';

        default:
            return '<span class="badge-pill muted"><span class="mini-dot"></span>' . e($status) . '</span>';
    }
}

function leaveTypeName($type, $leave_quotas) {
    return isset($leave_quotas[$type]) ? $leave_quotas[$type]['name'] : $type;
}

function safeActivityLog(
    $conn,
    $action_type,
    $module,
    $description,
    $module_id = null,
    $module_name = null,
    $old_data = null,
    $new_data = null
) {
    if (!$conn) {
        return false;
    }

    try {
        $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'activity_logs'");

        if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
            return false;
        }

        $columnsResult = mysqli_query($conn, "SHOW COLUMNS FROM activity_logs");

        if (!$columnsResult) {
            return false;
        }

        $existingColumns = [];

        while ($row = mysqli_fetch_assoc($columnsResult)) {
            $existingColumns[] = $row['Field'];
        }

        if (empty($existingColumns)) {
            return false;
        }

        $employee_id = $_SESSION['employee_id'] ?? null;
        $admin_id = $_SESSION['admin_id'] ?? null;

        $current_user_id = $employee_id ?: ($admin_id ?: null);

        $current_user_name =
            $_SESSION['employee_name']
            ?? $_SESSION['admin_name']
            ?? $_SESSION['username']
            ?? 'System';

        $current_user_role =
            $_SESSION['designation']
            ?? $_SESSION['role']
            ?? $_SESSION['user_role']
            ?? 'User';

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now = date('Y-m-d H:i:s');

        $dataMap = [
            'user_id' => $current_user_id,
            'employee_id' => $employee_id ?: $current_user_id,
            'admin_id' => $admin_id ?: null,
            'created_by' => $current_user_id,
            'performed_by' => $current_user_id,

            'user_name' => $current_user_name,
            'employee_name' => $current_user_name,
            'admin_name' => $current_user_name,
            'created_by_name' => $current_user_name,

            'user_role' => $current_user_role,
            'role' => $current_user_role,

            'action_type' => $action_type,
            'action' => $action_type,
            'activity_type' => $action_type,

            'module' => $module,
            'module_id' => $module_id,
            'module_name' => $module_name,

            'description' => $description,
            'details' => $description,
            'remarks' => $description,

            'old_data' => $old_data,
            'new_data' => $new_data,

            'ip_address' => $ip_address,
            'user_agent' => $user_agent,

            'created_at' => $now,
            'updated_at' => $now,
            'log_time' => $now,
            'logged_at' => $now
        ];

        $insertColumns = [];
        $insertValues = [];
        $types = '';

        foreach ($dataMap as $column => $value) {
            if (!in_array($column, $existingColumns, true)) {
                continue;
            }

            $insertColumns[] = $column;
            $insertValues[] = $value;

            if (
                $column === 'user_id' ||
                $column === 'employee_id' ||
                $column === 'admin_id' ||
                $column === 'created_by' ||
                $column === 'performed_by' ||
                $column === 'module_id'
            ) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
        }

        if (empty($insertColumns)) {
            return false;
        }

        $columnSql = implode(', ', array_map(function ($col) {
            return '`' . str_replace('`', '', $col) . '`';
        }, $insertColumns));

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));

        $sql = "INSERT INTO activity_logs ($columnSql) VALUES ($placeholders)";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return false;
        }

        $bindParams = [];
        $bindParams[] = $types;

        foreach ($insertValues as $key => $value) {
            $bindParams[] = &$insertValues[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindParams);

        $ok = mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);

        return $ok;

    } catch (Throwable $e) {
        error_log("safeActivityLog error: " . $e->getMessage());
        return false;
    }
}

/* ---------------- EMPLOYEE DETAILS ---------------- */

$emp_stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM employees
     WHERE id = ?
     AND employee_status = 'active'
     LIMIT 1"
);

if (!$emp_stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);

$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);

mysqli_stmt_close($emp_stmt);

if (!$employee) {
    die("Employee not found.");
}

$designation = strtolower(trim((string)($employee['designation'] ?? '')));
$department = strtolower(trim((string)($employee['department'] ?? '')));

$isHrOrAdmin =
    $designation === 'hr' ||
    $department === 'hr' ||
    $designation === 'administrator' ||
    $designation === 'admin' ||
    $designation === 'director';

$isManager =
    $designation === 'manager' ||
    $designation === 'team lead' ||
    $designation === 'project manager';

/* ---------------- REPORTING MANAGER ---------------- */

$reporting_manager = null;

if (!empty($employee['reporting_to'])) {

    $manager_stmt = mysqli_prepare(
        $conn,
        "SELECT
            id,
            full_name,
            email,
            mobile_number,
            designation
         FROM employees
         WHERE id = ?
         LIMIT 1"
    );

    if ($manager_stmt) {
        mysqli_stmt_bind_param($manager_stmt, "i", $employee['reporting_to']);
        mysqli_stmt_execute($manager_stmt);

        $manager_res = mysqli_stmt_get_result($manager_stmt);
        $reporting_manager = mysqli_fetch_assoc($manager_res);

        mysqli_stmt_close($manager_stmt);
    }
}

/* ---------------- LEAVE BALANCE ---------------- */

$leave_taken = [
    'cl_taken' => 0,
    'pl_taken' => 0,
    'sl_taken' => 0,
    'lwp_taken' => 0
];

$leave_balance_query = "
    SELECT
        SUM(CASE WHEN leave_type = 'CL' AND status = 'Approved' THEN total_days ELSE 0 END) AS cl_taken,
        SUM(CASE WHEN leave_type = 'PL' AND status = 'Approved' THEN total_days ELSE 0 END) AS pl_taken,
        SUM(CASE WHEN leave_type = 'SL' AND status = 'Approved' THEN total_days ELSE 0 END) AS sl_taken,
        SUM(CASE WHEN leave_type = 'LWP' AND status = 'Approved' THEN total_days ELSE 0 END) AS lwp_taken
    FROM leave_requests
    WHERE employee_id = ?
    AND YEAR(from_date) = YEAR(CURDATE())
";

$balance_stmt = mysqli_prepare($conn, $leave_balance_query);

if ($balance_stmt) {
    mysqli_stmt_bind_param($balance_stmt, "i", $current_employee_id);
    mysqli_stmt_execute($balance_stmt);

    $balance_res = mysqli_stmt_get_result($balance_stmt);
    $row = mysqli_fetch_assoc($balance_res);

    if ($row) {
        $leave_taken = array_merge($leave_taken, $row);
    }

    mysqli_stmt_close($balance_stmt);
}

$leave_quotas = [
    'CL' => [
        'name' => 'Casual Leave',
        'quota' => 12,
        'taken' => (float)($leave_taken['cl_taken'] ?? 0),
        'color' => 'green',
        'icon' => 'bi-umbrella'
    ],
    'PL' => [
        'name' => 'Privilege Leave',
        'quota' => 15,
        'taken' => (float)($leave_taken['pl_taken'] ?? 0),
        'color' => 'blue',
        'icon' => 'bi-suitcase-lg'
    ],
    'SL' => [
        'name' => 'Sick Leave',
        'quota' => 10,
        'taken' => (float)($leave_taken['sl_taken'] ?? 0),
        'color' => 'orange',
        'icon' => 'bi-hospital'
    ],
    'LWP' => [
        'name' => 'Leave Without Pay',
        'quota' => 0,
        'taken' => (float)($leave_taken['lwp_taken'] ?? 0),
        'color' => 'gray',
        'icon' => 'bi-clock'
    ]
];

foreach ($leave_quotas as $type => &$quota) {
    $quota['balance'] = $quota['quota'] > 0 ? max(0, $quota['quota'] - $quota['taken']) : 999;
    $quota['percentage'] = $quota['quota'] > 0 ? min(100, round(($quota['taken'] / $quota['quota']) * 100)) : 0;
}
unset($quota);

/* ---------------- HOLIDAYS ---------------- */

$holidays = [];

$holiday_stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM holidays
     WHERE YEAR(holiday_date) = YEAR(CURDATE())
     ORDER BY holiday_date"
);

if ($holiday_stmt) {
    mysqli_stmt_execute($holiday_stmt);

    $holiday_res = mysqli_stmt_get_result($holiday_stmt);

    while ($row = mysqli_fetch_assoc($holiday_res)) {
        $holidays[] = $row;
    }

    mysqli_stmt_close($holiday_stmt);
}

/* ---------------- APPLY LEAVE ---------------- */

$errors = [];
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {

    $leave_type = trim((string)($_POST['leave_type'] ?? ''));
    $from_date = trim((string)($_POST['from_date'] ?? ''));
    $to_date = trim((string)($_POST['to_date'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));
    $contact_during_leave = trim((string)($_POST['contact_during_leave'] ?? ''));
    $handover_to = trim((string)($_POST['handover_to'] ?? ''));
    $half_day_dates = $_POST['half_day'] ?? [];

    if ($leave_type === '' || !array_key_exists($leave_type, $leave_quotas)) {
        $errors[] = "Please select a valid leave type.";
    }

    if ($from_date === '') {
        $errors[] = "From date is required.";
    }

    if ($to_date === '') {
        $errors[] = "To date is required.";
    }

    if ($reason === '') {
        $errors[] = "Reason for leave is required.";
    }

    if ($from_date !== '' && $to_date !== '') {

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

    $total_days = 0;
    $selected_dates = [];

    if (empty($errors) && $from_date !== '' && $to_date !== '') {

        $current = strtotime($from_date);
        $end = strtotime($to_date);

        while ($current <= $end) {

            $date = date('Y-m-d', $current);
            $day_of_week = date('w', $current);

            if ((int)$day_of_week !== 0) {

                $is_half_day = in_array($date, $half_day_dates, true);

                $selected_dates[] = [
                    'date' => $date,
                    'half_day' => $is_half_day ? 'HD' : null
                ];

                $total_days += $is_half_day ? 0.5 : 1;
            }

            $current = strtotime('+1 day', $current);
        }

        if ($total_days <= 0) {
            $errors[] = "Selected date range has no working days.";
        }
    }

    if ($leave_type !== '' && isset($leave_quotas[$leave_type])) {

        $quota = $leave_quotas[$leave_type];

        if ($quota['quota'] > 0 && ($quota['taken'] + $total_days) > $quota['quota']) {
            $errors[] = "Insufficient leave balance. Available: {$quota['balance']} days, Requested: {$total_days} days.";
        }
    }

    if (empty($errors) && $from_date !== '' && $to_date !== '') {

        $overlap_query = "
            SELECT id
            FROM leave_requests
            WHERE employee_id = ?
            AND status IN ('Pending', 'Approved')
            AND (
                (from_date BETWEEN ? AND ?)
                OR (to_date BETWEEN ? AND ?)
                OR (? BETWEEN from_date AND to_date)
                OR (? BETWEEN from_date AND to_date)
            )
            LIMIT 1
        ";

        $overlap_stmt = mysqli_prepare($conn, $overlap_query);

        if ($overlap_stmt) {

            mysqli_stmt_bind_param(
                $overlap_stmt,
                "issssss",
                $current_employee_id,
                $from_date,
                $to_date,
                $from_date,
                $to_date,
                $from_date,
                $to_date
            );

            mysqli_stmt_execute($overlap_stmt);
            mysqli_stmt_store_result($overlap_stmt);

            if (mysqli_stmt_num_rows($overlap_stmt) > 0) {
                $errors[] = "You already have a pending or approved leave request for this period.";
            }

            mysqli_stmt_close($overlap_stmt);
        }
    }

    if (empty($errors)) {

        $selected_dates_json = json_encode($selected_dates);

        $insert_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO leave_requests
            (
                employee_id,
                leave_type,
                from_date,
                to_date,
                total_days,
                reason,
                contact_during_leave,
                handover_to,
                selected_dates_json,
                status,
                applied_at
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW()
            )"
        );

        if (!$insert_stmt) {

            $error_message = "Database error: " . mysqli_error($conn);

        } else {

            mysqli_stmt_bind_param(
                $insert_stmt,
                "isssdssss",
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

                safeActivityLog(
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
                        'total_days' => $total_days,
                        'reason' => $reason
                    ])
                );

                $_SESSION['flash_success'] = "Leave application submitted successfully.";
                header("Location: leave-request.php?action=list");
                exit;

            } else {
                $error_message = "Failed to submit leave application: " . mysqli_stmt_error($insert_stmt);
            }

            mysqli_stmt_close($insert_stmt);
        }
    }
}

/* ---------------- CANCEL LEAVE ---------------- */

if (isset($_GET['cancel']) && isset($_GET['id'])) {

    $leave_id = (int)$_GET['id'];

    $check_stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM leave_requests
         WHERE id = ?
         AND employee_id = ?
         AND status = 'Pending'
         LIMIT 1"
    );

    if ($check_stmt) {

        mysqli_stmt_bind_param($check_stmt, "ii", $leave_id, $current_employee_id);
        mysqli_stmt_execute($check_stmt);

        $check_res = mysqli_stmt_get_result($check_stmt);

        if (mysqli_num_rows($check_res) > 0) {

            $leave = mysqli_fetch_assoc($check_res);

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE leave_requests
                 SET status = 'Cancelled'
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param($update_stmt, "i", $leave_id);

                if (mysqli_stmt_execute($update_stmt)) {

                    safeActivityLog(
                        $conn,
                        'UPDATE',
                        'leave',
                        "Cancelled leave request",
                        $leave_id,
                        null,
                        json_encode($leave),
                        json_encode(['status' => 'Cancelled'])
                    );

                    $_SESSION['flash_success'] = "Leave request cancelled successfully.";

                } else {
                    $_SESSION['flash_error'] = "Failed to cancel leave request.";
                }

                mysqli_stmt_close($update_stmt);

            } else {
                $_SESSION['flash_error'] = "Database error: " . mysqli_error($conn);
            }

        } else {
            $_SESSION['flash_error'] = "Invalid leave request or you do not have permission to cancel it.";
        }

        mysqli_stmt_close($check_stmt);
    }

    header("Location: leave-request.php?action=list");
    exit;
}

/* ---------------- FETCH LIST ---------------- */

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

    $query .= " ORDER BY applied_at DESC, id DESC";

    $stmt = mysqli_prepare($conn, $query);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $leave_requests[] = $row;
        }

        mysqli_stmt_close($stmt);
    }
}

/* ---------------- FETCH DETAIL ---------------- */

$leave_detail = null;

if ($action === 'view' && isset($_GET['id'])) {

    $leave_id = (int)$_GET['id'];

    $detail_stmt = mysqli_prepare(
        $conn,
        "SELECT
            lr.*,
            e.full_name,
            e.employee_code,
            e.designation,
            e.department
         FROM leave_requests lr
         JOIN employees e
         ON lr.employee_id = e.id
         WHERE lr.id = ?
         AND lr.employee_id = ?
         LIMIT 1"
    );

    if ($detail_stmt) {

        mysqli_stmt_bind_param($detail_stmt, "ii", $leave_id, $current_employee_id);
        mysqli_stmt_execute($detail_stmt);

        $detail_res = mysqli_stmt_get_result($detail_stmt);
        $leave_detail = mysqli_fetch_assoc($detail_res);

        mysqli_stmt_close($detail_stmt);
    }

    if (!$leave_detail) {
        $_SESSION['flash_error'] = "Leave request not found.";
        header("Location: leave-request.php?action=list");
        exit;
    }
}

/* ---------------- STATS ---------------- */

$total_requests = count($leave_requests);
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
$cancelled_count = 0;

foreach ($leave_requests as $r) {
    if (($r['status'] ?? '') === 'Pending') {
        $pending_count++;
    } elseif (($r['status'] ?? '') === 'Approved') {
        $approved_count++;
    } elseif (($r['status'] ?? '') === 'Rejected') {
        $rejected_count++;
    } elseif (($r['status'] ?? '') === 'Cancelled') {
        $cancelled_count++;
    }
}

$loggedName = $_SESSION['employee_name'] ?? ($employee['full_name'] ?? 'Employee');

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8">

<title>Leave Requests - TEK-C</title>

<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
<link rel="manifest" href="assets/fav/site.webmanifest">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<link href="assets/css/layout-styles.css" rel="stylesheet">
<link href="assets/css/topbar.css" rel="stylesheet">
<link href="assets/css/footer.css" rel="stylesheet">

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

.back-btn{
    background:#ffffff;
    color:#475569;
    border:1px solid var(--border);
}

.back-btn:hover{
    background:#f8fafc;
    color:#111827;
}

.create-btn{
    background:#2f80ed;
}

.create-btn:hover{
    background:#2563eb;
}

.export-btn{
    background:#10b981;
}

.export-btn:hover{
    background:#059669;
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
}

.blue{ background:#2f80ed; }
.green{ background:#27ae60; }
.orange{ background:#f2994a; }
.red{ background:#ef4444; }
.gray{ background:#64748b; }

.stat-label{
    color:var(--muted);
    font-weight:800;
    font-size:10.5px;
    text-transform:uppercase;
}

.stat-value{
    font-size:24px;
    font-weight:950;
    line-height:1;
    margin-top:2px;
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
    color:#111827;
}

.panel-subtitle{
    color:var(--muted);
    font-size:11px;
    font-weight:700;
    margin-top:2px;
}

.panel-count{
    background:#64748b;
    color:#fff;
    border-radius:8px;
    padding:4px 8px;
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
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
    pointer-events:none;
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

.filter-select{
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 12px;
    font-size:12px;
    font-weight:800;
    min-width:140px;
}

.section-tabs{
    border:0;
    margin-bottom:12px;
    gap:8px;
    overflow:auto;
    flex-wrap:nowrap;
}

.section-tabs .nav-link{
    border:1px solid var(--border);
    background:#fff;
    color:#64748b;
    border-radius:11px;
    font-weight:900;
    font-size:12px;
    padding:8px 12px;
    white-space:nowrap;
    display:flex;
    align-items:center;
    gap:6px;
}

.section-tabs .nav-link.active{
    background:#111827;
    color:#fff;
    border-color:#111827;
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

.badge-pill{
    border-radius:999px;
    padding:5px 8px;
    font-weight:900;
    font-size:10px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    white-space:nowrap;
}

.mini-dot{
    width:6px;
    height:6px;
    border-radius:50%;
    background:currentColor;
}

.ontrack{ color:#15803d; background:#dcfce7; }
.warning{ color:#b45309; background:#fef3c7; }
.danger{ color:#b91c1c; background:#fee2e2; }
.info{ color:#2563eb; background:#dbeafe; }
.muted{ color:#475569; background:#f1f5f9; }

.action-group{
    display:flex;
    justify-content:flex-end;
    gap:5px;
    flex-wrap:wrap;
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

.cancel-icon-btn{
    color:#b91c1c;
    background:#fee2e2;
    border-color:#fecaca;
}

.alert{
    border-radius:var(--radius);
    border:none;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    font-size:12px;
    font-weight:700;
}

.empty-state{
    text-align:center;
    padding:28px 12px;
    color:#64748b;
    font-weight:800;
    font-size:12px;
    min-height:110px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    flex-wrap:wrap;
}

.empty-state a{
    font-weight:900;
    text-decoration:none;
}

.side-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:0 8px 20px rgba(15,23,42,.04);
    padding:14px;
    margin-bottom:14px;
}

.side-title{
    font-size:13px;
    font-weight:950;
    color:#111827;
    margin:0 0 10px;
    display:flex;
    align-items:center;
    gap:7px;
}

.employee-card{
    display:flex;
    align-items:center;
    gap:10px;
}

.employee-avatar{
    width:42px;
    height:42px;
    border-radius:13px;
    background:#eff6ff;
    color:#2563eb;
    display:grid;
    place-items:center;
    font-weight:950;
    flex:0 0 auto;
}

.employee-name{
    color:#111827;
    font-size:13px;
    font-weight:950;
}

.employee-meta{
    color:#64748b;
    font-size:10.5px;
    font-weight:750;
    margin-top:2px;
}

.info-row{
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:7px 0;
    border-bottom:1px solid #f1f5f9;
    font-size:11.5px;
    font-weight:800;
}

.info-row:last-child{
    border-bottom:0;
}

.info-row span:first-child{
    color:#64748b;
}

.info-row span:last-child{
    color:#111827;
    text-align:right;
}

.balance-item{
    padding:10px 0;
    border-bottom:1px solid #f1f5f9;
}

.balance-item:last-child{
    border-bottom:0;
}

.balance-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.balance-label{
    font-size:11.5px;
    font-weight:950;
    color:#111827;
}

.balance-value{
    font-size:11.5px;
    font-weight:950;
    color:#475569;
}

.progress-mini{
    height:7px;
    background:#e5e7eb;
    border-radius:999px;
    overflow:hidden;
    margin-top:7px;
}

.progress-mini-bar{
    height:100%;
    border-radius:999px;
}

.bg-mini-blue{ background:#2563eb; }
.bg-mini-green{ background:#16a34a; }
.bg-mini-orange{ background:#f59e0b; }
.bg-mini-gray{ background:#64748b; }

.form-panel{
    background:#ffffff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:0 8px 20px rgba(15,23,42,.04);
    padding:16px;
    margin-bottom:16px;
}

.section-header{
    display:flex;
    align-items:center;
    margin-bottom:18px;
    padding-bottom:12px;
    border-bottom:2px solid #f0f4f8;
}

.section-icon{
    width:36px;
    height:36px;
    border-radius:12px;
    background:#111827;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-right:12px;
    font-size:18px;
    color:#fff;
    flex:0 0 auto;
}

.section-title{
    font-size:13px;
    font-weight:900;
    color:#2d3748;
    margin:0;
}

.section-subtitle{
    font-size:11px;
    color:#64748b;
    font-weight:700;
    margin-top:3px;
}

.form-label{
    font-weight:800;
    font-size:12px;
    color:#4b5563;
    margin-bottom:6px;
}

.required-label::after{
    content:" *";
    color:#ef4444;
}

.optional-badge{
    font-size:10px;
    color:#718096;
    font-weight:600;
    margin-left:5px;
}

.form-control,
.form-select{
    border:1px solid #e5e7eb;
    border-radius:10px;
    font-size:12px;
    font-weight:700;
}

.form-control:not(textarea),
.form-select{
    height:40px;
}

textarea.form-control{
    padding:10px 12px;
}

.form-control:focus,
.form-select:focus{
    border-color:#2f80ed;
    box-shadow:0 0 0 3px rgba(47,128,237,.10);
}

.total-days-card{
    border:1px solid var(--border);
    background:#eff6ff;
    color:#1d4ed8;
    border-radius:13px;
    padding:12px;
    font-size:12px;
    font-weight:900;
    display:none;
}

.total-days-card.danger{
    background:#fee2e2;
    color:#b91c1c;
    border-color:#fecaca;
}

.half-day-box{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:13px;
    padding:12px;
    display:none;
}

.day-selector{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:8px;
}

.day-pill{
    border:1px solid var(--border);
    background:#fff;
    border-radius:999px;
    padding:6px 9px;
    font-size:11px;
    font-weight:850;
    display:flex;
    align-items:center;
    gap:6px;
}

.detail-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:13px;
    overflow:hidden;
}

.detail-row{
    display:flex;
    justify-content:space-between;
    gap:16px;
    padding:12px 13px;
    border-bottom:1px solid #f1f5f9;
}

.detail-row:last-child{
    border-bottom:0;
}

.detail-label{
    color:#64748b;
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    flex:0 0 160px;
}

.detail-value{
    color:#111827;
    font-size:12px;
    font-weight:850;
    text-align:right;
    flex:1;
}

.submit-bar{
    position:sticky;
    bottom:0;
    background:rgba(245,247,251,.94);
    backdrop-filter:blur(10px);
    border-top:1px solid var(--border);
    padding:12px 0 2px;
    z-index:10;
}

.submit-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:16px;
    box-shadow:var(--shadow);
    padding:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.submit-info{
    color:#64748b;
    font-size:11px;
    font-weight:800;
}

.submit-btn{
    border:0;
    background:#111827;
    color:#fff;
    height:40px;
    padding:0 18px;
    border-radius:12px;
    font-size:12px;
    font-weight:950;
    display:inline-flex;
    align-items:center;
    gap:8px;
}

.cancel-btn{
    border:1px solid var(--border);
    background:#fff;
    color:#475569;
    height:40px;
    padding:0 16px;
    border-radius:12px;
    font-size:12px;
    font-weight:950;
    display:inline-flex;
    align-items:center;
    gap:8px;
    text-decoration:none;
}

.cancel-btn:hover{
    background:#f8fafc;
    color:#111827;
}

.danger-outline{
    border:1px solid #fecaca;
    background:#fff;
    color:#b91c1c;
    height:40px;
    padding:0 16px;
    border-radius:12px;
    font-size:12px;
    font-weight:950;
    display:inline-flex;
    align-items:center;
    gap:8px;
    text-decoration:none;
}

.danger-outline:hover{
    background:#fee2e2;
    color:#991b1b;
}

.holiday-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:8px 0;
    border-bottom:1px solid #f1f5f9;
}

.holiday-row:last-child{
    border-bottom:0;
}

.holiday-name{
    font-size:11.5px;
    color:#111827;
    font-weight:950;
}

.holiday-date{
    color:#64748b;
    font-size:10.5px;
    font-weight:750;
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

    .compact-table tbody tr:last-child{
        border-bottom:0;
    }

    .compact-table tbody td{
        border:0;
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:12px;
        padding:7px 0;
    }

    .compact-table tbody td::before{
        content:attr(data-label);
        font-size:10px;
        font-weight:900;
        color:#64748b;
        text-transform:uppercase;
        flex:0 0 105px;
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

    .leave-wrapper{
        padding-left:0!important;
        padding-right:0!important;
    }

    .page-heading{
        gap:10px;
        margin-bottom:12px;
    }

    .page-heading h1{
        font-size:18px;
    }

    .page-heading p{
        font-size:11.5px;
        line-height:1.3;
    }

    .page-heading .d-flex{
        width:100%;
        gap:7px!important;
    }

    .primary-btn{
        height:34px;
        padding:0 11px;
        font-size:11px;
        flex:1 1 auto;
        justify-content:center;
    }

    .stat-card{
        min-height:72px;
        padding:10px;
        gap:9px;
    }

    .stat-ic{
        width:34px;
        height:34px;
        border-radius:11px;
        font-size:15px;
    }

    .stat-value{
        font-size:20px;
    }

    .panel,
    .form-panel,
    .side-card{
        padding:12px!important;
        border-radius:14px;
        margin-bottom:12px;
    }

    .panel-header{
        align-items:flex-start;
        gap:8px;
        margin-bottom:10px;
    }

    .filter-bar{
        flex-direction:column;
        align-items:stretch;
        gap:8px;
    }

    .search-box{
        width:100%;
        max-width:none;
        flex:none;
    }

    .filter-select{
        width:100%;
        min-width:0;
    }

    .section-header{
        align-items:flex-start;
        margin-bottom:14px;
    }

    .section-icon{
        width:34px;
        height:34px;
        border-radius:11px;
        font-size:16px;
        margin-right:10px;
    }

    .detail-row{
        flex-direction:column;
        gap:4px;
    }

    .detail-label{
        flex:0 0 auto;
    }

    .detail-value{
        text-align:left;
    }

    .submit-card{
        align-items:stretch;
        flex-direction:column;
    }

    .submit-btn,
    .cancel-btn,
    .danger-outline{
        width:100%;
        justify-content:center;
    }
}

@media(max-width:420px){

    .compact-table tbody td{
        flex-direction:column;
        align-items:flex-start;
        gap:3px;
    }

    .compact-table tbody td::before{
        flex:0 0 auto;
    }

    .action-group{
        justify-content:flex-start;
    }

    .empty-state{
        flex-direction:column;
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

<div class="container-fluid leave-wrapper px-0">

<!-- PAGE HEADER -->

<div class="page-heading">

<div>

<h1>
<?php if ($action === 'apply'): ?>
Apply for Leave
<?php elseif ($action === 'view' && $leave_detail): ?>
Leave Request Details
<?php else: ?>
Leave Requests
<?php endif; ?>
</h1>

<p>
<?php if ($action === 'apply'): ?>
Submit a new leave request for approval
<?php elseif ($action === 'view' && $leave_detail): ?>
View your leave request details and status
<?php else: ?>
View, filter and manage your leave requests
<?php endif; ?>
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<?php if ($action !== 'list'): ?>

<a href="leave-request.php?action=list" class="primary-btn back-btn">
<i class="bi bi-arrow-left"></i>
Back
</a>

<?php endif; ?>

<?php if ($action === 'list'): ?>

<a href="leave-request.php?action=apply" class="primary-btn create-btn">
<i class="bi bi-plus-lg"></i>
New Leave
</a>

<button type="button" class="primary-btn export-btn" onclick="exportTableToCSV()">
<i class="bi bi-download"></i>
Export
</button>

<?php endif; ?>

</div>

</div>

<!-- ALERTS -->

<?php if (isset($_SESSION['flash_success'])): ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">
<i class="bi bi-check-circle-fill me-2"></i>
<?php echo e($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<?php endif; ?>

<?php if (isset($_SESSION['flash_error'])): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="bi bi-exclamation-triangle-fill me-2"></i>
<?php echo e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<?php endif; ?>

<?php if (!empty($error_message)): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="bi bi-exclamation-triangle-fill me-2"></i>
<?php echo e($error_message); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<?php endif; ?>

<?php if (!empty($errors)): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="bi bi-exclamation-triangle-fill me-2"></i>
<strong>Please fix the following errors:</strong>
<ul class="mb-0 mt-2 ps-3">
<?php foreach ($errors as $error): ?>
<li><?php echo e($error); ?></li>
<?php endforeach; ?>
</ul>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>

<?php endif; ?>

<?php if ($action === 'apply'): ?>

<!-- APPLY FORM -->

<form method="POST" action="leave-request.php?action=apply" id="leaveForm" novalidate>

<input type="hidden" name="action" value="apply">

<div class="row g-3">

<div class="col-lg-8">

<div class="form-panel">

<div class="section-header">
<div class="section-icon"><i class="bi bi-calendar-plus"></i></div>
<div>
<h3 class="section-title">Leave Details</h3>
<p class="section-subtitle">Select leave type and leave period</p>
</div>
</div>

<div class="row g-3">

<div class="col-12">

<label class="form-label required-label" for="leave_type">
Leave Type
</label>

<select name="leave_type" id="leave_type" class="form-select" required>

<option value="">
Select Leave Type
</option>

<?php foreach ($leave_quotas as $code => $quota): ?>

<option
value="<?php echo e($code); ?>"
<?php echo selected(oldInput('leave_type'), $code); ?>
data-balance="<?php echo e($quota['balance']); ?>"
>
<?php echo e($quota['name']); ?>
-
<?php echo e($code); ?>
<?php if ($quota['quota'] > 0): ?>
(<?php echo number_format((float)$quota['balance'], 1); ?> days available)
<?php else: ?>
(Unlimited)
<?php endif; ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label class="form-label required-label" for="from_date">
From Date
</label>

<input
type="date"
name="from_date"
id="from_date"
class="form-control"
value="<?php echo e(oldInput('from_date')); ?>"
min="<?php echo e($current_date); ?>"
required
>

</div>

<div class="col-md-6">

<label class="form-label required-label" for="to_date">
To Date
</label>

<input
type="date"
name="to_date"
id="to_date"
class="form-control"
value="<?php echo e(oldInput('to_date')); ?>"
min="<?php echo e($current_date); ?>"
required
>

</div>

<div class="col-12">

<div id="halfDayContainer" class="half-day-box">

<label class="form-label mb-1">
Select Half Days
<span class="optional-badge">(Optional)</span>
</label>

<div class="table-secondary-text mb-1">
Check dates you want to mark as half day.
</div>

<div id="halfDayList" class="day-selector"></div>

</div>

</div>

<div class="col-12">

<div id="totalDaysDisplay" class="total-days-card">
<i class="bi bi-info-circle me-2"></i>
<span id="totalDaysText">Total: 0 days</span>
</div>

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#2563eb;"><i class="bi bi-card-text"></i></div>
<div>
<h3 class="section-title">Reason & Handover</h3>
<p class="section-subtitle">Reason, contact and work handover details</p>
</div>
</div>

<div class="row g-3">

<div class="col-12">

<label class="form-label required-label" for="reason">
Reason for Leave
</label>

<textarea
name="reason"
id="reason"
rows="4"
class="form-control"
required
placeholder="Enter reason for leave..."
><?php echo e(oldInput('reason')); ?></textarea>

</div>

<div class="col-md-6">

<label class="form-label" for="contact_during_leave">
Contact Number During Leave
<span class="optional-badge">(Optional)</span>
</label>

<input
type="text"
name="contact_during_leave"
id="contact_during_leave"
class="form-control"
value="<?php echo e(oldInput('contact_during_leave', $employee['mobile_number'] ?? '')); ?>"
placeholder="Mobile number where you can be reached"
>

</div>

<div class="col-md-6">

<label class="form-label" for="handover_to">
Work Handover To
<span class="optional-badge">(Optional)</span>
</label>

<input
type="text"
name="handover_to"
id="handover_to"
class="form-control"
value="<?php echo e(oldInput('handover_to')); ?>"
placeholder="Name of colleague handling your work"
>

</div>

</div>

</div>

</div>

<div class="col-lg-4">

<div class="side-card">

<h4 class="side-title">
<i class="bi bi-person-badge"></i>
Employee Information
</h4>

<div class="employee-card mb-3">

<div class="employee-avatar">
<?php echo e(initials($employee['full_name'] ?? 'Employee')); ?>
</div>

<div>
<div class="employee-name"><?php echo e($employee['full_name'] ?? ''); ?></div>
<div class="employee-meta">
<?php echo e($employee['employee_code'] ?? ''); ?>
•
<?php echo e($employee['department'] ?? 'N/A'); ?>
</div>
</div>

</div>

<div class="info-row">
<span>Designation</span>
<span><?php echo e($employee['designation'] ?? 'N/A'); ?></span>
</div>

<div class="info-row">
<span>Department</span>
<span><?php echo e($employee['department'] ?? 'N/A'); ?></span>
</div>

<?php if ($reporting_manager): ?>

<div class="info-row">
<span>Reporting To</span>
<span><?php echo e($reporting_manager['full_name']); ?></span>
</div>

<?php endif; ?>

</div>

<div class="side-card">

<h4 class="side-title">
<i class="bi bi-pie-chart"></i>
Leave Balance
</h4>

<?php foreach ($leave_quotas as $code => $quota): ?>

<div class="balance-item">

<div class="balance-top">

<div class="balance-label">
<i class="bi <?php echo e($quota['icon']); ?> me-1"></i>
<?php echo e($quota['name']); ?>
</div>

<div class="balance-value">

<?php if ($quota['quota'] > 0): ?>
<?php echo number_format((float)$quota['balance'], 1); ?>/<?php echo (int)$quota['quota']; ?>
<?php else: ?>
Unlimited
<?php endif; ?>

</div>

</div>

<?php if ($quota['quota'] > 0): ?>

<div class="progress-mini">
<div
class="progress-mini-bar bg-mini-<?php echo e($quota['color']); ?>"
style="width: <?php echo (int)$quota['percentage']; ?>%;"
></div>
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

</div>

</div>

<div class="submit-bar">

<div class="submit-card">

<div class="submit-info">
<i class="bi bi-info-circle me-1"></i>
Leave request will be submitted as pending.
</div>

<div class="d-flex gap-2 flex-wrap">

<a href="leave-request.php?action=list" class="cancel-btn">
<i class="bi bi-x-lg"></i>
Cancel
</a>

<button type="submit" class="submit-btn">
<i class="bi bi-send"></i>
Submit Application
</button>

</div>

</div>

</div>

</form>

<?php elseif ($action === 'view' && $leave_detail): ?>

<!-- VIEW DETAIL -->

<div class="row g-3">

<div class="col-lg-8">

<div class="panel">

<div class="panel-header">

<div>
<h3 class="panel-title">Leave Request Details</h3>
<div class="panel-subtitle">Request #<?php echo (int)$leave_detail['id']; ?></div>
</div>

<?php echo getStatusBadge($leave_detail['status']); ?>

</div>

<div class="detail-card">

<div class="detail-row">
<div class="detail-label">Request ID</div>
<div class="detail-value">#<?php echo (int)$leave_detail['id']; ?></div>
</div>

<div class="detail-row">
<div class="detail-label">Leave Type</div>
<div class="detail-value"><?php echo e(leaveTypeName($leave_detail['leave_type'], $leave_quotas)); ?></div>
</div>

<div class="detail-row">
<div class="detail-label">Period</div>
<div class="detail-value">
<?php echo safeDate($leave_detail['from_date']); ?>
to
<?php echo safeDate($leave_detail['to_date']); ?>
</div>
</div>

<div class="detail-row">
<div class="detail-label">Total Days</div>
<div class="detail-value">
<span class="badge-pill info">
<span class="mini-dot"></span>
<?php echo e($leave_detail['total_days']); ?>
days
</span>
</div>
</div>

<?php if (!empty($leave_detail['selected_dates_json'])): ?>

<?php
$dates = json_decode($leave_detail['selected_dates_json'], true);
?>

<?php if (!empty($dates)): ?>

<div class="detail-row">
<div class="detail-label">Selected Dates</div>
<div class="detail-value">
<?php foreach ($dates as $d): ?>
<span class="badge-pill <?php echo !empty($d['half_day']) ? 'warning' : 'info'; ?> mb-1">
<span class="mini-dot"></span>
<?php echo safeDate($d['date']); ?>
<?php echo !empty($d['half_day']) ? '(HD)' : ''; ?>
</span>
<?php endforeach; ?>
</div>
</div>

<?php endif; ?>

<?php endif; ?>

<div class="detail-row">
<div class="detail-label">Reason</div>
<div class="detail-value"><?php echo nl2br(e($leave_detail['reason'])); ?></div>
</div>

<?php if (!empty($leave_detail['contact_during_leave'])): ?>

<div class="detail-row">
<div class="detail-label">Contact</div>
<div class="detail-value"><?php echo e($leave_detail['contact_during_leave']); ?></div>
</div>

<?php endif; ?>

<?php if (!empty($leave_detail['handover_to'])): ?>

<div class="detail-row">
<div class="detail-label">Handover To</div>
<div class="detail-value"><?php echo e($leave_detail['handover_to']); ?></div>
</div>

<?php endif; ?>

<div class="detail-row">
<div class="detail-label">Applied On</div>
<div class="detail-value"><?php echo safeDateTime($leave_detail['applied_at'] ?? $leave_detail['created_at'] ?? ''); ?></div>
</div>

<?php if (($leave_detail['status'] ?? '') === 'Approved' && !empty($leave_detail['approved_at'])): ?>

<div class="detail-row">
<div class="detail-label">Approved On</div>
<div class="detail-value"><?php echo safeDateTime($leave_detail['approved_at']); ?></div>
</div>

<?php endif; ?>

<?php if (($leave_detail['status'] ?? '') === 'Rejected' && !empty($leave_detail['rejection_reason'])): ?>

<div class="detail-row">
<div class="detail-label">Rejection Reason</div>
<div class="detail-value text-danger"><?php echo nl2br(e($leave_detail['rejection_reason'])); ?></div>
</div>

<?php endif; ?>

</div>

</div>

</div>

<div class="col-lg-4">

<div class="side-card">

<h4 class="side-title">
<i class="bi bi-gear"></i>
Actions
</h4>

<?php if (($leave_detail['status'] ?? '') === 'Pending'): ?>

<a
href="?cancel=1&id=<?php echo (int)$leave_detail['id']; ?>"
class="danger-outline mb-2"
onclick="return confirm('Are you sure you want to cancel this leave request?')"
>
<i class="bi bi-x-circle"></i>
Cancel Request
</a>

<?php endif; ?>

<a href="leave-request.php?action=list" class="cancel-btn">
<i class="bi bi-arrow-left"></i>
Back to List
</a>

</div>

<?php if (!empty($holidays)): ?>

<div class="side-card">

<h4 class="side-title">
<i class="bi bi-calendar-heart"></i>
Upcoming Holidays
</h4>

<?php
$upcoming = array_filter($holidays, function ($h) {
    return strtotime($h['holiday_date']) >= strtotime(date('Y-m-d'));
});
$upcoming = array_slice($upcoming, 0, 5);
?>

<?php if (!empty($upcoming)): ?>

<?php foreach ($upcoming as $holiday): ?>

<div class="holiday-row">
<div>
<div class="holiday-name"><?php echo e($holiday['holiday_name']); ?></div>
<div class="holiday-date"><?php echo safeDate($holiday['holiday_date']); ?></div>
</div>
<span class="badge-pill info">
<span class="mini-dot"></span>
<?php echo e($holiday['holiday_type'] ?? 'Holiday'); ?>
</span>
</div>

<?php endforeach; ?>

<?php else: ?>

<div class="text-muted small fw-bold">
No upcoming holidays
</div>

<?php endif; ?>

</div>

<?php endif; ?>

</div>

</div>

<?php else: ?>

<!-- LIST VIEW -->

<div class="row g-3 mb-3">

<div class="col-6 col-md-3">

<div class="stat-card">
<div class="stat-ic blue"><i class="bi bi-list-check"></i></div>
<div>
<div class="stat-label">Total</div>
<div class="stat-value"><?php echo (int)$total_requests; ?></div>
</div>
</div>

</div>

<div class="col-6 col-md-3">

<div class="stat-card">
<div class="stat-ic orange"><i class="bi bi-clock"></i></div>
<div>
<div class="stat-label">Pending</div>
<div class="stat-value"><?php echo (int)$pending_count; ?></div>
</div>
</div>

</div>

<div class="col-6 col-md-3">

<div class="stat-card">
<div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
<div>
<div class="stat-label">Approved</div>
<div class="stat-value"><?php echo (int)$approved_count; ?></div>
</div>
</div>

</div>

<div class="col-6 col-md-3">

<div class="stat-card">
<div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
<div>
<div class="stat-label">Rejected</div>
<div class="stat-value"><?php echo (int)$rejected_count; ?></div>
</div>
</div>

</div>

</div>

<ul class="nav section-tabs" role="tablist">

<li class="nav-item">
<a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?action=list&filter=all">
<i class="bi bi-grid"></i>
All
</a>
</li>

<li class="nav-item">
<a class="nav-link <?php echo $filter === 'pending' ? 'active' : ''; ?>" href="?action=list&filter=pending">
<i class="bi bi-clock"></i>
Pending
</a>
</li>

<li class="nav-item">
<a class="nav-link <?php echo $filter === 'approved' ? 'active' : ''; ?>" href="?action=list&filter=approved">
<i class="bi bi-check-circle"></i>
Approved
</a>
</li>

<li class="nav-item">
<a class="nav-link <?php echo $filter === 'rejected' ? 'active' : ''; ?>" href="?action=list&filter=rejected">
<i class="bi bi-x-circle"></i>
Rejected
</a>
</li>

<li class="nav-item">
<a class="nav-link <?php echo $filter === 'cancelled' ? 'active' : ''; ?>" href="?action=list&filter=cancelled">
<i class="bi bi-dash-circle"></i>
Cancelled
</a>
</li>

</ul>

<div class="row g-3">

<div class="col-lg-8">

<div class="panel">

<div class="panel-header">

<div>
<h3 class="panel-title">Leave Request Directory</h3>
<div class="panel-subtitle">Compact responsive leave request table</div>
</div>

<span class="panel-count">
<?php echo count($leave_requests); ?>
records
</span>

</div>

<div class="filter-bar">

<div class="search-box">
<i class="bi bi-search"></i>
<input type="text" id="quickSearch" placeholder="Search leave type, period, status...">
</div>

</div>

<div class="compact-table-wrap">

<table id="leaveTable" class="table compact-table align-middle">

<thead>

<tr>
<th>Applied</th>
<th>Leave Type</th>
<th>Period</th>
<th>Days</th>
<th>Status</th>
<th class="text-end">Actions</th>
</tr>

</thead>

<tbody>

<?php if (empty($leave_requests)): ?>

<tr class="no-record-row">

<td colspan="6">

<div class="empty-state">
<i class="bi bi-calendar-x"></i>
<span>No leave requests found.</span>
<a href="?action=apply">Apply for leave</a>
</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($leave_requests as $request): ?>

<tr>

<td data-label="Applied">

<div class="table-primary-text">
<?php echo safeDate($request['applied_at'] ?? $request['created_at'] ?? ''); ?>
</div>

<div class="table-secondary-text">
#<?php echo (int)$request['id']; ?>
</div>

</td>

<td data-label="Leave Type">

<div class="table-primary-text">
<?php
$type = $request['leave_type'];
$icon = $leave_quotas[$type]['icon'] ?? 'bi-calendar';
?>
<i class="bi <?php echo e($icon); ?> me-1"></i>
<?php echo e($type); ?>
</div>

<div class="table-secondary-text">
<?php echo e(leaveTypeName($type, $leave_quotas)); ?>
</div>

</td>

<td data-label="Period">

<div class="table-primary-text">
<?php echo safeDate($request['from_date']); ?>
</div>

<div class="table-secondary-text">
to
<?php echo safeDate($request['to_date']); ?>
</div>

</td>

<td data-label="Days">

<span class="badge-pill info">
<span class="mini-dot"></span>
<?php echo e($request['total_days']); ?>
days
</span>

</td>

<td data-label="Status">

<?php echo getStatusBadge($request['status']); ?>

</td>

<td data-label="Actions">

<div class="action-group">

<a
href="?action=view&id=<?php echo (int)$request['id']; ?>"
class="action-btn view-btn"
title="View Details"
>
<i class="bi bi-eye"></i>
</a>

<?php if (($request['status'] ?? '') === 'Pending'): ?>

<a
href="?cancel=1&id=<?php echo (int)$request['id']; ?>"
class="action-btn cancel-icon-btn"
title="Cancel Request"
onclick="return confirm('Are you sure you want to cancel this leave request?')"
>
<i class="bi bi-x-circle"></i>
</a>

<?php endif; ?>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

<div class="table-secondary-text mt-2" id="recordInfo">
Showing
<?php echo count($leave_requests); ?>
leave request records
</div>

</div>

</div>

<div class="col-lg-4">

<div class="side-card">

<h4 class="side-title">
<i class="bi bi-pie-chart"></i>
Leave Balance
</h4>

<?php foreach ($leave_quotas as $code => $quota): ?>

<div class="balance-item">

<div class="balance-top">

<div class="balance-label">
<i class="bi <?php echo e($quota['icon']); ?> me-1"></i>
<?php echo e($quota['name']); ?>
</div>

<div class="balance-value">

<?php if ($quota['quota'] > 0): ?>
<?php echo number_format((float)$quota['balance'], 1); ?>/<?php echo (int)$quota['quota']; ?>
<?php else: ?>
Unlimited
<?php endif; ?>

</div>

</div>

<?php if ($quota['quota'] > 0): ?>

<div class="progress-mini">
<div
class="progress-mini-bar bg-mini-<?php echo e($quota['color']); ?>"
style="width: <?php echo (int)$quota['percentage']; ?>%;"
></div>
</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<a href="?action=apply" class="primary-btn create-btn w-100 justify-content-center mb-3">
<i class="bi bi-plus-lg"></i>
Apply for Leave
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
<script src="assets/js/sidebar-toggle.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function(){

    const quickSearch =
        document.getElementById('quickSearch');

    const rows =
        document.querySelectorAll('#leaveTable tbody tr:not(.no-record-row)');

    const recordInfo =
        document.getElementById('recordInfo');

    if (quickSearch) {

        quickSearch.addEventListener('input', function(){

            const value =
                quickSearch.value.toLowerCase().trim();

            let visible =
                0;

            rows.forEach(function(row){

                const match =
                    row.innerText.toLowerCase().includes(value);

                row.style.display =
                    match
                    ? ''
                    : 'none';

                if (match) {
                    visible++;
                }
            });

            if (recordInfo) {
                recordInfo.textContent =
                    'Showing ' + visible + ' leave request records';
            }
        });
    }

    const fromDate =
        document.getElementById('from_date');

    const toDate =
        document.getElementById('to_date');

    const leaveType =
        document.getElementById('leave_type');

    const halfDayContainer =
        document.getElementById('halfDayContainer');

    const halfDayList =
        document.getElementById('halfDayList');

    const totalDaysDisplay =
        document.getElementById('totalDaysDisplay');

    const totalDaysText =
        document.getElementById('totalDaysText');

    const form =
        document.getElementById('leaveForm');

    function calculateWorkingDays(start, end){

        const startDate =
            new Date(start);

        const endDate =
            new Date(end);

        const days =
            [];

        let currentDate =
            new Date(startDate);

        while (currentDate <= endDate) {

            const dayOfWeek =
                currentDate.getDay();

            if (dayOfWeek !== 0) {
                days.push(new Date(currentDate));
            }

            currentDate.setDate(currentDate.getDate() + 1);
        }

        return days;
    }

    function calculateTotalDays(){

        if (!totalDaysText || !totalDaysDisplay || !leaveType) {
            return 0;
        }

        const checkboxes =
            document.querySelectorAll('input[name="half_day[]"]');

        let total =
            0;

        checkboxes.forEach(function(cb){
            total += cb.checked ? 0.5 : 1;
        });

        totalDaysText.textContent =
            'Total: ' + total.toFixed(1) + ' days';

        totalDaysDisplay.style.display =
            'block';

        totalDaysDisplay.classList.remove('danger');

        const selectedType =
            leaveType.options[leaveType.selectedIndex];

        if (selectedType && selectedType.value) {

            const balance =
                parseFloat(selectedType.dataset.balance || 0);

            if (balance < 999 && total > balance) {

                totalDaysDisplay.classList.add('danger');

                totalDaysText.innerHTML =
                    'Total: ' + total.toFixed(1) +
                    ' days <i class="bi bi-exclamation-triangle ms-1"></i> Exceeds balance by ' +
                    (total - balance).toFixed(1) + ' days';
            }
        }

        return total;
    }

    function updateDateRange(){

        if (!fromDate || !toDate || !halfDayContainer || !totalDaysDisplay || !halfDayList) {
            return;
        }

        if (!fromDate.value || !toDate.value) {
            halfDayContainer.style.display = 'none';
            totalDaysDisplay.style.display = 'none';
            return;
        }

        const start =
            new Date(fromDate.value);

        const end =
            new Date(toDate.value);

        if (end < start) {
            toDate.value = fromDate.value;
            return;
        }

        const workingDays =
            calculateWorkingDays(fromDate.value, toDate.value);

        if (workingDays.length === 0) {
            halfDayContainer.style.display = 'none';
            totalDaysDisplay.style.display = 'none';
            return;
        }

        let html =
            '';

        workingDays.forEach(function(date){

            const dateStr =
                date.toISOString().split('T')[0];

            const formattedDate =
                date.toLocaleDateString(
                    'en-US',
                    {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'short'
                    }
                );

            html +=
                '<label class="day-pill" for="half_' + dateStr + '">' +
                    '<input class="form-check-input m-0" type="checkbox" name="half_day[]" value="' + dateStr + '" id="half_' + dateStr + '">' +
                    '<span>' + formattedDate + '</span>' +
                '</label>';
        });

        halfDayList.innerHTML =
            html;

        halfDayContainer.style.display =
            'block';

        calculateTotalDays();
    }

    if (fromDate) {
        fromDate.addEventListener('change', updateDateRange);
    }

    if (toDate) {
        toDate.addEventListener('change', updateDateRange);
    }

    if (leaveType) {
        leaveType.addEventListener('change', calculateTotalDays);
    }

    if (halfDayList) {
        halfDayList.addEventListener('change', function(e){
            if (e.target.name === 'half_day[]') {
                calculateTotalDays();
            }
        });
    }

    if (form) {

        form.addEventListener('submit', function(e){

            const errors =
                [];

            const leaveTypeField =
                document.getElementById('leave_type');

            const reason =
                document.getElementById('reason');

            if (!leaveTypeField || !leaveTypeField.value) {
                errors.push('Please select leave type');
            }

            if (!fromDate || !fromDate.value || !toDate || !toDate.value) {
                errors.push('Please select date range');
            }

            if (fromDate && toDate && fromDate.value && toDate.value && toDate.value < fromDate.value) {
                errors.push('To date must be on or after from date');
            }

            if (!reason || !reason.value.trim()) {
                errors.push('Reason for leave is required');
            }

            const selectedOption =
                leaveTypeField
                ? leaveTypeField.options[leaveTypeField.selectedIndex]
                : null;

            const total =
                calculateTotalDays();

            if (selectedOption && selectedOption.value) {

                const balance =
                    parseFloat(selectedOption.dataset.balance || 0);

                if (balance < 999 && total > balance) {
                    errors.push('Insufficient leave balance');
                }
            }

            if (errors.length > 0) {

                e.preventDefault();

                alert(errors.join('\n'));
            }
        });
    }

    <?php if (!empty($_POST['from_date']) && !empty($_POST['to_date'])): ?>
    setTimeout(updateDateRange, 100);
    <?php endif; ?>
});

function exportTableToCSV(){

    const table =
        document.getElementById('leaveTable');

    if (!table) {
        return;
    }

    const rows =
        table.querySelectorAll('tbody tr:not(.no-record-row)');

    const headers =
        ['Applied', 'Leave Type', 'Period', 'Days', 'Status'];

    const csv =
        [];

    csv.push(headers.join(','));

    rows.forEach(function(row){

        if (row.style.display === 'none') {
            return;
        }

        const cells =
            row.querySelectorAll('td');

        if (cells.length < 5) {
            return;
        }

        const rowData =
            [
                cells[0].innerText,
                cells[1].innerText,
                cells[2].innerText,
                cells[3].innerText,
                cells[4].innerText
            ].map(function(value){
                return '"' + value.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"';
            });

        csv.push(rowData.join(','));
    });

    const blob =
        new Blob(
            ["\uFEFF" + csv.join('\n')],
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