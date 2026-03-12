<?php
// employees.php
session_start();
require_once 'includes/db-config.php';

// Initialize variables
$success = '';
$error = '';
$employees = [];

// Get database connection
$conn = get_db_connection();
if (!$conn) {
  die("Database connection failed.");
}

// Helper: safely unlink file if exists
function safe_unlink($path) {
  if (!empty($path) && is_string($path) && file_exists($path) && is_file($path)) {
    @unlink($path);
  }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
  $id = (int)($_POST['delete_id'] ?? 0);

  if ($id > 0) {

    // Fetch file paths to delete from disk
    $sel = mysqli_prepare($conn, "SELECT photo_path, aadhar_photo_path, pancard_photo_path, passbook_photo_path FROM employees WHERE id = ?");
    if ($sel) {
      mysqli_stmt_bind_param($sel, "i", $id);
      mysqli_stmt_execute($sel);
      $res = mysqli_stmt_get_result($sel);

      if ($res && $row = mysqli_fetch_assoc($res)) {
        safe_unlink($row['photo_path'] ?? '');
        safe_unlink($row['aadhar_photo_path'] ?? '');
        safe_unlink($row['pancard_photo_path'] ?? '');
        safe_unlink($row['passbook_photo_path'] ?? '');
      }
      mysqli_stmt_close($sel);
    }

    // Delete employee row
    $del = mysqli_prepare($conn, "DELETE FROM employees WHERE id = ?");
    if ($del) {
      mysqli_stmt_bind_param($del, "i", $id);
      if (mysqli_stmt_execute($del)) {
        $success = "Employee deleted successfully!";
      } else {
        $error = "Error deleting employee: " . mysqli_stmt_error($del);
      }
      mysqli_stmt_close($del);
    } else {
      $error = "Prepare failed: " . mysqli_error($conn);
    }
  }
}

// Handle status update (employee_status)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
  $id = (int)($_POST['employee_id'] ?? 0);
  $status = trim($_POST['employee_status'] ?? 'active');

  $allowed = ['active','inactive','resigned'];
  if (!in_array($status, $allowed, true)) {
    $status = 'active';
  }

  if ($id > 0) {
    $upd = mysqli_prepare($conn, "UPDATE employees SET employee_status = ? WHERE id = ?");
    if ($upd) {
      mysqli_stmt_bind_param($upd, "si", $status, $id);
      if (mysqli_stmt_execute($upd)) {
        $success = "Employee status updated successfully!";
      } else {
        $error = "Error updating status: " . mysqli_stmt_error($upd);
      }
      mysqli_stmt_close($upd);
    } else {
      $error = "Prepare failed: " . mysqli_error($conn);
    }
  }
}

// Fetch all employees
$result = mysqli_query($conn, "SELECT * FROM employees ORDER BY created_at DESC");
if ($result) {
  $employees = mysqli_fetch_all($result, MYSQLI_ASSOC);
  mysqli_free_result($result);
}

// Counts
function get_count($conn, $where = "", $paramTypes = "", $params = []) {
  $sql = "SELECT COUNT(*) AS c FROM employees" . ($where ? " WHERE $where" : "");
  $stmt = mysqli_prepare($conn, $sql);
  if (!$stmt) return 0;

  if ($paramTypes && !empty($params)) {
    mysqli_stmt_bind_param($stmt, $paramTypes, ...$params);
  }
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = $res ? mysqli_fetch_assoc($res) : null;
  mysqli_stmt_close($stmt);
  return (int)($row['c'] ?? 0);
}

$total_employees   = get_count($conn);
$active_employees  = get_count($conn, "employee_status='active'");
$inactive_employees= get_count($conn, "employee_status='inactive'");
$resigned_employees= get_count($conn, "employee_status='resigned'");

$eng_employees     = get_count($conn, "department='Engineering'");
$qs_employees      = get_count($conn, "department='QS'");
$acc_employees     = get_count($conn, "department='Accounts'");
$hr_employees      = get_count($conn, "department='HR'");

