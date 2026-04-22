<?php
// dlar.php — Delay Analysis Report (DLAR)
// Same UI/template style as existing TEK-C pages
// Simple input layout with Add More rows
// Saves DLAR rows as JSON in dlar_reports.items_json

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_SESSION['employee_id'] ?? 0);
$designation = strtolower(trim((string) ($_SESSION['designation'] ?? '')));

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
function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function jsonCleanRows(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $has = false;
        foreach ($r as $k => $v) {
            if ($k === 'sl_no') {
                continue;
            }
            if (trim((string) $v) !== '') {
                $has = true;
                break;
            }
        }
        if ($has) {
            $out[] = $r;
        }
    }
    return $out;
}

function monthName(int $m): string
{
    $names = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December'
    ];
    return $names[$m] ?? 'Month';
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, designation FROM employees WHERE id=? LIMIT 1");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $empRow = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
}
$preparedBy = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');

// ---------------- Create DLAR Table if Not Exists ----------------
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS dlar_reports (
    id INT(11) NOT NULL AUTO_INCREMENT,
    site_id INT(11) NOT NULL,
    employee_id INT(11) NOT NULL,

    dlar_no VARCHAR(60) NOT NULL,
    report_date DATE NOT NULL,
    report_month TINYINT NOT NULL,
    report_year SMALLINT NOT NULL,

    project_name VARCHAR(255) DEFAULT NULL,
    client_name VARCHAR(255) DEFAULT NULL,
    architect_name VARCHAR(255) DEFAULT NULL,
    pmc_name VARCHAR(255) DEFAULT NULL,
    date_version VARCHAR(100) DEFAULT NULL,

    items_json LONGTEXT DEFAULT NULL,
    prepared_by VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),

    PRIMARY KEY (id),
    KEY idx_dlar_site (site_id),
    KEY idx_dlar_employee (employee_id),
    KEY idx_dlar_month (report_month),
    KEY idx_dlar_year (report_year),
    CONSTRAINT fk_dlar_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_dlar_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ---------------- Assigned Sites ----------------
$sites = [];

