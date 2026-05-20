<?php
// edit-quotation-request.php
session_start();
require_once 'includes/db-config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: ../login.php');
    exit();
}

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

$user_id = (int)$_SESSION['employee_id'];
$user_name = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? '';
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$user_department = strtolower(trim((string)($_SESSION['department'] ?? '')));
$currentRoleKey = roleKeyFromDesignation($user_designation, $user_department);
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
if (!in_array($currentRoleKey, ['project_engineer', 'tl'], true)) {
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
// GET SITES ASSIGNED TO THIS PE/TL
// PE: site_project_engineers.employee_id
// TL: sites.team_lead_employee_id OR fallback site_project_engineers
// ============================================================
$hasTeamLeadCol = columnExists($conn, 'sites', 'team_lead_employee_id');

if ($currentRoleKey === 'tl' && $hasTeamLeadCol) {
    $sites_query = "SELECT DISTINCT
                        s.id,
                        s.project_name,
                        s.project_code
                    FROM sites s
                    LEFT JOIN site_project_engineers spe ON spe.site_id = s.id
                    WHERE (s.team_lead_employee_id = ? OR spe.employee_id = ?)
                    AND s.deleted_at IS NULL
                    ORDER BY s.project_name ASC";

    $stmt = mysqli_prepare($conn, $sites_query);
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $user_id);
} else {
    $sites_query = "SELECT DISTINCT
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
}

