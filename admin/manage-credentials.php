<?php
// manage-credentials.php (TEK-C style)
// ✅ UPDATED:
// 1) PRG redirect + flash messages (no resubmit)
// 2) Prepared statements (no SQL injection)
// 3) Password stored ENCRYPTED (AES-256-GCM) + masked display + reveal toggle
// 4) Copy button copies decrypted password
// 5) MOBILE cards view + Desktop DataTable
// 6) Table auto-create + auto-add missing encryption columns safely
//
// IMPORTANT:
// - Add this line in includes/db-config.php (or another secure config):
//   define('APP_SECRET_KEY', 'YOUR-VERY-LONG-RANDOM-SECRET-KEY');  // keep private
// - If APP_SECRET_KEY is missing, code will fallback to plain storage (NOT recommended).

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$credentials = [];
$success = '';
$error = '';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- Helpers ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Flash helpers
function flash_set($key, $msg){ $_SESSION[$key] = (string)$msg; }
function flash_get($key){ $v = (string)($_SESSION[$key] ?? ''); unset($_SESSION[$key]); return $v; }

// Crypto helpers (AES-256-GCM)
function app_key_bytes() {
  if (defined('APP_SECRET_KEY') && APP_SECRET_KEY !== '') {
    // Make 32 bytes key using SHA-256
    return hash('sha256', APP_SECRET_KEY, true);
  }
  return null;
}
function encrypt_secret($plaintext) {
  $key = app_key_bytes();
  if (!$key) return [false, null, null, null]; // no encryption key
  $iv  = random_bytes(12);                     // GCM recommended 12 bytes
  $tag = '';
  $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($cipher === false) return [false, null, null, null];
  return [true, base64_encode($cipher), base64_encode($iv), base64_encode($tag)];
}
function decrypt_secret($cipher_b64, $iv_b64, $tag_b64) {
  $key = app_key_bytes();
  if (!$key) return null;
  $cipher = base64_decode((string)$cipher_b64, true);
  $iv     = base64_decode((string)$iv_b64, true);
  $tag    = base64_decode((string)$tag_b64, true);
  if ($cipher === false || $iv === false || $tag === false) return null;
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  return ($plain === false) ? null : $plain;
}

// ---------------- Ensure table exists ----------------
$createTable = "CREATE TABLE IF NOT EXISTS credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service_name VARCHAR(100) NOT NULL,
  username_email VARCHAR(150) NOT NULL,
  password VARCHAR(255) NOT NULL,
  requires_otp TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!mysqli_query($conn, $createTable)) {
  $error = "Error creating table: " . mysqli_error($conn);
}

// ---------------- Ensure encryption columns exist (non-fatal) ----------------
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM credentials LIKE 'password_enc'");
$hasEncCols = false;
if ($colCheck) {
  $hasEncCols = (mysqli_num_rows($colCheck) > 0);
  mysqli_free_result($colCheck);
}

if (!$hasEncCols) {
  // Add columns to support encrypted storage (leave old password column for backward compatibility)
  @mysqli_query($conn, "ALTER TABLE credentials ADD COLUMN password_enc TEXT NULL AFTER password");
  @mysqli_query($conn, "ALTER TABLE credentials ADD COLUMN password_iv VARCHAR(64) NULL AFTER password_enc");
  @mysqli_query($conn, "ALTER TABLE credentials ADD COLUMN password_tag VARCHAR(64) NULL AFTER password_iv");
  // re-check
  $colCheck2 = mysqli_query($conn, "SHOW COLUMNS FROM credentials LIKE 'password_enc'");
  if ($colCheck2) {
    $hasEncCols = (mysqli_num_rows($colCheck2) > 0);
    mysqli_free_result($colCheck2);
  }
}

