<?php
// hr/offer-approval.php - Offer Approval Management
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// ---------------- AUTH (HR/Manager/Director) ----------------
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
$isManager = in_array($designation, ['manager', 'team lead', 'project manager']);
$isDirector = in_array($designation, ['director', 'vice president', 'general manager']);
$isAdmin = ($designation === 'administrator' || $designation === 'admin');

// Only HR, Directors, and Admins can approve offers
if (!$isHr && !$isDirector && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access offer approval.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- HANDLE OFFER ACTIONS ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Approve Offer
    if (isset($_POST['action']) && $_POST['action'] === 'approve_offer') {
        $offer_id = (int)$_POST['offer_id'];
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Update offer status
            $update_stmt = mysqli_prepare($conn, "
                UPDATE offers 
                SET status = 'Approved',
                    approved_by = ?,
                    approved_by_name = ?,
                    approved_at = NOW(),
                    approver_remarks = ?
                WHERE id = ? AND status = 'Draft'
            ");
            
            $approver_name = $current_employee['full_name'];
            mysqli_stmt_bind_param($update_stmt, "issi", $current_employee_id, $approver_name, $remarks, $offer_id);
            
            if (!mysqli_stmt_execute($update_stmt)) {
                throw new Exception("Failed to approve offer: " . mysqli_error($conn));
            }
            
            if (mysqli_affected_rows($conn) === 0) {
                throw new Exception("Offer not found or already processed.");
            }
            
            // Get offer details for logging and candidate update
            $offer_query = "SELECT candidate_id, offer_no FROM offers WHERE id = ?";
            $offer_stmt = mysqli_prepare($conn, $offer_query);
            mysqli_stmt_bind_param($offer_stmt, "i", $offer_id);
            mysqli_stmt_execute($offer_stmt);
            $offer_res = mysqli_stmt_get_result($offer_stmt);
            $offer_data = mysqli_fetch_assoc($offer_res);
            
            // Update candidate status to 'Offered'
            $candidate_stmt = mysqli_prepare($conn, "UPDATE candidates SET status = 'Offered' WHERE id = ?");
            mysqli_stmt_bind_param($candidate_stmt, "i", $offer_data['candidate_id']);
            mysqli_stmt_execute($candidate_stmt);
            
            // Log activity
            logActivity(
                $conn,
                'UPDATE',
                'offer',
                "Approved offer: {$offer_data['offer_no']}",
                $offer_id,
                null,
                null,
                json_encode(['remarks' => $remarks])
            );
            
            mysqli_commit($conn);
            
            $message = "Offer approved successfully!";
            $messageType = "success";
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Reject Offer
    elseif (isset($_POST['action']) && $_POST['action'] === 'reject_offer') {
        $offer_id = (int)$_POST['offer_id'];
        $rejection_reason = mysqli_real_escape_string($conn, $_POST['rejection_reason'] ?? '');
        
        if (empty($rejection_reason)) {
            $message = "Rejection reason is required.";
            $messageType = "danger";
        } else {
            // Begin transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Update offer status
                $update_stmt = mysqli_prepare($conn, "
                    UPDATE offers 
                    SET status = 'Rejected',
                        approved_by = ?,
                        approved_by_name = ?,
                        approved_at = NOW(),
                        rejection_reason = ?
                    WHERE id = ? AND status = 'Draft'
                ");
                
                $approver_name = $current_employee['full_name'];
                mysqli_stmt_bind_param($update_stmt, "issi", $current_employee_id, $approver_name, $rejection_reason, $offer_id);
                
                if (!mysqli_stmt_execute($update_stmt)) {
                    throw new Exception("Failed to reject offer: " . mysqli_error($conn));
                }
                
                if (mysqli_affected_rows($conn) === 0) {
                    throw new Exception("Offer not found or already processed.");
                }
                
                // Get offer details for logging
                $offer_query = "SELECT offer_no FROM offers WHERE id = ?";
                $offer_stmt = mysqli_prepare($conn, $offer_query);
                mysqli_stmt_bind_param($offer_stmt, "i", $offer_id);
                mysqli_stmt_execute($offer_stmt);
                $offer_res = mysqli_stmt_get_result($offer_stmt);
                $offer_data = mysqli_fetch_assoc($offer_res);
                
                // Log activity
                logActivity(
                    $conn,
                    'REJECT',
                    'offer',
                    "Rejected offer: {$offer_data['offer_no']}",
                    $offer_id,
                    null,
                    null,
                    json_encode(['reason' => $rejection_reason])
                );
                
                mysqli_commit($conn);
                
                $message = "Offer rejected successfully.";
                $messageType = "success";
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = "Error: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
    
    // Bulk Approve Offers
    elseif (isset($_POST['action']) && $_POST['action'] === 'bulk_approve') {
        if (isset($_POST['offer_ids']) && is_array($_POST['offer_ids'])) {
            $offer_ids = array_map('intval', $_POST['offer_ids']);
            $remarks = mysqli_real_escape_string($conn, $_POST['bulk_remarks'] ?? '');
            $success_count = 0;
            $error_count = 0;
            
            mysqli_begin_transaction($conn);
            
            try {
                foreach ($offer_ids as $offer_id) {
                    // Update offer status
                    $update_stmt = mysqli_prepare($conn, "
                        UPDATE offers 
                        SET status = 'Approved',
                            approved_by = ?,
                            approved_by_name = ?,
                            approved_at = NOW(),
                            approver_remarks = ?
                        WHERE id = ? AND status = 'Draft'
                    ");
                    
                    $approver_name = $current_employee['full_name'];
                    mysqli_stmt_bind_param($update_stmt, "issi", $current_employee_id, $approver_name, $remarks, $offer_id);
                    mysqli_stmt_execute($update_stmt);
                    
                    if (mysqli_affected_rows($conn) > 0) {
                        // Get candidate_id
                        $cand_query = "SELECT candidate_id, offer_no FROM offers WHERE id = ?";
                        $cand_stmt = mysqli_prepare($conn, $cand_query);
                        mysqli_stmt_bind_param($cand_stmt, "i", $offer_id);
                        mysqli_stmt_execute($cand_stmt);
                        $cand_res = mysqli_stmt_get_result($cand_stmt);
                        $cand_data = mysqli_fetch_assoc($cand_res);
                        
                        if ($cand_data) {
                            // Update candidate status
                            $candidate_stmt = mysqli_prepare($conn, "UPDATE candidates SET status = 'Offered' WHERE id = ?");
                            mysqli_stmt_bind_param($candidate_stmt, "i", $cand_data['candidate_id']);
                            mysqli_stmt_execute($candidate_stmt);
                            
                            // Log activity
                            logActivity(
                                $conn,
                                'UPDATE',
                                'offer',
                                "Bulk approved offer: {$cand_data['offer_no']}",
                                $offer_id,
                                null,
                                null,
                                json_encode(['remarks' => $remarks])
                            );
                        }
                        
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                }
                
                mysqli_commit($conn);
                
                $message = "Successfully approved {$success_count} offers.";
                if ($error_count > 0) {
                    $message .= " {$error_count} offers could not be processed.";
                }
                $messageType = "success";
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = "Error during bulk approval: " . $e->getMessage();
                $messageType = "danger";
            }
        } else {
            $message = "No offers selected for bulk approval.";
            $messageType = "warning";
        }
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'pending';
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$department_filter = $_GET['department'] ?? '';

// Build query for pending offers
$query = "
    SELECT 
        o.*,
        c.id as candidate_id,
        c.first_name,
        c.last_name,
        c.candidate_code,
        c.photo_path as candidate_photo,
        c.email as candidate_email,
        c.phone as candidate_phone,
        c.total_experience,
        c.notice_period,
        c.current_company,
        CONCAT(c.first_name, ' ', c.last_name) as candidate_name,
        h.id as hiring_request_id,
        h.request_no,
        h.position_title,
        h.department,
        h.designation as hiring_designation,
        h.location as job_location,
        h.experience_min,
        h.experience_max,
        h.salary_min,
        h.salary_max,
        h.requested_by,
        h.requested_by_name,
        req_emp.full_name as requester_name,
        req_emp.designation as requester_designation
    FROM offers o
    JOIN candidates c ON o.candidate_id = c.id
    JOIN hiring_requests h ON o.hiring_request_id = h.id
    LEFT JOIN employees req_emp ON h.requested_by = req_emp.id
    WHERE 1=1
";

// Filter by status
if ($status_filter === 'pending') {
    $query .= " AND o.status = 'Draft'";
} elseif ($status_filter === 'approved') {
    $query .= " AND o.status = 'Approved'";
} elseif ($status_filter === 'rejected') {
    $query .= " AND o.status = 'Rejected'";
} elseif ($status_filter === 'sent') {
    $query .= " AND o.status = 'Sent'";
} elseif ($status_filter === 'accepted') {
    $query .= " AND o.status = 'Accepted'";
}

// Filter by department
if (!empty($department_filter)) {
    $department_filter = mysqli_real_escape_string($conn, $department_filter);
    $query .= " AND h.department = '{$department_filter}'";
}

// Filter by date range
if (!empty($date_from)) {
    $query .= " AND DATE(o.created_at) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}
if (!empty($date_to)) {
    $query .= " AND DATE(o.created_at) <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}

// Search
if (!empty($search)) {
    $search_term = mysqli_real_escape_string($conn, $search);
    $query .= " AND (c.first_name LIKE '%{$search_term}%' 
                    OR c.last_name LIKE '%{$search_term}%' 
                    OR c.email LIKE '%{$search_term}%'
                    OR c.candidate_code LIKE '%{$search_term}%'
                    OR o.offer_no LIKE '%{$search_term}%'
                    OR h.position_title LIKE '%{$search_term}%')";
}

$query .= " ORDER BY o.created_at DESC";

$offers = mysqli_query($conn, $query);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(CASE WHEN status = 'Draft' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'Approved' THEN 1 END) as approved_count,
        COUNT(CASE WHEN status = 'Rejected' THEN 1 END) as rejected_count,
        COUNT(CASE WHEN status = 'Sent' THEN 1 END) as sent_count,
        COUNT(CASE WHEN status = 'Accepted' THEN 1 END) as accepted_count,
        COUNT(CASE WHEN status = 'Draft' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as new_this_week,
        AVG(CASE WHEN status = 'Draft' THEN o.ctc END) as avg_offer_ctc
    FROM offers o
";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get departments for filter
$dept_query = "SELECT DISTINCT department FROM hiring_requests ORDER BY department";
$dept_result = mysqli_query($conn, $dept_query);

// ---------------- HELPER FUNCTIONS ----------------
function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount)
{
    if (!$amount)
        return '—';
    return '₹ ' . number_format($amount, 2) . ' LPA';
}

function formatDate($date)
{
    if (!$date)
        return '—';
    return date('d M Y', strtotime($date));
}

function getOfferStatusBadge($status)
{
    $classes = [
        'Draft' => 'bg-secondary',
        'Approved' => 'bg-success',
        'Rejected' => 'bg-danger',
        'Sent' => 'bg-info',
        'Accepted' => 'bg-success',
        'Expired' => 'bg-warning text-dark',
        'Withdrawn' => 'bg-dark'
    ];
    $icons = [
        'Draft' => 'bi-pencil',
        'Approved' => 'bi-check-circle',
        'Rejected' => 'bi-x-circle',
        'Sent' => 'bi-envelope',
        'Accepted' => 'bi-check2-circle',
        'Expired' => 'bi-clock',
        'Withdrawn' => 'bi-dash-circle'
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    $icon = $icons[$status] ?? 'bi-question';
    return "<span class='badge {$class} px-3 py-2'><i class='bi {$icon} me-1'></i> {$status}</span>";
}

function getInitials($name)
{
    $name = trim((string) $name);
    if ($name === '')
        return 'U';
    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last = strtoupper(substr(end($parts) ?: '', 0, 1));
    return (count($parts) > 1) ? ($first . $last) : $first;
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Offer Approval - TEK-C Hiring</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css"
        rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll {
            flex: 1 1 auto;
            overflow: auto;
            padding: 22px;
        }

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
            margin-bottom: 10px;
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

        .stat-ic.pending {
            background: #f59e0b;
        }

        .stat-ic.approved {
            background: #10b981;
        }

        .stat-ic.rejected {
            background: #ef4444;
        }

        .stat-ic.sent {
            background: #3b82f6;
        }

        .stat-ic.accepted {
            background: #8b5cf6;
        }

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
        .btn-primary-custom {
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

        .btn-primary-custom:hover {
            background: #2a8bc9;
            color: #fff;
        }

        .btn-success-custom {
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

        .btn-success-custom:hover {
            background: #0da271;
            color: #fff;
        }

        .btn-danger-custom {
            background: #ef4444;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(239, 68, 68, 0.18);
            white-space: nowrap;
        }

        .btn-danger-custom:hover {
            background: #dc2626;
            color: #fff;
        }

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

        .btn-reset:hover {
            background: #4b5563;
            color: #fff;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: hidden !important;
        }

        table.dataTable {
            width: 100% !important;
        }

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
            padding: 10px 10px !important;
            white-space: normal !important;
            word-break: break-word;
        }

        /* Candidate Avatar */
        .candidate-avatar {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 900;
            font-size: 16px;
            flex: 0 0 auto;
        }

        .candidate-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .candidate-name {
            font-weight: 900;
            font-size: 13px;
            color: #1f2937;
            margin-bottom: 2px;
            line-height: 1.2;
        }

        .candidate-code {
            font-size: 11px;
            color: #6b7280;
            font-weight: 650;
            line-height: 1.2;
        }

        /* Offer Details */
        .offer-no {
            font-weight: 900;
            font-size: 13px;
            color: #1f2937;
        }

        .offer-date {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .offer-amount {
            font-weight: 900;
            font-size: 14px;
            color: #059669;
        }

        /* Position Info */
        .position-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .position-text {
            font-size: 12px;
            font-weight: 800;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 6px;
            line-height: 1.2;
        }

        .position-text i {
            color: var(--blue);
            font-size: 13px;
        }

        .department-badge {
            background: rgba(45, 156, 219, .1);
            color: var(--blue);
            padding: 3px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 900;
            border: 1px solid rgba(45, 156, 219, .2);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            width: fit-content;
        }

        /* Requester Info */
        .requester-name {
            font-weight: 800;
            font-size: 12px;
            color: #1f2937;
        }

        .requester-designation {
            font-size: 10px;
            color: #6b7280;
            font-weight: 600;
        }

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

        .btn-action:hover {
            background: var(--bg);
            color: var(--blue);
        }

        .btn-action.approve:hover {
            background: #d1fae5;
            color: #065f46;
            border-color: #065f46;
        }

        .btn-action.reject:hover {
            background: #fee2e2;
            color: #991b1b;
            border-color: #991b1b;
        }

        .btn-action.view:hover {
            background: #dbeafe;
            color: #1e40af;
            border-color: #1e40af;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: #f9fafb;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--border);
        }

        .checkbox-col {
            width: 40px;
            text-align: center;
        }

        /* Approval Timeline */
        .approval-timeline {
            font-size: 11px;
            color: #6b7280;
        }

        .approval-timeline i {
            font-size: 12px;
            margin-right: 4px;
        }

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

        .modal-body {
            padding: 20px;
        }

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

        /* Summary Card */
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--radius);
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }

        .summary-stat {
            text-align: center;
        }

        .summary-stat-value {
            font-size: 32px;
            font-weight: 900;
            line-height: 1;
        }

        .summary-stat-label {
            font-size: 12px;
            opacity: 0.9;
            font-weight: 700;
            margin-top: 5px;
        }

        /* Actions Column Width */
        th.actions-col,
        td.actions-col {
            width: 120px !important;
            white-space: nowrap !important;
        }

        th.select-col,
        td.select-col {
            width: 40px !important;
        }
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

                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-dark mb-1">Offer Approval</h1>
                            <p class="text-muted mb-0">Review and approve employment offers</p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="offers.php" class="btn-primary-custom">
                                <i class="bi bi-plus-lg"></i> Manage Offers
                            </a>
                        </div>
                    </div>

                    <!-- Summary Cards -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic pending"><i class="bi bi-clock"></i></div>
                                <div>
                                    <div class="stat-label">Pending</div>
                                    <div class="stat-value"><?php echo (int) ($stats['pending_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic approved"><i class="bi bi-check-circle"></i></div>
                                <div>
                                    <div class="stat-label">Approved</div>
                                    <div class="stat-value"><?php echo (int) ($stats['approved_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic sent"><i class="bi bi-envelope"></i></div>
                                <div>
                                    <div class="stat-label">Sent</div>
                                    <div class="stat-value"><?php echo (int) ($stats['sent_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic accepted"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <div class="stat-label">Accepted</div>
                                    <div class="stat-value"><?php echo (int) ($stats['accepted_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic rejected"><i class="bi bi-x-circle"></i></div>
                                <div>
                                    <div class="stat-label">Rejected</div>
                                    <div class="stat-value"><?php echo (int) ($stats['rejected_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic pending"><i class="bi bi-graph-up"></i></div>
                                <div>
                                    <div class="stat-label">New This Week</div>
                                    <div class="stat-value"><?php echo (int) ($stats['new_this_week'] ?? 0); ?></div>
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
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="sent" <?php echo $status_filter === 'sent' ? 'selected' : ''; ?>>Sent to Candidate</option>
                                    <option value="accepted" <?php echo $status_filter === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Department</label>
                                <select name="department" class="form-select form-select-sm">
                                    <option value="">All Departments</option>
                                    <?php while ($dept = mysqli_fetch_assoc($dept_result)): ?>
                                        <option value="<?php echo e($dept['department']); ?>" <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                                            <?php echo e($dept['department']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo e($date_from); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo e($date_to); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control form-control-sm"
                                    placeholder="Candidate, Offer No, Position..." value="<?php echo e($search); ?>">
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="submit" class="btn-success-custom">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="offer-approval.php" class="btn-reset">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Bulk Actions (visible only for pending offers) -->
                    <?php if ($status_filter === 'pending' && mysqli_num_rows($offers) > 0): ?>
                        <div class="bulk-actions">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                            </div>
                            <span class="fw-bold" id="selectedCount">0 selected</span>
                            <div class="ms-auto d-flex gap-2">
                                <button class="btn-success-custom btn-sm" id="bulkApproveBtn" disabled onclick="openBulkApproveModal()">
                                    <i class="bi bi-check-lg"></i> Approve Selected
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Offers Table -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">
                                <?php if ($status_filter === 'pending'): ?>
                                    <i class="bi bi-clock-history"></i> Pending Approval
                                <?php elseif ($status_filter === 'approved'): ?>
                                    <i class="bi bi-check-circle"></i> Approved Offers
                                <?php elseif ($status_filter === 'rejected'): ?>
                                    <i class="bi bi-x-circle"></i> Rejected Offers
                                <?php else: ?>
                                    <i class="bi bi-file-text"></i> All Offers
                                <?php endif; ?>
                            </h3>
                            <span class="badge bg-secondary"><?php echo mysqli_num_rows($offers); ?> offers</span>
                        </div>

                        <div class="table-responsive">
                            <table id="offersTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <?php if ($status_filter === 'pending'): ?>
                                            <th class="select-col">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="selectAllHeader">
                                                </div>
                                            </th>
                                        <?php endif; ?>
                                        <th>Offer Details</th>
                                        <th>Candidate</th>
                                        <th>Position</th>
                                        <th>CTC</th>
                                        <th>Requested By</th>
                                        <th>Status</th>
                                        <th class="text-end actions-col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($offers) === 0): ?>
                                       
                                    <?php else: ?>
                                        <?php while ($offer = mysqli_fetch_assoc($offers)): ?>
                                            <tr>
                                                <?php if ($status_filter === 'pending' && $offer['status'] === 'Draft'): ?>
                                                    <td class="select-col">
                                                        <div class="form-check">
                                                            <input class="form-check-input offer-select" type="checkbox" name="offer_ids[]" value="<?php echo $offer['id']; ?>">
                                                        </div>
                                                    </td>
                                                <?php elseif ($status_filter === 'pending'): ?>
                                                    <td class="select-col">
                                                        <span class="text-muted">—</span>
                                                    </td>
                                                <?php endif; ?>

                                                <td>
                                                    <div class="offer-no"><?php echo e($offer['offer_no']); ?></div>
                                                    <div class="offer-date">
                                                        <i class="bi bi-calendar"></i> <?php echo formatDate($offer['offer_date']); ?>
                                                    </div>
                                                    <?php if (!empty($offer['offer_valid_till'])): ?>
                                                        <div class="offer-date">
                                                            <i class="bi bi-hourglass"></i> Valid till: <?php echo formatDate($offer['offer_valid_till']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="candidate-avatar">
                                                            <?php if (!empty($offer['candidate_photo'])): ?>
                                                                <img src="../<?php echo e($offer['candidate_photo']); ?>" alt="Photo">
                                                            <?php else: ?>
                                                                <?php echo getInitials($offer['candidate_name']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            <div class="candidate-name">
                                                                <a href="view-candidate.php?id=<?php echo $offer['candidate_id']; ?>" class="text-decoration-none text-dark">
                                                                    <?php echo e($offer['candidate_name']); ?>
                                                                </a>
                                                            </div>
                                                            <div class="candidate-code">
                                                                <i class="bi bi-hash"></i> <?php echo e($offer['candidate_code']); ?>
                                                            </div>
                                                            <div class="candidate-code">
                                                                <i class="bi bi-briefcase"></i> <?php echo e($offer['current_company'] ?: '—'); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div class="position-info">
                                                        <div class="position-text">
                                                            <i class="bi bi-briefcase"></i>
                                                            <?php echo e($offer['position_title']); ?>
                                                        </div>
                                                        <?php if (!empty($offer['department'])): ?>
                                                            <div class="department-badge">
                                                                <i class="bi bi-building"></i> <?php echo e($offer['department']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div class="offer-amount"><?php echo formatCurrency($offer['ctc']); ?></div>
                                                    <?php if ($offer['expected_ctc']): ?>
                                                        <div class="offer-date">
                                                            Expected: <?php echo formatCurrency($offer['expected_ctc']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div>
                                                        <div class="requester-name"><?php echo e($offer['requester_name'] ?: '—'); ?></div>
                                                        <div class="requester-designation"><?php echo e($offer['requester_designation'] ?: ''); ?></div>
                                                    </div>
                                                </td>

                                                <td>
                                                    <?php echo getOfferStatusBadge($offer['status']); ?>
                                                    
                                                    <?php if ($offer['status'] === 'Approved' && !empty($offer['approved_at'])): ?>
                                                        <div class="approval-timeline mt-1">
                                                            <i class="bi bi-check-circle text-success"></i>
                                                            <?php echo date('d M', strtotime($offer['approved_at'])); ?>
                                                        </div>
                                                    <?php elseif ($offer['status'] === 'Rejected' && !empty($offer['rejection_reason'])): ?>
                                                        <div class="approval-timeline mt-1 text-danger" title="<?php echo e($offer['rejection_reason']); ?>">
                                                            <i class="bi bi-exclamation-circle"></i> Rejected
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="text-end actions-col">
                                                    <a href="view-offer.php?id=<?php echo $offer['id']; ?>" class="btn-action view" title="View Offer">
                                                        <i class="bi bi-eye"></i>
                                                    </a>

                                                    <?php if ($offer['status'] === 'Draft'): ?>
                                                        <button class="btn-action approve" onclick="openApproveModal(<?php echo $offer['id']; ?>, '<?php echo e($offer['candidate_name']); ?>')" title="Approve">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                        <button class="btn-action reject" onclick="openRejectModal(<?php echo $offer['id']; ?>, '<?php echo e($offer['candidate_name']); ?>')" title="Reject">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($offer['status'] === 'Approved'): ?>
                                                        <span class="text-muted small">Ready to send</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Summary Section -->
                    <?php if ($stats['avg_offer_ctc'] > 0): ?>
                        <div class="summary-card mt-4">
                            <div class="row">
                                <div class="col-md-4 summary-stat">
                                    <div class="summary-stat-value"><?php echo formatCurrency($stats['avg_offer_ctc']); ?></div>
                                    <div class="summary-stat-label">Average Offer CTC</div>
                                </div>
                                <div class="col-md-4 summary-stat">
                                    <div class="summary-stat-value"><?php echo (int) ($stats['pending_count'] ?? 0); ?></div>
                                    <div class="summary-stat-label">Pending Approval</div>
                                </div>
                                <div class="col-md-4 summary-stat">
                                    <div class="summary-stat-value"><?php echo (int) ($stats['approved_count'] ?? 0); ?></div>
                                    <div class="summary-stat-label">Approved This Month</div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Approve Single Offer Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="approve_offer">
                    <input type="hidden" name="offer_id" id="approve_offer_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Approve Offer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to approve the offer for <strong id="approve_candidate_name"></strong>?</p>
                        
                        <p class="text-info small">
                            <i class="bi bi-info-circle"></i> 
                            Upon approval, the candidate status will be updated to 'Offered' and the offer will be ready to send.
                        </p>

                        <div class="mb-3">
                            <label class="form-label">Remarks (Optional)</label>
                            <textarea name="remarks" class="form-control" rows="2" placeholder="Add any approval notes..."></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Approve Offer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject Single Offer Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="reject_offer">
                    <input type="hidden" name="offer_id" id="reject_offer_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Reject Offer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to reject the offer for <strong id="reject_candidate_name"></strong>?</p>

                        <div class="mb-3">
                            <label class="form-label required">Reason for Rejection</label>
                            <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-danger-custom">Reject Offer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Approve Modal -->
    <div class="modal fade" id="bulkApproveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_approve">
                    <div id="bulkOfferIds"></div>

                    <div class="modal-header">
                        <h5 class="modal-title">Bulk Approve Offers</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to approve <strong id="bulkCount"></strong> selected offer(s)?</p>

                        <div class="mb-3">
                            <label class="form-label">Common Remarks (Optional)</label>
                            <textarea name="bulk_remarks" class="form-control" rows="2" placeholder="Add notes for all selected offers..."></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Approve Selected</button>
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
            var table = $('#offersTable').DataTable({
                responsive: true,
                autoWidth: false,
                scrollX: false,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                order: [[1, 'desc']],
                language: {
                    zeroRecords: "No matching offers found",
                    info: "Showing _START_ to _END_ of _TOTAL_ offers",
                    infoEmpty: "No offers to show",
                    lengthMenu: "Show _MENU_",
                    search: "Search:"
                },
                columnDefs: [
                    <?php if ($status_filter === 'pending'): ?>
                    { orderable: false, targets: [0, 7] },
                    { searchable: false, targets: [0] }
                    <?php else: ?>
                    { orderable: false, targets: [6] }
                    <?php endif; ?>
                ]
            });

            // Handle Select All checkbox
            $('#selectAll, #selectAllHeader').change(function() {
                var isChecked = $(this).prop('checked');
                $('.offer-select').prop('checked', isChecked);
                updateSelectedCount();
            });

            // Handle individual checkboxes
            $(document).on('change', '.offer-select', function() {
                updateSelectedCount();
                
                var allChecked = $('.offer-select:checked').length === $('.offer-select').length;
                $('#selectAll, #selectAllHeader').prop('checked', allChecked);
            });

            function updateSelectedCount() {
                var count = $('.offer-select:checked').length;
                $('#selectedCount').text(count + ' selected');
                
                if (count > 0) {
                    $('#bulkApproveBtn').prop('disabled', false);
                } else {
                    $('#bulkApproveBtn').prop('disabled', true);
                }
            }

            // Auto-focus search
            setTimeout(function() {
                $('.dataTables_filter input').focus();
            }, 400);
        });

        function openApproveModal(id, candidateName) {
            $('#approve_offer_id').val(id);
            $('#approve_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('approveModal')).show();
        }

        function openRejectModal(id, candidateName) {
            $('#reject_offer_id').val(id);
            $('#reject_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }

        function openBulkApproveModal() {
            var selectedIds = [];
            $('.offer-select:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (selectedIds.length === 0) {
                alert('Please select at least one offer to approve.');
                return;
            }

            // Create hidden inputs for selected IDs
            var html = '';
            selectedIds.forEach(function(id) {
                html += '<input type="hidden" name="offer_ids[]" value="' + id + '">';
            });
            $('#bulkOfferIds').html(html);
            
            $('#bulkCount').text(selectedIds.length);
            new bootstrap.Modal(document.getElementById('bulkApproveModal')).show();
        }

        // Export to Excel function
        function exportToExcel() {
            window.location.href = 'export-offers.php?' + window.location.search.substring(1);
        }
    </script>
</body>

</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>