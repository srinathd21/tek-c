<?php
// hr/attendance-details.php - View Attendance Record Details
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];
$is_admin = false;

// Get current employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$current_employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die("Employee not found.");
}

// Check if admin (for potential admin view)
$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department = strtolower(trim($current_employee['department'] ?? ''));
$is_admin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director' || $designation === 'hr');

// ---------------- GET ATTENDANCE ID ----------------
$attendance_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attendance_id <= 0) {
    $_SESSION['flash_error'] = "Invalid attendance ID.";
    header("Location: my-attendance.php");
    exit;
}

// ---------------- FETCH ATTENDANCE DETAILS ----------------
$query = "
    SELECT a.*, 
           e.full_name as employee_name,
           e.employee_code,
           e.designation as employee_designation,
           e.department as employee_department,
           e.photo as employee_photo
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.id = ?
";

// Security: Regular users can only view their own attendance
if (!$is_admin) {
    $query .= " AND a.employee_id = " . $current_employee_id;
}

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $attendance_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$attendance = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$attendance) {
    $_SESSION['flash_error'] = "Attendance record not found or you don't have permission to view it.";
    header("Location: my-attendance.php");
    exit;
}

// ---------------- FETCH SITE DETAILS IF APPLICABLE ----------------
$site = null;
if ($attendance['punch_in_site_id']) {
    $site_stmt = mysqli_prepare($conn, "SELECT * FROM sites WHERE id = ?");
    mysqli_stmt_bind_param($site_stmt, "i", $attendance['punch_in_site_id']);
    mysqli_stmt_execute($site_stmt);
    $site_result = mysqli_stmt_get_result($site_stmt);
    $site = mysqli_fetch_assoc($site_result);
    mysqli_stmt_close($site_stmt);
}

// ---------------- FETCH OFFICE DETAILS IF APPLICABLE ----------------
$office = null;
if ($attendance['punch_in_office_id']) {
    $office_stmt = mysqli_prepare($conn, "SELECT * FROM office_locations WHERE id = ?");
    mysqli_stmt_bind_param($office_stmt, "i", $attendance['punch_in_office_id']);
    mysqli_stmt_execute($office_stmt);
    $office_result = mysqli_stmt_get_result($office_stmt);
    $office = mysqli_fetch_assoc($office_result);
    mysqli_stmt_close($office_stmt);
}

// ---------------- FETCH PUNCH OUT LOCATION DETAILS ----------------
$punch_out_site = null;
$punch_out_office = null;

if ($attendance['punch_out_site_id']) {
    $site_stmt = mysqli_prepare($conn, "SELECT * FROM sites WHERE id = ?");
    mysqli_stmt_bind_param($site_stmt, "i", $attendance['punch_out_site_id']);
    mysqli_stmt_execute($site_stmt);
    $site_result = mysqli_stmt_get_result($site_stmt);
    $punch_out_site = mysqli_fetch_assoc($site_result);
    mysqli_stmt_close($site_stmt);
}

if ($attendance['punch_out_office_id']) {
    $office_stmt = mysqli_prepare($conn, "SELECT * FROM office_locations WHERE id = ?");
    mysqli_stmt_bind_param($office_stmt, "i", $attendance['punch_out_office_id']);
    mysqli_stmt_execute($office_stmt);
    $office_result = mysqli_stmt_get_result($office_stmt);
    $punch_out_office = mysqli_fetch_assoc($office_result);
    mysqli_stmt_close($office_stmt);
}

// ---------------- FETCH ACTIVITY LOGS FOR THIS ATTENDANCE ----------------
$log_query = "
    SELECT * FROM activity_logs 
    WHERE module = 'attendance' AND module_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
";
$log_stmt = mysqli_prepare($conn, $log_query);
mysqli_stmt_bind_param($log_stmt, "i", $attendance_id);
mysqli_stmt_execute($log_stmt);
$log_result = mysqli_stmt_get_result($log_stmt);
$activity_logs = [];
while ($row = mysqli_fetch_assoc($log_result)) {
    $activity_logs[] = $row;
}
mysqli_stmt_close($log_stmt);

