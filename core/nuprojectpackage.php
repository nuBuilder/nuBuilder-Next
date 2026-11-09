<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Project packages (0.8.1035): a project with its items as one JSON file ("nunext-project-1") – to move a piece of the application
 * from a test installation to the live one, or to keep a copy.
 *  - export(): the project (code, name, description, notes, to-dos without the assignee), its member list and the rows of every member:
 *    forms (NuFormSnapshot: tabs, objects, events, list columns, hooks, SQL builder rows of the form), procedures, reports, SQL builder
 *    queries (+ clauses), e-mail templates, code snippets, help pages (nunext-help-1), tables / views (MySQL: CREATE statement without
 *    DEFINER; rows only with $withData, at most MAX_ROWS per table – binary values as {"b64": …}).
 *  - check(): what the import would do per item – new / same / different / conflict (a code used by another record) / exists (tables).
 *  - import(): the chosen actions (add / replace / skip). A replaced form is put into its history first. New tables are created first,
 *    with their rows; existing tables are never changed. Access rights are not part of a package.
 */
final class NuProjectPackage
{
	public const FORMAT = 'nunext-project-1';
	public const MAX_ROWS = 20000;
	/** Simple row kinds: kind => [table, id column, code column]. */
	private const ROWS = [
		'procedure' => ['zzzzsys_php', 'zzzzsys_php_id', 'sph_code'],
		'report' => ['zzzzsys_report', 'zzzzsys_report_id', 'sre_code'],
		'emailtemplate' => ['zzzzsys_email_template', 'zzzzsys_email_template_id', 'emt_code'],
		'snippet' => ['zzzzsys_code_snippet', 'zzzzsys_code_snippet_id', 'cot_code'],
	];

	// ------------------------------------------------------------------ export

