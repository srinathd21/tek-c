<?php
// ajax/get-site-details.php
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

$siteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($siteId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid site ID']);
    exit;
}

// Get site details with all joins
$query = "
    SELECT 
        s.*,
        c.id as client_id,
        c.client_name,
        c.mobile_number as client_phone,
        c.email as client_email,
        c.company_name as client_company,
        c.office_address as client_address,
        c.gst_number as client_gst,
        c.pan_number as client_pan,
        m.id as manager_id,
        m.full_name as manager_name,
        m.email as manager_email,
        m.mobile_number as manager_phone,
        tl.id as team_lead_id,
        tl.full_name as team_lead_name,
        tl.email as team_lead_email
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    LEFT JOIN employees m ON m.id = s.manager_employee_id
    LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id
    WHERE s.id = ? AND s.deleted_at IS NULL
";

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    exit;
}

mysqli_stmt_bind_param($stmt, "i", $siteId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$site = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$site) {
    echo json_encode(['success' => false, 'message' => 'Site not found']);
    exit;
}

// Get project engineers
$engineers = [];
$engQuery = "
    SELECT e.id, e.full_name, e.designation, e.email, e.mobile_number, e.photo
    FROM site_project_engineers spe
    INNER JOIN employees e ON e.id = spe.employee_id
    WHERE spe.site_id = ? AND e.employee_status = 'active'
";
$engStmt = mysqli_prepare($conn, $engQuery);
if ($engStmt) {
    mysqli_stmt_bind_param($engStmt, "i", $siteId);
    mysqli_stmt_execute($engStmt);
    $engResult = mysqli_stmt_get_result($engStmt);
    while ($eng = mysqli_fetch_assoc($engResult)) {
        $engineers[] = $eng;
    }
    mysqli_stmt_close($engStmt);
}

$site['engineers'] = $engineers;

echo json_encode(['success' => true, 'site' => $site]);

mysqli_close($conn);
?>