<?php
// salary-loan.php
// Employee salary loan/advance request management

session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int)$_SESSION['employee_id'];
$employeeName = (string)($_SESSION['employee_name'] ?? '');
$employeeDesignation = (string)($_SESSION['designation'] ?? '');

// Determine user role and permissions
$is_hr = in_array(strtolower(trim($employeeDesignation)), ['hr', 'human resources', 'hr manager'], true);
$is_director = in_array(strtolower(trim($employeeDesignation)), ['director', 'vice president', 'general manager'], true);
$is_manager = in_array(strtolower(trim($employeeDesignation)), ['manager', 'team lead', 'project manager'], true);
$has_full_access = $is_hr || $is_director; // HR and Director have full access

// Get selected employee for HR/Director view
$selected_employee_id = $employeeId;
$selected_employee_name = $employeeName;

if ($has_full_access && isset($_GET['employee_id']) && $_GET['employee_id'] > 0) {
    $selected_employee_id = (int)$_GET['employee_id'];
    $emp_stmt = mysqli_prepare($conn, "SELECT full_name, employee_code FROM employees WHERE id = ?");
    mysqli_stmt_bind_param($emp_stmt, "i", $selected_employee_id);
    mysqli_stmt_execute($emp_stmt);
    $emp_res = mysqli_stmt_get_result($emp_stmt);
    $selected_emp = mysqli_fetch_assoc($emp_res);
    if ($selected_emp) {
        $selected_employee_name = $selected_emp['full_name'];
    }
    mysqli_stmt_close($emp_stmt);
}

// ---------------- GET FILTER PARAMETERS ----------------
$filterYear = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// ---------------- HELPERS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function formatCurrency($amount) {
    if (empty($amount)) return '₹0';
    return '₹' . number_format((float)$amount, 2);
}

function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') return '—';
    return date('d M Y', strtotime($date));
}

function formatDateTime($datetime) {
    if (empty($datetime)) return '—';
    return date('d M Y, h:i A', strtotime($datetime));
}

function getLoanStatusBadge($status) {
    $badges = [
        'pending' => '<span class="status-badge status-yellow"><i class="bi bi-hourglass-split"></i> Pending</span>',
        'approved' => '<span class="status-badge status-green"><i class="bi bi-check2-circle"></i> Approved</span>',
        'rejected' => '<span class="status-badge status-red"><i class="bi bi-x-circle"></i> Rejected</span>',
        'disbursed' => '<span class="status-badge status-info"><i class="bi bi-cash-stack"></i> Disbursed</span>',
        'repaid' => '<span class="status-badge status-success"><i class="bi bi-check-all"></i> Repaid</span>',
        'cancelled' => '<span class="status-badge status-gray"><i class="bi bi-ban"></i> Cancelled</span>'
    ];
    return $badges[strtolower($status)] ?? '<span class="status-badge status-gray">' . ucfirst($status) . '</span>';
}

