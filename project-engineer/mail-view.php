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

$messageId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if ($messageId === '') {
  header("Location: mail-inbox.php");
  exit;
}

$conn = get_db_connection();
if (!$conn) die("DB Error");

$client = gmailClientForEmployee($conn, $employeeId);

$error = '';
$gmailEmail = '';
$mail = [
  'id' => $messageId,
  'from' => '',
  'to' => '',
  'subject' => '(No Subject)',
  'date' => '',
  'snippet' => '',
  'body_html' => '',
  'body_text' => '',
];

/**
 * Recursively find the best body:
 * - prefer text/html
 * - fallback to text/plain
 *
 * NOTE: gmail_base64url_decode() is already available from includes/gmail-client.php
 */
function gmail_extract_body($payload, &$htmlOut, &$textOut): void {
  if (!$payload) return;

  $mimeType = $payload->getMimeType();
  $body = $payload->getBody();
  $data = $body ? $body->getData() : null;

  if ($data) {
    $decoded = gmail_base64url_decode($data);

    if ($mimeType === 'text/html' && $htmlOut === '') {
      $htmlOut = $decoded;
    } elseif ($mimeType === 'text/plain' && $textOut === '') {
      $textOut = $decoded;
    }
  }

  $parts = $payload->getParts();
  if ($parts) {
    foreach ($parts as $p) {
      gmail_extract_body($p, $htmlOut, $textOut);
    }
  }
}

/**
 * Extract email from "Name <email@x.com>"
 */
function extract_email_address(string $fromHeader): string {
  if (preg_match('/<([^>]+)>/', $fromHeader, $m)) {
    return trim($m[1]);
  }
  return trim($fromHeader);
}

$replyTo = '';
$replySubject = '';

if (!$client) {
  $error = "Gmail is not connected. Please connect Gmail first.";
} else {
  try {
    $gmail = new Google\Service\Gmail($client);

    $profile = $gmail->users->getProfile('me');
    $gmailEmail = $profile ? (string)$profile->getEmailAddress() : '';

    // Full message gives us body parts
    $msg = $gmail->users_messages->get('me', $messageId, [
      'format' => 'full'
    ]);

    $payload = $msg->getPayload();
    $headers = $payload ? ($payload->getHeaders() ?: []) : [];

    foreach ($headers as $h) {
      $name = $h->getName();
      $value = (string)$h->getValue();

      if ($name === 'From') $mail['from'] = $value;
      if ($name === 'To') $mail['to'] = $value;
      if ($name === 'Subject' && $value !== '') $mail['subject'] = $value;
      if ($name === 'Date') $mail['date'] = $value;
    }

    $mail['snippet'] = (string)$msg->getSnippet();

    $html = '';
    $text = '';
    gmail_extract_body($payload, $html, $text);

    $mail['body_html'] = $html;
    $mail['body_text'] = $text;

    // Reply prefill
    $replyTo = extract_email_address($mail['from']);
    $replySubject = $mail['subject'];
    if (stripos($replySubject, 'Re:') !== 0) {
      $replySubject = 'Re: ' . $replySubject;
    }

  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}

// Safer HTML rendering using iframe sandbox
$iframeHtml = '';
if (!empty($mail['body_html'])) {
  // Remove any script tags (extra safety)
  $safeHtml = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $mail['body_html']);
  $iframeHtml = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $safeHtml . '</body></html>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Mail View - TEK-C</title>
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

    .content-scroll{
      flex: 1 1 auto;
      overflow: auto;
      padding: 22px;
      -webkit-overflow-scrolling: touch;
    }

    .mail-meta{ font-size:12px; color:#6b7280; font-weight:600; }
    .mail-subject{ font-weight:900; color:#111827; font-size: 18px; }
    .mail-body{
      font-size: 14px;
      color: #111827;
      line-height: 1.6;
      white-space: normal;
    }

    /* Keep email content inside the card */
    .mail-body img { max-width: 100%; height: auto; }
    .mail-body table { max-width: 100%; }
    .mail-body pre { white-space: pre-wrap; }

    .mail-iframe{
      width: 100%;
      border: 0;
      min-height: 520px;
    }

    @media (max-width: 991.98px){
      .main{ margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
      .sidebar{ position: fixed !important; transform: translateX(-100%); z-index: 1040 !important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform: translateX(0) !important; }
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
            <h1 class="h4 fw-bold mb-0">Email</h1>
            <div class="text-muted">
              <?php if ($client && $gmailEmail): ?>
                Connected as <b><?= htmlspecialchars($gmailEmail) ?></b>
              <?php else: ?>
                Connect your Gmail to view email
              <?php endif; ?>
            </div>
          </div>

          <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="mail-inbox.php">
              <i class="bi bi-arrow-left me-1"></i> Back
            </a>

            <?php if ($client && $replyTo !== ''): ?>
              <a class="btn btn-primary"
                 href="mail-compose.php?to=<?= urlencode($replyTo) ?>&subject=<?= urlencode($replySubject) ?>">
                <i class="bi bi-reply me-1"></i> Reply
              </a>
            <?php elseif (!$client): ?>
              <a class="btn btn-primary" href="gmail-connect.php">
                <i class="bi bi-google me-1"></i> Connect Gmail
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
          </div>
        <?php else: ?>
          <div class="card">
            <div class="card-body">
              <div class="mail-subject mb-2"><?= htmlspecialchars($mail['subject']) ?></div>

              <div class="mail-meta mb-1">From: <?= htmlspecialchars($mail['from']) ?></div>
              <?php if (!empty($mail['to'])): ?>
                <div class="mail-meta mb-1">To: <?= htmlspecialchars($mail['to']) ?></div>
              <?php endif; ?>
              <div class="mail-meta mb-2">Date: <?= htmlspecialchars($mail['date']) ?></div>

              <hr>

              <div class="mail-body">
                <?php if (!empty($iframeHtml)): ?>
                  <iframe class="mail-iframe"
                          sandbox="allow-popups allow-popups-to-escape-sandbox"
                          srcdoc="<?= htmlspecialchars($iframeHtml, ENT_QUOTES) ?>"></iframe>
                <?php elseif (!empty($mail['body_text'])): ?>
                  <pre class="mb-0"><?= htmlspecialchars($mail['body_text']) ?></pre>
                <?php else: ?>
                  <div class="text-muted">No email content found.</div>
                  <?php if (!empty($mail['snippet'])): ?>
                    <div class="mt-2"><?= htmlspecialchars($mail['snippet']) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>

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