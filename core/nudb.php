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

		$own = (array)($c['options'] ?? []);
		$options = $own + [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		];
		// MySQL (0.8.1342, review P1): nuBuilder Next's own statements are sent in ONE step (emulated prepare – the values are quoted by
		// PDO and the statement goes to the server once; 40 % less time per statement than prepare + execute). Code of the application
		// (procedures, form events, nuRunQuery, SQL typed by users, custom pages through pdo()) keeps the server-side prepare it always
		// had – native() switches it on for those parts. Several statements in one call are refused on this connection, as a server-side
		// prepare always did, so a mistake in building SQL text can never run a second statement. $nuConfigDBOptions may set either.
		if ($this->type === 'mysql') {
			$this->emulate = !array_key_exists(PDO::ATTR_EMULATE_PREPARES, $own);
			$options += [PDO::ATTR_EMULATE_PREPARES => $this->emulate, nu_pdo_mysql_attr('MULTI_STATEMENTS') => false];
		}

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
		$marks = NuHash::$bound && str_contains($sql, NuHash::MARK);
		if ($marks) [$sql, $params] = NuHash::resolveMarks($sql, $params);   // bound hash variables (0.8.873)
		$this->noteWrite($sql);
		$t = hrtime(true);
		if ($marks && $this->emulated()) {
			// values of hash variables bound into the SQL of the application: a server-side prepare, as before (a value may stand where
			// MySQL wants a number, e.g. LIMIT – a quoted value would not do there)
			$st = $this->native(function () use ($sql, $params): PDOStatement {
				$st = $this->pdo->prepare($sql);
				$st->execute(self::bind($sql, $params));
				return $st;
			});
		} else {
			$st = $this->pdo->prepare($sql);
			$st->execute(self::bind($sql, $params));
		}
		self::$statements++;
		self::$nanos += hrtime(true) - $t;
		return $st;
	}

	/** MySQL: own statements in one step (see the constructor); false for SQL Server, SQLite, a connection handed out by pdo(). */
	private bool $emulate = false;
	private int $nativeDepth = 0;

	/** Are statements sent in one step right now? */
	public function emulated(): bool
	{
		return $this->emulate && $this->nativeDepth === 0;
	}

	/**
	 * Runs $fn with server-side prepared statements (prepare + execute, as nuBuilder Next did before 0.8.1342) – for code and SQL of the
	 * application: procedures and form events (NuPhp::run), nuRunQuery, SQL typed by users (readOnly). A bound value then stays a value
	 * of its own type, so "LIMIT ?" with a number in a string keeps working there. Nested calls are fine.
	 */
	public function native(callable $fn): mixed
	{
		if (!$this->emulate) return $fn();
		if ($this->nativeDepth++ === 0) $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		try {
			return $fn();
		} finally {
			if (--$this->nativeDepth === 0 && $this->emulate) $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
		}
	}

	/**
	 * What this request asked the database so far (0.8.1341): the number of statements through run() / exec() and the time they took
	 * (without reading the rows). Shown in developer mode at the end of a page (#nuPerf, core/nulayout.php) – dev/tests/perf.js
	 * compares it with the budget of each page, so a page that starts to ask more is noticed.
	 */
	public static int $statements = 0;
	public static int $nanos = 0;

	/**
	 * Per-request caches that a statement may invalidate (0.8.827): DDL → the table-exists cache of this connection, a write to
	 * zzzzsys_nunext_setting → nu_setting_value()'s cache. Looks only at the first word, so a SELECT costs one preg_match.
	 */
	private function noteWrite(string $sql): void
	{
		if (preg_match('/^\s*(?:\/\*.*?\*\/\s*)?([A-Za-z]+)/s', $sql, $m) === 1) $verb = strtoupper($m[1]);
		elseif (str_starts_with(ltrim($sql), '(')) return;      // (SELECT …) UNION (SELECT …)
		else $verb = '';                                        // starts with a "-- comment" line or the like: could be anything
		if ($verb === 'SELECT' || $verb === 'SHOW' || $verb === 'SET' || $verb === 'EXPLAIN') return;
		if ($verb === '' || $verb === 'CREATE' || $verb === 'DROP' || $verb === 'RENAME' || $verb === 'ALTER') {
			$this->tableCache = [];
			if (isset($_SESSION['nu_rd_found'])) unset($_SESSION['nu_rd_found']);   // NuRowDetails::candidates: tables changed
		}
		if (($verb === '' || stripos($sql, 'zzzzsys_nunext_setting') !== false) && function_exists('nu_setting_forget')) nu_setting_forget();
		// per-request values (NuMemo): a statement that names its tables drops what was read from them; one that does not (CALL, EXEC … – a
		// stored procedure may change anything) drops everything
		NuMemo::$writes++;
		if (in_array($verb, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'MERGE', 'CREATE', 'DROP', 'RENAME', 'ALTER', 'WITH'], true)) NuMemo::written($sql);
		else NuMemo::forget();
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
		if (trim($orderBy) !== '') $sql .= ' ORDER BY ' . self::uniqueOrder($orderBy);   // '' = no order (MySQL only – NuBrowse, 0.8.956)
		$sql   .= $this->type === 'sqlsrv'
			? " OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY"
			: " LIMIT $limit OFFSET $offset";
		return $this->all($sql, $params);
	}

	/**
	 * An ORDER BY list without repeated items (0.8.792): SQL Server refuses "ORDER BY nu_pk, nu_pk" ("A column has been specified more
	 * than once in the order by list"), MySQL ignores it. The first one of an expression counts (ASC / DESC do not make it another).
	 */
	public static function uniqueOrder(string $orderBy): string
	{
		$items = [];
		$depth = 0; $buf = ''; $q = '';
		for ($i = 0, $n = strlen($orderBy); $i < $n; $i++) {
			$c = $orderBy[$i];
			if ($q !== '') { $buf .= $c; if ($c === $q) $q = ''; continue; }
			if ($c === "'" || $c === '"' || $c === '`' || $c === '[') { $q = $c === '[' ? ']' : $c; $buf .= $c; continue; }
			if ($c === '(') $depth++; elseif ($c === ')') $depth--;
			if ($c === ',' && $depth === 0) { $items[] = $buf; $buf = ''; continue; }
			$buf .= $c;
		}
		$items[] = $buf;
		$out = $seen = [];
		foreach ($items as $it) {
			$it = trim($it);
			if ($it === '') continue;
			$key = strtolower((string)preg_replace('/\s+(ASC|DESC)$/i', '', $it));
			$key = (string)preg_replace('/\s+/', ' ', $key);
			if (isset($seen[$key])) continue;
			$seen[$key] = true;
			$out[] = $it;
		}
		return implode(', ', $out);
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
				// a value stored with spaces around it comes back trimmed from the form (nu_post trims) – not a change (0.8.956)
				elseif (is_string($o) && is_string($v) && $v !== '' && trim($o) === $v) unset($row[$col]);
			}
			if (!$row) return 0;
		}
		$set = [];
		foreach (array_keys($row) as $col) $set[] = self::word($col) . ' = ?';
		$sql = 'UPDATE ' . self::word($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . self::word($pk) . ' = ?';
		return $this->run($sql, [...array_values($row), $id])->rowCount();
	}

	/**
	 * Columns an editor fills with its own default where nuBuilder Forte left NULL (a switch shown off → '0', a status shown as
	 * Active → '1', an empty graphic → its JSON): while the stored value is NULL and the posted one is that default, the column is
	 * left out, so a save without a change keeps nuBuilder Forte's row as it was (0.8.956, dev/tests/compat.js meta:*).
	 * @param array<string, string> $defaults column => the value the editor shows for NULL
	 */
	public static function keepNull(?array $old, array $row, array $defaults): array
	{
		if (!$old) return $row;
		foreach ($defaults as $col => $def) {
			if (array_key_exists($col, $row) && array_key_exists($col, $old) && $old[$col] === null && (string)($row[$col] ?? '') === (string)$def) unset($row[$col]);
		}
		return $row;
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
		$mark = NuMemo::$writes;
		$this->pdo->beginTransaction();
		try {
			$result = $fn($this);
			$this->pdo->commit();
			return $result;
		} catch (Throwable $e) {
			if ($this->pdo->inTransaction()) $this->pdo->rollBack();
			if (NuMemo::$writes !== $mark) NuMemo::forget();    // something was written and is gone again: so may be what was read after it
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

	/**
	 * The PDO connection (savepoints and manual transactions, e.g. NuImport; custom pages). Statements sent through it are not seen
	 * by noteWrite(), so from here on nothing is kept in NuMemo for the rest of the request.
	 */
	public function pdo(): PDO
	{
		NuMemo::off();
		// the raw connection goes to code that may expect server-side prepares (custom pages: $nu->db(), "LIMIT ?" with a bound value):
		// from here on this request uses them for everything (0.8.1342)
		if ($this->emulate) {
			$this->emulate = false;
			$this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		}
		return $this->pdo;
	}

	/** A quoted string literal of this connection (PDO::quote) – for the few places that build SQL text. */
	public function quote(string $v): string
	{
		return (string)$this->pdo->quote($v);
	}

	/** A statement that only changes the connection itself (SET time_zone …) – no data: not a write, no demo check. */
	public function session(string $sql): void
	{
		$this->pdo->exec($sql);
	}

	public function exec(string $sql): int
	{
		NuDemo::check($sql);
		$this->noteWrite($sql);
		$t = hrtime(true);
		$n = (int)$this->pdo->exec($sql);
		self::$statements++;
		self::$nanos += hrtime(true) - $t;
		return $n;
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
		// SQL typed by users: server-side prepares as before (0.8.1342)
		if ($this->emulated()) return $this->native(fn () => $this->readOnly($fn, $seconds));
		static $depth = 0;
		$mark = NuMemo::$writes;                                // a write that gets rolled back here: what was read after it is forgotten
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
				if (NuMemo::$writes !== $mark) NuMemo::forget();
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
			if (NuMemo::$writes !== $mark) NuMemo::forget();
			foreach ($restore as $var => $old) {
				try {
					if ($var === 'timeout') $this->pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, (int)$old);
					elseif (is_numeric($old)) $this->pdo->exec("SET SESSION $var = " . (0 + $old));
				} catch (Throwable) {
				}
			}
		}
	}

	/** @var array<string, bool> table-exists answers of this connection (0.8.827: a page asked the catalogue 12–30 times) */
	private array $tableCache = [];

	public function tableExists(string $table): bool
	{
		$key = strtolower($table);
		if ($this->tableCache === []) {                          // the first question loads the whole list – one query instead of ~20
			$this->tableCache = ['' => true];
			try {
				foreach ($this->tableNames() as $t) $this->tableCache[strtolower($t)] = true;
			} catch (Throwable) {
				return $this->tableCache[$key] = $this->tableExistsNow($table);
			}
		}
		return $this->tableCache[$key] ??= $this->tableExistsNow($table);
	}

	/** @return list<string> every table (and view) of the connected database */
	public function tableNames(): array
	{
		$rows = match ($this->type) {
			'mysql'  => $this->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'),
			'sqlsrv' => $this->all('SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES'),
			default  => $this->all("SELECT name AS t FROM sqlite_master WHERE type = 'table'"),
		};
		return array_map(static fn($r) => (string)($r['t'] ?? $r['T'] ?? reset($r)), $rows);
	}

	private function tableExistsNow(string $table): bool
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

	/** @var array<string, array{0: string, 1: string}|null> "table.column" => [character set, collation] (null = not found) */
	private static array $collCache = [];

	/**
	 * Like sameText(), but an index on $a (a column of nuBuilder Next's own table $table) stays usable (0.8.761, review B1): only the other
	 * side is converted to $a's character set and collation. sameText() casts both sides to BINARY, which no index can use – the users
	 * list sorted by "Last login" took 39 s with 500 users and 200,000 log rows, 0.03 s this way. Unknown column: sameText().
	 */
	public function sameTextOn(string $a, string $b, string $table, string $column): string
	{
		$k = strtolower($table . '.' . $column);
		if (!array_key_exists($k, self::$collCache)) {
			self::$collCache[$k] = null;
			try {
				if ($this->type === 'mysql') {
					$r = $this->one('SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
					if ($r && preg_match('/^\w+$/', (string)$r['cs']) && preg_match('/^\w+$/', (string)$r['co'])) self::$collCache[$k] = [(string)$r['cs'], (string)$r['co']];
				} elseif ($this->type === 'sqlsrv') {
					$co = (string)$this->value('SELECT collation_name FROM sys.columns WHERE object_id = OBJECT_ID(?) AND name = ?', [$table, $column]);
					if (preg_match('/^\w+$/', $co)) self::$collCache[$k] = ['', $co];
				}
			} catch (Throwable) {
				self::$collCache[$k] = null;
			}
		}
		$c = self::$collCache[$k];
		if ($c === null) return $this->sameText($a, $b);
		return match ($this->type) {
			'mysql'  => "$a = CONVERT($b USING {$c[0]}) COLLATE {$c[1]}",
			'sqlsrv' => "$a = $b COLLATE {$c[1]}",
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

/**
 * Values that several parts of one page ask the database for (performance review, 0.8.1341): the signed-in user's row, the rights
 * rows of an access level, a user's permission items, the code of a form event … – read once per request and connection.
 * Every entry names the tables it comes from. A statement that changes data and goes through NuDB (run / exec – also nuRunQuery of
 * form code) drops the entries of every table it names; a rollback drops all of them; once the raw PDO connection was handed out
 * (NuDB::pdo – custom pages, imports) nothing is kept any more. Other requests are not seen, as with any value read earlier in a page.
 */
final class NuMemo
{
	/** @var array<string, array{0: list<string>, 1: mixed}> key => [tables, value] */
	private static array $data = [];
	/** @var array<string, true> lower-case table names the entries depend on */
	private static array $tables = [];
	private static bool $off = false;
	/** Statements that were not plain reads so far (NuDB::noteWrite) – a rollback forgets everything only when it took some back. */
	public static int $writes = 0;

	/**
	 * @template T
	 * @param list<string> $tables lower-case table names the value is read from
	 * @param callable(): T $load
	 * @return T
	 */
	public static function get(string $key, array $tables, callable $load): mixed
	{
		if (self::$off) return $load();
		$k = spl_object_id(nu_db()) . '|' . $key;
		if (array_key_exists($k, self::$data)) return self::$data[$k][1];
		$v = $load();
		if (self::$off) return $v;                              // the loader itself took the raw connection
		self::$data[$k] = [$tables, $v];
		foreach ($tables as $t) self::$tables[$t] = true;
		return $v;
	}

	/** Keeps a value that was read another way (several rows in one statement – NuLookup::prime). */
	public static function put(string $key, array $tables, mixed $value): void
	{
		if (self::$off) return;
		self::$data[spl_object_id(nu_db()) . '|' . $key] = [$tables, $value];
		foreach ($tables as $t) self::$tables[$t] = true;
	}

	/** A value kept under this key into $value: true when there is one (for code that builds the value in several steps – see keep()). */
	public static function find(string $key, mixed &$value): bool
	{
		$k = spl_object_id(nu_db()) . '|' . $key;
		if (self::$off || !array_key_exists($k, self::$data)) return false;
		$value = self::$data[$k][1];
		return true;
	}

	/** Keeps a value and gives it back: `return NuMemo::keep('x', ['table'], $value);` */
	public static function keep(string $key, array $tables, mixed $value): mixed
	{
		self::put($key, $tables, $value);
		return $value;
	}

	/** Is a value kept under this key? (null is a value too) */
	public static function has(string $key): bool
	{
		return !self::$off && array_key_exists(spl_object_id(nu_db()) . '|' . $key, self::$data);
	}

	/**
	 * A statement that is not a plain read went through NuDB: forget what was read from the tables it names. The table name '*' stands
	 * for "any table" (values of queries the application wrote – a lookup's list): dropped by every such statement.
	 */
	public static function written(string $sql): void
	{
		if (!self::$data) return;
		$low = strtolower($sql);
		$hit = [];
		foreach (self::$tables as $t => $_) if ($t === '*' || str_contains($low, $t)) $hit[$t] = true;
		if (!$hit) return;
		foreach (self::$data as $k => [$tables]) {
			foreach ($tables as $t) if (isset($hit[$t])) { unset(self::$data[$k]); break; }
		}
	}

	public static function forget(): void
	{
		self::$data = [];
	}

	/** Forgets one value (a change made another way than through NuDB, e.g. a cache file or another connection). */
	public static function drop(string $key): void
	{
		if (self::$data) unset(self::$data[spl_object_id(nu_db()) . '|' . $key]);
	}

	/** Nothing is kept from now on (the raw connection is in other hands). */
	public static function off(): void
	{
		self::$off = true;
		self::$data = [];
	}

	/** Starts over (tests; a command line job that begins a new piece of work). */
	public static function reset(): void
	{
		self::$off = false;
		self::$data = [];
		self::$tables = [];
	}
}
