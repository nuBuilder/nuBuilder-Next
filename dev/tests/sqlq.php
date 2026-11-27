<?php
declare(strict_types=1);
/*
 * The mysql / mysqldump client tools for a test installation on SQLite (dev/tests/e2e.js with SQLITE=1 calls this instead):
 *   php dev/tests/sqlq.php <site> query [-N] [-r] [-e "SQL"]   SQL from stdin or -e; rows as "mysql -N" prints them (tab separated, NULL)
 *   php dev/tests/sqlq.php <site> dump <file> [--no-create-info] [--insert-ignore] [--replace] [--where=…] [table …]
 *        no table = the whole database as an SQLite file (VACUUM INTO); tables = SQL text (DROP / CREATE / INSERT of those tables)
 *   php dev/tests/sqlq.php <site> restore <file>              an SQLite file → every table put back; SQL text → run
 * Runs through nuBuilder Next's own NuDB, so the SQL of the tests (written for MySQL) is translated as the program's is.
 */
if (PHP_SAPI !== 'cli') exit;
[$_, $site, $mode] = array_pad($argv, 3, '');
chdir($site);
require $site . '/core/nuapp.php';
$db = nu_db();
$pdo = $db->pdo();

$args = array_slice($argv, 3);
if ($mode === 'query') {
	$raw = in_array('-r', $args, true);
	$e = array_search('-e', $args, true);
	$sql = $e !== false ? (string)($args[$e + 1] ?? '') : (string)stream_get_contents(STDIN);
	if (str_starts_with($sql, 'SQLite format 3')) {                      // a whole-database dump fed back through "mysql DB"
		$tmp = tempnam(sys_get_temp_dir(), 'sqlq');
		file_put_contents($tmp, $sql);
		passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($site) . ' restore ' . escapeshellarg($tmp), $rc);
		@unlink($tmp);
		exit($rc);
	}
	if (str_starts_with($sql, "-- sqlq dump\n")) {            // a dump of this script fed through "mysql DB" (input): SQLite's SQL as it is
		$pdo->exec('BEGIN IMMEDIATE');
		foreach (NuSqlImport::split($sql, 'sqlsrv') as $st) if (trim($st) !== '') $pdo->exec($st);
		$pdo->exec('COMMIT');
		exit(0);
	}
	foreach (NuSqlImport::split($sql) as $st) {
		$st = trim($st);
		if ($st === '') continue;
		try {
			$r = $db->run($st);
		} catch (Throwable $ex) {
			fwrite(STDERR, 'ERROR: ' . $ex->getMessage() . "\n" . substr($st, 0, 300) . "\n");
			exit(1);
		}
		if ($r->columnCount() === 0) continue;
		while (($row = $r->fetch(PDO::FETCH_NUM)) !== false) {
			echo implode("\t", array_map(static function ($v) use ($raw) {
				if ($v === null) return 'NULL';
				$v = (string)$v;
				return $raw ? $v : strtr($v, ["\\" => "\\\\", "\t" => "\\t", "\n" => "\\n", "\0" => "\\0"]);
			}, $row)), "\n";
		}
	}
	exit(0);
}
$names = static fn() => array_column($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!'"), 'name');
if ($mode === 'dump') {
	$file = (string)array_shift($args);
	$noCreate = in_array('--no-create-info', $args, true);
	$ignore = in_array('--insert-ignore', $args, true);
	$replace = in_array('--replace', $args, true);
	$where = '';
	foreach ($args as $a) if (str_starts_with($a, '--where=')) $where = (string)preg_replace('/^"(.*)"$/s', '$1', substr($a, 8));
	$tables = array_values(array_filter($args, static fn($a) => $a !== '' && $a[0] !== '-'));
	if (!$tables) {
		@unlink($file);
		$pdo->exec('VACUUM INTO ' . $pdo->quote($file));
		exit(0);
	}
	$append = in_array('--append', $args, true) && is_file($file) && filesize($file) > 0;   // "mysqldump … >> file"
	$out = fopen($file, $append ? 'ab' : 'wb');
	$lit = static function ($v) use ($pdo): string {
		if ($v === null) return 'NULL';
		if (is_int($v) || is_float($v)) return (string)$v;
		return $pdo->quote((string)$v);
	};
	if (!$append) fwrite($out, "-- sqlq dump\n");          // SQLite's own SQL: the restore runs it as it is
	foreach ($tables as $t) {
		$def = $db->one("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]);
		if (!$def) continue;
		if (!$noCreate) {
			fwrite($out, 'DROP TABLE IF EXISTS "' . $t . "\";\n" . $def['sql'] . ";\n");
			foreach ($db->all("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$t]) as $ix) fwrite($out, $ix['sql'] . ";\n");
		}
		$st = $pdo->query('SELECT * FROM "' . $t . '"' . ($where !== '' ? ' WHERE ' . $where : ''));
		while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
			fwrite($out, 'INSERT ' . ($replace ? 'OR REPLACE ' : ($ignore ? 'OR IGNORE ' : '')) . 'INTO "' . $t . '" (' . implode(', ', array_map(static fn($c) => '"' . $c . '"', array_keys($row)))
				. ') VALUES (' . implode(', ', array_map($lit, array_values($row))) . ");\n");
		}
	}
	fclose($out);
	exit(0);
}
if ($mode === 'restore') {
	$file = (string)($args[0] ?? '');
	$head = (string)file_get_contents($file, false, null, 0, 16);
	if (str_starts_with($head, 'SQLite format 3')) {
		$pdo->exec('ATTACH DATABASE ' . $pdo->quote($file) . ' AS nub');
		$pdo->exec('BEGIN IMMEDIATE');
		foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'view'") as $v) $pdo->exec('DROP VIEW "' . $v['name'] . '"');
		foreach ($names() as $t) $pdo->exec('DROP TABLE "' . $t . '"');
		foreach ($pdo->query("SELECT type, name, sql FROM nub.sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite!_%' ESCAPE '!' ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 ELSE 2 END")->fetchAll() as $o) {
			$pdo->exec($o['sql']);
			if ($o['type'] === 'table') $pdo->exec('INSERT INTO main."' . $o['name'] . '" SELECT * FROM nub."' . $o['name'] . '"');
		}
		try { $pdo->exec('DELETE FROM main.sqlite_sequence; INSERT INTO main.sqlite_sequence SELECT * FROM nub.sqlite_sequence'); } catch (Throwable) { /* no AUTOINCREMENT table – expected */ }
		$pdo->exec('COMMIT');
		$pdo->exec('DETACH DATABASE nub');
		exit(0);
	}
	$text = (string)file_get_contents($file);
	$own = str_starts_with($text, "-- sqlq dump\n");          // written by dump: SQLite's SQL ('' in strings, no backslash escapes)
	$pdo->exec('BEGIN IMMEDIATE');
	foreach (NuSqlImport::split($text, $own ? 'sqlsrv' : 'mysql') as $st) {
		if (trim($st) === '') continue;
		try { if ($own) $pdo->exec($st); else $db->run($st); } catch (Throwable $ex) { $pdo->exec('ROLLBACK'); fwrite(STDERR, 'ERROR: ' . $ex->getMessage() . "\n" . substr($st, 0, 300) . "\n"); exit(1); }
	}
	$pdo->exec('COMMIT');
	exit(0);
}
fwrite(STDERR, "usage: sqlq.php <site> query|dump|restore …\n");
exit(2);
