<?php
session_start();
require_once 'includes/db-config.php';

$success = '';
$error = '';
$employees = [];

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- HELPERS ---------------- */

function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($v, $dash = 'Not Set'){

    $v = trim((string)$v);

    if ($v === '' || $v === '0000-00-00') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y', $ts) : e($v);
}

function statusBadge($status){

    $status = strtolower(trim((string)$status));

    if (!in_array($status, ['active', 'inactive', 'resigned'], true)) {
        $status = 'inactive';
    }

    $labels = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'resigned' => 'Resigned'
    ];

    $classes = [
        'active' => 'ontrack',
        'inactive' => 'pending',
        'resigned' => 'danger'
    ];

    return [
        $labels[$status],
        $classes[$status],
        $status
    ];
}

/**
 * Normalize employee file paths.
 *
 * Your employee files are expected from:
 * ../admin/uploads/...
 *
 * Handles old/new DB path styles:
 * - uploads/...
 * - /uploads/...
 * - admin/uploads/...
 * - /admin/uploads/...
 * - employees/photos/...
 * - employees/passbook/...
 */
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

/* ---------------- SOFT DELETE / MARK INACTIVE ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['delete_id'])) {

        $id = (int)($_POST['delete_id'] ?? 0);

        if ($id <= 0) {

            $error = "Invalid employee selected.";

        } else {

            $oldName = '';

            $fetchStmt = mysqli_prepare(
                $conn,
                "SELECT full_name FROM employees WHERE id = ? LIMIT 1"
            );

            if ($fetchStmt) {

                mysqli_stmt_bind_param($fetchStmt, "i", $id);
                mysqli_stmt_execute($fetchStmt);
                mysqli_stmt_bind_result($fetchStmt, $oldName);
                mysqli_stmt_fetch($fetchStmt);
                mysqli_stmt_close($fetchStmt);
            }

            $stmtD = mysqli_prepare(
                $conn,
                "UPDATE employees
                 SET employee_status = 'inactive'
                 WHERE id = ?
                 LIMIT 1"
            );

            if (!$stmtD) {

                $error =
                    "Database error: " .
                    mysqli_error($conn);

            } else {

                mysqli_stmt_bind_param($stmtD, "i", $id);

                if (mysqli_stmt_execute($stmtD)) {

                    $success =
                        "Employee marked as inactive successfully!";

                    logActivity(
                        $conn,
                        'DELETE',
                        'EMPLOYEE',
                        'Marked employee as inactive: ' . ($oldName ?: 'Employee #' . $id),
                        $id
                    );

                } else {

                    $error =
                        "Error updating employee: " .
                        mysqli_stmt_error($stmtD);
                }

                mysqli_stmt_close($stmtD);
            }
        }
    }
}

/* ---------------- FETCH EMPLOYEES ---------------- */

$sql = "
SELECT
    id,
    full_name,
    employee_code,
    photo,
    date_of_birth,
    gender,
    blood_group,
    mobile_number,
    email,
    current_address,
    emergency_contact_name,
    emergency_contact_phone,
    date_of_joining,
    department,
    designation,
    reporting_to,
    reporting_manager,
    work_location,
    site_name,
    employee_status,
    username,
    aadhar_card_number,
    pancard_number,
    bank_account_number,
    ifsc_code,
    passbook_photo,
    created_at,
    updated_at
FROM employees
ORDER BY created_at DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {

    $employees =
        mysqli_fetch_all($result, MYSQLI_ASSOC);

    mysqli_free_result($result);

} else {

    $error =
        "Error fetching employees: " .
        mysqli_error($conn);
}

/* ---------------- STATS ---------------- */

$total_employees = count($employees);
$active_employees = 0;
$inactive_employees = 0;
$resigned_employees = 0;

