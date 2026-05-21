<?php
// view-quotation-request.php – View a single quotation request details

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['employee_id'];
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$user_department = strtolower(trim((string)($_SESSION['department'] ?? '')));

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: my-quotation-requests.php');
    exit();
}

$request_id = intval($_GET['id']);
$success = '';
$error = '';

// Helper functions
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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

    if (str_contains($d, 'qs') || str_contains($dept, 'qs')) return 'qs';

    if (
        str_contains($d, 'project engineer') ||
        str_contains($d, 'engineer')
    ) return 'project_engineer';

    return 'other';
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






$currentRoleKey = roleKeyFromDesignation($user_designation, $user_department);

function safeDate($v, $dash = '—') {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function formatCurrency($amount) {
    if ($amount === null || $amount == 0) return '—';
    return '₹ ' . number_format($amount, 2);
}

function getPriorityBadge($priority) {
    $priority = (string)$priority;
    $classes = [
        'Low' => 'neutral',
        'Medium' => 'progressing',
        'High' => 'warning',
        'Urgent' => 'atrisk'
    ];

    $icons = [
        'Low' => 'bi-arrow-down',
        'Medium' => 'bi-dash',
        'High' => 'bi-arrow-up',
        'Urgent' => 'bi-exclamation-triangle'
    ];

    $class = $classes[$priority] ?? 'neutral';
    $icon = $icons[$priority] ?? 'bi-question';

    return '<span class="badge-pill ' . $class . '"><i class="bi ' . $icon . '"></i>' . e($priority ?: '—') . '</span>';
}

function getStatusBadge($status) {
    $status = (string)$status;
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

    $icons = [
        'Draft' => 'bi-pencil',
        'Pending Assignment' => 'bi-clock',
        'Assigned' => 'bi-person-check',
        'Quotations Received' => 'bi-file-text',
        'With QS' => 'bi-arrow-right',
        'QS Finalized' => 'bi-check-circle',
        'Approved' => 'bi-check-circle-fill',
        'Rejected' => 'bi-x-circle',
        'Cancelled' => 'bi-x'
    ];

    $class = $classes[$status] ?? 'neutral';
    $icon = $icons[$status] ?? 'bi-question';

    return '<span class="badge-pill ' . $class . '"><i class="bi ' . $icon . '"></i>' . e($status ?: '—') . '</span>';
}

// Fetch quotation request details with full joins
$query = "
    SELECT 
        qr.*,
        s.project_name,
        s.project_code,
        s.project_location,
        s.project_type,
        s.scope_of_work,
        s.manager_employee_id,
        s.team_lead_employee_id,
        c.client_name,
        c.company_name,
        c.mobile_number AS client_mobile,
        c.email AS client_email,
        c.state AS client_state,
        m.full_name AS manager_name,
        m.employee_code AS manager_code,
        tl.full_name AS team_lead_name,
        tl.employee_code AS team_lead_code,
        pe.full_name AS project_engineer_name,
        qs_emp.full_name AS qs_employee_name,
        qs_emp.employee_code AS qs_employee_code,
        req_by.full_name AS requested_by_name,
        (SELECT GROUP_CONCAT(e.full_name SEPARATOR ', ')
         FROM site_project_engineers spe
         JOIN employees e ON e.id = spe.employee_id
         WHERE spe.site_id = qr.site_id
        ) AS project_engineers,
        final_q.id AS final_quotation_id,
        final_q.quotation_no AS final_quotation_no,
        final_q.total_amount AS final_quotation_amount,
        final_q.grand_total AS final_quotation_grand_total,
        final_q.delivery_terms AS final_delivery_terms,
        final_q.payment_terms AS final_payment_terms,
        final_q.warranty AS final_warranty,
        final_q.finalized_amount AS final_negotiated_amount,
        final_q.finalized_at AS final_quotation_date,
        final_q.qs_remarks AS final_qs_remarks,
        final_q.quotation_document AS final_quotation_document,   -- Added for download
        final_d.dealer_name AS final_dealer_name
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN employees m ON s.manager_employee_id = m.id
    LEFT JOIN employees tl ON s.team_lead_employee_id = tl.id
    LEFT JOIN employees pe ON qr.project_engineer_id = pe.id
    LEFT JOIN employees qs_emp ON qr.qs_employee_id = qs_emp.id
    LEFT JOIN employees req_by ON qr.requested_by = req_by.id
    LEFT JOIN quotations final_q ON qr.final_quotation_id = final_q.id
    LEFT JOIN quotation_dealers final_d ON final_q.dealer_id = final_d.id
    WHERE qr.id = ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$request) {
    header('Location: my-quotation-requests.php');
    exit();
}

// Permission checks
$is_manager = ($request['requested_by'] == $user_id); // requester / creator
$is_project_engineer = ($request['project_engineer_id'] == $user_id);
$is_qs = ($request['qs_employee_id'] == $user_id && in_array($request['status'], ['With QS', 'QS Finalized']));
$is_team_lead = false;
$is_admin = in_array($user_designation, ['director', 'vice president', 'general manager', 'admin', 'administrator']) || in_array($user_department, ['accounts', 'hr']);

