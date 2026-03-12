<?php
// leave-app-rej-list.php — Manager Leave Approve / Reject List (corrected + DPR-style panels, existing stats design kept)

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------- AUTH ----------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$managerId   = (int)$_SESSION['employee_id'];
$managerName = $_SESSION['employee_name'] ?? ($_SESSION['name'] ?? 'Manager');

$success = '';
$error   = '';

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * mysqli_stmt_bind_param helper for dynamic params (by-reference safe)
 */
function stmt_bind_params(mysqli_stmt $stmt, string $types, array $params): bool {
    $bind = [$stmt, $types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    return call_user_func_array('mysqli_stmt_bind_param', $bind);
}

function buildCurrentFilterQuery(): string {
    $allowedStatus = ['All','Pending','Approved','Rejected','Completed'];
    $allowedPeriod = ['this_month','this_year','all_time','custom'];
    $allowedTypes  = ['All','Annual','Sick','Casual','Unpaid'];

    $status = $_GET['status'] ?? 'All';
    if (!in_array($status, $allowedStatus, true)) $status = 'All';

    $period = $_GET['period'] ?? 'this_month';
    if (!in_array($period, $allowedPeriod, true)) $period = 'this_month';

    $type = $_GET['leave_type'] ?? 'All';
    if (!in_array($type, $allowedTypes, true)) $type = 'All';

    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';

    if ($period !== 'custom') { $from = ''; $to = ''; }

    $q = [
        'status'    => $status,
        'period'    => $period,
        'leave_type'=> $type
    ];

    if ($period === 'custom') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $q['from'] = $from;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $q['to']   = $to;
    }

    return http_build_query($q);
}

// ---------- Flash messages (PRG) ----------
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ---------- FILTERS (GET) ----------
$allowedStatus = ['All','Pending','Approved','Rejected','Completed'];
$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, $allowedStatus, true)) $statusFilter = 'All';

$allowedPeriod = ['this_month','this_year','all_time','custom'];
$periodFilter = $_GET['period'] ?? 'this_month';
if (!in_array($periodFilter, $allowedPeriod, true)) $periodFilter = 'this_month';

$fromFilter = $_GET['from'] ?? '';
$toFilter   = $_GET['to'] ?? '';

$allowedTypes = ['All','Annual','Sick','Casual','Unpaid'];
$typeFilter = $_GET['leave_type'] ?? 'All';
if (!in_array($typeFilter, $allowedTypes, true)) $typeFilter = 'All';

// sanitize custom dates
if ($periodFilter !== 'custom') { $fromFilter = ''; $toFilter = ''; }
if ($periodFilter === 'custom') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromFilter)) $fromFilter = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toFilter))   $toFilter = '';
    if ($fromFilter && $toFilter && $fromFilter > $toFilter) {
        $tmp = $fromFilter; $fromFilter = $toFilter; $toFilter = $tmp;
    }
}

