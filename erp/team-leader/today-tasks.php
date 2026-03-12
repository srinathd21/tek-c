<?php
// today-tasks.php — My Projects + Today's ALL Reports task status + 4 Stats

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

$employeeId  = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

$allowed = [
  'project engineer grade 1',
  'project engineer grade 2',
  'sr. engineer',
  'team lead',
  'manager',
];
if (!in_array($designation, $allowed, true)) {
  header("Location: index.php");
  exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fmtTime($ts){
  if (!$ts) return '—';
  $t = strtotime($ts);
  return $t ? date('h:i A', $t) : '—';
}

/**
 * Fetch latest report submitted today by this employee, grouped by site.
 * Returns: [bySiteArray, latestCreatedAt]
 * bySiteArray: site_id => ['id'=>..,'site_id'=>..,'doc_no'=>..,'created_at'=>..]
 */
function fetchTodayBySite(mysqli $conn, int $employeeId, string $table, string $dateField, string $noField){
  $todayYmd = date('Y-m-d');
  $bySite = [];
  $latestCreatedAt = null;

  // Note: field names are controlled internally (not user input)
  $sql = "
    SELECT id, site_id, {$noField} AS doc_no, created_at
    FROM {$table}
    WHERE employee_id = ? AND {$dateField} = ?
    ORDER BY created_at DESC
  ";

  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    mysqli_stmt_bind_param($st, "is", $employeeId, $todayYmd);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);

    while ($row = mysqli_fetch_assoc($res)) {
      $sid = (int)$row['site_id'];
      if (!isset($bySite[$sid])) {
        $bySite[$sid] = $row; // latest per site (ordered desc)
      }
      if ($latestCreatedAt === null && !empty($row['created_at'])) {
        $latestCreatedAt = $row['created_at']; // latest overall within this table
      }
    }
    mysqli_stmt_close($st);
  }

  return [$bySite, $latestCreatedAt];
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, designation FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$employeeName = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');

// ---------------- Assigned Sites ----------------
$sites = [];

if ($designation === 'manager') {
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.manager_employee_id = ?
    ORDER BY s.created_at DESC
  ";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
} else {
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name
    FROM site_project_engineers spe
    INNER JOIN sites s ON s.id = spe.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE spe.employee_id = ?
    ORDER BY s.created_at DESC
  ";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
}

$todayYmd = date('Y-m-d');

// ---------------- Report Types (ADD/EDIT LINKS HERE) ----------------
// Adjust submit/open links to your actual pages.
$reportTypes = [
  [
    'key' => 'dpr',
    'label' => 'Daily DPR',
    'icon' => 'bi-file-text',
    'table' => 'dpr_reports',
    'dateField' => 'dpr_date',
    'noField' => 'dpr_no',
    'submitUrl' => 'dpr.php?site_id={sid}',
    'openUrl'   => 'dpr.php?site_id={sid}',
  ],
  [
    'key' => 'dar',
    'label' => 'Daily Activity Report (DAR)',
    'icon' => 'bi-journal-text',
    'table' => 'dar_reports',
    'dateField' => 'dar_date',
    'noField' => 'dar_no',
    'submitUrl' => 'dar.php?site_id={sid}',
    'openUrl'   => 'dar.php?site_id={sid}',
  ],
  [
    'key' => 'checklist',
    'label' => 'Checklist',
    'icon' => 'bi-card-checklist',
    'table' => 'checklist_reports',
    'dateField' => 'checklist_date',
    'noField' => 'doc_no',
    'submitUrl' => 'checklist.php?site_id={sid}',
    'openUrl'   => 'checklist.php?site_id={sid}',
  ],
  [
    'key' => 'ma',
    'label' => 'Meeting Agenda (MA)',
    'icon' => 'bi-clipboard2-check',
    'table' => 'ma_reports',
    'dateField' => 'ma_date',
    'noField' => 'ma_no',
    'submitUrl' => 'ma.php?site_id={sid}',
    'openUrl'   => 'ma.php?site_id={sid}',
  ],
  [
    'key' => 'mom',
    'label' => 'Minutes of Meeting (MOM)',
    'icon' => 'bi-people',
    'table' => 'mom_reports',
    'dateField' => 'mom_date',
    'noField' => 'mom_no',
    'submitUrl' => 'mom.php?site_id={sid}',
    'openUrl'   => 'mom.php?site_id={sid}',
  ],
  [
    'key' => 'mpt',
    'label' => 'Monthly Project Tracker (MPT)',
    'icon' => 'bi-graph-up',
    'table' => 'mpt_reports',
    'dateField' => 'mpt_date',
    'noField' => 'mpt_no',
    'submitUrl' => 'mpt.php?site_id={sid}',
    'openUrl'   => 'mpt.php?site_id={sid}',
  ],
];