	public static function export(string $projectId, bool $withData): array
	{
		$p = NuProject::load($projectId) ?? throw new RuntimeException(t('record_not_found'));
		$db = nu_db();
		$pkg = ['format' => self::FORMAT, 'version' => NU_VERSION, 'exported' => date('c'), 'db' => $db->type, 'by' => (string)(NuAuth::currentRaw()['login'] ?? ''),
			'project' => ['code' => (string)$p['code'], 'name' => (string)$p['name'], 'description' => (string)$p['description']],
			'notes' => array_map(static fn($n) => ['title' => (string)$n['title'], 'body' => (string)$n['body'], 'pinned' => (string)$n['pinned'] === '1'], NuProject::notes($projectId)),
			'tasks' => array_map(static fn($k) => ['title' => (string)$k['title'], 'body' => (string)$k['body'], 'status' => (string)$k['status'], 'due' => substr((string)$k['due'], 0, 10), 'item' => (string)$k['item']], NuProject::tasks($projectId)),
			'items' => [], 'forms' => [], 'procedure' => [], 'report' => [], 'select' => [], 'emailtemplate' => [], 'snippet' => [], 'help' => [], 'help_ids' => [], 'tables' => [], 'left_out' => []];
		$helpCodes = [];
		foreach (NuProject::items($projectId) as $it) {
			if ($it['missing']) continue;
			$pkg['items'][] = [$it['kind'], $it['ref']];
			$k = $it['kind'];
			if ($k === 'form') { if ($s = NuFormSnapshot::capture($it['ref'])) $pkg['forms'][] = $s; }
			elseif (isset(self::ROWS[$k])) { if ($r = $db->one('SELECT * FROM ' . self::ROWS[$k][0] . ' WHERE ' . self::ROWS[$k][1] . ' = ?', [$it['ref']])) $pkg[$k][] = $r; }
			elseif ($k === 'select') {
				if ($r = $db->one('SELECT * FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$it['ref']])) $pkg['select'][] = ['row' => $r, 'clauses' => $db->all('SELECT * FROM zzzzsys_select_clause WHERE ssc_zzzzsys_select_id = ?', [$it['ref']])];
			} elseif ($k === 'helppage') {
				if ($c = $db->value('SELECT code FROM ' . NuUserHelp::T . ' WHERE zzzzsys_nunext_help_id = ?', [$it['ref']])) { $helpCodes[(string)$c] = true; $pkg['help_ids'][$it['ref']] = (string)$c; }
			} elseif ($k === 'table') {
				$t = self::tableExport($it['ref'], $withData);
				if ($t) $pkg['tables'][] = $t; else $pkg['left_out'][] = ['table', $it['ref']];
			}
		}
		if ($helpCodes) {
			$all = json_decode(NuUserHelp::export(), true);
			$pkg['help'] = array_values(array_filter((array)($all['pages'] ?? []), static fn($x) => isset($helpCodes[(string)($x['code'] ?? '')])));
		}
		NuLog::write('project_exported', NuProject::T, $projectId, ['code' => (string)$p['code'], 'items' => count($pkg['items']), 'data' => $withData]);
		return $pkg;
	}

	/** One table / view: CREATE statement (MySQL), column names and – with $withData – its rows. null on SQL Server. */
	private static function tableExport(string $name, bool $withData): ?array
	{
		$db = nu_db();
		if ($db->type !== 'mysql') return null;
		$q = '`' . str_replace('`', '``', $name) . '`';
		$isView = (string)$db->value('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]) === 'VIEW';
		$row = $db->pdo()->query(($isView ? 'SHOW CREATE VIEW ' : 'SHOW CREATE TABLE ') . $q)->fetch(PDO::FETCH_NUM);
		$create = self::noDefiner((string)($row[1] ?? ''));
		if ($create === '') return null;
		$t = ['name' => $name, 'type' => $isView ? 'view' : 'table', 'create' => $create];
		if ($withData && !$isView) {
			$cols = array_column($db->all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE ? ORDER BY ORDINAL_POSITION', [$name, '%GENERATED%']), 'COLUMN_NAME');
			$total = (int)$db->value("SELECT COUNT(*) FROM $q");
			$t['columns'] = array_map('strval', $cols);
			$t['rows'] = [];
			if ($total > self::MAX_ROWS) { $t['rows_left_out'] = $total; return $t; }
			$sel = implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', $cols));
			foreach ($db->pdo()->query("SELECT $sel FROM $q")->fetchAll(PDO::FETCH_NUM) as $r) {
				$t['rows'][] = array_map(static fn($v) => is_string($v) && !mb_check_encoding($v, 'UTF-8') ? ['b64' => base64_encode($v)] : $v, $r);
			}
		}
		return $t;
	}

	public static function noDefiner(string $sql): string
	{
		return (string)(preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+?)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql) ?? $sql);
	}

	public static function json(array $pkg): string
	{
		return (string)json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	public static function fileName(array $pkg): string
	{
		return 'nunext-project-' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)($pkg['project']['code'] ?? 'project')) . '-' . date('Ymd') . '.json';
	}

	// ------------------------------------------------------------------ import

	/** Reads and checks a package file. @return array{0: ?array, 1: string} [package, error] */
	public static function read(string $json): array
	{
		$p = json_decode($json, true);
		if (!is_array($p) || ($p['format'] ?? '') !== self::FORMAT || !is_array($p['project'] ?? null) || !preg_match(NuProject::CODE_RE, (string)($p['project']['code'] ?? ''))) return [null, t('prj_err_package')];
		foreach (['items', 'forms', 'procedure', 'report', 'select', 'emailtemplate', 'snippet', 'help', 'tables', 'notes', 'tasks'] as $k) if (!is_array($p[$k] ?? [])) return [null, t('prj_err_package')];
		return [$p, ''];
	}

	/**
	 * What the import would do. @return list<array{key: string, kind: string, ref: string, label: string, state: string, note: string, action: string, actions: list<string>}>
	 * state: new | same | different | conflict | exists (tables) | unsupported; action = the proposal.
	 */
	public static function check(array $pkg): array
	{
		$db = nu_db();
		$out = [];
		$row = static function (string $kind, string $ref, string $label, string $state, string $note = '') use (&$out): void {
			$actions = match ($state) { 'new' => ['add', 'skip'], 'different' => ['replace', 'skip'], default => ['skip'] };
			$out[] = ['key' => $kind . '|' . $ref, 'kind' => $kind, 'ref' => $ref, 'label' => $label, 'state' => $state, 'note' => $note, 'action' => $actions[0], 'actions' => $actions];
		};
		foreach ($pkg['tables'] ?? [] as $t) {
			$name = (string)($t['name'] ?? '');
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) || str_starts_with(strtolower($name), 'zzzzsys_')) continue;
			if ($db->type !== 'mysql') { $row('table', $name, $name, 'unsupported', t('prj_imp_mysql_only')); continue; }
			$exists = $db->tableExists($name);
			$n = isset($t['rows']) ? count((array)$t['rows']) : 0;
			$note = ($t['type'] ?? '') === 'view' ? t('prj_imp_view') : ($n ? t('prj_imp_rows', $n) : (isset($t['rows_left_out']) ? t('prj_imp_rows_left', (int)$t['rows_left_out']) : t('prj_imp_structure')));
			$row('table', $name, $name, $exists ? 'exists' : 'new', $exists ? t('prj_imp_table_kept') : $note);
		}
		foreach ($pkg['forms'] ?? [] as $s) {
			$f = (array)($s['form'] ?? []);
			$id = (string)($f['zzzzsys_form_id'] ?? '');
			if ($id === '' || str_starts_with($id, 'nu')) continue;
			$label = trim((string)($f['sfo_description'] ?? '')) . ' (' . ($f['sfo_code'] ?? '') . ')';
			$cur = NuFormSnapshot::capture($id);
			if ($cur) { $row('form', $id, $label, NuFormSnapshot::hash($cur) === NuFormSnapshot::hash($s) ? 'same' : 'different', NuFormDraft::ready() && NuFormDraft::openFor($id) ? t('prj_imp_draft_open') : ''); continue; }
			$clash = $db->value('SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = ?', [(string)($f['sfo_code'] ?? '')]);
			$row('form', $id, $label, $clash ? 'conflict' : 'new', $clash ? t('prj_imp_code_used') : '');
		}
		foreach (self::ROWS as $kind => [$tab, $idc, $codec]) {
			foreach ($pkg[$kind] ?? [] as $r) self::checkRow($row, $kind, $tab, $idc, $codec, (array)$r);
		}
		foreach ($pkg['select'] ?? [] as $x) self::checkRow($row, 'select', 'zzzzsys_select', 'zzzzsys_select_id', 'sse_code', (array)($x['row'] ?? []), (array)($x['clauses'] ?? []));
		$bases = [];
		foreach ($pkg['help'] ?? [] as $h) if ((string)($h['lang'] ?? '') === '') $bases[(string)$h['code']] = (string)($h['title'] ?? $h['code']);
		foreach ($bases as $code => $title) {
			$exists = NuUserHelp::ready() && $db->value('SELECT COUNT(*) FROM ' . NuUserHelp::T . " WHERE code = ? AND lang = ''", [$code]);
			$row('helppage', $code, $title . ' (' . $code . ')', $exists ? 'different' : 'new', '');
		}
		$p = NuProject::byCode((string)$pkg['project']['code']);
		$row('project', (string)$pkg['project']['code'], (string)$pkg['project']['name'], $p ? 'different' : 'new', $p ? t('prj_imp_merge') : t('prj_imp_new_project', count($pkg['notes'] ?? []), count($pkg['tasks'] ?? [])));
		return $out;
	}

	private static function checkRow(callable $row, string $kind, string $tab, string $idc, string $codec, array $r, array $clauses = []): void
	{
		$db = nu_db();
		$id = (string)($r[$idc] ?? '');
		if ($id === '' || !$db->tableExists($tab)) return;
		$label = trim((string)($r[self::labelCol($kind)] ?? '')) . ' (' . ($r[$codec] ?? '') . ')';
		$cur = $db->one("SELECT * FROM $tab WHERE $idc = ?", [$id]);
		if ($cur) {
			$same = self::canon(NuFormSnapshot::only($tab, $cur)) === self::canon(NuFormSnapshot::only($tab, $r));
			if ($same && $kind === 'select') $same = self::canonList($db->all('SELECT * FROM zzzzsys_select_clause WHERE ssc_zzzzsys_select_id = ?', [$id])) === self::canonList($clauses);
			$row($kind, $id, $label, $same ? 'same' : 'different', '');
			return;
		}
		$code = (string)($r[$codec] ?? '');
		$clash = $code !== '' && $db->value("SELECT $idc FROM $tab WHERE $codec = ?", [$code]);
		$row($kind, $id, $label, $clash ? 'conflict' : 'new', $clash ? t('prj_imp_code_used') : '');
	}

	private static function labelCol(string $kind): string
	{
		return NuProject::KINDS[$kind][2] ?? '';
	}

	private static function canon(array $r): string
	{
		ksort($r);
		return (string)json_encode(array_map(static fn($v) => $v === null ? '' : (string)$v, $r));
	}

	private static function canonList(array $rows): string
	{
		$c = array_map(static fn($r) => self::canon((array)$r), $rows);
		sort($c);
		return implode("\n", $c);
	}

	/**
	 * Runs the import. $actions: key => add | replace | skip (missing keys: the proposal of check()). Only the globeadmin.
	 * @return array{done: array<string, int>, skipped: int, errors: list<string>, project: string}
	 */
	public static function import(array $pkg, array $actions): array
	{
		$u = NuAuth::user();
		if (!$u || empty($u['globeadmin'])) throw new RuntimeException(t('uf_edit_globeadmin'));
		nu_time_limit(0);
		$db = nu_db();
		$plan = [];
		foreach (self::check($pkg) as $c) {
			$a = (string)($actions[$c['key']] ?? $c['action']);
			$plan[$c['key']] = in_array($a, $c['actions'], true) ? $a : 'skip';
		}
		$want = static fn(string $kind, string $ref): string => $plan[$kind . '|' . $ref] ?? 'skip';
		$res = ['done' => [], 'skipped' => 0, 'errors' => [], 'project' => ''];
		$ok = static function (string $kind) use (&$res): void { $res['done'][$kind] = ($res['done'][$kind] ?? 0) + 1; };
		// 1. tables (views after the tables they may read)
		$tables = array_values(array_filter((array)($pkg['tables'] ?? []), 'is_array'));
		usort($tables, static fn($a, $b) => (($a['type'] ?? '') === 'view') <=> (($b['type'] ?? '') === 'view'));
		foreach ($tables as $t) {
			$name = (string)($t['name'] ?? '');
			if ($want('table', $name) !== 'add') { $res['skipped']++; continue; }
			try {
				$create = self::noDefiner((string)$t['create']);
				if (!preg_match('/^CREATE\s+(TABLE\s+`?' . preg_quote($name, '/') . '`?\s*\(|(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(SQL\s+SECURITY\s+\w+\s+)?VIEW\s+`?' . preg_quote($name, '/') . '`?\s+AS\s)/i', $create)) throw new RuntimeException(t('prj_err_create'));
				$db->exec($create);
				$rows = (array)($t['rows'] ?? []);
				$cols = array_map('strval', (array)($t['columns'] ?? []));
				if ($rows && $cols) {
					$sql = 'INSERT INTO `' . str_replace('`', '``', $name) . '` (' . implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
					$st = $db->pdo()->prepare($sql);
					$db->pdo()->beginTransaction();
					try {
						foreach ($rows as $r) $st->execute(array_map(static fn($v) => is_array($v) && isset($v['b64']) ? base64_decode((string)$v['b64']) : $v, (array)$r));
						$db->pdo()->commit();
					} catch (Throwable $e) {
						if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
						throw $e;
					}
				}
				NuDB::forgetColumns('');
				$ok('table');
			} catch (Throwable $e) {
				$res['errors'][] = t('prj_kind_table') . ' ' . $name . ': ' . $e->getMessage();
			}
		}
		// 2. forms
		foreach ($pkg['forms'] ?? [] as $s) {
			$id = (string)($s['form']['zzzzsys_form_id'] ?? '');
			$a = $want('form', $id);
			if ($a === 'skip') { $res['skipped']++; continue; }
			try {
				$db->transaction(static function (NuDB $db) use ($id, $s, $a): void {
					if ($a === 'replace') NuFormHistory::snapshot($id, 'before project import');
					else $db->insert('zzzzsys_form', NuFormSnapshot::only('zzzzsys_form', (array)$s['form']));
					NuFormSnapshot::apply($db, $id, (array)$s);
				});
				NuFormMeta::forget($id);
				$ok('form');
			} catch (Throwable $e) {
				$res['errors'][] = t('prj_kind_form') . ' ' . ($s['form']['sfo_code'] ?? $id) . ': ' . $e->getMessage();
			}
		}
		// 3. rows: procedures, reports, e-mail templates, snippets; SQL builder queries with their clauses
		foreach (self::ROWS as $kind => [$tab, $idc]) {
			foreach ($pkg[$kind] ?? [] as $r) {
				$id = (string)($r[$idc] ?? '');
				$a = $want($kind, $id);
				if ($a === 'skip') { $res['skipped']++; continue; }
				try {
					$db->transaction(static function (NuDB $db) use ($tab, $idc, $id, $r): void {
						$db->delete($tab, $idc, $id);
						$db->insert($tab, NuFormSnapshot::only($tab, (array)$r));
					});
					$ok($kind);
				} catch (Throwable $e) {
					$res['errors'][] = t('prj_kind_' . $kind) . ' ' . $id . ': ' . $e->getMessage();
				}
			}
		}
		foreach ($pkg['select'] ?? [] as $x) {
			$id = (string)($x['row']['zzzzsys_select_id'] ?? '');
			$a = $want('select', $id);
			if ($a === 'skip') { $res['skipped']++; continue; }
			try {
				$db->transaction(static function (NuDB $db) use ($id, $x): void {
					$db->delete('zzzzsys_select_clause', 'ssc_zzzzsys_select_id', $id);
					$db->delete('zzzzsys_select', 'zzzzsys_select_id', $id);
					$db->insert('zzzzsys_select', NuFormSnapshot::only('zzzzsys_select', (array)$x['row']));
					foreach ((array)($x['clauses'] ?? []) as $c) $db->insert('zzzzsys_select_clause', NuFormSnapshot::only('zzzzsys_select_clause', (array)$c));
				});
				$ok('select');
			} catch (Throwable $e) {
				$res['errors'][] = t('prj_kind_select') . ' ' . $id . ': ' . $e->getMessage();
			}
		}
		// 4. help pages (nunext-help-1, the chosen codes with their translations)
		$codes = [];
		foreach ($pkg['help'] ?? [] as $h) { $c = (string)($h['code'] ?? ''); if ($want('helppage', $c) !== 'skip') $codes[$c] = true; }
		if ($codes) {
			$hr = NuUserHelp::import((string)json_encode(['format' => 'nunext-help-1', 'pages' => array_values(array_filter((array)$pkg['help'], static fn($h) => isset($codes[(string)($h['code'] ?? '')])))]), true);
			$res['done']['helppage'] = count($codes);
			foreach ($hr['errors'] as $e) $res['errors'][] = t('prj_kind_helppage') . ': ' . $e;
		}
		// 5. the project: created (with notes and to-dos) or the items added to the existing one
		$code = (string)$pkg['project']['code'];
		$p = NuProject::byCode($code);
		$new = !$p;
		if ($want('project', $code) !== 'skip') {
			if ($new) {
				[$pid, $err] = NuProject::save('', ['code' => $code, 'name' => (string)$pkg['project']['name'], 'description' => (string)($pkg['project']['description'] ?? '')]);
				if ($err) { $res['errors'][] = implode(' ', $err); $pid = ''; }
			} else $pid = (string)$p['zzzzsys_nunext_project_id'];
			if ($pid !== '') {
				$items = [];
				foreach ((array)($pkg['items'] ?? []) as $it) if (is_array($it) && count($it) === 2) $items[] = [(string)$it[0], $it[0] === 'helppage' ? (string)($db->value('SELECT zzzzsys_nunext_help_id FROM ' . NuUserHelp::T . " WHERE code = ? AND lang = ''", [self::helpCode($pkg, (string)$it[1])]) ?: $it[1]) : (string)$it[1]];
				NuProject::addItems($pid, $items);
				if ($new) {
					foreach ((array)($pkg['notes'] ?? []) as $n) NuProject::saveNote($pid, '', (array)$n);
					foreach ((array)($pkg['tasks'] ?? []) as $k) {
						[$tid] = NuProject::saveTask($pid, '', ['title' => $k['title'] ?? '', 'body' => $k['body'] ?? '', 'due' => $k['due'] ?? '', 'item' => $k['item'] ?? '']);
						if (($k['status'] ?? '') === 'done' && $tid !== '') NuProject::setTaskStatus($pid, $tid, true);
					}
				}
				$res['project'] = $pid;
			}
		}
		NuLog::write('project_imported', NuProject::T, $res['project'], ['code' => $code, 'done' => $res['done'], 'skipped' => $res['skipped'], 'errors' => count($res['errors'])]);
		return $res;
	}

	/** The help page code of an exported help item id (the id differs in another installation). */
	private static function helpCode(array $pkg, string $ref): string
	{
		foreach ((array)($pkg['help_ids'] ?? []) as $id => $code) if ((string)$id === $ref) return (string)$code;
		return $ref;
	}
}
