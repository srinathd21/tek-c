<?php
// quotation-requests.php
session_start();
require_once 'includes/db-config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$user_id = (int)$_SESSION['employee_id'];
$success = '';
$error   = '';

$currentEmployee = null;
$empStmt = mysqli_prepare($conn, "SELECT id, full_name, designation, department FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
if ($empStmt) {
    mysqli_stmt_bind_param($empStmt, "i", $user_id);
    mysqli_stmt_execute($empStmt);
    $empRes = mysqli_stmt_get_result($empStmt);
    $currentEmployee = mysqli_fetch_assoc($empRes);
    mysqli_stmt_close($empStmt);
}

if (!$currentEmployee) {
    header('Location: ../login.php');
    exit();
}

$user_designation = strtolower(trim((string)($currentEmployee['designation'] ?? ($_SESSION['designation'] ?? ''))));
$userRoleKey = roleKeyFromDesignation($user_designation);

// Get site_id from URL if present
$preselected_site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;

// ============================================================
// AUTHORIZATION: Only allow Project Engineers and Team Leads
// ============================================================
if (!in_array($userRoleKey, ['project_engineer', 'tl'], true)) {
    header('Location: index.php');
    exit();
}

// Helper functions
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) return false;
    mysqli_stmt_bind_param($st, "ss", $table, $column);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $ok = (bool)mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    return $ok;
}

function roleKeyFromDesignation(string $designation): string {
    $d = strtolower(trim($designation));

    if (
        str_contains($d, 'team lead') ||
        str_contains($d, 'teamleader') ||
        str_contains($d, 'tl') ||
        str_contains($d, 'lead')
    ) return 'tl';

    if (
        str_contains($d, 'project engineer') ||
        str_contains($d, 'engineer')
    ) return 'project_engineer';

    return 'other';
}


function getPriorityBadge($priority) {
    $classes = [
        'Low' => 'neutral',
        'Medium' => 'progressing',
        'High' => 'pending',
        'Urgent' => 'atrisk'
    ];
    $class = $classes[$priority] ?? 'neutral';
    return '<span class="badge-pill ' . $class . '"><span class="mini-dot"></span>' . e($priority) . '</span>';
}

function getStatusBadge($status) {
    $classes = [
        'Draft' => 'neutral',
        'Pending Assignment' => 'pending',
        'Assigned' => 'progressing',
        'Quotations Received' => 'progressing',
        'With QS' => 'pending',
        'QS Finalized' => 'ontrack',
        'Approved' => 'ontrack',
        'Rejected' => 'atrisk',
        'Cancelled' => 'neutral'
    ];
    $class = $classes[$status] ?? 'neutral';
    return '<span class="badge-pill ' . $class . '"><span class="mini-dot"></span>' . e($status) . '</span>';
}

function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

// ============================================================
// GET SITES ASSIGNED TO THIS PE/TL
// PE: site_project_engineers
// TL: sites.team_lead_employee_id OR fallback site_project_engineers
// ============================================================
$hasTeamLeadCol = hasColumn($conn, 'sites', 'team_lead_employee_id');
$teamLeadSelect = $hasTeamLeadCol ? "s.team_lead_employee_id," : "NULL AS team_lead_employee_id,";

if ($userRoleKey === 'tl' && $hasTeamLeadCol) {
    $sites_query = "SELECT DISTINCT
                        s.id,
                        s.project_name,
                        s.project_code,
                        s.project_location,
                        s.project_type,
                        s.start_date,
                        s.expected_completion_date,
                        $teamLeadSelect
                        c.client_name,
                        c.company_name
                    FROM sites s
                    LEFT JOIN site_project_engineers spe ON spe.site_id = s.id
                    LEFT JOIN clients c ON c.id = s.client_id
                    WHERE (
                        s.team_lead_employee_id = ?
                        OR spe.employee_id = ?
                    )
                    AND s.deleted_at IS NULL
                    ORDER BY s.project_name ASC";

    $stmt = mysqli_prepare($conn, $sites_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $user_id, $user_id);
    }
} else {
    $sites_query = "SELECT DISTINCT
                        s.id,
                        s.project_name,
                        s.project_code,
                        s.project_location,
                        s.project_type,
                        s.start_date,
                        s.expected_completion_date,
                        $teamLeadSelect
                        c.client_name,
                        c.company_name
                    FROM sites s
                    INNER JOIN site_project_engineers spe ON spe.site_id = s.id
                    LEFT JOIN clients c ON c.id = s.client_id
                    WHERE spe.employee_id = ?
                    AND s.deleted_at IS NULL
                    ORDER BY s.project_name ASC";

    $stmt = mysqli_prepare($conn, $sites_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
    }
}

