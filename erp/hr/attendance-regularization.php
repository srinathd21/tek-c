<?php
// hr/attendance-regularization.php - Attendance Regularization Requests
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

function formatTime($v, $dash='—'){
  if (!$v || $v === '00:00:00') return $dash;
  return date('h:i A', strtotime($v));
}

function statusBadgeClass($status){
  $s = strtolower(trim((string)$status));
  if ($s === 'approved') return ['Approved', 'ontrack'];
  if ($s === 'pending') return ['Pending', 'delayed'];
  if ($s === 'rejected') return ['Rejected', 'atrisk'];
  if ($s === 'cancelled') return ['Cancelled', 'atrisk'];
  return [$status ?? 'Unknown', 'atrisk'];
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

// Approve/Reject Regularization Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $requestId = (int)($_POST['request_id'] ?? 0);
  $action = $_POST['action'];
  $remarks = trim($_POST['remarks'] ?? '');
  
  if ($requestId > 0 && in_array($action, ['approve', 'reject'])) {
    $status = ($action === 'approve') ? 'Approved' : 'Rejected';
    $approvedBy = $_SESSION['employee_id'];
    $approvedAt = date('Y-m-d H:i:s');
    
    // First, get the request details
    $getQuery = "SELECT * FROM attendance_exceptions WHERE id = ?";
    $st = mysqli_prepare($conn, $getQuery);
    if ($st) {
      mysqli_stmt_bind_param($st, "i", $requestId);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $request = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
      
      if ($request && $action === 'approve') {
        // If approved, update or create attendance record
        $attendanceDate = $request['exception_date'];
        $employeeId = $request['employee_id'];
        $startTime = $request['start_time'];
        $endTime = $request['end_time'];
        
        // Check if attendance record exists
        $checkQuery = "SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?";
        $st = mysqli_prepare($conn, $checkQuery);
        if ($st) {
          mysqli_stmt_bind_param($st, "is", $employeeId, $attendanceDate);
          mysqli_stmt_execute($st);
          $res = mysqli_stmt_get_result($st);
          $existing = mysqli_fetch_assoc($res);
          mysqli_stmt_close($st);
          
          if ($existing) {
            // Update existing attendance
            $updateQuery = "UPDATE attendance SET 
                           punch_in_time = CONCAT(?, ' ', ?),
                           punch_out_time = CONCAT(?, ' ', ?),
                           total_hours = TIMESTAMPDIFF(HOUR, CONCAT(?, ' ', ?), CONCAT(?, ' ', ?)),
                           status = 'present',
                           remarks = CONCAT('Regularized: ', ?)
                           WHERE id = ?";
            $st = mysqli_prepare($conn, $updateQuery);
            if ($st) {
              mysqli_stmt_bind_param($st, "ssssssssssi", 
                $attendanceDate, $startTime,
                $attendanceDate, $endTime,
                $attendanceDate, $startTime,
                $attendanceDate, $endTime,
                $remarks,
                $existing['id']
              );
              mysqli_stmt_execute($st);
              mysqli_stmt_close($st);
            }
          } else {
            // Create new attendance record
            $totalHours = (strtotime($endTime) - strtotime($startTime)) / 3600;
            $insertQuery = "INSERT INTO attendance 
                           (employee_id, attendance_date, punch_in_time, punch_out_time, 
                            total_hours, status, remarks, punch_in_type, punch_out_type) 
                           VALUES (?, ?, CONCAT(?, ' ', ?), CONCAT(?, ' ', ?), 
                                   ?, 'present', ?, 'regularized', 'regularized')";
            $st = mysqli_prepare($conn, $insertQuery);
            if ($st) {
              mysqli_stmt_bind_param($st, "isssssdss", 
                $employeeId, $attendanceDate,
                $attendanceDate, $startTime,
                $attendanceDate, $endTime,
                $totalHours,
                $remarks
              );
              mysqli_stmt_execute($st);
              mysqli_stmt_close($st);
            }
          }
        }
      }
      
      // Update the exception request status
      $updateQuery = "UPDATE attendance_exceptions SET 
                     status = ?, 
                     rejection_reason = ?, 
                     approved_by = ?, 
                     approved_at = ? 
                     WHERE id = ?";
      $st = mysqli_prepare($conn, $updateQuery);
      if ($st) {
        mysqli_stmt_bind_param($st, "ssisi", $status, $remarks, $approvedBy, $approvedAt, $requestId);
        if (mysqli_stmt_execute($st)) {
          $message = "Regularization request " . strtolower($status) . " successfully.";
          $messageType = "success";
        } else {
          $message = "Failed to update request.";
          $messageType = "danger";
        }
        mysqli_stmt_close($st);
      }
    }
  }
}

