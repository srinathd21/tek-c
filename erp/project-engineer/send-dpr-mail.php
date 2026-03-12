<?php
// send-dpr-mail.php — Send DPR PDF to Manager + Client (attachment)

session_start();
require_once __DIR__ . '/includes/db-config.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId = (int)$_SESSION['employee_id'];
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid DPR id");

$conn = get_db_connection();
if (!$conn) die("DB connection failed");

// 1) Load DPR + emails (manager + client)
$sql = "
  SELECT
    r.id, r.dpr_no, r.dpr_date, r.employee_id,
    s.project_name, s.manager_employee_id,
    c.client_name, c.email AS client_email,
    m.email AS manager_email,
    m.full_name AS manager_name
  FROM dpr_reports r
  INNER JOIN sites s ON s.id = r.site_id
  INNER JOIN clients c ON c.id = s.client_id
  LEFT JOIN employees m ON m.id = s.manager_employee_id
  WHERE r.id = ? AND r.employee_id = ?
  LIMIT 1
";
$st = mysqli_prepare($conn, $sql);
if (!$st) die("SQL error: ".mysqli_error($conn));
mysqli_stmt_bind_param($st, "ii", $viewId, $employeeId);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($st);

if (!$row) die("DPR not found or not allowed");

// recipients
$clientEmail  = trim((string)($row['client_email'] ?? ''));
$managerEmail = trim((string)($row['manager_email'] ?? ''));

// validate emails
$to = [];
if ($managerEmail !== '' && filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) $to[] = $managerEmail;
if ($clientEmail !== ''  && filter_var($clientEmail, FILTER_VALIDATE_EMAIL))  $to[] = $clientEmail;

if (empty($to)) {
  die("Manager email / Client email not found or invalid. Please update in database.");
}

// 2) Generate PDF bytes using report-print.php?mode=string
$_GET['mode'] = 'string';
require __DIR__ . '/report-print.php';

$pdfResult = $GLOBALS['__DPR_PDF_RESULT__'] ?? null;
if (!$pdfResult || empty($pdfResult['bytes'])) {
  die("Failed to generate PDF.");
}

$pdfName  = $pdfResult['filename'];
$pdfBytes = $pdfResult['bytes'];

// 3) Build email
$subject = "DPR " . ($row['dpr_no'] ?? '') . " - " . ($row['project_name'] ?? '');
$bodyText = "Dear Sir/Madam,\n\nPlease find attached the DPR PDF.\n\n"
          . "Project: " . ($row['project_name'] ?? '') . "\n"
          . "DPR No: " . ($row['dpr_no'] ?? '') . "\n"
          . "Date: " . ($row['dpr_date'] ?? '') . "\n\n"
          . "Regards,\n"
          . ($_SESSION['employee_name'] ?? 'Project Engineer');

// 4) Send using PHPMailer if available else mail()
$sent = false;
$err  = '';

$phpmailer1 = __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
$phpmailer2 = __DIR__ . '/libs/PHPMailer/PHPMailer.php';

try {
  if (file_exists($phpmailer1)) {
    require_once __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/libs/PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/libs/PHPMailer/src/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    // ✅ If you have SMTP, configure here:
    // $mail->isSMTP();
    // $mail->Host = "smtp.yourhost.com";
    // $mail->SMTPAuth = true;
    // $mail->Username = "your_email";
    // $mail->Password = "your_password";
    // $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    // $mail->Port = 587;

    // If not SMTP, it will use PHP mail() internally:
    $fromEmail = "no-reply@tekcglobal.com"; // change this to your domain email
    $fromName  = "TEK-C DPR";

    $mail->setFrom($fromEmail, $fromName);
    foreach ($to as $addr) $mail->addAddress($addr);

    $mail->Subject = $subject;
    $mail->Body    = $bodyText;
    $mail->AltBody = $bodyText;

    $mail->addStringAttachment($pdfBytes, $pdfName, 'base64', 'application/pdf');

    $sent = $mail->send();
  } else {
    // fallback: PHP mail() with attachment
    $boundary = "==Multipart_Boundary_x".md5(time())."x";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: TEK-C DPR <no-reply@tekcglobal.com>\r\n"; // change this

    $message  = "This is a multi-part message in MIME format.\r\n\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=\"utf-8\"\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $bodyText . "\r\n\r\n";

    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: application/pdf; name=\"{$pdfName}\"\r\n";
    $message .= "Content-Disposition: attachment; filename=\"{$pdfName}\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($pdfBytes)) . "\r\n";
    $message .= "--{$boundary}--\r\n";

    $sent = mail(implode(",", $to), $subject, $message, $headers);
  }
} catch (Throwable $e) {
  $sent = false;
  $err = $e->getMessage();
}

try { if (isset($conn) && $conn instanceof mysqli) $conn->close(); } catch (Throwable $e) {}

if ($sent) {
  // redirect back to report.php with success message
  header("Location: report.php?mail=sent");
  exit;
}

die("Mail sending failed. " . ($err ? "Error: ".$err : ""));
