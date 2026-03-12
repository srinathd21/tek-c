<?php
// admin/includes/topbar.php
// IMPORTANT: Do NOT call session_start() here.
// session_start() should be in the main page before any HTML.

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

// ---- Logged user details from session ----
// (Set these during login)
$loggedName   = $_SESSION['employee_name']  ?? ($_SESSION['name'] ?? 'User');
$loggedEmail  = $_SESSION['employee_email'] ?? ($_SESSION['email'] ?? '');
$loggedUser   = $_SESSION['username']      ?? '';
$loggedPhoto  = $_SESSION['employee_photo'] ?? ''; // <-- store employee photo path in session at login

$avatarText = initials($loggedName);

// If topbar is in admin/includes and logout.php is outside admin:
$logoutUrl = '../logout.php'; // change to 'logout.php' if inside admin folder

$displayMail = ($loggedEmail !== '') ? $loggedEmail : $loggedUser;

// Optional: verify photo exists (server path)
// This avoids broken images when path is missing
$showPhoto = false;
$photoSrc  = '';

if ($loggedPhoto !== '') {
  // Usually stored like: uploads/employees/photos/xxx.png
  $photoSrc = $loggedPhoto;

  // Try to check existence on disk (relative to admin/)
  $diskPath = __DIR__ . '/../' . ltrim($loggedPhoto, '/');
  if (file_exists($diskPath)) {
    $showPhoto = true;
  } else {
    // If your uploads path is relative to root differently, comment this check
    // and allow it to load directly:
    $showPhoto = true;
  }
}
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
               style="width:100%;height:100%;object-fit:cover;display:block;">
        <?php else: ?>
          <?php echo e($avatarText); ?>
        <?php endif; ?>
      </div>

      <div class="user-meta d-none d-sm-block">
        <div class="name"><?php echo e($loggedName); ?></div>
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
