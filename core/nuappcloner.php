<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * App cloner (globeadmin, Admin tools → App cloner) – nuBuilder's "Application Cloner", adapted.
 * Copies the whole application: database (tables, views, data, functions, procedures, triggers, events) into another MySQL /
 * MariaDB database – or into an SQL file to download – and the nuBuilder folder (with nuBuilder Next) into another folder,
 * with the database settings changed in the copied nuconfig.php files. A dry run shows what would happen.
 *
 * Improvements over nuBuilder's cloner:
 *   - safety: never the source database itself, never a target folder inside the source folder (endless copy) or the source
 *     inside the target, a folder is only emptied when "empty it" is chosen, nothing outside the target is deleted;
 *   - DEFINER=… is removed from views, routines, triggers and events (the target user may differ – otherwise they fail);
 *   - views are created in dependency order (a view that uses another view comes after it);
 *   - data is read in pages and written in batches (no whole table in memory), binary data stays binary;
 *   - MySQL / MariaDB (0.8.295, ideas from mysqldump – no code taken over): all tables are read in one consistent snapshot
 *     (START TRANSACTION WITH CONSISTENT SNAPSHOT – a save during the clone cannot leave an order without its items), each table is
 *     streamed with one unbuffered SELECT (no LIMIT/OFFSET pages that get slower towards the end), INSERT batches are limited by
 *     size as well (about 1 MB, below max_allowed_packet – large BLOBs no longer break the clone or the import), TIMESTAMP values
 *     are read and written in UTC (time_zone '+00:00' – no shift between servers in different time zones), the rows of a table
 *     are written in one transaction, and the SQL file wraps each table in autocommit=0 … COMMIT and DISABLE / ENABLE KEYS;
 *   - "Add to an existing database" (CREATE … IF NOT EXISTS) besides "stop when it is not empty" and "empty it first";
 *   - the same login as this database can be used; the target password is never stored;
 *   - the SQL file is streamed (large databases), with DELIMITER blocks for routines, triggers and events;
 *   - record counts of source and target are compared at the end; nuBuilder Next's own nuconfig.php is changed too;
 *   - the log is shown while it runs;
 *   - SQL Server (sqlsrv) as well: the structure is read from the system catalog (sys.tables, sys.columns, sys.indexes, sys.sql_modules …)
 *     – columns with IDENTITY, defaults, computed columns, primary key, unique / check constraints, indexes; foreign keys are added after
 *     the data; views, functions, procedures and DML triggers from their definition; data in batches within SQL Server's limits (1000 rows,
 *     2100 parameters), IDENTITY_INSERT, binary as 0x…; the SQL file uses GO between batches. SQL Server has no events; INSERT only.
 */
final class NuAppCloner
{
	/** Parts: key => [label key, group] */
	public const PARTS = [
		'sys_struct' => 'ac_p_sys_struct', 'user_struct' => 'ac_p_user_struct',
		'sys_rows' => 'ac_p_sys_rows', 'app_rows' => 'ac_p_app_rows', 'user_rows' => 'ac_p_user_rows',
		'functions' => 'ac_p_functions', 'procedures' => 'ac_p_procedures', 'triggers' => 'ac_p_triggers', 'events' => 'ac_p_events',
	];
	public const DEFAULT_EXCLUDES = ['.git', '.svn', 'node_modules', '_backup', 'nunext/_backup', 'temp', 'tmp'];
	/** Rows that do not belong into a copy: logged-in sessions and one-time tokens (password reset links …) – the tables are created. */
	public const SESSION_TABLES = ['zzzzsys_session', 'zzzzsys_nunext_session', 'zzzzsys_nunext_token'];
	private const BATCH = 500;
	/** Rows per INSERT of the SQL file (the size limit below usually comes first). */
	private const BATCH_EXPORT = 10000;
	/** Size of one INSERT (bytes) – as mysqldump's net_buffer_length; in a direct copy at most half of the target's max_allowed_packet. */
	private const BATCH_BYTES = 1048576;

	private NuDB $src;
	private PDO $s;
	private ?PDO $t = null;
	private array $o;
	private $log;                 // callable(string $line, string $kind)
	private $out;                 // callable(string $sql) – SQL file
	private array $stats = ['tables' => 0, 'views' => 0, 'rows' => 0, 'functions' => 0, 'procedures' => 0, 'triggers' => 0, 'events' => 0, 'files' => 0, 'bytes' => 0];
	private array $warnings = [];
	private string $srcDb;
	/** SQL Server instead of MySQL / MariaDB (source and target are the same kind). */
	private bool $ms = false;
	private static bool $brackets = false;
	private string $srcCollation = '';
	/** MariaDB sequences of the source (TABLE_TYPE SEQUENCE): created with CREATE SEQUENCE + SETVAL, no rows copied (0.8.291). */
	private array $sequences = [];
	/** Tables that could not be created in the target: their rows are left out. */
	private array $failed = [];
	/** MySQL: source read in a consistent snapshot / session time zone of the source connection before the run (restored at the end). */
	private bool $snapshot = false;
	private ?string $srcTz = null;
	private int $maxBytes = self::BATCH_BYTES;

	public function __construct(NuDB $src, array $options)
	{
		$this->src = $src;
		$this->s   = $src->pdo();
		$this->o   = $options + ['target' => [], 'create_db' => true, 'db_mode' => 'fail', 'parts' => [], 'tables' => null, 'insert' => 'INSERT',
			'files' => false, 'source_path' => '', 'target_path' => '', 'file_mode' => 'fail', 'excludes' => self::DEFAULT_EXCLUDES, 'dry' => false, 'export' => false, 'skip_sessions' => true,
			'skip_rows' => [], 'title' => ''];   // skip_rows: tables created without their rows (backup "without logs"); title: first line of the SQL file
		$this->ms = $src->type === 'sqlsrv';
		self::$brackets = $this->ms;
		$this->srcDb = (string)$this->s->query($this->ms ? 'SELECT DB_NAME()' : 'SELECT DATABASE()')->fetchColumn();
		if ($this->ms) $this->srcCollation = (string)$this->s->query("SELECT CONVERT(nvarchar(128), DATABASEPROPERTYEX(DB_NAME(), 'Collation'))")->fetchColumn();
	}

	// ================================================================== information for the page

