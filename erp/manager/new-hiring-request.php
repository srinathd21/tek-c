<?php
// hr/new-hiring-request.php
session_start();

require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
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
    'administrator'
]);

if (!$isHr && !$isManager) {
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

            $year = date('Y');
            $month = date('m');

            $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as count FROM hiring_requests WHERE YEAR(created_at) = ?");
            mysqli_stmt_bind_param($stmt, "i", $year);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);

            $count = $row['count'] + 1;

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

            $types = "ssssiisiiddssssssisss";

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

                logActivity(
                    $conn,
                    'CREATE',
                    'hiring',
                    "Created hiring request: {$request_no} for {$position_title}",
                    $request_id,
                    $request_no,
                    null,
                    json_encode($_POST)
                );

                $_SESSION['flash_success'] = "Hiring request created successfully! Request #: {$request_no}";
                header("Location: hiring-requests.php");
                exit;

            } else {

                $message = "Error creating request: " . mysqli_error($conn);
                $messageType = "danger";
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

$userRole = $isHr ? 'HR' : 'Manager';
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px; }
        .panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 8px 24px rgba(17,24,39,.06); padding:24px; }
        .panel-header{ margin-bottom:20px; }
        .panel-title{ font-weight:900; font-size:20px; color:#1f2937; margin:0; }

        .form-section{ background:#f9fafb; border-radius:12px; padding:20px; margin-bottom:24px; border:1px solid #e5e7eb; }
        .form-section h6{ font-weight:800; color:#4b5563; margin-bottom:16px; padding-bottom:8px; border-bottom:1px solid #e5e7eb; }

        .form-label{ font-weight:700; font-size:13px; color:#4b5563; margin-bottom:4px; }
        .required:after{ content:" *"; color:#dc3545; }

        .btn-submit{ padding:12px 30px; font-weight:800; }

        .role-badge{ font-size:11px; padding:4px 8px; border-radius:20px; font-weight:700; background:#dbeafe; color:#1e40af; }

        @media (max-width: 768px) {
            .content-scroll{ padding:12px; }
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content-scroll">
            <div class="container-fluid maxw">

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold mb-1">
                            <i class="bi bi-plus-circle me-2"></i>
                            New Hiring Request
                        </h1>
                        <div class="d-flex align-items-center gap-2">
                            <p class="text-muted mb-0">Create a new position requisition</p>
                            <span class="role-badge"><i class="bi bi-shield-check me-1"></i> <?php echo $userRole; ?></span>
                        </div>
                    </div>
                    <a href="hiring-requests.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Requests
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
                                            <option value="<?php echo $dept; ?>"><?php echo $dept; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required">Designation</label>
                                    <input type="text" name="designation" class="form-control" placeholder="e.g., Senior Engineer" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required">Position Title</label>
                                    <input type="text" name="position_title" class="form-control" placeholder="e.g., Project Engineer" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">No. of Vacancies</label>
                                    <input type="number" name="vacancies" class="form-control" min="1" value="1" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">Employment Type</label>
                                    <select name="employment_type" class="form-select" required>
                                        <option value="">Select Type</option>
                                        <?php foreach ($employment_types as $type): ?>
                                            <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">Priority</label>
                                    <select name="priority" class="form-select" required>
                                        <?php foreach ($priorities as $p): ?>
                                            <option value="<?php echo $p; ?>" <?php echo $p === 'Medium' ? 'selected' : ''; ?>>
                                                <?php echo $p; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Expected Joining Date</label>
                                    <input type="date" name="expected_joining_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Experience & Salary -->
                        <div class="form-section">
                            <h6><i class="bi bi-bar-chart me-2"></i>Experience & Compensation</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label required">Min Experience (years)</label>
                                    <input type="number" name="experience_min" class="form-control" min="0" step="0.5" value="0" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label required">Max Experience (years)</label>
                                    <input type="number" name="experience_max" class="form-control" min="0" step="0.5" value="2" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Min Salary (₹ LPA)</label>
                                    <input type="number" name="salary_min" class="form-control" min="0" step="0.1" placeholder="e.g., 3.5">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Max Salary (₹ LPA)</label>
                                    <input type="number" name="salary_max" class="form-control" min="0" step="0.1" placeholder="e.g., 6.0">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label required">Location</label>
                                    <input type="text" name="location" class="form-control" placeholder="e.g., Bangalore, Mumbai, Remote" required>
                                </div>
                            </div>
                        </div>

                        <!-- Qualifications & Skills -->
                        <div class="form-section">
                            <h6><i class="bi bi-mortarboard me-2"></i>Qualifications & Skills</h6>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label required">Job Description</label>
                                    <textarea name="job_description" class="form-control" rows="4" placeholder="Describe the role, responsibilities, etc." required></textarea>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Qualification Required</label>
                                    <textarea name="qualification" class="form-control" rows="3" placeholder="e.g., B.E/B.Tech in Civil Engineering"></textarea>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Skills Required</label>
                                    <textarea name="skills_required" class="form-control" rows="3" placeholder="List key skills required (comma separated)"></textarea>
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
                                        <option value="New Position">New Position</option>
                                        <option value="Replacement">Replacement</option>
                                        <option value="Project Expansion">Project Expansion</option>
                                        <option value="Backfill">Backfill</option>
                                        <option value="Seasonal">Seasonal</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-12" id="replacementField" style="display:none;">
                                    <label class="form-label">Replacement For (Employee Name/Code)</label>
                                    <input type="text" name="replacement_for" class="form-control" placeholder="Enter employee name or code">
                                </div>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <button type="reset" class="btn btn-outline-secondary btn-submit">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset
                            </button>
                            <button type="submit" class="btn btn-primary btn-submit">
                                <i class="bi bi-send"></i> Submit Request
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(document).ready(function() {
    // Show/hide replacement field based on reason selection
    $('select[name="reason_for_hiring"]').change(function() {
        if ($(this).val() === 'Replacement') {
            $('#replacementField').show();
            $('input[name="replacement_for"]').prop('required', true);
        } else {
            $('#replacementField').hide();
            $('input[name="replacement_for"]').prop('required', false);
        }
    });

    // Validate experience range
    $('form').submit(function(e) {
        var minExp = parseFloat($('input[name="experience_min"]').val());
        var maxExp = parseFloat($('input[name="experience_max"]').val());
        
        if (maxExp < minExp) {
            e.preventDefault();
            alert('Maximum experience cannot be less than minimum experience');
            return false;
        }
        
        var minSalary = parseFloat($('input[name="salary_min"]').val()) || 0;
        var maxSalary = parseFloat($('input[name="salary_max"]').val()) || 0;
        
        if (maxSalary > 0 && minSalary > 0 && maxSalary < minSalary) {
            e.preventDefault();
            alert('Maximum salary cannot be less than minimum salary');
            return false;
        }
    });

    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%'
    });
    
});

</script>

</body>
</html>