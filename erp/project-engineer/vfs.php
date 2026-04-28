    <?php
    // vfs.php - Vendor Finalization Schedule (VFS) Submission Form

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

    // ---------------- Get All Packages from Master ----------------
    $packages = [];
    $pkgQuery = "SELECT id, package_name, category FROM vfs_packages WHERE is_active = 1 ORDER BY category, package_name";
    $pkgResult = mysqli_query($conn, $pkgQuery);
    if ($pkgResult) {
        $packages = mysqli_fetch_all($pkgResult, MYSQLI_ASSOC);
    }

    // ---------------- AJAX Handler for Adding Package ----------------
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_package') {
        header('Content-Type: application/json');
        
        $new_package_name = trim($_POST['package_name'] ?? '');
        $category = trim($_POST['category'] ?? 'Other');
        
        if (empty($new_package_name)) {
            echo json_encode(['success' => false, 'message' => 'Package name is required']);
            exit;
        }
        
        // Check if package already exists
        $checkSql = "SELECT id FROM vfs_packages WHERE package_name = ?";
        $checkStmt = mysqli_prepare($conn, $checkSql);
        mysqli_stmt_bind_param($checkStmt, "s", $new_package_name);
        mysqli_stmt_execute($checkStmt);
        mysqli_stmt_store_result($checkStmt);
        
        if (mysqli_stmt_num_rows($checkStmt) > 0) {
            $result = mysqli_stmt_get_result($checkStmt);
            $row = mysqli_fetch_assoc($result);
            echo json_encode([
                'success' => true, 
                'id' => $row['id'], 
                'name' => $new_package_name, 
                'exists' => true
            ]);
            mysqli_stmt_close($checkStmt);
            exit;
        }
        mysqli_stmt_close($checkStmt);
        
        // Insert new package
        $sql = "INSERT INTO vfs_packages (package_name, category, is_active) VALUES (?, ?, 1)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $new_package_name, $category);
        
        if (mysqli_stmt_execute($stmt)) {
            $new_id = mysqli_insert_id($conn);
            echo json_encode(['success' => true, 'id' => $new_id, 'name' => $new_package_name, 'exists' => false]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add package: ' . mysqli_error($conn)]);
        }
        mysqli_stmt_close($stmt);
        exit;
    }

    // ---------------- Create VFS Tables if not exists ----------------
    function createVfsTables($conn) {
        // Main table
        $mainTable = "CREATE TABLE IF NOT EXISTS `vfs_main` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `vfs_no` varchar(100) NOT NULL,
            `site_id` int(11) NOT NULL,
            `client_id` int(11) NOT NULL,
            `project_name` varchar(255) NOT NULL,
            `client_name` varchar(255) NOT NULL,
            `architect` varchar(255) DEFAULT NULL,
            `pmc` varchar(255) DEFAULT NULL,
            `version` varchar(100) DEFAULT NULL,
            `vfs_date` date NOT NULL,
            `prepared_by` int(11) NOT NULL,
            `prepared_by_name` varchar(150) NOT NULL,
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_vfs_no` (`vfs_no`),
            KEY `idx_site` (`site_id`),
            KEY `idx_client` (`client_id`),
            KEY `idx_prepared_by` (`prepared_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        mysqli_query($conn, $mainTable);
        
        // Details table
        $detailsTable = "CREATE TABLE IF NOT EXISTS `vfs_details` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `vfs_main_id` int(11) NOT NULL,
            `sl_no` int(11) NOT NULL,
            `package_id` int(11) NOT NULL,
            `package_name` varchar(255) NOT NULL,
            `duration_days` int(11) DEFAULT NULL,
            `start_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_vfs_main` (`vfs_main_id`),
            KEY `idx_package` (`package_id`),
            CONSTRAINT `fk_vfs_details_main` FOREIGN KEY (`vfs_main_id`) REFERENCES `vfs_main` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        mysqli_query($conn, $detailsTable);
    }

    createVfsTables($conn);

    // ---------------- Generate VFS Number ----------------
    function generateVfsNo($conn, $siteId) {
        $year = date('Y');
        $month = date('m');
        $prefix = "VFS/{$siteId}/{$year}{$month}/";
        
        $st = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM vfs_main WHERE vfs_no LIKE ?");
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

    // ---------------- Submit VFS ----------------
    $error = '';
    $success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vfs'])) {
        $site_id = (int)($_POST['site_id'] ?? 0);
        $client_id = (int)($_POST['client_id'] ?? 0);
        $project_name = trim((string)($_POST['project_name'] ?? ''));
        $client_name = trim((string)($_POST['client_name'] ?? ''));
        $architect = trim($_POST['architect'] ?? '');
        $pmc = trim($_POST['pmc'] ?? '');
        $version = trim($_POST['version'] ?? '');
        $vfs_date = trim($_POST['vfs_date'] ?? date('Y-m-d'));
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Get rows data
        $sl_nos = $_POST['sl_no'] ?? [];
        $package_ids = $_POST['package_id'] ?? [];
        $package_names = $_POST['package_name'] ?? [];
        $duration_days = $_POST['duration_days'] ?? [];
        $date_starts = $_POST['date_start'] ?? [];
        $date_ends = $_POST['date_end'] ?? [];
        $item_remarks = $_POST['item_remarks'] ?? [];
        
        // Validate at least one row with package selected
        $hasValidRow = false;
        foreach ($package_ids as $idx => $pkg_id) {
            if ((int)$pkg_id > 0) {
                $hasValidRow = true;
                break;
            }
        }
        
        if ($site_id <= 0) $error = "Please select a site.";
        if (!$hasValidRow) $error = "Please select at least one package.";
        
        if ($error === '') {
            $vfs_no = generateVfsNo($conn, $site_id);
            
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Insert into main table
                $insMain = mysqli_prepare($conn, "
                    INSERT INTO vfs_main 
                    (vfs_no, site_id, client_id, project_name, client_name, architect, pmc, version, vfs_date, prepared_by, prepared_by_name, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                mysqli_stmt_bind_param($insMain, "siissssssiss",
                    $vfs_no, $site_id, $client_id,
                    $project_name, $client_name,
                    $architect, $pmc, $version, $vfs_date,
                    $employeeId, $employeeName, $remarks
                );
                if (!mysqli_stmt_execute($insMain)) {
                    throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
                }
                
                $mainId = mysqli_insert_id($conn);
                mysqli_stmt_close($insMain);
                
                // Insert into details table for each package
                $insDetail = mysqli_prepare($conn, "
                    INSERT INTO vfs_details 
                    (vfs_main_id, sl_no, package_id, package_name, duration_days, start_date, end_date, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $rowCount = 0;
                foreach ($package_ids as $idx => $pkg_id) {
                    $pkg_id = (int)$pkg_id;
                    if ($pkg_id <= 0) continue;
                    
                    $sl_no = (int)($sl_nos[$idx] ?? ($idx + 1));
                    $package_name = trim($package_names[$idx] ?? '');
                    $duration = (int)($duration_days[$idx] ?? 0);
                    $start_date = trim($date_starts[$idx] ?? '');
                    $end_date = trim($date_ends[$idx] ?? '');
                    $item_remark = trim($item_remarks[$idx] ?? '');
                    
                    mysqli_stmt_bind_param($insDetail, "iiisssss", $mainId, $sl_no, $pkg_id, $package_name, $duration, $start_date, $end_date, $item_remark);
                    
                    if (!mysqli_stmt_execute($insDetail)) {
                        throw new Exception("Failed to save detail row: " . mysqli_stmt_error($insDetail));
                    }
                    $rowCount++;
                }
                mysqli_stmt_close($insDetail);
                
                mysqli_commit($conn);
                
                header("Location: vfs.php?site_id=" . $site_id . "&saved=1&vid=" . $mainId);
                exit;
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
            }
        }
    }

    // ---------------- Get Recent VFS Records ----------------
    $recent = [];
    $st = mysqli_prepare($conn, "
        SELECT v.id, v.vfs_no, v.vfs_date, v.project_name, COUNT(d.id) as package_count
        FROM vfs_main v
        LEFT JOIN vfs_details d ON d.vfs_main_id = v.id
        WHERE v.prepared_by = ?
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
    $formVfsDate = date('Y-m-d');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>VFS - Vendor Finalization Schedule | TEK-C</title>

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
            
            .table-vfs thead th {
                font-size: 12px;
                color: #6b7280;
                font-weight: 900;
                background: #f9fafb;
                white-space: nowrap;
            }
            .table-vfs td { vertical-align: middle; }
            
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
            
            .package-select {
                min-width: 220px;
                width: 100% !important;
            }
            
            @media (max-width: 768px) {
                .content-scroll { padding: 12px 10px 12px !important; }
                .panel { padding: 12px !important; }
                .table-vfs { font-size: 12px; }
                .date-input, .duration-input, .sl-no-input, .package-select { min-width: auto; width: 100% !important; }
            }
            
            .select2-container--bootstrap-5 .select2-selection {
                border: 2px solid #e5e7eb;
                border-radius: 12px;
                min-height: 42px;
            }
            .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
                padding: 8px 12px;
                font-weight: 750;
                font-size: 14px;
            }
            .select2-container--bootstrap-5 .select2-dropdown {
                border: 2px solid #e5e7eb;
                border-radius: 12px;
            }
            .select2-container--bootstrap-5 .select2-results__option--highlighted {
                background: var(--blue);
            }
            
            .toast-container {
                z-index: 1100;
            }

            /* Fix for select2 in table */
            .select2-container {
                width: 100% !important;
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
                            <h1 class="h-title">Vendor Finalization Schedule (VFS)</h1>
                            <p class="h-sub">Vendor finalization schedule with package duration details</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
                            <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($_SESSION['designation'] ?? ''); ?></span>
                            <a href="vfs_packages.php" class="badge-pill text-decoration-none">
                                <i class="bi bi-box"></i> Manage Packages
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
                            <i class="bi bi-check-circle-fill me-2"></i> VFS submitted successfully!
                            <a href="report-vfs-print.php?view=<?php echo $lastInsertedId; ?>" target="_blank" class="alert-link ms-3">
                                <i class="bi bi-printer"></i> Print/View PDF
                            </a>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- VFS FORM -->
                    <form method="POST" autocomplete="off" id="vfsForm">
                        <input type="hidden" name="submit_vfs" value="1">
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
                                    <input type="text" class="form-control" name="version" placeholder="e.g., R0, R1, V1, V2" value="R0">
                                    <div class="small-muted mt-1">R0 (Initial), R1 (Revision 1), V1 (Version 1)</div>
                                </div>
                                <div>
                                    <label class="form-label">VFS Date</label>
                                    <input type="date" class="form-control" name="vfs_date" value="<?php echo e($formVfsDate); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- VFS TABLE (Dynamic Rows) -->
                        <div class="panel">
                            <div class="table-responsive">
                                <table class="table table-bordered table-vfs" id="vfsTable">
                                    <thead>
                                        <tr>
                                            <th style="width:80px;">SL NO</th>
                                            <th style="min-width:280px;">PACKAGE <span class="text-danger">*</span></th>
                                            <th style="width:130px;">DURATION (DAYS)</th>
                                            <th style="width:150px;">DATE START</th>
                                            <th style="width:150px;">END</th>
                                            <th style="min-width:180px;">REMARK</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="vfsTableBody">
                                        <tr class="vfs-row">
                                            <td>
                                                <input type="number" class="form-control sl-no-input" name="sl_no[]" value="1" readonly style="background:#f9fafb; text-align:center;">
                                            </td>
                                            <td>
                                                <select class="form-select package-select" name="package_id[]">
                                                    <option value="">-- Select or Type Package --</option>
                                                    <?php 
                                                    $currentCategory = '';
                                                    foreach ($packages as $pkg): 
                                                        $category = $pkg['category'] ?? 'Other';
                                                        if ($currentCategory != $category): 
                                                            if ($currentCategory != ''): ?>
                                                                </optgroup>
                                                            <?php endif; ?>
                                                            <optgroup label="<?php echo e($category); ?>">
                                                            <?php 
                                                            $currentCategory = $category;
                                                        endif; 
                                                    ?>
                                                        <option value="<?php echo $pkg['id']; ?>" data-pkg-name="<?php echo e($pkg['package_name']); ?>">
                                                            <?php echo e($pkg['package_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                    <?php if ($currentCategory != ''): ?>
                                                        </optgroup>
                                                    <?php endif; ?>
                                                </select>
                                                <input type="hidden" name="package_name[]" class="package-name-hidden" value="">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control duration-input" name="duration_days[]" placeholder="Days" min="0" value="0">
                                            </td>
                                            <td>
                                                <input type="date" class="form-control date-input date-start" name="date_start[]" placeholder="Start date">
                                            </td>
                                            <td>
                                                <input type="date" class="form-control date-input date-end" name="date_end[]" placeholder="End date" readonly style="background:#f9fafb;">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control" name="item_remarks[]" placeholder="Any remarks">
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
                                    <i class="bi bi-info-circle"></i> At least one package must be selected. End date auto-calculates from start date + duration. Type new package name and click to add.
                                </div>
                                <button type="button" class="btn btn-primary" id="addRowBtn" style="border-radius:12px;">
                                    <i class="bi bi-plus-circle"></i> Add Package
                                </button>
                            </div>

                            <div class="mt-3">
                                <label class="form-label">General Remarks</label>
                                <textarea class="form-control" name="remarks" rows="3" placeholder="Any additional notes or comments..."></textarea>
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button type="submit" class="btn-primary-tek">
                                    <i class="bi bi-save"></i> Submit VFS
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- RECENT VFS SUBMISSIONS -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                            <div>
                                <p class="sec-title mb-0">Recent VFS Submissions</p>
                                <p class="sec-sub mb-0">Your last 10 submissions</p>
                            </div>
                        </div>

                        <?php if (empty($recent)): ?>
                            <div class="text-muted" style="font-weight:800; text-align:center; padding:20px;">
                                <i class="bi bi-inbox" style="font-size: 48px; display:block; margin-bottom:10px;"></i>
                                No VFS submitted yet.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead>
                                        <tr>
                                            <th>VFS No</th>
                                            <th>Date</th>
                                            <th>Project</th>
                                            <th>Packages</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent as $r): ?>
                                            <tr>
                                                <td><strong><?php echo e($r['vfs_no']); ?></strong></td>
                                                <td><?php echo e($r['vfs_date']); ?></td>
                                                <td><?php echo e($r['project_name']); ?></td>
                                                <td class="text-center"><?php echo $r['package_count']; ?></td>
                                                <td>
                                                    <a href="report-vfs-print.php?view=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
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
$(document).ready(function() {
    
    // Global flag to prevent multiple initializations
    let isInitializing = false;
    
    // =========================
    // ADD NEW PACKAGE (AJAX)
    // =========================
    async function addNewPackage(packageName, selectElement) {
        const formData = new FormData();
        formData.append('ajax_action', 'add_package');
        formData.append('package_name', packageName);
        formData.append('category', 'Other');

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                const $select = $(selectElement);
                const row = $select.closest('.vfs-row');
                
                // Add new option
                const newOption = new Option(result.name, result.id, false, true);
                $select.append(newOption);
                $select.val(result.id).trigger('change');
                
                row.find('.package-name-hidden').val(result.name);
                
                // Show success message
                const msg = result.exists ? `Package "${result.name}" already exists and selected` : `Package "${result.name}" added successfully`;
                showNotification(msg, 'success');
            } else {
                showNotification(result.message || 'Failed to add package', 'error');
            }
        } catch (error) {
            showNotification('Error: ' + error.message, 'error');
        }
    }
    
    // Simple notification function
    function showNotification(message, type) {
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const $alert = $(`<div class="alert ${alertClass} alert-dismissible fade show" role="alert" style="position:fixed; top:20px; right:20px; z-index:9999; min-width:300px;">
            <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>`);
        
        $('body').append($alert);
        setTimeout(() => $alert.alert('close'), 3000);
    }
    
    function initSelect2(selectElement) {
        const $select = $(selectElement);

        // HARD reset if already initialized (safe way)
        if ($select.data('select2')) {
            $select.select2('destroy');
            // Remove any leftover Select2 artifacts
            $select.removeClass('select2-hidden-accessible');
            $select.removeAttr('data-select2-id');
            $select.removeAttr('aria-hidden');
            $select.next('.select2-container').remove();
        }

        $select.select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: '-- Select or Type Package --',
            allowClear: true,
            tags: true,
            dropdownParent: $select.closest('.vfs-row'),

            createTag: function (params) {
                const term = $.trim(params.term);
                if (!term) return null;

                const exists = $select.find('option').filter(function () {
                    return $(this).text().toLowerCase() === term.toLowerCase();
                }).length > 0;

                if (exists) return null;

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
            const row = $(this).closest('.vfs-row');

            if (data.newOption === true) {
                e.preventDefault();
                addNewPackage(data.id.trim(), this);
            } else {
                const text = $(this).find('option:selected').text();
                row.find('.package-name-hidden').val(text);
            }
        });

        $select.off('select2:clear').on('select2:clear', function () {
            $(this).closest('.vfs-row').find('.package-name-hidden').val('');
        });
    }
    
    // =========================
    // INITIALIZE ALL SELECT2 IN TABLE
    // =========================
    function initAllSelect2() {
        if (isInitializing) return;
        isInitializing = true;
        
        console.log('Initializing all Select2 elements...');
        
        // Get all package select elements
        const $selects = $('.package-select');
        console.log('Found', $selects.length, 'select elements');
        
        $selects.each(function(index) {
            console.log('Initializing select', index + 1);
            initSelect2(this);
        });
        
        isInitializing = false;
        console.log('All Select2 elements initialized');
    }
    
    // =========================
    // DATE CALCULATION
    // =========================
    function setupDateCalculation(row) {
        const $row = $(row);
        const $startDate = $row.find('.date-start');
        const $duration = $row.find('.duration-input');
        const $endDate = $row.find('.date-end');
        
        function calculateEndDate() {
            const start = $startDate.val();
            const days = parseInt($duration.val());
            
            if (start && days > 0) {
                const date = new Date(start);
                date.setDate(date.getDate() + days);
                const formattedDate = date.toISOString().split('T')[0];
                $endDate.val(formattedDate);
            } else {
                $endDate.val('');
            }
        }
        
        $startDate.off('change').on('change', calculateEndDate);
        $duration.off('input').on('input', calculateEndDate);
    }
    
    // =========================
    // ROW MANAGEMENT
    // =========================
    function renumberRows() {
        $('#vfsTableBody .vfs-row').each(function(index) {
            $(this).find('.sl-no-input').val(index + 1);
        });
    }
    
    function addNewRow() {
    const $firstRow = $('#vfsTableBody .vfs-row').first();

    const $templateSelect = $firstRow.find('.package-select');

    // destroy before clone
    if ($templateSelect.data('select2')) {
        $templateSelect.select2('destroy');
    }

    // clone WITHOUT events
    const $newRow = $firstRow.clone(false, false);

    // re-init original row
    initSelect2($templateSelect);

    // clean inputs
    $newRow.find('input:not(.sl-no-input)').val('');
    $newRow.find('.duration-input').val('0');
    $newRow.find('.date-end').val('');
    $newRow.find('.package-name-hidden').val('');

    // clean select
    const $select = $newRow.find('.package-select');
    $select.val(null);
    $select.removeAttr('data-select2-id');
    $select.removeClass('select2-hidden-accessible');
    $select.next('.select2-container').remove();

    // append first
    $('#vfsTableBody').append($newRow);

    // init fresh select2
    initSelect2($select);

    setupDateCalculation($newRow);
    renumberRows();
}
    
    function deleteRow(button) {
        const $row = $(button).closest('.vfs-row');
        const totalRows = $('#vfsTableBody .vfs-row').length;

        if (totalRows === 1) {
            // Clear the row instead of deleting it
            $row.find('input').not('.sl-no-input').val('');
            $row.find('.duration-input').val('0');
            $row.find('.package-name-hidden').val('');

            const $select = $row.find('.package-select');

            if ($select.data('select2')) {
                $select.select2('destroy');
            }
            
            // Remove Select2 artifacts
            $select.removeClass('select2-hidden-accessible');
            $select.removeAttr('data-select2-id');
            $select.removeAttr('aria-hidden');
            $select.next('.select2-container').remove();
            
            $select.val('');
            initSelect2($select);

        } else {
            // Destroy Select2 before removing the row
            const $selectToDestroy = $row.find('.package-select');
            if ($selectToDestroy.data('select2')) {
                $selectToDestroy.select2('destroy');
            }
            $row.remove();
            renumberRows();
        }
    }
    
    // =========================
    // SITE CHANGE HANDLER
    // =========================
    function setupSiteChange() {
        $('#sitePicker').on('change', function() {
            const $this = $(this);
            const selectedOption = $this.find('option:selected');
            
            $('#site_id').val($this.val());
            $('#client_id').val(selectedOption.data('client-id'));
            $('#project_name').val(selectedOption.data('project'));
            $('#client_name').val(selectedOption.data('client'));
            $('#display_project').val(selectedOption.data('project'));
            $('#display_client').val(selectedOption.data('client'));
        });
    }
    
    // =========================
    // PREVENT ENTER KEY FROM SUBMITTING FORM
    // =========================
    function preventEnterSubmit() {
        $(document).on('keydown', '.select2-search__field', function(e) {
            if (e.keyCode === 13) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        });
    }
    
    // =========================
    // UPDATE HIDDEN FIELDS BEFORE SUBMIT
    // =========================
    function updateHiddenFieldsBeforeSubmit() {
        $('#vfsForm').on('submit', function() {
            $('.vfs-row').each(function() {
                const $select = $(this).find('.package-select');
                const selectedText = $select.find('option:selected').text();
                if (selectedText && selectedText !== '-- Select or Type Package --' && selectedText !== '') {
                    $(this).find('.package-name-hidden').val(selectedText);
                }
            });
            return true;
        });
    }
    
    // =========================
    // INITIALIZE EVERYTHING
    // =========================
    function initialize() {
        console.log('Clean initialization start');

        // Initialize Select2 ONLY once for existing rows
        $('.package-select').each(function() {
            initSelect2(this);
        });

        // Setup date calculation
        $('.vfs-row').each(function() {
            setupDateCalculation(this);
        });

        setupSiteChange();
        preventEnterSubmit();
        updateHiddenFieldsBeforeSubmit();
        renumberRows();

        $('#sitePicker').trigger('change');

        console.log('Clean initialization done');
    }
    
    // =========================
    // EVENT BINDINGS
    // =========================
    
    // Add row button
    $('#addRowBtn').off('click').on('click', function(e) {
        e.preventDefault();
        addNewRow();
    });
    
    // Delete row (using event delegation)
    $(document).off('click', '.delete-row-btn').on('click', '.delete-row-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        deleteRow(this);
    });
    
    // Initialize on page load
    initialize();
    
});
</script>
    </body>
    </html>