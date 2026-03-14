<?php
// hr/payroll.php - Payroll & Appraisal Management
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
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'director', 'vice president', 'general manager']);
$isAdmin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director');

// ---------------- CREATE APPRAISAL TABLE IF NOT EXISTS ----------------
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'appraisal_requests'");
if (mysqli_num_rows($check_table) == 0) {
    $create_table = "
    CREATE TABLE `appraisal_requests` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `request_no` varchar(50) NOT NULL,
        `employee_id` int(11) NOT NULL,
        `employee_name` varchar(150) NOT NULL,
        `employee_code` varchar(50) NOT NULL,
        `department` varchar(50) NOT NULL,
        `designation` varchar(100) NOT NULL,
        `date_of_joining` date NOT NULL,
        `current_ctc` decimal(12,2) NOT NULL,
        `current_basic` decimal(12,2) DEFAULT NULL,
        `current_hra` decimal(12,2) DEFAULT NULL,
        `current_conveyance` decimal(12,2) DEFAULT NULL,
        `current_medical` decimal(12,2) DEFAULT NULL,
        `current_special` decimal(12,2) DEFAULT NULL,
        `current_bonus` decimal(12,2) DEFAULT NULL,
        `proposed_ctc` decimal(12,2) NOT NULL,
        `proposed_basic` decimal(12,2) DEFAULT NULL,
        `proposed_hra` decimal(12,2) DEFAULT NULL,
        `proposed_conveyance` decimal(12,2) DEFAULT NULL,
        `proposed_medical` decimal(12,2) DEFAULT NULL,
        `proposed_special` decimal(12,2) DEFAULT NULL,
        `proposed_bonus` decimal(12,2) DEFAULT NULL,
        `increment_percentage` decimal(5,2) GENERATED ALWAYS AS (((`proposed_ctc` - `current_ctc`) / `current_ctc`) * 100) STORED,
        `appraisal_type` enum('Annual','Promotion','Performance','Market Correction','Other') NOT NULL,
        `appraisal_date` date NOT NULL,
        `effective_from` date NOT NULL,
        `reason` text NOT NULL,
        `performance_rating` int(11) DEFAULT NULL COMMENT '1-5',
        `comments` text,
        `recommended_by` int(11) NOT NULL,
        `recommended_by_name` varchar(150) NOT NULL,
        `recommended_at` datetime NOT NULL,
        `manager_id` int(11) DEFAULT NULL,
        `manager_name` varchar(150) DEFAULT NULL,
        `manager_approved_at` datetime DEFAULT NULL,
        `manager_comments` text,
        `hr_id` int(11) DEFAULT NULL,
        `hr_name` varchar(150) DEFAULT NULL,
        `hr_approved_at` datetime DEFAULT NULL,
        `hr_comments` text,
        `status` enum('Draft','Pending Manager','Manager Approved','HR Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Draft',
        `rejection_reason` text,
        `rejected_by` int(11) DEFAULT NULL,
        `rejected_at` datetime DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `request_no` (`request_no`),
        KEY `idx_employee` (`employee_id`),
        KEY `idx_manager` (`manager_id`),
        KEY `idx_status` (`status`),
        KEY `idx_appraisal_date` (`appraisal_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    mysqli_query($conn, $create_table);
}

$check_table2 = mysqli_query($conn, "SHOW TABLES LIKE 'payroll_history'");
if (mysqli_num_rows($check_table2) == 0) {
    $create_table2 = "
    CREATE TABLE `payroll_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `employee_name` varchar(150) NOT NULL,
        `employee_code` varchar(50) NOT NULL,
        `month` tinyint(4) NOT NULL,
        `year` smallint(6) NOT NULL,
        `basic_salary` decimal(12,2) NOT NULL,
        `hra` decimal(12,2) NOT NULL,
        `conveyance` decimal(12,2) NOT NULL,
        `medical` decimal(12,2) NOT NULL,
        `special_allowance` decimal(12,2) NOT NULL,
        `bonus` decimal(12,2) DEFAULT NULL,
        `gross_salary` decimal(12,2) GENERATED ALWAYS AS (`basic_salary` + `hra` + `conveyance` + `medical` + `special_allowance` + IFNULL(`bonus`, 0)) STORED,
        `pf_deduction` decimal(12,2) DEFAULT NULL,
        `esi_deduction` decimal(12,2) DEFAULT NULL,
        `pt_deduction` decimal(12,2) DEFAULT NULL,
        `tds_deduction` decimal(12,2) DEFAULT NULL,
        `other_deductions` decimal(12,2) DEFAULT NULL,
        `total_deductions` decimal(12,2) GENERATED ALWAYS AS (IFNULL(`pf_deduction`, 0) + IFNULL(`esi_deduction`, 0) + IFNULL(`pt_deduction`, 0) + IFNULL(`tds_deduction`, 0) + IFNULL(`other_deductions`, 0)) STORED,
        `net_salary` decimal(12,2) GENERATED ALWAYS AS (`gross_salary` - (`pf_deduction` + `esi_deduction` + `pt_deduction` + `tds_deduction` + `other_deductions`)) STORED,
        `working_days` int(11) DEFAULT NULL,
        `present_days` int(11) DEFAULT NULL,
        `leave_days` int(11) DEFAULT NULL,
        `loss_of_pay_days` int(11) DEFAULT NULL,
        `overtime_hours` decimal(5,2) DEFAULT NULL,
        `overtime_amount` decimal(12,2) DEFAULT NULL,
        `incentives` decimal(12,2) DEFAULT NULL,
        `reimbursements` decimal(12,2) DEFAULT NULL,
        `status` enum('Draft','Processed','Paid','Cancelled') NOT NULL DEFAULT 'Draft',
        `processed_by` int(11) DEFAULT NULL,
        `processed_at` datetime DEFAULT NULL,
        `payment_date` date DEFAULT NULL,
        `payment_mode` enum('Bank Transfer','Cheque','Cash') DEFAULT NULL,
        `transaction_id` varchar(100) DEFAULT NULL,
        `remarks` text,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_employee_month` (`employee_id`,`month`,`year`),
        KEY `idx_status` (`status`),
        KEY `idx_month_year` (`month`,`year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    mysqli_query($conn, $create_table2);
}

// ---------------- HANDLE APPRAISAL ACTIONS ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Create New Appraisal Request (HR only)
    if (isset($_POST['action']) && $_POST['action'] === 'create_appraisal' && ($isHr || $isAdmin)) {
        $employee_id = (int)$_POST['employee_id'];
        $appraisal_type = mysqli_real_escape_string($conn, $_POST['appraisal_type']);
        $appraisal_date = mysqli_real_escape_string($conn, $_POST['appraisal_date']);
        $effective_from = mysqli_real_escape_string($conn, $_POST['effective_from']);
        $current_ctc = (float)$_POST['current_ctc'];
        $proposed_ctc = (float)$_POST['proposed_ctc'];
        $reason = mysqli_real_escape_string($conn, $_POST['reason']);
        $performance_rating = !empty($_POST['performance_rating']) ? (int)$_POST['performance_rating'] : null;
        $comments = mysqli_real_escape_string($conn, $_POST['comments'] ?? '');
        
        // Get employee details
        $emp_query = "SELECT full_name, employee_code, department, designation, date_of_joining FROM employees WHERE id = ?";
        $emp_stmt = mysqli_prepare($conn, $emp_query);
        mysqli_stmt_bind_param($emp_stmt, "i", $employee_id);
        mysqli_stmt_execute($emp_stmt);
        $emp_res = mysqli_stmt_get_result($emp_stmt);
        $employee = mysqli_fetch_assoc($emp_res);
        
        if (!$employee) {
            $message = "Employee not found.";
            $messageType = "danger";
        } else {
            // Generate request number
            $year = date('Y');
            $month = date('m');
            
            $seq_query = "SELECT COUNT(*) as count FROM appraisal_requests WHERE request_no LIKE 'APR-{$year}{$month}%'";
            $seq_result = mysqli_query($conn, $seq_query);
            $seq_row = mysqli_fetch_assoc($seq_result);
            $seq_num = str_pad($seq_row['count'] + 1, 4, '0', STR_PAD_LEFT);
            $request_no = "APR-{$year}{$month}-{$seq_num}";
            
            // Get manager ID (reporting_to from employees table)
            $manager_query = "SELECT reporting_to, reporting_manager FROM employees WHERE id = ?";
            $manager_stmt = mysqli_prepare($conn, $manager_query);
            mysqli_stmt_bind_param($manager_stmt, "i", $employee_id);
            mysqli_stmt_execute($manager_stmt);
            $manager_res = mysqli_stmt_get_result($manager_stmt);
            $manager_data = mysqli_fetch_assoc($manager_res);
            
            $manager_id = $manager_data['reporting_to'] ?? null;
            $manager_name = null;
            
            if ($manager_id) {
                $mgr_query = "SELECT full_name FROM employees WHERE id = ?";
                $mgr_stmt = mysqli_prepare($conn, $mgr_query);
                mysqli_stmt_bind_param($mgr_stmt, "i", $manager_id);
                mysqli_stmt_execute($mgr_stmt);
                $mgr_res = mysqli_stmt_get_result($mgr_stmt);
                $mgr_data = mysqli_fetch_assoc($mgr_res);
                $manager_name = $mgr_data['full_name'] ?? null;
            }
            
            // Insert appraisal request
            $insert_stmt = mysqli_prepare($conn, "
                INSERT INTO appraisal_requests (
                    request_no, employee_id, employee_name, employee_code, department, designation,
                    date_of_joining, current_ctc, proposed_ctc, appraisal_type, appraisal_date,
                    effective_from, reason, performance_rating, comments,
                    recommended_by, recommended_by_name, recommended_at,
                    manager_id, manager_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 'Pending Manager')
            ");
            
            mysqli_stmt_bind_param(
                $insert_stmt,
                "sisssssddssssiissss",
                $request_no,
                $employee_id,
                $employee['full_name'],
                $employee['employee_code'],
                $employee['department'],
                $employee['designation'],
                $employee['date_of_joining'],
                $current_ctc,
                $proposed_ctc,
                $appraisal_type,
                $appraisal_date,
                $effective_from,
                $reason,
                $performance_rating,
                $comments,
                $current_employee_id,
                $current_employee['full_name'],
                $manager_id,
                $manager_name
            );
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $appraisal_id = mysqli_insert_id($conn);
                
                logActivity(
                    $conn,
                    'CREATE',
                    'appraisal',
                    "Created appraisal request: {$request_no} for {$employee['full_name']}",
                    $appraisal_id,
                    null,
                    null,
                    json_encode($_POST)
                );
                
                $message = "Appraisal request created successfully! Request #: {$request_no}";
                $messageType = "success";
            } else {
                $message = "Error creating appraisal request: " . mysqli_error($conn);
                $messageType = "danger";
            }
        }
    }
    
    // Manager Approval
    elseif (isset($_POST['action']) && $_POST['action'] === 'manager_approve' && $isManager) {
        $request_id = (int)$_POST['request_id'];
        $manager_comments = mysqli_real_escape_string($conn, $_POST['manager_comments'] ?? '');
        
        $update_stmt = mysqli_prepare($conn, "
            UPDATE appraisal_requests 
            SET status = 'Manager Approved',
                manager_approved_at = NOW(),
                manager_comments = CONCAT(IFNULL(manager_comments, ''), '\n[" . date('Y-m-d H:i:s') . "] " . $manager_comments . "')
            WHERE id = ? AND status = 'Pending Manager'
        ");
        
        mysqli_stmt_bind_param($update_stmt, "i", $request_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'appraisal',
                "Manager approved appraisal request ID: {$request_id}",
                $request_id,
                null,
                null,
                json_encode(['comments' => $manager_comments])
            );
            
            $message = "Appraisal request approved by manager!";
            $messageType = "success";
        } else {
            $message = "Error approving request: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // HR Approval
    elseif (isset($_POST['action']) && $_POST['action'] === 'hr_approve' && ($isHr || $isAdmin)) {
        $request_id = (int)$_POST['request_id'];
        $hr_comments = mysqli_real_escape_string($conn, $_POST['hr_comments'] ?? '');
        
        // Begin transaction to update employee CTC
        mysqli_begin_transaction($conn);
        
        try {
            // Get appraisal details
            $app_query = "SELECT employee_id, proposed_ctc, effective_from FROM appraisal_requests WHERE id = ?";
            $app_stmt = mysqli_prepare($conn, $app_query);
            mysqli_stmt_bind_param($app_stmt, "i", $request_id);
            mysqli_stmt_execute($app_stmt);
            $app_res = mysqli_stmt_get_result($app_stmt);
            $app_data = mysqli_fetch_assoc($app_res);
            
            // Update appraisal status
            $update_stmt = mysqli_prepare($conn, "
                UPDATE appraisal_requests 
                SET status = 'HR Approved',
                    hr_approved_at = NOW(),
                    hr_id = ?,
                    hr_name = ?,
                    hr_comments = CONCAT(IFNULL(hr_comments, ''), '\n[" . date('Y-m-d H:i:s') . "] " . $hr_comments . "')
                WHERE id = ? AND status = 'Manager Approved'
            ");
            
            mysqli_stmt_bind_param($update_stmt, "isi", $current_employee_id, $current_employee['full_name'], $request_id);
            
            if (!mysqli_stmt_execute($update_stmt)) {
                throw new Exception("Failed to update appraisal status");
            }
            
            // Update employee salary in employees table (if you have salary fields)
            // You may need to add salary fields to employees table
            
            mysqli_commit($conn);
            
            logActivity(
                $conn,
                'UPDATE',
                'appraisal',
                "HR approved appraisal request ID: {$request_id}",
                $request_id,
                null,
                null,
                json_encode(['comments' => $hr_comments])
            );
            
            $message = "Appraisal request approved by HR!";
            $messageType = "success";
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Reject Request
    elseif (isset($_POST['action']) && $_POST['action'] === 'reject_request') {
        $request_id = (int)$_POST['request_id'];
        $rejection_reason = mysqli_real_escape_string($conn, $_POST['rejection_reason'] ?? '');
        
        if (empty($rejection_reason)) {
            $message = "Rejection reason is required.";
            $messageType = "danger";
        } else {
            $update_stmt = mysqli_prepare($conn, "
                UPDATE appraisal_requests 
                SET status = 'Rejected',
                    rejection_reason = ?,
                    rejected_by = ?,
                    rejected_at = NOW()
                WHERE id = ?
            ");
            
            mysqli_stmt_bind_param($update_stmt, "sii", $rejection_reason, $current_employee_id, $request_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                logActivity(
                    $conn,
                    'REJECT',
                    'appraisal',
                    "Rejected appraisal request ID: {$request_id}",
                    $request_id,
                    null,
                    null,
                    json_encode(['reason' => $rejection_reason])
                );
                
                $message = "Appraisal request rejected.";
                $messageType = "success";
            } else {
                $message = "Error rejecting request: " . mysqli_error($conn);
                $messageType = "danger";
            }
        }
    }
    
    // Process Payroll (HR only)
    elseif (isset($_POST['action']) && $_POST['action'] === 'process_payroll' && ($isHr || $isAdmin)) {
        $employee_id = (int)$_POST['employee_id'];
        $month = (int)$_POST['month'];
        $year = (int)$_POST['year'];
        $basic_salary = (float)$_POST['basic_salary'];
        $hra = (float)$_POST['hra'];
        $conveyance = (float)$_POST['conveyance'];
        $medical = (float)$_POST['medical'];
        $special_allowance = (float)$_POST['special_allowance'];
        $bonus = !empty($_POST['bonus']) ? (float)$_POST['bonus'] : null;
        $pf_deduction = !empty($_POST['pf_deduction']) ? (float)$_POST['pf_deduction'] : null;
        $esi_deduction = !empty($_POST['esi_deduction']) ? (float)$_POST['esi_deduction'] : null;
        $pt_deduction = !empty($_POST['pt_deduction']) ? (float)$_POST['pt_deduction'] : null;
        $tds_deduction = !empty($_POST['tds_deduction']) ? (float)$_POST['tds_deduction'] : null;
        $other_deductions = !empty($_POST['other_deductions']) ? (float)$_POST['other_deductions'] : null;
        $working_days = !empty($_POST['working_days']) ? (int)$_POST['working_days'] : null;
        $present_days = !empty($_POST['present_days']) ? (int)$_POST['present_days'] : null;
        $leave_days = !empty($_POST['leave_days']) ? (int)$_POST['leave_days'] : null;
        $loss_of_pay_days = !empty($_POST['loss_of_pay_days']) ? (int)$_POST['loss_of_pay_days'] : null;
        $overtime_hours = !empty($_POST['overtime_hours']) ? (float)$_POST['overtime_hours'] : null;
        $overtime_amount = !empty($_POST['overtime_amount']) ? (float)$_POST['overtime_amount'] : null;
        $incentives = !empty($_POST['incentives']) ? (float)$_POST['incentives'] : null;
        $reimbursements = !empty($_POST['reimbursements']) ? (float)$_POST['reimbursements'] : null;
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        
        // Get employee details
        $emp_query = "SELECT full_name, employee_code FROM employees WHERE id = ?";
        $emp_stmt = mysqli_prepare($conn, $emp_query);
        mysqli_stmt_bind_param($emp_stmt, "i", $employee_id);
        mysqli_stmt_execute($emp_stmt);
        $emp_res = mysqli_stmt_get_result($emp_stmt);
        $employee = mysqli_fetch_assoc($emp_res);
        
        if (!$employee) {
            $message = "Employee not found.";
            $messageType = "danger";
        } else {
            // Check if payroll already exists for this month
            $check_query = "SELECT id FROM payroll_history WHERE employee_id = ? AND month = ? AND year = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "iii", $employee_id, $month, $year);
            mysqli_stmt_execute($check_stmt);
            $check_res = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_res) > 0) {
                $message = "Payroll already exists for this employee for the selected month.";
                $messageType = "warning";
            } else {
                // Insert payroll record
                $insert_stmt = mysqli_prepare($conn, "
                    INSERT INTO payroll_history (
                        employee_id, employee_name, employee_code, month, year,
                        basic_salary, hra, conveyance, medical, special_allowance, bonus,
                        pf_deduction, esi_deduction, pt_deduction, tds_deduction, other_deductions,
                        working_days, present_days, leave_days, loss_of_pay_days,
                        overtime_hours, overtime_amount, incentives, reimbursements,
                        status, processed_by, processed_at, remarks
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?, NOW(), ?)
                ");
                
                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "issiiiddddddddddiiiiiddddis",
                    $employee_id,
                    $employee['full_name'],
                    $employee['employee_code'],
                    $month,
                    $year,
                    $basic_salary,
                    $hra,
                    $conveyance,
                    $medical,
                    $special_allowance,
                    $bonus,
                    $pf_deduction,
                    $esi_deduction,
                    $pt_deduction,
                    $tds_deduction,
                    $other_deductions,
                    $working_days,
                    $present_days,
                    $leave_days,
                    $loss_of_pay_days,
                    $overtime_hours,
                    $overtime_amount,
                    $incentives,
                    $reimbursements,
                    $current_employee_id,
                    $remarks
                );
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $payroll_id = mysqli_insert_id($conn);
                    
                    logActivity(
                        $conn,
                        'CREATE',
                        'payroll',
                        "Processed payroll for {$employee['full_name']} - {$month}/{$year}",
                        $payroll_id,
                        null,
                        null,
                        json_encode($_POST)
                    );
                    
                    $message = "Payroll processed successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error processing payroll: " . mysqli_error($conn);
                    $messageType = "danger";
                }
            }
        }
    }
    
    // Mark Payroll as Paid
    elseif (isset($_POST['action']) && $_POST['action'] === 'mark_paid' && ($isHr || $isAdmin)) {
        $payroll_id = (int)$_POST['payroll_id'];
        $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date']);
        $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode']);
        $transaction_id = mysqli_real_escape_string($conn, $_POST['transaction_id'] ?? '');
        
        $update_stmt = mysqli_prepare($conn, "
            UPDATE payroll_history 
            SET status = 'Paid',
                payment_date = ?,
                payment_mode = ?,
                transaction_id = ?
            WHERE id = ? AND status = 'Processed'
        ");
        
        mysqli_stmt_bind_param($update_stmt, "sssi", $payment_date, $payment_mode, $transaction_id, $payroll_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'payroll',
                "Marked payroll as paid ID: {$payroll_id}",
                $payroll_id,
                null,
                null,
                json_encode(['payment_date' => $payment_date, 'mode' => $payment_mode])
            );
            
            $message = "Payroll marked as paid!";
            $messageType = "success";
        } else {
            $message = "Error updating payroll: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
}

// ---------------- FETCH APPRAISAL REQUESTS ----------------
$appraisal_status = $_GET['appraisal_status'] ?? 'all';
$appraisal_search = trim($_GET['appraisal_search'] ?? '');

$appraisal_query = "
    SELECT a.*, 
           e.photo as employee_photo
    FROM appraisal_requests a
    LEFT JOIN employees e ON a.employee_id = e.id
    WHERE 1=1
";

// Filter by status
if ($appraisal_status !== 'all') {
    $appraisal_status = mysqli_real_escape_string($conn, $appraisal_status);
    $appraisal_query .= " AND a.status = '{$appraisal_status}'";
}

// Managers see only requests for their team
if ($isManager && !$isHr && !$isAdmin) {
    $appraisal_query .= " AND a.manager_id = {$current_employee_id}";
}

// Search
if (!empty($appraisal_search)) {
    $search_term = mysqli_real_escape_string($conn, $appraisal_search);
    $appraisal_query .= " AND (a.employee_name LIKE '%{$search_term}%' 
                              OR a.employee_code LIKE '%{$search_term}%'
                              OR a.request_no LIKE '%{$search_term}%')";
}

$appraisal_query .= " ORDER BY a.created_at DESC";

$appraisal_requests = mysqli_query($conn, $appraisal_query);

// ---------------- FETCH PAYROLL HISTORY ----------------
$payroll_month = $_GET['payroll_month'] ?? date('m');
$payroll_year = $_GET['payroll_year'] ?? date('Y');
$payroll_status = $_GET['payroll_status'] ?? 'all';
$payroll_search = trim($_GET['payroll_search'] ?? '');

$payroll_query = "
    SELECT p.*, e.photo as employee_photo
    FROM payroll_history p
    LEFT JOIN employees e ON p.employee_id = e.id
    WHERE p.month = ? AND p.year = ?
";

// Filter by status
if ($payroll_status !== 'all') {
    $payroll_status = mysqli_real_escape_string($conn, $payroll_status);
    $payroll_query .= " AND p.status = '{$payroll_status}'";
}

// Search
if (!empty($payroll_search)) {
    $search_term = mysqli_real_escape_string($conn, $payroll_search);
    $payroll_query .= " AND (p.employee_name LIKE '%{$search_term}%' 
                            OR p.employee_code LIKE '%{$search_term}%')";
}

$payroll_query .= " ORDER BY p.employee_name ASC";

$payroll_stmt = mysqli_prepare($conn, $payroll_query);
mysqli_stmt_bind_param($payroll_stmt, "ii", $payroll_month, $payroll_year);
mysqli_stmt_execute($payroll_stmt);
$payroll_history = mysqli_stmt_get_result($payroll_stmt);

// ---------------- FETCH EMPLOYEES FOR DROPDOWN ----------------
$employees_query = "
    SELECT id, full_name, employee_code, department, designation, date_of_joining
    FROM employees 
    WHERE employee_status = 'active'
    ORDER BY full_name ASC
";
$employees_result = mysqli_query($conn, $employees_query);

// Get appraisal statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_requests,
        COUNT(CASE WHEN status = 'Pending Manager' THEN 1 END) as pending_manager,
        COUNT(CASE WHEN status = 'Manager Approved' THEN 1 END) as pending_hr,
        COUNT(CASE WHEN status = 'HR Approved' THEN 1 END) as approved,
        COUNT(CASE WHEN status = 'Rejected' THEN 1 END) as rejected,
        AVG(CASE WHEN status = 'HR Approved' THEN increment_percentage END) as avg_increment
    FROM appraisal_requests
";

if ($isManager && !$isHr && !$isAdmin) {
    $stats_query .= " WHERE manager_id = {$current_employee_id}";
}

$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get payroll statistics
$payroll_stats_query = "
    SELECT 
        COUNT(*) as total_payrolls,
        SUM(gross_salary) as total_gross,
        SUM(net_salary) as total_net,
        COUNT(CASE WHEN status = 'Draft' THEN 1 END) as draft_count,
        COUNT(CASE WHEN status = 'Processed' THEN 1 END) as processed_count,
        COUNT(CASE WHEN status = 'Paid' THEN 1 END) as paid_count
    FROM payroll_history
    WHERE month = ? AND year = ?
";

$payroll_stats_stmt = mysqli_prepare($conn, $payroll_stats_query);
mysqli_stmt_bind_param($payroll_stats_stmt, "ii", $payroll_month, $payroll_year);
mysqli_stmt_execute($payroll_stats_stmt);
$payroll_stats_res = mysqli_stmt_get_result($payroll_stats_stmt);
$payroll_stats = mysqli_fetch_assoc($payroll_stats_res);

// ---------------- HELPER FUNCTIONS ----------------
function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount)
{
    if (!$amount)
        return '—';
    return '₹ ' . number_format($amount, 2);
}

function formatDate($date)
{
    if (!$date)
        return '—';
    return date('d M Y', strtotime($date));
}

function getAppraisalStatusBadge($status)
{
    $classes = [
        'Draft' => 'bg-secondary',
        'Pending Manager' => 'bg-warning text-dark',
        'Manager Approved' => 'bg-info',
        'HR Approved' => 'bg-success',
        'Rejected' => 'bg-danger',
        'Cancelled' => 'bg-dark'
    ];
    $icons = [
        'Draft' => 'bi-pencil',
        'Pending Manager' => 'bi-clock',
        'Manager Approved' => 'bi-check-circle',
        'HR Approved' => 'bi-check2-circle',
        'Rejected' => 'bi-x-circle',
        'Cancelled' => 'bi-dash-circle'
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    $icon = $icons[$status] ?? 'bi-question';
    return "<span class='badge {$class} px-3 py-2'><i class='bi {$icon} me-1'></i> {$status}</span>";
}

function getPayrollStatusBadge($status)
{
    $classes = [
        'Draft' => 'bg-secondary',
        'Processed' => 'bg-info',
        'Paid' => 'bg-success',
        'Cancelled' => 'bg-danger'
    ];
    $icons = [
        'Draft' => 'bi-pencil',
        'Processed' => 'bi-gear',
        'Paid' => 'bi-cash',
        'Cancelled' => 'bi-x-circle'
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
    <title>Payroll & Appraisal Management - TEK-C</title>
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

        .filter-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 18px;
            margin-bottom: 20px;
        }

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
            text-decoration: none;
        }

        .employee-avatar {
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
        }

        .employee-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .employee-name {
            font-weight: 900;
            font-size: 13px;
            color: #1f2937;
        }

        .employee-code {
            font-size: 11px;
            color: #6b7280;
            font-weight: 650;
        }

        .request-no {
            font-weight: 900;
            font-size: 13px;
            color: #1f2937;
        }

        .increment-badge {
            background: #d1fae5;
            color: #065f46;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 900;
            display: inline-block;
        }

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

        .nav-tabs {
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }

        .nav-tabs .nav-link {
            font-weight: 800;
            font-size: 13px;
            color: #6b7280;
            border: none;
            padding: 10px 16px;
        }

        .nav-tabs .nav-link:hover {
            color: var(--blue);
            border: none;
        }

        .nav-tabs .nav-link.active {
            color: var(--blue);
            border-bottom: 2px solid var(--blue);
            background: none;
        }

        .nav-tabs .nav-link i {
            margin-right: 6px;
        }

        .nav-tabs .nav-link .badge {
            margin-left: 6px;
            background: #e5e7eb;
            color: #374151;
        }

        .amount-positive {
            color: #059669;
            font-weight: 900;
        }

        .amount-negative {
            color: #dc2626;
            font-weight: 900;
        }

        th.actions-col,
        td.actions-col {
            width: 120px !important;
            white-space: nowrap !important;
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
                            <h1 class="h3 fw-bold text-dark mb-1">Payroll & Appraisal</h1>
                            <p class="text-muted mb-0">Manage employee appraisals and payroll processing</p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($isHr || $isAdmin): ?>
                                <button class="btn-primary-custom" onclick="openCreateAppraisalModal()">
                                    <i class="bi bi-plus-lg"></i> New Appraisal
                                </button>
                                <button class="btn-success-custom" onclick="openProcessPayrollModal()">
                                    <i class="bi bi-calculator"></i> Process Payroll
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <ul class="nav nav-tabs" id="payrollTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="appraisal-tab" data-bs-toggle="tab" data-bs-target="#appraisal" type="button" role="tab">
                                <i class="bi bi-graph-up"></i> Appraisal Requests
                                <span class="badge"><?php echo (int) ($stats['pending_manager'] + $stats['pending_hr']); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="payroll-tab" data-bs-toggle="tab" data-bs-target="#payroll" type="button" role="tab">
                                <i class="bi bi-cash-stack"></i> Payroll History
                                <span class="badge"><?php echo (int) ($payroll_stats['total_payrolls'] ?? 0); ?></span>
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="payrollTabContent">

                        <!-- APPRAISAL REQUESTS TAB -->
                        <div class="tab-pane fade show active" id="appraisal" role="tabpanel">

                            <!-- Appraisal Stats -->
                            <div class="row g-3 mb-3">
                                <div class="col-6 col-md-3">
                                    <div class="stat-card">
                                        <div class="stat-ic pending"><i class="bi bi-clock"></i></div>
                                        <div>
                                            <div class="stat-label">Pending Manager</div>
                                            <div class="stat-value"><?php echo (int) ($stats['pending_manager'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stat-card">
                                        <div class="stat-ic pending"><i class="bi bi-hourglass"></i></div>
                                        <div>
                                            <div class="stat-label">Pending HR</div>
                                            <div class="stat-value"><?php echo (int) ($stats['pending_hr'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stat-card">
                                        <div class="stat-ic approved"><i class="bi bi-check-circle"></i></div>
                                        <div>
                                            <div class="stat-label">Approved</div>
                                            <div class="stat-value"><?php echo (int) ($stats['approved'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stat-card">
                                        <div class="stat-ic total"><i class="bi bi-files"></i></div>
                                        <div>
                                            <div class="stat-label">Total Requests</div>
                                            <div class="stat-value"><?php echo (int) ($stats['total_requests'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Appraisal Filter -->
                            <div class="filter-card">
                                <form method="GET" class="row g-3 align-items-end">
                                    <input type="hidden" name="tab" value="appraisal">
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Status</label>
                                        <select name="appraisal_status" class="form-select form-select-sm">
                                            <option value="all">All Status</option>
                                            <option value="Pending Manager" <?php echo $appraisal_status === 'Pending Manager' ? 'selected' : ''; ?>>Pending Manager</option>
                                            <option value="Manager Approved" <?php echo $appraisal_status === 'Manager Approved' ? 'selected' : ''; ?>>Manager Approved</option>
                                            <option value="HR Approved" <?php echo $appraisal_status === 'HR Approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="Rejected" <?php echo $appraisal_status === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="appraisal_search" class="form-control form-control-sm"
                                            placeholder="Employee name, code, request no..." value="<?php echo e($appraisal_search); ?>">
                                    </div>

                                    <div class="col-md-3 d-flex justify-content-end gap-2">
                                        <button type="submit" class="btn-filter btn-sm">
                                            <i class="bi bi-funnel"></i> Apply
                                        </button>
                                        <a href="payroll.php?tab=appraisal" class="btn-reset btn-sm">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </a>
                                    </div>
                                </form>
                            </div>

                            <!-- Appraisal Table -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h3 class="panel-title">Appraisal Requests</h3>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Request #</th>
                                                <th>Employee</th>
                                                <th>Current CTC</th>
                                                <th>Proposed CTC</th>
                                                <th>Increment</th>
                                                <th>Type</th>
                                                <th>Effective From</th>
                                                <th>Status</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (mysqli_num_rows($appraisal_requests) === 0): ?>
                                                <tr>
                                                    <td colspan="9" class="text-center py-4">
                                                        <i class="bi bi-inbox" style="font-size: 2rem; color: #d1d5db;"></i>
                                                        <p class="mt-2 text-muted">No appraisal requests found</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php while ($request = mysqli_fetch_assoc($appraisal_requests)): 
                                                    $increment = $request['proposed_ctc'] - $request['current_ctc'];
                                                    $increment_percent = $request['increment_percentage'] ?? (($increment / $request['current_ctc']) * 100);
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="request-no"><?php echo e($request['request_no']); ?></div>
                                                            <small class="text-muted"><?php echo formatDate($request['created_at']); ?></small>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <div class="employee-avatar">
                                                                    <?php if (!empty($request['employee_photo'])): ?>
                                                                        <img src="../<?php echo e($request['employee_photo']); ?>" alt="Photo">
                                                                    <?php else: ?>
                                                                        <?php echo getInitials($request['employee_name']); ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div>
                                                                    <div class="employee-name"><?php echo e($request['employee_name']); ?></div>
                                                                    <div class="employee-code"><?php echo e($request['employee_code']); ?></div>
                                                                    <div class="employee-code"><?php echo e($request['designation']); ?></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold"><?php echo formatCurrency($request['current_ctc']); ?></div>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold amount-positive"><?php echo formatCurrency($request['proposed_ctc']); ?></div>
                                                        </td>
                                                        <td>
                                                            <span class="increment-badge">
                                                                <i class="bi bi-arrow-up"></i>
                                                                <?php echo number_format($increment_percent, 1); ?>%
                                                            </span>
                                                            <div class="employee-code">+<?php echo formatCurrency($increment); ?></div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo e($request['appraisal_type']); ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold"><?php echo formatDate($request['effective_from']); ?></div>
                                                        </td>
                                                        <td>
                                                            <?php echo getAppraisalStatusBadge($request['status']); ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <button class="btn-action" onclick="viewAppraisal(<?php echo $request['id']; ?>)" title="View">
                                                                <i class="bi bi-eye"></i>
                                                            </button>

                                                            <?php if ($request['status'] === 'Pending Manager' && $isManager && $request['manager_id'] == $current_employee_id): ?>
                                                                <button class="btn-action approve" onclick="openManagerApproveModal(<?php echo $request['id']; ?>, '<?php echo e($request['employee_name']); ?>')" title="Approve as Manager">
                                                                    <i class="bi bi-check-lg"></i>
                                                                </button>
                                                                <button class="btn-action reject" onclick="openRejectModal(<?php echo $request['id']; ?>, '<?php echo e($request['employee_name']); ?>')" title="Reject">
                                                                    <i class="bi bi-x-lg"></i>
                                                                </button>
                                                            <?php endif; ?>

                                                            <?php if ($request['status'] === 'Manager Approved' && ($isHr || $isAdmin)): ?>
                                                                <button class="btn-action approve" onclick="openHRApproveModal(<?php echo $request['id']; ?>, '<?php echo e($request['employee_name']); ?>')" title="Approve as HR">
                                                                    <i class="bi bi-check2-circle"></i>
                                                                </button>
                                                                <button class="btn-action reject" onclick="openRejectModal(<?php echo $request['id']; ?>, '<?php echo e($request['employee_name']); ?>')" title="Reject">
                                                                    <i class="bi bi-x-lg"></i>
                                                                </button>
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

                        <!-- PAYROLL HISTORY TAB -->
                        <div class="tab-pane fade" id="payroll" role="tabpanel">

                            <!-- Payroll Stats -->
                            <div class="row g-3 mb-3">
                                <div class="col-6 col-md-3">
                                    <div class="stat-card">
                                        <div class="stat-ic total"><i class="bi bi-calculator"></i></div>
                                        <div>
                                            <div class="stat-label">Total Payroll</div>
                                            <div class="stat-value"><?php echo formatCurrency($payroll_stats['total_net'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stat-card">
                                        <div class="stat-ic pending"><i class="bi bi-pencil"></i></div>
                                        <div>
                                            <div class="stat-label">Draft</div>
                                            <div class="stat-value"><?php echo (int) ($payroll_stats['draft_count'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stat-card">
                                        <div class="stat-ic approved"><i class="bi bi-gear"></i></div>
                                        <div>
                                            <div class="stat-label">Processed</div>
                                            <div class="stat-value"><?php echo (int) ($payroll_stats['processed_count'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="stat-card">
                                        <div class="stat-ic approved"><i class="bi bi-cash"></i></div>
                                        <div>
                                            <div class="stat-label">Paid</div>
                                            <div class="stat-value"><?php echo (int) ($payroll_stats['paid_count'] ?? 0); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Payroll Filter -->
                            <div class="filter-card">
                                <form method="GET" class="row g-3 align-items-end">
                                    <input type="hidden" name="tab" value="payroll">
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">Month</label>
                                        <select name="payroll_month" class="form-select form-select-sm">
                                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <option value="<?php echo $m; ?>" <?php echo $payroll_month == $m ? 'selected' : ''; ?>>
                                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Year</label>
                                        <select name="payroll_year" class="form-select form-select-sm">
                                            <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                                                <option value="<?php echo $y; ?>" <?php echo $payroll_year == $y ? 'selected' : ''; ?>>
                                                    <?php echo $y; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Status</label>
                                        <select name="payroll_status" class="form-select form-select-sm">
                                            <option value="all">All</option>
                                            <option value="Draft" <?php echo $payroll_status === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                                            <option value="Processed" <?php echo $payroll_status === 'Processed' ? 'selected' : ''; ?>>Processed</option>
                                            <option value="Paid" <?php echo $payroll_status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Search</label>
                                        <input type="text" name="payroll_search" class="form-control form-control-sm"
                                            placeholder="Employee name, code..." value="<?php echo e($payroll_search); ?>">
                                    </div>

                                    <div class="col-md-2 d-flex justify-content-end gap-2">
                                        <button type="submit" class="btn-filter btn-sm">
                                            <i class="bi bi-funnel"></i> Apply
                                        </button>
                                        <a href="payroll.php?tab=payroll&payroll_month=<?php echo date('m'); ?>&payroll_year=<?php echo date('Y'); ?>" class="btn-reset btn-sm">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </a>
                                    </div>
                                </form>
                            </div>

                            <!-- Payroll Table -->
                            <div class="panel">
                                <div class="panel-header">
                                    <h3 class="panel-title">Payroll for <?php echo date('F', mktime(0, 0, 0, $payroll_month, 1)); ?> <?php echo $payroll_year; ?></h3>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Employee</th>
                                                <th>Gross Salary</th>
                                                <th>Deductions</th>
                                                <th>Net Salary</th>
                                                <th>Days</th>
                                                <th>Status</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (mysqli_num_rows($payroll_history) === 0): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center py-4">
                                                        <i class="bi bi-inbox" style="font-size: 2rem; color: #d1d5db;"></i>
                                                        <p class="mt-2 text-muted">No payroll records found for this period</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php while ($payroll = mysqli_fetch_assoc($payroll_history)): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <div class="employee-avatar">
                                                                    <?php if (!empty($payroll['employee_photo'])): ?>
                                                                        <img src="../<?php echo e($payroll['employee_photo']); ?>" alt="Photo">
                                                                    <?php else: ?>
                                                                        <?php echo getInitials($payroll['employee_name']); ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div>
                                                                    <div class="employee-name"><?php echo e($payroll['employee_name']); ?></div>
                                                                    <div class="employee-code"><?php echo e($payroll['employee_code']); ?></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold"><?php echo formatCurrency($payroll['gross_salary']); ?></div>
                                                            <small class="text-muted">Basic: <?php echo formatCurrency($payroll['basic_salary']); ?></small>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold amount-negative">-<?php echo formatCurrency($payroll['total_deductions']); ?></div>
                                                            <?php if ($payroll['pf_deduction']): ?>
                                                                <small class="text-muted">PF: <?php echo formatCurrency($payroll['pf_deduction']); ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold amount-positive"><?php echo formatCurrency($payroll['net_salary']); ?></div>
                                                        </td>
                                                        <td>
                                                            <div><?php echo (int) ($payroll['present_days'] ?? 0); ?>/<?php echo (int) ($payroll['working_days'] ?? 0); ?></div>
                                                            <?php if ($payroll['overtime_hours']): ?>
                                                                <small class="text-info">OT: <?php echo $payroll['overtime_hours']; ?> hrs</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo getPayrollStatusBadge($payroll['status']); ?>
                                                        </td>
                                                        <td class="text-end">
                                                            <button class="btn-action" onclick="viewPayroll(<?php echo $payroll['id']; ?>)" title="View">
                                                                <i class="bi bi-eye"></i>
                                                            </button>

                                                            <?php if ($payroll['status'] === 'Draft' && ($isHr || $isAdmin)): ?>
                                                                <button class="btn-action approve" onclick="openProcessedModal(<?php echo $payroll['id']; ?>, '<?php echo e($payroll['employee_name']); ?>')" title="Mark as Processed">
                                                                    <i class="bi bi-check-lg"></i>
                                                                </button>
                                                            <?php endif; ?>

                                                            <?php if ($payroll['status'] === 'Processed' && ($isHr || $isAdmin)): ?>
                                                                <button class="btn-action approve" onclick="openPaidModal(<?php echo $payroll['id']; ?>, '<?php echo e($payroll['employee_name']); ?>')" title="Mark as Paid">
                                                                    <i class="bi bi-cash"></i>
                                                                </button>
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

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Create Appraisal Modal (HR only) -->
    <div class="modal fade" id="createAppraisalModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create_appraisal">

                    <div class="modal-header">
                        <h5 class="modal-title">Create New Appraisal Request</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Employee Selection -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label required">Select Employee</label>
                               <select name="employee_id" class="form-select select2" required>
    <option value="">Choose employee...</option>
    <?php 
    mysqli_data_seek($employees_result, 0);
    while ($emp = mysqli_fetch_assoc($employees_result)): 
    ?>
        <option value="<?php echo $emp['id']; ?>"
                data-ctc="0" 
                data-joining="<?php echo $emp['date_of_joining']; ?>">
            <?php echo e($emp['full_name']); ?> (<?php echo e($emp['employee_code']); ?>) - <?php echo e($emp['designation']); ?>
        </option>
    <?php endwhile; ?>
</select>
                            </div>
                        </div>

                        <!-- Current CTC (to be auto-filled) -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label required">Current CTC (LPA)</label>
                                <input type="number" step="0.01" name="current_ctc" class="form-control" id="current_ctc" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Date of Joining</label>
                                <input type="date" name="joining_date" class="form-control" id="joining_date" readonly>
                            </div>
                        </div>

                        <!-- Proposed CTC -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label required">Proposed CTC (LPA)</label>
                                <input type="number" step="0.01" name="proposed_ctc" class="form-control" id="proposed_ctc" required onchange="calculateIncrement()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Increment</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="increment_display" readonly>
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>

                        <!-- Appraisal Details -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label required">Appraisal Type</label>
                                <select name="appraisal_type" class="form-select" required>
                                    <option value="Annual">Annual</option>
                                    <option value="Promotion">Promotion</option>
                                    <option value="Performance">Performance</option>
                                    <option value="Market Correction">Market Correction</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Appraisal Date</label>
                                <input type="date" name="appraisal_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Effective From</label>
                                <input type="date" name="effective_from" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" required>
                            </div>
                        </div>

                        <!-- Performance Rating -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Performance Rating</label>
                                <select name="performance_rating" class="form-select">
                                    <option value="">Not Rated</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> - <?php echo $i == 1 ? 'Needs Improvement' : ($i == 2 ? 'Below Expectations' : ($i == 3 ? 'Meets Expectations' : ($i == 4 ? 'Exceeds Expectations' : 'Outstanding'))); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Reason -->
                        <div class="mb-3">
                            <label class="form-label required">Reason for Appraisal</label>
                            <textarea name="reason" class="form-control" rows="3" required></textarea>
                        </div>

                        <!-- Comments -->
                        <div class="mb-3">
                            <label class="form-label">Additional Comments</label>
                            <textarea name="comments" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Create Appraisal Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Manager Approve Modal -->
    <div class="modal fade" id="managerApproveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="manager_approve">
                    <input type="hidden" name="request_id" id="manager_request_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Manager Approval</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Approve appraisal request for <strong id="manager_employee_name"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label">Comments (Optional)</label>
                            <textarea name="manager_comments" class="form-control" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Approve as Manager</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- HR Approve Modal -->
    <div class="modal fade" id="hrApproveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="hr_approve">
                    <input type="hidden" name="request_id" id="hr_request_id">

                    <div class="modal-header">
                        <h5 class="modal-title">HR Approval</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Approve appraisal request for <strong id="hr_employee_name"></strong>?</p>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            This will finalize the appraisal and update the employee's salary.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Comments (Optional)</label>
                            <textarea name="hr_comments" class="form-control" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Approve as HR</button>
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
                    <input type="hidden" name="action" value="reject_request">
                    <input type="hidden" name="request_id" id="reject_request_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Reject Appraisal Request</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Reject appraisal request for <strong id="reject_employee_name"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label required">Reason for Rejection</label>
                            <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-danger-custom">Reject Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Process Payroll Modal (HR only) -->
    <div class="modal fade" id="processPayrollModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="process_payroll">

                    <div class="modal-header">
                        <h5 class="modal-title">Process Payroll</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label required">Month</label>
                                <select name="month" class="form-select" required>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Year</label>
                                <select name="year" class="form-select" required>
                                    <?php for ($y = date('Y') - 1; $y <= date('Y'); $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Employee</label>
                               <select name="employee_id" class="form-select select2" required>
    <option value="">Select Employee...</option>
    <?php 
    mysqli_data_seek($employees_result, 0);
    while ($emp = mysqli_fetch_assoc($employees_result)): 
    ?>
        <option value="<?php echo $emp['id']; ?>">
            <?php echo e($emp['full_name']); ?> (<?php echo e($emp['employee_code']); ?>)
        </option>
    <?php endwhile; ?>
</select>
                            </div>
                        </div>

                        <h6 class="fw-bold mb-3">Earnings</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label required">Basic Salary</label>
                                <input type="number" step="0.01" name="basic_salary" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">HRA</label>
                                <input type="number" step="0.01" name="hra" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Conveyance</label>
                                <input type="number" step="0.01" name="conveyance" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Medical Allowance</label>
                                <input type="number" step="0.01" name="medical" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Special Allowance</label>
                                <input type="number" step="0.01" name="special_allowance" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bonus</label>
                                <input type="number" step="0.01" name="bonus" class="form-control">
                            </div>
                        </div>

                        <h6 class="fw-bold mb-3">Deductions</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">PF Deduction</label>
                                <input type="number" step="0.01" name="pf_deduction" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">ESI Deduction</label>
                                <input type="number" step="0.01" name="esi_deduction" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Professional Tax</label>
                                <input type="number" step="0.01" name="pt_deduction" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">TDS</label>
                                <input type="number" step="0.01" name="tds_deduction" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Other Deductions</label>
                                <input type="number" step="0.01" name="other_deductions" class="form-control">
                            </div>
                        </div>

                        <h6 class="fw-bold mb-3">Attendance & Additions</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Working Days</label>
                                <input type="number" name="working_days" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Present Days</label>
                                <input type="number" name="present_days" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Leave Days</label>
                                <input type="number" name="leave_days" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">LOP Days</label>
                                <input type="number" name="loss_of_pay_days" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Overtime Hours</label>
                                <input type="number" step="0.5" name="overtime_hours" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Overtime Amount</label>
                                <input type="number" step="0.01" name="overtime_amount" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Incentives</label>
                                <input type="number" step="0.01" name="incentives" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Reimbursements</label>
                                <input type="number" step="0.01" name="reimbursements" class="form-control">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Process Payroll</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Mark as Paid Modal -->
    <div class="modal fade" id="paidModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="payroll_id" id="paid_payroll_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Mark Payroll as Paid</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label required">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Payment Mode</label>
                            <select name="payment_mode" class="form-select" required>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Cash">Cash</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transaction ID / Reference</label>
                            <input type="text" name="transaction_id" class="form-control">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Mark as Paid</button>
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
            // Initialize Select2
            $('.select2').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });

            // Set active tab based on URL parameter
            var urlParams = new URLSearchParams(window.location.search);
            var activeTab = urlParams.get('tab');
            if (activeTab === 'payroll') {
                $('#payroll-tab').tab('show');
            }

            // Update URL when tab changes
            $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
                var target = $(e.target).attr('id');
                var url = new URL(window.location.href);
                if (target === 'payroll-tab') {
                    url.searchParams.set('tab', 'payroll');
                } else {
                    url.searchParams.set('tab', 'appraisal');
                }
                window.history.pushState({}, '', url);
            });
        });

        function calculateIncrement() {
            var current = parseFloat($('#current_ctc').val()) || 0;
            var proposed = parseFloat($('#proposed_ctc').val()) || 0;
            if (current > 0 && proposed > 0) {
                var increment = ((proposed - current) / current * 100).toFixed(1);
                $('#increment_display').val(increment);
            }
        }

        function openCreateAppraisalModal() {
            new bootstrap.Modal(document.getElementById('createAppraisalModal')).show();
        }

        function openManagerApproveModal(id, employeeName) {
            $('#manager_request_id').val(id);
            $('#manager_employee_name').text(employeeName);
            new bootstrap.Modal(document.getElementById('managerApproveModal')).show();
        }

        function openHRApproveModal(id, employeeName) {
            $('#hr_request_id').val(id);
            $('#hr_employee_name').text(employeeName);
            new bootstrap.Modal(document.getElementById('hrApproveModal')).show();
        }

        function openRejectModal(id, employeeName) {
            $('#reject_request_id').val(id);
            $('#reject_employee_name').text(employeeName);
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }

        function openProcessPayrollModal() {
            new bootstrap.Modal(document.getElementById('processPayrollModal')).show();
        }

        function openPaidModal(id, employeeName) {
            $('#paid_payroll_id').val(id);
            new bootstrap.Modal(document.getElementById('paidModal')).show();
        }

        function viewAppraisal(id) {
            window.location.href = 'view-appraisal.php?id=' + id;
        }

        function viewPayroll(id) {
            window.location.href = 'view-payroll.php?id=' + id;
        }
    </script>
</body>

</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>