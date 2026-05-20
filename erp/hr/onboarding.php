<?php
// onboarding.php
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

$current_designation = strtolower(trim((string)($current_employee['designation'] ?? '')));
$current_department = strtolower(trim((string)($current_employee['department'] ?? '')));

$isHr =
    $current_designation === 'hr' ||
    $current_department === 'hr';

$isManager =
    in_array(
        $current_designation,
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
    $current_designation === 'administrator' ||
    $current_designation === 'admin' ||
    $current_designation === 'director';

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
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

function safeTime($time, $dash = '—') {

    $time = trim((string)$time);

    if ($time === '') {
        return $dash;
    }

    $ts = strtotime($time);

    return $ts ? date('h:i A', $ts) : e($time);
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

function onboardingStatusBadge($status) {

    $status = trim((string)$status);

    $map = [
        'Pending' => ['Pending', 'warning', 'pending'],
        'In Progress' => ['In Progress', 'info', 'in progress'],
        'Completed' => ['Completed', 'ontrack', 'completed'],
        'Cancelled' => ['Cancelled', 'danger', 'cancelled']
    ];

    return $map[$status] ?? [$status ?: 'Unknown', 'muted', strtolower($status ?: 'unknown')];
}

function progressClass($completed, $total) {

    $percentage = $total > 0 ? ($completed / $total * 100) : 0;

    if ($percentage >= 75) {
        return 'progress-success';
    }

    if ($percentage >= 50) {
        return 'progress-info';
    }

    if ($percentage >= 25) {
        return 'progress-warning';
    }

    return 'progress-muted';
}

function generateOnboardingNumber($conn) {

    $year = date('Y');
    $month = date('m');
    $prefix = "ONB-{$year}{$month}-";
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

function generateEmployeeCodeByDepartment($conn, $department) {

    $department = trim((string)$department);

    $dept_code = '';

    switch (strtoupper($department)) {
        case 'PM':
            $dept_code = 'PM';
            break;

        case 'CM':
            $dept_code = 'CM';
            break;

        case 'IFM':
            $dept_code = 'IF';
            break;

        case 'QS':
            $dept_code = 'QS';
            break;

        case 'HR':
            $dept_code = 'HR';
            break;

        case 'ACCOUNTS':
            $dept_code = 'AC';
            break;

        default:
            $clean = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $department), 0, 2));
            $dept_code = $clean !== '' ? $clean : 'EM';
            break;
    }

    $like = $dept_code . '%';
    $count = 0;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total_count
         FROM employees
         WHERE employee_code LIKE ?"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $like);
        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);

        $count = (int)($row['total_count'] ?? 0);

        mysqli_stmt_close($stmt);
    }

    return $dept_code . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

function ensureUniqueUsername($conn, $baseUsername) {

    $baseUsername = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$baseUsername));

    if ($baseUsername === '') {
        $baseUsername = 'employee';
    }

    $username = $baseUsername;
    $counter = 1;

    while (true) {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id
             FROM employees
             WHERE username = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return $username . time();
        }

        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);

        $exists = mysqli_stmt_num_rows($stmt) > 0;

        mysqli_stmt_close($stmt);

        if (!$exists) {
            return $username;
        }

        $username = $baseUsername . $counter;
        $counter++;
    }
}

/* ---------------- OPTIONS ---------------- */

$status_options = [
    'all' => 'All Status',
    'Pending' => 'Pending',
    'In Progress' => 'In Progress',
    'Completed' => 'Completed',
    'Cancelled' => 'Cancelled'
];

/* ---------------- MESSAGES ---------------- */

$message = '';
$messageType = '';
$validation_errors = [];

