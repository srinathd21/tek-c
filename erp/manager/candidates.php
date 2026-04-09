<?php
// manager/candidates.php - Candidate Management Page (TEK-C Style)
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

$current_employee_id = (int)$_SESSION['employee_id'];

// Get current employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$current_employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die("Employee not found.");
}

// Check permissions
$designation = strtolower(trim((string)($current_employee['designation'] ?? '')));
$department  = strtolower(trim((string)($current_employee['department'] ?? '')));

$isHr      = ($designation === 'hr' || $department === 'hr');
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'director', 'administrator'], true);
$isAdmin   = in_array($designation, ['administrator', 'admin', 'director'], true);

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- HELPERS ----------------
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function getFullName($first, $last) {
    return trim((string)$first . ' ' . (string)$last);
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
    return "<span class='status-badge {$class}'><i class='bi bi-circle-fill' style='font-size:8px;'></i> " . e($status) . "</span>";
}

function fileExistsWeb($path) {
    if (empty($path)) return false;
    $full = '../' . ltrim($path, '/');
    return is_file($full);
}

// ---------------- STATUS / INTERVIEW OPTIONS ----------------
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

$interview_rounds = [
    'Telephonic' => 'Telephonic',
    'Technical Round 1' => 'Technical Round 1',
    'Technical Round 2' => 'Technical Round 2',
    'HR Round' => 'HR Round',
    'Manager Round' => 'Manager Round',
    'Final Round' => 'Final Round'
];

$message = '';
$messageType = '';

