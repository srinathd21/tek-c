<?php
// my-quotation-requests.php — show ALL quotation requests created by logged-in user
// Edit and Delete functionality for draft and pending requests

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$requests = [];

// ---------- Auth ----------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$user_name = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? '';


// ============================================================
// Edit permission: User can edit if status is Draft OR Pending Assignment
// Because "Pending Assignment" means the request hasn't been assigned to anyone yet
// ============================================================
$editable_statuses = ['Draft', 'Pending Assignment'];

// ============================================================
// Direct delete permission:
// User can delete ONLY their own Draft or Pending Assignment request.
// No delete for Assigned / With QS / QS Finalized / Approved / Rejected / Cancelled.
// ============================================================
$deletable_statuses = ['Draft', 'Pending Assignment'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_request') {
    $delete_id = (int)($_POST['request_id'] ?? 0);

    if ($delete_id <= 0) {
        $error = 'Invalid quotation request selected.';
    } else {
        mysqli_begin_transaction($conn);

        try {
            $check_sql = "
                SELECT id, request_no, title, status, requested_by
                FROM quotation_requests
                WHERE id = ?
                  AND requested_by = ?
                LIMIT 1
            ";
            $check_stmt = mysqli_prepare($conn, $check_sql);

            if (!$check_stmt) {
                throw new Exception('Unable to validate request.');
            }

            mysqli_stmt_bind_param($check_stmt, "ii", $delete_id, $empId);
            mysqli_stmt_execute($check_stmt);
            $check_res = mysqli_stmt_get_result($check_stmt);
            $delete_req = $check_res ? mysqli_fetch_assoc($check_res) : null;
            mysqli_stmt_close($check_stmt);

            if (!$delete_req) {
                throw new Exception('Request not found or you do not have permission to delete it.');
            }

            if (!in_array($delete_req['status'], $deletable_statuses, true)) {
                throw new Exception('Only Draft and Pending Assignment requests can be deleted.');
            }

            // Delete child rows if they exist. Most Pending Assignment requests will not have these,
            // but this keeps the delete safe if drafts have uploaded request documents.
            if (tableExists($conn, 'quotation_request_files')) {
                $child_stmt = mysqli_prepare($conn, "DELETE FROM quotation_request_files WHERE quotation_request_id = ?");
                if ($child_stmt) {
                    mysqli_stmt_bind_param($child_stmt, "i", $delete_id);
                    mysqli_stmt_execute($child_stmt);
                    mysqli_stmt_close($child_stmt);
                }
            }

            if (tableExists($conn, 'quotation_request_documents')) {
                $child_stmt = mysqli_prepare($conn, "DELETE FROM quotation_request_documents WHERE quotation_request_id = ?");
                if ($child_stmt) {
                    mysqli_stmt_bind_param($child_stmt, "i", $delete_id);
                    mysqli_stmt_execute($child_stmt);
                    mysqli_stmt_close($child_stmt);
                }
            }

            $delete_stmt = mysqli_prepare($conn, "
                DELETE FROM quotation_requests
                WHERE id = ?
                  AND requested_by = ?
                  AND status IN ('Draft', 'Pending Assignment')
            ");

            if (!$delete_stmt) {
                throw new Exception('Unable to delete request.');
            }

            mysqli_stmt_bind_param($delete_stmt, "ii", $delete_id, $empId);
            mysqli_stmt_execute($delete_stmt);
            $affected = mysqli_stmt_affected_rows($delete_stmt);
            mysqli_stmt_close($delete_stmt);

            if ($affected <= 0) {
                throw new Exception('Request was not deleted. It may have already moved to the next workflow stage.');
            }

            logQuotationActivity(
                $conn,
                $empId,
                'DELETE',
                'Deleted quotation request: ' . ($delete_req['request_no'] ?? ''),
                $delete_id,
                $delete_req
            );

            mysqli_commit($conn);

            header('Location: my-quotation-requests.php?status=success&message=' . urlencode('Quotation request deleted successfully.'));
            exit;
        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = $ex->getMessage();
        }
    }
}


// Allow all authenticated users to view their requests

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function logQuotationActivity($conn, int $employeeId, string $activityType, string $description, $referenceId = null, array $oldData = []): bool {
    if (!$conn || !tableExists($conn, 'activity_logs')) return false;

    $oldDataJson = $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null;

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
        'module'        => ['s', 'quotation_requests'],
        'description'   => ['s', $description],
        'reference_id'  => ['i', $referenceId],
        'old_data'      => ['s', $oldDataJson],
        'ip_address'    => ['s', $ipAddress],
    ];

    $cols = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (columnExists($conn, 'activity_logs', $column)) {
            $cols[] = "`$column`";
            $types .= $pair[0];
            $values[] = $pair[1];
        }
    }

    if (!$cols) return false;

    $sql = "INSERT INTO activity_logs (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}


