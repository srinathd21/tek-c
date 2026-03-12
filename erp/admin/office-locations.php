<?php
// hr/office-locations.php - Map View of All Office Locations
session_start();
require_once 'includes/db-config.php';

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

// ---------------- FETCH ALL OFFICES ----------------
$offices = [];
$query = "SELECT * FROM office_locations ORDER BY is_head_office DESC, is_active DESC, location_name ASC";
$result = mysqli_query($conn, $query);
if ($result) {
  while ($row = mysqli_fetch_assoc($result)) {
    $offices[] = $row;
  }
  mysqli_free_result($result);
}

// ---------------- STATISTICS ----------------
$stats = [
  'total' => count($offices),
  'active' => 0,
  'inactive' => 0,
  'head_office' => null
];

foreach ($offices as $office) {
  if ($office['is_active']) $stats['active']++;
  else $stats['inactive']++;
  
  if ($office['is_head_office']) {
    $stats['head_office'] = $office;
  }
}

// Prepare office data for JavaScript
$officeMarkers = [];
foreach ($offices as $office) {
  if ($office['latitude'] && $office['longitude']) {
    $officeMarkers[] = [
      'id' => $office['id'],
      'name' => $office['location_name'],
      'lat' => (float)$office['latitude'],
      'lng' => (float)$office['longitude'],
      'radius' => (int)$office['geo_fence_radius'],
      'address' => $office['address'],
      'is_head_office' => (bool)$office['is_head_office'],
      'is_active' => (bool)$office['is_active'],
      'created' => $office['created_at']
    ];
  }
}

