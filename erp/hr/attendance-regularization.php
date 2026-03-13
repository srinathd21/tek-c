<?php
// hr/employee-regulations.php
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH (HR) ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$designation = trim((string)($_SESSION['designation'] ?? ''));
$department  = trim((string)($_SESSION['department'] ?? ''));

$isHr = (strtolower($designation) === 'hr') || (strtolower($department) === 'hr');
if (!$isHr) {
  $fallback = $_SESSION['role_redirect'] ?? '../login.php';
  header("Location: " . $fallback);
  exit;
}

// ---------------- HANDLE FORM SUBMISSIONS ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Add new employee regulation
    if ($action === 'add_regulation' && isset($_POST['employee_id'])) {
      $employee_id = (int)$_POST['employee_id'];
      $regulation_type = mysqli_real_escape_string($conn, $_POST['regulation_type']);
      $effective_date = mysqli_real_escape_string($conn, $_POST['effective_date']);
      $expiry_date = !empty($_POST['expiry_date']) ? mysqli_real_escape_string($conn, $_POST['expiry_date']) : null;
      $description = mysqli_real_escape_string($conn, $_POST['description']);
      $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
      $created_by = (int)$_SESSION['employee_id'];
      
      // Generate regulation number
      $reg_no = 'EMPREG-' . date('Ymd') . '-' . str_pad($employee_id, 4, '0', STR_PAD_LEFT);
      
      $stmt = mysqli_prepare($conn, "
        INSERT INTO employee_regulations 
        (employee_id, regulation_no, regulation_type, effective_date, expiry_date, description, remarks, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ");
      
      mysqli_stmt_bind_param($stmt, "issssssi", 
        $employee_id, $reg_no, $regulation_type, $effective_date, $expiry_date, $description, $remarks, $created_by
      );
      
      if (mysqli_stmt_execute($stmt)) {
        $reg_id = mysqli_insert_id($conn);
        
        // Log activity
        $log_stmt = mysqli_prepare($conn, "
          INSERT INTO activity_logs (user_id, user_name, user_role, action_type, module, module_id, module_name, description, new_data, ip_address, user_agent)
          VALUES (?, ?, ?, 'CREATE', 'employee_regulations', ?, ?, 'Created employee-specific regulation', ?, ?, ?)
        ");
        $user_id = $_SESSION['employee_id'];
        $user_name = $_SESSION['employee_name'] ?? 'HR';
        $user_role = $_SESSION['designation'] ?? 'hr';
        $module_name = $reg_no;
        $new_data_json = json_encode($_POST);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        mysqli_stmt_bind_param($log_stmt, "ississss", $user_id, $user_name, $user_role, $reg_id, $module_name, $new_data_json, $ip, $ua);
        mysqli_stmt_execute($log_stmt);
        
        $message = "Employee regulation added successfully!";
        $messageType = "success";
      } else {
        $message = "Error adding regulation: " . mysqli_error($conn);
        $messageType = "danger";
      }
    }
    
    // Cancel regulation
    elseif ($action === 'cancel_regulation' && isset($_POST['regulation_id'])) {
      $regulation_id = (int)$_POST['regulation_id'];
      $cancellation_reason = mysqli_real_escape_string($conn, $_POST['cancellation_reason'] ?? '');
      $cancelled_by = (int)$_SESSION['employee_id'];
      $cancelled_at = date('Y-m-d H:i:s');
      
      $stmt = mysqli_prepare($conn, "
        UPDATE employee_regulations 
        SET status = 'Cancelled', cancelled_by = ?, cancelled_at = ?, cancellation_reason = ?
        WHERE id = ?
      ");
      
      mysqli_stmt_bind_param($stmt, "issi", $cancelled_by, $cancelled_at, $cancellation_reason, $regulation_id);
      
      if (mysqli_stmt_execute($stmt)) {
        $message = "Regulation cancelled successfully!";
        $messageType = "success";
      } else {
        $message = "Error cancelling regulation: " . mysqli_error($conn);
        $messageType = "danger";
      }
    }
  }
}

// ---------------- FETCH EMPLOYEES FOR DROPDOWN ----------------
$employees = [];
$stmt = mysqli_prepare($conn, "
  SELECT id, full_name, employee_code, department, designation 
  FROM employees 
  WHERE employee_status = 'active'
  ORDER BY full_name ASC
");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
  $employees[] = $row;
}

// ---------------- FETCH ACTIVE REGULATIONS ----------------
$active_regulations = [];
$stmt = mysqli_prepare($conn, "
  SELECT er.*, e.full_name as employee_name, e.employee_code, e.department,
         c.full_name as created_by_name
  FROM employee_regulations er
  LEFT JOIN employees e ON er.employee_id = e.id
  LEFT JOIN employees c ON er.created_by = c.id
  WHERE er.status = 'Active' 
    AND (er.expiry_date IS NULL OR er.expiry_date >= CURDATE())
  ORDER BY er.created_at DESC
");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
  $active_regulations[] = $row;
}

// ---------------- FETCH EXPIRED/CANCELLED REGULATIONS ----------------
$history_regulations = [];
$stmt = mysqli_prepare($conn, "
  SELECT er.*, e.full_name as employee_name, e.employee_code, e.department,
         c.full_name as created_by_name, can.full_name as cancelled_by_name
  FROM employee_regulations er
  LEFT JOIN employees e ON er.employee_id = e.id
  LEFT JOIN employees c ON er.created_by = c.id
  LEFT JOIN employees can ON er.cancelled_by = can.id
  WHERE er.status != 'Active' OR (er.expiry_date IS NOT NULL AND er.expiry_date < CURDATE())
  ORDER BY er.updated_at DESC
  LIMIT 50
");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
  $history_regulations[] = $row;
}

// ---------------- HELPER FUNCTIONS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function getRegulationTypeBadge($type) {
  $colors = [
    'Late Permission' => 'warning',
    'Early Exit' => 'info',
    'Remote Work' => 'success',
    'Overtime' => 'primary',
    'Flexi Hours' => 'secondary',
    'Other' => 'dark'
  ];
  $color = $colors[$type] ?? 'secondary';
  return "<span class='badge bg-{$color} rounded-pill'>{$type}</span>";
}

function getStatusBadge($status) {
  switch ($status) {
    case 'Active':
      return '<span class="badge bg-success rounded-pill">Active</span>';
    case 'Expired':
      return '<span class="badge bg-secondary rounded-pill">Expired</span>';
    case 'Cancelled':
      return '<span class="badge bg-danger rounded-pill">Cancelled</span>';
    default:
      return '<span class="badge bg-dark rounded-pill">Unknown</span>';
  }
}

$loggedName = $_SESSION['employee_name'] ?? 'HR';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Employee Regulations - HR - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px 22px 14px; }
    .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 20px; margin-bottom: 20px; }
    .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; }
    .panel-title { font-weight: 900; font-size: 18px; color: #1f2937; margin: 0; }
    
    .table thead th { font-size: 12px; letter-spacing: .2px; color: #6b7280; font-weight: 800; border-bottom: 1px solid var(--border) !important; }
    .table td { vertical-align: middle; border-color: var(--border); font-weight: 650; color: #374151; padding: 12px 8px; }
    
    .employee-info { display: flex; align-items: center; gap: 10px; }
    .employee-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--yellow), #ffd66b); display: flex; align-items: center; justify-content: center; font-weight: 900; color: #1f2937; }
    
    .form-label { font-weight: 800; font-size: 13px; color: #4b5563; margin-bottom: 4px; }
    .required:after { content: " *"; color: var(--red); }
    
    .nav-tabs .nav-link { font-weight: 800; color: #6b7280; border: none; padding: 10px 20px; }
    .nav-tabs .nav-link.active { color: var(--green); border-bottom: 3px solid var(--green); background: none; }
    
    .stats-card { background: #f8fafc; border-radius: var(--radius); padding: 15px; border: 1px solid var(--border); }
    .stats-number { font-size: 28px; font-weight: 900; line-height: 1; }
    .stats-label { color: #6b7280; font-weight: 700; font-size: 13px; }
    
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
          <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
              <h2 class="fw-900 mb-1" style="color:#1f2937;">Employee Regulations</h2>
              <p class="text-muted" style="font-weight:650;">Manage employee-specific attendance exceptions and permissions</p>
            </div>
            <div>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeRegulationModal">
                <i class="bi bi-plus-lg"></i> New Employee Exception
              </button>
              <a href="attendance-regulations.php" class="btn btn-outline-secondary ms-2">
                <i class="bi bi-arrow-left"></i> Back to Company Regulations
              </a>
            </div>
          </div>

          <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
              <?php echo e($message); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>

          <!-- Stats Cards -->
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <div class="stats-card">
                <div class="stats-number"><?php echo count($active_regulations); ?></div>
                <div class="stats-label">Active Employee Exceptions</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="stats-card">
                <div class="stats-number">
                  <?php
                    $late_count = 0;
                    $remote_count = 0;
                    foreach ($active_regulations as $r) {
                      if ($r['regulation_type'] === 'Late Permission') $late_count++;
                      if ($r['regulation_type'] === 'Remote Work') $remote_count++;
                    }
                    echo $late_count + $remote_count;
                  ?>
                </div>
                <div class="stats-label">Late/Remote Exceptions</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="stats-card">
                <div class="stats-number"><?php echo count($history_regulations); ?></div>
                <div class="stats-label">Past Exceptions</div>
              </div>
            </div>
          </div>

          <!-- Tabs -->
          <ul class="nav nav-tabs mb-3" id="regulationTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="active-tab" data-bs-toggle="tab" data-bs-target="#active" type="button" role="tab">
                <i class="bi bi-check-circle"></i> Active Exceptions
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                <i class="bi bi-archive"></i> History
              </button>
            </li>
          </ul>

          <!-- Tab Content -->
          <div class="tab-content" id="regulationTabContent">
            <!-- Active Exceptions Tab -->
            <div class="tab-pane fade show active" id="active" role="tabpanel">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Active Employee Exceptions</h3>
                </div>

                <div class="table-responsive">
                  <table class="table" id="activeRegulationsTable">
                    <thead>
                      <tr>
                        <th>Reg No.</th>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Effective Period</th>
                        <th>Description</th>
                        <th>Created By</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($active_regulations)): ?>
                       
                      <?php else: ?>
                        <?php foreach ($active_regulations as $reg): ?>
                          <tr>
                            <td><span class="fw-800"><?php echo e($reg['regulation_no']); ?></span></td>
                            <td>
                              <div class="employee-info">
                                <div class="employee-avatar"><?php echo e(substr($reg['employee_name'] ?? 'U', 0, 1)); ?></div>
                                <div>
                                  <div class="fw-900"><?php echo e($reg['employee_name'] ?? 'Unknown'); ?></div>
                                  <div class="small text-muted"><?php echo e($reg['employee_code']); ?> • <?php echo e($reg['department'] ?? 'N/A'); ?></div>
                                </div>
                              </div>
                            </td>
                            <td><?php echo getRegulationTypeBadge($reg['regulation_type']); ?></td>
                            <td>
                              <div><i class="bi bi-calendar-check"></i> <?php echo date('d M Y', strtotime($reg['effective_date'])); ?></div>
                              <?php if ($reg['expiry_date']): ?>
                                <div class="small text-muted">Until <?php echo date('d M Y', strtotime($reg['expiry_date'])); ?></div>
                              <?php else: ?>
                                <div class="small text-muted">Indefinite</div>
                              <?php endif; ?>
                            </td>
                            <td>
                              <div class="fw-650"><?php echo e(substr($reg['description'], 0, 50)) . (strlen($reg['description']) > 50 ? '...' : ''); ?></div>
                              <?php if ($reg['remarks']): ?>
                                <div class="small text-muted"><i class="bi bi-chat"></i> <?php echo e(substr($reg['remarks'], 0, 30)); ?></div>
                              <?php endif; ?>
                            </td>
                            <td>
                              <div><?php echo e($reg['created_by_name'] ?? 'HR'); ?></div>
                              <div class="small text-muted"><?php echo date('d M Y', strtotime($reg['created_at'])); ?></div>
                            </td>
                            <td>
                              <button class="btn btn-sm btn-outline-danger cancel-regulation" 
                                      data-id="<?php echo (int)$reg['id']; ?>"
                                      data-name="<?php echo e($reg['regulation_no']); ?>"
                                      title="Cancel Exception">
                                <i class="bi bi-x-circle"></i>
                              </button>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- History Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Past Exceptions</h3>
                </div>

                <div class="table-responsive">
                  <table class="table" id="historyRegulationsTable">
                    <thead>
                      <tr>
                        <th>Reg No.</th>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Period</th>
                        <th>Description</th>
                        <th>Cancelled/Expired</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($history_regulations)): ?>
                       
                      <?php else: ?>
                        <?php foreach ($history_regulations as $reg): 
                          $status = $reg['status'];
                          if ($reg['expiry_date'] && $reg['expiry_date'] < date('Y-m-d') && $status === 'Active') {
                            $status = 'Expired';
                          }
                        ?>
                          <tr>
                            <td><span class="fw-800"><?php echo e($reg['regulation_no']); ?></span></td>
                            <td>
                              <div><?php echo e($reg['employee_name'] ?? 'Unknown'); ?></div>
                              <div class="small text-muted"><?php echo e($reg['employee_code']); ?></div>
                            </td>
                            <td><?php echo getRegulationTypeBadge($reg['regulation_type']); ?></td>
                            <td><?php echo getStatusBadge($status); ?></td>
                            <td>
                              <?php echo date('d M Y', strtotime($reg['effective_date'])); ?>
                              <?php if ($reg['expiry_date']): ?>
                                <br><small>to <?php echo date('d M Y', strtotime($reg['expiry_date'])); ?></small>
                              <?php endif; ?>
                            </td>
                            <td><?php echo e(substr($reg['description'], 0, 60)); ?></td>
                            <td>
                              <?php if ($reg['cancelled_at']): ?>
                                <div><?php echo date('d M Y', strtotime($reg['cancelled_at'])); ?></div>
                                <div class="small text-muted">by <?php echo e($reg['cancelled_by_name'] ?? 'HR'); ?></div>
                              <?php elseif ($reg['expiry_date'] && $reg['expiry_date'] < date('Y-m-d')): ?>
                                <div>Expired</div>
                                <div class="small text-muted"><?php echo date('d M Y', strtotime($reg['expiry_date'])); ?></div>
                              <?php endif; ?>
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

        </div>
      </div>

      <?php include 'includes/footer.php'; ?>
    </main>
  </div>

  <!-- Add Employee Regulation Modal -->
  <div class="modal fade" id="addEmployeeRegulationModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-900">Add Employee Exception</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="add_regulation">
          
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label required">Select Employee</label>
                <select class="form-select" name="employee_id" required>
                  <option value="">Choose employee...</option>
                  <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo (int)$emp['id']; ?>">
                      <?php echo e($emp['full_name']); ?> (<?php echo e($emp['employee_code']); ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <div class="col-12">
                <label class="form-label required">Regulation Type</label>
                <select class="form-select" name="regulation_type" required>
                  <option value="">Select type...</option>
                  <option value="Late Permission">Late Permission</option>
                  <option value="Early Exit">Early Exit</option>
                  <option value="Remote Work">Remote Work</option>
                  <option value="Overtime">Overtime</option>
                  <option value="Flexi Hours">Flexi Hours</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              
              <div class="col-md-6">
                <label class="form-label required">Effective From</label>
                <input type="date" class="form-control" name="effective_date" required value="<?php echo date('Y-m-d'); ?>">
              </div>
              
              <div class="col-md-6">
                <label class="form-label">Effective To</label>
                <input type="date" class="form-control" name="expiry_date">
                <div class="form-text">Leave blank for indefinite</div>
              </div>
              
              <div class="col-12">
                <label class="form-label required">Description</label>
                <textarea class="form-control" name="description" rows="3" required 
                          placeholder="E.g., Allowed to report at 10:00 AM on weekdays"></textarea>
              </div>
              
              <div class="col-12">
                <label class="form-label">Remarks (Optional)</label>
                <textarea class="form-control" name="remarks" rows="2" 
                          placeholder="Additional notes..."></textarea>
              </div>
            </div>
          </div>
          
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Add Exception</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Cancel Regulation Modal -->
  <div class="modal fade" id="cancelRegulationModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-900">Cancel Exception</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="cancel_regulation">
          <input type="hidden" name="regulation_id" id="cancelId">
          
          <div class="modal-body">
            <p>Are you sure you want to cancel <strong id="cancelName"></strong>?</p>
            
            <div class="mt-3">
              <label class="form-label">Cancellation Reason</label>
              <textarea class="form-control" name="cancellation_reason" rows="2" required 
                        placeholder="Reason for cancelling this exception..."></textarea>
            </div>
          </div>
          
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-danger">Cancel Exception</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="assets/js/sidebar-toggle.js"></script>

  <script>
    $(document).ready(function() {
      // Initialize DataTables
      $('#activeRegulationsTable').DataTable({
        pageLength: 10,
        order: [[0, 'desc']],
        language: { search: "", searchPlaceholder: "Search exceptions..." }
      });
      
      $('#historyRegulationsTable').DataTable({
        pageLength: 10,
        order: [[6, 'desc']],
        language: { search: "", searchPlaceholder: "Search history..." }
      });
      
      // Initialize Select2
      $('.form-select').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#addEmployeeRegulationModal')
      });
      
      // Cancel regulation
      $('.cancel-regulation').click(function() {
        $('#cancelId').val($(this).data('id'));
        $('#cancelName').text($(this).data('name'));
        $('#cancelRegulationModal').modal('show');
      });
      
      // Reset Select2 when modal is closed
      $('#addEmployeeRegulationModal').on('hidden.bs.modal', function() {
        $(this).find('form')[0].reset();
        $(this).find('select').val(null).trigger('change');
      });
    });
  </script>
</body>
</html>