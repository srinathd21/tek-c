<?php
// add-regulation.php
// TEK-C add/edit/delete page reference UI style
// Fixed safe activity logging

session_start();

require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- CURRENT USER / AUTH ---------------- */

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

if (!$is_admin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: attendance-regulations.php");
    exit;
}

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

function checkedInput($name, $default = false) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return isset($_POST[$name]) ? 'checked' : '';
    }

    return $default ? 'checked' : '';
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

/* ---------------- OPTIONS ---------------- */

$applicable_options = [
    'All' => 'All Employees',
    'Site Employees' => 'Site Employees',
    'Office Employees' => 'Office Employees',
    'Managers' => 'Managers',
    'Team Leads' => 'Team Leads'
];

/* ---------------- FORM SUBMIT ---------------- */

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $regulation_name = trim((string)($_POST['regulation_name'] ?? ''));
    $applicable_to = trim((string)($_POST['applicable_to'] ?? ''));
    $work_start_time = trim((string)($_POST['work_start_time'] ?? ''));
    $work_end_time = trim((string)($_POST['work_end_time'] ?? ''));
    $grace_period_minutes = (int)($_POST['grace_period_minutes'] ?? 0);

    $half_day_cutoff_time =
        trim((string)($_POST['half_day_cutoff_time'] ?? '')) !== ''
        ? trim((string)$_POST['half_day_cutoff_time'])
        : null;

    $late_cutoff_time =
        trim((string)($_POST['late_cutoff_time'] ?? '')) !== ''
        ? trim((string)$_POST['late_cutoff_time'])
        : null;

    $overtime_allowed = isset($_POST['overtime_allowed']) ? 1 : 0;

    $min_work_hours_full_day = (float)($_POST['min_work_hours_full_day'] ?? 8);
    $min_work_hours_half_day = (float)($_POST['min_work_hours_half_day'] ?? 4);

    $allow_office_punch = isset($_POST['allow_office_punch']) ? 1 : 0;
    $allow_site_punch = isset($_POST['allow_site_punch']) ? 1 : 0;

    $requires_manager_approval_for_vacation =
        isset($_POST['requires_manager_approval_for_vacation'])
        ? 1
        : 0;

    $effective_from = trim((string)($_POST['effective_from'] ?? ''));

    $effective_to =
        trim((string)($_POST['effective_to'] ?? '')) !== ''
        ? trim((string)$_POST['effective_to'])
        : null;

    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($regulation_name === '') {
        $errors[] = "Regulation name is required";
    }

    if ($applicable_to === '' || !array_key_exists($applicable_to, $applicable_options)) {
        $errors[] = "Valid applicable type is required";
    }

    if ($work_start_time === '') {
        $errors[] = "Work start time is required";
    }

    if ($work_end_time === '') {
        $errors[] = "Work end time is required";
    }

    if ($work_start_time !== '' && $work_end_time !== '' && strtotime($work_end_time) <= strtotime($work_start_time)) {
        $errors[] = "Work end time must be after work start time";
    }

    if ($grace_period_minutes < 0 || $grace_period_minutes > 120) {
        $errors[] = "Grace period must be between 0 and 120 minutes";
    }

    if ($min_work_hours_full_day <= 0) {
        $errors[] = "Full day minimum hours must be greater than 0";
    }

    if ($min_work_hours_half_day <= 0) {
        $errors[] = "Half day minimum hours must be greater than 0";
    }

    if ($min_work_hours_half_day > $min_work_hours_full_day) {
        $errors[] = "Half day minimum cannot be greater than full day minimum";
    }

    if ($effective_from === '') {
        $errors[] = "Effective from date is required";
    }

    if ($effective_from !== '' && $effective_to !== null && strtotime($effective_to) < strtotime($effective_from)) {
        $errors[] = "Effective to date cannot be before effective from date";
    }

    if ($allow_office_punch === 0 && $allow_site_punch === 0) {
        $errors[] = "Please allow at least one punch type: Office or Site";
    }

    if (empty($errors)) {

        $insert_query = "
            INSERT INTO attendance_regulations
            (
                regulation_name,
                applicable_to,
                work_start_time,
                work_end_time,
                grace_period_minutes,
                half_day_cutoff_time,
                late_cutoff_time,
                overtime_allowed,
                min_work_hours_full_day,
                min_work_hours_half_day,
                allow_office_punch,
                allow_site_punch,
                requires_manager_approval_for_vacation,
                effective_from,
                effective_to,
                is_active
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ";

        $stmt = mysqli_prepare($conn, $insert_query);

        if (!$stmt) {

            $errors[] = "Database error: " . mysqli_error($conn);

        } else {

            mysqli_stmt_bind_param(
                $stmt,
                "ssssissiddiiissi",
                $regulation_name,
                $applicable_to,
                $work_start_time,
                $work_end_time,
                $grace_period_minutes,
                $half_day_cutoff_time,
                $late_cutoff_time,
                $overtime_allowed,
                $min_work_hours_full_day,
                $min_work_hours_half_day,
                $allow_office_punch,
                $allow_site_punch,
                $requires_manager_approval_for_vacation,
                $effective_from,
                $effective_to,
                $is_active
            );

            if (mysqli_stmt_execute($stmt)) {

                $new_id = mysqli_insert_id($conn);

                safeActivityLog(
                    $conn,
                    'CREATE',
                    'attendance_regulations',
                    'Created new attendance regulation: ' . $regulation_name,
                    $new_id,
                    $regulation_name,
                    null,
                    json_encode([
                        'regulation_name' => $regulation_name,
                        'applicable_to' => $applicable_to,
                        'work_start_time' => $work_start_time,
                        'work_end_time' => $work_end_time,
                        'grace_period_minutes' => $grace_period_minutes,
                        'half_day_cutoff_time' => $half_day_cutoff_time,
                        'late_cutoff_time' => $late_cutoff_time,
                        'overtime_allowed' => $overtime_allowed,
                        'min_work_hours_full_day' => $min_work_hours_full_day,
                        'min_work_hours_half_day' => $min_work_hours_half_day,
                        'allow_office_punch' => $allow_office_punch,
                        'allow_site_punch' => $allow_site_punch,
                        'requires_manager_approval_for_vacation' => $requires_manager_approval_for_vacation,
                        'effective_from' => $effective_from,
                        'effective_to' => $effective_to,
                        'is_active' => $is_active
                    ])
                );

                $_SESSION['flash_success'] = "Regulation added successfully!";
                header("Location: attendance-regulations.php");
                exit;

            } else {
                $errors[] = "Database error: " . mysqli_stmt_error($stmt);
            }

            mysqli_stmt_close($stmt);
        }
    }
}

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';

