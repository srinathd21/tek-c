<?php
// hr/view-onboarding.php - View Onboarding Details
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];

// Get current employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$current_employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die("Employee not found.");
}

// Check permissions
$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department = strtolower(trim($current_employee['department'] ?? ''));

$isHr = ($designation === 'hr' || $department === 'hr');
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'director', 'administrator']);
$isAdmin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director');

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- GET ONBOARDING ID ----------------
$onboarding_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($onboarding_id <= 0) {
    header("Location: onboarding.php");
    exit;
}

// ---------------- FETCH ONBOARDING DETAILS ----------------
$query = "
    SELECT 
        o.*,
        c.id as candidate_id,
        c.first_name,
        c.last_name,
        c.candidate_code,
        c.email as candidate_email,
        c.phone as candidate_phone,
        c.alternate_phone,
        c.current_location,
        c.total_experience,
        c.relevant_experience,
        c.current_ctc,
        c.expected_ctc,
        c.notice_period,
        c.current_company,
        c.qualification,
        c.skills,
        c.resume_path,
        c.photo_path as candidate_photo,
        c.source,
        c.referred_by,
        c.status as candidate_status,
        c.remarks as candidate_remarks,
        h.id as hiring_request_id,
        h.request_no,
        h.position_title,
        h.department as hiring_department,
        h.designation as hiring_designation,
        h.vacancies,
        h.employment_type,
        h.experience_min,
        h.experience_max,
        h.location as hiring_location,
        h.job_description,
        h.qualification as hiring_qualification,
        h.skills_required,
        h.priority,
        h.reason_for_hiring,
        offr.id as offer_id,
        offr.offer_no,
        offr.offer_date,
        offr.offer_valid_till,
        offr.expected_joining_date,
        offr.designation as offer_designation,
        offr.department as offer_department,
        offr.employment_type as offer_employment_type,
        offr.ctc as offer_ctc,
        offr.basic_salary,
        offr.hra,
        offr.conveyance,
        offr.medical,
        offr.special_allowance,
        offr.bonus,
        offr.other_benefits,
        offr.terms_conditions,
        offr.offer_document,
        offr.status as offer_status,
        offr.response_date,
        offr.response_remarks,
        reporting_emp.full_name as reporting_to_name,
        reporting_emp.designation as reporting_to_designation,
        reporting_emp.employee_code as reporting_to_code,
        creator.full_name as created_by_name,
        creator.designation as created_by_designation,
        completed_by_emp.full_name as completed_by_name
    FROM onboarding o
    JOIN candidates c ON o.candidate_id = c.id
    JOIN hiring_requests h ON o.hiring_request_id = h.id
    LEFT JOIN offers offr ON o.offer_id = offr.id
    LEFT JOIN employees reporting_emp ON o.reporting_to = reporting_emp.id
    LEFT JOIN employees creator ON o.created_by = creator.id
    LEFT JOIN employees completed_by_emp ON o.completed_by = completed_by_emp.id
    WHERE o.id = ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $onboarding_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$onboarding = mysqli_fetch_assoc($result);

if (!$onboarding) {
    header("Location: onboarding.php?error=not_found");
    exit;
}

// Parse documents JSON
$documents = [];
if (!empty($onboarding['documents_json'])) {
    $documents = json_decode($onboarding['documents_json'], true);
}

// Calculate progress
$checklist_items = [
    'id_card_issued' => $onboarding['id_card_issued'],
    'email_created' => $onboarding['email_created'],
    'system_access_given' => $onboarding['system_access_given'],
    'biometric_enrolled' => $onboarding['biometric_enrolled'],
    'orientation_completed' => $onboarding['orientation_completed'],
    'training_completed' => $onboarding['training_completed'],
    'welcome_kit_issued' => $onboarding['welcome_kit_issued']
];
$completed_count = array_sum($checklist_items);
$total_items = count($checklist_items);
$progress_percentage = ($completed_count / $total_items) * 100;

// Log view activity
logActivity(
    $conn,
    'VIEW',
    'onboarding',
    "Viewed onboarding: {$onboarding['onboarding_no']}",
    $onboarding_id,
    null,
    null,
    null
);

