<?php
// hr/new-hiring-request.php
session_start();

require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

function hrTableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function hrColumnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEsc = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function logHiringActivityCurrentDb($conn, int $employeeId, string $activityType, string $description, int $referenceId, array $newData = []): bool {
    if (!$conn || !hrTableExists($conn, 'activity_logs')) {
        return false;
    }

    $newJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;
    $employeeName = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'System';
    $username = $_SESSION['username'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $department = $_SESSION['department'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $map = [
        'employee_id'   => ['i', $employeeId],
        'employee_name' => ['s', $employeeName],
        'username'      => ['s', $username],
        'designation'   => ['s', $designation],
        'department'    => ['s', $department],
        'activity_type' => ['s', $activityType],
        'module'        => ['s', 'hiring_request'],
        'description'   => ['s', $description],
        'reference_id'  => ['i', $referenceId],
        'new_data'      => ['s', $newJson],
        'ip_address'    => ['s', $ipAddress],
    ];

    $columns = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (hrColumnExists($conn, 'activity_logs', $column)) {
            $columns[] = "`$column`";
            $types .= $pair[0];
            $values[] = $pair[1];
        }
    }

    if (!$columns) return false;

    $sql = "INSERT INTO activity_logs (" . implode(',', $columns) . ") VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}


function createNotificationCurrentDb(
    $conn,
    int $employeeId,
    string $title,
    string $message,
    string $module,
    int $referenceId,
    string $link,
    string $type = 'hiring'
): bool {
    if ($employeeId <= 0 || !$conn || !hrTableExists($conn, 'notifications')) {
        return false;
    }

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $map = [
        'employee_id'  => ['i', $employeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', $type],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'priority'     => ['s', 'normal'],
        'is_read'      => ['i', 0],
        'created_at'   => ['raw', 'NOW()'],
    ];

    foreach ($map as $column => $pair) {
        if (hrColumnExists($conn, 'notifications', $column)) {
            $columns[] = "`$column`";

            if ($pair[0] === 'raw') {
                $placeholders[] = $pair[1];
            } else {
                $placeholders[] = '?';
                $types .= $pair[0];
                $values[] = $pair[1];
            }
        }
    }

    if (!$columns) {
        return false;
    }

    $sql = "INSERT INTO notifications (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    if ($values) {
        mysqli_stmt_bind_param($stmt, $types, ...$values);
    }

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function getHrEmployees($conn): array {
    $employees = [];

    if (!$conn || !hrTableExists($conn, 'employees')) {
        return $employees;
    }

    $sql = "
        SELECT id, full_name
        FROM employees
        WHERE employee_status = 'active'
          AND (
                LOWER(COALESCE(designation, '')) LIKE '%hr%'
             OR LOWER(COALESCE(department, '')) LIKE '%hr%'
             OR LOWER(COALESCE(department, '')) LIKE '%human resource%'
          )
        ORDER BY full_name
    ";

    $res = mysqli_query($conn, $sql);

    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $employees[] = $row;
        }
        mysqli_free_result($res);
    }

    return $employees;
}

function notifyHrForHiringRequest($conn, int $createdByEmployeeId, int $requestId, string $requestNo, string $positionTitle, string $createdByName): void {
    $hrs = getHrEmployees($conn);
    $notified = [];

    foreach ($hrs as $hr) {
        $hrId = (int)($hr['id'] ?? 0);

        if ($hrId <= 0 || $hrId === $createdByEmployeeId || isset($notified[$hrId])) {
            continue;
        }

        createNotificationCurrentDb(
            $conn,
            $hrId,
            'New hiring request',
            $createdByName . ' created hiring request ' . $requestNo . ' for ' . $positionTitle . '.',
            'hiring_request',
            $requestId,
            'hiring-requests.php',
            'hiring'
        );

        $notified[$hrId] = true;
    }
}


/* ---------------- AUTH (HR / MANAGER) ---------------- */

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];

/* Get logged employee */
$stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
mysqli_stmt_bind_param($stmt, "i", $current_employee_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$current_employee = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$current_employee) {
    die("Employee not found.");
}

/* Check Role */
$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department  = strtolower(trim($current_employee['department'] ?? ''));

$isHr = ($designation === 'hr' || $department === 'hr');

$isManager = in_array($designation, [
    'manager',
    'team lead',
    'project manager',
    'director',
    'administrator',
    'admin'
]);
$isAdmin = in_array($designation, ['administrator', 'admin', 'director']);

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to create hiring requests.";
    header("Location: ../dashboard.php");
    exit;
}