	/** Tables and views of this database: [name, type (TABLE|VIEW), rows (estimate), system]. */
	public static function objects(NuDB $db): array
	{
		$out = [];
		if ($db->type === 'sqlsrv') {                                         // own schema (usually dbo), no system objects
			foreach ($db->all("SELECT o.name n, CASE o.type WHEN 'V' THEN 'VIEW' ELSE 'TABLE' END t, (SELECT SUM(p.rows) FROM sys.partitions p WHERE p.object_id = o.object_id AND p.index_id IN (0, 1)) r
				FROM sys.objects o WHERE o.type IN ('U', 'V') AND o.schema_id = SCHEMA_ID() AND o.is_ms_shipped = 0 ORDER BY o.name") as $r) {
				$n = (string)$r['n'];
				if (str_starts_with($n, '___nu')) continue;
				$out[] = ['name' => $n, 'type' => $r['t'], 'rows' => (int)$r['r'], 'system' => str_starts_with($n, 'zzzzsys')];
			}
			return $out;
		}
		foreach ($db->all("SELECT TABLE_NAME n, TABLE_TYPE t, TABLE_ROWS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME") as $r) {
			$n = (string)$r['n'];
			if (str_starts_with($n, '___nu')) continue;                 // nuBuilder's temporary tables
			$out[] = ['name' => $n, 'type' => $r['t'] === 'VIEW' ? 'VIEW' : ($r['t'] === 'SEQUENCE' ? 'SEQUENCE' : 'TABLE'), 'rows' => (int)$r['r'], 'system' => str_starts_with($n, 'zzzzsys')];
		}
		return $out;
	}

	/** Character set and collation of this database. */
	public static function charset(NuDB $db): array
	{
		if ($db->type === 'sqlsrv') return ['', (string)$db->value("SELECT CONVERT(nvarchar(128), DATABASEPROPERTYEX(DB_NAME(), 'Collation'))")];
		$r = $db->one('SELECT DEFAULT_CHARACTER_SET_NAME c, DEFAULT_COLLATION_NAME l FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()');
		return [(string)($r['c'] ?? 'utf8mb4'), (string)($r['l'] ?? 'utf8mb4_unicode_ci')];
	}

	// ================================================================== run

	/** @param callable $log fn(string line, string kind: info|ok|warn|step) · $out fn(string sql) for the SQL file */
	public function run(callable $log, ?callable $out = null): array
	{
		$this->log = $log;
		$this->out = $out;
		$start = microtime(true);
		$this->validate();
		$parts = array_flip($this->o['parts']);

		if (!$this->o['export']) $this->prepareTarget();
		else $this->emitHeader();
		$this->beginSource();
		try {
			$this->runParts($parts);
		} finally {
			$this->endSource();
		}

		if ($this->o['files'] && !$this->o['export']) $this->copyFiles();

		$this->say(t('ac_done_in', round(microtime(true) - $start, 1)), 'ok');
		if (!$this->o['dry'] && !$this->o['export']) NuLog::write('app_cloned', 'database', (string)$this->o['target']['db'], ['stats' => $this->stats, 'files' => $this->o['files'] ? $this->o['target_path'] : '']);
		return ['stats' => $this->stats, 'warnings' => $this->warnings];
	}

	/**
	 * MySQL / MariaDB source (0.8.295): one consistent snapshot for all tables (InnoDB; as mysqldump --single-transaction) and
	 * time_zone '+00:00', so TIMESTAMP values are read in UTC. Not when the connection is already inside a transaction.
	 */
	private function beginSource(): void
	{
		if ($this->ms) return;
		try {
			$this->srcTz = (string)$this->s->query('SELECT @@SESSION.time_zone')->fetchColumn();
			$this->s->exec("SET SESSION time_zone = '+00:00'");
		} catch (PDOException) { $this->srcTz = null; }
		if ($this->o['dry'] || $this->s->inTransaction()) return;
		try {
			$this->s->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			$this->s->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
			$this->snapshot = true;
		} catch (PDOException) { $this->snapshot = false; }
	}

	private function endSource(): void
	{
		if ($this->ms) return;
		if ($this->snapshot) { try { $this->s->exec('COMMIT'); } catch (PDOException) {} $this->snapshot = false; }
		if ($this->srcTz !== null) { try { $this->s->exec('SET SESSION time_zone = ' . $this->s->quote($this->srcTz)); } catch (PDOException) {} $this->srcTz = null; }
	}

	private function runParts(array $parts): void
	{
		[$sysT, $userT, $sysV, $userV] = $this->selectedObjects();
		if ($this->ms && $this->o['export']) $this->dropForeignKeysMs(array_merge(isset($parts['sys_struct']) ? $sysT : [], isset($parts['user_struct']) ? $userT : []));
		// SQL Server: a view must find the functions it uses – views come after the functions (below)
		// MySQL / MariaDB: views come after the functions and procedures (a view may call a function – 0.8.291)
		if (isset($parts['sys_struct'])) { $this->step(t('ac_s_sys_struct')); $this->createTables($sysT); }
		if (isset($parts['user_struct'])) { $this->step(t('ac_s_user_struct')); $this->createTables($userT); }
		if (isset($parts['sys_rows']) || isset($parts['app_rows'])) {
			$this->step(t('ac_s_sys_rows'));
			foreach ($sysT as $tb) $this->copyRows($tb, isset($parts['sys_rows']), isset($parts['app_rows']));
		}
		if (isset($parts['user_rows'])) { $this->step(t('ac_s_user_rows')); foreach ($userT as $tb) $this->copyRows($tb, true, true); }
		$tables = array_merge($sysT, $userT);
		if ($this->ms) {
			if (isset($parts['functions'])) { $this->step(t('ac_s_functions')); $this->routinesMs('FUNCTION', 'functions'); }
			$views = array_merge(isset($parts['sys_struct']) ? $sysV : [], isset($parts['user_struct']) ? $userV : []);
			if ($views) { $this->step(t('ac_s_views')); $this->createViewsMs($views); }
			if (isset($parts['procedures'])) { $this->step(t('ac_s_procedures')); $this->routinesMs('PROCEDURE', 'procedures'); }
			if (isset($parts['triggers'])) { $this->step(t('ac_s_triggers')); $this->triggersMs($tables); }
			if (isset($parts['events'])) { $this->step(t('ac_s_events')); $this->say(t('ac_events_mssql')); }
			$structTables = array_merge(isset($parts['sys_struct']) ? $sysT : [], isset($parts['user_struct']) ? $userT : []);
			if ($structTables) $this->foreignKeysMs($structTables);
		} else {
			foreach (['functions' => 'FUNCTION', 'procedures' => 'PROCEDURE'] as $k => $type) {
				if (isset($parts[$k])) { $this->step(t('ac_s_' . $k)); $this->routines($type, $k); }
			}
			$views = array_merge(isset($parts['sys_struct']) ? $sysV : [], isset($parts['user_struct']) ? $userV : []);
			if ($views) { $this->step(t('ac_s_views')); $this->createViews($views); }
			if (isset($parts['triggers'])) { $this->step(t('ac_s_triggers')); $this->triggers($tables); }
			if (isset($parts['events'])) { $this->step(t('ac_s_events')); $this->routines('EVENT', 'events'); }
		}

		if ($this->o['export']) $this->emit($this->ms ? "\n" : "\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\nSET TIME_ZONE = @nu_old_time_zone;\n");
		elseif (!$this->o['dry']) { if (!$this->ms) $this->t->exec('SET FOREIGN_KEY_CHECKS = 1'); $this->compare($tables); }
	}

	private function say(string $line, string $kind = 'info'): void { ($this->log)($line, $kind); }
	private function step(string $line): void { $this->say($line, 'step'); }
	private function warn(string $line): void { $this->warnings[] = $line; $this->say($line, 'warn'); }
	private function emit(string $sql): void { if ($this->out) ($this->out)($sql); }
	private static function q(string $ident): string
	{
		return self::$brackets ? '[' . str_replace(']', ']]', $ident) . ']' : '`' . str_replace('`', '``', $ident) . '`';
	}
	/** SQL Server: [identifier]. */
	private static function b(string $ident): string { return '[' . str_replace(']', ']]', $ident) . ']'; }
	/** SQL Server: Unicode string literal. */
	private static function nq(string $s): string { return "N'" . str_replace("'", "''", $s) . "'"; }

	private function validate(): void
	{
		if (!in_array($this->src->type, ['mysql', 'sqlsrv'], true)) throw new RuntimeException(t('ac_err_mysql'));
		if (!$this->o['parts'] && !$this->o['files']) throw new RuntimeException(t('ac_err_nothing'));
		if ($this->o['export']) return;
		$t = $this->o['target'];
		if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', (string)($t['db'] ?? ''))) throw new RuntimeException(t('ac_err_dbname'));
		$cfg = nu_config();
		$local = ['localhost', '127.0.0.1', '::1'];
		$h1 = strtolower((string)$cfg['nuConfigDBHost']); $h2 = strtolower((string)$t['host']);
		$sameHost = ($h1 === $h2 || (in_array($h1, $local, true) && in_array($h2, $local, true)))
			&& (int)($cfg['nuConfigDBPort'] ?: ($this->ms ? 1433 : 3306)) === (int)($t['port'] ?: ($this->ms ? 1433 : 3306));
		if ($this->o['parts'] && $sameHost && strcasecmp((string)$t['db'], $this->srcDb) === 0) throw new RuntimeException(t('ac_err_same_db'));
		if ($this->o['files']) {
			$sp = realpath((string)$this->o['source_path']);
			if ($sp === false || !is_dir($sp)) throw new RuntimeException(t('ac_err_source_path'));
			$tp = self::normPath((string)$this->o['target_path']);
			if ($tp === '' || $tp === $sp || str_starts_with($tp . '/', $sp . '/') || str_starts_with($sp . '/', $tp . '/')) throw new RuntimeException(t('ac_err_target_path'));
			if ($tp === '/' || preg_match('~^[A-Za-z]:/?$~', $tp)) throw new RuntimeException(t('ac_err_target_path'));
		}
	}

	/** Absolute, normalised path (also for folders that do not exist yet). */
	public static function normPath(string $p): string
	{
		$p = str_replace('\\', '/', trim($p));
		if ($p === '') return '';
		if (!str_starts_with($p, '/') && !preg_match('~^[A-Za-z]:/~', $p)) $p = str_replace('\\', '/', getcwd()) . '/' . $p;
		$out = [];
		foreach (explode('/', $p) as $i => $seg) {
			if ($seg === '' && $i > 0) continue;
			if ($seg === '.') continue;
			if ($seg === '..') { if (count($out) > 1) array_pop($out); continue; }
			$out[] = $seg;
		}
		return implode('/', $out) ?: '/';
	}

	// ------------------------------------------------------------------ target database

	private function connect(?string $db): PDO
	{
		$t = $this->o['target'];
		if ($this->ms) {
			$dsn = 'sqlsrv:Server=' . $t['host'] . ((int)$t['port'] ? ',' . (int)$t['port'] : '') . ';Database=' . ($db ?? 'master') . ';LoginTimeout=10'
				. (!empty(nu_config()['nuConfigDBTrustServerCertificate']) ? ';TrustServerCertificate=1' : '');
			return new PDO($dsn, (string)$t['user'], (string)$t['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		}
		$dsn = 'mysql:host=' . $t['host'] . ';port=' . (int)($t['port'] ?: 3306) . ($db !== null ? ';dbname=' . $db : '') . ';charset=' . ($t['charset'] ?: 'utf8mb4');
		// native prepares (0.8.295): binary values are sent as they are – emulated, they were escaped and could grow to twice their size
		return new PDO($dsn, (string)$t['user'], (string)$t['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10, PDO::ATTR_EMULATE_PREPARES => false]);
	}

	private function prepareTarget(): void
	{
		if ($this->ms) { $this->prepareTargetMs(); return; }
		$t = $this->o['target'];
		$this->step(t('ac_s_target', $t['db'], $t['host']));
		$server = $this->connect(null);
		$exists = (bool)$server->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $server->quote((string)$t['db']))->fetchColumn();
		if (!$exists) {
			if (!$this->o['create_db']) throw new RuntimeException(t('ac_err_db_missing', $t['db']));
			if ($this->o['dry']) $this->say(t('ac_would_create_db', $t['db']));
			else {
				$server->exec('CREATE DATABASE ' . self::q((string)$t['db']) . ' CHARACTER SET ' . preg_replace('/\W/', '', (string)$t['charset']) . ' COLLATE ' . preg_replace('/\W/', '', (string)$t['collation']));
				$this->say(t('ac_db_created', $t['db']), 'ok');
			}
		}
		if ($exists) {
			$this->t = $this->connect((string)$t['db']);
			$n = (int)$this->t->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
			if ($n > 0) {
				if ($this->o['db_mode'] === 'fail') throw new RuntimeException(t('ac_err_db_not_empty', $t['db'], $n));
				if ($this->o['db_mode'] === 'clear') $this->clearTarget();
				else $this->say(t('ac_db_add', $t['db'], $n));
			}
		} elseif (!$this->o['dry']) {
			$this->t = $this->connect((string)$t['db']);
		}
		if ($this->t && !$this->o['dry']) $this->targetSession();
	}

	/** Session settings of the target connection (also after a reconnect). */
	private function targetSession(): void
	{
		$this->t->exec("SET FOREIGN_KEY_CHECKS = 0");
		// as mysqldump: only NO_AUTO_VALUE_ON_ZERO – no strict mode, so whatever the source holds (zero dates, old ENUM definitions …)
		// is created and inserted unchanged (0.8.291; before, the server's strict sql_mode refused such tables / rows)
		$this->t->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
		// TIMESTAMP values are read in UTC and written in UTC (0.8.295) – no shift when the servers run in different time zones
		$this->t->exec("SET SESSION time_zone = '+00:00'");
		$max = (int)$this->t->query('SELECT @@max_allowed_packet')->fetchColumn();
		if ($max > 0) $this->maxBytes = max(65536, min(self::BATCH_BYTES, intdiv($max, 2)));
	}

	private function clearTarget(): void
	{
		if ($this->ms) { $this->clearTargetMs(); return; }
		$t = $this->t;
		$db = $t->quote((string)$this->o['target']['db']);
		$drop = [];
		foreach ($t->query("SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = $db")->fetchAll(PDO::FETCH_COLUMN) as $n) $drop[] = 'DROP EVENT IF EXISTS ' . self::q($n);
		foreach ($t->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = $db")->fetchAll(PDO::FETCH_COLUMN) as $n) $drop[] = 'DROP TRIGGER IF EXISTS ' . self::q($n);
		foreach ($t->query("SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = $db")->fetchAll(PDO::FETCH_NUM) as [$ty, $n]) $drop[] = 'DROP ' . $ty . ' IF EXISTS ' . self::q($n);
		foreach ($t->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = $db")->fetchAll(PDO::FETCH_COLUMN) as $n) $drop[] = 'DROP VIEW IF EXISTS ' . self::q($n);
		foreach ($t->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = $db AND TABLE_TYPE = 'SEQUENCE'")->fetchAll(PDO::FETCH_COLUMN) as $n) $drop[] = 'DROP SEQUENCE IF EXISTS ' . self::q($n);
		foreach ($t->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = $db AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $n) $drop[] = 'DROP TABLE IF EXISTS ' . self::q($n);
		$this->say(t('ac_clearing', count($drop)), 'warn');
		if ($this->o['dry']) return;
		$t->exec('SET FOREIGN_KEY_CHECKS = 0');
		foreach ($drop as $sql) $t->exec($sql);
	}

	private function emitHeader(): void
	{
		if ($this->ms) {
			$this->emit('-- ' . ($this->o['title'] !== '' ? $this->o['title'] : 'nuBuilder Next app cloner') . ' – SQL export of database [' . $this->srcDb . "] (SQL Server)\n-- Created: " . date('Y-m-d H:i:s')
				. ' · nuBuilder Next ' . NU_VERSION . "\n-- Import into an empty database, e.g. with SQL Server Management Studio or: sqlcmd -S server -d newdb -i file.sql\n-- Batches are separated by GO.\n\n"
				. "SET NOCOUNT ON;\nSET DATEFORMAT ymd;\nSET QUOTED_IDENTIFIER ON;\nSET ANSI_NULLS ON;\nGO\n");
			return;
		}
		[$cs, $coll] = self::charset($this->src);
		$this->emit('-- ' . ($this->o['title'] !== '' ? $this->o['title'] : 'nuBuilder Next app cloner') . " – SQL export of database `{$this->srcDb}`\n-- Created: " . date('Y-m-d H:i:s') . ' · nuBuilder Next ' . NU_VERSION
			. "\n-- Import into an empty database (e.g. with phpMyAdmin or: mysql -u user -p newdb < file.sql)\n\n"
			. "SET NAMES $cs;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\nSET @nu_old_time_zone = @@TIME_ZONE;\nSET TIME_ZONE = '+00:00';\n");
	}

	/** Chosen tables / views, split into nuBuilder system (zzzzsys…) and own. */
	private function selectedObjects(): array
	{
		$only = $this->o['tables'] === null ? null : array_flip((array)$this->o['tables']);
		$sysT = $userT = $sysV = $userV = [];
		foreach (self::objects($this->src) as $ob) {
			if ($only !== null && !isset($only[$ob['name']])) continue;
			if ($ob['type'] === 'SEQUENCE') $this->sequences[$ob['name']] = true;
			if ($ob['type'] === 'VIEW') { if ($ob['system']) $sysV[] = $ob['name']; else $userV[] = $ob['name']; }
			elseif ($ob['system']) $sysT[] = $ob['name']; else $userT[] = $ob['name'];
		}
		return [$sysT, $userT, $sysV, $userV];
	}

	/** Run (or write / only log) one DDL statement. */
	private function ddl(string $sql, string $what): void
	{
		if ($this->o['export']) { $this->emit("\n" . rtrim($sql, ";\n ") . ($this->ms ? "\nGO\n" : ";\n")); return; }
		if ($this->o['dry']) return;
		$this->t->exec($sql);
	}

	private function createTables(array $tables): void
	{
		if ($this->ms) { $this->createTablesMs($tables); return; }
		foreach ($tables as $tb) {
			if (isset($this->sequences[$tb])) { $this->createSequence($tb); continue; }
			$create = (string)$this->s->query('SHOW CREATE TABLE ' . self::q($tb))->fetch(PDO::FETCH_NUM)[1];
			if ($this->o['db_mode'] === 'add' && !$this->o['export']) $create = preg_replace('/^CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $create, 1);
			elseif ($this->o['export']) $create = 'DROP TABLE IF EXISTS ' . self::q($tb) . ";\n" . $create;
			if (!$this->tryDdl($create, 'table', $tb)) { $this->failed[$tb] = true; continue; }
			$this->stats['tables']++;
		}
		if ($tables) $this->say(t('ac_n_tables', count($tables)), 'ok');
	}

	/** One DDL statement; an error is a warning (the object is named) and the clone goes on (0.8.291). */
	private function tryDdl(string $sql, string $kind, string $name): bool
	{
		try {
			$this->ddl($sql, $name);
			return true;
		} catch (PDOException $e) {
			$this->warn(t('ac_obj_failed', $kind, $name, $e->getMessage()));
			return false;
		}
	}

	/** MariaDB sequence: CREATE SEQUENCE as in the source, then its current value (as mysqldump does with SETVAL). */
	private function createSequence(string $sq): void
	{
		$create = (string)$this->s->query('SHOW CREATE SEQUENCE ' . self::q($sq))->fetch(PDO::FETCH_NUM)[1];
		$next = (string)$this->s->query('SELECT next_not_cached_value FROM ' . self::q($sq))->fetchColumn();
		if ($this->o['db_mode'] === 'add' && !$this->o['export']) $create = preg_replace('/^CREATE SEQUENCE/i', 'CREATE SEQUENCE IF NOT EXISTS', $create, 1);
		elseif ($this->o['export']) $create = 'DROP SEQUENCE IF EXISTS ' . self::q($sq) . ";\n" . $create;
		if (!$this->tryDdl($create, 'sequence', $sq)) { $this->failed[$sq] = true; return; }
		if (preg_match('/^-?\d+$/', $next)) {
			$setval = 'DO SETVAL(' . self::q($sq) . ', ' . $next . ', 0)';          // DO: no result set (also silent in an import)
			if ($this->o['export']) $this->emit($setval . ";\n");
			elseif (!$this->o['dry']) {
				try { $this->t->exec($setval); } catch (PDOException $e) { $this->warn(t('ac_obj_failed', 'sequence', $sq, $e->getMessage())); }
			}
		}
		$this->stats['tables']++;
	}

	private static function noDefiner(string $sql): string
	{
		return preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+?)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql) ?? $sql;
	}

	/** Views in dependency order: a view that names another view of the list comes after it. */
	private function createViews(array $views): void
	{
		if (!$views) return;
		$def = [];
		foreach ($views as $v) $def[$v] = self::noDefiner((string)$this->s->query('SHOW CREATE VIEW ' . self::q($v))->fetch(PDO::FETCH_NUM)[1]);
		$done = []; $order = [];
		$visit = function (string $v, array $path) use (&$visit, &$done, &$order, $def): void {
			if (isset($done[$v]) || isset($path[$v])) return;
			$path[$v] = true;
			foreach (array_keys($def) as $o) if ($o !== $v && preg_match('/`' . preg_quote($o, '/') . '`/', $def[$v])) $visit($o, $path);
			$done[$v] = true; $order[] = $v;
		};
		foreach (array_keys($def) as $v) $visit($v, []);
		foreach ($order as $v) {
			$sql = $def[$v];
			if ($this->o['db_mode'] === 'add' || $this->o['export']) $sql = preg_replace('/^CREATE\s+/i', 'CREATE OR REPLACE ', $sql, 1);
			if ($this->tryDdl($sql, 'view', $v)) $this->stats['views']++;
		}
		$this->say(t('ac_n_views', count($order)), 'ok');
	}

	// ------------------------------------------------------------------ data

	/** Copy rows of a table; nuBuilder tables: system rows (id nu…) and / or application rows (other ids). */
	private function copyRows(string $tb, bool $sysRows, bool $appRows): void
	{
		if (!$sysRows && !$appRows) return;
		if (isset($this->sequences[$tb]) || isset($this->failed[$tb])) return;      // sequence: its value comes with CREATE / SETVAL
		if ($this->o['skip_sessions'] && in_array($tb, self::SESSION_TABLES, true)) { $this->say(t('ac_skip_sessions', $tb)); return; }
		if (in_array($tb, (array)$this->o['skip_rows'], true)) { if ($this->o['export']) $this->emit("\n-- " . $tb . ": rows left out\n"); return; }
		if ($this->ms) { $this->copyRowsMs($tb, $sysRows, $appRows); return; }
		$cols = $this->s->query('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->s->quote($tb) . ' AND EXTRA NOT LIKE \'%GENERATED%\' ORDER BY ORDINAL_POSITION')->fetchAll(PDO::FETCH_NUM);
		if (!$cols) return;
		$names = array_column($cols, 0);
		$binary = array_flip(array_column(array_filter($cols, static fn($c) => preg_match('/blob|binary|geometry|point|linestring|polygon|geometrycollection/i', $c[1])), 0));
		// BIT: read as a number and written as a number (0.8.291 – PDO returns BIT as an integer; written as text "170" it became 0xFF)
		$bits = array_flip(array_column(array_filter($cols, static fn($c) => strtolower((string)$c[1]) === 'bit'), 0));
		$selList = implode(', ', array_map(static fn($n) => isset($bits[$n]) ? 'CAST(' . self::q($n) . ' AS UNSIGNED) AS ' . self::q($n) : self::q($n), $names));
		$where = '';
		$pk = $tb . '_id';
		if (str_starts_with($tb, 'zzzzsys') && in_array($pk, $names, true) && !($sysRows && $appRows)) {
			$where = ' WHERE ' . self::q($pk) . ($sysRows ? '' : ' NOT') . " LIKE 'nu%'";
		}
		// pages in a fixed order: by the primary key (any name, also composite – 0.8.291), else nuBuilder's <table>_id
		$pkCols = $this->s->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $this->s->quote($tb)
			. " AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
		if (!$pkCols && in_array($pk, $names, true)) $pkCols = [$pk];
		$order = $pkCols ? ' ORDER BY ' . implode(', ', array_map([self::class, 'q'], $pkCols)) : '';
		if ($this->o['dry']) {
			$total = (int)$this->s->query('SELECT COUNT(*) FROM ' . self::q($tb) . $where)->fetchColumn();
			if ($total > 0) { $this->stats['rows'] += $total; $this->say(t('ac_n_rows', $tb, $total)); }
			return;
		}

		$colList = '(' . implode(', ', array_map([self::class, 'q'], $names)) . ')';
		$verb = ['INSERT IGNORE' => 'INSERT IGNORE INTO ', 'REPLACE' => 'REPLACE INTO '][$this->o['insert']] ?? 'INSERT INTO ';
		$export = (bool)$this->o['export'];
		// rows per INSERT: in a direct copy at most 65535 placeholders; and never more than about 1 MB per statement (0.8.295)
		$maxRows = $export ? self::BATCH_EXPORT : max(1, min(self::BATCH, intdiv(60000, max(1, count($names)))));
		$maxBytes = $export ? self::BATCH_BYTES : $this->maxBytes;
		$ph = '(' . implode(', ', array_fill(0, count($names), '?')) . ')';
		$total = 0;
		$buf = []; $bytes = 0;
		$flush = function () use (&$buf, &$bytes, &$total, $export, $verb, $tb, $colList, $ph, $names, $bits, $binary): void {
			if (!$buf) return;
			if ($export) {
				if ($total === 0) $this->emit("\n-- " . $tb . "\n/*!40000 ALTER TABLE " . self::q($tb) . " DISABLE KEYS */;\nSET autocommit = 0;\n");
				$this->emit($verb . self::q($tb) . ' ' . $colList . " VALUES\n" . implode(",\n", $buf) . ";\n");
			} else {
				$st = $this->t->prepare($verb . self::q($tb) . ' ' . $colList . ' VALUES ' . implode(', ', array_fill(0, count($buf), $ph)));
				$k = 1;
				foreach ($buf as $r) foreach ($r as $i => $x) {
					if ($x !== null && isset($bits[$names[$i]])) $st->bindValue($k++, (int)$x, PDO::PARAM_INT);
					else $st->bindValue($k++, $x, $x === null ? PDO::PARAM_NULL : (isset($binary[$names[$i]]) ? PDO::PARAM_LOB : PDO::PARAM_STR));
				}
				$st->execute();
			}
			$total += count($buf);
			$this->stats['rows'] += count($buf);
			$buf = []; $bytes = 0;
		};

		// one unbuffered SELECT per table (0.8.295, as mysqldump): read once from start to end, constant memory – LIMIT/OFFSET pages
		// had to skip all earlier rows again and again. Nothing else may run on the source connection until the result is read.
		$buffered = $this->s->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
		$this->s->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
		$rs = null;
		if (!$export) $this->t->beginTransaction();                    // the rows of one table in one transaction: much faster on InnoDB
		try {
			$rs = $this->s->query('SELECT ' . $selList . ' FROM ' . self::q($tb) . $where . $order);
			while (($r = $rs->fetch(PDO::FETCH_NUM)) !== false) {
				if ($export) {
					$v = [];
					foreach ($r as $i => $x) $v[] = $x === null ? 'NULL' : (isset($bits[$names[$i]]) ? (string)(int)$x : (isset($binary[$names[$i]]) ? ($x === '' ? "''" : '0x' . bin2hex((string)$x)) : $this->s->quote((string)$x)));
					$line = '(' . implode(', ', $v) . ')';
					$size = strlen($line) + 2;
					if ($buf && $bytes + $size > $maxBytes) $flush();
					$buf[] = $line;
				} else {
					$size = count($r) * 4;
					foreach ($r as $x) if ($x !== null) $size += strlen((string)$x);
					if ($buf && $bytes + $size > $maxBytes) $flush();
					$buf[] = $r;
				}
				$bytes += $size;
				if (count($buf) >= $maxRows) $flush();
			}
			$flush();
			if ($export && $total > 0) $this->emit("COMMIT;\nSET autocommit = 1;\n/*!40000 ALTER TABLE " . self::q($tb) . " ENABLE KEYS */;\n");
			if (!$export) $this->t->commit();
		} catch (PDOException $e) {
			// e.g. one row larger than max_allowed_packet: the table is named, the clone goes on (the counts at the end show the gap)
			if (!$export && $this->t->inTransaction()) {
				try { $this->t->rollBack(); }
				catch (PDOException) {                                      // the server closed the connection (packet too large): connect again
					try { $this->t = $this->connect((string)$this->o['target']['db']); $this->targetSession(); } catch (PDOException) {}
				}
			}
			if ($rs) { try { $rs->closeCursor(); } catch (PDOException) {} }
			$this->s->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
			if ($export) throw $e;
			$this->warn(t('ac_obj_failed', 'rows', $tb, $e->getMessage()));
			return;
		}
		$rs->closeCursor();
		$this->s->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
		if ($total > 0) $this->say(t('ac_n_rows', $tb, $total));
	}

	private function compare(array $tables): void
	{
		$diff = 0;
		if ($this->o['skip_sessions']) $tables = array_values(array_diff($tables, self::SESSION_TABLES));   // left out on purpose
		foreach ($tables as $tb) {
			try {
				$a = (int)$this->s->query('SELECT COUNT(*) FROM ' . self::q($tb))->fetchColumn();
				$b = (int)$this->t->query('SELECT COUNT(*) FROM ' . self::q($tb))->fetchColumn();
			} catch (Throwable) { continue; }
			if ($a !== $b) { $diff++; $this->warn(t('ac_count_diff', $tb, $a, $b)); }
		}
		if (!$diff && $tables) $this->say(t('ac_counts_ok', count($tables)), 'ok');
	}

	// ------------------------------------------------------------------ functions, procedures, events, triggers

	private function routines(string $type, string $stat): void
	{
		$list = $type === 'EVENT'
			? $this->s->query('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME')->fetchAll(PDO::FETCH_COLUMN)
			: $this->s->query('SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = ' . $this->s->quote($type) . ' ORDER BY ROUTINE_NAME')->fetchAll(PDO::FETCH_COLUMN);
		$n = 0;
		foreach ($list as $name) {
			$row = $this->s->query('SHOW CREATE ' . $type . ' ' . self::q($name))->fetch(PDO::FETCH_ASSOC);
			$create = (string)($row[$type === 'EVENT' ? 'Create Event' : ($type === 'FUNCTION' ? 'Create Function' : 'Create Procedure')] ?? '');
			if ($create === '') { $this->warn(t('ac_no_rights', strtolower($type), $name)); continue; }
			if (!$this->routineSql($type, $name, self::noDefiner($create), (string)($row['sql_mode'] ?? ''), (string)($row['time_zone'] ?? ''))) continue;
			$this->stats[$stat]++; $n++;
		}
		if ($n) $this->say(t('ac_n_' . $stat, $n), 'ok');
		elseif (!$list) $this->say(t('ac_none_found'));
	}

	private function triggers(array $tables): void
	{
		$n = 0;
		$tset = array_flip($tables);
		// in the order they run (ACTION_ORDER): a trigger with FOLLOWS / PRECEDES needs the other one first (0.8.291)
		foreach ($this->s->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
			ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER, TRIGGER_NAME')->fetchAll(PDO::FETCH_NUM) as [$name, $tb]) {
			if ($this->o['tables'] !== null && !isset($tset[$tb])) continue;
			if (isset($this->failed[$tb])) continue;
			$row = $this->s->query('SHOW CREATE TRIGGER ' . self::q($name))->fetch(PDO::FETCH_ASSOC);
			$create = (string)($row['SQL Original Statement'] ?? '');
			if ($create === '') { $this->warn(t('ac_no_rights', 'trigger', $name)); continue; }
			if (!$this->routineSql('TRIGGER', $name, self::noDefiner($create), (string)($row['sql_mode'] ?? ''))) continue;
			$n++;
		}
		$this->stats['triggers'] += $n;
		if ($n) $this->say(t('ac_n_triggers', $n), 'ok');
	}

	/**
	 * Function / procedure / trigger / event with the sql_mode it was created with (and an event's time zone) – as mysqldump does;
	 * otherwise e.g. a function written for PIPES_AS_CONCAT or ANSI_QUOTES behaves differently in the copy (0.8.291).
	 * An error is a warning; returns whether it was created.
	 */
	private function routineSql(string $type, string $name, string $create, string $sqlMode = '', string $tz = ''): bool
	{
		$drop = 'DROP ' . $type . ' IF EXISTS ' . self::q($name);
		$set = ($sqlMode !== '' || $type !== 'EVENT' ? 'SET SESSION sql_mode = ' . $this->s->quote($sqlMode) . ";\n" : '')
			. ($tz !== '' ? 'SET SESSION time_zone = ' . $this->s->quote($tz) . ";\n" : '');
		if ($this->o['export']) {
			$this->emit("\n$drop;\nSET @nu_saved_sql_mode = @@SESSION.sql_mode, @nu_saved_time_zone = @@SESSION.time_zone;\n" . $set
				. "DELIMITER \$\$\n" . rtrim($create, "; \n") . " \$\$\nDELIMITER ;\nSET SESSION sql_mode = @nu_saved_sql_mode, time_zone = @nu_saved_time_zone;\n");
			return true;
		}
		if ($this->o['dry']) return true;
		[$oldMode, $oldTz] = $this->t->query('SELECT @@SESSION.sql_mode, @@SESSION.time_zone')->fetch(PDO::FETCH_NUM);
		try {
			$this->t->exec($drop);
			foreach (array_filter(explode(";\n", $set)) as $st) $this->t->exec($st);
			$this->t->exec($create);
			return true;
		} catch (PDOException $e) {
			$this->warn(t('ac_obj_failed', strtolower($type), $name, $e->getMessage()));
			return false;
		} finally {
			$this->t->exec('SET SESSION sql_mode = ' . $this->t->quote((string)$oldMode) . ', time_zone = ' . $this->t->quote((string)$oldTz));
		}
	}

	// ================================================================== SQL Server (sqlsrv)

	private function prepareTargetMs(): void
	{
		$t = $this->o['target'];
		$this->step(t('ac_s_target', $t['db'], $t['host']));
		$server = $this->connect(null);
		$exists = (bool)$server->query('SELECT COUNT(*) FROM sys.databases WHERE name = ' . self::nq((string)$t['db']))->fetchColumn();
		if (!$exists) {
			if (!$this->o['create_db']) throw new RuntimeException(t('ac_err_db_missing', $t['db']));
			if ($this->o['dry']) $this->say(t('ac_would_create_db', $t['db']));
			else {
				$coll = preg_replace('/\W/', '', (string)$t['collation']);
				$server->exec('CREATE DATABASE ' . self::b((string)$t['db']) . ($coll !== '' ? ' COLLATE ' . $coll : ''));
				$this->say(t('ac_db_created', $t['db']), 'ok');
			}
		}
		if ($exists || !$this->o['dry']) $this->t = $this->connect((string)$t['db']);
		if ($exists) {
			$n = (int)$this->t->query("SELECT COUNT(*) FROM sys.objects WHERE type IN ('U', 'V', 'P', 'FN', 'IF', 'TF') AND is_ms_shipped = 0")->fetchColumn();
			if ($n > 0) {
				if ($this->o['db_mode'] === 'fail') throw new RuntimeException(t('ac_err_db_not_empty', $t['db'], $n));
				if ($this->o['db_mode'] === 'clear') $this->clearTargetMs();
				else $this->say(t('ac_db_add', $t['db'], $n));
			}
		}
		if ($this->t && !$this->o['dry']) $this->t->exec('SET DATEFORMAT ymd');   // dates as text 'Y-m-d H:i:s' are read the same everywhere
	}

	/** Empties the target: foreign keys, views, functions, procedures, tables (DML triggers go with their tables). */
	private function clearTargetMs(): void
	{
		$t = $this->t;
		$drop = [];
		foreach ($t->query('SELECT name, OBJECT_NAME(parent_object_id) FROM sys.foreign_keys')->fetchAll(PDO::FETCH_NUM) as [$n, $tb]) $drop[] = 'ALTER TABLE ' . self::b((string)$tb) . ' DROP CONSTRAINT ' . self::b((string)$n);
		$kinds = ['V' => 'VIEW', 'P' => 'PROCEDURE', 'FN' => 'FUNCTION', 'IF' => 'FUNCTION', 'TF' => 'FUNCTION', 'U' => 'TABLE'];
		foreach (['V', 'P', 'FN', 'IF', 'TF', 'U'] as $ty) {
			foreach ($t->query("SELECT name FROM sys.objects WHERE type = '$ty' AND is_ms_shipped = 0")->fetchAll(PDO::FETCH_COLUMN) as $n) $drop[] = 'DROP ' . $kinds[$ty] . ' ' . self::b((string)$n);
		}
		$this->say(t('ac_clearing', count($drop)), 'warn');
		if ($this->o['dry']) return;
		foreach ($drop as $sql) $t->exec($sql);
	}

	/** SQL Server type of a column row (sys.columns + sys.types). */
	public static function msType(array $c): string
	{
		$t = strtolower((string)$c['type_name']);
		$len = (int)$c['max_length'];
		return match ($t) {
			'varchar', 'char', 'varbinary', 'binary' => $t . '(' . ($len === -1 ? 'max' : $len) . ')',
			'nvarchar', 'nchar' => $t . '(' . ($len === -1 ? 'max' : intdiv($len, 2)) . ')',
			'decimal', 'numeric' => $t . '(' . (int)$c['precision'] . ', ' . (int)$c['scale'] . ')',
			'datetime2', 'time', 'datetimeoffset' => $t . '(' . (int)$c['scale'] . ')',
			'float' => (int)$c['precision'] === 53 ? 'float' : 'float(' . (int)$c['precision'] . ')',
			default => $t,
		};
	}

	/**
	 * CREATE TABLE (+ CREATE INDEX …) statements of a table, from the catalog rows (public and pure for tests):
	 * $cols sys.columns rows, $idx sys.indexes rows with 'cols' / 'incl' (column lists: [name, desc]), $checks [name, definition].
	 */
	public static function msTableSql(string $tb, array $cols, array $idx, array $checks, string $dbCollation): array
	{
		$lines = [];
		foreach ($cols as $c) {
			if ($c['computed'] !== null && $c['computed'] !== '') {
				$lines[] = self::b((string)$c['name']) . ' AS ' . $c['computed'] . (!empty($c['is_persisted']) ? ' PERSISTED' : '');
				continue;
			}
			$l = self::b((string)$c['name']) . ' ' . self::msType($c);
			if (!empty($c['collation_name']) && strcasecmp((string)$c['collation_name'], $dbCollation) !== 0) $l .= ' COLLATE ' . $c['collation_name'];
			if (!empty($c['is_identity'])) $l .= ' IDENTITY(' . ($c['seed'] ?? 1) . ', ' . ($c['incr'] ?? 1) . ')';
			$l .= !empty($c['is_nullable']) ? ' NULL' : ' NOT NULL';
			if ($c['def_value'] !== null && $c['def_value'] !== '') $l .= ' CONSTRAINT ' . self::b((string)$c['def_name']) . ' DEFAULT ' . $c['def_value'];
			$lines[] = $l;
		}
		$keyList = static fn(array $k) => implode(', ', array_map(static fn($x) => self::b((string)$x[0]) . (!empty($x[1]) ? ' DESC' : ''), $k));
		$after = [];
		foreach ($idx as $i) {
			$kind = (string)$i['type_desc'] === 'CLUSTERED' ? 'CLUSTERED' : 'NONCLUSTERED';
			if (!empty($i['is_primary_key'])) { $lines[] = 'CONSTRAINT ' . self::b((string)$i['name']) . ' PRIMARY KEY ' . $kind . ' (' . $keyList($i['cols']) . ')'; continue; }
			if (!empty($i['is_unique_constraint'])) { $lines[] = 'CONSTRAINT ' . self::b((string)$i['name']) . ' UNIQUE ' . $kind . ' (' . $keyList($i['cols']) . ')'; continue; }
			$after[] = 'CREATE ' . (!empty($i['is_unique']) ? 'UNIQUE ' : '') . $kind . ' INDEX ' . self::b((string)$i['name']) . ' ON ' . self::b($tb) . ' (' . $keyList($i['cols']) . ')'
				. ($i['incl'] ? ' INCLUDE (' . implode(', ', array_map(static fn($x) => self::b((string)$x[0]), $i['incl'])) . ')' : '')
				. (!empty($i['filter_definition']) ? ' WHERE ' . $i['filter_definition'] : '');
		}
		foreach ($checks as [$name, $def]) $lines[] = 'CONSTRAINT ' . self::b((string)$name) . ' CHECK ' . $def;
		return array_merge(['CREATE TABLE ' . self::b($tb) . " (\n\t" . implode(",\n\t", $lines) . "\n)"], $after);
	}

	private function createTablesMs(array $tables): void
	{
		$n = 0;
		foreach ($tables as $tb) {
			$oid = $this->s->query('SELECT OBJECT_ID(' . self::nq(self::b($tb)) . ')')->fetchColumn();
			$cols = $this->s->query("SELECT c.name, CASE WHEN ty.is_user_defined = 0 THEN ty.name ELSE TYPE_NAME(ty.system_type_id) END AS type_name, c.max_length, c.precision, c.scale,
					c.is_nullable, c.is_identity, c.collation_name, CAST(ic.seed_value AS nvarchar(40)) AS seed, CAST(ic.increment_value AS nvarchar(40)) AS incr,
					cc.definition AS computed, cc.is_persisted, dc.name AS def_name, dc.definition AS def_value
				FROM sys.columns c JOIN sys.types ty ON ty.user_type_id = c.user_type_id
				LEFT JOIN sys.identity_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
				LEFT JOIN sys.computed_columns cc ON cc.object_id = c.object_id AND cc.column_id = c.column_id
				LEFT JOIN sys.default_constraints dc ON dc.object_id = c.default_object_id
				WHERE c.object_id = " . (int)$oid . ' ORDER BY c.column_id')->fetchAll(PDO::FETCH_ASSOC);
			$idx = [];
			foreach ($this->s->query('SELECT index_id, name, type_desc, is_primary_key, is_unique_constraint, is_unique, filter_definition FROM sys.indexes
				WHERE object_id = ' . (int)$oid . ' AND type > 0 AND is_hypothetical = 0 ORDER BY index_id')->fetchAll(PDO::FETCH_ASSOC) as $i) {
				if (!in_array($i['type_desc'], ['CLUSTERED', 'NONCLUSTERED'], true)) { $this->warn(t('ac_index_skipped', $i['name'], $tb, $i['type_desc'])); continue; }
				$idx[(int)$i['index_id']] = $i + ['cols' => [], 'incl' => []];
			}
			foreach ($this->s->query('SELECT ic.index_id, c.name, ic.is_descending_key, ic.is_included_column FROM sys.index_columns ic
				JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
				WHERE ic.object_id = ' . (int)$oid . ' ORDER BY ic.index_id, ic.key_ordinal, ic.index_column_id')->fetchAll(PDO::FETCH_NUM) as [$iid, $cn, $desc, $incl]) {
				if (!isset($idx[(int)$iid])) continue;
				if ((int)$incl) $idx[(int)$iid]['incl'][] = [$cn, false]; else $idx[(int)$iid]['cols'][] = [$cn, (int)$desc === 1];
			}
			$checks = $this->s->query('SELECT name, definition FROM sys.check_constraints WHERE parent_object_id = ' . (int)$oid . ' ORDER BY name')->fetchAll(PDO::FETCH_NUM);
			$stmts = self::msTableSql($tb, $cols, array_values($idx), $checks, $this->srcCollation);
			if ($this->o['export']) {
				$this->emit("\nIF OBJECT_ID(" . self::nq(self::b($tb)) . ", N'U') IS NOT NULL DROP TABLE " . self::b($tb) . ";\nGO\n");
			} elseif (!$this->o['dry'] && $this->o['db_mode'] === 'add' && $this->t->query('SELECT OBJECT_ID(' . self::nq(self::b($tb)) . ", N'U')")->fetchColumn() !== null) {
				$this->say(t('ac_table_exists', $tb));
				continue;
			}
			foreach ($stmts as $sql) $this->ddl($sql, $tb);
			$this->stats['tables']++; $n++;
		}
		if ($n) $this->say(t('ac_n_tables', $n), 'ok');
	}

	/** Views (after the functions) in dependency order – their definition as written in SQL Server, replaced when they exist. */
	private function createViewsMs(array $views): void
	{
		$def = [];
		foreach ($views as $v) {
			$d = $this->s->query('SELECT OBJECT_DEFINITION(OBJECT_ID(' . self::nq(self::b($v)) . '))')->fetchColumn();
			if ($d === null || $d === false || $d === '') { $this->warn(t('ac_no_rights', 'view', $v)); continue; }
			$def[$v] = (string)$d;
		}
		$done = []; $order = [];
		$visit = function (string $v, array $path) use (&$visit, &$done, &$order, $def): void {
			if (isset($done[$v]) || isset($path[$v])) return;
			$path[$v] = true;
			foreach (array_keys($def) as $o) if ($o !== $v && preg_match('/(?<![\w$#@])\[?' . preg_quote($o, '/') . '\]?(?![\w$#@])/i', $def[$v])) $visit($o, $path);
			$done[$v] = true; $order[] = $v;
		};
		foreach (array_keys($def) as $v) $visit($v, []);
		foreach ($order as $v) {
			$this->msModule('VIEW', $v, $def[$v]);
			$this->stats['views']++;
		}
		if ($order) $this->say(t('ac_n_views', count($order)), 'ok');
	}

	/** Drop (if it exists) and create a view / function / procedure / trigger from its definition. */
	private function msModule(string $kind, string $name, string $definition): void
	{
		$drop = 'IF OBJECT_ID(' . self::nq(self::b($name)) . ') IS NOT NULL DROP ' . $kind . ' ' . self::b($name);
		if ($this->o['export']) { $this->emit("\n$drop;\nGO\n" . rtrim($definition) . "\nGO\n"); return; }
		if ($this->o['dry']) return;
		$this->t->exec($drop);
		$this->t->exec($definition);
	}

	/** Functions (scalar, inline and table-valued) or procedures, in the order they were created (a function may use an older one). */
	private function routinesMs(string $kind, string $stat): void
	{
		$types = $kind === 'FUNCTION' ? "'FN', 'IF', 'TF'" : "'P'";
		$n = 0;
		$list = $this->s->query("SELECT o.name, m.definition FROM sys.objects o LEFT JOIN sys.sql_modules m ON m.object_id = o.object_id
			WHERE o.type IN ($types) AND o.schema_id = SCHEMA_ID() AND o.is_ms_shipped = 0 ORDER BY o.create_date, o.name")->fetchAll(PDO::FETCH_NUM);
		foreach ($list as [$name, $def]) {
			if ($def === null || $def === '') { $this->warn(t('ac_no_rights', strtolower($kind), $name)); continue; }   // encrypted or no VIEW DEFINITION right
			$this->msModule($kind, (string)$name, (string)$def);
			$this->stats[$stat]++; $n++;
		}
		if ($n) $this->say(t('ac_n_' . $stat, $n), 'ok');
		elseif (!$list) $this->say(t('ac_none_found'));
	}

	/** DML triggers of the chosen tables. */
	private function triggersMs(array $tables): void
	{
		$n = 0;
		$tset = array_flip($tables);
		foreach ($this->s->query('SELECT tr.name, OBJECT_NAME(tr.parent_id), m.definition FROM sys.triggers tr LEFT JOIN sys.sql_modules m ON m.object_id = tr.object_id
			WHERE tr.parent_class = 1 AND tr.is_ms_shipped = 0 ORDER BY tr.name')->fetchAll(PDO::FETCH_NUM) as [$name, $tb, $def]) {
			if (!isset($tset[(string)$tb])) continue;
			if ($def === null || $def === '') { $this->warn(t('ac_no_rights', 'trigger', $name)); continue; }
			$this->msModule('TRIGGER', (string)$name, (string)$def);
			$n++;
		}
		$this->stats['triggers'] += $n;
		if ($n) $this->say(t('ac_n_triggers', $n), 'ok');
	}

	/** Foreign keys between the copied tables – added after the data, so the order of the tables does not matter. */
	private function foreignKeysMs(array $tables): void
	{
		$fks = $this->fksMs($tables);
		if (!$fks) return;
		$this->step(t('ac_s_fks'));
		$n = 0;
		foreach ($fks as $f) {
			if (!$this->o['export'] && !$this->o['dry'] && $this->o['db_mode'] === 'add' && $this->t->query('SELECT OBJECT_ID(' . self::nq(self::b((string)$f['name'])) . ')')->fetchColumn() !== null) continue;
			$this->ddl(self::msForeignKeySql($f), (string)$f['tb']);
			$n++;
		}
		if ($n) $this->say(t('ac_n_fks', $n), 'ok');
	}

	/** SQL file: drop the foreign keys of the copied tables first, so the file can run again on the same database (DROP TABLE would fail). */
	private function dropForeignKeysMs(array $tables): void
	{
		foreach ($this->fksMs($tables) as $f) {
			$this->emit("\nIF OBJECT_ID(" . self::nq(self::b((string)$f['name'])) . ", N'F') IS NOT NULL ALTER TABLE " . self::b((string)$f['tb']) . ' DROP CONSTRAINT ' . self::b((string)$f['name']) . ";\nGO\n");
		}
	}

	/** @return array<int, array{name: string, tb: string, rt: string, del: string, upd: string, c: list<string>, r: list<string>}> foreign keys whose table and referenced table are both in $tables */
	private function fksMs(array $tables): array
	{
		$tset = array_flip($tables);
		$fks = [];
		foreach ($this->s->query('SELECT fk.object_id, fk.name, OBJECT_NAME(fk.parent_object_id), OBJECT_NAME(fk.referenced_object_id), fk.delete_referential_action_desc, fk.update_referential_action_desc
			FROM sys.foreign_keys fk WHERE fk.schema_id = SCHEMA_ID() ORDER BY fk.name')->fetchAll(PDO::FETCH_NUM) as [$id, $name, $tb, $rt, $del, $upd]) {
			if (isset($tset[(string)$tb], $tset[(string)$rt])) $fks[(int)$id] = ['name' => $name, 'tb' => $tb, 'rt' => $rt, 'del' => $del, 'upd' => $upd, 'c' => [], 'r' => []];
		}
		if (!$fks) return [];
		foreach ($this->s->query('SELECT fkc.constraint_object_id, pc.name, rc.name FROM sys.foreign_key_columns fkc
			JOIN sys.columns pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id
			JOIN sys.columns rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id
			ORDER BY fkc.constraint_object_id, fkc.constraint_column_id')->fetchAll(PDO::FETCH_NUM) as [$id, $c, $r]) {
			if (isset($fks[(int)$id])) { $fks[(int)$id]['c'][] = $c; $fks[(int)$id]['r'][] = $r; }
		}
		return $fks;
	}

	public static function msForeignKeySql(array $f): string
	{
		$act = static fn(string $a) => str_replace('_', ' ', strtoupper($a));
		$cols = static fn(array $l) => implode(', ', array_map(static fn($x) => self::b((string)$x), $l));
		return 'ALTER TABLE ' . self::b((string)$f['tb']) . ' ADD CONSTRAINT ' . self::b((string)$f['name']) . ' FOREIGN KEY (' . $cols($f['c']) . ') REFERENCES '
			. self::b((string)$f['rt']) . ' (' . $cols($f['r']) . ') ON DELETE ' . $act((string)$f['del']) . ' ON UPDATE ' . $act((string)$f['upd']);
	}

	/** One value of a row as SQL Server literal (export, and binary values in direct inserts). */
	public static function msLiteral(mixed $x, bool $binary): string
	{
		if ($x === null) return 'NULL';
		if (is_resource($x)) $x = stream_get_contents($x);
		if ($binary) return $x === '' ? '0x' : '0x' . bin2hex((string)$x);
		return self::nq((string)$x);
	}

	private function copyRowsMs(string $tb, bool $sysRows, bool $appRows): void
	{
		$oid = (int)$this->s->query('SELECT OBJECT_ID(' . self::nq(self::b($tb)) . ')')->fetchColumn();
		// computed columns and rowversion are written by SQL Server itself
		$cols = $this->s->query('SELECT c.name, TYPE_NAME(c.system_type_id), c.is_identity FROM sys.columns c WHERE c.object_id = ' . $oid
			. ' AND c.is_computed = 0 AND c.system_type_id <> 189 ORDER BY c.column_id')->fetchAll(PDO::FETCH_NUM);
		if (!$cols) return;
		$names = array_column($cols, 0);
		$binary = [];
		foreach ($cols as [$cn, $ty]) if (in_array(strtolower((string)$ty), ['varbinary', 'binary', 'image', 'hierarchyid', 'geometry', 'geography'], true)) $binary[$cn] = true;
		$identity = (bool)array_filter($cols, static fn($c) => (int)$c[2] === 1);
		$where = '';
		$pk = $tb . '_id';
		if (str_starts_with($tb, 'zzzzsys') && in_array($pk, $names, true) && !($sysRows && $appRows)) {
			$where = ' WHERE ' . self::b($pk) . ($sysRows ? '' : ' NOT') . " LIKE 'nu%'";
		}
		$total = (int)$this->s->query('SELECT COUNT(*) FROM ' . self::b($tb) . $where)->fetchColumn();
		if ($total === 0) return;
		if ($this->o['dry']) { $this->stats['rows'] += $total; $this->say(t('ac_n_rows', $tb, $total)); return; }

		$order = ' ORDER BY ' . (in_array($pk, $names, true) ? self::b($pk) : '(SELECT NULL)');
		$colList = '(' . implode(', ', array_map([self::class, 'b'], $names)) . ')';
		$params = max(1, count($names) - count($binary));
		$batch = max(1, min(1000, intdiv(2000, $params)));                        // max. 1000 rows per INSERT, 2100 parameters
		if ($this->o['export']) $this->emit("\n-- " . $tb . ' (' . $total . ")\n");
		$idOn = 'SET IDENTITY_INSERT ' . self::b($tb) . ' ON';
		$idOff = 'SET IDENTITY_INSERT ' . self::b($tb) . ' OFF';
		if ($identity) { if ($this->o['export']) $this->emit($idOn . ";\n"); else $this->t->exec($idOn); }
		try {
			for ($off = 0; $off < $total; $off += $batch) {
				$rows = $this->s->query('SELECT ' . implode(', ', array_map([self::class, 'b'], $names)) . ' FROM ' . self::b($tb) . $where . $order
					. ' OFFSET ' . $off . ' ROWS FETCH NEXT ' . $batch . ' ROWS ONLY')->fetchAll(PDO::FETCH_NUM);
				if (!$rows) break;
				if ($this->o['export']) {
					$vals = [];
					foreach ($rows as $r) {
						$v = [];
						foreach ($r as $i => $x) $v[] = self::msLiteral($x, isset($binary[$names[$i]]));
						$vals[] = '(' . implode(', ', $v) . ')';
					}
					$this->emit('INSERT INTO ' . self::b($tb) . ' ' . $colList . " VALUES\n" . implode(",\n", $vals) . ";\n");
				} else {
					$tuples = []; $bind = [];
					foreach ($rows as $r) {
						$v = [];
						foreach ($r as $i => $x) {
							if (isset($binary[$names[$i]])) { $v[] = self::msLiteral($x, true); continue; }   // binary as 0x… (no driver encoding options needed)
							$v[] = '?'; $bind[] = $x;
						}
						$tuples[] = '(' . implode(', ', $v) . ')';
					}
					$st = $this->t->prepare('INSERT INTO ' . self::b($tb) . ' ' . $colList . ' VALUES ' . implode(', ', $tuples));
					foreach ($bind as $k => $x) $st->bindValue($k + 1, $x, $x === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
					$st->execute();
				}
				$this->stats['rows'] += count($rows);
			}
		} finally {
			if ($identity) { if ($this->o['export']) $this->emit($idOff . ";\n"); else $this->t->exec($idOff); }
		}
		if ($this->o['export']) $this->emit("GO\n");
		$this->say(t('ac_n_rows', $tb, $total));
	}

	// ------------------------------------------------------------------ files

	private function copyFiles(): void
	{
		$sp = (string)realpath((string)$this->o['source_path']);
		$tp = self::normPath((string)$this->o['target_path']);
		$this->step(t('ac_s_files', $sp, $tp));
		$ex = array_values(array_filter(array_map(static fn($e) => trim(str_replace('\\', '/', $e), '/ '), (array)$this->o['excludes'])));
		if (is_dir($tp) && (new FilesystemIterator($tp))->valid()) {
			if ($this->o['file_mode'] === 'fail') throw new RuntimeException(t('ac_err_folder_not_empty', $tp));
			if ($this->o['file_mode'] === 'clear') {
				$this->say(t('ac_folder_clearing', $tp), 'warn');
				if (!$this->o['dry']) self::clearDir($tp);
			}
		}
		if (!$this->o['dry'] && !is_dir($tp) && !@mkdir($tp, 0775, true)) throw new RuntimeException(t('ac_err_mkdir', $tp));
		$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($sp, FilesystemIterator::SKIP_DOTS),
			static function (SplFileInfo $f) use ($sp, $ex): bool {
				$rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($sp))), '/');
				foreach ($ex as $e) if ($rel === $e || str_starts_with($rel, $e . '/') || $f->getFilename() === $e) return false;
				return !$f->isLink();
			}), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $f) {
			$rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($sp))), '/');
			$dst = $tp . '/' . $rel;
			if ($f->isDir()) { if (!$this->o['dry'] && !is_dir($dst)) @mkdir($dst, 0775, true); continue; }
			$this->stats['files']++;
			$this->stats['bytes'] += $f->getSize();
			if (!$this->o['dry'] && !@copy($f->getPathname(), $dst)) $this->warn(t('ac_copy_failed', $rel));
		}
		$this->say(t('ac_n_files', $this->stats['files'], round($this->stats['bytes'] / 1048576, 1)), 'ok');
		if (!$this->o['parts'] && !$this->o['target']['db']) return;
		// database settings of the copy: nuBuilder's nuconfig.php and nuBuilder Next's own (in its folder inside nuBuilder)
		$rel = ltrim(str_replace('\\', '/', substr(str_replace('\\', '/', NU_ROOT), strlen(str_replace('\\', '/', $sp)))), '/');
		foreach (array_unique(['nuconfig.php', ($rel !== '' && !str_starts_with($rel, '..') ? $rel . '/nuconfig.php' : 'nuconfig.php')]) as $cfg) {
			$file = $tp . '/' . $cfg;
			if ($this->o['dry']) { if (is_file($sp . '/' . $cfg)) $this->say(t('ac_would_config', $cfg)); continue; }
			if (!is_file($file)) continue;
			if (self::updateConfig($file, $this->o['target'])) $this->say(t('ac_config_done', $cfg), 'ok'); else $this->warn(t('ac_config_failed', $cfg));
		}
	}

	private static function clearDir(string $dir): void
	{
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
	}

	/** Set $nuConfigDBHost / Name / Port / User / Password / CharacterSet / Collate in a copied nuconfig.php (first assignment). */
	public static function updateConfig(string $file, array $t): bool
	{
		$src = (string)@file_get_contents($file);
		if ($src === '') return false;
		$set = ['nuConfigDBHost' => (string)$t['host'], 'nuConfigDBName' => (string)$t['db'], 'nuConfigDBUser' => (string)$t['user'], 'nuConfigDBPassword' => (string)$t['password'],
			'nuConfigDBPort' => (int)($t['port'] ?: 3306), 'nuConfigDBCharacterSet' => (string)$t['charset'], 'nuConfigDBCollate' => (string)$t['collation']];
		$changed = 0;
		foreach ($set as $var => $val) {
			$lit = is_int($val) ? (string)$val : '"' . addcslashes($val, "\"\\\$") . '"';
			$src = preg_replace_callback('/(\$' . $var . '\s*=\s*)("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[0-9]+)(\s*;)/', static function ($m) use ($lit, &$changed) { $changed++; return $m[1] . $lit . $m[3]; }, $src, 1) ?? $src;
		}
		return $changed > 0 && @file_put_contents($file, $src) !== false;
	}
}
