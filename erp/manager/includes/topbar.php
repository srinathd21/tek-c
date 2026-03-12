<?php
// admin/includes/topbar.php
// IMPORTANT: Do NOT call session_start() here.
// session_start() must be in the main page before any output.

// If you have db-config with get_db_connection()
require_once __DIR__ . '/db-config.php';

if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('initials')) {
  function initials($name){
    $name = trim((string)$name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
    return (count($parts) > 1 && $last) ? ($first.$last) : $first;
  }
}

// ---- Get logged employee id ----
$employeeId = isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : 0;

// ---- Session values (may be empty) ----
$loggedName  = $_SESSION['employee_name']  ?? ($_SESSION['name'] ?? 'User');
$loggedEmail = $_SESSION['employee_email'] ?? ($_SESSION['email'] ?? '');   // want email under name
$loggedUser  = $_SESSION['username']       ?? '';
$loggedPhoto = $_SESSION['employee_photo'] ?? '';                           // DB photo path

// ✅ If email/photo missing in session, fetch from DB (employees table)
if ($employeeId > 0 && (trim($loggedEmail) === '' || trim($loggedPhoto) === '' || trim($loggedName) === '' || $loggedName === 'User')) {
  $conn = get_db_connection();
  if ($conn) {
    $sql = "SELECT full_name, email, username, mobile_number, photo
            FROM employees
            WHERE id = ?
            LIMIT 1";
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
      mysqli_stmt_bind_param($st, "i", $employeeId);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      if ($row = mysqli_fetch_assoc($res)) {

        if (trim($loggedName) === '' || $loggedName === 'User') {
          $loggedName = $row['full_name'] ?: $loggedName;
          $_SESSION['employee_name'] = $loggedName;
        }

        if (trim($loggedEmail) === '' && !empty($row['email'])) {
          $loggedEmail = $row['email'];
          $_SESSION['employee_email'] = $loggedEmail;
        }

        if (trim($loggedUser) === '' && !empty($row['username'])) {
          $loggedUser = $row['username'];
          $_SESSION['username'] = $loggedUser;
        }

        if (trim($loggedPhoto) === '' && !empty($row['photo'])) {
          $loggedPhoto = $row['photo'];
          $_SESSION['employee_photo'] = $loggedPhoto;
        }

        // Optional fallback if email is not stored in DB too:
        // show mobile or username instead of blank
        if (trim($loggedEmail) === '' && !empty($row['mobile_number'])) {
          $loggedEmail = $row['mobile_number']; // fallback shown under name
        }
      }
      mysqli_stmt_close($st);
    }
    mysqli_close($conn);
  }
}

// ✅ Display under name: EMAIL only (fallback if missing)
$displayMail = trim($loggedEmail) !== '' ? $loggedEmail : ($loggedUser !== '' ? $loggedUser : '—');

// ---- Photo URL FIX ----
// Your image files are INSIDE admin/uploads/...
// DB stores like: uploads/employees/photos/xxx.png
// Best: make an absolute URL path so it works from any nested page.
$showPhoto = false;
$photoSrc  = '';

if (trim($loggedPhoto) !== '') {
  $stored = ltrim($loggedPhoto, '/');     // "uploads/employees/photos/xxx.png"

  // ✅ Force absolute web path: /admin/uploads/...
  // If stored already includes "admin/", don’t double it.
  if (strpos($stored, 'admin/') === 0) {
    $photoSrc = '/' . $stored;            // "/admin/uploads/..."
  } else {
    $photoSrc = '/admin/' . $stored;      // "/admin/uploads/..."
  }

  $showPhoto = true;
}

$avatarText = initials($loggedName);

// logout for pages under admin folder:
$logoutUrl = '../logout.php'; // ✅ keep this if logout.php is outside admin folder
// If your logout.php is inside admin folder, use:
// $logoutUrl = 'logout.php';
?>

<!-- Topbar -->
<div class="topbar">
  <div class="top-left">
    <button id="menuBtn" class="hamburger" aria-label="Toggle sidebar" title="Toggle sidebar">
      <i class="bi bi-list"></i>
    </button>

    <div class="dots d-none d-sm-flex" title="Menu">
      <i class="bi bi-telephone" style="opacity:.85"></i>
      <div class="dot"></div><div class="dot"></div><div class="dot"></div>
    </div>
  </div>

  <div class="top-right">
    <button class="icon-btn" aria-label="Notifications" title="Notifications">
      <i class="bi bi-bell"></i>
    </button>

    <div class="pill" title="<?php echo e($loggedName); ?>">
      <div class="avatar" style="overflow:hidden; display:flex; align-items:center; justify-content:center;">
        <?php if ($showPhoto): ?>
          <img src="<?php echo e($photoSrc); ?>"
               alt="<?php echo e($loggedName); ?>"
               style="width:100%;height:100%;object-fit:cover;display:block;"
               onerror="this.style.display='none'; this.parentElement.textContent='<?php echo e($avatarText); ?>';">
        <?php else: ?>
          <?php echo e($avatarText); ?>
        <?php endif; ?>
      </div>

      <div class="user-meta d-none d-sm-block">
        <div class="name"><?php echo e($loggedName); ?></div>
        <!-- ✅ EMAIL under name -->
        <div class="mail"><?php echo e($displayMail); ?></div>
      </div>

      <i class="bi bi-chevron-down" style="color:#6b7280"></i>
    </div>

    <!-- Logout -->
    <a class="icon-btn text-danger"
       href="<?php echo e($logoutUrl); ?>"
       aria-label="Logout"
       title="Logout"
       onclick="return confirm('Do you want to logout?');">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</div>
