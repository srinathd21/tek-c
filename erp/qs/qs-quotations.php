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

// Helper functions (same as before)
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}
function getPriorityBadge($priority) {
    $badges = [
        'Low' => ['bg-secondary', 'bi-arrow-down'],
        'Medium' => ['bg-info', 'bi-dash'],
        'High' => ['bg-warning', 'bi-arrow-up'],
        'Urgent' => ['bg-danger', 'bi-exclamation-triangle']
    ];
    $badge = $badges[$priority] ?? ['bg-secondary', 'bi-question'];
    return '<span class="badge ' . $badge[0] . '"><i class="bi ' . $badge[1] . ' me-1"></i>' . $priority . '</span>';
}
function getStatusBadge($status) {
    $badges = [
        'Pending Assignment' => ['bg-warning', 'bi-clock'],
        'Assigned' => ['bg-info', 'bi-person-check'],
        'Quotations Received' => ['bg-primary', 'bi-file-text'],
        'With QS' => ['bg-secondary', 'bi-arrow-right'],
        'QS Finalized' => ['bg-success', 'bi-check-circle'],
        'Approved' => ['bg-success', 'bi-check-circle-fill'],
        'Rejected' => ['bg-danger', 'bi-x-circle'],
        'Cancelled' => ['bg-dark', 'bi-x']
    ];
    $badge = $badges[$status] ?? ['bg-secondary', 'bi-question'];
    return '<span class="badge ' . $badge[0] . '"><i class="bi ' . $badge[1] . ' me-1"></i>' . $status . '</span>';
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

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        /* same styles as previous pages (keep everything) */
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

        .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
        .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
        .stat-ic.blue{ background: var(--blue); }
        .stat-ic.green{ background: #10b981; }
        .stat-ic.yellow{ background: #f59e0b; }
        .stat-ic.red{ background: #ef4444; }
        .stat-ic.purple{ background: #8b5cf6; }
        .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
        .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

        .table-responsive { overflow-x: hidden !important; }
        table.dataTable { width:100% !important; }
        .table thead th{ font-size: 11px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; padding: 10px 10px !important; white-space: normal !important; }
        .table td{ vertical-align: middle; border-color: var(--border); font-weight:650; color:#374151; padding: 10px 10px !important; white-space: normal !important; word-break: break-word; }

        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 7px 10px;
            color: var(--muted);
            font-size: 12px;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            font-weight: 900;
        }
        .btn-action:hover { background: var(--bg); color: var(--blue); }
        .btn-action.manage{ border-color: rgba(45,156,219,.25); }
        .btn-action.finalize{ border-color: rgba(16,185,129,.25); }
        .btn-action.reopen{ border-color: rgba(245,158,11,.25); }

        .proj-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
        .proj-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.25; }

        .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

        /* Mobile cards */
        .request-card{
            border:1px solid var(--border);
            border-radius: 16px;
            background: var(--surface);
            box-shadow: var(--shadow);
            padding: 12px;
            position: relative;
        }
        .request-card.urgent{ border-left: 4px solid #dc2626; }
        .request-card.high{ border-left: 4px solid #f59e0b; }
        .request-card.overdue{ background: rgba(239,68,68,.02); }
        .request-card .top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
        .request-card .title{ font-weight:1000; color:#111827; font-size: 14px; line-height:1.2; margin:0; }
        .request-card .meta{ margin-top:6px; display:flex; flex-wrap:wrap; gap:8px 10px; color:#6b7280; font-weight:800; font-size:12px; }
        .request-kv{ margin-top:10px; display:grid; gap:8px; }
        .request-row{ display:flex; gap:10px; align-items:flex-start; }
        .request-key{ flex:0 0 85px; color:#6b7280; font-weight:1000; font-size:12px; }
        .request-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:12.5px; line-height:1.3; word-break: break-word; }
        .request-actions{ margin-top:12px; display:flex; gap:8px; justify-content:flex-end; flex-wrap: wrap; }
        .days-badge{
            background: #e8f0fe;
            color: var(--blue);
            font-weight: 900;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }
        .days-badge.overdue{
            background: rgba(239,68,68,.12);
            color: #ef4444;
        }

        @media (max-width: 991.98px){
            .main{ margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
            .sidebar{ position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
            .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
        }
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
            .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
            .request-actions { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid maxw">

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

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">QS Quotations</h1>
                        <p class="text-muted mb-0">Manage quotation requests assigned to you</p>
                    </div>
                    <div>
                        <a href="dealers-directory.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-shop"></i> Dealers
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

                <!-- Directory -->
                <div class="panel mb-4">
                    <div class="panel-header">
                        <h3 class="panel-title">Quotation Requests</h3>
                        <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    </div>

                    <!-- Mobile Cards -->
                    <div class="d-block d-md-none">
                        <div class="d-grid gap-3">
                            <?php if (empty($requests)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox" style="font-size: 48px;"></i>
                                    <p class="mt-2 fw-bold">No quotation requests assigned</p>
                                    <p class="small">When TL forwards requests, they will appear here.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($requests as $req): 
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
                                                    <span class="badge <?php 
                                                        $priority = $req['priority'] ?? 'Medium';
                                                        if ($priority === 'Urgent') echo 'bg-danger';
                                                        elseif ($priority === 'High') echo 'bg-warning';
                                                        elseif ($priority === 'Medium') echo 'bg-info';
                                                        else echo 'bg-secondary';
                                                    ?>"><?php echo e($priority); ?></span>
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
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table id="qsQuotationsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
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
                                <?php foreach ($requests as $req): 
                                    $isOverdue = false;
                                    if (!empty($req['required_by_date']) && $req['required_by_date'] !== '0000-00-00') {
                                        if (strtotime($req['required_by_date']) < time()) $isOverdue = true;
                                    }
                                ?>
                                    <tr class="<?php echo $req['priority'] === 'Urgent' ? 'table-danger' : ($req['priority'] === 'High' ? 'table-warning' : ''); ?>">
                                        <td><span class="fw-800"><?php echo e($req['request_no']); ?></span></td>
                                        <td>
                                            <div class="proj-title"><?php echo e($req['title']); ?></div>
                                            <div class="proj-sub"><i class="bi bi-building"></i> <?php echo e($req['project_name']); ?><?php if (!empty($req['project_code'])): ?> (<?php echo e($req['project_code']); ?>)<?php endif; ?></div>
                                        </td>
                                        <td><?php echo e($req['quotation_type']); ?></td>
                                        <td>
                                            <div class="fw-700 <?php echo $isOverdue ? 'text-danger' : ''; ?>"><?php echo safeDate($req['required_by_date']); ?></div>
                                            <?php if ($isOverdue): ?><div class="proj-sub text-danger">Overdue</div><?php endif; ?>
                                        </td>
                                        <td><?php echo (int)$req['quotation_count']; ?></td>
                                        <td><?php echo getStatusBadge($req['status']); ?></td>
                                        <td><?php echo getPriorityBadge($req['priority']); ?></td>
                                        <td class="text-end">
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
                                </tbody>
                            </table>
                        </div>
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
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    function initTable() {
        const isDesktop = window.matchMedia('(min-width: 768px)').matches;
        const tbl = document.getElementById('qsQuotationsTable');
        if (!tbl) return;
        if (isDesktop) {
            if (!$.fn.DataTable.isDataTable('#qsQuotationsTable')) {
                $('#qsQuotationsTable').DataTable({
                    responsive: true,
                    autoWidth: false,
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                    order: [[3, 'asc']],
                    columnDefs: [{ targets: [7], orderable: false, searchable: false }],
                    language: { zeroRecords: "No quotation requests assigned", info: "Showing _START_ to _END_ of _TOTAL_ requests" }
                });
            }
        } else {
            if ($.fn.DataTable.isDataTable('#qsQuotationsTable')) {
                $('#qsQuotationsTable').DataTable().destroy();
            }
        }
    }
    $(function () {
        initTable();
        window.addEventListener('resize', initTable);
    });
</script>
</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>