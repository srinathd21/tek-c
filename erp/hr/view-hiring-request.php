<?php
// hr/view-hiring-request.php - View Hiring Request Details
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

function hrTableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function hrColumnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEsc = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function logHiringActivityCurrentDb($conn, int $employeeId, string $activityType, string $description, int $referenceId, array $newData = []): bool {
    if (!$conn || !hrTableExists($conn, 'activity_logs')) return false;

    $newJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;
    $employeeName = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'System';
    $username = $_SESSION['username'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $department = $_SESSION['department'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $map = [
        'employee_id'   => ['i', $employeeId],
        'employee_name' => ['s', $employeeName],
        'username'      => ['s', $username],
        'designation'   => ['s', $designation],
        'department'    => ['s', $department],
        'activity_type' => ['s', $activityType],
        'module'        => ['s', 'hiring_request'],
        'description'   => ['s', $description],
        'reference_id'  => ['i', $referenceId],
        'new_data'      => ['s', $newJson],
        'ip_address'    => ['s', $ipAddress],
    ];

    $columns = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (hrColumnExists($conn, 'activity_logs', $column)) {
            $columns[] = "`$column`";
            $types .= $pair[0];
            $values[] = $pair[1];
        }
    }

    if (!$columns) return false;

    $sql = "INSERT INTO activity_logs (" . implode(',', $columns) . ") VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function createNotificationCurrentDb($conn, int $employeeId, string $title, string $message, string $module, int $referenceId, string $link, string $type = 'hiring'): bool {
    if ($employeeId <= 0 || !$conn || !hrTableExists($conn, 'notifications')) return false;

    $map = [
        'employee_id'  => ['i', $employeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', $type],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'priority'     => ['s', 'normal'],
        'is_read'      => ['i', 0],
        'created_at'   => ['raw', 'NOW()'],
    ];

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (hrColumnExists($conn, 'notifications', $column)) {
            $columns[] = "`$column`";
            if ($pair[0] === 'raw') {
                $placeholders[] = $pair[1];
            } else {
                $placeholders[] = '?';
                $types .= $pair[0];
                $values[] = $pair[1];
            }
        }
    }

    if (!$columns) return false;

    $sql = "INSERT INTO notifications (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    if ($values) mysqli_stmt_bind_param($stmt, $types, ...$values);

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function notifyHiringRequestCreator($conn, array $request, int $actorId, string $status, string $remarks = ''): void {
    $creatorId = (int)($request['requested_by'] ?? 0);
    if ($creatorId <= 0 || $creatorId === $actorId) return;

    $requestNo = (string)($request['request_no'] ?? '');
    $positionTitle = (string)($request['position_title'] ?? '');

    $title = $status === 'Approved' ? 'Hiring request approved' : 'Hiring request declined';
    $message = 'Your hiring request ' . $requestNo . ' for ' . $positionTitle . ' has been ' . strtolower($status) . ' by HR.';
    if ($remarks !== '') $message .= ' Remarks: ' . $remarks;

    createNotificationCurrentDb(
        $conn,
        $creatorId,
        $title,
        $message,
        'hiring_request',
        (int)$request['id'],
        'view-hiring-request.php?id=' . (int)$request['id'],
        'hiring'
    );
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
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'director', 'administrator', 'admin']);
$isAdmin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director');

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- PAGE MESSAGE ----------------
$message = '';
$messageType = '';

// ---------------- GET HIRING REQUEST ID ----------------
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($request_id === 0) {
    header("Location: hiring-requests.php");
    exit;
}

// ---------------- FETCH HIRING REQUEST DETAILS ----------------
$query = "
    SELECT h.*, 
           e1.full_name as requester_name, e1.employee_code as requester_code, e1.designation as requester_designation,
           e2.full_name as approver_name, e2.employee_code as approver_code,
           e3.full_name as rejecter_name
    FROM hiring_requests h
    LEFT JOIN employees e1 ON h.requested_by = e1.id
    LEFT JOIN employees e2 ON h.approved_by = e2.id
    LEFT JOIN employees e3 ON h.rejected_by = e3.id
    WHERE h.id = ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$request) {
    $_SESSION['flash_error'] = "Hiring request not found.";
    header("Location: hiring-requests.php");
    exit;
}

// Check permission - managers can only view their own requests
if (!$isHr && !$isAdmin && $request['requested_by'] != $current_employee_id) {
    $_SESSION['flash_error'] = "You don't have permission to view this request.";
    header("Location: hiring-requests.php");
    exit;
}

