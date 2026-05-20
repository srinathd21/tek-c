<?php
// interviews.php
// TEK-C compact table section style

session_start();

require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- AUTH ---------------- */

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = (int)$_SESSION['employee_id'];

$emp_stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM employees
     WHERE id = ?
     AND employee_status = 'active'
     LIMIT 1"
);

if (!$emp_stmt) {
    die("Employee query failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);

$emp_res = mysqli_stmt_get_result($emp_stmt);
$current_employee = mysqli_fetch_assoc($emp_res);

mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die("Employee not found.");
}

$designation = strtolower(trim((string)($current_employee['designation'] ?? '')));
$department = strtolower(trim((string)($current_employee['department'] ?? '')));

$isHr =
    $designation === 'hr' ||
    $department === 'hr';

$isManager =
    in_array(
        $designation,
        [
            'manager',
            'team lead',
            'project manager',
            'director',
            'administrator',
            'admin',
            'general manager'
        ],
        true
    );

$isAdmin =
    $designation === 'administrator' ||
    $designation === 'admin' ||
    $designation === 'director';

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

/* ---------------- HELPERS ---------------- */

function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function nullIfEmpty($v){
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function safeDate($v, $dash = '—'){

    $v = trim((string)$v);

    if (
        $v === '' ||
        $v === '0000-00-00' ||
        $v === '0000-00-00 00:00:00'
    ) {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y', $ts) : e($v);
}

function safeTime($v, $dash = '—'){

    $v = trim((string)$v);

    if ($v === '') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('h:i A', $ts) : e($v);
}

function getFullName($first, $last){
    return trim((string)$first . ' ' . (string)$last);
}

function initials($name){

    $name = trim((string)$name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name);

    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last = strtoupper(substr(end($parts) ?: '', 0, 1));

    return count($parts) > 1 ? $first . $last : $first;
}

function fileUrl($path){

    $p = trim((string)$path);

    if ($p === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $p)) {
        return $p;
    }

    if (stripos($p, '../admin/uploads/') === 0) {
        return $p;
    }

    if (stripos($p, 'admin/uploads/') === 0) {
        return '../' . $p;
    }

    if (stripos($p, '/admin/uploads/') === 0) {
        return '..' . $p;
    }

    if (stripos($p, 'uploads/') === 0) {
        return '../admin/' . $p;
    }

    if (stripos($p, '/uploads/') === 0) {
        return '../admin' . $p;
    }

    return '../admin/uploads/' . ltrim($p, '/');
}

function interviewStatusBadge($status){

    $status = trim((string)$status);

    $map = [
        'Scheduled' => ['Scheduled', 'warning', 'scheduled'],
        'Completed' => ['Completed', 'ontrack', 'completed'],
        'Cancelled' => ['Cancelled', 'danger', 'cancelled'],
        'Rescheduled' => ['Rescheduled', 'info', 'rescheduled'],
        'No Show' => ['No Show', 'danger', 'no show']
    ];

    return $map[$status] ?? [$status ?: 'Unknown', 'muted', strtolower($status ?: 'unknown')];
}

function interviewResultBadge($result){

    $result = trim((string)$result);

    $map = [
        'Selected' => ['Selected', 'ontrack'],
        'Rejected' => ['Rejected', 'danger'],
        'On Hold' => ['On Hold', 'warning'],
        'Pending' => ['Pending', 'muted']
    ];

    return $map[$result] ?? [$result ?: 'Pending', 'muted'];
}

function candidateStatusBadge($status){

    $status = trim((string)$status);

    $map = [
        'Selected' => ['Selected', 'ontrack'],
        'Offered' => ['Offered', 'info'],
        'Joined' => ['Joined', 'ontrack'],
        'Rejected' => ['Rejected', 'danger'],
        'Interviewed' => ['Interviewed', 'info'],
        'Interview Scheduled' => ['Interview Scheduled', 'warning']
    ];

    return $map[$status] ?? [$status ?: 'Unknown', 'muted'];
}

function ratingStars($rating){

    $rating = (int)$rating;

    if ($rating <= 0) {
        return '—';
    }

    $html = '';

    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $rating
            ? '<i class="bi bi-star-fill"></i>'
            : '<i class="bi bi-star"></i>';
    }

    return $html;
}

function formatCurrency($amount){

    if ($amount === null || $amount === '' || (float)$amount <= 0) {
        return '—';
    }

    return '₹ ' . number_format((float)$amount, 2) . ' LPA';
}

/* ---------------- OPTIONS ---------------- */

$status_options = [
    'all' => 'All Status',
    'Scheduled' => 'Scheduled',
    'Completed' => 'Completed',
    'Rescheduled' => 'Rescheduled',
    'Cancelled' => 'Cancelled',
    'No Show' => 'No Show'
];

$result_options = [
    'Pending',
    'Selected',
    'Rejected',
    'On Hold'
];

/* ---------------- POST ACTIONS ---------------- */

$message = '';
$messageType = '';
$validation_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = trim((string)$_POST['action']);

    /* ---------- UPDATE INTERVIEW ---------- */

    if ($action === 'update_interview') {

        $interview_id = (int)($_POST['interview_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $result = trim($_POST['result'] ?? 'Pending');
        $feedback = trim($_POST['feedback'] ?? '');
        $rating = $_POST['rating'] !== '' ? (int)$_POST['rating'] : null;
        $strengths = trim($_POST['strengths'] ?? '');
        $weaknesses = trim($_POST['weaknesses'] ?? '');
        $technical_skills_rating = $_POST['technical_skills_rating'] !== '' ? (int)$_POST['technical_skills_rating'] : null;
        $communication_rating = $_POST['communication_rating'] !== '' ? (int)$_POST['communication_rating'] : null;
        $attitude_rating = $_POST['attitude_rating'] !== '' ? (int)$_POST['attitude_rating'] : null;

        if ($interview_id <= 0) {
            $validation_errors[] = "Invalid interview selected";
        }

        if (!in_array($status, ['Scheduled', 'Completed', 'Cancelled', 'No Show'], true)) {
            $validation_errors[] = "Invalid interview status selected";
        }

        if (!in_array($result, $result_options, true)) {
            $validation_errors[] = "Invalid interview result selected";
        }

        foreach ([
            'Overall rating' => $rating,
            'Technical skills rating' => $technical_skills_rating,
            'Communication rating' => $communication_rating,
            'Attitude rating' => $attitude_rating
        ] as $label => $val) {
            if ($val !== null && ($val < 1 || $val > 5)) {
                $validation_errors[] = $label . " must be between 1 and 5";
            }
        }

        if (empty($validation_errors)) {

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE interviews
                 SET
                    status = ?,
                    result = ?,
                    feedback = ?,
                    rating = ?,
                    strengths = ?,
                    weaknesses = ?,
                    technical_skills_rating = ?,
                    communication_rating = ?,
                    attitude_rating = ?
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "sssissiiii",
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

                    $candidate_id = 0;

                    $cand_stmt = mysqli_prepare(
                        $conn,
                        "SELECT candidate_id
                         FROM interviews
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($cand_stmt) {
                        mysqli_stmt_bind_param($cand_stmt, "i", $interview_id);
                        mysqli_stmt_execute($cand_stmt);
                        mysqli_stmt_bind_result($cand_stmt, $candidate_id);
                        mysqli_stmt_fetch($cand_stmt);
                        mysqli_stmt_close($cand_stmt);
                    }

                    if ($candidate_id > 0 && $status === 'Completed' && $result === 'Selected') {

                        $cand_update = mysqli_prepare(
                            $conn,
                            "UPDATE candidates
                             SET status = 'Selected'
                             WHERE id = ?
                             LIMIT 1"
                        );

                        if ($cand_update) {
                            mysqli_stmt_bind_param($cand_update, "i", $candidate_id);
                            mysqli_stmt_execute($cand_update);
                            mysqli_stmt_close($cand_update);
                        }

                    } elseif ($candidate_id > 0 && $status === 'Completed') {

                        $cand_update = mysqli_prepare(
                            $conn,
                            "UPDATE candidates
                             SET status = 'Interviewed'
                             WHERE id = ?
                             AND status NOT IN ('Selected', 'Offered', 'Joined')
                             LIMIT 1"
                        );

                        if ($cand_update) {
                            mysqli_stmt_bind_param($cand_update, "i", $candidate_id);
                            mysqli_stmt_execute($cand_update);
                            mysqli_stmt_close($cand_update);
                        }
                    }

                    if (function_exists('logActivity')) {
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
                    }

                    $message = "Interview updated successfully!";
                    $messageType = "success";

                } else {
                    $message = "Error updating interview: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);

            } else {
                $message = "Database error: " . mysqli_error($conn);
                $messageType = "danger";
            }
        } else {
            $messageType = "warning";
        }
    }

    /* ---------- RESCHEDULE INTERVIEW ---------- */

    elseif ($action === 'reschedule_interview') {

        $interview_id = (int)($_POST['interview_id'] ?? 0);
        $interview_date = trim($_POST['interview_date'] ?? '');
        $interview_time = trim($_POST['interview_time'] ?? '');
        $reschedule_reason = trim($_POST['reschedule_reason'] ?? '');

        if ($interview_id <= 0) {
            $validation_errors[] = "Invalid interview selected";
        }

        if ($interview_date === '') {
            $validation_errors[] = "New date is required";
        }

        if ($interview_time === '') {
            $validation_errors[] = "New time is required";
        }

        if ($reschedule_reason === '') {
            $validation_errors[] = "Reason for rescheduling is required";
        }

        if (empty($validation_errors)) {

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE interviews
                 SET
                    interview_date = ?,
                    interview_time = ?,
                    status = 'Rescheduled',
                    reschedule_reason = ?
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "sssi",
                    $interview_date,
                    $interview_time,
                    $reschedule_reason,
                    $interview_id
                );

                if (mysqli_stmt_execute($update_stmt)) {

                    if (function_exists('logActivity')) {
                        logActivity(
                            $conn,
                            'UPDATE',
                            'interview',
                            "Rescheduled interview ID: {$interview_id}",
                            $interview_id,
                            null,
                            null,
                            json_encode([
                                'date' => $interview_date,
                                'time' => $interview_time,
                                'reason' => $reschedule_reason
                            ])
                        );
                    }

                    $message = "Interview rescheduled successfully!";
                    $messageType = "success";

                } else {
                    $message = "Error rescheduling interview: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }

        } else {
            $messageType = "warning";
        }
    }

    /* ---------- CANCEL INTERVIEW ---------- */

    elseif ($action === 'cancel_interview') {

        $interview_id = (int)($_POST['interview_id'] ?? 0);
        $cancellation_reason = trim($_POST['cancellation_reason'] ?? '');

        if ($interview_id <= 0) {
            $validation_errors[] = "Invalid interview selected";
        }

        if ($cancellation_reason === '') {
            $validation_errors[] = "Reason for cancellation is required";
        }

        if (empty($validation_errors)) {

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE interviews
                 SET
                    status = 'Cancelled',
                    cancellation_reason = ?
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "si",
                    $cancellation_reason,
                    $interview_id
                );

                if (mysqli_stmt_execute($update_stmt)) {

                    if (function_exists('logActivity')) {
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
                    }

                    $message = "Interview cancelled successfully!";
                    $messageType = "success";

                } else {
                    $message = "Error cancelling interview: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }

        } else {
            $messageType = "warning";
        }
    }
}

/* ---------------- FILTERS ---------------- */

$status_filter = trim((string)($_GET['status'] ?? 'all'));
$interviewer_filter = isset($_GET['interviewer_id']) ? (int)$_GET['interviewer_id'] : 0;
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

if (!array_key_exists($status_filter, $status_options)) {
    $status_filter = 'all';
}

/* ---------------- INTERVIEWERS ---------------- */

$interviewers = [];

$interviewers_result = mysqli_query(
    $conn,
    "SELECT DISTINCT
        e.id,
        e.full_name,
        e.designation
     FROM interviews i
     JOIN employees e
     ON i.interviewer_id = e.id
     ORDER BY e.full_name ASC"
);

if ($interviewers_result) {
    $interviewers = mysqli_fetch_all($interviewers_result, MYSQLI_ASSOC);
}

/* ---------------- MAIN INTERVIEWS QUERY ---------------- */

$query = "
    SELECT
        i.*,
        c.first_name,
        c.last_name,
        c.photo_path AS candidate_photo,
        c.candidate_code,
        c.phone AS candidate_phone,
        c.email AS candidate_email,
        c.status AS candidate_status,
        CONCAT(c.first_name, ' ', c.last_name) AS candidate_name,
        h.request_no,
        h.position_title,
        h.department,
        e.full_name AS interviewer_full_name,
        e.designation AS interviewer_designation,
        e.employee_code AS interviewer_code,
        o.id AS offer_id,
        o.status AS offer_status,
        ob.id AS onboarding_id,
        ob.status AS onboarding_status,
        ob.employee_code
    FROM interviews i
    JOIN candidates c
    ON i.candidate_id = c.id
    JOIN hiring_requests h
    ON i.hiring_request_id = h.id
    JOIN employees e
    ON i.interviewer_id = e.id
    LEFT JOIN offers o
    ON o.candidate_id = c.id
    LEFT JOIN onboarding ob
    ON ob.candidate_id = c.id
    WHERE 1 = 1
";

$params = [];
$types = "";

if (!$isHr && !$isAdmin && $isManager) {
    $query .= " AND h.requested_by = ?";
    $params[] = $current_employee_id;
    $types .= "i";
}

if ($status_filter !== 'all') {
    $query .= " AND i.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($interviewer_filter > 0) {
    $query .= " AND i.interviewer_id = ?";
    $params[] = $interviewer_filter;
    $types .= "i";
}

if ($date_from !== '') {
    $query .= " AND i.interview_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to !== '') {
    $query .= " AND i.interview_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($search !== '') {

    $like = '%' . $search . '%';

    $query .= "
        AND (
            c.first_name LIKE ?
            OR c.last_name LIKE ?
            OR c.email LIKE ?
            OR c.phone LIKE ?
            OR c.candidate_code LIKE ?
            OR i.interview_round LIKE ?
            OR h.position_title LIKE ?
            OR h.request_no LIKE ?
        )
    ";

    for ($i = 0; $i < 8; $i++) {
        $params[] = $like;
        $types .= "s";
    }
}

$query .= "
    ORDER BY
        i.interview_date DESC,
        i.interview_time DESC
";

$interviews = [];

$stmtInterviews = mysqli_prepare($conn, $query);

if ($stmtInterviews) {

    if (!empty($params)) {
        mysqli_stmt_bind_param(
            $stmtInterviews,
            $types,
            ...$params
        );
    }

    mysqli_stmt_execute($stmtInterviews);

    $resInterviews = mysqli_stmt_get_result($stmtInterviews);

    $interviews = mysqli_fetch_all($resInterviews, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtInterviews);

} else {
    $message = "Error fetching interviews: " . mysqli_error($conn);
    $messageType = "danger";
}

/* ---------------- CONVERTED CANDIDATES QUERY ---------------- */

$converted_query = "
    SELECT
        c.id AS candidate_id,
        c.first_name,
        c.last_name,
        c.photo_path AS candidate_photo,
        c.candidate_code,
        c.email,
        c.phone,
        c.total_experience,
        c.expected_ctc,
        c.notice_period,
        c.status AS candidate_status,
        c.updated_at AS conversion_date,
        CONCAT(c.first_name, ' ', c.last_name) AS candidate_name,
        h.request_no,
        h.position_title,
        h.department,
        h.designation AS hiring_designation,
        (
            SELECT i.interview_round
            FROM interviews i
            WHERE i.candidate_id = c.id
            AND i.result = 'Selected'
            ORDER BY i.round_number DESC
            LIMIT 1
        ) AS selected_round,
        (
            SELECT i.interviewer_name
            FROM interviews i
            WHERE i.candidate_id = c.id
            AND i.result = 'Selected'
            ORDER BY i.round_number DESC
            LIMIT 1
        ) AS selected_by,
        (
            SELECT i.interview_date
            FROM interviews i
            WHERE i.candidate_id = c.id
            AND i.result = 'Selected'
            ORDER BY i.round_number DESC
            LIMIT 1
        ) AS selected_date,
        o.id AS offer_id,
        o.offer_no,
        o.ctc AS offered_ctc,
        o.status AS offer_status,
        o.offer_date,
        o.accepted_by_candidate,
        o.response_date,
        ob.id AS onboarding_id,
        ob.onboarding_no,
        ob.joining_date,
        ob.employee_code,
        ob.status AS onboarding_status
    FROM candidates c
    JOIN hiring_requests h
    ON c.hiring_request_id = h.id
    LEFT JOIN offers o
    ON o.candidate_id = c.id
    LEFT JOIN onboarding ob
    ON ob.candidate_id = c.id
    WHERE c.status IN ('Selected', 'Offered', 'Joined')
";

$converted_params = [];
$converted_types = "";

if (!$isHr && !$isAdmin && $isManager) {
    $converted_query .= " AND h.requested_by = ?";
    $converted_params[] = $current_employee_id;
    $converted_types .= "i";
}

$converted_query .= "
    ORDER BY
        CASE c.status
            WHEN 'Joined' THEN 1
            WHEN 'Offered' THEN 2
            WHEN 'Selected' THEN 3
            ELSE 4
        END,
        c.updated_at DESC
";

$converted_candidates = [];

$stmtConverted = mysqli_prepare($conn, $converted_query);

if ($stmtConverted) {

    if (!empty($converted_params)) {
        mysqli_stmt_bind_param(
            $stmtConverted,
            $converted_types,
            ...$converted_params
        );
    }

    mysqli_stmt_execute($stmtConverted);

    $resConverted = mysqli_stmt_get_result($stmtConverted);

    $converted_candidates = mysqli_fetch_all($resConverted, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtConverted);
}

/* ---------------- STATS ---------------- */

$today = date('Y-m-d');

$statsWhere = "";
$statsParams = [];
$statsTypes = "";

if (!$isHr && !$isAdmin && $isManager) {
    $statsWhere = "
        AND hiring_request_id IN (
            SELECT id
            FROM hiring_requests
            WHERE requested_by = ?
        )
    ";
    $statsParams[] = $current_employee_id;
    $statsTypes .= "i";
}

function scalarCount($conn, $sql, $types = "", $params = []){

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return 0;
    }

    if (!empty($params)) {
        mysqli_stmt_bind_param(
            $stmt,
            $types,
            ...$params
        );
    }

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);

    mysqli_stmt_close($stmt);

    return (int)($row['count'] ?? 0);
}

