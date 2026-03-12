<?php
// view-employee.php (TEK-C style) - shows employee profile photo from uploads/employees/photos
session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$emp = null;
$error = '';
$success = '';

// ---------- Helpers ----------
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

function initials($name){
  $name = trim((string)$name);
  if ($name === '') return 'E';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'E', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return ($last && count($parts) > 1) ? ($first.$last) : $first;
}

function fileLinkBtn($path, $label = 'View / Download'){
  $path = trim((string)$path);
  if ($path === '') return '—';
  $safe = e($path);
  return '<a class="btn btn-sm btn-outline-primary" href="'.$safe.'" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-arrow-down"></i> '.e($label).'
          </a>';
}

/**
 * ✅ Returns browser URL only if file exists in server
 * Stored value example:
 * uploads/employees/photos/photo_6980f8715a1070.87726641_1770059889.png
 */
function resolveEmployeePhoto($photoPath){
  $photoPath = trim((string)$photoPath);
  if ($photoPath === '') return '';

  // Normalize
  $photoPath = ltrim($photoPath, '/');

  // Check file exists physically
  $absPath = __DIR__ . '/' . $photoPath;
  if (!file_exists($absPath)) return '';

  // Return web path
  return $photoPath;
}

// ---------- Fetch employee ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  $error = "Invalid employee ID.";
} else {
  $stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? LIMIT 1");
  if (!$stmt) {
    $error = "Database error: " . mysqli_error($conn);
  } else {
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $emp = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$emp) $error = "Employee not found.";
  }
}

