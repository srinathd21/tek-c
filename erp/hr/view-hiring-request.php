<?php
// hr/view-hiring-request.php - View Hiring Request Details
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

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

// ---------------- GET HIRING REQUEST ID ----------------
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($request_id === 0) {
    header("Location: hiring-requests.php");
    exit;
}

// ---------------- FETCH HIRING REQUEST DETAILS ----------------
$query = "
    SELECT h.*, 
           e1.full_name as requester_name, e1.employee_code as requester_code, e1.designation as requester_designation,
           e2.full_name as approver_name, e2.employee_code as approver_code,
           e3.full_name as rejecter_name
    FROM hiring_requests h
    LEFT JOIN employees e1 ON h.requested_by = e1.id
    LEFT JOIN employees e2 ON h.approved_by = e2.id
    LEFT JOIN employees e3 ON h.rejected_by = e3.id
    WHERE h.id = ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$request) {
    $_SESSION['flash_error'] = "Hiring request not found.";
    header("Location: hiring-requests.php");
    exit;
}

// Check permission - managers can only view their own requests
if (!$isHr && !$isAdmin && $request['requested_by'] != $current_employee_id) {
    $_SESSION['flash_error'] = "You don't have permission to view this request.";
    header("Location: hiring-requests.php");
    exit;
}

// ---------------- FETCH CANDIDATES FOR THIS REQUEST ----------------
$candidates_query = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM interviews WHERE candidate_id = c.id) as interview_count,
           (SELECT MAX(round_number) FROM interviews WHERE candidate_id = c.id) as current_round
    FROM candidates c
    WHERE c.hiring_request_id = ?
    ORDER BY c.created_at DESC
";
$candidates_stmt = mysqli_prepare($conn, $candidates_query);
mysqli_stmt_bind_param($candidates_stmt, "i", $request_id);
mysqli_stmt_execute($candidates_stmt);
$candidates_result = mysqli_stmt_get_result($candidates_stmt);
$candidates = [];
$candidate_counts = [
    'total' => 0,
    'new' => 0,
    'screening' => 0,
    'shortlisted' => 0,
    'interview' => 0,
    'selected' => 0,
    'rejected' => 0,
    'offered' => 0,
    'joined' => 0
];

while ($row = mysqli_fetch_assoc($candidates_result)) {
    $candidates[] = $row;
    $candidate_counts['total']++;
    
    switch ($row['status']) {
        case 'New': $candidate_counts['new']++; break;
        case 'Screening': $candidate_counts['screening']++; break;
        case 'Shortlisted': $candidate_counts['shortlisted']++; break;
        case 'Interview Scheduled':
        case 'Interviewed': $candidate_counts['interview']++; break;
        case 'Selected': $candidate_counts['selected']++; break;
        case 'Rejected':
        case 'Declined': $candidate_counts['rejected']++; break;
        case 'Offered': $candidate_counts['offered']++; break;
        case 'Joined': $candidate_counts['joined']++; break;
    }
}

// ---------------- FETCH INTERVIEWS FOR THIS REQUEST ----------------
$interviews_query = "
    SELECT i.*, 
           c.first_name, c.last_name, c.photo_path as candidate_photo,
           CONCAT(c.first_name, ' ', c.last_name) as candidate_name
    FROM interviews i
    JOIN candidates c ON i.candidate_id = c.id
    WHERE i.hiring_request_id = ?
    ORDER BY i.interview_date DESC, i.interview_time DESC
    LIMIT 10
";
$interviews_stmt = mysqli_prepare($conn, $interviews_query);
mysqli_stmt_bind_param($interviews_stmt, "i", $request_id);
mysqli_stmt_execute($interviews_stmt);
$interviews_result = mysqli_stmt_get_result($interviews_stmt);

// ---------------- HELPER FUNCTIONS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function formatCurrency($amount) {
    if (!$amount) return '—';
    return '₹ ' . number_format($amount, 2) . ' LPA';
}

