<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* ?p=jqfile&f=<file>&v=<mtime> – a jQuery file the admin put into custom/jquery/ (0.8.741, NuJquery; custom/ itself is never served). */

$f = (string)($_GET['f'] ?? '');
session_write_close();
header('X-Content-Type-Options: nosniff');
$files = NuJquery::customFiles();
if (!NuJquery::validName($f) || !isset($files[$f])) {
	http_response_code(404);
	header('Cache-Control: no-store');
	header('Content-Type: text/javascript; charset=utf-8');
	exit('');
}
$path = NuJquery::dir() . '/' . $f;
$fresh = (string)($_GET['v'] ?? '') === base_convert((string)(int)@filemtime($path), 10, 36);
header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: ' . ($fresh ? 'private, max-age=31536000, immutable' : 'no-cache'));
header('Content-Length: ' . (string)filesize($path));
readfile($path);
exit;
