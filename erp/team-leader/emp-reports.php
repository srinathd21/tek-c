<?php
// emp-reports.php
// Admin / Manager / TL employee report monitoring page
// Same UI style as today-tasks.php
// Mobile responsive with card design
// Shows completed + incomplete reports for today
// Admin: all employees + manager + TL
// Manager: own site employees + their TL
// Team Lead: own site employees under TL
// Remarks supported (auto-creates table if missing)
// Added completed task actions: Open / Print / Download
// Download/print links point to ../project-engineer/ files

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int)($_SESSION['employee_id'] ?? 0);
$designationRaw = trim((string)($_SESSION['designation'] ?? ''));
$designation = strtolower($designationRaw);
$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fmtTime($ts){
    if (!$ts) return '—';
    $t = strtotime($ts);
    return $t ? date('h:i A', $t) : '—';
}

function normalizeRole(string $designation, string $sessionRole = ''): string {
    $d = strtolower(trim($designation));
    $r = strtolower(trim($sessionRole));

    if (in_array($r, ['admin', 'administrator', 'super admin'], true)) return 'admin';
    if (in_array($d, ['director', 'vice president', 'general manager', 'administrator', 'admin'], true)) return 'admin';
    if ($d === 'manager') return 'manager';
    if ($d === 'team lead') return 'tl';

    return 'other';
}

$currentRole = normalizeRole($designationRaw, $sessionRole);
$allowedRoles = ['admin', 'manager', 'tl'];
if (!in_array($currentRole, $allowedRoles, true)) {
    header("Location: index.php");
    exit;
}

$todayYmd = date('Y-m-d');
$currentPage = 'emp-reports';

