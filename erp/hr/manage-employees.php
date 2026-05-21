<?php
// manage-employees.php (TEK-C style like your current page)
// ✅ FETCH profiles + files from ../admin (i.e., ../admin/uploads/...)
// - Normalizes old DB paths to the new location
// - Shows profile photo from ../admin
// - (Optional) Shows passbook/file link in Actions if available

session_start();
require_once 'includes/db-config.php';

// OPTIONAL auth
// if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

$success = '';
$error = '';
$employees = [];

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// Helpers
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='Not Set'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

/**
 * ✅ Normalize file path (photo/passbook) to correct URL (from THIS page)
 * You said: fetch from ../admin
 *
 * New storage (preferred):
 *   DB: admin/uploads/...
 *   FS: ../admin/uploads/...
 *
 * Old storage might be:
 *   uploads/...
 *   /uploads/...
 *   employees/photos/...
 *   employees/passbook/...
 */
function fileUrl($path){
  $p = trim((string)$path);
  if ($p === '') return '';

  // Already correct for this page
  if (stripos($p, '../admin/uploads/') === 0) return $p;

  // If stored as admin/uploads/... -> convert to ../admin/uploads/...
  if (stripos($p, 'admin/uploads/') === 0) return '../' . $p;

  // If stored as /admin/uploads/... -> convert
  if (stripos($p, '/admin/uploads/') === 0) return '..' . $p;

  // If stored as uploads/... (meaning admin/uploads/...) -> convert
  if (stripos($p, 'uploads/') === 0) return '../admin/' . $p;

  // If stored as /uploads/... -> convert
  if (stripos($p, '/uploads/') === 0) return '../admin' . $p;

  // If stored as employees/... -> convert to ../admin/uploads/employees/...
  if (stripos($p, 'employees/') === 0) return '../admin/uploads/' . $p;

  // If stored as /employees/... -> convert
  if (stripos($p, '/employees/') === 0) return '../admin/uploads' . $p;

  // If already absolute URL (http/https), keep as-is
  if (preg_match('~^https?://~i', $p)) return $p;

  // Fallback: if it's some relative file, try to route via ../admin/uploads/
  // (comment this out if you prefer returning as-is)
  return '../admin/uploads/' . ltrim($p, '/');
}

// Handle POST (Soft delete -> inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];

    $stmtD = mysqli_prepare($conn, "UPDATE employees SET employee_status='inactive' WHERE id=? LIMIT 1");
    if (!$stmtD) {
      $error = "Database error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param($stmtD, "i", $id);
      if (mysqli_stmt_execute($stmtD)) {
        $success = "Employee marked as inactive successfully!";
      } else {
        $error = "Error updating employee: " . mysqli_stmt_error($stmtD);
      }
      mysqli_stmt_close($stmtD);
    }
  }
}

// Fetch employees
$res = mysqli_query($conn, "SELECT * FROM employees ORDER BY created_at DESC");
if ($res) {
  $employees = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_free_result($res);
} else {
  $error = "Error fetching employees: " . mysqli_error($conn);
}

