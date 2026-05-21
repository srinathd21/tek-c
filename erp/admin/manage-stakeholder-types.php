<?php
// manage-stakeholder-types.php
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

$user_id = $_SESSION['employee_id'];
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Get status message from session or URL
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';

// ============================================================
// CRUD Operations
// ============================================================

// Handle DELETE
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $delete_query = "DELETE FROM pd_stakeholder_types WHERE id = ?";
    $stmt = mysqli_prepare($conn, $delete_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $delete_id);
        if (mysqli_stmt_execute($stmt)) {
            header("Location: manage-stakeholder-types.php?status=success&message=" . urlencode("Stakeholder type deleted successfully."));
            exit();
        } else {
            header("Location: manage-stakeholder-types.php?status=error&message=" . urlencode("Error deleting record."));
            exit();
        }
        mysqli_stmt_close($stmt);
    }
}

// Handle INSERT / UPDATE
$edit_record = null;
$is_edit = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $stakeholder_type = trim($_POST['stakeholder_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if (empty($stakeholder_type)) {
        $errors[] = "Stakeholder type is required.";
    }

    if (empty($errors)) {
        if ($id > 0) {
            // UPDATE
            $update_query = "UPDATE pd_stakeholder_types SET stakeholder_type = ?, description = ?, is_active = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ssii", $stakeholder_type, $description, $is_active, $id);
                if (mysqli_stmt_execute($stmt)) {
                    header("Location: manage-stakeholder-types.php?status=success&message=" . urlencode("Stakeholder type updated successfully."));
                    exit();
                } else {
                    $error = "Error updating record: " . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            // INSERT - check for duplicate
            $check_query = "SELECT id FROM pd_stakeholder_types WHERE stakeholder_type = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            if ($check_stmt) {
                mysqli_stmt_bind_param($check_stmt, "s", $stakeholder_type);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "Stakeholder type '" . htmlspecialchars($stakeholder_type) . "' already exists.";
                } else {
                    $insert_query = "INSERT INTO pd_stakeholder_types (stakeholder_type, description, is_active) VALUES (?, ?, ?)";
                    $insert_stmt = mysqli_prepare($conn, $insert_query);
                    if ($insert_stmt) {
                        mysqli_stmt_bind_param($insert_stmt, "ssi", $stakeholder_type, $description, $is_active);
                        if (mysqli_stmt_execute($insert_stmt)) {
                            header("Location: manage-stakeholder-types.php?status=success&message=" . urlencode("Stakeholder type added successfully."));
                            exit();
                        } else {
                            $error = "Error inserting record: " . mysqli_error($conn);
                        }
                        mysqli_stmt_close($insert_stmt);
                    }
                }
                mysqli_stmt_close($check_stmt);
            }
        }
    }
}

