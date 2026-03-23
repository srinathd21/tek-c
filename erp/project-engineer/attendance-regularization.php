<?php
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

// Get current user from session
$current_employee_id = (int)($_SESSION['employee_id'] ?? 0);
$current_employee_name = (string)($_SESSION['employee_name'] ?? '');

if ($current_employee_id <= 0) {
    header("Location: ../login.php");
    exit;
}

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// Get employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$employee) {
    die("Employee not found or inactive.");
}

// Get assigned sites/projects for the employee
$sites_query = "
    SELECT s.id, s.project_name, s.project_code, s.manager_employee_id, 
           e.full_name as manager_name
    FROM sites s
    LEFT JOIN employees e ON s.manager_employee_id = e.id
    WHERE s.id IN (
        SELECT site_id FROM site_project_engineers WHERE employee_id = ?
    )
    AND s.deleted_at IS NULL
    ORDER BY s.project_name
";

$sites_stmt = mysqli_prepare($conn, $sites_query);
mysqli_stmt_bind_param($sites_stmt, "i", $current_employee_id);
mysqli_stmt_execute($sites_stmt);
$sites_res = mysqli_stmt_get_result($sites_stmt);
$assigned_sites = mysqli_fetch_all($sites_res, MYSQLI_ASSOC);
mysqli_stmt_close($sites_stmt);

// Get current date and time
$current_date = date('Y-m-d');

// Get attendance records for the last 60 days for regularization
$attendance_query = "
    SELECT a.*, 
           s.project_name as site_name,
           o.location_name as office_name
    FROM attendance a
    LEFT JOIN sites s ON a.punch_in_site_id = s.id
    LEFT JOIN office_locations o ON a.punch_in_office_id = o.id
    WHERE a.employee_id = ?
      AND a.attendance_date >= DATE_SUB(?, INTERVAL 60 DAY)
    ORDER BY a.attendance_date DESC
";

$attendance_stmt = mysqli_prepare($conn, $attendance_query);
mysqli_stmt_bind_param($attendance_stmt, "is", $current_employee_id, $current_date);
mysqli_stmt_execute($attendance_stmt);
$attendance_res = mysqli_stmt_get_result($attendance_stmt);
$attendance_records = mysqli_fetch_all($attendance_res, MYSQLI_ASSOC);
mysqli_stmt_close($attendance_stmt);

// Get existing regularization requests
$reg_query = "
    SELECT * FROM attendance_regularization 
    WHERE employee_id = ? 
    ORDER BY created_at DESC 
    LIMIT 50
";
$reg_stmt = mysqli_prepare($conn, $reg_query);
mysqli_stmt_bind_param($reg_stmt, "i", $current_employee_id);
mysqli_stmt_execute($reg_stmt);
$reg_res = mysqli_stmt_get_result($reg_stmt);
$reg_requests = mysqli_fetch_all($reg_res, MYSQLI_ASSOC);
mysqli_stmt_close($reg_stmt);

// Get holidays for validation
$holiday_query = "SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date >= DATE_SUB(?, INTERVAL 60 DAY)";
$holiday_stmt = mysqli_prepare($conn, $holiday_query);
mysqli_stmt_bind_param($holiday_stmt, "s", $current_date);
mysqli_stmt_execute($holiday_stmt);
$holiday_res = mysqli_stmt_get_result($holiday_stmt);
$holidays = mysqli_fetch_all($holiday_res, MYSQLI_ASSOC);
mysqli_stmt_close($holiday_stmt);

$holiday_dates = [];
foreach ($holidays as $holiday) {
    $holiday_dates[$holiday['holiday_date']] = $holiday['holiday_name'];
}

