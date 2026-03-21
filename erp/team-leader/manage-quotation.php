<?php
// manage-quotation.php (Team Lead) — manage a specific quotation request
// Allows TL to add dealers, upload quotations, and submit to QS

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$quotation_request = null;
$dealers = [];
$assigned_dealers = [];
$quotations = [];

// ---------- Auth (Team Lead only) ----------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$empId = (int)$_SESSION['employee_id'];
$empName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown User';
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Allow Team Leads and Project Engineers
$allowed = [
    'team lead',
    'project engineer grade 1',
    'project engineer grade 2',
    'sr. engineer'
];
if (!in_array($designation, $allowed, true)) {
    header("Location: index.php");
    exit;
}

// Get request ID from URL
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($request_id === 0) {
    header('Location: assigned-quotations.php?status=error&message=' . urlencode('Invalid request ID'));
    exit;
}

// ---------- Handle Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    if ($action === 'add_dealer') {
        $dealer_id = intval($_POST['dealer_id'] ?? 0);
        $expected_date = isset($_POST['expected_date']) && !empty($_POST['expected_date']) ? $_POST['expected_date'] : null;
        $notes = isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : '';
        
        if ($dealer_id > 0) {
            $check_query = "SELECT id FROM quotation_requests_dealers WHERE quotation_request_id = ? AND dealer_id = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "ii", $request_id, $dealer_id);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            
            if (mysqli_stmt_num_rows($check_stmt) == 0) {
                $insert_query = "INSERT INTO quotation_requests_dealers (quotation_request_id, dealer_id, assigned_by, assigned_by_name, assigned_at, expected_quotation_date, notes, status) VALUES (?, ?, ?, ?, NOW(), ?, ?, 'Pending')";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($insert_stmt, "iisiss", $request_id, $dealer_id, $empId, $empName, $expected_date, $notes);
                if (mysqli_stmt_execute($insert_stmt)) {
                    $success = "Dealer added successfully!";
                    // Refresh to show new dealer
                    header("Location: manage-quotation.php?id=" . $request_id . "&status=success&message=" . urlencode($success));
                    exit();
                } else {
                    $error = "Failed to add dealer: " . mysqli_error($conn);
                }
                mysqli_stmt_close($insert_stmt);
            } else {
                $error = "Dealer already assigned to this request.";
            }
            mysqli_stmt_close($check_stmt);
        } else {
            $error = "Please select a dealer.";
        }
        
    } elseif ($action === 'upload_quotation') {
        $request_dealer_id = intval($_POST['request_dealer_id'] ?? 0);
        $quotation_no = isset($_POST['quotation_no']) ? htmlspecialchars($_POST['quotation_no']) : '';
        $dealer_quotation_ref = isset($_POST['dealer_quotation_ref']) ? htmlspecialchars($_POST['dealer_quotation_ref']) : '';
        $quotation_date = isset($_POST['quotation_date']) ? $_POST['quotation_date'] : date('Y-m-d');
        $valid_until = isset($_POST['valid_until']) && !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
        $delivery_terms = isset($_POST['delivery_terms']) ? htmlspecialchars($_POST['delivery_terms']) : '';
        $payment_terms = isset($_POST['payment_terms']) ? htmlspecialchars($_POST['payment_terms']) : '';
        $warranty = isset($_POST['warranty']) ? htmlspecialchars($_POST['warranty']) : '';
        $grand_total = isset($_POST['grand_total']) ? floatval($_POST['grand_total']) : 0;
        
        if ($request_dealer_id > 0 && !empty($quotation_no) && $grand_total > 0) {
            // Handle file upload
            $quotation_document = null;
            if (isset($_FILES['quotation_document']) && $_FILES['quotation_document']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/quotation_documents/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $extension = pathinfo($_FILES['quotation_document']['name'], PATHINFO_EXTENSION);
                $filename = 'QTN_' . time() . '_' . uniqid() . '.' . $extension;
                $target_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['quotation_document']['tmp_name'], $target_path)) {
                    $quotation_document = $target_path;
                }
            }
            
            // Get dealer_id from quotation_requests_dealers
            $dealer_query = "SELECT dealer_id FROM quotation_requests_dealers WHERE id = ?";
            $dealer_stmt = mysqli_prepare($conn, $dealer_query);
            mysqli_stmt_bind_param($dealer_stmt, "i", $request_dealer_id);
            mysqli_stmt_execute($dealer_stmt);
            $dealer_result = mysqli_stmt_get_result($dealer_stmt);
            $dealer_row = mysqli_fetch_assoc($dealer_result);
            $dealer_id = $dealer_row['dealer_id'];
            mysqli_stmt_close($dealer_stmt);
            
            // Generate quotation number
            $quotation_no_full = 'QT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $insert_query = "INSERT INTO quotations (
                quotation_no, quotation_request_id, dealer_id, dealer_quotation_ref,
                quotation_date, valid_until, delivery_terms, payment_terms, warranty,
                grand_total, quotation_document, submitted_by, submitted_by_name,
                submitted_at, status
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                NOW(), 'Submitted'
            )";
            
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "siissssssdsis", 
                $quotation_no_full, $request_id, $dealer_id, $dealer_quotation_ref,
                $quotation_date, $valid_until, $delivery_terms, $payment_terms, $warranty,
                $grand_total, $quotation_document, $empId, $empName
            );
            
            if (mysqli_stmt_execute($insert_stmt)) {
                // Update the dealer status to 'Quotation Received'
                $update_dealer_query = "UPDATE quotation_requests_dealers SET status = 'Quotation Received', updated_at = NOW() WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_dealer_query);
                mysqli_stmt_bind_param($update_stmt, "i", $request_dealer_id);
                mysqli_stmt_execute($update_stmt);
                mysqli_stmt_close($update_stmt);
                
                // Check if all assigned dealers have submitted quotations
                $check_all_query = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Quotation Received' THEN 1 ELSE 0 END) as received FROM quotation_requests_dealers WHERE quotation_request_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_all_query);
                mysqli_stmt_bind_param($check_stmt, "i", $request_id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                $check_row = mysqli_fetch_assoc($check_result);
                mysqli_stmt_close($check_stmt);
                
                // If all dealers have submitted, update main request status
                if ($check_row['total'] > 0 && $check_row['total'] == $check_row['received']) {
                    $update_request_query = "UPDATE quotation_requests SET status = 'Quotations Received', updated_at = NOW() WHERE id = ?";
                    $update_request_stmt = mysqli_prepare($conn, $update_request_query);
                    mysqli_stmt_bind_param($update_request_stmt, "i", $request_id);
                    mysqli_stmt_execute($update_request_stmt);
                    mysqli_stmt_close($update_request_stmt);
                }
                
                $success = "Quotation uploaded successfully!";
                header("Location: manage-quotation.php?id=" . $request_id . "&status=success&message=" . urlencode($success));
                exit();
            } else {
                $error = "Failed to upload quotation: " . mysqli_error($conn);
            }
            mysqli_stmt_close($insert_stmt);
        } else {
            $error = "Please fill all required fields.";
        }
        
    } elseif ($action === 'submit_to_qs') {
        // Submit all quotations to QS team
        $update_query = "UPDATE quotation_requests SET status = 'With QS', updated_at = NOW() WHERE id = ? AND project_engineer_id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "ii", $request_id, $empId);
        if (mysqli_stmt_execute($update_stmt)) {
            $success = "All quotations submitted to QS team successfully!";
            header("Location: assigned-quotations.php?status=success&message=" . urlencode($success));
            exit();
        } else {
            $error = "Failed to submit quotations: " . mysqli_error($conn);
        }
        mysqli_stmt_close($update_stmt);
    }
}

