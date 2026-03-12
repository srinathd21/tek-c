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
$success = '';
$gmailEmail = '';

$to = '';
$cc = '';
$subject = '';
$body = '';

$pdfRel = '';
$pdfName = 'report.pdf';

// Prefill from query params (optional)
if (isset($_GET['to'])) $to = trim((string)$_GET['to']);
if (isset($_GET['cc'])) $cc = trim((string)$_GET['cc']);
if (isset($_GET['subject'])) $subject = trim((string)$_GET['subject']);
if (isset($_GET['body'])) $body = trim((string)$_GET['body']);
if (isset($_GET['pdf'])) $pdfRel = trim((string)$_GET['pdf']);
if (isset($_GET['pdf_name'])) $pdfName = trim((string)$_GET['pdf_name']);

// ---------------- HELPERS ----------------
function parse_emails(string $list): array {
  $list = trim($list);
  if ($list === '') return [];
  $parts = preg_split('/\s*,\s*/', $list, -1, PREG_SPLIT_NO_EMPTY);
  $valid = [];
  foreach ($parts as $e) {
    $e = trim($e);
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $valid[] = $e;
  }
  return array_values(array_unique($valid));
}

function detect_mime_type(string $filepath): string {
  if (!is_file($filepath)) return 'application/octet-stream';
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $mime = finfo_file($finfo, $filepath);
      finfo_close($finfo);
      if (is_string($mime) && $mime !== '') return $mime;
    }
  }
  return 'application/octet-stream';
}

function safe_filename(string $name): string {
  $name = basename($name);
  $name = str_replace(["\r", "\n", '"'], ['_', '_', "'"], $name);
  return $name === '' ? 'attachment' : $name;
}

/**
 * ✅ Allow only known print scripts.
 * We accept the file name (no folder traversal).
 */
function is_allowed_pdf_rel(string $rel): bool {
  $rel = trim($rel);
  if ($rel === '') return false;

  $parts = explode('?', $rel, 2);
  $file = $parts[0] ?? '';

  // no directory traversal
  if ($file !== basename($file)) return false;

  $allowedFiles = [
    'report-print.php',
    'report-dar-print.php',
    'report-ma-print.php',
    'report-mom-print.php',
    'report-mpt-print.php',
    // ✅ Accept both naming conventions for checklist
    'report-checklist-print.php',
    'checklist-report-print.php',
  ];

  if (!in_array($file, $allowedFiles, true)) return false;

  $q = [];
  if (isset($parts[1])) parse_str($parts[1], $q);

  if (!isset($q['view'])) return false;
  if (!ctype_digit((string)$q['view'])) return false;

  return true;
}

/**
 * ✅ Find the print file in known locations.
 * Add/remove folders here based on your project.
 */
function resolve_print_file_path(string $file): string {
  $file = basename($file);

  $candidates = [
    __DIR__ . '/' . $file,                    // Root directory
    __DIR__ . '/reports/' . $file,
    __DIR__ . '/print/' . $file,
    __DIR__ . '/pdf/' . $file,
    __DIR__ . '/pages/' . $file,
    __DIR__ . '/admin/' . $file,
    __DIR__ . '/modules/' . $file,
  ];

  foreach ($candidates as $p) {
    $rp = realpath($p);
    if ($rp && is_file($rp)) return $rp;
  }
  return '';
}

/**
 * ✅ Generate PDF bytes by including the print PHP locally using mode=string.
 * Avoids session deadlock (no HTTP call).
 */
function generate_pdf_bytes_locally(string $rel): array {
  $rel = trim($rel);
  if ($rel === '') throw new Exception("Empty PDF reference.");

  $parts = explode('?', $rel, 2);
  $file = $parts[0] ?? '';
  $qs = $parts[1] ?? '';

  $q = [];
  if ($qs !== '') parse_str($qs, $q);

  $view = isset($q['view']) ? (int)$q['view'] : 0;
  if ($view <= 0) throw new Exception("Invalid view id for PDF.");

  $fullPath = resolve_print_file_path($file);
  if ($fullPath === '') {
    throw new Exception("PDF print file not found: " . $file);
  }

  // Backup superglobals
  $oldGet = $_GET;
  $oldRequest = $_REQUEST;

  // clear old results
  unset($GLOBALS['__CHECKLIST_PDF_RESULT__']);

  // Force string mode
  $_GET = ['view' => $view, 'mode' => 'string'];
  if (isset($q['dl'])) $_GET['dl'] = $q['dl'];
  $_REQUEST = $_GET;

  ob_start();
  try {
    include $fullPath;
  } finally {
    ob_end_clean();
    $_GET = $oldGet;
    $_REQUEST = $oldRequest;
  }

  // ✅ checklist script returns here
  if (isset($GLOBALS['__CHECKLIST_PDF_RESULT__'])
      && is_array($GLOBALS['__CHECKLIST_PDF_RESULT__'])
      && !empty($GLOBALS['__CHECKLIST_PDF_RESULT__']['bytes'])) {
    return [
      'bytes' => $GLOBALS['__CHECKLIST_PDF_RESULT__']['bytes'],
      'filename' => $GLOBALS['__CHECKLIST_PDF_RESULT__']['filename'] ?? 'report.pdf',
    ];
  }

  throw new Exception("PDF script did not return bytes in string mode. Ensure it supports ?mode=string.");
}

