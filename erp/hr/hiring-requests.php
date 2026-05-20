<?php
// hiring-requests.php
// TEK-C compact table section style

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- AUTH HR / MANAGER ---------------- */

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id =
    (int)$_SESSION['employee_id'];

$current_employee = null;

$emp_stmt =
    mysqli_prepare(
        $conn,
        "SELECT *
         FROM employees
         WHERE id = ?
         AND employee_status = 'active'
         LIMIT 1"
    );

if ($emp_stmt) {

    mysqli_stmt_bind_param(
        $emp_stmt,
        "i",
        $current_employee_id
    );

    mysqli_stmt_execute($emp_stmt);

    $emp_res =
        mysqli_stmt_get_result($emp_stmt);

    $current_employee =
        mysqli_fetch_assoc($emp_res);

    mysqli_stmt_close($emp_stmt);
}

if (!$current_employee) {
    die("Employee not found.");
}

$designation =
    strtolower(trim((string)($current_employee['designation'] ?? '')));

$department =
    strtolower(trim((string)($current_employee['department'] ?? '')));

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
            'director',
            'administrator',
            'admin',
            'general manager'
        ],
        true
    );

$isAdmin =
    $designation === 'administrator' ||
    $designation === 'admin' ||
    $designation === 'director';

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] =
        "You don't have permission to access this page.";

    header("Location: ../dashboard.php");
    exit;
}

/* ---------------- HELPERS ---------------- */

function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($date, $dash = '—'){

    $date =
        trim((string)$date);

    if (
        $date === '' ||
        $date === '0000-00-00' ||
        $date === '0000-00-00 00:00:00'
    ) {
        return $dash;
    }

    $ts =
        strtotime($date);

    return $ts ? date('d M Y', $ts) : e($date);
}

function safeDateTime($date, $dash = '—'){

    $date =
        trim((string)$date);

    if (
        $date === '' ||
        $date === '0000-00-00' ||
        $date === '0000-00-00 00:00:00'
    ) {
        return $dash;
    }

    $ts =
        strtotime($date);

    return $ts ? date('d M Y, h:i A', $ts) : e($date);
}

function moneyRange($min, $max){

    $min =
        $min !== null && $min !== ''
        ? (float)$min
        : 0;

    $max =
        $max !== null && $max !== ''
        ? (float)$max
        : 0;

    if ($min > 0 && $max > 0) {
        return '₹' . number_format($min, 1) . ' - ₹' . number_format($max, 1) . ' LPA';
    }

    if ($min > 0) {
        return 'From ₹' . number_format($min, 1) . ' LPA';
    }

    if ($max > 0) {
        return 'Up to ₹' . number_format($max, 1) . ' LPA';
    }

    return '—';
}

function hiringStatusBadge($status){

    $status =
        trim((string)$status);

    if ($status === 'Pending') {
        return ['Pending', 'warning', 'pending'];
    }

    if ($status === 'Approved') {
        return ['Approved', 'ontrack', 'approved'];
    }

    if ($status === 'In Progress') {
        return ['In Progress', 'info', 'in progress'];
    }

    if ($status === 'Closed') {
        return ['Closed', 'ontrack', 'closed'];
    }

    if ($status === 'Rejected') {
        return ['Rejected', 'danger', 'rejected'];
    }

    if ($status === 'Cancelled') {
        return ['Cancelled', 'muted', 'cancelled'];
    }

    return [$status ?: 'Unknown', 'muted', strtolower($status ?: 'unknown')];
}

function priorityBadge($priority){

    $priority =
        trim((string)$priority);

    if ($priority === 'Urgent') {
        return ['Urgent', 'danger', 'urgent'];
    }

    if ($priority === 'High') {
        return ['High', 'warning', 'high'];
    }

    if ($priority === 'Medium') {
        return ['Medium', 'info', 'medium'];
    }

    if ($priority === 'Low') {
        return ['Low', 'muted', 'low'];
    }

    return [$priority ?: '—', 'muted', strtolower($priority ?: 'unknown')];
}