// ---------------- PROCESS FORM SUBMISSION ----------------
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Apply for new loan
    if ($action === 'apply_loan') {
        $amount = (float)($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $repayment_months = (int)($_POST['repayment_months'] ?? 1);
        $requested_date = $_POST['requested_date'] ?? date('Y-m-d');
        
        $errors = [];
        if ($amount <= 0) $errors[] = "Please enter a valid amount.";
        if (empty($reason)) $errors[] = "Please provide a reason for the loan.";
        if ($repayment_months < 1 || $repayment_months > 24) $errors[] = "Repayment period must be between 1 and 24 months.";
        
        // Check if employee has any pending loans
        $check_query = "SELECT COUNT(*) as pending FROM salary_loans WHERE employee_id = ? AND status IN ('pending', 'approved', 'disbursed')";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "i", $selected_employee_id);
        mysqli_stmt_execute($check_stmt);
        $check_res = mysqli_stmt_get_result($check_stmt);
        $check_row = mysqli_fetch_assoc($check_res);
        mysqli_stmt_close($check_stmt);
        
        if ($check_row['pending'] > 0) {
            $errors[] = "You have an existing pending or active loan. Please clear it before applying for a new one.";
        }
        
        if (empty($errors)) {
            $loan_no = 'LN-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $insert_query = "
                INSERT INTO salary_loans (
                    loan_no, employee_id, employee_name, amount, reason, repayment_months,
                    monthly_deduction, requested_date, status, applied_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ";
            
            $monthly_deduction = $amount / $repayment_months;
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "sisdsidss", 
                $loan_no, $selected_employee_id, $selected_employee_name, $amount, $reason,
                $repayment_months, $monthly_deduction, $requested_date
            );
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $message = "Loan application submitted successfully. Request ID: " . $loan_no;
                $message_type = "success";
                logActivity($conn, $selected_employee_id, $selected_employee_name, 'employee', 'CREATE', 
                    'salary_loans', mysqli_insert_id($conn), $loan_no, "Salary loan application submitted");
            } else {
                $message = "Failed to submit application: " . mysqli_error($conn);
                $message_type = "danger";
            }
            mysqli_stmt_close($insert_stmt);
        } else {
            $message = implode("<br>", $errors);
            $message_type = "danger";
        }
    }
    
    // Approve loan (HR/Director only)
    elseif ($has_full_access && $action === 'approve_loan') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        $approver_remarks = trim($_POST['approver_remarks'] ?? '');
        
        $update_query = "UPDATE salary_loans SET 
                        status = 'approved',
                        approved_by = ?,
                        approved_by_name = ?,
                        approved_at = NOW(),
                        approver_remarks = ?,
                        updated_at = NOW()
                        WHERE id = ? AND status = 'pending'";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "issi", $employeeId, $employeeName, $approver_remarks, $loan_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $message = "Loan approved successfully.";
            $message_type = "success";
            logActivity($conn, $employeeId, $employeeName, $employeeDesignation, 'UPDATE', 
                'salary_loans', $loan_id, null, "Salary loan approved");
        } else {
            $message = "Failed to approve loan: " . mysqli_error($conn);
            $message_type = "danger";
        }
        mysqli_stmt_close($update_stmt);
    }
    
    // Reject loan (HR/Director only)
    elseif ($has_full_access && $action === 'reject_loan') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        
        if (empty($rejection_reason)) {
            $message = "Please provide a rejection reason.";
            $message_type = "danger";
        } else {
            $update_query = "UPDATE salary_loans SET 
                            status = 'rejected',
                            approved_by = ?,
                            approved_by_name = ?,
                            approved_at = NOW(),
                            approver_remarks = ?,
                            updated_at = NOW()
                            WHERE id = ? AND status = 'pending'";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "issi", $employeeId, $employeeName, $rejection_reason, $loan_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $message = "Loan rejected successfully.";
                $message_type = "success";
                logActivity($conn, $employeeId, $employeeName, $employeeDesignation, 'UPDATE', 
                    'salary_loans', $loan_id, null, "Salary loan rejected");
            } else {
                $message = "Failed to reject loan: " . mysqli_error($conn);
                $message_type = "danger";
            }
            mysqli_stmt_close($update_stmt);
        }
    }
    
    // Mark as disbursed (HR/Director only)
    elseif ($has_full_access && $action === 'disburse_loan') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        $disbursement_date = $_POST['disbursement_date'] ?? date('Y-m-d');
        
        $update_query = "UPDATE salary_loans SET 
                        status = 'disbursed',
                        disbursement_date = ?,
                        updated_at = NOW()
                        WHERE id = ? AND status = 'approved'";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "si", $disbursement_date, $loan_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $message = "Loan marked as disbursed successfully.";
            $message_type = "success";
            logActivity($conn, $employeeId, $employeeName, $employeeDesignation, 'UPDATE', 
                'salary_loans', $loan_id, null, "Salary loan disbursed");
        } else {
            $message = "Failed to update loan: " . mysqli_error($conn);
            $message_type = "danger";
        }
        mysqli_stmt_close($update_stmt);
    }
}

// ---------------- BUILD QUERY WITH FILTERS ----------------
$whereConditions = ["employee_id = ?"];
$params = [$selected_employee_id];
$types = "i";

