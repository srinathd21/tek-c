<?php
// candidates.php
// TEK-C compact table section style + fixed full Add Candidate modal

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
$department  = strtolower(trim((string)($current_employee['department'] ?? '')));

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

if (!$isHr && !$isManager) {
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

    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateTime($v, $dash = '—'){

    $v = trim((string)$v);

    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function getFullName($first, $last){
    return trim((string)$first . ' ' . (string)$last);
}

function getInitials($first, $last){

    $a = strtoupper(substr(trim((string)$first), 0, 1));
    $b = strtoupper(substr(trim((string)$last), 0, 1));

    $initials = $a . $b;

    return $initials !== '' ? $initials : 'C';
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

function candidateStatusBadge($status){

    $status = trim((string)$status);

    $map = [
        'New' => ['New', 'info', 'new'],
        'Screening' => ['Screening', 'muted', 'screening'],
        'Shortlisted' => ['Shortlisted', 'purple-soft', 'shortlisted'],
        'Interview Scheduled' => ['Interview Scheduled', 'warning', 'interview scheduled'],
        'Interviewed' => ['Interviewed', 'ontrack', 'interviewed'],
        'Selected' => ['Selected', 'ontrack', 'selected'],
        'Rejected' => ['Rejected', 'danger', 'rejected'],
        'On Hold' => ['On Hold', 'warning', 'on hold'],
        'Offered' => ['Offered', 'ontrack', 'offered'],
        'Joined' => ['Joined', 'ontrack', 'joined'],
        'Declined' => ['Declined', 'danger', 'declined']
    ];

    return $map[$status] ?? [$status ?: 'Unknown', 'muted', strtolower($status ?: 'unknown')];
}

function getAdminFsBase(){

    $adminDir = __DIR__ . '/../admin';

    if (!is_dir($adminDir)) {
        @mkdir($adminDir, 0777, true);
    }

    $real = realpath($adminDir);

    return $real ?: $adminDir;
}

function handleUploadFile($field_name, $subdir, $allowed_extensions, $max_size_mb = 5){

    if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
        return [
            'success' => false,
            'path' => '',
            'fs_path' => '',
            'error' => ''
        ];
    }

    $file = $_FILES[$field_name];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'error' => 'Upload error for ' . $field_name
        ];
    }

    $size = (int)($file['size'] ?? 0);

    if ($size <= 0) {
        return [
            'success' => false,
            'error' => 'Invalid file size for ' . $field_name
        ];
    }

    if ($size > ($max_size_mb * 1024 * 1024)) {
        return [
            'success' => false,
            'error' => 'File too large for ' . $field_name . '. Max ' . $max_size_mb . 'MB allowed'
        ];
    }

    $original_name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_extensions, true)) {
        return [
            'success' => false,
            'error' => 'Invalid file type for ' . $field_name
        ];
    }

    $adminFsBase = getAdminFsBase();

    $subdir = trim($subdir, '/') . '/';

    $uploadFsDir =
        rtrim($adminFsBase, "/\\") .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $subdir);

    if (!is_dir($uploadFsDir)) {
        if (!@mkdir($uploadFsDir, 0777, true)) {
            return [
                'success' => false,
                'error' => 'Failed to create upload directory'
            ];
        }
    }

    try {
        $rand = bin2hex(random_bytes(6));
    } catch (Throwable $t) {
        $rand = uniqid();
    }

    $newName =
        $field_name .
        '_' .
        time() .
        '_' .
        $rand .
        '.' .
        $ext;

    $targetFs =
        rtrim($uploadFsDir, "/\\") .
        DIRECTORY_SEPARATOR .
        $newName;

    if (!move_uploaded_file($file['tmp_name'], $targetFs)) {
        return [
            'success' => false,
            'error' => 'Failed to move uploaded file'
        ];
    }

    return [
        'success' => true,
        'path' => 'admin/uploads/' . $subdir . $newName,
        'fs_path' => $targetFs,
        'error' => ''
    ];
}

/* ---------------- OPTIONS ---------------- */

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

$sources = [
    'LinkedIn',
    'Naukri',
    'Indeed',
    'Referral',
    'Company Website',
    'Consultant',
    'Other'
];

/* ---------------- MESSAGES ---------------- */

$message = '';
$messageType = '';
$validation_errors = [];