// ---------------- Load today reports for each type ----------------
$todayReports = []; // [typeKey][site_id] => row
$latestAnyCreatedAt = null;

foreach ($reportTypes as $rt) {
  [$bySite, $latestCreatedAt] = fetchTodayBySite(
    $conn,
    $employeeId,
    $rt['table'],
    $rt['dateField'],
    $rt['noField']
  );

  $todayReports[$rt['key']] = $bySite;

  if (!empty($latestCreatedAt)) {
    if ($latestAnyCreatedAt === null) {
      $latestAnyCreatedAt = $latestCreatedAt;
    } else {
      if (strtotime($latestCreatedAt) > strtotime($latestAnyCreatedAt)) {
        $latestAnyCreatedAt = $latestCreatedAt;
      }
    }
  }
}

// ---------------- Stats (4 only) ----------------
$totalProjects = count($sites);
$totalTasks = $totalProjects * count($reportTypes);

$completedCount = 0;
foreach ($sites as $s) {
  $sid = (int)$s['id'];
  foreach ($reportTypes as $rt) {
    if (isset($todayReports[$rt['key']][$sid])) $completedCount++;
  }
}
$pendingCount = max(0, $totalTasks - $completedCount);
$latestSubmitTime = fmtTime($latestAnyCreatedAt);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Today Tasks - TEK-C</title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{
      background: var(--surface);
      border:1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding:16px 16px 12px;
      height:100%;
      margin-bottom:14px;
    }

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
    .stat-ic{
      width:46px; height:46px;
      border-radius:14px;
      display:grid; place-items:center;
      color:#fff; font-size:20px;
      flex:0 0 auto;
    }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .h-title{ font-weight:1000; color:#111827; margin:0; }
    .h-sub{ color:#6b7280; font-weight:800; font-size:13px; margin:4px 0 0; }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid var(--border);
      background:#fff;
      font-weight:900; font-size:12px;
      color:#111827;
    }

    .table-responsive { overflow-x: auto; }
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:800;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: nowrap;
      background:#f9fafb;
    }
    .table td{
      vertical-align: top;
      border-color: var(--border);
      font-weight:650; color:#374151;
      padding: 10px 10px !important;
      white-space: normal;
    }

    .status-badge{
      padding: 3px 8px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: .3px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space: nowrap;
      text-transform: uppercase;
      border:1px solid var(--border);
    }
    .status-green{ background: rgba(16,185,129,.12); color:#10b981; border-color: rgba(16,185,129,.22); }
    .status-yellow{ background: rgba(245,158,11,.12); color:#f59e0b; border-color: rgba(245,158,11,.22); }

    .btn-action{
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 8px 10px;
      color: #374151;
      font-size: 13px;
      font-weight: 900;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
    }
    .btn-action.primary{
      background: var(--blue);
      border-color: var(--blue);
      color:#fff;
    }
    .btn-action:hover{ background: #f9fafb; color: var(--blue); }
    .btn-action.primary:hover{ filter: brightness(.98); color:#fff; background: var(--blue); }

    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div id="contentScroll" class="content-scroll">
      <div class="container-fluid maxw">

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h1 class="h-title">Today Tasks</h1>
            <p class="h-sub">Your report task status for <?php echo e(date('d M Y')); ?> (<?php echo e($todayYmd); ?>)</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
            <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($empRow['designation'] ?? ($_SESSION['designation'] ?? '')); ?></span>
            <a class="btn-action" href="today-tasks.php"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
          </div>
        </div>

        <!-- 4 Stats only -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-building"></i></div>
              <div>
                <div class="stat-label">Total Projects</div>
                <div class="stat-value"><?php echo (int)$totalProjects; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
              <div>
                <div class="stat-label">Reports Completed</div>
                <div class="stat-value"><?php echo (int)$completedCount; ?></div>
                <div class="small-muted">Today</div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-hourglass-split"></i></div>
              <div>
                <div class="stat-label">Reports Pending</div>
                <div class="stat-value"><?php echo (int)$pendingCount; ?></div>
                <div class="small-muted">Today</div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-clock"></i></div>
              <div>
                <div class="stat-label">Latest Submit Time</div>
                <div class="stat-value" style="font-size:22px;"><?php echo e($latestSubmitTime); ?></div>
                <div class="small-muted">Today</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Task List -->
        <div class="panel">
          <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
            <div>
              <div style="font-weight:1000; font-size:14px; color:#111827;">My Projects — All Reports Task</div>
              <div class="small-muted">Status is based on your submissions today (<?php echo e($todayYmd); ?>).</div>
            </div>
          </div>

          <hr style="border-color:#eef2f7;">

          <?php if (empty($sites)): ?>
            <div class="alert alert-warning" style="border-radius: var(--radius); border:none; box-shadow: var(--shadow);">
              <i class="bi bi-info-circle me-2"></i> No projects assigned to you currently.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width:60px;">#</th>
                    <th>Project</th>
                    <th>Location</th>
                    <th>Client</th>
                    <th>Task</th>
                    <th>Status</th>
                    <th class="text-end" style="min-width:260px;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $i = 1;
                    foreach ($sites as $s):
                      $sid = (int)$s['id'];
                      foreach ($reportTypes as $rt):
                        $isDone = isset($todayReports[$rt['key']][$sid]);
                        $rep = $isDone ? $todayReports[$rt['key']][$sid] : null;

                        $submitUrl = str_replace('{sid}', (string)$sid, $rt['submitUrl']);
                        $openUrl   = str_replace('{sid}', (string)$sid, $rt['openUrl']);
                  ?>
                    <tr>
                      <td style="font-weight:900;"><?php echo $i++; ?></td>
                      <td style="font-weight:900; color:#111827;"><?php echo e($s['project_name']); ?></td>
                      <td><?php echo e($s['project_location']); ?></td>
                      <td><?php echo e($s['client_name']); ?></td>
                      <td style="font-weight:900;">
                        <i class="bi <?php echo e($rt['icon']); ?> me-1"></i> <?php echo e($rt['label']); ?>
                      </td>
                      <td>
                        <?php if ($isDone): ?>
                          <span class="status-badge status-green"><i class="bi bi-check2-circle"></i> Completed</span>
                        <?php else: ?>
                          <span class="status-badge status-yellow"><i class="bi bi-hourglass-split"></i> Pending</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end">
                        <?php if ($isDone): ?>
                          <div class="d-flex justify-content-end gap-2 flex-wrap">
                            <span class="small-muted align-self-center">
                              No: <b style="color:#111827;"><?php echo e($rep['doc_no'] ?? ''); ?></b>
                            </span>
                            <a class="btn-action" href="<?php echo e($openUrl); ?>">
                              <i class="bi bi-box-arrow-up-right"></i> Open
                            </a>
                          </div>
                        <?php else: ?>
                          <a class="btn-action primary" href="<?php echo e($submitUrl); ?>">
                            <i class="bi bi-plus-circle"></i> Submit
                          </a>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php
                      endforeach;
                    endforeach;
                  ?>
                </tbody>
              </table>
            </div>

            <div class="small-muted mt-2">
              Note: Completed means you submitted that report today for that project.
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

<?php
if (isset($conn)) { mysqli_close($conn); }
?>