// ---------------------------------------------------------
// SAFE TABLE CREATE FOR REMARKS
// ---------------------------------------------------------
$createRemarksTable = "
CREATE TABLE IF NOT EXISTS employee_report_remarks (
    id INT(11) NOT NULL AUTO_INCREMENT,
    report_date DATE NOT NULL,
    employee_id INT(11) NOT NULL,
    site_id INT(11) NOT NULL,
    report_key VARCHAR(30) NOT NULL,
    reviewer_id INT(11) NOT NULL,
    remark TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_remark (report_date, employee_id, site_id, report_key),
    KEY idx_report_date (report_date),
    KEY idx_employee (employee_id),
    KEY idx_site (site_id),
    KEY idx_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";
@mysqli_query($conn, $createRemarksTable);

// ---------------------------------------------------------
// HANDLE REMARK SAVE
// ---------------------------------------------------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_remark'])) {
    $remarkDate   = trim((string)($_POST['report_date'] ?? $todayYmd));
    $targetEmpId  = (int)($_POST['employee_id'] ?? 0);
    $targetSiteId = (int)($_POST['site_id'] ?? 0);
    $reportKey    = trim((string)($_POST['report_key'] ?? ''));
    $remarkText   = trim((string)($_POST['remark'] ?? ''));

    $validReportKeys = ['dpr','dar','checklist','ma','mom','mpt'];

    if ($targetEmpId > 0 && $targetSiteId > 0 && in_array($reportKey, $validReportKeys, true)) {
        $saveSql = "
            INSERT INTO employee_report_remarks (
                report_date, employee_id, site_id, report_key, reviewer_id, remark
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                reviewer_id = VALUES(reviewer_id),
                remark = VALUES(remark),
                updated_at = CURRENT_TIMESTAMP
        ";
        $st = mysqli_prepare($conn, $saveSql);
        if ($st) {
            mysqli_stmt_bind_param($st, "siisis", $remarkDate, $targetEmpId, $targetSiteId, $reportKey, $employeeId, $remarkText);
            if (mysqli_stmt_execute($st)) {
                $message = "Remark saved successfully.";
                $messageType = "success";
            } else {
                $message = "Failed to save remark.";
                $messageType = "danger";
            }
            mysqli_stmt_close($st);
        } else {
            $message = "Unable to prepare remark query.";
            $messageType = "danger";
        }
    } else {
        $message = "Invalid remark request.";
        $messageType = "warning";
    }
}

// ---------------------------------------------------------
// CURRENT USER
// ---------------------------------------------------------
$loggedUser = null;
$st = mysqli_prepare($conn, "
    SELECT id, full_name, email, designation
    FROM employees
    WHERE id = ?
    LIMIT 1
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $loggedUser = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
}
$loggedUserName = $loggedUser['full_name'] ?? ($_SESSION['employee_name'] ?? 'User');

// ---------------------------------------------------------
// REPORT TYPES
// ---------------------------------------------------------
$reportTypes = [
    [
        'key' => 'dpr',
        'label' => 'Daily DPR',
        'icon' => 'bi-file-text',
        'table' => 'dpr_reports',
        'dateField' => 'dpr_date',
        'noField' => 'dpr_no',
        'openUrl' => 'dpr.php?site_id={sid}',
        'printFile' => '../project-engineer/report-print.php',
    ],
    [
        'key' => 'dar',
        'label' => 'Daily Activity Report (DAR)',
        'icon' => 'bi-journal-text',
        'table' => 'dar_reports',
        'dateField' => 'dar_date',
        'noField' => 'dar_no',
        'openUrl' => 'dar.php?site_id={sid}',
        'printFile' => '../project-engineer/report-dar-print.php',
    ],
    [
        'key' => 'checklist',
        'label' => 'Checklist',
        'icon' => 'bi-card-checklist',
        'table' => 'checklist_reports',
        'dateField' => 'checklist_date',
        'noField' => 'doc_no',
        'openUrl' => 'checklist.php?site_id={sid}',
        'printFile' => '../project-engineer/report-checklist-print.php',
    ],
    [
        'key' => 'ma',
        'label' => 'Meeting Agenda (MA)',
        'icon' => 'bi-clipboard2-check',
        'table' => 'ma_reports',
        'dateField' => 'ma_date',
        'noField' => 'ma_no',
        'openUrl' => 'ma.php?site_id={sid}',
        'printFile' => '../project-engineer/report-ma-print.php',
    ],
    [
        'key' => 'mom',
        'label' => 'Minutes of Meeting (MOM)',
        'icon' => 'bi-people',
        'table' => 'mom_reports',
        'dateField' => 'mom_date',
        'noField' => 'mom_no',
        'openUrl' => 'mom.php?site_id={sid}',
        'printFile' => '../project-engineer/report-mom-print.php',
    ],
    [
        'key' => 'mpt',
        'label' => 'Monthly Project Tracker (MPT)',
        'icon' => 'bi-graph-up',
        'table' => 'mpt_reports',
        'dateField' => 'mpt_date',
        'noField' => 'mpt_no',
        'openUrl' => 'mpt.php?site_id={sid}',
        'printFile' => '../project-engineer/report-mpt-print.php',
    ],
];

// ---------------------------------------------------------
// FILTERS
// ---------------------------------------------------------
$filterStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
$filterSiteId = (int)($_GET['site_id'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));

$validStatuses = ['all', 'completed', 'incomplete'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = 'all';
}

// ---------------------------------------------------------
// GET ACCESSIBLE SITES + EMPLOYEES
// ---------------------------------------------------------
$sitesById = [];
$rows = [];

if ($currentRole === 'admin') {
    $sql = "
        SELECT
            s.id AS site_id,
            s.project_name,
            s.project_location,
            s.manager_employee_id,
            s.team_lead_employee_id,
            mgr.full_name AS manager_name,
            tl.full_name AS tl_name
        FROM sites s
        LEFT JOIN employees mgr ON mgr.id = s.manager_employee_id
        LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id
        WHERE s.deleted_at IS NULL
        ORDER BY s.created_at DESC, s.id DESC
    ";
    $res = mysqli_query($conn, $sql);
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $sitesById[(int)$r['site_id']] = $r;
    }

    $sqlEmp = "
        SELECT
            spe.site_id,
            e.id AS employee_id,
            e.full_name,
            e.employee_code,
            e.designation,
            e.department,
            e.reporting_to,
            rep.full_name AS reporting_name
        FROM site_project_engineers spe
        INNER JOIN employees e ON e.id = spe.employee_id
        LEFT JOIN employees rep ON rep.id = e.reporting_to
        WHERE e.employee_status = 'active'
        ORDER BY e.full_name ASC
    ";
    $resEmp = mysqli_query($conn, $sqlEmp);
    while ($resEmp && $r = mysqli_fetch_assoc($resEmp)) {
        $sid = (int)$r['site_id'];
        if (!isset($sitesById[$sid])) continue;

        $rows[] = [
            'site_id' => $sid,
            'employee_id' => (int)$r['employee_id'],
            'employee_name' => $r['full_name'],
            'employee_code' => $r['employee_code'],
            'employee_designation' => $r['designation'],
            'department' => $r['department'],
            'reporting_to' => $r['reporting_to'],
            'reporting_name' => $r['reporting_name'],
            'project_name' => $sitesById[$sid]['project_name'],
            'project_location' => $sitesById[$sid]['project_location'],
            'manager_id' => (int)($sitesById[$sid]['manager_employee_id'] ?? 0),
            'manager_name' => $sitesById[$sid]['manager_name'] ?? '',
            'tl_id' => (int)($sitesById[$sid]['team_lead_employee_id'] ?? 0),
            'tl_name' => $sitesById[$sid]['tl_name'] ?? '',
        ];
    }

} elseif ($currentRole === 'manager') {
    $sql = "
        SELECT
            s.id AS site_id,
            s.project_name,
            s.project_location,
            s.manager_employee_id,
            s.team_lead_employee_id,
            mgr.full_name AS manager_name,
            tl.full_name AS tl_name
        FROM sites s
        LEFT JOIN employees mgr ON mgr.id = s.manager_employee_id
        LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id
        WHERE s.deleted_at IS NULL
          AND s.manager_employee_id = ?
        ORDER BY s.created_at DESC, s.id DESC
    ";
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
        mysqli_stmt_bind_param($st, "i", $employeeId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        while ($r = mysqli_fetch_assoc($res)) {
            $sitesById[(int)$r['site_id']] = $r;
        }
        mysqli_stmt_close($st);
    }

    if (!empty($sitesById)) {
        $sqlEmp = "
            SELECT
                spe.site_id,
                e.id AS employee_id,
                e.full_name,
                e.employee_code,
                e.designation,
                e.department,
                e.reporting_to,
                rep.full_name AS reporting_name
            FROM site_project_engineers spe
            INNER JOIN employees e ON e.id = spe.employee_id
            LEFT JOIN employees rep ON rep.id = e.reporting_to
            WHERE e.employee_status = 'active'
            ORDER BY e.full_name ASC
        ";
        $resEmp = mysqli_query($conn, $sqlEmp);
        while ($resEmp && $r = mysqli_fetch_assoc($resEmp)) {
            $sid = (int)$r['site_id'];
            if (!isset($sitesById[$sid])) continue;

            $rows[] = [
                'site_id' => $sid,
                'employee_id' => (int)$r['employee_id'],
                'employee_name' => $r['full_name'],
                'employee_code' => $r['employee_code'],
                'employee_designation' => $r['designation'],
                'department' => $r['department'],
                'reporting_to' => $r['reporting_to'],
                'reporting_name' => $r['reporting_name'],
                'project_name' => $sitesById[$sid]['project_name'],
                'project_location' => $sitesById[$sid]['project_location'],
                'manager_id' => (int)($sitesById[$sid]['manager_employee_id'] ?? 0),
                'manager_name' => $sitesById[$sid]['manager_name'] ?? '',
                'tl_id' => (int)($sitesById[$sid]['team_lead_employee_id'] ?? 0),
                'tl_name' => $sitesById[$sid]['tl_name'] ?? '',
            ];
        }
    }

} elseif ($currentRole === 'tl') {
    $sql = "
        SELECT
            s.id AS site_id,
            s.project_name,
            s.project_location,
            s.manager_employee_id,
            s.team_lead_employee_id,
            mgr.full_name AS manager_name,
            tl.full_name AS tl_name
        FROM sites s
        LEFT JOIN employees mgr ON mgr.id = s.manager_employee_id
        LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id
        WHERE s.deleted_at IS NULL
          AND s.team_lead_employee_id = ?
        ORDER BY s.created_at DESC, s.id DESC
    ";
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
        mysqli_stmt_bind_param($st, "i", $employeeId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        while ($r = mysqli_fetch_assoc($res)) {
            $sitesById[(int)$r['site_id']] = $r;
        }
        mysqli_stmt_close($st);
    }

    if (!empty($sitesById)) {
        $sqlEmp = "
            SELECT
                spe.site_id,
                e.id AS employee_id,
                e.full_name,
                e.employee_code,
                e.designation,
                e.department,
                e.reporting_to,
                rep.full_name AS reporting_name
            FROM site_project_engineers spe
            INNER JOIN employees e ON e.id = spe.employee_id
            LEFT JOIN employees rep ON rep.id = e.reporting_to
            WHERE e.employee_status = 'active'
            ORDER BY e.full_name ASC
        ";
        $resEmp = mysqli_query($conn, $sqlEmp);
        while ($resEmp && $r = mysqli_fetch_assoc($resEmp)) {
            $sid = (int)$r['site_id'];
            if (!isset($sitesById[$sid])) continue;

            $rows[] = [
                'site_id' => $sid,
                'employee_id' => (int)$r['employee_id'],
                'employee_name' => $r['full_name'],
                'employee_code' => $r['employee_code'],
                'employee_designation' => $r['designation'],
                'department' => $r['department'],
                'reporting_to' => $r['reporting_to'],
                'reporting_name' => $r['reporting_name'],
                'project_name' => $sitesById[$sid]['project_name'],
                'project_location' => $sitesById[$sid]['project_location'],
                'manager_id' => (int)($sitesById[$sid]['manager_employee_id'] ?? 0),
                'manager_name' => $sitesById[$sid]['manager_name'] ?? '',
                'tl_id' => (int)($sitesById[$sid]['team_lead_employee_id'] ?? 0),
                'tl_name' => $sitesById[$sid]['tl_name'] ?? '',
            ];
        }
    }
}

