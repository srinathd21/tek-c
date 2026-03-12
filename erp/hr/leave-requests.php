<?php
// hr/leave-requests.php - Manage Leave Requests (Using both leave_requests and employee_requests tables)
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH (HR) ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$designation = trim((string)($_SESSION['designation'] ?? ''));
$department  = trim((string)($_SESSION['department'] ?? ''));

$isHr = (strtolower($designation) === 'hr') || (strtolower($department) === 'hr');
if (!$isHr) {
  $fallback = $_SESSION['role_redirect'] ?? '../login.php';
  header("Location: " . $fallback);
  exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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

function statusBadgeClass($status){
  $s = strtolower(trim((string)$status));
  if ($s === 'approved') return ['Approved', 'ontrack'];
  if ($s === 'pending') return ['Pending', 'delayed'];
  if ($s === 'rejected') return ['Rejected', 'atrisk'];
  if ($s === 'cancelled') return ['Cancelled', 'atrisk'];
  return [$status ?? 'Unknown', 'atrisk'];
}

function leaveTypeFullName($type){
  $types = [
    'CL' => 'Casual Leave',
    'SL' => 'Sick Leave',
    'EL' => 'Earned Leave',
    'PL' => 'Privilege Leave',
    'LWP' => 'Leave Without Pay',
    'ML' => 'Maternity Leave',
    'PL' => 'Paternity Leave',
    'Comp-off' => 'Compensatory Off'
  ];
  return $types[$type] ?? $type;
}

function leaveTypeBadgeClass($type){
  $classes = [
    'CL' => 'bg-info',
    'SL' => 'bg-warning',
    'EL' => 'bg-success',
    'PL' => 'bg-primary',
    'LWP' => 'bg-secondary',
    'ML' => 'bg-danger',
    'Comp-off' => 'bg-dark'
  ];
  return $classes[$type] ?? 'bg-secondary';
}

function getInitials($name){
  $name = trim((string)$name);
  if ($name === '') return 'U';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return (count($parts) > 1) ? ($first.$last) : $first;
}

// ---------------- HANDLE ACTIONS ----------------
$message = '';
$messageType = '';

// Approve/Reject Leave Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $requestId = (int)($_POST['request_id'] ?? 0);
  $action = $_POST['action'];
  $remarks = trim($_POST['remarks'] ?? '');
  
  if ($requestId > 0 && in_array($action, ['approve', 'reject'])) {
    $status = ($action === 'approve') ? 'Approved' : 'Rejected';
    $approvedBy = $_SESSION['employee_id'];
    $approvedAt = date('Y-m-d H:i:s');
    
    // Update leave_requests table
    $query = "UPDATE leave_requests SET 
              status = ?, 
              remarks = ?, 
              approved_by = ?, 
              approved_at = ? 
              WHERE id = ?";
    
    $st = mysqli_prepare($conn, $query);
    if ($st) {
      mysqli_stmt_bind_param($st, "ssisi", $status, $remarks, $approvedBy, $approvedAt, $requestId);
      if (mysqli_stmt_execute($st)) {
        $message = "Leave request " . strtolower($status) . " successfully.";
        $messageType = "success";
        
        // Also update the corresponding employee_requests if exists
        $updateEmpReq = "UPDATE employee_requests SET 
                        status = ?, 
                        approver_remarks = ?, 
                        approved_by = ?, 
                        approved_at = ? 
                        WHERE request_type = 'Leave' 
                        AND request_subject LIKE ? 
                        AND employee_id = (SELECT employee_id FROM leave_requests WHERE id = ?)";
        
        $st2 = mysqli_prepare($conn, $updateEmpReq);
        if ($st2) {
          $subjectLike = "%Leave Request%";
          mysqli_stmt_bind_param($st2, "ssisii", $status, $remarks, $approvedBy, $approvedAt, $subjectLike, $requestId);
          mysqli_stmt_execute($st2);
          mysqli_stmt_close($st2);
        }
      } else {
        $message = "Failed to update leave request.";
        $messageType = "danger";
      }
      mysqli_stmt_close($st);
    }
  }
}

