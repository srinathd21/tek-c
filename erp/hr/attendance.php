<?php
// attendance.php - Attendance Management Page
// TEK-C compact table section style

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- AUTH HR ---------------- */

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$designation =
    trim((string)($_SESSION['designation'] ?? ''));

$department =
    trim((string)($_SESSION['department'] ?? ''));

$isHr =
    strtolower($designation) === 'hr' ||
    strtolower($department) === 'hr';

if (!$isHr) {
    $fallback = $_SESSION['role_redirect'] ?? '../login.php';
    header("Location: " . $fallback);
    exit;
}

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

function safeDateInput($v){

    $v = trim((string)$v);

    if ($v === '' || $v === '0000-00-00') {
        return '';
    }

    $ts = strtotime($v);

    return $ts ? date('Y-m-d', $ts) : '';
}

function formatTime($v, $dash = '—'){

    $v = trim((string)$v);

    if (
        $v === '' ||
        $v === '00:00:00' ||
        $v === '0000-00-00 00:00:00'
    ) {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('h:i A', $ts) : e($v);
}

function getInitials($name){

    $name = trim((string)$name);

    if ($name === '') {
        return 'U';
    }

    $parts =
        preg_split('/\s+/', $name);

    $first =
        strtoupper(substr($parts[0] ?? 'U', 0, 1));

    $last =
        strtoupper(substr(end($parts) ?: '', 0, 1));

    return count($parts) > 1
        ? $first . $last
        : $first;
}

function attendanceStatusBadge($status){

    $status =
        strtolower(trim((string)$status));

    if ($status === 'present') {
        return ['Present', 'ontrack', 'present'];
    }

    if ($status === 'absent') {
        return ['Absent', 'danger', 'absent'];
    }

    if ($status === 'half-day') {
        return ['Half Day', 'warning', 'half-day'];
    }

    if ($status === 'late') {
        return ['Late', 'danger', 'late'];
    }

    if ($status === 'holiday') {
        return ['Holiday', 'ontrack', 'holiday'];
    }

    if ($status === 'leave') {
        return ['On Leave', 'warning', 'leave'];
    }

    if ($status === 'vacation') {
        return ['Vacation', 'ontrack', 'vacation'];
    }

    return ['Unknown', 'danger', 'unknown'];
}

function fileUrl($path){

    $p =
        trim((string)$path);

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

/* ---------------- FILTERS ---------------- */

$selectedMonth =
    isset($_GET['month'])
    ? (int)$_GET['month']
    : (int)date('m');

$selectedYear =
    isset($_GET['year'])
    ? (int)$_GET['year']
    : (int)date('Y');

$selectedEmployee =
    isset($_GET['employee_id'])
    ? (int)$_GET['employee_id']
    : 0;

$selectedStatus =
    trim((string)($_GET['status'] ?? ''));

if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('m');
}

if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = (int)date('Y');
}

$allowedStatuses = [
    '',
    'present',
    'absent',
    'half-day',
    'late',
    'holiday',
    'leave',
    'vacation'
];

if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = '';
}

$months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

$currentYear =
    (int)date('Y');

$years =
    range($currentYear - 2, $currentYear + 1);

$today =
    date('Y-m-d');

/* ---------------- EMPLOYEES DROPDOWN ---------------- */

$employees = [];

$stmtEmployees =
    mysqli_prepare(
        $conn,
        "SELECT id, full_name, employee_code
         FROM employees
         WHERE LOWER(employee_status) = 'active'
         ORDER BY full_name ASC"
    );

if ($stmtEmployees) {

    mysqli_stmt_execute($stmtEmployees);

    $resEmployees =
        mysqli_stmt_get_result($stmtEmployees);

    while ($row = mysqli_fetch_assoc($resEmployees)) {
        $employees[] = $row;
    }

    mysqli_stmt_close($stmtEmployees);
}

/* ---------------- TOP STATS ---------------- */

$totalEmployees =
    count($employees);

$presentToday =
    0;

$onLeaveToday =
    0;

$absentToday =
    0;

$stmtToday =
    mysqli_prepare(
        $conn,
        "SELECT
            SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) AS present_today,
            SUM(CASE WHEN status IN ('leave', 'vacation') THEN 1 ELSE 0 END) AS leave_today,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_today
         FROM attendance
         WHERE attendance_date = ?"
    );