/* ---------------- POST ACTIONS ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = trim((string)$_POST['action']);

    if ($action === 'add_candidate') {

        if (!$isHr) {

            $message = "Only HR can add candidates.";
            $messageType = "danger";

        } else {

            $hiring_request_id = (int)($_POST['hiring_request_id'] ?? 0);
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $alternate_phone = trim($_POST['alternate_phone'] ?? '');
            $current_location = trim($_POST['current_location'] ?? '');
            $preferred_location = trim($_POST['preferred_location'] ?? '');
            $total_experience = trim($_POST['total_experience'] ?? '');
            $relevant_experience = trim($_POST['relevant_experience'] ?? '');
            $current_ctc = trim($_POST['current_ctc'] ?? '');
            $expected_ctc = trim($_POST['expected_ctc'] ?? '');
            $notice_period = trim($_POST['notice_period'] ?? '');
            $notice_period_negotiable = isset($_POST['notice_period_negotiable']) ? 1 : 0;
            $current_company = trim($_POST['current_company'] ?? '');
            $qualification = trim($_POST['qualification'] ?? '');
            $skills = trim($_POST['skills'] ?? '');
            $source = trim($_POST['source'] ?? 'Other');
            $referred_by = trim($_POST['referred_by'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');

            if ($hiring_request_id <= 0) {
                $validation_errors[] = "Hiring request is required";
            }

            if ($first_name === '') {
                $validation_errors[] = "First name is required";
            }

            if ($last_name === '') {
                $validation_errors[] = "Last name is required";
            }

            if ($email === '') {
                $validation_errors[] = "Email is required";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validation_errors[] = "Invalid email format";
            }

            if ($phone === '') {
                $validation_errors[] = "Phone number is required";
            } elseif (!preg_match('/^[0-9]{10,15}$/', $phone)) {
                $validation_errors[] = "Phone number must be 10 to 15 digits";
            }

            if ($alternate_phone !== '' && !preg_match('/^[0-9]{10,15}$/', $alternate_phone)) {
                $validation_errors[] = "Alternate phone must be 10 to 15 digits";
            }

            if ($source !== '' && !in_array($source, $sources, true)) {
                $validation_errors[] = "Invalid source selected";
            }

            if ($total_experience !== '' && !is_numeric($total_experience)) {
                $validation_errors[] = "Total experience must be a valid number";
            }

            if ($relevant_experience !== '' && !is_numeric($relevant_experience)) {
                $validation_errors[] = "Relevant experience must be a valid number";
            }

            if (
                is_numeric($total_experience) &&
                is_numeric($relevant_experience) &&
                (float)$relevant_experience > (float)$total_experience
            ) {
                $validation_errors[] = "Relevant experience cannot exceed total experience";
            }

            if ($current_ctc !== '' && !is_numeric($current_ctc)) {
                $validation_errors[] = "Current CTC must be a valid number";
            }

            if ($expected_ctc !== '' && !is_numeric($expected_ctc)) {
                $validation_errors[] = "Expected CTC must be a valid number";
            }

            if ($notice_period !== '' && (!ctype_digit((string)$notice_period) || (int)$notice_period < 0)) {
                $validation_errors[] = "Notice period must be a valid number of days";
            }

            if ($hiring_request_id > 0 && empty($validation_errors)) {

                $verifyStmt = mysqli_prepare(
                    $conn,
                    "SELECT id
                     FROM hiring_requests
                     WHERE id = ?
                     AND status IN ('Approved', 'In Progress')
                     LIMIT 1"
                );

                if ($verifyStmt) {
                    mysqli_stmt_bind_param($verifyStmt, "i", $hiring_request_id);
                    mysqli_stmt_execute($verifyStmt);
                    mysqli_stmt_store_result($verifyStmt);

                    if (mysqli_stmt_num_rows($verifyStmt) <= 0) {
                        $validation_errors[] = "Selected hiring request is not valid";
                    }

                    mysqli_stmt_close($verifyStmt);
                }
            }

            $resume_path = '';
            $resume_fs = '';
            $photo_path = '';
            $photo_fs = '';

            if (empty($validation_errors)) {

                if (!empty($_FILES['resume']['name'])) {

                    $resumeUpload = handleUploadFile(
                        'resume',
                        'candidates/resumes',
                        ['pdf', 'doc', 'docx'],
                        8
                    );

                    if (!empty($resumeUpload['success'])) {
                        $resume_path = $resumeUpload['path'];
                        $resume_fs = $resumeUpload['fs_path'];
                    } else {
                        $validation_errors[] = $resumeUpload['error'] ?? 'Resume upload failed';
                    }
                }

                if (!empty($_FILES['photo']['name'])) {

                    $photoUpload = handleUploadFile(
                        'photo',
                        'candidates/photos',
                        ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                        5
                    );

                    if (!empty($photoUpload['success'])) {
                        $photo_path = $photoUpload['path'];
                        $photo_fs = $photoUpload['fs_path'];
                    } else {
                        $validation_errors[] = $photoUpload['error'] ?? 'Photo upload failed';
                    }
                }
            }

            if (empty($validation_errors)) {

                $year = (int)date('Y');
                $count = 0;

                $stmtCount = mysqli_prepare(
                    $conn,
                    "SELECT COUNT(*) AS total_count
                     FROM candidates
                     WHERE YEAR(created_at) = ?"
                );

                if ($stmtCount) {
                    mysqli_stmt_bind_param($stmtCount, "i", $year);
                    mysqli_stmt_execute($stmtCount);

                    $resCount = mysqli_stmt_get_result($stmtCount);
                    $rowCount = mysqli_fetch_assoc($resCount);

                    $count = (int)($rowCount['total_count'] ?? 0);

                    mysqli_stmt_close($stmtCount);
                }

                $candidate_code =
                    "CAN-" .
                    $year .
                    "-" .
                    str_pad($count + 1, 4, '0', STR_PAD_LEFT);

                $alternate_phone_db = nullIfEmpty($alternate_phone);
                $current_location_db = nullIfEmpty($current_location);
                $preferred_location_db = nullIfEmpty($preferred_location);
                $total_experience_db = $total_experience !== '' ? (float)$total_experience : null;
                $relevant_experience_db = $relevant_experience !== '' ? (float)$relevant_experience : null;
                $current_ctc_db = $current_ctc !== '' ? (float)$current_ctc : null;
                $expected_ctc_db = $expected_ctc !== '' ? (float)$expected_ctc : null;
                $notice_period_db = $notice_period !== '' ? (int)$notice_period : null;
                $current_company_db = nullIfEmpty($current_company);
                $qualification_db = nullIfEmpty($qualification);
                $skills_db = nullIfEmpty($skills);
                $referred_by_db = nullIfEmpty($referred_by);
                $remarks_db = nullIfEmpty($remarks);
                $created_by = $current_employee_id;
                $created_by_name = $current_employee['full_name'] ?? '';
                $status = 'New';

                $insertSql = "
                    INSERT INTO candidates
                    (
                        hiring_request_id,
                        candidate_code,
                        first_name,
                        last_name,
                        email,
                        phone,
                        alternate_phone,
                        current_location,
                        preferred_location,
                        total_experience,
                        relevant_experience,
                        current_ctc,
                        expected_ctc,
                        notice_period,
                        notice_period_negotiable,
                        current_company,
                        qualification,
                        skills,
                        resume_path,
                        photo_path,
                        source,
                        referred_by,
                        remarks,
                        status,
                        created_by,
                        created_by_name
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?
                    )
                ";

                $insertStmt = mysqli_prepare($conn, $insertSql);

                if ($insertStmt) {

                    mysqli_stmt_bind_param(
                        $insertStmt,
                        "issssssssddddiisssssssssis",
                        $hiring_request_id,
                        $candidate_code,
                        $first_name,
                        $last_name,
                        $email,
                        $phone,
                        $alternate_phone_db,
                        $current_location_db,
                        $preferred_location_db,
                        $total_experience_db,
                        $relevant_experience_db,
                        $current_ctc_db,
                        $expected_ctc_db,
                        $notice_period_db,
                        $notice_period_negotiable,
                        $current_company_db,
                        $qualification_db,
                        $skills_db,
                        $resume_path,
                        $photo_path,
                        $source,
                        $referred_by_db,
                        $remarks_db,
                        $status,
                        $created_by,
                        $created_by_name
                    );

                    if (mysqli_stmt_execute($insertStmt)) {

                        $candidate_id = mysqli_insert_id($conn);

                        if (function_exists('logActivity')) {
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
                        }

                        $message = "Candidate added successfully!";
                        $messageType = "success";

                        $_POST = [];

                    } else {

                        if ($resume_fs && file_exists($resume_fs)) {
                            @unlink($resume_fs);
                        }

                        if ($photo_fs && file_exists($photo_fs)) {
                            @unlink($photo_fs);
                        }

                        $message = "Error adding candidate: " . mysqli_stmt_error($insertStmt);
                        $messageType = "danger";
                    }

                    mysqli_stmt_close($insertStmt);

                } else {

                    if ($resume_fs && file_exists($resume_fs)) {
                        @unlink($resume_fs);
                    }

                    if ($photo_fs && file_exists($photo_fs)) {
                        @unlink($photo_fs);
                    }

                    $message = "Database error: " . mysqli_error($conn);
                    $messageType = "danger";
                }

            } else {

                if ($resume_fs && file_exists($resume_fs)) {
                    @unlink($resume_fs);
                }

                if ($photo_fs && file_exists($photo_fs)) {
                    @unlink($photo_fs);
                }

                $messageType = "warning";
            }
        }
    }

    elseif ($action === 'update_status') {

        $candidate_id = (int)($_POST['candidate_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($candidate_id <= 0) {

            $message = "Invalid candidate selected.";
            $messageType = "danger";

        } elseif (!array_key_exists($status, $status_options)) {

            $message = "Invalid candidate status selected.";
            $messageType = "danger";

        } else {

            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE candidates
                 SET
                    status = ?,
                    remarks = CONCAT(COALESCE(remarks, ''), '\n[', NOW(), '] ', ?)
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($update_stmt) {

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "ssi",
                    $status,
                    $remarks,
                    $candidate_id
                );

                if (mysqli_stmt_execute($update_stmt)) {

                    if (function_exists('logActivity')) {
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
                    }

                    $message = "Candidate status updated successfully!";
                    $messageType = "success";

                } else {

                    $message = "Error updating status: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            }
        }
    }

    elseif ($action === 'schedule_interview') {

        $candidate_id = (int)($_POST['candidate_id'] ?? 0);
        $hiring_request_id = (int)($_POST['hiring_request_id'] ?? 0);
        $interview_round = trim($_POST['interview_round'] ?? '');
        $round_number = (int)($_POST['round_number'] ?? 1);
        $interview_date = trim($_POST['interview_date'] ?? '');
        $interview_time = trim($_POST['interview_time'] ?? '');
        $interview_duration = (int)($_POST['interview_duration'] ?? 60);
        $interview_mode = trim($_POST['interview_mode'] ?? '');
        $interview_link = trim($_POST['interview_link'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $interviewer_id = (int)($_POST['interviewer_id'] ?? 0);

        $schedule_errors = [];

        if ($candidate_id <= 0) {
            $schedule_errors[] = "Invalid candidate selected";
        }

        if ($hiring_request_id <= 0) {
            $schedule_errors[] = "Invalid hiring request selected";
        }

        if (!array_key_exists($interview_round, $interview_rounds)) {
            $schedule_errors[] = "Invalid interview round selected";
        }

        if ($interview_date === '') {
            $schedule_errors[] = "Interview date is required";
        }

        if ($interview_time === '') {
            $schedule_errors[] = "Interview time is required";
        }

        if (!in_array($interview_mode, ['Online', 'In-Person', 'Telephonic'], true)) {
            $schedule_errors[] = "Invalid interview mode selected";
        }

        if ($interviewer_id <= 0) {
            $schedule_errors[] = "Interviewer is required";
        }

        $interviewer_name = '';

        if (empty($schedule_errors)) {

            $int_stmt = mysqli_prepare(
                $conn,
                "SELECT full_name
                 FROM employees
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($int_stmt) {
                mysqli_stmt_bind_param($int_stmt, "i", $interviewer_id);
                mysqli_stmt_execute($int_stmt);
                mysqli_stmt_bind_result($int_stmt, $interviewer_name);
                mysqli_stmt_fetch($int_stmt);
                mysqli_stmt_close($int_stmt);
            }

            if ($interviewer_name === '') {
                $schedule_errors[] = "Interviewer not found";
            }
        }

        if (empty($schedule_errors)) {

            $interview_no =
                "INT-" .
                date('Ymd') .
                "-" .
                str_pad($candidate_id, 4, '0', STR_PAD_LEFT) .
                "-R" .
                $round_number;

            $interview_link_db = nullIfEmpty($interview_link);
            $location_db = nullIfEmpty($location);

            $insert_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO interviews
                (
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
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if ($insert_stmt) {

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
                    $interview_link_db,
                    $location_db,
                    $interviewer_id,
                    $interviewer_name,
                    $current_employee_id,
                    $current_employee['full_name']
                );

                if (mysqli_stmt_execute($insert_stmt)) {

                    $statusUpdate = mysqli_prepare(
                        $conn,
                        "UPDATE candidates
                         SET status = 'Interview Scheduled'
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if ($statusUpdate) {
                        mysqli_stmt_bind_param($statusUpdate, "i", $candidate_id);
                        mysqli_stmt_execute($statusUpdate);
                        mysqli_stmt_close($statusUpdate);
                    }

                    if (function_exists('logActivity')) {
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
                    }

                    $message = "Interview scheduled successfully!";
                    $messageType = "success";

                } else {

                    $message = "Error scheduling interview: " . mysqli_stmt_error($insert_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($insert_stmt);
            }

        } else {

            $validation_errors = array_merge($validation_errors, $schedule_errors);
            $messageType = "warning";
        }
    }
}

/* ---------------- FILTERS ---------------- */

