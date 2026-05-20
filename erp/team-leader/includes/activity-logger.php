<?php
// includes/activity-logger.php
// Compatible activity logger for TEK-C employee-based activity_logs table.
// This version avoids unknown-column errors by inserting only columns that exist.

if (!function_exists('getClientIP')) {
    function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        if (!empty($_SERVER['HTTP_X_FORWARDED'])) return $_SERVER['HTTP_X_FORWARDED'];
        if (!empty($_SERVER['HTTP_FORWARDED_FOR'])) return $_SERVER['HTTP_FORWARDED_FOR'];
        if (!empty($_SERVER['HTTP_FORWARDED'])) return $_SERVER['HTTP_FORWARDED'];
        if (!empty($_SERVER['REMOTE_ADDR'])) return $_SERVER['REMOTE_ADDR'];
        return 'UNKNOWN';
    }
}

if (!function_exists('getUserAgent')) {
    function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
    }
}

if (!function_exists('getCurrentEmployeeId')) {
    function getCurrentEmployeeId() {
        return $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getCurrentEmployeeName')) {
    function getCurrentEmployeeName() {
        return $_SESSION['employee_name'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'System';
    }
}

if (!function_exists('getCurrentUsername')) {
    function getCurrentUsername() {
        return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['employee_name'] ?? 'System';
    }
}

if (!function_exists('getCurrentDesignation')) {
    function getCurrentDesignation() {
        return $_SESSION['designation'] ?? $_SESSION['user_role'] ?? '';
    }
}

if (!function_exists('getCurrentDepartment')) {
    function getCurrentDepartment() {
        return $_SESSION['department'] ?? '';
    }
}

if (!function_exists('activityTableExists')) {
    function activityTableExists($conn, string $table): bool {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $sql = "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'";
        $res = mysqli_query($conn, $sql);
        if (!$res) return false;
        $exists = mysqli_num_rows($res) > 0;
        mysqli_free_result($res);
        return $exists;
    }
}

if (!function_exists('activityColumnExists')) {
    function activityColumnExists($conn, string $table, string $column): bool {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $columnEsc = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
        if (!$res) return false;
        $exists = mysqli_num_rows($res) > 0;
        mysqli_free_result($res);
        return $exists;
    }
}

if (!function_exists('logActivity')) {
    function logActivity($conn, $action_type, $module, $description, $module_id = null, $module_name = null, $old_data = null, $new_data = null) {
        if (!$conn || !activityTableExists($conn, 'activity_logs')) {
            return false;
        }

        $employee_id   = getCurrentEmployeeId();
        $employee_name = getCurrentEmployeeName();
        $username      = getCurrentUsername();
        $designation   = getCurrentDesignation();
        $department    = getCurrentDepartment();
        $ip_address    = getClientIP();
        $user_agent    = getUserAgent();

        if (is_array($old_data) || is_object($old_data)) {
            $old_data = json_encode($old_data, JSON_UNESCAPED_UNICODE);
        }

        if (is_array($new_data) || is_object($new_data)) {
            $new_data = json_encode($new_data, JSON_UNESCAPED_UNICODE);
        }

        $columns = [];
        $values  = [];
        $types   = '';

        // Your current DB style.
        $employeeStyleMap = [
            'employee_id'    => ['i', $employee_id],
            'employee_name'  => ['s', $employee_name],
            'username'       => ['s', $username],
            'designation'    => ['s', $designation],
            'department'     => ['s', $department],
            'activity_type'  => ['s', $action_type],
            'module'         => ['s', $module],
            'description'    => ['s', $description],
            'reference_id'   => ['i', $module_id],
            'reference_name' => ['s', $module_name],
            'old_data'       => ['s', $old_data],
            'new_data'       => ['s', $new_data],
            'ip_address'     => ['s', $ip_address],
            'user_agent'     => ['s', $user_agent],
        ];

        // Older/admin logger style, only used if these columns exist.
        $userStyleMap = [
            'user_id'     => ['i', $employee_id],
            'user_name'   => ['s', $employee_name],
            'user_role'   => ['s', $designation],
            'action_type' => ['s', $action_type],
            'module'      => ['s', $module],
            'module_id'   => ['i', $module_id],
            'module_name' => ['s', $module_name],
            'description' => ['s', $description],
            'old_data'    => ['s', $old_data],
            'new_data'    => ['s', $new_data],
            'ip_address'  => ['s', $ip_address],
            'user_agent'  => ['s', $user_agent],
        ];

        // Merge both styles. Existing columns only will be inserted.
        $map = array_merge($employeeStyleMap, $userStyleMap);

        foreach ($map as $column => $pair) {
            if (activityColumnExists($conn, 'activity_logs', $column)) {
                $columns[] = "`$column`";
                $types .= $pair[0];
                $values[] = $pair[1];
            }
        }

        if (empty($columns)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO activity_logs (" . implode(',', $columns) . ") VALUES ($placeholders)";
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
