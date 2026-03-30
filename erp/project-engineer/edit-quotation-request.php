<?php
// edit-quotation-request.php
session_start();
require_once 'includes/db-config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

$user_id = (int)$_SESSION['employee_id'];
$user_name = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? '';
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

// Editable statuses
$editable_statuses = ['Draft', 'Pending Assignment'];

if ($request_id <= 0) {
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('Invalid request ID'));
    exit();
}

// ============================================================
// AUTHORIZATION: Only allow Project Engineers and Team Leads to edit
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
    header('Location: index.php');
    exit();
}

// Fetch the quotation request
$query = "
    SELECT 
        qr.*,
        s.project_name,
        s.project_code,
        c.client_name,
        c.company_name
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    WHERE qr.id = ? AND qr.requested_by = ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$request) {
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('Request not found or you do not have permission to edit it'));
    exit();
}

// Check if request is editable
if (!in_array($request['status'], $editable_statuses)) {
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('This request cannot be edited because it has already been ' . strtolower($request['status'])));
    exit();
}

// ============================================================
// GET SITES ASSIGNED TO THIS PE/TL (via site_project_engineers)
// ============================================================
$sites_query = "SELECT 
                    s.id, 
                    s.project_name, 
                    s.project_code
                FROM sites s 
                INNER JOIN site_project_engineers spe ON spe.site_id = s.id
                WHERE spe.employee_id = ? 
                AND s.deleted_at IS NULL
                ORDER BY s.project_name ASC";