// ---------------- HANDLE CANDIDATE ACTIONS ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Update Candidate Status
    if ($_POST['action'] === 'update_status') {
        $candidate_id = (int)($_POST['candidate_id'] ?? 0);
        $status       = trim((string)($_POST['status'] ?? ''));
        $remarks      = trim((string)($_POST['remarks'] ?? ''));

        if ($candidate_id <= 0 || $status === '') {
            $message = "Invalid candidate or status.";
            $messageType = "danger";
        } else {
            // Managers can update only their own request candidates
            $allowed = false;

            if ($isHr || $isAdmin) {
                $allowed = true;
            } else {
                $chk = mysqli_prepare($conn, "
                    SELECT c.id
                    FROM candidates c
                    INNER JOIN hiring_requests h ON c.hiring_request_id = h.id
                    WHERE c.id = ? AND h.requested_by = ?
                    LIMIT 1
                ");
                mysqli_stmt_bind_param($chk, "ii", $candidate_id, $current_employee_id);
                mysqli_stmt_execute($chk);
                $chk_res = mysqli_stmt_get_result($chk);
                $allowed = ($chk_res && mysqli_num_rows($chk_res) > 0);
                mysqli_stmt_close($chk);
            }

            if (!$allowed) {
                $message = "You don't have permission to update this candidate.";
                $messageType = "danger";
            } else {
                $update_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE candidates 
                     SET status = ?, remarks = CONCAT(COALESCE(remarks, ''), '\n[', NOW(), '] ', ?) 
                     WHERE id = ?"
                );
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

                mysqli_stmt_close($update_stmt);
            }
        }
    }

    // Delete Candidate
    elseif ($_POST['action'] === 'delete_candidate') {
        $candidate_id = (int)($_POST['candidate_id'] ?? 0);

        if ($candidate_id <= 0) {
            $message = "Invalid candidate selected.";
            $messageType = "danger";
        } else {
            $allowed = false;

            if ($isHr || $isAdmin) {
                $allowed = true;
            } else {
                $chk = mysqli_prepare($conn, "
                    SELECT c.id
                    FROM candidates c
                    INNER JOIN hiring_requests h ON c.hiring_request_id = h.id
                    WHERE c.id = ? AND h.requested_by = ?
                    LIMIT 1
                ");
                mysqli_stmt_bind_param($chk, "ii", $candidate_id, $current_employee_id);
                mysqli_stmt_execute($chk);
                $chk_res = mysqli_stmt_get_result($chk);
                $allowed = ($chk_res && mysqli_num_rows($chk_res) > 0);
                mysqli_stmt_close($chk);
            }

            if (!$allowed) {
                $message = "You don't have permission to delete this candidate.";
                $messageType = "danger";
            } else {
                mysqli_begin_transaction($conn);

                try {
                    $cand_stmt = mysqli_prepare($conn, "
                        SELECT id, candidate_code, first_name, last_name, resume_path, photo_path
                        FROM candidates
                        WHERE id = ?
                        LIMIT 1
                    ");
                    mysqli_stmt_bind_param($cand_stmt, "i", $candidate_id);
                    mysqli_stmt_execute($cand_stmt);
                    $cand_res = mysqli_stmt_get_result($cand_stmt);
                    $candidate_row = mysqli_fetch_assoc($cand_res);
                    mysqli_stmt_close($cand_stmt);

                    if (!$candidate_row) {
                        throw new Exception("Candidate not found.");
                    }

                    $del_interviews = mysqli_prepare($conn, "DELETE FROM interviews WHERE candidate_id = ?");
                    mysqli_stmt_bind_param($del_interviews, "i", $candidate_id);
                    mysqli_stmt_execute($del_interviews);
                    mysqli_stmt_close($del_interviews);

                    $check_offer_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'offers'");
                    if ($check_offer_tbl && mysqli_num_rows($check_offer_tbl) > 0) {
                        $del_offer = mysqli_prepare($conn, "DELETE FROM offers WHERE candidate_id = ?");
                        mysqli_stmt_bind_param($del_offer, "i", $candidate_id);
                        mysqli_stmt_execute($del_offer);
                        mysqli_stmt_close($del_offer);
                    }

                    $check_onboard_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'onboarding'");
                    if ($check_onboard_tbl && mysqli_num_rows($check_onboard_tbl) > 0) {
                        $del_onboard = mysqli_prepare($conn, "DELETE FROM onboarding WHERE candidate_id = ?");
                        mysqli_stmt_bind_param($del_onboard, "i", $candidate_id);
                        mysqli_stmt_execute($del_onboard);
                        mysqli_stmt_close($del_onboard);
                    }

                    $del_candidate = mysqli_prepare($conn, "DELETE FROM candidates WHERE id = ?");
                    mysqli_stmt_bind_param($del_candidate, "i", $candidate_id);
                    mysqli_stmt_execute($del_candidate);

                    if (mysqli_stmt_affected_rows($del_candidate) <= 0) {
                        mysqli_stmt_close($del_candidate);
                        throw new Exception("Unable to delete candidate.");
                    }
                    mysqli_stmt_close($del_candidate);

                    if (!empty($candidate_row['resume_path'])) {
                        $resume_file = '../' . ltrim($candidate_row['resume_path'], '/');
                        if (is_file($resume_file)) {
                            @unlink($resume_file);
                        }
                    }

                    if (!empty($candidate_row['photo_path'])) {
                        $photo_file = '../' . ltrim($candidate_row['photo_path'], '/');
                        if (is_file($photo_file)) {
                            @unlink($photo_file);
                        }
                    }

                    logActivity(
                        $conn,
                        'DELETE',
                        'candidate',
                        "Deleted candidate: " . trim(($candidate_row['first_name'] ?? '') . ' ' . ($candidate_row['last_name'] ?? '')) . " (" . ($candidate_row['candidate_code'] ?? '') . ")",
                        $candidate_id,
                        $candidate_row['candidate_code'] ?? null,
                        json_encode($candidate_row),
                        null
                    );

                    mysqli_commit($conn);
                    $message = "Candidate deleted successfully!";
                    $messageType = "success";
                } catch (Throwable $e) {
                    mysqli_rollback($conn);
                    $message = "Error deleting candidate: " . $e->getMessage();
                    $messageType = "danger";
                }
            }
        }
    }

    // Schedule Interview
    elseif ($_POST['action'] === 'schedule_interview') {
        $candidate_id        = (int)($_POST['candidate_id'] ?? 0);
        $hiring_request_id   = (int)($_POST['hiring_request_id'] ?? 0);
        $interview_round     = trim((string)($_POST['interview_round'] ?? ''));
        $round_number        = (int)($_POST['round_number'] ?? 1);
        $interview_date      = trim((string)($_POST['interview_date'] ?? ''));
        $interview_time      = trim((string)($_POST['interview_time'] ?? ''));
        $interview_duration  = (int)($_POST['interview_duration'] ?? 30);
        $interview_mode      = trim((string)($_POST['interview_mode'] ?? ''));
        $interview_link      = trim((string)($_POST['interview_link'] ?? ''));
        $location            = trim((string)($_POST['location'] ?? ''));
        $interviewer_id      = (int)($_POST['interviewer_id'] ?? 0);

        if ($candidate_id <= 0 || $hiring_request_id <= 0 || $interviewer_id <= 0 || $interview_round === '' || $interview_date === '' || $interview_time === '' || $interview_mode === '') {
            $message = "Please fill all required interview fields.";
            $messageType = "danger";
        } else {
            $allowed = false;

            if ($isHr || $isAdmin) {
                $allowed = true;
            } else {
                $chk = mysqli_prepare($conn, "
                    SELECT c.id
                    FROM candidates c
                    INNER JOIN hiring_requests h ON c.hiring_request_id = h.id
                    WHERE c.id = ? AND h.requested_by = ?
                    LIMIT 1
                ");
                mysqli_stmt_bind_param($chk, "ii", $candidate_id, $current_employee_id);
                mysqli_stmt_execute($chk);
                $chk_res = mysqli_stmt_get_result($chk);
                $allowed = ($chk_res && mysqli_num_rows($chk_res) > 0);
                mysqli_stmt_close($chk);
            }

            if (!$allowed) {
                $message = "You don't have permission to schedule interview for this candidate.";
                $messageType = "danger";
            } else {
                $int_stmt = mysqli_prepare($conn, "SELECT full_name FROM employees WHERE id = ? LIMIT 1");
                mysqli_stmt_bind_param($int_stmt, "i", $interviewer_id);
                mysqli_stmt_execute($int_stmt);
                $int_res = mysqli_stmt_get_result($int_stmt);
                $int_row = mysqli_fetch_assoc($int_res);
                mysqli_stmt_close($int_stmt);

                $interviewer_name = $int_row['full_name'] ?? '';
                $interview_no = "INT-" . date('Ymd') . "-" . str_pad($candidate_id, 4, '0', STR_PAD_LEFT) . "-R{$round_number}";

                $insert_stmt = mysqli_prepare($conn, "
                    INSERT INTO interviews (
                        interview_no,
                        candidate_id,
                        hiring_request_id,
                        interview_round,
                        round_number,
                        interview_date,
                        interview_time,
                        interview_duration,
                        interview_mode,
                        interview_link,
                        location,
                        interviewer_id,
                        interviewer_name,
                        created_by,
                        created_by_name
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "siisississssiis",
                    $interview_no,
                    $candidate_id,
                    $hiring_request_id,
                    $interview_round,
                    $round_number,
                    $interview_date,
                    $interview_time,
                    $interview_duration,
                    $interview_mode,
                    $interview_link,
                    $location,
                    $interviewer_id,
                    $interviewer_name,
                    $current_employee_id,
                    $current_employee['full_name']
                );

                if (mysqli_stmt_execute($insert_stmt)) {
                    mysqli_query($conn, "UPDATE candidates SET status = 'Interview Scheduled' WHERE id = " . (int)$candidate_id);

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

                mysqli_stmt_close($insert_stmt);
            }
        }
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'all';
$hiring_filter = isset($_GET['hiring_id']) ? (int)$_GET['hiring_id'] : 0;
$search        = trim((string)($_GET['search'] ?? ''));

// ---------------- FETCH CANDIDATES ----------------
$query = "
    SELECT c.*,
           h.request_no,
           h.position_title,
           h.department,
           h.designation AS hiring_designation,
           (SELECT COUNT(*) FROM interviews i WHERE i.candidate_id = c.id) AS interview_count,
           (SELECT MAX(i2.round_number) FROM interviews i2 WHERE i2.candidate_id = c.id) AS current_round
    FROM candidates c
    LEFT JOIN hiring_requests h ON c.hiring_request_id = h.id
    WHERE 1 = 1
";

if (!$isHr && !$isAdmin && $isManager) {
    $query .= " AND h.requested_by = " . (int)$current_employee_id;
}

if ($status_filter !== 'all') {
    $status_filter_escaped = mysqli_real_escape_string($conn, $status_filter);
    $query .= " AND c.status = '{$status_filter_escaped}'";
}

if ($hiring_filter > 0) {
    $query .= " AND c.hiring_request_id = " . (int)$hiring_filter;
}

if ($search !== '') {
    $search_term = mysqli_real_escape_string($conn, $search);
    $query .= " AND (
        c.first_name LIKE '%{$search_term}%'
        OR c.last_name LIKE '%{$search_term}%'
        OR c.email LIKE '%{$search_term}%'
        OR c.phone LIKE '%{$search_term}%'
        OR c.candidate_code LIKE '%{$search_term}%'
        OR h.position_title LIKE '%{$search_term}%'
        OR h.request_no LIKE '%{$search_term}%'
    )";
}

$query .= " ORDER BY c.created_at DESC";
$candidates = mysqli_query($conn, $query);

// ---------------- HIRING REQUESTS FOR FILTER ----------------
$hiring_query = "
    SELECT hr.id, hr.request_no, hr.position_title, hr.department
    FROM hiring_requests hr
    WHERE hr.status IN ('Approved', 'In Progress')
";

if (!$isHr && !$isAdmin && $isManager) {
    $hiring_query .= " AND hr.requested_by = " . (int)$current_employee_id;
}

$hiring_query .= " ORDER BY hr.created_at DESC";
$hiring_requests = mysqli_query($conn, $hiring_query);

// ---------------- EMPLOYEES FOR INTERVIEWER DROPDOWN ----------------
$employees_query = "
    SELECT id, full_name, designation
    FROM employees
    WHERE employee_status = 'active'
    ORDER BY full_name
";
$employees = mysqli_query($conn, $employees_query);

// ---------------- STATS ----------------
$stats = [
    'total'     => 0,
    'new'       => 0,
    'interview' => 0,
    'selected'  => 0
];

$stats_query = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN c.status = 'New' THEN 1 ELSE 0 END) AS new,
        SUM(CASE WHEN c.status = 'Interview Scheduled' THEN 1 ELSE 0 END) AS interview,
        SUM(CASE WHEN c.status IN ('Selected', 'Offered', 'Joined') THEN 1 ELSE 0 END) AS selected
    FROM candidates c
";

if (!$isHr && !$isAdmin && $isManager) {
    $stats_query .= "
        LEFT JOIN hiring_requests h ON c.hiring_request_id = h.id
        WHERE h.requested_by = " . (int)$current_employee_id;
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

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">

    <link href="assets/css/layout-styles.css" rel="stylesheet">
    <link href="assets/css/topbar.css" rel="stylesheet">
    <link href="assets/css/footer.css" rel="stylesheet">

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

        .filter-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 18px;
            margin-bottom: 20px;
        }

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

        .delete-btn {
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.25);
        }

        .delete-btn:hover {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #ef4444;
        }

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

        .alert {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        th.actions-col,
        td.actions-col {
            width: 220px !important;
            white-space: nowrap !important;
        }

        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border-radius: 10px !important;
        }

        @media (max-width: 767px) {
            .content-scroll { padding: 14px; }
            .stat-value { font-size: 24px; }
            th.actions-col,
            td.actions-col { width: auto !important; }
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

                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
                        <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Candidates</h1>
                        <p class="text-muted mb-0">Manage candidate pipeline and interviews</p>
                    </div>
                </div>

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

                <div class="filter-card mb-4">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="all">All Status</option>
                                <?php foreach ($status_options as $key => $value): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>>
                                        <?php echo e($value); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Hiring Request</label>
                            <select name="hiring_id" class="form-select form-select-sm">
                                <option value="0">All Hiring Requests</option>
                                <?php if ($hiring_requests): ?>
                                    <?php while ($hr = mysqli_fetch_assoc($hiring_requests)): ?>
                                        <option value="<?php echo (int)$hr['id']; ?>" <?php echo $hiring_filter === (int)$hr['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($hr['request_no'] . ' - ' . $hr['position_title']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm" value="<?php echo e($search); ?>" placeholder="Name, email, phone, code...">
                        </div>

                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn-filter w-100 justify-content-center">
                                <i class="bi bi-funnel-fill"></i> Filter
                            </button>
                            <a href="candidates.php" class="btn btn-light border w-100 d-flex align-items-center justify-content-center">
                                Reset
                            </a>
                        </div>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Candidate List</h2>
                        <button class="panel-menu" type="button" title="Candidates">
                            <i class="bi bi-people"></i>
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table id="candidatesTable" class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Candidate</th>
                                    <th>Role / Request</th>
                                    <th>Contact</th>
                                    <th>Experience / CTC</th>
                                    <th>Status</th>
                                    <th>Pipeline</th>
                                    <th>Created</th>
                                    <th class="actions-col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($candidates && mysqli_num_rows($candidates) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($candidates)): ?>
                                        <?php
                                            $fullName = getFullName($row['first_name'] ?? '', $row['last_name'] ?? '');
                                            $initials = strtoupper(substr((string)($row['first_name'] ?? 'C'), 0, 1) . substr((string)($row['last_name'] ?? ''), 0, 1));
                                            $interview_count = (int)($row['interview_count'] ?? 0);
                                            $current_round = (int)($row['current_round'] ?? 0);
                                            $next_round = max(1, $current_round + 1);
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-start gap-2">
                                                    <div class="candidate-avatar">
                                                        <?php if (!empty($row['photo_path']) && fileExistsWeb($row['photo_path'])): ?>
                                                            <img src="../<?php echo e(ltrim($row['photo_path'], '/')); ?>" alt="<?php echo e($fullName); ?>">
                                                        <?php else: ?>
                                                            <?php echo e($initials ?: 'C'); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="candidate-name"><?php echo e($fullName); ?></div>
                                                        <div class="candidate-code"><?php echo e($row['candidate_code'] ?? '-'); ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="role-info">
                                                    <div class="position-text">
                                                        <i class="bi bi-briefcase-fill"></i>
                                                        <?php echo e($row['position_title'] ?? '-'); ?>
                                                    </div>
                                                    <div class="department-badge">
                                                        <i class="bi bi-diagram-3"></i>
                                                        <?php echo e($row['department'] ?? '-'); ?>
                                                    </div>
                                                    <div class="candidate-code mt-1">
                                                        Request: <?php echo e($row['request_no'] ?? '-'); ?>
                                                    </div>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="contact-info">
                                                    <i class="bi bi-envelope"></i>
                                                    <?php echo e($row['email'] ?? '-'); ?>
                                                </div>
                                                <div class="contact-info">
                                                    <i class="bi bi-telephone"></i>
                                                    <?php echo e($row['phone'] ?? '-'); ?>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="exp-info">
                                                    <?php echo e($row['total_experience'] !== null ? number_format((float)$row['total_experience'], 1) . ' yrs' : '-'); ?>
                                                </div>
                                                <div class="ctc-info">
                                                    Current: <?php echo e($row['current_ctc'] !== null ? number_format((float)$row['current_ctc'], 2) : '-'); ?>
                                                </div>
                                                <div class="ctc-info">
                                                    Expected: <?php echo e($row['expected_ctc'] !== null ? number_format((float)$row['expected_ctc'], 2) : '-'); ?>
                                                </div>
                                            </td>

                                            <td><?php echo getStatusBadge($row['status'] ?? 'New'); ?></td>

                                            <td>
                                                <div class="candidate-code">Interviews: <?php echo $interview_count; ?></div>
                                                <div class="pipeline-stages">
                                                    <span class="stage-dot <?php echo $interview_count >= 1 ? 'completed' : ($next_round == 1 ? 'current' : ''); ?>"></span>
                                                    <span class="stage-dot <?php echo $interview_count >= 2 ? 'completed' : ($next_round == 2 ? 'current' : ''); ?>"></span>
                                                    <span class="stage-dot <?php echo $interview_count >= 3 ? 'completed' : ($next_round == 3 ? 'current' : ''); ?>"></span>
                                                    <span class="stage-dot <?php echo $interview_count >= 4 ? 'completed' : ($next_round == 4 ? 'current' : ''); ?>"></span>
                                                    <span class="stage-dot <?php echo $interview_count >= 5 ? 'completed' : ($next_round >= 5 ? 'current' : ''); ?>"></span>
                                                </div>
                                            </td>

                                            <td>
                                                <?php echo !empty($row['created_at']) ? date('d-m-Y', strtotime($row['created_at'])) : '-'; ?>
                                            </td>

                                            <td class="actions-col">
                                                <a href="view-candidate.php?id=<?php echo (int)$row['id']; ?>" class="btn-action" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>

                                                <button type="button"
                                                        class="btn-action success"
                                                        title="Schedule Interview"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#scheduleInterviewModal"
                                                        data-candidate-id="<?php echo (int)$row['id']; ?>"
                                                        data-hiring-request-id="<?php echo (int)$row['hiring_request_id']; ?>"
                                                        data-candidate-name="<?php echo e($fullName); ?>"
                                                        data-next-round="<?php echo $next_round; ?>">
                                                    <i class="bi bi-calendar-plus"></i>
                                                </button>

                                                <button type="button"
                                                        class="btn-action warning"
                                                        title="Update Status"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#statusModal"
                                                        data-candidate-id="<?php echo (int)$row['id']; ?>"
                                                        data-candidate-name="<?php echo e($fullName); ?>"
                                                        data-status="<?php echo e($row['status'] ?? 'New'); ?>">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>

                                                <button type="button"
                                                        class="btn-action delete-btn"
                                                        title="Delete Candidate"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#deleteCandidateModal"
                                                        data-candidate-id="<?php echo (int)$row['id']; ?>"
                                                        data-candidate-name="<?php echo e($fullName); ?>"
                                                        data-candidate-code="<?php echo e($row['candidate_code'] ?? '-'); ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php include 'includes/footer.php'; ?>
            </div>
        </div>
    </main>
</div>

<!-- Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="candidates.php">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="candidate_id" id="status_candidate_id">

                <div class="modal-header">
                    <h5 class="modal-title">Update Candidate Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-3">Update status for <strong class="candidate-name-holder" id="status_candidate_name">Candidate</strong></p>

                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label required">Status</label>
                            <select name="status" class="form-select form-select-sm" required>
                                <?php foreach ($status_options as $key => $value): ?>
                                    <option value="<?php echo e($key); ?>"><?php echo e($value); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control form-control-sm" rows="3" placeholder="Add notes..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Candidate Modal -->
<div class="modal fade" id="deleteCandidateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="candidates.php">
                <input type="hidden" name="action" value="delete_candidate">
                <input type="hidden" name="candidate_id" id="delete_candidate_id">

                <div class="modal-header">
                    <h5 class="modal-title">Delete Candidate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-danger mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        This action will permanently delete the candidate and related records.
                    </div>

                    <p class="mb-1"><strong>Name:</strong> <span id="delete_candidate_name">-</span></p>
                    <p class="mb-0"><strong>Candidate Code:</strong> <span id="delete_candidate_code">-</span></p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Schedule Interview Modal -->
<div class="modal fade" id="scheduleInterviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="candidates.php">
                <input type="hidden" name="action" value="schedule_interview">
                <input type="hidden" name="candidate_id" id="interview_candidate_id">
                <input type="hidden" name="hiring_request_id" id="interview_hiring_id">
                <input type="hidden" name="round_number" id="interview_round" value="1">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Schedule Interview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-3">Schedule interview for <strong class="candidate-name-holder" id="interview_candidate_name">Candidate</strong></p>

                    <div class="mb-3">
                        <label class="form-label required">Interview Round</label>
                        <select name="interview_round" class="form-select select2" required>
                            <?php foreach ($interview_rounds as $key => $value): ?>
                                <option value="<?php echo e($key); ?>"><?php echo e($value); ?></option>
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
                            <label class="form-label required">Duration (Minutes)</label>
                            <input type="number" name="interview_duration" class="form-control" min="5" value="30" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label required">Mode</label>
                            <select name="interview_mode" class="form-select" required>
                                <option value="">Select Mode</option>
                                <option value="Online">Online</option>
                                <option value="In-Person">In-Person</option>
                                <option value="Phone">Phone</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label required">Interviewer</label>
                        <select name="interviewer_id" class="form-select select2" required>
                            <option value="">Select Interviewer</option>
                            <?php if ($employees): ?>
                                <?php mysqli_data_seek($employees, 0); ?>
                                <?php while ($emp = mysqli_fetch_assoc($employees)): ?>
                                    <option value="<?php echo (int)$emp['id']; ?>">
                                        <?php echo e($emp['full_name']); ?> (<?php echo e($emp['designation']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3" id="onlineLinkField">
                        <label class="form-label">Meeting Link</label>
                        <input type="url" name="interview_link" class="form-control" placeholder="https://meet.google.com/">
                    </div>

                    <div class="mb-3" id="locationField" style="display:none;">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control" placeholder="Office address">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calendar-check me-1"></i> Schedule Interview
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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

    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%',
        dropdownParent: $('.modal')
    });

    $('select[name="interview_mode"]').on('change', function() {
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

    setTimeout(function() {
        $('.dataTables_filter input').focus();
    }, 400);
});

document.addEventListener('DOMContentLoaded', function () {
    const deleteModal = document.getElementById('deleteCandidateModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;

            document.getElementById('delete_candidate_id').value = button.getAttribute('data-candidate-id') || '';
            document.getElementById('delete_candidate_name').textContent = button.getAttribute('data-candidate-name') || '-';
            document.getElementById('delete_candidate_code').textContent = button.getAttribute('data-candidate-code') || '-';
        });
    }

    const scheduleModal = document.getElementById('scheduleInterviewModal');
    if (scheduleModal) {
        scheduleModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;

            const candidateId = button.getAttribute('data-candidate-id') || '';
            const hiringRequestId = button.getAttribute('data-hiring-request-id') || '';
            const candidateName = button.getAttribute('data-candidate-name') || '';
            const nextRound = button.getAttribute('data-next-round') || '1';

            const candidateInput = document.getElementById('interview_candidate_id');
            const hiringInput = document.getElementById('interview_hiring_id');
            const roundInput = document.getElementById('interview_round');
            const nameHolder = document.getElementById('interview_candidate_name');
            const roundSelect = scheduleModal.querySelector('select[name="interview_round"]');

            if (candidateInput) candidateInput.value = candidateId;
            if (hiringInput) hiringInput.value = hiringRequestId;
            if (roundInput) roundInput.value = nextRound;
            if (nameHolder) nameHolder.textContent = candidateName;

            if (roundSelect) {
                if (parseInt(nextRound, 10) === 1) roundSelect.value = 'Telephonic';
                else if (parseInt(nextRound, 10) === 2) roundSelect.value = 'Technical Round 1';
                else if (parseInt(nextRound, 10) === 3) roundSelect.value = 'Technical Round 2';
                else if (parseInt(nextRound, 10) === 4) roundSelect.value = 'HR Round';
                else if (parseInt(nextRound, 10) === 5) roundSelect.value = 'Manager Round';
                else roundSelect.value = 'Final Round';

                $(roundSelect).trigger('change');
            }
        });
    }

    const statusModal = document.getElementById('statusModal');
    if (statusModal) {
        statusModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;

            const candidateId = button.getAttribute('data-candidate-id') || '';
            const candidateName = button.getAttribute('data-candidate-name') || '';
            const status = button.getAttribute('data-status') || '';

            const candidateInput = document.getElementById('status_candidate_id');
            const statusInput = statusModal.querySelector('select[name="status"]');
            const nameHolder = document.getElementById('status_candidate_name');

            if (candidateInput) candidateInput.value = candidateId;
            if (statusInput) statusInput.value = status;
            if (nameHolder) nameHolder.textContent = candidateName;
        });
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