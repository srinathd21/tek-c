<?php
// hr/view-interview.php - View Interview Details
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// ---------------- AUTH (HR/Manager) ----------------
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

// ---------------- GET INTERVIEW ID ----------------
$interview_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($interview_id === 0) {
    header("Location: interviews.php");
    exit;
}

// ---------------- FETCH INTERVIEW DETAILS ----------------
$query = "
    SELECT i.*, 
           c.id as candidate_id,
           c.first_name, 
           c.last_name, 
           c.photo_path as candidate_photo, 
           c.candidate_code,
           c.email as candidate_email, 
           c.phone as candidate_phone,
           c.current_location,
           c.preferred_location,
           c.total_experience,
           c.relevant_experience,
           c.current_ctc,
           c.expected_ctc,
           c.notice_period,
           c.notice_period_negotiable,
           c.current_company,
           c.qualification,
           c.skills,
           c.resume_path,
           c.source,
           c.referred_by,
           c.status as candidate_status,
           c.rating as candidate_rating,
           c.remarks as candidate_remarks,
           CONCAT(c.first_name, ' ', c.last_name) as candidate_name,
           h.id as hiring_request_id,
           h.request_no,
           h.position_title, 
           h.department,
           h.designation as hiring_designation,
           h.vacancies,
           h.location as job_location,
           h.experience_min,
           h.experience_max,
           h.salary_min,
           h.salary_max,
           h.job_description,
           e.id as interviewer_id,
           e.full_name as interviewer_full_name,
           e.designation as interviewer_designation,
           e.employee_code as interviewer_code,
           e.email as interviewer_email,
           e.mobile_number as interviewer_phone,
           e.photo as interviewer_photo,
           creator.full_name as created_by_name,
           creator.designation as created_by_designation
    FROM interviews i
    JOIN candidates c ON i.candidate_id = c.id
    JOIN hiring_requests h ON i.hiring_request_id = h.id
    JOIN employees e ON i.interviewer_id = e.id
    LEFT JOIN employees creator ON i.created_by = creator.id
    WHERE i.id = ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $interview_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$interview = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$interview) {
    $_SESSION['flash_error'] = "Interview not found.";
    header("Location: interviews.php");
    exit;
}

// Check permission - managers can only view interviews from their requests
if (!$isHr && !$isAdmin && $isManager) {
    $check_query = "SELECT id FROM hiring_requests WHERE id = ? AND requested_by = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $interview['hiring_request_id'], $current_employee_id);
    mysqli_stmt_execute($check_stmt);
    $check_res = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_res) === 0) {
        $_SESSION['flash_error'] = "You don't have permission to view this interview.";
        header("Location: interviews.php");
        exit;
    }
}

// ---------------- FETCH INTERVIEW HISTORY (Previous Rounds) ----------------
$history_query = "
    SELECT i.*, 
           e.full_name as interviewer_name,
           e.designation as interviewer_designation
    FROM interviews i
    JOIN employees e ON i.interviewer_id = e.id
    WHERE i.candidate_id = ? 
    ORDER BY i.round_number ASC, i.created_at ASC
";
$history_stmt = mysqli_prepare($conn, $history_query);
mysqli_stmt_bind_param($history_stmt, "i", $interview['candidate_id']);
mysqli_stmt_execute($history_stmt);
$history_result = mysqli_stmt_get_result($history_stmt);

// ---------------- FETCH OFFER DETAILS (if any) ----------------
$offer_query = "
    SELECT o.* 
    FROM offers o
    WHERE o.candidate_id = ?
    ORDER BY o.created_at DESC
    LIMIT 1
";
$offer_stmt = mysqli_prepare($conn, $offer_query);
mysqli_stmt_bind_param($offer_stmt, "i", $interview['candidate_id']);
mysqli_stmt_execute($offer_stmt);
$offer_result = mysqli_stmt_get_result($offer_stmt);
$offer = mysqli_fetch_assoc($offer_result);

// ---------------- FETCH ONBOARDING DETAILS (if any) ----------------
$onboarding_query = "
    SELECT ob.* 
    FROM onboarding ob
    WHERE ob.candidate_id = ?
    ORDER BY ob.created_at DESC
    LIMIT 1
