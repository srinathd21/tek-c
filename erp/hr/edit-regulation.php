<?php
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

$current_employee_id = $_SESSION['employee_id'] ?? 1;
$current_employee_name = $_SESSION['employee_name'] ?? 'Admin';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// Check admin permissions
$emp_stmt = mysqli_prepare($conn, "SELECT designation FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

$is_admin = in_array($employee['designation'] ?? '', ['Director', 'Manager', 'HR']);
if (!$is_admin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: attendance-regulations.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch regulation data
$reg_data = [];
if ($id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM attendance_regulations WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $reg_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$reg_data) {
        $_SESSION['flash_error'] = "Regulation not found.";
        header("Location: attendance-regulations.php");
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $regulation_name = mysqli_real_escape_string($conn, $_POST['regulation_name']);
    $applicable_to = mysqli_real_escape_string($conn, $_POST['applicable_to']);
    $work_start_time = $_POST['work_start_time'];
    $work_end_time = $_POST['work_end_time'];
    $grace_period_minutes = (int)$_POST['grace_period_minutes'];
    $half_day_cutoff_time = !empty($_POST['half_day_cutoff_time']) ? $_POST['half_day_cutoff_time'] : null;
    $late_cutoff_time = !empty($_POST['late_cutoff_time']) ? $_POST['late_cutoff_time'] : null;
    $overtime_allowed = isset($_POST['overtime_allowed']) ? 1 : 0;
    $min_work_hours_full_day = (float)$_POST['min_work_hours_full_day'];
    $min_work_hours_half_day = (float)$_POST['min_work_hours_half_day'];
    $allow_office_punch = isset($_POST['allow_office_punch']) ? 1 : 0;
    $allow_site_punch = isset($_POST['allow_site_punch']) ? 1 : 0;
    $requires_manager_approval_for_vacation = isset($_POST['requires_manager_approval_for_vacation']) ? 1 : 0;
    $effective_from = $_POST['effective_from'];
    $effective_to = !empty($_POST['effective_to']) ? $_POST['effective_to'] : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validate
    $errors = [];
    if (empty($regulation_name)) $errors[] = "Regulation name is required";
    if (empty($work_start_time)) $errors[] = "Work start time is required";
    if (empty($work_end_time)) $errors[] = "Work end time is required";
    if (empty($effective_from)) $errors[] = "Effective from date is required";
    
    if (empty($errors)) {
        $old_data = json_encode($reg_data);
        $new_data = json_encode($_POST);
        
        $update_query = "UPDATE attendance_regulations SET 
            regulation_name = ?, applicable_to = ?, work_start_time = ?, work_end_time = ?, 
            grace_period_minutes = ?, half_day_cutoff_time = ?, late_cutoff_time = ?, 
            overtime_allowed = ?, min_work_hours_full_day = ?, min_work_hours_half_day = ?, 
            allow_office_punch = ?, allow_site_punch = ?, requires_manager_approval_for_vacation = ?, 
            effective_from = ?, effective_to = ?, is_active = ? 
            WHERE id = ?";
        
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "ssssiisiddiiissii", 
            $regulation_name, $applicable_to, $work_start_time, $work_end_time, $grace_period_minutes,
            $half_day_cutoff_time, $late_cutoff_time, $overtime_allowed, $min_work_hours_full_day,
            $min_work_hours_half_day, $allow_office_punch, $allow_site_punch,
            $requires_manager_approval_for_vacation, $effective_from, $effective_to, $is_active,
            $id
        );
        
        if (mysqli_stmt_execute($stmt)) {
            // FIXED: Using logActivity with correct signature (capital A, not underscore)
            logActivity($conn, 'UPDATE', 'attendance_regulations', 
                'Updated attendance regulation: ' . $regulation_name, 
                $id, $regulation_name, $old_data, $new_data);
            
            $_SESSION['flash_success'] = "Regulation updated successfully!";
            header("Location: attendance-regulations.php");
            exit();
        } else {
            $errors[] = "Database error: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
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
  <title>Edit Regulation - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:24px; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
    .panel-title{ font-weight:900; font-size:20px; color:#1f2937; margin:0; }
    
    .form-label{ font-weight:700; font-size:13px; color:#4b5563; margin-bottom:4px; }
    .form-control, .form-select{
      border:1px solid var(--border);
      border-radius:10px;
      padding:10px 12px;
      font-weight:500;
    }
    .form-control:focus, .form-select:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45,156,219,0.1);
    }
    
    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }
    
    .btn-save{
      background: var(--blue);
      color: white;
      border: none;
      padding: 12px 24px;
      border-radius: 30px;
      font-weight: 700;
    }
    .btn-cancel{
      background: transparent;
      border: 1px solid var(--border);
      padding: 12px 24px;
      border-radius: 30px;
      font-weight: 700;
      color: #4b5563;
    }
    
    .section-divider{
      border-top: 2px solid var(--border);
      margin: 24px 0;
      position: relative;
    }
    .section-divider span{
      position: absolute;
      top: -12px;
      left: 20px;
      background: white;
      padding: 0 10px;
      font-weight: 800;
      font-size: 14px;
      color: #4b5563;
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
            <h1 class="h3 fw-bold text-dark mb-1">Edit Attendance Regulation</h1>
            <p class="text-muted mb-0">Modify attendance policy rules</p>
          </div>
          <a href="attendance-regulations.php" class="btn-action">
            <i class="bi bi-arrow-left"></i> Back to Regulations
          </a>
        </div>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <ul class="mb-0">
              <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="POST" class="panel">
          <div class="panel-header">
            <h2 class="panel-title">Regulation Details</h2>
          </div>

          <!-- Basic Info -->
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">Regulation Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="regulation_name" required 
                     value="<?= htmlspecialchars($_POST['regulation_name'] ?? $reg_data['regulation_name'] ?? '') ?>"
                     placeholder="e.g., Office Staff Policy, Site Engineers Rules">
            </div>
            <div class="col-md-6">
              <label class="form-label">Applicable To <span class="text-danger">*</span></label>
              <select class="form-select" name="applicable_to" required>
                <option value="">Select...</option>
                <option value="All" <?= (($_POST['applicable_to'] ?? $reg_data['applicable_to'] ?? '') == 'All') ? 'selected' : '' ?>>All Employees</option>
                <option value="Site Employees" <?= (($_POST['applicable_to'] ?? $reg_data['applicable_to'] ?? '') == 'Site Employees') ? 'selected' : '' ?>>Site Employees</option>
                <option value="Office Employees" <?= (($_POST['applicable_to'] ?? $reg_data['applicable_to'] ?? '') == 'Office Employees') ? 'selected' : '' ?>>Office Employees</option>
                <option value="Managers" <?= (($_POST['applicable_to'] ?? $reg_data['applicable_to'] ?? '') == 'Managers') ? 'selected' : '' ?>>Managers</option>
                <option value="Team Leads" <?= (($_POST['applicable_to'] ?? $reg_data['applicable_to'] ?? '') == 'Team Leads') ? 'selected' : '' ?>>Team Leads</option>
              </select>
            </div>
          </div>

          <!-- Working Hours -->
          <div class="section-divider"><span>Working Hours</span></div>
          
          <div class="row g-3 mb-4">
            <div class="col-md-3">
              <label class="form-label">Start Time <span class="text-danger">*</span></label>
              <input type="time" class="form-control" name="work_start_time" required 
                     value="<?= htmlspecialchars($_POST['work_start_time'] ?? $reg_data['work_start_time'] ?? '09:00') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">End Time <span class="text-danger">*</span></label>
              <input type="time" class="form-control" name="work_end_time" required 
                     value="<?= htmlspecialchars($_POST['work_end_time'] ?? $reg_data['work_end_time'] ?? '18:00') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Grace Period (minutes)</label>
              <input type="number" class="form-control" name="grace_period_minutes" min="0" max="120"
                     value="<?= htmlspecialchars($_POST['grace_period_minutes'] ?? $reg_data['grace_period_minutes'] ?? '15') ?>">
              <small class="text-muted">Minutes allowed to punch in late</small>
            </div>
            <div class="col-md-3">
              <label class="form-label">Overtime Allowed?</label>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="overtime_allowed" id="overtime_allowed" 
                       value="1" <?= (isset($_POST['overtime_allowed']) || ($reg_data['overtime_allowed'] ?? 0)) ? 'checked' : '' ?>>
                <label class="form-check-label" for="overtime_allowed">Yes, allow overtime</label>
              </div>
            </div>
          </div>

          <!-- Cutoff Times -->
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label">Half Day Cutoff Time</label>
              <input type="time" class="form-control" name="half_day_cutoff_time"
                     value="<?= htmlspecialchars($_POST['half_day_cutoff_time'] ?? $reg_data['half_day_cutoff_time'] ?? '') ?>">
              <small class="text-muted">Punch after this counts as half day</small>
            </div>
            <div class="col-md-4">
              <label class="form-label">Late Cutoff Time</label>
              <input type="time" class="form-control" name="late_cutoff_time"
                     value="<?= htmlspecialchars($_POST['late_cutoff_time'] ?? $reg_data['late_cutoff_time'] ?? '') ?>">
              <small class="text-muted">Punch after this counts as late</small>
            </div>
          </div>

          <!-- Minimum Hours -->
          <div class="section-divider"><span>Minimum Work Hours</span></div>
          
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label">Full Day Minimum (hours)</label>
              <input type="number" step="0.5" class="form-control" name="min_work_hours_full_day"
                     value="<?= htmlspecialchars($_POST['min_work_hours_full_day'] ?? $reg_data['min_work_hours_full_day'] ?? '8.00') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Half Day Minimum (hours)</label>
              <input type="number" step="0.5" class="form-control" name="min_work_hours_half_day"
                     value="<?= htmlspecialchars($_POST['min_work_hours_half_day'] ?? $reg_data['min_work_hours_half_day'] ?? '4.00') ?>">
            </div>
          </div>

          <!-- Punch Settings -->
          <div class="section-divider"><span>Punch Settings</span></div>
          
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="allow_office_punch" id="allow_office_punch" 
                       value="1" <?= (isset($_POST['allow_office_punch']) || ($reg_data['allow_office_punch'] ?? 0)) ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_office_punch">
                  Allow Office Punch
                </label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="allow_site_punch" id="allow_site_punch" 
                       value="1" <?= (isset($_POST['allow_site_punch']) || ($reg_data['allow_site_punch'] ?? 1)) ? 'checked' : '' ?>>
                <label class="form-check-label" for="allow_site_punch">
                  Allow Site Punch
                </label>
              </div>
            </div>
          </div>

          <!-- Vacation Settings -->
          <div class="section-divider"><span>Vacation Settings</span></div>
          
          <div class="row g-3 mb-4">
            <div class="col-md-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="requires_manager_approval_for_vacation" 
                       id="requires_manager_approval" value="1" 
                       <?= (isset($_POST['requires_manager_approval_for_vacation']) || ($reg_data['requires_manager_approval_for_vacation'] ?? 1)) ? 'checked' : '' ?>>
                <label class="form-check-label" for="requires_manager_approval">
                  Require Manager Approval for Vacation Requests
                </label>
              </div>
            </div>
          </div>

          <!-- Validity Period -->
          <div class="section-divider"><span>Validity Period</span></div>
          
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label">Effective From <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="effective_from" required 
                     value="<?= htmlspecialchars($_POST['effective_from'] ?? $reg_data['effective_from'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Effective To (Optional)</label>
              <input type="date" class="form-control" name="effective_to"
                     value="<?= htmlspecialchars($_POST['effective_to'] ?? $reg_data['effective_to'] ?? '') ?>">
              <small class="text-muted">Leave blank for ongoing</small>
            </div>
            <div class="col-md-4">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                       value="1" <?= (isset($_POST['is_active']) || ($reg_data['is_active'] ?? 1)) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">
                  Active
                </label>
              </div>
            </div>
          </div>

          <!-- Submit Buttons -->
          <div class="d-flex gap-3 justify-content-end mt-4">
            <a href="attendance-regulations.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">
              <i class="bi bi-check-circle"></i> Update Regulation
            </button>
          </div>
        </form>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>
</body>
</html>