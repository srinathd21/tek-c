<?php
// login.php (FULL SCREEN SPLIT - NO CARD)

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$error = '';
$success = '';

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// normalize string to small letters + trimmed + single spaces
function norm($v): string {
  $v = strtolower(trim((string)$v));
  $v = preg_replace('/\s+/', ' ', $v);
  return $v;
}

function roleRedirectPath(array $emp): string {
  $designation = norm($emp['designation'] ?? '');
  $department  = norm($emp['department'] ?? '');

  // top management
  if (in_array($designation, ['director', 'vice president', 'general manager'], true)) {
    return 'admin/';
  }

  // hr
  if ($designation === 'hr' || $department === 'hr') {
    return 'hr/';
  }

  // accounts
  if ($designation === 'accountant' || $department === 'accounts' || $department === 'account') {
    return 'accountant/';
  }

  // team lead
  if ($designation === 'team lead' || $designation === 'teamleader') {
    return 'team-leader/';
  }

  // qs
  if (
    $department === 'qs' ||
    strpos($designation, 'qs') === 0 ||
    str_contains($designation, 'quantity surveyor')
  ) {
    return 'qs/';
  }

  // manager
  if ($designation === 'manager' || str_contains($designation, 'manager')) {
    return 'manager/';
  }

  // employee
  if ($designation === 'employee' || str_contains($designation, 'employee')) {
    return 'employee/';
  }

  // project engineers
  if (
    in_array($designation, ['sr. engineer', 'sr engineer', 'senior engineer'], true) ||
    in_array($designation, ['project engineer grade 1', 'project engineer grade 2'], true) ||
    str_contains($designation, 'project engineer')
  ) {
    return 'project-engineer/';
  }

  // fallback
  return 'admin/';
}

// ✅ IMPORTANT: set correct base path of your project
$basePath = '';

// If already logged in, redirect to saved role
if (!empty($_SESSION['employee_id']) && !empty($_SESSION['role_redirect'])) {
  header("Location: " . $_SESSION['role_redirect']);
  exit;
}

// ---------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if ($username === '' || $password === '') {
    $error = "Please enter username and password.";
  } else {
    $sql = "SELECT id, full_name, username, password, designation, department, employee_status
            FROM employees WHERE username = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
      $error = "Database error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param($stmt, "s", $username);
      mysqli_stmt_execute($stmt);
      $res = mysqli_stmt_get_result($stmt);
      $emp = mysqli_fetch_assoc($res);
      mysqli_stmt_close($stmt);

      if (!$emp) {
        $error = "Invalid username or password.";
      } else {
        $status = norm($emp['employee_status'] ?? '');
        if ($status === 'inactive' || $status === 'resigned') {
          $error = "Your account is not active. Please contact Admin.";
        } else if (!password_verify($password, (string)$emp['password'])) {
          $error = "Invalid username or password.";
        } else {
          // ✅ Set session
          $_SESSION['employee_id']   = (int)$emp['id'];
          $_SESSION['employee_name'] = (string)$emp['full_name'];
          $_SESSION['username']      = (string)$emp['username'];
          $_SESSION['designation']   = norm($emp['designation'] ?? '');
          $_SESSION['department']    = norm($emp['department'] ?? '');

          // ✅ Redirect based on normalized small letters
          $redirect = $basePath . roleRedirectPath($emp);
          $_SESSION['role_redirect'] = $redirect;

          header("Location: " . $redirect);
          exit;
        }
      }
    }
  }
}

// Brand colors
$tekcYellow = '#F9C52A';
$tekcDark   = '#111827';
$tekcMuted  = '#6b7280';