// Delete/Cancel Leave Request
if (isset($_GET['delete']) || isset($_GET['cancel'])) {
  $requestId = isset($_GET['delete']) ? (int)$_GET['delete'] : (int)$_GET['cancel'];
  $action = isset($_GET['delete']) ? 'delete' : 'cancel';
  
  if ($requestId > 0) {
    if ($action === 'delete') {
      // Permanent delete from leave_requests
      $query = "DELETE FROM leave_requests WHERE id = ?";
    } else {
      // Cancel (update status)
      $query = "UPDATE leave_requests SET status = 'Cancelled' WHERE id = ?";
    }
    
    $st = mysqli_prepare($conn, $query);
    if ($st) {
      mysqli_stmt_bind_param($st, "i", $requestId);
      if (mysqli_stmt_execute($st)) {
        $message = "Leave request " . ($action === 'delete' ? 'deleted' : 'cancelled') . " successfully.";
        $messageType = "success";
      } else {
        $message = "Failed to " . $action . " leave request.";
        $messageType = "danger";
      }
      mysqli_stmt_close($st);
    }
  }
}

// ---------------- FILTERS ----------------
$selectedStatus = isset($_GET['status']) ? $_GET['status'] : '';
$selectedType = isset($_GET['leave_type']) ? $_GET['leave_type'] : '';
$selectedEmployee = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// ---------------- FETCH EMPLOYEES FOR DROPDOWN ----------------
$employees = [];
$st = mysqli_prepare($conn, "SELECT id, full_name, employee_code FROM employees WHERE LOWER(employee_status) = 'active' ORDER BY full_name ASC");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    $employees[] = $row;
  }
  mysqli_stmt_close($st);
}

// ---------------- FETCH LEAVE REQUESTS FROM leave_requests TABLE ----------------
$query = "
  SELECT 
    lr.*,
    e.full_name,
    e.employee_code,
    e.department,
    e.designation,
    e.photo,
    e.reporting_manager,
    manager.full_name as approved_by_name,
    rm.full_name as reporting_manager_name
  FROM leave_requests lr
  INNER JOIN employees e ON lr.employee_id = e.id
  LEFT JOIN employees manager ON lr.approved_by = manager.id
  LEFT JOIN employees rm ON e.reporting_to = rm.id
  WHERE 1=1
";

$params = [];
$types = "";

if (!empty($selectedStatus)) {
  $query .= " AND LOWER(lr.status) = ?";
  $params[] = strtolower($selectedStatus);
  $types .= "s";
}

if (!empty($selectedType)) {
  $query .= " AND lr.leave_type = ?";
  $params[] = $selectedType;
  $types .= "s";
}

if ($selectedEmployee > 0) {
  $query .= " AND lr.employee_id = ?";
  $params[] = $selectedEmployee;
  $types .= "i";
}

if (!empty($dateFrom)) {
  $query .= " AND lr.from_date >= ?";
  $params[] = $dateFrom;
  $types .= "s";
}

if (!empty($dateTo)) {
  $query .= " AND lr.to_date <= ?";
  $params[] = $dateTo;
  $types .= "s";
}

$query .= " ORDER BY lr.applied_at DESC";

$leaveRequests = [];
$st = mysqli_prepare($conn, $query);
if ($st) {
  if (!empty($params)) {
    mysqli_stmt_bind_param($st, $types, ...$params);
  }
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    $leaveRequests[] = $row;
  }
  mysqli_stmt_close($st);
}

// ---------------- CALCULATE SUMMARY STATS ----------------
$summaryStats = [
  'total' => count($leaveRequests),
  'pending' => 0,
  'approved' => 0,
  'rejected' => 0,
  'cancelled' => 0,
  'total_days' => 0
];

foreach ($leaveRequests as $request) {
  $status = strtolower($request['status'] ?? '');
  if ($status === 'pending') $summaryStats['pending']++;
  elseif ($status === 'approved') $summaryStats['approved']++;
  elseif ($status === 'rejected') $summaryStats['rejected']++;
  elseif ($status === 'cancelled') $summaryStats['cancelled']++;
  
  $summaryStats['total_days'] += (float)($request['total_days'] ?? 0);
}

// Get leave balance summary by type
$leaveBalance = [];
$balanceQuery = "
  SELECT 
    leave_type,
    SUM(CASE WHEN status = 'Approved' THEN total_days ELSE 0 END) as used_days
  FROM leave_requests 
  WHERE YEAR(from_date) = YEAR(CURDATE())
  GROUP BY leave_type
";
$st = mysqli_prepare($conn, $balanceQuery);
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    $leaveBalance[$row['leave_type']] = (float)($row['used_days'] ?? 0);
  }
  mysqli_stmt_close($st);
}