";
$onboarding_stmt = mysqli_prepare($conn, $onboarding_query);
mysqli_stmt_bind_param($onboarding_stmt, "i", $interview['candidate_id']);
mysqli_stmt_execute($onboarding_stmt);
$onboarding_result = mysqli_stmt_get_result($onboarding_stmt);
$onboarding = mysqli_fetch_assoc($onboarding_result);

// ---------------- LOG ACTIVITY ----------------
logActivity(
    $conn,
    'VIEW',
    'interview',
    "Viewed interview details for {$interview['candidate_name']}",
    $interview_id,
    null,
    null,
    null
);

// ---------------- HELPER FUNCTIONS ----------------
function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
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
        'Scheduled' => 'bg-warning text-dark',
        'Completed' => 'bg-success',
        'Cancelled' => 'bg-danger',
        'Rescheduled' => 'bg-info',
        'No Show' => 'bg-secondary'
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    return "<span class='badge {$class} px-3 py-2'><i class='bi bi-circle-fill me-1' style='font-size:8px;'></i> {$status}</span>";
}

function getResultBadge($result)
{
    $classes = [
        'Selected' => 'bg-success',
        'Rejected' => 'bg-danger',
        'On Hold' => 'bg-warning text-dark',
        'Pending' => 'bg-secondary'
    ];
    $class = $classes[$result] ?? 'bg-secondary';
    return "<span class='badge {$class} px-3 py-2'><i class='bi bi-check-circle me-1'></i> {$result}</span>";
}

function getCandidateStatusBadge($status)
{
    $classes = [
        'New' => 'bg-info',
        'Screening' => 'bg-secondary',
        'Shortlisted' => 'bg-primary',
        'Interview Scheduled' => 'bg-warning text-dark',
        'Interviewed' => 'bg-dark',
        'Selected' => 'bg-success',
        'Rejected' => 'bg-danger',
        'On Hold' => 'bg-warning',
        'Offered' => 'bg-success',
        'Joined' => 'bg-success',
        'Declined' => 'bg-danger'
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    return "<span class='badge {$class}'>{$status}</span>";
}

function getRatingStars($rating)
{
    if (!$rating)
        return '<span class="text-muted">Not rated</span>';
    
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $stars .= '<i class="bi bi-star-fill" style="color: #f59e0b;"></i>';
        } else {
            $stars .= '<i class="bi bi-star" style="color: #d1d5db;"></i>';
        }
    }
    return $stars;
}

function getFullName($first, $last)
{
    return trim($first . ' ' . $last);
}

function formatDateTime($date, $time)
{
    if (!$date)
        return '—';
    return date('d M Y', strtotime($date)) . ' at ' . date('h:i A', strtotime($time));
}

function formatFileSize($bytes)
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

