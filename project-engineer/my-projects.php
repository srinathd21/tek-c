<?php
// my-projects.php (Manager) — show ONLY projects assigned to logged-in Manager
// - Reads from sites + clients
// - Team column shows: Team Lead + Engineers (Manager removed because logged-in user is Manager)
//
// REQUIREMENTS:
// - session sets: $_SESSION['employee_id'], $_SESSION['designation'] from your login.php
// - tables: sites, clients, employees, site_project_engineers
// - OPTIONAL: sites.team_lead_employee_id (if exists, it will be used; if not, Team Lead shows "—")

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error = '';
$projects = [];

// ---------- Auth (Manager only) ----------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}
$managerId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
if ($designation !== 'manager') {
  header("Location: index.php");
  exit;
}

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function showMoney($v, $dash='—'){
  if ($v === null) return $dash;
  $v = trim((string)$v);
  if ($v === '') return $dash;
  if (!is_numeric($v)) return e($v);
  return number_format((float)$v, 2);
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
    return ['Completed', 'status-red', 'bi-check2-circle'];
  }
  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['Upcoming', 'status-yellow', 'bi-clock'];
  }
  return ['Ongoing', 'status-green', 'bi-lightning'];
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
      tl.designation AS team_lead_designation,
" : "
      '' AS team_lead_name,
      '' AS team_lead_designation,
";

$tlJoin = $hasTeamLead ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id" : "";

