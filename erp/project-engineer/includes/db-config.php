<?php
// includes/db-config.php

// =====================================
// SET PHP TIMEZONE
// =====================================

date_default_timezone_set('Asia/Kolkata');


// =====================================
// DATABASE CONFIGURATION
// =====================================

define('DB_HOST', 'srv2204.hstgr.io');
define('DB_USER', 'u209621005_tekc');
define('DB_PASS', 'Ariharan@2025');
define('DB_NAME', 'u209621005_tekc');


// =====================================
// DATABASE CONNECTION FUNCTION
// =====================================

function get_db_connection() {

    // REUSE SAME CONNECTION
    static $conn = null;


    // =====================================
    // CREATE LOG DIRECTORY
    // =====================================

    $logDir = dirname(__DIR__) . '/dblogs';

    // CREATE FOLDER IF NOT EXISTS
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }


    // =====================================
    // LOG FILE PATH
    // =====================================

    $logFile = $logDir . '/db-requests.log';


    // =====================================
    // REQUEST LOG DATA
    // =====================================

    $logData = [
        'time'      => date('Y-m-d H:i:s'),
        'file'      => $_SERVER['PHP_SELF'] ?? 'unknown',
        'url'       => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];


    // =====================================
    // SAVE LOG
    // =====================================

    file_put_contents(
        $logFile,
        json_encode($logData) . PHP_EOL,
        FILE_APPEND
    );


    // =====================================
    // AUTO DELETE LARGE LOG FILE
    // =====================================

    if (file_exists($logFile) && filesize($logFile) > 5000000) {

        unlink($logFile);

        file_put_contents(
            $logFile,
            "Log file auto cleared at " . date('Y-m-d H:i:s') . PHP_EOL,
            FILE_APPEND
        );
    }


    // =====================================
    // CREATE DB CONNECTION ONLY ONCE
    // =====================================

    if ($conn === null) {

        $conn = mysqli_connect(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );


        // =====================================
        // CHECK CONNECTION
        // =====================================

        if (!$conn) {

            die(
                "Database Connection Failed : " .
                mysqli_connect_error()
            );
        }


        // =====================================
        // SET UTF8 CHARSET
        // =====================================

        mysqli_set_charset($conn, "utf8mb4");


        // =====================================
        // SET MYSQL TIMEZONE
        // =====================================

        mysqli_query($conn, "SET time_zone = '+05:30'");
    }


    // =====================================
    // RETURN CONNECTION
    // =====================================

    return $conn;
}

?>