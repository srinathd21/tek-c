<?php
// includes/db-config.php
// Database configuration constants

define('DB_HOST', 'srv2204.hstgr.io');
define('DB_USER', 'u209621005_tekc');
define('DB_PASS', 'Ariharan@2025');
define('DB_NAME', 'u209621005_tekc');

// Create connection function
function get_db_connection() {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }
    
    // Set charset
    mysqli_set_charset($conn, "utf8mb4");
    
    return $conn;
}
?>
  <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
  <link rel="manifest" href="assets/favicon/site.webmanifest">