// Process form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'submit_regularization') {
        $attendance_date = $_POST['attendance_date'] ?? '';
        $request_type = $_POST['request_type'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $selected_site_id = $_POST['selected_site_id'] ?? '';
        $current_punch_in = $_POST['current_punch_in'] ?? '';
        $current_punch_out = $_POST['current_punch_out'] ?? '';
        $requested_punch_in = $_POST['requested_punch_in'] ?? '';
        $requested_punch_out = $_POST['requested_punch_out'] ?? '';
        
        // Get manager details from selected site
        $manager_id = null;
        $manager_name = '';
        if (!empty($selected_site_id)) {
            $site_manager_stmt = mysqli_prepare($conn, "
                SELECT manager_employee_id, 
                       (SELECT full_name FROM employees WHERE id = manager_employee_id) as manager_name 
                FROM sites WHERE id = ?
            ");
            mysqli_stmt_bind_param($site_manager_stmt, "i", $selected_site_id);
            mysqli_stmt_execute($site_manager_stmt);
            $site_manager_res = mysqli_stmt_get_result($site_manager_stmt);
            $site_manager = mysqli_fetch_assoc($site_manager_res);
            if ($site_manager) {
                $manager_id = $site_manager['manager_employee_id'];
                $manager_name = $site_manager['manager_name'];
            }
            mysqli_stmt_close($site_manager_stmt);
        }
        
        // Validation
        $errors = [];
        
        if (empty($attendance_date)) {
            $errors[] = "Please select a date.";
        }
        
        if (empty($request_type)) {
            $errors[] = "Please select request type.";
        }
        
        if (empty($selected_site_id)) {
            $errors[] = "Please select a project/site.";
        }
        
        if (empty($reason)) {
            $errors[] = "Please provide a reason for regularization.";
        }
        
        // Check if date is a holiday
        if (isset($holiday_dates[$attendance_date])) {
            $errors[] = "Cannot regularize attendance for holiday: " . $holiday_dates[$attendance_date];
        }
        
        // Check if date is future
        if (strtotime($attendance_date) > strtotime($current_date)) {
            $errors[] = "Cannot regularize future dates.";
        }
        
        // Check if date is more than 30 days old
        if (strtotime($attendance_date) < strtotime('-30 days')) {
            $errors[] = "Regularization is only allowed for dates within the last 30 days.";
        }
        
        // Check if already has a pending request
        $check_query = "SELECT id FROM attendance_regularization WHERE employee_id = ? AND attendance_date = ? AND status = 'Pending'";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "is", $current_employee_id, $attendance_date);
        mysqli_stmt_execute($check_stmt);
        $check_res = mysqli_stmt_get_result($check_stmt);
        $existing = mysqli_fetch_assoc($check_res);
        mysqli_stmt_close($check_stmt);
        
        if ($existing) {
            $errors[] = "You already have a pending regularization request for this date.";
        }
        
        // Time validation
        if ($request_type === 'punch_in') {
            if (empty($requested_punch_in)) {
                $errors[] = "Please provide requested punch-in time.";
            }
        } elseif ($request_type === 'punch_out') {
            if (empty($requested_punch_out)) {
                $errors[] = "Please provide requested punch-out time.";
            }
        } elseif ($request_type === 'both') {
            if (empty($requested_punch_in) && empty($requested_punch_out)) {
                $errors[] = "Please provide at least one requested time.";
            }
        } elseif ($request_type === 'full_day') {
            if (empty($requested_punch_in) || empty($requested_punch_out)) {
                $errors[] = "Please provide both punch-in and punch-out times.";
            }
        }
        
        if (empty($errors)) {
            // Generate request number
            $request_no = 'REG-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Handle file upload
            $document_path = null;
            if (isset($_FILES['supporting_document']) && $_FILES['supporting_document']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/regularization/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file_ext = pathinfo($_FILES['supporting_document']['name'], PATHINFO_EXTENSION);
                $file_name = 'reg_' . $current_employee_id . '_' . $attendance_date . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['supporting_document']['tmp_name'], $upload_path)) {
                    $document_path = $upload_path;
                }
            }
            
            $insert_query = "
                INSERT INTO attendance_regularization (
                    request_no, employee_id, employee_name, attendance_date, request_type,
                    reason, current_punch_in, current_punch_out, requested_punch_in, 
                    requested_punch_out, supporting_document, manager_id, manager_name,
                    status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ";
            
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            
            // Handle null values
            $manager_id_value = $manager_id !== null ? $manager_id : null;
            $current_punch_in_value = !empty($current_punch_in) ? $current_punch_in : null;
            $current_punch_out_value = !empty($current_punch_out) ? $current_punch_out : null;
            $requested_punch_in_value = !empty($requested_punch_in) ? $requested_punch_in : null;
            $requested_punch_out_value = !empty($requested_punch_out) ? $requested_punch_out : null;
            $document_path_value = !empty($document_path) ? $document_path : null;
            $manager_name_value = !empty($manager_name) ? $manager_name : null;
            
            // Type specifiers: 13 parameters
            // s=string, i=integer
            mysqli_stmt_bind_param($insert_stmt, "sisssssssssis", 
                $request_no,                           // 1: string
                $current_employee_id,                  // 2: integer
                $current_employee_name,                // 3: string
                $attendance_date,                      // 4: string
                $request_type,                         // 5: string
                $reason,                               // 6: string
                $current_punch_in_value,               // 7: string (nullable)
                $current_punch_out_value,              // 8: string (nullable)
                $requested_punch_in_value,             // 9: string (nullable)
                $requested_punch_out_value,            // 10: string (nullable)
                $document_path_value,                  // 11: string (nullable)
                $manager_id_value,                     // 12: integer (nullable)
                $manager_name_value                    // 13: string (nullable)
            );
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $message = "Regularization request submitted successfully. Request ID: " . $request_no;
                $message_type = "success";
                
                // Log activity
                logActivity($conn, 'CREATE', 'attendance_regularization', 
                    "Regularization request submitted for $attendance_date - Request No: $request_no", 
                    mysqli_insert_id($conn), $request_no);
                
                // Refresh the list
                $reg_stmt2 = mysqli_prepare($conn, $reg_query);
                mysqli_stmt_bind_param($reg_stmt2, "i", $current_employee_id);
                mysqli_stmt_execute($reg_stmt2);
                $reg_res2 = mysqli_stmt_get_result($reg_stmt2);
                $reg_requests = mysqli_fetch_all($reg_res2, MYSQLI_ASSOC);
                mysqli_stmt_close($reg_stmt2);
            } else {
                $message = "Failed to submit request: " . mysqli_error($conn);
                $message_type = "danger";
            }
            mysqli_stmt_close($insert_stmt);
        } else {
            $message = implode("<br>", $errors);
            $message_type = "danger";
        }
    }
}