// ---------- Fetch Quotation Request Details ----------
$request_query = "
    SELECT 
        qr.*,
        s.project_name,
        s.project_code,
        s.project_location,
        s.scope_of_work,
        c.client_name,
        c.company_name,
        m.full_name AS manager_name,
        DATEDIFF(qr.required_by_date, CURDATE()) AS days_remaining
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN employees m ON s.manager_employee_id = m.id
    WHERE qr.id = ? AND (qr.project_engineer_id = ? OR qr.site_id IN (SELECT id FROM sites WHERE team_lead_employee_id = ?))
";

$stmt = mysqli_prepare($conn, $request_query);
mysqli_stmt_bind_param($stmt, "iii", $request_id, $empId, $empId);
mysqli_stmt_execute($stmt);
$request_result = mysqli_stmt_get_result($stmt);
$quotation_request = mysqli_fetch_assoc($request_result);
mysqli_stmt_close($stmt);

if (!$quotation_request) {
    header('Location: assigned-quotations.php?status=error&message=' . urlencode('Quotation request not found or not assigned to you'));
    exit;
}

// ---------- Fetch all active dealers ----------
$dealers_query = "SELECT id, dealer_code, dealer_name, contact_person, mobile_number, email FROM quotation_dealers WHERE status = 'Active' ORDER BY dealer_name ASC";
$dealers_result = mysqli_query($conn, $dealers_query);
$dealers = mysqli_fetch_all($dealers_result, MYSQLI_ASSOC);

