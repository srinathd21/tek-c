<?php
// my-leave-history.php
// ✅ Enhanced: Added filters, search, pagination, and detailed view
// ✅ Maintained same UI design
// ✅ Added year filter and status filter
// ✅ Added export option

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId   = (int)$_SESSION['employee_id'];
$employeeName = (string)($_SESSION['employee_name'] ?? '');

// ---------------- GET FILTER PARAMETERS ----------------
$filterYear = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

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

function statusBadgeMeta($status){
  $s = strtolower(trim((string)$status));
  if ($s === 'pending')  return ['Pending',  'status-yellow', 'bi-hourglass-split'];
  if ($s === 'approved') return ['Approved', 'status-green',  'bi-check2-circle'];
  if ($s === 'rejected') return ['Rejected', 'status-red',    'bi-x-circle'];
  return [($status ?: '—'), 'status-gray', 'bi-info-circle'];
}

// ---------------- BUILD QUERY WITH FILTERS ----------------
$whereConditions = ["employee_id = ?"];
$params = [$employeeId];
$types = "i";

if ($filterYear !== 'all' && $filterYear > 0) {
  $whereConditions[] = "YEAR(applied_at) = ?";
  $params[] = $filterYear;
  $types .= "i";
}

if ($filterStatus !== '' && $filterStatus !== 'all') {
  $whereConditions[] = "LOWER(status) = ?";
  $params[] = strtolower($filterStatus);
  $types .= "s";
}

if ($searchTerm !== '') {
  $whereConditions[] = "(leave_type LIKE ? OR reason LIKE ? OR CAST(id AS CHAR) LIKE ?)";
  $searchPattern = "%{$searchTerm}%";
  $params[] = $searchPattern;
  $params[] = $searchPattern;
  $params[] = $searchPattern;
  $types .= "sss";
}

$whereClause = implode(" AND ", $whereConditions);

// ---------------- GET AVAILABLE YEARS ----------------
$years = [];
$yearStmt = mysqli_prepare($conn, "SELECT DISTINCT YEAR(applied_at) as yr FROM leave_requests WHERE employee_id = ? ORDER BY yr DESC");
if ($yearStmt) {
  mysqli_stmt_bind_param($yearStmt, "i", $employeeId);
  mysqli_stmt_execute($yearStmt);
  $yearRes = mysqli_stmt_get_result($yearStmt);
  while ($row = mysqli_fetch_assoc($yearRes)) {
    $years[] = $row['yr'];
  }
  mysqli_stmt_close($yearStmt);
}

// ---------------- FETCH LEAVE HISTORY WITH FILTERS ----------------
$leaveHistory = [];
$totalRecords = 0;

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM leave_requests WHERE {$whereClause}";
$countStmt = mysqli_prepare($conn, $countSql);
if ($countStmt) {
  mysqli_stmt_bind_param($countStmt, $types, ...$params);
  mysqli_stmt_execute($countStmt);
  $countRes = mysqli_stmt_get_result($countStmt);
  $totalRecords = mysqli_fetch_assoc($countRes)['total'];
  mysqli_stmt_close($countStmt);
}

// Fetch paginated results
$sql = "
  SELECT id, leave_type, from_date, to_date, total_days, reason, status, applied_at
  FROM leave_requests
  WHERE {$whereClause}
  ORDER BY applied_at DESC
  LIMIT ? OFFSET ?
