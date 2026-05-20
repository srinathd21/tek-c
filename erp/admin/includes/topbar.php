<?php
// admin/includes/topbar.php
// IMPORTANT: Do NOT call session_start() here.
// session_start() must be in the main page before any output.

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/notification-helper.php';

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

// ---- Session values ----
$loggedName  = $_SESSION['employee_name']  ?? ($_SESSION['name'] ?? 'User');
$loggedEmail = $_SESSION['employee_email'] ?? ($_SESSION['email'] ?? '');
$loggedUser  = $_SESSION['username']       ?? '';
$loggedPhoto = $_SESSION['employee_photo'] ?? '';

// ---- If email/photo missing in session, fetch from DB ----
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

        if (trim($loggedEmail) === '' && !empty($row['mobile_number'])) {
          $loggedEmail = $row['mobile_number'];
        }
      }

      mysqli_stmt_close($st);
    }

    mysqli_close($conn);
  }
}

$displayMail = trim($loggedEmail) !== '' ? $loggedEmail : ($loggedUser !== '' ? $loggedUser : '—');

// ---- Photo URL ----
$showPhoto = false;
$photoSrc  = '';

if (trim($loggedPhoto) !== '') {
  $stored = ltrim($loggedPhoto, '/');

  if (strpos($stored, 'admin/') === 0) {
    $photoSrc = '/' . $stored;
  } else {
    $photoSrc = '/admin/' . $stored;
  }

  $showPhoto = true;
}

$avatarText = initials($loggedName);

// ---- Notifications for logged employee ----
$topbarNotifications = [];
$topbarUnreadCount = 0;

if ($employeeId > 0) {
  $conn = get_db_connection();

  if ($conn) {
    $topbarUnreadCount = getUnreadNotificationCount($conn, $employeeId);
    $topbarNotifications = getEmployeeNotifications($conn, $employeeId, 5);
    mysqli_close($conn);
  }
}

// logout for pages under admin folder:
$logoutUrl = '../logout.php';
// If logout.php is inside admin folder, use:
// $logoutUrl = 'logout.php';
?>

<!-- Topbar -->
<div class="topbar">
  <div class="top-left">
    <button id="menuBtn" class="hamburger" aria-label="Toggle sidebar" title="Toggle sidebar">
      <i class="bi bi-list"></i>
    </button>

  </div>

  <div class="top-right">

    <!-- Notifications -->
    <div class="topbar-dropdown-wrap">
      <button id="notificationBtn" class="icon-btn notification-btn" aria-label="Notifications" title="Notifications" type="button">
        <i class="bi bi-bell"></i>
        <?php if ($topbarUnreadCount > 0): ?>
          <span class="notify-count-badge">
            <?php echo $topbarUnreadCount > 99 ? '99+' : (int)$topbarUnreadCount; ?>
          </span>
        <?php endif; ?>
      </button>

      <div id="notificationDropdown" class="topbar-dropdown notification-dropdown">
        <div class="dropdown-head">
          <div>
            <div class="dropdown-title">Notifications</div>
            <div class="dropdown-subtitle">Latest messages for you</div>
          </div>
          <span class="dropdown-count"><?php echo (int)$topbarUnreadCount; ?></span>
        </div>

        <div class="notification-list">
          <?php if (!empty($topbarNotifications)): ?>
            <?php foreach ($topbarNotifications as $notification): ?>
              <?php
                [$iconColor, $iconName] = notificationIconClass($notification['module'] ?? '', $notification['type'] ?? '');
                $notificationLink = trim((string)($notification['link'] ?? ''));
                if ($notificationLink === '') {
                  $notificationLink = 'notifications.php';
                }
                $isUnread = isset($notification['is_read']) && (int)$notification['is_read'] === 0;
              ?>
              <a href="<?php echo e($notificationLink); ?>"
                 class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>">
                <div class="notification-icon <?php echo e($iconColor); ?>">
                  <i class="bi <?php echo e($iconName); ?>"></i>
                </div>
                <div class="notification-content">
                  <div class="notification-title">
                    <?php echo e($notification['title'] ?? 'Notification'); ?>
                  </div>
                  <div class="notification-text">
                    <?php echo e($notification['message'] ?? ''); ?>
                  </div>
                  <div class="notification-time">
                    <?php echo e(notificationTimeAgo($notification['created_at'] ?? '')); ?>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="notification-empty">
              <i class="bi bi-bell"></i>
              <div>No notifications</div>
              <small>New messages will appear here.</small>
            </div>
          <?php endif; ?>
        </div>

        <div class="dropdown-footer">
          <a href="notifications.php" class="see-more-btn">See more</a>
        </div>
      </div>
    </div>

    <!-- Profile Dropdown -->
    <div class="topbar-dropdown-wrap">
      <button id="profileBtn" class="pill profile-btn" title="<?php echo e($loggedName); ?>" type="button">
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

        <i class="bi bi-chevron-down profile-chevron"></i>
      </button>

      <div id="profileDropdown" class="topbar-dropdown profile-dropdown">
        <div class="profile-card-head">
          <div class="profile-big-avatar">
            <?php if ($showPhoto): ?>
              <img src="<?php echo e($photoSrc); ?>"
                   alt="<?php echo e($loggedName); ?>"
                   onerror="this.style.display='none'; this.parentElement.textContent='<?php echo e($avatarText); ?>';">
            <?php else: ?>
              <?php echo e($avatarText); ?>
            <?php endif; ?>
          </div>

          <div>
            <div class="profile-name"><?php echo e($loggedName); ?></div>
            <div class="profile-email"><?php echo e($displayMail); ?></div>
          </div>
        </div>

        <div class="profile-menu">
          <a href="my-profile.php" class="profile-menu-item">
            <i class="bi bi-person"></i>
            <span>My Profile</span>
          </a>

          <a href="my-attendance.php" class="profile-menu-item">
            <i class="bi bi-calendar2-check"></i>
            <span>My Attendance</span>
          </a>

          <a href="apply-leave.php" class="profile-menu-item">
            <i class="bi bi-calendar-plus"></i>
            <span>Apply Leave</span>
          </a>

          <!-- <a href="settings.php" class="profile-menu-item">
            <i class="bi bi-gear"></i>
            <span>Settings</span>
          </a> -->

          <a href="logout.php"
             class="profile-menu-item logout-item"
             onclick="return confirm('Do you want to logout?');">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
          </a>
        </div>
      </div>
    </div>

  </div>
