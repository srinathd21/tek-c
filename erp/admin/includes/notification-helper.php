<?php
// includes/notification-helper.php
// Common notification helper for all panels.
// Use with current DB table: notifications.
// Required common columns supported:
// employee_id, title, message, module, reference_id, link, is_read, created_at
// Optional columns supported if available:
// type, icon, priority

if (!function_exists('nh_table_exists')) {
    function nh_table_exists($conn, string $table): bool {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        if (!$res) return false;
        $ok = mysqli_num_rows($res) > 0;
        mysqli_free_result($res);
        return $ok;
    }
}

if (!function_exists('nh_column_exists')) {
    function nh_column_exists($conn, string $table, string $column): bool {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $col = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
        if (!$res) return false;
        $ok = mysqli_num_rows($res) > 0;
        mysqli_free_result($res);
        return $ok;
    }
}

if (!function_exists('createNotification')) {
    function createNotification(
        $conn,
        int $employeeId,
        string $title,
        string $message,
        string $module = '',
        ?int $referenceId = null,
        string $link = '',
        string $type = 'info',
        string $priority = 'normal'
    ): bool {
        if ($employeeId <= 0 || !$conn || !nh_table_exists($conn, 'notifications')) {
            return false;
        }

        $columns = [];
        $values = [];
        $types = '';

        $map = [
            'employee_id'  => ['i', $employeeId],
            'title'        => ['s', $title],
            'message'      => ['s', $message],
            'type'         => ['s', $type],
            'module'       => ['s', $module],
            'reference_id' => ['i', $referenceId],
            'link'         => ['s', $link],
            'priority'     => ['s', $priority],
            'is_read'      => ['i', 0],
            'created_at'   => ['raw', 'NOW()'],
        ];

        foreach ($map as $column => $pair) {
            if (nh_column_exists($conn, 'notifications', $column)) {
                $columns[] = "`$column`";

                if ($pair[0] === 'raw') {
                    $values[] = $pair[1];
                } else {
                    $values[] = '?';
                    $types .= $pair[0];
                    $bindValues[] = $pair[1];
                }
            }
        }

        if (empty($columns)) {
            return false;
        }

        $sql = "INSERT INTO notifications (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            error_log("Notification prepare failed: " . mysqli_error($conn));
            return false;
        }

        if (!empty($bindValues)) {
            mysqli_stmt_bind_param($stmt, $types, ...$bindValues);
        }

        $ok = mysqli_stmt_execute($stmt);

        if (!$ok) {
            error_log("Notification execute failed: " . mysqli_stmt_error($stmt));
        }

        mysqli_stmt_close($stmt);

        return $ok;
    }
}

if (!function_exists('getUnreadNotificationCount')) {
    function getUnreadNotificationCount($conn, int $employeeId): int {
        if ($employeeId <= 0 || !$conn || !nh_table_exists($conn, 'notifications')) {
            return 0;
        }

        if (!nh_column_exists($conn, 'notifications', 'employee_id')) {
            return 0;
        }

        $hasIsRead = nh_column_exists($conn, 'notifications', 'is_read');

        $sql = "SELECT COUNT(*) AS cnt FROM notifications WHERE employee_id = ?";
        if ($hasIsRead) {
            $sql .= " AND COALESCE(is_read, 0) = 0";
        }

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return 0;

        mysqli_stmt_bind_param($stmt, "i", $employeeId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        return (int)($row['cnt'] ?? 0);
    }
}

if (!function_exists('getEmployeeNotifications')) {
    function getEmployeeNotifications($conn, int $employeeId, int $limit = 5): array {
        if ($employeeId <= 0 || !$conn || !nh_table_exists($conn, 'notifications')) {
            return [];
        }

        if (!nh_column_exists($conn, 'notifications', 'employee_id')) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        $select = [];
        foreach (['id', 'employee_id', 'title', 'message', 'type', 'module', 'reference_id', 'link', 'is_read', 'created_at'] as $column) {
            if (nh_column_exists($conn, 'notifications', $column)) {
                $select[] = "`$column`";
            }
        }

        if (empty($select)) {
            return [];
        }

        $orderColumn = nh_column_exists($conn, 'notifications', 'created_at') ? 'created_at' : 'id';

        $sql = "SELECT " . implode(',', $select) . "
                FROM notifications
                WHERE employee_id = ?
                ORDER BY `$orderColumn` DESC
                LIMIT $limit";

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return [];

        mysqli_stmt_bind_param($stmt, "i", $employeeId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        $rows = [];
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $rows[] = $row;
        }

        mysqli_stmt_close($stmt);

        return $rows;
    }
}

if (!function_exists('notificationTimeAgo')) {
    function notificationTimeAgo($datetime): string {
        if (empty($datetime)) return '';

        $ts = strtotime((string)$datetime);
        if (!$ts) return '';

        $diff = time() - $ts;

        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) > 1 ? 's' : '') . ' ago';
        if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';

        return date('d M Y', $ts);
    }
}

if (!function_exists('notificationIconClass')) {
    function notificationIconClass(string $module = '', string $type = ''): array {
        $key = strtolower(trim($module ?: $type));

        if (str_contains($key, 'leave')) return ['blue', 'bi-calendar2-x'];
        if (str_contains($key, 'attendance') || str_contains($key, 'regularization')) return ['green', 'bi-clock-history'];
        if (str_contains($key, 'quotation')) return ['orange', 'bi-file-earmark-text'];
        if (str_contains($key, 'project') || str_contains($key, 'site')) return ['orange', 'bi-kanban'];
        if (str_contains($key, 'hiring')) return ['green', 'bi-person-plus'];
        if (str_contains($key, 'mail')) return ['blue', 'bi-envelope'];

        return ['blue', 'bi-bell'];
    }
}
?>