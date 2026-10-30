<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Integrity check of an existing database (0.8.698): before an update (update page "Check package", unattended update, Update →
 * "Check the database") and before setting up nuBuilder Next on a database that already has nuBuilder's tables (?p=dbsetup).
 * Finds what would make an update fail or leave the installation broken: missing core tables, views in place of tables, unreadable
 * (damaged) tables, primary keys (missing, other column, duplicate or empty ids), missing / smaller columns, storage engines, character
 * sets, strict-mode traps (NOT NULL without default, zero-date defaults), invalid JSON, references to deleted records, duplicate
 * login names / codes / object ids, the setup row, the globeadmin's login name used by a user, database steps of a newer version and
 * the database user's right to change the structure. Reads only – except the structure-rights test (a scratch table, dropped again).
 * Levels: error (an update would fail or data would be damaged – going on needs a tick), warn, info.
 */
final class NuIntegrity
{
	/** Tables without which nuBuilder Forte / nuBuilder Next cannot work. */
	private const CORE = ['zzzzsys_form', 'zzzzsys_object', 'zzzzsys_tab', 'zzzzsys_browse', 'zzzzsys_event', 'zzzzsys_user', 'zzzzsys_access',
		'zzzzsys_access_form', 'zzzzsys_access_php', 'zzzzsys_access_report', 'zzzzsys_php', 'zzzzsys_report', 'zzzzsys_setup'];

	/** Columns nuBuilder reads as JSON. */
	private const JSON = [['zzzzsys_user', 'sus_json'], ['zzzzsys_access', 'sal_json'], ['zzzzsys_form', 'sfo_json'], ['zzzzsys_browse', 'sbr_json'],
		['zzzzsys_object', 'sob_json'], ['zzzzsys_object', 'sob_all_json'], ['zzzzsys_report', 'sre_layout'], ['zzzzsys_select', 'sse_json'],
		['zzzzsys_setup', 'set_json'], ['zzzzsys_tab', 'syt_json'], ['zzzzsys_php', 'sph_json']];

	/** References: [child table, column, parent table, condition on the child]. */
	private const REFS = [
		['zzzzsys_object', 'sob_all_zzzzsys_form_id', 'zzzzsys_form', ''],
		['zzzzsys_object', 'sob_all_zzzzsys_tab_id', 'zzzzsys_tab', ''],
		['zzzzsys_object', 'sob_subform_zzzzsys_form_id', 'zzzzsys_form', "c.sob_all_type = 'subform'"],
		['zzzzsys_object', 'sob_lookup_zzzzsys_form_id', 'zzzzsys_form', "c.sob_all_type = 'lookup'"],
		['zzzzsys_tab', 'syt_zzzzsys_form_id', 'zzzzsys_form', ''],
		['zzzzsys_browse', 'sbr_zzzzsys_form_id', 'zzzzsys_form', ''],
		['zzzzsys_event', 'sev_zzzzsys_object_id', 'zzzzsys_object', ''],
		['zzzzsys_access_form', 'slf_zzzzsys_access_id', 'zzzzsys_access', ''],
		['zzzzsys_access_form', 'slf_zzzzsys_form_id', 'zzzzsys_form', ''],
		['zzzzsys_access_php', 'slp_zzzzsys_access_id', 'zzzzsys_access', ''],
		['zzzzsys_access_php', 'slp_zzzzsys_php_id', 'zzzzsys_php', ''],
		['zzzzsys_access_report', 'sre_zzzzsys_access_id', 'zzzzsys_access', ''],
		['zzzzsys_access_report', 'sre_zzzzsys_report_id', 'zzzzsys_report', ''],
		['zzzzsys_user', 'sus_zzzzsys_access_id', 'zzzzsys_access', ''],
		['zzzzsys_access', 'sal_zzzzsys_form_id', 'zzzzsys_form', ''],
	];

