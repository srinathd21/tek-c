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

function tableExists(mysqli $conn, string $table): bool {
    $safe = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
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

function indexExists(mysqli $conn, string $table, string $index): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $index = mysqli_real_escape_string($conn, $index);
    $res = mysqli_query($conn, "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
    return $res && mysqli_num_rows($res) > 0;
}


function createNotification(mysqli $conn, int $employeeId, string $title, string $message, string $type, string $module, int $referenceId, string $link): bool {
    if ($employeeId <= 0 || !tableExists($conn, 'notifications')) {
        return false;
    }

    $sql = "
        INSERT INTO notifications
            (employee_id, title, message, type, module, reference_id, link, is_read)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0)
    ";

    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return false;
    }

    mysqli_stmt_bind_param($st, "issssis", $employeeId, $title, $message, $type, $module, $referenceId, $link);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    return $ok;
}

function fetchReportRowsForDate(mysqli $conn, array $rt, string $targetYmd): array {
    $map = [];

    if (!tableExists($conn, $rt['table'])) {
        return $map;
    }

    $siteField = getExistingColumn($conn, $rt['table'], ['site_id', 'project_id']);
    $employeeField = getExistingColumn($conn, $rt['table'], ['employee_id', 'created_by', 'prepared_by_id', 'created_user_id', 'user_id']);
    $noField = getExistingColumn($conn, $rt['table'], $rt['noFields']);
    $createdField = getExistingColumn($conn, $rt['table'], ['created_at', 'submitted_at', 'updated_at']);

    $dateFields = [];
    foreach ($rt['dateFields'] as $df) {
        if (columnExists($conn, $rt['table'], $df) && !in_array($df, $dateFields, true)) {
            $dateFields[] = $df;
        }
    }

    foreach (['created_at', 'submitted_at', 'updated_at'] as $df) {
        if (columnExists($conn, $rt['table'], $df) && !in_array($df, $dateFields, true)) {
            $dateFields[] = $df;
        }
    }

    if ($siteField === '' || $employeeField === '' || empty($dateFields)) {
        return $map;
    }

    $selectNo = $noField !== '' ? "`{$noField}` AS doc_no" : "'' AS doc_no";
    $selectCreated = $createdField !== '' ? "`{$createdField}` AS created_at" : "NULL AS created_at";
    $orderBy = $createdField !== '' ? "`{$createdField}` DESC, id DESC" : "id DESC";

    $dateWhere = [];
    foreach ($dateFields as $df) {
        $dateWhere[] = "DATE(`{$df}`) = ?";
    }

    $sql = "
        SELECT id, `{$siteField}` AS site_id, `{$employeeField}` AS employee_id, {$selectNo}, {$selectCreated}
        FROM `{$rt['table']}`
        WHERE (" . implode(' OR ', $dateWhere) . ")
        ORDER BY {$orderBy}
    ";

    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return $map;
    }

    $types = str_repeat('s', count($dateFields));
    $values = array_fill(0, count($dateFields), $targetYmd);
    mysqli_stmt_bind_param($st, $types, ...$values);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);

    while ($row = mysqli_fetch_assoc($res)) {
        $k = (int)$row['employee_id'] . '_' . (int)$row['site_id'];
        if (!isset($map[$k])) {
            $map[$k] = [
                'id' => (int)$row['id'],
                'doc_no' => $row['doc_no'] ?? '',
                'created_at' => $row['created_at'] ?? '',
            ];
        }
    }

    mysqli_stmt_close($st);
    return $map;
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
$filterDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}
$todayYmd = $filterDate;
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
    recipient_id INT(11) DEFAULT NULL,
    recipient_role VARCHAR(30) DEFAULT NULL,
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

/*
 * Safe one-time migration.
 * It avoids duplicate column/index fatal errors on repeated page loads.
 */
if (tableExists($conn, 'employee_report_remarks')) {
    if (!columnExists($conn, 'employee_report_remarks', 'recipient_id')) {
        mysqli_query($conn, "ALTER TABLE employee_report_remarks ADD COLUMN recipient_id INT(11) DEFAULT NULL AFTER reviewer_id");
    }

    if (!columnExists($conn, 'employee_report_remarks', 'recipient_role')) {
        mysqli_query($conn, "ALTER TABLE employee_report_remarks ADD COLUMN recipient_role VARCHAR(30) DEFAULT NULL AFTER recipient_id");
    }

    if (indexExists($conn, 'employee_report_remarks', 'uq_report_remark') && !indexExists($conn, 'employee_report_remarks', 'uq_report_remark_recipient')) {
        mysqli_query($conn, "ALTER TABLE employee_report_remarks DROP INDEX uq_report_remark");
    }

    if (!indexExists($conn, 'employee_report_remarks', 'uq_report_remark_recipient')) {
        mysqli_query($conn, "ALTER TABLE employee_report_remarks ADD UNIQUE KEY uq_report_remark_recipient (report_date, employee_id, site_id, report_key, recipient_id)");
    }
}

