<?php
// my-sites.php (Project Engineer)
// Updated UI using TEK-C compact manage-page template.
// Shows only sites assigned to the logged-in Project Engineer.
// Reports button opens today-tasks.php filtered by site.

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
  die("Database connection failed.");
}

$success = '';
$error   = '';
$sites   = [];

// ---------- Auth (Project Engineer only) ----------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// allow PE Grade 1/2 and Sr. Engineer
$allowed = [
  'project engineer grade 1',
  'project engineer grade 2',
  'sr. engineer',
];

if (!in_array($designation, $allowed, true)) {
  header("Location: index.php");
  exit;
}

// ---------- Helpers ----------
function e($v){
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function projectStatusBadge($start, $end){
  $today = date('Y-m-d');
  $start = trim((string)$start);
  $end   = trim((string)$end);

  if ($end !== '' && $end !== '0000-00-00' && $end < $today) {
    return ['Completed', 'ontrack', 'bi-check2-circle'];
  }

  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['Upcoming', 'pending', 'bi-clock'];
  }

  return ['Ongoing', 'progressing', 'bi-lightning-fill'];
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) return false;
  mysqli_stmt_bind_param($st, "ss", $table, $column);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $ok = (bool)mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
  return $ok;
}

// ---------- Detect optional Team Lead column ----------
$hasTeamLead = hasColumn($conn, 'sites', 'team_lead_employee_id');

$tlSelect = $hasTeamLead ? "
    tl.full_name AS team_lead_name,
    tl.employee_code AS team_lead_code,
" : "
    '' AS team_lead_name,
    '' AS team_lead_code,
";

$tlJoin = $hasTeamLead ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id" : "";

