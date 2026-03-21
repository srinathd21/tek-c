<?php
// quotations-in-progress.php (Team Lead) — show quotation requests that are in progress (status = 'Assigned')
// Follows same UI template as assigned-quotations.php

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$requests = [];

// ---------- Auth (Team Lead only) ----------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$empId = (int)$_SESSION['employee_id'];
$empName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown User';
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Allow Team Leads and Project Engineers
$allowed = [
    'team lead',
    'project engineer grade 1',
    'project engineer grade 2',
    'sr. engineer'
];
if (!in_array($designation, $allowed, true)) {
    header("Location: index.php");
    exit;
}

// ---------- Handle Actions ----------
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $request_id = intval($_GET['id']);
    
    if ($action === 'cancel') {
        // Cancel the assignment and put back to pending
        $update_query = "UPDATE quotation_requests SET status = 'Pending Assignment', project_engineer_id = NULL, project_engineer_name = NULL, updated_at = NOW() WHERE id = ? AND project_engineer_id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "ii", $request_id, $empId);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Quotation request cancelled and returned to pending.";
        } else {
            $error = "Failed to cancel quotation request.";
        }
        mysqli_stmt_close($stmt);
    }
}

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function getPriorityBadge($priority) {
    $badges = [
        'Low' => ['bg-secondary', 'bi-arrow-down'],
        'Medium' => ['bg-info', 'bi-dash'],
        'High' => ['bg-warning', 'bi-arrow-up'],
        'Urgent' => ['bg-danger', 'bi-exclamation-triangle']
    ];
    $badge = $badges[$priority] ?? ['bg-secondary', 'bi-question'];
    return '<span class="badge ' . $badge[0] . '"><i class="bi ' . $badge[1] . ' me-1"></i>' . $priority . '</span>';
}

function getStatusBadge($status) {
    $badges = [
        'Draft' => ['bg-secondary', 'bi-pencil'],
        'Pending Assignment' => ['bg-warning', 'bi-clock'],
        'Assigned' => ['bg-info', 'bi-person-check'],
        'Quotations Received' => ['bg-primary', 'bi-file-text'],
        'With QS' => ['bg-secondary', 'bi-arrow-right'],
        'QS Finalized' => ['bg-success', 'bi-check-circle'],
        'Approved' => ['bg-success', 'bi-check-circle-fill'],
        'Rejected' => ['bg-danger', 'bi-x-circle'],
        'Cancelled' => ['bg-dark', 'bi-x']
    ];
    $badge = $badges[$status] ?? ['bg-secondary', 'bi-question'];
    return '<span class="badge ' . $badge[0] . '"><i class="bi ' . $badge[1] . ' me-1"></i>' . $status . '</span>';
}

function getTimeAgo($datetime) {
    if (empty($datetime)) return '—';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('d M Y', $time);
}

// ---------- Fetch sites where this employee is the Team Lead ----------
$tl_sites_query = "SELECT id, project_name FROM sites WHERE team_lead_employee_id = ? AND deleted_at IS NULL";
$stmt = mysqli_prepare($conn, $tl_sites_query);
mysqli_stmt_bind_param($stmt, "i", $empId);
mysqli_stmt_execute($stmt);
$tl_sites_result = mysqli_stmt_get_result($stmt);
$tl_site_ids = [];
while ($site = mysqli_fetch_assoc($tl_sites_result)) {
    $tl_site_ids[] = $site['id'];
}
mysqli_stmt_close($stmt);

// ---------- Fetch quotations in progress (status = 'Assigned') ----------
$sql = "";
$params = [];
$types = "";

