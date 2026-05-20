<?php
// includes/activity-logger.php
// Compatible activity logger for TEK-C.
// Inserts only the columns that actually exist in activity_logs.
// This prevents errors like: Unknown column 'user_id' in 'INSERT INTO'

if (!function_exists('getClientIP')) {
    function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
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
        return $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getCurrentUserName')) {
    function getCurrentUserName() {
        return $_SESSION['employee_name'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'System';
    }
}

if (!function_exists('getCurrentUsername')) {
    function getCurrentUsername() {
        return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['employee_name'] ?? 'System';
    }
}

if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole() {
        return $_SESSION['designation'] ?? $_SESSION['user_role'] ?? 'Unknown';
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

        $res = mysqli_query(
            $conn,
            "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'"
        );

        if (!$res) {
            return false;
        }

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

        if (!$res) {
            return false;
        }

        $exists = mysqli_num_rows($res) > 0;
        mysqli_free_result($res);

        return $exists;
    }
}

if (!function_exists('normalizeActivityJson')) {
    function normalizeActivityJson($value) {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
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
        if (!$conn || !activityTableExists($conn, 'activity_logs')) {
            return false;
        }

        $old_data = normalizeActivityJson($old_data);
        $new_data = normalizeActivityJson($new_data);

        $employee_id   = getCurrentUserId();
        $employee_name = getCurrentUserName();
        $username      = getCurrentUsername();
        $designation   = getCurrentUserRole();
        $department    = getCurrentDepartment();
        $ip_address    = getClientIP();
        $user_agent    = getUserAgent();

        $columns = [];
        $values  = [];
        $types   = '';

        /*
         * Supports your employee-style activity_logs:
         * employee_id, employee_name, username, designation, department,
         * activity_type, module, description, reference_id, reference_name,
         * old_data, new_data, ip_address, user_agent
         *
         * Also supports old user-style tables if those columns exist:
         * user_id, user_name, user_role, action_type, module_id, module_name
         */
        $columnMap = [
            // Employee-style columns
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

            // Old user-style columns, only inserted if they exist
            'user_id'     => ['i', $employee_id],
            'user_name'   => ['s', $employee_name],
            'user_role'   => ['s', $designation],
            'action_type' => ['s', $action_type],
            'module_id'   => ['i', $module_id],
            'module_name' => ['s', $module_name],
        ];

        foreach ($columnMap as $column => $pair) {
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