// Stats
$stats = ['total'=>0,'active'=>0,'inactive'=>0,'resigned'=>0];
$statsRes = mysqli_query($conn, "SELECT 
  COUNT(*) AS total,
  SUM(CASE WHEN employee_status='active' THEN 1 ELSE 0 END) AS active,
  SUM(CASE WHEN employee_status='inactive' THEN 1 ELSE 0 END) AS inactive,
  SUM(CASE WHEN employee_status='resigned' THEN 1 ELSE 0 END) AS resigned
FROM employees");
if ($statsRes) {
  $row = mysqli_fetch_assoc($statsRes);
  if ($row) $stats = $row;
  mysqli_free_result($statsRes);
}

$total_employees     = (int)($stats['total'] ?? 0);
$active_employees    = (int)($stats['active'] ?? 0);
$inactive_employees  = (int)($stats['inactive'] ?? 0);
$resigned_employees  = (int)($stats['resigned'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Employees - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
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

    .employees-wrapper {
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

    .add-btn {
      background: #2f80ed;
    }

    .add-btn:hover {
      background: #2563eb;
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

    .employee-photo {
      width: 32px;
      height: 32px;
      border-radius: 9px;
      overflow: hidden;
      background: #eff6ff;
      color: #2563eb;
      display: grid;
      place-items: center;
      font-weight: 950;
      font-size: 13px;
      flex: 0 0 auto;
    }

    .employee-photo img {
      width: 100%;
      height: 100%;
      object-fit: cover;
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

    .team-text {
      font-size: 10px;
      color: #64748b;
      line-height: 1.5;
    }

    .badge-pill {
      border-radius: 999px;
      padding: 5px 8px;
      font-weight: 900;
      font-size: 10px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-transform: capitalize;
      white-space: nowrap;
    }

    .mini-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: currentColor;
    }

    .status-active {
      color: #15803d;
      background: #dcfce7;
    }

    .status-inactive {
      color: #c2410c;
      background: #ffedd5;
    }

    .status-resigned {
      color: #dc2626;
      background: #fee2e2;
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
      padding: 0;
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

    .delete-btn {
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

    .alert {
      border-radius: var(--radius);
      border: none;
      box-shadow: var(--shadow);
      margin-bottom: 14px;
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

    @media(max-width:575px) {
      .page-heading {
        align-items: flex-start;
        flex-direction: column;
      }

      .page-heading .d-flex {
        width: 100%;
        flex-wrap: wrap;
      }
    }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>

  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div id="contentScroll" class="content-scroll">
      <div class="container-fluid employees-wrapper px-0">

        <!-- PAGE HEADING -->
        <div class="page-heading">
          <div>
            <h1>Manage Employees</h1>
            <p>View and manage all employee records</p>
          </div>

          <div class="d-flex gap-2">
            <a href="add-employee.php" class="primary-btn add-btn">
              <i class="bi bi-person-plus"></i>
              Add Employee
            </a>

            <button class="primary-btn export-btn" data-bs-toggle="modal" data-bs-target="#exportModal">
              <i class="bi bi-download"></i>
              Export
            </button>
          </div>
        </div>

        <!-- Alerts -->
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

        <!-- STATS -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-people-fill"></i></div>
              <div>
                <div class="stat-label">Total Employees</div>
                <div class="stat-value"><?php echo $total_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-person-check"></i></div>
              <div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?php echo $active_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic orange"><i class="bi bi-person-x"></i></div>
              <div>
                <div class="stat-label">Inactive</div>
                <div class="stat-value"><?php echo $inactive_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-person-dash"></i></div>
              <div>
                <div class="stat-label">Resigned</div>
                <div class="stat-value"><?php echo $resigned_employees; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- PANEL -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <h3 class="panel-title">Employee Directory</h3>
              <div class="panel-subtitle">Search, filter and manage HR employee records</div>
            </div>
          </div>

          <!-- FILTER BAR -->
          <div class="filter-bar">
            <div class="search-box">
              <i class="bi bi-search"></i>
              <input type="text" id="employeeSearch" placeholder="Search name, code, department, contact or location...">
            </div>

            <div>
              <select class="filter-select" id="statusFilter">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="resigned">Resigned</option>
              </select>
            </div>
          </div>

          <!-- TABLE -->
          <div class="compact-table-wrap">
            <table id="employeesTable" class="table compact-table align-middle">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Role & Dept</th>
                  <th>Contact</th>
                  <th>Status</th>
                  <th>Joining</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($employees as $employee): ?>
                  <?php
                    $st = trim((string)($employee['employee_status'] ?? 'inactive'));
                    if (!in_array($st, ['active','inactive','resigned'], true)) $st = 'inactive';
                    $status_class = 'status-' . $st;
                    $status_text  = ucfirst($st);

                    $joining_order = (!empty($employee['date_of_joining']) && $employee['date_of_joining'] !== '0000-00-00')
                      ? strtotime($employee['date_of_joining'])
                      : 0;

                    // ✅ fetch from ../admin
                    $photoSrc    = fileUrl($employee['photo'] ?? '');
                    $passbookSrc = fileUrl($employee['passbook_photo'] ?? '');
                  ?>
                  <tr data-status="<?php echo e($st); ?>">
                    <td data-label="Employee">
                      <div class="table-title-cell">
                        <div class="employee-photo">
                          <?php if (!empty($photoSrc)): ?>
                            <img src="<?php echo e($photoSrc); ?>" alt="<?php echo e($employee['full_name'] ?? ''); ?>">
                          <?php else: ?>
                            <?php echo strtoupper(substr((string)($employee['full_name'] ?? ''), 0, 1)); ?>
                          <?php endif; ?>
                        </div>
                        <div>
                          <div class="table-primary-text"><?php echo e($employee['full_name'] ?? ''); ?></div>
                          <div class="table-secondary-text">
                            #<?php echo e($employee['employee_code'] ?? ''); ?>
                          </div>
                        </div>
                      </div>
                    </td>

                    <td data-label="Role & Dept">
                      <div class="team-text">
                        <?php if (!empty($employee['designation'])): ?>
                          <div>
                            <b>Role:</b>
                            <?php echo e($employee['designation']); ?>
                          </div>
                        <?php endif; ?>

                        <?php if (!empty($employee['department'])): ?>
                          <div>
                            <b>Dept:</b>
                            <?php echo e($employee['department']); ?>
                          </div>
                        <?php endif; ?>

                        <?php if (!empty($employee['reporting_manager'])): ?>
                          <div>
                            <b>Reports:</b>
                            <?php echo e($employee['reporting_manager']); ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </td>

                    <td data-label="Contact">
                      <?php if (!empty($employee['mobile_number'])): ?>
                        <div class="table-primary-text">
                          <?php echo e($employee['mobile_number']); ?>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($employee['email'])): ?>
                        <div class="table-secondary-text">
                          <?php echo e($employee['email']); ?>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($employee['work_location'])): ?>
                        <div class="table-secondary-text">
                          <?php echo e($employee['work_location']); ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td data-label="Status">
                      <span class="badge-pill <?php echo e($status_class); ?>">
                        <span class="mini-dot"></span>
                        <?php echo e($status_text); ?>
                      </span>
                    </td>

                    <td data-label="Joining" data-order="<?php echo (int)$joining_order; ?>">
                      <div class="table-primary-text">
                        <?php echo e(safeDate($employee['date_of_joining'] ?? '', 'Not Set')); ?>
                      </div>
                    </td>

                    <td data-label="Actions">
                      <div class="action-group">
                        <a href="view-employee.php?id=<?php echo (int)$employee['id']; ?>" class="action-btn view-btn" title="View">
                          <i class="bi bi-eye"></i>
                        </a>

                        <a href="edit-employee.php?id=<?php echo (int)$employee['id']; ?>" class="action-btn edit-btn" title="Edit">
                          <i class="bi bi-pencil-square"></i>
                        </a>

                        <?php if (!empty($passbookSrc)): ?>
                          <a href="<?php echo e($passbookSrc); ?>" target="_blank" rel="noopener" class="action-btn file-btn" title="Passbook/File">
                            <i class="bi bi-file-earmark-arrow-down"></i>
                          </a>
                        <?php endif; ?>

                        <form method="POST" onsubmit="return confirm('Mark this employee as inactive?');">
                          <input type="hidden" name="delete_id" value="<?php echo (int)$employee['id']; ?>">
                          <button type="submit" class="action-btn delete-btn" title="Mark Inactive">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
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
              <span id="visibleCount"><?php echo count($employees); ?></span>
              of
              <?php echo count($employees); ?>
              employee records
            </div>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="exportModalLabel">Export Employees</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="export-employees.php">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Export Format *</label>
              <select class="form-select" name="export_format" required>
                <option value="csv">CSV (Excel)</option>
                <option value="pdf">PDF Document</option>
                <option value="excel">Excel File</option>
              </select>
            </div>
            <div class="col-12">
              <div class="alert alert-warning mb-0" role="alert" style="box-shadow:none;">
                <i class="bi bi-info-circle me-2"></i>
                Create <b>export-employees.php</b> if you want export to work.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Export</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const searchInput =
      document.getElementById('employeeSearch');

    const statusFilter =
      document.getElementById('statusFilter');

    const visibleCount =
      document.getElementById('visibleCount');

    const tableRows =
      document.querySelectorAll('#employeesTable tbody tr');

    function filterEmployees() {
      const searchValue =
        searchInput.value.toLowerCase().trim();

      const statusValue =
        statusFilter.value.toLowerCase().trim();

      let shown = 0;

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

        const shouldShow =
          matchesSearch && matchesStatus;

        row.style.display =
          shouldShow ? '' : 'none';

        if (shouldShow) {
          shown++;
        }
      });

      if (visibleCount) {
        visibleCount.textContent = shown;
      }
    }

    searchInput.addEventListener(
      'input',
      filterEmployees
    );

    statusFilter.addEventListener(
      'change',
      filterEmployees
    );
  });
</script>

</body>
</html>
