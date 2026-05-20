<?php
// attendance-regulations.php
// TEK-C table section page reference UI style
// Fixed: safe activity logging without activity_logs user_id fatal error

session_start();

require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- AUTH / CURRENT USER ---------------- */

$current_employee_id = (int)($_SESSION['employee_id'] ?? 1);
$current_employee_name = $_SESSION['employee_name'] ?? 'Admin';

$employee = null;

$emp_stmt = mysqli_prepare(
    $conn,
    "SELECT id, full_name, designation, department
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

$current_designation = trim((string)($employee['designation'] ?? ''));
$current_department = trim((string)($employee['department'] ?? ''));

$is_admin = in_array(
    $current_designation,
    ['Director', 'Manager', 'HR', 'Administrator', 'Admin'],
    true
) || strtolower($current_department) === 'hr';

/* ---------------- HELPERS ---------------- */

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($date, $dash = '—') {
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return $dash;
    }

    $ts = strtotime($date);

    return $ts ? date('d M Y', $ts) : e($date);
}

function safeTime($time, $dash = '—') {
    $time = trim((string)$time);

    if ($time === '') {
        return $dash;
    }

    $ts = strtotime($time);

    return $ts ? date('h:i A', $ts) : e($time);
}

function yesNoBadge($value, $label) {
    if ((int)$value === 1) {
        return '<span class="badge-pill ontrack"><span class="mini-dot"></span>' . e($label) . '</span>';
    }

    return '';
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

/* ---------------- DELETE ACTION ---------------- */

if (isset($_GET['delete']) && $is_admin) {

    $delete_id = (int)$_GET['delete'];

    $get_stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM attendance_regulations
         WHERE id = ?
         LIMIT 1"
    );

    $reg_to_delete = null;

    if ($get_stmt) {
        mysqli_stmt_bind_param($get_stmt, "i", $delete_id);
        mysqli_stmt_execute($get_stmt);

        $get_result = mysqli_stmt_get_result($get_stmt);
        $reg_to_delete = mysqli_fetch_assoc($get_result);

        mysqli_stmt_close($get_stmt);
    }

    if ($reg_to_delete) {

        $delete_stmt = mysqli_prepare(
            $conn,
            "DELETE FROM attendance_regulations
             WHERE id = ?
             LIMIT 1"
        );

        if ($delete_stmt) {
            mysqli_stmt_bind_param($delete_stmt, "i", $delete_id);

            if (mysqli_stmt_execute($delete_stmt)) {

                safeActivityLog(
                    $conn,
                    'DELETE',
                    'attendance_regulations',
                    'Permanently deleted attendance regulation: ' . ($reg_to_delete['regulation_name'] ?? $delete_id),
                    $delete_id,
                    $reg_to_delete['regulation_name'] ?? null,
                    json_encode($reg_to_delete),
                    null
                );

                $_SESSION['flash_success'] = "Regulation permanently deleted successfully!";

            } else {
                $_SESSION['flash_error'] = "Failed to delete regulation: " . mysqli_stmt_error($delete_stmt);
            }

            mysqli_stmt_close($delete_stmt);

        } else {
            $_SESSION['flash_error'] = "Database error: " . mysqli_error($conn);
        }

    } else {
        $_SESSION['flash_error'] = "Regulation not found.";
    }

    header("Location: attendance-regulations.php");
    exit;
}

/* ---------------- FETCH REGULATIONS ---------------- */

$regulations = [];

$reg_query = "
    SELECT *
    FROM attendance_regulations
    ORDER BY effective_from DESC, id DESC
";

$reg_result = mysqli_query($conn, $reg_query);

if ($reg_result) {
    $regulations = mysqli_fetch_all($reg_result, MYSQLI_ASSOC);
}

/* ---------------- STATS ---------------- */

$total_regulations = count($regulations);
$active_count = 0;
$inactive_count = 0;
$office_count = 0;
$site_count = 0;
$currently_effective = 0;

$today = date('Y-m-d');

