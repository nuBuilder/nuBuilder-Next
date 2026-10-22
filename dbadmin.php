<?php
declare(strict_types=1);

/*
 * Adminer through nuBuilder Next (0.8.282) – Adminer itself is NOT part of the package: copy adminer.php (or
 * adminer-<version>.php, 4.8.1 or newer; 4.x, 5.x and 6.x) to third_party/adminer/. That folder gets a deny-all .htaccess; Adminer
 * runs only through this file:
 *   1. only for a globeadmin who opened it from nuBuilder Next (Admin tools, start page, form ⋮ menu): NuVendor::launch() writes
 *      a zzzzsys_session row for the nuBuilder Next session (as for phpMyAdmin / Tiny File Manager) and sets the cookie nu_ADM
 *      (HttpOnly, SameSite=Strict, only for this file). The row must belong to nuBuilder Next, have global_access = 1 and
 *      activity within the globeadmin session timeout; it is removed at logout / when the session is ended.
 *   2. Adminer logs in by itself with the database account of nuconfig.php (no password typed, never shown); other servers or
 *      accounts cannot be used (credentials() and login() of the Adminer object below).
 *   3. Adminer's Logout ends the access – open it again from nuBuilder Next.
 * nuBuilder Next's own code is NOT loaded here: Adminer 4 defines global functions (h(), lang() …) that would clash with it.
 * ?nu_table=<table> opens that table (form ⋮ menu → Database).
 */

const NU_ROOT = __DIR__;

// Adminer 6's service worker (?file=worker.js) keeps Adminer's files in the browser's Cache Storage by version – files of a damaged
// adminer.php (see below) stayed there, and only a hard reload got past it (0.8.289). Served here instead: a worker that deletes those
// caches and keeps nothing (the browser's normal cache still holds Adminer's files). Static script, no access check needed.
if (($_GET['file'] ?? '') === 'worker.js') {
	header('Content-Type: text/javascript; charset=utf-8');
	header('Cache-Control: no-cache');
	header('X-Content-Type-Options: nosniff');
	exit("'use strict';\n// nuBuilder Next: replaces Adminer's offline cache – removes it and caches nothing\n"
		. "self.addEventListener('install', () => self.skipWaiting());\n"
		. "self.addEventListener('activate', event => event.waitUntil(caches.keys()\n"
		. "\t.then(keys => Promise.all(keys.filter(key => key.startsWith('adminer-')).map(key => caches.delete(key))))\n"
		. "\t.then(() => self.clients.claim())));\n");
}

/** Texts from nuBuilder Next's language files (browser language, else English). */
function nu_adm_t(string $key): string
{
	static $d = null;
	if ($d === null) {
		$en = require NU_ROOT . '/lang/en.php';
		$d = $en;
		foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
			$code = strtolower(substr(trim($part), 0, 2));
			if ($code !== '' && preg_match('/^[a-z]{2}$/', $code) && is_file(NU_ROOT . "/lang/$code.php")) { $d = (require NU_ROOT . "/lang/$code.php") + $en; break; }
		}
	}
	return (string)($d[$key] ?? $key);
}

function nu_adm_fail(string $key, int $code = 403): never
{
	http_response_code($code);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	exit('Adminer: ' . nu_adm_t($key));
}

// Adminer designs next to adminer.php (third_party/adminer/adminer.css, adminer-dark.css – 0.8.290): that folder is blocked for the
// browser, so they are served here (only these two names). Adminer itself looks for them in the current folder (see css() below).
if (isset($_GET['nu_css'])) {
	$nuCss = (string)$_GET['nu_css'];
	$nuCssFile = NU_ROOT . '/third_party/adminer/' . $nuCss;
	if (!in_array($nuCss, ['adminer.css', 'adminer-dark.css'], true) || !is_file($nuCssFile)) { http_response_code(404); exit; }
	header('Content-Type: text/css; charset=utf-8');
	header('Cache-Control: public, max-age=31536000');     // the URL carries ?v=<checksum> of the file
	header('X-Content-Type-Options: nosniff');
	readfile($nuCssFile);
	exit;
}

