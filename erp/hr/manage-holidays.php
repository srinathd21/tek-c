<?php
// manage-holidays.php
// TEK-C compact table section style

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$success = '';
$error = '';
$validation_errors = [];
$holidays = [];

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
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

function holidayTypeBadge($type){

    $type = trim((string)$type);

    if ($type === 'Company') {
        return ['Company', 'ontrack', 'company'];
    }

    if ($type === 'Optional') {
        return ['Optional', 'warning', 'optional'];
    }

    return ['Public', 'info', 'public'];
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

/* ---------------- OPTIONS ---------------- */

$holiday_types = [
    'Public',
    'Company',
    'Optional'
];

/* ---------------- POST ACTIONS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        trim((string)($_POST['action'] ?? ''));

    /* ---------- ADD HOLIDAY ---------- */

    if ($action === 'add') {

        $holiday_name =
            trim($_POST['holiday_name'] ?? '');

        $holiday_date =
            trim($_POST['holiday_date'] ?? '');

        $holiday_type =
            trim($_POST['holiday_type'] ?? 'Public');

        $description =
            trim($_POST['description'] ?? '');

        if ($holiday_name === '') {
            $validation_errors[] = "Holiday name is required";
        }

        if ($holiday_date === '') {
            $validation_errors[] = "Holiday date is required";
        }

        if (
            $holiday_type !== '' &&
            !in_array($holiday_type, $holiday_types, true)
        ) {
            $validation_errors[] = "Invalid holiday type selected";
        }

        if ($holiday_date !== '') {

            $date_ts =
                strtotime($holiday_date);

            if (!$date_ts) {
                $validation_errors[] = "Invalid holiday date";
            } else {
                $holiday_date =
                    date('Y-m-d', $date_ts);
            }
        }

        if (empty($validation_errors)) {

            $checkStmt =
                mysqli_prepare(
                    $conn,
                    "SELECT id
                     FROM holidays
                     WHERE holiday_date = ?
                     LIMIT 1"
                );

            if ($checkStmt) {

                mysqli_stmt_bind_param(
                    $checkStmt,
                    "s",
                    $holiday_date
                );

                mysqli_stmt_execute($checkStmt);
                mysqli_stmt_store_result($checkStmt);

                if (mysqli_stmt_num_rows($checkStmt) > 0) {
                    $validation_errors[] =
                        "A holiday already exists on this date";
                }

                mysqli_stmt_close($checkStmt);
            }
        }

        if (empty($validation_errors)) {

            $stmt =
                mysqli_prepare(
                    $conn,
                    "INSERT INTO holidays
                    (
                        holiday_name,
                        holiday_date,
                        holiday_type,
                        description
                    )
                    VALUES
                    (?, ?, ?, ?)"
                );

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssss",
                    $holiday_name,
                    $holiday_date,
                    $holiday_type,
                    $description
                );

                if (mysqli_stmt_execute($stmt)) {

                    $new_holiday_id =
                        mysqli_insert_id($conn);

                    logActivity(
                        $conn,
                        'CREATE',
                        'HOLIDAY',
                        'Created new holiday: ' . $holiday_name,
                        $new_holiday_id
                    );

                    $success =
                        "Holiday added successfully!";

                } else {

                    $error =
                        "Error adding holiday: " .
                        mysqli_stmt_error($stmt);
                }

                mysqli_stmt_close($stmt);

            } else {

                $error =
                    "Database error: " .
                    mysqli_error($conn);
            }
        }
    }

    /* ---------- EDIT HOLIDAY ---------- */

    elseif ($action === 'edit') {

        $holiday_id =
            (int)($_POST['holiday_id'] ?? 0);

        $holiday_name =
            trim($_POST['holiday_name'] ?? '');

        $holiday_date =
            trim($_POST['holiday_date'] ?? '');

        $holiday_type =
            trim($_POST['holiday_type'] ?? 'Public');

        $description =
            trim($_POST['description'] ?? '');

        if ($holiday_id <= 0) {
            $validation_errors[] = "Invalid holiday selected";
        }

        if ($holiday_name === '') {
            $validation_errors[] = "Holiday name is required";
        }

        if ($holiday_date === '') {
            $validation_errors[] = "Holiday date is required";
        }

        if (
            $holiday_type !== '' &&
            !in_array($holiday_type, $holiday_types, true)
        ) {
            $validation_errors[] = "Invalid holiday type selected";
        }

        if ($holiday_date !== '') {

            $date_ts =
                strtotime($holiday_date);

            if (!$date_ts) {
                $validation_errors[] = "Invalid holiday date";
            } else {
                $holiday_date =
                    date('Y-m-d', $date_ts);
            }
        }

        if (empty($validation_errors)) {

            $checkStmt =
                mysqli_prepare(
                    $conn,
                    "SELECT id
                     FROM holidays
                     WHERE holiday_date = ?
                     AND id <> ?
                     LIMIT 1"
                );

            if ($checkStmt) {

                mysqli_stmt_bind_param(
                    $checkStmt,
                    "si",
                    $holiday_date,
                    $holiday_id
                );

                mysqli_stmt_execute($checkStmt);
                mysqli_stmt_store_result($checkStmt);

                if (mysqli_stmt_num_rows($checkStmt) > 0) {
                    $validation_errors[] =
                        "Another holiday already exists on this date";
                }

                mysqli_stmt_close($checkStmt);
            }
        }

        if (empty($validation_errors)) {

            $stmt =
                mysqli_prepare(
                    $conn,
                    "UPDATE holidays
                     SET
                        holiday_name = ?,
                        holiday_date = ?,
                        holiday_type = ?,
                        description = ?
                     WHERE id = ?
                     LIMIT 1"
                );

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssssi",
                    $holiday_name,
                    $holiday_date,
                    $holiday_type,
                    $description,
                    $holiday_id
                );

                if (mysqli_stmt_execute($stmt)) {

                    logActivity(
                        $conn,
                        'UPDATE',
                        'HOLIDAY',
                        'Updated holiday: ' . $holiday_name,
                        $holiday_id
                    );

                    $success =
                        "Holiday updated successfully!";

                } else {

                    $error =
                        "Error updating holiday: " .
                        mysqli_stmt_error($stmt);
                }

                mysqli_stmt_close($stmt);

            } else {

                $error =
                    "Database error: " .
                    mysqli_error($conn);
            }
        }
    }

    /* ---------- DELETE HOLIDAY ---------- */

    elseif (isset($_POST['delete_id'])) {

        $holiday_id =
            (int)($_POST['delete_id'] ?? 0);

        if ($holiday_id <= 0) {

            $error =
                "Invalid holiday selected.";

        } else {

            $holiday_name_for_log =
                '';

            $fetchStmt =
                mysqli_prepare(
                    $conn,
                    "SELECT holiday_name
                     FROM holidays
                     WHERE id = ?
                     LIMIT 1"
                );

            if ($fetchStmt) {

                mysqli_stmt_bind_param(
                    $fetchStmt,
                    "i",
                    $holiday_id
                );

                mysqli_stmt_execute($fetchStmt);
                mysqli_stmt_bind_result($fetchStmt, $holiday_name_for_log);
                mysqli_stmt_fetch($fetchStmt);
                mysqli_stmt_close($fetchStmt);
            }

            $stmt =
                mysqli_prepare(
                    $conn,
                    "DELETE FROM holidays
                     WHERE id = ?
                     LIMIT 1"
                );

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    "i",
                    $holiday_id
                );

                if (mysqli_stmt_execute($stmt)) {

                    logActivity(
                        $conn,
                        'DELETE',
                        'HOLIDAY',
                        'Deleted holiday: ' . ($holiday_name_for_log ?: 'Holiday #' . $holiday_id),
                        $holiday_id
                    );

                    $success =
                        "Holiday deleted successfully!";

                } else {

                    $error =
                        "Error deleting holiday: " .
                        mysqli_stmt_error($stmt);
                }

                mysqli_stmt_close($stmt);

            } else {

                $error =
                    "Database error: " .
                    mysqli_error($conn);
            }
        }
    }
}