/* ---------------- FORM SUBMISSION ---------------- */

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create_request') {

        $required = [
            'department',
            'designation',
            'position_title',
            'vacancies',
            'employment_type',
            'experience_min',
            'experience_max',
            'location',
            'job_description',
            'reason_for_hiring'
        ];

        $missing = [];

        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {

            $message = "Please fill all required fields: " . implode(', ', $missing);
            $messageType = "danger";

        } else {

            /* Generate Request Number */

            $year = (int)date('Y');
            $month = date('m');

            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as count FROM hiring_requests WHERE YEAR(created_at) = ?");
            mysqli_stmt_bind_param($stmt, "i", $year);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);

            $count = ((int)($row['count'] ?? 0)) + 1;

            $request_no = "HRQ-{$year}{$month}-" . str_pad($count, 4, '0', STR_PAD_LEFT);

            /* Collect Form Data */

            $department           = $_POST['department'];
            $designation          = $_POST['designation'];
            $position_title       = $_POST['position_title'];
            $vacancies            = (int)$_POST['vacancies'];
            $employment_type      = $_POST['employment_type'];
            $experience_min       = (int)$_POST['experience_min'];
            $experience_max       = (int)$_POST['experience_max'];
            $salary_min           = !empty($_POST['salary_min']) ? floatval($_POST['salary_min']) : null;
            $salary_max           = !empty($_POST['salary_max']) ? floatval($_POST['salary_max']) : null;
            $location             = $_POST['location'];
            $job_description      = $_POST['job_description'];
            $qualification        = $_POST['qualification'] ?? '';
            $skills_required      = $_POST['skills_required'] ?? '';
            $priority             = $_POST['priority'] ?? 'Medium';
            $reason_for_hiring    = $_POST['reason_for_hiring'];
            $replacement_for      = !empty($_POST['replacement_for']) ? $_POST['replacement_for'] : null;
            $expected_joining_date = !empty($_POST['expected_joining_date']) ? $_POST['expected_joining_date'] : null;

            $requested_by       = $current_employee_id;
            $requested_by_name  = $current_employee['full_name'];
            $requested_date     = date('Y-m-d');

            /* Insert Query */

            $insert = mysqli_prepare($conn, "
                INSERT INTO hiring_requests (
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
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending')
            ");

            $types = "ssssisiiddsssssssisss";

            mysqli_stmt_bind_param(
                $insert,
                $types,
                $request_no,
                $department,
                $designation,
                $position_title,
                $vacancies,
                $employment_type,
                $experience_min,
                $experience_max,
                $salary_min,
                $salary_max,
                $location,
                $job_description,
                $qualification,
                $skills_required,
                $priority,
                $reason_for_hiring,
                $replacement_for,
                $requested_by,
                $requested_by_name,
                $requested_date,
                $expected_joining_date
            );

            if (mysqli_stmt_execute($insert)) {

                $request_id = mysqli_insert_id($conn);

                logHiringActivityCurrentDb(
                    $conn,
                    (int)$current_employee_id,
                    'CREATE',
                    "Created hiring request: {$request_no} for {$position_title}",
                    (int)$request_id,
                    [
                        'request_no' => $request_no,
                        'department' => $department,
                        'designation' => $designation,
                        'position_title' => $position_title,
                        'vacancies' => $vacancies,
                        'employment_type' => $employment_type,
                        'priority' => $priority,
                        'requested_by_name' => $requested_by_name,
                        'status' => 'Pending'
                    ]
                );

                // When Manager/Admin creates a hiring request, notify HR employees.
                if (!$isHr) {
                    notifyHrForHiringRequest(
                        $conn,
                        (int)$current_employee_id,
                        (int)$request_id,
                        $request_no,
                        $position_title,
                        $requested_by_name
                    );
                }

                $_SESSION['flash_success'] = "Hiring request created successfully! Request #: {$request_no}";
                header("Location: hiring-requests.php");
                exit;

            } else {

                $message = "Error creating request: " . mysqli_stmt_error($insert);
                $messageType = "danger";
            }

            if (isset($insert) && $insert) {
                mysqli_stmt_close($insert);
            }
        }
    }
}

/* ---------------- HELPER ---------------- */

function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* Dropdown Data */

$departments = ['PM', 'CM', 'IFM', 'QS', 'HR', 'ACCOUNTS'];

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

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];

