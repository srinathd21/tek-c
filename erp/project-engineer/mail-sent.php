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

if ($client) {
  try {
    $gmail = new Google\Service\Gmail($client);

    $profile = $gmail->users->getProfile('me');
    $gmailEmail = $profile ? (string)$profile->getEmailAddress() : '';

    $opt = [
      'maxResults' => 20,
      'q' => 'in:sent',
    ];

    $list = $gmail->users_messages->listUsersMessages('me', $opt);
    $msgList = $list->getMessages() ?: [];

    foreach ($msgList as $m) {
      $msg = $gmail->users_messages->get('me', $m->getId(), [
        'format' => 'metadata',
        'metadataHeaders' => ['From','To','Subject','Date']
      ]);

      $headers = $msg->getPayload()->getHeaders();
      $from = $to = $subject = $date = '';

      foreach ($headers as $h) {
        if ($h->getName() === 'From') $from = $h->getValue();
        if ($h->getName() === 'To') $to = $h->getValue();
        if ($h->getName() === 'Subject') $subject = $h->getValue();
        if ($h->getName() === 'Date') $date = $h->getValue();
      }

      $messages[] = [
        'id' => $m->getId(),
        'from' => $from,
        'to' => $to,
        'subject' => $subject ?: '(No Subject)',
        'date' => $date,
        'snippet' => $msg->getSnippet(),
      ];
    }

  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sent Mail - TEK-C</title>
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
      .main{
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
      }
      .sidebar{
        position: fixed !important;
        transform: translateX(-100%);
        z-index: 1040 !important;
      }
      .sidebar.open, .sidebar.active, .sidebar.show{
        transform: translateX(0) !important;
      }
    }
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px 12px !important; }
      .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
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
            <h1 class="h4 fw-bold mb-0">Sent</h1>
            <div class="text-muted">
              <?php if ($client && $gmailEmail): ?>
                Connected as <b><?= htmlspecialchars($gmailEmail) ?></b>
              <?php else: ?>
                Connect your Gmail to view sent mails
              <?php endif; ?>
            </div>
          </div>

          <div class="d-flex gap-2">
            <a class="btn btn-primary" href="mail-compose.php">
              <i class="bi bi-pencil-square me-1"></i> Compose
            </a>

            <?php if (!$client): ?>
              <a class="btn btn-outline-secondary" href="gmail-connect.php">
                <i class="bi bi-google me-1"></i> Connect Gmail
              </a>
            <?php else: ?>
              <a class="btn btn-outline-secondary" href="gmail-connect.php" title="Reconnect / Re-consent">
                <i class="bi bi-arrow-repeat me-1"></i> Reconnect
              </a>
            <?php endif; ?>
          </div>
        </div>

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
          <div class="card">
            <div class="card-header fw-bold">Latest Sent Emails</div>
            <div class="list-group list-group-flush">
              <?php if (empty($messages)): ?>
                <div class="list-group-item text-muted">No sent emails found.</div>
              <?php else: ?>
                <?php foreach ($messages as $m): ?>
                  <a class="list-group-item list-group-item-action mail-item"
                     href="mail-view.php?id=<?= urlencode($m['id']) ?>">
                    <div class="d-flex justify-content-between align-items-start">
                      <div class="mail-subject"><?= htmlspecialchars($m['subject']) ?></div>
                      <div class="mail-meta"><?= htmlspecialchars($m['date']) ?></div>
                    </div>
                    <div class="mail-meta">To: <?= htmlspecialchars($m['to']) ?></div>
                    <div class="mail-meta">From: <?= htmlspecialchars($m['from']) ?></div>
                    <div class="mail-snippet mt-1"><?= htmlspecialchars($m['snippet']) ?></div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
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