/* ---------------- FILTERS ---------------- */

$current_year =
    (int)date('Y');

$filter_year =
    isset($_GET['year'])
    ? (int)$_GET['year']
    : $current_year;

if ($filter_year < 2000 || $filter_year > 2100) {
    $filter_year =
        $current_year;
}

/* ---------------- FETCH HOLIDAYS ---------------- */

$stmt =
    mysqli_prepare(
        $conn,
        "SELECT
            id,
            holiday_name,
            holiday_date,
            holiday_type,
            description,
            created_at,
            updated_at
         FROM holidays
         WHERE YEAR(holiday_date) = ?
         ORDER BY holiday_date ASC"
    );

if ($stmt) {

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $filter_year
    );

    mysqli_stmt_execute($stmt);

    $res =
        mysqli_stmt_get_result($stmt);

    $holidays =
        mysqli_fetch_all($res, MYSQLI_ASSOC);

    mysqli_stmt_close($stmt);

} else {

    $error =
        "Error fetching holidays: " .
        mysqli_error($conn);
}

/* ---------------- YEARS ---------------- */

$years = [];

$yearRes =
    mysqli_query(
        $conn,
        "SELECT DISTINCT YEAR(holiday_date) AS holiday_year
         FROM holidays
         ORDER BY holiday_year DESC"
    );