// ---------------- HANDLE APPROVE/DECLINE ON THIS PAGE ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($isHr || $isAdmin)) {
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if (($request['status'] ?? '') !== 'Pending') {
        $message = "Only pending hiring requests can be processed.";
        $messageType = "danger";
    } elseif ($action === 'approve') {
        $update_stmt = mysqli_prepare($conn, "
            UPDATE hiring_requests
            SET status = 'Approved',
                approved_by = ?,
                approved_by_name = ?,
                approved_at = NOW(),
                approver_remarks = ?
            WHERE id = ?
              AND status = 'Pending'
        ");

        if ($update_stmt) {
            mysqli_stmt_bind_param($update_stmt, "issi", $current_employee_id, $current_employee['full_name'], $remarks, $request_id);

            if (mysqli_stmt_execute($update_stmt)) {
                logHiringActivityCurrentDb(
                    $conn,
                    (int)$current_employee_id,
                    'UPDATE',
                    "Approved hiring request ID: {$request_id}",
                    $request_id,
                    ['remarks' => $remarks, 'status' => 'Approved']
                );

                notifyHiringRequestCreator($conn, $request, (int)$current_employee_id, 'Approved', $remarks);

                $message = "Hiring request approved successfully!";
                $messageType = "success";

                $request['status'] = 'Approved';
                $request['approved_by'] = $current_employee_id;
                $request['approved_by_name'] = $current_employee['full_name'];
                $request['approver_name'] = $current_employee['full_name'];
                $request['approved_at'] = date('Y-m-d H:i:s');
                $request['approver_remarks'] = $remarks;
            } else {
                $message = "Error approving request: " . mysqli_stmt_error($update_stmt);
                $messageType = "danger";
            }

            mysqli_stmt_close($update_stmt);
        } else {
            $message = "Error preparing approval: " . mysqli_error($conn);
            $messageType = "danger";
        }
    } elseif ($action === 'decline' || $action === 'reject') {
        if ($remarks === '') {
            $message = "Please enter decline reason.";
            $messageType = "danger";
        } else {
            $update_stmt = mysqli_prepare($conn, "
                UPDATE hiring_requests
                SET status = 'Rejected',
                    rejected_by = ?,
                    rejected_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
                  AND status = 'Pending'
            ");

            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, "isi", $current_employee_id, $remarks, $request_id);

                if (mysqli_stmt_execute($update_stmt)) {
                    logHiringActivityCurrentDb(
                        $conn,
                        (int)$current_employee_id,
                        'UPDATE',
                        "Declined hiring request ID: {$request_id}",
                        $request_id,
                        ['reason' => $remarks, 'status' => 'Rejected']
                    );

                    notifyHiringRequestCreator($conn, $request, (int)$current_employee_id, 'Rejected', $remarks);

                    $message = "Hiring request declined successfully!";
                    $messageType = "success";

                    $request['status'] = 'Rejected';
                    $request['rejected_by'] = $current_employee_id;
                    $request['rejecter_name'] = $current_employee['full_name'];
                    $request['rejected_at'] = date('Y-m-d H:i:s');
                    $request['rejection_reason'] = $remarks;
                } else {
                    $message = "Error declining request: " . mysqli_stmt_error($update_stmt);
                    $messageType = "danger";
                }

                mysqli_stmt_close($update_stmt);
            } else {
                $message = "Error preparing decline: " . mysqli_error($conn);
            }
        }
    }
}

// ---------------- FETCH CANDIDATES FOR THIS REQUEST ----------------
$candidates_query = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM interviews WHERE candidate_id = c.id) as interview_count,
           (SELECT MAX(round_number) FROM interviews WHERE candidate_id = c.id) as current_round
    FROM candidates c
    WHERE c.hiring_request_id = ?
    ORDER BY c.created_at DESC
";
$candidates_stmt = mysqli_prepare($conn, $candidates_query);
mysqli_stmt_bind_param($candidates_stmt, "i", $request_id);
mysqli_stmt_execute($candidates_stmt);
$candidates_result = mysqli_stmt_get_result($candidates_stmt);
$candidates = [];
$candidate_counts = [
    'total' => 0,
    'new' => 0,
    'screening' => 0,
    'shortlisted' => 0,
    'interview' => 0,
    'selected' => 0,
    'rejected' => 0,
    'offered' => 0,
    'joined' => 0
];

