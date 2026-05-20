<?php
// my-leave-history.php
// Updated to match manager projects compact UI/template.

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId   = (int)$_SESSION['employee_id'];
$employeeName = (string)($_SESSION['employee_name'] ?? '');

$filterYear   = isset($_GET['year']) ? trim((string)$_GET['year']) : (string)date('Y');
$filterStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$searchTerm   = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEsc = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

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
    if ($s === 'pending')  return ['Pending',  'pending',     'bi-hourglass-split'];
    if ($s === 'approved') return ['Approved', 'ontrack',     'bi-check2-circle'];
    if ($s === 'rejected') return ['Rejected', 'atrisk',      'bi-x-circle'];
    if ($s === 'cancelled') return ['Cancelled', 'delayed',   'bi-slash-circle'];
    return [($status ?: '—'), 'neutral', 'bi-info-circle'];
}

function getLeaveProjectName(array $leave): string {
    if (!empty($leave['project_name'])) return (string)$leave['project_name'];
    $json = trim((string)($leave['selected_dates_json'] ?? ''));
    if ($json === '') return '';
    $data = json_decode($json, true);
    if (!is_array($data)) return '';
    return (string)($data['project_name'] ?? $data['site_name'] ?? '');
}

function getLeaveProjectId(array $leave): int {
    if (!empty($leave['site_id'])) return (int)$leave['site_id'];
    $json = trim((string)($leave['selected_dates_json'] ?? ''));
    if ($json === '') return 0;
    $data = json_decode($json, true);
    if (!is_array($data)) return 0;
    return (int)($data['site_id'] ?? 0);
}

$hasSiteIdCol = columnExists($conn, 'leave_requests', 'site_id');
$hasApproverCol = columnExists($conn, 'leave_requests', 'approver_id');

$whereConditions = ["lr.employee_id = ?"];
$params = [$employeeId];
$types = "i";

if ($filterYear !== '' && strtolower($filterYear) !== 'all' && (int)$filterYear > 0) {
    $whereConditions[] = "YEAR(lr.applied_at) = ?";
    $params[] = (int)$filterYear;
    $types .= "i";
}

if ($filterStatus !== '' && strtolower($filterStatus) !== 'all') {
    $whereConditions[] = "LOWER(lr.status) = ?";
    $params[] = strtolower($filterStatus);
    $types .= "s";
}

if ($searchTerm !== '') {
    $whereConditions[] = "(lr.leave_type LIKE ? OR lr.reason LIKE ? OR CAST(lr.id AS CHAR) LIKE ? OR s.project_name LIKE ?)";
    $searchPattern = "%{$searchTerm}%";
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $types .= "ssss";
}

$whereClause = implode(" AND ", $whereConditions);

$years = [];
$yearStmt = mysqli_prepare($conn, "SELECT DISTINCT YEAR(applied_at) AS yr FROM leave_requests WHERE employee_id = ? AND applied_at IS NOT NULL ORDER BY yr DESC");
if ($yearStmt) {
    mysqli_stmt_bind_param($yearStmt, "i", $employeeId);
    mysqli_stmt_execute($yearStmt);
    $yearRes = mysqli_stmt_get_result($yearStmt);
    while ($row = mysqli_fetch_assoc($yearRes)) {
        if (!empty($row['yr'])) $years[] = (int)$row['yr'];
    }
    mysqli_stmt_close($yearStmt);
}
if (empty($years)) $years[] = (int)date('Y');

$siteIdSelect = $hasSiteIdCol ? "lr.site_id" : "NULL AS site_id";
$approverSelect = $hasApproverCol ? "lr.approver_id" : "NULL AS approver_id";
$siteJoin = $hasSiteIdCol
    ? "LEFT JOIN sites s ON s.id = lr.site_id"
    : "LEFT JOIN sites s ON s.id = CASE
        WHEN JSON_VALID(lr.selected_dates_json)
        THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(lr.selected_dates_json, '$.site_id')) AS UNSIGNED)
        ELSE 0
      END";
$approverJoin = $hasApproverCol
    ? "LEFT JOIN employees app ON app.id = lr.approver_id"
    : "LEFT JOIN employees app ON 1=0";