if ($filterYear !== 'all' && $filterYear > 0) {
    $whereConditions[] = "YEAR(applied_at) = ?";
    $params[] = $filterYear;
    $types .= "i";
}

if ($filterStatus !== '' && $filterStatus !== 'all') {
    $whereConditions[] = "LOWER(status) = ?";
    $params[] = strtolower($filterStatus);
    $types .= "s";
}

if ($searchTerm !== '') {
    $whereConditions[] = "(loan_no LIKE ? OR reason LIKE ? OR CAST(amount AS CHAR) LIKE ?)";
    $searchPattern = "%{$searchTerm}%";
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $types .= "sss";
}

$whereClause = implode(" AND ", $whereConditions);

// ---------------- GET AVAILABLE YEARS ----------------
$years = [];
$yearStmt = mysqli_prepare($conn, "SELECT DISTINCT YEAR(applied_at) as yr FROM salary_loans WHERE employee_id = ? ORDER BY yr DESC");
if ($yearStmt) {
    mysqli_stmt_bind_param($yearStmt, "i", $selected_employee_id);
    mysqli_stmt_execute($yearStmt);
    $yearRes = mysqli_stmt_get_result($yearStmt);
    while ($row = mysqli_fetch_assoc($yearRes)) {
        $years[] = $row['yr'];
    }
    mysqli_stmt_close($yearStmt);
}

// ---------------- FETCH LOAN HISTORY WITH FILTERS ----------------
$loanHistory = [];
$totalRecords = 0;

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM salary_loans WHERE {$whereClause}";
$countStmt = mysqli_prepare($conn, $countSql);
if ($countStmt) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
    mysqli_stmt_execute($countStmt);
    $countRes = mysqli_stmt_get_result($countStmt);
    $totalRecords = mysqli_fetch_assoc($countRes)['total'];
    mysqli_stmt_close($countStmt);
}

// Fetch paginated results
$sql = "
    SELECT * FROM salary_loans
    WHERE {$whereClause}
    ORDER BY applied_at DESC
    LIMIT ? OFFSET ?
";
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$st = mysqli_prepare($conn, $sql);
if ($st) {
    mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $loanHistory = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
}

// ---------------- LOAN STATS ----------------
$loanStats = [
    'total_loans' => 0,
    'total_amount' => 0,
    'pending' => 0,
    'approved' => 0,
    'disbursed' => 0,
    'repaid' => 0,
    'rejected' => 0,
];

$statsStmt = mysqli_prepare($conn, "
    SELECT 
        COUNT(*) as total_loans,
        SUM(amount) as total_amount,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN LOWER(status) = 'disbursed' THEN 1 ELSE 0 END) as disbursed,
        SUM(CASE WHEN LOWER(status) = 'repaid' THEN 1 ELSE 0 END) as repaid,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM salary_loans
    WHERE employee_id = ?
");
if ($statsStmt) {
    mysqli_stmt_bind_param($statsStmt, "i", $selected_employee_id);
    mysqli_stmt_execute($statsStmt);
    $statsRes = mysqli_stmt_get_result($statsStmt);
    $stats = mysqli_fetch_assoc($statsRes);
    $loanStats = [
        'total_loans' => (int)($stats['total_loans'] ?? 0),
        'total_amount' => (float)($stats['total_amount'] ?? 0),
        'pending' => (int)($stats['pending'] ?? 0),
        'approved' => (int)($stats['approved'] ?? 0),
        'disbursed' => (int)($stats['disbursed'] ?? 0),
        'repaid' => (int)($stats['repaid'] ?? 0),
        'rejected' => (int)($stats['rejected'] ?? 0),
    ];
    mysqli_stmt_close($statsStmt);
}

// Check if employee has active/pending loan
$has_active_loan = ($loanStats['pending'] > 0 || $loanStats['approved'] > 0 || $loanStats['disbursed'] > 0);

// Calculate pagination
$totalPages = ceil($totalRecords / $perPage);

