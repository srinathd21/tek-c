<?php
// dealers.php (Manager) — manage all dealers/vendors
// Follows same UI template as my-manager-sites.php

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$dealers = [];

// ---------- Auth (Manager only) ----------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Allow managers and directors
$allowed = [
  'manager',
  'director',
  'vice president',
  'general manager'
];
if (!in_array($designation, $allowed, true)) {
  header("Location: index.php");
  exit;
}

// ---------- Handle Actions ----------
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $dealer_id = intval($_GET['id']);
    
    if ($action === 'delete') {
        // Soft delete or deactivate dealer
        $update_query = "UPDATE quotation_dealers SET status = 'Inactive' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "i", $dealer_id);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Dealer deactivated successfully.";
        } else {
            $error = "Failed to deactivate dealer.";
        }
        mysqli_stmt_close($stmt);
    } elseif ($action === 'activate') {
        $update_query = "UPDATE quotation_dealers SET status = 'Active' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "i", $dealer_id);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Dealer activated successfully.";
        } else {
            $error = "Failed to activate dealer.";
        }
        mysqli_stmt_close($stmt);
    }
}

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function getDealerTypeBadge($types) {
    if (empty($types)) return '<span class="badge bg-secondary">—</span>';
    
    $type_array = explode(',', $types);
    $badges = [];
    foreach ($type_array as $type) {
        $type = trim($type);
        $color = 'bg-secondary';
        if ($type === 'Electrical') $color = 'bg-warning';
        elseif ($type === 'Plumbing') $color = 'bg-info';
        elseif ($type === 'Civil') $color = 'bg-primary';
        elseif ($type === 'Steel') $color = 'bg-danger';
        elseif ($type === 'Cement') $color = 'bg-dark';
        elseif ($type === 'Woodwork') $color = 'bg-success';
        $badges[] = '<span class="badge ' . $color . ' me-1">' . $type . '</span>';
    }
    return implode(' ', $badges);
}

function getStatusBadge($status) {
    if ($status === 'Active') {
        return '<span class="badge bg-success">Active</span>';
    } elseif ($status === 'Inactive') {
        return '<span class="badge bg-secondary">Inactive</span>';
    } elseif ($status === 'Blacklisted') {
        return '<span class="badge bg-danger">Blacklisted</span>';
    }
    return '<span class="badge bg-secondary">' . $status . '</span>';
}