$totalRecords = 0;
$countSql = "SELECT COUNT(*) AS total FROM leave_requests lr $siteJoin WHERE $whereClause";
$countStmt = mysqli_prepare($conn, $countSql);
if ($countStmt) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
    mysqli_stmt_execute($countStmt);
    $countRes = mysqli_stmt_get_result($countStmt);
    $countRow = $countRes ? mysqli_fetch_assoc($countRes) : null;
    $totalRecords = (int)($countRow['total'] ?? 0);
    mysqli_stmt_close($countStmt);
}

$leaveHistory = [];
$sql = "
    SELECT
        lr.id,
        lr.leave_type,
        lr.from_date,
        lr.to_date,
        lr.total_days,
        lr.reason,
        lr.status,
        lr.applied_at,
        lr.selected_dates_json,
        $siteIdSelect,
        $approverSelect,
        s.project_name,
        s.project_location,
        app.full_name AS approver_name
    FROM leave_requests lr
    $siteJoin
    $approverJoin
    WHERE $whereClause
    ORDER BY lr.applied_at DESC, lr.id DESC
    LIMIT ? OFFSET ?
";
$queryParams = $params;
$queryTypes = $types . "ii";
$queryParams[] = $perPage;
$queryParams[] = $offset;

$st = mysqli_prepare($conn, $sql);
if ($st) {
    mysqli_stmt_bind_param($st, $queryTypes, ...$queryParams);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $leaveHistory = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
}

$leaveStats = [
    'total_requests' => 0,
    'total_days' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];