if ($stmtToday) {

    mysqli_stmt_bind_param(
        $stmtToday,
        "s",
        $today
    );

    mysqli_stmt_execute($stmtToday);

    $resToday =
        mysqli_stmt_get_result($stmtToday);

    $rowToday =
        mysqli_fetch_assoc($resToday);

    $presentToday =
        (int)($rowToday['present_today'] ?? 0);

    $onLeaveToday =
        (int)($rowToday['leave_today'] ?? 0);

    $absentToday =
        (int)($rowToday['absent_today'] ?? 0);

    mysqli_stmt_close($stmtToday);
}

/* ---------------- ATTENDANCE RECORDS ---------------- */

$attendanceRecords = [];

$query = "
SELECT
    a.*,
    e.full_name,
    e.employee_code,
    e.department,
    e.designation,
    e.photo,
    s.project_name AS site_name,
    o.location_name AS office_name
FROM attendance a
INNER JOIN employees e
ON e.id = a.employee_id
LEFT JOIN sites s
ON s.id = a.punch_in_site_id
LEFT JOIN office_locations o
ON o.id = a.punch_in_office_id
WHERE MONTH(a.attendance_date) = ?
AND YEAR(a.attendance_date) = ?
";

$params =
    [$selectedMonth, $selectedYear];

$types =
    "ii";

if ($selectedEmployee > 0) {

    $query .= "
    AND a.employee_id = ?
    ";

    $params[] =
        $selectedEmployee;

    $types .=
        "i";
}

if ($selectedStatus !== '') {

    $query .= "
    AND a.status = ?
    ";

    $params[] =
        $selectedStatus;

    $types .=
        "s";
}

$query .= "
ORDER BY a.attendance_date DESC, e.full_name ASC
";

$stmtRecords =
    mysqli_prepare($conn, $query);

if ($stmtRecords) {

    mysqli_stmt_bind_param(
        $stmtRecords,
        $types,
        ...$params
    );

    mysqli_stmt_execute($stmtRecords);

    $resRecords =
        mysqli_stmt_get_result($stmtRecords);

    $attendanceRecords =
        mysqli_fetch_all($resRecords, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtRecords);
}

/* ---------------- MONTH SUMMARY ---------------- */

$summaryStats = [
    'total' => 0,
    'present' => 0,
    'absent' => 0,
    'half_day' => 0,
    'late' => 0,
    'leave' => 0,
    'holiday' => 0
];

foreach ($attendanceRecords as $record) {

    $summaryStats['total']++;

    $status =
        strtolower(trim((string)($record['status'] ?? '')));

    if ($status === 'present') {
        $summaryStats['present']++;
    } elseif ($status === 'absent') {
        $summaryStats['absent']++;
    } elseif ($status === 'half-day') {
        $summaryStats['half_day']++;
    } elseif ($status === 'late') {
        $summaryStats['late']++;
    } elseif ($status === 'leave' || $status === 'vacation') {
        $summaryStats['leave']++;
    } elseif ($status === 'holiday') {
        $summaryStats['holiday']++;
    }
}

$selectedEmployeeName =
    '';

if ($selectedEmployee > 0) {
    foreach ($employees as $emp) {
        if ((int)$emp['id'] === $selectedEmployee) {
            $selectedEmployeeName = $emp['full_name'];
            break;
        }
    }
}

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8" />

<meta
name="viewport"
content="width=device-width, initial-scale=1"
/>

<title>Attendance Management - TEK-C</title>

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

