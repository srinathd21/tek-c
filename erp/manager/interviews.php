<?php
// hr/interviews.php - Interview Management Page (TEK-C Style)
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

// ---------------- HANDLE INTERVIEW ACTIONS ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Update Interview Status/Feedback
    if ($_POST['action'] === 'update_interview') {
        $interview_id = (int)$_POST['interview_id'];
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        $result = mysqli_real_escape_string($conn, $_POST['result'] ?? 'Pending');
        $feedback = mysqli_real_escape_string($conn, $_POST['feedback'] ?? '');
        $rating = !empty($_POST['rating']) ? (int)$_POST['rating'] : null;
        $strengths = mysqli_real_escape_string($conn, $_POST['strengths'] ?? '');
        $weaknesses = mysqli_real_escape_string($conn, $_POST['weaknesses'] ?? '');
        $technical_skills_rating = !empty($_POST['technical_skills_rating']) ? (int)$_POST['technical_skills_rating'] : null;
        $communication_rating = !empty($_POST['communication_rating']) ? (int)$_POST['communication_rating'] : null;
        $attitude_rating = !empty($_POST['attitude_rating']) ? (int)$_POST['attitude_rating'] : null;

        $update_stmt = mysqli_prepare($conn, "
            UPDATE interviews 
            SET status = ?, result = ?, feedback = ?, rating = ?, 
                strengths = ?, weaknesses = ?, 
                technical_skills_rating = ?, communication_rating = ?, attitude_rating = ?
            WHERE id = ?
        ");

        mysqli_stmt_bind_param(
            $update_stmt,
            "sssisssssi",
            $status,
            $result,
            $feedback,
            $rating,
            $strengths,
            $weaknesses,
            $technical_skills_rating,
            $communication_rating,
            $attitude_rating,
            $interview_id
        );

        if (mysqli_stmt_execute($update_stmt)) {

            // If interview is completed with Selected result, update candidate status
            if ($status === 'Completed' && $result === 'Selected') {
                // Get candidate_id from interview
                $cand_stmt = mysqli_prepare($conn, "SELECT candidate_id FROM interviews WHERE id = ?");
                mysqli_stmt_bind_param($cand_stmt, "i", $interview_id);
                mysqli_stmt_execute($cand_stmt);
                $cand_res = mysqli_stmt_get_result($cand_stmt);
                $cand_row = mysqli_fetch_assoc($cand_res);

                if ($cand_row) {
                    // Check if this is the final round or update to Selected status
                    mysqli_query($conn, "UPDATE candidates SET status = 'Selected' WHERE id = {$cand_row['candidate_id']}");
                }
            } elseif ($status === 'Completed') {
                // Get candidate_id from interview
                $cand_stmt = mysqli_prepare($conn, "SELECT candidate_id FROM interviews WHERE id = ?");
                mysqli_stmt_bind_param($cand_stmt, "i", $interview_id);
                mysqli_stmt_execute($cand_stmt);
                $cand_res = mysqli_stmt_get_result($cand_stmt);
                $cand_row = mysqli_fetch_assoc($cand_res);

                if ($cand_row) {
                    mysqli_query($conn, "UPDATE candidates SET status = 'Interviewed' WHERE id = {$cand_row['candidate_id']}");
                }
            }

            logActivity(
                $conn,
                'UPDATE',
                'interview',
                "Updated interview ID: {$interview_id}",
                $interview_id,
                null,
                null,
                json_encode($_POST)
            );

            $message = "Interview updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating interview: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }

    // Reschedule Interview
    elseif ($_POST['action'] === 'reschedule_interview') {
        $interview_id = (int)$_POST['interview_id'];
        $interview_date = mysqli_real_escape_string($conn, $_POST['interview_date']);
        $interview_time = mysqli_real_escape_string($conn, $_POST['interview_time']);
        $reschedule_reason = mysqli_real_escape_string($conn, $_POST['reschedule_reason']);

        $update_stmt = mysqli_prepare($conn, "
            UPDATE interviews 
            SET interview_date = ?, interview_time = ?, status = 'Rescheduled', reschedule_reason = ?
            WHERE id = ?
        ");

        mysqli_stmt_bind_param($update_stmt, "sssi", $interview_date, $interview_time, $reschedule_reason, $interview_id);

        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'interview',
                "Rescheduled interview ID: {$interview_id}",
                $interview_id,
                null,
                null,
                json_encode(['date' => $interview_date, 'time' => $interview_time, 'reason' => $reschedule_reason])
            );

            $message = "Interview rescheduled successfully!";
            $messageType = "success";
        } else {
            $message = "Error rescheduling interview: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }

    // Cancel Interview
    elseif ($_POST['action'] === 'cancel_interview') {
        $interview_id = (int)$_POST['interview_id'];
        $cancellation_reason = mysqli_real_escape_string($conn, $_POST['cancellation_reason']);

        $update_stmt = mysqli_prepare($conn, "
            UPDATE interviews 
            SET status = 'Cancelled', cancellation_reason = ?
            WHERE id = ?
        ");

        mysqli_stmt_bind_param($update_stmt, "si", $cancellation_reason, $interview_id);

        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'interview',
                "Cancelled interview ID: {$interview_id}",
                $interview_id,
                null,
                null,
                json_encode(['reason' => $cancellation_reason])
            );

            $message = "Interview cancelled successfully!";
            $messageType = "success";
        } else {
            $message = "Error cancelling interview: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'all';
$interviewer_filter = isset($_GET['interviewer_id']) ? (int)$_GET['interviewer_id'] : 0;
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query with permissions for all interviews
$query = "
    SELECT i.*, 
           c.first_name, c.last_name, c.photo_path as candidate_photo, c.candidate_code,
           c.phone as candidate_phone, c.email as candidate_email, c.status as candidate_status,
           CONCAT(c.first_name, ' ', c.last_name) as candidate_name,
           h.request_no, h.position_title, h.department,
           e.full_name as interviewer_full_name, e.designation as interviewer_designation,
           e.employee_code as interviewer_code,
           o.id as offer_id, o.status as offer_status,
           ob.id as onboarding_id, ob.status as onboarding_status,
           ob.employee_code
    FROM interviews i
    JOIN candidates c ON i.candidate_id = c.id
    JOIN hiring_requests h ON i.hiring_request_id = h.id
    JOIN employees e ON i.interviewer_id = e.id
    LEFT JOIN offers o ON o.candidate_id = c.id
    LEFT JOIN onboarding ob ON ob.candidate_id = c.id
    WHERE 1=1
";

// Managers see only interviews from their requests
if (!$isHr && !$isAdmin && $isManager) {
    $query .= " AND h.requested_by = {$current_employee_id}";
}

// Filter by status
if ($status_filter !== 'all') {
    $status_filter = mysqli_real_escape_string($conn, $status_filter);
    $query .= " AND i.status = '{$status_filter}'";
}

// Filter by interviewer
if ($interviewer_filter > 0) {
    $query .= " AND i.interviewer_id = {$interviewer_filter}";
}

// Filter by date range
if (!empty($date_from)) {
    $query .= " AND i.interview_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}
if (!empty($date_to)) {
    $query .= " AND i.interview_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}

// Search
if (!empty($search)) {
    $search_term = mysqli_real_escape_string($conn, $search);
    $query .= " AND (c.first_name LIKE '%{$search_term}%' OR c.last_name LIKE '%{$search_term}%' 
                     OR c.email LIKE '%{$search_term}%' OR c.phone LIKE '%{$search_term}%'
                     OR c.candidate_code LIKE '%{$search_term}%'
                     OR i.interview_round LIKE '%{$search_term}%')";
}

$query .= " ORDER BY i.interview_date DESC, i.interview_time DESC";

$interviews = mysqli_query($conn, $query);

// ---------------- QUERY FOR CONVERTED CANDIDATES (Selected/Hired) ----------------
$converted_query = "
    SELECT 
        c.id as candidate_id,
        c.first_name, 
        c.last_name, 
        c.photo_path as candidate_photo, 
        c.candidate_code,
        c.email,
        c.phone,
        c.total_experience,
        c.expected_ctc,
        c.notice_period,
        c.status as candidate_status,
        c.updated_at as conversion_date,
        CONCAT(c.first_name, ' ', c.last_name) as candidate_name,
        h.request_no, 
        h.position_title, 
        h.department,
        h.designation as hiring_designation,
        (
            SELECT i.interview_round 
            FROM interviews i 
            WHERE i.candidate_id = c.id 
            AND i.result = 'Selected'
            ORDER BY i.round_number DESC 
            LIMIT 1
        ) as selected_round,
        (
            SELECT i.interviewer_name 
            FROM interviews i 
            WHERE i.candidate_id = c.id 
            AND i.result = 'Selected'
            ORDER BY i.round_number DESC 
            LIMIT 1
        ) as selected_by,
        (
            SELECT i.interview_date 
            FROM interviews i 
            WHERE i.candidate_id = c.id 
            AND i.result = 'Selected'
            ORDER BY i.round_number DESC 
            LIMIT 1
        ) as selected_date,
        o.id as offer_id,
        o.offer_no,
        o.ctc as offered_ctc,
        o.status as offer_status,
        o.offer_date,
        o.accepted_by_candidate,
        o.response_date,
        ob.id as onboarding_id,
        ob.onboarding_no,
        ob.joining_date,
        ob.employee_code,
        ob.status as onboarding_status
    FROM candidates c
    JOIN hiring_requests h ON c.hiring_request_id = h.id
    LEFT JOIN offers o ON o.candidate_id = c.id
    LEFT JOIN onboarding ob ON ob.candidate_id = c.id
    WHERE c.status IN ('Selected', 'Offered', 'Joined')
";

// Add permission filter for managers
if (!$isHr && !$isAdmin && $isManager) {
    $converted_query .= " AND h.requested_by = {$current_employee_id}";
}

$converted_query .= " ORDER BY 
    CASE c.status
        WHEN 'Joined' THEN 1
        WHEN 'Offered' THEN 2
        WHEN 'Selected' THEN 3
    END,
    c.updated_at DESC";

$converted_candidates = mysqli_query($conn, $converted_query);

// Get interviewers for filter dropdown
$interviewers_query = "
    SELECT DISTINCT e.id, e.full_name, e.designation 
    FROM interviews i
    JOIN employees e ON i.interviewer_id = e.id
    ORDER BY e.full_name
";
$interviewers = mysqli_query($conn, $interviewers_query);

// Get statistics
$today = date('Y-m-d');
$today_query = "
    SELECT COUNT(*) as count 
    FROM interviews 
    WHERE interview_date = '{$today}' 
    AND status = 'Scheduled'
";
$today_result = mysqli_query($conn, $today_query);
$today_count = mysqli_fetch_assoc($today_result)['count'];

$upcoming_query = "
    SELECT COUNT(*) as count 
    FROM interviews 
    WHERE interview_date BETWEEN '{$today}' AND DATE_ADD('{$today}', INTERVAL 7 DAY)
    AND status = 'Scheduled'
";
$upcoming_result = mysqli_query($conn, $upcoming_query);
$upcoming_count = mysqli_fetch_assoc($upcoming_result)['count'];

$completed_query = "
    SELECT COUNT(*) as count 
    FROM interviews 
    WHERE status = 'Completed'
";
$completed_result = mysqli_query($conn, $completed_query);
$completed_count = mysqli_fetch_assoc($completed_result)['count'];

// Get converted candidates statistics
$converted_stats_query = "
    SELECT 
        COUNT(*) as total_converted,
        SUM(CASE WHEN c.status = 'Joined' THEN 1 ELSE 0 END) as joined_count,
        SUM(CASE WHEN c.status = 'Offered' THEN 1 ELSE 0 END) as offered_count,
        SUM(CASE WHEN c.status = 'Selected' THEN 1 ELSE 0 END) as selected_count,
        AVG(c.total_experience) as avg_experience,
        AVG(o.ctc) as avg_ctc
    FROM candidates c
    LEFT JOIN offers o ON o.candidate_id = c.id
    WHERE c.status IN ('Selected', 'Offered', 'Joined')
";

if (!$isHr && !$isAdmin && $isManager) {
    $converted_stats_query .= " AND c.hiring_request_id IN (SELECT id FROM hiring_requests WHERE requested_by = {$current_employee_id})";
}

$converted_stats_result = mysqli_query($conn, $converted_stats_query);
$converted_stats = mysqli_fetch_assoc($converted_stats_result);

// ---------------- HELPER FUNCTIONS ----------------
function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function getStatusBadge($status)
{
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

function getResultBadge($result)
{
    $classes = [
        'Selected' => 'status-selected',
        'Rejected' => 'status-rejected',
        'On Hold' => 'status-hold',
        'Pending' => 'status-screening'
    ];
    $class = $classes[$result] ?? 'status-screening';
    return "<span class='status-badge {$class}'><i class='bi bi-circle-fill' style='font-size:8px;'></i> {$result}</span>";
}

function getCandidateStatusBadge($status)
{
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

function getRatingStars($rating)
{
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

function formatCurrency($amount)
{
    if (!$amount)
        return '—';
    return '₹ ' . number_format($amount, 2) . ' LPA';
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
    <title>Interviews - TEK-C Hiring</title>
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
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css"
        rel="stylesheet" />

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

        .stat-ic.blue {
            background: var(--blue);
        }

        .stat-ic.green {
            background: #10b981;
        }

        .stat-ic.yellow {
            background: #f59e0b;
        }

        .stat-ic.purple {
            background: #8b5cf6;
        }

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

        .btn-add:hover {
            background: #2a8bc9;
            color: #fff;
        }

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

        .btn-filter:hover {
            background: #0da271;
            color: #fff;
        }

        .btn-reset {
            background: #6b7280;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn-reset:hover {
            background: #4b5563;
            color: #fff;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: hidden !important;
        }

        table.dataTable {
            width: 100% !important;
        }

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

        /* Interview Time/Date */
        .interview-time {
            font-weight: 900;
            font-size: 13px;
            color: #1f2937;
        }

        .interview-date {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .interview-duration {
            font-size: 10px;
            color: #6b7280;
            font-weight: 600;
        }

        /* Position Info */
        .position-info {
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

        .position-text i {
            color: var(--blue);
            font-size: 13px;
        }

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

        /* Interviewer Info */
        .interviewer-name {
            font-weight: 800;
            font-size: 12px;
            color: #1f2937;
        }

        .interviewer-designation {
            font-size: 10px;
            color: #6b7280;
            font-weight: 600;
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

        .status-new {
            background: rgba(45, 156, 219, .12);
            color: var(--blue);
            border: 1px solid rgba(45, 156, 219, .22);
        }

        .status-screening {
            background: rgba(107, 114, 128, .12);
            color: #6b7280;
            border: 1px solid rgba(107, 114, 128, .22);
        }

        .status-shortlisted {
            background: rgba(139, 92, 246, .12);
            color: #8b5cf6;
            border: 1px solid rgba(139, 92, 246, .22);
        }

        .status-interview {
            background: rgba(245, 158, 11, .12);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, .22);
        }

        .status-interviewed {
            background: rgba(16, 185, 129, .12);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, .22);
        }

        .status-selected {
            background: rgba(16, 185, 129, .12);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, .22);
        }

        .status-rejected {
            background: rgba(239, 68, 68, .12);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, .22);
        }

        .status-hold {
            background: rgba(245, 158, 11, .12);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, .22);
        }

        .status-offered {
            background: rgba(16, 185, 129, .12);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, .22);
        }

        .status-joined {
            background: rgba(16, 185, 129, .12);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, .22);
        }

        .status-declined {
            background: rgba(239, 68, 68, .12);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, .22);
        }

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

        .btn-action.info:hover {
            background: #dbeafe;
            color: #1e40af;
            border-color: #1e40af;
        }

        /* Rating Stars */
        .rating-stars {
            white-space: nowrap;
        }

        .rating-stars i {
            font-size: 11px;
            margin-right: 1px;
        }

        /* Tabs */
        .nav-tabs {
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }

        .nav-tabs .nav-link {
            font-weight: 800;
            font-size: 13px;
            color: #6b7280;
            border: none;
            padding: 10px 16px;
        }

        .nav-tabs .nav-link:hover {
            color: var(--blue);
            border: none;
        }

        .nav-tabs .nav-link.active {
            color: var(--blue);
            border-bottom: 2px solid var(--blue);
            background: none;
        }

        .nav-tabs .nav-link i {
            margin-right: 6px;
        }

        .nav-tabs .nav-link .badge {
            margin-left: 6px;
            font-weight: 800;
            background: #e5e7eb;
            color: #374151;
        }

        /* Conversion Badge */
        .conversion-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #10b981;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 900;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* Timeline Progress */
        .timeline-progress {
            position: relative;
            padding-left: 15px;
            margin-top: 6px;
        }

        .timeline-progress-item {
            position: relative;
            padding-bottom: 4px;
            border-left: 2px solid #e5e7eb;
            padding-left: 12px;
        }

        .timeline-progress-item:last-child {
            border-left-color: transparent;
            padding-bottom: 0;
        }

        .timeline-progress-dot {
            position: absolute;
            left: -6px;
            top: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid;
        }

        .timeline-progress-dot.selected {
            border-color: #10b981;
        }

        .timeline-progress-dot.offered {
            border-color: #f59e0b;
        }

        .timeline-progress-dot.joined {
            border-color: #10b981;
        }

        .timeline-progress-item small {
            font-size: 9px;
            color: #6b7280;
            font-weight: 600;
        }

        /* Converted Stats */
        .converted-stats {
            background: linear-gradient(135deg, #2563eb, #8b5cf6);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 20px;
            color: white;
        }

        .converted-stat-item {
            text-align: center;
        }

        .converted-stat-value {
            font-size: 24px;
            font-weight: 900;
            line-height: 1;
        }

        .converted-stat-label {
            font-size: 11px;
            opacity: 0.9;
            font-weight: 700;
            margin-top: 4px;
        }

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

        .modal-body {
            padding: 20px;
        }

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
        th.actions-col,
        td.actions-col {
            width: 120px !important;
            white-space: nowrap !important;
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
                            <h1 class="h3 fw-bold text-dark mb-1">Interview Management</h1>
                            <p class="text-muted mb-0">Manage interview schedules and feedback</p>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn-add" onclick="exportToExcel()">
                                <i class="bi bi-file-excel"></i> Export
                            </button>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic yellow"><i class="bi bi-calendar-check"></i></div>
                                <div>
                                    <div class="stat-label">Today's Interviews</div>
                                    <div class="stat-value"><?php echo (int) $today_count; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-calendar-week"></i></div>
                                <div>
                                    <div class="stat-label">Upcoming (7 days)</div>
                                    <div class="stat-value"><?php echo (int) $upcoming_count; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                                <div>
                                    <div class="stat-label">Completed</div>
                                    <div class="stat-value"><?php echo (int) $completed_count; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic purple"><i class="bi bi-trophy"></i></div>
                                <div>
                                    <div class="stat-label">Converted</div>
                                    <div class="stat-value"><?php echo (int) ($converted_stats['total_converted'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Converted Candidates Stats (if any) -->
                    <?php if (($converted_stats['total_converted'] ?? 0) > 0): ?>
                        <div class="converted-stats mb-4">
                            <div class="row">
                                <div class="col-6 col-md-3 converted-stat-item">
                                    <div class="converted-stat-value"><?php echo (int) ($converted_stats['joined_count'] ?? 0); ?></div>
                                    <div class="converted-stat-label">Joined</div>
                                </div>
                                <div class="col-6 col-md-3 converted-stat-item">
                                    <div class="converted-stat-value"><?php echo (int) ($converted_stats['offered_count'] ?? 0); ?></div>
                                    <div class="converted-stat-label">Offered</div>
                                </div>
                                <div class="col-6 col-md-3 converted-stat-item">
                                    <div class="converted-stat-value"><?php echo (int) ($converted_stats['selected_count'] ?? 0); ?></div>
                                    <div class="converted-stat-label">Selected</div>
                                </div>
                                <div class="col-6 col-md-3 converted-stat-item">
                                    <div class="converted-stat-value">
                                        <?php echo isset($converted_stats['avg_experience']) && $converted_stats['avg_experience'] ? number_format($converted_stats['avg_experience'], 1) . ' yrs' : '—'; ?>
                                    </div>
                                    <div class="converted-stat-label">Avg Experience</div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tabs -->
                    <ul class="nav nav-tabs" id="interviewTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                                <i class="bi bi-list-ul"></i> All Interviews
                                <span class="badge"><?php echo mysqli_num_rows($interviews); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="converted-tab" data-bs-toggle="tab" data-bs-target="#converted" type="button" role="tab">
                                <i class="bi bi-person-check"></i> Converted
                                <span class="badge"><?php echo mysqli_num_rows($converted_candidates); ?></span>
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="interviewTabContent">

                        <!-- ALL INTERVIEWS TAB -->
                        <div class="tab-pane fade show active" id="all" role="tabpanel">

                            <!-- Filter Card -->
                            <div class="filter-card">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="all">All Status</option>
                                            <option value="Scheduled" <?php echo $status_filter === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                            <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="Rescheduled" <?php echo $status_filter === 'Rescheduled' ? 'selected' : ''; ?>>Rescheduled</option>
                                            <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            <option value="No Show" <?php echo $status_filter === 'No Show' ? 'selected' : ''; ?>>No Show</option>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Interviewer</label>
                                        <select name="interviewer_id" class="form-select form-select-sm">
                                            <option value="0">All Interviewers</option>
                                            <?php
                                            mysqli_data_seek($interviewers, 0);
                                            while ($int = mysqli_fetch_assoc($interviewers)):
                                            ?>
                                                <option value="<?php echo $int['id']; ?>" <?php echo $interviewer_filter == $int['id'] ? 'selected' : ''; ?>>
                                                    <?php echo e($int['full_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">From Date</label>
                                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo e($date_from); ?>">
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">To Date</label>
                                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo e($date_to); ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="search" class="form-control form-control-sm"
                                            placeholder="Candidate, Round..." value="<?php echo e($search); ?>">
                                    </div>

                                    <div class="col-12 d-flex justify-content-end gap-2">
                                        <button type="submit" class="btn-filter">
                                            <i class="bi bi-funnel"></i> Apply Filters
                                        </button>
                                        <a href="interviews.php" class="btn-reset">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                                        </a>
                                    </div>
                                </form>
                            </div>

                            <!-- Interviews Table -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h3 class="panel-title">All Interviews</h3>
                                    <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                                </div>

                                <div class="table-responsive">
                                    <table id="interviewsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Candidate</th>
                                                <th>Position</th>
                                                <th>Interviewer</th>
                                                <th>Round</th>
                                                <th>Status</th>
                                                <th>Result</th>
                                                <th class="text-end actions-col">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (mysqli_num_rows($interviews) === 0): ?>
                                               
                                            <?php else: ?>
                                                <?php
                                                mysqli_data_seek($interviews, 0);
                                                while ($interview = mysqli_fetch_assoc($interviews)):
                                                    $isConverted = in_array($interview['candidate_status'], ['Selected', 'Offered', 'Joined']);
                                                    $fullName = getFullName($interview['first_name'], $interview['last_name']);
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="interview-time">
                                                                <?php echo date('h:i A', strtotime($interview['interview_time'])); ?>
                                                            </div>
                                                            <div class="interview-date">
                                                                <i class="bi bi-calendar"></i>
                                                                <?php echo date('d M Y', strtotime($interview['interview_date'])); ?>
                                                            </div>
                                                            <div class="interview-duration">
                                                                <i class="bi bi-hourglass"></i>
                                                                <?php echo $interview['interview_duration']; ?> min
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2 position-relative">
                                                                <?php if ($isConverted): ?>
                                                                    <span class="conversion-badge" title="Converted Candidate">
                                                                        <i class="bi bi-check"></i>
                                                                    </span>
                                                                <?php endif; ?>
                                                                <div class="candidate-avatar">
                                                                    <?php if (!empty($interview['candidate_photo'])): ?>
                                                                        <img src="../<?php echo e($interview['candidate_photo']); ?>" alt="Photo">
                                                                    <?php else: ?>
                                                                        <?php echo initials($fullName); ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div>
                                                                    <div class="candidate-name">
                                                                        <a href="view-candidate.php?id=<?php echo $interview['candidate_id']; ?>" class="text-decoration-none text-dark">
                                                                            <?php echo e($fullName); ?>
                                                                        </a>
                                                                    </div>
                                                                    <div class="candidate-code">
                                                                        <i class="bi bi-hash"></i> <?php echo e($interview['candidate_code'] ?? ''); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="position-info">
                                                                <div class="position-text">
                                                                    <i class="bi bi-briefcase"></i>
                                                                    <?php echo e($interview['position_title'] ?? 'N/A'); ?>
                                                                </div>
                                                                <?php if (!empty($interview['department'])): ?>
                                                                    <div class="department-badge">
                                                                        <i class="bi bi-building"></i> <?php echo e($interview['department']); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div>
                                                                <div class="interviewer-name"><?php echo e($interview['interviewer_full_name']); ?></div>
                                                                <div class="interviewer-designation"><?php echo e($interview['interviewer_designation']); ?></div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="status-badge status-interview"><?php echo e($interview['interview_round']); ?></span>
                                                            <div class="interviewer-designation mt-1">Round <?php echo $interview['round_number']; ?></div>
                                                        </td>
                                                        <td><?php echo getStatusBadge($interview['status']); ?></td>
                                                        <td>
                                                            <?php if ($interview['status'] === 'Completed'): ?>
                                                                <?php echo getResultBadge($interview['result']); ?>
                                                                <?php if (!empty($interview['rating'])): ?>
                                                                    <div class="rating-stars mt-1">
                                                                        <?php echo getRatingStars($interview['rating']); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end actions-col">
                                                            <button class="btn-action" onclick="viewInterview(<?php echo $interview['id']; ?>)" title="View Details">
                                                                <i class="bi bi-eye"></i>
                                                            </button>

                                                            <?php if ($interview['status'] === 'Scheduled'): ?>
                                                                <button class="btn-action warning"
                                                                    onclick="openRescheduleModal(<?php echo $interview['id']; ?>, '<?php echo e($fullName); ?>', '<?php echo $interview['interview_date']; ?>', '<?php echo $interview['interview_time']; ?>')"
                                                                    title="Reschedule">
                                                                    <i class="bi bi-arrow-repeat"></i>
                                                                </button>
                                                                <button class="btn-action success"
                                                                    onclick='openUpdateModal(<?php echo json_encode($interview); ?>)'
                                                                    title="Update Feedback">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button class="btn-action danger"
                                                                    onclick="openCancelModal(<?php echo $interview['id']; ?>, '<?php echo e($fullName); ?>')"
                                                                    title="Cancel">
                                                                    <i class="bi bi-x-lg"></i>
                                                                </button>
                                                            <?php elseif ($interview['status'] === 'Completed'): ?>
                                                                <button class="btn-action success"
                                                                    onclick='openUpdateModal(<?php echo json_encode($interview); ?>)'
                                                                    title="Edit Feedback">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                            <?php endif; ?>

                                                            <?php if ($isConverted): ?>
                                                                <a href="view-candidate.php?id=<?php echo $interview['candidate_id']; ?>" class="btn-action info" title="Conversion Details">
                                                                    <i class="bi bi-person-check"></i>
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

                        <!-- CONVERTED CANDIDATES TAB -->
                        <div class="tab-pane fade" id="converted" role="tabpanel">

                            <!-- Converted Candidates Table -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h3 class="panel-title">Converted Candidates</h3>
                                    <span class="status-badge status-selected"><?php echo mysqli_num_rows($converted_candidates); ?> converted</span>
                                </div>

                                <div class="table-responsive">
                                    <table id="convertedTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                        <thead>
                                            <tr>
                                                <th>Candidate</th>
                                                <th>Position</th>
                                                <th>Selection</th>
                                                <th>Offer</th>
                                                <th>Onboarding</th>
                                                <th>Status</th>
                                                <th class="text-end actions-col">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (mysqli_num_rows($converted_candidates) === 0): ?>
                                               
                                            <?php else: ?>
                                                <?php while ($candidate = mysqli_fetch_assoc($converted_candidates)): 
                                                    $fullName = getFullName($candidate['first_name'], $candidate['last_name']);
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <div class="candidate-avatar">
                                                                    <?php if (!empty($candidate['candidate_photo'])): ?>
                                                                        <img src="../<?php echo e($candidate['candidate_photo']); ?>" alt="Photo">
                                                                    <?php else: ?>
                                                                        <?php echo initials($fullName); ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div>
                                                                    <div class="candidate-name">
                                                                        <a href="view-candidate.php?id=<?php echo $candidate['candidate_id']; ?>" class="text-decoration-none text-dark">
                                                                            <?php echo e($fullName); ?>
                                                                        </a>
                                                                    </div>
                                                                    <div class="candidate-code">
                                                                        <i class="bi bi-hash"></i> <?php echo e($candidate['candidate_code'] ?? ''); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="position-info">
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
                                                            <?php if (!empty($candidate['selected_round'])): ?>
                                                                <span class="status-badge status-selected"><?php echo e($candidate['selected_round']); ?></span>
                                                                <div class="interviewer-designation mt-1">
                                                                    <i class="bi bi-calendar"></i> <?php echo date('d M Y', strtotime($candidate['selected_date'])); ?>
                                                                </div>
                                                                <div class="interviewer-designation">
                                                                    <i class="bi bi-person"></i> <?php echo e($candidate['selected_by']); ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($candidate['offer_id'])): ?>
                                                                <span class="status-badge status-offered"><?php echo e($candidate['offer_no']); ?></span>
                                                                <div class="interviewer-designation mt-1">
                                                                    <?php echo formatCurrency($candidate['offered_ctc']); ?>
                                                                </div>
                                                                <div class="interviewer-designation">
                                                                    <i class="bi bi-calendar"></i> <?php echo date('d M Y', strtotime($candidate['offer_date'])); ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($candidate['onboarding_id'])): ?>
                                                                <span class="status-badge status-joined"><?php echo e($candidate['onboarding_no']); ?></span>
                                                                <div class="interviewer-designation mt-1">
                                                                    <i class="bi bi-calendar"></i> Joining: <?php echo date('d M Y', strtotime($candidate['joining_date'])); ?>
                                                                </div>
                                                                <?php if (!empty($candidate['employee_code'])): ?>
                                                                    <div class="interviewer-designation">
                                                                        <i class="bi bi-person-badge"></i> <?php echo e($candidate['employee_code']); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo getCandidateStatusBadge($candidate['candidate_status']); ?>

                                                            <!-- Conversion Timeline -->
                                                            <div class="timeline-progress">
                                                                <?php if ($candidate['candidate_status'] == 'Selected'): ?>
                                                                    <div class="timeline-progress-item">
                                                                        <div class="timeline-progress-dot selected"></div>
                                                                        <small>Selected</small>
                                                                    </div>
                                                                <?php elseif ($candidate['candidate_status'] == 'Offered'): ?>
                                                                    <div class="timeline-progress-item">
                                                                        <div class="timeline-progress-dot selected"></div>
                                                                        <small>Selected</small>
                                                                    </div>
                                                                    <div class="timeline-progress-item">
                                                                        <div class="timeline-progress-dot offered"></div>
                                                                        <small>Offered</small>
                                                                    </div>
                                                                <?php elseif ($candidate['candidate_status'] == 'Joined'): ?>
                                                                    <div class="timeline-progress-item">
                                                                        <div class="timeline-progress-dot selected"></div>
                                                                        <small>Selected</small>
                                                                    </div>
                                                                    <div class="timeline-progress-item">
                                                                        <div class="timeline-progress-dot offered"></div>
                                                                        <small>Offered</small>
                                                                    </div>
                                                                    <div class="timeline-progress-item">
                                                                        <div class="timeline-progress-dot joined"></div>
                                                                        <small>Joined</small>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td class="text-end actions-col">
                                                            <a href="view-candidate.php?id=<?php echo $candidate['candidate_id']; ?>" class="btn-action" title="View Candidate">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                            <?php if (!empty($candidate['offer_id'])): ?>
                                                                <a href="view-offer.php?id=<?php echo $candidate['offer_id']; ?>" class="btn-action info" title="View Offer">
                                                                    <i class="bi bi-file-text"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if (!empty($candidate['onboarding_id'])): ?>
                                                                <a href="view-onboarding.php?id=<?php echo $candidate['onboarding_id']; ?>" class="btn-action success" title="View Onboarding">
                                                                    <i class="bi bi-person-check"></i>
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
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Update Interview Modal -->
    <div class="modal fade" id="updateInterviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_interview">
                    <input type="hidden" name="interview_id" id="update_interview_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Update Interview Feedback</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select" id="update_status" required>
                                    <option value="Scheduled">Scheduled</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Cancelled">Cancelled</option>
                                    <option value="No Show">No Show</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="resultField">
                                <label class="form-label">Result</label>
                                <select name="result" class="form-select" id="update_result">
                                    <option value="Pending">Pending</option>
                                    <option value="Selected">Selected</option>
                                    <option value="Rejected">Rejected</option>
                                    <option value="On Hold">On Hold</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Overall Rating</label>
                                <select name="rating" class="form-select" id="update_rating">
                                    <option value="">Not Rated</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> - <?php echo $i == 1 ? 'Poor' : ($i == 2 ? 'Fair' : ($i == 3 ? 'Good' : ($i == 4 ? 'Very Good' : 'Excellent'))); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Technical Skills</label>
                                <select name="technical_skills_rating" class="form-select" id="update_technical">
                                    <option value="">Not Rated</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> / 5</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Communication</label>
                                <select name="communication_rating" class="form-select" id="update_communication">
                                    <option value="">Not Rated</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> / 5</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Strengths</label>
                            <textarea name="strengths" class="form-control" rows="2" id="update_strengths"
                                placeholder="What went well?"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Weaknesses</label>
                            <textarea name="weaknesses" class="form-control" rows="2" id="update_weaknesses"
                                placeholder="Areas to improve..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Detailed Feedback</label>
                            <textarea name="feedback" class="form-control" rows="4" id="update_feedback"
                                placeholder="Provide detailed interview feedback..."></textarea>
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
                <form method="POST">
                    <input type="hidden" name="action" value="reschedule_interview">
                    <input type="hidden" name="interview_id" id="reschedule_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Reschedule Interview</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-3">Reschedule interview for <strong id="reschedule_candidate"></strong></p>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label required">New Date</label>
                                <input type="date" name="interview_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">New Time</label>
                                <input type="time" name="interview_time" class="form-control" required>
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
                <form method="POST">
                    <input type="hidden" name="action" value="cancel_interview">
                    <input type="hidden" name="interview_id" id="cancel_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Cancel Interview</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-3">Are you sure you want to cancel interview for <strong id="cancel_candidate"></strong>?</p>

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
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
        $(document).ready(function () {
            // Initialize DataTables
            $('#interviewsTable').DataTable({
                responsive: true,
                autoWidth: false,
                scrollX: false,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                order: [[0, 'desc']],
                language: {
                    zeroRecords: "No matching interviews found",
                    info: "Showing _START_ to _END_ of _TOTAL_ interviews",
                    infoEmpty: "No interviews to show",
                    lengthMenu: "Show _MENU_",
                    search: "Search:"
                },
                columnDefs: [
                    { orderable: false, targets: [7] }
                ]
            });

            $('#convertedTable').DataTable({
                responsive: true,
                autoWidth: false,
                scrollX: false,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                order: [[5, 'desc']],
                language: {
                    zeroRecords: "No matching candidates found",
                    info: "Showing _START_ to _END_ of _TOTAL_ converted candidates",
                    infoEmpty: "No converted candidates to show",
                    lengthMenu: "Show _MENU_",
                    search: "Search:"
                },
                columnDefs: [
                    { orderable: false, targets: [6] }
                ]
            });

            // Toggle result field based on status
            $('#update_status').change(function () {
                if ($(this).val() === 'Completed') {
                    $('#resultField').show();
                } else {
                    $('#resultField').hide();
                    $('#update_result').val('Pending');
                }
            });

            // Auto-focus search
            setTimeout(function () {
                $('.dataTables_filter input').focus();
            }, 400);
        });

        function viewInterview(id) {
            window.location.href = 'view-interview.php?id=' + id;
        }

        function openUpdateModal(interview) {
            $('#update_interview_id').val(interview.id);
            $('#update_status').val(interview.status);
            $('#update_result').val(interview.result || 'Pending');
            $('#update_rating').val(interview.rating || '');
            $('#update_technical').val(interview.technical_skills_rating || '');
            $('#update_communication').val(interview.communication_rating || '');
            $('#update_strengths').val(interview.strengths || '');
            $('#update_weaknesses').val(interview.weaknesses || '');
            $('#update_feedback').val(interview.feedback || '');

            // Show/hide result based on status
            if (interview.status === 'Completed') {
                $('#resultField').show();
            } else {
                $('#resultField').hide();
            }

            new bootstrap.Modal(document.getElementById('updateInterviewModal')).show();
        }

        function openRescheduleModal(id, candidate, date, time) {
            $('#reschedule_id').val(id);
            $('#reschedule_candidate').text(candidate);
            $('input[name="interview_date"]').val(date);
            $('input[name="interview_time"]').val(time);
            new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
        }

        function openCancelModal(id, candidate) {
            $('#cancel_id').val(id);
            $('#cancel_candidate').text(candidate);
            new bootstrap.Modal(document.getElementById('cancelModal')).show();
        }

        function exportToExcel() {
            window.location.href = 'export-interviews.php?' + window.location.search.substring(1);
        }
    </script>
</body>

</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>