$loggedName = $_SESSION['employee_name'] ?? 'Admin';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Office Locations Map - TEK-C</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

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

    #map-container {
      height: 500px;
      width: 100%;
      border-radius: 12px;
      border: 1px solid #e5e7eb;
      margin-bottom: 20px;
      z-index: 1;
    }

    .office-list {
      max-height: 400px;
      overflow-y: auto;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 0;
    }

    .office-list-item {
      padding: 12px 16px;
      border-bottom: 1px solid #e5e7eb;
      cursor: pointer;
      transition: all 0.2s;
    }
    .office-list-item:last-child {
      border-bottom: none;
    }
    .office-list-item:hover {
      background-color: #f9fafb;
    }
    .office-list-item.active {
      background-color: #eef2ff;
      border-left: 4px solid var(--blue);
    }
    .office-list-item.head-office {
      background-color: #fff9e6;
    }

    .office-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      margin-right: 6px;
    }
    .badge-active { background: #d1fae5; color: #065f46; }
    .badge-inactive { background: #fee2e2; color: #991b1b; }
    .badge-head { background: #fef3c7; color: #92400e; }

    .radius-info {
      font-size: 12px;
      color: #6b7280;
      margin-top: 4px;
    }
    .radius-info i {
      margin-right: 4px;
      color: var(--blue);
    }

    .info-window {
      padding: 8px;
      max-width: 250px;
    }
    .info-window h6 {
      font-weight: 900;
      margin-bottom: 8px;
      color: #1f2937;
    }
    .info-window p {
      margin-bottom: 4px;
      font-size: 12px;
    }
    .info-window .badge {
      font-size: 10px;
      padding: 4px 8px;
    }

    .toolbar {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    @media (max-width: 768px) {
      .content-scroll { padding: 12px; }
      #map-container { height: 350px; }
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
            <h1 class="h3 fw-bold mb-1">Office Locations Map</h1>
            <p class="text-muted mb-0">View all office locations with geo-fencing visualization</p>
          </div>
          <div>
            <a href="manage-offices.php" class="btn btn-outline-secondary me-2">
              <i class="bi bi-list-ul"></i> List View
            </a>
            <a href="add-office.php" class="btn btn-primary">
              <i class="bi bi-plus-circle"></i> Add Office
            </a>
          </div>
        </div>

        <!-- Statistics Row - Matching Your UI Pattern -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-building"></i></div>
              <div>
                <div class="stat-label">Total Offices</div>
                <div class="stat-value"><?= $stats['total'] ?></div>
              </div>
            </div>
          </div>
          
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-check-circle-fill"></i></div>
              <div>
                <div class="stat-label">Active Offices</div>
                <div class="stat-value"><?= $stats['active'] ?></div>
              </div>
            </div>
          </div>
          
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic orange"><i class="bi bi-x-circle-fill"></i></div>
              <div>
                <div class="stat-label">Inactive Offices</div>
                <div class="stat-value"><?= $stats['inactive'] ?></div>
              </div>
            </div>
          </div>
          
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic purple"><i class="bi bi-star-fill"></i></div>
              <div>
                <div class="stat-label">Head Office</div>
                <div class="stat-value"><?= $stats['head_office'] ? '1' : '0' ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Head Office Info (if set) -->
        <?php if ($stats['head_office']): ?>
          <div class="alert alert-warning d-flex align-items-center mb-4">
            <i class="bi bi-star-fill text-warning me-3 fs-4"></i>
            <div>
              <strong>Head Office:</strong> <?= e($stats['head_office']['location_name']) ?> 
              <span class="text-muted ms-2">(<?= e($stats['head_office']['address']) ?>)</span>
            </div>
          </div>
        <?php endif; ?>

        <!-- Main Content Row -->
        <div class="row g-4">
          <!-- Map Column -->
          <div class="col-lg-8">
            <div class="panel p-0" style="overflow: hidden;">
              <div id="map-container"></div>
            </div>
          </div>

          <!-- Office List Column -->
          <div class="col-lg-4">
            <div class="panel">
              <div class="panel-header">
                <h5 class="panel-title">
                  <i class="bi bi-building me-2"></i>Office Directory
                  <span class="badge bg-secondary ms-2"><?= count($offices) ?></span>
                </h5>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary" onclick="fitAllMarkers()" title="Fit all markers">
                    <i class="bi bi-arrows-fullscreen"></i>
                  </button>
                  <button class="btn btn-outline-primary" onclick="toggleHeatmap()" title="Toggle radius overlay">
                    <i class="bi bi-broadcast"></i>
                  </button>
                </div>
              </div>

              <!-- Search Box -->
              <div class="mb-3">
                <input type="text" class="form-control" id="officeSearch" 
                       placeholder="Search offices..." onkeyup="filterOffices()">
              </div>

              <!-- Office List -->
              <div class="office-list" id="officeList">
                <?php if (empty($offices)): ?>
                  <div class="text-center text-muted py-4">
                    <i class="bi bi-building fs-2 d-block mb-2"></i>
                    No offices found.<br>
                    <a href="add-office.php" class="small">Add your first office</a>
                  </div>
                <?php else: ?>
                  <?php foreach ($offices as $office): ?>
                    <div class="office-list-item <?= $office['is_head_office'] ? 'head-office' : '' ?>" 
                         data-id="<?= $office['id'] ?>"
                         data-lat="<?= $office['latitude'] ?>"
                         data-lng="<?= $office['longitude'] ?>"
                         onclick="focusOffice(<?= $office['id'] ?>)">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <span class="fw-bold"><?= e($office['location_name']) ?></span>
                          <?php if ($office['is_head_office']): ?>
                            <span class="office-badge badge-head">
                              <i class="bi bi-star-fill"></i> Head
                            </span>
                          <?php endif; ?>
                          <?php if ($office['is_active']): ?>
                            <span class="office-badge badge-active">
                              <i class="bi bi-check-circle"></i> Active
                            </span>
                          <?php else: ?>
                            <span class="office-badge badge-inactive">
                              <i class="bi bi-x-circle"></i> Inactive
                            </span>
                          <?php endif; ?>
                        </div>
                        <small class="text-muted">#<?= $office['id'] ?></small>
                      </div>
                      <div class="small text-muted mt-1">
                        <i class="bi bi-geo-alt"></i> 
                        <?= substr(e($office['address']), 0, 60) ?><?= strlen($office['address']) > 60 ? '...' : '' ?>
                      </div>
                      <div class="radius-info">
                        <i class="bi bi-broadcast"></i> Radius: <?= (int)$office['geo_fence_radius'] ?>m
                        <span class="float-end">
                          <i class="bi bi-geo"></i> 
                          <?= number_format((float)$office['latitude'], 4) ?>, <?= number_format((float)$office['longitude'], 4) ?>
                        </span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <!-- Legend -->
              <div class="mt-3 pt-2 border-top">
                <div class="small fw-bold mb-2">Map Legend:</div>
                <div class="d-flex flex-wrap gap-3">
                  <div><span class="office-badge badge-active">●</span> Active Office</div>
                  <div><span class="office-badge badge-inactive">●</span> Inactive Office</div>
                  <div><span class="office-badge badge-head"><i class="bi bi-star-fill"></i></span> Head Office</div>
                  <div><span class="badge bg-white text-dark border"><i class="bi bi-broadcast text-primary"></i></span> Geo-fence</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
          <div class="col-12">
            <div class="panel">
              <div class="panel-header">
                <h5 class="panel-title">Quick Actions</h5>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <a href="add-office.php" class="btn btn-primary">
                  <i class="bi bi-plus-circle"></i> Add New Office
                </a>
                <a href="manage-offices.php" class="btn btn-outline-secondary">
                  <i class="bi bi-list-ul"></i> Manage Offices
                </a>
                <button class="btn btn-outline-secondary" onclick="centerMap()">
                  <i class="bi bi-crosshair"></i> Center Map
                </button>
                <button class="btn btn-outline-secondary" onclick="refreshMarkers()">
                  <i class="bi bi-arrow-clockwise"></i> Refresh Markers
                </button>
                <button class="btn btn-outline-secondary" onclick="toggleAllRadii()" id="toggleRadiiBtn">
                  <i class="bi bi-eye"></i> Hide All Radii
                </button>
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
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($google_maps_api_key) ?>&libraries=places,geometry"></script>

<script>
// Office data from PHP
const offices = <?= json_encode($officeMarkers) ?>;
let map;
let markers = [];
let circles = [];
let activeInfoWindow = null;
let showRadii = true;
let bounds;

// Initialize map
function initMap() {
  // Default center (India)
  const defaultCenter = { lat: 20.5937, lng: 78.9629 };
  
  map = new google.maps.Map(document.getElementById('map-container'), {
    center: defaultCenter,
    zoom: 5,
    mapTypeId: google.maps.MapTypeId.ROADMAP,
    mapTypeControl: true,
    streetViewControl: true,
    fullscreenControl: true,
    zoomControl: true
  });

  bounds = new google.maps.LatLngBounds();
  
  // Add all office markers
  offices.forEach(office => {
    addOfficeMarker(office);
  });

  // Fit map to show all markers
  if (offices.length > 0) {
    map.fitBounds(bounds);
    
    // Don't zoom in too far
    google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
      if (map.getZoom() > 15) {
        map.setZoom(15);
      }
    });
  }

  // Add click listener to close info windows
  map.addListener('click', function() {
    if (activeInfoWindow) {
      activeInfoWindow.close();
    }
  });
}

// Add marker for an office
function addOfficeMarker(office) {
  const position = { lat: office.lat, lng: office.lng };
  
  // Choose marker icon based on office type and status
  let icon = {
    url: office.is_head_office 
      ? 'https://maps.google.com/mapfiles/ms/icons/star.png'
      : (office.is_active 
          ? 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png'
          : 'https://maps.google.com/mapfiles/ms/icons/grey.png'),
    scaledSize: new google.maps.Size(40, 40)
  };

  const marker = new google.maps.Marker({
    position: position,
    map: map,
    title: office.name,
    icon: icon,
    animation: google.maps.Animation.DROP,
    id: office.id
  });

  // Create info window content
  const infoContent = `
    <div class="info-window">
      <h6>${office.name}</h6>
      <p><i class="bi bi-geo-alt"></i> ${office.address}</p>
      <p><i class="bi bi-broadcast"></i> Radius: ${office.radius}m</p>
      <p>
        <span class="badge ${office.is_active ? 'bg-success' : 'bg-secondary'}">
          ${office.is_active ? 'Active' : 'Inactive'}
        </span>
        ${office.is_head_office ? '<span class="badge bg-warning text-dark ms-1"><i class="bi bi-star-fill"></i> Head Office</span>' : ''}
      </p>
      <p class="text-muted small mb-0">Lat: ${office.lat.toFixed(6)}<br>Lng: ${office.lng.toFixed(6)}</p>
      <button class="btn btn-sm btn-outline-primary mt-2 w-100" onclick="getDirections(${office.lat}, ${office.lng})">
        <i class="bi bi-signpost"></i> Get Directions
      </button>
    </div>
  `;

  const infoWindow = new google.maps.InfoWindow({
    content: infoContent,
    maxWidth: 250
  });

  marker.addListener('click', function() {
    if (activeInfoWindow) {
      activeInfoWindow.close();
    }
    infoWindow.open(map, marker);
    activeInfoWindow = infoWindow;
    
    // Highlight in list
    highlightOfficeInList(office.id);
  });

  markers.push(marker);
  bounds.extend(position);

  // Add radius circle if active and showRadii is true
  if (office.is_active && showRadii) {
    addRadiusCircle(office);
  }
}

// Add radius circle for an office
function addRadiusCircle(office) {
  const circle = new google.maps.Circle({
    map: map,
    center: { lat: office.lat, lng: office.lng },
    radius: office.radius,
    fillColor: office.is_head_office ? '#fbbf24' : '#3b82f6',
    fillOpacity: 0.1,
    strokeColor: office.is_head_office ? '#d97706' : '#2563eb',
    strokeOpacity: 0.5,
    strokeWeight: 2,
    clickable: false,
    id: office.id
  });
  
  circles.push(circle);
}

// Highlight office in list
function highlightOfficeInList(officeId) {
  document.querySelectorAll('.office-list-item').forEach(item => {
    item.classList.remove('active');
  });
  
  const target = document.querySelector(`.office-list-item[data-id="${officeId}"]`);
  if (target) {
    target.classList.add('active');
    target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

// Focus on specific office
function focusOffice(officeId) {
  const office = offices.find(o => o.id === officeId);
  if (!office) return;
  
  map.setCenter({ lat: office.lat, lng: office.lng });
  map.setZoom(17);
  
  // Trigger click on marker
  const marker = markers.find(m => m.id === officeId);
  if (marker) {
    google.maps.event.trigger(marker, 'click');
  }
}

// Fit map to show all markers
function fitAllMarkers() {
  if (offices.length > 0) {
    map.fitBounds(bounds);
  }
}

// Center map (reset to default)
function centerMap() {
  if (offices.length > 0) {
    fitAllMarkers();
  } else {
    map.setCenter({ lat: 20.5937, lng: 78.9629 });
    map.setZoom(5);
  }
}

// Refresh markers (reload from server)
function refreshMarkers() {
  location.reload();
}

// Toggle all radius circles
function toggleAllRadii() {
  showRadii = !showRadii;
  const btn = document.getElementById('toggleRadiiBtn');
  
  circles.forEach(circle => {
    circle.setMap(showRadii ? map : null);
  });
  
  btn.innerHTML = showRadii ? 
    '<i class="bi bi-eye"></i> Hide All Radii' : 
    '<i class="bi bi-eye-slash"></i> Show All Radii';
}

// Alias for toggleAllRadii (for backward compatibility)
function toggleHeatmap() {
  toggleAllRadii();
}

// Filter offices by search
function filterOffices() {
  const searchTerm = document.getElementById('officeSearch').value.toLowerCase();
  const items = document.querySelectorAll('.office-list-item');
  
  items.forEach(item => {
    const text = item.textContent.toLowerCase();
    if (text.includes(searchTerm)) {
      item.style.display = 'block';
    } else {
      item.style.display = 'none';
    }
  });
}

// Get directions to office
function getDirections(lat, lng) {
  const url = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
  window.open(url, '_blank');
}

// Initialize map when API loads
window.initMap = initMap;

// Add event listener for window resize
window.addEventListener('resize', function() {
  if (map) {
    google.maps.event.trigger(map, 'resize');
  }
});
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