<?php
// includes/activity-logger.php
// Helper functions for logging activities

if (!function_exists('getClientIP')) {
    function getClientIP() {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN';
        return $ipaddress;
    }
}

if (!function_exists('getUserAgent')) {
    function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
    }
}

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? $_SESSION['employee_id'] ?? null;
    }
}

if (!function_exists('getCurrentUserName')) {
    function getCurrentUserName() {
        return $_SESSION['user_name'] ?? $_SESSION['employee_name'] ?? 'System';
    }
}

if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole() {
        return $_SESSION['user_role'] ?? $_SESSION['designation'] ?? 'Unknown';
    }
}

if (!function_exists('logActivity')) {
    function logActivity($conn, $action_type, $module, $description, $module_id = null, $module_name = null, $old_data = null, $new_data = null) {
        $user_id = getCurrentUserId();
        $user_name = getCurrentUserName();
        $user_role = getCurrentUserRole();
        $ip_address = getClientIP();
        $user_agent = getUserAgent();
        
        $stmt = mysqli_prepare($conn, "INSERT INTO activity_logs 
            (user_id, user_name, user_role, action_type, module, module_id, module_name, description, old_data, new_data, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "issssisissss", 
                $user_id, $user_name, $user_role, $action_type, $module, $module_id, $module_name, 
                $description, $old_data, $new_data, $ip_address, $user_agent);
            $result = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return $result;
        }
        return false;
    }
}