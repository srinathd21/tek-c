<?php
// quotation-requests.php
session_start();
require_once 'includes/db-config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$user_id = $_SESSION['employee_id'];
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$success = '';
$error   = '';

// Get site_id from URL if present
$preselected_site_id = isset($_GET['site_id']) ? intval($_GET['site_id']) : 0;

// ============================================================
// AUTHORIZATION: Only allow Project Engineers and Team Leads
// ============================================================
$allowed_roles = [
    'project engineer grade 1',
    'project engineer grade 2',
    'sr. engineer',
    'senior engineer',
    'team lead',
    'teamleader'
];

if (!in_array($user_designation, $allowed_roles, true)) {
    // Redirect unauthorized users
    header('Location: index.php');
    exit();
}

// Helper functions
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function getPriorityBadge($priority) {
    $badges = [
        'Low' => ['bg-secondary', 'bi-arrow-down'],
        'Medium' => ['bg-info', 'bi-dash'],
        'High' => ['bg-warning', 'bi-arrow-up'],
        'Urgent' => ['bg-danger', 'bi-exclamation-triangle']
    ];
    $badge = $badges[$priority] ?? ['bg-secondary', 'bi-question'];
    return '<span class="badge ' . $badge[0] . '"><i class="bi ' . $badge[1] . ' me-1"></i>' . $priority . '</span>';
}

function getStatusBadge($status) {
    $badges = [
        'Draft' => ['bg-secondary', 'bi-pencil'],
        'Pending Assignment' => ['bg-warning', 'bi-clock'],
        'Assigned' => ['bg-info', 'bi-person-check'],
        'Quotations Received' => ['bg-primary', 'bi-file-text'],
        'With QS' => ['bg-secondary', 'bi-arrow-right'],
        'QS Finalized' => ['bg-success', 'bi-check-circle'],
        'Approved' => ['bg-success', 'bi-check-circle-fill'],
        'Rejected' => ['bg-danger', 'bi-x-circle'],
        'Cancelled' => ['bg-dark', 'bi-x']
    ];
    $badge = $badges[$status] ?? ['bg-secondary', 'bi-question'];
    return '<span class="badge ' . $badge[0] . '"><i class="bi ' . $badge[1] . ' me-1"></i>' . $status . '</span>';
}

function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

// ============================================================
// GET SITES ASSIGNED TO THIS PE/TL (via site_project_engineers)
// ============================================================
$sites_query = "SELECT 
                    s.id, 
                    s.project_name, 
                    s.project_code, 
                    s.project_location, 
                    s.project_type, 
                    s.start_date, 
                    s.expected_completion_date,
                    c.client_name, 
                    c.company_name
                FROM sites s 
                INNER JOIN site_project_engineers spe ON spe.site_id = s.id
                LEFT JOIN clients c ON c.id = s.client_id
                WHERE spe.employee_id = ? 
                AND s.deleted_at IS NULL
                ORDER BY s.project_name ASC";

