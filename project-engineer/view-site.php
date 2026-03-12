<?php
// view-site.php — View Site + FULL History of Reports (DPR / DAR / MA / MOM / MPT / Checklist)
// ✅ Uses your real tables:
//    dpr_reports, dar_reports, ma_reports, mom_reports, mpt_reports, checklist_reports
// ✅ Access: Site Manager OR assigned Project Engineer
// ✅ Filters: type + from/to date
// ✅ Mobile: tables -> cards
// ✅ Print buttons: uses print_url templates in config

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

// ---------------- INPUT ----------------
$siteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($siteId <= 0) die("Invalid site id");

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDateDMY($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}
function safeDateYMD($v){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return '';
  $ts = strtotime($v);
  return $ts ? date('Y-m-d', $ts) : '';
}
function safeTime($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

// ---------------- REPORT CONFIG (MATCHES YOUR DB) ----------------
$REPORTS = [
  'DPR' => [
    'table'       => 'dpr_reports',
    'id_col'      => 'id',
    'site_col'    => 'site_id',
    'emp_col'     => 'employee_id',
    'no_col'      => 'dpr_no',
    'date_col'    => 'dpr_date',
    'created_col' => 'created_at',
    // NOTE: in your report.php you used report-print.php for DPR; keep whatever is correct
    'print_url'   => 'report-print.php?view=%d',
  ],
  'DAR' => [
    'table'       => 'dar_reports',
    'id_col'      => 'id',
    'site_col'    => 'site_id',
    'emp_col'     => 'employee_id',
    'no_col'      => 'dar_no',
    'date_col'    => 'dar_date',
    'created_col' => 'created_at',
    'print_url'   => 'report-dar-print.php?view=%d',
  ],
  'MA' => [
    'table'       => 'ma_reports',
    'id_col'      => 'id',
    'site_col'    => 'site_id',
    'emp_col'     => 'employee_id',
    'no_col'      => 'ma_no',
    'date_col'    => 'ma_date',
    'created_col' => 'created_at',
    'print_url'   => 'report-ma-print.php?view=%d',
  ],
  'MOM' => [
    'table'       => 'mom_reports',
    'id_col'      => 'id',
    'site_col'    => 'site_id',
    'emp_col'     => 'employee_id',
    'no_col'      => 'mom_no',
    'date_col'    => 'mom_date',
    'created_col' => 'created_at',
    'print_url'   => 'report-mom-print.php?view=%d',
  ],
  'MPT' => [
    'table'       => 'mpt_reports',
    'id_col'      => 'id',
    'site_col'    => 'site_id',
    'emp_col'     => 'employee_id',
    'no_col'      => 'mpt_no',
    'date_col'    => 'mpt_date',
    'created_col' => 'created_at',
    'print_url'   => 'report-mpt-print.php?view=%d',
  ],
  'CHECKLIST' => [
    'table'       => 'checklist_reports',
    'id_col'      => 'id',
    'site_col'    => 'site_id',
    'emp_col'     => 'employee_id',
    'no_col'      => 'doc_no',
    'date_col'    => 'checklist_date',
    'created_col' => 'created_at',
    'print_url'   => 'report-checklist-print.php?view=%d',
  ],
];

$ICONS = [
  'DPR' => 'bi-journal-text',
  'DAR' => 'bi-clipboard-check',
  'MA'  => 'bi-card-checklist',
  'MOM' => 'bi-people',
  'MPT' => 'bi-calendar2-check',
  'CHECKLIST' => 'bi-check2-square',
];

$STAT_COLORS = [
  'DPR' => 'green',
  'DAR' => 'yellow',
  'MA'  => 'blue',
  'MOM' => 'red',
  'MPT' => 'yellow',
  'CHECKLIST' => 'green',
];

// ---------------- LOAD SITE + PERMISSION ----------------
$site = null;
$sqlSite = "
  SELECT
    s.id, s.project_name, s.project_type, s.project_location, s.scope_of_work,
    s.start_date, s.expected_completion_date, s.created_at,
    s.manager_employee_id,
    c.client_name, c.company_name, c.mobile_number AS client_mobile, c.email AS client_email, c.state AS client_state,
    m.full_name AS manager_name, m.employee_code AS manager_code,
    tl.full_name AS team_lead_name, tl.employee_code AS team_lead_code
  FROM sites s
  INNER JOIN clients c ON c.id = s.client_id
  LEFT JOIN employees m ON m.id = s.manager_employee_id
  LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id
  WHERE s.id = ?
  LIMIT 1
";
$st = mysqli_prepare($conn, $sqlSite);
if ($st) {
  mysqli_stmt_bind_param($st, "i", $siteId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $site = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
if (!$site) die("Site not found");

$isManagerOfSite = ((int)($site['manager_employee_id'] ?? 0) === $employeeId);

$isAssignedEngineer = false;
$st = mysqli_prepare($conn, "SELECT 1 FROM site_project_engineers WHERE site_id=? AND employee_id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "ii", $siteId, $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $isAssignedEngineer = (bool)mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}

if (!$isManagerOfSite && !$isAssignedEngineer) {
  header("Location: index.php");
  exit;
}

// ---------------- FILTERS ----------------
$type = strtoupper(trim((string)($_GET['type'] ?? 'ALL'))); // ALL or keys in $REPORTS
$from = safeDateYMD($_GET['from'] ?? '');
$to   = safeDateYMD($_GET['to'] ?? '');

$validTypes = array_merge(['ALL'], array_keys($REPORTS));
if (!in_array($type, $validTypes, true)) $type = 'ALL';

// ---------------- FETCH HISTORY ----------------
function fetchEmployeesMap(mysqli $conn, array $rows): array {
  if (!$rows) return [];
  $ids = [];
  foreach ($rows as $r) $ids[(int)$r['remp']] = true;
  $ids = array_keys($ids);
  if (!$ids) return [];

  $in = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));
  $sql = "SELECT id, full_name, designation FROM employees WHERE id IN ($in)";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return [];

  mysqli_stmt_bind_param($st, $types, ...$ids);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);

  $map = [];
  while ($er = mysqli_fetch_assoc($res)) {
    $map[(int)$er['id']] = $er;
  }
  mysqli_stmt_close($st);
  return $map;
}

function fetchHistory(mysqli $conn, array $cfg, int $siteId, string $from, string $to): array {
  $t = $cfg['table'];
  $idCol = $cfg['id_col'];
  $siteCol = $cfg['site_col'];
  $empCol = $cfg['emp_col'];
  $noCol = $cfg['no_col'];
  $dateCol = $cfg['date_col'];
  $createdCol = $cfg['created_col'];

  $where = "WHERE $siteCol = ?";
  $bindTypes = "i";
  $bindVals = [$siteId];

  if ($from !== '') {
    $where .= " AND $dateCol >= ?";
    $bindTypes .= "s";
    $bindVals[] = $from;
  }
  if ($to !== '') {
    $where .= " AND $dateCol <= ?";
    $bindTypes .= "s";
    $bindVals[] = $to;
  }

  $sql = "
    SELECT
      $idCol AS rid,
      $noCol AS rno,
      $dateCol AS rdate,
      $createdCol AS rcreated,
      $empCol AS remp
    FROM $t
    $where
    ORDER BY $dateCol DESC, $createdCol DESC
    LIMIT 500
  ";

  $st = mysqli_prepare($conn, $sql);
  if (!$st) return [];

  mysqli_stmt_bind_param($st, $bindTypes, ...$bindVals);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);

  if (!$rows) return [];

  $empMap = fetchEmployeesMap($conn, $rows);
  foreach ($rows as &$r) {
    $eid = (int)$r['remp'];
    $r['emp_name'] = $empMap[$eid]['full_name'] ?? ('Employee #' . $eid);
    $r['emp_desg'] = $empMap[$eid]['designation'] ?? '';
  }
  unset($r);

  return $rows;
}