	/** Values that must be unique: [table, columns, check id]. */
	private const UNIQUE = [
		['zzzzsys_user', ['sus_login_name'], 'dup_login'],
		['zzzzsys_user', ['sus_code'], 'dups'],
		['zzzzsys_form', ['sfo_code'], 'dups'],
		['zzzzsys_access', ['sal_code'], 'dups'],
		['zzzzsys_php', ['sph_code'], 'dups'],
		['zzzzsys_report', ['sre_code'], 'dups'],
		['zzzzsys_access_form', ['slf_zzzzsys_access_id', 'slf_zzzzsys_form_id'], 'dups'],
		['zzzzsys_access_php', ['slp_zzzzsys_access_id', 'slp_zzzzsys_php_id'], 'dups'],
		['zzzzsys_access_report', ['sre_zzzzsys_access_id', 'sre_zzzzsys_report_id'], 'dups'],
		['zzzzsys_object', ['sob_all_zzzzsys_form_id', 'sob_all_id'], 'dup_objects'],
	];

	public const LEVELS = ['error', 'warn', 'info'];
	private const MAX_LIST = 20;
	public const SCRATCH = 'zzzzsys_nunext_ichk';

	/** @var array<string, array{id: string, level: string, list: list<string>, count: int}> */
	private static array $items = [];
	private static int $checks = 0;

