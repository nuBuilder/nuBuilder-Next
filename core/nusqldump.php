<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * SQL dump of this database into a gzip file – used by the backups of the update page (core/nuupdate.php).
 * The SQL is written by the app cloner's export (core/nuappcloner.php: tables, views in dependency order, data in batches, binary
 * data as hex, functions / procedures / triggers / events without DEFINER, no sessions and one-time tokens), so both stay the same.
 * MySQL / MariaDB and SQL Server (the SQL file then uses GO between batches). The file ends with a marker line; complete() checks it, so a dump that was cut off is never taken for a
 * full one.
 * Restore (0.8.1031, MySQL / MariaDB): restoreCheck() / restore() put the database part of a backup back – the file is read line by line
 * (NuSqlImport), the tables of the dump's scope are dropped first, nuBuilder Next's database steps run afterwards.
 */
final class NuSqlDump
{
	public const MARKER = '-- nuBuilder Next SQL dump completed';
	/** Rows left out with "without logs" (the tables are created, empty). */
	public const LOG_TABLES = ['zzzzsys_debug', 'zzzzsys_email_log', 'zzzzsys_nunext_log', 'zzzzsys_nunext_form_history'];
	// 'nubuilder' (0.8.615): every zzzzsys_* table – nuBuilder Forte's and nuBuilder Next's, all an update can change; 'nunext' only
	// nuBuilder Next's own tables (no longer offered, older backups have it)
	public const SCOPES = ['none', 'nubuilder', 'nunext', 'all'];

	public static function available(): bool
	{
		try { return in_array(nu_db()->type, ['mysql', 'sqlsrv'], true); } catch (Throwable) { return false; }
	}

	/** Tables of a scope ('nubuilder': all zzzzsys_* tables; 'nunext': nuBuilder Next's own zzzzsys_nunext_* tables; 'all': null = everything). */
	public static function tables(string $scope): ?array
	{
		if ($scope === 'all') return null;
		$prefix = $scope === 'nubuilder' ? 'zzzzsys_' : 'zzzzsys_nunext_';
		$out = [];
		foreach (NuAppCloner::objects(nu_db()) as $o) if ($o['type'] === 'TABLE' && str_starts_with(strtolower($o['name']), $prefix)) $out[] = $o['name'];
		return $out;
	}

	/** Size of the data (bytes, from information_schema – an estimate; the SQL text is usually larger, gzip much smaller). */
	public static function estimate(string $scope, bool $withoutLogs): int
	{
		if (!self::available() || $scope === 'none') return 0;
		$sum = 0;
		$only = self::tables($scope);
		$only = $only === null ? null : array_flip($only);
		$skip = $withoutLogs ? array_flip(array_merge(self::LOG_TABLES, NuAppCloner::SESSION_TABLES)) : array_flip(NuAppCloner::SESSION_TABLES);
		$sql = nu_db()->type === 'sqlsrv'
			? "SELECT o.name n, SUM(a.total_pages) * 8192 b FROM sys.objects o JOIN sys.partitions p ON p.object_id = o.object_id
				JOIN sys.allocation_units a ON a.container_id = p.partition_id WHERE o.type = 'U' AND o.schema_id = SCHEMA_ID() GROUP BY o.name"
			: "SELECT TABLE_NAME n, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'";
		foreach (nu_db()->all($sql) as $r) {
			$n = (string)$r['n'];
			if (str_starts_with($n, '___nu') || ($only !== null && !isset($only[$n])) || isset($skip[$n])) continue;
			$sum += (int)$r['b'];
		}
		return $sum;
	}