// ---------- Fetch all dealers ----------
$sql = "
  SELECT 
    d.*,
    (SELECT COUNT(*) FROM quotation_requests_dealers qrd WHERE qrd.dealer_id = d.id) AS request_count,
    (SELECT COUNT(*) FROM quotations q WHERE q.dealer_id = d.id) AS quotation_count,
    (SELECT COUNT(*) FROM quotation_dealer_contacts dc WHERE dc.dealer_id = d.id AND dc.is_primary = 1) AS has_primary_contact
  FROM quotation_dealers d
  WHERE d.created_by = ? OR ? = 1
  ORDER BY 
    CASE 
      WHEN d.status = 'Active' THEN 1
      WHEN d.status = 'Inactive' THEN 2
      ELSE 3
    END,
    d.dealer_name ASC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
  $error = "Database error: " . mysqli_error($conn);
} else {
    $is_admin = 1; // For now, allow all managers to see all dealers
    mysqli_stmt_bind_param($stmt, "ii", $empId, $is_admin);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $dealers = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

// ---------- Stats ----------
$total_dealers = count($dealers);
$active_count = 0;
$inactive_count = 0;
$blacklisted_count = 0;
$total_quotations = 0;

foreach ($dealers as $d) {
    if ($d['status'] === 'Active') $active_count++;
    elseif ($d['status'] === 'Inactive') $inactive_count++;
    elseif ($d['status'] === 'Blacklisted') $blacklisted_count++;
    
    $total_quotations += intval($d['quotation_count'] ?? 0);
}

// Get status message if any
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dealers Management - TEK-C</title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- DataTables (Bootstrap 5 + Responsive) -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

  <!-- Select2 for better dropdowns -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-ic.purple{ background: #8b5cf6; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table-responsive { overflow-x: hidden !important; }
    table.dataTable { width:100% !important; }
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:800;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: normal !important;
    }
    .table td{
      vertical-align: middle; border-color: var(--border);
      font-weight:650; color:#374151;
      padding: 10px 10px !important;
      white-space: normal !important;
      word-break: break-word;
    }

    .btn-action {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 10px;
      color: var(--muted);
      font-size: 12px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      font-weight: 900;
    }
    .btn-action:hover { background: var(--bg); color: var(--blue); }
    .btn-action.edit{
      border-color: rgba(45,156,219,.25);
    }
    .btn-action.delete{
      border-color: rgba(239,68,68,.25);
    }
    .btn-action.contacts{
      border-color: rgba(16,185,129,.25);
    }

    .proj-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
    .proj-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.25; }

    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 10px;
      font-weight: 650;
      outline: none;
    }
    div.dataTables_wrapper .dataTables_filter input:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
    }
    .dataTables_paginate .pagination .page-link{
      border-radius: 10px;
      margin: 0 3px;
      font-weight: 750;
    }
    th.actions-col, td.actions-col { width: 150px !important; }

    /* ---------- Mobile Cards ---------- */
    .dealer-card{
      border:1px solid var(--border);
      border-radius: 16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding: 12px;
    }
    .dealer-card .top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }
    .dealer-card .title{
      font-weight:1000;
      color:#111827;
      font-size: 14px;
      line-height:1.2;
      margin:0;
    }
    .dealer-card .meta{
      margin-top:6px;
      display:flex;
      flex-wrap:wrap;
      gap:8px 10px;
      color:#6b7280;
      font-weight:800;
      font-size:12px;
    }
    .dealer-kv{ margin-top:10px; display:grid; gap:8px; }
    .dealer-row{ display:flex; gap:10px; align-items:flex-start; }
    .dealer-key{
      flex:0 0 80px;
      color:#6b7280;
      font-weight:1000;
      font-size:12px;
    }
    .dealer-val{
      flex:1 1 auto;
      font-weight:900;
      color:#111827;
      font-size:12.5px;
      line-height:1.3;
      word-break: break-word;
    }
    .dealer-actions{
      margin-top:12px;
      display:flex;
      gap:8px;
      justify-content:flex-end;
    }
    .dealer-actions a, .dealer-actions button{ 
      padding: 6px 12px; 
      border-radius:10px; 
      justify-content:center;
      white-space: nowrap;
    }
    .type-badge{
      display: inline-block;
      padding: 2px 8px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 900;
      background: #e8f0fe;
      color: var(--blue);
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
      .dealer-actions { flex-wrap: wrap; }
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

        <!-- Status Messages -->
        <?php if ($status && $message): ?>
          <div class="alert alert-<?php echo $status === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-<?php echo $status === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

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

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Dealers & Vendors</h1>
            <p class="text-muted mb-0">Manage all suppliers and vendors for quotations</p>
          </div>
          <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDealerModal">
              <i class="bi bi-plus-circle"></i> Add New Dealer
            </button>
          </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-shop"></i></div>
              <div>
                <div class="stat-label">Total Dealers</div>
                <div class="stat-value"><?php echo (int)$total_dealers; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
              <div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?php echo (int)$active_count; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-clock"></i></div>
              <div>
                <div class="stat-label">Inactive</div>
                <div class="stat-value"><?php echo (int)$inactive_count; ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic purple"><i class="bi bi-file-text"></i></div>
              <div>
                <div class="stat-label">Total Quotations</div>
                <div class="stat-value"><?php echo (int)$total_quotations; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Dealers Directory -->
        <div class="panel mb-4">
          <div class="panel-header">
            <h3 class="panel-title">Dealer Directory</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <!-- MOBILE: Cards -->
          <div class="d-block d-md-none">
            <div class="d-grid gap-3">
              <?php if (empty($dealers)): ?>
                <div class="text-center py-4 text-muted">
                  <i class="bi bi-shop" style="font-size: 48px;"></i>
                  <p class="mt-2 fw-bold">No dealers found</p>
                  <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#addDealerModal">
                    <i class="bi bi-plus-circle"></i> Add your first dealer
                  </button>
                </div>
              <?php else: ?>
                <?php foreach ($dealers as $dealer): ?>
                  <div class="dealer-card">
                    <div class="top">
                      <div style="flex:1 1 auto;">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                          <h4 class="title"><?php echo e($dealer['dealer_name']); ?></h4>
                          <?php echo getStatusBadge($dealer['status']); ?>
                        </div>
                        
                        <div class="meta">
                          <span><i class="bi bi-code"></i> <?php echo e($dealer['dealer_code']); ?></span>
                          <?php if (!empty($dealer['city'])): ?>
                            <span><i class="bi bi-geo-alt"></i> <?php echo e($dealer['city']); ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <div class="dealer-kv">
                      <?php if (!empty($dealer['contact_person'])): ?>
                      <div class="dealer-row">
                        <div class="dealer-key">Contact</div>
                        <div class="dealer-val"><?php echo e($dealer['contact_person']); ?></div>
                      </div>
                      <?php endif; ?>

                      <div class="dealer-row">
                        <div class="dealer-key">Phone</div>
                        <div class="dealer-val"><?php echo e($dealer['mobile_number']); ?></div>
                      </div>

                      <?php if (!empty($dealer['email'])): ?>
                      <div class="dealer-row">
                        <div class="dealer-key">Email</div>
                        <div class="dealer-val"><?php echo e($dealer['email']); ?></div>
                      </div>
                      <?php endif; ?>

                      <?php if (!empty($dealer['gst_number'])): ?>
                      <div class="dealer-row">
                        <div class="dealer-key">GST</div>
                        <div class="dealer-val"><?php echo e($dealer['gst_number']); ?></div>
                      </div>
                      <?php endif; ?>

                      <?php if (!empty($dealer['dealer_type'])): ?>
                      <div class="dealer-row">
                        <div class="dealer-key">Types</div>
                        <div class="dealer-val"><?php echo getDealerTypeBadge($dealer['dealer_type']); ?></div>
                      </div>
                      <?php endif; ?>

                      <div class="dealer-row">
                        <div class="dealer-key">Activity</div>
                        <div class="dealer-val">
                          <span class="badge bg-info rounded-pill me-1"><?php echo intval($dealer['request_count'] ?? 0); ?> requests</span>
                          <span class="badge bg-success rounded-pill"><?php echo intval($dealer['quotation_count'] ?? 0); ?> quotes</span>
                        </div>
                      </div>
                    </div>

                    <div class="dealer-actions">
                      <button type="button" class="btn-action edit" onclick="editDealer(<?php echo $dealer['id']; ?>)" title="Edit">
                        <i class="bi bi-pencil"></i> Edit
                      </button>
                      <button type="button" class="btn-action contacts" onclick="manageContacts(<?php echo $dealer['id']; ?>)" title="Contacts">
                        <i class="bi bi-people"></i> Contacts
                      </button>
                      <?php if ($dealer['status'] === 'Active'): ?>
                        <a href="?action=delete&id=<?php echo $dealer['id']; ?>" class="btn-action delete" onclick="return confirm('Are you sure you want to deactivate this dealer?')" title="Deactivate">
                          <i class="bi bi-pause-circle"></i> Deactivate
                        </a>
                      <?php else: ?>
                        <a href="?action=activate&id=<?php echo $dealer['id']; ?>" class="btn-action edit" onclick="return confirm('Activate this dealer?')" title="Activate">
                          <i class="bi bi-play-circle"></i> Activate
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- DESKTOP/TABLET: DataTable -->
          <div class="d-none d-md-block">
            <div class="table-responsive">
              <table id="dealersTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Dealer Name</th>
                    <th>Contact Person</th>
                    <th>Phone / Email</th>
                    <th>Location</th>
                    <th>GST/PAN</th>
                    <th>Types</th>
                    <th>Activity</th>
                    <th>Status</th>
                    <th class="text-end actions-col">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($dealers as $dealer): ?>
                  <tr>
                    <td>
                      <span class="fw-800"><?php echo e($dealer['dealer_code']); ?></span>
                    </td>
                    <td>
                      <div class="proj-title"><?php echo e($dealer['dealer_name']); ?></div>
                      <?php if (!empty($dealer['payment_terms'])): ?>
                        <div class="proj-sub">Terms: <?php echo e($dealer['payment_terms']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($dealer['contact_person'])): ?>
                        <div class="fw-700"><?php echo e($dealer['contact_person']); ?></div>
                        <?php if ($dealer['has_primary_contact']): ?>
                          <span class="badge bg-success bg-opacity-10 text-success">Primary</span>
                        <?php endif; ?>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                    <td>
                      <div><i class="bi bi-telephone"></i> <?php echo e($dealer['mobile_number']); ?></div>
                      <?php if (!empty($dealer['alternate_phone'])): ?>
                        <div class="proj-sub">Alt: <?php echo e($dealer['alternate_phone']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($dealer['email'])): ?>
                        <div class="proj-sub"><i class="bi bi-envelope"></i> <?php echo e($dealer['email']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($dealer['city'])): ?>
                        <div><?php echo e($dealer['city']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($dealer['state'])): ?>
                        <div class="proj-sub"><?php echo e($dealer['state']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($dealer['gst_number'])): ?>
                        <div>GST: <?php echo e($dealer['gst_number']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($dealer['pan_number'])): ?>
                        <div class="proj-sub">PAN: <?php echo e($dealer['pan_number']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php echo getDealerTypeBadge($dealer['dealer_type']); ?>
                    </td>
                    <td>
                      <span class="badge bg-info rounded-pill me-1" title="Quotation Requests"><?php echo intval($dealer['request_count'] ?? 0); ?></span>
                      <span class="badge bg-success rounded-pill" title="Quotations"><?php echo intval($dealer['quotation_count'] ?? 0); ?></span>
                    </td>
                    <td><?php echo getStatusBadge($dealer['status']); ?></td>
                    <td class="text-end actions-col">
                      <button type="button" class="btn-action edit" onclick="editDealer(<?php echo $dealer['id']; ?>)" title="Edit">
                        <i class="bi bi-pencil"></i>
                      </button>
                      <button type="button" class="btn-action contacts" onclick="manageContacts(<?php echo $dealer['id']; ?>)" title="Manage Contacts">
                        <i class="bi bi-people"></i>
                      </button>
                      <?php if ($dealer['status'] === 'Active'): ?>
                        <a href="?action=delete&id=<?php echo $dealer['id']; ?>" class="btn-action delete" onclick="return confirm('Are you sure you want to deactivate this dealer?')" title="Deactivate">
                          <i class="bi bi-pause-circle"></i>
                        </a>
                      <?php else: ?>
                        <a href="?action=activate&id=<?php echo $dealer['id']; ?>" class="btn-action edit" onclick="return confirm('Activate this dealer?')" title="Activate">
                          <i class="bi bi-play-circle"></i>
                        </a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- Recent Activity Panel -->
        <div class="panel">
          <div class="panel-header">
            <h3 class="panel-title">Quick Actions</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>
          <div class="row g-3">
            <div class="col-md-3">
              <button type="button" class="btn btn-outline-secondary w-100" data-bs-toggle="modal" data-bs-target="#importDealersModal">
                <i class="bi bi-upload"></i> Import Dealers
              </button>
            </div>
            <div class="col-md-3">
              <a href="export-dealers.php" class="btn btn-outline-secondary w-100">
                <i class="bi bi-download"></i> Export List
              </a>
            </div>
            <div class="col-md-3">
              <button type="button" class="btn btn-outline-secondary w-100" onclick="window.location.href='dealer-categories.php'">
                <i class="bi bi-tags"></i> Manage Categories
              </button>
            </div>
            <div class="col-md-3">
              <button type="button" class="btn btn-outline-secondary w-100" onclick="window.location.href='dealer-performance.php'">
                <i class="bi bi-graph-up"></i> Performance Report
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- Add Dealer Modal -->
<div class="modal fade" id="addDealerModal" tabindex="-1" aria-labelledby="addDealerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-900" id="addDealerModalLabel">Add New Dealer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="process-dealer.php" method="POST">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label required">Dealer Code</label>
              <input type="text" class="form-control" name="dealer_code" required placeholder="e.g., DL-001">
            </div>
            <div class="col-md-6">
              <label class="form-label required">Dealer Name</label>
              <input type="text" class="form-control" name="dealer_name" required placeholder="Full name / Company name">
            </div>
            <div class="col-md-6">
              <label class="form-label">Contact Person</label>
              <input type="text" class="form-control" name="contact_person" placeholder="Primary contact name">
            </div>
            <div class="col-md-6">
              <label class="form-label required">Mobile Number</label>
              <input type="text" class="form-control" name="mobile_number" required placeholder="10 digit mobile">
            </div>
            <div class="col-md-6">
              <label class="form-label">Alternate Phone</label>
              <input type="text" class="form-control" name="alternate_phone" placeholder="Alternate number">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="email" placeholder="email@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label">GST Number</label>
              <input type="text" class="form-control" name="gst_number" placeholder="15 digit GST">
            </div>
            <div class="col-md-6">
              <label class="form-label">PAN Number</label>
              <input type="text" class="form-control" name="pan_number" placeholder="10 digit PAN">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea class="form-control" name="address" rows="2" placeholder="Full address"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">City</label>
              <input type="text" class="form-control" name="city" placeholder="City">
            </div>
            <div class="col-md-4">
              <label class="form-label">State</label>
              <input type="text" class="form-control" name="state" placeholder="State">
            </div>
            <div class="col-md-4">
              <label class="form-label">Pincode</label>
              <input type="text" class="form-control" name="pincode" placeholder="Pincode">
            </div>
            <div class="col-12">
              <label class="form-label">Dealer Type</label>
              <select class="form-select select2-multiple" name="dealer_type[]" multiple>
                <option value="Electrical">Electrical</option>
                <option value="Plumbing">Plumbing</option>
                <option value="Civil">Civil</option>
                <option value="Painting">Painting</option>
                <option value="Flooring">Flooring</option>
                <option value="Roofing">Roofing</option>
                <option value="Steel">Steel</option>
                <option value="Cement">Cement</option>
                <option value="Woodwork">Woodwork</option>
                <option value="Glass">Glass</option>
                <option value="Hardware">Hardware</option>
                <option value="Sanitary">Sanitary</option>
                <option value="Tile">Tile</option>
                <option value="Paint">Paint</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Payment Terms</label>
              <input type="text" class="form-control" name="payment_terms" placeholder="e.g., 30 days">
            </div>
            <div class="col-md-6">
              <label class="form-label">Credit Limit (₹)</label>
              <input type="number" class="form-control" name="credit_limit" placeholder="0.00" step="0.01">
            </div>
            <div class="col-12">
              <label class="form-label">Remarks</label>
              <textarea class="form-control" name="remarks" rows="2" placeholder="Any additional notes"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Dealer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Import Dealers Modal -->
<div class="modal fade" id="importDealersModal" tabindex="-1" aria-labelledby="importDealersModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-900" id="importDealersModalLabel">Import Dealers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="import-dealers.php" method="POST" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Upload CSV/Excel File</label>
            <input type="file" class="form-control" name="import_file" accept=".csv,.xlsx,.xls" required>
            <div class="form-text">
              Download template: <a href="templates/dealer-import-template.csv">template.csv</a>
            </div>
          </div>
          <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            File should contain columns: dealer_code, dealer_name, mobile_number, email, etc.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
  // Initialize Select2 for multiple dealer types
  $(document).ready(function() {
    $('.select2-multiple').select2({
      theme: 'bootstrap-5',
      width: '100%',
      placeholder: 'Select dealer types'
    });
  });

  // Init DataTable ONLY on md+ screens
  function initDealersTable() {
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    const tbl = document.getElementById('dealersTable');
    if (!tbl) return;

    if (isDesktop) {
      if (!$.fn.DataTable.isDataTable('#dealersTable')) {
        $('#dealersTable').DataTable({
          responsive: true,
          autoWidth: false,
          scrollX: false,
          pageLength: 10,
          lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
          order: [[1, 'asc']],
          columnDefs: [
            { targets: [9], orderable: false, searchable: false } // Action column
          ],
          language: {
            zeroRecords: "No dealers found",
            info: "Showing _START_ to _END_ of _TOTAL_ dealers",
            infoEmpty: "No dealers to show",
            lengthMenu: "Show _MENU_",
            search: "Search:"
          }
        });

        setTimeout(function() {
          $('.dataTables_filter input').focus();
        }, 400);
      }
    } else {
      if ($.fn.DataTable.isDataTable('#dealersTable')) {
        $('#dealersTable').DataTable().destroy();
      }
    }
  }

  // Placeholder functions for edit and contacts
  function editDealer(id) {
    // Redirect to edit page or open modal
    window.location.href = 'edit-dealer.php?id=' + id;
  }

  function manageContacts(id) {
    // Redirect to contacts management page
    window.location.href = 'dealer-contacts.php?dealer_id=' + id;
  }

  $(function () {
    initDealersTable();
    window.addEventListener('resize', initDealersTable);
  });
</script>

</body>
</html>