$today_count = scalarCount(
    $conn,
    "SELECT COUNT(*) AS count
     FROM interviews
     WHERE interview_date = ?
     AND status = 'Scheduled'
     {$statsWhere}",
    "s" . $statsTypes,
    array_merge([$today], $statsParams)
);

$upcoming_count = scalarCount(
    $conn,
    "SELECT COUNT(*) AS count
     FROM interviews
     WHERE interview_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
     AND status = 'Scheduled'
     {$statsWhere}",
    "ss" . $statsTypes,
    array_merge([$today, $today], $statsParams)
);

$completed_count = scalarCount(
    $conn,
    "SELECT COUNT(*) AS count
     FROM interviews
     WHERE status = 'Completed'
     {$statsWhere}",
    $statsTypes,
    $statsParams
);

$converted_stats_query = "
    SELECT
        COUNT(*) AS total_converted,
        SUM(CASE WHEN c.status = 'Joined' THEN 1 ELSE 0 END) AS joined_count,
        SUM(CASE WHEN c.status = 'Offered' THEN 1 ELSE 0 END) AS offered_count,
        SUM(CASE WHEN c.status = 'Selected' THEN 1 ELSE 0 END) AS selected_count,
        AVG(c.total_experience) AS avg_experience,
        AVG(o.ctc) AS avg_ctc
    FROM candidates c
    LEFT JOIN offers o
    ON o.candidate_id = c.id
    WHERE c.status IN ('Selected', 'Offered', 'Joined')