function logActivity(
    $conn,
    $activity_type,
    $module,
    $description,
    $reference_id = null
){

    $employee_id =
        $_SESSION['employee_id'] ?? null;

    $employee_name =
        $_SESSION['employee_name'] ?? '';

    $username =
        $_SESSION['username'] ?? '';

    $designation =
        $_SESSION['designation'] ?? '';

    $department =
        $_SESSION['department'] ?? '';

    $ip =
        $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt =
        mysqli_prepare(
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

/* ---------------- OPTIONS ---------------- */

$departments = [
    'PM',
    'CM',
    'IFM',
    'QS',
    'HR',
    'ACCOUNTS'
];

$priorities = [
    'Urgent',
    'High',
    'Medium',
    'Low'
];

$status_options = [
    'all' => 'All Status',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'in progress' => 'In Progress',
    'rejected' => 'Rejected',
    'closed' => 'Closed',
    'cancelled' => 'Cancelled'
];

/* ---------------- POST ACTIONS ---------------- */

$message = '';
$messageType = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {

    $action =
        trim((string)($_POST['action'] ?? ''));

    $request_id =
        (int)($_POST['request_id'] ?? 0);

    $remarks =
        trim((string)($_POST['remarks'] ?? ''));

    if (!$isHr && !$isAdmin) {

        $message =
            "Only HR/Admin can approve or reject hiring requests.";

        $messageType =
            "danger";

    } elseif ($request_id <= 0) {

        $message =
            "Invalid hiring request selected.";

        $messageType =
            "danger";

    } elseif ($action === 'reject' && $remarks === '') {

        $message =
            "Rejection reason is required.";

        $messageType =
            "danger";

    } else {

        $requestNoForLog =
            '';

        $oldStatus =
            '';

        $fetchStmt =
            mysqli_prepare(
                $conn,
                "SELECT request_no, status
                 FROM hiring_requests
                 WHERE id = ?
                 LIMIT 1"
            );

        if ($fetchStmt) {

            mysqli_stmt_bind_param(
                $fetchStmt,
                "i",
                $request_id
            );

            mysqli_stmt_execute($fetchStmt);
            mysqli_stmt_bind_result($fetchStmt, $requestNoForLog, $oldStatus);
            mysqli_stmt_fetch($fetchStmt);
            mysqli_stmt_close($fetchStmt);
        }

        if ($oldStatus !== 'Pending') {

            $message =
                "Only pending hiring requests can be processed.";

            $messageType =
                "warning";

        } else {

            if ($action === 'approve') {

                $update_stmt =
                    mysqli_prepare(
                        $conn,
                        "UPDATE hiring_requests
                         SET
                            status = 'Approved',
                            approved_by = ?,
                            approved_by_name = ?,
                            approved_at = NOW(),
                            approver_remarks = ?
                         WHERE id = ?
                         AND status = 'Pending'"
                    );

                if ($update_stmt) {

                    mysqli_stmt_bind_param(
                        $update_stmt,
                        "issi",
                        $current_employee_id,
                        $current_employee['full_name'],
                        $remarks,
                        $request_id
                    );

                    if (mysqli_stmt_execute($update_stmt)) {

                        logActivity(
                            $conn,
                            'UPDATE',
                            'HIRING',
                            'Approved hiring request: ' . ($requestNoForLog ?: '#' . $request_id),
                            $request_id
                        );

                        $message =
                            "Hiring request approved successfully!";

                        $messageType =
                            "success";

                    } else {

                        $message =
                            "Error approving request: " .
                            mysqli_stmt_error($update_stmt);

                        $messageType =
                            "danger";
                    }

                    mysqli_stmt_close($update_stmt);
                }

            } elseif ($action === 'reject') {

                $update_stmt =
                    mysqli_prepare(
                        $conn,
                        "UPDATE hiring_requests
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
                        $request_id
                    );

                    if (mysqli_stmt_execute($update_stmt)) {

                        logActivity(
                            $conn,
                            'UPDATE',
                            'HIRING',
                            'Rejected hiring request: ' . ($requestNoForLog ?: '#' . $request_id),
                            $request_id
                        );

                        $message =
                            "Hiring request rejected successfully!";

                        $messageType =
                            "success";

                    } else {

                        $message =
                            "Error rejecting request: " .
                            mysqli_stmt_error($update_stmt);

                        $messageType =
                            "danger";
                    }

                    mysqli_stmt_close($update_stmt);
                }
            }
        }
    }
}

/* ---------------- FILTERS ---------------- */

$status_filter =
    strtolower(trim((string)($_GET['status'] ?? 'all')));

if (!array_key_exists($status_filter, $status_options)) {
    $status_filter =
        'all';
}

$department_filter =
    trim((string)($_GET['department'] ?? ''));

$priority_filter =
    trim((string)($_GET['priority'] ?? ''));

$search =
    trim((string)($_GET['search'] ?? ''));

/* ---------------- MAIN QUERY ---------------- */

$query = "
    SELECT
        h.*,
        COUNT(c.id) AS candidates_count,
        SUM(CASE WHEN c.status IN ('Selected', 'Offered', 'Joined') THEN 1 ELSE 0 END) AS selected_count,
        SUM(CASE WHEN c.status = 'Joined' THEN 1 ELSE 0 END) AS joined_count
    FROM hiring_requests h
    LEFT JOIN candidates c
    ON h.id = c.hiring_request_id
    WHERE 1 = 1
";

$params = [];
$types = "";

if (!$isHr && !$isAdmin && $isManager) {
    $query .= "
        AND h.requested_by = ?
    ";
    $params[] = $current_employee_id;
    $types .= "i";
}

if ($status_filter !== 'all') {

    $dbStatus =
        $status_options[$status_filter];

    $query .= "
        AND h.status = ?
    ";

    $params[] =
        $dbStatus;

    $types .=
        "s";
}

if ($department_filter !== '') {

    $query .= "
        AND h.department = ?
    ";

    $params[] =
        $department_filter;

    $types .=
        "s";
}

if ($priority_filter !== '') {

    $query .= "
        AND h.priority = ?
    ";

    $params[] =
        $priority_filter;

    $types .=
        "s";
}

if ($search !== '') {

    $like =
        '%' . $search . '%';

    $query .= "
        AND (
            h.request_no LIKE ?
            OR h.position_title LIKE ?
            OR h.designation LIKE ?
            OR h.requested_by_name LIKE ?
            OR h.department LIKE ?
            OR h.location LIKE ?
        )
    ";

    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
        $types .= "s";
    }
}