// ---------------- POST handlers (PRG) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Save
  if (isset($_POST['save_credentials'])) {
    $service_name   = trim((string)($_POST['service_name'] ?? ''));
    $username_email = trim((string)($_POST['username_email'] ?? ''));
    $password_plain = trim((string)($_POST['password'] ?? ''));
    $requires_otp   = isset($_POST['requires_otp']) ? 1 : 0;

    if ($service_name === '' || $username_email === '' || $password_plain === '') {
      flash_set('flash_error', "Service Name, Username/Email and Password are required!");
      header("Location: manage-credentials.php");
      exit;
    }

    // Encrypt if possible
    $enc_ok = false; $enc = null; $iv = null; $tag = null;
    if ($hasEncCols) {
      [$enc_ok, $enc, $iv, $tag] = encrypt_secret($password_plain);
    }

    if ($hasEncCols && $enc_ok) {
      $stmt = mysqli_prepare($conn, "INSERT INTO credentials (service_name, username_email, password, password_enc, password_iv, password_tag, requires_otp)
                                     VALUES (?, ?, ?, ?, ?, ?, ?)");
      if (!$stmt) {
        flash_set('flash_error', "DB error: " . mysqli_error($conn));
        header("Location: manage-credentials.php");
        exit;
      }

      // keep password column as masked placeholder (not real password)
      $placeholder = 'ENCRYPTED';
      mysqli_stmt_bind_param($stmt, "ssssssi", $service_name, $username_email, $placeholder, $enc, $iv, $tag, $requires_otp);

      if (mysqli_stmt_execute($stmt)) {
        flash_set('flash_success', "Credentials saved successfully!");
      } else {
        flash_set('flash_error', "Error saving credentials: " . mysqli_stmt_error($stmt));
      }
      mysqli_stmt_close($stmt);
    } else {
      // fallback: store plain (not recommended, but keeps your app working if no key)
      $stmt = mysqli_prepare($conn, "INSERT INTO credentials (service_name, username_email, password, requires_otp)
                                     VALUES (?, ?, ?, ?)");
      if (!$stmt) {
        flash_set('flash_error', "DB error: " . mysqli_error($conn));
        header("Location: manage-credentials.php");
        exit;
      }
      mysqli_stmt_bind_param($stmt, "sssi", $service_name, $username_email, $password_plain, $requires_otp);

      if (mysqli_stmt_execute($stmt)) {
        flash_set('flash_success', "Credentials saved successfully! (Warning: stored without encryption)");
      } else {
        flash_set('flash_error', "Error saving credentials: " . mysqli_stmt_error($stmt));
      }
      mysqli_stmt_close($stmt);
    }

    header("Location: manage-credentials.php");
    exit;
  }

  // Delete
  if (isset($_POST['delete_id'])) {
    $id = (int)($_POST['delete_id'] ?? 0);

    $stmt = mysqli_prepare($conn, "DELETE FROM credentials WHERE id = ? LIMIT 1");
    if ($stmt) {
      mysqli_stmt_bind_param($stmt, "i", $id);
      if (mysqli_stmt_execute($stmt)) {
        flash_set('flash_success', "Credentials deleted successfully!");
      } else {
        flash_set('flash_error', "Error deleting credentials: " . mysqli_stmt_error($stmt));
      }
      mysqli_stmt_close($stmt);
    } else {
      flash_set('flash_error', "Database error: " . mysqli_error($conn));
    }

    header("Location: manage-credentials.php");
    exit;
  }
}

// ---------------- Flash messages ----------------
$success = flash_get('flash_success');
$error   = flash_get('flash_error');

// ---------------- Fetch all credentials ----------------
$result = mysqli_query($conn, "SELECT * FROM credentials ORDER BY created_at DESC");
if ($result) {
  $credentials = mysqli_fetch_all($result, MYSQLI_ASSOC);
  mysqli_free_result($result);
} else {
  $error = $error ?: ("Error fetching credentials: " . mysqli_error($conn));
}

// Total count
$total_credentials = 0;
$total_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM credentials");
if ($total_result) {
  $total_data = mysqli_fetch_assoc($total_result);
  $total_credentials = (int)($total_data['count'] ?? 0);
  mysqli_free_result($total_result);
}

// Build decrypted map for JS reveal/copy
$decrypted_map = [];
foreach ($credentials as $cred) {
  $id = (int)($cred['id'] ?? 0);

  $plain = null;
  if ($hasEncCols && !empty($cred['password_enc']) && !empty($cred['password_iv']) && !empty($cred['password_tag'])) {
    $plain = decrypt_secret($cred['password_enc'], $cred['password_iv'], $cred['password_tag']);
  }
  if ($plain === null) {
    // fallback to old plain password field
    $plain = (string)($cred['password'] ?? '');
  }
  $decrypted_map[(string)$id] = $plain;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Credentials - TEK-C</title>

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
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; }
    .table td{ vertical-align:middle; border-color: var(--border); font-weight:650; color:#374151; padding-top:14px; padding-bottom:14px; }

    .btn-add {
      background: var(--blue);
      color: white;
      border: none;
      padding: 10px 18px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
      text-decoration:none;
      white-space:nowrap;
    }
    .btn-add:hover { background: #2a8bc9; color:#fff; }

    .credential-badge {
      background: rgba(45, 156, 219, 0.1);
      color: var(--blue);
      padding: 6px 12px;
      border-radius: 8px;
      font-weight: 800;
      font-size: 12px;
      border: 1px solid rgba(45, 156, 219, 0.2);
      display:inline-flex;
      align-items:center;
      gap:8px;
    }

    .password-field {
      font-family: 'Courier New', monospace;
      font-size: 13px;
      background: rgba(0,0,0,0.02);
      padding: 4px 8px;
      border-radius: 6px;
      border: 1px solid var(--border);
    }

    .btn-copy, .btn-reveal {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 4px 8px;
      color: var(--muted);
      font-size: 12px;
    }
    .btn-copy:hover, .btn-reveal:hover { background: var(--bg); color: var(--blue); }

    .btn-delete {
      background: transparent;
      border: 1px solid rgba(235, 87, 87, 0.2);
      border-radius: 8px;
      padding: 6px 10px;
      color: var(--red);
      font-size: 12px;
      font-weight:800;
    }
    .btn-delete:hover { background: rgba(235, 87, 87, 0.1); color: #d32f2f; }

    .form-group { margin-bottom: 16px; }
    .form-label { font-weight: 800; font-size: 13px; color: #374151; margin-bottom: 6px; }
    .form-control { border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; font-weight: 650; }
    .form-control:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1); }

    .otp-badge {
      background: rgba(242, 201, 76, 0.1);
      color: #f59e0b;
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 800;
      border: 1px solid rgba(242, 201, 76, 0.2);
      display:inline-flex;
      align-items:center;
      gap:6px;
    }

    .service-buttons { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
    .service-btn {
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 6px 12px;
      font-size: 11px;
      font-weight: 800;
      color: var(--muted);
    }
    .service-btn:hover { background: var(--blue); color: white; border-color: var(--blue); }

    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

    /* DataTables tweaks */
    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 8px 12px;
      font-weight: 650;
      outline: none;
    }
    div.dataTables_wrapper .dataTables_filter input:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
    }
    .dataTables_paginate .pagination .page-link{
      border-radius: 10px;
      margin: 0 3px;
      font-weight: 750;
    }

    /* ✅ MOBILE CARDS */
    .cred-card{
      border:1px solid var(--border);
      border-radius:16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding:12px;
    }
    .cred-top{ display:flex; gap:10px; align-items:flex-start; justify-content:space-between; }
    .cred-main{ flex:1 1 auto; }
    .cc-title{ font-weight:1000; font-size:14px; color:#111827; line-height:1.25; }
    .cc-sub{ font-size:12px; color:#6b7280; font-weight:800; margin-top:2px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .cc-kv{ margin-top:10px; display:grid; gap:8px; }
    .cc-row{ display:flex; gap:10px; }
    .cc-key{ flex:0 0 92px; color:#6b7280; font-weight:1000; font-size:12px; }
    .cc-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; word-break:break-word; }
    .cc-actions{ margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; }
    .cc-actions button, .cc-actions a{ flex:1 1 auto; border-radius:12px; justify-content:center; font-weight:900; }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .main{ margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
      .sidebar{ position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
    }
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
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

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Manage Credentials</h1>
            <p class="text-muted mb-0">Store and manage login credentials for various services</p>
          </div>
          <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#addCredentialModal">
            <i class="bi bi-plus-lg"></i> Add New
          </button>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- Stats Card -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-key"></i></div>
              <div>
                <div class="stat-label">Total Credentials</div>
                <div class="stat-value"><?php echo (int)$total_credentials; ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ✅ MOBILE: Credentials Cards -->
        <div class="d-block d-md-none mb-4">
          <?php if (empty($credentials)): ?>
            <div class="panel text-muted" style="font-weight:900;">No credentials stored yet.</div>
          <?php else: ?>
            <div class="d-grid gap-3">
              <?php foreach ($credentials as $cred): ?>
                <?php
                  $id = (int)$cred['id'];
                  $ts = strtotime($cred['created_at'] ?? '') ?: 0;
                  $displayDate = $ts ? date('d M Y', $ts) : '—';
                  $otp = ((int)($cred['requires_otp'] ?? 0) === 1);
                  $service = (string)($cred['service_name'] ?? '');
                  $user = (string)($cred['username_email'] ?? '');
                ?>
                <div class="cred-card">
                  <div class="cred-top">
                    <div class="cred-main">
                      <div class="cc-title"><?php echo e($service); ?></div>
                      <div class="cc-sub">
                        <span><i class="bi bi-person-badge"></i> <?php echo e($user); ?></span>
                        <?php if ($otp): ?>
                          <span class="otp-badge"><i class="bi bi-shield-check"></i> OTP</span>
                        <?php else: ?>
                          <span class="text-muted" style="font-weight:900;">No OTP</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <span class="credential-badge"><i class="bi bi-calendar"></i> <?php echo e($displayDate); ?></span>
                  </div>

                  <div class="cc-kv">
                    <div class="cc-row">
                      <div class="cc-key">Password</div>
                      <div class="cc-val">
                        <span class="password-field" id="pw-masked-<?php echo $id; ?>">••••••••••</span>
                        <span class="password-field d-none" id="pw-plain-<?php echo $id; ?>"></span>
                      </div>
                    </div>
                  </div>

                  <div class="cc-actions">
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-reveal" data-id="<?php echo $id; ?>">
                      <i class="bi bi-eye"></i> Reveal
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm btn-copy" data-id="<?php echo $id; ?>">
                      <i class="bi bi-copy"></i> Copy
                    </button>
                    <form method="POST" style="margin:0;flex:1 1 auto;" onsubmit="return confirm('Are you sure you want to delete these credentials?');">
                      <input type="hidden" name="delete_id" value="<?php echo $id; ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                        <i class="bi bi-trash"></i> Delete
                      </button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- ✅ DESKTOP: Credentials Table -->
        <div class="panel mb-4 d-none d-md-block">
          <div class="panel-header">
            <h3 class="panel-title">Stored Credentials</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table id="credentialsTable" class="table align-middle mb-0 dt-responsive nowrap" style="width:100%">
              <thead>
                <tr>
                  <th style="min-width:150px;">Service Name</th>
                  <th style="min-width:180px;">Username/Email</th>
                  <th style="min-width:170px;">Password</th>
                  <th style="min-width:120px;">OTP Required</th>
                  <th style="min-width:120px;">Added On</th>
                  <th class="text-end" style="width:140px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($credentials as $cred): ?>
                  <?php
                    $id = (int)$cred['id'];
                    $ts = strtotime($cred['created_at'] ?? '') ?: 0;
                    $displayDate = $ts ? date('d M Y', $ts) : '';
                  ?>
                  <tr>
                    <td>
                      <span class="credential-badge">
                        <i class="bi bi-globe"></i> <?php echo e($cred['service_name'] ?? ''); ?>
                      </span>
                    </td>

                    <td>
                      <div class="d-flex flex-column">
                        <span><?php echo e($cred['username_email'] ?? ''); ?></span>
                        <small class="text-muted">
                          <?php echo filter_var((string)$cred['username_email'], FILTER_VALIDATE_EMAIL) ? 'Email' : 'Username'; ?>
                        </small>
                      </div>
                    </td>

                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <span class="password-field" id="pw-masked-t-<?php echo $id; ?>">••••••••••</span>
                        <span class="password-field d-none" id="pw-plain-t-<?php echo $id; ?>"></span>

                        <button type="button" class="btn-reveal" data-id="<?php echo $id; ?>" title="Reveal">
                          <i class="bi bi-eye"></i>
                        </button>
                        <button type="button" class="btn-copy" data-id="<?php echo $id; ?>" title="Copy password">
                          <i class="bi bi-copy"></i>
                        </button>
                      </div>
                    </td>

                    <td>
                      <?php if ((int)$cred['requires_otp'] === 1): ?>
                        <span class="otp-badge"><i class="bi bi-shield-check"></i> Yes</span>
                      <?php else: ?>
                        <span class="text-muted">No</span>
                      <?php endif; ?>
                    </td>

                    <td data-order="<?php echo $ts ? $ts : 0; ?>">
                      <?php echo e($displayDate); ?>
                    </td>

                    <td class="text-end">
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete these credentials?');">
                        <input type="hidden" name="delete_id" value="<?php echo $id; ?>">
                        <button type="submit" class="btn-delete" title="Delete">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>

  </main>
</div>

<!-- Add Credential Modal -->
<div class="modal fade" id="addCredentialModal" tabindex="-1" aria-labelledby="addCredentialModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="addCredentialModalLabel">Add New Credentials</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <div class="form-group">
                <label for="service_name" class="form-label">Service Name *</label>
                <input type="text" class="form-control" id="service_name" name="service_name" required placeholder="e.g., Hostinger, cPanel, AWS, Domain">
                <div class="service-buttons">
                  <button type="button" class="service-btn" data-service="Hostinger">Hostinger</button>
                  <button type="button" class="service-btn" data-service="cPanel">cPanel</button>
                  <button type="button" class="service-btn" data-service="AWS">AWS</button>
                  <button type="button" class="service-btn" data-service="Domain">Domain</button>
                  <button type="button" class="service-btn" data-service="FTP">FTP</button>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="form-group">
                <label for="username_email" class="form-label">Username or Email *</label>
                <input type="text" class="form-control" id="username_email" name="username_email" required placeholder="username or email@example.com">
              </div>
            </div>

            <div class="col-12">
              <div class="form-group">
                <label for="password" class="form-label">Password *</label>
                <div class="input-group">
                  <input type="text" class="form-control" id="password" name="password" required placeholder="Enter password">
                  <button type="button" class="btn btn-outline-secondary" id="generatePasswordBtn" title="Generate password">
                    <i class="bi bi-shuffle"></i>
                  </button>
                </div>
                <?php if (!defined('APP_SECRET_KEY') || APP_SECRET_KEY === ''): ?>
                  <div class="form-text text-danger" style="font-weight:800;">
                    ⚠️ APP_SECRET_KEY is not set. Passwords will be stored without encryption.
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="requires_otp" name="requires_otp" value="1">
                <label class="form-check-label" for="requires_otp">
                  Requires OTP/2FA
                </label>
                <div class="form-text">Check if this service requires One-Time Password or Two-Factor Authentication</div>
              </div>
            </div>

          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="save_credentials" class="btn-add">Save Credentials</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
(function () {
  const secrets = <?php echo json_encode($decrypted_map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

  // DataTables init
  $(function () {
    $('#credentialsTable').DataTable({
      responsive: true,
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      order: [[4, 'desc']],
      columnDefs: [
        { targets: [5], orderable: false, searchable: false },
        { targets: [2], orderable: false },
        { targets: [3], orderable: false }
      ],
      language: {
        zeroRecords: "No matching credentials found",
        info: "Showing _START_ to _END_ of _TOTAL_ credentials",
        infoEmpty: "No credentials to show",
        lengthMenu: "Show _MENU_",
        search: "Search:"
      }
    });

    // Focus first input when modal opens
    $('#addCredentialModal').on('shown.bs.modal', function () {
      $('#service_name').trigger('focus');
    });

    // Quick service buttons
    $(document).on('click', '.service-btn', function () {
      $('#service_name').val($(this).data('service'));
    });

    // Generate password
    $('#generatePasswordBtn').on('click', function () {
      const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
      let pw = '';
      for (let i = 0; i < 14; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
      $('#password').val(pw);
    });

    // Reveal/Hide
    $(document).on('click', '.btn-reveal', function () {
      const id = String($(this).data('id') || '');
      const plain = secrets[id] ?? '';

      // mobile ids
      const $mMasked = $('#pw-masked-' + id);
      const $mPlain  = $('#pw-plain-' + id);

      // table ids
      const $tMasked = $('#pw-masked-t-' + id);
      const $tPlain  = $('#pw-plain-t-' + id);

      const showing = ($mPlain.length && !$mPlain.hasClass('d-none')) || ($tPlain.length && !$tPlain.hasClass('d-none'));

      if (showing) {
        if ($mPlain.length) { $mPlain.addClass('d-none'); $mMasked.removeClass('d-none'); }
        if ($tPlain.length) { $tPlain.addClass('d-none'); $tMasked.removeClass('d-none'); }
        $(this).html('<i class="bi bi-eye"></i>');
      } else {
        if ($mPlain.length) { $mPlain.text(plain).removeClass('d-none'); $mMasked.addClass('d-none'); }
        if ($tPlain.length) { $tPlain.text(plain).removeClass('d-none'); $tMasked.addClass('d-none'); }
        $(this).html('<i class="bi bi-eye-slash"></i>');
      }
    });

    // Copy
    $(document).on('click', '.btn-copy', function () {
      const id = String($(this).data('id') || '');
      const pw = (secrets[id] ?? '').toString();
      const $btn = $(this);
      navigator.clipboard.writeText(pw).then(() => {
        const original = $btn.html();
        $btn.html('<i class="bi bi-check"></i>');
        setTimeout(() => $btn.html(original), 1200);
      });
    });
  });
})();
</script>

</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
?>