if (!is_file(NU_ROOT . '/nuconfig.php')) nu_adm_fail('adm_err_login');
require NU_ROOT . '/nuconfig.php';

// Adminer's file (as NuVendor::adminerFile())
$nuAdmDir = NU_ROOT . '/third_party/adminer';
$nuAdmFile = is_file("$nuAdmDir/adminer.php") ? "$nuAdmDir/adminer.php" : '';
if ($nuAdmFile === '') {
	$bestV = '';
	foreach (glob("$nuAdmDir/adminer-*.php") ?: [] as $c) {
		$v = preg_match('/adminer-([0-9][0-9.]*[0-9])/', basename($c), $m) ? $m[1] : '0';
		if ($nuAdmFile === '' || version_compare($v, $bestV, '>')) { $nuAdmFile = $c; $bestV = $v; }
	}
}
if ($nuAdmFile === '') nu_adm_fail('adm_err_missing', 404);

// A copy with Windows line endings (FTP in text mode, a Windows editor, git autocrlf) breaks Adminer: its packed texts contain line
// breaks, so every text shows as a number and its scripts fail (0.8.287). The official files have no "\r" – such a copy is repaired
// once into a hidden file next to it (.nunext-lf-<size>-<time>.php) and that one is used. The Security check reports it.
$nuFh = @fopen($nuAdmFile, 'rb');
$nuHead = $nuFh ? (string)fread($nuFh, 8192) : '';
if ($nuFh) fclose($nuFh);
if (str_contains($nuHead, "\r")) {
	$nuFixed = $nuAdmDir . '/.nunext-lf-' . filesize($nuAdmFile) . '-' . filemtime($nuAdmFile) . '.php';
	if (!is_file($nuFixed)) {
		foreach (glob($nuAdmDir . '/.nunext-lf-*.php') ?: [] as $nuOld) @unlink($nuOld);
		$nuSrc = (string)@file_get_contents($nuAdmFile);
		if ($nuSrc === '' || @file_put_contents($nuFixed, str_replace(["\r\n", "\r"], "\n", $nuSrc), LOCK_EX) === false) nu_adm_fail('adm_err_crlf', 500);
	}
	error_log('nuBuilder Next Adminer: ' . basename($nuAdmFile) . ' has Windows line endings – using the repaired copy ' . basename($nuFixed));
	$nuAdmFile = $nuFixed;
}
unset($nuFh, $nuHead);

// Adminer answers every "If-Modified-Since" for its files (?file=functions.js …) with 304 – a browser that cached them from an older or
// damaged adminer.php (same version in the URL) would keep the old ones. Older than the file in use → Adminer sends them again.
if (isset($_GET['file']) && !empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
	$nuIms = strtotime((string)$_SERVER['HTTP_IF_MODIFIED_SINCE']);
	if ($nuIms === false || $nuIms < (int)filemtime($nuAdmFile)) unset($_SERVER['HTTP_IF_MODIFIED_SINCE']);
}

$nuIsMs = strtolower((string)($nuConfigDBType ?? 'mysql')) === 'sqlsrv';
$nuPort = trim((string)($nuConfigDBPort ?? ''));
$nuDefPort = $nuIsMs ? '1433' : '3306';

