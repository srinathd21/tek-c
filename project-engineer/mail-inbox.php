<?php
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/includes/gmail-client.php';
require_once __DIR__ . '/vendor/autoload.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}
$employeeId = (int)$_SESSION['employee_id'];

$conn = get_db_connection();
if (!$conn) die("DB Error");

$client = gmailClientForEmployee($conn, $employeeId);

$error = '';
$messages = [];
$gmailEmail = '';

/**
 * Pagination settings
 */
$perPage = 20;

// Search term from query string
$search = isset($_GET['s']) ? trim((string)$_GET['s']) : '';

// Current page token from query string
$pageToken = isset($_GET['pageToken']) ? trim((string)$_GET['pageToken']) : '';

// A stable key to store token history in session per employee + per search query
$sessionKey = 'gmail_inbox_tokens_' . $employeeId . '_' . md5($search);

// Initialize token history stack
if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
  $_SESSION[$sessionKey] = [];
}

// If user changed search, reset token history
$prevSearchKey = 'gmail_inbox_prev_search_' . $employeeId;
if (!isset($_SESSION[$prevSearchKey])) {
  $_SESSION[$prevSearchKey] = $search;
}
if ($_SESSION[$prevSearchKey] !== $search) {
  $_SESSION[$sessionKey] = [];
  $_SESSION[$prevSearchKey] = $search;
}

/**
 * Determine navigation action
 */
$action = isset($_GET['nav']) ? $_GET['nav'] : ''; // 'next' | 'prev' | ''
$prevToken = '';

// Handle Prev: pop current token and get previous
if ($action === 'prev') {
  // Remove current token from history (if present at end)
  if (!empty($_SESSION[$sessionKey])) {
    array_pop($_SESSION[$sessionKey]);
  }
  // The new current pageToken should be the last stored token (or empty for first page)
  $pageToken = !empty($_SESSION[$sessionKey]) ? end($_SESSION[$sessionKey]) : '';
}

// For "Previous" button visibility:
$canGoPrev = !empty($_SESSION[$sessionKey]) && count($_SESSION[$sessionKey]) > 0;

$nextPageToken = '';