";

$converted_stats_params = [];
$converted_stats_types = "";

if (!$isHr && !$isAdmin && $isManager) {
    $converted_stats_query .= "
        AND c.hiring_request_id IN (
            SELECT id
            FROM hiring_requests
            WHERE requested_by = ?
        )
    ";

    $converted_stats_params[] = $current_employee_id;
    $converted_stats_types .= "i";
}

$converted_stats = [
    'total_converted' => 0,
    'joined_count' => 0,
    'offered_count' => 0,
    'selected_count' => 0,
    'avg_experience' => null,
    'avg_ctc' => null
];

$stmtConvertedStats = mysqli_prepare($conn, $converted_stats_query);

if ($stmtConvertedStats) {

    if (!empty($converted_stats_params)) {
        mysqli_stmt_bind_param(
            $stmtConvertedStats,
            $converted_stats_types,
            ...$converted_stats_params
        );
    }

    mysqli_stmt_execute($stmtConvertedStats);

    $resConvertedStats = mysqli_stmt_get_result($stmtConvertedStats);

    $rowConvertedStats = mysqli_fetch_assoc($resConvertedStats);

    if ($rowConvertedStats) {
        $converted_stats = array_merge($converted_stats, $rowConvertedStats);
    }

    mysqli_stmt_close($stmtConvertedStats);
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8" />

<meta
name="viewport"
content="width=device-width, initial-scale=1"
/>

<title>Interviews - TEK-C Hiring</title>

<link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
<link rel="manifest" href="assets/fav/site.webmanifest">

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
/>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
rel="stylesheet"
/>

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
}

body{
    background:var(--page-bg);
}

.content-scroll{
    flex:1 1 auto;
    overflow:auto;
    padding:16px;
}

.interviews-wrapper{
    width:100%;
}

.page-heading{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
}

.page-heading h1{
    font-size:19px;
    font-weight:900;
    color:var(--text);
    margin:0;
}

.page-heading p{
    margin:3px 0 0;
    color:var(--muted);
    font-size:12px;
    font-weight:600;
}

.primary-btn{
    border:0;
    background:#111827;
    color:#fff;
    height:36px;
    padding:0 14px;
    border-radius:11px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:7px;
    text-decoration:none;
    white-space:nowrap;
}

.primary-btn:hover{
    background:#020617;
    color:#fff;
}

.export-btn{
    background:#10b981;
}

.export-btn:hover{
    background:#059669;
}

.stat-card{
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:12px 13px;
    min-height:78px;
    display:flex;
    align-items:center;
    gap:11px;
}

.stat-ic{
    width:38px;
    height:38px;
    border-radius:12px;
    display:grid;
    place-items:center;
    color:#fff;
    font-size:17px;
}