// ---------- Fetch projects assigned to this manager ----------
$sql = "
  SELECT
    s.*,
    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.state AS client_state,
    c.client_type,

    $tlSelect

    eng.engineer_count,
    eng.engineer_list

  FROM sites s
  INNER JOIN clients c ON c.id = s.client_id

  $tlJoin

  LEFT JOIN (
    SELECT
      spe.site_id,
      COUNT(*) AS engineer_count,
      GROUP_CONCAT(
        CONCAT(e.full_name, ' (', e.designation, ')')
        ORDER BY e.full_name SEPARATOR ', '
      ) AS engineer_list
    FROM site_project_engineers spe
    INNER JOIN employees e ON e.id = spe.employee_id
    GROUP BY spe.site_id
  ) eng ON eng.site_id = s.id

  WHERE s.manager_employee_id = ?
  ORDER BY s.created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
  $error = "Database error: " . mysqli_error($conn);
} else {
  mysqli_stmt_bind_param($stmt, "i", $managerId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $projects = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($stmt);
}

// ---------- Stats ----------
$total_projects = count($projects);
$ongoing = 0; $upcoming = 0; $completed = 0;
$today = date('Y-m-d');

foreach ($projects as $p) {
  $start = $p['start_date'] ?? '';
  $end   = $p['expected_completion_date'] ?? '';
  if (!empty($end) && $end !== '0000-00-00' && $end < $today) $completed++;
  elseif (!empty($start) && $start !== '0000-00-00' && $start > $today) $upcoming++;
  else $ongoing++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>My Projects - TEK-C</title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- DataTables (Bootstrap 5 + Responsive) -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table-responsive { overflow-x: hidden !important; }
    table.dataTable { width:100% !important; }
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:800;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: normal !important;
    }
    .table td{
      vertical-align: top; border-color: var(--border);
      font-weight:650; color:#374151;
      padding: 10px 10px !important;
      white-space: normal !important;
      word-break: break-word;
    }

    .btn-action {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 5px 8px;
      color: var(--muted);
      font-size: 12px;
      margin-left: 4px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
    }
    .btn-action:hover { background: var(--bg); color: var(--blue); }

    .proj-title{ font-weight:800; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
    .proj-sub{ font-size:11px; color:#6b7280; font-weight:600; line-height:1.25; }

    .status-badge{
      padding: 3px 8px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: .3px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space: nowrap;
      text-transform: uppercase;
    }
    .status-green{ background: rgba(16,185,129,.12); color:#10b981; border:1px solid rgba(16,185,129,.22); }
    .status-yellow{ background: rgba(245,158,11,.12); color:#f59e0b; border:1px solid rgba(245,158,11,.22); }
    .status-red{ background: rgba(239,68,68,.12); color:#ef4444; border:1px solid rgba(239,68,68,.22); }

    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 10px;
      font-weight: 650;
      outline: none;
    }
    div.dataTables_wrapper .dataTables_filter input:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
    }
    .dataTables_paginate .pagination .page-link{
      border-radius: 10px;
      margin: 0 3px;
      font-weight: 750;
    }
    th.actions-col, td.actions-col { width: 120px !important; }

    .team-block b{ color:#111827; }
    .team-block .line{ margin-bottom:4px; }
    .team-block .muted{ color:#6b7280; font-weight:700; }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div id="contentScroll" class="content-scroll">
      <div class="container-fluid maxw">

        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">My Projects</h1>
            <p class="text-muted mb-0">Projects where you are assigned as the Manager</p>
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

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-kanban-fill"></i></div>
              <div>
                <div class="stat-label">Total</div>
                <div class="stat-value"><?php echo (int)$total_projects; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-lightning-fill"></i></div>
              <div>
                <div class="stat-label">Ongoing</div>
                <div class="stat-value"><?php echo (int)$ongoing; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-clock-fill"></i></div>
              <div>
                <div class="stat-label">Upcoming</div>
                <div class="stat-value"><?php echo (int)$upcoming; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-check2-circle"></i></div>
              <div>
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?php echo (int)$completed; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Table -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">Project Directory</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table id="myProjectsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Project</th>
                  <th>Client</th>
                  <th>Team</th>
                  <th>Dates</th>
                  <th>Status</th>
                  <th class="text-end actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($projects as $p): ?>
                <?php
                  [$stLabel, $stClass, $stIcon] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? '');

                  $clientName = trim((string)($p['client_name'] ?? ''));
                  $company    = trim((string)($p['company_name'] ?? ''));
                  $clientLine = $company !== '' ? ($clientName . ' • ' . $company) : $clientName;

                  $tlName  = trim((string)($p['team_lead_name'] ?? ''));
                  $tlDes   = trim((string)($p['team_lead_designation'] ?? 'Team Lead'));

                  $engCount = (int)($p['engineer_count'] ?? 0);
                  $engList  = trim((string)($p['engineer_list'] ?? ''));
                ?>
                <tr>
                  <td>
                    <div class="proj-title"><?php echo e($p['project_name'] ?? ''); ?></div>
                    <div class="proj-sub">
                      <i class="bi bi-geo-alt"></i> <?php echo e($p['project_location'] ?? ''); ?>
                      &nbsp;•&nbsp; <i class="bi bi-kanban"></i> <?php echo e($p['project_type'] ?? ''); ?>
                    </div>
                    <div class="proj-sub">
                      <i class="bi bi-cash-stack"></i> ₹ <?php echo e(showMoney($p['contract_value'] ?? '')); ?>
                      &nbsp;•&nbsp; PMC ₹ <?php echo e(showMoney($p['pmc_charges'] ?? '')); ?>
                    </div>
                  </td>

                  <td>
                    <div class="proj-title"><?php echo e($clientLine); ?></div>
                    <?php if (!empty($p['client_state'])): ?>
                      <div class="proj-sub"><i class="bi bi-pin-map"></i> <?php echo e($p['client_state']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['client_mobile'])): ?>
                      <div class="proj-sub"><i class="bi bi-telephone"></i> <?php echo e($p['client_mobile']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['client_email'])): ?>
                      <div class="proj-sub"><i class="bi bi-envelope"></i> <?php echo e($p['client_email']); ?></div>
                    <?php endif; ?>
                  </td>

                  <!-- ✅ TEAM (Manager removed) -->
                  <td class="team-block">
                    <div class="line">
                      <span class="muted">Team Lead:</span>
                      <b><?php echo $tlName !== '' ? e($tlName) : '—'; ?></b>
                      <?php if ($tlName !== ''): ?>
                        <span class="muted">(<?php echo e($tlDes !== '' ? $tlDes : 'Team Lead'); ?>)</span>
                      <?php endif; ?>
                    </div>

                    <div class="line">
                      <span class="muted">Engineers:</span>
                      <b><?php echo (int)$engCount; ?></b>
                    </div>

                    <?php if ($engList !== ''): ?>
                      <div class="proj-sub" title="<?php echo e($engList); ?>">
                        <i class="bi bi-people"></i>
                        <?php echo e($engList); ?>
                      </div>
                    <?php else: ?>
                      <div class="proj-sub"><i class="bi bi-people"></i> —</div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <div class="proj-title">Start: <?php echo e(safeDate($p['start_date'] ?? '')); ?></div>
                    <div class="proj-sub">End: <?php echo e(safeDate($p['expected_completion_date'] ?? '')); ?></div>
                    <div class="proj-sub">
                      <i class="bi bi-file-earmark-text"></i> Agreement: <?php echo e($p['agreement_number'] ?? '—'); ?>
                    </div>
                  </td>

                  <td>
                    <span class="status-badge <?php echo e($stClass); ?>">
                      <i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?>
                    </span>
                  </td>

                  <td class="text-end actions-col">
                    <a href="view-site.php?id=<?php echo (int)$p['id']; ?>" class="btn-action" title="View Project">
                      <i class="bi bi-eye"></i>
                    </a>
                    <?php if (!empty($p['contract_document'])): ?>
                      <a href="<?php echo e($p['contract_document']); ?>" class="btn-action" target="_blank" rel="noopener" title="Contract">
                        <i class="bi bi-file-earmark-arrow-down"></i>
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
  (function () {
    $(function () {
      $('#myProjectsTable').DataTable({
        responsive: true,
        autoWidth: false,
        scrollX: false,
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: [[0, 'asc']],
        columnDefs: [
          { targets: [5], orderable: false, searchable: false } // Actions
        ],
        language: {
          zeroRecords: "No matching projects found",
          info: "Showing _START_ to _END_ of _TOTAL_ projects",
          infoEmpty: "No projects to show",
          lengthMenu: "Show _MENU_",
          search: "Search:"
        }
      });

      setTimeout(function() {
        $('.dataTables_filter input').focus();
      }, 400);
    });
  })();
</script>

</body>
</html>

<?php
if (isset($conn)) { mysqli_close($conn); }
?>
