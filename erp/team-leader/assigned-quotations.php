<?php
// assigned-quotations.php (Team Lead) — show quotation requests assigned to the logged-in team lead
// TL can accept/reject, then forward to a selected QS employee.

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$requests = [];

// ---------- Auth (Team Lead only) ----------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$empId = (int)$_SESSION['employee_id'];

$currentEmployee = null;
$empStmt = mysqli_prepare($conn, "SELECT id, full_name, employee_code, designation, department FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
if ($empStmt) {
    mysqli_stmt_bind_param($empStmt, "i", $empId);
    mysqli_stmt_execute($empStmt);
    $empRes = mysqli_stmt_get_result($empStmt);
    $currentEmployee = mysqli_fetch_assoc($empRes);
    mysqli_stmt_close($empStmt);
}

if (!$currentEmployee) {
    header("Location: ../login.php");
    exit;
}

$designation = strtolower(trim((string)($currentEmployee['designation'] ?? ($_SESSION['designation'] ?? ''))));
$department = strtolower(trim((string)($currentEmployee['department'] ?? ($_SESSION['department'] ?? ''))));
$currentRoleKey = roleKeyFromDesignation($designation, $department);

$isTl = ($currentRoleKey === 'tl');
$isManager = ($currentRoleKey === 'manager');
$isAdmin = ($currentRoleKey === 'admin');

// TL can act. Manager/Admin are view-only.
if (!in_array($currentRoleKey, ['tl', 'manager', 'admin'], true)) {
    header("Location: index.php");
    exit;
}

// ---------- Handle Actions ----------
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $request_id = intval($_GET['id']);

    // First, get the site_id and current status of the request
    $site_id_query = "SELECT id, site_id, status, request_no, title, requested_by FROM quotation_requests WHERE id = ?";
    $stmt = mysqli_prepare($conn, $site_id_query);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $req_info = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$req_info) {
        $error = "Request not found.";
    } else {
        $site_id = $req_info['site_id'];
        $current_status = $req_info['status'];

        // Only TL can manage actions, and only for their own TL projects.
        $is_tl_site = $isTl && isTlSite($conn, (int)$site_id, (int)$empId);

        if (!$is_tl_site) {
            $error = "Only the assigned Team Lead can manage this request.";
        } else {
            if ($action === 'accept') {
                // Only allow if status is 'Pending Assignment'
                if ($current_status !== 'Pending Assignment') {
                    $error = "This request cannot be accepted (current status: $current_status).";
                } else {
                    $emp_name = $_SESSION['employee_name'] ?? '';
                    $update_query = "UPDATE quotation_requests SET project_engineer_id = ?, project_engineer_name = ?, status = 'Assigned', updated_at = NOW() WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt, "isi", $empId, $emp_name, $request_id);
                    if (mysqli_stmt_execute($stmt)) {
                        $success = "Quotation request accepted successfully. You can now forward it to QS.";

                        $creatorId = (int)($req_info['requested_by'] ?? 0);
                        if ($creatorId > 0 && $creatorId !== $empId) {
                            createNotificationCurrentDb(
                                $conn,
                                $creatorId,
                                'Quotation request accepted',
                                'Your quotation request ' . ($req_info['request_no'] ?? '') . ' has been accepted by TL.',
                                'quotation_requests',
                                $request_id,
                                'view-quotation-request.php?id=' . $request_id,
                                'quotation'
                            );
                        }
                    } else {
                        $error = "Failed to accept quotation request.";
                    }
                    mysqli_stmt_close($stmt);
                }
            } elseif ($action === 'reject') {
                // Only allow if status is 'Pending Assignment'
                if ($current_status !== 'Pending Assignment') {
                    $error = "This request cannot be rejected (current status: $current_status).";
                } else {
                    $update_query = "UPDATE quotation_requests SET project_engineer_id = NULL, project_engineer_name = NULL, status = 'Pending Assignment', updated_at = NOW() WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt, "i", $request_id);
                    if (mysqli_stmt_execute($stmt)) {
                        $success = "Quotation request rejected. It will remain pending assignment.";

                        $creatorId = (int)($req_info['requested_by'] ?? 0);
                        if ($creatorId > 0 && $creatorId !== $empId) {
                            createNotificationCurrentDb(
                                $conn,
                                $creatorId,
                                'Quotation request rejected',
                                'Your quotation request ' . ($req_info['request_no'] ?? '') . ' has been rejected by TL.',
                                'quotation_requests',
                                $request_id,
                                'view-quotation-request.php?id=' . $request_id,
                                'quotation'
                            );
                        }
                    } else {
                        $error = "Failed to reject quotation request.";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

// Handle POST forward request (separate from GET actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forward_to_qs') {
    $request_id = intval($_POST['id'] ?? 0);
    $qs_employee_id = intval($_POST['qs_employee_id'] ?? 0);

    if ($request_id <= 0 || $qs_employee_id <= 0) {
        $error = "Invalid request or QS selection.";
    } else {
        // Get current request details
        $site_id_query = "SELECT id, site_id, status, project_engineer_id, request_no, title, requested_by FROM quotation_requests WHERE id = ?";
        $stmt = mysqli_prepare($conn, $site_id_query);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $req_info = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$req_info) {
            $error = "Request not found.";
        } else {
            $site_id = $req_info['site_id'];
            $current_status = $req_info['status'];

            // Only TL can forward to QS, and only for their own TL projects.
            $is_tl_site = $isTl && isTlSite($conn, (int)$site_id, (int)$empId);

            if (!$is_tl_site) {
                $error = "Only the assigned Team Lead can forward this request.";
            } elseif ($current_status !== 'Assigned') {
                $error = "Cannot forward this request. It must be in 'Assigned' status (current: $current_status).";
            } elseif ($req_info['project_engineer_id'] != $empId) {
                $error = "This request is not assigned to you.";
            } else {
                // Verify the selected QS employee is valid (active QS)
                $check_qs_query = "SELECT id FROM employees WHERE id = ? AND (department = 'QS' OR designation LIKE '%QS%') AND employee_status = 'active'";
                $stmt = mysqli_prepare($conn, $check_qs_query);
                mysqli_stmt_bind_param($stmt, "i", $qs_employee_id);
                mysqli_stmt_execute($stmt);
                $qs_res = mysqli_stmt_get_result($stmt);
                $qs_exists = mysqli_num_rows($qs_res) > 0;
                mysqli_stmt_close($stmt);

                if (!$qs_exists) {
                    $error = "Selected employee is not a valid QS staff.";
                } else {
                    $update_query = "UPDATE quotation_requests SET status = 'With QS', qs_assigned_at = NOW(), qs_assigned_by = ?, qs_employee_id = ?, updated_at = NOW() WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt, "iii", $empId, $qs_employee_id, $request_id);
                    if (mysqli_stmt_execute($stmt)) {
                        $success = "Quotation request forwarded to QS successfully.";

                        createNotificationCurrentDb(
                            $conn,
                            $qs_employee_id,
                            'Quotation request assigned to you',
                            'Quotation request ' . ($req_info['request_no'] ?? '') . ' has been forwarded to you by TL.',
                            'quotation_requests',
                            $request_id,
                            'qs-quotation-requests.php',
                            'quotation'
                        );
                    } else {
                        $error = "Failed to forward request to QS.";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function roleKeyFromDesignation(string $designation, string $department = ''): string {
    $d = strtolower(trim($designation));
    $dept = strtolower(trim($department));

    if (
        str_contains($d, 'director') ||
        str_contains($d, 'admin') ||
        str_contains($d, 'administrator') ||
        str_contains($d, 'vice president') ||
        str_contains($d, 'general manager')
    ) return 'admin';

    if (str_contains($d, 'hr') || str_contains($dept, 'hr') || str_contains($dept, 'human resource')) {
        return 'admin';
    }

    if (str_contains($d, 'manager')) return 'manager';

    if (
        str_contains($d, 'team lead') ||
        str_contains($d, 'teamleader') ||
        str_contains($d, 'tl') ||
        str_contains($d, 'lead')
    ) return 'tl';

    if (
        str_contains($d, 'project engineer') ||
        str_contains($d, 'engineer')
    ) return 'project_engineer';

    return 'other';
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

function createNotificationCurrentDb(
    $conn,
    int $employeeId,
    string $title,
    string $message,
    string $module,
    int $referenceId,
    string $link,
    string $type = 'quotation'
): bool {
    if ($employeeId <= 0 || !$conn || !tableExists($conn, 'notifications')) {
        return false;
    }

    $columns = [];
    $placeholders = [];
    $types = '';
    $values = [];

    $map = [
        'employee_id'  => ['i', $employeeId],
        'title'        => ['s', $title],
        'message'      => ['s', $message],
        'type'         => ['s', $type],
        'module'       => ['s', $module],
        'reference_id' => ['i', $referenceId],
        'link'         => ['s', $link],
        'priority'     => ['s', 'normal'],
        'is_read'      => ['i', 0],
        'created_at'   => ['raw', 'NOW()'],
    ];

    foreach ($map as $column => $pair) {
        if (columnExists($conn, 'notifications', $column)) {
            $columns[] = "`$column`";

            if ($pair[0] === 'raw') {
                $placeholders[] = $pair[1];
            } else {
                $placeholders[] = '?';
                $types .= $pair[0];
                $values[] = $pair[1];
            }
        }
    }

    if (!$columns) return false;

    $sql = "INSERT INTO notifications (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;

    if ($values) {
        mysqli_stmt_bind_param($stmt, $types, ...$values);
    }

    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function isTlSite($conn, int $siteId, int $employeeId): bool {
    if ($siteId <= 0 || $employeeId <= 0) return false;

    $stmt = mysqli_prepare($conn, "
        SELECT id
        FROM sites
        WHERE id = ?
          AND team_lead_employee_id = ?
          AND deleted_at IS NULL
        LIMIT 1
    ");

    if (!$stmt) return false;

    mysqli_stmt_bind_param($stmt, "ii", $siteId, $employeeId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = $res && mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    return (bool)$ok;
}


function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function getPriorityBadge($priority) {
    $classes = [
        'Low' => 'neutral',
        'Medium' => 'progressing',
        'High' => 'warning',
        'Urgent' => 'atrisk'
    ];
    $class = $classes[$priority] ?? 'neutral';
    return '<span class="badge-pill ' . $class . '"><span class="mini-dot"></span>' . e($priority) . '</span>';
}

function getStatusBadge($status) {
    $classes = [
        'Draft' => 'neutral',
        'Pending Assignment' => 'pending',
        'Assigned' => 'progressing',
        'Quotations Received' => 'progressing',
        'With QS' => 'pending',
        'QS Finalized' => 'ontrack',
        'Approved' => 'ontrack',
        'Rejected' => 'atrisk',
        'Cancelled' => 'neutral'
    ];
    $class = $classes[$status] ?? 'neutral';
    return '<span class="badge-pill ' . $class . '"><span class="mini-dot"></span>' . e($status) . '</span>';
}

// ---------- Fetch requests based on role ----------
// TL: only sites where logged employee is team_lead_employee_id, with all action buttons.
// Manager: only sites where logged employee is manager_employee_id, view-only.
// Admin/HR: all quotation requests, view-only.
$scope_site_ids = [];
$scope_site_names = [];

if ($isTl) {
    $scope_sites_query = "SELECT id, project_name FROM sites WHERE team_lead_employee_id = ? AND deleted_at IS NULL";
    $stmt = mysqli_prepare($conn, $scope_sites_query);
    mysqli_stmt_bind_param($stmt, "i", $empId);
    mysqli_stmt_execute($stmt);
    $scope_sites_result = mysqli_stmt_get_result($stmt);
    while ($site = mysqli_fetch_assoc($scope_sites_result)) {
        $scope_site_ids[] = (int)$site['id'];
        $scope_site_names[(int)$site['id']] = $site['project_name'];
    }
    mysqli_stmt_close($stmt);
} elseif ($isManager) {
    $scope_sites_query = "SELECT id, project_name FROM sites WHERE manager_employee_id = ? AND deleted_at IS NULL";
    $stmt = mysqli_prepare($conn, $scope_sites_query);
    mysqli_stmt_bind_param($stmt, "i", $empId);
    mysqli_stmt_execute($stmt);
    $scope_sites_result = mysqli_stmt_get_result($stmt);
    while ($site = mysqli_fetch_assoc($scope_sites_result)) {
        $scope_site_ids[] = (int)$site['id'];
        $scope_site_names[(int)$site['id']] = $site['project_name'];
    }
    mysqli_stmt_close($stmt);
}

$tl_site_ids = $isTl ? $scope_site_ids : [];
$tl_site_names = $isTl ? $scope_site_names : [];

$base_select = "
    SELECT
        qr.*,
        s.project_name,
        s.project_code,
        s.project_location,
        s.scope_of_work,
        c.client_name,
        c.company_name,
        c.mobile_number AS client_mobile,
        m.full_name AS manager_name,
        m.employee_code AS manager_code,
        tl.full_name AS tl_name,
        tl.employee_code AS tl_code,
        DATEDIFF(qr.required_by_date, CURDATE()) AS days_remaining
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN employees m ON s.manager_employee_id = m.id
    LEFT JOIN employees tl ON s.team_lead_employee_id = tl.id
";

$order_sql = "
    ORDER BY
        CASE
            WHEN qr.priority = 'Urgent' THEN 1
            WHEN qr.priority = 'High' THEN 2
            WHEN qr.priority = 'Medium' THEN 3
            ELSE 4
        END,
        qr.required_by_date ASC,
        qr.created_at DESC
";

if ($isAdmin) {
    $sql = $base_select . "
        WHERE s.deleted_at IS NULL
        $order_sql
    ";

    $res = mysqli_query($conn, $sql);
    if ($res) {
        $requests = mysqli_fetch_all($res, MYSQLI_ASSOC);
    } else {
        $error = "Database error: " . mysqli_error($conn);
    }
} elseif (empty($scope_site_ids)) {
    $requests = [];
} else {
    $placeholders = implode(',', array_fill(0, count($scope_site_ids), '?'));

    $statusFilter = $isTl
        ? "AND qr.status IN ('Pending Assignment', 'Assigned')"
        : "";

    $sql = $base_select . "
        WHERE qr.site_id IN ($placeholders)
          AND s.deleted_at IS NULL
          $statusFilter
        $order_sql
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, str_repeat('i', count($scope_site_ids)), ...$scope_site_ids);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $requests = mysqli_fetch_all($res, MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    } else {
        $error = "Database error: " . mysqli_error($conn);
    }
}

// ---------- Stats ----------
$total_assigned = count($requests);
$new_count = 0;
$accepted_count = 0;
$urgent_count = 0;
$overdue_count = 0;

foreach ($requests as $req) {
    if ($req['status'] === 'Pending Assignment') $new_count++;
    elseif ($req['status'] === 'Assigned') $accepted_count++;
    if ($req['priority'] === 'Urgent') $urgent_count++;
    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
        $required = strtotime($req['required_by_date']);
        if ($required < time()) $overdue_count++;
    }
}

// Fetch list of QS employees for the modal
$qs_employees = [];
$qs_query = "SELECT id, full_name FROM employees WHERE (department = 'QS' OR designation LIKE '%QS%') AND employee_status = 'active' ORDER BY full_name";
$qs_result = mysqli_query($conn, $qs_query);
if ($qs_result) {
    while ($row = mysqli_fetch_assoc($qs_result)) {
        $qs_employees[] = $row;
    }
}

// ---------- UI Filters ----------
$filter_status = trim((string)($_GET['status'] ?? ''));
$filter_priority = trim((string)($_GET['priority'] ?? ''));
$filter_search = trim((string)($_GET['search'] ?? ''));

$filtered_requests = array_values(array_filter($requests, function($req) use ($filter_status, $filter_priority, $filter_search) {
    if ($filter_status !== '' && (string)($req['status'] ?? '') !== $filter_status) {
        return false;
    }

    if ($filter_priority !== '' && (string)($req['priority'] ?? '') !== $filter_priority) {
        return false;
    }

    if ($filter_search !== '') {
        $haystack = strtolower(
            (string)($req['request_no'] ?? '') . ' ' .
            (string)($req['title'] ?? '') . ' ' .
            (string)($req['project_name'] ?? '') . ' ' .
            (string)($req['project_code'] ?? '') . ' ' .
            (string)($req['quotation_type'] ?? '') . ' ' .
            (string)($req['manager_name'] ?? '') . ' ' .
            (string)($req['tl_name'] ?? '')
        );

        if (!str_contains($haystack, strtolower($filter_search))) {
            return false;
        }
    }

    return true;
}));

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Assigned Quotations - TEK-C</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />


    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        :root{
            --page-bg:#f5f7fb;
            --card-bg:#ffffff;
            --border:#e5e7eb;
            --text:#111827;
            --muted:#6b7280;
            --soft:#f8fafc;
            --shadow:0 10px 26px rgba(15,23,42,.055);
            --radius:15px;
            --blue:#2f80ed;
            --green:#27ae60;
            --orange:#f2994a;
            --red:#eb5757;
            --purple:#7c3aed;
        }

        body{ background:var(--page-bg); }

        .content-scroll{
            flex:1 1 auto;
            overflow:auto;
            padding:16px;
        }

        .projects-wrapper{ width:100%; }

        .page-heading{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .page-heading h1{
            font-size:19px;
            font-weight:900;
            color:var(--text);
            margin:0;
        }

        .page-heading p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:12px;
            font-weight:600;
        }

        .primary-btn,.secondary-btn,.btn-action{
            min-height:36px;
            padding:0 14px;
            border-radius:11px;
            font-size:12px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            text-decoration:none;
            white-space:nowrap;
        }

        .primary-btn{
            border:0;
            background:#111827;
            color:#fff;
        }

        .primary-btn:hover{
            background:#020617;
            color:#fff;
        }

        .secondary-btn,.btn-action{
            border:1px solid var(--border);
            background:#fff;
            color:#334155;
        }

        .secondary-btn:hover,.btn-action:hover{
            border-color:#cbd5e1;
            background:#f8fafc;
            color:#111827;
        }

        .panel,.filter-card{
            background:var(--card-bg);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:13px;
            margin-bottom:14px;
            height:auto;
        }

        .panel-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:12px;
        }

        .panel-title{
            font-weight:900;
            font-size:14px;
            color:var(--text);
            margin:0;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .panel-title i{
            color:var(--blue);
            font-size:16px;
        }

        .panel-subtitle{
            color:var(--muted);
            font-size:11px;
            font-weight:700;
            margin-top:2px;
        }

        .panel-menu{
            width:34px;
            height:34px;
            border-radius:11px;
            border:1px solid var(--border);
            background:#fff;
            display:grid;
            place-items:center;
            color:#64748b;
            flex:0 0 auto;
        }

        .stat-card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:12px 13px;
            min-height:78px;
            display:flex;
            align-items:center;
            gap:11px;
            transition:.15s ease;
        }

        .stat-card:hover{
            transform:translateY(-1px);
            box-shadow:0 14px 32px rgba(15,23,42,.09);
        }

        .stat-ic{
            width:38px;
            height:38px;
            border-radius:12px;
            display:grid;
            place-items:center;
            color:#fff;
            font-size:17px;
            flex:0 0 auto;
        }

        .stat-ic.blue{ background:var(--blue); }
        .stat-ic.green{ background:var(--green); }
        .stat-ic.yellow{ background:var(--orange); }
        .stat-ic.red{ background:var(--red); }
        .stat-ic.purple{ background:var(--purple); }

        .stat-label{
            color:#64748b;
            font-weight:800;
            font-size:10.5px;
            text-transform:uppercase;
        }

        .stat-value{
            font-size:24px;
            font-weight:950;
            line-height:1;
            color:#111827;
        }

        .form-label{
            font-size:11px;
            font-weight:900;
            color:#475569;
            text-transform:uppercase;
            margin-bottom:6px;
        }

        .form-control,.form-select{
            min-height:38px;
            border:1px solid var(--border);
            border-radius:11px;
            font-size:12px;
            font-weight:800;
            color:#111827;
            padding:8px 11px;
            background:#fff;
        }

        .form-control:focus,.form-select:focus{
            border-color:#bfdbfe;
            box-shadow:0 0 0 3px rgba(59,130,246,.10);
        }

        .badge-pill{
            border-radius:999px;
            padding:5px 8px;
            font-weight:900;
            font-size:10px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            border:1px solid transparent;
            text-decoration:none;
            white-space:nowrap;
        }

        .mini-dot{
            width:6px;
            height:6px;
            border-radius:50%;
            background:currentColor;
        }

        .ontrack{ color:#15803d; background:#dcfce7; border-color:#bbf7d0; }
        .progressing{ color:#2563eb; background:#dbeafe; border-color:#bfdbfe; }
        .pending{ color:#6d28d9; background:#ede9fe; border-color:#ddd6fe; }
        .atrisk{ color:#b91c1c; background:#fee2e2; border-color:#fecaca; }
        .neutral{ color:#475569; background:#f1f5f9; border-color:#e2e8f0; }
        .warning{ color:#b45309; background:#ffedd5; border-color:#fed7aa; }

        .btn-action{
            min-height:32px;
            padding:0 10px;
            border-radius:10px;
        }

        .btn-action.view:hover{
            color:#2563eb;
            border-color:#bfdbfe;
            background:#eff6ff;
        }

        .btn-action.accept:hover{
            color:#15803d;
            border-color:#86efac;
            background:#dcfce7;
        }

        .btn-action.reject:hover{
            color:#b91c1c;
            border-color:#fecaca;
            background:#fee2e2;
        }

        .btn-action.forward:hover{
            color:#6d28d9;
            border-color:#ddd6fe;
            background:#ede9fe;
        }

        .proj-title{
            font-weight:950;
            font-size:12px;
            color:#111827;
            margin:0;
            line-height:1.25;
        }

        .proj-sub{
            font-size:10.5px;
            color:#64748b;
            font-weight:750;
            line-height:1.35;
            margin-top:2px;
        }

        .days-badge{
            background:#eff6ff;
            color:#2563eb;
            border:1px solid #bfdbfe;
            font-weight:900;
            padding:4px 8px;
            border-radius:999px;
            font-size:10px;
            display:inline-flex;
            align-items:center;
            gap:5px;
            margin-left:4px;
        }

        .days-badge.overdue{
            background:#fee2e2;
            color:#b91c1c;
            border-color:#fecaca;
        }

        .compact-table-wrap{
            width:100%;
            border:1px solid var(--border);
            border-radius:13px;
            overflow:hidden;
            background:#fff;
        }

        .compact-table{
            width:100%;
            margin:0;
            table-layout:auto;
        }

        .compact-table thead th{
            background:var(--soft);
            color:#64748b;
            font-size:10px;
            text-transform:uppercase;
            font-weight:900;
            border-bottom:1px solid var(--border)!important;
            padding:8px 9px;
        }

        .compact-table tbody td{
            padding:8px 9px;
            vertical-align:middle;
            border-color:#eef2f7;
            color:#334155;
            font-weight:700;
            font-size:11.5px;
        }

        .compact-table tbody tr:hover{
            background:#fbfdff;
        }

        .table-primary-text{
            color:#111827;
            font-size:11.5px;
            font-weight:950;
        }

        .table-secondary-text{
            color:#64748b;
            font-size:10px;
            font-weight:700;
            margin-top:2px;
        }

        .actions-col{
            width:210px;
        }

        .request-card{
            border:1px solid var(--border);
            border-radius:14px;
            background:#fff;
            box-shadow:var(--shadow);
            padding:12px;
        }

        .request-card.urgent{ border-left:4px solid #dc2626; }
        .request-card.high{ border-left:4px solid #f59e0b; }
        .request-card.overdue{ background:#fffafa; }

        .request-card .top{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:10px;
        }

        .request-card .title{
            font-weight:950;
            color:#111827;
            font-size:13px;
            line-height:1.25;
            margin:0;
        }

        .request-card .meta{
            margin-top:6px;
            display:flex;
            flex-wrap:wrap;
            gap:8px 10px;
            color:#64748b;
            font-weight:750;
            font-size:10.5px;
        }

        .request-kv{
            margin-top:10px;
            display:grid;
            gap:7px;
        }

        .request-row{
            display:flex;
            gap:10px;
            align-items:flex-start;
        }

        .request-key{
            flex:0 0 85px;
            color:#64748b;
            font-weight:900;
            font-size:10.5px;
            text-transform:uppercase;
        }

        .request-val{
            flex:1 1 auto;
            font-weight:900;
            color:#111827;
            font-size:11.5px;
            line-height:1.3;
            word-break:break-word;
        }

        .request-actions{
            margin-top:12px;
            display:flex;
            gap:8px;
            justify-content:flex-end;
            flex-wrap:wrap;
        }

        .empty-state{
            text-align:center;
            padding:30px 12px;
            color:#64748b;
            font-size:12px;
            font-weight:900;
        }

        .empty-state i{
            display:block;
            font-size:34px;
            opacity:.45;
            margin-bottom:8px;
        }

        .quick-guide-card{
            border:1px solid #eef2f7;
            border-radius:13px;
            background:#f8fafc;
            padding:12px;
            height:100%;
        }

        .quick-guide-card h6{
            font-size:12px;
            font-weight:950;
            margin-bottom:3px;
            color:#111827;
        }

        .quick-guide-card p{
            font-size:10.5px;
            color:#64748b;
            margin:0;
            font-weight:750;
            line-height:1.35;
        }

        .modal-content{
            border:1px solid var(--border);
            border-radius:16px;
            box-shadow:0 24px 55px rgba(15,23,42,.18);
        }

        .modal-header{
            border-bottom:1px solid #eef2f7;
        }

        .modal-title{
            font-size:15px;
            font-weight:950;
            color:#111827;
        }

        .alert{
            border-radius:14px;
            border:1px solid transparent;
            box-shadow:var(--shadow);
            font-size:12px;
            font-weight:850;
            margin-bottom:14px;
        }

        .alert-success{
            background:#dcfce7;
            border-color:#bbf7d0;
            color:#166534;
        }

        .alert-danger{
            background:#fee2e2;
            border-color:#fecaca;
            color:#991b1b;
        }

        .alert-info{
            background:#eff6ff;
            border-color:#bfdbfe;
            color:#1e40af;
        }

        @media(max-width:991.98px){
            .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
            .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
            .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0)!important; }
        }

        @media(max-width:1199px){
            .compact-table thead{ display:none; }
            .compact-table,.compact-table tbody,.compact-table tr,.compact-table td{
                display:block;
                width:100%;
            }
            .compact-table tbody tr{
                border-bottom:1px solid var(--border);
                padding:10px;
            }
            .compact-table tbody td{
                border:0;
                display:flex;
                justify-content:space-between;
                gap:12px;
            }
            .compact-table tbody td::before{
                content:attr(data-label);
                font-size:10px;
                font-weight:900;
                color:#64748b;
                text-transform:uppercase;
                flex:0 0 105px;
            }
            .compact-table tbody td:first-child{
                display:block;
            }
            .compact-table tbody td:first-child::before{
                display:none;
            }
            .actions-col{
                width:auto!important;
            }
        }

        @media(max-width:768px){
            .content-scroll{ padding:12px 10px!important; }
            .container-fluid.projects-wrapper{ padding-left:0!important; padding-right:0!important; }
            .page-heading{ align-items:flex-start; flex-direction:column; }
            .panel,.filter-card{ padding:12px; }
            .primary-btn,.secondary-btn{ width:100%; }
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid projects-wrapper px-0">

                <!-- Status Messages -->
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Page Header -->
                <div class="page-heading">
                    <div>
                        <h1>Assigned Quotations</h1>
                        <p>
                            <?php if ($isTl): ?>
                                TL action workspace for your assigned projects.
                            <?php elseif ($isManager): ?>
                                Project manager view-only quotation requests.
                            <?php else: ?>
                                Admin / HR view-only quotation requests.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill neutral">
                            <i class="bi bi-person-badge"></i>
                            <?php echo e(strtoupper(str_replace('_', ' ', $currentRoleKey))); ?>
                        </span>

                        <?php if ($isTl && !empty($tl_site_ids)): ?>
                            <span class="badge-pill progressing">
                                <i class="bi bi-kanban"></i>
                                <?php echo count($tl_site_ids); ?> TL Site(s)
                            </span>
                        <?php elseif ($isManager): ?>
                            <span class="badge-pill progressing">
                                <i class="bi bi-kanban"></i>
                                <?php echo count($scope_site_ids); ?> Manager Site(s)
                            </span>
                        <?php elseif ($isAdmin): ?>
                            <span class="badge-pill neutral">
                                <i class="bi bi-shield-check"></i>
                                View Only
                            </span>
                        <?php endif; ?>

                        <a href="dealers-directory.php" class="secondary-btn">
                            <i class="bi bi-shop"></i>
                            Dealers
                        </a>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-file-text"></i></div>
                            <div>
                                <div class="stat-label">Total Requests</div>
                                <div class="stat-value"><?php echo (int)$total_assigned; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-clock"></i></div>
                            <div>
                                <div class="stat-label">New Requests</div>
                                <div class="stat-value"><?php echo (int)$new_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="stat-label">In Progress</div>
                                <div class="stat-value"><?php echo (int)$accepted_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-exclamation-triangle"></i></div>
                            <div>
                                <div class="stat-label">Urgent / Overdue</div>
                                <div class="stat-value"><?php echo (int)($urgent_count + $overdue_count); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-card">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Request, project, type..." value="<?php echo e($filter_search); ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <?php foreach (['Pending Assignment','Assigned','With QS','QS Finalized','Approved','Rejected','Cancelled'] as $st): ?>
                                    <option value="<?php echo e($st); ?>" <?php echo $filter_status === $st ? 'selected' : ''; ?>>
                                        <?php echo e($st); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-2">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <option value="">All Priority</option>
                                <?php foreach (['Low','Medium','High','Urgent'] as $pr): ?>
                                    <option value="<?php echo e($pr); ?>" <?php echo $filter_priority === $pr ? 'selected' : ''; ?>>
                                        <?php echo e($pr); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-auto">
                            <button type="submit" class="primary-btn">
                                <i class="bi bi-search"></i>
                                Filter
                            </button>
                        </div>

                        <div class="col-12 col-md-auto">
                            <a href="assigned-quotations.php" class="secondary-btn">
                                <i class="bi bi-arrow-counterclockwise"></i>
                                Reset
                            </a>
                        </div>
                    </form>
                </div>

                <?php if (!$isAdmin && empty($scope_site_ids)): ?>
                <div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    <?php echo $isTl ? 'You are not assigned as Team Lead to any sites.' : 'You are not assigned as Project Manager to any sites.'; ?>
                    Please contact admin/HR to assign projects.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Directory -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">
                                <i class="bi bi-file-earmark-text"></i>
                                Quotation Requests
                            </h3>
                            <div class="panel-subtitle">
                                Showing <?php echo count($filtered_requests); ?> of <?php echo count($requests); ?> request(s)
                            </div>
                        </div>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <!-- MOBILE: Cards -->
                    <div class="d-block d-md-none">
                        <div class="d-grid gap-3">
                            <?php if (empty($filtered_requests)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    No quotation requests found.
                                    <div class="table-secondary-text mt-1">Requests will appear here based on your role scope.</div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($filtered_requests as $req): 
                                    $cardClass = '';
                                    if ($req['priority'] === 'Urgent') $cardClass = 'urgent';
                                    elseif ($req['priority'] === 'High') $cardClass = 'high';
                                    $isOverdue = false;
                                    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
                                        $required = strtotime($req['required_by_date']);
                                        if ($required < time()) { $cardClass .= ' overdue'; $isOverdue = true; }
                                    }
                                ?>
                                    <div class="request-card <?php echo $cardClass; ?>">
                                        <div class="top">
                                            <div style="flex:1 1 auto;">
                                                <div class="d-flex align-items-center justify-content-between gap-2">
                                                    <h4 class="title"><?php echo e($req['title']); ?></h4>
                                                    <?php echo getPriorityBadge($req['priority'] ?? 'Medium'); ?>
                                                </div>
                                                <div class="meta">
                                                    <span><i class="bi bi-building"></i> <?php echo e($req['project_name'] ?? ''); ?></span>
                                                    <span><i class="bi bi-tag"></i> <?php echo e($req['quotation_type'] ?? ''); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="request-kv">
                                            <div class="request-row"><div class="request-key">Request No.</div><div class="request-val"><?php echo e($req['request_no']); ?></div></div>
                                            <div class="request-row"><div class="request-key">Manager</div><div class="request-val"><?php echo e($req['manager_name'] ?? '—'); ?></div></div>
                                            <div class="request-row"><div class="request-key">Required By</div><div class="request-val"><?php echo safeDate($req['required_by_date']); ?> <?php if (!empty($req['days_remaining']) && $req['days_remaining'] > 0): ?><span class="days-badge"><?php echo $req['days_remaining']; ?> days left</span><?php elseif ($isOverdue): ?><span class="days-badge overdue">Overdue</span><?php endif; ?></div></div>
                                            <div class="request-row"><div class="request-key">Status</div><div class="request-val"><?php echo getStatusBadge($req['status']); ?></div></div>
                                            <?php if (!empty($req['estimated_budget']) && $req['estimated_budget'] > 0): ?>
                                            <div class="request-row"><div class="request-key">Budget</div><div class="request-val">₹ <?php echo number_format($req['estimated_budget'], 2); ?></div></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="request-actions">
                                            <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view" title="View Details"><i class="bi bi-eye"></i> View</a>
                                            <?php
                                                $canManageThis = $isTl && (int)($req['site_id'] ?? 0) > 0 && in_array((int)$req['site_id'], array_map('intval', $tl_site_ids), true);
                                            ?>
                                            <?php if ($canManageThis && $req['status'] === 'Pending Assignment'): ?>
                                                <a href="?action=accept&id=<?php echo $req['id']; ?>" class="btn-action accept" onclick="return confirm('Accept this quotation request?')" title="Accept"><i class="bi bi-check-lg"></i> Accept</a>
                                                <a href="?action=reject&id=<?php echo $req['id']; ?>" class="btn-action reject" onclick="return confirm('Reject this quotation request?')" title="Reject"><i class="bi bi-x-lg"></i> Reject</a>
                                            <?php elseif ($canManageThis && $req['status'] === 'Assigned'): ?>
                                                <button type="button" class="btn-action forward" data-bs-toggle="modal" data-bs-target="#forwardModal" data-request-id="<?php echo $req['id']; ?>" title="Forward to QS"><i class="bi bi-send"></i> Forward to QS</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- DESKTOP/TABLET: DataTable -->
                    <div class="d-none d-md-block">
                        <div class="compact-table-wrap">
                            <table id="assignedQuotationsTable" class="table compact-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Request No.</th>
                                        <th>Title / Site</th>
                                        <th>Type</th>
                                        <th>Manager</th>
                                        <th>Required By</th>
                                        <th>Budget</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th class="text-end actions-col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($filtered_requests as $req): 
                                    $isOverdue = false;
                                    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
                                        $required = strtotime($req['required_by_date']);
                                        if ($required < time()) $isOverdue = true;
                                    }
                                ?>
                                    <tr>
                                        <td data-label="Request No."><span class="table-primary-text"><?php echo e($req['request_no']); ?></span></td>
                                        <td data-label="Title / Site">
                                            <div class="proj-title"><?php echo e($req['title']); ?></div>
                                            <div class="proj-sub"><i class="bi bi-building"></i> <?php echo e($req['project_name']); ?><?php if (!empty($req['project_code'])): ?> (<?php echo e($req['project_code']); ?>)<?php endif; ?></div>
                                        </td>
                                        <td data-label="Type"><?php echo e($req['quotation_type']); ?></td>
                                        <td data-label="Manager">
                                            <div class="table-primary-text"><?php echo e($req['manager_name'] ?? '—'); ?></div>
                                            <?php if (!empty($req['manager_code'])): ?><div class="proj-sub"><?php echo e($req['manager_code']); ?></div><?php endif; ?>
                                        </td>
                                        <td data-label="Required By">
                                            <div class="table-primary-text <?php echo $isOverdue ? 'text-danger' : ''; ?>"><?php echo safeDate($req['required_by_date']); ?></div>
                                            <?php if (!empty($req['days_remaining']) && $req['days_remaining'] > 0): ?>
                                                <div class="proj-sub"><?php echo $req['days_remaining']; ?> days left</div>
                                            <?php elseif ($isOverdue): ?>
                                                <div class="proj-sub text-danger">Overdue</div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Budget"><?php if (!empty($req['estimated_budget']) && $req['estimated_budget'] > 0): ?><span class="table-primary-text">₹ <?php echo number_format($req['estimated_budget'], 2); ?></span><?php else: ?>—<?php endif; ?></td>
                                        <td data-label="Priority"><?php echo getPriorityBadge($req['priority']); ?></td>
                                        <td data-label="Status"><?php echo getStatusBadge($req['status']); ?></td>
                                        <td data-label="Actions" class="text-end actions-col">
                                            <a href="view-quotation-request.php?id=<?php echo $req['id']; ?>" class="btn-action view" title="View Details"><i class="bi bi-eye"></i></a>
                                            <?php
                                                $canManageThis = $isTl && (int)($req['site_id'] ?? 0) > 0 && in_array((int)$req['site_id'], array_map('intval', $tl_site_ids), true);
                                            ?>
                                            <?php if ($canManageThis && $req['status'] === 'Pending Assignment'): ?>
                                                <a href="?action=accept&id=<?php echo $req['id']; ?>" class="btn-action accept" onclick="return confirm('Accept this quotation request?')" title="Accept"><i class="bi bi-check-lg"></i></a>
                                                <a href="?action=reject&id=<?php echo $req['id']; ?>" class="btn-action reject" onclick="return confirm('Reject this quotation request?')" title="Reject"><i class="bi bi-x-lg"></i></a>
                                            <?php elseif ($canManageThis && $req['status'] === 'Assigned'): ?>
                                                <button type="button" class="btn-action forward" data-bs-toggle="modal" data-bs-target="#forwardModal" data-request-id="<?php echo $req['id']; ?>" title="Forward to QS"><i class="bi bi-send"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($filtered_requests)): ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No quotation requests found.
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- Quick Tips Panel -->
                <div class="panel">
                    <div class="panel-header"><h3 class="panel-title"><?php echo $isTl ? "Quick Guide" : "View Only"; ?></h3><button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button></div>
                    <div class="row g-3">
                        <div class="col-md-4"><div class="d-flex gap-3"><div class="stat-ic blue" style="width: 40px; height: 40px; font-size: 16px;"><i class="bi bi-clock"></i></div><div><h6 class="fw-900 mb-1">New Requests</h6><p class="small text-muted mb-0">Accept or reject new assignments. Accept to forward to QS.</p></div></div></div>
                        <div class="col-md-4"><div class="d-flex gap-3"><div class="stat-ic purple" style="width: 40px; height: 40px; font-size: 16px;"><i class="bi bi-send"></i></div><div><h6 class="fw-900 mb-1">Forward to QS</h6><p class="small text-muted mb-0">After acceptance, select a QS person and forward the request.</p></div></div></div>
                        <div class="col-md-4"><div class="d-flex gap-3"><div class="stat-ic green" style="width: 40px; height: 40px; font-size: 16px;"><i class="bi bi-check-circle"></i></div><div><h6 class="fw-900 mb-1">QS Handles</h6><p class="small text-muted mb-0">QS will manage quotations and finalize.</p></div></div></div>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<?php if ($isTl): ?>
<!-- Forward to QS Modal -->
<div class="modal fade" id="forwardModal" tabindex="-1" aria-labelledby="forwardModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" id="forwardForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="forwardModalLabel">Forward to QS</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="forward_to_qs">
                    <input type="hidden" name="id" id="forwardRequestId" value="">
                    <div class="mb-3">
                        <label for="qs_employee_id" class="form-label">Select QS Employee</label>
                        <select class="form-select" name="qs_employee_id" id="qs_employee_id" required>
                            <option value="">-- Select QS --</option>
                            <?php foreach ($qs_employees as $qs): ?>
                                <option value="<?php echo $qs['id']; ?>"><?php echo e($qs['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($qs_employees)): ?>
                            <div class="text-warning small mt-1">No active QS employees found. Please add QS staff first.</div>
                        <?php endif; ?>
                    </div>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle"></i> The selected QS will be assigned to this request and can manage quotations.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="primary-btn" <?php echo empty($qs_employees) ? 'disabled' : ''; ?>>Forward to QS</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-bs-toggle="modal"][data-bs-target="#forwardModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const requestId = this.getAttribute('data-request-id');
                const input = document.getElementById('forwardRequestId');
                if (input) input.value = requestId;
            });
        });

        const yearElement = document.getElementById("year");
        if (yearElement) {
            yearElement.textContent = new Date().getFullYear();
        }
    });
</script>

</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>