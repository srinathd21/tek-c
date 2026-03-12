<?php
session_start();
require_once 'includes/db-config.php';

$error = '';
$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- Helpers ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($d, $dash='—'){
  $d = trim((string)$d);
  if ($d === '' || $d === '0000-00-00') return $dash;
  return e($d);
}

function safeText($t, $dash='—'){
  $t = trim((string)$t);
  return ($t === '') ? $dash : e($t);
}

function showMoney($v, $dash='—'){
  if ($v === null) return $dash;
  $v = trim((string)$v);
  if ($v === '') return $dash;
  if (!is_numeric($v)) return e($v);
  return number_format((float)$v, 2);
}

function decodeJsonList($json){
  if ($json === null) return [];
  $json = trim((string)$json);
  if ($json === '' || strtolower($json) === 'null') return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}

function renderKVPanel($title, $rows){
  ob_start(); ?>
  <div class="panel mb-4">
    <div class="panel-header">
      <h3 class="panel-title"><?php echo e($title); ?></h3>
      <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0" style="width:100%">
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="thk"><?php echo e($r['label']); ?></td>
              <td><?php echo $r['value']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php return ob_get_clean();
}

function renderJsonPanel($title, $items, $cols){
  ob_start(); ?>
  <div class="panel mb-4">
    <div class="panel-header">
      <h3 class="panel-title"><?php echo e($title); ?></h3>
      <span class="count-pill"><?php echo (int)count($items); ?> items</span>
    </div>

    <?php if (empty($items)): ?>
      <div class="empty">No records</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle mb-0" style="width:100%">
          <thead>
            <tr>
              <?php foreach ($cols as $c): ?>
                <th><?php echo e($c['label']); ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <?php foreach ($cols as $c): ?>
                  <?php
                    $val = $it[$c['key']] ?? '';
                    $val = trim((string)$val);
                  ?>
                  <td><?php echo ($val === '') ? '—' : e($val); ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php return ob_get_clean();
}

// ---------------- Input ----------------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { die("Invalid DPR id."); }

$download = isset($_GET['download']) && (int)$_GET['download'] === 1;

// ---------------- Fetch DPR ----------------
$sql = "
  SELECT
    d.*,

    s.project_name,
    s.project_location,
    s.project_type,
    s.scope_of_work,
    s.contract_value,
    s.start_date AS project_start_date,
    s.expected_completion_date,
    s.pmc_charges,
    s.agreement_number,
    s.agreement_date,
    s.work_order_date,

    c.id AS client_id,
    c.client_name,
    c.company_name,
    c.client_type,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.state AS client_state,
    c.office_address,
    c.site_address,

    emp.full_name AS employee_name,
    emp.designation AS employee_designation

  FROM dpr_reports d
  INNER JOIN sites s ON s.id = d.site_id
  INNER JOIN clients c ON c.id = s.client_id
  LEFT JOIN employees emp ON emp.id = d.employee_id
  WHERE d.id = ?
  LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) { die("Prepare failed: " . mysqli_error($conn)); }
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);
$dpr = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$dpr) { die("DPR not found."); }

// ---------------- Parse JSON fields ----------------
$manpower    = decodeJsonList($dpr['manpower_json'] ?? null);
$machinery   = decodeJsonList($dpr['machinery_json'] ?? null);
$material    = decodeJsonList($dpr['material_json'] ?? null);
$progress    = decodeJsonList($dpr['work_progress_json'] ?? null);
$constraints = decodeJsonList($dpr['constraints_json'] ?? null);

// ---------------- Derived ----------------
$clientName = trim((string)($dpr['client_name'] ?? ''));
$company    = trim((string)($dpr['company_name'] ?? ''));
$clientLine = ($company !== '') ? ($clientName.' • '.$company) : $clientName;

$preparedBy = trim((string)($dpr['prepared_by'] ?? ''));
if ($preparedBy === '') $preparedBy = trim((string)($dpr['employee_name'] ?? ''));

$dprNoSafe  = trim((string)($dpr['dpr_no'] ?? 'DPR'));
$fileName   = preg_replace('/[^A-Za-z0-9\-_\.]/', '_', $dprNoSafe) . ".html";

// ---------------- Build report content ----------------
$reportHtml = '';
$reportHtml .= renderKVPanel('DPR Overview', [
  ['label'=>'DPR No', 'value'=> e($dpr['dpr_no'] ?? '—')],
  ['label'=>'DPR Date', 'value'=> safeDate($dpr['dpr_date'] ?? '—')],
  ['label'=>'Weather', 'value'=> safeText($dpr['weather'] ?? '')],
  ['label'=>'Site Condition', 'value'=> safeText($dpr['site_condition'] ?? '')],
  ['label'=>'Prepared By', 'value'=> e($preparedBy !== '' ? $preparedBy : '—')],
  ['label'=>'Report Distribute To', 'value'=> e($dpr['report_distribute_to'] ?? '—')],
  ['label'=>'Created At', 'value'=> e($dpr['created_at'] ?? '—')],
]);