	/**
	 * Runs all checks. $expected: schema_expected.json (of the package; null = the installed one), $steps: database step ids the new
	 * version knows (null = this version's). @return array{items: list<array>, errors: int, warnings: int, infos: int, checks: int, ms: int}
	 */
	public static function run(?array $expected = null, ?array $steps = null, bool $structureTest = true): array
	{
		$t0 = microtime(true);
		self::$items = [];
		self::$checks = 0;
		$db = nu_db();
		$expected ??= NuSchemaCheck::installed();
		$pks = [];                                               // expected table => primary key column
		foreach ((array)($expected['tables'] ?? []) as $t => $def) if (!empty($def['pk'])) $pks[strtolower((string)$t)] = (string)$def['pk'];
		foreach (NuSchema::definitions() as $t => $def) $pks[strtolower((string)$t)] = (string)$def['pk'];
		$tables = self::tables();                                // lower-case name => [name, type, engine, collation]

		self::check('tables', 'error', static function () use ($tables): array {
			return array_values(array_filter(self::CORE, static fn($t) => !isset($tables[$t])));
		});
		self::check('views', 'error', static function () use ($tables, $pks): array {
			return array_values(array_map(static fn($x) => $x['name'], array_filter($tables, static fn($x, $k) => $x['view'] && isset($pks[$k]), ARRAY_FILTER_USE_BOTH)));
		});
		$readable = [];
		self::check('unreadable', 'error', static function () use ($db, $tables, &$readable): array {
			$bad = [];
			foreach ($tables as $k => $x) {
				if ($x['view']) continue;
				try {
					$db->value($db->type === 'sqlsrv' ? 'SELECT TOP 1 1 FROM ' . NuDB::ident($x['name']) : 'SELECT 1 FROM ' . NuDB::ident($x['name']) . ' LIMIT 1');
					$readable[$k] = $x['name'];
				} catch (Throwable $e) {
					$bad[] = $x['name'] . ': ' . self::short($e->getMessage());
				}
			}
			return $bad;
		});
		// primary keys
		$pkActual = self::primaryKeys();
		$noPk = [];
		self::check('pk_missing', 'warn', static function () use ($readable, $pks, $pkActual, &$noPk): array {
			foreach ($readable as $k => $name) if (isset($pks[$k]) && empty($pkActual[$k])) $noPk[$k] = $name;
			return array_values($noPk);
		});
		self::check('pk_dupes', 'error', static function () use ($db, $noPk, $pks): array {
			$out = [];
			foreach ($noPk as $k => $name) {
				$pk = NuDB::ident($pks[$k]);
				if (!isset(self::columnsOf($name)[strtolower($pk)])) continue;
				$n = (int)$db->value("SELECT COUNT(*) FROM (SELECT $pk FROM " . NuDB::ident($name) . " GROUP BY $pk HAVING COUNT(*) > 1) x");
				if ($n > 0) $out[] = $name . '.' . $pk . ': ' . $n;
			}
			return $out;
		});
		self::check('pk_other', 'warn', static function () use ($readable, $pks, $pkActual): array {
			$out = [];
			foreach ($readable as $k => $name) {
				if (!isset($pks[$k]) || empty($pkActual[$k])) continue;
				if ($pkActual[$k] !== [strtolower($pks[$k])]) $out[] = $name . ': ' . implode(', ', $pkActual[$k]) . ' (' . $pks[$k] . ')';
			}
			return $out;
		});
		self::check('pk_empty', 'warn', static function () use ($db, $readable, $pks): array {
			$out = [];
			foreach ($readable as $k => $name) {
				if (!isset($pks[$k])) continue;
				$c = self::columnsOf($name)[strtolower($pks[$k])] ?? null;
				if ($c === null || $c['numeric']) continue;
				$pk = NuDB::ident($c['name']);
				$n = (int)$db->value('SELECT COUNT(*) FROM ' . NuDB::ident($name) . " WHERE $pk IS NULL OR $pk = ''");
				if ($n > 0) $out[] = $name . ': ' . $n;
			}
			return $out;
		});
		// columns of nuBuilder Forte 4.9 (and smaller types)
		if ($expected) {
			self::check('columns', 'warn', static function () use ($expected): array {
				$cmp = NuSchemaCheck::compare($expected);
				$out = array_map(static fn($m) => $m['table'] . '.' . $m['column'] . ' (' . $m['type'] . ')', $cmp['missing']);
				foreach ($cmp['changed'] as $c) if ($c['safe']) $out[] = $c['table'] . '.' . $c['column'] . ': ' . $c['actual'] . ' → ' . $c['expected'];
				return $out;
			});
		}
		if ($db->type === 'mysql') self::mysqlChecks($tables, $expected);
		self::check('json', 'warn', static fn(): array => self::invalidJson($readable));
		// rows of nuBuilder Forte itself (ids nu…, from its install script) are reported apart as a note: not the application's
		$sys = [];
		self::check('orphans', 'warn', static function () use ($readable, &$sys): array { [$app, $s] = self::orphans($readable); $sys = array_merge($sys, $s); return $app; });
		foreach (['dup_login' => 'error', 'dups' => 'warn', 'dup_objects' => 'warn'] as $id => $lvl) {
			self::check($id, $lvl, static function () use ($readable, $id, &$sys): array { [$app, $s] = self::duplicates($readable, $id); $sys = array_merge($sys, $s); return $app; });
		}
		if ($sys) self::add('forte_rows', 'info', $sys);
		if (isset($readable['zzzzsys_setup'])) {
			$n = -1;
			self::check('setup', 'error', static function () use ($db, &$n): array {
				$n = (int)$db->value('SELECT COUNT(*) FROM zzzzsys_setup');
				return $n === 0 ? ['zzzzsys_setup'] : [];
			});
			if ($n > 1) self::add('setup_many', 'warn', ['zzzzsys_setup: ' . $n], $n);
		}
		self::check('ga_user', 'warn', static function () use ($db, $readable): array {
			if (NuInstall::needed()) return [];                 // installer: no nuconfig.php yet
			$ga = (string)nu_cfg('nuConfigDBGlobeadminUsername');
			if ($ga === '' || !isset($readable['zzzzsys_user'])) return [];
			return array_map(static fn($r) => (string)$r['id'] . ' (' . $ga . ')', $db->all('SELECT zzzzsys_user_id AS id FROM zzzzsys_user WHERE sus_login_name = ?', [$ga]));
		});
		self::check('migration_newer', $steps === null ? 'warn' : 'error', static function () use ($db, $readable, $steps): array {
			if (!isset($readable[strtolower(NuMigrate::TABLE)])) return [];
			$known = array_flip($steps ?? NuMigrate::stepIds());
			$have = array_map('strval', array_column($db->all('SELECT ' . NuMigrate::TABLE . '_id AS id FROM ' . NuMigrate::TABLE), 'id'));
			return array_values(array_filter($have, static fn($id) => !isset($known[$id])));
		});
		if ($structureTest && in_array($db->type, ['mysql', 'sqlsrv'], true)) {
			self::check('privileges', 'error', static fn(): array => self::structureRights());
		}
		$items = array_values(self::$items);
		usort($items, static fn($a, $b) => array_search($a['level'], self::LEVELS, true) <=> array_search($b['level'], self::LEVELS, true));
		$cnt = static fn(string $l) => count(array_filter($items, static fn($i) => $i['level'] === $l));
		return ['items' => $items, 'errors' => $cnt('error'), 'warnings' => $cnt('warn'), 'infos' => $cnt('info'), 'checks' => self::$checks,
			'ms' => (int)round((microtime(true) - $t0) * 1000)];
	}

