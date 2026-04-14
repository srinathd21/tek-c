<?php
// employee-pending-tasks.php
// Pending + Completed task page for logged-in employee
// Same UI/template style as today-tasks.php
// Mobile responsive card design
// Shows remarks + completed task actions in desktop and mobile
// Action buttons changed to small square icon buttons

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

function safeFileNamePart(string $s): string {
  $s = preg_replace('/[^a-zA-Z0-9_\- ]+/', '', $s);
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return $s === '' ? 'Report' : $s;
}

function tableExists(mysqli $conn, string $table): bool {
  $safe = mysqli_real_escape_string($conn, $table);
  $sql = "SHOW TABLES LIKE '{$safe}'";
  $res = mysqli_query($conn, $sql);
  return $res && mysqli_num_rows($res) > 0;
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
      if (!isset($bySite[$sid])) $bySite[$sid] = $row;
      if ($latestCreatedAt === null && !empty($row['created_at'])) $latestCreatedAt = $row['created_at'];
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
$employeeEmail = $empRow['email'] ?? '';

// ---------------- Assigned Sites ----------------
$sites = [];
$filterSiteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;

if ($designation === 'manager') {
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name, c.email AS client_email
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
    SELECT s.id, s.project_name, s.project_location, c.client_name, c.email AS client_email
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

// If site_id filter provided, ensure it's allowed for user
if ($filterSiteId > 0) {
  $ok = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $filterSiteId) { $ok = true; break; }
  }
  if ($ok) {
    $sites = array_values(array_filter($sites, fn($s) => (int)$s['id'] === $filterSiteId));
  } else {
    $filterSiteId = 0;
  }
}

$todayYmd = date('Y-m-d');

// ---------------- Report Types ----------------
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
    'printFile' => 'report-print.php',
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
    'printFile' => 'report-dar-print.php',
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
    'printFile' => 'report-checklist-print.php',
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
    'printFile' => 'report-ma-print.php',
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
    'printFile' => 'report-mom-print.php',
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
    'printFile' => 'report-mpt-print.php',
  ],
];

// ---------------- Load today reports for each type ----------------
$todayReports = [];
$latestAnyCreatedAt = null;

foreach ($reportTypes as $rt) {
  if (!tableExists($conn, $rt['table'])) {
    $todayReports[$rt['key']] = [];
    continue;
  }

  [$bySite, $latestCreatedAt] = fetchTodayBySite($conn, $employeeId, $rt['table'], $rt['dateField'], $rt['noField']);
  $todayReports[$rt['key']] = $bySite;

  if (!empty($latestCreatedAt)) {
    if ($latestAnyCreatedAt === null || strtotime($latestCreatedAt) > strtotime($latestAnyCreatedAt)) {
      $latestAnyCreatedAt = $latestCreatedAt;
    }
  }
}

// ---------------- Load remarks if table exists ----------------
$remarksMap = [];
if (tableExists($conn, 'employee_report_remarks')) {
  $sql = "
    SELECT site_id, report_key, remark
    FROM employee_report_remarks
    WHERE report_date = ? AND employee_id = ?
  ";
  $st = mysqli_prepare($conn, $sql);
  if ($st) {
    mysqli_stmt_bind_param($st, "si", $todayYmd, $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($res)) {
      $key = (int)$row['site_id'] . '_' . (string)$row['report_key'];
      $remarksMap[$key] = $row['remark'] ?? '';
    }
    mysqli_stmt_close($st);
  }
}

// ---------------- Build task list ----------------
$taskRows = [];
$completedCount = 0;