// Handle GET for EDIT (fetch record to populate form)
if (isset($_GET['edit_id']) && is_numeric($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $edit_query = "SELECT id, stakeholder_type, description, is_active FROM pd_stakeholder_types WHERE id = ?";
    $stmt = mysqli_prepare($conn, $edit_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $edit_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit_record = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        if ($edit_record) {
            $is_edit = true;
        }
    }
}

// Fetch all records for listing
$records_query = "SELECT id, stakeholder_type, description, is_active, created_at, updated_at FROM pd_stakeholder_types ORDER BY stakeholder_type ASC";
$records_result = mysqli_query($conn, $records_query);
$records = mysqli_fetch_all($records_result, MYSQLI_ASSOC);

// Helper functions
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function getStatusBadge($is_active) {
    if ($is_active == 1) {
        return '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>';
    } else {
        return '<span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Inactive</span>';
    }
}

function safeDate($v, $dash = '—') {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Manage Stakeholder Types - TEK-C Dashboard</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
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
        .btn-danger{ background: #ef4444; color:#fff; border-color: #ef4444; }
        .btn-danger:hover{ background: #dc2626; border-color: #dc2626; }

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

        .alert { border-radius: 12px; padding: 15px 20px; margin-bottom: 20px; border: none; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: var(--blue);
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }

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
            .action-buttons { display: flex; gap: 4px; justify-content: flex-start; }
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

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h1 class="h3 fw-900 text-dark mb-1">Manage Stakeholder Types</h1>
                        <p class="text-muted fw-650 mb-0" style="font-size:14px;">Create, edit, and manage stakeholder type configurations</p>
                    </div>
                </div>

                <!-- Add/Edit Form Panel -->
                <div class="panel mb-4">
                    <div class="form-section-title">
                        <i class="bi bi-<?php echo $is_edit ? 'pencil-square' : 'plus-circle'; ?>"></i>
                        <?php echo $is_edit ? 'Edit Stakeholder Type' : 'Add New Stakeholder Type'; ?>
                    </div>

                    <form method="POST" action="manage-stakeholder-types.php">
                        <?php if ($is_edit && $edit_record): ?>
                            <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Stakeholder Type</label>
                                <input type="text" class="form-control" name="stakeholder_type" 
                                       value="<?php echo $is_edit ? e($edit_record['stakeholder_type']) : ''; ?>" 
                                       placeholder="e.g., Investor, Contractor, Supplier, Government Agency" required>
                                <small class="text-muted">Unique identifier for the stakeholder type.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <div class="mt-2">
                                    <label class="switch">
                                        <input type="checkbox" name="is_active" value="1" <?php echo (!$is_edit || $edit_record['is_active'] == 1) ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <span class="ms-2 text-muted">Active/Inactive</span>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3" 
                                          placeholder="Optional description of this stakeholder type..."><?php echo $is_edit ? e($edit_record['description']) : ''; ?></textarea>
                            </div>

                            <div class="col-12">
                                <div class="d-flex gap-2 justify-content-end">
                                    <?php if ($is_edit): ?>
                                        <a href="manage-stakeholder-types.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-x-lg"></i> Cancel
                                        </a>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-<?php echo $is_edit ? 'check-lg' : 'plus-lg'; ?>"></i>
                                        <?php echo $is_edit ? 'Update' : 'Add'; ?> Stakeholder Type
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Records List Panel -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-diagram-3"></i> Stakeholder Types List
                        </h3>
                    </div>

                    <!-- MOBILE: Cards View -->
                    <div class="d-block d-md-none">
                        <?php if (empty($records)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox" style="font-size: 32px;"></i>
                                <p class="mt-2 fw-bold">No stakeholder types found</p>
                                <p class="small">Add your first stakeholder type using the form above.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-grid gap-3">
                                <?php foreach ($records as $record): ?>
                                    <div class="border rounded-3 p-3 bg-white" style="border-color: var(--border) !important;">
                                        <div class="d-flex align-items-start justify-content-between mb-2">
                                            <h5 class="fw-900 mb-0"><?php echo e($record['stakeholder_type']); ?></h5>
                                            <?php echo getStatusBadge($record['is_active']); ?>
                                        </div>
                                        <?php if (!empty($record['description'])): ?>
                                            <p class="text-muted small mb-2"><?php echo nl2br(e($record['description'])); ?></p>
                                        <?php endif; ?>
                                        <div class="text-muted small mb-2">
                                            <i class="bi bi-calendar"></i> Created: <?php echo safeDate($record['created_at']); ?>
                                        </div>
                                        <div class="action-buttons d-flex gap-2 mt-2">
                                            <a href="?edit_id=<?php echo $record['id']; ?>" class="btn-action flex-grow-1">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $record['id']; ?>)" class="btn-action flex-grow-1" style="color:#ef4444;">
                                                <i class="bi bi-trash"></i> Delete
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- DESKTOP/TABLET: DataTable -->
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table id="stakeholderTypesTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Stakeholder Type</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Created At</th>
                                        <th>Updated At</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $record): ?>
                                        <tr>
                                            <td><?php echo $record['id']; ?></td>
                                            <td class="fw-800"><?php echo e($record['stakeholder_type']); ?></td>
                                            <td><?php echo nl2br(e($record['description'])); ?></td>
                                            <td><?php echo getStatusBadge($record['is_active']); ?></td>
                                            <td><?php echo safeDate($record['created_at']); ?></td>
                                            <td><?php echo safeDate($record['updated_at']); ?></td>
                                            <td class="text-end">
                                                <a href="?edit_id=<?php echo $record['id']; ?>" class="btn-action" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $record['id']; ?>)" class="btn-action ms-1" title="Delete" style="color:#ef4444;">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-900" id="deleteModalLabel">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete this stakeholder type? This action cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                    <i class="bi bi-trash"></i> Delete
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<!-- TEK-C Custom JavaScript -->
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    // Delete confirmation
    let deleteId = null;
    
    function confirmDelete(id) {
        deleteId = id;
        const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
    }
    
    document.getElementById('confirmDeleteBtn').addEventListener('click', function(e) {
        if (deleteId) {
            window.location.href = 'manage-stakeholder-types.php?delete_id=' + deleteId;
        }
    });
    
    // Initialize DataTable for desktop view
    function initDataTable() {
        const isDesktop = window.matchMedia('(min-width: 768px)').matches;
        const tbl = document.getElementById('stakeholderTypesTable');
        if (!tbl) return;
        
        if (isDesktop) {
            if (!$.fn.DataTable.isDataTable('#stakeholderTypesTable')) {
                $('#stakeholderTypesTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    scrollX: false,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                    order: [[0, 'asc']], // Sort by ID ascending
                    columnDefs: [
                        { targets: [6], orderable: false, searchable: false } // Actions column
                    ],
                    language: {
                        zeroRecords: "No stakeholder types found",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries",
                        infoEmpty: "No entries to show",
                        lengthMenu: "Show _MENU_",
                        search: "Search:"
                    }
                });
            }
        } else {
            if ($.fn.DataTable.isDataTable('#stakeholderTypesTable')) {
                $('#stakeholderTypesTable').DataTable().destroy();
            }
        }
    }
    
    initDataTable();
    window.addEventListener('resize', initDataTable);
    
    // Set current year in footer
    const yearElement = document.getElementById("year");
    if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
    }
</script>

</body>
</html>