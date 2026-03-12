<?php
// leave-request-list.php
// Employee Leave Request List (DAR-style UI + existing stats cards kept)

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------- AUTH ----------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId   = (int)$_SESSION['employee_id'];
$employeeName = $_SESSION['employee_name'] ?? ($_SESSION['name'] ?? 'Employee');

$success = '';
$error   = '';

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Dynamic mysqli bind_param helper (reference-safe)
 */
function stmt_bind_params(mysqli_stmt $stmt, string $types, array &$params): bool {
    $bind = [$stmt, $types];
    foreach ($params as $k => &$v) {
        $bind[] = &$v;
    }
    return call_user_func_array('mysqli_stmt_bind_param', $bind);
}

// ---------- Flash messages ----------
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------- FILTERS (GET) ----------
$allowedStatus = ['All','Pending','Approved','Rejected','Completed'];
$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, $allowedStatus, true)) $statusFilter = 'All';

$allowedTypes = ['All','Annual','Sick','Casual','Unpaid'];
$typeFilter = $_GET['leave_type'] ?? 'All';
if (!in_array($typeFilter, $allowedTypes, true)) $typeFilter = 'All';

$allowedPeriod = ['this_month','this_year','all_time','custom'];
$periodFilter = $_GET['period'] ?? 'this_month';
if (!in_array($periodFilter, $allowedPeriod, true)) $periodFilter = 'this_month';

$fromFilter = $_GET['from'] ?? '';
$toFilter   = $_GET['to'] ?? '';

if ($periodFilter !== 'custom') {
    $fromFilter = '';
    $toFilter   = '';
}
if ($periodFilter === 'custom') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromFilter)) $fromFilter = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toFilter))   $toFilter   = '';
    if ($fromFilter && $toFilter && $fromFilter > $toFilter) {
        $tmp = $fromFilter; $fromFilter = $toFilter; $toFilter = $tmp;
    }
}

// ---------- SUMMARY: This Month ----------
$summary = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];