$query .= "
    GROUP BY h.id
    ORDER BY
        CASE h.priority
            WHEN 'Urgent' THEN 1
            WHEN 'High' THEN 2
            WHEN 'Medium' THEN 3
            WHEN 'Low' THEN 4
            ELSE 5
        END,
        h.created_at DESC
";

$hiring_requests = [];

$stmtRequests =
    mysqli_prepare($conn, $query);

if ($stmtRequests) {

    if (!empty($params)) {
        mysqli_stmt_bind_param(
            $stmtRequests,
            $types,
            ...$params
        );
    }

    mysqli_stmt_execute($stmtRequests);

    $resRequests =
        mysqli_stmt_get_result($stmtRequests);

    $hiring_requests =
        mysqli_fetch_all($resRequests, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtRequests);

} else {

    $message =
        "Error fetching hiring requests: " .
        mysqli_error($conn);

    $messageType =
        "danger";
}

/* ---------------- STATS ---------------- */

$stats = [
    'pending' => 0,
    'approved' => 0,
    'in_progress' => 0,
    'closed' => 0,
    'rejected' => 0,
    'total_vacancies' => 0
];

$stats_query = "
    SELECT
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closed,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected,
        SUM(vacancies) AS total_vacancies
    FROM hiring_requests
    WHERE 1 = 1
";