// ---------------- FETCH ADJACENT RECORDS (PREV/NEXT) ----------------
$adjacent_query = "
    SELECT id, attendance_date 
    FROM attendance 
    WHERE employee_id = ? 
    ORDER BY attendance_date DESC
";
$adjacent_stmt = mysqli_prepare($conn, $adjacent_query);
mysqli_stmt_bind_param($adjacent_stmt, "i", $attendance['employee_id']);
mysqli_stmt_execute($adjacent_stmt);
$adjacent_result = mysqli_stmt_get_result($adjacent_stmt);

$prev_id = null;
$next_id = null;
$found_current = false;
$all_records = [];

while ($row = mysqli_fetch_assoc($adjacent_result)) {
    $all_records[] = $row;
}

for ($i = 0; $i < count($all_records); $i++) {
    if ($all_records[$i]['id'] == $attendance_id) {
        if ($i > 0) $prev_id = $all_records[$i - 1]['id'];
        if ($i < count($all_records) - 1) $next_id = $all_records[$i + 1]['id'];
        break;
    }
}
mysqli_stmt_close($adjacent_stmt);

// ---------------- GOOGLE MAPS API KEY ----------------
$google_maps_api_key = 'AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE'; // Move to config file

// ---------------- HELPERS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateTime($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function formatTime($v, $dash='—'){
    if (!$v || $v === '0000-00-00 00:00:00') return $dash;
    return date('h:i A', strtotime($v));
}

function getStatusBadge($status) {
    switch($status) {
        case 'present':
            return '<span class="badge bg-success px-3 py-2"><i class="bi bi-check-circle"></i> Present</span>';
        case 'absent':
            return '<span class="badge bg-danger px-3 py-2"><i class="bi bi-x-circle"></i> Absent</span>';
        case 'half-day':
            return '<span class="badge bg-warning text-dark px-3 py-2"><i class="bi bi-clock"></i> Half Day</span>';
        case 'late':
            return '<span class="badge bg-info px-3 py-2"><i class="bi bi-exclamation-triangle"></i> Late</span>';
        case 'holiday':
            return '<span class="badge bg-primary px-3 py-2"><i class="bi bi-calendar-heart"></i> Holiday</span>';
        case 'leave':
            return '<span class="badge bg-secondary px-3 py-2"><i class="bi bi-calendar-x"></i> Leave</span>';
        default:
            return '<span class="badge bg-light text-dark px-3 py-2">' . e($status) . '</span>';
    }
}

function getPunchTypeIcon($type) {
    switch($type) {
        case 'site':
            return '<span class="badge bg-primary bg-opacity-10 text-primary"><i class="bi bi-building"></i> Site</span>';
        case 'office':
            return '<span class="badge bg-success bg-opacity-10 text-success"><i class="bi bi-briefcase"></i> Office</span>';
        case 'remote':
            return '<span class="badge bg-warning bg-opacity-10 text-warning"><i class="bi bi-house-door"></i> Remote</span>';
        default:
            return '<span class="badge bg-secondary bg-opacity-10 text-secondary">—</span>';
    }
}

function timeToDecimal($time) {
    if (!$time) return 0;
    $parts = explode(':', $time);
    return round($parts[0] + ($parts[1]/60), 2);
}

