<?php
// hr/edit-office.php - Edit Office Location
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
$office_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($office_id <= 0) {
  $_SESSION['flash_error'] = "Invalid office ID.";
  header("Location: manage-offices.php");
  exit;
}

// ---------------- GOOGLE MAPS API KEY ----------------
$google_maps_api_key = 'AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE'; // Move to config file

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------------- FETCH OFFICE DATA ----------------
$office = null;
$stmt = mysqli_prepare($conn, "SELECT * FROM office_locations WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $office_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$office = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$office) {
  $_SESSION['flash_error'] = "Office not found.";
  header("Location: manage-offices.php");
  exit;
}

// ---------------- HANDLE FORM SUBMISSION ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $location_name = trim($_POST['location_name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $latitude = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0;
  $longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0;
  $geo_fence_radius = (int)($_POST['geo_fence_radius'] ?? 100);
  $is_head_office = isset($_POST['is_head_office']) ? 1 : 0;
  $is_active = isset($_POST['is_active']) ? 1 : 0;
  
  $errors = [];
  
  // Validation
  if (empty($location_name)) {
    $errors[] = "Office location name is required.";
  }
  
  if (empty($address)) {
    $errors[] = "Address is required.";
  }
  
  if ($latitude == 0 || $longitude == 0) {
    $errors[] = "Please select a location on the map or use the search to get coordinates.";
  }
  
  if ($geo_fence_radius < 10 || $geo_fence_radius > 1000) {
    $errors[] = "Geo-fence radius must be between 10 and 1000 meters.";
  }
  
  // Check for duplicate location name (excluding current office)
  if (empty($errors)) {
    $check_stmt = mysqli_prepare($conn, "SELECT id FROM office_locations WHERE location_name = ? AND id != ?");
    mysqli_stmt_bind_param($check_stmt, "si", $location_name, $office_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
      $errors[] = "An office with this name already exists.";
    }
    mysqli_stmt_close($check_stmt);
  }
  
  // If no errors, update database
  if (empty($errors)) {
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
      // If setting as head office, unset any other head offices
      if ($is_head_office && !$office['is_head_office']) {
        $update_stmt = mysqli_prepare($conn, "UPDATE office_locations SET is_head_office = 0 WHERE id != ?");
        mysqli_stmt_bind_param($update_stmt, "i", $office_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
      }
      
      // Update office
      $stmt = mysqli_prepare($conn, "
        UPDATE office_locations SET 
          location_name = ?,
          address = ?,
          latitude = ?,
          longitude = ?,
          geo_fence_radius = ?,
          is_head_office = ?,
          is_active = ?,
          updated_at = NOW()
        WHERE id = ?
      ");
      
      mysqli_stmt_bind_param($stmt, "ssddiiii", 
        $location_name,
        $address,
        $latitude,
        $longitude,
        $geo_fence_radius,
        $is_head_office,
        $is_active,
        $office_id
      );
      
      if (mysqli_stmt_execute($stmt)) {
        // Log activity
        logActivity(
          $conn,
          'UPDATE',
          'office',
          "Updated office location: {$location_name}",
          $office_id,
          $location_name,
          json_encode([
            'old' => [
              'name' => $office['location_name'],
              'address' => $office['address'],
              'latitude' => $office['latitude'],
              'longitude' => $office['longitude'],
              'radius' => $office['geo_fence_radius'],
              'is_head_office' => $office['is_head_office'],
              'is_active' => $office['is_active']
            ],
            'new' => [
              'name' => $location_name,
              'address' => $address,
              'latitude' => $latitude,
              'longitude' => $longitude,
              'radius' => $geo_fence_radius,
              'is_head_office' => $is_head_office,
              'is_active' => $is_active
            ]
          ])
        );
        
        mysqli_commit($conn);
        $_SESSION['flash_success'] = "Office location '{$location_name}' updated successfully.";
        header("Location: manage-offices.php");
        exit;
      } else {
        throw new Exception(mysqli_stmt_error($stmt));
      }
      mysqli_stmt_close($stmt);
      
    } catch (Exception $e) {
      mysqli_rollback($conn);
      $message = "Database error: " . $e->getMessage();
      $messageType = "danger";
    }
  } else {
    $message = implode("<br>", $errors);
    $messageType = "danger";
  }
}