if (!$is_admin) {
    // Check if user is team lead for this site
    $check_tl = "SELECT id FROM sites WHERE id = ? AND team_lead_employee_id = ?";
    $stmt = mysqli_prepare($conn, $check_tl);
    mysqli_stmt_bind_param($stmt, "ii", $request['site_id'], $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        $is_team_lead = true;
    }
    mysqli_stmt_close($stmt);
}

// Parse attachments JSON
$attachments = [];
if (!empty($request['additional_documents_json'])) {
    $attachments = json_decode($request['additional_documents_json'], true) ?: [];
}

// Fetch all quotations for comparison
$quotations = [];
$quotations_query = "
    SELECT q.*, d.dealer_name 
    FROM quotations q 
    LEFT JOIN quotation_dealers d ON q.dealer_id = d.id 
    WHERE q.quotation_request_id = ? 
    ORDER BY q.total_amount ASC
";
$stmt = mysqli_prepare($conn, $quotations_query);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$quotations = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Common role permissions for this page.
$is_request_creator = ((int)($request['requested_by'] ?? 0) === $user_id);
$is_tl_for_project = $is_team_lead;
$is_project_manager_for_project = ((int)($request['manager_employee_id'] ?? 0) === $user_id);
$is_admin_view = $is_admin;

// Access control for common page.
// Project Engineer/creator can view own request.
// TL can view/action project request.
// Manager/Admin can view only.
// QS can manage only when assigned.
$can_view_request = $is_admin_view || $is_request_creator || $is_project_manager_for_project || $is_tl_for_project || $is_qs || $is_project_engineer;
if (!$can_view_request) {
    header('Location: my-quotation-requests.php');
    exit();
}

// Fetch active QS employees for TL forward action.
$qs_employees = [];
$qs_query = "SELECT id, full_name FROM employees WHERE (department = 'QS' OR designation LIKE '%QS%') AND employee_status = 'active' ORDER BY full_name";
$qs_result = mysqli_query($conn, $qs_query);
if ($qs_result) {
    while ($row = mysqli_fetch_assoc($qs_result)) {
        $qs_employees[] = $row;
    }
}

// TL action handling: approve/accept request, then forward to selected QS.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tl_action'])) {
    if (!$is_tl_for_project) {
        $error = 'Only the assigned Team Lead can process this request.';
    } else {
        $tlAction = $_POST['tl_action'];

        if ($tlAction === 'approve_request') {
            if ($request['status'] !== 'Pending Assignment') {
                $error = 'This request cannot be approved now. Current status: ' . $request['status'];
            } else {
                $tlName = $_SESSION['employee_name'] ?? ($request['team_lead_name'] ?? '');
                $update = "UPDATE quotation_requests
                           SET project_engineer_id = ?,
                               project_engineer_name = ?,
                               status = 'Assigned',
                               assigned_at = COALESCE(assigned_at, NOW()),
                               updated_at = NOW()
                           WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "isi", $user_id, $tlName, $request_id);

                if ($stmt && mysqli_stmt_execute($stmt)) {
                    $success = 'Quotation request approved successfully. You can now forward it to QS.';

                    $creatorId = (int)($request['requested_by'] ?? 0);
                    if ($creatorId > 0 && $creatorId !== $user_id) {
                        createNotificationCurrentDb(
                            $conn,
                            $creatorId,
                            'Quotation request approved',
                            'Your quotation request ' . ($request['request_no'] ?? '') . ' has been approved by TL.',
                            'quotation_requests',
                            $request_id,
                            'view-quotation-request.php?id=' . $request_id,
                            'quotation'
                        );
                    }

                    header('Location: view-quotation-request.php?id=' . $request_id . '&success=approved');
                    exit();
                } else {
                    $error = 'Failed to approve request.';
                }

                if ($stmt) mysqli_stmt_close($stmt);
            }
        }

        if ($tlAction === 'forward_to_qs') {
            $qs_employee_id = (int)($_POST['qs_employee_id'] ?? 0);

            if ($qs_employee_id <= 0) {
                $error = 'Please select QS employee.';
            } elseif (!in_array($request['status'], ['Assigned', 'Pending Assignment'], true)) {
                $error = 'This request cannot be forwarded now. Current status: ' . $request['status'];
            } else {
                $check_qs_query = "SELECT id, full_name FROM employees WHERE id = ? AND (department = 'QS' OR designation LIKE '%QS%') AND employee_status = 'active' LIMIT 1";
                $stmt = mysqli_prepare($conn, $check_qs_query);
                mysqli_stmt_bind_param($stmt, "i", $qs_employee_id);
                mysqli_stmt_execute($stmt);
                $qs_res = mysqli_stmt_get_result($stmt);
                $qs_row = $qs_res ? mysqli_fetch_assoc($qs_res) : null;
                mysqli_stmt_close($stmt);

                if (!$qs_row) {
                    $error = 'Selected employee is not a valid active QS staff.';
                } else {
                    $tlName = $_SESSION['employee_name'] ?? ($request['team_lead_name'] ?? '');
                    $update = "UPDATE quotation_requests
                               SET project_engineer_id = COALESCE(project_engineer_id, ?),
                                   project_engineer_name = COALESCE(NULLIF(project_engineer_name, ''), ?),
                                   status = 'With QS',
                                   qs_assigned_at = NOW(),
                                   qs_assigned_by = ?,
                                   qs_employee_id = ?,
                                   updated_at = NOW()
                               WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update);
                    mysqli_stmt_bind_param($stmt, "isiii", $user_id, $tlName, $user_id, $qs_employee_id, $request_id);

                    if ($stmt && mysqli_stmt_execute($stmt)) {
                        createNotificationCurrentDb(
                            $conn,
                            $qs_employee_id,
                            'Quotation request assigned to you',
                            'Quotation request ' . ($request['request_no'] ?? '') . ' has been forwarded to you by TL.',
                            'quotation_requests',
                            $request_id,
                            'qs-quotation-requests.php',
                            'quotation'
                        );

                        $success = 'Quotation request forwarded to QS successfully.';
                        header('Location: view-quotation-request.php?id=' . $request_id . '&success=forwarded');
                        exit();
                    } else {
                        $error = 'Failed to forward request to QS.';
                    }

                    if ($stmt) mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'approved') $success = 'Quotation request approved successfully.';
    if ($_GET['success'] === 'forwarded') $success = 'Quotation request forwarded to QS successfully.';
}


