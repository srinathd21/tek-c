<?php
// dealers-directory.php – Manage dealers/vendors for quotations

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';

// Auth: allow QS, Team Lead, Manager, Admin
if (empty($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}
$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$department = strtolower(trim((string)($_SESSION['department'] ?? '')));

// Allow if QS, Team Lead, Manager, Admin, Project Engineer, etc.
$allowed = in_array($designation, ['team lead', 'project engineer grade 1', 'project engineer grade 2', 'sr. engineer', 'manager', 'qs', 'director', 'vice president']);
if (!$allowed && $department !== 'qs') {
    header("Location: index.php");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    // Add dealer
    if ($action === 'add_dealer') {
        $dealer_name = trim($_POST['dealer_name']);
        $contact_person = trim($_POST['contact_person']);
        $mobile_number = trim($_POST['mobile_number']);
        $alternate_phone = trim($_POST['alternate_phone']);
        $email = trim($_POST['email']);
        $gst_number = trim($_POST['gst_number']);
        $pan_number = trim($_POST['pan_number']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $state = trim($_POST['state']);
        $pincode = trim($_POST['pincode']);
        $dealer_type = trim($_POST['dealer_type']); // text input
        $payment_terms = trim($_POST['payment_terms']);
        $credit_limit = floatval($_POST['credit_limit']);
        $remarks = trim($_POST['remarks']);
        $status = $_POST['status'];

        if (empty($dealer_name) || empty($mobile_number)) {
            $error = "Dealer name and mobile number are required.";
        } else {
            // Generate dealer code
            $prefix = 'DL';
            $year = date('Y');
            $last_code_query = "SELECT dealer_code FROM quotation_dealers WHERE dealer_code LIKE '{$prefix}-{$year}%' ORDER BY dealer_code DESC LIMIT 1";
            $last_res = mysqli_query($conn, $last_code_query);
            $next_num = 1;
            if ($last_res && mysqli_num_rows($last_res) > 0) {
                $last = mysqli_fetch_assoc($last_res);
                $last_num = intval(substr($last['dealer_code'], -4));
                $next_num = $last_num + 1;
            }
            $dealer_code = $prefix . '-' . $year . '-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

            $insert = "INSERT INTO quotation_dealers (dealer_code, dealer_name, contact_person, mobile_number, alternate_phone, email, gst_number, pan_number, address, city, state, pincode, dealer_type, payment_terms, credit_limit, status, remarks, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $insert);
            mysqli_stmt_bind_param($stmt, "sssssssssssssssssis", $dealer_code, $dealer_name, $contact_person, $mobile_number, $alternate_phone, $email, $gst_number, $pan_number, $address, $city, $state, $pincode, $dealer_type, $payment_terms, $credit_limit, $status, $remarks, $empId, $_SESSION['employee_name']);
            if (mysqli_stmt_execute($stmt)) {
                $success = "Dealer added successfully. Code: $dealer_code";
            } else {
                $error = "Failed to add dealer: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Edit dealer
    if ($action === 'edit_dealer') {
        $id = intval($_POST['id']);
        $dealer_name = trim($_POST['dealer_name']);
        $contact_person = trim($_POST['contact_person']);
        $mobile_number = trim($_POST['mobile_number']);
        $alternate_phone = trim($_POST['alternate_phone']);
        $email = trim($_POST['email']);
        $gst_number = trim($_POST['gst_number']);
        $pan_number = trim($_POST['pan_number']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $state = trim($_POST['state']);
        $pincode = trim($_POST['pincode']);
        $dealer_type = trim($_POST['dealer_type']); // text input
        $payment_terms = trim($_POST['payment_terms']);
        $credit_limit = floatval($_POST['credit_limit']);
        $remarks = trim($_POST['remarks']);
        $status = $_POST['status'];

        if (empty($dealer_name) || empty($mobile_number)) {
            $error = "Dealer name and mobile number are required.";
        } else {
            $update = "UPDATE quotation_dealers SET dealer_name=?, contact_person=?, mobile_number=?, alternate_phone=?, email=?, gst_number=?, pan_number=?, address=?, city=?, state=?, pincode=?, dealer_type=?, payment_terms=?, credit_limit=?, status=?, remarks=?, updated_at=NOW() WHERE id=?";
            $stmt = mysqli_prepare($conn, $update);
            mysqli_stmt_bind_param($stmt, "sssssssssssssdssi", $dealer_name, $contact_person, $mobile_number, $alternate_phone, $email, $gst_number, $pan_number, $address, $city, $state, $pincode, $dealer_type, $payment_terms, $credit_limit, $status, $remarks, $id);
            if (mysqli_stmt_execute($stmt)) {
                $success = "Dealer updated successfully.";
            } else {
                $error = "Failed to update dealer: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Delete dealer
    if ($action === 'delete_dealer') {
        $id = intval($_POST['id']);
        // Check if dealer has any quotations associated
        $check = "SELECT id FROM quotations WHERE dealer_id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $check);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($res) > 0) {
            $error = "Cannot delete dealer with existing quotations. You may mark as inactive instead.";
        } else {
            $delete = "DELETE FROM quotation_dealers WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete);
            mysqli_stmt_bind_param($stmt, "i", $id);
            if (mysqli_stmt_execute($stmt)) {
                $success = "Dealer deleted successfully.";
            } else {
                $error = "Failed to delete dealer.";
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Fetch all dealers
$dealers = [];
$sql = "SELECT * FROM quotation_dealers ORDER BY dealer_name";
$res = mysqli_query($conn, $sql);
if ($res) {
    $dealers = mysqli_fetch_all($res, MYSQLI_ASSOC);
}

// Helper functions
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function formatDate($v){ return !empty($v) ? date('d M Y', strtotime($v)) : '—'; }

// Dealer types (for stats only)
$dealer_types = ['Electrical', 'Plumbing', 'Civil', 'Painting', 'Flooring', 'Roofing', 'Steel', 'Cement', 'Woodwork', 'Glass', 'Hardware', 'Sanitary', 'Tile', 'Paint', 'Other'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Dealers Directory - TEK-C</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        /* same styles as before */
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

        .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
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
        .table thead th{ font-size: 11px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; padding: 10px 10px !important; white-space: normal !important; }
        .table td{ vertical-align: middle; border-color: var(--border); font-weight:650; color:#374151; padding: 10px 10px !important; white-space: normal !important; word-break: break-word; }

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
        .btn-action.view{ border-color: rgba(45,156,219,.25); }

        .proj-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
        .proj-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.25; }

        .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

        @media (max-width: 991.98px){
            .main{ margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
            .sidebar{ position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
            .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
        }
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
            .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
            .request-actions { flex-wrap: wrap; }
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
                        <h1 class="h3 fw-bold text-dark mb-1">Dealers Directory</h1>
                        <p class="text-muted mb-0">Manage vendors and suppliers for quotations</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDealerModal">
                            <i class="bi bi-plus-lg"></i> Add Dealer
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <?php
                $active_count = 0;
                $inactive_count = 0;
                foreach ($dealers as $d) {
                    if ($d['status'] === 'Active') $active_count++;
                    else $inactive_count++;
                }
                ?>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-shop"></i></div>
                            <div>
                                <div class="stat-label">Total Dealers</div>
                                <div class="stat-value"><?php echo count($dealers); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="stat-label">Active</div>
                                <div class="stat-value"><?php echo $active_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                            <div>
                                <div class="stat-label">Inactive</div>
                                <div class="stat-value"><?php echo $inactive_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-star"></i></div>
                            <div>
                                <div class="stat-label">Dealer Types</div>
                                <div class="stat-value"><?php echo count($dealer_types); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Directory -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">All Dealers</h3>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>
                    <div class="table-responsive">
                        <table id="dealersTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                            <thead>
                                32
                                    <th>Code</th>
                                    <th>Dealer Name</th>
                                    <th>Contact Person</th>
                                    <th>Mobile</th>
                                    <th>Email</th>
                                    <th>City</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </thead>
                            <tbody>
                                <?php foreach ($dealers as $d): ?>
                                <tr>
                                    <td><span class="fw-800"><?php echo e($d['dealer_code']); ?></span></td>
                                    <td>
                                        <div class="fw-700"><?php echo e($d['dealer_name']); ?></div>
                                        <?php if (!empty($d['dealer_type'])): ?>
                                            <div class="proj-sub"><?php echo e($d['dealer_type']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo e($d['contact_person'] ?? '—'); ?></td>
                                    <td><?php echo e($d['mobile_number']); ?></td>
                                    <td><?php echo e($d['email'] ?? '—'); ?></td>
                                    <td><?php echo e($d['city'] ?? '—'); ?></td>
                                    <td><span class="badge <?php echo $d['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo e($d['status']); ?></span></td>
                                    <td class="text-end">
                                        <button class="btn-action view" data-bs-toggle="modal" data-bs-target="#viewDealerModal" 
                                            data-id="<?php echo $d['id']; ?>"
                                            data-code="<?php echo e($d['dealer_code']); ?>"
                                            data-name="<?php echo e($d['dealer_name']); ?>"
                                            data-contact="<?php echo e($d['contact_person']); ?>"
                                            data-mobile="<?php echo e($d['mobile_number']); ?>"
                                            data-alt="<?php echo e($d['alternate_phone']); ?>"
                                            data-email="<?php echo e($d['email']); ?>"
                                            data-gst="<?php echo e($d['gst_number']); ?>"
                                            data-pan="<?php echo e($d['pan_number']); ?>"
                                            data-address="<?php echo e($d['address']); ?>"
                                            data-city="<?php echo e($d['city']); ?>"
                                            data-state="<?php echo e($d['state']); ?>"
                                            data-pincode="<?php echo e($d['pincode']); ?>"
                                            data-types="<?php echo e($d['dealer_type']); ?>"
                                            data-payment="<?php echo e($d['payment_terms']); ?>"
                                            data-credit="<?php echo $d['credit_limit']; ?>"
                                            data-status="<?php echo e($d['status']); ?>"
                                            data-remarks="<?php echo e($d['remarks']); ?>">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <button class="btn-action" data-bs-toggle="modal" data-bs-target="#editDealerModal" 
                                            data-id="<?php echo $d['id']; ?>"
                                            data-name="<?php echo e($d['dealer_name']); ?>"
                                            data-contact="<?php echo e($d['contact_person']); ?>"
                                            data-mobile="<?php echo e($d['mobile_number']); ?>"
                                            data-alt="<?php echo e($d['alternate_phone']); ?>"
                                            data-email="<?php echo e($d['email']); ?>"
                                            data-gst="<?php echo e($d['gst_number']); ?>"
                                            data-pan="<?php echo e($d['pan_number']); ?>"
                                            data-address="<?php echo e($d['address']); ?>"
                                            data-city="<?php echo e($d['city']); ?>"
                                            data-state="<?php echo e($d['state']); ?>"
                                            data-pincode="<?php echo e($d['pincode']); ?>"
                                            data-types="<?php echo e($d['dealer_type']); ?>"
                                            data-payment="<?php echo e($d['payment_terms']); ?>"
                                            data-credit="<?php echo $d['credit_limit']; ?>"
                                            data-status="<?php echo e($d['status']); ?>"
                                            data-remarks="<?php echo e($d['remarks']); ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this dealer? This action cannot be undone if no quotations exist.');">
                                            <input type="hidden" name="action" value="delete_dealer">
                                            <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                            <button type="submit" class="btn-action text-danger"><i class="bi bi-trash"></i> Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Add Dealer Modal -->
<div class="modal fade" id="addDealerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Dealer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_dealer">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Dealer Name *</label><input type="text" name="dealer_name" class="form-control" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Mobile Number *</label><input type="tel" name="mobile_number" class="form-control" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Alternate Phone</label><input type="tel" name="alternate_phone" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">GST Number</label><input type="text" name="gst_number" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">PAN Number</label><input type="text" name="pan_number" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">City</label><input type="text" name="city" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">State</label><input type="text" name="state" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Pincode</label><input type="text" name="pincode" class="form-control"></div>
                        <div class="col-12 mb-3"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Dealer Type</label>
                            <input type="text" name="dealer_type" class="form-control" placeholder="e.g., Electrical, Plumbing, Steel (comma separated)">
                            <small class="text-muted">Enter multiple types separated by commas if needed.</small>
                        </div>
                        <div class="col-md-6 mb-3"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Credit Limit (₹)</label><input type="number" step="0.01" name="credit_limit" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                        <div class="col-12 mb-3"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Dealer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Dealer Modal -->
<div class="modal fade" id="editDealerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Dealer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_dealer">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Dealer Name *</label><input type="text" name="dealer_name" id="edit_name" class="form-control" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Contact Person</label><input type="text" name="contact_person" id="edit_contact" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Mobile Number *</label><input type="tel" name="mobile_number" id="edit_mobile" class="form-control" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Alternate Phone</label><input type="tel" name="alternate_phone" id="edit_alt" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">GST Number</label><input type="text" name="gst_number" id="edit_gst" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">PAN Number</label><input type="text" name="pan_number" id="edit_pan" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">City</label><input type="text" name="city" id="edit_city" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">State</label><input type="text" name="state" id="edit_state" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Pincode</label><input type="text" name="pincode" id="edit_pincode" class="form-control"></div>
                        <div class="col-12 mb-3"><label class="form-label">Address</label><textarea name="address" id="edit_address" class="form-control" rows="2"></textarea></div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Dealer Type</label>
                            <input type="text" name="dealer_type" id="edit_dealer_type" class="form-control" placeholder="e.g., Electrical, Plumbing, Steel (comma separated)">
                            <small class="text-muted">Enter multiple types separated by commas if needed.</small>
                        </div>
                        <div class="col-md-6 mb-3"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" id="edit_payment" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Credit Limit (₹)</label><input type="number" step="0.01" name="credit_limit" id="edit_credit" class="form-control"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Status</label><select name="status" id="edit_status" class="form-select"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                        <div class="col-12 mb-3"><label class="form-label">Remarks</label><textarea name="remarks" id="edit_remarks" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Dealer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Dealer Modal -->
<div class="modal fade" id="viewDealerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dealer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row" id="viewDetails"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    function initTable() {
        const isDesktop = window.matchMedia('(min-width: 768px)').matches;
        const tbl = document.getElementById('dealersTable');
        if (!tbl) return;
        if (isDesktop) {
            if (!$.fn.DataTable.isDataTable('#dealersTable')) {
                $('#dealersTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                    order: [[1, 'asc']],
                    columnDefs: [{ targets: [7], orderable: false, searchable: false }],
                    language: { zeroRecords: "No dealers found", info: "Showing _START_ to _END_ of _TOTAL_ dealers" }
                });
            }
        } else {
            if ($.fn.DataTable.isDataTable('#dealersTable')) {
                $('#dealersTable').DataTable().destroy();
            }
        }
    }
    $(function () {
        initTable();
        window.addEventListener('resize', initTable);
    });

    // Populate edit modal
    document.querySelectorAll('[data-bs-target="#editDealerModal"]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_id').value = this.dataset.id;
            document.getElementById('edit_name').value = this.dataset.name;
            document.getElementById('edit_contact').value = this.dataset.contact || '';
            document.getElementById('edit_mobile').value = this.dataset.mobile;
            document.getElementById('edit_alt').value = this.dataset.alt || '';
            document.getElementById('edit_email').value = this.dataset.email || '';
            document.getElementById('edit_gst').value = this.dataset.gst || '';
            document.getElementById('edit_pan').value = this.dataset.pan || '';
            document.getElementById('edit_address').value = this.dataset.address || '';
            document.getElementById('edit_city').value = this.dataset.city || '';
            document.getElementById('edit_state').value = this.dataset.state || '';
            document.getElementById('edit_pincode').value = this.dataset.pincode || '';
            document.getElementById('edit_dealer_type').value = this.dataset.types || '';
            document.getElementById('edit_payment').value = this.dataset.payment || '';
            document.getElementById('edit_credit').value = this.dataset.credit || 0;
            document.getElementById('edit_status').value = this.dataset.status;
            document.getElementById('edit_remarks').value = this.dataset.remarks || '';
        });
    });

    // Populate view modal
    document.querySelectorAll('[data-bs-target="#viewDealerModal"]').forEach(btn => {
        btn.addEventListener('click', function() {
            let html = `
                <div class="col-md-6"><strong>Dealer Code:</strong><br>${this.dataset.code}</div>
                <div class="col-md-6"><strong>Status:</strong><br><span class="badge ${this.dataset.status === 'Active' ? 'bg-success' : 'bg-secondary'}">${this.dataset.status}</span></div>
                <div class="col-md-6 mt-2"><strong>Dealer Name:</strong><br>${this.dataset.name}</div>
                <div class="col-md-6 mt-2"><strong>Contact Person:</strong><br>${this.dataset.contact || '—'}</div>
                <div class="col-md-6 mt-2"><strong>Mobile:</strong><br>${this.dataset.mobile}</div>
                <div class="col-md-6 mt-2"><strong>Alternate Phone:</strong><br>${this.dataset.alt || '—'}</div>
                <div class="col-md-6 mt-2"><strong>Email:</strong><br>${this.dataset.email || '—'}</div>
                <div class="col-md-6 mt-2"><strong>GST Number:</strong><br>${this.dataset.gst || '—'}</div>
                <div class="col-md-6 mt-2"><strong>PAN Number:</strong><br>${this.dataset.pan || '—'}</div>
                <div class="col-12 mt-2"><strong>Address:</strong><br>${this.dataset.address || '—'}</div>
                <div class="col-md-4 mt-2"><strong>City:</strong><br>${this.dataset.city || '—'}</div>
                <div class="col-md-4 mt-2"><strong>State:</strong><br>${this.dataset.state || '—'}</div>
                <div class="col-md-4 mt-2"><strong>Pincode:</strong><br>${this.dataset.pincode || '—'}</div>
                <div class="col-12 mt-2"><strong>Dealer Type:</strong><br>${this.dataset.types || '—'}</div>
                <div class="col-md-6 mt-2"><strong>Payment Terms:</strong><br>${this.dataset.payment || '—'}</div>
                <div class="col-md-6 mt-2"><strong>Credit Limit:</strong><br>₹ ${parseFloat(this.dataset.credit).toLocaleString('en-IN', {minimumFractionDigits:2})}</div>
                <div class="col-12 mt-2"><strong>Remarks:</strong><br>${this.dataset.remarks || '—'}</div>
            `;
            document.getElementById('viewDetails').innerHTML = html;
        });
    });
</script>
</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>