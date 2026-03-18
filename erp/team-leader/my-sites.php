<?php
// sites.php - TEK-C Sites Management Page (Team Lead View Only)
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

$employeeId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// ---------------- HELPERS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) return false;
    mysqli_stmt_bind_param($st, "ss", $table, $column);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $ok = (bool)mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    return $ok;
}

function safeYmd($v) {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '';
    return $v;
}

function fmtDate($ymd) {
    $ymd = safeYmd($ymd);
    if ($ymd === '') return '—';
    $ts = strtotime($ymd);
    return $ts ? date('d M Y', $ts) : e($ymd);
}

function projectHealthBadge($start, $end) {
    $today = date('Y-m-d');
    $start = safeYmd($start);
    $end   = safeYmd($end);

    if ($start !== '' && $start > $today) {
        return ['Upcoming', 'upcoming', 'bi-calendar2-week'];
    }
    if ($end !== '' && $end < $today) {
        return ['Delayed', 'delayed', 'bi-exclamation-triangle-fill'];
    }

    // Check if near completion (within 30 days)
    if ($end !== '') {
        $d1 = new DateTime($today);
        $d2 = new DateTime($end);
        $diff = (int)$d1->diff($d2)->format('%r%a');
        if ($diff >= 0 && $diff <= 30) {
            return ['Near Completion', 'nearcomplete', 'bi-hourglass-split'];
        }
    }
    return ['Active', 'ontrack', 'bi-check2-circle'];
}

function getProjectStatusCounts($sites) {
    $counts = ['Active' => 0, 'Near Completion' => 0, 'Delayed' => 0, 'Upcoming' => 0];
    foreach ($sites as $site) {
        [$label] = projectHealthBadge($site['start_date'] ?? '', $site['expected_completion_date'] ?? '');
        if (isset($counts[$label])) $counts[$label]++;
        else $counts['Active']++;
    }
    return $counts;
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, designation, department FROM employees WHERE id=? LIMIT 1");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $empRow = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
}
$employeeName = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');
$isTeamLead = ($designation === 'team lead');

// ---------------- Filters ----------------
$searchTerm = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';

// Allowed sort columns
$allowedSorts = ['project_name', 'client_name', 'start_date', 'expected_completion_date', 'created_at'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'created_at';
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// ---------------- Get Team Lead's Sites with Details ----------------
$hasTeamLeadCol = hasColumn($conn, 'sites', 'team_lead_employee_id');

// Base query with all joins - ONLY for team lead's sites
$baseQuery = "
    SELECT 
        s.id, 
        s.project_name, 
        s.project_code,
        s.project_type,
        s.project_location,
        s.scope_of_work,
        s.contract_value,
        s.start_date,
        s.expected_completion_date,
        s.latitude,
        s.longitude,
        s.location_radius,
        s.created_at,
        s.updated_at,
        s.deleted_at,
        s.agreement_number,
        s.agreement_date,
        s.work_order_date,
        s.authorized_signatory_name,
        s.authorized_signatory_contact,
        s.contact_person_designation,
        s.contact_person_email,
        s.approval_authority,
        s.site_in_charge_client_side,
        c.id as client_id,
        c.client_name,
        c.mobile_number as client_phone,
        c.email as client_email,
        c.company_name as client_company,
        c.office_address as client_address,
        c.gst_number as client_gst,
        c.pan_number as client_pan,
        m.id as manager_id,
        m.full_name as manager_name,
        m.email as manager_email,
        m.mobile_number as manager_phone,
        tl.id as team_lead_id,
        tl.full_name as team_lead_name,
        tl.email as team_lead_email
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    LEFT JOIN employees m ON m.id = s.manager_employee_id
    LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id
    WHERE s.deleted_at IS NULL
";

// Add team lead filter
if ($hasTeamLeadCol) {
    $baseQuery .= " AND s.team_lead_employee_id = ?";
} else {
    // Fallback if column doesn't exist
    die("Team lead column not found in sites table.");
}

$countQuery = "
    SELECT COUNT(DISTINCT s.id) as total
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.deleted_at IS NULL AND s.team_lead_employee_id = ?
";

$params = [$employeeId];
$types = "i";

// Apply search filter
if (!empty($searchTerm)) {
    $baseQuery .= " AND (s.project_name LIKE ? OR s.project_code LIKE ? OR c.client_name LIKE ? OR s.project_location LIKE ?)";
    $countQuery .= " AND (s.project_name LIKE ? OR s.project_code LIKE ? OR c.client_name LIKE ? OR s.project_location LIKE ?)";
    $searchWildcard = "%$searchTerm%";
    $params = array_merge($params, [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard]);
    $types .= "ssss";
}

// Apply type filter
if (!empty($typeFilter)) {
    $baseQuery .= " AND s.project_type = ?";
    $countQuery .= " AND s.project_type = ?";
    $params[] = $typeFilter;
    $types .= "s";
}

// Get total count for pagination
$totalStmt = mysqli_prepare($conn, $countQuery);
if ($totalStmt) {
    // First parameter is employee_id, then search params if any
    $totalParams = [$employeeId];
    $totalTypes = "i";
    
    if (!empty($searchTerm)) {
        $totalParams = array_merge($totalParams, [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard]);
        $totalTypes .= "ssss";
    }
    if (!empty($typeFilter)) {
        $totalParams[] = $typeFilter;
        $totalTypes .= "s";
    }
    
    if (!empty($totalParams)) {
        mysqli_stmt_bind_param($totalStmt, $totalTypes, ...$totalParams);
    }
    mysqli_stmt_execute($totalStmt);
    $totalResult = mysqli_stmt_get_result($totalStmt);
    $totalRow = mysqli_fetch_assoc($totalResult);
    $totalSites = $totalRow['total'];
    mysqli_stmt_close($totalStmt);
} else {
    $totalSites = 0;
}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Add sorting and pagination to main query
$baseQuery .= " ORDER BY s.$sortBy $sortOrder LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

// Execute main query
$sites = [];
$st = mysqli_prepare($conn, $baseQuery);
if ($st) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($st, $types, ...$params);
    }
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
}