foreach ($sites as $s) {
  $sid = (int)$s['id'];
  $clientEmail = trim((string)($s['client_email'] ?? ''));

  foreach ($reportTypes as $rt) {
    $isDone = isset($todayReports[$rt['key']][$sid]);
    $rep = $isDone ? $todayReports[$rt['key']][$sid] : null;

    if ($isDone) {
      $completedCount++;
    }

    $remarkKey = $sid . '_' . $rt['key'];

    $printUrl = '';
    $downloadUrl = '';
    $mailUrl = '';

    if ($isDone && $rep && !empty($rt['printFile'])) {
      $rid = (int)($rep['id'] ?? 0);
      $printUrl = $rt['printFile'] . '?view=' . urlencode((string)$rid);
      $downloadUrl = $rt['printFile'] . '?view=' . urlencode((string)$rid) . '&dl=1';

      $projectName = (string)($s['project_name'] ?? 'Project');
      $safeProject = safeFileNamePart($projectName);
      $pdfName = strtoupper($rt['key']) . '_' . $safeProject . '_' . $todayYmd . '.pdf';

      $mailSubject = "TEK-C " . strtoupper($rt['key']) . " - " . $projectName . " - " . date('d M Y');
      $mailBody =
"Dear Team,

Please find attached the " . strtoupper($rt['key']) . " for:

Project: {$projectName}
Date: " . date('d M Y') . "
Report No: " . ($rep['doc_no'] ?? '') . "

Regards,
{$employeeName}";

      $mailUrl = 'mail-compose.php?'
        . 'to=' . urlencode($clientEmail)
        . '&subject=' . urlencode($mailSubject)
        . '&body=' . urlencode($mailBody)
        . '&pdf=' . urlencode($downloadUrl)
        . '&pdf_name=' . urlencode($pdfName);
    }

    $taskRows[] = [
      'site_id' => $sid,
      'project_name' => $s['project_name'] ?? '',
      'project_location' => $s['project_location'] ?? '',
      'client_name' => $s['client_name'] ?? '',
      'client_email' => $clientEmail,
      'report_key' => $rt['key'],
      'report_label' => $rt['label'],
      'report_icon' => $rt['icon'],
      'submit_url' => str_replace('{sid}', (string)$sid, $rt['submitUrl']),
      'open_url' => str_replace('{sid}', (string)$sid, $rt['openUrl']),
      'is_done' => $isDone,
      'doc_no' => $rep['doc_no'] ?? '',
      'created_at' => $rep['created_at'] ?? '',
      'print_url' => $printUrl,
      'download_url' => $downloadUrl,
      'mail_url' => $mailUrl,
      'remark' => $remarksMap[$remarkKey] ?? '',
    ];
  }
}