/* ---------------- POST ACTIONS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = trim((string)$_POST['action']);

    /* ---------- CREATE ONBOARDING ---------- */

    if ($action === 'create_onboarding') {

        if (!$isHr && !$isAdmin) {

            $message = "Only HR/Admin can start onboarding.";
            $messageType = "danger";

        } else {

            $candidate_id = (int)($_POST['candidate_id'] ?? 0);
            $offer_id = (int)($_POST['offer_id'] ?? 0);
            $hiring_request_id = (int)($_POST['hiring_request_id'] ?? 0);
            $joining_date = trim($_POST['joining_date'] ?? '');
            $reporting_time = trim($_POST['reporting_time'] ?? '09:00');
            $reporting_to = isset($_POST['reporting_to']) && $_POST['reporting_to'] !== ''
                ? (int)$_POST['reporting_to']
                : null;
            $department = trim($_POST['department'] ?? '');
            $designation = trim($_POST['designation'] ?? '');

            if ($candidate_id <= 0) {
                $validation_errors[] = "Candidate is required";
            }

            if ($offer_id <= 0) {
                $validation_errors[] = "Offer is required";
            }

            if ($hiring_request_id <= 0) {
                $validation_errors[] = "Hiring request is required";
            }

            if ($joining_date === '') {
                $validation_errors[] = "Joining date is required";
            }

            if ($department === '') {
                $validation_errors[] = "Department is required";
            }

            if ($designation === '') {
                $validation_errors[] = "Designation is required";
            }

            if (empty($validation_errors)) {

                $reporting_to_name = null;

                if ($reporting_to) {

                    $rm_stmt = mysqli_prepare(
                        $conn,
                        "SELECT full_name
                         FROM employees
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($rm_stmt) {
                        mysqli_stmt_bind_param($rm_stmt, "i", $reporting_to);
                        mysqli_stmt_execute($rm_stmt);

                        $rm_res = mysqli_stmt_get_result($rm_stmt);
                        $rm_row = mysqli_fetch_assoc($rm_res);

                        $reporting_to_name = $rm_row['full_name'] ?? null;

                        mysqli_stmt_close($rm_stmt);
                    }
                }

                mysqli_begin_transaction($conn);

                try {

                    $onboarding_no = generateOnboardingNumber($conn);

                    $insert_stmt = mysqli_prepare(
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
                            status,
                            created_by,
                            created_by_name,
                            created_at
                        )
                        VALUES
                        (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            'Pending',
                            ?, ?, NOW()
                        )"
                    );

                    if (!$insert_stmt) {
                        throw new Exception(mysqli_error($conn));
                    }

                    $reporting_to_db = $reporting_to ?: null;
                    $reporting_to_name_db = nullIfEmpty($reporting_to_name);
                    $created_by_name = $current_employee['full_name'];

                    mysqli_stmt_bind_param(
                        $insert_stmt,
                        "siiississsis",
                        $onboarding_no,
                        $candidate_id,
                        $offer_id,
                        $hiring_request_id,
                        $joining_date,
                        $reporting_time,
                        $reporting_to_db,
                        $reporting_to_name_db,
                        $department,
                        $designation,
                        $current_employee_id,
                        $created_by_name
                    );

                    if (!mysqli_stmt_execute($insert_stmt)) {
                        throw new Exception("Failed to create onboarding: " . mysqli_stmt_error($insert_stmt));
                    }

                    $onboarding_id = mysqli_insert_id($conn);

                    mysqli_stmt_close($insert_stmt);

                    $candidate_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE candidates
                         SET status = 'Onboarding'
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($candidate_stmt) {
                        mysqli_stmt_bind_param($candidate_stmt, "i", $candidate_id);
                        mysqli_stmt_execute($candidate_stmt);
                        mysqli_stmt_close($candidate_stmt);
                    }

                    $offer_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE offers
                         SET status = 'Accepted'
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($offer_stmt) {
                        mysqli_stmt_bind_param($offer_stmt, "i", $offer_id);
                        mysqli_stmt_execute($offer_stmt);
                        mysqli_stmt_close($offer_stmt);
                    }

                    if (function_exists('logActivity')) {
                        logActivity(
                            $conn,
                            'CREATE',
                            'onboarding',
                            "Created onboarding: {$onboarding_no}",
                            $onboarding_id,
                            $onboarding_no,
                            null,
                            json_encode($_POST)
                        );
                    }

                    mysqli_commit($conn);

                    $message = "Onboarding created successfully! Onboarding Number: {$onboarding_no}";
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

    /* ---------- UPDATE STATUS ---------- */

    elseif ($action === 'update_status') {

        $onboarding_id = (int)($_POST['onboarding_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($onboarding_id <= 0) {
            $validation_errors[] = "Invalid onboarding selected";
        }

        if (!array_key_exists($status, $status_options) || $status === 'all') {
            $validation_errors[] = "Invalid onboarding status selected";
        }

        if (empty($validation_errors)) {

            if ($status === 'Completed') {

                $update_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE onboarding
                     SET
                        status = ?,
                        completed_at = CURDATE(),
                        completed_by = ?,
                        remarks = CASE
                            WHEN ? <> '' THEN CONCAT(IFNULL(remarks, ''), '\n[', NOW(), '] ', ?)
                            ELSE remarks
                        END
                     WHERE id = ?
                     LIMIT 1"
                );

                if ($update_stmt) {
                    mysqli_stmt_bind_param(
                        $update_stmt,
                        "sissi",
                        $status,
                        $current_employee_id,
                        $remarks,
                        $remarks,
                        $onboarding_id
                    );
                }

            } else {

                $update_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE onboarding
                     SET
                        status = ?,
                        remarks = CASE
                            WHEN ? <> '' THEN CONCAT(IFNULL(remarks, ''), '\n[', NOW(), '] ', ?)
                            ELSE remarks
                        END
                     WHERE id = ?
                     LIMIT 1"
                );

                if ($update_stmt) {
                    mysqli_stmt_bind_param(
                        $update_stmt,
                        "sssi",
                        $status,
                        $remarks,
                        $remarks,
                        $onboarding_id
                    );
                }
            }

            if ($update_stmt && mysqli_stmt_execute($update_stmt)) {

                if (function_exists('logActivity')) {
                    logActivity(
                        $conn,
                        'UPDATE',
                        'onboarding',
                        "Updated onboarding status to {$status}",
                        $onboarding_id,
                        null,
                        null,
                        json_encode(['status' => $status, 'remarks' => $remarks])
                    );
                }

                $message = "Onboarding status updated successfully!";
                $messageType = "success";

            } else {
                $message = "Error updating status: " . mysqli_error($conn);
                $messageType = "danger";
            }

            if ($update_stmt) {
                mysqli_stmt_close($update_stmt);
            }

        } else {
            $messageType = "warning";
        }
    }

    /* ---------- UPDATE DOCUMENTS ---------- */

    elseif ($action === 'update_documents') {

        $onboarding_id = (int)($_POST['onboarding_id'] ?? 0);

        if ($onboarding_id <= 0) {
            $validation_errors[] = "Invalid onboarding selected";
        }

        if (empty($validation_errors)) {

            $documents = [];

            $doc_stmt = mysqli_prepare(
                $conn,
                "SELECT documents_json
                 FROM onboarding
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($doc_stmt) {
                mysqli_stmt_bind_param($doc_stmt, "i", $onboarding_id);
                mysqli_stmt_execute($doc_stmt);

                $doc_res = mysqli_stmt_get_result($doc_stmt);
                $doc_row = mysqli_fetch_assoc($doc_res);

                if (!empty($doc_row['documents_json'])) {
                    $decoded = json_decode($doc_row['documents_json'], true);
                    if (is_array($decoded)) {
                        $documents = $decoded;
                    }
                }

                mysqli_stmt_close($doc_stmt);
            }

            $upload_dir = '../uploads/onboarding/documents/';

            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0777, true);
            }

            $document_types = [
                'aadhar',
                'pan',
                'degree',
                'experience',
                'photo',
                'offer_acceptance',
                'bank',
                'other'
            ];

            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];

            foreach ($document_types as $doc_type) {

                $field = 'doc_' . $doc_type;

                if (
                    isset($_FILES[$field]) &&
                    isset($_FILES[$field]['error']) &&
                    $_FILES[$field]['error'] === UPLOAD_ERR_OK
                ) {

                    $file = $_FILES[$field];
                    $file_ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));

                    if (!in_array($file_ext, $allowed, true)) {
                        continue;
                    }

                    if ((int)$file['size'] > (5 * 1024 * 1024)) {
                        continue;
                    }

                    $file_name =
                        'doc_' .
                        $onboarding_id .
                        '_' .
                        $doc_type .
                        '_' .
                        time() .
                        '_' .
                        bin2hex(random_bytes(4)) .
                        '.' .
                        $file_ext;

                    $file_path = $upload_dir . $file_name;

                    if (move_uploaded_file($file['tmp_name'], $file_path)) {

                        $documents[$doc_type] = [
                            'file' => 'uploads/onboarding/documents/' . $file_name,
                            'name' => $file['name'],
                            'uploaded_at' => date('Y-m-d H:i:s'),
                            'uploaded_by' => $current_employee_id
                        ];
                    }
                }
            }

            $required_docs = ['aadhar', 'pan', 'offer_acceptance'];
            $all_submitted = true;

            foreach ($required_docs as $req) {
                if (!isset($documents[$req])) {
                    $all_submitted = false;
                    break;
                }
            }

            $documents_json = json_encode($documents);
            $doc_submitted = $all_submitted ? 1 : 0;

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE onboarding
                 SET
                    documents_json = ?,
                    documents_submitted = ?
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "sii",
                    $documents_json,
                    $doc_submitted,
                    $onboarding_id
                );

                if (mysqli_stmt_execute($update_stmt)) {

                    if (function_exists('logActivity')) {
                        logActivity(
                            $conn,
                            'UPDATE',
                            'onboarding',
                            "Updated documents for onboarding ID: {$onboarding_id}",
                            $onboarding_id,
                            null,
                            null,
                            null
                        );
                    }

                    $message = "Documents updated successfully!";
                    $messageType = "success";

                } else {
                    $message = "Error updating documents: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }

        } else {
            $messageType = "warning";
        }
    }

    /* ---------- UPDATE CHECKLIST ---------- */

    elseif ($action === 'update_checklist') {

        $onboarding_id = (int)($_POST['onboarding_id'] ?? 0);

        if ($onboarding_id <= 0) {
            $validation_errors[] = "Invalid onboarding selected";
        }

        if (empty($validation_errors)) {

            $checklist_items = [
                'id_card_issued' => isset($_POST['id_card_issued']) ? 1 : 0,
                'email_created' => isset($_POST['email_created']) ? 1 : 0,
                'system_access_given' => isset($_POST['system_access_given']) ? 1 : 0,
                'biometric_enrolled' => isset($_POST['biometric_enrolled']) ? 1 : 0,
                'orientation_completed' => isset($_POST['orientation_completed']) ? 1 : 0,
                'training_completed' => isset($_POST['training_completed']) ? 1 : 0,
                'welcome_kit_issued' => isset($_POST['welcome_kit_issued']) ? 1 : 0
            ];

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE onboarding
                 SET
                    id_card_issued = ?,
                    email_created = ?,
                    system_access_given = ?,
                    biometric_enrolled = ?,
                    orientation_completed = ?,
                    training_completed = ?,
                    welcome_kit_issued = ?
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "iiiiiiii",
                    $checklist_items['id_card_issued'],
                    $checklist_items['email_created'],
                    $checklist_items['system_access_given'],
                    $checklist_items['biometric_enrolled'],
                    $checklist_items['orientation_completed'],
                    $checklist_items['training_completed'],
                    $checklist_items['welcome_kit_issued'],
                    $onboarding_id
                );

                if (mysqli_stmt_execute($update_stmt)) {

                    if (function_exists('logActivity')) {
                        logActivity(
                            $conn,
                            'UPDATE',
                            'onboarding',
                            "Updated checklist for onboarding ID: {$onboarding_id}",
                            $onboarding_id,
                            null,
                            null,
                            json_encode($checklist_items)
                        );
                    }

                    $message = "Checklist updated successfully!";
                    $messageType = "success";

                } else {
                    $message = "Error updating checklist: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }

        } else {
            $messageType = "warning";
        }
    }

    /* ---------- GENERATE EMPLOYEE CODE ---------- */

    elseif ($action === 'generate_employee_code') {

        $onboarding_id = (int)($_POST['onboarding_id'] ?? 0);

        if ($onboarding_id <= 0) {
            $validation_errors[] = "Invalid onboarding selected";
        }

        if (empty($validation_errors)) {

            $dept = '';

            $dept_stmt = mysqli_prepare(
                $conn,
                "SELECT department
                 FROM onboarding
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($dept_stmt) {
                mysqli_stmt_bind_param($dept_stmt, "i", $onboarding_id);
                mysqli_stmt_execute($dept_stmt);

                $dept_res = mysqli_stmt_get_result($dept_stmt);
                $dept_row = mysqli_fetch_assoc($dept_res);

                $dept = $dept_row['department'] ?? '';

                mysqli_stmt_close($dept_stmt);
            }

            $employee_code = generateEmployeeCodeByDepartment($conn, $dept);

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE onboarding
                 SET employee_code = ?
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param($update_stmt, "si", $employee_code, $onboarding_id);

                if (mysqli_stmt_execute($update_stmt)) {
                    $message = "Employee code generated: {$employee_code}";
                    $messageType = "success";
                } else {
                    $message = "Error generating employee code: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }

        } else {
            $messageType = "warning";
        }
    }

    /* ---------- COMPLETE ONBOARDING ---------- */

    elseif ($action === 'complete_onboarding') {

        $onboarding_id = (int)($_POST['onboarding_id'] ?? 0);

        if ($onboarding_id <= 0) {
            $validation_errors[] = "Invalid onboarding selected";
        }

        if (empty($validation_errors)) {

            $details_stmt = mysqli_prepare(
                $conn,
                "SELECT
                    o.*,
                    c.id AS candidate_id,
                    c.first_name,
                    c.last_name,
                    c.email,
                    c.phone AS mobile_number,
                    c.current_location,
                    c.total_experience,
                    c.current_company,
                    c.resume_path,
                    c.photo_path AS candidate_photo,
                    c.source,
                    c.status AS candidate_status,
                    h.id AS hiring_request_id,
                    h.department AS hiring_department,
                    h.designation AS hiring_designation,
                    h.requested_by AS reporting_manager_id,
                    h.location,
                    offr.id AS offer_id,
                    offr.offer_no,
                    offr.ctc AS offer_ctc,
                    offr.basic_salary,
                    offr.hra,
                    offr.conveyance,
                    offr.medical,
                    offr.special_allowance,
                    offr.bonus,
                    offr.other_benefits,
                    offr.offer_document
                 FROM onboarding o
                 JOIN candidates c
                 ON o.candidate_id = c.id
                 JOIN hiring_requests h
                 ON o.hiring_request_id = h.id
                 LEFT JOIN offers offr
                 ON o.offer_id = offr.id
                 WHERE o.id = ?
                 LIMIT 1"
            );

            if (!$details_stmt) {
                $message = "Database error: " . mysqli_error($conn);
                $messageType = "danger";
            } else {

                mysqli_stmt_bind_param($details_stmt, "i", $onboarding_id);
                mysqli_stmt_execute($details_stmt);

                $details_res = mysqli_stmt_get_result($details_stmt);
                $details = mysqli_fetch_assoc($details_res);

                mysqli_stmt_close($details_stmt);

                if (!$details) {

                    $message = "Onboarding record not found.";
                    $messageType = "danger";

                } else {

                    mysqli_begin_transaction($conn);

                    try {

                        $documents = [];

                        if (!empty($details['documents_json'])) {
                            $decoded = json_decode($details['documents_json'], true);
                            if (is_array($decoded)) {
                                $documents = $decoded;
                            }
                        }

                        $photo_path = '';

                        if (!empty($documents['photo']['file'])) {
                            $photo_path = $documents['photo']['file'];
                        } elseif (!empty($details['candidate_photo'])) {
                            $photo_path = $details['candidate_photo'];
                        }

                        $passbook_photo_path = '';

                        if (!empty($documents['bank']['file'])) {
                            $passbook_photo_path = $documents['bank']['file'];
                        }

                        if (!empty($details['email'])) {
                            $email_parts = explode('@', $details['email']);
                            $username_base = $email_parts[0] ?? '';
                        } else {
                            $username_base = $details['first_name'] . $details['last_name'];
                        }

                        $username = ensureUniqueUsername($conn, $username_base);

                        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
                        $password = '';

                        for ($i = 0; $i < 12; $i++) {
                            $password .= $chars[random_int(0, strlen($chars) - 1)];
                        }

                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                        $reporting_to = null;

                        if (!empty($details['reporting_to'])) {
                            $reporting_to = (int)$details['reporting_to'];
                        } elseif (!empty($details['reporting_manager_id'])) {
                            $reporting_to = (int)$details['reporting_manager_id'];
                        }

                        $reporting_manager_name = null;

                        if ($reporting_to) {

                            $rm_stmt = mysqli_prepare(
                                $conn,
                                "SELECT full_name
                                 FROM employees
                                 WHERE id = ?
                                 LIMIT 1"
                            );

                            if ($rm_stmt) {
                                mysqli_stmt_bind_param($rm_stmt, "i", $reporting_to);
                                mysqli_stmt_execute($rm_stmt);

                                $rm_res = mysqli_stmt_get_result($rm_stmt);
                                $rm_row = mysqli_fetch_assoc($rm_res);

                                $reporting_manager_name = $rm_row['full_name'] ?? null;

                                mysqli_stmt_close($rm_stmt);
                            }

                        } elseif (!empty($details['reporting_to_name'])) {
                            $reporting_manager_name = $details['reporting_to_name'];
                        }

                        $full_name = trim($details['first_name'] . ' ' . $details['last_name']);

                        $employee_code = trim((string)($details['employee_code'] ?? ''));

                        if ($employee_code === '') {
                            $employee_code = generateEmployeeCodeByDepartment(
                                $conn,
                                $details['department'] ?: $details['hiring_department']
                            );
                        }

                        $work_location =
                            !empty($details['current_location'])
                            ? $details['current_location']
                            : (!empty($details['location']) ? $details['location'] : null);

                        $site_name = $work_location;

                        $date_of_joining = $details['joining_date'] ?? date('Y-m-d');

                        $department_val =
                            !empty($details['department'])
                            ? $details['department']
                            : ($details['hiring_department'] ?? '');

                        $designation_val =
                            !empty($details['designation'])
                            ? $details['designation']
                            : ($details['hiring_designation'] ?? '');

                        $employee_status = 'active';

                        $emp_insert = mysqli_prepare(
                            $conn,
                            "INSERT INTO employees
                            (
                                full_name,
                                employee_code,
                                photo,
                                mobile_number,
                                email,
                                date_of_joining,
                                department,
                                designation,
                                reporting_manager,
                                reporting_to,
                                work_location,
                                site_name,
                                employee_status,
                                username,
                                password,
                                passbook_photo,
                                created_at
                            )
                            VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                        );

                        if (!$emp_insert) {
                            throw new Exception(mysqli_error($conn));
                        }

                        $mobile_number_val = $details['mobile_number'] ?? null;
                        $email_val = $details['email'] ?? null;
                        $reporting_to_val = $reporting_to ?: null;

                        mysqli_stmt_bind_param(
                            $emp_insert,
                            "sssssssssissssss",
                            $full_name,
                            $employee_code,
                            $photo_path,
                            $mobile_number_val,
                            $email_val,
                            $date_of_joining,
                            $department_val,
                            $designation_val,
                            $reporting_manager_name,
                            $reporting_to_val,
                            $work_location,
                            $site_name,
                            $employee_status,
                            $username,
                            $hashed_password,
                            $passbook_photo_path
                        );

                        if (!mysqli_stmt_execute($emp_insert)) {
                            throw new Exception("Failed to create employee record: " . mysqli_stmt_error($emp_insert));
                        }

                        $employee_id = mysqli_insert_id($conn);

                        mysqli_stmt_close($emp_insert);

                        $update_onboarding = mysqli_prepare(
                            $conn,
                            "UPDATE onboarding
                             SET
                                status = 'Completed',
                                completed_at = CURDATE(),
                                completed_by = ?,
                                employee_code = ?
                             WHERE id = ?
                             LIMIT 1"
                        );

                        if ($update_onboarding) {
                            mysqli_stmt_bind_param($update_onboarding, "isi", $current_employee_id, $employee_code, $onboarding_id);
                            mysqli_stmt_execute($update_onboarding);
                            mysqli_stmt_close($update_onboarding);
                        }

                        $update_candidate = mysqli_prepare(
                            $conn,
                            "UPDATE candidates
                             SET status = 'Joined'
                             WHERE id = ?
                             LIMIT 1"
                        );

                        if ($update_candidate) {
                            mysqli_stmt_bind_param($update_candidate, "i", $details['candidate_id']);
                            mysqli_stmt_execute($update_candidate);
                            mysqli_stmt_close($update_candidate);
                        }

                        if (!empty($details['offer_id'])) {

                            $update_offer = mysqli_prepare(
                                $conn,
                                "UPDATE offers
                                 SET status = 'Accepted'
                                 WHERE id = ?
                                 LIMIT 1"
                            );

                            if ($update_offer) {
                                mysqli_stmt_bind_param($update_offer, "i", $details['offer_id']);
                                mysqli_stmt_execute($update_offer);
                                mysqli_stmt_close($update_offer);
                            }
                        }

                        if (function_exists('logActivity')) {
                            logActivity(
                                $conn,
                                'CREATE',
                                'employee',
                                "Created employee from onboarding: {$employee_code}",
                                $employee_id,
                                $employee_code,
                                null,
                                json_encode([
                                    'onboarding_id' => $onboarding_id,
                                    'employee_code' => $employee_code,
                                    'username' => $username
                                ])
                            );
                        }

                        mysqli_commit($conn);

                        $_SESSION['last_onboarding_credentials'] = [
                            'employee_code' => $employee_code,
                            'username' => $username,
                            'password' => $password,
                            'full_name' => $full_name
                        ];

                        $message = "Employee created successfully!";
                        $messageType = "success";

                    } catch (Throwable $e) {

                        mysqli_rollback($conn);

                        $message = "Error: " . $e->getMessage();
                        $messageType = "danger";
                    }
                }
            }

        } else {
            $messageType = "warning";
        }
    }
}

/* ---------------- FILTERS ---------------- */

$status_filter = trim((string)($_GET['status'] ?? 'all'));
$department_filter = trim((string)($_GET['department'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

if (!array_key_exists($status_filter, $status_options)) {
    $status_filter = 'all';
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

/* ---------------- ACCEPTED CANDIDATES FOR CREATE MODAL ---------------- */

$candidates_query = "
    SELECT
        c.id,
        c.first_name,
        c.last_name,
        c.candidate_code,
        c.email,
        c.phone,
        o.id AS offer_id,
        o.offer_no,
        o.ctc,
        o.expected_joining_date,
        h.id AS hiring_id,
        h.position_title,
        h.department,
        h.designation,
        h.requested_by,
        h.requested_by_name
    FROM candidates c
    JOIN offers o
    ON c.id = o.candidate_id
    JOIN hiring_requests h
    ON c.hiring_request_id = h.id
    LEFT JOIN onboarding ob
    ON ob.candidate_id = c.id
    WHERE c.status = 'Accepted'
    AND o.status = 'Accepted'
    AND ob.id IS NULL
";

if (!$isHr && !$isAdmin && $isManager) {
    $candidates_query .= " AND h.requested_by = {$current_employee_id}";
}

$candidates_query .= "
    ORDER BY o.response_date DESC
";

$accepted_candidates = [];

$candidates_result = mysqli_query($conn, $candidates_query);

if ($candidates_result) {
    $accepted_candidates = mysqli_fetch_all($candidates_result, MYSQLI_ASSOC);
}

/* ---------------- MANAGERS ---------------- */

$managers = [];

$managers_result = mysqli_query(
    $conn,
    "SELECT
        id,
        full_name,
        designation,
        department
     FROM employees
     WHERE employee_status = 'active'
     AND designation IN ('Manager', 'Team Lead', 'Director', 'Vice President', 'General Manager', 'Administrator', 'Admin')
     ORDER BY full_name ASC"
);

if ($managers_result) {
    $managers = mysqli_fetch_all($managers_result, MYSQLI_ASSOC);
}

/* ---------------- MAIN ONBOARDING QUERY ---------------- */

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
        CONCAT(c.first_name, ' ', c.last_name) AS candidate_name,
        h.id AS hiring_request_id,
        h.request_no,
        h.position_title,
        h.designation AS hiring_designation,
        offr.id AS offer_id,
        offr.offer_no,
        offr.ctc AS offer_ctc,
        reporting_emp.full_name AS reporting_to_name_joined,
        reporting_emp.designation AS reporting_to_designation,
        creator.full_name AS creator_name
    FROM onboarding o
    JOIN candidates c
    ON o.candidate_id = c.id
    JOIN hiring_requests h
    ON o.hiring_request_id = h.id
    LEFT JOIN offers offr
    ON o.offer_id = offr.id
    LEFT JOIN employees reporting_emp
    ON o.reporting_to = reporting_emp.id
    LEFT JOIN employees creator
    ON o.created_by = creator.id
    WHERE 1 = 1
";

$params = [];
$types = "";

if ($status_filter !== 'all') {
    $query .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($department_filter !== '') {
    $query .= " AND o.department = ?";
    $params[] = $department_filter;
    $types .= "s";
}

if ($date_from !== '') {
    $query .= " AND DATE(o.joining_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to !== '') {
    $query .= " AND DATE(o.joining_date) <= ?";
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
            OR o.onboarding_no LIKE ?
            OR o.employee_code LIKE ?
            OR h.position_title LIKE ?
            OR h.request_no LIKE ?
        )
    ";

    for ($i = 0; $i < 8; $i++) {
        $params[] = $like;
        $types .= "s";
    }
}

if (!$isHr && !$isAdmin && $isManager) {
    $query .= " AND h.requested_by = ?";
    $params[] = $current_employee_id;
    $types .= "i";
}

$query .= "
    ORDER BY
        o.joining_date DESC,
        o.created_at DESC
";

$onboardings = [];

$stmtOnboarding = mysqli_prepare($conn, $query);

if ($stmtOnboarding) {

    if (!empty($params)) {
        mysqli_stmt_bind_param(
            $stmtOnboarding,
            $types,
            ...$params
        );
    }

    mysqli_stmt_execute($stmtOnboarding);

    $resOnboarding = mysqli_stmt_get_result($stmtOnboarding);

    $onboardings = mysqli_fetch_all($resOnboarding, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtOnboarding);

} else {
    $message = "Error fetching onboarding records: " . mysqli_error($conn);
    $messageType = "danger";
}

/* ---------------- STATS ---------------- */

$stats_query = "
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        SUM(CASE WHEN joining_date >= CURDATE() AND joining_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS upcoming_joining,
        SUM(CASE WHEN joining_date < CURDATE() AND status <> 'Completed' THEN 1 ELSE 0 END) AS overdue_joining,
        AVG(CASE WHEN status = 'Completed' THEN DATEDIFF(completed_at, joining_date) END) AS avg_onboarding_days
    FROM onboarding o
";

$stats_params = [];
$stats_types = "";

if (!$isHr && !$isAdmin && $isManager) {
    $stats_query .= "
        WHERE o.hiring_request_id IN (
            SELECT id
            FROM hiring_requests
            WHERE requested_by = ?
        )
    ";

    $stats_params[] = $current_employee_id;
    $stats_types .= "i";
}

$stats = [
    'total_count' => 0,
    'pending_count' => 0,
    'in_progress_count' => 0,
    'completed_count' => 0,
    'cancelled_count' => 0,
    'upcoming_joining' => 0,
    'overdue_joining' => 0,
    'avg_onboarding_days' => null
];

$stmtStats = mysqli_prepare($conn, $stats_query);

if ($stmtStats) {

    if (!empty($stats_params)) {
        mysqli_stmt_bind_param(
            $stmtStats,
            $stats_types,
            ...$stats_params
        );
    }

    mysqli_stmt_execute($stmtStats);

    $resStats = mysqli_stmt_get_result($stmtStats);
    $rowStats = mysqli_fetch_assoc($resStats);

    if ($rowStats) {
        $stats = array_merge($stats, $rowStats);
    }

    mysqli_stmt_close($stmtStats);
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

<title>Onboarding Management - TEK-C Hiring</title>

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

.onboarding-wrapper{
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

.create-btn{
    background:#2f80ed;
}

.create-btn:hover{
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
.gray{ background:#64748b; }

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

.employee-code{
    color:#059669;
    background:#d1fae5;
    padding:3px 7px;
    border-radius:999px;
    font-size:10px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:4px;
    width:fit-content;
    margin-top:3px;
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

.progress-mini{
    height:7px;
    background:#e5e7eb;
    border-radius:999px;
    overflow:hidden;
    min-width:90px;
}

.progress-mini-bar{
    height:100%;
    border-radius:999px;
}

.progress-success{ background:#16a34a; }
.progress-info{ background:#2563eb; }
.progress-warning{ background:#f59e0b; }
.progress-muted{ background:#64748b; }

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
.edit-btn{ color:#2563eb; background:#eff6ff; }
.doc-btn{ color:#15803d; background:#dcfce7; }
.complete-btn{ color:#15803d; background:#dcfce7; }
.cancel-btn{ color:#b91c1c; background:#fee2e2; }
.code-btn{ color:#7c3aed; background:#ede9fe; }

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

.form-panel{
    background:#ffffff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:0 8px 20px rgba(15,23,42,.04);
    padding:16px;
    margin-bottom:16px;
}

.section-header{
    display:flex;
    align-items:center;
    margin-bottom:18px;
    padding-bottom:12px;
    border-bottom:2px solid #f0f4f8;
}

.section-icon{
    width:36px;
    height:36px;
    border-radius:12px;
    background:#111827;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-right:12px;
    font-size:18px;
    color:#fff;
}

.section-title{
    font-size:13px;
    font-weight:900;
    color:#2d3748;
    margin:0;
}

.section-subtitle{
    font-size:11px;
    color:#64748b;
    font-weight:700;
    margin-top:3px;
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

.onboarding-modal{
    max-width:min(1020px, calc(100vw - 24px));
    margin:12px auto;
}

.onboarding-modal .modal-content{
    height:calc(100vh - 24px);
    max-height:calc(100vh - 24px);
    border-radius:18px;
    overflow:hidden;
    border:0;
    box-shadow:var(--shadow);
}

.onboarding-modal .modal-header{
    flex:0 0 auto;
    padding:14px 18px;
}

.onboarding-modal .modal-body{
    flex:1 1 auto;
    overflow-y:auto;
    padding:16px;
}

.onboarding-modal .modal-footer{
    flex:0 0 auto;
    padding:12px 18px;
    background:#fff;
    border-top:1px solid var(--border);
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

.document-box{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:12px;
    padding:12px;
}

.checklist-item{
    padding:9px 0;
    border-bottom:1px solid #f1f5f9;
}

.checklist-item:last-child{
    border-bottom:0;
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
        flex:0 0 105px;
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

    .onboarding-modal{
        width:100%;
        max-width:100%;
        height:100%;
        margin:0;
    }

    .onboarding-modal .modal-content{
        height:100vh;
        max-height:100vh;
        border-radius:0;
    }

    .onboarding-modal .modal-body{
        padding:12px;
    }

    .form-panel{
        padding:12px!important;
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

<div class="container-fluid onboarding-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Onboarding Management
</h1>

<p>
Manage joining, documents, checklist and employee creation
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<?php if ($isHr || $isAdmin): ?>

<button
class="primary-btn create-btn"
type="button"
onclick="openCreateModal()"
>
<i class="bi bi-plus-lg"></i>
Start Onboarding
</button>

<?php endif; ?>

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

<li><?php echo e($err); ?></li>

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
<div class="stat-ic orange"><i class="bi bi-clock"></i></div>
<div>
<div class="stat-label">Pending</div>
<div class="stat-value"><?php echo (int)($stats['pending_count'] ?? 0); ?></div>
</div>
</div>
</div>

<div class="col-6 col-md-4 col-xl-2">
<div class="stat-card">
<div class="stat-ic blue"><i class="bi bi-gear"></i></div>
<div>
<div class="stat-label">In Progress</div>
<div class="stat-value"><?php echo (int)($stats['in_progress_count'] ?? 0); ?></div>
</div>
</div>
</div>

<div class="col-6 col-md-4 col-xl-2">
<div class="stat-card">
<div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
<div>
<div class="stat-label">Completed</div>
<div class="stat-value"><?php echo (int)($stats['completed_count'] ?? 0); ?></div>
</div>
</div>
</div>

<div class="col-6 col-md-4 col-xl-2">
<div class="stat-card">
<div class="stat-ic purple"><i class="bi bi-calendar-check"></i></div>
<div>
<div class="stat-label">Upcoming</div>
<div class="stat-value"><?php echo (int)($stats['upcoming_joining'] ?? 0); ?></div>
</div>
</div>
</div>

<div class="col-6 col-md-4 col-xl-2">
<div class="stat-card">
<div class="stat-ic red"><i class="bi bi-exclamation-triangle"></i></div>
<div>
<div class="stat-label">Overdue</div>
<div class="stat-value"><?php echo (int)($stats['overdue_joining'] ?? 0); ?></div>
</div>
</div>
</div>

<div class="col-6 col-md-4 col-xl-2">
<div class="stat-card">
<div class="stat-ic gray"><i class="bi bi-people"></i></div>
<div>
<div class="stat-label">Total</div>
<div class="stat-value"><?php echo (int)($stats['total_count'] ?? 0); ?></div>
</div>
</div>
</div>

</div>

<?php if (!empty($stats['avg_onboarding_days'])): ?>

<div class="summary-card">

<div class="row g-3">

<div class="col-12 col-md-4 summary-stat">
<div class="summary-stat-value"><?php echo number_format((float)$stats['avg_onboarding_days'], 1); ?> days</div>
<div class="summary-stat-label">Average Onboarding Time</div>
</div>

<div class="col-12 col-md-4 summary-stat">
<div class="summary-stat-value"><?php echo (int)($stats['completed_count'] ?? 0); ?></div>
<div class="summary-stat-label">Completed Records</div>
</div>

<div class="col-12 col-md-4 summary-stat">
<div class="summary-stat-value"><?php echo count($onboardings); ?></div>
<div class="summary-stat-label">Current View Records</div>
</div>

</div>

</div>

<?php endif; ?>

<!-- PANEL -->

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Onboarding List
</h3>

<div class="panel-subtitle">
Compact responsive onboarding directory
</div>

</div>

<span class="badge bg-secondary">
<?php echo count($onboardings); ?>
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
placeholder="Search candidate, onboarding no, employee code, position..."
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

<option value="">All Departments</option>

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
href="onboarding.php"
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
id="onboardingTable"
>

<thead>

<tr>
<th>Onboarding</th>
<th>Candidate</th>
<th>Position</th>
<th>Joining</th>
<th>Reporting</th>
<th>Progress</th>
<th>Status</th>
<th class="text-end">Actions</th>
</tr>

</thead>

<tbody>

<?php if (empty($onboardings)): ?>

<tr class="no-record-row">

<td colspan="8">

<div class="empty-state">

<i class="bi bi-person-check me-1"></i>
No onboarding records found.

</div>

</td>

</tr>

<?php else: ?>

<?php foreach ($onboardings as $item): ?>

<?php

$checklist_items = [
    'id_card_issued' => (int)($item['id_card_issued'] ?? 0),
    'email_created' => (int)($item['email_created'] ?? 0),
    'system_access_given' => (int)($item['system_access_given'] ?? 0),
    'biometric_enrolled' => (int)($item['biometric_enrolled'] ?? 0),
    'orientation_completed' => (int)($item['orientation_completed'] ?? 0),
    'training_completed' => (int)($item['training_completed'] ?? 0),
    'welcome_kit_issued' => (int)($item['welcome_kit_issued'] ?? 0)
];

$completed_count = array_sum($checklist_items);
$total_items = count($checklist_items);
$progress_percentage = $total_items > 0 ? (($completed_count / $total_items) * 100) : 0;

$today = date('Y-m-d');
$joiningClass = '';
$joiningIcon = '';

if (!empty($item['joining_date']) && $item['joining_date'] < $today && ($item['status'] ?? '') !== 'Completed') {
    $joiningClass = 'text-danger';
    $joiningIcon = '<i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Overdue"></i>';
} elseif (!empty($item['joining_date']) && $item['joining_date'] <= date('Y-m-d', strtotime('+7 days')) && ($item['status'] ?? '') !== 'Completed') {
    $joiningClass = 'text-warning';
    $joiningIcon = '<i class="bi bi-clock-fill text-warning ms-1" title="Upcoming"></i>';
}

$candidateName = trim((string)($item['candidate_name'] ?? ''));
$photoSrc = fileUrl($item['candidate_photo'] ?? '');

[$statusLabel, $statusClass, $statusKey] =
    onboardingStatusBadge($item['status'] ?? 'Pending');

$itemJson =
    htmlspecialchars(
        json_encode($item),
        ENT_QUOTES,
        'UTF-8'
    );

?>

<tr data-status="<?php echo e($statusKey); ?>">

<td data-label="Onboarding">

<div class="table-primary-text">
<?php echo e($item['onboarding_no'] ?? ''); ?>
</div>

<?php if (!empty($item['employee_code'])): ?>

<div class="employee-code">
<i class="bi bi-person-badge"></i>
<?php echo e($item['employee_code']); ?>
</div>

<?php endif; ?>

<div class="table-secondary-text">
<i class="bi bi-calendar me-1"></i>
Created:
<?php echo e(safeDate($item['created_at'] ?? '')); ?>
</div>

<?php if (!empty($item['offer_no'])): ?>

<div class="table-secondary-text">
<i class="bi bi-file-text me-1"></i>
<?php echo e($item['offer_no']); ?>
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
href="view-candidate.php?id=<?php echo (int)$item['candidate_id']; ?>"
class="text-decoration-none text-dark"
>
<?php echo e($candidateName); ?>
</a>

</div>

<div class="table-secondary-text">
<i class="bi bi-hash"></i>
<?php echo e($item['candidate_code'] ?? ''); ?>
</div>

<?php if (!empty($item['candidate_email'])): ?>

<div class="table-secondary-text">
<i class="bi bi-envelope me-1"></i>
<?php echo e($item['candidate_email']); ?>
</div>

<?php endif; ?>

</div>

</div>

</td>

<td data-label="Position">

<div class="table-primary-text">
<?php echo e($item['position_title'] ?? 'N/A'); ?>
</div>

<div class="table-secondary-text">
<?php echo e($item['request_no'] ?? ''); ?>
</div>

<?php if (!empty($item['department'])): ?>

<span class="department-tag">
<i class="bi bi-building"></i>
<?php echo e($item['department']); ?>
</span>

<?php endif; ?>

<?php if (!empty($item['offer_ctc'])): ?>

<div class="table-secondary-text mt-1">
<i class="bi bi-currency-rupee me-1"></i>
<?php echo e(formatCurrency($item['offer_ctc'])); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Joining">

<div class="table-primary-text <?php echo e($joiningClass); ?>">
<?php echo e(safeDate($item['joining_date'] ?? '')); ?>
<?php echo $joiningIcon; ?>
</div>

<?php if (!empty($item['reporting_time'])): ?>

<div class="table-secondary-text">
<i class="bi bi-clock me-1"></i>
<?php echo e(safeTime($item['reporting_time'])); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Reporting">

<?php
$reportingName =
    !empty($item['reporting_to_name_joined'])
    ? $item['reporting_to_name_joined']
    : ($item['reporting_to_name'] ?? '');
?>

<?php if (!empty($reportingName)): ?>

<div class="table-primary-text">
<?php echo e($reportingName); ?>
</div>

<?php if (!empty($item['reporting_to_designation'])): ?>

<div class="table-secondary-text">
<?php echo e($item['reporting_to_designation']); ?>
</div>

<?php endif; ?>

<?php else: ?>

<span class="table-secondary-text">
—
</span>

<?php endif; ?>

</td>

<td data-label="Progress">

<div class="d-flex align-items-center gap-2">

<span class="table-primary-text">
<?php echo (int)$completed_count; ?>/<?php echo (int)$total_items; ?>
</span>

<div class="progress-mini flex-grow-1">

<div
class="progress-mini-bar <?php echo e(progressClass($completed_count, $total_items)); ?>"
style="width: <?php echo (float)$progress_percentage; ?>%;"
></div>

</div>

</div>

<div class="table-secondary-text mt-1">
ID:
<?php echo !empty($item['id_card_issued']) ? 'Yes' : 'No'; ?>
•
Email:
<?php echo !empty($item['email_created']) ? 'Yes' : 'No'; ?>
</div>

<?php if (!empty($item['documents_submitted'])): ?>

<div class="table-secondary-text text-success">
<i class="bi bi-file-earmark-check me-1"></i>
Docs submitted
</div>

<?php endif; ?>

</td>

<td data-label="Status">

<span class="badge-pill <?php echo e($statusClass); ?>">

<span class="mini-dot"></span>

<?php echo e($statusLabel); ?>

</span>

<?php if (($item['status'] ?? '') === 'Completed' && !empty($item['completed_at'])): ?>

<div class="table-secondary-text mt-1">
<i class="bi bi-calendar-check me-1"></i>
<?php echo e(safeDate($item['completed_at'])); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Actions">

<div class="action-group">

<a
href="view-onboarding.php?id=<?php echo (int)$item['id']; ?>"
class="action-btn view-btn"
title="View Details"
>
<i class="bi bi-eye"></i>
</a>

<?php if (($item['status'] ?? '') !== 'Completed' && ($item['status'] ?? '') !== 'Cancelled'): ?>

<button
type="button"
class="action-btn edit-btn"
onclick="openStatusModal(<?php echo $itemJson; ?>)"
title="Update Status"
>
<i class="bi bi-pencil"></i>
</button>

<button
type="button"
class="action-btn doc-btn"
onclick="openDocumentModal(<?php echo (int)$item['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Upload Documents"
>
<i class="bi bi-file-earmark"></i>
</button>

<button
type="button"
class="action-btn code-btn"
onclick="openCodeModal(<?php echo (int)$item['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Generate Employee Code"
>
<i class="bi bi-person-badge"></i>
</button>

<button
type="button"
class="action-btn complete-btn"
onclick="openCompleteModal(<?php echo (int)$item['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Complete Onboarding"
>
<i class="bi bi-check-lg"></i>
</button>

<?php endif; ?>

<?php if (($item['status'] ?? '') === 'Pending' || ($item['status'] ?? '') === 'In Progress'): ?>

<button
type="button"
class="action-btn cancel-btn"
onclick="openCancelModal(<?php echo (int)$item['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Cancel"
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
<?php echo count($onboardings); ?>
onboarding records
</div>

</div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<!-- CREATE ONBOARDING MODAL -->

<div
class="modal fade"
id="createOnboardingModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down onboarding-modal">

<div class="modal-content">

<form
method="POST"
class="h-100 d-flex flex-column"
>

<input type="hidden" name="action" value="create_onboarding">

<div class="modal-header">

<div>

<h5 class="modal-title mb-1">
Start New Onboarding
</h5>

<div class="text-muted small fw-semibold">
Create onboarding from an accepted offer.
</div>

</div>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<div class="form-panel">

<div class="section-header">

<div class="section-icon">
<i class="bi bi-person-check"></i>
</div>

<div>

<h3 class="section-title">
Candidate Selection
</h3>

<p class="section-subtitle">
Choose a candidate with accepted offer and no existing onboarding
</p>

</div>

</div>

<div class="row g-3">

<div class="col-12">

<label class="form-label required-label">
Select Candidate
</label>

<select
name="candidate_id"
class="form-select"
id="candidate_select"
required
>

<option value="">
Choose candidate who accepted offer...
</option>

<?php foreach ($accepted_candidates as $candidate): ?>

<option
value="<?php echo (int)$candidate['id']; ?>"
data-offer-id="<?php echo (int)$candidate['offer_id']; ?>"
data-offer-no="<?php echo e($candidate['offer_no']); ?>"
data-hiring-id="<?php echo (int)$candidate['hiring_id']; ?>"
data-position="<?php echo e($candidate['position_title']); ?>"
data-department="<?php echo e($candidate['department']); ?>"
data-designation="<?php echo e($candidate['designation']); ?>"
data-joining-date="<?php echo e($candidate['expected_joining_date']); ?>"
>
<?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?>
(<?php echo e($candidate['candidate_code']); ?>)
-
<?php echo e($candidate['position_title']); ?>
-
Offer:
<?php echo e($candidate['offer_no']); ?>
</option>

<?php endforeach; ?>

</select>

<input type="hidden" name="offer_id" id="offer_id">
<input type="hidden" name="hiring_request_id" id="hiring_request_id">

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#2563eb;">
<i class="bi bi-calendar-event"></i>
</div>

<div>

<h3 class="section-title">
Joining Details
</h3>

<p class="section-subtitle">
Joining date, time and reporting details
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-6">

<label class="form-label required-label">
Joining Date
</label>

<input
type="date"
name="joining_date"
id="joining_date"
class="form-control"
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
Select Manager
</option>

<?php foreach ($managers as $manager): ?>

<option value="<?php echo (int)$manager['id']; ?>">
<?php echo e($manager['full_name']); ?>
<?php if (!empty($manager['designation'])): ?>
(<?php echo e($manager['designation']); ?>)
<?php endif; ?>
</option>

<?php endforeach; ?>

</select>

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#10b981;">
<i class="bi bi-briefcase"></i>
</div>

<div>

<h3 class="section-title">
Position Details
</h3>

<p class="section-subtitle">
Auto-filled from accepted offer / hiring request
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-6">

<label class="form-label required-label">
Department
</label>

<input
type="text"
name="department"
id="department"
class="form-control"
readonly
required
>

</div>

<div class="col-md-6">

<label class="form-label required-label">
Designation
</label>

<input
type="text"
name="designation"
id="designation"
class="form-control"
readonly
required
>

</div>

</div>

</div>

<div class="alert alert-info mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>
On completion, this onboarding can create the employee record with login credentials.

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
<i class="bi bi-plus-lg me-1"></i>
Start Onboarding
</button>

</div>

</form>

</div>

</div>

</div>

<!-- UPDATE STATUS MODAL -->

<div
class="modal fade"
id="statusModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input type="hidden" name="action" value="update_status">
<input type="hidden" name="onboarding_id" id="status_onboarding_id">

<div class="modal-header">

<h5 class="modal-title">
Update Onboarding Status
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<div class="mb-3">

<label class="form-label required-label">
Status
</label>

<select
name="status"
class="form-select"
id="status_select"
required
>

<option value="Pending">
Pending
</option>

<option value="In Progress">
In Progress
</option>

<option value="Completed">
Completed
</option>

<option value="Cancelled">
Cancelled
</option>

</select>

</div>

<div class="mb-3">

<label class="form-label">
Remarks
<span class="optional-badge">(Optional)</span>
</label>

<textarea
name="remarks"
class="form-control"
rows="3"
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
class="btn btn-dark"
>
Update Status
</button>

</div>

</form>

</div>

</div>

</div>

<!-- DOCUMENT UPLOAD MODAL -->

<div
class="modal fade"
id="documentModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog modal-lg modal-dialog-scrollable">

<div class="modal-content">

<form method="POST" enctype="multipart/form-data">

<input type="hidden" name="action" value="update_documents">
<input type="hidden" name="onboarding_id" id="doc_onboarding_id">

<div class="modal-header">

<div>

<h5 class="modal-title mb-1">
Upload Documents
</h5>

<div class="text-muted small fw-semibold">
Candidate:
<span id="doc_candidate_name"></span>
</div>

</div>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<div class="row g-3">

<div class="col-md-6">
<div class="document-box">
<label class="form-label required-label">Aadhar Card</label>
<input type="file" name="doc_aadhar" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
<div class="form-text">PDF or image, max 5MB</div>
</div>
</div>

<div class="col-md-6">
<div class="document-box">
<label class="form-label required-label">PAN Card</label>
<input type="file" name="doc_pan" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
</div>
</div>

<div class="col-md-6">
<div class="document-box">
<label class="form-label">Degree Certificate</label>
<input type="file" name="doc_degree" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
</div>
</div>

<div class="col-md-6">
<div class="document-box">
<label class="form-label">Experience Letters</label>
<input type="file" name="doc_experience" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
</div>
</div>

<div class="col-md-6">
<div class="document-box">
<label class="form-label">Photograph</label>
<input type="file" name="doc_photo" class="form-control" accept=".jpg,.jpeg,.png">
</div>
</div>

<div class="col-md-6">
<div class="document-box">
<label class="form-label required-label">Offer Acceptance</label>
<input type="file" name="doc_offer_acceptance" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
</div>
</div>

<div class="col-md-6">
<div class="document-box">
<label class="form-label">Bank Details</label>
<input type="file" name="doc_bank" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
</div>
</div>

<div class="col-md-6">
<div class="document-box">
<label class="form-label">Other Documents</label>
<input type="file" name="doc_other" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
</div>
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
class="btn btn-dark"
>
Upload Documents
</button>

</div>

</form>

</div>

</div>

</div>

<!-- CHECKLIST MODAL -->

<div
class="modal fade"
id="checklistModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input type="hidden" name="action" value="update_checklist">
<input type="hidden" name="onboarding_id" id="checklist_onboarding_id">

<div class="modal-header">

<h5 class="modal-title">
Onboarding Checklist
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<?php
$checklistLabels = [
    'id_card_issued' => ['ID Card Issued', 'Employee ID card has been issued', 'chk_id_card'],
    'email_created' => ['Email Account Created', 'Company email address created', 'chk_email'],
    'system_access_given' => ['System Access Granted', 'Access to systems, drives, and software', 'chk_system'],
    'biometric_enrolled' => ['Biometric Enrolled', 'Biometric attendance registered', 'chk_biometric'],
    'orientation_completed' => ['Orientation Completed', 'Company orientation session attended', 'chk_orientation'],
    'training_completed' => ['Training Completed', 'Role-specific training completed', 'chk_training'],
    'welcome_kit_issued' => ['Welcome Kit Issued', 'Welcome kit / onboarding kit provided', 'chk_welcome']
];
?>

<?php foreach ($checklistLabels as $name => $meta): ?>

<div class="checklist-item">

<div class="form-check">

<input
class="form-check-input"
type="checkbox"
name="<?php echo e($name); ?>"
id="<?php echo e($meta[2]); ?>"
>

<label
class="form-check-label"
for="<?php echo e($meta[2]); ?>"
>

<strong><?php echo e($meta[0]); ?></strong>
<br>
<small class="text-muted"><?php echo e($meta[1]); ?></small>

</label>

</div>

</div>

<?php endforeach; ?>

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
Update Checklist
</button>

</div>

</form>

</div>

</div>

</div>

<!-- GENERATE CODE MODAL -->

<div
class="modal fade"
id="codeModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input type="hidden" name="action" value="generate_employee_code">
<input type="hidden" name="onboarding_id" id="code_onboarding_id">

<div class="modal-header">

<h5 class="modal-title">
Generate Employee Code
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Generate employee code for
<strong id="code_candidate_name"></strong>?
</p>

<div class="alert alert-info mb-0" style="box-shadow:none;">
<i class="bi bi-info-circle me-2"></i>
This will save the generated code in the onboarding record.
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
Generate Code
</button>

</div>

</form>

</div>

</div>

</div>

<!-- COMPLETE MODAL -->

<div
class="modal fade"
id="completeModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input type="hidden" name="action" value="complete_onboarding">
<input type="hidden" name="onboarding_id" id="complete_onboarding_id">

<div class="modal-header">

<h5 class="modal-title">
Complete Onboarding
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Complete onboarding for
<strong id="complete_candidate_name"></strong>?
</p>

<div class="alert alert-warning" style="box-shadow:none;">

<i class="bi bi-exclamation-triangle me-2"></i>

<strong>This will:</strong>

<ul class="mb-0 mt-2">
<li>Create an employee record</li>
<li>Generate username and password</li>
<li>Mark candidate as Joined</li>
<li>Mark onboarding as Completed</li>
</ul>

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
Complete Onboarding
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
aria-hidden="true"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input type="hidden" name="action" value="update_status">
<input type="hidden" name="onboarding_id" id="cancel_onboarding_id">
<input type="hidden" name="status" value="Cancelled">

<div class="modal-header">

<h5 class="modal-title">
Cancel Onboarding
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Cancel onboarding for
<strong id="cancel_candidate_name"></strong>?
</p>

<div class="mb-3">

<label class="form-label required-label">
Reason for Cancellation
</label>

<textarea
name="remarks"
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
Cancel Onboarding
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
Export Onboarding
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
CSV exports currently displayed rows. Server export redirects to export-onboarding.php with current filters.
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

<!-- CREDENTIALS MODAL -->

<div
class="modal fade"
id="credentialsModal"
tabindex="-1"
data-bs-backdrop="static"
aria-hidden="true"
>

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content">

<div class="modal-header bg-success text-white">

<h5 class="modal-title text-white">
<i class="bi bi-check-circle-fill me-2"></i>
Employee Created Successfully!
</h5>

<button
type="button"
class="btn-close btn-close-white"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<div class="alert alert-info" style="box-shadow:none;">
<i class="bi bi-info-circle-fill me-2"></i>
Please save these credentials and share them securely with the employee.
</div>

<div class="card mb-3 border">

<div class="card-body">

<h6 class="card-title text-muted mb-3">
Login Credentials
</h6>

<div class="mb-3">

<label class="fw-bold text-dark mb-1">
Employee Code:
</label>

<div class="input-group">

<input
type="text"
id="cred_employee_code"
class="form-control bg-light"
readonly
>

<button
class="btn btn-outline-primary copy-btn"
type="button"
data-copy="cred_employee_code"
>
<i class="bi bi-clipboard"></i>
</button>

</div>

</div>

<div class="mb-3">

<label class="fw-bold text-dark mb-1">
Username:
</label>

<div class="input-group">

<input
type="text"
id="cred_username"
class="form-control bg-light"
readonly
>

<button
class="btn btn-outline-primary copy-btn"
type="button"
data-copy="cred_username"
>
<i class="bi bi-clipboard"></i>
</button>

</div>

</div>

<div class="mb-2">

<label class="fw-bold text-dark mb-1">
Password:
</label>

<div class="input-group">

<input
type="text"
id="cred_password"
class="form-control bg-light"
readonly
>

<button
class="btn btn-outline-primary copy-btn"
type="button"
data-copy="cred_password"
>
<i class="bi bi-clipboard"></i>
</button>

<button
class="btn btn-outline-secondary"
type="button"
id="togglePasswordVisibility"
>
<i class="bi bi-eye"></i>
</button>

</div>

</div>

</div>

</div>

<div class="alert alert-warning mb-0" style="box-shadow:none;">
<i class="bi bi-exclamation-triangle-fill me-2"></i>
Ask the employee to change this password after first login.
</div>

</div>

<div class="modal-footer">

<a
href="employees.php"
class="btn btn-dark"
>
<i class="bi bi-people me-1"></i>
View Employee Directory
</a>

<button
type="button"
class="btn btn-secondary"
data-bs-dismiss="modal"
>
Close
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
        document.querySelectorAll('#onboardingTable tbody tr:not(.no-record-row)');

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
                'Showing ' + visibleCount + ' onboarding records';
        }

        if (serverSearch) {
            serverSearch.value =
                searchValue;
        }
    }

    if (quickSearch) {
        quickSearch.addEventListener('input', filterRows);
    }

    const candidateSelect =
        document.getElementById('candidate_select');

    if (candidateSelect) {

        candidateSelect.addEventListener('change', function(){

            const selected =
                this.options[this.selectedIndex];

            document.getElementById('offer_id').value =
                selected.getAttribute('data-offer-id') || '';

            document.getElementById('hiring_request_id').value =
                selected.getAttribute('data-hiring-id') || '';

            document.getElementById('department').value =
                selected.getAttribute('data-department') || '';

            document.getElementById('designation').value =
                selected.getAttribute('data-designation') ||
                selected.getAttribute('data-position') ||
                '';

            const joiningDate =
                selected.getAttribute('data-joining-date') || '';

            if (joiningDate) {
                document.getElementById('joining_date').value =
                    joiningDate;
            }
        });
    }

    const copyButtons =
        document.querySelectorAll('.copy-btn');

    copyButtons.forEach(function(button){

        button.addEventListener('click', function(){

            const targetId =
                this.getAttribute('data-copy');

            const input =
                document.getElementById(targetId);

            if (!input) {
                return;
            }

            navigator.clipboard.writeText(input.value).then(() => {

                const oldHtml =
                    this.innerHTML;

                this.innerHTML =
                    '<i class="bi bi-check"></i>';

                setTimeout(() => {
                    this.innerHTML = oldHtml;
                }, 1600);
            });
        });
    });

    const togglePassword =
        document.getElementById('togglePasswordVisibility');

    if (togglePassword) {

        togglePassword.addEventListener('click', function(){

            const input =
                document.getElementById('cred_password');

            const icon =
                this.querySelector('i');

            if (!input || !icon) {
                return;
            }

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    }

    <?php if (isset($_SESSION['last_onboarding_credentials'])): ?>

    const creds =
        <?php echo json_encode($_SESSION['last_onboarding_credentials']); ?>;

    document.getElementById('cred_employee_code').value =
        creds.employee_code || '';

    document.getElementById('cred_username').value =
        creds.username || '';

    document.getElementById('cred_password').value =
        creds.password || '';

    new bootstrap.Modal(
        document.getElementById('credentialsModal')
    ).show();

    <?php unset($_SESSION['last_onboarding_credentials']); ?>

    <?php endif; ?>
});

function openCreateModal(){

    new bootstrap.Modal(
        document.getElementById('createOnboardingModal')
    ).show();
}

function openStatusModal(item){

    document.getElementById('status_onboarding_id').value =
        item.id || '';

    document.getElementById('status_select').value =
        item.status || 'Pending';

    new bootstrap.Modal(
        document.getElementById('statusModal')
    ).show();
}

function openDocumentModal(id, candidateName){

    document.getElementById('doc_onboarding_id').value =
        id;

    document.getElementById('doc_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('documentModal')
    ).show();
}

function openCodeModal(id, candidateName){

    document.getElementById('code_onboarding_id').value =
        id;

    document.getElementById('code_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('codeModal')
    ).show();
}

function openCompleteModal(id, candidateName){

    document.getElementById('complete_onboarding_id').value =
        id;

    document.getElementById('complete_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('completeModal')
    ).show();
}

function openCancelModal(id, candidateName){

    document.getElementById('cancel_onboarding_id').value =
        id;

    document.getElementById('cancel_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('cancelModal')
    ).show();
}

function handleExport(){

    const format =
        document.getElementById('exportFormat').value;

    if (format === 'server') {
        window.location.href =
            'export-onboarding.php?' + window.location.search.substring(1);
        return;
    }

    exportToCSV();
}

function exportToCSV(){

    const rows =
        document.querySelectorAll('#onboardingTable tbody tr:not(.no-record-row)');

    const csv =
        [];

    const headers = [
        'Onboarding',
        'Candidate',
        'Position',
        'Joining',
        'Reporting',
        'Progress',
        'Status'
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
        'onboarding_<?php echo date('Y-m-d'); ?>.csv';

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