// Fetch all employees for HR/Director dropdown
$all_employees = [];
if ($has_full_access) {
    $emp_list_query = "SELECT id, full_name, employee_code, designation FROM employees WHERE employee_status = 'active' ORDER BY full_name";
    $emp_list_stmt = mysqli_prepare($conn, $emp_list_query);
    mysqli_stmt_execute($emp_list_stmt);
    $emp_list_res = mysqli_stmt_get_result($emp_list_stmt);
    $all_employees = mysqli_fetch_all($emp_list_res, MYSQLI_ASSOC);
    mysqli_stmt_close($emp_list_stmt);
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Salary Loan Management - TEK-C</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll { flex: 1 1 auto; overflow: auto; padding: 16px 12px 14px; }
        .panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(17, 24, 39, 0.05);
            padding: 12px;
            margin-bottom: 12px;
        }

        .title-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .h-title { margin: 0; font-weight: 1000; color: #111827; line-height: 1.1; }
        .h-sub { margin: 4px 0 0; color: #6b7280; font-weight: 800; font-size: 13px; }

        .sec-head {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            border-radius: 14px;
            background: #f9fafb;
            border: 1px solid #eef2f7;
            margin-bottom: 10px;
        }
        .sec-ic {
            width: 34px; height: 34px; border-radius: 12px;
            display: grid; place-items: center;
            background: rgba(45, 156, 219, 0.12);
            color: var(--blue, #2d9cdb);
            flex: 0 0 auto;
        }
        .sec-title { margin: 0; font-weight: 1000; color: #111827; font-size: 14px; }
        .sec-sub { margin: 2px 0 0; color: #6b7280; font-weight: 800; font-size: 12px; }

        /* Stats cards */
        .stat-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(17, 24, 39, 0.05);
            padding: 12px 14px;
            height: 78px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(17, 24, 39, 0.1);
        }
        .stat-ic {
            width: 42px; height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 18px;
            flex: 0 0 auto;
        }
        .stat-ic.blue { background: var(--blue, #2d9cdb); }
        .stat-ic.green { background: #10b981; }
        .stat-ic.yellow { background: #f59e0b; }
        .stat-ic.red { background: #ef4444; }
        .stat-ic.purple { background: #8b5cf6; }
        .stat-ic.teal { background: #14b8a6; }
        .stat-label { color: #4b5563; font-weight: 850; font-size: 12px; }
        .stat-value { font-size: 22px; font-weight: 1000; line-height: 1; margin-top: 2px; }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        .filter-label {
            font-size: 11px;
            font-weight: 900;
            color: #6b7280;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filter-select, .filter-input {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 800;
            width: 100%;
            background: #fff;
        }
        .filter-select:focus, .filter-input:focus {
            border-color: var(--blue, #2d9cdb);
            outline: none;
        }
        .btn-filter {
            background: var(--blue, #2d9cdb);
            border: none;
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 900;
            font-size: 13px;
            color: #fff;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-filter:hover {
            background: #2a8bc9;
            transform: translateY(-1px);
        }
        .btn-reset {
            background: #f3f4f6;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 900;
            font-size: 13px;
            color: #4b5563;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-reset:hover {
            background: #e5e7eb;
        }
        .btn-submit {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 12px;
            padding: 10px 28px;
            font-weight: 700;
            color: #fff;
            transition: all 0.3s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -4px rgba(16, 185, 129, 0.3);
        }
        .btn-secondary-custom {
            background: #f3f4f6;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 900;
            font-size: 13px;
            color: #4b5563;
        }

        /* Status badge */
        .status-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 1000;
            letter-spacing: .3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            border: 1px solid transparent;
        }
        .status-green { background: rgba(16, 185, 129, 0.12); color: #10b981; border-color: rgba(16, 185, 129, 0.22); }
        .status-yellow { background: rgba(245, 158, 11, 0.12); color: #f59e0b; border-color: rgba(245, 158, 11, 0.22); }
        .status-red { background: rgba(239, 68, 68, 0.12); color: #ef4444; border-color: rgba(239, 68, 68, 0.22); }
        .status-info { background: rgba(6, 182, 212, 0.12); color: #06b6d4; border-color: rgba(6, 182, 212, 0.22); }
        .status-success { background: rgba(16, 185, 129, 0.12); color: #10b981; border-color: rgba(16, 185, 129, 0.22); }
        .status-gray { background: rgba(100, 116, 139, 0.12); color: #64748b; border-color: rgba(100, 116, 139, 0.22); }

        /* Loan Application Form */
        .loan-form {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 24px;
            color: white;
            margin-bottom: 20px;
        }
        .loan-form label {
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 6px;
            opacity: 0.9;
        }
        .loan-form .form-control, .loan-form .form-select {
            border-radius: 12px;
            border: none;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.95);
        }

        /* Mobile cards */
        .loan-card {
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(17, 24, 39, 0.05);
            padding: 12px;
            transition: all 0.2s;
        }
        .loan-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(17, 24, 39, 0.1);
        }
        .loan-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .loan-title {
            font-weight: 1000;
            color: #111827;
            font-size: 14px;
            line-height: 1.2;
            margin: 0;
        }
        .loan-sub {
            margin-top: 6px;
            color: #6b7280;
            font-weight: 800;
            font-size: 12px;
            line-height: 1.2;
        }
        .loan-kv { margin-top: 10px; display: grid; gap: 8px; }
        .loan-row { display: flex; gap: 10px; align-items: flex-start; }
        .loan-key { flex: 0 0 100px; color: #6b7280; font-weight: 1000; font-size: 12px; }
        .loan-val { flex: 1 1 auto; font-weight: 900; color: #111827; font-size: 13px; line-height: 1.25; }

        /* Pagination */
        .pagination-custom {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .page-link-custom {
            padding: 8px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            color: #4b5563;
            font-weight: 800;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .page-link-custom:hover {
            background: var(--blue, #2d9cdb);
            border-color: var(--blue, #2d9cdb);
            color: #fff;
        }
        .page-link-custom.active {
            background: var(--blue, #2d9cdb);
            border-color: var(--blue, #2d9cdb);
            color: #fff;
        }
        .page-link-custom.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        .amount-highlight {
            font-size: 18px;
            font-weight: 1000;
            color: #111827;
        }

        @media (max-width: 991.98px) {
            .main { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
            .sidebar { position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
            .sidebar.open, .sidebar.active, .sidebar.show { transform: translateX(0) !important; }
        }
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
            .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .btn-filter, .btn-reset { width: 100%; justify-content: center; }
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
                        <h1 class="h-title">Salary Loan Management</h1>
                        <p class="h-sub">Apply for salary advance / loan and track your requests</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($selected_employee_name); ?></span>
                    </div>
                </div>

                <!-- Flash Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Employee Selection (HR/Director only) -->
                <?php if ($has_full_access): ?>
                <div class="panel mb-3">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted">Select Employee</label>
                            <select name="employee_id" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($all_employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" <?php echo $selected_employee_id == $emp['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($emp['full_name']); ?> (<?php echo e($emp['employee_code']); ?>) - <?php echo e($emp['designation']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <div class="text-muted small">
                                <i class="bi bi-info-circle"></i> Viewing loan records for: <strong><?php echo e($selected_employee_name); ?></strong>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Loan Application Form -->
                <?php if (!$has_active_loan || ($has_full_access && $selected_employee_id != $employeeId)): ?>
                <div class="loan-form">
                    <h4 class="mb-3"><i class="bi bi-cash-stack me-2"></i> Apply for Salary Advance</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="apply_loan">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Amount (₹)</label>
                                <input type="number" name="amount" class="form-control" step="500" min="500" max="100000" required placeholder="Enter amount">
                                <small class="text-white-50">Min: ₹500, Max: ₹1,00,000</small>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Repayment Period (Months)</label>
                                <select name="repayment_months" class="form-select" required>
                                    <option value="1">1 Month</option>
                                    <option value="2">2 Months</option>
                                    <option value="3">3 Months</option>
                                    <option value="4">4 Months</option>
                                    <option value="5">5 Months</option>
                                    <option value="6">6 Months</option>
                                    <option value="12">12 Months</option>
                                    <option value="18">18 Months</option>
                                    <option value="24">24 Months</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Requested Date</label>
                                <input type="date" name="requested_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reason for Loan <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control" rows="2" required placeholder="Please provide reason for the loan request..."></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn-submit">
                                    <i class="bi bi-send"></i> Submit Application
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="panel mb-3">
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        You have an existing active or pending loan request. Please wait for it to be processed before applying for a new one.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-inboxes"></i></div>
                            <div>
                                <div class="stat-label">Total Requests</div>
                                <div class="stat-value"><?php echo e($loanStats['total_loans']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-currency-rupee"></i></div>
                            <div>
                                <div class="stat-label">Total Amount</div>
                                <div class="stat-value"><?php echo formatCurrency($loanStats['total_amount']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-hourglass-split"></i></div>
                            <div>
                                <div class="stat-label">Pending</div>
                                <div class="stat-value"><?php echo e($loanStats['pending']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic teal"><i class="bi bi-check2-circle"></i></div>
                            <div>
                                <div class="stat-label">Approved</div>
                                <div class="stat-value"><?php echo e($loanStats['approved']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-cash-stack"></i></div>
                            <div>
                                <div class="stat-label">Disbursed</div>
                                <div class="stat-value"><?php echo e($loanStats['disbursed']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-x-circle"></i></div>
                            <div>
                                <div class="stat-label">Rejected</div>
                                <div class="stat-value"><?php echo e($loanStats['rejected']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="panel">
                    <form method="GET" id="filterForm" class="filter-bar">
                        <?php if ($has_full_access && isset($_GET['employee_id'])): ?>
                            <input type="hidden" name="employee_id" value="<?php echo e($_GET['employee_id']); ?>">
                        <?php endif; ?>
                        <div class="filter-group">
                            <div class="filter-label">Year</div>
                            <select name="year" class="filter-select">
                                <option value="all">All Years</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo ($filterYear == $year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Status</div>
                            <select name="status" class="filter-select">
                                <option value="all">All Status</option>
                                <option value="pending" <?php echo ($filterStatus == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo ($filterStatus == 'approved') ? 'selected' : ''; ?>>Approved</option>
                                <option value="disbursed" <?php echo ($filterStatus == 'disbursed') ? 'selected' : ''; ?>>Disbursed</option>
                                <option value="rejected" <?php echo ($filterStatus == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                <option value="repaid" <?php echo ($filterStatus == 'repaid') ? 'selected' : ''; ?>>Repaid</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Search</div>
                            <input type="text" name="search" class="filter-input" placeholder="Loan ID, Reason, Amount..." value="<?php echo e($searchTerm); ?>">
                        </div>

                        <div class="filter-group" style="flex: 0 0 auto;">
                            <button type="submit" class="btn-filter">
                                <i class="bi bi-funnel"></i> Apply Filters
                            </button>
                        </div>

                        <div class="filter-group" style="flex: 0 0 auto;">
                            <a href="salary-loan.php<?php echo $has_full_access && isset($_GET['employee_id']) ? '?employee_id=' . e($_GET['employee_id']) : ''; ?>" class="btn-reset text-decoration-none">
                                <i class="bi bi-arrow-repeat"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Loan History -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-journal-bookmark-fill"></i></div>
                        <div>
                            <p class="sec-title mb-0">Loan History</p>
                            <p class="sec-sub mb-0">
                                Showing <?php echo count($loanHistory); ?> of <?php echo $totalRecords; ?> records
                                <?php if ($filterYear !== 'all' && $filterYear > 0): ?> • Year: <?php echo $filterYear; ?><?php endif; ?>
                                <?php if ($filterStatus !== '' && $filterStatus !== 'all'): ?> • Status: <?php echo ucfirst($filterStatus); ?><?php endif; ?>
                                <?php if ($searchTerm !== ''): ?> • Search: "<?php echo e($searchTerm); ?>"<?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if (empty($loanHistory)): ?>
                        <div class="text-muted text-center py-4" style="font-weight:900;">
                            <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                            No loan history found matching your filters.
                        </div>
                    <?php else: ?>

                        <!-- MOBILE: Cards -->
                        <div class="d-block d-md-none">
                            <div class="d-grid gap-3">
                                <?php foreach ($loanHistory as $loan): ?>
                                    <div class="loan-card">
                                        <div class="loan-top">
                                            <div style="flex:1 1 auto;">
                                                <div class="loan-title">
                                                    <?php echo e($loan['loan_no']); ?>
                                                    <span class="small text-muted"><?php echo formatDate($loan['applied_at']); ?></span>
                                                </div>
                                                <div class="loan-sub">
                                                    <span class="amount-highlight"><?php echo formatCurrency($loan['amount']); ?></span>
                                                    • <?php echo $loan['repayment_months']; ?> month(s)
                                                </div>
                                            </div>
                                            <?php echo getLoanStatusBadge($loan['status']); ?>
                                        </div>

                                        <div class="loan-kv">
                                            <div class="loan-row">
                                                <div class="loan-key">Reason</div>
                                                <div class="loan-val"><?php echo e($loan['reason']); ?></div>
                                            </div>
                                            <div class="loan-row">
                                                <div class="loan-key">Monthly Deduction</div>
                                                <div class="loan-val"><?php echo formatCurrency($loan['monthly_deduction']); ?></div>
                                            </div>
                                            <?php if ($loan['disbursement_date']): ?>
                                                <div class="loan-row">
                                                    <div class="loan-key">Disbursed On</div>
                                                    <div class="loan-val"><?php echo formatDate($loan['disbursement_date']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($loan['approver_remarks']): ?>
                                                <div class="loan-row">
                                                    <div class="loan-key">Remarks</div>
                                                    <div class="loan-val"><?php echo e($loan['approver_remarks']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($has_full_access && $loan['status'] == 'pending'): ?>
                                            <div class="mt-2 d-flex gap-2">
                                                <button class="btn-action btn-sm" onclick="approveLoan(<?php echo htmlspecialchars(json_encode($loan)); ?>)">
                                                    <i class="bi bi-check-lg"></i> Approve
                                                </button>
                                                <button class="btn-action btn-sm text-danger" onclick="rejectLoan(<?php echo $loan['id']; ?>)">
                                                    <i class="bi bi-x-lg"></i> Reject
                                                </button>
                                            </div>
                                        <?php elseif ($has_full_access && $loan['status'] == 'approved'): ?>
                                            <div class="mt-2">
                                                <button class="btn-action btn-sm" onclick="disburseLoan(<?php echo $loan['id']; ?>)">
                                                    <i class="bi bi-cash-stack"></i> Mark as Disbursed
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- DESKTOP: Table -->
                        <div class="d-none d-md-block">
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Loan No.</th>
                                            <th>Applied On</th>
                                            <th>Amount</th>
                                            <th>Repayment</th>
                                            <th>Monthly Deduction</th>
                                            <th>Status</th>
                                            <th>Reason</th>
                                            <?php if ($has_full_access): ?>
                                                <th>Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($loanHistory as $loan): ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo e($loan['loan_no']); ?></td>
                                                <td><?php echo formatDateTime($loan['applied_at']); ?></td>
                                                <td class="fw-bold"><?php echo formatCurrency($loan['amount']); ?></td>
                                                <td><?php echo $loan['repayment_months']; ?> months</td>
                                                <td><?php echo formatCurrency($loan['monthly_deduction']); ?></td>
                                                <td><?php echo getLoanStatusBadge($loan['status']); ?></td>
                                                <td><small><?php echo e(substr($loan['reason'], 0, 60)); ?><?php echo strlen($loan['reason']) > 60 ? '...' : ''; ?></small></td>
                                                <?php if ($has_full_access): ?>
                                                    <td>
                                                        <?php if ($loan['status'] == 'pending'): ?>
                                                            <button class="btn-action btn-sm mb-1" onclick="approveLoan(<?php echo htmlspecialchars(json_encode($loan)); ?>)">
                                                                <i class="bi bi-check-lg"></i> Approve
                                                            </button>
                                                            <button class="btn-action btn-sm text-danger" onclick="rejectLoan(<?php echo $loan['id']; ?>)">
                                                                <i class="bi bi-x-lg"></i> Reject
                                                            </button>
                                                        <?php elseif ($loan['status'] == 'approved'): ?>
                                                            <button class="btn-action btn-sm" onclick="disburseLoan(<?php echo $loan['id']; ?>)">
                                                                <i class="bi bi-cash-stack"></i> Disburse
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted small">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="pagination-custom">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>" class="page-link-custom">
                                    <i class="bi bi-chevron-left"></i> Previous
                                </a>
                            <?php else: ?>
                                <span class="page-link-custom disabled">Previous</span>
                            <?php endif; ?>

                            <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="page-link-custom <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>" class="page-link-custom">
                                    Next <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link-custom disabled">Next</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>

                <!-- Info Section -->
                <div class="row g-3 mt-2">
                    <div class="col-md-6">
                        <div class="panel">
                            <h6 class="fw-bold mb-2"><i class="bi bi-info-circle text-primary"></i> Loan Policy</h6>
                            <p class="small text-muted mb-0">Salary advance/loan is available to all permanent employees. The amount is deducted from salary in equal monthly installments. Maximum loan amount is ₹1,00,000 with repayment period up to 24 months.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="panel">
                            <h6 class="fw-bold mb-2"><i class="bi bi-question-circle text-warning"></i> Need Help?</h6>
                            <p class="small text-muted mb-0">For any questions regarding salary loan applications, please contact HR department. Loan approval typically takes 2-3 business days after submission.</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<!-- Approve Loan Modal -->
<div class="modal fade" id="approveLoanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle text-success"></i> Approve Loan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve_loan">
                <input type="hidden" name="loan_id" id="approve_loan_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <p>You are about to approve the loan application for:</p>
                        <div class="bg-light p-3 rounded">
                            <p class="mb-1"><strong>Employee:</strong> <span id="approve_employee_name"></span></p>
                            <p class="mb-1"><strong>Amount:</strong> <span id="approve_amount"></span></p>
                            <p class="mb-0"><strong>Loan No:</strong> <span id="approve_loan_no"></span></p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks (Optional)</label>
                        <textarea name="approver_remarks" class="form-control" rows="3" placeholder="Add any remarks..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, #10b981, #059669);">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Loan Modal -->
<div class="modal fade" id="rejectLoanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> Reject Loan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject_loan">
                <input type="hidden" name="loan_id" id="reject_loan_id">
                <div class="modal-body">
                    <p>Are you sure you want to reject this loan application?</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Please provide reason for rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, #ef4444, #dc2626);">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Disburse Loan Modal -->
<div class="modal fade" id="disburseLoanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success"></i> Mark as Disbursed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="disburse_loan">
                <input type="hidden" name="loan_id" id="disburse_loan_id">
                <div class="modal-body">
                    <p>Confirm that the loan amount has been disbursed to the employee.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Disbursement Date</label>
                        <input type="date" name="disbursement_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-submit">Confirm Disbursement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    function approveLoan(loan) {
        document.getElementById('approve_loan_id').value = loan.id;
        document.getElementById('approve_employee_name').textContent = '<?php echo e($selected_employee_name); ?>';
        document.getElementById('approve_amount').textContent = '₹' + parseFloat(loan.amount).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('approve_loan_no').textContent = loan.loan_no;
        new bootstrap.Modal(document.getElementById('approveLoanModal')).show();
    }
    
    function rejectLoan(id) {
        document.getElementById('reject_loan_id').value = id;
        new bootstrap.Modal(document.getElementById('rejectLoanModal')).show();
    }
    
    function disburseLoan(id) {
        document.getElementById('disburse_loan_id').value = id;
        new bootstrap.Modal(document.getElementById('disburseLoanModal')).show();
    }
    
    // Animation on load
    document.addEventListener('DOMContentLoaded', function() {
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            setTimeout(() => {
                card.style.transition = 'all 0.3s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });
</script>

</body>
</html>

<?php
try {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) { }
?>