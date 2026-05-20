<?php
// my-profile.php
// TEK-C table section page reference UI style
// Shows logged-in employee profile with compact cards and table-section layout
// Fetch photo + files from ../admin/uploads/... normalized

session_start();

require_once 'includes/db-config.php';

$conn = get_db_connection();

if (!$conn) {
    die("Database connection failed.");
}

/* ---------------- AUTH ---------------- */

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int)$_SESSION['employee_id'];

/* ---------------- HELPERS ---------------- */

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function showVal($v, $dash = '—') {
    $v = trim((string)$v);
    return $v === '' ? $dash : e($v);
}

function showDateVal($v, $dash = '—') {
    $v = trim((string)$v);

    if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
        return $dash;
    }

    $ts = strtotime($v);

    return $ts ? date('d M Y', $ts) : e($v);
}

function initials($name) {
    $name = trim((string)$name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name);

    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last  = strtoupper(substr(end($parts) ?: '', 0, 1));

    return count($parts) > 1 ? $first . $last : $first;
}

function statusClass($status) {
    $status = strtolower(trim((string)$status));

    if ($status === 'active') {
        return 'ontrack';
    }

    if ($status === 'resigned') {
        return 'danger';
    }

    return 'warning';
}

/**
 * Normalize file path to correct URL.
 * This page is inside hr/, so admin upload files are loaded from ../admin/uploads/...
 */
function fileUrl($path) {
    $p = trim((string)$path);

    if ($p === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $p)) {
        return $p;
    }

    if (stripos($p, '../admin/uploads/') === 0) {
        return $p;
    }

    if (stripos($p, 'admin/uploads/') === 0) {
        return '../' . $p;
    }

    if (stripos($p, '/admin/uploads/') === 0) {
        return '..' . $p;
    }

    if (stripos($p, 'uploads/') === 0) {
        return '../admin/' . $p;
    }

    if (stripos($p, '/uploads/') === 0) {
        return '../admin' . $p;
    }

    if (stripos($p, 'employees/') === 0) {
        return '../admin/uploads/' . $p;
    }

    if (stripos($p, '/employees/') === 0) {
        return '../admin/uploads' . $p;
    }

    return '../admin/uploads/' . ltrim($p, '/');
}

/**
 * Avoid broken file links.
 * If your local setup blocks file_exists checks, return $u directly.
 */
function existingFileUrl($url) {
    $u = trim((string)$url);

    if ($u === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $u)) {
        return $u;
    }

    $abs1 = __DIR__ . '/' . $u;

    if (file_exists($abs1)) {
        return $u;
    }

    $abs2 = realpath($abs1);

    if ($abs2 && file_exists($abs2)) {
        return $u;
    }

    return '';
}

/* ---------------- FETCH EMPLOYEE ---------------- */

$error = '';
$emp = null;

$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM employees
     WHERE id = ?
     LIMIT 1"
);

if (!$stmt) {

    $error = "Database error: " . mysqli_error($conn);

} else {

    mysqli_stmt_bind_param($stmt, "i", $employeeId);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    $emp = mysqli_fetch_assoc($res);

    mysqli_stmt_close($stmt);

    if (!$emp) {
        $error = "Employee not found.";
    }
}

$photoUrl = $emp ? existingFileUrl(fileUrl($emp['photo'] ?? '')) : '';
$passbookUrl = $emp ? existingFileUrl(fileUrl($emp['passbook_photo'] ?? '')) : '';

$profileCompletionItems = [];

if ($emp) {
    $profileCompletionItems = [
        'Full Name' => !empty($emp['full_name']),
        'Employee Code' => !empty($emp['employee_code']),
        'Mobile' => !empty($emp['mobile_number']),
        'Email' => !empty($emp['email']),
        'Department' => !empty($emp['department']),
        'Designation' => !empty($emp['designation']),
        'Date of Joining' => !empty($emp['date_of_joining']) && $emp['date_of_joining'] !== '0000-00-00',
        'Aadhaar' => !empty($emp['aadhar_card_number']),
        'PAN' => !empty($emp['pancard_number']),
        'Bank Account' => !empty($emp['bank_account_number'])
    ];
}

