<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Reset database (developer mode only): deletes ALL tables of the database and imports a nuBuilder Forte (4.x) SQL file –
 * nubuilder4.sql of any 4.x (made 4.9 with NuInstall::upgradeTo49) or a complete dump of a nuBuilder database with the application's
 * tables – then creates nuBuilder Next's tables (NuSchema + NuMigrate, origin "nunext": set up by nuBuilder Next itself, not blocked as
 * an existing database) and optionally the sample application. nuconfig.php, files, library packs, custom pages and backups stay.
 * Page: Admin tools → Reset database (pages/nudbreset.php, globeadmin, recent password, typed database name, optional full backup).
 * Command line: php nucron.php reset --sql=<file> [--backup] [--sample] --yes. MySQL / MariaDB only.
 * Fresh installation from a package: a signed nunext.zip (any version, also older or the same) – its files are installed
 * (NuUpdate::install, backup first), library packs replaced by the package's own (_install/), ALL tables deleted and the database built
 * from the package's install scripts; the database steps and the sample application run on the next request with the new code
 * (finishFresh(), ?p=dbreset&fresh=1). nuconfig.php, custom/ and the backups stay.
 * Clean installation (source CLEAN, MySQL and SQL Server – on SQL Server the only one: a MySQL dump cannot be read there): ALL tables
 * deleted, nuBuilder Next's install scripts of the database type imported, then as after a file (own tables, steps, sample).
 */
final class NuDbReset
{
	/** Source of a clean installation (nuBuilder Next's install scripts) – not a file. */
	public const CLEAN = '@clean';

	/** MySQL or SQL Server – the globeadmin check is the caller's (page: allowed()) or the shell's (nucron.php). */
	public static function available(): bool
	{
		try {
			return in_array(nu_db()->type, ['mysql', 'sqlsrv'], true);
		} catch (Throwable) {
			return false;
		}
	}

	/** The page and its tile: the real globeadmin (not demo, not acting as a user) on MySQL or SQL Server. */
	public static function allowed(): bool
	{
		$u = NuAuth::user();
		return $u && !empty($u['globeadmin']) && empty($u['demo']) && self::available();
	}

	/** SQL files (dumps, packages) can be imported: MySQL / MariaDB only. */
	public static function filesAllowed(): bool
	{
		return nu_db()->type === 'mysql';
	}

	/** Name of the database. */
	public static function dbName(): string
	{
		$db = nu_db();
		return (string)$db->value($db->type === 'sqlsrv' ? 'SELECT DB_NAME()' : 'SELECT DATABASE()');
	}

	/** SQL files offered: nubuilder4.sql of the nuBuilder Forte folder, .sql / .sql.gz / .zip with SQL in _install/ and _update/. @return array<string, string> label => path */
	public static function sources(): array
	{
		$out = [t('dbr_src_clean') => self::CLEAN];
		if (!self::filesAllowed()) return $out;
		$nub = NuInstall::nuBuilderPath();
		if ($nub && is_file($nub . '/nubuilder4.sql')) $out['nuBuilder Forte: nubuilder4.sql'] = $nub . '/nubuilder4.sql';
		foreach (['_install' => NU_ROOT . '/_install', '_update' => NuUpdate::folderDir()] as $label => $dir) {
			foreach (glob($dir . '/*') ?: [] as $f) {
				if (self::isUpload($f)) continue;                     // a left-over upload of this page, not a file to offer
				if (self::isPackage($f)) { $out[$label . '/' . basename($f)] = $f; continue; }
				$b = basename($f);
				if (!is_file($f) || !preg_match('/\.(sql|sql\.gz|zip)$/i', $b) || str_starts_with($b, 'nunext-lib-') || preg_match('/^nunext.*\.zip$/i', $b)) continue;
				if (str_ends_with(strtolower($b), '.zip') && !self::zipHasSql($f)) continue;
				$out[$label . '/' . $b] = $f;
			}
		}
		return $out;
	}

	/** A nuBuilder Next package (nunext-manifest.json at the top or below nunext/). */
	public static function isPackage(string $f): bool
	{
		if (!is_file($f) || !str_ends_with(strtolower($f), '.zip') || str_starts_with(basename($f), 'nunext-lib-') || !class_exists('ZipArchive')) return false;
		$z = new ZipArchive();
		if ($z->open($f) !== true) return false;
		$has = NuUpdate::packagePrefix($z) !== null;
		$z->close();
		return $has;
	}

