<?php
// hr/view-interview.php - View Interview Details (TEK-C Style)
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

// ---------------- GET INTERVIEW DETAILS ----------------
$interview_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$interview_id) {
    $_SESSION['flash_error'] = "Invalid interview ID.";
    header("Location: interviews.php");
    exit;
}

// Get interview details with all related information
$query = "
    SELECT i.*, 
           c.id as candidate_id,
           c.first_name, 
           c.last_name, 
           c.candidate_code,
           c.email as candidate_email,
           c.phone as candidate_phone,
           c.photo_path as candidate_photo,
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
           c.remarks as candidate_remarks,
           h.id as hiring_request_id,
           h.request_no,
           h.position_title,
           h.department as hiring_department,
           h.designation as hiring_designation,
           h.vacancies,
           h.employment_type,
           h.location as job_location,
           h.experience_min,
           h.experience_max,
           h.salary_min,
           h.salary_max,
           h.job_description,
           h.qualification as required_qualification,
           h.skills_required,
           h.requested_by as hiring_requested_by,
           h.requested_by_name,
           h.requested_date,
           e.full_name as interviewer_name,
           e.designation as interviewer_designation,
           e.employee_code as interviewer_code,
           e.email as interviewer_email,
           e.mobile_number as interviewer_phone,
           e.photo as interviewer_photo,
           (SELECT COUNT(*) FROM interviews WHERE candidate_id = c.id) as total_interviews,
           (SELECT MAX(round_number) FROM interviews WHERE candidate_id = c.id) as max_round,
           o.id as offer_id,
           o.offer_no,
           o.ctc as offered_ctc,
           o.status as offer_status,
           ob.id as onboarding_id,
           ob.onboarding_no,
           ob.employee_code,
           ob.joining_date,
           ob.status as onboarding_status
    FROM interviews i
    JOIN candidates c ON i.candidate_id = c.id
    JOIN hiring_requests h ON i.hiring_request_id = h.id
    JOIN employees e ON i.interviewer_id = e.id
    LEFT JOIN offers o ON o.candidate_id = c.id
    LEFT JOIN onboarding ob ON ob.candidate_id = c.id
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

// Get all interviews for this candidate (timeline)
$timeline_query = "
    SELECT i.*, 
           e.full_name as interviewer_name,
           e.designation as interviewer_designation
    FROM interviews i
    JOIN employees e ON i.interviewer_id = e.id
    WHERE i.candidate_id = ?
    ORDER BY i.round_number ASC, i.interview_date ASC
";
$timeline_stmt = mysqli_prepare($conn, $timeline_query);
mysqli_stmt_bind_param($timeline_stmt, "i", $interview['candidate_id']);
mysqli_stmt_execute($timeline_stmt);
$timeline_result = mysqli_stmt_get_result($timeline_stmt);

// Get feedback comments/history if any
$feedback_query = "
    SELECT * FROM interview_feedback 
    WHERE interview_id = ? 
    ORDER BY created_at DESC
";
$feedback_stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($feedback_stmt, "i", $interview_id);
mysqli_stmt_execute($feedback_stmt);
$feedback_result = mysqli_stmt_get_result($feedback_stmt);

// ---------------- HELPER FUNCTIONS ----------------
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function getStatusBadge($status) {
    $classes = [
        'Scheduled' => 'status-interview',
        'Completed' => 'status-selected',
        'Cancelled' => 'status-rejected',
        'Rescheduled' => 'status-hold',
        'No Show' => 'status-rejected'
    ];
    $class = $classes[$status] ?? 'status-new';
    return "<span class='status-badge {$class}'><i class='bi bi-circle-fill' style='font-size:8px;'></i> {$status}</span>";
}

function getResultBadge($result) {
    $classes = [
        'Selected' => 'status-selected',
        'Rejected' => 'status-rejected',
        'On Hold' => 'status-hold',
        'Pending' => 'status-screening'
    ];
    $class = $classes[$result] ?? 'status-screening';
    return "<span class='status-badge {$class}'><i class='bi bi-circle-fill' style='font-size:8px;'></i> {$result}</span>";
}

