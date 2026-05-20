<?php
// offers.php
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

$designation_current = strtolower(trim((string)($current_employee['designation'] ?? '')));
$department_current  = strtolower(trim((string)($current_employee['department'] ?? '')));

$isHr =
    $designation_current === 'hr' ||
    $department_current === 'hr';

$isManager =
    in_array(
        $designation_current,
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
    $designation_current === 'administrator' ||
    $designation_current === 'admin' ||
    $designation_current === 'director';

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
    $last  = strtoupper(substr(end($parts) ?: '', 0, 1));

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

    return '../uploads/' . ltrim($p, '/');
}

function offerStatusBadge($status) {

    $status = trim((string)$status);

    $map = [
        'Draft' => ['Draft', 'muted', 'draft'],
        'Approved' => ['Approved', 'ontrack', 'approved'],
        'Rejected' => ['Rejected', 'danger', 'rejected'],
        'Sent' => ['Sent', 'info', 'sent'],
        'Accepted' => ['Accepted', 'ontrack', 'accepted'],
        'Expired' => ['Expired', 'warning', 'expired'],
        'Withdrawn' => ['Withdrawn', 'dark-soft', 'withdrawn']
    ];

    return $map[$status] ?? [$status ?: 'Unknown', 'muted', strtolower($status ?: 'unknown')];
}

function getUploadBaseDir() {

    $dir = __DIR__ . '/../uploads/offers';

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $real = realpath($dir);

    return $real ?: $dir;
}

function handleOfferDocumentUpload($fieldName = 'offer_document') {

    if (
        empty($_FILES[$fieldName]) ||
        empty($_FILES[$fieldName]['name'])
    ) {
        return [
            'success' => true,
            'path' => null,
            'fs_path' => null,
            'error' => ''
        ];
    }

    $file = $_FILES[$fieldName];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'path' => null,
            'fs_path' => null,
            'error' => 'Offer document upload failed'
        ];
    }

    $allowed = ['pdf', 'doc', 'docx'];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        return [
            'success' => false,
            'path' => null,
            'fs_path' => null,
            'error' => 'Offer document must be PDF, DOC, or DOCX'
        ];
    }

    $size = (int)($file['size'] ?? 0);

    if ($size <= 0 || $size > (10 * 1024 * 1024)) {
        return [
            'success' => false,
            'path' => null,
            'fs_path' => null,
            'error' => 'Offer document must be less than 10MB'
        ];
    }

    try {
        $rand = bin2hex(random_bytes(6));
    } catch (Throwable $t) {
        $rand = uniqid();
    }

    $fileName =
        'offer_' .
        time() .
        '_' .
        $rand .
        '.' .
        $ext;

    $uploadDir = getUploadBaseDir();

    $targetFs =
        rtrim($uploadDir, "/\\") .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetFs)) {
        return [
            'success' => false,
            'path' => null,
            'fs_path' => null,
            'error' => 'Failed to save offer document'
        ];
    }

    return [
        'success' => true,
        'path' => 'uploads/offers/' . $fileName,
        'fs_path' => $targetFs,
        'error' => ''
    ];
}

/* ---------------- OPTIONS ---------------- */

$status_options = [
    'all' => 'All Status',
    'Draft' => 'Draft',
    'Approved' => 'Approved',
    'Rejected' => 'Rejected',
    'Sent' => 'Sent',
    'Accepted' => 'Accepted',
    'Expired' => 'Expired',
    'Withdrawn' => 'Withdrawn'
];

$employment_types = [
    'Full-time',
    'Part-time',
    'Contract',
    'Intern'
];

/* ---------------- MESSAGES ---------------- */

$message = '';
$messageType = '';
$validation_errors = [];

