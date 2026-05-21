<?php
session_start();
require_once 'includes/db-config.php';

$current_employee_id = (int)($_SESSION['employee_id'] ?? 0);
if ($current_employee_id <= 0) {
    header("Location: ../login.php");
    exit;
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$today = date('Y-m-d');
$action = $_GET['action'] ?? 'in'; // in | out
if (!in_array($action, ['in', 'out'])) $action = 'in';

// IMPORTANT: move API key to env/config in production
$google_maps_api_key = 'AIzaSyCyBiTiehtlXq0UxU-CTy_odcLF33eekBE';

function calculateDistanceFallback($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $dLat = $lat2 - $lat1;
    $dLon = $lon2 - $lon1;

    $a = sin($dLat/2) * sin($dLat/2) +
         cos($lat1) * cos($lat2) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * asin(sqrt($a));
    return $earthRadius * $c;
}

function tableExists($conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function columnExists($conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEsc = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
    if (!$res) return false;
    $ok = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $ok;
}

function roleKeyFromEmployee(array $employee): string {
    $designation = strtolower(trim((string)($employee['designation'] ?? '')));
    $department = strtolower(trim((string)($employee['department'] ?? '')));

    if (str_contains($designation, 'director') || str_contains($designation, 'admin') || str_contains($designation, 'administrator') || str_contains($designation, 'vice president') || str_contains($designation, 'general manager')) return 'admin';
    if (str_contains($designation, 'hr') || str_contains($department, 'hr') || str_contains($department, 'human resource')) return 'hr';
    if (str_contains($designation, 'team lead') || str_contains($designation, 'teamleader') || str_contains($designation, 'tl') || str_contains($designation, 'lead')) return 'tl';
    if (str_contains($designation, 'qs') || str_contains($department, 'qs') || str_contains($designation, 'quantity survey')) return 'qs';
    if (str_contains($designation, 'manager')) return 'manager';
    if (str_contains($designation, 'project engineer') || str_contains($designation, 'engineer')) return 'project_engineer';

    return 'employee';
}

function logPunchActivity($conn, int $employeeId, string $activityType, string $description, $referenceId = null, array $newData = []): bool {
    if (!$conn || !tableExists($conn, 'activity_logs')) return false;

    $newDataJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;

    $employeeName = $_SESSION['employee_name'] ?? $_SESSION['name'] ?? 'System';
    $username = $_SESSION['username'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $department = $_SESSION['department'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $map = [
        'employee_id'   => ['i', $employeeId],
        'employee_name' => ['s', $employeeName],
        'username'      => ['s', $username],
        'designation'   => ['s', $designation],
        'department'    => ['s', $department],
        'activity_type' => ['s', $activityType],
        'module'        => ['s', 'attendance'],
        'description'   => ['s', $description],
        'reference_id'  => ['i', $referenceId],
        'new_data'      => ['s', $newDataJson],
        'ip_address'    => ['s', $ipAddress],
    ];

    $columns = [];
    $types = '';
    $values = [];

    foreach ($map as $column => $pair) {
        if (columnExists($conn, 'activity_logs', $column)) {
            $columns[] = "`$column`";
            $types .= $pair[0];
            $values[] = $pair[1];
        }
    }

    if (!$columns) return false;

    $sql = "INSERT INTO activity_logs (" . implode(',', $columns) . ") VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}


$error = '';
$success = '';
$punch_location_data = null;

// Employee
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);
if (!$employee) die("Employee not found or inactive.");

// Current role
$currentRoleKey = roleKeyFromEmployee($employee ?: []);
$designation = strtolower(trim($employee['designation'] ?? ''));
$isHrOrAdmin = in_array($currentRoleKey, ['admin', 'hr'], true);
$isHrOnlyPunch = ($currentRoleKey === 'hr');

// Today's attendance
$att_stmt = mysqli_prepare($conn, "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
mysqli_stmt_bind_param($att_stmt, "is", $current_employee_id, $today);
mysqli_stmt_execute($att_stmt);
$att_res = mysqli_stmt_get_result($att_stmt);
$attendance = mysqli_fetch_assoc($att_res);
mysqli_stmt_close($att_stmt);

// All roles can punch from office. HR users will see office only.
$can_punch_office = true;

// Assigned sites (for punch in page)
// PE/QS: site_project_engineers.employee_id
// Manager: sites.manager_employee_id OR site_project_engineers fallback
// TL: sites.team_lead_employee_id OR site_project_engineers fallback
// HR: office only, so site list is intentionally empty.
$assigned_sites = [];
$hasTeamLeadCol = columnExists($conn, 'sites', 'team_lead_employee_id');
$hasManagerCol = columnExists($conn, 'sites', 'manager_employee_id');

if ($currentRoleKey !== 'hr') {
    $siteConditions = ["spe.employee_id = ?"];
    $siteTypes = "i";
    $siteParams = [$current_employee_id];

    if ($hasManagerCol) {
        $siteConditions[] = "s.manager_employee_id = ?";
        $siteTypes .= "i";
        $siteParams[] = $current_employee_id;
    }

    if ($hasTeamLeadCol) {
        $siteConditions[] = "s.team_lead_employee_id = ?";
        $siteTypes .= "i";
        $siteParams[] = $current_employee_id;
    }

    $sitesSql = "
        SELECT DISTINCT s.*
        FROM sites s
        LEFT JOIN site_project_engineers spe ON s.id = spe.site_id
        WHERE (" . implode(" OR ", $siteConditions) . ")
          AND s.deleted_at IS NULL
        ORDER BY s.project_name ASC
    ";

    $sites_stmt = mysqli_prepare($conn, $sitesSql);
    if ($sites_stmt) {
        mysqli_stmt_bind_param($sites_stmt, $siteTypes, ...$siteParams);
        mysqli_stmt_execute($sites_stmt);
        $sites_res = mysqli_stmt_get_result($sites_stmt);
        $assigned_sites = $sites_res ? mysqli_fetch_all($sites_res, MYSQLI_ASSOC) : [];
        mysqli_stmt_close($sites_stmt);
    }
}

// Offices - All active offices (available for all roles now)
$offices = [];
$office_query = "SELECT * FROM office_locations WHERE is_active = 1 ORDER BY is_head_office DESC, location_name ASC";
$office_res = mysqli_query($conn, $office_query);
if ($office_res) $offices = mysqli_fetch_all($office_res, MYSQLI_ASSOC);

// Prepare punch out target location
if ($action === 'out' && $attendance && !$attendance['punch_out_time']) {
    if ($attendance['punch_in_type'] === 'site' && !empty($attendance['punch_in_site_id'])) {
        $site_id = (int)$attendance['punch_in_site_id'];
        $site_q = mysqli_query($conn, "SELECT * FROM sites WHERE id = {$site_id} LIMIT 1");
        $site = mysqli_fetch_assoc($site_q);
        if ($site && $site['latitude'] && $site['longitude']) {
            $punch_location_data = [
                'type' => 'site',
                'lat' => (float)$site['latitude'],
                'lng' => (float)$site['longitude'],
                'radius' => (int)($site['location_radius'] ?? 100),
                'name' => $site['project_name']
            ];
        }
    } elseif ($attendance['punch_in_type'] === 'office' && !empty($attendance['punch_in_office_id'])) {
        $office_id = (int)$attendance['punch_in_office_id'];
        $office_q = mysqli_query($conn, "SELECT * FROM office_locations WHERE id = {$office_id} LIMIT 1");
        $office = mysqli_fetch_assoc($office_q);
        if ($office && $office['latitude'] && $office['longitude']) {
            $punch_location_data = [
                'type' => 'office',
                'lat' => (float)$office['latitude'],
                'lng' => (float)$office['longitude'],
                'radius' => (int)($office['geo_fence_radius'] ?? 100),
                'name' => $office['location_name']
            ];
        }
    }
}

// Guard conditions
if ($action === 'in' && $attendance) {
    $_SESSION['flash_error'] = 'You have already punched in today.';
    header("Location: punchin.php");
    exit;
}

if ($action === 'out') {
    if (!$attendance) {
        $_SESSION['flash_error'] = 'No punch-in found for today.';
        header("Location: punchin.php");
        exit;
    }
    if (!empty($attendance['punch_out_time'])) {
        $_SESSION['flash_error'] = 'You have already punched out today.';
        header("Location: punchin.php");
        exit;
    }
    if (!$punch_location_data) {
        $_SESSION['flash_error'] = 'Punch-out location config not found.';
        header("Location: punchin.php");
        exit;
    }
}

// ---------------------------
// HANDLE POST: PUNCH IN
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'in') {
    $punch_lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0;
    $punch_lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0;
    $punch_location = trim($_POST['location'] ?? '');
    $punch_type = $_POST['punch_type_radio'] ?? '';
    $site_id = isset($_POST['site_id']) && $_POST['site_id'] !== '' ? (int)$_POST['site_id'] : null;
    $office_id = isset($_POST['office_id']) && $_POST['office_id'] !== '' ? (int)$_POST['office_id'] : null;

    $can_punch = false;

    if (!$punch_lat || !$punch_lng) {
        $error = "Invalid GPS coordinates. Please allow location access and try again.";
    } elseif (!in_array($punch_type, ['site', 'office'])) {
        $error = "Invalid punch type.";
    } else {
        // SITE punch
        if ($punch_type === 'site') {
            if (!$site_id) {
                $error = "Please select a site.";
            } else {
                $siteConditions = ["spe.employee_id = ?"];
                $siteTypes = "i";
                $siteParams = [$current_employee_id];

                if ($hasManagerCol) {
                    $siteConditions[] = "s.manager_employee_id = ?";
                    $siteTypes .= "i";
                    $siteParams[] = $current_employee_id;
                }

                if ($hasTeamLeadCol) {
                    $siteConditions[] = "s.team_lead_employee_id = ?";
                    $siteTypes .= "i";
                    $siteParams[] = $current_employee_id;
                }

                $siteCheckSql = "
                    SELECT DISTINCT s.*
                    FROM sites s
                    LEFT JOIN site_project_engineers spe ON s.id = spe.site_id
                    WHERE s.id = ?
                      AND (" . implode(" OR ", $siteConditions) . ")
                      AND s.deleted_at IS NULL
                    LIMIT 1
                ";

                $site_check_stmt = mysqli_prepare($conn, $siteCheckSql);
                $siteCheckTypes = "i" . $siteTypes;
                $siteCheckParams = array_merge([$site_id], $siteParams);
                mysqli_stmt_bind_param($site_check_stmt, $siteCheckTypes, ...$siteCheckParams);
                mysqli_stmt_execute($site_check_stmt);
                $site_check_res = mysqli_stmt_get_result($site_check_stmt);
                $site = mysqli_fetch_assoc($site_check_res);
                mysqli_stmt_close($site_check_stmt);

                if (!$site) {
                    $error = "You are not assigned to this site.";
                } elseif (!$site['latitude'] || !$site['longitude']) {
                    $error = "Site location not configured.";
                } else {
                    $distance = calculateDistanceFallback(
                        $punch_lat, $punch_lng,
                        (float)$site['latitude'], (float)$site['longitude']
                    );
                    $radius = (int)($site['location_radius'] ?? 100);

                    if ($distance > $radius) {
                        $error = "You are outside allowed site radius ({$radius}m).";
                    } else {
                        $can_punch = true;
                        $punch_location = $site['project_name'];
                    }
                }
            }
        }

        // OFFICE punch
        if ($punch_type === 'office') {
            if (!$office_id) {
                $error = "Please select an office location.";
            } else {
                $office_check_stmt = mysqli_prepare($conn, "
                    SELECT * FROM office_locations WHERE id = ? AND is_active = 1 LIMIT 1
                ");
                mysqli_stmt_bind_param($office_check_stmt, "i", $office_id);
                mysqli_stmt_execute($office_check_stmt);
                $office_check_res = mysqli_stmt_get_result($office_check_stmt);
                $office = mysqli_fetch_assoc($office_check_res);
                mysqli_stmt_close($office_check_stmt);

                if (!$office) {
                    $error = "Invalid office location.";
                } elseif (!$office['latitude'] || !$office['longitude']) {
                    $error = "Office location not configured.";
                } else {
                    $distance = calculateDistanceFallback(
                        $punch_lat, $punch_lng,
                        (float)$office['latitude'], (float)$office['longitude']
                    );
                    $radius = (int)($office['geo_fence_radius'] ?? 100);

                    if ($distance > $radius) {
                        $error = "You are outside allowed office radius ({$radius}m).";
                    } else {
                        $can_punch = true;
                        $punch_location = $office['location_name'];
                    }
                }
            }
        }
    }

    if ($can_punch && !$error) {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO attendance
            (employee_id, attendance_date, punch_in_time,
             punch_in_location, punch_in_latitude, punch_in_longitude,
             punch_in_type, punch_in_site_id, punch_in_office_id, status)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, 'present')
        ");
        mysqli_stmt_bind_param(
            $stmt,
            "issddsii",
            $current_employee_id,
            $today,
            $punch_location,
            $punch_lat,
            $punch_lng,
            $punch_type,
            $site_id,
            $office_id
        );

        if (mysqli_stmt_execute($stmt)) {
            logPunchActivity(
                $conn,
                $current_employee_id,
                'CREATE',
                "Punched in at {$punch_location}",
                mysqli_insert_id($conn),
                ['type' => $punch_type, 'location' => $punch_location]
            );
            $_SESSION['flash_success'] = "Punch in successful at {$punch_location}.";
            header("Location: punchin.php");
            exit;
        } else {
            $error = mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
    }
}

// ---------------------------
// HANDLE POST: PUNCH OUT
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'out' && $attendance) {
    $punch_lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0;
    $punch_lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0;
    $punch_location = trim($_POST['location'] ?? '');

    if (!$punch_lat || !$punch_lng) {
        $error = "Invalid GPS coordinates. Please allow location access and try again.";
    } else {
        $distance = calculateDistanceFallback(
            $punch_lat,
            $punch_lng,
            $punch_location_data['lat'],
            $punch_location_data['lng']
        );

        if ($distance > $punch_location_data['radius']) {
            $error = "You are outside allowed {$punch_location_data['type']} radius.";
        } else {
            $punch_in_time = strtotime($attendance['punch_in_time']);
            $punch_out_time = time();
            $total_hours = round(($punch_out_time - $punch_in_time) / 3600, 2);

            $stmt = mysqli_prepare($conn, "
                UPDATE attendance SET
                    punch_out_time = NOW(),
                    punch_out_location = ?,
                    punch_out_latitude = ?,
                    punch_out_longitude = ?,
                    total_hours = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt, "sdddi",
                $punch_location,
                $punch_lat,
                $punch_lng,
                $total_hours,
                $attendance['id']
            );

            if (mysqli_stmt_execute($stmt)) {
                logPunchActivity(
                    $conn,
                    $current_employee_id,
                    'UPDATE',
                    "Punched out after {$total_hours} hours",
                    (int)$attendance['id'],
                    ['hours' => $total_hours, 'location' => $punch_location]
                );
                $_SESSION['flash_success'] = "Punch out successful. Total hours: {$total_hours}h";
                header("Location: punchin.php");
                exit;
            } else {
                $error = mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Get current time for display
$current_time = date('h:i A');
$current_date = date('d M Y');
$defaultPunchType = ($isHrOnlyPunch || empty($assigned_sites)) ? 'office' : 'site';
$showSiteOption = (!$isHrOnlyPunch && !empty($assigned_sites));
$showOfficeOption = $can_punch_office;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title><?= $action === 'in' ? 'Punch In' : 'Punch Out' ?> - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <script
        src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($google_maps_api_key) ?>&libraries=places,geometry">
    </script>

    <style>
    :root {
        --page-bg: #f5f7fb;
        --card-bg: #ffffff;
        --border: #e5e7eb;
        --text: #111827;
        --muted: #6b7280;
        --soft: #f8fafc;
        --shadow: 0 10px 26px rgba(15, 23, 42, .055);
        --radius: 15px;
        --blue: #2f80ed;
        --green: #27ae60;
        --orange: #f2994a;
        --red: #eb5757;
        --purple: #7c3aed;
    }

    body {
        background: var(--page-bg);
    }

    .content-scroll {
        flex: 1 1 auto;
        overflow: auto;
        padding: 16px;
    }

    .projects-wrapper {
        width: 100%;
    }

    .page-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .page-heading h1 {
        font-size: 19px;
        font-weight: 900;
        color: var(--text);
        margin: 0;
    }

    .page-heading p {
        margin: 3px 0 0;
        color: var(--muted);
        font-size: 12px;
        font-weight: 600;
    }

    .primary-btn,
    .secondary-btn {
        min-height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 12px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        text-decoration: none;
        white-space: nowrap;
        border: 0;
    }

    .primary-btn {
        background: #111827;
        color: #fff;
    }

    .primary-btn:hover {
        background: #020617;
        color: #fff;
    }

    .secondary-btn {
        border: 1px solid var(--border);
        background: #fff;
        color: #334155;
    }

    .secondary-btn:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #111827;
    }

    .card-panel,
    .panel {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 13px;
        margin-bottom: 14px;
    }

    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .panel-title {
        font-weight: 900;
        font-size: 14px;
        color: var(--text);
        margin: 0;
    }

    .panel-subtitle {
        color: var(--muted);
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
    }

    .form-label {
        font-size: 11px;
        font-weight: 900;
        color: #475569;
        text-transform: uppercase;
        margin-bottom: 6px;
    }

    .form-control,
    .form-select {
        min-height: 38px;
        border: 1px solid var(--border);
        border-radius: 11px;
        font-size: 12px;
        font-weight: 800;
        color: #111827;
        padding: 8px 11px;
        background: #fff;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #bfdbfe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .form-check {
        border: 1px solid #eef2f7;
        border-radius: 13px;
        background: #f8fafc;
        padding: 10px 12px 10px 36px;
        min-width: 190px;
    }

    .form-check-label {
        font-size: 12px;
        font-weight: 900;
        color: #334155;
    }

    .location-status {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 13px;
        padding: 12px;
        font-size: 12px;
        font-weight: 800;
    }

    .map-box {
        height: 260px;
        border-radius: 13px;
        border: 1px solid #e5e7eb;
        display: none;
        overflow: hidden;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px;
    }

    .mini-info {
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 12px;
        padding: 9px 10px;
    }

    .mini-info .lbl,
    .address-box .lbl {
        font-size: 10px;
        color: #64748b;
        font-weight: 900;
        text-transform: uppercase;
        margin-bottom: 3px;
    }

    .mini-info .val,
    .address-box .val {
        font-size: 11.5px;
        color: #111827;
        font-weight: 850;
        word-break: break-word;
        line-height: 1.35;
    }

    .address-box {
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 12px;
        padding: 10px;
    }

    .role-badge,
    .badge-pill {
        border-radius: 999px;
        padding: 5px 8px;
        font-weight: 900;
        font-size: 10px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .role-hr {
        background: #dbeafe;
        color: #2563eb;
        border-color: #bfdbfe;
    }

    .role-admin {
        background: #ede9fe;
        color: #6d28d9;
        border-color: #ddd6fe;
    }

    .employee-info-list {
        display: grid;
        gap: 8px;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        border-bottom: 1px dashed #eef2f7;
        padding-bottom: 7px;
    }

    .info-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .info-key {
        color: #64748b;
        font-size: 10.5px;
        font-weight: 900;
        text-transform: uppercase;
    }

    .info-val {
        color: #111827;
        font-size: 11.5px;
        font-weight: 850;
        text-align: right;
        word-break: break-word;
    }

    .alert {
        border-radius: 14px;
        border: 1px solid transparent;
        box-shadow: var(--shadow);
        font-size: 12px;
        font-weight: 850;
    }

    .alert-info {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1e40af;
    }

    .alert-warning {
        background: #fffbeb;
        border-color: #fde68a;
        color: #92400e;
    }

    .alert-danger {
        background: #fee2e2;
        border-color: #fecaca;
        color: #991b1b;
    }

    @media(max-width:991.98px) {
        .main {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        .sidebar {
            position: fixed !important;
            transform: translateX(-100%);
            z-index: 1040 !important;
        }

        .sidebar.open,
        .sidebar.active,
        .sidebar.show {
            transform: translateX(0) !important;
        }
    }

    @media(max-width:768px) {
        .content-scroll {
            padding: 12px 10px !important;
        }

        .container-fluid.projects-wrapper {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        .page-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .card-panel,
        .panel {
            padding: 12px;
        }

        .primary-btn,
        .secondary-btn {
            width: 100%;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .form-check {
            width: 100%;
        }

        .info-row {
            flex-direction: column;
            gap: 3px;
        }

        .info-val {
            text-align: left;
        }
    }
    </style>
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main" aria-label="Main">
            <?php include 'includes/topbar.php'; ?>

            <div class="content-scroll">
                <div class="container-fluid projects-wrapper px-0">
                    <div class="page-heading">
                        <div>
                            <h1><?= $action === 'in' ? 'Punch In' : 'Punch Out' ?></h1>
                            <p>
                                <?= $action === 'in' ? 'Validate location and mark punch in' : 'Validate location and mark punch out' ?>
                                <?php if ($isHrOrAdmin): ?>
                                <span class="role-badge <?= $currentRoleKey === 'admin' ? 'role-admin' : 'role-hr' ?>">
                                    <i class="bi bi-shield-check"></i>
                                    <?= $currentRoleKey === 'admin' ? 'Admin Access' : 'HR Access' ?>
                                </span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="punchin.php" class="secondary-btn">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                    </div>

                    <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="card-panel">
                                <?php if ($action === 'in'): ?>
                                <form method="POST" id="punchForm">
                                    <input type="hidden" name="latitude" id="punchLat">
                                    <input type="hidden" name="longitude" id="punchLng">
                                    <input type="hidden" name="location" id="punchAddress">
                                    <input type="hidden" name="punch_type_radio" id="punchType"
                                        value="<?= e($defaultPunchType) ?>">

                                    <div class="mb-3">
                                        <label class="form-label">Select Punch Location Type</label>
                                        <div class="d-flex gap-4 flex-wrap">
                                            <?php if ($showSiteOption): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio"
                                                    name="punch_type_radio_display" id="punchTypeSite" value="site"
                                                    <?= $defaultPunchType === 'site' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="punchTypeSite">
                                                    <i class="bi bi-building"></i> Site Location
                                                </label>
                                            </div>
                                            <?php endif; ?>

                                            <?php if ($showOfficeOption): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio"
                                                    name="punch_type_radio_display" id="punchTypeOffice" value="office"
                                                    <?= $defaultPunchType === 'office' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="punchTypeOffice">
                                                    <i class="bi bi-briefcase"></i> Office Location
                                                </label>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isHrOnlyPunch): ?>
                                        <small class="text-muted mt-1 d-block">
                                            <i class="bi bi-info-circle"></i> HR users can punch only from office
                                            locations.
                                        </small>
                                        <?php elseif (empty($assigned_sites)): ?>
                                        <small class="text-muted mt-1 d-block">
                                            <i class="bi bi-info-circle"></i> No assigned project site found. You can
                                            still punch from office location.
                                        </small>
                                        <?php else: ?>
                                        <small class="text-muted mt-1 d-block">
                                            <i class="bi bi-info-circle"></i> You can punch from assigned project site
                                            or office location.
                                        </small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mb-3" id="siteSelectDiv"
                                        style="<?= $defaultPunchType === 'site' ? '' : 'display:none;' ?>">
                                        <label class="form-label">Select Your Site</label>
                                        <select class="form-select" name="site_id" id="siteSelect"
                                            <?= $defaultPunchType === 'site' ? 'required' : '' ?>>
                                            <option value="">Choose assigned site</option>
                                            <?php foreach ($assigned_sites as $site): ?>
                                            <option value="<?= (int)$site['id'] ?>"
                                                data-lat="<?= htmlspecialchars($site['latitude']) ?>"
                                                data-lng="<?= htmlspecialchars($site['longitude']) ?>"
                                                data-radius="<?= (int)($site['location_radius'] ?? 100) ?>"
                                                data-name="<?= htmlspecialchars($site['project_name']) ?>">
                                                <?= htmlspecialchars($site['project_name']) ?>
                                                (<?= htmlspecialchars($site['project_code'] ?? 'No Code') ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3" id="officeSelectDiv"
                                        style="<?= $defaultPunchType === 'office' ? '' : 'display:none;' ?>">
                                        <label class="form-label">Select Office Location</label>
                                        <select class="form-select" name="office_id" id="officeSelect"
                                            <?= $defaultPunchType === 'office' ? 'required' : '' ?>>
                                            <option value="">Choose office</option>
                                            <?php foreach ($offices as $office): ?>
                                            <option value="<?= (int)$office['id'] ?>"
                                                data-lat="<?= htmlspecialchars($office['latitude']) ?>"
                                                data-lng="<?= htmlspecialchars($office['longitude']) ?>"
                                                data-radius="<?= (int)($office['geo_fence_radius'] ?? 100) ?>"
                                                data-name="<?= htmlspecialchars($office['location_name']) ?>">
                                                <?= htmlspecialchars($office['location_name']) ?>
                                                <?php if ($office['is_head_office']): ?>
                                                (Head Office)
                                                <?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted mt-1 d-block">
                                            <i class="bi bi-info-circle"></i> Select your current office location
                                        </small>
                                    </div>

                                    <!-- Current Location Details -->
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label fw-bold mb-0">Current Location Details</label>
                                            <button type="button" class="secondary-btn" id="refreshLocationBtn">
                                                <i class="bi bi-arrow-clockwise"></i> Refresh GPS
                                            </button>
                                        </div>

                                        <div class="info-grid mb-2">
                                            <div class="mini-info">
                                                <div class="lbl">Latitude</div>
                                                <div class="val" id="currentLatText">—</div>
                                            </div>
                                            <div class="mini-info">
                                                <div class="lbl">Longitude</div>
                                                <div class="val" id="currentLngText">—</div>
                                            </div>
                                            <div class="mini-info">
                                                <div class="lbl">Accuracy</div>
                                                <div class="val" id="currentAccuracyText">—</div>
                                            </div>
                                            <div class="mini-info">
                                                <div class="lbl">GPS Updated At</div>
                                                <div class="val" id="currentGpsTimeText">—</div>
                                            </div>
                                        </div>

                                        <div class="address-box">
                                            <div class="lbl">Detected Address</div>
                                            <div class="val" id="currentAddressText">Detecting location...</div>
                                        </div>
                                    </div>

                                    <div id="locationStatus" class="location-status mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="spinner-border spinner-border-sm me-2"></div>
                                            <span>Getting your location...</span>
                                        </div>
                                    </div>

                                    <div id="mapPreview" class="map-box mb-3"></div>

                                    <div class="alert alert-info mb-3">
                                        <i class="bi bi-info-circle me-2"></i>
                                        Make sure your device location is enabled. You can only punch in within the
                                        geo-fence radius.
                                    </div>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="submit" class="primary-btn" id="submitBtn" disabled>
                                            <i class="bi bi-box-arrow-in-right"></i> Confirm Punch In
                                        </button>
                                        <a href="punchin.php" class="secondary-btn">Cancel</a>
                                    </div>
                                </form>

                                <?php else: ?>
                                <form method="POST" id="punchOutForm">
                                    <input type="hidden" name="latitude" id="punchLat">
                                    <input type="hidden" name="longitude" id="punchLng">
                                    <input type="hidden" name="location" id="punchAddress">

                                    <div class="alert alert-info">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-info-circle-fill me-3 fs-4"></i>
                                            <div>
                                                <strong>Punch Out Location:
                                                    <?= htmlspecialchars($punch_location_data['name'] ?? '') ?></strong><br>
                                                <small>
                                                    Type: <?= ucfirst($punch_location_data['type'] ?? '') ?> |
                                                    Required Radius:
                                                    <?= (int)($punch_location_data['radius'] ?? 100) ?>m
                                                </small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Current Location Details -->
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label fw-bold mb-0">Current Location Details</label>
                                            <button type="button" class="secondary-btn" id="refreshLocationBtn">
                                                <i class="bi bi-arrow-clockwise"></i> Refresh GPS
                                            </button>
                                        </div>

                                        <div class="info-grid mb-2">
                                            <div class="mini-info">
                                                <div class="lbl">Latitude</div>
                                                <div class="val" id="currentLatText">—</div>
                                            </div>
                                            <div class="mini-info">
                                                <div class="lbl">Longitude</div>
                                                <div class="val" id="currentLngText">—</div>
                                            </div>
                                            <div class="mini-info">
                                                <div class="lbl">Accuracy</div>
                                                <div class="val" id="currentAccuracyText">—</div>
                                            </div>
                                            <div class="mini-info">
                                                <div class="lbl">GPS Updated At</div>
                                                <div class="val" id="currentGpsTimeText">—</div>
                                            </div>
                                        </div>

                                        <div class="address-box">
                                            <div class="lbl">Detected Address</div>
                                            <div class="val" id="currentAddressText">Detecting location...</div>
                                        </div>
                                    </div>

                                    <div id="locationStatus" class="location-status mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="spinner-border spinner-border-sm me-2"></div>
                                            <span>Validating your location...</span>
                                        </div>
                                    </div>

                                    <div id="mapPreview" class="map-box mb-3" style="display:block;"></div>

                                    <div class="alert alert-warning mb-3">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        You must be within <?= (int)($punch_location_data['radius'] ?? 100) ?>m of the
                                        <?= htmlspecialchars($punch_location_data['type'] ?? '') ?> location to punch
                                        out.
                                    </div>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="submit" class="primary-btn" id="submitBtn" disabled>
                                            <i class="bi bi-box-arrow-right"></i> Confirm Punch Out
                                        </button>
                                        <a href="punchin.php" class="secondary-btn">Cancel</a>
                                    </div>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card-panel">
                                <div class="panel-header">
                                    <div>
                                        <h5 class="panel-title">Employee Details</h5>
                                        <div class="panel-subtitle">Current attendance context</div>
                                    </div>
                                    <?php if ($isHrOrAdmin): ?>
                                    <span
                                        class="badge-pill <?= $currentRoleKey === 'admin' ? 'role-admin' : 'role-hr' ?>">
                                        <i class="bi bi-shield-check"></i>
                                        <?= $currentRoleKey === 'admin' ? 'ADMIN' : 'HR' ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-2"><strong>Name:</strong> <?= htmlspecialchars($employee['full_name']) ?>
                                </div>
                                <div class="mb-2"><strong>Code:</strong>
                                    <?= htmlspecialchars($employee['employee_code']) ?></div>
                                <div class="mb-2"><strong>Designation:</strong>
                                    <?= htmlspecialchars($employee['designation']) ?></div>
                                <div class="mb-2"><strong>Date:</strong> <?= $current_date ?></div>
                                <div class="mb-2"><strong>Time:</strong> <?= $current_time ?></div>

                                <?php if ($attendance): ?>
                                <hr>
                                <div class="mb-2"><strong>Today's Punch In:</strong>
                                    <?= date('h:i A', strtotime($attendance['punch_in_time'])) ?></div>
                                <div class="mb-2"><strong>Type:</strong> <?= ucfirst($attendance['punch_in_type']) ?>
                                </div>
                                <div class="mb-2"><strong>Location:</strong>
                                    <?= htmlspecialchars($attendance['punch_in_location']) ?></div>
                                <?php endif; ?>

                                <?php if ($action === 'out' && $punch_location_data): ?>
                                <hr>
                                <h6 class="fw-bold mb-2">Punch Out Target</h6>
                                <div class="mb-2"><strong>Target:</strong>
                                    <?= htmlspecialchars($punch_location_data['name']) ?></div>
                                <div class="mb-2"><strong>Type:</strong> <?= ucfirst($punch_location_data['type']) ?>
                                </div>
                                <div class="mb-2"><strong>Radius:</strong> <?= (int)$punch_location_data['radius'] ?>m
                                </div>
                                <div class="mb-2"><strong>Target Lat:</strong>
                                    <?= htmlspecialchars((string)$punch_location_data['lat']) ?></div>
                                <div class="mb-2"><strong>Target Lng:</strong>
                                    <?= htmlspecialchars((string)$punch_location_data['lng']) ?></div>
                                <?php endif; ?>

                                <hr>
                                <div class="small text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    If GPS fails, enable location permissions in browser and refresh the page.
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>
    <script src="assets/js/sidebar-toggle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let map, geocoder, currentMarker, targetMarker, targetCircle;
    let currentLat = null,
        currentLng = null,
        currentAccuracy = null;
    let currentAddress = '';
    let lastLocationUpdateTime = null;

    function formatTime(dt) {
        if (!dt) return '—';
        return dt.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
    }

    function updateCurrentLocationUI() {
        const latEl = document.getElementById('currentLatText');
        const lngEl = document.getElementById('currentLngText');
        const accEl = document.getElementById('currentAccuracyText');
        const gpsTimeEl = document.getElementById('currentGpsTimeText');
        const addrEl = document.getElementById('currentAddressText');

        if (latEl) latEl.textContent = (currentLat !== null) ? Number(currentLat).toFixed(6) : '—';
        if (lngEl) lngEl.textContent = (currentLng !== null) ? Number(currentLng).toFixed(6) : '—';
        if (accEl) accEl.textContent = (currentAccuracy !== null) ? `${Math.round(currentAccuracy)} m` : '—';
        if (gpsTimeEl) gpsTimeEl.textContent = formatTime(lastLocationUpdateTime);
        if (addrEl) addrEl.textContent = currentAddress || 'Detecting location...';
    }

    function getAddressFromLatLng(lat, lng) {
        return new Promise((resolve) => {
            try {
                if (!geocoder) geocoder = new google.maps.Geocoder();
                geocoder.geocode({
                    location: {
                        lat: parseFloat(lat),
                        lng: parseFloat(lng)
                    }
                }, (results, status) => {
                    if (status === 'OK' && results && results[0]) {
                        resolve(results[0].formatted_address);
                    } else {
                        resolve(`Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`);
                    }
                });
            } catch (e) {
                resolve(`Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`);
            }
        });
    }

    function getLocation(options = {}) {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation is not supported by your browser'));
                return;
            }

            const config = Object.assign({
                enableHighAccuracy: true,
                timeout: 30000,
                maximumAge: 0
            }, options);

            navigator.geolocation.getCurrentPosition(resolve, reject, config);
        });
    }

    function initMap(lat, lng) {
        const mapDiv = document.getElementById('mapPreview');
        if (!mapDiv) return;
        mapDiv.style.display = 'block';

        if (!map) {
            map = new google.maps.Map(mapDiv, {
                center: {
                    lat: parseFloat(lat),
                    lng: parseFloat(lng)
                },
                zoom: 18,
                mapTypeId: google.maps.MapTypeId.ROADMAP
            });
        } else {
            map.setCenter({
                lat: parseFloat(lat),
                lng: parseFloat(lng)
            });
        }
    }

    function renderMap(currentLat, currentLng, targetLat, targetLng, radius) {
        initMap(currentLat, currentLng);

        if (currentMarker) currentMarker.setMap(null);
        if (targetMarker) targetMarker.setMap(null);
        if (targetCircle) targetCircle.setMap(null);

        currentMarker = new google.maps.Marker({
            position: {
                lat: parseFloat(currentLat),
                lng: parseFloat(currentLng)
            },
            map: map,
            title: 'Your Location',
            icon: {
                url: 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png',
                scaledSize: new google.maps.Size(40, 40)
            }
        });

        targetMarker = new google.maps.Marker({
            position: {
                lat: parseFloat(targetLat),
                lng: parseFloat(targetLng)
            },
            map: map,
            title: 'Target Location',
            icon: {
                url: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png',
                scaledSize: new google.maps.Size(40, 40)
            }
        });

        targetCircle = new google.maps.Circle({
            map: map,
            center: {
                lat: parseFloat(targetLat),
                lng: parseFloat(targetLng)
            },
            radius: parseFloat(radius),
            fillColor: '#3b82f6',
            fillOpacity: 0.10,
            strokeColor: '#3b82f6',
            strokeOpacity: 0.55,
            strokeWeight: 2
        });

        // Auto-fit bounds (nice UX)
        const bounds = new google.maps.LatLngBounds();
        bounds.extend({
            lat: parseFloat(currentLat),
            lng: parseFloat(currentLng)
        });
        bounds.extend({
            lat: parseFloat(targetLat),
            lng: parseFloat(targetLng)
        });
        map.fitBounds(bounds);

        // Avoid over-zoom
        google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
            if (map.getZoom() > 18) map.setZoom(18);
        });
    }

    function setStatus(type, title, subtitle = '') {
        const el = document.getElementById('locationStatus');
        if (!el) return;
        const icon = type === 'success' ? 'check-circle-fill' : (type === 'danger' ? 'exclamation-triangle-fill' :
            'info-circle-fill');
        const cls = type === 'success' ? 'text-success' : (type === 'danger' ? 'text-danger' : 'text-warning');

        el.innerHTML = `
    <div class="d-flex align-items-center ${cls}">
      <i class="bi bi-${icon} me-2 fs-4"></i>
      <div>
        <strong>${title}</strong>${subtitle ? '<br><small class="text-muted">' + subtitle + '</small>' : ''}
      </div>
    </div>
  `;
    }

    function setLoadingStatus(msg = 'Getting your location...') {
        const el = document.getElementById('locationStatus');
        if (!el) return;
        el.innerHTML = `
    <div class="d-flex align-items-center">
      <div class="spinner-border spinner-border-sm me-2" role="status"></div>
      <span>${msg}</span>
    </div>
  `;
    }

    function setRefreshButtonLoading(loading) {
        const btn = document.getElementById('refreshLocationBtn');
        if (!btn) return;
        if (loading) {
            btn.disabled = true;
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Refreshing...';
        } else {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.originalHtml || '<i class="bi bi-arrow-clockwise"></i> Refresh GPS';
        }
    }

    async function fetchAndStoreLocation(options = {}) {
        const position = await getLocation(options);
        currentLat = position.coords.latitude;
        currentLng = position.coords.longitude;
        currentAccuracy = position.coords.accuracy ?? null;
        lastLocationUpdateTime = new Date();

        document.getElementById('punchLat').value = currentLat;
        document.getElementById('punchLng').value = currentLng;

        currentAddress = await getAddressFromLatLng(currentLat, currentLng);
        document.getElementById('punchAddress').value = currentAddress;

        updateCurrentLocationUI();
        return position;
    }

    async function validateCurrentLocationForPunchIn() {
        const submitBtn = document.getElementById('submitBtn');
        const siteRadio = document.getElementById('punchTypeSite');
        const officeRadio = document.getElementById('punchTypeOffice');
        const siteSelect = document.getElementById('siteSelect');
        const officeSelect = document.getElementById('officeSelect');
        const punchTypeHidden = document.getElementById('punchType');

        if (!currentLat || !currentLng) {
            submitBtn.disabled = true;
            return;
        }

        let selectedOption = null;
        let mode = 'site';

        if (siteRadio && siteRadio.checked) {
            mode = 'site';
            if (punchTypeHidden) punchTypeHidden.value = 'site';
            if (!siteSelect || !siteSelect.value) {
                setStatus('warning', 'Please select a site');
                submitBtn.disabled = true;
                return;
            }
            selectedOption = siteSelect.options[siteSelect.selectedIndex];
        } else if (officeRadio && officeRadio.checked) {
            mode = 'office';
            if (punchTypeHidden) punchTypeHidden.value = 'office';
            if (!officeSelect || !officeSelect.value) {
                setStatus('warning', 'Please select an office');
                submitBtn.disabled = true;
                return;
            }
            selectedOption = officeSelect.options[officeSelect.selectedIndex];
        }

        const targetLat = parseFloat(selectedOption.dataset.lat);
        const targetLng = parseFloat(selectedOption.dataset.lng);
        const radius = parseInt(selectedOption.dataset.radius) || 100;

        if (!targetLat || !targetLng) {
            setStatus('danger', `${mode === 'site' ? 'Site' : 'Office'} location not configured`);
            submitBtn.disabled = true;
            return;
        }

        const currentLatLng = new google.maps.LatLng(currentLat, currentLng);
        const targetLatLng = new google.maps.LatLng(targetLat, targetLng);
        const distance = google.maps.geometry.spherical.computeDistanceBetween(currentLatLng, targetLatLng);

        renderMap(currentLat, currentLng, targetLat, targetLng, radius);

        let subtitle = `${Math.round(distance)}m / ${radius}m`;
        if (currentAccuracy !== null) subtitle += ` • GPS accuracy ±${Math.round(currentAccuracy)}m`;

        if (distance <= radius) {
            setStatus('success', '✅ Within Range', subtitle);
            submitBtn.disabled = false;
        } else {
            setStatus('danger', '❌ Too Far', subtitle);
            submitBtn.disabled = true;
        }
    }

    async function validateCurrentLocationForPunchOut() {
        const submitBtn = document.getElementById('submitBtn');
        if (!currentLat || !currentLng) {
            submitBtn.disabled = true;
            return;
        }

        const targetLat = <?= json_encode($punch_location_data['lat'] ?? 0) ?>;
        const targetLng = <?= json_encode($punch_location_data['lng'] ?? 0) ?>;
        const radius = <?= json_encode($punch_location_data['radius'] ?? 100) ?>;

        const currentLatLng = new google.maps.LatLng(currentLat, currentLng);
        const targetLatLng = new google.maps.LatLng(targetLat, targetLng);
        const distance = google.maps.geometry.spherical.computeDistanceBetween(currentLatLng, targetLatLng);

        renderMap(currentLat, currentLng, targetLat, targetLng, radius);

        let subtitle = `${Math.round(distance)}m / ${radius}m`;
        if (currentAccuracy !== null) subtitle += ` • GPS accuracy ±${Math.round(currentAccuracy)}m`;

        if (distance <= radius) {
            setStatus('success', '✅ Within Range', subtitle);
            submitBtn.disabled = false;
        } else {
            setStatus('danger', '❌ Too Far', `${subtitle} - Required: ${radius}m`);
            submitBtn.disabled = true;
        }
    }

    async function initializeLocation() {
        const submitBtn = document.getElementById('submitBtn');
        setLoadingStatus('Getting your location...');

        try {
            await fetchAndStoreLocation({
                timeout: 30000
            });

            <?php if ($action === 'in'): ?>
            await validateCurrentLocationForPunchIn();
            <?php else: ?>
            await validateCurrentLocationForPunchOut();
            <?php endif; ?>

        } catch (error) {
            console.error('Location error:', error);
            let msg = 'Unable to get your location. ';
            if (error.code === 1) msg += 'Please allow location access in browser settings.';
            else if (error.code === 2) msg += 'Location unavailable. Please check GPS.';
            else if (error.code === 3) msg += 'Location request timed out. Please try again.';
            else msg += (error.message || 'Please try again.');

            currentAddress = msg;
            updateCurrentLocationUI();

            setStatus('danger', 'Location Error', msg);
            if (submitBtn) submitBtn.disabled = true;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('punchForm');
        const punchOutForm = document.getElementById('punchOutForm');
        const submitBtn = document.getElementById('submitBtn');
        const refreshLocationBtn = document.getElementById('refreshLocationBtn');

        // Punch-in toggles
        const punchTypeSite = document.getElementById('punchTypeSite');
        const punchTypeOffice = document.getElementById('punchTypeOffice');
        const punchTypeHidden = document.getElementById('punchType');
        const siteSelectDiv = document.getElementById('siteSelectDiv');
        const officeSelectDiv = document.getElementById('officeSelectDiv');
        const siteSelect = document.getElementById('siteSelect');
        const officeSelect = document.getElementById('officeSelect');

        if (punchTypeSite && siteSelectDiv) {
            punchTypeSite.addEventListener('change', function() {
                siteSelectDiv.style.display = 'block';
                if (officeSelectDiv) officeSelectDiv.style.display = 'none';
                if (punchTypeHidden) punchTypeHidden.value = 'site';

                // Manage required attributes
                if (siteSelect) siteSelect.required = true;
                if (officeSelect) officeSelect.required = false;

                if (currentLat && currentLng) validateCurrentLocationForPunchIn();
            });
        }

        if (punchTypeOffice && officeSelectDiv) {
            punchTypeOffice.addEventListener('change', function() {
                if (siteSelectDiv) siteSelectDiv.style.display = 'none';
                officeSelectDiv.style.display = 'block';
                if (punchTypeHidden) punchTypeHidden.value = 'office';

                // Manage required attributes
                if (siteSelect) siteSelect.required = false;
                if (officeSelect) officeSelect.required = true;

                if (currentLat && currentLng) validateCurrentLocationForPunchIn();
            });
        }

        if (siteSelect) {
            siteSelect.addEventListener('change', function() {
                if (currentLat && currentLng) validateCurrentLocationForPunchIn();
            });
        }

        if (officeSelect) {
            officeSelect.addEventListener('change', function() {
                if (currentLat && currentLng) validateCurrentLocationForPunchIn();
            });
        }

        if (refreshLocationBtn) {
            refreshLocationBtn.addEventListener('click', async function() {
                try {
                    setRefreshButtonLoading(true);
                    setLoadingStatus('Refreshing GPS location...');
                    await fetchAndStoreLocation({
                        timeout: 30000,
                        maximumAge: 0
                    });

                    <?php if ($action === 'in'): ?>
                    await validateCurrentLocationForPunchIn();
                    <?php else: ?>
                    await validateCurrentLocationForPunchOut();
                    <?php endif; ?>
                } catch (err) {
                    console.error('Refresh error:', err);
                    let msg = 'Unable to refresh location.';
                    if (err.code === 1) msg = 'Please allow location access in browser settings.';
                    else if (err.code === 2) msg = 'Location unavailable. Check GPS.';
                    else if (err.code === 3) msg = 'Location request timed out.';
                    else msg += ' ' + (err.message || '');
                    alert(msg);
                } finally {
                    setRefreshButtonLoading(false);
                }
            });
        }

        // Handle Punch In Form
        if (form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                const originalBtnText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Validating...';

                try {
                    // First, handle the required field validation manually
                    const punchType = document.querySelector(
                        'input[name="punch_type_radio_display"]:checked')?.value || (document
                        .getElementById('punchType')?.value || 'office');
                    const punchTypeHidden = document.getElementById('punchType');

                    // Set the hidden punch type value
                    if (punchTypeHidden) {
                        punchTypeHidden.value = punchType;
                    }

                    // Temporarily remove required attributes from hidden fields
                    const siteSelect = document.getElementById('siteSelect');
                    const officeSelect = document.getElementById('officeSelect');

                    if (siteSelect) siteSelect.required = false;
                    if (officeSelect) officeSelect.required = false;

                    // Now validate based on selected type
                    if (punchType === 'site') {
                        if (!siteSelect || !siteSelect.value) {
                            alert('Please select a site');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                            if (siteSelect) siteSelect.required = true;
                            return false;
                        }
                        siteSelect.required = true;
                    } else if (punchType === 'office') {
                        if (!officeSelect || !officeSelect.value) {
                            alert('Please select an office location');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                            if (officeSelect) officeSelect.required = true;
                            return false;
                        }
                        officeSelect.required = true;
                    }

                    // Re-fetch latest GPS just before submit
                    await fetchAndStoreLocation({
                        timeout: 30000,
                        maximumAge: 0
                    });

                    // Validate based on action type
                    await validateCurrentLocationForPunchIn();
                    if (submitBtn.disabled) {
                        setStatus('danger', '❌ Validation Failed',
                            'Please check your location and try again');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;

                        if (siteSelect) siteSelect.required = (punchType === 'site');
                        if (officeSelect) officeSelect.required = (punchType === 'office');
                        return false;
                    }

                    setTimeout(() => {
                        form.submit();
                    }, 100);

                } catch (err) {
                    console.error('Form submission error:', err);
                    let msg = 'Unable to get your location. ';
                    if (err.code === 1) msg += 'Please allow location access in browser settings.';
                    else if (err.code === 2) msg += 'Location unavailable. Check GPS.';
                    else if (err.code === 3) msg += 'Location request timed out.';
                    else msg += err.message || 'Please try again.';

                    alert(msg);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            });
        }

        // Handle Punch Out Form
        if (punchOutForm) {
            punchOutForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const originalBtnText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Validating...';

                try {
                    // Re-fetch latest GPS just before submit
                    await fetchAndStoreLocation({
                        timeout: 30000,
                        maximumAge: 0
                    });

                    // Validate location for punch out
                    await validateCurrentLocationForPunchOut();
                    if (submitBtn.disabled) {
                        setStatus('danger', '❌ Validation Failed',
                            'You are outside the required radius');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                        return false;
                    }

                    setTimeout(() => {
                        punchOutForm.submit();
                    }, 100);

                } catch (err) {
                    console.error('Punch out error:', err);
                    let msg = 'Unable to get your location. ';
                    if (err.code === 1) msg += 'Please allow location access in browser settings.';
                    else if (err.code === 2) msg += 'Location unavailable. Check GPS.';
                    else if (err.code === 3) msg += 'Location request timed out.';
                    else msg += err.message || 'Please try again.';

                    alert(msg);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            });
        }

        // Initial setup
        updateCurrentLocationUI();
        initializeLocation();

        // Set initial radio button state and required attributes
        if (punchTypeSite && punchTypeSite.checked) {
            if (punchTypeHidden) punchTypeHidden.value = 'site';
            if (siteSelect) siteSelect.required = true;
            if (officeSelect) officeSelect.required = false;
        } else if (punchTypeOffice && punchTypeOffice.checked) {
            if (punchTypeHidden) punchTypeHidden.value = 'office';
            if (siteSelect) siteSelect.required = false;
            if (officeSelect) officeSelect.required = true;
        }
    });
    </script>
</body>

</html>
<?php
if (isset($conn) && $conn) {
  mysqli_close($conn);
}
?>