	/** The checks before installing a package: its table structure and its database steps. */
	public static function forPackage(string $zip, string $prefix): array
	{
		$meta = NuDbChanges::fromZip($zip, $prefix);
		$steps = $meta ? array_values(array_filter(array_map(static fn($s) => (string)($s['id'] ?? ''), (array)$meta['steps']))) : null;
		return self::run(NuSchemaCheck::fromZip($zip, $prefix), $steps ?: null);
	}

	/** One check; an exception becomes a note "could not run". */
	private static function check(string $id, string $level, callable $fn): void
	{
		self::$checks++;
		try {
			$list = $fn();
			if ($list) self::add($id, $level, array_map('strval', $list));
		} catch (Throwable $e) {
			self::add('failed', 'info', [$id . ': ' . self::short($e->getMessage())]);
		}
	}

	private static function add(string $id, string $level, array $list, ?int $count = null): void
	{
		if (isset(self::$items[$id])) {
			self::$items[$id]['list'] = array_merge(self::$items[$id]['list'], $list);
			self::$items[$id]['count'] += $count ?? count($list);
			return;
		}
		self::$items[$id] = ['id' => $id, 'level' => $level, 'list' => $list, 'count' => $count ?? count($list)];
	}

	private static function short(string $msg): string
	{
		$msg = trim((string)preg_replace('/\s+/', ' ', $msg));
		return mb_strlen($msg) > 160 ? mb_substr($msg, 0, 157) . '…' : $msg;
	}

