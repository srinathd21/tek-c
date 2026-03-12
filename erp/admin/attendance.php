<?php
// attendance.php
// ✅ Complete Attendance Management System

session_start();
require_once 'includes/db-config.php';

// Helper functions
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function timeAgo($datetime) {
    if (!$datetime) return 'Not punched';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff/60).' mins ago';
    if ($diff < 86400) return floor($diff/3600).' hours ago';
    return date('d M, h:i A', $time);
}

function formatTime($datetime) {
    return $datetime ? date('h:i A', strtotime($datetime)) : '--:--';
}

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return null;
    
    $earthRadius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + 
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * asin(sqrt($a));
    return $earthRadius * $c;
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error = '';
$today = date('Y-m-d');

// Handle Punch In/Out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Punch In
    if (isset($_POST['punch_in'])) {
        $employee_id = (int)$_POST['employee_id'];
        $punch_lat = (float)$_POST['latitude'];
        $punch_lng = (float)$_POST['longitude'];
        $punch_location = e($_POST['location'] ?? '');
        $punch_type = $_POST['punch_type']; // 'site' or 'office'
        $site_id = isset($_POST['site_id']) ? (int)$_POST['site_id'] : null;
        $office_id = isset($_POST['office_id']) ? (int)$_POST['office_id'] : null;
        
        // Check if already punched in today
        $check = mysqli_query($conn, "SELECT id FROM attendance WHERE employee_id = $employee_id AND attendance_date = '$today'");
        if (mysqli_num_rows($check) > 0) {
            $error = "You have already punched in today.";
        } else {
            // Get employee details for validation
            $emp_res = mysqli_query($conn, "SELECT * FROM employees WHERE id = $employee_id");
            $employee = mysqli_fetch_assoc($emp_res);
            
            // Check if employee can punch at this location
            $can_punch = false;
            $validation_msg = '';
            
            if ($punch_type == 'site') {
                // Check if employee is assigned to this site
                $site_check = mysqli_query($conn, 
                    "SELECT s.* FROM sites s 
                     JOIN site_project_engineers spe ON s.id = spe.site_id 
                     WHERE spe.employee_id = $employee_id AND s.id = $site_id");
                
                if (mysqli_num_rows($site_check) == 0) {
                    $validation_msg = "You are not assigned to this site.";
                } else {
                    $site = mysqli_fetch_assoc($site_check);
                    
                    // Calculate distance
                    $distance = calculateDistance($punch_lat, $punch_lng, $site['latitude'], $site['longitude']);
                    
                    if ($distance === null) {
                        $validation_msg = "Site location not configured.";
                    } elseif ($distance <= ($site['location_radius'] ?? 100)) {
                        $can_punch = true;
                    } else {
                        $validation_msg = "You are " . round($distance) . "m away from site (allowed: " . ($site['location_radius'] ?? 100) . "m)";
                    }
                }
            } elseif ($punch_type == 'office') {
                // Check if employee is allowed to punch from office (Managers/Team Leads)
                $allowed_designations = ['Manager', 'Team Lead', 'Director', 'Vice President', 'General Manager'];
                if (in_array($employee['designation'], $allowed_designations)) {
                    // Check office location
                    $office_res = mysqli_query($conn, "SELECT * FROM office_locations WHERE id = $office_id AND is_active = 1");
                    if (mysqli_num_rows($office_res) > 0) {
                        $office = mysqli_fetch_assoc($office_res);
                        $distance = calculateDistance($punch_lat, $punch_lng, $office['latitude'], $office['longitude']);
                        
                        if ($distance <= ($office['geo_fence_radius'] ?? 100)) {
                            $can_punch = true;
                        } else {
                            $validation_msg = "You are " . round($distance) . "m away from office";
                        }
                    } else {
                        $validation_msg = "Invalid office location.";
                    }
                } else {
                    $validation_msg = "You are not authorized to punch from office.";
                }
            }
            
            if ($can_punch) {
                $stmt = mysqli_prepare($conn, 
                    "INSERT INTO attendance 
                    (employee_id, attendance_date, punch_in_time, punch_in_location, 
                     punch_in_latitude, punch_in_longitude, punch_in_type, 
                     punch_in_site_id, punch_in_office_id, status) 
                    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, 'present')");
                
                mysqli_stmt_bind_param($stmt, "issdsdii", 
                    $employee_id, $today, $punch_location, 
                    $punch_lat, $punch_lng, $punch_type, 
                    $site_id, $office_id);
                
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Punch In successful at " . date('h:i A');
                } else {
                    $error = "Error punching in: " . mysqli_stmt_error($stmt);
                }
                mysqli_stmt_close($stmt);
            } else {
                $error = $validation_msg;
            }
        }
    }
    
    // Punch Out
    elseif (isset($_POST['punch_out'])) {
        $attendance_id = (int)$_POST['attendance_id'];
        $punch_lat = (float)$_POST['latitude'];
        $punch_lng = (float)$_POST['longitude'];
        $punch_location = e($_POST['location'] ?? '');
        
        // Get attendance record
        $att_res = mysqli_query($conn, "SELECT * FROM attendance WHERE id = $attendance_id");
        $attendance = mysqli_fetch_assoc($att_res);
        
        if ($attendance) {
            // Calculate total hours
            $punch_in = strtotime($attendance['punch_in_time']);
            $punch_out = time();
            $total_hours = round(($punch_out - $punch_in) / 3600, 2);
            
            $stmt = mysqli_prepare($conn,
                "UPDATE attendance SET 
                 punch_out_time = NOW(),
                 punch_out_location = ?,
                 punch_out_latitude = ?,
                 punch_out_longitude = ?,
                 total_hours = ?
                 WHERE id = ?");
            
            mysqli_stmt_bind_param($stmt, "sdddi", 
                $punch_location, $punch_lat, $punch_lng, 
                $total_hours, $attendance_id);
            
            if (mysqli_stmt_execute($stmt)) {
                $success = "Punch Out successful. Total hours: " . $total_hours;
            } else {
                $error = "Error punching out: " . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Get selected filters
$filter_employee = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$filter_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Fetch all active employees
$employees = [];
$emp_res = mysqli_query($conn, "SELECT id, full_name, employee_code, designation FROM employees WHERE employee_status = 'active' ORDER BY full_name");
if ($emp_res) {
    $employees = mysqli_fetch_all($emp_res, MYSQLI_ASSOC);
}

// Fetch today's attendance
$today_attendance = [];
$today_query = "SELECT a.*, e.full_name, e.employee_code, e.designation, e.photo,
                       s.project_name as site_name, s.latitude as site_lat, s.longitude as site_lng,
                       o.location_name as office_name
                FROM attendance a
                JOIN employees e ON a.employee_id = e.id
                LEFT JOIN sites s ON a.punch_in_site_id = s.id
                LEFT JOIN office_locations o ON a.punch_in_office_id = o.id
                WHERE a.attendance_date = '$today'
                ORDER BY a.punch_in_time DESC";

$today_res = mysqli_query($conn, $today_query);
if ($today_res) {
    $today_attendance = mysqli_fetch_all($today_res, MYSQLI_ASSOC);
}

// Fetch monthly attendance summary
$summary_query = "SELECT 
                    e.id, e.full_name, e.employee_code, e.designation,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_days,
                    COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late_days,
                    COUNT(CASE WHEN a.status = 'half-day' THEN 1 END) as half_days,
                    COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_days,
                    COUNT(CASE WHEN a.is_vacation = 1 THEN 1 END) as vacation_days,
                    SEC_TO_TIME(SUM(TIME_TO_SEC(a.total_hours) * 3600)) as total_hours
                  FROM employees e
                  LEFT JOIN attendance a ON e.id = a.employee_id 
                    AND MONTH(a.attendance_date) = $filter_month 
                    AND YEAR(a.attendance_date) = $filter_year
                  WHERE e.employee_status = 'active'
                  GROUP BY e.id
                  ORDER BY e.full_name";

if ($filter_employee > 0) {
    $summary_query = "SELECT 
                        e.id, e.full_name, e.employee_code, e.designation,
                        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_days,
                        COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late_days,
                        COUNT(CASE WHEN a.status = 'half-day' THEN 1 END) as half_days,
                        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_days,
                        COUNT(CASE WHEN a.is_vacation = 1 THEN 1 END) as vacation_days,
                        SEC_TO_TIME(SUM(TIME_TO_SEC(a.total_hours) * 3600)) as total_hours
                      FROM employees e
                      LEFT JOIN attendance a ON e.id = a.employee_id 
                        AND MONTH(a.attendance_date) = $filter_month 
                        AND YEAR(a.attendance_date) = $filter_year
                      WHERE e.id = $filter_employee
                      GROUP BY e.id";
}

$summary_res = mysqli_query($conn, $summary_query);
$monthly_summary = mysqli_fetch_all($summary_res, MYSQLI_ASSOC);

// Fetch sites for punch in dropdown
$sites = mysqli_query($conn, "SELECT id, project_name, project_code, latitude, longitude FROM sites WHERE latitude IS NOT NULL");

// Fetch office locations
$offices = mysqli_query($conn, "SELECT id, location_name, address, latitude, longitude FROM office_locations WHERE is_active = 1");

// Get employee's assigned sites for current user (you'd get from session)
$current_employee_id = 6; // Replace with session employee ID
$assigned_sites = [];
$assigned_res = mysqli_query($conn, 
    "SELECT s.* FROM sites s 
     JOIN site_project_engineers spe ON s.id = spe.site_id 
     WHERE spe.employee_id = $current_employee_id");
if ($assigned_res) {
    $assigned_sites = mysqli_fetch_all($assigned_res, MYSQLI_ASSOC);
}

// Check if already punched in today
$punched_today = null;
$punch_check = mysqli_query($conn, "SELECT * FROM attendance WHERE employee_id = $current_employee_id AND attendance_date = '$today'");
if ($punch_check && mysqli_num_rows($punch_check) > 0) {
    $punched_today = mysqli_fetch_assoc($punch_check);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Attendance Management - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px 22px 14px; }

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 20px;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .panel-title {
            font-weight: 900;
            font-size: 18px;
            color: #1f2937;
            margin: 0;
        }

        .punch-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--radius);
            padding: 25px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .punch-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.3; }
        }

        .punch-time {
            font-size: 48px;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 5px;
            position: relative;
        }

        .punch-date {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 20px;
            position: relative;
        }

        .punch-btn {
            background: rgba(255,255,255,0.2);
            border: 2px solid rgba(255,255,255,0.5);
            color: white;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 800;
            font-size: 16px;
            backdrop-filter: blur(5px);
            transition: all 0.3s;
        }

        .punch-btn:hover {
            background: white;
            color: #667eea;
            border-color: white;
        }

        .punch-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 15px;
            height: 100%;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 900;
            color: #1f2937;
            line-height: 1;
        }

        .stat-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .attendance-badge {
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }

        .badge-present { background: rgba(16,185,129,0.1); color: #10b981; }
        .badge-late { background: rgba(245,158,11,0.1); color: #f59e0b; }
        .badge-half { background: rgba(139,92,246,0.1); color: #8b5cf6; }
        .badge-absent { background: rgba(239,68,68,0.1); color: #ef4444; }
        .badge-vacation { background: rgba(59,130,246,0.1); color: #3b82f6; }

        .employee-avatar {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 900;
            font-size: 16px;
        }

        .employee-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 8px;
            object-fit: cover;
        }

        .location-badge {
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            color: #4b5563;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .filter-card {
            background: #f9fafb;
            border-radius: var(--radius);
            padding: 15px;
            border: 1px solid var(--border);
        }

        #calendar {
            max-width: 100%;
            margin: 20px 0;
            background: white;
            border-radius: var(--radius);
            padding: 15px;
        }

        .fc-event {
            cursor: pointer;
            border: none;
            padding: 2px 4px;
        }

        .fc-day-today {
            background: rgba(102, 126, 234, 0.05) !important;
        }

        .modal-location-status {
            padding: 10px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 13px;
        }

        .status-valid { background: #d1fae5; color: #065f46; }
        .status-invalid { background: #fee2e2; color: #991b1b; }
        @media (max-width: 768px) {
  .content-scroll {
    padding: 12px 10px 12px !important;   /* was 22px */
  }

  .container-fluid.maxw {
    padding-left: 6px !important;
    padding-right: 6px !important;
  }

  .panel {
    padding: 12px !important;
    margin-bottom: 12px;
    border-radius: 14px;
  }

  .sec-head {
    padding: 10px !important;
    border-radius: 12px;
  }
}
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid">

                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Attendance Management</h1>
                        <p class="text-muted mb-0">Track employee attendance and working hours</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#attendanceReportModal">
                            <i class="bi bi-download"></i> Export Report
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

                <!-- Quick Punch Card -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="punch-card">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <div class="punch-time" id="currentTime"></div>
                                    <div class="punch-date" id="currentDate"></div>
                                    
                                    <?php if ($punched_today): ?>
                                        <?php if (!$punched_today['punch_out_time']): ?>
                                            <div class="mb-2">
                                                <i class="bi bi-check-circle-fill me-2"></i>
                                                Punched In at <?php echo date('h:i A', strtotime($punched_today['punch_in_time'])); ?>
                                            </div>
                                            <form method="POST" id="punchOutForm">
                                                <input type="hidden" name="punch_out" value="1">
                                                <input type="hidden" name="attendance_id" value="<?php echo $punched_today['id']; ?>">
                                                <input type="hidden" name="latitude" id="punchOutLat">
                                                <input type="hidden" name="longitude" id="punchOutLng">
                                                <input type="hidden" name="location" id="punchOutLocation">
                                                <button type="submit" class="punch-btn" id="punchOutBtn">
                                                    <i class="bi bi-box-arrow-right me-2"></i>Punch Out
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <div class="mb-2">
                                                <i class="bi bi-check-circle-fill me-2"></i>
                                                Completed: <?php echo date('h:i A', strtotime($punched_today['punch_in_time'])); ?> - 
                                                <?php echo date('h:i A', strtotime($punched_today['punch_out_time'])); ?>
                                                (<?php echo $punched_today['total_hours']; ?> hrs)
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="mb-3">Ready to start your work day?</div>
                                        <button class="punch-btn" data-bs-toggle="modal" data-bs-target="#punchInModal">
                                            <i class="bi bi-box-arrow-in-right me-2"></i>Punch In
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <i class="bi bi-fingerprint" style="font-size: 80px; opacity: 0.3;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Attendance Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo count($today_attendance); ?></div>
                            <div class="stat-label">Present Today</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value">
                                <?php 
                                $punched_in = array_filter($today_attendance, function($a) { return !$a['punch_out_time']; });
                                echo count($punched_in);
                                ?>
                            </div>
                            <div class="stat-label">Currently Working</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value">
                                <?php 
                                $on_time = array_filter($today_attendance, function($a) { 
                                    return strtotime($a['punch_in_time']) <= strtotime('09:15:00'); 
                                });
                                echo count($on_time);
                                ?>
                            </div>
                            <div class="stat-label">On Time</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value">
                                <?php 
                                $late = array_filter($today_attendance, function($a) { 
                                    return strtotime($a['punch_in_time']) > strtotime('09:15:00'); 
                                });
                                echo count($late);
                                ?>
                            </div>
                            <div class="stat-label">Late Arrivals</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-card mb-4">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Employee</label>
                            <select class="form-control" name="employee_id">
                                <option value="0">All Employees</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" <?php echo $filter_employee == $emp['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($emp['full_name']); ?> (<?php echo e($emp['employee_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Month</label>
                            <select class="form-control" name="month">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Year</label>
                            <select class="form-control" name="year">
                                <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-filter"></i> Apply
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Monthly Summary Table -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Monthly Attendance Summary</h3>
                        <span class="badge bg-light text-dark">
                            <?php echo date('F Y', mktime(0, 0, 0, $filter_month, 1, $filter_year)); ?>
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="summaryTable">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Present</th>
                                    <th>Late</th>
                                    <th>Half Day</th>
                                    <th>Absent</th>
                                    <th>Vacation</th>
                                    <th>Total Hours</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthly_summary as $summary): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="employee-avatar">
                                                    <?php echo strtoupper(substr($summary['full_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?php echo e($summary['full_name']); ?></div>
                                                    <small class="text-muted"><?php echo e($summary['employee_code']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="attendance-badge badge-present"><?php echo $summary['present_days'] ?? 0; ?></span></td>
                                        <td><span class="attendance-badge badge-late"><?php echo $summary['late_days'] ?? 0; ?></span></td>
                                        <td><span class="attendance-badge badge-half"><?php echo $summary['half_days'] ?? 0; ?></span></td>
                                        <td><span class="attendance-badge badge-absent"><?php echo $summary['absent_days'] ?? 0; ?></span></td>
                                        <td><span class="attendance-badge badge-vacation"><?php echo $summary['vacation_days'] ?? 0; ?></span></td>
                                        <td><strong><?php echo $summary['total_hours'] ? substr($summary['total_hours'], 0, 5) : '0.0'; ?></strong></td>
                                        <td>
                                            <a href="employee-attendance.php?id=<?php echo $summary['id']; ?>" class="btn-action">
                                                <i class="bi bi-calendar-week"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Today's Attendance List -->
                <div class="panel mt-4">
                    <div class="panel-header">
                        <h3 class="panel-title">Today's Attendance</h3>
                        <span class="text-muted"><?php echo date('d M Y'); ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="todayTable">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Punch In</th>
                                    <th>Punch Out</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_attendance as $att): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="employee-avatar">
                                                    <?php echo strtoupper(substr($att['full_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?php echo e($att['full_name']); ?></div>
                                                    <small class="text-muted"><?php echo e($att['designation']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo date('h:i A', strtotime($att['punch_in_time'])); ?></div>
                                            <small class="text-muted"><?php echo timeAgo($att['punch_in_time']); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($att['punch_out_time']): ?>
                                                <div class="fw-bold"><?php echo date('h:i A', strtotime($att['punch_out_time'])); ?></div>
                                                <small class="text-muted"><?php echo $att['total_hours']; ?> hrs</small>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Working</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="location-badge">
                                                <i class="bi bi-geo-alt"></i>
                                                <?php 
                                                if ($att['punch_in_type'] == 'site') echo $att['site_name'] ?? 'Site';
                                                else echo $att['office_name'] ?? 'Office';
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (strtotime($att['punch_in_time']) > strtotime('09:15:00')): ?>
                                                <span class="attendance-badge badge-late">Late</span>
                                            <?php else: ?>
                                                <span class="attendance-badge badge-present">On Time</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($today_attendance)): ?>
                                    
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

<!-- Punch In Modal -->
<div class="modal fade" id="punchInModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Punch In</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="punchInForm">
                <input type="hidden" name="punch_in" value="1">
                <input type="hidden" name="employee_id" value="<?php echo $current_employee_id; ?>">
                <input type="hidden" name="latitude" id="punchLat">
                <input type="hidden" name="longitude" id="punchLng">
                <input type="hidden" name="location" id="punchAddress">
                <input type="hidden" name="punch_type" id="punchType" value="site">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Punch Location Type</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="punch_type_radio" id="punchTypeSite" value="site" checked>
                                <label class="form-check-label" for="punchTypeSite">
                                    <i class="bi bi-building"></i> Site
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="punch_type_radio" id="punchTypeOffice" value="office">
                                <label class="form-check-label" for="punchTypeOffice">
                                    <i class="bi bi-briefcase"></i> Office
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3" id="siteSelectDiv">
                        <label class="form-label">Select Site</label>
                        <select class="form-control" name="site_id" id="siteSelect">
                            <option value="">Select your site</option>
                            <?php foreach ($assigned_sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>" 
                                        data-lat="<?php echo $site['latitude']; ?>"
                                        data-lng="<?php echo $site['longitude']; ?>"
                                        data-radius="<?php echo $site['location_radius']; ?>">
                                    <?php echo e($site['project_name']); ?> (<?php echo e($site['project_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" id="officeSelectDiv" style="display: none;">
                        <label class="form-label">Select Office</label>
                        <select class="form-control" name="office_id" id="officeSelect">
                            <option value="">Select office location</option>
                            <?php while ($office = mysqli_fetch_assoc($offices)): ?>
                                <option value="<?php echo $office['id']; ?>" 
                                        data-lat="<?php echo $office['latitude']; ?>"
                                        data-lng="<?php echo $office['longitude']; ?>">
                                    <?php echo e($office['location_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div id="locationStatus" class="modal-location-status">
                        <i class="bi bi-geo-alt-fill me-2"></i>
                        Getting your location...
                    </div>

                    <div class="mt-3 text-muted small">
                        <i class="bi bi-info-circle"></i>
                        Make sure your location services are enabled
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="punchInSubmit" disabled>
                        <i class="bi bi-box-arrow-in-right"></i> Confirm Punch In
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Export Report Modal -->
<div class="modal fade" id="attendanceReportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Export Attendance Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="export-attendance.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Report Type</label>
                        <select class="form-control" name="report_type" required>
                            <option value="daily">Daily Report</option>
                            <option value="monthly">Monthly Summary</option>
                            <option value="employee">Employee Wise Report</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Format</label>
                        <select class="form-control" name="format" required>
                            <option value="excel">Excel (CSV)</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="from_date" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="to_date" value="<?php echo date('Y-m-t'); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
// Update current time
function updateTime() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit',
        hour12: true 
    });
    const dateStr = now.toLocaleDateString('en-US', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    
    document.getElementById('currentTime').textContent = timeStr;
    document.getElementById('currentDate').textContent = dateStr;
}

setInterval(updateTime, 1000);
updateTime();

// DataTables initialization
$(document).ready(function() {
    $('#summaryTable').DataTable({
        pageLength: 10,
        order: [[0, 'asc']],
        language: { searchPlaceholder: "Search employees..." }
    });
    
    $('#todayTable').DataTable({
        pageLength: 10,
        order: [[1, 'desc']],
        language: { searchPlaceholder: "Search..." }
    });
});

// Punch In location handling
document.addEventListener('DOMContentLoaded', function() {
    const punchTypeSite = document.getElementById('punchTypeSite');
    const punchTypeOffice = document.getElementById('punchTypeOffice');
    const siteSelectDiv = document.getElementById('siteSelectDiv');
    const officeSelectDiv = document.getElementById('officeSelectDiv');
    const siteSelect = document.getElementById('siteSelect');
    const officeSelect = document.getElementById('officeSelect');
    const locationStatus = document.getElementById('locationStatus');
    const punchInSubmit = document.getElementById('punchInSubmit');
    const punchType = document.getElementById('punchType');
    
    let currentLat = null;
    let currentLng = null;
    let currentAddress = '';
    
    // Toggle between site and office
    punchTypeSite.addEventListener('change', function() {
        siteSelectDiv.style.display = 'block';
        officeSelectDiv.style.display = 'none';
        punchType.value = 'site';
        validateLocation();
    });
    
    punchTypeOffice.addEventListener('change', function() {
        siteSelectDiv.style.display = 'none';
        officeSelectDiv.style.display = 'block';
        punchType.value = 'office';
        validateLocation();
    });
    
    // Get user's location
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                currentLat = position.coords.latitude;
                currentLng = position.coords.longitude;
                
                document.getElementById('punchLat').value = currentLat;
                document.getElementById('punchLng').value = currentLng;
                
                // Get address from coordinates (reverse geocoding)
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${currentLat}&lon=${currentLng}`)
                    .then(response => response.json())
                    .then(data => {
                        currentAddress = data.display_name || 'Location detected';
                        document.getElementById('punchAddress').value = currentAddress;
                        locationStatus.innerHTML = '<i class="bi bi-check-circle-fill text-success me-2"></i> Location detected: ' + currentAddress.substring(0, 100) + '...';
                        validateLocation();
                    })
                    .catch(() => {
                        currentAddress = `Lat: ${currentLat.toFixed(6)}, Lng: ${currentLng.toFixed(6)}`;
                        document.getElementById('punchAddress').value = currentAddress;
                        locationStatus.innerHTML = '<i class="bi bi-check-circle-fill text-success me-2"></i> Location detected';
                        validateLocation();
                    });
            },
            function(error) {
                let errorMsg = 'Unable to get your location. ';
                switch(error.code) {
                    case error.PERMISSION_DENIED:
                        errorMsg += 'Please enable location services.';
                        break;
                    case error.POSITION_UNAVAILABLE:
                        errorMsg += 'Location information unavailable.';
                        break;
                    case error.TIMEOUT:
                        errorMsg += 'Location request timed out.';
                        break;
                }
                locationStatus.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-danger me-2"></i> ' + errorMsg;
                locationStatus.className = 'modal-location-status status-invalid';
            }
        );
    } else {
        locationStatus.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-danger me-2"></i> Geolocation is not supported by your browser';
        locationStatus.className = 'modal-location-status status-invalid';
    }
    
    // Validate location against selected site/office
    function validateLocation() {
        if (!currentLat || !currentLng) {
            punchInSubmit.disabled = true;
            return;
        }
        
        if (punchTypeSite.checked) {
            const selectedOption = siteSelect.options[siteSelect.selectedIndex];
            if (!selectedOption.value) {
                locationStatus.innerHTML = '<i class="bi bi-info-circle-fill text-warning me-2"></i> Please select a site';
                locationStatus.className = 'modal-location-status';
                punchInSubmit.disabled = true;
                return;
            }
            
            const siteLat = parseFloat(selectedOption.dataset.lat);
            const siteLng = parseFloat(selectedOption.dataset.lng);
            const radius = parseInt(selectedOption.dataset.radius) || 100;
            
            if (!siteLat || !siteLng) {
                locationStatus.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-danger me-2"></i> Site location not configured';
                locationStatus.className = 'modal-location-status status-invalid';
                punchInSubmit.disabled = true;
                return;
            }
            
            // Calculate distance (simplified)
            const distance = calculateDistance(currentLat, currentLng, siteLat, siteLng);
            
            if (distance <= radius) {
                locationStatus.innerHTML = `<i class="bi bi-check-circle-fill text-success me-2"></i> You are within ${Math.round(distance)}m of the site (allowed: ${radius}m)`;
                locationStatus.className = 'modal-location-status status-valid';
                punchInSubmit.disabled = false;
            } else {
                locationStatus.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-danger me-2"></i> You are ${Math.round(distance)}m away from site (allowed: ${radius}m)`;
                locationStatus.className = 'modal-location-status status-invalid';
                punchInSubmit.disabled = true;
            }
        } else {
            const selectedOption = officeSelect.options[officeSelect.selectedIndex];
            if (!selectedOption.value) {
                locationStatus.innerHTML = '<i class="bi bi-info-circle-fill text-warning me-2"></i> Please select an office';
                locationStatus.className = 'modal-location-status';
                punchInSubmit.disabled = true;
                return;
            }
            
            const officeLat = parseFloat(selectedOption.dataset.lat);
            const officeLng = parseFloat(selectedOption.dataset.lng);
            
            const distance = calculateDistance(currentLat, currentLng, officeLat, officeLng);
            
            if (distance <= 100) {
                locationStatus.innerHTML = `<i class="bi bi-check-circle-fill text-success me-2"></i> You are within ${Math.round(distance)}m of the office`;
                locationStatus.className = 'modal-location-status status-valid';
                punchInSubmit.disabled = false;
            } else {
                locationStatus.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-danger me-2"></i> You are ${Math.round(distance)}m away from office`;
                locationStatus.className = 'modal-location-status status-invalid';
                punchInSubmit.disabled = true;
            }
        }
    }
    
    // Calculate distance between two coordinates
    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371e3; // metres
        const φ1 = lat1 * Math.PI/180;
        const φ2 = lat2 * Math.PI/180;
        const Δφ = (lat2-lat1) * Math.PI/180;
        const Δλ = (lon2-lon1) * Math.PI/180;

        const a = Math.sin(Δφ/2) * Math.sin(Δφ/2) +
                Math.cos(φ1) * Math.cos(φ2) *
                Math.sin(Δλ/2) * Math.sin(Δλ/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));

        return R * c;
    }
    
    siteSelect.addEventListener('change', validateLocation);
    officeSelect.addEventListener('change', validateLocation);
});

// Punch Out location handling
document.getElementById('punchOutForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                document.getElementById('punchOutLat').value = position.coords.latitude;
                document.getElementById('punchOutLng').value = position.coords.longitude;
                
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${position.coords.latitude}&lon=${position.coords.longitude}`)
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('punchOutLocation').value = data.display_name || 'Location detected';
                        document.getElementById('punchOutForm').submit();
                    })
                    .catch(() => {
                        document.getElementById('punchOutLocation').value = `Lat: ${position.coords.latitude.toFixed(6)}, Lng: ${position.coords.longitude.toFixed(6)}`;
                        document.getElementById('punchOutForm').submit();
                    });
            },
            function() {
                alert('Unable to get your location. Please enable location services.');
            }
        );
    } else {
        alert('Geolocation is not supported by your browser');
    }
});
</script>

<style>
.btn-action {
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 5px 8px;
    color: var(--muted);
    font-size: 12px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-action:hover {
    background: var(--bg);
    color: var(--blue);
}

.dataTables_wrapper .row {
    margin: 0;
}

.dataTables_filter input {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 12px;
}

.table thead th {
    background: #f8fafc;
    font-weight: 800;
    font-size: 12px;
    color: #475569;
}
</style>
</body>
</html>