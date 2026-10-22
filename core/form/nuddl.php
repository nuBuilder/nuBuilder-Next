<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Portable table changes for the form builder: CREATE TABLE for new forms and ADD COLUMN for new objects.
 * Column types are abstract and mapped per driver:
 *   text (length, default 255) · longtext · int · bigint · decimal (length "15,2") · date · datetime · time · bool · id (varchar 25)
 */
final class NuDDL
{
	public const TYPES = ['text', 'longtext', 'int', 'bigint', 'decimal', 'date', 'datetime', 'time', 'bool', 'id'];

	/** Standard audit columns, filled by NuRecord::save when the form has {"nunext":{"audit":true}}. */
	public const AUDIT = [
		'created_at' => 'datetime', 'created_by' => ['text', '50'],
		'updated_at' => 'datetime', 'updated_by' => ['text', '50'],
	];

	public static function validName(string $name): bool
	{
		return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name);
	}

	/** SQL type for an abstract type on the current driver. */
	/**
	 * MySQL table options for new tables that are joined with nuBuilder's tables: same character set and collation
	 * as zzzzsys_object (joins between different collations fail with "Illegal mix of collations").
	 */
	public static function mysqlTableOptions(string $like = 'zzzzsys_object', ?NuDB $db = null): string
	{
		static $cache = [];
		if (isset($cache[$like])) return $cache[$like];
		$coll = '';
		try {
			// $db: the installer's connection – nu_db() needs nuconfig.php, which the installer writes only afterwards
			$coll = (string)($db ?? nu_db())->value('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$like]);
		} catch (Throwable) {
		}
		if (!preg_match('/^(utf8mb4|utf8mb3|utf8)_\w+$/', $coll, $m)) return $cache[$like] = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
		return $cache[$like] = ' ENGINE=InnoDB DEFAULT CHARSET=' . $m[1] . ' COLLATE=' . $coll;
	}

	public static function sqlType(string $type, string $length = ''): string
	{
		$driver = nu_db()->type;
		$length = trim($length);
		switch ($type) {
			case 'text':
				$n = max(1, min($driver === 'sqlsrv' ? 4000 : 16000, (int)($length !== '' ? $length : 255)));
				return $driver === 'sqlsrv' ? "NVARCHAR($n)" : ($driver === 'mysql' ? "VARCHAR($n)" : 'TEXT');
			case 'id':
				return $driver === 'sqlsrv' ? 'NVARCHAR(25)' : ($driver === 'mysql' ? 'VARCHAR(25)' : 'TEXT');
			case 'longtext':
				return $driver === 'sqlsrv' ? 'NVARCHAR(MAX)' : ($driver === 'mysql' ? 'LONGTEXT' : 'TEXT');
			case 'int':
				return $driver === 'sqlite' ? 'INTEGER' : 'INT';
			case 'bigint':
				return $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
			case 'decimal':
				[$p, $s] = array_pad(array_map('intval', preg_split('/[,.]/', $length !== '' ? $length : '15,2')), 2, 2);
				$p = max(1, min(38, $p ?: 15));
				$s = max(0, min($p, $s));
				return $driver === 'sqlite' ? 'REAL' : "DECIMAL($p,$s)";
			case 'date':
				return 'DATE';
			case 'datetime':
				return $driver === 'sqlsrv' ? 'DATETIME2(0)' : 'DATETIME';
			case 'time':
				return $driver === 'sqlsrv' ? 'TIME(0)' : 'TIME';
			case 'bool':
				return $driver === 'sqlsrv' ? 'BIT' : ($driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER');
		}
		throw new InvalidArgumentException("Unknown column type '$type'");
	}

	/**
	 * @param array $columns [['name' => …, 'type' => …, 'length' => …], …]  (primary key excluded)
	 */
	public static function createTable(string $table, string $pk, array $columns, bool $audit, bool $nulog = false): void
	{
		$db = nu_db();
		$lines = [NuDB::ident($pk) . ' ' . self::sqlType('id') . ' NOT NULL PRIMARY KEY'];
		foreach ($columns as $c) $lines[] = NuDB::ident($c['name']) . ' ' . self::sqlType($c['type'], (string)($c['length'] ?? '')) . ' NULL';
		if ($audit) {
			foreach (self::AUDIT as $name => $t) {
				[$type, $len] = is_array($t) ? $t : [$t, ''];
				$lines[] = $name . ' ' . self::sqlType($type, $len) . ' NULL';
			}
		}
		if ($nulog) $lines[] = NuDB::ident($table . '_nulog') . ' ' . self::sqlType('text', '1000') . ' NULL';   // nuBuilder: VARCHAR(1000), JSON added / edited
		$sql = 'CREATE TABLE ' . NuDB::ident($table) . " (\n  " . implode(",\n  ", $lines) . "\n)";
		if ($db->type === 'mysql') {
			$engine  = 'InnoDB';   // always (transactions, row locks); $nuConfigDBEngine is ignored
			$charset = preg_replace('/\W/', '', (string)(nu_cfg('nuConfigDBCharacterSet') ?: 'utf8mb4'));
			$collate = preg_replace('/\W/', '', (string)nu_cfg('nuConfigDBCollate'));
			$sql .= " ENGINE=$engine DEFAULT CHARSET=$charset" . ($collate !== '' ? " COLLATE=$collate" : '');
		}
		$db->run($sql);
	}

	/**
	 * An index on a column unless one starts with it already (0.8.366): sub-form foreign keys – without it every form with a sub-form
	 * reads the whole child table (300,000 order items: seconds per record). MySQL / MariaDB and SQL Server. @return bool created
	 */
	public static function ensureIndex(string $table, string $column): bool
	{
		$db = nu_db();
		if (!self::validName($table) || !self::validName($column) || !$db->tableExists($table)) return false;
		if (!isset(array_change_key_case((array)$db->columns($table), CASE_LOWER)[strtolower($column)])) return false;
		if ($db->type === 'sqlsrv') {
			$has = (int)$db->value('SELECT COUNT(*) FROM sys.index_columns ic JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id'
				. ' WHERE ic.object_id = OBJECT_ID(?) AND ic.key_ordinal = 1 AND c.name = ?', [$table, $column]);
		} else {
			$has = (int)$db->value('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND seq_in_index = 1', [$table, $column]);
		}
		if ($has) return false;
		$name = substr('ix_' . $table . '_' . $column, 0, 60);
		$db->run('CREATE INDEX ' . NuDB::ident($name) . ' ON ' . NuDB::ident($table) . ' (' . NuDB::ident($column) . ')');
		return true;
	}

	public static function dropTable(string $table): void
	{
		$db = nu_db();
		$t  = NuDB::ident($table);
		$db->run($db->type === 'sqlsrv' ? "IF OBJECT_ID('$t') IS NOT NULL DROP TABLE $t" : "DROP TABLE IF EXISTS $t");
	}

	public static function addColumn(string $table, string $name, string $type, string $length = ''): void
	{
		$db  = nu_db();
		$add = $db->type === 'sqlsrv' ? 'ADD' : 'ADD COLUMN';
		$db->run('ALTER TABLE ' . NuDB::ident($table) . " $add " . NuDB::ident($name) . ' ' . self::sqlType($type, $length) . ' NULL');
		NuDB::forgetColumns($table);
	}

	/** Abstract column type that fits a builder object: [type, length] or null when the object stores nothing. */
	public static function typeForObject(array $o): ?array
	{
		$type = (string)($o['sob_all_type'] ?? '');
		$it   = (string)($o['sob_input_type'] ?? '');
		switch ($type) {
			case 'textarea':
			case 'editor':
				return ['longtext', ''];
			case 'lookup':
				return ['id', ''];
			case 'calc':
				return ['decimal', '15,2'];
			case 'select':
				return (string)($o['sob_select_multiple'] ?? '') === '1' ? ['longtext', ''] : ['text', '255'];
			case 'input':
				if ($it === 'button') return null;
				if ($it === 'file') return ['longtext', ''];
				$fmt = (string)($o['sob_input_format'] ?? '');
				return match ($it) {
					'checkbox'       => ['bool', ''],
					'nuDate'         => [preg_match('/hh|nn/', $fmt) ? 'datetime' : 'date', ''],
					'date'           => ['date', ''],
					'datetime-local' => ['datetime', ''],
					'time'           => ['time', ''],
					'number', 'range', 'nuNumber' => ['decimal', '15,2'],
					'nuAutoNumber'   => ['int', ''],
					default          => ['text', '255'],
				};
		}
		return null;
	}
}