if ($stmt) {
    mysqli_stmt_execute($stmt);
    $sites_result = mysqli_stmt_get_result($stmt);
    $sites = mysqli_fetch_all($sites_result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    // Validate preselected site belongs to this user.
    if ($preselected_site_id > 0) {
        $site_valid = false;
        foreach ($sites as $site) {
            if ((int)$site['id'] === (int)$preselected_site_id) {
                $site_valid = true;
                break;
            }
        }

        if (!$site_valid) {
            $preselected_site_id = 0;
            $error = "Invalid site selected or you don't have access to this site.";
        }
    }
} else {
    $sites = [];
    $error = "Failed to fetch sites: " . mysqli_error($conn);
}

// Get recent quotation requests for this user (created by them)
$recent_requests_query = "SELECT qr.*, s.project_name, s.project_code 
                          FROM quotation_requests qr
                          JOIN sites s ON qr.site_id = s.id
                          WHERE qr.requested_by = ?
                          ORDER BY qr.created_at DESC
                          LIMIT 5";

$stmt = mysqli_prepare($conn, $recent_requests_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $recent_requests = mysqli_stmt_get_result($stmt);
    $recent_requests_list = mysqli_fetch_all($recent_requests, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
} else {
    $recent_requests_list = [];
}

// Get status message if any
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';

// Stats
$total_requests = count($recent_requests_list);
$draft_count = 0;
$pending_count = 0;
$approved_count = 0;

foreach ($recent_requests_list as $req) {
    if ($req['status'] === 'Draft') $draft_count++;
    elseif ($req['status'] === 'Approved') $approved_count++;
    elseif (in_array($req['status'], ['Pending Assignment', 'Assigned', 'Quotations Received', 'With QS'])) $pending_count++;
}

// Get site name for display if preselected
$preselected_site_name = '';
if ($preselected_site_id > 0) {
    foreach ($sites as $site) {
        if ($site['id'] == $preselected_site_id) {
            $preselected_site_name = $site['project_name'];
            if (!empty($site['project_code'])) {
                $preselected_site_name .= ' (' . $site['project_code'] . ')';
            }
            break;
        }
    }
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Vendor Finalization Tender - TEK-C Dashboard</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <!-- Flatpickr for date picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />

    <!-- TEK-C Custom Styles -->
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
        --blue: #2f80ed;
        --orange: #f2994a;
        --green: #27ae60;
        --red: #eb5757;
        --purple: #7c3aed;
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

    .primary-btn,
    .secondary-btn {
        min-height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
        border: 0;
    }

    .primary-btn {
        background: #111827;
        color: #fff;
    }

    .primary-btn:hover {
        background: #020617;
        color: #fff;
    }

    .secondary-btn {
        border: 1px solid var(--border);
        background: #fff;
        color: #334155;
    }

    .secondary-btn:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #111827;
    }

    .panel {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 13px;
        margin-bottom: 14px;
    }

    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .panel-title {
        font-weight: 900;
        font-size: 14px;
        color: var(--text);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .panel-title i {
        color: var(--blue);
        font-size: 16px;
    }

    .panel-subtitle {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
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

    .stat-ic.blue {
        background: var(--blue);
    }

    .stat-ic.green {
        background: var(--green);
    }

    .stat-ic.yellow {
        background: var(--orange);
    }

    .stat-ic.red {
        background: var(--red);
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
        color: var(--text);
        line-height: 1;
    }

    .form-section {
        border: 1px solid #eef2f7;
        background: #fff;
        border-radius: 14px;
        padding: 13px;
        margin-bottom: 13px;
    }

    .form-section-title {
        font-weight: 900;
        font-size: 13px;
        color: #111827;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #eef2f7;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-section-title i {
        color: var(--blue);
        font-size: 15px;
    }

    .form-label {
        font-size: 11px;
        font-weight: 900;
        color: #475569;
        text-transform: uppercase;
        margin-bottom: 6px;
    }

    .form-control,
    .form-select {
        min-height: 38px;
        border: 1px solid var(--border);
        border-radius: 11px;
        font-size: 12px;
        font-weight: 800;
        color: #111827;
        padding: 8px 11px;
        background-color: #fff;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .form-control::placeholder {
        color: #9ca3af;
        font-weight: 600;
    }

    .required:after {
        content: " *";
        color: var(--red);
        font-weight: 900;
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
        text-decoration: none;
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

    .neutral {
        color: #475569;
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .btn-action {
        min-height: 32px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: #64748b;
        text-decoration: none;
        transition: .15s ease;
        font-weight: 900;
        font-size: 11px;
        padding: 0 10px;
    }

    .btn-action:hover {
        background: #f8fafc;
        color: #111827;
    }

    .file-upload {
        border: 1.5px dashed #cbd5e1;
        border-radius: 14px;
        padding: 18px;
        text-align: center;
        background: #f8fafc;
        cursor: pointer;
        transition: .15s ease;
    }

    .file-upload:hover {
        border-color: #93c5fd;
        background: #eff6ff;
    }

    .file-upload i {
        font-size: 30px;
        color: #94a3b8;
        margin-bottom: 8px;
    }

    .file-upload p {
        margin: 0;
        font-weight: 900;
        color: #475569;
        font-size: 12px;
    }

    .file-upload small {
        color: #94a3b8;
        font-weight: 700;
        font-size: 10.5px;
    }

    .file-list {
        margin-top: 12px;
    }

    .file-item {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 8px 10px;
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 11px;
        margin-bottom: 8px;
        font-size: 11px;
    }

    .file-item i {
        color: var(--blue);
    }

    .file-item .file-name {
        flex: 1;
        font-weight: 900;
        color: #334155;
    }

    .file-item .file-size {
        color: #64748b;
        font-weight: 800;
        font-size: 10px;
    }

    .file-item .remove-file {
        color: var(--red);
        cursor: pointer;
    }

    .info-note,
    .site-info-banner {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 13px;
        padding: 11px 13px;
        margin-top: 14px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .info-note i,
    .site-info-banner i {
        color: #2563eb;
        font-size: 18px;
    }

    .info-note p,
    .site-info-banner p {
        margin: 0;
        color: #1e293b;
        font-weight: 750;
        font-size: 11.5px;
    }

    .site-info-banner .site-name {
        font-weight: 950;
        color: #111827;
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

    .table-primary-text {
        color: #111827;
        font-size: 11.5px;
        font-weight: 950;
    }

    .table-secondary-text {
        color: #64748b;
        font-size: 10px;
        font-weight: 700;
        margin-top: 2px;
    }

    .request-card {
        border: 1px solid var(--border);
        border-radius: 14px;
        background: #fff;
        box-shadow: var(--shadow);
        padding: 12px;
    }

    .request-card .top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }

    .request-card .title {
        font-weight: 950;
        color: #111827;
        font-size: 13px;
        line-height: 1.25;
        margin: 0;
    }

    .request-card .meta {
        margin-top: 6px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px 10px;
        color: #64748b;
        font-weight: 750;
        font-size: 10.5px;
    }

    .request-kv {
        margin-top: 10px;
        display: grid;
        gap: 7px;
    }

    .request-row {
        display: flex;
        gap: 10px;
        align-items: flex-start;
    }

    .request-key {
        flex: 0 0 78px;
        color: #64748b;
        font-weight: 900;
        font-size: 10.5px;
        text-transform: uppercase;
    }

    .request-val {
        flex: 1 1 auto;
        font-weight: 900;
        color: #111827;
        font-size: 11.5px;
        line-height: 1.3;
        word-break: break-word;
    }

    .request-actions {
        margin-top: 12px;
        display: grid;
        gap: 8px;
    }

    .request-actions a {
        width: 100%;
        border-radius: 11px;
        justify-content: center;
    }

    .empty-state {
        text-align: center;
        padding: 30px 12px;
        color: #64748b;
        font-size: 12px;
        font-weight: 900;
    }

    .empty-state i {
        display: block;
        font-size: 34px;
        opacity: .45;
        margin-bottom: 8px;
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
    }

    @media(max-width:768px) {
        .content-scroll {
            padding: 12px 10px !important;
        }

        .page-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .panel {
            padding: 12px;
        }

        .primary-btn,
        .secondary-btn {
            width: 100%;
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

                    <!-- Status Messages -->
                    <?php if ($status && $message): ?>
                    <div class="alert alert-<?php echo $status === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show"
                        role="alert">
                        <i
                            class="bi bi-<?php echo $status === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Site Info Banner (if site preselected) -->
                    <?php if ($preselected_site_id > 0 && $preselected_site_name): ?>
                    <div class="site-info-banner">
                        <i class="bi bi-info-circle-fill"></i>
                        <div>
                            <strong>Creating quotation request for site:</strong>
                            <span class="site-name"><?php echo e($preselected_site_name); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Page Header -->
                    <div class="page-heading">
                        <div>
                            <h1>Vendor Finalization Tender</h1>
                            <p>Create supplier quotation request based on project drawings and requirements.</p>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge-pill neutral">
                                <i class="bi bi-person-badge"></i>
                                <?php echo e(strtoupper(str_replace('_', ' ', $userRoleKey))); ?>
                            </span>
                            <a href="my-quotation-requests.php" class="secondary-btn">
                                <i class="bi bi-arrow-left"></i>
                                Back to Requests
                            </a>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-file-text"></i></div>
                                <div>
                                    <div class="stat-label">Total Requests</div>
                                    <div class="stat-value"><?php echo (int)$total_requests; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic yellow"><i class="bi bi-clock-history"></i></div>
                                <div>
                                    <div class="stat-label">Pending</div>
                                    <div class="stat-value"><?php echo (int)$pending_count; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                                <div>
                                    <div class="stat-label">Approved</div>
                                    <div class="stat-value"><?php echo (int)$approved_count; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Form Panel -->
                    <div class="panel">
                        <form id="quotationRequestForm" method="POST" action="process-quotation-request.php"
                            enctype="multipart/form-data">

                            <!-- Basic Information Section -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="bi bi-info-circle"></i> Basic Information
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label required">Site/Project</label>
                                        <select class="form-select" name="site_id" id="site_id" required>
                                            <option value="" selected disabled>Select site</option>
                                            <?php 
                      if (!empty($sites)) {
                          foreach ($sites as $site) {
                              $display_text = e($site['project_name']);
                              if (!empty($site['project_code'])) {
                                  $display_text .= ' (' . e($site['project_code']) . ')';
                              }
                              $selected = ($site['id'] == $preselected_site_id) ? 'selected' : '';
                              echo "<option value='" . $site['id'] . "' $selected>" . $display_text . "</option>";
                          }
                      } else {
                          echo "<option value='' disabled>No sites assigned to you</option>";
                      }
                      ?>
                                        </select>
                                        <?php if (empty($sites)): ?>
                                        <small class="text-danger">No projects assigned to you. TL projects are checked
                                            from sites.team_lead_employee_id and PE projects from
                                            site_project_engineers.</small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label required">Request Title</label>
                                        <input type="text" class="form-control" name="title"
                                            placeholder="e.g., Electrical materials for Tower A" required>
                                    </div>

                                    <!-- CHANGED: Quotation Type now TEXT INPUT instead of dropdown -->
                                    <div class="col-md-6">
                                        <label class="form-label required">Quotation Type</label>
                                        <input type="text" class="form-control" name="quotation_type"
                                            placeholder="e.g., Electrical, Plumbing, Civil, Painting, etc." required>
                                        <small class="text-muted">Enter the type of materials/services needed for
                                            quotation</small>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label required">Priority</label>
                                        <select class="form-select" name="priority" required>
                                            <option value="Medium" selected>Medium</option>
                                            <option value="Low">Low</option>
                                            <option value="High">High</option>
                                            <option value="Urgent">Urgent</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label required">Request Date</label>
                                        <input type="text" class="form-control datepicker" name="request_date"
                                            value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Required By Date</label>
                                        <input type="text" class="form-control datepicker" name="required_by_date"
                                            placeholder="Select date">
                                    </div>
                                </div>
                            </div>

                            <!-- Description & Specifications Section -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="bi bi-file-text"></i> Description & Specifications
                                </div>

                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label required">Description</label>
                                        <textarea class="form-control" name="description" rows="3"
                                            placeholder="Describe what you need quotations for..." required></textarea>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Specifications (Optional)</label>
                                        <textarea class="form-control" name="specifications" rows="2"
                                            placeholder="Technical specifications, quality requirements, etc."></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Drawing & Documents Section -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="bi bi-file-earmark-image"></i> Drawing & Documents
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Drawing Number (Optional)</label>
                                        <input type="text" class="form-control" name="drawing_number"
                                            placeholder="e.g., DWG-2024-001">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Drawing File (Optional)</label>
                                        <input type="file" class="form-control" name="drawing_file"
                                            accept=".pdf,.dwg,.dxf,.jpg,.png">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Additional Documents (Optional)</label>
                                        <div class="file-upload" id="fileUploadArea">
                                            <i class="bi bi-cloud-upload"></i>
                                            <p>Click or drag files to upload</p>
                                            <small>Supported formats: PDF, DWG, DXF, JPG, PNG (Max: 25MB each)</small>
                                            <input type="file" id="fileInput" name="additional_files[]" multiple
                                                style="display:none;">
                                        </div>

                                        <!-- File List -->
                                        <div class="file-list" id="fileList"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- REMOVED: Budget & Quantity (Optional) section completely -->

                            <!-- Additional Notes -->
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="bi bi-pencil"></i> Additional Notes
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <textarea class="form-control" name="notes" rows="2"
                                            placeholder="Any special instructions or notes for the TL/dealers..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Info Note -->
                            <div class="info-note">
                                <i class="bi bi-info-circle-fill"></i>
                                <p>After submission, this request will move to the quotation workflow for TL/QS/dealer
                                    follow-up based on the selected project.</p>
                            </div>

                            <!-- Form Actions -->
                            <div class="d-flex gap-2 justify-content-end mt-4">
                                <button type="button" class="secondary-btn"
                                    onclick="window.location.href='my-quotation-requests.php'">
                                    <i class="bi bi-x-lg"></i> Cancel
                                </button>
                                <button type="submit" class="primary-btn"
                                    <?php echo empty($sites) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-check-lg"></i> Submit Request
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Recent Requests -->
                    <div class="panel mt-4">
                        <div class="panel-header">
                            <h3 class="panel-title">
                                <i class="bi bi-clock-history"></i> Recent Requests
                            </h3>
                            <a href="my-quotation-requests.php" class="muted-link" style="font-size:13px;">View All <i
                                    class="bi bi-arrow-right"></i></a>
                        </div>

                        <!-- MOBILE: Cards -->
                        <div class="d-block d-md-none">
                            <div class="d-grid gap-3">
                                <?php if (empty($recent_requests_list)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    No quotation requests yet
                                </div>
                                <?php else: ?>
                                <?php foreach ($recent_requests_list as $request): ?>
                                <div class="request-card">
                                    <div class="top">
                                        <div style="flex:1 1 auto;">
                                            <div class="d-flex align-items-center justify-content-between gap-2">
                                                <h4 class="title"><?php echo e($request['title']); ?></h4>
                                                <span class="badge <?php 
                              $priority = $request['priority'] ?? 'Medium';
                              if ($priority === 'Urgent') echo 'bg-danger';
                              elseif ($priority === 'High') echo 'bg-warning';
                              elseif ($priority === 'Medium') echo 'bg-info';
                              else echo 'bg-secondary';
                            ?>"><?php echo e($priority); ?></span>
                                            </div>

                                            <div class="meta">
                                                <span><i class="bi bi-building"></i>
                                                    <?php echo e($request['project_name'] ?? ''); ?></span>
                                                <span><i class="bi bi-tag"></i>
                                                    <?php echo e($request['quotation_type'] ?? ''); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="request-kv">
                                        <div class="request-row">
                                            <div class="request-key">Request No.</div>
                                            <div class="request-val fw-800"><?php echo e($request['request_no']); ?>
                                            </div>
                                        </div>

                                        <div class="request-row">
                                            <div class="request-key">Date</div>
                                            <div class="request-val"><?php echo safeDate($request['request_date']); ?>
                                            </div>
                                        </div>

                                        <div class="request-row">
                                            <div class="request-key">Status</div>
                                            <div class="request-val"><?php echo getStatusBadge($request['status']); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="request-actions">
                                        <a href="view-quotation-request.php?id=<?php echo $request['id']; ?>"
                                            class="btn-action" title="View Details">
                                            <i class="bi bi-eye"></i> View Details
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- DESKTOP/TABLET: DataTable -->
                        <div class="d-none d-md-block">
                            <div class="compact-table-wrap">
                                <table id="recentRequestsTable" class="table compact-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Request No.</th>
                                            <th>Title</th>
                                            <th>Site/Project</th>
                                            <th>Type</th>
                                            <th>Date</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($recent_requests_list)): ?>
                                        <?php foreach ($recent_requests_list as $request): ?>
                                        <tr>
                                            <td data-label="Request No."><span
                                                    class="table-primary-text"><?php echo e($request['request_no']); ?></span>
                                            </td>
                                            <td data-label="Title"><span
                                                    class="table-primary-text"><?php echo e($request['title']); ?></span>
                                            </td>
                                            <td data-label="Project"><?php echo e($request['project_name']); ?></td>
                                            <td data-label="Type"><?php echo e($request['quotation_type']); ?></td>
                                            <td data-label="Date"><?php echo safeDate($request['request_date']); ?></td>
                                            <td data-label="Priority">
                                                <?php echo getPriorityBadge($request['priority']); ?></td>
                                            <td data-label="Status"><?php echo getStatusBadge($request['status']); ?>
                                            </td>
                                            <td data-label="Action" class="text-end">
                                                <a href="view-quotation-request.php?id=<?php echo $request['id']; ?>"
                                                    class="btn-action" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>

        </main>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <!-- TEK-C Custom JavaScript -->
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof flatpickr !== 'undefined') {
            flatpickr(".datepicker", {
                dateFormat: "Y-m-d",
                allowInput: true
            });
        }

        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        let filesArray = [];

        if (fileUploadArea && fileInput) {
            fileUploadArea.addEventListener('click', () => fileInput.click());

            fileUploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                fileUploadArea.style.borderColor = '#93c5fd';
                fileUploadArea.style.background = '#eff6ff';
            });

            fileUploadArea.addEventListener('dragleave', () => {
                fileUploadArea.style.borderColor = '#cbd5e1';
                fileUploadArea.style.background = '#f8fafc';
            });

            fileUploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                fileUploadArea.style.borderColor = '#cbd5e1';
                fileUploadArea.style.background = '#f8fafc';
                handleFiles(e.dataTransfer.files);
            });

            fileInput.addEventListener('change', (e) => {
                handleFiles(e.target.files);
            });
        }

        function handleFiles(files) {
            for (let file of files) {
                if (file.size > 25 * 1024 * 1024) {
                    alert(`File ${file.name} is too large. Max size is 25MB.`);
                    continue;
                }

                filesArray.push(file);
                displayFileItem(file);
            }
        }

        function displayFileItem(file) {
            if (!fileList) return;

            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';

            const sizeKb = file.size / 1024;
            const displaySize = sizeKb > 1024 ? (sizeKb / 1024).toFixed(1) : sizeKb.toFixed(1);
            const sizeUnit = sizeKb > 1024 ? 'MB' : 'KB';

            fileItem.innerHTML = `
          <i class="bi bi-file-earmark"></i>
          <span class="file-name"></span>
          <span class="file-size">${displaySize} ${sizeUnit}</span>
          <i class="bi bi-x-circle remove-file"></i>
        `;

            fileItem.querySelector('.file-name').textContent = file.name;
            fileItem.querySelector('.remove-file').addEventListener('click', () => {
                fileItem.remove();
                filesArray = filesArray.filter(f => f.name !== file.name);
            });

            fileList.appendChild(fileItem);
        }

        const form = document.getElementById('quotationRequestForm');
        if (form) {
            form.addEventListener('submit', (e) => {
                const title = form.querySelector('[name="title"]').value.trim();
                const type = form.querySelector('[name="quotation_type"]').value.trim();
                const site = form.querySelector('[name="site_id"]').value;
                const description = form.querySelector('[name="description"]').value.trim();

                if (!title || !type || !site || !description) {
                    e.preventDefault();
                    alert('Please fill in all required fields');
                }
            });
        }

        const saveDraftBtn = document.getElementById('saveDraft');
        if (saveDraftBtn && form) {
            saveDraftBtn.addEventListener('click', () => {
                let draftInput = form.querySelector('[name="save_as_draft"]');
                if (!draftInput) {
                    draftInput = document.createElement('input');
                    draftInput.type = 'hidden';
                    draftInput.name = 'save_as_draft';
                    form.appendChild(draftInput);
                }
                draftInput.value = '1';
                form.submit();
            });
        }

        const yearElement = document.getElementById("year");
        if (yearElement) {
            yearElement.textContent = new Date().getFullYear();
        }
    });
    </script>

</body>

</html>