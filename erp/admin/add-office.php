<?php
// hr/add-office.php - Add New Office Location
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

// ---------------- GOOGLE MAPS API KEY ----------------
$google_maps_api_key = 'AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE'; // Move to config file

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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
  $is_active = isset($_POST['is_active']) ? 1 : 1; // Default to active if not set
  
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
  
  // Check for duplicate location name
  if (empty($errors)) {
    $check_stmt = mysqli_prepare($conn, "SELECT id FROM office_locations WHERE location_name = ?");
    mysqli_stmt_bind_param($check_stmt, "s", $location_name);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
      $errors[] = "An office with this name already exists.";
    }
    mysqli_stmt_close($check_stmt);
  }
  
  // If no errors, insert into database
  if (empty($errors)) {
    $stmt = mysqli_prepare($conn, "
      INSERT INTO office_locations 
      (location_name, address, latitude, longitude, geo_fence_radius, is_head_office, is_active, created_at, updated_at) 
      VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    mysqli_stmt_bind_param($stmt, "ssddiii", 
      $location_name, 
      $address, 
      $latitude, 
      $longitude, 
      $geo_fence_radius, 
      $is_head_office, 
      $is_active
    );
    
    if (mysqli_stmt_execute($stmt)) {
      $office_id = mysqli_insert_id($conn);
      
      // If this is set as head office, unset any other head offices
      if ($is_head_office) {
        $update_stmt = mysqli_prepare($conn, "UPDATE office_locations SET is_head_office = 0 WHERE id != ?");
        mysqli_stmt_bind_param($update_stmt, "i", $office_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
      }
      
      // Log activity
      logActivity(
        $conn,
        'CREATE',
        'office',
        "Added new office location: {$location_name}",
        $office_id,
        $location_name,
        null,
        json_encode([
          'location_name' => $location_name,
          'address' => $address,
          'latitude' => $latitude,
          'longitude' => $longitude,
          'radius' => $geo_fence_radius,
          'is_head_office' => $is_head_office
        ])
      );
      
      $_SESSION['flash_success'] = "Office location '{$location_name}' added successfully.";
      header("Location: manage-offices.php");
      exit;
    } else {
      $message = "Database error: " . mysqli_stmt_error($stmt);
      $messageType = "danger";
    }
    mysqli_stmt_close($stmt);
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
  <title>Add Office Location - TEK-C</title>
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
            <h1 class="h3 fw-bold mb-1">Add Office Location</h1>
            <p class="text-muted mb-0">Create a new office location for attendance geo-fencing</p>
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

        <!-- Flash Message from Session -->
        <?php if (isset($_SESSION['flash_success'])): ?>
          <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

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
                         value="<?= e($_POST['location_name'] ?? '') ?>" 
                         placeholder="e.g., Head Office, Branch Office, Regional Office" required>
                  <small class="text-muted">A unique name to identify this office location</small>
                </div>

                <!-- Address -->
                <div class="mb-3">
                  <label class="form-label required">Address</label>
                  <div class="input-group mb-2">
                    <input type="text" name="address" id="address" class="form-control" 
                           value="<?= e($_POST['address'] ?? '') ?>" 
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
                           step="any" value="<?= e($_POST['latitude'] ?? '') ?>" readonly required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label required">Longitude</label>
                    <input type="number" name="longitude" id="longitude" class="form-control" 
                           step="any" value="<?= e($_POST['longitude'] ?? '') ?>" readonly required>
                  </div>
                </div>

                <!-- Geo-fence Radius -->
                <div class="mb-3">
                  <label class="form-label required">Geo-fence Radius (meters)</label>
                  <div class="d-flex align-items-center gap-3">
                    <input type="range" name="geo_fence_radius" id="radiusSlider" class="radius-slider" 
                           min="10" max="500" value="<?= e($_POST['geo_fence_radius'] ?? '100') ?>" step="5">
                    <span class="radius-value" id="radiusDisplay">100 m</span>
                  </div>
                  <input type="hidden" name="geo_fence_radius_hidden" id="geo_fence_radius" value="100">
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
                             <?= (isset($_POST['is_head_office']) && $_POST['is_head_office']) ? 'checked' : '' ?>>
                      <label class="form-check-label fw-bold" for="is_head_office">
                        <i class="bi bi-star-fill text-warning"></i> Set as Head Office
                      </label>
                      <small class="text-muted d-block">Only one location can be head office</small>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                      <label class="form-check-label fw-bold" for="is_active">
                        <i class="bi bi-check-circle-fill text-success"></i> Active
                      </label>
                      <small class="text-muted d-block">Inactive offices cannot be used for attendance</small>
                    </div>
                  </div>
                </div>

                <!-- Submit Buttons -->
                <div class="d-flex gap-2 mt-4">
                  <button type="submit" class="btn btn-primary" style="font-weight:800; padding:12px 24px;">
                    <i class="bi bi-save me-2"></i>Save Office Location
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
              <h6 class="fw-bold mb-2">About Office Locations</h6>
              <p class="small mb-2">Office locations are used for:</p>
              <ul class="small mb-0 ps-3">
                <li>Attendance geo-fencing validation</li>
                <li>Office punch-in/out for managers & HR</li>
                <li>Location tracking for reports</li>
                <li>Head office designation for company details</li>
              </ul>
            </div>

            <!-- Radius Guide -->
            <div class="panel mt-3 p-3">
              <h6 class="fw-bold mb-2"><i class="bi bi-rulers me-2"></i>Radius Guide</h6>
              <div class="mb-2">
                <div class="d-flex justify-content-between">
                  <span>Small building:</span>
                  <span class="fw-bold">30-50m</span>
                </div>
                <div class="progress mb-2" style="height:6px;">
                  <div class="progress-bar bg-info" style="width:20%"></div>
                </div>
              </div>
              <div class="mb-2">
                <div class="d-flex justify-content-between">
                  <span>Medium complex:</span>
                  <span class="fw-bold">50-100m</span>
                </div>
                <div class="progress mb-2" style="height:6px;">
                  <div class="progress-bar bg-primary" style="width:40%"></div>
                </div>
              </div>
              <div class="mb-2">
                <div class="d-flex justify-content-between">
                  <span>Large campus:</span>
                  <span class="fw-bold">100-200m</span>
                </div>
                <div class="progress mb-2" style="height:6px;">
                  <div class="progress-bar bg-success" style="width:70%"></div>
                </div>
              </div>
              <div class="mb-2">
                <div class="d-flex justify-content-between">
                  <span>Industrial area:</span>
                  <span class="fw-bold">200-500m</span>
                </div>
                <div class="progress mb-2" style="height:6px;">
                  <div class="progress-bar bg-warning" style="width:90%"></div>
                </div>
              </div>
            </div>

            <!-- Preview Card -->
            <div class="panel mt-3 p-3" id="previewPanel" style="display:none;">
              <h6 class="fw-bold mb-2"><i class="bi bi-eye me-2"></i>Location Preview</h6>
              <div class="location-preview" id="locationPreview"></div>
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

// Initialize map
function initMap() {
  const defaultLocation = { lat: 12.9716, lng: 77.5946 }; // Bangalore as default
  
  map = new google.maps.Map(document.getElementById('map'), {
    center: defaultLocation,
    zoom: 15,
    mapTypeId: google.maps.MapTypeId.ROADMAP,
    mapTypeControl: true,
    streetViewControl: true,
    fullscreenControl: true
  });

  geocoder = new google.maps.Geocoder();
  
  // Create marker but don't place it yet
  marker = new google.maps.Marker({
    map: map,
    draggable: true,
    animation: google.maps.Animation.DROP
  });

  // Add click listener to map
  map.addListener('click', function(event) {
    placeMarker(event.latLng);
    updateCoordinates(event.latLng);
    getAddressFromLatLng(event.latLng);
  });

  // Add drag end listener to marker
  marker.addListener('dragend', function(event) {
    updateCoordinates(event.latLng);
    getAddressFromLatLng(event.latLng);
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
    
    // Update address field with formatted address
    if (place.formatted_address) {
      document.getElementById('address').value = place.formatted_address;
    }
  });

  // Check if we have existing coordinates from POST
  const lat = <?= isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0 ?>;
  const lng = <?= isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0 ?>;
  
  if (lat && lng) {
    const pos = { lat: parseFloat(lat), lng: parseFloat(lng) };
    map.setCenter(pos);
    map.setZoom(17);
    placeMarker(pos);
  }
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

// Update radius display
function updateRadius() {
  const slider = document.getElementById('radiusSlider');
  const display = document.getElementById('radiusDisplay');
  const hidden = document.getElementById('geo_fence_radius');
  
  const val = slider.value;
  display.textContent = val + ' m';
  hidden.value = val;
  
  drawRadiusCircle(val);
}

// Draw radius circle on map
let radiusCircle = null;
function drawRadiusCircle(radius) {
  if (!marker.getPosition()) return;
  
  if (radiusCircle) {
    radiusCircle.setMap(null);
  }
  
  radiusCircle = new google.maps.Circle({
    strokeColor: '#3b82f6',
    strokeOpacity: 0.5,
    strokeWeight: 2,
    fillColor: '#3b82f6',
    fillOpacity: 0.1,
    map: map,
    center: marker.getPosition(),
    radius: parseInt(radius)
  });
}

// Update preview panel
function updatePreview() {
  const lat = document.getElementById('latitude').value;
  const lng = document.getElementById('longitude').value;
  const name = document.getElementById('location_name').value;
  const address = document.getElementById('address').value;
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
      <div class="preview-item">
        <span class="preview-label">Address:</span>
        <span class="preview-value">${address.substring(0, 50)}${address.length > 50 ? '...' : ''}</span>
      </div>
    `;
    
    document.getElementById('locationPreview').innerHTML = previewHtml;
    document.getElementById('previewPanel').style.display = 'block';
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

  // Initialize radius display
  updateRadius();
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