$reportHtml .= renderKVPanel('Project & Client', [
  ['label'=>'Project', 'value'=> e($dpr['project_name'] ?? '—')],
  ['label'=>'Type / Location', 'value'=> e(($dpr['project_type'] ?? '—').' • '.($dpr['project_location'] ?? '—'))],
  ['label'=>'Agreement No', 'value'=> e($dpr['agreement_number'] ?? '—')],
  ['label'=>'Agreement Date', 'value'=> safeDate($dpr['agreement_date'] ?? '—')],
  ['label'=>'Work Order Date', 'value'=> safeDate($dpr['work_order_date'] ?? '—')],
  ['label'=>'Scope', 'value'=> e($dpr['scope_of_work'] ?? '—')],
  ['label'=>'Contract Value', 'value'=> '₹ '.e(showMoney($dpr['contract_value'] ?? null))],
  ['label'=>'PMC Charges', 'value'=> '₹ '.e(showMoney($dpr['pmc_charges'] ?? null))],
  ['label'=>'Project Schedule', 'value'=> safeDate($dpr['project_start_date'] ?? '—').' → '.safeDate($dpr['expected_completion_date'] ?? '—')],
  ['label'=>'Client', 'value'=> e($clientLine)],
  ['label'=>'Client Type / State', 'value'=> e(($dpr['client_type'] ?? '—').' • '.($dpr['client_state'] ?? '—'))],
  ['label'=>'Client Contact', 'value'=> e(($dpr['client_mobile'] ?? '—').' • '.($dpr['client_email'] ?? '—'))],
]);

$reportHtml .= renderKVPanel('Schedule & Duration', [
  ['label'=>'Schedule Start', 'value'=> safeDate($dpr['schedule_start'] ?? '—')],
  ['label'=>'Schedule End', 'value'=> safeDate($dpr['schedule_end'] ?? '—')],
  ['label'=>'Schedule Projected', 'value'=> safeDate($dpr['schedule_projected'] ?? '—')],
  ['label'=>'Duration Total', 'value'=> e($dpr['duration_total'] ?? '—')],
  ['label'=>'Duration Elapsed', 'value'=> e($dpr['duration_elapsed'] ?? '—')],
  ['label'=>'Duration Balance', 'value'=> e($dpr['duration_balance'] ?? '—')],
  ['label'=>'Employee', 'value'=> e(($dpr['employee_name'] ?? '—') . (($dpr['employee_designation'] ?? '') ? ' • '.$dpr['employee_designation'] : ''))],
]);

$reportHtml .= renderJsonPanel('Manpower', $manpower, [
  ['key'=>'agency','label'=>'Agency'],
  ['key'=>'category','label'=>'Category'],
  ['key'=>'unit','label'=>'Unit'],
  ['key'=>'qty','label'=>'Qty'],
  ['key'=>'remark','label'=>'Remark'],
]);

$reportHtml .= renderJsonPanel('Machinery', $machinery, [
  ['key'=>'equipment','label'=>'Equipment'],
  ['key'=>'unit','label'=>'Unit'],
  ['key'=>'qty','label'=>'Qty'],
  ['key'=>'remark','label'=>'Remark'],
]);

$reportHtml .= renderJsonPanel('Material', $material, [
  ['key'=>'vendor','label'=>'Vendor'],
  ['key'=>'material','label'=>'Material'],
  ['key'=>'unit','label'=>'Unit'],
  ['key'=>'qty','label'=>'Qty'],
  ['key'=>'remark','label'=>'Remark'],
]);

$reportHtml .= renderJsonPanel('Work Progress', $progress, [
  ['key'=>'task','label'=>'Task'],
  ['key'=>'duration','label'=>'Duration'],
  ['key'=>'start','label'=>'Start'],
  ['key'=>'end','label'=>'End'],
  ['key'=>'status','label'=>'Status'],
  ['key'=>'reasons','label'=>'Reasons'],
]);

$reportHtml .= renderJsonPanel('Constraints / Issues', $constraints, [
  ['key'=>'issue','label'=>'Issue'],
  ['key'=>'status','label'=>'Status'],
  ['key'=>'date','label'=>'Date'],
  ['key'=>'remark','label'=>'Remark'],
]);

