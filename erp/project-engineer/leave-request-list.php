<?php
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

// ---------- Flash messages (PRG) ----------
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------- THIS MONTH SUMMARY (employee wise) ----------
$summary = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0
];

$st = mysqli_prepare($conn, "
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Pending') AS pending,
        SUM(status = 'Approved') AS approved,
        SUM(status = 'Rejected') AS rejected,
        SUM(status = 'In Progress') AS in_progress,
        SUM(status = 'Completed') AS completed,
        SUM(status = 'Cancelled') AS cancelled
    FROM employee_requests
    WHERE request_type = 'Leave'
      AND employee_id = ?
      AND YEAR(request_date) = YEAR(CURDATE())
      AND MONTH(request_date) = MONTH(CURDATE())
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);

    if ($row) {
        foreach ($summary as $k => $v) {
            $summary[$k] = (int)($row[$k] ?? 0);
        }
    }
}

// ---------- FETCH EMPLOYEE REQUESTS ----------
$requests = [];
$st = mysqli_prepare($conn, "
    SELECT
        id, request_no, request_subject, request_date, status,
        priority, attachments,
        leave_type, leave_from_date, leave_to_date,
        manager_name,
        approver_remarks,
        rejection_reason,
        approved_at, rejected_at
    FROM employee_requests
    WHERE request_type = 'Leave'
      AND employee_id = ?
    ORDER BY request_date DESC, id DESC
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) $requests[] = $r;
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

    /* DPR-like panels + headers */
    .panel{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding:16px;
      margin-bottom:14px;
    }
    .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .h-title{ margin:0; font-weight:1000; color:#111827; }
    .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

    .sec-head{
      display:flex; align-items:center; gap:10px;
      padding:10px 12px;
      border-radius: 14px;
      background:#f9fafb;
      border:1px solid #eef2f7;
      margin-bottom:10px;
    }
    .sec-ic{
      width:34px;height:34px;border-radius: 12px;
      display:grid;place-items:center;
      background: rgba(45,156,219,.12);
      color: var(--blue);
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

    .badge-pill{
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

    /* KEEPING YOUR EXISTING STATS DESIGN */
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
      font-weight:650;
      color:#374151;
      padding-top:14px;
      padding-bottom:14px;
    }

    .status-pill{
      border-radius:999px;
      padding:8px 12px;
      font-weight:900;
      font-size:12px;
      border:1px solid transparent;
      display:inline-flex;
      align-items:center;
      gap:8px;
    }
    .status-pill .mini-dot{ width:8px; height:8px; border-radius:50%; background: currentColor; opacity:.9; }

    .pending{ color:#9a3412; background: rgba(242,153,74,.14); border-color: rgba(242,153,74,.20); }
    .approved{ color: var(--green); background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.18); }
    .rejected{ color: var(--red); background: rgba(235,87,87,.12); border-color: rgba(235,87,87,.18); }
    .otherst{ color:#374151; background:#f3f4f6; border-color:#e5e7eb; }

    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

    .small-muted{ color:#6b7280; font-size:12px; font-weight:650; }
    .remarks-box{ background:#f8fafc; border:1px solid var(--border); border-radius:12px; padding:10px 12px; margin-top:8px; }

    .priority-pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:4px 8px;
      border-radius:999px;
      border:1px solid #e5e7eb;
      background:#fff;
      font-weight:900;
      font-size:11px;
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
  .content-scroll {
    padding: 12px 10px 12px !important;   /* was 22px */
  }

  .container-fluid.maxw {
    padding-left: 6px !important;
    padding-right: 6px !important;
  }

  .panel {
    padding: 12px !important;
    margin-bottom: 12px;
    border-radius: 14px;
  }

  .sec-head {
    padding: 10px !important;
    border-radius: 12px;
  }
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
            <h1 class="h-title">My Leave Requests</h1>
            <p class="h-sub">Track your leave approval status</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
            <a href="leave-request-form.php" class="btn-primary-tek">
              <i class="bi bi-plus-circle"></i> New Request
            </a>
          </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-check-circle-fill me-2"></i>
            <div class="d-inline"><?php echo e($success); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <div class="d-inline"><?php echo e($error); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Summary (EXISTING STATS DESIGN KEPT) -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-bar-chart"></i></div>
            <div>
              <p class="sec-title mb-0">This Month Summary</p>
              <p class="sec-sub mb-0">Current month leave request statistics</p>
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

        <!-- List -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-table"></i></div>
            <div>
              <p class="sec-title mb-0">Request List</p>
              <p class="sec-sub mb-0">All your leave requests (latest first)</p>
            </div>
          </div>

          <?php if (empty($requests)): ?>
            <div class="text-muted" style="font-weight:800;">No requests found.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th style="min-width:170px;">Request No</th>
                    <th style="min-width:220px;">Subject</th>
                    <th style="min-width:110px;">Type</th>
                    <th style="min-width:120px;">From</th>
                    <th style="min-width:120px;">To</th>
                    <th style="min-width:120px;">Status</th>
                    <th style="min-width:180px;">Manager</th>
                    <th style="min-width:220px;">Remarks</th>
                    <th style="min-width:120px;">Attachment</th>
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
                      <div class="mono"><?php echo e($r['request_no']); ?></div>
                      <div class="small-muted mt-1">Date: <?php echo e($r['request_date']); ?></div>
                    </td>

                    <td>
                      <div class="fw-bold" style="color:#111827;"><?php echo e($r['request_subject']); ?></div>
                      <div class="small-muted mt-1">
                        <span class="priority-pill"><i class="bi bi-flag"></i> <?php echo e($r['priority']); ?></span>
                      </div>
                    </td>

                    <td><?php echo e($r['leave_type'] ?? '-'); ?></td>
                    <td><?php echo e($r['leave_from_date'] ?? '-'); ?></td>
                    <td><?php echo e($r['leave_to_date'] ?? '-'); ?></td>

                    <td>
                      <span class="status-pill <?php echo e($badgeClass); ?>">
                        <span class="mini-dot"></span> <?php echo e($r['status']); ?>
                      </span>

                      <?php if (!empty($r['approved_at']) && ($r['status'] ?? '') === 'Approved'): ?>
                        <div class="small-muted mt-1">Approved: <?php echo e($r['approved_at']); ?></div>
                      <?php endif; ?>

                      <?php if (!empty($r['rejected_at']) && ($r['status'] ?? '') === 'Rejected'): ?>
                        <div class="small-muted mt-1">Rejected: <?php echo e($r['rejected_at']); ?></div>
                      <?php endif; ?>
                    </td>

                    <td><?php echo e($r['manager_name'] ?? ''); ?></td>

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
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo e($r['attachments']); ?>" target="_blank">
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
</body>
</html>