// ---------- Fetch sites assigned to this Project Engineer ----------
$sql = "
  SELECT
    s.id,
    s.project_name,
    s.project_type,
    s.project_location,
    s.scope_of_work,
    s.start_date,
    s.expected_completion_date,
    s.created_at,

    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.state AS client_state,
    c.client_type,

    m.full_name AS manager_name,
    m.employee_code AS manager_code,

    $tlSelect

    eng.engineer_names

  FROM site_project_engineers spe
  INNER JOIN sites s ON s.id = spe.site_id
  INNER JOIN clients c ON c.id = s.client_id
  LEFT JOIN employees m ON m.id = s.manager_employee_id
  $tlJoin

  LEFT JOIN (
    SELECT
      spe2.site_id,
      GROUP_CONCAT(DISTINCT e.full_name ORDER BY e.full_name SEPARATOR ', ') AS engineer_names
    FROM site_project_engineers spe2
    INNER JOIN employees e ON e.id = spe2.employee_id
    GROUP BY spe2.site_id
  ) eng ON eng.site_id = s.id

  WHERE spe.employee_id = ?
  ORDER BY s.created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
  $error = "Database error: " . mysqli_error($conn);
} else {
  mysqli_stmt_bind_param($stmt, "i", $empId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $sites = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
  mysqli_stmt_close($stmt);
}

// ---------- Stats ----------
$total_sites = count($sites);
$ongoing = 0;
$upcoming = 0;
$completed = 0;
$today = date('Y-m-d');

foreach ($sites as $p) {
  $start = $p['start_date'] ?? '';
  $end   = $p['expected_completion_date'] ?? '';

  if (!empty($end) && $end !== '0000-00-00' && $end < $today) {
    $completed++;
  } elseif (!empty($start) && $start !== '0000-00-00' && $start > $today) {
    $upcoming++;
  } else {
    $ongoing++;
  }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Sites - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
    :root {
        --page-bg: #f5f7fb;
        --card-bg: #ffffff;
        --border: #e5e7eb;
        --text: #111827;
        --muted: #6b7280;
        --soft: #f8fafc;
        --shadow: 0 10px 26px rgba(15, 23, 42, .055);
        --radius: 15px;
    }

    body {
        background: var(--page-bg);
    }

    .content-scroll {
        flex: 1 1 auto;
        overflow: auto;
        padding: 16px;
    }

    .projects-wrapper {
        width: 100%;
    }

    .page-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .page-heading h1 {
        font-size: 19px;
        font-weight: 900;
        color: var(--text);
        margin: 0;
    }

    .page-heading p {
        margin: 3px 0 0;
        color: var(--muted);
        font-size: 12px;
        font-weight: 600;
    }

    .primary-btn {
        border: 0;
        background: #111827;
        color: #fff;
        height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
    }

    .primary-btn:hover {
        background: #020617;
        color: #fff;
    }

    .secondary-btn {
        border: 1px solid var(--border) !important;
        background: #fff !important;
        color: #334155 !important;
        height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
    }

    .secondary-btn:hover {
        border-color: #cbd5e1 !important;
        background: #f8fafc !important;
        color: #111827 !important;
    }

    .stat-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 12px 13px;
        min-height: 78px;
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .stat-ic {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        color: #fff;
        font-size: 17px;
        flex: 0 0 auto;
    }

    .blue {
        background: #2f80ed;
    }

    .orange {
        background: #f2994a;
    }

    .green {
        background: #27ae60;
    }

    .red {
        background: #eb5757;
    }

    .gray {
        background: #64748b;
    }

    .stat-label {
        color: var(--muted);
        font-weight: 800;
        font-size: 10.5px;
        text-transform: uppercase;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 950;
        color: #111827;
        line-height: 1;
    }

    .panel {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 13px;
    }

    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        gap: 10px;
    }

    .panel-title {
        font-weight: 900;
        font-size: 14px;
        margin: 0;
        color: #111827;
    }

    .panel-subtitle {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
    }

    .filter-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .search-box {
        position: relative;
        flex: 1 1 260px;
        max-width: 430px;
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 13px;
    }

    .search-box input {
        width: 100%;
        height: 36px;
        border: 1px solid var(--border);
        border-radius: 11px;
        background: #fff;
        padding: 0 12px 0 34px;
        font-size: 12px;
        font-weight: 700;
        color: var(--text);
        outline: none;
    }

    .search-box input:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .filter-select {
        height: 36px;
        border: 1px solid var(--border);
        border-radius: 11px;
        background: #fff;
        padding: 0 42px 0 12px;
        font-size: 12px;
        font-weight: 800;
        min-width: 145px;
    }

    .compact-table-wrap {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 13px;
        overflow: hidden;
        background: #fff;
    }

    .compact-table {
        width: 100%;
        margin: 0;
        table-layout: auto;
    }

    .compact-table thead th {
        background: var(--soft);
        color: #64748b;
        font-size: 10px;
        text-transform: uppercase;
        font-weight: 900;
        border-bottom: 1px solid var(--border) !important;
        padding: 8px 9px;
    }

    .compact-table tbody td {
        padding: 8px 9px;
        vertical-align: middle;
        border-color: #eef2f7;
        color: #334155;
        font-weight: 700;
        font-size: 11.5px;
    }

    .compact-table tbody tr:hover {
        background: #fbfdff;
    }

    .table-title-cell {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .table-icon {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        background: #eff6ff;
        color: #2563eb;
        font-size: 13px;
        flex: 0 0 auto;
    }

    .table-primary-text {
        color: #111827;
        font-size: 11.5px;
        font-weight: 900;
    }

    .table-secondary-text {
        color: #64748b;
        font-size: 10px;
        font-weight: 700;
        margin-top: 1px;
    }

    .badge-pill {
        border-radius: 999px;
        padding: 5px 8px;
        font-weight: 900;
        font-size: 10px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid transparent;
        white-space: nowrap;
        text-decoration: none;
    }

    .mini-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    .ontrack {
        color: #15803d;
        background: #dcfce7;
        border-color: #bbf7d0;
    }

    .progressing {
        color: #2563eb;
        background: #dbeafe;
        border-color: #bfdbfe;
    }

    .pending {
        color: #6d28d9;
        background: #ede9fe;
        border-color: #ddd6fe;
    }

    .atrisk {
        color: #b91c1c;
        background: #fee2e2;
        border-color: #fecaca;
    }

    .neutral {
        color: #475569;
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .action-group {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .action-btn {
        width: 27px;
        height: 27px;
        border-radius: 9px;
        border: 1px solid var(--border);
        background: #fff;
        display: grid;
        place-items: center;
        text-decoration: none;
    }

    .view-btn {
        color: #475569;
        background: #f8fafc;
    }

    .file-btn {
        color: #10b981;
        background: #ecfdf5;
    }

    .report-btn {
        color: #2563eb;
        background: #eff6ff;
    }

    .team-text {
        font-size: 10px;
        color: #64748b;
        line-height: 1.5;
        font-weight: 750;
    }

    .team-text b {
        color: #111827;
    }

    .reason-cell {
        max-width: 260px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .empty-state {
        text-align: center;
        color: #64748b;
        padding: 30px 12px;
        font-size: 12px;
        font-weight: 900;
    }

    .empty-state i {
        font-size: 34px;
        display: block;
        margin-bottom: 8px;
        opacity: .45;
    }

    .pagination-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding-top: 12px;
        flex-wrap: wrap;
    }

    .pagination-info {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
    }

    .alert {
        border-radius: var(--radius);
        border: none;
        box-shadow: var(--shadow);
        margin-bottom: 14px;
    }

    @media(max-width:991.98px) {
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

    @media(max-width:1199px) {
        .compact-table thead {
            display: none;
        }

        .compact-table,
        .compact-table tbody,
        .compact-table tr,
        .compact-table td {
            display: block;
            width: 100%;
        }

        .compact-table tbody tr {
            border-bottom: 1px solid var(--border);
            padding: 10px;
        }

        .compact-table tbody td {
            border: 0;
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        .compact-table tbody td::before {
            content: attr(data-label);
            font-size: 10px;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            flex: 0 0 95px;
        }

        .compact-table tbody td:first-child {
            display: block;
        }

        .compact-table tbody td:first-child::before {
            display: none;
        }

        .action-group {
            justify-content: flex-start;
        }

        .reason-cell {
            max-width: none;
            white-space: normal;
            text-align: right;
        }
    }

    @media(max-width:768px) {
        .content-scroll {
            padding: 12px 10px 12px !important;
        }

        .page-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .filter-bar {
            align-items: stretch;
        }

        .search-box {
            max-width: none;
            width: 100%;
            flex: 1 1 100%;
        }

        .filter-select,
        .primary-btn,
        .secondary-btn {
            width: 100%;
            justify-content: center;
        }

        .panel {
            padding: 12px;
        }

        .compact-table tbody td {
            align-items: flex-start;
        }
    }
    </style>
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main" aria-label="Main">
            <?php include 'includes/topbar.php'; ?>

            <div class="content-scroll">
                <div class="container-fluid projects-wrapper px-0">

                    <div class="page-heading">
                        <div>
                            <h1>My Sites</h1>
                            <p>Sites where you are assigned as Project Engineer</p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="today-tasks.php" class="primary-btn">
                                <i class="bi bi-list-task"></i>
                                Today Tasks
                            </a>
                            <a href="report.php" class="secondary-btn">
                                <i class="bi bi-file-text"></i>
                                Reports
                            </a>
                        </div>
                    </div>

                    <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-geo-alt-fill"></i></div>
                                <div>
                                    <div class="stat-label">Total Sites</div>
                                    <div class="stat-value"><?php echo (int)$total_sites; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-lightning-fill"></i></div>
                                <div>
                                    <div class="stat-label">Ongoing</div>
                                    <div class="stat-value"><?php echo (int)$ongoing; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic orange"><i class="bi bi-clock-fill"></i></div>
                                <div>
                                    <div class="stat-label">Upcoming</div>
                                    <div class="stat-value"><?php echo (int)$upcoming; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic red"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <div class="stat-label">Completed</div>
                                    <div class="stat-value"><?php echo (int)$completed; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel mb-4">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">Site Directory</h3>
                                <div class="panel-subtitle">Showing <?php echo count($sites); ?> assigned site records
                                </div>
                            </div>
                        </div>

                        <div class="filter-bar">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="siteSearch"
                                    placeholder="Search site, client, manager, team lead or location...">
                            </div>

                            <select class="filter-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>

                        <div class="compact-table-wrap">
                            <table class="table compact-table align-middle" id="sitesTable">
                                <thead>
                                    <tr>
                                        <th>Site</th>
                                        <th>Client</th>
                                        <th>Management</th>
                                        <th>Dates</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($sites)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No assigned sites found.
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($sites as $p): ?>
                                    <?php
              [$stLabel, $stClass, $stIcon] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');

              $clientName = trim((string)($p['client_name'] ?? ''));
              $company    = trim((string)($p['company_name'] ?? ''));
              $clientLine = $company !== '' ? ($clientName . ' • ' . $company) : $clientName;

              $mgrName = trim((string)($p['manager_name'] ?? ''));
              $mgrCode = trim((string)($p['manager_code'] ?? ''));

              $tlName  = trim((string)($p['team_lead_name'] ?? ''));
              $tlCode  = trim((string)($p['team_lead_code'] ?? ''));

              $engineers = trim((string)($p['engineer_names'] ?? ''));
              $siteId = (int)$p['id'];

              $statusKey = strtolower($stLabel);
            ?>

                                    <tr data-status="<?php echo e($statusKey); ?>">
                                        <td data-label="Site">
                                            <div class="table-title-cell">
                                                <div class="table-icon"><i class="bi bi-building"></i></div>
                                                <div>
                                                    <div class="table-primary-text">
                                                        <?php echo e($p['project_name'] ?? ''); ?></div>
                                                    <div class="table-secondary-text">
                                                        <i class="bi bi-geo-alt"></i>
                                                        <?php echo e($p['project_location'] ?? '—'); ?>
                                                        <?php if (!empty($p['project_type'])): ?>
                                                        • <i class="bi bi-kanban"></i>
                                                        <?php echo e($p['project_type']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($p['scope_of_work'])): ?>
                                                    <div class="table-secondary-text reason-cell"
                                                        title="<?php echo e($p['scope_of_work']); ?>">
                                                        <i class="bi bi-tools"></i>
                                                        <?php echo e($p['scope_of_work']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td data-label="Client">
                                            <div class="table-primary-text">
                                                <?php echo e($clientLine !== '' ? $clientLine : '—'); ?></div>
                                            <?php if (!empty($p['client_state'])): ?>
                                            <div class="table-secondary-text"><i class="bi bi-pin-map"></i>
                                                <?php echo e($p['client_state']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($p['client_mobile'])): ?>
                                            <div class="table-secondary-text"><i class="bi bi-telephone"></i>
                                                <?php echo e($p['client_mobile']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($p['client_email'])): ?>
                                            <div class="table-secondary-text"><i class="bi bi-envelope"></i>
                                                <?php echo e($p['client_email']); ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <td data-label="Management">
                                            <div class="team-text">
                                                <div>
                                                    <b>Manager:</b>
                                                    <?php echo $mgrName !== '' ? e($mgrName) : '—'; ?>
                                                    <?php if ($mgrCode !== ''): ?>
                                                    <span>(<?php echo e($mgrCode); ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <b>Team Lead:</b>
                                                    <?php echo $tlName !== '' ? e($tlName) : '—'; ?>
                                                    <?php if ($tlCode !== ''): ?>
                                                    <span>(<?php echo e($tlCode); ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!$hasTeamLead): ?>
                                                <div class="text-warning"><i class="bi bi-info-circle"></i> TL column
                                                    not available</div>
                                                <?php endif; ?>
                                                <div>
                                                    <b>Engineers:</b>
                                                    <?php echo $engineers !== '' ? e($engineers) : '—'; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td data-label="Dates">
                                            <div class="table-primary-text">Start:
                                                <?php echo e(safeDate($p['start_date'] ?? '')); ?></div>
                                            <div class="table-secondary-text">End:
                                                <?php echo e(safeDate($p['expected_completion_date'] ?? '')); ?></div>
                                        </td>

                                        <td data-label="Status">
                                            <span class="badge-pill <?php echo e($stClass); ?>">
                                                <i class="bi <?php echo e($stIcon); ?>"></i>
                                                <?php echo e($stLabel); ?>
                                            </span>
                                        </td>

                                        <td data-label="Actions">
                                            <div class="action-group">
                                                <a href="view-site.php?id=<?php echo $siteId; ?>"
                                                    class="action-btn view-btn" title="View Site">
                                                    <i class="bi bi-eye"></i>
                                                </a>

                                                <a href="today-tasks.php?site_id=<?php echo $siteId; ?>"
                                                    class="action-btn report-btn" title="Reports / Today Tasks">
                                                    <i class="bi bi-clipboard-data"></i>
                                                </a>

                                                <a href="report.php?site_id=<?php echo $siteId; ?>"
                                                    class="action-btn file-btn" title="All Reports">
                                                    <i class="bi bi-file-text"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination-wrap">
                            <div class="pagination-info">
                                Showing <span id="visibleCount"><?php echo count($sites); ?></span> of
                                <?php echo count($sites); ?> assigned site records
                            </div>
                            <div class="pagination-info">
                                <i class="bi bi-info-circle"></i>
                                Reports button opens Today Tasks filtered by selected site.
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('siteSearch');
        const statusFilter = document.getElementById('statusFilter');
        const tableRows = document.querySelectorAll('#sitesTable tbody tr[data-status]');
        const visibleCount = document.getElementById('visibleCount');

        function filterSites() {
            const searchValue = (searchInput ? searchInput.value : '').toLowerCase().trim();
            const statusValue = (statusFilter ? statusFilter.value : '').toLowerCase().trim();
            let visible = 0;

            tableRows.forEach(function(row) {
                const rowText = row.innerText.toLowerCase();
                const rowStatus = row.getAttribute('data-status') || '';
                const matchesSearch = rowText.includes(searchValue);
                const matchesStatus = !statusValue || rowStatus === statusValue;
                const show = matchesSearch && matchesStatus;

                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if (visibleCount) visibleCount.textContent = visible;
        }

        if (searchInput) searchInput.addEventListener('input', filterSites);
        if (statusFilter) statusFilter.addEventListener('change', filterSites);
    });
    </script>
</body>

</html>
<?php
try {
  if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
  }
} catch (Throwable $e) { }
?>