$completedProfileItems = 0;

foreach ($profileCompletionItems as $done) {
    if ($done) {
        $completedProfileItems++;
    }
}

$totalProfileItems = count($profileCompletionItems);
$profilePercent = $totalProfileItems > 0 ? round(($completedProfileItems / $totalProfileItems) * 100) : 0;

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8" />

<meta
name="viewport"
content="width=device-width, initial-scale=1"
/>

<title>My Profile - TEK-C</title>

<link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
<link rel="manifest" href="assets/fav/site.webmanifest">

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet"
/>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
rel="stylesheet"
/>

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
}

body{
    background:var(--page-bg);
}

.content-scroll{
    flex:1 1 auto;
    overflow:auto;
    padding:16px;
}

.profile-wrapper{
    width:100%;
}

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

.primary-btn{
    border:0;
    background:#111827;
    color:#fff;
    height:36px;
    padding:0 14px;
    border-radius:11px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    gap:7px;
    text-decoration:none;
    white-space:nowrap;
}

.primary-btn:hover{
    background:#020617;
    color:#fff;
}

.back-btn{
    background:#ffffff;
    color:#475569;
    border:1px solid var(--border);
}

.back-btn:hover{
    background:#f8fafc;
    color:#111827;
}

.edit-btn{
    background:#2f80ed;
}

.edit-btn:hover{
    background:#2563eb;
}

.doc-btn{
    background:#10b981;
}

.doc-btn:hover{
    background:#059669;
}

.profile-hero{
    background:linear-gradient(135deg,#2563eb,#7c3aed);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:16px;
    color:#fff;
    margin-bottom:14px;
}

.hero-content{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    flex-wrap:wrap;
}

.hero-left{
    display:flex;
    align-items:center;
    gap:14px;
    min-width:260px;
}

.emp-avatar{
    width:66px;
    height:66px;
    border-radius:18px;
    background:rgba(255,255,255,.18);
    color:#fff;
    display:grid;
    place-items:center;
    font-size:19px;
    font-weight:950;
    overflow:hidden;
    border:3px solid rgba(255,255,255,.55);
    flex:0 0 auto;
}

.emp-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.hero-name{
    font-size:20px;
    font-weight:950;
    line-height:1.15;
    margin:0;
}

.hero-meta{
    font-size:12px;
    font-weight:750;
    opacity:.9;
    margin-top:4px;
}

.hero-chips{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
    margin-top:9px;
}

.hero-chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.24);
    color:#fff;
    border-radius:999px;
    padding:5px 9px;
    font-size:10.5px;
    font-weight:900;
}

.hero-actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.hero-action{
    height:34px;
    border-radius:10px;
    border:1px solid rgba(255,255,255,.28);
    background:rgba(255,255,255,.14);
    color:#fff;
    padding:0 11px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    text-decoration:none;
    font-size:11px;
    font-weight:900;
}

.hero-action:hover{
    background:rgba(255,255,255,.22);
    color:#fff;
}

.stat-card{
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:12px 13px;
    min-height:78px;
    display:flex;
    align-items:center;
    gap:11px;
}

.stat-ic{
    width:38px;
    height:38px;
    border-radius:12px;
    display:grid;
    place-items:center;
    color:#fff;
    font-size:17px;
}