// Mask bank account helper
function mask_account($acc) {
  $acc = (string)$acc;
  $len = strlen($acc);
  if ($len <= 8) return $acc; // too short, show as-is
  return substr($acc, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($acc, -4);
}
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
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: var(--green); }
    .stat-ic.red{ background: var(--red); }
    .stat-ic.orange{ background: var(--orange); }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    /* Table borders */
    .table, .table th, .table td {
      border: 1px solid #dee2e6;
    }
    .table th, .table td {
      padding: 10px;
      text-align: center;
    }

    .btn-add {
      background: var(--blue);
      color: white;
      border: none;
      padding: 10px 18px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
      text-decoration: none;
    }
    .btn-add:hover { background:#2a8bc9; color:#fff; box-shadow: 0 12px 24px rgba(45, 156, 219, 0.25); }

    .employee-avatar {
      width: 40px; height: 40px; border-radius: 50%;
      object-fit: cover; border: 2px solid var(--border);
    }

    .btn-delete {
      background: transparent;
      border: 1px solid rgba(235, 87, 87, 0.2);
      border-radius: 8px;
      padding: 6px 10px;
      color: var(--red);
      font-size: 12px;
    }
    .btn-delete:hover { background: rgba(235, 87, 87, 0.1); color:#d32f2f; }

    .btn-edit {
      background: transparent;
      border: 1px solid rgba(242, 201, 76, 0.2);
      border-radius: 8px;
      padding: 6px 10px;
      color: var(--yellow);
      font-size: 12px;
      margin-right: 5px;
      text-decoration:none;
    }
    .btn-edit:hover { background: rgba(242, 201, 76, 0.1); color:#b7791f; }

    .status-form { display:inline; }
    .status-select {
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 6px 10px;
      font-size: 12px;
      font-weight: 800;
      background:#fff;
    }

    .small-muted{ font-size:11px; color: var(--muted); font-weight:700; margin-top:2px; }
    .doc-line{ font-size:11px; color: var(--muted); margin-top:4px; line-height:1.25; }

    .alert { border-radius: var(--radius); border: none; box-shadow: var(--shadow); margin-bottom: 20px; }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .table-responsive { overflow-x:auto; }
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
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Manage Employees</h1>
            <p class="text-muted mb-0">Add and manage employee information</p>
          </div>
          <a href="add-employee.php" class="btn-add">
            <i class="bi bi-plus-lg"></i> Add New Employee
          </a>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-people-fill"></i></div>
              <div>
                <div class="stat-label">Total</div>
                <div class="stat-value"><?php echo (int)$total_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-check-circle-fill"></i></div>
              <div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?php echo (int)$active_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-ic red"><i class="bi bi-x-circle-fill"></i></div>
              <div>
                <div class="stat-label">Inactive</div>
                <div class="stat-value"><?php echo (int)$inactive_employees; ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="stat-card">
              <div class="stat-ic orange"><i class="bi bi-box-arrow-right"></i></div>
              <div>
                <div class="stat-label">Resigned</div>
                <div class="stat-value"><?php echo (int)$resigned_employees; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Table -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">Employee Directory</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th style="min-width:60px;">Photo</th>
                  <th style="min-width:240px;">Employee</th>
                  <th style="min-width:180px;">Position</th>
                  <th style="min-width:130px;">Status</th>
                  <th style="min-width:110px;">Added On</th>
                  <th class="text-end" style="width:150px;">Actions</th>
                </tr>
              </thead>

              <tbody>
              <?php if (empty($employees)): ?>
                <tr>
                  <td colspan="6" class="text-center py-4">
                    <div class="text-muted" style="font-weight:800;">No employees added yet.</div>
                    <div class="small-muted">
                      <a href="add-employee.php" class="text-primary" style="font-weight:900;">Add your first employee</a>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                  <?php
                    $createdTs = strtotime($emp['created_at'] ?? '');
                    $createdOn = $createdTs ? date('d M Y', $createdTs) : '';
                    $status = $emp['employee_status'] ?? 'active';
                  ?>
                  <tr>
                    <td>
                      <?php if (!empty($emp['photo_path']) && file_exists($emp['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($emp['photo_path']); ?>" class="employee-avatar" alt="Photo">
                      <?php else: ?>
                        <div class="employee-avatar bg-secondary d-flex align-items-center justify-content-center text-white">
                          <i class="bi bi-person"></i>
                        </div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div class="d-flex flex-column">
                        <strong><?php echo htmlspecialchars($emp['full_name'] ?? ''); ?></strong>
                        <div class="small-muted">Code: <?php echo htmlspecialchars($emp['employee_code'] ?? ''); ?></div>
                      </div>
                    </td>

                    <td>
                      <div class="badge bg-primary">
                        <?php echo htmlspecialchars($emp['designation'] ?? ''); ?>
                      </div>
                    </td>

                    <td>
                      <form method="POST" class="status-form">
                        <input type="hidden" name="employee_id" value="<?php echo (int)($emp['id'] ?? 0); ?>">
                        <input type="hidden" name="update_status" value="1">
                        <select name="employee_status" class="status-select" onchange="this.form.submit()">
                          <option value="active" <?php echo ($status === 'active') ? 'selected' : ''; ?>>active</option>
                          <option value="inactive" <?php echo ($status === 'inactive') ? 'selected' : ''; ?>>inactive</option>
                          <option value="resigned" <?php echo ($status === 'resigned') ? 'selected' : ''; ?>>resigned</option>
                        </select>
                      </form>
                    </td>

                    <td><?php echo htmlspecialchars($createdOn); ?></td>

                    <td class="text-end">
                      <a href="view-employee.php?id=<?php echo (int)($emp['id'] ?? 0); ?>" class="btn-edit" title="View">
                        <i class="bi bi-eye"></i>
                      </a>
                      <a href="edit-employee.php?id=<?php echo (int)($emp['id'] ?? 0); ?>" class="btn-edit" title="Modify">
                        <i class="bi bi-pencil"></i>
                      </a>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this employee?');">
                        <input type="hidden" name="delete_id" value="<?php echo (int)($emp['id'] ?? 0); ?>">
                        <button type="submit" class="btn-delete" title="Delete">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>

            </table>
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
    const yearElement = document.getElementById("year");
    if (yearElement) yearElement.textContent = new Date().getFullYear();
  });
</script>

</body>
</html>

<?php
if (isset($conn)) {
  mysqli_close($conn);
}
?>
