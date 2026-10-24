<?php
declare(strict_types=1);

/*
 * Return address of the OAuth 2 consent page for the SMTP login (0.8.445): https://<nuBuilder Next>/oauth.php – entered as redirect URI
 * in the Google Cloud console / Azure app registration (an address without a query string). Everything happens in pages/numailoauth.php.
 */
$_GET['p'] = 'mailoauth';
require __DIR__ . '/index.php';
