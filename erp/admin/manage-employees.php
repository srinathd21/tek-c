<?php
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

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

// ✅ Handle POST (Soft delete -> inactive) + PRG redirect to avoid re-submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];

    $stmtD = mysqli_prepare($conn, "UPDATE employees SET employee_status='inactive' WHERE id=? LIMIT 1");
    if (!$stmtD) {
      $_SESSION['flash_error'] = "Database error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param($stmtD, "i", $id);
      if (mysqli_stmt_execute($stmtD)) {
        $_SESSION['flash_success'] = "Employee marked as inactive successfully!";
      } else {
        $_SESSION['flash_error'] = "Error updating employee: " . mysqli_stmt_error($stmtD);
      }
      mysqli_stmt_close($stmtD);
    }
    header("Location: manage-employees.php"); // <-- keep your filename here
    exit;
  }
}

// Flash messages
$success = (string)($_SESSION['flash_success'] ?? '');
$error   = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Fetch employees (exclude deleted if you have deleted_at, otherwise keep as-is)
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

$total_employees    = (int)($stats['total'] ?? 0);
$active_employees   = (int)($stats['active'] ?? 0);
$inactive_employees = (int)($stats['inactive'] ?? 0);
$resigned_employees = (int)($stats['resigned'] ?? 0);
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

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

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

    .btn-add {
      background: var(--blue);
      color: white;
      border: none;
      padding: 10px 18px;
      border-radius: 12px;
      font-weight: 900;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
      text-decoration:none;
      white-space: nowrap;
    }
    .btn-add:hover { background:#2a8bc9; color:#fff; }

    .btn-export {
      background: #10b981;
      color: white;
      border: none;
      padding: 10px 18px;
      border-radius: 12px;
      font-weight: 900;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(16, 185, 129, 0.18);
      white-space: nowrap;
    }
    .btn-export:hover { background:#0da271; color:#fff; }

    .employee-photo {
      width: 38px; height: 38px; border-radius: 10px; overflow: hidden;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      display:flex; align-items:center; justify-content:center;
      color:#fff; font-weight:900; font-size:16px; flex:0 0 auto;
    }
    .employee-photo img { width:100%; height:100%; object-fit:cover; }

    .employee-name{ font-weight:1000; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
    .employee-code{ font-size:11px; color:#6b7280; font-weight:800; line-height:1.2; }

    .role-info{ display:flex; flex-direction:column; gap:4px; }
    .designation-text{ font-size:12px; font-weight:900; color:#2d3748; display:flex; align-items:center; gap:6px; line-height:1.2; }
    .designation-text i{ color: var(--blue); font-size: 13px; }
    .department-badge{
      background: rgba(45,156,219,.1);
      color: var(--blue);
      padding: 3px 8px;
      border-radius: 8px;
      font-size: 10px;
      font-weight: 900;
      border: 1px solid rgba(45,156,219,.2);
      display:inline-flex; align-items:center; gap:4px; width:fit-content;
    }

    .contact-info{ font-size:11px; color:#6b7280; display:flex; align-items:center; gap:6px; margin-top:2px; line-height:1.2; font-weight:800; }
    .contact-info i{ font-size: 11px; }

    .status-badge{
      padding: 3px 8px; border-radius: 20px;
      font-size: 10px; font-weight: 1000;
      text-transform: uppercase; letter-spacing: .3px;
      display:inline-flex; align-items:center; gap:6px;
      white-space: nowrap;
    }
    .status-active{
      background: rgba(16,185,129,.12);
      color:#10b981;
      border:1px solid rgba(16,185,129,.22);
    }
    .status-inactive{
      background: rgba(245,158,11,.12);
      color:#f59e0b;
      border:1px solid rgba(245,158,11,.22);
    }
    .status-resigned{
      background: rgba(239,68,68,.12);
      color:#ef4444;
      border:1px solid rgba(239,68,68,.22);
    }

    .btn-action{
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 6px 9px;
      color: var(--muted);
      font-size: 12px;
      margin-left: 4px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      font-weight:900;
    }
    .btn-action:hover{ background: var(--bg); color: var(--blue); }

    .btn-delete{
      background: transparent;
      border: 1px solid rgba(235,87,87,.25);
      border-radius: 10px;
      padding: 6px 9px;
      color: var(--red);
      font-size: 12px;
      font-weight: 900;
    }
    .btn-delete:hover{ background: rgba(235,87,87,.10); color:#d32f2f; }

    .alert{ border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 10px;
      font-weight: 750;
      outline: none;
    }
    div.dataTables_wrapper .dataTables_filter input:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45,156,219,.1);
    }
    .dataTables_paginate .pagination .page-link{
      border-radius: 10px;
      margin: 0 3px;
      font-weight: 750;
    }

    th.actions-col, td.actions-col { width: 160px !important; white-space: nowrap !important; }

    /* ✅ MOBILE: cards instead of table */
    .emp-card{
      border:1px solid var(--border);
      border-radius:16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding:12px;
    }
    .emp-top{ display:flex; gap:10px; align-items:flex-start; }
    .emp-kv{ margin-top:10px; display:grid; gap:8px; }
    .emp-row{ display:flex; gap:10px; }
    .emp-key{ flex:0 0 90px; color:#6b7280; font-weight:1000; font-size:12px; }
    .emp-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; }
    .emp-actions{ margin-top:10px; display:flex; gap:8px; }
    .emp-actions a, .emp-actions button{ flex:1 1 auto; border-radius:12px; justify-content:center; }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .main{ margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
      .sidebar{ position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
    }
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
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

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Manage Employees</h1>
            <p class="text-muted mb-0">View and manage all employee records</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="add-employee.php" class="btn-add">
              <i class="bi bi-person-plus"></i> Add Employee
            </a>
            <button class="btn-export" data-bs-toggle="modal" data-bs-target="#exportModal">
              <i class="bi bi-download"></i> Export
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

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-people-fill"></i></div>
              <div>
                <div class="stat-label">Total Employees</div>
                <div class="stat-value"><?php echo $total_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-person-check"></i></div>
              <div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?php echo $active_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-person-x"></i></div>
              <div>
                <div class="stat-label">Inactive</div>
                <div class="stat-value"><?php echo $inactive_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-person-dash"></i></div>
              <div>
                <div class="stat-label">Resigned</div>
                <div class="stat-value"><?php echo $resigned_employees; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ✅ MOBILE: Employee Cards -->
        <div class="d-block d-md-none mb-4">
          <?php if (empty($employees)): ?>
            <div class="panel text-muted" style="font-weight:900;">No employees found.</div>
          <?php else: ?>
            <div class="d-grid gap-3">
              <?php foreach ($employees as $employee): ?>
                <?php
                  $stt = trim((string)($employee['employee_status'] ?? 'inactive'));
                  if (!in_array($stt, ['active','inactive','resigned'], true)) $stt = 'inactive';
                  $status_class = 'status-' . $stt;
                  $status_text  = ucfirst($stt);
                  $joining = safeDate($employee['date_of_joining'] ?? '', 'Not Set');
                ?>
                <div class="emp-card">
                  <div class="emp-top">
                    <div class="employee-photo">
                      <?php if (!empty($employee['photo'])): ?>
                        <img src="<?php echo e($employee['photo']); ?>" alt="<?php echo e($employee['full_name']); ?>">
                      <?php else: ?>
                        <?php echo strtoupper(substr((string)$employee['full_name'], 0, 1)); ?>
                      <?php endif; ?>
                    </div>
                    <div style="flex:1 1 auto;">
                      <div class="employee-name"><?php echo e($employee['full_name'] ?? ''); ?></div>
                      <div class="employee-code"><i class="bi bi-hash"></i> <?php echo e($employee['employee_code'] ?? ''); ?></div>
                      <div class="mt-2">
                        <span class="status-badge <?php echo e($status_class); ?>">
                          <i class="bi bi-circle-fill" style="font-size:8px;"></i> <?php echo e($status_text); ?>
                        </span>
                      </div>
                    </div>
                  </div>

                  <div class="emp-kv">
                    <div class="emp-row">
                      <div class="emp-key">Designation</div>
                      <div class="emp-val"><?php echo e($employee['designation'] ?? '—'); ?></div>
                    </div>
                    <div class="emp-row">
                      <div class="emp-key">Department</div>
                      <div class="emp-val"><?php echo e($employee['department'] ?? '—'); ?></div>
                    </div>
                    <div class="emp-row">
                      <div class="emp-key">Mobile</div>
                      <div class="emp-val"><?php echo e($employee['mobile_number'] ?? '—'); ?></div>
                    </div>
                    <div class="emp-row">
                      <div class="emp-key">Email</div>
                      <div class="emp-val"><?php echo e($employee['email'] ?? '—'); ?></div>
                    </div>
                    <div class="emp-row">
                      <div class="emp-key">Joining</div>
                      <div class="emp-val"><?php echo e($joining); ?></div>
                    </div>
                  </div>

                  <div class="emp-actions">
                    <a href="view-employee.php?id=<?php echo (int)$employee['id']; ?>" class="btn btn-outline-primary btn-sm" style="font-weight:900;">
                      <i class="bi bi-eye"></i> View
                    </a>
                    <a href="edit-employee.php?id=<?php echo (int)$employee['id']; ?>" class="btn btn-outline-secondary btn-sm" style="font-weight:900;">
                      <i class="bi bi-pencil"></i> Edit
                    </a>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Mark this employee as inactive?');">
                      <input type="hidden" name="delete_id" value="<?php echo (int)$employee['id']; ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm w-100" style="font-weight:900;">
                        <i class="bi bi-trash"></i> Inactive
                      </button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- ✅ DESKTOP: DataTable -->
        <div class="panel mb-4 d-none d-md-block">
          <div class="panel-header">
            <h3 class="panel-title">Employee Directory</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table id="employeesTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Role & Dept</th>
                  <th>Contact</th>
                  <th>Status</th>
                  <th>Joining</th>
                  <th class="text-end actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($employees as $employee): ?>
                  <?php
                    $stt = trim((string)($employee['employee_status'] ?? 'inactive'));
                    if (!in_array($stt, ['active','inactive','resigned'], true)) $stt = 'inactive';
                    $status_class = 'status-' . $stt;
                    $status_text  = ucfirst($stt);

                    $joining_order = (!empty($employee['date_of_joining']) && $employee['date_of_joining'] !== '0000-00-00')
                      ? strtotime($employee['date_of_joining'])
                      : 0;
                  ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="employee-photo">
                          <?php if (!empty($employee['photo'])): ?>
                            <img src="<?php echo e($employee['photo']); ?>" alt="<?php echo e($employee['full_name']); ?>">
                          <?php else: ?>
                            <?php echo strtoupper(substr((string)$employee['full_name'], 0, 1)); ?>
                          <?php endif; ?>
                        </div>
                        <div>
                          <div class="employee-name"><?php echo e($employee['full_name'] ?? ''); ?></div>
                          <div class="employee-code"><i class="bi bi-hash"></i> <?php echo e($employee['employee_code'] ?? ''); ?></div>
                        </div>
                      </div>
                    </td>

                    <td>
                      <div class="role-info">
                        <?php if (!empty($employee['designation'])): ?>
                          <div class="designation-text"><i class="bi bi-briefcase"></i> <?php echo e($employee['designation']); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($employee['department'])): ?>
                          <div class="department-badge"><i class="bi bi-building"></i> <?php echo e($employee['department']); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($employee['reporting_manager'])): ?>
                          <div class="contact-info"><i class="bi bi-person-badge"></i> Reports to: <?php echo e($employee['reporting_manager']); ?></div>
                        <?php endif; ?>
                      </div>
                    </td>

                    <td>
                      <?php if (!empty($employee['mobile_number'])): ?>
                        <div class="contact-info"><i class="bi bi-telephone"></i> <?php echo e($employee['mobile_number']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($employee['email'])): ?>
                        <div class="contact-info"><i class="bi bi-envelope"></i> <?php echo e($employee['email']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($employee['work_location'])): ?>
                        <div class="contact-info"><i class="bi bi-geo-alt"></i> <?php echo e($employee['work_location']); ?></div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <span class="status-badge <?php echo e($status_class); ?>">
                        <i class="bi bi-circle-fill" style="font-size:8px;"></i> <?php echo e($status_text); ?>
                      </span>
                    </td>

                    <td data-order="<?php echo (int)$joining_order; ?>">
                      <?php echo e(safeDate($employee['date_of_joining'] ?? '', 'Not Set')); ?>
                    </td>

                    <td class="text-end actions-col">
                      <a href="view-employee.php?id=<?php echo (int)$employee['id']; ?>" class="btn-action" title="View">
                        <i class="bi bi-eye"></i>
                      </a>
                      <a href="edit-employee.php?id=<?php echo (int)$employee['id']; ?>" class="btn-action" title="Edit">
                        <i class="bi bi-pencil"></i>
                      </a>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this employee as inactive?');">
                        <input type="hidden" name="delete_id" value="<?php echo (int)$employee['id']; ?>">
                        <button type="submit" class="btn-delete" title="Mark Inactive">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
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
              <select class="form-control" name="export_format" required>
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
          <button type="submit" class="btn-export"><i class="bi bi-download me-2"></i> Export</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-responsive@2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-responsive-bs5@2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
(function () {
  $(function () {
    $('#employeesTable').DataTable({
      responsive: true,
      autoWidth: false,
      scrollX: false,
      pageLength: 10,
      lengthMenu: [[10,25,50,100,-1],[10,25,50,100,'All']],
      order: [[4, 'desc']],
      columnDefs: [
        { targets: [5], orderable: false, searchable: false }
      ],
      language: {
        zeroRecords: "No matching employees found",
        info: "Showing _START_ to _END_ of _TOTAL_ employees",
        infoEmpty: "No employees to show",
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
try {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) { }
?>