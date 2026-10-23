<?php
declare(strict_types=1);

/*
 * nuBuilder Next – builds and signs library packs on the command line (the same as Admin → Update → Libraries → "Build library
 * packs" in developer mode, core/nulibs.php):
 *
 *   php dev/libpack.php <out folder> <key file> <slot>=<source folder>[@<version>] … [--keep=<old libraries.json>]
 *     slot: tcpdf | phpmailer | tinymce | phpmyadmin | adminer | tinyfilemanager
 *     version: read from the library (or its NUNEXT-VERSION.txt) when left out
 *     --keep: entries of an earlier index for the slots not built now (its signature is checked)
 *
 *   e.g. php dev/libpack.php ../libpacks ../nunext-signing.key tcpdf=../src/tcpdf phpmyadmin=../src/nudb tinyfilemanager=../src/tfm@2.6+git.4149143.nu1
 *
 * Writes nunext-lib-<slot>-<version>.zip per library and the signed index libraries.json into the out folder – attach them to the
 * GitHub release with the tag "libraries". The key file is the owner's (the one of nunext.zip) and never part of the project.
 */
if (PHP_SAPI !== 'cli' || $argc < 4) { fwrite(STDERR, "usage: php dev/libpack.php <out folder> <key file> <slot>=<folder>[@<version>] … [--keep=<libraries.json>]\n"); exit(2); }
define('NU_ROOT', dirname(__DIR__));
const NU_VERSION_FILE = NU_ROOT . '/core/nuapp.php';
if (!defined('NU_VERSION')) define('NU_VERSION', preg_match("/define\\('NU_VERSION', '([^']+)'\\)/", (string)file_get_contents(NU_VERSION_FILE), $m) ? $m[1] : '');
const NU_TINYMCE = 'assets/vendor/tinymce';
$GLOBALS['nuLibpackEn'] = require NU_ROOT . '/lang/en.php';
function t(string $key, mixed ...$args): string { $s = (string)($GLOBALS['nuLibpackEn'][$key] ?? $key); return $args ? vsprintf($s, $args) : $s; }
function nu_time_limit(int $s): void { @set_time_limit($s); }
function nu_cfg(string $k): mixed { return null; }
final class NuSecurity
{
	public const DENY = '';
	public static function localVersion(string $dir): ?array
	{
		$f = rtrim($dir, '/') . '/NUNEXT-VERSION.txt';
		if (!is_file($f)) return null;
		$v = preg_match('/^\s*version\s*:\s*(.+)$/mi', (string)file_get_contents($f), $mm) ? trim($mm[1]) : '';
		return ['version' => $v, 'cves' => [], 'text' => ''];
	}
}
require NU_ROOT . '/core/nuupdate.php';
require NU_ROOT . '/core/nulibs.php';

$out = $argv[1];
$sk = '';
try {
	$sk = NuLibs::secretKey((string)file_get_contents($argv[2]));
	$packs = [];
	$keep = [];
	foreach (array_slice($argv, 3) as $a) {
		if (str_starts_with($a, '--keep=')) { $keep = NuLibs::parseIndex((string)file_get_contents(substr($a, 7))); continue; }
		if (!preg_match('/^([a-z]+)=(.+?)(?:@([0-9][0-9A-Za-z.+_-]*))?$/', $a, $m)) throw new RuntimeException("bad argument: $a");
		$t0 = microtime(true);
		$p = NuLibs::build($m[1], $m[2], $sk, $out, $m[3] ?? '');
		$packs[] = $p;
		printf("%-16s %-24s %6d files %8.1f MB  %s  (%.1f s)\n", $p['name'], $p['version'], $p['files'], $p['size'] / 1048576, basename($p['file']), microtime(true) - $t0);
	}
	file_put_contents(rtrim($out, '/') . '/' . NuLibs::INDEX, NuLibs::buildIndex($packs, $sk, $keep));
	$idx = NuLibs::parseIndex((string)file_get_contents(rtrim($out, '/') . '/' . NuLibs::INDEX));
	echo NuLibs::INDEX, ': ', count($idx), ' libraries, signature valid', "\n";
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
} finally {
	if ($sk !== '') sodium_memzero($sk);
}