// remove duplicates if same employee-site duplicated
$uniqueRows = [];
foreach ($rows as $r) {
    $uk = $r['site_id'] . '_' . $r['employee_id'];
    $uniqueRows[$uk] = $r;
}
$rows = array_values($uniqueRows);

// site filter based on accessible sites
if ($filterSiteId > 0) {
    $rows = array_values(array_filter($rows, function($r) use ($filterSiteId){
        return (int)$r['site_id'] === $filterSiteId;
    }));
}

// ---------------------------------------------------------
// LOAD COMPLETION DATA FOR TODAY
// key: reportKey_employeeId_siteId => data
// ---------------------------------------------------------
$reportStatusMap = [];
$latestAnyCreatedAt = null;

foreach ($reportTypes as $rt) {
    $sql = "
        SELECT id, site_id, employee_id, {$rt['noField']} AS doc_no, created_at
        FROM {$rt['table']}
        WHERE {$rt['dateField']} = ?
        ORDER BY created_at DESC, id DESC
    ";
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
        mysqli_stmt_bind_param($st, "s", $todayYmd);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        while ($row = mysqli_fetch_assoc($res)) {
            $k = $rt['key'] . '_' . (int)$row['employee_id'] . '_' . (int)$row['site_id'];
            if (!isset($reportStatusMap[$k])) {
                $reportStatusMap[$k] = [
                    'id' => (int)$row['id'],
                    'doc_no' => $row['doc_no'],
                    'created_at' => $row['created_at'],
                    'key' => $rt['key'],
                ];
            }
            if (!empty($row['created_at'])) {
                if ($latestAnyCreatedAt === null || strtotime($row['created_at']) > strtotime($latestAnyCreatedAt)) {
                    $latestAnyCreatedAt = $row['created_at'];
                }
            }
        }
        mysqli_stmt_close($st);
    }
}