$loggedName = $_SESSION['employee_name'] ?? 'HR';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Leave Requests - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:100%; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: var(--green); }
    .stat-ic.orange{ background: var(--orange); }
    .stat-ic.red{ background: var(--red); }
    .stat-ic.purple{ background: #8e44ad; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .filter-card{ background: #f9fafb; border-radius: var(--radius); padding:16px; margin-bottom:20px; border:1px solid var(--border); }

    .badge-pill{ border-radius:999px; padding:6px 12px; font-weight:800; font-size:12px; border:1px solid transparent; display:inline-flex; align-items:center; gap:6px; }
    .badge-pill .mini-dot{ width:6px; height:6px; border-radius:50%; background: currentColor; opacity:.9; }
    .ontrack{ color: var(--green); background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.18); }
    .atrisk{ color: var(--red); background: rgba(235,87,87,.12); border-color: rgba(235,87,87,.18); }
    .delayed{ color:#b7791f; background: rgba(242,201,76,.20); border-color: rgba(242,201,76,.28); }

    .employee-avatar-sm{ width:36px; height:36px; border-radius:50%; background: linear-gradient(135deg, var(--yellow), #ffd66b);
      display:grid; place-items:center; font-weight:900; color:#1f2937; font-size:14px; flex-shrink:0; }
    .employee-avatar-sm img{ width:100%; height:100%; border-radius:50%; object-fit:cover; }

    .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; }
    .table td{ vertical-align:middle; border-color: var(--border); font-weight:650; color:#374151; padding-top:12px; padding-bottom:12px; }

    .action-btn{ width:32px; height:32px; border-radius:8px; border:1px solid var(--border); background:#fff; 
      display:inline-flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; margin:0 2px; }
    .action-btn:hover{ background:#f3f4f6; color:#374151; }

    .leave-dates{ font-size:13px; font-weight:700; color:#1f2937; }
    .leave-reason{ font-size:12px; color:#6b7280; max-width:250px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .modal-content{ border-radius:16px; border:0; box-shadow:0 20px 40px rgba(0,0,0,0.2); }
    .modal-header{ background: #f9fafb; border-bottom:1px solid var(--border); padding:20px; }
    .modal-body{ padding:20px; }
    .modal-footer{ border-top:1px solid var(--border); padding:16px 20px; }

    .summary-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:20px; }
    .summary-item{ background:#f9fafb; border-radius:12px; padding:12px; text-align:center; border:1px solid var(--border); }
    .summary-value{ font-size:24px; font-weight:900; line-height:1; color:#1f2937; }
    .summary-label{ font-size:12px; font-weight:700; color:#6b7280; margin-top:4px; }

    .leave-balance-card{ background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:white; border-radius:12px; padding:16px; margin-bottom:20px; }
    .balance-item{ display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.2); }
    .balance-item:last-child{ border-bottom:0; }
    .balance-type{ font-weight:700; }
    .balance-days{ font-weight:900; font-size:18px; }

    @media (max-width: 767.98px){
      .stat-value{ font-size:24px; }
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

          <!-- Page Header -->
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h1 class="h3 mb-0" style="font-weight:900;">Leave Requests</h1>
              <p class="text-muted mt-1 mb-0">Manage employee leave applications and approvals</p>
            </div>
            <div>
              <a href="leave-balance.php" class="btn btn-outline-secondary me-2" style="font-weight:800;">
                <i class="bi bi-pie-chart"></i> Leave Balance
              </a>
              <a href="leave-policies.php" class="btn btn-outline-primary" style="font-weight:800;">
                <i class="bi bi-gear"></i> Policies
              </a>
            </div>
          </div>

          <!-- Alert Message -->
          <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-3" role="alert">
              <?php echo e($message); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>

          <!-- Leave Balance Summary -->
          <?php if (!empty($leaveBalance)): ?>
          <div class="leave-balance-card mb-4">
            <h5 class="fw-bold mb-3">Leave Usage (Current Year)</h5>
            <div class="row">
              <?php foreach ($leaveBalance as $type => $days): ?>
              <div class="col-6 col-md-3">
                <div class="balance-item">
                  <span class="balance-type"><?php echo e(leaveTypeFullName($type)); ?></span>
                  <span class="balance-days"><?php echo number_format($days, 1); ?></span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Stats Cards -->
          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-calendar-check"></i></div>
                <div>
                  <div class="stat-label">Total Requests</div>
                  <div class="stat-value"><?php echo $summaryStats['total']; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
                <div>
                  <div class="stat-label">Pending</div>
                  <div class="stat-value"><?php echo $summaryStats['pending']; ?></div>
                  <?php if ($summaryStats['pending'] > 0): ?>
                    <span class="badge bg-warning text-dark mt-1">Action Required</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                <div>
                  <div class="stat-label">Approved</div>
                  <div class="stat-value"><?php echo $summaryStats['approved']; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic purple"><i class="bi bi-calendar-range"></i></div>
                <div>
                  <div class="stat-label">Total Days</div>
                  <div class="stat-value"><?php echo number_format($summaryStats['total_days'], 1); ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Filter Card -->
          <div class="filter-card">
            <form method="GET" action="" class="row g-3 align-items-end">
              <div class="col-12 col-md-3">
                <label class="form-label fw-bold">Employee</label>
                <select name="employee_id" class="form-select select2">
                  <option value="0">All Employees</option>
                  <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $selectedEmployee == $emp['id'] ? 'selected' : ''; ?>>
                      <?php echo e($emp['full_name']); ?> (<?php echo e($emp['employee_code']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 col-md-2">
                <label class="form-label fw-bold">Status</label>
                <select name="status" class="form-select">
                  <option value="">All Status</option>
                  <option value="Pending" <?php echo $selectedStatus == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                  <option value="Approved" <?php echo $selectedStatus == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                  <option value="Rejected" <?php echo $selectedStatus == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                  <option value="Cancelled" <?php echo $selectedStatus == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
              </div>

              <div class="col-12 col-md-2">
                <label class="form-label fw-bold">Leave Type</label>
                <select name="leave_type" class="form-select">
                  <option value="">All Types</option>
                  <option value="CL" <?php echo $selectedType == 'CL' ? 'selected' : ''; ?>>Casual Leave</option>
                  <option value="SL" <?php echo $selectedType == 'SL' ? 'selected' : ''; ?>>Sick Leave</option>
                  <option value="EL" <?php echo $selectedType == 'EL' ? 'selected' : ''; ?>>Earned Leave</option>
                  <option value="PL" <?php echo $selectedType == 'PL' ? 'selected' : ''; ?>>Privilege Leave</option>
                  <option value="LWP" <?php echo $selectedType == 'LWP' ? 'selected' : ''; ?>>Leave Without Pay</option>
                  <option value="ML" <?php echo $selectedType == 'ML' ? 'selected' : ''; ?>>Maternity Leave</option>
                  <option value="Comp-off" <?php echo $selectedType == 'Comp-off' ? 'selected' : ''; ?>>Compensatory Off</option>
                </select>
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label fw-bold">Date Range</label>
                <div class="d-flex gap-2">
                  <input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>" placeholder="From">
                  <input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>" placeholder="To">
                </div>
              </div>

              <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100" style="font-weight:800;">
                  <i class="bi bi-funnel"></i> Apply Filters
                </button>
              </div>
            </form>
          </div>

          <!-- Leave Requests Table -->
          <div class="panel">
            <div class="panel-header">
              <h3 class="panel-title">
                <i class="bi bi-list-check me-2"></i>Leave Applications
                <?php if ($summaryStats['pending'] > 0): ?>
                  <span class="badge bg-warning text-dark ms-2"><?php echo $summaryStats['pending']; ?> pending</span>
                <?php endif; ?>
              </h3>
              <span class="badge bg-secondary"><?php echo count($leaveRequests); ?> records</span>
            </div>

            <div class="table-responsive">
              <table class="table align-middle" id="leaveTable">
                <thead>
                  <tr>
                    <th style="min-width:180px;">Employee</th>
                    <th style="min-width:100px;">Leave Type</th>
                    <th style="min-width:200px;">Leave Period</th>
                    <th style="min-width:80px;">Days</th>
                    <th style="min-width:200px;">Reason</th>
                    <th style="min-width:100px;">Applied On</th>
                    <th style="min-width:120px;">Status / Approver</th>
                    <th style="min-width:120px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($leaveRequests)): ?>
                    <tr>
                      <td colspan="8" class="text-center text-muted py-4" style="font-weight:800;">
                        No leave requests found.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($leaveRequests as $request): ?>
                      <?php 
                        [$statusLabel, $statusClass] = statusBadgeClass($request['status'] ?? '');
                        $leaveTypeFull = leaveTypeFullName($request['leave_type'] ?? '');
                        $typeBadgeClass = leaveTypeBadgeClass($request['leave_type'] ?? '');
                        
                        $fromDate = safeDate($request['from_date'] ?? '');
                        $toDate = safeDate($request['to_date'] ?? '');
                        $appliedAt = safeDateTime($request['applied_at'] ?? '');
                        
                        // Parse selected dates if JSON
                        $selectedDates = [];
                        if (!empty($request['selected_dates_json'])) {
                          $selectedDates = json_decode($request['selected_dates_json'], true);
                        }
                        
                        $avatar = '';
                        if (!empty($request['photo'])) {
                          $avatar = '<img src="../admin/' . e($request['photo']) . '" alt="' . e($request['full_name']) . '">';
                        }
                      ?>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <div class="employee-avatar-sm">
                              <?php if (!empty($request['photo'])): ?>
                                <?php echo $avatar; ?>
                              <?php else: ?>
                                <?php echo getInitials($request['full_name'] ?? ''); ?>
                              <?php endif; ?>
                            </div>
                            <div>
                              <div style="font-weight:900;"><?php echo e($request['full_name'] ?? ''); ?></div>
                              <div class="activity-sub"><?php echo e($request['employee_code'] ?? ''); ?> • <?php echo e($request['department'] ?? ''); ?></div>
                            </div>
                          </div>
                        </td>
                        <td>
                          <span class="badge <?php echo $typeBadgeClass; ?> text-white"><?php echo e($request['leave_type'] ?? ''); ?></span>
                          <div><small class="text-muted"><?php echo e($leaveTypeFull); ?></small></div>
                        </td>
                        <td>
                          <div class="leave-dates">
                            <?php echo $fromDate; ?> - <?php echo $toDate; ?>
                          </div>
                          <?php if (!empty($selectedDates)): ?>
                            <small class="text-muted">
                              <i class="bi bi-calendar-week"></i> <?php echo count($selectedDates); ?> selected dates
                            </small>
                          <?php endif; ?>
                          <?php if (!empty($request['reporting_manager_name'])): ?>
                            <div><small class="text-muted">Mgr: <?php echo e($request['reporting_manager_name']); ?></small></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="fw-bold"><?php echo number_format($request['total_days'] ?? 0, 1); ?></span>
                          <?php 
                            // Check if any half days
                            if (!empty($selectedDates)) {
                              $halfDays = array_filter($selectedDates, function($item) {
                                return isset($item['half_day']) && !empty($item['half_day']);
                              });
                              if (count($halfDays) > 0) {
                                echo '<div><small class="text-muted">' . count($halfDays) . ' half day</small></div>';
                              }
                            }
                          ?>
                        </td>
                        <td>
                          <div class="leave-reason" title="<?php echo e($request['reason'] ?? ''); ?>">
                            <?php echo e($request['reason'] ?? '—'); ?>
                          </div>
                          <?php if (!empty($request['contact_during_leave'])): ?>
                            <small class="text-muted">
                              <i class="bi bi-telephone"></i> <?php echo e($request['contact_during_leave']); ?>
                            </small>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div class="fw-bold"><?php echo date('d M', strtotime($request['applied_at'] ?? '')); ?></div>
                          <small class="text-muted"><?php echo date('h:i A', strtotime($request['applied_at'] ?? '')); ?></small>
                        </td>
                        <td>
                          <span class="badge-pill <?php echo e($statusClass); ?>">
                            <span class="mini-dot"></span> <?php echo e($statusLabel); ?>
                          </span>
                          <?php if (!empty($request['approved_by_name']) && $request['status'] === 'Approved'): ?>
                            <div><small class="text-muted">by <?php echo e($request['approved_by_name']); ?></small></div>
                            <div><small class="text-muted"><?php echo safeDateTime($request['approved_at'] ?? ''); ?></small></div>
                          <?php endif; ?>
                          <?php if (!empty($request['remarks']) && $request['status'] !== 'Pending'): ?>
                            <div><small class="text-muted" title="<?php echo e($request['remarks']); ?>"><?php echo e(substr($request['remarks'], 0, 20)); ?>...</small></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div class="d-flex gap-1">
                            <button type="button" class="action-btn" title="View Details" 
                                    onclick="viewLeaveDetails(<?php echo htmlspecialchars(json_encode($request), ENT_QUOTES, 'UTF-8'); ?>)">
                              <i class="bi bi-eye"></i>
                            </button>
                            
                            <?php if (strtolower($request['status'] ?? '') === 'pending'): ?>
                              <button type="button" class="action-btn text-success" title="Approve"
                                      onclick="showApproveModal(<?php echo $request['id']; ?>, '<?php echo e($request['full_name']); ?>')">
                                <i class="bi bi-check-lg"></i>
                              </button>
                              <button type="button" class="action-btn text-danger" title="Reject"
                                      onclick="showRejectModal(<?php echo $request['id']; ?>, '<?php echo e($request['full_name']); ?>')">
                                <i class="bi bi-x-lg"></i>
                              </button>
                            <?php endif; ?>
                            
                            <?php if (strtolower($request['status'] ?? '') === 'pending'): ?>
                              <a href="?cancel=<?php echo $request['id']; ?>" class="action-btn text-warning" 
                                 title="Cancel" onclick="return confirm('Are you sure you want to cancel this request?')">
                                <i class="bi bi-slash-circle"></i>
                              </a>
                            <?php endif; ?>
                            
                            <?php if (strtolower($request['status'] ?? '') === 'rejected' || strtolower($request['status'] ?? '') === 'cancelled'): ?>
                              <a href="?delete=<?php echo $request['id']; ?>" class="action-btn text-danger" 
                                 title="Delete" onclick="return confirm('Are you sure you want to delete this request? This action cannot be undone.')">
                                <i class="bi bi-trash"></i>
                              </a>
                            <?php endif; ?>
                          </div>
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

      <?php include 'includes/footer.php'; ?>
    </main>
  </div>

  <!-- Approve Modal -->
  <div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST" action="">
          <input type="hidden" name="request_id" id="approveRequestId">
          <input type="hidden" name="action" value="approve">
          
          <div class="modal-header">
            <h5 class="modal-title fw-bold">Approve Leave Request</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          
          <div class="modal-body">
            <p>Are you sure you want to approve leave request for <span id="approveEmployeeName" class="fw-bold"></span>?</p>
            
            <div class="mb-3">
              <label class="form-label fw-bold">Remarks (Optional)</label>
              <textarea name="remarks" class="form-control" rows="3" placeholder="Add any approval remarks..."></textarea>
            </div>
          </div>
          
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Approve Leave</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Reject Modal -->
  <div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST" action="">
          <input type="hidden" name="request_id" id="rejectRequestId">
          <input type="hidden" name="action" value="reject">
          
          <div class="modal-header">
            <h5 class="modal-title fw-bold">Reject Leave Request</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          
          <div class="modal-body">
            <p>Are you sure you want to reject leave request for <span id="rejectEmployeeName" class="fw-bold"></span>?</p>
            
            <div class="mb-3">
              <label class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span></label>
              <textarea name="remarks" class="form-control" rows="3" placeholder="Please provide reason for rejection..." required></textarea>
            </div>
          </div>
          
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">Reject Leave</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- View Details Modal -->
  <div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Leave Request Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        
        <div class="modal-body" id="viewModalBody">
          <!-- Content will be populated by JavaScript -->
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="assets/js/sidebar-toggle.js"></script>

  <script>
    $(document).ready(function() {
      // Initialize Select2
      $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Select employee'
      });

      // Initialize DataTable
      <?php if (!empty($leaveRequests)): ?>
      $('#leaveTable').DataTable({
        pageLength: 25,
        order: [[5, 'desc']], // Sort by applied date descending
        language: {
          search: "Search requests:",
          lengthMenu: "Show _MENU_ entries per page",
          info: "Showing _START_ to _END_ of _TOTAL_ requests",
          infoEmpty: "No requests available",
          infoFiltered: "(filtered from _MAX_ total requests)"
        },
        columnDefs: [
          { orderable: false, targets: [7] } // Disable sorting on actions column
        ]
      });
      <?php endif; ?>
    });

    // Show Approve Modal
    function showApproveModal(requestId, employeeName) {
      document.getElementById('approveRequestId').value = requestId;
      document.getElementById('approveEmployeeName').textContent = employeeName;
      
      const modal = new bootstrap.Modal(document.getElementById('approveModal'));
      modal.show();
    }

    // Show Reject Modal
    function showRejectModal(requestId, employeeName) {
      document.getElementById('rejectRequestId').value = requestId;
      document.getElementById('rejectEmployeeName').textContent = employeeName;
      
      const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
      modal.show();
    }

    // View Leave Details
    function viewLeaveDetails(request) {
      const statusClass = request.status.toLowerCase() === 'approved' ? 'ontrack' : 
                         (request.status.toLowerCase() === 'pending' ? 'delayed' : 'atrisk');
      
      const leaveTypeFull = {
        'CL': 'Casual Leave',
        'SL': 'Sick Leave',
        'EL': 'Earned Leave',
        'PL': 'Privilege Leave',
        'LWP': 'Leave Without Pay',
        'ML': 'Maternity Leave',
        'Comp-off': 'Compensatory Off'
      }[request.leave_type] || request.leave_type;
      
      const fromDate = request.from_date ? new Date(request.from_date).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'}) : '—';
      const toDate = request.to_date ? new Date(request.to_date).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'}) : '—';
      
      // Parse selected dates if available
      let selectedDatesHtml = '';
      if (request.selected_dates_json) {
        try {
          const dates = JSON.parse(request.selected_dates_json);
          if (dates && dates.length > 0) {
            selectedDatesHtml = '<div class="mt-3"><h6 class="fw-bold mb-2">Selected Dates:</h6><div class="d-flex flex-wrap gap-2">';
            dates.forEach(item => {
              const date = new Date(item.date);
              const halfDay = item.half_day ? ' (Half Day)' : '';
              selectedDatesHtml += `<span class="badge bg-light text-dark border p-2">${date.toLocaleDateString('en-IN', {day: '2-digit', month: 'short'})}${halfDay}</span>`;
            });
            selectedDatesHtml += '</div></div>';
          }
        } catch(e) {
          console.error('Error parsing dates:', e);
        }
      }
      
      const html = `
        <div class="row">
          <div class="col-md-6">
            <div class="mb-3">
              <label class="text-muted small fw-bold">Employee</label>
              <div class="fw-bold">${request.full_name || ''}</div>
              <div class="text-muted">${request.employee_code || ''} • ${request.department || ''} • ${request.designation || ''}</div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Leave Type</label>
              <div><span class="badge bg-secondary text-white">${request.leave_type || ''}</span> ${leaveTypeFull}</div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Leave Period</label>
              <div class="fw-bold">${fromDate} - ${toDate}</div>
              <div class="text-muted">${request.total_days || 0} days total</div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Reason</label>
              <div>${request.reason || '—'}</div>
            </div>
            
            ${request.contact_during_leave ? `
            <div class="mb-3">
              <label class="text-muted small fw-bold">Contact During Leave</label>
              <div>${request.contact_during_leave}</div>
            </div>
            ` : ''}
            
            ${request.handover_to ? `
            <div class="mb-3">
              <label class="text-muted small fw-bold">Handover To</label>
              <div>${request.handover_to}</div>
            </div>
            ` : ''}
            
            ${selectedDatesHtml}
          </div>
          
          <div class="col-md-6">
            <div class="mb-3">
              <label class="text-muted small fw-bold">Status</label>
              <div><span class="badge-pill ${statusClass}"><span class="mini-dot"></span> ${request.status}</span></div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Applied On</label>
              <div>${new Date(request.applied_at).toLocaleString('en-IN', {day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'})}</div>
            </div>
            
            ${request.reporting_manager ? `
            <div class="mb-3">
              <label class="text-muted small fw-bold">Reporting Manager</label>
              <div>${request.reporting_manager}</div>
            </div>
            ` : ''}
            
            ${request.approved_by_name ? `
            <div class="mb-3">
              <label class="text-muted small fw-bold">Approved By</label>
              <div>${request.approved_by_name}</div>
              <div class="text-muted small">${new Date(request.approved_at).toLocaleString('en-IN', {day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'})}</div>
            </div>
            ` : ''}
            
            ${request.remarks ? `
            <div class="mb-3">
              <label class="text-muted small fw-bold">${request.status === 'Rejected' ? 'Rejection Reason' : 'Remarks'}</label>
              <div>${request.remarks}</div>
            </div>
            ` : ''}
          </div>
        </div>
      `;
      
      document.getElementById('viewModalBody').innerHTML = html;
      
      const modal = new bootstrap.Modal(document.getElementById('viewModal'));
      modal.show();
    }
  </script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
  mysqli_close($conn);
}
?>