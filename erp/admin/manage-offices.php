<?php
// hr/manage-offices.php - Manage Office Locations
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

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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
    '<span class="badge bg-light text-dark"><i class="bi bi-building"></i> Branch</span>';
}

// ---------------- HANDLE ACTIONS ----------------
$message = '';
$messageType = '';

// Toggle active status
if (isset($_GET['toggle']) && isset($_GET['id'])) {
  $officeId = (int)$_GET['id'];
  $newStatus = (int)$_GET['toggle'];
  
  $stmt = mysqli_prepare($conn, "UPDATE office_locations SET is_active = ?, updated_at = NOW() WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "ii", $newStatus, $officeId);
  
  if (mysqli_stmt_execute($stmt)) {
    // Get office name for log
    $name_stmt = mysqli_prepare($conn, "SELECT location_name FROM office_locations WHERE id = ?");
    mysqli_stmt_bind_param($name_stmt, "i", $officeId);
    mysqli_stmt_execute($name_stmt);
    $name_res = mysqli_stmt_get_result($name_stmt);
    $office = mysqli_fetch_assoc($name_res);
    mysqli_stmt_close($name_stmt);
    
    logActivity(
      $conn,
      'UPDATE',
      'office',
      ($newStatus ? 'Activated' : 'Deactivated') . " office: {$office['location_name']}",
      $officeId,
      $office['location_name']
    );
    
    $_SESSION['flash_success'] = "Office status updated successfully.";
  } else {
    $_SESSION['flash_error'] = "Failed to update office status.";
  }
  
  header("Location: manage-offices.php");
  exit;
}

// Set as head office
if (isset($_GET['set_head']) && isset($_GET['id'])) {
  $officeId = (int)$_GET['id'];
  
  // First, unset any existing head office
  $reset_stmt = mysqli_prepare($conn, "UPDATE office_locations SET is_head_office = 0");
  mysqli_stmt_execute($reset_stmt);
  mysqli_stmt_close($reset_stmt);
  
  // Set new head office
  $set_stmt = mysqli_prepare($conn, "UPDATE office_locations SET is_head_office = 1, updated_at = NOW() WHERE id = ?");
  mysqli_stmt_bind_param($set_stmt, "i", $officeId);
  
  if (mysqli_stmt_execute($set_stmt)) {
    // Get office name for log
    $name_stmt = mysqli_prepare($conn, "SELECT location_name FROM office_locations WHERE id = ?");
    mysqli_stmt_bind_param($name_stmt, "i", $officeId);
    mysqli_stmt_execute($name_stmt);
    $name_res = mysqli_stmt_get_result($name_stmt);
    $office = mysqli_fetch_assoc($name_res);
    mysqli_stmt_close($name_stmt);
    
    logActivity(
      $conn,
      'UPDATE',
      'office',
      "Set as Head Office: {$office['location_name']}",
      $officeId,
      $office['location_name']
    );
    
    $_SESSION['flash_success'] = "Head office updated successfully.";
  } else {
    $_SESSION['flash_error'] = "Failed to update head office.";
  }
  
  header("Location: manage-offices.php");
  exit;
}

// Delete office
if (isset($_GET['delete']) && isset($_GET['id'])) {
  $officeId = (int)$_GET['id'];
  
  // Check if this office is used in attendance records
  $check_stmt = mysqli_prepare($conn, "SELECT COUNT(*) as c FROM attendance WHERE punch_in_office_id = ? OR punch_out_office_id = ?");
  mysqli_stmt_bind_param($check_stmt, "ii", $officeId, $officeId);
  mysqli_stmt_execute($check_stmt);
  $check_res = mysqli_stmt_get_result($check_stmt);
  $check_row = mysqli_fetch_assoc($check_res);
  mysqli_stmt_close($check_stmt);
  
  if ($check_row['c'] > 0) {
    $_SESSION['flash_error'] = "Cannot delete office because it has associated attendance records. Deactivate it instead.";
  } else {
    // Get office name before deletion
    $name_stmt = mysqli_prepare($conn, "SELECT location_name, is_head_office FROM office_locations WHERE id = ?");
    mysqli_stmt_bind_param($name_stmt, "i", $officeId);
    mysqli_stmt_execute($name_stmt);
    $name_res = mysqli_stmt_get_result($name_stmt);
    $office = mysqli_fetch_assoc($name_res);
    mysqli_stmt_close($name_stmt);
    
    if ($office && $office['is_head_office']) {
      $_SESSION['flash_error'] = "Cannot delete head office. Set another office as head office first.";
    } else {
      $delete_stmt = mysqli_prepare($conn, "DELETE FROM office_locations WHERE id = ?");
      mysqli_stmt_bind_param($delete_stmt, "i", $officeId);
      
      if (mysqli_stmt_execute($delete_stmt)) {
        logActivity(
          $conn,
          'DELETE',
          'office',
          "Deleted office: {$office['location_name']}",
          $officeId,
          $office['location_name']
        );
        
        $_SESSION['flash_success'] = "Office deleted successfully.";
      } else {
        $_SESSION['flash_error'] = "Failed to delete office.";
      }
      mysqli_stmt_close($delete_stmt);
    }
  }
  
  header("Location: manage-offices.php");
  exit;
}

// ---------------- FILTERS ----------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name_asc';

// ---------------- FETCH OFFICES ----------------
$query = "SELECT * FROM office_locations WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
  $query .= " AND (location_name LIKE ? OR address LIKE ?)";
  $searchTerm = "%{$search}%";
  $params[] = $searchTerm;
  $params[] = $searchTerm;
  $types .= "ss";
}

