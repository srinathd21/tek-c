<?php
// admin/includes/topbar.php
// ✅ UPDATED:
// 1) Routes fixed for pages INSIDE admin folder (no "../" for view/profile/logout)
// 2) Removed "Change Password" option from dropdown
// IMPORTANT: Do NOT call session_start() here.
// session_start() must be in the main page before any output.

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
$loggedEmail = $_SESSION['employee_email'] ?? ($_SESSION['email'] ?? '');
$loggedUser  = $_SESSION['username']       ?? '';
$loggedPhoto = $_SESSION['employee_photo'] ?? '';

// ✅ If email/photo/name missing in session, fetch from DB (employees table)
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

        // fallback under name if email empty
        if (trim($loggedEmail) === '' && !empty($row['mobile_number'])) {
          $loggedEmail = $row['mobile_number'];
        }
      }
      mysqli_stmt_close($st);
    }
  }
}

// ✅ Display under name: EMAIL only (fallback if missing)
$displayMail = trim($loggedEmail) !== '' ? $loggedEmail : ($loggedUser !== '' ? $loggedUser : '—');

// ---- Photo URL FIX ----
$showPhoto = false;
$photoSrc  = '';

if (trim($loggedPhoto) !== '') {
  $stored = ltrim($loggedPhoto, '/'); // "uploads/employees/photos/xxx.png"
  // When topbar is used inside /admin, best is "/admin/..."
  if (strpos($stored, 'admin/') === 0) $photoSrc = '/' . $stored;
  else $photoSrc = '/admin/' . $stored;

  $showPhoto = true;
}

$avatarText = initials($loggedName);

// ✅ ROUTES (inside admin folder)
$logoutUrl  = 'logout.php';
$profileUrl = 'view-employee.php?id=' . (int)$employeeId;

// ✅ Welcome message
$welcomeText = 'Welcome, ' . trim((string)$loggedName);
?>

<!-- Topbar -->
<style>
  /* dropdown styles */
  .user-dropdown{
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 280px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow);
    padding: 12px;
    z-index: 2000;
    display: none;
  }
  .user-dropdown.show{ display:block; }

  .ud-top{
    display:flex;
    gap:10px;
    align-items:center;
    padding-bottom:10px;
    border-bottom:1px solid var(--border);
    margin-bottom:10px;
  }
  .ud-avatar{
    width:42px;height:42px;border-radius:12px;overflow:hidden;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-weight:900;
    flex:0 0 auto;
  }
  .ud-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
  .ud-name{ font-weight:1000; color:#111827; line-height:1.1; }
  .ud-mail{ font-weight:800; color:#6b7280; font-size:12px; margin-top:2px; }

  .ud-actions{ display:grid; gap:8px; }

  .ud-btn{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    text-decoration:none;
    border:1px solid var(--border);
    background:#fff;
    border-radius:12px;
    padding:10px 12px;
    font-weight:900;
    color:#374151;
  }
  .ud-btn:hover{ background: var(--bg); color: var(--blue); }
  .ud-btn .left{ display:flex; align-items:center; gap:10px; }

  .ud-btn.danger{
    border-color: rgba(235,87,87,.25);
    color:#ef4444;
  }
  .ud-btn.danger:hover{
    background: rgba(239,68,68,.08);
    color:#dc2626;
  }

  /* Make pill clickable on mobile (and desktop) */
  .pill.user-pill{ cursor:pointer; position:relative; user-select:none; }
</style>

<div class="topbar">
  <div class="top-left">
    <button id="menuBtn" class="hamburger" aria-label="Toggle sidebar" title="Toggle sidebar">
      <i class="bi bi-list"></i>
    </button>

    <div class="d-none d-md-flex align-items-center ms-2"
         style="font-weight:900; color:#111827; font-size:14px;">
      <?php echo e($welcomeText); ?>
    </div>
  </div>

  <div class="top-right">
    <button class="icon-btn" aria-label="Notifications" title="Notifications">
      <i class="bi bi-bell"></i>
    </button>

    <!-- ✅ Click/Tap this pill to open dropdown -->
    <div class="pill user-pill" id="userPill" title="<?php echo e($loggedName); ?>" aria-haspopup="true" aria-expanded="false">
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
        <div class="mail"><?php echo e($displayMail); ?></div>
      </div>

      <i class="bi bi-chevron-down" style="color:#6b7280"></i>

      <!-- ✅ DROPDOWN -->
      <div class="user-dropdown" id="userDropdown" role="menu" aria-label="User menu">
        <div class="ud-top">
          <div class="ud-avatar">
            <?php if ($showPhoto): ?>
              <img src="<?php echo e($photoSrc); ?>"
                   alt="<?php echo e($loggedName); ?>"
                   onerror="this.style.display='none'; this.parentElement.textContent='<?php echo e($avatarText); ?>';">
            <?php else: ?>
              <?php echo e($avatarText); ?>
            <?php endif; ?>
          </div>
          <div style="min-width:0;">
            <div class="ud-name"><?php echo e($loggedName); ?></div>
            <div class="ud-mail"><?php echo e($displayMail); ?></div>
          </div>
        </div>

        <div class="ud-actions">
          <a class="ud-btn" href="<?php echo e($profileUrl); ?>">
            <span class="left"><i class="bi bi-person"></i> My Profile</span>
            <i class="bi bi-chevron-right"></i>
          </a>

          <a class="ud-btn danger"
             href="<?php echo e($logoutUrl); ?>"
             onclick="return confirm('Do you want to logout?');">
            <span class="left"><i class="bi bi-box-arrow-right"></i> Logout</span>
            <i class="bi bi-chevron-right"></i>
          </a>
        </div>
      </div>
    </div>

    <!-- Optional logout icon (desktop only) -->
    <a class="icon-btn text-danger d-none d-sm-inline-flex"
       href="<?php echo e($logoutUrl); ?>"
       aria-label="Logout"
       title="Logout"
       onclick="return confirm('Do you want to logout?');">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</div>

<script>
(function(){
  const pill = document.getElementById('userPill');
  const dd = document.getElementById('userDropdown');

  if (!pill || !dd) return;

  function closeDropdown(){
    dd.classList.remove('show');
    pill.setAttribute('aria-expanded', 'false');
  }

  function toggleDropdown(){
    const open = dd.classList.contains('show');
    if (open) closeDropdown();
    else {
      dd.classList.add('show');
      pill.setAttribute('aria-expanded', 'true');
    }
  }

  // Toggle on click/tap
  pill.addEventListener('click', function(e){
    const target = e.target;
    // if click is on a link inside dropdown, allow navigation
    if (target && dd.contains(target) && target.closest('a')) return;
    toggleDropdown();
  });

  // Close on outside click
  document.addEventListener('click', function(e){
    if (!pill.contains(e.target)) closeDropdown();
  });

  // Close on ESC
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeDropdown();
  });
})();
</script>