/**
 * Build RFC 2822 raw email (multipart/mixed for attachments)
 */
function build_raw_email(
  string $from,
  array $toList,
  array $ccList,
  string $subject,
  string $bodyText,
  array $attachments = []
): string {
  $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

  $headers = [];
  $headers[] = "From: {$from}";
  $headers[] = "To: " . implode(', ', $toList);
  if (!empty($ccList)) $headers[] = "Cc: " . implode(', ', $ccList);
  $headers[] = "Subject: {$encodedSubject}";
  $headers[] = "MIME-Version: 1.0";

  if (empty($attachments)) {
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";
    return implode("\r\n", $headers) . "\r\n\r\n" . $bodyText;
  }

  $boundary = '=_tekc_' . bin2hex(random_bytes(12));
  $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

  $msg = [];
  $msg[] = implode("\r\n", $headers);
  $msg[] = "";

  // Body
  $msg[] = "--{$boundary}";
  $msg[] = "Content-Type: text/plain; charset=UTF-8";
  $msg[] = "Content-Transfer-Encoding: 8bit";
  $msg[] = "";
  $msg[] = $bodyText;
  $msg[] = "";

  // Attachments
  foreach ($attachments as $att) {
    $path = $att['path'] ?? '';
    $name = safe_filename((string)($att['name'] ?? 'attachment'));
    $mime = (string)($att['mime'] ?? 'application/octet-stream');
    if ($path === '' || !is_file($path)) continue;

    $content = file_get_contents($path);
    if ($content === false) continue;

    $b64 = chunk_split(base64_encode($content), 76, "\r\n");

    $msg[] = "--{$boundary}";
    $msg[] = "Content-Type: {$mime}; name=\"{$name}\"";
    $msg[] = "Content-Disposition: attachment; filename=\"{$name}\"";
    $msg[] = "Content-Transfer-Encoding: base64";
    $msg[] = "";
    $msg[] = $b64;
    $msg[] = "";
  }

  $msg[] = "--{$boundary}--";
  $msg[] = "";

  return implode("\r\n", $msg);
}

