<?php
// hr/offers.php - Offer Management Page
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

// ---------------- HANDLE OFFER ACTIONS ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Create New Offer
    if (isset($_POST['action']) && $_POST['action'] === 'create_offer') {
        $candidate_id = (int)$_POST['candidate_id'];
        $hiring_request_id = (int)$_POST['hiring_request_id'];
        $offer_date = mysqli_real_escape_string($conn, $_POST['offer_date']);
        $offer_valid_till = mysqli_real_escape_string($conn, $_POST['offer_valid_till']);
        $expected_joining_date = mysqli_real_escape_string($conn, $_POST['expected_joining_date']);
        $designation = mysqli_real_escape_string($conn, $_POST['designation']);
        $department = mysqli_real_escape_string($conn, $_POST['department']);
        $employment_type = mysqli_real_escape_string($conn, $_POST['employment_type']);
        $ctc = (float)$_POST['ctc'];
        $basic_salary = !empty($_POST['basic_salary']) ? (float)$_POST['basic_salary'] : null;
        $hra = !empty($_POST['hra']) ? (float)$_POST['hra'] : null;
        $conveyance = !empty($_POST['conveyance']) ? (float)$_POST['conveyance'] : null;
        $medical = !empty($_POST['medical']) ? (float)$_POST['medical'] : null;
        $special_allowance = !empty($_POST['special_allowance']) ? (float)$_POST['special_allowance'] : null;
        $bonus = !empty($_POST['bonus']) ? (float)$_POST['bonus'] : null;
        $other_benefits = mysqli_real_escape_string($conn, $_POST['other_benefits'] ?? '');
        $terms_conditions = mysqli_real_escape_string($conn, $_POST['terms_conditions'] ?? '');
        
        // Generate offer number
        $year = date('Y');
        $month = date('m');
        
        $seq_query = "SELECT COUNT(*) as count FROM offers WHERE offer_no LIKE 'OFF-{$year}{$month}%'";
        $seq_result = mysqli_query($conn, $seq_query);
        $seq_row = mysqli_fetch_assoc($seq_result);
        $seq_num = str_pad($seq_row['count'] + 1, 4, '0', STR_PAD_LEFT);
        $offer_no = "OFF-{$year}{$month}-{$seq_num}";
        
        // Handle offer document upload
        $offer_document = null;
        if (isset($_FILES['offer_document']) && $_FILES['offer_document']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/offers/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_ext = pathinfo($_FILES['offer_document']['name'], PATHINFO_EXTENSION);
            $file_name = 'offer_' . time() . '_' . uniqid() . '.' . $file_ext;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['offer_document']['tmp_name'], $file_path)) {
                $offer_document = 'uploads/offers/' . $file_name;
            }
        }
        
        // Insert offer
        $insert_stmt = mysqli_prepare($conn, "
            INSERT INTO offers (
                offer_no, candidate_id, hiring_request_id, offer_date, offer_valid_till,
                expected_joining_date, designation, department, employment_type, ctc,
                basic_salary, hra, conveyance, medical, special_allowance, bonus,
                other_benefits, terms_conditions, offer_document, status,
                created_by, created_by_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?, ?)
        ");
        
        mysqli_stmt_bind_param(
            $insert_stmt,
            "siissssssddddddddsssis",
            $offer_no,
            $candidate_id,
            $hiring_request_id,
            $offer_date,
            $offer_valid_till,
            $expected_joining_date,
            $designation,
            $department,
            $employment_type,
            $ctc,
            $basic_salary,
            $hra,
            $conveyance,
            $medical,
            $special_allowance,
            $bonus,
            $other_benefits,
            $terms_conditions,
            $offer_document,
            $current_employee_id,
            $current_employee['full_name']
        );
        
        if (mysqli_stmt_execute($insert_stmt)) {
            $offer_id = mysqli_insert_id($conn);
            
            // Update candidate status
            mysqli_query($conn, "UPDATE candidates SET status = 'Offered' WHERE id = {$candidate_id}");
            
            logActivity(
                $conn,
                'CREATE',
                'offer',
                "Created new offer: {$offer_no}",
                $offer_id,
                null,
                null,
                json_encode($_POST)
            );
            
            $message = "Offer created successfully! Offer Number: {$offer_no}";
            $messageType = "success";
        } else {
            $message = "Error creating offer: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Update Offer
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_offer') {
        $offer_id = (int)$_POST['offer_id'];
        $offer_date = mysqli_real_escape_string($conn, $_POST['offer_date']);
        $offer_valid_till = mysqli_real_escape_string($conn, $_POST['offer_valid_till']);
        $expected_joining_date = mysqli_real_escape_string($conn, $_POST['expected_joining_date']);
        $designation = mysqli_real_escape_string($conn, $_POST['designation']);
        $employment_type = mysqli_real_escape_string($conn, $_POST['employment_type']);
        $ctc = (float)$_POST['ctc'];
        $basic_salary = !empty($_POST['basic_salary']) ? (float)$_POST['basic_salary'] : null;
        $hra = !empty($_POST['hra']) ? (float)$_POST['hra'] : null;
        $conveyance = !empty($_POST['conveyance']) ? (float)$_POST['conveyance'] : null;
        $medical = !empty($_POST['medical']) ? (float)$_POST['medical'] : null;
        $special_allowance = !empty($_POST['special_allowance']) ? (float)$_POST['special_allowance'] : null;
        $bonus = !empty($_POST['bonus']) ? (float)$_POST['bonus'] : null;
        $other_benefits = mysqli_real_escape_string($conn, $_POST['other_benefits'] ?? '');
        $terms_conditions = mysqli_real_escape_string($conn, $_POST['terms_conditions'] ?? '');
        
        $update_stmt = mysqli_prepare($conn, "
            UPDATE offers SET
                offer_date = ?,
                offer_valid_till = ?,
                expected_joining_date = ?,
                designation = ?,
                employment_type = ?,
                ctc = ?,
                basic_salary = ?,
                hra = ?,
                conveyance = ?,
                medical = ?,
                special_allowance = ?,
                bonus = ?,
                other_benefits = ?,
                terms_conditions = ?
            WHERE id = ? AND status = 'Draft'
        ");
        
        mysqli_stmt_bind_param(
            $update_stmt,
            "sssssddddddddssi",
            $offer_date,
            $offer_valid_till,
            $expected_joining_date,
            $designation,
            $employment_type,
            $ctc,
            $basic_salary,
            $hra,
            $conveyance,
            $medical,
            $special_allowance,
            $bonus,
            $other_benefits,
            $terms_conditions,
            $offer_id
        );
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'offer',
                "Updated offer ID: {$offer_id}",
                $offer_id,
                null,
                null,
                json_encode($_POST)
            );
            
            $message = "Offer updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating offer: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Send Offer to Candidate
    elseif (isset($_POST['action']) && $_POST['action'] === 'send_offer') {
        $offer_id = (int)$_POST['offer_id'];
        
        $update_stmt = mysqli_prepare($conn, "
            UPDATE offers 
            SET status = 'Sent',
                sent_date = CURDATE(),
                sent_by = ?,
                sent_by_name = ?
            WHERE id = ? AND status = 'Approved'
        ");
        
        mysqli_stmt_bind_param($update_stmt, "isi", $current_employee_id, $current_employee['full_name'], $offer_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'offer',
                "Sent offer to candidate",
                $offer_id,
                null,
                null,
                null
            );
            
            $message = "Offer sent to candidate successfully!";
            $messageType = "success";
        } else {
            $message = "Error sending offer: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Mark Offer as Accepted
    elseif (isset($_POST['action']) && $_POST['action'] === 'accept_offer') {
        $offer_id = (int)$_POST['offer_id'];
        $response_remarks = mysqli_real_escape_string($conn, $_POST['response_remarks'] ?? '');
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Update offer status
            $update_stmt = mysqli_prepare($conn, "
                UPDATE offers 
                SET status = 'Accepted',
                    response_date = CURDATE(),
                    response_remarks = ?,
                    accepted_by_candidate = 1
                WHERE id = ? AND status = 'Sent'
            ");
            
            mysqli_stmt_bind_param($update_stmt, "si", $response_remarks, $offer_id);
            mysqli_stmt_execute($update_stmt);
            
            // Get candidate_id
            $cand_query = "SELECT candidate_id FROM offers WHERE id = ?";
            $cand_stmt = mysqli_prepare($conn, $cand_query);
            mysqli_stmt_bind_param($cand_stmt, "i", $offer_id);
            mysqli_stmt_execute($cand_stmt);
            $cand_res = mysqli_stmt_get_result($cand_stmt);
            $cand_data = mysqli_fetch_assoc($cand_res);
            
            if ($cand_data) {
                // Update candidate status
                mysqli_query($conn, "UPDATE candidates SET status = 'Accepted' WHERE id = {$cand_data['candidate_id']}");
            }
            
            mysqli_commit($conn);
            
            logActivity(
                $conn,
                'UPDATE',
                'offer',
                "Offer accepted by candidate",
                $offer_id,
                null,
                null,
                json_encode(['remarks' => $response_remarks])
            );
            
            $message = "Offer marked as accepted!";
            $messageType = "success";
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Mark Offer as Declined
    elseif (isset($_POST['action']) && $_POST['action'] === 'decline_offer') {
        $offer_id = (int)$_POST['offer_id'];
        $rejection_reason = mysqli_real_escape_string($conn, $_POST['rejection_reason'] ?? '');
        
        if (empty($rejection_reason)) {
            $message = "Rejection reason is required.";
            $messageType = "danger";
        } else {
            $update_stmt = mysqli_prepare($conn, "
                UPDATE offers 
                SET status = 'Rejected',
                    response_date = CURDATE(),
                    rejection_reason = ?
                WHERE id = ? AND status = 'Sent'
            ");
            
            mysqli_stmt_bind_param($update_stmt, "si", $rejection_reason, $offer_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                logActivity(
                    $conn,
                    'UPDATE',
                    'offer',
                    "Offer declined by candidate",
                    $offer_id,
                    null,
                    null,
                    json_encode(['reason' => $rejection_reason])
                );
                
                $message = "Offer marked as declined.";
                $messageType = "success";
            } else {
                $message = "Error updating offer: " . mysqli_error($conn);
                $messageType = "danger";
            }
        }
    }
    
    // Withdraw Offer
    elseif (isset($_POST['action']) && $_POST['action'] === 'withdraw_offer') {
        $offer_id = (int)$_POST['offer_id'];
        $withdraw_reason = mysqli_real_escape_string($conn, $_POST['withdraw_reason'] ?? '');
        
        $update_stmt = mysqli_prepare($conn, "
            UPDATE offers 
            SET status = 'Withdrawn',
                rejection_reason = ?
            WHERE id = ? AND status IN ('Draft', 'Approved', 'Sent')
        ");
        
        mysqli_stmt_bind_param($update_stmt, "si", $withdraw_reason, $offer_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'offer',
                "Offer withdrawn",
                $offer_id,
                null,
                null,
                json_encode(['reason' => $withdraw_reason])
            );
            
            $message = "Offer withdrawn successfully.";
            $messageType = "success";
        } else {
            $message = "Error withdrawing offer: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Delete Offer (soft delete)
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_offer') {
        $offer_id = (int)$_POST['offer_id'];
        
        // Instead of deleting, we can mark as withdrawn or just log
        $update_stmt = mysqli_prepare($conn, "UPDATE offers SET status = 'Withdrawn' WHERE id = ? AND status = 'Draft'");
        mysqli_stmt_bind_param($update_stmt, "i", $offer_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'SOFT_DELETE',
                'offer',
                "Deleted (withdrawn) offer ID: {$offer_id}",
                $offer_id,
                null,
                null,
                null
            );
            
            $message = "Offer deleted successfully.";
            $messageType = "success";
        } else {
            $message = "Error deleting offer: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'all';
$candidate_filter = isset($_GET['candidate_id']) ? (int)$_GET['candidate_id'] : 0;
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query
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
        c.current_ctc as candidate_current_ctc,
        c.expected_ctc as candidate_expected_ctc,
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
        req_emp.designation as requester_designation,
        approver.full_name as approver_full_name,
        sender.full_name as sender_full_name,
        ob.id as onboarding_id,
        ob.status as onboarding_status,
        ob.employee_code
    FROM offers o
    JOIN candidates c ON o.candidate_id = c.id
    JOIN hiring_requests h ON o.hiring_request_id = h.id
    LEFT JOIN employees req_emp ON h.requested_by = req_emp.id
    LEFT JOIN employees approver ON o.approved_by = approver.id
    LEFT JOIN employees sender ON o.sent_by = sender.id
    LEFT JOIN onboarding ob ON ob.candidate_id = c.id
    WHERE 1=1
";

// Filter by status
if ($status_filter !== 'all') {
    $status_filter = mysqli_real_escape_string($conn, $status_filter);
    $query .= " AND o.status = '{$status_filter}'";
}

// Filter by candidate
if ($candidate_filter > 0) {
    $query .= " AND o.candidate_id = {$candidate_filter}";
}

// Filter by date range
if (!empty($date_from)) {
    $query .= " AND DATE(o.offer_date) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}
if (!empty($date_to)) {
    $query .= " AND DATE(o.offer_date) <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
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

// Managers see only offers from their requests
if (!$isHr && !$isAdmin && $isManager) {
    $query .= " AND h.requested_by = {$current_employee_id}";
}

$query .= " ORDER BY o.created_at DESC";

$offers = mysqli_query($conn, $query);

// Get selected candidates for dropdown (for creating new offer)
$candidates_query = "
    SELECT c.id, c.first_name, c.last_name, c.candidate_code, c.status,
           h.id as hiring_id, h.position_title, h.department, h.designation
    FROM candidates c
    JOIN hiring_requests h ON c.hiring_request_id = h.id
    WHERE c.status IN ('Selected', 'Interviewed')
    ORDER BY c.created_at DESC
";

if (!$isHr && !$isAdmin && $isManager) {
    $candidates_query .= " AND h.requested_by = {$current_employee_id}";
}

$candidates_result = mysqli_query($conn, $candidates_query);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_count,
        COUNT(CASE WHEN status = 'Draft' THEN 1 END) as draft_count,
        COUNT(CASE WHEN status = 'Approved' THEN 1 END) as approved_count,
        COUNT(CASE WHEN status = 'Rejected' THEN 1 END) as rejected_count,
        COUNT(CASE WHEN status = 'Sent' THEN 1 END) as sent_count,
        COUNT(CASE WHEN status = 'Accepted' THEN 1 END) as accepted_count,
        COUNT(CASE WHEN status = 'Expired' THEN 1 END) as expired_count,
        COUNT(CASE WHEN status = 'Withdrawn' THEN 1 END) as withdrawn_count,
        AVG(CASE WHEN status IN ('Approved', 'Sent', 'Accepted') THEN ctc END) as avg_ctc,
        SUM(CASE WHEN status = 'Accepted' THEN 1 ELSE 0 END) as hired_count
    FROM offers o
";

if (!$isHr && !$isAdmin && $isManager) {
    $stats_query .= " WHERE o.hiring_request_id IN (SELECT id FROM hiring_requests WHERE requested_by = {$current_employee_id})";
}

$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

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
    <title>Offer Management - TEK-C Hiring</title>
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

        .stat-ic.draft {
            background: #6b7280;
        }

        .stat-ic.approved {
            background: #10b981;
        }

        .stat-ic.sent {
            background: #3b82f6;
        }

        .stat-ic.accepted {
            background: #8b5cf6;
        }

        .stat-ic.rejected {
            background: #ef4444;
        }

        .stat-ic.total {
            background: var(--blue);
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

        .btn-filter:hover {
            background: #0da271;
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

        .btn-action.send:hover {
            background: #dbeafe;
            color: #1e40af;
            border-color: #1e40af;
        }

        .btn-action.accept:hover {
            background: #d1fae5;
            color: #065f46;
            border-color: #065f46;
        }

        .btn-action.decline:hover {
            background: #fee2e2;
            color: #991b1b;
            border-color: #991b1b;
        }

        .btn-action.withdraw:hover {
            background: #fef3c7;
            color: #92400e;
            border-color: #92400e;
        }

        /* Timeline */
        .offer-timeline {
            font-size: 11px;
            color: #6b7280;
            margin-top: 5px;
        }

        .offer-timeline i {
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
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            border-radius: var(--radius);
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }

        .summary-stat {
            text-align: center;
        }

        .summary-stat-value {
            font-size: 28px;
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
            width: 150px !important;
            white-space: nowrap !important;
        }

        /* Salary Breakdown */
        .salary-breakdown {
            font-size: 10px;
            color: #6b7280;
            margin-top: 3px;
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
                            <h1 class="h3 fw-bold text-dark mb-1">Offer Management</h1>
                            <p class="text-muted mb-0">Create and manage employment offers</p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($isHr || $isAdmin): ?>
                                <button class="btn-primary-custom" onclick="openCreateModal()">
                                    <i class="bi bi-plus-lg"></i> Create New Offer
                                </button>
                            <?php endif; ?>
                            <a href="offer-approval.php" class="btn-success-custom">
                                <i class="bi bi-check-circle"></i> Approval Queue
                            </a>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic total"><i class="bi bi-files"></i></div>
                                <div>
                                    <div class="stat-label">Total Offers</div>
                                    <div class="stat-value"><?php echo (int) ($stats['total_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic draft"><i class="bi bi-pencil"></i></div>
                                <div>
                                    <div class="stat-label">Draft</div>
                                    <div class="stat-value"><?php echo (int) ($stats['draft_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic approved"><i class="bi bi-check-circle"></i></div>
                                <div>
                                    <div class="stat-label">Approved</div>
                                    <div class="stat-value"><?php echo (int) ($stats['approved_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic sent"><i class="bi bi-envelope"></i></div>
                                <div>
                                    <div class="stat-label">Sent</div>
                                    <div class="stat-value"><?php echo (int) ($stats['sent_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic accepted"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <div class="stat-label">Accepted</div>
                                    <div class="stat-value"><?php echo (int) ($stats['accepted_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic rejected"><i class="bi bi-x-circle"></i></div>
                                <div>
                                    <div class="stat-label">Rejected</div>
                                    <div class="stat-value"><?php echo (int) ($stats['rejected_count'] ?? 0); ?></div>
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
                                    <option value="Draft" <?php echo $status_filter === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="Sent" <?php echo $status_filter === 'Sent' ? 'selected' : ''; ?>>Sent</option>
                                    <option value="Accepted" <?php echo $status_filter === 'Accepted' ? 'selected' : ''; ?>>Accepted</option>
                                    <option value="Expired" <?php echo $status_filter === 'Expired' ? 'selected' : ''; ?>>Expired</option>
                                    <option value="Withdrawn" <?php echo $status_filter === 'Withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
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

                            <div class="col-md-6">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control form-control-sm"
                                    placeholder="Candidate, Offer No, Position..." value="<?php echo e($search); ?>">
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="submit" class="btn-filter">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="offers.php" class="btn-reset">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Summary Card (if stats available) -->
                    <?php if (($stats['avg_ctc'] ?? 0) > 0 || ($stats['hired_count'] ?? 0) > 0): ?>
                        <div class="summary-card mb-4">
                            <div class="row">
                                <div class="col-md-4 summary-stat">
                                    <div class="summary-stat-value"><?php echo formatCurrency($stats['avg_ctc'] ?? 0); ?></div>
                                    <div class="summary-stat-label">Average Offer CTC</div>
                                </div>
                                <div class="col-md-4 summary-stat">
                                    <div class="summary-stat-value"><?php echo (int) ($stats['hired_count'] ?? 0); ?></div>
                                    <div class="summary-stat-label">Candidates Hired</div>
                                </div>
                                <div class="col-md-4 summary-stat">
                                    <div class="summary-stat-value"><?php echo (int) ($stats['accepted_count'] ?? 0); ?></div>
                                    <div class="summary-stat-label">Accepted Offers</div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Offers Table -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">
                                <i class="bi bi-file-text"></i> All Offers
                            </h3>
                            <span class="badge bg-secondary"><?php echo mysqli_num_rows($offers); ?> offers</span>
                        </div>

                        <div class="table-responsive">
                            <table id="offersTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Offer Details</th>
                                        <th>Candidate</th>
                                        <th>Position</th>
                                        <th>CTC</th>
                                        <th>Status</th>
                                        <th>Timeline</th>
                                        <th class="text-end actions-col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($offers) === 0): ?>
                                        
                                    <?php else: ?>
                                        <?php while ($offer = mysqli_fetch_assoc($offers)): ?>
                                            <tr>
                                                <td>
                                                    <div class="offer-no"><?php echo e($offer['offer_no']); ?></div>
                                                    <div class="offer-date">
                                                        <i class="bi bi-calendar"></i> Created: <?php echo formatDate($offer['offer_date']); ?>
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
                                                    <?php if ($offer['candidate_expected_ctc']): ?>
                                                        <div class="salary-breakdown">
                                                            Expected: <?php echo formatCurrency($offer['candidate_expected_ctc']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php echo getOfferStatusBadge($offer['status']); ?>
                                                    
                                                    <?php if ($offer['status'] === 'Accepted' && !empty($offer['onboarding_id'])): ?>
                                                        <div class="offer-timeline">
                                                            <i class="bi bi-person-check text-success"></i>
                                                            Onboarding: <?php echo e($offer['onboarding_status']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <div class="offer-timeline">
                                                        <?php if ($offer['status'] === 'Approved' && !empty($offer['approved_at'])): ?>
                                                            <i class="bi bi-check-circle text-success"></i>
                                                            Approved: <?php echo date('d M', strtotime($offer['approved_at'])); ?>
                                                        <?php elseif ($offer['status'] === 'Sent' && !empty($offer['sent_date'])): ?>
                                                            <i class="bi bi-envelope text-info"></i>
                                                            Sent: <?php echo date('d M', strtotime($offer['sent_date'])); ?>
                                                        <?php elseif ($offer['status'] === 'Accepted' && !empty($offer['response_date'])): ?>
                                                            <i class="bi bi-check2-circle text-success"></i>
                                                            Accepted: <?php echo date('d M', strtotime($offer['response_date'])); ?>
                                                        <?php elseif ($offer['status'] === 'Rejected' && !empty($offer['response_date'])): ?>
                                                            <i class="bi bi-x-circle text-danger"></i>
                                                            Declined: <?php echo date('d M', strtotime($offer['response_date'])); ?>
                                                        <?php else: ?>
                                                            <i class="bi bi-clock"></i>
                                                            Created: <?php echo date('d M', strtotime($offer['created_at'])); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <td class="text-end actions-col">
                                                    <a href="view-offer.php?id=<?php echo $offer['id']; ?>" class="btn-action view" title="View Offer">
                                                        <i class="bi bi-eye"></i>
                                                    </a>

                                                    <?php if ($offer['status'] === 'Draft'): ?>
                                                        <?php if ($isHr || $isAdmin): ?>
                                                            <button class="btn-action approve" onclick="openEditModal(<?php echo e(json_encode($offer)); ?>)" title="Edit">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <a href="offer-approval.php?status=pending" class="btn-action send" title="Send for Approval">
                                                                <i class="bi bi-send"></i>
                                                            </a>
                                                            <button class="btn-action withdraw" onclick="openWithdrawModal(<?php echo $offer['id']; ?>, '<?php echo e($offer['candidate_name']); ?>')" title="Withdraw">
                                                                <i class="bi bi-dash-circle"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php elseif ($offer['status'] === 'Approved'): ?>
                                                        <button class="btn-action send" onclick="openSendModal(<?php echo $offer['id']; ?>, '<?php echo e($offer['candidate_name']); ?>')" title="Send to Candidate">
                                                            <i class="bi bi-envelope"></i>
                                                        </button>
                                                    <?php elseif ($offer['status'] === 'Sent'): ?>
                                                        <button class="btn-action accept" onclick="openAcceptModal(<?php echo $offer['id']; ?>, '<?php echo e($offer['candidate_name']); ?>')" title="Mark as Accepted">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                        <button class="btn-action decline" onclick="openDeclineModal(<?php echo $offer['id']; ?>, '<?php echo e($offer['candidate_name']); ?>')" title="Mark as Declined">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if (!empty($offer['onboarding_id'])): ?>
                                                        <a href="view-onboarding.php?id=<?php echo $offer['onboarding_id']; ?>" class="btn-action view" title="View Onboarding">
                                                            <i class="bi bi-person-check"></i>
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

    <!-- Create Offer Modal -->
    <div class="modal fade" id="createOfferModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_offer">

                    <div class="modal-header">
                        <h5 class="modal-title">Create New Offer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Candidate Selection -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label required">Select Candidate</label>
                                <select name="candidate_id" class="form-select select2" id="candidate_select" required>
                                    <option value="">Choose candidate...</option>
                                    <?php 
                                    mysqli_data_seek($candidates_result, 0);
                                    while ($candidate = mysqli_fetch_assoc($candidates_result)): 
                                    ?>
                                        <option value="<?php echo $candidate['id']; ?>" 
                                                data-hiring-id="<?php echo $candidate['hiring_id']; ?>"
                                                data-position="<?php echo e($candidate['position_title']); ?>"
                                                data-department="<?php echo e($candidate['department']); ?>"
                                                data-designation="<?php echo e($candidate['designation']); ?>">
                                            <?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?> 
                                            (<?php echo e($candidate['candidate_code']); ?>) - <?php echo e($candidate['position_title']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <input type="hidden" name="hiring_request_id" id="hiring_request_id">
                            </div>
                        </div>

                        <!-- Offer Dates -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label required">Offer Date</label>
                                <input type="date" name="offer_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Valid Till</label>
                                <input type="date" name="offer_valid_till" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Expected Joining Date</label>
                                <input type="date" name="expected_joining_date" class="form-control" required>
                            </div>
                        </div>

                        <!-- Position Details (auto-filled) -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label required">Designation</label>
                                <input type="text" name="designation" class="form-control" id="designation" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Department</label>
                                <input type="text" name="department" class="form-control" id="department" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Employment Type</label>
                                <select name="employment_type" class="form-select" required>
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Intern">Intern</option>
                                </select>
                            </div>
                        </div>

                        <!-- CTC -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label required">Total CTC (in LPA)</label>
                                <input type="number" step="0.01" name="ctc" class="form-control" required>
                            </div>
                        </div>

                        <!-- Salary Breakdown -->
                        <h6 class="fw-bold mb-3">Salary Breakdown (Optional)</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Basic Salary</label>
                                <input type="number" step="0.01" name="basic_salary" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">HRA</label>
                                <input type="number" step="0.01" name="hra" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Conveyance</label>
                                <input type="number" step="0.01" name="conveyance" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Medical Allowance</label>
                                <input type="number" step="0.01" name="medical" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Special Allowance</label>
                                <input type="number" step="0.01" name="special_allowance" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bonus/Performance Pay</label>
                                <input type="number" step="0.01" name="bonus" class="form-control">
                            </div>
                        </div>

                        <!-- Other Benefits -->
                        <div class="mb-3">
                            <label class="form-label">Other Benefits</label>
                            <textarea name="other_benefits" class="form-control" rows="2" placeholder="e.g., Health insurance, Laptop, etc."></textarea>
                        </div>

                        <!-- Terms & Conditions -->
                        <div class="mb-3">
                            <label class="form-label">Terms & Conditions</label>
                            <textarea name="terms_conditions" class="form-control" rows="3"></textarea>
                        </div>

                        <!-- Offer Document -->
                        <div class="mb-3">
                            <label class="form-label">Offer Letter Document (PDF)</label>
                            <input type="file" name="offer_document" class="form-control" accept=".pdf,.doc,.docx">
                            <div class="form-text">Upload the official offer letter (optional)</div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Create Offer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Offer Modal -->
    <div class="modal fade" id="editOfferModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_offer">
                    <input type="hidden" name="offer_id" id="edit_offer_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Edit Offer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Offer Dates -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label required">Offer Date</label>
                                <input type="date" name="offer_date" id="edit_offer_date" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Valid Till</label>
                                <input type="date" name="offer_valid_till" id="edit_valid_till" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Expected Joining Date</label>
                                <input type="date" name="expected_joining_date" id="edit_joining_date" class="form-control" required>
                            </div>
                        </div>

                        <!-- Position Details -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label required">Designation</label>
                                <input type="text" name="designation" id="edit_designation" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Department</label>
                                <input type="text" name="department" id="edit_department" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Employment Type</label>
                                <select name="employment_type" id="edit_employment_type" class="form-select" required>
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Intern">Intern</option>
                                </select>
                            </div>
                        </div>

                        <!-- CTC -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label required">Total CTC (in LPA)</label>
                                <input type="number" step="0.01" name="ctc" id="edit_ctc" class="form-control" required>
                            </div>
                        </div>

                        <!-- Salary Breakdown -->
                        <h6 class="fw-bold mb-3">Salary Breakdown (Optional)</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Basic Salary</label>
                                <input type="number" step="0.01" name="basic_salary" id="edit_basic" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">HRA</label>
                                <input type="number" step="0.01" name="hra" id="edit_hra" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Conveyance</label>
                                <input type="number" step="0.01" name="conveyance" id="edit_conveyance" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Medical Allowance</label>
                                <input type="number" step="0.01" name="medical" id="edit_medical" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Special Allowance</label>
                                <input type="number" step="0.01" name="special_allowance" id="edit_special" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bonus/Performance Pay</label>
                                <input type="number" step="0.01" name="bonus" id="edit_bonus" class="form-control">
                            </div>
                        </div>

                        <!-- Other Benefits -->
                        <div class="mb-3">
                            <label class="form-label">Other Benefits</label>
                            <textarea name="other_benefits" id="edit_benefits" class="form-control" rows="2"></textarea>
                        </div>

                        <!-- Terms & Conditions -->
                        <div class="mb-3">
                            <label class="form-label">Terms & Conditions</label>
                            <textarea name="terms_conditions" id="edit_terms" class="form-control" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Update Offer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Send Offer Modal -->
    <div class="modal fade" id="sendModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="send_offer">
                    <input type="hidden" name="offer_id" id="send_offer_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Send Offer to Candidate</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to send this offer to <strong id="send_candidate_name"></strong>?</p>
                        <p class="text-info small">
                            <i class="bi bi-info-circle"></i> 
                            The offer will be marked as 'Sent' and the candidate will be notified.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Send Offer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Accept Offer Modal -->
    <div class="modal fade" id="acceptModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="accept_offer">
                    <input type="hidden" name="offer_id" id="accept_offer_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Accept Offer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Mark offer as accepted by <strong id="accept_candidate_name"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label">Response Remarks (Optional)</label>
                            <textarea name="response_remarks" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Accept Offer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Decline Offer Modal -->
    <div class="modal fade" id="declineModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="decline_offer">
                    <input type="hidden" name="offer_id" id="decline_offer_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Decline Offer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Mark offer as declined by <strong id="decline_candidate_name"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label required">Reason for Decline</label>
                            <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-danger-custom">Decline Offer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Withdraw Offer Modal -->
    <div class="modal fade" id="withdrawModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="withdraw_offer">
                    <input type="hidden" name="offer_id" id="withdraw_offer_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Withdraw Offer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to withdraw the offer for <strong id="withdraw_candidate_name"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label required">Reason for Withdrawal</label>
                            <textarea name="withdraw_reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-danger-custom">Withdraw Offer</button>
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#offersTable').DataTable({
                responsive: true,
                autoWidth: false,
                scrollX: false,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                order: [[0, 'desc']],
                language: {
                    zeroRecords: "No matching offers found",
                    info: "Showing _START_ to _END_ of _TOTAL_ offers",
                    infoEmpty: "No offers to show",
                    lengthMenu: "Show _MENU_",
                    search: "Search:"
                },
                columnDefs: [
                    { orderable: false, targets: [6] }
                ]
            });

            // Initialize Select2
            $('.select2').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#createOfferModal'),
                width: '100%'
            });

            // Handle candidate selection
            $('#candidate_select').on('change', function() {
                var selected = $(this).find(':selected');
                var hiringId = selected.data('hiring-id');
                var position = selected.data('position');
                var department = selected.data('department');
                var designation = selected.data('designation');
                
                $('#hiring_request_id').val(hiringId);
                $('#designation').val(designation || position);
                $('#department').val(department);
            });

            // Auto-focus search
            setTimeout(function() {
                $('.dataTables_filter input').focus();
            }, 400);
        });

        function openCreateModal() {
            new bootstrap.Modal(document.getElementById('createOfferModal')).show();
        }

        function openEditModal(offer) {
            $('#edit_offer_id').val(offer.id);
            $('#edit_offer_date').val(offer.offer_date);
            $('#edit_valid_till').val(offer.offer_valid_till);
            $('#edit_joining_date').val(offer.expected_joining_date);
            $('#edit_designation').val(offer.designation);
            $('#edit_department').val(offer.department);
            $('#edit_employment_type').val(offer.employment_type);
            $('#edit_ctc').val(offer.ctc);
            $('#edit_basic').val(offer.basic_salary || '');
            $('#edit_hra').val(offer.hra || '');
            $('#edit_conveyance').val(offer.conveyance || '');
            $('#edit_medical').val(offer.medical || '');
            $('#edit_special').val(offer.special_allowance || '');
            $('#edit_bonus').val(offer.bonus || '');
            $('#edit_benefits').val(offer.other_benefits || '');
            $('#edit_terms').val(offer.terms_conditions || '');
            
            new bootstrap.Modal(document.getElementById('editOfferModal')).show();
        }

        function openSendModal(id, candidateName) {
            $('#send_offer_id').val(id);
            $('#send_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('sendModal')).show();
        }

        function openAcceptModal(id, candidateName) {
            $('#accept_offer_id').val(id);
            $('#accept_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('acceptModal')).show();
        }

        function openDeclineModal(id, candidateName) {
            $('#decline_offer_id').val(id);
            $('#decline_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('declineModal')).show();
        }

        function openWithdrawModal(id, candidateName) {
            $('#withdraw_offer_id').val(id);
            $('#withdraw_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('withdrawModal')).show();
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