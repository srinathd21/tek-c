<?php
// approved-quotations.php (Manager) — show ALL approved quotation requests
// Follows same UI template as my-manager-sites.php

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$requests = [];

// ---------- Auth (Manager only) ----------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Allow managers and directors
$allowed = [
  'manager',
  'director',
  'vice president',
  'general manager'
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

// ---------- Fetch all approved quotation requests ----------
$sql = "
  SELECT 
    qr.*,
    s.project_name,
    s.project_code,
    s.project_location,
    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    (
      SELECT COUNT(*) 
      FROM quotations q 
      WHERE q.quotation_request_id = qr.id
    ) AS quotation_count,
    (
      SELECT q.grand_total 
      FROM quotations q 
      WHERE q.quotation_request_id = qr.id 
        AND q.status = 'Approved'
      ORDER BY q.created_at DESC 
      LIMIT 1
    ) AS approved_amount,
    (
      SELECT q.finalized_amount 
      FROM quotations q 
      WHERE q.quotation_request_id = qr.id 
        AND q.status = 'Approved'
      ORDER BY q.created_at DESC 
      LIMIT 1
    ) AS finalized_amount,
    (
      SELECT q.quotation_no 
      FROM quotations q 
      WHERE q.quotation_request_id = qr.id 
        AND q.status = 'Approved'
      ORDER BY q.created_at DESC 
      LIMIT 1
    ) AS approved_quotation_no,
    (
      SELECT d.dealer_name 
      FROM quotations q 
      LEFT JOIN quotation_dealers d ON q.dealer_id = d.id
      WHERE q.quotation_request_id = qr.id 
        AND q.status = 'Approved'
      ORDER BY q.created_at DESC 
      LIMIT 1
    ) AS approved_dealer,
    (
      SELECT q.quotation_document 
      FROM quotations q 
      WHERE q.quotation_request_id = qr.id 
        AND q.status = 'Approved'
      ORDER BY q.created_at DESC 
      LIMIT 1
    ) AS quotation_document
  FROM quotation_requests qr
  JOIN sites s ON qr.site_id = s.id
  LEFT JOIN clients c ON s.client_id = c.id
  WHERE qr.requested_by = ? 
    AND qr.status = 'Approved'
  ORDER BY qr.manager_decision_at DESC, qr.updated_at DESC
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
$total_approved = count($requests);
$total_amount = 0;
$this_month_count = 0;
$this_month_amount = 0;
$current_month = date('m');
$current_year = date('Y');

foreach ($requests as $req) {
    $amount = $req['approved_amount'] ?? $req['finalized_amount'] ?? 0;
    $total_amount += floatval($amount);
    
    // Count this month's approvals
    $approval_date = $req['manager_decision_at'] ?? $req['updated_at'];
    if (!empty($approval_date)) {
        $month = date('m', strtotime($approval_date));
        $year = date('Y', strtotime($approval_date));
        if ($month == $current_month && $year == $current_year) {
            $this_month_count++;
            $this_month_amount += floatval($amount);
        }
    }
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
  <title>Approved Quotations - TEK-C</title>

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
    .btn-action.pdf{
      border-color: rgba(239,68,68,.25);
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
    th.actions-col, td.actions-col { width: 100px !important; }

    /* ---------- Mobile Cards ---------- */
    .approval-card{
      border:1px solid var(--border);
      border-radius: 16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding: 12px;
    }
    .approval-card .top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }
    .approval-card .title{
      font-weight:1000;
      color:#111827;
      font-size: 14px;
      line-height:1.2;
      margin:0;
    }
    .approval-card .meta{
      margin-top:6px;
      display:flex;
      flex-wrap:wrap;
      gap:8px 10px;
      color:#6b7280;
      font-weight:800;
      font-size:12px;
    }
    .approval-kv{ margin-top:10px; display:grid; gap:8px; }
    .approval-row{ display:flex; gap:10px; align-items:flex-start; }
    .approval-key{
      flex:0 0 85px;
      color:#6b7280;
      font-weight:1000;
      font-size:12px;
    }
    .approval-val{
      flex:1 1 auto;
      font-weight:900;
      color:#111827;
      font-size:12.5px;
      line-height:1.3;
      word-break: break-word;
    }
    .approval-actions{
      margin-top:12px;
      display:flex;
      gap:8px;
      justify-content:flex-end;
    }
    .approval-actions a{ 
      padding: 6px 12px; 
      border-radius:10px; 
      justify-content:center;
      white-space: nowrap;
    }
    .amount-badge{
      background: #e8f0fe;
      color: var(--blue);
      font-weight: 900;
      padding: 4px 8px;
      border-radius: 20px;
      font-size: 12px;
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
      .approval-actions { flex-wrap: wrap; }
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

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Approved Quotations</h1>
            <p class="text-muted mb-0">All quotation requests that have been approved</p>
          </div>
          <div class="d-flex gap-2">
            <a href="quotation-requests.php" class="btn btn-primary">
              <i class="bi bi-plus-circle"></i> New Request
            </a>
          </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
              <div>
                <div class="stat-label">Total Approved</div>
                <div class="stat-value"><?php echo (int)$total_approved; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic purple"><i class="bi bi-currency-rupee"></i></div>
              <div>
                <div class="stat-label">Total Value</div>
                <div class="stat-value"><?php echo formatCurrency($total_amount); ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-calendar-check"></i></div>
              <div>
                <div class="stat-label">This Month</div>
                <div class="stat-value"><?php echo (int)$this_month_count; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-graph-up"></i></div>
              <div>
                <div class="stat-label">Monthly Value</div>
                <div class="stat-value"><?php echo formatCurrency($this_month_amount); ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Directory -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">Approved Quotation Requests</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <!-- MOBILE: Cards -->
          <div class="d-block d-md-none">
            <div class="d-grid gap-3">
              <?php if (empty($requests)): ?>
                <div class="text-center py-4 text-muted">
                  <i class="bi bi-check-circle" style="font-size: 48px;"></i>
                  <p class="mt-2 fw-bold">No approved quotations yet</p>
                  <p class="small">When you approve quotation requests, they will appear here.</p>
                  <a href="quotation-requests.php" class="btn btn-primary btn-sm mt-2">
                    <i class="bi bi-plus-circle"></i> Create New Request
                  </a>
                </div>
              <?php else: ?>
                <?php foreach ($requests as $req): ?>
                  <div class="approval-card">
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

                    <div class="approval-kv">
                      <div class="approval-row">
                        <div class="approval-key">Request No.</div>
                        <div class="approval-val fw-800"><?php echo e($req['request_no']); ?></div>
                      </div>

                      <div class="approval-row">
                        <div class="approval-key">Approved On</div>
                        <div class="approval-val">
                          <?php echo safeDate($req['manager_decision_at'] ?? $req['updated_at']); ?>
                          <span class="proj-sub ms-1">(<?php echo getTimeAgo($req['manager_decision_at'] ?? $req['updated_at']); ?>)</span>
                        </div>
                      </div>

                      <?php if (!empty($req['approved_amount']) || !empty($req['finalized_amount'])): ?>
                      <div class="approval-row">
                        <div class="approval-key">Amount</div>
                        <div class="approval-val fw-800 text-success">
                          <?php echo formatCurrency($req['approved_amount'] ?? $req['finalized_amount']); ?>
                        </div>
                      </div>
                      <?php endif; ?>

                      <?php if (!empty($req['approved_dealer'])): ?>
                      <div class="approval-row">
                        <div class="approval-key">Dealer</div>
                        <div class="approval-val"><?php echo e($req['approved_dealer']); ?></div>
                      </div>
                      <?php endif; ?>

                      <?php if (!empty($req['quotation_count'])): ?>
                      <div class="approval-row">
                        <div class="approval-key">Quotations</div>
                        <div class="approval-val">
                          <span class="amount-badge">
                            <i class="bi bi-file-text"></i> <?php echo intval($req['quotation_count']); ?> total
                          </span>
                        </div>
                      </div>
                      <?php endif; ?>
                    </div>

                    <div class="approval-actions">
                      <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action" title="View Details">
                        <i class="bi bi-eye"></i> View
                      </a>
                      <?php if (!empty($req['quotation_document'])): ?>
                        <a href="<?php echo e($req['quotation_document']); ?>" target="_blank" class="btn-action pdf" title="Download PDF">
                          <i class="bi bi-file-pdf"></i> PDF
                        </a>
                      <?php endif; ?>
                      <a href="quotation-comparison.php?id=<?php echo $req['id']; ?>" class="btn-action" title="View Comparison">
                        <i class="bi bi-bar-chart"></i> Compare
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
              <table id="approvedQuotationsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                <thead>
                  <tr>
                    <th>Request No.</th>
                    <th>Title / Site</th>
                    <th>Type</th>
                    <th>Approved Dealer</th>
                    <th>Approved Amount</th>
                    <th>Approved On</th>
                    <th>Priority</th>
                    <th>Quotations</th>
                    <th class="text-end actions-col">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $req): ?>
                  <tr>
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
                      <?php if (!empty($req['client_name'])): ?>
                        <div class="proj-sub"><i class="bi bi-person"></i> <?php echo e($req['client_name']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?php echo e($req['quotation_type']); ?></td>
                    <td>
                      <?php if (!empty($req['approved_dealer'])): ?>
                        <div class="fw-700"><?php echo e($req['approved_dealer']); ?></div>
                        <div class="proj-sub"><?php echo e($req['approved_quotation_no'] ?? ''); ?></div>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="fw-800 text-success">
                        <?php echo formatCurrency($req['approved_amount'] ?? $req['finalized_amount']); ?>
                      </span>
                    </td>
                    <td>
                      <div class="fw-700"><?php echo safeDate($req['manager_decision_at'] ?? $req['updated_at']); ?></div>
                      <div class="proj-sub"><?php echo getTimeAgo($req['manager_decision_at'] ?? $req['updated_at']); ?></div>
                    </td>
                    <td><?php echo getPriorityBadge($req['priority']); ?></td>
                    <td class="text-center">
                      <span class="badge bg-info rounded-pill"><?php echo intval($req['quotation_count'] ?? 0); ?></span>
                    </td>
                    <td class="text-end actions-col">
                      <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action" title="View Details">
                        <i class="bi bi-eye"></i>
                      </a>
                      <?php if (!empty($req['quotation_document'])): ?>
                        <a href="<?php echo e($req['quotation_document']); ?>" target="_blank" class="btn-action pdf" title="Download PDF">
                          <i class="bi bi-file-pdf"></i>
                        </a>
                      <?php endif; ?>
                      <a href="quotation-comparison.php?id=<?php echo $req['id']; ?>" class="btn-action" title="View Comparison">
                        <i class="bi bi-bar-chart"></i>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- Export Options Panel (if there are approved quotations) -->
        <?php if (!empty($requests)): ?>
        <div class="panel">
          <div class="panel-header">
            <h3 class="panel-title">Export & Reports</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>
          <div class="row g-3">
            <div class="col-md-3">
              <a href="export-approved-quotations.php?format=csv" class="btn btn-outline-secondary w-100">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export as CSV
              </a>
            </div>
            <div class="col-md-3">
              <a href="export-approved-quotations.php?format=excel" class="btn btn-outline-secondary w-100">
                <i class="bi bi-file-earmark-excel"></i> Export as Excel
              </a>
            </div>
            <div class="col-md-3">
              <a href="export-approved-quotations.php?format=pdf" class="btn btn-outline-secondary w-100">
                <i class="bi bi-file-earmark-pdf"></i> Export as PDF
              </a>
            </div>
            <div class="col-md-3">
              <a href="approved-quotations-report.php" class="btn btn-outline-secondary w-100">
                <i class="bi bi-graph-up"></i> View Report
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>

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
  function initApprovedQuotationsTable() {
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    const tbl = document.getElementById('approvedQuotationsTable');
    if (!tbl) return;

    if (isDesktop) {
      if (!$.fn.DataTable.isDataTable('#approvedQuotationsTable')) {
        $('#approvedQuotationsTable').DataTable({
          responsive: true,
          autoWidth: false,
          scrollX: false,
          pageLength: 10,
          lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
          order: [[5, 'desc']], // Sort by approval date
          columnDefs: [
            { targets: [8], orderable: false, searchable: false } // Action column
          ],
          language: {
            zeroRecords: "No approved quotations found",
            info: "Showing _START_ to _END_ of _TOTAL_ approved quotations",
            infoEmpty: "No approved quotations to show",
            lengthMenu: "Show _MENU_",
            search: "Search:"
          }
        });

        setTimeout(function() {
          $('.dataTables_filter input').focus();
        }, 400);
      }
    } else {
      if ($.fn.DataTable.isDataTable('#approvedQuotationsTable')) {
        $('#approvedQuotationsTable').DataTable().destroy();
      }
    }
  }

  $(function () {
    initApprovedQuotationsTable();
    window.addEventListener('resize', initApprovedQuotationsTable);
  });
</script>

</body>
</html>