	/** zzzzsys_* tables and views: lower-case name => [name, view, engine, collation]. */
	private static function tables(): array
	{
		$db = nu_db();
		$rows = match ($db->type) {
			'mysql'  => $db->all("SELECT TABLE_NAME AS n, TABLE_TYPE AS y, ENGINE AS e, TABLE_COLLATION AS c FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'zzzzsys!_%' ESCAPE '!'"),
			'sqlsrv' => $db->all("SELECT TABLE_NAME AS n, TABLE_TYPE AS y, '' AS e, '' AS c FROM INFORMATION_SCHEMA.TABLES
				WHERE TABLE_CATALOG = DB_NAME() AND TABLE_NAME LIKE 'zzzzsys[_]%'"),
			default  => $db->all("SELECT name AS n, type AS y, '' AS e, '' AS c FROM sqlite_master WHERE type IN ('table', 'view') AND name LIKE 'zzzzsys_%'"),
		};
		$out = [];
		foreach ($rows as $r) {
			$n = (string)$r['n'];
			if ($n === self::SCRATCH) continue;
			$out[strtolower($n)] = ['name' => $n, 'view' => stripos((string)$r['y'], 'view') !== false, 'engine' => (string)($r['e'] ?? ''), 'collation' => (string)($r['c'] ?? '')];
		}
		ksort($out);
		return $out;
	}

	/** lower-case table => list of lower-case primary key columns. */
	private static function primaryKeys(): array
	{
		$db = nu_db();
		$rows = match ($db->type) {
			'mysql'  => $db->all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
				WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' AND TABLE_NAME LIKE 'zzzzsys!_%' ESCAPE '!' ORDER BY TABLE_NAME, ORDINAL_POSITION"),
			'sqlsrv' => $db->all("SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
				JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k ON k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME AND k.TABLE_NAME = tc.TABLE_NAME AND k.TABLE_SCHEMA = tc.TABLE_SCHEMA
				WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_CATALOG = DB_NAME() AND k.TABLE_NAME LIKE 'zzzzsys[_]%' ORDER BY k.TABLE_NAME, k.ORDINAL_POSITION"),
			default  => (static function () use ($db): array {
				$r = [];
				foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'zzzzsys_%'") as $t) {
					foreach ($db->all('PRAGMA table_info(' . NuDB::ident((string)$t['name']) . ')') as $c) if ((int)$c['pk'] > 0) $r[] = ['t' => $t['name'], 'c' => $c['name']];
				}
				return $r;
			})(),
		};
		$out = [];
		foreach ($rows as $r) $out[strtolower((string)$r['t'])][] = strtolower((string)$r['c']);
		return $out;
	}

	/** lower-case column => [name, numeric] of a table. */
	private static function columnsOf(string $table): array
	{
		$out = [];
		foreach (nu_db()->columns($table) as $name => $c) $out[strtolower((string)$name)] = ['name' => (string)$name, 'numeric' => $c['numeric']];
		return $out;
	}

	/** Storage engines, character sets, strict-mode traps (MySQL / MariaDB). */
	private static function mysqlChecks(array $tables, ?array $expected): void
	{
		$db = nu_db();
		// grouped: one line per engine / character set ("MyISAM (40): zzzzsys_access, …")
		$group = static function (array $names): array {
			$out = [];
			foreach ($names as $key => $list) $out[] = $key . ' (' . count($list) . '): ' . implode(', ', $list);
			return $out;
		};
		$byEngine = [];
		foreach ($tables as $x) if (!$x['view'] && $x['engine'] !== '') $byEngine[$x['engine']][] = $x['name'];
		self::check('engine', 'info', static fn(): array => $group(array_filter($byEngine, static fn($k) => in_array(strtolower((string)$k), ['myisam', 'aria'], true), ARRAY_FILTER_USE_KEY)));
		self::check('engine_other', 'warn', static fn(): array => $group(array_filter($byEngine, static fn($k) => !in_array(strtolower((string)$k), ['innodb', 'myisam', 'aria'], true), ARRAY_FILTER_USE_KEY)));
		// character sets: utf8mb3 (nuBuilder Forte's own install script) is a note – emoji are lost; anything else (latin1 …) a warning
		$cs = ['utf8' => [], 'other' => []];
		self::check('charset', 'warn', static function () use ($db, $tables, $group, &$cs): array {
			$tableCs = [];
			foreach ($tables as $k => $x) {
				if ($x['view'] || $x['collation'] === '') continue;
				$set = strtolower((string)preg_replace('/_.*$/', '', $x['collation']));
				$tableCs[$k] = $set;
				if ($set !== 'utf8mb4') $cs[in_array($set, ['utf8', 'utf8mb3'], true) ? 'utf8' : 'other'][$set][] = $x['name'];
			}
			foreach ($db->all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, CHARACTER_SET_NAME AS s FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME LIKE 'zzzzsys!_%' ESCAPE '!' AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME NOT LIKE 'utf8mb4%' AND CHARACTER_SET_NAME <> 'binary'
				ORDER BY TABLE_NAME, ORDINAL_POSITION") as $r) {
				$set = strtolower((string)$r['s']);
				if (($tableCs[strtolower((string)$r['t'])] ?? '') === $set) continue;          // as its table: already listed
				$cs[in_array($set, ['utf8', 'utf8mb3'], true) ? 'utf8' : 'other'][$set][] = $r['t'] . '.' . $r['c'];
			}
			return $group($cs['other']);
		});
		if ($cs['utf8']) self::add('charset_utf8', 'info', $group($cs['utf8']));
		$mode = strtoupper((string)$db->value('SELECT @@SESSION.sql_mode'));
		$strict = str_contains($mode, 'STRICT_TRANS_TABLES') || str_contains($mode, 'STRICT_ALL_TABLES');
		if ($strict) {
			// columns nuBuilder Forte 4.9 itself defines NOT NULL without a default (its own design) are left out
			$forte = [];
			foreach ((array)($expected['tables'] ?? []) as $t => $def) foreach ((array)($def['cols'] ?? []) as $c => $x) {
				$d = (string)($x['def'] ?? '');
				if (stripos($d, 'NOT NULL') !== false && stripos($d, 'DEFAULT') === false) $forte[strtolower($t . '.' . $c)] = true;
			}
			self::check('strict', 'warn', static fn(): array => array_map(static fn($r) => $r['t'] . '.' . $r['c'] . ' (' . $r['y'] . ')', array_filter(
				$db->all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS y FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME LIKE 'zzzzsys!_%' ESCAPE '!' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND COLUMN_KEY <> 'PRI'
					AND EXTRA NOT LIKE '%auto_increment%' AND EXTRA NOT LIKE '%GENERATED%' ORDER BY TABLE_NAME, ORDINAL_POSITION"),
				static fn($r) => !isset($forte[strtolower($r['t'] . '.' . $r['c'])]))));
		}
		if (str_contains($mode, 'NO_ZERO_DATE') || str_contains($mode, 'NO_ZERO_IN_DATE')) {
			self::check('zerodate', 'warn', static fn(): array => array_map(static fn($r) => $r['t'] . '.' . $r['c'] . ' (' . $r['d'] . ')',
				$db->all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_DEFAULT AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME LIKE 'zzzzsys!_%' ESCAPE '!' AND (COLUMN_DEFAULT LIKE '0000-00-00%' OR COLUMN_DEFAULT LIKE '''0000-00-00%')")));
		}
	}

	/** Values of the JSON columns that are not valid JSON: "table.column id". */
	private static function invalidJson(array $readable): array
	{
		$db = nu_db();
		$out = [];
		foreach (self::JSON as [$t, $col]) {
			if (!isset($readable[$t])) continue;
			$cols = self::columnsOf($t);
			if (!isset($cols[$col])) continue;
			$pk = $t . '_id';
			if (!isset($cols[$pk])) continue;
			$ids = null;
			$n = 0;
			try {
				$bad = match ($db->type) {
					'mysql'  => "$col IS NOT NULL AND $col <> '' AND JSON_VALID($col) = 0",
					'sqlsrv' => "$col IS NOT NULL AND LEN($col) > 0 AND ISJSON($col) = 0",
					default  => null,
				};
				if ($bad !== null) {
					$n = (int)$db->value("SELECT COUNT(*) FROM $t WHERE $bad");
					$ids = $n ? array_map('strval', array_column($db->all("SELECT $pk AS id FROM $t WHERE $bad ORDER BY $pk" . ($db->type === 'sqlsrv' ? ' OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY' : ' LIMIT 5')), 'id')) : [];
				}
			} catch (Throwable) {
				$ids = null;                                       // no JSON functions (old server): read the values
			}
			if ($ids === null) {
				$ids = [];
				$n = 0;
				foreach ($db->all("SELECT $pk AS id, $col AS v FROM $t WHERE $col IS NOT NULL") as $r) {
					$v = trim((string)$r['v']);
					if ($v === '') continue;
					json_decode($v);
					if (json_last_error() !== JSON_ERROR_NONE) { $n++; if (count($ids) < 5) $ids[] = (string)$r['id']; }
				}
			}
			if ($n) $out[] = "$t.$col: $n (" . implode(', ', $ids) . ($n > count($ids) ? ', …' : '') . ')';
		}
		return $out;
	}

	/** References to records that no longer exist: "child.column → parent: n (ids …)". @return array{0: list<string>, 1: list<string>} [application rows, Forte's rows] */
	private static function orphans(array $readable): array
	{
		$db = nu_db();
		$out = [[], []];
		foreach (self::REFS as [$child, $col, $parent, $cond]) {
			if (!isset($readable[$child], $readable[$parent])) continue;
			$cc = self::columnsOf($child);
			if (!isset($cc[$col], $cc[$child . '_id']) || !isset(self::columnsOf($parent)[$parent . '_id'])) continue;
			if ($cond !== '' && !isset($cc['sob_all_type'])) continue;
			$from = "FROM $child c LEFT JOIN $parent p ON " . $db->sameText("p.{$parent}_id", "c.$col");
			foreach ([0 => 'NOT LIKE', 1 => 'LIKE'] as $k => $like) {
				$where = "c.$col IS NOT NULL AND c.$col <> '' AND p.{$parent}_id IS NULL AND c.{$child}_id $like 'nu%'" . ($cond !== '' ? " AND $cond" : '');
				$n = (int)$db->value("SELECT COUNT(*) $from WHERE $where");
				if (!$n) continue;
				$ids = array_map('strval', array_column($db->all("SELECT c.{$child}_id AS id $from WHERE $where ORDER BY c.{$child}_id"
					. ($db->type === 'sqlsrv' ? ' OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY' : ' LIMIT 5')), 'id'));
				$out[$k][] = "$child.$col → $parent: $n (" . implode(', ', $ids) . ($n > count($ids) ? ', …' : '') . ')';
			}
		}
		return $out;
	}

	/** Values used more than once (check id of self::UNIQUE). @return array{0: list<string>, 1: list<string>} [application rows, only Forte's rows] */
	private static function duplicates(array $readable, string $id): array
	{
		$db = nu_db();
		$out = [[], []];
		foreach (self::UNIQUE as [$t, $cols, $cid]) {
			if ($cid !== $id || !isset($readable[$t])) continue;
			$have = self::columnsOf($t);
			foreach ($cols as $c) if (!isset($have[$c])) continue 2;
			$list = implode(', ', $cols);
			$nonEmpty = implode(' AND ', array_map(static fn($c) => "$c IS NOT NULL AND $c <> ''", $cols));
			$sel = implode(', ', array_map(static fn($c, $i) => "$c AS v$i", $cols, array_keys($cols)));
			$sql = "SELECT $sel, COUNT(*) AS n, SUM(CASE WHEN {$t}_id LIKE 'nu%' THEN 0 ELSE 1 END) AS app FROM $t WHERE $nonEmpty GROUP BY $list HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC";
			foreach ($db->all($sql) as $r) {
				$vals = [];
				foreach (array_keys($cols) as $i) $vals[] = (string)$r['v' . $i];
				$out[(int)$r['app'] > 0 ? 0 : 1][] = $t . ': ' . implode(' / ', $vals) . ' ×' . (int)$r['n'];
			}
		}
		return $out;
	}

	/** May the database user create, change, index and drop a table? Error texts of what failed. */
	private static function structureRights(): array
	{
		$db = nu_db();
		$t = self::SCRATCH;
		$type = $db->type === 'sqlsrv' ? 'NVARCHAR(25)' : 'VARCHAR(25)';
		try { $db->run("DROP TABLE $t"); } catch (Throwable) { /* not there */ }
		$steps = [
			'CREATE' => "CREATE TABLE $t ({$t}_id $type NOT NULL PRIMARY KEY)",
			'ALTER'  => $db->type === 'sqlsrv' ? "ALTER TABLE $t ADD c1 $type NULL" : "ALTER TABLE $t ADD COLUMN c1 $type NULL",
			'INDEX'  => "CREATE INDEX ix_{$t}_c1 ON $t (c1)",
			'DROP'   => "DROP TABLE $t",
		];
		foreach ($steps as $what => $sql) {
			try {
				$db->run($sql);
			} catch (Throwable $e) {
				if ($what !== 'CREATE' && $what !== 'DROP') { try { $db->run("DROP TABLE $t"); } catch (Throwable) { /* keep the first error */ } }
				return [$what . ': ' . self::short($e->getMessage())];
			}
		}
		return [];
	}

	// ------------------------------------------------------------------ output

	/** Number of problems (level error) apart from the checks in $except (e.g. missing tables the set-up is about to create). */
	public static function blocking(array $r, array $except = []): int
	{
		return count(array_filter($r['items'], static fn($i) => $i['level'] === 'error' && !in_array($i['id'], $except, true)));
	}

	/** Short title of an item (with its count). */
	public static function title(array $it): string
	{
		return t('ic_' . $it['id'], (int)$it['count']);
	}

	/**
	 * The report as HTML (escaped). $ackForm: id of the form whose submit needs the "go on anyway" tick when errors were found
	 * (checkbox name integrity_ack, required).
	 */
	public static function html(array $r, ?string $ackForm = null): string
	{
		$lvl = ['error' => ['danger', 'bi-exclamation-octagon-fill'], 'warn' => ['warning', 'bi-exclamation-triangle-fill'], 'info' => ['secondary', 'bi-info-circle']];
		if (!$r['items']) return '<p class="small text-success mb-0" id="nuIcOk"><i class="bi bi-check-circle me-1"></i>' . h(t('ic_ok', (int)$r['checks'])) . '</p>';
		$html = '<p class="small mb-2" id="nuIcSum">' . h(t('ic_sum', (int)$r['errors'], (int)$r['warnings'], (int)$r['infos'])) . '</p><div class="nuIcList">';
		foreach ($r['items'] as $it) {
			[$cls, $icon] = $lvl[$it['level']];
			$list = array_slice($it['list'], 0, self::MAX_LIST);
			$html .= '<details class="nuIcItem mb-1" data-ic="' . h($it['id']) . '" data-level="' . h($it['level']) . '"' . ($it['level'] === 'error' ? ' open' : '') . '>'
				. '<summary class="small"><i class="bi ' . $icon . ' text-' . $cls . ' me-1" aria-hidden="true"></i>'
				. '<span class="badge text-bg-' . $cls . '-subtle text-' . $cls . '-emphasis border me-1">' . h(t('ic_lvl_' . $it['level'])) . '</span>'
				. h(self::title($it)) . '</summary>'
				. '<div class="small text-body-secondary ms-4 mt-1">' . h(t('ic_' . $it['id'] . '_h')) . '</div>'
				. '<ul class="small ms-4 mb-2 nuIcRows">';
			foreach ($list as $line) $html .= '<li><code>' . h($line) . '</code></li>';
			if (count($it['list']) > count($list)) $html .= '<li class="text-body-secondary">' . h(t('ic_more', count($it['list']) - count($list))) . '</li>';
			$html .= '</ul></details>';
		}
		$html .= '</div>';
		if ($r['errors'] && $ackForm !== null) {
			$html .= '<div class="form-check mt-2 nuIcAck"><input class="form-check-input" type="checkbox" name="integrity_ack" value="1" id="nuIcAck" required form="' . h($ackForm) . '">'
				. '<label class="form-check-label small fw-semibold" for="nuIcAck">' . h(t('ic_ack')) . '</label></div>';
		}
		return $html;
	}

	/** Plain lines for the command line / API. @return list<string> */
	public static function lines(array $r): array
	{
		if (!$r['items']) return [t('ic_ok', (int)$r['checks'])];
		$out = [t('ic_sum', (int)$r['errors'], (int)$r['warnings'], (int)$r['infos'])];
		foreach ($r['items'] as $it) {
			$out[] = strtoupper($it['level']) . ' ' . self::title($it);
			foreach (array_slice($it['list'], 0, self::MAX_LIST) as $l) $out[] = '    ' . $l;
			if (count($it['list']) > self::MAX_LIST) $out[] = '    ' . t('ic_more', count($it['list']) - self::MAX_LIST);
		}
		return $out;
	}

	/** Compact form for JSON answers (unattended update). */
	public static function summary(array $r): array
	{
		return ['errors' => $r['errors'], 'warnings' => $r['warnings'], 'infos' => $r['infos'],
			'items' => array_map(static fn($it) => ['id' => $it['id'], 'level' => $it['level'], 'title' => self::title($it), 'list' => array_slice($it['list'], 0, self::MAX_LIST)], $r['items'])];
	}
}
