<?php
// all-quotation-requests.php – Manager/Admin view of all quotation requests with advanced filters

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$requests = [];

// ---------- Auth: Only Managers and Admins ----------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$department = strtolower(trim((string)($_SESSION['department'] ?? '')));

$allowed = [
    'manager', 'director', 'vice president', 'general manager',
    'admin', 'administrator', 'accounts', 'hr'
];
if (!in_array($designation, $allowed, true) && $department !== 'accounts' && $department !== 'hr') {
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

// ---------- Fetch all quotation requests (no filtering by site/user) ----------
$sql = "
    SELECT 
        qr.*,
        s.project_name,
        s.project_code,
        s.project_location,
        c.client_name,
        c.company_name,
        m.full_name AS manager_name,
        m.employee_code AS manager_code,
        tl.full_name AS team_lead_name,
        pe.full_name AS project_engineer_name,
        qs_emp.full_name AS qs_employee_name,
        DATEDIFF(qr.required_by_date, CURDATE()) AS days_remaining,
        (SELECT COUNT(*) FROM quotations WHERE quotation_request_id = qr.id) AS quotations_count,
        (SELECT MIN(grand_total) FROM quotations WHERE quotation_request_id = qr.id) AS lowest_amount,
        (SELECT finalized_amount FROM quotations WHERE quotation_request_id = qr.id AND status = 'Finalized' ORDER BY finalized_at DESC LIMIT 1) AS finalized_amount,
        (SELECT dealer_name FROM quotations q LEFT JOIN quotation_dealers d ON q.dealer_id = d.id WHERE q.quotation_request_id = qr.id AND q.status = 'Finalized' ORDER BY q.finalized_at DESC LIMIT 1) AS selected_dealer,
        (SELECT finalized_at FROM quotations WHERE quotation_request_id = qr.id AND status = 'Finalized' ORDER BY finalized_at DESC LIMIT 1) AS finalized_at
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN employees m ON s.manager_employee_id = m.id
    LEFT JOIN employees tl ON s.team_lead_employee_id = tl.id
    LEFT JOIN employees pe ON qr.project_engineer_id = pe.id
    LEFT JOIN employees qs_emp ON qr.qs_employee_id = qs_emp.id
    ORDER BY qr.created_at DESC
";

$res = mysqli_query($conn, $sql);
if ($res) {
    $requests = mysqli_fetch_all($res, MYSQLI_ASSOC);
} else {
    $error = "Database error: " . mysqli_error($conn);
}

// ---------- Stats ----------
$total_requests = count($requests);
$pending_assignment = 0;
$assigned = 0;
$with_qs = 0;
$qs_finalized = 0;
$approved = 0;
$rejected = 0;
$urgent = 0;
$high = 0;

foreach ($requests as $req) {
    switch ($req['status']) {
        case 'Pending Assignment': $pending_assignment++; break;
        case 'Assigned': $assigned++; break;
        case 'With QS': $with_qs++; break;
        case 'QS Finalized': $qs_finalized++; break;
        case 'Approved': $approved++; break;
        case 'Rejected': $rejected++; break;
    }
    if ($req['priority'] === 'Urgent') $urgent++;
    if ($req['priority'] === 'High') $high++;
}

// Collect unique site names for filter dropdown
$site_names = array_unique(array_column($requests, 'project_name'));
sort($site_names);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>All Quotation Requests - TEK-C</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- Flatpickr for date range -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        /* Same styles as previous, plus filter panel */
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

        .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
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
        .table thead th{ font-size:11px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; padding:10px 10px !important; white-space:normal !important; }
        .table td{ vertical-align:middle; border-color:var(--border); font-weight:650; color:#374151; padding:10px 10px !important; white-space:normal !important; word-break:break-word; }

        .btn-action { background:transparent; border:1px solid var(--border); border-radius:10px; padding:7px 10px; color:var(--muted); font-size:12px; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; font-weight:900; }
        .btn-action:hover { background:var(--bg); color:var(--blue); }
        .btn-action.view{ border-color:rgba(45,156,219,.25); }

        .proj-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
        .proj-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.25; }

        .alert { border-radius:var(--radius); border:none; box-shadow:var(--shadow); margin-bottom:20px; }

        div.dataTables_wrapper .dataTables_length select, div.dataTables_wrapper .dataTables_filter input{ border:1px solid var(--border); border-radius:10px; padding:7px 10px; font-weight:650; outline:none; }
        div.dataTables_wrapper .dataTables_filter input:focus{ border-color:var(--blue); box-shadow:0 0 0 3px rgba(45,156,219,0.1); }
        .dataTables_paginate .pagination .page-link{ border-radius:10px; margin:0 3px; font-weight:750; }
        th.actions-col, td.actions-col { width:60px !important; }

        /* Mobile Cards */
        .request-card{ border:1px solid var(--border); border-radius:16px; background:var(--surface); box-shadow:var(--shadow); padding:12px; position:relative; }
        .request-card .top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
        .request-card .title{ font-weight:1000; color:#111827; font-size:14px; line-height:1.2; margin:0; }
        .request-card .meta{ margin-top:6px; display:flex; flex-wrap:wrap; gap:8px 10px; color:#6b7280; font-weight:800; font-size:12px; }
        .request-kv{ margin-top:10px; display:grid; gap:8px; }
        .request-row{ display:flex; gap:10px; align-items:flex-start; }
        .request-key{ flex:0 0 85px; color:#6b7280; font-weight:1000; font-size:12px; }
        .request-val{ flex:1; font-weight:900; color:#111827; font-size:12.5px; }
        .request-actions{ margin-top:12px; display:flex; gap:8px; justify-content:flex-end; }
        .stat-chip{ background:#f3f4f6; padding:4px 8px; border-radius:20px; font-size:11px; font-weight:700; color:#6b7280; }

        .filter-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 20px;
        }
        .filter-panel .form-label {
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 4px;
            color: #6b7280;
        }
        .filter-panel select, .filter-panel input {
            font-size: 13px;
            font-weight: 600;
        }
        .filter-panel .btn-filter {
            margin-top: 28px;
        }

        @media (max-width:991.98px){ .main{ margin-left:0; width:100%; max-width:100%; } .sidebar{ position:fixed; transform:translateX(-100%); z-index:1040; } .sidebar.open, .sidebar.active, .sidebar.show{ transform:translateX(0); } }
        @media (max-width:768px){ .content-scroll{ padding:12px 10px; } .container-fluid.maxw{ padding-left:6px; padding-right:6px; } .panel{ padding:12px; margin-bottom:12px; border-radius:14px; } .filter-panel .btn-filter{ margin-top:8px; } }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid maxw">

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">All Quotation Requests</h1>
                        <p class="text-muted mb-0">Complete overview of all quotation requests across the organization</p>
                    </div>
                    <div>
                        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
                    </div>
                </div>

                <!-- Stats Row -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card"><div class="stat-ic blue"><i class="bi bi-file-text"></i></div><div><div class="stat-label">Total Requests</div><div class="stat-value"><?php echo $total_requests; ?></div></div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card"><div class="stat-ic yellow"><i class="bi bi-clock"></i></div><div><div class="stat-label">Pending Assignment</div><div class="stat-value"><?php echo $pending_assignment; ?></div></div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card"><div class="stat-ic orange"><i class="bi bi-person-check"></i></div><div><div class="stat-label">With QS</div><div class="stat-value"><?php echo $with_qs; ?></div></div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card"><div class="stat-ic green"><i class="bi bi-check-circle"></i></div><div><div class="stat-label">Finalized / Approved</div><div class="stat-value"><?php echo ($qs_finalized + $approved); ?></div></div></div>
                    </div>
                </div>

                <!-- Secondary Stats -->
                <div class="row g-3 mb-3">
                    <div class="col-md-3"><div class="stat-card"><div class="stat-ic red"><i class="bi bi-exclamation-triangle"></i></div><div><div class="stat-label">Urgent</div><div class="stat-value"><?php echo $urgent; ?></div></div></div></div>
                    <div class="col-md-3"><div class="stat-card"><div class="stat-ic orange"><i class="bi bi-arrow-up"></i></div><div><div class="stat-label">High Priority</div><div class="stat-value"><?php echo $high; ?></div></div></div></div>
                    <div class="col-md-3"><div class="stat-card"><div class="stat-ic purple"><i class="bi bi-person-check"></i></div><div><div class="stat-label">Assigned to TL</div><div class="stat-value"><?php echo $assigned; ?></div></div></div></div>
                    <div class="col-md-3"><div class="stat-card"><div class="stat-ic" style="background:#ef4444;"><i class="bi bi-x-circle"></i></div><div><div class="stat-label">Rejected/Cancelled</div><div class="stat-value"><?php echo ($rejected); ?></div></div></div></div>
                </div>

                <!-- Filter Panel -->
                <div class="filter-panel">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select id="filterStatus" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="Pending Assignment">Pending Assignment</option>
                                <option value="Assigned">Assigned</option>
                                <option value="With QS">With QS</option>
                                <option value="QS Finalized">QS Finalized</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Priority</label>
                            <select id="filterPriority" class="form-select">
                                <option value="">All Priorities</option>
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                                <option value="Urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Site / Project</label>
                            <select id="filterSite" class="form-select">
                                <option value="">All Sites</option>
                                <?php foreach ($site_names as $site): ?>
                                    <option value="<?php echo e($site); ?>"><?php echo e($site); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Created From</label>
                            <input type="text" id="filterDateFrom" class="form-control" placeholder="DD/MM/YYYY">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Created To</label>
                            <input type="text" id="filterDateTo" class="form-control" placeholder="DD/MM/YYYY">
                        </div>
                        <div class="col-md-1">
                            <button id="resetFilters" class="btn btn-secondary w-100 btn-filter">Reset</button>
                        </div>
                    </div>
                </div>

                <!-- Directory -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">Quotation Requests</h3>
                        <button class="panel-menu"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <!-- MOBILE: Cards -->
                    <div class="d-block d-md-none">
                        <div id="mobileCardsContainer" class="d-grid gap-3">
                            <!-- dynamically populated via JS or fallback to full list with filters -->
                        </div>
                    </div>

                    <!-- DESKTOP: DataTable -->
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table id="allQuotationsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Request No.</th>
                                        <th>Title / Site</th>
                                        <th>Type</th>
                                        <th>Manager</th>
                                        <th>Team Lead</th>
                                        <th>Assigned TL</th>
                                        <th>QS</th>
                                        <th>Quotes</th>
                                        <th>Finalized Amount</th>
                                        <th>Status</th>
                                        <th>Priority</th>
                                        <th>Created</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($requests as $req): ?>
                                    <tr data-status="<?php echo e($req['status']); ?>" data-priority="<?php echo e($req['priority']); ?>" data-site="<?php echo e($req['project_name']); ?>" data-created="<?php echo e($req['created_at']); ?>">
                                        <td><span class="fw-800"><?php echo e($req['request_no']); ?></span></td>
                                        <td>
                                            <div class="proj-title"><?php echo e($req['title']); ?></div>
                                            <div class="proj-sub"><i class="bi bi-building"></i> <?php echo e($req['project_name']); ?><?php if (!empty($req['project_code'])): ?> (<?php echo e($req['project_code']); ?>)<?php endif; ?></div>
                                        </td>
                                        <td><?php echo e($req['quotation_type']); ?></td>
                                        <td><?php echo e($req['manager_name'] ?? '—'); ?></td>
                                        <td><?php echo e($req['team_lead_name'] ?? '—'); ?></td>
                                        <td><?php echo e($req['project_engineer_name'] ?? '—'); ?></td>
                                        <td><?php echo e($req['qs_employee_name'] ?? '—'); ?></td>
                                        <td class="text-center"><span class="badge bg-info"><?php echo intval($req['quotations_count'] ?? 0); ?></span></td>
                                        <td>
                                            <?php if (!empty($req['finalized_amount'])): ?>
                                                <span class="fw-700 text-success"><?php echo formatCurrency($req['finalized_amount']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo getStatusBadge($req['status']); ?></td>
                                        <td><?php echo getPriorityBadge($req['priority']); ?></td>
                                        <td><?php echo safeDate($req['created_at']); ?></td>
                                        <td class="text-end">
                                            <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view" title="View Details"><i class="bi bi-eye"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
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
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    let dataTable = null;

    function initAllQuotationsTable() {
        const isDesktop = window.matchMedia('(min-width: 768px)').matches;
        const tbl = document.getElementById('allQuotationsTable');
        if (!tbl) return;

        if (isDesktop) {
            if (!$.fn.DataTable.isDataTable('#allQuotationsTable')) {
                dataTable = $('#allQuotationsTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                    order: [[11, 'desc']], // Created date column
                    columnDefs: [{ targets: [12], orderable: false, searchable: false }],
                    language: {
                        zeroRecords: "No quotation requests found",
                        info: "Showing _START_ to _END_ of _TOTAL_ requests",
                        infoEmpty: "No requests to show",
                        lengthMenu: "Show _MENU_",
                        search: "Search:"
                    }
                });
            } else {
                dataTable = $('#allQuotationsTable').DataTable();
            }
        } else {
            if ($.fn.DataTable.isDataTable('#allQuotationsTable')) {
                dataTable = $('#allQuotationsTable').DataTable();
                dataTable.destroy();
                dataTable = null;
            }
            // Mobile: we'll repopulate cards based on filters instead of using DataTable
            renderMobileCards();
        }
    }

    function renderMobileCards() {
        const container = document.getElementById('mobileCardsContainer');
        if (!container) return;

        // Get all rows data (from original PHP data via inline JavaScript or we can re-fetch from hidden JSON)
        // For simplicity, we'll pass PHP data to JS as a variable
        const allData = <?php echo json_encode($requests); ?>;
        
        // Apply filters
        const statusFilter = document.getElementById('filterStatus').value;
        const priorityFilter = document.getElementById('filterPriority').value;
        const siteFilter = document.getElementById('filterSite').value;
        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo = document.getElementById('filterDateTo').value;

        let filtered = allData;
        if (statusFilter) filtered = filtered.filter(r => r.status === statusFilter);
        if (priorityFilter) filtered = filtered.filter(r => r.priority === priorityFilter);
        if (siteFilter) filtered = filtered.filter(r => r.project_name === siteFilter);
        if (dateFrom) {
            const from = new Date(dateFrom.split('/').reverse().join('-'));
            filtered = filtered.filter(r => new Date(r.created_at) >= from);
        }
        if (dateTo) {
            const to = new Date(dateTo.split('/').reverse().join('-'));
            filtered = filtered.filter(r => new Date(r.created_at) <= to);
        }

        if (filtered.length === 0) {
            container.innerHTML = '<div class="text-center py-4 text-muted"><i class="bi bi-inbox" style="font-size:48px;"></i><p class="mt-2 fw-bold">No requests match your filters.</p></div>';
            return;
        }

        let html = '';
        filtered.forEach(req => {
            const priorityClass = req.priority === 'Urgent' ? 'bg-danger' : (req.priority === 'High' ? 'bg-warning' : 'bg-info');
            html += `
                <div class="request-card">
                    <div class="top">
                        <div style="flex:1">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <h4 class="title">${escapeHtml(req.title)}</h4>
                                <span class="badge ${priorityClass}">${escapeHtml(req.priority)}</span>
                            </div>
                            <div class="meta">
                                <span><i class="bi bi-building"></i> ${escapeHtml(req.project_name)}</span>
                                <span><i class="bi bi-tag"></i> ${escapeHtml(req.quotation_type)}</span>
                            </div>
                        </div>
                    </div>
                    <div class="request-kv">
                        <div class="request-row"><div class="request-key">Request No.</div><div class="request-val">${escapeHtml(req.request_no)}</div></div>
                        <div class="request-row"><div class="request-key">Manager</div><div class="request-val">${escapeHtml(req.manager_name || '—')}</div></div>
                        <div class="request-row"><div class="request-key">Team Lead</div><div class="request-val">${escapeHtml(req.team_lead_name || '—')}</div></div>
                        <div class="request-row"><div class="request-key">Assigned TL</div><div class="request-val">${escapeHtml(req.project_engineer_name || '—')}</div></div>
                        <div class="request-row"><div class="request-key">QS</div><div class="request-val">${escapeHtml(req.qs_employee_name || '—')}</div></div>
                        <div class="request-row"><div class="request-key">Status</div><div class="request-val">${getStatusBadgeHtml(req.status)}</div></div>
                        <div class="request-row"><div class="request-key">Created</div><div class="request-val">${escapeHtml(req.created_at ? new Date(req.created_at).toLocaleDateString('en-GB') : '—')}</div></div>
                    </div>
                    <div class="request-actions">
                        <a href="view-quotation-request.php?id=${req.id}" class="btn-action view"><i class="bi bi-eye"></i> View</a>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    function getStatusBadgeHtml(status) {
        const badges = {
            'Draft': 'bg-secondary',
            'Pending Assignment': 'bg-warning',
            'Assigned': 'bg-info',
            'Quotations Received': 'bg-primary',
            'With QS': 'bg-secondary',
            'QS Finalized': 'bg-success',
            'Approved': 'bg-success',
            'Rejected': 'bg-danger',
            'Cancelled': 'bg-dark'
        };
        const cls = badges[status] || 'bg-secondary';
        return `<span class="badge ${cls}">${escapeHtml(status)}</span>`;
    }

    function applyFilters() {
        const status = document.getElementById('filterStatus').value;
        const priority = document.getElementById('filterPriority').value;
        const site = document.getElementById('filterSite').value;
        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo = document.getElementById('filterDateTo').value;

        if (window.matchMedia('(min-width: 768px)').matches && dataTable) {
            // Apply custom search on columns
            dataTable.column(9).search(status).draw(); // Status column (index 9)
            dataTable.column(10).search(priority).draw(); // Priority column (index 10)
            dataTable.column(1).search(site).draw(); // Title/Site column (index 1) – contains project name
            // Date range – custom search on column 11 (Created)
            if (dateFrom || dateTo) {
                dataTable.column(11).search(function(settings, data, dataIndex) {
                    const cellDate = data[11]; // raw created date string
                    if (!cellDate) return false;
                    const created = new Date(cellDate);
                    let ok = true;
                    if (dateFrom) {
                        const from = new Date(dateFrom.split('/').reverse().join('-'));
                        if (created < from) ok = false;
                    }
                    if (dateTo && ok) {
                        const to = new Date(dateTo.split('/').reverse().join('-'));
                        // Add one day to include the end date
                        to.setDate(to.getDate() + 1);
                        if (created > to) ok = false;
                    }
                    return ok;
                }, false).draw();
            } else {
                dataTable.column(11).search('').draw();
            }
        } else {
            renderMobileCards();
        }
    }

    function resetFilters() {
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterPriority').value = '';
        document.getElementById('filterSite').value = '';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        applyFilters();
    }

    $(function () {
        // Initialize flatpickr for date inputs
        flatpickr("#filterDateFrom", { dateFormat: "d/m/Y", allowInput: true });
        flatpickr("#filterDateTo", { dateFormat: "d/m/Y", allowInput: true });

        // Event listeners
        $('#filterStatus, #filterPriority, #filterSite, #filterDateFrom, #filterDateTo').on('change', applyFilters);
        $('#resetFilters').on('click', resetFilters);

        initAllQuotationsTable();
        window.addEventListener('resize', function() {
            initAllQuotationsTable();
            if (!window.matchMedia('(min-width: 768px)').matches) {
                renderMobileCards();
            }
        });
    });
</script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    @mysqli_close($conn);
}
?>