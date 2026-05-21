<?php
// employee-pending-tasks.php
// Pending + Completed task page for logged-in employee
// Same UI/template style as today-tasks.php
// Mobile responsive card design
// Shows only TODAY DPR, DAR, Checklist reports

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
  die("Database connection failed.");
}

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
function e($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fmtTime($ts) {
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

function columnExists(mysqli $conn, string $table, string $column): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $column = mysqli_real_escape_string($conn, $column);
  $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
  return $res && mysqli_num_rows($res) > 0;
}

function getExistingColumn(mysqli $conn, string $table, array $columns): string {
  foreach ($columns as $column) {
    if (columnExists($conn, $table, $column)) {
      return $column;
    }
  }

  return '';
}

/**
 * Fetch latest report submitted for selected date by this employee, grouped by site.
 * Returns: [bySiteArray, latestCreatedAt]
 */
function fetchBySiteForDate(mysqli $conn, int $employeeId, string $table, array $dateFields, array $noFields, string $targetYmd) {
  $bySite = [];
  $latestCreatedAt = null;

  if (!tableExists($conn, $table)) {
    return [$bySite, $latestCreatedAt];
  }

  $dateField = getExistingColumn($conn, $table, $dateFields);
  $noField = getExistingColumn($conn, $table, $noFields);
  $createdField = getExistingColumn($conn, $table, ['created_at', 'submitted_at', 'updated_at']);
  $siteField = getExistingColumn($conn, $table, ['site_id', 'project_id']);

  $employeeCandidates = ['employee_id', 'created_by', 'prepared_by_id', 'created_user_id', 'user_id'];
  $employeeFields = [];

  foreach ($employeeCandidates as $candidate) {
    if (columnExists($conn, $table, $candidate)) {
      $employeeFields[] = $candidate;
    }
  }

  if ($dateField === '' || empty($employeeFields) || $siteField === '') {
    return [$bySite, $latestCreatedAt];
  }

  $selectNo = $noField !== '' ? "`{$noField}` AS doc_no" : "'' AS doc_no";
  $selectCreated = $createdField !== '' ? "`{$createdField}` AS created_at" : "NULL AS created_at";
  $orderBy = $createdField !== '' ? "`{$createdField}` DESC, id DESC" : "id DESC";

  $employeeWhere = [];
  foreach ($employeeFields as $field) {
    $employeeWhere[] = "`{$field}` = ?";
  }

  $sql = "
    SELECT id, `{$siteField}` AS site_id, {$selectNo}, {$selectCreated}
    FROM `{$table}`
    WHERE (" . implode(' OR ', $employeeWhere) . ")
      AND DATE(`{$dateField}`) = ?
    ORDER BY {$orderBy}
  ";

  $st = mysqli_prepare($conn, $sql);

  if ($st) {
    $types = str_repeat('i', count($employeeFields)) . 's';
    $values = array_fill(0, count($employeeFields), $employeeId);
    $values[] = $targetYmd;

    mysqli_stmt_bind_param($st, $types, ...$values);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);

    while ($row = mysqli_fetch_assoc($res)) {
      $sid = (int)$row['site_id'];

      if (!isset($bySite[$sid])) {
        $bySite[$sid] = $row;
      }

      if (!empty($row['created_at'])) {
        if ($latestCreatedAt === null || strtotime($row['created_at']) > strtotime($latestCreatedAt)) {
          $latestCreatedAt = $row['created_at'];
        }
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

$employeeName  = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');
$employeeEmail = $empRow['email'] ?? '';

// ---------------- Assigned Sites ----------------
$sites = [];
$filterSiteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$filterStatus = strtolower(trim((string)($_GET['status'] ?? 'pending')));
$filterReport = strtolower(trim((string)($_GET['report'] ?? 'all')));
$filterDate = trim((string)($_GET['date'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
  $filterDate = date('Y-m-d');
}

if (!in_array($filterStatus, ['all', 'pending', 'completed'], true)) {
  $filterStatus = 'pending';
}

if ($designation === 'manager') {
  $q = "
    SELECT 
      s.id,
      s.project_name,
      s.project_location,
      c.client_name,
      c.email AS client_email
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
    SELECT 
      s.id,
      s.project_name,
      s.project_location,
      c.client_name,
      c.email AS client_email
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
    if ((int)$s['id'] === $filterSiteId) {
      $ok = true;
      break;
    }
  }

  if ($ok) {
    $sites = array_values(array_filter($sites, fn($s) => (int)$s['id'] === $filterSiteId));
  } else {
    $filterSiteId = 0;
  }
}

$targetYmd = $filterDate;
$todayYmd = $targetYmd;

// ---------------- Report Types ----------------
// All Time Management documents from Project Engineer sidebar.
// Important: table names below follow current DB, e.g. AIT/MAS/DDS use *_main tables.
$reportTypes = [
  [
    'key' => 'dpr',
    'label' => 'DPR',
    'icon' => 'bi-journal-text',
    'table' => 'dpr_reports',
    'dateFields' => ['dpr_date', 'dpr_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['dpr_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'dpr.php?site_id={sid}',
    'openUrl'   => 'dpr.php?site_id={sid}',
    'printFile' => 'report-print.php',
    'downloadSupported' => true,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'dar',
    'label' => 'DAR',
    'icon' => 'bi-check2-square',
    'table' => 'dar_reports',
    'dateFields' => ['dar_date', 'dar_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['dar_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'dar.php?site_id={sid}',
    'openUrl'   => 'dar.php?site_id={sid}',
    'printFile' => 'report-dar-print.php',
    'downloadSupported' => true,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'ma',
    'label' => 'MA',
    'icon' => 'bi-calendar2-week',
    'table' => 'ma_reports',
    'dateFields' => ['ma_date', 'ma_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['ma_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'ma.php?site_id={sid}',
    'openUrl'   => 'ma.php?site_id={sid}',
    'printFile' => 'report-ma-print.php',
    'downloadSupported' => true,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'mpt',
    'label' => 'MPT',
    'icon' => 'bi-list-task',
    'table' => 'mpt_reports',
    'dateFields' => ['mpt_date', 'mpt_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['mpt_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'mpt.php?site_id={sid}',
    'openUrl'   => 'mpt.php?site_id={sid}',
    'printFile' => 'report-mpt-print.php',
    'downloadSupported' => true,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'mom',
    'label' => 'MOM',
    'icon' => 'bi-chat-left-text',
    'table' => 'mom_main',
    'dateFields' => ['mom_date', 'issued_date', 'mom_date', 'report_date', 'date', 'dated', 'meeting_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['mom_no', 'mom_no', 'doc_no', 'report_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'mom.php?site_id={sid}',
    'openUrl'   => 'mom.php?site_id={sid}',
    'printFile' => 'report-mom-main-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'mom-short',
    'label' => 'MOM Short-term',
    'icon' => 'bi-chat-left-quote',
    'table' => 'mom_reports',
    'dateFields' => ['mom_date', 'mom_short_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['mom_no', 'mom_short_no', 'doc_no', 'report_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'mom-short.php?site_id={sid}',
    'openUrl'   => 'mom-short.php?site_id={sid}',
    'printFile' => 'report-mom-print.php',
    'downloadSupported' => true,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'rfi',
    'label' => 'RFI',
    'icon' => 'bi-question-circle',
    'table' => 'rfi_reports',
    'dateFields' => ['rfi_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['rfi_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'rfi.php?site_id={sid}',
    'openUrl'   => 'rfi.php?site_id={sid}',
    'printFile' => '',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'checklist',
    'label' => 'Checklist',
    'icon' => 'bi-card-checklist',
    'table' => 'checklist_reports',
    'dateFields' => ['checklist_date', 'checklist_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['doc_no', 'checklist_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'checklist.php?site_id={sid}',
    'openUrl'   => 'checklist.php?site_id={sid}',
    'printFile' => 'report-checklist-print.php',
    'downloadSupported' => true,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'sat',
    'label' => 'SAT',
    'icon' => 'bi-bar-chart-steps',
    'table' => 'sat_reports',
    'dateFields' => ['sat_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['sat_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'sat.php?site_id={sid}',
    'openUrl'   => 'sat.php?site_id={sid}',
    'printFile' => '',
    'downloadSupported' => false,
    'specialDownloadUrl' => 'sat_report_pdf.php?batch_id={rid}',
  ],
  [
    'key' => 'dlar',
    'label' => 'DLAR',
    'icon' => 'bi-file-earmark-spreadsheet',
    'table' => 'dlar_reports',
    'dateFields' => ['report_date', 'dlar_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['dlar_no', 'dlar_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'dlar.php?site_id={sid}',
    'openUrl'   => 'dlar.php?site_id={sid}',
    'printFile' => 'report-dlar-print.php',
    'downloadSupported' => true,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'ait',
    'label' => 'AIT',
    'icon' => 'bi-cpu',
    'table' => 'ait_main',
    'dateFields' => ['ait_date', 'ait_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['ait_no', 'ait_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'ait.php?site_id={sid}',
    'openUrl'   => 'ait.php?site_id={sid}',
    'printFile' => 'report-ait-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'mas',
    'label' => 'MAS',
    'icon' => 'bi-diagram-3',
    'table' => 'mas_main',
    'dateFields' => ['meeting_date', 'mas_date', 'report_date', 'date', 'dated', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['mas_no', 'mas_no', 'doc_no', 'report_no', 'mom_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'mas.php?site_id={sid}',
    'openUrl'   => 'mas.php?site_id={sid}',
    'printFile' => 'report-mas-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'pd',
    'label' => 'PD',
    'icon' => 'bi-graph-up',
    'table' => 'pd_main',
    'dateFields' => ['pd_date', 'pd_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['pd_no', 'pd_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'pd.php?site_id={sid}',
    'openUrl'   => 'pd.php?site_id={sid}',
    'printFile' => 'report-pd-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'pms',
    'label' => 'PMS',
    'icon' => 'bi-tools',
    'table' => 'pms_main',
    'dateFields' => ['pms_date', 'pms_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['pms_no', 'pms_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'pms.php?site_id={sid}',
    'openUrl'   => 'pms.php?site_id={sid}',
    'printFile' => 'report-pms-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'vfs',
    'label' => 'VFS',
    'icon' => 'bi-eye',
    'table' => 'vfs_main',
    'dateFields' => ['vfs_date', 'vfs_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vft_date', 'wpt_date'],
    'noFields' => ['vfs_no', 'vfs_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'vfs.php?site_id={sid}',
    'openUrl'   => 'vfs.php?site_id={sid}',
    'printFile' => 'report-vfs-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'vft',
    'label' => 'VFT',
    'icon' => 'bi-eye-fill',
    'table' => 'vft_main',
    'dateFields' => ['vft_date', 'vft_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'wpt_date'],
    'noFields' => ['vft_no', 'vft_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'vft.php?site_id={sid}',
    'openUrl'   => 'vft.php?site_id={sid}',
    'printFile' => 'report-vft-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'wpt',
    'label' => 'WPT',
    'icon' => 'bi-database',
    'table' => 'wpt_main',
    'dateFields' => ['wpt_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['wpt_no', 'wpt_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'sat_no'],
    'submitUrl' => 'wpt.php?site_id={sid}',
    'openUrl'   => 'wpt.php?site_id={sid}',
    'printFile' => 'report-wpt-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'dds',
    'label' => 'DDS',
    'icon' => 'bi-database',
    'table' => 'dds_main',
    'dateFields' => ['dds_date', 'dds_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['dds_no', 'dds_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'ddt_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'dds.php?site_id={sid}',
    'openUrl'   => 'dds.php?site_id={sid}',
    'printFile' => 'report-dds-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'ddt',
    'label' => 'DDT',
    'icon' => 'bi-table',
    'table' => 'ddt_main',
    'dateFields' => ['ddt_date', 'ddt_date', 'report_date', 'date', 'dated', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['ddt_no', 'ddt_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'dpt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'ddt.php?site_id={sid}',
    'openUrl'   => 'ddt.php?site_id={sid}',
    'printFile' => 'report-ddt-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ],
  [
    'key' => 'dpt',
    'label' => 'DPT',
    'icon' => 'bi-pie-chart',
    'table' => 'dpt_main',
    'dateFields' => ['dated', 'dpt_date', 'report_date', 'date', 'meeting_date', 'issued_date', 'checklist_date', 'dpr_date', 'dar_date', 'ma_date', 'mpt_date', 'mom_date', 'rfi_date', 'sat_date', 'ait_date', 'dds_date', 'ddt_date', 'dlar_date', 'pd_date', 'pms_date', 'vfs_date', 'vft_date', 'wpt_date'],
    'noFields' => ['dpt_no', 'dpt_no', 'doc_no', 'report_no', 'mom_no', 'mas_no', 'ait_no', 'dds_no', 'ddt_no', 'dlar_no', 'pd_no', 'pms_no', 'vfs_no', 'vft_no', 'wpt_no', 'sat_no'],
    'submitUrl' => 'dpt.php?site_id={sid}',
    'openUrl'   => 'dpt.php?site_id={sid}',
    'printFile' => 'report-dpt-print.php',
    'downloadSupported' => false,
    'specialDownloadUrl' => '',
  ]
];

$validReportKeys = array_column($reportTypes, 'key');
if ($filterReport !== 'all' && !in_array($filterReport, $validReportKeys, true)) {
  $filterReport = 'all';
}

// ---------------- Load selected date reports for each type ----------------
$todayReports = [];
$latestAnyCreatedAt = null;

foreach ($reportTypes as $rt) {
  [$bySite, $latestCreatedAt] = fetchBySiteForDate(
    $conn,
    $employeeId,
    $rt['table'],
    $rt['dateFields'],
    $rt['noFields'],
    $targetYmd
  );

  $todayReports[$rt['key']] = $bySite;

  if (!empty($latestCreatedAt)) {
    if (
      $latestAnyCreatedAt === null ||
      strtotime($latestCreatedAt) > strtotime($latestAnyCreatedAt)
    ) {
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
    WHERE report_date = ?
      AND employee_id = ?
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
    if ($filterReport !== 'all' && $filterReport !== $rt['key']) {
      continue;
    }

    $isDone = isset($todayReports[$rt['key']][$sid]);

    if ($filterStatus === 'pending' && $isDone) {
      continue;
    }

    if ($filterStatus === 'completed' && !$isDone) {
      continue;
    }

    $rep = $isDone ? $todayReports[$rt['key']][$sid] : null;

    if ($isDone) {
      $completedCount++;
    }

    $remarkKey = $sid . '_' . $rt['key'];

    $printUrl = '';
    $downloadUrl = '';
    $mailUrl = '';

    if ($isDone && $rep) {
      $rid = (int)($rep['id'] ?? 0);

      if (!empty($rt['printFile'])) {
        $printUrl = $rt['printFile'] . '?view=' . urlencode((string)$rid);

        if (!empty($rt['downloadSupported'])) {
          $downloadUrl = $rt['printFile'] . '?view=' . urlencode((string)$rid) . '&dl=1';
        }
      }

      if (!empty($rt['specialDownloadUrl'])) {
        $downloadUrl = str_replace('{rid}', urlencode((string)$rid), $rt['specialDownloadUrl']);
      }

      if ($downloadUrl !== '') {
        $projectName = (string)($s['project_name'] ?? 'Project');
        $safeProject = safeFileNamePart($projectName);

        $pdfName = strtoupper($rt['key']) . '_' . $safeProject . '_' . $todayYmd . '.pdf';

        $mailSubject = "TEK-C " . strtoupper($rt['key']) . " - " . $projectName . " - " . date('d M Y', strtotime($todayYmd));

        $mailBody =
"Dear Team,

Please find attached the " . strtoupper($rt['key']) . " for:

Project: {$projectName}
Date: " . date('d M Y', strtotime($todayYmd)) . "
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
$allTasksCount = count($sites) * count($reportTypes);
$allCompletedCount = 0;

foreach ($sites as $s) {
  $sid = (int)$s['id'];
  foreach ($reportTypes as $rt) {
    if (isset($todayReports[$rt['key']][$sid])) {
      $allCompletedCount++;
    }
  }
}

$allPendingCount = max(0, $allTasksCount - $allCompletedCount);
$pendingCount = count(array_filter($taskRows, fn($r) => empty($r['is_done'])));
$completedCount = count(array_filter($taskRows, fn($r) => !empty($r['is_done'])));
$latestSubmitTime = fmtTime($latestAnyCreatedAt);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Time Management Pending Documents - TEK-C</title>

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
      --blue:#2f80ed;
      --green:#27ae60;
      --orange:#f2994a;
      --red:#eb5757;
      --purple:#7c3aed;
    }

    body{ background:var(--page-bg); }
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:16px; }
    .projects-wrapper{ width:100%; }

    .page-heading{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }

    .page-heading h1{
      font-size:19px;
      font-weight:950;
      color:var(--text);
      margin:0;
      display:flex;
      align-items:center;
      gap:8px;
    }

    .page-heading p{
      margin:3px 0 0;
      color:var(--muted);
      font-size:12px;
      font-weight:650;
    }

    .panel,.filter-card{
      background:var(--card-bg);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:13px;
      margin-bottom:14px;
    }

    .panel-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:12px;
    }

    .panel-title{
      font-weight:950;
      font-size:14px;
      color:var(--text);
      margin:0;
      display:flex;
      align-items:center;
      gap:8px;
    }

    .panel-title i{ color:var(--blue); font-size:16px; }
    .panel-subtitle{ color:#64748b; font-size:11px; font-weight:700; margin-top:2px; }

    .stat-card{
      background:#fff;
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:12px 13px;
      min-height:78px;
      display:flex;
      align-items:center;
      gap:11px;
      transition:.15s ease;
    }

    .stat-card:hover{ transform:translateY(-1px); box-shadow:0 14px 32px rgba(15,23,42,.09); }

    .stat-ic{
      width:38px;
      height:38px;
      border-radius:12px;
      display:grid;
      place-items:center;
      color:#fff;
      font-size:17px;
      flex:0 0 auto;
    }

    .stat-ic.blue{ background:var(--blue); }
    .stat-ic.green{ background:var(--green); }
    .stat-ic.yellow{ background:var(--orange); }
    .stat-ic.red{ background:var(--red); }

    .stat-label{
      color:#64748b;
      font-weight:850;
      font-size:10.5px;
      text-transform:uppercase;
    }

    .stat-value{
      font-size:24px;
      font-weight:950;
      line-height:1;
      color:#111827;
      margin-top:2px;
    }

    .small-muted{
      color:#64748b;
      font-weight:750;
      font-size:11px;
    }

    .badge-pill{
      border-radius:999px;
      padding:6px 9px;
      font-weight:900;
      font-size:11px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      border:1px solid var(--border);
      background:#fff;
      color:#111827;
      text-decoration:none;
      white-space:nowrap;
    }

    .form-label{
      font-size:11px;
      font-weight:900;
      color:#475569;
      text-transform:uppercase;
      margin-bottom:6px;
    }

    .form-control,.form-select{
      min-height:38px;
      border:1px solid var(--border);
      border-radius:11px;
      font-size:12px;
      font-weight:800;
      color:#111827;
      padding:8px 11px;
      background:#fff;
    }

    .form-control:focus,.form-select:focus{
      border-color:#bfdbfe;
      box-shadow:0 0 0 3px rgba(59,130,246,.10);
    }

    .primary-btn,.secondary-btn,.btn-action{
      min-height:36px;
      padding:0 14px;
      border-radius:11px;
      font-size:12px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:7px;
      text-decoration:none;
      white-space:nowrap;
      line-height:1;
      border:0;
    }

    .primary-btn{
      background:#111827;
      color:#fff;
    }

    .primary-btn:hover{ background:#020617; color:#fff; }

    .secondary-btn,.btn-action{
      border:1px solid var(--border);
      background:#fff;
      color:#334155;
    }

    .secondary-btn:hover,.btn-action:hover{
      border-color:#cbd5e1;
      background:#f8fafc;
      color:#111827;
    }

    .btn-action{
      min-height:31px;
      min-width:31px;
      width:31px;
      padding:0;
      border-radius:10px;
    }

    .btn-action.primary{
      background:#111827;
      color:#fff;
      border-color:#111827;
    }

    .btn-action.primary:hover{
      background:#020617;
      color:#fff;
      border-color:#020617;
    }

    .status-badge{
      border-radius:999px;
      padding:5px 8px;
      font-weight:900;
      font-size:10px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      border:1px solid transparent;
      white-space:nowrap;
      text-transform:uppercase;
    }

    .status-green{ color:#15803d; background:#dcfce7; border-color:#bbf7d0; }
    .status-yellow{ color:#b45309; background:#ffedd5; border-color:#fed7aa; }

    .compact-table-wrap{
      width:100%;
      border:1px solid var(--border);
      border-radius:13px;
      overflow:hidden;
      background:#fff;
    }

    .compact-table{ width:100%; margin:0; table-layout:auto; }

    .compact-table thead th{
      background:var(--soft);
      color:#64748b;
      font-size:10px;
      text-transform:uppercase;
      font-weight:900;
      border-bottom:1px solid var(--border)!important;
      padding:8px 9px;
      white-space:nowrap;
    }

    .compact-table tbody td{
      padding:8px 9px;
      vertical-align:middle;
      border-color:#eef2f7;
      color:#334155;
      font-weight:700;
      font-size:11.5px;
    }

    .compact-table tbody tr:hover{ background:#fbfdff; }

    .task-card{
      border:1px solid var(--border);
      border-radius:14px;
      background:#fff;
      box-shadow:var(--shadow);
      padding:12px;
    }

    .task-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }

    .task-title{
      font-weight:950;
      color:#111827;
      font-size:13px;
      line-height:1.25;
      margin:0;
    }

    .task-sub{
      color:#64748b;
      font-weight:750;
      font-size:11px;
      margin-top:5px;
    }

    .task-kv{ margin-top:10px; display:grid; gap:7px; }
    .task-row{ display:flex; gap:10px; align-items:flex-start; }
    .task-key{ flex:0 0 90px; color:#64748b; font-weight:950; font-size:11px; text-transform:uppercase; }
    .task-val{ flex:1 1 auto; font-weight:850; color:#111827; font-size:12px; line-height:1.3; }

    .task-actions{
      margin-top:12px;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }

    .remark-box{
      margin-top:12px;
      padding:10px 12px;
      border-radius:12px;
      border:1px dashed #f59e0b;
      background:#fffaf0;
    }

    .remark-title{
      font-size:10px;
      font-weight:950;
      color:#b45309;
      text-transform:uppercase;
      margin-bottom:4px;
      letter-spacing:.3px;
    }

    .remark-text{
      font-size:12px;
      font-weight:800;
      color:#111827;
      line-height:1.35;
      white-space:pre-wrap;
    }

    .empty-state{
      text-align:center;
      padding:30px 12px;
      color:#64748b;
      font-size:12px;
      font-weight:900;
    }

    .empty-state i{
      display:block;
      font-size:34px;
      opacity:.45;
      margin-bottom:8px;
    }

    @media (max-width:991.98px){
      .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
      .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
      .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0)!important; }
    }

    @media (max-width:1199px){
      .compact-table thead{ display:none; }
      .compact-table,.compact-table tbody,.compact-table tr,.compact-table td{ display:block; width:100%; }
      .compact-table tbody tr{ border-bottom:1px solid var(--border); padding:10px; }
      .compact-table tbody td{
        border:0;
        display:flex;
        justify-content:space-between;
        gap:12px;
      }
      .compact-table tbody td::before{
        content:attr(data-label);
        font-size:10px;
        font-weight:900;
        color:#64748b;
        text-transform:uppercase;
        flex:0 0 105px;
      }
      .compact-table tbody td:first-child{ display:block; }
      .compact-table tbody td:first-child::before{ display:none; }
    }

    @media (max-width:768px){
      .content-scroll{ padding:12px 10px!important; }
      .container-fluid.projects-wrapper{ padding-left:0!important; padding-right:0!important; }
      .page-heading{ align-items:flex-start; flex-direction:column; }
      .panel,.filter-card{ padding:12px; }
      .primary-btn,.secondary-btn{ width:100%; }
    }
  </style>
</head>

<body>
<div class="app">

  <?php include 'includes/sidebar.php'; ?>

  <main class="main" aria-label="Main">

    <?php include 'includes/topbar.php'; ?>

    <div id="contentScroll" class="content-scroll">
      <div class="container-fluid projects-wrapper px-0">

        <div class="page-heading">
          <div>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
              <h1><i class="bi bi-clock-history"></i> Time Management Documents</h1>
              <span class="badge-pill">
                <i class="bi bi-calendar-event"></i>
                <?php echo e(date('d M Y', strtotime($targetYmd))); ?>
              </span>
            </div>
            <p>
              Submitted / not submitted document status for assigned projects using current DB tables.
              <?php if ($filterSiteId > 0 && !empty($sites[0])): ?>
                • Filtered Project: <b style="color:#111827;"><?php echo e($sites[0]['project_name']); ?></b>
              <?php endif; ?>
            </p>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill">
              <i class="bi bi-person"></i>
              <?php echo e($employeeName); ?>
            </span>

            <span class="badge-pill">
              <i class="bi bi-award"></i>
              <?php echo e($empRow['designation'] ?? ($_SESSION['designation'] ?? '')); ?>
            </span>

            <a
              class="secondary-btn"
              href="emp-reports.php"
              title="Reset Filters"
            >
              <i class="bi bi-arrow-counterclockwise"></i>
              Reset
            </a>
          </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue">
                <i class="bi bi-building"></i>
              </div>
              <div>
                <div class="stat-label">Total Projects</div>
                <div class="stat-value"><?php echo (int)$totalProjects; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green">
                <i class="bi bi-check2-circle"></i>
              </div>
              <div>
                <div class="stat-label">Submitted</div>
                <div class="stat-value"><?php echo (int)$allCompletedCount; ?></div>
                <div class="small-muted">Time Management</div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow">
                <i class="bi bi-hourglass-split"></i>
              </div>
              <div>
                <div class="stat-label">Not Submitted</div>
                <div class="stat-value"><?php echo (int)$allPendingCount; ?></div>
                <div class="small-muted">Time Management</div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic red">
                <i class="bi bi-clock"></i>
              </div>
              <div>
                <div class="stat-label">Latest Submitted</div>
                <div class="stat-value" style="font-size:22px;">
                  <?php echo e($latestSubmitTime); ?>
                </div>
                <div class="small-muted">Selected date</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
          <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
              <label class="form-label">Project</label>
              <select name="site_id" class="form-select">
                <option value="0">All Projects</option>
                <?php foreach ($sites as $siteOption): ?>
                  <option value="<?php echo (int)$siteOption['id']; ?>" <?php echo $filterSiteId === (int)$siteOption['id'] ? 'selected' : ''; ?>>
                    <?php echo e($siteOption['project_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Document</label>
              <select name="report" class="form-select">
                <option value="all">All Documents</option>
                <?php foreach ($reportTypes as $typeOption): ?>
                  <option value="<?php echo e($typeOption['key']); ?>" <?php echo $filterReport === $typeOption['key'] ? 'selected' : ''; ?>>
                    <?php echo e($typeOption['label']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-2">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Not Submitted</option>
                <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Submitted</option>
                <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All</option>
              </select>
            </div>

            <div class="col-12 col-md-2">
              <label class="form-label">Date</label>
              <input type="date" name="date" class="form-control" value="<?php echo e($targetYmd); ?>">
            </div>

            <div class="col-12 col-md-2 d-flex gap-2">
              <button type="submit" class="primary-btn flex-fill">
                <i class="bi bi-funnel"></i>
                Filter
              </button>
            </div>
          </form>
        </div>

        <!-- Task List -->
        <div class="panel">
          <div style="font-weight:1000; font-size:14px; color:#111827;">
            My Projects — Time Management
          </div>

          <div class="small-muted">
            All Time Management documents are listed below based on the selected filters.
          </div>

          <hr style="border-color:#eef2f7;">

          <?php if (empty($sites)): ?>

            <div class="alert alert-warning mb-0" style="border-radius:16px; border:none; box-shadow:var(--shadow);">
              <i class="bi bi-info-circle me-2"></i>
              No projects assigned to you currently.
            </div>

          <?php else: ?>

            <?php if (empty($taskRows)): ?>
              <div class="empty-state">
                <i class="bi bi-inbox"></i>
                No documents found for selected filters.
              </div>
            <?php else: ?>

            <!-- Mobile cards -->
            <div class="d-block d-md-none">
              <div class="d-grid gap-3">

                <?php foreach ($taskRows as $row): ?>
                  <div class="task-card">

                    <div class="task-top">
                      <div style="flex:1 1 auto;">
                        <h3 class="task-title">
                          <?php echo e($row['project_name']); ?>
                        </h3>

                        <div class="task-sub">
                          <i class="bi bi-geo-alt"></i>
                          <?php echo e($row['project_location']); ?>

                          &nbsp;•&nbsp;

                          <i class="bi bi-person-badge"></i>
                          <?php echo e($row['client_name']); ?>
                        </div>
                      </div>

                      <?php if ($row['is_done']): ?>
                        <span class="status-badge status-green">
                          <i class="bi bi-check2-circle"></i>
                          Completed
                        </span>
                      <?php else: ?>
                        <span class="status-badge status-yellow">
                          <i class="bi bi-hourglass-split"></i>
                          Pending
                        </span>
                      <?php endif; ?>
                    </div>

                    <div class="task-kv">
                      <div class="task-row">
                        <div class="task-key">Task</div>
                        <div class="task-val">
                          <i class="bi <?php echo e($row['report_icon']); ?> me-1"></i>
                          <?php echo e($row['report_label']); ?>
                        </div>
                      </div>

                      <?php if ($row['is_done']): ?>
                        <div class="task-row">
                          <div class="task-key">Completed</div>
                          <div class="task-val">
                            No: <?php echo e($row['doc_no'] ?: '—'); ?>
                          </div>
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
                        <div class="remark-title">
                          <i class="bi bi-chat-left-text me-1"></i>
                          Remark
                        </div>
                        <div class="remark-text">
                          <?php echo e($row['remark']); ?>
                        </div>
                      </div>
                    <?php endif; ?>

                    <div class="task-actions">
                      <?php if ($row['is_done']): ?>

                        <a class="btn-action" href="<?php echo e($row['open_url']); ?>" title="Open">
                          <i class="bi bi-box-arrow-up-right"></i>
                        </a>

                        <?php if ($row['print_url'] !== ''): ?>
                          <a 
                            class="btn-action" 
                            href="<?php echo e($row['print_url']); ?>" 
                            target="_blank" 
                            rel="noopener noreferrer" 
                            title="Print"
                          >
                            <i class="bi bi-printer"></i>
                          </a>

                          <a 
                            class="btn-action" 
                            href="<?php echo e($row['download_url']); ?>" 
                            rel="noopener noreferrer" 
                            title="Download"
                          >
                            <i class="bi bi-download"></i>
                          </a>

                          <a class="btn-action primary" href="<?php echo e($row['mail_url']); ?>" title="Send Mail">
                            <i class="bi bi-envelope"></i>
                          </a>
                        <?php endif; ?>

                      <?php else: ?>

                        <a class="btn-action primary" href="<?php echo e($row['submit_url']); ?>" title="Submit Document">
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
                <table class="table compact-table align-middle mb-0">
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
                        <td data-label="#" style="font-weight:1000;">
                          <?php echo $i++; ?>
                        </td>

                        <td data-label="Project" style="font-weight:1000; color:#111827;">
                          <?php echo e($row['project_name']); ?>
                        </td>

                        <td data-label="Location">
                          <?php echo e($row['project_location']); ?>
                        </td>

                        <td data-label="Client">
                          <?php echo e($row['client_name']); ?>

                          <?php if (!empty($row['client_email'])): ?>
                            <div class="small-muted">
                              <?php echo e($row['client_email']); ?>
                            </div>
                          <?php endif; ?>
                        </td>

                        <td data-label="#" style="font-weight:1000;">
                          <i class="bi <?php echo e($row['report_icon']); ?> me-1"></i>
                          <?php echo e($row['report_label']); ?>
                        </td>

                        <td data-label="Status">
                          <?php if ($row['is_done']): ?>
                            <span class="status-badge status-green">
                              <i class="bi bi-check2-circle"></i>
                              Completed
                            </span>
                          <?php else: ?>
                            <span class="status-badge status-yellow">
                              <i class="bi bi-hourglass-split"></i>
                              Pending
                            </span>
                          <?php endif; ?>
                        </td>

                        <td data-label="Remark">
                          <?php if (trim((string)$row['remark']) !== ''): ?>
                            <div class="remark-text">
                              <?php echo e($row['remark']); ?>
                            </div>
                          <?php else: ?>
                            <span class="small-muted">No remark</span>
                          <?php endif; ?>
                        </td>

                        <td data-label="Action" class="text-end">
                          <?php if ($row['is_done']): ?>

                            <div class="d-flex justify-content-end gap-2 flex-wrap align-items-center">
                              <span class="small-muted align-self-center">
                                Completed &nbsp; No:
                                <b style="color:#111827;">
                                  <?php echo e($row['doc_no'] ?: ''); ?>
                                </b>
                              </span>

                              <a class="btn-action" href="<?php echo e($row['open_url']); ?>" title="Open">
                                <i class="bi bi-box-arrow-up-right"></i>
                              </a>

                              <?php if ($row['print_url'] !== ''): ?>
                                <a
                                  class="btn-action"
                                  href="<?php echo e($row['print_url']); ?>"
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  title="Print / View"
                                >
                                  <i class="bi bi-printer"></i>
                                </a>
                              <?php endif; ?>

                              <?php if ($row['download_url'] !== ''): ?>
                                <a
                                  class="btn-action"
                                  href="<?php echo e($row['download_url']); ?>"
                                  rel="noopener noreferrer"
                                  title="Download"
                                >
                                  <i class="bi bi-download"></i>
                                </a>
                              <?php endif; ?>

                              <?php if ($row['mail_url'] !== ''): ?>
                                <a class="btn-action primary" href="<?php echo e($row['mail_url']); ?>" title="Send Mail">
                                  <i class="bi bi-envelope"></i>
                                </a>
                              <?php endif; ?>
                            </div>

                          <?php else: ?>

                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                              <a class="btn-action primary" href="<?php echo e($row['submit_url']); ?>" title="Submit Document">
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
                Note: All Time Management documents are listed based on filters.
              </div>
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
</body>
</html>

