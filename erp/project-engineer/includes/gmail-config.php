<?php
// includes/gmail-config.php
// Keep this file OUTSIDE git if possible.

require_once __DIR__ . '/../vendor/autoload.php';

return [
  'client_id' => '454806819173-rpt28dk7v56plnin8eteelcan46qmql0.apps.googleusercontent.com',
  'client_secret' => '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',

  // MUST match Google Console redirect URI exactly:
  'redirect_uri' => 'https://tekcglobal.com/erp/project-engineer/gmail-callback.php',

  'scopes' => [
    Google\Service\Gmail::GMAIL_READONLY, // Inbox read
    // Google\Service\Gmail::GMAIL_SEND, // enable later for sending
  ],
];