if ($client) {
  try {
    $gmail = new Google\Service\Gmail($client);

    $profile = $gmail->users->getProfile('me');
    $gmailEmail = $profile ? (string)$profile->getEmailAddress() : '';

    // Gmail search query (always stays within inbox)
    $q = 'in:inbox';
    if ($search !== '') {
      // This will search Gmail like normal. User can also type advanced operators.
      // Example: from:abc subject:invoice
      $q .= ' ' . $search;
    }

    $opt = [
      'maxResults' => $perPage,
      'q' => $q,
    ];
    if ($pageToken !== '') {
      $opt['pageToken'] = $pageToken;
    }

    $list = $gmail->users_messages->listUsersMessages('me', $opt);
    $msgList = $list->getMessages() ?: [];
    $nextPageToken = (string)($list->getNextPageToken() ?: '');

    // Save token history when moving next or first load (so Prev works)
    // Only push token if it is not already the last one
    if ($pageToken !== '') {
      $last = !empty($_SESSION[$sessionKey]) ? end($_SESSION[$sessionKey]) : null;
      if ($last !== $pageToken) {
        $_SESSION[$sessionKey][] = $pageToken;
      }
    }

    foreach ($msgList as $m) {
      $msg = $gmail->users_messages->get('me', $m->getId(), [
        'format' => 'metadata',
        'metadataHeaders' => ['From','Subject','Date']
      ]);

      $headers = $msg->getPayload()->getHeaders();
      $from = $subject = $date = '';

      foreach ($headers as $h) {
        if ($h->getName() === 'From') $from = $h->getValue();
        if ($h->getName() === 'Subject') $subject = $h->getValue();
        if ($h->getName() === 'Date') $date = $h->getValue();
      }

      $messages[] = [
        'id' => $m->getId(),
        'from' => $from,
        'subject' => $subject ?: '(No Subject)',
        'date' => $date,
        'snippet' => $msg->getSnippet(),
      ];
    }

    // Update Prev visibility AFTER potential push
    $canGoPrev = !empty($_SESSION[$sessionKey]) && count($_SESSION[$sessionKey]) > 0;

  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}

// Build base url params for links
function build_query(array $params): string {
  return http_build_query($params);
}

$baseParams = [];
if ($search !== '') $baseParams['s'] = $search;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Mail Inbox - TEK-C</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    html, body { height: 100%; }
    .app { min-height: 100vh; }
    .main { min-height: 100vh; display: flex; flex-direction: column; }

    .content-scroll {
      flex: 1 1 auto;
      overflow: auto;
      padding: 22px;
      -webkit-overflow-scrolling: touch;
    }

    .mail-meta{ font-size:12px; color:#6b7280; font-weight:600; }
    .mail-subject{ font-weight:800; color:#111827; }
    .mail-snippet{ font-size:13px; color:#374151; }
    .mail-item { text-decoration:none; color: inherit; }
    .mail-item:hover { background: rgba(0,0,0,.03); }

    @media (max-width: 991.98px){
      .main{ margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
      .sidebar{ position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
    }
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
      .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
      .sec-head { padding: 10px !important; border-radius: 12px; }
    }
  </style>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>

  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div class="content-scroll">
      <div class="container-fluid maxw">

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h1 class="h4 fw-bold mb-0">Inbox</h1>
            <div class="text-muted">
              <?php if ($client && $gmailEmail): ?>
                Connected as <b><?= htmlspecialchars($gmailEmail) ?></b>
              <?php else: ?>
                Connect your Gmail to view inbox
              <?php endif; ?>
            </div>
          </div>

          <div class="d-flex gap-2">
            <?php if (!$client): ?>
              <a class="btn btn-primary" href="gmail-connect.php">
                <i class="bi bi-google me-1"></i> Connect Gmail
              </a>
            <?php else: ?>
              <a class="btn btn-outline-secondary" href="gmail-connect.php" title="Reconnect / Re-consent">
                <i class="bi bi-arrow-repeat me-1"></i> Reconnect
              </a>
            <?php endif; ?>
          </div>
        </div>

        <!-- ✅ Search box -->
        <?php if ($client): ?>
          <div class="card mb-3">
            <div class="card-body">
              <form class="row g-2 align-items-center" method="get" action="mail-inbox.php">
                <div class="col-12 col-md-9">
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="s" class="form-control"
                           placeholder="Search inbox (example: from:amazon subject:invoice)"
                           value="<?= htmlspecialchars($search) ?>">
                  </div>
                  <div class="form-text">
                    Tip: You can use Gmail operators like <code>from:</code>, <code>subject:</code>, <code>has:attachment</code>.
                  </div>
                </div>
                <div class="col-12 col-md-3 d-grid d-md-flex gap-2">
                  <button class="btn btn-primary" type="submit">
                    <i class="bi bi-search me-1"></i> Search
                  </button>
                  <a class="btn btn-outline-secondary" href="mail-inbox.php">
                    Clear
                  </a>
                </div>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <?php if (!$client): ?>
          <div class="alert alert-warning">
            <i class="bi bi-info-circle me-2"></i>
            Gmail is not connected. Click <b>Connect Gmail</b> to authorize.
          </div>
        <?php else: ?>

          <!-- ✅ Pagination controls (Top) -->
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="text-muted small">
              Showing <?= count($messages) ?> emails<?= $search !== '' ? ' for search: <b>' . htmlspecialchars($search) . '</b>' : '' ?>
            </div>

            <div class="d-flex gap-2">
              <?php
                // Prev URL
                $prevUrl = 'mail-inbox.php?' . build_query(array_merge($baseParams, [
                  'nav' => 'prev',
                ]));

                // Next URL includes nextPageToken
                $nextUrl = 'mail-inbox.php?' . build_query(array_merge($baseParams, [
                  'nav' => 'next',
                  'pageToken' => $nextPageToken,
                ]));
              ?>

              <a class="btn btn-outline-secondary btn-sm <?= $canGoPrev ? '' : 'disabled' ?>"
                 href="<?= $canGoPrev ? $prevUrl : '#' ?>">
                <i class="bi bi-chevron-left"></i> Prev
              </a>

              <a class="btn btn-outline-secondary btn-sm <?= $nextPageToken !== '' ? '' : 'disabled' ?>"
                 href="<?= $nextPageToken !== '' ? $nextUrl : '#' ?>">
                Next <i class="bi bi-chevron-right"></i>
              </a>
            </div>
          </div>

          <div class="card">
            <div class="card-header fw-bold">Emails</div>
            <div class="list-group list-group-flush">
              <?php if (empty($messages)): ?>
                <div class="list-group-item text-muted">No emails found.</div>
              <?php else: ?>
                <?php foreach ($messages as $m): ?>
                  <a class="list-group-item list-group-item-action mail-item"
                     href="mail-view.php?id=<?= urlencode($m['id']) ?>">
                    <div class="d-flex justify-content-between align-items-start">
                      <div class="mail-subject"><?= htmlspecialchars($m['subject']) ?></div>
                      <div class="mail-meta"><?= htmlspecialchars($m['date']) ?></div>
                    </div>
                    <div class="mail-meta">From: <?= htmlspecialchars($m['from']) ?></div>
                    <div class="mail-snippet mt-1"><?= htmlspecialchars($m['snippet']) ?></div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- ✅ Pagination controls (Bottom) -->
          <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary btn-sm <?= $canGoPrev ? '' : 'disabled' ?>"
               href="<?= $canGoPrev ? $prevUrl : '#' ?>">
              <i class="bi bi-chevron-left"></i> Prev
            </a>

            <a class="btn btn-outline-secondary btn-sm <?= $nextPageToken !== '' ? '' : 'disabled' ?>"
               href="<?= $nextPageToken !== '' ? $nextUrl : '#' ?>">
              Next <i class="bi bi-chevron-right"></i>
            </a>
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