// ---------------------------------------------------------
// LOAD TODAY REMARKS
// ---------------------------------------------------------
$remarksMap = [];
$remarksSql = "
    SELECT report_date, employee_id, site_id, report_key, remark
    FROM employee_report_remarks
    WHERE report_date = ?
";
$st = mysqli_prepare($conn, $remarksSql);
if ($st) {
    mysqli_stmt_bind_param($st, "s", $todayYmd);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $rk = $r['report_key'] . '_' . (int)$r['employee_id'] . '_' . (int)$r['site_id'];
        $remarksMap[$rk] = $r['remark'] ?? '';
    }
    mysqli_stmt_close($st);
}

// ---------------------------------------------------------
// BUILD DISPLAY ROWS
// ---------------------------------------------------------
$displayRows = [];

foreach ($rows as $base) {
    foreach ($reportTypes as $rt) {
        $rk = $rt['key'] . '_' . $base['employee_id'] . '_' . $base['site_id'];
        $completed = isset($reportStatusMap[$rk]);
        $reportData = $completed ? $reportStatusMap[$rk] : null;

        $printUrl = '';
        $downloadUrl = '';

        if ($completed && !empty($reportData['id']) && !empty($rt['printFile'])) {
            $rid = (int)$reportData['id'];
            $printUrl = $rt['printFile'] . '?view=' . urlencode((string)$rid);
            $downloadUrl = $rt['printFile'] . '?view=' . urlencode((string)$rid) . '&dl=1';
        }

        $displayRows[] = [
            'site_id' => $base['site_id'],
            'project_name' => $base['project_name'],
            'project_location' => $base['project_location'],
            'employee_id' => $base['employee_id'],
            'employee_name' => $base['employee_name'],
            'employee_code' => $base['employee_code'],
            'employee_designation' => $base['employee_designation'],
            'department' => $base['department'],
            'manager_name' => $base['manager_name'],
            'tl_name' => $base['tl_name'],
            'report_key' => $rt['key'],
            'report_label' => $rt['label'],
            'report_icon' => $rt['icon'],
            'open_url' => str_replace('{sid}', (string)$base['site_id'], $rt['openUrl']),
            'is_completed' => $completed,
            'doc_no' => $reportData['doc_no'] ?? '',
            'created_at' => $reportData['created_at'] ?? '',
            'report_id' => $reportData['id'] ?? 0,
            'print_url' => $printUrl,
            'download_url' => $downloadUrl,
            'remark' => $remarksMap[$rk] ?? '',
        ];
    }
}

