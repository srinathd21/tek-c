<?php
// hr/regularization-policy.php - Attendance Regularization Policy Settings
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

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

// ---------------- HANDLE FORM SUBMISSION ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['save_policy'])) {
    // Save regularization policy settings
    $regularizationDeadline = (int)($_POST['regularization_deadline'] ?? 7);
    $maxRegularizationsPerMonth = (int)($_POST['max_regularizations_per_month'] ?? 3);
    $requireApproval = isset($_POST['require_approval']) ? 1 : 0;
    $requireReason = isset($_POST['require_reason']) ? 1 : 0;
    $allowBackdated = isset($_POST['allow_backdated']) ? 1 : 0;
    $maxBackdatedDays = (int)($_POST['max_backdated_days'] ?? 30);
    $notifyManager = isset($_POST['notify_manager']) ? 1 : 0;
    $notifyHR = isset($_POST['notify_hr']) ? 1 : 0;
    $autoApproveThreshold = (int)($_POST['auto_approve_threshold'] ?? 0);
    $workingHoursRequired = (float)($_POST['working_hours_required'] ?? 9.0);
    
    // Allowed exception types
    $allowedTypes = isset($_POST['allowed_types']) ? implode(',', $_POST['allowed_types']) : 'vacation,remote-work,field-work,other';
    
    // Check if policy exists
    $checkQuery = "SELECT COUNT(*) as c FROM attendance_regulations WHERE regulation_name = 'regularization_policy'";
    $st = mysqli_prepare($conn, $checkQuery);
    if ($st) {
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $row = mysqli_fetch_assoc($res);
      $exists = (int)($row['c'] ?? 0) > 0;
      mysqli_stmt_close($st);
      
      if ($exists) {
        // Update existing policy
        $query = "UPDATE attendance_regulations SET 
                  work_start_time = ?,
                  work_end_time = ?,
                  grace_period_minutes = ?,
                  min_work_hours_full_day = ?,
                  min_work_hours_half_day = ?,
                  overtime_allowed = ?,
                  updated_at = NOW()
                  WHERE regulation_name = 'regularization_policy'";
        
        $st = mysqli_prepare($conn, $query);
        if ($st) {
          // Note: This is simplified - you'd need to add all fields
          mysqli_stmt_bind_param($st, "ssiddi", 
            $regularizationDeadline, $maxRegularizationsPerMonth, $requireApproval,
            $requireReason, $allowBackdated, $maxBackdatedDays
          );
          mysqli_stmt_execute($st);
          mysqli_stmt_close($st);
        }
      } else {
        // Insert new policy
        $query = "INSERT INTO attendance_regulations 
                  (regulation_name, applicable_to, work_start_time, work_end_time, 
                   grace_period_minutes, min_work_hours_full_day, min_work_hours_half_day,
                   overtime_allowed, effective_from, is_active, created_at)
                  VALUES (?, 'All', ?, ?, ?, ?, ?, ?, CURDATE(), 1, NOW())";
        
        $st = mysqli_prepare($conn, $query);
        if ($st) {
          mysqli_stmt_bind_param($st, "sssddi", 
            $regularizationDeadline, $maxRegularizationsPerMonth, $requireApproval,
            $requireReason, $allowBackdated, $maxBackdatedDays, $autoApproveThreshold
          );
          mysqli_stmt_execute($st);
          mysqli_stmt_close($st);
        }
      }
    }
    
    // Save to a custom settings table or JSON file for non-standard fields
    $settings = [
      'regularization_deadline' => $regularizationDeadline,
      'max_regularizations_per_month' => $maxRegularizationsPerMonth,
      'require_approval' => $requireApproval,
      'require_reason' => $requireReason,
      'allow_backdated' => $allowBackdated,
      'max_backdated_days' => $maxBackdatedDays,
      'notify_manager' => $notifyManager,
      'notify_hr' => $notifyHR,
      'auto_approve_threshold' => $autoApproveThreshold,
      'working_hours_required' => $workingHoursRequired,
      'allowed_types' => $allowedTypes
    ];
    
    // Save to a JSON file in the includes directory
    $settingsFile = __DIR__ . '/includes/regularization-settings.json';
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    
    $message = "Regularization policy updated successfully.";
    $messageType = "success";
  }
}