if (empty($tl_site_ids)) {
    // If no sites assigned as Team Lead, show nothing
    $requests = [];
} else {
    // Build IN clause with site IDs
    $placeholders = implode(',', array_fill(0, count($tl_site_ids), '?'));
    
    $sql = "
        SELECT 
            qr.*,
            s.project_name,
            s.project_code,
            s.project_location,
            s.scope_of_work,
            c.client_name,
            c.company_name,
            c.mobile_number AS client_mobile,
            m.full_name AS manager_name,
            m.employee_code AS manager_code,
            DATEDIFF(qr.required_by_date, CURDATE()) AS days_remaining,
            (
                SELECT COUNT(*) 
                FROM quotation_requests_dealers qrd 
                WHERE qrd.quotation_request_id = qr.id
            ) AS dealers_count,
            (
                SELECT COUNT(*) 
                FROM quotations q 
                WHERE q.quotation_request_id = qr.id
            ) AS quotations_count
        FROM quotation_requests qr
        JOIN sites s ON qr.site_id = s.id
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN employees m ON s.manager_employee_id = m.id
        WHERE qr.status = 'Assigned'
        AND (qr.project_engineer_id = ? OR qr.site_id IN ($placeholders))
        ORDER BY 
            CASE 
                WHEN qr.priority = 'Urgent' THEN 1
                WHEN qr.priority = 'High' THEN 2
                WHEN qr.priority = 'Medium' THEN 3
                ELSE 4
            END,
            qr.required_by_date ASC,
            qr.created_at DESC
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        // Bind parameters: first the project_engineer_id, then site IDs
        $param_types = "i" . str_repeat('i', count($tl_site_ids));
        $params = array_merge([$empId], $tl_site_ids);
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $requests = mysqli_fetch_all($res, MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    } else {
        $error = "Database error: " . mysqli_error($conn);
    }
}

// ---------- Stats ----------
$total_in_progress = count($requests);
$urgent_count = 0;
$high_count = 0;
$overdue_count = 0;
$total_dealers_assigned = 0;
$total_quotations_received = 0;

foreach ($requests as $req) {
    if ($req['priority'] === 'Urgent') $urgent_count++;
    if ($req['priority'] === 'High') $high_count++;
    
    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
        $required = strtotime($req['required_by_date']);
        if ($required < time()) $overdue_count++;
    }
    
    $total_dealers_assigned += intval($req['dealers_count'] ?? 0);
    $total_quotations_received += intval($req['quotations_count'] ?? 0);
}