$empPhoto = $emp ? resolveEmployeePhoto($emp['photo'] ?? '') : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>View Employee - TEK-C</title>

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
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{
      background:#fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding: 18px;
      margin-bottom: 16px;
    }

    .btn-back{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 14px;
      color:#111827;
      font-weight: 900;
      display:inline-flex;
      align-items:center;
      gap:8px;
      text-decoration:none;
    }
    .btn-back:hover{ background:#f9fafb; color: var(--blue); border-color: rgba(45,156,219,.25); }

    .btn-edit{
      background: var(--blue);
      color:#fff;
      border:none;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 900;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
      text-decoration:none;
    }
    .btn-edit:hover{ background:#2a8bc9; color:#fff; }

    .hero{
      display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;
    }
    .hero-left{ display:flex; gap:14px; align-items:center; min-width:260px; }

    /* ✅ Unique class name to avoid topbar conflict */
    .emp-avatar{
      width:54px;height:54px;border-radius: 16px;
      background: linear-gradient(135deg, rgba(45,156,219,.95), rgba(99,102,241,.95));
      color:#fff; display:flex; align-items:center; justify-content:center;
      font-weight:1000; letter-spacing:.5px; flex:0 0 auto;
      overflow:hidden;
    }
    .emp-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }

    .hero-title{ margin:0; font-weight:1000; color:#111827; font-size:18px; line-height:1.2; }
    .hero-sub{ margin:4px 0 0; color:#6b7280; font-weight:700; font-size:13px; }

    .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      font-size:12px; font-weight:900;
      border:1px solid #e5e7eb;
      background:#f9fafb;
      color:#111827;
      white-space:nowrap;
    }
    .chip-primary{
      border-color: rgba(45,156,219,.22);
      background: rgba(45,156,219,.08);
      color: var(--blue);
    }

    .section-card{
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      overflow:hidden;
      background:#fff;
      margin-bottom: 14px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
    }
    .section-head{
      padding: 14px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      background:#f9fafb;
      border-bottom: 1px solid #eef2f7;
    }
    .section-head .left{ display:flex; align-items:center; gap:10px; }
    .sec-ic{
      width:36px;height:36px;border-radius: 12px;
      display:flex; align-items:center; justify-content:center;
      background: rgba(45,156,219,.12);
      color: var(--blue);
      font-size: 16px;
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:700; font-size:12px; }

    .kv-row{
      display:grid;
      grid-template-columns: 260px 1fr;
      gap: 12px;
      padding: 12px 16px;
      border-bottom: 1px solid #eef2f7;
      align-items:start;
    }
    .kv-row:last-child{ border-bottom:none; }
    .kv-k{
      color:#6b7280;
      font-weight:900;
      font-size:12px;
      text-transform: uppercase;
      letter-spacing:.35px;
    }
    .kv-v{
      text-align:right;
      color:#111827;
      font-weight:800;
      font-size:13px;
      word-break: break-word;
    }

    .qa a{
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 10px;
      border-radius: 12px;
      border:1px solid #e5e7eb;
      background:#fff;
      font-weight:900;
      color:#111827;
      margin-left:8px;
      margin-bottom:8px;
    }
    .qa a:hover{ background:#f9fafb; color: var(--blue); border-color: rgba(45,156,219,.25); }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .kv-row{ grid-template-columns: 160px 1fr; }
      .kv-v{ text-align:left; }
      .qa a{ margin-left:0; margin-right:8px; }
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

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">View Employee</h1>
            <p class="text-muted mb-0">Employee profile, role, documents and banking</p>
          </div>
          <div class="d-flex gap-2">
            <a href="manage-employees.php" class="btn-back">
              <i class="bi bi-arrow-left"></i> Back
            </a>
            <?php if ($emp): ?>
              <a href="edit-employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn-edit">
                <i class="bi bi-pencil"></i> Edit
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($emp): ?>

          <!-- Summary -->
          <div class="panel">
            <div class="hero">
              <div class="hero-left">

                <div class="emp-avatar">
                  <?php if (!empty($empPhoto)): ?>
                    <img src="<?php echo e($empPhoto); ?>" alt="<?php echo e($emp['full_name']); ?>">
                  <?php else: ?>
                    <?php echo e(initials($emp['full_name'] ?? 'Employee')); ?>
                  <?php endif; ?>
                </div>

                <div>
                  <p class="hero-title"><?php echo showVal($emp['full_name']); ?></p>
                  <p class="hero-sub">
                    Code: <?php echo showVal($emp['employee_code']); ?>
                    <?php if (!empty($emp['designation'])): ?> • <?php echo e($emp['designation']); ?><?php endif; ?>
                    <?php if (!empty($emp['department'])): ?> • <?php echo e($emp['department']); ?><?php endif; ?>
                  </p>

                  <div class="d-flex flex-wrap gap-2 mt-2">
                    <?php if (!empty($emp['employee_status'])): ?>
                      <span class="chip chip-primary"><i class="bi bi-activity"></i> <?php echo e(ucfirst($emp['employee_status'])); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($emp['work_location'])): ?>
                      <span class="chip"><i class="bi bi-geo-alt"></i> <?php echo e($emp['work_location']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($emp['site_name'])): ?>
                      <span class="chip"><i class="bi bi-building"></i> <?php echo e($emp['site_name']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="qa">
                <?php if (!empty($emp['email'])): ?>
                  <a href="mailto:<?php echo e($emp['email']); ?>"><i class="bi bi-envelope"></i> Email</a>
                <?php endif; ?>
                <?php if (!empty($emp['mobile_number'])): ?>
                  <a href="tel:<?php echo e($emp['mobile_number']); ?>"><i class="bi bi-telephone"></i> Call</a>
                <?php endif; ?>
                <?php if (!empty($emp['passbook_photo'])): ?>
                  <a href="<?php echo e($emp['passbook_photo']); ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-arrow-down"></i> Passbook</a>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Details -->
          <div class="section-card">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-person"></i></div>
                <div>
                  <p class="sec-title mb-0">Basic Information</p>
                  <p class="sec-sub">Personal details and contact</p>
                </div>
              </div>
            </div>

            <div class="kv">
              <div class="kv-row"><div class="kv-k">Full Name</div><div class="kv-v"><?php echo showVal($emp['full_name']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Employee Code</div><div class="kv-v"><?php echo showVal($emp['employee_code']); ?></div></div>
              <div class="kv-row"><div class="kv-k">DOB</div><div class="kv-v"><?php echo showDateVal($emp['date_of_birth']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Mobile</div><div class="kv-v"><?php echo showVal($emp['mobile_number']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Email</div><div class="kv-v"><?php echo showVal($emp['email']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Address</div><div class="kv-v"><?php echo showVal($emp['current_address']); ?></div></div>
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
if (isset($conn)) { mysqli_close($conn); }
?>