.blue{ background:#2f80ed; }
.green{ background:#27ae60; }
.orange{ background:#f2994a; }
.purple{ background:#8e44ad; }

.stat-label{
    color:var(--muted);
    font-weight:800;
    font-size:10.5px;
    text-transform:uppercase;
}

.stat-value{
    font-size:24px;
    font-weight:950;
}

.converted-stats{
    background:linear-gradient(135deg,#2563eb,#8b5cf6);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:14px;
    color:#fff;
    margin-bottom:14px;
}

.converted-stat-item{
    text-align:center;
}

.converted-stat-value{
    font-size:23px;
    font-weight:950;
    line-height:1;
}

.converted-stat-label{
    font-size:10.5px;
    font-weight:800;
    opacity:.9;
    margin-top:4px;
    text-transform:uppercase;
}

.panel{
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:13px;
}

.panel-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:12px;
}

.panel-title{
    font-weight:900;
    font-size:14px;
    margin:0;
}

.panel-subtitle{
    color:var(--muted);
    font-size:11px;
    font-weight:700;
    margin-top:2px;
}

.filter-bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:12px;
}

.search-box{
    position:relative;
    flex:1 1 260px;
    max-width:430px;
}

.search-box i{
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    color:#94a3b8;
    font-size:13px;
}

.search-box input{
    width:100%;
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 12px 0 34px;
    font-size:12px;
    font-weight:700;
    color:var(--text);
    outline:none;
}

.filter-form{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.filter-select,
.filter-input{
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 12px;
    font-size:12px;
    font-weight:800;
    min-width:135px;
}

.filter-submit{
    height:36px;
    border:0;
    border-radius:11px;
    background:#111827;
    color:#fff;
    padding:0 14px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:7px;
}

.filter-reset{
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    color:#475569;
    padding:0 14px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:7px;
    text-decoration:none;
}

.compact-tabs{
    border:0;
    margin-bottom:12px;
    gap:8px;
}

.compact-tabs .nav-link{
    border:1px solid var(--border);
    background:#fff;
    color:#64748b;
    border-radius:11px;
    font-weight:900;
    font-size:12px;
    padding:8px 12px;
}

.compact-tabs .nav-link.active{
    background:#111827;
    color:#fff;
    border-color:#111827;
}

.compact-tabs .badge{
    font-size:10px;
    margin-left:5px;
}

.compact-table-wrap{
    width:100%;
    border:1px solid var(--border);
    border-radius:13px;
    overflow:hidden;
    background:#fff;
}

.compact-table{
    width:100%;
    margin:0;
    table-layout:auto;
}

.compact-table thead th{
    background:var(--soft);
    color:#64748b;
    font-size:10px;
    text-transform:uppercase;
    font-weight:900;
    border-bottom:1px solid var(--border)!important;
    padding:8px 9px;
}

.compact-table tbody td{
    padding:8px 9px;
    vertical-align:middle;
    border-color:#eef2f7;
    color:#334155;
    font-weight:700;
    font-size:11.5px;
}

.compact-table tbody tr:hover{
    background:#fbfdff;
}

.table-title-cell{
    display:flex;
    align-items:center;
    gap:8px;
}

.candidate-avatar{
    width:30px;
    height:30px;
    border-radius:9px;
    display:grid;
    place-items:center;
    overflow:hidden;
    background:#eff6ff;
    color:#2563eb;
    font-size:11px;
    font-weight:950;
    flex:0 0 auto;
    position:relative;
}

.candidate-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.converted-dot{
    position:absolute;
    top:-3px;
    right:-3px;
    width:15px;
    height:15px;
    border-radius:50%;
    background:#10b981;
    color:#fff;
    display:grid;
    place-items:center;
    font-size:9px;
    border:2px solid #fff;
}

.table-primary-text{
    color:#111827;
    font-size:11.5px;
    font-weight:900;
}

.table-secondary-text{
    color:#64748b;
    font-size:10px;
    font-weight:700;
    margin-top:1px;
}

.badge-pill{
    border-radius:999px;
    padding:5px 8px;
    font-weight:900;
    font-size:10px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    white-space:nowrap;
}

.mini-dot{
    width:6px;
    height:6px;
    border-radius:50%;
    background:currentColor;
}

.ontrack{ color:#15803d; background:#dcfce7; }
.warning{ color:#b45309; background:#fef3c7; }
.danger{ color:#b91c1c; background:#fee2e2; }
.info{ color:#2563eb; background:#dbeafe; }
.muted{ color:#475569; background:#f1f5f9; }
.purple-soft{ color:#7c3aed; background:#ede9fe; }

.department-tag{
    background:#eff6ff;
    color:#2563eb;
    padding:3px 7px;
    border-radius:8px;
    font-size:10px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:4px;
}

.rating-stars{
    white-space:nowrap;
    color:#f59e0b;
    font-size:11px;
    margin-top:3px;
}

.action-group{
    display:flex;
    justify-content:flex-end;
    gap:5px;
}

.action-btn{
    width:27px;
    height:27px;
    border-radius:9px;
    border:1px solid var(--border);
    background:#fff;
    display:grid;
    place-items:center;
    text-decoration:none;
    cursor:pointer;
}

.view-btn{ color:#475569; background:#f8fafc; }
.update-btn{ color:#15803d; background:#dcfce7; }
.reschedule-btn{ color:#b45309; background:#fef3c7; }
.cancel-btn{ color:#b91c1c; background:#fee2e2; }
.info-btn{ color:#2563eb; background:#eff6ff; }

.timeline-progress{
    margin-top:5px;
    display:flex;
    gap:4px;
    flex-wrap:wrap;
}

.timeline-chip{
    font-size:9px;
    font-weight:900;
    padding:3px 6px;
    border-radius:999px;
    background:#f1f5f9;
    color:#475569;
}

.alert{
    border-radius:var(--radius);
    border:none;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    font-size:12px;
    font-weight:700;
}

.empty-state{
    text-align:center;
    padding:28px 12px;
    color:#64748b;
    font-weight:800;
    font-size:12px;
}

.form-label{
    font-weight:800;
    font-size:12px;
    color:#4b5563;
}

.required-label::after{
    content:" *";
    color:#ef4444;
}

.modal-content{
    border:0;
    border-radius:var(--radius);
    box-shadow:var(--shadow);
}

.modal-title{
    font-size:16px;
    font-weight:900;
}

@media(max-width:1199px){

    .compact-table thead{
        display:none;
    }

    .compact-table,
    .compact-table tbody,
    .compact-table tr,
    .compact-table td{
        display:block;
        width:100%;
    }

    .compact-table tbody tr{
        border-bottom:1px solid var(--border);
        padding:10px;
    }

    .compact-table tbody td{
        border:0;
        display:flex;
        justify-content:space-between;
        gap:12px;
    }

    .compact-table tbody td::before{
        content:attr(data-label);
        font-size:10px;
        font-weight:900;
        color:#64748b;
        text-transform:uppercase;
        flex:0 0 100px;
    }

    .compact-table tbody td:first-child{
        display:block;
    }

    .compact-table tbody td:first-child::before{
        display:none;
    }

    .action-group{
        justify-content:flex-start;
    }

    .page-heading{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:768px){

    .content-scroll{
        padding:12px 10px 12px!important;
    }

    .panel{
        padding:12px!important;
        margin-bottom:12px;
        border-radius:14px;
    }

    .filter-form,
    .filter-select,
    .filter-input,
    .filter-submit,
    .filter-reset{
        width:100%;
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

<div class="container-fluid interviews-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Interview Management
</h1>

<p>
Manage interview schedules, feedback, converted candidates and hiring progress
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<button
class="primary-btn export-btn"
data-bs-toggle="modal"
data-bs-target="#exportModal"
>
<i class="bi bi-download"></i>
Export
</button>

</div>

</div>

<!-- ALERTS -->

<?php if (!empty($message)): ?>

<div class="alert alert-<?php echo e($messageType); ?> alert-dismissible fade show" role="alert">

<i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>

<?php echo e($message); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<?php echo e($_SESSION['flash_error']); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php unset($_SESSION['flash_error']); ?>

<?php endif; ?>

<?php if (!empty($validation_errors)): ?>

<div class="alert alert-warning alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<strong>Please fix the following errors:</strong>

<ul class="mb-0 mt-2 ps-3">

<?php foreach ($validation_errors as $err): ?>

<li>
<?php echo e($err); ?>
</li>

<?php endforeach; ?>

</ul>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<!-- STATS -->

<div class="row g-3 mb-3">

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-calendar-check"></i>
</div>

<div>

<div class="stat-label">
Today's Interviews
</div>

<div class="stat-value">
<?php echo (int)$today_count; ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-calendar-week"></i>
</div>

<div>

<div class="stat-label">
Upcoming 7 Days
</div>

<div class="stat-value">
<?php echo (int)$upcoming_count; ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic green">
<i class="bi bi-check-circle"></i>
</div>

<div>

<div class="stat-label">
Completed
</div>

<div class="stat-value">
<?php echo (int)$completed_count; ?>
</div>

</div>

</div>

</div>

<div class="col-12 col-sm-6 col-xl-3">

<div class="stat-card">

<div class="stat-ic purple">
<i class="bi bi-trophy"></i>
</div>

<div>

<div class="stat-label">
Converted
</div>

<div class="stat-value">
<?php echo (int)($converted_stats['total_converted'] ?? 0); ?>
</div>

</div>

</div>

</div>

</div>

<?php if ((int)($converted_stats['total_converted'] ?? 0) > 0): ?>

<div class="converted-stats">

<div class="row g-3">

<div class="col-6 col-md-3 converted-stat-item">

<div class="converted-stat-value">
<?php echo (int)($converted_stats['joined_count'] ?? 0); ?>
</div>

<div class="converted-stat-label">
Joined
</div>

</div>

<div class="col-6 col-md-3 converted-stat-item">

<div class="converted-stat-value">
<?php echo (int)($converted_stats['offered_count'] ?? 0); ?>
</div>

<div class="converted-stat-label">
Offered
</div>

</div>

<div class="col-6 col-md-3 converted-stat-item">

<div class="converted-stat-value">
<?php echo (int)($converted_stats['selected_count'] ?? 0); ?>
</div>

<div class="converted-stat-label">
Selected
</div>

</div>

<div class="col-6 col-md-3 converted-stat-item">

<div class="converted-stat-value">
<?php echo !empty($converted_stats['avg_experience']) ? number_format((float)$converted_stats['avg_experience'], 1) . ' yrs' : '—'; ?>
</div>

<div class="converted-stat-label">
Avg Experience
</div>

</div>

</div>

</div>

<?php endif; ?>

<!-- TABS -->

<ul class="nav compact-tabs" id="interviewTabs" role="tablist">

<li class="nav-item" role="presentation">

<button
class="nav-link active"
id="all-tab"
data-bs-toggle="tab"
data-bs-target="#allInterviews"
type="button"
role="tab"
>
<i class="bi bi-list-ul me-1"></i>
All Interviews
<span class="badge bg-secondary">
<?php echo count($interviews); ?>
</span>
</button>

</li>

<li class="nav-item" role="presentation">

<button
class="nav-link"
id="converted-tab"
data-bs-toggle="tab"
data-bs-target="#convertedCandidates"
type="button"
role="tab"
>
<i class="bi bi-person-check me-1"></i>
Converted
<span class="badge bg-success">
<?php echo count($converted_candidates); ?>
</span>
</button>

</li>

</ul>

<div class="tab-content" id="interviewTabContent">

<!-- ALL INTERVIEWS -->

<div
class="tab-pane fade show active"
id="allInterviews"
role="tabpanel"
>

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
All Interviews
</h3>

<div class="panel-subtitle">
Compact responsive interview directory
</div>

</div>

<span class="badge bg-secondary">
<?php echo count($interviews); ?>
records
</span>

</div>

<!-- FILTER BAR -->

<div class="filter-bar">

<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="quickSearch"
placeholder="Search candidate, round, interviewer, position..."
value="<?php echo e($search); ?>"
>

</div>

<form
method="GET"
action=""
class="filter-form"
id="filterForm"
>

<select
name="status"
class="filter-select"
>

<?php foreach ($status_options as $value => $label): ?>

<option
value="<?php echo e($value); ?>"
<?php echo $status_filter === $value ? 'selected' : ''; ?>
>
<?php echo e($label); ?>
</option>

<?php endforeach; ?>

</select>

<select
name="interviewer_id"
class="filter-select"
>

<option value="0">
All Interviewers
</option>

<?php foreach ($interviewers as $int): ?>

<option
value="<?php echo (int)$int['id']; ?>"
<?php echo $interviewer_filter === (int)$int['id'] ? 'selected' : ''; ?>
>
<?php echo e($int['full_name']); ?>
</option>

<?php endforeach; ?>

</select>

<input
type="date"
name="date_from"
class="filter-input"
value="<?php echo e($date_from); ?>"
title="From Date"
>

<input
type="date"
name="date_to"
class="filter-input"
value="<?php echo e($date_to); ?>"
title="To Date"
>

<input
type="hidden"
name="search"
id="serverSearch"
value="<?php echo e($search); ?>"
>

<button
type="submit"
class="filter-submit"
>
<i class="bi bi-funnel"></i>
Apply
</button>

<a
href="interviews.php"
class="filter-reset"
>
<i class="bi bi-arrow-counterclockwise"></i>
Reset
</a>

</form>

</div>

<!-- TABLE -->

<div class="compact-table-wrap">

<table
class="table compact-table align-middle"
id="interviewsTable"
>

<thead>

<tr>

<th>Date & Time</th>
<th>Candidate</th>
<th>Position</th>
<th>Interviewer</th>
<th>Round</th>
<th>Status</th>
<th>Result</th>
<th class="text-end">Actions</th>

</tr>

</thead>

<tbody>

<?php if (empty($interviews)): ?>

<tr class="no-record-row">

<td colspan="8">

<div class="empty-state">

<i class="bi bi-calendar-x me-1"></i>
No interviews found.

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($interviews as $interview): ?>

<?php

$fullName = getFullName($interview['first_name'] ?? '', $interview['last_name'] ?? '');
$photoSrc = fileUrl($interview['candidate_photo'] ?? '');
$isConverted = in_array(($interview['candidate_status'] ?? ''), ['Selected', 'Offered', 'Joined'], true);

[$statusLabel, $statusClass, $statusKey] =
    interviewStatusBadge($interview['status'] ?? '');

[$resultLabel, $resultClass] =
    interviewResultBadge($interview['result'] ?? 'Pending');

$interviewJson =
    htmlspecialchars(
        json_encode($interview),
        ENT_QUOTES,
        'UTF-8'
    );

?>

<tr data-status="<?php echo e($statusKey); ?>">

<td data-label="Date & Time">

<div class="table-primary-text">
<?php echo e(safeTime($interview['interview_time'] ?? '')); ?>
</div>

<div class="table-secondary-text">
<i class="bi bi-calendar me-1"></i>
<?php echo e(safeDate($interview['interview_date'] ?? '')); ?>
</div>

<div class="table-secondary-text">
<i class="bi bi-hourglass me-1"></i>
<?php echo (int)($interview['interview_duration'] ?? 0); ?>
min
</div>

</td>

<td data-label="Candidate">

<div class="table-title-cell">

<div class="candidate-avatar">

<?php if ($photoSrc !== ''): ?>

<img
src="<?php echo e($photoSrc); ?>"
alt="<?php echo e($fullName); ?>"
onerror="this.style.display='none'; this.parentNode.innerHTML='<?php echo e(initials($fullName)); ?>';"
>

<?php else: ?>

<?php echo e(initials($fullName)); ?>

<?php endif; ?>

<?php if ($isConverted): ?>

<span class="converted-dot" title="Converted">
<i class="bi bi-check"></i>
</span>

<?php endif; ?>

</div>

<div>

<div class="table-primary-text">

<a
href="view-candidate.php?id=<?php echo (int)$interview['candidate_id']; ?>"
class="text-decoration-none text-dark"
>
<?php echo e($fullName); ?>
</a>

</div>

<div class="table-secondary-text">
<i class="bi bi-hash"></i>
<?php echo e($interview['candidate_code'] ?? ''); ?>
</div>

</div>

</div>

</td>

<td data-label="Position">

<div class="table-primary-text">
<?php echo e($interview['position_title'] ?? 'N/A'); ?>
</div>

<div class="table-secondary-text">
<?php echo e($interview['request_no'] ?? ''); ?>
</div>

<?php if (!empty($interview['department'])): ?>

<span class="department-tag">
<i class="bi bi-building"></i>
<?php echo e($interview['department']); ?>
</span>

<?php endif; ?>

</td>

<td data-label="Interviewer">

<div class="table-primary-text">
<?php echo e($interview['interviewer_full_name'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<?php echo e($interview['interviewer_designation'] ?? ''); ?>
</div>

<?php if (!empty($interview['interviewer_code'])): ?>

<div class="table-secondary-text">
<i class="bi bi-person-badge me-1"></i>
<?php echo e($interview['interviewer_code']); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Round">

<span class="badge-pill info">

<span class="mini-dot"></span>

<?php echo e($interview['interview_round'] ?? ''); ?>

</span>

<div class="table-secondary-text mt-1">
Round
<?php echo (int)($interview['round_number'] ?? 0); ?>
</div>

</td>

<td data-label="Status">

<span class="badge-pill <?php echo e($statusClass); ?>">

<span class="mini-dot"></span>

<?php echo e($statusLabel); ?>

</span>

</td>

<td data-label="Result">

<?php if (($interview['status'] ?? '') === 'Completed'): ?>

<span class="badge-pill <?php echo e($resultClass); ?>">

<span class="mini-dot"></span>

<?php echo e($resultLabel); ?>

</span>

<?php if (!empty($interview['rating'])): ?>

<div class="rating-stars">
<?php echo ratingStars($interview['rating']); ?>
</div>

<?php endif; ?>

<?php else: ?>

<span class="table-secondary-text">
—
</span>

<?php endif; ?>

</td>

<td data-label="Actions">

<div class="action-group">

<a
href="view-interview.php?id=<?php echo (int)$interview['id']; ?>"
class="action-btn view-btn"
title="View Details"
>
<i class="bi bi-eye"></i>
</a>

<?php if (($interview['status'] ?? '') === 'Scheduled'): ?>

<button
type="button"
class="action-btn reschedule-btn"
onclick="openRescheduleModal(<?php echo (int)$interview['id']; ?>, '<?php echo e(addslashes($fullName)); ?>', '<?php echo e($interview['interview_date']); ?>', '<?php echo e($interview['interview_time']); ?>')"
title="Reschedule"
>
<i class="bi bi-arrow-repeat"></i>
</button>

<button
type="button"
class="action-btn update-btn"
onclick="openUpdateModal(<?php echo $interviewJson; ?>)"
title="Update Feedback"
>
<i class="bi bi-pencil"></i>
</button>

<button
type="button"
class="action-btn cancel-btn"
onclick="openCancelModal(<?php echo (int)$interview['id']; ?>, '<?php echo e(addslashes($fullName)); ?>')"
title="Cancel"
>
<i class="bi bi-x-lg"></i>
</button>

<?php elseif (($interview['status'] ?? '') === 'Completed'): ?>

<button
type="button"
class="action-btn update-btn"
onclick="openUpdateModal(<?php echo $interviewJson; ?>)"
title="Edit Feedback"
>
<i class="bi bi-pencil"></i>
</button>

<?php endif; ?>

<?php if ($isConverted): ?>

<a
href="view-candidate.php?id=<?php echo (int)$interview['candidate_id']; ?>"
class="action-btn info-btn"
title="Conversion Details"
>
<i class="bi bi-person-check"></i>
</a>

<?php endif; ?>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

<div class="pagination-wrap mt-2">

<div class="table-secondary-text" id="recordInfo">
Showing
<?php echo count($interviews); ?>
interview records
</div>

</div>

</div>

</div>

<!-- CONVERTED CANDIDATES -->

<div
class="tab-pane fade"
id="convertedCandidates"
role="tabpanel"
>

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Converted Candidates
</h3>

<div class="panel-subtitle">
Selected, offered and joined candidates
</div>

</div>

<span class="badge bg-success">
<?php echo count($converted_candidates); ?>
converted
</span>

</div>

<div class="filter-bar">

<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="convertedSearch"
placeholder="Search converted candidate, position, offer, onboarding..."
>

</div>

</div>

<div class="compact-table-wrap">

<table
class="table compact-table align-middle"
id="convertedTable"
>

<thead>

<tr>

<th>Candidate</th>
<th>Position</th>
<th>Selection</th>
<th>Offer</th>
<th>Onboarding</th>
<th>Status</th>
<th class="text-end">Actions</th>

</tr>

</thead>

<tbody>

<?php if (empty($converted_candidates)): ?>

<tr class="no-record-row">

<td colspan="7">

<div class="empty-state">

<i class="bi bi-person-check me-1"></i>
No converted candidates found.

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($converted_candidates as $candidate): ?>

<?php

$fullName = getFullName($candidate['first_name'] ?? '', $candidate['last_name'] ?? '');
$photoSrc = fileUrl($candidate['candidate_photo'] ?? '');

[$candStatusLabel, $candStatusClass] =
    candidateStatusBadge($candidate['candidate_status'] ?? '');

?>

<tr>

<td data-label="Candidate">

<div class="table-title-cell">

<div class="candidate-avatar">

<?php if ($photoSrc !== ''): ?>

<img
src="<?php echo e($photoSrc); ?>"
alt="<?php echo e($fullName); ?>"
onerror="this.style.display='none'; this.parentNode.innerHTML='<?php echo e(initials($fullName)); ?>';"
>

<?php else: ?>

<?php echo e(initials($fullName)); ?>

<?php endif; ?>

</div>

<div>

<div class="table-primary-text">

<a
href="view-candidate.php?id=<?php echo (int)$candidate['candidate_id']; ?>"
class="text-decoration-none text-dark"
>
<?php echo e($fullName); ?>
</a>

</div>

<div class="table-secondary-text">
<i class="bi bi-hash"></i>
<?php echo e($candidate['candidate_code'] ?? ''); ?>
</div>

</div>

</div>

</td>

<td data-label="Position">

<div class="table-primary-text">
<?php echo e($candidate['position_title'] ?? 'N/A'); ?>
</div>

<div class="table-secondary-text">
<?php echo e($candidate['request_no'] ?? ''); ?>
</div>

<?php if (!empty($candidate['department'])): ?>

<span class="department-tag">
<i class="bi bi-building"></i>
<?php echo e($candidate['department']); ?>
</span>

<?php endif; ?>

</td>

<td data-label="Selection">

<?php if (!empty($candidate['selected_round'])): ?>

<span class="badge-pill ontrack">

<span class="mini-dot"></span>

<?php echo e($candidate['selected_round']); ?>

</span>

<div class="table-secondary-text mt-1">
<i class="bi bi-calendar me-1"></i>
<?php echo e(safeDate($candidate['selected_date'] ?? '')); ?>
</div>

<div class="table-secondary-text">
<i class="bi bi-person me-1"></i>
<?php echo e($candidate['selected_by'] ?? ''); ?>
</div>

<?php else: ?>

<span class="table-secondary-text">
—
</span>

<?php endif; ?>

</td>

<td data-label="Offer">

<?php if (!empty($candidate['offer_id'])): ?>

<span class="badge-pill info">

<span class="mini-dot"></span>

<?php echo e($candidate['offer_no']); ?>

</span>

<div class="table-secondary-text mt-1">
<?php echo e(formatCurrency($candidate['offered_ctc'] ?? null)); ?>
</div>

<div class="table-secondary-text">
<i class="bi bi-calendar me-1"></i>
<?php echo e(safeDate($candidate['offer_date'] ?? '')); ?>
</div>

<?php else: ?>

<span class="table-secondary-text">
—
</span>

<?php endif; ?>

</td>

<td data-label="Onboarding">

<?php if (!empty($candidate['onboarding_id'])): ?>

<span class="badge-pill ontrack">

<span class="mini-dot"></span>

<?php echo e($candidate['onboarding_no']); ?>

</span>

<div class="table-secondary-text mt-1">
Joining:
<?php echo e(safeDate($candidate['joining_date'] ?? '')); ?>
</div>

<?php if (!empty($candidate['employee_code'])): ?>

<div class="table-secondary-text">
<i class="bi bi-person-badge me-1"></i>
<?php echo e($candidate['employee_code']); ?>
</div>

<?php endif; ?>

<?php else: ?>

<span class="table-secondary-text">
—
</span>

<?php endif; ?>

</td>

<td data-label="Status">

<span class="badge-pill <?php echo e($candStatusClass); ?>">

<span class="mini-dot"></span>

<?php echo e($candStatusLabel); ?>

</span>

<div class="timeline-progress">

<?php if (($candidate['candidate_status'] ?? '') === 'Selected'): ?>

<span class="timeline-chip">Selected</span>

<?php elseif (($candidate['candidate_status'] ?? '') === 'Offered'): ?>

<span class="timeline-chip">Selected</span>
<span class="timeline-chip">Offered</span>

<?php elseif (($candidate['candidate_status'] ?? '') === 'Joined'): ?>

<span class="timeline-chip">Selected</span>
<span class="timeline-chip">Offered</span>
<span class="timeline-chip">Joined</span>

<?php endif; ?>

</div>

</td>

<td data-label="Actions">

<div class="action-group">

<a
href="view-candidate.php?id=<?php echo (int)$candidate['candidate_id']; ?>"
class="action-btn view-btn"
title="View Candidate"
>
<i class="bi bi-eye"></i>
</a>

<?php if (!empty($candidate['offer_id'])): ?>

<a
href="view-offer.php?id=<?php echo (int)$candidate['offer_id']; ?>"
class="action-btn info-btn"
title="View Offer"
>
<i class="bi bi-file-text"></i>
</a>

<?php endif; ?>

<?php if (!empty($candidate['onboarding_id'])): ?>

<a
href="view-onboarding.php?id=<?php echo (int)$candidate['onboarding_id']; ?>"
class="action-btn update-btn"
title="View Onboarding"
>
<i class="bi bi-person-check"></i>
</a>

<?php endif; ?>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

<div class="pagination-wrap mt-2">

<div class="table-secondary-text" id="convertedRecordInfo">
Showing
<?php echo count($converted_candidates); ?>
converted candidate records
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

<!-- UPDATE INTERVIEW MODAL -->

<div
class="modal fade"
id="updateInterviewModal"
tabindex="-1"
>

<div class="modal-dialog modal-lg modal-dialog-scrollable">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="update_interview"
>

<input
type="hidden"
name="interview_id"
id="update_interview_id"
>

<div class="modal-header">

<h5 class="modal-title">
Update Interview Feedback
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<div class="row g-3 mb-3">

<div class="col-md-6">

<label class="form-label required-label">
Status
</label>

<select
name="status"
class="form-select"
id="update_status"
required
>

<option value="Scheduled">
Scheduled
</option>

<option value="Completed">
Completed
</option>

<option value="Cancelled">
Cancelled
</option>

<option value="No Show">
No Show
</option>

</select>

</div>

<div class="col-md-6" id="resultField">

<label class="form-label">
Result
</label>

<select
name="result"
class="form-select"
id="update_result"
>

<option value="Pending">
Pending
</option>

<option value="Selected">
Selected
</option>

<option value="Rejected">
Rejected
</option>

<option value="On Hold">
On Hold
</option>

</select>

</div>

</div>

<div class="row g-3 mb-3">

<div class="col-md-3">

<label class="form-label">
Overall Rating
</label>

<select
name="rating"
class="form-select"
id="update_rating"
>

<option value="">
Not Rated
</option>

<?php for ($i = 1; $i <= 5; $i++): ?>

<option value="<?php echo $i; ?>">
<?php echo $i; ?>
/ 5
</option>

<?php endfor; ?>

</select>

</div>

<div class="col-md-3">

<label class="form-label">
Technical
</label>

<select
name="technical_skills_rating"
class="form-select"
id="update_technical"
>

<option value="">
Not Rated
</option>

<?php for ($i = 1; $i <= 5; $i++): ?>

<option value="<?php echo $i; ?>">
<?php echo $i; ?>
/ 5
</option>

<?php endfor; ?>

</select>

</div>

<div class="col-md-3">

<label class="form-label">
Communication
</label>

<select
name="communication_rating"
class="form-select"
id="update_communication"
>

<option value="">
Not Rated
</option>

<?php for ($i = 1; $i <= 5; $i++): ?>

<option value="<?php echo $i; ?>">
<?php echo $i; ?>
/ 5
</option>

<?php endfor; ?>

</select>

</div>

<div class="col-md-3">

<label class="form-label">
Attitude
</label>

<select
name="attitude_rating"
class="form-select"
id="update_attitude"
>

<option value="">
Not Rated
</option>

<?php for ($i = 1; $i <= 5; $i++): ?>

<option value="<?php echo $i; ?>">
<?php echo $i; ?>
/ 5
</option>

<?php endfor; ?>

</select>

</div>

</div>

<div class="mb-3">

<label class="form-label">
Strengths
</label>

<textarea
name="strengths"
class="form-control"
rows="2"
id="update_strengths"
placeholder="What went well?"
></textarea>

</div>

<div class="mb-3">

<label class="form-label">
Weaknesses
</label>

<textarea
name="weaknesses"
class="form-control"
rows="2"
id="update_weaknesses"
placeholder="Areas to improve..."
></textarea>

</div>

<div class="mb-3">

<label class="form-label">
Detailed Feedback
</label>

<textarea
name="feedback"
class="form-control"
rows="4"
id="update_feedback"
placeholder="Provide detailed interview feedback..."
></textarea>

</div>

<div class="alert alert-info mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>
If you mark as Completed with Selected result, the candidate will be moved to Selected status.

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-secondary"
data-bs-dismiss="modal"
>
Cancel
</button>

<button
type="submit"
class="btn btn-dark"
>
Update Interview
</button>

</div>

</form>

</div>

</div>

</div>

<!-- RESCHEDULE MODAL -->

<div
class="modal fade"
id="rescheduleModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="reschedule_interview"
>

<input
type="hidden"
name="interview_id"
id="reschedule_id"
>

<div class="modal-header">

<h5 class="modal-title">
Reschedule Interview
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p class="mb-3">
Reschedule interview for
<strong id="reschedule_candidate"></strong>
</p>

<div class="row g-2 mb-3">

<div class="col-md-6">

<label class="form-label required-label">
New Date
</label>

<input
type="date"
name="interview_date"
class="form-control"
id="reschedule_date"
min="<?php echo date('Y-m-d'); ?>"
required
>

</div>

<div class="col-md-6">

<label class="form-label required-label">
New Time
</label>

<input
type="time"
name="interview_time"
class="form-control"
id="reschedule_time"
required
>

</div>

</div>

<div class="mb-3">

<label class="form-label required-label">
Reason for Rescheduling
</label>

<textarea
name="reschedule_reason"
class="form-control"
rows="3"
required
></textarea>

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-secondary"
data-bs-dismiss="modal"
>
Cancel
</button>

<button
type="submit"
class="btn btn-warning text-white"
>
Reschedule Interview
</button>

</div>

</form>

</div>

</div>

</div>

<!-- CANCEL MODAL -->

<div
class="modal fade"
id="cancelModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="cancel_interview"
>

<input
type="hidden"
name="interview_id"
id="cancel_id"
>

<div class="modal-header">

<h5 class="modal-title">
Cancel Interview
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p class="mb-3">
Are you sure you want to cancel interview for
<strong id="cancel_candidate"></strong>?
</p>

<div class="mb-3">

<label class="form-label required-label">
Reason for Cancellation
</label>

<textarea
name="cancellation_reason"
class="form-control"
rows="3"
required
></textarea>

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-secondary"
data-bs-dismiss="modal"
>
Cancel
</button>

<button
type="submit"
class="btn btn-danger"
>
Cancel Interview
</button>

</div>

</form>

</div>

</div>

</div>

<!-- EXPORT MODAL -->

<div
class="modal fade"
id="exportModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">

<h5 class="modal-title">
Export Interviews
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<div class="mb-3">

<label class="form-label">
Export Format
</label>

<select
class="form-select"
id="exportFormat"
>

<option value="csv">
CSV
</option>

<option value="server">
Server Export Page
</option>

</select>

</div>

<div class="alert alert-info mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>
CSV exports the currently displayed rows. Server Export redirects to export-interviews.php with current filters.

</div>

</div>

<div class="modal-footer">

<button
type="button"
class="btn btn-secondary"
data-bs-dismiss="modal"
>
Cancel
</button>

<button
type="button"
class="btn btn-success"
onclick="handleExport()"
>
<i class="bi bi-download me-1"></i>
Export
</button>

</div>

</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function(){

    const quickSearch =
        document.getElementById('quickSearch');

    const serverSearch =
        document.getElementById('serverSearch');

    const tableRows =
        document.querySelectorAll('#interviewsTable tbody tr:not(.no-record-row)');

    const recordInfo =
        document.getElementById('recordInfo');

    function filterRows(){

        const searchValue =
            quickSearch.value.toLowerCase().trim();

        let visibleCount =
            0;

        tableRows.forEach(function(row){

            const rowText =
                row.innerText.toLowerCase();

            const matches =
                rowText.includes(searchValue);

            row.style.display =
                matches
                ? ''
                : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        if (recordInfo) {
            recordInfo.textContent =
                'Showing ' + visibleCount + ' interview records';
        }

        if (serverSearch) {
            serverSearch.value =
                searchValue;
        }
    }

    if (quickSearch) {
        quickSearch.addEventListener('input', filterRows);
    }

    const convertedSearch =
        document.getElementById('convertedSearch');

    const convertedRows =
        document.querySelectorAll('#convertedTable tbody tr:not(.no-record-row)');

    const convertedRecordInfo =
        document.getElementById('convertedRecordInfo');

    function filterConverted(){

        const searchValue =
            convertedSearch.value.toLowerCase().trim();

        let visibleCount =
            0;

        convertedRows.forEach(function(row){

            const rowText =
                row.innerText.toLowerCase();

            const matches =
                rowText.includes(searchValue);

            row.style.display =
                matches
                ? ''
                : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        if (convertedRecordInfo) {
            convertedRecordInfo.textContent =
                'Showing ' + visibleCount + ' converted candidate records';
        }
    }

    if (convertedSearch) {
        convertedSearch.addEventListener('input', filterConverted);
    }

    const statusSelect =
        document.getElementById('update_status');

    const resultField =
        document.getElementById('resultField');

    const resultSelect =
        document.getElementById('update_result');

    function toggleResultField(){

        if (!statusSelect || !resultField || !resultSelect) {
            return;
        }

        if (statusSelect.value === 'Completed') {
            resultField.style.display = '';
        } else {
            resultField.style.display = 'none';
            resultSelect.value = 'Pending';
        }
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', toggleResultField);
        toggleResultField();
    }
});

function openUpdateModal(interview){

    document.getElementById('update_interview_id').value =
        interview.id || '';

    document.getElementById('update_status').value =
        interview.status || 'Scheduled';

    document.getElementById('update_result').value =
        interview.result || 'Pending';

    document.getElementById('update_rating').value =
        interview.rating || '';

    document.getElementById('update_technical').value =
        interview.technical_skills_rating || '';

    document.getElementById('update_communication').value =
        interview.communication_rating || '';

    document.getElementById('update_attitude').value =
        interview.attitude_rating || '';

    document.getElementById('update_strengths').value =
        interview.strengths || '';

    document.getElementById('update_weaknesses').value =
        interview.weaknesses || '';

    document.getElementById('update_feedback').value =
        interview.feedback || '';

    const resultField =
        document.getElementById('resultField');

    if (interview.status === 'Completed') {
        resultField.style.display = '';
    } else {
        resultField.style.display = 'none';
    }

    new bootstrap.Modal(
        document.getElementById('updateInterviewModal')
    ).show();
}

function openRescheduleModal(id, candidate, date, time){

    document.getElementById('reschedule_id').value =
        id;

    document.getElementById('reschedule_candidate').textContent =
        candidate;

    document.getElementById('reschedule_date').value =
        date || '';

    document.getElementById('reschedule_time').value =
        time || '';

    new bootstrap.Modal(
        document.getElementById('rescheduleModal')
    ).show();
}

function openCancelModal(id, candidate){

    document.getElementById('cancel_id').value =
        id;

    document.getElementById('cancel_candidate').textContent =
        candidate;

    new bootstrap.Modal(
        document.getElementById('cancelModal')
    ).show();
}

function handleExport(){

    const format =
        document.getElementById('exportFormat').value;

    if (format === 'server') {
        window.location.href =
            'export-interviews.php?' + window.location.search.substring(1);
        return;
    }

    exportToCSV();
}

function exportToCSV(){

    const rows =
        document.querySelectorAll('#interviewsTable tbody tr:not(.no-record-row)');

    const csv =
        [];

    const headers = [
        'Date & Time',
        'Candidate',
        'Position',
        'Interviewer',
        'Round',
        'Status',
        'Result'
    ];

    csv.push(headers.join(','));

    rows.forEach(function(row){

        if (row.style.display === 'none') {
            return;
        }

        const cells =
            row.querySelectorAll('td');

        if (cells.length < 7) {
            return;
        }

        const rowData = [
            cells[0]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[1]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[2]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[3]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[4]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[5]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[6]?.innerText.replace(/\s+/g, ' ').trim() || ''
        ].map(function(value){
            return '"' + String(value).replace(/"/g, '""') + '"';
        });

        csv.push(rowData.join(','));
    });

    const csvString =
        csv.join('\n');

    const blob =
        new Blob(
            ["\uFEFF" + csvString],
            { type: 'text/csv;charset=utf-8;' }
        );

    const url =
        window.URL.createObjectURL(blob);

    const a =
        document.createElement('a');

    a.href =
        url;

    a.download =
        'interviews_<?php echo date('Y-m-d'); ?>.csv';

    document.body.appendChild(a);

    a.click();

    document.body.removeChild(a);

    window.URL.revokeObjectURL(url);
}

</script>

</body>
</html>

<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>