?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Quotation Request #<?php echo e($request['request_no']); ?> - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
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
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:16px; }
        .projects-wrapper{ width:100%; }

        .page-heading{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:12px;
            margin-bottom:14px;
        }

        .page-heading h1{
            font-size:19px;
            font-weight:950;
            color:var(--text);
            margin:0;
            letter-spacing:-.01em;
        }

        .page-heading p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:12px;
            font-weight:650;
        }

        .primary-btn,.secondary-btn,.success-btn,.warning-btn,.danger-btn,.btn-action{
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
            line-height:1;
        }

        .primary-btn{ border:0; background:#111827; color:#fff; }
        .primary-btn:hover{ background:#020617; color:#fff; }

        .success-btn{ border:0; background:#16a34a; color:#fff; }
        .success-btn:hover{ background:#15803d; color:#fff; }

        .warning-btn{ border:0; background:#7c3aed; color:#fff; }
        .warning-btn:hover{ background:#6d28d9; color:#fff; }

        .danger-btn{ border:0; background:#dc2626; color:#fff; }
        .danger-btn:hover{ background:#b91c1c; color:#fff; }

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

        .panel,.action-panel{
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
            font-weight:950;
            font-size:14px;
            color:var(--text);
            margin:0;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .panel-title i{ color:var(--blue); font-size:16px; }
        .panel-subtitle{ color:var(--muted); font-size:11px; font-weight:700; margin-top:2px; }

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
            overflow:hidden;
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

        .stat-label{ color:#64748b; font-weight:850; font-size:10.5px; text-transform:uppercase; }
        .stat-value{ font-size:17px; font-weight:950; color:#111827; line-height:1.15; word-break:break-word; }

        .info-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(235px, 1fr));
            gap:10px;
            margin-top:10px;
        }

        .info-item{
            padding:10px 11px;
            background:#f8fafc;
            border-radius:12px;
            border:1px solid #eef2f7;
            min-width:0;
        }

        .info-label{
            font-size:10px;
            font-weight:950;
            color:#64748b;
            text-transform:uppercase;
            margin-bottom:3px;
        }

        .info-value{
            font-size:12px;
            font-weight:900;
            color:#111827;
            word-break:break-word;
        }

        .section-heading{
            font-size:12px;
            font-weight:950;
            color:#111827;
            margin:0 0 8px;
            display:flex;
            align-items:center;
            gap:7px;
        }

        .description-box{
            background:#f8fafc;
            border-radius:13px;
            padding:12px;
            border:1px solid #eef2f7;
            line-height:1.5;
            font-size:12px;
            font-weight:750;
            color:#334155;
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

        .mini-dot{ width:6px; height:6px; border-radius:50%; background:currentColor; }
        .ontrack{ color:#15803d; background:#dcfce7; border-color:#bbf7d0; }
        .progressing{ color:#2563eb; background:#dbeafe; border-color:#bfdbfe; }
        .pending{ color:#6d28d9; background:#ede9fe; border-color:#ddd6fe; }
        .atrisk{ color:#b91c1c; background:#fee2e2; border-color:#fecaca; }
        .neutral{ color:#475569; background:#f1f5f9; border-color:#e2e8f0; }
        .warning{ color:#b45309; background:#ffedd5; border-color:#fed7aa; }

        .file-list{ display:flex; flex-direction:column; gap:8px; margin-top:10px; }

        .file-item{
            display:flex;
            align-items:center;
            gap:10px;
            padding:8px 10px;
            background:#f8fafc;
            border-radius:11px;
            border:1px solid #eef2f7;
        }

        .file-icon{
            width:32px;
            height:32px;
            background:var(--blue);
            border-radius:9px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:white;
            font-size:15px;
            flex:0 0 auto;
        }

        .file-icon.pdf{ background:#dc2626; }
        .file-icon.image{ background:#8b5cf6; }
        .file-icon.dwg{ background:#f59e0b; }

        .file-details{ flex:1; min-width:0; }
        .file-name{ font-weight:900; color:#111827; font-size:12px; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .file-meta{ font-size:10px; color:#64748b; font-weight:700; }
        .file-actions{ display:flex; gap:6px; }

        .btn-icon{
            width:30px;
            height:30px;
            border-radius:9px;
            border:1px solid var(--border);
            background:white;
            color:#64748b;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            font-size:14px;
        }

        .btn-icon:hover{ background:var(--blue); color:white; border-color:var(--blue); }

        .timeline{ position:relative; padding-left:25px; margin-top:15px; }
        .timeline-item{ position:relative; padding-bottom:18px; }
        .timeline-item:last-child{ padding-bottom:0; }
        .timeline-item::before{
            content:'';
            position:absolute;
            left:-25px;
            top:0;
            width:2px;
            height:100%;
            background:#e5e7eb;
        }
        .timeline-item:last-child::before{ height:0; }
        .timeline-dot{
            position:absolute;
            left:-31px;
            top:0;
            width:12px;
            height:12px;
            border-radius:50%;
            background:#2f80ed;
            border:2px solid #fff;
            box-shadow:0 2px 4px rgba(0,0,0,.1);
        }
        .timeline-dot.completed{ background:#10b981; }
        .timeline-dot.pending{ background:#f59e0b; }
        .timeline-dot.cancelled{ background:#ef4444; }
        .timeline-content{
            background:#f8fafc;
            border-radius:12px;
            padding:10px 12px;
            border:1px solid #eef2f7;
        }
        .timeline-title{ font-weight:900; color:#111827; font-size:12px; margin-bottom:2px; }
        .timeline-date{ font-size:10.5px; color:#64748b; font-weight:700; }

        .final-quote-card{
            background:linear-gradient(135deg,#f0fdf4 0%,#e6f9e6 100%);
            border:1px solid #86efac;
            border-radius:14px;
            padding:14px;
            margin-bottom:14px;
            box-shadow:var(--shadow);
        }

        .final-quote-card .dealer-name{
            font-size:14px;
            font-weight:950;
            color:#15803d;
            margin-bottom:6px;
        }

        .proj-title{ font-weight:950; font-size:12px; color:#111827; margin-bottom:2px; line-height:1.25; }
        .proj-sub{ font-size:10.5px; color:#64748b; font-weight:700; line-height:1.35; }

        .compact-table-wrap{
            width:100%;
            border:1px solid var(--border);
            border-radius:13px;
            overflow:hidden;
            background:#fff;
        }

        .compact-table{ width:100%; margin:0; table-layout:auto; }
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

        .form-label{
            font-size:11px;
            font-weight:900;
            color:#475569;
            text-transform:uppercase;
            margin-bottom:6px;
        }

        .form-select{
            min-height:38px;
            border:1px solid var(--border);
            border-radius:11px;
            font-size:12px;
            font-weight:800;
        }

        .modal-content{
            border:1px solid var(--border);
            border-radius:16px;
            box-shadow:0 24px 55px rgba(15,23,42,.18);
        }

        .modal-header{ border-bottom:1px solid #eef2f7; }
        .modal-title{ font-size:15px; font-weight:950; color:#111827; }

        .alert{
            border-radius:14px;
            border:1px solid transparent;
            box-shadow:var(--shadow);
            font-size:12px;
            font-weight:850;
            margin-bottom:14px;
        }
        .alert-success{ background:#dcfce7; border-color:#bbf7d0; color:#166534; }
        .alert-danger{ background:#fee2e2; border-color:#fecaca; color:#991b1b; }
        .alert-info{ background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }

        @media(max-width:991.98px){
            .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
            .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
            .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0)!important; }
        }

        @media(max-width:1199px){
            .compact-table thead{ display:none; }
            .compact-table,.compact-table tbody,.compact-table tr,.compact-table td{ display:block; width:100%; }
            .compact-table tbody tr{ border-bottom:1px solid var(--border); padding:10px; }
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
                flex:0 0 100px;
            }
            .compact-table tbody td:first-child{ display:block; }
            .compact-table tbody td:first-child::before{ display:none; }
        }

        @media(max-width:768px){
            .content-scroll{ padding:12px 10px!important; }
            .container-fluid.projects-wrapper{ padding-left:0!important; padding-right:0!important; }
            .page-heading{ align-items:flex-start; flex-direction:column; }
            .panel,.action-panel{ padding:12px; }
            .primary-btn,.secondary-btn,.success-btn,.warning-btn,.danger-btn{ width:100%; }
            .info-grid{ grid-template-columns:1fr; }
            .stat-card{ min-height:72px; }
            .file-item{ align-items:flex-start; }
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

                <!-- Header with Actions -->
                <div class="page-heading">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h1>Quotation Request Details</h1>
                            <?php echo getStatusBadge($request['status']); ?>
                            <span class="badge-pill neutral">
                                <i class="bi bi-person-badge"></i>
                                <?php echo e(strtoupper(str_replace('_', ' ', $currentRoleKey))); ?>
                            </span>
                        </div>
                        <p>
                            Request #<?php echo e($request['request_no']); ?> • Created on <?php echo safeDate($request['created_at']); ?>
                        </p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <?php
                        $backUrl = 'my-quotation-requests.php';
                        if ($is_request_creator) $backUrl = 'my-quotation-requests.php';
                        elseif ($is_tl_for_project || $is_project_manager_for_project || $is_admin_view) $backUrl = 'assigned-quotations.php';
                        elseif ($is_qs) $backUrl = 'qs-quotations.php';
                        ?>
                        <a href="<?php echo $backUrl; ?>" class="secondary-btn">
                            <i class="bi bi-arrow-left"></i>
                            Back
                        </a>

                        <?php if ($request['status'] === 'Draft' && $is_request_creator): ?>
                            <a href="quotation-requests.php?edit=<?php echo $request['id']; ?>" class="primary-btn">
                                <i class="bi bi-pencil"></i>
                                Edit Draft
                            </a>
                        <?php endif; ?>

                        <?php if ($is_tl_for_project && $request['status'] === 'Pending Assignment'): ?>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="tl_action" value="approve_request">
                                <button type="submit" class="success-btn" onclick="return confirm('Approve this quotation request?')">
                                    <i class="bi bi-check2-circle"></i>
                                    Approve
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($is_tl_for_project && in_array($request['status'], ['Assigned','Pending Assignment'], true)): ?>
                            <button type="button" class="warning-btn" data-bs-toggle="modal" data-bs-target="#forwardQsModal">
                                <i class="bi bi-send"></i>
                                Forward to QS
                            </button>
                        <?php endif; ?>

                        <?php if ($request['status'] === 'With QS' && $is_qs): ?>
                            <a href="qs-manage-quotation.php?id=<?php echo $request['id']; ?>" class="primary-btn">
                                <i class="bi bi-file-text"></i>
                                Manage Quotations
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (($is_project_manager_for_project || $is_admin_view) && !$is_tl_for_project): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        This is a view-only page for Manager/Admin. TL actions are hidden.
                    </div>
                <?php endif; ?>


                <!-- TL Action Strip -->
                <?php if ($is_tl_for_project): ?>
                    <div class="action-panel">
                        <div class="panel-header mb-2">
                            <div>
                                <h3 class="panel-title"><i class="bi bi-shield-check"></i> TL Action Controls</h3>
                                <div class="panel-subtitle">
                                    Approve pending requests or forward accepted requests to QS.
                                </div>
                            </div>
                            <?php echo getStatusBadge($request['status']); ?>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($request['status'] === 'Pending Assignment'): ?>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="tl_action" value="approve_request">
                                    <button type="submit" class="success-btn" onclick="return confirm('Approve this quotation request?')">
                                        <i class="bi bi-check2-circle"></i>
                                        Approve Request
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if (in_array($request['status'], ['Assigned','Pending Assignment'], true)): ?>
                                <button type="button" class="warning-btn" data-bs-toggle="modal" data-bs-target="#forwardQsModal">
                                    <i class="bi bi-send"></i>
                                    Forward to QS
                                </button>
                            <?php endif; ?>

                            <?php if (!in_array($request['status'], ['Pending Assignment','Assigned'], true)): ?>
                                <span class="badge-pill neutral">
                                    <i class="bi bi-lock"></i>
                                    No TL action available for this status
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic blue"><i class="bi bi-building"></i></div>
                            <div>
                                <div class="stat-label">Site/Project</div>
                                <div class="stat-value" style="font-size:20px;"><?php echo e($request['project_name']); ?></div>
                                <?php if (!empty($request['project_code'])): ?>
                                    <div class="proj-sub">Code: <?php echo e($request['project_code']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-tag"></i></div>
                            <div>
                                <div class="stat-label">Quotation Type</div>
                                <div class="stat-value" style="font-size:20px;"><?php echo e($request['quotation_type']); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic yellow"><i class="bi bi-calendar"></i></div>
                            <div>
                                <div class="stat-label">Required By</div>
                                <div class="stat-value" style="font-size:20px;"><?php echo safeDate($request['required_by_date']); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-flag"></i></div>
                            <div>
                                <div class="stat-label">Priority / Status</div>
                                <div class="stat-value"><?php echo getPriorityBadge($request['priority']); ?> <?php echo getStatusBadge($request['status']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Budget Card -->
                <?php if ($request['estimated_budget'] > 0): ?>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-currency-rupee"></i></div>
                            <div>
                                <div class="stat-label">Estimated Budget</div>
                                <div class="stat-value"><?php echo formatCurrency($request['estimated_budget']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Finalized Quotation Card (with download button) -->
                <?php if ($request['status'] === 'QS Finalized' || $request['status'] === 'Approved'): ?>
                <div class="final-quote-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="dealer-name"><i class="bi bi-star-fill text-warning"></i> Finalized Quotation</div>
                            <div class="fw-900 mt-2"><?php echo e($request['final_dealer_name'] ?? '—'); ?></div>
                            <div class="proj-sub">Quotation #<?php echo e($request['final_quotation_no'] ?? '—'); ?></div>
                        </div>
                        <div class="text-end">
                            <div class="fw-900 text-success fs-4"><?php echo formatCurrency($request['final_negotiated_amount'] ?? $request['final_quotation_amount']); ?></div>
                            <div class="proj-sub">Finalized on <?php echo safeDate($request['final_quotation_date']); ?></div>
                        </div>
                    </div>

                    <!-- Download/View button for the final quotation document -->
                    <?php if (!empty($request['final_quotation_document'])): ?>
                    <div class="mt-3">
                        <a href="<?php echo e($request['final_quotation_document']); ?>" class="success-btn" download>
                            <i class="bi bi-file-earmark-pdf"></i> Download Final Quotation Document
                        </a>
                        <a href="<?php echo e($request['final_quotation_document']); ?>" target="_blank" class="secondary-btn">
                            <i class="bi bi-eye"></i> View
                        </a>
                    </div>
                    <?php endif; ?>

                    <div class="row mt-2">
                        <?php if ($request['final_negotiated_amount'] && $request['final_quotation_amount'] && $request['final_negotiated_amount'] < $request['final_quotation_amount']): ?>
                            <div class="col-12"><span class="badge-pill ontrack"><i class="bi bi-piggy-bank"></i> Saved <?php echo formatCurrency($request['final_quotation_amount'] - $request['final_negotiated_amount']); ?></span></div>
                        <?php endif; ?>
                        <?php if ($request['final_delivery_terms']): ?>
                            <div class="col-md-4 mt-2"><small class="text-muted">Delivery:</small><br><strong><?php echo e($request['final_delivery_terms']); ?></strong></div>
                        <?php endif; ?>
                        <?php if ($request['final_payment_terms']): ?>
                            <div class="col-md-4 mt-2"><small class="text-muted">Payment:</small><br><strong><?php echo e($request['final_payment_terms']); ?></strong></div>
                        <?php endif; ?>
                        <?php if ($request['final_warranty']): ?>
                            <div class="col-md-4 mt-2"><small class="text-muted">Warranty:</small><br><strong><?php echo e($request['final_warranty']); ?></strong></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($request['final_qs_remarks']): ?>
                        <div class="mt-2 p-2 bg-light rounded"><small class="text-muted"><i class="bi bi-chat-dots"></i> QS Remarks:</small><br><?php echo e($request['final_qs_remarks']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Main Request Details Panel -->
                <div class="panel">
                    <div class="panel-header"><div><h3 class="panel-title"><i class="bi bi-file-earmark-text"></i> Request Details</h3><div class="panel-subtitle">Main request, project and team information</div></div><button class="panel-menu"><i class="bi bi-three-dots"></i></button></div>
                    <div class="mb-4"><h4 class="section-heading"><i class="bi bi-card-text"></i>Description</h4><div class="description-box"><?php echo nl2br(e($request['description'])); ?></div></div>
                    <?php if (!empty($request['specifications'])): ?>
                        <div class="mb-4"><h4 class="section-heading"><i class="bi bi-list-check"></i>Specifications</h4><div class="description-box"><?php echo nl2br(e($request['specifications'])); ?></div></div>
                    <?php endif; ?>

                    <h4 class="section-heading"><i class="bi bi-building"></i>Project Information</h4>
                    <div class="info-grid mb-4">
                        <div class="info-item"><div class="info-label">Client Name</div><div class="info-value"><?php echo e($request['client_name'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Company</div><div class="info-value"><?php echo e($request['company_name'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Location</div><div class="info-value"><?php echo e($request['project_location'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Project Type</div><div class="info-value"><?php echo e($request['project_type'] ?? '—'); ?></div></div>
                        <?php if (!empty($request['scope_of_work'])): ?>
                            <div class="info-item" style="grid-column: span 2;"><div class="info-label">Scope of Work</div><div class="info-value"><?php echo e($request['scope_of_work']); ?></div></div>
                        <?php endif; ?>
                    </div>

                    <h4 class="section-heading"><i class="bi bi-people"></i>Team</h4>
                    <div class="info-grid mb-4">
                        <div class="info-item"><div class="info-label">Manager</div><div class="info-value"><?php echo e($request['manager_name'] ?? '—'); ?><?php if (!empty($request['manager_code'])): ?><div class="proj-sub">Code: <?php echo e($request['manager_code']); ?></div><?php endif; ?></div></div>
                        <div class="info-item"><div class="info-label">Team Lead</div><div class="info-value"><?php echo e($request['team_lead_name'] ?? '—'); ?><?php if (!empty($request['team_lead_code'])): ?><div class="proj-sub">Code: <?php echo e($request['team_lead_code']); ?></div><?php endif; ?></div></div>
                        <div class="info-item" style="grid-column: span 2;"><div class="info-label">Project Engineers</div><div class="info-value"><?php echo e($request['project_engineers'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Assigned TL</div><div class="info-value"><?php echo e($request['project_engineer_name'] ?? '—'); ?></div></div>
                        <div class="info-item"><div class="info-label">Assigned QS</div><div class="info-value"><?php echo e($request['qs_employee_name'] ?? '—'); ?></div></div>
                    </div>

                    <?php if (!empty($request['client_mobile']) || !empty($request['client_email']) || !empty($request['client_state'])): ?>
                    <h4 class="section-heading"><i class="bi bi-person-lines-fill"></i>Client Contact</h4>
                    <div class="info-grid">
                        <?php if (!empty($request['client_mobile'])): ?>
                            <div class="info-item"><div class="info-label">Phone</div><div class="info-value"><?php echo e($request['client_mobile']); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($request['client_email'])): ?>
                            <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo e($request['client_email']); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($request['client_state'])): ?>
                            <div class="info-item"><div class="info-label">State</div><div class="info-value"><?php echo e($request['client_state']); ?></div></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Drawings & Attachments -->
                <?php if (!empty($request['drawing_number']) || !empty($request['drawing_file']) || !empty($attachments)): ?>
                <div class="panel">
                    <div class="panel-header"><div><h3 class="panel-title"><i class="bi bi-paperclip"></i> Drawings & Attachments</h3><div class="panel-subtitle">Uploaded drawings and supporting documents</div></div><button class="panel-menu"><i class="bi bi-three-dots"></i></button></div>
                    <?php if (!empty($request['drawing_number']) || !empty($request['drawing_file'])): ?>
                    <div class="mb-3">
                        <h4 class="section-heading"><i class="bi bi-file-earmark"></i>Drawing</h4>
                        <?php if (!empty($request['drawing_number'])): ?>
                            <div class="info-item mb-2"><div class="info-label">Drawing Number</div><div class="info-value"><?php echo e($request['drawing_number']); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($request['drawing_file'])): ?>
                        <div class="file-item">
                            <div class="file-icon pdf"><i class="bi bi-file-earmark-pdf"></i></div>
                            <div class="file-details">
                                <div class="file-name">Drawing File</div>
                                <div class="file-meta"><?php $filename = basename($request['drawing_file']); echo e(strlen($filename) > 30 ? substr($filename,0,27).'...' : $filename); ?></div>
                            </div>
                            <div class="file-actions">
                                <a href="<?php echo e($request['drawing_file']); ?>" target="_blank" class="btn-icon"><i class="bi bi-eye"></i></a>
                                <a href="<?php echo e($request['drawing_file']); ?>" download class="btn-icon"><i class="bi bi-download"></i></a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($attachments)): ?>
                    <div><h4 class="section-heading"><i class="bi bi-files"></i>Additional Documents</h4><div class="file-list">
                        <?php if (isset($attachments['drawing'])): ?>
                        <div class="file-item">
                            <div class="file-icon pdf"><i class="bi bi-file-earmark"></i></div>
                            <div class="file-details"><div class="file-name">Drawing</div><div class="file-meta">Uploaded <?php echo safeDate($attachments['drawing']['uploaded_at']); ?></div></div>
                            <div class="file-actions"><a href="<?php echo e($attachments['drawing']['file_path']); ?>" target="_blank" class="btn-icon"><i class="bi bi-eye"></i></a><a href="<?php echo e($attachments['drawing']['file_path']); ?>" download class="btn-icon"><i class="bi bi-download"></i></a></div>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($attachments['additional'])): foreach ($attachments['additional'] as $file): ?>
                        <div class="file-item">
                            <div class="file-icon"><i class="bi bi-file-earmark"></i></div>
                            <div class="file-details"><div class="file-name"><?php echo e(strlen($file['original_name']) > 30 ? substr($file['original_name'],0,27).'...' : $file['original_name']); ?></div><div class="file-meta">Uploaded <?php echo safeDate($file['uploaded_at']); ?></div></div>
                            <div class="file-actions"><a href="<?php echo e($file['file_path']); ?>" target="_blank" class="btn-icon"><i class="bi bi-eye"></i></a><a href="<?php echo e($file['file_path']); ?>" download class="btn-icon"><i class="bi bi-download"></i></a></div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Quotations Comparison Table -->
                <?php if (count($quotations) > 0): ?>
                <div class="panel">
                    <div class="panel-header"><div><h3 class="panel-title"><i class="bi bi-table"></i> All Quotations Received</h3><div class="panel-subtitle">Dealer quotations comparison</div></div><button class="panel-menu"><i class="bi bi-three-dots"></i></button></div>
                    <div class="compact-table-wrap">
                        <table class="table compact-table align-middle mb-0">
                            <thead>
                                <tr><th>Dealer</th><th>Amount</th><th>Delivery</th><th>Payment</th><th>Warranty</th><th>Status</th> </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($quotations as $q): ?>
                                <tr class="<?php echo ($request['final_quotation_id'] == $q['id']) ? 'table-success' : ''; ?>">
                                    <td data-label="Dealer"><?php echo e($q['dealer_name'] ?? '—'); ?></td>
                                    <td data-label="Amount" class="fw-700"><?php echo formatCurrency($q['total_amount']); ?></td>
                                    <td data-label="Delivery"><?php echo e($q['delivery_terms'] ?? '—'); ?></td>
                                    <td data-label="Payment"><?php echo e($q['payment_terms'] ?? '—'); ?></td>
                                    <td data-label="Warranty"><?php echo e($q['warranty'] ?? '—'); ?></td>
                                    <td data-label="Status"><?php echo ($request['final_quotation_id'] == $q['id']) ? '<span class="badge-pill ontrack">Finalized</span>' : '<span class="badge-pill neutral">' . e($q['status']) . '</span>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Timeline/Workflow Status -->
                <div class="panel">
                    <div class="panel-header"><div><h3 class="panel-title"><i class="bi bi-clock-history"></i> Workflow Timeline</h3><div class="panel-subtitle">Request progress and current status</div></div><button class="panel-menu"><i class="bi bi-three-dots"></i></button></div>
                    <div class="timeline">
                        <div class="timeline-item"><div class="timeline-dot completed"></div><div class="timeline-content"><div class="timeline-title">Request Created</div><div class="timeline-date"><?php echo safeDate($request['created_at']); ?> by <?php echo e($request['requested_by_name']); ?></div></div></div>
                        <?php if ($request['assigned_at']): ?>
                        <div class="timeline-item"><div class="timeline-dot completed"></div><div class="timeline-content"><div class="timeline-title">Assigned to Project Engineer</div><div class="timeline-date"><?php echo safeDate($request['assigned_at']); ?> to <?php echo e($request['project_engineer_name']); ?></div></div></div>
                        <?php endif; ?>
                        <?php if ($request['qs_assigned_at'] && $request['status'] !== 'Assigned'): ?>
                        <div class="timeline-item"><div class="timeline-dot completed"></div><div class="timeline-content"><div class="timeline-title">Forwarded to QS</div><div class="timeline-date"><?php echo safeDate($request['qs_assigned_at']); ?> to <?php echo e($request['qs_employee_name']); ?></div></div></div>
                        <?php endif; ?>
                        <?php if ($request['final_quotation_date']): ?>
                        <div class="timeline-item"><div class="timeline-dot completed"></div><div class="timeline-content"><div class="timeline-title">QS Finalized</div><div class="timeline-date"><?php echo safeDate($request['final_quotation_date']); ?> – Dealer: <?php echo e($request['final_dealer_name']); ?></div></div></div>
                        <?php endif; ?>
                        <?php
                        $status_dot = 'pending';
                        if (in_array($request['status'], ['Approved','QS Finalized'])) $status_dot = 'completed';
                        elseif (in_array($request['status'], ['Rejected','Cancelled'])) $status_dot = 'cancelled';
                        ?>
                        <div class="timeline-item"><div class="timeline-dot <?php echo $status_dot; ?>"></div><div class="timeline-content"><div class="timeline-title">Current Status: <?php echo e($request['status']); ?></div><div class="timeline-date">Last updated: <?php echo safeDate($request['updated_at']); ?></div>
                        <?php if ($request['status'] === 'Rejected' && !empty($request['rejection_reason'])): ?>
                            <div class="mt-2 p-2 bg-danger bg-opacity-10 rounded"><strong class="text-danger">Rejection Reason:</strong><br><?php echo e($request['rejection_reason']); ?></div>
                        <?php endif; ?>
                        <?php if ($request['status'] === 'Cancelled' && !empty($request['cancellation_reason'])): ?>
                            <div class="mt-2 p-2 bg-secondary bg-opacity-10 rounded"><strong>Cancellation Reason:</strong><br><?php echo e($request['cancellation_reason']); ?></div>
                        <?php endif; ?>
                        </div></div>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>


<?php if ($is_tl_for_project): ?>
<div class="modal fade" id="forwardQsModal" tabindex="-1" aria-labelledby="forwardQsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="forwardQsModalLabel">Forward to QS</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="tl_action" value="forward_to_qs">

                    <div class="mb-3">
                        <label for="qs_employee_id" class="form-label">Select QS Employee</label>
                        <select name="qs_employee_id" id="qs_employee_id" class="form-select" required>
                            <option value="">-- Select QS --</option>
                            <?php foreach ($qs_employees as $qs): ?>
                                <option value="<?php echo (int)$qs['id']; ?>"><?php echo e($qs['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (empty($qs_employees)): ?>
                            <div class="text-warning small mt-2">No active QS employees found.</div>
                        <?php endif; ?>
                    </div>

                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Selected QS will receive a notification and this request status will become With QS.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="secondary-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="warning-btn" <?php echo empty($qs_employees) ? 'disabled' : ''; ?>>
                        <i class="bi bi-send"></i>
                        Forward
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const yearElement = document.getElementById("year");
        if (yearElement) {
            yearElement.textContent = new Date().getFullYear();
        }
    });
</script>
</body>
</html>
<?php
if (isset($conn) && $conn) { @mysqli_close($conn); }
?>