";
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$st = mysqli_prepare($conn, $sql);
if ($st) {
  mysqli_stmt_bind_param($st, $types, ...$params);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $leaveHistory = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- LEAVE STATS ----------------
$leaveStats = [
  'total_requests' => 0,
  'total_days'     => 0,
  'pending'        => 0,
  'approved'       => 0,
  'rejected'       => 0,
];

// Get all stats (without filters for summary)
$statsStmt = mysqli_prepare($conn, "
  SELECT 
    COUNT(*) as total_requests,
    SUM(total_days) as total_days,
    SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) as rejected
  FROM leave_requests
  WHERE employee_id = ?
");
if ($statsStmt) {
  mysqli_stmt_bind_param($statsStmt, "i", $employeeId);
  mysqli_stmt_execute($statsStmt);
  $statsRes = mysqli_stmt_get_result($statsStmt);
  $stats = mysqli_fetch_assoc($statsRes);
  $leaveStats = [
    'total_requests' => (int)($stats['total_requests'] ?? 0),
    'total_days'     => (float)($stats['total_days'] ?? 0),
    'pending'        => (int)($stats['pending'] ?? 0),
    'approved'       => (int)($stats['approved'] ?? 0),
    'rejected'       => (int)($stats['rejected'] ?? 0),
  ];
  mysqli_stmt_close($statsStmt);
}
$leaveStats['total_days'] = rtrim(rtrim(number_format((float)$leaveStats['total_days'], 1, '.', ''), '0'), '.');

// Calculate pagination
$totalPages = ceil($totalRecords / $perPage);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Leave History - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:16px 12px 14px; }
    .panel{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      box-shadow:0 10px 30px rgba(17,24,39,.05);
      padding:12px;
      margin-bottom:12px;
    }

    .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .h-title{ margin:0; font-weight:1000; color:#111827; line-height:1.1; }
    .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

    .sec-head{
      display:flex; align-items:center; gap:10px;
      padding:10px 12px;
      border-radius:14px;
      background:#f9fafb;
      border:1px solid #eef2f7;
      margin-bottom:10px;
    }
    .sec-ic{
      width:34px; height:34px; border-radius:12px;
      display:grid; place-items:center;
      background: rgba(45,156,219,.12);
      color: var(--blue, #2d9cdb);
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

    /* Stats cards */
    .stat-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      box-shadow:0 10px 30px rgba(17,24,39,.05);
      padding:12px 14px;
      height:78px;
      display:flex;
      align-items:center;
      gap:12px;
      transition: all 0.2s;
    }
    .stat-card:hover{
      transform: translateY(-2px);
      box-shadow:0 15px 35px rgba(17,24,39,.1);
    }
    .stat-ic{
      width:42px; height:42px;
      border-radius:14px;
      display:grid; place-items:center;
      color:#fff; font-size:18px;
      flex:0 0 auto;
    }
    .stat-ic.blue{ background: var(--blue, #2d9cdb); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-ic.gray{ background: #64748b; }
    .stat-label{ color:#4b5563; font-weight:850; font-size:12px; }
    .stat-value{ font-size:22px; font-weight:1000; line-height:1; margin-top:2px; }

    /* Filter Bar */
    .filter-bar{
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 16px;
      align-items: flex-end;
    }
    .filter-group{
      flex: 1;
      min-width: 150px;
    }
    .filter-label{
      font-size: 11px;
      font-weight: 900;
      color: #6b7280;
      margin-bottom: 4px;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }
    .filter-select, .filter-input{
      border: 2px solid #e5e7eb;
      border-radius: 12px;
      padding: 8px 12px;
      font-size: 13px;
      font-weight: 800;
      width: 100%;
      background: #fff;
    }
    .filter-select:focus, .filter-input:focus{
      border-color: var(--blue, #2d9cdb);
      outline: none;
    }
    .btn-filter{
      background: var(--blue, #2d9cdb);
      border: none;
      border-radius: 12px;
      padding: 8px 20px;
      font-weight: 900;
      font-size: 13px;
      color: #fff;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-filter:hover{
      background: #2a8bc9;
      transform: translateY(-1px);
    }
    .btn-reset{
      background: #f3f4f6;
      border: 2px solid #e5e7eb;
      border-radius: 12px;
      padding: 8px 20px;
      font-weight: 900;
      font-size: 13px;
      color: #4b5563;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-reset:hover{
      background: #e5e7eb;
    }
    .btn-export{
      background: #10b981;
      border: none;
      border-radius: 12px;
      padding: 8px 20px;
      font-weight: 900;
      font-size: 13px;
      color: #fff;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-export:hover{
      background: #059669;
    }

    /* Table */
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:900;
      border-bottom:1px solid #e5e7eb!important;
      white-space: nowrap;
      background:#f9fafb;
    }
    .table td{
      font-weight:800; color:#111827;
      vertical-align: top;
      word-break: break-word;
    }

    /* Status badge */
    .status-badge{
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 1000;
      letter-spacing: .3px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space: nowrap;
      text-transform: uppercase;
      border:1px solid transparent;
    }
    .status-green{ background: rgba(16,185,129,.12); color:#10b981; border-color: rgba(16,185,129,.22); }
    .status-yellow{ background: rgba(245,158,11,.12); color:#f59e0b; border-color: rgba(245,158,11,.22); }
    .status-red{ background: rgba(239,68,68,.12); color:#ef4444; border-color: rgba(239,68,68,.22); }
    .status-gray{ background: rgba(100,116,139,.12); color:#64748b; border-color: rgba(100,116,139,.22); }

    /* Mobile cards */
    .leave-card{
      border:1px solid #e5e7eb;
      border-radius:16px;
      background:#fff;
      box-shadow:0 10px 30px rgba(17,24,39,.05);
      padding:12px;
      transition: all 0.2s;
    }
    .leave-card:hover{
      transform: translateY(-2px);
      box-shadow:0 15px 35px rgba(17,24,39,.1);
    }
    .leave-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }
    .leave-title{
      font-weight:1000;
      color:#111827;
      font-size:14px;
      line-height:1.2;
      margin:0;
    }
    .leave-sub{
      margin-top:6px;
      color:#6b7280;
      font-weight:800;
      font-size:12px;
      line-height:1.2;
    }
    .leave-kv{ margin-top:10px; display:grid; gap:8px; }
    .leave-row{ display:flex; gap:10px; align-items:flex-start; }
    .leave-key{ flex:0 0 88px; color:#6b7280; font-weight:1000; font-size:12px; }
    .leave-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; }

    /* Pagination */
    .pagination-custom{
      display: flex;
      justify-content: center;
      gap: 8px;
      margin-top: 20px;
      flex-wrap: wrap;
    }
    .page-link-custom{
      padding: 8px 14px;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      background: #fff;
      color: #4b5563;
      font-weight: 800;
      font-size: 13px;
      text-decoration: none;
      transition: all 0.2s;
    }
    .page-link-custom:hover{
      background: var(--blue, #2d9cdb);
      border-color: var(--blue, #2d9cdb);
      color: #fff;
    }
    .page-link-custom.active{
      background: var(--blue, #2d9cdb);
      border-color: var(--blue, #2d9cdb);
      color: #fff;
    }
    .page-link-custom.disabled{
      opacity: 0.5;
      pointer-events: none;
    }

    @media (max-width: 991.98px){
      .main{ margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
      .sidebar{ position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
    }
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
      .sec-head { padding: 10px !important; border-radius: 12px; }
      .filter-bar { flex-direction: column; }
      .filter-group { width: 100%; }
      .btn-filter, .btn-reset, .btn-export { width: 100%; justify-content: center; }
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

        <div class="title-row mb-3">
          <div>
            <h1 class="h-title">My Leave History</h1>
            <p class="h-sub">View and filter your past leave applications</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
            <a href="apply-leave.php" class="badge-pill text-decoration-none" style="background: var(--blue, #2d9cdb); color: #fff; padding:5px; border-radius:10px;">
              <i class="bi bi-plus-lg"></i> Apply New Leave
            </a>
          </div>
        </div>

        <!-- Stats Cards -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-inboxes"></i></div>
              <div>
                <div class="stat-label">Total Requests</div>
                <div class="stat-value"><?php echo e($leaveStats['total_requests']); ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic gray"><i class="bi bi-calendar2-week"></i></div>
              <div>
                <div class="stat-label">Total Leave Days</div>
                <div class="stat-value"><?php echo e($leaveStats['total_days']); ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-2">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-hourglass-split"></i></div>
              <div>
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?php echo e($leaveStats['pending']); ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-2">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
              <div>
                <div class="stat-label">Approved</div>
                <div class="stat-value"><?php echo e($leaveStats['approved']); ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-2">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
              <div>
                <div class="stat-label">Rejected</div>
                <div class="stat-value"><?php echo e($leaveStats['rejected']); ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filter Bar -->
        <div class="panel">
          <form method="GET" id="filterForm" class="filter-bar">
            <div class="filter-group">
              <div class="filter-label">Year</div>
              <select name="year" class="filter-select">
                <option value="all">All Years</option>
                <?php foreach ($years as $year): ?>
                  <option value="<?php echo $year; ?>" <?php echo ($filterYear == $year) ? 'selected' : ''; ?>>
                    <?php echo $year; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-group">
              <div class="filter-label">Status</div>
              <select name="status" class="filter-select">
                <option value="all">All Status</option>
                <option value="pending" <?php echo ($filterStatus == 'pending') ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo ($filterStatus == 'approved') ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo ($filterStatus == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
              </select>
            </div>

            <div class="filter-group">
              <div class="filter-label">Search</div>
              <input type="text" name="search" class="filter-input" placeholder="ID, Type, or Reason..." value="<?php echo e($searchTerm); ?>">
            </div>

            <div class="filter-group" style="flex: 0 0 auto;">
              <button type="submit" class="btn-filter">
                <i class="bi bi-funnel"></i> Apply Filters
              </button>
            </div>

            <div class="filter-group" style="flex: 0 0 auto;">
              <a href="my-leave-history.php" class="btn-reset text-decoration-none">
                <i class="bi bi-arrow-repeat"></i> Reset
              </a>
            </div>

            <div class="filter-group" style="flex: 0 0 auto;">
              <button type="button" class="btn-export" onclick="exportToCSV()">
                <i class="bi bi-download"></i> Export
              </button>
            </div>
          </form>
        </div>

        <!-- Leave History -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Leave History</p>
              <p class="sec-sub mb-0">
                Showing <?php echo count($leaveHistory); ?> of <?php echo $totalRecords; ?> records
                <?php if ($filterYear !== 'all' && $filterYear > 0): ?> • Year: <?php echo $filterYear; ?><?php endif; ?>
                <?php if ($filterStatus !== '' && $filterStatus !== 'all'): ?> • Status: <?php echo ucfirst($filterStatus); ?><?php endif; ?>
                <?php if ($searchTerm !== ''): ?> • Search: "<?php echo e($searchTerm); ?>"<?php endif; ?>
              </p>
            </div>
          </div>

          <?php if (empty($leaveHistory)): ?>
            <div class="text-muted text-center py-4" style="font-weight:900;">
              <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
              No leave history found matching your filters.
            </div>
          <?php else: ?>

            <!-- MOBILE: Cards -->
            <div class="d-block d-md-none">
              <div class="d-grid gap-3">
                <?php foreach ($leaveHistory as $leave): ?>
                  <?php
                    [$stLabel, $stClass, $stIcon] = statusBadgeMeta($leave['status'] ?? '');
                    $leaveType = trim((string)($leave['leave_type'] ?? 'Leave'));
                    $id = (int)($leave['id'] ?? 0);
                    $fromD = safeDate($leave['from_date'] ?? '');
                    $toD   = safeDate($leave['to_date'] ?? '');
                    $days  = $leave['total_days'] ?? '—';
                    $applied = safeDateTime($leave['applied_at'] ?? '');
                    $reason  = trim((string)($leave['reason'] ?? ''));
                  ?>
                  <div class="leave-card">
                    <div class="leave-top">
                      <div style="flex:1 1 auto;">
                        <div class="leave-title">
                          <?php echo e($leaveType); ?> 
                          <span class="small text-muted" style="font-weight:900;">#<?php echo $id; ?></span>
                        </div>
                        <div class="leave-sub">
                          <i class="bi bi-calendar-event"></i> <?php echo e($fromD); ?> → <?php echo e($toD); ?>
                          &nbsp;•&nbsp; <b style="color:#111827;"><?php echo e($days); ?></b> day(s)
                        </div>
                      </div>
                      <span class="status-badge <?php echo e($stClass); ?>">
                        <i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?>
                      </span>
                    </div>

                    <div class="leave-kv">
                      <div class="leave-row">
                        <div class="leave-key">Applied</div>
                        <div class="leave-val"><?php echo e($applied); ?></div>
                      </div>

                      <div class="leave-row">
                        <div class="leave-key">Reason</div>
                        <div class="leave-val"><?php echo $reason !== '' ? e($reason) : '—'; ?></div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- DESKTOP: Table -->
            <div class="d-none d-md-block">
              <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                  <thead>
                    <tr>
                      <th style="width:70px;">ID</th>
                      <th style="width:90px;">Type</th>
                      <th style="width:100px;">From</th>
                      <th style="width:100px;">To</th>
                      <th style="width:90px;">Total Days</th>
                      <th style="width:120px;">Status</th>
                      <th style="width:160px;">Applied At</th>
                      <th>Reason</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($leaveHistory as $leave): ?>
                      <?php
                        [$stLabel, $stClass, $stIcon] = statusBadgeMeta($leave['status'] ?? '');
                      ?>
                      <tr>
                        <td style="font-weight:1000;">#<?php echo (int)$leave['id']; ?></td>
                        <td><?php echo e($leave['leave_type']); ?></td>
                        <td><?php echo e(safeDate($leave['from_date'])); ?></td>
                        <td><?php echo e(safeDate($leave['to_date'])); ?></td>
                        <td><?php echo e($leave['total_days']); ?></td>
                        <td>
                          <span class="status-badge <?php echo e($stClass); ?>">
                            <i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?>
                          </span>
                        </td>
                        <td><?php echo e(safeDateTime($leave['applied_at'])); ?></td>
                        <td><?php echo e($leave['reason']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-custom">
              <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>" class="page-link-custom">
                  <i class="bi bi-chevron-left"></i> Previous
                </a>
              <?php else: ?>
                <span class="page-link-custom disabled">Previous</span>
              <?php endif; ?>

              <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++):
              ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                   class="page-link-custom <?php echo ($i == $page) ? 'active' : ''; ?>">
                  <?php echo $i; ?>
                </a>
              <?php endfor; ?>

              <?php if ($page < $totalPages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>" class="page-link-custom">
                  Next <i class="bi bi-chevron-right"></i>
                </a>
              <?php else: ?>
                <span class="page-link-custom disabled">Next</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          <?php endif; ?>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
function exportToCSV() {
  // Get current filter values
  const year = document.querySelector('select[name="year"]').value;
  const status = document.querySelector('select[name="status"]').value;
  const search = document.querySelector('input[name="search"]').value;
  
  // Build export URL
  let url = 'export-leave-history.php?';
  if (year !== 'all') url += `year=${year}&`;
  if (status !== 'all') url += `status=${status}&`;
  if (search) url += `search=${encodeURIComponent(search)}&`;
  
  // Open in new window or trigger download
  window.location.href = url;
}

// Add animation to stat cards on load
document.addEventListener('DOMContentLoaded', function() {
  const statCards = document.querySelectorAll('.stat-card');
  statCards.forEach((card, index) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    setTimeout(() => {
      card.style.transition = 'all 0.3s ease';
      card.style.opacity = '1';
      card.style.transform = 'translateY(0)';
    }, index * 100);
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