// ---------------------------------------------------------
// HANDLE REMARK SAVE + NOTIFICATION
// ---------------------------------------------------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_remark'])) {
    $remarkDate    = trim((string)($_POST['report_date'] ?? $todayYmd));
    $targetEmpId   = (int)($_POST['employee_id'] ?? 0);
    $targetSiteId  = (int)($_POST['site_id'] ?? 0);
    $reportKey     = trim((string)($_POST['report_key'] ?? ''));
    $remarkText    = trim((string)($_POST['remark'] ?? ''));
    $recipientId   = (int)($_POST['recipient_id'] ?? 0);
    $recipientRole = strtolower(trim((string)($_POST['recipient_role'] ?? '')));

    $validReportKeys = [
        'dpr','dar','ma','mpt','mom','mom-short','rfi','checklist','sat','dlar',
        'ait','mas','pd','pms','vfs','vft','wpt','dds','ddt','dpt'
    ];

    $allowedRecipientRoles = [];
    if ($currentRole === 'tl') {
        $allowedRecipientRoles = ['pe'];
    } elseif ($currentRole === 'manager') {
        $allowedRecipientRoles = ['pe', 'tl'];
    } elseif ($currentRole === 'admin') {
        $allowedRecipientRoles = ['pe', 'tl', 'manager'];
    }

    if (
        $targetEmpId > 0 &&
        $targetSiteId > 0 &&
        $recipientId > 0 &&
        $remarkText !== '' &&
        in_array($reportKey, $validReportKeys, true) &&
        in_array($recipientRole, $allowedRecipientRoles, true)
    ) {
        $saveSql = "
            INSERT INTO employee_report_remarks (
                report_date, employee_id, site_id, report_key, reviewer_id, recipient_id, recipient_role, remark
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                reviewer_id = VALUES(reviewer_id),
                recipient_id = VALUES(recipient_id),
                recipient_role = VALUES(recipient_role),
                remark = VALUES(remark),
                updated_at = CURRENT_TIMESTAMP
        ";

        $st = mysqli_prepare($conn, $saveSql);

        if ($st) {
            mysqli_stmt_bind_param(
                $st,
                "siisiiss",
                $remarkDate,
                $targetEmpId,
                $targetSiteId,
                $reportKey,
                $employeeId,
                $recipientId,
                $recipientRole,
                $remarkText
            );

            if (mysqli_stmt_execute($st)) {
                $title = "Time Management Remark";
                $msg = "You received a remark for " . strtoupper($reportKey) . " on " . date('d M Y', strtotime($remarkDate)) . ".";
                $link = "emp-reports.php?status=all&site_id=" . $targetSiteId . "&date=" . urlencode($remarkDate) . "&report=" . urlencode($reportKey);

                createNotification(
                    $conn,
                    $recipientId,
                    $title,
                    $msg,
                    'remark',
                    'time_management',
                    $targetSiteId,
                    $link
                );

                $message = "Remark sent successfully and notification created.";
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
        'label' => 'DPR',
        'icon' => 'bi-file-text',
        'table' => 'dpr_reports',
        'dateFields' => ['dpr_date', 'report_date', 'created_at'],
        'noFields' => ['dpr_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/dpr.php?site_id={sid}',
        'printFile' => '../project-engineer/report-print.php',
        'downloadSupported' => true,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'dar',
        'label' => 'DAR',
        'icon' => 'bi-journal-text',
        'table' => 'dar_reports',
        'dateFields' => ['dar_date', 'report_date', 'created_at'],
        'noFields' => ['dar_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/dar.php?site_id={sid}',
        'printFile' => '../project-engineer/report-dar-print.php',
        'downloadSupported' => true,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'ma',
        'label' => 'MA',
        'icon' => 'bi-clipboard2-check',
        'table' => 'ma_reports',
        'dateFields' => ['ma_date', 'report_date', 'created_at'],
        'noFields' => ['ma_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/ma.php?site_id={sid}',
        'printFile' => '../project-engineer/report-ma-print.php',
        'downloadSupported' => true,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'mpt',
        'label' => 'MPT',
        'icon' => 'bi-graph-up',
        'table' => 'mpt_reports',
        'dateFields' => ['mpt_date', 'report_date', 'created_at'],
        'noFields' => ['mpt_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/mpt.php?site_id={sid}',
        'printFile' => '../project-engineer/report-mpt-print.php',
        'downloadSupported' => true,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'mom',
        'label' => 'MOM',
        'icon' => 'bi-people',
        'table' => 'mom_main',
        'dateFields' => ['mom_date', 'meeting_date', 'report_date', 'created_at'],
        'noFields' => ['mom_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/mom.php?site_id={sid}',
        'printFile' => '../project-engineer/report-mom-main-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'mom-short',
        'label' => 'MOM Short-term',
        'icon' => 'bi-chat-left-quote',
        'table' => 'mom_reports',
        'dateFields' => ['mom_date', 'meeting_date', 'report_date', 'created_at'],
        'noFields' => ['mom_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/mom-short.php?site_id={sid}',
        'printFile' => '../project-engineer/report-mom-print.php',
        'downloadSupported' => true,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'rfi',
        'label' => 'RFI',
        'icon' => 'bi-question-circle',
        'table' => 'rfi_reports',
        'dateFields' => ['rfi_date', 'report_date', 'created_at'],
        'noFields' => ['rfi_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/rfi.php?site_id={sid}',
        'printFile' => '',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'checklist',
        'label' => 'Checklist',
        'icon' => 'bi-card-checklist',
        'table' => 'checklist_reports',
        'dateFields' => ['checklist_date', 'report_date', 'created_at'],
        'noFields' => ['doc_no', 'checklist_no', 'report_no'],
        'openUrl' => '../project-engineer/checklist.php?site_id={sid}',
        'printFile' => '../project-engineer/report-checklist-print.php',
        'downloadSupported' => true,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'sat',
        'label' => 'SAT',
        'icon' => 'bi-bar-chart-steps',
        'table' => 'sat_reports',
        'dateFields' => ['sat_date', 'report_date', 'created_at'],
        'noFields' => ['sat_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/sat.php?site_id={sid}',
        'printFile' => '',
        'downloadSupported' => false,
        'specialDownloadUrl' => '../project-engineer/sat_report_pdf.php?batch_id={rid}',
    ],
    [
        'key' => 'dlar',
        'label' => 'DLAR',
        'icon' => 'bi-file-earmark-spreadsheet',
        'table' => 'dlar_reports',
        'dateFields' => ['dlar_date', 'report_date', 'created_at'],
        'noFields' => ['dlar_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/dlar.php?site_id={sid}',
        'printFile' => '../project-engineer/report-dlar-print.php',
        'downloadSupported' => true,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'ait',
        'label' => 'AIT',
        'icon' => 'bi-cpu',
        'table' => 'ait_main',
        'dateFields' => ['ait_date', 'report_date', 'created_at'],
        'noFields' => ['ait_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/ait.php?site_id={sid}',
        'printFile' => '../project-engineer/report-ait-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'mas',
        'label' => 'MAS',
        'icon' => 'bi-diagram-3',
        'table' => 'mas_main',
        'dateFields' => ['mas_date', 'report_date', 'created_at'],
        'noFields' => ['mas_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/mas.php?site_id={sid}',
        'printFile' => '../project-engineer/report-mas-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'pd',
        'label' => 'PD',
        'icon' => 'bi-graph-up',
        'table' => 'pd_main',
        'dateFields' => ['pd_date', 'report_date', 'created_at'],
        'noFields' => ['pd_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/pd.php?site_id={sid}',
        'printFile' => '../project-engineer/report-pd-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'pms',
        'label' => 'PMS',
        'icon' => 'bi-tools',
        'table' => 'pms_main',
        'dateFields' => ['pms_date', 'report_date', 'created_at'],
        'noFields' => ['pms_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/pms.php?site_id={sid}',
        'printFile' => '../project-engineer/report-pms-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'vfs',
        'label' => 'VFS',
        'icon' => 'bi-eye',
        'table' => 'vfs_main',
        'dateFields' => ['vfs_date', 'report_date', 'created_at'],
        'noFields' => ['vfs_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/vfs.php?site_id={sid}',
        'printFile' => '../project-engineer/report-vfs-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'vft',
        'label' => 'VFT',
        'icon' => 'bi-eye-fill',
        'table' => 'vft_main',
        'dateFields' => ['vft_date', 'report_date', 'created_at'],
        'noFields' => ['vft_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/vft.php?site_id={sid}',
        'printFile' => '../project-engineer/report-vft-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'wpt',
        'label' => 'WPT',
        'icon' => 'bi-database',
        'table' => 'wpt_main',
        'dateFields' => ['week_ends_on', 'wpt_date', 'report_date', 'created_at'],
        'noFields' => ['wpt_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/wpt.php?site_id={sid}',
        'printFile' => '../project-engineer/report-wpt-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'dds',
        'label' => 'DDS',
        'icon' => 'bi-database',
        'table' => 'dds_main',
        'dateFields' => ['dds_date', 'report_date', 'created_at'],
        'noFields' => ['dds_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/dds.php?site_id={sid}',
        'printFile' => '../project-engineer/report-dds-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'ddt',
        'label' => 'DDT',
        'icon' => 'bi-table',
        'table' => 'ddt_main',
        'dateFields' => ['ddt_date', 'report_date', 'created_at'],
        'noFields' => ['ddt_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/ddt.php?site_id={sid}',
        'printFile' => '../project-engineer/report-ddt-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
    [
        'key' => 'dpt',
        'label' => 'DPT',
        'icon' => 'bi-pie-chart',
        'table' => 'dpt_main',
        'dateFields' => ['dpt_date', 'report_date', 'created_at'],
        'noFields' => ['dpt_no', 'doc_no', 'report_no'],
        'openUrl' => '../project-engineer/dpt.php?site_id={sid}',
        'printFile' => '../project-engineer/report-dpt-print.php',
        'downloadSupported' => false,
        'specialDownloadUrl' => '',
    ],
];

// ---------------------------------------------------------
// FILTERS
// ---------------------------------------------------------
$filterStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
$filterSiteId = (int)($_GET['site_id'] ?? 0);
$filterReport = strtolower(trim((string)($_GET['report'] ?? 'all')));
$filterEmployeeId = (int)($_GET['employee_id'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));

$validStatuses = ['all', 'completed', 'incomplete'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = 'all';
}

$validReportKeys = array_column($reportTypes, 'key');
if ($filterReport !== 'all' && !in_array($filterReport, $validReportKeys, true)) {
    $filterReport = 'all';
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

if ($filterEmployeeId > 0) {
    $rows = array_values(array_filter($rows, function($r) use ($filterEmployeeId){
        return (int)$r['employee_id'] === $filterEmployeeId;
    }));
}

// ---------------------------------------------------------
// LOAD COMPLETION DATA FOR SELECTED DATE
// key: reportKey_employeeId_siteId => data
// ---------------------------------------------------------
$reportStatusMap = [];
$latestAnyCreatedAt = null;

foreach ($reportTypes as $rt) {
    $reportMap = fetchReportRowsForDate($conn, $rt, $todayYmd);

    foreach ($reportMap as $baseKey => $row) {
        $k = $rt['key'] . '_' . $baseKey;

        if (!isset($reportStatusMap[$k])) {
            $reportStatusMap[$k] = [
                'id' => (int)$row['id'],
                'doc_no' => $row['doc_no'] ?? '',
                'created_at' => $row['created_at'] ?? '',
                'key' => $rt['key'],
            ];
        }

        if (!empty($row['created_at'])) {
            if ($latestAnyCreatedAt === null || strtotime($row['created_at']) > strtotime($latestAnyCreatedAt)) {
                $latestAnyCreatedAt = $row['created_at'];
            }
        }
    }
}

// ---------------------------------------------------------
// LOAD TODAY REMARKS
// ---------------------------------------------------------
$remarksMap = [];
$remarksSql = "
    SELECT er.report_date, er.employee_id, er.site_id, er.report_key, er.recipient_role, er.recipient_id, er.remark, emp.full_name AS recipient_name
    FROM employee_report_remarks er
    LEFT JOIN employees emp ON emp.id = er.recipient_id
    WHERE er.report_date = ?
    ORDER BY er.updated_at DESC, er.created_at DESC
";
$st = mysqli_prepare($conn, $remarksSql);
if ($st) {
    mysqli_stmt_bind_param($st, "s", $todayYmd);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($res)) {
        $rk = $r['report_key'] . '_' . (int)$r['employee_id'] . '_' . (int)$r['site_id'];
        if (!isset($remarksMap[$rk])) {
            $remarksMap[$rk] = [];
        }

        $remarksMap[$rk][] = [
            'recipient_role' => $r['recipient_role'] ?? '',
            'recipient_name' => $r['recipient_name'] ?? '',
            'remark' => $r['remark'] ?? '',
        ];
    }
    mysqli_stmt_close($st);
}

// ---------------------------------------------------------
// BUILD DISPLAY ROWS
// ---------------------------------------------------------
$displayRows = [];

foreach ($rows as $base) {
    foreach ($reportTypes as $rt) {
        if ($filterReport !== 'all' && $filterReport !== $rt['key']) {
            continue;
        }

        $rk = $rt['key'] . '_' . $base['employee_id'] . '_' . $base['site_id'];
        $completed = isset($reportStatusMap[$rk]);
        $reportData = $completed ? $reportStatusMap[$rk] : null;

        $printUrl = '';
        $downloadUrl = '';

        if ($completed && !empty($reportData['id'])) {
            $rid = (int)$reportData['id'];

            if (!empty($rt['printFile'])) {
                $printUrl = $rt['printFile'] . '?view=' . urlencode((string)$rid);

                if (!empty($rt['downloadSupported'])) {
                    $downloadUrl = $rt['printFile'] . '?view=' . urlencode((string)$rid) . '&dl=1';
                }
            }

            if (!empty($rt['specialDownloadUrl'])) {
                $downloadUrl = str_replace('{rid}', urlencode((string)$rid), $rt['specialDownloadUrl']);
            }
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
            'tl_id' => (int)($base['tl_id'] ?? 0),
            'tl_name' => $base['tl_name'],
            'manager_id' => (int)($base['manager_id'] ?? 0),
            'manager_name' => $base['manager_name'],
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
            'remarks' => $remarksMap[$rk] ?? [],
            'remark' => !empty($remarksMap[$rk]) ? implode("\n", array_map(function($item) {
                $role = strtoupper((string)($item['recipient_role'] ?? ''));
                $name = trim((string)($item['recipient_name'] ?? ''));
                $text = trim((string)($item['remark'] ?? ''));
                $prefix = trim($role . ($name !== '' ? ' - ' . $name : ''));
                return ($prefix !== '' ? $prefix . ': ' : '') . $text;
            }, $remarksMap[$rk])) : '',
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
    :root {
        --page-bg: #f5f7fb;
        --card-bg: #ffffff;
        --border: #e5e7eb;
        --text: #111827;
        --muted: #6b7280;
        --soft: #f8fafc;
        --shadow: 0 10px 26px rgba(15, 23, 42, .055);
        --radius: 15px;
    }

    body { background: var(--page-bg); }

    .content-scroll {
        flex: 1 1 auto;
        overflow: auto;
        padding: 16px;
    }

    .projects-wrapper { width: 100%; }

    .page-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .page-heading h1 {
        font-size: 19px;
        font-weight: 900;
        color: var(--text);
        margin: 0;
    }

    .page-heading p {
        margin: 3px 0 0;
        color: var(--muted);
        font-size: 12px;
        font-weight: 600;
    }

    .primary-btn {
        border: 0;
        background: #111827;
        color: #fff;
        height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
    }

    .primary-btn:hover { background: #020617; color: #fff; }

    .secondary-btn {
        border: 1px solid var(--border) !important;
        background: #fff !important;
        color: #334155 !important;
        height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
    }

    .secondary-btn:hover {
        border-color: #cbd5e1 !important;
        background: #f8fafc !important;
        color: #111827 !important;
    }

    .stat-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 12px 13px;
        min-height: 78px;
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .stat-ic {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        color: #fff;
        font-size: 17px;
        flex: 0 0 auto;
    }

    .blue { background: #2f80ed; }
    .orange { background: #f2994a; }
    .green { background: #27ae60; }
    .red { background: #eb5757; }
    .gray { background: #64748b; }

    .stat-label {
        color: var(--muted);
        font-weight: 800;
        font-size: 10.5px;
        text-transform: uppercase;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 950;
        color: #111827;
        line-height: 1;
    }

    .panel {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 13px;
    }

    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        gap: 10px;
    }

    .panel-title {
        font-weight: 900;
        font-size: 14px;
        margin: 0;
        color: #111827;
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .panel-title i { color: #2563eb; font-size: 14px; }

    .panel-subtitle {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
    }

    .filter-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .search-box {
        position: relative;
        flex: 1 1 260px;
        max-width: 430px;
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 13px;
    }

    .search-box input {
        width: 100%;
        height: 36px;
        border: 1px solid var(--border);
        border-radius: 11px;
        background: #fff;
        padding: 0 12px 0 34px;
        font-size: 12px;
        font-weight: 700;
        color: var(--text);
        outline: none;
    }

    .search-box input:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .filter-select,
    .filter-date {
        height: 36px;
        border: 1px solid var(--border);
        border-radius: 11px;
        background: #fff;
        padding: 0 42px 0 12px;
        font-size: 12px;
        font-weight: 800;
        min-width: 145px;
        color: var(--text);
    }

    .filter-date { padding: 0 12px; }

    .filter-select:focus,
    .filter-date:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
        outline: none;
    }

    .compact-table-wrap {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 13px;
        overflow: hidden;
        background: #fff;
    }

    .compact-table {
        width: 100%;
        margin: 0;
        table-layout: auto;
    }

    .compact-table thead th {
        background: var(--soft);
        color: #64748b;
        font-size: 10px;
        text-transform: uppercase;
        font-weight: 900;
        border-bottom: 1px solid var(--border) !important;
        padding: 8px 9px !important;
        white-space: nowrap;
    }

    .compact-table tbody td {
        padding: 8px 9px !important;
        vertical-align: middle;
        border-color: #eef2f7;
        color: #334155;
        font-weight: 700;
        font-size: 11.5px;
    }

    .compact-table tbody tr:hover { background: #fbfdff; }

    .table-title-cell {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .table-icon {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        background: #eff6ff;
        color: #2563eb;
        font-size: 13px;
        flex: 0 0 auto;
    }

    .table-primary-text {
        color: #111827;
        font-size: 11.5px;
        font-weight: 900;
    }

    .table-secondary-text {
        color: #64748b;
        font-size: 10px;
        font-weight: 700;
        margin-top: 1px;
    }

    .badge-pill,
    .status-badge {
        border-radius: 999px;
        padding: 5px 8px;
        font-weight: 900;
        font-size: 10px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid transparent;
        white-space: nowrap;
        text-decoration: none;
    }

    .status-badge { text-transform: uppercase; }

    .status-green,
    .ontrack { color: #15803d; background: #dcfce7; border-color: #bbf7d0; }

    .status-yellow,
    .atrisk { color: #b45309; background: #ffedd5; border-color: #fed7aa; }

    .progressing { color: #2563eb; background: #dbeafe; border-color: #bfdbfe; }
    .pending { color: #6d28d9; background: #ede9fe; border-color: #ddd6fe; }
    .neutral { color: #475569; background: #f1f5f9; border-color: #e2e8f0; }

    .action-group {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .action-btn {
        width: 27px;
        height: 27px;
        border-radius: 9px;
        border: 1px solid var(--border);
        background: #fff;
        display: grid;
        place-items: center;
        text-decoration: none;
    }

    .view-btn { color: #475569; background: #f8fafc; }
    .file-btn { color: #10b981; background: #ecfdf5; }
    .report-btn { color: #2563eb; background: #eff6ff; }

    .team-text {
        font-size: 10px;
        color: #64748b;
        line-height: 1.5;
        font-weight: 750;
    }

    .team-text b { color: #111827; }

    .remark-column { min-width: 220px; }

    .remark-preview {
        max-width: 310px;
        color: #64748b;
        font-size: 10.5px;
        font-weight: 750;
        line-height: 1.35;
        white-space: pre-wrap;
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 10px;
        padding: 7px 9px;
        margin-bottom: 7px;
    }

    .remark-preview.empty { color: #94a3b8; font-style: italic; }

    .remark-buttons { display: flex; gap: 6px; flex-wrap: wrap; }

    .remark-btn {
        min-height: 29px;
        padding: 0 10px;
        border-radius: 10px;
        border: 1px solid #dbeafe;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 11px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        text-decoration: none;
        white-space: nowrap;
    }

    .remark-btn:hover { background: #dbeafe; color: #1e40af; }
    .remark-btn.rtl { border-color: #ede9fe; background: #f5f3ff; color: #6d28d9; }
    .remark-btn.rm { border-color: #ffedd5; background: #fff7ed; color: #c2410c; }

    .empty-state {
        text-align: center;
        color: #64748b;
        padding: 30px 12px;
        font-size: 12px;
        font-weight: 900;
    }

    .empty-state i {
        font-size: 34px;
        display: block;
        margin-bottom: 8px;
        opacity: .45;
    }

    .pagination-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding-top: 12px;
        flex-wrap: wrap;
    }

    .pagination-info {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
    }

    .alert {
        border-radius: var(--radius);
        border: none;
        box-shadow: var(--shadow);
        margin-bottom: 14px;
    }

    .modal-content {
        border: 1px solid var(--border);
        border-radius: 18px;
        box-shadow: 0 24px 65px rgba(15,23,42,.18);
    }

    .modal-header,
    .modal-footer { border-color: #eef2f7; }

    .modal-title { font-size: 15px; font-weight: 950; color: #111827; }

    .modal-info {
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 14px;
        padding: 11px 12px;
        margin-bottom: 12px;
    }

    .modal-info .label {
        color: #64748b;
        font-size: 10px;
        font-weight: 950;
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .modal-info .value { color: #111827; font-size: 12px; font-weight: 900; }

    .remark-textarea {
        min-height: 90px;
        border: 1px solid var(--border);
        border-radius: 12px;
        font-size: 12px;
        font-weight: 800;
        color: #111827;
        padding: 10px 11px;
        background: #fff;
        box-shadow: none !important;
    }

    .reason-cell {
        max-width: 260px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    @media(max-width:991.98px) {
        .main { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }

        .sidebar {
            position: fixed !important;
            transform: translateX(-100%);
            z-index: 1040 !important;
        }

        .sidebar.open,
        .sidebar.active,
        .sidebar.show { transform: translateX(0) !important; }
    }

    @media(max-width:1199px) {
        .compact-table-wrap {
            border: 0;
            border-radius: 0;
            overflow: visible;
            background: transparent;
        }

        .compact-table {
            border-collapse: separate;
            border-spacing: 0;
            margin: 0;
        }

        .compact-table thead { display: none; }

        .compact-table,
        .compact-table tbody,
        .compact-table tr,
        .compact-table td {
            display: block;
            width: 100%;
        }

        .compact-table tbody tr {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .045);
            padding: 12px;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .compact-table tbody tr:hover { background: #fff; }

        .compact-table tbody td {
            border: 0 !important;
            display: grid !important;
            grid-template-columns: 92px minmax(0, 1fr);
            column-gap: 10px;
            align-items: flex-start;
            padding: 8px 0 !important;
            color: #334155;
            text-align: left !important;
            min-width: 0;
        }

        .compact-table tbody td::before {
            content: attr(data-label);
            grid-column: 1;
            color: #64748b;
            font-size: 10px;
            font-weight: 950;
            letter-spacing: .02em;
            text-transform: uppercase;
            line-height: 1.25;
            padding-top: 2px;
            min-width: 0;
        }

        .compact-table tbody td>* {
            grid-column: 2;
            min-width: 0;
        }

        .compact-table tbody td:first-child {
            display: grid !important;
            padding-top: 0 !important;
        }

        .compact-table tbody td:first-child::before { display: block; }

        .compact-table tbody td:last-child { padding-bottom: 0 !important; }

        .table-title-cell {
            align-items: flex-start;
            min-width: 0;
            max-width: 100%;
        }

        .table-title-cell>div:last-child {
            min-width: 0;
            max-width: 100%;
        }

        .table-icon {
            width: 24px;
            height: 24px;
            border-radius: 8px;
            font-size: 12px;
            flex: 0 0 24px;
            margin-top: 1px;
        }

        .table-primary-text,
        .table-secondary-text,
        .team-text,
        .team-text div {
            max-width: 100%;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        .reason-cell,
        .remark-preview {
            max-width: 100%;
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            text-align: left;
        }

        .badge-pill,
        .status-badge {
            justify-self: flex-start;
            max-width: 100%;
            white-space: normal;
            line-height: 1.25;
            padding: 5px 9px;
        }

        .action-group {
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 7px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 10px;
        }

        .pagination-wrap { align-items: flex-start; }
    }

    @media(max-width:768px) {
        .content-scroll { padding: 12px 10px 12px !important; }

        .page-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .page-heading .d-flex { width: 100%; }

        .filter-bar { align-items: stretch; }

        .search-box {
            max-width: none;
            width: 100%;
            flex: 1 1 100%;
        }

        .filter-select,
        .filter-date,
        .primary-btn,
        .secondary-btn {
            width: 100%;
            justify-content: center;
        }

        .panel {
            padding: 12px;
            border-radius: 14px;
        }

        .compact-table tbody td {
            grid-template-columns: 84px minmax(0, 1fr);
            column-gap: 9px;
            padding: 7px 0 !important;
        }

        .compact-table tbody td::before { font-size: 9.8px; }

        .compact-table tbody tr {
            padding: 11px;
            border-radius: 13px;
        }

        .pagination-info {
            width: 100%;
            line-height: 1.45;
        }

        .modal-footer {
            flex-direction: column-reverse;
            align-items: stretch;
        }
    }

    @media(max-width:420px) {
        .compact-table tbody td {
            grid-template-columns: 76px minmax(0, 1fr);
            column-gap: 8px;
        }

        .table-primary-text { font-size: 11px; }

        .table-secondary-text,
        .team-text { font-size: 10px; }

        .action-btn {
            width: 31px;
            height: 31px;
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
                <div class="container-fluid projects-wrapper px-0">

                    <div class="page-heading">
                        <div>
                            <h1>Employee Time Management Reports</h1>
                            <p>Common report monitoring for TL, Manager and Admin</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="emp-reports.php" class="secondary-btn">
                                <i class="bi bi-arrow-clockwise"></i>
                                Refresh
                            </a>
                        </div>
                    </div>

                    <?php if ($message !== ''): ?>
                    <div class="alert alert-<?php echo e($messageType); ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-files"></i></div>
                                <div>
                                    <div class="stat-label">Total Rows</div>
                                    <div class="stat-value"><?php echo (int)$totalTasks; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <div class="stat-label">Completed</div>
                                    <div class="stat-value"><?php echo (int)$completedCount; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="stat-label">Incomplete</div>
                                    <div class="stat-value"><?php echo (int)$incompleteCount; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic gray"><i class="bi bi-clock-history"></i></div>
                                <div>
                                    <div class="stat-label">Latest Submit</div>
                                    <div class="stat-value" style="font-size:17px;"><?php echo e(fmtTime($latestAnyCreatedAt)); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel mb-4">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">
                                    <i class="bi bi-list-check"></i>
                                    Employee Report Status
                                </h3>
                                <div class="panel-subtitle">
                                    Showing <?php echo count($displayRows); ?> document row(s) based on selected filters
                                </div>
                            </div>
                        </div>

                        <form method="get" class="filter-bar">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input
                                    type="text"
                                    name="search"
                                    id="reportSearch"
                                    placeholder="Search employee, project, manager, TL or report..."
                                    value="<?php echo e($search); ?>"
                                >
                            </div>

                            <select name="status" class="filter-select">
                                <option value="all" <?php echo $filterStatus==='all'?'selected':''; ?>>All Status</option>
                                <option value="completed" <?php echo $filterStatus==='completed'?'selected':''; ?>>Completed</option>
                                <option value="incomplete" <?php echo $filterStatus==='incomplete'?'selected':''; ?>>Incomplete</option>
                            </select>

                            <input type="date" name="date" class="filter-date" value="<?php echo e($todayYmd); ?>">

                            <select name="site_id" class="filter-select">
                                <option value="0">All Projects</option>
                                <?php foreach ($sitesById as $sid => $site): ?>
                                    <option value="<?php echo (int)$sid; ?>" <?php echo $filterSiteId===(int)$sid?'selected':''; ?>>
                                        <?php echo e($site['project_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="report" class="filter-select">
                                <option value="all">All Documents</option>
                                <?php foreach ($reportTypes as $rtFilter): ?>
                                    <option value="<?php echo e($rtFilter['key']); ?>" <?php echo $filterReport===$rtFilter['key']?'selected':''; ?>>
                                        <?php echo e($rtFilter['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button class="primary-btn" type="submit">
                                <i class="bi bi-funnel"></i>
                                Filter
                            </button>

                            <a class="secondary-btn" href="emp-reports.php">
                                <i class="bi bi-x-circle"></i>
                                Clear
                            </a>
                        </form>

                        <div class="compact-table-wrap">
                            <table class="table compact-table align-middle" id="reportsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Employee</th>
                                        <th>Project</th>
                                        <th>Report</th>
                                        <th>Status</th>
                                        <th>Doc / Time</th>
                                        <th class="remark-column">Remark</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($displayRows)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No report records found.
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php $i = 1; ?>
                                    <?php foreach ($displayRows as $row): ?>
                                    <tr>
                                        <td data-label="#"><?php echo $i++; ?></td>

                                        <td data-label="Employee">
                                            <div class="table-title-cell">
                                                <div class="table-icon"><i class="bi bi-person"></i></div>
                                                <div>
                                                    <div class="table-primary-text"><?php echo e($row['employee_name']); ?></div>
                                                    <div class="table-secondary-text">
                                                        <?php echo e($row['employee_designation']); ?>
                                                        <?php if (!empty($row['employee_code'])): ?>
                                                            • <?php echo e($row['employee_code']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td data-label="Project">
                                            <div class="table-primary-text"><?php echo e($row['project_name']); ?></div>
                                            <div class="table-secondary-text">
                                                <i class="bi bi-geo-alt"></i>
                                                <?php echo e($row['project_location']); ?>
                                            </div>
                                        </td>

                                        <td data-label="Report">
                                            <div class="table-title-cell">
                                                <div class="table-icon"><i class="bi <?php echo e($row['report_icon']); ?>"></i></div>
                                                <div class="table-primary-text"><?php echo e($row['report_label']); ?></div>
                                            </div>
                                        </td>

                                        <td data-label="Status">
                                            <?php if ($row['is_completed']): ?>
                                                <span class="status-badge status-green">
                                                    <i class="bi bi-check2-circle"></i>
                                                    Completed
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-yellow">
                                                    <i class="bi bi-hourglass-split"></i>
                                                    Incomplete
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td data-label="Doc / Time">
                                            <?php if ($row['is_completed']): ?>
                                                <div class="table-primary-text"><?php echo e($row['doc_no'] ?: '—'); ?></div>
                                                <div class="table-secondary-text"><?php echo e(fmtTime($row['created_at'])); ?></div>
                                            <?php else: ?>
                                                <div class="table-primary-text">—</div>
                                            <?php endif; ?>
                                        </td>

                                        <td data-label="Remark" class="remark-column">
                                            <?php if (!empty($row['remark'])): ?>
                                                <div class="remark-preview"><?php echo e($row['remark']); ?></div>
                                            <?php else: ?>
                                                <div class="remark-preview empty">No remark sent yet.</div>
                                            <?php endif; ?>

                                            <div class="remark-buttons">
                                                <?php if ($currentRole === 'tl' || $currentRole === 'manager' || $currentRole === 'admin'): ?>
                                                    <button type="button" class="remark-btn open-remark-modal" data-bs-toggle="modal" data-bs-target="#remarkModal"
                                                        data-recipient-id="<?php echo (int)$row['employee_id']; ?>"
                                                        data-recipient-role="pe"
                                                        data-recipient-label="Project Engineer"
                                                        data-employee-id="<?php echo (int)$row['employee_id']; ?>"
                                                        data-site-id="<?php echo (int)$row['site_id']; ?>"
                                                        data-report-key="<?php echo e($row['report_key']); ?>"
                                                        data-report-date="<?php echo e($todayYmd); ?>"
                                                        data-employee-name="<?php echo e($row['employee_name']); ?>"
                                                        data-project-name="<?php echo e($row['project_name']); ?>"
                                                        data-report-label="<?php echo e($row['report_label']); ?>">
                                                        <i class="bi bi-chat-left-text"></i> RPE
                                                    </button>
                                                <?php endif; ?>

                                                <?php if (($currentRole === 'manager' || $currentRole === 'admin') && !empty($row['tl_id'])): ?>
                                                    <button type="button" class="remark-btn rtl open-remark-modal" data-bs-toggle="modal" data-bs-target="#remarkModal"
                                                        data-recipient-id="<?php echo (int)$row['tl_id']; ?>"
                                                        data-recipient-role="tl"
                                                        data-recipient-label="Team Lead"
                                                        data-employee-id="<?php echo (int)$row['employee_id']; ?>"
                                                        data-site-id="<?php echo (int)$row['site_id']; ?>"
                                                        data-report-key="<?php echo e($row['report_key']); ?>"
                                                        data-report-date="<?php echo e($todayYmd); ?>"
                                                        data-employee-name="<?php echo e($row['employee_name']); ?>"
                                                        data-project-name="<?php echo e($row['project_name']); ?>"
                                                        data-report-label="<?php echo e($row['report_label']); ?>">
                                                        <i class="bi bi-chat-left-text"></i> RTL
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($currentRole === 'admin' && !empty($row['manager_id'])): ?>
                                                    <button type="button" class="remark-btn rm open-remark-modal" data-bs-toggle="modal" data-bs-target="#remarkModal"
                                                        data-recipient-id="<?php echo (int)$row['manager_id']; ?>"
                                                        data-recipient-role="manager"
                                                        data-recipient-label="Manager"
                                                        data-employee-id="<?php echo (int)$row['employee_id']; ?>"
                                                        data-site-id="<?php echo (int)$row['site_id']; ?>"
                                                        data-report-key="<?php echo e($row['report_key']); ?>"
                                                        data-report-date="<?php echo e($todayYmd); ?>"
                                                        data-employee-name="<?php echo e($row['employee_name']); ?>"
                                                        data-project-name="<?php echo e($row['project_name']); ?>"
                                                        data-report-label="<?php echo e($row['report_label']); ?>">
                                                        <i class="bi bi-chat-left-text"></i> RM
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td data-label="Actions">
                                            <div class="action-group">
                                                <a class="action-btn view-btn" href="<?php echo e($row['open_url']); ?>" title="Open">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>

                                                <?php if (!empty($row['print_url'])): ?>
                                                <a class="action-btn report-btn" href="<?php echo e($row['print_url']); ?>" target="_blank" rel="noopener" title="Print / View">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <?php endif; ?>

                                                <?php if (!empty($row['download_url'])): ?>
                                                <a class="action-btn file-btn" href="<?php echo e($row['download_url']); ?>" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <?php endif; ?>

                                                <?php if (!$row['is_completed']): ?>
                                                    <span class="table-secondary-text">Not Submitted</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination-wrap">
                            <div class="pagination-info">
                                Showing <?php echo count($displayRows); ?> document row(s)
                            </div>
                            <div class="pagination-info">
                                <i class="bi bi-info-circle"></i>
                                RPE / RTL / RM buttons send remark notifications.
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Remark Modal -->
<div class="modal fade" id="remarkModal" tabindex="-1" aria-labelledby="remarkModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="remarkModalForm">
                <input type="hidden" name="save_remark" value="1">
                <input type="hidden" name="report_date" id="modal_report_date">
                <input type="hidden" name="employee_id" id="modal_employee_id">
                <input type="hidden" name="site_id" id="modal_site_id">
                <input type="hidden" name="report_key" id="modal_report_key">
                <input type="hidden" name="recipient_id" id="modal_recipient_id">
                <input type="hidden" name="recipient_role" id="modal_recipient_role">

                <div class="modal-header">
                    <h5 class="modal-title" id="remarkModalLabel">
                        <i class="bi bi-chat-left-text me-2"></i>
                        Send Remark
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="modal-info">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="label">To</div>
                                <div class="value" id="modal_recipient_label">—</div>
                            </div>
                            <div class="col-6">
                                <div class="label">Employee</div>
                                <div class="value" id="modal_employee_name">—</div>
                            </div>
                            <div class="col-6">
                                <div class="label">Project</div>
                                <div class="value" id="modal_project_name">—</div>
                            </div>
                            <div class="col-6">
                                <div class="label">Document</div>
                                <div class="value" id="modal_report_label">—</div>
                            </div>
                        </div>
                    </div>

                    <label class="form-label">Remark</label>
                    <textarea
                        name="remark"
                        id="modal_remark"
                        class="form-control remark-textarea"
                        rows="5"
                        placeholder="Enter remark and click Send..."
                        required
                    ></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i>
                        Cancel
                    </button>
                    <button type="submit" class="primary-btn">
                        <i class="bi bi-send"></i>
                        Send Remark
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('remarkModal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        if (!button) return;

        const dataset = button.dataset;

        document.getElementById('modal_report_date').value = dataset.reportDate || '';
        document.getElementById('modal_employee_id').value = dataset.employeeId || '';
        document.getElementById('modal_site_id').value = dataset.siteId || '';
        document.getElementById('modal_report_key').value = dataset.reportKey || '';
        document.getElementById('modal_recipient_id').value = dataset.recipientId || '';
        document.getElementById('modal_recipient_role').value = dataset.recipientRole || '';

        document.getElementById('modal_recipient_label').textContent = dataset.recipientLabel || '—';
        document.getElementById('modal_employee_name').textContent = dataset.employeeName || '—';
        document.getElementById('modal_project_name').textContent = dataset.projectName || '—';
        document.getElementById('modal_report_label').textContent = dataset.reportLabel || '—';

        const textarea = document.getElementById('modal_remark');
        textarea.value = '';
        setTimeout(function () { textarea.focus(); }, 250);
    });
});
</script>

</body>
</html>

