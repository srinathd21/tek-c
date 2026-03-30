<?php
// my-quotations-history.php (Team Lead) — show complete history of all quotation requests handled by TL
// Shows all statuses including completed, approved, rejected, etc.

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

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function formatCurrency($amount) {
    if ($amount === null || $amount == 0) return '—';
    return '₹ ' . number_format($amount, 2);
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

// ---------- Fetch all quotation requests handled by this TL ----------
$sql = "";
$params = [];
$types = "";

if (empty($tl_site_ids)) {
    $requests = [];
} else {
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
                FROM quotations q 
                WHERE q.quotation_request_id = qr.id
            ) AS quotations_count,
            (
                SELECT MIN(q.grand_total) 
                FROM quotations q 
                WHERE q.quotation_request_id = qr.id
            ) AS lowest_amount,
            (
                SELECT q.finalized_amount 
                FROM quotations q 
                WHERE q.quotation_request_id = qr.id 
                AND q.status IN ('Finalized', 'QS Negotiated')
                ORDER BY q.finalized_at DESC 
                LIMIT 1
            ) AS finalized_amount,
            (
                SELECT d.dealer_name 
                FROM quotations q 
                LEFT JOIN quotation_dealers d ON q.dealer_id = d.id
                WHERE q.quotation_request_id = qr.id 
                AND q.status IN ('Finalized', 'QS Negotiated')
                ORDER BY q.finalized_at DESC 
                LIMIT 1
            ) AS selected_dealer,
            (
                SELECT q.finalized_at 
                FROM quotations q 
                WHERE q.quotation_request_id = qr.id 
                AND q.status IN ('Finalized', 'QS Negotiated')
                ORDER BY q.finalized_at DESC 
                LIMIT 1
            ) AS finalized_at
        FROM quotation_requests qr
        JOIN sites s ON qr.site_id = s.id
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN employees m ON s.manager_employee_id = m.id
        WHERE (qr.project_engineer_id = ? OR qr.site_id IN ($placeholders))
        ORDER BY qr.created_at DESC
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
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
$total_history = count($requests);
$approved_count = 0;
$rejected_count = 0;
$cancelled_count = 0;
$completed_count = 0;
$total_quotations = 0;

foreach ($requests as $req) {
    if ($req['status'] === 'Approved') {
        $approved_count++;
        $completed_count++;
    } elseif ($req['status'] === 'QS Finalized') {
        $completed_count++;
    } elseif ($req['status'] === 'Rejected') {
        $rejected_count++;
    } elseif ($req['status'] === 'Cancelled') {
        $cancelled_count++;
    }
    
    $total_quotations += intval($req['quotations_count'] ?? 0);
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
    <title>My Quotations History - TEK-C</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        /* All styles same as before */
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
        .btn-action.view{ border-color: rgba(45,156,219,.25); }
        .btn-action.compare{ border-color: rgba(245,158,11,.25); }

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
        th.actions-col, td.actions-col { width: 100px !important; }

        /* Mobile cards styles */
        .history-card{
            border:1px solid var(--border);
            border-radius: 16px;
            background: var(--surface);
            box-shadow: var(--shadow);
            padding: 12px;
            position: relative;
        }
        .history-card.approved{ border-left: 4px solid #10b981; }
        .history-card.rejected{ border-left: 4px solid #ef4444; }
        .history-card.cancelled{ border-left: 4px solid #6b7280; }
        .history-card.completed{ border-left: 4px solid #10b981; }
        .history-card .top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
        .history-card .title{ font-weight:1000; color:#111827; font-size:14px; line-height:1.2; margin:0; }
        .history-card .meta{ margin-top:6px; display:flex; flex-wrap:wrap; gap:8px 10px; color:#6b7280; font-weight:800; font-size:12px; }
        .history-kv{ margin-top:10px; display:grid; gap:8px; }
        .history-row{ display:flex; gap:10px; align-items:flex-start; }
        .history-key{ flex:0 0 85px; color:#6b7280; font-weight:1000; font-size:12px; }
        .history-val{ flex:1; font-weight:900; color:#111827; font-size:12.5px; }
        .history-actions{ margin-top:12px; display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; }
        .stat-chip{ background:#f3f4f6; padding:4px 8px; border-radius:20px; font-size:11px; font-weight:700; color:#6b7280; }
        .stat-chip i{ margin-right:4px; }

        @media (max-width: 991.98px){
            .main{ margin-left:0; width:100%; max-width:100%; }
            .sidebar{ position:fixed; transform:translateX(-100%); z-index:1040; }
            .sidebar.open, .sidebar.active, .sidebar.show{ transform:translateX(0); }
        }
        @media (max-width: 768px){
            .content-scroll{ padding:12px 10px; }
            .container-fluid.maxw{ padding-left:6px; padding-right:6px; }
            .panel{ padding:12px; margin-bottom:12px; border-radius:14px; }
            .history-actions{ flex-wrap:wrap; }
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
                    <div class="alert alert-<?php echo $status === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
                        <i class="bi bi-<?php echo $status === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">My Quotations History</h1>
                        <p class="text-muted mb-0">Complete history of all quotation requests handled by you</p>
                    </div>
                    <div>
                        <a href="assigned-quotations.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card"><div class="stat-ic blue"><i class="bi bi-clock-history"></i></div><div><div class="stat-label">Total History</div><div class="stat-value"><?php echo (int)$total_history; ?></div></div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card"><div class="stat-ic green"><i class="bi bi-check-circle"></i></div><div><div class="stat-label">Approved</div><div class="stat-value"><?php echo (int)$approved_count; ?></div></div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card"><div class="stat-ic red"><i class="bi bi-x-circle"></i></div><div><div class="stat-label">Rejected</div><div class="stat-value"><?php echo (int)$rejected_count; ?></div></div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card"><div class="stat-ic purple"><i class="bi bi-file-text"></i></div><div><div class="stat-label">Total Quotations</div><div class="stat-value"><?php echo (int)$total_quotations; ?></div></div></div>
                    </div>
                </div>

                <!-- Info Banner -->
                <?php if (empty($requests)): ?>
                <div class="alert alert-info alert-dismissible fade show mb-3">
                    <i class="bi bi-info-circle-fill me-2"></i> No quotation history found. Start working on assigned quotations to build your history.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Directory -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">Quotation History</h3>
                        <button class="panel-menu"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <!-- MOBILE: Cards -->
                    <div class="d-block d-md-none">
                        <div class="d-grid gap-3">
                            <?php if (empty($requests)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-clock-history" style="font-size:48px;"></i>
                                    <p class="mt-2 fw-bold">No history found</p>
                                    <p class="small">Completed quotation requests will appear here.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($requests as $req): 
                                    $cardClass = '';
                                    if ($req['status'] === 'Approved') $cardClass = 'approved';
                                    elseif ($req['status'] === 'Rejected') $cardClass = 'rejected';
                                    elseif ($req['status'] === 'Cancelled') $cardClass = 'cancelled';
                                    elseif (in_array($req['status'], ['QS Finalized', 'Completed'])) $cardClass = 'completed';
                                ?>
                                    <div class="history-card <?php echo $cardClass; ?>">
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

                                        <div class="progress-stats mb-2">
                                            <span class="stat-chip"><i class="bi bi-file-text"></i> <?php echo intval($req['quotations_count'] ?? 0); ?> Quotations</span>
                                            <?php if (!empty($req['finalized_amount'])): ?>
                                                <span class="stat-chip"><i class="bi bi-currency-rupee"></i> <?php echo formatCurrency($req['finalized_amount']); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="history-kv">
                                            <div class="history-row"><div class="history-key">Request No.</div><div class="history-val"><?php echo e($req['request_no']); ?></div></div>
                                            <div class="history-row"><div class="history-key">Created</div><div class="history-val"><?php echo safeDate($req['created_at']); ?></div></div>
                                            <?php if (!empty($req['finalized_at'])): ?>
                                            <div class="history-row"><div class="history-key">Completed</div><div class="history-val"><?php echo safeDate($req['finalized_at']); ?></div></div>
                                            <?php endif; ?>
                                            <div class="history-row"><div class="history-key">Status</div><div class="history-val"><?php echo getStatusBadge($req['status']); ?></div></div>
                                            <?php if (!empty($req['selected_dealer'])): ?>
                                            <div class="history-row"><div class="history-key">Selected Dealer</div><div class="history-val"><?php echo e($req['selected_dealer']); ?></div></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="history-actions">
                                            <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view"><i class="bi bi-eye"></i> View</a>
                                            <a href="quotation-comparison.php?id=<?php echo $req['id']; ?>" class="btn-action compare"><i class="bi bi-bar-chart"></i> Compare</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- DESKTOP/TABLET: DataTable -->
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table id="historyTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Request No.</th>
                                        <th>Title / Site</th>
                                        <th>Type</th>
                                        <th>Quotes</th>
                                        <th>Finalized Amount</th>
                                        <th>Selected Dealer</th>
                                        <th>Created</th>
                                        <th>Completed</th>
                                        <th>Status</th>
                                        <th>Priority</th>
                                        <th class="text-end actions-col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($requests as $req): ?>
                                    <tr class="<?php echo $req['priority'] === 'Urgent' ? 'table-danger' : ($req['priority'] === 'High' ? 'table-warning' : ''); ?>">
                                        <td><span class="fw-800"><?php echo e($req['request_no']); ?></span></td>
                                        <td>
                                            <div class="proj-title"><?php echo e($req['title']); ?></div>
                                            <div class="proj-sub"><i class="bi bi-building"></i> <?php echo e($req['project_name']); ?><?php if (!empty($req['project_code'])): ?> (<?php echo e($req['project_code']); ?>)<?php endif; ?></div>
                                        </td>
                                        <td><?php echo e($req['quotation_type']); ?></td>
                                        <td class="text-center"><span class="badge bg-info rounded-pill"><?php echo intval($req['quotations_count'] ?? 0); ?></span></td>
                                        <td><?php if (!empty($req['finalized_amount'])): ?><span class="fw-700 text-success"><?php echo formatCurrency($req['finalized_amount']); ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                        <td><?php if (!empty($req['selected_dealer'])): ?><div class="fw-700"><?php echo e($req['selected_dealer']); ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                        <td>
                                            <div class="fw-700"><?php echo safeDate($req['created_at']); ?></div>
                                            <div class="proj-sub"><?php echo getTimeAgo($req['created_at']); ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($req['finalized_at'])): ?>
                                                <div class="fw-700"><?php echo safeDate($req['finalized_at']); ?></div>
                                                <div class="proj-sub"><?php echo getTimeAgo($req['finalized_at']); ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo getStatusBadge($req['status']); ?></td>
                                        <td><?php echo getPriorityBadge($req['priority']); ?></td>
                                        <td class="text-end actions-col">
                                            <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view" title="View Details"><i class="bi bi-eye"></i></a>
                                            <a href="quotation-comparison.php?id=<?php echo $req['id']; ?>" class="btn-action compare" title="Compare Quotations"><i class="bi bi-bar-chart"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Performance Summary Panel -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Performance Summary</h3>
                        <button class="panel-menu"><i class="bi bi-three-dots"></i></button>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="fw-900 text-muted small">Success Rate</div>
                                <div class="fw-900 fs-2 text-success">
                                    <?php 
                                    $success_rate = $total_history > 0 ? round(($approved_count / $total_history) * 100, 1) : 0;
                                    echo $success_rate . '%';
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="fw-900 text-muted small">Avg Quotations/Request</div>
                                <div class="fw-900 fs-2 text-primary">
                                    <?php echo $total_history > 0 ? number_format($total_quotations / $total_history, 1) : '0'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="fw-900 text-muted small">Completed Requests</div>
                                <div class="fw-900 fs-2 text-success"><?php echo (int)$completed_count; ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="fw-900 text-muted small">Total Requests</div>
                                <div class="fw-900 fs-2 text-primary"><?php echo (int)$total_history; ?></div>
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
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    function initHistoryTable() {
        const isDesktop = window.matchMedia('(min-width: 768px)').matches;
        const tbl = document.getElementById('historyTable');
        if (!tbl) return;

        if (isDesktop) {
            if (!$.fn.DataTable.isDataTable('#historyTable')) {
                $('#historyTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                    order: [[6, 'desc']], // Created column (index 6)
                    columnDefs: [{ targets: [10], orderable: false, searchable: false }],
                    language: {
                        zeroRecords: "No history found",
                        info: "Showing _START_ to _END_ of _TOTAL_ requests",
                        infoEmpty: "No requests to show",
                        lengthMenu: "Show _MENU_",
                        search: "Search:"
                    }
                });
            }
        } else {
            if ($.fn.DataTable.isDataTable('#historyTable')) {
                $('#historyTable').DataTable().destroy();
            }
        }
    }

    $(function () {
        initHistoryTable();
        window.addEventListener('resize', initHistoryTable);
    });
</script>
</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>