foreach ($regulations as $reg) {

    if (!empty($reg['is_active'])) {
        $active_count++;
    } else {
        $inactive_count++;
    }

    if (!empty($reg['allow_office_punch'])) {
        $office_count++;
    }

    if (!empty($reg['allow_site_punch'])) {
        $site_count++;
    }

    $from = trim((string)($reg['effective_from'] ?? ''));
    $to = trim((string)($reg['effective_to'] ?? ''));

    if (
        !empty($reg['is_active']) &&
        ($from === '' || $from <= $today) &&
        ($to === '' || $to >= $today)
    ) {
        $currently_effective++;
    }
}

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';

unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$loggedName = $_SESSION['employee_name'] ?? $current_employee_name;

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8" />

<meta
name="viewport"
content="width=device-width, initial-scale=1"
/>

<title>Attendance Regulations - TEK-C</title>

<link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
<link rel="manifest" href="assets/fav/site.webmanifest">

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

.regulation-wrapper{
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

.create-btn{
    background:#2f80ed;
}

.create-btn:hover{
    background:#2563eb;
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
.purple{ background:#8e44ad; }
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
.primary-soft{ color:#1d4ed8; background:#dbeafe; }
.purple-soft{ color:#7e22ce; background:#f3e8ff; }

.punch-chip-wrap{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}

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

.edit-btn{
    color:#2563eb;
    background:#eff6ff;
}

.toggle-btn{
    color:#7c3aed;
    background:#f3e8ff;
}

.delete-btn{
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
}

.info-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:14px;
    height:100%;
}

.info-title{
    color:#111827;
    font-size:14px;
    font-weight:900;
    margin:0 0 10px;
}

.info-list{
    list-style:none;
    padding:0;
    margin:0;
}

.info-list li{
    display:flex;
    gap:8px;
    align-items:flex-start;
    padding:6px 0;
    color:#475569;
    font-size:12px;
    font-weight:700;
    border-bottom:1px solid #f1f5f9;
}

.info-list li:last-child{
    border-bottom:0;
}

.info-list i{
    margin-top:1px;
}

.modal-content{
    border:0;
    border-radius:var(--radius);
    box-shadow:var(--shadow);
}

.modal-title{
    font-size:16px;
    font-weight:900;
}

.warning-text{
    color:#dc3545;
    font-weight:900;
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
        padding:7px 0;
    }

    .compact-table tbody td::before{
        content:attr(data-label);
        font-size:10px;
        font-weight:900;
        color:#64748b;
        text-transform:uppercase;
        flex:0 0 110px;
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

    .panel{
        padding:12px!important;
        border-radius:14px;
    }

    .filter-bar{
        align-items:stretch;
        flex-direction:column;
    }

    .search-box{
        max-width:none;
        width:100%;
    }

    .filter-select{
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

<div class="container-fluid regulation-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Attendance Regulations
</h1>

<p>
Manage work timing policies, punch permissions and attendance rules
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<?php if ($is_admin): ?>

<a
href="add-regulation.php"
class="primary-btn create-btn"
>
<i class="bi bi-plus-lg"></i>
Add Regulation
</a>

<?php endif; ?>

<a
href="employee-regulations.php"
class="primary-btn back-btn"
>
<i class="bi bi-person-check"></i>
Employee Exceptions
</a>

<a
href="punchin.php"
class="primary-btn back-btn"
>
<i class="bi bi-arrow-left"></i>
Back to Punch
</a>

<button
type="button"
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

<?php if ($flash_success): ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">

<i class="bi bi-check-circle-fill me-2"></i>

<?php echo e($flash_success); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<?php if ($flash_error): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<?php echo e($flash_error); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<!-- STATS -->

<div class="row g-3 mb-3">

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-list-check"></i>
</div>

<div>

<div class="stat-label">
Total
</div>

<div class="stat-value">
<?php echo (int)$total_regulations; ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic green">
<i class="bi bi-check-circle"></i>
</div>

<div>

<div class="stat-label">
Active
</div>

<div class="stat-value">
<?php echo (int)$active_count; ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-calendar-check"></i>
</div>

<div>

<div class="stat-label">
Effective Now
</div>

<div class="stat-value">
<?php echo (int)$currently_effective; ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic purple">
<i class="bi bi-building"></i>
</div>

<div>

<div class="stat-label">
Office Punch
</div>

<div class="stat-value">
<?php echo (int)$office_count; ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-geo-alt"></i>
</div>

<div>

<div class="stat-label">
Site Punch
</div>

<div class="stat-value">
<?php echo (int)$site_count; ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic red">
<i class="bi bi-x-circle"></i>
</div>

<div>

<div class="stat-label">
Inactive
</div>

<div class="stat-value">
<?php echo (int)$inactive_count; ?>
</div>

</div>

</div>

</div>

</div>

<!-- TABLE PANEL -->

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Attendance Regulations
</h3>

<div class="panel-subtitle">
Compact responsive attendance policy directory
</div>

</div>

<span class="badge bg-secondary">
<?php echo count($regulations); ?>
records
</span>

</div>

<div class="filter-bar">

<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="quickSearch"
placeholder="Search regulation name, applicable type, punch type..."
>

</div>

<select
class="filter-select"
id="statusFilter"
>

<option value="all">
All Status
</option>

<option value="active">
Active
</option>

<option value="inactive">
Inactive
</option>

</select>

</div>

<div class="compact-table-wrap">

<table
id="regulationsTable"
class="table compact-table align-middle"
>

<thead>

<tr>
<th>Regulation</th>
<th>Applicable To</th>
<th>Work Hours</th>
<th>Grace</th>
<th>Min Hours</th>
<th>Punch Types</th>
<th>Effective</th>
<th>Status</th>
<?php if ($is_admin): ?>
<th class="text-end">Actions</th>
<?php endif; ?>
</tr>

</thead>

<tbody>

<?php if (empty($regulations)): ?>

<tr class="no-record-row">

<td colspan="<?php echo $is_admin ? '9' : '8'; ?>">

<div class="empty-state">

<i class="bi bi-inbox me-1"></i>
No attendance regulations found.

<?php if ($is_admin): ?>

<a
href="add-regulation.php"
class="text-primary text-decoration-none fw-bold ms-1"
>
Add your first regulation
</a>

<?php endif; ?>

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($regulations as $reg): ?>

<?php
$isActive = !empty($reg['is_active']);
$rowStatus = $isActive ? 'active' : 'inactive';

$from = trim((string)($reg['effective_from'] ?? ''));
$to = trim((string)($reg['effective_to'] ?? ''));

$isCurrentlyEffective =
    $isActive &&
    ($from === '' || $from <= $today) &&
    ($to === '' || $to >= $today);
?>

<tr data-status="<?php echo e($rowStatus); ?>">

<td data-label="Regulation">

<div class="table-primary-text">
<?php echo e($reg['regulation_name'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<i class="bi bi-hash"></i>
ID:
<?php echo (int)$reg['id']; ?>
</div>

<?php if ($isCurrentlyEffective): ?>

<div class="table-secondary-text text-success">
<i class="bi bi-check-circle me-1"></i>
Currently effective
</div>

<?php endif; ?>

</td>

<td data-label="Applicable To">

<span class="badge-pill info">

<span class="mini-dot"></span>

<?php echo e($reg['applicable_to'] ?? ''); ?>

</span>

</td>

<td data-label="Work Hours">

<div class="table-primary-text">
<i class="bi bi-clock me-1"></i>
<?php echo safeTime($reg['work_start_time'] ?? ''); ?>
-
<?php echo safeTime($reg['work_end_time'] ?? ''); ?>
</div>

<div class="table-secondary-text">
Standard work window
</div>

</td>

<td data-label="Grace">

<div class="table-primary-text">
<?php echo (int)($reg['grace_period_minutes'] ?? 0); ?>
min
</div>

<div class="table-secondary-text">
Late grace period
</div>

</td>

<td data-label="Min Hours">

<div class="table-primary-text">
Full:
<?php echo e($reg['min_work_hours_full_day'] ?? '0'); ?>h
</div>

<div class="table-secondary-text">
Half:
<?php echo e($reg['min_work_hours_half_day'] ?? '0'); ?>h
</div>

</td>

<td data-label="Punch Types">

<div class="punch-chip-wrap">

<?php if (!empty($reg['allow_office_punch'])): ?>

<span class="badge-pill primary-soft">
<span class="mini-dot"></span>
Office
</span>

<?php endif; ?>

<?php if (!empty($reg['allow_site_punch'])): ?>

<span class="badge-pill ontrack">
<span class="mini-dot"></span>
Site
</span>

<?php endif; ?>

<?php if (empty($reg['allow_office_punch']) && empty($reg['allow_site_punch'])): ?>

<span class="badge-pill muted">
<span class="mini-dot"></span>
None
</span>

<?php endif; ?>

</div>

</td>

<td data-label="Effective">

<div class="table-primary-text">
From:
<?php echo safeDate($reg['effective_from'] ?? ''); ?>
</div>

<div class="table-secondary-text">

<?php if (!empty($reg['effective_to'])): ?>

To:
<?php echo safeDate($reg['effective_to']); ?>

<?php else: ?>

No end date

<?php endif; ?>

</div>

</td>

<td data-label="Status">

<?php if ($isActive): ?>

<span class="badge-pill ontrack">
<span class="mini-dot"></span>
Active
</span>

<?php else: ?>

<span class="badge-pill danger">
<span class="mini-dot"></span>
Inactive
</span>

<?php endif; ?>

</td>

<?php if ($is_admin): ?>

<td data-label="Actions">

<div class="action-group">

<a
href="edit-regulation.php?id=<?php echo (int)$reg['id']; ?>"
class="action-btn edit-btn"
title="Edit"
>
<i class="bi bi-pencil"></i>
</a>

<a
href="toggle-regulation.php?id=<?php echo (int)$reg['id']; ?>"
class="action-btn toggle-btn"
title="<?php echo $isActive ? 'Deactivate' : 'Activate'; ?>"
>
<i class="bi bi-<?php echo $isActive ? 'pause-circle' : 'play-circle'; ?>"></i>
</a>

<button
type="button"
class="action-btn delete-btn"
title="Permanently Delete"
onclick="confirmDelete(<?php echo (int)$reg['id']; ?>, '<?php echo e(addslashes($reg['regulation_name'] ?? '')); ?>')"
>
<i class="bi bi-trash"></i>
</button>

</div>

</td>

<?php endif; ?>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

<div class="table-secondary-text mt-2" id="recordInfo">
Showing
<?php echo count($regulations); ?>
attendance regulation records
</div>

</div>

<!-- INFO PANELS -->

<div class="row g-3 mt-1">

<div class="col-md-6">

<div class="info-card">

<h4 class="info-title">
How Regulations Work
</h4>

<ul class="info-list">

<li>
<i class="bi bi-clock text-primary"></i>
<div>
<strong>Work Hours:</strong>
Define standard working hours for different employee types.
</div>
</li>

<li>
<i class="bi bi-alarm text-warning"></i>
<div>
<strong>Grace Period:</strong>
Employees can punch in late within this window without penalty.
</div>
</li>

<li>
<i class="bi bi-hourglass-split text-info"></i>
<div>
<strong>Half Day:</strong>
If employee works less than full day but more than half-day minimum.
</div>
</li>

<li>
<i class="bi bi-building text-success"></i>
<div>
<strong>Punch Types:</strong>
Restrict whether employees can punch from office or site.
</div>
</li>

</ul>

</div>

</div>

<div class="col-md-6">

<div class="info-card">

<h4 class="info-title">
Current Default Rules
</h4>

<ul class="info-list">

<li>
<i class="bi bi-clock text-success"></i>
<div>
<strong>Standard Work Day:</strong>
9:00 AM - 6:00 PM
</div>
</li>

<li>
<i class="bi bi-alarm text-warning"></i>
<div>
<strong>Grace Period:</strong>
15 minutes
</div>
</li>

<li>
<i class="bi bi-hourglass-split text-info"></i>
<div>
<strong>Half Day:</strong>
Minimum 4 hours
</div>
</li>

<li>
<i class="bi bi-building text-primary"></i>
<div>
<strong>Office/Site Punch:</strong>
Allowed for designated employees.
</div>
</li>

</ul>

</div>

</div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<!-- DELETE CONFIRMATION MODAL -->

<div
class="modal fade"
id="deleteModal"
tabindex="-1"
aria-labelledby="deleteModalLabel"
aria-hidden="true"
>

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content">

<div class="modal-header">

<h5
class="modal-title"
id="deleteModalLabel"
>
Confirm Permanent Delete
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
aria-label="Close"
></button>

</div>

<div class="modal-body">

<p class="mb-0">
Are you sure you want to
<span class="warning-text">
permanently delete
</span>
this regulation?
</p>

<p
class="fw-bold text-center my-3"
id="deleteRegulationName"
></p>

<div class="alert alert-danger mb-0" style="box-shadow:none;">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<strong>Warning:</strong>
This action cannot be undone. The regulation will be permanently removed from the database.

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

<a
href="#"
id="confirmDeleteBtn"
class="btn btn-danger"
>
Permanently Delete
</a>

</div>

</div>

</div>

</div>

<!-- EXPORT MODAL -->

<div
class="modal fade"
id="exportModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">

<h5 class="modal-title">
Export Attendance Regulations
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
This exports currently visible rows from the table.

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
Export CSV
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

    const statusFilter =
        document.getElementById('statusFilter');

    const rows =
        document.querySelectorAll('#regulationsTable tbody tr:not(.no-record-row)');

    const recordInfo =
        document.getElementById('recordInfo');

    function filterRows(){

        const searchValue =
            quickSearch.value.toLowerCase().trim();

        const statusValue =
            statusFilter.value;

        let visible =
            0;

        rows.forEach(function(row){

            const textMatch =
                row.innerText.toLowerCase().includes(searchValue);

            const rowStatus =
                row.getAttribute('data-status') || '';

            const statusMatch =
                statusValue === 'all' ||
                rowStatus === statusValue;

            const show =
                textMatch && statusMatch;

            row.style.display =
                show
                ? ''
                : 'none';

            if (show) {
                visible++;
            }
        });

        if (recordInfo) {
            recordInfo.textContent =
                'Showing ' + visible + ' attendance regulation records';
        }
    }

    if (quickSearch) {
        quickSearch.addEventListener('input', filterRows);
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', filterRows);
    }

    setTimeout(function(){
        document.querySelectorAll('.alert-dismissible').forEach(function(alertEl){
            try {
                const instance =
                    bootstrap.Alert.getOrCreateInstance(alertEl);

                instance.close();
            } catch (e) {}
        });
    }, 5000);
});

function confirmDelete(id, name){

    document.getElementById('deleteRegulationName').textContent =
        name;

    document.getElementById('confirmDeleteBtn').href =
        '?delete=' + encodeURIComponent(id);

    new bootstrap.Modal(
        document.getElementById('deleteModal')
    ).show();
}

function exportToCSV(){

    const table =
        document.getElementById('regulationsTable');

    if (!table) {
        return;
    }

    const rows =
        table.querySelectorAll('tbody tr:not(.no-record-row)');

    const headers =
        Array.from(table.querySelectorAll('thead th')).map(function(th){
            return th.innerText.replace(/\s+/g, ' ').trim();
        });

    const csv =
        [];

    csv.push(headers.join(','));

    rows.forEach(function(row){

        if (row.style.display === 'none') {
            return;
        }

        const cells =
            row.querySelectorAll('td');

        const rowData =
            Array.from(cells).map(function(td){
                const value =
                    td.innerText.replace(/\s+/g, ' ').trim();

                return '"' + value.replace(/"/g, '""') + '"';
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
        'attendance_regulations_<?php echo date('Y-m-d'); ?>.csv';

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