function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function getPriorityBadge($priority) {
    $p = trim((string)$priority);
    $map = [
        'Low'    => ['neutral', 'bi-arrow-down'],
        'Medium' => ['progressing', 'bi-dash'],
        'High'   => ['delayed', 'bi-arrow-up'],
        'Urgent' => ['atrisk', 'bi-exclamation-triangle']
    ];
    $m = $map[$p] ?? ['neutral', 'bi-question'];
    return '<span class="badge-pill ' . $m[0] . '"><i class="bi ' . $m[1] . '"></i>' . e($p !== '' ? $p : '—') . '</span>';
}

function getStatusBadge($status) {
    $s = trim((string)$status);
    $map = [
        'Draft'               => ['neutral', 'bi-pencil'],
        'Pending Assignment'  => ['pending', 'bi-clock'],
        'Assigned'            => ['progressing', 'bi-person-check'],
        'Quotations Received' => ['progressing', 'bi-file-text'],
        'With QS'             => ['pending', 'bi-arrow-right'],
        'QS Finalized'        => ['ontrack', 'bi-check-circle'],
        'Approved'            => ['ontrack', 'bi-check-circle-fill'],
        'Rejected'            => ['atrisk', 'bi-x-circle'],
        'Cancelled'           => ['delayed', 'bi-slash-circle']
    ];
    $m = $map[$s] ?? ['neutral', 'bi-info-circle'];
    return '<span class="badge-pill ' . $m[0] . '"><i class="bi ' . $m[1] . '"></i>' . e($s !== '' ? $s : '—') . '</span>';
}

