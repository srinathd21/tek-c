<?php
// includes/db-config.php
// Database configuration constants

define('DB_HOST', 'localhost');
define('DB_USER', 'u966043993_tekc1');
define('DB_PASS', 'Ariharan@2025');
define('DB_NAME', 'u966043993_tekc1');

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