	/**
	 * Writes the dump to $file (gzip). @return array{complete: bool, bytes: int, tables: int, views: int, rows: int, routines: int, error: string}
	 * An error does not throw: the result says incomplete, with the message.
	 */
	public static function write(string $file, string $scope, bool $withoutLogs): array
	{
		$res = ['scope' => $scope, 'without_logs' => $withoutLogs, 'complete' => false, 'bytes' => 0, 'tables' => 0, 'views' => 0, 'rows' => 0, 'routines' => 0, 'error' => ''];
		$gz = gzopen($file, 'wb6');
		if ($gz === false) { $res['error'] = 'gzopen'; return $res; }
		$all = $scope === 'all';
		$parts = $all ? array_keys(NuAppCloner::PARTS) : ['sys_struct', 'sys_rows', 'app_rows'];
		try {
			$c = new NuAppCloner(nu_db(), ['export' => true, 'parts' => $parts, 'tables' => self::tables($scope), 'insert' => 'INSERT',
				'skip_rows' => $withoutLogs ? self::LOG_TABLES : [], 'title' => 'nuBuilder Next backup' . ($all ? '' : ($scope === 'nubuilder' ? ' (nuBuilder tables zzzzsys_*)' : ' (nuBuilder Next tables)'))]);
			$r = $c->run(static function () {}, static function (string $sql) use ($gz) { gzwrite($gz, $sql); });
			$st = $r['stats'];
			$res = ['tables' => (int)$st['tables'], 'views' => (int)$st['views'], 'rows' => (int)$st['rows'],
				'routines' => (int)$st['functions'] + (int)$st['procedures'] + (int)$st['triggers'] + (int)$st['events'],
				'counts' => (array)($r['counts'] ?? [])] + $res;   // rows per table (0.8.1032): compared after a restore
			gzwrite($gz, "\n" . self::MARKER . ' ' . date('Y-m-d H:i:s') . "\n");
		} catch (Throwable $e) {
			$res['error'] = $e->getMessage();
			gzwrite($gz, "\n-- ERROR: " . str_replace("\n", ' ', $e->getMessage()) . "\n");
		} finally {
			gzclose($gz);
		}
		$res['bytes'] = (int)@filesize($file);
		$res['complete'] = $res['error'] === '' && self::complete($file);
		return $res;
	}

	/** True when the gzip file (path or stream) ends with the marker line – the dump ran to its end. */
	public static function complete(string $file): bool
	{
		$gz = @gzopen($file, 'rb');
		if ($gz === false) return false;
		$tail = '';
		while (!gzeof($gz)) {
			$chunk = gzread($gz, 1 << 20);
			if ($chunk === false) { gzclose($gz); return false; }
			$tail = substr($tail . $chunk, -256);
		}
		gzclose($gz);
		return str_contains($tail, self::MARKER);
	}

	// ------------------------------------------------------------------ restore (0.8.1031)

	/** The database part can be restored here: MySQL / MariaDB (SQL Server: TODO.md – its dump uses GO batches, not tried on a server). */
	public static function canRestore(): bool
	{
		try { return nu_db()->type === 'mysql'; } catch (Throwable) { return false; }
	}

	/** backup.json of a backup zip + whether it holds a complete dump. @return array{meta: array, sql: ?array} */
	private static function backupMeta(string $zip): array
	{
		$z = new ZipArchive();
		if ($z->open($zip) !== true) throw new RuntimeException(t('upd_err_backup_invalid'));
		$meta = json_decode((string)$z->getFromName('backup.json'), true) ?: [];
		$has = $z->locateName(NuUpdate::SQL_ENTRY) !== false;
		$z->close();
		$sql = is_array($meta['sql'] ?? null) ? $meta['sql'] : null;
		if (($meta['product'] ?? '') !== 'nuNext' || !$has || !$sql || !empty($sql['removed'])) throw new RuntimeException(t('rst_err_no_sql'));
		if (empty($sql['complete'])) throw new RuntimeException(t('rst_err_incomplete'));
		return ['meta' => $meta, 'sql' => $sql];
	}

	/** Copies the dump of a backup into a temporary .sql.gz next to the backups (the caller removes it). */
	public static function extract(string $zip): string
	{
		$z = new ZipArchive();
		if ($z->open($zip) !== true) throw new RuntimeException(t('upd_err_backup_invalid'));
		$in = $z->getStream(NuUpdate::SQL_ENTRY);
		if (!$in) { $z->close(); throw new RuntimeException(t('rst_err_no_sql')); }
		$tmp = NuUpdate::backupDir() . '/.restore-' . bin2hex(random_bytes(8)) . '.sql.gz';
		$out = @fopen($tmp, 'wb');
		if (!$out) { fclose($in); $z->close(); throw new RuntimeException(t('rst_err_temp')); }
		stream_copy_to_stream($in, $out);
		fclose($in); fclose($out);
		$z->close();
		@chmod($tmp, 0600);
		return $tmp;
	}

	/** Tables / views the restore drops first: the dump's scope ('all': everything, also routines and events). @return list<array{0: string, 1: string}> [name, TABLE|VIEW|PROCEDURE|FUNCTION|EVENT] */
	public static function dropList(string $scope): array
	{
		$db = nu_db();
		$out = [];
		foreach ($db->all('SELECT TABLE_NAME AS t, TABLE_TYPE AS k FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME') as $r) {
			$n = (string)$r['t'];
			$l = strtolower($n);
			if ($scope === 'nubuilder' && !str_starts_with($l, 'zzzzsys_')) continue;
			if ($scope === 'nunext' && !str_starts_with($l, 'zzzzsys_nunext_')) continue;
			$out[] = [$n, $r['k'] === 'VIEW' ? 'VIEW' : ($r['k'] === 'SEQUENCE' ? 'SEQUENCE' : 'TABLE')];
		}
		if ($scope === 'all') {
			foreach ($db->all('SELECT ROUTINE_NAME AS n, ROUTINE_TYPE AS k FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()') as $r) $out[] = [(string)$r['n'], (string)$r['k']];
			foreach ($db->all('SELECT EVENT_NAME AS n FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()') as $r) $out[] = [(string)$r['n'], 'EVENT'];
		}
		return $out;
	}

