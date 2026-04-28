<?php
// ajax_get_site_details.php - Fetch site details for AJAX
session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
if ($siteId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid site ID']);
    exit;
}

$sql = "SELECT contractor, scope_of_work, architect, pmc FROM sites WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $siteId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$site = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($site) {
    echo json_encode([
        'success' => true,
        'contractor' => $site['contractor'] ?? '',
        'scope_of_work' => $site['scope_of_work'] ?? '',
        'architect' => $site['architect'] ?? '',
        'pmc' => $site['pmc'] ?? ''
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Site not found']);
}