// Get status message if any
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Quotations In Progress - TEK-C</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <!-- DataTables (Bootstrap 5 + Responsive) -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

        .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
            padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
        .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
        .stat-ic.blue{ background: var(--blue); }
        .stat-ic.green{ background: #10b981; }
        .stat-ic.yellow{ background: #f59e0b; }
        .stat-ic.red{ background: #ef4444; }
        .stat-ic.purple{ background: #8b5cf6; }
        .stat-ic.orange{ background: #f2994a; }
        .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
        .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

        .table-responsive { overflow-x: hidden !important; }
        table.dataTable { width:100% !important; }
        .table thead th{
            font-size: 11px; color:#6b7280; font-weight:800;
            border-bottom:1px solid var(--border)!important;
            padding: 10px 10px !important;
            white-space: normal !important;
        }
        .table td{
            vertical-align: middle; border-color: var(--border);
            font-weight:650; color:#374151;
            padding: 10px 10px !important;
            white-space: normal !important;
            word-break: break-word;
        }

        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 7px 10px;
            color: var(--muted);
            font-size: 12px;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            font-weight: 900;
        }
        .btn-action:hover { background: var(--bg); color: var(--blue); }
        .btn-action.cancel{
            border-color: rgba(239,68,68,.25);
        }
        .btn-action.view{
            border-color: rgba(45,156,219,.25);
        }
        .btn-action.continue{
            border-color: rgba(16,185,129,.25);
        }

        .proj-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
        .proj-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.25; }

        .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

        div.dataTables_wrapper .dataTables_length select,
        div.dataTables_wrapper .dataTables_filter input{
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 7px 10px;
            font-weight: 650;
            outline: none;
        }
        div.dataTables_wrapper .dataTables_filter input:focus{
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
        }
        .dataTables_paginate .pagination .page-link{
            border-radius: 10px;
            margin: 0 3px;
            font-weight: 750;
        }
        th.actions-col, td.actions-col { width: 140px !important; }

        /* ---------- Mobile Cards ---------- */
        .request-card{
            border:1px solid var(--border);
            border-radius: 16px;
            background: var(--surface);
            box-shadow: var(--shadow);
            padding: 12px;
            position: relative;
        }
        .request-card.urgent{
            border-left: 4px solid #dc2626;
        }
        .request-card.high{
            border-left: 4px solid #f59e0b;
        }
        .request-card.overdue{
            background: rgba(239,68,68,.02);
        }
        .request-card .top{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:10px;
        }
        .request-card .title{
            font-weight:1000;
            color:#111827;
            font-size: 14px;
            line-height:1.2;
            margin:0;
        }
        .request-card .meta{
            margin-top:6px;
            display:flex;
            flex-wrap:wrap;
            gap:8px 10px;
            color:#6b7280;
            font-weight:800;
            font-size:12px;
        }
        .request-kv{ margin-top:10px; display:grid; gap:8px; }
        .request-row{ display:flex; gap:10px; align-items:flex-start; }
        .request-key{
            flex:0 0 85px;
            color:#6b7280;
            font-weight:1000;
            font-size:12px;
        }
        .request-val{
            flex:1 1 auto;
            font-weight:900;
            color:#111827;
            font-size:12.5px;
            line-height:1.3;
            word-break: break-word;
        }
        .request-actions{
            margin-top:12px;
            display:flex;
            gap:8px;
            justify-content:flex-end;
            flex-wrap: wrap;
        }
        .request-actions a, .request-actions button{ 
            padding: 6px 12px; 
            border-radius:10px; 
            justify-content:center;
            white-space: nowrap;
        }
        .days-badge{
            background: #e8f0fe;
            color: var(--blue);
            font-weight: 900;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }
        .days-badge.overdue{
            background: rgba(239,68,68,.12);
            color: #ef4444;
        }
        .progress-stats{
            display: flex;
            gap: 15px;
            margin-top: 8px;
        }
        .stat-chip{
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
        }
        .stat-chip i{
            margin-right: 4px;
        }

        @media (max-width: 991.98px){
            .main{
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .sidebar{
                position: fixed !important;
                transform: translateX(-100%);
                z-index: 1040 !important;
            }
            .sidebar.open, .sidebar.active, .sidebar.show{
                transform: translateX(0) !important;
            }
        }
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
            .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
            .request-actions { flex-wrap: wrap; }
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

                <!-- Status Messages -->
                <?php if ($status && $message): ?>
                    <div class="alert alert-<?php echo $status === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $status === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
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

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Quotations In Progress</h1>
                        <p class="text-muted mb-0">Quotation requests you are currently working on</p>
                    </div>
                    <div>
                        <a href="assigned-quotations.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-play-circle"></i></div>
                            <div>
                                <div class="stat-label">In Progress</div>
                                <div class="stat-value"><?php echo (int)$total_in_progress; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-exclamation-triangle"></i></div>
                            <div>
                                <div class="stat-label">Urgent</div>
                                <div class="stat-value"><?php echo (int)$urgent_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic orange"><i class="bi bi-arrow-up"></i></div>
                            <div>
                                <div class="stat-label">High Priority</div>
                                <div class="stat-value"><?php echo (int)$high_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-clock"></i></div>
                            <div>
                                <div class="stat-label">Overdue</div>
                                <div class="stat-value"><?php echo (int)$overdue_count; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Progress Summary -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-shop"></i></div>
                            <div>
                                <div class="stat-label">Dealers Assigned</div>
                                <div class="stat-value"><?php echo (int)$total_dealers_assigned; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-file-text"></i></div>
                            <div>
                                <div class="stat-label">Quotations Received</div>
                                <div class="stat-value"><?php echo (int)$total_quotations_received; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Banner if no in-progress requests -->
                <?php if (empty($requests)): ?>
                <div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    You don't have any quotation requests in progress. Accept new assignments from the <a href="assigned-quotations.php" class="alert-link">Assigned Quotations</a> page.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Directory -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">In Progress Requests</h3>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <!-- MOBILE: Cards -->
                    <div class="d-block d-md-none">
                        <div class="d-grid gap-3">
                            <?php if (empty($requests)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-play-circle" style="font-size: 48px;"></i>
                                    <p class="mt-2 fw-bold">No quotations in progress</p>
                                    <p class="small">Accept new assignments to start working on them.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($requests as $req): 
                                    $cardClass = '';
                                    if ($req['priority'] === 'Urgent') $cardClass = 'urgent';
                                    elseif ($req['priority'] === 'High') $cardClass = 'high';
                                    
                                    $isOverdue = false;
                                    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
                                        $required = strtotime($req['required_by_date']);
                                        if ($required < time()) {
                                            $cardClass .= ' overdue';
                                            $isOverdue = true;
                                        }
                                    }
                                ?>
                                    <div class="request-card <?php echo $cardClass; ?>">
                                        <div class="top">
                                            <div style="flex:1 1 auto;">
                                                <div class="d-flex align-items-center justify-content-between gap-2">
                                                    <h4 class="title"><?php echo e($req['title']); ?></h4>
                                                    <span class="badge <?php 
                                                        $priority = $req['priority'] ?? 'Medium';
                                                        if ($priority === 'Urgent') echo 'bg-danger';
                                                        elseif ($priority === 'High') echo 'bg-warning';
                                                        elseif ($priority === 'Medium') echo 'bg-info';
                                                        else echo 'bg-secondary';
                                                    ?>"><?php echo e($priority); ?></span>
                                                </div>
                                                
                                                <div class="meta">
                                                    <span><i class="bi bi-building"></i> <?php echo e($req['project_name'] ?? ''); ?></span>
                                                    <span><i class="bi bi-tag"></i> <?php echo e($req['quotation_type'] ?? ''); ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="progress-stats">
                                            <span class="stat-chip"><i class="bi bi-shop"></i> <?php echo intval($req['dealers_count'] ?? 0); ?> Dealers</span>
                                            <span class="stat-chip"><i class="bi bi-file-text"></i> <?php echo intval($req['quotations_count'] ?? 0); ?> Quotes</span>
                                        </div>

                                        <div class="request-kv">
                                            <div class="request-row">
                                                <div class="request-key">Request No.</div>
                                                <div class="request-val fw-800"><?php echo e($req['request_no']); ?></div>
                                            </div>

                                            <div class="request-row">
                                                <div class="request-key">Manager</div>
                                                <div class="request-val"><?php echo e($req['manager_name'] ?? '—'); ?></div>
                                            </div>

                                            <div class="request-row">
                                                <div class="request-key">Required By</div>
                                                <div class="request-val">
                                                    <?php echo safeDate($req['required_by_date']); ?>
                                                    <?php if (!empty($req['days_remaining']) && $req['days_remaining'] > 0): ?>
                                                        <span class="days-badge"><?php echo $req['days_remaining']; ?> days left</span>
                                                    <?php elseif ($isOverdue): ?>
                                                        <span class="days-badge overdue">Overdue</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="request-row">
                                                <div class="request-key">Status</div>
                                                <div class="request-val"><?php echo getStatusBadge($req['status']); ?></div>
                                            </div>
                                        </div>

                                        <div class="request-actions">
                                            <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view" title="View Details">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <a href="manage-quotation.php?id=<?php echo $req['id']; ?>" class="btn-action continue" title="Continue Working">
                                                <i class="bi bi-play-circle"></i> Continue
                                            </a>
                                            <a href="?action=cancel&id=<?php echo $req['id']; ?>" class="btn-action cancel" onclick="return confirm('Are you sure you want to cancel this assignment? The request will be returned to pending.')" title="Cancel">
                                                <i class="bi bi-x-lg"></i> Cancel
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- DESKTOP/TABLET: DataTable -->
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table id="inProgressTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Request No.</th>
                                        <th>Title / Site</th>
                                        <th>Type</th>
                                        <th>Manager</th>
                                        <th>Required By</th>
                                        <th>Dealers</th>
                                        <th>Quotes</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th class="text-end actions-col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($requests as $req): 
                                    $isOverdue = false;
                                    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
                                        $required = strtotime($req['required_by_date']);
                                        if ($required < time()) $isOverdue = true;
                                    }
                                ?>
                                    <tr class="<?php echo $req['priority'] === 'Urgent' ? 'table-danger' : ($req['priority'] === 'High' ? 'table-warning' : ''); ?>">
                                        <td>
                                            <span class="fw-800"><?php echo e($req['request_no']); ?></span>
                                        </td>
                                        <td>
                                            <div class="proj-title"><?php echo e($req['title']); ?></div>
                                            <div class="proj-sub">
                                                <i class="bi bi-building"></i> <?php echo e($req['project_name']); ?>
                                                <?php if (!empty($req['project_code'])): ?>
                                                    (<?php echo e($req['project_code']); ?>)
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo e($req['quotation_type']); ?></td>
                                        <td>
                                            <div class="fw-700"><?php echo e($req['manager_name'] ?? '—'); ?></div>
                                            <?php if (!empty($req['manager_code'])): ?>
                                                <div class="proj-sub"><?php echo e($req['manager_code']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-700 <?php echo $isOverdue ? 'text-danger' : ''; ?>">
                                                <?php echo safeDate($req['required_by_date']); ?>
                                            </div>
                                            <?php if (!empty($req['days_remaining']) && $req['days_remaining'] > 0): ?>
                                                <div class="proj-sub"><?php echo $req['days_remaining']; ?> days left</div>
                                            <?php elseif ($isOverdue): ?>
                                                <div class="proj-sub text-danger">Overdue</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info rounded-pill"><?php echo intval($req['dealers_count'] ?? 0); ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success rounded-pill"><?php echo intval($req['quotations_count'] ?? 0); ?></span>
                                        </td>
                                        <td><?php echo getPriorityBadge($req['priority']); ?></td>
                                        <td><?php echo getStatusBadge($req['status']); ?></td>
                                        <td class="text-end actions-col">
                                            <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="manage-quotation.php?id=<?php echo $req['id']; ?>" class="btn-action continue" title="Continue Working">
                                                <i class="bi bi-play-circle"></i>
                                            </a>
                                            <a href="?action=cancel&id=<?php echo $req['id']; ?>" class="btn-action cancel" onclick="return confirm('Cancel this assignment?')" title="Cancel">
                                                <i class="bi bi-x-lg"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- Quick Tips Panel -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Quick Tips</h3>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="d-flex gap-3">
                                <div class="stat-ic blue" style="width: 40px; height: 40px; font-size: 16px;">
                                    <i class="bi bi-shop"></i>
                                </div>
                                <div>
                                    <h6 class="fw-900 mb-1">Assign Dealers</h6>
                                    <p class="small text-muted mb-0">Add multiple dealers to get competitive quotations.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-3">
                                <div class="stat-ic green" style="width: 40px; height: 40px; font-size: 16px;">
                                    <i class="bi bi-upload"></i>
                                </div>
                                <div>
                                    <h6 class="fw-900 mb-1">Upload Quotations</h6>
                                    <p class="small text-muted mb-0">Upload received quotations with details and documents.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-3">
                                <div class="stat-ic yellow" style="width: 40px; height: 40px; font-size: 16px;">
                                    <i class="bi bi-send"></i>
                                </div>
                                <div>
                                    <h6 class="fw-900 mb-1">Submit to Manager</h6>
                                    <p class="small text-muted mb-0">Once all quotations are collected, submit for review.</p>
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

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
    // Init DataTable ONLY on md+ screens
    function initInProgressTable() {
        const isDesktop = window.matchMedia('(min-width: 768px)').matches;
        const tbl = document.getElementById('inProgressTable');
        if (!tbl) return;

        if (isDesktop) {
            if (!$.fn.DataTable.isDataTable('#inProgressTable')) {
                $('#inProgressTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    scrollX: false,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                    order: [[4, 'asc']], // Sort by required date
                    columnDefs: [
                        { targets: [9], orderable: false, searchable: false } // Action column
                    ],
                    language: {
                        zeroRecords: "No in-progress quotations found",
                        info: "Showing _START_ to _END_ of _TOTAL_ requests",
                        infoEmpty: "No requests to show",
                        lengthMenu: "Show _MENU_",
                        search: "Search:"
                    }
                });

                setTimeout(function() {
                    $('.dataTables_filter input').focus();
                }, 400);
            }
        } else {
            if ($.fn.DataTable.isDataTable('#inProgressTable')) {
                $('#inProgressTable').DataTable().destroy();
            }
        }
    }

    $(function () {
        initInProgressTable();
        window.addEventListener('resize', initInProgressTable);
    });
</script>

</body>
</html>