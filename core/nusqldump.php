<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * SQL dump of this database into a gzip file – used by the backups of the update page (core/nuupdate.php).
 * The SQL is written by the app cloner's export (core/nuappcloner.php: tables, views in dependency order, data in batches, binary
 * data as hex, functions / procedures / triggers / events without DEFINER, no sessions and one-time tokens), so both stay the same.
 * MySQL / MariaDB and SQL Server (the SQL file then uses GO between batches). The file ends with a marker line; complete() checks it, so a dump that was cut off is never taken for a
 * full one.
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
				'routines' => (int)$st['functions'] + (int)$st['procedures'] + (int)$st['triggers'] + (int)$st['events']] + $res;
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
}
