<?php
// hr/hiring-requests.php - Hiring Request Management (TEK-C Style)
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// ---------------- AUTH (HR/Manager) ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];

// Get current employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$current_employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die("Employee not found.");
}

// Check permissions
$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department = strtolower(trim($current_employee['department'] ?? ''));

$isHr = ($designation === 'hr' || $department === 'hr');
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'director', 'administrator']);
$isAdmin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director');

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- HANDLE APPROVAL/REJECTION (HR/Admin only) ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isHr) {
    $request_id = (int)$_POST['request_id'];
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
    
    if ($_POST['action'] === 'approve') {
        $update_stmt = mysqli_prepare($conn, "
            UPDATE hiring_requests 
            SET status = 'Approved', approved_by = ?, approved_by_name = ?, approved_at = NOW(), approver_remarks = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($update_stmt, "issi", $current_employee_id, $current_employee['full_name'], $remarks, $request_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'hiring_request',
                "Approved hiring request ID: {$request_id}",
                $request_id,
                null,
                null,
                json_encode(['remarks' => $remarks])
            );
            
            $message = "Hiring request approved successfully!";
            $messageType = "success";
        }
    } elseif ($_POST['action'] === 'reject') {
        $update_stmt = mysqli_prepare($conn, "
            UPDATE hiring_requests 
            SET status = 'Rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ?
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($update_stmt, "isi", $current_employee_id, $remarks, $request_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'hiring_request',
                "Rejected hiring request ID: {$request_id}",
                $request_id,
                null,
                null,
                json_encode(['reason' => $remarks])
            );
            
            $message = "Hiring request rejected successfully!";
            $messageType = "success";
        }
    }
    
    if (isset($update_stmt) && mysqli_stmt_execute($update_stmt)) {
        // Message already set above
    } else {
        $message = "Error processing request: " . mysqli_error($conn);
        $messageType = "danger";
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'all';
$department_filter = $_GET['department'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT h.*, 
           COUNT(c.id) as candidates_count,
           SUM(CASE WHEN c.status IN ('Selected', 'Offered', 'Joined') THEN 1 ELSE 0 END) as selected_count,
           SUM(CASE WHEN c.status = 'Joined' THEN 1 ELSE 0 END) as joined_count
    FROM hiring_requests h
    LEFT JOIN candidates c ON h.id = c.hiring_request_id
    WHERE 1=1
";

// Managers see only their own requests, HR sees all
if (!$isHr && $isManager) {
    $query .= " AND h.requested_by = {$current_employee_id}";
}

if ($status_filter !== 'all') {
    $status_filter = mysqli_real_escape_string($conn, ucfirst($status_filter));
    $query .= " AND h.status = '{$status_filter}'";
}

if (!empty($department_filter)) {
    $department_filter = mysqli_real_escape_string($conn, $department_filter);
    $query .= " AND h.department = '{$department_filter}'";
}

if (!empty($priority_filter)) {
    $priority_filter = mysqli_real_escape_string($conn, $priority_filter);
    $query .= " AND h.priority = '{$priority_filter}'";
}

if (!empty($search)) {
    $search_term = mysqli_real_escape_string($conn, $search);
    $query .= " AND (h.request_no LIKE '%{$search_term}%' 
                     OR h.position_title LIKE '%{$search_term}%'
                     OR h.designation LIKE '%{$search_term}%'
                     OR h.requested_by_name LIKE '%{$search_term}%')";
}

$query .= " GROUP BY h.id ORDER BY 
    CASE h.priority 
        WHEN 'Urgent' THEN 1 
        WHEN 'High' THEN 2 
        WHEN 'Medium' THEN 3 
        WHEN 'Low' THEN 4 
    END, 
    h.created_at DESC";

$requests = mysqli_query($conn, $query);

// Get counts for dashboard
$stats_query = "
    SELECT 
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(vacancies) as total_vacancies
    FROM hiring_requests
";
if (!$isHr && $isManager) {
    $stats_query .= " WHERE requested_by = {$current_employee_id}";
}
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// ---------------- HELPER FUNCTIONS ----------------
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($date, $format = 'd M Y')
{
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($date));
}

function getStatusBadge($status) {
    $classes = [
        'Pending' => 'status-screening',
        'Approved' => 'status-selected',
        'In Progress' => 'status-interview',
        'Rejected' => 'status-rejected',
        'Closed' => 'status-joined',
        'Cancelled' => 'status-declined'
    ];
    $class = $classes[$status] ?? 'status-new';
    return "<span class='status-badge {$class}'><i class='bi bi-circle-fill' style='font-size:8px;'></i> {$status}</span>";
}

function getPriorityBadge($priority) {
    switch($priority) {
        case 'Urgent':
            return '<span class="status-badge status-rejected"><i class="bi bi-exclamation-triangle-fill"></i> Urgent</span>';
        case 'High':
            return '<span class="status-badge status-hold"><i class="bi bi-arrow-up-circle-fill"></i> High</span>';
        case 'Medium':
            return '<span class="status-badge status-interview"><i class="bi bi-dash-circle-fill"></i> Medium</span>';
        case 'Low':
            return '<span class="status-badge status-screening"><i class="bi bi-arrow-down-circle-fill"></i> Low</span>';
        default:
            return '<span class="status-badge">' . e($priority) . '</span>';
    }
}

function getProgressIndicator($vacancies, $selected, $joined) {
    $filled = $joined;
    $in_progress = $selected - $joined;
    $remaining = $vacancies - $selected;
    
    $filled_width = $vacancies > 0 ? round(($filled / $vacancies) * 100) : 0;
    $progress_width = $vacancies > 0 ? round(($in_progress / $vacancies) * 100) : 0;
    
    $html = '<div class="d-flex align-items-center gap-2">';
    $html .= '<div class="flex-grow-1">';
    $html .= '<div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">';
    if ($filled > 0) {
        $html .= '<div style="height: 6px; background: #10b981; width: ' . $filled_width . '%; float: left;"></div>';
    }
    if ($in_progress > 0) {
        $html .= '<div style="height: 6px; background: #f59e0b; width: ' . $progress_width . '%; float: left;"></div>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<span class="fw-900" style="font-size:12px;">' . $filled . '/' . $vacancies . '</span>';
    $html .= '</div>';
    
    return $html;
}

$departments = ['PM', 'CM', 'IFM', 'QS', 'HR', 'ACCOUNTS'];
$priorities = ['Urgent', 'High', 'Medium', 'Low'];

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hiring Requests - TEK-C Hiring</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px; }
        
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px;
            height: 100%;
        }
        
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .panel-title {
            font-weight: 900;
            font-size: 18px;
            color: #1f2937;
            margin: 0;
        }
        
        .panel-menu {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
            display: grid;
            place-items: center;
            color: #6b7280;
        }

        /* Stats Cards */
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 14px 16px;
            height: 90px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .stat-ic {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 20px;
            flex: 0 0 auto;
        }
        
        .stat-ic.blue { background: var(--blue); }
        .stat-ic.green { background: #10b981; }
        .stat-ic.yellow { background: #f59e0b; }
        .stat-ic.purple { background: #8b5cf6; }
        .stat-ic.red { background: #ef4444; }
        
        .stat-label {
            color: #4b5563;
            font-weight: 750;
            font-size: 13px;
        }
        
        .stat-value {
            font-size: 30px;
            font-weight: 900;
            line-height: 1;
            margin-top: 2px;
        }

        /* Filter Card */
        .filter-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 18px;
            margin-bottom: 20px;
        }

        /* Buttons */
        .btn-add {
            background: var(--blue);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-add:hover { background: #2a8bc9; color: #fff; }

        .btn-filter {
            background: #10b981;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(16, 185, 129, 0.18);
            white-space: nowrap;
        }
        .btn-filter:hover { background: #0da271; color: #fff; }

        .btn-reset {
            background: #6b7280;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            text-decoration: none;
        }
        .btn-reset:hover { background: #4b5563; color: #fff; }

        /* Table Styles */
        .table-responsive { overflow-x: hidden !important; }
        table.dataTable { width: 100% !important; }
        
        .table thead th {
            font-size: 11px;
            color: #6b7280;
            font-weight: 800;
            border-bottom: 1px solid var(--border) !important;
            padding: 10px 10px !important;
            white-space: normal !important;
        }
        
        .table td {
            vertical-align: middle;
            border-color: var(--border);
            font-weight: 650;
            color: #374151;
            padding: 12px 10px !important;
            white-space: normal !important;
            word-break: break-word;
        }

        /* Status Badges */
        .status-badge {
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        
        .status-new { background: rgba(45, 156, 219, .12); color: var(--blue); border: 1px solid rgba(45, 156, 219, .22); }
        .status-screening { background: rgba(107, 114, 128, .12); color: #6b7280; border: 1px solid rgba(107, 114, 128, .22); }
        .status-shortlisted { background: rgba(139, 92, 246, .12); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, .22); }
        .status-interview { background: rgba(245, 158, 11, .12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, .22); }
        .status-interviewed { background: rgba(16, 185, 129, .12); color: #10b981; border: 1px solid rgba(16, 185, 129, .22); }
        .status-selected { background: rgba(16, 185, 129, .12); color: #10b981; border: 1px solid rgba(16, 185, 129, .22); }
        .status-rejected { background: rgba(239, 68, 68, .12); color: #ef4444; border: 1px solid rgba(239, 68, 68, .22); }
        .status-hold { background: rgba(245, 158, 11, .12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, .22); }
        .status-offered { background: rgba(16, 185, 129, .12); color: #10b981; border: 1px solid rgba(16, 185, 129, .22); }
        .status-joined { background: rgba(16, 185, 129, .12); color: #10b981; border: 1px solid rgba(16, 185, 129, .22); }
        .status-declined { background: rgba(239, 68, 68, .12); color: #ef4444; border: 1px solid rgba(239, 68, 68, .22); }

        /* Action Buttons */
        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 8px;
            color: var(--muted);
            font-size: 12px;
            margin: 0 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-action:hover { background: var(--bg); color: var(--blue); }
        .btn-action.success:hover { background: #d1fae5; color: #065f46; border-color: #065f46; }
        .btn-action.warning:hover { background: #fef3c7; color: #92400e; border-color: #92400e; }
        .btn-action.danger:hover { background: #fee2e2; color: #991b1b; border-color: #991b1b; }

        /* Request Number */
        .request-no {
            font-weight: 900;
            font-size: 13px;
            color: var(--blue);
        }

        /* Position Info */
        .position-title {
            font-weight: 800;
            font-size: 13px;
            color: #1f2937;
            margin-bottom: 2px;
        }
        
        .designation-text {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
        }

        /* Requester Info */
        .requester-name {
            font-weight: 700;
            font-size: 12px;
            color: #1f2937;
        }
        
        .request-date {
            font-size: 10px;
            color: #6b7280;
            font-weight: 600;
        }

        /* Candidate Stats */
        .candidate-stats {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-circle {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 13px;
        }
        
        .stat-circle.total { background: #f3f4f6; color: #374151; }
        .stat-circle.selected { background: #d1fae5; color: #065f46; }
        .stat-circle.joined { background: #dbeafe; color: #1e40af; }

        /* Progress Bar */
        .progress {
            height: 6px;
            background-color: #e5e7eb;
            border-radius: 3px;
        }
        .progress-bar-filled { background-color: #10b981; }
        .progress-bar-progress { background-color: #f59e0b; }

        /* Modal Styles */
        .modal-content {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
        }
        .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 16px 20px;
        }
        .modal-title {
            font-weight: 900;
            font-size: 18px;
            color: #1f2937;
        }
        .modal-body { padding: 20px; }
        .modal-footer {
            border-top: 1px solid var(--border);
            padding: 16px 20px;
        }

        /* Form Labels */
        .form-label {
            font-weight: 800;
            font-size: 12px;
            color: #4b5563;
            margin-bottom: 4px;
        }
        .required:after {
            content: " *";
            color: #ef4444;
        }

        /* Alert Styles */
        .alert {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        /* Actions Column Width */
        th.actions-col, td.actions-col { width: 120px !important; white-space: nowrap !important; }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div class="content-scroll">
            <div class="container-fluid maxw">

                <!-- Flash Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
                        <?php echo e($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Hiring Requests</h1>
                        <p class="text-muted mb-0">Manage job openings and recruitment requests</p>
                    </div>
                    <a href="new-hiring-request.php" class="btn-add">
                        <i class="bi bi-plus-circle"></i> New Request
                    </a>
                </div>

                <!-- Stats Cards -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-clock"></i></div>
                            <div>
                                <div class="stat-label">Pending</div>
                                <div class="stat-value"><?php echo (int)($stats['pending'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="stat-label">Approved</div>
                                <div class="stat-value"><?php echo (int)($stats['approved'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-gear"></i></div>
                            <div>
                                <div class="stat-label">In Progress</div>
                                <div class="stat-value"><?php echo (int)($stats['in_progress'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check2-circle"></i></div>
                            <div>
                                <div class="stat-label">Closed</div>
                                <div class="stat-value"><?php echo (int)($stats['closed'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                            <div>
                                <div class="stat-label">Rejected</div>
                                <div class="stat-value"><?php echo (int)($stats['rejected'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-people"></i></div>
                            <div>
                                <div class="stat-label">Vacancies</div>
                                <div class="stat-value"><?php echo (int)($stats['total_vacancies'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="filter-card">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="all">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="in progress" <?php echo $status_filter === 'in progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select form-select-sm">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept; ?>" <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                        <?php echo $dept; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select form-select-sm">
                                <option value="">All Priorities</option>
                                <?php foreach ($priorities as $p): ?>
                                    <option value="<?php echo $p; ?>" <?php echo $priority_filter === $p ? 'selected' : ''; ?>>
                                        <?php echo $p; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm" 
                                   placeholder="Request No., Position, Requester..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn-filter w-100">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                            <a href="hiring-requests.php" class="btn-reset">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Requests Table -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">Hiring Requests</h3>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <div class="table-responsive">
                        <table id="requestsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Request No.</th>
                                    <th>Position</th>
                                    <th>Department</th>
                                    <th>Vacancies</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Requested By</th>
                                    <th>Progress</th>
                                    <th class="text-end actions-col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($requests) === 0): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                            No hiring requests found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php while ($row = mysqli_fetch_assoc($requests)): ?>
                                        <tr>
                                            <td>
                                                <span class="request-no"><?php echo e($row['request_no']); ?></span>
                                            </td>
                                            <td>
                                                <div class="position-title"><?php echo e($row['position_title']); ?></div>
                                                <div class="designation-text"><?php echo e($row['designation']); ?></div>
                                            </td>
                                            <td><?php echo e($row['department']); ?></td>
                                            <td class="text-center fw-900"><?php echo (int)$row['vacancies']; ?></td>
                                            <td><?php echo getPriorityBadge($row['priority']); ?></td>
                                            <td><?php echo getStatusBadge($row['status']); ?></td>
                                            <td>
                                                <div class="requester-name"><?php echo e($row['requested_by_name']); ?></div>
                                                <div class="request-date">
                                                    <i class="bi bi-calendar"></i> <?php echo safeDate($row['requested_date']); ?>
                                                </div>
                                            </td>
                                            <td style="min-width: 150px;">
                                                <?php 
                                                $filled = (int)$row['joined_count'];
                                                $selected = (int)$row['selected_count'];
                                                $vacancies = (int)$row['vacancies'];
                                                ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="flex-grow-1">
                                                        <div class="progress">
                                                            <?php if ($filled > 0): ?>
                                                                <div class="progress-bar-filled" style="width: <?php echo ($filled/$vacancies)*100; ?>%"></div>
                                                            <?php endif; ?>
                                                            <?php if ($selected - $filled > 0): ?>
                                                                <div class="progress-bar-progress" style="width: <?php echo (($selected-$filled)/$vacancies)*100; ?>%"></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <span class="fw-900" style="font-size:12px;">
                                                        <?php echo $filled; ?>/<?php echo $vacancies; ?>
                                                    </span>
                                                </div>
                                                <div class="d-flex gap-2 mt-1">
                                                    <small class="text-success">
                                                        <i class="bi bi-check-circle-fill" style="font-size:8px;"></i> <?php echo $selected; ?> selected
                                                    </small>
                                                    <?php if ($filled > 0): ?>
                                                        <small class="text-primary">
                                                            <i class="bi bi-person-check-fill" style="font-size:8px;"></i> <?php echo $filled; ?> joined
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="text-end actions-col">
                                                <a href="view-hiring-request.php?id=<?php echo $row['id']; ?>" class="btn-action" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                
                                                <?php if ($row['status'] === 'Pending' && $isHr): ?>
                                                    <button class="btn-action success" 
                                                            onclick="openApproveModal(<?php echo $row['id']; ?>, '<?php echo e($row['request_no']); ?>')" 
                                                            title="Approve">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                    <button class="btn-action danger" 
                                                            onclick="openRejectModal(<?php echo $row['id']; ?>, '<?php echo e($row['request_no']); ?>')" 
                                                            title="Reject">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <a href="candidates.php?hiring_id=<?php echo $row['id']; ?>" class="btn-action" title="View Candidates">
                                                    <i class="bi bi-people"></i>
                                                </a>
                                                
                                                <?php if ($row['status'] === 'Approved' || $row['status'] === 'In Progress'): ?>
                                                    <a href="candidates.php?hiring_id=<?php echo $row['id']; ?>&add=1" class="btn-action success" title="Add Candidate">
                                                        <i class="bi bi-person-plus"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
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

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" id="approve_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Approve Hiring Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p class="mb-3">Are you sure you want to approve <strong id="approve_no"></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2" placeholder="Add any remarks..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-add">Approve Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="reject_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Reject Hiring Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p class="mb-3">Are you sure you want to reject <strong id="reject_no"></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label required">Reason for Rejection</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Please provide reason for rejection" required></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-add" style="background: #ef4444;">Reject Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#requestsTable').DataTable({
        responsive: true,
        autoWidth: false,
        scrollX: false,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: [[0, 'desc']],
        language: {
            zeroRecords: "No matching hiring requests found",
            info: "Showing _START_ to _END_ of _TOTAL_ requests",
            infoEmpty: "No requests to show",
            lengthMenu: "Show _MENU_",
            search: "Search:"
        },
        columnDefs: [
            { orderable: false, targets: [8] }
        ]
    });

    // Auto-focus search
    setTimeout(function() {
        $('.dataTables_filter input').focus();
    }, 400);
});

function openApproveModal(id, requestNo) {
    $('#approve_id').val(id);
    $('#approve_no').text(requestNo);
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function openRejectModal(id, requestNo) {
    $('#reject_id').val(id);
    $('#reject_no').text(requestNo);
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>