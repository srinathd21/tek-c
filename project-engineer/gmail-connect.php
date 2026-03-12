<?php
// gmail-connect.php
session_start();

require_once __DIR__ . '/vendor/autoload.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$config = require __DIR__ . '/includes/gmail-config.php';

$client = new Google\Client();
$client->setClientId($config['client_id']);
$client->setClientSecret($config['client_secret']);
$client->setRedirectUri($config['redirect_uri']);

// ✅ Ensure required scopes (inbox/view + send)
$scopes = $config['scopes'] ?? [];
if (!is_array($scopes)) $scopes = [$scopes];

$requiredScopes = [
  Google\Service\Gmail::GMAIL_READONLY,
  Google\Service\Gmail::GMAIL_SEND,
];

$scopes = array_values(array_unique(array_merge($scopes, $requiredScopes)));
$client->setScopes($scopes);

// ✅ Required to receive refresh_token (and to keep long-lived access)
$client->setAccessType('offline');

// ✅ Force consent every time you reconnect so new scopes apply
$client->setPrompt('consent');

// ✅ Optional but useful: store who is connecting
// Also add random data to prevent CSRF/replay attacks
$state = [
  'employee_id' => (int)$_SESSION['employee_id'],
  'nonce' => bin2hex(random_bytes(16)),
  'ts' => time(),
];
$_SESSION['gmail_oauth_state'] = $state;

$client->setState(base64_encode(json_encode($state)));

header("Location: " . $client->createAuthUrl());
exit;