if ($status === 'active') {
  $query .= " AND is_active = 1";
} elseif ($status === 'inactive') {
  $query .= " AND is_active = 0";
}

// Sorting
switch ($sort) {
  case 'name_asc':
    $query .= " ORDER BY location_name ASC";
    break;
  case 'name_desc':
    $query .= " ORDER BY location_name DESC";
    break;
  case 'date_asc':
    $query .= " ORDER BY created_at ASC";
    break;
  case 'date_desc':
    $query .= " ORDER BY created_at DESC";
    break;
  case 'radius_asc':
    $query .= " ORDER BY geo_fence_radius ASC";
    break;
  case 'radius_desc':
    $query .= " ORDER BY geo_fence_radius DESC";
    break;
  default:
    $query .= " ORDER BY is_head_office DESC, is_active DESC, location_name ASC";
}

$offices = [];
$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
  if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
  }
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  while ($row = mysqli_fetch_assoc($res)) {
    $offices[] = $row;
  }
  mysqli_stmt_close($stmt);
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

$loggedName = $_SESSION['employee_name'] ?? 'Admin';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Offices - TEK-C</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />
  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />

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
    .filter-card{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:20px; }

    .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid #e5e7eb!important; }
    .table td{ vertical-align:middle; border-color:#e5e7eb; font-weight:600; color:#374151; padding:14px 8px; }

    .action-btn{ width:32px; height:32px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; 
      display:inline-flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; margin:0 2px; }
    .action-btn:hover{ background:#f3f4f6; color:#374151; }

    .office-coord{ font-size:12px; color:#6b7280; font-family:monospace; }

    .radius-badge{ background:#e6f7ff; color:#0066cc; padding:4px 8px; border-radius:20px; font-weight:700; font-size:12px; }

    .head-office-row{ background: rgba(255,215,0,0.05); border-left:4px solid #ffc107; }

    .toolbar{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }

    @media (max-width: 768px) {
      .content-scroll{ padding:12px; }
      .stat-card{ margin-bottom:12px; }
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
            <h1 class="h3 fw-bold mb-1">Manage Offices</h1>
            <p class="text-muted mb-0">View and manage all office locations</p>
          </div>
          <div>
            <a href="office-locations.php" class="btn btn-outline-primary me-2">
              <i class="bi bi-map"></i> View Map
            </a>
            <a href="add-office.php" class="btn btn-primary">
              <i class="bi bi-plus-circle"></i> Add New Office
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

        <!-- Statistics Row -->
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
          <div class="alert alert-warning d-flex align-items-center mb-3">
            <i class="bi bi-star-fill text-warning me-3 fs-4"></i>
            <div>
              <strong>Head Office:</strong> <?= e($stats['head_office']['location_name']) ?> 
              (<?= e($stats['head_office']['address']) ?>)
            </div>
          </div>
        <?php endif; ?>

        <!-- Filter Card -->
        <div class="filter-card">
          <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
              <label class="form-label fw-bold">Search</label>
              <input type="text" name="search" class="form-control" value="<?= e($search) ?>" 
                     placeholder="Search by name or address...">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Status</label>
              <select name="status" class="form-select">
                <option value="">All Offices</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Sort By</label>
              <select name="sort" class="form-select">
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Newest First</option>
                <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Oldest First</option>
                <option value="radius_desc" <?= $sort === 'radius_desc' ? 'selected' : '' ?>>Radius (Largest)</option>
                <option value="radius_asc" <?= $sort === 'radius_asc' ? 'selected' : '' ?>>Radius (Smallest)</option>
              </select>
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-funnel"></i> Apply
              </button>
            </div>
          </form>
        </div>

        <!-- Offices Table -->
        <div class="panel">
          <div class="panel-header">
            <h5 class="panel-title">
              <i class="bi bi-building me-2"></i>Office Locations
              <span class="badge bg-secondary ms-2"><?= count($offices) ?></span>
            </h5>
          </div>

          <div class="table-responsive">
            <table class="table align-middle" id="officesTable">
              <thead>
                <tr>
                  <th style="min-width:200px;">Office Name</th>
                  <th style="min-width:300px;">Address</th>
                  <th style="min-width:120px;">Coordinates</th>
                  <th style="min-width:100px;">Radius</th>
                  <th style="min-width:120px;">Type</th>
                  <th style="min-width:100px;">Status</th>
                  <th style="min-width:160px;">Created</th>
                  <th style="width:140px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($offices)): ?>
                  <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                      <i class="bi bi-building fs-3 d-block mb-2"></i>
                      No office locations found.
                      <a href="add-office.php" class="d-block mt-2">Add your first office</a>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($offices as $office): ?>
                    <tr class="<?= $office['is_head_office'] ? 'head-office-row' : '' ?>">
                      <td>
                        <div class="fw-bold"><?= e($office['location_name']) ?></div>
                        <?php if ($office['is_head_office']): ?>
                          <span class="badge bg-warning text-dark mt-1">
                            <i class="bi bi-star-fill"></i> Head Office
                          </span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div><?= e($office['address']) ?></div>
                      </td>
                      <td>
                        <div class="office-coord">
                          <i class="bi bi-geo-alt"></i> <?= number_format((float)$office['latitude'], 6) ?><br>
                          <i class="bi bi-geo-alt"></i> <?= number_format((float)$office['longitude'], 6) ?>
                        </div>
                      </td>
                      <td>
                        <span class="radius-badge">
                          <i class="bi bi-broadcast"></i> <?= (int)$office['geo_fence_radius'] ?>m
                        </span>
                      </td>
                      <td>
                        <?= headOfficeBadge($office['is_head_office']) ?>
                      </td>
                      <td>
                        <?= $office['is_active'] ? 
                          '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Active</span>' : 
                          '<span class="badge bg-secondary"><i class="bi bi-x-circle"></i> Inactive</span>' ?>
                      </td>
                      <td>
                        <div><?= safeDate($office['created_at']) ?></div>
                        <small class="text-muted"><?= safeDateTime($office['updated_at'] ?? $office['created_at']) ?></small>
                      </td>
                      <td>
                        <div class="d-flex gap-1">
                          <a href="edit-office.php?id=<?= $office['id'] ?>" class="action-btn" title="Edit">
                            <i class="bi bi-pencil"></i>
                          </a>
                          
                          <?php if (!$office['is_head_office']): ?>
                            <a href="?set_head=1&id=<?= $office['id'] ?>" class="action-btn text-warning" 
                               title="Set as Head Office" onclick="return confirm('Set this as the head office? This will remove head office status from current head office.')">
                              <i class="bi bi-star"></i>
                            </a>
                          <?php endif; ?>
                          
                          <a href="?toggle=<?= $office['is_active'] ? 0 : 1 ?>&id=<?= $office['id'] ?>" 
                             class="action-btn <?= $office['is_active'] ? 'text-danger' : 'text-success' ?>" 
                             title="<?= $office['is_active'] ? 'Deactivate' : 'Activate' ?>">
                            <i class="bi <?= $office['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                          </a>
                          
                          <?php if (!$office['is_head_office']): ?>
                            <a href="?delete=1&id=<?= $office['id'] ?>" class="action-btn text-danger" 
                               title="Delete" onclick="return confirm('Are you sure you want to delete this office? This action cannot be undone.')">
                              <i class="bi bi-trash"></i>
                            </a>
                          <?php endif; ?>
                          
                          <a href="office-details.php?id=<?= $office['id'] ?>" class="action-btn" title="View Details">
                            <i class="bi bi-eye"></i>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Export Options -->
        <div class="d-flex justify-content-end gap-2 mt-3">
          <button class="btn btn-outline-secondary btn-sm" onclick="exportTableToCSV()">
            <i class="bi bi-download"></i> Export to CSV
          </button>
          <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
          </button>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(document).ready(function() {
  <?php if (!empty($offices)): ?>
  $('#officesTable').DataTable({
    pageLength: 25,
    searching: false,
    ordering: false,
    info: true,
    language: {
      info: "Showing _START_ to _END_ of _TOTAL_ offices",
      infoEmpty: "No offices to show",
      infoFiltered: "(filtered from _MAX_ total offices)"
    }
  });
  <?php endif; ?>
});

// Export to CSV
function exportTableToCSV() {
  const rows = document.querySelectorAll('#officesTable tbody tr');
  const csv = [];
  
  // Headers
  const headers = ['Office Name', 'Address', 'Latitude', 'Longitude', 'Radius (m)', 'Type', 'Status', 'Created'];
  csv.push(headers.join(','));
  
  // Data rows
  rows.forEach(row => {
    if (row.cells.length === 8) {
      const rowData = [
        '"' + row.cells[0].innerText.replace(/"/g, '""') + '"',
        '"' + row.cells[1].innerText.replace(/"/g, '""') + '"',
        row.cells[2].innerText.trim().split('\n')[0].replace('i bi-geo-alt', '').trim(),
        row.cells[2].innerText.trim().split('\n')[1].replace('i bi-geo-alt', '').trim(),
        row.cells[3].innerText.replace('i bi-broadcast', '').replace('m', '').trim(),
        row.cells[4].innerText.trim(),
        row.cells[5].innerText.trim(),
        row.cells[6].innerText.trim()
      ];
      csv.push(rowData.join(','));
    }
  });
  
  const csvString = csv.join('\n');
  const blob = new Blob([csvString], { type: 'text/csv' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'offices_export_' + new Date().toISOString().slice(0,10) + '.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  window.URL.revokeObjectURL(url);
}
</script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
  mysqli_close($conn);
}
?>