<?php
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

$current_employee_id = $_SESSION['employee_id'] ?? 1;
$current_employee_name = $_SESSION['employee_name'] ?? 'Admin';

$conn = get_db_connection();
if (!$conn) { 
    $_SESSION['flash_error'] = "Database connection failed.";
    header("Location: attendance-regulations.php");
    exit();
}

// Check admin permissions
$emp_stmt = mysqli_prepare($conn, "SELECT designation FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

$is_admin = in_array($employee['designation'] ?? '', ['Director', 'Manager', 'HR']);
if (!$is_admin) {
    $_SESSION['flash_error'] = "You don't have permission to perform this action.";
    header("Location: attendance-regulations.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // Get current status
    $stmt = mysqli_prepare($conn, "SELECT is_active, regulation_name FROM attendance_regulations WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $reg = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($reg) {
        $new_status = $reg['is_active'] ? 0 : 1;
        $action = $new_status ? 'ACTIVATE' : 'DEACTIVATE';
        
        $update_stmt = mysqli_prepare($conn, "UPDATE attendance_regulations SET is_active = ? WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "ii", $new_status, $id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            // FIXED: Using logActivity with correct signature (capital A, not underscore)
            logActivity($conn, 
                $action == 'ACTIVATE' ? 'RESTORE' : 'SOFT_DELETE', 
                'attendance_regulations', 
                ($action == 'ACTIVATE' ? 'Activated' : 'Deactivated') . ' attendance regulation: ' . $reg['regulation_name'], 
                $id, 
                $reg['regulation_name'], 
                json_encode(['is_active' => $reg['is_active']]), 
                json_encode(['is_active' => $new_status])
            );
            
            $_SESSION['flash_success'] = "Regulation " . ($action == 'ACTIVATE' ? 'activated' : 'deactivated') . " successfully!";
        } else {
            $_SESSION['flash_error'] = "Failed to update regulation status.";
        }
        mysqli_stmt_close($update_stmt);
    } else {
        $_SESSION['flash_error'] = "Regulation not found.";
    }
} else {
    $_SESSION['flash_error'] = "Invalid regulation ID.";
}

header("Location: attendance-regulations.php");
exit();
?>