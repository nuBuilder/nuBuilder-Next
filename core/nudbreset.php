<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Reset database (0.8.628, developer mode only): deletes ALL tables of the database and imports a nuBuilder Forte (4.x) SQL file –
 * nubuilder4.sql of any 4.x (made 4.9 with NuInstall::upgradeTo49) or a complete dump of a nuBuilder database with the application's
 * tables – then creates nuBuilder Next's tables (NuSchema + NuMigrate, origin "nunext": set up by nuBuilder Next itself, not blocked as
 * an existing database) and optionally the sample application. nuconfig.php, files, library packs, custom pages and backups stay.
 * Page: Admin tools → Reset database (pages/nudbreset.php, globeadmin, recent password, typed database name, optional full backup).
 * Command line: php nucron.php reset --sql=<file> [--backup] [--sample] --yes. MySQL / MariaDB only.
 * Fresh installation from a package (0.8.844): a signed nunext.zip (any version, also older or the same) – its files are installed
 * (NuUpdate::install, backup first), library packs replaced by the package's own (_install/), ALL tables deleted and the database built
 * from the package's install scripts; the database steps and the sample application run on the next request with the new code
 * (finishFresh(), ?p=dbreset&fresh=1). nuconfig.php, custom/ and the backups stay.
 */
final class NuDbReset
{
	/** Developer mode and MySQL – the globeadmin check is the caller's (page) or the shell's (nucron.php). */
	public static function available(): bool
	{
		$dev = nu_cfg('nuConfigDevMode');
		$on = $dev === true || $dev === 1 || (is_string($dev) && in_array(strtolower(trim($dev)), ['1', 'true', 'yes', 'on'], true));
		try {
			return $on && nu_db()->type === 'mysql';
		} catch (Throwable) {
			return false;
		}
	}

	/** SQL files offered: nubuilder4.sql of the nuBuilder Forte folder, .sql / .sql.gz / .zip with SQL in _install/ and _update/. @return array<string, string> label => path */
	public static function sources(): array
	{
		$out = [];
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
		$has = $z->locateName(NuUpdate::MANIFEST) !== false || $z->locateName('nunext/' . NuUpdate::MANIFEST) !== false;
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

	/** An upload of this page (_update/dbreset-*) – deleted after its run or Cancel (0.8.847). */
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
		return array_map(static fn($r) => [(string)$r['t'], (string)$r['k'], $r['r'] === null ? null : (int)$r['r']],
			nu_db()->all('SELECT TABLE_NAME AS t, TABLE_TYPE AS k, TABLE_ROWS AS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'));
	}

	/** Dry run: the file read and checked (only allowed statements), what it creates, what would be deleted. */
	public static function check(string $path): array
	{
		if (self::isPackage($path)) return self::checkPackage($path);
		$sql = NuInstall::dumpSql($path);
		if ($sql === null) return ['ok' => false, 'error' => t('ins_dump_err_read')];
		$chk = NuInstall::checkDump($sql, true);
		$tables = self::currentTables();
		return $chk + ['file' => basename($path), 'bytes' => strlen($sql), 'current' => $tables, 'database' => (string)nu_db()->value('SELECT DATABASE()')];
	}

