<?php
declare(strict_types=1);

/*
 * Checks release packages like the update page does (NuUpdate::inspect): signature, manifest, files, requirements.
 *   php dev/inspect.php <test installation folder> ../nunext.zip [more zips]
 * The test installation needs a working nuconfig.php (database); its own files are not changed.
 * Expected after a release: ok=true, version = the new one, signature=valid, errors=[].
 */
if (PHP_SAPI !== 'cli' || $argc < 3) { fwrite(STDERR, "usage: php dev/inspect.php <installation> <zip> [zip …]\n"); exit(2); }
$zips = array_map(static fn($z) => realpath($z) ?: $z, array_slice($argv, 2));
chdir($argv[1]);
require 'core/nuapp.php';
$fail = 0;
foreach ($zips as $zip) {
	$r = NuUpdate::inspect($zip);
	if (!$r['ok'] || ($r['signature'] ?? '') !== 'valid') $fail = 1;
	echo basename($zip), ': ok=', var_export($r['ok'], true), ' version=', $r['version'], ' signature=', $r['signature'] ?? '-', ' files=', count($r['manifest']['files'] ?? []),
		' errors=', json_encode($r['errors'], JSON_UNESCAPED_UNICODE), "\n";
}
exit($fail);