// Delete/Cancel Request
if (isset($_GET['delete']) || isset($_GET['cancel'])) {
  $requestId = isset($_GET['delete']) ? (int)$_GET['delete'] : (int)$_GET['cancel'];
  $action = isset($_GET['delete']) ? 'delete' : 'cancel';
  
  if ($requestId > 0) {
    if ($action === 'delete') {
      $query = "DELETE FROM attendance_exceptions WHERE id = ?";
    } else {
      $query = "UPDATE attendance_exceptions SET status = 'Cancelled' WHERE id = ?";
    }
    
    $st = mysqli_prepare($conn, $query);
    if ($st) {
      mysqli_stmt_bind_param($st, "i", $requestId);
      if (mysqli_stmt_execute($st)) {
        $message = "Request " . ($action === 'delete' ? 'deleted' : 'cancelled') . " successfully.";
        $messageType = "success";
      } else {
        $message = "Failed to " . $action . " request.";
        $messageType = "danger";
      }
      mysqli_stmt_close($st);
    }
  }
}

// ---------------- FILTERS ----------------
$selectedStatus = isset($_GET['status']) ? $_GET['status'] : '';
$selectedType = isset($_GET['type']) ? $_GET['type'] : '';
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

// ---------------- FETCH REGULARIZATION REQUESTS ----------------
$query = "
  SELECT 
    ae.*,
    e.full_name,
    e.employee_code,
    e.department,
    e.designation,
    e.photo,
    manager.full_name as approved_by_name,
    a.punch_in_time as original_punch_in,
    a.punch_out_time as original_punch_out,
    a.status as original_status
  FROM attendance_exceptions ae
  INNER JOIN employees e ON ae.employee_id = e.id
  LEFT JOIN employees manager ON ae.approved_by = manager.id
  LEFT JOIN attendance a ON ae.employee_id = a.employee_id AND ae.exception_date = a.attendance_date
  WHERE 1=1
";

$params = [];
$types = "";

if (!empty($selectedStatus)) {
  $query .= " AND LOWER(ae.status) = ?";
  $params[] = strtolower($selectedStatus);
  $types .= "s";
}

if (!empty($selectedType)) {
  $query .= " AND ae.exception_type = ?";
  $params[] = $selectedType;
  $types .= "s";
}

if ($selectedEmployee > 0) {
  $query .= " AND ae.employee_id = ?";
  $params[] = $selectedEmployee;
  $types .= "i";
}

if (!empty($dateFrom)) {
  $query .= " AND ae.exception_date >= ?";
  $params[] = $dateFrom;
  $types .= "s";
}

if (!empty($dateTo)) {
  $query .= " AND ae.exception_date <= ?";
  $params[] = $dateTo;
  $types .= "s";
}

$query .= " ORDER BY ae.created_at DESC";

$regularizationRequests = [];
$st = mysqli_prepare($conn, $query);
if ($st) {
  if (!empty($params)) {
    mysqli_stmt_bind_param($st, $types, ...$params);
  }
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    $regularizationRequests[] = $row;
  }
  mysqli_stmt_close($st);
}

// ---------------- CALCULATE SUMMARY STATS ----------------
$summaryStats = [
  'total' => count($regularizationRequests),
  'pending' => 0,
  'approved' => 0,
  'rejected' => 0,
  'cancelled' => 0
];

foreach ($regularizationRequests as $request) {
  $status = strtolower($request['status'] ?? '');
  if ($status === 'pending') $summaryStats['pending']++;
  elseif ($status === 'approved') $summaryStats['approved']++;
  elseif ($status === 'rejected') $summaryStats['rejected']++;
  elseif ($status === 'cancelled') $summaryStats['cancelled']++;
}