unset($_SESSION['flash_success'], $_SESSION['flash_error']);

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8" />

<meta
name="viewport"
content="width=device-width, initial-scale=1"
/>

<title>Add Regulation - TEK-C</title>

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

.alert{
    border-radius:var(--radius);
    border:none;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    font-size:12px;
    font-weight:700;
}

.rule-preview{
    background:linear-gradient(135deg,#2563eb,#7c3aed);
    color:#fff;
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:16px;
    margin-bottom:14px;
}

.rule-preview-title{
    font-size:15px;
    font-weight:950;
    margin:0;
}

.rule-preview-subtitle{
    font-size:11.5px;
    font-weight:700;
    opacity:.9;
    margin-top:4px;
}

.preview-chip-row{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
    margin-top:12px;
}

.preview-chip{
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

.option-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:10px;
}

.option-card{
    border:1px solid var(--border);
    background:#f8fafc;
    border-radius:13px;
    padding:12px;
    min-height:78px;
    cursor:pointer;
    transition:.18s ease;
}

.option-card:hover{
    border-color:#2f80ed;
    background:#eff6ff;
}

.option-card .form-check{
    margin:0;
}

.option-card .form-check-input{
    margin-top:2px;
}

.option-title{
    color:#111827;
    font-size:12px;
    font-weight:950;
}

.option-desc{
    color:#64748b;
    font-size:10.5px;
    font-weight:700;
    margin-top:2px;
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

@media(max-width:992px){

    .option-grid{
        grid-template-columns:repeat(2, minmax(0, 1fr));
    }
}

@media(max-width:768px){

    .content-scroll{
        padding:12px 10px 12px!important;
    }

    .regulation-wrapper{
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

    .form-panel{
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

    .rule-preview{
        padding:14px;
        margin-bottom:12px;
    }

    .option-grid{
        grid-template-columns:1fr;
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

<div
id="contentScroll"
class="content-scroll"
>

<div class="container-fluid regulation-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Add Attendance Regulation
</h1>

<p>
Create a new work timing, punch permission and attendance policy rule
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="attendance-regulations.php"
class="primary-btn back-btn"
>
<i class="bi bi-arrow-left"></i>
Back
</a>

<a
href="attendance-regulations.php"
class="primary-btn list-btn"
>
<i class="bi bi-list-check"></i>
Regulations
</a>

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

<?php if (!empty($errors)): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<strong>Please fix the following errors:</strong>

<ul class="mb-0 mt-2 ps-3">

<?php foreach ($errors as $err): ?>

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

<!-- PREVIEW HEADER -->

<div class="rule-preview">

<h2 class="rule-preview-title">
New Regulation Setup
</h2>

<div class="rule-preview-subtitle">
Configure attendance timing, cutoff rules, punch permissions and validity period.
</div>

<div class="preview-chip-row">

<span class="preview-chip">
<i class="bi bi-clock"></i>
Default: 09:00 - 18:00
</span>

<span class="preview-chip">
<i class="bi bi-alarm"></i>
Grace: 15 minutes
</span>

<span class="preview-chip">
<i class="bi bi-building"></i>
Office / Site Punch
</span>

<span class="preview-chip">
<i class="bi bi-calendar-check"></i>
Effective From Today
</span>

</div>

</div>

<form
method="POST"
id="regulationForm"
novalidate
>

<!-- BASIC INFO -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon">
<i class="bi bi-info-circle"></i>
</div>

<div>

<h3 class="section-title">
Basic Information
</h3>

<p class="section-subtitle">
Name this policy and choose who it applies to
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-6">

<label
class="form-label required-label"
for="regulation_name"
>
Regulation Name
</label>

<input
type="text"
class="form-control"
id="regulation_name"
name="regulation_name"
required
value="<?php echo e(oldInput('regulation_name')); ?>"
placeholder="e.g., Office Staff Policy, Site Engineers Rules"
>

<div class="form-helper">
<i class="bi bi-info-circle"></i>
Use a clear name to identify this attendance policy.
</div>

</div>

<div class="col-md-6">

<label
class="form-label required-label"
for="applicable_to"
>
Applicable To
</label>

<select
class="form-select"
id="applicable_to"
name="applicable_to"
required
>

<option value="">
Select...
</option>

<?php foreach ($applicable_options as $value => $label): ?>

<option
value="<?php echo e($value); ?>"
<?php echo selected(oldInput('applicable_to'), $value); ?>
>
<?php echo e($label); ?>
</option>

<?php endforeach; ?>

</select>

</div>

</div>

</div>

<!-- WORKING HOURS -->

<div class="form-panel">

<div class="section-header">

<div
class="section-icon"
style="background:#2563eb;"
>
<i class="bi bi-clock"></i>
</div>

<div>

<h3 class="section-title">
Working Hours
</h3>

<p class="section-subtitle">
Define standard shift timing and grace period
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-3">

<label
class="form-label required-label"
for="work_start_time"
>
Start Time
</label>

<input
type="time"
class="form-control"
id="work_start_time"
name="work_start_time"
required
value="<?php echo e(oldInput('work_start_time', '09:00')); ?>"
>

</div>

<div class="col-md-3">

<label
class="form-label required-label"
for="work_end_time"
>
End Time
</label>

<input
type="time"
class="form-control"
id="work_end_time"
name="work_end_time"
required
value="<?php echo e(oldInput('work_end_time', '18:00')); ?>"
>

</div>

<div class="col-md-3">

<label
class="form-label"
for="grace_period_minutes"
>
Grace Period
</label>

<input
type="number"
class="form-control"
id="grace_period_minutes"
name="grace_period_minutes"
min="0"
max="120"
value="<?php echo e(oldInput('grace_period_minutes', '15')); ?>"
>

<div class="form-helper">
<i class="bi bi-alarm"></i>
Minutes allowed for late punch.
</div>

</div>

<div class="col-md-3">

<label class="form-label">
Overtime
</label>

<label class="option-card d-block">

<div class="form-check">

<input
class="form-check-input"
type="checkbox"
name="overtime_allowed"
id="overtime_allowed"
value="1"
<?php echo checkedInput('overtime_allowed', false); ?>
>

<label
class="form-check-label"
for="overtime_allowed"
>

<div class="option-title">
Allow Overtime
</div>

<div class="option-desc">
Enable overtime calculation.
</div>

</label>

</div>

</label>

</div>

<div class="col-md-4">

<label
class="form-label"
for="half_day_cutoff_time"
>
Half Day Cutoff
<span class="optional-badge">(Optional)</span>
</label>

<input
type="time"
class="form-control"
id="half_day_cutoff_time"
name="half_day_cutoff_time"
value="<?php echo e(oldInput('half_day_cutoff_time')); ?>"
>

<div class="form-helper">
<i class="bi bi-hourglass-split"></i>
Punch after this may count as half day.
</div>

</div>

<div class="col-md-4">

<label
class="form-label"
for="late_cutoff_time"
>
Late Cutoff
<span class="optional-badge">(Optional)</span>
</label>

<input
type="time"
class="form-control"
id="late_cutoff_time"
name="late_cutoff_time"
value="<?php echo e(oldInput('late_cutoff_time')); ?>"
>

<div class="form-helper">
<i class="bi bi-clock-history"></i>
Punch after this counts as late.
</div>

</div>

</div>

</div>

<!-- MINIMUM HOURS -->

<div class="form-panel">

<div class="section-header">

<div
class="section-icon"
style="background:#10b981;"
>
<i class="bi bi-hourglass"></i>
</div>

<div>

<h3 class="section-title">
Minimum Work Hours
</h3>

<p class="section-subtitle">
Set minimum required hours for full day and half day
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-4">

<label
class="form-label"
for="min_work_hours_full_day"
>
Full Day Minimum
</label>

<input
type="number"
step="0.5"
min="0"
class="form-control"
id="min_work_hours_full_day"
name="min_work_hours_full_day"
value="<?php echo e(oldInput('min_work_hours_full_day', '8.00')); ?>"
>

<div class="form-helper">
<i class="bi bi-calendar-check"></i>
Required hours for full day.
</div>

</div>

<div class="col-md-4">

<label
class="form-label"
for="min_work_hours_half_day"
>
Half Day Minimum
</label>

<input
type="number"
step="0.5"
min="0"
class="form-control"
id="min_work_hours_half_day"
name="min_work_hours_half_day"
value="<?php echo e(oldInput('min_work_hours_half_day', '4.00')); ?>"
>

<div class="form-helper">
<i class="bi bi-calendar-minus"></i>
Required hours for half day.
</div>

</div>

</div>

</div>

<!-- PUNCH SETTINGS -->

<div class="form-panel">

<div class="section-header">

<div
class="section-icon"
style="background:#7c3aed;"
>
<i class="bi bi-geo-alt"></i>
</div>

<div>

<h3 class="section-title">
Punch Settings
</h3>

<p class="section-subtitle">
Control where employees can punch attendance
</p>

</div>

</div>

<div class="option-grid">

<label class="option-card">

<div class="form-check">

<input
class="form-check-input"
type="checkbox"
name="allow_office_punch"
id="allow_office_punch"
value="1"
<?php echo checkedInput('allow_office_punch', true); ?>
>

<label
class="form-check-label"
for="allow_office_punch"
>

<div class="option-title">
Allow Office Punch
</div>

<div class="option-desc">
Employees can punch from office location.
</div>

</label>

</div>

</label>

<label class="option-card">

<div class="form-check">

<input
class="form-check-input"
type="checkbox"
name="allow_site_punch"
id="allow_site_punch"
value="1"
<?php echo checkedInput('allow_site_punch', true); ?>
>

<label
class="form-check-label"
for="allow_site_punch"
>

<div class="option-title">
Allow Site Punch
</div>

<div class="option-desc">
Employees can punch from assigned sites.
</div>

</label>

</div>

</label>

<label class="option-card">

<div class="form-check">

<input
class="form-check-input"
type="checkbox"
name="requires_manager_approval_for_vacation"
id="requires_manager_approval"
value="1"
<?php echo checkedInput('requires_manager_approval_for_vacation', true); ?>
>

<label
class="form-check-label"
for="requires_manager_approval"
>

<div class="option-title">
Vacation Approval
</div>

<div class="option-desc">
Require manager approval for vacation requests.
</div>

</label>

</div>

</label>

</div>

</div>

<!-- VALIDITY -->

<div class="form-panel">

<div class="section-header">

<div
class="section-icon"
style="background:#f59e0b;"
>
<i class="bi bi-calendar-event"></i>
</div>

<div>

<h3 class="section-title">
Validity Period
</h3>

<p class="section-subtitle">
Set when this attendance policy becomes active
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-4">

<label
class="form-label required-label"
for="effective_from"
>
Effective From
</label>

<input
type="date"
class="form-control"
id="effective_from"
name="effective_from"
required
value="<?php echo e(oldInput('effective_from', date('Y-m-d'))); ?>"
>

</div>

<div class="col-md-4">

<label
class="form-label"
for="effective_to"
>
Effective To
<span class="optional-badge">(Optional)</span>
</label>

<input
type="date"
class="form-control"
id="effective_to"
name="effective_to"
value="<?php echo e(oldInput('effective_to')); ?>"
>

<div class="form-helper">
<i class="bi bi-infinity"></i>
Leave blank for ongoing policy.
</div>

</div>

<div class="col-md-4">

<label class="form-label">
Status
</label>

<label class="option-card d-block">

<div class="form-check">

<input
class="form-check-input"
type="checkbox"
name="is_active"
id="is_active"
value="1"
<?php echo checkedInput('is_active', true); ?>
>

<label
class="form-check-label"
for="is_active"
>

<div class="option-title">
Active Immediately
</div>

<div class="option-desc">
Use this regulation after saving.
</div>

</label>

</div>

</label>

</div>

</div>

</div>

<!-- SUBMIT BAR -->

<div class="submit-bar">

<div class="submit-card">

<div class="submit-info">

<i class="bi bi-info-circle me-1"></i>
Review all attendance rule details before saving.

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="attendance-regulations.php"
class="cancel-btn"
>
<i class="bi bi-x-lg"></i>
Cancel
</a>

<button
type="submit"
class="submit-btn"
>
<i class="bi bi-save"></i>
Save Regulation
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

    const form =
        document.getElementById('regulationForm');

    function setInvalid(el){
        if (el) {
            el.classList.add('is-invalid');
        }
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

            const startTime =
                document.getElementById('work_start_time');

            const endTime =
                document.getElementById('work_end_time');

            if (
                startTime &&
                endTime &&
                startTime.value &&
                endTime.value &&
                endTime.value <= startTime.value
            ) {
                valid = false;
                setInvalid(startTime);
                setInvalid(endTime);
                errors.push('Work end time must be after work start time');
            }

            const fullDay =
                document.getElementById('min_work_hours_full_day');

            const halfDay =
                document.getElementById('min_work_hours_half_day');

            if (
                fullDay &&
                halfDay &&
                parseFloat(halfDay.value || 0) > parseFloat(fullDay.value || 0)
            ) {
                valid = false;
                setInvalid(fullDay);
                setInvalid(halfDay);
                errors.push('Half day minimum cannot be greater than full day minimum');
            }

            const officePunch =
                document.getElementById('allow_office_punch');

            const sitePunch =
                document.getElementById('allow_site_punch');

            if (
                officePunch &&
                sitePunch &&
                !officePunch.checked &&
                !sitePunch.checked
            ) {
                valid = false;
                errors.push('Please allow at least one punch type: Office or Site');
            }

            const effectiveFrom =
                document.getElementById('effective_from');

            const effectiveTo =
                document.getElementById('effective_to');

            if (
                effectiveFrom &&
                effectiveTo &&
                effectiveFrom.value &&
                effectiveTo.value &&
                effectiveTo.value < effectiveFrom.value
            ) {
                valid = false;
                setInvalid(effectiveFrom);
                setInvalid(effectiveTo);
                errors.push('Effective to date cannot be before effective from date');
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
                    document.querySelector('.regulation-wrapper');

                const preview =
                    document.querySelector('.rule-preview');

                if (container && preview) {
                    container.insertBefore(alertDiv, preview);
                }

                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
        });
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