$status_filter = trim((string)($_GET['status'] ?? 'all'));

$hiring_filter =
    isset($_GET['hiring_id'])
    ? (int)$_GET['hiring_id']
    : 0;

$search = trim((string)($_GET['search'] ?? ''));

if ($status_filter !== 'all' && !array_key_exists($status_filter, $status_options)) {
    $status_filter = 'all';
}

/* ---------------- HIRING REQUEST DROPDOWN ---------------- */

$hiring_requests = [];

$hiring_query = "
    SELECT
        id,
        request_no,
        position_title,
        department
    FROM hiring_requests
    WHERE status IN ('Approved', 'In Progress')
";

$hiring_params = [];
$hiring_types = "";

if (!$isHr && $isManager) {
    $hiring_query .= " AND requested_by = ?";
    $hiring_params[] = $current_employee_id;
    $hiring_types .= "i";
}

$hiring_query .= " ORDER BY created_at DESC";

$stmtHiring = mysqli_prepare($conn, $hiring_query);

if ($stmtHiring) {

    if (!empty($hiring_params)) {
        mysqli_stmt_bind_param(
            $stmtHiring,
            $hiring_types,
            ...$hiring_params
        );
    }

    mysqli_stmt_execute($stmtHiring);

    $resHiring = mysqli_stmt_get_result($stmtHiring);

    $hiring_requests = mysqli_fetch_all($resHiring, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtHiring);
}

/* ---------------- EMPLOYEES DROPDOWN ---------------- */

$employees = [];

$resEmployees = mysqli_query(
    $conn,
    "SELECT
        id,
        full_name,
        designation
     FROM employees
     WHERE employee_status = 'active'
     ORDER BY full_name ASC"
);

if ($resEmployees) {
    $employees = mysqli_fetch_all($resEmployees, MYSQLI_ASSOC);
}

/* ---------------- CANDIDATES QUERY ---------------- */

