<?php
// delete-quotation-request.php
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

$user_id = (int)$_SESSION['employee_id'];
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($request_id <= 0) {
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('Invalid request ID'));
    exit();
}

// Check if the request exists and belongs to the user
$check_query = "SELECT id, status, title FROM quotation_requests WHERE id = ? AND requested_by = ?";
$stmt = mysqli_prepare($conn, $check_query);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$request) {
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('Request not found or you do not have permission to delete it'));
    exit();
}

// Only allow deletion of draft requests
if ($request['status'] !== 'Draft') {
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('Only draft requests can be deleted'));
    exit();
}

// Delete the request
$delete_query = "DELETE FROM quotation_requests WHERE id = ? AND requested_by = ?";
$stmt = mysqli_prepare($conn, $delete_query);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $user_id);

if (mysqli_stmt_execute($stmt)) {
    // Log the activity
    $log_query = "INSERT INTO activity_logs (user_id, user_name, user_role, action_type, module, module_id, module_name, description, created_at) 
                  VALUES (?, ?, (SELECT designation FROM employees WHERE id = ?), 'DELETE', 'quotation_requests', ?, ?, ?, NOW())";
    
    $log_stmt = mysqli_prepare($conn, $log_query);
    if ($log_stmt) {
        $user_name = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? '';
        $description = 'Deleted quotation request: ' . $request['title'];
        mysqli_stmt_bind_param($log_stmt, "isisis", $user_id, $user_name, $user_id, $request_id, $request['title'], $description);
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
    }
    
    mysqli_stmt_close($stmt);
    header('Location: my-quotation-requests.php?status=success&message=' . urlencode('Quotation request deleted successfully'));
} else {
    mysqli_stmt_close($stmt);
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('Failed to delete request: ' . mysqli_error($conn)));
}

mysqli_close($conn);
exit();
?>