<?php
// view-employee.php (TEK-C style)
// ✅ Updated:
// 1) Handles profile/passbook paths like ..admin/, ../admin/, admin/, uploads/
// 2) Same width info cards
// 3) Consistent card heights
// 4) Full employee profile view
// 5) Assigned sites + attendance + leave requests

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeText($v, $fallback = 'Not Available'){
    $v = trim((string)$v);
    return $v !== '' ? e($v) : $fallback;
}

function safeDate($v, $fallback = 'Not Set'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $fallback;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateTime($v, $fallback = '—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return $fallback;
    $ts = strtotime($v);
    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

/**
 * Normalize file path from DB for this page.
 * Supports:
 *  - ..admin/uploads/...
 *  - ../admin/uploads/...
 *  - admin/uploads/...
 *  - /admin/uploads/...
 *  - uploads/...
 *  - /uploads/...
 *  - employees/...
 *  - /employees/...
 *  - full URL
 */
function fileUrl($path){
    $p = trim((string)$path);
    if ($p === '') return '';

    // Normalize slashes
    $p = str_replace('\\', '/', $p);
    $p = preg_replace('~/+~', '/', $p);

    // Full URL
    if (preg_match('~^https?://~i', $p)) return $p;

    // Fix bad stored path like "..admin/uploads/..."
    if (stripos($p, '..admin/') === 0) {
        $p = '../admin/' . substr($p, 8);
    }

    // Already correct relative path
    if (stripos($p, '../admin/uploads/') === 0) return $p;

    // admin/uploads/...  -> ../admin/uploads/...
    if (stripos($p, 'admin/uploads/') === 0) return '../' . $p;

    // /admin/uploads/... -> ../admin/uploads/...
    if (stripos($p, '/admin/uploads/') === 0) return '..' . $p;

    // uploads/... -> ../admin/uploads/...
    if (stripos($p, 'uploads/') === 0) return '../admin/' . $p;

    // /uploads/... -> ../admin/uploads/...
    if (stripos($p, '/uploads/') === 0) return '../admin' . $p;

    // employees/... -> ../admin/uploads/employees/...
    if (stripos($p, 'employees/') === 0) return '../admin/uploads/' . $p;

    // /employees/... -> ../admin/uploads/employees/...
    if (stripos($p, '/employees/') === 0) return '../admin/uploads' . $p;

    // Fallback
    return '../admin/uploads/' . ltrim($p, '/');
}

function badgeClass($status){
    $status = strtolower(trim((string)$status));
    if ($status === 'active') return 'status-active';
    if ($status === 'resigned') return 'status-resigned';
    return 'status-inactive';
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Invalid employee ID.");
}

$employee = null;
$assigned_sites = [];
$recent_attendance = [];
$recent_leave_requests = [];

/* =========================
   FETCH EMPLOYEE + REPORTING NAME
========================= */
$sql = "
    SELECT e.*,
           r.full_name AS reporting_to_name
    FROM employees e
    LEFT JOIN employees r ON e.reporting_to = r.id
    WHERE e.id = ?
    LIMIT 1
";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$employee = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$employee) {
    die("Employee not found.");
}

/* =========================
   FETCH ASSIGNED SITES
========================= */
$sqlSites = "
    SELECT 
        s.id,
        s.project_name,
        s.project_code,
        s.project_location,
        s.project_type,
        s.scope_of_work,
        s.location_address,
        CASE 
            WHEN s.manager_employee_id = ? THEN 'Manager'
            WHEN s.team_lead_employee_id = ? THEN 'Team Lead'
            WHEN spe.employee_id IS NOT NULL THEN 'Project Engineer'
            ELSE 'Assigned'
        END AS role_in_site
    FROM sites s
    LEFT JOIN site_project_engineers spe 
        ON s.id = spe.site_id AND spe.employee_id = ?
    WHERE (
        s.manager_employee_id = ?
        OR s.team_lead_employee_id = ?
        OR spe.employee_id = ?
    )
    AND s.deleted_at IS NULL
    ORDER BY s.created_at DESC
";
$stmtSites = mysqli_prepare($conn, $sqlSites);
if ($stmtSites) {
    mysqli_stmt_bind_param($stmtSites, "iiiiii", $id, $id, $id, $id, $id, $id);
    mysqli_stmt_execute($stmtSites);
    $resSites = mysqli_stmt_get_result($stmtSites);
    if ($resSites) {
        $assigned_sites = mysqli_fetch_all($resSites, MYSQLI_ASSOC);
    }
    mysqli_stmt_close($stmtSites);
}

/* =========================
   FETCH RECENT ATTENDANCE
========================= */
$sqlAttendance = "
    SELECT id, attendance_date, punch_in_time, punch_out_time, total_hours, status, punch_in_location, punch_out_location
    FROM attendance
    WHERE employee_id = ?
    ORDER BY attendance_date DESC, id DESC
    LIMIT 7
";
$stmtAttendance = mysqli_prepare($conn, $sqlAttendance);
if ($stmtAttendance) {
    mysqli_stmt_bind_param($stmtAttendance, "i", $id);
    mysqli_stmt_execute($stmtAttendance);
    $resAttendance = mysqli_stmt_get_result($stmtAttendance);
    if ($resAttendance) {
        $recent_attendance = mysqli_fetch_all($resAttendance, MYSQLI_ASSOC);
    }
    mysqli_stmt_close($stmtAttendance);
}

/* =========================
   FETCH RECENT LEAVE REQUESTS
========================= */
$sqlLeave = "
    SELECT id, leave_type, from_date, to_date, total_days, reason, status, applied_at
    FROM leave_requests
    WHERE employee_id = ?
    ORDER BY id DESC
    LIMIT 5
";
$stmtLeave = mysqli_prepare($conn, $sqlLeave);
if ($stmtLeave) {
    mysqli_stmt_bind_param($stmtLeave, "i", $id);
    mysqli_stmt_execute($stmtLeave);
    $resLeave = mysqli_stmt_get_result($stmtLeave);
    if ($resLeave) {
        $recent_leave_requests = mysqli_fetch_all($resLeave, MYSQLI_ASSOC);
    }
    mysqli_stmt_close($stmtLeave);
}

$photoSrc = fileUrl($employee['photo'] ?? '');
$passbookSrc = fileUrl($employee['passbook_photo'] ?? '');

$status = trim((string)($employee['employee_status'] ?? 'inactive'));
if (!in_array($status, ['active', 'inactive', 'resigned'], true)) {
    $status = 'inactive';
}
$statusText = ucfirst($status);
$statusClass = badgeClass($status);

$reportingName = trim((string)($employee['reporting_to_name'] ?? ''));
if ($reportingName === '') {
    $reportingName = trim((string)($employee['reporting_manager'] ?? ''));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>View Employee - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{
            flex:1 1 auto;
            overflow:auto;
            padding:22px 22px 14px;
        }

        .panel{
            background: var(--surface);
            border:1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding:16px;
            height:100%;
        }

        .panel-title{
            font-weight:900;
            font-size:18px;
            color:#1f2937;
            margin:0 0 14px;
        }

        .profile-card{
            background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
            border:1px solid var(--border);
            border-radius:20px;
            box-shadow: var(--shadow);
            padding:20px;
        }

        .profile-top{
            display:flex;
            gap:18px;
            align-items:center;
            flex-wrap:wrap;
        }

        .profile-photo{
            width:110px;
            height:110px;
            border-radius:22px;
            overflow:hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display:flex;
            align-items:center;
            justify-content:center;
            color:#fff;
            font-size:42px;
            font-weight:900;
            flex:0 0 auto;
            border:4px solid #fff;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
        }

        .profile-photo img{
            width:100%;
            height:100%;
            object-fit:cover;
        }

        .profile-name{
            font-size:28px;
            font-weight:1000;
            color:#111827;
            line-height:1.15;
        }

        .profile-sub{
            color:#6b7280;
            font-weight:800;
            margin-top:6px;
            display:flex;
            flex-wrap:wrap;
            gap:10px 16px;
        }

        .status-badge{
            padding:5px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:1000;
            text-transform:uppercase;
            letter-spacing:.4px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            white-space:nowrap;
        }

        .status-active{
            background: rgba(16,185,129,.12);
            color:#10b981;
            border:1px solid rgba(16,185,129,.22);
        }
        .status-inactive{
            background: rgba(245,158,11,.12);
            color:#f59e0b;
            border:1px solid rgba(245,158,11,.22);
        }
        .status-resigned{
            background: rgba(239,68,68,.12);
            color:#ef4444;
            border:1px solid rgba(239,68,68,.22);
        }

        .mini-badge{
            background: rgba(45,156,219,.1);
            color: var(--blue);
            padding:4px 10px;
            border-radius:10px;
            font-size:11px;
            font-weight:900;
            border:1px solid rgba(45,156,219,.18);
            display:inline-flex;
            align-items:center;
            gap:5px;
        }

        .info-grid{
            display:grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap:14px;
            align-items:stretch;
        }

        .info-item{
            border:1px solid var(--border);
            border-radius:14px;
            padding:12px 14px;
            background:#fff;
            min-height:88px;
            width:100%;
            display:flex;
            flex-direction:column;
            justify-content:flex-start;
        }

        .info-item.full-width{
            grid-column:1 / -1;
        }

        .info-label{
            font-size:11px;
            font-weight:1000;
            color:#6b7280;
            text-transform:uppercase;
            letter-spacing:.4px;
            margin-bottom:6px;
        }

        .info-value{
            font-size:14px;
            font-weight:900;
            color:#111827;
            line-height:1.35;
            word-break: break-word;
            width:100%;
        }

        .site-card{
            border:1px solid var(--border);
            border-radius:16px;
            padding:14px;
            background:#fff;
            height:100%;
        }

        .site-name{
            font-weight:1000;
            color:#111827;
            margin-bottom:6px;
            font-size:16px;
        }

        .site-meta{
            color:#6b7280;
            font-size:12px;
            font-weight:800;
            line-height:1.5;
        }

        .table thead th{
            font-size:11px;
            color:#6b7280;
            font-weight:800;
            border-bottom:1px solid var(--border)!important;
            padding:10px !important;
        }

        .table td{
            vertical-align:top;
            border-color:var(--border);
            font-weight:700;
            color:#374151;
            padding:10px !important;
            font-size:13px;
        }

        .section-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn-main{
            background: var(--blue);
            color:#fff;
            border:none;
            padding:10px 16px;
            border-radius:12px;
            font-weight:900;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }

        .btn-main:hover{
            color:#fff;
            background:#2589c5;
        }

        .btn-lite{
            background:#fff;
            color:#374151;
            border:1px solid var(--border);
            padding:10px 16px;
            border-radius:12px;
            font-weight:900;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }

        .btn-lite:hover{
            color:var(--blue);
            background:#f9fafb;
        }

        @media (max-width: 991.98px){
            .content-scroll{
                padding:18px;
            }
            .main{
                margin-left:0 !important;
                width:100% !important;
                max-width:100% !important;
            }
            .sidebar{
                position:fixed !important;
                transform:translateX(-100%);
                z-index:1040 !important;
            }
            .sidebar.open,
            .sidebar.active,
            .sidebar.show{
                transform:translateX(0) !important;
            }
        }

        @media (max-width: 767.98px){
            .content-scroll{
                padding:12px 10px 12px !important;
            }
            .info-grid{
                grid-template-columns:1fr;
            }
            .info-item,
            .info-item.full-width{
                grid-column:auto;
                min-height:auto;
            }
            .profile-name{
                font-size:22px;
            }
            .profile-photo{
                width:92px;
                height:92px;
                font-size:34px;
                border-radius:18px;
            }
        }

        @media (max-width: 768px) {
            .content-scroll {
                padding: 12px 10px 12px !important;
            }

            .container-fluid.maxw {
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            .panel {
                padding: 12px !important;
                margin-bottom: 12px;
                border-radius: 14px;
            }

            .sec-head {
                padding: 10px !important;
                border-radius: 12px;
            }
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

                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Employee Profile</h1>
                        <p class="text-muted mb-0">View complete employee information</p>
                    </div>

                    <div class="section-actions">
                        <a href="manage-employees.php" class="btn-lite">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <a href="edit-employee.php?id=<?php echo (int)$employee['id']; ?>" class="btn-main">
                            <i class="bi bi-pencil-square"></i> Edit Employee
                        </a>
                    </div>
                </div>

                <!-- Profile Header -->
                <div class="profile-card mb-4">
                    <div class="profile-top">
                        <div class="profile-photo">
                            <?php if (!empty($photoSrc)): ?>
                                <img src="<?php echo e($photoSrc); ?>" alt="<?php echo e($employee['full_name'] ?? ''); ?>">
                            <?php else: ?>
                                <?php echo strtoupper(substr((string)($employee['full_name'] ?? ''), 0, 1)); ?>
                            <?php endif; ?>
                        </div>

                        <div class="flex-grow-1">
                            <div class="profile-name"><?php echo e($employee['full_name'] ?? ''); ?></div>

                            <div class="profile-sub">
                                <span><i class="bi bi-hash"></i> <?php echo e($employee['employee_code'] ?? ''); ?></span>
                                <span><i class="bi bi-person-badge"></i> <?php echo safeText($employee['designation'] ?? '', 'No Designation'); ?></span>
                                <span><i class="bi bi-building"></i> <?php echo safeText($employee['department'] ?? '', 'No Department'); ?></span>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <span class="status-badge <?php echo e($statusClass); ?>">
                                    <i class="bi bi-circle-fill" style="font-size:8px;"></i>
                                    <?php echo e($statusText); ?>
                                </span>

                                <?php if (!empty($employee['work_location'])): ?>
                                    <span class="mini-badge"><i class="bi bi-geo-alt"></i> <?php echo e($employee['work_location']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($employee['site_name'])): ?>
                                    <span class="mini-badge"><i class="bi bi-diagram-3"></i> <?php echo e($employee['site_name']); ?></span>
                                <?php endif; ?>

                                <?php if (!empty($reportingName)): ?>
                                    <span class="mini-badge"><i class="bi bi-person-lines-fill"></i> Reports to: <?php echo e($reportingName); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Personal Details -->
                    <div class="col-12 col-xl-6">
                        <div class="panel">
                            <h3 class="panel-title">Personal Details</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Username</div>
                                    <div class="info-value"><?php echo safeText($employee['username'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Email</div>
                                    <div class="info-value"><?php echo safeText($employee['email'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Mobile Number</div>
                                    <div class="info-value"><?php echo safeText($employee['mobile_number'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Date of Birth</div>
                                    <div class="info-value"><?php echo safeDate($employee['date_of_birth'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Gender</div>
                                    <div class="info-value"><?php echo safeText($employee['gender'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Blood Group</div>
                                    <div class="info-value"><?php echo safeText($employee['blood_group'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Aadhaar Number</div>
                                    <div class="info-value"><?php echo safeText($employee['aadhar_card_number'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">PAN Number</div>
                                    <div class="info-value"><?php echo safeText($employee['pancard_number'] ?? ''); ?></div>
                                </div>
                                <div class="info-item full-width">
                                    <div class="info-label">Current Address</div>
                                    <div class="info-value"><?php echo safeText($employee['current_address'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Emergency Contact Name</div>
                                    <div class="info-value"><?php echo safeText($employee['emergency_contact_name'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Emergency Contact Phone</div>
                                    <div class="info-value"><?php echo safeText($employee['emergency_contact_phone'] ?? ''); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Work & Bank Details -->
                    <div class="col-12 col-xl-6">
                        <div class="panel">
                            <h3 class="panel-title">Work & Bank Details</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Date of Joining</div>
                                    <div class="info-value"><?php echo safeDate($employee['date_of_joining'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Employee Status</div>
                                    <div class="info-value"><?php echo e($statusText); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Department</div>
                                    <div class="info-value"><?php echo safeText($employee['department'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Designation</div>
                                    <div class="info-value"><?php echo safeText($employee['designation'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Work Location</div>
                                    <div class="info-value"><?php echo safeText($employee['work_location'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Site Name</div>
                                    <div class="info-value"><?php echo safeText($employee['site_name'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Reporting Manager</div>
                                    <div class="info-value"><?php echo safeText($reportingName, 'Not Assigned'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Created At</div>
                                    <div class="info-value"><?php echo safeDateTime($employee['created_at'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Bank Account Number</div>
                                    <div class="info-value"><?php echo safeText($employee['bank_account_number'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">IFSC Code</div>
                                    <div class="info-value"><?php echo safeText($employee['ifsc_code'] ?? ''); ?></div>
                                </div>
                                <div class="info-item full-width">
                                    <div class="info-label">Passbook / Bank File</div>
                                    <div class="info-value">
                                        <?php if (!empty($passbookSrc)): ?>
                                            <a href="<?php echo e($passbookSrc); ?>" target="_blank" rel="noopener" class="btn-lite">
                                                <i class="bi bi-file-earmark-arrow-down"></i> Open File
                                            </a>
                                        <?php else: ?>
                                            Not Available
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Assigned Sites -->
                    <div class="col-12">
                        <div class="panel">
                            <h3 class="panel-title">Assigned Sites</h3>

                            <?php if (empty($assigned_sites)): ?>
                                <div class="text-muted fw-bold">No assigned sites found for this employee.</div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($assigned_sites as $site): ?>
                                        <div class="col-12 col-md-6 col-xl-4">
                                            <div class="site-card">
                                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                                    <div class="site-name"><?php echo e($site['project_name'] ?? ''); ?></div>
                                                    <span class="mini-badge"><?php echo e($site['role_in_site'] ?? 'Assigned'); ?></span>
                                                </div>

                                                <div class="site-meta">
                                                    <div><i class="bi bi-hash"></i> <?php echo safeText($site['project_code'] ?? '', 'No Code'); ?></div>
                                                    <div><i class="bi bi-buildings"></i> <?php echo safeText($site['project_type'] ?? ''); ?></div>
                                                    <div><i class="bi bi-geo-alt"></i> <?php echo safeText($site['project_location'] ?? ''); ?></div>
                                                    <div><i class="bi bi-briefcase"></i> <?php echo safeText($site['scope_of_work'] ?? ''); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Attendance -->
                    <div class="col-12 col-xl-6">
                        <div class="panel">
                            <h3 class="panel-title">Recent Attendance</h3>

                            <?php if (empty($recent_attendance)): ?>
                                <div class="text-muted fw-bold">No attendance records found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>In</th>
                                                <th>Out</th>
                                                <th>Hours</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_attendance as $row): ?>
                                                <?php
                                                    $attStatus = trim((string)($row['status'] ?? 'present'));
                                                    $attClass = badgeClass($attStatus === 'present' ? 'active' : ($attStatus === 'absent' ? 'resigned' : 'inactive'));
                                                ?>
                                                <tr>
                                                    <td><?php echo safeDate($row['attendance_date'] ?? '', '—'); ?></td>
                                                    <td><?php echo safeDateTime($row['punch_in_time'] ?? '', '—'); ?></td>
                                                    <td><?php echo safeDateTime($row['punch_out_time'] ?? '', '—'); ?></td>
                                                    <td><?php echo e((string)($row['total_hours'] ?? '0')); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo e($attClass); ?>">
                                                            <?php echo e(ucfirst((string)($row['status'] ?? '—'))); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Leave Requests -->
                    <div class="col-12 col-xl-6">
                        <div class="panel">
                            <h3 class="panel-title">Recent Leave Requests</h3>

                            <?php if (empty($recent_leave_requests)): ?>
                                <div class="text-muted fw-bold">No leave requests found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Type</th>
                                                <th>From</th>
                                                <th>To</th>
                                                <th>Days</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_leave_requests as $leave): ?>
                                                <?php
                                                    $lvStatus = trim((string)($leave['status'] ?? 'Pending'));
                                                    $lvClass = 'status-inactive';
                                                    if (strcasecmp($lvStatus, 'Approved') === 0) $lvClass = 'status-active';
                                                    elseif (strcasecmp($lvStatus, 'Rejected') === 0 || strcasecmp($lvStatus, 'Cancelled') === 0) $lvClass = 'status-resigned';
                                                ?>
                                                <tr>
                                                    <td><?php echo safeText($leave['leave_type'] ?? ''); ?></td>
                                                    <td><?php echo safeDate($leave['from_date'] ?? '', '—'); ?></td>
                                                    <td><?php echo safeDate($leave['to_date'] ?? '', '—'); ?></td>
                                                    <td><?php echo e((string)($leave['total_days'] ?? '0')); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo e($lvClass); ?>">
                                                            <?php echo e($lvStatus); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
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
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>