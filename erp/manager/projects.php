<?php
session_start();
require_once 'includes/db-config.php';

$success = '';
$error = '';
$projects = [];

$conn = get_db_connection();

if (!$conn) {
  die("Database connection failed.");
}

/* ---------------- CURRENT EMPLOYEE ----------------
   This page shows only projects assigned to the logged-in employee.
   Match happens against:
   1) sites.manager_employee_id
   2) sites.team_lead_employee_id, if the column exists
   3) site_project_engineers.employee_id
*/
$current_employee_id = (int) (
  $_SESSION['employee_id']
  ?? $_SESSION['user_id']
  ?? 0
);

if ($current_employee_id <= 0) {
  $error = "Employee session not found. Please login again.";
}

/* ---------------- HELPERS ---------------- */

function e($v)
{
  return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function showMoney($v, $dash = '—')
{

  if ($v === null)
    return $dash;

  $v = trim((string) $v);

  if ($v === '')
    return $dash;

  if (!is_numeric($v))
    return e($v);

  $num = (float) $v;

  if ($num >= 10000000) {
    return number_format($num / 10000000, 2) . 'Cr';
  }

  if ($num >= 100000) {
    return number_format($num / 100000, 2) . 'L';
  }

  return number_format($num, 2);
}

function projectStatusBadge($start, $end)
{

  $today = date('Y-m-d');

  $start = trim((string) $start);
  $end = trim((string) $end);

  if ($end !== '' && $end !== '0000-00-00' && $end < $today) {
    return ['Completed', 'ontrack'];
  }

  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['Upcoming', 'pending'];
  }

  return ['Ongoing', 'progressing'];
}

function parseMembersConcat($str)
{

  $str = trim((string) $str);

  if ($str === '')
    return [];

  $items = explode('||', $str);

  $out = [];

  foreach ($items as $it) {

    $it = trim($it);

    if ($it === '')
      continue;

    $parts = explode('|', $it);

    $out[] = [
      'name' => trim($parts[0] ?? ''),
      'designation' => trim($parts[1] ?? '')
    ];
  }

  return $out;
}

/* ---------------- TEAM LEAD CHECK ---------------- */

$hasTeamLeadCol = false;

$chk = mysqli_query(
  $conn,
  "SHOW COLUMNS FROM sites LIKE 'team_lead_employee_id'"
);

if ($chk) {

  $hasTeamLeadCol =
    mysqli_num_rows($chk) > 0;

  mysqli_free_result($chk);
}

/* ---------------- QUERY ---------------- */

$teamLeadSelect = $hasTeamLeadCol
  ? "s.team_lead_employee_id,"
  : "NULL AS team_lead_employee_id,";

$teamLeadJoin = $hasTeamLeadCol
  ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id"
  : "LEFT JOIN employees tl ON 1=0";

$employeeFilterSql = $hasTeamLeadCol
  ? "(
        s.manager_employee_id = ?
        OR s.team_lead_employee_id = ?
        OR EXISTS (
            SELECT 1
            FROM site_project_engineers spe_filter
            WHERE spe_filter.site_id = s.id
              AND spe_filter.employee_id = ?
        )
    )"
  : "(
        s.manager_employee_id = ?
        OR EXISTS (
            SELECT 1
            FROM site_project_engineers spe_filter
            WHERE spe_filter.site_id = s.id
              AND spe_filter.employee_id = ?
        )
    )";

$sql = "

SELECT

    s.*,

    c.client_name,
    c.company_name,
    c.mobile_number AS client_mobile,
    c.email AS client_email,
    c.state AS client_state,

    $teamLeadSelect

    m.full_name AS manager_name,
    m.designation AS manager_designation,

    tl.full_name AS team_lead_name,
    tl.designation AS team_lead_designation,

    GROUP_CONCAT(

        DISTINCT CONCAT(

            COALESCE(pe.full_name,''),'|',
            COALESCE(pe.designation,'')

        )

        ORDER BY pe.full_name

        SEPARATOR '||'

    ) AS engineers_concat

FROM sites s

INNER JOIN clients c
ON c.id = s.client_id

LEFT JOIN employees m
ON m.id = s.manager_employee_id

$teamLeadJoin