$query = "
    SELECT
        c.*,
        h.request_no,
        h.position_title,
        h.department,
        h.designation AS hiring_designation,
        (
            SELECT COUNT(*)
            FROM interviews
            WHERE candidate_id = c.id
        ) AS interview_count,
        (
            SELECT MAX(round_number)
            FROM interviews
            WHERE candidate_id = c.id
        ) AS current_round
    FROM candidates c
    LEFT JOIN hiring_requests h
    ON c.hiring_request_id = h.id
    WHERE 1 = 1
";

$params = [];
$types = "";

if (!$isHr && $isManager) {
    $query .= " AND h.requested_by = ?";
    $params[] = $current_employee_id;
    $types .= "i";
}

if ($status_filter !== 'all') {
    $query .= " AND c.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($hiring_filter > 0) {
    $query .= " AND c.hiring_request_id = ?";
    $params[] = $hiring_filter;
    $types .= "i";
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
            OR h.position_title LIKE ?
            OR h.request_no LIKE ?
        )
    ";

    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
        $types .= "s";
    }
}

$query .= " ORDER BY c.created_at DESC";

$candidates = [];

$stmtCandidates = mysqli_prepare($conn, $query);

if ($stmtCandidates) {

    if (!empty($params)) {
        mysqli_stmt_bind_param(
            $stmtCandidates,
            $types,
            ...$params
        );
    }

    mysqli_stmt_execute($stmtCandidates);

    $resCandidates = mysqli_stmt_get_result($stmtCandidates);

    $candidates = mysqli_fetch_all($resCandidates, MYSQLI_ASSOC);

    mysqli_stmt_close($stmtCandidates);

} else {

    $message = "Error fetching candidates: " . mysqli_error($conn);
    $messageType = "danger";
}

/* ---------------- STATS ---------------- */

$stats = [
    'total' => 0,
    'new' => 0,
    'interview' => 0,
    'selected' => 0
];

$stats_query = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN c.status = 'New' THEN 1 ELSE 0 END) AS new,
        SUM(CASE WHEN c.status = 'Interview Scheduled' THEN 1 ELSE 0 END) AS interview,
        SUM(CASE WHEN c.status IN ('Selected', 'Offered', 'Joined') THEN 1 ELSE 0 END) AS selected
    FROM candidates c
";

$stats_params = [];
$stats_types = "";

if (!$isHr && $isManager) {

    $stats_query .= "
        LEFT JOIN hiring_requests h
        ON c.hiring_request_id = h.id
        WHERE h.requested_by = ?
    ";

    $stats_params[] = $current_employee_id;
    $stats_types .= "i";
}

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

<title>Candidates - TEK-C</title>

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

