<?php
// hr/apply-leave.php
// TEK-C add/edit/delete page reference UI style
// Fixed: quota warning + safe activity logging

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

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function oldInput($key, $fallback = '') {
    return isset($_POST[$key]) ? (string)$_POST[$key] : (string)$fallback;
}

function selected($a, $b) {
    return (string)$a === (string)$b ? 'selected' : '';
}

function safeDate($date, $dash = '—') {
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return $dash;
    }

    $ts = strtotime($date);

    return $ts ? date('d M Y', $ts) : e($date);
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

$employee = null;

$emp_stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM employees
     WHERE id = ?
     AND employee_status = 'active'
     LIMIT 1"
);

if ($emp_stmt) {
    mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
    mysqli_stmt_execute($emp_stmt);

    $emp_res = mysqli_stmt_get_result($emp_stmt);
    $employee = mysqli_fetch_assoc($emp_res);

    mysqli_stmt_close($emp_stmt);
}

if (!$employee) {
    die("Employee not found.");
}

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
    'sl_taken' => 0
];

$leave_balance_query = "
    SELECT
        SUM(CASE WHEN leave_type = 'CL' AND status = 'Approved' THEN total_days ELSE 0 END) AS cl_taken,
        SUM(CASE WHEN leave_type = 'PL' AND status = 'Approved' THEN total_days ELSE 0 END) AS pl_taken,
        SUM(CASE WHEN leave_type = 'SL' AND status = 'Approved' THEN total_days ELSE 0 END) AS sl_taken
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
        'color' => 'green'
    ],
    'PL' => [
        'name' => 'Privilege Leave',
        'quota' => 15,
        'taken' => (float)($leave_taken['pl_taken'] ?? 0),
        'color' => 'blue'
    ],
    'SL' => [
        'name' => 'Sick Leave',
        'quota' => 10,
        'taken' => (float)($leave_taken['sl_taken'] ?? 0),
        'color' => 'orange'
    ],
    'LWP' => [
        'name' => 'Leave Without Pay',
        'quota' => 0,
        'taken' => 0,
        'color' => 'gray'
    ]
];

foreach ($leave_quotas as $type => &$quota) {
    $quota['balance'] = max(0, $quota['quota'] - $quota['taken']);
    $quota['percentage'] = $quota['quota'] > 0 ? min(100, round(($quota['taken'] / $quota['quota']) * 100)) : 0;
}
unset($quota);

/* ---------------- PENDING LEAVES ---------------- */

$pending_leaves = [];

$pending_stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM leave_requests
     WHERE employee_id = ?
     AND status = 'Pending'
     ORDER BY from_date ASC"
);

if ($pending_stmt) {
    mysqli_stmt_bind_param($pending_stmt, "i", $current_employee_id);
    mysqli_stmt_execute($pending_stmt);

    $pending_res = mysqli_stmt_get_result($pending_stmt);

    while ($row = mysqli_fetch_assoc($pending_res)) {
        $pending_leaves[] = $row;
    }

    mysqli_stmt_close($pending_stmt);
}

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

/* ---------------- FORM SUBMIT ---------------- */

$errors = [];
$current_date = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

            $errors[] = "Database error: " . mysqli_error($conn);

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

                $_SESSION['flash_success'] = "Leave application submitted successfully. Your request is pending approval.";
                header("Location: my-leaves.php");
                exit;

            } else {
                $errors[] = "Failed to submit leave application: " . mysqli_stmt_error($insert_stmt);
            }

            mysqli_stmt_close($insert_stmt);
        }
    }
}

$loggedName = $_SESSION['employee_name'] ?? ($employee['full_name'] ?? 'Employee');

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8">

<title>Apply for Leave - TEK-C</title>

<meta
name="viewport"
content="width=device-width, initial-scale=1"
>

<link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
<link rel="manifest" href="assets/fav/site.webmanifest">

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
rel="stylesheet"
>

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

.list-btn{
    background:#2f80ed;
}

.list-btn:hover{
    background:#2563eb;
}