LEFT JOIN site_project_engineers spe
ON spe.site_id = s.id

LEFT JOIN employees pe
ON pe.id = spe.employee_id

WHERE $employeeFilterSql

GROUP BY s.id

ORDER BY s.created_at DESC

";

if ($current_employee_id > 0) {

  $stmt = mysqli_prepare($conn, $sql);

  if ($stmt) {

    if ($hasTeamLeadCol) {
      mysqli_stmt_bind_param(
        $stmt,
        "iii",
        $current_employee_id,
        $current_employee_id,
        $current_employee_id
      );
    } else {
      mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $current_employee_id,
        $current_employee_id
      );
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($result) {
      $projects = mysqli_fetch_all($result, MYSQLI_ASSOC);
      mysqli_free_result($result);
    }

    mysqli_stmt_close($stmt);

  } else {

    $error =
      "Error preparing project query: " .
      mysqli_error($conn);
  }
}

/* ---------------- STATS ---------------- */

$total_projects = count($projects);

$ongoing = 0;
$upcoming = 0;
$completed = 0;

$today = date('Y-m-d');

foreach ($projects as $p) {

  $start =
    $p['start_date'] ?? '';

  $end =
    $p['expected_completion_date'] ?? '';

  if (
    !empty($end) &&
    $end !== '0000-00-00' &&
    $end < $today
  ) {

    $completed++;

  } elseif (
    !empty($start) &&
    $start !== '0000-00-00' &&
    $start > $today
  ) {

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

  <title>Projects - TEK-C</title>

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

    .export-btn {
      background: #10b981;
    }

    .export-btn:hover {
      background: #059669;
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

    .stat-label {
      color: var(--muted);
      font-weight: 800;
      font-size: 10.5px;
      text-transform: uppercase;
    }

    .stat-value {
      font-size: 24px;
      font-weight: 950;
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
    }

    .panel-title {
      font-weight: 900;
      font-size: 14px;
      margin: 0;
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
    }

    .progressing {
      color: #2563eb;
      background: #dbeafe;
    }

    .pending {
      color: #6d28d9;
      background: #ede9fe;
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

    .edit-btn {
      color: #2563eb;
      background: #eff6ff;
    }

    .file-btn {
      color: #dc2626;
      background: #fef2f2;
    }

    .pagination-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-top: 12px;
    }

    .pagination-info {
      color: var(--muted);
      font-size: 11px;
      font-weight: 700;
    }

    .team-text {
      font-size: 10px;
      color: #64748b;
      line-height: 1.5;
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
    }
  </style>

</head>

<body>

  <div class="app">

    <?php include 'includes/sidebar.php'; ?>

    <main class="main">

      <?php include 'includes/topbar.php'; ?>

      <div class="content-scroll">

        <div class="container-fluid projects-wrapper px-0">

          <!-- PAGE HEADING -->

          <div class="page-heading">

            <div>
              <h1>Projects</h1>

              <p>
                Manage only projects assigned to your employee account
              </p>
            </div>

            <div class="d-flex gap-2">



              <button class="primary-btn export-btn" data-bs-toggle="modal" data-bs-target="#exportModal">
                <i class="bi bi-download"></i>
                Export
              </button>

            </div>

          </div>

          <!-- ALERTS -->

          <?php if ($success): ?>

            <div class="alert alert-success">
              <?php echo e($success); ?>
            </div>

          <?php endif; ?>

          <?php if ($error): ?>

            <div class="alert alert-danger">
              <?php echo e($error); ?>
            </div>

          <?php endif; ?>

          <!-- STATS -->

          <div class="row g-3 mb-3">

            <div class="col-12 col-sm-6 col-xl-3">

              <div class="stat-card">

                <div class="stat-ic blue">
                  <i class="bi bi-folder2-open"></i>
                </div>

                <div>
                  <div class="stat-label">Total Projects</div>
                  <div class="stat-value">
                    <?php echo (int) $total_projects; ?>
                  </div>
                </div>

              </div>

            </div>

            <div class="col-12 col-sm-6 col-xl-3">

              <div class="stat-card">

                <div class="stat-ic green">
                  <i class="bi bi-lightning-fill"></i>
                </div>

                <div>
                  <div class="stat-label">Ongoing</div>
                  <div class="stat-value">
                    <?php echo (int) $ongoing; ?>
                  </div>
                </div>

              </div>

            </div>

            <div class="col-12 col-sm-6 col-xl-3">

              <div class="stat-card">

                <div class="stat-ic orange">
                  <i class="bi bi-clock-fill"></i>
                </div>

                <div>
                  <div class="stat-label">Upcoming</div>
                  <div class="stat-value">
                    <?php echo (int) $upcoming; ?>
                  </div>
                </div>

              </div>

            </div>

            <div class="col-12 col-sm-6 col-xl-3">

              <div class="stat-card">

                <div class="stat-ic red">
                  <i class="bi bi-check-circle-fill"></i>
                </div>

                <div>
                  <div class="stat-label">Completed</div>
                  <div class="stat-value">
                    <?php echo (int) $completed; ?>
                  </div>
                </div>

              </div>

            </div>

          </div>

          <!-- PANEL -->

          <div class="panel">

            <div class="panel-header">

              <div>

                <h3 class="panel-title">
                  My Projects
                </h3>

                <div class="panel-subtitle">
                  Projects where you are Manager, Team Lead, or Project Engineer
                </div>

              </div>

            </div>

            <!-- FILTER BAR -->

            <div class="filter-bar">

              <div class="search-box">

                <i class="bi bi-search"></i>

                <input type="text" id="projectSearch" placeholder="Search project, client, manager or location...">

              </div>

              <div>

                <select class="filter-select" id="statusFilter">

                  <option value="">All Status</option>

                  <option value="ongoing">
                    Ongoing
                  </option>

                  <option value="completed">
                    Completed
                  </option>

                  <option value="upcoming">
                    Upcoming
                  </option>

                </select>

              </div>

            </div>

            <!-- TABLE -->

            <div class="compact-table-wrap">

              <table class="table compact-table align-middle" id="projectsTable">

                <thead>

                  <tr>

                    <th>Project</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Timeline</th>
                    <th>Value</th>
                    <th>Team</th>
                    <th class="text-end">Actions</th>

                  </tr>

                </thead>

                <tbody>

                  <?php foreach ($projects as $p): ?>

                    <?php

                    [$stLabel, $stClass] =
                      projectStatusBadge(
                        $p['start_date'] ?? '',
                        $p['expected_completion_date'] ?? ''
                      );

                    $engineers =
                      parseMembersConcat(
                        $p['engineers_concat'] ?? ''
                      );

                    ?>

                    <tr data-status="<?php echo strtolower($stLabel); ?>">

                      <!-- PROJECT -->

                      <td data-label="Project">

                        <div class="table-title-cell">

                          <div class="table-icon">
                            <i class="bi bi-building"></i>
                          </div>

                          <div>

                            <div class="table-primary-text">
                              <?php echo e($p['project_name']); ?>
                            </div>

                            <div class="table-secondary-text">

                              <?php echo e($p['agreement_number'] ?? '—'); ?>

                              •

                              <?php echo e($p['project_type'] ?? ''); ?>

                              •

                              <?php echo e($p['project_location'] ?? ''); ?>

                            </div>

                          </div>

                        </div>

                      </td>

                      <!-- CLIENT -->

                      <td data-label="Client">

                        <div class="table-primary-text">

                          <?php echo e($p['client_name']); ?>

                        </div>

                        <div class="table-secondary-text">

                          <?php echo e($p['company_name']); ?>

                        </div>

                      </td>

                      <!-- STATUS -->

                      <td data-label="Status">

                        <span class="badge-pill <?php echo e($stClass); ?>">

                          <span class="mini-dot"></span>

                          <?php echo e($stLabel); ?>

                        </span>

                      </td>

                      <!-- TIMELINE -->

                      <td data-label="Timeline">

                        <div class="table-primary-text">

                          <?php

                          echo !empty($p['start_date'])
                            ? date('d M Y', strtotime($p['start_date']))
                            : '—';

                          ?>

                        </div>

                        <div class="table-secondary-text">

                          to

                          <?php

                          echo !empty($p['expected_completion_date'])
                            ? date('d M Y', strtotime($p['expected_completion_date']))
                            : '—';

                          ?>

                        </div>

                      </td>

                      <!-- VALUE -->

                      <td data-label="Value">

                        <div class="table-primary-text">

                          ₹
                          <?php echo e(showMoney($p['contract_value'])); ?>

                        </div>

                        <div class="table-secondary-text">

                          PMC:
                          ₹
                          <?php echo e(showMoney($p['pmc_charges'])); ?>

                        </div>

                      </td>

                      <!-- TEAM -->

                      <td data-label="Team">

                        <div class="team-text">

                          <div>

                            <b>Manager:</b>

                            <?php

                            echo !empty($p['manager_name'])
                              ? e($p['manager_name'])
                              : 'Not Assigned';

                            ?>

                          </div>

                          <div>

                            <b>Engineers:</b>

                            <?php

                            $engNames = [];

                            foreach (array_slice($engineers, 0, 2) as $eng) {

                              $engNames[] = $eng['name'];

                            }

                            echo !empty($engNames)
                              ? e(implode(', ', $engNames))
                              : 'None';

                            ?>

                          </div>

                        </div>

                      </td>

                      <!-- ACTIONS -->

                      <td data-label="Actions">

                        <div class="action-group">

                          <a href="view-site.php?id=<?php echo (int) $p['id']; ?>" class="action-btn view-btn"
                            title="View">
                            <i class="bi bi-eye"></i>
                          </a>

                          <a href="edit-site.php?id=<?php echo (int) $p['id']; ?>" class="action-btn edit-btn"
                            title="Edit">
                            <i class="bi bi-pencil-square"></i>
                          </a>

                          <?php if (!empty($p['contract_document'])): ?>

                            <a href="<?php echo e($p['contract_document']); ?>" target="_blank" class="action-btn file-btn"
                              title="Contract">
                              <i class="bi bi-file-earmark-arrow-down"></i>
                            </a>

                          <?php endif; ?>

                        </div>

                      </td>

                    </tr>

                  <?php endforeach; ?>

                </tbody>

              </table>

            </div>

            <!-- PAGINATION INFO -->

            <div class="pagination-wrap">

              <div class="pagination-info">

                Showing
                <?php echo count($projects); ?>
                assigned project records

              </div>

            </div>

          </div>

        </div>

      </div>

      <?php include 'includes/footer.php'; ?>

    </main>

  </div>

  <!-- EXPORT MODAL -->

  <div class="modal fade" id="exportModal" tabindex="-1">

    <div class="modal-dialog">

      <div class="modal-content">

        <div class="modal-header">

          <h5 class="modal-title fw-bold">
            Export Projects
          </h5>

          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

        </div>

        <form method="POST" action="export-sites.php">

          <div class="modal-body">

            <div class="mb-3">

              <label class="form-label">
                Export Format
              </label>

              <select class="form-select" name="export_format">

                <option value="csv">CSV</option>
                <option value="excel">Excel</option>
                <option value="pdf">PDF</option>

              </select>

            </div>

          </div>

          <div class="modal-footer">

            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              Cancel
            </button>

            <button type="submit" class="btn btn-success">
              Export
            </button>

          </div>

        </form>

      </div>

    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script src="assets/js/sidebar-toggle.js"></script>

  <script>

    document.addEventListener('DOMContentLoaded', function () {

      const searchInput =
        document.getElementById('projectSearch');

      const statusFilter =
        document.getElementById('statusFilter');

      const tableRows =
        document.querySelectorAll('#projectsTable tbody tr');

      function filterProjects() {

        const searchValue =
          searchInput.value.toLowerCase().trim();

        const statusValue =
          statusFilter.value.toLowerCase().trim();

        tableRows.forEach(function (row) {

          const rowText =
            row.innerText.toLowerCase();

          const rowStatus =
            row.getAttribute('data-status') || '';

          const matchesSearch =
            rowText.includes(searchValue);

          const matchesStatus =
            !statusValue ||
            rowStatus === statusValue;

          row.style.display =
            matchesSearch && matchesStatus
              ? ''
              : 'none';

        });

      }

      searchInput.addEventListener(
        'input',
        filterProjects
      );

      statusFilter.addEventListener(
        'change',
        filterProjects
      );

    });

  </script>

</body>

</html>

<?php
if (isset($conn)) {
  mysqli_close($conn);
}
?>