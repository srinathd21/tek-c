<?php
// notifications.php
// Common notifications listing page for all panels.
// Place this file in each panel folder or adjust include paths as needed.

session_start();
require_once 'includes/db-config.php';
require_once 'includes/notification-helper.php';

if (empty($_SESSION['employee_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!function_exists('e')) {
    function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$employeeId = (int)$_SESSION['employee_id'];
$conn = get_db_connection();

if (!$conn) {
    die('Database connection failed.');
}

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    if (nh_table_exists($conn, 'notifications') && nh_column_exists($conn, 'notifications', 'is_read')) {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE employee_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $employeeId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    header('Location: notifications.php');
    exit;
}

// Mark single as read and redirect
if (isset($_GET['read'])) {
    $notificationId = (int)$_GET['read'];

    if ($notificationId > 0 && nh_column_exists($conn, 'notifications', 'is_read')) {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE id = ? AND employee_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $notificationId, $employeeId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

$notifications = getEmployeeNotifications($conn, $employeeId, 20);
$unreadCount = getUnreadNotificationCount($conn, $employeeId);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Notifications - TEK-C</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/layout-styles.css" rel="stylesheet">
  <link href="assets/css/topbar.css" rel="stylesheet">
  <link href="assets/css/footer.css" rel="stylesheet">

  <style>
    :root{
      --page-bg:#f5f7fb;
      --card-bg:#fff;
      --border:#e5e7eb;
      --text:#111827;
      --muted:#6b7280;
      --shadow:0 10px 26px rgba(15,23,42,.055);
      --radius:15px;
    }

    body{background:var(--page-bg);}
    .content-scroll{flex:1 1 auto;overflow:auto;padding:16px;}
    .projects-wrapper{width:100%;}

    .page-heading{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }

    .page-heading h1{font-size:19px;font-weight:900;color:var(--text);margin:0;}
    .page-heading p{margin:3px 0 0;color:var(--muted);font-size:12px;font-weight:600;}

    .primary-btn,.secondary-btn{
      min-height:36px;
      padding:0 14px;
      border-radius:11px;
      font-size:12px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:7px;
      text-decoration:none;
      white-space:nowrap;
    }

    .primary-btn{border:0;background:#111827;color:#fff;}
    .primary-btn:hover{background:#020617;color:#fff;}
    .secondary-btn{border:1px solid var(--border);background:#fff;color:#334155;}
    .secondary-btn:hover{background:#f8fafc;color:#111827;}

    .panel{
      background:var(--card-bg);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:13px;
    }

    .notification-row{
      display:flex;
      gap:12px;
      padding:13px;
      border:1px solid var(--border);
      border-radius:14px;
      background:#fff;
      text-decoration:none;
      color:inherit;
      margin-bottom:10px;
      transition:.15s ease;
    }

    .notification-row:hover{
      border-color:#cbd5e1;
      background:#fbfdff;
      color:inherit;
    }

    .notification-row.unread{
      background:#f8fbff;
      border-color:#bfdbfe;
    }

    .notification-icon{
      width:38px;
      height:38px;
      border-radius:13px;
      display:grid;
      place-items:center;
      flex:0 0 auto;
      font-size:16px;
    }

    .notification-icon.blue{background:#eff6ff;color:#2563eb;}
    .notification-icon.green{background:#ecfdf5;color:#16a34a;}
    .notification-icon.orange{background:#fff7ed;color:#ea580c;}

    .notification-title{
      font-size:13px;
      font-weight:950;
      color:#111827;
      margin:0;
    }

    .notification-text{
      font-size:12px;
      color:#64748b;
      font-weight:700;
      margin-top:3px;
      line-height:1.4;
    }

    .notification-time{
      font-size:10.5px;
      color:#94a3b8;
      font-weight:800;
      margin-top:5px;
    }

    .badge-pill{
      border-radius:999px;
      padding:5px 8px;
      font-weight:900;
      font-size:10px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      border:1px solid #ddd6fe;
      background:#ede9fe;
      color:#6d28d9;
    }

    .empty-state{
      text-align:center;
      padding:34px 12px;
      color:#64748b;
      font-size:12px;
      font-weight:900;
    }

    .empty-state i{
      display:block;
      font-size:36px;
      opacity:.45;
      margin-bottom:8px;
    }

    @media(max-width:991.98px){
      .main{margin-left:0!important;width:100%!important;max-width:100%!important;}
      .sidebar{position:fixed!important;transform:translateX(-100%);z-index:1040!important;}
      .sidebar.open,.sidebar.active,.sidebar.show{transform:translateX(0)!important;}
    }

    @media(max-width:768px){
      .content-scroll{padding:12px 10px!important;}
      .page-heading{align-items:flex-start;flex-direction:column;}
      .primary-btn,.secondary-btn{width:100%;}
    }
  </style>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>

  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div class="content-scroll">
      <div class="container-fluid projects-wrapper px-0">
        <div class="page-heading">
          <div>
            <h1>Notifications</h1>
            <p>Messages and approval updates assigned to you.</p>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill">
              <i class="bi bi-bell"></i>
              <?php echo (int)$unreadCount; ?> Unread
            </span>

            <form method="POST" class="m-0">
              <input type="hidden" name="action" value="mark_all_read">
              <button type="submit" class="secondary-btn">
                <i class="bi bi-check2-all"></i>
                Mark all read
              </button>
            </form>
          </div>
        </div>

        <div class="panel">
          <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $notification): ?>
              <?php
                [$iconColor, $iconName] = notificationIconClass($notification['module'] ?? '', $notification['type'] ?? '');
                $isUnread = isset($notification['is_read']) && (int)$notification['is_read'] === 0;
                $link = trim((string)($notification['link'] ?? ''));
                if ($link === '') {
                    $link = 'notifications.php';
                }
                $id = (int)($notification['id'] ?? 0);
                $href = $id > 0 ? 'notifications.php?read=' . $id : $link;
              ?>

              <a href="<?php echo e($href); ?>" class="notification-row <?php echo $isUnread ? 'unread' : ''; ?>">
                <div class="notification-icon <?php echo e($iconColor); ?>">
                  <i class="bi <?php echo e($iconName); ?>"></i>
                </div>
                <div class="flex-grow-1">
                  <h2 class="notification-title"><?php echo e($notification['title'] ?? 'Notification'); ?></h2>
                  <div class="notification-text"><?php echo e($notification['message'] ?? ''); ?></div>
                  <div class="notification-time"><?php echo e(notificationTimeAgo($notification['created_at'] ?? '')); ?></div>
                </div>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-state">
              <i class="bi bi-bell"></i>
              No notifications yet.
            </div>
          <?php endif; ?>
        </div>
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
if ($conn) {
    mysqli_close($conn);
}
?>