.blue{ background:#2f80ed; }
.green{ background:#27ae60; }
.orange{ background:#f2994a; }
.purple{ background:#8e44ad; }

.stat-label{
    color:var(--muted);
    font-weight:800;
    font-size:10.5px;
    text-transform:uppercase;
}

.stat-value{
    font-size:21px;
    font-weight:950;
    line-height:1;
    margin-top:2px;
}

.panel{
    background:var(--card-bg);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:13px;
    margin-bottom:14px;
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
    margin:0;
    color:#111827;
}

.panel-subtitle{
    color:var(--muted);
    font-size:11px;
    font-weight:700;
    margin-top:2px;
}

.badge-pill{
    border-radius:999px;
    padding:5px 8px;
    font-weight:900;
    font-size:10px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    white-space:nowrap;
}

.mini-dot{
    width:6px;
    height:6px;
    border-radius:50%;
    background:currentColor;
}

.ontrack{ color:#15803d; background:#dcfce7; }
.warning{ color:#b45309; background:#fef3c7; }
.danger{ color:#b91c1c; background:#fee2e2; }
.info{ color:#2563eb; background:#dbeafe; }
.muted{ color:#475569; background:#f1f5f9; }

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
    padding:9px 10px;
    vertical-align:middle;
    border-color:#eef2f7;
    color:#334155;
    font-weight:700;
    font-size:11.5px;
}

.compact-table tbody tr:hover{
    background:#fbfdff;
}

.field-label{
    display:flex;
    align-items:center;
    gap:7px;
    color:#64748b;
    font-size:10.5px;
    font-weight:900;
    text-transform:uppercase;
}

.field-value{
    color:#111827;
    font-size:12px;
    font-weight:850;
    text-align:right;
    word-break:break-word;
}

.field-value a{
    color:#2563eb;
    font-weight:900;
    text-decoration:none;
}

.section-tabs{
    border:0;
    margin-bottom:12px;
    gap:8px;
    overflow:auto;
    flex-wrap:nowrap;
}

.section-tabs .nav-link{
    border:1px solid var(--border);
    background:#fff;
    color:#64748b;
    border-radius:11px;
    font-weight:900;
    font-size:12px;
    padding:8px 12px;
    white-space:nowrap;
    display:flex;
    align-items:center;
    gap:6px;
}

.section-tabs .nav-link.active{
    background:#111827;
    color:#fff;
    border-color:#111827;
}

.progress-mini{
    height:8px;
    background:rgba(255,255,255,.25);
    border-radius:999px;
    overflow:hidden;
    min-width:150px;
    margin-top:8px;
}

.progress-mini-bar{
    height:100%;
    background:#fff;
    border-radius:999px;
}

.document-card{
    border:1px solid var(--border);
    background:#fff;
    border-radius:14px;
    padding:13px;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.document-left{
    display:flex;
    align-items:center;
    gap:10px;
}

.document-icon{
    width:38px;
    height:38px;
    border-radius:12px;
    display:grid;
    place-items:center;
    background:#eff6ff;
    color:#2563eb;
    font-size:18px;
    flex:0 0 auto;
}

.document-title{
    font-size:12px;
    font-weight:950;
    color:#111827;
}

.document-subtitle{
    color:#64748b;
    font-size:10.5px;
    font-weight:700;
    margin-top:2px;
}

.document-link{
    width:30px;
    height:30px;
    border-radius:10px;
    display:grid;
    place-items:center;
    background:#dcfce7;
    color:#15803d;
    text-decoration:none;
    border:1px solid rgba(22,163,74,.12);
}

.document-empty{
    width:30px;
    height:30px;
    border-radius:10px;
    display:grid;
    place-items:center;
    background:#f1f5f9;
    color:#94a3b8;
    border:1px solid var(--border);
}

.alert{
    border-radius:var(--radius);
    border:none;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    font-size:12px;
    font-weight:700;
}

@media(max-width:1199px){

    .compact-table thead{
        display:none;
    }

    .compact-table,
    .compact-table tbody,
    .compact-table tr,
    .compact-table td{
        display:block;
        width:100%;
    }

    .compact-table tbody tr{
        border-bottom:1px solid var(--border);
        padding:9px 10px;
    }

    .compact-table tbody td{
        border:0;
        display:flex;
        justify-content:space-between;
        gap:12px;
        padding:7px 0;
    }

    .compact-table tbody td::before{
        content:attr(data-label);
        font-size:10px;
        font-weight:900;
        color:#64748b;
        text-transform:uppercase;
        flex:0 0 110px;
    }

    .field-value{
        text-align:right;
    }

    .page-heading{
        align-items:flex-start;
        flex-direction:column;
    }
}

@media(max-width:768px){

    .content-scroll{
        padding:12px 10px 12px!important;
    }

    .panel{
        padding:12px!important;
        border-radius:14px;
    }

    .profile-hero{
        padding:14px;
    }

    .hero-content{
        align-items:flex-start;
        flex-direction:column;
    }

    .hero-left{
        align-items:flex-start;
    }

    .emp-avatar{
        width:58px;
        height:58px;
    }

    .field-value{
        text-align:left;
    }
}

</style>

</head>

<body>

<div class="app">

<?php include 'includes/sidebar.php'; ?>

<main class="main" aria-label="Main">

<?php include 'includes/topbar.php'; ?>

<div
id="contentScroll"
class="content-scroll"
>

<div class="container-fluid profile-wrapper px-0">

<!-- PAGE HEADER -->

<div class="page-heading">

<div>

<h1>
My Profile
</h1>

<p>
View your profile, employment, KYC, bank and document details
</p>

</div>

<div class="d-flex gap-2 flex-wrap">

<a
href="index.php"
class="primary-btn back-btn"
>
<i class="bi bi-arrow-left"></i>
Back
</a>

<?php if ($emp): ?>

<a
href="edit-employee.php?id=<?php echo (int)$employeeId; ?>"
class="primary-btn edit-btn"
>
<i class="bi bi-pencil"></i>
Edit Profile
</a>

<?php endif; ?>

<?php if (!empty($passbookUrl)): ?>

<a
href="<?php echo e($passbookUrl); ?>"
target="_blank"
rel="noopener"
class="primary-btn doc-btn"
>
<i class="bi bi-file-earmark-arrow-down"></i>
Passbook
</a>

<?php endif; ?>

</div>

</div>

<?php if (!empty($error)): ?>

<div class="alert alert-danger alert-dismissible fade show" role="alert">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

<?php echo e($error); ?>

<button
type="button"
class="btn-close"
data-bs-dismiss="alert"
></button>

</div>

<?php endif; ?>

<?php if ($emp): ?>

<!-- HERO -->

<div class="profile-hero">

<div class="hero-content">

<div class="hero-left">

<div class="emp-avatar">

<?php if (!empty($photoUrl)): ?>

<img
src="<?php echo e($photoUrl); ?>"
alt="<?php echo e($emp['full_name'] ?? ''); ?>"
onerror="this.style.display='none'; this.parentNode.innerHTML='<?php echo e(initials($emp['full_name'] ?? 'User')); ?>';"
>

<?php else: ?>

<?php echo e(initials($emp['full_name'] ?? 'User')); ?>

<?php endif; ?>

</div>

<div>

<h2 class="hero-name">
<?php echo showVal($emp['full_name']); ?>
</h2>

<div class="hero-meta">

<i class="bi bi-hash"></i>
<?php echo showVal($emp['employee_code']); ?>

<?php if (!empty($emp['designation'])): ?>
•
<?php echo e($emp['designation']); ?>
<?php endif; ?>

<?php if (!empty($emp['department'])): ?>
•
<?php echo e($emp['department']); ?>
<?php endif; ?>

</div>

<div class="hero-chips">

<?php if (!empty($emp['employee_status'])): ?>

<span class="hero-chip">

<i class="bi bi-activity"></i>

<?php echo e(ucfirst($emp['employee_status'])); ?>

</span>

<?php endif; ?>

<?php if (!empty($emp['work_location'])): ?>

<span class="hero-chip">

<i class="bi bi-geo-alt"></i>

<?php echo e($emp['work_location']); ?>

</span>

<?php endif; ?>

<?php if (!empty($emp['site_name'])): ?>

<span class="hero-chip">

<i class="bi bi-building"></i>

<?php echo e($emp['site_name']); ?>

</span>

<?php endif; ?>

</div>

<div class="progress-mini">

<div
class="progress-mini-bar"
style="width: <?php echo (int)$profilePercent; ?>%;"
></div>

</div>

<div class="hero-meta mt-1">

Profile completion:
<?php echo (int)$profilePercent; ?>%

</div>

</div>

</div>

<div class="hero-actions">

<?php if (!empty($emp['email'])): ?>

<a
class="hero-action"
href="mailto:<?php echo e($emp['email']); ?>"
>
<i class="bi bi-envelope"></i>
Email
</a>

<?php endif; ?>

<?php if (!empty($emp['mobile_number'])): ?>

<a
class="hero-action"
href="tel:<?php echo e($emp['mobile_number']); ?>"
>
<i class="bi bi-telephone"></i>
Call
</a>

<?php endif; ?>

<?php if (!empty($photoUrl)): ?>

<a
class="hero-action"
href="<?php echo e($photoUrl); ?>"
target="_blank"
rel="noopener"
>
<i class="bi bi-image"></i>
Photo
</a>

<?php endif; ?>

</div>

</div>

</div>

<!-- STATS -->

<div class="row g-3 mb-3">

<div class="col-6 col-md-3">

<div class="stat-card">

<div class="stat-ic blue">
<i class="bi bi-person-badge"></i>
</div>

<div>

<div class="stat-label">
Employee Code
</div>

<div class="stat-value" style="font-size:16px;">
<?php echo showVal($emp['employee_code']); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-3">

<div class="stat-card">

<div class="stat-ic green">
<i class="bi bi-activity"></i>
</div>

<div>

<div class="stat-label">
Status
</div>

<div class="stat-value" style="font-size:16px;">
<?php echo showVal(ucfirst($emp['employee_status'] ?? '')); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-3">

<div class="stat-card">

<div class="stat-ic orange">
<i class="bi bi-calendar-check"></i>
</div>

<div>

<div class="stat-label">
Joined
</div>

<div class="stat-value" style="font-size:16px;">
<?php echo showDateVal($emp['date_of_joining'] ?? ''); ?>
</div>

</div>

</div>

</div>

<div class="col-6 col-md-3">

<div class="stat-card">

<div class="stat-ic purple">
<i class="bi bi-graph-up"></i>
</div>

<div>

<div class="stat-label">
Completion
</div>

<div class="stat-value">
<?php echo (int)$profilePercent; ?>%
</div>

</div>

</div>

</div>

</div>

<!-- TABS -->

<ul
class="nav section-tabs"
id="profileTabs"
role="tablist"
>

<li class="nav-item" role="presentation">

<button
class="nav-link active"
id="overview-tab"
data-bs-toggle="tab"
data-bs-target="#overviewPane"
type="button"
role="tab"
>
<i class="bi bi-grid-1x2"></i>
Overview
</button>

</li>

<li class="nav-item" role="presentation">

<button
class="nav-link"
id="personal-tab"
data-bs-toggle="tab"
data-bs-target="#personalPane"
type="button"
role="tab"
>
<i class="bi bi-person"></i>
Personal
</button>

</li>

<li class="nav-item" role="presentation">

<button
class="nav-link"
id="employment-tab"
data-bs-toggle="tab"
data-bs-target="#employmentPane"
type="button"
role="tab"
>
<i class="bi bi-briefcase"></i>
Employment
</button>

</li>

<li class="nav-item" role="presentation">

<button
class="nav-link"
id="kyc-tab"
data-bs-toggle="tab"
data-bs-target="#kycPane"
type="button"
role="tab"
>
<i class="bi bi-credit-card-2-front"></i>
KYC & Bank
</button>

</li>

<li class="nav-item" role="presentation">

<button
class="nav-link"
id="documents-tab"
data-bs-toggle="tab"
data-bs-target="#documentsPane"
type="button"
role="tab"
>
<i class="bi bi-folder2-open"></i>
Documents
</button>

</li>

</ul>

<div
class="tab-content"
id="profileTabsContent"
>

<!-- OVERVIEW -->

<div
class="tab-pane fade show active"
id="overviewPane"
role="tabpanel"
>

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Profile Overview
</h3>

<div class="panel-subtitle">
Compact profile summary
</div>

</div>

<span class="badge-pill <?php echo e(statusClass($emp['employee_status'] ?? '')); ?>">

<span class="mini-dot"></span>

<?php echo showVal(ucfirst($emp['employee_status'] ?? '')); ?>

</span>

</div>

<div class="compact-table-wrap">

<table class="table compact-table align-middle">

<thead>

<tr>
<th>Field</th>
<th class="text-end">Details</th>
</tr>

</thead>

<tbody>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-hash"></i> Employee Code</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['employee_code']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-person"></i> Full Name</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['full_name']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-briefcase"></i> Designation</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['designation']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-building"></i> Department</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['department']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-calendar"></i> Date of Joining</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showDateVal($emp['date_of_joining']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-geo-alt"></i> Work Location</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['work_location']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-person-badge"></i> Reporting Manager</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['reporting_manager']); ?></div>
</td>
</tr>

</tbody>

</table>

</div>

</div>

</div>

<!-- PERSONAL -->

<div
class="tab-pane fade"
id="personalPane"
role="tabpanel"
>

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Personal Information
</h3>

<div class="panel-subtitle">
Contact, identity and emergency details
</div>

</div>

</div>

<div class="compact-table-wrap">

<table class="table compact-table align-middle">

<thead>

<tr>
<th>Field</th>
<th class="text-end">Details</th>
</tr>

</thead>

<tbody>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-person"></i> Full Name</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['full_name']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-calendar-heart"></i> Date of Birth</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showDateVal($emp['date_of_birth']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-gender-ambiguous"></i> Gender</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['gender']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-droplet"></i> Blood Group</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['blood_group']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-telephone"></i> Mobile Number</div>
</td>
<td data-label="Details">
<div class="field-value">
<?php if (!empty($emp['mobile_number'])): ?>
<a href="tel:<?php echo e($emp['mobile_number']); ?>"><?php echo e($emp['mobile_number']); ?></a>
<?php else: ?>
—
<?php endif; ?>
</div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-envelope"></i> Email</div>
</td>
<td data-label="Details">
<div class="field-value">
<?php if (!empty($emp['email'])): ?>
<a href="mailto:<?php echo e($emp['email']); ?>"><?php echo e($emp['email']); ?></a>
<?php else: ?>
—
<?php endif; ?>
</div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-geo"></i> Current Address</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['current_address']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-life-preserver"></i> Emergency Contact Name</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['emergency_contact_name']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-telephone-plus"></i> Emergency Contact Phone</div>
</td>
<td data-label="Details">
<div class="field-value">
<?php if (!empty($emp['emergency_contact_phone'])): ?>
<a href="tel:<?php echo e($emp['emergency_contact_phone']); ?>"><?php echo e($emp['emergency_contact_phone']); ?></a>
<?php else: ?>
—
<?php endif; ?>
</div>
</td>
</tr>

</tbody>

</table>

</div>

</div>

</div>

<!-- EMPLOYMENT -->

<div
class="tab-pane fade"
id="employmentPane"
role="tabpanel"
>

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Employment Details
</h3>

<div class="panel-subtitle">
Department, role, reporting and location
</div>

</div>

<span class="badge-pill info">

<span class="mini-dot"></span>

<?php echo showVal($emp['department']); ?>

</span>

</div>

<div class="compact-table-wrap">

<table class="table compact-table align-middle">

<thead>

<tr>
<th>Field</th>
<th class="text-end">Details</th>
</tr>

</thead>

<tbody>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-building"></i> Department</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['department']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-briefcase"></i> Designation</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['designation']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-calendar-check"></i> Date of Joining</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showDateVal($emp['date_of_joining']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-person-badge"></i> Reporting Manager</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['reporting_manager']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-geo-alt"></i> Work Location</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['work_location']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-buildings"></i> Site Name</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['site_name']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-activity"></i> Employee Status</div>
</td>
<td data-label="Details">
<div class="field-value">

<span class="badge-pill <?php echo e(statusClass($emp['employee_status'] ?? '')); ?>">

<span class="mini-dot"></span>

<?php echo showVal(ucfirst($emp['employee_status'] ?? '')); ?>

</span>

</div>
</td>
</tr>

</tbody>

</table>

</div>

</div>

</div>

<!-- KYC -->

<div
class="tab-pane fade"
id="kycPane"
role="tabpanel"
>

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
KYC & Bank Details
</h3>

<div class="panel-subtitle">
Identity and banking information
</div>

</div>

</div>

<div class="compact-table-wrap">

<table class="table compact-table align-middle">

<thead>

<tr>
<th>Field</th>
<th class="text-end">Details</th>
</tr>

</thead>

<tbody>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-credit-card-2-front"></i> Aadhaar Number</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['aadhar_card_number']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-credit-card"></i> PAN Number</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['pancard_number']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-bank"></i> Bank Account</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['bank_account_number']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-upc-scan"></i> IFSC Code</div>
</td>
<td data-label="Details">
<div class="field-value"><?php echo showVal($emp['ifsc_code']); ?></div>
</td>
</tr>

<tr>
<td data-label="Field">
<div class="field-label"><i class="bi bi-file-earmark-arrow-down"></i> Passbook File</div>
</td>
<td data-label="Details">
<div class="field-value">
<?php if (!empty($passbookUrl)): ?>
<a href="<?php echo e($passbookUrl); ?>" target="_blank" rel="noopener">View / Download</a>
<?php else: ?>
—
<?php endif; ?>
</div>
</td>
</tr>

</tbody>

</table>

</div>

</div>

</div>

<!-- DOCUMENTS -->

<div
class="tab-pane fade"
id="documentsPane"
role="tabpanel"
>

<div class="panel">

<div class="panel-header">

<div>

<h3 class="panel-title">
Documents
</h3>

<div class="panel-subtitle">
Profile and uploaded document files
</div>

</div>

</div>

<div class="row g-3">

<div class="col-12 col-md-6">

<div class="document-card">

<div class="document-left">

<div class="document-icon">
<i class="bi bi-image"></i>
</div>

<div>

<div class="document-title">
Profile Photo
</div>

<div class="document-subtitle">
Employee profile image
</div>

</div>

</div>

<?php if (!empty($photoUrl)): ?>

<a
href="<?php echo e($photoUrl); ?>"
target="_blank"
rel="noopener"
class="document-link"
title="View Photo"
>
<i class="bi bi-eye"></i>
</a>

<?php else: ?>

<span class="document-empty">
<i class="bi bi-dash"></i>
</span>

<?php endif; ?>

</div>

</div>

<div class="col-12 col-md-6">

<div class="document-card">

<div class="document-left">

<div class="document-icon">
<i class="bi bi-file-earmark-arrow-down"></i>
</div>

<div>

<div class="document-title">
Passbook File
</div>

<div class="document-subtitle">
Bank passbook or account document
</div>

</div>

</div>

<?php if (!empty($passbookUrl)): ?>

<a
href="<?php echo e($passbookUrl); ?>"
target="_blank"
rel="noopener"
class="document-link"
title="View / Download"
>
<i class="bi bi-download"></i>
</a>

<?php else: ?>

<span class="document-empty">
<i class="bi bi-dash"></i>
</span>

<?php endif; ?>

</div>

</div>

</div>

</div>

</div>

</div>

<?php endif; ?>

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
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>