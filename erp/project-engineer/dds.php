<?php
// dds.php - Design Deliverable Schedule (DDS) Submission Form

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
        $sql = "SELECT s.id, s.project_name, s.client_id, c.client_name, s.project_location, 
                       s.manager_employee_id, s.team_lead_employee_id,
                       e1.full_name as manager_name, e2.full_name as team_lead_name
                FROM sites s
                INNER JOIN clients c ON c.id = s.client_id
                LEFT JOIN employees e1 ON e1.id = s.manager_employee_id
                LEFT JOIN employees e2 ON e2.id = s.team_lead_employee_id
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

// ---------------- Get Structural Consultants & PMC from clients table or custom ----------------
$consultants = [];
$structuralConsultants = [];
$pmcList = [];

$qConsultants = "SELECT id, client_name, company_name FROM clients WHERE client_type IN ('Company', 'Corporate', 'Builder') ORDER BY client_name";
$resConsult = mysqli_query($conn, $qConsultants);
if ($resConsult) {
    while ($row = mysqli_fetch_assoc($resConsult)) {
        $consultants[] = $row;
    }
}

// ---------------- Generate DDS Number ----------------
function generateDDSNo($conn, $siteId) {
    $year = date('Y');
    $month = date('m');
    $prefix = "DDS/{$siteId}/{$year}{$month}/";
    
    $st = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM dds_main WHERE dds_no LIKE ?");
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
if (isset($_GET['saved']) && $_GET['saved'] === '1' && isset($_GET['did'])) {
    $lastInsertedId = (int)$_GET['did'];
}

// ---------------- Submit DDS ----------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dds'])) {
    $site_id = (int)($_POST['site_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $project_name = trim((string)($_POST['project_name'] ?? ''));
    $client_name = trim((string)($_POST['client_name'] ?? ''));
    $architect = trim((string)($_POST['architect'] ?? ''));
    $structural_consultant = trim((string)($_POST['structural_consultant'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $date_version = trim((string)($_POST['date_version'] ?? ''));
    $dds_date = trim((string)($_POST['dds_date'] ?? date('Y-m-d')));
    
    // Get rows data
    $sl_nos = $_POST['sl_no'] ?? [];
    $list_of_drawings = $_POST['list_of_drawings'] ?? [];
    $durations = $_POST['duration'] ?? [];
    $start_dates = $_POST['start_date'] ?? [];
    $end_dates = $_POST['end_date'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    $sections = $_POST['section'] ?? [];
    
    // Validate at least one row with list of drawings
    $hasValidRow = false;
    foreach ($list_of_drawings as $idx => $drawing) {
        if (trim($drawing) !== '') {
            $hasValidRow = true;
            break;
        }
    }
    
    if ($site_id <= 0) $error = "Please select a site.";
    if (!$hasValidRow) $error = "Please enter at least one drawing.";
    
    if ($error === '') {
        // Generate DDS Number
        $dds_no = generateDDSNo($conn, $site_id);
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert into main table
            $insMain = mysqli_prepare($conn, "
                INSERT INTO dds_main 
                (dds_no, site_id, client_id, project_name, client_name, architect, structural_consultant, pmc, date_version, dds_date, created_by, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            mysqli_stmt_bind_param($insMain, "siisssssssis",
                $dds_no, $site_id, $client_id,
                $project_name, $client_name,
                $architect, $structural_consultant, $pmc,
                $date_version, $dds_date,
                $employeeId, $employeeName
            );
            if (!mysqli_stmt_execute($insMain)) {
                throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
            }
            
            $mainId = mysqli_insert_id($conn);
            mysqli_stmt_close($insMain);
            
            // Insert into details table for each drawing row
            $insDetail = mysqli_prepare($conn, "
                INSERT INTO dds_details 
                (dds_main_id, sl_no, section, list_of_drawings, duration_days, start_date, end_date, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($list_of_drawings as $idx => $drawing) {
                if (trim($drawing) === '') continue;
                
                $sl_no = (int)($sl_nos[$idx] ?? ($idx + 1));
                $section = trim($sections[$idx] ?? '');
                $duration = !empty($durations[$idx]) ? (int)$durations[$idx] : null;
                $start_date = !empty($start_dates[$idx]) ? trim($start_dates[$idx]) : null;
                $end_date = !empty($end_dates[$idx]) ? trim($end_dates[$idx]) : null;
                $remarks_val = trim($remarks[$idx] ?? '');
                
                mysqli_stmt_bind_param($insDetail, "iissiiss",
                    $mainId, $sl_no, $section, $drawing, 
                    $duration, $start_date, $end_date, $remarks_val
                );
                
                if (!mysqli_stmt_execute($insDetail)) {
                    throw new Exception("Failed to save detail row: " . mysqli_stmt_error($insDetail));
                }
            }
            mysqli_stmt_close($insDetail);
            
            mysqli_commit($conn);
            
            header("Location: dds.php?site_id=" . $site_id . "&saved=1&did=" . $mainId);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// ---------------- Get Recent DDS Records ----------------
$recent = [];
$st = mysqli_prepare($conn, "
    SELECT d.id, d.dds_no, d.dds_date, d.project_name, d.client_name, COUNT(dt.id) as drawing_count
    FROM dds_main d
    LEFT JOIN dds_details dt ON dt.dds_main_id = d.id
    WHERE d.created_by = ?
    GROUP BY d.id
    ORDER BY d.created_at DESC
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
$formDDSDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>DDS - Design Deliverable Schedule | TEK-C</title>

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
        .grid-5{ display:grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr; gap:16px; }
        @media (max-width: 992px){
            .grid-2, .grid-3, .grid-4, .grid-5{ grid-template-columns: 1fr; }
        }

        .badge-pill{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 12px; border-radius:999px;
            border:1px solid #e5e7eb; background:#fff;
            font-weight:900; font-size:12px;
        }
        .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
        
        .table-dds thead th {
            font-size: 12px;
            color: #6b7280;
            font-weight: 900;
            background: #f9fafb;
            white-space: nowrap;
            vertical-align: middle;
            text-align: center;
        }
        .table-dds td { vertical-align: middle; }
        
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
        
        .section-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            background: #eef2ff;
            color: #1e40af;
        }
        
        /* Section header row styling */
        .section-header-row td {
            background: #f8fafc !important;
            font-weight: 700;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .section-code {
            display: inline-block;
            background: #2d9cdb;
            color: white;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            margin-right: 8px;
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
                        <h1 class="h-title">DESIGN DELIVERABLE SCHEDULE (DDS)</h1>
                        <p class="h-sub">Track drawing deliverables, durations, and scheduled dates</p>
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
                        <i class="bi bi-check-circle-fill me-2"></i> DDS submitted successfully!
                        <a href="report-dds-print.php?view=<?php echo $lastInsertedId; ?>" target="_blank" class="alert-link ms-3">
                            <i class="bi bi-printer"></i> Print/View PDF
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- DDS FORM -->
                <form method="POST" autocomplete="off" id="ddsForm">
                    <input type="hidden" name="submit_dds" value="1">
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
                        
                        <div class="grid-3 mt-3">
                            <div>
                                <label class="form-label">Architect</label>
                                <input type="text" class="form-control" name="architect" placeholder="Enter architect name">
                            </div>
                            <div>
                                <label class="form-label">Structural Consultant</label>
                                <input type="text" class="form-control" name="structural_consultant" placeholder="Enter structural consultant name">
                            </div>
                            <div>
                                <label class="form-label">PMC</label>
                                <input type="text" class="form-control" name="pmc" placeholder="Enter PMC name">
                            </div>
                        </div>
                        
                        <div class="grid-2 mt-3">
                            <div>
                                <label class="form-label">Date / Version</label>
                                <input type="text" class="form-control" name="date_version" placeholder="e.g., 24-03-2026 / V1">
                            </div>
                            <div>
                                <label class="form-label">DDS Date</label>
                                <input type="date" class="form-control" name="dds_date" value="<?php echo e($formDDSDate); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- DRAWINGS TRACKER TABLE - Based on Image -->
                    <div class="panel">
                        <div class="table-responsive">
                            <table class="table table-bordered table-dds" id="ddsTable">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">SL NO</th>
                                        <th style="min-width:300px;">LIST OF DRAWINGS</th>
                                        <th style="min-width:100px;">DURATION (DAYS)</th>
                                        <th style="min-width:120px;">START DATE</th>
                                        <th style="min-width:120px;">END DATE</th>
                                        <th style="min-width:150px;">REMARK</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="ddsTableBody">
                                    <!-- Architectural Drawings Section -->
                                    <tr class="section-header-row">
                                        <td colspan="7" style="background:#f0f4f8;">
                                            <span class="section-code">A</span> ARCHITECTURAL DRAWINGS
                                        </td>
                                    </tr>
                                    <tr class="dds-row arch-row">
                                        <td><input type="number" class="form-control sl-no" name="sl_no[]" value="1" readonly style="background:#f9fafb; width:70px;"></td>
                                        <td><input type="text" class="form-control" name="list_of_drawings[]" placeholder="Drawing name / description"></td>
                                        <td><input type="number" class="form-control" name="duration[]" placeholder="Days"></td>
                                        <td><input type="date" class="form-control" name="start_date[]" placeholder="Start date"></td>
                                        <td><input type="date" class="form-control" name="end_date[]" placeholder="End date"></td>
                                        <td><input type="text" class="form-control" name="remarks[]" placeholder="Remarks"></td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px;"></i>
                                        </td>
                                        <input type="hidden" name="section[]" value="A">
                                    </tr>

                                    <!-- Structural Drawings Section -->
                                    <tr class="section-header-row">
                                        <td colspan="7" style="background:#f0f4f8;">
                                            <span class="section-code">B</span> STRUCTURAL DRAWINGS
                                        </td>
                                    </tr>
                                    <tr class="dds-row struct-row">
                                        <td><input type="number" class="form-control sl-no" name="sl_no[]" value="2" readonly style="background:#f9fafb; width:70px;"></td>
                                        <td><input type="text" class="form-control" name="list_of_drawings[]" placeholder="Drawing name / description"></td>
                                        <td><input type="number" class="form-control" name="duration[]" placeholder="Days"></td>
                                        <td><input type="date" class="form-control" name="start_date[]" placeholder="Start date"></td>
                                        <td><input type="date" class="form-control" name="end_date[]" placeholder="End date"></td>
                                        <td><input type="text" class="form-control" name="remarks[]" placeholder="Remarks"></td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px;"></i>
                                        </td>
                                        <input type="hidden" name="section[]" value="B">
                                    </tr>

                                    <!-- Electrical Drawings Section -->
                                    <tr class="section-header-row">
                                        <td colspan="7" style="background:#f0f4f8;">
                                            <span class="section-code">C</span> ELECTRICAL DRAWINGS
                                        </td>
                                    </tr>
                                    <tr class="dds-row elec-row">
                                        <td><input type="number" class="form-control sl-no" name="sl_no[]" value="3" readonly style="background:#f9fafb; width:70px;"></td>
                                        <td><input type="text" class="form-control" name="list_of_drawings[]" placeholder="Drawing name / description"></td>
                                        <td><input type="number" class="form-control" name="duration[]" placeholder="Days"></td>
                                        <td><input type="date" class="form-control" name="start_date[]" placeholder="Start date"></td>
                                        <td><input type="date" class="form-control" name="end_date[]" placeholder="End date"></td>
                                        <td><input type="text" class="form-control" name="remarks[]" placeholder="Remarks"></td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px;"></i>
                                        </td>
                                        <input type="hidden" name="section[]" value="C">
                                    </tr>

                                    <!-- Plumbing Drawings Section -->
                                    <tr class="section-header-row">
                                        <td colspan="7" style="background:#f0f4f8;">
                                            <span class="section-code">D</span> PLUMBING DRAWINGS
                                        </td>
                                    </tr>
                                    <tr class="dds-row plumb-row">
                                        <td><input type="number" class="form-control sl-no" name="sl_no[]" value="4" readonly style="background:#f9fafb; width:70px;"></td>
                                        <td><input type="text" class="form-control" name="list_of_drawings[]" placeholder="Drawing name / description"></td>
                                        <td><input type="number" class="form-control" name="duration[]" placeholder="Days"></td>
                                        <td><input type="date" class="form-control" name="start_date[]" placeholder="Start date"></td>
                                        <td><input type="date" class="form-control" name="end_date[]" placeholder="End date"></td>
                                        <td><input type="text" class="form-control" name="remarks[]" placeholder="Remarks"></td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px;"></i>
                                        </td>
                                        <input type="hidden" name="section[]" value="D">
                                    </tr>

                                    <!-- HVAC Drawings Section -->
                                    <tr class="section-header-row">
                                        <td colspan="7" style="background:#f0f4f8;">
                                            <span class="section-code">E</span> HVAC DRAWINGS
                                        </td>
                                    </tr>
                                    <tr class="dds-row hvac-row">
                                        <td><input type="number" class="form-control sl-no" name="sl_no[]" value="5" readonly style="background:#f9fafb; width:70px;"></td>
                                        <td><input type="text" class="form-control" name="list_of_drawings[]" placeholder="Drawing name / description"></td>
                                        <td><input type="number" class="form-control" name="duration[]" placeholder="Days"></td>
                                        <td><input type="date" class="form-control" name="start_date[]" placeholder="Start date"></td>
                                        <td><input type="date" class="form-control" name="end_date[]" placeholder="End date"></td>
                                        <td><input type="text" class="form-control" name="remarks[]" placeholder="Remarks"></td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-row-btn" style="font-size:18px;"></i>
                                        </td>
                                        <input type="hidden" name="section[]" value="E">
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                            <div class="small-muted">
                                <i class="bi bi-info-circle"></i> At least one drawing must be filled.
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-secondary" id="addArchRowBtn" style="border-radius:12px;">
                                    <i class="bi bi-plus-circle"></i> Add Architectural Row
                                </button>
                                <button type="button" class="btn btn-secondary" id="addStructRowBtn" style="border-radius:12px;">
                                    <i class="bi bi-plus-circle"></i> Add Structural Row
                                </button>
                                <button type="button" class="btn btn-secondary" id="addElecRowBtn" style="border-radius:12px;">
                                    <i class="bi bi-plus-circle"></i> Add Electrical Row
                                </button>
                                <button type="button" class="btn btn-secondary" id="addPlumbRowBtn" style="border-radius:12px;">
                                    <i class="bi bi-plus-circle"></i> Add Plumbing Row
                                </button>
                                <button type="button" class="btn btn-secondary" id="addHVACRowBtn" style="border-radius:12px;">
                                    <i class="bi bi-plus-circle"></i> Add HVAC Row
                                </button>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-save"></i> Submit DDS
                            </button>
                        </div>
                    </div>
                </form>

                <!-- RECENT DDS SUBMISSIONS -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <p class="sec-title mb-0">Recent DDS Submissions</p>
                            <p class="sec-sub mb-0">Your last 10 submissions</p>
                        </div>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="text-muted" style="font-weight:800;">No DDS submitted yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>DDS No</th>
                                        <th>Date</th>
                                        <th>Project</th>
                                        <th>Client</th>
                                        <th>Drawings</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><?php echo e($r['dds_no']); ?></td>
                                            <td><?php echo e($r['dds_date']); ?></td>
                                            <td><?php echo e($r['project_name']); ?></td>
                                            <td><?php echo e($r['client_name']); ?></td>
                                            <td class="text-center"><?php echo $r['drawing_count']; ?></td>
                                            <td>
                                                <a href="report-dds-print.php?view=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
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
    
    // Function to add row to specific section
    function addRowToSection(sectionClass, sectionCode, sectionName, sectionValue) {
        var tbody = document.getElementById('ddsTableBody');
        
        // Find the last row of this section
        var rows = tbody.querySelectorAll('tr');
        var lastRowInSection = null;
        var inSection = false;
        
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].classList.contains('section-header-row') && rows[i].innerText.includes(sectionName)) {
                inSection = true;
                lastRowInSection = rows[i];
            } else if (inSection && rows[i].classList.contains('section-header-row')) {
                break;
            } else if (inSection && rows[i].classList.contains('dds-row')) {
                lastRowInSection = rows[i];
            }
        }
        
        // Get max SL NO in this section
        var sectionRows = tbody.querySelectorAll('.dds-row.' + sectionClass);
        var maxSlNo = 0;
        sectionRows.forEach(function(row) {
            var slInput = row.querySelector('.sl-no');
            if (slInput && slInput.value) {
                var val = parseInt(slInput.value);
                if (!isNaN(val) && val > maxSlNo) maxSlNo = val;
            }
        });
        
        // Clone the first row of this section as template
        var templateRow = tbody.querySelector('.dds-row.' + sectionClass);
        if (!templateRow) return;
        
        var newRow = templateRow.cloneNode(true);
        
        // Clear all input values in the new row
        newRow.querySelectorAll('input, select').forEach(function(field){
            if (field.classList && field.classList.contains('sl-no')) {
                field.value = maxSlNo + 1;
            } else if (field.type === 'date') {
                field.value = '';
            } else if (field.type === 'text' && field.name !== 'section[]') {
                field.value = '';
            } else if (field.type === 'number') {
                field.value = '';
            } else if (field.tagName === 'SELECT') {
                field.selectedIndex = 0;
            }
        });
        
        // Set section value
        var sectionInput = newRow.querySelector('input[name="section[]"]');
        if (sectionInput) sectionInput.value = sectionValue;
        
        newRow.classList.add(sectionClass);
        
        if (lastRowInSection && lastRowInSection.nextSibling) {
            tbody.insertBefore(newRow, lastRowInSection.nextSibling);
        } else if (lastRowInSection) {
            tbody.appendChild(newRow);
        } else {
            tbody.appendChild(newRow);
        }
        
        // Renumber only rows in this section
        var counter = 1;
        tbody.querySelectorAll('.dds-row.' + sectionClass).forEach(function(row){
            var slInput = row.querySelector('.sl-no');
            if (slInput) slInput.value = counter++;
        });
    }

    // Add row button clicks
    document.getElementById('addArchRowBtn')?.addEventListener('click', function(){
        addRowToSection('arch-row', 'A', 'ARCHITECTURAL DRAWINGS', 'A');
    });

    document.getElementById('addStructRowBtn')?.addEventListener('click', function(){
        addRowToSection('struct-row', 'B', 'STRUCTURAL DRAWINGS', 'B');
    });
    
    document.getElementById('addElecRowBtn')?.addEventListener('click', function(){
        addRowToSection('elec-row', 'C', 'ELECTRICAL DRAWINGS', 'C');
    });
    
    document.getElementById('addPlumbRowBtn')?.addEventListener('click', function(){
        addRowToSection('plumb-row', 'D', 'PLUMBING DRAWINGS', 'D');
    });
    
    document.getElementById('addHVACRowBtn')?.addEventListener('click', function(){
        addRowToSection('hvac-row', 'E', 'HVAC DRAWINGS', 'E');
    });
    
    // Delete Row (Event Delegation)
    document.getElementById('ddsTableBody')?.addEventListener('click', function(e){
        var deleteBtn = e.target.closest('.delete-row-btn');
        if (!deleteBtn) return;
        
        var row = deleteBtn.closest('.dds-row');
        var sectionClass = row.classList.contains('arch-row') ? 'arch-row' : 
                          (row.classList.contains('struct-row') ? 'struct-row' :
                          (row.classList.contains('elec-row') ? 'elec-row' :
                          (row.classList.contains('plumb-row') ? 'plumb-row' : 'hvac-row')));
        var rowsInSection = document.querySelectorAll('.dds-row.' + sectionClass);
        
        if (rowsInSection.length <= 1) {
            // Clear all fields instead of deleting last row
            row.querySelectorAll('input, select').forEach(function(field){
                if (field.classList && field.classList.contains('sl-no')) {
                    // Keep SL NO
                } else if (field.type === 'date') {
                    field.value = '';
                } else if (field.type === 'text' || field.type === 'number') {
                    field.value = '';
                } else if (field.tagName === 'SELECT') {
                    field.selectedIndex = 0;
                }
            });
        } else {
            row.remove();
            // Re-number rows in this section only
            var counter = 1;
            document.querySelectorAll('.dds-row.' + sectionClass).forEach(function(r){
                var slInput = r.querySelector('.sl-no');
                if (slInput) slInput.value = counter++;
            });
        }
    });
    
    // Auto-calculate end date when start date and duration are entered
    function calculateEndDate(startDateInput, durationInput, endDateInput) {
        if (startDateInput && durationInput && endDateInput) {
            var startDate = startDateInput.value;
            var duration = parseInt(durationInput.value);
            
            if (startDate && !isNaN(duration) && duration > 0) {
                var date = new Date(startDate);
                date.setDate(date.getDate() + duration - 1);
                var year = date.getFullYear();
                var month = String(date.getMonth() + 1).padStart(2, '0');
                var day = String(date.getDate()).padStart(2, '0');
                endDateInput.value = year + '-' + month + '-' + day;
            }
        }
    }
    
    // Add event listeners for auto-calculation on all rows
    function addAutoCalculateListeners() {
        document.querySelectorAll('.dds-row').forEach(function(row) {
            var startInput = row.querySelector('input[name="start_date[]"]');
            var durationInput = row.querySelector('input[name="duration[]"]');
            var endInput = row.querySelector('input[name="end_date[]"]');
            
            if (startInput && durationInput && endInput) {
                startInput.removeEventListener('change', function() {});
                durationInput.removeEventListener('input', function() {});
                
                startInput.addEventListener('change', function() {
                    calculateEndDate(startInput, durationInput, endInput);
                });
                durationInput.addEventListener('input', function() {
                    calculateEndDate(startInput, durationInput, endInput);
                });
            }
        });
    }
    
    // Initial setup
    addAutoCalculateListeners();
    
    // Watch for new rows added
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                addAutoCalculateListeners();
            }
        });
    });
    
    observer.observe(document.getElementById('ddsTableBody'), { childList: true, subtree: true });
});
</script>

</body>
</html>