// Get unique project types for filter (from team lead's sites only)
$types = [];
$typesQuery = "
    SELECT DISTINCT s.project_type 
    FROM sites s 
    WHERE s.deleted_at IS NULL 
    AND s.team_lead_employee_id = ?
    ORDER BY s.project_type
";
$typesStmt = mysqli_prepare($conn, $typesQuery);
if ($typesStmt) {
    mysqli_stmt_bind_param($typesStmt, "i", $employeeId);
    mysqli_stmt_execute($typesStmt);
    $typesResult = mysqli_stmt_get_result($typesStmt);
    while ($row = mysqli_fetch_assoc($typesResult)) {
        $types[] = $row;
    }
    mysqli_stmt_close($typesStmt);
}

// Get project engineers for each site
$siteEngineers = [];
if (!empty($sites)) {
    $siteIds = array_column($sites, 'id');
    $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
    $engineerQuery = "
        SELECT spe.site_id, e.id, e.full_name, e.designation, e.photo, e.email, e.mobile_number
        FROM site_project_engineers spe
        INNER JOIN employees e ON e.id = spe.employee_id
        WHERE spe.site_id IN ($placeholders) AND e.employee_status = 'active'
    ";
    $st = mysqli_prepare($conn, $engineerQuery);
    $types = str_repeat('i', count($siteIds));
    $st->bind_param($types, ...$siteIds);
    mysqli_stmt_execute($st);
    $engRes = mysqli_stmt_get_result($st);
    while ($eng = mysqli_fetch_assoc($engRes)) {
        $siteEngineers[$eng['site_id']][] = $eng;
    }
    mysqli_stmt_close($st);
}

// Get status counts for current filter set
$statusCounts = getProjectStatusCounts($sites);

