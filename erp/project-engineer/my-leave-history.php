<?php
// my-leave-history.php
// Updated to match manager projects compact UI/template.

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId   = (int)$_SESSION['employee_id'];
$employeeName = (string)($_SESSION['employee_name'] ?? '');

$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$filterYear   = isset($_GET['year']) ? trim((string)$_GET['year']) : (string)date('Y');
$filterStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$searchTerm   = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEsc = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateTime($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function statusBadgeMeta($status){
    $s = strtolower(trim((string)$status));
    if ($s === 'pending')  return ['Pending',  'pending',     'bi-hourglass-split'];
    if ($s === 'approved') return ['Approved', 'ontrack',     'bi-check2-circle'];
    if ($s === 'rejected') return ['Rejected', 'atrisk',      'bi-x-circle'];
    if ($s === 'cancelled') return ['Cancelled', 'delayed',   'bi-slash-circle'];
    return [($status ?: '—'), 'neutral', 'bi-info-circle'];
}

function getLeaveProjectName(array $leave): string {
    if (!empty($leave['project_name'])) return (string)$leave['project_name'];
    $json = trim((string)($leave['selected_dates_json'] ?? ''));
    if ($json === '') return '';
    $data = json_decode($json, true);
    if (!is_array($data)) return '';
    return (string)($data['project_name'] ?? $data['site_name'] ?? '');
}

function getLeaveProjectId(array $leave): int {
    if (!empty($leave['site_id'])) return (int)$leave['site_id'];
    $json = trim((string)($leave['selected_dates_json'] ?? ''));
    if ($json === '') return 0;
    $data = json_decode($json, true);
    if (!is_array($data)) return 0;
    return (int)($data['site_id'] ?? 0);
}

function logActivity(mysqli $conn, string $activityType, string $module, string $description, ?int $referenceId = null): void {
    if (!mysqli_query($conn, "SHOW TABLES LIKE 'activity_logs'")) return;

    $employee_id   = $_SESSION['employee_id'] ?? null;
    $employee_name = $_SESSION['employee_name'] ?? '';
    $username      = $_SESSION['username'] ?? '';
    $designation   = $_SESSION['designation'] ?? '';
    $department    = $_SESSION['department'] ?? '';
    $ip            = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = mysqli_prepare($conn, "
        INSERT INTO activity_logs
        (employee_id, employee_name, username, designation, department, activity_type, module, description, reference_id, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt,
            "isssssssis",
            $employee_id,
            $employee_name,
            $username,
            $designation,
            $department,
            $activityType,
            $module,
            $description,
            $referenceId,
            $ip
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function leaveTypeName(string $code): string {
    $names = [
        'CL' => 'Casual Leave',
        'SL' => 'Sick Leave',
        'EL' => 'Earned Leave',
        'LOP' => 'Loss of Pay',
        'OD' => 'On Duty',
        'WFH' => 'Work From Home'
    ];
    return $names[$code] ?? $code;
}

function calculateLeaveDaysPayload(string $fromDate, string $toDate, int $siteId = 0): array {
    $start = DateTime::createFromFormat('Y-m-d', $fromDate);
    $end = DateTime::createFromFormat('Y-m-d', $toDate);

    if (!$start || !$end || $start->format('Y-m-d') !== $fromDate || $end->format('Y-m-d') !== $toDate || $start > $end) {
        return [0.0, ''];
    }

    $payload = [];
    $totalDays = 0.0;
    $cursor = clone $start;

    while ($cursor <= $end) {
        $ymd = $cursor->format('Y-m-d');

        // Match apply-leave.php rule: Sundays are excluded.
        if ($cursor->format('w') !== '0') {
            $payload[] = [
                'date' => $ymd,
                'half_day' => null,
                'day_name' => $cursor->format('l')
            ];
            $totalDays += 1.0;
        }

        $cursor->modify('+1 day');
    }

    if ($totalDays <= 0) {
        return [0.0, ''];
    }

    $json = json_encode([
        'site_id' => $siteId,
        'dates' => $payload
    ], JSON_UNESCAPED_UNICODE);

    return [$totalDays, $json ?: ''];
}


$hasSiteIdCol = columnExists($conn, 'leave_requests', 'site_id');
$hasApproverCol = columnExists($conn, 'leave_requests', 'approver_id');

// ---------------- HANDLE EDIT / DELETE ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['action'] ?? ''));

    if ($postAction === 'edit_leave') {
        $leaveId = (int)($_POST['leave_id'] ?? 0);
        $leaveType = strtoupper(trim((string)($_POST['leave_type'] ?? '')));
        $fromDate = trim((string)($_POST['from_date'] ?? ''));
        $toDate = trim((string)($_POST['to_date'] ?? ''));
        $contactDuringLeave = trim((string)($_POST['contact_during_leave'] ?? ''));
        $handoverTo = trim((string)($_POST['handover_to'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));

        $allowedLeaveTypes = ['CL','SL','EL','LOP','OD','WFH'];

        if ($leaveId <= 0) {
            $_SESSION['flash_error'] = "Invalid leave request.";
        } elseif (!in_array($leaveType, $allowedLeaveTypes, true)) {
            $_SESSION['flash_error'] = "Invalid leave type selected.";
        } elseif ($fromDate === '' || $toDate === '') {
            $_SESSION['flash_error'] = "Please select from date and to date.";
        } elseif ($reason === '') {
            $_SESSION['flash_error'] = "Please enter leave reason.";
        } else {
            $check = mysqli_prepare($conn, "
                SELECT id, status, site_id
                FROM leave_requests
                WHERE id = ? AND employee_id = ?
                LIMIT 1
            ");
            if ($check) {
                mysqli_stmt_bind_param($check, "ii", $leaveId, $employeeId);
                mysqli_stmt_execute($check);
                $checkRes = mysqli_stmt_get_result($check);
                $existing = $checkRes ? mysqli_fetch_assoc($checkRes) : null;
                mysqli_stmt_close($check);

                if (!$existing) {
                    $_SESSION['flash_error'] = "Leave request not found.";
                } elseif (strtolower((string)$existing['status']) !== 'pending') {
                    $_SESSION['flash_error'] = "Only pending leave requests can be edited.";
                } else {
                    [$totalDays, $selectedDatesJson] = calculateLeaveDaysPayload($fromDate, $toDate, (int)($existing['site_id'] ?? 0));

                    if ($totalDays <= 0) {
                        $_SESSION['flash_error'] = "Invalid leave dates. Sundays are excluded, so select at least one working day.";
                    } else {
                        $dup = mysqli_prepare($conn, "
                            SELECT id, from_date, to_date, status
                            FROM leave_requests
                            WHERE employee_id = ?
                              AND id <> ?
                              AND status IN ('Pending','Approved')
                              AND NOT (to_date < ? OR from_date > ?)
                            LIMIT 1
                        ");

                        $hasDuplicate = false;
                        if ($dup) {
                            mysqli_stmt_bind_param($dup, "iiss", $employeeId, $leaveId, $fromDate, $toDate);
                            mysqli_stmt_execute($dup);
                            $dupRes = mysqli_stmt_get_result($dup);
                            $dupRow = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
                            mysqli_stmt_close($dup);

                            if ($dupRow) {
                                $hasDuplicate = true;
                                $_SESSION['flash_error'] = "Another {$dupRow['status']} leave request overlaps with selected dates.";
                            }
                        }

                        if (!$hasDuplicate) {
                            $upd = mysqli_prepare($conn, "
                                UPDATE leave_requests
                                SET leave_type = ?,
                                    from_date = ?,
                                    to_date = ?,
                                    total_days = ?,
                                    reason = ?,
                                    contact_during_leave = ?,
                                    handover_to = ?,
                                    selected_dates_json = ?,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = ? AND employee_id = ? AND status = 'Pending'
                            ");

                            if ($upd) {
                                mysqli_stmt_bind_param(
                                    $upd,
                                    "sssdssssii",
                                    $leaveType,
                                    $fromDate,
                                    $toDate,
                                    $totalDays,
                                    $reason,
                                    $contactDuringLeave,
                                    $handoverTo,
                                    $selectedDatesJson,
                                    $leaveId,
                                    $employeeId
                                );

                                if (mysqli_stmt_execute($upd)) {
                                    logActivity(
                                        $conn,
                                        'UPDATE',
                                        'LEAVE',
                                        "Updated leave request #{$leaveId}: {$leaveType} from {$fromDate} to {$toDate} ({$totalDays} day(s))",
                                        $leaveId
                                    );
                                    $_SESSION['flash_success'] = "Leave request updated successfully.";
                                } else {
                                    $_SESSION['flash_error'] = "Failed to update leave request.";
                                }
                                mysqli_stmt_close($upd);
                            } else {
                                $_SESSION['flash_error'] = "Database error while updating leave request.";
                            }
                        }
                    }
                }
            } else {
                $_SESSION['flash_error'] = "Database error while checking leave request.";
            }
        }

        header("Location: my-leave-history.php?" . http_build_query($_GET));
        exit;
    }

    if ($postAction === 'delete_leave') {
        $leaveId = (int)($_POST['leave_id'] ?? 0);

        if ($leaveId <= 0) {
            $_SESSION['flash_error'] = "Invalid leave request.";
        } else {
            $check = mysqli_prepare($conn, "
                SELECT id, leave_type, from_date, to_date, total_days, status
                FROM leave_requests
                WHERE id = ? AND employee_id = ?
                LIMIT 1
            ");

            if ($check) {
                mysqli_stmt_bind_param($check, "ii", $leaveId, $employeeId);
                mysqli_stmt_execute($check);
                $checkRes = mysqli_stmt_get_result($check);
                $leaveRow = $checkRes ? mysqli_fetch_assoc($checkRes) : null;
                mysqli_stmt_close($check);

                if (!$leaveRow) {
                    $_SESSION['flash_error'] = "Leave request not found.";
                } elseif (strtolower((string)$leaveRow['status']) !== 'pending') {
                    $_SESSION['flash_error'] = "Only pending leave requests can be deleted.";
                } else {
                    $del = mysqli_prepare($conn, "DELETE FROM leave_requests WHERE id = ? AND employee_id = ? AND status = 'Pending'");
                    if ($del) {
                        mysqli_stmt_bind_param($del, "ii", $leaveId, $employeeId);
                        if (mysqli_stmt_execute($del)) {
                            logActivity(
                                $conn,
                                'DELETE',
                                'LEAVE',
                                "Deleted leave request #{$leaveId}: {$leaveRow['leave_type']} from {$leaveRow['from_date']} to {$leaveRow['to_date']} ({$leaveRow['total_days']} day(s))",
                                $leaveId
                            );
                            $_SESSION['flash_success'] = "Leave request deleted successfully.";
                        } else {
                            $_SESSION['flash_error'] = "Failed to delete leave request.";
                        }
                        mysqli_stmt_close($del);
                    } else {
                        $_SESSION['flash_error'] = "Database error while deleting leave request.";
                    }
                }
            } else {
                $_SESSION['flash_error'] = "Database error while checking leave request.";
            }
        }

        header("Location: my-leave-history.php?" . http_build_query($_GET));
        exit;
    }
}


$whereConditions = ["lr.employee_id = ?"];
$params = [$employeeId];
$types = "i";

if ($filterYear !== '' && strtolower($filterYear) !== 'all' && (int)$filterYear > 0) {
    $whereConditions[] = "YEAR(lr.applied_at) = ?";
    $params[] = (int)$filterYear;
    $types .= "i";
}

if ($filterStatus !== '' && strtolower($filterStatus) !== 'all') {
    $whereConditions[] = "LOWER(lr.status) = ?";
    $params[] = strtolower($filterStatus);
    $types .= "s";
}

if ($searchTerm !== '') {
    $whereConditions[] = "(lr.leave_type LIKE ? OR lr.reason LIKE ? OR CAST(lr.id AS CHAR) LIKE ? OR s.project_name LIKE ?)";
    $searchPattern = "%{$searchTerm}%";
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $types .= "ssss";
}

$whereClause = implode(" AND ", $whereConditions);

$years = [];
$yearStmt = mysqli_prepare($conn, "SELECT DISTINCT YEAR(applied_at) AS yr FROM leave_requests WHERE employee_id = ? AND applied_at IS NOT NULL ORDER BY yr DESC");
if ($yearStmt) {
    mysqli_stmt_bind_param($yearStmt, "i", $employeeId);
    mysqli_stmt_execute($yearStmt);
    $yearRes = mysqli_stmt_get_result($yearStmt);
    while ($row = mysqli_fetch_assoc($yearRes)) {
        if (!empty($row['yr'])) $years[] = (int)$row['yr'];
    }
    mysqli_stmt_close($yearStmt);
}
if (empty($years)) $years[] = (int)date('Y');

$siteIdSelect = $hasSiteIdCol ? "lr.site_id" : "NULL AS site_id";
$approverSelect = $hasApproverCol ? "lr.approver_id" : "NULL AS approver_id";
$siteJoin = $hasSiteIdCol
    ? "LEFT JOIN sites s ON s.id = lr.site_id"
    : "LEFT JOIN sites s ON s.id = CASE
        WHEN JSON_VALID(lr.selected_dates_json)
        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(lr.selected_dates_json, '$.site_id')) AS UNSIGNED)
        ELSE 0
      END";
$approverJoin = $hasApproverCol
    ? "LEFT JOIN employees app ON app.id = lr.approver_id"
    : "LEFT JOIN employees app ON 1=0";

$totalRecords = 0;
$countSql = "SELECT COUNT(*) AS total FROM leave_requests lr $siteJoin WHERE $whereClause";
$countStmt = mysqli_prepare($conn, $countSql);
if ($countStmt) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
    mysqli_stmt_execute($countStmt);
    $countRes = mysqli_stmt_get_result($countStmt);
    $countRow = $countRes ? mysqli_fetch_assoc($countRes) : null;
    $totalRecords = (int)($countRow['total'] ?? 0);
    mysqli_stmt_close($countStmt);
}

$leaveHistory = [];
$sql = "
    SELECT
        lr.id,
        lr.leave_type,
        lr.from_date,
        lr.to_date,
        lr.total_days,
        lr.reason,
        lr.contact_during_leave,
        lr.handover_to,
        lr.status,
        lr.applied_at,
        lr.created_at,
        lr.updated_at,
        lr.approved_at,
        lr.rejected_at,
        lr.approver_remarks,
        lr.rejection_reason,
        lr.selected_dates_json,
        $siteIdSelect,
        $approverSelect,
        s.project_name,
        s.project_location,
        app.full_name AS approver_name
    FROM leave_requests lr
    $siteJoin
    $approverJoin
    WHERE $whereClause
    ORDER BY lr.applied_at DESC, lr.id DESC
    LIMIT ? OFFSET ?
";
$queryParams = $params;
$queryTypes = $types . "ii";
$queryParams[] = $perPage;
$queryParams[] = $offset;

$st = mysqli_prepare($conn, $sql);
if ($st) {
    mysqli_stmt_bind_param($st, $queryTypes, ...$queryParams);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $leaveHistory = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
}

$leaveStats = [
    'total_requests' => 0,
    'total_days' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];
$statsStmt = mysqli_prepare($conn, "
    SELECT
        COUNT(*) AS total_requests,
        SUM(total_days) AS total_days,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected
    FROM leave_requests
    WHERE employee_id = ?
");
if ($statsStmt) {
    mysqli_stmt_bind_param($statsStmt, "i", $employeeId);
    mysqli_stmt_execute($statsStmt);
    $statsRes = mysqli_stmt_get_result($statsStmt);
    $stats = $statsRes ? mysqli_fetch_assoc($statsRes) : [];
    $leaveStats = [
        'total_requests' => (int)($stats['total_requests'] ?? 0),
        'total_days' => (float)($stats['total_days'] ?? 0),
        'pending' => (int)($stats['pending'] ?? 0),
        'approved' => (int)($stats['approved'] ?? 0),
        'rejected' => (int)($stats['rejected'] ?? 0),
    ];
    mysqli_stmt_close($statsStmt);
}
$leaveStats['total_days'] = rtrim(rtrim(number_format((float)$leaveStats['total_days'], 1, '.', ''), '0'), '.');
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Leave History - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
    :root {
        --page-bg: #f5f7fb;
        --card-bg: #ffffff;
        --border: #e5e7eb;
        --text: #111827;
        --muted: #6b7280;
        --soft: #f8fafc;
        --shadow: 0 10px 26px rgba(15, 23, 42, .055);
        --radius: 15px;
    }

    body {
        background: var(--page-bg);
    }

    .content-scroll {
        flex: 1 1 auto;
        overflow: auto;
        padding: 16px;
    }

    .projects-wrapper {
        width: 100%;
    }

    .page-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .page-heading h1 {
        font-size: 19px;
        font-weight: 900;
        color: var(--text);
        margin: 0;
    }

    .page-heading p {
        margin: 3px 0 0;
        color: var(--muted);
        font-size: 12px;
        font-weight: 600;
    }

    .primary-btn {
        border: 0;
        background: #111827;
        color: #fff;
        height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
    }

    .primary-btn:hover {
        background: #020617;
        color: #fff;
    }

    .secondary-btn {
        border: 1px solid var(--border) !important;
        background: #fff !important;
        color: #334155 !important;
        height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
    }

    .secondary-btn:hover {
        border-color: #cbd5e1 !important;
        background: #f8fafc !important;
        color: #111827 !important;
    }

    .export-btn {
        background: #10b981;
    }

    .export-btn:hover {
        background: #059669;
    }

    .stat-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 12px 13px;
        min-height: 78px;
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .stat-ic {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        color: #fff;
        font-size: 17px;
    }

    .blue {
        background: #2f80ed;
    }

    .orange {
        background: #f2994a;
    }

    .green {
        background: #27ae60;
    }

    .red {
        background: #eb5757;
    }

    .gray {
        background: #64748b;
    }

    .stat-label {
        color: var(--muted);
        font-weight: 800;
        font-size: 10.5px;
        text-transform: uppercase;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 950;
        color: #111827;
    }

    .panel {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 13px;
    }

    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        gap: 10px;
    }

    .panel-title {
        font-weight: 900;
        font-size: 14px;
        margin: 0;
        color: #111827;
    }

    .panel-subtitle {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
    }

    .filter-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .search-box {
        position: relative;
        flex: 1 1 260px;
        max-width: 430px;
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 13px;
    }

    .search-box input {
        width: 100%;
        height: 36px;
        border: 1px solid var(--border);
        border-radius: 11px;
        background: #fff;
        padding: 0 12px 0 34px;
        font-size: 12px;
        font-weight: 700;
        color: var(--text);
        outline: none;
    }

    .search-box input:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .filter-select {
        height: 36px;
        border: 1px solid var(--border);
        border-radius: 11px;
        background: #fff;
        padding: 0 42px 0 12px;
        font-size: 12px;
        font-weight: 800;
        min-width: 145px;
    }

    .filter-submit {
        height: 36px;
        border: 0;
        background: #111827;
        color: #fff;
        border-radius: 11px;
        padding: 0 12px;
        font-size: 12px;
        font-weight: 900;
    }

    .compact-table-wrap {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 13px;
        overflow: hidden;
        background: #fff;
    }

    .compact-table {
        width: 100%;
        margin: 0;
        table-layout: auto;
    }

    .compact-table thead th {
        background: var(--soft);
        color: #64748b;
        font-size: 10px;
        text-transform: uppercase;
        font-weight: 900;
        border-bottom: 1px solid var(--border) !important;
        padding: 8px 9px;
    }

    .compact-table tbody td {
        padding: 8px 9px;
        vertical-align: middle;
        border-color: #eef2f7;
        color: #334155;
        font-weight: 700;
        font-size: 11.5px;
    }

    .compact-table tbody tr:hover {
        background: #fbfdff;
    }

    .table-title-cell {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .table-icon {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        background: #eff6ff;
        color: #2563eb;
        font-size: 13px;
        flex: 0 0 auto;
    }

    .table-primary-text {
        color: #111827;
        font-size: 11.5px;
        font-weight: 900;
    }

    .table-secondary-text {
        color: #64748b;
        font-size: 10px;
        font-weight: 700;
        margin-top: 1px;
    }

    .badge-pill {
        border-radius: 999px;
        padding: 5px 8px;
        font-weight: 900;
        font-size: 10px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .mini-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    .ontrack {
        color: #15803d;
        background: #dcfce7;
        border-color: #bbf7d0;
    }

    .progressing {
        color: #2563eb;
        background: #dbeafe;
        border-color: #bfdbfe;
    }

    .pending {
        color: #6d28d9;
        background: #ede9fe;
        border-color: #ddd6fe;
    }

    .atrisk {
        color: #b91c1c;
        background: #fee2e2;
        border-color: #fecaca;
    }

    .delayed {
        color: #a16207;
        background: #fef3c7;
        border-color: #fde68a;
    }

    .neutral {
        color: #475569;
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .action-group {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .action-btn {
        width: 27px;
        height: 27px;
        border-radius: 9px;
        border: 1px solid var(--border);
        background: #fff;
        display: grid;
        place-items: center;
        text-decoration: none;
    }

    .view-btn {
        color: #475569;
        background: #f8fafc;
    }

    .file-btn {
        color: #10b981;
        background: #ecfdf5;
    }

    .edit-btn {
        color: #2563eb;
        background: #eff6ff;
    }

    .delete-btn {
        color: #dc2626;
        background: #fee2e2;
    }

    .modal-content {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .20);
    }

    .modal-header,
    .modal-footer {
        border-color: #eef2f7;
    }

    .modal-title {
        font-size: 15px;
        font-weight: 950;
        color: #111827;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .detail-item {
        border: 1px solid #eef2f7;
        background: #f8fafc;
        border-radius: 12px;
        padding: 10px;
    }

    .detail-item.full {
        grid-column: 1 / -1;
    }

    .detail-label {
        font-size: 10px;
        font-weight: 900;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 4px;
    }

    .detail-value {
        font-size: 12px;
        font-weight: 850;
        color: #111827;
        line-height: 1.35;
        word-break: break-word;
    }

    .pagination-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding-top: 12px;
        flex-wrap: wrap;
    }

    .pagination-info {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
    }

    .page-mini {
        height: 30px;
        min-width: 30px;
        padding: 0 10px;
        border: 1px solid var(--border);
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        color: #334155;
        font-size: 11px;
        font-weight: 900;
        background: #fff;
    }

    .page-mini.active {
        background: #111827;
        color: #fff;
        border-color: #111827;
    }

    .page-mini.disabled {
        opacity: .45;
        pointer-events: none;
    }

    .empty-state {
        text-align: center;
        color: #64748b;
        padding: 30px 12px;
        font-size: 12px;
        font-weight: 900;
    }

    .empty-state i {
        font-size: 34px;
        display: block;
        margin-bottom: 8px;
        opacity: .45;
    }

    .reason-cell {
        max-width: 260px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    @media(max-width:991.98px) {
        .main {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        .sidebar {
            position: fixed !important;
            transform: translateX(-100%);
            z-index: 1040 !important;
        }

        .sidebar.open,
        .sidebar.active,
        .sidebar.show {
            transform: translateX(0) !important;
        }
    }

    @media(max-width:1199px) {
        .compact-table thead {
            display: none;
        }

        .compact-table,
        .compact-table tbody,
        .compact-table tr,
        .compact-table td {
            display: block;
            width: 100%;
        }

        .compact-table tbody tr {
            border-bottom: 1px solid var(--border);
            padding: 10px;
        }

        .compact-table tbody td {
            border: 0;
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        .compact-table tbody td::before {
            content: attr(data-label);
            font-size: 10px;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            flex: 0 0 95px;
        }

        .compact-table tbody td:first-child {
            display: block;
        }

        .compact-table tbody td:first-child::before {
            display: none;
        }

        .reason-cell {
            max-width: none;
            white-space: normal;
            text-align: right;
        }

        .action-group {
            justify-content: flex-start;
        }
    }

    @media(max-width:768px) {
        .detail-grid {
            grid-template-columns: 1fr;
        }

        .content-scroll {
            padding: 12px 10px 12px !important;
        }

        .page-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .filter-bar {
            align-items: stretch;
        }

        .filter-select,
        .filter-submit,
        .primary-btn {
            width: 100%;
            justify-content: center;
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
                <div class="container-fluid projects-wrapper px-0">

                    <div class="page-heading">
                        <div>
                            <h1>My Leave History</h1>
                            <p>View, search and export your leave applications</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="apply-leave.php" class="primary-btn"><i class="bi bi-plus-circle"></i> Apply
                                Leave</a>
                            <a href="export-leave-history.php?<?php echo e(http_build_query($_GET)); ?>"
                                class="primary-btn export-btn"><i class="bi bi-download"></i> Export</a>
                        </div>
                    </div>

                    <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-inboxes"></i></div>
                                <div>
                                    <div class="stat-label">Total Requests</div>
                                    <div class="stat-value"><?php echo e($leaveStats['total_requests']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic gray"><i class="bi bi-calendar2-week"></i></div>
                                <div>
                                    <div class="stat-label">Total Leave Days</div>
                                    <div class="stat-value"><?php echo e($leaveStats['total_days']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="stat-label">Pending</div>
                                    <div class="stat-value"><?php echo e($leaveStats['pending']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <div class="stat-label">Approved</div>
                                    <div class="stat-value"><?php echo e($leaveStats['approved']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                                <div>
                                    <div class="stat-label">Rejected</div>
                                    <div class="stat-value"><?php echo e($leaveStats['rejected']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">Leave Records</h3>
                                <div class="panel-subtitle">Showing <?php echo count($leaveHistory); ?> of
                                    <?php echo (int)$totalRecords; ?> records</div>
                            </div>
                        </div>

                        <form method="GET" class="filter-bar">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" name="search" placeholder="Search ID, type, project or reason..."
                                    value="<?php echo e($searchTerm); ?>">
                            </div>
                            <select class="filter-select" name="year">
                                <option value="all">All Years</option>
                                <?php foreach($years as $year): ?>
                                <option value="<?php echo (int)$year; ?>"
                                    <?php echo ((string)$filterYear === (string)$year ? 'selected' : ''); ?>>
                                    <?php echo (int)$year; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="filter-select" name="status">
                                <option value="all">All Status</option>
                                <option value="pending"
                                    <?php echo (strtolower($filterStatus) === 'pending' ? 'selected' : ''); ?>>Pending
                                </option>
                                <option value="approved"
                                    <?php echo (strtolower($filterStatus) === 'approved' ? 'selected' : ''); ?>>Approved
                                </option>
                                <option value="rejected"
                                    <?php echo (strtolower($filterStatus) === 'rejected' ? 'selected' : ''); ?>>Rejected
                                </option>
                                <option value="cancelled"
                                    <?php echo (strtolower($filterStatus) === 'cancelled' ? 'selected' : ''); ?>>
                                    Cancelled</option>
                            </select>
                            <button type="submit" class="filter-submit"><i class="bi bi-funnel"></i> Filter</button>
                            <a href="my-leave-history.php" class="secondary-btn"><i class="bi bi-arrow-repeat"></i>
                                Reset</a>
                        </form>

                        <div class="compact-table-wrap">
                            <table class="table compact-table align-middle">
                                <thead>
                                    <tr>
                                        <th>Leave</th>
                                        <th>Project</th>
                                        <th>Dates</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Applied</th>
                                        <th>Reason</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($leaveHistory)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No leave history found.
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach($leaveHistory as $leave): ?>
                                    <?php
            [$stLabel, $stClass, $stIcon] = statusBadgeMeta($leave['status'] ?? '');
            $projectName = getLeaveProjectName($leave);
            $projectId = getLeaveProjectId($leave);
          ?>
                                    <tr>
                                        <td data-label="Leave">
                                            <div class="table-title-cell">
                                                <div class="table-icon"><i class="bi bi-calendar-check"></i></div>
                                                <div>
                                                    <div class="table-primary-text">
                                                        <?php echo e($leave['leave_type'] ?? 'Leave'); ?> <span
                                                            class="text-muted">#<?php echo (int)$leave['id']; ?></span>
                                                    </div>
                                                    <div class="table-secondary-text">
                                                        <?php echo e((string)($leave['total_days'] ?? '0')); ?> day(s)
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Project">
                                            <div class="table-primary-text">
                                                <?php echo $projectName !== '' ? e($projectName) : '—'; ?></div>
                                            <div class="table-secondary-text">
                                                <?php echo e($leave['project_location'] ?? ''); ?></div>
                                        </td>
                                        <td data-label="Dates">
                                            <div class="table-primary-text">
                                                <?php echo e(safeDate($leave['from_date'] ?? '')); ?></div>
                                            <div class="table-secondary-text">to
                                                <?php echo e(safeDate($leave['to_date'] ?? '')); ?></div>
                                        </td>
                                        <td data-label="Total">
                                            <div class="table-primary-text">
                                                <?php echo e($leave['total_days'] ?? '—'); ?></div>
                                        </td>
                                        <td data-label="Status"><span class="badge-pill <?php echo e($stClass); ?>"><i
                                                    class="bi <?php echo e($stIcon); ?>"></i>
                                                <?php echo e($stLabel); ?></span></td>
                                        <td data-label="Applied">
                                            <div class="table-primary-text">
                                                <?php echo e(safeDateTime($leave['applied_at'] ?? '')); ?></div>
                                            <?php if(!empty($leave['approver_name'])): ?><div
                                                class="table-secondary-text">Approver:
                                                <?php echo e($leave['approver_name']); ?></div><?php endif; ?>
                                        </td>
                                        <td data-label="Reason">
                                            <div class="table-secondary-text reason-cell">
                                                <?php echo e($leave['reason'] ?? '—'); ?></div>
                                        </td>
                                        <td data-label="Actions">
                                            <?php $canModifyLeave = strtolower((string)($leave['status'] ?? '')) === 'pending'; ?>
                                            <div class="action-group">
                                                <?php if($projectId > 0): ?>
                                                <a href="view-site.php?id=<?php echo (int)$projectId; ?>"
                                                    class="action-btn view-btn" title="View Project"><i
                                                        class="bi bi-building"></i></a>
                                                <?php endif; ?>

                                                <button type="button" class="action-btn file-btn js-view-leave"
                                                    title="View Leave" data-bs-toggle="modal"
                                                    data-bs-target="#viewLeaveModal"
                                                    data-id="<?php echo (int)$leave['id']; ?>"
                                                    data-leave-type="<?php echo e($leave['leave_type'] ?? ''); ?>"
                                                    data-leave-name="<?php echo e(leaveTypeName((string)($leave['leave_type'] ?? ''))); ?>"
                                                    data-project="<?php echo e($projectName !== '' ? $projectName : '—'); ?>"
                                                    data-project-location="<?php echo e($leave['project_location'] ?? '—'); ?>"
                                                    data-from="<?php echo e(safeDate($leave['from_date'] ?? '')); ?>"
                                                    data-to="<?php echo e(safeDate($leave['to_date'] ?? '')); ?>"
                                                    data-total="<?php echo e($leave['total_days'] ?? '—'); ?>"
                                                    data-status="<?php echo e($stLabel); ?>"
                                                    data-applied="<?php echo e(safeDateTime($leave['applied_at'] ?? '')); ?>"
                                                    data-approver="<?php echo e($leave['approver_name'] ?? '—'); ?>"
                                                    data-contact="<?php echo e($leave['contact_during_leave'] ?? '—'); ?>"
                                                    data-handover="<?php echo e($leave['handover_to'] ?? '—'); ?>"
                                                    data-reason="<?php echo e($leave['reason'] ?? '—'); ?>"
                                                    data-approver-remarks="<?php echo e($leave['approver_remarks'] ?? '—'); ?>"
                                                    data-rejection-reason="<?php echo e($leave['rejection_reason'] ?? '—'); ?>">
                                                    <i class="bi bi-eye"></i>
                                                </button>

                                                <button type="button"
                                                    class="action-btn edit-btn <?php echo $canModifyLeave ? '' : 'disabled'; ?>"
                                                    title="<?php echo $canModifyLeave ? 'Edit Leave' : 'Only pending leave can be edited'; ?>"
                                                    <?php echo $canModifyLeave ? 'data-bs-toggle="modal" data-bs-target="#editLeaveModal"' : 'disabled'; ?>
                                                    data-id="<?php echo (int)$leave['id']; ?>"
                                                    data-leave-type="<?php echo e($leave['leave_type'] ?? ''); ?>"
                                                    data-from-raw="<?php echo e($leave['from_date'] ?? ''); ?>"
                                                    data-to-raw="<?php echo e($leave['to_date'] ?? ''); ?>"
                                                    data-contact="<?php echo e($leave['contact_during_leave'] ?? ''); ?>"
                                                    data-handover="<?php echo e($leave['handover_to'] ?? ''); ?>"
                                                    data-reason="<?php echo e($leave['reason'] ?? ''); ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <button type="button"
                                                    class="action-btn delete-btn <?php echo $canModifyLeave ? '' : 'disabled'; ?>"
                                                    title="<?php echo $canModifyLeave ? 'Delete Leave' : 'Only pending leave can be deleted'; ?>"
                                                    <?php echo $canModifyLeave ? 'data-bs-toggle="modal" data-bs-target="#deleteLeaveModal"' : 'disabled'; ?>
                                                    data-id="<?php echo (int)$leave['id']; ?>"
                                                    data-title="<?php echo e(($leave['leave_type'] ?? 'Leave') . ' #' . (int)$leave['id']); ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination-wrap">
                            <div class="pagination-info">Page <?php echo (int)$page; ?> of
                                <?php echo (int)$totalPages; ?></div>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php
        $baseQuery = $_GET;
        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);
        $baseQuery['page'] = $prevPage;
      ?>
                                <a class="page-mini <?php echo $page <= 1 ? 'disabled' : ''; ?>"
                                    href="?<?php echo e(http_build_query($baseQuery)); ?>"><i
                                        class="bi bi-chevron-left"></i></a>
                                <?php for($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
                                <?php $baseQuery['page'] = $i; ?>
                                <a class="page-mini <?php echo $i === $page ? 'active' : ''; ?>"
                                    href="?<?php echo e(http_build_query($baseQuery)); ?>"><?php echo (int)$i; ?></a>
                                <?php endfor; ?>
                                <?php $baseQuery['page'] = $nextPage; ?>
                                <a class="page-mini <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"
                                    href="?<?php echo e(http_build_query($baseQuery)); ?>"><i
                                        class="bi bi-chevron-right"></i></a>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>


    <!-- View Leave Modal -->
    <div class="modal fade" id="viewLeaveModal" tabindex="-1" aria-labelledby="viewLeaveModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewLeaveModalLabel">Leave Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Leave ID</div>
                            <div class="detail-value" id="viewLeaveId">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Leave Type</div>
                            <div class="detail-value" id="viewLeaveType">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Project</div>
                            <div class="detail-value" id="viewProject">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Project Location</div>
                            <div class="detail-value" id="viewProjectLocation">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">From Date</div>
                            <div class="detail-value" id="viewFrom">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">To Date</div>
                            <div class="detail-value" id="viewTo">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Total Days</div>
                            <div class="detail-value" id="viewTotal">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status</div>
                            <div class="detail-value" id="viewStatus">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Applied On</div>
                            <div class="detail-value" id="viewApplied">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Approver</div>
                            <div class="detail-value" id="viewApprover">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Contact During Leave</div>
                            <div class="detail-value" id="viewContact">—</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Handover To</div>
                            <div class="detail-value" id="viewHandover">—</div>
                        </div>
                        <div class="detail-item full">
                            <div class="detail-label">Reason</div>
                            <div class="detail-value" id="viewReason">—</div>
                        </div>
                        <div class="detail-item full">
                            <div class="detail-label">Approver Remarks</div>
                            <div class="detail-value" id="viewApproverRemarks">—</div>
                        </div>
                        <div class="detail-item full">
                            <div class="detail-label">Rejection Reason</div>
                            <div class="detail-value" id="viewRejectionReason">—</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Leave Modal -->
    <div class="modal fade" id="editLeaveModal" tabindex="-1" aria-labelledby="editLeaveModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_leave">
                    <input type="hidden" name="leave_id" id="editLeaveId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editLeaveModalLabel">Edit Leave Request</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            Only pending leave requests can be edited. Sundays are excluded while recalculating total
                            days.
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Leave Type</label>
                                <select class="filter-select w-100" name="leave_type" id="editLeaveType" required>
                                    <option value="CL">Casual Leave (CL)</option>
                                    <option value="SL">Sick Leave (SL)</option>
                                    <option value="EL">Earned Leave (EL)</option>
                                    <option value="LOP">Loss of Pay (LOP)</option>
                                    <option value="OD">On Duty (OD)</option>
                                    <option value="WFH">Work From Home (WFH)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">From Date</label>
                                <input type="date" class="filter-select w-100" name="from_date" id="editFromDate"
                                    required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">To Date</label>
                                <input type="date" class="filter-select w-100" name="to_date" id="editToDate" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact During Leave</label>
                                <input type="text" class="filter-select w-100" name="contact_during_leave"
                                    id="editContact" placeholder="Mobile / email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Handover To</label>
                                <input type="text" class="filter-select w-100" name="handover_to" id="editHandover"
                                    placeholder="Employee name">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reason</label>
                                <textarea class="form-control" name="reason" id="editReason" rows="4"
                                    required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="primary-btn">
                            <i class="bi bi-save"></i> Update Leave
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Leave Modal -->
    <div class="modal fade" id="deleteLeaveModal" tabindex="-1" aria-labelledby="deleteLeaveModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="delete_leave">
                    <input type="hidden" name="leave_id" id="deleteLeaveId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteLeaveModalLabel">Delete Leave Request</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Are you sure you want to delete this leave request?</p>
                        <div class="detail-item">
                            <div class="detail-label">Selected Request</div>
                            <div class="detail-value" id="deleteLeaveTitle">—</div>
                        </div>
                        <div class="text-danger fw-bold small mt-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            This action cannot be undone. Only pending leave requests can be deleted.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="primary-btn" style="background:#dc2626;">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        function setText(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = value && value.trim ? (value.trim() || '—') : (value || '—');
        }

        document.querySelectorAll('.js-view-leave').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setText('viewLeaveId', '#' + (this.dataset.id || ''));
                setText('viewLeaveType', (this.dataset.leaveType || '') + ' - ' + (this.dataset
                    .leaveName || ''));
                setText('viewProject', this.dataset.project || '—');
                setText('viewProjectLocation', this.dataset.projectLocation || '—');
                setText('viewFrom', this.dataset.from || '—');
                setText('viewTo', this.dataset.to || '—');
                setText('viewTotal', (this.dataset.total || '—') + ' day(s)');
                setText('viewStatus', this.dataset.status || '—');
                setText('viewApplied', this.dataset.applied || '—');
                setText('viewApprover', this.dataset.approver || '—');
                setText('viewContact', this.dataset.contact || '—');
                setText('viewHandover', this.dataset.handover || '—');
                setText('viewReason', this.dataset.reason || '—');
                setText('viewApproverRemarks', this.dataset.approverRemarks || '—');
                setText('viewRejectionReason', this.dataset.rejectionReason || '—');
            });
        });

        document.querySelectorAll('[data-bs-target="#editLeaveModal"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = document.getElementById('editLeaveId');
                const type = document.getElementById('editLeaveType');
                const from = document.getElementById('editFromDate');
                const to = document.getElementById('editToDate');
                const contact = document.getElementById('editContact');
                const handover = document.getElementById('editHandover');
                const reason = document.getElementById('editReason');

                if (id) id.value = this.dataset.id || '';
                if (type) type.value = this.dataset.leaveType || 'CL';
                if (from) from.value = this.dataset.fromRaw || '';
                if (to) to.value = this.dataset.toRaw || '';
                if (contact) contact.value = this.dataset.contact || '';
                if (handover) handover.value = this.dataset.handover || '';
                if (reason) reason.value = this.dataset.reason || '';
            });
        });

        document.querySelectorAll('[data-bs-target="#deleteLeaveModal"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = document.getElementById('deleteLeaveId');
                if (id) id.value = this.dataset.id || '';
                setText('deleteLeaveTitle', this.dataset.title || '—');
            });
        });
    });
    </script>
</body>

</html>
<?php
try {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) { }
?>