if ($designation === 'manager') {
    $q = "
        SELECT s.id, s.project_name, s.project_location, c.client_name, s.expected_completion_date
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
        SELECT s.id, s.project_name, s.project_location, c.client_name, s.expected_completion_date
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

// ---------------- Selected Site ----------------
$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
$site = null;

if ($siteId > 0) {
    $isAllowedSite = false;
    foreach ($sites as $s) {
        if ((int) $s['id'] === $siteId) {
            $isAllowedSite = true;
            break;
        }
    }

    if ($isAllowedSite) {
        $sql = "
            SELECT
                s.id, s.project_name, s.project_type, s.project_location, s.scope_of_work,
                s.start_date, s.expected_completion_date,
                c.client_name, c.client_type, c.company_name
            FROM sites s
            INNER JOIN clients c ON c.id = s.client_id
            WHERE s.id = ?
            LIMIT 1
        ";
        $st = mysqli_prepare($conn, $sql);
        if ($st) {
            mysqli_stmt_bind_param($st, "i", $siteId);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            $site = mysqli_fetch_assoc($res);
            mysqli_stmt_close($st);
        }
    }
}

// ---------------- Month/Year Defaults ----------------
$nowMonth = (int) date('n');
$nowYear = (int) date('Y');

$formMonth = isset($_GET['m']) ? (int) $_GET['m'] : $nowMonth;
$formYear = isset($_GET['y']) ? (int) $_GET['y'] : $nowYear;

if ($formMonth < 1 || $formMonth > 12)
    $formMonth = $nowMonth;
if ($formYear < 2000 || $formYear > 2100)
    $formYear = $nowYear;

// ---------------- Default DLAR No ----------------
$defaultDlarNo = '';
if ($siteId > 0) {
    $seq = 1;
    $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM dlar_reports WHERE site_id=? AND report_month=? AND report_year=?");
    if ($st) {
        mysqli_stmt_bind_param($st, "iii", $siteId, $formMonth, $formYear);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($st);
        $seq = ((int) ($row['cnt'] ?? 0)) + 1;
    }
    $defaultDlarNo = 'DLAR-' . $siteId . '-' . date('Ym') . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
}

// ---------------- SUBMIT ----------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dlar'])) {
    $site_id = (int) ($_POST['site_id'] ?? 0);
    $dlar_no = trim((string) ($_POST['dlar_no'] ?? ''));
    $report_date = trim((string) ($_POST['report_date'] ?? ''));
    $report_month = (int) ($_POST['report_month'] ?? 0);
    $report_year = (int) ($_POST['report_year'] ?? 0);

    $project_name = trim((string) ($_POST['project_name'] ?? ''));
    $client_name = trim((string) ($_POST['client_name'] ?? ''));
    $architect_name = trim((string) ($_POST['architect_name'] ?? ''));
    $pmc_name = trim((string) ($_POST['pmc_name'] ?? ''));
    $date_version = trim((string) ($_POST['date_version'] ?? ''));

    $okSite = false;
    foreach ($sites as $s) {
        if ((int) $s['id'] === $site_id) {
            $okSite = true;
            break;
        }
    }

    if (!$okSite)
        $error = "Invalid site selection.";
    if ($error === '' && $site_id <= 0)
        $error = "Please choose a site.";
    if ($error === '' && $dlar_no === '')
        $error = "DLAR No is required.";
    if ($error === '' && $report_date === '')
        $error = "Report date is required.";
    if ($error === '' && ($report_month < 1 || $report_month > 12))
        $error = "Invalid month.";
    if ($error === '' && ($report_year < 2000 || $report_year > 2100))
        $error = "Invalid year.";

    $sl_no = $_POST['sl_no'] ?? [];
    $delayed_task = $_POST['delayed_task'] ?? [];
    $planned_date = $_POST['planned_date'] ?? [];
    $actual_date = $_POST['actual_date'] ?? [];
    $delay_days = $_POST['delay_days'] ?? [];
    $delay_response_by = $_POST['delay_response_by'] ?? [];
    $issues_opened_on = $_POST['issues_opened_on'] ?? [];
    $reminders_dated = $_POST['reminders_dated'] ?? [];
    $issues_closed_on = $_POST['issues_closed_on'] ?? [];

    $items = [];
    $max = max(
        count($sl_no),
        count($delayed_task),
        count($planned_date),
        count($actual_date),
        count($delay_days),
        count($delay_response_by),
        count($issues_opened_on),
        count($reminders_dated),
        count($issues_closed_on)
    );

    for ($i = 0; $i < $max; $i++) {
        $items[] = [
            'sl_no' => $sl_no[$i] ?? ($i + 1),
            'delayed_task' => trim((string) ($delayed_task[$i] ?? '')),
            'planned_date' => trim((string) ($planned_date[$i] ?? '')),
            'actual_date' => trim((string) ($actual_date[$i] ?? '')),
            'delay_days' => trim((string) ($delay_days[$i] ?? '')),
            'delay_response_by' => trim((string) ($delay_response_by[$i] ?? '')),
            'issues_opened_on' => trim((string) ($issues_opened_on[$i] ?? '')),
            'reminders_dated' => trim((string) ($reminders_dated[$i] ?? '')),
            'issues_closed_on' => trim((string) ($issues_closed_on[$i] ?? '')),
        ];
    }

    $items = jsonCleanRows($items);

    if ($error === '' && empty($items)) {
        $error = "Please enter at least one delayed task row.";
    }

    if ($error === '') {
        $items_json = json_encode($items, JSON_UNESCAPED_UNICODE);

        $ins = mysqli_prepare($conn, "
            INSERT INTO dlar_reports
            (
                site_id, employee_id, dlar_no, report_date, report_month, report_year,
                project_name, client_name, architect_name, pmc_name, date_version,
                items_json, prepared_by
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        if (!$ins) {
            $error = "DB Error: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param(
                $ins,
                "iissiisssssss",
                $site_id,
                $employeeId,
                $dlar_no,
                $report_date,
                $report_month,
                $report_year,
                $project_name,
                $client_name,
                $architect_name,
                $pmc_name,
                $date_version,
                $items_json,
                $preparedBy
            );

            if (!mysqli_stmt_execute($ins)) {
                $error = "Failed to save DLAR: " . mysqli_stmt_error($ins);
            } else {
                $newId = mysqli_insert_id($conn);
                mysqli_stmt_close($ins);
                header("Location: dlar.php?site_id=" . $site_id . "&m=" . $report_month . "&y=" . $report_year . "&saved=1&dlar_id=" . $newId);
                exit;
            }
            mysqli_stmt_close($ins);
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success = "DLAR submitted successfully.";
}

// ---------------- Recent DLAR ----------------
$recent = [];
$st = mysqli_prepare($conn, "
    SELECT r.id, r.dlar_no, r.report_date, r.report_month, r.report_year, s.project_name
    FROM dlar_reports r
    INNER JOIN sites s ON s.id = r.site_id
    WHERE r.employee_id = ?
    ORDER BY r.report_date DESC, r.created_at DESC
    LIMIT 10
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $recent = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
}

// ---------------- Form Defaults ----------------
$formSiteId = $siteId;
$formDlarNo = $defaultDlarNo;
$formReportDate = date('Y-m-d');
$defaultProject = $site['project_name'] ?? '';
$defaultClient = $site['client_name'] ?? '';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>DLAR - TEK-C</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll {
            flex: 1 1 auto;
            overflow: auto;
            padding: 22px 22px 14px;
        }

        .panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(17, 24, 39, .05);
            padding: 16px;
            margin-bottom: 14px;
        }

        .title-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .h-title {
            margin: 0;
            font-weight: 1000;
            color: #111827;
        }

        .h-sub {
            margin: 4px 0 0;
            color: #6b7280;
            font-weight: 800;
            font-size: 13px;
        }

        .form-label {
            font-weight: 900;
            color: #374151;
            font-size: 13px;
        }

        .form-control,
        .form-select,
        textarea.form-control {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 10px 12px;
            font-weight: 750;
            font-size: 14px;
        }

        .sec-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 14px;
            background: #f9fafb;
            border: 1px solid #eef2f7;
            margin-bottom: 10px;
        }

        .sec-ic {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: rgba(45, 156, 219, .12);
            color: var(--blue);
            flex: 0 0 auto;
        }

        .sec-title {
            margin: 0;
            font-weight: 1000;
            color: #111827;
            font-size: 14px;
        }

        .sec-sub {
            margin: 2px 0 0;
            color: #6b7280;
            font-weight: 800;
            font-size: 12px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }

        .grid-5 {
            display: grid;
            grid-template-columns: 1.25fr 1.25fr 1fr 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 992px) {

            .grid-2,
            .grid-3,
            .grid-5 {
                grid-template-columns: 1fr;
            }
        }

        .table thead th {
            font-size: 12px;
            color: #6b7280;
            font-weight: 900;
            border-bottom: 1px solid #e5e7eb !important;
            background: #f9fafb;
            text-align: center;
            vertical-align: middle;
        }

        .btn-primary-tek {
            background: var(--blue);
            border: none;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 1000;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 12px 26px rgba(45, 156, 219, .18);
            color: #fff;
        }

        .btn-primary-tek:hover {
            background: #2a8bc9;
            color: #fff;
        }

        .btn-addrow {
            border-radius: 12px;
            font-weight: 900;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-weight: 900;
            font-size: 12px;
        }

        .small-muted {
            color: #6b7280;
            font-weight: 800;
            font-size: 12px;
        }

        @media (max-width: 991.98px) {
            .main {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .sidebar {
                position: fixed !important;
                transform: translateX(-100%);
                z-index: 1040 !important;
            }

            .sidebar.open,
            .sidebar.active,
            .sidebar.show {
                transform: translateX(0) !important;
            }
        }

        @media (max-width: 768px) {
            .content-scroll {
                padding: 12px 10px 12px !important;
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

                    <div class="title-row mb-3">
                        <div>
                            <h1 class="h-title">Delay Analysis Report (DLAR)</h1>
                            <p class="h-sub">Create and submit your project delay analysis report</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($preparedBy); ?></span>
                            <span class="badge-pill"><i class="bi bi-award"></i>
                                <?php echo e($empRow['designation'] ?? ($_SESSION['designation'] ?? '')); ?></span>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert"
                            style="border-radius:14px;">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert"
                            style="border-radius:14px;">
                            <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- SITE PICKER -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-geo-alt"></i></div>
                            <div>
                                <p class="sec-title mb-0">Project Selection</p>
                                <p class="sec-sub mb-0">Choose the site to prepare DLAR</p>
                            </div>
                        </div>

                        <div class="grid-3">
                            <div>
                                <label class="form-label">My Assigned Sites <span class="text-danger">*</span></label>
                                <select class="form-select" id="sitePicker">
                                    <option value="">-- Select Site --</option>
                                    <?php foreach ($sites as $s): ?>
                                        <?php $sid = (int) $s['id']; ?>
                                        <option value="<?php echo $sid; ?>" <?php echo ($sid === $formSiteId ? 'selected' : ''); ?>>
                                            <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?>
                                            (<?php echo e($s['client_name']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="small-muted mt-1">Selecting a site will load project details.</div>
                            </div>

                            <div>
                                <label class="form-label">Month</label>
                                <select class="form-select" id="monthPicker">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo ($m === $formMonth ? 'selected' : ''); ?>>
                                            <?php echo e(monthName($m)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Year</label>
                                <select class="form-select" id="yearPicker">
                                    <?php for ($y = (int) date('Y') - 2; $y <= (int) date('Y') + 3; $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($y === $formYear ? 'selected' : ''); ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <div class="d-flex align-items-end justify-content-end mt-3">
                            <a class="btn btn-outline-secondary" href="dlar.php"
                                style="border-radius:12px; font-weight:900;">
                                <i class="bi bi-arrow-clockwise"></i> Reset
                            </a>
                        </div>
                    </div>

                    <!-- PROJECT DETAILS -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-building"></i></div>
                            <div>
                                <p class="sec-title mb-0">Project Details</p>
                                <p class="sec-sub mb-0">Auto-filled from selected site</p>
                            </div>
                        </div>

                        <?php if (!$site): ?>
                            <div class="text-muted" style="font-weight:800;">Please select a site above to load project
                                details.</div>
                        <?php else: ?>
                            <div class="grid-3">
                                <div>
                                    <div class="small-muted">Project</div>
                                    <div style="font-weight:1000;"><?php echo e($site['project_name']); ?></div>
                                </div>
                                <div>
                                    <div class="small-muted">Client</div>
                                    <div style="font-weight:1000;"><?php echo e($site['client_name']); ?></div>
                                </div>
                                <div>
                                    <div class="small-muted">Location</div>
                                    <div style="font-weight:1000;"><?php echo e($site['project_location']); ?></div>
                                </div>
                            </div>

                            <hr style="border-color:#eef2f7;">

                            <div class="grid-3">
                                <div>
                                    <div class="small-muted">Project Type</div>
                                    <div style="font-weight:900;"><?php echo e($site['project_type']); ?></div>
                                </div>
                                <div>
                                    <div class="small-muted">Project Start</div>
                                    <div style="font-weight:900;"><?php echo e($site['start_date']); ?></div>
                                </div>
                                <div>
                                    <div class="small-muted">Expected Completion</div>
                                    <div style="font-weight:900;"><?php echo e($site['expected_completion_date']); ?></div>
                                </div>
                            </div>

                            <div class="mt-2">
                                <div class="small-muted">Scope of Work</div>
                                <div style="font-weight:850;"><?php echo e($site['scope_of_work']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- FORM -->
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="submit_dlar" value="1">
                        <input type="hidden" name="site_id" value="<?php echo (int) $formSiteId; ?>">
                        <input type="hidden" name="report_month" value="<?php echo (int) $formMonth; ?>">
                        <input type="hidden" name="report_year" value="<?php echo (int) $formYear; ?>">

                        <!-- DLAR HEADER -->
                        <div class="panel">
                            <div class="sec-head">
                                <div class="sec-ic"><i class="bi bi-file-text"></i></div>
                                <div>
                                    <p class="sec-title mb-0">DLAR Header</p>
                                    <p class="sec-sub mb-0">Basic report details</p>
                                </div>
                            </div>

                            <div class="grid-3">
                                <div>
                                    <label class="form-label">DLAR No <span class="text-danger">*</span></label>
                                    <input class="form-control" name="dlar_no" value="<?php echo e($formDlarNo); ?>"
                                        required>
                                </div>
                                <div>
                                    <label class="form-label">Report Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="report_date"
                                        value="<?php echo e($formReportDate); ?>" required>
                                </div>
                                <div>
                                    <label class="form-label">Date / Version</label>
                                    <input class="form-control" name="date_version"
                                        value="<?php echo e(date('d-m-Y') . ' / V1'); ?>"
                                        placeholder="e.g. 22-04-2026 / V1">
                                </div>
                            </div>

                            <div class="grid-5 mt-2">
                                <div>
                                    <label class="form-label">Project</label>
                                    <input class="form-control" name="project_name"
                                        value="<?php echo e($defaultProject); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Client</label>
                                    <input class="form-control" name="client_name"
                                        value="<?php echo e($defaultClient); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Architect</label>
                                    <input class="form-control" name="architect_name"
                                        placeholder="Enter architect name">
                                </div>
                                <div>
                                    <label class="form-label">PMC</label>
                                    <input class="form-control" name="pmc_name" placeholder="Enter PMC name">
                                </div>
                                <div>
                                    <label class="form-label">Prepared By</label>
                                    <input class="form-control" value="<?php echo e($preparedBy); ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- DELAY ENTRIES -->
                        <div class="panel">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <div class="sec-head mb-0" style="flex:1;">
                                    <div class="sec-ic"><i class="bi bi-list-check"></i></div>
                                    <div>
                                        <p class="sec-title mb-0">Delay Entries</p>
                                        <p class="sec-sub mb-0">Add project delay details row by row</p>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-addrow" id="addRowBtn">
                                    <i class="bi bi-plus-circle"></i> Add More
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width:70px;">SL NO</th>
                                            <th>DELAYED TASK</th>
                                            <th style="width:150px;">PLANNED DATE</th>
                                            <th style="width:150px;">ACTUAL DATE</th>
                                            <th style="width:110px;">DELAY DAYS</th>
                                            <th style="width:150px;">RESPONSE BY</th>
                                            <th style="width:150px;">ISSUES OPENED ON</th>
                                            <th style="width:220px;">REMINDERS / FOLLOW UPS</th>
                                            <th style="width:150px;">ISSUES CLOSED ON</th>
                                            <th style="width:70px;">DEL</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dlarBody">
                                        <tr>
                                            <td><input class="form-control text-center sl-no" name="sl_no[]" value="1"
                                                    readonly></td>
                                            <td><textarea class="form-control" name="delayed_task[]"
                                                    rows="2"></textarea></td>
                                            <td><input type="date" class="form-control planned-date"
                                                    name="planned_date[]"></td>
                                            <td><input type="date" class="form-control actual-date"
                                                    name="actual_date[]"></td>
                                            <td><input class="form-control delay-days text-center" name="delay_days[]"
                                                    readonly></td>
                                            <td><input class="form-control" name="delay_response_by[]"></td>
                                            <td><input type="date" class="form-control" name="issues_opened_on[]"></td>
                                            <td><input class="form-control" name="reminders_dated[]"></td>
                                            <td><input type="date" class="form-control" name="issues_closed_on[]"></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-danger delRow">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- SUBMIT -->
                        <div class="panel">
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn-primary-tek" <?php echo ($formSiteId <= 0 ? 'disabled' : ''); ?>>
                                    <i class="bi bi-check2-circle"></i> Submit DLAR
                                </button>
                            </div>

                            <?php if ($formSiteId <= 0): ?>
                                <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to
                                    enable submit.</div>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- RECENT DLAR -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                            <div>
                                <p class="sec-title mb-0">Recent DLAR</p>
                                <p class="sec-sub mb-0">Your last submissions</p>
                            </div>
                        </div>

                        <?php if (empty($recent)): ?>
                            <div class="text-muted" style="font-weight:800;">No DLAR submitted yet.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>DLAR No</th>
                                            <th>Date</th>
                                            <th>Month</th>
                                            <th>Project</th>
                                            <th style="width:120px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent as $r): ?>
                                            <tr>
                                                <td style="font-weight:1000;"><?php echo e($r['dlar_no']); ?></td>
                                                <td><?php echo e($r['report_date']); ?></td>
                                                <td><?php echo e(monthName((int) $r['report_month'])); ?>
                                                    <?php echo (int) $r['report_year']; ?></td>
                                                <td><?php echo e($r['project_name']); ?></td>
                                                <td class="text-center">
                                                    <a href="report-dlar-print.php?view=<?php echo (int) $r['id']; ?>"
                                                        target="_blank" class="btn btn-sm btn-outline-primary"
                                                        style="border-radius:10px; font-weight:800;">
                                                        <i class="bi bi-printer"></i> Print
                                                    </a>
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
            const sitePicker = document.getElementById('sitePicker');
            const monthPicker = document.getElementById('monthPicker');
            const yearPicker = document.getElementById('yearPicker');

            function reloadPage() {
                const sid = sitePicker ? (sitePicker.value || '') : '';
                const m = monthPicker ? (monthPicker.value || '') : '';
                const y = yearPicker ? (yearPicker.value || '') : '';

                let url = 'dlar.php';
                const params = [];

                if (sid) params.push('site_id=' + encodeURIComponent(sid));
                if (m) params.push('m=' + encodeURIComponent(m));
                if (y) params.push('y=' + encodeURIComponent(y));

                if (params.length) {
                    url += '?' + params.join('&');
                }

                window.location.href = url;
            }

            if (sitePicker) sitePicker.addEventListener('change', reloadPage);
            if (monthPicker) monthPicker.addEventListener('change', reloadPage);
            if (yearPicker) yearPicker.addEventListener('change', reloadPage);

            const dlarBody = document.getElementById('dlarBody');
            const addRowBtn = document.getElementById('addRowBtn');

            function renumberRows() {
                const rows = dlarBody.querySelectorAll('tr');
                rows.forEach((row, idx) => {
                    const sl = row.querySelector('.sl-no');
                    if (sl) sl.value = idx + 1;
                });
            }

            function calculateDelay(row) {
                const planned = row.querySelector('.planned-date')?.value || '';
                const actual = row.querySelector('.actual-date')?.value || '';
                const delayEl = row.querySelector('.delay-days');

                if (!planned || !actual) {
                    if (delayEl) delayEl.value = '';
                    return;
                }

                const p = new Date(planned + 'T00:00:00');
                const a = new Date(actual + 'T00:00:00');

                if (isNaN(p.getTime()) || isNaN(a.getTime())) {
                    if (delayEl) delayEl.value = '';
                    return;
                }

                const diff = Math.round((a - p) / (1000 * 60 * 60 * 24));
                delayEl.value = diff > 0 ? diff : 0;
            }

            function bindRow(row) {
                const planned = row.querySelector('.planned-date');
                const actual = row.querySelector('.actual-date');

                if (planned) planned.addEventListener('change', () => calculateDelay(row));
                if (actual) actual.addEventListener('change', () => calculateDelay(row));
            }

            function addRow() {
                const nextNo = dlarBody.querySelectorAll('tr').length + 1;

                const tr = document.createElement('tr');
                tr.innerHTML = `
            <td><input class="form-control text-center sl-no" name="sl_no[]" value="${nextNo}" readonly></td>
            <td><textarea class="form-control" name="delayed_task[]" rows="2"></textarea></td>
            <td><input type="date" class="form-control planned-date" name="planned_date[]"></td>
            <td><input type="date" class="form-control actual-date" name="actual_date[]"></td>
            <td><input class="form-control delay-days text-center" name="delay_days[]" readonly></td>
            <td><input class="form-control" name="delay_response_by[]"></td>
            <td><input type="date" class="form-control" name="issues_opened_on[]"></td>
            <td><input class="form-control" name="reminders_dated[]"></td>
            <td><input type="date" class="form-control" name="issues_closed_on[]"></td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger delRow">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
                dlarBody.appendChild(tr);
                bindRow(tr);
            }

            addRowBtn?.addEventListener('click', addRow);

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.delRow');
                if (!btn) return;

                const tr = btn.closest('tr');
                if (!tr) return;

                if (dlarBody.querySelectorAll('tr').length <= 1) {
                    tr.querySelectorAll('textarea, input:not(.sl-no)').forEach(el => el.value = '');
                } else {
                    tr.remove();
                }

                renumberRows();
            });

            dlarBody.querySelectorAll('tr').forEach(bindRow);
        });
    </script>
</body>

</html>