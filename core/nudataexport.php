<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Data export (0.8.758): saved exports of tables, views and queries to Excel (.xlsx) or CSV – Admin tools → Data export (?p=dataexport,
 * admin right dataexport) and the REST API (/v1/exports). An export (zzzzsys_nunext_export) has one or more DATASETS – each a sheet of
 * the workbook or a CSV file (several → ZIP):
 *   {name, source: table | sql | select, table, columns: [{col, head, fmt}], where: {m: all|any, c: [[col, op, value, value2], …]},
 *    sort: [[col, asc|desc], …], sql, select (zzzzsys_select id), reimport}
 * Columns: for a table the ticked ones in order (empty = all, secret-looking ones left out unless ticked); for a query the result
 * columns (an empty list = all of them). head = the heading (default: column name), fmt = Excel number format / CSV date format
 * ('' = by type). reimport: the primary key first and column names as headings, so Data import can update the rows by key.
 * Placeholders #name# in SQL become parameters asked before the run (system ones – USER_ID, TODAY … of NuHash::plain() – are filled).
 * Values are typed from the column (int / decimal / bool / date / datetime / text): numbers with more than 15 digits and text columns
 * stay text (leading zeros), dates before 1900 become ISO text. Everything is streamed row by row (NuXlsxStream / fputcsv); the query
 * runs in a read-only transaction. CSV: separator, line ends, BOM, header, NULL text, date format, decimal separator, formula protection.
 */
final class NuDataExport
{
	public const T = 'zzzzsys_nunext_export';
	public const FORMAT_VERSION = 'nunext-export-1';
	public const OPS = ['eq', 'neq', 'contains', 'ncontains', 'starts', 'ends', 'lt', 'le', 'gt', 'ge', 'between', 'in', 'empty', 'nempty', 'true', 'false'];
	public const OPS_BY_KIND = [
		'text' => ['contains', 'ncontains', 'eq', 'neq', 'starts', 'ends', 'in', 'empty', 'nempty'],
		'int' => ['eq', 'neq', 'lt', 'le', 'gt', 'ge', 'between', 'in', 'empty', 'nempty'],
		'decimal' => ['eq', 'neq', 'lt', 'le', 'gt', 'ge', 'between', 'empty', 'nempty'],
		'date' => ['eq', 'neq', 'lt', 'le', 'gt', 'ge', 'between', 'empty', 'nempty'],
		'datetime' => ['eq', 'neq', 'lt', 'le', 'gt', 'ge', 'between', 'empty', 'nempty'],
		'time' => ['eq', 'neq', 'lt', 'gt', 'between', 'empty', 'nempty'],
		'bool' => ['true', 'false', 'empty', 'nempty'],
	];
	/** Excel number formats offered per kind ('' = by type); the user may type any other. */
	public const FORMATS = [
		'int' => ['0', '#,##0', '@'], 'decimal' => ['0.00', '#,##0.00', '0', '#,##0', '0.000', '0%', '0.00%', '@'],
		'date' => ['yyyy-mm-dd', 'dd.mm.yyyy', 'dd/mm/yyyy', 'mm/dd/yyyy', 'd mmm yyyy', '@'],
		'datetime' => ['yyyy-mm-dd hh:mm', 'yyyy-mm-dd hh:mm:ss', 'dd.mm.yyyy hh:mm', 'dd/mm/yyyy hh:mm', 'mm/dd/yyyy hh:mm', 'yyyy-mm-dd', '@'],
		'time' => ['hh:mm', 'hh:mm:ss'], 'bool' => [], 'text' => ['@'],
	];
	public const CSV_SEPS = ['semicolon' => ';', 'comma' => ',', 'tab' => "\t", 'pipe' => '|'];
	public const CSV_DEFAULTS = ['sep' => 'semicolon', 'eol' => 'crlf', 'bom' => true, 'head' => true, 'null' => '', 'date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s',
		'decimal' => '.', 'protect' => true, 'bool' => '1/0'];
	public const MAX_DATASETS = 20;
	public const MAX_CONDITIONS = 30;
	public const PREVIEW_ROWS = 20;
	public const QUERY_SECONDS = 1800;
	/** Placeholders the server fills (NuHash::plain()); every other #name# is a parameter of the export. */
	private const BUILTIN = ['USER_ID', 'LOGIN_NAME', 'USER_LOGIN', 'USER_NAME', 'USER_EMAIL', 'ACCESS_LEVEL_ID', 'ACCESS_LEVEL_CODE', 'USER_GROUP_ID', 'GLOBAL_ACCESS',
		'TODAY', 'NOW', 'YEAR', 'MONTH', 'DATE', 'TIME', 'DATETIME', 'TIMESTAMP'];

	// ================================================================= rights, tables, columns

	public static function can(string $level = 'view'): bool
	{
		return NuAdmin::can('dataexport', $level);
	}

