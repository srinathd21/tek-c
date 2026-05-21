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

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEsc = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function sendNotification(
    $conn,
    int $toEmployeeId,
    string $title,
    string $message,
    string $module = 'attendance_regularization',
    ?int $referenceId = null,
    string $link = ''
): bool {
    if ($toEmployeeId <= 0 || !tableExists($conn, 'notifications')) return false;

    $cols = [];
    $vals = [];
    $types = '';

    $map = [
        'employee_id'  => ['i', $toEmployeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', 'attendance'],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'is_read'      => ['i', 0]
    ];

    foreach ($map as $col => $pair) {
        if (columnExists($conn, 'notifications', $col)) {
            $cols[] = "`$col`";
            $types .= $pair[0];
            $vals[] = $pair[1];
        }
    }

    if (!$cols) return false;

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, $types, ...$vals);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function safeDate($v, $dash='—') {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function formatTime($time) {
    if (empty($time)) return '—';
    $ts = strtotime($time);
    return $ts ? date('h:i A', $ts) : $time;
}

function requestTypeMeta($type) {
    $type = strtolower((string)$type);
    $map = [
        'punch_in' => ['Missing Punch In', 'progressing', 'bi-box-arrow-in-right'],
        'punch_out' => ['Missing Punch Out', 'pending', 'bi-box-arrow-right'],
        'both' => ['Both Missed', 'atrisk', 'bi-clock'],
        'full_day' => ['Full Day Correction', 'progressing', 'bi-calendar-day'],
        'incorrect' => ['Incorrect Time', 'neutral', 'bi-pencil-square']
    ];
    return $map[$type] ?? [ucfirst(str_replace('_', ' ', $type)), 'neutral', 'bi-info-circle'];
}

function statusMeta($status) {
    $status = strtolower((string)$status);
    if ($status === 'pending') return ['Pending', 'pending', 'bi-hourglass-split'];
    if ($status === 'approved') return ['Approved', 'ontrack', 'bi-check2-circle'];
    if ($status === 'rejected') return ['Rejected', 'atrisk', 'bi-x-circle'];
    return [ucfirst($status ?: '—'), 'neutral', 'bi-info-circle'];
}

function buildTimeRange($in, $out) {
    if ($in && $out) return formatTime($in) . ' - ' . formatTime($out);
    if ($in) return 'In: ' . formatTime($in);
    if ($out) return 'Out: ' . formatTime($out);
    return '—';
}


function employeeRoleKey(array $empRow): string {
    $designation = strtolower(trim((string)($empRow['designation'] ?? '')));
    $department  = strtolower(trim((string)($empRow['department'] ?? '')));

    if (
        str_contains($designation, 'admin') ||
        str_contains($designation, 'administrator') ||
        str_contains($department, 'admin')
    ) {
        return 'admin';
    }

    if (
        str_contains($designation, 'hr') ||
        str_contains($designation, 'human resource') ||
        str_contains($department, 'hr') ||
        str_contains($department, 'human resource')
    ) {
        return 'hr';
    }

    if (
        str_contains($designation, 'qs') ||
        str_contains($designation, 'quantity surveyor') ||
        str_contains($department, 'qs') ||
        str_contains($department, 'quantity surveyor')
    ) {
        return 'qs';
    }

    if (
        str_contains($designation, 'manager') ||
        str_contains($designation, 'project manager')
    ) {
        return 'manager';
    }

    if (
        str_contains($designation, 'team lead') ||
        str_contains($designation, 'tl') ||
        str_contains($designation, 'lead')
    ) {
        return 'tl';
    }

    if (
        str_contains($designation, 'project engineer') ||
        str_contains($designation, 'engineer')
    ) {
        return 'project_engineer';
    }

    return 'employee';
}

function findAdminApprover($conn, int $excludeEmployeeId = 0): array {
    $stmt = mysqli_prepare($conn, "
        SELECT id, full_name
        FROM employees
        WHERE id <> ?
          AND (
            LOWER(COALESCE(designation,'')) LIKE '%admin%'
            OR LOWER(COALESCE(department,'')) LIKE '%admin%'
            OR LOWER(COALESCE(username,'')) = 'admin'
          )
          AND (
            employee_status IS NULL
            OR LOWER(employee_status) = 'active'
          )
        ORDER BY
          CASE
            WHEN LOWER(COALESCE(username,'')) = 'admin' THEN 1
            WHEN LOWER(COALESCE(designation,'')) LIKE '%admin%' THEN 2
            ELSE 3
          END,
          id ASC
        LIMIT 1
    ");

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $excludeEmployeeId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $admin = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if ($admin) {
            return [
                'id' => (int)$admin['id'],
                'name' => (string)($admin['full_name'] ?? ''),
                'source' => 'Admin'
            ];
        }
    }

    return ['id' => 0, 'name' => '', 'source' => ''];
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

$currentRoleKey = employeeRoleKey($employee ?: []);
$projectSelectionNotRequired = in_array($currentRoleKey, ['hr', 'qs'], true);

$hasTeamLeadCol = columnExists($conn, 'sites', 'team_lead_employee_id');
$hasDeletedAtCol = columnExists($conn, 'sites', 'deleted_at');

$teamLeadSelect = $hasTeamLeadCol
    ? "s.team_lead_employee_id, tl.full_name AS team_lead_name,"
    : "NULL AS team_lead_employee_id, NULL AS team_lead_name,";

$teamLeadJoin = $hasTeamLeadCol
    ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id"
    : "LEFT JOIN employees tl ON 1=0";

$deletedFilter = $hasDeletedAtCol ? "AND s.deleted_at IS NULL" : "";

// Get assigned sites/projects for the employee.
// Managers see projects where they are project manager.
// TL sees projects where they are TL or assigned in site_project_engineers.
// Project Engineers see assigned projects.
// HR and QS do not need project selection for this request.
$assigned_sites = [];

if (!$projectSelectionNotRequired) {
    $siteAccessWhere = "spe_self.employee_id = ?";

    if ($currentRoleKey === 'manager') {
        $siteAccessWhere = "(s.manager_employee_id = ? OR spe_self.employee_id = ?)";
    } elseif ($currentRoleKey === 'tl') {
        if ($hasTeamLeadCol) {
            $siteAccessWhere = "(s.team_lead_employee_id = ? OR spe_self.employee_id = ?)";
        } else {
            $siteAccessWhere = "spe_self.employee_id = ?";
        }
    }

    $sites_query = "
        SELECT DISTINCT
            s.id,
            s.project_name,
            s.project_code,
            s.manager_employee_id,
            $teamLeadSelect
            e.full_name AS manager_name,
            ptl.id AS fallback_team_lead_id,
            ptl.full_name AS fallback_team_lead_name
        FROM sites s
        LEFT JOIN employees e ON s.manager_employee_id = e.id
        $teamLeadJoin
        LEFT JOIN site_project_engineers spe_self ON spe_self.site_id = s.id
        LEFT JOIN site_project_engineers spe_tl ON spe_tl.site_id = s.id
        LEFT JOIN employees ptl
          ON ptl.id = spe_tl.employee_id
         AND (
           LOWER(COALESCE(ptl.designation,'')) LIKE '%team lead%'
           OR LOWER(COALESCE(ptl.designation,'')) LIKE '%tl%'
           OR LOWER(COALESCE(ptl.designation,'')) LIKE '%lead%'
         )
        WHERE $siteAccessWhere
          $deletedFilter
        ORDER BY s.project_name
    ";

    $sites_stmt = mysqli_prepare($conn, $sites_query);
    if ($sites_stmt) {
        if ($currentRoleKey === 'manager' || ($currentRoleKey === 'tl' && $hasTeamLeadCol)) {
            mysqli_stmt_bind_param($sites_stmt, "ii", $current_employee_id, $current_employee_id);
        } else {
            mysqli_stmt_bind_param($sites_stmt, "i", $current_employee_id);
        }

        mysqli_stmt_execute($sites_stmt);
        $sites_res = mysqli_stmt_get_result($sites_stmt);
        $assigned_sites = mysqli_fetch_all($sites_res, MYSQLI_ASSOC);
        mysqli_stmt_close($sites_stmt);
    }
}

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
        
        // Get approver details.
        // HR and QS do not select projects; they go directly to Admin.
        // Others use project hierarchy: PE -> TL, TL -> Manager, Manager -> Admin.
        $manager_id = null;
        $manager_name = '';
        $approver_source = '';

        if ($projectSelectionNotRequired) {
            $adminApprover = findAdminApprover($conn, $current_employee_id);
            if ((int)$adminApprover['id'] > 0) {
                $manager_id = (int)$adminApprover['id'];
                $manager_name = (string)$adminApprover['name'];
                $approver_source = 'Admin';
            }
        } elseif (!empty($selected_site_id)) {
            $site_manager_stmt = mysqli_prepare($conn, "
                SELECT
                    s.manager_employee_id,
                    mgr.full_name AS manager_name,
                    $teamLeadSelect
                    ptl.id AS fallback_team_lead_id,
                    ptl.full_name AS fallback_team_lead_name
                FROM sites s
                LEFT JOIN employees mgr ON mgr.id = s.manager_employee_id
                $teamLeadJoin
                LEFT JOIN site_project_engineers spe_tl ON spe_tl.site_id = s.id
                LEFT JOIN employees ptl
                  ON ptl.id = spe_tl.employee_id
                 AND (
       LOWER(COALESCE(ptl.designation,'')) LIKE '%team lead%'
       OR LOWER(COALESCE(ptl.designation,'')) LIKE '%tl%'
       OR LOWER(COALESCE(ptl.designation,'')) LIKE '%lead%'
     )
                WHERE s.id = ?
                LIMIT 1
            ");
            mysqli_stmt_bind_param($site_manager_stmt, "i", $selected_site_id);
            mysqli_stmt_execute($site_manager_stmt);
            $site_manager_res = mysqli_stmt_get_result($site_manager_stmt);
            $site_manager = mysqli_fetch_assoc($site_manager_res);
            if ($site_manager) {
                $tlId = (int)($site_manager['team_lead_employee_id'] ?? 0);
                $fallbackTlId = (int)($site_manager['fallback_team_lead_id'] ?? 0);
                $projectManagerId = (int)($site_manager['manager_employee_id'] ?? 0);

                if ($currentRoleKey === 'manager') {
                    $adminApprover = findAdminApprover($conn, $current_employee_id);
                    if ((int)$adminApprover['id'] > 0) {
                        $manager_id = (int)$adminApprover['id'];
                        $manager_name = (string)$adminApprover['name'];
                        $approver_source = 'Admin';
                    }
                } elseif ($currentRoleKey === 'tl') {
                    if ($projectManagerId > 0 && $projectManagerId !== $current_employee_id) {
                        $manager_id = $projectManagerId;
                        $manager_name = (string)($site_manager['manager_name'] ?? '');
                        $approver_source = 'Project Manager';
                    } else {
                        $adminApprover = findAdminApprover($conn, $current_employee_id);
                        if ((int)$adminApprover['id'] > 0) {
                            $manager_id = (int)$adminApprover['id'];
                            $manager_name = (string)$adminApprover['name'];
                            $approver_source = 'Admin';
                        }
                    }
                } else {
                    if ($tlId > 0 && $tlId !== $current_employee_id) {
                        $manager_id = $tlId;
                        $manager_name = (string)($site_manager['team_lead_name'] ?? '');
                        $approver_source = 'Project TL';
                    } elseif ($fallbackTlId > 0 && $fallbackTlId !== $current_employee_id) {
                        $manager_id = $fallbackTlId;
                        $manager_name = (string)($site_manager['fallback_team_lead_name'] ?? '');
                        $approver_source = 'Project TL';
                    } elseif ($projectManagerId > 0 && $projectManagerId !== $current_employee_id) {
                        $manager_id = $projectManagerId;
                        $manager_name = (string)($site_manager['manager_name'] ?? '');
                        $approver_source = 'Project Manager';
                    } else {
                        $adminApprover = findAdminApprover($conn, $current_employee_id);
                        if ((int)$adminApprover['id'] > 0) {
                            $manager_id = (int)$adminApprover['id'];
                            $manager_name = (string)$adminApprover['name'];
                            $approver_source = 'Admin';
                        }
                    }
                }
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
    
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    
    <style>
        :root{
            --page-bg:#f5f7fb;
            --card-bg:#ffffff;
            --border:#e5e7eb;
            --text:#111827;
            --muted:#6b7280;
            --soft:#f8fafc;
            --shadow:0 10px 26px rgba(15,23,42,.055);
            --radius:15px;
        }

        body{ background:var(--page-bg); }

        .content-scroll{
            flex:1 1 auto;
            overflow:auto;
            padding:16px;
        }

        .projects-wrapper{ width:100%; }

        .page-heading{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .page-heading h1{
            font-size:19px;
            font-weight:900;
            color:var(--text);
            margin:0;
        }

        .page-heading p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:12px;
            font-weight:600;
        }

        .primary-btn,
        .secondary-btn{
            height:36px;
            padding:0 14px;
            border-radius:11px;
            font-size:12px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            text-decoration:none;
            white-space:nowrap;
        }

        .primary-btn{
            border:0;
            background:#111827;
            color:#fff;
        }

        .primary-btn:hover{
            background:#020617;
            color:#fff;
        }

        .secondary-btn{
            border:1px solid var(--border);
            background:#fff;
            color:#334155;
        }

        .secondary-btn:hover{
            border-color:#cbd5e1;
            background:#f8fafc;
            color:#111827;
        }

        .stat-card{
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:12px 13px;
            min-height:78px;
            display:flex;
            align-items:center;
            gap:11px;
        }

        .stat-ic{
            width:38px;
            height:38px;
            border-radius:12px;
            display:grid;
            place-items:center;
            color:#fff;
            font-size:17px;
            flex:0 0 auto;
        }

        .blue{background:#2f80ed;}
        .orange{background:#f2994a;}
        .green{background:#27ae60;}
        .red{background:#eb5757;}
        .gray{background:#64748b;}

        .stat-label{
            color:var(--muted);
            font-weight:800;
            font-size:10.5px;
            text-transform:uppercase;
        }

        .stat-value{
            font-size:24px;
            font-weight:950;
            color:var(--text);
            line-height:1;
        }

        .panel{
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:13px;
            margin-bottom:14px;
        }

        .panel-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:12px;
        }

        .panel-title{
            font-weight:900;
            font-size:14px;
            margin:0;
            color:var(--text);
        }

        .panel-subtitle{
            color:var(--muted);
            font-size:11px;
            font-weight:700;
            margin-top:2px;
        }

        .form-label{
            font-size:11px;
            font-weight:900;
            color:#475569;
            text-transform:uppercase;
            margin-bottom:6px;
        }

        .form-control,
        .form-select{
            min-height:38px;
            border:1px solid var(--border);
            border-radius:11px;
            font-size:12px;
            font-weight:800;
            color:#111827;
            padding:8px 11px;
        }

        .form-control:focus,
        .form-select:focus{
            border-color:#bfdbfe;
            box-shadow:0 0 0 3px rgba(59,130,246,.10);
        }

        textarea.form-control{ min-height:86px; }

        .form-hint{
            color:#64748b;
            font-size:10.5px;
            font-weight:700;
            margin-top:5px;
        }

        .info-card{
            border:1px solid var(--border);
            border-radius:13px;
            background:#f8fafc;
            padding:10px 12px;
            color:#334155;
            font-size:12px;
            font-weight:800;
        }

        .badge-pill{
            border-radius:999px;
            padding:5px 8px;
            font-weight:900;
            font-size:10px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            border:1px solid transparent;
            text-decoration:none;
            white-space:nowrap;
        }

        .mini-dot{
            width:6px;
            height:6px;
            border-radius:50%;
            background:currentColor;
        }

        .ontrack{color:#15803d;background:#dcfce7;border-color:#bbf7d0;}
        .progressing{color:#2563eb;background:#dbeafe;border-color:#bfdbfe;}
        .pending{color:#6d28d9;background:#ede9fe;border-color:#ddd6fe;}
        .atrisk{color:#b91c1c;background:#fee2e2;border-color:#fecaca;}
        .neutral{color:#475569;background:#f1f5f9;border-color:#e2e8f0;}

        .compact-table-wrap{
            width:100%;
            border:1px solid var(--border);
            border-radius:13px;
            overflow:hidden;
            background:#fff;
        }

        .compact-table{
            width:100%;
            margin:0;
            table-layout:auto;
        }

        .compact-table thead th{
            background:var(--soft);
            color:#64748b;
            font-size:10px;
            text-transform:uppercase;
            font-weight:900;
            border-bottom:1px solid var(--border)!important;
            padding:8px 9px;
        }

        .compact-table tbody td{
            padding:8px 9px;
            vertical-align:middle;
            border-color:#eef2f7;
            color:#334155;
            font-weight:700;
            font-size:11.5px;
        }

        .compact-table tbody tr:hover{ background:#fbfdff; }

        .table-title-cell{
            display:flex;
            align-items:center;
            gap:8px;
        }

        .table-icon{
            width:26px;
            height:26px;
            border-radius:8px;
            display:grid;
            place-items:center;
            background:#eff6ff;
            color:#2563eb;
            font-size:13px;
            flex:0 0 auto;
        }

        .table-primary-text{
            color:#111827;
            font-size:11.5px;
            font-weight:900;
        }

        .table-secondary-text{
            color:#64748b;
            font-size:10px;
            font-weight:700;
            margin-top:1px;
        }

        .reason-cell{
            max-width:260px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }

        .empty-state{
            text-align:center;
            color:#64748b;
            padding:30px 12px;
            font-size:12px;
            font-weight:900;
        }

        .empty-state i{
            font-size:34px;
            display:block;
            margin-bottom:8px;
            opacity:.45;
        }

        .submit-row{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding-top:12px;
            border-top:1px solid #eef2f7;
            margin-top:12px;
        }

        @media(max-width:991.98px){
            .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
            .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
            .sidebar.open, .sidebar.active, .sidebar.show{ transform:translateX(0)!important; }
        }

        @media(max-width:1199px){
            .compact-table thead{ display:none; }
            .compact-table,
            .compact-table tbody,
            .compact-table tr,
            .compact-table td{
                display:block;
                width:100%;
            }
            .compact-table tbody tr{
                border-bottom:1px solid var(--border);
                padding:10px;
            }
            .compact-table tbody td{
                border:0;
                display:flex;
                justify-content:space-between;
                gap:12px;
            }
            .compact-table tbody td::before{
                content:attr(data-label);
                font-size:10px;
                font-weight:900;
                color:#64748b;
                text-transform:uppercase;
                flex:0 0 95px;
            }
            .compact-table tbody td:first-child{
                display:block;
            }
            .compact-table tbody td:first-child::before{
                display:none;
            }
            .reason-cell{
                max-width:none;
                white-space:normal;
                text-align:right;
            }
        }

        @media(max-width:768px){
            .content-scroll{ padding:12px 10px 12px!important; }
            .page-heading{ align-items:flex-start; flex-direction:column; }
            .panel{ padding:12px; }
            .submit-row{ align-items:stretch; flex-direction:column; }
            .primary-btn,.secondary-btn{ width:100%; }
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>
        
        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid projects-wrapper px-0">

                <div class="page-heading">
                    <div>
                        <h1>Attendance Regularization</h1>
                        <p>Request corrections for missed or incorrect punch entries under your assigned project.</p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <a href="punchin.php" class="secondary-btn">
                            <i class="bi bi-fingerprint"></i>
                            Punch In / Out
                        </a>

                        <a href="my-attendance.php" class="primary-btn">
                            <i class="bi bi-calendar-check"></i>
                            My Attendance
                        </a>
                    </div>
                </div>

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
                    <div class="alert alert-<?php echo e($message_type); ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php
                    $pendingCount = 0;
                    $approvedCount = 0;
                    $rejectedCount = 0;
                    foreach ($reg_requests as $rr) {
                        $st = strtolower((string)($rr['status'] ?? ''));
                        if ($st === 'pending') $pendingCount++;
                        if ($st === 'approved') $approvedCount++;
                        if ($st === 'rejected') $rejectedCount++;
                    }
                ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-inboxes"></i></div>
                            <div>
                                <div class="stat-label">Total Requests</div>
                                <div class="stat-value"><?php echo count($reg_requests); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="stat-label">Pending</div>
                                <div class="stat-value"><?php echo (int)$pendingCount; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                            <div>
                                <div class="stat-label">Approved</div>
                                <div class="stat-value"><?php echo (int)$approvedCount; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic gray"><i class="bi bi-folder2"></i></div>
                            <div>
                                <div class="stat-label"><?php echo $projectSelectionNotRequired ? 'Approval Route' : 'Assigned Projects'; ?></div>
                                <div class="stat-value" style="<?php echo $projectSelectionNotRequired ? 'font-size:17px;' : ''; ?>">
                                    <?php echo $projectSelectionNotRequired ? 'Admin' : count($assigned_sites); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-xl-5">
                        <div class="panel">
                            <div class="panel-header">
                                <div>
                                    <h3 class="panel-title">New Regularization Request</h3>
                                    <div class="panel-subtitle">Select project, date, correction type and requested time</div>
                                </div>
                                <span class="badge-pill progressing">
                                    <span class="mini-dot"></span>
                                    <?php echo e($current_employee_name); ?>
                                </span>
                            </div>

                            <form method="POST" enctype="multipart/form-data" id="regularizationForm">
                                <input type="hidden" name="action" value="submit_regularization">

                                <div class="row g-3">
                                    <?php if (!$projectSelectionNotRequired): ?>
                                    <div class="col-12">
                                        <label class="form-label">Project / Site <span class="text-danger">*</span></label>
                                        <select name="selected_site_id" class="form-select" id="selected_site" required onchange="updateManagerInfo()">
                                            <option value="">Select Project</option>
                                            <?php foreach ($assigned_sites as $site): ?>
                                                <?php
                                                    $projectTlName = !empty($site['team_lead_name'])
                                                        ? $site['team_lead_name']
                                                        : ($site['fallback_team_lead_name'] ?? '');

                                                    $routeName = !empty($projectTlName)
                                                        ? $projectTlName
                                                        : ($site['manager_name'] ?? '');

                                                    $routeLabel = !empty($projectTlName) ? 'Project TL' : 'Project Manager';

                                                    if ($currentRoleKey === 'tl') {
                                                        $routeName = $site['manager_name'] ?? '';
                                                        $routeLabel = 'Project Manager';
                                                    } elseif ($currentRoleKey === 'manager') {
                                                        $routeName = 'Admin';
                                                        $routeLabel = 'Admin';
                                                    }
                                                ?>
                                                <option value="<?php echo (int)$site['id']; ?>"
                                                        data-manager-id="<?php echo e(!empty($site['team_lead_employee_id']) ? $site['team_lead_employee_id'] : (!empty($site['fallback_team_lead_id']) ? $site['fallback_team_lead_id'] : $site['manager_employee_id'])); ?>"
                                                        data-manager-name="<?php echo e($routeName); ?>"
                                                        data-manager-label="<?php echo e($routeLabel); ?>">
                                                    <?php echo e($site['project_name']); ?>
                                                    <?php if (!empty($site['project_code'])): ?>(<?php echo e($site['project_code']); ?>)<?php endif; ?>
                                                    <?php if (!empty($routeName)): ?> - <?php echo e($routeLabel . ': ' . $routeName); ?><?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <?php if (empty($assigned_sites)): ?>
                                            <div class="form-hint text-danger">
                                                No projects assigned. Please contact admin.
                                            </div>
                                        <?php else: ?>
                                            <div class="form-hint">
                                                Approval route is hierarchy based: Project Engineer → TL, TL → Manager, Manager → Admin.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                    <input type="hidden" name="selected_site_id" value="">
                                    <div class="col-12">
                                        <div class="info-card">
                                            <i class="bi bi-person-badge me-2"></i>
                                            <strong>Approval Route:</strong>
                                            HR / QS regularization requests are sent directly to Admin. Project selection is not required.
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-12" id="managerInfo" style="display:none;">
                                        <div class="info-card">
                                            <i class="bi bi-person-badge me-2"></i>
                                            <strong id="manager_label_display">Approver:</strong>
                                            <span id="manager_name_display">—</span>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Attendance Date <span class="text-danger">*</span></label>
                                        <input type="date" name="attendance_date" class="form-control" id="attendance_date"
                                               max="<?php echo date('Y-m-d'); ?>"
                                               min="<?php echo date('Y-m-d', strtotime('-30 days')); ?>"
                                               required onchange="loadAttendanceData(this.value)">
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

                                    <div class="col-12" id="currentTimeDisplay" style="display:none;">
                                        <div class="info-card">
                                            <div class="row g-2">
                                                <div class="col-md-6">
                                                    <strong>Current Punch In:</strong>
                                                    <span id="current_punch_in_display">—</span>
                                                </div>
                                                <div class="col-md-6">
                                                    <strong>Current Punch Out:</strong>
                                                    <span id="current_punch_out_display">—</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6" id="punch_in_field" style="display:none;">
                                        <label class="form-label">Requested Punch In</label>
                                        <input type="time" name="requested_punch_in" class="form-control" id="requested_punch_in" step="60">
                                        <div class="form-hint">Format: HH:MM</div>
                                    </div>

                                    <div class="col-md-6" id="punch_out_field" style="display:none;">
                                        <label class="form-label">Requested Punch Out</label>
                                        <input type="time" name="requested_punch_out" class="form-control" id="requested_punch_out" step="60">
                                        <div class="form-hint">Format: HH:MM</div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                                        <textarea name="reason" class="form-control" rows="3" required placeholder="Please provide detailed reason for this request..."></textarea>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Supporting Document</label>
                                        <input type="file" name="supporting_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                        <div class="form-hint">Accepted formats: PDF, JPG, PNG, DOC. Max size depends on server upload setting.</div>
                                    </div>
                                </div>

                                <div class="submit-row">
                                    <div class="table-secondary-text">
                                        Create action is stored in <b>activity_logs</b> and notification is sent to the approver.
                                    </div>

                                    <button type="submit" class="primary-btn" <?php echo (!$projectSelectionNotRequired && empty($assigned_sites)) ? 'disabled' : ''; ?>>
                                        <i class="bi bi-send-check"></i>
                                        Submit Request
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-12 col-xl-7">
                        <div class="panel">
                            <div class="panel-header">
                                <div>
                                    <h3 class="panel-title">My Regularization Requests</h3>
                                    <div class="panel-subtitle">Latest attendance correction requests</div>
                                </div>
                                <span class="badge-pill neutral">
                                    <?php echo count($reg_requests); ?> Requests
                                </span>
                            </div>

                            <div class="compact-table-wrap">
                                <table class="table compact-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Request</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Current</th>
                                            <th>Requested</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($reg_requests)): ?>
                                            <tr>
                                                <td colspan="7">
                                                    <div class="empty-state">
                                                        <i class="bi bi-inbox"></i>
                                                        No regularization requests found.
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($reg_requests as $req): ?>
                                                <?php
                                                    $current_time = buildTimeRange($req['current_punch_in'] ?? '', $req['current_punch_out'] ?? '');
                                                    $requested_time = buildTimeRange($req['requested_punch_in'] ?? '', $req['requested_punch_out'] ?? '');
                                                    [$typeLabel, $typeClass, $typeIcon] = requestTypeMeta($req['request_type'] ?? '');
                                                    [$statusLabel, $statusClass, $statusIcon] = statusMeta($req['status'] ?? '');
                                                ?>
                                                <tr>
                                                    <td data-label="Request">
                                                        <div class="table-title-cell">
                                                            <div class="table-icon">
                                                                <i class="bi bi-pencil-square"></i>
                                                            </div>
                                                            <div>
                                                                <div class="table-primary-text"><?php echo e($req['request_no'] ?? '—'); ?></div>
                                                                <div class="table-secondary-text reason-cell"><?php echo e($req['reason'] ?? '—'); ?></div>
                                                            </div>
                                                        </div>
                                                    </td>

                                                    <td data-label="Date">
                                                        <div class="table-primary-text"><?php echo e(safeDate($req['attendance_date'] ?? '')); ?></div>
                                                    </td>

                                                    <td data-label="Type">
                                                        <span class="badge-pill <?php echo e($typeClass); ?>">
                                                            <i class="bi <?php echo e($typeIcon); ?>"></i>
                                                            <?php echo e($typeLabel); ?>
                                                        </span>
                                                    </td>

                                                    <td data-label="Current">
                                                        <div class="table-secondary-text"><?php echo e($current_time); ?></div>
                                                    </td>

                                                    <td data-label="Requested">
                                                        <div class="table-primary-text"><?php echo e($requested_time); ?></div>
                                                    </td>

                                                    <td data-label="Status">
                                                        <span class="badge-pill <?php echo e($statusClass); ?>">
                                                            <i class="bi <?php echo e($statusIcon); ?>"></i>
                                                            <?php echo e($statusLabel); ?>
                                                        </span>
                                                    </td>

                                                    <td data-label="Submitted">
                                                        <div class="table-secondary-text"><?php echo e(safeDate($req['created_at'] ?? '')); ?></div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="panel text-center">
                                    <i class="bi bi-info-circle fs-2 text-primary"></i>
                                    <h6 class="mt-2 fw-bold">Eligibility</h6>
                                    <p class="small text-muted mb-0">Allowed only for dates within the last 30 days.</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="panel text-center">
                                    <i class="bi bi-clock-history fs-2 text-warning"></i>
                                    <h6 class="mt-2 fw-bold">Processing</h6>
                                    <p class="small text-muted mb-0">Requests are usually processed in 2-3 business days.</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="panel text-center">
                                    <i class="bi bi-person-check fs-2 text-success"></i>
                                    <h6 class="mt-2 fw-bold">Approval</h6>
                                    <p class="small text-muted mb-0">Project Engineer → TL, TL → Manager, Manager/HR/QS → Admin.</p>
                                </div>
                            </div>
                        </div>
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
    
    document.addEventListener('DOMContentLoaded', function() {
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
    document.getElementById('regularizationForm')?.addEventListener('submit', function(e) {
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