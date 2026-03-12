<?php
// includes/gmail-client.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db-config.php';

/**
 * Base64url decode (Gmail uses URL-safe base64)
 */
function gmail_base64url_decode(string $data): string {
  $data = strtr($data, '-_', '+/');
  $pad = strlen($data) % 4;
  if ($pad) $data .= str_repeat('=', 4 - $pad);
  $out = base64_decode($data);
  return $out === false ? '' : $out;
}

/**
 * Base64url encode
 */
function gmail_base64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Build and return an authenticated Google\Client for the given employee.
 * Returns null if employee has not connected Gmail (no token in DB) or token is invalid.
 *
 * Expects table: employee_gmail_tokens(employee_id UNIQUE, access_token TEXT, refresh_token TEXT NULL, token_expires_at DATETIME NULL, gmail_email VARCHAR)
 */
function gmailClientForEmployee(mysqli $conn, int $employeeId): ?Google\Client
{
  $config = require __DIR__ . '/gmail-config.php';

  // Fetch token row
  $st = mysqli_prepare($conn, "
    SELECT access_token, refresh_token
    FROM employee_gmail_tokens
    WHERE employee_id = ?
    LIMIT 1
  ");
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $row = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);

  if (!$row) return null;

  // Build Google client
  $client = new Google\Client();
  $client->setClientId($config['client_id']);
  $client->setClientSecret($config['client_secret']);
  $client->setRedirectUri($config['redirect_uri']);

  // Scopes: use config + enforce minimum for your app (inbox/view + send)
  $scopes = $config['scopes'] ?? [];
  if (!is_array($scopes)) $scopes = [$scopes];

  $requiredScopes = [
    Google\Service\Gmail::GMAIL_READONLY, // inbox/view
    Google\Service\Gmail::GMAIL_SEND,     // send
  ];
  $scopes = array_values(array_unique(array_merge($scopes, $requiredScopes)));

  $client->setScopes($scopes);
  $client->setAccessType('offline');
  // Helps when you "Reconnect" to upgrade scopes (in gmail-connect.php)
  $client->setPrompt('consent');

  // Load stored access token JSON
  $tokenArr = json_decode((string)$row['access_token'], true);
  if (!is_array($tokenArr) || empty($tokenArr['access_token'])) {
    return null; // invalid / missing token
  }

  $client->setAccessToken($tokenArr);

  // Refresh if expired (needs refresh_token saved in DB)
  $refreshToken = (string)($row['refresh_token'] ?? '');
  if ($client->isAccessTokenExpired() && $refreshToken !== '') {
    try {
      $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

      if (!isset($newToken['error'])) {
        // Merge new token fields into existing token array
        $merged = array_merge($tokenArr, $newToken);

        // Sometimes Google can return refresh_token again (rare). Keep existing if not present.
        $mergedRefresh = $merged['refresh_token'] ?? $refreshToken;

        // Compute expires_at for DB (optional)
        $expiresAt = null;
        if (!empty($merged['expires_in'])) {
          $expiresAt = date('Y-m-d H:i:s', time() + (int)$merged['expires_in']);
        }

        // Update DB with refreshed access token (and refresh token if changed)
        $accessTokenJson = json_encode($merged);

        $upd = mysqli_prepare($conn, "
          UPDATE employee_gmail_tokens
          SET access_token = ?, refresh_token = ?, token_expires_at = ?
          WHERE employee_id = ?
        ");
        mysqli_stmt_bind_param($upd, "sssi", $accessTokenJson, $mergedRefresh, $expiresAt, $employeeId);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        $client->setAccessToken($merged);
      }
    } catch (Exception $e) {
      // If refresh fails, caller can handle Gmail API errors and force reconnect
      // return null; // uncomment if you prefer forcing reconnect immediately
    }
  }

  return $client;
}