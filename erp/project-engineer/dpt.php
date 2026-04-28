<?php
// dpt.php - Daily Progress Tracker (DPT) Submission Form with Dynamic Rows

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
$pmcName = '';
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

// ---------------- Get Last Inserted ID ----------------
$lastInsertedId = null;
if (isset($_GET['saved']) && $_GET['saved'] === '1' && isset($_GET['did'])) {
    $lastInsertedId = (int)$_GET['did'];
}

// ---------------- Generate DPT Number ----------------
function generateDptNo($conn, $siteId) {
    $year = date('Y');
    $month = date('m');
    $prefix = "DPT/{$siteId}/{$year}{$month}/";
    
    $st = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM dpt_main WHERE dpt_no LIKE ?");
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

// ---------------- Submit DPT ----------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dpt'])) {
    $site_id = (int)($_POST['site_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $project_name = trim((string)($_POST['project_name'] ?? ''));
    $client_name = trim((string)($_POST['client_name'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $dated = trim((string)($_POST['dated'] ?? date('Y-m-d')));
    
    // Get rows data
    $sl_nos = $_POST['sl_no'] ?? [];
    $list_of_works = $_POST['list_of_work'] ?? [];
    $scheduled_finishes = $_POST['scheduled_finish'] ?? [];
    $actual_targeted_finishes = $_POST['actual_targeted_finish'] ?? [];
    $statuses = $_POST['status'] ?? [];
    $remarks = $_POST['remark'] ?? [];
    
    // Validate at least one row with work description
    $hasValidRow = false;
    foreach ($list_of_works as $idx => $work) {
        if (trim($work) !== '') {
            $hasValidRow = true;
            break;
        }
    }
    
    if ($site_id <= 0) $error = "Please select a site.";
    if (!$hasValidRow) $error = "Please enter at least one pending work item.";
    
    if ($error === '') {
        // Generate DPT Number
        $dpt_no = generateDptNo($conn, $site_id);
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert into main table
            $insMain = mysqli_prepare($conn, "
                INSERT INTO dpt_main 
                (dpt_no, site_id, client_id, project_name, client_name, pmc, dated, created_by, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            mysqli_stmt_bind_param($insMain, "siissssis",
                $dpt_no, $site_id, $client_id,
                $project_name, $client_name, $pmc,
                $dated, $employeeId, $employeeName
            );
            if (!mysqli_stmt_execute($insMain)) {
                throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
            }
            
            $mainId = mysqli_insert_id($conn);
            mysqli_stmt_close($insMain);
            
            // Insert into details table for each work item
            $insDetail = mysqli_prepare($conn, "
                INSERT INTO dpt_details 
                (dpt_main_id, sl_no, list_of_work, scheduled_finish, actual_targeted_finish, status, remark)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($list_of_works as $idx => $work) {
                if (trim($work) === '') continue;
                
                $sl_no = (int)($sl_nos[$idx] ?? ($idx + 1));
                $scheduled_finish = !empty($scheduled_finishes[$idx]) ? $scheduled_finishes[$idx] : null;
                $actual_targeted_finish = !empty($actual_targeted_finishes[$idx]) ? $actual_targeted_finishes[$idx] : null;
                $status = trim($statuses[$idx] ?? 'ONTRACK');
                $remark = trim($remarks[$idx] ?? '');
                
                mysqli_stmt_bind_param($insDetail, "iisssss",
                    $mainId, $sl_no, $work, $scheduled_finish, $actual_targeted_finish, $status, $remark
                );
                
                if (!mysqli_stmt_execute($insDetail)) {
                    throw new Exception("Failed to save detail row: " . mysqli_stmt_error($insDetail));
                }
            }
            mysqli_stmt_close($insDetail);
            
            mysqli_commit($conn);
            
            header("Location: dpt.php?site_id=" . $site_id . "&saved=1&did=" . $mainId);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// ---------------- Get Recent DPT Records ----------------
$recent = [];
$st = mysqli_prepare($conn, "
    SELECT m.id, m.dpt_no, m.dated, m.project_name, COUNT(d.id) as item_count
    FROM dpt_main m
    LEFT JOIN dpt_details d ON d.dpt_main_id = m.id
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
$formPmc = ''; // PMC is entered manually by user
$formDated = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>DPT - Daily Progress Tracker | TEK-C</title>

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
        .grid-4{ display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:16px; }
        @media (max-width: 992px){
            .grid-2, .grid-4{ grid-template-columns: 1fr; }
        }

        .badge-pill{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 12px; border-radius:999px;
            border:1px solid #e5e7eb; background:#fff;
            font-weight:900; font-size:12px;
        }
        .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
        
        .table-dpt thead th {
            font-size: 12px;
            color: #6b7280;
            font-weight: 900;
            background: #f9fafb;
            white-space: nowrap;
        }
        .table-dpt td { vertical-align: middle; }
        
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
        
        .status-select {
            min-width: 110px;
        }
        .status-ontrack { background-color: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .status-delay { background-color: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .status-completed { background-color: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .panel { padding: 12px !important; }
            .table-responsive { font-size: 12px; }
            .status-select { min-width: 90px; font-size: 11px; padding: 4px 6px; }
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
                        <h1 class="h-title">Daily Progress Tracker (DPT)</h1>
                        <p class="h-sub">Track pending works with scheduled vs actual completion dates</p>
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
                        <i class="bi bi-check-circle-fill me-2"></i> DPT submitted successfully!
                        <a href="report-dpt-print.php?view=<?php echo $lastInsertedId; ?>" target="_blank" class="alert-link ms-3">
                            <i class="bi bi-printer"></i> Print/View PDF
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- DPT FORM -->
                <form method="POST" autocomplete="off" id="dptForm">
                    <input type="hidden" name="submit_dpt" value="1">
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
                                <label class="form-label">PMC</label>
                                <input type="text" class="form-control" name="pmc" id="pmc_input" value="<?php echo e($formPmc); ?>" placeholder="PMC Name">
                            </div>
                            <div>
                                <label class="form-label">Dated</label>
                                <input type="date" class="form-control" name="dated" value="<?php echo e($formDated); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- PENDING WORKS TABLE (Dynamic Rows) -->
                    <div class="panel">
                        <div class="table-responsive">
                            <table class="table table-bordered table-dpt" id="dptTable">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">SL.NO</th>
                                        <th style="min-width:250px;">LIST OF PENDING WORKS</th>
                                        <th style="width:130px;">DATE SCHEDULED FINISH</th>
                                        <th style="width:150px;">ACTUAL/ TARGETED FINISH</th>
                                        <th style="width:120px;">STATUS</th>
                                        <th style="min-width:180px;">REMARKS</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="dptTableBody">
                                    <tr class="dpt-row">
                                        <td><input type="number" class="form-control sl-no" name="sl_no[]" value="1" readonly style="background:#f9fafb; width:70px;"></td>
                                        <td><input type="text" class="form-control" name="list_of_work[]" placeholder="Enter pending work description"></td>
                                        <td><input type="date" class="form-control" name="scheduled_finish[]" placeholder="Scheduled date"></td>
                                        <td><input type="date" class="form-control" name="actual_targeted_finish[]" placeholder="Actual/Targeted date"></td>
                                        <td>
                                            <select class="form-select status-select" name="status[]">
                                                <option value="ONTRACK">ON TRACK</option>
                                                <option value="DELAY">DELAY</option>
                                                <option value="COMPLETED">COMPLETED</option>
                                                <option value="BLOCKED">BLOCKED</option>
                                                <option value="CANCELLED">CANCELLED</option>
                                            </select>
                                        </td>
                                        <td><input type="text" class="form-control" name="remark[]" placeholder="Any remarks"></td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px;"></i>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                            <div class="small-muted">
                                <i class="bi bi-info-circle"></i> At least one pending work item must be filled.
                            </div>
                            <button type="button" class="btn btn-primary" id="addRowBtn" style="border-radius:12px;">
                                <i class="bi bi-plus-circle"></i> Add Work Item
                            </button>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-save"></i> Submit DPT
                            </button>
                        </div>
                    </div>
                </form>

                <!-- RECENT DPT SUBMISSIONS -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <p class="sec-title mb-0">Recent DPT Submissions</p>
                            <p class="sec-sub mb-0">Your last 10 submissions</p>
                        </div>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="text-muted" style="font-weight:800;">No DPT submitted yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>DPT No</th>
                                        <th>Dated</th>
                                        <th>Project</th>
                                        <th>Work Items</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><?php echo e($r['dpt_no']); ?></td>
                                            <td><?php echo e($r['dated']); ?></td>
                                            <td><?php echo e($r['project_name']); ?></td>
                                            <td class="text-center"><?php echo $r['item_count']; ?></td>
                                            <td>
                                                <a href="report-dpt-print.php?view=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
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
    
    // Update status select styling on change
    function updateStatusStyle(selectEl) {
        const val = selectEl.value;
        selectEl.classList.remove('status-ontrack', 'status-delay', 'status-completed');
        if (val === 'ONTRACK') selectEl.classList.add('status-ontrack');
        else if (val === 'DELAY') selectEl.classList.add('status-delay');
        else if (val === 'COMPLETED') selectEl.classList.add('status-completed');
    }
    
    // Initialize existing status selects
    document.querySelectorAll('.status-select').forEach(function(sel){
        updateStatusStyle(sel);
        sel.addEventListener('change', function(){ updateStatusStyle(this); });
    });
    
    // Re-number rows
    function renumberRows() {
        document.querySelectorAll('#dptTableBody .dpt-row').forEach(function(row, idx){
            var slInput = row.querySelector('.sl-no');
            if (slInput) slInput.value = idx + 1;
        });
    }
    
    // Add Row
    function addRow() {
        const tbody = document.getElementById('dptTableBody');
        const originalRow = document.querySelector('#dptTableBody .dpt-row');
        if (!originalRow) return;
        
        const newRow = originalRow.cloneNode(true);
        
        // Clear all input values in the new row
        newRow.querySelectorAll('input').forEach(function(field){
            if (field.classList && field.classList.contains('sl-no')) {
                // Keep SL NO - will be renumbered
            } else if (field.type === 'date') {
                field.value = '';
            } else {
                field.value = '';
            }
        });
        
        // Reset status select to default
        const statusSelect = newRow.querySelector('.status-select');
        if (statusSelect) {
            statusSelect.value = 'ONTRACK';
            updateStatusStyle(statusSelect);
            // Re-attach event listener
            statusSelect.addEventListener('change', function(){ updateStatusStyle(this); });
        }
        
        tbody.appendChild(newRow);
        renumberRows();
    }
    
    // Delete Row (Event Delegation)
    document.getElementById('dptTableBody')?.addEventListener('click', function(e){
        const deleteBtn = e.target.closest('.delete-row-btn');
        if (!deleteBtn) return;
        
        const row = deleteBtn.closest('.dpt-row');
        const tbody = row.parentNode;
        const rows = tbody.querySelectorAll('.dpt-row');
        
        if (rows.length <= 1) {
            // Clear all fields instead of deleting last row
            row.querySelectorAll('input').forEach(function(field){
                if (field.classList && field.classList.contains('sl-no')) {
                    field.value = 1;
                } else if (field.type === 'date') {
                    field.value = '';
                } else {
                    field.value = '';
                }
            });
            const statusSelect = row.querySelector('.status-select');
            if (statusSelect) {
                statusSelect.value = 'ONTRACK';
                updateStatusStyle(statusSelect);
            }
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