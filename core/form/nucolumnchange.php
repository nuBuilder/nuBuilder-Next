<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Change the database column of a builder object (0.8.623, as nuBuilder Forte's "Alter Database Column" form – with a data check):
 * info() reads the column, plan() builds the ALTER TABLE for a new type / length and counts the values that would be cut, rounded or
 * lost, run() executes it (recent password, logged). The globeadmin may also send his own ALTER TABLE for this table (free SQL, no
 * data check). MySQL / MariaDB and SQL Server; not SQLite. Called by pages/nubuilderapi.php (column.info / column.check / column.change).
 */
final class NuColumnChange
{
	/** Types offered in the dialog (NuDDL types; 'id' / 'uuid' are text of 25 / 36). */
	public const TYPES = ['text', 'longtext', 'int', 'bigint', 'decimal', 'date', 'datetime', 'time', 'bool', 'id', 'uuid'];
	private const SAMPLES = 5;

	/**
	 * The column as it is: sql_type (e.g. varchar(25)), nullable, default, key, auto, the NuDDL type and length closest to it, rows.
	 * @return array<string, mixed>
	 */
	public static function info(string $table, string $column): array
	{
		$db = nu_db();
		if ($db->type !== 'mysql' && $db->type !== 'sqlsrv') throw new RuntimeException(t('builder_colchg_err_db'));
		self::names($table, $column);
		if ($db->type === 'mysql') {
			$c = $db->one('SELECT COLUMN_NAME AS name, DATA_TYPE AS dt, COLUMN_TYPE AS ct, IS_NULLABLE AS nul, COLUMN_DEFAULT AS def, COLUMN_KEY AS ck, EXTRA AS ex,
				CHARACTER_MAXIMUM_LENGTH AS len, NUMERIC_PRECISION AS prec, NUMERIC_SCALE AS scale, COLUMN_COMMENT AS cm
				FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
		} else {
			$c = $db->one("SELECT c.COLUMN_NAME AS name, c.DATA_TYPE AS dt, c.IS_NULLABLE AS nul, c.COLUMN_DEFAULT AS def, c.CHARACTER_MAXIMUM_LENGTH AS len,
				c.NUMERIC_PRECISION AS prec, c.NUMERIC_SCALE AS scale,
				CASE WHEN COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') = 1 THEN 'auto_increment' ELSE '' END AS ex,
				CASE WHEN EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
					WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND k.TABLE_NAME = c.TABLE_NAME AND k.COLUMN_NAME = c.COLUMN_NAME) THEN 'PRI' ELSE '' END AS ck, '' AS cm
				FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_NAME = ? AND c.COLUMN_NAME = ?", [$table, $column]);
			if ($c) $c['ct'] = strtolower((string)$c['dt']) . (in_array(strtolower((string)$c['dt']), ['varchar', 'nvarchar', 'char', 'nchar'], true)
				? '(' . ((int)$c['len'] === -1 ? 'max' : (int)$c['len']) . ')' : (in_array(strtolower((string)$c['dt']), ['decimal', 'numeric'], true) ? '(' . (int)$c['prec'] . ',' . (int)$c['scale'] . ')' : ''));
		}
		if (!$c) throw new RuntimeException(t('builder_colchg_err_missing', $column, $table));
		[$type, $length] = self::guess($c);
		$rows = null;
		try { $rows = (int)$db->value('SELECT COUNT(*) FROM ' . NuDB::ident($table)); } catch (Throwable) {}
		return [
			'table' => $table, 'column' => (string)$c['name'], 'sql_type' => strtolower((string)$c['ct']), 'nullable' => strtoupper((string)$c['nul']) === 'YES',
			'default' => $c['def'], 'pk' => (string)$c['ck'] === 'PRI', 'auto' => str_contains(strtolower((string)$c['ex']), 'auto_increment'),
			'type' => $type, 'length' => $length, 'rows' => $rows, 'comment' => (string)($c['cm'] ?? ''), 'raw' => $c,
		];
	}

	/** The NuDDL type and length closest to a column (['', ''] when none fits – only free SQL then). @return array{0: string, 1: string} */
	private static function guess(array $c): array
	{
		$dt = strtolower((string)$c['dt']);
		$len = (int)($c['len'] ?? 0);
		$ct = strtolower((string)($c['ct'] ?? ''));
		return match (true) {
			in_array($dt, ['varchar', 'nvarchar', 'char', 'nchar'], true) && $len > 0 => $len === 25 ? ['id', ''] : ($len === 36 ? ['uuid', ''] : ['text', (string)$len]),
			in_array($dt, ['text', 'mediumtext', 'longtext', 'tinytext', 'ntext', 'varchar', 'nvarchar'], true) => ['longtext', ''],
			$dt === 'tinyint' && str_starts_with($ct, 'tinyint(1)'), $dt === 'bit' => ['bool', ''],
			in_array($dt, ['int', 'integer', 'smallint', 'mediumint', 'tinyint'], true) => ['int', ''],
			$dt === 'bigint' => ['bigint', ''],
			in_array($dt, ['decimal', 'numeric'], true) => ['decimal', (int)$c['prec'] . ',' . (int)$c['scale']],
			$dt === 'date' => ['date', ''],
			in_array($dt, ['datetime', 'datetime2', 'timestamp', 'smalldatetime'], true) => ['datetime', ''],
			$dt === 'time' => ['time', ''],
			default => ['', ''],
		};
	}

	private static function names(string $table, string $column): void
	{
		if (!NuDDL::validName($table) || !NuDDL::validName($column)) throw new RuntimeException(t('builder_colchg_err_name'));
		if (stripos($table, 'zzzzsys') === 0) throw new RuntimeException(t('builder_colchg_err_system'));
	}

	/**
	 * ALTER TABLE for a new type / length and the data check: [sql, checks: [{key, n, samples}], same (nothing changes)].
	 * @return array{sql: string, checks: list<array{key: string, arg: string, n: int, samples: list<string>}>, same: bool, target: string}
	 */
	public static function plan(array $info, string $type, string $length): array
	{
		if (!in_array($type, self::TYPES, true)) throw new RuntimeException(t('builder_colchg_err_type'));
		if ($length !== '' && !preg_match('/^\d{1,5}([,.]\d{1,2})?$/', $length)) throw new RuntimeException(t('builder_colchg_err_length'));
		if ($info['auto']) throw new RuntimeException(t('builder_colchg_err_auto'));
		$db = nu_db();
		$target = NuDDL::sqlType($type, $length);
		$col = NuDB::ident($info['column']);
		$tbl = NuDB::ident($info['table']);
		$null = $info['nullable'] ? 'NULL' : 'NOT NULL';
		if ($db->type === 'mysql') {
			$def = '';
			$d = $info['default'];
			if ($d !== null && strtoupper((string)$d) !== 'NULL') {
				$isMaria = stripos((string)$db->value('SELECT VERSION()'), 'mariadb') !== false;
				$def = ' DEFAULT ' . ($isMaria || is_numeric($d) || preg_match('/^current_timestamp/i', (string)$d) ? (string)$d : $db->pdo()->quote((string)$d));
			}
			$cm = $info['comment'] !== '' ? ' COMMENT ' . $db->pdo()->quote($info['comment']) : '';
			$sql = "ALTER TABLE $tbl MODIFY $col $target $null$def$cm";
		} else {
			$sql = "ALTER TABLE $tbl ALTER COLUMN $col $target $null";
		}
		$same = strtolower(preg_replace('/\s+/', '', $target)) === preg_replace('/\s+/', '', strtolower((string)$info['sql_type'])) || ($type === 'bool' && $info['type'] === 'bool');
		return ['sql' => $sql, 'checks' => $same ? [] : self::checks($info, $type, $length), 'same' => $same, 'target' => strtolower($target)];
	}

	/** Values that would not survive the change. @return list<array{key: string, arg: string, n: int, samples: list<string>}> */
	private static function checks(array $info, string $type, string $length): array
	{
		$db = nu_db();
		$my = $db->type === 'mysql';
		$c = NuDB::ident($info['column']);
		$t = NuDB::ident($info['table']);
		$txt = $my ? "CAST($c AS CHAR)" : "CAST($c AS NVARCHAR(4000))";
		$len = $my ? "CHAR_LENGTH($txt)" : "LEN($txt)";
		$blank = "$c IS NOT NULL AND TRIM($txt) <> ''";
		$out = [];
		$add = static function (string $key, string $where, string $arg = '') use ($db, $t, $txt, &$out): void {
			try {
				$n = (int)$db->value("SELECT COUNT(*) FROM $t WHERE $where");
			} catch (Throwable) {
				return;
			}
			if ($n === 0) return;
			$top = $db->type === 'sqlsrv' ? 'TOP ' . self::SAMPLES . ' ' : '';
			$lim = $db->type === 'mysql' ? ' LIMIT ' . self::SAMPLES : '';
			$s = [];
			try { foreach ($db->all("SELECT {$top}$txt AS v FROM $t WHERE $where$lim") as $r) $s[] = mb_substr((string)$r['v'], 0, 60); } catch (Throwable) {}
			$out[] = ['key' => $key, 'arg' => $arg, 'n' => $n, 'samples' => $s];
		};
		$num = $my ? "TRIM($txt) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'" : "TRY_CAST($c AS DECIMAL(38,10)) IS NOT NULL";
		$int = $my ? "TRIM($txt) REGEXP '^-?[0-9]+$'" : "TRY_CAST($txt AS BIGINT) IS NOT NULL";
		switch ($type) {
			case 'text':
			case 'id':
			case 'uuid':
				$n = $type === 'id' ? 25 : ($type === 'uuid' ? 36 : max(1, (int)($length !== '' ? $length : 255)));
				$add('too_long', "$c IS NOT NULL AND $len > $n", (string)$n);
				break;
			case 'int':
			case 'bigint':
				if ($info['type'] === 'decimal') $add('rounded', "$c IS NOT NULL AND $c <> ROUND($c, 0)", '0');
				else $add('not_number', "$blank AND NOT ($int)");
				if ($type === 'int') $add('too_big', "$blank AND ($int) AND ABS(CAST($txt AS DECIMAL(38,0))) > 2147483647");
				break;
			case 'decimal':
				[$p, $s] = array_pad(array_map('intval', preg_split('/[,.]/', $length !== '' ? $length : '15,2')), 2, 2);
				$p = max(1, min(38, $p ?: 15)); $s = max(0, min($p, $s));
				$add('not_number', "$blank AND NOT ($num)");
				$add('too_big', "$blank AND ($num) AND ABS(CAST($txt AS DECIMAL(38,10))) >= POWER(10, " . ($p - $s) . ')');
				if ($info['type'] === 'decimal' || $info['type'] === 'text') $add('rounded', "$blank AND ($num) AND CAST($txt AS DECIMAL(38,10)) <> ROUND(CAST($txt AS DECIMAL(38,10)), $s)", (string)$s);
				break;
			case 'date':
			case 'datetime':
			case 'time':
				$cast = $type === 'date' ? 'DATE' : ($type === 'time' ? 'TIME' : ($my ? 'DATETIME' : 'DATETIME2'));
				if (!in_array($info['type'], ['date', 'datetime', 'time'], true))
					$add('not_date', "$blank AND " . ($my ? "CAST(TRIM($txt) AS $cast) IS NULL" : "TRY_CAST($txt AS $cast) IS NULL"));
				if ($type === 'date' && $info['type'] === 'datetime') $add('time_lost', "$c IS NOT NULL AND CAST($c AS TIME) <> '00:00:00'");
				break;
			case 'bool':
				$add('not_bool', "$blank AND TRIM($txt) NOT IN ('0', '1')");
				break;
		}
		if (!$info['nullable'] && in_array($type, ['int', 'bigint', 'decimal', 'date', 'datetime', 'time', 'bool'], true) && in_array($info['type'], ['text', 'longtext', 'id', 'uuid', ''], true))
			$add('empty', "$c IS NOT NULL AND TRIM($txt) = ''");
		return $out;
	}

	/** The globeadmin's own statement: one ALTER TABLE of this table, nothing that drops or deletes. */
	public static function checkFreeSql(string $sql, string $table): string
	{
		$sql = rtrim(trim($sql), "; \t\r\n");
		$q = '[`"\[]?';
		if (!preg_match('/^ALTER\s+TABLE\s+' . $q . preg_quote($table, '/') . '[`"\]]?\s+\S/i', $sql) || str_contains($sql, ';')
			|| preg_match('/\b(DROP|DELETE|INSERT|UPDATE|TRUNCATE|RENAME|GRANT)\b/i', $sql)) throw new RuntimeException(t('builder_colchg_err_free'));
		return $sql;
	}

	/** Runs the statement (DDL: no transaction), logs it, forgets the cached columns. */
	public static function run(array $info, string $sql, string $to): void
	{
		nu_db()->run($sql);
		NuDB::forgetColumns($info['table']);
		NuLog::write('column_changed', $info['table'], $info['column'], ['from' => $info['sql_type'], 'to' => $to, 'sql' => $sql]);
	}
}