$history = [];
foreach ($REPORTS as $k => $cfg) $history[$k] = [];

if ($type === 'ALL') {
  foreach ($REPORTS as $k => $cfg) {
    $history[$k] = fetchHistory($conn, $cfg, $siteId, $from, $to);
  }
} else {
  $history[$type] = fetchHistory($conn, $REPORTS[$type], $siteId, $from, $to);
}

// ---------------- STATS ----------------
$counts = [];
$totalReports = 0;
foreach ($REPORTS as $k => $_) {
  $counts[$k] = count($history[$k] ?? []);
  $totalReports += $counts[$k];
}

// ---------------- RENDER (DESKTOP TABLE / MOBILE CARDS) ----------------
function renderHistoryBlock(string $key, array $rows, array $cfg, array $ICONS){
  $icon = $ICONS[$key] ?? 'bi-file-earmark';
  $printTpl = (string)($cfg['print_url'] ?? '');
  ?>
  <div class="panel">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <div class="kv">
          <span class="badge-type"><i class="bi <?php echo e($icon); ?>"></i> <?php echo e($key); ?></span>
          <span class="muted ms-2">Showing latest 500</span>
        </div>
      </div>
    </div>

    <hr style="border-color:#eef2f7;">

    <?php if (empty($rows)): ?>
      <div class="alert alert-warning mb-0" style="border-radius:14px;">
        <i class="bi bi-info-circle me-2"></i> No records found.
      </div>
    <?php else: ?>

      <!-- MOBILE: Cards -->
      <div class="d-block d-md-none">
        <div class="d-grid gap-3">
          <?php foreach ($rows as $r): ?>
            <?php
              $rid = (int)($r['rid'] ?? 0);
              $printUrl = ($printTpl !== '') ? sprintf($printTpl, $rid) : '';
            ?>
            <div class="r-card">
              <div class="top">
                <div style="flex:1 1 auto;">
                  <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                      <div style="font-weight:1000; color:#111827; font-size:14px;"><?php echo e($r['rno'] ?? ''); ?></div>
                      <div class="small-muted mt-1">
                        <i class="bi bi-calendar-event"></i> <?php echo e(safeDateDMY($r['rdate'] ?? '')); ?>
                      </div>
                    </div>
                    <span class="badge-type"><i class="bi <?php echo e($icon); ?>"></i> <?php echo e($key); ?></span>
                  </div>

                  <div class="r-kv">
                    <div class="r-row">
                      <div class="r-key">Created</div>
                      <div class="r-val"><?php echo e(safeTime($r['rcreated'] ?? '')); ?></div>
                    </div>
                    <div class="r-row">
                      <div class="r-key">Prepared</div>
                      <div class="r-val">
                        <?php echo e($r['emp_name'] ?? ''); ?>
                        <?php if (!empty($r['emp_desg'])): ?>
                          <div class="small-muted"><?php echo e($r['emp_desg']); ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="r-actions">
                <?php if ($printUrl !== ''): ?>
                  <a class="btn btn-outline-dark" style="border-radius:12px; font-weight:900;" target="_blank" href="<?php echo e($printUrl); ?>">
                    <i class="bi bi-printer"></i> Print / PDF
                  </a>
                <?php else: ?>
                  <div class="small-muted fw-bold">Print not available.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- DESKTOP: Table -->
      <div class="d-none d-md-block">
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th style="width:70px;">#</th>
                <th>No</th>
                <th>Date</th>
                <th>Created</th>
                <th>Prepared By</th>
                <th class="text-end" style="width:240px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=1; foreach ($rows as $r): ?>
                <?php
                  $rid = (int)($r['rid'] ?? 0);
                  $printUrl = ($printTpl !== '') ? sprintf($printTpl, $rid) : '';
                ?>
                <tr>
                  <td style="font-weight:900;"><?php echo (int)$i++; ?></td>
                  <td style="font-weight:900; color:#111827;"><?php echo e($r['rno'] ?? ''); ?></td>
                  <td><?php echo e(safeDateDMY($r['rdate'] ?? '')); ?></td>
                  <td><?php echo e(safeTime($r['rcreated'] ?? '')); ?></td>
                  <td>
                    <div style="font-weight:900; color:#111827;"><?php echo e($r['emp_name'] ?? ''); ?></div>
                    <?php if (!empty($r['emp_desg'])): ?>
                      <div class="muted"><?php echo e($r['emp_desg']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <?php if ($printUrl !== ''): ?>
                      <a class="btn-action" target="_blank" href="<?php echo e($printUrl); ?>">
                        <i class="bi bi-printer"></i> Print / PDF
                      </a>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php endif; ?>
  </div>
  <?php
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>View Site - TEK-C</title>

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
      margin-bottom:14px;
    }
    .title{ font-weight:1000; color:#111827; margin:0; }
    .sub{ color:#6b7280; font-weight:800; font-size:13px; margin:4px 0 0; }

    .badge-type{
      font-weight:1000;
      border-radius:999px;
      padding:6px 10px;
      border:1px solid #e5e7eb;
      background:#fff;
      display:inline-flex;
      align-items:center;
      gap:8px;
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
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .kv{ font-weight:900; color:#111827; }
    .muted{ color:#6b7280; font-weight:800; }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:900;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: nowrap;
      background:#f9fafb;
    }
    .table td{
      vertical-align: top;
      border-color: var(--border);
      font-weight:800; color:#111827;
      padding: 10px 10px !important;
      white-space: normal;
      word-break: break-word;
    }

    .btn-action{
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 10px 12px;
      color: #374151;
      font-size: 13px;
      font-weight: 1000;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
    }
    .btn-action:hover{ background:#f9fafb; color: var(--blue); }

    /* Mobile cards (history) */
    .r-card{
      border:1px solid var(--border);
      border-radius:16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding:12px;
    }
    .r-kv{ margin-top:10px; display:grid; gap:8px; }
    .r-row{ display:flex; gap:10px; align-items:flex-start; }
    .r-key{ flex:0 0 90px; color:#6b7280; font-weight:1000; font-size:12px; }
    .r-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; }
    .r-actions{ margin-top:12px; display:grid; gap:8px; }
    .r-actions .btn{ width:100%; }

    .form-label{ font-weight:900; color:#374151; font-size:13px; }
    .form-control, .form-select{
      border:2px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 12px;
      font-weight: 800;
      font-size: 14px;
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
    }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div class="content-scroll">
      <div class="container-fluid maxw">

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <h1 class="title"><?php echo e($site['project_name'] ?? 'Site'); ?></h1>
            <p class="sub">
              <i class="bi bi-geo-alt"></i> <?php echo e($site['project_location'] ?? ''); ?>
              &nbsp;•&nbsp; <i class="bi bi-kanban"></i> <?php echo e($site['project_type'] ?? ''); ?>
            </p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a class="btn-action" href="my-sites.php"><i class="bi bi-arrow-left"></i> Back</a>
            <a class="btn-action" href="view-site.php?id=<?php echo (int)$siteId; ?>"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
          </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-collection"></i></div>
              <div>
                <div class="stat-label">Total Reports</div>
                <div class="stat-value"><?php echo (int)$totalReports; ?></div>
              </div>
            </div>
          </div>

          <?php foreach ($REPORTS as $k => $_): ?>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic <?php echo e($STAT_COLORS[$k] ?? 'blue'); ?>">
                  <i class="bi <?php echo e($ICONS[$k] ?? 'bi-file-earmark'); ?>"></i>
                </div>
                <div>
                  <div class="stat-label"><?php echo e($k); ?></div>
                  <div class="stat-value"><?php echo (int)($counts[$k] ?? 0); ?></div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Site Details -->
        <div class="panel">
          <div class="kv">Site Details</div>
          <div class="muted">Client & management info</div>
          <hr style="border-color:#eef2f7;">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="muted">Client</div>
              <div class="kv">
                <?php
                  $clientLine = trim((string)($site['client_name'] ?? ''));
                  $company = trim((string)($site['company_name'] ?? ''));
                  echo e($company !== '' ? ($clientLine . ' • ' . $company) : $clientLine);
                ?>
              </div>
              <?php if (!empty($site['client_state'])): ?><div class="muted"><i class="bi bi-pin-map"></i> <?php echo e($site['client_state']); ?></div><?php endif; ?>
              <?php if (!empty($site['client_mobile'])): ?><div class="muted"><i class="bi bi-telephone"></i> <?php echo e($site['client_mobile']); ?></div><?php endif; ?>
              <?php if (!empty($site['client_email'])): ?><div class="muted"><i class="bi bi-envelope"></i> <?php echo e($site['client_email']); ?></div><?php endif; ?>
            </div>

            <div class="col-md-6">
              <div class="muted">Manager</div>
              <div class="kv">
                <?php echo e($site['manager_name'] ?? '—'); ?>
                <?php if (!empty($site['manager_code'])): ?>
                  <span class="muted">(<?php echo e($site['manager_code']); ?>)</span>
                <?php endif; ?>
              </div>

              <div class="muted mt-2">Team Lead</div>
              <div class="kv">
                <?php echo e($site['team_lead_name'] ?? '—'); ?>
                <?php if (!empty($site['team_lead_code'])): ?>
                  <span class="muted">(<?php echo e($site['team_lead_code']); ?>)</span>
                <?php endif; ?>
              </div>

              <div class="muted mt-2">
                Start: <?php echo e(safeDateDMY($site['start_date'] ?? '')); ?>
                &nbsp;•&nbsp;
                End: <?php echo e(safeDateDMY($site['expected_completion_date'] ?? '')); ?>
              </div>
            </div>

            <?php if (!empty($site['scope_of_work'])): ?>
              <div class="col-12">
                <div class="muted">Scope of Work</div>
                <div class="kv"><?php echo e($site['scope_of_work']); ?></div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Filters -->
        <div class="panel">
          <div class="kv">Report History</div>
          <div class="muted">Filter by type and date range</div>
          <hr style="border-color:#eef2f7;">

          <form class="row g-2 align-items-end" method="GET" action="view-site.php">
            <input type="hidden" name="id" value="<?php echo (int)$siteId; ?>">

            <div class="col-12 col-md-3">
              <label class="form-label">Type</label>
              <select class="form-select" name="type">
                <option value="ALL" <?php echo $type==='ALL'?'selected':''; ?>>All</option>
                <?php foreach ($REPORTS as $k => $_): ?>
                  <option value="<?php echo e($k); ?>" <?php echo $type===$k?'selected':''; ?>><?php echo e($k); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">From</label>
              <input type="date" class="form-control" name="from" value="<?php echo e($from); ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">To</label>
              <input type="date" class="form-control" name="to" value="<?php echo e($to); ?>">
            </div>

            <div class="col-12 col-md-3 d-flex gap-2">
              <button class="btn btn-primary w-100" type="submit" style="border-radius:12px; font-weight:1000;">
                <i class="bi bi-funnel"></i> Apply
              </button>
              <a class="btn btn-outline-secondary w-100" style="border-radius:12px; font-weight:1000;"
                 href="view-site.php?id=<?php echo (int)$siteId; ?>">
                <i class="bi bi-x-circle"></i> Reset
              </a>
            </div>
          </form>
        </div>

        <?php
          if ($type === 'ALL') {
            foreach ($REPORTS as $k => $cfg) {
              renderHistoryBlock($k, $history[$k] ?? [], $cfg, $ICONS);
            }
          } else {
            renderHistoryBlock($type, $history[$type] ?? [], $REPORTS[$type], $ICONS);
          }
        ?>

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
  if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
  }
} catch (Throwable $e) { }
?>