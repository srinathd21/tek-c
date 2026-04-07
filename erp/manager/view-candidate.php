<?php
// hr/view-candidate.php - View Candidate Details (TEK-C Style)
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

// ---------------- GET CANDIDATE ID ----------------
$candidate_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($candidate_id === 0) {
    header("Location: candidates.php");
    exit;
}

// ---------------- FETCH CANDIDATE DETAILS ----------------
$query = "
    SELECT c.*, 
           h.request_no, h.position_title, h.department, h.designation as hiring_designation,
           h.location as job_location, h.experience_min, h.experience_max,
           h.salary_min, h.salary_max, h.job_description,
           e1.full_name as created_by_name, e1.employee_code as created_by_code
    FROM candidates c
    LEFT JOIN hiring_requests h ON c.hiring_request_id = h.id
    LEFT JOIN employees e1 ON c.created_by = e1.id
    WHERE c.id = ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $candidate_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$candidate = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$candidate) {
    $_SESSION['flash_error'] = "Candidate not found.";
    header("Location: candidates.php");
    exit;
}

// Check permission - managers can only view candidates from their requests
if (!$isHr && !$isAdmin) {
    $check_query = "
        SELECT h.requested_by 
        FROM candidates c
        JOIN hiring_requests h ON c.hiring_request_id = h.id
        WHERE c.id = ?
    ";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "i", $candidate_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $check_row = mysqli_fetch_assoc($check_result);
    
    if ($check_row['requested_by'] != $current_employee_id) {
        $_SESSION['flash_error'] = "You don't have permission to view this candidate.";
        header("Location: candidates.php");
        exit;
    }
}

// ---------------- FETCH INTERVIEWS FOR THIS CANDIDATE ----------------
$interviews_query = "
    SELECT i.*, 
           e.full_name as interviewer_full_name,
           e.designation as interviewer_designation
    FROM interviews i
    LEFT JOIN employees e ON i.interviewer_id = e.id
    WHERE i.candidate_id = ?
    ORDER BY i.interview_date DESC, i.interview_time DESC
";
$interviews_stmt = mysqli_prepare($conn, $interviews_query);
mysqli_stmt_bind_param($interviews_stmt, "i", $candidate_id);
mysqli_stmt_execute($interviews_stmt);
$interviews_result = mysqli_stmt_get_result($interviews_stmt);
$interview_count = mysqli_num_rows($interviews_result);

// ---------------- FETCH OFFER FOR THIS CANDIDATE (if any) ----------------
$offer_query = "
    SELECT * FROM offers 
    WHERE candidate_id = ? 
    ORDER BY created_at DESC 
    LIMIT 1
";
$offer_stmt = mysqli_prepare($conn, $offer_query);
mysqli_stmt_bind_param($offer_stmt, "i", $candidate_id);
mysqli_stmt_execute($offer_stmt);
$offer_result = mysqli_stmt_get_result($offer_stmt);
$offer = mysqli_fetch_assoc($offer_result);

// ---------------- FETCH ONBOARDING FOR THIS CANDIDATE (if any) ----------------
$onboarding_query = "
    SELECT * FROM onboarding 
    WHERE candidate_id = ? 
    LIMIT 1
";
$onboarding_stmt = mysqli_prepare($conn, $onboarding_query);
mysqli_stmt_bind_param($onboarding_stmt, "i", $candidate_id);
mysqli_stmt_execute($onboarding_stmt);
$onboarding_result = mysqli_stmt_get_result($onboarding_stmt);
$onboarding = mysqli_fetch_assoc($onboarding_result);
// Temporarily remove the status filter for testing
        $employees_query = "SELECT id, full_name, designation FROM employees ORDER BY full_name";
        $employees_result = mysqli_query($conn, $employees_query);