// ---------------- Download mode ----------------
if ($download) {
  header('Content-Type: text/html; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$fileName.'"');

  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo e($dprNoSafe); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <style>
      body{ padding:20px; background:#fff; }
      .panel{ border:1px solid #e5e7eb; border-radius:14px; padding:16px; margin-bottom:14px; }
      .panel-title{ font-weight:900; font-size:16px; margin:0; color:#111827; }
      .panel-header{ display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
      .panel-menu{ display:none; }
      .thk{ width:220px; color:#6b7280; font-weight:900; font-size:12px; }
      .count-pill{ border:1px solid #e5e7eb; background:#f9fafb; color:#111827; font-weight:900; font-size:11px; padding:4px 8px; border-radius:999px; }
      .empty{ padding:12px; border:1px dashed #e5e7eb; border-radius:14px; background:#f9fafb; color:#6b7280; font-weight:800; }
      table thead th{ font-size:11px; font-weight:900; color:#6b7280; }
      table td{ font-weight:650; color:#374151; }
      .hdr h1{ font-weight:900; font-size:20px; margin:0; }
      .hdr .sub{ color:#6b7280; font-weight:700; margin-top:6px; font-size:12px; }
    </style>
  </head>
  <body>
    <div class="hdr mb-3">
      <h1>DPR: <?php echo e($dpr['dpr_no'] ?? ''); ?></h1>
      <div class="sub">
        Date: <?php echo safeDate($dpr['dpr_date'] ?? ''); ?> |
        Project: <?php echo e($dpr['project_name'] ?? ''); ?> |
        Prepared: <?php echo e($preparedBy !== '' ? $preparedBy : '—'); ?>
      </div>
    </div>
    <?php echo $reportHtml; ?>
  </body>
  </html>
  <?php

  if (isset($conn)) { mysqli_close($conn); }
  exit;
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>View DPR - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

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

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .btn-soft{
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 10px 14px;
      color: #111827;
      font-weight: 800;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration:none;
      white-space: nowrap;
    }
    .btn-soft:hover { background: var(--bg); color: var(--blue); }

    .btn-primary2{
      background: var(--blue);
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
      text-decoration:none;
      white-space: nowrap;
    }
    .btn-primary2:hover { background: #2a8bc9; color: white; box-shadow: 0 12px 24px rgba(45, 156, 219, 0.25); }

    .chip{
      display:inline-flex; align-items:center; gap:6px;
      border:1px solid #e5e7eb; background:#f9fafb; border-radius:999px;
      padding:4px 8px; font-weight:800; font-size:11px; color:#111827;
      white-space:nowrap;
    }
    .count-pill{
      border:1px solid #e5e7eb; background:#f9fafb; color:#111827;
      font-weight:900; font-size:11px; padding:4px 8px; border-radius:999px;
      white-space:nowrap;
    }
    .thk{ width:240px; color:#6b7280; font-weight:900; }

    .empty{ padding:14px; color:#6b7280; font-weight:800; background:#f9fafb; border:1px dashed #e5e7eb; border-radius:14px; }

    table thead th{
      font-size: 11px;
      letter-spacing: .15px;
      color:#6b7280;
      font-weight: 800;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: normal !important;
    }
    table td{
      vertical-align: top;
      border-color: var(--border);
      font-weight: 650;
      color:#374151;
      padding: 10px 10px !important;
      white-space: normal !important;
      word-break: break-word;
    }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
    }
    @media print{
      .no-print{ display:none !important; }
      .content-scroll{ padding:0 !important; }
      .panel{ box-shadow:none !important; }
      body{ background:#fff !important; }
      .app, main{ height:auto !important; overflow:visible !important; }
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
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">View DPR</h1>
            <p class="text-muted mb-0">
              DPR No: <b><?php echo e($dpr['dpr_no'] ?? ''); ?></b> •
              Date: <b><?php echo safeDate($dpr['dpr_date'] ?? ''); ?></b> •
              Project: <b><?php echo e($dpr['project_name'] ?? ''); ?></b>
            </p>

            <div class="mt-2 d-flex flex-wrap gap-2">
              <span class="chip"><i class="bi bi-cloud-sun"></i> Weather: <?php echo safeText($dpr['weather'] ?? ''); ?></span>
              <span class="chip"><i class="bi bi-geo"></i> Site: <?php echo safeText($dpr['site_condition'] ?? ''); ?></span>
              <span class="chip"><i class="bi bi-person-badge"></i> Prepared: <?php echo e($preparedBy !== '' ? $preparedBy : '—'); ?></span>
            </div>
          </div>

          <div class="no-print d-flex align-items-center gap-2 flex-wrap">
            <a href="reports.php?type=dpr" class="btn-soft">
              <i class="bi bi-arrow-left"></i> Back
            </a>
            <button type="button" class="btn-soft" onclick="window.print()">
              <i class="bi bi-printer"></i> Print
            </button>
            <a href="view-dpr.php?id=<?php echo (int)$id; ?>&download=1" class="btn-primary2">
              <i class="bi bi-download"></i> Download
            </a>
          </div>
        </div>

        <!-- Content -->
        <?php echo $reportHtml; ?>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- ✅ Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ✅ jQuery (added so sidebar-toggle works even if it uses $) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- ✅ TEK-C Custom JS (REQUIRED for sidebar toggle) -->
<script src="assets/js/sidebar-toggle.js"></script>

</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>
