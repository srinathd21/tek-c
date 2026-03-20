<?php
// get-quotation-counts.php
session_start();
require_once 'includes/db-config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$conn = get_db_connection();
$empId = (int)$_SESSION['employee_id'];

// Get counts for different quotation statuses
$counts = [
    'assigned' => 0,
    'in_progress' => 0,
    'to_submit' => 0
];

// Count assigned quotations (new requests assigned to this TL)
$query = "SELECT COUNT(*) as count FROM quotation_requests 
          WHERE project_engineer_id = ? AND status = 'Assigned'";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $empId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if ($row = mysqli_fetch_assoc($result)) {
    $counts['assigned'] = (int)$row['count'];
}
mysqli_stmt_close($stmt);

// Count in-progress quotations (TL working on them)
$query = "SELECT COUNT(*) as count FROM quotation_requests 
          WHERE project_engineer_id = ? AND status = 'Quotations Received'";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $empId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if ($row = mysqli_fetch_assoc($result)) {
    $counts['in_progress'] = (int)$row['count'];
}
mysqli_stmt_close($stmt);

// Count ready to submit (quotations collected but not submitted)
$query = "SELECT COUNT(*) as count FROM quotation_requests 
          WHERE project_engineer_id = ? AND status = 'Quotations Received'";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $empId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if ($row = mysqli_fetch_assoc($result)) {
    $counts['to_submit'] = (int)$row['count'];
}
mysqli_stmt_close($stmt);

mysqli_close($conn);
echo json_encode($counts);
?>