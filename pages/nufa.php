<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Serves nuBuilder's Font Awesome (<nuBuilder>/third_party/fontawesome/) through nuBuilder Next, so existing
 * "fa fa-…" icons in forms keep working. Runs before the session starts (no locking, cacheable).
 * index.php?p=fa&f=css/all.min.css  |  index.php?p=fa&f=webfonts/fa-solid-900.woff2
 */

$f   = (string)($_GET['f'] ?? '');
$dir = NuBuilderLib::fontAwesomeDir();
$types = ['css' => 'text/css; charset=UTF-8', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject', 'svg' => 'image/svg+xml'];

if ($dir === null || !preg_match('~^(css|webfonts)/[A-Za-z0-9._-]+\.(css|woff2|woff|ttf|eot|svg)$~', $f, $m)) {
	http_response_code(404);
	exit;
}
$file = realpath("$dir/$f");
if ($file === false || !str_starts_with($file, realpath($dir) . DIRECTORY_SEPARATOR) || !is_file($file)) {
	http_response_code(404);
	exit;
}

$mtime = (int)filemtime($file);
$etag  = '"' . dechex($mtime) . '-' . dechex((int)filesize($file)) . '"';
header('Content-Type: ' . $types[$m[2]]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=' . (isset($_GET['v']) ? 31536000 : 86400));
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
	http_response_code(304);
	exit;
}

if ($m[2] !== 'css') {
	header('Content-Length: ' . filesize($file));
	readfile($file);
	exit;
}

// point the font URLs of the stylesheet at this script
$v = base_convert((string)$mtime, 10, 36);
echo preg_replace_callback('~url\(\s*([\'"]?)\.\./webfonts/([A-Za-z0-9._-]+)(\?[^\'")]*)?\1\s*\)~', static fn($x) =>
	'url("index.php?p=fa&f=webfonts/' . $x[2] . '&v=' . $v . '")', (string)file_get_contents($file));