	private static function zipHasSql(string $f): bool
	{
		if (!class_exists('ZipArchive')) return false;
		$z = new ZipArchive();
		if ($z->open($f) !== true) return false;
		$has = false;
		for ($i = 0; $i < $z->numFiles && !$has; $i++) $has = (bool)preg_match('/\.sql$/i', (string)$z->getNameIndex($i));
		$z->close();
		return $has;
	}

	/** An uploaded file into _update/ (deny-all) as dbreset-<random>.<ext>. @return string path */
	public static function storeUpload(array $f): string
	{
		$err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException(t('ins_dump_err_size', (string)nu_ini_get('upload_max_filesize')));
		if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) throw new RuntimeException(t('ins_dump_err_upload'));
		if (!preg_match('/\.(sql|sql\.gz|zip)$/i', (string)($f['name'] ?? ''), $m)) throw new RuntimeException(t('ins_dump_err_type'));
		$dest = NuUpdate::folderDir() . '/dbreset-' . bin2hex(random_bytes(4)) . '.' . strtolower($m[1]);
		if (!@move_uploaded_file((string)$f['tmp_name'], $dest)) throw new RuntimeException(t('ins_dump_err_upload'));
		@chmod($dest, 0640);
		return $dest;
	}

	/** An upload of this page (_update/dbreset-*) – deleted after its run or Cancel. */
	public static function isUpload(string $path): bool
	{
		return (bool)preg_match('/^dbreset-[0-9a-f]{8}\.(sql|sql\.gz|zip)$/i', basename($path)) && dirname($path) === NuUpdate::folderDir();
	}

	public static function forgetUpload(string $path): void
	{
		if (self::isUpload($path) && is_file($path)) @unlink($path);
	}

	/** Housekeeping: uploads older than a day (a check that was never run or cancelled). @return int files deleted */
	public static function purgeUploads(int $age = 86400): int
	{
		$n = 0;
		foreach (glob(NuUpdate::folderDir() . '/dbreset-*') ?: [] as $f) {
			if (self::isUpload($f) && is_file($f) && (int)@filemtime($f) < time() - $age && @unlink($f)) $n++;
		}
		return $n;
	}

	/** Tables and views of the database now: [[name, type, rows]] (rows: estimate of information_schema). */
	public static function currentTables(): array
	{
		$db = nu_db();
		if ($db->type === 'sqlsrv') {
			$rows = $db->all("SELECT o.name AS t, CASE o.type WHEN 'V' THEN 'VIEW' ELSE 'BASE TABLE' END AS k, "
				. "(SELECT SUM(p.rows) FROM sys.partitions p WHERE p.object_id = o.object_id AND p.index_id IN (0, 1)) AS r "
				. "FROM sys.objects o WHERE o.type IN ('U', 'V') AND o.is_ms_shipped = 0 AND o.schema_id = SCHEMA_ID() ORDER BY o.name");
			return array_map(static fn($r) => [(string)$r['t'], (string)$r['k'], $r['r'] === null ? null : (int)$r['r']], $rows);
		}
		return array_map(static fn($r) => [(string)$r['t'], (string)$r['k'], $r['r'] === null ? null : (int)$r['r']],
			nu_db()->all('SELECT TABLE_NAME AS t, TABLE_TYPE AS k, TABLE_ROWS AS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'));
	}

	/** Dry run: the file read and checked (only allowed statements), what it creates, what would be deleted. */
	public static function check(string $path): array
	{
		nu_time_limit(0);   // a large file takes longer than max_execution_time to read through
		if ($path === self::CLEAN) return self::checkClean();
		if (!self::filesAllowed()) return ['ok' => false, 'error' => t('dbr_err_mysql_only')];
		if (self::isPackage($path)) return self::checkPackage($path);
		// read line by line: no size limit, routines / triggers between DELIMITER lines
		try { $chk = NuInstall::checkDump(NuSqlImport::statements($path), true); } catch (RuntimeException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
		$tables = self::currentTables();
		clearstatcache();
		// views the database user may not create are left out of the import (NuSqlImport::skippable) – said BEFORE anything is dropped
		$chk['views_blocked'] = $chk['ok'] && !empty($chk['views']) && !NuSqlImport::canCreateView(nu_db());
		return $chk + ['file' => basename($path), 'bytes' => NuSqlImport::size($path), 'fsize' => (int)@filesize($path), 'mtime' => (int)@filemtime($path),
			'current' => $tables, 'database' => self::dbName()];
	}

	/** Dry run of a clean installation: the install scripts of the database type, what would be deleted. */
	public static function checkClean(): array
	{
		$files = array_filter(NuInstall::schemaFiles('nunext', nu_db()->type, null), 'is_file');
		if (!$files) return ['ok' => false, 'error' => t('dbr_err_noscripts')];
		$n = 0;
		foreach ($files as $f) $n += preg_match_all('/\bCREATE\s+TABLE\b/i', (string)file_get_contents($f));
		return ['ok' => true, 'kind' => 'clean', 'file' => t('dbr_src_clean'), 'bytes' => 0, 'tables' => $n, 'app' => 0, 'forms' => 0, 'users' => 0,
			'current' => self::currentTables(), 'database' => self::dbName()];
	}

	/** Deletes every table and view of the database (SQL Server: foreign keys first). @return int objects dropped */
	public static function dropAll(NuDB $db): int
	{
		$n = 0;
		if ($db->type === 'sqlsrv') {
			$q = static fn(string $x) => '[' . str_replace(']', ']]', $x) . ']';
			foreach ($db->all('SELECT OBJECT_NAME(f.parent_object_id) AS t, f.name AS f FROM sys.foreign_keys f WHERE OBJECT_SCHEMA_NAME(f.parent_object_id) = SCHEMA_NAME()') as $r) {
				$db->exec('ALTER TABLE ' . $q((string)$r['t']) . ' DROP CONSTRAINT ' . $q((string)$r['f']));
			}
			foreach (self::currentTables() as [$t, $kind]) {
				$db->exec(($kind === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . $q($t));
				$n++;
			}
			return $n;
		}
		$db->exec('SET FOREIGN_KEY_CHECKS = 0');
		try {
			foreach (self::currentTables() as [$t, $kind]) {
				$db->exec(($kind === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . '`' . str_replace('`', '``', $t) . '`');
				$n++;
			}
		} finally {
			$db->exec('SET FOREIGN_KEY_CHECKS = 1');
		}
		return $n;
	}

	/**
	 * Clean installation: everything dropped, nuBuilder Next's install scripts of the database type imported, own tables, steps,
	 * optionally the sample application. One request – the scripts are small.
	 * @return array{backup: string, dropped: int, statements: int, upgraded: array, steps: array, sample: string}
	 */
	public static function runClean(bool $backup, bool $sample, string $via = 'page'): array
	{
		if (!self::available()) throw new RuntimeException(t('dbr_err_unavailable'));
		nu_time_limit(0);
		nu_ignore_abort();
		$chk = self::checkClean();
		if (!$chk['ok']) throw new RuntimeException($chk['error']);
		$out = ['backup' => '', 'dropped' => 0, 'statements' => 0, 'upgraded' => [], 'steps' => [], 'sample' => ''];
		if ($backup) $out['backup'] = NuUpdate::backup('dbreset', 'all', false);
		$db = nu_db();
		$dbName = self::dbName();
		error_log('nuBuilder Next: clean installation of database ' . $dbName . ' (' . $via . ')');
		$out['dropped'] = self::dropAll($db);
		foreach (NuInstall::schemaFiles('nunext', $db->type, null) as $f) if (is_file($f)) $out['statements'] += NuInstall::importFile($db, $f);
		NuDB::forgetColumns('');
		try { NuSettings::create($db); } catch (Throwable $e) { error_log('nuBuilder Next clean installation, settings table: ' . $e->getMessage()); }
		NuSchema::allow();
		NuSchema::create();
		NuInstall::setOrigin('nunext');
		NuSecurity::save(['record_check' => true, 'protect_fields' => true]);
		$out['steps'] = NuMigrate::run();
		if ($sample) {
			try { NuSample::install(); $out['sample'] = 'ok'; } catch (Throwable $e) { $out['sample'] = $e->getMessage(); }
		}
		if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && ($u = NuAuth::user())) {
			unset($_SESSION[NuSess::SESS]);
			try { NuSessions::touch($u); } catch (Throwable $ig) { nu_ignored($ig); }
		}
		unset($_SESSION[NuSess::SCHEMA], $_SESSION[NuSess::DB_READY]);
		NuLog::write('database_reset', $dbName, '', ['file' => 'clean', 'dropped' => $out['dropped'], 'statements' => $out['statements'],
			'backup' => $out['backup'], 'sample' => $out['sample'], 'via' => $via]);
		return $out;
	}

	/**
	 * Runs the reset. $backup: a backup with the whole database first (Update → Backups). $sample: the sample application afterwards.
	 * @return array{backup: string, dropped: int, statements: int, upgraded: array, steps: array, sample: string, skipped?: list<string>, skipped_sql?: array<string, string>}
	 */
	public static function run(string $path, bool $backup, bool $sample, string $via = 'page', ?callable $progress = null): array
	{
		if ($path === self::CLEAN) return self::runClean($backup, $sample, $via);
		if (!self::available() || !self::filesAllowed()) throw new RuntimeException(t('dbr_err_unavailable'));
		nu_time_limit(0);
		nu_ignore_abort();   // a closed tab / dropped connection must not stop the reset half way
		$chk = NuInstall::checkDump(NuSqlImport::statements($path), true);
		if (!$chk['ok']) throw new RuntimeException($chk['error']);
		$out = ['backup' => '', 'dropped' => 0, 'statements' => 0, 'upgraded' => [], 'steps' => [], 'sample' => ''];
		if ($backup) $out['backup'] = NuUpdate::backup('dbreset', 'all', false);
		$db = nu_db();
		$dbName = self::dbName();
		error_log('nuBuilder Next: reset database ' . $dbName . ' from ' . basename($path) . ' (' . $via . ')');
		$out['dropped'] = self::dropAll($db);
		$db->exec('SET FOREIGN_KEY_CHECKS = 0');
		try {
			$out['statements'] = NuSqlImport::run($db, $path, $progress, true);
			$out['skipped'] = NuSqlImport::$skipped;
			$out['skipped_sql'] = NuSqlImport::$skippedSql;
		} finally {
			$db->exec('SET FOREIGN_KEY_CHECKS = 1');
		}
		NuDB::forgetColumns('');                            // all tables
		$out['upgraded'] = NuInstall::upgradeTo49($db);
		try { NuSettings::create($db); } catch (Throwable $e) { error_log('nuBuilder Next reset, settings table: ' . $e->getMessage()); }
		NuSchema::allow();
		NuSchema::create();
		NuInstall::setOrigin('nunext');                     // set up by nuBuilder Next itself – not blocked as an existing database
		NuSecurity::save(['record_check' => true, 'protect_fields' => true]);   // the defaults of a new installation
		$out['steps'] = NuMigrate::run();
		if ($sample) {
			try { NuSample::install(); $out['sample'] = 'ok'; } catch (Throwable $e) { $out['sample'] = $e->getMessage(); }
		}
		if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && ($u = NuAuth::user())) {   // stay signed in: the sessions table is new
			unset($_SESSION[NuSess::SESS]);
			try { NuSessions::touch($u); } catch (Throwable $ig) { nu_ignored($ig); }
		}
		NuLog::write('database_reset', $dbName, '', ['file' => basename($path), 'dropped' => $out['dropped'], 'statements' => $out['statements'],
			'backup' => $out['backup'], 'sample' => $out['sample'], 'via' => $via]);
		return $out;
	}

	// ------------------------------------------------------------------ the reset in steps
	/*
	 * A large file takes minutes; web hosts end a request long before (a proxy answers 503 / 504). From the page the reset therefore
	 * runs as a JOB in short steps: startJob() writes a job file next to the upload (_update/, deny-all) and gives the page a random
	 * token; the page (assets/js/nudbreset.js) calls ?p=dbresetstep with it again and again (index.php, before nu_boot: no session,
	 * no sign-in – the token is the right, the database has no sessions table in between). step() works for JOB_SECONDS, saves where
	 * it stopped and answers: backup → unpack (.zip / .gz to a plain .sql, which can be read from any byte on) → drop → import in
	 * slices (NuSqlImport::slice) → finish (as run()) → done. A step that is still running holds a lock: a second call answers
	 * "busy" and the page asks again. A dropped connection therefore loses nothing; reloading the page goes on where it was.
	 */
	public const JOB_SECONDS = 12;

	private static function jobPath(): string
	{
		return NuUpdate::folderDir() . '/.dbreset-job.json';
	}

	/** @return array<string, mixed>|null */
	private static function jobRead(): ?array
	{
		$f = self::jobPath();
		if (!is_file($f)) return null;
		$j = json_decode((string)@file_get_contents($f), true);
		return is_array($j) ? $j : null;
	}

	/** @param array<string, mixed> $j */
	private static function jobWrite(array $j): void
	{
		$j['updated'] = time();
		$f = self::jobPath();
		if (@file_put_contents($f . '.tmp', json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !@rename($f . '.tmp', $f)) {
			throw new RuntimeException(t('dbr_err_job_file'));
		}
		@chmod($f, 0640);
	}

	/** The job of this token (any phase), or null. @return array<string, mixed>|null */
	public static function job(string $token): ?array
	{
		$j = self::jobRead();
		return $j && $token !== '' && hash_equals((string)($j['th'] ?? ''), hash('sha256', $token)) ? $j : null;
	}

	/**
	 * Starts a reset from the page (the check was done on this file: size and time unchanged). @return string the token
	 */
	public static function startJob(string $path, bool $backup, bool $sample, array $chk): string
	{
		if (!self::available()) throw new RuntimeException(t('dbr_err_unavailable'));
		$old = self::jobRead();
		if ($old && !in_array($old['phase'] ?? '', ['done', 'error'], true) && time() - (int)($old['updated'] ?? 0) < 600) throw new RuntimeException(t('dbr_err_job_running'));
		clearstatcache();
		if (!is_file($path) || (int)@filesize($path) !== (int)($chk['fsize'] ?? -1) || (int)@filemtime($path) !== (int)($chk['mtime'] ?? -1)) throw new RuntimeException(t('dbr_err_changed'));
		self::jobClean($old);
		$token = bin2hex(random_bytes(24));
		self::jobWrite([
			'th' => hash('sha256', $token), 'path' => $path, 'work' => '', 'backup' => $backup, 'sample' => $sample,
			'phase' => $backup ? 'backup' : 'unpack', 'total' => max(1, (int)($chk['bytes'] ?? 0)), 'st' => ['off' => 0, 'n' => 0],
			'database' => (string)nu_db()->value('SELECT DATABASE()'), 'user' => NuAuth::currentRaw(),
			'started' => time(), 'result' => null, 'error' => '', 'stayed' => false,
		]);
		error_log('nuBuilder Next: reset database ' . $chk['database'] . ' from ' . basename($path) . ' (page, in steps)');
		return $token;
	}

	/** The plain work copy and the upload of a finished / failed / given-up job. @param array<string, mixed>|null $j */
	private static function jobClean(?array $j): void
	{
		if (!$j) return;
		$w = (string)($j['work'] ?? '');
		if ($w !== '' && dirname($w) === NuUpdate::folderDir() && str_starts_with(basename($w), '.dbreset-work')) { @unlink($w); @unlink($w . '.part'); }
		if (in_array($j['phase'] ?? '', ['done', 'error'], true)) self::forgetUpload((string)($j['path'] ?? ''));
	}

	/** What the page shows. @param array<string, mixed> $j @return array<string, mixed> */
	private static function jobStatus(array $j, bool $busy = false): array
	{
		$ph = (string)$j['phase'];
		$pct = match ($ph) {
			'backup' => 0, 'unpack' => 1, 'drop' => 2,
			'import' => 2 + (int)floor(95 * min(1, (int)($j['st']['off'] ?? 0) / max(1, (int)$j['total']))),
			'finish' => 97, default => 100,
		};
		$n = (int)($j['st']['n'] ?? 0);
		return ['ok' => $ph !== 'error', 'phase' => $ph, 'pct' => $pct, 'busy' => $busy, 'done' => $ph === 'done',
			'text' => $ph === 'import' ? t('dbr_ph_import', number_format($n, 0, '', ' ')) : t('dbr_ph_' . $ph),
			'error' => $ph === 'error' ? t('dbr_err_failed', (string)$j['error']) : ''];
	}

	/**
	 * One step of the job ($token from startJob). Never longer than about JOB_SECONDS, except a backup and the finish (one piece each);
	 * those go on after a dropped connection too. @return array<string, mixed> status for the page
	 */
	public static function step(string $token): array
	{
		$j = self::job($token);
		if (!$j) return ['ok' => false, 'phase' => 'none', 'error' => t('dbr_err_no_job')];
		if (in_array($j['phase'], ['done', 'error'], true)) return self::jobStatus($j);
		$lock = @fopen(self::jobPath() . '.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return self::jobStatus($j, true); }
		nu_time_limit(0);
		nu_ignore_abort();
		$start = microtime(true);
		$max = (int)nu_ini_get('max_execution_time');
		$until = $start + ($max > 0 ? min(self::JOB_SECONDS, max(3, intdiv($max, 3))) : self::JOB_SECONDS);
		try {
			$j = self::job($token) ?? $j;                                  // read again under the lock
			$db = nu_db();
			while (!in_array($j['phase'], ['done', 'error'], true) && microtime(true) < $until) {
				switch ($j['phase']) {
					case 'backup':
						$j['result']['backup'] = NuUpdate::backup('dbreset', 'all', false);
						$j['phase'] = 'unpack';
						break;
					case 'unpack':
						$path = (string)$j['path'];
						if (!is_file($path)) throw new RuntimeException(t('dbr_err_changed'));
						$lc = strtolower($path);
						if (str_ends_with($lc, '.zip') || str_ends_with($lc, '.gz')) {
							$p = NuSqlDump::spaceProblem(NuUpdate::folderDir(), (int)$j['total']);
							if ($p !== null) throw new RuntimeException($p);
						}
						$j['work'] = NuSqlImport::plainCopy($path, NuUpdate::folderDir() . '/.dbreset-work-' . substr((string)$j['th'], 0, 8) . '.sql');
						$j['total'] = max(1, (int)@filesize((string)$j['work']));
						$j['phase'] = 'drop';
						break;
					case 'drop':
						$db->exec('SET FOREIGN_KEY_CHECKS = 0');
						$j['result']['dropped'] = 0;
						foreach (self::currentTables() as [$t, $kind]) {
							$db->exec(($kind === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . '`' . str_replace('`', '``', $t) . '`');
							$j['result']['dropped']++;
						}
						$db->exec('SET FOREIGN_KEY_CHECKS = 1');
						$j['phase'] = 'import';
						break;
					case 'import':
						$db->exec('SET FOREIGN_KEY_CHECKS = 0');
						$st = (array)$j['st'];
						try {
							$fin = NuSqlImport::slice($db, (string)$j['work'], $st, $until, true);
						} finally {
							$j['st'] = $st;
							try { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $ig) { nu_ignored($ig); }
						}
						if ($fin) $j['phase'] = 'finish';
						break;
					case 'finish':
						$j['result'] = self::finishImport($db, $j);
						$j['phase'] = 'done';
						break;
					default:
						throw new RuntimeException('phase ' . $j['phase']);
				}
				self::jobWrite($j);
			}
		} catch (Throwable $e) {
			error_log('nuBuilder Next reset database (step ' . $j['phase'] . '): ' . $e->getMessage());
			$j['error'] = $e->getMessage();
			$j['failed_in'] = $j['phase'];
			$j['phase'] = 'error';
			try { self::jobWrite($j); } catch (Throwable $ig) { nu_ignored($ig); }
		} finally {
			if (in_array($j['phase'], ['done', 'error'], true)) self::jobClean($j);
			flock($lock, LOCK_UN);
			fclose($lock);
		}
		return self::jobStatus($j);
	}

	/** The end of a reset in steps – as run() after the import (no session here: the page re-signs the user in, afterJob()). @param array<string, mixed> $j */
	private static function finishImport(NuDB $db, array $j): array
	{
		NuDB::forgetColumns('');
		$out = ['backup' => (string)($j['result']['backup'] ?? ''), 'dropped' => (int)($j['result']['dropped'] ?? 0), 'statements' => (int)$j['st']['n'],
			'upgraded' => NuInstall::upgradeTo49($db), 'steps' => [], 'sample' => '', 'skipped' => array_values((array)($j['st']['skipped'] ?? [])),
			'skipped_sql' => (array)($j['st']['skipped_sql'] ?? [])];
		try { NuSettings::create($db); } catch (Throwable $e) { error_log('nuBuilder Next reset, settings table: ' . $e->getMessage()); }
		NuSchema::allow();
		NuSchema::create();
		NuInstall::setOrigin('nunext');
		NuSecurity::save(['record_check' => true, 'protect_fields' => true]);
		$out['steps'] = NuMigrate::run();
		if (!empty($j['sample'])) {
			try { NuSample::install(); $out['sample'] = 'ok'; } catch (Throwable $e) { $out['sample'] = $e->getMessage(); }
		}
		$u = is_array($j['user'] ?? null) ? $j['user'] : null;
		NuLog::write('database_reset', (string)$j['database'], '', ['file' => basename((string)$j['path']), 'dropped' => $out['dropped'], 'statements' => $out['statements'], 'skipped' => $out['skipped'],
			'backup' => $out['backup'], 'sample' => $out['sample'], 'via' => 'page'], $u && $u['id'] !== '' ? $u : null);
		return $out;
	}

	/**
	 * index.php, before the sign-in check: once the job of this session is done, the user stays signed in – the sessions table is new
	 * (as run() does at its end). Cheap: only with a job token in the session.
	 */
	public static function afterJob(): void
	{
		$tok = $_SESSION[NuSess::DB_RESET]['job'] ?? '';
		if (!is_string($tok) || $tok === '' || !empty($_SESSION[NuSess::DB_RESET]['stayed'])) return;
		$j = self::job($tok);
		if (!$j || $j['phase'] !== 'done') return;
		unset($_SESSION[NuSess::SESS], $_SESSION[NuSess::SCHEMA], $_SESSION[NuSess::DB_READY]);
		$_SESSION[NuSess::DB_RESET]['stayed'] = true;
	}

	/** Dry run of a fresh installation: the package checked (signature must be valid), its version, packs, what would be deleted. */
	public static function checkPackage(string $path): array
	{
		nu_time_limit(0);
		$i = NuUpdate::inspect($path);
		$out = ['kind' => 'package', 'file' => basename($path), 'bytes' => (int)@filesize($path), 'version' => $i['version'],
			'signature' => (string)($i['signature'] ?? ''), 'packs' => self::packageLibs($path, $i['prefix'] ?? ''), 'current' => self::currentTables(),
			'database' => self::dbName(), 'installed_packs' => self::installedPacks()];
		if (!$i['ok']) return $out + ['ok' => false, 'error' => implode(' ', $i['errors'])];
		if ($out['signature'] !== 'valid') return $out + ['ok' => false, 'error' => t('dbr_err_unsigned')];
		if (!is_file(NU_ROOT . '/install/schema_mysql.sql') && !isset($i['manifest']['files']['install/schema_mysql.sql'])) return $out + ['ok' => false, 'error' => t('dbr_err_noscripts')];
		return $out + ['ok' => true];
	}

	/** Library packs inside a package (_install/nunext-lib-*.zip). @return list<string> file names */
	private static function packageLibs(string $zip, string $prefix): array
	{
		$out = [];
		$z = new ZipArchive();
		if ($z->open($zip) !== true) return $out;
		for ($n = 0; $n < $z->numFiles; $n++) {
			$name = (string)$z->getNameIndex($n);
			if (preg_match('#^' . preg_quote($prefix, '#') . '_install/(nunext-lib-[A-Za-z0-9._+-]+\.zip)$#', $name, $m)) $out[] = $m[1];
		}
		$z->close();
		sort($out);
		return $out;
	}

	/** Folders below vendor/packs/ (installed library packs). @return list<string> */
	private static function installedPacks(): array
	{
		return array_values(array_map('basename', array_filter(glob(NuLibs::baseDir() . '/*', GLOB_ONLYDIR) ?: [], static fn($d) => !str_starts_with(basename($d), '.'))));
	}

	/**
	 * Fresh installation from a package. Files first (NuUpdate::install – a backup of the files and, with $backup, the whole database),
	 * then library packs, then the database: all tables dropped, the NEW install scripts imported, the own tables created (origin
	 * nunext). The database steps and the sample application follow on the next request (finishFresh) – with the new code.
	 * @return array{version: string, backup: string, dropped: int, statements: int, packs: list<array{0: string, 1: bool, 2: string}>}
	 */
	public static function runPackage(string $path, bool $backup, bool $sample, string $via = 'page'): array
	{
		if (!self::available() || !self::filesAllowed()) throw new RuntimeException(t('dbr_err_unavailable'));
		nu_time_limit(0);
		nu_ignore_abort();   // a closed tab / dropped connection must not stop the reset half way
		$chk = self::checkPackage($path);
		if (!$chk['ok']) throw new RuntimeException($chk['error']);
		$info = NuUpdate::inspect($path);
		// load every class this request still needs before the files change under it (no mixing of versions in one request)
		foreach (['NuLibs', 'NuInstall', 'NuSettings', 'NuSessions', 'NuSecurity', 'NuLog', 'NuDB', 'NuSchema'] as $cls) class_exists($cls);
		$db = nu_db();
		$dbName = self::dbName();
		$from = NU_VERSION;
		error_log('nuBuilder Next: fresh installation of ' . $info['version'] . ' on ' . $dbName . ' (' . $via . ')');
		$out = ['version' => $info['version'], 'from' => $from, 'backup' => '', 'dropped' => 0, 'statements' => 0, 'packs' => []];
		$out['backup'] = NuUpdate::install($path, $info, $backup ? 'all' : 'none');      // maintenance mode on, files copied
		unset($_SESSION[NuSess::UPDATE]);
		// library packs: the package's own only
		$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/nunext-fresh-' . bin2hex(random_bytes(6));
		@mkdir($tmp, 0700, true);
		try {
			foreach (self::installedPacks() as $d) self::rmTree(NuLibs::baseDir() . '/' . $d);
			@unlink(NuLibs::baseDir() . '/nunext-libs.php');
			$z = new ZipArchive();
			if ($z->open($path) === true) {
				foreach ($chk['packs'] as $lib) {
					$f = $tmp . '/' . $lib;
					$ok = false;
					$err = '';
					try {
						$data = $z->getFromName($info['prefix'] . '_install/' . $lib);
						if ($data === false || file_put_contents($f, $data) === false) throw new RuntimeException(t('upd_err_zip'));
						NuLibs::install($f, 'dbreset');
						$ok = true;
					} catch (Throwable $e) {
						$err = $e->getMessage();
						error_log('nuBuilder Next fresh installation, pack ' . $lib . ': ' . $err);
					}
					$out['packs'][] = [$lib, $ok, $err];
				}
				$z->close();
			}
		} finally {
			self::rmTree($tmp);
		}
		// database: everything dropped, the package's install scripts (now on disk)
		$db->exec('SET FOREIGN_KEY_CHECKS = 0');
		try {
			foreach (self::currentTables() as [$t, $kind]) {
				$db->exec(($kind === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . '`' . str_replace('`', '``', $t) . '`');
				$out['dropped']++;
			}
			foreach (NuInstall::schemaFiles('nunext', 'mysql', null) as $f) if (is_file($f)) $out['statements'] += NuInstall::importFile($db, $f);
		} finally {
			$db->exec('SET FOREIGN_KEY_CHECKS = 1');
		}
		NuDB::forgetColumns('');
		try { NuSettings::create($db); } catch (Throwable $e) { error_log('nuBuilder Next fresh installation, settings table: ' . $e->getMessage()); }
		NuSchema::allow();
		NuSchema::create();
		NuInstall::setOrigin('nunext');
		NuSecurity::save(['record_check' => true, 'protect_fields' => true]);
		if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && ($u = NuAuth::user())) {
			unset($_SESSION[NuSess::SESS]);
			try { NuSessions::touch($u); } catch (Throwable $ig) { nu_ignored($ig); }
		}
		unset($_SESSION[NuSess::SCHEMA], $_SESSION[NuSess::DB_READY]);
		$_SESSION[NuSess::DB_RESET_FRESH] = $out + ['sample' => $sample, 'file' => basename($path)];
		NuLog::write('database_fresh', $dbName, '', ['file' => basename($path), 'from' => $from, 'to' => $info['version'], 'dropped' => $out['dropped'],
			'statements' => $out['statements'], 'backup' => $out['backup'], 'packs' => array_map(static fn($p) => $p[0] . ($p[1] ? '' : ' (failed)'), $out['packs']), 'via' => $via]);
		return $out;
	}

	/**
	 * Second half of a fresh installation, with the new code (?p=dbreset&fresh=1): the database steps, the sample application,
	 * maintenance mode off. @return array|null the result for the page, null when nothing is pending
	 */
	public static function finishFresh(): ?array
	{
		$r = $_SESSION[NuSess::DB_RESET_FRESH] ?? null;
		if (!is_array($r)) return null;
		unset($_SESSION[NuSess::DB_RESET_FRESH]);
		$r['steps'] = [];
		try { NuSchema::allow(); NuSchema::create(); $r['steps'] = NuMigrate::run(); } catch (Throwable $e) { $r['error'] = $e->getMessage(); }
		$want = !empty($r['sample']);
		$r['sample'] = '';
		if ($want) {
			try { NuSample::install(); $r['sample'] = 'ok'; } catch (Throwable $e) { $r['sample'] = $e->getMessage(); }
		}
		NuUpdate::maintenanceOff();
		NuUpdate::resetCaches();
		$_SESSION[NuSess::SCHEMA] = NuSchema::VERSION . '/' . NU_VERSION;
		return $r;
	}

	private static function rmTree(string $dir): void
	{
		if (!is_dir($dir) || is_link($dir)) { if (is_file($dir) || is_link($dir)) @unlink($dir); return; }
		foreach (scandir($dir) ?: [] as $e) {
			if ($e === '.' || $e === '..') continue;
			self::rmTree($dir . '/' . $e);
		}
		@rmdir($dir);
	}
}
