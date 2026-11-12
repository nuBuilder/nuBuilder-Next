<?php
declare(strict_types=1);
/*
 * Docker entrypoint, step 2/3 (run as www-data in /var/www/html, after nuconfig.php exists and the database answers):
 * an empty database is set up (nuBuilder Forte's tables from the install scripts + nuBuilder Next's own), a ready one gets the
 * database steps of this version; argv[1] = 1 installs the sample application once.
 */
if (PHP_SAPI !== 'cli') exit(1);
require getcwd() . '/core/nuapp.php';
$_SESSION = [];
try {
	$s = NuDbState::check();
	if ($s['state'] === 'empty') {
		$r = NuDbState::setUp('scripts');
		echo 'database set up: ', $r['imported'] ?? '?', " statements\n";
	} elseif ($s['state'] === 'ok') {
		NuSchema::ensure();
		$m = NuMigrate::run();
		echo 'database ready', $m ? ', steps applied: ' . implode(', ', $m) : '', "\n";
	} else {
		echo 'database state: ', $s['state'], " – the globeadmin's sign-in shows what to do\n";
	}
	NuDbState::reset();
	if (($argv[1] ?? '0') === '1' && NuDbState::check()['state'] === 'ok' && !NuSample::status()['installed']) {
		NuAuth::cliGlobeadmin();
		NuSample::install();
		echo "sample application installed\n";
	}
} catch (Throwable $e) {
	echo 'set-up: ', $e->getMessage(), "\n";
	exit(1);
}
