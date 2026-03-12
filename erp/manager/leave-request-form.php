<?php
// manager-leave-request.php
// Manager Leave Request Form (DAR-style UI)
// Full corrected code

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error = '';
$validation_errors = [];

// ---------- AUTH ----------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId   = (int)$_SESSION['employee_id']; // manager is also an employee
$employeeName = $_SESSION['employee_name'] ?? ($_SESSION['name'] ?? 'Manager');

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------- Flash ----------
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------- FIND MANAGER'S MANAGER (optional approver) ----------
$managerInfo = null;

// 1) Try join by reporting_manager name
$st = mysqli_prepare($conn, "
    SELECT e.id, e.full_name, e.designation, e.email, e.mobile_number
    FROM employees e
    INNER JOIN employees emp ON emp.reporting_manager = e.full_name
    WHERE emp.id = ?
    LIMIT 1
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $managerInfo = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
}

// 2) Fallback: fetch reporting_manager and match full_name
if (!$managerInfo) {
    $st = mysqli_prepare($conn, "SELECT reporting_manager FROM employees WHERE id = ? LIMIT 1");
    if ($st) {
        mysqli_stmt_bind_param($st, "i", $employeeId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $emp = mysqli_fetch_assoc($res);
        mysqli_stmt_close($st);

        if ($emp && !empty($emp['reporting_manager'])) {
            $reportingManagerName = $emp['reporting_manager'];
            $st = mysqli_prepare($conn, "
                SELECT id, full_name, designation, email, mobile_number
                FROM employees
                WHERE full_name = ?
                LIMIT 1
            ");
            if ($st) {
                mysqli_stmt_bind_param($st, "s", $reportingManagerName);
                mysqli_stmt_execute($st);
                $res = mysqli_stmt_get_result($st);
                $managerInfo = mysqli_fetch_assoc($res);
                mysqli_stmt_close($st);
            }
        }
    }
}

// ---------- FETCH LEAVE BALANCE (count-based) ----------
$leaveBalance = [
    'annual'   => 12,
    'sick'     => 6,
    'casual'   => 6,
    'used'     => 0,
    'pending'  => 0,
    'approved' => 0,
    'available'=> 0
];

// Total approved/pending count this year (count-based, not days)
$st = mysqli_prepare($conn, "
    SELECT
        COUNT(CASE WHEN status = 'Approved' THEN 1 END) AS approved,
        COUNT(CASE WHEN status = 'Pending' THEN 1 END) AS pending
    FROM employee_requests
    WHERE employee_id = ?
      AND request_type = 'Leave'
      AND YEAR(request_date) = YEAR(CURDATE())
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);

    $leaveBalance['approved'] = (int)($row['approved'] ?? 0);
    $leaveBalance['pending']  = (int)($row['pending'] ?? 0);
    $leaveBalance['used']     = $leaveBalance['approved'];

    mysqli_stmt_close($st);
}

// Available balance (count-based total)
$leaveBalance['available'] = (
    $leaveBalance['annual'] +
    $leaveBalance['sick'] +
    $leaveBalance['casual'] -
    $leaveBalance['approved'] -
    $leaveBalance['pending']
);

// ---------- UPLOAD HANDLER ----------
function handleAttachmentUpload($field_name) {
    if (!isset($_FILES[$field_name])) {
        return ['success' => false, 'no_file' => true];
    }

    $upload_dir = 'uploads/requests/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }

    $file = $_FILES[$field_name];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'no_file' => true];
    }

    $file_name  = basename($file['name'] ?? '');
    $file_tmp   = $file['tmp_name'] ?? '';
    $file_size  = (int)($file['size'] ?? 0);
    $file_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    if ($file_error !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error: ' . $file_error];
    }
    if ($file_size > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File size too large (max 5MB)'];
    }

    $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];
    if (!in_array($file_ext, $allowed_extensions, true)) {
        return ['success' => false, 'error' => 'Allowed file types: PDF, DOC, DOCX, JPG, JPEG, PNG, TXT'];
    }

    if ($file_ext === '') {
        return ['success' => false, 'error' => 'Invalid file name or extension'];
    }

    $new_file_name = uniqid('leave_', true) . '_' . time() . '.' . $file_ext;
    $target_path = $upload_dir . $new_file_name;

    if (move_uploaded_file($file_tmp, $target_path)) {
        return ['success' => true, 'path' => $target_path];
    }

    return ['success' => false, 'error' => 'Failed to move uploaded file'];
}