$st = mysqli_prepare($conn, "
    SELECT
        COUNT(*) AS total,
        SUM(status='Pending') AS pending,
        SUM(status='Approved') AS approved,
        SUM(status='Rejected') AS rejected
    FROM employee_requests
    WHERE request_type='Leave'
      AND employee_id=?
      AND YEAR(request_date)=YEAR(CURDATE())
      AND MONTH(request_date)=MONTH(CURDATE())
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
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

// ---------- REQUEST LIST with filters ----------
$where  = " WHERE request_type='Leave' AND employee_id=? ";
$params = [$employeeId];
$types  = "i";

if ($statusFilter !== 'All') {
    $where .= " AND status=? ";
    $params[] = $statusFilter;
    $types .= "s";
}
if ($typeFilter !== 'All') {
    $where .= " AND leave_type=? ";
    $params[] = $typeFilter;
    $types .= "s";
}

if ($periodFilter === 'this_month') {
    $where .= " AND YEAR(request_date)=YEAR(CURDATE()) AND MONTH(request_date)=MONTH(CURDATE()) ";
} elseif ($periodFilter === 'this_year') {
    $where .= " AND YEAR(request_date)=YEAR(CURDATE()) ";
} elseif ($periodFilter === 'custom') {
    if ($fromFilter) { $where .= " AND request_date >= ? "; $params[] = $fromFilter; $types .= "s"; }
    if ($toFilter)   { $where .= " AND request_date <= ? "; $params[] = $toFilter;   $types .= "s"; }
}

$requests = [];
$sql = "
    SELECT
        id, request_no, request_subject, request_date, status,
        priority, attachments,
        leave_type, leave_from_date, leave_to_date,
        manager_name,
        approver_remarks,
        rejection_reason,
        approved_at, rejected_at
    FROM employee_requests
    $where
    ORDER BY request_date DESC, id DESC
";

$st = mysqli_prepare($conn, $sql);
if (!$st) {
    $error = "DB error (prepare list): " . mysqli_error($conn);
} else {
    if (!stmt_bind_params($st, $types, $params)) {
        $error = "DB error (bind params).";
    } else {
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        while ($r = mysqli_fetch_assoc($res)) {
            $requests[] = $r;
        }
    }
    mysqli_stmt_close($st);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>My Leave Requests - <?php echo e($employeeName); ?></title>

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

    /* DAR-style panel UI */
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

    .form-label{
      font-weight:900; color:#374151; font-size:13px;
      margin-bottom:6px;
    }
    .form-control, .form-select{
      border:2px solid #e5e7eb;
      border-radius:12px;
      padding:10px 12px;
      font-weight:750;
      font-size:14px;
    }
    .form-control:focus, .form-select:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45,156,219,.10);
    }

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
      background:rgba(45,156,219,.12);
      color: var(--blue);
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

    .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; }
    @media (max-width: 992px){
      .grid-2, .grid-3{ grid-template-columns: 1fr; }
    }

    /* KEEP YOUR EXISTING STATS DESIGN */
    .stat-card{
      background: var(--surface);
      border:1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding:14px 16px;
      height:90px;
      display:flex;
      align-items:center;
      gap:14px;
    }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.orange{ background: var(--orange); }
    .stat-ic.green{ background: var(--green); }
    .stat-ic.red{ background: var(--red); }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
    }
    .badge-pill.status-pill{
      padding:8px 12px;
      border:1px solid transparent;
    }
    .badge-pill .mini-dot{ width:8px; height:8px; border-radius:50%; background: currentColor; opacity:.9; }

    .pending{ color:#9a3412; background:rgba(242,153,74,.14); border-color:rgba(242,153,74,.20); }
    .approved{ color:var(--green); background:rgba(39,174,96,.12); border-color:rgba(39,174,96,.18); }
    .rejected{ color:var(--red); background:rgba(235,87,87,.12); border-color:rgba(235,87,87,.18); }
    .otherst{ color:#374151; background:#f3f4f6; border-color:#e5e7eb; }

    .table thead th{
      font-size:12px;
      color:#6b7280;
      font-weight:900;
      border-bottom:1px solid #e5e7eb !important;
      background:#f9fafb;
      white-space:nowrap;
    }
    .table td{
      vertical-align:top;
      border-color:#eef2f7;
      color:#374151;
      font-weight:650;
      padding-top:14px;
      padding-bottom:14px;
    }

    .mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
    .remarks-box{
      background:#f8fafc;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:10px 12px;
      margin-top:8px;
    }

    .btn-primary-tek{
      background: var(--blue);
      border:none;
      border-radius:12px;
      padding:10px 16px;
      font-weight:1000;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
      color:#fff;
      text-decoration:none;
    }
    .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }

    .btn-soft{ border-radius:12px; font-weight:900; }

    @media (max-width: 768px){
      .table td, .table th{ white-space:nowrap; }
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

        <!-- Header (same style as DAR page) -->
        <div class="title-row mb-3">
          <div>
            <h1 class="h-title">My Leave Requests</h1>
            <p class="h-sub">Track approval status, remarks and submitted leave requests</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
            <a href="leave-request-form.php" class="btn-primary-tek">
              <i class="bi bi-plus-circle"></i> New Request
            </a>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Summary Cards (existing design retained) -->
        <div class="row g-3 mb-3">
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

        <!-- Filters (DAR style panel) -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-funnel"></i></div>
            <div>
              <p class="sec-title mb-0">Filters</p>
              <p class="sec-sub mb-0">Filter by status, leave type and period</p>
            </div>
          </div>

          <form method="GET" autocomplete="off">
            <div class="grid-3">
              <div>
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <?php foreach ($allowedStatus as $s): ?>
                    <option value="<?php echo e($s); ?>" <?php echo ($statusFilter === $s) ? 'selected' : ''; ?>>
                      <?php echo e($s); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label class="form-label">Leave Type</label>
                <select name="leave_type" class="form-select">
                  <?php foreach ($allowedTypes as $t): ?>
                    <option value="<?php echo e($t); ?>" <?php echo ($typeFilter === $t) ? 'selected' : ''; ?>>
                      <?php echo e($t); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label class="form-label">Period</label>
                <select name="period" id="periodSelect" class="form-select">
                  <option value="this_month" <?php echo ($periodFilter==='this_month')?'selected':''; ?>>This Month</option>
                  <option value="this_year"  <?php echo ($periodFilter==='this_year')?'selected':''; ?>>This Year</option>
                  <option value="all_time"   <?php echo ($periodFilter==='all_time')?'selected':''; ?>>All Time</option>
                  <option value="custom"     <?php echo ($periodFilter==='custom')?'selected':''; ?>>Custom</option>
                </select>
              </div>
            </div>

            <div class="grid-3 mt-2">
              <div>
                <label class="form-label">From</label>
                <input type="date" name="from" id="fromDate" class="form-control" value="<?php echo e($fromFilter); ?>">
              </div>

              <div>
                <label class="form-label">To</label>
                <input type="date" name="to" id="toDate" class="form-control" value="<?php echo e($toFilter); ?>">
              </div>

              <div class="d-flex align-items-end gap-2">
                <button class="btn btn-primary btn-soft" type="submit" style="border-radius:12px; padding:10px 14px; font-weight:900;">
                  <i class="bi bi-funnel"></i> Apply
                </button>
                <a class="btn btn-outline-secondary btn-soft" href="leave-request-list.php" style="border-radius:12px; padding:10px 14px;">
                  <i class="bi bi-arrow-clockwise"></i> Reset
                </a>
              </div>
            </div>
          </form>
        </div>

        <!-- Request List Panel -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-card-list"></i></div>
            <div>
              <p class="sec-title mb-0">Request List</p>
              <p class="sec-sub mb-0">Submitted leave requests and approval remarks</p>
            </div>
          </div>

          <?php if (empty($requests)): ?>
            <div class="text-muted" style="font-weight:800;">No requests found for selected filters.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th style="min-width:170px;">Request No</th>
                    <th style="min-width:260px;">Subject</th>
                    <th style="min-width:120px;">Type</th>
                    <th style="min-width:120px;">From</th>
                    <th style="min-width:120px;">To</th>
                    <th style="min-width:130px;">Status</th>
                    <th style="min-width:170px;">Approver</th>
                    <th style="min-width:280px;">Remarks</th>
                    <th style="min-width:120px;">Attachment</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                  <?php
                    $stText = (string)($r['status'] ?? '');
                    $badgeClass = 'otherst';
                    if ($stText === 'Pending')  $badgeClass = 'pending';
                    if ($stText === 'Approved') $badgeClass = 'approved';
                    if ($stText === 'Rejected') $badgeClass = 'rejected';
                  ?>
                  <tr>
                    <td class="mono"><?php echo e($r['request_no']); ?></td>

                    <td>
                      <div class="fw-bold"><?php echo e($r['request_subject']); ?></div>
                      <div class="small-muted">
                        Date: <?php echo e($r['request_date']); ?> | Priority: <?php echo e($r['priority'] ?? 'Medium'); ?>
                      </div>

                      <?php if (!empty($r['approved_at']) && $stText === 'Approved'): ?>
                        <div class="small-muted">Approved at: <?php echo e($r['approved_at']); ?></div>
                      <?php endif; ?>

                      <?php if (!empty($r['rejected_at']) && $stText === 'Rejected'): ?>
                        <div class="small-muted">Rejected at: <?php echo e($r['rejected_at']); ?></div>
                      <?php endif; ?>
                    </td>

                    <td><?php echo e($r['leave_type'] ?? '-'); ?></td>
                    <td><?php echo e($r['leave_from_date'] ?? '-'); ?></td>
                    <td><?php echo e($r['leave_to_date'] ?? '-'); ?></td>

                    <td>
                      <span class="badge-pill status-pill <?php echo e($badgeClass); ?>">
                        <span class="mini-dot"></span> <?php echo e($stText !== '' ? $stText : '-'); ?>
                      </span>
                    </td>

                    <td><?php echo e($r['manager_name'] ?? '-'); ?></td>

                    <td>
                      <?php if (!empty($r['approver_remarks']) || !empty($r['rejection_reason'])): ?>
                        <div class="remarks-box">
                          <?php if (!empty($r['approver_remarks'])): ?>
                            <div><strong>Remarks:</strong> <?php echo e($r['approver_remarks']); ?></div>
                          <?php endif; ?>
                          <?php if (!empty($r['rejection_reason'])): ?>
                            <div class="mt-1"><strong>Reason:</strong> <?php echo e($r['rejection_reason']); ?></div>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-muted">-</span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if (!empty($r['attachments'])): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo e($r['attachments']); ?>" target="_blank" rel="noopener">
                          <i class="bi bi-paperclip"></i> View
                        </a>
                      <?php else: ?>
                        <span class="text-muted">-</span>
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
document.addEventListener('DOMContentLoaded', function () {
  const period = document.getElementById('periodSelect');
  const from = document.getElementById('fromDate');
  const to = document.getElementById('toDate');

  function toggleDates(){
    if (!period || !from || !to) return;
    const isCustom = (period.value === 'custom');
    from.disabled = !isCustom;
    to.disabled = !isCustom;
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