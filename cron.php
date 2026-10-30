<?php
declare(strict_types=1);

/*
 * nuBuilder Next on the command line (the web server never runs this file: .htaccess, PHP_SAPI).
 *
 * Housekeeping (0.8.207), e.g. every 15 minutes:  *\/15 * * * *  php /var/www/html/nunext/cron.php > /dev/null
 *     php cron.php
 *   Removes expired tokens and sessions, old API counters, log rows beyond "Keep the log" (Admin tools → Security check) and temporary
 *   files; finishes an unattended update whose database steps are still pending. Without cron, page views do this now and then.
 *
 * Unattended update (0.8.301) – run it as the web server's user (e.g. sudo -u www-data php cron.php update …), so the new files
 * belong to it:
 *     php cron.php update --url=https://example.com/nunext.zip [options]
 *     php cron.php update --zip=/path/nunext.zip [options]
 *     php cron.php update --github [options]   the newest release of the repository set on the update page (0.8.374, NuGitHub)
 *   options:  --dry-run                 check only (signature, requirements, files, database structure, sample data) – nothing changes
 *             --sha256=<hex>            expected checksum of the package
 *             --backup=auto|files|nubuilder|sql|sql_nologs   (auto = nubuilder: the zzzzsys_* tables without logs; sql = whole database)
 *             --schema=none|missing|safe|all       database columns to add / widen first (safe = as the update page preselects)
 *             --sample=auto|yes|no      refresh the sample data (auto: when the package brings a newer sample version)
 *             --reinstall               install even when the package is not newer
 *             --ignore-integrity        install although the integrity check of the database found problems (0.8.698)
 *             --json                    output as JSON
 *     php cron.php update --status [job]     the last jobs / one job
 *     php cron.php update --finish=<job>     database steps of an installed job (started automatically in a new process)
 *   Exit code: 0 = done / checked, 1 = failed, 2 = wrong call.
 *
 * Integrity check of the database (0.8.698) – what would make an update fail or leave data broken:
 *     php cron.php integrity [--json]      Exit code: 0 = no problems (warnings / notes allowed), 1 = problems found
 *
 * Reset database (0.8.628, only with $nuConfigDevMode = true, MySQL / MariaDB): deletes ALL tables of the database, imports a nuBuilder
 * Forte (4.x) SQL file (nubuilder4.sql or a complete dump, .sql / .sql.gz / .zip) and creates nuBuilder Next's tables:
 *     php cron.php reset --sql=<file> [--check] [--backup] [--sample] --yes
 *   --check  read and check the file only, list the tables that would be deleted – nothing changes (no --yes needed)
 *   --backup a backup with the whole database first (Update → Backups)      --sample  install the sample application afterwards
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
chdir(__DIR__);
require __DIR__ . '/core/nuapp.php';
nu_config();
nu_apply_timezone();
$_SESSION = [];

$args = array_slice($argv, 1);
if (($args[0] ?? '') === 'reset') {
	$ro = [];
	foreach (array_slice($args, 1) as $a) if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $a, $m)) $ro[$m[1]] = $m[2] ?? true;
	$usage = "Usage: php cron.php reset --sql=<file> [--check] [--backup] [--sample] --yes\n";
	if (!NuDbReset::available()) { fwrite(STDERR, "Reset database needs \$nuConfigDevMode = true in nuconfig.php and a MySQL / MariaDB database.\n"); exit(2); }
	$file = is_string($ro['sql'] ?? null) ? (string)$ro['sql'] : '';
	if ($file === '' || !is_file($file)) { fwrite(STDERR, ($file === '' ? 'No --sql=<file>.' : 'File not found: ' . $file) . "\n" . $usage); exit(2); }
	$chk = NuDbReset::check($file);
	echo str_pad('database', 14) . ($chk['database'] ?? '') . "\n" . str_pad('file', 14) . basename($file) . "\n";
	if (!$chk['ok']) { fwrite(STDERR, 'The file cannot be imported: ' . $chk['error'] . "\n"); exit(1); }
	echo str_pad('creates', 14) . $chk['tables'] . ' table(s), ' . $chk['app'] . ' of the application, ' . $chk['forms'] . ' form(s), ' . $chk['users'] . " user(s)\n";
	echo str_pad('deletes', 14) . count($chk['current']) . ' table(s) / view(s) now in the database' . "\n";
	if (!empty($ro['check'])) exit(0);
	if (empty($ro['yes'])) { fwrite(STDERR, "Nothing done: add --yes to delete all tables and import the file.\n" . $usage); exit(2); }
	try {
		$r = NuDbReset::run($file, !empty($ro['backup']), !empty($ro['sample']), 'cli');
	} catch (Throwable $e) {
		fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
		exit(1);
	}
	if ($r['backup'] !== '') echo str_pad('backup', 14) . $r['backup'] . "\n";
	echo str_pad('dropped', 14) . $r['dropped'] . "\n" . str_pad('statements', 14) . $r['statements'] . "\n" . str_pad('steps', 14) . count($r['steps']) . "\n";
	if ($r['sample'] !== '') echo str_pad('sample', 14) . ($r['sample'] === 'ok' ? 'installed (Admin tools → Sample data → New passwords)' : 'failed: ' . $r['sample']) . "\n";
	echo "Done. Open index.php?p=setupwizard to set up the installation.\n";
	exit(0);
}
if (($args[0] ?? '') === 'integrity') {
	$ic = NuIntegrity::run();
	if (in_array('--json', $args, true)) echo json_encode(NuIntegrity::summary($ic), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	else echo implode("\n", NuIntegrity::lines($ic)) . "\n";
	exit($ic['errors'] ? 1 : 0);
}
if (($args[0] ?? '') !== 'update') {
	$done = NuHousekeeping::run('cron');
	foreach ($done as $k => $n) echo str_pad($k, 14) . ($n < 0 ? 'error (see the PHP error log)' : $n) . "\n";
	foreach (NuAutoUpdate::finishPending() as $job) echo str_pad('update', 14) . 'finished job ' . $job . "\n";
	exit(0);
}

// ---------------------------------------------------------------------- update
$opt = [];
$flags = [];
foreach (array_slice($args, 1) as $a) {
	if (preg_match('/^--([a-z0-9-]+)=(.*)$/s', $a, $m)) $opt[str_replace('-', '_', $m[1])] = $m[2];
	elseif (preg_match('/^--([a-z0-9-]+)$/', $a, $m)) $flags[str_replace('-', '_', $m[1])] = true;
	else $opt['_job'] = $a;
}
$json = !empty($flags['json']);
$out = static function (array $r) use ($json): void {
	if ($json) { echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"; return; }
	foreach ($r as $k => $v) {
		if ($v === null || $v === [] || $k === 'options') continue;
		if ($k === 'requirements' && is_array($v)) $v = array_map(static fn($q) => (!empty($q['ok']) ? 'ok   ' : 'FAIL ') . $q['check'] . ': ' . $q['detail'], $v);
		if ($k === 'database' && is_array($v)) {                         // database changes of the update (0.8.346), readable
			$lines = !empty($v['none']) ? ['no changes to the database'] : [];
			if (empty($v['described'])) $lines[] = '(the package does not describe its database steps – structure only)';
			foreach ((array)($v['steps'] ?? []) as $st) $lines[] = 'step ' . $st['id'] . ': ' . $st['description'];
			if (!empty($v['kept'])) $lines[] = count($v['kept']) . ' column(s) larger in the database than expected – left as they are (--schema=all would change them)';
			foreach ((array)($v['tables'] ?? []) as $t => $list) foreach ($list as $it) {
				$lines[] = $t . ': ' . $it['kind'] . ' – ' . $it['what'] . (isset($it['step']) ? ' (step ' . $it['step'] . ')' : '') . (!empty($it['risky']) ? ' (not safe)' : '');
				foreach ((array)($it['sql'] ?? []) as $q) $lines[] = '    ' . str_replace("\n", ' ', $q);
			}
			$v = $lines;
		}
		if (is_array($v)) $v = array_is_list($v) ? implode("\n" . str_repeat(' ', 16), array_map(static fn($x) => is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (string)$x, $v))
			: json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		elseif (is_bool($v)) $v = $v ? 'yes' : 'no';
		echo str_pad((string)$k, 18) . $v . "\n";
	}
};

if (!empty($flags['status'])) {
	$job = (string)($opt['_job'] ?? '');
	$r = $job !== '' ? (NuAutoUpdate::job($job) ?? ['errors' => ['Unknown job.']]) : ['installed' => NU_VERSION, 'jobs' => array_map(static fn($j) => $j['job'] . '  ' . $j['status'] . '  ' . $j['from'] . ' → ' . $j['to'] . '  ' . $j['started'], NuAutoUpdate::jobs())];
	$out($r);
	exit(isset($r['errors']) ? 1 : 0);
}
if (isset($opt['finish'])) {
	$r = NuAutoUpdate::finish((string)$opt['finish']);
	$out($r);
	exit(!empty($r['ok']) ? 0 : 1);
}

[$o, $errors] = NuAutoUpdate::options($opt + ['dry_run' => !empty($flags['dry_run']), 'reinstall' => !empty($flags['reinstall']), 'github' => !empty($flags['github'])], true);
if ($errors) {
	fwrite(STDERR, implode("\n", $errors) . "\nUsage: php cron.php update --url=https://…/nunext.zip | --zip=/path/nunext.zip [--dry-run] [--sha256=…] [--backup=…] [--schema=…] [--sample=…] [--reinstall] [--json]\n");
	exit(2);
}
$r = NuAutoUpdate::run($o, 'cli');
if ($r['ok'] && ($r['status'] ?? '') === 'installed') {
	// the database steps with the NEW code: a new PHP process (the classes of this one are the old ones)
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cron.php') . ' update --finish=' . escapeshellarg((string)$r['job']) . ' --json';
	$res = null;
	if (function_exists('proc_open')) {
		$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (is_resource($p)) {
			$res = json_decode((string)stream_get_contents($pipes[1]), true);
			fclose($pipes[1]); fclose($pipes[2]);
			proc_close($p);
		}
	}
	if (is_array($res)) {
		$r['status'] = (string)($res['status'] ?? $r['status']);
		$r['ok'] = !empty($res['ok']);
		foreach (['steps', 'sample_error', 'errors', 'finished'] as $k) if (isset($res[$k])) $r[$k] = $res[$k];
		if (isset($res['sample'])) $r['sample_refreshed'] = $res['sample'];
	} else {
		$r['pending'] = 'Database steps not run yet: php cron.php update --finish=' . $r['job'] . ' (or the next run of cron.php, or the update page).';
	}
	if (!empty($r['sample_refreshed'])) $r['sample_passwords'] = 'The sample users have new passwords: Admin tools → Sample data → New passwords.';
}
$out($r);
exit(!empty($r['ok']) ? 0 : 1);