$loggedName = $_SESSION['employee_name'] ?? 'Admin';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Office - <?= e($office['location_name']) ?> - TEK-C</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($google_maps_api_key) ?>&libraries=places,geometry"></script>

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px; }
    .panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 8px 24px rgba(17,24,39,.06); padding:24px; }
    .panel-header{ margin-bottom:20px; }
    .panel-title{ font-weight:900; font-size:20px; color:#1f2937; margin:0; }

    .form-label{ font-weight:800; font-size:13px; color:#4b5563; margin-bottom:6px; }
    .form-control, .form-select{ border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font-weight:500; }
    .form-control:focus, .form-select:focus{ border-color: #3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }

    .map-container{ height:300px; border-radius:12px; border:1px solid #e5e7eb; margin:20px 0; }

    .location-preview{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-top:20px; }
    .preview-item{ display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #e5e7eb; }
    .preview-item:last-child{ border-bottom:0; }
    .preview-label{ font-weight:700; color:#6b7280; }
    .preview-value{ font-weight:800; color:#1f2937; }

    .radius-slider{ width:100%; margin:10px 0; }
    .radius-value{ font-size:18px; font-weight:900; color:#3b82f6; }

    .info-card{ background:#eef2ff; border-radius:12px; padding:16px; margin-bottom:20px; }
    .info-icon{ width:40px; height:40px; border-radius:10px; background:#3b82f6; color:white; display:grid; place-items:center; font-size:20px; margin-bottom:12px; }

    .form-check-input{ width:20px; height:20px; margin-top:2px; border:2px solid #d1d5db; }
    .form-check-input:checked{ background-color:#3b82f6; border-color:#3b82f6; }

    .required::after{ content:" *"; color:#ef4444; font-weight:800; }

    .badge-active{ background: #d1fae5; color: #065f46; padding:4px 8px; border-radius:20px; font-size:12px; font-weight:700; }
    .badge-inactive{ background: #fee2e2; color: #991b1b; padding:4px 8px; border-radius:20px; font-size:12px; font-weight:700; }
    .badge-head{ background: #fef3c7; color: #92400e; padding:4px 8px; border-radius:20px; font-size:12px; font-weight:700; }

    @media (max-width: 768px) {
      .content-scroll{ padding:12px; }
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
          <div>
            <h1 class="h3 fw-bold mb-1">Edit Office Location</h1>
            <p class="text-muted mb-0">Update office details and geo-fencing settings</p>
          </div>
          <div>
            <a href="manage-offices.php" class="btn btn-outline-secondary me-2">
              <i class="bi bi-arrow-left"></i> Back to Offices
            </a>
            <a href="office-locations.php" class="btn btn-outline-primary">
              <i class="bi bi-map"></i> View Map
            </a>
          </div>
        </div>

        <!-- Alert Message -->
        <?php if (!empty($message)): ?>
          <div class="alert alert-<?= $messageType ?> alert-dismissible fade show mb-3" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Current Status Banner -->
        <div class="alert alert-info d-flex align-items-center mb-3">
          <i class="bi bi-info-circle-fill me-3 fs-4"></i>
          <div>
            <strong>Current Status:</strong> 
            <?php if ($office['is_head_office']): ?>
              <span class="badge-head ms-2"><i class="bi bi-star-fill"></i> Head Office</span>
            <?php endif; ?>
            <?php if ($office['is_active']): ?>
              <span class="badge-active ms-2"><i class="bi bi-check-circle"></i> Active</span>
            <?php else: ?>
              <span class="badge-inactive ms-2"><i class="bi bi-x-circle"></i> Inactive</span>
            <?php endif; ?>
            <span class="ms-3"><i class="bi bi-building"></i> ID: #<?= $office['id'] ?></span>
            <span class="ms-3"><i class="bi bi-calendar"></i> Created: <?= date('d M Y', strtotime($office['created_at'])) ?></span>
          </div>
        </div>

        <div class="row g-4">
          <!-- Main Form -->
          <div class="col-lg-8">
            <div class="panel">
              <div class="panel-header">
                <h5 class="panel-title">
                  <i class="bi bi-building me-2"></i>Office Details
                </h5>
              </div>

              <form method="POST" action="" id="officeForm">
                <!-- Location Name -->
                <div class="mb-3">
                  <label class="form-label required">Office Location Name</label>
                  <input type="text" name="location_name" id="location_name" class="form-control" 
                         value="<?= e($_POST['location_name'] ?? $office['location_name']) ?>" 
                         placeholder="e.g., Head Office, Branch Office, Regional Office" required>
                </div>

                <!-- Address -->
                <div class="mb-3">
                  <label class="form-label required">Address</label>
                  <div class="input-group mb-2">
                    <input type="text" name="address" id="address" class="form-control" 
                           value="<?= e($_POST['address'] ?? $office['address']) ?>" 
                           placeholder="Enter full address" required>
                    <button class="btn btn-outline-primary" type="button" id="searchAddressBtn">
                      <i class="bi bi-search"></i> Search
                    </button>
                  </div>
                  <small class="text-muted">Enter address and click Search to locate on map</small>
                </div>

                <!-- Map Container -->
                <div id="map" class="map-container"></div>

                <!-- Coordinates Row -->
                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label class="form-label required">Latitude</label>
                    <input type="number" name="latitude" id="latitude" class="form-control" 
                           step="any" value="<?= e($_POST['latitude'] ?? $office['latitude']) ?>" readonly required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label required">Longitude</label>
                    <input type="number" name="longitude" id="longitude" class="form-control" 
                           step="any" value="<?= e($_POST['longitude'] ?? $office['longitude']) ?>" readonly required>
                  </div>
                </div>

                <!-- Geo-fence Radius -->
                <div class="mb-3">
                  <label class="form-label required">Geo-fence Radius (meters)</label>
                  <div class="d-flex align-items-center gap-3">
                    <input type="range" name="geo_fence_radius" id="radiusSlider" class="radius-slider" 
                           min="10" max="500" value="<?= (int)($_POST['geo_fence_radius'] ?? $office['geo_fence_radius']) ?>" step="5">
                    <span class="radius-value" id="radiusDisplay"><?= (int)($office['geo_fence_radius'] ?? 100) ?> m</span>
                  </div>
                  <input type="hidden" name="geo_fence_radius_hidden" id="geo_fence_radius" value="<?= (int)($office['geo_fence_radius'] ?? 100) ?>">
                  <small class="text-muted">
                    <i class="bi bi-info-circle"></i> 
                    Employees must be within this radius to punch in/out from this office
                  </small>
                </div>

                <!-- Options Row -->
                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_head_office" id="is_head_office" value="1"
                             <?= (isset($_POST['is_head_office']) ? $_POST['is_head_office'] : $office['is_head_office']) ? 'checked' : '' ?>>
                      <label class="form-check-label fw-bold" for="is_head_office">
                        <i class="bi bi-star-fill text-warning"></i> Set as Head Office
                      </label>
                      <?php if ($office['is_head_office']): ?>
                        <small class="text-muted d-block text-warning">
                          <i class="bi bi-exclamation-triangle"></i> This is currently the head office. Unchecking will remove this status.
                        </small>
                      <?php else: ?>
                        <small class="text-muted d-block">Only one location can be head office</small>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                             <?= (isset($_POST['is_active']) ? $_POST['is_active'] : $office['is_active']) ? 'checked' : '' ?>>
                      <label class="form-check-label fw-bold" for="is_active">
                        <i class="bi bi-check-circle-fill text-success"></i> Active
                      </label>
                      <small class="text-muted d-block">Inactive offices cannot be used for attendance</small>
                    </div>
                  </div>
                </div>

                <!-- Last Updated Info -->
                <?php if ($office['updated_at'] != $office['created_at']): ?>
                  <div class="text-muted small mb-3">
                    <i class="bi bi-clock-history"></i> Last updated: <?= date('d M Y, h:i A', strtotime($office['updated_at'])) ?>
                  </div>
                <?php endif; ?>

                <!-- Submit Buttons -->
                <div class="d-flex gap-2 mt-4">
                  <button type="submit" class="btn btn-primary" style="font-weight:800; padding:12px 24px;">
                    <i class="bi bi-save me-2"></i>Update Office
                  </button>
                  <button type="button" class="btn btn-outline-secondary" onclick="getCurrentLocation()" style="font-weight:800;">
                    <i class="bi bi-crosshair me-2"></i>Use My Location
                  </button>
                  <a href="manage-offices.php" class="btn btn-outline-secondary" style="font-weight:800;">Cancel</a>
                </div>
              </form>
            </div>
          </div>

          <!-- Sidebar Info -->
          <div class="col-lg-4">
            <!-- Info Card -->
            <div class="info-card">
              <div class="info-icon">
                <i class="bi bi-info-lg"></i>
              </div>
              <h6 class="fw-bold mb-2">About Geo-fencing</h6>
              <p class="small mb-2">The radius determines how close employees need to be to mark attendance:</p>
              <ul class="small mb-0 ps-3">
                <li><span class="fw-bold">10-50m:</span> Single building</li>
                <li><span class="fw-bold">50-100m:</span> Small campus</li>
                <li><span class="fw-bold">100-200m:</span> Large campus</li>
                <li><span class="fw-bold">200-500m:</span> Industrial area</li>
              </ul>
            </div>

            <!-- Office Stats -->
            <div class="panel mt-3 p-3">
              <h6 class="fw-bold mb-2"><i class="bi bi-graph-up me-2"></i>Office Statistics</h6>
              <div class="mb-2">
                <div class="d-flex justify-content-between">
                  <span>Total check-ins:</span>
                  <span class="fw-bold">—</span>
                </div>
                <div class="d-flex justify-content-between">
                  <span>Last used:</span>
                  <span class="fw-bold">—</span>
                </div>
                <div class="d-flex justify-content-between">
                  <span>Active employees:</span>
                  <span class="fw-bold">—</span>
                </div>
              </div>
              <small class="text-muted">Stats coming soon</small>
            </div>

            <!-- Preview Card -->
            <div class="panel mt-3 p-3" id="previewPanel">
              <h6 class="fw-bold mb-2"><i class="bi bi-eye me-2"></i>Location Preview</h6>
              <div class="location-preview" id="locationPreview">
                <div class="preview-item">
                  <span class="preview-label">Name:</span>
                  <span class="preview-value"><?= e($office['location_name']) ?></span>
                </div>
                <div class="preview-item">
                  <span class="preview-label">Coordinates:</span>
                  <span class="preview-value"><?= number_format((float)$office['latitude'], 6) ?>, <?= number_format((float)$office['longitude'], 6) ?></span>
                </div>
                <div class="preview-item">
                  <span class="preview-label">Radius:</span>
                  <span class="preview-value"><?= (int)$office['geo_fence_radius'] ?>m</span>
                </div>
                <div class="preview-item">
                  <span class="preview-label">Type:</span>
                  <span class="preview-value"><?= $office['is_head_office'] ? '🏢 Head Office' : '🏢 Branch Office' ?></span>
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
let map;
let marker;
let geocoder;
let searchBox;
let radiusCircle = null;

// Initialize map with office location
function initMap() {
  const officeLat = <?= $office['latitude'] ?>;
  const officeLng = <?= $office['longitude'] ?>;
  const officeLocation = { lat: officeLat, lng: officeLng };
  
  map = new google.maps.Map(document.getElementById('map'), {
    center: officeLocation,
    zoom: 17,
    mapTypeId: google.maps.MapTypeId.ROADMAP,
    mapTypeControl: true,
    streetViewControl: true,
    fullscreenControl: true
  });

  geocoder = new google.maps.Geocoder();
  
  // Place marker at office location
  marker = new google.maps.Marker({
    position: officeLocation,
    map: map,
    draggable: true,
    animation: google.maps.Animation.DROP
  });

  // Draw initial radius
  updateRadiusCircle();

  // Add click listener to map
  map.addListener('click', function(event) {
    placeMarker(event.latLng);
    updateCoordinates(event.latLng);
    getAddressFromLatLng(event.latLng);
    updateRadiusCircle();
  });

  // Add drag end listener to marker
  marker.addListener('dragend', function(event) {
    updateCoordinates(event.latLng);
    getAddressFromLatLng(event.latLng);
    updateRadiusCircle();
  });

  // Initialize search box
  const addressInput = document.getElementById('address');
  searchBox = new google.maps.places.SearchBox(addressInput);

  // Bias search results to map viewport
  map.addListener('bounds_changed', function() {
    searchBox.setBounds(map.getBounds());
  });

  // Listen for place selection
  searchBox.addListener('places_changed', function() {
    const places = searchBox.getPlaces();
    if (places.length === 0) return;

    const place = places[0];
    if (!place.geometry) return;

    // Center map on selected place
    if (place.geometry.viewport) {
      map.fitBounds(place.geometry.viewport);
    } else {
      map.setCenter(place.geometry.location);
      map.setZoom(17);
    }

    // Place marker
    placeMarker(place.geometry.location);
    updateCoordinates(place.geometry.location);
    updateRadiusCircle();
    
    // Update address field with formatted address
    if (place.formatted_address) {
      document.getElementById('address').value = place.formatted_address;
    }
  });
}

// Place marker on map
function placeMarker(location) {
  marker.setPosition(location);
  marker.setMap(map);
  map.panTo(location);
}

// Update coordinate fields
function updateCoordinates(location) {
  document.getElementById('latitude').value = location.lat().toFixed(8);
  document.getElementById('longitude').value = location.lng().toFixed(8);
  updatePreview();
}

// Get address from coordinates
function getAddressFromLatLng(latlng) {
  geocoder.geocode({ location: latlng }, function(results, status) {
    if (status === 'OK' && results[0]) {
      document.getElementById('address').value = results[0].formatted_address;
    }
  });
}

// Get user's current location
function getCurrentLocation() {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      function(position) {
        const pos = {
          lat: position.coords.latitude,
          lng: position.coords.longitude
        };
        
        map.setCenter(pos);
        map.setZoom(18);
        placeMarker(pos);
        updateCoordinates(pos);
        getAddressFromLatLng(pos);
        updateRadiusCircle();
      },
      function(error) {
        let errorMessage = 'Error getting location: ';
        switch(error.code) {
          case error.PERMISSION_DENIED:
            errorMessage += 'Permission denied';
            break;
          case error.POSITION_UNAVAILABLE:
            errorMessage += 'Position unavailable';
            break;
          case error.TIMEOUT:
            errorMessage += 'Request timeout';
            break;
        }
        alert(errorMessage);
      }
    );
  } else {
    alert('Geolocation is not supported by this browser.');
  }
}

// Update radius display and circle
function updateRadius() {
  const slider = document.getElementById('radiusSlider');
  const display = document.getElementById('radiusDisplay');
  const hidden = document.getElementById('geo_fence_radius');
  
  const val = slider.value;
  display.textContent = val + ' m';
  hidden.value = val;
  
  updateRadiusCircle();
}

// Draw/update radius circle on map
function updateRadiusCircle() {
  if (!marker.getPosition()) return;
  
  if (radiusCircle) {
    radiusCircle.setMap(null);
  }
  
  const radius = parseInt(document.getElementById('radiusSlider').value);
  
  radiusCircle = new google.maps.Circle({
    strokeColor: '#3b82f6',
    strokeOpacity: 0.5,
    strokeWeight: 2,
    fillColor: '#3b82f6',
    fillOpacity: 0.1,
    map: map,
    center: marker.getPosition(),
    radius: radius
  });
}

// Update preview panel
function updatePreview() {
  const lat = document.getElementById('latitude').value;
  const lng = document.getElementById('longitude').value;
  const name = document.getElementById('location_name').value;
  const radius = document.getElementById('radiusSlider').value;
  const isHeadOffice = document.getElementById('is_head_office').checked;
  
  if (lat && lng && name) {
    const previewHtml = `
      <div class="preview-item">
        <span class="preview-label">Name:</span>
        <span class="preview-value">${name}</span>
      </div>
      <div class="preview-item">
        <span class="preview-label">Coordinates:</span>
        <span class="preview-value">${parseFloat(lat).toFixed(6)}, ${parseFloat(lng).toFixed(6)}</span>
      </div>
      <div class="preview-item">
        <span class="preview-label">Radius:</span>
        <span class="preview-value">${radius}m</span>
      </div>
      <div class="preview-item">
        <span class="preview-label">Type:</span>
        <span class="preview-value">${isHeadOffice ? '🏢 Head Office' : '🏢 Branch Office'}</span>
      </div>
    `;
    
    document.getElementById('locationPreview').innerHTML = previewHtml;
  }
}

// Add event listeners
document.addEventListener('DOMContentLoaded', function() {
  // Radius slider
  const radiusSlider = document.getElementById('radiusSlider');
  radiusSlider.addEventListener('input', updateRadius);
  
  // Location name change
  document.getElementById('location_name').addEventListener('input', updatePreview);
  
  // Head office checkbox
  document.getElementById('is_head_office').addEventListener('change', updatePreview);
  
  // Active checkbox
  document.getElementById('is_active').addEventListener('change', updatePreview);
  
  // Search button
  document.getElementById('searchAddressBtn').addEventListener('click', function() {
    const address = document.getElementById('address').value;
    if (address) {
      geocoder.geocode({ address: address }, function(results, status) {
        if (status === 'OK' && results[0]) {
          map.setCenter(results[0].geometry.location);
          map.setZoom(17);
          placeMarker(results[0].geometry.location);
          updateCoordinates(results[0].geometry.location);
          updateRadiusCircle();
        } else {
          alert('Address not found: ' + status);
        }
      });
    }
  });

  // Form validation
  document.getElementById('officeForm').addEventListener('submit', function(e) {
    const lat = document.getElementById('latitude').value;
    const lng = document.getElementById('longitude').value;
    const name = document.getElementById('location_name').value;
    
    if (!lat || !lng) {
      e.preventDefault();
      alert('Please select a location on the map');
      return;
    }
    
    if (!name) {
      e.preventDefault();
      alert('Please enter an office location name');
      return;
    }
  });

  // Confirm head office change
  const headOfficeCheckbox = document.getElementById('is_head_office');
  const wasHeadOffice = <?= $office['is_head_office'] ? 'true' : 'false' ?>;
  
  headOfficeCheckbox.addEventListener('change', function(e) {
    if (!wasHeadOffice && this.checked) {
      if (!confirm('Setting this as head office will remove head office status from the current head office. Continue?')) {
        this.checked = false;
      }
    }
  });
});

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