</div>

<style>
  .topbar-dropdown-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
  }

  .notification-btn {
    position: relative;
    overflow: visible;
  }

  .notify-count-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    background: #ef4444;
    color: #fff;
    border: 2px solid #fff;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 950;
    line-height: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 10px rgba(239, 68, 68, .28);
    z-index: 2;
  }

  .profile-btn {
    border: 0;
    cursor: pointer;
  }

  .profile-chevron {
    color: #6b7280;
    transition: transform .2s ease;
  }

  .profile-btn.active .profile-chevron {
    transform: rotate(180deg);
  }

  .topbar-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: 320px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, .14);
    z-index: 99999;
    opacity: 0;
    visibility: hidden;
    transform: translateY(8px);
    transition: .18s ease;
    overflow: hidden;
  }

  .topbar-dropdown.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
  }

  .dropdown-head {
    padding: 14px 15px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .dropdown-title {
    font-size: 14px;
    font-weight: 900;
    color: #111827;
    line-height: 1.2;
  }

  .dropdown-subtitle {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    margin-top: 2px;
  }

  .dropdown-count {
    min-width: 24px;
    height: 24px;
    border-radius: 999px;
    background: #eff6ff;
    color: #2563eb;
    font-size: 11px;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .notification-list {
    max-height: 310px;
    overflow-y: auto;
  }

  .notification-item {
    display: flex;
    gap: 10px;
    padding: 12px 15px;
    text-decoration: none;
    border-bottom: 1px solid #f8fafc;
    transition: .15s ease;
  }

  .notification-item:hover {
    background: #f8fafc;
  }

  .notification-item.unread {
    background: #f8fbff;
  }

  .notification-item.unread .notification-title::after {
    content: "";
    width: 6px;
    height: 6px;
    background: #2563eb;
    border-radius: 999px;
    display: inline-block;
    margin-left: 6px;
    vertical-align: middle;
  }

  .notification-empty {
    padding: 24px 14px;
    text-align: center;
    color: #64748b;
    font-size: 12px;
    font-weight: 850;
  }

  .notification-empty i {
    display: block;
    font-size: 28px;
    opacity: .45;
    margin-bottom: 7px;
  }

  .notification-empty small {
    display: block;
    margin-top: 2px;
    font-size: 10.5px;
    font-weight: 700;
    color: #94a3b8;
  }

  .notification-icon {
    width: 34px;
    height: 34px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    font-size: 15px;
  }

  .notification-icon.blue {
    background: #eff6ff;
    color: #2563eb;
  }

  .notification-icon.green {
    background: #ecfdf5;
    color: #16a34a;
  }

  .notification-icon.orange {
    background: #fff7ed;
    color: #ea580c;
  }

  .notification-content {
    min-width: 0;
  }

  .notification-title {
    font-size: 12px;
    font-weight: 900;
    color: #111827;
    line-height: 1.25;
  }

  .notification-text {
    font-size: 11px;
    font-weight: 650;
    color: #64748b;
    margin-top: 2px;
    line-height: 1.35;
  }

  .notification-time {
    font-size: 10px;
    font-weight: 800;
    color: #94a3b8;
    margin-top: 4px;
  }

  .dropdown-footer {
    padding: 10px;
    border-top: 1px solid #f1f5f9;
    background: #fff;
  }

  .see-more-btn {
    width: 100%;
    height: 34px;
    border-radius: 11px;
    background: #111827;
    color: #fff;
    text-decoration: none;
    font-size: 12px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: .15s ease;
  }

  .see-more-btn:hover {
    background: #020617;
    color: #fff;
  }

  .profile-dropdown {
    width: 280px;
  }

  .profile-card-head {
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 11px;
    border-bottom: 1px solid #f1f5f9;
    background: #fbfdff;
  }

  .profile-big-avatar {
    width: 44px;
    height: 44px;
    border-radius: 15px;
    background: #111827;
    color: #fff;
    display: grid;
    place-items: center;
    font-size: 15px;
    font-weight: 900;
    overflow: hidden;
    flex: 0 0 auto;
  }

  .profile-big-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .profile-name {
    font-size: 13px;
    font-weight: 900;
    color: #111827;
    line-height: 1.2;
  }

  .profile-email {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    margin-top: 2px;
    max-width: 185px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .profile-menu {
    padding: 8px;
  }

  .profile-menu-item {
    min-height: 38px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    text-decoration: none;
    color: #334155;
    font-size: 12px;
    font-weight: 850;
    transition: .15s ease;
  }

  .profile-menu-item i {
    font-size: 15px;
    width: 18px;
    text-align: center;
    color: #64748b;
  }

  .profile-menu-item:hover {
    background: #f8fafc;
    color: #111827;
  }

  .logout-item {
    color: #dc2626;
  }

  .logout-item i {
    color: #dc2626;
  }

  .logout-item:hover {
    background: #fef2f2;
    color: #b91c1c;
  }

  @media (max-width: 575.98px) {
    .notify-count-badge {
      top: -5px;
      right: -5px;
    }

    .topbar-dropdown {
      position: fixed;
      top: 70px;
      right: 12px;
      left: 12px;
      width: auto;
    }

    .profile-dropdown,
    .notification-dropdown {
      width: auto;
    }
  }
</style>

<script>
  document.addEventListener("DOMContentLoaded", function () {
    const notificationBtn = document.getElementById("notificationBtn");
    const notificationDropdown = document.getElementById("notificationDropdown");

    const profileBtn = document.getElementById("profileBtn");
    const profileDropdown = document.getElementById("profileDropdown");

    function closeTopbarDropdowns() {
      if (notificationDropdown) notificationDropdown.classList.remove("show");
      if (profileDropdown) profileDropdown.classList.remove("show");
      if (profileBtn) profileBtn.classList.remove("active");
    }

    if (notificationBtn && notificationDropdown) {
      notificationBtn.addEventListener("click", function (e) {
        e.stopPropagation();

        const isOpen = notificationDropdown.classList.contains("show");

        closeTopbarDropdowns();

        if (!isOpen) {
          notificationDropdown.classList.add("show");
        }
      });
    }

    if (profileBtn && profileDropdown) {
      profileBtn.addEventListener("click", function (e) {
        e.stopPropagation();

        const isOpen = profileDropdown.classList.contains("show");

        closeTopbarDropdowns();

        if (!isOpen) {
          profileDropdown.classList.add("show");
          profileBtn.classList.add("active");
        }
      });
    }

    document.addEventListener("click", function () {
      closeTopbarDropdowns();
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        closeTopbarDropdowns();
      }
    });

    if (notificationDropdown) {
      notificationDropdown.addEventListener("click", function (e) {
        e.stopPropagation();
      });
    }

    if (profileDropdown) {
      profileDropdown.addEventListener("click", function (e) {
        e.stopPropagation();
      });
    }
  });
</script>