// ---------- GENERATE REQUEST NUMBER ----------
function generateRequestNo(mysqli $conn) {
    $year = date('Y');
    $month = date('m');

    $st = mysqli_prepare($conn, "
        SELECT COUNT(*) AS cnt
        FROM employee_requests
        WHERE request_no LIKE ?
    ");
    if (!$st) {
        return "LEAVE-{$year}{$month}-" . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    $pattern = "LEAVE-{$year}{$month}-%";
    mysqli_stmt_bind_param($st, "s", $pattern);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    $count = (int)($row['cnt'] ?? 0) + 1;
    mysqli_stmt_close($st);

    return "LEAVE-{$year}{$month}-" . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}

// ---------- HANDLE POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $leave_type           = trim($_POST['leave_type'] ?? '');
    $leave_from_date      = !empty($_POST['leave_from_date']) ? trim($_POST['leave_from_date']) : null;
    $leave_to_date        = !empty($_POST['leave_to_date']) ? trim($_POST['leave_to_date']) : null;
    $request_subject      = trim($_POST['request_subject'] ?? '');
    $request_description  = trim($_POST['request_description'] ?? '');
    $priority             = trim($_POST['priority'] ?? 'Medium');
    $contact_during_leave = trim($_POST['contact_during_leave'] ?? '');
    $handover_notes       = trim($_POST['handover_notes'] ?? '');

    $leave_days = 0;
    if ($leave_from_date && $leave_to_date) {
        try {
            $from = new DateTime($leave_from_date);
            $to   = new DateTime($leave_to_date);
            $leave_days = $from->diff($to)->days + 1;
        } catch (Exception $ex) {
            $validation_errors[] = "Invalid leave date format.";
        }
    }

    // Validation
    if ($leave_type === '') $validation_errors[] = "Leave type is required.";
    if (!$leave_from_date)  $validation_errors[] = "Leave from date is required.";
    if (!$leave_to_date)    $validation_errors[] = "Leave to date is required.";
    if ($request_subject === '') $validation_errors[] = "Request subject is required.";
    if ($request_description === '') $validation_errors[] = "Request description is required.";

    $allowed_types = ['Annual', 'Sick', 'Casual', 'Unpaid'];
    if ($leave_type !== '' && !in_array($leave_type, $allowed_types, true)) {
        $validation_errors[] = "Invalid leave type selected.";
    }

    $allowed_priorities = ['Low', 'Medium', 'High', 'Urgent'];
    if ($priority !== '' && !in_array($priority, $allowed_priorities, true)) {
        $validation_errors[] = "Invalid priority selected.";
    }

    if ($leave_from_date && $leave_to_date && $leave_from_date > $leave_to_date) {
        $validation_errors[] = "Leave from date cannot be after leave to date.";
    }

    $today = date('Y-m-d');
    if ($leave_from_date && $leave_from_date < $today) {
        $validation_errors[] = "Leave from date cannot be in the past.";
    }

    // Check leave balance (count-based) except unpaid
    if ($leave_type !== '' && $leave_type !== 'Unpaid') {
        $count_stmt = mysqli_prepare($conn, "
            SELECT COUNT(*) AS leave_count
            FROM employee_requests
            WHERE employee_id = ?
              AND request_type = 'Leave'
              AND leave_type = ?
              AND status IN ('Approved', 'Pending')
              AND YEAR(request_date) = YEAR(CURDATE())
        ");
        if ($count_stmt) {
            mysqli_stmt_bind_param($count_stmt, "is", $employeeId, $leave_type);
            mysqli_stmt_execute($count_stmt);
            $count_res = mysqli_stmt_get_result($count_stmt);
            $count_row = mysqli_fetch_assoc($count_res);
            mysqli_stmt_close($count_stmt);

            $used_count = (int)($count_row['leave_count'] ?? 0);

            $type_balance = 0;
            if ($leave_type === 'Annual') $type_balance = $leaveBalance['annual'];
            if ($leave_type === 'Sick')   $type_balance = $leaveBalance['sick'];
            if ($leave_type === 'Casual') $type_balance = $leaveBalance['casual'];

            if ($type_balance > 0 && $used_count >= $type_balance) {
                $validation_errors[] = "No {$leave_type} leave balance available. You've used all {$type_balance} leaves.";
            }
        }
    }

    // Attachment
    $attachment_path = null;
    $upload_result = handleAttachmentUpload('attachments');

    if (!empty($upload_result['no_file'])) {
        $attachment_path = null;
    } elseif (empty($upload_result['success'])) {
        $validation_errors[] = $upload_result['error'] ?? 'Attachment upload failed.';
    } else {
        $attachment_path = $upload_result['path'];
    }

    // Approver info for this manager (manager's manager)
    $approverId   = isset($managerInfo['id']) ? (int)$managerInfo['id'] : null;
    $approverName = $managerInfo['full_name'] ?? null;

    if (empty($validation_errors)) {

        $request_no = generateRequestNo($conn);

        $full_description  = "Leave Request Details:\n";
        $full_description .= "Type: {$leave_type}\n";
        $full_description .= "Period: {$leave_from_date} to {$leave_to_date} ({$leave_days} days)\n";
        $full_description .= "Contact during leave: {$contact_during_leave}\n\n";
        $full_description .= "Description:\n{$request_description}\n\n";
        $full_description .= "Handover Notes:\n{$handover_notes}";

        $sql = "INSERT INTO employee_requests (
                    employee_id, manager_id, manager_name, request_no, request_type, request_subject,
                    request_description, priority, request_date, attachments,
                    leave_from_date, leave_to_date, leave_type, status
                ) VALUES (?, ?, ?, ?, 'Leave', ?, ?, ?, CURDATE(), ?, ?, ?, ?, 'Pending')";

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            $error = "Database error: Failed to prepare statement - " . mysqli_error($conn);
            if ($attachment_path && file_exists($attachment_path)) {
                @unlink($attachment_path);
            }
        } else {
            // manager_id/manager_name fields are used as approver here (manager's manager)
            mysqli_stmt_bind_param(
                $stmt,
                "iisssssssss",
                $employeeId,
                $approverId,
                $approverName,
                $request_no,
                $request_subject,
                $full_description,
                $priority,
                $attachment_path,
                $leave_from_date,
                $leave_to_date,
                $leave_type
            );

            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['flash_success'] = "Leave request submitted successfully! Request No: " . $request_no;
                mysqli_stmt_close($stmt);
                header("Location: leave-request-list.php");
                exit;
            } else {
                $error = "Failed to submit request: " . mysqli_stmt_error($stmt);
                if ($attachment_path && file_exists($attachment_path)) {
                    @unlink($attachment_path);
                }
                mysqli_stmt_close($stmt);
            }
        }
    } else {
        if (!empty($upload_result['success']) && !empty($upload_result['path']) && file_exists($upload_result['path'])) {
            @unlink($upload_result['path']);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Manager Leave Request - <?php echo e($employeeName); ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

        /* DAR-style page UI */
        .panel{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:16px;
            box-shadow:0 10px 30px rgba(17,24,39,.05);
            padding:16px;
            margin-bottom:14px;
        }
        .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .h-title{ margin:0; font-weight:1000; color:#111827; }
        .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

        .sec-head{
            display:flex; align-items:center; gap:10px;
            padding:10px 12px;
            border-radius:14px;
            background:#f9fafb;
            border:1px solid #eef2f7;
            margin-bottom:10px;
        }
        .sec-ic{
            width:34px;height:34px;border-radius:12px;
            display:grid;place-items:center;
            background: rgba(45,156,219,.12);
            color: var(--blue);
            flex:0 0 auto;
        }
        .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
        .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

        .form-label{ font-weight:900; color:#374151; font-size:13px; margin-bottom:6px; }
        .required-field::after{ content:" *"; color: var(--red); }

        .form-control, .form-select{
            border:2px solid #e5e7eb;
            border-radius:12px;
            padding:10px 12px;
            font-weight:750;
            font-size:14px;
        }
        .form-control:focus, .form-select:focus{
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(45,156,219,.10);
        }

        .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .grid-3{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }

        .badge-pill{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 10px; border-radius:999px;
            border:1px solid #e5e7eb; background:#fff;
            font-weight:900; font-size:12px;
        }

        .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

        .info-box{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:14px;
            padding:12px;
            height:100%;
        }
        .info-label{
            color:#6b7280;
            font-weight:800;
            font-size:12px;
            margin-bottom:4px;
        }
        .info-value{
            color:#111827;
            font-weight:1000;
            font-size:15px;
            line-height:1.3;
        }

        .balance-grid{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:10px;
            margin-top:8px;
        }
        .balance-card{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:12px;
            padding:12px;
            text-align:center;
        }
        .balance-type{
            font-size:11px;
            color:#6b7280;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.2px;
        }
        .balance-value{
            font-size:22px;
            font-weight:1000;
            color: var(--blue);
            line-height:1.2;
            margin:5px 0;
        }
        .balance-used{
            font-size:11px;
            color:#6b7280;
            font-weight:700;
        }

        .days-card{
            background:#e0f2fe;
            border:1px solid #bae6fd;
            border-radius:12px;
            padding:12px 14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
        }
        .days-count{
            font-size:22px;
            line-height:1;
            font-weight:1000;
            color:var(--blue);
        }

        .file-upload-area{
            border:2px dashed #d1d5db;
            border-radius:14px;
            padding:24px;
            text-align:center;
            cursor:pointer;
            transition:all .2s ease;
            background:#f8fafc;
        }
        .file-upload-area:hover{
            border-color: var(--blue);
            background:#f1f5f9;
        }
        .file-upload-area i{
            font-size:30px;
            color:#64748b;
            margin-bottom:8px;
        }
        .file-upload-area p{
            margin:0;
            color:#475569;
            font-weight:800;
            font-size:14px;
        }
        .file-upload-area small{
            color:#94a3b8;
            font-size:11px;
            font-weight:700;
        }

        .btn-primary-tek{
            background: var(--blue);
            border:none;
            border-radius:12px;
            padding:10px 16px;
            font-weight:1000;
            display:inline-flex;
            align-items:center;
            gap:8px;
            box-shadow: 0 12px 26px rgba(45,156,219,.18);
            color:#fff;
            text-decoration:none;
        }
        .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }

        .btn-soft{
            border-radius:12px;
            font-weight:900;
            padding:10px 14px;
            display:inline-flex;
            align-items:center;
            gap:8px;
            text-decoration:none;
        }

        .notice-list{
            margin:0;
            padding-left:18px;
        }
        .notice-list li{
            margin-bottom:4px;
            font-weight:700;
            color:#7f1d1d;
        }

        @media (max-width: 992px){
            .grid-2, .grid-3{ grid-template-columns:1fr; }
            .balance-grid{ grid-template-columns:repeat(2, minmax(0,1fr)); }
        }
        @media (max-width: 576px){
            .balance-grid{ grid-template-columns:1fr; }
            .btn-primary-tek, .btn-soft{ justify-content:center; width:100%; }
        }
    </style>
</head>
<body>
<div class="app">

    <?php include 'includes/sidebar.php'; ?>

    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid maxw">

                <!-- Header (DAR-style) -->
                <div class="title-row mb-3">
                    <div>
                        <h1 class="h-title">Manager Leave Request</h1>
                        <p class="h-sub">Submit your leave request with DAR-style page layout</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
                        <a href="leave-request-list.php" class="btn btn-outline-secondary btn-soft">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($validation_errors)): ?>
                    <div class="alert alert-danger" role="alert" style="border-radius:14px;">
                        <div class="fw-bold mb-2"><i class="bi bi-exclamation-circle-fill me-2"></i>Please fix the following:</div>
                        <ul class="notice-list">
                            <?php foreach ($validation_errors as $err): ?>
                                <li><?php echo e($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Employee / Approver + Balance -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-people"></i></div>
                        <div>
                            <p class="sec-title mb-0">Employee & Approver Details</p>
                            <p class="sec-sub mb-0">Your profile, approver and leave balance summary</p>
                        </div>
                    </div>

                    <div class="grid-2 mb-3">
                        <div class="info-box">
                            <div class="info-label">Manager (Employee)</div>
                            <div class="info-value"><?php echo e($employeeName); ?></div>
                        </div>
                        <div class="info-box">
                            <div class="info-label">Approver</div>
                            <div class="info-value"><?php echo e($managerInfo['full_name'] ?? 'Not Assigned'); ?></div>
                            <div class="small-muted mt-1"><?php echo e($managerInfo['designation'] ?? ''); ?></div>
                        </div>
                    </div>

                    <div class="sec-head mb-2">
                        <div class="sec-ic"><i class="bi bi-pie-chart"></i></div>
                        <div>
                            <p class="sec-title mb-0">Leave Balance</p>
                            <p class="sec-sub mb-0">Count-based summary for current year</p>
                        </div>
                    </div>

                    <div class="balance-grid">
                        <div class="balance-card">
                            <div class="balance-type">Annual</div>
                            <div class="balance-value"><?php echo (int)$leaveBalance['annual']; ?></div>
                            <div class="balance-used">Approved: <?php echo (int)$leaveBalance['approved']; ?></div>
                        </div>
                        <div class="balance-card">
                            <div class="balance-type">Sick</div>
                            <div class="balance-value"><?php echo (int)$leaveBalance['sick']; ?></div>
                            <div class="balance-used">Quota</div>
                        </div>
                        <div class="balance-card">
                            <div class="balance-type">Casual</div>
                            <div class="balance-value"><?php echo (int)$leaveBalance['casual']; ?></div>
                            <div class="balance-used">Quota</div>
                        </div>
                        <div class="balance-card">
                            <div class="balance-type">Available</div>
                            <div class="balance-value" style="color: var(--green);"><?php echo (int)$leaveBalance['available']; ?></div>
                            <div class="balance-used">Pending: <?php echo (int)$leaveBalance['pending']; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <form method="POST" enctype="multipart/form-data" id="leaveRequestForm" autocomplete="off">

                    <!-- Leave Details -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-calendar-week"></i></div>
                            <div>
                                <p class="sec-title mb-0">Leave Details</p>
                                <p class="sec-sub mb-0">Leave type and date range</p>
                            </div>
                        </div>

                        <div class="grid-3">
                            <div>
                                <label class="form-label required-field">Leave Type</label>
                                <select class="form-select" name="leave_type" id="leaveType" required>
                                    <option value="">Select</option>
                                    <option value="Annual" <?php echo (($_POST['leave_type'] ?? '') === 'Annual') ? 'selected' : ''; ?>>Annual Leave</option>
                                    <option value="Sick" <?php echo (($_POST['leave_type'] ?? '') === 'Sick') ? 'selected' : ''; ?>>Sick Leave</option>
                                    <option value="Casual" <?php echo (($_POST['leave_type'] ?? '') === 'Casual') ? 'selected' : ''; ?>>Casual Leave</option>
                                    <option value="Unpaid" <?php echo (($_POST['leave_type'] ?? '') === 'Unpaid') ? 'selected' : ''; ?>>Unpaid Leave</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label required-field">From Date</label>
                                <input type="date" class="form-control" name="leave_from_date" id="leaveFromDate"
                                       value="<?php echo e($_POST['leave_from_date'] ?? ''); ?>"
                                       min="<?php echo e(date('Y-m-d')); ?>" required>
                            </div>

                            <div>
                                <label class="form-label required-field">To Date</label>
                                <input type="date" class="form-control" name="leave_to_date" id="leaveToDate"
                                       value="<?php echo e($_POST['leave_to_date'] ?? ''); ?>"
                                       min="<?php echo e(date('Y-m-d')); ?>" required>
                            </div>
                        </div>

                        <div id="leaveDaysInfo" class="days-card mt-3" style="display:none;">
                            <span class="fw-bold"><i class="bi bi-calendar-check me-2"></i>Total Leave Days</span>
                            <span class="days-count" id="leaveDaysCount">0</span>
                        </div>
                    </div>

                    <!-- Request Details -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-file-earmark-text"></i></div>
                            <div>
                                <p class="sec-title mb-0">Request Details</p>
                                <p class="sec-sub mb-0">Subject, priority, reason and handover notes</p>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div style="grid-column: 1 / -1;">
                                <label class="form-label required-field">Subject</label>
                                <input type="text" class="form-control" name="request_subject"
                                       value="<?php echo e($_POST['request_subject'] ?? ''); ?>"
                                       placeholder="Brief subject"
                                       required maxlength="255">
                            </div>

                            <div>
                                <label class="form-label required-field">Priority</label>
                                <select class="form-select" name="priority" required>
                                    <option value="Low" <?php echo (($_POST['priority'] ?? 'Medium') === 'Low') ? 'selected' : ''; ?>>Low</option>
                                    <option value="Medium" <?php echo (($_POST['priority'] ?? 'Medium') === 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                    <option value="High" <?php echo (($_POST['priority'] ?? 'Medium') === 'High') ? 'selected' : ''; ?>>High</option>
                                    <option value="Urgent" <?php echo (($_POST['priority'] ?? 'Medium') === 'Urgent') ? 'selected' : ''; ?>>Urgent</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Contact During Leave</label>
                                <input type="text" class="form-control" name="contact_during_leave"
                                       value="<?php echo e($_POST['contact_during_leave'] ?? ''); ?>"
                                       placeholder="Phone / Email">
                            </div>

                            <div style="grid-column: 1 / -1;">
                                <label class="form-label required-field">Reason for Leave</label>
                                <textarea class="form-control" name="request_description" rows="4"
                                          placeholder="Detailed reason for leave" required><?php echo e($_POST['request_description'] ?? ''); ?></textarea>
                            </div>

                            <div style="grid-column: 1 / -1;">
                                <label class="form-label">Work Handover Notes</label>
                                <textarea class="form-control" name="handover_notes" rows="3"
                                          placeholder="Who will handle your work / pending items?"><?php echo e($_POST['handover_notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Supporting Documents -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-paperclip"></i></div>
                            <div>
                                <p class="sec-title mb-0">Supporting Documents</p>
                                <p class="sec-sub mb-0">Optional attachment (max 5MB)</p>
                            </div>
                        </div>

                        <div class="file-upload-area" onclick="document.getElementById('attachments').click();">
                            <i class="bi bi-cloud-upload d-block"></i>
                            <p>Click to upload file</p>
                            <small>Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG, TXT (Max 5MB)</small>
                            <input type="file" class="d-none" name="attachments" id="attachments"
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt">
                        </div>

                        <div id="fileSelected" class="mt-2 text-success small fw-bold" style="display:none;">
                            <i class="bi bi-check-circle-fill me-1"></i><span id="fileName"></span>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="panel">
                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                            <a href="dashboard.php" class="btn btn-outline-secondary btn-soft">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-send"></i> Submit Request
                            </button>
                        </div>
                    </div>

                </form>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    calculateLeaveDays();

    const fromEl = document.getElementById('leaveFromDate');
    const toEl   = document.getElementById('leaveToDate');
    const fileEl = document.getElementById('attachments');
    const formEl = document.getElementById('leaveRequestForm');

    if (fromEl) fromEl.addEventListener('change', calculateLeaveDays);
    if (toEl)   toEl.addEventListener('change', calculateLeaveDays);

    if (fileEl) {
        fileEl.addEventListener('change', function() {
            const fileSelected = document.getElementById('fileSelected');
            const fileName = document.getElementById('fileName');

            if (this.files && this.files.length > 0) {
                if (fileName) fileName.textContent = this.files[0].name;
                if (fileSelected) fileSelected.style.display = 'block';
            } else {
                if (fileSelected) fileSelected.style.display = 'none';
            }
        });
    }

    if (formEl) {
        formEl.addEventListener('submit', function(e) {
            const leaveType = document.getElementById('leaveType')?.value || '';
            const fromDate = document.getElementById('leaveFromDate')?.value || '';
            const toDate = document.getElementById('leaveToDate')?.value || '';
            const subject = document.querySelector('input[name="request_subject"]')?.value.trim() || '';
            const description = document.querySelector('textarea[name="request_description"]')?.value.trim() || '';

            if (!leaveType || !fromDate || !toDate || !subject || !description) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return;
            }

            if (new Date(fromDate) > new Date(toDate)) {
                e.preventDefault();
                alert('From date cannot be after To date.');
                return;
            }
        });
    }
});

function calculateLeaveDays() {
    const fromDate = document.getElementById('leaveFromDate')?.value;
    const toDate   = document.getElementById('leaveToDate')?.value;
    const info     = document.getElementById('leaveDaysInfo');
    const count    = document.getElementById('leaveDaysCount');

    if (!info || !count) return;

    if (fromDate && toDate) {
        const from = new Date(fromDate);
        const to   = new Date(toDate);

        if (isNaN(from.getTime()) || isNaN(to.getTime())) {
            info.style.display = 'none';
            return;
        }

        const diff = to.getTime() - from.getTime();
        const diffDays = Math.floor(diff / (1000 * 60 * 60 * 24)) + 1;

        if (diffDays > 0) {
            count.textContent = diffDays;
            info.style.display = 'flex';
        } else {
            info.style.display = 'none';
        }
    } else {
        info.style.display = 'none';
    }
}
</script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    mysqli_close($conn);
}
?>  