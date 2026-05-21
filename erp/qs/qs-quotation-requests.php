<?php
// qs-quotations.php – Dashboard for QS: view requests assigned to the logged-in QS

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$success = '';
$error   = '';
$requests = [];

// ---------- Auth (QS only) ----------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$empId = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$department = strtolower(trim((string)($_SESSION['department'] ?? '')));

// Only allow users from QS department or designation containing 'QS'
$is_qs = ($department === 'qs' || strpos($designation, 'qs') !== false);
if (!$is_qs) {
    header("Location: index.php");
    exit;
}

// ---------- Handle Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $request_id = intval($_POST['request_id'] ?? 0);
    
    if ($action === 'finalize') {
        $quotation_id = intval($_POST['quotation_id'] ?? 0);
        if ($request_id <= 0 || $quotation_id <= 0) {
            $error = "Invalid request or quotation selection.";
        } else {
            // Verify the request belongs to this QS and is in 'With QS' status
            $check_query = "SELECT id FROM quotation_requests WHERE id = ? AND qs_employee_id = ? AND status = 'With QS'";
            $stmt_check = mysqli_prepare($conn, $check_query);
            if ($stmt_check) {
                mysqli_stmt_bind_param($stmt_check, "ii", $request_id, $empId);
                mysqli_stmt_execute($stmt_check);
                $check_res = mysqli_stmt_get_result($stmt_check);
                if (mysqli_num_rows($check_res) === 0) {
                    $error = "Request not found or not assigned to you.";
                } else {
                    // Update request: set status to 'QS Finalized' and store final quotation ID
                    $update_query = "UPDATE quotation_requests SET status = 'QS Finalized', final_quotation_id = ?, updated_at = NOW() WHERE id = ?";
                    $stmt_update = mysqli_prepare($conn, $update_query);
                    if ($stmt_update) {
                        mysqli_stmt_bind_param($stmt_update, "ii", $quotation_id, $request_id);
                        if (mysqli_stmt_execute($stmt_update)) {
                            $success = "Quotation finalized successfully. Request moved to QS Finalized status.";
                        } else {
                            $error = "Failed to finalize quotation.";
                        }
                        mysqli_stmt_close($stmt_update);
                    } else {
                        $error = "Database error preparing update.";
                    }
                }
                mysqli_stmt_close($stmt_check);
            } else {
                $error = "Database error preparing check.";
            }
        }
    } elseif ($action === 'reopen') {
        $check_query = "SELECT id FROM quotation_requests WHERE id = ? AND qs_employee_id = ? AND status = 'QS Finalized'";
        $stmt_check = mysqli_prepare($conn, $check_query);
        if ($stmt_check) {
            mysqli_stmt_bind_param($stmt_check, "ii", $request_id, $empId);
            mysqli_stmt_execute($stmt_check);
            $check_res = mysqli_stmt_get_result($stmt_check);
            if (mysqli_num_rows($check_res) === 0) {
                $error = "Request not found or not in QS Finalized status.";
            } else {
                $update_query = "UPDATE quotation_requests SET status = 'With QS', final_quotation_id = NULL, updated_at = NOW() WHERE id = ?";
                $stmt_update = mysqli_prepare($conn, $update_query);
                if ($stmt_update) {
                    mysqli_stmt_bind_param($stmt_update, "i", $request_id);
                    if (mysqli_stmt_execute($stmt_update)) {
                        $success = "Request reopened. You can now modify quotations.";
                    } else {
                        $error = "Failed to reopen request.";
                    }
                    mysqli_stmt_close($stmt_update);
                } else {
                    $error = "Database error preparing update.";
                }
            }
            mysqli_stmt_close($stmt_check);
        } else {
            $error = "Database error preparing check.";
        }
    }
}
// ---------- Fetch requests assigned to this QS ----------
// Show: With QS (active), and optionally QS Finalized (for history)
// We'll show both, but with a filter or separate sections.

$sql = "
    SELECT 
        qr.*,
        s.project_name,
        s.project_code,
        c.client_name,
        m.full_name AS manager_name,
        tl.full_name AS team_lead_name,
        DATEDIFF(qr.required_by_date, CURDATE()) AS days_remaining,
        (SELECT COUNT(*) FROM quotations WHERE quotation_request_id = qr.id) AS quotation_count,
        (SELECT COUNT(*) FROM quotations WHERE quotation_request_id = qr.id AND status = 'Finalized') AS has_finalized
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN employees m ON s.manager_employee_id = m.id
    LEFT JOIN employees tl ON s.team_lead_employee_id = tl.id
    WHERE qr.qs_employee_id = ?
      AND qr.status IN ('With QS', 'QS Finalized')
    ORDER BY 
        CASE 
            WHEN qr.status = 'With QS' THEN 1
            ELSE 2
        END,
        qr.required_by_date ASC,
        qr.created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $empId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$requests = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Stats
