<?php
// offer-approval.php
// TEK-C compact table section style + automatic onboarding creation

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

$current_designation = strtolower(trim((string)($current_employee['designation'] ?? '')));
$current_department  = strtolower(trim((string)($current_employee['department'] ?? '')));

$isHr =
    $current_designation === 'hr' ||
    $current_department === 'hr';

$isDirector =
    in_array(
        $current_designation,
        [
            'director',
            'vice president',
            'general manager'
        ],
        true
    );

$isAdmin =
    $current_designation === 'administrator' ||
    $current_designation === 'admin';

if (!$isHr && !$isDirector && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access offer approval.";
    header("Location: ../dashboard.php");
    exit;
}

/* ---------------- HELPERS ---------------- */

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function nullIfEmpty($v) {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function safeDate($date, $dash = '—') {

    $date = trim((string)$date);

    if (
        $date === '' ||
        $date === '0000-00-00' ||
        $date === '0000-00-00 00:00:00'
    ) {
        return $dash;
    }

    $ts = strtotime($date);

    return $ts ? date('d M Y', $ts) : e($date);
}

function safeDateTime($date, $dash = '—') {

    $date = trim((string)$date);

    if (
        $date === '' ||
        $date === '0000-00-00' ||
        $date === '0000-00-00 00:00:00'
    ) {
        return $dash;
    }

    $ts = strtotime($date);

    return $ts ? date('d M Y, h:i A', $ts) : e($date);
}

function formatCurrency($amount) {

    if ($amount === null || $amount === '' || (float)$amount <= 0) {
        return '—';
    }

    return '₹ ' . number_format((float)$amount, 2) . ' LPA';
}

function getInitials($name) {

    $name = trim((string)$name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name);

    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last = strtoupper(substr(end($parts) ?: '', 0, 1));

    return count($parts) > 1 ? $first . $last : $first;
}

function fileUrl($path) {

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

    if (stripos($p, '../uploads/') === 0) {
        return $p;
    }

    if (stripos($p, 'uploads/') === 0) {
        return '../' . $p;
    }

    if (stripos($p, '/uploads/') === 0) {
        return '..' . $p;
    }

    return '../admin/uploads/' . ltrim($p, '/');
}

function offerStatusBadge($status) {

    $status = trim((string)$status);

    $map = [
        'Draft' => ['Pending Approval', 'warning', 'pending'],
        'Approved' => ['Approved', 'ontrack', 'approved'],
        'Rejected' => ['Rejected', 'danger', 'rejected'],
        'Sent' => ['Sent', 'info', 'sent'],
        'Accepted' => ['Accepted', 'ontrack', 'accepted'],
        'Expired' => ['Expired', 'warning', 'expired'],
        'Withdrawn' => ['Withdrawn', 'dark-soft', 'withdrawn']
    ];

    return $map[$status] ?? [$status ?: 'Unknown', 'muted', strtolower($status ?: 'unknown')];
}

function generateEmployeeCode($conn, $department) {

    $department = trim((string)$department);

    if ($department === '') {
        $department = 'EMP';
    }

    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $department), 0, 2));

    if ($prefix === '') {
        $prefix = 'EM';
    }

    $year = (int)date('Y');
    $count = 0;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total_count
         FROM onboarding
         WHERE YEAR(created_at) = ?"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $year);
        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);

        $count = (int)($row['total_count'] ?? 0);

        mysqli_stmt_close($stmt);
    }

    return $prefix . $year . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

function generateOnboardingNo($conn) {

    $prefix = 'ONB-' . date('Ymd') . '-';
    $like = $prefix . '%';
    $count = 0;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total_count
         FROM onboarding
         WHERE onboarding_no LIKE ?"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $like);
        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);

        $count = (int)($row['total_count'] ?? 0);

        mysqli_stmt_close($stmt);
    }

    return $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

function createOnboardingFromOffer(
    $conn,
    $offer,
    $candidate,
    $hiring,
    $joining_date,
    $reporting_time,
    $reporting_to,
    $reporting_to_name,
    $current_employee_id,
    $current_employee_name
) {

    $department_val =
        $hiring['department'] ??
        $offer['department'] ??
        'General';

    $designation_val =
        $offer['designation'] ??
        $hiring['designation'] ??
        '';

    $employee_code = generateEmployeeCode($conn, $department_val);
    $onboarding_no = generateOnboardingNo($conn);

    $onboarding_stmt = mysqli_prepare(
        $conn,
        "INSERT INTO onboarding
        (
            onboarding_no,
            candidate_id,
            offer_id,
            hiring_request_id,
            joining_date,
            reporting_time,
            reporting_to,
            reporting_to_name,
            department,
            designation,
            employee_code,
            status,
            created_by,
            created_by_name,
            created_at
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            'Pending',
            ?, ?, NOW()
        )"
    );

    if (!$onboarding_stmt) {
        throw new Exception("Failed to prepare onboarding record: " . mysqli_error($conn));
    }

    $candidate_id = (int)$offer['candidate_id'];
    $offer_id = (int)$offer['id'];
    $hiring_request_id = (int)$offer['hiring_request_id'];
    $reporting_to_db = $reporting_to ? (int)$reporting_to : null;
    $reporting_to_name_db = nullIfEmpty($reporting_to_name);

    mysqli_stmt_bind_param(
        $onboarding_stmt,
        "siiississssis",
        $onboarding_no,
        $candidate_id,
        $offer_id,
        $hiring_request_id,
        $joining_date,
        $reporting_time,
        $reporting_to_db,
        $reporting_to_name_db,
        $department_val,
        $designation_val,
        $employee_code,
        $current_employee_id,
        $current_employee_name
    );

    if (!mysqli_stmt_execute($onboarding_stmt)) {
        throw new Exception("Failed to create onboarding record: " . mysqli_stmt_error($onboarding_stmt));
    }

    $onboarding_id = mysqli_insert_id($conn);

    mysqli_stmt_close($onboarding_stmt);

    if (function_exists('logActivity')) {
        logActivity(
            $conn,
            'CREATE',
            'onboarding',
            "Onboarding created for candidate: {$candidate['first_name']} {$candidate['last_name']}",
            $onboarding_id,
            $onboarding_no
        );
    }

    return [
        'id' => $onboarding_id,
        'onboarding_no' => $onboarding_no
    ];
}

