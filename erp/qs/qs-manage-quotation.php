<?php
// qs-manage-quotation.php – QS manages a single quotation request: view details, add/edit/delete quotations, manage items, finalize.

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error = '';
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Auth: QS only
if (empty($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}
$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$department = strtolower(trim((string)($_SESSION['department'] ?? '')));
$is_qs = ($department === 'qs' || strpos($designation, 'qs') !== false);
if (!$is_qs) {
    header("Location: index.php");
    exit;
}
if ($request_id <= 0) {
    header("Location: qs-quotations.php");
    exit;
}

// Fetch the request, ensure it's assigned to this QS
$req_query = "
    SELECT qr.*, s.project_name, s.project_code, c.client_name, c.company_name,
           m.full_name AS manager_name, tl.full_name AS team_lead_name
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN employees m ON s.manager_employee_id = m.id
    LEFT JOIN employees tl ON s.team_lead_employee_id = tl.id
    WHERE qr.id = ? AND qr.qs_employee_id = ?
";
$stmt = mysqli_prepare($conn, $req_query);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $empId);
mysqli_stmt_execute($stmt);
$req_res = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($req_res);
mysqli_stmt_close($stmt);
if (!$request) {
    header("Location: qs-quotations.php");
    exit;
}

// Handle file upload for quotation document
function handleFileUpload($file, $quotation_id) {
    $target_dir = "../uploads/quotations/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'doc', 'docx'];
    if (!in_array($file_extension, $allowed_types)) {
        return ["error" => "Invalid file type. Allowed: PDF, JPG, PNG, Excel, Word."];
    }
    if ($file["size"] > 5 * 1024 * 1024) { // 5 MB
        return ["error" => "File size too large. Max 5 MB."];
    }
    $new_filename = "quotation_" . $quotation_id . "_" . time() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return ["success" => $target_file];
    } else {
        return ["error" => "Failed to upload file."];
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    // Add quotation (manual or from dealer)
    if ($action === 'add_quotation') {
        $dealer_id = intval($_POST['dealer_id'] ?? 0);
        $total_amount = floatval($_POST['total_amount'] ?? 0);
        $delivery_terms = trim($_POST['delivery_terms'] ?? '');
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $warranty = trim($_POST['warranty'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $quotation_document = null;

        if (isset($_FILES['quotation_document']) && $_FILES['quotation_document']['error'] === UPLOAD_ERR_OK) {
            $upload = handleFileUpload($_FILES['quotation_document'], 0); // placeholder
            if (isset($upload['error'])) {
                $error = $upload['error'];
                goto after_add;
            } else {
                $quotation_document = $upload['success'];
            }
        }

        if ($dealer_id <= 0 || $total_amount <= 0) {
            $error = "Please select a dealer and enter a valid total amount.";
        } else {
            // Verify dealer exists and is active
            $check_dealer = "SELECT dealer_name FROM quotation_dealers WHERE id = ? AND status = 'Active'";
            $stmt = mysqli_prepare($conn, $check_dealer);
            mysqli_stmt_bind_param($stmt, "i", $dealer_id);
            mysqli_stmt_execute($stmt);
            $dealer_res = mysqli_stmt_get_result($stmt);
            $dealer = mysqli_fetch_assoc($dealer_res);
            mysqli_stmt_close($stmt);
            if (!$dealer) {
                $error = "Invalid or inactive dealer selected.";
            } else {
                $quotation_no = 'QT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $insert = "INSERT INTO quotations (quotation_no, quotation_request_id, dealer_id, total_amount, grand_total, delivery_terms, payment_terms, warranty, remarks, quotation_document, submitted_by, submitted_by_name, submitted_at, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'With QS')";
                $stmt = mysqli_prepare($conn, $insert);
                mysqli_stmt_bind_param($stmt, "siidssssssis", $quotation_no, $request_id, $dealer_id, $total_amount, $total_amount, $delivery_terms, $payment_terms, $warranty, $remarks, $quotation_document, $empId, $_SESSION['employee_name']);
                if (mysqli_stmt_execute($stmt)) {
                    $quotation_id = mysqli_stmt_insert_id($stmt);
                    if ($quotation_document && strpos($quotation_document, "quotation_0_") !== false) {
                        $new_name = str_replace("quotation_0_", "quotation_" . $quotation_id . "_", $quotation_document);
                        rename($quotation_document, $new_name);
                        $update_doc = "UPDATE quotations SET quotation_document = ? WHERE id = ?";
                        $stmt2 = mysqli_prepare($conn, $update_doc);
                        mysqli_stmt_bind_param($stmt2, "si", $new_name, $quotation_id);
                        mysqli_stmt_execute($stmt2);
                        mysqli_stmt_close($stmt2);
                    }
                    $success = "Quotation added successfully.";
                } else {
                    $error = "Failed to add quotation: " . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            }
        }
        after_add:
    }

    // Edit quotation
    if ($action === 'edit_quotation') {
        $quotation_id = intval($_POST['quotation_id']);
        $dealer_id = intval($_POST['dealer_id']);
        $total_amount = floatval($_POST['total_amount']);
        $delivery_terms = trim($_POST['delivery_terms']);
        $payment_terms = trim($_POST['payment_terms']);
        $warranty = trim($_POST['warranty']);
        $remarks = trim($_POST['remarks']);
        $quotation_document = null;

        if (isset($_FILES['quotation_document']) && $_FILES['quotation_document']['error'] === UPLOAD_ERR_OK) {
            $upload = handleFileUpload($_FILES['quotation_document'], $quotation_id);
            if (isset($upload['error'])) {
                $error = $upload['error'];
                goto after_edit;
            } else {
                $quotation_document = $upload['success'];
            }
        }

        if ($dealer_id <= 0 || $total_amount <= 0) {
            $error = "Dealer and total amount are required.";
        } else {
            if ($quotation_document) {
                $update = "UPDATE quotations SET dealer_id = ?, total_amount = ?, grand_total = ?, delivery_terms = ?, payment_terms = ?, warranty = ?, remarks = ?, quotation_document = ? WHERE id = ? AND quotation_request_id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "iddsssssii", $dealer_id, $total_amount, $total_amount, $delivery_terms, $payment_terms, $warranty, $remarks, $quotation_document, $quotation_id, $request_id);
            } else {
                $update = "UPDATE quotations SET dealer_id = ?, total_amount = ?, grand_total = ?, delivery_terms = ?, payment_terms = ?, warranty = ?, remarks = ? WHERE id = ? AND quotation_request_id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "iddssssii", $dealer_id, $total_amount, $total_amount, $delivery_terms, $payment_terms, $warranty, $remarks, $quotation_id, $request_id);
            }
            if (mysqli_stmt_execute($stmt)) {
                $success = "Quotation updated.";
            } else {
                $error = "Failed to update.";
            }
            mysqli_stmt_close($stmt);
        }
        after_edit:
    }

    // Delete quotation
    if ($action === 'delete_quotation') {
        $quotation_id = intval($_POST['quotation_id']);
        $delete = "DELETE FROM quotations WHERE id = ? AND quotation_request_id = ?";
        $stmt = mysqli_prepare($conn, $delete);
        mysqli_stmt_bind_param($stmt, "ii", $quotation_id, $request_id);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Quotation deleted.";
        } else {
            $error = "Failed to delete.";
        }
        mysqli_stmt_close($stmt);
    }

    // Add item to quotation
    if ($action === 'add_item') {
        $quotation_id = intval($_POST['quotation_id']);
        $item_name = trim($_POST['item_name']);
        $quantity = floatval($_POST['quantity']);
        $unit = trim($_POST['unit']);
        $unit_price = floatval($_POST['unit_price']);
        $description = trim($_POST['description']);
        if ($item_name == '' || $quantity <= 0 || $unit_price <= 0) {
            $error = "Item name, quantity, and unit price are required.";
        } else {
            $insert = "INSERT INTO quotation_items (quotation_id, item_name, description, quantity, unit, unit_price, discount_percentage, discount_amount, cgst_amount, sgst_amount, igst_amount)
                       VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0)";
            $stmt = mysqli_prepare($conn, $insert);
            mysqli_stmt_bind_param($stmt, "issdds", $quotation_id, $item_name, $description, $quantity, $unit, $unit_price);
            if (mysqli_stmt_execute($stmt)) {
                // Recalculate total
                $sum_total = "SELECT SUM(quantity * unit_price) AS total FROM quotation_items WHERE quotation_id = ?";
                $stmt2 = mysqli_prepare($conn, $sum_total);
                mysqli_stmt_bind_param($stmt2, "i", $quotation_id);
                mysqli_stmt_execute($stmt2);
                $res2 = mysqli_stmt_get_result($stmt2);
                $row2 = mysqli_fetch_assoc($res2);
                $new_total = floatval($row2['total']);
                mysqli_stmt_close($stmt2);
                $update = "UPDATE quotations SET total_amount = ?, grand_total = ? WHERE id = ?";
                $stmt3 = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt3, "ddi", $new_total, $new_total, $quotation_id);
                mysqli_stmt_execute($stmt3);
                mysqli_stmt_close($stmt3);
                $success = "Item added and quotation total updated.";
            } else {
                $error = "Failed to add item.";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Edit item
    if ($action === 'edit_item') {
        $item_id = intval($_POST['item_id']);
        $quotation_id = intval($_POST['quotation_id']);
        $item_name = trim($_POST['item_name']);
        $quantity = floatval($_POST['quantity']);
        $unit = trim($_POST['unit']);
        $unit_price = floatval($_POST['unit_price']);
        $description = trim($_POST['description']);
        $update = "UPDATE quotation_items SET item_name=?, description=?, quantity=?, unit=?, unit_price=? WHERE id=?";
        $stmt = mysqli_prepare($conn, $update);
        mysqli_stmt_bind_param($stmt, "ssddsi", $item_name, $description, $quantity, $unit, $unit_price, $item_id);
        if (mysqli_stmt_execute($stmt)) {
            $sum_total = "SELECT SUM(quantity * unit_price) AS total FROM quotation_items WHERE quotation_id = ?";
            $stmt2 = mysqli_prepare($conn, $sum_total);
            mysqli_stmt_bind_param($stmt2, "i", $quotation_id);
            mysqli_stmt_execute($stmt2);
            $res2 = mysqli_stmt_get_result($stmt2);
            $row2 = mysqli_fetch_assoc($res2);
            $new_total = floatval($row2['total']);
            mysqli_stmt_close($stmt2);
            $update_q = "UPDATE quotations SET total_amount = ?, grand_total = ? WHERE id = ?";
            $stmt3 = mysqli_prepare($conn, $update_q);
            mysqli_stmt_bind_param($stmt3, "ddi", $new_total, $new_total, $quotation_id);
            mysqli_stmt_execute($stmt3);
            mysqli_stmt_close($stmt3);
            $success = "Item updated.";
        } else {
            $error = "Failed to update item.";
        }
        mysqli_stmt_close($stmt);
    }

    // Delete item
    if ($action === 'delete_item') {
        $item_id = intval($_POST['item_id']);
        $quotation_id = intval($_POST['quotation_id']);
        $delete = "DELETE FROM quotation_items WHERE id = ?";
        $stmt = mysqli_prepare($conn, $delete);
        mysqli_stmt_bind_param($stmt, "i", $item_id);
        if (mysqli_stmt_execute($stmt)) {
            $sum_total = "SELECT SUM(quantity * unit_price) AS total FROM quotation_items WHERE quotation_id = ?";
            $stmt2 = mysqli_prepare($conn, $sum_total);
            mysqli_stmt_bind_param($stmt2, "i", $quotation_id);
            mysqli_stmt_execute($stmt2);
            $res2 = mysqli_stmt_get_result($stmt2);
            $row2 = mysqli_fetch_assoc($res2);
            $new_total = floatval($row2['total']);
            mysqli_stmt_close($stmt2);
            $update_q = "UPDATE quotations SET total_amount = ?, grand_total = ? WHERE id = ?";
            $stmt3 = mysqli_prepare($conn, $update_q);
            mysqli_stmt_bind_param($stmt3, "ddi", $new_total, $new_total, $quotation_id);
            mysqli_stmt_execute($stmt3);
            mysqli_stmt_close($stmt3);
            $success = "Item deleted.";
        } else {
            $error = "Failed to delete item.";
        }
        mysqli_stmt_close($stmt);
    }

    // Finalize
    if ($action === 'finalize') {
        $quotation_id = intval($_POST['quotation_id']);
        $check = "SELECT id FROM quotations WHERE id = ? AND quotation_request_id = ?";
        $stmt = mysqli_prepare($conn, $check);
        mysqli_stmt_bind_param($stmt, "ii", $quotation_id, $request_id);
        mysqli_stmt_execute($stmt);
        $check_res = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($check_res) == 0) {
            $error = "Invalid quotation selected.";
        } else {
            $update = "UPDATE quotation_requests SET status = 'QS Finalized', final_quotation_id = ?, updated_at = NOW() WHERE id = ? AND status = 'With QS'";
            $stmt = mysqli_prepare($conn, $update);
            mysqli_stmt_bind_param($stmt, "ii", $quotation_id, $request_id);
            if (mysqli_stmt_execute($stmt)) {
                $update_q = "UPDATE quotations SET status = 'Finalized' WHERE id = ?";
                $stmt2 = mysqli_prepare($conn, $update_q);
                mysqli_stmt_bind_param($stmt2, "i", $quotation_id);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
                $success = "Quotation finalized. Request moved to QS Finalized status.";
                $request['status'] = 'QS Finalized';
                $request['final_quotation_id'] = $quotation_id;
            } else {
                $error = "Failed to finalize.";
            }
            mysqli_stmt_close($stmt);
        }
        
    }
}

// Fetch quotations for this request
$quotations = [];
$queries = "SELECT q.*, d.dealer_name FROM quotations q LEFT JOIN quotation_dealers d ON q.dealer_id = d.id WHERE q.quotation_request_id = ? ORDER BY q.total_amount ASC";
$stmt = mysqli_prepare($conn, $queries);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$quotations = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Fetch active dealers for dropdown
$dealers = [];
$dealer_query = "SELECT id, dealer_name FROM quotation_dealers WHERE status = 'Active' ORDER BY dealer_name";
$dealer_res = mysqli_query($conn, $dealer_query);
if ($dealer_res) {
    while ($row = mysqli_fetch_assoc($dealer_res)) {
        $dealers[] = $row;
    }
}

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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Manage Quotation - <?php echo e($request['request_no']); ?> - TEK-C</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />
    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }
        .info-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(250px,1fr)); gap:12px; margin-top:10px; }
        .info-item{ padding:10px 12px; background:#f9fafb; border-radius:10px; border:1px solid var(--border); }
        .info-label{ font-size:11px; font-weight:800; color:#6b7280; text-transform:uppercase; margin-bottom:2px; }
        .info-value{ font-size:14px; font-weight:900; color:#1f2937; }
        .description-box{ background:#f9fafb; border-radius:12px; padding:16px; border:1px solid var(--border); line-height:1.5; }
        .btn-action{ background:transparent; border:1px solid var(--border); border-radius:10px; padding:7px 10px; color:var(--muted); font-size:12px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-weight:900; }
        .btn-action:hover{ background:var(--bg); color:var(--blue); }
        .quotation-row{ border-left:3px solid transparent; transition:all 0.2s; }
        .quotation-row.selected{ background:rgba(16,185,129,.05); border-left-color:#10b981; }
        .badge-final{ background:#10b981; color:white; padding:4px 8px; border-radius:20px; font-size:10px; font-weight:800; }
        .items-table th, .items-table td{ padding:8px; font-size:13px; }
        .document-link{ display:inline-flex; align-items:center; gap:8px; text-decoration:none; color:var(--blue); }
        .table-responsive { overflow-x: hidden !important; }
        table.dataTable { width:100% !important; }
        .table thead th{ font-size: 11px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; padding: 10px 10px !important; white-space: normal !important; }
        .table td{ vertical-align: middle; border-color: var(--border); font-weight:650; color:#374151; padding: 10px 10px !important; white-space: normal !important; word-break: break-word; }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid maxw">

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold mb-1">Manage Quotation</h1>
                        <p class="text-muted">Request #<?php echo e($request['request_no']); ?> • <?php echo getStatusBadge($request['status']); ?></p>
                    </div>
                    <a href="qs-quotations.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
                </div>

                <!-- Request Summary Panel -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">Request Details</h3>
                        <button class="panel-menu"><i class="bi bi-three-dots"></i></button>
                    </div>
                    <div class="info-grid mb-3">
                        <div class="info-item"><div class="info-label">Project</div><div class="info-value"><?php echo e($request['project_name']); ?></div></div>
                        <div class="info-item"><div class="info-label">Client</div><div class="info-value"><?php echo e($request['client_name'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Quotation Type</div><div class="info-value"><?php echo e($request['quotation_type']); ?></div></div>
                        <div class="info-item"><div class="info-label">Required By</div><div class="info-value"><?php echo safeDate($request['required_by_date']); ?></div></div>
                        <div class="info-item"><div class="info-label">Manager</div><div class="info-value"><?php echo e($request['manager_name'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Team Lead</div><div class="info-value"><?php echo e($request['team_lead_name'] ?? '—'); ?></div></div>
                    </div>
                    <h4 class="fw-900 mb-2">Description</h4>
                    <div class="description-box mb-3"><?php echo nl2br(e($request['description'])); ?></div>
                    <?php if (!empty($request['specifications'])): ?>
                        <h4 class="fw-900 mb-2">Specifications</h4>
                        <div class="description-box"><?php echo nl2br(e($request['specifications'])); ?></div>
                    <?php endif; ?>
                </div>

                <!-- Quotations Section -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">Quotations Received</h3>
                        <?php if ($request['status'] === 'With QS'): ?>
                            <button type="button" class="btn-action" data-bs-toggle="modal" data-bs-target="#addQuotationModal"><i class="bi bi-plus-lg"></i> Add Quotation</button>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Dealer</th>
                                    <th>Total Amount</th>
                                    <th>Delivery Terms</th>
                                    <th>Payment Terms</th>
                                    <th>Warranty</th>
                                    <th>Document</th>
                                    <th>Items</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($quotations)): ?>
                                    <tr><td colspan="8" class="text-muted text-center">No quotations added yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($quotations as $q): 
                                        $isFinal = ($request['final_quotation_id'] == $q['id']);
                                        // fetch items
                                        $items = [];
                                        $item_sql = "SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id";
                                        $stmt_i = mysqli_prepare($conn, $item_sql);
                                        mysqli_stmt_bind_param($stmt_i, "i", $q['id']);
                                        mysqli_stmt_execute($stmt_i);
                                        $res_i = mysqli_stmt_get_result($stmt_i);
                                        $items = mysqli_fetch_all($res_i, MYSQLI_ASSOC);
                                        mysqli_stmt_close($stmt_i);
                                    ?>
                                        <tr class="quotation-row <?php echo $isFinal ? 'selected' : ''; ?>">
                                            <td><?php echo e($q['dealer_name'] ?? '—'); ?></td>
                                            <td class="fw-800"><?php echo formatCurrency($q['total_amount']); ?></td>
                                            <td><?php echo e($q['delivery_terms'] ?? '—'); ?></td>
                                            <td><?php echo e($q['payment_terms'] ?? '—'); ?></td>
                                            <td><?php echo e($q['warranty'] ?? '—'); ?></td>
                                            <td>
                                                <?php if (!empty($q['quotation_document'])): ?>
                                                    <a href="<?php echo e($q['quotation_document']); ?>" target="_blank" class="document-link"><i class="bi bi-file-pdf"></i> View</a>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn-action" data-bs-toggle="modal" data-bs-target="#itemsModal" data-quotation-id="<?php echo $q['id']; ?>" data-dealer="<?php echo e($q['dealer_name']); ?>" data-total="<?php echo $q['total_amount']; ?>">
                                                    <i class="bi bi-list-ul"></i> <?php echo count($items); ?> items
                                                </button>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($request['status'] === 'With QS'): ?>
                                                    <button class="btn-action" data-bs-toggle="modal" data-bs-target="#editQuotationModal" 
                                                        data-id="<?php echo $q['id']; ?>" 
                                                        data-dealer-id="<?php echo $q['dealer_id']; ?>" 
                                                        data-amount="<?php echo $q['total_amount']; ?>" 
                                                        data-delivery="<?php echo e($q['delivery_terms']); ?>" 
                                                        data-payment="<?php echo e($q['payment_terms']); ?>" 
                                                        data-warranty="<?php echo e($q['warranty']); ?>" 
                                                        data-remarks="<?php echo e($q['remarks']); ?>"
                                                        data-document="<?php echo e($q['quotation_document']); ?>">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </button>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this quotation?');">
                                                        <input type="hidden" name="action" value="delete_quotation">
                                                        <input type="hidden" name="quotation_id" value="<?php echo $q['id']; ?>">
                                                        <button type="submit" class="btn-action text-danger"><i class="bi bi-trash"></i> Delete</button>
                                                    </form>
                                                    <?php if (!$isFinal): ?>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Finalize this quotation? This will move the request to QS Finalized.');">
                                                            <input type="hidden" name="action" value="finalize">
                                                            <input type="hidden" name="quotation_id" value="<?php echo $q['id']; ?>">
                                                            <button type="submit" class="btn-action text-success"><i class="bi bi-check-lg"></i> Finalize</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($isFinal): ?>
                                                    <span class="badge-final"><i class="bi bi-star-fill"></i> Finalized</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($request['status'] === 'QS Finalized'): ?>
                    <div class="alert alert-info"><i class="bi bi-info-circle"></i> This request has been finalized. To make changes, you can reopen it from the dashboard.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Add Quotation Modal -->
<div class="modal fade" id="addQuotationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Add Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_quotation">
                    <div class="mb-3">
                        <label class="form-label">Dealer *</label>
                        <select name="dealer_id" class="form-select" required>
                            <option value="">-- Select Dealer --</option>
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo e($d['dealer_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Total Amount (₹) *</label><input type="number" step="0.01" name="total_amount" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Delivery Terms</label><input type="text" name="delivery_terms" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Warranty</label><input type="text" name="warranty" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3">
                        <label class="form-label">Quotation Document (PDF/Image/Excel)</label>
                        <input type="file" name="quotation_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.doc,.docx">
                        <small class="text-muted">Max 5MB. Allowed: PDF, JPG, PNG, Excel, Word.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Quotation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Quotation Modal -->
<div class="modal fade" id="editQuotationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_quotation">
                    <input type="hidden" name="quotation_id" id="edit_quotation_id">
                    <div class="mb-3">
                        <label class="form-label">Dealer *</label>
                        <select name="dealer_id" id="edit_dealer_id" class="form-select" required>
                            <option value="">-- Select Dealer --</option>
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo e($d['dealer_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Total Amount (₹) *</label><input type="number" step="0.01" name="total_amount" id="edit_total_amount" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Delivery Terms</label><input type="text" name="delivery_terms" id="edit_delivery_terms" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" id="edit_payment_terms" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Warranty</label><input type="text" name="warranty" id="edit_warranty" class="form-control"></div>
                    <div class="mb-3"><label class="form-label">Remarks</label><textarea name="remarks" id="edit_remarks" class="form-control" rows="2"></textarea></div>
                    <div class="mb-3">
                        <label class="form-label">Quotation Document (PDF/Image/Excel)</label>
                        <input type="file" name="quotation_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.doc,.docx">
                        <small class="text-muted">Leave blank to keep existing. Max 5MB.</small>
                        <div id="current_document" class="mt-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Quotation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Items Modal -->
<div class="modal fade" id="itemsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Itemized Breakdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="itemsModalBody">
                <!-- dynamic content -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>
<script>
    // Populate edit modal with data
    document.querySelectorAll('[data-bs-target="#editQuotationModal"]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_quotation_id').value = this.dataset.id;
            document.getElementById('edit_dealer_id').value = this.dataset.dealerId;
            document.getElementById('edit_total_amount').value = this.dataset.amount;
            document.getElementById('edit_delivery_terms').value = this.dataset.delivery;
            document.getElementById('edit_payment_terms').value = this.dataset.payment;
            document.getElementById('edit_warranty').value = this.dataset.warranty;
            document.getElementById('edit_remarks').value = this.dataset.remarks;
            if (this.dataset.document) {
                document.getElementById('current_document').innerHTML = `<a href="${this.dataset.document}" target="_blank">Current Document</a>`;
            } else {
                document.getElementById('current_document').innerHTML = '';
            }
        });
    });

    // Load items for a quotation
    document.querySelectorAll('[data-bs-target="#itemsModal"]').forEach(btn => {
        btn.addEventListener('click', function() {
            const quotationId = this.dataset.quotationId;
            const dealerName = this.dataset.dealer;
            const total = this.dataset.total;
            fetch(`ajax-get-items.php?quotation_id=${quotationId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('itemsModalBody').innerHTML = `
                        <h6>Dealer: ${dealerName}</h6>
                        <p>Total Amount: ₹ ${parseFloat(total).toLocaleString('en-IN', {minimumFractionDigits:2})}</p>
                        <hr>
                        <div class="table-responsive">
                            <table class="table table-sm items-table">
                                <thead>
                                    <tr><th>Item</th><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Total</th><th>Actions</th></tr>
                                </thead>
                                <tbody>${html}</tbody>
                            </table>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn-action" onclick="showAddItemForm(${quotationId})"><i class="bi bi-plus-lg"></i> Add Item</button>
                        </div>
                    `;
                });
        });
    });

    function showAddItemForm(quotationId) {
        const formHtml = `
            <form method="POST" class="mt-3 border-top pt-3" id="addItemForm">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="quotation_id" value="${quotationId}">
                <div class="row g-2">
                    <div class="col-12"><label>Item Name *</label><input type="text" name="item_name" class="form-control form-control-sm" required></div>
                    <div class="col-6"><label>Quantity *</label><input type="number" step="0.01" name="quantity" class="form-control form-control-sm" required></div>
                    <div class="col-6"><label>Unit *</label><input type="text" name="unit" class="form-control form-control-sm" required></div>
                    <div class="col-6"><label>Unit Price (₹) *</label><input type="number" step="0.01" name="unit_price" class="form-control form-control-sm" required></div>
                    <div class="col-12"><label>Description</label><textarea name="description" class="form-control form-control-sm" rows="2"></textarea></div>
                    <div class="col-12"><button type="submit" class="btn-action">Save Item</button></div>
                </div>
            </form>
        `;
        document.getElementById('itemsModalBody').insertAdjacentHTML('beforeend', formHtml);
    }
</script>
</body>
</html>
<?php if (isset($conn)) { mysqli_close($conn); } ?>