$stmt = mysqli_prepare($conn, $sites_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$sites_result = mysqli_stmt_get_result($stmt);
$sites = mysqli_fetch_all($sites_result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $quotation_type = trim($_POST['quotation_type'] ?? '');
    $site_id = intval($_POST['site_id'] ?? 0);
    $priority = trim($_POST['priority'] ?? 'Medium');
    $request_date = trim($_POST['request_date'] ?? date('Y-m-d'));
    $required_by_date = !empty($_POST['required_by_date']) ? trim($_POST['required_by_date']) : null;
    $description = trim($_POST['description'] ?? '');
    $specifications = !empty($_POST['specifications']) ? trim($_POST['specifications']) : null;
    $drawing_number = !empty($_POST['drawing_number']) ? trim($_POST['drawing_number']) : null;
    
    // Check if saving as draft or submitting
    $submit_action = $_POST['submit_action'] ?? 'update';
    $status = ($submit_action === 'draft') ? 'Draft' : 'Pending Assignment';
    
    // Validate required fields
    if (empty($title) || empty($quotation_type) || empty($site_id) || empty($description)) {
        $error = 'Please fill in all required fields.';
    } else {
        // Validate site access
        $site_valid = false;
        foreach ($sites as $site) {
            if ($site['id'] == $site_id) {
                $site_valid = true;
                break;
            }
        }
        
        if (!$site_valid) {
            $error = 'Invalid site selected or you do not have access to this site.';
        } else {
            // Handle drawing file upload
            $drawing_file = $request['drawing_file']; // Keep existing
            
            // New drawing file upload
            if (isset($_FILES['drawing_file']) && $_FILES['drawing_file']['error'] === UPLOAD_ERR_OK) {
                // Delete old file if exists
                if ($drawing_file && file_exists($drawing_file)) {
                    unlink($drawing_file);
                }
                
                // Upload new file
                $target_dir = 'uploads/quotation_requests/drawings/';
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                $extension = pathinfo($_FILES['drawing_file']['name'], PATHINFO_EXTENSION);
                $filename = uniqid() . '_' . time() . '.' . $extension;
                $target_path = $target_dir . $filename;
                
                if (move_uploaded_file($_FILES['drawing_file']['tmp_name'], $target_path)) {
                    $drawing_file = $target_path;
                }
            }
            
            // Keep existing additional documents
            $additional_documents_json = $request['additional_documents_json'];
            
            // Update the request
            $update_query = "UPDATE quotation_requests SET
                quotation_type = ?,
                site_id = ?,
                priority = ?,
                request_date = ?,
                required_by_date = ?,
                title = ?,
                description = ?,
                specifications = ?,
                drawing_number = ?,
                drawing_file = ?,
                additional_documents_json = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ? AND requested_by = ?";
            
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "sissssssssssii", 
                $quotation_type,
                $site_id,
                $priority,
                $request_date,
                $required_by_date,
                $title,
                $description,
                $specifications,
                $drawing_number,
                $drawing_file,
                $additional_documents_json,
                $status,
                $request_id,
                $user_id
            );
            
            if (mysqli_stmt_execute($stmt)) {
                // Log the activity
                $log_query = "INSERT INTO activity_logs (user_id, user_name, user_role, action_type, module, module_id, module_name, description, created_at) 
                              VALUES (?, ?, (SELECT designation FROM employees WHERE id = ?), 'UPDATE', 'quotation_requests', ?, ?, ?, NOW())";
                $log_stmt = mysqli_prepare($conn, $log_query);
                if ($log_stmt) {
                    $description = ($submit_action === 'draft') ? 'Updated draft: ' . $title : 'Updated and submitted: ' . $title;
                    mysqli_stmt_bind_param($log_stmt, "isisis", $user_id, $user_name, $user_id, $request_id, $title, $description);
                    mysqli_stmt_execute($log_stmt);
                    mysqli_stmt_close($log_stmt);
                }
                
                mysqli_stmt_close($stmt);
                
                $message = ($submit_action === 'draft') ? 'Quotation request saved as draft successfully!' : 'Quotation request updated and submitted successfully!';
                header("Location: my-quotation-requests.php?status=success&message=" . urlencode($message));
                exit();
            } else {
                $error = 'Failed to update request: ' . mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Quotation Request - TEK-C Dashboard</title>

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

    .form-section{ margin-bottom:30px; }
    .form-section-title{ font-weight:850; font-size:16px; color:#374151; margin-bottom:16px; padding-bottom:8px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px; }
    .form-section-title i{ color: var(--blue); font-size:18px; }

    .form-label{ font-weight:800; color:#4b5563; font-size:13px; margin-bottom:6px; }
    .form-control, .form-select{ border:1px solid var(--border); border-radius:12px; padding:10px 14px; font-weight:600; color:#1f2937; background-color:#fff; }
    .form-control:focus, .form-select:focus{ border-color: var(--blue); box-shadow:0 0 0 3px rgba(45,156,219,.15); outline:none; }

    .required:after{ content:" *"; color: var(--red); font-weight:900; }

    .btn{ padding:10px 20px; border-radius:12px; font-weight:800; font-size:14px; display:inline-flex; align-items:center; gap:8px; border:1px solid transparent; transition:all .15s; }
    .btn-primary{ background: var(--blue); color:#fff; border-color: var(--blue); }
    .btn-primary:hover{ background: #1f7ab0; border-color: #1f7ab0; }
    .btn-outline-secondary{ background:#fff; border-color: var(--border); color:#4b5563; }
    .btn-outline-secondary:hover{ background:#f3f4f6; border-color:#d1d5db; }
    .btn-warning{ background: #f59e0b; color:#fff; border-color: #f59e0b; }
    .btn-warning:hover{ background: #d97706; }

    .alert { border-radius: 12px; padding: 15px 20px; margin-bottom: 20px; border: none; }
    .alert-danger { background: #f8d7da; color: #721c24; }
    .alert-info { background: #d1ecf1; color: #0c5460; }
    .alert-success { background: #d4edda; color: #155724; }

    .status-badge{
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 800;
    }
    .status-draft{ background: #e5e7eb; color: #374151; }
    .status-pending-assignment{ background: #fef3c7; color: #92400e; }

    .info-note{ background:#f0f9ff; border:1px solid #b8e0ff; border-radius:12px; padding:12px 16px; margin-top:20px; display:flex; align-items:center; gap:12px; }
    .info-note i{ color: var(--blue); font-size:20px; }
    .info-note p{ margin:0; color:#1f2937; font-weight:650; font-size:13px; }

    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .panel { padding: 12px !important; }
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

        <!-- Page Header -->
        <div class="d-flex align-items-center justify-content-between mb-4">
          <div>
            <h1 class="h3 fw-900 text-dark mb-1">Edit Quotation Request</h1>
            <p class="text-muted fw-650 mb-0">
              Request #<?php echo e($request['request_no']); ?>
              <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $request['status'])); ?> ms-2">
                <i class="bi bi-<?php echo $request['status'] === 'Draft' ? 'pencil' : 'clock'; ?>"></i>
                <?php echo e($request['status']); ?>
              </span>
            </p>
          </div>
          <div>
            <a href="my-quotation-requests.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left"></i> Back to Requests
            </a>
          </div>
        </div>

        <!-- Error Alert -->
        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Info Alert -->
        <div class="alert alert-info alert-dismissible fade show" role="alert">
          <i class="bi bi-info-circle-fill me-2"></i>
          <strong>Note:</strong> You can edit this request as long as it's in <strong>Draft</strong> or <strong>Pending Assignment</strong> status.
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>

        <!-- Main Form Panel -->
        <div class="panel">
          <form method="POST" action="edit-quotation-request.php?id=<?php echo $request_id; ?>" enctype="multipart/form-data">
            
            <!-- Basic Information Section -->
            <div class="form-section">
              <div class="form-section-title">
                <i class="bi bi-info-circle"></i> Basic Information
              </div>
              
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label required">Site/Project</label>
                  <select class="form-select" name="site_id" id="site_id" required>
                    <option value="" disabled>Select site</option>
                    <?php if (!empty($sites)): ?>
                      <?php foreach ($sites as $site): ?>
                        <option value="<?php echo $site['id']; ?>" <?php echo $site['id'] == $request['site_id'] ? 'selected' : ''; ?>>
                          <?php echo e($site['project_name']); ?>
                          <?php if (!empty($site['project_code'])): ?>
                            (<?php echo e($site['project_code']); ?>)
                          <?php endif; ?>
                        </option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                </div>
                
                <div class="col-md-6">
                  <label class="form-label required">Request Title</label>
                  <input type="text" class="form-control" name="title" value="<?php echo e($request['title']); ?>" placeholder="e.g., Electrical materials for Tower A" required>
                </div>
                
                <!-- Quotation Type - TEXT INPUT (matching create form) -->
                <div class="col-md-6">
                  <label class="form-label required">Quotation Type</label>
                  <input type="text" class="form-control" name="quotation_type" value="<?php echo e($request['quotation_type']); ?>" placeholder="e.g., Electrical, Plumbing, Civil, Painting, etc." required>
                  <small class="text-muted">Enter the type of materials/services needed for quotation</small>
                </div>

                <div class="col-md-6">
                  <label class="form-label required">Priority</label>
                  <select class="form-select" name="priority" required>
                    <option value="Low" <?php echo $request['priority'] === 'Low' ? 'selected' : ''; ?>>Low</option>
                    <option value="Medium" <?php echo $request['priority'] === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="High" <?php echo $request['priority'] === 'High' ? 'selected' : ''; ?>>High</option>
                    <option value="Urgent" <?php echo $request['priority'] === 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label required">Request Date</label>
                  <input type="text" class="form-control datepicker" name="request_date" value="<?php echo e($request['request_date']); ?>" required>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Required By Date</label>
                  <input type="text" class="form-control datepicker" name="required_by_date" value="<?php echo e($request['required_by_date'] ?: ''); ?>" placeholder="Select date">
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
                  <textarea class="form-control" name="description" rows="3" placeholder="Describe what you need quotations for..." required><?php echo e($request['description']); ?></textarea>
                </div>

                <div class="col-12">
                  <label class="form-label">Specifications (Optional)</label>
                  <textarea class="form-control" name="specifications" rows="2" placeholder="Technical specifications, quality requirements, etc."><?php echo e($request['specifications']); ?></textarea>
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
                  <input type="text" class="form-control" name="drawing_number" value="<?php echo e($request['drawing_number']); ?>" placeholder="e.g., DWG-2024-001">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Drawing File (Optional)</label>
                  <input type="file" class="form-control" name="drawing_file" accept=".pdf,.dwg,.dxf,.jpg,.png">
                  <?php if ($request['drawing_file']): ?>
                    <small class="text-muted d-block mt-1">
                      Current: <a href="<?php echo e($request['drawing_file']); ?>" target="_blank">View Current Drawing</a>
                    </small>
                  <?php endif; ?>
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
                  
                  <?php if ($request['additional_documents_json'] && $request['additional_documents_json'] !== '[]'): ?>
                    <div class="mt-2">
                      <small class="text-muted">Existing additional documents will be preserved.</small>
                    </div>
                  <?php endif; ?>
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
              <button type="submit" name="submit_action" value="draft" class="btn btn-warning">
                <i class="bi bi-save"></i> Save as Draft
              </button>
              <button type="submit" name="submit_action" value="submit" class="btn btn-primary">
                <i class="bi bi-check-lg"></i> Update & Submit
              </button>
            </div>
          </form>
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

    // File upload handling (same as create form)
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
  });
</script>

</body>
</html>