mysqli_stmt_execute($stmt);
$sites_result = mysqli_stmt_get_result($stmt);
$sites = mysqli_fetch_all($sites_result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Ensure current request site stays selectable if it was valid when request was created.
$currentSiteInList = false;
foreach ($sites as $siteRow) {
    if ((int)$siteRow['id'] === (int)$request['site_id']) {
        $currentSiteInList = true;
        break;
    }
}
if (!$currentSiteInList && !empty($request['site_id'])) {
    $sites[] = [
        'id' => (int)$request['site_id'],
        'project_name' => $request['project_name'] ?? 'Current Project',
        'project_code' => $request['project_code'] ?? ''
    ];
}

// Helper functions
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $col = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function roleKeyFromDesignation(string $designation, string $department = ''): string {
    $d = strtolower(trim($designation));
    $dept = strtolower(trim($department));

    if (
        str_contains($d, 'team lead') ||
        str_contains($d, 'teamleader') ||
        str_contains($d, 'tl') ||
        str_contains($d, 'lead')
    ) return 'tl';

    if (
        str_contains($d, 'project engineer') ||
        str_contains($d, 'engineer') ||
        str_contains($d, 'sr. engineer') ||
        str_contains($d, 'sr engineer') ||
        str_contains($d, 'senior engineer')
    ) return 'project_engineer';

    return 'other';
}

function logQuotationEditActivity($conn, int $employeeId, string $description, int $requestId, array $oldData = [], array $newData = []): bool {
    if (!$conn || !tableExists($conn, 'activity_logs')) return false;

    $oldJson = $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null;
    $newJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;

    $employeeName = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'System';
    $username = $_SESSION['username'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $department = $_SESSION['department'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $map = [
        'employee_id'   => ['i', $employeeId],
        'employee_name' => ['s', $employeeName],
        'username'      => ['s', $username],
        'designation'   => ['s', $designation],
        'department'    => ['s', $department],
        'activity_type' => ['s', 'UPDATE'],
        'module'        => ['s', 'quotation_requests'],
        'description'   => ['s', $description],
        'reference_id'  => ['i', $requestId],
        'old_data'      => ['s', $oldJson],
        'new_data'      => ['s', $newJson],
        'ip_address'    => ['s', $ipAddress],
    ];

    $cols = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (columnExists($conn, 'activity_logs', $column)) {
            $cols[] = "`$column`";
            $types .= $pair[0];
            $values[] = $pair[1];
        }
    }

    if (!$cols) return false;

    $sql = "INSERT INTO activity_logs (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function getPriorityBadge($priority) {
    $p = trim((string)$priority);
    $map = [
        'Low'    => ['neutral', 'bi-arrow-down'],
        'Medium' => ['progressing', 'bi-dash'],
        'High'   => ['warning', 'bi-arrow-up'],
        'Urgent' => ['atrisk', 'bi-exclamation-triangle']
    ];
    $m = $map[$p] ?? ['neutral', 'bi-question'];
    return '<span class="badge-pill ' . $m[0] . '"><i class="bi ' . $m[1] . '"></i>' . e($p !== '' ? $p : '—') . '</span>';
}

function getStatusBadge($status) {
    $s = trim((string)$status);
    $map = [
        'Draft'              => ['neutral', 'bi-pencil'],
        'Pending Assignment' => ['pending', 'bi-clock'],
        'Assigned'           => ['progressing', 'bi-person-check'],
        'Quotations Received'=> ['progressing', 'bi-file-text'],
        'With QS'            => ['pending', 'bi-arrow-right'],
        'QS Finalized'       => ['ontrack', 'bi-check-circle'],
        'Approved'           => ['ontrack', 'bi-check-circle-fill'],
        'Rejected'           => ['atrisk', 'bi-x-circle'],
        'Cancelled'          => ['neutral', 'bi-x']
    ];
    $m = $map[$s] ?? ['neutral', 'bi-info-circle'];
    return '<span class="badge-pill ' . $m[0] . '"><i class="bi ' . $m[1] . '"></i>' . e($s !== '' ? $s : '—') . '</span>';
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
    
    // Draft button removed: edit page always updates and submits to Pending Assignment.
    $submit_action = 'submit';
    $status = 'Pending Assignment';
    
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
                    @unlink($drawing_file);
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
            
            // Keep existing additional documents and append newly uploaded files.
            $existing_docs = [];
            if (!empty($request['additional_documents_json']) && $request['additional_documents_json'] !== '[]') {
                $decoded_docs = json_decode($request['additional_documents_json'], true);
                if (is_array($decoded_docs)) {
                    $existing_docs = $decoded_docs;
                }
            }

            if (isset($_FILES['additional_files']) && !empty($_FILES['additional_files']['name'][0])) {
                $doc_dir = 'uploads/quotation_requests/documents/';
                if (!file_exists($doc_dir)) {
                    mkdir($doc_dir, 0777, true);
                }

                foreach ($_FILES['additional_files']['name'] as $idx => $original_name) {
                    if ($_FILES['additional_files']['error'][$idx] !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    if ($_FILES['additional_files']['size'][$idx] > 25 * 1024 * 1024) {
                        continue;
                    }

                    $extension = pathinfo($original_name, PATHINFO_EXTENSION);
                    $safe_name = uniqid('doc_', true) . '_' . time() . '.' . $extension;
                    $target_path = $doc_dir . $safe_name;

                    if (move_uploaded_file($_FILES['additional_files']['tmp_name'][$idx], $target_path)) {
                        $existing_docs[] = [
                            'file_name' => $original_name,
                            'file_path' => $target_path,
                            'file_size' => $_FILES['additional_files']['size'][$idx],
                            'uploaded_at' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }

            $additional_documents_json = json_encode($existing_docs, JSON_UNESCAPED_UNICODE);
            
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
                // Log the activity using current DB activity_logs columns.
                logQuotationEditActivity(
                    $conn,
                    $user_id,
                    'Updated and submitted quotation request: ' . $title,
                    $request_id,
                    $request,
                    [
                        'quotation_type' => $quotation_type,
                        'site_id' => $site_id,
                        'priority' => $priority,
                        'request_date' => $request_date,
                        'required_by_date' => $required_by_date,
                        'title' => $title,
                        'status' => $status
                    ]
                );

mysqli_stmt_close($stmt);
                
                $message = 'Quotation request updated and submitted successfully!';
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
  <!-- Flatpickr for date picker -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />
  
  <style>
    :root{
      --page-bg:#f5f7fb;
      --card-bg:#ffffff;
      --border:#e5e7eb;
      --text:#111827;
      --muted:#6b7280;
      --soft:#f8fafc;
      --shadow:0 10px 26px rgba(15,23,42,.055);
      --radius:15px;
      --blue:#2f80ed;
      --green:#27ae60;
      --orange:#f2994a;
      --red:#eb5757;
      --purple:#7c3aed;
    }

    body{ background:var(--page-bg); }

    .content-scroll{ flex:1 1 auto; overflow:auto; padding:16px; }
    .projects-wrapper{ width:100%; }

    .page-heading{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }

    .page-heading h1{
      font-size:19px;
      font-weight:950;
      color:var(--text);
      margin:0;
    }

    .page-heading p{
      margin:3px 0 0;
      color:var(--muted);
      font-size:12px;
      font-weight:650;
    }

    .primary-btn,.secondary-btn,.success-btn,.danger-btn{
      min-height:36px;
      padding:0 14px;
      border-radius:11px;
      font-size:12px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:7px;
      text-decoration:none;
      white-space:nowrap;
      border:0;
      line-height:1;
    }

    .primary-btn{ background:#111827; color:#fff; }
    .primary-btn:hover{ background:#020617; color:#fff; }

    .success-btn{ background:#16a34a; color:#fff; }
    .success-btn:hover{ background:#15803d; color:#fff; }

    .secondary-btn{
      border:1px solid var(--border);
      background:#fff;
      color:#334155;
    }

    .secondary-btn:hover{
      border-color:#cbd5e1;
      background:#f8fafc;
      color:#111827;
    }

    .panel{
      background:var(--card-bg);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:13px;
      margin-bottom:14px;
      height:auto;
    }

    .panel-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:12px;
    }

    .panel-title{
      font-weight:950;
      font-size:14px;
      color:var(--text);
      margin:0;
      display:flex;
      align-items:center;
      gap:8px;
    }

    .panel-title i{ color:var(--blue); font-size:16px; }
    .panel-subtitle{ color:var(--muted); font-size:11px; font-weight:700; margin-top:2px; }

    .form-section{
      border:1px solid #eef2f7;
      background:#fff;
      border-radius:14px;
      padding:13px;
      margin-bottom:13px;
    }

    .form-section-title{
      font-weight:950;
      font-size:13px;
      color:#111827;
      margin-bottom:12px;
      padding-bottom:8px;
      border-bottom:1px solid #eef2f7;
      display:flex;
      align-items:center;
      gap:8px;
    }

    .form-section-title i{ color:var(--blue); font-size:15px; }

    .form-label{
      font-size:11px;
      font-weight:900;
      color:#475569;
      text-transform:uppercase;
      margin-bottom:6px;
    }

    .form-control,.form-select{
      min-height:38px;
      border:1px solid var(--border);
      border-radius:11px;
      font-size:12px;
      font-weight:800;
      color:#111827;
      padding:8px 11px;
      background:#fff;
    }

    .form-control:focus,.form-select:focus{
      border-color:#bfdbfe;
      box-shadow:0 0 0 3px rgba(59,130,246,.10);
    }

    textarea.form-control{ min-height:88px; }

    .required:after{ content:" *"; color:var(--red); font-weight:950; }

    .badge-pill{
      border-radius:999px;
      padding:5px 8px;
      font-weight:900;
      font-size:10px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      border:1px solid transparent;
      text-decoration:none;
      white-space:nowrap;
    }

    .ontrack{ color:#15803d; background:#dcfce7; border-color:#bbf7d0; }
    .progressing{ color:#2563eb; background:#dbeafe; border-color:#bfdbfe; }
    .pending{ color:#6d28d9; background:#ede9fe; border-color:#ddd6fe; }
    .atrisk{ color:#b91c1c; background:#fee2e2; border-color:#fecaca; }
    .neutral{ color:#475569; background:#f1f5f9; border-color:#e2e8f0; }
    .warning{ color:#b45309; background:#ffedd5; border-color:#fed7aa; }

    .file-upload{
      border:1.5px dashed #cbd5e1;
      border-radius:14px;
      padding:18px;
      text-align:center;
      background:#f8fafc;
      cursor:pointer;
      transition:.15s ease;
    }

    .file-upload:hover{
      border-color:#93c5fd;
      background:#eff6ff;
    }

    .file-upload i{
      font-size:30px;
      color:#94a3b8;
      margin-bottom:8px;
    }

    .file-upload p{
      margin:0;
      font-weight:900;
      color:#475569;
      font-size:12px;
    }

    .file-upload small{
      color:#94a3b8;
      font-weight:700;
      font-size:10.5px;
    }

    .file-list{ margin-top:12px; }

    .file-item{
      display:flex;
      align-items:center;
      gap:9px;
      padding:8px 10px;
      background:#f8fafc;
      border:1px solid #eef2f7;
      border-radius:11px;
      margin-bottom:8px;
      font-size:11px;
    }

    .file-item i{ color:var(--blue); }
    .file-item .file-name{ flex:1; font-weight:900; color:#334155; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .file-item .file-size{ color:#64748b; font-weight:800; font-size:10px; }
    .file-item .remove-file{ color:var(--red); cursor:pointer; }

    .info-note{
      background:#eff6ff;
      border:1px solid #bfdbfe;
      border-radius:13px;
      padding:11px 13px;
      margin-top:14px;
      display:flex;
      align-items:center;
      gap:10px;
    }

    .info-note i{ color:#2563eb; font-size:18px; }
    .info-note p{ margin:0; color:#1e293b; font-weight:750; font-size:11.5px; }

    .alert{
      border-radius:14px;
      border:1px solid transparent;
      box-shadow:var(--shadow);
      font-size:12px;
      font-weight:850;
      margin-bottom:14px;
    }

    .alert-danger{ background:#fee2e2; border-color:#fecaca; color:#991b1b; }
    .alert-info{ background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
    .alert-success{ background:#dcfce7; border-color:#bbf7d0; color:#166534; }

    @media(max-width:991.98px){
      .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
      .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
      .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0)!important; }
    }

    @media(max-width:768px){
      .content-scroll{ padding:12px 10px!important; }
      .container-fluid.projects-wrapper{ padding-left:0!important; padding-right:0!important; }
      .page-heading{ align-items:flex-start; flex-direction:column; }
      .panel,.form-section{ padding:12px; }
      .primary-btn,.secondary-btn,.success-btn{ width:100%; }
      .form-actions{ flex-direction:column-reverse; align-items:stretch!important; }
      .file-item{ align-items:flex-start; }
    }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div id="contentScroll" class="content-scroll">
      <div class="container-fluid projects-wrapper px-0">

        <!-- Page Header -->
        <div class="page-heading">
          <div>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
              <h1>Edit Quotation Request</h1>
              <?php echo getStatusBadge($request['status']); ?>
            </div>
            <p>
              Request #<?php echo e($request['request_no']); ?> • Update and submit to Pending Assignment
            </p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="my-quotation-requests.php" class="secondary-btn">
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
          <strong>Note:</strong> You can edit only <strong>Draft</strong> or <strong>Pending Assignment</strong> requests. Draft save is removed; this page will submit the request to <strong>Pending Assignment</strong>.
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>

        <!-- Main Form Panel -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <h3 class="panel-title"><i class="bi bi-pencil-square"></i> Update Request</h3>
              <div class="panel-subtitle">Edit request details and submit for TL approval workflow</div>
            </div>
            <?php echo getPriorityBadge($request['priority']); ?>
          </div>

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
              <p>After update, this request will move to Pending Assignment and notify the project workflow for TL review.</p>
            </div>

            <!-- Form Actions -->
            <div class="d-flex gap-2 justify-content-end align-items-center mt-4 form-actions">
              <button type="button" class="secondary-btn" onclick="window.location.href='my-quotation-requests.php'">
                <i class="bi bi-x-lg"></i> Cancel
              </button>
              <button type="submit" name="submit_action" value="submit" class="primary-btn">
                <i class="bi bi-check2-circle"></i> Update & Submit
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
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof flatpickr !== 'undefined') {
      flatpickr(".datepicker", {
        dateFormat: "Y-m-d",
        allowInput: true
      });
    }

    const fileUploadArea = document.getElementById('fileUploadArea');
    const fileInput = document.getElementById('fileInput');
    const fileList = document.getElementById('fileList');
    let filesArray = [];

    if (fileUploadArea && fileInput) {
      fileUploadArea.addEventListener('click', () => fileInput.click());

      fileUploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        fileUploadArea.style.borderColor = '#93c5fd';
        fileUploadArea.style.background = '#eff6ff';
      });

      fileUploadArea.addEventListener('dragleave', () => {
        fileUploadArea.style.borderColor = '#cbd5e1';
        fileUploadArea.style.background = '#f8fafc';
      });

      fileUploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        fileUploadArea.style.borderColor = '#cbd5e1';
        fileUploadArea.style.background = '#f8fafc';
        handleFiles(e.dataTransfer.files);
      });

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

      const sizeKb = file.size / 1024;
      const displaySize = sizeKb > 1024 ? (sizeKb / 1024).toFixed(1) : sizeKb.toFixed(1);
      const sizeUnit = sizeKb > 1024 ? 'MB' : 'KB';

      fileItem.innerHTML = `
        <i class="bi bi-file-earmark"></i>
        <span class="file-name"></span>
        <span class="file-size">${displaySize} ${sizeUnit}</span>
        <i class="bi bi-x-circle remove-file"></i>
      `;

      fileItem.querySelector('.file-name').textContent = file.name;
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