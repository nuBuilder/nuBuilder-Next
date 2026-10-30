<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Thin PDO wrapper that hides the MySQL / MSSQL differences.
 * Only plain, unquoted nuBuilder identifiers are used, so no identifier quoting is needed.
 */
final class NuDB
{
	public readonly string $type;
	private PDO $pdo;

	public function __construct(array $c)
	{
		$this->type = strtolower((string)($c['type'] ?: 'mysql'));
		$port       = trim((string)($c['port'] ?? ''));

		switch ($this->type) {
			case 'mysql':
				$dsn = "mysql:host={$c['host']};dbname={$c['name']}";
				if ($port !== '') $dsn .= ';port=' . (int)$port;
				if (!empty($c['charset'])) $dsn .= ';charset=' . self::word($c['charset']);
				break;

			case 'sqlsrv':
				$server = $c['host'] . ($port !== '' ? ',' . (int)$port : '');
				$dsn    = "sqlsrv:Server={$server};Database={$c['name']}";
				if (!empty($c['trust_cert'])) $dsn .= ';TrustServerCertificate=1';
				break;

			case 'sqlite':                       // development / automated tests only
				$dsn = 'sqlite:' . $c['name'];
				break;

			default:
				throw new RuntimeException("Unsupported \$nuConfigDBType '{$this->type}' (use mysql or sqlsrv).");
		}

		$options = ($c['options'] ?? []) + [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		];
		if ($this->type === 'mysql') $options += [PDO::ATTR_EMULATE_PREPARES => false];

		$this->pdo = new PDO($dsn, $c['user'] ?? null, $c['password'] ?? null, $options);
		self::$connected = true;                               // a connection exists (0.8.701: NuLanguages reads its setting only then)

		if ($this->type === 'mysql' && !empty($c['charset'])) {
			$set = 'SET NAMES ' . self::word($c['charset']);
			if (!empty($c['collate'])) $set .= ' COLLATE ' . self::word($c['collate']);
			$this->pdo->exec($set);
		}
	}

	private static function word(string $s): string
	{
		if (!preg_match('/^[A-Za-z0-9_]+$/', $s)) throw new RuntimeException("Invalid identifier '$s'");
		return $s;
	}

	// ----------------------------------------------------------------- queries

	public function run(string $sql, array $params = []): PDOStatement
	{
		NuDemo::check($sql);                                    // demo mode: read only (core/nudemo.php)
		$st = $this->pdo->prepare($sql);
		$st->execute(self::bind($sql, $params));
		return $st;
	}

	/**
	 * Named parameters (nuBuilder style: "… VALUES(:id, :name)" with ['id' => …, 'name' => …]) are passed by name,
	 * everything else positionally.
	 */
	private static function bind(string $sql, array $params): array
	{
		if (!$params || array_is_list($params) || str_contains($sql, '?') || !preg_match('/(?<![:\w]):[A-Za-z_]\w*/', $sql)) {
			return array_values($params);
		}
		$out = [];
		foreach ($params as $k => $v) {
			if (is_bool($v)) $v = $v ? 1 : 0;
			$out[(is_string($k) && str_starts_with($k, ':')) ? $k : ':' . $k] = $v;
		}
		return $out;
	}

	public function all(string $sql, array $params = []): array
	{
		return $this->run($sql, $params)->fetchAll();
	}

	public function one(string $sql, array $params = []): ?array
	{
		$row = $this->run($sql, $params)->fetch();
		return $row === false ? null : $row;
	}

	public function value(string $sql, array $params = []): mixed
	{
		$v = $this->run($sql, $params)->fetchColumn();
		return $v === false ? null : $v;
	}

	/** Appends ORDER BY + paging in the dialect of the current driver. */
	public function page(string $sql, array $params, string $orderBy, int $limit, int $offset): array
	{
		$limit  = max(1, $limit);
		$offset = max(0, $offset);
		$sql   .= " ORDER BY $orderBy";
		$sql   .= $this->type === 'sqlsrv'
			? " OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY"
			: " LIMIT $limit OFFSET $offset";
		return $this->all($sql, $params);
	}

