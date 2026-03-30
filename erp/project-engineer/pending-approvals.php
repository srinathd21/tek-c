<?php
// pending-approvals.php — show quotation requests pending approval
// Shows based on user's role and request status

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

// ---------- Helper Functions ----------
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

// Determine user role type
$isManager = in_array($designation, ['manager', 'director', 'vice president', 'general manager'], true);
$isQS = in_array($designation, ['qs manager', 'qs engineer', 'quantity surveyor'], true) || 
        (isset($_SESSION['department']) && strtolower($_SESSION['department']) === 'qs');
$isPEorTL = in_array($designation, ['project engineer grade 1', 'project engineer grade 2', 'sr. engineer', 'senior engineer', 'team lead', 'teamleader'], true);

// ============================================================
// FETCH QUOTATION REQUESTS BASED ON USER'S ROLE AND STATUS
// ============================================================
$sql = "
  SELECT 
    qr.*,
    s.project_name,
    s.project_code,
    s.project_location,
    s.manager_employee_id,
    s.team_lead_employee_id,
    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    e.full_name AS requested_by_employee_name,
    m.full_name AS manager_name,
    tl.full_name AS team_lead_name,
    GROUP_CONCAT(DISTINCT pe.full_name ORDER BY pe.full_name SEPARATOR ', ') AS project_engineers
  FROM quotation_requests qr
  JOIN sites s ON qr.site_id = s.id
  LEFT JOIN clients c ON s.client_id = c.id
  LEFT JOIN employees e ON qr.requested_by = e.id
  LEFT JOIN employees m ON s.manager_employee_id = m.id
  LEFT JOIN employees tl ON s.team_lead_employee_id = tl.id
  LEFT JOIN site_project_engineers spe ON spe.site_id = s.id
  LEFT JOIN employees pe ON pe.id = spe.employee_id
  WHERE 1=1 ";

// Add role-based status filtering
if ($isManager) {
    // Managers: see requests that are ready for approval (QS Finalized)
    $sql .= " AND qr.status = 'QS Finalized' ";
    $sql .= " AND (s.manager_employee_id = ? OR s.team_lead_employee_id = ?) ";
    $roleDesc = "ready for your approval";
} elseif ($isQS) {
    // QS: see requests that are with QS for negotiation
    $sql .= " AND qr.status = 'With QS' ";
    $sql .= " AND (s.manager_employee_id = ? OR s.team_lead_employee_id = ?) ";
    $roleDesc = "awaiting your review";
} elseif ($isPEorTL) {
    // Project Engineers/Team Leads: see requests assigned to them that need action
    $sql .= " AND (qr.status = 'Assigned' OR qr.status = 'Quotations Received') ";
    $sql .= " AND qr.project_engineer_id = ? ";
    $roleDesc = "assigned to you for action";
} else {
    // Other employees: see only their own requests that are pending
    $sql .= " AND (qr.status = 'Pending Assignment' OR qr.status = 'Assigned') ";
    $sql .= " AND qr.requested_by = ? ";
    $roleDesc = "you created (pending action)";
}

$sql .= " GROUP BY qr.id
  ORDER BY 
    FIELD(qr.priority, 'Urgent', 'High', 'Medium', 'Low'),
    qr.updated_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
  $error = "Database error: " . mysqli_error($conn);
} else {
    // Bind parameters based on role
    if ($isManager || $isQS) {
        mysqli_stmt_bind_param($stmt, "ii", $empId, $empId);
    } elseif ($isPEorTL) {
        mysqli_stmt_bind_param($stmt, "i", $empId);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $empId);
    }
    
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $requests = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

// ---------- Stats ----------
$total_pending = count($requests);
$urgent_count = 0;
$high_count = 0;

foreach ($requests as $req) {
    if ($req['priority'] === 'Urgent') $urgent_count++;
    if ($req['priority'] === 'High') $high_count++;
}

// Get user role display
function getUserRoleDisplay($designation) {
    $roles = [
        'manager' => 'Manager',
        'director' => 'Director',
        'vice president' => 'VP',
        'general manager' => 'GM',
        'project engineer grade 1' => 'Project Engineer',
        'project engineer grade 2' => 'Project Engineer',
        'sr. engineer' => 'Senior Engineer',
        'senior engineer' => 'Senior Engineer',
        'team lead' => 'Team Lead',
        'teamleader' => 'Team Lead'
    ];
    
    // Check for QS roles
    if (strpos($designation, 'qs') !== false) {
        return 'QS';
    }
    
    return $roles[$designation] ?? 'Employee';
}

$userRoleDisplay = getUserRoleDisplay($designation);

