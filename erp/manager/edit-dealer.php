<?php
// edit-dealer.php
session_start();
require_once 'includes/db-config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$user_id = (int)$_SESSION['employee_id'];
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Allow managers and directors
$allowed = ['manager', 'director', 'vice president', 'general manager'];
if (!in_array($user_designation, $allowed, true)) {
    header('Location: index.php');
    exit();
}

// Get dealer ID from URL
$dealer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($dealer_id === 0) {
    header('Location: dealers.php?status=error&message=' . urlencode('Invalid dealer ID'));
    exit();
}

// Fetch dealer details
$query = "SELECT * FROM quotation_dealers WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $dealer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$dealer = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$dealer) {
    header('Location: dealers.php?status=error&message=' . urlencode('Dealer not found'));
    exit();
}

// Parse dealer types
$dealer_types = [];
if (!empty($dealer['dealer_type'])) {
    $dealer_types = explode(',', $dealer['dealer_type']);
}

// Helper function
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Edit Dealer - TEK-C</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        .form-label{ font-weight:800; color:#4b5563; font-size:12px; margin-bottom:4px; }
        .form-control, .form-select{ border:1px solid var(--border); border-radius:10px; padding:8px 12px; font-weight:600; }
        .required:after{ content:" *"; color: #ef4444; font-weight:900; }
        .btn{ padding:8px 16px; border-radius:10px; font-weight:800; font-size:13px; }
    </style>
</head>
<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main" aria-label="Main">
            <?php include 'includes/topbar.php'; ?>

            <div id="contentScroll" class="content-scroll">
                <div class="container-fluid maxw">
                    
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-dark mb-1">Edit Dealer</h1>
                            <p class="text-muted mb-0">Update dealer information</p>
                        </div>
                        <div>
                            <a href="dealers.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Dealers
                            </a>
                        </div>
                    </div>

                    <!-- Edit Form Panel -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Dealer Information</h3>
                            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                        </div>

                        <form action="process-dealer.php" method="POST">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="dealer_id" value="<?php echo $dealer_id; ?>">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label required">Dealer Code</label>
                                    <input type="text" class="form-control" value="<?php echo e($dealer['dealer_code']); ?>" readonly disabled>
                                    <small class="text-muted">Dealer code cannot be changed</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required">Dealer Name</label>
                                    <input type="text" class="form-control" name="dealer_name" value="<?php echo e($dealer['dealer_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" name="contact_person" value="<?php echo e($dealer['contact_person']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required">Mobile Number</label>
                                    <input type="text" class="form-control" name="mobile_number" value="<?php echo e($dealer['mobile_number']); ?>" required maxlength="10">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Alternate Phone</label>
                                    <input type="text" class="form-control" name="alternate_phone" value="<?php echo e($dealer['alternate_phone']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="email" value="<?php echo e($dealer['email']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">GST Number</label>
                                    <input type="text" class="form-control" name="gst_number" value="<?php echo e($dealer['gst_number']); ?>" maxlength="15">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">PAN Number</label>
                                    <input type="text" class="form-control" name="pan_number" value="<?php echo e($dealer['pan_number']); ?>" maxlength="10">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" name="address" rows="2"><?php echo e($dealer['address']); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">City</label>
                                    <input type="text" class="form-control" name="city" value="<?php echo e($dealer['city']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">State</label>
                                    <input type="text" class="form-control" name="state" value="<?php echo e($dealer['state']); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Pincode</label>
                                    <input type="text" class="form-control" name="pincode" value="<?php echo e($dealer['pincode']); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Dealer Type</label>
                                    <select class="form-select select2-multiple" name="dealer_type[]" multiple>
                                        <option value="Electrical" <?php echo in_array('Electrical', $dealer_types) ? 'selected' : ''; ?>>Electrical</option>
                                        <option value="Plumbing" <?php echo in_array('Plumbing', $dealer_types) ? 'selected' : ''; ?>>Plumbing</option>
                                        <option value="Civil" <?php echo in_array('Civil', $dealer_types) ? 'selected' : ''; ?>>Civil</option>
                                        <option value="Painting" <?php echo in_array('Painting', $dealer_types) ? 'selected' : ''; ?>>Painting</option>
                                        <option value="Flooring" <?php echo in_array('Flooring', $dealer_types) ? 'selected' : ''; ?>>Flooring</option>
                                        <option value="Roofing" <?php echo in_array('Roofing', $dealer_types) ? 'selected' : ''; ?>>Roofing</option>
                                        <option value="Steel" <?php echo in_array('Steel', $dealer_types) ? 'selected' : ''; ?>>Steel</option>
                                        <option value="Cement" <?php echo in_array('Cement', $dealer_types) ? 'selected' : ''; ?>>Cement</option>
                                        <option value="Woodwork" <?php echo in_array('Woodwork', $dealer_types) ? 'selected' : ''; ?>>Woodwork</option>
                                        <option value="Glass" <?php echo in_array('Glass', $dealer_types) ? 'selected' : ''; ?>>Glass</option>
                                        <option value="Hardware" <?php echo in_array('Hardware', $dealer_types) ? 'selected' : ''; ?>>Hardware</option>
                                        <option value="Sanitary" <?php echo in_array('Sanitary', $dealer_types) ? 'selected' : ''; ?>>Sanitary</option>
                                        <option value="Tile" <?php echo in_array('Tile', $dealer_types) ? 'selected' : ''; ?>>Tile</option>
                                        <option value="Paint" <?php echo in_array('Paint', $dealer_types) ? 'selected' : ''; ?>>Paint</option>
                                        <option value="Other" <?php echo in_array('Other', $dealer_types) ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Payment Terms</label>
                                    <input type="text" class="form-control" name="payment_terms" value="<?php echo e($dealer['payment_terms']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Credit Limit (₹)</label>
                                    <input type="number" class="form-control" name="credit_limit" value="<?php echo e($dealer['credit_limit']); ?>" step="0.01">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="Active" <?php echo $dealer['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $dealer['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="Blacklisted" <?php echo $dealer['status'] === 'Blacklisted' ? 'selected' : ''; ?>>Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Remarks</label>
                                    <textarea class="form-control" name="remarks" rows="2"><?php echo e($dealer['remarks']); ?></textarea>
                                </div>
                            </div>

                            <div class="d-flex gap-2 justify-content-end mt-4">
                                <a href="dealers.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Update Dealer</button>
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
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
        $(document).ready(function() {
            $('.select2-multiple').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Select dealer types'
            });
        });
    </script>
</body>
</html>