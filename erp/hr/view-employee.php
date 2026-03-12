<?php
// view-employee.php (TEK-C TAB STYLE)
// ✅ Shows ALL employee details in tabs + profile pic + passbook/doc
// ✅ Fetch profile/files from ../admin/uploads/... (normalized)
// ✅ Same look as your My Profile tab design

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$emp = null;
$error = '';

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

function showDateTimeVal($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function initials($name){
  $name = trim((string)$name);
  if ($name === '') return 'E';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'E', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return (count($parts) > 1 && $last) ? ($first.$last) : $first;
}

/**
 * ✅ Normalize DB path to correct URL from THIS page
 * We want to load files from ../admin/uploads/...
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

function fileLink($url, $label='View / Download'){
  $url = trim((string)$url);
  if ($url === '') return '—';
  return '<a class="btn btn-sm btn-outline-primary" href="'.e($url).'" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-arrow-down"></i> '.e($label).'
          </a>';
}

function statusChip($status){
  $s = strtolower(trim((string)$status));
  if (!in_array($s, ['active','inactive','resigned'], true)) $s = 'inactive';

  $map = [
    'active'   => ['bg'=>'rgba(16,185,129,.12)','bd'=>'rgba(16,185,129,.22)','tx'=>'#10b981','icon'=>'bi-check-circle'],
    'inactive' => ['bg'=>'rgba(245,158,11,.12)','bd'=>'rgba(245,158,11,.22)','tx'=>'#f59e0b','icon'=>'bi-pause-circle'],
    'resigned' => ['bg'=>'rgba(239,68,68,.12)','bd'=>'rgba(239,68,68,.22)','tx'=>'#ef4444','icon'=>'bi-x-circle'],
  ];
  $c = $map[$s];

  return '<span class="chip" style="background:'.$c['bg'].';border-color:'.$c['bd'].';color:'.$c['tx'].';">
            <i class="bi '.$c['icon'].'"></i> '.e(ucfirst($s)).'
          </span>';
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

$photoUrl    = $emp ? fileUrl($emp['photo'] ?? '') : '';
$passbookUrl = $emp ? fileUrl($emp['passbook_photo'] ?? '') : '';
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
      width:72px;height:72px;border-radius: 18px;
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

    .profile-preview{
      display:flex;
      justify-content:flex-end;
      gap:10px;
      flex-wrap:wrap;
      align-items:flex-start;
    }
    .profile-preview img{
      width:130px;height:130px;border-radius:16px;
      object-fit:cover;
      border:1px solid #e5e7eb;
      box-shadow: 0 10px 22px rgba(17,24,39,.08);
      background:#f3f4f6;
    }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .kv-row{ grid-template-columns: 160px 1fr; }
      .kv-v{ text-align:left; }
      .profile-tabs{ overflow:auto; flex-wrap:nowrap; }
      .profile-tabs .nav-link{ white-space:nowrap; }
      .profile-preview{ justify-content:flex-start; }
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
            <p class="text-muted mb-0">All employee details in tab view</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="manage-employees.php" class="btn-back">
              <i class="bi bi-arrow-left"></i> Back
            </a>
            <?php if ($emp): ?>
              <a href="edit-employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn-edit">
                <i class="bi bi-pencil"></i> Edit
              </a>
            <?php endif; ?>
            <?php if (!empty($passbookUrl)): ?>
              <a href="<?php echo e($passbookUrl); ?>" target="_blank" rel="noopener" class="btn-doc">
                <i class="bi bi-file-earmark-arrow-down"></i> Passbook
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
                  <?php if (!empty($photoUrl)): ?>
                    <img src="<?php echo e($photoUrl); ?>" alt="<?php echo e($emp['full_name'] ?? 'Employee'); ?>"
                         onerror="this.style.display='none';">
                    <?php if (empty($photoUrl)): ?>
                      <?php echo e(initials($emp['full_name'] ?? 'Employee')); ?>
                    <?php endif; ?>
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
                    <?php echo statusChip($emp['employee_status'] ?? 'inactive'); ?>
                    <?php if (!empty($emp['work_location'])): ?>
                      <span class="chip"><i class="bi bi-geo-alt"></i> <?php echo e($emp['work_location']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($emp['site_name'])): ?>
                      <span class="chip"><i class="bi bi-building"></i> <?php echo e($emp['site_name']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($emp['date_of_joining']) && $emp['date_of_joining'] !== '0000-00-00'): ?>
                      <span class="chip"><i class="bi bi-calendar2-check"></i> Joined: <?php echo showDateVal($emp['date_of_joining']); ?></span>
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
                <div class="kv-row"><div class="kv-k">Username</div><div class="kv-v"><?php echo showVal($emp['username']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Status</div><div class="kv-v"><?php echo showVal(ucfirst($emp['employee_status'] ?? '')); ?></div></div>
                <div class="kv-row"><div class="kv-k">Department</div><div class="kv-v"><?php echo showVal($emp['department']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Designation</div><div class="kv-v"><?php echo showVal($emp['designation']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Date of Joining</div><div class="kv-v"><?php echo showDateVal($emp['date_of_joining']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Work Location</div><div class="kv-v"><?php echo showVal($emp['work_location']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Site Name</div><div class="kv-v"><?php echo showVal($emp['site_name']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Reporting Manager</div><div class="kv-v"><?php echo showVal($emp['reporting_manager']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Created At</div><div class="kv-v"><?php echo showDateTimeVal($emp['created_at'] ?? ''); ?></div></div>
                <div class="kv-row"><div class="kv-k">Updated At</div><div class="kv-v"><?php echo showDateTimeVal($emp['updated_at'] ?? ''); ?></div></div>
              </div>
            </div>

            <!-- Personal -->
            <div class="tab-pane fade" id="pane-personal" role="tabpanel" aria-labelledby="tab-personal">
              <div class="kv">
                <div class="kv-row">
                  <div class="kv-k">Profile Photo</div>
                  <div class="kv-v">
                    <div class="profile-preview">
                      <?php if (!empty($photoUrl)): ?>
                        <img src="<?php echo e($photoUrl); ?>" alt="Profile Photo" onerror="this.style.display='none';">
                      <?php else: ?>
                        <div class="chip"><i class="bi bi-image"></i> No photo</div>
                      <?php endif; ?>
                    </div>
                    <div class="mt-2">
                      <?php echo !empty($photoUrl) ? fileLink($photoUrl, 'Open Photo') : '—'; ?>
                    </div>
                  </div>
                </div>

                <div class="kv-row"><div class="kv-k">Full Name</div><div class="kv-v"><?php echo showVal($emp['full_name']); ?></div></div>
                <div class="kv-row"><div class="kv-k">DOB</div><div class="kv-v"><?php echo showDateVal($emp['date_of_birth']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Gender</div><div class="kv-v"><?php echo showVal($emp['gender']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Blood Group</div><div class="kv-v"><?php echo showVal($emp['blood_group']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Mobile</div><div class="kv-v"><?php echo showVal($emp['mobile_number']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Email</div><div class="kv-v"><?php echo showVal($emp['email']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Address</div><div class="kv-v"><?php echo showVal($emp['current_address']); ?></div></div>
              </div>

              <div class="kv mt-3">
                <div class="kv-row"><div class="kv-k">Emergency Contact Name</div><div class="kv-v"><?php echo showVal($emp['emergency_contact_name']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Emergency Contact Phone</div><div class="kv-v"><?php echo showVal($emp['emergency_contact_phone']); ?></div></div>
              </div>
            </div>

            <!-- Employment -->
            <div class="tab-pane fade" id="pane-employment" role="tabpanel" aria-labelledby="tab-employment">
              <div class="kv">
                <div class="kv-row"><div class="kv-k">Employee Status</div><div class="kv-v"><?php echo showVal(ucfirst($emp['employee_status'] ?? ''), '—'); ?></div></div>
                <div class="kv-row"><div class="kv-k">Date of Joining</div><div class="kv-v"><?php echo showDateVal($emp['date_of_joining']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Department</div><div class="kv-v"><?php echo showVal($emp['department']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Designation</div><div class="kv-v"><?php echo showVal($emp['designation']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Reporting Manager</div><div class="kv-v"><?php echo showVal($emp['reporting_manager']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Work Location</div><div class="kv-v"><?php echo showVal($emp['work_location']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Site Name</div><div class="kv-v"><?php echo showVal($emp['site_name']); ?></div></div>
              </div>
            </div>

            <!-- KYC & Bank -->
            <div class="tab-pane fade" id="pane-kyc" role="tabpanel" aria-labelledby="tab-kyc">
              <div class="kv">
                <div class="kv-row"><div class="kv-k">Aadhaar Number</div><div class="kv-v"><?php echo showVal($emp['aadhar_card_number']); ?></div></div>
                <div class="kv-row"><div class="kv-k">PAN Number</div><div class="kv-v"><?php echo showVal($emp['pancard_number']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Bank Account</div><div class="kv-v"><?php echo showVal($emp['bank_account_number']); ?></div></div>
                <div class="kv-row"><div class="kv-k">IFSC Code</div><div class="kv-v"><?php echo showVal($emp['ifsc_code']); ?></div></div>
                <div class="kv-row"><div class="kv-k">Passbook / Document</div><div class="kv-v"><?php echo !empty($passbookUrl) ? fileLink($passbookUrl, 'Open Passbook') : '—'; ?></div></div>
              </div>
            </div>

            <!-- Documents -->
            <div class="tab-pane fade" id="pane-docs" role="tabpanel" aria-labelledby="tab-docs">
              <div class="kv">
                <div class="kv-row"><div class="kv-k">Profile Photo</div><div class="kv-v"><?php echo !empty($photoUrl) ? fileLink($photoUrl, 'View Photo') : '—'; ?></div></div>
                <div class="kv-row"><div class="kv-k">Passbook File</div><div class="kv-v"><?php echo !empty($passbookUrl) ? fileLink($passbookUrl, 'View / Download') : '—'; ?></div></div>

                <!-- If you later add more file columns, add them here -->
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

<?php
if (isset($conn)) { mysqli_close($conn); }
?>