	public function insert(string $table, array $row): void
	{
		$cols = array_map([self::class, 'word'], array_keys($row));
		$sql  = 'INSERT INTO ' . self::word($table) . ' (' . implode(', ', $cols) . ') VALUES ('
			. implode(', ', array_fill(0, count($cols), '?')) . ')';
		$this->run($sql, $row);
	}

	public function update(string $table, array $row, string $pk, string $id): int
	{
		if (!$row) return 0;
		// nuBuilder's own tables: write only what really changed (NULL and '' count as the same) – nuBuilder writes only edited
		// fields, so a save without a change must leave its rows exactly as they were (0.8.364, dev/tests/compat.js meta:*)
		if (self::nuBuilderTable($table) && ($old = $this->one('SELECT * FROM ' . self::word($table) . ' WHERE ' . self::word($pk) . ' = ?', [$id]))) {
			$oldCi = array_change_key_case($old, CASE_LOWER);
			foreach ($row as $col => $v) {
				$k = strtolower((string)$col);
				if (!array_key_exists($k, $oldCi)) continue;
				$o = $oldCi[$k];
				if (!is_scalar($v) && $v !== null) continue;
				if ((string)($o ?? '') === (string)($v ?? '')) unset($row[$col]);
			}
			if (!$row) return 0;
		}
		$set = [];
		foreach (array_keys($row) as $col) $set[] = self::word($col) . ' = ?';
		$sql = 'UPDATE ' . self::word($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . self::word($pk) . ' = ?';
		return $this->run($sql, [...array_values($row), $id])->rowCount();
	}

	/** One of nuBuilder's tables (zzzzsys_…), not nuBuilder Next's own (zzzzsys_nunext_…). */
	/** True once a database connection was made in this request (no connection attempt). */
	public static bool $connected = false;
	/** The connection nu_db() returns instead of the configured one – the installer's, before nuconfig.php exists (integrity check, 0.8.705). */
	public static ?NuDB $override = null;

	public static function nuBuilderTable(string $table): bool
	{
		$t = strtolower(trim($table, '`[]"'));
		return str_starts_with($t, 'zzzzsys_') && !str_starts_with($t, 'zzzzsys_nunext_');
	}

	public function delete(string $table, string $col, string $value): int
	{
		return $this->run('DELETE FROM ' . self::word($table) . ' WHERE ' . self::word($col) . ' = ?', [$value])->rowCount();
	}

	public function transaction(callable $fn): mixed
	{
		$this->pdo->beginTransaction();
		try {
			$result = $fn($this);
			$this->pdo->commit();
			return $result;
		} catch (Throwable $e) {
			if ($this->pdo->inTransaction()) $this->pdo->rollBack();
			throw $e;
		}
	}

	/** Runs SQL without parameters (DDL, install scripts). */
	/**
	 * MySQL / MariaDB with sql_mode NO_BACKSLASH_ESCAPES: a backslash is an ordinary character in strings, so quotes must be
	 * doubled ('') instead of escaped (\'). Read once per connection.
	 */
	public function noBackslashEscapes(): bool
	{
		static $cache = [];
		$k = spl_object_id($this);
		if (!isset($cache[$k])) {
			$cache[$k] = false;
			if ($this->type === 'mysql') {
				try { $cache[$k] = str_contains(strtoupper((string)$this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()), 'NO_BACKSLASH_ESCAPES'); }
				catch (Throwable) { }
			}
		}
		return $cache[$k];
	}

	/** Text for a quoted SQL string literal ('…') of this database – for code that cannot use parameters (hash variables). */
	public function escapeString(string $v): string
	{
		if ($this->type !== 'mysql' || $this->noBackslashEscapes()) return str_replace("'", "''", $v);
		return addslashes($v);                               // \ ' " NUL – also safe inside "…" strings
	}

	/** The PDO connection (savepoints and manual transactions, e.g. NuImport). */
	public function pdo(): PDO
	{
		return $this->pdo;
	}

	public function exec(string $sql): int
	{
		NuDemo::check($sql);
		return (int)$this->pdo->exec($sql);
	}

	/**
	 * Runs $fn inside a transaction that is always rolled back, with a time limit per statement – for queries typed by a user
	 * (SQL builder preview). MySQL / MariaDB: START TRANSACTION READ ONLY (writes fail), max_execution_time (MySQL, SELECT) or
	 * max_statement_time (MariaDB); SQL Server: BEGIN TRAN … ROLLBACK and the query timeout of the driver.
	 * Inside a running transaction (0.8.461, e.g. an access condition checked during a save): a SAVEPOINT that is rolled back – a new
	 * START TRANSACTION would commit the running one in MySQL (the save wrote half, its ROLLBACK then hit nothing).
	 */
	public function readOnly(callable $fn, int $seconds = 15): mixed
	{
		static $depth = 0;
		if ($this->pdo->inTransaction()) {
			$sp = 'nu_ro_' . (++$depth);
			$this->pdo->exec($this->type === 'sqlsrv' ? "SAVE TRANSACTION $sp" : "SAVEPOINT $sp");
			try {
				return $fn($this);
			} finally {
				$depth--;
				try {
					if ($this->type === 'sqlsrv') $this->pdo->exec("ROLLBACK TRANSACTION $sp");
					else { $this->pdo->exec("ROLLBACK TO SAVEPOINT $sp"); $this->pdo->exec("RELEASE SAVEPOINT $sp"); }
				} catch (Throwable $e) {
					error_log('nuBuilder Next readOnly savepoint: ' . $e->getMessage());
				}
			}
		}
		$restore = [];
		try {
			if ($this->type === 'mysql') {
				foreach (['max_execution_time' => $seconds * 1000, 'max_statement_time' => $seconds] as $var => $val) {
					try {
						$old = $this->pdo->query("SELECT @@SESSION.$var")->fetchColumn();
						$this->pdo->exec("SET SESSION $var = $val");
						$restore[$var] = $old;
					} catch (Throwable) {
						// the other server has the other variable
					}
				}
				$this->pdo->exec('START TRANSACTION READ ONLY');
			} elseif ($this->type === 'sqlsrv') {
				if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
					$restore['timeout'] = $this->pdo->getAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT);
					$this->pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $seconds);
				}
				$this->pdo->beginTransaction();
			} else {
				$this->pdo->beginTransaction();
			}
			return $fn($this);
		} finally {
			try { if ($this->type === 'mysql') $this->pdo->exec('ROLLBACK'); elseif ($this->pdo->inTransaction()) $this->pdo->rollBack(); } catch (Throwable) {}
			foreach ($restore as $var => $old) {
				try {
					if ($var === 'timeout') $this->pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, (int)$old);
					elseif (is_numeric($old)) $this->pdo->exec("SET SESSION $var = " . (0 + $old));
				} catch (Throwable) {
				}
			}
		}
	}

	public function tableExists(string $table): bool
	{
		return (bool)match ($this->type) {
			'mysql'  => $this->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]),
			'sqlsrv' => $this->value('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?', [$table]),
			default  => $this->value("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]),
		};
	}

	public function lastInsertId(): string
	{
		if ($this->type === 'sqlsrv') {
			$id = $this->value('SELECT CAST(@@IDENTITY AS VARCHAR(40))');      // session scope: survives separate prepared statements
			if ($id !== null) return (string)$id;
		}
		return (string)$this->pdo->lastInsertId();
	}

	/**
	 * Columns of a table: name => ['type' => data type, 'auto' => identity/auto_increment, 'numeric' => bool, 'temporal' => bool].
	 * Names are returned as stored; lookups should use the lower-case key map from columnMap().
	 */
	private static array $columnCache = [];

	public static function forgetColumns(string $table): void
	{
		if ($table === '') { self::$columnCache = []; return; }     // '' = all tables (Reset database, 0.8.628)
		unset(self::$columnCache[strtolower($table)]);
	}

	public function columns(string $table): array
	{
		$key = strtolower($table);
		if (isset(self::$columnCache[$key])) return self::$columnCache[$key];

		$rows = match ($this->type) {
			'mysql'  => $this->all("SELECT COLUMN_NAME AS n, DATA_TYPE AS t, EXTRA AS x, CHARACTER_MAXIMUM_LENGTH AS l FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$table]),
			'sqlsrv' => $this->all("SELECT c.COLUMN_NAME AS n, c.DATA_TYPE AS t,
				CASE WHEN COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA + '.' + c.TABLE_NAME), c.COLUMN_NAME, 'IsIdentity') = 1 THEN 'auto_increment' ELSE '' END AS x,
				c.CHARACTER_MAXIMUM_LENGTH AS l
				FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_NAME = ? ORDER BY c.ORDINAL_POSITION", [$table]),
			default  => array_map(static fn($r) => ['n' => $r['name'], 't' => strtolower((string)preg_replace('/\s*\(.*$/', '', (string)$r['type'])),
				'x' => $r['pk'] ? 'auto_increment' : '', 'l' => preg_match('/\((\d+)\)/', (string)$r['type'], $m) ? (int)$m[1] : null],
				$this->all('PRAGMA table_info(' . self::word($table) . ')')),
		};

		$cols = [];
		foreach ($rows as $r) {
			$t = strtolower((string)$r['t']);
			$cols[(string)$r['n']] = [
				'type'     => $t,
				'auto'     => str_contains(strtolower((string)$r['x']), 'auto_increment'),
				'numeric'  => (bool)preg_match('/int|dec|num|float|double|real|money|bit/', $t),
				'temporal' => (bool)preg_match('/date|time|year/', $t),
				'length'   => isset($r['l']) && $r['l'] !== null && (int)$r['l'] > 0 ? (int)$r['l'] : null,   // characters of text columns (0.8.622)
			];
		}
		return self::$columnCache[$key] = $cols;
	}

	public static function ident(string $s): string
	{
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*(\.[A-Za-z_][A-Za-z0-9_$]*)?$/', $s)) throw new RuntimeException("Invalid identifier '$s'");
		return $s;
	}

	// ----------------------------------------------------------------- value helpers

	/** 'Y-m-d' → datetime literal the driver parses unambiguously (MSSQL DATEFORMAT safe). */
	public function datetime(string $ymd): string
	{
		return $this->type === 'sqlsrv' ? $ymd . 'T00:00:00' : $ymd . ' 00:00:00';
	}

	/**
	 * Equal text of two columns from tables that may have different collations (e.g. nuBuilder's zzzzsys_user in utf8mb4_general_ci,
	 * nuBuilder Next's own tables in utf8mb4_unicode_ci): MySQL compares the bytes, SQL Server the database's collation (0.8.331).
	 */
	public function sameText(string $a, string $b): string
	{
		return match ($this->type) {
			'mysql'  => "CAST($a AS BINARY) = CAST($b AS BINARY)",
			'sqlsrv' => "$a COLLATE DATABASE_DEFAULT = $b COLLATE DATABASE_DEFAULT",
			default  => "$a = $b",
		};
	}

	/** LIKE pattern for "contains", to be used with  LIKE ? ESCAPE '!'  (works on all drivers). */
	public static function like(string $s): string
	{
		return '%' . self::likeRaw($s) . '%';
	}

	/** The text with LIKE's wildcards escaped (ESCAPE '!'), without the % around it (0.8.719: starts with / ends with). */
	public static function likeRaw(string $s): string
	{
		return str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $s);
	}
}
