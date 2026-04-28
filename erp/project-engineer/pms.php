<?php
// pms.php - Project Master Schedule (PMS) Submission Form with Dynamic Rows

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
$employeeName = $_SESSION['employee_name'] ?? '';

$allowed = [
    'project engineer grade 1', 'project engineer grade 2', 'sr. engineer',
    'team lead', 'manager', 'hr', 'director'
];
if (!in_array($designation, $allowed, true)) {
    header("Location: index.php");
    exit;
}

// ---------------- HELPERS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------------- Get Assigned Sites ----------------
$sites = [];
if ($designation === 'manager') {
    $q = "SELECT s.id, s.project_name, s.project_location, c.client_name, c.id as client_id
          FROM sites s
          INNER JOIN clients c ON c.id = s.client_id
          WHERE s.manager_employee_id = ?
          ORDER BY s.created_at DESC";
    $st = mysqli_prepare($conn, $q);
    if ($st) {
        mysqli_stmt_bind_param($st, "i", $employeeId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
        mysqli_stmt_close($st);
    }
} else {
    $q = "SELECT s.id, s.project_name, s.project_location, c.client_name, c.id as client_id
          FROM site_project_engineers spe
          INNER JOIN sites s ON s.id = spe.site_id
          INNER JOIN clients c ON c.id = s.client_id
          WHERE spe.employee_id = ?
          ORDER BY s.created_at DESC";
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
$siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;

if ($siteId <= 0 && !empty($sites)) {
    $siteId = (int)$sites[0]['id'];
}

$site = null;
$clientId = 0;
if ($siteId > 0) {
    $isAllowedSite = false;
    foreach ($sites as $s) {
        if ((int)$s['id'] === $siteId) { $isAllowedSite = true; break; }
    }

    if ($isAllowedSite) {
        $sql = "SELECT s.id, s.project_name, s.client_id, c.client_name, s.project_location
                FROM sites s
                INNER JOIN clients c ON c.id = s.client_id
                WHERE s.id = ? LIMIT 1";
        $st = mysqli_prepare($conn, $sql);
        if ($st) {
            mysqli_stmt_bind_param($st, "i", $siteId);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            $site = mysqli_fetch_assoc($res);
            mysqli_stmt_close($st);
            if ($site) {
                $clientId = (int)$site['client_id'];
            }
        }
    }
}

// ---------------- Generate PMS Number ----------------
function generatePmsNo($conn, $siteId) {
    $year = date('Y');
    $month = date('m');
    $prefix = "PMS/{$siteId}/{$year}{$month}/";
    
    $st = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM pms_main WHERE pms_no LIKE ?");
    $likePattern = $prefix . '%';
    if ($st) {
        mysqli_stmt_bind_param($st, "s", $likePattern);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($st);
        $nextNum = ((int)($row['cnt'] ?? 0)) + 1;
        return $prefix . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    }
    return $prefix . '001';
}

// ---------------- Get Last Inserted ID ----------------
$lastInsertedId = null;
if (isset($_GET['saved']) && $_GET['saved'] === '1' && isset($_GET['pid'])) {
    $lastInsertedId = (int)$_GET['pid'];
}

// ---------------- Create PMS Tables if not exists ----------------
function createPmsTables($conn) {
    // Main table
    $mainTable = "CREATE TABLE IF NOT EXISTS `pms_main` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pms_no` varchar(100) NOT NULL,
        `site_id` int(11) NOT NULL,
        `client_id` int(11) NOT NULL,
        `project_name` varchar(255) NOT NULL,
        `client_name` varchar(255) NOT NULL,
        `architect` varchar(255) DEFAULT NULL,
        `pmc` varchar(255) DEFAULT NULL,
        `version` varchar(100) DEFAULT NULL,
        `pms_date` date NOT NULL,
        `prepared_by` int(11) NOT NULL,
        `prepared_by_name` varchar(150) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_pms_no` (`pms_no`),
        KEY `idx_site` (`site_id`),
        KEY `idx_client` (`client_id`),
        KEY `idx_prepared_by` (`prepared_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $mainTable);
    
    // Details table
    $detailsTable = "CREATE TABLE IF NOT EXISTS `pms_details` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pms_main_id` int(11) NOT NULL,
        `sl_no` int(11) NOT NULL,
        `task_activity` text NOT NULL,
        `duration_days` int(11) NOT NULL,
        `date_start` date NOT NULL,
        `date_end` date NOT NULL,
        `remark` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_pms_main` (`pms_main_id`),
        KEY `idx_sl_no` (`sl_no`),
        CONSTRAINT `fk_pms_details_main` FOREIGN KEY (`pms_main_id`) REFERENCES `pms_main` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $detailsTable);
}

createPmsTables($conn);

// ---------------- Submit Project Master Schedule ----------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pms'])) {
    $site_id = (int)($_POST['site_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $project_name = trim((string)($_POST['project_name'] ?? ''));
    $client_name = trim((string)($_POST['client_name'] ?? ''));
    $architect = trim($_POST['architect'] ?? '');
    $pmc = trim($_POST['pmc'] ?? '');
    $version = trim($_POST['version'] ?? '');
    $pms_date = trim($_POST['pms_date'] ?? date('Y-m-d'));
    
    // Get rows data
    $sl_nos = $_POST['sl_no'] ?? [];
    $task_activities = $_POST['task_activity'] ?? [];
    $duration_days = $_POST['duration_days'] ?? [];
    $date_starts = $_POST['date_start'] ?? [];
    $date_ends = $_POST['date_end'] ?? [];
    $remarks = $_POST['remark'] ?? [];
    
    // Validate at least one row with task activity
    $hasValidRow = false;
    foreach ($task_activities as $idx => $task) {
        if (trim($task) !== '') {
            $hasValidRow = true;
            break;
        }
    }
    
    if ($site_id <= 0) $error = "Please select a site.";
    if (!$hasValidRow) $error = "Please enter at least one task/activity.";
    
    if ($error === '') {
        // Generate PMS Number
        $pms_no = generatePmsNo($conn, $site_id);
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert into main table
            $insMain = mysqli_prepare($conn, "
                INSERT INTO pms_main 
                (pms_no, site_id, client_id, project_name, client_name, architect, pmc, version, pms_date, prepared_by, prepared_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            mysqli_stmt_bind_param($insMain, "siissssssis",
                $pms_no, $site_id, $client_id,
                $project_name, $client_name,
                $architect, $pmc, $version, $pms_date,
                $employeeId, $employeeName
            );
            if (!mysqli_stmt_execute($insMain)) {
                throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
            }
            
            $mainId = mysqli_insert_id($conn);
            mysqli_stmt_close($insMain);
            
            // Insert into details table for each task
            $insDetail = mysqli_prepare($conn, "
                INSERT INTO pms_details 
                (pms_main_id, sl_no, task_activity, duration_days, date_start, date_end, remark)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $rowCount = 0;
            foreach ($task_activities as $idx => $task) {
                if (trim($task) === '') continue;
                
                $sl_no = (int)($sl_nos[$idx] ?? ($idx + 1));
                $duration = (int)($duration_days[$idx] ?? 0);
                $date_start = trim($date_starts[$idx] ?? '');
                $date_end = trim($date_ends[$idx] ?? '');
                $remark = trim($remarks[$idx] ?? '');
                
                // Auto-calculate end date if not provided
                if (empty($date_end) && !empty($date_start) && $duration > 0) {
                    $date_end = date('Y-m-d', strtotime($date_start . ' + ' . ($duration - 1) . ' days'));
                }
                
                mysqli_stmt_bind_param($insDetail, "iisisss", $mainId, $sl_no, $task, $duration, $date_start, $date_end, $remark);
                
                if (!mysqli_stmt_execute($insDetail)) {
                    throw new Exception("Failed to save detail row: " . mysqli_stmt_error($insDetail));
                }
                $rowCount++;
            }
            mysqli_stmt_close($insDetail);
            
            mysqli_commit($conn);
            
            header("Location: pms.php?site_id=" . $site_id . "&saved=1&pid=" . $mainId);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// ---------------- Get Recent PMS Records ----------------
$recent = [];
$st = mysqli_prepare($conn, "
    SELECT m.id, m.pms_no, m.pms_date, m.project_name, COUNT(d.id) as task_count
    FROM pms_main m
    LEFT JOIN pms_details d ON d.pms_main_id = m.id
    WHERE m.prepared_by = ?
    GROUP BY m.id
    ORDER BY m.created_at DESC
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
$formClientId = $clientId;
$formProjectName = $site ? $site['project_name'] : '';
$formClientName = $site ? $site['client_name'] : '';
$formPmsDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>PMS - Project Master Schedule | TEK-C</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(17,24,39,.05);
            padding:20px;
            margin-bottom:20px;
        }
        .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .h-title{ margin:0; font-weight:1000; color:#111827; }
        .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

        .form-label{ font-weight:900; color:#374151; font-size:13px; margin-bottom:6px; }
        .form-control, .form-select{
            border:2px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 12px;
            font-weight: 750;
            font-size: 14px;
        }
        textarea.form-control { resize: vertical; }

        .sec-head{
            display:flex; align-items:center; gap:10px;
            padding: 12px 16px;
            border-radius: 14px;
            background:#f9fafb;
            border:1px solid #eef2f7;
            margin-bottom:16px;
        }
        .sec-ic{
            width:38px;height:38px;border-radius: 12px;
            display:grid;place-items:center;
            background: rgba(45,156,219,.12);
            color: var(--blue);
            flex:0 0 auto;
        }
        .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:15px; }
        .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

        .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
        .grid-3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px; }
        .grid-4{ display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:16px; }
        @media (max-width: 992px){
            .grid-2, .grid-3, .grid-4{ grid-template-columns: 1fr; }
        }

        .badge-pill{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 12px; border-radius:999px;
            border:1px solid #e5e7eb; background:#fff;
            font-weight:900; font-size:12px;
        }
        .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
        
        .table-pms thead th {
            font-size: 12px;
            color: #6b7280;
            font-weight: 900;
            background: #f9fafb;
            white-space: nowrap;
        }
        .table-pms td { vertical-align: middle; }
        
        .btn-primary-tek{
            background: var(--blue);
            border:none;
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 1000;
            display:inline-flex;
            align-items:center;
            gap:8px;
            box-shadow: 0 12px 26px rgba(45,156,219,.18);
            color:#fff;
        }
        .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }
        
        .delete-row-btn {
            cursor: pointer;
            color: #dc2626;
            transition: all 0.2s;
        }
        .delete-row-btn:hover { color: #b91c1c; }
        
        .site-selector {
            background: #f9fafb;
            border-radius: 12px;
            padding: 8px 12px;
            font-weight: 750;
            font-size: 14px;
            border: 2px solid #e5e7eb;
            width: 100%;
        }
        
        .duration-input {
            width: 100px;
        }
        
        .date-input {
            min-width: 140px;
        }
        
        .sl-no-input {
            width: 70px;
            text-align: center;
            background: #f9fafb;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .panel { padding: 12px !important; }
            .table-pms { font-size: 12px; }
            .date-input, .duration-input, .sl-no-input { min-width: auto; width: 100%; }
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
                        <h1 class="h-title">Project Master Schedule (PMS)</h1>
                        <p class="h-sub">Project schedule planning with milestones and timelines</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
                        <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($_SESSION['designation'] ?? ''); ?></span>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($lastInsertedId): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-check-circle-fill me-2"></i> PMS submitted successfully!
                        <a href="report-pms-print.php?view=<?php echo $lastInsertedId; ?>" target="_blank" class="alert-link ms-3">
                            <i class="bi bi-printer"></i> Print/View PDF
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- PMS FORM -->
                <form method="POST" autocomplete="off" id="pmsForm">
                    <input type="hidden" name="submit_pms" value="1">
                    <input type="hidden" name="site_id" id="site_id" value="<?php echo (int)$formSiteId; ?>">
                    <input type="hidden" name="client_id" id="client_id" value="<?php echo (int)$formClientId; ?>">
                    <input type="hidden" name="project_name" id="project_name" value="<?php echo e($formProjectName); ?>">
                    <input type="hidden" name="client_name" id="client_name" value="<?php echo e($formClientName); ?>">

                    <!-- PROJECT INFO HEADER -->
                    <div class="panel">
                        <!-- Site Selection Dropdown -->
                        <div class="mb-4">
                            <label class="form-label">My Assigned Sites <span class="text-danger">*</span></label>
                            <select class="site-selector" id="sitePicker">
                                <?php foreach ($sites as $s): ?>
                                    <?php $sid = (int)$s['id']; ?>
                                    <option value="<?php echo $sid; ?>" 
                                            data-project="<?php echo e($s['project_name']); ?>"
                                            data-client="<?php echo e($s['client_name']); ?>"
                                            data-client-id="<?php echo $s['client_id']; ?>"
                                            <?php echo ($sid === $formSiteId ? 'selected' : ''); ?>>
                                        <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?> (<?php echo e($s['client_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small-muted mt-1">
                                <i class="bi bi-info-circle"></i> Select a site to update project details below
                            </div>
                        </div>

                        <div class="grid-2">
                            <div>
                                <label class="form-label">Project</label>
                                <input type="text" class="form-control" id="display_project" value="<?php echo e($formProjectName); ?>" readonly style="background:#f9fafb;">
                            </div>
                            <div>
                                <label class="form-label">Client</label>
                                <input type="text" class="form-control" id="display_client" value="<?php echo e($formClientName); ?>" readonly style="background:#f9fafb;">
                            </div>
                        </div>
                        <div class="grid-2 mt-3">
                            <div>
                                <label class="form-label">Architect</label>
                                <input type="text" class="form-control" name="architect" placeholder="Enter architect name">
                            </div>
                            <div>
                                <label class="form-label">PMC</label>
                                <input type="text" class="form-control" name="pmc" placeholder="Enter PMC name">
                            </div>
                        </div>
                        <div class="grid-2 mt-3">
                            <div>
                                <label class="form-label">Version</label>
                                <input type="text" class="form-control" name="version" placeholder="e.g., V1.0, V2.0, Draft, Final">
                            </div>
                            <div>
                                <label class="form-label">PMS Date</label>
                                <input type="date" class="form-control" name="pms_date" value="<?php echo e($formPmsDate); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- SCHEDULE TABLE (Dynamic Rows) -->
                    <div class="panel">
                        <div class="table-responsive">
                            <table class="table table-bordered table-pms" id="pmsTable">
                                <thead>
                                    <tr>
                                        <th style="width:80px;">SL NO</th>
                                        <th style="min-width:250px;">TASK/ACTIVITY/MILESTONE</th>
                                        <th style="width:130px;">DURATION (DAYS)</th>
                                        <th style="width:150px;">DATE START</th>
                                        <th style="width:150px;">END</th>
                                        <th style="min-width:180px;">REMARK</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="pmsTableBody">
                                    <tr class="pms-row">
                                        <td>
                                            <input type="number" class="form-control sl-no-input" name="sl_no[]" value="1" readonly style="background:#f9fafb; text-align:center;">
                                         </td>
                                        <td>
                                            <input type="text" class="form-control task-activity" name="task_activity[]" placeholder="Enter task, activity or milestone">
                                         </td>
                                        <td>
                                            <input type="number" class="form-control duration-input" name="duration_days[]" placeholder="Days" min="0" value="0">
                                         </td>
                                        <td>
                                            <input type="date" class="form-control date-input date-start" name="date_start[]" placeholder="Start date">
                                         </td>
                                        <td>
                                            <input type="date" class="form-control date-input date-end" name="date_end[]" placeholder="End date">
                                         </td>
                                        <td>
                                            <input type="text" class="form-control" name="remark[]" placeholder="Any remarks">
                                         </td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px; cursor:pointer;"></i>
                                         </td>
                                     </tr>
                                </tbody>
                             </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                            <div class="small-muted">
                                <i class="bi bi-info-circle"></i> At least one task/activity must be filled. End date auto-calculates from start date + duration.
                            </div>
                            <button type="button" class="btn btn-primary" id="addRowBtn" style="border-radius:12px;">
                                <i class="bi bi-plus-circle"></i> Add Task
                            </button>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-save"></i> Submit PMS
                            </button>
                        </div>
                    </div>
                </form>

                <!-- RECENT PMS SUBMISSIONS -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <p class="sec-title mb-0">Recent PMS Submissions</p>
                            <p class="sec-sub mb-0">Your last 10 submissions</p>
                        </div>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="text-muted" style="font-weight:800;">No PMS submitted yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>PMS No</th>
                                        <th>Date</th>
                                        <th>Project</th>
                                        <th>Tasks</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><?php echo e($r['pms_no']); ?></td>
                                            <td><?php echo e($r['pms_date']); ?></td>
                                            <td><?php echo e($r['project_name']); ?></td>
                                            <td class="text-center"><?php echo $r['task_count']; ?></td>
                                            <td>
                                                <a href="report-pms-print.php?view=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
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
document.addEventListener('DOMContentLoaded', function(){
    
    // Function to renumber all rows
    function renumberRows() {
        var rows = document.querySelectorAll('#pmsTableBody .pms-row');
        for (var i = 0; i < rows.length; i++) {
            var slInput = rows[i].querySelector('.sl-no-input');
            if (slInput) {
                slInput.value = i + 1;
            }
        }
    }
    
    // Auto-calculate end date based on start date and duration
    function calculateEndDate(startDate, duration) {
        if (!startDate || duration <= 0) return '';
        var date = new Date(startDate);
        date.setDate(date.getDate() + parseInt(duration) - 1);
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }
    
    // Attach calculation listeners to a specific row
    function attachCalculationListeners(row) {
        var startInput = row.querySelector('.date-start');
        var durationInput = row.querySelector('.duration-input');
        var endInput = row.querySelector('.date-end');
        
        function updateEndDate() {
            if (startInput && durationInput && endInput) {
                var startVal = startInput.value;
                var durationVal = parseInt(durationInput.value);
                if (startVal && durationVal > 0) {
                    var calculatedEnd = calculateEndDate(startVal, durationVal);
                    endInput.value = calculatedEnd;
                }
            }
        }
        
        // Remove existing listeners and add new ones
        if (startInput) {
            startInput.removeEventListener('change', updateEndDate);
            startInput.addEventListener('change', updateEndDate);
        }
        if (durationInput) {
            durationInput.removeEventListener('input', updateEndDate);
            durationInput.addEventListener('input', updateEndDate);
        }
    }
    
    // Attach listeners to all rows
    function attachAllListeners() {
        var rows = document.querySelectorAll('#pmsTableBody .pms-row');
        for (var i = 0; i < rows.length; i++) {
            attachCalculationListeners(rows[i]);
        }
    }
    
    // Site Picker - Update hidden fields
    var picker = document.getElementById('sitePicker');
    if (picker) {
        picker.addEventListener('change', function(){
            var selected = picker.options[picker.selectedIndex];
            var siteId = picker.value || '';
            var projectName = selected.getAttribute('data-project') || '';
            var clientName = selected.getAttribute('data-client') || '';
            var clientId = selected.getAttribute('data-client-id') || '';
            
            if (siteId) {
                document.getElementById('site_id').value = siteId;
                document.getElementById('client_id').value = clientId;
                document.getElementById('project_name').value = projectName;
                document.getElementById('client_name').value = clientName;
                document.getElementById('display_project').value = projectName;
                document.getElementById('display_client').value = clientName;
                
                var newUrl = window.location.pathname + '?site_id=' + encodeURIComponent(siteId);
                window.history.pushState({path: newUrl}, '', newUrl);
            }
        });
    }
    
    // Add new row
    function addNewRow() {
        var tbody = document.getElementById('pmsTableBody');
        var originalRow = document.querySelector('#pmsTableBody .pms-row');
        if (!originalRow) return;
        
        // Clone the row
        var newRow = originalRow.cloneNode(true);
        
        // Clear all input values in the new row
        var inputs = newRow.querySelectorAll('input');
        for (var i = 0; i < inputs.length; i++) {
            var field = inputs[i];
            // Don't clear SL NO (will be renumbered)
            if (field.classList && field.classList.contains('sl-no-input')) {
                // Skip - will be renumbered
            } else if (field.type === 'date') {
                field.value = '';
            } else if (field.type === 'number') {
                field.value = '0';
            } else {
                field.value = '';
            }
        }
        
        // Append the new row
        tbody.appendChild(newRow);
        
        // Renumber all rows
        renumberRows();
        
        // Attach calculation listeners to the new row
        attachCalculationListeners(newRow);
    }
    
    // Delete row (Event Delegation)
    document.getElementById('pmsTableBody')?.addEventListener('click', function(e){
        var deleteBtn = e.target.closest('.delete-row-btn');
        if (!deleteBtn) return;
        
        var row = deleteBtn.closest('.pms-row');
        var tbody = row.parentNode;
        var rows = tbody.querySelectorAll('.pms-row');
        
        if (rows.length <= 1) {
            // Clear all fields instead of deleting last row
            var inputs = row.querySelectorAll('input');
            for (var i = 0; i < inputs.length; i++) {
                var field = inputs[i];
                if (field.classList && field.classList.contains('sl-no-input')) {
                    field.value = 1;
                } else if (field.type === 'date') {
                    field.value = '';
                } else if (field.type === 'number') {
                    field.value = '0';
                } else {
                    field.value = '';
                }
            }
        } else {
            row.remove();
            renumberRows();
        }
    });
    
    // Add row button click
    var addBtn = document.getElementById('addRowBtn');
    if (addBtn) {
        addBtn.addEventListener('click', addNewRow);
    }
    
    // Initialize - renumber rows and attach listeners
    renumberRows();
    attachAllListeners();
});
</script>

</body>
</html>