// ---------------- Stats ----------------
$totalProjects = count($sites);
$totalTasks = count($taskRows);
$pendingCount = max(0, $totalTasks - $completedCount);
$latestSubmitTime = fmtTime($latestAnyCreatedAt);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Employee Tasks - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{
      background: var(--surface);
      border:1px solid var(--border);
      border-radius: 16px;
      box-shadow: var(--shadow);
      padding:16px 16px 12px;
      height:100%;
      margin-bottom:14px;
    }
    .stat-card{
      background: var(--surface);
      border:1px solid var(--border);
      border-radius: 16px;
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
    .stat-label{ color:#4b5563; font-weight:800; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:1000; line-height:1; margin-top:2px; }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

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

    .table-responsive{ overflow-x:auto; }
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:900;
      border-bottom:1px solid var(--border)!important;
      padding:10px 10px !important;
      white-space:nowrap;
      background:#f9fafb;
    }
    .table td{
      vertical-align:top;
      border-color:var(--border);
      font-weight:800; color:#111827;
      padding:10px 10px !important;
      white-space:normal;
      word-break: break-word;
    }

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

    .btn-action{
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      width: 36px;
      height: 36px;
      padding: 0;
      color: #374151;
      font-size: 13px;
      font-weight: 1000;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:0;
      line-height:1;
      flex:0 0 36px;
    }
    .btn-action.primary{
      background: var(--blue);
      border-color: var(--blue);
      color:#fff;
    }
    .btn-action:hover{ background:#f9fafb; color:var(--blue); }
    .btn-action.primary:hover{ filter:brightness(.98); color:#fff; background:var(--blue); }

    .task-card{
      border:1px solid var(--border);
      border-radius:16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding:12px;
    }
    .task-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }
    .task-title{ font-weight:1000; color:#111827; font-size:14px; line-height:1.2; margin:0; }
    .task-sub{ color:#6b7280; font-weight:800; font-size:12px; margin-top:6px; }
    .task-kv{ margin-top:10px; display:grid; gap:8px; }
    .task-row{ display:flex; gap:10px; align-items:flex-start; }
    .task-key{ flex:0 0 92px; color:#6b7280; font-weight:1000; font-size:12px; }
    .task-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; }
    .task-actions{ margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
    .task-actions a{ justify-content:center; }

    .remark-box{
      margin-top:12px;
      padding:10px 12px;
      border-radius:12px;
      border:1px dashed #f59e0b;
      background:#fffaf0;
    }
    .remark-title{
      font-size:11px;
      font-weight:1000;
      color:#b45309;
      text-transform:uppercase;
      margin-bottom:4px;
      letter-spacing:.3px;
    }
    .remark-text{
      font-size:13px;
      font-weight:900;
      color:#111827;
      line-height:1.35;
      white-space:pre-wrap;
    }

    @media (max-width: 991.98px){
      .main{ margin-left:0 !important; width:100% !important; max-width:100% !important; }
      .sidebar{ position:fixed !important; transform:translateX(-100%); z-index:1040 !important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform:translateX(0) !important; }
    }
    @media (max-width: 768px){
      .content-scroll{ padding:12px 10px 12px !important; }
      .container-fluid.maxw{ padding-left:6px !important; padding-right:6px !important; }
      .panel{ padding:12px !important; margin-bottom:12px; border-radius:14px; }
      .btn-action{
        width:34px;
        height:34px;
        flex:0 0 34px;
        border-radius:8px;
        font-size:12px;
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

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h1 class="h-title">Employee Tasks</h1>
            <p class="h-sub">
              Your report task status for <?php echo e(date('d M Y')); ?> (<?php echo e($todayYmd); ?>)
              <?php if ($filterSiteId > 0 && !empty($sites[0])): ?>
                • <span class="small-muted">Filtered Site: <b style="color:#111827;"><?php echo e($sites[0]['project_name']); ?></b></span>
              <?php endif; ?>
            </p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
            <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($empRow['designation'] ?? ($_SESSION['designation'] ?? '')); ?></span>
            <a class="btn-action" href="employee-pending-tasks.php<?php echo $filterSiteId>0 ? ('?site_id='.e($filterSiteId)) : ''; ?>" title="Refresh">
              <i class="bi bi-arrow-clockwise"></i>
            </a>
          </div>
        </div>

        <!-- Stats -->
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
          <div style="font-weight:1000; font-size:14px; color:#111827;">My Projects — All Reports Task</div>
          <div class="small-muted">Pending and completed reports are shown below. If a remark is given, it will be shown in both desktop and mobile.</div>
          <hr style="border-color:#eef2f7;">

          <?php if (empty($sites)): ?>
            <div class="alert alert-warning mb-0" style="border-radius:16px; border:none; box-shadow:var(--shadow);">
              <i class="bi bi-info-circle me-2"></i> No projects assigned to you currently.
            </div>
          <?php else: ?>

            <!-- Mobile cards -->
            <div class="d-block d-md-none">
              <div class="d-grid gap-3">
                <?php foreach ($taskRows as $row): ?>
                  <div class="task-card">
                    <div class="task-top">
                      <div style="flex:1 1 auto;">
                        <h3 class="task-title"><?php echo e($row['project_name']); ?></h3>
                        <div class="task-sub">
                          <i class="bi bi-geo-alt"></i> <?php echo e($row['project_location']); ?>
                          &nbsp;•&nbsp; <i class="bi bi-person-badge"></i> <?php echo e($row['client_name']); ?>
                        </div>
                      </div>
                      <?php if ($row['is_done']): ?>
                        <span class="status-badge status-green"><i class="bi bi-check2-circle"></i> Completed</span>
                      <?php else: ?>
                        <span class="status-badge status-yellow"><i class="bi bi-hourglass-split"></i> Pending</span>
                      <?php endif; ?>
                    </div>

                    <div class="task-kv">
                      <div class="task-row">
                        <div class="task-key">Task</div>
                        <div class="task-val"><i class="bi <?php echo e($row['report_icon']); ?> me-1"></i> <?php echo e($row['report_label']); ?></div>
                      </div>

                      <?php if ($row['is_done']): ?>
                        <div class="task-row">
                          <div class="task-key">Completed</div>
                          <div class="task-val">No: <?php echo e($row['doc_no'] ?: '—'); ?></div>
                        </div>
                      <?php else: ?>
                        <div class="task-row">
                          <div class="task-key">Status</div>
                          <div class="task-val">Not submitted yet</div>
                        </div>
                      <?php endif; ?>
                    </div>

                    <?php if (trim((string)$row['remark']) !== ''): ?>
                      <div class="remark-box">
                        <div class="remark-title"><i class="bi bi-chat-left-text me-1"></i> Remark</div>
                        <div class="remark-text"><?php echo e($row['remark']); ?></div>
                      </div>
                    <?php endif; ?>

                    <div class="task-actions">
                      <?php if ($row['is_done']): ?>
                        <a class="btn-action" href="<?php echo e($row['open_url']); ?>" title="Open">
                          <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                        <?php if ($row['print_url'] !== ''): ?>
                          <a class="btn-action" href="<?php echo e($row['print_url']); ?>" target="_blank" rel="noopener noreferrer" title="Print">
                            <i class="bi bi-printer"></i>
                          </a>
                          <a class="btn-action" href="<?php echo e($row['download_url']); ?>" rel="noopener noreferrer" title="Download">
                            <i class="bi bi-download"></i>
                          </a>
                          <a class="btn-action primary" href="<?php echo e($row['mail_url']); ?>" title="Send Mail">
                            <i class="bi bi-envelope"></i>
                          </a>
                        <?php endif; ?>
                      <?php else: ?>
                        <a class="btn-action primary" href="<?php echo e($row['submit_url']); ?>" title="Submit Report">
                          <i class="bi bi-plus-circle"></i>
                        </a>
                        <a class="btn-action" href="<?php echo e($row['open_url']); ?>" title="Open Page">
                          <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Desktop table -->
            <div class="d-none d-md-block">
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
                      <th>Remark</th>
                      <th class="text-end" style="min-width:320px;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php $i = 1; foreach ($taskRows as $row): ?>
                      <tr>
                        <td style="font-weight:1000;"><?php echo $i++; ?></td>
                        <td style="font-weight:1000; color:#111827;"><?php echo e($row['project_name']); ?></td>
                        <td><?php echo e($row['project_location']); ?></td>
                        <td>
                          <?php echo e($row['client_name']); ?>
                          <?php if (!empty($row['client_email'])): ?>
                            <div class="small-muted"><?php echo e($row['client_email']); ?></div>
                          <?php endif; ?>
                        </td>
                        <td style="font-weight:1000;"><i class="bi <?php echo e($row['report_icon']); ?> me-1"></i> <?php echo e($row['report_label']); ?></td>
                        <td>
                          <?php if ($row['is_done']): ?>
                            <span class="status-badge status-green"><i class="bi bi-check2-circle"></i> Completed</span>
                          <?php else: ?>
                            <span class="status-badge status-yellow"><i class="bi bi-hourglass-split"></i> Pending</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if (trim((string)$row['remark']) !== ''): ?>
                            <div class="remark-text"><?php echo e($row['remark']); ?></div>
                          <?php else: ?>
                            <span class="small-muted">No remark</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                          <?php if ($row['is_done']): ?>
                            <div class="d-flex justify-content-end gap-2 flex-wrap align-items-center">
                              <span class="small-muted align-self-center">
                                Completed &nbsp; No: <b style="color:#111827;"><?php echo e($row['doc_no'] ?: ''); ?></b>
                              </span>
                              <a class="btn-action" href="<?php echo e($row['open_url']); ?>" title="Open">
                                <i class="bi bi-box-arrow-up-right"></i>
                              </a>
                              <?php if ($row['print_url'] !== ''): ?>
                                <a class="btn-action" href="<?php echo e($row['print_url']); ?>" target="_blank" rel="noopener noreferrer" title="Print">
                                  <i class="bi bi-printer"></i>
                                </a>
                                <a class="btn-action" href="<?php echo e($row['download_url']); ?>" rel="noopener noreferrer" title="Download">
                                  <i class="bi bi-download"></i>
                                </a>
                                <a class="btn-action primary" href="<?php echo e($row['mail_url']); ?>" title="Send Mail">
                                  <i class="bi bi-envelope"></i>
                                </a>
                              <?php endif; ?>
                            </div>
                          <?php else: ?>
                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                              <a class="btn-action primary" href="<?php echo e($row['submit_url']); ?>" title="Submit">
                                <i class="bi bi-plus-circle"></i>
                              </a>
                              <a class="btn-action" href="<?php echo e($row['open_url']); ?>" title="Open">
                                <i class="bi bi-box-arrow-up-right"></i>
                              </a>
                            </div>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="small-muted mt-2">
                Note: Desktop and mobile both show completed actions and remarks.
              </div>
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
try {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) { }
?>