$statsStmt = mysqli_prepare($conn, "
    SELECT
        COUNT(*) AS total_requests,
        SUM(total_days) AS total_days,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected
    FROM leave_requests
    WHERE employee_id = ?
");
if ($statsStmt) {
    mysqli_stmt_bind_param($statsStmt, "i", $employeeId);
    mysqli_stmt_execute($statsStmt);
    $statsRes = mysqli_stmt_get_result($statsStmt);
    $stats = $statsRes ? mysqli_fetch_assoc($statsRes) : [];
    $leaveStats = [
        'total_requests' => (int)($stats['total_requests'] ?? 0),
        'total_days' => (float)($stats['total_days'] ?? 0),
        'pending' => (int)($stats['pending'] ?? 0),
        'approved' => (int)($stats['approved'] ?? 0),
        'rejected' => (int)($stats['rejected'] ?? 0),
    ];
    mysqli_stmt_close($statsStmt);
}
$leaveStats['total_days'] = rtrim(rtrim(number_format((float)$leaveStats['total_days'], 1, '.', ''), '0'), '.');
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>My Leave History - TEK-C</title>

<link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
<link href="assets/css/layout-styles.css" rel="stylesheet" />
<link href="assets/css/topbar.css" rel="stylesheet" />
<link href="assets/css/footer.css" rel="stylesheet" />

<style>
:root{
  --page-bg:#f5f7fb;
  --card-bg:#ffffff;
  --border:#e5e7eb;
  --text:#111827;
  --muted:#6b7280;
  --soft:#f8fafc;
  --shadow:0 10px 26px rgba(15,23,42,.055);
  --radius:15px;
}
body{ background:var(--page-bg); }
.content-scroll{ flex:1 1 auto; overflow:auto; padding:16px; }
.projects-wrapper{ width:100%; }
.page-heading{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
.page-heading h1{ font-size:19px; font-weight:900; color:var(--text); margin:0; }
.page-heading p{ margin:3px 0 0; color:var(--muted); font-size:12px; font-weight:600; }
.primary-btn{ border:0; background:#111827; color:#fff; height:36px; padding:0 14px; border-radius:11px; font-size:12px; font-weight:900; display:inline-flex; align-items:center; gap:7px; text-decoration:none; white-space:nowrap; }
.primary-btn:hover{ background:#020617; color:#fff; }
.secondary-btn{
  border:1px solid var(--border)!important;
  background:#fff!important;
  color:#334155!important;
  height:36px;
  padding:0 14px;
  border-radius:11px;
  font-size:12px;
  font-weight:900;
  display:inline-flex;
  align-items:center;
  gap:7px;
  text-decoration:none;
  white-space:nowrap;
}
.secondary-btn:hover{
  border-color:#cbd5e1!important;
  background:#f8fafc!important;
  color:#111827!important;
}
.export-btn{ background:#10b981; }
.export-btn:hover{ background:#059669; }
.stat-card{ background:var(--card-bg); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:12px 13px; min-height:78px; display:flex; align-items:center; gap:11px; }
.stat-ic{ width:38px; height:38px; border-radius:12px; display:grid; place-items:center; color:#fff; font-size:17px; }
.blue{ background:#2f80ed; }
.orange{ background:#f2994a; }
.green{ background:#27ae60; }
.red{ background:#eb5757; }
.gray{ background:#64748b; }
.stat-label{ color:var(--muted); font-weight:800; font-size:10.5px; text-transform:uppercase; }
.stat-value{ font-size:24px; font-weight:950; color:#111827; }
.panel{ background:var(--card-bg); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:13px; }
.panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:10px; }
.panel-title{ font-weight:900; font-size:14px; margin:0; color:#111827; }
.panel-subtitle{ color:var(--muted); font-size:11px; font-weight:700; margin-top:2px; }
.filter-bar{ display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.search-box{ position:relative; flex:1 1 260px; max-width:430px; }
.search-box i{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; }
.search-box input{ width:100%; height:36px; border:1px solid var(--border); border-radius:11px; background:#fff; padding:0 12px 0 34px; font-size:12px; font-weight:700; color:var(--text); outline:none; }
.search-box input:focus{ border-color:#bfdbfe; box-shadow:0 0 0 3px rgba(59,130,246,.10); }
.filter-select{ height:36px; border:1px solid var(--border); border-radius:11px; background:#fff; padding:0 42px 0 12px; font-size:12px; font-weight:800; min-width:145px; }
.filter-submit{ height:36px; border:0; background:#111827; color:#fff; border-radius:11px; padding:0 12px; font-size:12px; font-weight:900; }
.compact-table-wrap{ width:100%; border:1px solid var(--border); border-radius:13px; overflow:hidden; background:#fff; }
.compact-table{ width:100%; margin:0; table-layout:auto; }
.compact-table thead th{ background:var(--soft); color:#64748b; font-size:10px; text-transform:uppercase; font-weight:900; border-bottom:1px solid var(--border)!important; padding:8px 9px; }
.compact-table tbody td{ padding:8px 9px; vertical-align:middle; border-color:#eef2f7; color:#334155; font-weight:700; font-size:11.5px; }
.compact-table tbody tr:hover{ background:#fbfdff; }
.table-title-cell{ display:flex; align-items:center; gap:8px; }
.table-icon{ width:26px; height:26px; border-radius:8px; display:grid; place-items:center; background:#eff6ff; color:#2563eb; font-size:13px; flex:0 0 auto; }
.table-primary-text{ color:#111827; font-size:11.5px; font-weight:900; }
.table-secondary-text{ color:#64748b; font-size:10px; font-weight:700; margin-top:1px; }
.badge-pill{ border-radius:999px; padding:5px 8px; font-weight:900; font-size:10px; display:inline-flex; align-items:center; gap:6px; border:1px solid transparent; white-space:nowrap; }
.mini-dot{ width:6px; height:6px; border-radius:50%; background:currentColor; }
.ontrack{ color:#15803d; background:#dcfce7; border-color:#bbf7d0; }
.progressing{ color:#2563eb; background:#dbeafe; border-color:#bfdbfe; }
.pending{ color:#6d28d9; background:#ede9fe; border-color:#ddd6fe; }
.atrisk{ color:#b91c1c; background:#fee2e2; border-color:#fecaca; }
.delayed{ color:#a16207; background:#fef3c7; border-color:#fde68a; }
.neutral{ color:#475569; background:#f1f5f9; border-color:#e2e8f0; }
.action-group{ display:flex; justify-content:flex-end; gap:5px; }
.action-btn{ width:27px; height:27px; border-radius:9px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; text-decoration:none; }
.view-btn{ color:#475569; background:#f8fafc; }
.file-btn{ color:#10b981; background:#ecfdf5; }
.pagination-wrap{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding-top:12px; flex-wrap:wrap; }
.pagination-info{ color:var(--muted); font-size:11px; font-weight:700; }
.page-mini{ height:30px; min-width:30px; padding:0 10px; border:1px solid var(--border); border-radius:9px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; color:#334155; font-size:11px; font-weight:900; background:#fff; }
.page-mini.active{ background:#111827; color:#fff; border-color:#111827; }
.page-mini.disabled{ opacity:.45; pointer-events:none; }
.empty-state{
  text-align:center;
  color:#64748b;
  padding:30px 12px;
  font-size:12px;
  font-weight:900;
}
.empty-state i{
  font-size:34px;
  display:block;
  margin-bottom:8px;
  opacity:.45;
}
.reason-cell{
  max-width:260px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
@media(max-width:991.98px){
  .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
  .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
  .sidebar.open, .sidebar.active, .sidebar.show{ transform:translateX(0)!important; }
}

@media(max-width:1199px){
  .compact-table thead{ display:none; }
  .compact-table,.compact-table tbody,.compact-table tr,.compact-table td{ display:block; width:100%; }
  .compact-table tbody tr{ border-bottom:1px solid var(--border); padding:10px; }
  .compact-table tbody td{ border:0; display:flex; justify-content:space-between; gap:12px; }
  .compact-table tbody td::before{ content:attr(data-label); font-size:10px; font-weight:900; color:#64748b; text-transform:uppercase; flex:0 0 95px; }
  .compact-table tbody td:first-child{ display:block; }
  .compact-table tbody td:first-child::before{ display:none; }
  .reason-cell{ max-width:none; white-space:normal; text-align:right; }
  .action-group{ justify-content:flex-start; }
}
@media(max-width:768px){
  .content-scroll{ padding:12px 10px 12px!important; }
  .page-heading{ align-items:flex-start; flex-direction:column; }
  .filter-bar{ align-items:stretch; }
  .filter-select,.filter-submit,.primary-btn{ width:100%; justify-content:center; }
}
</style>
</head>
<body>
<div class="app">
<?php include 'includes/sidebar.php'; ?>
<main class="main" aria-label="Main">
<?php include 'includes/topbar.php'; ?>

<div class="content-scroll">
<div class="container-fluid projects-wrapper px-0">

<div class="page-heading">
  <div>
    <h1>My Leave History</h1>
    <p>View, search and export your leave applications</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="apply-leave.php" class="primary-btn"><i class="bi bi-plus-circle"></i> Apply Leave</a>
    <a href="export-leave-history.php?<?php echo e(http_build_query($_GET)); ?>" class="primary-btn export-btn"><i class="bi bi-download"></i> Export</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-ic blue"><i class="bi bi-inboxes"></i></div><div><div class="stat-label">Total Requests</div><div class="stat-value"><?php echo e($leaveStats['total_requests']); ?></div></div></div></div>
  <div class="col-12 col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-ic gray"><i class="bi bi-calendar2-week"></i></div><div><div class="stat-label">Total Leave Days</div><div class="stat-value"><?php echo e($leaveStats['total_days']); ?></div></div></div></div>
  <div class="col-12 col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-label">Pending</div><div class="stat-value"><?php echo e($leaveStats['pending']); ?></div></div></div></div>
  <div class="col-12 col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-ic green"><i class="bi bi-check2-circle"></i></div><div><div class="stat-label">Approved</div><div class="stat-value"><?php echo e($leaveStats['approved']); ?></div></div></div></div>
  <div class="col-12 col-sm-6 col-xl-2"><div class="stat-card"><div class="stat-ic red"><i class="bi bi-x-circle"></i></div><div><div class="stat-label">Rejected</div><div class="stat-value"><?php echo e($leaveStats['rejected']); ?></div></div></div></div>
</div>

<div class="panel">
  <div class="panel-header">
    <div>
      <h3 class="panel-title">Leave Records</h3>
      <div class="panel-subtitle">Showing <?php echo count($leaveHistory); ?> of <?php echo (int)$totalRecords; ?> records</div>
    </div>
  </div>

  <form method="GET" class="filter-bar">
    <div class="search-box">
      <i class="bi bi-search"></i>
      <input type="text" name="search" placeholder="Search ID, type, project or reason..." value="<?php echo e($searchTerm); ?>">
    </div>
    <select class="filter-select" name="year">
      <option value="all">All Years</option>
      <?php foreach($years as $year): ?>
        <option value="<?php echo (int)$year; ?>" <?php echo ((string)$filterYear === (string)$year ? 'selected' : ''); ?>><?php echo (int)$year; ?></option>
      <?php endforeach; ?>
    </select>
    <select class="filter-select" name="status">
      <option value="all">All Status</option>
      <option value="pending" <?php echo (strtolower($filterStatus) === 'pending' ? 'selected' : ''); ?>>Pending</option>
      <option value="approved" <?php echo (strtolower($filterStatus) === 'approved' ? 'selected' : ''); ?>>Approved</option>
      <option value="rejected" <?php echo (strtolower($filterStatus) === 'rejected' ? 'selected' : ''); ?>>Rejected</option>
      <option value="cancelled" <?php echo (strtolower($filterStatus) === 'cancelled' ? 'selected' : ''); ?>>Cancelled</option>
    </select>
    <button type="submit" class="filter-submit"><i class="bi bi-funnel"></i> Filter</button>
    <a href="my-leave-history.php" class="secondary-btn"><i class="bi bi-arrow-repeat"></i> Reset</a>
  </form>

  <div class="compact-table-wrap">
    <table class="table compact-table align-middle">
      <thead>
        <tr>
          <th>Leave</th>
          <th>Project</th>
          <th>Dates</th>
          <th>Total</th>
          <th>Status</th>
          <th>Applied</th>
          <th>Reason</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($leaveHistory)): ?>
        <tr>
          <td colspan="8">
            <div class="empty-state">
              <i class="bi bi-inbox"></i>
              No leave history found.
            </div>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach($leaveHistory as $leave): ?>
          <?php
            [$stLabel, $stClass, $stIcon] = statusBadgeMeta($leave['status'] ?? '');
            $projectName = getLeaveProjectName($leave);
            $projectId = getLeaveProjectId($leave);
          ?>
          <tr>
            <td data-label="Leave">
              <div class="table-title-cell">
                <div class="table-icon"><i class="bi bi-calendar-check"></i></div>
                <div>
                  <div class="table-primary-text"><?php echo e($leave['leave_type'] ?? 'Leave'); ?> <span class="text-muted">#<?php echo (int)$leave['id']; ?></span></div>
                  <div class="table-secondary-text"><?php echo e((string)($leave['total_days'] ?? '0')); ?> day(s)</div>
                </div>
              </div>
            </td>
            <td data-label="Project">
              <div class="table-primary-text"><?php echo $projectName !== '' ? e($projectName) : '—'; ?></div>
              <div class="table-secondary-text"><?php echo e($leave['project_location'] ?? ''); ?></div>
            </td>
            <td data-label="Dates">
              <div class="table-primary-text"><?php echo e(safeDate($leave['from_date'] ?? '')); ?></div>
              <div class="table-secondary-text">to <?php echo e(safeDate($leave['to_date'] ?? '')); ?></div>
            </td>
            <td data-label="Total"><div class="table-primary-text"><?php echo e($leave['total_days'] ?? '—'); ?></div></td>
            <td data-label="Status"><span class="badge-pill <?php echo e($stClass); ?>"><i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?></span></td>
            <td data-label="Applied">
              <div class="table-primary-text"><?php echo e(safeDateTime($leave['applied_at'] ?? '')); ?></div>
              <?php if(!empty($leave['approver_name'])): ?><div class="table-secondary-text">Approver: <?php echo e($leave['approver_name']); ?></div><?php endif; ?>
            </td>
            <td data-label="Reason"><div class="table-secondary-text reason-cell"><?php echo e($leave['reason'] ?? '—'); ?></div></td>
            <td data-label="Actions">
              <div class="action-group">
                <?php if($projectId > 0): ?>
                  <a href="view-site.php?id=<?php echo (int)$projectId; ?>" class="action-btn view-btn" title="View Project"><i class="bi bi-building"></i></a>
                <?php endif; ?>
                <a href="leave-details.php?id=<?php echo (int)$leave['id']; ?>" class="action-btn file-btn" title="View Leave"><i class="bi bi-box-arrow-up-right"></i></a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="pagination-wrap">
    <div class="pagination-info">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
    <div class="d-flex gap-1 flex-wrap">
      <?php
        $baseQuery = $_GET;
        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);
        $baseQuery['page'] = $prevPage;
      ?>
      <a class="page-mini <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo e(http_build_query($baseQuery)); ?>"><i class="bi bi-chevron-left"></i></a>
      <?php for($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
        <?php $baseQuery['page'] = $i; ?>
        <a class="page-mini <?php echo $i === $page ? 'active' : ''; ?>" href="?<?php echo e(http_build_query($baseQuery)); ?>"><?php echo (int)$i; ?></a>
      <?php endfor; ?>
      <?php $baseQuery['page'] = $nextPage; ?>
      <a class="page-mini <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="?<?php echo e(http_build_query($baseQuery)); ?>"><i class="bi bi-chevron-right"></i></a>
    </div>
  </div>
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
