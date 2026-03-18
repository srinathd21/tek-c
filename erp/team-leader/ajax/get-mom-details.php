<?php
// ajax/get-mom-details.php
session_start();
require_once '../includes/db-config.php';

header('Content-Type: application/json');

if (empty($_SESSION['employee_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = get_db_connection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$momId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($momId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid MOM ID']);
    exit;
}

$employeeId = (int)$_SESSION['employee_id'];

// Get MOM details with site information
$query = "
    SELECT 
        m.*,
        s.project_name,
        s.project_location,
        c.client_name
    FROM mom_reports m
    INNER JOIN sites s ON s.id = m.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE m.id = ? AND m.employee_id = ?
";

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $momId, $employeeId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$mom = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$mom) {
    echo json_encode(['success' => false, 'message' => 'MOM not found or access denied']);
    exit;
}

// Decode JSON fields
$mom['agenda_json'] = $mom['agenda_json'] ? json_decode($mom['agenda_json'], true) : [];
$mom['attendees_json'] = $mom['attendees_json'] ? json_decode($mom['attendees_json'], true) : [];
$mom['minutes_json'] = $mom['minutes_json'] ? json_decode($mom['minutes_json'], true) : [];
$mom['amended_json'] = $mom['amended_json'] ? json_decode($mom['amended_json'], true) : [];

echo json_encode(['success' => true, 'mom' => $mom]);

mysqli_close($conn);
?>