<?php
// hr/office-details.php - View Office Location Details
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH (HR/Admin) ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$designation = trim((string)($_SESSION['designation'] ?? ''));
$department  = trim((string)($_SESSION['department'] ?? ''));

$isHrOrAdmin = (strtolower($designation) === 'hr' || 
                strtolower($department) === 'hr' || 
                strtolower($designation) === 'director' || 
                strtolower($designation) === 'admin');

if (!$isHrOrAdmin) {
    $fallback = $_SESSION['role_redirect'] ?? '../dashboard.php';
    header("Location: " . $fallback);
    exit;
}

// ---------------- GET OFFICE ID ----------------
$officeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($officeId <= 0) {
    $_SESSION['flash_error'] = "Invalid office ID.";
    header("Location: manage-offices.php");
    exit;
}

// ---------------- GOOGLE MAPS API KEY ----------------
$google_maps_api_key = 'AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE'; // Move to config file

// ---------------- FETCH OFFICE DETAILS ----------------
$query = "SELECT * FROM office_locations WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $officeId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$office = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$office) {
    $_SESSION['flash_error'] = "Office not found.";
    header("Location: manage-offices.php");
    exit;
}

// ---------------- FETCH RECENT ATTENDANCE USING THIS OFFICE ----------------
$attendanceQuery = "
    SELECT 
        a.*,
        e.full_name as employee_name,
        e.employee_code
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    WHERE (a.punch_in_office_id = ? OR a.punch_out_office_id = ?)
    ORDER BY a.attendance_date DESC, a.punch_in_time DESC
    LIMIT 10
";
$attStmt = mysqli_prepare($conn, $attendanceQuery);
mysqli_stmt_bind_param($attStmt, "ii", $officeId, $officeId);
mysqli_stmt_execute($attStmt);
$attResult = mysqli_stmt_get_result($attStmt);
$recentAttendance = [];
while ($row = mysqli_fetch_assoc($attResult)) {
    $recentAttendance[] = $row;
}
mysqli_stmt_close($attStmt);

// ---------------- FETCH ATTENDANCE STATISTICS ----------------
$statsQuery = "
    SELECT 
        COUNT(DISTINCT a.employee_id) as unique_employees,
        COUNT(*) as total_punches,
        SUM(CASE WHEN a.punch_in_office_id = ? THEN 1 ELSE 0 END) as punch_ins,
        SUM(CASE WHEN a.punch_out_office_id = ? THEN 1 ELSE 0 END) as punch_outs,
        MIN(a.attendance_date) as first_use,
        MAX(a.attendance_date) as last_use
    FROM attendance a
    WHERE a.punch_in_office_id = ? OR a.punch_out_office_id = ?
";
$statsStmt = mysqli_prepare($conn, $statsQuery);
mysqli_stmt_bind_param($statsStmt, "iiii", $officeId, $officeId, $officeId, $officeId);
mysqli_stmt_execute($statsStmt);
$statsResult = mysqli_stmt_get_result($statsStmt);
$usageStats = mysqli_fetch_assoc($statsResult);
mysqli_stmt_close($statsStmt);

// ---------------- FETCH ACTIVITY LOGS FOR THIS OFFICE ----------------
$logQuery = "
    SELECT * FROM activity_logs 
    WHERE module = 'office' AND module_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
";
$logStmt = mysqli_prepare($conn, $logQuery);
mysqli_stmt_bind_param($logStmt, "i", $officeId);
mysqli_stmt_execute($logStmt);
$logResult = mysqli_stmt_get_result($logStmt);
$activityLogs = [];
while ($row = mysqli_fetch_assoc($logResult)) {
    $activityLogs[] = $row;
}
mysqli_stmt_close($logStmt);

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

function statusBadge($isActive){
    return $isActive ? 
        '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Active</span>' : 
        '<span class="badge bg-secondary"><i class="bi bi-x-circle"></i> Inactive</span>';
}