// ------------------------------------------------------------------ access: the zzzzsys_session row of the cookie nu_ADM
$nuSid = is_string($_COOKIE['nu_ADM'] ?? null) ? $_COOKIE['nu_ADM'] : '';
if (!preg_match('/^nx[0-9a-f]{23}$/', $nuSid)) nu_adm_fail('adm_err_open');
try {
	$nuPdo = $nuIsMs
		? new PDO('sqlsrv:Server=' . $nuConfigDBHost . ($nuPort !== '' ? ',' . $nuPort : '') . ';Database=' . $nuConfigDBName, (string)$nuConfigDBUser, (string)$nuConfigDBPassword)
		: new PDO('mysql:host=' . $nuConfigDBHost . ';dbname=' . $nuConfigDBName . ';charset=utf8mb4' . ($nuPort !== '' ? ';port=' . $nuPort : ''), (string)$nuConfigDBUser, (string)$nuConfigDBPassword);
	$nuPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$st = $nuPdo->prepare('SELECT sss_access, sss_time FROM zzzzsys_session WHERE zzzzsys_session_id = ?');
	$st->execute([$nuSid]);
	$nuRow = $st->fetch(PDO::FETCH_ASSOC);
	$minutes = (int)($nuConfigSessionTimeoutGlobeadmin ?? 0);
	if ($minutes <= 0 && !empty($nuConfigSettingsFromDB)) {
		try { $minutes = (int)$nuPdo->query("SELECT cfg_value FROM zzzzsys_config WHERE cfg_setting = '\$nuConfigSessionTimeoutGlobeadmin'")->fetchColumn(); } catch (Throwable) {}
	}
} catch (Throwable $e) {
	error_log('nuBuilder Next Adminer: ' . $e->getMessage());       // details only in the server log
	nu_adm_fail('adm_err_open');
}
if (!$nuRow) nu_adm_fail('adm_err_open');
$nuAcc = json_decode((string)$nuRow['sss_access'], true);
$nuSess = is_array($nuAcc) && is_array($nuAcc['session'] ?? null) ? $nuAcc['session'] : [];
if (!isset($nuSess['nunext_vendor']) || (string)($nuSess['global_access'] ?? '') !== '1') nu_adm_fail('adm_err_open');
if ($minutes <= 0) $minutes = 720;
if ((int)$nuRow['sss_time'] < time() - $minutes * 60) nu_adm_fail('adm_err_open');
unset($nuPdo, $st, $nuRow, $nuAcc, $nuSess);

$nuHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$nuPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/dbadmin.php'), PHP_URL_PATH);

// Adminer's Logout: ends the access (it would log in again by itself) – the cookie goes, the nuBuilder Next session stays
if (isset($_POST['logout'])) {
	setcookie('nu_ADM', '', ['expires' => time() - 3600, 'path' => $nuPath, 'secure' => $nuHttps, 'httponly' => true, 'samesite' => 'Strict']);
	nu_adm_fail('adm_logged_out', 200);
}

define('NU_ADM_DRIVER', $nuIsMs ? 'mssql' : 'server');
define('NU_ADM_SERVER', (string)$nuConfigDBHost . ($nuPort !== '' && $nuPort !== $nuDefPort ? ($nuIsMs ? ',' : ':') . $nuPort : ''));
define('NU_ADM_USER', (string)$nuConfigDBUser);
define('NU_ADM_DB', (string)$nuConfigDBName);
$GLOBALS['nuAdmPassword'] = (string)$nuConfigDBPassword;

// Adminer's session: own cookie, only for this file
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// PHP messages never into Adminer's output (0.8.286): with display_errors on (development servers) a warning – e.g. Adminer 6's
// ini_set('session.use_trans_sid') while this session is active, or Adminer 4's PHP 8 notices – lands in the page, its JSON and
// its scripts (ReferenceErrors in the browser). They still go to the server's error log (log_errors). Adminer sets its own error
// handler, so this is done with display_errors.
ini_set('display_errors', '0');
ini_set('session.use_trans_sid', '0');
session_cache_limiter('');                               // as Adminer: it sends its own cache headers and restarts the session later
session_name('nu_adminer');
session_set_cookie_params(['lifetime' => 0, 'path' => $nuPath, 'secure' => $nuHttps, 'httponly' => true, 'samesite' => 'Strict']);
session_start();                                          // defines SID – Adminer uses this session instead of starting its own

$nuLoggedIn = isset($_SESSION['pwds'][NU_ADM_DRIVER][NU_ADM_SERVER][NU_ADM_USER]);
$nuBase = basename(__FILE__) . '?' . (NU_ADM_DRIVER === 'server' ? 'server' : 'mssql') . '=' . rawurlencode(NU_ADM_SERVER)
	. '&username=' . rawurlencode(NU_ADM_USER) . '&db=' . rawurlencode(NU_ADM_DB);