// search filter
if ($search !== '') {
    $searchLower = strtolower($search);
    $displayRows = array_values(array_filter($displayRows, function($r) use ($searchLower){
        return (
            strpos(strtolower((string)$r['employee_name']), $searchLower) !== false ||
            strpos(strtolower((string)$r['employee_code']), $searchLower) !== false ||
            strpos(strtolower((string)$r['project_name']), $searchLower) !== false ||
            strpos(strtolower((string)$r['project_location']), $searchLower) !== false ||
            strpos(strtolower((string)$r['manager_name']), $searchLower) !== false ||
            strpos(strtolower((string)$r['tl_name']), $searchLower) !== false ||
            strpos(strtolower((string)$r['report_label']), $searchLower) !== false
        );
    }));
}

// status filter
if ($filterStatus === 'completed') {
    $displayRows = array_values(array_filter($displayRows, fn($r) => !empty($r['is_completed'])));
} elseif ($filterStatus === 'incomplete') {
    $displayRows = array_values(array_filter($displayRows, fn($r) => empty($r['is_completed'])));
}

// ---------------------------------------------------------
// STATS
// ---------------------------------------------------------
$totalEmployees = count(array_unique(array_map(fn($r) => $r['employee_id'], $rows)));
$totalTasks = count($displayRows);