foreach ($employees as $emp) {

    $st = strtolower(trim((string)($emp['employee_status'] ?? 'inactive')));

    if ($st === 'active') {
        $active_employees++;
    } elseif ($st === 'resigned') {
        $resigned_employees++;
    } else {
        $inactive_employees++;
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

<title>Employees - TEK-C</title>

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

:root {

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

.employees-wrapper{
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

.employee-photo{
  width:30px;
  height:30px;
  border-radius:9px;
  display:grid;
  place-items:center;
  overflow:hidden;
  background:#eff6ff;
  color:#2563eb;
  font-size:13px;
  font-weight:950;
  flex:0 0 auto;
}

.employee-photo img{
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

.pending{
  color:#6d28d9;
  background:#ede9fe;
}

.danger{
  color:#b91c1c;
  background:#fee2e2;
}

.role-text{
  font-size:10px;
  color:#64748b;
  line-height:1.5;
}

.contact-text{
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

.edit-btn{
  color:#2563eb;
  background:#eff6ff;
}

.file-btn{
  color:#dc2626;
  background:#fef2f2;
}

.delete-btn{
  color:#b91c1c;
  background:#fee2e2;
  border:1px solid #fecaca;
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
}

</style>

</head>

<body>

<div class="app">

<?php include 'includes/sidebar.php'; ?>

<main class="main">

<?php include 'includes/topbar.php'; ?>

<div class="content-scroll">

<div class="container-fluid employees-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>Employees</h1>

<p>
Manage all active, inactive and resigned employees
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="add-employee.php"
class="primary-btn"
>
<i class="bi bi-person-plus"></i>
Add Employee
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

<?php if($success): ?>

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

<?php if($error): ?>

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

<!-- STATS -->

<div class="row g-3 mb-3">

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-people-fill"></i>
</div>

<div>
<div class="stat-label">Total Employees</div>
<div class="stat-value">
<?php echo (int)$total_employees; ?>
</div>
</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic green">
<i class="bi bi-person-check-fill"></i>
</div>

<div>
<div class="stat-label">Active</div>
<div class="stat-value">
<?php echo (int)$active_employees; ?>
</div>
</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-person-x-fill"></i>
</div>

<div>
<div class="stat-label">Inactive</div>
<div class="stat-value">
<?php echo (int)$inactive_employees; ?>
</div>
</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic red">
<i class="bi bi-person-dash-fill"></i>
</div>

<div>
<div class="stat-label">Resigned</div>
<div class="stat-value">
<?php echo (int)$resigned_employees; ?>
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
Employee Directory
</h3>

<div class="panel-subtitle">
Compact responsive employee records
</div>

</div>

</div>

<!-- FILTER BAR -->

<div class="filter-bar">

<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="employeeSearch"
placeholder="Search employee, code, department, designation or mobile..."
>

</div>

<div class="d-flex gap-2 flex-wrap">

<select
class="filter-select"
id="statusFilter"
>

<option value="">All Status</option>
<option value="active">Active</option>
<option value="inactive">Inactive</option>
<option value="resigned">Resigned</option>

</select>

<select
class="filter-select"
id="departmentFilter"
>

<option value="">All Departments</option>

<?php
$departments = [];

foreach ($employees as $emp) {
    $dept = trim((string)($emp['department'] ?? ''));
    if ($dept !== '') {
        $departments[$dept] = true;
    }
}

ksort($departments);

foreach (array_keys($departments) as $dept):
?>

<option value="<?php echo e(strtolower($dept)); ?>">
<?php echo e($dept); ?>
</option>

<?php endforeach; ?>

</select>

</div>

</div>

<!-- TABLE -->

<div class="compact-table-wrap">

<table
class="table compact-table align-middle"
id="employeesTable"
>

<thead>

<tr>

<th>Employee</th>
<th>Role</th>
<th>Contact</th>
<th>Status</th>
<th>Joining</th>
<th>Location</th>
<th class="text-end">Actions</th>

</tr>

</thead>

<tbody>

<?php foreach($employees as $employee): ?>

<?php

[$stLabel, $stClass, $stKey] =
    statusBadge($employee['employee_status'] ?? '');

$photoSrc =
    fileUrl($employee['photo'] ?? '');

$passbookSrc =
    fileUrl($employee['passbook_photo'] ?? '');

$fullName =
    trim((string)($employee['full_name'] ?? ''));

$initial =
    $fullName !== ''
    ? strtoupper(substr($fullName, 0, 1))
    : 'E';

$department =
    trim((string)($employee['department'] ?? ''));

?>

<tr
data-status="<?php echo e($stKey); ?>"
data-department="<?php echo e(strtolower($department)); ?>"
>

<!-- EMPLOYEE -->

<td data-label="Employee">

<div class="table-title-cell">

<div class="employee-photo">

<?php if(!empty($photoSrc)): ?>

<img
src="<?php echo e($photoSrc); ?>"
alt="<?php echo e($fullName); ?>"
onerror="this.style.display='none'; this.parentNode.innerHTML='<?php echo e($initial); ?>';"
>

<?php else: ?>

<?php echo e($initial); ?>

<?php endif; ?>

</div>

<div>

<div class="table-primary-text">
<?php echo e($employee['full_name'] ?? ''); ?>
</div>

<div class="table-secondary-text">

<?php echo e($employee['employee_code'] ?? ''); ?>

<?php if(!empty($employee['username'])): ?>

•

<?php echo e($employee['username']); ?>

<?php endif; ?>

</div>

</div>

</div>

</td>

<!-- ROLE -->

<td data-label="Role">

<div class="role-text">

<div>

<b>Designation:</b>

<?php echo !empty($employee['designation']) ? e($employee['designation']) : '—'; ?>

</div>

<div>

<b>Department:</b>

<?php echo !empty($employee['department']) ? e($employee['department']) : '—'; ?>

</div>

<?php if(!empty($employee['reporting_manager'])): ?>

<div>

<b>Reports to:</b>

<?php echo e($employee['reporting_manager']); ?>

</div>

<?php endif; ?>

</div>

</td>

<!-- CONTACT -->

<td data-label="Contact">

<div class="contact-text">

<?php if(!empty($employee['mobile_number'])): ?>

<div>
<i class="bi bi-telephone me-1"></i>
<?php echo e($employee['mobile_number']); ?>
</div>

<?php endif; ?>

<?php if(!empty($employee['email'])): ?>

<div>
<i class="bi bi-envelope me-1"></i>
<?php echo e($employee['email']); ?>
</div>

<?php endif; ?>

<?php if(empty($employee['mobile_number']) && empty($employee['email'])): ?>

—

<?php endif; ?>

</div>

</td>

<!-- STATUS -->

<td data-label="Status">

<span class="badge-pill <?php echo e($stClass); ?>">

<span class="mini-dot"></span>

<?php echo e($stLabel); ?>

</span>

</td>

<!-- JOINING -->

<td data-label="Joining">

<div class="table-primary-text">

<?php echo e(safeDate($employee['date_of_joining'] ?? '')); ?>

</div>

<?php if(!empty($employee['created_at'])): ?>

<div class="table-secondary-text">

Created:
<?php echo e(safeDate($employee['created_at'], '—')); ?>

</div>

<?php endif; ?>

</td>

<!-- LOCATION -->

<td data-label="Location">

<div class="contact-text">

<div>

<b>Work:</b>

<?php echo !empty($employee['work_location']) ? e($employee['work_location']) : '—'; ?>

</div>

<?php if(!empty($employee['site_name'])): ?>

<div>

<b>Site:</b>

<?php echo e($employee['site_name']); ?>

</div>

<?php endif; ?>

</div>

</td>

<!-- ACTIONS -->

<td data-label="Actions">

<div class="action-group">

<a
href="view-employee.php?id=<?php echo (int)$employee['id']; ?>"
class="action-btn view-btn"
title="View"
>
<i class="bi bi-eye"></i>
</a>

<a
href="edit-employee.php?id=<?php echo (int)$employee['id']; ?>"
class="action-btn edit-btn"
title="Edit"
>
<i class="bi bi-pencil-square"></i>
</a>

<?php if(!empty($passbookSrc)): ?>

<a
href="<?php echo e($passbookSrc); ?>"
target="_blank"
rel="noopener"
class="action-btn file-btn"
title="Passbook / File"
>
<i class="bi bi-file-earmark-arrow-down"></i>
</a>

<?php endif; ?>

<form
method="POST"
style="display:inline;"
onsubmit="return confirm('Mark this employee as inactive?');"
>

<input
type="hidden"
name="delete_id"
value="<?php echo (int)$employee['id']; ?>"
>

<button
type="submit"
class="action-btn delete-btn"
title="Mark Inactive"
>
<i class="bi bi-trash"></i>
</button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<!-- PAGINATION INFO -->

<div class="pagination-wrap">

<div class="pagination-info" id="recordInfo">

Showing
<?php echo count($employees); ?>
employee records

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
Export Employees
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<form
method="POST"
action="export-employees.php"
>

<div class="modal-body">

<div class="mb-3">

<label class="form-label">
Export Format
</label>

<select
class="form-select"
name="export_format"
required
>

<option value="csv">CSV</option>
<option value="excel">Excel</option>
<option value="pdf">PDF</option>

</select>

</div>

<div class="alert alert-warning mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>

Make sure
<b>export-employees.php</b>
exists for export functionality.

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

document.addEventListener('DOMContentLoaded', function () {

const searchInput =
document.getElementById('employeeSearch');

const statusFilter =
document.getElementById('statusFilter');

const departmentFilter =
document.getElementById('departmentFilter');

const tableRows =
document.querySelectorAll('#employeesTable tbody tr');

const recordInfo =
document.getElementById('recordInfo');

function filterEmployees() {

const searchValue =
searchInput.value.toLowerCase().trim();

const statusValue =
statusFilter.value.toLowerCase().trim();

const departmentValue =
departmentFilter.value.toLowerCase().trim();

let visibleCount = 0;

tableRows.forEach(function (row) {

const rowText =
row.innerText.toLowerCase();

const rowStatus =
row.getAttribute('data-status') || '';

const rowDepartment =
row.getAttribute('data-department') || '';

const matchesSearch =
rowText.includes(searchValue);

const matchesStatus =
!statusValue ||
rowStatus === statusValue;

const matchesDepartment =
!departmentValue ||
rowDepartment === departmentValue;

const visible =
matchesSearch &&
matchesStatus &&
matchesDepartment;

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
'Showing ' + visibleCount + ' employee records';
}

}

searchInput.addEventListener(
'input',
filterEmployees
);

statusFilter.addEventListener(
'change',
filterEmployees
);

departmentFilter.addEventListener(
'change',
filterEmployees
);

});

</script>

</body>
</html>

<?php
if(isset($conn)){
    mysqli_close($conn);
}
?>