<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Compares the database with the table structure a package expects (install/schema_expected.json: nuBuilder 4.9's zzzzsys_*
 * tables + nuBuilder Next's own tables) and adds missing columns / changes column types the globeadmin selects.
 * Only tables that exist are checked; extra columns are left alone; primary keys are never changed.
 * Column types are compared on MySQL / MariaDB only (MSSQL and SQLite: missing columns).
 */
final class NuSchemaCheck
{
	public const FILE = 'install/schema_expected.json';

	/** Expected structure from JSON text; null when it cannot be read. */
	public static function parse(?string $json): ?array
	{
		$d = json_decode((string)$json, true);
		return is_array($d) && isset($d['tables']) && is_array($d['tables']) ? $d : null;
	}

	/** Expected structure of the installed version. */
	public static function installed(): ?array
	{
		$f = NU_ROOT . '/' . self::FILE;
		return is_file($f) ? self::parse((string)file_get_contents($f)) : null;
	}

	/** Expected structure inside an update package (zip). */
	public static function fromZip(string $zipFile, string $prefix): ?array
	{
		if (!class_exists('ZipArchive')) return null;
		$z = new ZipArchive();
		if ($z->open($zipFile) !== true) return null;
		try {
			$j = $z->getFromName($prefix . self::FILE);
			return $j === false ? null : self::parse($j);
		} finally {
			$z->close();
		}
	}

	/**
	 * @return array{missing: list<array>, changed: list<array>, tables_missing: list<string>, types: bool}
	 *   missing: [key, table, column, type]; changed: [key, table, column, actual, expected, safe]
	 */
	public static function compare(array $expected): array
	{
		$db  = nu_db();
		$out = ['missing' => [], 'changed' => [], 'tables_missing' => [], 'types' => $db->type === 'mysql'];
		$actualAll = self::actualColumns();
		foreach ($expected['tables'] as $table => $def) {
			if (!preg_match('/^zzzzsys_\w+$/', (string)$table)) continue;
			$actual = $actualAll[strtolower((string)$table)] ?? null;
			if ($actual === null) { $out['tables_missing'][] = (string)$table; continue; }
			$pk = strtolower((string)($def['pk'] ?? ''));
			foreach ((array)($def['cols'] ?? []) as $col => $c) {
				$lc = strtolower((string)$col);
				$want = (string)($c['type'] ?? '');
				if (!isset($actual[$lc])) {
					if ($lc === $pk) continue;
					$out['missing'][] = ['key' => 'add:' . $table . '.' . $col, 'table' => (string)$table, 'column' => (string)$col, 'type' => $want];
					continue;
				}
				if (!$out['types'] || $lc === $pk) continue;
				$have = $actual[$lc];
				if (self::norm($have) === self::norm($want)) continue;
				$out['changed'][] = ['key' => 'modify:' . $table . '.' . $col, 'table' => (string)$table, 'column' => (string)$col,
					'actual' => $have, 'expected' => $want, 'safe' => self::isWidening($have, $want)];
			}
		}
		return $out;
	}

	/** table (lower case) => [column (lower case) => type] for all zzzzsys_ tables. */
	private static function actualColumns(): array
	{
		$db = nu_db();
		$rows = match ($db->type) {
			'mysql'  => $db->all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS y FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'zzzzsys!_%' ESCAPE '!'"),
			'sqlsrv' => $db->all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS y FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_CATALOG = DB_NAME() AND TABLE_NAME LIKE 'zzzzsys[_]%'"),
			default  => (static function () use ($db): array {
				$r = [];
				foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'zzzzsys_%'") as $t) {
					foreach ($db->all('PRAGMA table_info(' . NuDB::ident((string)$t['name']) . ')') as $c) $r[] = ['t' => $t['name'], 'c' => $c['name'], 'y' => $c['type']];
				}
				return $r;
			})(),
		};
		$out = [];
		foreach ($rows as $r) $out[strtolower((string)$r['t'])][strtolower((string)$r['c'])] = strtolower((string)$r['y']);
		return $out;
	}

	/** MySQL type for comparing: lower case, without integer display widths (int(11) = int, MySQL 8 drops them). */
	private static function norm(string $t): string
	{
		$t = strtolower(trim($t));
		$t = (string)preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $t);
		return $t === 'integer' ? 'int' : $t;
	}

	/** [family, size] of a MySQL type; family: s (string), t (text), i (integer), d (decimal), f (float), dt (date/time), o (other). */
	private static function rank(string $t): array
	{
		$t = self::norm($t);
		if (preg_match('/^(var)?char\((\d+)\)/', $t, $m)) return ['s', (int)$m[2]];
		$text = ['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215, 'longtext' => 4294967295];
		if (isset($text[$t])) return ['t', $text[$t]];
		$ints = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'bigint' => 8];
		if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)( unsigned)?$/', $t, $m)) return ['i', $ints[$m[1]]];
		if (preg_match('/^decimal\((\d+),(\d+)\)/', $t, $m)) return ['d', (int)$m[1] * 100 + (int)$m[2]];
		if (in_array($t, ['float', 'double'], true)) return ['f', $t === 'double' ? 2 : 1];
		$dt = ['date' => 1, 'time' => 1, 'datetime' => 2, 'timestamp' => 2];
		if (isset($dt[$t])) return ['dt', $dt[$t]];
		return ['o', 0];
	}

	/** True when changing $from to $to cannot shorten or lose data (larger string/text/number, string → text, number → string). */
	public static function isWidening(string $from, string $to): bool
	{
		[$fa, $fs] = self::rank($from);
		[$ta, $ts] = self::rank($to);
		if ($fa === $ta && $fa !== 'o' && $fa !== 'dt') return $ts >= $fs;
		if ($fa === 'dt' && $ta === 'dt') {
			$f = self::norm($from); $t = self::norm($to);
			return $f === $t || ($f === 'date' && $t === 'datetime') || ($f === 'timestamp' && $t === 'datetime');   // timestamp: 1970–2038 only
		}
		if ($fa === 's' && $ta === 't') return $ts >= $fs;
		if (($fa === 'i' || $fa === 'd' || $fa === 'f') && ($ta === 's' && $ts >= 40 || $ta === 't')) return true;   // a number fits as text
		if ($fa === 'i' && $ta === 'd') return true;
		return false;
	}

	/**
	 * Applies the selected changes ("add:table.column" / "modify:table.column").
	 * @return array{done: list<string>, errors: list<string>}
	 */
	public static function apply(array $expected, array $keys): array
	{
		$db = nu_db();
		$res = ['done' => [], 'errors' => []];
		$cmp = self::compare($expected);
		$known = [];
		foreach (array_merge($cmp['missing'], $cmp['changed']) as $it) $known[$it['key']] = $it;
		foreach (array_unique(array_map('strval', $keys)) as $key) {
			$it = $known[$key] ?? null;
			if ($it === null) continue;                                   // already done or no longer different
			$def = (string)($expected['tables'][$it['table']]['cols'][$it['column']]['def'] ?? '');
			try {
				$sql = self::alterSql(str_starts_with($key, 'add:') ? 'add' : 'modify', $it['table'], $it['column'], (string)($it['type'] ?? $it['expected']), $def);
				if ($sql === null) continue;
				$db->run($sql);
				NuDB::forgetColumns($it['table']);
				$res['done'][] = $it['table'] . '.' . $it['column'];
				NuLog::write($key[0] === 'a' ? 'schema_column_added' : 'schema_column_changed', $it['table'], $it['column'],
					['type' => $it['type'] ?? $it['expected'], 'previous' => $it['actual'] ?? null]);
			} catch (Throwable $e) {
				$res['errors'][] = $it['table'] . '.' . $it['column'] . ': ' . $e->getMessage();
			}
		}
		return $res;
	}

	/** SQL of one difference ("add:…" / "modify:…" with its compare() item) for the preview of an update (0.8.346). */
	public static function sqlFor(array $expected, string $key, array $it): ?string
	{
		try {
			$def = (string)($expected['tables'][$it['table']]['cols'][$it['column']]['def'] ?? '');
			return self::alterSql(str_starts_with($key, 'add:') ? 'add' : 'modify', $it['table'], $it['column'], (string)($it['type'] ?? $it['expected']), $def);
		} catch (Throwable) {
			return null;
		}
	}

	/** ALTER TABLE statement for the current database; null when not possible (type changes outside MySQL). */
	private static function alterSql(string $op, string $table, string $col, string $mysqlType, string $mysqlDef): ?string
	{
		NuDB::ident($table);
		NuDB::ident($col);
		$db = nu_db();
		if ($db->type === 'mysql') {
			$def = self::safeDef($mysqlDef !== '' ? $mysqlDef : $mysqlType . ' DEFAULT NULL');
			return 'ALTER TABLE `' . $table . '` ' . ($op === 'add' ? 'ADD COLUMN' : 'MODIFY') . ' `' . $col . '` ' . $def;
		}
		if ($op !== 'add') return null;
		$t = self::foreignType($mysqlType);
		return $db->type === 'sqlsrv' ? "ALTER TABLE [$table] ADD [$col] $t NULL" : "ALTER TABLE \"$table\" ADD COLUMN \"$col\" $t";
	}

	/** Column definition from the schema file, only what a column definition may contain (no statements). */
	private static function safeDef(string $def): string
	{
		if (!preg_match("/^[a-z]+(\(\d+(,\d+)?\))?( unsigned)?( NOT NULL| NULL)?( DEFAULT ('[^';]*'|NULL|-?\d+(\.\d+)?|current_timestamp(\(\))?))?( ON UPDATE current_timestamp(\(\))?)?$/i", trim($def))) {
			throw new RuntimeException('Unexpected column definition: ' . $def);
		}
		return trim($def);
	}

	/** MySQL type → MSSQL / SQLite type for a new column. */
	private static function foreignType(string $t): string
	{
		[$fam, $size] = self::rank($t);
		$ms = nu_db()->type === 'sqlsrv';
		$n = preg_match('/\((\d+)\)/', $t, $m) ? (int)$m[1] : 255;
		return match ($fam) {
			's'  => $ms ? 'NVARCHAR(' . min(4000, $n) . ')' : 'TEXT',
			't'  => $ms ? 'NVARCHAR(MAX)' : 'TEXT',
			'i'  => $ms ? ($size >= 8 ? 'BIGINT' : 'INT') : 'INTEGER',
			'd'  => $ms && preg_match('/\((\d+),(\d+)\)/', $t, $m) ? "DECIMAL($m[1],$m[2])" : ($ms ? 'DECIMAL(15,2)' : 'REAL'),
			'f'  => $ms ? 'FLOAT' : 'REAL',
			'dt' => self::norm($t) === 'date' ? 'DATE' : ($ms ? 'DATETIME2(0)' : 'TEXT'),
			default => $ms ? 'NVARCHAR(MAX)' : 'TEXT',
		};
	}
}
