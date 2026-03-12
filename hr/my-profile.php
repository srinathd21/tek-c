<?php
// my-profile.php (TAB STYLE) — TEK-C style
// ✅ Shows profile in Bootstrap Tabs (Overview / Personal / Employment / KYC & Bank / Documents)
// ✅ Fetch photo + files from ../admin/uploads/... (normalized)
// ✅ Uses logged-in employee_id

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}
$employeeId = (int)$_SESSION['employee_id'];

// ---------------- HELPERS ----------------
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
  if ($name === '') return 'U';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return (count($parts) > 1) ? ($first.$last) : $first;
}

/**
 * ✅ Normalize file path to correct URL (this page is in hr/)
 * We want: ../admin/uploads/...
 */
function fileUrl($path){
  $p = trim((string)$path);
  if ($p === '') return '';

  if (stripos($p, '../admin/uploads/') === 0) return $p;

  if (stripos($p, 'admin/uploads/') === 0) return '../' . $p;
  if (stripos($p, '/admin/uploads/') === 0) return '..' . $p;

  if (stripos($p, 'uploads/') === 0) return '../admin/' . $p;
  if (stripos($p, '/uploads/') === 0) return '../admin' . $p;

  if (stripos($p, 'employees/') === 0) return '../admin/uploads/' . $p;
  if (stripos($p, '/employees/') === 0) return '../admin/uploads' . $p;

  if (preg_match('~^https?://~i', $p)) return $p;

  return '../admin/uploads/' . ltrim($p, '/');
}

/**
 * ✅ Optional: Avoid broken links by checking file exists.
 * If your server blocks file_exists on some setups, you can return $url directly.
 */
function existingFileUrl($url){
  $u = trim((string)$url);
  if ($u === '') return '';
  if (preg_match('~^https?://~i', $u)) return $u;

  $abs1 = __DIR__ . '/' . $u;
  if (file_exists($abs1)) return $u;

  $abs2 = realpath($abs1);
  if ($abs2 && file_exists($abs2)) return $u;

  return '';
}

// ---------------- FETCH EMPLOYEE ----------------
$error = '';
$emp = null;

$stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? LIMIT 1");
if (!$stmt) {
  $error = "Database error: " . mysqli_error($conn);
} else {
  mysqli_stmt_bind_param($stmt, "i", $employeeId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $emp = mysqli_fetch_assoc($res);
  mysqli_stmt_close($stmt);

  if (!$emp) $error = "Employee not found.";
}

$photoUrl    = $emp ? existingFileUrl(fileUrl($emp['photo'] ?? '')) : '';
$passbookUrl = $emp ? existingFileUrl(fileUrl($emp['passbook_photo'] ?? '')) : '';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>My Profile - TEK-C</title>

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
      white-space:nowrap;
    }
    .btn-edit:hover{ background:#2a8bc9; color:#fff; }

    .btn-doc{
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
      white-space:nowrap;
    }
    .btn-doc:hover{ background:#f9fafb; color: var(--blue); border-color: rgba(45,156,219,.25); }

    .hero{
      display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;
    }
    .hero-left{ display:flex; gap:14px; align-items:center; min-width:260px; }

    .emp-avatar{
      width:62px;height:62px;border-radius: 18px;
      background: linear-gradient(135deg, rgba(45,156,219,.95), rgba(99,102,241,.95));
      color:#fff; display:flex; align-items:center; justify-content:center;
      font-weight:1000; letter-spacing:.5px; flex:0 0 auto;
      overflow:hidden;
      border: 3px solid rgba(255,255,255,.7);
      box-shadow: 0 12px 26px rgba(17,24,39,.10);
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

    /* --- TAB STYLE --- */
    .profile-tabs{
      border-bottom: 1px solid #e5e7eb;
      gap: 8px;
    }
    .profile-tabs .nav-link{
      border: 1px solid #e5e7eb;
      background:#fff;
      color:#374151;
      font-weight: 900;
      border-radius: 12px;
      padding: 10px 12px;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .profile-tabs .nav-link:hover{
      background:#f9fafb;
      color: var(--blue);
      border-color: rgba(45,156,219,.25);
    }
    .profile-tabs .nav-link.active{
      background: rgba(45,156,219,.10);
      color: var(--blue);
      border-color: rgba(45,156,219,.25);
      box-shadow: 0 12px 26px rgba(45,156,219,.10);
    }

    .kv{
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      overflow:hidden;
      background:#fff;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
    }
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

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .kv-row{ grid-template-columns: 160px 1fr; }
      .kv-v{ text-align:left; }
      .profile-tabs{ overflow:auto; flex-wrap:nowrap; }
      .profile-tabs .nav-link{ white-space:nowrap; }
    }
    @media (max-width: 768px) {
  .content-scroll {
    padding: 12px 10px 12px !important;   /* was 22px */
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

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">My Profile</h1>
            <p class="text-muted mb-0">View your profile, documents and banking details</p>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <a href="index.php" class="btn-back">
              <i class="bi bi-arrow-left"></i> Back
            </a>

            <!-- Optional -->
            <a href="edit-employee.php?id=<?php echo (int)$employeeId; ?>" class="btn-edit">
              <i class="bi bi-pencil"></i> Edit Profile
            </a>

            <?php if (!empty($passbookUrl)): ?>
              <a href="<?php echo e($passbookUrl); ?>" target="_blank" rel="noopener" class="btn-doc">
                <i class="bi bi-file-earmark-arrow-down"></i> Passbook
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($error)): ?>
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
                  <?php if (!empty($photoUrl)): ?>
                    <img src="<?php echo e($photoUrl); ?>" alt="<?php echo e($emp['full_name'] ?? ''); ?>">
                  <?php else: ?>
                    <?php echo e(initials($emp['full_name'] ?? 'User')); ?>
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

              <div class="d-flex flex-wrap gap-2">
                <?php if (!empty($emp['email'])): ?>
                  <a class="btn-doc" href="mailto:<?php echo e($emp['email']); ?>"><i class="bi bi-envelope"></i> Email</a>
                <?php endif; ?>
                <?php if (!empty($emp['mobile_number'])): ?>
                  <a class="btn-doc" href="tel:<?php echo e($emp['mobile_number']); ?>"><i class="bi bi-telephone"></i> Call</a>
                <?php endif; ?>
                <?php if (!empty($photoUrl)): ?>
                  <a class="btn-doc" href="<?php echo e($photoUrl); ?>" target="_blank" rel="noopener"><i class="bi bi-image"></i> Photo</a>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Tabs -->
          <ul class="nav nav-tabs profile-tabs mb-3" id="profileTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="tab-overview" data-bs-toggle="tab" data-bs-target="#pane-overview" type="button" role="tab">
                <i class="bi bi-grid-1x2"></i> Overview
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-personal" data-bs-toggle="tab" data-bs-target="#pane-personal" type="button" role="tab">
                <i class="bi bi-person"></i> Personal
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-employment" data-bs-toggle="tab" data-bs-target="#pane-employment" type="button" role="tab">
                <i class="bi bi-briefcase"></i> Employment
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-kyc" data-bs-toggle="tab" data-bs-target="#pane-kyc" type="button" role="tab">
                <i class="bi bi-credit-card-2-front"></i> KYC & Bank
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="tab-docs" data-bs-toggle="tab" data-bs-target="#pane-docs" type="button" role="tab">
                <i class="bi bi-folder2-open"></i> Documents
              </button>
            </li>
          </ul>

          <div class="tab-content" id="profileTabsContent">

            <!-- Overview -->
            <div class="tab-pane fade show active" id="pane-overview" role="tabpanel" aria-labelledby="tab-overview">
              <div class="kv">
                <div class="kv-row"><div class="kv-k">Employee Code</div><div class="kv-v"><?php echo showVal($emp['employee_code']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Status</div><div class="kv-v"><?php echo showVal(ucfirst($emp['employee_status'] ?? '')); ?></div></div>
                <div class="kv-row"><div class="kv-k">Department</div><div class="kv-v"><?php echo showVal($emp['department']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Designation</div><div class="kv-v"><?php echo showVal($emp['designation']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Date of Joining</div><div class="kv-v"><?php echo showDateVal($emp['date_of_joining']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Work Location</div><div class="kv-v"><?php echo showVal($emp['work_location']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Site Name</div><div class="kv-v"><?php echo showVal($emp['site_name']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Reporting Manager</div><div class="kv-v"><?php echo showVal($emp['reporting_manager']); ?></div></div>
              </div>
            </div>

            <!-- Personal -->
            <div class="tab-pane fade" id="pane-personal" role="tabpanel" aria-labelledby="tab-personal">
              <div class="kv">
                <div class="kv-row"><div class="kv-k">Full Name</div><div class="kv-v"><?php echo showVal($emp['full_name']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Date of Birth</div><div class="kv-v"><?php echo showDateVal($emp['date_of_birth']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Gender</div><div class="kv-v"><?php echo showVal($emp['gender']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Blood Group</div><div class="kv-v"><?php echo showVal($emp['blood_group']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Mobile</div><div class="kv-v"><?php echo showVal($emp['mobile_number']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Email</div><div class="kv-v"><?php echo showVal($emp['email']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Current Address</div><div class="kv-v"><?php echo showVal($emp['current_address']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Emergency Contact Name</div><div class="kv-v"><?php echo showVal($emp['emergency_contact_name']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Emergency Contact Phone</div><div class="kv-v"><?php echo showVal($emp['emergency_contact_phone']); ?></div></div>
              </div>
            </div>

            <!-- Employment -->
            <div class="tab-pane fade" id="pane-employment" role="tabpanel" aria-labelledby="tab-employment">
              <div class="kv">
                <div class="kv-row"><div class="kv-k">Department</div><div class="kv-v"><?php echo showVal($emp['department']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Designation</div><div class="kv-v"><?php echo showVal($emp['designation']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Date of Joining</div><div class="kv-v"><?php echo showDateVal($emp['date_of_joining']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Work Location</div><div class="kv-v"><?php echo showVal($emp['work_location']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Site Name</div><div class="kv-v"><?php echo showVal($emp['site_name']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Reporting Manager</div><div class="kv-v"><?php echo showVal($emp['reporting_manager']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Employee Status</div><div class="kv-v"><?php echo showVal(ucfirst($emp['employee_status'] ?? '')); ?></div></div>
              </div>
            </div>

            <!-- KYC & Bank -->
            <div class="tab-pane fade" id="pane-kyc" role="tabpanel" aria-labelledby="tab-kyc">
              <div class="kv">
                <div class="kv-row"><div class="kv-k">Aadhaar Number</div><div class="kv-v"><?php echo showVal($emp['aadhar_card_number']); ?></div></div>
                <div class="kv-row"><div class="kv-k">PAN Number</div><div class="kv-v"><?php echo showVal($emp['pancard_number']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Bank Account</div><div class="kv-v"><?php echo showVal($emp['bank_account_number']); ?></div></div>
                <div class="kv-row"><div class="kv-k">IFSC Code</div><div class="kv-v"><?php echo showVal($emp['ifsc_code']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Passbook</div><div class="kv-v">
                  <?php echo !empty($passbookUrl) ? '<a href="'.e($passbookUrl).'" target="_blank" rel="noopener">View / Download</a>' : '—'; ?>
                </div></div>
              </div>
            </div>

            <!-- Documents -->
            <div class="tab-pane fade" id="pane-docs" role="tabpanel" aria-labelledby="tab-docs">
              <div class="kv">
                <div class="kv-row"><div class="kv-k">Profile Photo</div><div class="kv-v">
                  <?php echo !empty($photoUrl) ? '<a href="'.e($photoUrl).'" target="_blank" rel="noopener">View Photo</a>' : '—'; ?>
                </div></div>
                <div class="kv-row"><div class="kv-k">Passbook File</div><div class="kv-v">
                  <?php echo !empty($passbookUrl) ? '<a href="'.e($passbookUrl).'" target="_blank" rel="noopener">View / Download</a>' : '—'; ?>
                </div></div>

                <!-- If you have more file columns later, add them here -->
                <!-- Example:
                <div class="kv-row"><div class="kv-k">Aadhaar Copy</div><div class="kv-v">...</div></div>
                -->
              </div>
            </div>

          </div><!-- /tab-content -->

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


