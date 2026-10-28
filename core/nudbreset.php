<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Reset database (0.8.628, developer mode only): deletes ALL tables of the database and imports a nuBuilder Forte (4.x) SQL file –
 * nubuilder4.sql of any 4.x (made 4.9 with NuInstall::upgradeTo49) or a complete dump of a nuBuilder database with the application's
 * tables – then creates nuBuilder Next's tables (NuSchema + NuMigrate, origin "nunext": set up by nuBuilder Next itself, not blocked as
 * an existing database) and optionally the sample application. nuconfig.php, files, library packs, custom pages and backups stay.
 * Page: Admin tools → Reset database (pages/nudbreset.php, globeadmin, recent password, typed database name, optional full backup).
 * Command line: php cron.php reset --sql=<file> [--backup] [--sample] --yes. MySQL / MariaDB only.
 */
final class NuDbReset
{
	/** Developer mode and MySQL – the globeadmin check is the caller's (page) or the shell's (cron.php). */
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
				$b = basename($f);
				if (!is_file($f) || !preg_match('/\.(sql|sql\.gz|zip)$/i', $b) || str_starts_with($b, 'nunext-lib-') || preg_match('/^nunext.*\.zip$/i', $b)) continue;
				if (str_ends_with(strtolower($b), '.zip') && !self::zipHasSql($f)) continue;
				$out[$label . '/' . $b] = $f;
			}
		}
		return $out;
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

	/** Tables and views of the database now: [[name, type, rows]] (rows: estimate of information_schema). */
	public static function currentTables(): array
	{
		return array_map(static fn($r) => [(string)$r['t'], (string)$r['k'], $r['r'] === null ? null : (int)$r['r']],
			nu_db()->all('SELECT TABLE_NAME AS t, TABLE_TYPE AS k, TABLE_ROWS AS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'));
	}

	/** Dry run: the file read and checked (only allowed statements), what it creates, what would be deleted. */
	public static function check(string $path): array
	{
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
}