function getRatingStars($rating) {
    if (!$rating) return '<span class="text-muted">Not rated</span>';
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

function getFullName($first, $last) {
    return trim($first . ' ' . $last);
}

function formatCurrency($amount) {
    if (!$amount) return '—';
    return '₹ ' . number_format($amount, 2) . ' LPA';
}

function initials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last = strtoupper(substr(end($parts) ?: '', 0, 1));
    return (count($parts) > 1) ? ($first . $last) : $first;
}

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return round($diff / 60) . ' minutes ago';
    if ($diff < 86400) return round($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return round($diff / 86400) . ' days ago';
    return date('d M Y', $timestamp);
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
$candidateName = getFullName($interview['first_name'], $interview['last_name']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Interview Details - <?php echo e($candidateName); ?> - TEK-C Hiring</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px; }
        
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px;
            height: 100%;
        }
        
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .panel-title {
            font-weight: 900;
            font-size: 18px;
            color: #1f2937;
            margin: 0;
        }
        
        .panel-menu {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
            display: grid;
            place-items: center;
            color: #6b7280;
        }

        /* Back Button */
        .btn-back {
            background: #f3f4f6;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 18px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #374151;
        }
        .btn-back:hover {
            background: #e5e7eb;
            color: #1f2937;
        }

        .btn-edit {
            background: var(--blue);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
            text-decoration: none;
        }
        .btn-edit:hover { background: #2a8bc9; color: #fff; }

        /* Candidate Avatar */
        .candidate-avatar-large {
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
        .candidate-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .candidate-avatar-sm {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 900;
            font-size: 14px;
            flex: 0 0 auto;
        }
        .candidate-avatar-sm img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .candidate-name-large {
            font-weight: 900;
            font-size: 24px;
            color: #1f2937;
            line-height: 1.2;
        }
        
        .candidate-code-large {
            font-size: 14px;
            color: #6b7280;
            font-weight: 650;
        }

        /* Info Grid */
        .info-label {
            font-weight: 800;
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .3px;
            margin-bottom: 4px;
        }

        .info-value {
            font-weight: 750;
            font-size: 14px;
            color: #1f2937;
            margin-bottom: 12px;
        }

        .info-value-sm {
            font-weight: 650;
            font-size: 12px;
            color: #374151;
        }

        .info-card {
            background: #f9fafb;
            border-radius: 12px;
            padding: 12px;
            border: 1px solid var(--border);
        }

        /* Status Badges */
        .status-badge {
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        
        .status-new { background: rgba(45, 156, 219, .12); color: var(--blue); border: 1px solid rgba(45, 156, 219, .22); }
        .status-screening { background: rgba(107, 114, 128, .12); color: #6b7280; border: 1px solid rgba(107, 114, 128, .22); }
        .status-shortlisted { background: rgba(139, 92, 246, .12); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, .22); }
        .status-interview { background: rgba(245, 158, 11, .12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, .22); }
        .status-interviewed { background: rgba(16, 185, 129, .12); color: #10b981; border: 1px solid rgba(16, 185, 129, .22); }
        .status-selected { background: rgba(16, 185, 129, .12); color: #10b981; border: 1px solid rgba(16, 185, 129, .22); }
        .status-rejected { background: rgba(239, 68, 68, .12); color: #ef4444; border: 1px solid rgba(239, 68, 68, .22); }
        .status-hold { background: rgba(245, 158, 11, .12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, .22); }
        .status-offered { background: rgba(16, 185, 129, .12); color: #10b981; border: 1px solid rgba(16, 185, 129, .22); }
        .status-joined { background: rgba(16, 185, 129, .12); color: #10b981; border: 1px solid rgba(16, 185, 129, .22); }
        .status-declined { background: rgba(239, 68, 68, .12); color: #ef4444; border: 1px solid rgba(239, 68, 68, .22); }

        /* Rating Stars */
        .rating-stars {
            white-space: nowrap;
        }
        .rating-stars i {
            font-size: 14px;
            margin-right: 2px;
        }
        .rating-large i {
            font-size: 18px;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 8px;
            bottom: 8px;
            width: 2px;
            background: #e5e7eb;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 24px;
        }
        .timeline-dot {
            position: absolute;
            left: -30px;
            top: 0;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #fff;
            border: 3px solid;
            z-index: 1;
        }
        .timeline-dot.scheduled { border-color: #f59e0b; }
        .timeline-dot.completed { border-color: #10b981; }
        .timeline-dot.selected { border-color: #10b981; background: #10b981; }
        .timeline-dot.rejected { border-color: #ef4444; }
        .timeline-dot.current { 
            border-color: var(--blue); 
            background: var(--blue);
            width: 20px;
            height: 20px;
            left: -31px;
        }
        .timeline-date {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .timeline-title {
            font-weight: 800;
            font-size: 14px;
            color: #1f2937;
            margin-bottom: 4px;
        }
        .timeline-sub {
            font-size: 12px;
            color: #4b5563;
            margin-bottom: 4px;
        }
        .timeline-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 800;
            background: #f3f4f6;
            color: #4b5563;
        }

        /* Feedback Card */
        .feedback-card {
            background: #f9fafb;
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid;
            margin-bottom: 12px;
        }
        .feedback-card.selected { border-left-color: #10b981; }
        .feedback-card.rejected { border-left-color: #ef4444; }
        .feedback-card.hold { border-left-color: #f59e0b; }

        .feedback-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .feedback-author {
            font-weight: 800;
            font-size: 13px;
            color: #1f2937;
        }
        .feedback-date {
            font-size: 11px;
            color: #6b7280;
        }
        .feedback-text {
            font-size: 13px;
            color: #374151;
            line-height: 1.5;
        }

        /* Action Buttons */
        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 12px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-action:hover {
            background: var(--bg);
            color: var(--blue);
        }
        .btn-action.success:hover {
            background: #d1fae5;
            color: #065f46;
            border-color: #065f46;
        }
        .btn-action.warning:hover {
            background: #fef3c7;
            color: #92400e;
            border-color: #92400e;
        }
        .btn-action.danger:hover {
            background: #fee2e2;
            color: #991b1b;
            border-color: #991b1b;
        }

        /* Skills Tags */
        .skill-tag {
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 650;
            color: #374151;
            display: inline-block;
            margin: 0 4px 4px 0;
        }

        /* Conversion Card */
        .conversion-card {
            background: linear-gradient(135deg, #10b98110, #2563eb10);
            border: 1px solid #10b98130;
            border-radius: 12px;
            padding: 16px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content-scroll { padding: 12px; }
            .candidate-avatar-large { width: 60px; height: 60px; font-size: 24px; }
            .candidate-name-large { font-size: 20px; }
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

                <!-- Header with Back Button -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <a href="interviews.php" class="btn-back">
                        <i class="bi bi-arrow-left"></i> Back to Interviews
                    </a>
                    <div class="d-flex gap-2">
                        <?php if ($interview['status'] === 'Scheduled'): ?>
                            <button class="btn-action warning" onclick="openRescheduleModal()">
                                <i class="bi bi-arrow-repeat"></i> Reschedule
                            </button>
                            <button class="btn-action success" onclick="openUpdateModal()">
                                <i class="bi bi-pencil"></i> Update Feedback
                            </button>
                            <button class="btn-action danger" onclick="openCancelModal()">
                                <i class="bi bi-x-lg"></i> Cancel
                            </button>
                        <?php elseif ($interview['status'] === 'Completed'): ?>
                            <button class="btn-action success" onclick="openUpdateModal()">
                                <i class="bi bi-pencil"></i> Edit Feedback
                            </button>
                        <?php endif; ?>
                        <a href="view-candidate.php?id=<?php echo $interview['candidate_id']; ?>" class="btn-action">
                            <i class="bi bi-person"></i> View Candidate
                        </a>
                    </div>
                </div>

                <!-- Candidate Header Card -->
                <div class="panel mb-4">
                    <div class="d-flex align-items-center gap-4">
                        <div class="candidate-avatar-large">
                            <?php if (!empty($interview['candidate_photo'])): ?>
                                <img src="../<?php echo e($interview['candidate_photo']); ?>" alt="Photo">
                            <?php else: ?>
                                <?php echo initials($candidateName); ?>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <h1 class="candidate-name-large"><?php echo e($candidateName); ?></h1>
                                <?php echo getStatusBadge($interview['status']); ?>
                                <?php if ($interview['status'] === 'Completed'): ?>
                                    <?php echo getResultBadge($interview['result']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="candidate-code-large mb-2">
                                <i class="bi bi-hash"></i> <?php echo e($interview['candidate_code'] ?? ''); ?>
                            </div>
                            <div class="d-flex gap-3 flex-wrap">
                                <span><i class="bi bi-envelope"></i> <?php echo e($interview['candidate_email']); ?></span>
                                <span><i class="bi bi-telephone"></i> <?php echo e($interview['candidate_phone']); ?></span>
                                <?php if (!empty($interview['current_location'])): ?>
                                    <span><i class="bi bi-geo-alt"></i> <?php echo e($interview['current_location']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Left Column - Interview Details -->
                    <div class="col-lg-8">
                        <!-- Interview Details Panel -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h3 class="panel-title">Interview Details</h3>
                                <div>
                                    <span class="badge-pill">Round <?php echo $interview['round_number']; ?></span>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-label">Interview No</div>
                                    <div class="info-value"><?php echo e($interview['interview_no']); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-label">Interview Round</div>
                                    <div class="info-value"><?php echo e($interview['interview_round']); ?></div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="info-label">Date</div>
                                    <div class="info-value">
                                        <i class="bi bi-calendar"></i> <?php echo date('l, d M Y', strtotime($interview['interview_date'])); ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-label">Time</div>
                                    <div class="info-value">
                                        <i class="bi bi-clock"></i> <?php echo date('h:i A', strtotime($interview['interview_time'])); ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-label">Duration</div>
                                    <div class="info-value">
                                        <i class="bi bi-hourglass"></i> <?php echo $interview['interview_duration']; ?> minutes
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="info-label">Mode</div>
                                    <div class="info-value">
                                        <?php if ($interview['interview_mode'] === 'Online'): ?>
                                            <span class="status-badge status-interview"><i class="bi bi-camera-video"></i> Online</span>
                                        <?php elseif ($interview['interview_mode'] === 'In-Person'): ?>
                                            <span class="status-badge status-shortlisted"><i class="bi bi-building"></i> In-Person</span>
                                        <?php else: ?>
                                            <span class="status-badge status-screening"><i class="bi bi-telephone"></i> Telephonic</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <?php if ($interview['interview_mode'] === 'Online' && !empty($interview['interview_link'])): ?>
                                        <div class="info-label">Meeting Link</div>
                                        <div class="info-value">
                                            <a href="<?php echo e($interview['interview_link']); ?>" target="_blank" class="text-decoration-none">
                                                <i class="bi bi-link"></i> <?php echo e($interview['interview_link']); ?>
                                            </a>
                                        </div>
                                    <?php elseif ($interview['interview_mode'] === 'In-Person' && !empty($interview['location'])): ?>
                                        <div class="info-label">Location</div>
                                        <div class="info-value">
                                            <i class="bi bi-geo-alt"></i> <?php echo e($interview['location']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($interview['status'] === 'Rescheduled' && !empty($interview['reschedule_reason'])): ?>
                                <div class="alert alert-warning mt-3" style="font-size:13px;">
                                    <i class="bi bi-arrow-repeat me-2"></i>
                                    <strong>Rescheduled:</strong> <?php echo e($interview['reschedule_reason']); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($interview['status'] === 'Cancelled' && !empty($interview['cancellation_reason'])): ?>
                                <div class="alert alert-danger mt-3" style="font-size:13px;">
                                    <i class="bi bi-x-circle me-2"></i>
                                    <strong>Cancelled:</strong> <?php echo e($interview['cancellation_reason']); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Interview Feedback Panel -->
                        <?php if ($interview['status'] === 'Completed'): ?>
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h3 class="panel-title">Interview Feedback</h3>
                                <span class="status-badge <?php echo $interview['result'] === 'Selected' ? 'status-selected' : ($interview['result'] === 'Rejected' ? 'status-rejected' : 'status-hold'); ?>">
                                    <?php echo $interview['result']; ?>
                                </span>
                            </div>

                            <!-- Ratings -->
                            <div class="row mb-4">
                                <div class="col-md-3 text-center">
                                    <div class="info-label mb-2">Overall Rating</div>
                                    <div class="rating-stars rating-large">
                                        <?php echo getRatingStars($interview['rating']); ?>
                                    </div>
                                    <?php if ($interview['rating']): ?>
                                        <div class="info-value-sm mt-1">
                                            <?php 
                                                $rating_text = ['', 'Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];
                                                echo $rating_text[$interview['rating']] ?? '';
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="info-label mb-2">Technical Skills</div>
                                    <div class="rating-stars">
                                        <?php echo getRatingStars($interview['technical_skills_rating']); ?>
                                    </div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="info-label mb-2">Communication</div>
                                    <div class="rating-stars">
                                        <?php echo getRatingStars($interview['communication_rating']); ?>
                                    </div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <div class="info-label mb-2">Attitude</div>
                                    <div class="rating-stars">
                                        <?php echo getRatingStars($interview['attitude_rating']); ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Strengths & Weaknesses -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="info-label mb-2">Strengths</div>
                                    <div class="info-card">
                                        <?php echo nl2br(e($interview['strengths'] ?: 'No strengths recorded')); ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-label mb-2">Weaknesses / Areas to Improve</div>
                                    <div class="info-card">
                                        <?php echo nl2br(e($interview['weaknesses'] ?: 'No weaknesses recorded')); ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Detailed Feedback -->
                            <div class="mb-3">
                                <div class="info-label mb-2">Detailed Feedback</div>
                                <div class="info-card">
                                    <?php echo nl2br(e($interview['feedback'] ?: 'No detailed feedback provided')); ?>
                                </div>
                            </div>

                            <!-- Next Round Suggestion -->
                            <?php if ($interview['next_round_suggested']): ?>
                                <div class="alert alert-info mt-3">
                                    <i class="bi bi-arrow-right-circle me-2"></i>
                                    <strong>Next Round Suggested:</strong> 
                                    <?php echo e($interview['next_round_type'] ?? 'Next Round'); ?> on 
                                    <?php echo date('d M Y', strtotime($interview['next_round_date'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Interview Timeline -->
                        <div class="panel">
                            <div class="panel-header">
                                <h3 class="panel-title">Interview Timeline</h3>
                                <span class="badge-pill"><?php echo mysqli_num_rows($timeline_result); ?> rounds</span>
                            </div>

                            <div class="timeline">
                                <?php 
                                mysqli_data_seek($timeline_result, 0);
                                $round_num = 1;
                                while ($round = mysqli_fetch_assoc($timeline_result)): 
                                    $isCurrent = ($round['id'] == $interview_id);
                                    $dotClass = $round['status'] === 'Completed' ? 'completed' : 
                                               ($round['status'] === 'Scheduled' ? 'scheduled' : 
                                               ($round['result'] === 'Selected' ? 'selected' : 
                                               ($round['result'] === 'Rejected' ? 'rejected' : '')));
                                    if ($isCurrent) $dotClass .= ' current';
                                ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot <?php echo $dotClass; ?>"></div>
                                        <div class="timeline-date">
                                            <?php echo date('d M Y, h:i A', strtotime($round['interview_date'] . ' ' . $round['interview_time'])); ?>
                                        </div>
                                        <div class="timeline-title">
                                            Round <?php echo $round['round_number']; ?>: <?php echo e($round['interview_round']); ?>
                                            <?php if ($isCurrent): ?>
                                                <span class="timeline-badge ms-2">Current</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="timeline-sub">
                                            <i class="bi bi-person"></i> Interviewer: <?php echo e($round['interviewer_name']); ?> 
                                            (<?php echo e($round['interviewer_designation']); ?>)
                                        </div>
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            <?php echo getStatusBadge($round['status']); ?>
                                            <?php if ($round['status'] === 'Completed'): ?>
                                                <?php echo getResultBadge($round['result']); ?>
                                                <?php if ($round['rating']): ?>
                                                    <span class="rating-stars ms-2">
                                                        <?php echo getRatingStars($round['rating']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($round['id'] == $interview_id && !empty($round['feedback'])): ?>
                                            <div class="mt-2 p-2" style="background:#f3f4f6; border-radius:8px; font-size:12px;">
                                                <i class="bi bi-quote"></i> <?php echo e(substr($round['feedback'], 0, 100)) . (strlen($round['feedback']) > 100 ? '...' : ''); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Additional Info -->
                    <div class="col-lg-4">
                        <!-- Interviewer Info Panel -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h3 class="panel-title">Interviewer</h3>
                            </div>

                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="candidate-avatar-sm">
                                    <?php if (!empty($interview['interviewer_photo'])): ?>
                                        <img src="../<?php echo e($interview['interviewer_photo']); ?>" alt="Photo">
                                    <?php else: ?>
                                        <?php echo initials($interview['interviewer_name']); ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="fw-900"><?php echo e($interview['interviewer_name']); ?></div>
                                    <div class="info-value-sm"><?php echo e($interview['interviewer_designation']); ?></div>
                                </div>
                            </div>

                            <div class="info-card mb-2">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="bi bi-envelope" style="color:#6b7280; font-size:12px;"></i>
                                    <span style="font-size:12px;"><?php echo e($interview['interviewer_email']); ?></span>
                                </div>
                                <?php if (!empty($interview['interviewer_phone'])): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-telephone" style="color:#6b7280; font-size:12px;"></i>
                                    <span style="font-size:12px;"><?php echo e($interview['interviewer_phone']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Position Details Panel -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h3 class="panel-title">Position Details</h3>
                                <a href="view-request.php?id=<?php echo $interview['hiring_request_id']; ?>" class="btn-action" style="padding:4px 8px;">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>

                            <div class="info-label">Request No</div>
                            <div class="info-value"><?php echo e($interview['request_no']); ?></div>

                            <div class="info-label mt-2">Position Title</div>
                            <div class="info-value"><?php echo e($interview['position_title']); ?></div>

                            <div class="row">
                                <div class="col-6">
                                    <div class="info-label">Department</div>
                                    <div class="info-value-sm"><?php echo e($interview['hiring_department']); ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="info-label">Designation</div>
                                    <div class="info-value-sm"><?php echo e($interview['hiring_designation']); ?></div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-6">
                                    <div class="info-label">Experience Required</div>
                                    <div class="info-value-sm">
                                        <?php echo $interview['experience_min']; ?> - <?php echo $interview['experience_max']; ?> years
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="info-label">Employment Type</div>
                                    <div class="info-value-sm"><?php echo e($interview['employment_type']); ?></div>
                                </div>
                            </div>

                            <div class="info-label mt-2">Location</div>
                            <div class="info-value-sm"><?php echo e($interview['job_location']); ?></div>

                            <div class="info-label mt-2">Salary Range</div>
                            <div class="info-value-sm">
                                <?php echo formatCurrency($interview['salary_min']); ?> - <?php echo formatCurrency($interview['salary_max']); ?>
                            </div>
                        </div>

                        <!-- Candidate Summary Panel -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h3 class="panel-title">Candidate Summary</h3>
                            </div>

                            <div class="info-label">Current Status</div>
                            <div class="mb-2"><?php echo getCandidateStatusBadge($interview['candidate_status']); ?></div>

                            <?php if ($interview['total_experience'] !== null): ?>
                            <div class="row">
                                <div class="col-6">
                                    <div class="info-label">Total Exp</div>
                                    <div class="info-value-sm"><?php echo number_format($interview['total_experience'], 1); ?> years</div>
                                </div>
                                <div class="col-6">
                                    <div class="info-label">Relevant Exp</div>
                                    <div class="info-value-sm"><?php echo $interview['relevant_experience'] ? number_format($interview['relevant_experience'], 1) . ' years' : '—'; ?></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-6">
                                    <div class="info-label">Current CTC</div>
                                    <div class="info-value-sm"><?php echo formatCurrency($interview['current_ctc']); ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="info-label">Expected CTC</div>
                                    <div class="info-value-sm"><?php echo formatCurrency($interview['expected_ctc']); ?></div>
                                </div>
                            </div>

                            <div class="info-label">Notice Period</div>
                            <div class="info-value-sm mb-2">
                                <?php echo $interview['notice_period'] ? $interview['notice_period'] . ' days' : '—'; ?>
                                <?php if ($interview['notice_period_negotiable']): ?>
                                    <span class="badge-pill" style="background:#d1fae5; color:#065f46; font-size:9px;">Negotiable</span>
                                <?php endif; ?>
                            </div>

                            <div class="info-label">Current Company</div>
                            <div class="info-value-sm"><?php echo e($interview['current_company'] ?: '—'); ?></div>

                            <div class="info-label mt-2">Qualification</div>
                            <div class="info-value-sm"><?php echo e($interview['qualification'] ?: '—'); ?></div>

                            <?php if (!empty($interview['skills'])): ?>
                            <div class="info-label mt-2">Skills</div>
                            <div class="mt-1">
                                <?php 
                                $skills = explode(',', $interview['skills']);
                                foreach ($skills as $skill): 
                                    if (trim($skill)): ?>
                                        <span class="skill-tag"><?php echo e(trim($skill)); ?></span>
                                <?php endif; endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($interview['resume_path'])): ?>
                            <div class="mt-3">
                                <a href="../<?php echo e($interview['resume_path']); ?>" target="_blank" class="btn-action w-100 justify-content-center">
                                    <i class="bi bi-file-pdf"></i> Download Resume
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Conversion Status (if any) -->
                        <?php if ($interview['offer_id'] || $interview['onboarding_id']): ?>
                        <div class="conversion-card mb-4">
                            <h4 class="fw-900 fs-6 mb-3">Conversion Status</h4>
                            
                            <?php if ($interview['offer_id']): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span>Offer:</span>
                                <a href="view-offer.php?id=<?php echo $interview['offer_id']; ?>" class="text-decoration-none">
                                    <?php echo e($interview['offer_no']); ?> 
                                    <?php echo getCandidateStatusBadge($interview['offer_status']); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($interview['onboarding_id']): ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Onboarding:</span>
                                <a href="view-onboarding.php?id=<?php echo $interview['onboarding_id']; ?>" class="text-decoration-none">
                                    <?php echo e($interview['onboarding_no']); ?> 
                                    <?php echo getCandidateStatusBadge($interview['onboarding_status']); ?>
                                </a>
                            </div>
                            <?php if ($interview['employee_code']): ?>
                            <div class="mt-2 p-2" style="background: #2563eb10; border-radius:8px;">
                                <small>Employee Code: <strong><?php echo e($interview['employee_code']); ?></strong></small>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Update Interview Modal -->
<div class="modal fade" id="updateInterviewModal" tabindex="-1">
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
                            <select name="status" class="form-select" id="modal_status" required>
                                <option value="Scheduled" <?php echo $interview['status'] === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="Completed" <?php echo $interview['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?php echo $interview['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="No Show" <?php echo $interview['status'] === 'No Show' ? 'selected' : ''; ?>>No Show</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="modal_result_field">
                            <label class="form-label">Result</label>
                            <select name="result" class="form-select">
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
                                    <option value="<?php echo $i; ?>" <?php echo ($interview['technical_skills_rating'] ?? 0) == $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?> / 5
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Communication</label>
                            <select name="communication_rating" class="form-select">
                                <option value="">Not Rated</option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($interview['communication_rating'] ?? 0) == $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?> / 5
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Strengths</label>
                        <textarea name="strengths" class="form-control" rows="2"><?php echo e($interview['strengths']); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Weaknesses</label>
                        <textarea name="weaknesses" class="form-control" rows="2"><?php echo e($interview['weaknesses']); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Detailed Feedback</label>
                        <textarea name="feedback" class="form-control" rows="4"><?php echo e($interview['feedback']); ?></textarea>
                    </div>

                    <div class="alert alert-info" style="font-weight:600; font-size:12px;">
                        <i class="bi bi-info-circle me-2"></i>
                        If you mark as Completed with "Selected" result, the candidate will be moved to Selected status.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-add">Update Interview</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reschedule Interview Modal -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="interviews.php">
                <input type="hidden" name="action" value="reschedule_interview">
                <input type="hidden" name="interview_id" value="<?php echo $interview_id; ?>">

                <div class="modal-header">
                    <h5 class="modal-title">Reschedule Interview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-3">Reschedule interview for <strong><?php echo e($candidateName); ?></strong></p>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">New Date</label>
                            <input type="date" name="interview_date" class="form-control" 
                                   value="<?php echo $interview['interview_date']; ?>" 
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">New Time</label>
                            <input type="time" name="interview_time" class="form-control" 
                                   value="<?php echo $interview['interview_time']; ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label required">Reason for Rescheduling</label>
                        <textarea name="reschedule_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-add" style="background: #f59e0b;">Reschedule Interview</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Interview Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="interviews.php">
                <input type="hidden" name="action" value="cancel_interview">
                <input type="hidden" name="interview_id" value="<?php echo $interview_id; ?>">

                <div class="modal-header">
                    <h5 class="modal-title">Cancel Interview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-3">Are you sure you want to cancel interview for <strong><?php echo e($candidateName); ?></strong>?</p>

                    <div class="mb-3">
                        <label class="form-label required">Reason for Cancellation</label>
                        <textarea name="cancellation_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-add" style="background: #ef4444;">Cancel Interview</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    $(document).ready(function() {
        // Toggle result field based on status
        $('#modal_status').change(function() {
            if ($(this).val() === 'Completed') {
                $('#modal_result_field').show();
            } else {
                $('#modal_result_field').hide();
            }
        });
        
        // Initial state
        if ($('#modal_status').val() !== 'Completed') {
            $('#modal_result_field').hide();
        }
    });
                                    
    function openUpdateModal() {
        new bootstrap.Modal(document.getElementById('updateInterviewModal')).show();
    }

    function openRescheduleModal() {
        new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
    }

    function openCancelModal() {
        new bootstrap.Modal(document.getElementById('cancelModal')).show();
    }
    function getCandidateStatusBadge($status) {
    $classes = [
        'New' => 'status-new',
        'Screening' => 'status-screening',
        'Shortlisted' => 'status-shortlisted',
        'Interview Scheduled' => 'status-interview',
        'Interviewed' => 'status-interviewed',
        'Selected' => 'status-selected',
        'Rejected' => 'status-rejected',
        'On Hold' => 'status-hold',
        'Offered' => 'status-offered',
        'Joined' => 'status-joined',
        'Declined' => 'status-declined'
    ];
    $class = $classes[$status] ?? 'status-new';
    return "<span class='status-badge {$class}'><i class='bi bi-circle-fill' style='font-size:8px;'></i> {$status}</span>";
}
</script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>