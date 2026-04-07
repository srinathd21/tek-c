<?php
// hr/view-offer.php - View Offer Details (TEK-C Tab Style)
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die('Database connection failed.'); }

if (empty($_SESSION['employee_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_employee_id = (int)($_SESSION['employee_id'] ?? 0);

$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active' LIMIT 1");
mysqli_stmt_bind_param($emp_stmt, 'i', $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$current_employee = mysqli_fetch_assoc(mysqli_stmt_get_result($emp_stmt));
mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die('Employee not found.');
}

$designation = strtolower(trim((string)($current_employee['designation'] ?? '')));
$department  = strtolower(trim((string)($current_employee['department'] ?? '')));
$isHr      = ($designation === 'hr' || $department === 'hr');
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'director', 'administrator'], true);
$isAdmin   = in_array($designation, ['administrator', 'admin', 'director'], true);

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header('Location: ../dashboard.php');
    exit;
}

$offer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($offer_id <= 0) {
    header('Location: candidates.php');
    exit;
}

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function showVal($v, $dash='—'){
    $v = trim((string)$v);
    return $v === '' ? $dash : e($v);
}
function showDateVal($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}
function showDateTimeVal($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}
function initials($name){
    $name = trim((string)$name);
    if ($name === '') return 'C';
    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'C', 0, 1));
    $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
    return (count($parts) > 1 && $last) ? ($first . $last) : $first;
}
function fileUrl($path){
    $p = trim((string)$path);
    if ($p === '') return '';
    if (preg_match('~^https?://~i', $p)) return $p;
    if (stripos($p, '../admin/uploads/') === 0) return $p;
    if (stripos($p, 'admin/uploads/') === 0) return '../' . $p;
    if (stripos($p, '/admin/uploads/') === 0) return '..' . $p;
    if (stripos($p, 'uploads/') === 0) return '../' . $p;
    if (stripos($p, '/uploads/') === 0) return '..' . $p;
    if (stripos($p, 'candidates/') === 0) return '../uploads/' . $p;
    if (stripos($p, '/candidates/') === 0) return '../uploads' . $p;
    return '../' . ltrim($p, '/');
}
function formatMoney($amount, $suffix=''){
    if ($amount === null || $amount === '') return '—';
    return '₹ ' . number_format((float)$amount, 2) . $suffix;
}
function statusChip($status){
    $s = trim((string)$status);
    $key = strtolower($s);
    $map = [
        'draft' => ['bg'=>'rgba(107,114,128,.12)','bd'=>'rgba(107,114,128,.22)','tx'=>'#6b7280','icon'=>'bi-pencil-square','label'=>'Draft'],
        'pending' => ['bg'=>'rgba(245,158,11,.12)','bd'=>'rgba(245,158,11,.22)','tx'=>'#f59e0b','icon'=>'bi-hourglass-split','label'=>'Pending'],
        'approved' => ['bg'=>'rgba(16,185,129,.12)','bd'=>'rgba(16,185,129,.22)','tx'=>'#10b981','icon'=>'bi-check-circle','label'=>'Approved'],
        'accepted' => ['bg'=>'rgba(34,197,94,.12)','bd'=>'rgba(34,197,94,.22)','tx'=>'#22c55e','icon'=>'bi-check2-circle','label'=>'Accepted'],
        'rejected' => ['bg'=>'rgba(239,68,68,.12)','bd'=>'rgba(239,68,68,.22)','tx'=>'#ef4444','icon'=>'bi-x-circle','label'=>'Rejected'],
        'declined' => ['bg'=>'rgba(239,68,68,.12)','bd'=>'rgba(239,68,68,.22)','tx'=>'#ef4444','icon'=>'bi-slash-circle','label'=>'Declined'],
        'sent' => ['bg'=>'rgba(59,130,246,.12)','bd'=>'rgba(59,130,246,.22)','tx'=>'#3b82f6','icon'=>'bi-send','label'=>'Sent'],
        'joined' => ['bg'=>'rgba(6,182,212,.12)','bd'=>'rgba(6,182,212,.22)','tx'=>'#06b6d4','icon'=>'bi-person-check','label'=>'Joined'],
    ];
    $c = $map[$key] ?? ['bg'=>'#f9fafb','bd'=>'#e5e7eb','tx'=>'#111827','icon'=>'bi-circle','label'=>($s !== '' ? $s : 'Unknown')];
    return '<span class="chip" style="background:' . $c['bg'] . ';border-color:' . $c['bd'] . ';color:' . $c['tx'] . ';"><i class="bi ' . $c['icon'] . '"></i> ' . e($c['label']) . '</span>';
}
function get_column_names(mysqli $conn, $table){
    $cols = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}`");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cols[] = $row['Field'];
        }
        mysqli_free_result($res);
    }
    return $cols;
}
function pick_value(array $row, array $keys, $default=''){
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

$offer_columns = get_column_names($conn, 'offers');
$candidate_columns = get_column_names($conn, 'candidates');
$employee_columns = get_column_names($conn, 'employees');
$onboarding_columns = get_column_names($conn, 'onboarding');

$offer_select_parts = ['o.*'];
$offer_select_parts[] = 'c.id AS candidate_id_ref';
if (in_array('candidate_code', $candidate_columns, true)) $offer_select_parts[] = 'c.candidate_code';
if (in_array('first_name', $candidate_columns, true)) $offer_select_parts[] = 'c.first_name';
if (in_array('last_name', $candidate_columns, true)) $offer_select_parts[] = 'c.last_name';
if (in_array('email', $candidate_columns, true)) $offer_select_parts[] = 'c.email AS candidate_email';
if (in_array('phone', $candidate_columns, true)) $offer_select_parts[] = 'c.phone AS candidate_phone';
if (in_array('photo_path', $candidate_columns, true)) $offer_select_parts[] = 'c.photo_path';
if (in_array('current_location', $candidate_columns, true)) $offer_select_parts[] = 'c.current_location';
if (in_array('hiring_request_id', $candidate_columns, true)) $offer_select_parts[] = 'c.hiring_request_id';
if (in_array('created_by', $employee_columns, true)) { /* no-op */ }
if (in_array('full_name', $employee_columns, true)) $offer_select_parts[] = 'e.full_name AS created_by_name';
if (in_array('employee_code', $employee_columns, true)) $offer_select_parts[] = 'e.employee_code AS created_by_code';

$offer_query = "SELECT " . implode(', ', $offer_select_parts) . "
                FROM offers o
                LEFT JOIN candidates c ON o.candidate_id = c.id
                LEFT JOIN employees e ON o.created_by = e.id
                WHERE o.id = ?
                LIMIT 1";

$stmt = mysqli_prepare($conn, $offer_query);
if (!$stmt) { die('Unable to prepare offer query: ' . mysqli_error($conn)); }
mysqli_stmt_bind_param($stmt, 'i', $offer_id);
mysqli_stmt_execute($stmt);
$offer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$offer) {
    $_SESSION['flash_error'] = 'Offer not found.';
    header('Location: candidates.php');
    exit;
}

$candidate_id = (int)pick_value($offer, ['candidate_id_ref', 'candidate_id'], 0);

if (!$isHr && !$isAdmin && $candidate_id > 0) {
    $check_query = "SELECT h.requested_by
                    FROM candidates c
                    JOIN hiring_requests h ON c.hiring_request_id = h.id
                    WHERE c.id = ?
                    LIMIT 1";
    $check_stmt = mysqli_prepare($conn, $check_query);
    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, 'i', $candidate_id);
        mysqli_stmt_execute($check_stmt);
        $check_row = mysqli_fetch_assoc(mysqli_stmt_get_result($check_stmt));
        mysqli_stmt_close($check_stmt);
        if (!$check_row || (int)$check_row['requested_by'] !== $current_employee_id) {
            $_SESSION['flash_error'] = "You don't have permission to view this offer.";
            header('Location: candidates.php');
            exit;
        }
    }
}

$onboarding = null;
if ($candidate_id > 0 && !empty($onboarding_columns)) {
    $onboarding_stmt = mysqli_prepare($conn, "SELECT * FROM onboarding WHERE candidate_id = ? ORDER BY id DESC LIMIT 1");
    if ($onboarding_stmt) {
        mysqli_stmt_bind_param($onboarding_stmt, 'i', $candidate_id);
        mysqli_stmt_execute($onboarding_stmt);
        $onboarding = mysqli_fetch_assoc(mysqli_stmt_get_result($onboarding_stmt));
        mysqli_stmt_close($onboarding_stmt);
    }
}

$candidate_name = trim((string)pick_value($offer, ['candidate_name'], trim((string)pick_value($offer, ['first_name']) . ' ' . (string)pick_value($offer, ['last_name']))));
$candidate_name = $candidate_name !== '' ? $candidate_name : 'Candidate';
$photoUrl = !empty($offer['photo_path']) ? fileUrl($offer['photo_path']) : '';

$offer_no          = pick_value($offer, ['offer_no','offer_number','offer_letter_no','reference_no','offer_code'], 'OFF' . str_pad((string)$offer_id, 4, '0', STR_PAD_LEFT));
$offer_status      = pick_value($offer, ['status','offer_status'], 'Pending');
$designation_val   = pick_value($offer, ['designation','job_title','position_title','role','position']);
$department_val    = pick_value($offer, ['department']);
$location_val      = pick_value($offer, ['location','job_location','work_location']);
$joining_date      = pick_value($offer, ['joining_date','expected_joining_date','date_of_joining']);
$offer_date        = pick_value($offer, ['offer_date','date','created_at']);
$valid_till        = pick_value($offer, ['valid_till','expiry_date','offer_expiry_date','acceptance_deadline']);
$salary_monthly    = pick_value($offer, ['monthly_salary','monthly_ctc','gross_salary','salary_per_month']);
$salary_annual     = pick_value($offer, ['annual_ctc','ctc','offered_ctc','annual_salary']);
$basic_salary      = pick_value($offer, ['basic_salary']);
$hra               = pick_value($offer, ['hra','house_rent_allowance']);
$special_allowance = pick_value($offer, ['special_allowance']);
$bonus             = pick_value($offer, ['bonus','joining_bonus']);
$reporting_to      = pick_value($offer, ['reporting_to','manager_name','reporting_manager']);
$employment_type   = pick_value($offer, ['employment_type','employee_type']);
$probation_period  = pick_value($offer, ['probation_period','probation']);
$remarks           = pick_value($offer, ['remarks','notes','comment']);
$terms             = pick_value($offer, ['terms_conditions','terms_and_conditions','offer_terms']);
$document_path     = pick_value($offer, ['offer_letter_path','document_path','pdf_path','attachment']);
$documentUrl       = $document_path !== '' ? fileUrl($document_path) : '';
$created_at        = pick_value($offer, ['created_at']);
$updated_at        = pick_value($offer, ['updated_at']);
$created_by_name   = pick_value($offer, ['created_by_name']);
$created_by_code   = pick_value($offer, ['created_by_code']);
$candidate_email   = pick_value($offer, ['candidate_email','email']);
$candidate_phone   = pick_value($offer, ['candidate_phone','phone']);
$current_location  = pick_value($offer, ['current_location']);

$pageTitle = 'Offer: ' . $offer_no;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo e($pageTitle); ?> - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 10px 30px rgba(17,24,39,.05); padding:18px; margin-bottom:16px; }
        .btn-back,.btn-doc,.btn-edit{ border-radius:12px; padding:10px 14px; font-weight:900; display:inline-flex; align-items:center; gap:8px; text-decoration:none; white-space:nowrap; }
        .btn-back,.btn-doc{ background:#fff; border:1px solid #e5e7eb; color:#111827; }
        .btn-back:hover,.btn-doc:hover{ background:#f9fafb; color:var(--blue); border-color:rgba(45,156,219,.25); }
        .btn-edit{ background:var(--blue); color:#fff; border:none; box-shadow:0 12px 26px rgba(45,156,219,.18); padding:10px 16px; }
        .btn-edit:hover{ background:#2a8bc9; color:#fff; }
        .hero{ display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .hero-left{ display:flex; gap:14px; align-items:center; min-width:260px; }
        .emp-avatar{ width:72px; height:72px; border-radius:18px; background:linear-gradient(135deg, rgba(45,156,219,.95), rgba(99,102,241,.95)); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:1000; letter-spacing:.5px; flex:0 0 auto; overflow:hidden; border:3px solid rgba(255,255,255,.7); box-shadow:0 12px 26px rgba(17,24,39,.10); }
        .emp-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
        .hero-title{ margin:0; font-weight:1000; color:#111827; font-size:18px; line-height:1.2; }
        .hero-sub{ margin:4px 0 0; color:#6b7280; font-weight:700; font-size:13px; }
        .profile-preview{ min-width:250px; background:linear-gradient(135deg,#f8fbff,#f3f4f6); border:1px dashed #dbe3ef; border-radius:18px; padding:18px; display:flex; flex-direction:column; gap:10px; }
        .preview-stat{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .preview-stat .k{ color:#6b7280; font-weight:800; font-size:12px; }
        .preview-stat .v{ color:#111827; font-weight:900; font-size:14px; text-align:right; }
        .chip{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:900; border:1px solid #e5e7eb; background:#f9fafb; color:#111827; white-space:nowrap; }
        .profile-tabs{ border-bottom:1px solid #e5e7eb; gap:8px; }
        .profile-tabs .nav-link{ border:1px solid #e5e7eb; background:#fff; color:#374151; font-weight:900; border-radius:12px; padding:10px 12px; display:flex; align-items:center; gap:8px; }
        .profile-tabs .nav-link:hover{ background:#f9fafb; color:var(--blue); border-color:rgba(45,156,219,.25); }
        .profile-tabs .nav-link.active{ background:rgba(45,156,219,.10); color:var(--blue); border-color:rgba(45,156,219,.25); box-shadow:0 12px 26px rgba(45,156,219,.10); }
        .kv{ border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; background:#fff; box-shadow:0 10px 30px rgba(17,24,39,.05); }
        .kv-row{ display:grid; grid-template-columns:260px 1fr; gap:12px; padding:12px 16px; border-bottom:1px solid #eef2f7; align-items:start; }
        .kv-row:last-child{ border-bottom:none; }
        .kv-k{ color:#6b7280; font-weight:900; font-size:13px; }
        .kv-v{ color:#111827; font-weight:700; font-size:14px; word-break:break-word; }
        .stats-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(190px,1fr)); gap:14px; }
        .stat-card{ background:linear-gradient(180deg,#fff,#fbfdff); border:1px solid #e5e7eb; border-radius:16px; padding:16px; box-shadow:0 10px 24px rgba(17,24,39,.04); display:flex; gap:12px; align-items:center; }
        .stat-icon{ width:48px; height:48px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
        .stat-icon.blue{ background:linear-gradient(135deg,#3b82f6,#2563eb); }
        .stat-icon.green{ background:linear-gradient(135deg,#10b981,#059669); }
        .stat-icon.orange{ background:linear-gradient(135deg,#f59e0b,#d97706); }
        .stat-icon.purple{ background:linear-gradient(135deg,#8b5cf6,#7c3aed); }
        .stat-label{ color:#6b7280; font-weight:800; font-size:12px; }
        .stat-value{ color:#111827; font-weight:1000; font-size:22px; line-height:1.1; }
        .text-note{ background:#f8fafc; border:1px solid #e5e7eb; border-radius:14px; padding:14px; color:#374151; }
        .offer-box{ background:linear-gradient(135deg,#eef6ff,#f9fbff); border:1px dashed #bfdbfe; border-radius:16px; padding:16px; }
        @media (max-width: 991px){ .kv-row{ grid-template-columns:1fr; } .profile-preview{ min-width:100%; } }
        @media (max-width: 768px){ .content-scroll{ padding:12px; } }
        @media print {
            .btn-back,.btn-doc,.btn-edit,.profile-tabs,.sidebar,.topbar,footer{ display:none !important; }
            .content-scroll{ padding:0; }
            .panel{ box-shadow:none; border-color:#ddd; }
            body{ background:#fff !important; }
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

                <?php if (isset($_SESSION['flash_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Offer Details</h1>
                        <p class="text-muted mb-0">Complete offer information in tab view</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($candidate_id > 0): ?>
                            <a href="view-candidate.php?id=<?php echo (int)$candidate_id; ?>" class="btn-back"><i class="bi bi-arrow-left"></i> Candidate</a>
                        <?php else: ?>
                            <a href="candidates.php" class="btn-back"><i class="bi bi-arrow-left"></i> Back</a>
                        <?php endif; ?>
                        <?php if (!empty($documentUrl)): ?>
                            <a href="<?php echo e($documentUrl); ?>" target="_blank" rel="noopener" class="btn-doc"><i class="bi bi-file-earmark-arrow-down"></i> Offer File</a>
                        <?php endif; ?>
                        <a href="#" onclick="window.print(); return false;" class="btn-edit"><i class="bi bi-printer"></i> Print</a>
                    </div>
                </div>

                <div class="panel">
                    <div class="hero">
                        <div class="hero-left">
                            <div class="emp-avatar">
                                <?php if (!empty($photoUrl)): ?>
                                    <img src="<?php echo e($photoUrl); ?>" alt="<?php echo e($candidate_name); ?>" onerror="this.style.display='none'; this.parentNode.textContent='<?php echo e(initials($candidate_name)); ?>';">
                                <?php else: ?>
                                    <?php echo e(initials($candidate_name)); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="hero-title"><?php echo e($candidate_name); ?></p>
                                <p class="hero-sub">Offer No: <?php echo e($offer_no); ?><?php if ($designation_val !== ''): ?> • <?php echo e($designation_val); ?><?php endif; ?></p>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <?php echo statusChip($offer_status); ?>
                                    <?php if ($department_val !== ''): ?><span class="chip"><i class="bi bi-diagram-3"></i> <?php echo e($department_val); ?></span><?php endif; ?>
                                    <?php if ($location_val !== ''): ?><span class="chip"><i class="bi bi-geo-alt"></i> <?php echo e($location_val); ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="profile-preview">
                            <div class="preview-stat"><span class="k">Offer Date</span><span class="v"><?php echo showDateVal($offer_date); ?></span></div>
                            <div class="preview-stat"><span class="k">Joining Date</span><span class="v"><?php echo showDateVal($joining_date); ?></span></div>
                            <div class="preview-stat"><span class="k">Annual CTC</span><span class="v"><?php echo formatMoney($salary_annual); ?></span></div>
                            <div class="preview-stat"><span class="k">Monthly Salary</span><span class="v"><?php echo formatMoney($salary_monthly); ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="stats-grid mb-3">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="bi bi-cash-stack"></i></div>
                        <div><div class="stat-label">Annual CTC</div><div class="stat-value"><?php echo formatMoney($salary_annual); ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="bi bi-wallet2"></i></div>
                        <div><div class="stat-label">Monthly Salary</div><div class="stat-value"><?php echo formatMoney($salary_monthly); ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="bi bi-calendar2-event"></i></div>
                        <div><div class="stat-label">Joining Date</div><div class="stat-value"><?php echo showDateVal($joining_date); ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="bi bi-check2-square"></i></div>
                        <div><div class="stat-label">Offer Status</div><div class="stat-value" style="font-size:15px;"><?php echo strip_tags(statusChip($offer_status)); ?></div></div>
                    </div>
                </div>

                <div class="panel">
                    <ul class="nav nav-tabs profile-tabs mb-3" id="offerTabs" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-overview" type="button"><i class="bi bi-grid-1x2"></i> Overview</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-candidate" type="button"><i class="bi bi-person"></i> Candidate</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-compensation" type="button"><i class="bi bi-currency-rupee"></i> Compensation</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-terms" type="button"><i class="bi bi-file-earmark-text"></i> Terms</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-meta" type="button"><i class="bi bi-info-circle"></i> Meta</button></li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="pane-overview" role="tabpanel">
                            <div class="kv">
                                <div class="kv-row"><div class="kv-k">Offer Number</div><div class="kv-v"><?php echo showVal($offer_no); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Candidate Name</div><div class="kv-v"><?php echo showVal($candidate_name); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Designation / Role</div><div class="kv-v"><?php echo showVal($designation_val); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Department</div><div class="kv-v"><?php echo showVal($department_val); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Work Location</div><div class="kv-v"><?php echo showVal($location_val); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Employment Type</div><div class="kv-v"><?php echo showVal($employment_type); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Offer Date</div><div class="kv-v"><?php echo showDateVal($offer_date); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Joining Date</div><div class="kv-v"><?php echo showDateVal($joining_date); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Valid Till</div><div class="kv-v"><?php echo showDateVal($valid_till); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Status</div><div class="kv-v"><?php echo statusChip($offer_status); ?></div></div>
                            </div>

                            <?php if ($onboarding): ?>
                                <div class="offer-box mt-3">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <div class="fw-bold mb-1">Onboarding record available</div>
                                            <div class="text-muted small">Onboarding No: <?php echo showVal(pick_value($onboarding, ['onboarding_no'])); ?> • Joining Date: <?php echo showDateVal(pick_value($onboarding, ['joining_date'])); ?></div>
                                        </div>
                                        <a href="view-onboarding.php?id=<?php echo (int)pick_value($onboarding, ['id'], 0); ?>" class="btn-doc"><i class="bi bi-eye"></i> View Onboarding</a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tab-pane fade" id="pane-candidate" role="tabpanel">
                            <div class="kv">
                                <div class="kv-row"><div class="kv-k">Candidate Code</div><div class="kv-v"><?php echo showVal(pick_value($offer, ['candidate_code'])); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Candidate Name</div><div class="kv-v"><?php echo showVal($candidate_name); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Email</div><div class="kv-v"><?php echo showVal($candidate_email); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Phone</div><div class="kv-v"><?php echo showVal($candidate_phone); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Current Location</div><div class="kv-v"><?php echo showVal($current_location); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Hiring Request ID</div><div class="kv-v"><?php echo showVal(pick_value($offer, ['hiring_request_id'])); ?></div></div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="pane-compensation" role="tabpanel">
                            <div class="kv">
                                <div class="kv-row"><div class="kv-k">Annual CTC</div><div class="kv-v"><?php echo formatMoney($salary_annual); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Monthly Salary</div><div class="kv-v"><?php echo formatMoney($salary_monthly); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Basic Salary</div><div class="kv-v"><?php echo formatMoney($basic_salary); ?></div></div>
                                <div class="kv-row"><div class="kv-k">HRA</div><div class="kv-v"><?php echo formatMoney($hra); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Special Allowance</div><div class="kv-v"><?php echo formatMoney($special_allowance); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Bonus / Joining Bonus</div><div class="kv-v"><?php echo formatMoney($bonus); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Probation Period</div><div class="kv-v"><?php echo showVal($probation_period); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Reporting To</div><div class="kv-v"><?php echo showVal($reporting_to); ?></div></div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="pane-terms" role="tabpanel">
                            <?php if ($terms !== ''): ?>
                                <div class="text-note mb-3"><?php echo nl2br(e($terms)); ?></div>
                            <?php endif; ?>

                            <?php if ($remarks !== ''): ?>
                                <div class="text-note"><strong>Remarks / Notes</strong><br><?php echo nl2br(e($remarks)); ?></div>
                            <?php endif; ?>

                            <?php if ($terms === '' && $remarks === ''): ?>
                                <div class="kv">
                                    <div class="kv-row"><div class="kv-k">Terms & Notes</div><div class="kv-v">No terms, conditions, or remarks available for this offer.</div></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tab-pane fade" id="pane-meta" role="tabpanel">
                            <div class="kv">
                                <div class="kv-row"><div class="kv-k">Offer ID</div><div class="kv-v"><?php echo (int)$offer_id; ?></div></div>
                                <div class="kv-row"><div class="kv-k">Created By</div><div class="kv-v"><?php echo showVal($created_by_name); ?><?php if ($created_by_code !== ''): ?> (<?php echo e($created_by_code); ?>)<?php endif; ?></div></div>
                                <div class="kv-row"><div class="kv-k">Created At</div><div class="kv-v"><?php echo showDateTimeVal($created_at); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Last Updated</div><div class="kv-v"><?php echo showDateTimeVal($updated_at); ?></div></div>
                                <div class="kv-row"><div class="kv-k">Document</div><div class="kv-v"><?php if ($documentUrl !== ''): ?><a href="<?php echo e($documentUrl); ?>" target="_blank" rel="noopener">Open uploaded offer file</a><?php else: ?>No attached offer file<?php endif; ?></div></div>
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
</body>
</html>