function getStatusBadge($status) {
    switch($status) {
        case 'Pending':
            return '<span class="badge bg-warning text-dark px-3 py-2"><i class="bi bi-clock"></i> Pending</span>';
        case 'Approved':
            return '<span class="badge bg-success px-3 py-2"><i class="bi bi-check-circle"></i> Approved</span>';
        case 'In Progress':
            return '<span class="badge bg-info px-3 py-2"><i class="bi bi-gear"></i> In Progress</span>';
        case 'Rejected':
            return '<span class="badge bg-danger px-3 py-2"><i class="bi bi-x-circle"></i> Rejected</span>';
        case 'Closed':
            return '<span class="badge bg-secondary px-3 py-2"><i class="bi bi-check2-circle"></i> Closed</span>';
        default:
            return '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    }
}

function getPriorityBadge($priority) {
    switch($priority) {
        case 'Urgent':
            return '<span class="badge bg-danger">Urgent</span>';
        case 'High':
            return '<span class="badge bg-warning text-dark">High</span>';
        case 'Medium':
            return '<span class="badge bg-info">Medium</span>';
        case 'Low':
            return '<span class="badge bg-secondary">Low</span>';
        default:
            return '<span class="badge bg-light text-dark">' . e($priority) . '</span>';
    }
}

function getCandidateStatusBadge($status) {
    $colors = [
        'New' => 'info',
        'Screening' => 'secondary',
        'Shortlisted' => 'primary',
        'Interview Scheduled' => 'warning',
        'Interviewed' => 'dark',
        'Selected' => 'success',
        'Rejected' => 'danger',
        'On Hold' => 'secondary',
        'Offered' => 'success',
        'Joined' => 'success',
        'Declined' => 'danger'
    ];
    $color = $colors[$status] ?? 'secondary';
    return "<span class='badge bg-{$color}'>{$status}</span>";
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hiring Request Details - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px; }
        .panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 8px 24px rgba(17,24,39,.06); padding:24px; margin-bottom:24px; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; display:flex; align-items:center; gap:8px; }

        .info-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:20px; }
        .info-item{ margin-bottom:12px; }
        .info-label{ font-size:12px; color:#6b7280; font-weight:700; text-transform:uppercase; margin-bottom:4px; }
        .info-value{ font-weight:800; color:#1f2937; font-size:16px; }

        .stat-card{ background:#f9fafb; border-radius:12px; padding:16px; text-align:center; border:1px solid #e5e7eb; }
        .stat-value{ font-size:28px; font-weight:900; line-height:1; color:#1f2937; }
        .stat-label{ font-size:12px; font-weight:700; color:#6b7280; margin-top:4px; }

        .timeline{ position:relative; padding-left:30px; }
        .timeline-item{ position:relative; padding-bottom:20px; border-left:2px solid #e5e7eb; padding-left:20px; margin-left:10px; }
        .timeline-item:last-child{ border-left-color:transparent; }
        .timeline-dot{ position:absolute; left:-34px; top:0; width:16px; height:16px; border-radius:50%; background:#fff; border:3px solid; }
        .timeline-dot.pending{ border-color:#f59e0b; }
        .timeline-dot.approved{ border-color:#10b981; }
        .timeline-dot.rejected{ border-color:#ef4444; }
        .timeline-date{ font-size:12px; color:#6b7280; margin-bottom:4px; }
        .timeline-title{ font-weight:800; margin-bottom:4px; }
        .timeline-text{ font-size:13px; color:#4b5563; }

        .candidate-avatar{ width:40px; height:40px; border-radius:50%; background:#e5e7eb; display:flex; align-items:center; justify-content:center; font-weight:800; color:#4b5563; }
        .candidate-avatar img{ width:40px; height:40px; border-radius:50%; object-fit:cover; }

        .badge-count{ background:#e5e7eb; color:#374151; padding:2px 8px; border-radius:20px; font-size:12px; font-weight:700; }

        .quick-stats{ display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:20px; }
        .quick-stat{ background:#f9fafb; border-radius:8px; padding:10px; text-align:center; }
        .quick-stat .number{ font-size:20px; font-weight:900; }
        .quick-stat .label{ font-size:11px; font-weight:700; color:#6b7280; }

        .action-btn{ width:32px; height:32px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; 
            display:inline-flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; margin:0 2px; }
        .action-btn:hover{ background:#f3f4f6; }

        @media (max-width: 768px) {
            .content-scroll{ padding:12px; }
            .quick-stats{ grid-template-columns:repeat(2, 1fr); }
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
                            <i class="bi bi-file-text me-2"></i>
                            Hiring Request Details
                        </h1>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted">Request #: <?php echo e($request['request_no']); ?></span>
                            <?php echo getStatusBadge($request['status']); ?>
                            <?php echo getPriorityBadge($request['priority']); ?>
                        </div>
                    </div>
                    <div>
                        <a href="hiring-requests.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <?php if ($isHr && $request['status'] === 'Pending'): ?>
                            <button class="btn btn-success" onclick="openApproveModal()">
                                <i class="bi bi-check-lg"></i> Approve
                            </button>
                            <button class="btn btn-danger" onclick="openRejectModal()">
                                <i class="bi bi-x-lg"></i> Reject
                            </button>
                        <?php endif; ?>
                        <?php if ($request['status'] === 'Approved' || $request['status'] === 'In Progress'): ?>
                            <a href="candidates.php?hiring_id=<?php echo $request_id; ?>" class="btn btn-primary">
                                <i class="bi bi-people"></i> View Candidates (<?php echo $candidate_counts['total']; ?>)
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="number"><?php echo (int)$request['vacancies']; ?></div>
                        <div class="label">Vacancies</div>
                    </div>
                    <div class="quick-stat">
                        <div class="number"><?php echo $candidate_counts['total']; ?></div>
                        <div class="label">Total Candidates</div>
                    </div>
                    <div class="quick-stat">
                        <div class="number"><?php echo $candidate_counts['interview']; ?></div>
                        <div class="label">In Interview</div>
                    </div>
                    <div class="quick-stat">
                        <div class="number"><?php echo $candidate_counts['selected'] + $candidate_counts['offered'] + $candidate_counts['joined']; ?></div>
                        <div class="label">Selected</div>
                    </div>
                </div>

                <!-- Main Content Grid -->
                <div class="row">
                    <!-- Left Column - Request Details -->
                    <div class="col-lg-8">
                        <!-- Position Details -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-briefcase"></i>
                                    Position Details
                                </h5>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Department</div>
                                    <div class="info-value"><?php echo e($request['department']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Designation</div>
                                    <div class="info-value"><?php echo e($request['designation']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Position Title</div>
                                    <div class="info-value"><?php echo e($request['position_title']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Employment Type</div>
                                    <div class="info-value"><?php echo e($request['employment_type']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Location</div>
                                    <div class="info-value"><?php echo e($request['location']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Expected Joining</div>
                                    <div class="info-value">
                                        <?php echo $request['expected_joining_date'] ? date('d M Y', strtotime($request['expected_joining_date'])) : 'Flexible'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Experience & Compensation -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-bar-chart"></i>
                                    Experience & Compensation
                                </h5>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Experience Required</div>
                                    <div class="info-value">
                                        <?php echo (int)$request['experience_min']; ?> - <?php echo (int)$request['experience_max']; ?> years
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Salary Range</div>
                                    <div class="info-value">
                                        <?php echo formatCurrency($request['salary_min']); ?> - <?php echo formatCurrency($request['salary_max']); ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Qualification</div>
                                    <div class="info-value"><?php echo e($request['qualification'] ?: 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Skills Required</div>
                                    <div class="info-value"><?php echo e($request['skills_required'] ?: 'Not specified'); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Job Description -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-file-text"></i>
                                    Job Description
                                </h5>
                            </div>
                            
                            <div class="p-3 bg-light rounded">
                                <?php echo nl2br(e($request['job_description'])); ?>
                            </div>
                        </div>

                        <!-- Candidates Pipeline -->
                        <?php if ($candidate_counts['total'] > 0): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-people"></i>
                                    Candidate Pipeline
                                </h5>
                                <a href="candidates.php?hiring_id=<?php echo $request_id; ?>" class="btn btn-sm btn-outline-primary">
                                    View All <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Candidate</th>
                                            <th>Status</th>
                                            <th>Experience</th>
                                            <th>Expected CTC</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $display_candidates = array_slice($candidates, 0, 5);
                                        foreach ($display_candidates as $candidate): 
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="candidate-avatar" style="width:32px;height:32px;">
                                                        <?php if (!empty($candidate['photo_path'])): ?>
                                                            <img src="../<?php echo e($candidate['photo_path']); ?>" alt="Photo">
                                                        <?php else: ?>
                                                            <?php echo strtoupper(substr($candidate['first_name'], 0, 1) . substr($candidate['last_name'], 0, 1)); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?>
                                                        <?php if ($candidate['interview_count'] > 0): ?>
                                                            <small class="text-info">(R<?php echo $candidate['current_round']; ?>)</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo getCandidateStatusBadge($candidate['status']); ?></td>
                                            <td><?php echo $candidate['total_experience'] ? number_format($candidate['total_experience'], 1) . ' yrs' : 'Fresher'; ?></td>
                                            <td><?php echo $candidate['expected_ctc'] ? '₹' . number_format($candidate['expected_ctc'], 2) . ' L' : '—'; ?></td>
                                            <td>
                                                <a href="view-candidate.php?id=<?php echo $candidate['id']; ?>" class="action-btn" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if (count($candidates) > 5): ?>
                                <div class="text-center mt-2">
                                    <a href="candidates.php?hiring_id=<?php echo $request_id; ?>" class="text-decoration-none">
                                        View all <?php echo count($candidates); ?> candidates <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Recent Interviews -->
                        <?php if (mysqli_num_rows($interviews_result) > 0): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-camera-video"></i>
                                    Recent Interviews
                                </h5>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Candidate</th>
                                            <th>Round</th>
                                            <th>Date & Time</th>
                                            <th>Interviewer</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($interview = mysqli_fetch_assoc($interviews_result)): ?>
                                        <tr>
                                            <td><?php echo e($interview['candidate_name']); ?></td>
                                            <td><?php echo e($interview['interview_round']); ?></td>
                                            <td>
                                                <?php echo date('d M Y', strtotime($interview['interview_date'])); ?><br>
                                                <small><?php echo date('h:i A', strtotime($interview['interview_time'])); ?></small>
                                            </td>
                                            <td><?php echo e($interview['interviewer_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $interview['status'] === 'Scheduled' ? 'warning' : ($interview['status'] === 'Completed' ? 'success' : 'secondary'); ?>">
                                                    <?php echo e($interview['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column - Request Info & Timeline -->
                    <div class="col-lg-4">
                        <!-- Request Information -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-info-circle"></i>
                                    Request Information
                                </h5>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Requested By</div>
                                <div class="info-value"><?php echo e($request['requester_name']); ?></div>
                                <div class="small text-muted"><?php echo e($request['requester_designation']); ?> (<?php echo e($request['requester_code']); ?>)</div>
                            </div>
                            
                            <div class="info-item mt-3">
                                <div class="info-label">Requested Date</div>
                                <div class="info-value"><?php echo date('d M Y', strtotime($request['requested_date'])); ?></div>
                            </div>
                            
                            <div class="info-item mt-3">
                                <div class="info-label">Reason for Hiring</div>
                                <div class="info-value"><?php echo e($request['reason_for_hiring']); ?></div>
                                <?php if ($request['replacement_for']): ?>
                                    <div class="small text-muted">Replacement for: <?php echo e($request['replacement_for']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($request['status'] === 'Approved' || $request['status'] === 'In Progress'): ?>
                            <div class="info-item mt-3">
                                <div class="info-label">Approved By</div>
                                <div class="info-value"><?php echo e($request['approver_name'] ?: 'N/A'); ?></div>
                                <?php if ($request['approved_at']): ?>
                                    <div class="small text-muted"><?php echo date('d M Y, h:i A', strtotime($request['approved_at'])); ?></div>
                                <?php endif; ?>
                                <?php if ($request['approver_remarks']): ?>
                                    <div class="small text-info mt-1">"<?php echo e($request['approver_remarks']); ?>"</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($request['status'] === 'Rejected'): ?>
                            <div class="info-item mt-3">
                                <div class="info-label">Rejected By</div>
                                <div class="info-value"><?php echo e($request['rejecter_name'] ?: 'N/A'); ?></div>
                                <?php if ($request['rejected_at']): ?>
                                    <div class="small text-muted"><?php echo date('d M Y, h:i A', strtotime($request['rejected_at'])); ?></div>
                                <?php endif; ?>
                                <?php if ($request['rejection_reason']): ?>
                                    <div class="alert alert-danger mt-2 p-2 small">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <?php echo e($request['rejection_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Timeline -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-clock-history"></i>
                                    Timeline
                                </h5>
                            </div>
                            
                            <div class="timeline">
                                <!-- Created -->
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date"><?php echo date('d M Y, h:i A', strtotime($request['created_at'])); ?></div>
                                    <div class="timeline-title">Request Created</div>
                                    <div class="timeline-text">By <?php echo e($request['requester_name']); ?></div>
                                </div>
                                
                                <!-- Status changes -->
                                <?php if ($request['approved_at']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date"><?php echo date('d M Y, h:i A', strtotime($request['approved_at'])); ?></div>
                                    <div class="timeline-title">Request Approved</div>
                                    <div class="timeline-text">By <?php echo e($request['approver_name']); ?></div>
                                    <?php if ($request['approver_remarks']): ?>
                                        <div class="timeline-text small text-info">"<?php echo e($request['approver_remarks']); ?>"</div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($request['rejected_at']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot rejected"></div>
                                    <div class="timeline-date"><?php echo date('d M Y, h:i A', strtotime($request['rejected_at'])); ?></div>
                                    <div class="timeline-title">Request Rejected</div>
                                    <div class="timeline-text">By <?php echo e($request['rejecter_name']); ?></div>
                                    <div class="timeline-text small text-danger"><?php echo e($request['rejection_reason']); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Latest candidate activity -->
                                <?php if ($candidate_counts['joined'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date">Recent</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['joined']; ?> Candidate(s) Joined</div>
                                </div>
                                <?php elseif ($candidate_counts['offered'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date">Recent</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['offered']; ?> Offer(s) Sent</div>
                                </div>
                                <?php elseif ($candidate_counts['selected'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date">Recent</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['selected']; ?> Candidate(s) Selected</div>
                                </div>
                                <?php elseif ($candidate_counts['interview'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot pending"></div>
                                    <div class="timeline-date">Current</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['interview']; ?> Interview(s) in Progress</div>
                                </div>
                                <?php elseif ($candidate_counts['total'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot pending"></div>
                                    <div class="timeline-date">Current</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['total']; ?> Candidate(s) in Pipeline</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Candidate Summary -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-pie-chart"></i>
                                    Candidate Summary
                                </h5>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>New / Screening</span>
                                    <span class="fw-bold"><?php echo $candidate_counts['new'] + $candidate_counts['screening']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo $candidate_counts['total'] > 0 ? (($candidate_counts['new'] + $candidate_counts['screening']) / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Shortlisted</span>
                                    <span class="fw-bold"><?php echo $candidate_counts['shortlisted']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-primary" style="width: <?php echo $candidate_counts['total'] > 0 ? ($candidate_counts['shortlisted'] / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Interview</span>
                                    <span class="fw-bold"><?php echo $candidate_counts['interview']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo $candidate_counts['total'] > 0 ? ($candidate_counts['interview'] / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Selected / Offered</span>
                                    <span class="fw-bold"><?php echo $candidate_counts['selected'] + $candidate_counts['offered']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $candidate_counts['total'] > 0 ? (($candidate_counts['selected'] + $candidate_counts['offered']) / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Joined</span>
                                    <span class="fw-bold"><?php echo $candidate_counts['joined']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $candidate_counts['total'] > 0 ? ($candidate_counts['joined'] / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Rejected</span>
                                    <span class="fw-bold"><?php echo $candidate_counts['rejected']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-danger" style="width: <?php echo $candidate_counts['total'] > 0 ? ($candidate_counts['rejected'] / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Approve Modal (HR only) -->
<?php if ($isHr && $request['status'] === 'Pending'): ?>
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="hiring-requests.php">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">Approve Hiring Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p>Are you sure you want to approve request <strong><?php echo e($request['request_no']); ?></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="hiring-requests.php">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">Reject Hiring Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p>Are you sure you want to reject request <strong><?php echo e($request['request_no']); ?></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold required">Reason for Rejection</label>
                        <textarea name="remarks" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openApproveModal() {
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function openRejectModal() {
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>