$completedCount = 0;
foreach ($displayRows as $r) {
    if (!empty($r['is_completed'])) $completedCount++;
}
$incompleteCount = max(0, $totalTasks - $completedCount);
$latestSubmitTime = fmtTime($latestAnyCreatedAt);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Employee Reports - TEK-C</title>

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

        .filter-input, .filter-select, .remark-textarea {
            border:1px solid var(--border);
            border-radius:12px;
            font-weight:800;
            font-size:13px;
            padding:10px 12px;
            box-shadow:none !important;
        }

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
        .task-key{ flex:0 0 95px; color:#6b7280; font-weight:1000; font-size:12px; }
        .task-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; }
        .task-actions{ margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
        .remark-box{
            margin-top:12px;
            border-top:1px dashed var(--border);
            padding-top:12px;
        }
        .desk-remark-form{
            min-width:250px;
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
            .stat-card{ height:auto; min-height:86px; }
            .stat-value{ font-size:24px; }
            .btn-action{
                width:34px;
                height:34px;
                flex:0 0 34px;
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
                        <h1 class="h-title">Employee Reports</h1>
                        <p class="h-sub">
                            Today report monitoring for <?php echo e(date('d M Y')); ?> (<?php echo e($todayYmd); ?>)
                            • Panel: <b style="color:#111827;"><?php echo strtoupper(e($currentRole)); ?></b>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($loggedUserName); ?></span>
                        <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($designationRaw); ?></span>
                        <a class="btn-action" href="emp-reports.php" title="Refresh"><i class="bi bi-arrow-clockwise"></i></a>
                    </div>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?php echo e($messageType ?: 'info'); ?> border-0 shadow-sm" style="border-radius:16px;">
                        <i class="bi bi-info-circle me-2"></i><?php echo e($message); ?>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-people"></i></div>
                            <div>
                                <div class="stat-label">Employees</div>
                                <div class="stat-value"><?php echo (int)$totalEmployees; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                            <div>
                                <div class="stat-label">Completed</div>
                                <div class="stat-value"><?php echo (int)$completedCount; ?></div>
                                <div class="small-muted">Today</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="stat-label">Incomplete</div>
                                <div class="stat-value"><?php echo (int)$incompleteCount; ?></div>
                                <div class="small-muted">Today</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-clock-history"></i></div>
                            <div>
                                <div class="stat-label">Latest Submit</div>
                                <div class="stat-value" style="font-size:22px;"><?php echo e($latestSubmitTime); ?></div>
                                <div class="small-muted">Today</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <form method="get" class="row g-2 align-items-end mb-3">
                        <div class="col-12 col-md-4 col-lg-4">
                            <label class="small-muted mb-1">Search</label>
                            <input type="text" name="search" class="form-control filter-input"
                                   placeholder="Employee / project / manager / TL / report"
                                   value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="small-muted mb-1">Status</label>
                            <select name="status" class="form-select filter-select">
                                <option value="all" <?php echo $filterStatus==='all'?'selected':''; ?>>All</option>
                                <option value="completed" <?php echo $filterStatus==='completed'?'selected':''; ?>>Completed</option>
                                <option value="incomplete" <?php echo $filterStatus==='incomplete'?'selected':''; ?>>Incomplete</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-3">
                            <label class="small-muted mb-1">Project</label>
                            <select name="site_id" class="form-select filter-select">
                                <option value="0">All Projects</option>
                                <?php foreach ($sitesById as $sid => $site): ?>
                                    <option value="<?php echo (int)$sid; ?>" <?php echo $filterSiteId===(int)$sid?'selected':''; ?>>
                                        <?php echo e($site['project_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 col-lg-3 d-flex gap-2 align-items-center">
                            <button class="btn-action primary" type="submit" title="Filter"><i class="bi bi-funnel"></i></button>
                            <a class="btn-action" href="emp-reports.php" title="Clear"><i class="bi bi-x-circle"></i></a>
                        </div>
                    </form>

                    <div style="font-weight:1000; font-size:14px; color:#111827;">Employee Report Status</div>
                    <div class="small-muted">
                        <?php if ($currentRole === 'admin'): ?>
                            Admin view: all project employees with their manager and TL.
                        <?php elseif ($currentRole === 'manager'): ?>
                            Manager view: employees under your projects with their TL.
                        <?php else: ?>
                            Team Lead view: employees working under your sites.
                        <?php endif; ?>
                    </div>
                    <hr style="border-color:#eef2f7;">

                    <?php if (empty($displayRows)): ?>
                        <div class="alert alert-warning mb-0" style="border-radius:16px; border:none; box-shadow:var(--shadow);">
                            <i class="bi bi-info-circle me-2"></i> No employee report records found for the selected filter.
                        </div>
                    <?php else: ?>

                        <!-- Mobile Cards -->
                        <div class="d-block d-md-none">
                            <div class="d-grid gap-3">
                                <?php foreach ($displayRows as $row): ?>
                                    <div class="task-card">
                                        <div class="task-top">
                                            <div style="flex:1 1 auto;">
                                                <h3 class="task-title"><?php echo e($row['employee_name']); ?></h3>
                                                <div class="task-sub">
                                                    <?php echo e($row['employee_designation'] ?: 'Employee'); ?>
                                                    <?php if (!empty($row['employee_code'])): ?>
                                                        • <?php echo e($row['employee_code']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($row['is_completed']): ?>
                                                <span class="status-badge status-green"><i class="bi bi-check2-circle"></i> Completed</span>
                                            <?php else: ?>
                                                <span class="status-badge status-yellow"><i class="bi bi-hourglass-split"></i> Incomplete</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="task-kv">
                                            <div class="task-row">
                                                <div class="task-key">Project</div>
                                                <div class="task-val"><?php echo e($row['project_name']); ?></div>
                                            </div>
                                            <div class="task-row">
                                                <div class="task-key">Location</div>
                                                <div class="task-val"><?php echo e($row['project_location']); ?></div>
                                            </div>
                                            <div class="task-row">
                                                <div class="task-key">Report</div>
                                                <div class="task-val"><i class="bi <?php echo e($row['report_icon']); ?> me-1"></i> <?php echo e($row['report_label']); ?></div>
                                            </div>
                                            <?php if ($currentRole === 'admin' || $currentRole === 'manager'): ?>
                                                <div class="task-row">
                                                    <div class="task-key">TL</div>
                                                    <div class="task-val"><?php echo e($row['tl_name'] ?: '—'); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($currentRole === 'admin'): ?>
                                                <div class="task-row">
                                                    <div class="task-key">Manager</div>
                                                    <div class="task-val"><?php echo e($row['manager_name'] ?: '—'); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <div class="task-row">
                                                <div class="task-key">Doc No</div>
                                                <div class="task-val"><?php echo $row['is_completed'] ? e($row['doc_no']) : '—'; ?></div>
                                            </div>
                                            <div class="task-row">
                                                <div class="task-key">Submit Time</div>
                                                <div class="task-val"><?php echo $row['is_completed'] ? e(fmtTime($row['created_at'])) : '—'; ?></div>
                                            </div>
                                        </div>

                                        <?php if ($row['is_completed']): ?>
                                            <div class="task-actions">
                                                
                                                <?php if (!empty($row['print_url'])): ?>
                                                    <a class="btn-action" href="<?php echo e($row['print_url']); ?>" target="_blank" rel="noopener noreferrer" title="Print">
                                                        <i class="bi bi-printer"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!empty($row['download_url'])): ?>
                                                    <a class="btn-action" href="<?php echo e($row['download_url']); ?>" rel="noopener noreferrer" title="Download">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="remark-box">
                                            <form method="post">
                                                <input type="hidden" name="save_remark" value="1">
                                                <input type="hidden" name="report_date" value="<?php echo e($todayYmd); ?>">
                                                <input type="hidden" name="employee_id" value="<?php echo (int)$row['employee_id']; ?>">
                                                <input type="hidden" name="site_id" value="<?php echo (int)$row['site_id']; ?>">
                                                <input type="hidden" name="report_key" value="<?php echo e($row['report_key']); ?>">

                                                <label class="small-muted mb-1">Remark</label>
                                                <textarea name="remark" class="form-control remark-textarea" rows="3" placeholder="Enter remark..."><?php echo e($row['remark']); ?></textarea>
                                                <button type="submit" class="btn-action primary mt-2" title="Save Remark">
                                                    <i class="bi bi-save"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Desktop Table -->
                        <div class="d-none d-md-block">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width:60px;">#</th>
                                            <th>Employee</th>
                                            <th>Project</th>
                                            <th>Report</th>
                                            <?php if ($currentRole === 'admin'): ?>
                                                <th>Manager</th>
                                                <th>TL</th>
                                            <?php elseif ($currentRole === 'manager'): ?>
                                                <th>TL</th>
                                            <?php endif; ?>
                                            <th>Status</th>
                                            <th>Doc / Time</th>
                                            <th style="min-width:280px;">Remark</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($displayRows as $row): ?>
                                            <tr>
                                                <td style="font-weight:1000;"><?php echo $i++; ?></td>
                                                <td>
                                                    <div style="font-weight:1000; color:#111827;"><?php echo e($row['employee_name']); ?></div>
                                                    <div class="small-muted">
                                                        <?php echo e($row['employee_designation'] ?: 'Employee'); ?>
                                                        <?php if (!empty($row['employee_code'])): ?>
                                                            • <?php echo e($row['employee_code']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="font-weight:1000;"><?php echo e($row['project_name']); ?></div>
                                                    <div class="small-muted"><?php echo e($row['project_location']); ?></div>
                                                </td>
                                                <td style="font-weight:1000;">
                                                    <i class="bi <?php echo e($row['report_icon']); ?> me-1"></i> <?php echo e($row['report_label']); ?>
                                                </td>

                                                <?php if ($currentRole === 'admin'): ?>
                                                    <td><?php echo e($row['manager_name'] ?: '—'); ?></td>
                                                    <td><?php echo e($row['tl_name'] ?: '—'); ?></td>
                                                <?php elseif ($currentRole === 'manager'): ?>
                                                    <td><?php echo e($row['tl_name'] ?: '—'); ?></td>
                                                <?php endif; ?>

                                                <td>
                                                    <?php if ($row['is_completed']): ?>
                                                        <span class="status-badge status-green"><i class="bi bi-check2-circle"></i> Completed</span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-yellow"><i class="bi bi-hourglass-split"></i> Incomplete</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($row['is_completed']): ?>
                                                        <div style="font-weight:1000;"><?php echo e($row['doc_no']); ?></div>
                                                        <div class="small-muted"><?php echo e(fmtTime($row['created_at'])); ?></div>
                                                    <?php else: ?>
                                                        <span class="small-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form method="post" class="desk-remark-form">
                                                        <input type="hidden" name="save_remark" value="1">
                                                        <input type="hidden" name="report_date" value="<?php echo e($todayYmd); ?>">
                                                        <input type="hidden" name="employee_id" value="<?php echo (int)$row['employee_id']; ?>">
                                                        <input type="hidden" name="site_id" value="<?php echo (int)$row['site_id']; ?>">
                                                        <input type="hidden" name="report_key" value="<?php echo e($row['report_key']); ?>">
                                                        <textarea name="remark" class="form-control remark-textarea mb-2" rows="2" placeholder="Enter remark..."><?php echo e($row['remark']); ?></textarea>
                                                        <button type="submit" class="btn-action primary" title="Save">
                                                            <i class="bi bi-save"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($row['is_completed']): ?>
                                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                            
                                                            <?php if (!empty($row['print_url'])): ?>
                                                                <a class="btn-action" href="<?php echo e($row['print_url']); ?>" target="_blank" rel="noopener noreferrer" title="Print">
                                                                    <i class="bi bi-printer"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if (!empty($row['download_url'])): ?>
                                                                <a class="btn-action" href="<?php echo e($row['download_url']); ?>" rel="noopener noreferrer" title="Download">
                                                                    <i class="bi bi-download"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="small-muted">Not Submitted</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="small-muted mt-2">
                                Incomplete means that employee has not submitted that report today for that project.
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
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
} catch (Throwable $e) {}
?>