while ($row = mysqli_fetch_assoc($candidates_result)) {
    $candidates[] = $row;
    $candidate_counts['total']++;
    
    switch ($row['status']) {
        case 'New': $candidate_counts['new']++; break;
        case 'Screening': $candidate_counts['screening']++; break;
        case 'Shortlisted': $candidate_counts['shortlisted']++; break;
        case 'Interview Scheduled':
        case 'Interviewed': $candidate_counts['interview']++; break;
        case 'Selected': $candidate_counts['selected']++; break;
        case 'Rejected':
        case 'Declined': $candidate_counts['rejected']++; break;
        case 'Offered': $candidate_counts['offered']++; break;
        case 'Joined': $candidate_counts['joined']++; break;
    }
}
mysqli_stmt_close($candidates_stmt);

// ---------------- FETCH INTERVIEWS FOR THIS REQUEST ----------------
$interviews_query = "
    SELECT i.*, 
           c.first_name, c.last_name, c.photo_path as candidate_photo,
           CONCAT(c.first_name, ' ', c.last_name) as candidate_name
    FROM interviews i
    JOIN candidates c ON i.candidate_id = c.id
    WHERE i.hiring_request_id = ?
    ORDER BY i.interview_date DESC, i.interview_time DESC
    LIMIT 10
";
$interviews_stmt = mysqli_prepare($conn, $interviews_query);
mysqli_stmt_bind_param($interviews_stmt, "i", $request_id);
mysqli_stmt_execute($interviews_stmt);
$interviews_result = mysqli_stmt_get_result($interviews_stmt);
$interviews = [];
if ($interviews_result) {
    while ($row = mysqli_fetch_assoc($interviews_result)) {
        $interviews[] = $row;
    }
}
mysqli_stmt_close($interviews_stmt);

// ---------------- HELPER FUNCTIONS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function formatCurrency($amount) {
    if (!$amount) return '—';
    return '₹ ' . number_format($amount, 2) . ' LPA';
}

function getStatusBadge($status) {
    $status = trim((string)$status);
    $map = [
        'Pending'     => ['pending', 'bi-clock'],
        'Approved'    => ['ontrack', 'bi-check-circle'],
        'In Progress' => ['progressing', 'bi-gear'],
        'Rejected'    => ['atrisk', 'bi-x-circle'],
        'Closed'      => ['ontrack', 'bi-check2-circle'],
        'Cancelled'   => ['neutral', 'bi-x']
    ];
    $item = $map[$status] ?? ['neutral', 'bi-info-circle'];
    return "<span class='badge-pill {$item[0]}'><i class='bi {$item[1]}'></i> " . e($status ?: '—') . "</span>";
}

function getPriorityBadge($priority) {
    $priority = trim((string)$priority);
    $map = [
        'Urgent' => ['atrisk', 'bi-exclamation-triangle-fill'],
        'High'   => ['warning', 'bi-arrow-up-circle-fill'],
        'Medium' => ['progressing', 'bi-dash-circle-fill'],
        'Low'    => ['neutral', 'bi-arrow-down-circle-fill']
    ];
    $item = $map[$priority] ?? ['neutral', 'bi-info-circle'];
    return "<span class='badge-pill {$item[0]}'><i class='bi {$item[1]}'></i> " . e($priority ?: '—') . "</span>";
}