function initials($name)
{
    $name = trim((string) $name);
    if ($name === '')
        return 'U';
    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last = strtoupper(substr(end($parts) ?: '', 0, 1));
    return (count($parts) > 1) ? ($first . $last) : $first;
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Interview Details - <?php echo e($interview['candidate_name']); ?> - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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

        .panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(17, 24, 39, .06);
            padding: 24px;
            margin-bottom: 24px;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .panel-title {
            font-weight: 900;
            font-size: 18px;
            color: #1f2937;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            margin-bottom: 16px;
        }

        .info-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .info-value {
            font-weight: 800;
            color: #1f2937;
            font-size: 15px;
        }

        .info-value-sm {
            font-size: 13px;
            color: #374151;
        }

        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 20px 0;
        }

        /* Avatar */
        .candidate-avatar-lg {
            width: 80px;
            height: 80px;
            border-radius: 16px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 900;
            font-size: 32px;
            flex: 0 0 auto;
        }

        .candidate-avatar-lg img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .interviewer-avatar {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            overflow: hidden;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #4b5563;
        }

        .interviewer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Status Badges */
        .badge-status {
            padding: 6px 12px;
            border-radius: 30px;
            font-weight: 800;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-interview-round {
            background: rgba(45, 156, 219, 0.12);
            color: #2d9cdb;
            border: 1px solid rgba(45, 156, 219, 0.22);
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 800;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* Action Buttons */
        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            text-decoration: none;
            margin: 0 3px;
        }

        .action-btn:hover {
            background: #f3f4f6;
            color: #2d9cdb;
        }

        .btn-edit {
            background: #2d9cdb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
        }

        .btn-edit:hover {
            background: #2a8bc9;
            color: white;
        }

        /* Rating Stars */
        .rating-stars-lg i {
            font-size: 20px;
            margin-right: 4px;
        }

        /* Skills */
        .skill-tag {
            background: #f3f4f6;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            color: #374151;
            display: inline-block;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        /* Timeline */
        .interview-timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 25px;
            border-left: 2px solid #e5e7eb;
            padding-left: 20px;
            margin-left: 10px;
        }

        .timeline-item:last-child {
            border-left-color: transparent;
            padding-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -31px;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #fff;
            border: 4px solid;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .timeline-dot.current {
            border-color: #2d9cdb;
        }

        .timeline-dot.completed {
            border-color: #10b981;
        }

        .timeline-dot.cancelled {
            border-color: #ef4444;
        }

        .timeline-dot i {
            font-size: 10px;
            color: #fff;
        }

        .timeline-date {
            font-size: 11px;
            color: #6b7280;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .timeline-round {
            font-weight: 900;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .timeline-result {
            font-size: 12px;
            font-weight: 700;
        }

        /* Feedback Card */
        .feedback-card {
            background: #f9fafb;
            border-radius: 12px;
            padding: 16px;
            margin-top: 12px;
        }

        .rating-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .rating-label {
            width: 100px;
            font-size: 12px;
            font-weight: 700;
            color: #4b5563;
        }

        .rating-progress {
            flex: 1;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
        }

        .rating-progress-fill {
            height: 6px;
            border-radius: 3px;
            background: #2d9cdb;
        }

        .rating-value {
            width: 30px;
            font-size: 12px;
            font-weight: 800;
            color: #1f2937;
        }

        @media (max-width: 768px) {
            .content-scroll {
                padding: 12px;
            }

            .info-grid {
                grid-template-columns: 1fr;
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
                <div class="container-fluid maxw">

                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <a href="interviews.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-arrow-left"></i> Back to Interviews
                                </a>
                                <span class="badge-interview-round">
                                    <i class="bi bi-camera-video"></i> <?php echo e($interview['interview_round']); ?>
                                </span>
                            </div>
                            <h1 class="h3 fw-bold mb-1">
                                Interview with <?php echo e($interview['candidate_name']); ?>
                            </h1>
                            <div class="d-flex align-items-center gap-3">
                                <span>Request: <a href="view-hiring-request.php?id=<?php echo $interview['hiring_request_id']; ?>" class="text-decoration-none"><?php echo e($interview['request_no']); ?></a></span>
                                <span>•</span>
                                <span><?php echo getStatusBadge($interview['status']); ?></span>
                                <?php if ($interview['status'] === 'Completed'): ?>
                                    <span><?php echo getResultBadge($interview['result']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($interview['status'] === 'Scheduled'): ?>
                                <button class="btn-edit" onclick="openEditModal()">
                                    <i class="bi bi-pencil"></i> Edit Feedback
                                </button>
                            <?php elseif ($interview['status'] === 'Completed'): ?>
                                <button class="btn-edit" onclick="openEditModal()">
                                    <i class="bi bi-pencil"></i> Edit Feedback
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Main Content Grid -->
                    <div class="row">
                        <!-- Left Column - Interview Details -->
                        <div class="col-lg-8">

                            <!-- Interview Information -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-calendar-check"></i>
                                        Interview Details
                                    </h5>
                                    <span class="badge-interview-round">
                                        Round <?php echo (int) $interview['round_number']; ?>
                                    </span>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-item">
                                            <div class="info-label">Interview Date & Time</div>
                                            <div class="info-value">
                                                <i class="bi bi-calendar me-1"></i> <?php echo date('l, d F Y', strtotime($interview['interview_date'])); ?>
                                            </div>
                                            <div class="info-value-sm">
                                                <i class="bi bi-clock me-1"></i> <?php echo date('h:i A', strtotime($interview['interview_time'])); ?>
                                                (<?php echo (int) $interview['interview_duration']; ?> minutes)
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-item">
                                            <div class="info-label">Interview Mode</div>
                                            <div class="info-value">
                                                <?php if ($interview['interview_mode'] === 'Online'): ?>
                                                    <i class="bi bi-camera-video me-1 text-primary"></i> Online
                                                <?php elseif ($interview['interview_mode'] === 'In-Person'): ?>
                                                    <i class="bi bi-person me-1 text-success"></i> In-Person
                                                <?php else: ?>
                                                    <i class="bi bi-telephone me-1 text-info"></i> Telephonic
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($interview['interview_mode'] === 'Online' && !empty($interview['interview_link'])): ?>
                                                <div class="info-value-sm">
                                                    <a href="<?php echo e($interview['interview_link']); ?>" target="_blank" class="text-decoration-none">
                                                        <i class="bi bi-link"></i> Join Meeting
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($interview['interview_mode'] === 'In-Person' && !empty($interview['location'])): ?>
                                                <div class="info-value-sm">
                                                    <i class="bi bi-geo-alt"></i> <?php echo e($interview['location']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($interview['status'] === 'Rescheduled' && !empty($interview['reschedule_reason'])): ?>
                                    <div class="alert alert-warning mt-3 mb-0">
                                        <i class="bi bi-arrow-repeat me-2"></i>
                                        <strong>Rescheduled:</strong> <?php echo e($interview['reschedule_reason']); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($interview['status'] === 'Cancelled' && !empty($interview['cancellation_reason'])): ?>
                                    <div class="alert alert-danger mt-3 mb-0">
                                        <i class="bi bi-x-circle me-2"></i>
                                        <strong>Cancelled:</strong> <?php echo e($interview['cancellation_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Interview Feedback -->
                            <?php if ($interview['status'] === 'Completed'): ?>
                                <div class="panel">
                                    <div class="panel-header">
                                        <h5 class="panel-title">
                                            <i class="bi bi-chat-dots"></i>
                                            Interview Feedback
                                        </h5>
                                        <?php if (!empty($interview['rating'])): ?>
                                            <div class="rating-stars-lg">
                                                <?php echo getRatingStars($interview['rating']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="row g-4">
                                        <!-- Ratings -->
                                        <div class="col-md-12">
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <div class="rating-bar">
                                                        <div class="rating-label">Technical Skills</div>
                                                        <div class="rating-progress">
                                                            <div class="rating-progress-fill" style="width: <?php echo ($interview['technical_skills_rating'] ?? 0) * 20; ?>%"></div>
                                                        </div>
                                                        <div class="rating-value"><?php echo (int) ($interview['technical_skills_rating'] ?? 0); ?>/5</div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="rating-bar">
                                                        <div class="rating-label">Communication</div>
                                                        <div class="rating-progress">
                                                            <div class="rating-progress-fill" style="width: <?php echo ($interview['communication_rating'] ?? 0) * 20; ?>%"></div>
                                                        </div>
                                                        <div class="rating-value"><?php echo (int) ($interview['communication_rating'] ?? 0); ?>/5</div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="rating-bar">
                                                        <div class="rating-label">Attitude</div>
                                                        <div class="rating-progress">
                                                            <div class="rating-progress-fill" style="width: <?php echo ($interview['attitude_rating'] ?? 0) * 20; ?>%"></div>
                                                        </div>
                                                        <div class="rating-value"><?php echo (int) ($interview['attitude_rating'] ?? 0); ?>/5</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Strengths & Weaknesses -->
                                        <?php if (!empty($interview['strengths']) || !empty($interview['weaknesses'])): ?>
                                            <div class="col-md-6">
                                                <div class="feedback-card">
                                                    <div class="d-flex align-items-center gap-2 mb-2">
                                                        <i class="bi bi-arrow-up-circle text-success"></i>
                                                        <span class="fw-bold">Strengths</span>
                                                    </div>
                                                    <p class="mb-0"><?php echo nl2br(e($interview['strengths'])); ?></p>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="feedback-card">
                                                    <div class="d-flex align-items-center gap-2 mb-2">
                                                        <i class="bi bi-arrow-down-circle text-danger"></i>
                                                        <span class="fw-bold">Areas to Improve</span>
                                                    </div>
                                                    <p class="mb-0"><?php echo nl2br(e($interview['weaknesses'])); ?></p>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Detailed Feedback -->
                                        <?php if (!empty($interview['feedback'])): ?>
                                            <div class="col-md-12">
                                                <div class="feedback-card">
                                                    <div class="d-flex align-items-center gap-2 mb-2">
                                                        <i class="bi bi-file-text"></i>
                                                        <span class="fw-bold">Detailed Feedback</span>
                                                    </div>
                                                    <p class="mb-0"><?php echo nl2br(e($interview['feedback'])); ?></p>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Interview History -->
                            <?php if (mysqli_num_rows($history_result) > 1): ?>
                                <div class="panel">
                                    <div class="panel-header">
                                        <h5 class="panel-title">
                                            <i class="bi bi-clock-history"></i>
                                            Interview History
                                        </h5>
                                    </div>

                                    <div class="interview-timeline">
                                        <?php
                                        mysqli_data_seek($history_result, 0);
                                        while ($history = mysqli_fetch_assoc($history_result)):
                                            $isCurrent = ($history['id'] == $interview['id']);
                                        ?>
                                            <div class="timeline-item">
                                                <div class="timeline-dot <?php echo $history['status'] === 'Completed' ? 'completed' : ($history['status'] === 'Cancelled' ? 'cancelled' : ($isCurrent ? 'current' : '')); ?>">
                                                    <?php if ($history['status'] === 'Completed'): ?>
                                                        <i class="bi bi-check" style="background: #10b981; width:100%; height:100%; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:12px;"></i>
                                                    <?php elseif ($history['status'] === 'Cancelled'): ?>
                                                        <i class="bi bi-x" style="background: #ef4444; width:100%; height:100%; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:12px;"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="timeline-date">
                                                    <?php echo date('d M Y, h:i A', strtotime($history['interview_date'] . ' ' . $history['interview_time'])); ?>
                                                </div>
                                                <div class="timeline-round">
                                                    Round <?php echo (int) $history['round_number']; ?>: <?php echo e($history['interview_round']); ?>
                                                    <?php if ($isCurrent): ?>
                                                        <span class="badge bg-primary ms-2">Current</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <span class="badge bg-<?php echo $history['status'] === 'Completed' ? 'success' : ($history['status'] === 'Cancelled' ? 'danger' : 'warning'); ?>">
                                                        <?php echo e($history['status']); ?>
                                                    </span>
                                                    <?php if ($history['status'] === 'Completed'): ?>
                                                        <span class="badge bg-<?php echo $history['result'] === 'Selected' ? 'success' : ($history['result'] === 'Rejected' ? 'danger' : 'secondary'); ?>">
                                                            <?php echo e($history['result']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="info-value-sm">
                                                    Interviewer: <?php echo e($history['interviewer_name']); ?> (<?php echo e($history['interviewer_designation']); ?>)
                                                </div>
                                                <?php if ($history['status'] === 'Completed' && !empty($history['feedback'])): ?>
                                                    <div class="mt-2 p-2 bg-light rounded" style="font-size:12px;">
                                                        <i class="bi bi-chat-quote"></i> <?php echo e(substr($history['feedback'], 0, 100)) . (strlen($history['feedback']) > 100 ? '...' : ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Position Details -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-briefcase"></i>
                                        Position Details
                                    </h5>
                                    <a href="view-hiring-request.php?id=<?php echo $interview['hiring_request_id']; ?>" class="btn btn-sm btn-outline-primary">
                                        View Full Request <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>

                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Position Title</div>
                                        <div class="info-value"><?php echo e($interview['position_title']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Department</div>
                                        <div class="info-value"><?php echo e($interview['department']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Designation</div>
                                        <div class="info-value"><?php echo e($interview['hiring_designation']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Vacancies</div>
                                        <div class="info-value"><?php echo (int) $interview['vacancies']; ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Experience Required</div>
                                        <div class="info-value">
                                            <?php echo (int) $interview['experience_min']; ?> - <?php echo (int) $interview['experience_max']; ?> years
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Salary Range</div>
                                        <div class="info-value">
                                            <?php echo formatCurrency($interview['salary_min']); ?> - <?php echo formatCurrency($interview['salary_max']); ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Job Location</div>
                                        <div class="info-value"><?php echo e($interview['job_location']); ?></div>
                                    </div>
                                </div>

                                <?php if (!empty($interview['job_description'])): ?>
                                    <div class="divider"></div>
                                    <div class="info-item">
                                        <div class="info-label mb-2">Job Description</div>
                                        <div class="p-3 bg-light rounded" style="font-size:13px;">
                                            <?php echo nl2br(e($interview['job_description'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right Column - Candidate & Interviewer Info -->
                        <div class="col-lg-4">

                            <!-- Candidate Card -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-person"></i>
                                        Candidate Profile
                                    </h5>
                                    <a href="view-candidate.php?id=<?php echo $interview['candidate_id']; ?>" class="btn btn-sm btn-outline-primary">
                                        View Full
                                    </a>
                                </div>

                                <div class="d-flex align-items-center gap-3 mb-4">
                                    <div class="candidate-avatar-lg">
                                        <?php if (!empty($interview['candidate_photo'])): ?>
                                            <img src="../<?php echo e($interview['candidate_photo']); ?>" alt="Candidate Photo">
                                        <?php else: ?>
                                            <?php echo initials($interview['candidate_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h4 class="fw-bold mb-1"><?php echo e($interview['candidate_name']); ?></h4>
                                        <div class="d-flex flex-wrap gap-2 mb-1">
                                            <?php echo getCandidateStatusBadge($interview['candidate_status']); ?>
                                        </div>
                                        <div class="text-muted small">
                                            <i class="bi bi-hash"></i> <?php echo e($interview['candidate_code']); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Contact Information</div>
                                    <div class="mb-1">
                                        <i class="bi bi-envelope me-2 text-muted"></i>
                                        <a href="mailto:<?php echo e($interview['candidate_email']); ?>" class="text-decoration-none">
                                            <?php echo e($interview['candidate_email']); ?>
                                        </a>
                                    </div>
                                    <div>
                                        <i class="bi bi-telephone me-2 text-muted"></i>
                                        <a href="tel:<?php echo e($interview['candidate_phone']); ?>" class="text-decoration-none">
                                            <?php echo e($interview['candidate_phone']); ?>
                                        </a>
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Current Company</div>
                                    <div class="info-value"><?php echo e($interview['current_company'] ?: '—'); ?></div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Experience</div>
                                    <div class="info-value">
                                        <?php if ($interview['total_experience']): ?>
                                            <?php echo number_format($interview['total_experience'], 1); ?> years
                                            <?php if ($interview['relevant_experience']): ?>
                                                <span class="text-muted">(Relevant: <?php echo number_format($interview['relevant_experience'], 1); ?> yrs)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Fresher
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-6">
                                        <div class="info-item">
                                            <div class="info-label">Current CTC</div>
                                            <div class="info-value"><?php echo formatCurrency($interview['current_ctc']); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="info-item">
                                            <div class="info-label">Expected CTC</div>
                                            <div class="info-value"><?php echo formatCurrency($interview['expected_ctc']); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Notice Period</div>
                                    <div class="info-value">
                                        <?php echo (int) $interview['notice_period']; ?> days
                                        <?php if ($interview['notice_period_negotiable']): ?>
                                            <span class="badge bg-info ms-2">Negotiable</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!empty($interview['skills'])): ?>
                                    <div class="info-item">
                                        <div class="info-label mb-2">Skills</div>
                                        <div>
                                            <?php
                                            $skills = explode(',', $interview['skills']);
                                            foreach ($skills as $skill):
                                                $skill = trim($skill);
                                                if (!empty($skill)):
                                            ?>
                                                <span class="skill-tag"><?php echo e($skill); ?></span>
                                            <?php
                                                endif;
                                            endforeach;
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($interview['resume_path'])): ?>
                                    <div class="info-item">
                                        <div class="info-label mb-2">Resume</div>
                                        <a href="../<?php echo e($interview['resume_path']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-file-pdf"></i> View Resume
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Interviewer Card -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-person-badge"></i>
                                        Interviewer
                                    </h5>
                                </div>

                                <div class="d-flex align-items-center gap-3">
                                    <div class="interviewer-avatar">
                                        <?php if (!empty($interview['interviewer_photo'])): ?>
                                            <img src="../<?php echo e($interview['interviewer_photo']); ?>" alt="Interviewer Photo">
                                        <?php else: ?>
                                            <?php echo initials($interview['interviewer_full_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo e($interview['interviewer_full_name']); ?></div>
                                        <div class="text-muted small"><?php echo e($interview['interviewer_designation']); ?> (<?php echo e($interview['interviewer_code']); ?>)</div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="mb-1">
                                        <i class="bi bi-envelope me-2 text-muted"></i>
                                        <a href="mailto:<?php echo e($interview['interviewer_email']); ?>" class="text-decoration-none small">
                                            <?php echo e($interview['interviewer_email']); ?>
                                        </a>
                                    </div>
                                    <?php if (!empty($interview['interviewer_phone'])): ?>
                                        <div>
                                            <i class="bi bi-telephone me-2 text-muted"></i>
                                            <a href="tel:<?php echo e($interview['interviewer_phone']); ?>" class="text-decoration-none small">
                                                <?php echo e($interview['interviewer_phone']); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($interview['panel_members'])): ?>
                                    <div class="divider"></div>
                                    <div class="info-label mb-2">Panel Members</div>
                                    <?php
                                    $panel = json_decode($interview['panel_members'], true);
                                    if (is_array($panel)):
                                        foreach ($panel as $member):
                                    ?>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <i class="bi bi-person"></i>
                                                <span><?php echo e($member); ?></span>
                                            </div>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                <?php endif; ?>
                            </div>

                            <!-- Offer / Onboarding Card (if exists) -->
                            <?php if ($offer || $onboarding): ?>
                                <div class="panel">
                                    <div class="panel-header">
                                        <h5 class="panel-title">
                                            <i class="bi bi-trophy"></i>
                                            Conversion Status
                                        </h5>
                                    </div>

                                    <?php if ($offer): ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="fw-bold">Offer Details</span>
                                                <span class="badge bg-<?php echo $offer['status'] === 'Accepted' ? 'success' : ($offer['status'] === 'Rejected' ? 'danger' : 'warning'); ?>">
                                                    <?php echo e($offer['status']); ?>
                                                </span>
                                            </div>
                                            <div class="small">
                                                <div><i class="bi bi-file-text me-2"></i> <?php echo e($offer['offer_no']); ?></div>
                                                <div><i class="bi bi-currency-rupee me-2"></i> <?php echo formatCurrency($offer['ctc']); ?></div>
                                                <div><i class="bi bi-calendar me-2"></i> Valid till: <?php echo date('d M Y', strtotime($offer['offer_valid_till'])); ?></div>
                                            </div>
                                            <a href="view-offer.php?id=<?php echo $offer['id']; ?>" class="btn btn-sm btn-outline-success mt-2 w-100">
                                                View Offer
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($onboarding): ?>
                                        <div>
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="fw-bold">Onboarding</span>
                                                <span class="badge bg-<?php echo $onboarding['status'] === 'Completed' ? 'success' : 'info'; ?>">
                                                    <?php echo e($onboarding['status']); ?>
                                                </span>
                                            </div>
                                            <div class="small">
                                                <div><i class="bi bi-calendar-check me-2"></i> Joining: <?php echo date('d M Y', strtotime($onboarding['joining_date'])); ?></div>
                                                <?php if (!empty($onboarding['employee_code'])): ?>
                                                    <div><i class="bi bi-person-badge me-2"></i> Emp Code: <?php echo e($onboarding['employee_code']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <a href="view-onboarding.php?id=<?php echo $onboarding['id']; ?>" class="btn btn-sm btn-outline-info mt-2 w-100">
                                                View Onboarding
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Metadata Card -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-info-circle"></i>
                                        Additional Information
                                    </h5>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Created By</div>
                                    <div class="info-value"><?php echo e($interview['created_by_name'] ?: 'System'); ?></div>
                                    <div class="text-muted small">
                                        <i class="bi bi-calendar"></i> <?php echo date('d M Y, h:i A', strtotime($interview['created_at'])); ?>
                                    </div>
                                </div>

                                <?php if ($interview['updated_at'] && $interview['updated_at'] != $interview['created_at']): ?>
                                    <div class="info-item mt-2">
                                        <div class="info-label">Last Updated</div>
                                        <div class="text-muted small">
                                            <i class="bi bi-clock"></i> <?php echo date('d M Y, h:i A', strtotime($interview['updated_at'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($interview['remarks'])): ?>
                                    <div class="divider"></div>
                                    <div class="info-item">
                                        <div class="info-label">Remarks</div>
                                        <div class="p-2 bg-light rounded small">
                                            <?php echo nl2br(e($interview['remarks'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Edit Interview Modal -->
    <div class="modal fade" id="editInterviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="interviews.php">
                    <input type="hidden" name="action" value="update_interview">
                    <input type="hidden" name="interview_id" value="<?php echo $interview_id; ?>">

                    <div class="modal-header">
                        <h5 class="modal-title">Update Interview Feedback</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select" id="edit_status" required>
                                    <option value="Scheduled" <?php echo $interview['status'] === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="Completed" <?php echo $interview['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="Cancelled" <?php echo $interview['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="No Show" <?php echo $interview['status'] === 'No Show' ? 'selected' : ''; ?>>No Show</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="edit_result_field" style="<?php echo $interview['status'] !== 'Completed' ? 'display:none;' : ''; ?>">
                                <label class="form-label">Result</label>
                                <select name="result" class="form-select" id="edit_result">
                                    <option value="Pending" <?php echo ($interview['result'] ?? 'Pending') === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Selected" <?php echo ($interview['result'] ?? '') === 'Selected' ? 'selected' : ''; ?>>Selected</option>
                                    <option value="Rejected" <?php echo ($interview['result'] ?? '') === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="On Hold" <?php echo ($interview['result'] ?? '') === 'On Hold' ? 'selected' : ''; ?>>On Hold</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Overall Rating</label>
                                <select name="rating" class="form-select">
                                    <option value="">Not Rated</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($interview['rating'] ?? 0) == $i ? 'selected' : ''; ?>>
                                            <?php echo $i; ?> - <?php echo $i == 1 ? 'Poor' : ($i == 2 ? 'Fair' : ($i == 3 ? 'Good' : ($i == 4 ? 'Very Good' : 'Excellent'))); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Technical Skills</label>
                                <select name="technical_skills_rating" class="form-select">
                                    <option value="">Not Rated</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($interview['technical_skills_rating'] ?? 0) == $i ? 'selected' : ''; ?>><?php echo $i; ?> / 5</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Communication</label>
                                <select name="communication_rating" class="form-select">
                                    <option value="">Not Rated</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($interview['communication_rating'] ?? 0) == $i ? 'selected' : ''; ?>><?php echo $i; ?> / 5</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Strengths</label>
                            <textarea name="strengths" class="form-control" rows="2"><?php echo e($interview['strengths'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Weaknesses</label>
                            <textarea name="weaknesses" class="form-control" rows="2"><?php echo e($interview['weaknesses'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Detailed Feedback</label>
                            <textarea name="feedback" class="form-control" rows="4"><?php echo e($interview['feedback'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Interview</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
        function openEditModal() {
            new bootstrap.Modal(document.getElementById('editInterviewModal')).show();
        }

        // Toggle result field based on status
        document.getElementById('edit_status')?.addEventListener('change', function() {
            const resultField = document.getElementById('edit_result_field');
            if (this.value === 'Completed') {
                resultField.style.display = 'block';
            } else {
                resultField.style.display = 'none';
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