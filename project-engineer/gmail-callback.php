<?php
session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/db-config.php';

if (empty($_SESSION['employee_id'])) {
  die("Unauthorized");
}
$employeeId = (int)$_SESSION['employee_id'];

$conn = get_db_connection();
if (!$conn) die("DB Error");

$config = require __DIR__ . '/includes/gmail-config.php';

if (empty($_GET['code'])) {
  die("Authorization failed. No code returned.");
}

$client = new Google\Client();
$client->setClientId($config['client_id']);
$client->setClientSecret($config['client_secret']);
$client->setRedirectUri($config['redirect_uri']);
$client->setScopes($config['scopes']);
$client->setAccessType('offline');
$client->setPrompt('consent');

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
if (isset($token['error'])) {
  die("Token error: " . htmlspecialchars($token['error_description'] ?? $token['error']));
}

// Get Gmail profile email (optional but useful)
$client->setAccessToken($token);
$gmail = new Google\Service\Gmail($client);
$profile = $gmail->users->getProfile('me');
$gmailEmail = $profile ? $profile->getEmailAddress() : null;

$accessTokenJson = json_encode($token);
$refreshToken = $token['refresh_token'] ?? null;

$expiresAt = null;
if (!empty($token['expires_in'])) {
  $expiresAt = date('Y-m-d H:i:s', time() + (int)$token['expires_in']);
}

// Upsert token
$sql = "
INSERT INTO employee_gmail_tokens (employee_id, gmail_email, access_token, refresh_token, token_expires_at)
VALUES (?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  gmail_email = VALUES(gmail_email),
  access_token = VALUES(access_token),
  refresh_token = COALESCE(employee_gmail_tokens.refresh_token, VALUES(refresh_token)),
  token_expires_at = VALUES(token_expires_at)
";
$st = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($st, "issss", $employeeId, $gmailEmail, $accessTokenJson, $refreshToken, $expiresAt);
mysqli_stmt_execute($st);
mysqli_stmt_close($st);

header("Location: mail-inbox.php");
exit;