// ---------------- HELPER FUNCTIONS ----------------
function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function formatDate($date)
{
    if (!$date || $date == '0000-00-00')
        return '—';
    return date('d M Y', strtotime($date));
}

function formatDateTime($datetime)
{
    if (!$datetime)
        return '—';
    return date('d M Y h:i A', strtotime($datetime));
}

function formatCurrency($amount)
{
    if (!$amount)
        return '—';
    return '₹ ' . number_format($amount, 2) . ' LPA';
}

function getStatusBadge($status)
{
    $classes = [
        'Pending' => 'bg-warning text-dark',
        'In Progress' => 'bg-info',
        'Completed' => 'bg-success',
        'Cancelled' => 'bg-danger',
        'Accepted' => 'bg-success',
        'Rejected' => 'bg-danger',
        'Sent' => 'bg-primary',
        'Draft' => 'bg-secondary'
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    return "<span class='badge {$class} px-3 py-2'>{$status}</span>";
}

function getDocumentStatusIcon($doc)
{
    if (!empty($doc) && isset($doc['file'])) {
        return '<i class="bi bi-check-circle-fill text-success fs-5" title="Uploaded"></i>';
    }
    return '<i class="bi bi-clock-history text-warning fs-5" title="Pending"></i>';
}

function getDocumentDownloadLink($doc)
{
    if (!empty($doc) && isset($doc['file'])) {
        return '<a href="../' . e($doc['file']) . '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> Download</a>';
    }
    return '<button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-cloud-upload"></i> Not Uploaded</button>';
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Onboarding Details - <?php echo e($onboarding['onboarding_no']); ?> | TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll {
            flex: 1 1 auto;
            overflow: auto;
            padding: 22px;
        }

        .detail-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .detail-header {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
        }

        .detail-title {
            font-weight: 900;
            font-size: 16px;
            color: #1f2937;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-title i {
            color: var(--blue);
            font-size: 20px;
        }

        .detail-body {
            padding: 20px 24px;
        }

        .info-row {
            display: flex;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #e2e8f0;
        }

        .info-label {
            width: 180px;
            font-weight: 800;
            color: #4b5563;
            font-size: 13px;
        }

        .info-value {
            flex: 1;
            color: #1f2937;
            font-weight: 600;
            font-size: 13px;
        }

        .info-value a {
            color: var(--blue);
            text-decoration: none;
        }

        .info-value a:hover {
            text-decoration: underline;
        }

        .progress-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .progress-card .detail-title i {
            color: white;
        }

        .progress-circle {
            width: 120px;
            height: 120px;
        }

        .document-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }

        .document-item:hover {
            background: #f1f5f9;
            border-color: var(--blue);
        }

        .document-name {
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 5px;
        }

        .document-meta {
            font-size: 11px;
            color: #6b7280;
        }

        .checklist-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .checklist-item:last-child {
            border-bottom: none;
        }

        .checklist-label {
            font-weight: 700;
            color: #374151;
        }

        .btn-back {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 16px;
            color: #4a5568;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-back:hover {
            background: var(--bg);
            color: var(--blue);
            border-color: var(--blue);
        }

        .btn-edit {
            background: var(--blue);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-edit:hover {
            background: #2a8bc9;
            color: white;
        }

        .status-timeline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 20px 0;
        }

        .timeline-step {
            text-align: center;
            flex: 1;
            position: relative;
        }

        .timeline-step .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            color: #6b7280;
            font-size: 18px;
        }

        .timeline-step.completed .step-icon {
            background: #10b981;
            color: white;
        }

        .timeline-step.active .step-icon {
            background: #3b82f6;
            color: white;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2);
        }

        .timeline-step .step-label {
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
        }

        .timeline-step.completed .step-label {
            color: #10b981;
        }

        .timeline-step.active .step-label {
            color: #3b82f6;
        }

        .timeline-connector {
            position: absolute;
            top: 20px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #e5e7eb;
            z-index: 0;
        }

        .timeline-step:last-child .timeline-connector {
            display: none;
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

                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-dark mb-1">Onboarding Details</h1>
                            <p class="text-muted mb-0">
                                <i class="bi bi-hash"></i> <?php echo e($onboarding['onboarding_no']); ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="onboarding.php" class="btn-back">
                                <i class="bi bi-arrow-left"></i> Back to List
                            </a>
                            <?php if ($onboarding['status'] !== 'Completed' && $onboarding['status'] !== 'Cancelled' && ($isHr || $isAdmin)): ?>
                                <a href="onboarding.php?edit=<?php echo $onboarding_id; ?>" class="btn-edit">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Status Timeline -->
                    <div class="detail-card">
                        <div class="detail-body">
                            <div class="status-timeline">
                                <?php
                                $statuses = ['Pending', 'In Progress', 'Completed'];
                                $current_status = $onboarding['status'];
                                $current_index = array_search($current_status, $statuses);
                                ?>
                                <?php foreach ($statuses as $index => $status): ?>
                                    <div class="timeline-step <?php echo $index <= $current_index ? 'completed' : ''; ?> <?php echo $index == $current_index ? 'active' : ''; ?>">
                                        <div class="timeline-connector"></div>
                                        <div class="step-icon">
                                            <?php if ($index <= $current_index && $status != 'Pending'): ?>
                                                <i class="bi bi-check-lg"></i>
                                            <?php elseif ($index == $current_index): ?>
                                                <i class="bi bi-hourglass-split"></i>
                                            <?php else: ?>
                                                <i class="bi bi-clock"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="step-label"><?php echo $status; ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Card -->
                    <div class="detail-card progress-card">
                        <div class="detail-header" style="background: rgba(255,255,255,0.1);">
                            <h3 class="detail-title text-white">
                                <i class="bi bi-graph-up"></i> Onboarding Progress
                            </h3>
                        </div>
                        <div class="detail-body">
                            <div class="row align-items-center">
                                <div class="col-md-3 text-center">
                                    <div class="position-relative">
                                        <h1 class="display-4 fw-bold mb-0"><?php echo round($progress_percentage); ?>%</h1>
                                        <p class="text-white-50 mb-0">Complete</p>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    <div class="progress mb-3" style="height: 10px;">
                                        <div class="progress-bar bg-white" style="width: <?php echo $progress_percentage; ?>%"></div>
                                    </div>
                                    <p class="mb-0 text-white-50">
                                        <i class="bi bi-check-circle"></i> <?php echo $completed_count; ?> of <?php echo $total_items; ?> checklist items completed
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Candidate Information -->
                    <div class="detail-card">
                        <div class="detail-header">
                            <h3 class="detail-title">
                                <i class="bi bi-person-badge"></i> Candidate Information
                            </h3>
                        </div>
                        <div class="detail-body">
                            <div class="row">
                                <div class="col-md-3 text-center mb-3 mb-md-0">
                                    <div class="candidate-avatar mx-auto" style="width: 120px; height: 120px; font-size: 48px;">
                                        <?php if (!empty($onboarding['candidate_photo'])): ?>
                                            <img src="../<?php echo e($onboarding['candidate_photo']); ?>" alt="Photo" style="width: 100%; height: 100%; object-fit: cover;">
                                        <?php else: ?>
                                            <?php echo substr($onboarding['first_name'], 0, 1) . substr($onboarding['last_name'], 0, 1); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($onboarding['resume_path'])): ?>
                                        <a href="../<?php echo e($onboarding['resume_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary mt-2">
                                            <i class="bi bi-file-pdf"></i> View Resume
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-9">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="info-row">
                                                <div class="info-label">Full Name:</div>
                                                <div class="info-value"><?php echo e($onboarding['first_name'] . ' ' . $onboarding['last_name']); ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Candidate Code:</div>
                                                <div class="info-value"><?php echo e($onboarding['candidate_code']); ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Email:</div>
                                                <div class="info-value"><?php echo e($onboarding['candidate_email']); ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Phone:</div>
                                                <div class="info-value"><?php echo e($onboarding['candidate_phone']); ?></div>
                                            </div>
                                            <?php if (!empty($onboarding['alternate_phone'])): ?>
                                                <div class="info-row">
                                                    <div class="info-label">Alternate Phone:</div>
                                                    <div class="info-value"><?php echo e($onboarding['alternate_phone']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-row">
                                                <div class="info-label">Current Location:</div>
                                                <div class="info-value"><?php echo e($onboarding['current_location'] ?: '—'); ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Total Experience:</div>
                                                <div class="info-value"><?php echo e($onboarding['total_experience'] ? $onboarding['total_experience'] . ' years' : '—'); ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Current Company:</div>
                                                <div class="info-value"><?php echo e($onboarding['current_company'] ?: '—'); ?></div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-label">Source:</div>
                                                <div class="info-value"><?php echo e($onboarding['source'] ?: '—'); ?></div>
                                            </div>
                                            <?php if (!empty($onboarding['referred_by'])): ?>
                                                <div class="info-row">
                                                    <div class="info-label">Referred By:</div>
                                                    <div class="info-value"><?php echo e($onboarding['referred_by']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Offer Details -->
                    <?php if ($onboarding['offer_id']): ?>
                        <div class="detail-card">
                            <div class="detail-header">
                                <h3 class="detail-title">
                                    <i class="bi bi-file-text"></i> Offer Details
                                </h3>
                            </div>
                            <div class="detail-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <div class="info-label">Offer Number:</div>
                                            <div class="info-value"><?php echo e($onboarding['offer_no']); ?></div>
                                        </div>
                                        <div class="info-row">
                                            <div class="info-label">Offer Date:</div>
                                            <div class="info-value"><?php echo formatDate($onboarding['offer_date']); ?></div>
                                        </div>
                                        <div class="info-row">
                                            <div class="info-label">Valid Till:</div>
                                            <div class="info-value"><?php echo formatDate($onboarding['offer_valid_till']); ?></div>
                                        </div>
                                        <div class="info-row">
                                            <div class="info-label">Expected Joining:</div>
                                            <div class="info-value"><?php echo formatDate($onboarding['expected_joining_date']); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <div class="info-label">Designation:</div>
                                            <div class="info-value"><?php echo e($onboarding['offer_designation']); ?></div>
                                        </div>
                                        <div class="info-row">
                                            <div class="info-label">Department:</div>
                                            <div class="info-value"><?php echo e($onboarding['offer_department']); ?></div>
                                        </div>
                                        <div class="info-row">
                                            <div class="info-label">CTC:</div>
                                            <div class="info-value"><?php echo formatCurrency($onboarding['offer_ctc']); ?></div>
                                        </div>
                                        <div class="info-row">
                                            <div class="info-label">Offer Status:</div>
                                            <div class="info-value"><?php echo getStatusBadge($onboarding['offer_status']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($onboarding['offer_document'])): ?>
                                    <div class="mt-3">
                                        <a href="../<?php echo e($onboarding['offer_document']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-pdf"></i> Download Offer Document
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Onboarding Details -->
                    <div class="detail-card">
                        <div class="detail-header">
                            <h3 class="detail-title">
                                <i class="bi bi-calendar-check"></i> Onboarding Details
                            </h3>
                        </div>
                        <div class="detail-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Onboarding Number:</div>
                                        <div class="info-value"><?php echo e($onboarding['onboarding_no']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Joining Date:</div>
                                        <div class="info-value">
                                            <strong><?php echo formatDate($onboarding['joining_date']); ?></strong>
                                            <?php if ($onboarding['joining_date'] < date('Y-m-d') && $onboarding['status'] != 'Completed'): ?>
                                                <span class="badge bg-danger ms-2">Overdue</span>
                                            <?php elseif ($onboarding['joining_date'] <= date('Y-m-d', strtotime('+7 days')) && $onboarding['status'] != 'Completed'): ?>
                                                <span class="badge bg-warning ms-2">Upcoming</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Reporting Time:</div>
                                        <div class="info-value"><?php echo date('h:i A', strtotime($onboarding['reporting_time'])); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Reporting To:</div>
                                        <div class="info-value">
                                            <?php if (!empty($onboarding['reporting_to_name'])): ?>
                                                <strong><?php echo e($onboarding['reporting_to_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo e($onboarding['reporting_to_designation']); ?></small>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Employee Code:</div>
                                        <div class="info-value">
                                            <?php if (!empty($onboarding['employee_code'])): ?>
                                                <span class="badge bg-success"><?php echo e($onboarding['employee_code']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Not generated yet</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Created By:</div>
                                        <div class="info-value"><?php echo e($onboarding['created_by_name']); ?> (<?php echo e($onboarding['created_by_designation']); ?>)</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Created At:</div>
                                        <div class="info-value"><?php echo formatDateTime($onboarding['created_at']); ?></div>
                                    </div>
                                </div>
                                <?php if ($onboarding['status'] === 'Completed'): ?>
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <div class="info-label">Completed By:</div>
                                            <div class="info-value"><?php echo e($onboarding['completed_by_name'] ?: '—'); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <div class="info-label">Completed At:</div>
                                            <div class="info-value"><?php echo formatDate($onboarding['completed_at']); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($onboarding['remarks'])): ?>
                                <div class="info-row mt-3">
                                    <div class="info-label">Remarks:</div>
                                    <div class="info-value"><?php echo nl2br(e($onboarding['remarks'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Documents Section -->
                    <div class="detail-card">
                        <div class="detail-header">
                            <h3 class="detail-title">
                                <i class="bi bi-files"></i> Documents
                            </h3>
                        </div>
                        <div class="detail-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="document-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="document-name">
                                                    <?php echo getDocumentStatusIcon($documents['aadhar'] ?? null); ?>
                                                    Aadhar Card
                                                </div>
                                                <?php if (!empty($documents['aadhar'])): ?>
                                                    <div class="document-meta">
                                                        Uploaded: <?php echo formatDateTime($documents['aadhar']['uploaded_at'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo getDocumentDownloadLink($documents['aadhar'] ?? null); ?>
                                        </div>
                                    </div>
                                    <div class="document-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="document-name">
                                                    <?php echo getDocumentStatusIcon($documents['pan'] ?? null); ?>
                                                    PAN Card
                                                </div>
                                                <?php if (!empty($documents['pan'])): ?>
                                                    <div class="document-meta">
                                                        Uploaded: <?php echo formatDateTime($documents['pan']['uploaded_at'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo getDocumentDownloadLink($documents['pan'] ?? null); ?>
                                        </div>
                                    </div>
                                    <div class="document-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="document-name">
                                                    <?php echo getDocumentStatusIcon($documents['degree'] ?? null); ?>
                                                    Degree Certificate
                                                </div>
                                                <?php if (!empty($documents['degree'])): ?>
                                                    <div class="document-meta">
                                                        Uploaded: <?php echo formatDateTime($documents['degree']['uploaded_at'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo getDocumentDownloadLink($documents['degree'] ?? null); ?>
                                        </div>
                                    </div>
                                    <div class="document-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="document-name">
                                                    <?php echo getDocumentStatusIcon($documents['experience'] ?? null); ?>
                                                    Experience Letters
                                                </div>
                                                <?php if (!empty($documents['experience'])): ?>
                                                    <div class="document-meta">
                                                        Uploaded: <?php echo formatDateTime($documents['experience']['uploaded_at'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo getDocumentDownloadLink($documents['experience'] ?? null); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="document-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="document-name">
                                                    <?php echo getDocumentStatusIcon($documents['photo'] ?? null); ?>
                                                    Photograph
                                                </div>
                                                <?php if (!empty($documents['photo'])): ?>
                                                    <div class="document-meta">
                                                        Uploaded: <?php echo formatDateTime($documents['photo']['uploaded_at'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo getDocumentDownloadLink($documents['photo'] ?? null); ?>
                                        </div>
                                    </div>
                                    <div class="document-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="document-name">
                                                    <?php echo getDocumentStatusIcon($documents['offer_acceptance'] ?? null); ?>
                                                    Offer Acceptance
                                                </div>
                                                <?php if (!empty($documents['offer_acceptance'])): ?>
                                                    <div class="document-meta">
                                                        Uploaded: <?php echo formatDateTime($documents['offer_acceptance']['uploaded_at'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo getDocumentDownloadLink($documents['offer_acceptance'] ?? null); ?>
                                        </div>
                                    </div>
                                    <div class="document-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="document-name">
                                                    <?php echo getDocumentStatusIcon($documents['bank'] ?? null); ?>
                                                    Bank Details / Passbook
                                                </div>
                                                <?php if (!empty($documents['bank'])): ?>
                                                    <div class="document-meta">
                                                        Uploaded: <?php echo formatDateTime($documents['bank']['uploaded_at'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo getDocumentDownloadLink($documents['bank'] ?? null); ?>
                                        </div>
                                    </div>
                                    <div class="document-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="document-name">
                                                    <?php echo getDocumentStatusIcon($documents['other'] ?? null); ?>
                                                    Other Documents
                                                </div>
                                                <?php if (!empty($documents['other'])): ?>
                                                    <div class="document-meta">
                                                        Uploaded: <?php echo formatDateTime($documents['other']['uploaded_at'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php echo getDocumentDownloadLink($documents['other'] ?? null); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-info mt-3 mb-0">
                                <i class="bi bi-info-circle"></i>
                                <strong>Required Documents:</strong> Aadhar Card, PAN Card, and Offer Acceptance are mandatory for onboarding completion.
                                <?php if ($onboarding['documents_submitted']): ?>
                                    <span class="badge bg-success ms-2">All Required Documents Submitted</span>
                                <?php else: ?>
                                    <span class="badge bg-warning ms-2">Pending Required Documents</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Checklist Section -->
                    <div class="detail-card">
                        <div class="detail-header">
                            <h3 class="detail-title">
                                <i class="bi bi-check2-square"></i> Onboarding Checklist
                            </h3>
                        </div>
                        <div class="detail-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="checklist-item">
                                        <span class="checklist-label">
                                            <i class="bi bi-credit-card"></i> ID Card Issued
                                        </span>
                                        <?php if ($onboarding['id_card_issued']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Done</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="checklist-item">
                                        <span class="checklist-label">
                                            <i class="bi bi-envelope"></i> Email Account Created
                                        </span>
                                        <?php if ($onboarding['email_created']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Done</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="checklist-item">
                                        <span class="checklist-label">
                                            <i class="bi bi-laptop"></i> System Access Granted
                                        </span>
                                        <?php if ($onboarding['system_access_given']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Done</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="checklist-item">
                                        <span class="checklist-label">
                                            <i class="bi bi-fingerprint"></i> Biometric Enrolled
                                        </span>
                                        <?php if ($onboarding['biometric_enrolled']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Done</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="checklist-item">
                                        <span class="checklist-label">
                                            <i class="bi bi-people"></i> Orientation Completed
                                        </span>
                                        <?php if ($onboarding['orientation_completed']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Done</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="checklist-item">
                                        <span class="checklist-label">
                                            <i class="bi bi-mortarboard"></i> Training Completed
                                        </span>
                                        <?php if ($onboarding['training_completed']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Done</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="checklist-item">
                                        <span class="checklist-label">
                                            <i class="bi bi-gift"></i> Welcome Kit Issued
                                        </span>
                                        <?php if ($onboarding['welcome_kit_issued']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Done</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons (if not completed) -->
                    <?php if ($onboarding['status'] !== 'Completed' && $onboarding['status'] !== 'Cancelled' && ($isHr || $isAdmin)): ?>
                        <div class="text-center mt-4 mb-5">
                            <div class="d-flex gap-3 justify-content-center">
                                <a href="onboarding.php?edit=<?php echo $onboarding_id; ?>" class="btn-primary-custom">
                                    <i class="bi bi-pencil"></i> Edit Onboarding
                                </a>
                                <button class="btn-success-custom" onclick="openDocumentModal()">
                                    <i class="bi bi-cloud-upload"></i> Upload Documents
                                </button>
                                <button class="btn-primary-custom" onclick="openChecklistModal()">
                                    <i class="bi bi-check2-square"></i> Update Checklist
                                </button>
                                <?php if ($onboarding['status'] === 'Pending' || $onboarding['status'] === 'In Progress'): ?>
                                    <button class="btn-success-custom" onclick="openCompleteModal()">
                                        <i class="bi bi-check-lg"></i> Complete Onboarding
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Document Upload Modal -->
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="onboarding.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_documents">
                    <input type="hidden" name="onboarding_id" value="<?php echo $onboarding_id; ?>">

                    <div class="modal-header">
                        <h5 class="modal-title">Upload Documents</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Aadhar Card</label>
                                <input type="file" name="doc_aadhar" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">PAN Card</label>
                                <input type="file" name="doc_pan" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Degree Certificate</label>
                                <input type="file" name="doc_degree" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Experience Letters</label>
                                <input type="file" name="doc_experience" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Photograph</label>
                                <input type="file" name="doc_photo" class="form-control" accept=".jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Offer Acceptance</label>
                                <input type="file" name="doc_offer_acceptance" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Bank Details</label>
                                <input type="file" name="doc_bank" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Other Documents</label>
                                <input type="file" name="doc_other" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Upload Documents</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Checklist Modal -->
    <div class="modal fade" id="checklistModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="onboarding.php">
                    <input type="hidden" name="action" value="update_checklist">
                    <input type="hidden" name="onboarding_id" value="<?php echo $onboarding_id; ?>">

                    <div class="modal-header">
                        <h5 class="modal-title">Update Checklist</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="id_card_issued" id="chk_id_card" <?php echo $onboarding['id_card_issued'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_id_card">
                                    <strong>ID Card Issued</strong>
                                </label>
                            </div>
                        </div>
                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="email_created" id="chk_email" <?php echo $onboarding['email_created'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_email">
                                    <strong>Email Account Created</strong>
                                </label>
                            </div>
                        </div>
                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="system_access_given" id="chk_system" <?php echo $onboarding['system_access_given'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_system">
                                    <strong>System Access Granted</strong>
                                </label>
                            </div>
                        </div>
                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="biometric_enrolled" id="chk_biometric" <?php echo $onboarding['biometric_enrolled'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_biometric">
                                    <strong>Biometric Enrolled</strong>
                                </label>
                            </div>
                        </div>
                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="orientation_completed" id="chk_orientation" <?php echo $onboarding['orientation_completed'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_orientation">
                                    <strong>Orientation Completed</strong>
                                </label>
                            </div>
                        </div>
                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="training_completed" id="chk_training" <?php echo $onboarding['training_completed'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_training">
                                    <strong>Training Completed</strong>
                                </label>
                            </div>
                        </div>
                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="welcome_kit_issued" id="chk_welcome" <?php echo $onboarding['welcome_kit_issued'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="chk_welcome">
                                    <strong>Welcome Kit Issued</strong>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Update Checklist</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Complete Onboarding Modal -->
    <div class="modal fade" id="completeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="onboarding.php">
                    <input type="hidden" name="action" value="complete_onboarding">
                    <input type="hidden" name="onboarding_id" value="<?php echo $onboarding_id; ?>">

                    <div class="modal-header">
                        <h5 class="modal-title">Complete Onboarding</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to complete onboarding for <strong><?php echo e($onboarding['first_name'] . ' ' . $onboarding['last_name']); ?></strong>?</p>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>This will:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Create an employee record in the system</li>
                                <li>Generate employee username and password</li>
                                <li>Mark the candidate as 'Joined'</li>
                                <li>Complete the onboarding process</li>
                            </ul>
                        </div>

                        <p class="text-info small">
                            Ensure all documents are uploaded and checklist items are completed before proceeding.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Complete Onboarding</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        .candidate-avatar {
            width: 120px;
            height: 120px;
            border-radius: 60px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 900;
            font-size: 48px;
        }
        .btn-primary-custom, .btn-success-custom {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
            border: none;
            transition: all 0.3s;
        }
        .btn-primary-custom {
            background: var(--blue);
            color: white;
        }
        .btn-primary-custom:hover {
            background: #2a8bc9;
            color: white;
        }
        .btn-success-custom {
            background: #10b981;
            color: white;
        }
        .btn-success-custom:hover {
            background: #0da271;
            color: white;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
        function openDocumentModal() {
            new bootstrap.Modal(document.getElementById('documentModal')).show();
        }

        function openChecklistModal() {
            new bootstrap.Modal(document.getElementById('checklistModal')).show();
        }

        function openCompleteModal() {
            new bootstrap.Modal(document.getElementById('completeModal')).show();
        }
    </script>
</body>

</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>