.candidates-wrapper{
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

.filter-select{
    height:36px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
    padding:0 32px 0 12px;
    font-size:12px;
    font-weight:800;
    min-width:150px;
}

.filter-hiring{
    min-width:230px;
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
.update-btn{ color:#b45309; background:#fef3c7; }
.interview-btn{ color:#15803d; background:#dcfce7; }
.offer-btn{ color:#2563eb; background:#eff6ff; }
.file-btn{ color:#b91c1c; background:#fee2e2; }

.pagination-wrap{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding-top:12px;
}

.pagination-info{
    color:var(--muted);
    font-size:11px;
    font-weight:700;
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

/* IMPORTANT: fixed Add Candidate modal visibility */

.add-candidate-modal{
    max-width:min(1180px, calc(100vw - 24px));
    margin:12px auto;
}

.add-candidate-modal .modal-content{
    height:calc(100vh - 24px);
    max-height:calc(100vh - 24px);
    border-radius:18px;
    overflow:hidden;
}

.add-candidate-modal .modal-header{
    flex:0 0 auto;
    padding:14px 18px;
}

.add-candidate-modal .modal-body{
    flex:1 1 auto;
    overflow-y:auto;
    padding:16px;
    max-height:none;
}

.add-candidate-modal .modal-footer{
    flex:0 0 auto;
    padding:12px 18px;
    background:#fff;
    border-top:1px solid var(--border);
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
    font-weight:700;
    color:#4a5568;
    margin-bottom:6px;
    font-size:12px;
}

.required-label::after{
    content:" *";
    color:#e53e3e;
    font-weight:900;
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

.file-upload-container{
    border:2px dashed #cbd5e1;
    border-radius:12px;
    padding:14px;
    text-align:center;
    background:#f8fafc;
    cursor:pointer;
    transition:all .2s;
}

.file-upload-container:hover{
    border-color:#2563eb;
    background:#eff6ff;
}

.file-upload-icon{
    font-size:28px;
    color:#94a3b8;
    margin-bottom:6px;
}

.file-upload-text{
    font-size:11px;
    color:#475569;
    font-weight:900;
}

.file-upload-subtext{
    font-size:10px;
    color:#94a3b8;
    font-weight:700;
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
        flex:0 0 95px;
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
    .filter-hiring,
    .filter-submit{
        width:100%;
    }

    .add-candidate-modal{
        width:100%;
        max-width:100%;
        height:100%;
        margin:0;
    }

    .add-candidate-modal .modal-content{
        height:100vh;
        max-height:100vh;
        border-radius:0;
    }

    .add-candidate-modal .modal-body{
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

<div class="container-fluid candidates-wrapper px-0">

<div class="page-heading">

<div>
<h1>Candidates</h1>
<p>Manage candidate pipeline, status updates and interviews</p>
</div>

<div class="d-flex gap-2 flex-wrap">

<?php if ($isHr): ?>
<button
class="primary-btn"
data-bs-toggle="modal"
data-bs-target="#addCandidateModal"
>
<i class="bi bi-person-plus"></i>
Add Candidate
</button>
<?php endif; ?>

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

<?php if (!empty($message)): ?>
<div class="alert alert-<?php echo e($messageType); ?> alert-dismissible fade show" role="alert">
<i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
<?php echo e($message); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="bi bi-exclamation-triangle-fill me-2"></i>
<?php echo e($_SESSION['flash_error']); ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">

<div class="col-12 col-sm-6 col-xl-3">
<div class="stat-card">
<div class="stat-ic blue"><i class="bi bi-people-fill"></i></div>
<div>
<div class="stat-label">Total Candidates</div>
<div class="stat-value"><?php echo (int)($stats['total'] ?? 0); ?></div>
</div>
</div>
</div>

<div class="col-12 col-sm-6 col-xl-3">
<div class="stat-card">
<div class="stat-ic green"><i class="bi bi-star-fill"></i></div>
<div>
<div class="stat-label">New Applications</div>
<div class="stat-value"><?php echo (int)($stats['new'] ?? 0); ?></div>
</div>
</div>
</div>

<div class="col-12 col-sm-6 col-xl-3">
<div class="stat-card">
<div class="stat-ic orange"><i class="bi bi-camera-video-fill"></i></div>
<div>
<div class="stat-label">Interviews</div>
<div class="stat-value"><?php echo (int)($stats['interview'] ?? 0); ?></div>
</div>
</div>
</div>

<div class="col-12 col-sm-6 col-xl-3">
<div class="stat-card">
<div class="stat-ic purple"><i class="bi bi-check-circle-fill"></i></div>
<div>
<div class="stat-label">Selected</div>
<div class="stat-value"><?php echo (int)($stats['selected'] ?? 0); ?></div>
</div>
</div>
</div>

</div>

<div class="panel">

<div class="panel-header">
<div>
<h3 class="panel-title">Candidate Pipeline</h3>
<div class="panel-subtitle">Compact responsive candidate directory</div>
</div>
<span class="badge bg-secondary"><?php echo count($candidates); ?> records</span>
</div>

<div class="filter-bar">

<div class="search-box">
<i class="bi bi-search"></i>
<input
type="text"
id="quickSearch"
placeholder="Search candidate, email, phone, request or position..."
value="<?php echo e($search); ?>"
>
</div>

<form method="GET" action="" class="filter-form" id="filterForm">

<select name="status" class="filter-select">
<option value="all">All Status</option>
<?php foreach ($status_options as $key => $value): ?>
<option value="<?php echo e($key); ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>>
<?php echo e($value); ?>
</option>
<?php endforeach; ?>
</select>

<select name="hiring_id" class="filter-select filter-hiring">
<option value="0">All Hiring Requests</option>
<?php foreach ($hiring_requests as $hr): ?>
<option
value="<?php echo (int)$hr['id']; ?>"
<?php echo (int)$hiring_filter === (int)$hr['id'] ? 'selected' : ''; ?>
>
<?php echo e($hr['request_no']); ?> - <?php echo e($hr['position_title']); ?>
</option>
<?php endforeach; ?>
</select>

<input type="hidden" name="search" id="serverSearch" value="<?php echo e($search); ?>">

<button type="submit" class="filter-submit">
<i class="bi bi-funnel"></i>
Apply
</button>

</form>

</div>

<div class="compact-table-wrap">

<table class="table compact-table align-middle" id="candidatesTable">

<thead>
<tr>
<th>Candidate</th>
<th>Contact</th>
<th>Position</th>
<th>Experience</th>
<th>Status</th>
<th>Source</th>
<th>Applied</th>
<th class="text-end">Actions</th>
</tr>
</thead>

<tbody>

<?php if (empty($candidates)): ?>

<tr class="no-record-row">
<td colspan="8">
<div class="empty-state">
<i class="bi bi-inbox me-1"></i>
No candidates found.
</div>
</td>
</tr>

<?php else: ?>

<?php foreach ($candidates as $candidate): ?>

<?php
$fullName = getFullName($candidate['first_name'] ?? '', $candidate['last_name'] ?? '');
$initials = getInitials($candidate['first_name'] ?? '', $candidate['last_name'] ?? '');
$photoSrc = fileUrl($candidate['photo_path'] ?? '');
$resumeSrc = fileUrl($candidate['resume_path'] ?? '');
[$statusLabel, $statusClass, $statusKey] = candidateStatusBadge($candidate['status'] ?? 'New');
$currentStatusJs = e(addslashes($candidate['status'] ?? 'New'));
$fullNameJs = e(addslashes($fullName));
$currentRound = (int)($candidate['current_round'] ?? 0);
$nextRound = $currentRound + 1;
?>

<tr data-status="<?php echo e($statusKey); ?>">

<td data-label="Candidate">
<div class="table-title-cell">
<div class="candidate-avatar">
<?php if ($photoSrc !== ''): ?>
<img
src="<?php echo e($photoSrc); ?>"
alt="<?php echo e($fullName); ?>"
onerror="this.style.display='none'; this.parentNode.innerHTML='<?php echo e($initials); ?>';"
>
<?php else: ?>
<?php echo e($initials); ?>
<?php endif; ?>
</div>
<div>
<div class="table-primary-text"><?php echo e($fullName); ?></div>
<div class="table-secondary-text">
<?php echo e($candidate['candidate_code'] ?? ''); ?>
<?php if ((int)($candidate['interview_count'] ?? 0) > 0): ?>
• Round <?php echo (int)($candidate['current_round'] ?? 0); ?>/<?php echo (int)($candidate['interview_count'] ?? 0); ?>
<?php endif; ?>
</div>
</div>
</div>
</td>

<td data-label="Contact">
<div class="table-primary-text"><?php echo e($candidate['email'] ?? ''); ?></div>
<div class="table-secondary-text">
<i class="bi bi-telephone me-1"></i>
<?php echo e($candidate['phone'] ?? ''); ?>
</div>
<?php if (!empty($candidate['current_location'])): ?>
<div class="table-secondary-text">
<i class="bi bi-geo-alt me-1"></i>
<?php echo e($candidate['current_location']); ?>
</div>
<?php endif; ?>
</td>

<td data-label="Position">
<div class="table-primary-text"><?php echo e($candidate['position_title'] ?? 'N/A'); ?></div>
<div class="table-secondary-text"><?php echo e($candidate['request_no'] ?? ''); ?></div>
<?php if (!empty($candidate['department'])): ?>
<span class="department-tag">
<i class="bi bi-building"></i>
<?php echo e($candidate['department']); ?>
</span>
<?php endif; ?>
</td>

<td data-label="Experience">
<?php if ($candidate['total_experience'] !== null && $candidate['total_experience'] !== ''): ?>
<div class="table-primary-text">
<?php echo number_format((float)$candidate['total_experience'], 1); ?> years
</div>
<div class="table-secondary-text">
Expected: ₹<?php echo number_format((float)($candidate['expected_ctc'] ?? 0), 2); ?> LPA
</div>
<?php else: ?>
<div class="table-primary-text">Fresher</div>
<?php endif; ?>

<?php if (!empty($candidate['notice_period'])): ?>
<div class="table-secondary-text">
Notice: <?php echo (int)$candidate['notice_period']; ?> days
</div>
<?php endif; ?>
</td>

<td data-label="Status">
<span class="badge-pill <?php echo e($statusClass); ?>">
<span class="mini-dot"></span>
<?php echo e($statusLabel); ?>
</span>
</td>

<td data-label="Source">
<div class="table-primary-text"><?php echo e($candidate['source'] ?? 'N/A'); ?></div>
<?php if (!empty($candidate['referred_by'])): ?>
<div class="table-secondary-text">
Ref: <?php echo e($candidate['referred_by']); ?>
</div>
<?php endif; ?>
</td>

<td data-label="Applied">
<div class="table-primary-text"><?php echo e(safeDate($candidate['created_at'] ?? '')); ?></div>
<div class="table-secondary-text"><?php echo e(safeDateTime($candidate['created_at'] ?? '')); ?></div>
</td>

<td data-label="Actions">

<div class="action-group">

<a
href="view-candidate.php?id=<?php echo (int)$candidate['id']; ?>"
class="action-btn view-btn"
title="View Candidate"
>
<i class="bi bi-eye"></i>
</a>

<?php if (!in_array(($candidate['status'] ?? ''), ['Rejected', 'Joined', 'Declined'], true)): ?>

<button
type="button"
class="action-btn update-btn"
onclick="openStatusModal(<?php echo (int)$candidate['id']; ?>, '<?php echo $fullNameJs; ?>', '<?php echo $currentStatusJs; ?>')"
title="Update Status"
>
<i class="bi bi-arrow-repeat"></i>
</button>

<?php if (!in_array(($candidate['status'] ?? ''), ['Interview Scheduled', 'Selected', 'Offered'], true)): ?>

<button
type="button"
class="action-btn interview-btn"
onclick="openInterviewModal(<?php echo (int)$candidate['id']; ?>, <?php echo (int)($candidate['hiring_request_id'] ?? 0); ?>, '<?php echo $fullNameJs; ?>', <?php echo (int)$nextRound; ?>)"
title="Schedule Interview"
>
<i class="bi bi-camera-video"></i>
</button>

<?php endif; ?>

<?php endif; ?>

<?php if (($candidate['status'] ?? '') === 'Selected' && $isHr): ?>

<a
href="offer-approval.php?candidate_id=<?php echo (int)$candidate['id']; ?>"
class="action-btn offer-btn"
title="Create Offer"
>
<i class="bi bi-file-text"></i>
</a>

<?php endif; ?>

<?php if ($resumeSrc !== ''): ?>

<a
href="<?php echo e($resumeSrc); ?>"
target="_blank"
rel="noopener"
class="action-btn file-btn"
title="Resume"
>
<i class="bi bi-file-earmark-pdf"></i>
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

<div class="pagination-wrap">
<div class="pagination-info" id="recordInfo">
Showing <?php echo count($candidates); ?> candidate records
</div>
</div>

</div>

</div>

</div>

<?php include 'includes/footer.php'; ?>

</main>

</div>

<!-- ADD CANDIDATE MODAL -->

<?php if ($isHr): ?>

<div
class="modal fade"
id="addCandidateModal"
tabindex="-1"
aria-hidden="true"
>

<div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down add-candidate-modal">

<div class="modal-content">

<form
method="POST"
enctype="multipart/form-data"
id="addCandidateForm"
novalidate
class="h-100 d-flex flex-column"
>

<input type="hidden" name="action" value="add_candidate">

<div class="modal-header">

<div>
<h5 class="modal-title fw-bold mb-1">Add New Candidate</h5>
<div class="text-muted small fw-semibold">
Fill all candidate details. Scroll inside this form to view every section.
</div>
</div>

<button type="button" class="btn-close" data-bs-dismiss="modal"></button>

</div>

<div class="modal-body">

<div class="form-panel">

<div class="section-header">
<div class="section-icon"><i class="bi bi-briefcase"></i></div>
<div>
<h3 class="section-title">Position Details</h3>
<p class="section-subtitle">Select the hiring request this candidate belongs to</p>
</div>
</div>

<div class="row g-3">

<div class="col-12">
<label for="hiring_request_id" class="form-label required-label">Hiring Request</label>
<select name="hiring_request_id" id="hiring_request_id" class="form-select" required>
<option value="">Select Position</option>
<?php foreach ($hiring_requests as $hr): ?>
<option value="<?php echo (int)$hr['id']; ?>">
<?php echo e($hr['request_no']); ?> - <?php echo e($hr['position_title']); ?> (<?php echo e($hr['department']); ?>)
</option>
<?php endforeach; ?>
</select>
</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#2563eb;"><i class="bi bi-person-badge"></i></div>
<div>
<h3 class="section-title">Candidate Details</h3>
<p class="section-subtitle">Basic identity and contact information</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-6">
<label for="first_name" class="form-label required-label">First Name</label>
<input type="text" name="first_name" id="first_name" class="form-control" required>
</div>

<div class="col-md-6">
<label for="last_name" class="form-label required-label">Last Name</label>
<input type="text" name="last_name" id="last_name" class="form-control" required>
</div>

<div class="col-md-6">
<label for="email" class="form-label required-label">Email</label>
<input type="email" name="email" id="email" class="form-control" required>
</div>

<div class="col-md-6">
<label for="phone" class="form-label required-label">Phone</label>
<input type="tel" name="phone" id="phone" class="form-control" required>
</div>

<div class="col-md-6">
<label for="alternate_phone" class="form-label">
Alternate Phone <span class="optional-badge">(Optional)</span>
</label>
<input type="tel" name="alternate_phone" id="alternate_phone" class="form-control">
</div>

<div class="col-md-6">
<label for="current_location" class="form-label">
Current Location <span class="optional-badge">(Optional)</span>
</label>
<input type="text" name="current_location" id="current_location" class="form-control">
</div>

<div class="col-md-6">
<label for="preferred_location" class="form-label">
Preferred Location <span class="optional-badge">(Optional)</span>
</label>
<input type="text" name="preferred_location" id="preferred_location" class="form-control">
</div>

<div class="col-md-6">
<label for="current_company" class="form-label">
Current Company <span class="optional-badge">(Optional)</span>
</label>
<input type="text" name="current_company" id="current_company" class="form-control">
</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#10b981;"><i class="bi bi-bar-chart"></i></div>
<div>
<h3 class="section-title">Experience & CTC</h3>
<p class="section-subtitle">Experience, salary and notice period information</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-3">
<label for="total_experience" class="form-label">
Total Experience <span class="optional-badge">(Years)</span>
</label>
<input type="number" name="total_experience" id="total_experience" class="form-control" step="0.5" min="0">
</div>

<div class="col-md-3">
<label for="relevant_experience" class="form-label">
Relevant Experience <span class="optional-badge">(Years)</span>
</label>
<input type="number" name="relevant_experience" id="relevant_experience" class="form-control" step="0.5" min="0">
</div>

<div class="col-md-3">
<label for="current_ctc" class="form-label">
Current CTC <span class="optional-badge">₹ LPA</span>
</label>
<input type="number" name="current_ctc" id="current_ctc" class="form-control" step="0.1" min="0">
</div>

<div class="col-md-3">
<label for="expected_ctc" class="form-label">
Expected CTC <span class="optional-badge">₹ LPA</span>
</label>
<input type="number" name="expected_ctc" id="expected_ctc" class="form-control" step="0.1" min="0">
</div>

<div class="col-md-4">
<label for="notice_period" class="form-label">
Notice Period <span class="optional-badge">(Days)</span>
</label>
<input type="number" name="notice_period" id="notice_period" class="form-control" min="0">
</div>

<div class="col-md-4 d-flex align-items-end">
<div class="form-check mb-2">
<input class="form-check-input" type="checkbox" name="notice_period_negotiable" id="notice_period_negotiable">
<label class="form-check-label fw-bold" for="notice_period_negotiable">
Notice Period Negotiable
</label>
</div>
</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#8e44ad;"><i class="bi bi-mortarboard"></i></div>
<div>
<h3 class="section-title">Qualification & Skills</h3>
<p class="section-subtitle">Education, skills and candidate source</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-6">
<label for="qualification" class="form-label">
Qualification <span class="optional-badge">(Optional)</span>
</label>
<input type="text" name="qualification" id="qualification" class="form-control" placeholder="Example: B.E / B.Tech Civil">
</div>

<div class="col-md-6">
<label for="source" class="form-label">Source</label>
<select name="source" id="source" class="form-select">
<?php foreach ($sources as $src): ?>
<option value="<?php echo e($src); ?>"><?php echo e($src); ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label for="referred_by" class="form-label">
Referred By <span class="optional-badge">(Optional)</span>
</label>
<input type="text" name="referred_by" id="referred_by" class="form-control">
</div>

<div class="col-12">
<label for="skills" class="form-label">
Skills <span class="optional-badge">(Optional)</span>
</label>
<textarea name="skills" id="skills" class="form-control" rows="2" placeholder="Comma separated skills"></textarea>
</div>

</div>

</div>

<div class="form-panel">

<div class="section-header">
<div class="section-icon" style="background:#f59e0b;"><i class="bi bi-file-earmark-arrow-up"></i></div>
<div>
<h3 class="section-title">Documents & Remarks</h3>
<p class="section-subtitle">Upload resume, photo and add notes</p>
</div>
</div>

<div class="row g-3">

<div class="col-md-6">
<label for="resume" class="form-label">
Resume <span class="optional-badge">PDF/DOC/DOCX, Max 8MB</span>
</label>
<div class="file-upload-container" onclick="document.getElementById('resume').click()">
<div class="file-upload-icon"><i class="bi bi-file-earmark-pdf"></i></div>
<div class="file-upload-text" id="resumeText">Click to upload resume</div>
<div class="file-upload-subtext">PDF, DOC, DOCX</div>
<input type="file" name="resume" id="resume" class="d-none" accept=".pdf,.doc,.docx">
</div>
</div>

<div class="col-md-6">
<label for="photo" class="form-label">
Photo <span class="optional-badge">Image, Max 5MB</span>
</label>
<div class="file-upload-container" onclick="document.getElementById('photo').click()">
<div class="file-upload-icon"><i class="bi bi-person-square"></i></div>
<div class="file-upload-text" id="photoText">Click to upload photo</div>
<div class="file-upload-subtext">JPG, PNG, GIF, WebP</div>
<input type="file" name="photo" id="photo" class="d-none" accept="image/*">
</div>
</div>

<div class="col-12">
<label for="remarks" class="form-label">
Remarks <span class="optional-badge">(Optional)</span>
</label>
<textarea name="remarks" id="remarks" class="form-control" rows="2"></textarea>
</div>

</div>

</div>

</div>

<div class="modal-footer">

<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
Cancel
</button>

<button type="submit" class="btn btn-dark">
<i class="bi bi-person-plus me-1"></i>
Add Candidate
</button>

</div>

</form>

</div>

</div>

</div>

<?php endif; ?>

<!-- UPDATE STATUS MODAL -->

<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog">
<div class="modal-content">

<form method="POST">

<input type="hidden" name="action" value="update_status">
<input type="hidden" name="candidate_id" id="status_candidate_id">

<div class="modal-header">
<h5 class="modal-title fw-bold">Update Candidate Status</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<p>
Update status for
<strong id="status_candidate_name"></strong>
</p>

<div class="mb-3">
<label class="form-label required-label">New Status</label>
<select name="status" id="status_select" class="form-select" required>
<?php foreach ($status_options as $key => $value): ?>
<option value="<?php echo e($key); ?>"><?php echo e($value); ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="mb-3">
<label class="form-label">
Remarks <span class="optional-badge">(Optional)</span>
</label>
<textarea name="remarks" class="form-control" rows="2" placeholder="Add any remarks..."></textarea>
</div>

</div>

<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-dark">Update Status</button>
</div>

</form>

</div>
</div>
</div>

<!-- SCHEDULE INTERVIEW MODAL -->

<div class="modal fade" id="interviewModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog">
<div class="modal-content">

<form method="POST">

<input type="hidden" name="action" value="schedule_interview">
<input type="hidden" name="candidate_id" id="interview_candidate_id">
<input type="hidden" name="hiring_request_id" id="interview_hiring_id">
<input type="hidden" name="round_number" id="interview_round">

<div class="modal-header">
<h5 class="modal-title fw-bold">Schedule Interview</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<p>
Schedule interview for
<strong id="interview_candidate_name"></strong>
</p>

<div class="mb-3">
<label class="form-label required-label">Interview Round</label>
<select name="interview_round" id="interview_round_select" class="form-select" required>
<?php foreach ($interview_rounds as $key => $value): ?>
<option value="<?php echo e($key); ?>"><?php echo e($value); ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="row g-2 mb-3">

<div class="col-md-6">
<label class="form-label required-label">Date</label>
<input type="date" name="interview_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
</div>

<div class="col-md-6">
<label class="form-label required-label">Time</label>
<input type="time" name="interview_time" class="form-control" required>
</div>

</div>

<div class="row g-2 mb-3">

<div class="col-md-6">
<label class="form-label required-label">Duration</label>
<select name="interview_duration" class="form-select" required>
<option value="30">30 minutes</option>
<option value="45">45 minutes</option>
<option value="60" selected>1 hour</option>
<option value="90">1.5 hours</option>
<option value="120">2 hours</option>
</select>
</div>

<div class="col-md-6">
<label class="form-label required-label">Mode</label>
<select name="interview_mode" id="interview_mode" class="form-select" required>
<option value="Online">Online</option>
<option value="In-Person">In-Person</option>
<option value="Telephonic">Telephonic</option>
</select>
</div>

</div>

<div class="mb-3">
<label class="form-label required-label">Interviewer</label>
<select name="interviewer_id" class="form-select" required>
<option value="">Select Interviewer</option>
<?php foreach ($employees as $emp): ?>
<option value="<?php echo (int)$emp['id']; ?>">
<?php echo e($emp['full_name']); ?> (<?php echo e($emp['designation']); ?>)
</option>
<?php endforeach; ?>
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
<button type="submit" class="btn btn-dark">Schedule Interview</button>
</div>

</form>

</div>
</div>
</div>

<!-- EXPORT MODAL -->

<div class="modal fade" id="exportModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">

<div class="modal-header">
<h5 class="modal-title fw-bold">Export Candidates</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<div class="mb-3">
<label class="form-label">Export Format</label>
<select class="form-select" id="exportFormat">
<option value="csv">CSV</option>
</select>
</div>

<div class="alert alert-info mb-0" style="box-shadow:none;">
<i class="bi bi-info-circle me-2"></i>
This exports the currently displayed candidate rows.
</div>

</div>

<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="button" class="btn btn-success" onclick="exportToCSV()">
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

    const quickSearch = document.getElementById('quickSearch');
    const serverSearch = document.getElementById('serverSearch');
    const tableRows = document.querySelectorAll('#candidatesTable tbody tr:not(.no-record-row)');
    const recordInfo = document.getElementById('recordInfo');

    function filterRows(){

        const searchValue = quickSearch.value.toLowerCase().trim();
        let visibleCount = 0;

        tableRows.forEach(function(row){

            const rowText = row.innerText.toLowerCase();
            const matches = rowText.includes(searchValue);

            row.style.display = matches ? '' : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        if (recordInfo) {
            recordInfo.textContent = 'Showing ' + visibleCount + ' candidate records';
        }

        if (serverSearch) {
            serverSearch.value = searchValue;
        }
    }

    if (quickSearch) {
        quickSearch.addEventListener('input', filterRows);
    }

    const mode = document.getElementById('interview_mode');
    const onlineLinkField = document.getElementById('onlineLinkField');
    const locationField = document.getElementById('locationField');

    function toggleInterviewMode(){

        if (!mode || !onlineLinkField || !locationField) {
            return;
        }

        if (mode.value === 'Online') {
            onlineLinkField.style.display = '';
            locationField.style.display = 'none';
        } else if (mode.value === 'In-Person') {
            onlineLinkField.style.display = 'none';
            locationField.style.display = '';
        } else {
            onlineLinkField.style.display = 'none';
            locationField.style.display = 'none';
        }
    }

    if (mode) {
        mode.addEventListener('change', toggleInterviewMode);
        toggleInterviewMode();
    }

    const resumeInput = document.getElementById('resume');
    const resumeText = document.getElementById('resumeText');

    if (resumeInput && resumeText) {
        resumeInput.addEventListener('change', function(){
            resumeText.textContent =
                this.files && this.files[0]
                ? this.files[0].name
                : 'Click to upload resume';
        });
    }

    const photoInput = document.getElementById('photo');
    const photoText = document.getElementById('photoText');

    if (photoInput && photoText) {
        photoInput.addEventListener('change', function(){
            photoText.textContent =
                this.files && this.files[0]
                ? this.files[0].name
                : 'Click to upload photo';
        });
    }

    const addCandidateForm = document.getElementById('addCandidateForm');

    if (addCandidateForm) {

        addCandidateForm.addEventListener('submit', function(e){

            let valid = true;
            const errors = [];

            addCandidateForm.querySelectorAll('.is-invalid').forEach(function(el){
                el.classList.remove('is-invalid');
            });

            addCandidateForm.querySelectorAll('[required]').forEach(function(field){

                if (!String(field.value || '').trim()) {

                    valid = false;
                    field.classList.add('is-invalid');

                    const label = addCandidateForm.querySelector('label[for="' + field.id + '"]');

                    errors.push(
                        (label ? label.textContent.replace('*', '').trim() : field.name) +
                        ' is required'
                    );
                }
            });

            const email = document.getElementById('email');

            if (
                email &&
                email.value &&
                !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)
            ) {
                valid = false;
                email.classList.add('is-invalid');
                errors.push('Invalid email format');
            }

            const phone = document.getElementById('phone');

            if (
                phone &&
                phone.value &&
                !/^[0-9]{10,15}$/.test(phone.value)
            ) {
                valid = false;
                phone.classList.add('is-invalid');
                errors.push('Phone number must be 10 to 15 digits');
            }

            const altPhone = document.getElementById('alternate_phone');

            if (
                altPhone &&
                altPhone.value &&
                !/^[0-9]{10,15}$/.test(altPhone.value)
            ) {
                valid = false;
                altPhone.classList.add('is-invalid');
                errors.push('Alternate phone must be 10 to 15 digits');
            }

            const totalExp = document.getElementById('total_experience');
            const relevantExp = document.getElementById('relevant_experience');

            if (
                totalExp &&
                relevantExp &&
                totalExp.value !== '' &&
                relevantExp.value !== '' &&
                parseFloat(relevantExp.value) > parseFloat(totalExp.value)
            ) {
                valid = false;
                relevantExp.classList.add('is-invalid');
                errors.push('Relevant experience cannot exceed total experience');
            }

            if (!valid) {
                e.preventDefault();
                alert(errors.join("\\n"));
            }
        });
    }
});

function openStatusModal(id, name, currentStatus){

    document.getElementById('status_candidate_id').value = id;
    document.getElementById('status_candidate_name').textContent = name;
    document.getElementById('status_select').value = currentStatus;

    new bootstrap.Modal(
        document.getElementById('statusModal')
    ).show();
}

function openInterviewModal(id, hiringId, name, round){

    document.getElementById('interview_candidate_id').value = id;
    document.getElementById('interview_hiring_id').value = hiringId;
    document.getElementById('interview_candidate_name').textContent = name;
    document.getElementById('interview_round').value = round;

    const roundSelect = document.getElementById('interview_round_select');

    if (roundSelect) {
        if (round === 1) roundSelect.value = 'Telephonic';
        else if (round === 2) roundSelect.value = 'Technical Round 1';
        else if (round === 3) roundSelect.value = 'Technical Round 2';
        else if (round === 4) roundSelect.value = 'HR Round';
        else if (round === 5) roundSelect.value = 'Manager Round';
        else roundSelect.value = 'Final Round';
    }

    const mode = document.getElementById('interview_mode');

    if (mode) {
        mode.value = 'Online';
        mode.dispatchEvent(new Event('change'));
    }

    new bootstrap.Modal(
        document.getElementById('interviewModal')
    ).show();
}

function exportToCSV(){

    const rows = document.querySelectorAll('#candidatesTable tbody tr:not(.no-record-row)');
    const csv = [];

    const headers = [
        'Candidate',
        'Contact',
        'Position',
        'Experience',
        'Status',
        'Source',
        'Applied'
    ];

    csv.push(headers.join(','));

    rows.forEach(function(row){

        if (row.style.display === 'none') {
            return;
        }

        const cells = row.querySelectorAll('td');

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

    const csvString = csv.join('\n');

    const blob = new Blob(
        ["\uFEFF" + csvString],
        { type: 'text/csv;charset=utf-8;' }
    );

    const url = window.URL.createObjectURL(blob);

    const a = document.createElement('a');

    a.href = url;
    a.download = 'candidates_<?php echo date('Y-m-d'); ?>.csv';

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