function headOfficeBadge($isHeadOffice){
    return $isHeadOffice ? 
        '<span class="badge bg-warning text-dark"><i class="bi bi-star-fill"></i> Head Office</span>' : 
        '<span class="badge bg-light text-dark"><i class="bi bi-building"></i> Branch Office</span>';
}

$loggedName = $_SESSION['employee_name'] ?? 'Admin';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Office Details: <?= e($office['location_name']) ?> - TEK-C</title>
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

        .map-container{ height:300px; border-radius:12px; border:1px solid #e5e7eb; margin:0 0 20px 0; }

        .info-row{ display:flex; padding:12px 0; border-bottom:1px solid #e5e7eb; }
        .info-label{ width:180px; font-weight:800; color:#6b7280; }
        .info-value{ flex:1; font-weight:700; color:#1f2937; }

        .coord-badge{ background:#eef2ff; color:#3b82f6; padding:8px 16px; border-radius:30px; font-weight:700; font-size:14px; display:inline-flex; align-items:center; gap:8px; }

        .attendance-table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid #e5e7eb!important; }
        .attendance-table td{ vertical-align:middle; border-color:#e5e7eb; font-weight:600; color:#374151; padding:12px 8px; }

        .timeline-item{ padding:12px 0; border-left:2px solid #3b82f6; padding-left:20px; position:relative; margin-left:10px; }
        .timeline-item:before{ content:''; width:12px; height:12px; background:#3b82f6; border-radius:50%; position:absolute; left:-7px; top:18px; }
        .timeline-date{ font-size:12px; color:#6b7280; margin-bottom:4px; }
        .timeline-title{ font-weight:800; margin-bottom:4px; }
        .timeline-desc{ color:#4b5563; }

        .radius-display{ background:#f0f9ff; border-radius:30px; padding:8px 20px; display:inline-block; border:1px solid #bae6fd; }

        @media (max-width: 768px) {
            .content-scroll{ padding:12px; }
            .info-row{ flex-direction:column; }
            .info-label{ width:100%; margin-bottom:5px; }
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
                        <a href="manage-offices.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <div>
                            <h1 class="h3 fw-bold mb-1">
                                <?= e($office['location_name']) ?>
                                <?php if ($office['is_head_office']): ?>
                                    <span class="badge bg-warning text-dark ms-2"><i class="bi bi-star-fill"></i> Head Office</span>
                                <?php endif; ?>
                                <?= $office['is_active'] ? 
                                    '<span class="badge bg-success ms-2"><i class="bi bi-check-circle"></i> Active</span>' : 
                                    '<span class="badge bg-secondary ms-2"><i class="bi bi-x-circle"></i> Inactive</span>' ?>
                            </h1>
                            <p class="text-muted mb-0">Office location details and usage statistics</p>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="edit-office.php?id=<?= $office['id'] ?>" class="btn btn-primary">
                            <i class="bi bi-pencil-square"></i> Edit Office
                        </a>
                        <a href="office-locations.php?id=<?= $office['id'] ?>" class="btn btn-outline-primary">
                            <i class="bi bi-map"></i> View on Map
                        </a>
                    </div>
                </div>

                <!-- Flash Messages -->
                <?php if (isset($_SESSION['flash_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                        <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['flash_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                        <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Left Column - Map & Details -->
                    <div class="col-lg-6">
                        <!-- Map Card -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-geo-alt-fill text-primary me-2"></i>Location Map
                                </h5>
                            </div>
                            <div id="map" class="map-container"></div>
                            
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <span class="coord-badge">
                                    <i class="bi bi-crosshair"></i> <?= number_format((float)$office['latitude'], 6) ?>, <?= number_format((float)$office['longitude'], 6) ?>
                                </span>
                                <span class="coord-badge bg-light text-dark">
                                    <i class="bi bi-broadcast"></i> Geo-fence Radius: <strong><?= (int)$office['geo_fence_radius'] ?>m</strong>
                                </span>
                                <?php if ($office['is_head_office']): ?>
                                    <span class="coord-badge bg-warning text-dark">
                                        <i class="bi bi-star-fill"></i> Head Office
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Office Details Card -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-building me-2"></i>Office Information
                                </h5>
                            </div>

                            <div class="info-row">
                                <div class="info-label">Office Name:</div>
                                <div class="info-value fw-bold"><?= e($office['location_name']) ?></div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Address:</div>
                                <div class="info-value"><?= nl2br(e($office['address'])) ?></div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Coordinates:</div>
                                <div class="info-value">
                                    <span class="fw-bold"><?= number_format((float)$office['latitude'], 8) ?></span>° N, 
                                    <span class="fw-bold"><?= number_format((float)$office['longitude'], 8) ?></span>° E
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Geo-fence Radius:</div>
                                <div class="info-value">
                                    <span class="radius-display">
                                        <i class="bi bi-broadcast text-primary"></i> <?= (int)$office['geo_fence_radius'] ?> meters
                                    </span>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Office Type:</div>
                                <div class="info-value">
                                    <?= headOfficeBadge($office['is_head_office']) ?>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Status:</div>
                                <div class="info-value">
                                    <?= statusBadge($office['is_active']) ?>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Created:</div>
                                <div class="info-value"><?= safeDateTime($office['created_at']) ?></div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Last Updated:</div>
                                <div class="info-value"><?= safeDateTime($office['updated_at']) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Statistics & Usage -->
                    <div class="col-lg-6">
                        <!-- Usage Statistics -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-graph-up me-2"></i>Usage Statistics
                                </h5>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-ic blue"><i class="bi bi-people"></i></div>
                                        <div>
                                            <div class="stat-label">Unique Employees</div>
                                            <div class="stat-value"><?= (int)($usageStats['unique_employees'] ?? 0) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-ic green"><i class="bi bi-fingerprint"></i></div>
                                        <div>
                                            <div class="stat-label">Total Punches</div>
                                            <div class="stat-value"><?= (int)($usageStats['total_punches'] ?? 0) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-ic orange"><i class="bi bi-box-arrow-in-right"></i></div>
                                        <div>
                                            <div class="stat-label">Punch Ins</div>
                                            <div class="stat-value"><?= (int)($usageStats['punch_ins'] ?? 0) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-ic purple"><i class="bi bi-box-arrow-right"></i></div>
                                        <div>
                                            <div class="stat-label">Punch Outs</div>
                                            <div class="stat-value"><?= (int)($usageStats['punch_outs'] ?? 0) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="border rounded p-3">
                                        <small class="text-muted d-block">First Used</small>
                                        <span class="fw-bold"><?= safeDate($usageStats['first_use'] ?? '') ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded p-3">
                                        <small class="text-muted d-block">Last Used</small>
                                        <span class="fw-bold"><?= safeDate($usageStats['last_use'] ?? '') ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Attendance -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-clock-history me-2"></i>Recent Attendance
                                    <span class="badge bg-secondary ms-2"><?= count($recentAttendance) ?></span>
                                </h5>
                            </div>

                            <?php if (empty($recentAttendance)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                                    <p>No attendance records found for this office.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table attendance-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Employee</th>
                                                <th>Punch In</th>
                                                <th>Punch Out</th>
                                                <th>Type</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentAttendance as $att): ?>
                                                <tr>
                                                    <td><?= safeDate($att['attendance_date']) ?></td>
                                                    <td>
                                                        <div class="fw-bold"><?= e($att['employee_name'] ?? '') ?></div>
                                                        <small class="text-muted"><?= e($att['employee_code'] ?? '') ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($att['punch_in_office_id'] == $officeId): ?>
                                                            <span class="badge bg-success bg-opacity-10 text-success">
                                                                <i class="bi bi-box-arrow-in-right"></i> 
                                                                <?= $att['punch_in_time'] ? date('h:i A', strtotime($att['punch_in_time'])) : '—' ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <?= $att['punch_in_time'] ? date('h:i A', strtotime($att['punch_in_time'])) : '—' ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($att['punch_out_office_id'] == $officeId): ?>
                                                            <span class="badge bg-warning bg-opacity-10 text-warning">
                                                                <i class="bi bi-box-arrow-right"></i> 
                                                                <?= $att['punch_out_time'] ? date('h:i A', strtotime($att['punch_out_time'])) : '—' ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <?= $att['punch_out_time'] ? date('h:i A', strtotime($att['punch_out_time'])) : '—' ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($att['punch_in_office_id'] == $officeId && $att['punch_out_office_id'] == $officeId): ?>
                                                            <span class="badge bg-info">Full Day</span>
                                                        <?php elseif ($att['punch_in_office_id'] == $officeId): ?>
                                                            <span class="badge bg-primary">Punched In</span>
                                                        <?php elseif ($att['punch_out_office_id'] == $officeId): ?>
                                                            <span class="badge bg-warning">Punched Out</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Activity Timeline -->
                        <?php if (!empty($activityLogs)): ?>
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-activity me-2"></i>Recent Activity
                                </h5>
                            </div>

                            <div class="timeline">
                                <?php foreach ($activityLogs as $log): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-date">
                                            <?= safeDateTime($log['created_at']) ?>
                                            <span class="badge bg-light text-dark ms-2"><?= e($log['action_type']) ?></span>
                                        </div>
                                        <div class="timeline-title">
                                            <?= e($log['description']) ?>
                                        </div>
                                        <?php if (!empty($log['user_name'])): ?>
                                            <div class="timeline-desc">
                                                <i class="bi bi-person"></i> <?= e($log['user_name']) ?> 
                                                (<?= e($log['user_role'] ?? 'N/A') ?>)
                                            </div>
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
let marker;
let circle;

// Initialize map
function initMap() {
    const officeLocation = { 
        lat: <?= (float)$office['latitude'] ?>, 
        lng: <?= (float)$office['longitude'] ?> 
    };
    
    map = new google.maps.Map(document.getElementById('map'), {
        center: officeLocation,
        zoom: 16,
        mapTypeId: google.maps.MapTypeId.ROADMAP,
        mapTypeControl: true,
        streetViewControl: true,
        fullscreenControl: true
    });

    // Add marker
    marker = new google.maps.Marker({
        position: officeLocation,
        map: map,
        title: '<?= e($office['location_name']) ?>',
        animation: google.maps.Animation.DROP
    });

    // Add info window
    const infoWindow = new google.maps.InfoWindow({
        content: `
            <div style="padding:8px;">
                <strong><?= e($office['location_name']) ?></strong><br>
                <span style="color:#666;"><?= e(substr($office['address'], 0, 100)) ?>...</span><br>
                <span style="color:#3b82f6;">Radius: <?= (int)$office['geo_fence_radius'] ?>m</span>
            </div>
        `
    });

    marker.addListener('click', function() {
        infoWindow.open(map, marker);
    });

    // Draw geo-fence circle
    circle = new google.maps.Circle({
        strokeColor: '#3b82f6',
        strokeOpacity: 0.5,
        strokeWeight: 2,
        fillColor: '#3b82f6',
        fillOpacity: 0.1,
        map: map,
        center: officeLocation,
        radius: <?= (int)$office['geo_fence_radius'] ?>
    });

    // Fit bounds to show both marker and circle
    const bounds = new google.maps.LatLngBounds();
    bounds.extend(officeLocation);
    
    // Add circle bounds if radius is large enough
    if (<?= (int)$office['geo_fence_radius'] ?> > 50) {
        const ne = google.maps.geometry.spherical.computeOffset(officeLocation, <?= (int)$office['geo_fence_radius'] ?>, 45);
        const sw = google.maps.geometry.spherical.computeOffset(officeLocation, <?= (int)$office['geo_fence_radius'] ?>, 225);
        bounds.extend(ne);
        bounds.extend(sw);
    }
    
    map.fitBounds(bounds);
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