// Get status message if any
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Pending Approvals - TEK-C</title>

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
    .btn-action.approve{
      border-color: rgba(16,185,129,.25);
    }
    .btn-action.reject{
      border-color: rgba(239,68,68,.25);
    }
    .btn-action.view{
      border-color: rgba(45,156,219,.25);
    }
    .btn-action.review{
      border-color: rgba(245,158,11,.25);
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
    th.actions-col, td.actions-col { width: 120px !important; }

    /* Role badge */
    .role-badge {
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 20px;
      background: #f3f4f6;
      color: #6b7280;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    /* ---------- Mobile Cards ---------- */
    .approval-card{
      border:1px solid var(--border);
      border-radius: 16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding: 12px;
      position: relative;
    }
    .approval-card.urgent{
      border-left: 4px solid #dc2626;
    }
    .approval-card.high{
      border-left: 4px solid #f59e0b;
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
      flex:0 0 90px;
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
    .approval-actions a, .approval-actions button{ 
      padding: 6px 12px; 
      border-radius:10px; 
      justify-content:center;
      white-space: nowrap;
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
            <h1 class="h3 fw-bold text-dark mb-1">Pending Actions</h1>
            <p class="text-muted mb-0">
              <i class="bi bi-person-badge me-1"></i> Your role: 
              <span class="role-badge">
                <i class="bi bi-clock-history"></i>
                <?php echo $userRoleDisplay; ?>
              </span>
              <span class="ms-2 text-muted small">Requests <?php echo $roleDesc; ?></span>
            </p>
          </div>
          <?php if ($isPEorTL || $isManager || $isQS): ?>
          <div>
            <a href="quotation-requests.php" class="btn btn-primary">
              <i class="bi bi-plus-circle"></i> New Request
            </a>
          </div>
          <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-clock-history"></i></div>
              <div>
                <div class="stat-label">Pending Actions</div>
                <div class="stat-value"><?php echo (int)$total_pending; ?></div>
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
              <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
              <div>
                <div class="stat-label">To Review</div>
                <div class="stat-value"><?php echo (int)$total_pending; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Directory -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">Requests Needing Action</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <!-- MOBILE: Cards -->
          <div class="d-block d-md-none">
            <div class="d-grid gap-3">
              <?php if (empty($requests)): ?>
                <div class="text-center py-4 text-muted">
                  <i class="bi bi-check2-circle" style="font-size: 48px;"></i>
                  <p class="mt-2 fw-bold">No pending actions</p>
                  <p class="small">All caught up! No requests need your attention.</p>
                </div>
              <?php else: ?>
                <?php foreach ($requests as $req): 
                  $cardClass = '';
                  if ($req['priority'] === 'Urgent') $cardClass = 'urgent';
                  elseif ($req['priority'] === 'High') $cardClass = 'high';
                ?>
                  <div class="approval-card <?php echo $cardClass; ?>">
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
                          <span><?php echo getStatusBadge($req['status']); ?></span>
                        </div>
                      </div>
                    </div>

                    <div class="approval-kv">
                      <div class="approval-row">
                        <div class="approval-key">Request No.</div>
                        <div class="approval-val fw-800"><?php echo e($req['request_no']); ?></div>
                      </div>

                      <div class="approval-row">
                        <div class="approval-key">Requested By</div>
                        <div class="approval-val"><?php echo e($req['requested_by_employee_name'] ?? $req['requested_by_name']); ?></div>
                      </div>

                      <div class="approval-row">
                        <div class="approval-key">Last Updated</div>
                        <div class="approval-val">
                          <?php echo safeDate($req['updated_at']); ?>
                          <span class="proj-sub d-block"><?php echo getTimeAgo($req['updated_at']); ?></span>
                        </div>
                      </div>

                      <?php if (!empty($req['client_name'])): ?>
                      <div class="approval-row">
                        <div class="approval-key">Client</div>
                        <div class="approval-val"><?php echo e($req['client_name']); ?></div>
                      </div>
                      <?php endif; ?>
                    </div>

                    <div class="approval-actions">
                      <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view" title="View Details">
                        <i class="bi bi-eye"></i> View
                      </a>
                      <?php if ($isPEorTL && $req['status'] === 'Assigned'): ?>
                        <a href="manage-quotations.php?request_id=<?php echo $req['id']; ?>" class="btn-action review" title="Collect Quotations">
                          <i class="bi bi-file-text"></i> Collect Quotes
                        </a>
                      <?php elseif ($isPEorTL && $req['status'] === 'Quotations Received'): ?>
                        <a href="send-to-qs.php?request_id=<?php echo $req['id']; ?>" class="btn-action review" title="Send to QS">
                          <i class="bi bi-send"></i> Send to QS
                        </a>
                      <?php elseif ($isQS && $req['status'] === 'With QS'): ?>
                        <a href="finalize-quotation.php?request_id=<?php echo $req['id']; ?>" class="btn-action review" title="Finalize Quotation">
                          <i class="bi bi-check2-circle"></i> Finalize
                        </a>
                      <?php elseif ($isManager && $req['status'] === 'QS Finalized'): ?>
                        <a href="approve-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action approve" title="Approve">
                          <i class="bi bi-check-lg"></i> Approve
                        </a>
                        <button type="button" class="btn-action reject" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $req['id']; ?>" title="Reject">
                          <i class="bi bi-x-lg"></i> Reject
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- Reject Modal for managers -->
                  <?php if ($isManager && $req['status'] === 'QS Finalized'): ?>
                  <div class="modal fade" id="rejectModal<?php echo $req['id']; ?>" tabindex="-1" aria-labelledby="rejectModalLabel<?php echo $req['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title fw-900" id="rejectModalLabel<?php echo $req['id']; ?>">Reject Quotation Request</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="process-quotation-decision.php" method="POST">
                          <div class="modal-body">
                            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                            <input type="hidden" name="decision" value="reject">
                            
                            <p class="mb-3">You are about to reject request: <strong><?php echo e($req['request_no']); ?></strong></p>
                            <p class="mb-3">Site: <strong><?php echo e($req['project_name']); ?></strong></p>
                            
                            <div class="mb-3">
                              <label class="form-label required">Reason for Rejection</label>
                              <textarea class="form-control" name="rejection_reason" rows="3" required placeholder="Please provide a reason for rejection..."></textarea>
                            </div>
                            
                            <div class="form-text">
                              <i class="bi bi-info-circle"></i> This will update the request status to "Rejected" and notify the requester.
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Reject Request</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- DESKTOP/TABLET: DataTable -->
          <div class="d-none d-md-block">
            <div class="table-responsive">
              <table id="pendingActionsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                <thead>
                  <tr>
                    <th>Request No.</th>
                    <th>Title / Site</th>
                    <th>Type</th>
                    <th>Requested By</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                    <th>Priority</th>
                    <th class="text-end actions-col">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $req): ?>
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
                      <?php if (!empty($req['client_name'])): ?>
                        <div class="proj-sub"><i class="bi bi-person"></i> <?php echo e($req['client_name']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?php echo e($req['quotation_type']); ?></td>
                    <td>
                      <div class="proj-title"><?php echo e($req['requested_by_employee_name'] ?? $req['requested_by_name']); ?></div>
                    </td>
                    <td><?php echo getStatusBadge($req['status']); ?></td>
                    <td>
                      <span class="fw-800"><?php echo safeDate($req['updated_at']); ?></span>
                      <div class="proj-sub"><?php echo getTimeAgo($req['updated_at']); ?></div>
                    </td>
                    <td><?php echo getPriorityBadge($req['priority']); ?></td>
                    <td class="text-end actions-col">
                      <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view" title="View Details">
                        <i class="bi bi-eye"></i>
                      </a>
                      <?php if ($isPEorTL && $req['status'] === 'Assigned'): ?>
                        <a href="manage-quotations.php?request_id=<?php echo $req['id']; ?>" class="btn-action review" title="Collect Quotations">
                          <i class="bi bi-file-text"></i>
                        </a>
                      <?php elseif ($isPEorTL && $req['status'] === 'Quotations Received'): ?>
                        <a href="send-to-qs.php?request_id=<?php echo $req['id']; ?>" class="btn-action review" title="Send to QS">
                          <i class="bi bi-send"></i>
                        </a>
                      <?php elseif ($isQS && $req['status'] === 'With QS'): ?>
                        <a href="finalize-quotation.php?request_id=<?php echo $req['id']; ?>" class="btn-action review" title="Finalize Quotation">
                          <i class="bi bi-check2-circle"></i>
                        </a>
                      <?php elseif ($isManager && $req['status'] === 'QS Finalized'): ?>
                        <a href="approve-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action approve" title="Approve">
                          <i class="bi bi-check-lg"></i>
                        </a>
                        <button type="button" class="btn-action reject" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $req['id']; ?>" title="Reject">
                          <i class="bi bi-x-lg"></i>
                        </button>
                      <?php endif; ?>
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
  function initPendingActionsTable() {
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    const tbl = document.getElementById('pendingActionsTable');
    if (!tbl) return;

    if (isDesktop) {
      if (!$.fn.DataTable.isDataTable('#pendingActionsTable')) {
        $('#pendingActionsTable').DataTable({
          responsive: true,
          autoWidth: false,
          scrollX: false,
          pageLength: 10,
          lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
          order: [[5, 'desc']], // Sort by date
          columnDefs: [
            { targets: [7], orderable: false, searchable: false } // Action column
          ],
          language: {
            zeroRecords: "No pending actions found",
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
      if ($.fn.DataTable.isDataTable('#pendingActionsTable')) {
        $('#pendingActionsTable').DataTable().destroy();
      }
    }
  }

  $(function () {
    initPendingActionsTable();
    window.addEventListener('resize', initPendingActionsTable);
  });
</script>

</body>
</html>