// ---------------- Gmail setup ----------------
if (!$client) {
  $error = "Gmail is not connected. Please connect Gmail first.";
} else {
  try {
    $gmail = new Google\Service\Gmail($client);
    $profile = $gmail->users->getProfile('me');
    $gmailEmail = $profile ? (string)$profile->getEmailAddress() : '';
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}

// ---------------- SEND ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $to      = trim((string)($_POST['to'] ?? ''));
  $cc      = trim((string)($_POST['cc'] ?? ''));
  $subject = trim((string)($_POST['subject'] ?? ''));
  $body    = trim((string)($_POST['body'] ?? ''));

  $pdfRel  = trim((string)($_POST['pdf'] ?? $pdfRel));
  $pdfName = trim((string)($_POST['pdf_name'] ?? $pdfName));

  $toList = parse_emails($to);
  $ccList = parse_emails($cc);

  if (!$client) {
    $error = "Gmail is not connected. Please connect Gmail first.";
  } elseif (empty($toList) || $subject === '' || $body === '') {
    $error = "Please fill To, Subject, and Message. (To must contain at least one valid email)";
  } else {
    $tempFilesToDelete = [];

    try {
      $gmail = new Google\Service\Gmail($client);

      if ($gmailEmail === '') {
        $profile = $gmail->users->getProfile('me');
        $gmailEmail = $profile ? (string)$profile->getEmailAddress() : '';
      }
      if ($gmailEmail === '') throw new Exception("Unable to detect sender Gmail address.");

      $attachments = [];

      // ✅ Auto attach PDF (LOCAL generation, no HTTP)
      if ($pdfRel !== '' && is_allowed_pdf_rel($pdfRel)) {
        $result = generate_pdf_bytes_locally($pdfRel);
        $pdfBytes = $result['bytes'];
        $serverFileName = $result['filename'] ?? 'report.pdf';

        $tmp = tempnam(sys_get_temp_dir(), 'tekc_pdf_');
        if ($tmp === false) throw new Exception("Unable to create temp file for PDF.");
        file_put_contents($tmp, $pdfBytes);

        $tempFilesToDelete[] = $tmp;

        $finalName = ($pdfName !== '') ? $pdfName : $serverFileName;

        $attachments[] = [
          'path' => $tmp,
          'name' => safe_filename($finalName),
          'mime' => 'application/pdf',
        ];
      }

      // ✅ Uploaded attachments
      if (!empty($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $names = $_FILES['attachments']['name'];
        $tmpNames = $_FILES['attachments']['tmp_name'];
        $errors = $_FILES['attachments']['error'];
        $sizes = $_FILES['attachments']['size'];

        $maxFiles = 5;
        $maxSizeEach = 10 * 1024 * 1024;

        $count = min(count($names), $maxFiles);
        for ($i = 0; $i < $count; $i++) {
          if (!isset($errors[$i]) || $errors[$i] === UPLOAD_ERR_NO_FILE) continue;
          if ($errors[$i] !== UPLOAD_ERR_OK) continue;

          $tmp = $tmpNames[$i] ?? '';
          $nm = $names[$i] ?? 'attachment';
          $sz = (int)($sizes[$i] ?? 0);

          if ($tmp === '' || !is_uploaded_file($tmp)) continue;
          if ($sz <= 0) continue;
          if ($sz > $maxSizeEach) throw new Exception("Attachment '{$nm}' exceeds 10MB.");

          $mime = detect_mime_type($tmp);
          $attachments[] = ['path' => $tmp, 'name' => $nm, 'mime' => $mime];
        }
      }

      $raw = build_raw_email($gmailEmail, $toList, $ccList, $subject, $body, $attachments);

      $gMessage = new Google\Service\Gmail\Message();
      $gMessage->setRaw(gmail_base64url_encode($raw));
      $gmail->users_messages->send('me', $gMessage);

      $success = "Email sent successfully.";
      $to = $cc = $subject = $body = '';
      $pdfRel = '';
      $pdfName = 'report.pdf';

    } catch (Exception $e) {
      $error = $e->getMessage();
    } finally {
      foreach ($tempFilesToDelete as $f) {
        if (is_string($f) && $f !== '' && is_file($f)) @unlink($f);
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Compose Mail - TEK-C</title>
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
    .content-scroll { flex: 1 1 auto; overflow: auto; padding: 22px; -webkit-overflow-scrolling: touch; }

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
            <h1 class="h4 fw-bold mb-0">Compose</h1>
            <div class="text-muted">
              <?php if ($client && $gmailEmail): ?>
                Connected as <b><?= htmlspecialchars($gmailEmail) ?></b>
              <?php else: ?>
                Connect your Gmail to send emails
              <?php endif; ?>
            </div>
          </div>

          <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="mail-inbox.php">
              <i class="bi bi-arrow-left me-1"></i> Back to Inbox
            </a>
            <?php if (!$client): ?>
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
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-body">
            <form method="post" action="mail-compose.php" autocomplete="off" enctype="multipart/form-data">

              <input type="hidden" name="pdf" value="<?= htmlspecialchars($pdfRel) ?>">
              <input type="hidden" name="pdf_name" value="<?= htmlspecialchars($pdfName) ?>">

              <div class="mb-3">
                <label class="form-label fw-semibold">To</label>
                <input type="text" name="to" class="form-control"
                       placeholder="recipient@example.com, another@example.com"
                       value="<?= htmlspecialchars($to) ?>" required>
                <div class="form-text">Multiple emails separated by comma.</div>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold">Cc</label>
                <input type="text" name="cc" class="form-control"
                       placeholder="cc1@example.com, cc2@example.com"
                       value="<?= htmlspecialchars($cc) ?>">
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold">Subject</label>
                <input type="text" name="subject" class="form-control" placeholder="Subject"
                       value="<?= htmlspecialchars($subject) ?>" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold">Message</label>
                <textarea name="body" class="form-control" rows="10" placeholder="Write your message..." required><?= htmlspecialchars($body) ?></textarea>
              </div>

              <?php if ($pdfRel !== '' && is_allowed_pdf_rel($pdfRel)): ?>
                <div class="alert alert-info" style="border-radius:12px;">
                  <i class="bi bi-paperclip me-2"></i>
                  Auto-attached PDF: <b><?= htmlspecialchars($pdfName) ?></b>
                </div>
              <?php endif; ?>

              <div class="mb-3">
                <label class="form-label fw-semibold">Attachments (optional)</label>
                <input class="form-control" type="file" name="attachments[]" multiple>
                <div class="form-text">You can attach up to 5 files (max 10MB each).</div>
              </div>

              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                  <i class="bi bi-send me-1"></i> Send
                </button>
                <a class="btn btn-outline-secondary" href="mail-inbox.php">Cancel</a>
              </div>
            </form>
          </div>
        </div>

        <div class="text-muted small mt-3">
          Note: This sends a plain-text email using Gmail API. Attachments are sent as multipart/mixed.
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