// Calculate pagination
$totalPages = ceil($totalSites / $perPage);
$paginationRange = 5;
$startPage = max(1, $page - floor($paginationRange / 2));
$endPage = min($totalPages, $startPage + $paginationRange - 1);
if ($endPage - $startPage + 1 < $paginationRange) {
    $startPage = max(1, $endPage - $paginationRange + 1);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Sites (Team Lead) - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <!-- Select2 for better dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px 22px 14px; }

        .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 16px 16px 12px; height: 100%; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .panel-title { font-weight: 900; font-size: 20px; color: #1f2937; margin: 0; }
        .panel-menu { width: 36px; height: 36px; border-radius: 12px; border: 1px solid var(--border); background: #fff; display: grid; place-items: center; color: #6b7280; }
        .panel-menu:hover { background: #f3f4f6; }

        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
            padding: 14px 16px; height: 90px; display: flex; align-items: center; gap: 14px; }
        .stat-ic { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; color: #fff; font-size: 20px; flex: 0 0 auto; }
        .stat-ic.blue { background: var(--blue); }
        .stat-ic.green { background: var(--green); }
        .stat-ic.orange { background: var(--orange); }
        .stat-ic.red { background: var(--red); }
        .stat-label { color: #4b5563; font-weight: 750; font-size: 13px; }
        .stat-value { font-size: 30px; font-weight: 900; line-height: 1; margin-top: 2px; }

        .filter-section { background: #fff; border-radius: var(--radius); border: 1px solid var(--border); padding: 16px; margin-bottom: 20px; }

        .status-badge { padding: 6px 12px; border-radius: 999px; font-weight: 800; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; }
        .status-badge.ontrack { color: var(--green); background: rgba(39,174,96,.12); border: 1px solid rgba(39,174,96,.18); }
        .status-badge.delayed { color: var(--red); background: rgba(235,87,87,.12); border: 1px solid rgba(235,87,87,.18); }
        .status-badge.upcoming { color: #b7791f; background: rgba(242,201,76,.20); border: 1px solid rgba(242,201,76,.28); }
        .status-badge.nearcomplete { color: var(--blue); background: rgba(47,128,237,.12); border: 1px solid rgba(47,128,237,.18); }

        .table thead th { font-size: 12px; letter-spacing: .2px; color: #6b7280; font-weight: 800; border-bottom: 1px solid var(--border)!important; }
        .table td { vertical-align: middle; border-color: var(--border); font-weight: 650; color: #374151; padding-top: 14px; padding-bottom: 14px; }

        .btn-sort { color: #6b7280; text-decoration: none; font-weight: 800; }
        .btn-sort:hover { color: #374151; }
        .btn-sort.active { color: var(--blue); }
        .btn-sort i { font-size: 12px; margin-left: 4px; }

        .pagination .page-link { border: 1px solid var(--border); color: #4b5563; font-weight: 700; padding: 8px 14px; }
        .pagination .page-item.active .page-link { background: var(--blue); border-color: var(--blue); color: #fff; }
        .pagination .page-link:hover { background: #f3f4f6; }

        .action-btn { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border); 
            background: #fff; color: #6b7280; display: inline-flex; align-items: center; justify-content: center; margin: 0 2px; }
        .action-btn:hover { background: #f3f4f6; color: #374151; }

        /* Modal Styles */
        .modal-content { border: none; border-radius: var(--radius); }
        .modal-header { background: #f9fafb; border-bottom: 1px solid var(--border); }
        .modal-title { font-weight: 900; color: #1f2937; }
        .modal-body { padding: 24px; }
        .info-section { margin-bottom: 24px; }
        .info-section h6 { font-weight: 900; color: #1f2937; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .info-item { display: flex; flex-direction: column; }
        .info-label { font-weight: 800; color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .info-value { font-weight: 800; color: #1f2937; font-size: 14px; }
        .info-value.full-width { grid-column: span 2; }
        
        .team-member { display: flex; align-items: center; gap: 12px; padding: 8px; background: #f9fafb; border-radius: var(--radius); margin-bottom: 8px; }
        .member-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--yellow); display: flex; align-items: center; justify-content: center; font-weight: 900; color: #1f2937; }
        .member-details { flex: 1; }
        .member-name { font-weight: 900; color: #1f2937; font-size: 14px; }
        .member-designation { font-weight: 650; color: #6b7280; font-size: 12px; }
        .member-contact { font-size: 11px; color: #6b7280; margin-top: 2px; }
        
        .badge-geo { background: #e5e7eb; color: #4b5563; font-weight: 700; padding: 4px 8px; border-radius: 4px; font-size: 11px; }

        .team-lead-badge { background: var(--yellow); color: #1f2937; font-weight: 800; padding: 2px 8px; border-radius: 4px; font-size: 11px; margin-left: 8px; }

        @media (max-width: 991.98px) {
            .content-scroll { padding: 18px; }
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

                    <!-- Page Header -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                        <div>
                            <h2 class="h3 mb-1" style="font-weight: 900; color: #1f2937;">
                                My Sites 
                                <span class="team-lead-badge">Team Lead</span>
                            </h2>
                            <p class="text-muted" style="font-weight: 650;">Sites where you are assigned as Team Lead</p>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-building"></i></div>
                                <div>
                                    <div class="stat-label">Total Sites</div>
                                    <div class="stat-value"><?php echo $totalSites; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <div class="stat-label">Active</div>
                                    <div class="stat-value"><?php echo $statusCounts['Active'] ?? 0; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="stat-label">Near Completion</div>
                                    <div class="stat-value"><?php echo $statusCounts['Near Completion'] ?? 0; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                                <div>
                                    <div class="stat-label">Delayed</div>
                                    <div class="stat-value"><?php echo $statusCounts['Delayed'] ?? 0; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent border-end-0" style="border-color: var(--border);">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control border-start-0 ps-0" 
                                           name="search" 
                                           placeholder="Search by project, client, location..."
                                           value="<?php echo e($searchTerm); ?>"
                                           style="border-color: var(--border); font-weight: 650;">
                                </div>
                            </div>
                            <div class="col-12 col-md-3">
                                <select class="form-select select2-filter" name="type" style="border-color: var(--border); font-weight: 650;">
                                    <option value="">All Types</option>
                                    <?php foreach ($types as $t): ?>
                                    <option value="<?php echo e($t['project_type']); ?>" <?php echo $typeFilter === $t['project_type'] ? 'selected' : ''; ?>>
                                        <?php echo e($t['project_type']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary flex-grow-1" style="font-weight: 800;">
                                        <i class="bi bi-funnel me-2"></i>Apply Filters
                                    </button>
                                    <a href="sites.php" class="btn btn-outline-secondary" style="font-weight: 800; border-color: var(--border);">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Sort Bar -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <span style="font-weight: 800; color: #6b7280;">Sort by:</span>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'project_name', 'order' => ($sortBy === 'project_name' && $sortOrder === 'DESC') ? 'ASC' : 'DESC'])); ?>" 
                               class="btn-sort <?php echo $sortBy === 'project_name' ? 'active' : ''; ?>">
                                Project Name
                                <?php if ($sortBy === 'project_name'): ?>
                                <i class="bi bi-arrow-<?php echo $sortOrder === 'DESC' ? 'down' : 'up'; ?>"></i>
                                <?php endif; ?>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'client_name', 'order' => ($sortBy === 'client_name' && $sortOrder === 'DESC') ? 'ASC' : 'DESC'])); ?>" 
                               class="btn-sort <?php echo $sortBy === 'client_name' ? 'active' : ''; ?>">
                                Client
                                <?php if ($sortBy === 'client_name'): ?>
                                <i class="bi bi-arrow-<?php echo $sortOrder === 'DESC' ? 'down' : 'up'; ?>"></i>
                                <?php endif; ?>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'start_date', 'order' => ($sortBy === 'start_date' && $sortOrder === 'DESC') ? 'ASC' : 'DESC'])); ?>" 
                               class="btn-sort <?php echo $sortBy === 'start_date' ? 'active' : ''; ?>">
                                Start Date
                                <?php if ($sortBy === 'start_date'): ?>
                                <i class="bi bi-arrow-<?php echo $sortOrder === 'DESC' ? 'down' : 'up'; ?>"></i>
                                <?php endif; ?>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'expected_completion_date', 'order' => ($sortBy === 'expected_completion_date' && $sortOrder === 'DESC') ? 'ASC' : 'DESC'])); ?>" 
                               class="btn-sort <?php echo $sortBy === 'expected_completion_date' ? 'active' : ''; ?>">
                                End Date
                                <?php if ($sortBy === 'expected_completion_date'): ?>
                                <i class="bi bi-arrow-<?php echo $sortOrder === 'DESC' ? 'down' : 'up'; ?>"></i>
                                <?php endif; ?>
                            </a>
                        </div>
                        <div style="font-weight: 650; color: #6b7280;">
                            Showing <?php echo count($sites); ?> of <?php echo $totalSites; ?> sites
                        </div>
                    </div>

                    <!-- Sites Table -->
                    <?php if (empty($sites)): ?>
                    <div class="panel text-center py-5">
                        <i class="bi bi-building" style="font-size: 48px; color: #d1d5db;"></i>
                        <h5 class="mt-3" style="font-weight: 900; color: #6b7280;">No sites found</h5>
                        <p class="text-muted" style="font-weight: 650;">You are not assigned as Team Lead to any sites yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">My Assigned Sites (Team Lead)</h3>
                            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th style="min-width: 250px;">Project</th>
                                        <th style="min-width: 200px;">Client</th>
                                        <th style="min-width: 200px;">Manager/Team</th>
                                        <th style="min-width: 120px;">Type</th>
                                        <th style="min-width: 130px;">Status</th>
                                        <th style="min-width: 120px;">Start Date</th>
                                        <th style="min-width: 120px;">End Date</th>
                                        <th style="width: 120px;" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sites as $site): ?>
                                    <?php 
                                        [$statusLabel, $statusClass] = projectHealthBadge($site['start_date'] ?? '', $site['expected_completion_date'] ?? '');
                                        $engineers = $siteEngineers[$site['id']] ?? [];
                                        $teamCount = count($engineers);
                                        $managerName = $site['manager_name'] ?? 'Not Assigned';
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 900; color: #111827;"><?php echo e($site['project_name']); ?></div>
                                            <div style="font-size: 11px; color: #6b7280; font-weight: 650;">
                                                <i class="bi bi-geo-alt"></i> <?php echo e($site['project_location'] ?? 'N/A'); ?>
                                                <?php if (!empty($site['project_code'])): ?>
                                                <span class="ms-2"><i class="bi bi-qr-code"></i> <?php echo e($site['project_code']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 800;"><?php echo e($site['client_name']); ?></div>
                                            <div style="font-size: 11px; color: #6b7280;">
                                                <?php if (!empty($site['client_phone'])): ?>
                                                <i class="bi bi-telephone"></i> <?php echo e($site['client_phone']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 800;"><?php echo e($managerName); ?></div>
                                            <div style="font-size: 11px; color: #6b7280;">
                                                <i class="bi bi-people"></i> 
                                                <?php echo $teamCount; ?> Engineer<?php echo $teamCount != 1 ? 's' : ''; ?>
                                                <?php if (!empty($site['team_lead_name'])): ?>
                                                <br><span class="ms-0"><i class="bi bi-star-fill text-warning"></i> You (TL)</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark" style="font-weight: 800; font-size: 11px; padding: 6px 10px;">
                                                <?php echo e($site['project_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <span class="mini-dot"></span> <?php echo $statusLabel; ?>
                                            </span>
                                        </td>
                                        <td><?php echo fmtDate($site['start_date'] ?? ''); ?></td>
                                        <td><?php echo fmtDate($site['expected_completion_date'] ?? ''); ?></td>
                                        <td class="text-end">
                                            <button class="action-btn" title="View Details" onclick="viewSiteDetails(<?php echo $site['id']; ?>)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <div class="dropdown d-inline-block">
                                                <button class="action-btn" data-bs-toggle="dropdown" title="More Actions">
                                                    <i class="bi bi-three-dots"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li><a class="dropdown-item" href="dpr.php?site_id=<?php echo $site['id']; ?>"><i class="bi bi-file-text me-2"></i>View DPRs</a></li>
                                                    <li><a class="dropdown-item" href="rfi.php?site_id=<?php echo $site['id']; ?>"><i class="bi bi-question-circle me-2"></i>View RFIs</a></li>
                                                    <li><a class="dropdown-item" href="mom.php?site_id=<?php echo $site['id']; ?>"><i class="bi bi-chat-dots me-2"></i>View MoMs</a></li>
                                                    <li><a class="dropdown-item" href="attendance.php?site_id=<?php echo $site['id']; ?>"><i class="bi bi-clock-history me-2"></i>Attendance</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <nav class="mt-4">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($endPage < $totalPages): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>">
                                        <?php echo $totalPages; ?>
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Site Details Modal -->
    <div class="modal fade" id="siteDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="siteDetailsModalLabel">Site Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="siteDetailsContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="font-weight: 800;">Close</button>
                    <a href="#" id="modalViewReports" class="btn btn-primary" style="font-weight: 800;">View Reports</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal (removed as Team Leads shouldn't delete) -->

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (required for Select2) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- TEK-C Custom JavaScript -->
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
        // Initialize Select2
        $(document).ready(function() {
            $('.select2-filter').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $(document.body)
            });
        });

        // View Site Details
        function viewSiteDetails(siteId) {
            const modal = new bootstrap.Modal(document.getElementById('siteDetailsModal'));
            const contentDiv = document.getElementById('siteDetailsContent');
            const viewReportsBtn = document.getElementById('modalViewReports');
            
            // Show loading
            contentDiv.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            viewReportsBtn.href = `dpr.php?site_id=${siteId}`;
            
            // Fetch site details
            fetch(`ajax/get-site-details.php?id=${siteId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        contentDiv.innerHTML = formatSiteDetails(data.site);
                    } else {
                        contentDiv.innerHTML = '<div class="alert alert-danger">Failed to load site details</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    contentDiv.innerHTML = '<div class="alert alert-danger">Error loading site details</div>';
                });
            
            modal.show();
        }

        // Format site details HTML (same as before)
        function formatSiteDetails(site) {
            return `
                <div class="info-section">
                    <h6>Basic Information</h6>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Project Name</span>
                            <span class="info-value">${escapeHtml(site.project_name)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Project Code</span>
                            <span class="info-value">${site.project_code ? escapeHtml(site.project_code) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Project Type</span>
                            <span class="info-value">${escapeHtml(site.project_type)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Project Location</span>
                            <span class="info-value">${site.project_location ? escapeHtml(site.project_location) : '—'}</span>
                        </div>
                        <div class="info-item full-width">
                            <span class="info-label">Scope of Work</span>
                            <span class="info-value">${site.scope_of_work ? escapeHtml(site.scope_of_work) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Start Date</span>
                            <span class="info-value">${formatDate(site.start_date)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Expected Completion</span>
                            <span class="info-value">${formatDate(site.expected_completion_date)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Status</span>
                            <span class="info-value"><span class="status-badge ${getStatusClass(site.start_date, site.expected_completion_date)}">${getStatusLabel(site.start_date, site.expected_completion_date)}</span></span>
                        </div>
                    </div>
                </div>

                <div class="info-section">
                    <h6>Client Information</h6>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Client Name</span>
                            <span class="info-value">${escapeHtml(site.client_name)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Company</span>
                            <span class="info-value">${site.client_company ? escapeHtml(site.client_company) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Contact</span>
                            <span class="info-value">${site.client_phone ? escapeHtml(site.client_phone) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value">${site.client_email ? escapeHtml(site.client_email) : '—'}</span>
                        </div>
                        <div class="info-item full-width">
                            <span class="info-label">Address</span>
                            <span class="info-value">${site.client_address ? escapeHtml(site.client_address) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">GST No</span>
                            <span class="info-value">${site.client_gst ? escapeHtml(site.client_gst) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">PAN No</span>
                            <span class="info-value">${site.client_pan ? escapeHtml(site.client_pan) : '—'}</span>
                        </div>
                    </div>
                </div>

                <div class="info-section">
                    <h6>Agreement Details</h6>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Agreement Number</span>
                            <span class="info-value">${site.agreement_number ? escapeHtml(site.agreement_number) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Agreement Date</span>
                            <span class="info-value">${formatDate(site.agreement_date)}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Work Order Date</span>
                            <span class="info-value">${formatDate(site.work_order_date)}</span>
                        </div>
                        <div class="info-item full-width">
                            <span class="info-label">Authorized Signatory</span>
                            <span class="info-value">${site.authorized_signatory_name ? escapeHtml(site.authorized_signatory_name) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Signatory Contact</span>
                            <span class="info-value">${site.authorized_signatory_contact ? escapeHtml(site.authorized_signatory_contact) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Contact Person</span>
                            <span class="info-value">${site.contact_person_designation ? escapeHtml(site.contact_person_designation) : '—'}</span>
                        </div>
                    </div>
                </div>

                <div class="info-section">
                    <h6>Team</h6>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Project Manager</span>
                            <span class="info-value">${site.manager_name ? escapeHtml(site.manager_name) : 'Not Assigned'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Manager Contact</span>
                            <span class="info-value">${site.manager_phone ? escapeHtml(site.manager_phone) : '—'}</span>
                        </div>
                        <div class="info-item full-width">
                            <span class="info-label">Manager Email</span>
                            <span class="info-value">${site.manager_email ? escapeHtml(site.manager_email) : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Team Lead</span>
                            <span class="info-value">${site.team_lead_name ? escapeHtml(site.team_lead_name) + ' (You)' : 'Not Assigned'}</span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <span class="info-label mb-2 d-block">Project Engineers (${site.engineers ? site.engineers.length : 0})</span>
                        ${site.engineers && site.engineers.length > 0 ? 
                            site.engineers.map(eng => `
                                <div class="team-member">
                                    <div class="member-avatar">${eng.full_name ? eng.full_name.charAt(0).toUpperCase() : 'E'}</div>
                                    <div class="member-details">
                                        <div class="member-name">${escapeHtml(eng.full_name)}</div>
                                        <div class="member-designation">${escapeHtml(eng.designation || 'Engineer')}</div>
                                        <div class="member-contact">
                                            ${eng.email ? escapeHtml(eng.email) : ''}
                                            ${eng.mobile_number ? ' • ' + escapeHtml(eng.mobile_number) : ''}
                                        </div>
                                    </div>
                                </div>
                            `).join('') 
                            : '<p class="text-muted">No engineers assigned</p>'
                        }
                    </div>
                </div>

                <div class="info-section">
                    <h6>Geofencing Information</h6>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Latitude</span>
                            <span class="info-value">${site.latitude ? site.latitude : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Longitude</span>
                            <span class="info-value">${site.longitude ? site.longitude : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Radius</span>
                            <span class="info-value">${site.location_radius ? site.location_radius + ' meters' : '—'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Place ID</span>
                            <span class="info-value">${site.place_id ? escapeHtml(site.place_id) : '—'}</span>
                        </div>
                        <div class="info-item full-width">
                            <span class="info-label">Site Address</span>
                            <span class="info-value">${site.location_address ? escapeHtml(site.location_address) : '—'}</span>
                        </div>
                    </div>
                    ${site.latitude && site.longitude ? 
                        `<div class="mt-2">
                            <span class="badge-geo"><i class="bi bi-geo-fill me-1"></i>Geofencing Active</span>
                        </div>` : ''
                    }
                </div>
            `;
        }

        // Helper functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateStr) {
            if (!dateStr || dateStr === '0000-00-00') return '—';
            const date = new Date(dateStr);
            if (isNaN(date.getTime())) return '—';
            return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
        }

        function getStatusClass(start, end) {
            const today = new Date().toISOString().split('T')[0];
            if (start && start > today) return 'upcoming';
            if (end && end < today) return 'delayed';
            if (end) {
                const endDate = new Date(end);
                const todayDate = new Date();
                const diffTime = endDate - todayDate;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                if (diffDays >= 0 && diffDays <= 30) return 'nearcomplete';
            }
            return 'ontrack';
        }

        function getStatusLabel(start, end) {
            const today = new Date().toISOString().split('T')[0];
            if (start && start > today) return 'Upcoming';
            if (end && end < today) return 'Delayed';
            if (end) {
                const endDate = new Date(end);
                const todayDate = new Date();
                const diffTime = endDate - todayDate;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                if (diffDays >= 0 && diffDays <= 30) return 'Near Completion';
            }
            return 'Active';
        }
    </script>

    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
    <script>
        alert('Site created successfully!');
        window.history.replaceState(null, null, window.location.pathname);
    </script>
    <?php endif; ?>
</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>