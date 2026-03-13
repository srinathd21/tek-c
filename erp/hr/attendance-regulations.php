<?php
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

$current_employee_id = $_SESSION['employee_id'] ?? 1;
$current_employee_name = $_SESSION['employee_name'] ?? 'Admin';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// Check if user has admin/manager permissions
$emp_stmt = mysqli_prepare($conn, "SELECT designation FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

$is_admin = in_array($employee['designation'] ?? '', ['Director', 'Manager', 'HR']);

// Handle delete action (HARD DELETE)
if (isset($_GET['delete']) && $is_admin) {
    $delete_id = (int)$_GET['delete'];
    
    // First get the regulation details for logging
    $get_stmt = mysqli_prepare($conn, "SELECT regulation_name FROM attendance_regulations WHERE id = ?");
    mysqli_stmt_bind_param($get_stmt, "i", $delete_id);
    mysqli_stmt_execute($get_stmt);
    $get_result = mysqli_stmt_get_result($get_stmt);
    $reg_to_delete = mysqli_fetch_assoc($get_result);
    mysqli_stmt_close($get_stmt);
    
    if ($reg_to_delete) {
        // HARD DELETE - permanently remove from database
        $delete_stmt = mysqli_prepare($conn, "DELETE FROM attendance_regulations WHERE id = ?");
        mysqli_stmt_bind_param($delete_stmt, "i", $delete_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            // Log the deletion
            logActivity($conn, 'DELETE', 'attendance_regulations', 
                'Permanently deleted attendance regulation: ' . $reg_to_delete['regulation_name'], 
                $delete_id, $reg_to_delete['regulation_name'], null, null);
            
            $_SESSION['flash_success'] = "Regulation permanently deleted successfully!";
        } else {
            $_SESSION['flash_error'] = "Failed to delete regulation.";
        }
        mysqli_stmt_close($delete_stmt);
    } else {
        $_SESSION['flash_error'] = "Regulation not found.";
    }
    
    header("Location: attendance-regulations.php");
    exit();
}

// Fetch all regulations
$regulations = [];
$reg_query = "SELECT * FROM attendance_regulations ORDER BY effective_from DESC";
$reg_result = mysqli_query($conn, $reg_query);
if ($reg_result) {
    $regulations = mysqli_fetch_all($reg_result, MYSQLI_ASSOC);
}

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Attendance Regulations - TEK-C</title>

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

    .btn-action {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 5px 8px;
      color: var(--muted);
      font-size: 12px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
    }
    .btn-action:hover { background: var(--bg); color: var(--blue); }
    
    .btn-delete {
      color: #dc3545;
      border-color: #dc3545;
    }
    .btn-delete:hover {
      background: #dc3545;
      color: white;
    }

    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
    }
    
    /* Delete Modal Styles */
    .modal-content {
      border-radius: var(--radius);
      border: none;
      box-shadow: var(--shadow);
    }
    .modal-header {
      border-bottom: 1px solid var(--border);
      padding: 20px 24px;
    }
    .modal-body {
      padding: 24px;
    }
    .modal-footer {
      border-top: 1px solid var(--border);
      padding: 16px 24px;
    }
    .modal-title {
      font-weight: 900;
      color: #1f2937;
    }
    .btn-modal-cancel {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 30px;
      padding: 8px 20px;
      font-weight: 600;
    }
    .btn-modal-delete {
      background: #dc3545;
      color: white;
      border: none;
      border-radius: 30px;
      padding: 8px 20px;
      font-weight: 600;
    }
    .btn-modal-delete:hover {
      background: #bb2d3b;
    }
    .warning-text {
      color: #dc3545;
      font-weight: 600;
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

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Attendance Regulations</h1>
            <p class="text-muted mb-0">Manage work timing policies and attendance rules</p>
          </div>
          <div class="d-flex gap-2">
            <?php if ($is_admin): ?>
            <a href="add-regulation.php" class="btn-action" style="background: var(--blue); color: white; border: none; padding: 8px 16px;">
              <i class="bi bi-plus-circle"></i> Add New Regulation
            </a>
            <?php endif; ?>
            <a href="punchin.php" class="btn-action">
              <i class="bi bi-arrow-left"></i> Back to Punch
            </a>
          </div>
        </div>

        <?php if ($flash_success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($flash_success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($flash_error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($flash_error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Regulations List -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">Attendance Regulations</h3>
          </div>

          <div class="table-responsive">
            <table id="regulationsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Regulation Name</th>
                  <th>Applicable To</th>
                  <th>Work Hours</th>
                  <th>Grace Period</th>
                  <th>Min Hours</th>
                  <th>Punch Types</th>
                  <th>Effective</th>
                  <th>Status</th>
                  <?php if ($is_admin): ?><th>Actions</th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($regulations as $reg): ?>
                <tr>
                  <td>
                    <div class="fw-bold"><?= htmlspecialchars($reg['regulation_name']) ?></div>
                  </td>
                  <td>
                    <span class="badge bg-light text-dark"><?= htmlspecialchars($reg['applicable_to']) ?></span>
                  </td>
                  <td>
                    <?= date('h:i A', strtotime($reg['work_start_time'])) ?> - 
                    <?= date('h:i A', strtotime($reg['work_end_time'])) ?>
                  </td>
                  <td><?= (int)$reg['grace_period_minutes'] ?> min</td>
                  <td>
                    Full: <?= $reg['min_work_hours_full_day'] ?>h<br>
                    Half: <?= $reg['min_work_hours_half_day'] ?>h
                  </td>
                  <td>
                    <?php if ($reg['allow_office_punch']): ?>
                      <span class="badge bg-primary" style="font-size:9px;">Office</span>
                    <?php endif; ?>
                    <?php if ($reg['allow_site_punch']): ?>
                      <span class="badge bg-success" style="font-size:9px;">Site</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <small>
                      From: <?= date('d M Y', strtotime($reg['effective_from'])) ?><br>
                      <?php if ($reg['effective_to']): ?>
                        To: <?= date('d M Y', strtotime($reg['effective_to'])) ?>
                      <?php endif; ?>
                    </small>
                  </td>
                  <td>
                    <?php if ($reg['is_active']): ?>
                      <span class="status-badge status-green">
                        <i class="bi bi-check-circle-fill"></i> Active
                      </span>
                    <?php else: ?>
                      <span class="status-badge status-red">
                        <i class="bi bi-x-circle-fill"></i> Inactive
                      </span>
                    <?php endif; ?>
                  </td>
                  <?php if ($is_admin): ?>
                  <td>
                    <div class="d-flex gap-1">
                      <a href="edit-regulation.php?id=<?= $reg['id'] ?>" class="btn-action" title="Edit">
                        <i class="bi bi-pencil"></i>
                      </a>
                      <a href="toggle-regulation.php?id=<?= $reg['id'] ?>" class="btn-action" title="<?= $reg['is_active'] ? 'Deactivate' : 'Activate' ?>">
                        <i class="bi bi-<?= $reg['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                      </a>
                      <button type="button" class="btn-action btn-delete" title="Permanently Delete" 
                              onclick="confirmDelete(<?= $reg['id'] ?>, '<?= htmlspecialchars($reg['regulation_name'], ENT_QUOTES) ?>')">
                        <i class="bi bi-trash"></i>
                      </button>
                    </div>
                  </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($regulations)): ?>
                <tr>
                  <td colspan="<?= $is_admin ? '9' : '8' ?>" class="text-center py-4 text-muted">
                    No attendance regulations found.
                    <?php if ($is_admin): ?>
                      <a href="add-regulation.php" class="text-primary">Add your first regulation</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Info Panel -->
        <div class="row g-3">
          <div class="col-md-6">
            <div class="panel">
              <h4 class="panel-title mb-3">How Regulations Work</h4>
              <ul class="list-unstyled">
                <li class="mb-2"><i class="bi bi-dot text-primary"></i> <strong>Work Hours:</strong> Define standard working hours for different employee types</li>
                <li class="mb-2"><i class="bi bi-dot text-primary"></i> <strong>Grace Period:</strong> Employees can punch in late within this window without penalty</li>
                <li class="mb-2"><i class="bi bi-dot text-primary"></i> <strong>Half Day:</strong> If employee works less than full day but more than half-day minimum</li>
                <li class="mb-2"><i class="bi bi-dot text-primary"></i> <strong>Punch Types:</strong> Restrict where employees can punch from (office/site)</li>
              </ul>
            </div>
          </div>
          <div class="col-md-6">
            <div class="panel">
              <h4 class="panel-title mb-3">Current Default Rules</h4>
              <ul class="list-unstyled">
                <li class="mb-2"><i class="bi bi-clock text-success"></i> <strong>Standard Work Day:</strong> 9:00 AM - 6:00 PM</li>
                <li class="mb-2"><i class="bi bi-alarm text-warning"></i> <strong>Grace Period:</strong> 15 minutes</li>
                <li class="mb-2"><i class="bi bi-hourglass-split text-info"></i> <strong>Half Day:</strong> Minimum 4 hours</li>
                <li class="mb-2"><i class="bi bi-building text-primary"></i> <strong>Office/Site Punch:</strong> Allowed for designated employees</li>
              </ul>
            </div>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteModalLabel">⚠️ Confirm Permanent Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Are you sure you want to <span class="warning-text">permanently delete</span> the regulation:</p>
        <p class="fw-bold text-center my-3" id="deleteRegulationName"></p>
        <div class="alert alert-danger">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          <strong>Warning:</strong> This action cannot be undone. The regulation will be permanently removed from the database.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
        <a href="#" id="confirmDeleteBtn" class="btn-modal-delete">Permanently Delete</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(function () {
  $('#regulationsTable').DataTable({
    responsive: true,
    autoWidth: false,
    pageLength: 10,
    order: [[6, 'desc']],
    language: {
      zeroRecords: "No regulations found",
      info: "Showing _START_ to _END_ of _TOTAL_ entries",
      search: "Search:"
    }
  });
});

// Delete confirmation modal
function confirmDelete(id, name) {
  document.getElementById('deleteRegulationName').textContent = name;
  document.getElementById('confirmDeleteBtn').href = '?delete=' + id;
  
  var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
  deleteModal.show();
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
  document.querySelectorAll('.alert').forEach(function(alert) {
    var bsAlert = new bootstrap.Alert(alert);
    bsAlert.close();
  });
}, 5000);
</script>
</body>
</html>