.attendance-wrapper{
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

.regularize-btn{
    background:#2563eb;
}

.regularize-btn:hover{
    background:#1d4ed8;
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

.filter-select{
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 32px 0 12px;
    font-size:12px;
    font-weight:800;
    min-width:130px;
}

.filter-employee{
    min-width:220px;
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

.detail-text{
    font-size:10px;
    color:#64748b;
    line-height:1.5;
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

.map-btn{
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

.empty-state{
    text-align:center;
    padding:28px 12px;
    color:#64748b;
    font-weight:800;
    font-size:12px;
}

.summary-strip{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));
    gap:10px;
    margin-bottom:12px;
}

.summary-item{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:13px;
    padding:10px;
    text-align:center;
}

.summary-value{
    font-size:22px;
    font-weight:950;
    color:#111827;
    line-height:1;
}

.summary-label{
    color:#64748b;
    font-size:10px;
    font-weight:900;
    margin-top:5px;
    text-transform:uppercase;
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

<div id="contentScroll" class="content-scroll">

<div class="container-fluid attendance-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Attendance Management
</h1>

<p>
Track employee punch-in, punch-out, hours and daily attendance status
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="attendance-regularization.php"
class="primary-btn regularize-btn"
>
<i class="bi bi-clock-history"></i>
Regularization
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

<!-- STATS -->

<div class="row g-3 mb-3">

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-people-fill"></i>
</div>

<div>

<div class="stat-label">
Total Employees
</div>

<div class="stat-value">
<?php echo (int)$totalEmployees; ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic green">
<i class="bi bi-check-circle-fill"></i>
</div>

<div>

<div class="stat-label">
Present Today
</div>

<div class="stat-value">
<?php echo (int)$presentToday; ?>
</div>

<div class="stat-small">
<?php echo e(safeDate($today)); ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-calendar2-x-fill"></i>
</div>

<div>

<div class="stat-label">
On Leave
</div>

<div class="stat-value">
<?php echo (int)$onLeaveToday; ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic red">
<i class="bi bi-x-circle-fill"></i>
</div>

<div>

<div class="stat-label">
Absent Today
</div>

<div class="stat-value">
<?php echo (int)$absentToday; ?>
</div>

</div>

</div>

</div>

</div>

<!-- MONTH SUMMARY -->

<?php if ($summaryStats['total'] > 0): ?>

<div class="summary-strip">

<div class="summary-item">

<div class="summary-value">
<?php echo (int)$summaryStats['present']; ?>
</div>

<div class="summary-label">
Present
</div>

</div>

<div class="summary-item">

<div class="summary-value">
<?php echo (int)$summaryStats['absent']; ?>
</div>

<div class="summary-label">
Absent
</div>

</div>

<div class="summary-item">

<div class="summary-value">
<?php echo (int)$summaryStats['half_day']; ?>
</div>

<div class="summary-label">
Half Days
</div>

</div>

<div class="summary-item">

<div class="summary-value">
<?php echo (int)$summaryStats['late']; ?>
</div>

<div class="summary-label">
Late
</div>

</div>

<div class="summary-item">

<div class="summary-value">
<?php echo (int)$summaryStats['leave']; ?>
</div>

<div class="summary-label">
Leaves
</div>

</div>

<div class="summary-item">

<div class="summary-value">
<?php echo (int)$summaryStats['holiday']; ?>
</div>

<div class="summary-label">
Holidays
</div>

</div>

</div>

<?php endif; ?>

<!-- PANEL -->

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Attendance Records -
<?php echo e($months[$selectedMonth]); ?>
<?php echo (int)$selectedYear; ?>
</h3>

<div class="panel-subtitle">

Compact responsive attendance directory

<?php if ($selectedEmployeeName !== ''): ?>

•
<?php echo e($selectedEmployeeName); ?>

<?php endif; ?>

</div>

</div>

<span class="badge bg-secondary">
<?php echo count($attendanceRecords); ?>
records
</span>

</div>

<!-- FILTER BAR -->

<div class="filter-bar">

<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="attendanceSearch"
placeholder="Search employee, code, department, location or status..."
>

</div>

<form
method="GET"
action=""
class="filter-form"
>

<select
name="month"
class="filter-select"
>

<?php foreach ($months as $num => $name): ?>

<option
value="<?php echo (int)$num; ?>"
<?php echo ((int)$selectedMonth === (int)$num) ? 'selected' : ''; ?>
>
<?php echo e($name); ?>
</option>

<?php endforeach; ?>

</select>

<select
name="year"
class="filter-select"
>

<?php foreach ($years as $year): ?>

<option
value="<?php echo (int)$year; ?>"
<?php echo ((int)$selectedYear === (int)$year) ? 'selected' : ''; ?>
>
<?php echo (int)$year; ?>
</option>

<?php endforeach; ?>

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
<?php echo ((int)$selectedEmployee === (int)$emp['id']) ? 'selected' : ''; ?>
>
<?php echo e($emp['full_name']); ?>
(<?php echo e($emp['employee_code']); ?>)
</option>

<?php endforeach; ?>

</select>

<select
name="status"
class="filter-select"
>

<option value="">
All Status
</option>

<option value="present" <?php echo $selectedStatus === 'present' ? 'selected' : ''; ?>>
Present
</option>

<option value="absent" <?php echo $selectedStatus === 'absent' ? 'selected' : ''; ?>>
Absent
</option>

<option value="half-day" <?php echo $selectedStatus === 'half-day' ? 'selected' : ''; ?>>
Half Day
</option>

<option value="late" <?php echo $selectedStatus === 'late' ? 'selected' : ''; ?>>
Late
</option>

<option value="leave" <?php echo $selectedStatus === 'leave' ? 'selected' : ''; ?>>
Leave
</option>

<option value="vacation" <?php echo $selectedStatus === 'vacation' ? 'selected' : ''; ?>>
Vacation
</option>

<option value="holiday" <?php echo $selectedStatus === 'holiday' ? 'selected' : ''; ?>>
Holiday
</option>

</select>

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
id="attendanceTable"
>

<thead>

<tr>

<th>Employee</th>
<th>Date</th>
<th>Punch In</th>
<th>Punch Out</th>
<th>Hours</th>
<th>Status</th>
<th>Location</th>
<th class="text-end">Actions</th>

</tr>

</thead>

<tbody>

<?php if (empty($attendanceRecords)): ?>

<tr class="no-record-row">

<td colspan="8">

<div class="empty-state">

<i class="bi bi-calendar-x me-1"></i>
No attendance records found for the selected filters.

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($attendanceRecords as $record): ?>

<?php

[$statusLabel, $statusClass, $statusKey] =
    attendanceStatusBadge($record['status'] ?? '');

$photoSrc =
    fileUrl($record['photo'] ?? '');

$employeeName =
    trim((string)($record['full_name'] ?? ''));

$initials =
    getInitials($employeeName);

$punchInTime =
    formatTime($record['punch_in_time'] ?? '');

$punchOutTime =
    formatTime($record['punch_out_time'] ?? '');

$totalHours =
    $record['total_hours'] ?? null;

$locationText =
    '—';

$locationIcon =
    'bi-geo-alt';

if (($record['punch_in_type'] ?? '') === 'site' && !empty($record['site_name'])) {

    $locationText =
        $record['site_name'];

    $locationIcon =
        'bi-building';

} elseif (($record['punch_in_type'] ?? '') === 'office' && !empty($record['office_name'])) {

    $locationText =
        $record['office_name'];

    $locationIcon =
        'bi-briefcase';

} elseif (($record['punch_in_type'] ?? '') === 'remote') {

    $locationText =
        'Remote';

    $locationIcon =
        'bi-house';

} elseif (!empty($record['punch_in_location'])) {

    $locationText =
        $record['punch_in_location'];
}

$lat =
    $record['punch_in_latitude'] ?? '';

$lng =
    $record['punch_in_longitude'] ?? '';

$mapLink =
    '';

if ($lat !== '' && $lng !== '') {
    $mapLink =
        'https://www.google.com/maps?q=' .
        urlencode($lat . ',' . $lng);
}

?>

<tr
data-status="<?php echo e($statusKey); ?>"
>

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

<?php echo e($record['employee_code'] ?? ''); ?>

<?php if (!empty($record['department'])): ?>

•
<?php echo e($record['department']); ?>

<?php endif; ?>

</div>

</div>

</div>

</td>

<!-- DATE -->

<td data-label="Date">

<div class="table-primary-text">
<?php echo e(safeDate($record['attendance_date'] ?? '')); ?>
</div>

<div class="table-secondary-text">
<?php echo e($record['designation'] ?? ''); ?>
</div>

</td>

<!-- PUNCH IN -->

<td data-label="Punch In">

<div class="table-primary-text">
<?php echo e($punchInTime); ?>
</div>

<div class="table-secondary-text">

<?php if (!empty($record['punch_in_type'])): ?>

<?php echo e(ucfirst($record['punch_in_type'])); ?>

<?php endif; ?>

<?php if ((int)($record['late_minutes'] ?? 0) > 0): ?>

•
Late by
<?php echo (int)$record['late_minutes']; ?>
min

<?php endif; ?>

</div>

</td>

<!-- PUNCH OUT -->

<td data-label="Punch Out">

<div class="table-primary-text">
<?php echo e($punchOutTime); ?>
</div>

<div class="table-secondary-text">

<?php if (!empty($record['punch_out_type'])): ?>

<?php echo e(ucfirst($record['punch_out_type'])); ?>

<?php endif; ?>

<?php if ((int)($record['early_exit_minutes'] ?? 0) > 0): ?>

•
Early by
<?php echo (int)$record['early_exit_minutes']; ?>
min

<?php endif; ?>

</div>

</td>

<!-- HOURS -->

<td data-label="Hours">

<div class="table-primary-text">

<?php if ($totalHours !== null && $totalHours !== '' && (float)$totalHours > 0): ?>

<?php echo number_format((float)$totalHours, 2); ?>
hrs

<?php else: ?>

—

<?php endif; ?>

</div>

<div class="table-secondary-text">

<?php if ((int)($record['overtime_minutes'] ?? 0) > 0): ?>

OT:
<?php echo (int)$record['overtime_minutes']; ?>
min

<?php else: ?>

No OT

<?php endif; ?>

</div>

</td>

<!-- STATUS -->

<td data-label="Status">

<span class="badge-pill <?php echo e($statusClass); ?>">

<span class="mini-dot"></span>

<?php echo e($statusLabel); ?>

</span>

</td>

<!-- LOCATION -->

<td data-label="Location">

<div class="detail-text">

<i class="bi <?php echo e($locationIcon); ?> me-1"></i>

<?php echo e($locationText); ?>

</div>

<?php if (!empty($record['remarks'])): ?>

<div class="table-secondary-text">
<?php echo e($record['remarks']); ?>
</div>

<?php endif; ?>

</td>

<!-- ACTIONS -->

<td data-label="Actions">

<div class="action-group">

<a
href="view-attendance.php?id=<?php echo (int)$record['id']; ?>"
class="action-btn view-btn"
title="View Details"
>
<i class="bi bi-eye"></i>
</a>

<?php if ($mapLink !== ''): ?>

<a
href="<?php echo e($mapLink); ?>"
target="_blank"
rel="noopener"
class="action-btn map-btn"
title="Open Location"
>
<i class="bi bi-geo-alt"></i>
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
<?php echo count($attendanceRecords); ?>
attendance records

</div>

</div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

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
Export Attendance
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<form
method="GET"
action="attendance-export.php"
>

<div class="modal-body">

<div class="row g-3">

<div class="col-md-6">

<label class="form-label">
Month
</label>

<select
name="month"
class="form-select"
>

<?php foreach ($months as $num => $name): ?>

<option
value="<?php echo (int)$num; ?>"
<?php echo ((int)$selectedMonth === (int)$num) ? 'selected' : ''; ?>
>
<?php echo e($name); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label class="form-label">
Year
</label>

<select
name="year"
class="form-select"
>

<?php foreach ($years as $year): ?>

<option
value="<?php echo (int)$year; ?>"
<?php echo ((int)$selectedYear === (int)$year) ? 'selected' : ''; ?>
>
<?php echo (int)$year; ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-12">

<label class="form-label">
Employee
</label>

<select
name="employee_id"
class="form-select"
>

<option value="0">
All Employees
</option>

<?php foreach ($employees as $emp): ?>

<option
value="<?php echo (int)$emp['id']; ?>"
<?php echo ((int)$selectedEmployee === (int)$emp['id']) ? 'selected' : ''; ?>
>
<?php echo e($emp['full_name']); ?>
(<?php echo e($emp['employee_code']); ?>)
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-12">

<label class="form-label">
Status
</label>

<select
name="status"
class="form-select"
>

<option value="">
All Status
</option>

<option value="present" <?php echo $selectedStatus === 'present' ? 'selected' : ''; ?>>
Present
</option>

<option value="absent" <?php echo $selectedStatus === 'absent' ? 'selected' : ''; ?>>
Absent
</option>

<option value="half-day" <?php echo $selectedStatus === 'half-day' ? 'selected' : ''; ?>>
Half Day
</option>

<option value="late" <?php echo $selectedStatus === 'late' ? 'selected' : ''; ?>>
Late
</option>

<option value="leave" <?php echo $selectedStatus === 'leave' ? 'selected' : ''; ?>>
Leave
</option>

<option value="vacation" <?php echo $selectedStatus === 'vacation' ? 'selected' : ''; ?>>
Vacation
</option>

<option value="holiday" <?php echo $selectedStatus === 'holiday' ? 'selected' : ''; ?>>
Holiday
</option>

</select>

</div>

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
<i class="bi bi-download me-1"></i>
Export
</button>

</div>

</form>

</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function(){

    const searchInput =
        document.getElementById('attendanceSearch');

    const tableRows =
        document.querySelectorAll('#attendanceTable tbody tr:not(.no-record-row)');

    const recordInfo =
        document.getElementById('recordInfo');

    function filterAttendance(){

        const searchValue =
            searchInput.value.toLowerCase().trim();

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
                'Showing ' + visibleCount + ' attendance records';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterAttendance);
    }

});

</script>

</body>

</html>

<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>