$active_count = 0;
$finalized_count = 0;
$overdue_count = 0;
foreach ($requests as $req) {
    if ($req['status'] === 'With QS') $active_count++;
    else $finalized_count++;
    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
        if (strtotime($req['required_by_date']) < time()) $overdue_count++;
    }
}


// ---------- UI filters ----------
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
            (string)($req['client_name'] ?? '') . ' ' .
            (string)($req['manager_name'] ?? '') . ' ' .
            (string)($req['team_lead_name'] ?? '')
        );

        if (!str_contains($haystack, strtolower($filter_search))) {
            return false;
        }
    }

    return true;
}));

// Helper functions
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}
function getPriorityBadge($priority) {
    $priority = trim((string)$priority);
    $map = [
        'Low' => ['neutral', 'bi-arrow-down'],
        'Medium' => ['progressing', 'bi-dash'],
        'High' => ['warning', 'bi-arrow-up'],
        'Urgent' => ['atrisk', 'bi-exclamation-triangle']
    ];
    $item = $map[$priority] ?? ['neutral', 'bi-question'];
    return '<span class="badge-pill ' . $item[0] . '"><i class="bi ' . $item[1] . '"></i>' . e($priority ?: '—') . '</span>';
}
function getStatusBadge($status) {
    $status = trim((string)$status);
    $map = [
        'With QS' => ['pending', 'bi-arrow-right'],
        'QS Finalized' => ['ontrack', 'bi-check-circle'],
        'Approved' => ['ontrack', 'bi-check-circle-fill'],
        'Rejected' => ['atrisk', 'bi-x-circle'],
        'Cancelled' => ['neutral', 'bi-x']
    ];
    $item = $map[$status] ?? ['neutral', 'bi-question'];
    return '<span class="badge-pill ' . $item[0] . '"><i class="bi ' . $item[1] . '"></i>' . e($status ?: '—') . '</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>QS Quotations - TEK-C</title>

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

        body{background:var(--page-bg);}
        .content-scroll{flex:1 1 auto;overflow:auto;padding:16px;}
        .projects-wrapper{width:100%;}

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
        }

        .page-heading p{
            margin:3px 0 0;
            color:var(--muted);
            font-size:12px;
            font-weight:650;
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
            line-height:1;
        }

        .primary-btn{
            border:0;
            background:#111827;
            color:#fff;
        }

        .primary-btn:hover{background:#020617;color:#fff;}

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
            font-weight:950;
            font-size:14px;
            color:var(--text);
            margin:0;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .panel-title i{color:var(--blue);font-size:16px;}

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

        .stat-ic.blue{background:var(--blue);}
        .stat-ic.green{background:var(--green);}
        .stat-ic.yellow{background:var(--orange);}
        .stat-ic.red{background:var(--red);}
        .stat-ic.purple{background:var(--purple);}

        .stat-label{
            color:#64748b;
            font-weight:850;
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

        .ontrack{color:#15803d;background:#dcfce7;border-color:#bbf7d0;}
        .progressing{color:#2563eb;background:#dbeafe;border-color:#bfdbfe;}
        .pending{color:#6d28d9;background:#ede9fe;border-color:#ddd6fe;}
        .atrisk{color:#b91c1c;background:#fee2e2;border-color:#fecaca;}
        .neutral{color:#475569;background:#f1f5f9;border-color:#e2e8f0;}
        .warning{color:#b45309;background:#ffedd5;border-color:#fed7aa;}

        .btn-action{
            min-height:32px;
            padding:0 10px;
            border-radius:10px;
        }

        .btn-action.manage:hover{
            color:#2563eb;
            border-color:#bfdbfe;
            background:#eff6ff;
        }

        .btn-action.reopen:hover{
            color:#b45309;
            border-color:#fed7aa;
            background:#ffedd5;
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

        .compact-table{width:100%;margin:0;table-layout:auto;}

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

        .compact-table tbody tr:hover{background:#fbfdff;}

        .table-primary-text{
            color:#111827;
            font-size:11.5px;
            font-weight:950;
        }

        .actions-col{width:190px;}

        .request-card{
            border:1px solid var(--border);
            border-radius:14px;
            background:#fff;
            box-shadow:var(--shadow);
            padding:12px;
        }

        .request-card.urgent{border-left:4px solid #dc2626;}
        .request-card.high{border-left:4px solid #f59e0b;}
        .request-card.overdue{background:#fffafa;}

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

        .request-kv{margin-top:10px;display:grid;gap:7px;}
        .request-row{display:flex;gap:10px;align-items:flex-start;}
        .request-key{flex:0 0 85px;color:#64748b;font-weight:900;font-size:10.5px;text-transform:uppercase;}
        .request-val{flex:1 1 auto;font-weight:900;color:#111827;font-size:11.5px;line-height:1.3;word-break:break-word;}
        .request-actions{margin-top:12px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;}

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

        .alert{
            border-radius:14px;
            border:1px solid transparent;
            box-shadow:var(--shadow);
            font-size:12px;
            font-weight:850;
            margin-bottom:14px;
        }

        .alert-success{background:#dcfce7;border-color:#bbf7d0;color:#166534;}
        .alert-danger{background:#fee2e2;border-color:#fecaca;color:#991b1b;}

        @media(max-width:991.98px){
            .main{margin-left:0!important;width:100%!important;max-width:100%!important;}
            .sidebar{position:fixed!important;transform:translateX(-100%);z-index:1040!important;}
            .sidebar.open,.sidebar.active,.sidebar.show{transform:translateX(0)!important;}
        }

        @media(max-width:1199px){
            .compact-table thead{display:none;}
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
            .compact-table tbody td:first-child{display:block;}
            .compact-table tbody td:first-child::before{display:none;}
            .actions-col{width:auto!important;}
        }

        @media(max-width:768px){
            .content-scroll{padding:12px 10px!important;}
            .container-fluid.projects-wrapper{padding-left:0!important;padding-right:0!important;}
            .page-heading{align-items:flex-start;flex-direction:column;}
            .panel,.filter-card{padding:12px;}
            .primary-btn,.secondary-btn{width:100%;}
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

                <div class="page-heading">
                    <div>
                        <h1>QS Quotations</h1>
                        <p>Manage quotation requests assigned to you and finalize dealer quotations.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge-pill pending">
                            <i class="bi bi-person-badge"></i>
                            QS Workspace
                        </span>
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
                                <div class="stat-label">Active Requests</div>
                                <div class="stat-value"><?php echo $active_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic green"><i class="bi bi-check-circle"></i></div>
                            <div>
                                <div class="stat-label">Finalized</div>
                                <div class="stat-value"><?php echo $finalized_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic red"><i class="bi bi-exclamation-triangle"></i></div>
                            <div>
                                <div class="stat-label">Overdue</div>
                                <div class="stat-value"><?php echo $overdue_count; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-ic purple"><i class="bi bi-people"></i></div>
                            <div>
                                <div class="stat-label">Total Assigned</div>
                                <div class="stat-value"><?php echo count($requests); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-card">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Request, project, client..." value="<?php echo e($filter_search); ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <?php foreach (['With QS','QS Finalized'] as $st): ?>
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
                            <a href="qs-quotations.php" class="secondary-btn">
                                <i class="bi bi-arrow-counterclockwise"></i>
                                Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Directory -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">
                                <i class="bi bi-file-earmark-text"></i>
                                Quotation Requests
                            </h3>
                            <div class="panel-subtitle">
                                Showing <?php echo count($filtered_requests); ?> of <?php echo count($requests); ?> assigned request(s)
                            </div>
                        </div>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <!-- Mobile Cards -->
                    <div class="d-block d-md-none">
                        <div class="d-grid gap-3">
                            <?php if (empty($filtered_requests)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    No quotation requests assigned.
                                    <div class="proj-sub mt-1">When TL forwards requests, they will appear here.</div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($filtered_requests as $req): 
                                    $isOverdue = false;
                                    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
                                        $required = strtotime($req['required_by_date']);
                                        if ($required < time()) $isOverdue = true;
                                    }
                                    $cardClass = ($req['priority'] === 'Urgent') ? 'urgent' : (($req['priority'] === 'High') ? 'high' : '');
                                    if ($isOverdue) $cardClass .= ' overdue';
                                ?>
                                    <div class="request-card <?php echo $cardClass; ?>">
                                        <div class="top">
                                            <div style="flex:1 1 auto;">
                                                <div class="d-flex align-items-center justify-content-between gap-2">
                                                    <h4 class="title"><?php echo e($req['title']); ?></h4>
                                                    <?php echo getPriorityBadge($req['priority'] ?? 'Medium'); ?>
                                                </div>
                                                <div class="meta">
                                                    <span><i class="bi bi-building"></i> <?php echo e($req['project_name']); ?></span>
                                                    <span><i class="bi bi-tag"></i> <?php echo e($req['quotation_type']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="request-kv">
                                            <div class="request-row"><div class="request-key">Request No.</div><div class="request-val"><?php echo e($req['request_no']); ?></div></div>
                                            <div class="request-row"><div class="request-key">Required By</div><div class="request-val"><?php echo safeDate($req['required_by_date']); ?> <?php if ($isOverdue): ?><span class="days-badge overdue">Overdue</span><?php endif; ?></div></div>
                                            <div class="request-row"><div class="request-key">Quotations</div><div class="request-val"><?php echo (int)$req['quotation_count']; ?> received</div></div>
                                            <div class="request-row"><div class="request-key">Status</div><div class="request-val"><?php echo getStatusBadge($req['status']); ?></div></div>
                                        </div>
                                        <div class="request-actions">
                                            <a href="qs-manage-quotation.php?id=<?php echo $req['id']; ?>" class="btn-action manage" title="Manage"><i class="bi bi-pencil-square"></i> Manage</a>
                                            <?php if ($req['status'] === 'QS Finalized'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Reopen this request to make changes?');">
                                                    <input type="hidden" name="action" value="reopen">
                                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                    <button type="submit" class="btn-action reopen"><i class="bi bi-arrow-return-left"></i> Reopen</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Desktop Table -->
                    <div class="compact-table-wrap d-none d-md-block">
                            <table id="qsQuotationsTable" class="table compact-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Request No.</th>
                                        <th>Title / Site</th>
                                        <th>Type</th>
                                        <th>Required By</th>
                                        <th>Quotations</th>
                                        <th>Status</th>
                                        <th>Priority</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($filtered_requests as $req): 
                                    $isOverdue = false;
                                    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
                                        if (strtotime($req['required_by_date']) < time()) $isOverdue = true;
                                    }
                                ?>
                                    <tr>
                                        <td data-label="Request No."><span class="table-primary-text"><?php echo e($req['request_no']); ?></span></td>
                                        <td data-label="Title / Site">
                                            <div class="proj-title"><?php echo e($req['title']); ?></div>
                                            <div class="proj-sub"><i class="bi bi-building"></i> <?php echo e($req['project_name']); ?><?php if (!empty($req['project_code'])): ?> (<?php echo e($req['project_code']); ?>)<?php endif; ?></div>
                                        </td>
                                        <td data-label="Type"><?php echo e($req['quotation_type']); ?></td>
                                        <td data-label="Required By">
                                            <div class="table-primary-text <?php echo $isOverdue ? 'text-danger' : ''; ?>"><?php echo safeDate($req['required_by_date']); ?></div>
                                            <?php if ($isOverdue): ?><div class="proj-sub text-danger">Overdue</div><?php endif; ?>
                                        </td>
                                        <td data-label="Quotations"><span class="badge-pill neutral"><?php echo (int)$req['quotation_count']; ?></span></td>
                                        <td data-label="Status"><?php echo getStatusBadge($req['status']); ?></td>
                                        <td data-label="Priority"><?php echo getPriorityBadge($req['priority']); ?></td>
                                        <td data-label="Actions" class="text-end actions-col">
                                            <a href="qs-manage-quotation.php?id=<?php echo $req['id']; ?>" class="btn-action manage" title="Manage"><i class="bi bi-pencil-square"></i> Manage</a>
                                            <?php if ($req['status'] === 'QS Finalized'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Reopen this request to make changes?');">
                                                    <input type="hidden" name="action" value="reopen">
                                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                    <button type="submit" class="btn-action reopen"><i class="bi bi-arrow-return-left"></i> Reopen</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($filtered_requests)): ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                No quotation requests assigned.
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                </div>

                <!-- Quick Guide -->
                <div class="panel">
                    <div class="panel-header"><h3 class="panel-title">QS Workflow Guide</h3><button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button></div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="d-flex gap-3">
                                <div class="stat-ic blue" style="width: 40px; height: 40px; font-size: 16px;"><i class="bi bi-file-text"></i></div>
                                <div>
                                    <h6 class="fw-900 mb-1">Review Request</h6>
                                    <p class="small text-muted mb-0">Check description, specifications, and drawings.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-3">
                                <div class="stat-ic purple" style="width: 40px; height: 40px; font-size: 16px;"><i class="bi bi-plus-circle"></i></div>
                                <div>
                                    <h6 class="fw-900 mb-1">Add Quotations</h6>
                                    <p class="small text-muted mb-0">Enter quotations from dealers/vendors with amounts.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-3">
                                <div class="stat-ic green" style="width: 40px; height: 40px; font-size: 16px;"><i class="bi bi-check2-circle"></i></div>
                                <div>
                                    <h6 class="fw-900 mb-1">Finalize</h6>
                                    <p class="small text-muted mb-0">Select the best quotation and finalize. Request moves to manager for approval.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

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
if (isset($conn)) { mysqli_close($conn); }
?>