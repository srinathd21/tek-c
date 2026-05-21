<?php
// vfs_packages.php - Manage VFS Packages with Category Dropdown

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

$employeeId  = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$employeeName = $_SESSION['employee_name'] ?? '';

$allowed = ['manager', 'hr', 'director', 'project engineer grade 1', 'project engineer grade 2'];
if (!in_array($designation, $allowed, true)) {
    header("Location: index.php");
    exit;
}

// ---------------- HELPERS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------------- Predefined Categories ----------------
$predefinedCategories = [
    'Construction',
    'MEP',
    'Electrical',
    'Finishing',
    'Interior',
    'Safety',
    'Furniture',
    'External',
    'Structure',
    'Other'
];

// ---------------- Handle CRUD Operations ----------------
$message = '';
$error = '';

// INSERT Package
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $package_name = trim($_POST['package_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($package_name)) {
        $error = "Package name is required";
    } else {
        // Check if package already exists
        $checkSql = "SELECT id FROM vfs_packages WHERE package_name = ?";
        $checkStmt = mysqli_prepare($conn, $checkSql);
        mysqli_stmt_bind_param($checkStmt, "s", $package_name);
        mysqli_stmt_execute($checkStmt);
        mysqli_stmt_store_result($checkStmt);
        
        if (mysqli_stmt_num_rows($checkStmt) > 0) {
            $error = "Package already exists!";
        } else {
            $sql = "INSERT INTO vfs_packages (package_name, category, is_active) VALUES (?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssi", $package_name, $category, $is_active);
            
            if (mysqli_stmt_execute($stmt)) {
                $message = "Package added successfully!";
            } else {
                $error = "Failed to add package: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
        mysqli_stmt_close($checkStmt);
    }
}

// UPDATE Package
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $package_id = (int)($_POST['package_id'] ?? 0);
    $package_name = trim($_POST['package_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($package_name)) {
        $error = "Package name is required";
    } elseif ($package_id > 0) {
        $sql = "UPDATE vfs_packages SET package_name = ?, category = ?, is_active = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssii", $package_name, $category, $is_active, $package_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = "Package updated successfully!";
        } else {
            $error = "Failed to update package: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
}

// DELETE Package (Soft delete by setting is_active = 0)
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $package_id = (int)$_GET['delete'];
    $sql = "UPDATE vfs_packages SET is_active = 0 WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $package_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $message = "Package deactivated successfully!";
    } else {
        $error = "Failed to deactivate package: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
}

// Restore Package
if (isset($_GET['restore']) && is_numeric($_GET['restore'])) {
    $package_id = (int)$_GET['restore'];
    $sql = "UPDATE vfs_packages SET is_active = 1 WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $package_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $message = "Package restored successfully!";
    } else {
        $error = "Failed to restore package: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
}

// Permanent Delete
if (isset($_GET['permanent_delete']) && is_numeric($_GET['permanent_delete'])) {
    $package_id = (int)$_GET['permanent_delete'];
    $sql = "DELETE FROM vfs_packages WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $package_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $message = "Package permanently deleted!";
    } else {
        $error = "Failed to permanently delete package: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
}

// Get Package for Edit
$editPackage = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $sql = "SELECT * FROM vfs_packages WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editPackage = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Get All Packages (ordered by package_name)
$packages = [];
$sql = "SELECT * FROM vfs_packages ORDER BY package_name";
$result = mysqli_query($conn, $sql);
if ($result) {
    $packages = mysqli_fetch_all($result, MYSQLI_ASSOC);
}

// Get distinct categories from existing packages for filter
$existingCategories = [];
$catSql = "SELECT DISTINCT category FROM vfs_packages WHERE category IS NOT NULL AND category != '' ORDER BY category";
$catResult = mysqli_query($conn, $catSql);
if ($catResult) {
    $existingCategories = mysqli_fetch_all($catResult, MYSQLI_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>VFS Packages Management | TEK-C</title>

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
            padding:20px;
            margin-bottom:20px;
        }
        .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .h-title{ margin:0; font-weight:1000; color:#111827; }
        .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

        .form-label{ font-weight:900; color:#374151; font-size:13px; margin-bottom:6px; }
        .form-control, .form-select{
            border:2px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 12px;
            font-weight: 750;
            font-size: 14px;
        }
        
        .btn-primary-tek{
            background: var(--blue);
            border:none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 1000;
            display:inline-flex;
            align-items:center;
            gap:8px;
            color:#fff;
        }
        .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }
        
        .badge-active{
            background: #dcfce7;
            color: #16a34a;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 900;
        }
        .badge-inactive{
            background: #fee2e2;
            color: #dc2626;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 900;
        }
        
        .table-packages th{
            background: #f9fafb;
            font-weight: 900;
            font-size: 12px;
            color: #6b7280;
        }
        
        .action-icons i{
            font-size: 18px;
            margin: 0 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .action-icons i:hover{ opacity: 0.7; }
        .text-edit{ color: var(--blue); }
        .text-delete{ color: #dc2626; }
        .text-restore{ color: #16a34a; }
        
        .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }
        
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

                <div class="title-row mb-3">
                    <div>
                        <h1 class="h-title">VFS Packages Management</h1>
                        <p class="h-sub">Manage vendor finalization sheet packages</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
                        <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($_SESSION['designation'] ?? ''); ?></span>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Add/Edit Package Form -->
                <div class="panel">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-plus-circle" style="font-size: 24px; color: var(--blue);"></i>
                        <h5 class="mb-0" style="font-weight: 1000;">
                            <?php echo $editPackage ? 'Edit Package' : 'Add New Package'; ?>
                        </h5>
                    </div>
                    
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="<?php echo $editPackage ? 'edit' : 'add'; ?>">
                        <?php if ($editPackage): ?>
                            <input type="hidden" name="package_id" value="<?php echo $editPackage['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="col-md-6">
                            <label class="form-label">Package Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="package_name" 
                                   value="<?php echo $editPackage ? e($editPackage['package_name']) : ''; ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($predefinedCategories as $cat): ?>
                                    <option value="<?php echo e($cat); ?>" 
                                        <?php echo ($editPackage && $editPackage['category'] == $cat) ? 'selected' : ''; ?>>
                                        <?php echo e($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small-muted mt-1">Select a category to organize packages</div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                       <?php echo (!$editPackage || $editPackage['is_active']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active" style="font-weight: 900;">
                                    Active
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-8">
                            <button type="submit" class="btn-primary-tek">
                                <i class="bi bi-save"></i> 
                                <?php echo $editPackage ? 'Update Package' : 'Add Package'; ?>
                            </button>
                            
                            <?php if ($editPackage): ?>
                                <a href="vfs_packages.php" class="btn btn-secondary ms-2" style="border-radius:12px;">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Packages List -->
                <div class="panel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-grid-3x3-gap-fill" style="font-size: 24px; color: var(--blue);"></i>
                            <h5 class="mb-0" style="font-weight: 1000;">Packages List</h5>
                            <span class="badge bg-secondary ms-2"><?php echo count($packages); ?> Total</span>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-packages align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Package Name</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($packages)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No packages found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($packages as $pkg): ?>
                                        <tr>
                                            <td><?php echo $pkg['id']; ?></td>
                                            <td><strong><?php echo e($pkg['package_name']); ?></strong></td>
                                            <td><?php echo e($pkg['category'] ?: '-'); ?></td>
                                            <td>
                                                <?php if ($pkg['is_active']): ?>
                                                    <span class="badge-active">Active</span>
                                                <?php else: ?>
                                                    <span class="badge-inactive">Inactive</span>
                                                <?php endif; ?>
                                             </td>
                                            <td><?php echo date('d-m-Y', strtotime($pkg['created_at'])); ?></td>
                                            <td class="action-icons">
                                                <a href="?edit=<?php echo $pkg['id']; ?>" class="text-edit" title="Edit">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                                <?php if ($pkg['is_active']): ?>
                                                    <a href="?delete=<?php echo $pkg['id']; ?>" 
                                                       class="text-delete" 
                                                       onclick="return confirm('Are you sure you want to deactivate this package?')"
                                                       title="Deactivate">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?restore=<?php echo $pkg['id']; ?>" 
                                                       class="text-restore" 
                                                       onclick="return confirm('Are you sure you want to restore this package?')"
                                                       title="Restore">
                                                        <i class="bi bi-arrow-repeat"></i>
                                                    </a>
                                                    <a href="?permanent_delete=<?php echo $pkg['id']; ?>" 
                                                       class="text-delete" 
                                                       onclick="return confirm('WARNING: This will permanently delete the package. Are you sure?')"
                                                       title="Permanent Delete">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </a>
                                                <?php endif; ?>
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

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>

</body>
</html>