// ---------- Fetch assigned dealers for this request ----------
$assigned_dealers_query = "
    SELECT 
        qrd.*,
        d.dealer_name,
        d.contact_person,
        d.mobile_number,
        d.email
    FROM quotation_requests_dealers qrd
    JOIN quotation_dealers d ON qrd.dealer_id = d.id
    WHERE qrd.quotation_request_id = ?
    ORDER BY qrd.assigned_at ASC
";
$stmt = mysqli_prepare($conn, $assigned_dealers_query);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$assigned_dealers_result = mysqli_stmt_get_result($stmt);
$assigned_dealers = mysqli_fetch_all($assigned_dealers_result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// ---------- Fetch quotations for this request ----------
$quotations_query = "
    SELECT 
        q.*,
        d.dealer_name
    FROM quotations q
    JOIN quotation_dealers d ON q.dealer_id = d.id
    WHERE q.quotation_request_id = ?
    ORDER BY q.submitted_at DESC
";
$stmt = mysqli_prepare($conn, $quotations_query);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$quotations_result = mysqli_stmt_get_result($stmt);
$quotations = mysqli_fetch_all($quotations_result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Check if all assigned dealers have submitted quotations
$all_submitted = false;
$total_dealers = count($assigned_dealers);
$submitted_count = 0;
foreach ($assigned_dealers as $dealer) {
    if ($dealer['status'] === 'Quotation Received') {
        $submitted_count++;
    }
}
if ($total_dealers > 0 && $total_dealers == $submitted_count) {
    $all_submitted = true;
}

// Helper functions
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}
function formatCurrency($amount) {
    if ($amount === null || $amount == 0) return '—';
    return '₹ ' . number_format($amount, 2);
}
function getStatusBadge($status) {
    $badges = [
        'Pending' => ['bg-warning', 'bi-clock'],
        'Quotation Received' => ['bg-primary', 'bi-file-text'],
        'Rejected' => ['bg-danger', 'bi-x-circle'],
        'Withdrawn' => ['bg-secondary', 'bi-x']
    ];
    $badge = $badges[$status] ?? ['bg-secondary', 'bi-question'];
    return '<span class="badge ' . $badge[0] . '"><i class="bi ' . $badge[1] . ' me-1"></i>' . $status . '</span>';
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
    <title>Manage Quotation - TEK-C</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <!-- Flatpickr -->
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

        .proj-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
        .proj-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.25; }

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

        .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

        .form-label{ font-weight:800; color:#4b5563; font-size:12px; margin-bottom:4px; }
        .form-control, .form-select{ border:1px solid var(--border); border-radius:10px; padding:8px 12px; font-weight:600; font-size:13px; }

        .dealer-item, .quotation-item {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
            background: #f9fafb;
            transition: all 0.2s;
        }
        .dealer-item:hover, .quotation-item:hover {
            border-color: var(--blue);
            background: #fff;
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

                    <!-- Header -->
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-dark mb-1">Manage Quotation</h1>
                            <p class="text-muted mb-0">
                                Request #<?php echo e($quotation_request['request_no']); ?> • <?php echo e($quotation_request['title']); ?>
                            </p>
                        </div>
                        <div>
                            <a href="assigned-quotations.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-building"></i></div>
                                <div>
                                    <div class="stat-label">Project</div>
                                    <div class="stat-value" style="font-size:20px;"><?php echo e($quotation_request['project_name']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-person"></i></div>
                                <div>
                                    <div class="stat-label">Client</div>
                                    <div class="stat-value" style="font-size:20px;"><?php echo e($quotation_request['client_name'] ?? '—'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic yellow"><i class="bi bi-calendar"></i></div>
                                <div>
                                    <div class="stat-label">Required By</div>
                                    <div class="stat-value" style="font-size:20px;"><?php echo safeDate($quotation_request['required_by_date']); ?></div>
                                    <?php if (!empty($quotation_request['days_remaining']) && $quotation_request['days_remaining'] > 0): ?>
                                        <div class="proj-sub"><?php echo $quotation_request['days_remaining']; ?> days left</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic red"><i class="bi bi-flag"></i></div>
                                <div>
                                    <div class="stat-label">Priority</div>
                                    <div class="stat-value" style="font-size:20px;"><?php echo e($quotation_request['priority']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Description Panel -->
                    <div class="panel mb-4">
                        <div class="panel-header">
                            <h3 class="panel-title">Description</h3>
                            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                        </div>
                        <div class="description-box" style="background:#f9fafb; border-radius:12px; padding:16px;">
                            <?php echo nl2br(e($quotation_request['description'])); ?>
                        </div>
                        <?php if (!empty($quotation_request['specifications'])): ?>
                            <div class="mt-3">
                                <h4 class="fw-900 mb-2" style="font-size:14px;">Specifications</h4>
                                <div class="description-box" style="background:#f9fafb; border-radius:12px; padding:16px;">
                                    <?php echo nl2br(e($quotation_request['specifications'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-4">
                        <!-- Left Column: Dealers -->
                        <div class="col-lg-6">
                            <!-- Add Dealer Panel -->
                            <div class="panel mb-4">
                                <div class="panel-header">
                                    <h3 class="panel-title">Add Dealer</h3>
                                    <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                                </div>
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="add_dealer">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">Select Dealer</label>
                                            <select class="form-select select2" name="dealer_id" required>
                                                <option value="">-- Select Dealer --</option>
                                                <?php foreach ($dealers as $dealer): ?>
                                                    <option value="<?php echo $dealer['id']; ?>">
                                                        <?php echo e($dealer['dealer_name']); ?> (<?php echo e($dealer['dealer_code']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Expected Date</label>
                                            <input type="text" class="form-control datepicker" name="expected_date" placeholder="YYYY-MM-DD">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Notes</label>
                                            <textarea class="form-control" name="notes" rows="2" placeholder="Any specific instructions..."></textarea>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary w-100">Add Dealer</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Assigned Dealers Panel -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h3 class="panel-title">Assigned Dealers</h3>
                                    <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                                </div>
                                <?php if (empty($assigned_dealers)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-shop" style="font-size: 32px;"></i>
                                        <p class="mt-2 fw-bold">No dealers assigned yet</p>
                                        <p class="small">Add dealers to start collecting quotations.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($assigned_dealers as $dealer): ?>
                                        <div class="dealer-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h5 class="fw-900 mb-1"><?php echo e($dealer['dealer_name']); ?></h5>
                                                    <div class="proj-sub">
                                                        <i class="bi bi-person"></i> <?php echo e($dealer['contact_person'] ?? '—'); ?>
                                                        <?php if (!empty($dealer['mobile_number'])): ?>
                                                            • <i class="bi bi-telephone"></i> <?php echo e($dealer['mobile_number']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php echo getStatusBadge($dealer['status']); ?>
                                            </div>
                                            <?php if ($dealer['status'] === 'Pending'): ?>
                                                <div class="mt-3">
                                                    <button type="button" class="btn-action" data-bs-toggle="modal" data-bs-target="#uploadModal<?php echo $dealer['id']; ?>">
                                                        <i class="bi bi-upload"></i> Upload Quotation
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($dealer['expected_quotation_date'])): ?>
                                                <div class="mt-2 proj-sub">
                                                    <i class="bi bi-calendar"></i> Expected: <?php echo safeDate($dealer['expected_quotation_date']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Upload Quotation Modal -->
                                        <div class="modal fade" id="uploadModal<?php echo $dealer['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title fw-900">Upload Quotation - <?php echo e($dealer['dealer_name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="" enctype="multipart/form-data">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="action" value="upload_quotation">
                                                            <input type="hidden" name="request_dealer_id" value="<?php echo $dealer['id']; ?>">
                                                            
                                                            <div class="row g-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label required">Quotation No.</label>
                                                                    <input type="text" class="form-control" name="quotation_no" required placeholder="Your reference">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Dealer Ref.</label>
                                                                    <input type="text" class="form-control" name="dealer_quotation_ref" placeholder="Dealer's reference">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label required">Quotation Date</label>
                                                                    <input type="text" class="form-control datepicker" name="quotation_date" value="<?php echo date('Y-m-d'); ?>" required>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Valid Until</label>
                                                                    <input type="text" class="form-control datepicker" name="valid_until">
                                                                </div>
                                                                <div class="col-md-12">
                                                                    <label class="form-label required">Grand Total (₹)</label>
                                                                    <input type="number" class="form-control" name="grand_total" step="0.01" required placeholder="0.00">
                                                                </div>
                                                                <div class="col-md-12">
                                                                    <label class="form-label">Delivery Terms</label>
                                                                    <input type="text" class="form-control" name="delivery_terms" placeholder="e.g., 7-10 days">
                                                                </div>
                                                                <div class="col-md-12">
                                                                    <label class="form-label">Payment Terms</label>
                                                                    <input type="text" class="form-control" name="payment_terms" placeholder="e.g., 30% advance">
                                                                </div>
                                                                <div class="col-md-12">
                                                                    <label class="form-label">Warranty</label>
                                                                    <input type="text" class="form-control" name="warranty" placeholder="e.g., 1 year">
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label">Document (PDF/Excel)</label>
                                                                    <input type="file" class="form-control" name="quotation_document" accept=".pdf,.xlsx,.xls,.doc,.docx">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Upload Quotation</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right Column: Quotations Received -->
                        <div class="col-lg-6">
                            <div class="panel">
                                <div class="panel-header">
                                    <h3 class="panel-title">Quotations Received</h3>
                                    <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                                </div>
                                <?php if (empty($quotations)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox" style="font-size: 32px;"></i>
                                        <p class="mt-2 fw-bold">No quotations uploaded yet</p>
                                        <p class="small">Upload quotations from assigned dealers.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($quotations as $quote): ?>
                                        <div class="quotation-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h5 class="fw-900 mb-1"><?php echo e($quote['quotation_no']); ?></h5>
                                                    <div class="proj-sub">
                                                        <i class="bi bi-shop"></i> <?php echo e($quote['dealer_name']); ?>
                                                        <?php if (!empty($quote['dealer_quotation_ref'])): ?>
                                                            • Ref: <?php echo e($quote['dealer_quotation_ref']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span class="badge bg-primary">Submitted</span>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <div class="proj-sub">Amount</div>
                                                    <div class="fw-900 text-success"><?php echo formatCurrency($quote['grand_total']); ?></div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="proj-sub">Date</div>
                                                    <div><?php echo safeDate($quote['quotation_date']); ?></div>
                                                </div>
                                            </div>
                                            <?php if (!empty($quote['delivery_terms']) || !empty($quote['payment_terms'])): ?>
                                                <div class="mt-2">
                                                    <?php if (!empty($quote['delivery_terms'])): ?>
                                                        <span class="badge bg-info me-1">Delivery: <?php echo e($quote['delivery_terms']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($quote['payment_terms'])): ?>
                                                        <span class="badge bg-secondary">Payment: <?php echo e($quote['payment_terms']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($quote['quotation_document'])): ?>
                                                <div class="mt-2">
                                                    <a href="<?php echo e($quote['quotation_document']); ?>" target="_blank" class="btn-action">
                                                        <i class="bi bi-file-pdf"></i> View Document
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Submit to QS Button Panel -->
                    <div class="panel mt-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="panel-title mb-0">Ready to Submit to QS?</h3>
                                <p class="proj-sub mt-1 mb-0">Once submitted, QS team will review and negotiate with dealers.</p>
                            </div>
                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to submit all quotations to the QS team? You cannot make changes after submission.');">
                                <input type="hidden" name="action" value="submit_to_qs">
                                <button type="submit" class="btn btn-success btn-lg" <?php echo empty($quotations) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-send"></i> Submit to QS
                                </button>
                            </form>
                        </div>
                        <?php if ($total_dealers > 0 && !$all_submitted): ?>
                            <div class="mt-3 text-warning">
                                <i class="bi bi-info-circle"></i> 
                                <?php echo $submitted_count; ?> out of <?php echo $total_dealers; ?> dealers have submitted quotations. 
                                Please wait for all quotations before submitting.
                            </div>
                        <?php endif; ?>
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
        $(document).ready(function() {
            $('.select2').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Select a dealer'
            });
            
            flatpickr(".datepicker", {
                dateFormat: "Y-m-d",
                allowInput: true
            });
        });
    </script>
</body>
</html>