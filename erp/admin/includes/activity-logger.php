<?php
// includes/activity-logger.php
// Safe helper functions for logging activities

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('tableColumnExists')) {
    function tableColumnExists($conn, $table, $column) {
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);

        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        return ($res && mysqli_num_rows($res) > 0);
    }
}

if (!function_exists('getClientIP')) {
    function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED'])) {
            return $_SERVER['HTTP_X_FORWARDED'];
        }

        if (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_FORWARDED_FOR'];
        }

        if (!empty($_SERVER['HTTP_FORWARDED'])) {
            return $_SERVER['HTTP_FORWARDED'];
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return 'UNKNOWN';
    }
}

if (!function_exists('getUserAgent')) {
    function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
    }
}

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return $_SESSION['employee_id']
            ?? $_SESSION['user_id']
            ?? $_SESSION['id']
            ?? null;
    }
}

if (!function_exists('getCurrentUserName')) {
    function getCurrentUserName() {
        return $_SESSION['employee_name']
            ?? $_SESSION['user_name']
            ?? $_SESSION['full_name']
            ?? 'System';
    }
}

if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole() {
        return $_SESSION['designation']
            ?? $_SESSION['user_role']
            ?? $_SESSION['role']
            ?? 'Unknown';
    }
}

if (!function_exists('logActivity')) {
    function logActivity(
        $conn,
        $action_type,
        $module,
        $description,
        $module_id = null,
        $module_name = null,
        $old_data = null,
        $new_data = null
    ) {
        if (!$conn) {
            return false;
        }

        if (!tableColumnExists($conn, 'activity_logs', 'id')) {
            return false;
        }

        $current_id = getCurrentUserId();
        $user_name = getCurrentUserName();
        $user_role = getCurrentUserRole();
        $ip_address = getClientIP();
        $user_agent = getUserAgent();

        if (is_array($old_data) || is_object($old_data)) {
            $old_data = json_encode($old_data, JSON_UNESCAPED_UNICODE);
        }

        if (is_array($new_data) || is_object($new_data)) {
            $new_data = json_encode($new_data, JSON_UNESCAPED_UNICODE);
        }

        $idColumn = null;

        if (tableColumnExists($conn, 'activity_logs', 'employee_id')) {
            $idColumn = 'employee_id';
        } elseif (tableColumnExists($conn, 'activity_logs', 'user_id')) {
            $idColumn = 'user_id';
        }

        $columns = [];
        $values = [];
        $types = '';

        if ($idColumn !== null) {
            $columns[] = $idColumn;
            $values[] = $current_id;
            $types .= 'i';
        }

        if (tableColumnExists($conn, 'activity_logs', 'user_name')) {
            $columns[] = 'user_name';
            $values[] = $user_name;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'employee_name')) {
            $columns[] = 'employee_name';
            $values[] = $user_name;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'user_role')) {
            $columns[] = 'user_role';
            $values[] = $user_role;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'role')) {
            $columns[] = 'role';
            $values[] = $user_role;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'action_type')) {
            $columns[] = 'action_type';
            $values[] = $action_type;
            $types .= 's';
        } elseif (tableColumnExists($conn, 'activity_logs', 'action')) {
            $columns[] = 'action';
            $values[] = $action_type;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'module')) {
            $columns[] = 'module';
            $values[] = $module;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'module_id')) {
            $columns[] = 'module_id';
            $values[] = $module_id;
            $types .= 'i';
        }

        if (tableColumnExists($conn, 'activity_logs', 'module_name')) {
            $columns[] = 'module_name';
            $values[] = $module_name;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'description')) {
            $columns[] = 'description';
            $values[] = $description;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'old_data')) {
            $columns[] = 'old_data';
            $values[] = $old_data;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'new_data')) {
            $columns[] = 'new_data';
            $values[] = $new_data;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'ip_address')) {
            $columns[] = 'ip_address';
            $values[] = $ip_address;
            $types .= 's';
        }

        if (tableColumnExists($conn, 'activity_logs', 'user_agent')) {
            $columns[] = 'user_agent';
            $values[] = $user_agent;
            $types .= 's';
        }

        if (empty($columns)) {
            return false;
        }

        $columnSql = '`' . implode('`, `', $columns) . '`';
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO activity_logs ($columnSql) VALUES ($placeholders)";
        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, $types, ...$values);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $result;
    }
}
?>