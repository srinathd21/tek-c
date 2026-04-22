<?php
// sat_form.php - Samples Approval Tracker (SAT) Form
session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

$allowed = [
    'project engineer grade 1',
    'project engineer grade 2',
    'sr. engineer',
    'team lead',
    'manager',
    'hr',
    'admin'
];
if (!in_array($designation, $allowed, true)) {
    header("Location: index.php");
    exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function hasColumn(mysqli $conn, string $table, string $col): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) return false;
    mysqli_stmt_bind_param($st, "ss", $table, $col);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $ok = (bool)mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    return $ok;
}

// ---------------- Create SAT Table if Not Exists ----------------
$tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'sat_samples'");
if (mysqli_num_rows($tableCheck) == 0) {
    mysqli_query($conn, "
    CREATE TABLE sat_samples (
        id INT(11) NOT NULL AUTO_INCREMENT,
        project_name VARCHAR(200) NOT NULL,
        client_name VARCHAR(200) NOT NULL,
        architects VARCHAR(200),
        pmc VARCHAR(200),
        revisions VARCHAR(100),
        sl_no INT(11) NOT NULL,
        sample_name VARCHAR(255) NOT NULL,
        vendor_name VARCHAR(255) NOT NULL,
        sample_delivered TINYINT(1) DEFAULT 0,
        sample_delivered_date DATE,
        quote_received TINYINT(1) DEFAULT 0,
        quote_received_date DATE,
        approved TINYINT(1) DEFAULT 0,
        rejected TINYINT(1) DEFAULT 0,
        comments TEXT,
        created_by INT(11),
        batch_id VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP(),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
        PRIMARY KEY (id),
        KEY idx_project (project_name),
        KEY idx_batch (batch_id),
        KEY idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// Add batch_id column if it doesn't exist
if (!hasColumn($conn, 'sat_samples', 'batch_id')) {
    @mysqli_query($conn, "ALTER TABLE sat_samples ADD COLUMN batch_id VARCHAR(50) AFTER created_by");
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, designation FROM employees WHERE id=? LIMIT 1");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $empRow = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
}
$preparedBy = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');



// ---------------- Generate Batch ID ----------------
function generateBatchId() {
    return 'SAT-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
}

// ---------------- Check if user has already submitted TODAY ----------------
$existingBatch = null;
$existingRows = [];
$existingMeta = [];

// Check database for any submission by this user today
$checkStmt = mysqli_prepare($conn, "SELECT DISTINCT batch_id FROM sat_samples 
    WHERE created_by = ? AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 1");
    
if ($checkStmt) {
    mysqli_stmt_bind_param($checkStmt, "i", $employeeId);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    $todaySubmission = mysqli_fetch_assoc($checkResult);
    mysqli_stmt_close($checkStmt);
    
    if ($todaySubmission && !empty($todaySubmission['batch_id'])) {
        $existingBatch = $todaySubmission['batch_id'];
        
        // Fetch all rows for this batch
        $stmt = mysqli_prepare($conn, "SELECT * FROM sat_samples WHERE batch_id = ? ORDER BY sl_no ASC");
        mysqli_stmt_bind_param($stmt, "s", $existingBatch);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $existingRows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        
        if (!empty($existingRows)) {
            $firstRow = $existingRows[0];
            $existingMeta = [
                'project' => $firstRow['project_name'],
                'client' => $firstRow['client_name'],
                'architects' => $firstRow['architects'] ?? '',
                'pmc' => $firstRow['pmc'] ?? '',
                'revisions' => $firstRow['revisions'] ?? ''
            ];
        }
    }
}
// ---------------- Handle Form Submission ----------------
// ---------------- Handle Form Submission ----------------
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sat'])) {
    // Check if already submitted today
    if ($existingBatch) {
        $error = "You have already submitted SAT today. You can submit again tomorrow.";
    } else {
        $project = trim($_POST['project_name'] ?? '');
        $client = trim($_POST['client_name'] ?? '');
        $architects = trim($_POST['architects'] ?? '');
        $pmc = trim($_POST['pmc'] ?? '');
        $revisions = trim($_POST['revisions'] ?? '');
        
        $samples = $_POST['sample_name'] ?? [];
        $vendors = $_POST['vendor_name'] ?? [];
        $sampleDelivered = $_POST['sample_delivered'] ?? [];
        $sampleDates = $_POST['sample_delivered_date'] ?? [];
        $quoteReceived = $_POST['quote_received'] ?? [];
        $quoteDates = $_POST['quote_received_date'] ?? [];
        $approved = $_POST['approved'] ?? [];
        $rejected = $_POST['rejected'] ?? [];
        $comments = $_POST['comments'] ?? [];
        
        if (empty($project) || empty($client)) {
            $error = "Project Name and Client Name are required.";
        } else {
            $batchId = generateBatchId();
            $successCount = 0;
            
            for ($i = 0; $i < count($samples); $i++) {
                if (trim($samples[$i] ?? '') === '' && trim($vendors[$i] ?? '') === '') {
                    continue;
                }
                
                $stmt = mysqli_prepare($conn, "
                    INSERT INTO sat_samples 
                    (project_name, client_name, architects, pmc, revisions, batch_id, sl_no, 
                    sample_name, vendor_name, sample_delivered, sample_delivered_date, 
                    quote_received, quote_received_date, approved, rejected, comments, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $slNo = $i + 1;
                $sampleDeliveredVal = isset($sampleDelivered[$i]) ? 1 : 0;
                $sampleDateVal = !empty($sampleDates[$i]) ? $sampleDates[$i] : null;
                $quoteReceivedVal = isset($quoteReceived[$i]) ? 1 : 0;
                $quoteDateVal = !empty($quoteDates[$i]) ? $quoteDates[$i] : null;
                $approvedVal = isset($approved[$i]) ? 1 : 0;
                $rejectedVal = isset($rejected[$i]) ? 1 : 0;
                
                mysqli_stmt_bind_param($stmt, "ssssssissisisissi", 
                    $project, $client, $architects, $pmc, $revisions, $batchId, $slNo,
                    $samples[$i], $vendors[$i], $sampleDeliveredVal, $sampleDateVal,
                    $quoteReceivedVal, $quoteDateVal, $approvedVal, $rejectedVal,
                    $comments[$i], $employeeId
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    $successCount++;
                }
                mysqli_stmt_close($stmt);
            }
            
            if ($successCount > 0) {
                // Redirect to show the submitted data in view mode
                header("Location: sat_form.php?success=1");
                exit;
            } else {
                $error = "No valid entries to save.";
            }
        }
    }
}

// Check for success parameter in URL
if (isset($_GET['success']) && $_GET['success'] == 1 && empty($success)) {
    $success = "SAT submitted successfully!";
}

// Check for success message from session (after redirect)
if (isset($_SESSION['sat_success'])) {
    $success = $_SESSION['sat_success'];
    unset($_SESSION['sat_success']);    
}



// Determine if we're in view mode (after submission)
$viewMode = ($existingBatch !== null);
$displayRows = $existingRows;
$displayMeta = $existingMeta;


?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>SAT - Samples Approval Tracker | TEK-C</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(17,24,39,.05);
            padding:16px;
            margin-bottom:14px;
        }
        .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .h-title{ margin:0; font-weight:1000; color:#111827; }
        .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }
        .form-label{ font-weight:900; color:#374151; font-size:13px; }
        .form-control, .form-select{
            border:2px solid #e5e7eb;
            border-radius: 12px;
            padding: 10px 12px;
            font-weight: 750;
            font-size: 14px;
        }
        .form-control[readonly], .form-control-plaintext{
            background-color:#f9fafb;
        }
        .sec-head{
            display:flex; align-items:center; gap:10px;
            padding: 10px 12px;
            border-radius: 14px;
            background:#f9fafb;
            border:1px solid #eef2f7;
            margin-bottom:10px;
        }
        .sec-ic{
            width:34px;height:34px;border-radius: 12px;
            display:grid;place-items:center;
            background: rgba(45,156,219,.12);
            color: var(--blue);
            flex:0 0 auto;
        }
        .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
        .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }
        .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
        .grid-4{ display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; }
        @media (max-width: 992px){
            .grid-2, .grid-4{ grid-template-columns: 1fr; }
        }
        .table thead th{
            font-size: 12px;
            color:#6b7280;
            font-weight: 900;
            border-bottom:1px solid #e5e7eb !important;
            background:#f9fafb;
            white-space: nowrap;
        }
        .btn-primary-tek{
            background: var(--blue);
            border:none;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 1000;
            display:inline-flex;
            align-items:center;
            gap:8px;
            box-shadow: 0 12px 26px rgba(45,156,219,.18);
            color:#fff;
        }
        .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }
        .btn-primary-tek:disabled{ opacity:0.6; cursor:not-allowed; }
        .badge-pill{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 10px; border-radius:999px;
            border:1px solid #e5e7eb; background:#fff;
            font-weight:900; font-size:12px;
        }
        .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
        .submitted-badge{
            background:#e6f7e6; color:#2e7d32; padding:4px 12px; border-radius:40px;
            font-size:12px; font-weight:700;
        }
        .readonly-table td{
            background:#fefefe;
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
                
                <div class="title-row mb-3">
                    <div>
                        <h1 class="h-title">SAMPLES APPROVAL TRACKER (SAT)</h1>
                        <p class="h-sub">Sample status, quotation tracking & approval workflow</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($preparedBy); ?></span>
                        <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($empRow['designation'] ?? ($_SESSION['designation'] ?? '')); ?></span>
                        <?php if ($viewMode): ?>
                            <span class="submitted-badge"><i class="bi bi-check-circle-fill"></i> Submitted · Batch: <?php echo e($existingBatch); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
<form method="POST" autocomplete="off" onsubmit="return preventDoubleSubmission(this);">
                        <input type="hidden" name="submit_sat" value="1">
                    
                    <!-- Project Information Panel -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-building"></i></div>
                            <div>
                                <p class="sec-title mb-0">Project Information</p>
                                <p class="sec-sub mb-0">Client, Architect & PMC details</p>
                            </div>
                        </div>
                        <div class="grid-4">
                            <div>
                                <label class="form-label">PROJECT <span class="text-danger">*</span></label>
                                <input type="text" name="project_name" class="form-control" value="<?php echo e($displayMeta['project'] ?? ''); ?>" <?php echo $viewMode ? 'readonly' : 'required'; ?>>
                            </div>
                            <div>
                                <label class="form-label">CLIENT <span class="text-danger">*</span></label>
                                <input type="text" name="client_name" class="form-control" value="<?php echo e($displayMeta['client'] ?? ''); ?>" <?php echo $viewMode ? 'readonly' : 'required'; ?>>
                            </div>
                            <div>
                                <label class="form-label">ARCHITECTS</label>
                                <input type="text" name="architects" class="form-control" value="<?php echo e($displayMeta['architects'] ?? ''); ?>" <?php echo $viewMode ? 'readonly' : ''; ?>>
                            </div>
                            <div>
                                <label class="form-label">PMC</label>
                                <input type="text" name="pmc" class="form-control" value="<?php echo e($displayMeta['pmc'] ?? ''); ?>" <?php echo $viewMode ? 'readonly' : ''; ?>>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">REVISIONS / DATED</label>
                            <input type="text" name="revisions" class="form-control" style="width:300px;" value="<?php echo e($displayMeta['revisions'] ?? ''); ?>" <?php echo $viewMode ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                    
                    <!-- Samples Table Panel -->
                    <div class="panel">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <div class="sec-head mb-0" style="flex:1;">
                                <div class="sec-ic"><i class="bi bi-table"></i></div>
                                <div>
                                    <p class="sec-title mb-0">Sample & Quotation Matrix</p>
                                    <p class="sec-sub mb-0">Track samples, vendor quotes and approval status</p>
                                </div>
                            </div>
                            <?php if (!$viewMode): ?>
                                <button type="button" class="btn btn-outline-primary btn-addrow" id="addRowBtn">
                                    <i class="bi bi-plus-circle"></i> Add Row
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:45px;">#</th>
                                        <th>SAMPLES / MATERIAL</th>
                                        <th>VENDORS</th>
                                        <th colspan="2">SAMPLE STATUS</th>
                                        <th colspan="2">QUOTE STATUS</th>
                                        <th colspan="2">APPROVAL STATUS (✓)</th>
                                        <th style="min-width:180px;">COMMENTS / ACTION</th>
                                        <?php if (!$viewMode): ?><th style="width:50px;"></th><?php endif; ?>
                                    </tr>
                                    <tr class="bg-light">
                                        <th></th><th></th><th></th>
                                        <th>DELIVERED</th><th>DATE</th>
                                        <th>RECEIVED</th><th>DATE</th>
                                        <th>✓</th><th>✗</th>
                                        <th></th>
                                        <?php if (!$viewMode): ?><th></th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <?php if (!empty($displayRows)): ?>
                                        <?php foreach ($displayRows as $index => $row): ?>
                                        <tr class="data-row">
                                            <td class="text-center fw-semibold sl-cell"><?php echo $index + 1; ?></td>
                                            <td>
                                                <input type="text" name="sample_name[]" class="form-control form-control-sm" 
                                                    value="<?php echo e($row['sample_name']); ?>" placeholder="Material/Sample"
                                                    <?php echo $viewMode ? 'readonly' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="text" name="vendor_name[]" class="form-control form-control-sm" 
                                                    value="<?php echo e($row['vendor_name']); ?>" placeholder="Vendor"
                                                    <?php echo $viewMode ? 'readonly' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" name="sample_delivered[]" class="form-check-input" 
                                                    <?php echo $row['sample_delivered'] ? 'checked' : ''; ?>
                                                    <?php echo $viewMode ? 'disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="date" name="sample_delivered_date[]" class="form-control form-control-sm" 
                                                    value="<?php echo e($row['sample_delivered_date']); ?>"
                                                    <?php echo $viewMode ? 'readonly' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" name="quote_received[]" class="form-check-input" 
                                                    <?php echo $row['quote_received'] ? 'checked' : ''; ?>
                                                    <?php echo $viewMode ? 'disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <input type="date" name="quote_received_date[]" class="form-control form-control-sm" 
                                                    value="<?php echo e($row['quote_received_date']); ?>"
                                                    <?php echo $viewMode ? 'readonly' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" name="approved[]" class="form-check-input" 
                                                    <?php echo $row['approved'] ? 'checked' : ''; ?>
                                                    <?php echo $viewMode ? 'disabled' : ''; ?>>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" name="rejected[]" class="form-check-input" 
                                                    <?php echo $row['rejected'] ? 'checked' : ''; ?>
                                                    <?php echo $viewMode ? 'disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <textarea name="comments[]" class="form-control form-control-sm" rows="1" 
                                                    placeholder="Action / remarks" <?php echo $viewMode ? 'readonly' : ''; ?>><?php echo e($row['comments']); ?></textarea>
                                            </td>
                                            <?php if (!$viewMode): ?>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger delRowBtn"><i class="bi bi-trash"></i></button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr class="data-row">
                                            <td class="text-center fw-semibold sl-cell">1</td>
                                            <td><input type="text" name="sample_name[]" class="form-control form-control-sm" placeholder="e.g. Vitrified Tile, GI Pipe"></td>
                                            <td><input type="text" name="vendor_name[]" class="form-control form-control-sm" placeholder="Vendor name"></td>
                                            <td class="text-center"><input type="checkbox" name="sample_delivered[]" class="form-check-input"></td>
                                            <td><input type="date" name="sample_delivered_date[]" class="form-control form-control-sm"></td>
                                            <td class="text-center"><input type="checkbox" name="quote_received[]" class="form-check-input"></td>
                                            <td><input type="date" name="quote_received_date[]" class="form-control form-control-sm"></td>
                                            <td class="text-center"><input type="checkbox" name="approved[]" class="form-check-input"></td>
                                            <td class="text-center"><input type="checkbox" name="rejected[]" class="form-check-input"></td>
                                            <td><textarea name="comments[]" class="form-control form-control-sm" rows="1" placeholder="Action / remarks"></textarea></td>
                                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRowBtn"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> At least one sample entry required.</div>
                    </div>
                    
                    <!-- Submit Panel -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-send"></i></div>
                            <div>
                                <p class="sec-title mb-0">Report Distribution</p>
                                <p class="sec-sub mb-0">Default includes Client + Manager + Director</p>
                            </div>
                        </div>
                        <div class="grid-2">
                            <div>
                                <label class="form-label">Prepared By</label>
                                <input type="text" class="form-control" value="<?php echo e($preparedBy); ?>" readonly>
                            </div>
                            <div class="d-flex justify-content-end align-items-end gap-2">
                                <button type="button" class="btn btn-outline-secondary" id="previewBtn" style="border-radius:12px; font-weight:900;">
                                    <i class="bi bi-eye"></i> Preview Report
                                </button>
                                <?php if ($viewMode && $existingBatch): ?>
                                    <a href="sat_report_pdf.php?batch_id=<?php echo urlencode($existingBatch); ?>" target="_blank" class="btn-primary-tek" style="text-decoration:none;">
                                        <i class="bi bi-file-pdf"></i> Download PDF
                                    </a>
                                <?php else: ?>
                                    <button type="submit" class="btn-primary-tek">
                                        <i class="bi bi-check2-circle"></i> Submit SAT
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    let isSubmitting = false;

function preventDoubleSubmission(form) {
    if (isSubmitting) {
        alert('Form is already being submitted. Please wait...');
        return false;
    }
    
    // Check if at least one sample entry has data
    let hasValidEntry = false;
    const sampleNames = document.querySelectorAll('input[name="sample_name[]"]');
    const vendorNames = document.querySelectorAll('input[name="vendor_name[]"]');
    
    for(let i = 0; i < sampleNames.length; i++) {
        if((sampleNames[i].value && sampleNames[i].value.trim() !== '') || 
           (vendorNames[i].value && vendorNames[i].value.trim() !== '')) {
            hasValidEntry = true;
            break;
        }
    }
    
    if(!hasValidEntry) {
        alert('Please add at least one sample/vendor entry before submitting.');
        return false;
    }
    
    isSubmitting = true;
    const submitBtn = form.querySelector('button[type="submit"]');
    if(submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Submitting...';
    }
    
    return true;
}
document.addEventListener('DOMContentLoaded', function(){
    const tableBody = document.getElementById('tableBody');
    const addRowBtn = document.getElementById('addRowBtn');
    const previewBtn = document.getElementById('previewBtn');
    const viewMode = <?php echo $viewMode ? 'true' : 'false'; ?>;
    
    if(viewMode) return; // No dynamic features in view mode
    
    function updateSerialNumbers(){
        document.querySelectorAll('#tableBody .data-row').forEach(function(tr, idx){
            var sl = tr.querySelector('.sl-cell');
            if(sl) sl.textContent = String(idx + 1);
        });
    }
    
    function addEmptyRow(){
        const tb = document.getElementById('tableBody');
        if(!tb) return;
        const tr = document.createElement('tr');
        tr.className = 'data-row';
        const rowNum = tb.children.length + 1;
        tr.innerHTML = `
            <td class="text-center fw-semibold sl-cell">${rowNum}</td>
            <td><input type="text" name="sample_name[]" class="form-control form-control-sm" placeholder="Material/Sample"></td>
            <td><input type="text" name="vendor_name[]" class="form-control form-control-sm" placeholder="Vendor"></td>
            <td class="text-center"><input type="checkbox" name="sample_delivered[]" class="form-check-input"></td>
            <td><input type="date" name="sample_delivered_date[]" class="form-control form-control-sm"></td>
            <td class="text-center"><input type="checkbox" name="quote_received[]" class="form-check-input"></td>
            <td><input type="date" name="quote_received_date[]" class="form-control form-control-sm"></td>
            <td class="text-center"><input type="checkbox" name="approved[]" class="form-check-input"></td>
            <td class="text-center"><input type="checkbox" name="rejected[]" class="form-check-input"></td>
            <td><textarea name="comments[]" class="form-control form-control-sm" rows="1" placeholder="Action / remarks"></textarea></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger delRowBtn"><i class="bi bi-trash"></i></button></td>
        `;
        tb.appendChild(tr);
        attachDeleteEvents();
        updateSerialNumbers();
    }
    
    function attachDeleteEvents(){
        document.querySelectorAll('.delRowBtn').forEach(function(btn){
            btn.removeEventListener('click', handleDelete);
            btn.addEventListener('click', handleDelete);
        });
    }
    
    function handleDelete(e){
        const row = e.target.closest('.data-row');
        const tb = row.parentNode;
        if(tb.children.length <= 1){
            row.querySelectorAll('input, textarea').forEach(function(el){
                if(el.type === 'checkbox') el.checked = false;
                else el.value = '';
            });
        } else {
            row.remove();
            updateSerialNumbers();
        }
    }
    
    function previewReport(){
        const project = document.querySelector('input[name="project_name"]').value;
        const client = document.querySelector('input[name="client_name"]').value;
        const architects = document.querySelector('input[name="architects"]').value;
        const pmc = document.querySelector('input[name="pmc"]').value;
        const revisions = document.querySelector('input[name="revisions"]').value;
        const preparedBy = '<?php echo e($preparedBy); ?>';
        
        const rows = [];
        document.querySelectorAll('#tableBody .data-row').forEach(function(row, idx){
            const inputs = row.querySelectorAll('input, textarea');
            if(inputs.length >= 10){
                rows.push({
                    sl: idx + 1,
                    sample: inputs[0]?.value || '—',
                    vendor: inputs[1]?.value || '—',
                    sampleDelivered: inputs[2]?.checked || false,
                    sampleDate: inputs[3]?.value || '—',
                    quoteReceived: inputs[4]?.checked || false,
                    quoteDate: inputs[5]?.value || '—',
                    approved: inputs[6]?.checked || false,
                    rejected: inputs[7]?.checked || false,
                    comments: inputs[8]?.value || '—'
                });
            }
        });
        
        const win = window.open('', '_blank');
        win.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Samples Approval Tracker Report</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    body{ padding:30px; font-family:Arial, sans-serif; }
                    .report-header{ margin-bottom:25px; border-bottom:2px solid #1e4663; padding-bottom:15px; }
                    .report-title{ font-size:22px; font-weight:bold; color:#1e4663; }
                    .meta-grid{ display:flex; flex-wrap:wrap; gap:20px; margin:20px 0; background:#f8fafc; padding:15px; border-radius:8px; }
                    .meta-item{ flex:1; min-width:180px; }
                    .meta-label{ font-size:11px; text-transform:uppercase; color:#6b7280; font-weight:600; }
                    .meta-value{ font-size:14px; font-weight:600; color:#1e4663; }
                    table{ width:100%; border-collapse:collapse; font-size:11px; }
                    th{ background:#f1f5f9; padding:10px 8px; border:1px solid #ddd; text-align:center; font-weight:700; }
                    td{ padding:8px; border:1px solid #ddd; vertical-align:middle; }
                    .footer{ margin-top:30px; text-align:center; font-size:10px; color:#999; border-top:1px solid #eee; padding-top:15px; }
                    @media print{ body{ padding:15px; } .no-print{ display:none; } }
                </style>
            </head>
            <body>
                <div class="report-header">
                    <div class="report-title">SAMPLES APPROVAL TRACKER (SAT)</div>
                    <div class="meta-grid">
                        <div class="meta-item"><div class="meta-label">PROJECT</div><div class="meta-value">${escapeHtml(project)}</div></div>
                        <div class="meta-item"><div class="meta-label">CLIENT</div><div class="meta-value">${escapeHtml(client)}</div></div>
                        <div class="meta-item"><div class="meta-label">ARCHITECTS</div><div class="meta-value">${escapeHtml(architects)}</div></div>
                        <div class="meta-item"><div class="meta-label">PMC</div><div class="meta-value">${escapeHtml(pmc)}</div></div>
                        <div class="meta-item"><div class="meta-label">REVISIONS</div><div class="meta-value">${escapeHtml(revisions)}</div></div>
                    </div>
                </div>
                <table class="table-bordered">
                    <thead>
                        <tr><th>SL NO</th><th>SAMPLES</th><th>VENDORS</th><th colspan="2">SAMPLE STATUS</th><th colspan="2">QUOTE STATUS</th><th colspan="2">APPROVAL STATUS</th><th>COMMENTS</th></tr>
                        <tr style="background:#f9fafb;"><th></th><th></th><th></th><th>DELIVERED</th><th>DATE</th><th>RECEIVED</th><th>DATE</th><th>✓</th><th>✗</th><th></th></tr>
                    </thead>
                    <tbody>
        `);
        
        rows.forEach(function(row){
            win.document.write(`
                <tr>
                    <td class="text-center">${row.sl}</td>
                    <td>${escapeHtml(row.sample)}</td><td>${escapeHtml(row.vendor)}</td>
                    <td class="text-center">${row.sampleDelivered ? '✓' : '—'}</td><td>${row.sampleDate}</td>
                    <td class="text-center">${row.quoteReceived ? '✓' : '—'}</td><td>${row.quoteDate}</td>
                    <td class="text-center">${row.approved ? '✓' : '—'}</td>
                    <td class="text-center">${row.rejected ? '✗' : '—'}</td>
                    <td>${escapeHtml(row.comments)}</td>
                </tr>
            `);
        });
        
        if(rows.length === 0){
            win.document.write(`<tr><td colspan="10" class="text-center">No entries found.</td></tr>`);
        }
        
        win.document.write(`
                    </tbody>
                </table>
                <div class="footer">Generated on ${new Date().toLocaleString()} | Prepared by: ${escapeHtml(preparedBy)}</div>
                <div class="no-print text-center mt-3">
                    <button onclick="window.print();" class="btn btn-primary">Print / Save as PDF</button>
                    <button onclick="window.close();" class="btn btn-secondary">Close</button>
                </div>
            </body>
            </html>
        `);
        win.document.close();
    }
    
    function escapeHtml(str){
        if(!str) return '';
        return String(str).replace(/[&<>]/g, function(m){
            if(m === '&') return '&amp;';
            if(m === '<') return '&lt;';
            if(m === '>') return '&gt;';
            return m;
        });
    }
    
    if(addRowBtn) addRowBtn.addEventListener('click', addEmptyRow);
    if(previewBtn) previewBtn.addEventListener('click', previewReport);
    attachDeleteEvents();
    updateSerialNumbers();
});
</script>
</body>
</html>