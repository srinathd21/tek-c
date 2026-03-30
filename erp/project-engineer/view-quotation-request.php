<?php
// view-quotation-request.php
session_start();
require_once 'includes/db-config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$user_id = (int)$_SESSION['employee_id'];
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

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

function safeDate($v, $dash = '—') {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function formatCurrency($amount) {
    if ($amount === null || $amount == 0) return '—';
    return '₹ ' . number_format($amount, 2);
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
        'Draft' => ['bg-secondary', 'bi-pencil'],
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

// Fetch quotation request details
$query = "
    SELECT 
        qr.*,
        s.project_name,
        s.project_code,
        s.project_location,
        s.project_type,
        s.scope_of_work,
        c.client_name,
        c.company_name,
        c.mobile_number AS client_mobile,
        c.email AS client_email,
        c.state AS client_state,
        m.full_name AS manager_name,
        m.employee_code AS manager_code,
        tl.full_name AS team_lead_name,
        tl.employee_code AS team_lead_code,
        (
            SELECT GROUP_CONCAT(e.full_name SEPARATOR ', ')
            FROM site_project_engineers spe
            JOIN employees e ON e.id = spe.employee_id
            WHERE spe.site_id = qr.site_id
        ) AS project_engineers
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN employees m ON s.manager_employee_id = m.id
    LEFT JOIN employees tl ON s.team_lead_employee_id = tl.id
    WHERE qr.id = ? AND qr.requested_by = ?
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$request) {
    header('Location: my-quotation-requests.php');
    exit();
}

// Parse attachments JSON
$attachments = [];
if (!empty($request['additional_documents_json'])) {
    $attachments = json_decode($request['additional_documents_json'], true) ?: [];
}

// Get status message if any
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';
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

    <!-- DataTables (Bootstrap 5 + Responsive) -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- Lightbox for image viewing -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

        .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
            padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
        .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
        .stat-ic.blue{ background: var(--blue); }
        .stat-ic.green{ background: #10b981; }
        .stat-ic.yellow{ background: #f59e0b; }
        .stat-ic.red{ background: #ef4444; }
        .stat-ic.purple{ background: #8b5cf6; }
        .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
        .stat-value{ font-size:17px; font-weight:900; line-height:1; margin-top:2px; }

        .proj-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
        .proj-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.25; }

        .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }

        .info-item {
            padding: 10px 12px;
            background: #f9fafb;
            border-radius: 10px;
            border: 1px solid var(--border);
        }

        .info-label {
            font-size: 11px;
            font-weight: 800;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 900;
            color: #1f2937;
        }

        .description-box {
            background: #f9fafb;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid var(--border);
            line-height: 1.5;
            font-size: 14px;
        }

        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 7px 10px;
            color: var(--muted);
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-weight: 900;
        }
        .btn-action:hover { background: var(--bg); color: var(--blue); }
        .btn-action.quotation{
            border-color: rgba(16,185,129,.25);
        }

        .file-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #f9fafb;
            border-radius: 10px;
            border: 1px solid var(--border);
            transition: all 0.2s;
        }

        .file-item:hover {
            background: #f3f4f6;
            border-color: var(--blue);
        }

        .file-icon {
            width: 32px;
            height: 32px;
            background: var(--blue);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }

        .file-icon.pdf { background: #dc2626; }
        .file-icon.image { background: #8b5cf6; }
        .file-icon.dwg { background: #f59e0b; }

        .file-details {
            flex: 1;
        }

        .file-name {
            font-weight: 800;
            color: #1f2937;
            font-size: 13px;
            margin-bottom: 2px;
        }

        .file-meta {
            font-size: 10px;
            color: #6b7280;
            font-weight: 700;
        }

        .file-actions {
            display: flex;
            gap: 6px;
        }

        .btn-icon {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: white;
            color: #6b7280;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 14px;
        }

        .btn-icon:hover {
            background: var(--blue);
            color: white;
            border-color: var(--blue);
        }

        .timeline {
            position: relative;
            padding-left: 25px;
            margin-top: 15px;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -25px;
            top: 0;
            width: 2px;
            height: 100%;
            background: var(--border);
        }

        .timeline-item:last-child::before {
            height: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -31px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--blue);
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .timeline-dot.completed { background: #10b981; }
        .timeline-dot.pending { background: #f59e0b; }
        .timeline-dot.cancelled { background: #ef4444; }

        .timeline-content {
            background: #f9fafb;
            border-radius: 10px;
            padding: 12px 15px;
            border: 1px solid var(--border);
        }

        .timeline-title {
            font-weight: 900;
            color: #1f2937;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .timeline-date {
            font-size: 11px;
            color: #6b7280;
            font-weight: 700;
        }

        .status-badge{
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 1000;
            letter-spacing: .3px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            white-space: nowrap;
            text-transform: uppercase;
            border:1px solid transparent;
        }
        .status-green{ background: rgba(16,185,129,.12); color:#10b981; border-color: rgba(16,185,129,.22); }
        .status-yellow{ background: rgba(245,158,11,.12); color:#f59e0b; border-color: rgba(245,158,11,.22); }
        .status-red{ background: rgba(239,68,68,.12); color:#ef4444; border-color: rgba(239,68,68,.22); }

        @media (max-width: 991.98px) {
            .main{
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .sidebar{
                position: fixed !important;
                transform: translateX(-100%);
                z-index: 1040 !important;
            }
            .sidebar.open, .sidebar.active, .sidebar.show{
                transform: translateX(0) !important;
            }
        }
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
            .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
            .sec-head { padding: 10px !important; border-radius: 12px; }
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

                    <!-- Status Messages -->
                    <?php if ($status && $message): ?>
                        <div class="alert alert-<?php echo $status === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                            <i class="bi bi-<?php echo $status === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Header with Actions -->
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-dark mb-1">Quotation Request Details</h1>
                            <p class="text-muted mb-0">
                                Request #<?php echo e($request['request_no']); ?> • Created on <?php echo safeDate($request['created_at']); ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="my-quotation-requests.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                            <?php if ($request['status'] === 'Draft'): ?>
                                <a href="quotation-requests.php?edit=<?php echo $request['id']; ?>" class="btn btn-primary">
                                    <i class="bi bi-pencil"></i> Edit Draft
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-building"></i></div>
                                <div>
                                    <div class="stat-label">Site/Project</div>
                                    <div class="stat-value"><?php echo e($request['project_name']); ?></div>
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
                                    <div class="stat-value"><?php echo e($request['quotation_type']); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic yellow"><i class="bi bi-calendar"></i></div>
                                <div>
                                    <div class="stat-label">Required By</div>
                                    <div class="stat-value"><?php echo safeDate($request['required_by_date']); ?></div>
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

                    <!-- Contract Value Summary (if budget exists) -->
                    <?php if ($request['estimated_budget'] > 0): ?>
                    <div class="row g-3 mb-3">
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

                    <!-- Directory / Details Panel -->
                    <div class="panel mb-4">
                        <div class="panel-header">
                            <h3 class="panel-title">Request Details</h3>
                            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                        </div>

                        <!-- Description -->
                        <div class="mb-4">
                            <h4 class="fw-900 mb-2" style="font-size: 14px;">Description</h4>
                            <div class="description-box">
                                <?php echo nl2br(e($request['description'])); ?>
                            </div>
                        </div>

                        <?php if (!empty($request['specifications'])): ?>
                        <div class="mb-4">
                            <h4 class="fw-900 mb-2" style="font-size: 14px;">Specifications</h4>
                            <div class="description-box">
                                <?php echo nl2br(e($request['specifications'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($request['notes'])): ?>
                        <div class="mb-4">
                            <h4 class="fw-900 mb-2" style="font-size: 14px;">Additional Notes</h4>
                            <div class="description-box">
                                <?php echo nl2br(e($request['notes'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Project Information Grid -->
                        <h4 class="fw-900 mb-2" style="font-size: 14px;">Project Information</h4>
                        <div class="info-grid mb-4">
                            <div class="info-item">
                                <div class="info-label">Client Name</div>
                                <div class="info-value"><?php echo e($request['client_name'] ?? '—'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Company</div>
                                <div class="info-value"><?php echo e($request['company_name'] ?? '—'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Location</div>
                                <div class="info-value"><?php echo e($request['project_location'] ?? '—'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Project Type</div>
                                <div class="info-value"><?php echo e($request['project_type'] ?? '—'); ?></div>
                            </div>
                            <?php if (!empty($request['scope_of_work'])): ?>
                            <div class="info-item" style="grid-column: span 2;">
                                <div class="info-label">Scope of Work</div>
                                <div class="info-value"><?php echo e($request['scope_of_work']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Team Information Grid -->
                        <h4 class="fw-900 mb-2" style="font-size: 14px;">Team</h4>
                        <div class="info-grid mb-4">
                            <div class="info-item">
                                <div class="info-label">Manager</div>
                                <div class="info-value"><?php echo e($request['manager_name'] ?? '—'); ?></div>
                                <?php if (!empty($request['manager_code'])): ?>
                                    <div class="proj-sub">Code: <?php echo e($request['manager_code']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Team Lead</div>
                                <div class="info-value"><?php echo e($request['team_lead_name'] ?? '—'); ?></div>
                                <?php if (!empty($request['team_lead_code'])): ?>
                                    <div class="proj-sub">Code: <?php echo e($request['team_lead_code']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="info-item" style="grid-column: span 2;">
                                <div class="info-label">Project Engineers</div>
                                <div class="info-value"><?php echo e($request['project_engineers'] ?? '—'); ?></div>
                            </div>
                        </div>

                        <!-- Client Contact Information -->
                        <?php if (!empty($request['client_mobile']) || !empty($request['client_email'])): ?>
                        <h4 class="fw-900 mb-2" style="font-size: 14px;">Client Contact</h4>
                        <div class="info-grid">
                            <?php if (!empty($request['client_mobile'])): ?>
                            <div class="info-item">
                                <div class="info-label">Phone</div>
                                <div class="info-value"><?php echo e($request['client_mobile']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($request['client_email'])): ?>
                            <div class="info-item">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?php echo e($request['client_email']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($request['client_state'])): ?>
                            <div class="info-item">
                                <div class="info-label">State</div>
                                <div class="info-value"><?php echo e($request['client_state']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Drawing & Attachments Panel -->
                    <?php if (!empty($request['drawing_number']) || !empty($request['drawing_file']) || !empty($attachments)): ?>
                    <div class="panel mb-4">
                        <div class="panel-header">
                            <h3 class="panel-title">Drawings & Attachments</h3>
                            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                        </div>

                        <!-- Drawing Information -->
                        <?php if (!empty($request['drawing_number']) || !empty($request['drawing_file'])): ?>
                        <div class="mb-3">
                            <h4 class="fw-900 mb-2" style="font-size: 14px;">Drawing</h4>
                            <?php if (!empty($request['drawing_number'])): ?>
                            <div class="info-item mb-2">
                                <div class="info-label">Drawing Number</div>
                                <div class="info-value"><?php echo e($request['drawing_number']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($request['drawing_file'])): ?>
                            <div class="file-item">
                                <div class="file-icon pdf">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </div>
                                <div class="file-details">
                                    <div class="file-name">Drawing File</div>
                                    <div class="file-meta">
                                        <?php 
                                        $filename = basename($request['drawing_file']);
                                        echo e(strlen($filename) > 30 ? substr($filename, 0, 27) . '...' : $filename);
                                        ?>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="<?php echo e($request['drawing_file']); ?>" target="_blank" class="btn-icon" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="<?php echo e($request['drawing_file']); ?>" download class="btn-icon" title="Download">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Additional Documents -->
                        <?php if (!empty($attachments)): ?>
                        <div>
                            <h4 class="fw-900 mb-2" style="font-size: 14px;">Additional Documents</h4>
                            <div class="file-list">
                                <?php if (isset($attachments['drawing'])): ?>
                                    <div class="file-item">
                                        <div class="file-icon pdf">
                                            <i class="bi bi-file-earmark"></i>
                                        </div>
                                        <div class="file-details">
                                            <div class="file-name">Drawing</div>
                                            <div class="file-meta">
                                                Uploaded <?php echo safeDate($attachments['drawing']['uploaded_at']); ?>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <a href="<?php echo e($attachments['drawing']['file_path']); ?>" target="_blank" class="btn-icon">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?php echo e($attachments['drawing']['file_path']); ?>" download class="btn-icon">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (isset($attachments['additional'])): ?>
                                    <?php foreach ($attachments['additional'] as $file): ?>
                                    <div class="file-item">
                                        <div class="file-icon">
                                            <i class="bi bi-file-earmark"></i>
                                        </div>
                                        <div class="file-details">
                                            <div class="file-name"><?php echo e(strlen($file['original_name']) > 30 ? substr($file['original_name'], 0, 27) . '...' : $file['original_name']); ?></div>
                                            <div class="file-meta">
                                                Uploaded <?php echo safeDate($file['uploaded_at']); ?>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <a href="<?php echo e($file['file_path']); ?>" target="_blank" class="btn-icon">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?php echo e($file['file_path']); ?>" download class="btn-icon">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Timeline/Workflow Status Panel -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Workflow Timeline</h3>
                            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                        </div>

                        <div class="timeline">
                            <div class="timeline-item">
                                <div class="timeline-dot completed"></div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Request Created</div>
                                    <div class="timeline-date">
                                        <?php echo safeDate($request['created_at']); ?> by <?php echo e($request['requested_by_name']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($request['assigned_at']): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot completed"></div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Assigned to Project Engineer</div>
                                    <div class="timeline-date">
                                        <?php echo safeDate($request['assigned_at']); ?> 
                                        <?php if ($request['project_engineer_name']): ?>
                                            to <?php echo e($request['project_engineer_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php
                            $status_dot_class = 'pending';
                            if (in_array($request['status'], ['Approved', 'QS Finalized'])) {
                                $status_dot_class = 'completed';
                            } elseif (in_array($request['status'], ['Rejected', 'Cancelled'])) {
                                $status_dot_class = 'cancelled';
                            }
                            ?>
                            <div class="timeline-item">
                                <div class="timeline-dot <?php echo $status_dot_class; ?>"></div>
                                <div class="timeline-content">
                                    <div class="timeline-title">Current Status: <?php echo e($request['status']); ?></div>
                                    <div class="timeline-date">
                                        Last updated: <?php echo safeDate($request['updated_at']); ?>
                                    </div>
                                    <?php if ($request['status'] === 'Rejected' && !empty($request['rejection_reason'])): ?>
                                        <div class="mt-2 p-2" style="background: rgba(239,68,68,.08); border-radius: 8px; font-size:12px;">
                                            <strong class="text-danger">Rejection Reason:</strong><br>
                                            <?php echo e($request['rejection_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($request['status'] === 'Cancelled' && !empty($request['cancellation_reason'])): ?>
                                        <div class="mt-2 p-2" style="background: rgba(107,114,128,.08); border-radius: 8px; font-size:12px;">
                                            <strong>Cancellation Reason:</strong><br>
                                            <?php echo e($request['cancellation_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <!-- Lightbox -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>

    <!-- TEK-C Custom JavaScript -->
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
        // Initialize lightbox for images
        lightbox.option({
            'resizeDuration': 200,
            'wrapAround': true,
            'albumLabel': 'Image %1 of %2'
        });
    </script>
</body>
</html>