<?php
// includes/activity-logger.php
// STRICT current DB activity logger for TEK-C.
// This version NEVER inserts user_id/user_name/user_role/module_id/module_name.
// Use this when your activity_logs table has employee-style columns only.

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

if (!function_exists('normalizeActivityData')) {
    function normalizeActivityData($value) {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }
}

if (!function_exists('logActivity')) {
    function logActivity(
        $conn,
        $activity_type,
        $module,
        $description,
        $reference_id = null,
        $reference_name = null,
        $old_data = null,
        $new_data = null
    ) {
        if (!$conn || !activityTableExists($conn, 'activity_logs')) {
            return false;
        }

        $employee_id = $_SESSION['employee_id'] ?? null;
        $employee_name = $_SESSION['employee_name'] ?? $_SESSION['name'] ?? 'System';
        $username = $_SESSION['username'] ?? '';
        $designation = $_SESSION['designation'] ?? '';
        $department = $_SESSION['department'] ?? '';
        $ip_address = getClientIP();

        $old_data = normalizeActivityData($old_data);
        $new_data = normalizeActivityData($new_data);

        /*
         * Current activity_logs columns from your DB:
         * employee_id, employee_name, username, designation, department,
         * activity_type, module, description, reference_id, ip_address, created_at
         *
         * Optional columns are included only if they exist:
         * reference_name, old_data, new_data
         */
        $columnMap = [
            'employee_id'    => ['i', $employee_id],
            'employee_name'  => ['s', $employee_name],
            'username'       => ['s', $username],
            'designation'    => ['s', $designation],
            'department'     => ['s', $department],
            'activity_type'  => ['s', $activity_type],
            'module'         => ['s', $module],
            'description'    => ['s', $description],
            'reference_id'   => ['i', $reference_id],
            'reference_name' => ['s', $reference_name],
            'old_data'       => ['s', $old_data],
            'new_data'       => ['s', $new_data],
            'ip_address'     => ['s', $ip_address],
        ];

        $columns = [];
        $values = [];
        $types = '';

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
            error_log("Activity log prepare failed: " . mysqli_error($conn));
            return false;
        }

        mysqli_stmt_bind_param($stmt, $types, ...$values);
        $result = mysqli_stmt_execute($stmt);

        if (!$result) {
            error_log("Activity log execute failed: " . mysqli_stmt_error($stmt));
        }

        mysqli_stmt_close($stmt);

        return $result;
    }
}