if ($yearRes) {

    while ($row = mysqli_fetch_assoc($yearRes)) {
        if (!empty($row['holiday_year'])) {
            $years[] = (int)$row['holiday_year'];
        }
    }

    mysqli_free_result($yearRes);
}

if (!in_array($current_year, $years, true)) {
    $years[] =
        $current_year;
}

sort($years);

/* ---------------- STATS ---------------- */

$stats = [
    'Public' => 0,
    'Company' => 0,
    'Optional' => 0
];

foreach ($holidays as $holiday) {

    $type =
        $holiday['holiday_type'] ?? 'Public';

    if (!isset($stats[$type])) {
        $stats[$type] = 0;
    }

    $stats[$type]++;
}

$total_holidays =
    count($holidays);

/* ---------------- UPCOMING HOLIDAYS ---------------- */

$upcoming = [];

$upcomingRes =
    mysqli_query(
        $conn,
        "SELECT
            id,
            holiday_name,
            holiday_date,
            holiday_type,
            description
         FROM holidays
         WHERE holiday_date >= CURDATE()
         AND holiday_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY holiday_date ASC
         LIMIT 5"
    );

if ($upcomingRes) {

    $upcoming =
        mysqli_fetch_all($upcomingRes, MYSQLI_ASSOC);

    mysqli_free_result($upcomingRes);
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

<title>Holiday Calendar - TEK-C</title>

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

.holidays-wrapper{
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

.filter-select{
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 42px 0 12px;
    font-size:12px;
    font-weight:800;
    min-width:145px;
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
}

.mini-dot{
    width:6px;
    height:6px;
    border-radius:50%;
    background:currentColor;
}

.info{
    color:#2563eb;
    background:#dbeafe;
}

.ontrack{
    color:#15803d;
    background:#dcfce7;
}

.warning{
    color:#b45309;
    background:#fef3c7;
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

.edit-btn{
    color:#2563eb;
    background:#eff6ff;
}

.delete-btn{
    color:#b91c1c;
    background:#fee2e2;
    border-color:#fecaca;
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
}

.upcoming-strip{
    background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
    border-radius:var(--radius);
    padding:14px;
    color:#fff;
    margin-bottom:14px;
    box-shadow:var(--shadow);
}

.upcoming-title{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:13px;
    font-weight:900;
    margin-bottom:10px;
}

.upcoming-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:8px;
}

.upcoming-item{
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.14);
    border-radius:12px;
    padding:10px;
}

.upcoming-name{
    font-weight:900;
    font-size:12px;
}

.upcoming-date{
    opacity:.9;
    font-size:10px;
    font-weight:700;
    margin-top:3px;
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
}

</style>

</head>

<body>

<div class="app">

<?php include 'includes/sidebar.php'; ?>

<main class="main" aria-label="Main">

<?php include 'includes/topbar.php'; ?>

<div id="contentScroll" class="content-scroll">

<div class="container-fluid holidays-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Holiday Calendar
</h1>

<p>
Manage company holidays and observances
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<button
class="primary-btn"
data-bs-toggle="modal"
data-bs-target="#addHolidayModal"
>
<i class="bi bi-calendar-plus"></i>
Add Holiday
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

<!-- ALERTS -->

<?php if ($success): ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">

<i class="bi bi-check-circle-fill me-2"></i>

<?php echo e($success); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<?php if ($error): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<?php echo e($error); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<?php if (!empty($validation_errors)): ?>

<div class="alert alert-warning alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<strong>Please fix the following errors:</strong>

<ul class="mb-0 mt-2 ps-3">

<?php foreach ($validation_errors as $err): ?>

<li>
<?php echo e($err); ?>
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

<!-- STATS -->

<div class="row g-3 mb-3">

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-calendar-week"></i>
</div>

<div>

<div class="stat-label">
Total Holidays
<?php echo (int)$filter_year; ?>
</div>

<div class="stat-value">
<?php echo (int)$total_holidays; ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic green">
<i class="bi bi-building"></i>
</div>

<div>

<div class="stat-label">
Public Holidays
</div>

<div class="stat-value">
<?php echo (int)($stats['Public'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-briefcase"></i>
</div>

<div>

<div class="stat-label">
Company Holidays
</div>

<div class="stat-value">
<?php echo (int)($stats['Company'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic purple">
<i class="bi bi-calendar-check"></i>
</div>

<div>

<div class="stat-label">
Optional Holidays
</div>

<div class="stat-value">
<?php echo (int)($stats['Optional'] ?? 0); ?>
</div>

</div>

</div>

</div>

</div>

<!-- UPCOMING HOLIDAYS -->

<?php if (!empty($upcoming)): ?>

<div class="upcoming-strip">

<div class="upcoming-title">

<i class="bi bi-calendar-event"></i>

Upcoming Holidays - Next 30 Days

</div>

<div class="upcoming-grid">

<?php foreach ($upcoming as $up): ?>

<div class="upcoming-item">

<div class="upcoming-name">
<?php echo e($up['holiday_name']); ?>
</div>

<div class="upcoming-date">

<i class="bi bi-calendar3 me-1"></i>

<?php echo e(safeDate($up['holiday_date'])); ?>

•
<?php echo e($up['holiday_type'] ?? 'Public'); ?>

</div>

</div>

<?php endforeach; ?>

</div>

</div>

<?php endif; ?>

<!-- PANEL -->

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Holidays -
<?php echo (int)$filter_year; ?>
</h3>

<div class="panel-subtitle">
Compact responsive holiday directory
</div>

</div>

</div>

<!-- FILTER BAR -->

<div class="filter-bar">

<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="holidaySearch"
placeholder="Search holiday name, date, day, type or description..."
>

</div>

<div class="d-flex gap-2 flex-wrap">

<select
class="filter-select"
id="yearFilter"
onchange="window.location.href='?year=' + this.value;"
>

<?php foreach ($years as $year): ?>

<option
value="<?php echo (int)$year; ?>"
<?php echo ((int)$filter_year === (int)$year) ? 'selected' : ''; ?>
>
<?php echo (int)$year; ?>
</option>

<?php endforeach; ?>

</select>

<select
class="filter-select"
id="typeFilter"
>

<option value="">
All Types
</option>

<option value="public">
Public
</option>

<option value="company">
Company
</option>

<option value="optional">
Optional
</option>

</select>

</div>

</div>

<!-- TABLE -->

<div class="compact-table-wrap">

<table
class="table compact-table align-middle"
id="holidaysTable"
>

<thead>

<tr>

<th>Holiday</th>
<th>Date</th>
<th>Day</th>
<th>Type</th>
<th>Description</th>
<th class="text-end">Actions</th>

</tr>

</thead>

<tbody>

<?php if (empty($holidays)): ?>

<tr class="no-record-row">

<td colspan="6">

<div class="empty-state">

<i class="bi bi-calendar-x me-1"></i>
No holidays found for this year.

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($holidays as $holiday): ?>

<?php

$date_ts =
    strtotime($holiday['holiday_date']);

$day_name =
    $date_ts ? date('l', $date_ts) : '—';

[$typeLabel, $typeClass, $typeKey] =
    holidayTypeBadge($holiday['holiday_type'] ?? 'Public');

$holidayJson =
    htmlspecialchars(
        json_encode($holiday),
        ENT_QUOTES,
        'UTF-8'
    );

?>

<tr
data-type="<?php echo e($typeKey); ?>"
>

<!-- HOLIDAY -->

<td data-label="Holiday">

<div class="table-title-cell">

<div class="table-icon">
<i class="bi bi-calendar-event"></i>
</div>

<div>

<div class="table-primary-text">
<?php echo e($holiday['holiday_name']); ?>
</div>

<div class="table-secondary-text">

ID:
<?php echo (int)$holiday['id']; ?>

<?php if (!empty($holiday['created_at'])): ?>

•
Created:
<?php echo e(safeDate($holiday['created_at'])); ?>

<?php endif; ?>

</div>

</div>

</div>

</td>

<!-- DATE -->

<td data-label="Date">

<div class="table-primary-text">

<?php echo e(safeDate($holiday['holiday_date'])); ?>

</div>

<div class="table-secondary-text">

<?php echo $date_ts ? e(date('Y-m-d', $date_ts)) : '—'; ?>

</div>

</td>

<!-- DAY -->

<td data-label="Day">

<div class="table-primary-text">
<?php echo e($day_name); ?>
</div>

</td>

<!-- TYPE -->

<td data-label="Type">

<span class="badge-pill <?php echo e($typeClass); ?>">

<span class="mini-dot"></span>

<?php echo e($typeLabel); ?>

</span>

</td>

<!-- DESCRIPTION -->

<td data-label="Description">

<div class="table-secondary-text">

<?php

$desc =
    trim((string)($holiday['description'] ?? ''));

echo $desc !== ''
    ? e(strlen($desc) > 90 ? substr($desc, 0, 90) . '...' : $desc)
    : '—';

?>

</div>

</td>

<!-- ACTIONS -->

<td data-label="Actions">

<div class="action-group">

<button
type="button"
class="action-btn edit-btn"
onclick="editHoliday(<?php echo $holidayJson; ?>)"
data-bs-toggle="modal"
data-bs-target="#editHolidayModal"
title="Edit"
>
<i class="bi bi-pencil-square"></i>
</button>

<form
method="POST"
style="display:inline;"
onsubmit="return confirm('Are you sure you want to delete this holiday?');"
>

<input
type="hidden"
name="delete_id"
value="<?php echo (int)$holiday['id']; ?>"
>

<button
type="submit"
class="action-btn delete-btn"
title="Delete"
>
<i class="bi bi-trash"></i>
</button>

</form>

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
<?php echo count($holidays); ?>
holiday records

</div>

</div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<!-- ADD HOLIDAY MODAL -->

<div
class="modal fade"
id="addHolidayModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">

<h5 class="modal-title fw-bold">
Add New Holiday
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<form method="POST">

<input
type="hidden"
name="action"
value="add"
>

<div class="modal-body">

<div class="row g-3">

<div class="col-12">

<label class="form-label">
Holiday Name
<span class="text-danger">*</span>
</label>

<input
type="text"
class="form-control"
name="holiday_name"
required
placeholder="Example: Diwali, Christmas, Independence Day"
>

</div>

<div class="col-12">

<label class="form-label">
Date
<span class="text-danger">*</span>
</label>

<input
type="date"
class="form-control"
name="holiday_date"
required
>

</div>

<div class="col-12">

<label class="form-label">
Holiday Type
</label>

<select
class="form-select"
name="holiday_type"
>

<option value="Public">
Public Holiday
</option>

<option value="Company">
Company Holiday
</option>

<option value="Optional">
Optional Holiday
</option>

</select>

</div>

<div class="col-12">

<label class="form-label">
Description
<span class="text-muted">(Optional)</span>
</label>

<textarea
class="form-control"
name="description"
rows="2"
placeholder="Additional notes about this holiday"
></textarea>

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
class="btn btn-dark"
>
<i class="bi bi-calendar-plus me-1"></i>
Add Holiday
</button>

</div>

</form>

</div>

</div>

</div>

<!-- EDIT HOLIDAY MODAL -->

<div
class="modal fade"
id="editHolidayModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">

<h5 class="modal-title fw-bold">
Edit Holiday
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<form method="POST">

<input
type="hidden"
name="action"
value="edit"
>

<input
type="hidden"
name="holiday_id"
id="edit_holiday_id"
>

<div class="modal-body">

<div class="row g-3">

<div class="col-12">

<label class="form-label">
Holiday Name
<span class="text-danger">*</span>
</label>

<input
type="text"
class="form-control"
name="holiday_name"
id="edit_holiday_name"
required
>

</div>

<div class="col-12">

<label class="form-label">
Date
<span class="text-danger">*</span>
</label>

<input
type="date"
class="form-control"
name="holiday_date"
id="edit_holiday_date"
required
>

</div>

<div class="col-12">

<label class="form-label">
Holiday Type
</label>

<select
class="form-select"
name="holiday_type"
id="edit_holiday_type"
>

<option value="Public">
Public Holiday
</option>

<option value="Company">
Company Holiday
</option>

<option value="Optional">
Optional Holiday
</option>

</select>

</div>

<div class="col-12">

<label class="form-label">
Description
</label>

<textarea
class="form-control"
name="description"
id="edit_description"
rows="2"
></textarea>

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
class="btn btn-dark"
>
<i class="bi bi-save me-1"></i>
Update Holiday
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
Export Holidays
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
This exports the currently displayed holiday rows.

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

    const searchInput =
        document.getElementById('holidaySearch');

    const typeFilter =
        document.getElementById('typeFilter');

    const tableRows =
        document.querySelectorAll('#holidaysTable tbody tr:not(.no-record-row)');

    const recordInfo =
        document.getElementById('recordInfo');

    function filterHolidays(){

        const searchValue =
            searchInput.value.toLowerCase().trim();

        const typeValue =
            typeFilter.value.toLowerCase().trim();

        let visibleCount =
            0;

        tableRows.forEach(function(row){

            const rowText =
                row.innerText.toLowerCase();

            const rowType =
                row.getAttribute('data-type') || '';

            const matchesSearch =
                rowText.includes(searchValue);

            const matchesType =
                !typeValue ||
                rowType === typeValue;

            const visible =
                matchesSearch &&
                matchesType;

            row.style.display =
                visible
                ? ''
                : 'none';

            if (visible) {
                visibleCount++;
            }
        });

        if (recordInfo) {
            recordInfo.textContent =
                'Showing ' + visibleCount + ' holiday records';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterHolidays);
    }

    if (typeFilter) {
        typeFilter.addEventListener('change', filterHolidays);
    }
});

function editHoliday(holiday){

    document.getElementById('edit_holiday_id').value =
        holiday.id || '';

    document.getElementById('edit_holiday_name').value =
        holiday.holiday_name || '';

    document.getElementById('edit_holiday_date').value =
        holiday.holiday_date || '';

    document.getElementById('edit_holiday_type').value =
        holiday.holiday_type || 'Public';

    document.getElementById('edit_description').value =
        holiday.description || '';
}

function exportToCSV(){

    const rows =
        document.querySelectorAll('#holidaysTable tbody tr:not(.no-record-row)');

    const csv =
        [];

    const headers = [
        'Holiday',
        'Date',
        'Day',
        'Type',
        'Description'
    ];

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

        const holiday =
            cells[0]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const date =
            cells[1]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const day =
            cells[2]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const type =
            cells[3]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const description =
            cells[4]?.innerText.replace(/\s+/g, ' ').trim() || '';

        const rowData = [
            holiday,
            date,
            day,
            type,
            description
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
        'holidays_<?php echo (int)$filter_year; ?>_<?php echo date('Y-m-d'); ?>.csv';

    document.body.appendChild(a);

    a.click();

    document.body.removeChild(a);

    window.URL.revokeObjectURL(url);
}

</script>

</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>