/* ---------------- POST ACTIONS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = trim((string)$_POST['action']);

    /* ---------- CREATE OFFER ---------- */

    if ($action === 'create_offer') {

        if (!$isHr && !$isAdmin) {

            $message = "Only HR/Admin can create offers.";
            $messageType = "danger";

        } else {

            $candidate_id = (int)($_POST['candidate_id'] ?? 0);
            $hiring_request_id = (int)($_POST['hiring_request_id'] ?? 0);
            $offer_date = trim($_POST['offer_date'] ?? '');
            $offer_valid_till = trim($_POST['offer_valid_till'] ?? '');
            $expected_joining_date = trim($_POST['expected_joining_date'] ?? '');
            $designation = trim($_POST['designation'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $employment_type = trim($_POST['employment_type'] ?? '');
            $ctc = trim($_POST['ctc'] ?? '');
            $basic_salary = trim($_POST['basic_salary'] ?? '');
            $hra = trim($_POST['hra'] ?? '');
            $conveyance = trim($_POST['conveyance'] ?? '');
            $medical = trim($_POST['medical'] ?? '');
            $special_allowance = trim($_POST['special_allowance'] ?? '');
            $bonus = trim($_POST['bonus'] ?? '');
            $other_benefits = trim($_POST['other_benefits'] ?? '');
            $terms_conditions = trim($_POST['terms_conditions'] ?? '');

            if ($candidate_id <= 0) {
                $validation_errors[] = "Candidate is required";
            }

            if ($hiring_request_id <= 0) {
                $validation_errors[] = "Hiring request is required";
            }

            if ($offer_date === '') {
                $validation_errors[] = "Offer date is required";
            }

            if ($offer_valid_till === '') {
                $validation_errors[] = "Offer valid till date is required";
            }

            if ($expected_joining_date === '') {
                $validation_errors[] = "Expected joining date is required";
            }

            if ($designation === '') {
                $validation_errors[] = "Designation is required";
            }

            if ($department === '') {
                $validation_errors[] = "Department is required";
            }

            if (!in_array($employment_type, $employment_types, true)) {
                $validation_errors[] = "Invalid employment type";
            }

            if ($ctc === '' || !is_numeric($ctc) || (float)$ctc <= 0) {
                $validation_errors[] = "Valid CTC is required";
            }

            foreach ([
                'Basic salary' => $basic_salary,
                'HRA' => $hra,
                'Conveyance' => $conveyance,
                'Medical allowance' => $medical,
                'Special allowance' => $special_allowance,
                'Bonus' => $bonus
            ] as $label => $amount) {
                if ($amount !== '' && (!is_numeric($amount) || (float)$amount < 0)) {
                    $validation_errors[] = $label . " must be a valid amount";
                }
            }

            if (empty($validation_errors)) {

                $verify_stmt = mysqli_prepare(
                    $conn,
                    "SELECT c.id
                     FROM candidates c
                     JOIN hiring_requests h
                     ON c.hiring_request_id = h.id
                     WHERE c.id = ?
                     AND h.id = ?
                     AND c.status IN ('Selected', 'Interviewed')
                     LIMIT 1"
                );

                if ($verify_stmt) {

                    mysqli_stmt_bind_param(
                        $verify_stmt,
                        "ii",
                        $candidate_id,
                        $hiring_request_id
                    );

                    mysqli_stmt_execute($verify_stmt);
                    mysqli_stmt_store_result($verify_stmt);

                    if (mysqli_stmt_num_rows($verify_stmt) <= 0) {
                        $validation_errors[] = "Selected candidate is not valid for offer creation";
                    }

                    mysqli_stmt_close($verify_stmt);
                }
            }

            $offer_document = null;
            $offer_document_fs = null;

            if (empty($validation_errors)) {

                $uploadResult = handleOfferDocumentUpload('offer_document');

                if ($uploadResult['success']) {
                    $offer_document = $uploadResult['path'];
                    $offer_document_fs = $uploadResult['fs_path'];
                } else {
                    $validation_errors[] = $uploadResult['error'];
                }
            }

            if (empty($validation_errors)) {

                $year = date('Y');
                $month = date('m');
                $like = "OFF-{$year}{$month}%";
                $count = 0;

                $seq_stmt = mysqli_prepare(
                    $conn,
                    "SELECT COUNT(*) AS total_count
                     FROM offers
                     WHERE offer_no LIKE ?"
                );

                if ($seq_stmt) {
                    mysqli_stmt_bind_param($seq_stmt, "s", $like);
                    mysqli_stmt_execute($seq_stmt);

                    $seq_res = mysqli_stmt_get_result($seq_stmt);
                    $seq_row = mysqli_fetch_assoc($seq_res);

                    $count = (int)($seq_row['total_count'] ?? 0);

                    mysqli_stmt_close($seq_stmt);
                }

                $seq_num = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
                $offer_no = "OFF-{$year}{$month}-{$seq_num}";

                $ctc_db = (float)$ctc;
                $basic_salary_db = $basic_salary !== '' ? (float)$basic_salary : null;
                $hra_db = $hra !== '' ? (float)$hra : null;
                $conveyance_db = $conveyance !== '' ? (float)$conveyance : null;
                $medical_db = $medical !== '' ? (float)$medical : null;
                $special_allowance_db = $special_allowance !== '' ? (float)$special_allowance : null;
                $bonus_db = $bonus !== '' ? (float)$bonus : null;

                $other_benefits_db = nullIfEmpty($other_benefits);
                $terms_conditions_db = nullIfEmpty($terms_conditions);
                $created_by_name = $current_employee['full_name'] ?? '';

                $insert_stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO offers
                    (
                        offer_no,
                        candidate_id,
                        hiring_request_id,
                        offer_date,
                        offer_valid_till,
                        expected_joining_date,
                        designation,
                        department,
                        employment_type,
                        ctc,
                        basic_salary,
                        hra,
                        conveyance,
                        medical,
                        special_allowance,
                        bonus,
                        other_benefits,
                        terms_conditions,
                        offer_document,
                        status,
                        created_by,
                        created_by_name
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        'Draft', ?, ?
                    )"
                );

                if ($insert_stmt) {

                    mysqli_stmt_bind_param(
                        $insert_stmt,
                        "siissssssdddddddsssis",
                        $offer_no,
                        $candidate_id,
                        $hiring_request_id,
                        $offer_date,
                        $offer_valid_till,
                        $expected_joining_date,
                        $designation,
                        $department,
                        $employment_type,
                        $ctc_db,
                        $basic_salary_db,
                        $hra_db,
                        $conveyance_db,
                        $medical_db,
                        $special_allowance_db,
                        $bonus_db,
                        $other_benefits_db,
                        $terms_conditions_db,
                        $offer_document,
                        $current_employee_id,
                        $created_by_name
                    );

                    if (mysqli_stmt_execute($insert_stmt)) {

                        $offer_id = mysqli_insert_id($conn);

                        $candidate_stmt = mysqli_prepare(
                            $conn,
                            "UPDATE candidates
                             SET status = 'Offered'
                             WHERE id = ?
                             LIMIT 1"
                        );

                        if ($candidate_stmt) {
                            mysqli_stmt_bind_param($candidate_stmt, "i", $candidate_id);
                            mysqli_stmt_execute($candidate_stmt);
                            mysqli_stmt_close($candidate_stmt);
                        }

                        if (function_exists('logActivity')) {
                            logActivity(
                                $conn,
                                'CREATE',
                                'offer',
                                "Created new offer: {$offer_no}",
                                $offer_id,
                                $offer_no,
                                null,
                                json_encode($_POST)
                            );
                        }

                        $message = "Offer created successfully! Offer Number: {$offer_no}";
                        $messageType = "success";

                    } else {

                        if ($offer_document_fs && file_exists($offer_document_fs)) {
                            @unlink($offer_document_fs);
                        }

                        $message = "Error creating offer: " . mysqli_stmt_error($insert_stmt);
                        $messageType = "danger";
                    }

                    mysqli_stmt_close($insert_stmt);

                } else {

                    if ($offer_document_fs && file_exists($offer_document_fs)) {
                        @unlink($offer_document_fs);
                    }

                    $message = "Database error: " . mysqli_error($conn);
                    $messageType = "danger";
                }

            } else {

                if ($offer_document_fs && file_exists($offer_document_fs)) {
                    @unlink($offer_document_fs);
                }

                $messageType = "warning";
            }
        }
    }

    /* ---------- UPDATE OFFER ---------- */

    elseif ($action === 'update_offer') {

        if (!$isHr && !$isAdmin) {

            $message = "Only HR/Admin can update offers.";
            $messageType = "danger";

        } else {

            $offer_id = (int)($_POST['offer_id'] ?? 0);
            $offer_date = trim($_POST['offer_date'] ?? '');
            $offer_valid_till = trim($_POST['offer_valid_till'] ?? '');
            $expected_joining_date = trim($_POST['expected_joining_date'] ?? '');
            $designation = trim($_POST['designation'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $employment_type = trim($_POST['employment_type'] ?? '');
            $ctc = trim($_POST['ctc'] ?? '');
            $basic_salary = trim($_POST['basic_salary'] ?? '');
            $hra = trim($_POST['hra'] ?? '');
            $conveyance = trim($_POST['conveyance'] ?? '');
            $medical = trim($_POST['medical'] ?? '');
            $special_allowance = trim($_POST['special_allowance'] ?? '');
            $bonus = trim($_POST['bonus'] ?? '');
            $other_benefits = trim($_POST['other_benefits'] ?? '');
            $terms_conditions = trim($_POST['terms_conditions'] ?? '');

            if ($offer_id <= 0) {
                $validation_errors[] = "Invalid offer selected";
            }

            if ($offer_date === '') {
                $validation_errors[] = "Offer date is required";
            }

            if ($offer_valid_till === '') {
                $validation_errors[] = "Offer valid till date is required";
            }

            if ($expected_joining_date === '') {
                $validation_errors[] = "Expected joining date is required";
            }

            if ($designation === '') {
                $validation_errors[] = "Designation is required";
            }

            if ($department === '') {
                $validation_errors[] = "Department is required";
            }

            if (!in_array($employment_type, $employment_types, true)) {
                $validation_errors[] = "Invalid employment type";
            }

            if ($ctc === '' || !is_numeric($ctc) || (float)$ctc <= 0) {
                $validation_errors[] = "Valid CTC is required";
            }

            foreach ([
                'Basic salary' => $basic_salary,
                'HRA' => $hra,
                'Conveyance' => $conveyance,
                'Medical allowance' => $medical,
                'Special allowance' => $special_allowance,
                'Bonus' => $bonus
            ] as $label => $amount) {
                if ($amount !== '' && (!is_numeric($amount) || (float)$amount < 0)) {
                    $validation_errors[] = $label . " must be a valid amount";
                }
            }

            if (empty($validation_errors)) {

                $ctc_db = (float)$ctc;
                $basic_salary_db = $basic_salary !== '' ? (float)$basic_salary : null;
                $hra_db = $hra !== '' ? (float)$hra : null;
                $conveyance_db = $conveyance !== '' ? (float)$conveyance : null;
                $medical_db = $medical !== '' ? (float)$medical : null;
                $special_allowance_db = $special_allowance !== '' ? (float)$special_allowance : null;
                $bonus_db = $bonus !== '' ? (float)$bonus : null;
                $other_benefits_db = nullIfEmpty($other_benefits);
                $terms_conditions_db = nullIfEmpty($terms_conditions);

                $update_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE offers
                     SET
                        offer_date = ?,
                        offer_valid_till = ?,
                        expected_joining_date = ?,
                        designation = ?,
                        department = ?,
                        employment_type = ?,
                        ctc = ?,
                        basic_salary = ?,
                        hra = ?,
                        conveyance = ?,
                        medical = ?,
                        special_allowance = ?,
                        bonus = ?,
                        other_benefits = ?,
                        terms_conditions = ?
                     WHERE id = ?
                     AND status = 'Draft'
                     LIMIT 1"
                );

                if ($update_stmt) {

                    mysqli_stmt_bind_param(
                        $update_stmt,
                        "ssssssdddddddssi",
                        $offer_date,
                        $offer_valid_till,
                        $expected_joining_date,
                        $designation,
                        $department,
                        $employment_type,
                        $ctc_db,
                        $basic_salary_db,
                        $hra_db,
                        $conveyance_db,
                        $medical_db,
                        $special_allowance_db,
                        $bonus_db,
                        $other_benefits_db,
                        $terms_conditions_db,
                        $offer_id
                    );

                    if (mysqli_stmt_execute($update_stmt)) {

                        if (mysqli_stmt_affected_rows($update_stmt) > 0) {

                            if (function_exists('logActivity')) {
                                logActivity(
                                    $conn,
                                    'UPDATE',
                                    'offer',
                                    "Updated offer ID: {$offer_id}",
                                    $offer_id,
                                    null,
                                    null,
                                    json_encode($_POST)
                                );
                            }

                            $message = "Offer updated successfully!";
                            $messageType = "success";

                        } else {
                            $message = "Offer not updated. Only Draft offers can be edited.";
                            $messageType = "warning";
                        }

                    } else {
                        $message = "Error updating offer: " . mysqli_stmt_error($update_stmt);
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
    }

    /* ---------- SEND OFFER ---------- */

    elseif ($action === 'send_offer') {

        $offer_id = (int)($_POST['offer_id'] ?? 0);

        if ($offer_id <= 0) {
            $validation_errors[] = "Invalid offer selected";
        }

        if (empty($validation_errors)) {

            $sent_by_name = $current_employee['full_name'] ?? '';

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE offers
                 SET
                    status = 'Sent',
                    sent_date = CURDATE(),
                    sent_by = ?,
                    sent_by_name = ?
                 WHERE id = ?
                 AND status = 'Approved'
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "isi",
                    $current_employee_id,
                    $sent_by_name,
                    $offer_id
                );

                if (mysqli_stmt_execute($update_stmt)) {

                    if (mysqli_stmt_affected_rows($update_stmt) > 0) {

                        if (function_exists('logActivity')) {
                            logActivity(
                                $conn,
                                'UPDATE',
                                'offer',
                                "Sent offer to candidate",
                                $offer_id,
                                null,
                                null,
                                null
                            );
                        }

                        $message = "Offer sent to candidate successfully!";
                        $messageType = "success";

                    } else {
                        $message = "Offer cannot be sent. Only Approved offers can be sent.";
                        $messageType = "warning";
                    }

                } else {
                    $message = "Error sending offer: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }
        } else {
            $messageType = "warning";
        }
    }

    /* ---------- ACCEPT OFFER ---------- */

    elseif ($action === 'accept_offer') {

        $offer_id = (int)($_POST['offer_id'] ?? 0);
        $response_remarks = trim($_POST['response_remarks'] ?? '');

        if ($offer_id <= 0) {
            $validation_errors[] = "Invalid offer selected";
        }

        if (empty($validation_errors)) {

            mysqli_begin_transaction($conn);

            try {

                $update_stmt = mysqli_prepare(
                    $conn,
                    "UPDATE offers
                     SET
                        status = 'Accepted',
                        response_date = CURDATE(),
                        response_remarks = ?,
                        accepted_by_candidate = 1
                     WHERE id = ?
                     AND status = 'Sent'
                     LIMIT 1"
                );

                if (!$update_stmt) {
                    throw new Exception(mysqli_error($conn));
                }

                $response_remarks_db = nullIfEmpty($response_remarks);

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "si",
                    $response_remarks_db,
                    $offer_id
                );

                if (!mysqli_stmt_execute($update_stmt)) {
                    throw new Exception(mysqli_stmt_error($update_stmt));
                }

                if (mysqli_stmt_affected_rows($update_stmt) <= 0) {
                    throw new Exception("Offer cannot be accepted. Only Sent offers can be accepted.");
                }

                mysqli_stmt_close($update_stmt);

                $candidate_id = 0;

                $cand_stmt = mysqli_prepare(
                    $conn,
                    "SELECT candidate_id
                     FROM offers
                     WHERE id = ?
                     LIMIT 1"
                );

                if (!$cand_stmt) {
                    throw new Exception(mysqli_error($conn));
                }

                mysqli_stmt_bind_param($cand_stmt, "i", $offer_id);
                mysqli_stmt_execute($cand_stmt);
                mysqli_stmt_bind_result($cand_stmt, $candidate_id);
                mysqli_stmt_fetch($cand_stmt);
                mysqli_stmt_close($cand_stmt);

                if ($candidate_id > 0) {

                    $candidate_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE candidates
                         SET status = 'Accepted'
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($candidate_stmt) {
                        mysqli_stmt_bind_param($candidate_stmt, "i", $candidate_id);
                        mysqli_stmt_execute($candidate_stmt);
                        mysqli_stmt_close($candidate_stmt);
                    }
                }

                mysqli_commit($conn);

                if (function_exists('logActivity')) {
                    logActivity(
                        $conn,
                        'UPDATE',
                        'offer',
                        "Offer accepted by candidate",
                        $offer_id,
                        null,
                        null,
                        json_encode(['remarks' => $response_remarks])
                    );
                }

                $message = "Offer marked as accepted!";
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

    /* ---------- DECLINE OFFER ---------- */

    elseif ($action === 'decline_offer') {

        $offer_id = (int)($_POST['offer_id'] ?? 0);
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');

        if ($offer_id <= 0) {
            $validation_errors[] = "Invalid offer selected";
        }

        if ($rejection_reason === '') {
            $validation_errors[] = "Reason for decline is required";
        }

        if (empty($validation_errors)) {

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE offers
                 SET
                    status = 'Rejected',
                    response_date = CURDATE(),
                    rejection_reason = ?
                 WHERE id = ?
                 AND status = 'Sent'
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "si",
                    $rejection_reason,
                    $offer_id
                );

                if (mysqli_stmt_execute($update_stmt)) {

                    if (mysqli_stmt_affected_rows($update_stmt) > 0) {

                        if (function_exists('logActivity')) {
                            logActivity(
                                $conn,
                                'UPDATE',
                                'offer',
                                "Offer declined by candidate",
                                $offer_id,
                                null,
                                null,
                                json_encode(['reason' => $rejection_reason])
                            );
                        }

                        $message = "Offer marked as declined.";
                        $messageType = "success";

                    } else {
                        $message = "Offer cannot be declined. Only Sent offers can be declined.";
                        $messageType = "warning";
                    }

                } else {
                    $message = "Error updating offer: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }

        } else {
            $messageType = "warning";
        }
    }

    /* ---------- WITHDRAW OFFER ---------- */

    elseif ($action === 'withdraw_offer') {

        $offer_id = (int)($_POST['offer_id'] ?? 0);
        $withdraw_reason = trim($_POST['withdraw_reason'] ?? '');

        if ($offer_id <= 0) {
            $validation_errors[] = "Invalid offer selected";
        }

        if ($withdraw_reason === '') {
            $validation_errors[] = "Reason for withdrawal is required";
        }

        if (empty($validation_errors)) {

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE offers
                 SET
                    status = 'Withdrawn',
                    rejection_reason = ?
                 WHERE id = ?
                 AND status IN ('Draft', 'Approved', 'Sent')
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "si",
                    $withdraw_reason,
                    $offer_id
                );

                if (mysqli_stmt_execute($update_stmt)) {

                    if (mysqli_stmt_affected_rows($update_stmt) > 0) {

                        if (function_exists('logActivity')) {
                            logActivity(
                                $conn,
                                'UPDATE',
                                'offer',
                                "Offer withdrawn",
                                $offer_id,
                                null,
                                null,
                                json_encode(['reason' => $withdraw_reason])
                            );
                        }

                        $message = "Offer withdrawn successfully.";
                        $messageType = "success";

                    } else {
                        $message = "Offer cannot be withdrawn in its current status.";
                        $messageType = "warning";
                    }

                } else {
                    $message = "Error withdrawing offer: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }

        } else {
            $messageType = "warning";
        }
    }

    /* ---------- DELETE / SOFT DELETE OFFER ---------- */

    elseif ($action === 'delete_offer') {

        $offer_id = (int)($_POST['offer_id'] ?? 0);

        if ($offer_id <= 0) {
            $validation_errors[] = "Invalid offer selected";
        }

        if (empty($validation_errors)) {

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE offers
                 SET status = 'Withdrawn'
                 WHERE id = ?
                 AND status = 'Draft'
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param($update_stmt, "i", $offer_id);

                if (mysqli_stmt_execute($update_stmt)) {

                    if (mysqli_stmt_affected_rows($update_stmt) > 0) {

                        if (function_exists('logActivity')) {
                            logActivity(
                                $conn,
                                'SOFT_DELETE',
                                'offer',
                                "Deleted/withdrawn offer ID: {$offer_id}",
                                $offer_id,
                                null,
                                null,
                                null
                            );
                        }

                        $message = "Offer deleted successfully.";
                        $messageType = "success";

                    } else {
                        $message = "Only Draft offers can be deleted.";
                        $messageType = "warning";
                    }

                } else {
                    $message = "Error deleting offer: " . mysqli_stmt_error($update_stmt);
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
$candidate_filter = isset($_GET['candidate_id']) ? (int)$_GET['candidate_id'] : 0;
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

if (!array_key_exists($status_filter, $status_options)) {
    $status_filter = 'all';
}

/* ---------------- SELECTED CANDIDATES FOR CREATE MODAL ---------------- */

$candidates_query = "
    SELECT
        c.id,
        c.first_name,
        c.last_name,
        c.candidate_code,
        c.status,
        h.id AS hiring_id,
        h.position_title,
        h.department,
        h.designation
    FROM candidates c
    JOIN hiring_requests h
    ON c.hiring_request_id = h.id
    WHERE c.status IN ('Selected', 'Interviewed')
";

$candidates_params = [];
$candidates_types = "";

if (!$isHr && !$isAdmin && $isManager) {
    $candidates_query .= " AND h.requested_by = ?";
    $candidates_params[] = $current_employee_id;
    $candidates_types .= "i";
}

$candidates_query .= "
    ORDER BY c.created_at DESC
";

$selected_candidates = [];

$stmtCandidates = mysqli_prepare($conn, $candidates_query);

if ($stmtCandidates) {

    if (!empty($candidates_params)) {
        mysqli_stmt_bind_param(
            $stmtCandidates,
            $candidates_types,
            ...$candidates_params
        );
    }

    mysqli_stmt_execute($stmtCandidates);

    $resCandidates = mysqli_stmt_get_result($stmtCandidates);

    $selected_candidates = mysqli_fetch_all($resCandidates, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtCandidates);
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
        c.current_ctc AS candidate_current_ctc,
        c.expected_ctc AS candidate_expected_ctc,
        c.notice_period,
        c.current_company,
        CONCAT(c.first_name, ' ', c.last_name) AS candidate_name,
        h.id AS hiring_request_id,
        h.request_no,
        h.position_title,
        h.department AS hiring_department,
        h.designation AS hiring_designation,
        h.location AS job_location,
        h.experience_min,
        h.experience_max,
        h.salary_min,
        h.salary_max,
        h.requested_by,
        h.requested_by_name,
        req_emp.full_name AS requester_name,
        req_emp.designation AS requester_designation,
        approver.full_name AS approver_full_name,
        sender.full_name AS sender_full_name,
        ob.id AS onboarding_id,
        ob.status AS onboarding_status,
        ob.employee_code
    FROM offers o
    JOIN candidates c
    ON o.candidate_id = c.id
    JOIN hiring_requests h
    ON o.hiring_request_id = h.id
    LEFT JOIN employees req_emp
    ON h.requested_by = req_emp.id
    LEFT JOIN employees approver
    ON o.approved_by = approver.id
    LEFT JOIN employees sender
    ON o.sent_by = sender.id
    LEFT JOIN onboarding ob
    ON ob.candidate_id = c.id
    WHERE 1 = 1
";

$params = [];
$types = "";

if ($status_filter !== 'all') {
    $query .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($candidate_filter > 0) {
    $query .= " AND o.candidate_id = ?";
    $params[] = $candidate_filter;
    $types .= "i";
}

if ($date_from !== '') {
    $query .= " AND DATE(o.offer_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to !== '') {
    $query .= " AND DATE(o.offer_date) <= ?";
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

if (!$isHr && !$isAdmin && $isManager) {
    $query .= " AND h.requested_by = ?";
    $params[] = $current_employee_id;
    $types .= "i";
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
        COUNT(*) AS total_count,
        SUM(CASE WHEN o.status = 'Draft' THEN 1 ELSE 0 END) AS draft_count,
        SUM(CASE WHEN o.status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN o.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN o.status = 'Sent' THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN o.status = 'Accepted' THEN 1 ELSE 0 END) AS accepted_count,
        SUM(CASE WHEN o.status = 'Expired' THEN 1 ELSE 0 END) AS expired_count,
        SUM(CASE WHEN o.status = 'Withdrawn' THEN 1 ELSE 0 END) AS withdrawn_count,
        AVG(CASE WHEN o.status IN ('Approved', 'Sent', 'Accepted') THEN o.ctc END) AS avg_ctc,
        SUM(CASE WHEN o.status = 'Accepted' THEN 1 ELSE 0 END) AS hired_count
    FROM offers o
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
    'draft_count' => 0,
    'approved_count' => 0,
    'rejected_count' => 0,
    'sent_count' => 0,
    'accepted_count' => 0,
    'expired_count' => 0,
    'withdrawn_count' => 0,
    'avg_ctc' => null,
    'hired_count' => 0
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

<title>Offer Management - TEK-C Hiring</title>

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

.offers-wrapper{
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

.approval-btn{
    background:#10b981;
}

.approval-btn:hover{
    background:#059669;
}

.export-btn{
    background:#64748b;
}

.export-btn:hover{
    background:#475569;
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
.gray{ background:#6b7280; }
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
.edit-btn{ color:#15803d; background:#dcfce7; }
.send-btn{ color:#2563eb; background:#eff6ff; }
.accept-btn{ color:#15803d; background:#dcfce7; }
.decline-btn{ color:#b91c1c; background:#fee2e2; }
.withdraw-btn{ color:#b45309; background:#fef3c7; }
.delete-btn{ color:#b91c1c; background:#fee2e2; }

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

.offer-modal{
    max-width:min(1180px, calc(100vw - 24px));
    margin:12px auto;
}

.offer-modal .modal-content{
    height:calc(100vh - 24px);
    max-height:calc(100vh - 24px);
    border-radius:18px;
    overflow:hidden;
    border:0;
    box-shadow:var(--shadow);
}

.offer-modal .modal-header{
    flex:0 0 auto;
    padding:14px 18px;
}

.offer-modal .modal-body{
    flex:1 1 auto;
    overflow-y:auto;
    padding:16px;
}

.offer-modal .modal-footer{
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

    .offer-modal{
        width:100%;
        max-width:100%;
        height:100%;
        margin:0;
    }

    .offer-modal .modal-content{
        height:100vh;
        max-height:100vh;
        border-radius:0;
    }

    .offer-modal .modal-body{
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

<div class="container-fluid offers-wrapper px-0">

<!-- PAGE HEADING -->

<div class="page-heading">

<div>

<h1>
Offer Management
</h1>

<p>
Create, track and manage employment offers
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
Create Offer
</button>

<?php endif; ?>

<a
href="offer-approval.php"
class="primary-btn approval-btn"
>
<i class="bi bi-check-circle"></i>
Approval Queue
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

<div class="stat-ic blue">
<i class="bi bi-files"></i>
</div>

<div>

<div class="stat-label">
Total
</div>

<div class="stat-value">
<?php echo (int)($stats['total_count'] ?? 0); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-4 col-xl-2">

<div class="stat-card">

<div class="stat-ic gray">
<i class="bi bi-pencil"></i>
</div>

<div>

<div class="stat-label">
Draft
</div>

<div class="stat-value">
<?php echo (int)($stats['draft_count'] ?? 0); ?>
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

</div>

<?php if ((float)($stats['avg_ctc'] ?? 0) > 0 || (int)($stats['hired_count'] ?? 0) > 0): ?>

<div class="summary-card">

<div class="row g-3">

<div class="col-12 col-md-4 summary-stat">

<div class="summary-stat-value">
<?php echo e(formatCurrency($stats['avg_ctc'] ?? 0)); ?>
</div>

<div class="summary-stat-label">
Average Offer CTC
</div>

</div>

<div class="col-12 col-md-4 summary-stat">

<div class="summary-stat-value">
<?php echo (int)($stats['hired_count'] ?? 0); ?>
</div>

<div class="summary-stat-label">
Candidates Hired
</div>

</div>

<div class="col-12 col-md-4 summary-stat">

<div class="summary-stat-value">
<?php echo (int)($stats['accepted_count'] ?? 0); ?>
</div>

<div class="summary-stat-label">
Accepted Offers
</div>

</div>

</div>

</div>

<?php endif; ?>

<!-- PANEL -->

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
All Offers
</h3>

<div class="panel-subtitle">
Compact responsive offer directory
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

<?php foreach ($status_options as $value => $label): ?>

<option
value="<?php echo e($value); ?>"
<?php echo $status_filter === $value ? 'selected' : ''; ?>
>
<?php echo e($label); ?>
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
href="offers.php"
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

<th>Offer Details</th>
<th>Candidate</th>
<th>Position</th>
<th>CTC</th>
<th>Status</th>
<th>Timeline</th>
<th class="text-end">Actions</th>

</tr>

</thead>

<tbody>

<?php if (empty($offers)): ?>

<tr class="no-record-row">

<td colspan="7">

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
$documentSrc = fileUrl($offer['offer_document'] ?? '');

[$statusLabel, $statusClass, $statusKey] =
    offerStatusBadge($offer['status'] ?? '');

$offerJson =
    htmlspecialchars(
        json_encode($offer),
        ENT_QUOTES,
        'UTF-8'
    );

?>

<tr data-status="<?php echo e($statusKey); ?>">

<td data-label="Offer Details">

<div class="table-primary-text">
<?php echo e($offer['offer_no'] ?? ''); ?>
</div>

<div class="table-secondary-text">
<i class="bi bi-calendar me-1"></i>
Created:
<?php echo e(safeDate($offer['offer_date'] ?? '')); ?>
</div>

<?php if (!empty($offer['offer_valid_till'])): ?>

<div class="table-secondary-text">
<i class="bi bi-hourglass me-1"></i>
Valid till:
<?php echo e(safeDate($offer['offer_valid_till'])); ?>
</div>

<?php endif; ?>

<?php if (!empty($offer['expected_joining_date'])): ?>

<div class="table-secondary-text">
<i class="bi bi-person-check me-1"></i>
Joining:
<?php echo e(safeDate($offer['expected_joining_date'])); ?>
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

<?php if (!empty($offer['hiring_department'])): ?>

<span class="department-tag">
<i class="bi bi-building"></i>
<?php echo e($offer['hiring_department']); ?>
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

<?php if (!empty($offer['candidate_expected_ctc'])): ?>

<div class="table-secondary-text">
Expected:
<?php echo e(formatCurrency($offer['candidate_expected_ctc'])); ?>
</div>

<?php endif; ?>

<?php if (!empty($offer['candidate_current_ctc'])): ?>

<div class="table-secondary-text">
Current:
<?php echo e(formatCurrency($offer['candidate_current_ctc'])); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Status">

<span class="badge-pill <?php echo e($statusClass); ?>">

<span class="mini-dot"></span>

<?php echo e($statusLabel); ?>

</span>

<?php if (($offer['status'] ?? '') === 'Accepted' && !empty($offer['onboarding_id'])): ?>

<div class="table-secondary-text mt-1">
<i class="bi bi-person-check text-success me-1"></i>
Onboarding:
<?php echo e($offer['onboarding_status'] ?? ''); ?>
</div>

<?php endif; ?>

</td>

<td data-label="Timeline">

<?php if (($offer['status'] ?? '') === 'Approved' && !empty($offer['approved_at'])): ?>

<div class="table-secondary-text">
<i class="bi bi-check-circle text-success me-1"></i>
Approved:
<?php echo e(safeDate($offer['approved_at'])); ?>
</div>

<?php elseif (($offer['status'] ?? '') === 'Sent' && !empty($offer['sent_date'])): ?>

<div class="table-secondary-text">
<i class="bi bi-envelope text-info me-1"></i>
Sent:
<?php echo e(safeDate($offer['sent_date'])); ?>
</div>

<?php elseif (($offer['status'] ?? '') === 'Accepted' && !empty($offer['response_date'])): ?>

<div class="table-secondary-text">
<i class="bi bi-check2-circle text-success me-1"></i>
Accepted:
<?php echo e(safeDate($offer['response_date'])); ?>
</div>

<?php elseif (($offer['status'] ?? '') === 'Rejected' && !empty($offer['response_date'])): ?>

<div class="table-secondary-text">
<i class="bi bi-x-circle text-danger me-1"></i>
Declined:
<?php echo e(safeDate($offer['response_date'])); ?>
</div>

<?php else: ?>

<div class="table-secondary-text">
<i class="bi bi-clock me-1"></i>
Created:
<?php echo e(safeDate($offer['created_at'] ?? '')); ?>
</div>

<?php endif; ?>

<?php if (!empty($offer['sender_full_name'])): ?>

<div class="table-secondary-text">
Sent by:
<?php echo e($offer['sender_full_name']); ?>
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

<?php if ($documentSrc !== ''): ?>

<a
href="<?php echo e($documentSrc); ?>"
target="_blank"
rel="noopener"
class="action-btn info-btn"
title="Offer Document"
>
<i class="bi bi-file-earmark-arrow-down"></i>
</a>

<?php endif; ?>

<?php if (($offer['status'] ?? '') === 'Draft'): ?>

<?php if ($isHr || $isAdmin): ?>

<button
type="button"
class="action-btn edit-btn"
onclick="openEditModal(<?php echo $offerJson; ?>)"
title="Edit Offer"
>
<i class="bi bi-pencil"></i>
</button>

<a
href="offer-approval.php?status=pending"
class="action-btn send-btn"
title="Send for Approval"
>
<i class="bi bi-send"></i>
</a>

<button
type="button"
class="action-btn withdraw-btn"
onclick="openWithdrawModal(<?php echo (int)$offer['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Withdraw"
>
<i class="bi bi-dash-circle"></i>
</button>

<button
type="button"
class="action-btn delete-btn"
onclick="openDeleteModal(<?php echo (int)$offer['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Delete Draft"
>
<i class="bi bi-trash"></i>
</button>

<?php endif; ?>

<?php elseif (($offer['status'] ?? '') === 'Approved'): ?>

<button
type="button"
class="action-btn send-btn"
onclick="openSendModal(<?php echo (int)$offer['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Send to Candidate"
>
<i class="bi bi-envelope"></i>
</button>

<?php elseif (($offer['status'] ?? '') === 'Sent'): ?>

<button
type="button"
class="action-btn accept-btn"
onclick="openAcceptModal(<?php echo (int)$offer['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Mark Accepted"
>
<i class="bi bi-check-lg"></i>
</button>

<button
type="button"
class="action-btn decline-btn"
onclick="openDeclineModal(<?php echo (int)$offer['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Mark Declined"
>
<i class="bi bi-x-lg"></i>
</button>

<button
type="button"
class="action-btn withdraw-btn"
onclick="openWithdrawModal(<?php echo (int)$offer['id']; ?>, '<?php echo e(addslashes($candidateName)); ?>')"
title="Withdraw"
>
<i class="bi bi-dash-circle"></i>
</button>

<?php endif; ?>

<?php if (!empty($offer['onboarding_id'])): ?>

<a
href="view-onboarding.php?id=<?php echo (int)$offer['onboarding_id']; ?>"
class="action-btn accept-btn"
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

<!-- CREATE OFFER MODAL -->

<div
class="modal fade"
id="createOfferModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down offer-modal">

<div class="modal-content">

<form
method="POST"
enctype="multipart/form-data"
class="h-100 d-flex flex-column"
id="createOfferForm"
>

<input
type="hidden"
name="action"
value="create_offer"
>

<div class="modal-header">

<div>

<h5 class="modal-title mb-1">
Create New Offer
</h5>

<div class="text-muted small fw-semibold">
Fill offer details, salary breakdown and terms.
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
Choose selected/interviewed candidate for offer creation
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
Choose candidate...
</option>

<?php foreach ($selected_candidates as $candidate): ?>

<option
value="<?php echo (int)$candidate['id']; ?>"
data-hiring-id="<?php echo (int)$candidate['hiring_id']; ?>"
data-position="<?php echo e($candidate['position_title']); ?>"
data-department="<?php echo e($candidate['department']); ?>"
data-designation="<?php echo e($candidate['designation']); ?>"
>
<?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?>
(<?php echo e($candidate['candidate_code']); ?>)
-
<?php echo e($candidate['position_title']); ?>
</option>

<?php endforeach; ?>

</select>

<input
type="hidden"
name="hiring_request_id"
id="hiring_request_id"
>

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
Offer Dates
</h3>

<p class="section-subtitle">
Offer issue date, validity and joining date
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-4">

<label class="form-label required-label">
Offer Date
</label>

<input
type="date"
name="offer_date"
class="form-control"
value="<?php echo date('Y-m-d'); ?>"
required
>

</div>

<div class="col-md-4">

<label class="form-label required-label">
Valid Till
</label>

<input
type="date"
name="offer_valid_till"
class="form-control"
value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>"
required
>

</div>

<div class="col-md-4">

<label class="form-label required-label">
Expected Joining Date
</label>

<input
type="date"
name="expected_joining_date"
class="form-control"
required
>

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
Auto-filled from candidate hiring request
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-4">

<label class="form-label required-label">
Designation
</label>

<input
type="text"
name="designation"
class="form-control"
id="designation"
required
>

</div>

<div class="col-md-4">

<label class="form-label required-label">
Department
</label>

<input
type="text"
name="department"
class="form-control"
id="department"
required
>

</div>

<div class="col-md-4">

<label class="form-label required-label">
Employment Type
</label>

<select
name="employment_type"
class="form-select"
required
>

<?php foreach ($employment_types as $type): ?>

<option value="<?php echo e($type); ?>">
<?php echo e($type); ?>
</option>

<?php endforeach; ?>

</select>

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#8e44ad;">
<i class="bi bi-cash-stack"></i>
</div>

<div>

<h3 class="section-title">
Compensation
</h3>

<p class="section-subtitle">
Total CTC and optional salary components
</p>

</div>

</div>

<div class="row g-3">

<div class="col-12">

<label class="form-label required-label">
Total CTC
<span class="optional-badge">₹ LPA</span>
</label>

<input
type="number"
step="0.01"
min="0"
name="ctc"
class="form-control"
required
>

</div>

<div class="col-md-4">

<label class="form-label">
Basic Salary
<span class="optional-badge">(Optional)</span>
</label>

<input
type="number"
step="0.01"
min="0"
name="basic_salary"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
HRA
<span class="optional-badge">(Optional)</span>
</label>

<input
type="number"
step="0.01"
min="0"
name="hra"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Conveyance
<span class="optional-badge">(Optional)</span>
</label>

<input
type="number"
step="0.01"
min="0"
name="conveyance"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Medical Allowance
<span class="optional-badge">(Optional)</span>
</label>

<input
type="number"
step="0.01"
min="0"
name="medical"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Special Allowance
<span class="optional-badge">(Optional)</span>
</label>

<input
type="number"
step="0.01"
min="0"
name="special_allowance"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Bonus / Performance Pay
<span class="optional-badge">(Optional)</span>
</label>

<input
type="number"
step="0.01"
min="0"
name="bonus"
class="form-control"
>

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#f59e0b;">
<i class="bi bi-file-earmark-text"></i>
</div>

<div>

<h3 class="section-title">
Terms & Documents
</h3>

<p class="section-subtitle">
Benefits, offer terms and optional offer document
</p>

</div>

</div>

<div class="row g-3">

<div class="col-12">

<label class="form-label">
Other Benefits
<span class="optional-badge">(Optional)</span>
</label>

<textarea
name="other_benefits"
class="form-control"
rows="2"
placeholder="Example: Health insurance, laptop, travel allowance..."
></textarea>

</div>

<div class="col-12">

<label class="form-label">
Terms & Conditions
<span class="optional-badge">(Optional)</span>
</label>

<textarea
name="terms_conditions"
class="form-control"
rows="3"
></textarea>

</div>

<div class="col-12">

<label class="form-label">
Offer Letter Document
<span class="optional-badge">PDF/DOC/DOCX, Max 10MB</span>
</label>

<input
type="file"
name="offer_document"
class="form-control"
accept=".pdf,.doc,.docx"
>

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
<i class="bi bi-plus-lg me-1"></i>
Create Offer
</button>

</div>

</form>

</div>

</div>

</div>

<!-- EDIT OFFER MODAL -->

<div
class="modal fade"
id="editOfferModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down offer-modal">

<div class="modal-content">

<form
method="POST"
class="h-100 d-flex flex-column"
>

<input
type="hidden"
name="action"
value="update_offer"
>

<input
type="hidden"
name="offer_id"
id="edit_offer_id"
>

<div class="modal-header">

<div>

<h5 class="modal-title mb-1">
Edit Offer
</h5>

<div class="text-muted small fw-semibold">
Only Draft offers can be edited.
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

<div class="section-icon" style="background:#2563eb;">
<i class="bi bi-calendar-event"></i>
</div>

<div>

<h3 class="section-title">
Offer Dates
</h3>

<p class="section-subtitle">
Update date, validity and joining date
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-4">

<label class="form-label required-label">
Offer Date
</label>

<input
type="date"
name="offer_date"
id="edit_offer_date"
class="form-control"
required
>

</div>

<div class="col-md-4">

<label class="form-label required-label">
Valid Till
</label>

<input
type="date"
name="offer_valid_till"
id="edit_valid_till"
class="form-control"
required
>

</div>

<div class="col-md-4">

<label class="form-label required-label">
Expected Joining Date
</label>

<input
type="date"
name="expected_joining_date"
id="edit_joining_date"
class="form-control"
required
>

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
Edit designation, department and employment type
</p>

</div>

</div>

<div class="row g-3">

<div class="col-md-4">

<label class="form-label required-label">
Designation
</label>

<input
type="text"
name="designation"
id="edit_designation"
class="form-control"
required
>

</div>

<div class="col-md-4">

<label class="form-label required-label">
Department
</label>

<input
type="text"
name="department"
id="edit_department"
class="form-control"
required
>

</div>

<div class="col-md-4">

<label class="form-label required-label">
Employment Type
</label>

<select
name="employment_type"
id="edit_employment_type"
class="form-select"
required
>

<?php foreach ($employment_types as $type): ?>

<option value="<?php echo e($type); ?>">
<?php echo e($type); ?>
</option>

<?php endforeach; ?>

</select>

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#8e44ad;">
<i class="bi bi-cash-stack"></i>
</div>

<div>

<h3 class="section-title">
Compensation
</h3>

<p class="section-subtitle">
Update CTC and salary components
</p>

</div>

</div>

<div class="row g-3">

<div class="col-12">

<label class="form-label required-label">
Total CTC
<span class="optional-badge">₹ LPA</span>
</label>

<input
type="number"
step="0.01"
min="0"
name="ctc"
id="edit_ctc"
class="form-control"
required
>

</div>

<div class="col-md-4">

<label class="form-label">
Basic Salary
</label>

<input
type="number"
step="0.01"
min="0"
name="basic_salary"
id="edit_basic"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
HRA
</label>

<input
type="number"
step="0.01"
min="0"
name="hra"
id="edit_hra"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Conveyance
</label>

<input
type="number"
step="0.01"
min="0"
name="conveyance"
id="edit_conveyance"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Medical Allowance
</label>

<input
type="number"
step="0.01"
min="0"
name="medical"
id="edit_medical"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Special Allowance
</label>

<input
type="number"
step="0.01"
min="0"
name="special_allowance"
id="edit_special"
class="form-control"
>

</div>

<div class="col-md-4">

<label class="form-label">
Bonus / Performance Pay
</label>

<input
type="number"
step="0.01"
min="0"
name="bonus"
id="edit_bonus"
class="form-control"
>

</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">

<div class="section-icon" style="background:#f59e0b;">
<i class="bi bi-file-earmark-text"></i>
</div>

<div>

<h3 class="section-title">
Terms
</h3>

<p class="section-subtitle">
Benefits and terms conditions
</p>

</div>

</div>

<div class="row g-3">

<div class="col-12">

<label class="form-label">
Other Benefits
</label>

<textarea
name="other_benefits"
id="edit_benefits"
class="form-control"
rows="2"
></textarea>

</div>

<div class="col-12">

<label class="form-label">
Terms & Conditions
</label>

<textarea
name="terms_conditions"
id="edit_terms"
class="form-control"
rows="3"
></textarea>

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
Update Offer
</button>

</div>

</form>

</div>

</div>

</div>

<!-- SEND OFFER MODAL -->

<div
class="modal fade"
id="sendModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="send_offer"
>

<input
type="hidden"
name="offer_id"
id="send_offer_id"
>

<div class="modal-header">

<h5 class="modal-title">
Send Offer to Candidate
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Are you sure you want to send this offer to
<strong id="send_candidate_name"></strong>?
</p>

<div class="alert alert-info mb-0" style="box-shadow:none;">

<i class="bi bi-info-circle me-2"></i>
The offer will be marked as Sent.

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
Send Offer
</button>

</div>

</form>

</div>

</div>

</div>

<!-- ACCEPT OFFER MODAL -->

<div
class="modal fade"
id="acceptModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="accept_offer"
>

<input
type="hidden"
name="offer_id"
id="accept_offer_id"
>

<div class="modal-header">

<h5 class="modal-title">
Accept Offer
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Mark offer as accepted by
<strong id="accept_candidate_name"></strong>?
</p>

<div class="mb-3">

<label class="form-label">
Response Remarks
<span class="optional-badge">(Optional)</span>
</label>

<textarea
name="response_remarks"
class="form-control"
rows="2"
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
class="btn btn-success"
>
Accept Offer
</button>

</div>

</form>

</div>

</div>

</div>

<!-- DECLINE OFFER MODAL -->

<div
class="modal fade"
id="declineModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="decline_offer"
>

<input
type="hidden"
name="offer_id"
id="decline_offer_id"
>

<div class="modal-header">

<h5 class="modal-title">
Decline Offer
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Mark offer as declined by
<strong id="decline_candidate_name"></strong>?
</p>

<div class="mb-3">

<label class="form-label required-label">
Reason for Decline
</label>

<textarea
name="rejection_reason"
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
Decline Offer
</button>

</div>

</form>

</div>

</div>

</div>

<!-- WITHDRAW OFFER MODAL -->

<div
class="modal fade"
id="withdrawModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="withdraw_offer"
>

<input
type="hidden"
name="offer_id"
id="withdraw_offer_id"
>

<div class="modal-header">

<h5 class="modal-title">
Withdraw Offer
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Are you sure you want to withdraw the offer for
<strong id="withdraw_candidate_name"></strong>?
</p>

<div class="mb-3">

<label class="form-label required-label">
Reason for Withdrawal
</label>

<textarea
name="withdraw_reason"
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
Withdraw Offer
</button>

</div>

</form>

</div>

</div>

</div>

<!-- DELETE OFFER MODAL -->

<div
class="modal fade"
id="deleteModal"
tabindex="-1"
>

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">

<input
type="hidden"
name="action"
value="delete_offer"
>

<input
type="hidden"
name="offer_id"
id="delete_offer_id"
>

<div class="modal-header">

<h5 class="modal-title">
Delete Draft Offer
</h5>

<button
type="button"
class="btn-close"
data-bs-dismiss="modal"
></button>

</div>

<div class="modal-body">

<p>
Delete draft offer for
<strong id="delete_candidate_name"></strong>?
</p>

<div class="alert alert-warning mb-0" style="box-shadow:none;">

<i class="bi bi-exclamation-triangle me-2"></i>
This will mark the draft offer as Withdrawn.

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
Delete Offer
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
Export Offers
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
CSV exports the currently displayed rows. Server Export redirects to export-offers.php with current filters.

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
        document.querySelectorAll('#offersTable tbody tr:not(.no-record-row)');

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

    const candidateSelect =
        document.getElementById('candidate_select');

    if (candidateSelect) {

        candidateSelect.addEventListener('change', function(){

            const selected =
                this.options[this.selectedIndex];

            const hiringId =
                selected.getAttribute('data-hiring-id') || '';

            const position =
                selected.getAttribute('data-position') || '';

            const department =
                selected.getAttribute('data-department') || '';

            const designation =
                selected.getAttribute('data-designation') || '';

            document.getElementById('hiring_request_id').value =
                hiringId;

            document.getElementById('designation').value =
                designation || position;

            document.getElementById('department').value =
                department;
        });
    }
});

function openCreateModal(){

    new bootstrap.Modal(
        document.getElementById('createOfferModal')
    ).show();
}

function openEditModal(offer){

    document.getElementById('edit_offer_id').value =
        offer.id || '';

    document.getElementById('edit_offer_date').value =
        offer.offer_date || '';

    document.getElementById('edit_valid_till').value =
        offer.offer_valid_till || '';

    document.getElementById('edit_joining_date').value =
        offer.expected_joining_date || '';

    document.getElementById('edit_designation').value =
        offer.designation || '';

    document.getElementById('edit_department').value =
        offer.department || offer.hiring_department || '';

    document.getElementById('edit_employment_type').value =
        offer.employment_type || 'Full-time';

    document.getElementById('edit_ctc').value =
        offer.ctc || '';

    document.getElementById('edit_basic').value =
        offer.basic_salary || '';

    document.getElementById('edit_hra').value =
        offer.hra || '';

    document.getElementById('edit_conveyance').value =
        offer.conveyance || '';

    document.getElementById('edit_medical').value =
        offer.medical || '';

    document.getElementById('edit_special').value =
        offer.special_allowance || '';

    document.getElementById('edit_bonus').value =
        offer.bonus || '';

    document.getElementById('edit_benefits').value =
        offer.other_benefits || '';

    document.getElementById('edit_terms').value =
        offer.terms_conditions || '';

    new bootstrap.Modal(
        document.getElementById('editOfferModal')
    ).show();
}

function openSendModal(id, candidateName){

    document.getElementById('send_offer_id').value =
        id;

    document.getElementById('send_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('sendModal')
    ).show();
}

function openAcceptModal(id, candidateName){

    document.getElementById('accept_offer_id').value =
        id;

    document.getElementById('accept_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('acceptModal')
    ).show();
}

function openDeclineModal(id, candidateName){

    document.getElementById('decline_offer_id').value =
        id;

    document.getElementById('decline_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('declineModal')
    ).show();
}

function openWithdrawModal(id, candidateName){

    document.getElementById('withdraw_offer_id').value =
        id;

    document.getElementById('withdraw_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('withdrawModal')
    ).show();
}

function openDeleteModal(id, candidateName){

    document.getElementById('delete_offer_id').value =
        id;

    document.getElementById('delete_candidate_name').textContent =
        candidateName;

    new bootstrap.Modal(
        document.getElementById('deleteModal')
    ).show();
}

function handleExport(){

    const format =
        document.getElementById('exportFormat').value;

    if (format === 'server') {
        window.location.href =
            'export-offers.php?' + window.location.search.substring(1);
        return;
    }

    exportToCSV();
}

function exportToCSV(){

    const rows =
        document.querySelectorAll('#offersTable tbody tr:not(.no-record-row)');

    const csv =
        [];

    const headers = [
        'Offer Details',
        'Candidate',
        'Position',
        'CTC',
        'Status',
        'Timeline'
    ];

    csv.push(headers.join(','));

    rows.forEach(function(row){

        if (row.style.display === 'none') {
            return;
        }

        const cells =
            row.querySelectorAll('td');

        if (cells.length < 6) {
            return;
        }

        const rowData = [
            cells[0]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[1]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[2]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[3]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[4]?.innerText.replace(/\s+/g, ' ').trim() || '',
            cells[5]?.innerText.replace(/\s+/g, ' ').trim() || ''
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
        'offers_<?php echo date('Y-m-d'); ?>.csv';

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