// logo path (update if needed)
$logoPath = 'tek-c.png';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <style>
    :root{
      --tekc-yellow: <?php echo $tekcYellow; ?>;
      --tekc-dark: <?php echo $tekcDark; ?>;
      --tekc-muted: <?php echo $tekcMuted; ?>;
      --border: #e5e7eb;
    }

    *{ box-sizing:border-box; }
    html, body{ height:100%; }
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color: var(--tekc-dark);
      background:#fff;
    }

    .split{
      min-height:100vh;
      display:grid;
      grid-template-columns: 1.1fr 0.9fr;
    }

    .left{
      background: var(--tekc-yellow);
      padding: 54px 56px;
      position:relative;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
    }

    /* white blobs + positions */
    .blob1, .blob2{
      position:absolute;
      border-radius:999px;
      z-index:0;
      background:#fff;
      opacity:.22;
    }
    .blob1{
      width:560px;height:560px;
      left:-240px;
      top:-200px;
      opacity:.22;
    }
    .blob2{
      width:700px;height:700px;
      right:-300px;
      bottom:-300px;
      opacity:.18;
    }

    .left-inner{ position:relative; z-index:1; max-width: 760px; }

    /* ✅ Make BRAND CARD same width as feature cards */
    .brand{
      display:flex;
      align-items:center;
      gap:12px;

      background:#fff;
      border-radius: 18px;
      padding: 14px 14px;

      width: 100%;              /* ✅ same full length like feature cards */
      max-width: 760px;

      box-shadow: 0 10px 25px rgba(17,24,39,.10);
      border: 1px solid rgba(17,24,39,.08);
    }

    /* ✅ Make LOGO BOX same size as feature icons */
    .brand .logo{
      width:46px;height:46px;   /* ✅ same as .feature .ic */
      display:flex;
      align-items:center;
      justify-content:center;
      overflow:hidden;
      flex:0 0 auto;

    }
    .brand .logo img{
      width:50px;height:50px;   /* scaled inside the 46x46 */
      object-fit:contain;
      display:block;
      filter: none;
    }

    .brand .t1{
      margin:0;
      font-weight:1100;
      font-size: 18px;
      line-height:1.1;
      color: var(--tekc-dark);
    }
    .brand .t2{
      margin:2px 0 0;
      font-weight:900;
      font-size: 12px;
      color: rgba(17,24,39,.78);
    }

    .headline{
      margin: 34px 0 12px;
      font-size: 42px;
      font-weight: 1200;
      letter-spacing: -.6px;
      line-height: 1.05;
    }
    .subline{
      margin: 0 0 22px;
      font-weight: 900;
      font-size: 14px;
      line-height: 1.7;
      color: rgba(17,24,39,.78);
      max-width: 560px;
    }

    .feature{
      display:flex;
      gap:12px;
      align-items:flex-start;
      padding: 14px 14px;
      border-radius: 18px;
      background:#fff;
      border: 1px solid rgba(17,24,39,.10);
      margin-bottom: 12px;
      box-shadow: 0 10px 25px rgba(17,24,39,.08);
    }

    /* icon background same as left yellow */
    .feature .ic{
      width:46px;height:46px;border-radius: 16px;
      background: var(--tekc-yellow);
      display:flex;align-items:center;justify-content:center;
      color:#111827;
      flex:0 0 auto;
      font-size:18px;
      box-shadow: 0 8px 18px rgba(17,24,39,.12);
    }

    .feature .ft{ margin:0; font-weight:1100; font-size:14px; color:#111827; }
    .feature .fd{ margin:6px 0 0; font-weight:850; font-size:12px; color: rgba(17,24,39,.78); line-height:1.45; }

    .left-footer{
      position:relative;
      z-index:1;
      margin-top: 18px;
      padding-top: 14px;
      border-top: 1px solid rgba(17,24,39,.08);
      display:flex;
      justify-content:space-between;
      gap:12px;
      font-weight: 900;
      font-size: 12px;
      color: rgba(17,24,39,.80);
      flex-wrap: wrap;
    }

    .right{
      background:#fff;
      display:flex;
      flex-direction:column;
      justify-content:center;
      padding: 54px 56px;
    }

    .login-wrap{
      width:100%;
      max-width:520px;
      margin-left:auto;
      margin-right:auto;
    }

    .login-title{
      margin:0 0 6px;
      font-weight:1200;
      font-size:28px;
      letter-spacing:-.3px;
    }
    .login-sub{
      margin:0 0 18px;
      font-weight:850;
      font-size:13px;
      color: var(--tekc-muted);
      line-height:1.45;
    }

    .alert{
      border-radius:14px;
      border:none;
      box-shadow: 0 10px 25px rgba(17,24,39,.08);
    }

    .form-label{
      font-weight:950;
      font-size:13px;
      color:#374151;
    }
    .form-control{
      border:2px solid #e5e7eb;
      border-radius:12px;
      padding:12px 14px;
      font-weight:850;
      font-size:14px;
    }
    .form-control:focus{
      border-color: var(--tekc-yellow);
      box-shadow: 0 0 0 3px rgba(249,197,42,.25);
    }

    .btn-login{
      width:100%;
      border:none;
      border-radius:14px;
      padding:12px 14px;
      font-weight:1200;
      background: var(--tekc-yellow);
      color:#111827;
      box-shadow: 0 12px 26px rgba(249,197,42,.35);
      display:flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      transition: .15s ease;
    }
    .btn-login:hover{ filter: brightness(.98); transform: translateY(-1px); }

    .tiny{
      margin-top:14px;
      font-weight:850;
      font-size:12px;
      color: var(--tekc-muted);
      display:flex;
      align-items:center;
      gap:8px;
    }

    @media (max-width: 992px){
      .split{ grid-template-columns: 1fr; }
      .left{ padding: 28px 22px; min-height: 44vh; }
      .right{ padding: 28px 22px; }
      .headline{ font-size: 32px; }
      .login-wrap{ max-width: 520px; margin: 0; }
      .left-inner{ max-width: 100%; }
    }
  </style>
</head>

<body>
<div class="split">

  <section class="left" aria-label="TEK-C Information">
    <div class="blob1"></div>
    <div class="blob2"></div>

    <div class="left-inner">

      <div class="brand">
        <div class="logo">
          <img src="<?php echo e($logoPath); ?>" onerror="this.style.display='none';" alt="TEK-C Logo">
        </div>
        <div>
          <p class="t1">TEK-C | A UKB Group Company</p>
          <p class="t2">Construction Management Software</p>
        </div>
      </div>

      <h1 class="headline">Manage Projects. Empower Teams. Deliver Faster.</h1>
      <p class="subline">
        TEK-C helps you streamline project execution with organized tracking, clear accountability,
        and secure document control—across every stage of construction.
      </p>

      <div class="feature">
        <div class="ic"><i class="bi bi-kanban"></i></div>
        <div>
          <p class="ft">Sites &amp; Contracts</p>
          <p class="fd">Track project scope, site details, agreements, work orders, and all related documents in one place.</p>
        </div>
      </div>

      <div class="feature">
        <div class="ic"><i class="bi bi-people"></i></div>
        <div>
          <p class="ft">Role-Based Access Control</p>
          <p class="fd">
            Tailored access for Admin, HR, QS, Accounts, Project Managers, Team Leads, and Site Engineers—ensuring clarity,
            control, and accountability.
          </p>
        </div>
      </div>

      <div class="feature" style="margin-bottom:0;">
        <div class="ic"><i class="bi bi-folder2-open"></i></div>
        <div>
          <p class="ft">Centralized Document Management</p>
          <p class="fd">Securely store and manage staff records, site photos, drawings, approvals, and all critical project documents.</p>
        </div>
      </div>
    </div>

    <div class="left-footer">
      <span>©️ 2026 TEK-C – A UKB Group Company</span>
      <span>Secure Construction Management Platform</span>
    </div>
  </section>

  <section class="right" aria-label="Login">
    <div class="login-wrap">

      <h2 class="login-title">Sign in</h2>
      <p class="login-sub">Enter your employee username and password to continue.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill mt-1"></i>
          <div>
            <strong>Login Failed</strong>
            <div><?php echo e($error); ?></div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success d-flex align-items-start gap-2 mb-3" role="alert">
          <i class="bi bi-check-circle-fill mt-1"></i>
          <div>
            <strong>Success</strong>
            <div><?php echo e($success); ?></div>
          </div>
        </div>
      <?php endif; ?>

      <form method="POST" autocomplete="off" novalidate>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <div class="input-group">
            <span class="input-group-text" style="border-radius:12px 0 0 12px; border:2px solid #e5e7eb; border-right:none; background:#fff;">
              <i class="bi bi-person"></i>
            </span>
            <input
              type="text"
              class="form-control"
              id="username"
              name="username"
              value="<?php echo e($_POST['username'] ?? ''); ?>"
              placeholder="Enter username"
              required
              style="border-left:none; border-radius:0 12px 12px 0;"
            >
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <div class="input-group">
            <span class="input-group-text" style="border-radius:12px 0 0 12px; border:2px solid #e5e7eb; border-right:none; background:#fff;">
              <i class="bi bi-lock"></i>
            </span>
            <input
              type="password"
              class="form-control"
              id="password"
              name="password"
              placeholder="Enter password"
              required
              style="border-left:none;"
            >
            <button class="btn btn-outline-secondary" type="button" id="togglePass"
                    style="border-radius:0 12px 12px 0; border:2px solid #e5e7eb; border-left:none; background:#fff;">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-login">
          <i class="bi bi-box-arrow-in-right"></i> Login
        </button>

        <div class="tiny">
          <i class="bi bi-shield-lock"></i>
          Your access is based on your role (hr, qs, manager, employee, etc.).
        </div>
      </form>

    </div>
  </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('togglePass');
    const input = document.getElementById('password');
    if (btn && input) {
      btn.addEventListener('click', function () {
        const isPwd = input.type === 'password';
        input.type = isPwd ? 'text' : 'password';
        btn.innerHTML = isPwd ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
      });
    }
  });
</script>

</body>
</html>

<?php
if (isset($conn)) { mysqli_close($conn); }
?>