$stats_params = [];
$stats_types = "";

if (!$isHr && !$isAdmin && $isManager) {
    $stats_query .= "
        AND requested_by = ?
    ";
    $stats_params[] = $current_employee_id;
    $stats_types .= "i";
}

$stmtStats =
    mysqli_prepare($conn, $stats_query);

if ($stmtStats) {

    if (!empty($stats_params)) {
        mysqli_stmt_bind_param(
            $stmtStats,
            $stats_types,
            ...$stats_params
        );
    }

    mysqli_stmt_execute($stmtStats);

    $resStats =
        mysqli_stmt_get_result($stmtStats);

    $rowStats =
        mysqli_fetch_assoc($resStats);

    if ($rowStats) {
        $stats = array_merge($stats, $rowStats);
    }

    mysqli_stmt_close($stmtStats);
}

$total_requests =
    (int)($stats['pending'] ?? 0) +
    (int)($stats['approved'] ?? 0) +
    (int)($stats['in_progress'] ?? 0) +
    (int)($stats['closed'] ?? 0) +
    (int)($stats['rejected'] ?? 0);

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

<title>Hiring Requests - TEK-C</title>

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

.hiring-wrapper{
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

.gray{
    background:#64748b;
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

.filter-select{
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 32px 0 12px;
    font-size:12px;
    font-weight:800;
    min-width:135px;
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

.table-icon{
    width:26px;
    height:26px;
    border-radius:8px;
    display:grid;
    place-items:center;
    background:#eff6ff;
    color:#2563eb;
    font-size:13px;
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

.info{
    color:#2563eb;
    background:#dbeafe;
}

.muted{
    color:#475569;
    background:#f1f5f9;
}

.progress-mini{
    height:6px;
    background:#e5e7eb;
    border-radius:999px;
    overflow:hidden;
    min-width:90px;
}

.progress-fill{
    height:6px;
    background:#10b981;
    float:left;
}

.progress-progress{
    height:6px;
    background:#f59e0b;
    float:left;
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

.people-btn{
    color:#7c3aed;
    background:#ede9fe;
}

.add-btn{
    color:#2563eb;
    background:#eff6ff;
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

    .filter-form,
    .filter-select,
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

<div class="container-fluid hiring-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Hiring Requests
</h1>

<p>
Manage job openings, recruitment requests and candidate progress
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="new-hiring-request.php"
class="primary-btn"
>
<i class="bi bi-plus-circle"></i>
New Request
</a>

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

<!-- ALERTS -->

<?php if (!empty($message)): ?>

<div class="alert alert-<?php echo e($messageType); ?> alert-dismissible fade show" role="alert">

<i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>

<?php echo e($message); ?>

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

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php unset($_SESSION['flash_success']); ?>

<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<?php echo e($_SESSION['flash_error']); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php unset($_SESSION['flash_error']); ?>

<?php endif; ?>

<!-- STATS -->

<div class="row g-3 mb-3">

<div class="col-12 col-sm-6 col-xl-2">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-clock-fill"></i>
</div>

<div>

<div class="stat-label">
Pending
</div>

<div class="stat-value">
<?php echo (int)($stats['pending'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-2">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-check-circle-fill"></i>
</div>

<div>

<div class="stat-label">
Approved
</div>

<div class="stat-value">
<?php echo (int)($stats['approved'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-2">

<div class="stat-card">

<div class="stat-ic purple">
<i class="bi bi-gear-fill"></i>
</div>

<div>

<div class="stat-label">
In Progress
</div>

<div class="stat-value">
<?php echo (int)($stats['in_progress'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-2">

<div class="stat-card">

<div class="stat-ic green">
<i class="bi bi-check2-circle"></i>
</div>

<div>

<div class="stat-label">
Closed
</div>

<div class="stat-value">
<?php echo (int)($stats['closed'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-2">

<div class="stat-card">

<div class="stat-ic red">
<i class="bi bi-x-circle-fill"></i>
</div>

<div>

<div class="stat-label">
Rejected
</div>

<div class="stat-value">
<?php echo (int)($stats['rejected'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-2">

<div class="stat-card">

<div class="stat-ic gray">
<i class="bi bi-people-fill"></i>
</div>

<div>

<div class="stat-label">
Vacancies
</div>

<div class="stat-value">
<?php echo (int)($stats['total_vacancies'] ?? 0); ?>
</div>

</div>

</div>

</div>

</div>

<!-- PANEL -->

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
All Hiring Requests
</h3>

<div class="panel-subtitle">
Compact responsive hiring request directory
</div>

</div>

<span class="badge bg-secondary">
<?php echo count($hiring_requests); ?>
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
placeholder="Search request no, position, department, requester or location..."
value="<?php echo e($search); ?>"
>

</div>

<form
method="GET"
action=""
class="filter-form"
id="filterForm"
>

<select
name="status"
class="filter-select"
>

<?php foreach ($status_options as $value => $label): ?>

<option
value="<?php echo e($value); ?>"
<?php echo $status_filter === $value ? 'selected' : ''; ?>
>
<?php echo e($label); ?>
</option>

<?php endforeach; ?>

</select>

<select
name="department"
class="filter-select"
>

<option value="">
All Departments
</option>

<?php foreach ($departments as $dept): ?>

<option
value="<?php echo e($dept); ?>"
<?php echo $department_filter === $dept ? 'selected' : ''; ?>
>
<?php echo e($dept); ?>
</option>

<?php endforeach; ?>

</select>

<select
name="priority"
class="filter-select"
>

<option value="">
All Priorities
</option>

<?php foreach ($priorities as $priority): ?>

<option
value="<?php echo e($priority); ?>"
<?php echo $priority_filter === $priority ? 'selected' : ''; ?>
>
<?php echo e($priority); ?>
</option>

<?php endforeach; ?>

</select>

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
id="requestsTable"
>

<thead>

<tr>

<th>Request</th>
<th>Position</th>
<th>Dept</th>
<th>Vacancies</th>
<th>Priority</th>
<th>Status</th>
<th>Requester</th>
<th>Progress</th>
<th class="text-end">Actions</th>

</tr>

</thead>

<tbody>

<?php if (empty($hiring_requests)): ?>

<tr class="no-record-row">

<td colspan="9">

<div class="empty-state">

<i class="bi bi-inbox me-1"></i>
No hiring requests found.

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($hiring_requests as $row): ?>

<?php

[$statusLabel, $statusClass, $statusKey] =
    hiringStatusBadge($row['status'] ?? '');

[$priorityLabel, $priorityClass, $priorityKey] =
    priorityBadge($row['priority'] ?? '');

$vacancies =
    (int)($row['vacancies'] ?? 0);

$selected =
    (int)($row['selected_count'] ?? 0);

$joined =
    (int)($row['joined_count'] ?? 0);

$candidates =
    (int)($row['candidates_count'] ?? 0);

$inProgress =
    max(0, $selected - $joined);

$filledWidth =
    $vacancies > 0
    ? min(100, round(($joined / $vacancies) * 100, 2))
    : 0;

$progressWidth =
    $vacancies > 0
    ? min(100 - $filledWidth, round(($inProgress / $vacancies) * 100, 2))
    : 0;

$requestNoEsc =
    e($row['request_no'] ?? '');

?>

<tr
data-status="<?php echo e($statusKey); ?>"
data-priority="<?php echo e($priorityKey); ?>"
data-department="<?php echo e(strtolower($row['department'] ?? '')); ?>"
>

<!-- REQUEST -->

<td data-label="Request">

<div class="table-title-cell">

<div class="table-icon">
<i class="bi bi-file-earmark-text"></i>
</div>

<div>

<div class="table-primary-text">
<?php echo e($row['request_no'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<?php echo e(safeDate($row['created_at'] ?? '')); ?>
</div>

</div>

</div>

</td>

<!-- POSITION -->

<td data-label="Position">

<div class="table-primary-text">
<?php echo e($row['position_title'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<?php echo e($row['designation'] ?? ''); ?>
•
<?php echo e($row['employment_type'] ?? ''); ?>
</div>

<?php if (!empty($row['location'])): ?>

<div class="table-secondary-text">
<i class="bi bi-geo-alt me-1"></i>
<?php echo e($row['location']); ?>
</div>

<?php endif; ?>

</td>

<!-- DEPARTMENT -->

<td data-label="Dept">

<div class="table-primary-text">
<?php echo e($row['department'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<?php echo e(moneyRange($row['salary_min'] ?? null, $row['salary_max'] ?? null)); ?>
</div>

</td>

<!-- VACANCIES -->

<td data-label="Vacancies">

<div class="table-primary-text">
<?php echo (int)$vacancies; ?>
</div>

<div class="table-secondary-text">
Candidates:
<?php echo (int)$candidates; ?>
</div>

</td>

<!-- PRIORITY -->

<td data-label="Priority">

<span class="badge-pill <?php echo e($priorityClass); ?>">

<span class="mini-dot"></span>

<?php echo e($priorityLabel); ?>

</span>

</td>

<!-- STATUS -->

<td data-label="Status">

<span class="badge-pill <?php echo e($statusClass); ?>">

<span class="mini-dot"></span>

<?php echo e($statusLabel); ?>

</span>

</td>

<!-- REQUESTER -->

<td data-label="Requester">

<div class="table-primary-text">
<?php echo e($row['requested_by_name'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<i class="bi bi-calendar me-1"></i>
<?php echo e(safeDate($row['requested_date'] ?? '')); ?>
</div>

<?php if (!empty($row['expected_joining_date'])): ?>

<div class="table-secondary-text">
Join:
<?php echo e(safeDate($row['expected_joining_date'])); ?>
</div>

<?php endif; ?>

</td>

<!-- PROGRESS -->

<td data-label="Progress">

<div class="d-flex align-items-center gap-2">

<div class="progress-mini flex-grow-1">

<?php if ($filledWidth > 0): ?>

<div
class="progress-fill"
style="width:<?php echo (float)$filledWidth; ?>%;"
></div>

<?php endif; ?>

<?php if ($progressWidth > 0): ?>

<div
class="progress-progress"
style="width:<?php echo (float)$progressWidth; ?>%;"
></div>

<?php endif; ?>

</div>

<span class="table-primary-text">
<?php echo (int)$joined; ?>/<?php echo (int)$vacancies; ?>
</span>

</div>

<div class="table-secondary-text">
<?php echo (int)$selected; ?>
selected
<?php if ($joined > 0): ?>
•
<?php echo (int)$joined; ?>
joined
<?php endif; ?>
</div>

</td>

<!-- ACTIONS -->

<td data-label="Actions">

<div class="action-group">

<a
href="view-hiring-request.php?id=<?php echo (int)$row['id']; ?>"
class="action-btn view-btn"
title="View Details"
>
<i class="bi bi-eye"></i>
</a>

<?php if (($row['status'] ?? '') === 'Pending' && ($isHr || $isAdmin)): ?>

<button
type="button"
class="action-btn approve-btn"
onclick="openApproveModal(<?php echo (int)$row['id']; ?>, '<?php echo e(addslashes($row['request_no'] ?? '')); ?>')"
title="Approve"
>
<i class="bi bi-check-lg"></i>
</button>

<button
type="button"
class="action-btn reject-btn"
onclick="openRejectModal(<?php echo (int)$row['id']; ?>, '<?php echo e(addslashes($row['request_no'] ?? '')); ?>')"
title="Reject"
>
<i class="bi bi-x-lg"></i>
</button>

<?php endif; ?>

<a
href="candidates.php?hiring_id=<?php echo (int)$row['id']; ?>"
class="action-btn people-btn"
title="View Candidates"
>
<i class="bi bi-people"></i>
</a>

<?php if (($row['status'] ?? '') === 'Approved' || ($row['status'] ?? '') === 'In Progress'): ?>

<a
href="candidates.php?hiring_id=<?php echo (int)$row['id']; ?>&add=1"
class="action-btn add-btn"
title="Add Candidate"
>
<i class="bi bi-person-plus"></i>
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

<!-- PAGINATION INFO -->

<div class="pagination-wrap">

<div class="pagination-info" id="recordInfo">

Showing
<?php echo count($hiring_requests); ?>
hiring request records

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

<form method="POST">

<input
type="hidden"
name="action"
value="approve"
>

<input
type="hidden"
name="request_id"
id="approve_id"
>

<div class="modal-header">

<h5 class="modal-title fw-bold">
Approve Hiring Request
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Are you sure you want to approve
<strong id="approve_no"></strong>?
</p>

<div class="mb-3">

<label class="form-label">
Remarks
<span class="text-muted">(Optional)</span>
</label>

<textarea
name="remarks"
class="form-control"
rows="2"
placeholder="Add any remarks..."
></textarea>

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
type="submit"
class="btn btn-success"
>
<i class="bi bi-check-lg me-1"></i>
Approve Request
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

<form method="POST">

<input
type="hidden"
name="action"
value="reject"
>

<input
type="hidden"
name="request_id"
id="reject_id"
>

<div class="modal-header">

<h5 class="modal-title fw-bold">
Reject Hiring Request
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Are you sure you want to reject
<strong id="reject_no"></strong>?
</p>

<div class="mb-3">

<label class="form-label">
Reason for Rejection
<span class="text-danger">*</span>
</label>

<textarea
name="remarks"
class="form-control"
rows="3"
placeholder="Please provide reason for rejection"
required
></textarea>

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
type="submit"
class="btn btn-danger"
>
<i class="bi bi-x-lg me-1"></i>
Reject Request
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
Export Hiring Requests
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

This exports the currently displayed rows.

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

document.addEventListener('DOMContentLoaded', function(){

    const quickSearch =
        document.getElementById('quickSearch');

    const serverSearch =
        document.getElementById('serverSearch');

    const tableRows =
        document.querySelectorAll('#requestsTable tbody tr:not(.no-record-row)');

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

            const matchesSearch =
                rowText.includes(searchValue);

            row.style.display =
                matchesSearch
                ? ''
                : 'none';

            if (matchesSearch) {
                visibleCount++;
            }
        });

        if (recordInfo) {
            recordInfo.textContent =
                'Showing ' + visibleCount + ' hiring request records';
        }

        if (serverSearch) {
            serverSearch.value =
                searchValue;
        }
    }

    if (quickSearch) {
        quickSearch.addEventListener('input', filterRows);
    }

});

function openApproveModal(id, requestNo){

    document.getElementById('approve_id').value =
        id;

    document.getElementById('approve_no').textContent =
        requestNo;

    new bootstrap.Modal(
        document.getElementById('approveModal')
    ).show();
}

function openRejectModal(id, requestNo){

    document.getElementById('reject_id').value =
        id;

    document.getElementById('reject_no').textContent =
        requestNo;

    new bootstrap.Modal(
        document.getElementById('rejectModal')
    ).show();
}

function exportToCSV(){

    const rows =
        document.querySelectorAll('#requestsTable tbody tr:not(.no-record-row)');

    const csv =
        [];

    const headers = [
        'Request No',
        'Position',
        'Department',
        'Vacancies',
        'Priority',
        'Status',
        'Requester',
        'Progress'
    ];

    csv.push(headers.join(','));

    rows.forEach(function(row){

        if (row.style.display === 'none') {
            return;
        }

        const cells =
            row.querySelectorAll('td');

        if (cells.length < 8) {
            return;
        }

        const rowData = [
            cells[0]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[1]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[2]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[3]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[4]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[5]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[6]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[7]?.innerText.replace(/\s+/g, ' ').trim() || ''
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
        'hiring_requests_<?php echo date('Y-m-d'); ?>.csv';

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