<?php
// hr/candidates.php - Candidate Management Page (TEK-C Style)
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

if (!$isHr && !$isManager) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- HANDLE CANDIDATE ACTIONS ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Add New Candidate
    if ($_POST['action'] === 'add_candidate' && $isHr) {
        $hiring_request_id = (int)$_POST['hiring_request_id'];
        $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
        $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $alternate_phone = !empty($_POST['alternate_phone']) ? mysqli_real_escape_string($conn, $_POST['alternate_phone']) : null;
        $current_location = mysqli_real_escape_string($conn, $_POST['current_location'] ?? '');
        $preferred_location = mysqli_real_escape_string($conn, $_POST['preferred_location'] ?? '');
        $total_experience = !empty($_POST['total_experience']) ? floatval($_POST['total_experience']) : null;
        $relevant_experience = !empty($_POST['relevant_experience']) ? floatval($_POST['relevant_experience']) : null;
        $current_ctc = !empty($_POST['current_ctc']) ? floatval($_POST['current_ctc']) : null;
        $expected_ctc = !empty($_POST['expected_ctc']) ? floatval($_POST['expected_ctc']) : null;
        $notice_period = !empty($_POST['notice_period']) ? (int)$_POST['notice_period'] : null;
        $notice_period_negotiable = isset($_POST['notice_period_negotiable']) ? 1 : 0;
        $current_company = mysqli_real_escape_string($conn, $_POST['current_company'] ?? '');
        $qualification = mysqli_real_escape_string($conn, $_POST['qualification'] ?? '');
        $skills = mysqli_real_escape_string($conn, $_POST['skills'] ?? '');
        $source = mysqli_real_escape_string($conn, $_POST['source'] ?? 'Other');
        $referred_by = !empty($_POST['referred_by']) ? mysqli_real_escape_string($conn, $_POST['referred_by']) : null;
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        
        // Handle file upload
        $resume_path = '';
        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/candidates/resumes/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
            $file_name = 'resume_' . time() . '_' . uniqid() . '.' . $file_ext;
            $target_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $target_path)) {
                $resume_path = 'uploads/candidates/resumes/' . $file_name;
            }
        }
        
        // Handle photo upload
        $photo_path = '';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/candidates/photos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $file_name = 'photo_' . time() . '_' . uniqid() . '.' . $file_ext;
            $target_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_path)) {
                $photo_path = 'uploads/candidates/photos/' . $file_name;
            }
        }
        
        // Generate candidate code
        $year = date('Y');
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as count FROM candidates WHERE YEAR(created_at) = ?");
        mysqli_stmt_bind_param($stmt, "i", $year);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        $count = $row['count'] + 1;
        $candidate_code = "CAN-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
        
        $insert_stmt = mysqli_prepare($conn, "
            INSERT INTO candidates (
                hiring_request_id, candidate_code, first_name, last_name, email, phone,
                alternate_phone, current_location, preferred_location, total_experience,
                relevant_experience, current_ctc, expected_ctc, notice_period,
                notice_period_negotiable, current_company, qualification, skills,
                resume_path, photo_path, source, referred_by, remarks, status,
                created_by, created_by_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'New', ?, ?)
        ");

        mysqli_stmt_bind_param(
            $insert_stmt,
            "issssssssddddiissssssssis",
            $hiring_request_id,
            $candidate_code,
            $first_name,
            $last_name,
            $email,
            $phone,
            $alternate_phone,
            $current_location,
            $preferred_location,
            $total_experience,
            $relevant_experience,
            $current_ctc,
            $expected_ctc,
            $notice_period,
            $notice_period_negotiable,
            $current_company,
            $qualification,
            $skills,
            $resume_path,
            $photo_path,
            $source,
            $referred_by,
            $remarks,
            $current_employee_id,
            $current_employee['full_name']
        );
        
        if (mysqli_stmt_execute($insert_stmt)) {
            $candidate_id = mysqli_insert_id($conn);
            
            logActivity(
                $conn,
                'CREATE',
                'candidate',
                "Added new candidate: {$first_name} {$last_name} ({$candidate_code})",
                $candidate_id,
                $candidate_code,
                null,
                json_encode($_POST)
            );
            
            $message = "Candidate added successfully!";
            $messageType = "success";
        } else {
            $message = "Error adding candidate: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Update Candidate Status
    elseif ($_POST['action'] === 'update_status') {
        $candidate_id = (int)$_POST['candidate_id'];
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        
        $update_stmt = mysqli_prepare($conn, "UPDATE candidates SET status = ?, remarks = CONCAT(remarks, '\n[', NOW(), '] ', ?) WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "ssi", $status, $remarks, $candidate_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'candidate',
                "Updated candidate status to {$status}",
                $candidate_id,
                null,
                null,
                json_encode(['status' => $status, 'remarks' => $remarks])
            );
            
            $message = "Candidate status updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating status: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Schedule Interview
    elseif ($_POST['action'] === 'schedule_interview') {
        $candidate_id = (int)$_POST['candidate_id'];
        $hiring_request_id = (int)$_POST['hiring_request_id'];
        $interview_round = mysqli_real_escape_string($conn, $_POST['interview_round']);
        $round_number = (int)$_POST['round_number'];
        $interview_date = mysqli_real_escape_string($conn, $_POST['interview_date']);
        $interview_time = mysqli_real_escape_string($conn, $_POST['interview_time']);
        $interview_duration = (int)$_POST['interview_duration'];
        $interview_mode = mysqli_real_escape_string($conn, $_POST['interview_mode']);
        $interview_link = !empty($_POST['interview_link']) ? mysqli_real_escape_string($conn, $_POST['interview_link']) : null;
        $location = !empty($_POST['location']) ? mysqli_real_escape_string($conn, $_POST['location']) : null;
        $interviewer_id = (int)$_POST['interviewer_id'];
        
        // Get interviewer name
        $int_stmt = mysqli_prepare($conn, "SELECT full_name FROM employees WHERE id = ?");
        mysqli_stmt_bind_param($int_stmt, "i", $interviewer_id);
        mysqli_stmt_execute($int_stmt);
        $int_res = mysqli_stmt_get_result($int_stmt);
        $int_row = mysqli_fetch_assoc($int_res);
        $interviewer_name = $int_row['full_name'];
        
        // Generate interview number
        $interview_no = "INT-" . date('Ymd') . "-" . str_pad($candidate_id, 4, '0', STR_PAD_LEFT) . "-R{$round_number}";
        
        $insert_stmt = mysqli_prepare($conn, "
            INSERT INTO interviews (
                interview_no, candidate_id, hiring_request_id, interview_round, round_number,
                interview_date, interview_time, interview_duration, interview_mode,
                interview_link, location, interviewer_id, interviewer_name,
                created_by, created_by_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        mysqli_stmt_bind_param($insert_stmt, "siisississssiis", 
            $interview_no, $candidate_id, $hiring_request_id, $interview_round, $round_number,
            $interview_date, $interview_time, $interview_duration, $interview_mode,
            $interview_link, $location, $interviewer_id, $interviewer_name,
            $current_employee_id, $current_employee['full_name']
        );
        
        if (mysqli_stmt_execute($insert_stmt)) {
            // Update candidate status to Interview Scheduled
            mysqli_query($conn, "UPDATE candidates SET status = 'Interview Scheduled' WHERE id = {$candidate_id}");
            
            logActivity(
                $conn,
                'CREATE',
                'interview',
                "Scheduled {$interview_round} for candidate ID: {$candidate_id}",
                $candidate_id,
                $interview_no,
                null,
                json_encode($_POST)
            );
            
            $message = "Interview scheduled successfully!";
            $messageType = "success";
        } else {
            $message = "Error scheduling interview: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'all';
$hiring_filter = isset($_GET['hiring_id']) ? (int)$_GET['hiring_id'] : 0;
$search = trim($_GET['search'] ?? '');

// Build query with permissions
$query = "
    SELECT c.*, 
           h.request_no, h.position_title, h.department, h.designation as hiring_designation,
           (SELECT COUNT(*) FROM interviews WHERE candidate_id = c.id) as interview_count,
           (SELECT MAX(round_number) FROM interviews WHERE candidate_id = c.id) as current_round
    FROM candidates c
    LEFT JOIN hiring_requests h ON c.hiring_request_id = h.id
    WHERE 1=1
";

// Managers see only candidates from their requests
if (!$isHr && $isManager) {
    $query .= " AND h.requested_by = {$current_employee_id}";
}

if ($status_filter !== 'all') {
    $status_filter = mysqli_real_escape_string($conn, $status_filter);
    $query .= " AND c.status = '{$status_filter}'";
}

if ($hiring_filter > 0) {
    $query .= " AND c.hiring_request_id = {$hiring_filter}";
}

if (!empty($search)) {
    $search_term = mysqli_real_escape_string($conn, $search);
    $query .= " AND (c.first_name LIKE '%{$search_term}%' OR c.last_name LIKE '%{$search_term}%' 
                     OR c.email LIKE '%{$search_term}%' OR c.phone LIKE '%{$search_term}%'
                     OR c.candidate_code LIKE '%{$search_term}%')";
}

$query .= " ORDER BY c.created_at DESC";

$candidates = mysqli_query($conn, $query);

// Get hiring requests for dropdown
$hiring_query = "SELECT id, request_no, position_title, department FROM hiring_requests WHERE status IN ('Approved', 'In Progress')";
if (!$isHr && $isManager) {
    $hiring_query .= " AND requested_by = {$current_employee_id}";
}
$hiring_query .= " ORDER BY created_at DESC";
$hiring_requests = mysqli_query($conn, $hiring_query);

// Get employees for interviewer dropdown
$employees_query = "SELECT id, full_name, designation FROM employees WHERE employee_status = 'active' ORDER BY full_name";
$employees = mysqli_query($conn, $employees_query);

// Status options for candidate pipeline
$status_options = [
    'New' => 'New',
    'Screening' => 'Screening',
    'Shortlisted' => 'Shortlisted',
    'Interview Scheduled' => 'Interview Scheduled',
    'Interviewed' => 'Interviewed',
    'Selected' => 'Selected',
    'Rejected' => 'Rejected',
    'On Hold' => 'On Hold',
    'Offered' => 'Offered',
    'Joined' => 'Joined',
    'Declined' => 'Declined'
];

// Interview round options
$interview_rounds = [
    'Telephonic' => 'Telephonic',
    'Technical Round 1' => 'Technical Round 1',
    'Technical Round 2' => 'Technical Round 2',
    'HR Round' => 'HR Round',
    'Manager Round' => 'Manager Round',
    'Final Round' => 'Final Round'
];

// ---------------- HELPER FUNCTIONS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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

function getFullName($first, $last) {
    return trim($first . ' ' . $last);
}

// Stats for candidates
$stats = [
    'total' => 0,
    'new' => 0,
    'interview' => 0,
    'selected' => 0
];

$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) as new,
    SUM(CASE WHEN status = 'Interview Scheduled' THEN 1 ELSE 0 END) as interview,
    SUM(CASE WHEN status IN ('Selected', 'Offered', 'Joined') THEN 1 ELSE 0 END) as selected
FROM candidates c";
if (!$isHr && $isManager) {
    $stats_query .= " LEFT JOIN hiring_requests h ON c.hiring_request_id = h.id WHERE h.requested_by = {$current_employee_id}";
}
$stats_res = mysqli_query($conn, $stats_query);
if ($stats_res) {
    $stats = mysqli_fetch_assoc($stats_res);
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Candidates - TEK-C Hiring</title>
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
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />
    
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
            padding: 16px;
            height: 100%;
        }
        
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
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

        /* Stats Cards */
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 14px 16px;
            height: 90px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .stat-ic {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 20px;
            flex: 0 0 auto;
        }
        
        .stat-ic.blue { background: var(--blue); }
        .stat-ic.green { background: #10b981; }
        .stat-ic.yellow { background: #f59e0b; }
        .stat-ic.purple { background: #8b5cf6; }
        
        .stat-label {
            color: #4b5563;
            font-weight: 750;
            font-size: 13px;
        }
        
        .stat-value {
            font-size: 30px;
            font-weight: 900;
            line-height: 1;
            margin-top: 2px;
        }

        /* Filter Card */
        .filter-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 18px;
            margin-bottom: 20px;
        }

        /* Buttons */
        .btn-add {
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
            white-space: nowrap;
        }
        .btn-add:hover { background: #2a8bc9; color: #fff; }

        .btn-filter {
            background: #10b981;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(16, 185, 129, 0.18);
            white-space: nowrap;
        }
        .btn-filter:hover { background: #0da271; color: #fff; }

        /* Table Styles */
        .table-responsive { overflow-x: hidden !important; }
        table.dataTable { width: 100% !important; }
        
        .table thead th {
            font-size: 11px;
            color: #6b7280;
            font-weight: 800;
            border-bottom: 1px solid var(--border) !important;
            padding: 10px 10px !important;
            white-space: normal !important;
        }
        
        .table td {
            vertical-align: top;
            border-color: var(--border);
            font-weight: 650;
            color: #374151;
            padding: 10px 10px !important;
            white-space: normal !important;
            word-break: break-word;
        }

        /* Candidate Avatar */
        .candidate-avatar {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 900;
            font-size: 16px;
            flex: 0 0 auto;
        }
        .candidate-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .candidate-name {
            font-weight: 900;
            font-size: 13px;
            color: #1f2937;
            margin-bottom: 2px;
            line-height: 1.2;
        }
        
        .candidate-code {
            font-size: 11px;
            color: #6b7280;
            font-weight: 650;
            line-height: 1.2;
        }

        /* Role Info */
        .role-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        
        .position-text {
            font-size: 12px;
            font-weight: 800;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 6px;
            line-height: 1.2;
        }
        .position-text i { color: var(--blue); font-size: 13px; }
        
        .department-badge {
            background: rgba(45, 156, 219, .1);
            color: var(--blue);
            padding: 3px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 900;
            border: 1px solid rgba(45, 156, 219, .2);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            width: fit-content;
        }

        /* Contact Info */
        .contact-info {
            font-size: 11px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
            line-height: 1.2;
        }
        .contact-info i { font-size: 11px; }

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

        /* Action Buttons */
        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 8px;
            color: var(--muted);
            font-size: 12px;
            margin: 0 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-action:hover { background: var(--bg); color: var(--blue); }
        .btn-action.success:hover { background: #d1fae5; color: #065f46; border-color: #065f46; }
        .btn-action.warning:hover { background: #fef3c7; color: #92400e; border-color: #92400e; }

        /* Experience/CTC Info */
        .exp-info {
            font-size: 12px;
            font-weight: 700;
            color: #2d3748;
        }
        .ctc-info {
            font-size: 10px;
            color: #6b7280;
            font-weight: 600;
        }

        /* Pipeline Stages */
        .pipeline-stages {
            display: flex;
            gap: 4px;
            margin-top: 4px;
        }
        .stage-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d1d5db;
        }
        .stage-dot.completed { background: #10b981; }
        .stage-dot.current { background: #3b82f6; width: 10px; height: 10px; }

        /* Modal Styles */
        .modal-content {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
        }
        .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 16px 20px;
        }
        .modal-title {
            font-weight: 900;
            font-size: 18px;
            color: #1f2937;
        }
        .modal-body { padding: 20px; }
        .modal-footer {
            border-top: 1px solid var(--border);
            padding: 16px 20px;
        }

        /* Form Labels */
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

        /* Alert Styles */
        .alert {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        /* Actions Column Width */
        th.actions-col, td.actions-col { width: 140px !important; white-space: nowrap !important; }
    </style>
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

                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Candidates</h1>
                        <p class="text-muted mb-0">Manage candidate pipeline and interviews</p>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($isHr): ?>
                        <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addCandidateModal">
                            <i class="bi bi-person-plus"></i> Add Candidate
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-people-fill"></i></div>
                            <div>
                                <div class="stat-label">Total Candidates</div>
                                <div class="stat-value"><?php echo (int)($stats['total'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-star-fill"></i></div>
                            <div>
                                <div class="stat-label">New Applications</div>
                                <div class="stat-value"><?php echo (int)($stats['new'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-camera-video-fill"></i></div>
                            <div>
                                <div class="stat-label">Interviews</div>
                                <div class="stat-value"><?php echo (int)($stats['interview'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-check-circle-fill"></i></div>
                            <div>
                                <div class="stat-label">Selected</div>
                                <div class="stat-value"><?php echo (int)($stats['selected'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="filter-card mb-4">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="all">All Status</option>
                                <?php foreach ($status_options as $key => $value): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>>
                                        <?php echo $value; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Hiring Request</label>
                            <select name="hiring_id" class="form-select form-select-sm">
                                <option value="0">All Requests</option>
                                <?php 
                                mysqli_data_seek($hiring_requests, 0);
                                while ($hr = mysqli_fetch_assoc($hiring_requests)): ?>
                                    <option value="<?php echo $hr['id']; ?>" <?php echo $hiring_filter == $hr['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($hr['request_no']); ?> - <?php echo e($hr['position_title']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm" 
                                   placeholder="Name, Email, Phone..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn-filter w-100">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Candidates Table -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Candidate Pipeline</h3>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <div class="table-responsive">
                        <table id="candidatesTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Candidate</th>
                                    <th>Contact</th>
                                    <th>Position</th>
                                    <th>Experience</th>
                                    <th>Status</th>
                                    <th>Source</th>
                                    <th>Applied</th>
                                    <th class="text-end actions-col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($candidates) === 0): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                            No candidates found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    mysqli_data_seek($candidates, 0);
                                    while ($candidate = mysqli_fetch_assoc($candidates)): 
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="candidate-avatar">
                                                        <?php if (!empty($candidate['photo_path'])): ?>
                                                            <img src="../<?php echo e($candidate['photo_path']); ?>" alt="Photo">
                                                        <?php else: ?>
                                                            <?php echo strtoupper(substr($candidate['first_name'] ?? '', 0, 1) . substr($candidate['last_name'] ?? '', 0, 1)); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="candidate-name"><?php echo e(getFullName($candidate['first_name'], $candidate['last_name'])); ?></div>
                                                        <div class="candidate-code"><i class="bi bi-hash"></i> <?php echo e($candidate['candidate_code'] ?? ''); ?></div>
                                                        <?php if (($candidate['interview_count'] ?? 0) > 0): ?>
                                                            <div class="pipeline-stages">
                                                                <small class="text-info">
                                                                    <i class="bi bi-camera-video"></i> Round <?php echo ($candidate['current_round'] ?? 0); ?>/<?php echo ($candidate['interview_count'] ?? 0); ?>
                                                                </small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="contact-info"><i class="bi bi-envelope"></i> <?php echo e($candidate['email'] ?? ''); ?></div>
                                                <div class="contact-info"><i class="bi bi-telephone"></i> <?php echo e($candidate['phone'] ?? ''); ?></div>
                                                <?php if (!empty($candidate['current_location'])): ?>
                                                    <div class="contact-info"><i class="bi bi-geo-alt"></i> <?php echo e($candidate['current_location']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="role-info">
                                                    <div class="position-text">
                                                        <i class="bi bi-briefcase"></i> 
                                                        <?php echo e($candidate['position_title'] ?? 'N/A'); ?>
                                                    </div>
                                                    <?php if (!empty($candidate['department'])): ?>
                                                        <div class="department-badge">
                                                            <i class="bi bi-building"></i> <?php echo e($candidate['department']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($candidate['total_experience'])): ?>
                                                    <div class="exp-info"><?php echo number_format($candidate['total_experience'], 1); ?> years</div>
                                                    <div class="ctc-info">
                                                        ₹<?php echo number_format($candidate['expected_ctc'] ?? 0, 2); ?> L
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">Fresher</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo getStatusBadge($candidate['status'] ?? 'New'); ?></td>
                                            <td>
                                                <span class="fw-bold"><?php echo e($candidate['source'] ?? 'N/A'); ?></span>
                                                <?php if (!empty($candidate['referred_by'])): ?>
                                                    <br><small class="text-muted">Ref: <?php echo e($candidate['referred_by']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td data-order="<?php echo strtotime($candidate['created_at']); ?>">
                                                <?php echo date('d M Y', strtotime($candidate['created_at'])); ?>
                                            </td>
                                            <td class="text-end actions-col">
                                                <button class="btn-action" onclick="viewCandidate(<?php echo $candidate['id']; ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                
                                                <?php if (!in_array($candidate['status'] ?? '', ['Rejected', 'Joined', 'Declined'])): ?>
                                                    <button class="btn-action warning" 
                                                            onclick="openStatusModal(<?php echo $candidate['id']; ?>, '<?php echo e(addslashes(getFullName($candidate['first_name'], $candidate['last_name']))); ?>', '<?php echo $candidate['status'] ?? 'New'; ?>')" 
                                                            title="Update Status">
                                                        <i class="bi bi-arrow-repeat"></i>
                                                    </button>
                                                    
                                                    <?php if (!in_array($candidate['status'] ?? '', ['Interview Scheduled', 'Selected', 'Offered'])): ?>
                                                        <button class="btn-action success" 
                                                                onclick="openInterviewModal(<?php echo $candidate['id']; ?>, <?php echo $candidate['hiring_request_id'] ?? 0; ?>, '<?php echo e(addslashes(getFullName($candidate['first_name'], $candidate['last_name']))); ?>', <?php echo ($candidate['current_round'] ?? 0) + 1; ?>)" 
                                                                title="Schedule Interview">
                                                            <i class="bi bi-camera-video"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if (($candidate['status'] ?? '') === 'Selected' && $isHr): ?>
                                                    <a href="offer-approval.php?candidate_id=<?php echo $candidate['id']; ?>" class="btn-action success" title="Create Offer">
                                                        <i class="bi bi-file-text"></i>
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($candidate['resume_path'])): ?>
                                                    <a href="../<?php echo e($candidate['resume_path']); ?>" target="_blank" class="btn-action" title="Download Resume">
                                                        <i class="bi bi-file-pdf"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Add Candidate Modal (HR only) -->
<?php if ($isHr): ?>
<div class="modal fade" id="addCandidateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_candidate">
                
                <div class="modal-header">
                    <h5 class="modal-title">Add New Candidate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Hiring Request</label>
                            <select name="hiring_request_id" class="form-select" required>
                                <option value="">Select Position</option>
                                <?php 
                                mysqli_data_seek($hiring_requests, 0);
                                while ($hr = mysqli_fetch_assoc($hiring_requests)): ?>
                                    <option value="<?php echo $hr['id']; ?>">
                                        <?php echo e($hr['request_no']); ?> - <?php echo e($hr['position_title']); ?> (<?php echo e($hr['department']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">Phone</label>
                            <input type="text" name="phone" class="form-control" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Alternate Phone</label>
                            <input type="text" name="alternate_phone" class="form-control">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Current Location</label>
                            <input type="text" name="current_location" class="form-control">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Preferred Location</label>
                            <input type="text" name="preferred_location" class="form-control">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Total Exp (years)</label>
                            <input type="number" name="total_experience" class="form-control" step="0.5" min="0">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Relevant Exp</label>
                            <input type="number" name="relevant_experience" class="form-control" step="0.5" min="0">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Current CTC (₹ LPA)</label>
                            <input type="number" name="current_ctc" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Expected CTC (₹ LPA)</label>
                            <input type="number" name="expected_ctc" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Notice Period (days)</label>
                            <input type="number" name="notice_period" class="form-control" min="0">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Current Company</label>
                            <input type="text" name="current_company" class="form-control">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Qualification</label>
                            <input type="text" name="qualification" class="form-control">
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="notice_period_negotiable" id="noticePeriodNegotiable">
                                <label class="form-check-label" for="noticePeriodNegotiable">
                                    Notice Period Negotiable
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Skills</label>
                            <textarea name="skills" class="form-control" rows="2" placeholder="Comma separated skills"></textarea>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Source</label>
                            <select name="source" class="form-select">
                                <option value="LinkedIn">LinkedIn</option>
                                <option value="Naukri">Naukri</option>
                                <option value="Indeed">Indeed</option>
                                <option value="Referral">Referral</option>
                                <option value="Company Website">Company Website</option>
                                <option value="Consultant">Consultant</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Referred By</label>
                            <input type="text" name="referred_by" class="form-control">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Upload Resume</label>
                            <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Upload Photo</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-add">Add Candidate</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Update Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="candidate_id" id="status_candidate_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Update Candidate Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p class="mb-3">Update status for <strong id="status_candidate_name"></strong></p>
                    
                    <div class="mb-3">
                        <label class="form-label required">New Status</label>
                        <select name="status" class="form-select" required>
                            <?php foreach ($status_options as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2" placeholder="Add any remarks..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-add">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Schedule Interview Modal -->
<div class="modal fade" id="interviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="schedule_interview">
                <input type="hidden" name="candidate_id" id="interview_candidate_id">
                <input type="hidden" name="hiring_request_id" id="interview_hiring_id">
                <input type="hidden" name="round_number" id="interview_round">
                
                <div class="modal-header">
                    <h5 class="modal-title">Schedule Interview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p class="mb-3">Schedule interview for <strong id="interview_candidate_name"></strong></p>
                    
                    <div class="mb-3">
                        <label class="form-label required">Interview Round</label>
                        <select name="interview_round" class="form-select" required>
                            <?php foreach ($interview_rounds as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
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
                        <select name="interviewer_id" class="form-select" required>
                            <option value="">Select Interviewer</option>
                            <?php 
                            mysqli_data_seek($employees, 0);
                            while ($emp = mysqli_fetch_assoc($employees)): ?>
                                <option value="<?php echo $emp['id']; ?>">
                                    <?php echo e($emp['full_name']); ?> (<?php echo e($emp['designation']); ?>)
                                </option>
                            <?php endwhile; ?>
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
                    <button type="submit" class="btn-add">Schedule Interview</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#candidatesTable').DataTable({
        responsive: true,
        autoWidth: false,
        scrollX: false,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: [[6, 'desc']],
        language: {
            zeroRecords: "No matching candidates found",
            info: "Showing _START_ to _END_ of _TOTAL_ candidates",
            infoEmpty: "No candidates to show",
            lengthMenu: "Show _MENU_",
            search: "Search:"
        }
    });

    // Initialize Select2 for dropdowns
    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('.modal')
    });

    // Show/hide location/link based on interview mode
    $('select[name="interview_mode"]').change(function() {
        if ($(this).val() === 'Online') {
            $('#onlineLinkField').show();
            $('#locationField').hide();
            $('input[name="location"]').prop('required', false);
            $('input[name="interview_link"]').prop('required', false);
        } else if ($(this).val() === 'In-Person') {
            $('#onlineLinkField').hide();
            $('#locationField').show();
            $('input[name="location"]').prop('required', true);
            $('input[name="interview_link"]').prop('required', false);
        } else {
            $('#onlineLinkField').hide();
            $('#locationField').hide();
            $('input[name="location"]').prop('required', false);
            $('input[name="interview_link"]').prop('required', false);
        }
    });

    // Auto-focus search
    setTimeout(function() {
        $('.dataTables_filter input').focus();
    }, 400);
});

function viewCandidate(id) {
    window.location.href = 'view-candidate.php?id=' + id;
}

function openStatusModal(id, name, currentStatus) {
    $('#status_candidate_id').val(id);
    $('#status_candidate_name').text(name);
    $('select[name="status"]').val(currentStatus);
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

function openInterviewModal(id, hiringId, name, round) {
    $('#interview_candidate_id').val(id);
    $('#interview_hiring_id').val(hiringId);
    $('#interview_candidate_name').text(name);
    $('#interview_round').val(round);
    
    // Set default round selection based on round number
    if (round === 1) $('select[name="interview_round"]').val('Telephonic');
    else if (round === 2) $('select[name="interview_round"]').val('Technical Round 1');
    else if (round === 3) $('select[name="interview_round"]').val('Technical Round 2');
    else if (round === 4) $('select[name="interview_round"]').val('HR Round');
    else if (round === 5) $('select[name="interview_round"]').val('Manager Round');
    else if (round >= 6) $('select[name="interview_round"]').val('Final Round');
    
    // Reset form fields
    $('input[name="interview_date"]').val('');
    $('input[name="interview_time"]').val('');
    $('select[name="interview_duration"]').val('60');
    $('select[name="interview_mode"]').val('Online').trigger('change');
    $('input[name="interview_link"]').val('');
    $('input[name="location"]').val('');
    $('select[name="interviewer_id"]').val('');
    
    new bootstrap.Modal(document.getElementById('interviewModal')).show();
}
</script>

</body>
</html>
<?php
