<?php
// vft.php - Vendor Finalization Tracker (VFT) Submission Form with Dynamic Rows

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

// ---------------- Generate VFT Number ----------------
function generateVftNo($conn, $siteId) {
    $year = date('Y');
    $month = date('m');
    $prefix = "VFT/{$siteId}/{$year}{$month}/";
    
    $st = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM vft_main WHERE vft_no LIKE ?");
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
if (isset($_GET['saved']) && $_GET['saved'] === '1' && isset($_GET['vid'])) {
    $lastInsertedId = (int)$_GET['vid'];
}

// ---------------- Submit VFT ----------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vft'])) {
    $site_id = (int)($_POST['site_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $project_name = trim((string)($_POST['project_name'] ?? ''));
    $client_name = trim((string)($_POST['client_name'] ?? ''));
    $architects = trim((string)($_POST['architects'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $date_version = trim((string)($_POST['date_version'] ?? ''));
    $vft_date = trim((string)($_POST['vft_date'] ?? date('Y-m-d')));
    
    // Get rows data
    $sl_nos = $_POST['sl_no'] ?? [];
    $packages = $_POST['package'] ?? [];
    $statuses = $_POST['status'] ?? [];
    $planned_start = $_POST['planned_start'] ?? [];
    $planned_finish = $_POST['planned_finish'] ?? [];
    $actual_start = $_POST['actual_start'] ?? [];
    $actual_finish = $_POST['actual_finish'] ?? [];
    $design_approval = $_POST['design_approval'] ?? [];
    $budget_approval = $_POST['budget_approval'] ?? [];
    $vendor_identification = $_POST['vendor_identification'] ?? [];
    $rfq_tender = $_POST['rfq_tender'] ?? [];
    $finalization = $_POST['finalization'] ?? [];
    $approved = $_POST['approved'] ?? [];
    $po_wo = $_POST['po_wo'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    
    // Validate at least one row with package name
    $hasValidRow = false;
    foreach ($packages as $idx => $pkg) {
        if (trim($pkg) !== '') {
            $hasValidRow = true;
            break;
        }
    }
    
    if ($site_id <= 0) $error = "Please select a site.";
    if (!$hasValidRow) $error = "Please enter at least one package.";
    
    if ($error === '') {
        // Generate VFT Number
        $vft_no = generateVftNo($conn, $site_id);
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert into main table
            $insMain = mysqli_prepare($conn, "
                INSERT INTO vft_main 
                (vft_no, site_id, client_id, project_name, client_name, architects, pmc,
                date_version, vft_date, created_by, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            mysqli_stmt_bind_param($insMain, "siissssssis",
                $vft_no, $site_id, $client_id,
                $project_name, $client_name,
                $architects, $pmc,
                $date_version, $vft_date,
                $employeeId, $employeeName
            );
            if (!mysqli_stmt_execute($insMain)) {
                throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
            }
            
            $mainId = mysqli_insert_id($conn);
            mysqli_stmt_close($insMain);
            
            // Insert into details table for each package
            $insDetail = mysqli_prepare($conn, "
                INSERT INTO vft_details 
                (vft_main_id, sl_no, package, status, 
                planned_schedule_start, planned_schedule_finish,
                actual_expected_start, actual_expected_finish,
                design_approval, budget_approval, vendor_identification, 
                rfq_tender, finalization, approved, po_wo, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $rowCount = 0;
            foreach ($packages as $idx => $pkg) {
                if (trim($pkg) === '') continue;
                
                $sl_no = (int)($sl_nos[$idx] ?? ($idx + 1));
                $status = in_array($statuses[$idx] ?? '', ['Done','Approved','WIP','NIP']) ? $statuses[$idx] : 'NIP';
                
                // Prepare variables for binding
                $planned_start_val = !empty($planned_start[$idx]) ? $planned_start[$idx] : null;
                $planned_finish_val = !empty($planned_finish[$idx]) ? $planned_finish[$idx] : null;
                $actual_start_val = !empty($actual_start[$idx]) ? $actual_start[$idx] : null;
                $actual_finish_val = !empty($actual_finish[$idx]) ? $actual_finish[$idx] : null;
                $design_approval_val = $design_approval[$idx] ?? '';
                $budget_approval_val = $budget_approval[$idx] ?? '';
                $vendor_identification_val = $vendor_identification[$idx] ?? '';
                $rfq_tender_val = $rfq_tender[$idx] ?? '';
                $finalization_val = $finalization[$idx] ?? '';
                $approved_val = $approved[$idx] ?? '';
                $po_wo_val = $po_wo[$idx] ?? '';
                $remarks_val = $remarks[$idx] ?? '';
                
                mysqli_stmt_bind_param($insDetail, "iissssssssssssss",
                    $mainId, 
                    $sl_no, 
                    $pkg, 
                    $status,
                    $planned_start_val,
                    $planned_finish_val,
                    $actual_start_val,
                    $actual_finish_val,
                    $design_approval_val,
                    $budget_approval_val,
                    $vendor_identification_val,
                    $rfq_tender_val,
                    $finalization_val,
                    $approved_val,
                    $po_wo_val,
                    $remarks_val
                );
                
                if (!mysqli_stmt_execute($insDetail)) {
                    throw new Exception("Failed to save detail row: " . mysqli_stmt_error($insDetail));
                }
                $rowCount++;
            }
            mysqli_stmt_close($insDetail);
            
            mysqli_commit($conn);
            
            header("Location: vft.php?site_id=" . $site_id . "&saved=1&vid=" . $mainId);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// ---------------- Get Recent VFT Records ----------------
$recent = [];
$st = mysqli_prepare($conn, "
    SELECT v.id, v.vft_no, v.vft_date, v.project_name, COUNT(d.id) as package_count
    FROM vft_main v
    LEFT JOIN vft_details d ON d.vft_main_id = v.id
    WHERE v.created_by = ?
    GROUP BY v.id
    ORDER BY v.created_at DESC
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
$formVftDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>VFT - Vendor Finalization Tracker | TEK-C</title>

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
        
        .table-vft thead th {
            font-size: 12px;
            color: #6b7280;
            font-weight: 900;
            background: #f9fafb;
            white-space: nowrap;
            padding: 10px 8px;
            text-align: center;
            vertical-align: middle;
        }
        .table-vft td { vertical-align: middle; padding: 8px; }
        .table-vft input, .table-vft select { width: 100%; min-width: 100px; }
        
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
            font-size: 18px;
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
        
        .table-wrapper {
            overflow-x: auto;
            max-width: 100%;
        }
        
        @media (max-width: 1400px) {
            .table-vft { min-width: 1400px; }
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
                        <h1 class="h-title">Vendor Finalization Tracker (VFT)</h1>
                        <p class="h-sub">Track vendor selection, approval status, and procurement milestones</p>
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
                        <i class="bi bi-check-circle-fill me-2"></i> VFT submitted successfully!
                        <a href="report-vft-print.php?view=<?php echo $lastInsertedId; ?>" target="_blank" class="alert-link ms-3">
                            <i class="bi bi-printer"></i> Print/View PDF
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- VFT FORM -->
                <form method="POST" autocomplete="off" id="vftForm">
                    <input type="hidden" name="submit_vft" value="1">
                    <input type="hidden" name="site_id" id="site_id" value="<?php echo (int)$formSiteId; ?>">
                    <input type="hidden" name="client_id" id="client_id" value="<?php echo (int)$formClientId; ?>">
                    <input type="hidden" name="project_name" id="project_name" value="<?php echo e($formProjectName); ?>">
                    <input type="hidden" name="client_name" id="client_name" value="<?php echo e($formClientName); ?>">

                    <!-- PROJECT INFO HEADER -->
                    <div class="panel">
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
                                <label class="form-label">Architects</label>
                                <input type="text" class="form-control" name="architects" placeholder="Enter architect name/firm">
                            </div>
                            <div>
                                <label class="form-label">PMC</label>
                                <input type="text" class="form-control" name="pmc" placeholder="Enter PMC name">
                            </div>
                        </div>
                        <div class="grid-2 mt-3">
                            <div>
                                <label class="form-label">Date / Version</label>
                                <input type="text" class="form-control" name="date_version" placeholder="e.g., 24-04-2026 / V1">
                            </div>
                            <div>
                                <label class="form-label">Report Date</label>
                                <input type="date" class="form-control" name="vft_date" value="<?php echo e($formVftDate); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- VFT TABLE (Dynamic Rows) -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-list-check"></i></div>
                            <div>
                                <p class="sec-title mb-0">Vendor Finalization Details</p>
                                <p class="sec-sub mb-0">Track each package through the procurement lifecycle</p>
                            </div>
                        </div>

                        <div class="table-wrapper">
                            <table class="table table-bordered table-vft" id="vftTable">
                                <thead>
                                    <tr>
                                        <th style="width:50px;">SL NO</th>
                                        <th style="min-width:200px;">PACKAGE</th>
                                        <th style="width:100px;">STATUS</th>
                                        <th style="min-width:120px;">PLANNED SCHEDULE START</th>
                                        <th style="min-width:120px;">FINISH</th>
                                        <th style="min-width:120px;">ACTUAL/EXPECTED START</th>
                                        <th style="min-width:120px;">FINISH</th>
                                        <th style="min-width:100px;">DESIGN APPROVAL</th>
                                        <th style="min-width:100px;">BUDGET APPROVAL</th>
                                        <th style="min-width:100px;">VENDOR IDENTIFICATION</th>
                                        <th style="min-width:100px;">RFQ / TENDER</th>
                                        <th style="min-width:100px;">FINALIZATION</th>
                                        <th style="min-width:100px;">APPROVED</th>
                                        <th style="min-width:100px;">PO/WO</th>
                                        <th style="min-width:150px;">REMARKS</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="vftTableBody">
                                    <tr class="vft-row">
                                        <td><input type="number" class="form-control sl-no" name="sl_no[]" value="1" readonly style="background:#f9fafb; width:70px;"></td>
                                        <td><input type="text" class="form-control" name="package[]" placeholder="Package name"></td>
                                        <td>
                                            <select class="form-select status-select" name="status[]">
                                                <option value="NIP">NIP - Not In Progress</option>
                                                <option value="WIP">WIP - Work In Progress</option>
                                                <option value="Approved">Approved</option>
                                                <option value="Done">Done</option>
                                            </select>
                                </td>
                                        <td><input type="date" class="form-control" name="planned_start[]"></td>
                                        <td><input type="date" class="form-control" name="planned_finish[]"></td>
                                        <td><input type="date" class="form-control" name="actual_start[]"></td>
                                        <td><input type="date" class="form-control" name="actual_finish[]"></td>
                                        <td><input type="text" class="form-control" name="design_approval[]" placeholder="Status/Date"></td>
                                        <td><input type="text" class="form-control" name="budget_approval[]" placeholder="Status/Date"></td>
                                        <td><input type="text" class="form-control" name="vendor_identification[]" placeholder="Vendor name"></td>
                                        <td><input type="text" class="form-control" name="rfq_tender[]" placeholder="RFQ No."></td>
                                        <td><input type="text" class="form-control" name="finalization[]" placeholder="Status/Date"></td>
                                        <td><input type="text" class="form-control" name="approved[]" placeholder="Approved By/Date"></td>
                                        <td><input type="text" class="form-control" name="po_wo[]" placeholder="PO/WO No."></td>
                                        <td><input type="text" class="form-control" name="remarks[]" placeholder="Remarks"></td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn"></i>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                            <div class="small-muted">
                                <i class="bi bi-info-circle"></i> At least one package must be filled.
                            </div>
                            <button type="button" class="btn btn-primary" id="addRowBtn" style="border-radius:12px;">
                                <i class="bi bi-plus-circle"></i> Add Package
                            </button>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-save"></i> Submit VFT
                            </button>
                        </div>
                    </div>
                </form>

                <!-- RECENT VFT SUBMISSIONS -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <p class="sec-title mb-0">Recent VFT Submissions</p>
                            <p class="sec-sub mb-0">Your last 10 submissions</p>
                        </div>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="text-muted" style="font-weight:800;">No VFT submitted yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>VFT No</th>
                                        <th>Date</th>
                                        <th>Project</th>
                                        <th>Packages</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><?php echo e($r['vft_no']); ?></td>
                                            <td><?php echo e($r['vft_date']); ?></td>
                                            <td><?php echo e($r['project_name']); ?></td>
                                            <td class="text-center"><?php echo $r['package_count']; ?></td>
                                            <td>
                                                <a href="report-vft-print.php?view=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
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
        document.querySelectorAll('#vftTableBody .vft-row').forEach(function(row, idx){
            var slInput = row.querySelector('.sl-no');
            if (slInput) slInput.value = idx + 1;
        });
    }
    
    // Add Row
    function addRow() {
        const tbody = document.getElementById('vftTableBody');
        const originalRow = document.querySelector('#vftTableBody .vft-row');
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
        
        // Reset selects to default
        newRow.querySelectorAll('select').forEach(function(select){
            select.value = 'NIP';
        });
        
        tbody.appendChild(newRow);
        renumberRows();
    }
    
    // Delete Row (Event Delegation)
    document.getElementById('vftTableBody')?.addEventListener('click', function(e){
        const deleteBtn = e.target.closest('.delete-row-btn');
        if (!deleteBtn) return;
        
        const row = deleteBtn.closest('.vft-row');
        const tbody = row.parentNode;
        const rows = tbody.querySelectorAll('.vft-row');
        
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
            row.querySelectorAll('select').forEach(function(select){
                select.value = 'NIP';
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