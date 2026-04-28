<?php
// pd.php - Project Directory (PD) - Stakeholders Directory

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

// ---------------- Get Stakeholder Options from Database ----------------
function getStakeholderOptions($conn) {
    $options = [];
    $query = "SELECT stakeholder_type FROM pd_stakeholder_types WHERE is_active = 1 ORDER BY stakeholder_type";
    $result = mysqli_query($conn, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $options[] = $row['stakeholder_type'];
        }
    }
    return $options;
}

$stakeholderOptions = getStakeholderOptions($conn);

// ---------------- AJAX Handler for Adding New Stakeholder Type ----------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_stakeholder_type') {
    header('Content-Type: application/json');
    
    $new_stakeholder_type = trim($_POST['stakeholder_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($new_stakeholder_type)) {
        echo json_encode(['success' => false, 'message' => 'Stakeholder type is required']);
        exit;
    }
    
    // Check if already exists
    $checkSql = "SELECT id FROM pd_stakeholder_types WHERE stakeholder_type = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "s", $new_stakeholder_type);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    
    if (mysqli_stmt_num_rows($checkStmt) > 0) {
        $result = mysqli_stmt_get_result($checkStmt);
        $row = mysqli_fetch_assoc($result);
        echo json_encode([
            'success' => true, 
            'id' => $row['id'], 
            'name' => $new_stakeholder_type, 
            'exists' => true
        ]);
        mysqli_stmt_close($checkStmt);
        exit;
    }
    mysqli_stmt_close($checkStmt);
    
    // Insert new stakeholder type
    $sql = "INSERT INTO pd_stakeholder_types (stakeholder_type, description, is_active) VALUES (?, ?, 1)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $new_stakeholder_type, $description);
    
    if (mysqli_stmt_execute($stmt)) {
        $new_id = mysqli_insert_id($conn);
        echo json_encode(['success' => true, 'id' => $new_id, 'name' => $new_stakeholder_type, 'exists' => false]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add stakeholder type: ' . mysqli_error($conn)]);
    }
    mysqli_stmt_close($stmt);
    exit;
}

// ---------------- Create PD Tables if not exists ----------------
function createPdTables($conn) {
    // Main table
    $mainTable = "CREATE TABLE IF NOT EXISTS `pd_main` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pd_no` varchar(100) NOT NULL,
        `site_id` int(11) NOT NULL,
        `client_id` int(11) NOT NULL,
        `project_name` varchar(255) NOT NULL,
        `client_name` varchar(255) NOT NULL,
        `architect` varchar(255) DEFAULT NULL,
        `pmc` varchar(255) DEFAULT NULL,
        `version` varchar(100) DEFAULT NULL,
        `pd_date` date NOT NULL,
        `prepared_by` int(11) NOT NULL,
        `prepared_by_name` varchar(150) NOT NULL,
        `remarks` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_pd_no` (`pd_no`),
        KEY `idx_site` (`site_id`),
        KEY `idx_client` (`client_id`),
        KEY `idx_prepared_by` (`prepared_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $mainTable);
    
    // Details table
    $detailsTable = "CREATE TABLE IF NOT EXISTS `pd_details` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pd_main_id` int(11) NOT NULL,
        `sl_no` int(11) NOT NULL,
        `stakeholder_type` varchar(100) NOT NULL,
        `company_name` varchar(200) DEFAULT NULL,
        `contact_person` varchar(150) DEFAULT NULL,
        `designation` varchar(100) DEFAULT NULL,
        `mobile_number` varchar(20) DEFAULT NULL,
        `email_id` varchar(190) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_pd_main` (`pd_main_id`),
        CONSTRAINT `fk_pd_details_main` FOREIGN KEY (`pd_main_id`) REFERENCES `pd_main` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $detailsTable);
    
    // Stakeholder Types table
    $typeTable = "CREATE TABLE IF NOT EXISTS `pd_stakeholder_types` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `stakeholder_type` varchar(100) NOT NULL,
        `description` text DEFAULT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_stakeholder_type` (`stakeholder_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $typeTable);
}

createPdTables($conn);

// ---------------- Generate PD Number ----------------
function generatePdNo($conn, $siteId) {
    $year = date('Y');
    $month = date('m');
    $prefix = "PD/{$siteId}/{$year}{$month}/";
    
    $st = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM pd_main WHERE pd_no LIKE ?");
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

// ---------------- Submit PD ----------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pd'])) {
    $site_id = (int)($_POST['site_id'] ?? 0);
    $client_id = (int)($_POST['client_id'] ?? 0);
    $project_name = trim((string)($_POST['project_name'] ?? ''));
    $client_name = trim((string)($_POST['client_name'] ?? ''));
    $architect = trim($_POST['architect'] ?? '');
    $pmc = trim($_POST['pmc'] ?? '');
    $version = trim($_POST['version'] ?? '');
    $pd_date = trim($_POST['pd_date'] ?? date('Y-m-d'));
    $remarks = trim($_POST['remarks'] ?? '');
    
    // Get details data
    $detail_sl_nos = $_POST['detail_sl_no'] ?? [];
    $stakeholder_types = $_POST['stakeholder_type'] ?? [];
    $companies = $_POST['company'] ?? [];
    $contact_persons = $_POST['contact_person'] ?? [];
    $designations = $_POST['designation'] ?? [];
    $mobiles = $_POST['mobile'] ?? [];
    $emails = $_POST['email'] ?? [];
    
    // Validate at least one stakeholder
    $hasValidRow = false;
    foreach ($stakeholder_types as $type) {
        if (trim($type) !== '') {
            $hasValidRow = true;
            break;
        }
    }
    
    if ($site_id <= 0) $error = "Please select a site.";
    if (!$hasValidRow) $error = "Please add at least one stakeholder.";
    
    if ($error === '') {
        $pd_no = generatePdNo($conn, $site_id);
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert into main table
            $insMain = mysqli_prepare($conn, "
                INSERT INTO pd_main 
                (pd_no, site_id, client_id, project_name, client_name, architect, pmc, version, pd_date, prepared_by, prepared_by_name, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            mysqli_stmt_bind_param($insMain, "siissssssiss",
                $pd_no, $site_id, $client_id,
                $project_name, $client_name,
                $architect, $pmc, $version, $pd_date,
                $employeeId, $employeeName, $remarks
            );
            if (!mysqli_stmt_execute($insMain)) {
                throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
            }
            
            $mainId = mysqli_insert_id($conn);
            mysqli_stmt_close($insMain);
            
            // Insert details
            $insDetail = mysqli_prepare($conn, "
                INSERT INTO pd_details 
                (pd_main_id, sl_no, stakeholder_type, company_name, contact_person, designation, mobile_number, email_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($stakeholder_types as $idx => $stakeholder_type) {
                $stakeholder_type = trim($stakeholder_type);
                if ($stakeholder_type === '') continue;
                
                $detail_sl_no = (int)($detail_sl_nos[$idx] ?? ($idx + 1));
                $company = trim($companies[$idx] ?? '');
                $contact_person = trim($contact_persons[$idx] ?? '');
                $designation = trim($designations[$idx] ?? '');
                $mobile = trim($mobiles[$idx] ?? '');
                $email = trim($emails[$idx] ?? '');
                
                mysqli_stmt_bind_param($insDetail, "iissssss", 
                    $mainId, $detail_sl_no, $stakeholder_type, $company, $contact_person, $designation, $mobile, $email);
                
                if (!mysqli_stmt_execute($insDetail)) {
                    throw new Exception("Failed to save stakeholder detail: " . mysqli_stmt_error($insDetail));
                }
            }
            mysqli_stmt_close($insDetail);
            
            mysqli_commit($conn);
            
            header("Location: pd.php?site_id=" . $site_id . "&saved=1&pid=" . $mainId);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}

// ---------------- Get Recent PD Records ----------------
$recent = [];
$st = mysqli_prepare($conn, "
    SELECT p.id, p.pd_no, p.pd_date, p.project_name, 
           COUNT(d.id) as stakeholder_count
    FROM pd_main p
    LEFT JOIN pd_details d ON d.pd_main_id = p.id
    WHERE p.prepared_by = ?
    GROUP BY p.id
    ORDER BY p.created_at DESC
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
$formPdDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>PD - Project Directory | TEK-C</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

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
        @media (max-width: 992px){
            .grid-2{ grid-template-columns: 1fr; }
        }

        .badge-pill{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 12px; border-radius:999px;
            border:1px solid #e5e7eb; background:#fff;
            font-weight:900; font-size:12px;
        }
        .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
        
        .table-details thead th {
            font-size: 12px;
            color: #6b7280;
            font-weight: 900;
            background: #f9fafb;
            white-space: nowrap;
        }
        .table-details td { vertical-align: middle; }
        
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
        
        .delete-detail-btn {
            cursor: pointer;
            color: #dc2626;
            transition: all 0.2s;
        }
        .delete-detail-btn:hover { color: #b91c1c; }
        
        .site-selector {
            background: #f9fafb;
            border-radius: 12px;
            padding: 8px 12px;
            font-weight: 750;
            font-size: 14px;
            border: 2px solid #e5e7eb;
            width: 100%;
        }
        
        .sl-no-input {
            width: 70px;
            text-align: center;
            background: #f9fafb;
            font-weight: 600;
        }
        .stakeholder-select { min-width: 200px; width: 100% !important; }
        
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .panel { padding: 12px !important; }
            .table-details { font-size: 12px; }
        }
        
        .select2-container--bootstrap-5 .select2-selection {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            min-height: 42px;
        }
        
        .select2-container--bootstrap-5 .select2-dropdown {
            z-index: 1060;
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
                        <h1 class="h-title">Project Directory (PD)</h1>
                        <p class="h-sub">Manage project stakeholders and their contact information</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
                        <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($_SESSION['designation'] ?? ''); ?></span>
                        <a href="pd_stakeholder_types.php" class="badge-pill text-decoration-none">
                            <i class="bi bi-tags"></i> Manage Types
                        </a>
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
                        <i class="bi bi-check-circle-fill me-2"></i> Project Directory submitted successfully!
                        <a href="report-pd-print.php?view=<?php echo $lastInsertedId; ?>" target="_blank" class="alert-link ms-3">
                            <i class="bi bi-printer"></i> Print/View PDF
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- PD FORM -->
                <form method="POST" autocomplete="off" id="pdForm">
                    <input type="hidden" name="submit_pd" value="1">
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
                                <input type="text" class="form-control" name="version" placeholder="e.g., R0, R1, V1, V2" value="R0">
                                <div class="small-muted mt-1">R0 (Initial), R1 (Revision 1), V1 (Version 1)</div>
                            </div>
                            <div>
                                <label class="form-label">PD Date</label>
                                <input type="date" class="form-control" name="pd_date" value="<?php echo e($formPdDate); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- PD DETAILS TABLE (Stakeholders) -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-people"></i></div>
                            <div>
                                <p class="sec-title mb-0">Project Stakeholders</p>
                                <p class="sec-sub mb-0">Key stakeholders involved in the project</p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-details" id="detailsTable">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">SL NO</th>
                                        <th style="min-width:200px;">STAKEHOLDERS <span class="text-danger">*</span></th>
                                        <th style="min-width:180px;">COMPANY</th>
                                        <th style="min-width:150px;">CONTACT PERSON</th>
                                        <th style="min-width:150px;">DESIGNATION</th>
                                        <th style="min-width:150px;">MOBILE / LANDLINE NO</th>
                                        <th style="min-width:200px;">EMAIL ID</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="detailsTableBody">
                                    <tr class="detail-row">
                                        <td>
                                            <input type="number" class="form-control sl-no-input" name="detail_sl_no[]" value="1" readonly style="background:#f9fafb; text-align:center;">
                                        </td>
                                        <td>
                                            <select class="form-select stakeholder-select" name="stakeholder_type[]" style="width:100%;">
                                                <option value="">-- Select Stakeholder --</option>
                                                <?php foreach ($stakeholderOptions as $option): ?>
                                                    <option value="<?php echo e($option); ?>"><?php echo e($option); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" name="company[]" placeholder="Company name">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" name="contact_person[]" placeholder="Contact person">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" name="designation[]" placeholder="Designation">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" name="mobile[]" placeholder="Mobile / Landline">
                                        </td>
                                        <td>
                                            <input type="email" class="form-control" name="email[]" placeholder="Email ID">
                                        </td>
                                        <td class="text-center">
                                            <i class="bi bi-trash delete-detail-btn" style="font-size:18px; cursor:pointer;"></i>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                            <div class="small-muted">
                                <i class="bi bi-info-circle"></i> Add all key stakeholders involved in this project
                            </div>
                            <button type="button" class="btn btn-primary" id="addDetailBtn" style="border-radius:12px;">
                                <i class="bi bi-plus-circle"></i> Add Stakeholder
                            </button>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3" placeholder="Any additional notes or comments..."></textarea>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-save"></i> Submit PD
                            </button>
                        </div>
                    </div>
                </form>

                <!-- RECENT PD SUBMISSIONS -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <p class="sec-title mb-0">Recent PD Submissions</p>
                            <p class="sec-sub mb-0">Your last 10 submissions</p>
                        </div>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="text-muted" style="font-weight:800; text-align:center; padding:20px;">
                            <i class="bi bi-inbox" style="font-size:48px; display:block; margin-bottom:10px;"></i>
                            No Project Directory submitted yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>PD No</th>
                                        <th>Date</th>
                                        <th>Project</th>
                                        <th>Stakeholders</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td><strong><?php echo e($r['pd_no']); ?></strong></td>
                                            <td><?php echo e($r['pd_date']); ?></td>
                                            <td><?php echo e($r['project_name']); ?></td>
                                            <td class="text-center"><?php echo $r['stakeholder_count']; ?></td>
                                            <td>
                                                <a href="report-pd-print.php?view=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
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

<!-- jQuery and Select2 JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>
<script>
$(document).ready(function () {

    // =========================
    // ADD NEW STAKEHOLDER (AJAX)
    // =========================
    async function addNewStakeholderType(typeName, selectElement) {

        const formData = new FormData();
        formData.append('ajax_action', 'add_stakeholder_type');
        formData.append('stakeholder_type', typeName);
        formData.append('description', '');

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {

                const $select = $(selectElement);

                if ($select.find("option[value='" + result.name + "']").length === 0) {
                    const newOption = new Option(result.name, result.name, true, true);
                    $select.append(newOption);
                }

                $select.val(result.name).trigger('change');

                showNotification('Stakeholder added: ' + result.name, 'success');

            } else {
                showNotification(result.message || 'Failed to add stakeholder', 'error');
            }

        } catch (err) {
            showNotification('Error: ' + err.message, 'error');
        }
    }

    // =========================
    // NOTIFICATION
    // =========================
    function showNotification(message, type) {

        var alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        var icon = type === 'success' ? 'check-circle' : 'exclamation-triangle';

        var html =
            '<div class="alert ' + alertClass + ' alert-dismissible fade show" role="alert" ' +
            'style="position:fixed; top:20px; right:20px; z-index:9999; min-width:300px;">' +
                '<i class="bi bi-' + icon + ' me-2"></i>' +
                message +
                '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';

        var $alert = $(html);
        $('body').append($alert);

        setTimeout(function () {
            $alert.alert('close');
        }, 3000);
    }

    // =========================
    // SELECT2 INIT (CLEAN + SAFE)
    // =========================
    function initSelect2(selectElement) {

        const $select = $(selectElement);

        if ($select.data('select2')) {
            $select.select2('destroy');
            $select.removeClass('select2-hidden-accessible');
            $select.removeAttr('data-select2-id');
            $select.next('.select2-container').remove();
        }

        $select.select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: '-- Select Stakeholder --',
            allowClear: true,
            tags: true,
            dropdownParent: $select.closest('.detail-row'),

            createTag: function (params) {
                const term = $.trim(params.term);
                if (!term) return null;

                return {
                    id: term,
                    text: term + ' (Add New)',
                    newOption: true
                };
            }
        });

        // EVENTS
        $select.off('select2:select').on('select2:select', function (e) {

            const data = e.params.data;

            if (data.newOption === true) {
                e.preventDefault();
                addNewStakeholderType(data.id.trim(), this);
            }
        });
    }

    // =========================
    // ROW MANAGEMENT
    // =========================
    function renumberRows() {
        $('#detailsTableBody .detail-row').each(function (index) {
            $(this).find('.sl-no-input').val(index + 1);
        });
    }

    function addDetailRow() {

        const $firstRow = $('#detailsTableBody .detail-row').first();
        const $templateSelect = $firstRow.find('.stakeholder-select');

        // destroy before clone
        if ($templateSelect.data('select2')) {
            $templateSelect.select2('destroy');
        }

        const $newRow = $firstRow.clone(false, false);

        // re-init original
        initSelect2($templateSelect);

        // clear inputs
        $newRow.find('input:not(.sl-no-input)').val('');

        const $select = $newRow.find('.stakeholder-select');

        $select.val(null);
        $select.removeAttr('data-select2-id');
        $select.removeClass('select2-hidden-accessible');
        $select.next('.select2-container').remove();

        $('#detailsTableBody').append($newRow);

        initSelect2($select);

        renumberRows();
    }

    function deleteDetailRow(button) {

        const $row = $(button).closest('.detail-row');
        const total = $('#detailsTableBody .detail-row').length;

        if (total === 1) {

            $row.find('input:not(.sl-no-input)').val('');
            const $select = $row.find('.stakeholder-select');

            if ($select.data('select2')) {
                $select.select2('destroy');
            }

            $select.val('');
            initSelect2($select);

        } else {

            const $select = $row.find('.stakeholder-select');

            if ($select.data('select2')) {
                $select.select2('destroy');
            }

            $row.remove();
            renumberRows();
        }
    }

    // =========================
    // SITE CHANGE
    // =========================
    function setupSiteChange() {

        $('#sitePicker').on('change', function () {

            const opt = $(this).find('option:selected');

            $('#site_id').val($(this).val());
            $('#client_id').val(opt.data('client-id'));
            $('#project_name').val(opt.data('project'));
            $('#client_name').val(opt.data('client'));

            $('#display_project').val(opt.data('project'));
            $('#display_client').val(opt.data('client'));
        });
    }

    // =========================
    // INIT
    // =========================
    function initialize() {

        $('.stakeholder-select').each(function () {
            initSelect2(this);
        });

        setupSiteChange();
        renumberRows();

        $('#sitePicker').trigger('change');
    }

    // =========================
    // EVENTS
    // =========================
    $('#addDetailBtn').on('click', function (e) {
        e.preventDefault();
        addDetailRow();
    });

    $(document).on('click', '.delete-detail-btn', function (e) {
        e.preventDefault();
        deleteDetailRow(this);
    });

    initialize();
});
</script>
</body>
</html>