// ---------- Fetch all quotation requests for this user ----------
$sql = "
  SELECT 
    qr.*,
    s.project_name,
    s.project_code,
    s.project_location,
    c.client_name,
    c.company_name,
    e.full_name as requested_by_employee_name,
    e.id as requested_by_id
  FROM quotation_requests qr
  JOIN sites s ON qr.site_id = s.id
  LEFT JOIN clients c ON s.client_id = c.id
  LEFT JOIN employees e ON qr.requested_by = e.id
  WHERE qr.requested_by = ?
  ORDER BY 
    CASE 
      WHEN qr.status = 'Draft' THEN 1
      WHEN qr.status = 'Pending Assignment' THEN 2
      WHEN qr.status = 'Assigned' THEN 3
      WHEN qr.status = 'Quotations Received' THEN 4
      WHEN qr.status = 'With QS' THEN 5
      WHEN qr.status = 'QS Finalized' THEN 6
      WHEN qr.status = 'Approved' THEN 7
      WHEN qr.status = 'Rejected' THEN 8
      WHEN qr.status = 'Cancelled' THEN 9
      ELSE 10
    END,
    qr.created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
  $error = "Database error: " . mysqli_error($conn);
} else {
  mysqli_stmt_bind_param($stmt, "i", $empId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $requests = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($stmt);
}


// ---------- Stats ----------
$total_requests = count($requests);
$draft_count = 0;
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;

foreach ($requests as $req) {
    if ($req['status'] === 'Draft') $draft_count++;
    elseif ($req['status'] === 'Approved') $approved_count++;
    elseif ($req['status'] === 'Rejected') $rejected_count++;
    elseif (in_array($req['status'], ['Pending Assignment', 'Assigned', 'Quotations Received', 'With QS', 'QS Finalized'])) $pending_count++;
}

// Get status message if any
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';

// Get user's role display name
function getRoleDisplay($designation) {
    $roles = [
        'project engineer grade 1' => 'Project Engineer',
        'project engineer grade 2' => 'Project Engineer',
        'sr. engineer' => 'Senior Engineer',
        'sr engineer' => 'Senior Engineer',
        'senior engineer' => 'Senior Engineer',
        'team lead' => 'Team Lead',
        'teamleader' => 'Team Lead',
        'manager' => 'Manager',
        'director' => 'Director',
        'hr' => 'HR',
        'accountant' => 'Accountant'
    ];
    return $roles[$designation] ?? ucfirst($designation);
}

// Define editable statuses globally for JavaScript
$editable_statuses_js = json_encode($editable_statuses);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Vendor Finalization Tender - TEK-C</title>

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
        flex: 0 0 auto;
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

    .purple {
        background: #8b5cf6;
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
        line-height: 1;
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
        gap: 10px;
        margin-bottom: 12px;
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
        min-width: 155px;
    }

    .role-badge {
        border-radius: 999px;
        padding: 4px 8px;
        font-weight: 900;
        font-size: 10px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        color: #475569;
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
        text-decoration: none;
        cursor: pointer;
        padding: 0;
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

    .edit-btn {
        color: #2563eb;
        background: #eff6ff;
    }

    .delete-btn {
        color: #dc2626;
        background: #fef2f2;
    }

    button.action-btn {
        appearance: none;
        -webkit-appearance: none;
    }

    .action-btn i {
        pointer-events: none;
    }

    .file-btn {
        color: #10b981;
        background: #ecfdf5;
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

    .request-title {
        max-width: 260px;
    }

    .budget-text {
        font-weight: 900;
        color: #111827;
        white-space: nowrap;
    }

    .alert {
        border-radius: var(--radius);
        border: none;
        box-shadow: var(--shadow);
        margin-bottom: 14px;
    }

    .modal-content {
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .modal-title {
        font-size: 15px;
        font-weight: 900;
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
            flex: 0 0 100px;
        }

        .compact-table tbody td:first-child {
            display: block;
        }

        .compact-table tbody td:first-child::before {
            display: none;
        }

        .request-title {
            max-width: none;
        }

        .action-group {
            justify-content: flex-start;
            flex-wrap: wrap;
        }
    }

    @media(max-width:768px) {
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
        .primary-btn,
        .secondary-btn {
            width: 100%;
            justify-content: center;
        }

        .search-box {
            max-width: none;
            width: 100%;
            flex: 1 1 100%;
        }

        .stat-card {
            min-height: 72px;
        }
    }
    </style>
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main" aria-label="Main">
            <?php include 'includes/topbar.php'; ?>

            <div id="contentScroll" class="content-scroll">
                <div class="container-fluid projects-wrapper px-0">

                    <div class="page-heading">
                        <div>
                            <h1>Vendor Finalization Tender</h1>
                            <p>
                                <i class="bi bi-person-badge me-1"></i>
                                Role:
                                <span class="role-badge">
                                    <i class="bi bi-pencil-square"></i>
                                    <?php echo e(getRoleDisplay($designation)); ?>
                                </span>
                                <span class="ms-1">Edit and direct delete available only for Draft and Pending
                                    Assignment requests.</span>
                            </p>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <a href="quotation-requests.php" class="primary-btn">
                                <i class="bi bi-plus-circle"></i>
                                New Request
                            </a>
                        </div>
                    </div>

                    <?php if ($status && $message): ?>
                    <div class="alert alert-<?php echo $status === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show"
                        role="alert">
                        <i
                            class="bi bi-<?php echo $status === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-file-text"></i></div>
                                <div>
                                    <div class="stat-label">Total Requests</div>
                                    <div class="stat-value"><?php echo (int)$total_requests; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="stat-label">Pending</div>
                                    <div class="stat-value"><?php echo (int)$pending_count; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <div class="stat-label">Approved</div>
                                    <div class="stat-value"><?php echo (int)$approved_count; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic red"><i class="bi bi-pencil-square"></i></div>
                                <div>
                                    <div class="stat-label">Drafts</div>
                                    <div class="stat-value"><?php echo (int)$draft_count; ?></div>
                                </div>
                            </div>
                        </div>

                        <?php if ($rejected_count > 0): ?>
                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic purple"><i class="bi bi-x-circle"></i></div>
                                <div>
                                    <div class="stat-label">Rejected</div>
                                    <div class="stat-value"><?php echo (int)$rejected_count; ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="panel mb-4">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">Quotation Requests</h3>
                                <div class="panel-subtitle">
                                    Showing <span id="visibleCount"><?php echo count($requests); ?></span> of
                                    <?php echo (int)$total_requests; ?> records
                                </div>
                            </div>
                        </div>

                        <div class="filter-bar">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="requestSearch"
                                    placeholder="Search request no, title, project, type or status...">
                            </div>

                            <select class="filter-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="draft">Draft</option>
                                <option value="pending assignment">Pending Assignment</option>
                                <option value="assigned">Assigned</option>
                                <option value="quotations received">Quotations Received</option>
                                <option value="with qs">With QS</option>
                                <option value="qs finalized">QS Finalized</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                                <option value="cancelled">Cancelled</option>
                            </select>

                            <select class="filter-select" id="priorityFilter">
                                <option value="">All Priority</option>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>

                            <button type="button" class="secondary-btn" id="resetFilters">
                                <i class="bi bi-arrow-repeat"></i>
                                Reset
                            </button>
                        </div>

                        <div class="compact-table-wrap">
                            <table class="table compact-table align-middle" id="quotationRequestsTable">
                                <thead>
                                    <tr>
                                        <th>Request</th>
                                        <th>Project</th>
                                        <th>Type</th>
                                        <th>Date</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Budget</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (empty($requests)): ?>
                                    <tr class="empty-row">
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No quotation requests found.
                                                <div class="mt-2">
                                                    <a href="quotation-requests.php" class="primary-btn">
                                                        <i class="bi bi-plus-circle"></i>
                                                        Create your first request
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($requests as $req): ?>
                                    <?php
              $is_editable = in_array($req['status'], $editable_statuses, true);
              $is_deletable = in_array($req['status'], $deletable_statuses, true);
              $statusKey = strtolower(trim((string)($req['status'] ?? '')));
              $priorityKey = strtolower(trim((string)($req['priority'] ?? '')));
              $budget = (!empty($req['estimated_budget']) && (float)$req['estimated_budget'] > 0)
                ? '₹ ' . number_format((float)$req['estimated_budget'], 2)
                : '—';
            ?>
                                    <tr data-status="<?php echo e($statusKey); ?>"
                                        data-priority="<?php echo e($priorityKey); ?>">
                                        <td data-label="Request">
                                            <div class="table-title-cell">
                                                <div class="table-icon"><i class="bi bi-file-earmark-text"></i></div>
                                                <div class="request-title">
                                                    <div class="table-primary-text">
                                                        <?php echo e($req['title'] ?? ''); ?>
                                                    </div>
                                                    <div class="table-secondary-text">
                                                        <?php echo e($req['request_no'] ?? ''); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td data-label="Project">
                                            <div class="table-primary-text">
                                                <?php echo e($req['project_name'] ?? ''); ?>
                                            </div>
                                            <div class="table-secondary-text">
                                                <?php if (!empty($req['project_code'])): ?>
                                                Code: <?php echo e($req['project_code']); ?>
                                                <?php endif; ?>
                                                <?php if (!empty($req['project_location'])): ?>
                                                <?php echo !empty($req['project_code']) ? ' • ' : ''; ?>
                                                <?php echo e($req['project_location']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td data-label="Type">
                                            <div class="table-primary-text">
                                                <?php echo e($req['quotation_type'] ?? '—'); ?></div>
                                        </td>

                                        <td data-label="Date">
                                            <div class="table-primary-text">
                                                <?php echo e(safeDate($req['request_date'] ?? '')); ?></div>
                                            <div class="table-secondary-text">
                                                Created: <?php echo e(safeDate($req['created_at'] ?? '')); ?>
                                            </div>
                                        </td>

                                        <td data-label="Priority">
                                            <?php echo getPriorityBadge($req['priority'] ?? ''); ?>
                                        </td>

                                        <td data-label="Status">
                                            <?php echo getStatusBadge($req['status'] ?? ''); ?>
                                        </td>

                                        <td data-label="Budget">
                                            <span class="budget-text"><?php echo e($budget); ?></span>
                                        </td>

                                        <td data-label="Actions">
                                            <div class="action-group">
                                                <a href="view-quotation-request.php?id=<?php echo (int)$req['id']; ?>"
                                                    class="action-btn view-btn" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>

                                                <?php if ($is_editable): ?>
                                                <a href="edit-quotation-request.php?id=<?php echo (int)$req['id']; ?>"
                                                    class="action-btn edit-btn" title="Edit">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                                <?php endif; ?>

                                                <?php if ($is_deletable): ?>
                                                <button type="button"
                                                    onclick="deleteRequest(<?php echo (int)$req['id']; ?>, '<?php echo e(addslashes($req['title'] ?? '')); ?>')"
                                                    class="action-btn delete-btn" title="Delete">
                                                    <i class="bi bi-trash"></i>
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

                        <div class="table-secondary-text mt-2">
                            <i class="bi bi-info-circle"></i>
                            Draft and Pending Assignment requests can be edited or directly deleted. Once assigned,
                            delete is locked.
                        </div>
                    </div>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Delete Quotation Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Are you sure you want to delete this quotation request?</p>
                    <div class="summary-card border rounded-3 p-3 bg-light">
                        <div class="table-primary-text" id="deleteRequestTitle"></div>
                    </div>
                    <p class="text-danger small mt-3 mb-0">This action cannot be undone.</p>
                    <p class="text-muted small mt-1 mb-0">Only Draft and Pending Assignment requests are allowed for
                        direct delete.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" class="m-0">
                        <input type="hidden" name="action" value="delete_request">
                        <input type="hidden" name="request_id" id="deleteRequestId" value="">
                        <button type="submit" id="confirmDeleteBtn" class="primary-btn" style="background:#dc2626;"
                            onclick="return confirm('Confirm direct delete? This cannot be undone.');">
                            <i class="bi bi-trash"></i>
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
    function deleteRequest(id, title) {
        const titleEl = document.getElementById('deleteRequestTitle');
        const deleteIdInput = document.getElementById('deleteRequestId');

        if (titleEl) titleEl.innerText = title || 'Selected request';
        if (deleteIdInput) deleteIdInput.value = id;

        const modalEl = document.getElementById('deleteModal');
        if (modalEl) {
            const deleteModal = new bootstrap.Modal(modalEl);
            deleteModal.show();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('requestSearch');
        const statusFilter = document.getElementById('statusFilter');
        const priorityFilter = document.getElementById('priorityFilter');
        const resetBtn = document.getElementById('resetFilters');
        const visibleCount = document.getElementById('visibleCount');
        const rows = Array.from(document.querySelectorAll('#quotationRequestsTable tbody tr')).filter(row => !
            row.classList.contains('empty-row'));

        function applyFilters() {
            const searchValue = (searchInput?.value || '').toLowerCase().trim();
            const statusValue = (statusFilter?.value || '').toLowerCase().trim();
            const priorityValue = (priorityFilter?.value || '').toLowerCase().trim();

            let shown = 0;

            rows.forEach(function(row) {
                const rowText = row.innerText.toLowerCase();
                const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
                const rowPriority = (row.getAttribute('data-priority') || '').toLowerCase();

                const matchesSearch = !searchValue || rowText.includes(searchValue);
                const matchesStatus = !statusValue || rowStatus === statusValue;
                const matchesPriority = !priorityValue || rowPriority === priorityValue;

                const show = matchesSearch && matchesStatus && matchesPriority;
                row.style.display = show ? '' : 'none';

                if (show) shown++;
            });

            if (visibleCount) visibleCount.textContent = shown;
        }

        if (searchInput) searchInput.addEventListener('input', applyFilters);
        if (statusFilter) statusFilter.addEventListener('change', applyFilters);
        if (priorityFilter) priorityFilter.addEventListener('change', applyFilters);

        if (resetBtn) {
            resetBtn.addEventListener('click', function() {
                if (searchInput) searchInput.value = '';
                if (statusFilter) statusFilter.value = '';
                if (priorityFilter) priorityFilter.value = '';
                applyFilters();
            });
        }
    });
    </script>
</body>

</html>