// Helper functions
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function formatTime($time) {
    if (empty($time)) return '—';
    $ts = strtotime($time);
    return $ts ? date('h:i A', $ts) : $time;
}

function getRequestTypeBadge($type) {
    $badges = [
        'punch_in' => '<span class="badge bg-info"><i class="bi bi-box-arrow-in-right"></i> Missing Punch In</span>',
        'punch_out' => '<span class="badge bg-warning"><i class="bi bi-box-arrow-right"></i> Missing Punch Out</span>',
        'both' => '<span class="badge bg-danger"><i class="bi bi-clock"></i> Both Missed</span>',
        'full_day' => '<span class="badge bg-primary"><i class="bi bi-calendar-day"></i> Full Day Correction</span>',
        'incorrect' => '<span class="badge bg-secondary"><i class="bi bi-pencil-square"></i> Incorrect Time</span>'
    ];
    return $badges[$type] ?? '<span class="badge bg-secondary">' . ucfirst($type) . '</span>';
}

function getStatusBadge($status) {
    $badges = [
        'Pending' => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Pending</span>',
        'Approved' => '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>',
        'Rejected' => '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">' . $status . '</span>';
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
    <title>Attendance Regularization - TEK-C</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet" />
    
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    
    <style>
        .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px 22px 14px; }
        .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow); padding: 16px; height: 100%; }
        .panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .panel-title { font-weight: 1000; font-size: 18px; color: #1f2937; margin: 0; }
        
        /* Form Card - White background with shadow */
        .form-card { 
            background: #ffffff; 
            border-radius: 20px; 
            padding: 24px; 
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
        }
        .form-card h3 { 
            color: #111827; 
            font-weight: 800; 
            margin-bottom: 20px;
            font-size: 20px;
        }
        .form-card h3 i { 
            color: #3b82f6; 
        }
        .form-card label { 
            font-weight: 700; 
            font-size: 13px; 
            margin-bottom: 6px; 
            color: #374151;
            opacity: 1;
        }
        .form-card .form-control, 
        .form-card .form-select { 
            border-radius: 12px; 
            border: 1px solid #d1d5db; 
            padding: 10px 14px; 
            background: #ffffff;
            color: #111827;
            font-weight: 500;
            transition: all 0.2s;
        }
        .form-card .form-control:focus, 
        .form-card .form-select:focus { 
            border-color: #3b82f6; 
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        .form-card .form-control::placeholder {
            color: #9ca3af;
        }
        .form-card .btn-submit { 
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white; 
            border: none; 
            border-radius: 12px; 
            padding: 10px 28px; 
            font-weight: 700; 
            transition: all 0.3s;
            font-size: 14px;
        }
        .form-card .btn-submit:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 16px -4px rgba(59, 130, 246, 0.3);
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }
        .form-card .btn-submit:active {
            transform: translateY(0);
        }
        
        .info-card { 
            background: #f9fafb; 
            border: 1px solid #e5e7eb; 
            border-radius: 12px; 
            padding: 12px 16px; 
            margin-top: 15px;
            color: #111827;
        }
        .info-card strong {
            color: #374151;
        }
        
        .table-responsive { overflow-x: hidden !important; }
        table.dataTable { width: 100% !important; }
        .table thead th { font-size: 12px; color: #6b7280; font-weight: 900; border-bottom: 1px solid var(--border) !important; padding: 12px 10px !important; }
        .table td { vertical-align: middle; border-color: var(--border); font-weight: 600; color: #374151; padding: 12px 10px !important; }
        
        .btn-action { background: transparent; border: 1px solid var(--border); border-radius: 12px; padding: 8px 16px; color: #374151; font-size: 13px; font-weight: 1000; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-action:hover { background: #f9fafb; color: var(--blue); border-color: var(--blue); }
        
        .r-card { border: 1px solid var(--border); border-radius: 16px; background: var(--surface); padding: 12px; margin-bottom: 12px; }
        .r-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .r-kv { margin-top: 12px; display: grid; gap: 8px; }
        .r-row { display: flex; gap: 10px; align-items: flex-start; }
        .r-key { flex: 0 0 100px; color: #6b7280; font-weight: 800; font-size: 11px; text-transform: uppercase; }
        .r-val { flex: 1 1 auto; font-weight: 700; color: #111827; font-size: 13px; }
        .r-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        
        .time-group { display: flex; gap: 10px; align-items: center; }
        .time-group .form-control { flex: 1; }
        
        .form-hint {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px !important; }
            .time-group { flex-direction: column; align-items: stretch; }
            .time-group .form-control { width: 100%; }
            .form-card { padding: 16px; }
        }
        @media (max-width: 991.98px) {
            .main { margin-left: 0 !important; width: 100% !important; }
            .sidebar { position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
            .sidebar.open { transform: translateX(0) !important; }
        }
        
        .manager-info {
            background: #f0f9ff;
            border-radius: 12px;
            padding: 12px;
            margin-top: 10px;
            display: none;
        }
        .manager-info.show {
            display: block;
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
                        <h1 class="h3 fw-bold text-dark mb-1">Attendance Regularization</h1>
                        <p class="text-muted mb-0">Request corrections for missed or incorrect punch entries</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="attendance.php" class="btn-action">
                            <i class="bi bi-box-arrow-in-right"></i> Punch In/Out
                        </a>
                        <a href="my-attendance.php" class="btn-action">
                            <i class="bi bi-calendar-check"></i> My Attendance
                        </a>
                    </div>
                </div>
                
                <!-- Flash Messages -->
                <?php if ($flash_success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo e($flash_success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($flash_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($flash_error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Regularization Form -->
                <div class="form-card">
                    <h3><i class="bi bi-pencil-square me-2"></i> New Regularization Request</h3>
                    <form method="POST" enctype="multipart/form-data" id="regularizationForm">
                        <input type="hidden" name="action" value="submit_regularization">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Select Project/Site <span class="text-danger">*</span></label>
                                <select name="selected_site_id" class="form-select" id="selected_site" required onchange="updateManagerInfo()">
                                    <option value="">Select Project</option>
                                    <?php foreach ($assigned_sites as $site): ?>
                                        <option value="<?php echo $site['id']; ?>" 
                                                data-manager-id="<?php echo $site['manager_employee_id']; ?>"
                                                data-manager-name="<?php echo e($site['manager_name']); ?>">
                                            <?php echo e($site['project_name']); ?> 
                                            <?php if ($site['project_code']): ?>(<?php echo e($site['project_code']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($assigned_sites)): ?>
                                    <div class="form-hint text-danger">No projects assigned. Please contact HR.</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Select Date <span class="text-danger">*</span></label>
                                <input type="date" name="attendance_date" class="form-control" id="attendance_date" 
                                       max="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" 
                                       required onchange="loadAttendanceData(this.value)">
                            </div>
                            
                            <div class="col-md-12" id="managerInfo" class="manager-info">
                                <div class="info-card">
                                    <i class="bi bi-person-badge me-2"></i>
                                    <strong>Reporting Manager:</strong> <span id="manager_name_display">—</span>
                                </div>
                            </div>
                            
                            <div class="col-md-12" id="currentTimeDisplay" style="display: none;">
                                <div class="info-card">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Current Punch In:</strong> <span id="current_punch_in_display">—</span>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Current Punch Out:</strong> <span id="current_punch_out_display">—</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Request Type <span class="text-danger">*</span></label>
                                <select name="request_type" class="form-select" id="request_type" required onchange="toggleTimeFields()">
                                    <option value="">Select Type</option>
                                    <option value="punch_in">Missing Punch In</option>
                                    <option value="punch_out">Missing Punch Out</option>
                                    <option value="both">Both Punches Missing</option>
                                    <option value="full_day">Full Day Correction</option>
                                    <option value="incorrect">Incorrect Time Entry</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6" id="punch_in_field" style="display: none;">
                                <label class="form-label">Requested Punch In Time</label>
                                <input type="time" name="requested_punch_in" class="form-control" id="requested_punch_in" step="60">
                                <div class="form-hint">Format: HH:MM (24-hour)</div>
                            </div>
                            
                            <div class="col-md-6" id="punch_out_field" style="display: none;">
                                <label class="form-label">Requested Punch Out Time</label>
                                <input type="time" name="requested_punch_out" class="form-control" id="requested_punch_out" step="60">
                                <div class="form-hint">Format: HH:MM (24-hour)</div>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">Reason for Regularization <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control" rows="3" required placeholder="Please provide detailed reason for this request..."></textarea>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="form-label">Supporting Document (Optional)</label>
                                <input type="file" name="supporting_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <div class="form-hint">Accepted formats: PDF, JPG, PNG, DOC (Max 5MB)</div>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn-submit" <?php echo empty($assigned_sites) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-send"></i> Submit Request
                                </button>
                                <?php if (empty($assigned_sites)): ?>
                                    <div class="form-hint text-danger mt-2">You need to be assigned to a project to submit regularization requests.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Guidelines -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="panel text-center">
                            <i class="bi bi-info-circle fs-1 text-primary"></i>
                            <h6 class="mt-2 fw-bold">Eligibility</h6>
                            <p class="small text-muted mb-0">Regularization allowed only for dates within the last 30 days</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="panel text-center">
                            <i class="bi bi-clock-history fs-1 text-warning"></i>
                            <h6 class="mt-2 fw-bold">Processing Time</h6>
                            <p class="small text-muted mb-0">Requests are typically processed within 2-3 business days</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="panel text-center">
                            <i class="bi bi-person-check fs-1 text-success"></i>
                            <h6 class="mt-2 fw-bold">Approval Required</h6>
                            <p class="small text-muted mb-0">All requests require project manager approval</p>
                        </div>
                    </div>
                </div>
                
                <!-- My Regularization Requests -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">My Regularization Requests</h3>
                        <span class="badge bg-light text-dark"><?php echo count($reg_requests); ?> requests</span>
                    </div>
                    
                    <!-- Desktop Table -->
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table id="requestsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Request No.</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Current</th>
                                        <th>Requested</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reg_requests as $req): ?>
                                        <?php
                                        $current_time = '';
                                        if ($req['current_punch_in'] && $req['current_punch_out']) {
                                            $current_time = formatTime($req['current_punch_in']) . ' - ' . formatTime($req['current_punch_out']);
                                        } elseif ($req['current_punch_in']) {
                                            $current_time = 'In: ' . formatTime($req['current_punch_in']);
                                        } elseif ($req['current_punch_out']) {
                                            $current_time = 'Out: ' . formatTime($req['current_punch_out']);
                                        } else {
                                            $current_time = '—';
                                        }
                                        
                                        $requested_time = '';
                                        if ($req['requested_punch_in'] && $req['requested_punch_out']) {
                                            $requested_time = formatTime($req['requested_punch_in']) . ' - ' . formatTime($req['requested_punch_out']);
                                        } elseif ($req['requested_punch_in']) {
                                            $requested_time = 'In: ' . formatTime($req['requested_punch_in']);
                                        } elseif ($req['requested_punch_out']) {
                                            $requested_time = 'Out: ' . formatTime($req['requested_punch_out']);
                                        } else {
                                            $requested_time = '—';
                                        }
                                        ?>
                                        <tr>
                                            <td><span class="fw-bold"><?php echo e($req['request_no']); ?></span></td>
                                            <td><?php echo e(date('d M Y', strtotime($req['attendance_date']))); ?></td>
                                            <td><?php echo getRequestTypeBadge($req['request_type']); ?></td>
                                            <td><small><?php echo e($current_time); ?></small></td>
                                            <td><small class="text-primary fw-bold"><?php echo e($requested_time); ?></small></td>
                                            <td><small><?php echo e(substr($req['reason'], 0, 50)); ?>...</small></td>
                                            <td><?php echo getStatusBadge($req['status']); ?></td>
                                            <td><small><?php echo e(date('d M Y', strtotime($req['created_at']))); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($reg_requests)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                No regularization requests found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Mobile Cards -->
                    <div class="d-block d-md-none">
                        <?php if (empty($reg_requests)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No regularization requests found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($reg_requests as $req): ?>
                                <?php
                                $current_time = '';
                                if ($req['current_punch_in'] && $req['current_punch_out']) {
                                    $current_time = formatTime($req['current_punch_in']) . ' - ' . formatTime($req['current_punch_out']);
                                } elseif ($req['current_punch_in']) {
                                    $current_time = 'In: ' . formatTime($req['current_punch_in']);
                                } elseif ($req['current_punch_out']) {
                                    $current_time = 'Out: ' . formatTime($req['current_punch_out']);
                                } else {
                                    $current_time = '—';
                                }
                                
                                $requested_time = '';
                                if ($req['requested_punch_in'] && $req['requested_punch_out']) {
                                    $requested_time = formatTime($req['requested_punch_in']) . ' - ' . formatTime($req['requested_punch_out']);
                                } elseif ($req['requested_punch_in']) {
                                    $requested_time = 'In: ' . formatTime($req['requested_punch_in']);
                                } elseif ($req['requested_punch_out']) {
                                    $requested_time = 'Out: ' . formatTime($req['requested_punch_out']);
                                } else {
                                    $requested_time = '—';
                                }
                                ?>
                                <div class="r-card">
                                    <div class="r-top">
                                        <div>
                                            <div class="fw-bold"><?php echo e($req['request_no']); ?></div>
                                            <div class="small text-muted"><?php echo e(date('d M Y', strtotime($req['attendance_date']))); ?></div>
                                        </div>
                                        <?php echo getStatusBadge($req['status']); ?>
                                    </div>
                                    <div class="r-kv">
                                        <div class="r-row">
                                            <div class="r-key">Type</div>
                                            <div class="r-val"><?php echo getRequestTypeBadge($req['request_type']); ?></div>
                                        </div>
                                        <div class="r-row">
                                            <div class="r-key">Current</div>
                                            <div class="r-val"><?php echo e($current_time); ?></div>
                                        </div>
                                        <div class="r-row">
                                            <div class="r-key">Requested</div>
                                            <div class="r-val text-primary fw-bold"><?php echo e($requested_time); ?></div>
                                        </div>
                                        <div class="r-row">
                                            <div class="r-key">Reason</div>
                                            <div class="r-val"><?php echo e($req['reason']); ?></div>
                                        </div>
                                        <?php if ($req['remarks']): ?>
                                            <div class="r-row">
                                                <div class="r-key">Remarks</div>
                                                <div class="r-val"><?php echo e($req['remarks']); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
            </div>
        </div>
        
        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    // Store attendance data for quick lookup
    const attendanceData = <?php 
        $attendance_map = [];
        foreach ($attendance_records as $record) {
            $attendance_map[$record['attendance_date']] = [
                'punch_in' => $record['punch_in_time'] ? date('H:i', strtotime($record['punch_in_time'])) : null,
                'punch_out' => $record['punch_out_time'] ? date('H:i', strtotime($record['punch_out_time'])) : null
            ];
        }
        echo json_encode($attendance_map);
    ?>;
    
    function updateManagerInfo() {
        const select = document.getElementById('selected_site');
        const selectedOption = select.options[select.selectedIndex];
        const managerName = selectedOption.getAttribute('data-manager-name');
        const managerInfo = document.getElementById('managerInfo');
        const managerNameDisplay = document.getElementById('manager_name_display');
        
        if (select.value && managerName) {
            managerNameDisplay.textContent = managerName;
            managerInfo.style.display = 'block';
        } else {
            managerInfo.style.display = 'none';
        }
    }
    
    function loadAttendanceData(date) {
        const data = attendanceData[date];
        const currentDisplayDiv = document.getElementById('currentTimeDisplay');
        const currentInSpan = document.getElementById('current_punch_in_display');
        const currentOutSpan = document.getElementById('current_punch_out_display');
        
        if (data) {
            currentInSpan.textContent = data.punch_in ? data.punch_in : '—';
            currentOutSpan.textContent = data.punch_out ? data.punch_out : '—';
            currentDisplayDiv.style.display = 'block';
        } else {
            currentInSpan.textContent = 'No record found';
            currentOutSpan.textContent = 'No record found';
            currentDisplayDiv.style.display = 'block';
        }
    }
    
    function toggleTimeFields() {
        const requestType = document.getElementById('request_type').value;
        const punchInField = document.getElementById('punch_in_field');
        const punchOutField = document.getElementById('punch_out_field');
        const punchInInput = document.getElementById('requested_punch_in');
        const punchOutInput = document.getElementById('requested_punch_out');
        
        // Hide both first
        punchInField.style.display = 'none';
        punchOutField.style.display = 'none';
        
        // Clear values
        punchInInput.value = '';
        punchOutInput.value = '';
        
        switch(requestType) {
            case 'punch_in':
                punchInField.style.display = 'block';
                break;
            case 'punch_out':
                punchOutField.style.display = 'block';
                break;
            case 'both':
            case 'full_day':
            case 'incorrect':
                punchInField.style.display = 'block';
                punchOutField.style.display = 'block';
                break;
        }
        
        // For incorrect type, pre-populate with current values if available
        if (requestType === 'incorrect') {
            const date = document.getElementById('attendance_date').value;
            const data = attendanceData[date];
            if (data) {
                if (data.punch_in) punchInInput.value = data.punch_in;
                if (data.punch_out) punchOutInput.value = data.punch_out;
            }
        }
    }
    
    // Initialize DataTable
    $(document).ready(function() {
        if (window.matchMedia('(min-width: 768px)').matches && $('#requestsTable tbody tr').length > 1) {
            $('#requestsTable').DataTable({
                responsive: true,
                autoWidth: false,
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
                order: [[7, 'desc']],
                language: {
                    zeroRecords: "No regularization requests found",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "No entries to show",
                    lengthMenu: "Show _MENU_",
                    search: "Search:"
                }
            });
        }
        
        // Set default date to yesterday
        const dateInput = document.getElementById('attendance_date');
        if (dateInput && !dateInput.value) {
            const yesterday = new Date();
            yesterday.setDate(yesterday.getDate() - 1);
            dateInput.value = yesterday.toISOString().split('T')[0];
            loadAttendanceData(dateInput.value);
        }
    });
    
    // Form validation
    document.getElementById('regularizationForm').addEventListener('submit', function(e) {
        const requestType = document.getElementById('request_type').value;
        const punchIn = document.getElementById('requested_punch_in').value;
        const punchOut = document.getElementById('requested_punch_out').value;
        
        if (requestType === 'punch_in' && !punchIn) {
            e.preventDefault();
            alert('Please provide requested punch-in time.');
            return false;
        }
        
        if (requestType === 'punch_out' && !punchOut) {
            e.preventDefault();
            alert('Please provide requested punch-out time.');
            return false;
        }
        
        if ((requestType === 'both' || requestType === 'full_day') && !punchIn && !punchOut) {
            e.preventDefault();
            alert('Please provide at least one requested time.');
            return false;
        }
        
        if (requestType === 'full_day' && (!punchIn || !punchOut)) {
            e.preventDefault();
            alert('Please provide both punch-in and punch-out times for full day correction.');
            return false;
        }
        
        return true;
    });
</script>
</body>
</html>

<?php
try {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
} catch (Throwable $e) { }
?>