function getCandidateStatusBadge($status) {
    $status = trim((string)$status);
    $map = [
        'New' => ['progressing', 'bi-person-plus'],
        'Screening' => ['neutral', 'bi-search'],
        'Shortlisted' => ['progressing', 'bi-list-check'],
        'Interview Scheduled' => ['warning', 'bi-calendar-event'],
        'Interviewed' => ['pending', 'bi-camera-video'],
        'Selected' => ['ontrack', 'bi-check-circle'],
        'Rejected' => ['atrisk', 'bi-x-circle'],
        'On Hold' => ['neutral', 'bi-pause-circle'],
        'Offered' => ['ontrack', 'bi-envelope-check'],
        'Joined' => ['ontrack', 'bi-person-check'],
        'Declined' => ['atrisk', 'bi-x-circle']
    ];
    $item = $map[$status] ?? ['neutral', 'bi-info-circle'];
    return "<span class='badge-pill {$item[0]}'><i class='bi {$item[1]}'></i> " . e($status ?: '—') . "</span>";
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hiring Request Details - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        :root{--page-bg:#f5f7fb;--card-bg:#fff;--border:#e5e7eb;--text:#111827;--muted:#6b7280;--soft:#f8fafc;--shadow:0 10px 26px rgba(15,23,42,.055);--radius:15px;--blue:#2f80ed;--green:#27ae60;--orange:#f2994a;--red:#eb5757;--purple:#7c3aed}
        body{background:var(--page-bg)}.content-scroll{flex:1 1 auto;overflow:auto;padding:16px}.projects-wrapper{width:100%}
        .page-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.page-heading h1{font-size:19px;font-weight:950;color:var(--text);margin:0;display:flex;align-items:center;gap:8px}.page-heading p{margin:3px 0 0;color:var(--muted);font-size:12px;font-weight:650}
        .primary-btn,.secondary-btn,.success-btn,.danger-btn,.btn-action{min-height:36px;padding:0 14px;border-radius:11px;font-size:12px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:7px;text-decoration:none;white-space:nowrap;line-height:1;border:0}.primary-btn{background:#111827;color:#fff}.primary-btn:hover{background:#020617;color:#fff}.success-btn{background:#16a34a;color:#fff}.success-btn:hover{background:#15803d;color:#fff}.danger-btn{background:#dc2626;color:#fff}.danger-btn:hover{background:#b91c1c;color:#fff}.secondary-btn,.btn-action{border:1px solid var(--border);background:#fff;color:#334155}.secondary-btn:hover,.btn-action:hover{border-color:#cbd5e1;background:#f8fafc;color:#111827}
        .panel{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:13px;margin-bottom:14px}.panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.panel-title{font-weight:950;font-size:14px;color:var(--text);margin:0;display:flex;align-items:center;gap:8px}.panel-title i{color:var(--blue);font-size:16px}
        .quick-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px}.quick-stat{background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:12px 13px;min-height:78px;display:flex;align-items:center;gap:11px;text-align:left}.quick-stat .icon{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;color:#fff;font-size:17px;flex:0 0 auto;background:#111827}.quick-stat .icon.blue{background:var(--blue)}.quick-stat .icon.green{background:var(--green)}.quick-stat .icon.orange{background:var(--orange)}.quick-stat .icon.purple{background:var(--purple)}.quick-stat .number{font-size:24px;font-weight:950;line-height:1;color:#111827}.quick-stat .label{color:#64748b;font-weight:850;font-size:10.5px;text-transform:uppercase;margin-top:2px}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}.info-item{padding:10px 11px;background:#f8fafc;border-radius:12px;border:1px solid #eef2f7;margin-bottom:0}.info-label{font-size:10px;color:#64748b;font-weight:950;text-transform:uppercase;margin-bottom:3px}.info-value{font-weight:900;color:#111827;font-size:12px;word-break:break-word}.description-box{background:#f8fafc;border:1px solid #eef2f7;border-radius:13px;padding:12px;color:#334155;font-size:12px;font-weight:750;line-height:1.5}
        .badge-pill{border-radius:999px;padding:5px 8px;font-weight:900;font-size:10px;display:inline-flex;align-items:center;gap:6px;border:1px solid transparent;text-decoration:none;white-space:nowrap}.ontrack{color:#15803d;background:#dcfce7;border-color:#bbf7d0}.progressing{color:#2563eb;background:#dbeafe;border-color:#bfdbfe}.pending{color:#6d28d9;background:#ede9fe;border-color:#ddd6fe}.atrisk{color:#b91c1c;background:#fee2e2;border-color:#fecaca}.neutral{color:#475569;background:#f1f5f9;border-color:#e2e8f0}.warning{color:#b45309;background:#ffedd5;border-color:#fed7aa}
        .compact-table-wrap{width:100%;border:1px solid var(--border);border-radius:13px;overflow:hidden;background:#fff}.compact-table{width:100%;margin:0;table-layout:auto}.compact-table thead th{background:var(--soft);color:#64748b;font-size:10px;text-transform:uppercase;font-weight:900;border-bottom:1px solid var(--border)!important;padding:8px 9px;white-space:nowrap}.compact-table tbody td{padding:8px 9px;vertical-align:middle;border-color:#eef2f7;color:#334155;font-weight:700;font-size:11.5px}.compact-table tbody tr:hover{background:#fbfdff}
        .candidate-avatar{width:34px;height:34px;border-radius:12px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:900;color:#4b5563;overflow:hidden;flex:0 0 auto}.candidate-avatar img{width:100%;height:100%;object-fit:cover}.timeline{position:relative;padding-left:10px}.timeline-item{position:relative;padding:0 0 16px 22px;border-left:2px solid #e5e7eb;margin-left:8px}.timeline-item:last-child{border-left-color:transparent}.timeline-dot{position:absolute;left:-9px;top:1px;width:16px;height:16px;border-radius:50%;background:#fff;border:3px solid #94a3b8}.timeline-dot.pending{border-color:#f59e0b}.timeline-dot.approved{border-color:#10b981}.timeline-dot.rejected{border-color:#ef4444}.timeline-date{font-size:10.5px;color:#64748b;font-weight:750;margin-bottom:3px}.timeline-title{font-weight:950;font-size:12px;color:#111827;margin-bottom:2px}.timeline-text{font-size:11px;color:#475569;font-weight:750}
        .summary-row{margin-bottom:10px}.summary-row .label{font-size:11px;font-weight:850;color:#475569}.summary-row .value{font-size:11px;font-weight:950;color:#111827}.progress{height:7px;background-color:#e5e7eb;border-radius:999px;overflow:hidden}.progress-bar{height:7px}.alert{border-radius:14px;border:1px solid transparent;box-shadow:var(--shadow);margin-bottom:14px;font-size:12px;font-weight:850}.alert-success{background:#dcfce7;border-color:#bbf7d0;color:#166534}.alert-danger{background:#fee2e2;border-color:#fecaca;color:#991b1b}.modal-content{border:1px solid var(--border);border-radius:16px;box-shadow:0 24px 55px rgba(15,23,42,.18)}.modal-header{border-bottom:1px solid #eef2f7;padding:14px 16px}.modal-title{font-size:15px;font-weight:950;color:#111827}.modal-body{padding:16px}.modal-footer{border-top:1px solid #eef2f7;padding:14px 16px}.required:after{content:" *";color:#ef4444}.form-label{font-size:11px;font-weight:900;color:#475569;text-transform:uppercase;margin-bottom:6px}.form-control{min-height:38px;border:1px solid var(--border);border-radius:11px;font-size:12px;font-weight:800;color:#111827;padding:8px 11px;background:#fff}.action-btn{min-height:31px;min-width:31px;padding:0 9px;border-radius:10px;border:1px solid var(--border);color:#334155;background:#fff;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.action-btn:hover{background:#eff6ff;border-color:#bfdbfe;color:#2563eb}
        @media(max-width:991.98px){.main{margin-left:0!important;width:100%!important;max-width:100%!important}.sidebar{position:fixed!important;transform:translateX(-100%);z-index:1040!important}.sidebar.open,.sidebar.active,.sidebar.show{transform:translateX(0)!important}}
        @media(max-width:1199px){.compact-table thead{display:none}.compact-table,.compact-table tbody,.compact-table tr,.compact-table td{display:block;width:100%}.compact-table tbody tr{border-bottom:1px solid var(--border);padding:10px}.compact-table tbody td{border:0;display:flex;justify-content:space-between;gap:12px}.compact-table tbody td::before{content:attr(data-label);font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;flex:0 0 105px}.compact-table tbody td:first-child{display:block}.compact-table tbody td:first-child::before{display:none}}
        @media(max-width:768px){.content-scroll{padding:12px 10px!important}.container-fluid.projects-wrapper{padding-left:0!important;padding-right:0!important}.page-heading{align-items:flex-start;flex-direction:column}.quick-stats{grid-template-columns:repeat(2,1fr)}.panel{padding:12px}.primary-btn,.secondary-btn,.success-btn,.danger-btn{width:100%}.modal-footer{flex-direction:column-reverse;align-items:stretch}}
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content-scroll">
            <div class="container-fluid projects-wrapper px-0">

                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
                        <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="page-heading">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h1><i class="bi bi-file-text"></i> Hiring Request Details</h1>
                            <?php echo getStatusBadge($request['status']); ?>
                            <?php echo getPriorityBadge($request['priority']); ?>
                        </div>
                        <p>Request #<?php echo e($request['request_no']); ?> • <?php echo e($request['position_title']); ?></p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <a href="hiring-requests.php" class="secondary-btn">
                            <i class="bi bi-arrow-left"></i>
                            Back
                        </a>

                        <?php if (($isHr || $isAdmin) && $request['status'] === 'Pending'): ?>
                            <button class="success-btn" onclick="openApproveModal()">
                                <i class="bi bi-check-lg"></i>
                                Approve
                            </button>
                            <button class="danger-btn" onclick="openDeclineModal()">
                                <i class="bi bi-x-lg"></i>
                                Decline
                            </button>
                        <?php endif; ?>

                        <?php if ($request['status'] === 'Approved' || $request['status'] === 'In Progress'): ?>
                            <a href="candidates.php?hiring_id=<?php echo $request_id; ?>" class="primary-btn">
                                <i class="bi bi-people"></i>
                                Candidates (<?php echo $candidate_counts['total']; ?>)
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="icon blue"><i class="bi bi-briefcase"></i></div>
                        <div>
                            <div class="number"><?php echo (int)$request['vacancies']; ?></div>
                            <div class="label">Vacancies</div>
                        </div>
                    </div>
                    <div class="quick-stat">
                        <div class="icon purple"><i class="bi bi-people"></i></div>
                        <div>
                            <div class="number"><?php echo $candidate_counts['total']; ?></div>
                            <div class="label">Total Candidates</div>
                        </div>
                    </div>
                    <div class="quick-stat">
                        <div class="icon orange"><i class="bi bi-camera-video"></i></div>
                        <div>
                            <div class="number"><?php echo $candidate_counts['interview']; ?></div>
                            <div class="label">In Interview</div>
                        </div>
                    </div>
                    <div class="quick-stat">
                        <div class="icon green"><i class="bi bi-check2-circle"></i></div>
                        <div>
                            <div class="number"><?php echo $candidate_counts['selected'] + $candidate_counts['offered'] + $candidate_counts['joined']; ?></div>
                            <div class="label">Selected</div>
                        </div>
                    </div>
                </div>

                <!-- Main Content Grid -->
                <div class="row">
                    <!-- Left Column - Request Details -->
                    <div class="col-lg-8">
                        <!-- Position Details -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-briefcase"></i>
                                    Position Details
                                </h5>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Department</div>
                                    <div class="info-value"><?php echo e($request['department']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Designation</div>
                                    <div class="info-value"><?php echo e($request['designation']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Position Title</div>
                                    <div class="info-value"><?php echo e($request['position_title']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Employment Type</div>
                                    <div class="info-value"><?php echo e($request['employment_type']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Location</div>
                                    <div class="info-value"><?php echo e($request['location']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Expected Joining</div>
                                    <div class="info-value">
                                        <?php echo $request['expected_joining_date'] ? date('d M Y', strtotime($request['expected_joining_date'])) : 'Flexible'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Experience & Compensation -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-bar-chart"></i>
                                    Experience & Compensation
                                </h5>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Experience Required</div>
                                    <div class="info-value">
                                        <?php echo (int)$request['experience_min']; ?> - <?php echo (int)$request['experience_max']; ?> years
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Salary Range</div>
                                    <div class="info-value">
                                        <?php echo formatCurrency($request['salary_min']); ?> - <?php echo formatCurrency($request['salary_max']); ?>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Qualification</div>
                                    <div class="info-value"><?php echo e($request['qualification'] ?: 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Skills Required</div>
                                    <div class="info-value"><?php echo e($request['skills_required'] ?: 'Not specified'); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Job Description -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-file-text"></i>
                                    Job Description
                                </h5>
                            </div>
                            
                            <div class="description-box">
                                <?php echo nl2br(e($request['job_description'])); ?>
                            </div>
                        </div>

                        <!-- Candidates Pipeline -->
                        <?php if ($candidate_counts['total'] > 0): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-people"></i>
                                    Candidate Pipeline
                                </h5>
                                <a href="candidates.php?hiring_id=<?php echo $request_id; ?>" class="secondary-btn">
                                    View All <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                            
                            <div class="compact-table-wrap">
                                <table class="table compact-table">
                                    <thead>
                                        <tr>
                                            <th>Candidate</th>
                                            <th>Status</th>
                                            <th>Experience</th>
                                            <th>Expected CTC</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $display_candidates = array_slice($candidates, 0, 5);
                                        foreach ($display_candidates as $candidate): 
                                        ?>
                                        <tr>
                                            <td data-label="Candidate">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="candidate-avatar" style="width:32px;height:32px;">
                                                        <?php if (!empty($candidate['photo_path'])): ?>
                                                            <img src="../<?php echo e($candidate['photo_path']); ?>" alt="Photo">
                                                        <?php else: ?>
                                                            <?php echo strtoupper(substr($candidate['first_name'], 0, 1) . substr($candidate['last_name'], 0, 1)); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?>
                                                        <?php if ($candidate['interview_count'] > 0): ?>
                                                            <small class="text-info">(R<?php echo $candidate['current_round']; ?>)</small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Status"><?php echo getCandidateStatusBadge($candidate['status']); ?></td>
                                            <td data-label="Experience"><?php echo $candidate['total_experience'] ? number_format($candidate['total_experience'], 1) . ' yrs' : 'Fresher'; ?></td>
                                            <td data-label="Expected CTC"><?php echo $candidate['expected_ctc'] ? '₹' . number_format($candidate['expected_ctc'], 2) . ' L' : '—'; ?></td>
                                            <td data-label="Action">
                                                <a href="view-candidate.php?id=<?php echo $candidate['id']; ?>" class="action-btn" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if (count($candidates) > 5): ?>
                                <div class="text-center mt-2">
                                    <a href="candidates.php?hiring_id=<?php echo $request_id; ?>" class="text-decoration-none">
                                        View all <?php echo count($candidates); ?> candidates <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Recent Interviews -->
                        <?php if (!empty($interviews)): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-camera-video"></i>
                                    Recent Interviews
                                </h5>
                            </div>
                            
                            <div class="compact-table-wrap">
                                <table class="table compact-table">
                                    <thead>
                                        <tr>
                                            <th>Candidate</th>
                                            <th>Round</th>
                                            <th>Date & Time</th>
                                            <th>Interviewer</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($interviews as $interview): ?>
                                        <tr>
                                            <td data-label="Candidate"><?php echo e($interview['candidate_name']); ?></td>
                                            <td data-label="Round"><?php echo e($interview['interview_round']); ?></td>
                                            <td data-label="Date & Time">
                                                <?php echo date('d M Y', strtotime($interview['interview_date'])); ?><br>
                                                <small><?php echo date('h:i A', strtotime($interview['interview_time'])); ?></small>
                                            </td>
                                            <td data-label="Interviewer"><?php echo e($interview['interviewer_name']); ?></td>
                                            <td data-label="Status">
                                                <span class="badge-pill <?php echo $interview['status'] === 'Scheduled' ? 'warning' : ($interview['status'] === 'Completed' ? 'ontrack' : 'neutral'); ?>">
                                                    <?php echo e($interview['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column - Request Info & Timeline -->
                    <div class="col-lg-4">
                        <!-- Request Information -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-info-circle"></i>
                                    Request Information
                                </h5>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Requested By</div>
                                <div class="info-value"><?php echo e($request['requester_name']); ?></div>
                                <div class="small text-muted"><?php echo e($request['requester_designation']); ?> (<?php echo e($request['requester_code']); ?>)</div>
                            </div>
                            
                            <div class="info-item mt-3">
                                <div class="info-label">Requested Date</div>
                                <div class="info-value"><?php echo date('d M Y', strtotime($request['requested_date'])); ?></div>
                            </div>
                            
                            <div class="info-item mt-3">
                                <div class="info-label">Reason for Hiring</div>
                                <div class="info-value"><?php echo e($request['reason_for_hiring']); ?></div>
                                <?php if ($request['replacement_for']): ?>
                                    <div class="small text-muted">Replacement for: <?php echo e($request['replacement_for']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($request['status'] === 'Approved' || $request['status'] === 'In Progress'): ?>
                            <div class="info-item mt-3">
                                <div class="info-label">Approved By</div>
                                <div class="info-value"><?php echo e($request['approver_name'] ?: 'N/A'); ?></div>
                                <?php if ($request['approved_at']): ?>
                                    <div class="small text-muted"><?php echo date('d M Y, h:i A', strtotime($request['approved_at'])); ?></div>
                                <?php endif; ?>
                                <?php if ($request['approver_remarks']): ?>
                                    <div class="small text-info mt-1">"<?php echo e($request['approver_remarks']); ?>"</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($request['status'] === 'Rejected'): ?>
                            <div class="info-item mt-3">
                                <div class="info-label">Rejected By</div>
                                <div class="info-value"><?php echo e($request['rejecter_name'] ?: 'N/A'); ?></div>
                                <?php if ($request['rejected_at']): ?>
                                    <div class="small text-muted"><?php echo date('d M Y, h:i A', strtotime($request['rejected_at'])); ?></div>
                                <?php endif; ?>
                                <?php if ($request['rejection_reason']): ?>
                                    <div class="alert alert-danger mt-2 p-2 small">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <?php echo e($request['rejection_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Timeline -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-clock-history"></i>
                                    Timeline
                                </h5>
                            </div>
                            
                            <div class="timeline">
                                <!-- Created -->
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date"><?php echo date('d M Y, h:i A', strtotime($request['created_at'])); ?></div>
                                    <div class="timeline-title">Request Created</div>
                                    <div class="timeline-text">By <?php echo e($request['requester_name']); ?></div>
                                </div>
                                
                                <!-- Status changes -->
                                <?php if ($request['approved_at']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date"><?php echo date('d M Y, h:i A', strtotime($request['approved_at'])); ?></div>
                                    <div class="timeline-title">Request Approved</div>
                                    <div class="timeline-text">By <?php echo e($request['approver_name']); ?></div>
                                    <?php if ($request['approver_remarks']): ?>
                                        <div class="timeline-text small text-info">"<?php echo e($request['approver_remarks']); ?>"</div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($request['rejected_at']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot rejected"></div>
                                    <div class="timeline-date"><?php echo date('d M Y, h:i A', strtotime($request['rejected_at'])); ?></div>
                                    <div class="timeline-title">Request Rejected</div>
                                    <div class="timeline-text">By <?php echo e($request['rejecter_name']); ?></div>
                                    <div class="timeline-text small text-danger"><?php echo e($request['rejection_reason']); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Latest candidate activity -->
                                <?php if ($candidate_counts['joined'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date">Recent</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['joined']; ?> Candidate(s) Joined</div>
                                </div>
                                <?php elseif ($candidate_counts['offered'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date">Recent</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['offered']; ?> Offer(s) Sent</div>
                                </div>
                                <?php elseif ($candidate_counts['selected'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot approved"></div>
                                    <div class="timeline-date">Recent</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['selected']; ?> Candidate(s) Selected</div>
                                </div>
                                <?php elseif ($candidate_counts['interview'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot pending"></div>
                                    <div class="timeline-date">Current</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['interview']; ?> Interview(s) in Progress</div>
                                </div>
                                <?php elseif ($candidate_counts['total'] > 0): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot pending"></div>
                                    <div class="timeline-date">Current</div>
                                    <div class="timeline-title"><?php echo $candidate_counts['total']; ?> Candidate(s) in Pipeline</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Candidate Summary -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-pie-chart"></i>
                                    Candidate Summary
                                </h5>
                            </div>
                            
                            <div class="summary-row">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="label">New / Screening</span>
                                    <span class="value"><?php echo $candidate_counts['new'] + $candidate_counts['screening']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo $candidate_counts['total'] > 0 ? (($candidate_counts['new'] + $candidate_counts['screening']) / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="summary-row">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="label">Shortlisted</span>
                                    <span class="value"><?php echo $candidate_counts['shortlisted']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-primary" style="width: <?php echo $candidate_counts['total'] > 0 ? ($candidate_counts['shortlisted'] / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="summary-row">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="label">Interview</span>
                                    <span class="value"><?php echo $candidate_counts['interview']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo $candidate_counts['total'] > 0 ? ($candidate_counts['interview'] / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="summary-row">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="label">Selected / Offered</span>
                                    <span class="value"><?php echo $candidate_counts['selected'] + $candidate_counts['offered']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $candidate_counts['total'] > 0 ? (($candidate_counts['selected'] + $candidate_counts['offered']) / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="summary-row">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="label">Joined</span>
                                    <span class="value"><?php echo $candidate_counts['joined']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $candidate_counts['total'] > 0 ? ($candidate_counts['joined'] / $candidate_counts['total'] * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="summary-row">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="label">Rejected</span>
                                    <span class="value"><?php echo $candidate_counts['rejected']; ?></span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-danger" style="width: <?php echo $candidate_counts['total'] > 0 ? ($candidate_counts['rejected'] / $candidate_counts['total'] * 100) : 0; ?>%"></div>
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

<!-- Approve Modal (HR only) -->
<?php if (($isHr || $isAdmin) && $request['status'] === 'Pending'): ?>
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="view-hiring-request.php?id=<?php echo $request_id; ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">Approve Hiring Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p>Are you sure you want to approve request <strong><?php echo e($request['request_no']); ?></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="success-btn">Approve Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="view-hiring-request.php?id=<?php echo $request_id; ?>">
                <input type="hidden" name="action" value="decline">
                <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">Decline Hiring Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p>Are you sure you want to reject request <strong><?php echo e($request['request_no']); ?></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label required">Reason for Decline</label>
                        <textarea name="remarks" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="danger-btn">Decline Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openApproveModal() {
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function openDeclineModal() {
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

</body>
</html>