.alert{
    border-radius:var(--radius);
    border:none;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    font-size:12px;
    font-weight:700;
}

.leave-hero{
    background:linear-gradient(135deg,#2563eb,#7c3aed);
    color:#fff;
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:16px;
    margin-bottom:14px;
}

.hero-title{
    font-size:15px;
    font-weight:950;
    margin:0;
}

.hero-subtitle{
    font-size:11.5px;
    font-weight:700;
    opacity:.9;
    margin-top:4px;
}

.hero-chip-row{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
    margin-top:12px;
}

.hero-chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.25);
    border-radius:999px;
    padding:5px 9px;
    font-size:10.5px;
    font-weight:900;
}

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

.form-control.is-invalid,
.form-select.is-invalid{
    border-color:#ef4444;
    background:#fff5f5;
}

.form-helper{
    font-size:10.5px;
    color:#718096;
    margin-top:5px;
    display:flex;
    align-items:center;
    gap:5px;
    font-weight:700;
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

.side-card{
    background:#ffffff;
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

.pending-card{
    background:#fff7ed;
    border:1px solid #fed7aa;
    border-radius:13px;
    padding:11px;
    margin-bottom:9px;
}

.pending-card:last-child{
    margin-bottom:0;
}

.pending-title{
    font-size:11.5px;
    color:#111827;
    font-weight:950;
}

.pending-meta{
    color:#9a3412;
    font-size:10.5px;
    font-weight:750;
    margin-top:2px;
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

.submit-btn:hover{
    background:#020617;
    color:#fff;
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

@media(max-width:768px){

    .content-scroll{
        padding:12px 10px 12px!important;
    }

    .leave-wrapper{
        padding-left:0!important;
        padding-right:0!important;
    }

    .page-heading{
        align-items:flex-start;
        flex-direction:column;
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

    .leave-hero{
        padding:14px;
        margin-bottom:12px;
    }

    .form-panel,
    .side-card{
        padding:12px!important;
        margin-bottom:12px;
        border-radius:14px;
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

    .submit-card{
        align-items:stretch;
        flex-direction:column;
    }

    .submit-btn,
    .cancel-btn{
        width:100%;
        justify-content:center;
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
Apply for Leave
</h1>

<p>
Submit a leave request for reporting manager approval
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="my-leaves.php"
class="primary-btn back-btn"
>
<i class="bi bi-arrow-left"></i>
Back
</a>

<a
href="my-leaves.php"
class="primary-btn list-btn"
>
<i class="bi bi-list-check"></i>
My Leaves
</a>

</div>

</div>

<!-- ALERTS -->

<?php if (!empty($errors)): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<strong>Please fix the following errors:</strong>

<ul class="mb-0 mt-2 ps-3">

<?php foreach ($errors as $error): ?>

<li>
<?php echo e($error); ?>
</li>

<?php endforeach; ?>

</ul>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<?php if (!empty($_SESSION['flash_success'])): ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">

<i class="bi bi-check-circle-fill me-2"></i>

<?php echo e($_SESSION['flash_success']); ?>

<?php unset($_SESSION['flash_success']); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<!-- HERO -->

<div class="leave-hero">

<h2 class="hero-title">
New Leave Application
</h2>

<div class="hero-subtitle">
Choose leave type, date range and handover details before submitting.
</div>

<div class="hero-chip-row">

<span class="hero-chip">
<i class="bi bi-calendar-check"></i>
Current Year
</span>

<span class="hero-chip">
<i class="bi bi-person-badge"></i>
<?php echo e($employee['employee_code'] ?? ''); ?>
</span>

<?php if ($reporting_manager): ?>

<span class="hero-chip">
<i class="bi bi-person-check"></i>
Approver:
<?php echo e($reporting_manager['full_name']); ?>
</span>

<?php endif; ?>

<span class="hero-chip">
<i class="bi bi-clock"></i>
Pending Approval
</span>

</div>

</div>

<form
method="POST"
action=""
id="leaveForm"
novalidate
>

<div class="row g-3">

<!-- MAIN FORM -->

<div class="col-lg-8">

<!-- LEAVE DETAILS -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon">
<i class="bi bi-calendar-plus"></i>
</div>

<div>

<h3 class="section-title">
Leave Details
</h3>

<p class="section-subtitle">
Select leave type and leave period
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-12">

<label
class="form-label required-label"
for="leave_type"
>
Leave Type
</label>

<select
name="leave_type"
id="leave_type"
class="form-select"
required
>

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

<label
class="form-label required-label"
for="from_date"
>
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

<label
class="form-label required-label"
for="to_date"
>
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

<div
id="halfDayContainer"
class="half-day-box"
>

<label class="form-label mb-1">
Select Half Days
<span class="optional-badge">(Optional)</span>
</label>

<div class="form-helper mb-1">
<i class="bi bi-info-circle"></i>
Check the dates you want to mark as half day.
</div>

<div
id="halfDayList"
class="day-selector"
></div>

</div>

</div>

<div class="col-12">

<div
id="totalDaysDisplay"
class="total-days-card"
>

<i class="bi bi-info-circle me-2"></i>

<span id="totalDaysText">
Total: 0 days
</span>

</div>

</div>

</div>

</div>

<!-- REASON -->

<div class="form-panel">

<div class="section-header">

<div
class="section-icon"
style="background:#2563eb;"
>
<i class="bi bi-card-text"></i>
</div>

<div>

<h3 class="section-title">
Reason & Availability
</h3>

<p class="section-subtitle">
Explain your leave reason and contact availability
</p>

</div>

</div>

<div class="row g-3">

<div class="col-12">

<label
class="form-label required-label"
for="reason"
>
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

<label
class="form-label"
for="contact_during_leave"
>
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

<label
class="form-label"
for="handover_to"
>
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

<!-- DOCUMENTS -->

<div class="form-panel">

<div class="section-header">

<div
class="section-icon"
style="background:#10b981;"
>
<i class="bi bi-paperclip"></i>
</div>

<div>

<h3 class="section-title">
Supporting Documents
</h3>

<p class="section-subtitle">
Optional upload placeholder for medical certificate or supporting file
</p>

</div>

</div>

<div class="row g-3">

<div class="col-12">

<label class="form-label">
Supporting Documents
<span class="optional-badge">(Coming Soon)</span>
</label>

<input
type="file"
class="form-control"
disabled
>

<div class="form-helper">
<i class="bi bi-info-circle"></i>
File upload will be available soon.
</div>

</div>

</div>

</div>

</div>

<!-- SIDEBAR -->

<div class="col-lg-4">

<!-- EMPLOYEE CARD -->

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

<div class="employee-name">
<?php echo e($employee['full_name'] ?? ''); ?>
</div>

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

<div class="info-row">
<span>Approver Role</span>
<span><?php echo e($reporting_manager['designation'] ?? 'Manager'); ?></span>
</div>

<?php endif; ?>

</div>

<!-- LEAVE BALANCE -->

<div class="side-card">

<h4 class="side-title">
<i class="bi bi-pie-chart"></i>
Leave Balance
</h4>

<?php foreach ($leave_quotas as $code => $quota): ?>

<div class="balance-item">

<div class="balance-top">

<div>

<div class="balance-label">
<?php echo e($quota['name']); ?>
(<?php echo e($code); ?>)
</div>

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

<!-- PENDING REQUESTS -->

<?php if (!empty($pending_leaves)): ?>

<div class="side-card">

<h4 class="side-title">
<i class="bi bi-clock-history"></i>
Pending Requests
</h4>

<?php foreach ($pending_leaves as $pending): ?>

<div class="pending-card">

<div class="d-flex justify-content-between align-items-start gap-2">

<div>

<?php echo getStatusBadge($pending['status'] ?? 'Pending'); ?>

<div class="pending-title mt-2">
<?php echo e($pending['leave_type']); ?>
Leave
</div>

<div class="pending-meta">
<?php echo safeDate($pending['from_date']); ?>
-
<?php echo safeDate($pending['to_date']); ?>
</div>

<div class="pending-meta">
<?php echo e($pending['total_days']); ?>
days
</div>

</div>

</div>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

<!-- HOLIDAYS -->

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

<div class="holiday-name">
<?php echo e($holiday['holiday_name']); ?>
</div>

<div class="holiday-date">
<?php echo safeDate($holiday['holiday_date']); ?>
</div>

</div>

<span class="badge-pill info">
<span class="mini-dot"></span>
<?php echo e($holiday['holiday_type']); ?>
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

<!-- SUBMIT BAR -->

<div class="submit-bar">

<div class="submit-card">

<div class="submit-info">

<i class="bi bi-info-circle me-1"></i>
Leave request will be submitted for manager approval.

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="my-leaves.php"
class="cancel-btn"
>
<i class="bi bi-x-lg"></i>
Cancel
</a>

<button
type="submit"
class="submit-btn"
>
<i class="bi bi-send"></i>
Submit Application
</button>

</div>

</div>

</div>

</form>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function(){

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

            if (selectedType.value !== 'LWP' && total > balance) {

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

    function setInvalid(el){
        if (el) {
            el.classList.add('is-invalid');
        }
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

            let valid =
                true;

            const errors =
                [];

            form.querySelectorAll('.is-invalid').forEach(function(el){
                el.classList.remove('is-invalid');
            });

            form.querySelectorAll('[required]').forEach(function(field){

                if (!String(field.value || '').trim()) {

                    valid =
                        false;

                    setInvalid(field);

                    const label =
                        form.querySelector('label[for="' + field.id + '"]');

                    const name =
                        label
                        ? label.textContent.replace('*', '').trim()
                        : field.name;

                    errors.push(name + ' is required');
                }
            });

            if (
                fromDate &&
                toDate &&
                fromDate.value &&
                toDate.value &&
                toDate.value < fromDate.value
            ) {
                valid = false;
                setInvalid(fromDate);
                setInvalid(toDate);
                errors.push('To date must be on or after from date');
            }

            const reason =
                document.getElementById('reason');

            if (reason && !reason.value.trim()) {
                valid = false;
                setInvalid(reason);
                errors.push('Reason for leave is required');
            }

            const selectedOption =
                leaveType.options[leaveType.selectedIndex];

            const total =
                calculateTotalDays();

            if (
                selectedOption &&
                selectedOption.value &&
                selectedOption.value !== 'LWP'
            ) {
                const balance =
                    parseFloat(selectedOption.dataset.balance || 0);

                if (total > balance) {
                    valid = false;
                    setInvalid(leaveType);
                    errors.push('Insufficient leave balance');
                }
            }

            if (!valid) {

                e.preventDefault();

                const oldClientAlert =
                    document.getElementById('clientValidationAlert');

                if (oldClientAlert) {
                    oldClientAlert.remove();
                }

                const alertDiv =
                    document.createElement('div');

                alertDiv.id =
                    'clientValidationAlert';

                alertDiv.className =
                    'alert alert-danger alert-dismissible fade show';

                alertDiv.innerHTML =
                    '<div class="d-flex align-items-start">' +
                    '<i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>' +
                    '<div>' +
                    '<strong>Form Validation Error</strong>' +
                    '<ul class="mb-0 mt-2 ps-3">' +
                    errors.map(function(x){ return '<li>' + x + '</li>'; }).join('') +
                    '</ul>' +
                    '</div>' +
                    '</div>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

                const container =
                    document.querySelector('.leave-wrapper');

                const hero =
                    document.querySelector('.leave-hero');

                if (container && hero) {
                    container.insertBefore(alertDiv, hero);
                }

                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
        });
    }

    <?php if (!empty($_POST['from_date']) && !empty($_POST['to_date'])): ?>
    setTimeout(updateDateRange, 100);
    <?php endif; ?>
});

</script>

</body>
</html>

<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>