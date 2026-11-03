<?php
declare(strict_types=1);

/*
 * Return address of the OAuth 2 consent page for the SMTP login (0.8.445): https://<nuBuilder Next>/oauth.php – entered as redirect URI
 * in the Google Cloud console / Azure app registration (an address without a query string). Everything happens in pages/numailoauth.php,
 * or in pages/nusso.php for single sign-on.
 */
$_GET['p'] = str_starts_with((string)($_GET['state'] ?? ''), 'sso.') ? 'sso' : 'mailoauth';   // single sign-on returns here too (0.8.863, state "sso.…")
require __DIR__ . '/index.php';