$userRole = $isHr ? 'HR' : ($isAdmin ? 'Admin' : 'Manager');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>New Hiring Request - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
            --blue:#2f80ed;
            --green:#27ae60;
            --orange:#f2994a;
            --red:#eb5757;
            --purple:#7c3aed;
        }

        body{ background:var(--page-bg); }

        .content-scroll{
            flex:1 1 auto;
            overflow:auto;
            padding:16px;
        }

        .projects-wrapper{ width:100%; }

        .page-heading{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .page-heading h1{
            font-size:19px;
            font-weight:950;
            color:var(--text);
            margin:0;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .page-heading p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:12px;
            font-weight:650;
        }

        .primary-btn,.secondary-btn,.submit-btn{
            min-height:36px;
            padding:0 14px;
            border-radius:11px;
            font-size:12px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            text-decoration:none;
            white-space:nowrap;
            line-height:1;
            border:0;
        }

        .primary-btn,.submit-btn{
            background:#111827;
            color:#fff;
        }

        .primary-btn:hover,.submit-btn:hover{
            background:#020617;
            color:#fff;
        }

        .secondary-btn{
            border:1px solid var(--border);
            background:#fff;
            color:#334155;
        }

        .secondary-btn:hover{
            border-color:#cbd5e1;
            background:#f8fafc;
            color:#111827;
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
            font-weight:950;
            font-size:14px;
            color:var(--text);
            margin:0;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .panel-title i{
            color:var(--blue);
            font-size:16px;
        }

        .panel-subtitle{
            color:var(--muted);
            font-size:11px;
            font-weight:700;
            margin-top:2px;
        }

        .form-section{
            border:1px solid #eef2f7;
            background:#fff;
            border-radius:14px;
            padding:13px;
            margin-bottom:13px;
        }

        .form-section h6{
            font-weight:950;
            font-size:13px;
            color:#111827;
            margin:0 0 12px;
            padding-bottom:8px;
            border-bottom:1px solid #eef2f7;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .form-section h6 i{
            color:var(--blue);
            font-size:15px;
        }

        .form-label{
            font-size:11px;
            font-weight:900;
            color:#475569;
            text-transform:uppercase;
            margin-bottom:6px;
        }

        .required:after{
            content:" *";
            color:var(--red);
            font-weight:950;
        }

        .form-control,.form-select{
            min-height:38px;
            border:1px solid var(--border);
            border-radius:11px;
            font-size:12px;
            font-weight:800;
            color:#111827;
            padding:8px 11px;
            background:#fff;
        }

        .form-control:focus,.form-select:focus{
            border-color:#bfdbfe;
            box-shadow:0 0 0 3px rgba(59,130,246,.10);
        }

        textarea.form-control{ min-height:92px; }

        .role-badge,.badge-pill{
            border-radius:999px;
            padding:5px 8px;
            font-weight:900;
            font-size:10px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            border:1px solid #bfdbfe;
            color:#2563eb;
            background:#dbeafe;
            white-space:nowrap;
        }

        .info-note{
            background:#eff6ff;
            border:1px solid #bfdbfe;
            border-radius:13px;
            padding:11px 13px;
            margin-bottom:14px;
            display:flex;
            align-items:center;
            gap:10px;
        }

        .info-note i{
            color:#2563eb;
            font-size:18px;
        }

        .info-note p{
            margin:0;
            color:#1e293b;
            font-weight:750;
            font-size:11.5px;
        }

        .submit-strip{
            background:#fff;
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:13px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-top:14px;
        }

        .submit-note{
            color:#64748b;
            font-size:11px;
            font-weight:750;
            margin:0;
        }

        .alert{
            border-radius:14px;
            border:1px solid transparent;
            box-shadow:var(--shadow);
            font-size:12px;
            font-weight:850;
            margin-bottom:14px;
        }

        .alert-danger{
            background:#fee2e2;
            border-color:#fecaca;
            color:#991b1b;
        }

        .alert-success{
            background:#dcfce7;
            border-color:#bbf7d0;
            color:#166534;
        }

        @media(max-width:991.98px){
            .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
            .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
            .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0)!important; }
        }

        @media(max-width:768px){
            .content-scroll{ padding:12px 10px!important; }
            .container-fluid.projects-wrapper{ padding-left:0!important; padding-right:0!important; }
            .page-heading,.submit-strip{ flex-direction:column; align-items:flex-start; }
            .panel,.form-section{ padding:12px; }
            .primary-btn,.secondary-btn,.submit-btn{ width:100%; }
            .form-actions{ flex-direction:column-reverse; align-items:stretch!important; }
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content-scroll">
            <div class="container-fluid projects-wrapper px-0">

                <!-- Page Header -->
                <div class="page-heading">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h1><i class="bi bi-plus-circle"></i> New Hiring Request</h1>
                            <span class="role-badge">
                                <i class="bi bi-shield-check"></i>
                                <?php echo e($userRole); ?>
                            </span>
                        </div>
                        <p>Create a new position requisition for HR approval and recruitment tracking.</p>
                    </div>

                    <a href="hiring-requests.php" class="secondary-btn">
                        <i class="bi bi-arrow-left"></i>
                        Back to Requests
                    </a>
                </div>

                <!-- Alert Message -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
                        <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
                        <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">
                                <i class="bi bi-briefcase"></i>
                                Request Details
                            </h3>
                            <div class="panel-subtitle">Fill the position details, experience range, and hiring reason.</div>
                        </div>
                    </div>

                    <div class="info-note">
                        <i class="bi bi-info-circle"></i>
                        <p><?php echo $isHr ? 'After submission, the hiring request will be saved as Pending and visible in the hiring requests page.' : 'After submission, HR employees will be notified and the request will stay Pending for HR review.'; ?></p>
                    </div>

                    <form method="POST" action="" id="hiringRequestForm">
                        <input type="hidden" name="action" value="create_request">

                        <!-- Position Details -->
                        <div class="form-section">
                            <h6><i class="bi bi-briefcase me-2"></i>Position Details</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label required">Department</label>
                                    <select name="department" class="form-select" required>
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo e($dept); ?>" <?php echo (($_POST['department'] ?? '') === $dept) ? 'selected' : ''; ?>><?php echo e($dept); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required">Designation</label>
                                    <input type="text" name="designation" class="form-control" value="<?php echo e($_POST['designation'] ?? ''); ?>" placeholder="e.g., Senior Engineer" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required">Position Title</label>
                                    <input type="text" name="position_title" class="form-control" value="<?php echo e($_POST['position_title'] ?? ''); ?>" placeholder="e.g., Project Engineer" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">No. of Vacancies</label>
                                    <input type="number" name="vacancies" class="form-control" min="1" value="<?php echo e($_POST['vacancies'] ?? '1'); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">Employment Type</label>
                                    <select name="employment_type" class="form-select" required>
                                        <option value="">Select Type</option>
                                        <?php foreach ($employment_types as $type): ?>
                                            <option value="<?php echo e($type); ?>" <?php echo (($_POST['employment_type'] ?? '') === $type) ? 'selected' : ''; ?>><?php echo e($type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">Priority</label>
                                    <select name="priority" class="form-select" required>
                                        <?php foreach ($priorities as $p): ?>
                                            <option value="<?php echo e($p); ?>" <?php echo (($_POST['priority'] ?? 'Medium') === $p) ? 'selected' : ''; ?>>
                                                <?php echo $p; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Expected Joining Date</label>
                                    <input type="date" name="expected_joining_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" value="<?php echo e($_POST['expected_joining_date'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Experience & Salary -->
                        <div class="form-section">
                            <h6><i class="bi bi-bar-chart me-2"></i>Experience & Compensation</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label required">Min Experience (years)</label>
                                    <input type="number" name="experience_min" class="form-control" min="0" step="0.5" value="<?php echo e($_POST['experience_min'] ?? '0'); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">Max Experience (years)</label>
                                    <input type="number" name="experience_max" class="form-control" min="0" step="0.5" value="<?php echo e($_POST['experience_max'] ?? '2'); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Min Salary (₹ LPA)</label>
                                    <input type="number" name="salary_min" class="form-control" min="0" step="0.1" value="<?php echo e($_POST['salary_min'] ?? ''); ?>" placeholder="e.g., 3.5">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Max Salary (₹ LPA)</label>
                                    <input type="number" name="salary_max" class="form-control" min="0" step="0.1" value="<?php echo e($_POST['salary_max'] ?? ''); ?>" placeholder="e.g., 6.0">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label required">Location</label>
                                    <input type="text" name="location" class="form-control" value="<?php echo e($_POST['location'] ?? ''); ?>" placeholder="e.g., Bangalore, Mumbai, Remote" required>
                                </div>
                            </div>
                        </div>

                        <!-- Qualifications & Skills -->
                        <div class="form-section">
                            <h6><i class="bi bi-mortarboard me-2"></i>Qualifications & Skills</h6>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label required">Job Description</label>
                                    <textarea name="job_description" class="form-control" rows="4" placeholder="Describe the role, responsibilities, etc." required><?php echo e($_POST['job_description'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Qualification Required</label>
                                    <textarea name="qualification" class="form-control" rows="3" placeholder="e.g., B.E/B.Tech in Civil Engineering"><?php echo e($_POST['qualification'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Skills Required</label>
                                    <textarea name="skills_required" class="form-control" rows="3" placeholder="List key skills required (comma separated)"><?php echo e($_POST['skills_required'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Hiring Reason -->
                        <div class="form-section">
                            <h6><i class="bi bi-question-circle me-2"></i>Hiring Details</h6>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label required">Reason for Hiring</label>
                                    <select name="reason_for_hiring" class="form-select" required>
                                        <option value="">Select Reason</option>
                                        <option value="New Position" <?php echo (($_POST['reason_for_hiring'] ?? '') === 'New Position') ? 'selected' : ''; ?>>New Position</option>
                                        <option value="Replacement" <?php echo (($_POST['reason_for_hiring'] ?? '') === 'Replacement') ? 'selected' : ''; ?>>Replacement</option>
                                        <option value="Project Expansion" <?php echo (($_POST['reason_for_hiring'] ?? '') === 'Project Expansion') ? 'selected' : ''; ?>>Project Expansion</option>
                                        <option value="Backfill" <?php echo (($_POST['reason_for_hiring'] ?? '') === 'Backfill') ? 'selected' : ''; ?>>Backfill</option>
                                        <option value="Seasonal" <?php echo (($_POST['reason_for_hiring'] ?? '') === 'Seasonal') ? 'selected' : ''; ?>>Seasonal</option>
                                        <option value="Other" <?php echo (($_POST['reason_for_hiring'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-12" id="replacementField" style="display:none;">
                                    <label class="form-label">Replacement For (Employee Name/Code)</label>
                                    <input type="text" name="replacement_for" class="form-control" value="<?php echo e($_POST['replacement_for'] ?? ''); ?>" placeholder="Enter employee name or code">
                                </div>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="submit-strip">
                            <p class="submit-note">
                                <i class="bi bi-info-circle me-1"></i>
                                <?php echo $isHr ? 'Required fields are marked with *. Request will be saved as Pending.' : 'Required fields are marked with *. HR will receive a notification after submission.'; ?>
                            </p>

                            <div class="d-flex gap-2 form-actions">
                                <button type="reset" class="secondary-btn">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                    Reset
                                </button>
                                <button type="submit" class="submit-btn" id="submitHiringBtn">
                                    <i class="bi bi-send"></i>
                                    Submit Request
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const reasonSelect = document.querySelector('select[name="reason_for_hiring"]');
    const replacementField = document.getElementById('replacementField');
    const replacementInput = document.querySelector('input[name="replacement_for"]');
    const form = document.getElementById('hiringRequestForm');
    const submitBtn = document.getElementById('submitHiringBtn');

    function toggleReplacementField() {
        if (!reasonSelect || !replacementField || !replacementInput) return;

        if (reasonSelect.value === 'Replacement') {
            replacementField.style.display = '';
            replacementInput.required = true;
        } else {
            replacementField.style.display = 'none';
            replacementInput.required = false;
            replacementInput.value = '';
        }
    }

    if (reasonSelect) {
        reasonSelect.addEventListener('change', toggleReplacementField);
        toggleReplacementField();
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            const minExp = parseFloat(document.querySelector('input[name="experience_min"]')?.value || '0');
            const maxExp = parseFloat(document.querySelector('input[name="experience_max"]')?.value || '0');

            if (maxExp < minExp) {
                e.preventDefault();
                alert('Maximum experience cannot be less than minimum experience');
                return;
            }

            const minSalary = parseFloat(document.querySelector('input[name="salary_min"]')?.value || '0');
            const maxSalary = parseFloat(document.querySelector('input[name="salary_max"]')?.value || '0');

            if (maxSalary > 0 && minSalary > 0 && maxSalary < minSalary) {
                e.preventDefault();
                alert('Maximum salary cannot be less than minimum salary');
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Submitting...';
            }
        });
    }

    const yearElement = document.getElementById('year');
    if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
    }
});
</script>


</body>
</html>