	/**
	 * Runs the reset. $backup: a backup with the whole database first (Update → Backups). $sample: the sample application afterwards.
	 * @return array{backup: string, dropped: int, statements: int, upgraded: array, steps: array, sample: string}
	 */
	public static function run(string $path, bool $backup, bool $sample, string $via = 'page'): array
	{
		if (!self::available()) throw new RuntimeException(t('dbr_err_unavailable'));
		nu_time_limit(0);
		$sql = NuInstall::dumpSql($path);
		if ($sql === null) throw new RuntimeException(t('ins_dump_err_read'));
		$chk = NuInstall::checkDump($sql, true);
		if (!$chk['ok']) throw new RuntimeException($chk['error']);
		$out = ['backup' => '', 'dropped' => 0, 'statements' => 0, 'upgraded' => [], 'steps' => [], 'sample' => ''];
		if ($backup) $out['backup'] = NuUpdate::backup('dbreset', 'all', false);
		$db = nu_db();
		$dbName = (string)$db->value('SELECT DATABASE()');
		error_log('nuBuilder Next: reset database ' . $dbName . ' from ' . basename($path) . ' (' . $via . ')');
		$db->exec('SET FOREIGN_KEY_CHECKS = 0');
		try {
			foreach (self::currentTables() as [$t, $kind]) {
				$db->exec(($kind === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . '`' . str_replace('`', '``', $t) . '`');
				$out['dropped']++;
			}
			$out['statements'] = NuInstall::importSql($db, $sql, basename($path));
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
			unset($_SESSION['nu_sess']);
			try { NuSessions::touch($u); } catch (Throwable) {}
		}
		NuLog::write('database_reset', $dbName, '', ['file' => basename($path), 'dropped' => $out['dropped'], 'statements' => $out['statements'],
			'backup' => $out['backup'], 'sample' => $out['sample'], 'via' => $via]);
		return $out;
	}

	/** Dry run of a fresh installation: the package checked (signature must be valid), its version, packs, what would be deleted. */
	public static function checkPackage(string $path): array
	{
		$i = NuUpdate::inspect($path);
		$out = ['kind' => 'package', 'file' => basename($path), 'bytes' => (int)@filesize($path), 'version' => $i['version'],
			'signature' => (string)($i['signature'] ?? ''), 'packs' => self::packageLibs($path, $i['prefix'] ?? ''), 'current' => self::currentTables(),
			'database' => (string)nu_db()->value('SELECT DATABASE()'), 'installed_packs' => self::installedPacks()];
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
		if (!self::available()) throw new RuntimeException(t('dbr_err_unavailable'));
		nu_time_limit(0);
		$chk = self::checkPackage($path);
		if (!$chk['ok']) throw new RuntimeException($chk['error']);
		$info = NuUpdate::inspect($path);
		// load every class this request still needs before the files change under it (no mixing of versions in one request)
		foreach (['NuLibs', 'NuInstall', 'NuSettings', 'NuSessions', 'NuSecurity', 'NuLog', 'NuDB', 'NuSchema'] as $cls) class_exists($cls);
		$db = nu_db();
		$dbName = (string)$db->value('SELECT DATABASE()');
		$from = NU_VERSION;
		error_log('nuBuilder Next: fresh installation of ' . $info['version'] . ' on ' . $dbName . ' (' . $via . ')');
		$out = ['version' => $info['version'], 'from' => $from, 'backup' => '', 'dropped' => 0, 'statements' => 0, 'packs' => []];
		$out['backup'] = NuUpdate::install($path, $info, $backup ? 'all' : 'none');      // maintenance mode on, files copied
		unset($_SESSION['nu_update']);
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
			unset($_SESSION['nu_sess']);
			try { NuSessions::touch($u); } catch (Throwable) {}
		}
		unset($_SESSION['nu_schema'], $_SESSION['nu_db_ready']);
		$_SESSION['nu_dbreset_fresh'] = $out + ['sample' => $sample, 'file' => basename($path)];
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
		$r = $_SESSION['nu_dbreset_fresh'] ?? null;
		if (!is_array($r)) return null;
		unset($_SESSION['nu_dbreset_fresh']);
		$r['steps'] = [];
		try { NuSchema::allow(); NuSchema::create(); $r['steps'] = NuMigrate::run(); } catch (Throwable $e) { $r['error'] = $e->getMessage(); }
		$want = !empty($r['sample']);
		$r['sample'] = '';
		if ($want) {
			try { NuSample::install(); $r['sample'] = 'ok'; } catch (Throwable $e) { $r['sample'] = $e->getMessage(); }
		}
		NuUpdate::maintenanceOff();
		NuUpdate::resetCaches();
		$_SESSION['nu_schema'] = NuSchema::VERSION . '/' . NU_VERSION;
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