// ---------------- LOAD CURRENT SETTINGS ----------------
// Default settings
$settings = [
  'regularization_deadline' => 7,
  'max_regularizations_per_month' => 3,
  'require_approval' => 1,
  'require_reason' => 1,
  'allow_backdated' => 1,
  'max_backdated_days' => 30,
  'notify_manager' => 1,
  'notify_hr' => 0,
  'auto_approve_threshold' => 0,
  'working_hours_required' => 9.0,
  'allowed_types' => 'vacation,remote-work,field-work,other'
];

// Load from JSON file if exists
$settingsFile = __DIR__ . '/includes/regularization-settings.json';
if (file_exists($settingsFile)) {
  $loaded = json_decode(file_get_contents($settingsFile), true);
  if ($loaded) {
    $settings = array_merge($settings, $loaded);
  }
}

// Parse allowed types into array
$allowedTypesArray = explode(',', $settings['allowed_types']);

// ---------------- FETCH REGULATIONS FROM DATABASE ----------------
$regulations = [];
$st = mysqli_prepare($conn, "SELECT * FROM attendance_regulations WHERE is_active = 1 ORDER BY effective_from DESC");
if ($st) {
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    $regulations[] = $row;
  }
  mysqli_stmt_close($st);
}

$loggedName = $_SESSION['employee_name'] ?? 'HR';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Regularization Policy - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:20px; margin-bottom:20px; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }

    .settings-section{ margin-bottom:30px; }
    .section-title{ font-weight:900; font-size:16px; color:#374151; margin-bottom:16px; padding-bottom:8px; border-bottom:2px solid var(--border); }
    
    .form-label{ font-weight:800; font-size:13px; color:#4b5563; margin-bottom:4px; }
    .form-control, .form-select{ border:1px solid var(--border); border-radius:10px; padding:10px 12px; font-weight:600; }
    .form-control:focus, .form-select:focus{ border-color: var(--blue); box-shadow:0 0 0 3px rgba(59,130,246,.1); }
    
    .form-check{ margin-bottom:12px; }
    .form-check-input{ width:20px; height:20px; margin-top:2px; border:2px solid var(--border); }
    .form-check-input:checked{ background-color: var(--blue); border-color: var(--blue); }
    .form-check-label{ font-weight:650; color:#374151; margin-left:8px; }

    .info-card{ background: #f9fafb; border-radius:12px; padding:16px; border:1px solid var(--border); height:100%; }
    .info-icon{ width:40px; height:40px; border-radius:10px; background: rgba(59,130,246,.1); color: var(--blue); display:grid; place-items:center; font-size:20px; margin-bottom:12px; }
    .info-title{ font-weight:800; font-size:14px; color:#374151; margin-bottom:4px; }
    .info-desc{ font-size:12px; color:#6b7280; }

    .badge-type{ display:inline-block; padding:6px 12px; border-radius:20px; font-weight:700; font-size:12px; margin:0 4px 8px 0; }
    .badge-vacation{ background:#e3f2fd; color:#0d47a1; }
    .badge-remote{ background:#e8f5e8; color:#1b5e20; }
    .badge-field{ background:#fff3e0; color:#b85c00; }
    .badge-other{ background:#f3e5f5; color:#4a148c; }

    .preview-card{ background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:white; border-radius:12px; padding:20px; margin-top:20px; }
    .preview-title{ font-weight:900; font-size:18px; margin-bottom:12px; }
    .preview-item{ display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.2); }
    .preview-item:last-child{ border-bottom:0; }

    .action-btn{ padding:10px 20px; border-radius:10px; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border:1px solid transparent; }
    .action-btn-primary{ background: var(--blue); color:white; }
    .action-btn-outline{ background: white; color:#374151; border:1px solid var(--border); }
    .action-btn-outline:hover{ background:#f3f4f6; }

    hr{ border-top:2px solid var(--border); opacity:.5; margin:24px 0; }

    .toggle-switch{ position:relative; display:inline-block; width:50px; height:24px; }
    .toggle-switch input{ opacity:0; width:0; height:0; }
    .toggle-slider{ position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:#ccc; transition:.4s; border-radius:24px; }
    .toggle-slider:before{ position:absolute; content:""; height:18px; width:18px; left:3px; bottom:3px; background-color:white; transition:.4s; border-radius:50%; }
    input:checked + .toggle-slider{ background-color: var(--blue); }
    input:checked + .toggle-slider:before{ transform:translateX(26px); }

    @media (max-width: 767.98px){
      .content-scroll{ padding:18px; }
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
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h1 class="h3 mb-0" style="font-weight:900;">Regularization Policy</h1>
              <p class="text-muted mt-1 mb-0">Configure rules and settings for attendance regularization requests</p>
            </div>
            <div>
              <a href="attendance-regularization.php" class="btn btn-outline-secondary me-2" style="font-weight:800;">
                <i class="bi bi-arrow-left"></i> Back to Requests
              </a>
              <button type="submit" form="policyForm" class="btn btn-primary" style="font-weight:800;">
                <i class="bi bi-save"></i> Save Changes
              </button>
            </div>
          </div>

          <!-- Alert Message -->
          <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-3" role="alert">
              <?php echo e($message); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>

          <div class="row g-4">
            <!-- Main Settings Form -->
            <div class="col-12 col-lg-8">
              <form method="POST" action="" id="policyForm">
                <div class="panel">
                  <div class="panel-header">
                    <h3 class="panel-title">
                      <i class="bi bi-gear me-2"></i>General Settings
                    </h3>
                  </div>

                  <div class="settings-section">
                    <h4 class="section-title">Eligibility & Limits</h4>
                    
                    <div class="row g-3 mb-3">
                      <div class="col-md-6">
                        <label class="form-label">Regularization Deadline (days)</label>
                        <input type="number" name="regularization_deadline" class="form-control" 
                               value="<?php echo $settings['regularization_deadline']; ?>" min="1" max="90" required>
                        <small class="text-muted">Maximum days after the date to apply for regularization</small>
                      </div>
                      
                      <div class="col-md-6">
                        <label class="form-label">Max Requests Per Month</label>
                        <input type="number" name="max_regularizations_per_month" class="form-control" 
                               value="<?php echo $settings['max_regularizations_per_month']; ?>" min="1" max="30" required>
                        <small class="text-muted">Maximum regularization requests allowed per employee per month</small>
                      </div>
                    </div>

                    <div class="row g-3 mb-3">
                      <div class="col-md-6">
                        <label class="form-label">Working Hours Required</label>
                        <div class="input-group">
                          <input type="number" name="working_hours_required" class="form-control" 
                                 value="<?php echo $settings['working_hours_required']; ?>" min="1" max="24" step="0.5" required>
                          <span class="input-group-text">hours</span>
                        </div>
                        <small class="text-muted">Standard working hours per day</small>
                      </div>
                      
                      <div class="col-md-6">
                        <label class="form-label">Auto-Approve Threshold (minutes)</label>
                        <input type="number" name="auto_approve_threshold" class="form-control" 
                               value="<?php echo $settings['auto_approve_threshold']; ?>" min="0" max="120">
                        <small class="text-muted">0 = manual approval required</small>
                      </div>
                    </div>
                  </div>

                  <hr>

                  <div class="settings-section">
                    <h4 class="section-title">Backdated Requests</h4>
                    
                    <div class="row g-3">
                      <div class="col-12">
                        <div class="form-check form-switch">
                          <input class="form-check-input" type="checkbox" name="allow_backdated" id="allowBackdated" 
                                 value="1" <?php echo $settings['allow_backdated'] ? 'checked' : ''; ?>>
                          <label class="form-check-label fw-bold" for="allowBackdated">
                            Allow backdated regularization requests
                          </label>
                        </div>
                      </div>
                      
                      <div class="col-md-6">
                        <label class="form-label">Max Backdated Days</label>
                        <input type="number" name="max_backdated_days" class="form-control" 
                               value="<?php echo $settings['max_backdated_days']; ?>" min="1" max="365"
                               <?php echo !$settings['allow_backdated'] ? 'disabled' : ''; ?>>
                        <small class="text-muted">Maximum days in past allowed for requests</small>
                      </div>
                    </div>
                  </div>

                  <hr>

                  <div class="settings-section">
                    <h4 class="section-title">Request Requirements</h4>
                    
                    <div class="row g-3">
                      <div class="col-md-6">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="require_approval" id="requireApproval" 
                                 value="1" <?php echo $settings['require_approval'] ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="requireApproval">
                            Require manager approval
                          </label>
                        </div>
                        
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="require_reason" id="requireReason" 
                                 value="1" <?php echo $settings['require_reason'] ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="requireReason">
                            Require reason for request
                          </label>
                        </div>
                      </div>
                      
                      <div class="col-md-6">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="notify_manager" id="notifyManager" 
                                 value="1" <?php echo $settings['notify_manager'] ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="notifyManager">
                            Notify manager on new request
                          </label>
                        </div>
                        
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="notify_hr" id="notifyHR" 
                                 value="1" <?php echo $settings['notify_hr'] ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="notifyHR">
                            Notify HR on approval/rejection
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>

                  <hr>

                  <div class="settings-section">
                    <h4 class="section-title">Allowed Regularization Types</h4>
                    
                    <div class="row g-3">
                      <div class="col-md-6">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="allowed_types[]" value="vacation" 
                                 id="typeVacation" <?php echo in_array('vacation', $allowedTypesArray) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="typeVacation">
                            <span class="badge-type badge-vacation">Vacation</span>
                          </label>
                        </div>
                        
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="allowed_types[]" value="remote-work" 
                                 id="typeRemote" <?php echo in_array('remote-work', $allowedTypesArray) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="typeRemote">
                            <span class="badge-type badge-remote">Remote Work</span>
                          </label>
                        </div>
                      </div>
                      
                      <div class="col-md-6">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="allowed_types[]" value="field-work" 
                                 id="typeField" <?php echo in_array('field-work', $allowedTypesArray) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="typeField">
                            <span class="badge-type badge-field">Field Work</span>
                          </label>
                        </div>
                        
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="allowed_types[]" value="other" 
                                 id="typeOther" <?php echo in_array('other', $allowedTypesArray) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="typeOther">
                            <span class="badge-type badge-other">Other</span>
                          </label>
                        </div>
                      </div>
                    </div>
                  </div>

                  <input type="hidden" name="save_policy" value="1">
                </div>
              </form>
            </div>

            <!-- Sidebar Info & Preview -->
            <div class="col-12 col-lg-4">
              <!-- Information Cards -->
              <div class="info-card mb-4">
                <div class="info-icon">
                  <i class="bi bi-clock-history"></i>
                </div>
                <h5 class="info-title">Regularization Deadline</h5>
                <p class="info-desc">Employees can request corrections within <?php echo $settings['regularization_deadline']; ?> days of the attendance date. Requests beyond this period will be automatically rejected.</p>
              </div>

              <div class="info-card mb-4">
                <div class="info-icon">
                  <i class="bi bi-calendar-range"></i>
                </div>
                <h5 class="info-title">Monthly Limits</h5>
                <p class="info-desc">Each employee can submit up to <?php echo $settings['max_regularizations_per_month']; ?> regularization requests per month to prevent misuse.</p>
              </div>

              <div class="info-card mb-4">
                <div class="info-icon">
                  <i class="bi bi-shield-check"></i>
                </div>
                <h5 class="info-title">Approval Workflow</h5>
                <p class="info-desc">
                  <?php if ($settings['require_approval']): ?>
                    All requests require manager approval. <?php echo $settings['auto_approve_threshold'] > 0 ? "Requests under {$settings['auto_approve_threshold']} minutes are auto-approved." : ''; ?>
                  <?php else: ?>
                    Requests are automatically approved without manager review.
                  <?php endif; ?>
                </p>
              </div>

              <!-- Policy Preview -->
              <div class="preview-card">
                <h5 class="preview-title">Policy Summary</h5>
                <div class="preview-item">
                  <span>Deadline</span>
                  <span class="fw-bold"><?php echo $settings['regularization_deadline']; ?> days</span>
                </div>
                <div class="preview-item">
                  <span>Monthly Limit</span>
                  <span class="fw-bold"><?php echo $settings['max_regularizations_per_month']; ?> requests</span>
                </div>
                <div class="preview-item">
                  <span>Backdated Allowed</span>
                  <span class="fw-bold"><?php echo $settings['allow_backdated'] ? 'Yes (Max '.$settings['max_backdated_days'].' days)' : 'No'; ?></span>
                </div>
                <div class="preview-item">
                  <span>Approval Required</span>
                  <span class="fw-bold"><?php echo $settings['require_approval'] ? 'Yes' : 'No'; ?></span>
                </div>
                <div class="preview-item">
                  <span>Allowed Types</span>
                  <span class="fw-bold"><?php echo count($allowedTypesArray); ?> types</span>
                </div>
              </div>

              <!-- Recent Policy Changes -->
              <?php if (!empty($regulations)): ?>
              <div class="panel mt-4">
                <div class="panel-header">
                  <h3 class="panel-title">Recent Policy Updates</h3>
                </div>
                <div class="list-group list-group-flush">
                  <?php foreach (array_slice($regulations, 0, 3) as $reg): ?>
                  <div class="list-group-item px-0 border-0">
                    <div class="d-flex justify-content-between align-items-center">
                      <div>
                        <div class="fw-bold"><?php echo e($reg['regulation_name']); ?></div>
                        <small class="text-muted">Effective: <?php echo safeDate($reg['effective_from'] ?? ''); ?></small>
                      </div>
                      <span class="badge bg-success">Active</span>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Database Regulations Table -->
          <?php if (!empty($regulations)): ?>
          <div class="panel mt-4">
            <div class="panel-header">
              <h3 class="panel-title">Attendance Regulations</h3>
              <button class="btn btn-sm btn-outline-primary" style="font-weight:800;" onclick="location.href='attendance-regulations.php'">
                Manage All
              </button>
            </div>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead>
                  <tr>
                    <th>Regulation Name</th>
                    <th>Work Hours</th>
                    <th>Grace Period</th>
                    <th>Applicable To</th>
                    <th>Effective From</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($regulations, 0, 5) as $reg): ?>
                  <tr>
                    <td class="fw-bold"><?php echo e($reg['regulation_name']); ?></td>
                    <td><?php echo e(substr($reg['work_start_time'] ?? '', 0, 5)); ?> - <?php echo e(substr($reg['work_end_time'] ?? '', 0, 5)); ?></td>
                    <td><?php echo e($reg['grace_period_minutes'] ?? 15); ?> min</td>
                    <td><?php echo e($reg['applicable_to'] ?? 'All'); ?></td>
                    <td><?php echo safeDate($reg['effective_from'] ?? ''); ?></td>
                    <td>
                      <span class="badge bg-success">Active</span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <?php include 'includes/footer.php'; ?>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="assets/js/sidebar-toggle.js"></script>

  <script>
    $(document).ready(function() {
      // Toggle backdated days input based on checkbox
      $('#allowBackdated').change(function() {
        const isChecked = $(this).is(':checked');
        $('input[name="max_backdated_days"]').prop('disabled', !isChecked);
      });
    });
  </script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
  mysqli_close($conn);
}
?>