if (isset($_GET['nu_table']) && is_string($_GET['nu_table']) && preg_match('/^[A-Za-z0-9_$]+$/', $_GET['nu_table'])) $_SESSION['nu_goto'] = $_GET['nu_table'];
// automatic login / jump only for the plain start (dbadmin.php or ?nu_table=…); every other request – Adminer's files (?file=),
// its manifest (?manifest=, Adminer 6) and whatever later versions add – goes to Adminer unchanged
$nuStart = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && array_diff(array_keys($_GET), ['nu_table']) === [];
if ($nuStart) {
	if ($nuLoggedIn) {
		$g = (string)($_SESSION['nu_goto'] ?? '');
		unset($_SESSION['nu_goto']);
		header('Location: ' . $nuBase . ($g !== '' ? '&select=' . rawurlencode($g) : ''));
		exit;
	}
	// log in with nuconfig.php's account – as if Adminer's login form had been sent
	$_POST['auth'] = ['driver' => NU_ADM_DRIVER, 'server' => NU_ADM_SERVER, 'username' => NU_ADM_USER, 'password' => $GLOBALS['nuAdmPassword'], 'db' => NU_ADM_DB, 'permanent' => ''];
} elseif ($nuLoggedIn && isset($_GET['username']) && !empty($_SESSION['nu_goto'])) {
	$g = (string)$_SESSION['nu_goto'];
	unset($_SESSION['nu_goto']);
	header('Location: ' . $nuBase . '&select=' . rawurlencode($g));
	exit;
}
unset($nuConfigDBPassword);

/** Adminer's css() run in third_party/adminer/ and its links pointed to dbadmin.php?nu_css=… (4.x: list of URLs, 5.x / 6.x: URL => mode). */
function nu_adm_css(callable $parent): array
{
	$cwd = getcwd();
	@chdir(NU_ROOT . '/third_party/adminer');
	try { $r = (array)$parent(); } finally { if ($cwd !== false) @chdir($cwd); }
	$fix = static fn(string $u): string => preg_replace('~^(adminer(?:-dark)?\.css)\?v=~', basename(__FILE__) . '?nu_css=$1&v=', $u) ?? $u;
	if (array_is_list($r)) return array_map(static fn($u) => $fix((string)$u), $r);
	$out = [];
	foreach ($r as $u => $mode) $out[$fix((string)$u)] = $mode;
	return $out;
}

/** Adminer's customisation hook (4.x: class Adminer, 5.x: Adminer\Adminer): only nuconfig.php's server and account. */
function adminer_object()
{
	if (class_exists('Adminer\\Adminer', false)) {
		class NuAdminer extends Adminer\Adminer
		{
			public function name() { return 'nuBuilder Next · Adminer'; }
			public function credentials() { return [NU_ADM_SERVER, NU_ADM_USER, $GLOBALS['nuAdmPassword']]; }
			public function login($login, $password) { return $login === NU_ADM_USER; }
			public function permanentLogin($create = false) { return ''; }
			public function verifyLoginToken() { return false; }   // Adminer 6: the login comes from here, not from its form
			public function css() { return nu_adm_css(fn() => parent::css()); }   // designs next to adminer.php
		}
	} else {
		class NuAdminer extends Adminer
		{
			public function name() { return 'nuBuilder Next · Adminer'; }
			public function credentials() { return [NU_ADM_SERVER, NU_ADM_USER, $GLOBALS['nuAdmPassword']]; }
			public function login($login, $password) { return $login === NU_ADM_USER; }
			public function permanentLogin($create = false) { return ''; }
			public function verifyLoginToken() { return false; }   // Adminer 6: the login comes from here, not from its form
			public function css() { return nu_adm_css(fn() => parent::css()); }   // designs next to adminer.php
		}
	}
	return new NuAdminer();
}

require $nuAdmFile;
