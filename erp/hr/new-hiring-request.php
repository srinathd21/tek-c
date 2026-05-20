<?php
// new-hiring-request.php
// TEK-C add/edit/delete reference style

session_start();

require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$success = '';
$error = '';
$validation_errors = [];

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

$stmt =
    mysqli_prepare(
        $conn,
        "SELECT *
         FROM employees
         WHERE id = ?
         AND employee_status = 'active'
         LIMIT 1"
    );

if (!$stmt) {
    die("Employee query failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $current_employee_id
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$current_employee =
    mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

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

if (!$isHr && !$isManager) {
    $_SESSION['flash_error'] =
        "You don't have permission to create hiring requests.";

    header("Location: ../dashboard.php");
    exit;
}

/* ---------------- HELPERS ---------------- */

function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function nullIfEmpty($v){
    $v = trim((string)$v);
    return $v === '' ? null : $v;
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

$departments = [
    'PM',
    'CM',
    'IFM',
    'QS',
    'HR',
    'ACCOUNTS'
];

$employment_types = [
    'Full-time',
    'Part-time',
    'Contract',
    'Intern'
];

$priorities = [
    'Low',
    'Medium',
    'High',
    'Urgent'
];

$reasons_for_hiring = [
    'New Position',
    'Replacement',
    'Project Expansion',
    'Backfill',
    'Seasonal',
    'Other'
];

$userRole =
    $isHr ? 'HR' : 'Manager';

/* ---------------- HANDLE POST ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action =
        trim((string)($_POST['action'] ?? ''));

    if ($action === 'create_request') {

        /* ---------- COLLECT FORM DATA ---------- */

        $departmentPost =
            trim($_POST['department'] ?? '');

        $designationPost =
            trim($_POST['designation'] ?? '');

        $position_title =
            trim($_POST['position_title'] ?? '');

        $vacancies =
            trim($_POST['vacancies'] ?? '');

        $employment_type =
            trim($_POST['employment_type'] ?? '');

        $experience_min =
            trim($_POST['experience_min'] ?? '');

        $experience_max =
            trim($_POST['experience_max'] ?? '');

        $salary_min =
            trim($_POST['salary_min'] ?? '');

        $salary_max =
            trim($_POST['salary_max'] ?? '');

        $location =
            trim($_POST['location'] ?? '');

        $job_description =
            trim($_POST['job_description'] ?? '');

        $qualification =
            trim($_POST['qualification'] ?? '');

        $skills_required =
            trim($_POST['skills_required'] ?? '');

        $priority =
            trim($_POST['priority'] ?? 'Medium');

        $reason_for_hiring =
            trim($_POST['reason_for_hiring'] ?? '');

        $replacement_for =
            trim($_POST['replacement_for'] ?? '');

        $expected_joining_date =
            trim($_POST['expected_joining_date'] ?? '');

        /* ---------- VALIDATION ---------- */

        if ($departmentPost === '') {
            $validation_errors[] = "Department is required";
        }

        if (
            $departmentPost !== '' &&
            !in_array($departmentPost, $departments, true)
        ) {
            $validation_errors[] = "Invalid department selected";
        }

        if ($designationPost === '') {
            $validation_errors[] = "Designation is required";
        }

        if ($position_title === '') {
            $validation_errors[] = "Position title is required";
        }

        if ($vacancies === '') {
            $validation_errors[] = "No. of vacancies is required";
        }

        if (
            $vacancies !== '' &&
            (!ctype_digit((string)$vacancies) || (int)$vacancies < 1)
        ) {
            $validation_errors[] = "Vacancies must be at least 1";
        }

        if ($employment_type === '') {
            $validation_errors[] = "Employment type is required";
        }

        if (
            $employment_type !== '' &&
            !in_array($employment_type, $employment_types, true)
        ) {
            $validation_errors[] = "Invalid employment type selected";
        }

        if ($experience_min === '') {
            $validation_errors[] = "Minimum experience is required";
        }

        if ($experience_max === '') {
            $validation_errors[] = "Maximum experience is required";
        }

        if (
            $experience_min !== '' &&
            !is_numeric($experience_min)
        ) {
            $validation_errors[] = "Minimum experience must be a valid number";
        }

        if (
            $experience_max !== '' &&
            !is_numeric($experience_max)
        ) {
            $validation_errors[] = "Maximum experience must be a valid number";
        }

        if (
            is_numeric($experience_min) &&
            is_numeric($experience_max) &&
            (float)$experience_max < (float)$experience_min
        ) {
            $validation_errors[] =
                "Maximum experience cannot be less than minimum experience";
        }

        if (
            $salary_min !== '' &&
            !is_numeric($salary_min)
        ) {
            $validation_errors[] = "Minimum salary must be a valid number";
        }

        if (
            $salary_max !== '' &&
            !is_numeric($salary_max)
        ) {
            $validation_errors[] = "Maximum salary must be a valid number";
        }

        if (
            is_numeric($salary_min) &&
            is_numeric($salary_max) &&
            (float)$salary_max < (float)$salary_min
        ) {
            $validation_errors[] =
                "Maximum salary cannot be less than minimum salary";
        }

        if ($location === '') {
            $validation_errors[] = "Location is required";
        }

        if ($job_description === '') {
            $validation_errors[] = "Job description is required";
        }

        if ($priority === '') {
            $validation_errors[] = "Priority is required";
        }

        if (
            $priority !== '' &&
            !in_array($priority, $priorities, true)
        ) {
            $validation_errors[] = "Invalid priority selected";
        }

        if ($reason_for_hiring === '') {
            $validation_errors[] = "Reason for hiring is required";
        }

        if (
            $reason_for_hiring !== '' &&
            !in_array($reason_for_hiring, $reasons_for_hiring, true)
        ) {
            $validation_errors[] = "Invalid reason for hiring selected";
        }

        if (
            $reason_for_hiring === 'Replacement' &&
            $replacement_for === ''
        ) {
            $validation_errors[] =
                "Replacement employee name/code is required";
        }

        if ($expected_joining_date !== '') {

            $joining_ts =
                strtotime($expected_joining_date);

            if (!$joining_ts) {
                $validation_errors[] = "Invalid expected joining date";
            } else {
                $expected_joining_date =
                    date('Y-m-d', $joining_ts);
            }
        }

        /* ---------- INSERT ---------- */

        if (empty($validation_errors)) {

            $year =
                (int)date('Y');

            $month =
                date('m');

            $count =
                0;

            $stmtCount =
                mysqli_prepare(
                    $conn,
                    "SELECT COUNT(*) AS total_count
                     FROM hiring_requests
                     WHERE YEAR(created_at) = ?"
                );

            if ($stmtCount) {

                mysqli_stmt_bind_param(
                    $stmtCount,
                    "i",
                    $year
                );

                mysqli_stmt_execute($stmtCount);

                $resCount =
                    mysqli_stmt_get_result($stmtCount);

                $rowCount =
                    mysqli_fetch_assoc($resCount);

                $count =
                    (int)($rowCount['total_count'] ?? 0);

                mysqli_stmt_close($stmtCount);
            }

            $nextCount =
                $count + 1;

            $request_no =
                "HRQ-" .
                $year .
                $month .
                "-" .
                str_pad($nextCount, 4, '0', STR_PAD_LEFT);

            $vacancies_db =
                (int)$vacancies;

            $experience_min_db =
                (float)$experience_min;

            $experience_max_db =
                (float)$experience_max;

            $salary_min_db =
                $salary_min !== ''
                ? (float)$salary_min
                : null;

            $salary_max_db =
                $salary_max !== ''
                ? (float)$salary_max
                : null;

            $replacement_for_db =
                nullIfEmpty($replacement_for);

            $expected_joining_date_db =
                nullIfEmpty($expected_joining_date);

            $qualification_db =
                nullIfEmpty($qualification);

            $skills_required_db =
                nullIfEmpty($skills_required);

            $requested_by =
                $current_employee_id;

            $requested_by_name =
                $current_employee['full_name'] ?? '';

            $requested_date =
                date('Y-m-d');

            $status =
                'Pending';

            $sql = "INSERT INTO hiring_requests
            (
                request_no,
                department,
                designation,
                position_title,
                vacancies,
                employment_type,
                experience_min,
                experience_max,
                salary_min,
                salary_max,
                location,
                job_description,
                qualification,
                skills_required,
                priority,
                reason_for_hiring,
                replacement_for,
                requested_by,
                requested_by_name,
                requested_date,
                expected_joining_date,
                status
            )
            VALUES
            (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?
            )";

            $stmtInsert =
                mysqli_prepare($conn, $sql);

            if ($stmtInsert) {

                mysqli_stmt_bind_param(
                    $stmtInsert,
                    "ssssisdiddsssssssissss",
                    $request_no,
                    $departmentPost,
                    $designationPost,
                    $position_title,
                    $vacancies_db,
                    $employment_type,
                    $experience_min_db,
                    $experience_max_db,
                    $salary_min_db,
                    $salary_max_db,
                    $location,
                    $job_description,
                    $qualification_db,
                    $skills_required_db,
                    $priority,
                    $reason_for_hiring,
                    $replacement_for_db,
                    $requested_by,
                    $requested_by_name,
                    $requested_date,
                    $expected_joining_date_db,
                    $status
                );

                if (mysqli_stmt_execute($stmtInsert)) {

                    $request_id =
                        mysqli_insert_id($conn);

                    logActivity(
                        $conn,
                        'CREATE',
                        'HIRING',
                        'Created hiring request: ' .
                        $request_no .
                        ' for ' .
                        $position_title,
                        $request_id
                    );

                    $success =
                        "Hiring request created successfully! Request #: " .
                        $request_no;

                    $_POST = [];

                } else {

                    $error =
                        "Error creating hiring request: " .
                        mysqli_stmt_error($stmtInsert);
                }

                mysqli_stmt_close($stmtInsert);

            } else {

                $error =
                    "Database error: " .
                    mysqli_error($conn);
            }
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

<title>New Hiring Request - TEK-C</title>

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

.content-scroll{
    flex:1 1 auto;
    overflow:auto;
    padding:22px 22px 14px;
}

.form-panel{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:18px;
    margin-bottom:18px;
}

.section-header{
    display:flex;
    align-items:center;
    margin-bottom:25px;
    padding-bottom:15px;
    border-bottom:2px solid #f0f4f8;
}

.section-icon{
    width:38px;
    height:38px;
    border-radius:12px;
    background:var(--blue);
    display:flex;
    align-items:center;
    justify-content:center;
    margin-right:15px;
    font-size:20px;
    color:#fff;
}

.section-title{
    font-size:14px;
    font-weight:900;
    color:#2d3748;
    margin:0;
}

.section-subtitle{
    font-size:11px;
    color:#64748b;
    font-weight:700;
    margin-top:4px;
}

.form-label{
    font-weight:700;
    color:#4a5568;
    margin-bottom:8px;
    font-size:14px;
}

.required-label::after{
    content:" *";
    color:#e53e3e;
    font-weight:900;
}

.optional-badge{
    font-size:11px;
    color:#718096;
    font-weight:600;
    margin-left:5px;
}

.form-control,
.form-select{
    height:40px;
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:0 12px;
    font-size:12px;
    font-weight:700;
    transition:all .3s;
}

textarea.form-control{
    height:auto;
    padding:10px 12px;
}

.form-control:focus,
.form-select:focus{
    border-color:var(--blue);
    box-shadow:0 0 0 3px rgba(45,156,219,.1);
}

.form-control.is-invalid,
.form-select.is-invalid{
    border-color:#fc8181;
    background:#fff5f5;
}

.form-helper{
    font-size:12px;
    color:#718096;
    margin-top:5px;
    display:flex;
    align-items:center;
    gap:6px;
}

.btn-back{
    background:transparent;
    border:1px solid var(--border);
    border-radius:10px;
    padding:8px 16px;
    color:#4a5568;
    font-weight:700;
    display:flex;
    align-items:center;
    gap:6px;
    text-decoration:none;
}

.btn-back:hover{
    background:var(--bg);
    color:var(--blue);
    border-color:var(--blue);
}

.btn-submit{
    background:#111827;
    color:#fff;
    border:none;
    height:42px;
    padding:0 18px;
    border-radius:12px;
    font-weight:800;
    font-size:15px;
    display:flex;
    align-items:center;
    gap:10px;
    box-shadow:0 8px 20px rgba(45,156,219,.2);
    transition:all .3s;
    margin:40px auto;
}

.btn-submit:hover{
    background:#2a8bc9;
    transform:translateY(-2px);
    box-shadow:0 12px 25px rgba(45,156,219,.3);
    color:#fff;
}

.alert{
    border-radius:var(--radius);
    border:none;
    box-shadow:var(--shadow);
    margin-bottom:20px;
}

.role-badge{
    display:inline-flex;
    align-items:center;
    gap:5px;
    border-radius:999px;
    padding:5px 9px;
    font-size:10px;
    font-weight:900;
    background:#dbeafe;
    color:#1e40af;
}

@media(max-width:768px){

    .content-scroll{
        padding:12px 10px 12px!important;
    }

    .container-fluid.maxw{
        padding-left:6px!important;
        padding-right:6px!important;
    }

    .form-panel{
        padding:12px!important;
        margin-bottom:12px;
        border-radius:14px;
    }

    .section-header{
        padding:10px!important;
        border-radius:12px;
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

<div class="container-fluid maxw">

<!-- HEADER -->

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

<div>

<h1 class="h3 fw-bold text-dark mb-1">
New Hiring Request
</h1>

<div class="d-flex align-items-center gap-2 flex-wrap">

<p class="text-muted mb-0">
Create a new position requisition
</p>

<span class="role-badge">
<i class="bi bi-shield-check"></i>
<?php echo e($userRole); ?>
</span>

</div>

</div>

<a href="hiring-requests.php" class="btn-back">
<i class="bi bi-arrow-left"></i>
Back to Requests
</a>

</div>

<!-- ALERTS -->

<?php if ($success): ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">

<i class="bi bi-check-circle-fill me-2"></i>

<strong>Success!</strong>
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

<strong>Error!</strong>
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

<!-- FORM -->

<form
method="POST"
action=""
id="hiringRequestForm"
novalidate
>

<input
type="hidden"
name="action"
value="create_request"
>

<!-- POSITION DETAILS -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon">
<i class="bi bi-briefcase"></i>
</div>

<div>

<h3 class="section-title">
Position Details
</h3>

<p class="section-subtitle">
Department, role, vacancies and hiring priority
</p>

</div>

</div>

<div class="row g-4">

<div class="col-md-4">

<label for="department" class="form-label required-label">
Department
</label>

<select
name="department"
id="department"
class="form-select"
required
>

<option value="">
Select Department
</option>

<?php foreach ($departments as $dept): ?>

<option
value="<?php echo e($dept); ?>"
<?php echo (($_POST['department'] ?? '') === $dept) ? 'selected' : ''; ?>
>
<?php echo e($dept); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-4">

<label for="designation" class="form-label required-label">
Designation
</label>

<input
type="text"
name="designation"
id="designation"
class="form-control"
placeholder="Example: Senior Engineer"
value="<?php echo e($_POST['designation'] ?? ''); ?>"
required
>

</div>

<div class="col-md-4">

<label for="position_title" class="form-label required-label">
Position Title
</label>

<input
type="text"
name="position_title"
id="position_title"
class="form-control"
placeholder="Example: Project Engineer"
value="<?php echo e($_POST['position_title'] ?? ''); ?>"
required
>

</div>

<div class="col-md-3">

<label for="vacancies" class="form-label required-label">
No. of Vacancies
</label>

<input
type="number"
name="vacancies"
id="vacancies"
class="form-control"
min="1"
value="<?php echo e($_POST['vacancies'] ?? '1'); ?>"
required
>

</div>

<div class="col-md-3">

<label for="employment_type" class="form-label required-label">
Employment Type
</label>

<select
name="employment_type"
id="employment_type"
class="form-select"
required
>

<option value="">
Select Type
</option>

<?php foreach ($employment_types as $type): ?>

<option
value="<?php echo e($type); ?>"
<?php echo (($_POST['employment_type'] ?? '') === $type) ? 'selected' : ''; ?>
>
<?php echo e($type); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-3">

<label for="priority" class="form-label required-label">
Priority
</label>

<select
name="priority"
id="priority"
class="form-select"
required
>

<?php foreach ($priorities as $p): ?>

<option
value="<?php echo e($p); ?>"
<?php echo (($_POST['priority'] ?? 'Medium') === $p) ? 'selected' : ''; ?>
>
<?php echo e($p); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-3">

<label for="expected_joining_date" class="form-label">
Expected Joining Date
<span class="optional-badge">(Optional)</span>
</label>

<input
type="date"
name="expected_joining_date"
id="expected_joining_date"
class="form-control"
min="<?php echo date('Y-m-d', strtotime('+7 days')); ?>"
value="<?php echo e($_POST['expected_joining_date'] ?? ''); ?>"
>

</div>

</div>

</div>

<!-- EXPERIENCE AND SALARY -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#4facfe;">
<i class="bi bi-bar-chart"></i>
</div>

<div>

<h3 class="section-title">
Experience & Compensation
</h3>

<p class="section-subtitle">
Experience range, salary range and job location
</p>

</div>

</div>

<div class="row g-4">

<div class="col-md-3">

<label for="experience_min" class="form-label required-label">
Min Experience
</label>

<input
type="number"
name="experience_min"
id="experience_min"
class="form-control"
min="0"
step="0.5"
value="<?php echo e($_POST['experience_min'] ?? '0'); ?>"
required
>

<div class="form-helper">
<i class="bi bi-info-circle"></i>
Years
</div>

</div>

<div class="col-md-3">

<label for="experience_max" class="form-label required-label">
Max Experience
</label>

<input
type="number"
name="experience_max"
id="experience_max"
class="form-control"
min="0"
step="0.5"
value="<?php echo e($_POST['experience_max'] ?? '2'); ?>"
required
>

<div class="form-helper">
<i class="bi bi-info-circle"></i>
Years
</div>

</div>

<div class="col-md-3">

<label for="salary_min" class="form-label">
Min Salary
<span class="optional-badge">(Optional)</span>
</label>

<input
type="number"
name="salary_min"
id="salary_min"
class="form-control"
min="0"
step="0.1"
placeholder="Example: 3.5"
value="<?php echo e($_POST['salary_min'] ?? ''); ?>"
>

<div class="form-helper">
<i class="bi bi-currency-rupee"></i>
LPA
</div>

</div>

<div class="col-md-3">

<label for="salary_max" class="form-label">
Max Salary
<span class="optional-badge">(Optional)</span>
</label>

<input
type="number"
name="salary_max"
id="salary_max"
class="form-control"
min="0"
step="0.1"
placeholder="Example: 6.0"
value="<?php echo e($_POST['salary_max'] ?? ''); ?>"
>

<div class="form-helper">
<i class="bi bi-currency-rupee"></i>
LPA
</div>

</div>

<div class="col-12">

<label for="location" class="form-label required-label">
Location
</label>

<input
type="text"
name="location"
id="location"
class="form-control"
placeholder="Example: Bangalore, Mumbai, Remote"
value="<?php echo e($_POST['location'] ?? ''); ?>"
required
>

</div>

</div>

</div>

<!-- QUALIFICATION AND SKILLS -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#30cfd0;">
<i class="bi bi-mortarboard"></i>
</div>

<div>

<h3 class="section-title">
Qualifications & Skills
</h3>

<p class="section-subtitle">
Job description, qualifications and required skills
</p>

</div>

</div>

<div class="row g-4">

<div class="col-12">

<label for="job_description" class="form-label required-label">
Job Description
</label>

<textarea
name="job_description"
id="job_description"
class="form-control"
rows="4"
placeholder="Describe role, responsibilities and expectations"
required
><?php echo e($_POST['job_description'] ?? ''); ?></textarea>

</div>

<div class="col-12">

<label for="qualification" class="form-label">
Qualification Required
<span class="optional-badge">(Optional)</span>
</label>

<textarea
name="qualification"
id="qualification"
class="form-control"
rows="3"
placeholder="Example: B.E / B.Tech in Civil Engineering"
><?php echo e($_POST['qualification'] ?? ''); ?></textarea>

</div>

<div class="col-12">

<label for="skills_required" class="form-label">
Skills Required
<span class="optional-badge">(Optional)</span>
</label>

<textarea
name="skills_required"
id="skills_required"
class="form-control"
rows="3"
placeholder="List key skills required"
><?php echo e($_POST['skills_required'] ?? ''); ?></textarea>

</div>

</div>

</div>

<!-- HIRING DETAILS -->

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#fa709a;">
<i class="bi bi-question-circle"></i>
</div>

<div>

<h3 class="section-title">
Hiring Details
</h3>

<p class="section-subtitle">
Reason for hiring and replacement details
</p>

</div>

</div>

<div class="row g-4">

<div class="col-12">

<label for="reason_for_hiring" class="form-label required-label">
Reason for Hiring
</label>

<select
name="reason_for_hiring"
id="reason_for_hiring"
class="form-select"
required
>

<option value="">
Select Reason
</option>

<?php foreach ($reasons_for_hiring as $reason): ?>

<option
value="<?php echo e($reason); ?>"
<?php echo (($_POST['reason_for_hiring'] ?? '') === $reason) ? 'selected' : ''; ?>
>
<?php echo e($reason); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div
class="col-12"
id="replacementField"
style="<?php echo (($_POST['reason_for_hiring'] ?? '') === 'Replacement') ? '' : 'display:none;'; ?>"
>

<label for="replacement_for" class="form-label">
Replacement For
<span class="optional-badge">(Required for Replacement)</span>
</label>

<input
type="text"
name="replacement_for"
id="replacement_for"
class="form-control"
placeholder="Enter employee name or code"
value="<?php echo e($_POST['replacement_for'] ?? ''); ?>"
>

</div>

</div>

</div>

<!-- SUBMIT -->

<div>

<a href="hiring-requests.php" class="btn btn-light border">
Cancel
</a>

<button type="submit" class="btn-submit">
<i class="bi bi-send"></i>
Submit Request
</button>

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
        document.getElementById('hiringRequestForm');

    const reasonSelect =
        document.getElementById('reason_for_hiring');

    const replacementField =
        document.getElementById('replacementField');

    const replacementInput =
        document.getElementById('replacement_for');

    function toggleReplacementField(){

        if (!reasonSelect || !replacementField || !replacementInput) {
            return;
        }

        if (reasonSelect.value === 'Replacement') {
            replacementField.style.display = '';
            replacementInput.setAttribute('required', 'required');
        } else {
            replacementField.style.display = 'none';
            replacementInput.removeAttribute('required');
            replacementInput.classList.remove('is-invalid');
        }
    }

    if (reasonSelect) {
        reasonSelect.addEventListener('change', toggleReplacementField);
        toggleReplacementField();
    }

    const setInvalid =
        (el) => el && el.classList.add('is-invalid');

    if (form) {

        form.addEventListener('submit', function(e){

            let valid =
                true;

            const errorMessages =
                [];

            form.querySelectorAll('.is-invalid').forEach(function(el){
                el.classList.remove('is-invalid');
            });

            const requiredFields =
                form.querySelectorAll('[required]');

            requiredFields.forEach(function(field){

                if (!String(field.value || '').trim()) {

                    valid =
                        false;

                    setInvalid(field);

                    const label =
                        form.querySelector('label[for="' + field.id + '"]');

                    const fieldName =
                        label
                        ? label.textContent.replace('*', '').trim()
                        : field.name;

                    errorMessages.push(
                        fieldName + ' is required'
                    );
                }
            });

            const minExp =
                document.getElementById('experience_min');

            const maxExp =
                document.getElementById('experience_max');

            if (
                minExp &&
                maxExp &&
                minExp.value !== '' &&
                maxExp.value !== '' &&
                parseFloat(maxExp.value) < parseFloat(minExp.value)
            ) {
                valid = false;
                setInvalid(maxExp);
                errorMessages.push('Maximum experience cannot be less than minimum experience');
            }

            const minSalary =
                document.getElementById('salary_min');

            const maxSalary =
                document.getElementById('salary_max');

            if (
                minSalary &&
                maxSalary &&
                minSalary.value !== '' &&
                maxSalary.value !== '' &&
                parseFloat(maxSalary.value) < parseFloat(minSalary.value)
            ) {
                valid = false;
                setInvalid(maxSalary);
                errorMessages.push('Maximum salary cannot be less than minimum salary');
            }

            const vacancies =
                document.getElementById('vacancies');

            if (
                vacancies &&
                vacancies.value !== '' &&
                parseInt(vacancies.value, 10) < 1
            ) {
                valid = false;
                setInvalid(vacancies);
                errorMessages.push('Vacancies must be at least 1');
            }

            if (!valid) {

                e.preventDefault();

                const alertDiv =
                    document.createElement('div');

                alertDiv.className =
                    'alert alert-danger alert-dismissible fade show';

                alertDiv.innerHTML =
                    `
                    <div class="d-flex align-items-start">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div>
                            <strong>Form Validation Error</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                ${errorMessages.map(function(msg){
                                    return '<li>' + msg + '</li>';
                                }).join('')}
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;

                const container =
                    document.querySelector('.container-fluid.maxw');

                const headerBlock =
                    container.children[0];

                container.insertBefore(
                    alertDiv,
                    headerBlock.nextSibling
                );

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
if (isset($conn)) {
    mysqli_close($conn);
}
?>