$loggedName = $_SESSION['employee_name'] ?? 'HR';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Attendance Regularization - TEK-C</title>

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

    .time-slot{ font-size:13px; font-weight:700; color:#1f2937; }
    .request-reason{ font-size:12px; color:#6b7280; max-width:250px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .modal-content{ border-radius:16px; border:0; box-shadow:0 20px 40px rgba(0,0,0,0.2); }
    .modal-header{ background: #f9fafb; border-bottom:1px solid var(--border); padding:20px; }
    .modal-body{ padding:20px; }
    .modal-footer{ border-top:1px solid var(--border); padding:16px 20px; }

    .summary-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:20px; }
    .summary-item{ background:#f9fafb; border-radius:12px; padding:12px; text-align:center; border:1px solid var(--border); }
    .summary-value{ font-size:24px; font-weight:900; line-height:1; color:#1f2937; }
    .summary-label{ font-size:12px; font-weight:700; color:#6b7280; margin-top:4px; }

    .type-badge{ display:inline-block; padding:4px 8px; border-radius:6px; font-weight:700; font-size:11px; }
    .type-vacation{ background:#e3f2fd; color:#0d47a1; }
    .type-remote{ background:#e8f5e8; color:#1b5e20; }
    .type-field{ background:#fff3e0; color:#b85c00; }
    .type-other{ background:#f3e5f5; color:#4a148c; }

    .original-entry{ background:#f8f9fa; padding:4px 8px; border-radius:4px; font-size:11px; color:#6c757d; }

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
              <h1 class="h3 mb-0" style="font-weight:900;">Attendance Regularization</h1>
              <p class="text-muted mt-1 mb-0">Manage employee attendance correction requests</p>
            </div>
            <div>
              <a href="attendance.php" class="btn btn-outline-secondary me-2" style="font-weight:800;">
                <i class="bi bi-calendar-check"></i> Attendance
              </a>
              <a href="regularization-policy.php" class="btn btn-outline-primary" style="font-weight:800;">
                <i class="bi bi-gear"></i> Policy
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

          <!-- Stats Cards -->
          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-clock-history"></i></div>
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
                <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                <div>
                  <div class="stat-label">Rejected</div>
                  <div class="stat-value"><?php echo $summaryStats['rejected']; ?></div>
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
                <label class="form-label fw-bold">Request Type</label>
                <select name="type" class="form-select">
                  <option value="">All Types</option>
                  <option value="vacation" <?php echo $selectedType == 'vacation' ? 'selected' : ''; ?>>Vacation</option>
                  <option value="remote-work" <?php echo $selectedType == 'remote-work' ? 'selected' : ''; ?>>Remote Work</option>
                  <option value="field-work" <?php echo $selectedType == 'field-work' ? 'selected' : ''; ?>>Field Work</option>
                  <option value="other" <?php echo $selectedType == 'other' ? 'selected' : ''; ?>>Other</option>
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

          <!-- Regularization Requests Table -->
          <div class="panel">
            <div class="panel-header">
              <h3 class="panel-title">
                <i class="bi bi-list-check me-2"></i>Regularization Requests
                <?php if ($summaryStats['pending'] > 0): ?>
                  <span class="badge bg-warning text-dark ms-2"><?php echo $summaryStats['pending']; ?> pending</span>
                <?php endif; ?>
              </h3>
              <span class="badge bg-secondary"><?php echo count($regularizationRequests); ?> records</span>
            </div>

            <div class="table-responsive">
              <table class="table align-middle" id="regularizationTable">
                <thead>
                  <tr>
                    <th style="min-width:180px;">Employee</th>
                    <th style="min-width:100px;">Date</th>
                    <th style="min-width:120px;">Type</th>
                    <th style="min-width:160px;">Requested Time</th>
                    <th style="min-width:160px;">Original Record</th>
                    <th style="min-width:200px;">Reason</th>
                    <th style="min-width:120px;">Status</th>
                    <th style="min-width:140px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($regularizationRequests)): ?>
                    <tr>
                      <td colspan="8" class="text-center text-muted py-4" style="font-weight:800;">
                        No regularization requests found.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($regularizationRequests as $request): ?>
                      <?php 
                        [$statusLabel, $statusClass] = statusBadgeClass($request['status'] ?? '');
                        
                        $typeClass = '';
                        $typeIcon = '';
                        switch($request['exception_type']) {
                          case 'vacation':
                            $typeClass = 'type-vacation';
                            $typeIcon = 'bi-umbrella';
                            $typeDisplay = 'Vacation';
                            break;
                          case 'remote-work':
                            $typeClass = 'type-remote';
                            $typeIcon = 'bi-house';
                            $typeDisplay = 'Remote Work';
                            break;
                          case 'field-work':
                            $typeClass = 'type-field';
                            $typeIcon = 'bi-truck';
                            $typeDisplay = 'Field Work';
                            break;
                          default:
                            $typeClass = 'type-other';
                            $typeIcon = 'bi-question-circle';
                            $typeDisplay = 'Other';
                        }
                        
                        $avatar = '';
                        if (!empty($request['photo'])) {
                          $avatar = '<img src="../' . e($request['photo']) . '" alt="' . e($request['full_name']) . '">';
                        }
                        
                        $requestedTime = '';
                        if (!empty($request['start_time']) && !empty($request['end_time'])) {
                          $requestedTime = formatTime($request['start_time']) . ' - ' . formatTime($request['end_time']);
                        } elseif (!empty($request['start_time'])) {
                          $requestedTime = 'From: ' . formatTime($request['start_time']);
                        } else {
                          $requestedTime = 'Full Day';
                        }
                        
                        $originalRecord = '';
                        if (!empty($request['original_punch_in']) || !empty($request['original_punch_out'])) {
                          $originalRecord .= '<div>';
                          if (!empty($request['original_punch_in'])) {
                            $originalRecord .= 'In: ' . date('h:i A', strtotime($request['original_punch_in']));
                          }
                          if (!empty($request['original_punch_out'])) {
                            $originalRecord .= ' Out: ' . date('h:i A', strtotime($request['original_punch_out']));
                          }
                          $originalRecord .= '</div>';
                          if (!empty($request['original_status'])) {
                            $originalRecord .= '<div><small>Status: ' . ucfirst($request['original_status']) . '</small></div>';
                          }
                        } else {
                          $originalRecord = '<span class="text-muted">No record</span>';
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
                          <div class="fw-bold"><?php echo safeDate($request['exception_date'] ?? ''); ?></div>
                        </td>
                        <td>
                          <span class="type-badge <?php echo $typeClass; ?>">
                            <i class="bi <?php echo $typeIcon; ?> me-1"></i>
                            <?php echo $typeDisplay; ?>
                          </span>
                        </td>
                        <td>
                          <div class="time-slot"><?php echo $requestedTime; ?></div>
                        </td>
                        <td>
                          <div class="original-entry">
                            <?php echo $originalRecord; ?>
                          </div>
                        </td>
                        <td>
                          <div class="request-reason" title="<?php echo e($request['reason'] ?? ''); ?>">
                            <?php echo e($request['reason'] ?? '—'); ?>
                          </div>
                        </td>
                        <td>
                          <span class="badge-pill <?php echo e($statusClass); ?>">
                            <span class="mini-dot"></span> <?php echo e($statusLabel); ?>
                          </span>
                          <?php if (!empty($request['approved_by_name']) && $request['status'] === 'Approved'): ?>
                            <div><small class="text-muted">by <?php echo e($request['approved_by_name']); ?></small></div>
                            <div><small class="text-muted"><?php echo safeDateTime($request['approved_at'] ?? ''); ?></small></div>
                          <?php endif; ?>
                          <?php if (!empty($request['rejection_reason']) && $request['status'] === 'Rejected'): ?>
                            <div><small class="text-danger" title="<?php echo e($request['rejection_reason']); ?>"><?php echo e(substr($request['rejection_reason'], 0, 20)); ?>...</small></div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div class="d-flex gap-1">
                            <button type="button" class="action-btn" title="View Details" 
                                    onclick="viewRequestDetails(<?php echo htmlspecialchars(json_encode($request), ENT_QUOTES, 'UTF-8'); ?>)">
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
            <h5 class="modal-title fw-bold">Approve Regularization Request</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          
          <div class="modal-body">
            <p>Are you sure you want to approve regularization request for <span id="approveEmployeeName" class="fw-bold"></span>?</p>
            <p class="text-muted small">This will update the attendance record for the requested date.</p>
            
            <div class="mb-3">
              <label class="form-label fw-bold">Remarks (Optional)</label>
              <textarea name="remarks" class="form-control" rows="3" placeholder="Add any approval remarks..."></textarea>
            </div>
          </div>
          
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Approve Request</button>
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
            <h5 class="modal-title fw-bold">Reject Regularization Request</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          
          <div class="modal-body">
            <p>Are you sure you want to reject regularization request for <span id="rejectEmployeeName" class="fw-bold"></span>?</p>
            
            <div class="mb-3">
              <label class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span></label>
              <textarea name="remarks" class="form-control" rows="3" placeholder="Please provide reason for rejection..." required></textarea>
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

  <!-- View Details Modal -->
  <div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Regularization Request Details</h5>
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
      <?php if (!empty($regularizationRequests)): ?>
      $('#regularizationTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']], // Sort by date descending
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

    // View Request Details
    function viewRequestDetails(request) {
      const statusClass = request.status.toLowerCase() === 'approved' ? 'ontrack' : 
                         (request.status.toLowerCase() === 'pending' ? 'delayed' : 'atrisk');
      
      const typeLabels = {
        'vacation': 'Vacation',
        'remote-work': 'Remote Work',
        'field-work': 'Field Work',
        'other': 'Other'
      };
      
      const typeIcons = {
        'vacation': 'bi-umbrella',
        'remote-work': 'bi-house',
        'field-work': 'bi-truck',
        'other': 'bi-question-circle'
      };
      
      const typeDisplay = typeLabels[request.exception_type] || request.exception_type;
      const typeIcon = typeIcons[request.exception_type] || 'bi-question-circle';
      
      const requestedTime = request.start_time && request.end_time ? 
        `${new Date('1970-01-01T' + request.start_time).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})} - ${new Date('1970-01-01T' + request.end_time).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}` :
        (request.start_time ? `From: ${new Date('1970-01-01T' + request.start_time).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'})}` : 'Full Day');
      
      const originalPunch = request.original_punch_in || request.original_punch_out ? 
        `<div class="mt-2 p-2 bg-light rounded">
          <small class="text-muted">Original Record:</small><br>
          ${request.original_punch_in ? `<span class="fw-bold">In:</span> ${new Date(request.original_punch_in).toLocaleString('en-US', {hour: '2-digit', minute: '2-digit', day: '2-digit', month: 'short'})}<br>` : ''}
          ${request.original_punch_out ? `<span class="fw-bold">Out:</span> ${new Date(request.original_punch_out).toLocaleString('en-US', {hour: '2-digit', minute: '2-digit', day: '2-digit', month: 'short'})}<br>` : ''}
          ${request.original_status ? `<span class="fw-bold">Status:</span> ${request.original_status}` : ''}
        </div>` : '<div class="text-muted mt-2">No original attendance record</div>';
      
      const html = `
        <div class="row">
          <div class="col-md-6">
            <div class="mb-3">
              <label class="text-muted small fw-bold">Employee</label>
              <div class="fw-bold">${request.full_name || ''}</div>
              <div class="text-muted">${request.employee_code || ''} • ${request.department || ''} • ${request.designation || ''}</div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Request Type</label>
              <div><i class="bi ${typeIcon} me-2"></i>${typeDisplay}</div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Exception Date</label>
              <div class="fw-bold">${new Date(request.exception_date).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'})}</div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Requested Time</label>
              <div class="fw-bold">${requestedTime}</div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Reason</label>
              <div>${request.reason || '—'}</div>
            </div>
            
            ${originalPunch}
          </div>
          
          <div class="col-md-6">
            <div class="mb-3">
              <label class="text-muted small fw-bold">Status</label>
              <div><span class="badge-pill ${statusClass}"><span class="mini-dot"></span> ${request.status}</span></div>
            </div>
            
            <div class="mb-3">
              <label class="text-muted small fw-bold">Requested On</label>
              <div>${new Date(request.created_at).toLocaleString('en-IN', {day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'})}</div>
            </div>
            
            ${request.approved_by_name ? `
            <div class="mb-3">
              <label class="text-muted small fw-bold">Approved By</label>
              <div>${request.approved_by_name}</div>
              <div class="text-muted small">${new Date(request.approved_at).toLocaleString('en-IN', {day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'})}</div>
            </div>
            ` : ''}
            
            ${request.rejection_reason ? `
            <div class="mb-3">
              <label class="text-muted small fw-bold">Rejection Reason</label>
              <div class="text-danger">${request.rejection_reason}</div>
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