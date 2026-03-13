<?php
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

$current_employee_id = $_SESSION['employee_id'] ?? 6;
$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$today = date('Y-m-d');
$action = $_GET['action'] ?? 'in'; // in | out
if (!in_array($action, ['in', 'out'])) $action = 'in';

// IMPORTANT: move API key to env/config in production
$google_maps_api_key = 'AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE';

function calculateDistanceFallback($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $dLat = $lat2 - $lat1;
    $dLon = $lon2 - $lon1;

    $a = sin($dLat/2) * sin($dLat/2) +
         cos($lat1) * cos($lat2) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * asin(sqrt($a));
    return $earthRadius * $c;
}

$error = '';
$success = '';
$punch_location_data = null;

// Employee
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);
if (!$employee) die("Employee not found or inactive.");

// Check if user is HR or Admin
$designation = strtolower(trim($employee['designation'] ?? ''));
$department = strtolower(trim($employee['department'] ?? ''));
$isHrOrAdmin = ($designation === 'hr' || $department === 'hr' || $designation === 'administrator' || $designation === 'admin');

// Today's attendance
$att_stmt = mysqli_prepare($conn, "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
mysqli_stmt_bind_param($att_stmt, "is", $current_employee_id, $today);
mysqli_stmt_execute($att_stmt);
$att_res = mysqli_stmt_get_result($att_stmt);
$attendance = mysqli_fetch_assoc($att_res);
mysqli_stmt_close($att_stmt);

// Role-based office punch (All employees can punch from office if they have access to office locations)
// Now all roles can punch from office, but we'll show different office options
$can_punch_office = true; // All employees can punch from office

// Assigned sites (for punch in page)
$assigned_sites = [];
$sites_stmt = mysqli_prepare($conn, "
    SELECT s.*
    FROM sites s
    JOIN site_project_engineers spe ON s.id = spe.site_id
    WHERE spe.employee_id = ? AND s.deleted_at IS NULL
");
mysqli_stmt_bind_param($sites_stmt, "i", $current_employee_id);
mysqli_stmt_execute($sites_stmt);
$sites_res = mysqli_stmt_get_result($sites_stmt);
$assigned_sites = mysqli_fetch_all($sites_res, MYSQLI_ASSOC);
mysqli_stmt_close($sites_stmt);

// Offices - All active offices (available for all roles now)
$offices = [];
$office_query = "SELECT * FROM office_locations WHERE is_active = 1 ORDER BY is_head_office DESC, location_name ASC";
$office_res = mysqli_query($conn, $office_query);
if ($office_res) $offices = mysqli_fetch_all($office_res, MYSQLI_ASSOC);

// Prepare punch out target location
if ($action === 'out' && $attendance && !$attendance['punch_out_time']) {
    if ($attendance['punch_in_type'] === 'site' && !empty($attendance['punch_in_site_id'])) {
        $site_id = (int)$attendance['punch_in_site_id'];
        $site_q = mysqli_query($conn, "SELECT * FROM sites WHERE id = {$site_id} LIMIT 1");
        $site = mysqli_fetch_assoc($site_q);
        if ($site && $site['latitude'] && $site['longitude']) {
            $punch_location_data = [
                'type' => 'site',
                'lat' => (float)$site['latitude'],
                'lng' => (float)$site['longitude'],
                'radius' => (int)($site['location_radius'] ?? 100),
                'name' => $site['project_name']
            ];
        }
    } elseif ($attendance['punch_in_type'] === 'office' && !empty($attendance['punch_in_office_id'])) {
        $office_id = (int)$attendance['punch_in_office_id'];
        $office_q = mysqli_query($conn, "SELECT * FROM office_locations WHERE id = {$office_id} LIMIT 1");
        $office = mysqli_fetch_assoc($office_q);
        if ($office && $office['latitude'] && $office['longitude']) {
            $punch_location_data = [
                'type' => 'office',
                'lat' => (float)$office['latitude'],
                'lng' => (float)$office['longitude'],
                'radius' => (int)($office['geo_fence_radius'] ?? 100),
                'name' => $office['location_name']
            ];
        }
    }
}

// Guard conditions
if ($action === 'in' && $attendance) {
    $_SESSION['flash_error'] = 'You have already punched in today.';
    header("Location: punchin.php");
    exit;
}

if ($action === 'out') {
    if (!$attendance) {
        $_SESSION['flash_error'] = 'No punch-in found for today.';
        header("Location: punchin.php");
        exit;
    }
    if (!empty($attendance['punch_out_time'])) {
        $_SESSION['flash_error'] = 'You have already punched out today.';
        header("Location: punchin.php");
        exit;
    }
    if (!$punch_location_data) {
        $_SESSION['flash_error'] = 'Punch-out location config not found.';
        header("Location: punchin.php");
        exit;
    }
}

// ---------------------------
// HANDLE POST: PUNCH IN
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'in') {
    $punch_lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0;
    $punch_lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0;
    $punch_location = trim($_POST['location'] ?? '');
    $punch_type = $_POST['punch_type_radio'] ?? '';
    $site_id = isset($_POST['site_id']) && $_POST['site_id'] !== '' ? (int)$_POST['site_id'] : null;
    $office_id = isset($_POST['office_id']) && $_POST['office_id'] !== '' ? (int)$_POST['office_id'] : null;

    $can_punch = false;

    if (!$punch_lat || !$punch_lng) {
        $error = "Invalid GPS coordinates. Please allow location access and try again.";
    } elseif (!in_array($punch_type, ['site', 'office'])) {
        $error = "Invalid punch type.";
    } else {
        // SITE punch
        if ($punch_type === 'site') {
            if (!$site_id) {
                $error = "Please select a site.";
            } else {
                $site_check_stmt = mysqli_prepare($conn, "
                    SELECT s.*
                    FROM sites s
                    JOIN site_project_engineers spe ON s.id = spe.site_id
                    WHERE spe.employee_id = ? AND s.id = ?
                    LIMIT 1
                ");
                mysqli_stmt_bind_param($site_check_stmt, "ii", $current_employee_id, $site_id);
                mysqli_stmt_execute($site_check_stmt);
                $site_check_res = mysqli_stmt_get_result($site_check_stmt);
                $site = mysqli_fetch_assoc($site_check_res);
                mysqli_stmt_close($site_check_stmt);

                if (!$site) {
                    $error = "You are not assigned to this site.";
                } elseif (!$site['latitude'] || !$site['longitude']) {
                    $error = "Site location not configured.";
                } else {
                    $distance = calculateDistanceFallback(
                        $punch_lat, $punch_lng,
                        (float)$site['latitude'], (float)$site['longitude']
                    );
                    $radius = (int)($site['location_radius'] ?? 100);

                    if ($distance > $radius) {
                        $error = "You are outside allowed site radius ({$radius}m).";
                    } else {
                        $can_punch = true;
                        $punch_location = $site['project_name'];
                    }
                }
            }
        }

        // OFFICE punch
        if ($punch_type === 'office') {
            if (!$office_id) {
                $error = "Please select an office location.";
            } else {
                $office_check_stmt = mysqli_prepare($conn, "
                    SELECT * FROM office_locations WHERE id = ? AND is_active = 1 LIMIT 1
                ");
                mysqli_stmt_bind_param($office_check_stmt, "i", $office_id);
                mysqli_stmt_execute($office_check_stmt);
                $office_check_res = mysqli_stmt_get_result($office_check_stmt);
                $office = mysqli_fetch_assoc($office_check_res);
                mysqli_stmt_close($office_check_stmt);

                if (!$office) {
                    $error = "Invalid office location.";
                } elseif (!$office['latitude'] || !$office['longitude']) {
                    $error = "Office location not configured.";
                } else {
                    $distance = calculateDistanceFallback(
                        $punch_lat, $punch_lng,
                        (float)$office['latitude'], (float)$office['longitude']
                    );
                    $radius = (int)($office['geo_fence_radius'] ?? 100);

                    if ($distance > $radius) {
                        $error = "You are outside allowed office radius ({$radius}m).";
                    } else {
                        $can_punch = true;
                        $punch_location = $office['location_name'];
                    }
                }
            }
        }
    }

    if ($can_punch && !$error) {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO attendance
            (employee_id, attendance_date, punch_in_time,
             punch_in_location, punch_in_latitude, punch_in_longitude,
             punch_in_type, punch_in_site_id, punch_in_office_id, status)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, 'present')
        ");
        mysqli_stmt_bind_param(
            $stmt,
            "issddsii",
            $current_employee_id,
            $today,
            $punch_location,
            $punch_lat,
            $punch_lng,
            $punch_type,
            $site_id,
            $office_id
        );

        if (mysqli_stmt_execute($stmt)) {
            logActivity(
                $conn,
                'CREATE',
                'attendance',
                "Punched in at {$punch_location}",
                null,
                null,
                null,
                json_encode(['type' => $punch_type])
            );
            $_SESSION['flash_success'] = "Punch in successful at {$punch_location}.";
            header("Location: punchin.php");
            exit;
        } else {
            $error = mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
    }
}

// ---------------------------
// HANDLE POST: PUNCH OUT
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'out' && $attendance) {
    $punch_lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0;
    $punch_lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0;
    $punch_location = trim($_POST['location'] ?? '');

    if (!$punch_lat || !$punch_lng) {
        $error = "Invalid GPS coordinates. Please allow location access and try again.";
    } else {
        $distance = calculateDistanceFallback(
            $punch_lat,
            $punch_lng,
            $punch_location_data['lat'],
            $punch_location_data['lng']
        );

        if ($distance > $punch_location_data['radius']) {
            $error = "You are outside allowed {$punch_location_data['type']} radius.";
        } else {
            $punch_in_time = strtotime($attendance['punch_in_time']);
            $punch_out_time = time();
            $total_hours = round(($punch_out_time - $punch_in_time) / 3600, 2);

            $stmt = mysqli_prepare($conn, "
                UPDATE attendance SET
                    punch_out_time = NOW(),
                    punch_out_location = ?,
                    punch_out_latitude = ?,
                    punch_out_longitude = ?,
                    total_hours = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "sdddi",
                $punch_location,
                $punch_lat,
                $punch_lng,
                $total_hours,
                $attendance['id']
            );

            if (mysqli_stmt_execute($stmt)) {
                logActivity(
                    $conn,
                    'UPDATE',
                    'attendance',
                    "Punched out after {$total_hours} hours",
                    $attendance['id'],
                    null,
                    null,
                    json_encode(['hours' => $total_hours])
                );
                $_SESSION['flash_success'] = "Punch out successful. Total hours: {$total_hours}h";
                header("Location: punchin.php");
                exit;
            } else {
                $error = mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Get current time for display
$current_time = date('h:i A');
$current_date = date('d M Y');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $action === 'in' ? 'Punch In' : 'Punch Out' ?> - TEK-C</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($google_maps_api_key) ?>&libraries=places,geometry"></script>

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px; }
    .card-panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 8px 24px rgba(17,24,39,.06); }
    .location-status{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:15px; }
    .map-box{ height:260px; border-radius:12px; border:1px solid #e5e7eb; display:none; }
    .pill{ display:inline-flex; align-items:center; gap:8px; border-radius:999px; padding:6px 10px; font-size:12px; font-weight:700; }
    .pill-blue{ background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
    .pill-green{ background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
    .pill-purple{ background:#f3e8ff; color:#6b21a8; border:1px solid #d8b4fe; }

    .info-grid{
      display:grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap:10px;
    }
    .mini-info{
      background:#f8fafc;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:10px 12px;
    }
    .mini-info .lbl{
      font-size:11px;
      color:#6b7280;
      font-weight:700;
      margin-bottom:2px;
    }
    .mini-info .val{
      font-size:13px;
      color:#111827;
      font-weight:700;
      word-break:break-word;
    }
    .address-box{
      background:#f8fafc;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:12px;
    }
    .address-box .lbl{
      font-size:11px;
      color:#6b7280;
      font-weight:700;
      margin-bottom:4px;
    }
    .address-box .val{
      font-size:13px;
      color:#111827;
      font-weight:600;
      line-height:1.35;
      word-break:break-word;
    }

    .role-badge{
      display:inline-block;
      padding:4px 10px;
      border-radius:20px;
      font-size:11px;
      font-weight:700;
      margin-left:8px;
    }
    .role-hr{
      background:#e3f2fd;
      color:#0d47a1;
    }
    .role-admin{
      background:#f3e5f5;
      color:#4a148c;
    }

    @media (max-width: 991.98px){
      .main{
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
      }
      .sidebar{
        position: fixed !important;
        transform: translateX(-100%);
        z-index: 1040 !important;
      }
      .sidebar.open, .sidebar.active, .sidebar.show{
        transform: translateX(0) !important;
      }
    }
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
      .sec-head { padding: 10px !important; border-radius: 12px; }
      .info-grid{ grid-template-columns: 1fr; }
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
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h1 class="h3 fw-bold mb-1"><?= $action === 'in' ? 'Punch In' : 'Punch Out' ?></h1>
            <p class="text-muted mb-0">
              <?= $action === 'in' ? 'Validate location and mark punch in' : 'Validate location and mark punch out' ?>
              <?php if ($isHrOrAdmin): ?>
                <span class="role-badge <?= $designation === 'administrator' || $designation === 'admin' ? 'role-admin' : 'role-hr' ?>">
                  <i class="bi bi-shield-check me-1"></i>
                  <?= $designation === 'administrator' || $designation === 'admin' ? 'Admin Access' : 'HR Access' ?>
                </span>
              <?php endif; ?>
            </p>
          </div>
          <a href="punchin.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
          </a>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-lg-8">
            <div class="card-panel p-4">
              <?php if ($action === 'in'): ?>
                <form method="POST" id="punchForm">
                  <input type="hidden" name="latitude" id="punchLat">
                  <input type="hidden" name="longitude" id="punchLng">
                  <input type="hidden" name="location" id="punchAddress">
                  <input type="hidden" name="punch_type_radio" id="punchType" value="site">

                  <div class="mb-3">
                    <label class="form-label fw-bold">Select Punch Location Type</label>
                    <div class="d-flex gap-4 flex-wrap">
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="punch_type_radio_display" id="punchTypeSite" value="site" checked>
                        <label class="form-check-label" for="punchTypeSite">
                          <i class="bi bi-building"></i> Site Location
                        </label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="punch_type_radio_display" id="punchTypeOffice" value="office">
                        <label class="form-check-label" for="punchTypeOffice">
                          <i class="bi bi-briefcase"></i> Office Location
                        </label>
                      </div>
                    </div>
                  </div>

                  <div class="mb-3" id="siteSelectDiv">
                    <label class="form-label fw-bold">Select Your Site</label>
                    <select class="form-select" name="site_id" id="siteSelect" required>
                      <option value="">Choose assigned site</option>
                      <?php foreach ($assigned_sites as $site): ?>
                        <option value="<?= (int)$site['id'] ?>"
                          data-lat="<?= htmlspecialchars($site['latitude']) ?>"
                          data-lng="<?= htmlspecialchars($site['longitude']) ?>"
                          data-radius="<?= (int)($site['location_radius'] ?? 100) ?>"
                          data-name="<?= htmlspecialchars($site['project_name']) ?>">
                          <?= htmlspecialchars($site['project_name']) ?> (<?= htmlspecialchars($site['project_code'] ?? 'No Code') ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="mb-3" id="officeSelectDiv" style="display:none;">
                    <label class="form-label fw-bold">Select Office Location</label>
                    <select class="form-select" name="office_id" id="officeSelect">
                      <option value="">Choose office</option>
                      <?php foreach ($offices as $office): ?>
                        <option value="<?= (int)$office['id'] ?>"
                          data-lat="<?= htmlspecialchars($office['latitude']) ?>"
                          data-lng="<?= htmlspecialchars($office['longitude']) ?>"
                          data-radius="<?= (int)($office['geo_fence_radius'] ?? 100) ?>"
                          data-name="<?= htmlspecialchars($office['location_name']) ?>">
                          <?= htmlspecialchars($office['location_name']) ?>
                          <?php if ($office['is_head_office']): ?>
                            (Head Office)
                          <?php endif; ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="text-muted mt-1 d-block">
                      <i class="bi bi-info-circle"></i> Select your current office location
                    </small>
                  </div>

                  <!-- Current Location Details -->
                  <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <label class="form-label fw-bold mb-0">Current Location Details</label>
                      <button type="button" class="btn btn-sm btn-outline-primary" id="refreshLocationBtn">
                        <i class="bi bi-arrow-clockwise"></i> Refresh GPS
                      </button>
                    </div>

                    <div class="info-grid mb-2">
                      <div class="mini-info">
                        <div class="lbl">Latitude</div>
                        <div class="val" id="currentLatText">—</div>
                      </div>
                      <div class="mini-info">
                        <div class="lbl">Longitude</div>
                        <div class="val" id="currentLngText">—</div>
                      </div>
                      <div class="mini-info">
                        <div class="lbl">Accuracy</div>
                        <div class="val" id="currentAccuracyText">—</div>
                      </div>
                      <div class="mini-info">
                        <div class="lbl">GPS Updated At</div>
                        <div class="val" id="currentGpsTimeText">—</div>
                      </div>
                    </div>

                    <div class="address-box">
                      <div class="lbl">Detected Address</div>
                      <div class="val" id="currentAddressText">Detecting location...</div>
                    </div>
                  </div>

                  <div id="locationStatus" class="location-status mb-3">
                    <div class="d-flex align-items-center">
                      <div class="spinner-border spinner-border-sm me-2"></div>
                      <span>Getting your location...</span>
                    </div>
                  </div>

                  <div id="mapPreview" class="map-box mb-3"></div>

                  <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-2"></i>
                    Make sure your device location is enabled. You can only punch in within the geo-fence radius.
                  </div>

                  <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                      <i class="bi bi-box-arrow-in-right"></i> Confirm Punch In
                    </button>
                    <a href="punchin.php" class="btn btn-outline-secondary">Cancel</a>
                  </div>
                </form>

              <?php else: ?>
                <form method="POST" id="punchOutForm">
                  <input type="hidden" name="latitude" id="punchLat">
                  <input type="hidden" name="longitude" id="punchLng">
                  <input type="hidden" name="location" id="punchAddress">

                  <div class="alert alert-info">
                    <div class="d-flex align-items-center">
                      <i class="bi bi-info-circle-fill me-3 fs-4"></i>
                      <div>
                        <strong>Punch Out Location: <?= htmlspecialchars($punch_location_data['name'] ?? '') ?></strong><br>
                        <small>
                          Type: <?= ucfirst($punch_location_data['type'] ?? '') ?> |
                          Required Radius: <?= (int)($punch_location_data['radius'] ?? 100) ?>m
                        </small>
                      </div>
                    </div>
                  </div>

                  <!-- Current Location Details -->
                  <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <label class="form-label fw-bold mb-0">Current Location Details</label>
                      <button type="button" class="btn btn-sm btn-outline-primary" id="refreshLocationBtn">
                        <i class="bi bi-arrow-clockwise"></i> Refresh GPS
                      </button>
                    </div>

                    <div class="info-grid mb-2">
                      <div class="mini-info">
                        <div class="lbl">Latitude</div>
                        <div class="val" id="currentLatText">—</div>
                      </div>
                      <div class="mini-info">
                        <div class="lbl">Longitude</div>
                        <div class="val" id="currentLngText">—</div>
                      </div>
                      <div class="mini-info">
                        <div class="lbl">Accuracy</div>
                        <div class="val" id="currentAccuracyText">—</div>
                      </div>
                      <div class="mini-info">
                        <div class="lbl">GPS Updated At</div>
                        <div class="val" id="currentGpsTimeText">—</div>
                      </div>
                    </div>

                    <div class="address-box">
                      <div class="lbl">Detected Address</div>
                      <div class="val" id="currentAddressText">Detecting location...</div>
                    </div>
                  </div>

                  <div id="locationStatus" class="location-status mb-3">
                    <div class="d-flex align-items-center">
                      <div class="spinner-border spinner-border-sm me-2"></div>
                      <span>Validating your location...</span>
                    </div>
                  </div>

                  <div id="mapPreview" class="map-box mb-3" style="display:block;"></div>

                  <div class="alert alert-warning mb-3">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    You must be within <?= (int)($punch_location_data['radius'] ?? 100) ?>m of the
                    <?= htmlspecialchars($punch_location_data['type'] ?? '') ?> location to punch out.
                  </div>

                  <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                      <i class="bi bi-box-arrow-right"></i> Confirm Punch Out
                    </button>
                    <a href="punchin.php" class="btn btn-outline-secondary">Cancel</a>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card-panel p-4">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold mb-0">Employee Details</h5>
                <?php if ($isHrOrAdmin): ?>
                  <span class="badge <?= $designation === 'administrator' || $designation === 'admin' ? 'bg-dark' : 'bg-info' ?>">
                    <i class="bi bi-shield-check"></i>
                    <?= $designation === 'administrator' || $designation === 'admin' ? 'ADMIN' : 'HR' ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="mb-2"><strong>Name:</strong> <?= htmlspecialchars($employee['full_name']) ?></div>
              <div class="mb-2"><strong>Code:</strong> <?= htmlspecialchars($employee['employee_code']) ?></div>
              <div class="mb-2"><strong>Designation:</strong> <?= htmlspecialchars($employee['designation']) ?></div>
              <div class="mb-2"><strong>Date:</strong> <?= $current_date ?></div>
              <div class="mb-2"><strong>Time:</strong> <?= $current_time ?></div>

              <?php if ($attendance): ?>
                <hr>
                <div class="mb-2"><strong>Today's Punch In:</strong> <?= date('h:i A', strtotime($attendance['punch_in_time'])) ?></div>
                <div class="mb-2"><strong>Type:</strong> <?= ucfirst($attendance['punch_in_type']) ?></div>
                <div class="mb-2"><strong>Location:</strong> <?= htmlspecialchars($attendance['punch_in_location']) ?></div>
              <?php endif; ?>

              <?php if ($action === 'out' && $punch_location_data): ?>
                <hr>
                <h6 class="fw-bold mb-2">Punch Out Target</h6>
                <div class="mb-2"><strong>Target:</strong> <?= htmlspecialchars($punch_location_data['name']) ?></div>
                <div class="mb-2"><strong>Type:</strong> <?= ucfirst($punch_location_data['type']) ?></div>
                <div class="mb-2"><strong>Radius:</strong> <?= (int)$punch_location_data['radius'] ?>m</div>
                <div class="mb-2"><strong>Target Lat:</strong> <?= htmlspecialchars((string)$punch_location_data['lat']) ?></div>
                <div class="mb-2"><strong>Target Lng:</strong> <?= htmlspecialchars((string)$punch_location_data['lng']) ?></div>
              <?php endif; ?>

              <hr>
              <div class="small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                If GPS fails, enable location permissions in browser and refresh the page.
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>
<script src="assets/js/sidebar-toggle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
let map, geocoder, currentMarker, targetMarker, targetCircle;
let currentLat = null, currentLng = null, currentAccuracy = null;
let currentAddress = '';
let lastLocationUpdateTime = null;

function formatTime(dt) {
  if (!dt) return '—';
  return dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
}

function updateCurrentLocationUI() {
  const latEl = document.getElementById('currentLatText');
  const lngEl = document.getElementById('currentLngText');
  const accEl = document.getElementById('currentAccuracyText');
  const gpsTimeEl = document.getElementById('currentGpsTimeText');
  const addrEl = document.getElementById('currentAddressText');

  if (latEl) latEl.textContent = (currentLat !== null) ? Number(currentLat).toFixed(6) : '—';
  if (lngEl) lngEl.textContent = (currentLng !== null) ? Number(currentLng).toFixed(6) : '—';
  if (accEl) accEl.textContent = (currentAccuracy !== null) ? `${Math.round(currentAccuracy)} m` : '—';
  if (gpsTimeEl) gpsTimeEl.textContent = formatTime(lastLocationUpdateTime);
  if (addrEl) addrEl.textContent = currentAddress || 'Detecting location...';
}

function getAddressFromLatLng(lat, lng) {
  return new Promise((resolve) => {
    try {
      if (!geocoder) geocoder = new google.maps.Geocoder();
      geocoder.geocode({ location: { lat: parseFloat(lat), lng: parseFloat(lng) } }, (results, status) => {
        if (status === 'OK' && results && results[0]) {
          resolve(results[0].formatted_address);
        } else {
          resolve(`Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`);
        }
      });
    } catch (e) {
      resolve(`Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`);
    }
  });
}

function getLocation(options = {}) {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error('Geolocation is not supported by your browser'));
      return;
    }

    const config = Object.assign({
      enableHighAccuracy: true,
      timeout: 30000,
      maximumAge: 0
    }, options);

    navigator.geolocation.getCurrentPosition(resolve, reject, config);
  });
}

function initMap(lat, lng) {
  const mapDiv = document.getElementById('mapPreview');
  if (!mapDiv) return;
  mapDiv.style.display = 'block';

  if (!map) {
    map = new google.maps.Map(mapDiv, {
      center: { lat: parseFloat(lat), lng: parseFloat(lng) },
      zoom: 18,
      mapTypeId: google.maps.MapTypeId.ROADMAP
    });
  } else {
    map.setCenter({ lat: parseFloat(lat), lng: parseFloat(lng) });
  }
}

function renderMap(currentLat, currentLng, targetLat, targetLng, radius) {
  initMap(currentLat, currentLng);

  if (currentMarker) currentMarker.setMap(null);
  if (targetMarker) targetMarker.setMap(null);
  if (targetCircle) targetCircle.setMap(null);

  currentMarker = new google.maps.Marker({
    position: { lat: parseFloat(currentLat), lng: parseFloat(currentLng) },
    map: map,
    title: 'Your Location',
    icon: {
      url: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png',
      scaledSize: new google.maps.Size(40, 40)
    }
  });

  targetMarker = new google.maps.Marker({
    position: { lat: parseFloat(targetLat), lng: parseFloat(targetLng) },
    map: map,
    title: 'Target Location',
    icon: {
      url: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png',
      scaledSize: new google.maps.Size(40, 40)
    }
  });

  targetCircle = new google.maps.Circle({
    map: map,
    center: { lat: parseFloat(targetLat), lng: parseFloat(targetLng) },
    radius: parseFloat(radius),
    fillColor: '#3b82f6',
    fillOpacity: 0.10,
    strokeColor: '#3b82f6',
    strokeOpacity: 0.55,
    strokeWeight: 2
  });

  // Auto-fit bounds (nice UX)
  const bounds = new google.maps.LatLngBounds();
  bounds.extend({ lat: parseFloat(currentLat), lng: parseFloat(currentLng) });
  bounds.extend({ lat: parseFloat(targetLat), lng: parseFloat(targetLng) });
  map.fitBounds(bounds);

  // Avoid over-zoom
  google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
    if (map.getZoom() > 18) map.setZoom(18);
  });
}

function setStatus(type, title, subtitle = '') {
  const el = document.getElementById('locationStatus');
  if (!el) return;
  const icon = type === 'success' ? 'check-circle-fill' : (type === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill');
  const cls  = type === 'success' ? 'text-success' : (type === 'danger' ? 'text-danger' : 'text-warning');

  el.innerHTML = `
    <div class="d-flex align-items-center ${cls}">
      <i class="bi bi-${icon} me-2 fs-4"></i>
      <div>
        <strong>${title}</strong>${subtitle ? '<br><small class="text-muted">' + subtitle + '</small>' : ''}
      </div>
    </div>
  `;
}

function setLoadingStatus(msg = 'Getting your location...') {
  const el = document.getElementById('locationStatus');
  if (!el) return;
  el.innerHTML = `
    <div class="d-flex align-items-center">
      <div class="spinner-border spinner-border-sm me-2" role="status"></div>
      <span>${msg}</span>
    </div>
  `;
}

function setRefreshButtonLoading(loading) {
  const btn = document.getElementById('refreshLocationBtn');
  if (!btn) return;
  if (loading) {
    btn.disabled = true;
    btn.dataset.originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Refreshing...';
  } else {
    btn.disabled = false;
    btn.innerHTML = btn.dataset.originalHtml || '<i class="bi bi-arrow-clockwise"></i> Refresh GPS';
  }
}

async function fetchAndStoreLocation(options = {}) {
  const position = await getLocation(options);
  currentLat = position.coords.latitude;
  currentLng = position.coords.longitude;
  currentAccuracy = position.coords.accuracy ?? null;
  lastLocationUpdateTime = new Date();

  document.getElementById('punchLat').value = currentLat;
  document.getElementById('punchLng').value = currentLng;

  currentAddress = await getAddressFromLatLng(currentLat, currentLng);
  document.getElementById('punchAddress').value = currentAddress;

  updateCurrentLocationUI();
  return position;
}

async function validateCurrentLocationForPunchIn() {
  const submitBtn = document.getElementById('submitBtn');
  const siteRadio = document.getElementById('punchTypeSite');
  const officeRadio = document.getElementById('punchTypeOffice');
  const siteSelect = document.getElementById('siteSelect');
  const officeSelect = document.getElementById('officeSelect');
  const punchTypeHidden = document.getElementById('punchType');

  if (!currentLat || !currentLng) {
    submitBtn.disabled = true;
    return;
  }

  let selectedOption = null;
  let mode = 'site';

  if (siteRadio && siteRadio.checked) {
    mode = 'site';
    if (punchTypeHidden) punchTypeHidden.value = 'site';
    if (!siteSelect || !siteSelect.value) {
      setStatus('warning', 'Please select a site');
      submitBtn.disabled = true;
      return;
    }
    selectedOption = siteSelect.options[siteSelect.selectedIndex];
  } else if (officeRadio && officeRadio.checked) {
    mode = 'office';
    if (punchTypeHidden) punchTypeHidden.value = 'office';
    if (!officeSelect || !officeSelect.value) {
      setStatus('warning', 'Please select an office');
      submitBtn.disabled = true;
      return;
    }
    selectedOption = officeSelect.options[officeSelect.selectedIndex];
  }

  const targetLat = parseFloat(selectedOption.dataset.lat);
  const targetLng = parseFloat(selectedOption.dataset.lng);
  const radius = parseInt(selectedOption.dataset.radius) || 100;

  if (!targetLat || !targetLng) {
    setStatus('danger', `${mode === 'site' ? 'Site' : 'Office'} location not configured`);
    submitBtn.disabled = true;
    return;
  }

  const currentLatLng = new google.maps.LatLng(currentLat, currentLng);
  const targetLatLng = new google.maps.LatLng(targetLat, targetLng);
  const distance = google.maps.geometry.spherical.computeDistanceBetween(currentLatLng, targetLatLng);

  renderMap(currentLat, currentLng, targetLat, targetLng, radius);

  let subtitle = `${Math.round(distance)}m / ${radius}m`;
  if (currentAccuracy !== null) subtitle += ` • GPS accuracy ±${Math.round(currentAccuracy)}m`;

  if (distance <= radius) {
    setStatus('success', '✅ Within Range', subtitle);
    submitBtn.disabled = false;
  } else {
    setStatus('danger', '❌ Too Far', subtitle);
    submitBtn.disabled = true;
  }
}

async function validateCurrentLocationForPunchOut() {
  const submitBtn = document.getElementById('submitBtn');
  if (!currentLat || !currentLng) {
    submitBtn.disabled = true;
    return;
  }

  const targetLat = <?= json_encode($punch_location_data['lat'] ?? 0) ?>;
  const targetLng = <?= json_encode($punch_location_data['lng'] ?? 0) ?>;
  const radius = <?= json_encode($punch_location_data['radius'] ?? 100) ?>;

  const currentLatLng = new google.maps.LatLng(currentLat, currentLng);
  const targetLatLng = new google.maps.LatLng(targetLat, targetLng);
  const distance = google.maps.geometry.spherical.computeDistanceBetween(currentLatLng, targetLatLng);

  renderMap(currentLat, currentLng, targetLat, targetLng, radius);

  let subtitle = `${Math.round(distance)}m / ${radius}m`;
  if (currentAccuracy !== null) subtitle += ` • GPS accuracy ±${Math.round(currentAccuracy)}m`;

  if (distance <= radius) {
    setStatus('success', '✅ Within Range', subtitle);
    submitBtn.disabled = false;
  } else {
    setStatus('danger', '❌ Too Far', `${subtitle} - Required: ${radius}m`);
    submitBtn.disabled = true;
  }
}

async function initializeLocation() {
  const submitBtn = document.getElementById('submitBtn');
  setLoadingStatus('Getting your location...');

  try {
    await fetchAndStoreLocation({ timeout: 30000 });

    <?php if ($action === 'in'): ?>
      await validateCurrentLocationForPunchIn();
    <?php else: ?>
      await validateCurrentLocationForPunchOut();
    <?php endif; ?>

  } catch (error) {
    console.error('Location error:', error);
    let msg = 'Unable to get your location. ';
    if (error.code === 1) msg += 'Please allow location access in browser settings.';
    else if (error.code === 2) msg += 'Location unavailable. Please check GPS.';
    else if (error.code === 3) msg += 'Location request timed out. Please try again.';
    else msg += (error.message || 'Please try again.');

    currentAddress = msg;
    updateCurrentLocationUI();

    setStatus('danger', 'Location Error', msg);
    if (submitBtn) submitBtn.disabled = true;
  }
}

document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('punchForm');
  const punchOutForm = document.getElementById('punchOutForm');
  const submitBtn = document.getElementById('submitBtn');
  const refreshLocationBtn = document.getElementById('refreshLocationBtn');

  // Punch-in toggles
  const punchTypeSite = document.getElementById('punchTypeSite');
  const punchTypeOffice = document.getElementById('punchTypeOffice');
  const punchTypeHidden = document.getElementById('punchType');
  const siteSelectDiv = document.getElementById('siteSelectDiv');
  const officeSelectDiv = document.getElementById('officeSelectDiv');
  const siteSelect = document.getElementById('siteSelect');
  const officeSelect = document.getElementById('officeSelect');

  if (punchTypeSite && siteSelectDiv) {
    punchTypeSite.addEventListener('change', function() {
      siteSelectDiv.style.display = 'block';
      if (officeSelectDiv) officeSelectDiv.style.display = 'none';
      if (punchTypeHidden) punchTypeHidden.value = 'site';
      
      // Manage required attributes
      if (siteSelect) siteSelect.required = true;
      if (officeSelect) officeSelect.required = false;
      
      if (currentLat && currentLng) validateCurrentLocationForPunchIn();
    });
  }

  if (punchTypeOffice && officeSelectDiv) {
    punchTypeOffice.addEventListener('change', function() {
      if (siteSelectDiv) siteSelectDiv.style.display = 'none';
      officeSelectDiv.style.display = 'block';
      if (punchTypeHidden) punchTypeHidden.value = 'office';
      
      // Manage required attributes
      if (siteSelect) siteSelect.required = false;
      if (officeSelect) officeSelect.required = true;
      
      if (currentLat && currentLng) validateCurrentLocationForPunchIn();
    });
  }

  if (siteSelect) {
    siteSelect.addEventListener('change', function() {
      if (currentLat && currentLng) validateCurrentLocationForPunchIn();
    });
  }
  
  if (officeSelect) {
    officeSelect.addEventListener('change', function() {
      if (currentLat && currentLng) validateCurrentLocationForPunchIn();
    });
  }

  if (refreshLocationBtn) {
    refreshLocationBtn.addEventListener('click', async function() {
      try {
        setRefreshButtonLoading(true);
        setLoadingStatus('Refreshing GPS location...');
        await fetchAndStoreLocation({ timeout: 30000, maximumAge: 0 });

        <?php if ($action === 'in'): ?>
          await validateCurrentLocationForPunchIn();
        <?php else: ?>
          await validateCurrentLocationForPunchOut();
        <?php endif; ?>
      } catch (err) {
        console.error('Refresh error:', err);
        let msg = 'Unable to refresh location.';
        if (err.code === 1) msg = 'Please allow location access in browser settings.';
        else if (err.code === 2) msg = 'Location unavailable. Check GPS.';
        else if (err.code === 3) msg = 'Location request timed out.';
        else msg += ' ' + (err.message || '');
        alert(msg);
      } finally {
        setRefreshButtonLoading(false);
      }
    });
  }

  // Handle Punch In Form
  if (form) {
    form.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const originalBtnText = submitBtn.innerHTML;
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Validating...';

      try {
        // First, handle the required field validation manually
        const punchType = document.querySelector('input[name="punch_type_radio_display"]:checked')?.value || 'site';
        const punchTypeHidden = document.getElementById('punchType');
        
        // Set the hidden punch type value
        if (punchTypeHidden) {
          punchTypeHidden.value = punchType;
        }

        // Temporarily remove required attributes from hidden fields
        const siteSelect = document.getElementById('siteSelect');
        const officeSelect = document.getElementById('officeSelect');
        
        if (siteSelect) siteSelect.required = false;
        if (officeSelect) officeSelect.required = false;

        // Now validate based on selected type
        if (punchType === 'site') {
          if (!siteSelect || !siteSelect.value) {
            alert('Please select a site');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            if (siteSelect) siteSelect.required = true;
            return false;
          }
          siteSelect.required = true;
        } else if (punchType === 'office') {
          if (!officeSelect || !officeSelect.value) {
            alert('Please select an office location');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            if (officeSelect) officeSelect.required = true;
            return false;
          }
          officeSelect.required = true;
        }

        // Re-fetch latest GPS just before submit
        await fetchAndStoreLocation({ timeout: 30000, maximumAge: 0 });

        // Validate based on action type
        await validateCurrentLocationForPunchIn();
        if (submitBtn.disabled) {
          setStatus('danger', '❌ Validation Failed', 'Please check your location and try again');
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
          
          if (siteSelect) siteSelect.required = (punchType === 'site');
          if (officeSelect) officeSelect.required = (punchType === 'office');
          return false;
        }

        setTimeout(() => {
          form.submit();
        }, 100);
        
      } catch (err) {
        console.error('Form submission error:', err);
        let msg = 'Unable to get your location. ';
        if (err.code === 1) msg += 'Please allow location access in browser settings.';
        else if (err.code === 2) msg += 'Location unavailable. Check GPS.';
        else if (err.code === 3) msg += 'Location request timed out.';
        else msg += err.message || 'Please try again.';
        
        alert(msg);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    });
  }

  // Handle Punch Out Form
  if (punchOutForm) {
    punchOutForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const originalBtnText = submitBtn.innerHTML;
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Validating...';

      try {
        // Re-fetch latest GPS just before submit
        await fetchAndStoreLocation({ timeout: 30000, maximumAge: 0 });

        // Validate location for punch out
        await validateCurrentLocationForPunchOut();
        if (submitBtn.disabled) {
          setStatus('danger', '❌ Validation Failed', 'You are outside the required radius');
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnText;
          return false;
        }

        setTimeout(() => {
          punchOutForm.submit();
        }, 100);
        
      } catch (err) {
        console.error('Punch out error:', err);
        let msg = 'Unable to get your location. ';
        if (err.code === 1) msg += 'Please allow location access in browser settings.';
        else if (err.code === 2) msg += 'Location unavailable. Check GPS.';
        else if (err.code === 3) msg += 'Location request timed out.';
        else msg += err.message || 'Please try again.';
        
        alert(msg);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    });
  }

  // Initial setup
  updateCurrentLocationUI();
  initializeLocation();

  // Set initial radio button state and required attributes
  if (punchTypeSite && punchTypeSite.checked) {
    if (punchTypeHidden) punchTypeHidden.value = 'site';
    if (siteSelect) siteSelect.required = true;
    if (officeSelect) officeSelect.required = false;
  } else if (punchTypeOffice && punchTypeOffice.checked) {
    if (punchTypeHidden) punchTypeHidden.value = 'office';
    if (siteSelect) siteSelect.required = false;
    if (officeSelect) officeSelect.required = true;
  }
});
</script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
  mysqli_close($conn);
}
?>