$stmt = mysqli_prepare($conn, $sites_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $sites_result = mysqli_stmt_get_result($stmt);
    $sites = mysqli_fetch_all($sites_result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    
    // Validate preselected site belongs to this user
    if ($preselected_site_id > 0) {
        $site_valid = false;
        foreach ($sites as $site) {
            if ($site['id'] == $preselected_site_id) {
                $site_valid = true;
                break;
            }
        }
        if (!$site_valid) {
            $preselected_site_id = 0;
            $error = "Invalid site selected or you don't have access to this site.";
        }
    }
} else {
    $sites = [];
    $error = "Failed to fetch sites: " . mysqli_error($conn);
}

// Get recent quotation requests for this user (created by them)
$recent_requests_query = "SELECT qr.*, s.project_name, s.project_code 
                          FROM quotation_requests qr
                          JOIN sites s ON qr.site_id = s.id
                          WHERE qr.requested_by = ?
                          ORDER BY qr.created_at DESC
                          LIMIT 5";

$stmt = mysqli_prepare($conn, $recent_requests_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $recent_requests = mysqli_stmt_get_result($stmt);
    $recent_requests_list = mysqli_fetch_all($recent_requests, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
} else {
    $recent_requests_list = [];
}

// Get status message if any
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';

// Stats
$total_requests = count($recent_requests_list);
$draft_count = 0;
$pending_count = 0;
$approved_count = 0;

foreach ($recent_requests_list as $req) {
    if ($req['status'] === 'Draft') $draft_count++;
    elseif ($req['status'] === 'Approved') $approved_count++;
    elseif (in_array($req['status'], ['Pending Assignment', 'Assigned', 'Quotations Received', 'With QS'])) $pending_count++;
}

// Get site name for display if preselected
$preselected_site_name = '';
if ($preselected_site_id > 0) {
    foreach ($sites as $site) {
        if ($site['id'] == $preselected_site_id) {
            $preselected_site_name = $site['project_name'];
            if (!empty($site['project_code'])) {
                $preselected_site_name .= ' (' . $site['project_code'] . ')';
            }
            break;
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>New Quotation Request - TEK-C Dashboard</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- Select2 for better dropdowns -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
  <!-- Flatpickr for date picker -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />
  
  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:24px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
    .panel-title{ font-weight:900; font-size:20px; color:#1f2937; margin:0; display:flex; align-items:center; gap:10px; }
    .panel-title i{ color: var(--blue); font-size:24px; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .form-section{ margin-bottom:30px; }
    .form-section-title{ font-weight:850; font-size:16px; color:#374151; margin-bottom:16px; padding-bottom:8px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px; }
    .form-section-title i{ color: var(--blue); font-size:18px; }

    .form-label{ font-weight:800; color:#4b5563; font-size:13px; margin-bottom:6px; }
    .form-control, .form-select{ border:1px solid var(--border); border-radius:12px; padding:10px 14px; font-weight:600; color:#1f2937; background-color:#fff; }
    .form-control:focus, .form-select:focus{ border-color: var(--blue); box-shadow:0 0 0 3px rgba(45,156,219,.15); outline:none; }
    .form-control::placeholder{ color:#9ca3af; font-weight:500; }

    .required:after{ content:" *"; color: var(--red); font-weight:900; }

    .btn{ padding:10px 20px; border-radius:12px; font-weight:800; font-size:14px; display:inline-flex; align-items:center; gap:8px; border:1px solid transparent; transition:all .15s; }
    .btn-primary{ background: var(--blue); color:#fff; border-color: var(--blue); }
    .btn-primary:hover{ background: #1f7ab0; border-color: #1f7ab0; }
    .btn-outline-secondary{ background:#fff; border-color: var(--border); color:#4b5563; }
    .btn-outline-secondary:hover{ background:#f3f4f6; border-color:#d1d5db; }

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

    .file-upload{ border:2px dashed var(--border); border-radius:16px; padding:20px; text-align:center; background:#f9fafb; cursor:pointer; transition:all .15s; }
    .file-upload:hover{ border-color: var(--blue); background:#f0f9ff; }
    .file-upload i{ font-size:32px; color:#9ca3af; margin-bottom:8px; }
    .file-upload p{ margin:0; font-weight:700; color:#6b7280; font-size:14px; }
    .file-upload small{ color:#9ca3af; font-weight:600; font-size:12px; }

    .file-list{ margin-top:15px; }
    .file-item{ display:flex; align-items:center; gap:10px; padding:8px 12px; background:#f3f4f6; border-radius:10px; margin-bottom:8px; }
    .file-item i{ color: var(--blue); }
    .file-item .file-name{ flex:1; font-weight:700; color:#374151; }
    .file-item .file-size{ color:#6b7280; font-weight:600; font-size:12px; }
    .file-item .remove-file{ color: var(--red); cursor:pointer; }

    .info-note{ background:#f0f9ff; border:1px solid #b8e0ff; border-radius:12px; padding:12px 16px; margin-top:20px; display:flex; align-items:center; gap:12px; }
    .info-note i{ color: var(--blue); font-size:20px; }
    .info-note p{ margin:0; color:#1f2937; font-weight:650; font-size:13px; }

    .alert { border-radius: 12px; padding: 15px 20px; margin-bottom: 20px; border: none; }
    .alert-success { background: #d4edda; color: #155724; }
    .alert-danger { background: #f8d7da; color: #721c24; }

    /* Site info banner when preselected */
    .site-info-banner {
      background: #e8f0fe;
      border: 1px solid #b8e0ff;
      border-radius: 12px;
      padding: 12px 16px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .site-info-banner i {
      color: var(--blue);
      font-size: 20px;
    }
    .site-info-banner .site-name {
      font-weight: 900;
      color: #1f2937;
    }

    /* Mobile Cards */
    .request-card{
      border:1px solid var(--border);
      border-radius: 16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding: 12px;
    }
    .request-card .top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }
    .request-card .title{
      font-weight:1000;
      color:#111827;
      font-size: 14px;
      line-height:1.2;
      margin:0;
    }
    .request-card .meta{
      margin-top:6px;
      display:flex;
      flex-wrap:wrap;
      gap:8px 10px;
      color:#6b7280;
      font-weight:800;
      font-size:12px;
    }
    .request-kv{ margin-top:10px; display:grid; gap:8px; }
    .request-row{ display:flex; gap:10px; align-items:flex-start; }
    .request-key{
      flex:0 0 85px;
      color:#6b7280;
      font-weight:1000;
      font-size:12px;
    }
    .request-val{
      flex:1 1 auto;
      font-weight:900;
      color:#111827;
      font-size:12.5px;
      line-height:1.3;
      word-break: break-word;
    }
    .request-actions{
      margin-top:12px;
      display:grid;
      gap:8px;
    }
    .request-actions a{ width:100%; border-radius:12px; justify-content:center; }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .panel{ padding:18px; }
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
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>

          <!-- Site Info Banner (if site preselected) -->
          <?php if ($preselected_site_id > 0 && $preselected_site_name): ?>
            <div class="site-info-banner">
              <i class="bi bi-info-circle-fill"></i>
              <div>
                <strong>Creating quotation request for site:</strong> 
                <span class="site-name"><?php echo e($preselected_site_name); ?></span>
              </div>
            </div>
          <?php endif; ?>

          <!-- Page Header -->
          <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
              <h1 class="h3 fw-900 text-dark mb-1">New Quotation Request</h1>
              <p class="text-muted fw-650 mb-0" style="font-size:14px;">Create a request for supplier quotations based on project drawings</p>
            </div>
            <div>
              <a href="my-quotation-requests.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Requests
              </a>
            </div>
          </div>

          <!-- Stats -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-file-text"></i></div>
                <div>
                  <div class="stat-label">Total Requests</div>
                  <div class="stat-value"><?php echo (int)$total_requests; ?></div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic yellow"><i class="bi bi-clock-history"></i></div>
                <div>
                  <div class="stat-label">Pending</div>
                  <div class="stat-value"><?php echo (int)$pending_count; ?></div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                <div>
                  <div class="stat-label">Approved</div>
                  <div class="stat-value"><?php echo (int)$approved_count; ?></div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-pencil"></i></div>
                <div>
                  <div class="stat-label">Drafts</div>
                  <div class="stat-value"><?php echo (int)$draft_count; ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Main Form Panel -->
          <div class="panel">
            <form id="quotationRequestForm" method="POST" action="process-quotation-request.php" enctype="multipart/form-data">
              
              <!-- Basic Information Section -->
              <div class="form-section">
                <div class="form-section-title">
                  <i class="bi bi-info-circle"></i> Basic Information
                </div>
                
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label required">Site/Project</label>
                    <select class="form-select" name="site_id" id="site_id" required>
                      <option value="" selected disabled>Select site</option>
                      <?php 
                      if (!empty($sites)) {
                          foreach ($sites as $site) {
                              $display_text = e($site['project_name']);
                              if (!empty($site['project_code'])) {
                                  $display_text .= ' (' . e($site['project_code']) . ')';
                              }
                              $selected = ($site['id'] == $preselected_site_id) ? 'selected' : '';
                              echo "<option value='" . $site['id'] . "' $selected>" . $display_text . "</option>";
                          }
                      } else {
                          echo "<option value='' disabled>No sites assigned to you</option>";
                      }
                      ?>
                    </select>
                    <?php if (empty($sites)): ?>
                      <small class="text-danger">You don't have any sites assigned. Please contact admin.</small>
                    <?php endif; ?>
                  </div>
                  
                  <div class="col-md-6">
                    <label class="form-label required">Request Title</label>
                    <input type="text" class="form-control" name="title" placeholder="e.g., Electrical materials for Tower A" required>
                  </div>
                  
                  <!-- CHANGED: Quotation Type now TEXT INPUT instead of dropdown -->
                  <div class="col-md-6">
                    <label class="form-label required">Quotation Type</label>
                    <input type="text" class="form-control" name="quotation_type" placeholder="e.g., Electrical, Plumbing, Civil, Painting, etc." required>
                    <small class="text-muted">Enter the type of materials/services needed for quotation</small>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label required">Priority</label>
                    <select class="form-select" name="priority" required>
                      <option value="Medium" selected>Medium</option>
                      <option value="Low">Low</option>
                      <option value="High">High</option>
                      <option value="Urgent">Urgent</option>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label required">Request Date</label>
                    <input type="text" class="form-control datepicker" name="request_date" value="<?php echo date('Y-m-d'); ?>" required>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Required By Date</label>
                    <input type="text" class="form-control datepicker" name="required_by_date" placeholder="Select date">
                  </div>
                </div>
              </div>

              <!-- Description & Specifications Section -->
              <div class="form-section">
                <div class="form-section-title">
                  <i class="bi bi-file-text"></i> Description & Specifications
                </div>
                
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label required">Description</label>
                    <textarea class="form-control" name="description" rows="3" placeholder="Describe what you need quotations for..." required></textarea>
                  </div>

                  <div class="col-12">
                    <label class="form-label">Specifications (Optional)</label>
                    <textarea class="form-control" name="specifications" rows="2" placeholder="Technical specifications, quality requirements, etc."></textarea>
                  </div>
                </div>
              </div>

              <!-- Drawing & Documents Section -->
              <div class="form-section">
                <div class="form-section-title">
                  <i class="bi bi-file-earmark-image"></i> Drawing & Documents
                </div>
                
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Drawing Number (Optional)</label>
                    <input type="text" class="form-control" name="drawing_number" placeholder="e.g., DWG-2024-001">
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Drawing File (Optional)</label>
                    <input type="file" class="form-control" name="drawing_file" accept=".pdf,.dwg,.dxf,.jpg,.png">
                  </div>

                  <div class="col-12">
                    <label class="form-label">Additional Documents (Optional)</label>
                    <div class="file-upload" id="fileUploadArea">
                      <i class="bi bi-cloud-upload"></i>
                      <p>Click or drag files to upload</p>
                      <small>Supported formats: PDF, DWG, DXF, JPG, PNG (Max: 25MB each)</small>
                      <input type="file" id="fileInput" name="additional_files[]" multiple style="display:none;">
                    </div>
                    
                    <!-- File List -->
                    <div class="file-list" id="fileList"></div>
                  </div>
                </div>
              </div>

              <!-- REMOVED: Budget & Quantity (Optional) section completely -->

              <!-- Additional Notes -->
              <div class="form-section">
                <div class="form-section-title">
                  <i class="bi bi-pencil"></i> Additional Notes
                </div>
                
                <div class="row">
                  <div class="col-12">
                    <textarea class="form-control" name="notes" rows="2" placeholder="Any special instructions or notes for the TL/dealers..."></textarea>
                  </div>
                </div>
              </div>

              <!-- Info Note -->
              <div class="info-note">
                <i class="bi bi-info-circle-fill"></i>
                <p>After submission, this request will be assigned to the Project Engineer (TL) who will contact dealers and obtain quotations based on the provided drawings.</p>
              </div>

              <!-- Form Actions -->
              <div class="d-flex gap-2 justify-content-end mt-4">
                <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='my-quotation-requests.php'">
                  <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" <?php echo empty($sites) ? 'disabled' : ''; ?>>
                  <i class="bi bi-check-lg"></i> Submit Request
                </button>
                <button type="button" class="btn btn-outline-secondary" id="saveDraft" <?php echo empty($sites) ? 'disabled' : ''; ?>>
                  <i class="bi bi-save"></i> Save as Draft
                </button>
              </div>
            </form>
          </div>

          <!-- Recent Requests -->
          <div class="panel mt-4">
            <div class="panel-header">
              <h3 class="panel-title">
                <i class="bi bi-clock-history"></i> Recent Requests
              </h3>
              <a href="my-quotation-requests.php" class="muted-link" style="font-size:13px;">View All <i class="bi bi-arrow-right"></i></a>
            </div>

            <!-- MOBILE: Cards -->
            <div class="d-block d-md-none">
              <div class="d-grid gap-3">
                <?php if (empty($recent_requests_list)): ?>
                  <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 32px;"></i>
                    <p class="mt-2 fw-bold">No quotation requests yet</p>
                  </div>
                <?php else: ?>
                  <?php foreach ($recent_requests_list as $request): ?>
                    <div class="request-card">
                      <div class="top">
                        <div style="flex:1 1 auto;">
                          <div class="d-flex align-items-center justify-content-between gap-2">
                            <h4 class="title"><?php echo e($request['title']); ?></h4>
                            <span class="badge <?php 
                              $priority = $request['priority'] ?? 'Medium';
                              if ($priority === 'Urgent') echo 'bg-danger';
                              elseif ($priority === 'High') echo 'bg-warning';
                              elseif ($priority === 'Medium') echo 'bg-info';
                              else echo 'bg-secondary';
                            ?>"><?php echo e($priority); ?></span>
                          </div>
                          
                          <div class="meta">
                            <span><i class="bi bi-building"></i> <?php echo e($request['project_name'] ?? ''); ?></span>
                            <span><i class="bi bi-tag"></i> <?php echo e($request['quotation_type'] ?? ''); ?></span>
                          </div>
                        </div>
                      </div>

                      <div class="request-kv">
                        <div class="request-row">
                          <div class="request-key">Request No.</div>
                          <div class="request-val fw-800"><?php echo e($request['request_no']); ?></div>
                        </div>

                        <div class="request-row">
                          <div class="request-key">Date</div>
                          <div class="request-val"><?php echo safeDate($request['request_date']); ?></div>
                        </div>

                        <div class="request-row">
                          <div class="request-key">Status</div>
                          <div class="request-val"><?php echo getStatusBadge($request['status']); ?></div>
                        </div>
                      </div>

                      <div class="request-actions">
                        <a href="view-quotation-request.php?id=<?php echo $request['id']; ?>" class="btn-action" title="View Details">
                          <i class="bi bi-eye"></i> View Details
                        </a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- DESKTOP/TABLET: DataTable -->
            <div class="d-none d-md-block">
              <div class="table-responsive">
                <table id="recentRequestsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                  <thead>
                    <tr>
                      <th>Request No.</th>
                      <th>Title</th>
                      <th>Site/Project</th>
                      <th>Type</th>
                      <th>Date</th>
                      <th>Priority</th>
                      <th>Status</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!empty($recent_requests_list)): ?>
                      <?php foreach ($recent_requests_list as $request): ?>
                        <tr>
                          <td><span class="fw-800"><?php echo e($request['request_no']); ?></span></td>
                          <td><?php echo e($request['title']); ?></td>
                          <td><?php echo e($request['project_name']); ?></td>
                          <td><?php echo e($request['quotation_type']); ?></td>
                          <td><?php echo safeDate($request['request_date']); ?></td>
                          <td><?php echo getPriorityBadge($request['priority']); ?></td>
                          <td><?php echo getStatusBadge($request['status']); ?></td>
                          <td class="text-end">
                            <a href="view-quotation-request.php?id=<?php echo $request['id']; ?>" class="btn-action" title="View Details">
                              <i class="bi bi-eye"></i>
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
      </div>

      <?php include 'includes/footer.php'; ?>

    </main>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
  
  <!-- TEK-C Custom JavaScript -->
  <script src="assets/js/sidebar-toggle.js"></script>
  
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize Select2 for better dropdowns
      $('.form-select').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Select an option'
      });

      // Initialize Flatpickr for date pickers
      flatpickr(".datepicker", {
        dateFormat: "Y-m-d",
        allowInput: true
      });

      // File upload handling
      const fileUploadArea = document.getElementById('fileUploadArea');
      const fileInput = document.getElementById('fileInput');
      const fileList = document.getElementById('fileList');
      let filesArray = [];

      if (fileUploadArea) {
        fileUploadArea.addEventListener('click', () => {
          fileInput.click();
        });

        fileUploadArea.addEventListener('dragover', (e) => {
          e.preventDefault();
          fileUploadArea.style.borderColor = 'var(--blue)';
          fileUploadArea.style.background = '#f0f9ff';
        });

        fileUploadArea.addEventListener('dragleave', () => {
          fileUploadArea.style.borderColor = 'var(--border)';
          fileUploadArea.style.background = '#f9fafb';
        });

        fileUploadArea.addEventListener('drop', (e) => {
          e.preventDefault();
          fileUploadArea.style.borderColor = 'var(--border)';
          fileUploadArea.style.background = '#f9fafb';
          
          const files = e.dataTransfer.files;
          handleFiles(files);
        });
      }

      if (fileInput) {
        fileInput.addEventListener('change', (e) => {
          handleFiles(e.target.files);
        });
      }

      function handleFiles(files) {
        for (let file of files) {
          if (file.size > 25 * 1024 * 1024) {
            alert(`File ${file.name} is too large. Max size is 25MB.`);
            continue;
          }
          
          filesArray.push(file);
          displayFileItem(file);
        }
      }

      function displayFileItem(file) {
        if (!fileList) return;
        
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        
        const fileSize = (file.size / 1024).toFixed(1);
        const sizeUnit = fileSize > 1024 ? 'MB' : 'KB';
        const displaySize = fileSize > 1024 ? (fileSize / 1024).toFixed(1) : fileSize;
        
        fileItem.innerHTML = `
          <i class="bi bi-file-earmark"></i>
          <span class="file-name">${file.name}</span>
          <span class="file-size">${displaySize} ${sizeUnit}</span>
          <i class="bi bi-x-circle remove-file"></i>
        `;
        
        fileItem.querySelector('.remove-file').addEventListener('click', () => {
          fileItem.remove();
          filesArray = filesArray.filter(f => f.name !== file.name);
        });
        
        fileList.appendChild(fileItem);
      }

      // Form validation
      const form = document.getElementById('quotationRequestForm');
      if (form) {
        form.addEventListener('submit', (e) => {
          e.preventDefault();
          
          // Basic validation
          const title = form.querySelector('[name="title"]').value;
          const type = form.querySelector('[name="quotation_type"]').value;
          const site = form.querySelector('[name="site_id"]').value;
          const description = form.querySelector('[name="description"]').value;
          
          if (!title || !type || !site || !description) {
            alert('Please fill in all required fields');
            return;
          }
          
          // Submit form
          form.submit();
        });
      }

      // Save draft
      const saveDraftBtn = document.getElementById('saveDraft');
      if (saveDraftBtn) {
        saveDraftBtn.addEventListener('click', () => {
          // Add a hidden field to indicate draft
          const draftInput = document.createElement('input');
          draftInput.type = 'hidden';
          draftInput.name = 'save_as_draft';
          draftInput.value = '1';
          form.appendChild(draftInput);
          
          // Submit as draft
          form.submit();
        });
      }

      // Initialize DataTable for recent requests
      function initRequestsTable() {
        const isDesktop = window.matchMedia('(min-width: 768px)').matches;
        const tbl = document.getElementById('recentRequestsTable');
        if (!tbl) return;

        if (isDesktop) {
          if (!$.fn.DataTable.isDataTable('#recentRequestsTable')) {
            $('#recentRequestsTable').DataTable({
              responsive: true,
              autoWidth: false,
              scrollX: false,
              pageLength: 5,
              lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'All']],
              order: [[4, 'desc']], // Sort by date descending
              columnDefs: [
                { targets: [7], orderable: false, searchable: false } // Action column
              ],
              language: {
                zeroRecords: "No quotation requests found",
                info: "Showing _START_ to _END_ of _TOTAL_ requests",
                infoEmpty: "No requests to show",
                lengthMenu: "Show _MENU_",
                search: "Search:"
              }
            });
          }
        } else {
          // If resized from desktop to mobile, destroy datatable cleanly
          if ($.fn.DataTable.isDataTable('#recentRequestsTable')) {
            $('#recentRequestsTable').DataTable().destroy();
          }
        }
      }

      initRequestsTable();
      window.addEventListener('resize', initRequestsTable);

      // Set current year in footer
      const yearElement = document.getElementById("year");
      if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
      }
    });
  </script>
  
</body>
</html>