	/**
	 * Dry run: the dump read to its end and checked (the statements a complete dump may hold – NuInstall::checkDump), what it creates and
	 * what would be dropped. Nothing changes. @return array{ok: bool, error: string, scope?: string, version?: string, created?: string, tables?: int, rows?: int, bytes?: int, drops?: int}
	 */
	public static function restoreCheck(string $zip): array
	{
		if (!self::canRestore()) return ['ok' => false, 'error' => t('rst_err_mysql')];
		$tmp = '';
		try {
			['meta' => $meta, 'sql' => $sql] = self::backupMeta($zip);
			$tmp = self::extract($zip);
			if (!self::complete($tmp)) return ['ok' => false, 'error' => t('rst_err_incomplete')];
			$scope = (string)($sql['scope'] ?? 'all');
			$chk = NuInstall::checkDump(NuSqlImport::statements($tmp), true, $scope !== 'nunext');
			return $chk + ['scope' => $scope, 'version' => (string)($meta['version'] ?? '?'), 'created' => (string)($meta['created'] ?? ''), 'rows' => (int)($sql['rows'] ?? 0),
				'bytes' => NuSqlImport::size($tmp), 'drops' => count(self::dropList($scope)), 'without_logs' => !empty($sql['without_logs']), 'counts' => count((array)($sql['counts'] ?? []))];
		} catch (Throwable $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		} finally {
			if ($tmp !== '') @unlink($tmp);
		}
	}

	/**
	 * Puts the database part of a backup back: checks it again, makes a backup of the database now (same scope), maintenance mode on,
	 * drops the tables of the scope, runs the dump, compares the row count of every table with the backup, then nuBuilder Next's tables
	 * and database steps of THIS version. If the restore fails after the drop, the backup made first is put back by itself (0.8.1032).
	 * $backupFirst false only from the command line (--no-backup): then there is no way back.
	 * @return array{safety: string, dropped: int, statements: int, steps: array, scope: string, version: string, checked: int, mismatch: array<string, array{0: int, 1: int}>}
	 */
	public static function restore(string $zip, bool $backupFirst, string $via = 'page', ?callable $progress = null): array
	{
		if (!self::canRestore()) throw new RuntimeException(t('rst_err_mysql'));
		nu_time_limit(0);
		nu_ignore_abort();
		['meta' => $meta, 'sql' => $sql] = self::backupMeta($zip);
		$scope = in_array($sql['scope'] ?? '', ['nubuilder', 'nunext', 'all'], true) ? (string)$sql['scope'] : 'all';
		if ($p = self::spaceProblem(NuUpdate::backupDir(), self::entrySize($zip) * 2 + ($backupFirst ? self::backupNeed($scope, false) : 0))) throw new RuntimeException($p);
		$tmp = self::extract($zip);                        // before the safety backup: the keep rule may take the SQL out of older backups
		$out = ['safety' => '', 'dropped' => 0, 'statements' => 0, 'steps' => [], 'scope' => $scope, 'version' => (string)($meta['version'] ?? '?'), 'checked' => 0, 'mismatch' => []];
		$db = nu_db();
		$dbName = (string)$db->value('SELECT DATABASE()');
		try {
			if (!self::complete($tmp)) throw new RuntimeException(t('rst_err_incomplete'));
			$chk = NuInstall::checkDump(NuSqlImport::statements($tmp), true, $scope !== 'nunext');
			if (!$chk['ok']) throw new RuntimeException($chk['error']);
			if ($backupFirst) {
				$out['safety'] = NuUpdate::backup('before restore', $scope, false);
				if (!(NuUpdate::$lastSql['complete'] ?? false)) throw new RuntimeException(t('rst_err_safety', (string)(NuUpdate::$lastSql['error'] ?? '?')));
			}
			error_log('nuBuilder Next: restore database ' . $dbName . ' from backup ' . basename($zip) . ' (' . $via . ')');
			NuUpdate::maintenanceOn('restore');
			try {
				try {
					[$out['dropped'], $out['statements']] = self::load($db, $tmp, $scope, $progress);
				} catch (Throwable $e) {
					// the tables are gone: put the backup made first back (0.8.1032), then report both
					$back = '';
					if ($out['safety'] !== '' && ($sf = NuUpdate::backupPath($out['safety']))) {
						$st = '';
						try {
							$st = self::extract($sf);
							self::load($db, $st, $scope, null);
						} catch (Throwable $e2) {
							$back = $e2->getMessage();
						} finally {
							if ($st !== '') @unlink($st);
						}
						NuDB::forgetColumns('');
						self::stayIn();
						NuLog::write('database_restore_failed', $dbName, '', ['backup' => basename($zip), 'error' => $e->getMessage(), 'safety' => $out['safety'],
							'put_back' => $back === '' ? 'ok' : $back, 'via' => $via]);
						throw new RuntimeException($back === '' ? t('rst_err_rolled_back', $out['safety'], $e->getMessage()) : t('rst_err_rollback_failed', $e->getMessage(), $out['safety'], $back), 0, $e);
					}
					throw $e;
				}
				// row counts as in the backup? (before nuBuilder Next's steps, which may add rows)
				foreach ((array)($sql['counts'] ?? []) as $t => $n) {
					try { $now = (int)$db->value('SELECT COUNT(*) FROM `' . str_replace('`', '``', (string)$t) . '`'); } catch (Throwable) { $now = -1; }
					$out['checked']++;
					if ($now !== (int)$n) $out['mismatch'][(string)$t] = [(int)$n, $now];
				}
				NuDB::forgetColumns('');
				NuSchema::allow();
				NuSchema::create();
				$out['steps'] = NuMigrate::run();
			} finally {
				NuUpdate::maintenanceOff();
			}
		} finally {
			@unlink($tmp);
		}
		self::stayIn();
		NuLog::write('database_restore', $dbName, '', ['backup' => basename($zip), 'version' => $out['version'], 'scope' => $scope, 'dropped' => $out['dropped'],
			'statements' => $out['statements'], 'safety' => $out['safety'], 'checked' => $out['checked'], 'mismatch' => $out['mismatch'], 'via' => $via]);
		return $out;
	}