$page_title = "Attendance Details - " . safeDate($attendance['attendance_date']);
$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= $page_title ?> - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($google_maps_api_key) ?>&libraries=places,geometry"></script>

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px; }
        .panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 8px 24px rgba(17,24,39,.06); padding:20px; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }

        .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
            padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
        .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
        .stat-ic.blue{ background: var(--blue); }
        .stat-ic.green{ background: var(--green); }
        .stat-ic.orange{ background: var(--orange); }
        .stat-ic.purple{ background: #8e44ad; }
        .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
        .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

        .info-row{ display:flex; padding:12px 0; border-bottom:1px solid #e5e7eb; }
        .info-label{ width:180px; font-weight:800; color:#6b7280; }
        .info-value{ flex:1; font-weight:700; color:#1f2937; }

        .map-container{ height:250px; border-radius:12px; border:1px solid #e5e7eb; margin:15px 0; }

        .employee-photo{ width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid #fff; box-shadow:0 4px 12px rgba(0,0,0,0.1); }

        .timeline{ position:relative; padding:20px 0; }
        .timeline-item{ display:flex; gap:20px; margin-bottom:30px; position:relative; }
        .timeline-time{ min-width:120px; font-weight:800; color:#3b82f6; }
        .timeline-content{ flex:1; background:#f9fafb; border-radius:12px; padding:16px; border:1px solid #e5e7eb; }
        .timeline-icon{ width:40px; height:40px; border-radius:50%; background:#fff; border:2px solid #3b82f6; display:flex; align-items:center; justify-content:center; color:#3b82f6; }

        .badge-hours{ background:#e8f5e9; color:#2e7d32; padding:8px 16px; border-radius:30px; font-weight:800; font-size:24px; }
        .badge-hours small{ font-size:14px; font-weight:400; }

        .coord-badge{ background:#eef2ff; color:#3b82f6; padding:6px 12px; border-radius:30px; font-weight:600; font-size:13px; display:inline-flex; align-items:center; gap:6px; }

        .nav-btn{ width:40px; height:40px; border-radius:10px; border:1px solid #e5e7eb; display:inline-flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; }
        .nav-btn:hover{ background:#f3f4f6; color:#374151; }

        @media (max-width: 768px) {
            .content-scroll{ padding:12px; }
            .info-row{ flex-direction:column; }
            .info-label{ width:100%; margin-bottom:5px; }
            .timeline-item{ flex-direction:column; gap:10px; }
            .timeline-time{ min-width:auto; }
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content-scroll">
            <div class="container-fluid maxw">

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?= $is_admin ? 'my-attendance.php' : 'my-attendance.php' ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <div>
                            <h1 class="h3 fw-bold mb-1">
                                Attendance Details
                                <span class="badge bg-secondary ms-2">ID: #<?= $attendance_id ?></span>
                            </h1>
                            <p class="text-muted mb-0"><?= safeDate($attendance['attendance_date']) ?> • <?= date('l', strtotime($attendance['attendance_date'])) ?></p>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($prev_id): ?>
                            <a href="?id=<?= $prev_id ?>" class="nav-btn" title="Previous Record">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        <?php if ($next_id): ?>
                            <a href="?id=<?= $next_id ?>" class="nav-btn" title="Next Record">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Flash Messages -->
                <?php if (isset($_SESSION['flash_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                        <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Status Banner -->
                <div class="alert alert-<?= $attendance['status'] === 'present' ? 'success' : ($attendance['status'] === 'absent' ? 'danger' : 'info') ?> d-flex align-items-center mb-3">
                    <div class="me-3 fs-2">
                        <i class="bi bi-<?= $attendance['status'] === 'present' ? 'check-circle-fill' : ($attendance['status'] === 'absent' ? 'x-circle-fill' : 'info-circle-fill') ?>"></i>
                    </div>
                    <div>
                        <strong>Status: <?= ucfirst($attendance['status']) ?></strong>
                        <?php if ($attendance['late_minutes'] > 0): ?>
                            <br><small>Late by <?= $attendance['late_minutes'] ?> minutes</small>
                        <?php endif; ?>
                        <?php if ($attendance['early_exit_minutes'] > 0): ?>
                            <br><small>Early exit by <?= $attendance['early_exit_minutes'] ?> minutes</small>
                        <?php endif; ?>
                        <?php if ($attendance['overtime_minutes'] > 0): ?>
                            <br><small>Overtime: <?= $attendance['overtime_minutes'] ?> minutes</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Left Column - Employee & Basic Info -->
                    <div class="col-lg-4">
                        <!-- Employee Card -->
                        <div class="panel text-center mb-4">
                            <?php if ($attendance['employee_photo']): ?>
                                <img src="<?= e($attendance['employee_photo']) ?>" class="employee-photo mb-3" alt="Employee Photo">
                            <?php else: ?>
                                <div class="employee-photo bg-light d-flex align-items-center justify-content-center mx-auto mb-3">
                                    <i class="bi bi-person-fill text-secondary fs-1"></i>
                                </div>
                            <?php endif; ?>
                            <h5 class="fw-bold mb-1"><?= e($attendance['employee_name']) ?></h5>
                            <p class="text-muted mb-2"><?= e($attendance['employee_code']) ?></p>
                            <div class="d-flex justify-content-center gap-2 mb-3">
                                <span class="badge bg-light text-dark"><?= e($attendance['employee_designation'] ?? 'N/A') ?></span>
                                <span class="badge bg-light text-dark"><?= e($attendance['employee_department'] ?? 'N/A') ?></span>
                            </div>
                            <?= getStatusBadge($attendance['status']) ?>
                        </div>

                        <!-- Quick Stats -->
                        <div class="panel">
                            <h6 class="fw-bold mb-3"><i class="bi bi-speedometer2 me-2"></i>Quick Stats</h6>
                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Total Hours</div>
                                        <div class="fw-bold fs-3 <?= $attendance['total_hours'] >= 8 ? 'text-success' : 'text-warning' ?>">
                                            <?= number_format($attendance['total_hours'] ?? 0, 2) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Punch Type</div>
                                        <div class="mt-2"><?= getPunchTypeIcon($attendance['punch_in_type']) ?></div>
                                    </div>
                                </div>
                                <?php if ($attendance['late_minutes'] > 0): ?>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center bg-danger bg-opacity-10">
                                        <div class="text-muted small">Late By</div>
                                        <div class="fw-bold text-danger"><?= $attendance['late_minutes'] ?> min</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($attendance['overtime_minutes'] > 0): ?>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center bg-success bg-opacity-10">
                                        <div class="text-muted small">Overtime</div>
                                        <div class="fw-bold text-success"><?= $attendance['overtime_minutes'] ?> min</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Timeline & Details -->
                    <div class="col-lg-8">
                        <!-- Timeline -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-clock-history me-2"></i>Attendance Timeline
                                </h5>
                                <span class="badge-hours">
                                    <?= number_format($attendance['total_hours'] ?? 0, 1) ?><small>hrs</small>
                                </span>
                            </div>

                            <div class="timeline">
                                <!-- Punch In -->
                                <div class="timeline-item">
                                    <div class="timeline-time">
                                        <div class="fw-bold"><?= formatTime($attendance['punch_in_time']) ?></div>
                                        <small class="text-muted">Punch In</small>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <span class="badge bg-primary">IN</span>
                                            <?= getPunchTypeIcon($attendance['punch_in_type']) ?>
                                        </div>
                                        <p class="mb-1"><strong>Location:</strong> <?= e($attendance['punch_in_location'] ?? 'N/A') ?></p>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <span class="coord-badge">
                                                <i class="bi bi-geo-alt"></i> <?= number_format((float)$attendance['punch_in_latitude'], 6) ?>, <?= number_format((float)$attendance['punch_in_longitude'], 6) ?>
                                            </span>
                                            <?php if ($site): ?>
                                                <span class="coord-badge bg-primary bg-opacity-10 text-primary">
                                                    <i class="bi bi-building"></i> <?= e($site['project_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($office): ?>
                                                <span class="coord-badge bg-success bg-opacity-10 text-success">
                                                    <i class="bi bi-briefcase"></i> <?= e($office['location_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Punch Out -->
                                <?php if ($attendance['punch_out_time']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-time">
                                        <div class="fw-bold"><?= formatTime($attendance['punch_out_time']) ?></div>
                                        <small class="text-muted">Punch Out</small>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <span class="badge bg-warning text-dark">OUT</span>
                                            <?= getPunchTypeIcon($attendance['punch_out_type']) ?>
                                        </div>
                                        <p class="mb-1"><strong>Location:</strong> <?= e($attendance['punch_out_location'] ?? 'N/A') ?></p>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <span class="coord-badge">
                                                <i class="bi bi-geo-alt"></i> <?= number_format((float)$attendance['punch_out_latitude'], 6) ?>, <?= number_format((float)$attendance['punch_out_longitude'], 6) ?>
                                            </span>
                                            <?php if ($punch_out_site): ?>
                                                <span class="coord-badge bg-primary bg-opacity-10 text-primary">
                                                    <i class="bi bi-building"></i> <?= e($punch_out_site['project_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($punch_out_office): ?>
                                                <span class="coord-badge bg-success bg-opacity-10 text-success">
                                                    <i class="bi bi-briefcase"></i> <?= e($punch_out_office['location_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Location Map -->
                        <?php if ($attendance['punch_in_latitude'] && $attendance['punch_in_longitude']): ?>
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-map me-2"></i>Location Map
                                </h5>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="toggleMapMarkers('in')">Show Punch In</button>
                                    <?php if ($attendance['punch_out_latitude'] && $attendance['punch_out_longitude']): ?>
                                    <button class="btn btn-outline-warning" onclick="toggleMapMarkers('out')">Show Punch Out</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div id="map" class="map-container"></div>
                        </div>
                        <?php endif; ?>

                        <!-- Detailed Information -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-info-circle me-2"></i>Detailed Information
                                </h5>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Attendance Date:</div>
                                        <div class="info-value"><?= safeDate($attendance['attendance_date']) ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Day of Week:</div>
                                        <div class="info-value"><?= date('l', strtotime($attendance['attendance_date'])) ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Punch In Time:</div>
                                        <div class="info-value"><?= formatTime($attendance['punch_in_time']) ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Punch Out Time:</div>
                                        <div class="info-value"><?= formatTime($attendance['punch_out_time']) ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Total Hours:</div>
                                        <div class="info-value fw-bold text-primary"><?= number_format($attendance['total_hours'] ?? 0, 2) ?> hours</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Punch In Type:</div>
                                        <div class="info-value"><?= getPunchTypeIcon($attendance['punch_in_type']) ?> <?= ucfirst($attendance['punch_in_type'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Punch Out Type:</div>
                                        <div class="info-value"><?= getPunchTypeIcon($attendance['punch_out_type']) ?> <?= ucfirst($attendance['punch_out_type'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Record Created:</div>
                                        <div class="info-value"><?= safeDateTime($attendance['created_at']) ?></div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($attendance['remarks']): ?>
                            <div class="info-row">
                                <div class="info-label">Remarks:</div>
                                <div class="info-value"><?= nl2br(e($attendance['remarks'])) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($attendance['is_vacation']): ?>
                            <div class="alert alert-info mt-3">
                                <i class="bi bi-umbrella"></i>
                                <strong>Vacation Day</strong>
                                <?php if ($attendance['vacation_reason']): ?>
                                    <p class="mb-0 mt-1">Reason: <?= e($attendance['vacation_reason']) ?></p>
                                <?php endif; ?>
                                <?php if ($attendance['vacation_approved_by']): ?>
                                    <small class="d-block mt-1">Approved on <?= safeDateTime($attendance['vacation_approved_at']) ?></small>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Activity Logs -->
                        <?php if (!empty($activity_logs)): ?>
                        <div class="panel mt-4">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-activity me-2"></i>Recent Activity
                                </h5>
                            </div>
                            <div class="list-group list-group-flush">
                                <?php foreach ($activity_logs as $log): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge bg-light text-dark me-2"><?= e($log['action_type']) ?></span>
                                                <span><?= e($log['description']) ?></span>
                                            </div>
                                            <small class="text-muted"><?= safeDateTime($log['created_at']) ?></small>
                                        </div>
                                        <?php if ($log['user_name']): ?>
                                            <small class="text-muted d-block mt-1">
                                                <i class="bi bi-person"></i> <?= e($log['user_name']) ?> (<?= e($log['user_role'] ?? 'N/A') ?>)
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
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
let map;
let markers = [];
let circles = [];

function initMap() {
    const punchInLat = <?= (float)($attendance['punch_in_latitude'] ?? 0) ?>;
    const punchInLng = <?= (float)($attendance['punch_in_longitude'] ?? 0) ?>;
    const punchOutLat = <?= (float)($attendance['punch_out_latitude'] ?? 0) ?>;
    const punchOutLng = <?= (float)($attendance['punch_out_longitude'] ?? 0) ?>;

    if (!punchInLat && !punchInLng) return;

    const center = { lat: punchInLat, lng: punchInLng };
    
    map = new google.maps.Map(document.getElementById('map'), {
        center: center,
        zoom: 15,
        mapTypeId: google.maps.MapTypeId.ROADMAP
    });

    // Add punch in marker
    if (punchInLat && punchInLng) {
        const inMarker = new google.maps.Marker({
            position: { lat: punchInLat, lng: punchInLng },
            map: map,
            title: 'Punch In Location',
            icon: {
                url: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png',
                scaledSize: new google.maps.Size(40, 40)
            }
        });
        markers.push(inMarker);

        const inInfo = new google.maps.InfoWindow({
            content: `
                <div style="padding:8px;">
                    <strong>Punch In</strong><br>
                    Time: <?= formatTime($attendance['punch_in_time']) ?><br>
                    Location: <?= e($attendance['punch_in_location'] ?? 'N/A') ?>
                </div>
            `
        });
        inMarker.addListener('click', () => inInfo.open(map, inMarker));
    }

    // Add punch out marker
    if (punchOutLat && punchOutLng) {
        const outMarker = new google.maps.Marker({
            position: { lat: punchOutLat, lng: punchOutLng },
            map: map,
            title: 'Punch Out Location',
            icon: {
                url: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png',
                scaledSize: new google.maps.Size(40, 40)
            }
        });
        markers.push(outMarker);

        const outInfo = new google.maps.InfoWindow({
            content: `
                <div style="padding:8px;">
                    <strong>Punch Out</strong><br>
                    Time: <?= formatTime($attendance['punch_out_time']) ?><br>
                    Location: <?= e($attendance['punch_out_location'] ?? 'N/A') ?>
                </div>
            `
        });
        outMarker.addListener('click', () => outInfo.open(map, outMarker));
    }

    // Draw radius circles if site/office data exists
    <?php if ($site && $site['latitude'] && $site['longitude']): ?>
    const siteCircle = new google.maps.Circle({
        map: map,
        center: { lat: <?= (float)$site['latitude'] ?>, lng: <?= (float)$site['longitude'] ?> },
        radius: <?= (int)($site['location_radius'] ?? 100) ?>,
        fillColor: '#3b82f6',
        fillOpacity: 0.1,
        strokeColor: '#3b82f6',
        strokeOpacity: 0.5,
        strokeWeight: 2
    });
    circles.push(siteCircle);
    <?php endif; ?>

    <?php if ($office && $office['latitude'] && $office['longitude']): ?>
    const officeCircle = new google.maps.Circle({
        map: map,
        center: { lat: <?= (float)$office['latitude'] ?>, lng: <?= (float)$office['longitude'] ?> },
        radius: <?= (int)($office['geo_fence_radius'] ?? 100) ?>,
        fillColor: '#10b981',
        fillOpacity: 0.1,
        strokeColor: '#10b981',
        strokeOpacity: 0.5,
        strokeWeight: 2
    });
    circles.push(officeCircle);
    <?php endif; ?>

    // Fit bounds to show all markers
    const bounds = new google.maps.LatLngBounds();
    markers.forEach(marker => bounds.extend(marker.getPosition()));
    if (markers.length > 0) {
        map.fitBounds(bounds);
    }
}

function toggleMapMarkers(type) {
    if (!map) return;
    
    markers.forEach((marker, index) => {
        if (type === 'in' && index === 0) {
            map.setCenter(marker.getPosition());
            map.setZoom(18);
        } else if (type === 'out' && index === 1) {
            map.setCenter(marker.getPosition());
            map.setZoom(18);
        }
    });
}

// Initialize map when API loads
window.initMap = initMap;
</script>

<!-- Load Google Maps API with callback -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($google_maps_api_key) ?>&libraries=places,geometry&callback=initMap" async defer></script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>