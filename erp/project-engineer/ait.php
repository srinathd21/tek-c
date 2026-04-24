<?php
// ait.php — Action Item Tracker (AIT) Submission Form with Dynamic Rows

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
$site = null;
$clientId = 0;

// If no site selected but sites exist, use the first one
if ($siteId <= 0 && !empty($sites)) {
    $siteId = (int)$sites[0]['id'];
}

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

// ---------------- Generate AIT Number ----------------
function generateAitNo($conn, $siteId) {
    $year = date('Y');
    $month = date('m');
    $prefix = "AIT/{$siteId}/{$year}{$month}/";
    
    $st = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM ait_main WHERE ait_no LIKE ?");
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
if (isset($_GET['saved']) && $_GET['saved'] === '1' && isset($_GET['aid'])) {
    $lastInsertedId = (int)$_GET['aid'];
}

// ---------------- Submit Action Item ----------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ait'])) {
    $site_id = (int)($_POST['site_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $project_name = trim((string)($_POST['project_name'] ?? ''));
    $client_name = trim((string)($_POST['client_name'] ?? ''));
    $architects = trim((string)($_POST['architects'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $revisions = trim((string)($_POST['revisions'] ?? ''));
    $ait_date = trim((string)($_POST['ait_date'] ?? date('Y-m-d')));
    
    // Get rows data
    $sl_nos = $_POST['sl_no'] ?? [];
    $dateds = $_POST['dated'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $priorities = $_POST['priority'] ?? [];
    $responsible_bys = $_POST['responsible_by'] ?? [];
    $due_dates = $_POST['due_date'] ?? [];
    $completion_dates = $_POST['completion_date'] ?? [];
    $progress_notes = $_POST['progress_notes'] ?? [];
    $statuses = $_POST['status'] ?? [];
    
    // Validate at least one row with data
    $hasValidRow = false;
    foreach ($descriptions as $idx => $desc) {
        if (trim($desc) !== '') {
            $hasValidRow = true;
            break;
        }
    }
    
    if ($site_id <= 0) $error = "Please select a site.";
    if (!$hasValidRow) $error = "Please enter at least one action item.";
    
    // Get employee name
    $empRow = null;
    $st = mysqli_prepare($conn, "SELECT full_name FROM employees WHERE id=? LIMIT 1");
    if ($st) {
        mysqli_stmt_bind_param($st, "i", $employeeId);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $empRow = mysqli_fetch_assoc($res);
        mysqli_stmt_close($st);
    }
    $createdByName = $empRow['full_name'] ?? $_SESSION['employee_name'] ?? '';
    
    if ($error === '') {
        // Generate AIT Number
        $ait_no = generateAitNo($conn, $site_id);
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert into main table
            $insMain = mysqli_prepare($conn, "
                INSERT INTO ait_main 
                (ait_no, site_id, client_id, project_name, client_name, architects, pmc, revisions, ait_date, created_by, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            mysqli_stmt_bind_param($insMain, "siissssssis", 
                $ait_no, $site_id, $client_id, $project_name, $client_name, 
                $architects, $pmc, $revisions, $ait_date, $employeeId, $createdByName
            );
            
            if (!mysqli_stmt_execute($insMain)) {
                throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
            }
            
            $mainId = mysqli_insert_id($conn);
            mysqli_stmt_close($insMain);
            
            // Insert into details table for each row
            $insDetail = mysqli_prepare($conn, "
                INSERT INTO ait_details 
                (ait_main_id, sl_no, dated, description, priority, responsible_by, due_date, completion_date, progress_notes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $rowCount = 0;
            foreach ($descriptions as $idx => $desc) {
                if (trim($desc) === '') continue;
                
                $sl_no = (int)($sl_nos[$idx] ?? ($idx + 1));
                $dated = trim($dateds[$idx] ?? date('Y-m-d'));
                $priority = trim($priorities[$idx] ?? 'MEDIUM');
                $responsible_by = trim($responsible_bys[$idx] ?? '');
                $due_date = trim($due_dates[$idx] ?? '');
                $completion_date = trim($completion_dates[$idx] ?? '');
                $progress_note = trim($progress_notes[$idx] ?? '');
                $status = trim($statuses[$idx] ?? 'OPEN');
                
                if ($completion_date === '') $completion_date = null;
                
                mysqli_stmt_bind_param($insDetail, "iissssssss",
                    $mainId, $sl_no, $dated, $desc, $priority, $responsible_by,
                    $due_date, $completion_date, $progress_note, $status
                );
                
                if (!mysqli_stmt_execute($insDetail)) {
                    throw new Exception("Failed to save detail row: " . mysqli_stmt_error($insDetail));
                }
                $rowCount++;
            }
            mysqli_stmt_close($insDetail);
            
            mysqli_commit($conn);
            
            header("Location: ait.php?site_id=" . $site_id . "&saved=1&aid=" . $mainId);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// ---------------- Get Recent AIT Items ----------------
$recent = [];
$st = mysqli_prepare($conn, "
    SELECT m.id, m.ait_no, m.ait_date, m.project_name, COUNT(d.id) as item_count
    FROM ait_main m
    LEFT JOIN ait_details d ON d.ait_main_id = m.id
    WHERE m.created_by = ?
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
$formAitDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>AIT - Action Item Tracker | TEK-C</title>

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
        
        .priority-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }
        .priority-HIGH { background: #fee2e2; color: #dc2626; }
        .priority-URGENT { background: #fef3c7; color: #d97706; }
        .priority-MEDIUM { background: #dbeafe; color: #2563eb; }
        .priority-LOW { background: #dcfce7; color: #16a34a; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }
        .status-OPEN { background: #fef3c7; color: #d97706; }
        .status-IN-PROGRESS { background: #dbeafe; color: #2563eb; }
        .status-COMPLETED { background: #dcfce7; color: #16a34a; }
        .status-BLOCKED { background: #fee2e2; color: #dc2626; }
        .status-CANCELLED { background: #f3f4f6; color: #6b7280; }

        .table-ait thead th {
            font-size: 12px;
            color: #6b7280;
            font-weight: 900;
            background: #f9fafb;
            white-space: nowrap;
        }
        .table-ait td { vertical-align: middle; }
        
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
                        <h1 class="h-title">Action Item Tracker (AIT)</h1>
                        <p class="h-sub">Create and manage action items for your projects</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($_SESSION['employee_name'] ?? ''); ?></span>
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
                        <i class="bi bi-check-circle-fill me-2"></i> AIT submitted successfully!
                        <a href="report-ait-print.php?view=<?php echo $lastInsertedId; ?>" target="_blank" class="alert-link ms-3">
                            <i class="bi bi-printer"></i> Print/View PDF
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- AIT FORM -->
                <form method="POST" autocomplete="off" id="aitForm">
                    <input type="hidden" name="submit_ait" value="1">
                    <input type="hidden" name="site_id" id="site_id" value="<?php echo (int)$formSiteId; ?>">
                    <input type="hidden" name="client_id" id="client_id" value="<?php echo (int)$formClientId; ?>">
                    <input type="hidden" name="project_name" id="project_name" value="<?php echo e($formProjectName); ?>">
                    <input type="hidden" name="client_name" id="client_name" value="<?php echo e($formClientName); ?>">

                    <!-- PROJECT INFO HEADER -->
                    <div class="panel">
                        <!-- Site Selection Dropdown (No Auto-Trigger) -->
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

                        <div class="grid-4">
                            <div>
                                <label class="form-label">Project</label>
                                <input type="text" class="form-control" id="display_project" value="<?php echo e($formProjectName); ?>" readonly style="background:#f9fafb;">
                            </div>
                            <div>
                                <label class="form-label">Client</label>
                                <input type="text" class="form-control" id="display_client" value="<?php echo e($formClientName); ?>" readonly style="background:#f9fafb;">
                            </div>
                            <div>
                                <label class="form-label">Architects</label>
                                <input type="text" class="form-control" name="architects" placeholder="Architect firm name">
                            </div>
                            <div>
                                <label class="form-label">PMC</label>
                                <input type="text" class="form-control" name="pmc" placeholder="PMC name">
                            </div>
                        </div>
                        <div class="grid-2 mt-3">
                            <div>
                                <label class="form-label">Revisions/Dated</label>
                                <input type="text" class="form-control" name="revisions" placeholder="Revision reference">
                            </div>
                            <div>
                                <label class="form-label">AIT Date</label>
                                <input type="date" class="form-control" name="ait_date" value="<?php echo e($formAitDate); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- ACTION ITEMS TABLE (Dynamic Rows) -->
                    <div class="panel">
                        <div class="table-responsive">
                            <table class="table table-bordered table-ait" id="aitTable">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">SL NO</th>
                                        <th style="width:100px;">DATED</th>
                                        <th style="min-width:250px;">DESCRIPTION</th>
                                        <th style="width:100px;">PRIORITY</th>
                                        <th style="width:140px;">RESPONSIBLE BY</th>
                                        <th style="width:100px;">DUE DATE</th>
                                        <th style="width:100px;">COMPLETION DATE</th>
                                        <th style="min-width:180px;">PROGRESS NOTES</th>
                                        <th style="width:100px;">STATUS</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="aitTableBody">
                                    <tr class="ait-row">
                                        <td><input type="number" class="form-control sl-no" name="sl_no[]" value="1" readonly style="background:#f9fafb;"></td>
                                        <td><input type="date" class="form-control" name="dated[]" value="<?php echo date('Y-m-d'); ?>"></td>
                                        <td><textarea class="form-control" name="description[]" rows="2" placeholder="Describe the action item..."></textarea></td>
                                        <td>
                                            <select class="form-select" name="priority[]">
                                                <option value="LOW">LOW</option>
                                                <option value="MEDIUM" selected>MEDIUM</option>
                                                <option value="HIGH">HIGH</option>
                                                <option value="URGENT">URGENT</option>
                                            </select>
                                        </td>
                                        <td><input type="text" class="form-control" name="responsible_by[]" placeholder="Person/Team"></td>
                                        <td><input type="date" class="form-control" name="due_date[]" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>"></td>
                                        <td><input type="date" class="form-control" name="completion_date[]" value=""></td>
                                        <td><textarea class="form-control" name="progress_notes[]" rows="2" placeholder="Progress notes..."></textarea></td>
                                        <td>
                                            <select class="form-select" name="status[]">
                                                <option value="OPEN" selected>OPEN</option>
                                                <option value="IN PROGRESS">IN PROGRESS</option>
                                                <option value="COMPLETED">COMPLETED</option>
                                                <option value="BLOCKED">BLOCKED</option>
                                                <option value="CANCELLED">CANCELLED</option>
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px;"></i>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                            <div class="small-muted">
                                <i class="bi bi-info-circle"></i> At least one row must be filled.
                            </div>
                            <button type="button" class="btn btn-primary" id="addRowBtn" style="border-radius:12px;">
                                <i class="bi bi-plus-circle"></i> Add Row
                            </button>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-save"></i> Submit AIT
                            </button>
                        </div>
                    </div>
                </form>

                <!-- RECENT AIT SUBMISSIONS -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <p class="sec-title mb-0">Recent AIT Submissions</p>
                            <p class="sec-sub mb-0">Your last 10 submissions</p>
                        </div>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="text-muted" style="font-weight:800;">No AIT submitted yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>AIT No</th>
                                        <th>Date</th>
                                        <th>Project</th>
                                        <th>Items</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><?php echo e($r['ait_no']); ?></td>
                                            <td><?php echo e($r['ait_date']); ?></td>
                                            <td><?php echo e($r['project_name']); ?></td>
                                            <td class="text-center"><?php echo $r['item_count']; ?></td>
                                            <td>
                                                <a href="report-ait-print.php?view=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
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
    // Site Picker - Update hidden fields and display fields without page reload
    var picker = document.getElementById('sitePicker');
    if (picker) {
        picker.addEventListener('change', function(){
            var selected = picker.options[picker.selectedIndex];
            var siteId = picker.value || '';
            var projectName = selected.getAttribute('data-project') || '';
            var clientName = selected.getAttribute('data-client') || '';
            var clientId = selected.getAttribute('data-client-id') || '';
            
            if (siteId) {
                // Update hidden fields
                document.getElementById('site_id').value = siteId;
                document.getElementById('client_id').value = clientId;
                document.getElementById('project_name').value = projectName;
                document.getElementById('client_name').value = clientName;
                
                // Update display fields
                document.getElementById('display_project').value = projectName;
                document.getElementById('display_client').value = clientName;
                
                // Update URL without page reload (optional - for bookmarking)
                var newUrl = window.location.pathname + '?site_id=' + encodeURIComponent(siteId);
                window.history.pushState({path: newUrl}, '', newUrl);
            }
        });
    }
    
    // Add Row
    function renumberRows() {
        document.querySelectorAll('#aitTableBody .ait-row').forEach(function(row, idx){
            var slInput = row.querySelector('.sl-no');
            if (slInput) slInput.value = idx + 1;
        });
    }
    
    function addRow() {
        const tbody = document.getElementById('aitTableBody');
        const originalRow = document.querySelector('#aitTableBody .ait-row');
        if (!originalRow) return;
        
        const newRow = originalRow.cloneNode(true);
        
        // Clear all input values in the new row
        newRow.querySelectorAll('input, textarea, select').forEach(function(field){
            if (field.classList && field.classList.contains('sl-no')) {
                // Keep SL NO - will be renumbered
            } else if (field.type === 'date') {
                field.value = '';
            } else if (field.type === 'text' || field.tagName === 'TEXTAREA') {
                field.value = '';
            } else if (field.tagName === 'SELECT') {
                // Reset to default values
                if (field.name === 'priority[]') {
                    field.value = 'MEDIUM';
                } else if (field.name === 'status[]') {
                    field.value = 'OPEN';
                }
            }
        });
        
        tbody.appendChild(newRow);
        renumberRows();
    }
    
    // Delete Row (Event Delegation)
    document.getElementById('aitTableBody')?.addEventListener('click', function(e){
        const deleteBtn = e.target.closest('.delete-row-btn');
        if (!deleteBtn) return;
        
        const row = deleteBtn.closest('.ait-row');
        const tbody = row.parentNode;
        const rows = tbody.querySelectorAll('.ait-row');
        
        if (rows.length <= 1) {
            // Clear all fields instead of deleting last row
            row.querySelectorAll('input, textarea, select').forEach(function(field){
                if (field.classList && field.classList.contains('sl-no')) {
                    field.value = 1;
                } else if (field.type === 'date') {
                    field.value = '';
                } else if (field.type === 'text' || field.tagName === 'TEXTAREA') {
                    field.value = '';
                } else if (field.tagName === 'SELECT') {
                    if (field.name === 'priority[]') field.value = 'MEDIUM';
                    if (field.name === 'status[]') field.value = 'OPEN';
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