/* ---------------- OPTIONS ---------------- */

$status_options = [
    'pending' => 'Pending Approval',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'sent' => 'Sent to Candidate',
    'accepted' => 'Accepted',
    'all' => 'All Offers'
];

/* ---------------- MESSAGES ---------------- */

$message = '';
$messageType = '';
$validation_errors = [];

/* ---------------- POST ACTIONS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = trim((string)$_POST['action']);

    /* ---------- APPROVE SINGLE OFFER ---------- */

    if ($action === 'approve_offer') {

        $offer_id = (int)($_POST['offer_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        $joining_date = trim($_POST['joining_date'] ?? date('Y-m-d', strtotime('+15 days')));
        $reporting_time = trim($_POST['reporting_time'] ?? '09:00');
        $reporting_to = isset($_POST['reporting_to']) && $_POST['reporting_to'] !== ''
            ? (int)$_POST['reporting_to']
            : null;

        if ($offer_id <= 0) {
            $validation_errors[] = "Invalid offer selected";
        }

        if ($joining_date === '') {
            $validation_errors[] = "Joining date is required";
        }

        if ($reporting_time === '') {
            $validation_errors[] = "Reporting time is required";
        }

        if (empty($validation_errors)) {

            mysqli_begin_transaction($conn);

            try {

                $offer_stmt = mysqli_prepare(
                    $conn,
                    "SELECT *
                     FROM offers
                     WHERE id = ?
                     LIMIT 1"
                );

                if (!$offer_stmt) {
                    throw new Exception(mysqli_error($conn));
                }

                mysqli_stmt_bind_param($offer_stmt, "i", $offer_id);
                mysqli_stmt_execute($offer_stmt);

                $offer_res = mysqli_stmt_get_result($offer_stmt);
                $offer = mysqli_fetch_assoc($offer_res);

                mysqli_stmt_close($offer_stmt);

                if (!$offer) {
                    throw new Exception("Offer not found.");
                }

                if (($offer['status'] ?? '') !== 'Draft') {
                    throw new Exception("Only Draft offers can be approved.");
                }

                $candidate_stmt = mysqli_prepare(
                    $conn,
                    "SELECT *
                     FROM candidates
                     WHERE id = ?
                     LIMIT 1"
                );

                if (!$candidate_stmt) {
                    throw new Exception(mysqli_error($conn));
                }

                $candidate_id = (int)$offer['candidate_id'];

                mysqli_stmt_bind_param($candidate_stmt, "i", $candidate_id);
                mysqli_stmt_execute($candidate_stmt);

                $candidate_res = mysqli_stmt_get_result($candidate_stmt);
                $candidate = mysqli_fetch_assoc($candidate_res);

                mysqli_stmt_close($candidate_stmt);

                if (!$candidate) {
                    throw new Exception("Candidate not found.");
                }

                $hiring_stmt = mysqli_prepare(
                    $conn,
                    "SELECT *
                     FROM hiring_requests
                     WHERE id = ?
                     LIMIT 1"
                );

                if (!$hiring_stmt) {
                    throw new Exception(mysqli_error($conn));
                }

                $hiring_request_id = (int)$offer['hiring_request_id'];

                mysqli_stmt_bind_param($hiring_stmt, "i", $hiring_request_id);
                mysqli_stmt_execute($hiring_stmt);

                $hiring_res = mysqli_stmt_get_result($hiring_stmt);
                $hiring = mysqli_fetch_assoc($hiring_res);

                mysqli_stmt_close($hiring_stmt);

                if (!$hiring) {
                    throw new Exception("Hiring request not found.");
                }

                $reporting_to_name = null;

                if ($reporting_to) {
                    $rep_stmt = mysqli_prepare(
                        $conn,
                        "SELECT full_name
                         FROM employees
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($rep_stmt) {
                        mysqli_stmt_bind_param($rep_stmt, "i", $reporting_to);
                        mysqli_stmt_execute($rep_stmt);

                        $rep_res = mysqli_stmt_get_result($rep_stmt);
                        $reporter = mysqli_fetch_assoc($rep_res);

                        if ($reporter) {
                            $reporting_to_name = $reporter['full_name'];
                        }

                        mysqli_stmt_close($rep_stmt);
                    }
                }

                $onboarding = createOnboardingFromOffer(
                    $conn,
                    $offer,
                    $candidate,
                    $hiring,
                    $joining_date,
                    $reporting_time,
                    $reporting_to,
                    $reporting_to_name,
                    $current_employee_id,
                    $current_employee['full_name']
                );

                $approver_name = $current_employee['full_name'];
                $remarks_db = nullIfEmpty($remarks);

                $update_offer_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE offers
                     SET
                        status = 'Approved',
                        approved_by = ?,
                        approved_by_name = ?,
                        approved_at = NOW(),
                        approver_remarks = ?
                     WHERE id = ?
                     AND status = 'Draft'
                     LIMIT 1"
                );

                if (!$update_offer_stmt) {
                    throw new Exception(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $update_offer_stmt,
                    "issi",
                    $current_employee_id,
                    $approver_name,
                    $remarks_db,
                    $offer_id
                );

                if (!mysqli_stmt_execute($update_offer_stmt)) {
                    throw new Exception("Failed to update offer: " . mysqli_stmt_error($update_offer_stmt));
                }

                if (mysqli_stmt_affected_rows($update_offer_stmt) <= 0) {
                    throw new Exception("Offer already processed.");
                }

                mysqli_stmt_close($update_offer_stmt);

                $update_cand_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE candidates
                     SET status = 'Offered'
                     WHERE id = ?
                     LIMIT 1"
                );

                if ($update_cand_stmt) {
                    mysqli_stmt_bind_param($update_cand_stmt, "i", $candidate_id);
                    mysqli_stmt_execute($update_cand_stmt);
                    mysqli_stmt_close($update_cand_stmt);
                }

                if (function_exists('logActivity')) {
                    logActivity(
                        $conn,
                        'UPDATE',
                        'offer',
                        "Offer approved: {$offer['offer_no']}",
                        $offer_id,
                        $offer['offer_no']
                    );
                }

                mysqli_commit($conn);

                $message = "Offer approved successfully! Onboarding record created: {$onboarding['onboarding_no']}";
                $messageType = "success";

            } catch (Throwable $e) {

                mysqli_rollback($conn);

                $message = "Error: " . $e->getMessage();
                $messageType = "danger";
            }
        } else {
            $messageType = "warning";
        }
    }

    /* ---------- BULK APPROVE ---------- */

    elseif ($action === 'bulk_approve') {

        $offer_ids = [];

        if (!empty($_POST['offer_ids']) && is_array($_POST['offer_ids'])) {
            $offer_ids = array_values(array_unique(array_map('intval', $_POST['offer_ids'])));
        }

        $remarks = trim($_POST['bulk_remarks'] ?? '');
        $joining_date = trim($_POST['bulk_joining_date'] ?? date('Y-m-d', strtotime('+15 days')));
        $reporting_time = trim($_POST['bulk_reporting_time'] ?? '09:00');

        if (empty($offer_ids)) {
            $validation_errors[] = "No offers selected for bulk approval";
        }

        if ($joining_date === '') {
            $validation_errors[] = "Joining date is required";
        }

        if ($reporting_time === '') {
            $validation_errors[] = "Reporting time is required";
        }

        if (empty($validation_errors)) {

            mysqli_begin_transaction($conn);

            try {

                $success_count = 0;
                $skipped_count = 0;
                $created_onboarding = [];

                foreach ($offer_ids as $offer_id) {

                    $offer_stmt = mysqli_prepare(
                        $conn,
                        "SELECT *
                         FROM offers
                         WHERE id = ?
                         AND status = 'Draft'
                         LIMIT 1"
                    );

                    if (!$offer_stmt) {
                        throw new Exception(mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($offer_stmt, "i", $offer_id);
                    mysqli_stmt_execute($offer_stmt);

                    $offer_res = mysqli_stmt_get_result($offer_stmt);
                    $offer = mysqli_fetch_assoc($offer_res);

                    mysqli_stmt_close($offer_stmt);

                    if (!$offer) {
                        $skipped_count++;
                        continue;
                    }

                    $candidate_stmt = mysqli_prepare(
                        $conn,
                        "SELECT *
                         FROM candidates
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if (!$candidate_stmt) {
                        throw new Exception(mysqli_error($conn));
                    }

                    $candidate_id = (int)$offer['candidate_id'];

                    mysqli_stmt_bind_param($candidate_stmt, "i", $candidate_id);
                    mysqli_stmt_execute($candidate_stmt);

                    $candidate_res = mysqli_stmt_get_result($candidate_stmt);
                    $candidate = mysqli_fetch_assoc($candidate_res);

                    mysqli_stmt_close($candidate_stmt);

                    if (!$candidate) {
                        $skipped_count++;
                        continue;
                    }

                    $hiring_stmt = mysqli_prepare(
                        $conn,
                        "SELECT *
                         FROM hiring_requests
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if (!$hiring_stmt) {
                        throw new Exception(mysqli_error($conn));
                    }

                    $hiring_request_id = (int)$offer['hiring_request_id'];

                    mysqli_stmt_bind_param($hiring_stmt, "i", $hiring_request_id);
                    mysqli_stmt_execute($hiring_stmt);

                    $hiring_res = mysqli_stmt_get_result($hiring_stmt);
                    $hiring = mysqli_fetch_assoc($hiring_res);

                    mysqli_stmt_close($hiring_stmt);

                    if (!$hiring) {
                        $skipped_count++;
                        continue;
                    }

                    $onboarding = createOnboardingFromOffer(
                        $conn,
                        $offer,
                        $candidate,
                        $hiring,
                        $joining_date,
                        $reporting_time,
                        null,
                        null,
                        $current_employee_id,
                        $current_employee['full_name']
                    );

                    $created_onboarding[] = $onboarding['onboarding_no'];

                    $approver_name = $current_employee['full_name'];
                    $remarks_db = nullIfEmpty($remarks);

                    $update_offer_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE offers
                         SET
                            status = 'Approved',
                            approved_by = ?,
                            approved_by_name = ?,
                            approved_at = NOW(),
                            approver_remarks = ?
                         WHERE id = ?
                         AND status = 'Draft'
                         LIMIT 1"
                    );

                    if (!$update_offer_stmt) {
                        throw new Exception(mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param(
                        $update_offer_stmt,
                        "issi",
                        $current_employee_id,
                        $approver_name,
                        $remarks_db,
                        $offer_id
                    );

                    if (!mysqli_stmt_execute($update_offer_stmt)) {
                        throw new Exception("Failed to approve offer ID {$offer_id}");
                    }

                    mysqli_stmt_close($update_offer_stmt);

                    $update_cand_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE candidates
                         SET status = 'Offered'
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($update_cand_stmt) {
                        mysqli_stmt_bind_param($update_cand_stmt, "i", $candidate_id);
                        mysqli_stmt_execute($update_cand_stmt);
                        mysqli_stmt_close($update_cand_stmt);
                    }

                    if (function_exists('logActivity')) {
                        logActivity(
                            $conn,
                            'UPDATE',
                            'offer',
                            "Bulk approved offer: {$offer['offer_no']}",
                            $offer_id,
                            $offer['offer_no']
                        );
                    }

                    $success_count++;
                }

                mysqli_commit($conn);

                $message = "Successfully approved {$success_count} offer(s).";

                if ($skipped_count > 0) {
                    $message .= " Skipped {$skipped_count} invalid/processed offer(s).";
                }

                $messageType = "success";

            } catch (Throwable $e) {

                mysqli_rollback($conn);

                $message = "Error during bulk approval: " . $e->getMessage();
                $messageType = "danger";
            }

        } else {
            $messageType = "warning";
        }
    }

    /* ---------- REJECT OFFER ---------- */

    elseif ($action === 'reject_offer') {

        $offer_id = (int)($_POST['offer_id'] ?? 0);
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');

        if ($offer_id <= 0) {
            $validation_errors[] = "Invalid offer selected";
        }

        if ($rejection_reason === '') {
            $validation_errors[] = "Rejection reason is required";
        }

        if (empty($validation_errors)) {

            mysqli_begin_transaction($conn);

            try {

                $approver_name = $current_employee['full_name'];

                $update_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE offers
                     SET
                        status = 'Rejected',
                        approved_by = ?,
                        approved_by_name = ?,
                        approved_at = NOW(),
                        rejection_reason = ?
                     WHERE id = ?
                     AND status = 'Draft'
                     LIMIT 1"
                );

                if (!$update_stmt) {
                    throw new Exception(mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "issi",
                    $current_employee_id,
                    $approver_name,
                    $rejection_reason,
                    $offer_id
                );

                if (!mysqli_stmt_execute($update_stmt)) {
                    throw new Exception("Failed to reject offer: " . mysqli_stmt_error($update_stmt));
                }

                if (mysqli_stmt_affected_rows($update_stmt) <= 0) {
                    throw new Exception("Offer not found or already processed.");
                }

                mysqli_stmt_close($update_stmt);

                $offer_no = '';
                $candidate_id = 0;

                $offer_stmt = mysqli_prepare(
                    $conn,
                    "SELECT offer_no, candidate_id
                     FROM offers
                     WHERE id = ?
                     LIMIT 1"
                );

                if ($offer_stmt) {
                    mysqli_stmt_bind_param($offer_stmt, "i", $offer_id);
                    mysqli_stmt_execute($offer_stmt);

                    $offer_res = mysqli_stmt_get_result($offer_stmt);
                    $offer_data = mysqli_fetch_assoc($offer_res);

                    if ($offer_data) {
                        $offer_no = $offer_data['offer_no'];
                        $candidate_id = (int)$offer_data['candidate_id'];
                    }

                    mysqli_stmt_close($offer_stmt);
                }

                if ($candidate_id > 0) {

                    $update_cand_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE candidates
                         SET status = 'Rejected'
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($update_cand_stmt) {
                        mysqli_stmt_bind_param($update_cand_stmt, "i", $candidate_id);
                        mysqli_stmt_execute($update_cand_stmt);
                        mysqli_stmt_close($update_cand_stmt);
                    }
                }

                if (function_exists('logActivity')) {
                    logActivity(
                        $conn,
                        'REJECT',
                        'offer',
                        "Rejected offer: {$offer_no}",
                        $offer_id,
                        $offer_no,
                        null,
                        json_encode(['reason' => $rejection_reason])
                    );
                }

                mysqli_commit($conn);

                $message = "Offer rejected successfully.";
                $messageType = "success";

            } catch (Throwable $e) {

                mysqli_rollback($conn);

                $message = "Error: " . $e->getMessage();
                $messageType = "danger";
            }

        } else {
            $messageType = "warning";
        }
    }
}

/* ---------------- FILTERS ---------------- */

$status_filter = trim((string)($_GET['status'] ?? 'pending'));
$search = trim((string)($_GET['search'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));
$department_filter = trim((string)($_GET['department'] ?? ''));

if (!array_key_exists($status_filter, $status_options)) {
    $status_filter = 'pending';
}

/* ---------------- DEPARTMENTS ---------------- */

$departments = [];

$dept_result = mysqli_query(
    $conn,
    "SELECT DISTINCT department
     FROM hiring_requests
     WHERE department IS NOT NULL
     AND department <> ''
     ORDER BY department ASC"
);

if ($dept_result) {
    $departments = mysqli_fetch_all($dept_result, MYSQLI_ASSOC);
}

/* ---------------- EMPLOYEES FOR REPORTING ---------------- */

$employees = [];

$employees_result = mysqli_query(
    $conn,
    "SELECT id, full_name, designation
     FROM employees
     WHERE employee_status = 'active'
     ORDER BY full_name ASC"
);

if ($employees_result) {
    $employees = mysqli_fetch_all($employees_result, MYSQLI_ASSOC);
}

/* ---------------- OFFERS QUERY ---------------- */

$query = "
    SELECT
        o.*,
        c.id AS candidate_id,
        c.first_name,
        c.last_name,
        c.candidate_code,
        c.photo_path AS candidate_photo,
        c.email AS candidate_email,
        c.phone AS candidate_phone,
        c.total_experience,
        c.notice_period,
        c.expected_ctc,
        c.current_company,
        CONCAT(c.first_name, ' ', c.last_name) AS candidate_name,
        h.id AS hiring_request_id,
        h.request_no,
        h.position_title,
        h.department,
        h.designation AS hiring_designation,
        h.location AS job_location,
        h.experience_min,
        h.experience_max,
        h.salary_min,
        h.salary_max,
        h.requested_by,
        h.requested_by_name,
        req_emp.full_name AS requester_name,
        req_emp.designation AS requester_designation
    FROM offers o
    JOIN candidates c
    ON o.candidate_id = c.id
    JOIN hiring_requests h
    ON o.hiring_request_id = h.id
    LEFT JOIN employees req_emp
    ON h.requested_by = req_emp.id
    WHERE 1 = 1
";

$params = [];
$types = "";

if ($status_filter === 'pending') {
    $query .= " AND o.status = 'Draft'";
} elseif ($status_filter === 'approved') {
    $query .= " AND o.status = 'Approved'";
} elseif ($status_filter === 'rejected') {
    $query .= " AND o.status = 'Rejected'";
} elseif ($status_filter === 'sent') {
    $query .= " AND o.status = 'Sent'";
} elseif ($status_filter === 'accepted') {
    $query .= " AND o.status = 'Accepted'";
}

if ($department_filter !== '') {
    $query .= " AND h.department = ?";
    $params[] = $department_filter;
    $types .= "s";
}

if ($date_from !== '') {
    $query .= " AND DATE(o.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to !== '') {
    $query .= " AND DATE(o.created_at) <= ?";
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
            OR c.candidate_code LIKE ?
            OR o.offer_no LIKE ?
            OR h.position_title LIKE ?
            OR h.request_no LIKE ?
        )
    ";

    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
        $types .= "s";
    }
}

$query .= "
    ORDER BY o.created_at DESC
";

$offers = [];

$stmtOffers = mysqli_prepare($conn, $query);

if ($stmtOffers) {

    if (!empty($params)) {
        mysqli_stmt_bind_param(
            $stmtOffers,
            $types,
            ...$params
        );
    }

    mysqli_stmt_execute($stmtOffers);

    $resOffers = mysqli_stmt_get_result($stmtOffers);

    $offers = mysqli_fetch_all($resOffers, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtOffers);

} else {
    $message = "Error fetching offers: " . mysqli_error($conn);
    $messageType = "danger";
}

/* ---------------- STATS ---------------- */

$stats_query = "
    SELECT
        SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN status = 'Sent' THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN status = 'Accepted' THEN 1 ELSE 0 END) AS accepted_count,
        SUM(CASE WHEN status = 'Draft' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS new_this_week,
        AVG(CASE WHEN status = 'Draft' THEN ctc END) AS avg_offer_ctc
    FROM offers
";

$stats = [
    'pending_count' => 0,
    'approved_count' => 0,
    'rejected_count' => 0,
    'sent_count' => 0,
    'accepted_count' => 0,
    'new_this_week' => 0,
    'avg_offer_ctc' => null
];

$stats_result = mysqli_query($conn, $stats_query);

if ($stats_result) {
    $stats_row = mysqli_fetch_assoc($stats_result);

    if ($stats_row) {
        $stats = array_merge($stats, $stats_row);
    }
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

<title>Offer Approval - TEK-C Hiring</title>

<link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

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

.approval-wrapper{
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

.manage-btn{
    background:#2f80ed;
}

.manage-btn:hover{
    background:#2563eb;
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
.red{ background:#ef4444; }

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

.summary-card{
    background:linear-gradient(135deg,#2563eb,#7c3aed);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:14px;
    color:#fff;
    margin-bottom:14px;
}

.summary-stat{
    text-align:center;
}

.summary-stat-value{
    font-size:23px;
    font-weight:950;
    line-height:1;
}

.summary-stat-label{
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

.bulk-actions{
    background:#fff;
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:11px 13px;
    margin-bottom:14px;
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.bulk-title{
    font-size:12px;
    font-weight:900;
    color:#111827;
}

.bulk-count{
    font-size:11px;
    font-weight:800;
    color:#64748b;
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
}

.candidate-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
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

.offer-amount{
    color:#059669;
    font-size:12px;
    font-weight:950;
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
.dark-soft{ color:#111827; background:#e5e7eb; }

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

.action-group{
    display:flex;
    justify-content:flex-end;
    gap:5px;
    flex-wrap:wrap;
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
.approve-btn{ color:#15803d; background:#dcfce7; }
.reject-btn{ color:#b91c1c; background:#fee2e2; }

.select-cell{
    width:36px;
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
    margin-bottom:6px;
}

.required-label::after{
    content:" *";
    color:#ef4444;
}

.optional-badge{
    font-size:10px;
    color:#718096;
    font-weight:600;
    margin-left:5px;
}

.form-control,
.form-select{
    border:1px solid #e5e7eb;
    border-radius:10px;
    font-size:12px;
    font-weight:700;
}

.form-control:not(textarea),
.form-select{
    height:40px;
}

textarea.form-control{
    padding:10px 12px;
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

.modal-header{
    padding:14px 18px;
}

.modal-body{
    padding:16px 18px;
}

.modal-footer{
    padding:12px 18px;
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
        display:flex;
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

    .bulk-actions{
        align-items:flex-start;
        flex-direction:column;
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

<div class="container-fluid approval-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Offer Approval
</h1>

<p>
Review offers, approve employment terms, and create onboarding automatically
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="offers.php"
class="primary-btn manage-btn"
>
<i class="bi bi-file-text"></i>
Manage Offers
</a>

<button
class="primary-btn export-btn"
type="button"
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

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-clock"></i>
</div>

<div>

<div class="stat-label">
Pending
</div>

<div class="stat-value">
<?php echo (int)($stats['pending_count'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic green">
<i class="bi bi-check-circle"></i>
</div>

<div>

<div class="stat-label">
Approved
</div>

<div class="stat-value">
<?php echo (int)($stats['approved_count'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-envelope"></i>
</div>

<div>

<div class="stat-label">
Sent
</div>

<div class="stat-value">
<?php echo (int)($stats['sent_count'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic purple">
<i class="bi bi-check2-circle"></i>
</div>

<div>

<div class="stat-label">
Accepted
</div>

<div class="stat-value">
<?php echo (int)($stats['accepted_count'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic red">
<i class="bi bi-x-circle"></i>
</div>

<div>

<div class="stat-label">
Rejected
</div>

<div class="stat-value">
<?php echo (int)($stats['rejected_count'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-graph-up"></i>
</div>

<div>

<div class="stat-label">
New Week
</div>

<div class="stat-value">
<?php echo (int)($stats['new_this_week'] ?? 0); ?>
</div>

</div>

</div>

</div>

</div>

<?php if ((float)($stats['avg_offer_ctc'] ?? 0) > 0): ?>

<div class="summary-card">

<div class="row g-3">

<div class="col-12 col-md-4 summary-stat">

<div class="summary-stat-value">
<?php echo e(formatCurrency($stats['avg_offer_ctc'] ?? 0)); ?>
</div>

<div class="summary-stat-label">
Average Pending Offer CTC
</div>

</div>

<div class="col-12 col-md-4 summary-stat">

<div class="summary-stat-value">
<?php echo (int)($stats['pending_count'] ?? 0); ?>
</div>

<div class="summary-stat-label">
Awaiting Decision
</div>

</div>

<div class="col-12 col-md-4 summary-stat">

<div class="summary-stat-value">
<?php echo count($offers); ?>
</div>

<div class="summary-stat-label">
Current View Records
</div>

</div>

</div>

</div>

<?php endif; ?>

<!-- BULK ACTIONS -->

<?php if ($status_filter === 'pending' && !empty($offers)): ?>

<div class="bulk-actions">

<div class="form-check mb-0">

<input
class="form-check-input"
type="checkbox"
id="selectAll"
>

</div>

<div>

<div class="bulk-title">
Bulk Approval
</div>

<div class="bulk-count" id="selectedCount">
0 selected
</div>

</div>

<div class="ms-auto">

<button
type="button"
class="primary-btn approval-btn"
id="bulkApproveBtn"
disabled
onclick="openBulkApproveModal()"
>
<i class="bi bi-check-lg"></i>
Approve Selected
</button>

</div>

</div>

<?php endif; ?>

<!-- PANEL -->

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">

<?php if ($status_filter === 'pending'): ?>
Pending Approval
<?php elseif ($status_filter === 'approved'): ?>
Approved Offers
<?php elseif ($status_filter === 'rejected'): ?>
Rejected Offers
<?php elseif ($status_filter === 'sent'): ?>
Sent Offers
<?php elseif ($status_filter === 'accepted'): ?>
Accepted Offers
<?php else: ?>
All Offers
<?php endif; ?>

</h3>

<div class="panel-subtitle">
Compact responsive offer approval list
</div>

</div>

<span class="badge bg-secondary">
<?php echo count($offers); ?>
offers
</span>

</div>

<!-- FILTER BAR -->

<div class="filter-bar">

<div class="search-box">

<i class="bi bi-search"></i>

<input
type="text"
id="quickSearch"
placeholder="Search candidate, offer no, position..."
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

<?php foreach ($status_options as $key => $label): ?>

<option
value="<?php echo e($key); ?>"
<?php echo $status_filter === $key ? 'selected' : ''; ?>
>
<?php echo e($label); ?>
</option>

<?php endforeach; ?>

</select>

<select
name="department"
class="filter-select"
>

<option value="">
All Departments
</option>

<?php foreach ($departments as $dept): ?>

<option
value="<?php echo e($dept['department']); ?>"
<?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>
>
<?php echo e($dept['department']); ?>
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
href="offer-approval.php"
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
id="offersTable"
>

<thead>

<tr>

<?php if ($status_filter === 'pending'): ?>

<th class="select-cell">

<input
class="form-check-input"
type="checkbox"
id="selectAllHeader"
>

</th>

<?php endif; ?>

<th>Offer Details</th>
<th>Candidate</th>
<th>Position</th>
<th>CTC</th>
<th>Requested By</th>
<th>Status</th>
<th class="text-end">Actions</th>

</tr>

</thead>

<tbody>

<?php if (empty($offers)): ?>

<tr class="no-record-row">

<td colspan="<?php echo $status_filter === 'pending' ? 8 : 7; ?>">

<div class="empty-state">

<i class="bi bi-file-earmark-x me-1"></i>
No offers found.

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($offers as $offer): ?>

<?php

$candidateName = trim((string)($offer['candidate_name'] ?? ''));
$photoSrc = fileUrl($offer['candidate_photo'] ?? '');

[$statusLabel, $statusClass, $statusKey] =
    offerStatusBadge($offer['status'] ?? '');

?>

<tr data-status="<?php echo e($statusKey); ?>">

<?php if ($status_filter === 'pending'): ?>

<td class="select-cell" data-label="Select">

<?php if (($offer['status'] ?? '') === 'Draft'): ?>

<input
class="form-check-input offer-select"
type="checkbox"
value="<?php echo (int)$offer['id']; ?>"
>

<?php else: ?>

<span class="text-muted">
—
</span>

<?php endif; ?>

</td>

<?php endif; ?>

<td data-label="Offer Details">

<div class="table-primary-text">
<?php echo e($offer['offer_no'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<i class="bi bi-calendar me-1"></i>
<?php echo e(safeDate($offer['offer_date'] ?? '')); ?>
</div>

<?php if (!empty($offer['offer_valid_till'])): ?>

<div class="table-secondary-text">
<i class="bi bi-hourglass me-1"></i>
Valid till:
<?php echo e(safeDate($offer['offer_valid_till'])); ?>
</div>

<?php endif; ?>

<?php if (!empty($offer['created_at'])): ?>

<div class="table-secondary-text">
<i class="bi bi-clock me-1"></i>
Created:
<?php echo e(safeDate($offer['created_at'])); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Candidate">

<div class="table-title-cell">

<div class="candidate-avatar">

<?php if ($photoSrc !== ''): ?>

<img
src="<?php echo e($photoSrc); ?>"
alt="<?php echo e($candidateName); ?>"
onerror="this.style.display='none'; this.parentNode.innerHTML='<?php echo e(getInitials($candidateName)); ?>';"
>

<?php else: ?>

<?php echo e(getInitials($candidateName)); ?>

<?php endif; ?>

</div>

<div>

<div class="table-primary-text">

<a
href="view-candidate.php?id=<?php echo (int)$offer['candidate_id']; ?>"
class="text-decoration-none text-dark"
>
<?php echo e($candidateName); ?>
</a>

</div>

<div class="table-secondary-text">
<i class="bi bi-hash"></i>
<?php echo e($offer['candidate_code'] ?? ''); ?>
</div>

<?php if (!empty($offer['current_company'])): ?>

<div class="table-secondary-text">
<i class="bi bi-briefcase me-1"></i>
<?php echo e($offer['current_company']); ?>
</div>

<?php endif; ?>

</div>

</div>

</td>

<td data-label="Position">

<div class="table-primary-text">
<?php echo e($offer['position_title'] ?? 'N/A'); ?>
</div>

<div class="table-secondary-text">
<?php echo e($offer['request_no'] ?? ''); ?>
</div>

<?php if (!empty($offer['department'])): ?>

<span class="department-tag">
<i class="bi bi-building"></i>
<?php echo e($offer['department']); ?>
</span>

<?php endif; ?>

<?php if (!empty($offer['job_location'])): ?>

<div class="table-secondary-text mt-1">
<i class="bi bi-geo-alt me-1"></i>
<?php echo e($offer['job_location']); ?>
</div>

<?php endif; ?>

</td>

<td data-label="CTC">

<div class="offer-amount">
<?php echo e(formatCurrency($offer['ctc'] ?? null)); ?>
</div>

<?php if (!empty($offer['expected_ctc'])): ?>

<div class="table-secondary-text">
Expected:
<?php echo e(formatCurrency($offer['expected_ctc'])); ?>
</div>

<?php endif; ?>

<?php if (!empty($offer['total_experience'])): ?>

<div class="table-secondary-text">
Exp:
<?php echo number_format((float)$offer['total_experience'], 1); ?>
yrs
</div>

<?php endif; ?>

</td>

<td data-label="Requested By">

<div class="table-primary-text">
<?php echo e($offer['requester_name'] ?: '—'); ?>
</div>

<?php if (!empty($offer['requester_designation'])): ?>

<div class="table-secondary-text">
<?php echo e($offer['requester_designation']); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Status">

<span class="badge-pill <?php echo e($statusClass); ?>">

<span class="mini-dot"></span>

<?php echo e($statusLabel); ?>

</span>

<?php if (($offer['status'] ?? '') === 'Approved' && !empty($offer['approved_at'])): ?>

<div class="table-secondary-text mt-1">
<i class="bi bi-check-circle text-success me-1"></i>
<?php echo e(safeDate($offer['approved_at'])); ?>
</div>

<?php endif; ?>

<?php if (($offer['status'] ?? '') === 'Rejected' && !empty($offer['rejection_reason'])): ?>

<div class="table-secondary-text mt-1">
Reason:
<?php echo e($offer['rejection_reason']); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Actions">

<div class="action-group">

<a
href="view-offer.php?id=<?php echo (int)$offer['id']; ?>"
class="action-btn view-btn"
title="View Offer"
>
<i class="bi bi-eye"></i>
</a>

<?php if (($offer['status'] ?? '') === 'Draft'): ?>

<button
type="button"
class="action-btn approve-btn"
onclick="openApproveModal(<?php echo (int)$offer['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Approve"
>
<i class="bi bi-check-lg"></i>
</button>

<button
type="button"
class="action-btn reject-btn"
onclick="openRejectModal(<?php echo (int)$offer['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Reject"
>
<i class="bi bi-x-lg"></i>
</button>

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
<?php echo count($offers); ?>
offer records
</div>

</div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<!-- APPROVE MODAL -->

<div
class="modal fade"
id="approveModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog modal-lg modal-dialog-scrollable">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="approve_offer"
>

<input
type="hidden"
name="offer_id"
id="approve_offer_id"
>

<div class="modal-header">

<div>

<h5 class="modal-title mb-1">
Approve Offer & Create Onboarding
</h5>

<div class="text-muted small fw-semibold">
This will approve the offer and automatically create onboarding.
</div>

</div>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Approve offer for
<strong id="approve_candidate_name"></strong>
</p>

<div class="alert alert-info" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>
After approval, the candidate moves into onboarding with Pending status.

</div>

<div class="row g-3">

<div class="col-md-6">

<label class="form-label required-label">
Joining Date
</label>

<input
type="date"
name="joining_date"
class="form-control"
value="<?php echo date('Y-m-d', strtotime('+15 days')); ?>"
required
>

</div>

<div class="col-md-6">

<label class="form-label">
Reporting Time
</label>

<input
type="time"
name="reporting_time"
class="form-control"
value="09:00"
>

</div>

<div class="col-12">

<label class="form-label">
Reporting To
<span class="optional-badge">(Optional)</span>
</label>

<select
name="reporting_to"
class="form-select"
>

<option value="">
Select Reporting Manager
</option>

<?php foreach ($employees as $emp): ?>

<option value="<?php echo (int)$emp['id']; ?>">
<?php echo e($emp['full_name']); ?>
<?php if (!empty($emp['designation'])): ?>
(<?php echo e($emp['designation']); ?>)
<?php endif; ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-12">

<label class="form-label">
Remarks
<span class="optional-badge">(Optional)</span>
</label>

<textarea
name="remarks"
class="form-control"
rows="2"
placeholder="Add any approval notes..."
></textarea>

</div>

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
class="btn btn-success"
>
<i class="bi bi-check-lg me-1"></i>
Approve & Create Onboarding
</button>

</div>

</form>

</div>

</div>

</div>

<!-- REJECT MODAL -->

<div
class="modal fade"
id="rejectModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="reject_offer"
>

<input
type="hidden"
name="offer_id"
id="reject_offer_id"
>

<div class="modal-header">

<h5 class="modal-title">
Reject Offer
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Reject offer for
<strong id="reject_candidate_name"></strong>?
</p>

<div class="mb-3">

<label class="form-label required-label">
Reason for Rejection
</label>

<textarea
name="rejection_reason"
class="form-control"
rows="3"
required
placeholder="Please provide reason for rejection..."
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
Reject Offer
</button>

</div>

</form>

</div>

</div>

</div>

<!-- BULK APPROVE MODAL -->

<div
class="modal fade"
id="bulkApproveModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog modal-lg modal-dialog-scrollable">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="bulk_approve"
>

<div id="bulkOfferIds"></div>

<div class="modal-header">

<div>

<h5 class="modal-title mb-1">
Bulk Approve Offers
</h5>

<div class="text-muted small fw-semibold">
Onboarding records will be created for all selected offers.
</div>

</div>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Approve
<strong id="bulkCount">0</strong>
selected offer(s)?
</p>

<div class="alert alert-info" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>
Each selected draft offer will get an onboarding record automatically.

</div>

<div class="row g-3">

<div class="col-md-6">

<label class="form-label required-label">
Joining Date
</label>

<input
type="date"
name="bulk_joining_date"
class="form-control"
value="<?php echo date('Y-m-d', strtotime('+15 days')); ?>"
required
>

</div>

<div class="col-md-6">

<label class="form-label">
Reporting Time
</label>

<input
type="time"
name="bulk_reporting_time"
class="form-control"
value="09:00"
>

</div>

<div class="col-12">

<label class="form-label">
Common Remarks
<span class="optional-badge">(Optional)</span>
</label>

<textarea
name="bulk_remarks"
class="form-control"
rows="2"
placeholder="Add notes for all selected offers..."
></textarea>

</div>

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
class="btn btn-success"
>
Approve Selected
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
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<div class="modal-header">

<h5 class="modal-title">
Export Offer Approval
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

</select>

</div>

<div class="alert alert-info mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>
This exports the currently displayed rows.

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
onclick="exportToCSV()"
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
        document.querySelectorAll('#offersTable tbody tr:not(.no-record-row)');

    const recordInfo =
        document.getElementById('recordInfo');

    const selectAll =
        document.getElementById('selectAll');

    const selectAllHeader =
        document.getElementById('selectAllHeader');

    const offerCheckboxes =
        document.querySelectorAll('.offer-select');

    const selectedCount =
        document.getElementById('selectedCount');

    const bulkApproveBtn =
        document.getElementById('bulkApproveBtn');

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
                'Showing ' + visibleCount + ' offer records';
        }

        if (serverSearch) {
            serverSearch.value =
                searchValue;
        }
    }

    if (quickSearch) {
        quickSearch.addEventListener('input', filterRows);
    }

    function updateSelectedCount(){

        let count =
            0;

        offerCheckboxes.forEach(function(cb){
            if (cb.checked) {
                count++;
            }
        });

        if (selectedCount) {
            selectedCount.textContent =
                count + ' selected';
        }

        if (bulkApproveBtn) {
            bulkApproveBtn.disabled =
                count === 0;
        }

        const allChecked =
            offerCheckboxes.length > 0 &&
            count === offerCheckboxes.length;

        if (selectAll) {
            selectAll.checked =
                allChecked;
        }

        if (selectAllHeader) {
            selectAllHeader.checked =
                allChecked;
        }
    }

    function setAllChecked(value){

        offerCheckboxes.forEach(function(cb){
            cb.checked = value;
        });

        updateSelectedCount();
    }

    if (selectAll) {
        selectAll.addEventListener('change', function(){
            setAllChecked(this.checked);
        });
    }

    if (selectAllHeader) {
        selectAllHeader.addEventListener('change', function(){
            setAllChecked(this.checked);
        });
    }

    offerCheckboxes.forEach(function(cb){
        cb.addEventListener('change', updateSelectedCount);
    });

    updateSelectedCount();
});

function openApproveModal(id, candidateName){

    document.getElementById('approve_offer_id').value =
        id;

    document.getElementById('approve_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('approveModal')
    ).show();
}

function openRejectModal(id, candidateName){

    document.getElementById('reject_offer_id').value =
        id;

    document.getElementById('reject_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('rejectModal')
    ).show();
}

function openBulkApproveModal(){

    const selected =
        document.querySelectorAll('.offer-select:checked');

    if (!selected.length) {
        alert('Please select at least one offer to approve.');
        return;
    }

    let html =
        '';

    selected.forEach(function(cb){
        html += '<input type="hidden" name="offer_ids[]" value="' + cb.value + '">';
    });

    document.getElementById('bulkOfferIds').innerHTML =
        html;

    document.getElementById('bulkCount').textContent =
        selected.length;

    new bootstrap.Modal(
        document.getElementById('bulkApproveModal')
    ).show();
}

function exportToCSV(){

    const rows =
        document.querySelectorAll('#offersTable tbody tr:not(.no-record-row)');

    const csv =
        [];

    const isPending =
        <?php echo $status_filter === 'pending' ? 'true' : 'false'; ?>;

    const headers =
        isPending
        ? ['Offer Details', 'Candidate', 'Position', 'CTC', 'Requested By', 'Status']
        : ['Offer Details', 'Candidate', 'Position', 'CTC', 'Requested By', 'Status'];

    csv.push(headers.join(','));

    rows.forEach(function(row){

        if (row.style.display === 'none') {
            return;
        }

        const cells =
            row.querySelectorAll('td');

        if (!cells.length) {
            return;
        }

        const startIndex =
            isPending
            ? 1
            : 0;

        const rowData = [
            cells[startIndex]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[startIndex + 1]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[startIndex + 2]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[startIndex + 3]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[startIndex + 4]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[startIndex + 5]?.innerText.replace(/\s+/g, ' ').trim() || ''
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
        'offer_approval_<?php echo date('Y-m-d'); ?>.csv';

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