// ---------------- HANDLE STATUS UPDATE ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $new_status = mysqli_real_escape_string($conn, $_POST['status']);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        
        $update_stmt = mysqli_prepare($conn, "UPDATE candidates SET status = ?, remarks = CONCAT(remarks, '\n[', NOW(), '] ', ?) WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "ssi", $new_status, $remarks, $candidate_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'candidate',
                "Updated candidate status to {$new_status}",
                $candidate_id,
                $candidate['candidate_code'],
                null,
                json_encode(['status' => $new_status, 'remarks' => $remarks])
            );
            
            $message = "Candidate status updated successfully!";
            $messageType = "success";
            
            // Refresh candidate data
            $candidate['status'] = $new_status;
        } else {
            $message = "Error updating status: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
}

// ---------------- HELPER FUNCTIONS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function formatCurrency($amount) {
    if (!$amount || $amount == 0) return '—';
    return '₹ ' . number_format($amount, 2) . ' LPA';
}

function getStatusBadge($status) {
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

function getInterviewStatusBadge($status) {
    $classes = [
        'Scheduled' => 'status-interview',
        'Completed' => 'status-selected',
        'Cancelled' => 'status-rejected',
        'Rescheduled' => 'status-hold',
        'No Show' => 'status-rejected'
    ];
    $class = $classes[$status] ?? 'status-screening';
    return "<span class='status-badge {$class}'><i class='bi bi-circle-fill' style='font-size:8px;'></i> {$status}</span>";
}

function getFullName($first, $last) {
    return trim($first . ' ' . $last);
}

function timeAgo($datetime) {
    if (!$datetime || $datetime == '0000-00-00 00:00:00') return 'Never';
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff/60) . ' minutes ago';
    if ($diff < 86400) return floor($diff/3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff/86400) . ' days ago';
    return date('d M Y', $timestamp);
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
$pageTitle = "Candidate: " . getFullName($candidate['first_name'], $candidate['last_name']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo e($pageTitle); ?> - TEK-C</title>
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
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

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
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .panel-title {
            font-weight: 900;
            font-size: 16px;
            color: #1f2937;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .panel-title i {
            color: var(--blue);
            font-size: 18px;
        }

        /* Candidate Header */
        .candidate-header {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .candidate-avatar-large {
            width: 90px;
            height: 90px;
            border-radius: 12px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 900;
            color: #fff;
            flex: 0 0 auto;
        }
        
        .candidate-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        
        .info-item {
            background: #f9fafb;
            border-radius: 10px;
            padding: 12px;
            border: 1px solid var(--border);
        }
        
        .info-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .info-value {
            font-weight: 800;
            color: #1f2937;
            font-size: 14px;
            word-break: break-word;
        }

        /* Quick Stats */
        .quick-stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        
        .quick-stat-value {
            font-size: 24px;
            font-weight: 900;
            color: var(--blue);
            line-height: 1.2;
        }
        
        .quick-stat-label {
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
            margin-top: 5px;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding: 10px 0;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 70px;
            padding-bottom: 25px;
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        
        .timeline-badge {
            position: absolute;
            left: 0;
            top: 0;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 16px;
            border: 2px solid #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .timeline-badge.status-scheduled { background: #fef3c7; color: #92400e; }
        .timeline-badge.status-completed { background: #d1fae5; color: #065f46; }
        .timeline-badge.status-cancelled { background: #fee2e2; color: #991b1b; }
        
        .timeline-content {
            background: #f9fafb;
            border-radius: 12px;
            padding: 15px;
            border: 1px solid var(--border);
        }
        
        .timeline-date {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .timeline-title {
            font-weight: 900;
            font-size: 14px;
            margin-bottom: 8px;
        }

        /* Action Buttons */
        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 10px;
            color: var(--muted);
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-action:hover { background: var(--bg); color: var(--blue); }
        .btn-action.primary:hover { background: #dbeafe; color: #1e40af; border-color: #1e40af; }
        .btn-action.success:hover { background: #d1fae5; color: #065f46; border-color: #065f46; }

        /* Status Badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
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

        /* Resume Preview */
        .resume-preview {
            background: #f9fafb;
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 25px;
            text-align: center;
        }
        .resume-preview i { font-size: 48px; color: #9ca3af; }

        /* Skills */
        .skill-badge {
            background: #f3f4f6;
            color: #374151;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            display: inline-block;
            margin: 0 5px 5px 0;
            border: 1px solid var(--border);
        }

        /* Meta Info */
        .meta-item {
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .meta-item:last-child { border-bottom: none; }
        .meta-label {
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
        }
        .meta-value {
            font-weight: 800;
            color: #1f2937;
            font-size: 14px;
            margin-top: 2px;
        }

        /* Form Elements */
        .form-label {
            font-weight: 800;
            font-size: 12px;
            color: #4b5563;
            margin-bottom: 4px;
        }
        .required:after {
            content: " *";
            color: #ef4444;
        }

        /* Alert */
        .alert {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .content-scroll { padding: 12px; }
            .candidate-header { flex-direction: column; text-align: center; }
        }
    </style>

    <!-- JavaScript - MOVED TO HEAD FOR BETTER AVAILABILITY -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
    // DEFINE THE FUNCTION GLOBALLY BEFORE ANY BUTTON CAN CALL IT
    function openScheduleInterviewModal() {
        console.log('openScheduleInterviewModal called');
        var modalElement = document.getElementById('scheduleInterviewModal');
        if (modalElement) {
            var modal = new bootstrap.Modal(modalElement);
            modal.show();
        } else {
            console.error('Modal element not found');
            alert('Error: Could not open interview scheduling form. Please refresh the page.');
        }
    }
    
    // Make it available on window object as well
    window.openScheduleInterviewModal = openScheduleInterviewModal;
    
    $(document).ready(function() {
        console.log('Document ready - Initializing components');
        
        // Initialize Select2
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#scheduleInterviewModal'),
            placeholder: 'Select an option',
            allowClear: true
        });

        // Show/hide location/link based on interview mode
        $('select[name="interview_mode"]').change(function() {
            var selectedMode = $(this).val();
            
            if (selectedMode === 'Online') {
                $('#onlineLinkField').show();
                $('#locationField').hide();
                $('input[name="location"]').val('').prop('required', false);
                $('input[name="interview_link"]').prop('required', false);
            } else if (selectedMode === 'In-Person') {
                $('#onlineLinkField').hide();
                $('#locationField').show();
                $('input[name="interview_link"]').val('').prop('required', false);
                $('input[name="location"]').prop('required', true);
            } else {
                $('#onlineLinkField').hide();
                $('#locationField').hide();
                $('input[name="interview_link"]').val('').prop('required', false);
                $('input[name="location"]').val('').prop('required', false);
            }
        });
        
        // Trigger change event to set initial state
        $('select[name="interview_mode"]').trigger('change');
        
        // Set minimum date for interview date picker
        var today = new Date().toISOString().split('T')[0];
        $('input[name="interview_date"]').attr('min', today);
        
        // Validate date selection
        $('input[name="interview_date"]').change(function() {
            var selectedDate = $(this).val();
            if (selectedDate < today) {
                alert('Please select today or a future date for the interview.');
                $(this).val(today);
            }
        });
        
        // Auto-set round number based on existing interviews count
        var interviewCount = <?php echo $interview_count; ?>;
        $('#interview_round').val(interviewCount + 1);
        
        // Reset modal form when closed
        $('#scheduleInterviewModal').on('hidden.bs.modal', function() {
            $(this).find('form')[0].reset();
            $('select[name="interview_mode"]').trigger('change');
            $('.select2').val(null).trigger('change');
        });
        
        console.log('All components initialized successfully');
    });
    </script>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content-scroll">
            <div class="container-fluid maxw">

                <!-- Flash Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
                        <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">
                            <i class="bi bi-person-badge me-2"></i>
                            Candidate Profile
                        </h1>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted" style="font-size: 13px; font-weight: 600;">
                                <i class="bi bi-hash"></i> <?php echo e($candidate['candidate_code']); ?>
                            </span>
                            <?php echo getStatusBadge($candidate['status']); ?>
                        </div>
                    </div>
                    <div>
                        <a href="candidates.php<?php echo $candidate['hiring_request_id'] ? '?hiring_id=' . $candidate['hiring_request_id'] : ''; ?>" class="btn-action me-2" title="Back">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <?php if ($candidate['status'] === 'Selected' && $isHr && !$offer): ?>
                            <a href="offer-approval.php?candidate_id=<?php echo $candidate_id; ?>" class="btn-action success">
                                <i class="bi bi-file-text"></i> Create Offer
                            </a>
                        <?php endif; ?>
                        <?php if ($offer && $offer['status'] === 'Accepted' && !$onboarding && $isHr): ?>
                            <a href="onboarding.php?candidate_id=<?php echo $candidate_id; ?>" class="btn-action primary">
                                <i class="bi bi-person-check"></i> Start Onboarding
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Candidate Header Card -->
                <div class="panel">
                    <div class="candidate-header">
                        <div class="candidate-avatar-large">
                            <?php if (!empty($candidate['photo_path'])): ?>
                                <img src="../<?php echo e($candidate['photo_path']); ?>" alt="Photo">
                            <?php else: ?>
                                <?php echo strtoupper(substr($candidate['first_name'] ?? '', 0, 1) . substr($candidate['last_name'] ?? '', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;">
                            <h2 class="fw-bold mb-2"><?php echo e(getFullName($candidate['first_name'], $candidate['last_name'])); ?></h2>
                            <div class="d-flex flex-wrap gap-3">
                                <div><i class="bi bi-envelope text-muted"></i> <?php echo e($candidate['email']); ?></div>
                                <div><i class="bi bi-telephone text-muted"></i> <?php echo e($candidate['phone']); ?></div>
                                <?php if (!empty($candidate['alternate_phone'])): ?>
                                    <div><i class="bi bi-telephone text-muted"></i> <?php echo e($candidate['alternate_phone']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2">
                                <span class="skill-badge">
                                    <i class="bi bi-building"></i> Source: <?php echo e($candidate['source'] ?? 'Not specified'); ?>
                                </span>
                                <?php if (!empty($candidate['referred_by'])): ?>
                                    <span class="skill-badge">
                                        <i class="bi bi-person-plus"></i> Referred by: <?php echo e($candidate['referred_by']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <button class="btn-action" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="quick-stat-card">
                            <div class="quick-stat-value">
                                <?php echo !empty($candidate['total_experience']) ? number_format($candidate['total_experience'], 1) . ' yrs' : 'Fresher'; ?>
                            </div>
                            <div class="quick-stat-label">Total Experience</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-stat-card">
                            <div class="quick-stat-value"><?php echo formatCurrency($candidate['expected_ctc']); ?></div>
                            <div class="quick-stat-label">Expected CTC</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-stat-card">
                            <div class="quick-stat-value">
                                <?php echo !empty($candidate['notice_period']) ? $candidate['notice_period'] . ' days' : '—'; ?>
                            </div>
                            <div class="quick-stat-label">Notice Period</div>
                            <?php if (!empty($candidate['notice_period_negotiable'])): ?>
                                <small class="text-success">(Negotiable)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="quick-stat-card">
                            <div class="quick-stat-value"><?php echo $interview_count; ?></div>
                            <div class="quick-stat-label">Interviews</div>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="row">
                    <!-- Left Column - Personal & Professional Details -->
                    <div class="col-lg-8">
                        <!-- Professional Details -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-briefcase"></i>
                                    Professional Details
                                </h5>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Current Company</div>
                                    <div class="info-value"><?php echo e($candidate['current_company'] ?: 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Qualification</div>
                                    <div class="info-value"><?php echo e($candidate['qualification'] ?: 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Current Location</div>
                                    <div class="info-value"><?php echo e($candidate['current_location'] ?: 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Preferred Location</div>
                                    <div class="info-value"><?php echo e($candidate['preferred_location'] ?: 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Current CTC</div>
                                    <div class="info-value"><?php echo formatCurrency($candidate['current_ctc']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Expected CTC</div>
                                    <div class="info-value"><?php echo formatCurrency($candidate['expected_ctc']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Relevant Experience</div>
                                    <div class="info-value">
                                        <?php echo !empty($candidate['relevant_experience']) ? number_format($candidate['relevant_experience'], 1) . ' years' : '—'; ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Notice Period</div>
                                    <div class="info-value">
                                        <?php echo !empty($candidate['notice_period']) ? $candidate['notice_period'] . ' days' : '—'; ?>
                                        <?php if (!empty($candidate['notice_period_negotiable'])): ?>
                                            <span class="status-selected" style="padding:2px 6px; margin-left:5px;">Negotiable</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Skills -->
                        <?php if (!empty($candidate['skills'])): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-code-square"></i>
                                    Skills
                                </h5>
                            </div>
                            
                            <div>
                                <?php 
                                $skills = explode(',', $candidate['skills']);
                                foreach ($skills as $skill): 
                                    $skill = trim($skill);
                                    if (!empty($skill)):
                                ?>
                                    <span class="skill-badge"><?php echo e($skill); ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Interview Timeline -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-camera-video"></i>
                                    Interview Timeline
                                </h5>
                                <?php if (!in_array($candidate['status'], ['Rejected', 'Joined', 'Declined', 'Offered'])): ?>
                                    <button class="btn-action primary" onclick="openScheduleInterviewModal()">
                                        <i class="bi bi-plus-circle"></i> Schedule Interview
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($interview_count === 0): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                                    No interviews scheduled yet.
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php 
                                    mysqli_data_seek($interviews_result, 0);
                                    while ($interview = mysqli_fetch_assoc($interviews_result)): 
                                    ?>
                                        <div class="timeline-item">
                                            <div class="timeline-badge status-<?php echo strtolower($interview['status'] ?? 'scheduled'); ?>">
                                                R<?php echo $interview['round_number']; ?>
                                            </div>
                                            <div class="timeline-content">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <div class="timeline-date">
                                                            <i class="bi bi-calendar"></i> <?php echo date('d M Y', strtotime($interview['interview_date'])); ?> 
                                                            at <?php echo date('h:i A', strtotime($interview['interview_time'])); ?>
                                                            (<?php echo $interview['interview_duration']; ?> min)
                                                        </div>
                                                        <div class="timeline-title">
                                                            <?php echo e($interview['interview_round']); ?> 
                                                            <?php echo getInterviewStatusBadge($interview['status'] ?? 'Scheduled'); ?>
                                                        </div>
                                                        <div class="mt-2">
                                                            <div><i class="bi bi-person"></i> Interviewer: <?php echo e($interview['interviewer_full_name']); ?></div>
                                                            <div><i class="bi bi-geo-alt"></i> Mode: <?php echo e($interview['interview_mode']); ?></div>
                                                            <?php if (!empty($interview['interview_link'])): ?>
                                                                <div><i class="bi bi-link"></i> <a href="<?php echo e($interview['interview_link']); ?>" target="_blank">Meeting Link</a></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($interview['location'])): ?>
                                                                <div><i class="bi bi-pin-map"></i> <?php echo e($interview['location']); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($interview['feedback'])): ?>
                                                            <div class="mt-2 p-2" style="background:#fff; border-radius:8px;">
                                                                <strong>Feedback:</strong><br>
                                                                <?php echo nl2br(e($interview['feedback'])); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <a href="view-interview.php?id=<?php echo $interview['id']; ?>" class="btn-action" title="View Details">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Remarks/Notes -->
                        <?php if (!empty($candidate['remarks'])): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-chat-text"></i>
                                    Notes & Remarks
                                </h5>
                            </div>
                            
                            <div style="background:#f9fafb; padding:15px; border-radius:10px;">
                                <?php echo nl2br(e($candidate['remarks'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column - Application Details & Actions -->
                    <div class="col-lg-4">
                        <!-- Status Update -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-arrow-repeat"></i>
                                    Update Status
                                </h5>
                            </div>
                            
                            <form method="POST">
                                <input type="hidden" name="action" value="update_status">
                                
                                <div class="mb-3">
                                    <label class="form-label">Current Status</label>
                                    <select name="status" class="form-select form-select-sm">
                                        <?php 
                                        $status_options = [
                                            'New', 'Screening', 'Shortlisted', 'Interview Scheduled', 
                                            'Interviewed', 'Selected', 'Rejected', 'On Hold', 'Offered', 'Joined', 'Declined'
                                        ];
                                        foreach ($status_options as $option): 
                                        ?>
                                            <option value="<?php echo $option; ?>" <?php echo $candidate['status'] === $option ? 'selected' : ''; ?>>
                                                <?php echo $option; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Remarks</label>
                                    <textarea name="remarks" class="form-control form-control-sm" rows="2" placeholder="Add notes about this status change..."></textarea>
                                </div>
                                
                                <button type="submit" class="btn-action primary w-100 justify-content-center">
                                    <i class="bi bi-check-lg"></i> Update Status
                                </button>
                            </form>
                        </div>

                        <!-- Hiring Request Details -->
                        <?php if (!empty($candidate['request_no'])): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-file-text"></i>
                                    Position Details
                                </h5>
                                <a href="view-hiring-request.php?id=<?php echo $candidate['hiring_request_id']; ?>" class="btn-action">
                                    View
                                </a>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Request No.</div>
                                <div class="info-value"><?php echo e($candidate['request_no']); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Position</div>
                                <div class="info-value"><?php echo e($candidate['position_title']); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Department</div>
                                <div class="info-value"><?php echo e($candidate['department']); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Designation</div>
                                <div class="info-value"><?php echo e($candidate['hiring_designation']); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Location</div>
                                <div class="info-value"><?php echo e($candidate['job_location'] ?: 'Not specified'); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Experience Required</div>
                                <div class="info-value">
                                    <?php echo ($candidate['experience_min'] ?? '0'); ?> - <?php echo ($candidate['experience_max'] ?? '0'); ?> years
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Salary Range</div>
                                <div class="info-value">
                                    <?php echo formatCurrency($candidate['salary_min']); ?> - <?php echo formatCurrency($candidate['salary_max']); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Offer Details -->
                        <?php if ($offer): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-file-text"></i>
                                    Offer Details
                                </h5>
                                <?php echo getStatusBadge($offer['status']); ?>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Offer No.</div>
                                <div class="info-value"><?php echo e($offer['offer_no']); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Offer Date</div>
                                <div class="info-value"><?php echo date('d M Y', strtotime($offer['offer_date'])); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Valid Till</div>
                                <div class="info-value"><?php echo date('d M Y', strtotime($offer['offer_valid_till'])); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">CTC Offered</div>
                                <div class="info-value"><?php echo formatCurrency($offer['ctc']); ?></div>
                            </div>
                            
                            <?php if ($offer['status'] === 'Accepted' && !empty($offer['expected_joining_date'])): ?>
                                <div class="info-item">
                                    <div class="info-label">Expected Joining</div>
                                    <div class="info-value"><?php echo date('d M Y', strtotime($offer['expected_joining_date'])); ?></div>
                                </div>
                            <?php endif; ?>
                            
                            <a href="view-offer.php?id=<?php echo $offer['id']; ?>" class="btn-action primary w-100 justify-content-center mt-3">
                                <i class="bi bi-eye"></i> View Offer Details
                            </a>
                        </div>
                        <?php endif; ?>

                        <!-- Onboarding Details -->
                        <?php if ($onboarding): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-person-check"></i>
                                    Onboarding Status
                                </h5>
                                <?php echo getStatusBadge($onboarding['status']); ?>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Onboarding No.</div>
                                <div class="info-value"><?php echo e($onboarding['onboarding_no']); ?></div>
                            </div>
                            
                            <div class="info-item mb-2">
                                <div class="info-label">Joining Date</div>
                                <div class="info-value"><?php echo date('d M Y', strtotime($onboarding['joining_date'])); ?></div>
                            </div>
                            
                            <?php if (!empty($onboarding['employee_code'])): ?>
                            <div class="info-item">
                                <div class="info-label">Employee Code</div>
                                <div class="info-value"><?php echo e($onboarding['employee_code']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <a href="view-onboarding.php?id=<?php echo $onboarding['id']; ?>" class="btn-action primary w-100 justify-content-center mt-3">
                                <i class="bi bi-eye"></i> View Onboarding Details
                            </a>
                        </div>
                        <?php endif; ?>

                        <!-- Resume -->
                        <?php if (!empty($candidate['resume_path'])): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-file-pdf"></i>
                                    Resume
                                </h5>
                            </div>
                            
                            <div class="resume-preview">
                                <i class="bi bi-file-earmark-pdf"></i>
                                <p class="mt-2">Candidate Resume</p>
                                <a href="../<?php echo e($candidate['resume_path']); ?>" target="_blank" class="btn-action primary">
                                    <i class="bi bi-download"></i> Download
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Meta Information -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-info-circle"></i>
                                    Meta Information
                                </h5>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-label">Created By</div>
                                <div class="meta-value"><?php echo e($candidate['created_by_name'] ?: 'System'); ?></div>
                                <div class="small text-muted"><?php echo timeAgo($candidate['created_at']); ?></div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-label">Last Updated</div>
                                <div class="meta-value"><?php echo timeAgo($candidate['updated_at']); ?></div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-label">Candidate Code</div>
                                <div class="meta-value"><?php echo e($candidate['candidate_code']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Schedule Interview Modal -->
<div class="modal fade" id="scheduleInterviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="candidates.php">
                <input type="hidden" name="action" value="schedule_interview">
                <input type="hidden" name="candidate_id" value="<?php echo $candidate_id; ?>">
                <input type="hidden" name="hiring_request_id" value="<?php echo $candidate['hiring_request_id']; ?>">
                <input type="hidden" name="round_number" id="interview_round" value="<?php echo $interview_count + 1; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">Schedule Interview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p class="mb-3">Schedule interview for <strong><?php echo e(getFullName($candidate['first_name'], $candidate['last_name'])); ?></strong></p>
                    
                    <div class="mb-3">
                        <label class="form-label required">Interview Round</label>
                        <select name="interview_round" class="form-select" required>
                            <option value="Telephonic" <?php echo ($interview_count + 1 == 1) ? 'selected' : ''; ?>>Telephonic</option>
                            <option value="Technical Round 1" <?php echo ($interview_count + 1 == 2) ? 'selected' : ''; ?>>Technical Round 1</option>
                            <option value="Technical Round 2" <?php echo ($interview_count + 1 == 3) ? 'selected' : ''; ?>>Technical Round 2</option>
                            <option value="HR Round" <?php echo ($interview_count + 1 == 4) ? 'selected' : ''; ?>>HR Round</option>
                            <option value="Manager Round" <?php echo ($interview_count + 1 == 5) ? 'selected' : ''; ?>>Manager Round</option>
                            <option value="Final Round" <?php echo ($interview_count + 1 >= 6) ? 'selected' : ''; ?>>Final Round</option>
                        </select>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">Date</label>
                            <input type="date" name="interview_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Time</label>
                            <input type="time" name="interview_time" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">Duration</label>
                            <select name="interview_duration" class="form-select" required>
                                <option value="30">30 minutes</option>
                                <option value="45">45 minutes</option>
                                <option value="60" selected>1 hour</option>
                                <option value="90">1.5 hours</option>
                                <option value="120">2 hours</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Mode</label>
                            <select name="interview_mode" class="form-select" required>
                                <option value="Online">Online</option>
                                <option value="In-Person">In-Person</option>
                                <option value="Telephonic">Telephonic</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
    <label class="form-label required">Interviewer</label>
    <select name="interviewer_id" class="form-select select2" required>
        <option value="">Select Interviewer</option>
        <?php 
        
        
        if ($employees_result && mysqli_num_rows($employees_result) > 0) {
            while ($emp = mysqli_fetch_assoc($employees_result)) {
                ?>
                <option value="<?php echo $emp['id']; ?>">
                    <?php echo htmlspecialchars($emp['full_name']); ?> (<?php echo htmlspecialchars($emp['designation']); ?>)
                </option>
                <?php
            }
        } else {
            echo '<option value="" disabled>No employees found in database</option>';
        }
        ?>
    </select>
</div>
                    
                    <div class="mb-3" id="onlineLinkField">
                        <label class="form-label">Meeting Link</label>
                        <input type="url" name="interview_link" class="form-control" placeholder="https://meet.google.com/...">
                    </div>
                    
                    <div class="mb-3" id="locationField" style="display:none;">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control" placeholder="Office address">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-add btn btn-success">Schedule Interview</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/sidebar-toggle.js"></script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>