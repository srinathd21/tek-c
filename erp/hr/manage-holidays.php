<?php
// manage-holidays.php
// ✅ Holiday Management Page with TEK-C UI style

session_start();
require_once 'includes/db-config.php';

// OPTIONAL auth
// if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

$success = '';
$error = '';
$holidays = [];

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// Helpers
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='Not Set') {
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  
  // Add Holiday
  if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $holiday_name = trim($_POST['holiday_name'] ?? '');
    $holiday_date = trim($_POST['holiday_date'] ?? '');
    $holiday_type = trim($_POST['holiday_type'] ?? 'Public');
    $description = trim($_POST['description'] ?? '');

    if (empty($holiday_name) || empty($holiday_date)) {
      $error = "Holiday name and date are required.";
    } else {
      // Check if date already exists
      $check = mysqli_query($conn, "SELECT id FROM holidays WHERE holiday_date = '$holiday_date'");
      if (mysqli_num_rows($check) > 0) {
        $error = "A holiday already exists on this date.";
      } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO holidays (holiday_name, holiday_date, holiday_type, description) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "ssss", $holiday_name, $holiday_date, $holiday_type, $description);
        
        if (mysqli_stmt_execute($stmt)) {
          $success = "Holiday added successfully!";
        } else {
          $error = "Error adding holiday: " . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
      }
    }
  }
  
  // Edit Holiday
  elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = (int)$_POST['holiday_id'];
    $holiday_name = trim($_POST['holiday_name'] ?? '');
    $holiday_date = trim($_POST['holiday_date'] ?? '');
    $holiday_type = trim($_POST['holiday_type'] ?? 'Public');
    $description = trim($_POST['description'] ?? '');

    if (empty($holiday_name) || empty($holiday_date)) {
      $error = "Holiday name and date are required.";
    } else {
      // Check if date exists for other records
      $check = mysqli_query($conn, "SELECT id FROM holidays WHERE holiday_date = '$holiday_date' AND id != $id");
      if (mysqli_num_rows($check) > 0) {
        $error = "Another holiday already exists on this date.";
      } else {
        $stmt = mysqli_prepare($conn, "UPDATE holidays SET holiday_name=?, holiday_date=?, holiday_type=?, description=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, "ssssi", $holiday_name, $holiday_date, $holiday_type, $description, $id);
        
        if (mysqli_stmt_execute($stmt)) {
          $success = "Holiday updated successfully!";
        } else {
          $error = "Error updating holiday: " . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
      }
    }
  }
  
  // Delete Holiday
  elseif (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $stmt = mysqli_prepare($conn, "DELETE FROM holidays WHERE id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $id);
    
    if (mysqli_stmt_execute($stmt)) {
      $success = "Holiday deleted successfully!";
    } else {
      $error = "Error deleting holiday: " . mysqli_stmt_error($stmt);
    }
    mysqli_stmt_close($stmt);
  }
}

// Fetch current year for default filter
$current_year = date('Y');
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : $current_year;

// Fetch holidays
$query = "SELECT * FROM holidays WHERE YEAR(holiday_date) = $filter_year ORDER BY holiday_date ASC";
$res = mysqli_query($conn, $query);
if ($res) {
  $holidays = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_free_result($res);
} else {
  $error = "Error fetching holidays: " . mysqli_error($conn);
}

// Get available years for filter
$years = [];
$year_res = mysqli_query($conn, "SELECT DISTINCT YEAR(holiday_date) as year FROM holidays ORDER BY year DESC");
if ($year_res) {
  while ($row = mysqli_fetch_assoc($year_res)) {
    $years[] = $row['year'];
  }
  mysqli_free_result($year_res);
}
// Always include current year even if no holidays
if (!in_array($current_year, $years)) {
  $years[] = $current_year;
}
sort($years);

// Stats by type for current year
$stats = ['Public' => 0, 'Company' => 0, 'Optional' => 0];
foreach ($holidays as $h) {
  $type = $h['holiday_type'] ?? 'Public';
  $stats[$type] = ($stats[$type] ?? 0) + 1;
}
$total_holidays = count($holidays);

// Upcoming holidays (next 30 days)
$upcoming = [];
$upcoming_res = mysqli_query($conn, "SELECT * FROM holidays WHERE holiday_date >= CURDATE() AND holiday_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY holiday_date ASC LIMIT 5");
if ($upcoming_res) {
  $upcoming = mysqli_fetch_all($upcoming_res, MYSQLI_ASSOC);
  mysqli_free_result($upcoming_res);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Holidays - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

  <!-- Datepicker -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.10.0/dist/css/bootstrap-datepicker.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll { flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel { 
      background: var(--surface); 
      border:1px solid var(--border); 
      border-radius: var(--radius); 
      box-shadow: var(--shadow); 
      padding:16px 16px 12px; 
      height:100%; 
    }
    .panel-header { 
      display:flex; 
      align-items:center; 
      justify-content:space-between; 
      margin-bottom:10px; 
    }
    .panel-title { 
      font-weight:900; 
      font-size:18px; 
      color:#1f2937; 
      margin:0; 
    }
    .panel-menu { 
      width:36px; 
      height:36px; 
      border-radius:12px; 
      border:1px solid var(--border); 
      background:#fff; 
      display:grid; 
      place-items:center; 
      color:#6b7280; 
    }

    .stat-card { 
      background: var(--surface); 
      border:1px solid var(--border); 
      border-radius: var(--radius); 
      box-shadow: var(--shadow);
      padding:14px 16px; 
      height:90px; 
      display:flex; 
      align-items:center; 
      gap:14px; 
    }
    .stat-ic { 
      width:46px; 
      height:46px; 
      border-radius:14px; 
      display:grid; 
      place-items:center; 
      color:#fff; 
      font-size:20px; 
      flex:0 0 auto; 
    }
    .stat-ic.blue { background: var(--blue); }
    .stat-ic.green { background: #10b981; }
    .stat-ic.orange { background: #f59e0b; }
    .stat-ic.purple { background: #8b5cf6; }
    .stat-label { color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value { font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .upcoming-card {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border-radius: var(--radius);
      padding: 16px;
      color: white;
      height: 100%;
    }
    .upcoming-item {
      background: rgba(255,255,255,0.1);
      border-radius: 10px;
      padding: 10px 12px;
      margin-bottom: 8px;
      backdrop-filter: blur(5px);
    }
    .upcoming-item small { opacity: 0.9; }

    .type-badge {
      padding: 3px 8px; 
      border-radius: 20px;
      font-size: 10px; 
      font-weight: 900;
      display:inline-flex; 
      align-items:center; 
      gap:6px;
    }
    .type-public { background: rgba(45,156,219,.12); color: var(--blue); border:1px solid rgba(45,156,219,.22); }
    .type-company { background: rgba(16,185,129,.12); color: #10b981; border:1px solid rgba(16,185,129,.22); }
    .type-optional { background: rgba(245,158,11,.12); color: #f59e0b; border:1px solid rgba(245,158,11,.22); }

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
      text-decoration:none;
      white-space: nowrap;
    }
    .btn-add:hover { background:#2a8bc9; color:#fff; }

    .btn-outline-year {
      background: white;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 8px 16px;
      font-weight: 700;
      font-size: 13px;
      color: #4b5563;
    }
    .btn-outline-year.active {
      background: var(--blue);
      color: white;
      border-color: var(--blue);
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

    .btn-delete {
      background: transparent;
      border: 1px solid rgba(235,87,87,.25);
      border-radius: 8px;
      padding: 5px 8px;
      color: var(--red);
      font-size: 12px;
    }
    .btn-delete:hover { background: rgba(235,87,87,.10); color:#d32f2f; }

    .table td {
      vertical-align: middle;
      padding: 12px 10px !important;
    }

    .holiday-name {
      font-weight: 800;
      color: #1f2937;
      font-size: 14px;
    }

    .holiday-date {
      font-weight: 650;
      color: #4b5563;
      font-size: 13px;
    }

    .datepicker { z-index: 9999 !important; }
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
            <h1 class="h3 fw-bold text-dark mb-1">Holiday Calendar</h1>
            <p class="text-muted mb-0">Manage company holidays and observances</p>
          </div>
          <div class="d-flex gap-2">
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addHolidayModal">
              <i class="bi bi-calendar-plus"></i> Add Holiday
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

        <!-- Year Filter -->
        <div class="mb-4">
          <div class="d-flex gap-2 flex-wrap">
            <?php foreach ($years as $year): ?>
              <a href="?year=<?php echo $year; ?>" 
                 class="btn-outline-year <?php echo $filter_year == $year ? 'active' : ''; ?>">
                <?php echo $year; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Stats & Upcoming -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-md-6 col-lg-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-calendar-week"></i></div>
              <div>
                <div class="stat-label">Total Holidays (<?php echo $filter_year; ?>)</div>
                <div class="stat-value"><?php echo $total_holidays; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-lg-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-building"></i></div>
              <div>
                <div class="stat-label">Public Holidays</div>
                <div class="stat-value"><?php echo $stats['Public'] ?? 0; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-lg-3">
            <div class="stat-card">
              <div class="stat-ic orange"><i class="bi bi-briefcase"></i></div>
              <div>
                <div class="stat-label">Company Holidays</div>
                <div class="stat-value"><?php echo $stats['Company'] ?? 0; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-lg-3">
            <div class="stat-card">
              <div class="stat-ic purple"><i class="bi bi-calendar-check"></i></div>
              <div>
                <div class="stat-label">Optional Holidays</div>
                <div class="stat-value"><?php echo $stats['Optional'] ?? 0; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Upcoming Holidays -->
        <?php if (!empty($upcoming)): ?>
        <div class="row mb-4">
          <div class="col-12">
            <div class="upcoming-card">
              <div class="d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-calendar-event fs-4"></i>
                <h5 class="mb-0 fw-bold">Upcoming Holidays (Next 30 Days)</h5>
              </div>
              <div class="row g-2">
                <?php foreach ($upcoming as $up): ?>
                  <div class="col-12 col-md-6 col-lg-<?php echo count($upcoming) > 2 ? '4' : '6'; ?>">
                    <div class="upcoming-item">
                      <div class="fw-bold"><?php echo e($up['holiday_name']); ?></div>
                      <small>
                        <i class="bi bi-calendar3 me-1"></i>
                        <?php echo date('d M Y', strtotime($up['holiday_date'])); ?>
                        <span class="ms-2">•</span>
                        <span class="ms-2"><?php echo e($up['holiday_type']); ?></span>
                      </small>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Holidays Table -->
        <div class="panel">
          <div class="panel-header">
            <h3 class="panel-title">Holidays - <?php echo $filter_year; ?></h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table id="holidaysTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Day</th>
                  <th>Holiday Name</th>
                  <th>Type</th>
                  <th>Description</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($holidays as $holiday): ?>
                  <?php
                    $date_ts = strtotime($holiday['holiday_date']);
                    $day_name = date('l', $date_ts);
                    $type_class = 'type-' . strtolower($holiday['holiday_type'] ?? 'public');
                  ?>
                  <tr>
                    <td>
                      <div class="holiday-date">
                        <i class="bi bi-calendar3 me-1"></i>
                        <?php echo date('d M Y', $date_ts); ?>
                      </div>
                    </td>
                    <td>
                      <span class="badge bg-light text-dark"><?php echo $day_name; ?></span>
                    </td>
                    <td>
                      <span class="holiday-name"><?php echo e($holiday['holiday_name']); ?></span>
                    </td>
                    <td>
                      <span class="type-badge <?php echo $type_class; ?>">
                        <i class="bi bi-circle-fill" style="font-size:8px;"></i>
                        <?php echo e($holiday['holiday_type'] ?? 'Public'); ?>
                      </span>
                    </td>
                    <td>
                      <?php if (!empty($holiday['description'])): ?>
                        <span class="text-muted small"><?php echo e($holiday['description']); ?></span>
                      <?php else: ?>
                        <span class="text-muted small">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <button class="btn-action" onclick="editHoliday(<?php echo htmlspecialchars(json_encode($holiday)); ?>)" 
                              data-bs-toggle="modal" data-bs-target="#editHolidayModal" title="Edit">
                        <i class="bi bi-pencil"></i>
                      </button>
                      
                      <form method="POST" style="display:inline;" 
                            onsubmit="return confirm('Are you sure you want to delete this holiday?');">
                        <input type="hidden" name="delete_id" value="<?php echo (int)$holiday['id']; ?>">
                        <button type="submit" class="btn-delete" title="Delete">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <?php if (empty($holidays)): ?>
                  
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

<!-- Add Holiday Modal -->
<div class="modal fade" id="addHolidayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Add New Holiday</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Holiday Name *</label>
              <input type="text" class="form-control" name="holiday_name" required 
                     placeholder="e.g., Diwali, Christmas, etc.">
            </div>
            <div class="col-12">
              <label class="form-label">Date *</label>
              <input type="text" class="form-control datepicker" name="holiday_date" 
                     required autocomplete="off" placeholder="Select date">
            </div>
            <div class="col-12">
              <label class="form-label">Holiday Type</label>
              <select class="form-control" name="holiday_type">
                <option value="Public">Public Holiday</option>
                <option value="Company">Company Holiday</option>
                <option value="Optional">Optional Holiday</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Description (Optional)</label>
              <textarea class="form-control" name="description" rows="2" 
                        placeholder="Additional notes about the holiday"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-add">Add Holiday</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Holiday Modal -->
<div class="modal fade" id="editHolidayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Edit Holiday</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="holiday_id" id="edit_holiday_id">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Holiday Name *</label>
              <input type="text" class="form-control" name="holiday_name" id="edit_holiday_name" required>
            </div>
            <div class="col-12">
              <label class="form-label">Date *</label>
              <input type="text" class="form-control datepicker" name="holiday_date" 
                     id="edit_holiday_date" required autocomplete="off">
            </div>
            <div class="col-12">
              <label class="form-label">Holiday Type</label>
              <select class="form-control" name="holiday_type" id="edit_holiday_type">
                <option value="Public">Public Holiday</option>
                <option value="Company">Company Holiday</option>
                <option value="Optional">Optional Holiday</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-add">Update Holiday</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.10.0/dist/js/bootstrap-datepicker.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
  $(function () {
    // Initialize Datepicker
    $('.datepicker').datepicker({
      format: 'yyyy-mm-dd',
      autoclose: true,
      todayHighlight: true,
      startDate: '2020-01-01',
      endDate: '2030-12-31'
    });

    // Initialize DataTable
    $('#holidaysTable').DataTable({
      responsive: true,
      autoWidth: false,
      pageLength: 10,
      lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
      order: [[0, 'asc']],
      columnDefs: [
        { targets: [5], orderable: false, searchable: false }
      ],
      language: {
        zeroRecords: "No holidays found",
        info: "Showing _START_ to _END_ of _TOTAL_ holidays",
        infoEmpty: "No holidays to show",
        lengthMenu: "Show _MENU_",
        search: "Search:"
      }
    });
  });

  // Edit holiday function
  function editHoliday(holiday) {
    $('#edit_holiday_id').val(holiday.id);
    $('#edit_holiday_name').val(holiday.holiday_name);
    $('#edit_holiday_date').val(holiday.holiday_date);
    $('#edit_holiday_type').val(holiday.holiday_type);
    $('#edit_description').val(holiday.description || '');
    
    // Refresh datepicker with new date
    $('#edit_holiday_date').datepicker('update', holiday.holiday_date);
  }
</script>

</body>
</html>