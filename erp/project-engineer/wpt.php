<?php
// wpt.php - Work Progress Tracker (WPT) Submission Form

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
    'team lead', 'manager', 'hr', 'director', 'qs manager', 'qs engineer'
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
          WHERE s.manager_employee_id = ? AND s.deleted_at IS NULL
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
          WHERE spe.employee_id = ? AND s.deleted_at IS NULL
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

// ---------------- Generate WPT Number ----------------
function generateWptNo($conn, $siteId) {
    $year = date('Y');
    $month = date('m');
    $prefix = "WPT/{$siteId}/{$year}{$month}/";
    
    $st = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM wpt_main WHERE wpt_no LIKE ?");
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
if (isset($_GET['saved']) && $_GET['saved'] === '1' && isset($_GET['wid'])) {
    $lastInsertedId = (int)$_GET['wid'];
}

// ---------------- Submit WPT Record ----------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_wpt'])) {
    $site_id = (int)($_POST['site_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $project_name = trim((string)($_POST['project_name'] ?? ''));
    $client_name = trim((string)($_POST['client_name'] ?? ''));
    $contractor = trim((string)($_POST['contractor'] ?? ''));
    $scope_of_work = trim((string)($_POST['scope_of_work'] ?? ''));
    $architect = trim((string)($_POST['architect'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $week_ends_on = trim((string)($_POST['week_ends_on'] ?? ''));
    
    // Get row data
    $task_names = $_POST['task_name'] ?? [];
    $durations = $_POST['duration'] ?? [];
    $start_dates = $_POST['start_date'] ?? [];
    $finish_dates = $_POST['finish_date'] ?? [];
    $schedule_work_done = $_POST['schedule_work_done'] ?? [];
    $actual_starts = $_POST['actual_start'] ?? [];
    $actual_finishes = $_POST['actual_finish'] ?? [];
    $actual_work_done = $_POST['actual_work_done'] ?? [];
    $prev_delays = $_POST['prev_delay'] ?? [];
    $present_delays = $_POST['present_delay'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    
    // Validate at least one row with task name
    $hasValidRow = false;
    foreach ($task_names as $idx => $name) {
        if (trim($name) !== '') {
            $hasValidRow = true;
            break;
        }
    }
    
    if ($site_id <= 0) $error = "Please select a site.";
    if (empty($week_ends_on)) $error = "Please select Week Ending Date.";
    if (!$hasValidRow) $error = "Please enter at least one task.";
    
    if ($error === '') {
        // Generate WPT Number
        $wpt_no = generateWptNo($conn, $site_id);
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert into main table
            $insMain = mysqli_prepare($conn, "
                INSERT INTO wpt_main 
                (wpt_no, site_id, client_id, project_name, client_name, contractor, scope_of_work,
                 architect, pmc, week_ends_on, created_by, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            mysqli_stmt_bind_param($insMain, "siisssssssis",
                $wpt_no, $site_id, $client_id,
                $project_name, $client_name,
                $contractor, $scope_of_work,
                $architect, $pmc, $week_ends_on,
                $employeeId, $employeeName
            );
            if (!mysqli_stmt_execute($insMain)) {
                throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
            }
            
            $mainId = mysqli_insert_id($conn);
            mysqli_stmt_close($insMain);
            
            // Insert into details table for each task
            $insDetail = mysqli_prepare($conn, "
                INSERT INTO wpt_details 
                (wpt_main_id, sl_no, task_name, duration, start_date, finish_date, schedule_work_done,
                 actual_start, actual_finish, actual_work_done, prev_delay, present_delay, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($task_names as $idx => $task_name) {
                if (trim($task_name) === '') continue;
                
                $sl_no = $idx + 1;
                $duration = trim($durations[$idx] ?? '');
                $start_date = !empty($start_dates[$idx]) ? $start_dates[$idx] : null;
                $finish_date = !empty($finish_dates[$idx]) ? $finish_dates[$idx] : null;
                $schedule_work_done = trim($schedule_work_done[$idx] ?? '');
                $actual_start = !empty($actual_starts[$idx]) ? $actual_starts[$idx] : null;
                $actual_finish = !empty($actual_finishes[$idx]) ? $actual_finishes[$idx] : null;
                $actual_work_done = trim($actual_work_done[$idx] ?? '');
                $prev_delay = trim($prev_delays[$idx] ?? '');
                $present_delay = trim($present_delays[$idx] ?? '');
                $remark = trim($remarks[$idx] ?? '');
                
                mysqli_stmt_bind_param($insDetail, "iisssssssssss",
                    $mainId, $sl_no, $task_name, $duration,
                    $start_date, $finish_date, $schedule_work_done,
                    $actual_start, $actual_finish, $actual_work_done,
                    $prev_delay, $present_delay, $remark
                );
                
                if (!mysqli_stmt_execute($insDetail)) {
                    throw new Exception("Failed to save detail row: " . mysqli_stmt_error($insDetail));
                }
            }
            mysqli_stmt_close($insDetail);
            
            mysqli_commit($conn);
            
            header("Location: wpt.php?site_id=" . $site_id . "&saved=1&wid=" . $mainId);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// ---------------- Get Recent WPT Records ----------------
$recent = [];
$st = mysqli_prepare($conn, "
    SELECT w.id, w.wpt_no, w.week_ends_on, w.project_name, COUNT(d.id) as task_count
    FROM wpt_main w
    LEFT JOIN wpt_details d ON d.wpt_main_id = w.id
    WHERE w.created_by = ?
    GROUP BY w.id
    ORDER BY w.created_at DESC
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
$formWeekEndsOn = date('Y-m-d', strtotime('this saturday'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>WPT - Work Progress Tracker | TEK-C</title>

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
        @media (max-width: 992px){
            .grid-2, .grid-3{ grid-template-columns: 1fr; }
        }

        .badge-pill{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 12px; border-radius:999px;
            border:1px solid #e5e7eb; background:#fff;
            font-weight:900; font-size:12px;
        }
        .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
        
        .table-wpt thead th {
            font-size: 11px;
            color: #6b7280;
            font-weight: 900;
            background: #f9fafb;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }
        .table-wpt td { vertical-align: middle; font-size: 12px; }
        .table-wpt input, .table-wpt textarea { font-size: 12px; padding: 4px 8px; }
        .table-wpt input[type="text"] { min-width: 80px; }
        
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
        
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .panel { padding: 12px !important; }
            .table-wpt { overflow-x: auto; display: block; }
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
                        <h1 class="h-title">Work Progress Tracker (WPT)</h1>
                        <p class="h-sub">Weekly Task Progress Monitoring Sheet</p>
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
                        <i class="bi bi-check-circle-fill me-2"></i> WPT submitted successfully!
                        <a href="report-wpt-print.php?view=<?php echo $lastInsertedId; ?>" target="_blank" class="alert-link ms-3">
                            <i class="bi bi-printer"></i> Print/View PDF
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- WPT FORM -->
                <form method="POST" autocomplete="off" id="wptForm">
                    <input type="hidden" name="submit_wpt" value="1">
                    <input type="hidden" name="site_id" id="site_id" value="<?php echo (int)$formSiteId; ?>">
                    <input type="hidden" name="client_id" id="client_id" value="<?php echo (int)$formClientId; ?>">
                    <input type="hidden" name="project_name" id="project_name" value="<?php echo e($formProjectName); ?>">
                    <input type="hidden" name="client_name" id="client_name" value="<?php echo e($formClientName); ?>">

                    <!-- PROJECT INFO HEADER -->
                    <div class="panel">
                        <div class="mb-4">
                            <label class="form-label">Select Site <span class="text-danger">*</span></label>
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
                                <i class="bi bi-info-circle"></i> Select site to auto-fill project & client details
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
                                <label class="form-label">Contractor</label>
                                <input type="text" class="form-control" name="contractor" id="contractor" placeholder="Enter contractor name">
                            </div>
                            <div>
                                <label class="form-label">Scope of Work</label>
                                <input type="text" class="form-control" name="scope_of_work" id="scope_of_work" placeholder="Enter scope of work">
                            </div>
                        </div>
                        <div class="grid-2 mt-3">
                            <div>
                                <label class="form-label">Architect</label>
                                <input type="text" class="form-control" name="architect" id="architect" placeholder="Enter architect name">
                            </div>
                            <div>
                                <label class="form-label">PMC</label>
                                <input type="text" class="form-control" name="pmc" id="pmc" placeholder="Enter PMC name">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">Week Ends On <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="week_ends_on" value="<?php echo e($formWeekEndsOn); ?>" required>
                        </div>
                    </div>

                    <!-- WPT TABLE -->
                    <div class="panel">
                        <div class="mb-3">
                            <p class="fw-bold mb-0"><i class="bi bi-table me-2"></i> Task Progress Details</p>
                            <small class="text-muted">Fill in task schedule and actual progress</small>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-wpt" id="wptTable">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">SL NO</th>
                                        <th style="min-width:200px;">TASK AS PER SCHEDULE</th>
                                        <th style="width:70px;">DURATION</th>
                                        <th style="width:90px;">START</th>
                                        <th style="width:90px;">FINISH</th>
                                        <th style="width:70px;">% WORK DONE</th>
                                        <th style="width:90px;">ACTUAL START</th>
                                        <th style="width:90px;">ACTUAL FINISH</th>
                                        <th style="width:70px;">% WORK DONE</th>
                                        <th style="width:70px;">DELAY PREVIOUS</th>
                                        <th style="width:70px;">DELAY PRESENT</th>
                                        <th style="min-width:150px;">REMARKS</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="wptTableBody">
                                    <tr class="wpt-row">
                                        <td><input type="text" class="form-control sl-no" name="sl_no[]" value="1" readonly style="background:#f9fafb; text-align:center; width:50px;"></td>
                                        <td><textarea class="form-control" name="task_name[]" rows="2" placeholder="Enter task description" style="width:200px;"></textarea></td>
                                        <td><input type="text" class="form-control" name="duration[]" placeholder="Days" style="width:70px;"></td>
                                        <td><input type="date" class="form-control" name="start_date[]" style="width:90px;"></td>
                                        <td><input type="date" class="form-control" name="finish_date[]" style="width:90px;"></td>
                                        <td><input type="text" class="form-control" name="schedule_work_done[]" placeholder="%" style="width:70px;"></td>
                                        <td><input type="date" class="form-control" name="actual_start[]" style="width:90px;"></td>
                                        <td><input type="date" class="form-control" name="actual_finish[]" style="width:90px;"></td>
                                        <td><input type="text" class="form-control" name="actual_work_done[]" placeholder="%" style="width:70px;"></td>
                                        <td><input type="text" class="form-control" name="prev_delay[]" placeholder="Days" style="width:70px;"></td>
                                        <td><input type="text" class="form-control" name="present_delay[]" placeholder="Days" style="width:70px;"></td>
                                        <td><textarea class="form-control" name="remarks[]" rows="2" placeholder="Remarks" style="width:150px;"></textarea></td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px; cursor:pointer;"></i>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                            <div class="small-muted">
                                <i class="bi bi-info-circle"></i> At least one task must be filled. Delay days should be numeric.
                            </div>
                            <button type="button" class="btn btn-primary" id="addRowBtn" style="border-radius:12px;">
                                <i class="bi bi-plus-circle"></i> Add Task Row
                            </button>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-save"></i> Submit WPT
                            </button>
                        </div>
                    </div>
                </form>

                <!-- RECENT WPT SUBMISSIONS -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <p class="sec-title mb-0">Recent WPT Submissions</p>
                            <p class="sec-sub mb-0">Your last 10 submissions</p>
                        </div>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="text-muted" style="font-weight:800;">No WPT submitted yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>WPT No</th>
                                        <th>Week Ending</th>
                                        <th>Project</th>
                                        <th>Tasks</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><?php echo e($r['wpt_no']); ?></td>
                                            <td><?php echo e($r['week_ends_on']); ?></td>
                                            <td><?php echo e($r['project_name']); ?></td>
                                            <td class="text-center"><?php echo $r['task_count']; ?></td>
                                            <td>
                                                <a href="report-wpt-print.php?view=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
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
    // Site Picker - Update hidden fields and display fields
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
    
    // Re-number rows
    function renumberRows() {
        document.querySelectorAll('#wptTableBody .wpt-row').forEach(function(row, idx){
            var slInput = row.querySelector('.sl-no');
            if (slInput) slInput.value = idx + 1;
        });
    }
    
    // Add Row
    function addRow() {
        const tbody = document.getElementById('wptTableBody');
        const originalRow = document.querySelector('#wptTableBody .wpt-row');
        if (!originalRow) return;
        
        const newRow = originalRow.cloneNode(true);
        
        // Clear all input values in the new row
        newRow.querySelectorAll('input, textarea').forEach(function(field){
            if (field.classList && field.classList.contains('sl-no')) {
                // Keep SL NO - will be renumbered
            } else {
                field.value = '';
            }
        });
        
        tbody.appendChild(newRow);
        renumberRows();
    }
    
    // Delete Row (Event Delegation)
    document.getElementById('wptTableBody')?.addEventListener('click', function(e){
        const deleteBtn = e.target.closest('.delete-row-btn');
        if (!deleteBtn) return;
        
        const row = deleteBtn.closest('.wpt-row');
        const tbody = row.parentNode;
        const rows = tbody.querySelectorAll('.wpt-row');
        
        if (rows.length <= 1) {
            // Clear all fields instead of deleting last row
            row.querySelectorAll('input, textarea').forEach(function(field){
                if (field.classList && field.classList.contains('sl-no')) {
                    field.value = 1;
                } else {
                    field.value = '';
                }
            });
        } else {
            row.remove();
            renumberRows();
        }
    });
    
    document.getElementById('addRowBtn')?.addEventListener('click', addRow);
});
</script>

</body>
</html>