	/** The signed-in user stays signed in: the sessions table comes back empty from a dump. */
	private static function stayIn(): void
	{
		if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
		$u = NuAuth::user();
		if (!$u) return;
		unset($_SESSION['nu_sess'], $_SESSION['nu_schema'], $_SESSION['nu_db_ready']);
		try { NuSessions::touch($u); } catch (Throwable) {}
	}

	/** Drops the tables of the scope and runs a dump (.sql.gz). @return array{0: int, 1: int} dropped, statements */
	private static function load(NuDB $db, string $file, string $scope, ?callable $progress): array
	{
		$dropped = 0;
		$db->exec('SET FOREIGN_KEY_CHECKS = 0');
		try {
			foreach (self::dropList($scope) as [$n, $k]) {
				$db->exec('DROP ' . $k . ' IF EXISTS `' . str_replace('`', '``', $n) . '`');
				$dropped++;
			}
			return [$dropped, NuSqlImport::run($db, $file, $progress)];
		} finally {
			$db->exec('SET FOREIGN_KEY_CHECKS = 1');
		}
	}

	// ------------------------------------------------------------------ free disk space (0.8.1032, as mydumper's --disk-limits)

	/** Bytes of the SQL dump inside a backup zip (gzip), 0 when missing. */
	private static function entrySize(string $zip): int
	{
		$z = new ZipArchive();
		if ($z->open($zip) !== true) return 0;
		$n = (int)($z->statName(NuUpdate::SQL_ENTRY)['size'] ?? 0);
		$z->close();
		return $n;
	}

	/** About the space a backup takes: the program files, and a gzip dump of about a third of the data (with a margin). */
	public static function backupNeed(string $scope, bool $withoutLogs): int
	{
		$files = 0;
		foreach (NuUpdate::programFiles() as $rel) $files += (int)@filesize(NU_ROOT . '/' . $rel);
		return $files + ($scope === 'none' ? 0 : intdiv(self::estimate($scope, $withoutLogs), 3));
	}

	/**
	 * Message when $dir has less than $need bytes (+ 20 MB) free; null when enough – or when the host does not tell (disk_free_space
	 * disabled or failing: nothing is refused then). $free: for tests.
	 */
	public static function spaceProblem(string $dir, int $need, ?float $free = null): ?string
	{
		if ($free === null) {
			if (!function_exists('disk_free_space')) return null;
			$f = @disk_free_space($dir);
			if ($f === false) return null;
			$free = (float)$f;
		}
		$need += 20 * 1048576;
		return $free < $need ? t('bk_err_space', NuFile::size($need), NuFile::size((int)$free)) : null;
	}
}