// ---------- Handle Approve / Reject ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';
    $filterQs = buildCurrentFilterQuery();
    $redirectUrl = "leave-app-rej-list.php" . ($filterQs ? ("?".$filterQs) : "");

    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $_SESSION['flash_error'] = "Security check failed. Please try again.";
        header("Location: " . $redirectUrl);
        exit;
    }

    $action    = $_POST['action'] ?? '';
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $remarks   = trim($_POST['remarks'] ?? '');

    if ($requestId <= 0 || !in_array($action, ['approve','reject'], true)) {
        $_SESSION['flash_error'] = "Invalid request.";
        header("Location: " . $redirectUrl);
        exit;
    }

    // Ensure request belongs to this manager and is pending
    $st = mysqli_prepare($conn, "
        SELECT id, status
        FROM employee_requests
        WHERE id = ? AND manager_id = ?
        LIMIT 1
    ");
    if (!$st) {
        $_SESSION['flash_error'] = "DB error (prepare fetch).";
        header("Location: " . $redirectUrl);
        exit;
    }

    mysqli_stmt_bind_param($st, "ii", $requestId, $managerId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);

    if (!$row) {
        $_SESSION['flash_error'] = "Request not found (or not assigned to you).";
        header("Location: " . $redirectUrl);
        exit;
    }

    if (($row['status'] ?? '') !== 'Pending') {
        $_SESSION['flash_error'] = "This request is already processed.";
        header("Location: " . $redirectUrl);
        exit;
    }

    if ($action === 'approve') {
        $upd = mysqli_prepare($conn, "
            UPDATE employee_requests
            SET status = 'Approved',
                approver_remarks = ?,
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ? AND manager_id = ? AND status = 'Pending'
        ");
        if (!$upd) {
            $_SESSION['flash_error'] = "DB error (prepare approve).";
            header("Location: " . $redirectUrl);
            exit;
        }

        mysqli_stmt_bind_param($upd, "siii", $remarks, $managerId, $requestId, $managerId);

        if (mysqli_stmt_execute($upd)) {
            if (mysqli_stmt_affected_rows($upd) > 0) {
                $_SESSION['flash_success'] = "Request approved successfully.";
            } else {
                $_SESSION['flash_error'] = "Request could not be approved (already updated).";
            }
        } else {
            $_SESSION['flash_error'] = "Failed to approve: " . mysqli_stmt_error($upd);
        }
        mysqli_stmt_close($upd);

    } else { // reject

        if ($remarks === '') {
            $_SESSION['flash_error'] = "Rejection remark is required.";
            header("Location: " . $redirectUrl);
            exit;
        }

        $upd = mysqli_prepare($conn, "
            UPDATE employee_requests
            SET status = 'Rejected',
                approver_remarks = ?,
                rejected_by = ?,
                rejected_at = NOW(),
                rejection_reason = ?
            WHERE id = ? AND manager_id = ? AND status = 'Pending'
        ");
        if (!$upd) {
            $_SESSION['flash_error'] = "DB error (prepare reject).";
            header("Location: " . $redirectUrl);
            exit;
        }

        mysqli_stmt_bind_param($upd, "sisii", $remarks, $managerId, $remarks, $requestId, $managerId);

        if (mysqli_stmt_execute($upd)) {
            if (mysqli_stmt_affected_rows($upd) > 0) {
                $_SESSION['flash_success'] = "Request rejected successfully.";
            } else {
                $_SESSION['flash_error'] = "Request could not be rejected (already updated).";
            }
        } else {
            $_SESSION['flash_error'] = "Failed to reject: " . mysqli_stmt_error($upd);
        }
        mysqli_stmt_close($upd);
    }

    header("Location: " . $redirectUrl);
    exit;
}

// ---------- Build WHERE for filters ----------
$where  = " WHERE r.request_type = 'Leave' AND r.manager_id = ? ";
$params = [$managerId];
$types  = "i";

if ($statusFilter !== 'All') {
    $where .= " AND r.status = ? ";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($typeFilter !== 'All') {
    $where .= " AND r.leave_type = ? ";
    $params[] = $typeFilter;
    $types .= "s";
}

if ($periodFilter === 'this_month') {
    $where .= " AND YEAR(r.request_date) = YEAR(CURDATE()) AND MONTH(r.request_date) = MONTH(CURDATE()) ";
} elseif ($periodFilter === 'this_year') {
    $where .= " AND YEAR(r.request_date) = YEAR(CURDATE()) ";
} elseif ($periodFilter === 'custom') {
    if ($fromFilter) { $where .= " AND r.request_date >= ? "; $params[] = $fromFilter; $types .= "s"; }
    if ($toFilter)   { $where .= " AND r.request_date <= ? "; $params[] = $toFilter;   $types .= "s"; }
}

// ---------- THIS MONTH SUMMARY (always this month; no filters) ----------
$summary = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
$st = mysqli_prepare($conn, "
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Pending') AS pending,
        SUM(status = 'Approved') AS approved,
        SUM(status = 'Rejected') AS rejected
    FROM employee_requests
    WHERE request_type = 'Leave'
      AND manager_id = ?
      AND YEAR(request_date) = YEAR(CURDATE())
      AND MONTH(request_date) = MONTH(CURDATE())
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $managerId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);

    if ($row) {
        $summary['total']    = (int)($row['total'] ?? 0);
        $summary['pending']  = (int)($row['pending'] ?? 0);
        $summary['approved'] = (int)($row['approved'] ?? 0);
        $summary['rejected'] = (int)($row['rejected'] ?? 0);
    }
}

// ---------- Fetch Requests for Manager (with filters) ----------
$requests = [];

$sql = "
    SELECT
        r.id, r.request_no, r.request_subject, r.request_date, r.status,
        r.priority, r.attachments,
        r.leave_type, r.leave_from_date, r.leave_to_date,
        r.employee_id,
        r.approver_remarks, r.rejection_reason, r.approved_at, r.rejected_at,
        e.full_name AS employee_name, e.designation AS employee_designation
    FROM employee_requests r
    INNER JOIN employees e ON e.id = r.employee_id
    $where
    ORDER BY
      (r.status = 'Pending') DESC,
      r.request_date DESC,
      r.id DESC
";

$st = mysqli_prepare($conn, $sql);
if ($st) {
    if (!stmt_bind_params($st, $types, $params)) {
        $error = "DB error (bind params).";
    } else {
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        while ($r = mysqli_fetch_assoc($res)) $requests[] = $r;
    }
    mysqli_stmt_close($st);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Approve Leave Requests - <?php echo e($managerName); ?></title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    /* DPR-style shell */
    .panel{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      box-shadow:0 10px 30px rgba(17,24,39,.05);
      padding:16px;
      margin-bottom:14px;
    }
    .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .h-title{ margin:0; font-weight:1000; color:#111827; }
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
      width:34px;height:34px;border-radius:12px;
      display:grid;place-items:center;
      background: rgba(45,156,219,.12);
      color: var(--blue);
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

    .badge-pill-top{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
    }

    .btn-primary-tek{
      background: var(--blue);
      border:none;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 1000;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
      color:#fff;
      text-decoration:none;
    }
    .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }

    .btn-outline-tek{
      border:1px solid #d1d5db;
      background:#fff;
      color:#374151;
      border-radius:12px;
      padding:10px 16px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      gap:8px;
      text-decoration:none;
    }
    .btn-outline-tek:hover{ background:#f9fafb; color:#111827; }

    /* KEEP your existing stats design */
    .stat-card{
      background: var(--surface); border:1px solid var(--border); border-radius: var(--radius);
      box-shadow: var(--shadow); padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px;
    }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.orange{ background: var(--orange); }
    .stat-ic.green{ background: var(--green); }
    .stat-ic.red{ background: var(--red); }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table thead th{
      font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800;
      border-bottom:1px solid var(--border)!important;
      background:#f9fafb;
      white-space:nowrap;
    }
    .table td{
      vertical-align:top; border-color: var(--border); font-weight:650; color:#374151;
      padding-top:14px; padding-bottom:14px;
    }

    .badge-pill{
      border-radius:999px; padding:8px 12px; font-weight:900; font-size:12px; border:1px solid transparent;
      display:inline-flex; align-items:center; gap:8px;
    }
    .badge-pill .mini-dot{ width:8px; height:8px; border-radius:50%; background: currentColor; opacity:.9; }

    .pending{ color:#9a3412; background: rgba(242,153,74,.14); border-color: rgba(242,153,74,.20); }
    .approved{ color: var(--green); background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.18); }
    .rejected{ color: var(--red); background: rgba(235,87,87,.12); border-color: rgba(235,87,87,.18); }
    .otherst{ color:#374151; background:#f3f4f6; border-color:#e5e7eb; }

    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

    .btn-approve{ background: var(--green); border:none; color:#fff; font-weight:900; border-radius:10px; padding:10px 14px; }
    .btn-reject{ background: var(--red); border:none; color:#fff; font-weight:900; border-radius:10px; padding:10px 14px; }
    .btn-approve:hover,.btn-reject:hover{ color:#fff; filter:brightness(.96); }

    .remarks{ border:2px solid var(--border); border-radius:10px; padding:10px 12px; font-weight:650; width:100%; }
    .remarks:focus{ outline:none; border-color: var(--blue); box-shadow:0 0 0 3px rgba(37,99,235,0.10); }

    .filter-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      box-shadow:0 10px 30px rgba(17,24,39,.05);
      padding:16px;
      margin-bottom:14px;
    }

    .form-label{ font-weight:900; color:#374151; font-size:13px; }
    .form-control, .form-select{
      border:2px solid #e5e7eb;
      border-radius:12px;
      padding:10px 12px;
      font-weight:750;
      font-size:14px;
    }

    .small-muted{ color:#6b7280; font-size:12px; font-weight:650; }
    .remarks-box{
      background:#f8fafc;
      border:1px solid var(--border);
      border-radius:12px;
      padding:10px 12px;
      margin-top:8px;
    }

    .priority-pill{
      display:inline-flex; align-items:center; gap:6px;
      padding:4px 8px; border-radius:999px; border:1px solid #e5e7eb;
      background:#fff; font-weight:900; font-size:11px;
    }

    @media (max-width: 992px){
      .action-cell{ min-width:280px; }
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

        <!-- Header -->
        <div class="title-row mb-3">
          <div>
            <h1 class="h-title">Leave Requests</h1>
            <p class="h-sub">Approve / Reject leave requests with filters and summary</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill-top"><i class="bi bi-person-gear"></i> <?php echo e($managerName); ?></span>
            <a href="dashboard.php" class="btn-outline-tek">
              <i class="bi bi-arrow-left"></i> Back
            </a>
          </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Summary Cards (existing design kept) -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-bar-chart"></i></div>
            <div>
              <p class="sec-title mb-0">This Month Summary</p>
              <p class="sec-sub mb-0">Manager-wise leave requests for current month</p>
            </div>
          </div>

          <div class="row g-3 mb-0">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-list-check"></i></div>
                <div>
                  <div class="stat-label">This Month Total</div>
                  <div class="stat-value"><?php echo (int)$summary['total']; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
                <div>
                  <div class="stat-label">Pending</div>
                  <div class="stat-value"><?php echo (int)$summary['pending']; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                <div>
                  <div class="stat-label">Approved</div>
                  <div class="stat-value"><?php echo (int)$summary['approved']; ?></div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                <div>
                  <div class="stat-label">Rejected</div>
                  <div class="stat-value"><?php echo (int)$summary['rejected']; ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-funnel"></i></div>
            <div>
              <p class="sec-title mb-0">Filters</p>
              <p class="sec-sub mb-0">Filter by status, leave type and period</p>
            </div>
          </div>

          <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach ($allowedStatus as $s): ?>
                  <option value="<?php echo e($s); ?>" <?php echo ($statusFilter === $s) ? 'selected' : ''; ?>>
                    <?php echo e($s); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Leave Type</label>
              <select name="leave_type" class="form-select">
                <?php foreach ($allowedTypes as $t): ?>
                  <option value="<?php echo e($t); ?>" <?php echo ($typeFilter === $t) ? 'selected' : ''; ?>>
                    <?php echo e($t); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Period</label>
              <select name="period" id="periodSelect" class="form-select">
                <option value="this_month" <?php echo ($periodFilter==='this_month')?'selected':''; ?>>This Month</option>
                <option value="this_year"  <?php echo ($periodFilter==='this_year')?'selected':''; ?>>This Year</option>
                <option value="all_time"   <?php echo ($periodFilter==='all_time')?'selected':''; ?>>All Time</option>
                <option value="custom"     <?php echo ($periodFilter==='custom')?'selected':''; ?>>Custom</option>
              </select>
            </div>

            <div class="col-6 col-md-1">
              <label class="form-label">From</label>
              <input type="date" name="from" id="fromDate" class="form-control" value="<?php echo e($fromFilter); ?>">
            </div>

            <div class="col-6 col-md-1">
              <label class="form-label">To</label>
              <input type="date" name="to" id="toDate" class="form-control" value="<?php echo e($toFilter); ?>">
            </div>

            <div class="col-12 col-md-1 d-grid">
              <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
            </div>

            <div class="col-12">
              <a class="btn btn-outline-secondary btn-sm mt-2" href="leave-app-rej-list.php">Reset Filters</a>
            </div>
          </form>
        </div>

        <!-- Request List -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-table"></i></div>
            <div>
              <p class="sec-title mb-0">Requests List</p>
              <p class="sec-sub mb-0">Pending requests are shown first</p>
            </div>
          </div>

          <?php if (empty($requests)): ?>
            <div class="text-muted" style="font-weight:800;">No leave requests found for selected filters.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th style="min-width:170px;">Request No</th>
                    <th style="min-width:220px;">Employee</th>
                    <th style="min-width:220px;">Subject</th>
                    <th style="min-width:120px;">Leave Type</th>
                    <th style="min-width:130px;">From</th>
                    <th style="min-width:130px;">To</th>
                    <th style="min-width:140px;">Status</th>
                    <th style="min-width:290px;">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                  <?php
                    $badgeClass = 'otherst';
                    if (($r['status'] ?? '') === 'Pending')  $badgeClass = 'pending';
                    if (($r['status'] ?? '') === 'Approved') $badgeClass = 'approved';
                    if (($r['status'] ?? '') === 'Rejected') $badgeClass = 'rejected';
                  ?>
                  <tr>
                    <td>
                      <div class="mono fw-bold"><?php echo e($r['request_no']); ?></div>
                      <div class="small-muted mt-1">Date: <?php echo e($r['request_date']); ?></div>
                    </td>

                    <td>
                      <div class="fw-bold"><?php echo e($r['employee_name']); ?></div>
                      <div class="text-muted small"><?php echo e($r['employee_designation'] ?? ''); ?></div>
                    </td>

                    <td>
                      <div class="fw-bold"><?php echo e($r['request_subject']); ?></div>
                      <div class="small-muted mt-1">
                        <span class="priority-pill"><i class="bi bi-flag"></i> <?php echo e($r['priority'] ?? 'Medium'); ?></span>
                      </div>

                      <?php if (!empty($r['approver_remarks']) || !empty($r['rejection_reason'])): ?>
                        <div class="remarks-box">
                          <?php if (!empty($r['approver_remarks'])): ?>
                            <div><strong>Remarks:</strong> <?php echo e($r['approver_remarks']); ?></div>
                          <?php endif; ?>
                          <?php if (!empty($r['rejection_reason'])): ?>
                            <div class="mt-1"><strong>Reason:</strong> <?php echo e($r['rejection_reason']); ?></div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td><?php echo e($r['leave_type'] ?? '-'); ?></td>
                    <td><?php echo e($r['leave_from_date'] ?? '-'); ?></td>
                    <td><?php echo e($r['leave_to_date'] ?? '-'); ?></td>

                    <td>
                      <span class="badge-pill <?php echo e($badgeClass); ?>">
                        <span class="mini-dot"></span> <?php echo e($r['status']); ?>
                      </span>

                      <?php if (!empty($r['approved_at']) && ($r['status'] ?? '') === 'Approved'): ?>
                        <div class="small-muted mt-1">Approved: <?php echo e($r['approved_at']); ?></div>
                      <?php endif; ?>

                      <?php if (!empty($r['rejected_at']) && ($r['status'] ?? '') === 'Rejected'): ?>
                        <div class="small-muted mt-1">Rejected: <?php echo e($r['rejected_at']); ?></div>
                      <?php endif; ?>
                    </td>

                    <td class="action-cell">
                      <?php if (!empty($r['attachments'])): ?>
                        <a class="btn btn-sm btn-outline-primary me-2 mb-2" href="<?php echo e($r['attachments']); ?>" target="_blank" title="Attachment">
                          <i class="bi bi-paperclip"></i> Attachment
                        </a>
                      <?php endif; ?>

                      <?php if (($r['status'] ?? '') === 'Pending'): ?>
                        <form method="POST" class="d-grid gap-2">
                          <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                          <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">

                          <textarea name="remarks" class="remarks" rows="2" placeholder="Remarks (required for reject)"></textarea>

                          <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" name="action" value="approve" class="btn-approve">
                              <i class="bi bi-check2-circle"></i> Approve
                            </button>
                            <button type="submit" name="action" value="reject" class="btn-reject">
                              <i class="bi bi-x-circle"></i> Reject
                            </button>
                          </div>
                        </form>
                      <?php else: ?>
                        <span class="text-muted small">Processed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
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
  // Enable custom date inputs only when period=custom
  document.addEventListener('DOMContentLoaded', function () {
    const period = document.getElementById('periodSelect');
    const from = document.getElementById('fromDate');
    const to = document.getElementById('toDate');

    function toggleDates(){
      const isCustom = (period && period.value === 'custom');
      if (from) from.disabled = !isCustom;
      if (to) to.disabled = !isCustom;
    }

    if (period) {
      period.addEventListener('change', toggleDates);
      toggleDates();
    }
  });
</script>
</body>
</html>

<?php
if (isset($conn) && $conn instanceof mysqli) {
    mysqli_close($conn);
}
?>