	/**
	 * Tables and views of the database: [name => ['view' => bool, 'system' => bool]] – zzzzsys_ tables are offered but marked (5a).
	 * @return array<string, array{view: bool, system: bool}>
	 */
	public static function tables(): array
	{
		$db = nu_db();
		$rows = match ($db->type) {
			'mysql'  => $db->all("SELECT TABLE_NAME AS n, TABLE_TYPE AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"),
			'sqlsrv' => $db->all("SELECT TABLE_NAME AS n, TABLE_TYPE AS t FROM INFORMATION_SCHEMA.TABLES ORDER BY TABLE_NAME"),
			default  => $db->all("SELECT name AS n, CASE WHEN type = 'view' THEN 'VIEW' ELSE 'BASE TABLE' END AS t FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name"),
		};
		$out = [];
		foreach ($rows as $r) {
			$n = (string)$r['n'];
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $n) || str_starts_with($n, 'sqlite_')) continue;
			$out[$n] = ['view' => strtoupper((string)$r['t']) === 'VIEW', 'system' => str_starts_with(strtolower($n), 'zzzzsys_')];
		}
		return $out;
	}

	/**
	 * Columns of a table with their export kind. @return array<string, array{kind: string, pk: bool, secret: bool, type: string}>
	 */
	public static function columns(string $table): array
	{
		if (!isset(self::tables()[$table])) throw new RuntimeException(t('dxp_err_table'));
		if (NuDemo::active() && (in_array(strtolower($table), NuDemo::HIDDEN_TABLES, true) || str_starts_with(strtolower($table), 'zzzzsys_nunext_'))) throw new RuntimeException(t('demo_not_available'));
		$db = nu_db();
		$pk = self::primaryKey($table);
		$out = [];
		foreach ($db->columns($table) as $name => $c) {
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', (string)$name)) continue;
			$secret = NuSecurity::secretName((string)$name) || strtolower((string)$name) === 'sus_json';   // sus_json: login tokens, 2FA / OTP keys
			$out[$name] = ['kind' => self::kindOfType((string)$c['type'], $c), 'pk' => $name === $pk, 'secret' => $secret, 'type' => (string)$c['type']];
		}
		return $out;
	}

	public static function primaryKey(string $table): string
	{
		$db = nu_db();
		try {
			$k = match ($db->type) {
				'mysql'  => $db->value("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI' ORDER BY ORDINAL_POSITION", [$table]),
				'sqlsrv' => $db->value("SELECT TOP 1 ku.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku ON ku.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
					WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_NAME = ? ORDER BY ku.ORDINAL_POSITION", [$table]),
				default  => $db->value("SELECT name FROM pragma_table_info(?) WHERE pk = 1", [$table]),
			};
		} catch (Throwable) {
			$k = null;
		}
		if ($k === null || $k === '') { $cols = array_keys($db->columns($table)); $k = $cols[0] ?? ''; }   // a view: the first column
		return (string)$k;
	}

	/** int / decimal / bool / date / datetime / time / text from a database type. */
	public static function kindOfType(string $t, array $c = []): string
	{
		$t = strtolower($t);
		return match (true) {
			in_array($t, ['bit', 'boolean', 'bool'], true) => 'bool',
			$t === 'tinyint' && (int)($c['length'] ?? 0) === 1 => 'bool',
			in_array($t, ['date'], true) => 'date',
			str_contains($t, 'time') && !str_contains($t, 'date') => 'time',
			str_contains($t, 'date') || $t === 'timestamp' => 'datetime',
			$t === 'year' || str_contains($t, 'int') => 'int',
			(bool)preg_match('/dec|num|float|double|real|money/', $t) => 'decimal',
			default => 'text',
		};
	}

	/** Kind from PDO column meta (query sources). */
	private static function kindOfMeta(array $m): string
	{
		if (!empty($m['sqlsrv:decl_type'])) return self::kindOfType((string)$m['sqlsrv:decl_type'], ['length' => (int)($m['len'] ?? 0)]);   // pdo_sqlsrv: native_type is always "string"
		$n = strtoupper((string)($m['native_type'] ?? ''));
		$len = (int)($m['len'] ?? 0);
		if ($n === '') return 'text';
		if (in_array($n, ['TINY'], true) && $len === 1) return 'bool';
		if ($n === 'BIT') return 'bool';
		if ($n === 'DATE') return 'date';
		if ($n === 'TIME') return 'time';
		if (in_array($n, ['DATETIME', 'TIMESTAMP', 'DATETIME2', 'SMALLDATETIME'], true)) return 'datetime';
		if (in_array($n, ['TINY', 'SHORT', 'LONG', 'LONGLONG', 'INT24', 'YEAR', 'INT', 'BIGINT', 'SMALLINT', 'TINYINT'], true)) return 'int';
		if (in_array($n, ['NEWDECIMAL', 'DECIMAL', 'FLOAT', 'DOUBLE', 'NUMERIC', 'MONEY', 'REAL'], true)) return 'decimal';
		return 'text';
	}

	// ================================================================= stored exports

	public static function ensure(): void { NuSchema::ensure(); }

	public static function all(): array
	{
		self::ensure();
		try {
			return array_map([self::class, 'row'], nu_db()->all('SELECT * FROM ' . self::T . ' ORDER BY name'));
		} catch (Throwable) {
			return [];
		}
	}

	/** By id or code. */
	public static function get(string $ref): ?array
	{
		if ($ref === '') return null;
		self::ensure();
		$r = nu_db()->one('SELECT * FROM ' . self::T . ' WHERE ' . self::T . '_id = ? OR code = ?', [$ref, $ref]);
		return $r ? self::row($r) : null;
	}

	private static function row(array $r): array
	{
		$opt = json_decode((string)$r['options'], true);
		$ds = json_decode((string)$r['datasets'], true);
		return ['id' => (string)$r[self::T . '_id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'description' => (string)$r['description'],
			'format' => (string)$r['format'] === 'csv' ? 'csv' : 'xlsx', 'filename' => (string)$r['filename'], 'options' => self::cleanOptions(is_array($opt) ? $opt : []),
			'datasets' => array_map([self::class, 'cleanDataset'], is_array($ds) && array_is_list($ds) ? $ds : []),
			'created_by' => (string)$r['created_by'], 'created_at' => (string)$r['created_at'], 'updated_by' => (string)$r['updated_by'], 'updated_at' => (string)$r['updated_at'],
			'last_run_at' => (string)$r['last_run_at'], 'runs' => (int)$r['runs']];
	}

	/** The shape of a stored / posted dataset (ids and SQL checked at run time). */
	public static function cleanDataset(mixed $d): array
	{
		$d = is_array($d) ? $d : [];
		$src = in_array($d['source'] ?? '', ['table', 'sql', 'select'], true) ? $d['source'] : 'table';
		$cols = [];
		foreach (is_array($d['columns'] ?? null) ? $d['columns'] : [] as $c) {
			if (is_string($c)) $c = ['col' => $c];
			if (!is_array($c) || !preg_match('/^[A-Za-z_][A-Za-z0-9_$ .-]{0,127}$/', (string)($c['col'] ?? ''))) continue;
			$cols[] = ['col' => (string)$c['col'], 'head' => mb_substr(trim((string)($c['head'] ?? '')), 0, 100), 'fmt' => mb_substr(trim((string)($c['fmt'] ?? '')), 0, 60)];
		}
		$where = ['m' => ($d['where']['m'] ?? '') === 'any' ? 'any' : 'all', 'c' => []];
		foreach (is_array($d['where']['c'] ?? null) ? $d['where']['c'] : [] as $c) {
			if (!is_array($c) || count($where['c']) >= self::MAX_CONDITIONS) continue;
			$col = (string)($c[0] ?? ''); $op = (string)($c[1] ?? '');
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $col) || !in_array($op, self::OPS, true)) continue;
			$where['c'][] = [$col, $op, mb_substr((string)($c[2] ?? ''), 0, 1000), mb_substr((string)($c[3] ?? ''), 0, 1000)];
		}
		$sort = [];
		foreach (is_array($d['sort'] ?? null) ? $d['sort'] : [] as $s) {
			if (!is_array($s) || count($sort) >= 10 || !preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', (string)($s[0] ?? ''))) continue;
			$sort[] = [(string)$s[0], ($s[1] ?? '') === 'desc' ? 'desc' : 'asc'];
		}
		return ['name' => mb_substr(trim((string)($d['name'] ?? '')), 0, 31), 'source' => $src,
			'table' => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)($d['table'] ?? '')) ? (string)$d['table'] : '',
			'columns' => $cols, 'where' => $where, 'sort' => $sort, 'sql' => mb_substr((string)($d['sql'] ?? ''), 0, 20000),
			'select' => mb_substr(trim((string)($d['select'] ?? '')), 0, 25), 'reimport' => !empty($d['reimport'])];
	}

	public static function cleanOptions(array $o): array
	{
		$csv = is_array($o['csv'] ?? null) ? $o['csv'] : [];
		$out = ['csv' => [
			'sep' => isset(self::CSV_SEPS[(string)($csv['sep'] ?? '')]) ? (string)$csv['sep'] : self::CSV_DEFAULTS['sep'],
			'eol' => ($csv['eol'] ?? '') === 'lf' ? 'lf' : 'crlf',
			'bom' => !isset($csv['bom']) || !empty($csv['bom']),
			'head' => !isset($csv['head']) || !empty($csv['head']),
			'null' => mb_substr((string)($csv['null'] ?? ''), 0, 20),
			'date' => mb_substr((string)($csv['date'] ?? self::CSV_DEFAULTS['date']), 0, 30) ?: self::CSV_DEFAULTS['date'],
			'datetime' => mb_substr((string)($csv['datetime'] ?? self::CSV_DEFAULTS['datetime']), 0, 30) ?: self::CSV_DEFAULTS['datetime'],
			'decimal' => ($csv['decimal'] ?? '.') === ',' ? ',' : '.',
			'protect' => !isset($csv['protect']) || !empty($csv['protect']),
			'bool' => in_array($csv['bool'] ?? '', ['1/0', 'true/false', 'yes/no'], true) ? (string)$csv['bool'] : '1/0',
		]];
		return $out;
	}

	/** Validates and stores. @return array{0: string, 1: array} [id, errors] */
	public static function save(array $in, string $id = ''): array
	{
		self::ensure();
		$db = nu_db();
		$errors = [];
		$name = trim((string)($in['name'] ?? ''));
		$code = strtolower(trim((string)($in['code'] ?? '')));
		if ($name === '') $errors['name'] = t('err_required');
		nu_maxlen($errors, 'name', $name, 100);
		if ($code === '') $code = self::codeFrom($name);
		if (!preg_match('/^[a-z0-9_]{1,50}$/', $code)) $errors['code'] = t('dxp_err_code');
		elseif ((int)$db->value('SELECT COUNT(*) FROM ' . self::T . ' WHERE code = ? AND ' . self::T . '_id <> ?', [$code, $id])) $errors['code'] = t('dxp_err_code_taken');
		$ds = $in['datasets'] ?? [];
		if (is_string($ds)) $ds = json_decode($ds, true);
		$ds = array_map([self::class, 'cleanDataset'], is_array($ds) && array_is_list($ds) ? array_slice($ds, 0, self::MAX_DATASETS) : []);
		if (!$ds) $errors['datasets'] = t('dxp_err_no_dataset');
		foreach ($ds as $i => $d) {
			$e = self::checkDataset($d);
			if ($e !== '') { $errors['datasets'] = t('dxp_err_dataset', $d['name'] !== '' ? $d['name'] : (string)($i + 1), $e); break; }
		}
		$row = ['code' => $code, 'name' => $name, 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 1000),
			'format' => ($in['format'] ?? '') === 'csv' ? 'csv' : 'xlsx', 'filename' => mb_substr(trim((string)($in['filename'] ?? '')), 0, 100),
			'options' => json_encode(self::cleanOptions(is_array($in['options'] ?? null) ? $in['options'] : []), JSON_UNESCAPED_UNICODE),
			'datasets' => json_encode($ds, JSON_UNESCAPED_UNICODE), 'updated_by' => self::who(), 'updated_at' => date('Y-m-d H:i:s')];
		if ($errors) return [$id, $errors];
		if ($id === '') {
			$id = nu_id();
			$db->insert(self::T, [self::T . '_id' => $id, 'created_by' => self::who(), 'created_at' => $row['updated_at'], 'runs' => 0] + $row);
			NuLog::write('export_saved', self::T, $id, ['code' => $code, 'name' => $name, 'new' => true]);
		} else {
			$db->update(self::T, $row, self::T . '_id', $id);
			NuLog::write('export_saved', self::T, $id, ['code' => $code, 'name' => $name]);
		}
		return [$id, []];
	}

	public static function delete(string $id): void
	{
		$e = self::get($id);
		if (!$e) return;
		nu_db()->delete(self::T, self::T . '_id', $e['id']);
		NuLog::write('export_deleted', self::T, $e['id'], ['code' => $e['code'], 'name' => $e['name']]);
	}

	public static function duplicate(string $id): string
	{
		$e = self::get($id);
		if (!$e) return '';
		$code = $e['code'] . '_copy';
		$n = 2;
		while (self::get($code)) $code = $e['code'] . '_copy' . $n++;
		[$newId] = self::save(['name' => $e['name'] . ' (2)', 'code' => $code, 'description' => $e['description'], 'format' => $e['format'], 'filename' => $e['filename'],
			'options' => $e['options'], 'datasets' => $e['datasets']]);
		return $newId;
	}

	private static function codeFrom(string $name): string
	{
		$c = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)), '_'));
		return $c !== '' ? substr($c, 0, 50) : 'export_' . substr(nu_id(), 0, 6);
	}

	private static function who(): string
	{
		$u = NuAuth::user();
		return $u ? (string)($u['login'] ?? $u['id'] ?? '') : 'cli';
	}

	/** What is wrong with a dataset ('' = fine): source present, SQL reading only. */
	public static function checkDataset(array $d): string
	{
		if ($d['source'] === 'table') {
			if ($d['table'] === '') return t('dxp_err_table');
			return '';
		}
		$sql = self::sqlOf($d);
		if (trim($sql) === '') return t('dxp_err_sql_empty');
		if (!NuDemo::readOnlySql(self::placeholdersToParams($sql)[0])) return t('sqb_err_select_only');
		return '';
	}

	/** The SQL of a query dataset (typed, or the SQL builder query's). */
	public static function sqlOf(array $d): string
	{
		if ($d['source'] === 'select') {
			$q = NuSqlSelect::available() ? NuSqlSelect::load($d['select']) : null;
			return $q ? (string)$q['sse_sql'] : '';
		}
		return (string)$d['sql'];
	}

	// ================================================================= JSON file (10a)

	public static function toJson(array $e): string
	{
		return (string)json_encode(['format' => self::FORMAT_VERSION, 'nunext' => NU_VERSION, 'export' => ['code' => $e['code'], 'name' => $e['name'], 'description' => $e['description'],
			'format' => $e['format'], 'filename' => $e['filename'], 'options' => $e['options'], 'datasets' => $e['datasets']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/** Reads a JSON file into a save() input (code made unique). @return array{0: ?array, 1: string} [input, error] */
	public static function fromJson(string $json): array
	{
		$j = json_decode($json, true);
		if (!is_array($j) || ($j['format'] ?? '') !== self::FORMAT_VERSION || !is_array($j['export'] ?? null)) return [null, t('dxp_err_json')];
		$e = $j['export'];
		$code = strtolower((string)preg_replace('/[^a-z0-9_]+/i', '_', (string)($e['code'] ?? '')));
		if ($code !== '' && self::get($code)) { $n = 2; while (self::get($code . '_' . $n)) $n++; $code .= '_' . $n; }
		return [['name' => (string)($e['name'] ?? ''), 'code' => $code, 'description' => (string)($e['description'] ?? ''), 'format' => (string)($e['format'] ?? 'xlsx'),
			'filename' => (string)($e['filename'] ?? ''), 'options' => is_array($e['options'] ?? null) ? $e['options'] : [], 'datasets' => $e['datasets'] ?? []], ''];
	}

	// ================================================================= parameters (3a)

	/** Placeholder names a user must give for this export (every #name# of its SQL that the server does not fill). */
	public static function params(array $e): array
	{
		$out = [];
		foreach ($e['datasets'] as $d) {
			if ($d['source'] === 'table') continue;
			foreach (self::placeholders(self::sqlOf($d)) as $p) if (!in_array(strtoupper($p), self::BUILTIN, true) && !in_array($p, $out, true)) $out[] = $p;
		}
		return $out;
	}

	private static function placeholders(string $sql): array
	{
		return array_values(array_unique(self::placeholdersToParams($sql)[1]));
	}

	/** #name# (quoted or not) → ? with the names in order. @return array{0: string, 1: list<string>} */
	private static function placeholdersToParams(string $sql): array
	{
		$names = [];
		// string literals are walked separately: a literal that IS a placeholder ('#name#') becomes ?, a placeholder inside a longer literal
		// ('%#x#%') stays text; outside literals a bare #name# becomes ?
		$parts = preg_split("/('(?:[^']|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) return [$sql, []];
		foreach ($parts as $k => $part) {
			if ($k % 2 === 1) {
				if (preg_match("/^'#([A-Za-z_][A-Za-z0-9_]*)#'$/", $part, $m)) { $names[] = $m[1]; $parts[$k] = '?'; }
				continue;
			}
			$parts[$k] = (string)preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)#/', static function ($m) use (&$names) { $names[] = $m[1]; return '?'; }, $part);
		}
		return [implode('', $parts), $names];
	}

	// ================================================================= the query of a dataset

	/**
	 * SQL + parameters of a dataset. @param array<string, string> $values the parameters given for the run.
	 * @return array{0: string, 1: list<mixed>, 2: list<array{col: string, head: string, fmt: string, kind: string}>|null} [sql, params, columns (table source) or null]
	 */
	public static function query(array $d, array $values = []): array
	{
		$db = nu_db();
		if ($d['source'] === 'table') {
			$all = self::columns($d['table']);
			$pk = self::primaryKey($d['table']);
			$picked = [];
			foreach ($d['columns'] as $c) if (isset($all[$c['col']])) $picked[$c['col']] = $c;
			if (!$picked) foreach ($all as $n => $c) if (!$c['secret']) $picked[$n] = ['col' => $n, 'head' => '', 'fmt' => ''];
			if ($d['reimport'] && $pk !== '' && isset($all[$pk])) $picked = [$pk => $picked[$pk] ?? ['col' => $pk, 'head' => '', 'fmt' => '']] + $picked;   // the key first
			$cols = [];
			$select = [];
			foreach ($picked as $n => $c) {
				$cols[] = ['col' => $n, 'head' => $d['reimport'] || $c['head'] === '' ? $n : $c['head'], 'fmt' => $c['fmt'], 'kind' => $all[$n]['kind']];
				$select[] = $db->ident($n);
			}
			[$where, $params] = self::where($d['where'], $all);
			$order = [];
			foreach ($d['sort'] as [$col, $dir]) if (isset($all[$col])) $order[] = $db->ident($col) . ($dir === 'desc' ? ' DESC' : ' ASC');
			$sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $db->ident($d['table']) . ($where !== '' ? ' WHERE ' . $where : '') . ($order ? ' ORDER BY ' . implode(', ', $order) : '');
			return [$sql, $params, $cols];
		}
		$sql = rtrim(trim(self::sqlOf($d)), "; \n\r\t");
		[$sql, $names] = self::placeholdersToParams($sql);
		if (!NuDemo::readOnlySql($sql)) throw new RuntimeException(t('sqb_err_select_only'));
		if (NuDemo::active() && NuDemo::touchesHidden($sql)) throw new RuntimeException(t('demo_not_available'));
		$builtin = NuHash::plain();
		$params = [];
		foreach ($names as $n) {
			if (array_key_exists($n, $values)) $params[] = (string)$values[$n];
			elseif (in_array(strtoupper($n), self::BUILTIN, true)) $params[] = (string)($builtin[strtoupper($n)] ?? $builtin[$n] ?? '');
			else throw new RuntimeException(t('dxp_err_param', $n));
		}
		return [$sql, $params, null];
	}

	/** WHERE of a table dataset's conditions (values as parameters). @return array{0: string, 1: list<mixed>} */
	private static function where(array $w, array $cols): array
	{
		$db = nu_db();
		$parts = []; $params = [];
		foreach ($w['c'] as [$col, $op, $v, $v2]) {
			if (!isset($cols[$col])) continue;
			$kind = $cols[$col]['kind'];
			if (!in_array($op, self::OPS_BY_KIND[$kind] ?? self::OPS_BY_KIND['text'], true)) continue;
			// a condition without its value is not applied (review D4: an empty row filtered out every row) – "is empty" is the operator for that
			if (!in_array($op, ['empty', 'nempty', 'true', 'false'], true) && trim($v) === '') continue;
			if ($op === 'between' && trim($v2) === '') $op = 'ge';
			$e = $db->ident($col);
			$num = in_array($kind, ['int', 'decimal'], true);
			$val = static fn(string $x): mixed => $num ? (is_numeric($x) ? $x + 0 : 0) : $x;
			switch ($op) {
				case 'eq': $parts[] = "$e = ?"; $params[] = $val($v); break;
				case 'neq': $parts[] = "($e <> ? OR $e IS NULL)"; $params[] = $val($v); break;
				case 'lt': $parts[] = "$e < ?"; $params[] = $val($v); break;
				case 'le': $parts[] = "$e <= ?"; $params[] = $val($v); break;
				case 'gt': $parts[] = "$e > ?"; $params[] = $val($v); break;
				case 'ge': $parts[] = "$e >= ?"; $params[] = $val($v); break;
				case 'between': $parts[] = "$e BETWEEN ? AND ?"; $params[] = $val($v); $params[] = $val($v2); break;
				case 'contains': $parts[] = "$e LIKE ?"; $params[] = '%' . $db->likeRaw($v) . '%'; break;
				case 'ncontains': $parts[] = "($e NOT LIKE ? OR $e IS NULL)"; $params[] = '%' . $db->likeRaw($v) . '%'; break;
				case 'starts': $parts[] = "$e LIKE ?"; $params[] = $db->likeRaw($v) . '%'; break;
				case 'ends': $parts[] = "$e LIKE ?"; $params[] = '%' . $db->likeRaw($v); break;
				case 'in':
					$list = array_values(array_filter(array_map('trim', explode(',', $v)), static fn($x) => $x !== ''));
					if (!$list) break;
					$parts[] = "$e IN (" . implode(', ', array_fill(0, count($list), '?')) . ')';
					foreach ($list as $x) $params[] = $val($x);
					break;
				case 'empty': $parts[] = $kind === 'text' ? "($e IS NULL OR $e = '')" : "$e IS NULL"; break;
				case 'nempty': $parts[] = $kind === 'text' ? "($e IS NOT NULL AND $e <> '')" : "$e IS NOT NULL"; break;
				case 'true': $parts[] = "$e = 1"; break;
				case 'false': $parts[] = "($e = 0 OR $e IS NULL)"; break;
			}
		}
		return [$parts ? '(' . implode($w['m'] === 'any' ? ' OR ' : ' AND ', $parts) . ')' : '', $params];
	}

	/**
	 * Runs a dataset's query and hands every row (typed values) to $row; returns the column list of the result.
	 * @param callable(array<int, mixed>, array): void|null $row  called with (values, columns); null = the first rows only (preview, $limit)
	 * @return array{cols: list<array{col: string, head: string, fmt: string, kind: string}>, rows: int, sample: array}
	 */
	public static function fetch(array $d, array $values, ?callable $row, int $limit = 0): array
	{
		[$sql, $params, $tableCols] = self::query($d, $values);
		$db = nu_db();
		return $db->readOnly(static function (NuDB $db) use ($sql, $params, $tableCols, $d, $row, $limit): array {
			$pdo = $db->pdo();
			$buffered = null;
			if ($db->type === 'mysql') { try { $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); } catch (Throwable) { $buffered = null; } }
			$st = null;
			try {
				$st = $db->run($sql, $params);
				$cols = $tableCols;
				if ($cols === null) {                                   // query: columns from the result, picked / renamed by the dataset
					$meta = [];
					for ($i = 0; $i < $st->columnCount(); $i++) { $m = $st->getColumnMeta($i) ?: []; $meta[] = ['name' => (string)($m['name'] ?? ('c' . $i)), 'kind' => self::kindOfMeta($m)]; }
					$byName = [];
					foreach ($meta as $i => $m) $byName[$m['name']] = $i;
					$pick = [];
					foreach ($d['columns'] as $c) if (isset($byName[$c['col']])) $pick[] = ['col' => $c['col'], 'head' => $c['head'] !== '' ? $c['head'] : $c['col'], 'fmt' => $c['fmt'], 'kind' => $meta[$byName[$c['col']]]['kind'], 'i' => $byName[$c['col']]];
					if (!$pick) foreach ($meta as $i => $m) $pick[] = ['col' => $m['name'], 'head' => $m['name'], 'fmt' => '', 'kind' => $m['kind'], 'i' => $i];
					$cols = $pick;
				} else {
					foreach ($cols as $i => &$c) $c['i'] = $i;
					unset($c);
				}
				$kinds = array_column($cols, 'kind');
				$idx = array_column($cols, 'i');
				$n = 0;
				$sample = [];
				while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
					$n++;
					$vals = [];
					foreach ($idx as $k => $i) $vals[] = self::typed($r[$i] ?? null, $kinds[$k]);
					if ($row) $row($vals, $cols);
					if ($limit > 0) { $sample[] = $vals; if ($n >= $limit) break; }
				}
				$st->closeCursor();
				foreach ($cols as &$c) unset($c['i']);
				unset($c);
				return ['cols' => $cols, 'rows' => $n, 'sample' => $sample];
			} finally {
				if ($st) { try { $st->closeCursor(); } catch (Throwable) {} }   // an open unbuffered cursor would block the ROLLBACK of readOnly()
				if ($buffered !== null) { try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered); } catch (Throwable) {} }
			}
		}, self::QUERY_SECONDS);
	}

	/** A database value as a typed PHP value: int / float / bool / DateTimeImmutable / string / null. */
	public static function typed(mixed $v, string $kind): mixed
	{
		if ($v === null) return null;
		if (is_bool($v)) return $v;
		$s = is_string($v) ? $v : (string)$v;
		switch ($kind) {
			case 'bool':
				if ($s === '' ) return null;
				return in_array(strtolower($s), ['1', 'true', 'yes', 'y', 't'], true) ? true : (in_array(strtolower($s), ['0', 'false', 'no', 'n', 'f'], true) ? false : $s);
			case 'int':
				if (preg_match('/^-?\d{1,15}$/', $s)) return (int)$s;
				return $s;                                         // longer: text (Excel's 15 digits)
			case 'decimal':
				if (is_int($v) || is_float($v)) return $v;
				if (preg_match('/^-?\d{1,15}(\.\d+)?([eE][-+]?\d+)?$/', $s)) return str_contains($s, '.') || str_contains(strtolower($s), 'e') ? (float)$s : (int)$s;
				return $s;
			case 'date':
			case 'datetime':
				if ($s === '' || str_starts_with($s, '0000-00-00')) return null;
				try {
					$d = new DateTimeImmutable($s);
					if ($kind === 'date') $d = $d->setTime(0, 0, 0);
					return $d;
				} catch (Throwable) {
					return $s;
				}
			case 'time':
				return $s;
			default:
				return $s;
		}
	}

	// ================================================================= running

	/** The file name of a run ('' → <code>_<date>.<ext>; placeholders {code} {name} {date} {time}). */
	public static function fileName(array $e, string $ext): string
	{
		$n = $e['filename'] !== '' ? $e['filename'] : '{code}_{date}';
		$n = strtr($n, ['{code}' => $e['code'], '{name}' => $e['name'], '{date}' => date('Y-m-d'), '{time}' => date('His'), '{datetime}' => date('Y-m-d_His')]);
		$n = trim((string)preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '_', mb_scrub($n, 'UTF-8')), ' ._');
		$n = (string)preg_replace('/\.(xlsx|csv|zip)$/i', '', $n);
		return mb_substr($n !== '' ? $n : 'export', 0, 120) . '.' . $ext;
	}

	/** Default number format of a kind for Excel ('' = General). */
	private static function xlsxFormat(array $c): string
	{
		if ($c['fmt'] !== '') return $c['fmt'] === 'text' ? '@' : $c['fmt'];
		return match ($c['kind']) { 'date' => 'yyyy-mm-dd', 'datetime' => 'yyyy-mm-dd hh:mm', 'text' => '@', default => '' };
	}

	/**
	 * Runs an export: builds the file in a temp folder and returns [path, download name, mime, stats]. The caller sends or stores it and
	 * unlinks it. Stats: datasets [{name, rows}], ms.
	 * @param array<string, string> $values parameters
	 * @return array{path: string, name: string, mime: string, datasets: list<array{name: string, rows: int}>, ms: int}
	 */
	public static function build(array $e, array $values = []): array
	{
		$t0 = microtime(true);
		nu_time_limit(0);
		$stats = [];
		$o = $e['options']['csv'];
		$tmp = tempnam(sys_get_temp_dir(), 'nudxp');
		if ($tmp === false) throw new RuntimeException('no temporary file');
		try {
			if ($e['format'] === 'xlsx') {
				$w = new NuXlsxStream($e['name']);
				foreach ($e['datasets'] as $i => $d) {
					$name = $d['name'] !== '' ? $d['name'] : ($d['source'] === 'table' ? $d['table'] : t('dxp_dataset') . ' ' . ($i + 1));
					$started = false;
					$res = self::fetch($d, $values, static function (array $vals, array $cols) use (&$started, $w, $name) {
						if (!$started) { $w->sheet($name, array_column($cols, 'head'), array_map([self::class, 'xlsxFormat'], $cols)); $started = true; }
						$w->row($vals);
					});
					if (!$started) $w->sheet($name, array_column($res['cols'], 'head'), array_map([self::class, 'xlsxFormat'], $res['cols']));
					$stats[] = ['name' => $name, 'rows' => $res['rows']];
				}
				$w->save($tmp);
				$mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
				$ext = 'xlsx';
			} elseif (count($e['datasets']) > 1) {                      // several CSV files → ZIP
				if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive missing');
				$zip = new ZipArchive();
				if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('cannot create the zip');
				$files = [];
				foreach ($e['datasets'] as $i => $d) {
					$name = $d['name'] !== '' ? $d['name'] : ($d['source'] === 'table' ? $d['table'] : t('dxp_dataset') . ' ' . ($i + 1));
					$f = tempnam(sys_get_temp_dir(), 'nudxc');
					if ($f === false) throw new RuntimeException('no temporary file');
					$files[] = $f;
					$rows = self::writeCsv($d, $values, $o, $f);
					$entry = trim((string)preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '_', $name), ' ._') ?: ('dataset' . ($i + 1));
					$zip->addFile($f, $entry . '.csv');
					$stats[] = ['name' => $name, 'rows' => $rows];
				}
				$zip->close();
				foreach ($files as $f) @unlink($f);
				$mime = 'application/zip';
				$ext = 'zip';
			} else {
				$d = $e['datasets'][0];
				$rows = self::writeCsv($d, $values, $o, $tmp);
				$stats[] = ['name' => $d['name'] !== '' ? $d['name'] : $d['table'], 'rows' => $rows];
				$mime = 'text/csv; charset=utf-8';
				$ext = 'csv';
			}
		} catch (Throwable $x) {
			@unlink($tmp);
			throw $x;
		}
		return ['path' => $tmp, 'name' => self::fileName($e, $ext), 'mime' => $mime, 'datasets' => $stats, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
	}

	/** Writes one dataset as CSV into $file; returns the row count. */
	private static function writeCsv(array $d, array $values, array $o, string $file): int
	{
		$fh = fopen($file, 'w');
		if ($fh === false) throw new RuntimeException('cannot write ' . $file);
		$sep = self::CSV_SEPS[$o['sep']];
		$eol = $o['eol'] === 'lf' ? "\n" : "\r\n";
		if ($o['bom']) fwrite($fh, "\xEF\xBB\xBF");
		$started = false;
		$text = static function (mixed $v, array $c) use ($o): string {
			if ($v === null) return $o['null'];
			if (is_bool($v)) return match ($o['bool']) { 'true/false' => $v ? 'true' : 'false', 'yes/no' => $v ? 'yes' : 'no', default => $v ? '1' : '0' };
			if ($v instanceof DateTimeInterface) return $v->format($c['kind'] === 'date' ? $o['date'] : $o['datetime']);
			if (is_float($v)) { $s = rtrim(rtrim(sprintf('%.12F', $v), '0'), '.'); return $o['decimal'] === ',' ? str_replace('.', ',', $s) : $s; }
			if (is_int($v)) return (string)$v;
			$s = (string)$v;
			if ($o['protect'] && $s !== '' && strpbrk(ltrim($s, " \t\r\n\x0B\x00")[0] ?? '', '=+-@') !== false && !is_numeric(trim($s))) $s = "'" . $s;   // no formulas in Excel
			return $s;
		};
		$res = self::fetch($d, $values, static function (array $vals, array $cols) use (&$started, $fh, $sep, $eol, $o, $text) {
			if (!$started) { if ($o['head']) fputcsv($fh, array_column($cols, 'head'), $sep, '"', '', $eol); $started = true; }
			$line = [];
			foreach ($vals as $k => $v) $line[] = $text($v, $cols[$k]);
			fputcsv($fh, $line, $sep, '"', '', $eol);
		});
		if (!$started && $o['head']) fputcsv($fh, array_column($res['cols'], 'head'), $sep, '"', '', $eol);
		fclose($fh);
		return $res['rows'];
	}

	/** A preview of a dataset: columns + the first rows as display text. */
	public static function preview(array $d, array $values = [], int $limit = self::PREVIEW_ROWS): array
	{
		$res = self::fetch($d, $values, null, $limit);
		$rows = [];
		foreach ($res['sample'] as $r) {
			$line = [];
			foreach ($r as $k => $v) {
				$c = $res['cols'][$k];
				$line[] = $v === null ? null : ($v instanceof DateTimeInterface ? $v->format($c['kind'] === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s') : (is_bool($v) ? ($v ? 'true' : 'false') : mb_substr((string)$v, 0, 200)));
			}
			$rows[] = $line;
		}
		return ['cols' => $res['cols'], 'rows' => $rows];
	}

	/** Row count of a dataset (COUNT(*) around the query). */
	public static function count(array $d, array $values = []): ?int
	{
		try {
			[$sql, $params] = self::query($d, $values);
			if ($d['source'] === 'table') $sql = (string)preg_replace('/\s+ORDER BY\s[^)]*$/i', '', $sql);   // SQL Server: no ORDER BY inside a derived table
			return (int)nu_db()->readOnly(static fn(NuDB $db) => $db->value('SELECT COUNT(*) FROM (' . $sql . ') nuX', $params), 60);
		} catch (Throwable) {
			return null;
		}
	}

	/** Runs, logs and counts; returns build()'s result. */
	public static function run(array $e, array $values = [], string $via = 'page'): array
	{
		$r = self::build($e, $values);
		try {
			nu_db()->run('UPDATE ' . self::T . ' SET runs = runs + 1, last_run_at = ? WHERE ' . self::T . '_id = ?', [date('Y-m-d H:i:s'), $e['id']]);
		} catch (Throwable) {
			// counting is not essential
		}
		NuLog::write('export_run', self::T, $e['id'], ['code' => $e['code'], 'format' => $e['format'], 'via' => $via, 'datasets' => $r['datasets'], 'ms' => $r['ms'],
			'params' => array_keys($values)]);
		return $r;
	}

	/** Sends a built file as a download and ends the request. */
	public static function sendFile(string $path, string $name, string $mime): never
	{
		if (function_exists('session_write_close')) session_write_close();
		while (ob_get_level()) ob_end_clean();
		$ascii = (string)preg_replace('/[^A-Za-z0-9_. -]+/', '_', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name));
		header('Content-Type: ' . $mime);
		header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
		header('Content-Length: ' . filesize($path));
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		readfile($path);
		@unlink($path);                                             // exit skips finally blocks: the temp file goes here
		exit;
	}
}
