<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Project packages: a project with its items as one JSON file ("nunext-project-1") – to move a piece of the application
 * from a test installation to the live one, or to keep a copy.
 *  - export(): the project (code, name, description, notes, to-dos without the assignee), its member list and the rows of every member:
 *    forms (NuFormSnapshot: tabs, objects, events, list columns, hooks, SQL builder rows of the form), procedures, reports, SQL builder
 *    queries (+ clauses), e-mail templates, code snippets, help pages (nunext-help-1), tables / views (MySQL: CREATE statement without
 *    DEFINER; rows only with $withData, at most MAX_ROWS per table – binary values as {"b64": …}).
 *  - check(): what the import would do per item – new / same / different / conflict (a code used by another record) / exists (tables).
 *    Also permission items, formats, files, data exports, webhooks (without their secret), the access rights of the forms /
 *    procedures / reports (by access level code) and the translations of the forms' texts.
 *  - import(): the chosen actions (add / replace / skip). A replaced form is put into its history first. New tables are created first,
 *    with their rows; of an existing table only missing columns are added – nothing is dropped or changed. Before anything is replaced
 *    the current versions go into a restore point (a package file in the backup folder – importing it undoes the replacements).
 *  - exportAll(): the whole application as one package (no data) – NuAppFolder writes it as a folder for git.
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
		'permission' => ['zzzzsys_permission_item', 'zzzzsys_permission_item_id', 'pme_ident'],
		'format' => ['zzzzsys_format', 'zzzzsys_format_id', ''],
		'file' => ['zzzzsys_file', 'zzzzsys_file_id', 'sfi_code'],
		'dataexport' => ['zzzzsys_nunext_export', 'zzzzsys_nunext_export_id', 'code'],
		'webhook' => ['zzzzsys_nunext_webhook', 'zzzzsys_nunext_webhook_id', ''],
	];
	/** The key of a kind's rows in the package – 'format' names the package format itself. */
	private static function key(string $kind): string
	{
		return $kind === 'format' ? 'formats' : $kind;
	}
	/** Columns never exported (secrets; each installation keeps its own). */
	private const SECRET = ['zzzzsys_nunext_webhook' => ['secret']];
	/** Access rights of a kind: kind => [table, level column, object column]. */
	public const RIGHTS = ['form' => ['zzzzsys_access_form', 'slf_zzzzsys_access_id', 'slf_zzzzsys_form_id'],
		'procedure' => ['zzzzsys_access_php', 'slp_zzzzsys_access_id', 'slp_zzzzsys_php_id'],
		'report' => ['zzzzsys_access_report', 'sre_zzzzsys_access_id', 'sre_zzzzsys_report_id']];
	/** Every list of a package (read() checks them). */
	public const LISTS = ['items', 'forms', 'procedure', 'report', 'select', 'emailtemplate', 'snippet', 'permission', 'formats', 'file', 'dataexport',
		'webhook', 'help', 'tables', 'notes', 'tasks', 'rights', 'translations'];

	// ------------------------------------------------------------------ export

	public static function export(string $projectId, bool $withData): array
	{
		$p = NuProject::load($projectId) ?? throw new RuntimeException(t('record_not_found'));
		$items = [];
		foreach (NuProject::items($projectId) as $it) if (!$it['missing']) $items[] = [$it['kind'], $it['ref']];
		$pkg = self::build($items, $withData, ['code' => (string)$p['code'], 'name' => (string)$p['name'], 'description' => (string)$p['description']]);
		$pkg['notes'] = array_map(static fn($n) => ['title' => (string)$n['title'], 'body' => (string)$n['body'], 'pinned' => (string)$n['pinned'] === '1'], NuProject::notes($projectId));
		$pkg['tasks'] = array_map(static fn($k) => ['title' => (string)$k['title'], 'body' => (string)$k['body'], 'status' => (string)$k['status'],
			'due' => substr((string)$k['due'], 0, 10), 'item' => (string)$k['item']], NuProject::tasks($projectId));
		NuLog::write('project_exported', NuProject::T, $projectId, ['code' => (string)$p['code'], 'items' => count($pkg['items']), 'data' => $withData]);
		return $pkg;
	}

	/** Code of the package of the whole application (no project is created or merged on import). */
	public const APP = '_application';

	/** The whole application as a package – every form, procedure, report … of the application (not nuBuilder Forte's own), tables without data. */
	public static function exportAll(bool $withData = false): array
	{
		return self::build(self::ownItems(), $withData, ['code' => self::APP, 'name' => 'Application', 'description' => '']);
	}

	/** Every part of the application that is not nuBuilder Forte's or nuBuilder Next's own, plus the tables. @return list<array{0: string, 1: string}> */
	private static function ownItems(): array
	{
		$db = nu_db();
		$items = [];
		// nuBuilder Forte's own rows (ids nu…) and nuBuilder Next's (nx_…) are left out below, form event code goes with its form
		$q = ['form' => "SELECT zzzzsys_form_id FROM zzzzsys_form WHERE COALESCE(sfo_type, '') <> 'page'",
			'procedure' => "SELECT zzzzsys_php_id FROM zzzzsys_php WHERE COALESCE(sph_system, '') <> '1'",
			'report' => 'SELECT zzzzsys_report_id FROM zzzzsys_report',
			'select' => "SELECT zzzzsys_select_id FROM zzzzsys_select WHERE COALESCE(sse_system, '') = ''",
			'emailtemplate' => 'SELECT zzzzsys_email_template_id FROM zzzzsys_email_template',
			'snippet' => 'SELECT zzzzsys_code_snippet_id FROM zzzzsys_code_snippet',
			'helppage' => "SELECT zzzzsys_nunext_help_id FROM zzzzsys_nunext_help WHERE lang = ''",
			'permission' => "SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item WHERE pme_ident NOT LIKE 'NUNEXT%'",
			'format' => 'SELECT zzzzsys_format_id FROM zzzzsys_format',
			'file' => "SELECT zzzzsys_file_id FROM zzzzsys_file WHERE COALESCE(sfi_system, '') <> '1'",
			'dataexport' => 'SELECT zzzzsys_nunext_export_id FROM zzzzsys_nunext_export',
			'webhook' => 'SELECT zzzzsys_nunext_webhook_id FROM zzzzsys_nunext_webhook'];
		foreach ($q as $kind => $sql) {
			$tab = NuProject::KINDS[$kind][0];
			if (!$db->tableExists($tab)) continue;
			try {
				foreach ($db->all($sql) as $r) {
					$ref = (string)reset($r);
					if (str_starts_with($ref, 'nu') || str_starts_with($ref, 'nx_')) continue;
					if ($kind === 'procedure' && NuProcedure::isHook($ref)) continue;
					$items[] = [$kind, $ref];
				}
			} catch (Throwable $ig) { nu_ignored($ig, 'a column of an older nuBuilder Forte table'); }
		}
		foreach ($db->tableNames() as $n) {
			if (!str_starts_with(strtolower($n), 'zzzzsys_') && !str_starts_with($n, '___nu')) $items[] = ['table', $n];
		}
		return $items;
	}

	/** A package of the given items. @param list<array{0: string, 1: string}> $items */
	private static function build(array $items, bool $withData, array $project): array
	{
		$db = nu_db();
		$pkg = ['format' => self::FORMAT, 'version' => NU_VERSION, 'exported' => date('c'), 'db' => $db->type, 'by' => (string)(NuAuth::currentRaw()['login'] ?? ''),
			'project' => $project, 'notes' => [], 'tasks' => [], 'items' => [], 'forms' => [], 'procedure' => [], 'report' => [], 'select' => [], 'emailtemplate' => [],
			'snippet' => [], 'permission' => [], 'formats' => [], 'file' => [], 'dataexport' => [], 'webhook' => [], 'help' => [], 'help_ids' => [], 'tables' => [],
			'rights' => [], 'translations' => [], 'left_out' => []];
		$helpCodes = [];
		$formIds = [];
		foreach ($items as [$k, $ref]) {
			$pkg['items'][] = [$k, $ref];
			if ($k === 'form') { if ($s = NuFormSnapshot::capture($ref)) { $pkg['forms'][] = $s; $formIds[] = $ref; } }
			elseif (isset(self::ROWS[$k])) {
				if (!$db->tableExists(self::ROWS[$k][0])) continue;
				if ($r = $db->one('SELECT * FROM ' . self::ROWS[$k][0] . ' WHERE ' . self::ROWS[$k][1] . ' = ?', [$ref])) {
					foreach (self::SECRET[self::ROWS[$k][0]] ?? [] as $c) unset($r[$c]);
					$pkg[self::key($k)][] = $r;
				}
			}
			elseif ($k === 'select') {
				if ($r = $db->one('SELECT * FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$ref])) $pkg['select'][] = ['row' => $r,
					'clauses' => $db->all('SELECT * FROM zzzzsys_select_clause WHERE ssc_zzzzsys_select_id = ?', [$ref])];
			} elseif ($k === 'helppage') {
				if ($c = $db->value('SELECT code FROM ' . NuUserHelp::T . ' WHERE zzzzsys_nunext_help_id = ?', [$ref])) { $helpCodes[(string)$c] = true; $pkg['help_ids'][$ref] = (string)$c; }
			} elseif ($k === 'table') {
				$t = self::tableExport($ref, $withData);
				if ($t) $pkg['tables'][] = $t; else $pkg['left_out'][] = ['table', $ref];
			}
		}
		if ($helpCodes) {
			$all = json_decode(NuUserHelp::export(), true);
			$pkg['help'] = array_values(array_filter((array)($all['pages'] ?? []), static fn($x) => isset($helpCodes[(string)($x['code'] ?? '')])));
		}
		// access rights of the members, by access level code (ids differ between installations)
		$codes = NuAcl::levelCodes();
		foreach (self::RIGHTS as $kind => [$tab, $lc, $oc]) {
			$refs = array_values(array_unique(array_map(static fn($i) => (string)$i[1], array_filter($items, static fn($i) => $i[0] === $kind))));
			if (!$refs || !$db->tableExists($tab)) continue;
			foreach (array_chunk($refs, 200) as $chunk) {
				foreach ($db->all("SELECT * FROM $tab WHERE $oc IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
					$lv = (string)($codes[(string)$r[$lc]] ?? '');
					if ($lv !== '') $pkg['rights'][] = ['kind' => $kind, 'ref' => (string)$r[$oc], 'level' => $lv, 'row' => $r];
				}
			}
		}
		// translations of the forms' texts
		if ($formIds && $db->tableExists('zzzzsys_translate')) {
			$texts = [];
			foreach ($formIds as $fid) { try { $texts += NuTranslate::texts($fid); } catch (Throwable $ig) { nu_ignored($ig, 'texts of a form'); } }
			foreach (array_chunk(array_map('strval', array_keys($texts)), 200) as $chunk) {
				foreach ($db->all('SELECT trl_language, trl_english, trl_translation FROM zzzzsys_translate WHERE trl_english IN ('
					. implode(',', array_fill(0, count($chunk), '?')) . ") AND zzzzsys_translate_id NOT LIKE 'nu%'", $chunk) as $r) {
					$pkg['translations'][] = ['language' => (string)$r['trl_language'], 'english' => (string)$r['trl_english'], 'translation' => (string)$r['trl_translation']];
				}
			}
		}
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
		if (!is_array($p) || ($p['format'] ?? '') !== self::FORMAT || !is_array($p['project'] ?? null)
			|| !preg_match(NuProject::CODE_RE, (string)($p['project']['code'] ?? ''))) return [null, t('prj_err_package')];
		foreach (self::LISTS as $k) if (!is_array($p[$k] ?? [])) return [null, t('prj_err_package')];
		return [$p, ''];
	}

	/**
	 * What the import would do. @return list<array{key: string, kind: string, ref: string, label: string, state: string, note: string, action: string, actions: list<string>}>
	 * state: new | same | different | conflict | exists (tables) | unsupported | only (here, not in the package); action = the proposal
	 * (an "only here" part is kept unless chosen: Delete).
	 */
	public static function check(array $pkg): array
	{
		$db = nu_db();
		$out = [];
		$row = static function (string $kind, string $ref, string $label, string $state, string $note = '', bool $blocked = false) use (&$out): void {
			$actions = $blocked ? ['skip'] : match ($state) { 'new' => ['add', 'skip'], 'different' => ['replace', 'skip'], 'only' => ['skip', 'delete'], default => ['skip'] };
			$out[] = ['key' => $kind . '|' . $ref, 'kind' => $kind, 'ref' => $ref, 'label' => $label, 'state' => $state, 'note' => $note, 'action' => $actions[0], 'actions' => $actions];
		};
		foreach ($pkg['tables'] ?? [] as $t) {
			$name = (string)($t['name'] ?? '');
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) || str_starts_with(strtolower($name), 'zzzzsys_')) continue;
			if ($db->type !== 'mysql') { $row('table', $name, $name, 'unsupported', t('prj_imp_mysql_only')); continue; }
			$exists = $db->tableExists($name);
			$n = isset($t['rows']) ? count((array)$t['rows']) : 0;
			$note = ($t['type'] ?? '') === 'view' ? t('prj_imp_view') : ($n ? t('prj_imp_rows', $n) : (isset($t['rows_left_out']) ? t('prj_imp_rows_left', (int)$t['rows_left_out']) : t('prj_imp_structure')));
			$add = $exists ? self::missingColumns($t) : [];
			if ($add) $row('table', $name, $name, 'different', t('prj_imp_add_cols', implode(', ', array_keys($add))));
			else $row('table', $name, $name, $exists ? 'exists' : 'new', $exists ? t('prj_imp_table_kept') : $note);
		}
		foreach ($pkg['forms'] ?? [] as $s) {
			$f = (array)($s['form'] ?? []);
			$id = (string)($f['zzzzsys_form_id'] ?? '');
			if ($id === '' || str_starts_with($id, 'nu')) continue;
			$label = trim((string)($f['sfo_description'] ?? '')) . ' (' . ($f['sfo_code'] ?? '') . ')';
			$cur = NuFormSnapshot::capture($id);
			if ($cur) {
				$same = NuFormSnapshot::hash($cur) === NuFormSnapshot::hash($s);
				$n = 0;
				if (!$same) { try { $n = array_sum(NuFormSnapshot::diff($cur, $s)['counts']); } catch (Throwable $ig) { nu_ignored($ig, 'compare of a form'); } }
				$notes = array_filter([$n ? t('prj_imp_changes', $n) : '', NuFormDraft::ready() && NuFormDraft::openFor($id) ? t('prj_imp_draft_open') : '']);
				$row('form', $id, $label, $same ? 'same' : 'different', implode(' · ', $notes));
				continue;
			}
			$clash = $db->value('SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = ?', [(string)($f['sfo_code'] ?? '')]);
			$row('form', $id, $label, $clash ? 'conflict' : 'new', $clash ? t('prj_imp_code_used') : '');
		}
		foreach (self::ROWS as $kind => [$tab, $idc, $codec]) {
			foreach ($pkg[self::key($kind)] ?? [] as $r) self::checkRow($row, $kind, $tab, $idc, $codec, (array)$r);
		}
		foreach ($pkg['select'] ?? [] as $x) self::checkRow($row, 'select', 'zzzzsys_select', 'zzzzsys_select_id', 'sse_code', (array)($x['row'] ?? []), (array)($x['clauses'] ?? []));
		$bases = [];
		foreach ($pkg['help'] ?? [] as $h) if ((string)($h['lang'] ?? '') === '') $bases[(string)$h['code']] = (string)($h['title'] ?? $h['code']);
		$here = [];                                        // help pages of this installation, in the export format: same = nothing to do
		if ($bases && NuUserHelp::ready()) {
			foreach ((array)(json_decode(NuUserHelp::export(), true)['pages'] ?? []) as $h) $here[(string)($h['code'] ?? '')][] = $h;
		}
		$their = [];
		foreach ($pkg['help'] ?? [] as $h) $their[(string)($h['code'] ?? '')][] = $h;
		$norm = static function (array $pages): string {
			usort($pages, static fn($a, $b) => strcmp((string)($a['lang'] ?? ''), (string)($b['lang'] ?? '')));
			return (string)json_encode($pages);
		};
		foreach ($bases as $code => $title) {
			$state = !isset($here[$code]) ? 'new' : ($norm($here[$code]) === $norm($their[$code] ?? []) ? 'same' : 'different');
			$row('helppage', $code, $title . ' (' . $code . ')', $state, '');
		}
		// access rights (by access level code) and translations: one line each
		if ($pkg['rights'] ?? []) {
			[$todo, $missing] = self::rightsPlan((array)$pkg['rights']);
			$note = t('prj_imp_rights_n', count((array)$pkg['rights'])) . ($missing ? ' · ' . t('prj_imp_levels_missing', implode(', ', $missing)) : '');
			$row('rights', 'all', t('prj_kind_rights'), $todo ? 'different' : 'same', $note);
		}
		if ($pkg['translations'] ?? []) {
			$todo = self::translationsPlan((array)$pkg['translations']);
			$row('translations', 'all', t('prj_kind_translations'), $todo ? 'different' : 'same', t('prj_imp_trl_n', count((array)$pkg['translations']), $todo));
		}
		if ((string)$pkg['project']['code'] !== self::APP) {
			$p = NuProject::byCode((string)$pkg['project']['code']);
			$row('project', (string)$pkg['project']['code'], (string)$pkg['project']['name'], $p ? 'different' : 'new',
				$p ? t('prj_imp_merge') : t('prj_imp_new_project', count($pkg['notes'] ?? []), count($pkg['tasks'] ?? [])));
		}
		// parts that exist only here: of the same project (project package) or of the whole application (application package)
		$only = self::onlyHere($pkg);
		$gone = [];
		foreach ($only as [$k, $r]) if ($k === 'form') $gone[$r] = true;
		foreach ($only as [$kind, $ref]) {
			$label = self::partLabel($kind, $ref);
			if ($kind === 'table') { $row('table', $ref, $label, 'only', t('prj_only_table'), true); continue; }
			$users = self::usedBy($kind, $ref, $gone);
			$row($kind, $ref, $label, 'only', $users ? t('prj_only_used', implode(', ', array_slice($users, 0, 5))) : '', (bool)$users);
		}
		if ($n = count(self::extraRights($pkg))) $row('rights', 'extra', t('prj_kind_rights'), 'only', t('prj_only_rights', $n));
		return $out;
	}

	private static function checkRow(callable $row, string $kind, string $tab, string $idc, string $codec, array $r, array $clauses = []): void
	{
		$db = nu_db();
		$id = (string)($r[$idc] ?? '');
		if ($id === '' || !$db->tableExists($tab)) return;
		$label = trim((string)($r[self::labelCol($kind)] ?? '')) . ' (' . ($r[$codec] ?? '') . ')';
		$cur = $db->one("SELECT * FROM $tab WHERE $idc = ?", [$id]);
		foreach (self::SECRET[$tab] ?? [] as $c) unset($cur[$c], $r[$c]);
		if ($cur) {
			$same = self::canon(NuFormSnapshot::only($tab, $cur)) === self::canon(NuFormSnapshot::only($tab, $r));
			if ($same && $kind === 'select') $same = self::canonList($db->all('SELECT * FROM zzzzsys_select_clause WHERE ssc_zzzzsys_select_id = ?', [$id])) === self::canonList($clauses);
			$row($kind, $id, $label, $same ? 'same' : 'different', '');
			return;
		}
		$code = $codec !== '' ? (string)($r[$codec] ?? '') : '';
		$clash = $code !== '' && $db->value("SELECT $idc FROM $tab WHERE $codec = ?", [$code]);
		$row($kind, $id, $label, $clash ? 'conflict' : 'new', $clash ? t('prj_imp_code_used') : '');
	}

	/** Columns of a table in the package that the existing table lacks: name => definition (MySQL; never the key or generated columns). */
	public static function missingColumns(array $t): array
	{
		$db = nu_db();
		$name = (string)($t['name'] ?? '');
		if ($db->type !== 'mysql' || ($t['type'] ?? '') === 'view' || !$db->tableExists($name)) return [];
		$have = array_change_key_case($db->columns($name), CASE_LOWER);
		$out = [];
		foreach (preg_split('/\R/', (string)($t['create'] ?? '')) ?: [] as $line) {
			if (!preg_match('/^\s*`((?:[^`]|``)+)`\s+(.+?),?\s*$/', $line, $m)) continue;
			$col = str_replace('``', '`', $m[1]);
			if (isset($have[strtolower($col)]) || preg_match('/\b(AUTO_INCREMENT|GENERATED|AS\s*\()/i', $m[2])) continue;
			$out[$col] = $m[2];
		}
		return $out;
	}

	/** Access rights still to write and the level codes this installation lacks. @return array{0: int, 1: list<string>} */
	private static function rightsPlan(array $rights): array
	{
		$db = nu_db();
		$byCode = array_flip(NuAcl::levelCodes());
		$todo = 0; $missing = [];
		foreach ($rights as $x) {
			$kind = (string)($x['kind'] ?? '');
			if (!isset(self::RIGHTS[$kind])) continue;
			[$tab, $lc, $oc] = self::RIGHTS[$kind];
			$lv = (string)($x['level'] ?? '');
			if (!isset($byCode[$lv])) { $missing[$lv] = true; continue; }
			$cur = $db->tableExists($tab) ? $db->one("SELECT * FROM $tab WHERE $lc = ? AND $oc = ?", [$byCode[$lv], (string)($x['ref'] ?? '')]) : null;
			$want = (array)($x['row'] ?? []);
			unset($want[$tab . '_id'], $want[$lc]);
			if ($cur) unset($cur[$tab . '_id'], $cur[$lc]);
			if (!$cur || self::canon(NuFormSnapshot::only($tab, $cur)) !== self::canon(NuFormSnapshot::only($tab, $want))) $todo++;
		}
		return [$todo, array_keys($missing)];
	}

	/** Translations that are missing or different here. */
	private static function translationsPlan(array $rows): int
	{
		$db = nu_db();
		$n = 0;
		foreach ($rows as $x) {
			$cur = $db->value('SELECT trl_translation FROM zzzzsys_translate WHERE trl_language = ? AND trl_english = ?', [(string)($x['language'] ?? ''), (string)($x['english'] ?? '')]);
			if ($cur === null || $cur === false || (string)$cur !== (string)($x['translation'] ?? '')) $n++;
		}
		return $n;
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
	 * @return array{done: array<string, int>, deleted: array<string, int>, skipped: int, errors: list<string>, project: string, restore: string}
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
		$res = ['done' => [], 'deleted' => [], 'skipped' => 0, 'errors' => [], 'project' => '', 'restore' => self::restorePoint($pkg, $plan)];
		$ok = static function (string $kind) use (&$res): void { $res['done'][$kind] = ($res['done'][$kind] ?? 0) + 1; };
		// 1. tables (views after the tables they may read)
		$tables = array_values(array_filter((array)($pkg['tables'] ?? []), 'is_array'));
		usort($tables, static fn($a, $b) => (($a['type'] ?? '') === 'view') <=> (($b['type'] ?? '') === 'view'));
		foreach ($tables as $t) {
			$name = (string)($t['name'] ?? '');
			if ($want('table', $name) === 'replace') {                 // an existing table: only the missing columns are added
				try {
					foreach (self::missingColumns($t) as $col => $def) {
						$db->exec('ALTER TABLE `' . str_replace('`', '``', $name) . '` ADD COLUMN `' . str_replace('`', '``', (string)$col) . '` ' . $def);
					}
					NuDB::forgetColumns('');
					$ok('table');
				} catch (Throwable $e) {
					$res['errors'][] = t('prj_kind_table') . ' ' . $name . ': ' . $e->getMessage();
				}
				continue;
			}
			if ($want('table', $name) !== 'add') { $res['skipped']++; continue; }
			try {
				$create = self::noDefiner((string)$t['create']);
				if (!preg_match('/^CREATE\s+(TABLE\s+`?' . preg_quote($name, '/')
					. '`?\s*\(|(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(SQL\s+SECURITY\s+\w+\s+)?VIEW\s+`?' . preg_quote($name, '/') . '`?\s+AS\s)/i',
					$create)) throw new RuntimeException(t('prj_err_create'));
				$db->exec($create);
				$rows = (array)($t['rows'] ?? []);
				$cols = array_map('strval', (array)($t['columns'] ?? []));
				if ($rows && $cols) {
					$sql = 'INSERT INTO `' . str_replace('`', '``', $name) . '` ('
						. implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $cols)) . ') VALUES ('
						. implode(', ', array_fill(0, count($cols), '?')) . ')';
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
			foreach ($pkg[self::key($kind)] ?? [] as $r) {
				$id = (string)($r[$idc] ?? '');
				$a = $want($kind, $id);
				if ($a === 'skip') { $res['skipped']++; continue; }
				try {
					$db->transaction(static function (NuDB $db) use ($tab, $idc, $id, $r): void {
						if ($sec = self::SECRET[$tab] ?? []) {               // secrets stay as they are here (a new row gets none)
							$cur = $db->one("SELECT * FROM $tab WHERE $idc = ?", [$id]);
							foreach ($sec as $c) $r[$c] = $cur[$c] ?? '';
						}
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
		// 5. access rights (levels found by code) and translations
		if (($pkg['rights'] ?? []) && $want('rights', 'all') !== 'skip') {
			try { $res['done']['rights'] = self::writeRights((array)$pkg['rights']); } catch (Throwable $e) { $res['errors'][] = t('prj_kind_rights') . ': ' . $e->getMessage(); }
		}
		if (($pkg['translations'] ?? []) && $want('translations', 'all') !== 'skip') {
			try { $res['done']['translations'] = self::writeTranslations((array)$pkg['translations']); } catch (Throwable $e) { $res['errors'][] = t('prj_kind_translations') . ': ' . $e->getMessage(); }
		}
		// 6. parts that exist only here and were chosen "Delete" (after the forms of the package are in: they may use them)
		self::deleteParts($pkg, $plan, $res);
		// 7. the project: created (with notes and to-dos) or the items added to the existing one (not for the whole application)
		$code = (string)$pkg['project']['code'];
		$p = NuProject::byCode($code);
		$new = !$p;
		if ($code !== self::APP && $want('project', $code) !== 'skip') {
			if ($new) {
				[$pid, $err] = NuProject::save('', ['code' => $code, 'name' => (string)$pkg['project']['name'], 'description' => (string)($pkg['project']['description'] ?? '')]);
				if ($err) { $res['errors'][] = implode(' ', $err); $pid = ''; }
			} else $pid = (string)$p['zzzzsys_nunext_project_id'];
			if ($pid !== '') {
				$items = [];
				foreach ((array)($pkg['items'] ?? []) as $it) if (is_array($it) && count($it) === 2) $items[] = [(string)$it[0],
					$it[0] === 'helppage' ? (string)($db->value('SELECT zzzzsys_nunext_help_id FROM ' . NuUserHelp::T . " WHERE code = ? AND lang = ''",
						[self::helpCode($pkg, (string)$it[1])]) ?: $it[1]) : (string)$it[1]];
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

	private static function writeRights(array $rights): int
	{
		$db = nu_db();
		$byCode = array_flip(NuAcl::levelCodes());
		$n = 0;
		foreach ($rights as $x) {
			$kind = (string)($x['kind'] ?? '');
			$lv = (string)($x['level'] ?? '');
			if (!isset(self::RIGHTS[$kind], $byCode[$lv])) continue;
			[$tab, $lc, $oc] = self::RIGHTS[$kind];
			$ref = (string)($x['ref'] ?? '');
			$row = NuFormSnapshot::only($tab, (array)($x['row'] ?? []));
			$row[$lc] = (string)$byCode[$lv];
			$row[$oc] = $ref;
			$db->transaction(static function (NuDB $db) use ($tab, $lc, $oc, $row, $ref, $byCode, $lv): void {
				$cur = $db->value("SELECT {$tab}_id FROM $tab WHERE $lc = ? AND $oc = ?", [(string)$byCode[$lv], $ref]);
				if ($cur) $db->delete($tab, $tab . '_id', (string)$cur);
				$id = (string)($row[$tab . '_id'] ?? '');
				if ($id === '' || $db->value("SELECT COUNT(*) FROM $tab WHERE {$tab}_id = ?", [$id])) $row[$tab . '_id'] = $cur ? (string)$cur : nu_id();
				$db->insert($tab, $row);
			});
			$n++;
		}
		return $n;
	}

	private static function writeTranslations(array $rows): int
	{
		$db = nu_db();
		$n = 0;
		foreach ($rows as $x) {
			$lang = (string)($x['language'] ?? ''); $en = (string)($x['english'] ?? ''); $tr = (string)($x['translation'] ?? '');
			if ($lang === '' || $en === '') continue;
			$cur = $db->value('SELECT zzzzsys_translate_id FROM zzzzsys_translate WHERE trl_language = ? AND trl_english = ?', [$lang, $en]);
			if ($cur) $db->update('zzzzsys_translate', ['trl_translation' => $tr], 'zzzzsys_translate_id', (string)$cur);
			else $db->insert('zzzzsys_translate', ['zzzzsys_translate_id' => nu_id(), 'trl_language' => $lang, 'trl_english' => $en, 'trl_translation' => $tr]);
			$n++;
		}
		return $n;
	}

	/**
	 * Restore point: the current versions of everything the import replaces, as a package in the backup folder (importing it undoes
	 * the replacements; added parts stay). '' when nothing is replaced. @param array<string, string> $plan key => action
	 */
	private static function restorePoint(array $pkg, array $plan): string
	{
		$items = [];
		foreach ($plan as $key => $a) {
			if ($a !== 'replace' && $a !== 'delete') continue;
			[$kind, $ref] = explode('|', $key, 2) + [1 => ''];
			if ($kind === 'rights' || $kind === 'translations') continue;
			if ($kind === 'helppage') { $id = nu_db()->value('SELECT zzzzsys_nunext_help_id FROM ' . NuUserHelp::T . " WHERE code = ? AND lang = ''", [$ref]); if ($id) $items[] = ['helppage', (string)$id]; }
			elseif (isset(NuProject::KINDS[$kind]) && $kind !== 'table') $items[] = [$kind, $ref];
		}
		$rightsTouched = ($plan['rights|all'] ?? '') === 'replace' || ($plan['rights|extra'] ?? '') === 'delete';
		if (!$items && !$rightsTouched && ($plan['translations|all'] ?? '') !== 'replace') return '';
		try {
			$code = (string)($pkg['project']['code'] ?? 'project');
			$back = self::build($items, false, ['code' => $code, 'name' => (string)($pkg['project']['name'] ?? $code), 'description' => '']);
			$back['restore_point'] = true;               // its import puts parts back – it never lists the others as "only here"
			// the rights / translations as they are here now, for the same objects and texts
			if ($rightsTouched) {
				$refs = self::pkgRefs($pkg);
				$codes = NuAcl::levelCodes();
				$seen = [];                                // the rights of deleted parts are in $back already (build())
				foreach ((array)($back['rights'] ?? []) as $x) $seen[$x['kind'] . '|' . $x['ref']] = true;
				foreach (self::RIGHTS as $kind => [$tab, $lc, $oc]) {
					foreach (array_keys($refs[$kind] ?? []) as $ref) {
						if (isset($seen[$kind . '|' . $ref])) continue;
						foreach (nu_db()->all("SELECT * FROM $tab WHERE $oc = ?", [(string)$ref]) as $r) {
							if (isset($codes[(string)$r[$lc]])) $back['rights'][] = ['kind' => $kind, 'ref' => (string)$ref, 'level' => (string)$codes[(string)$r[$lc]], 'row' => $r];
						}
					}
				}
			}
			if (($plan['translations|all'] ?? '') === 'replace') {
				$back['translations'] = [];
				foreach ((array)($pkg['translations'] ?? []) as $x) {
					$cur = nu_db()->value('SELECT trl_translation FROM zzzzsys_translate WHERE trl_language = ? AND trl_english = ?', [(string)$x['language'], (string)$x['english']]);
					if ($cur !== null && $cur !== false) $back['translations'][] = ['language' => (string)$x['language'], 'english' => (string)$x['english'], 'translation' => (string)$cur];
				}
			}
			$dir = NuUpdate::backupDir();
			if (!is_dir($dir)) @mkdir($dir, 0775, true);
			$name = 'project-restore-' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $code) . '-' . date('Ymd-His') . '.json';
			if (@file_put_contents($dir . '/' . $name, self::json($back)) === false) return '';
			@chmod($dir . '/' . $name, 0600);
			return $name;
		} catch (Throwable $ig) {
			nu_ignored($ig, 'restore point of a project import');
			return '';
		}
	}

	/** Full path of a restore point by name ('' when the name is not one). */
	public static function restoreFile(string $name): string
	{
		if (!preg_match('/^project-restore-[A-Za-z0-9_.-]+-\d{8}-\d{6}\.json$/', $name)) return '';
		$f = NuUpdate::backupDir() . '/' . $name;
		return is_file($f) ? $f : '';
	}

	// ------------------------------------------------------------------ parts that exist only here

	/** The parts a package holds, by kind => [ref => true] (help pages by code, tables by name). @return array<string, array<string, bool>> */
	private static function pkgRefs(array $pkg): array
	{
		$out = [];
		foreach ($pkg['forms'] ?? [] as $x) $out['form'][(string)($x['form']['zzzzsys_form_id'] ?? '')] = true;
		foreach (self::ROWS as $kind => [, $idc]) foreach ($pkg[self::key($kind)] ?? [] as $r) $out[$kind][(string)($r[$idc] ?? '')] = true;
		foreach ($pkg['select'] ?? [] as $x) $out['select'][(string)($x['row']['zzzzsys_select_id'] ?? '')] = true;
		foreach ($pkg['help'] ?? [] as $h) $out['helppage'][(string)($h['code'] ?? '')] = true;
		foreach ($pkg['tables'] ?? [] as $t) $out['table'][(string)($t['name'] ?? '')] = true;
		foreach ($pkg['left_out'] ?? [] as $l) if (is_array($l) && ($l[0] ?? '') === 'table') $out['table'][(string)($l[1] ?? '')] = true;
		return $out;
	}

	/**
	 * Parts here that the package does not hold: of the project with the same code (a project package), or every own part (the
	 * application package). Help pages come back by code. @return list<array{0: string, 1: string}>
	 */
	private static function onlyHere(array $pkg): array
	{
		if (!empty($pkg['restore_point'])) return [];
		$code = (string)($pkg['project']['code'] ?? '');
		if ($code === self::APP) $here = self::ownItems();
		else {
			$p = NuProject::byCode($code);
			if (!$p) return [];
			$here = [];
			foreach (NuProject::items((string)$p['zzzzsys_nunext_project_id']) as $it) if (!$it['missing']) $here[] = [$it['kind'], $it['ref']];
		}
		$have = self::pkgRefs($pkg);
		$out = [];
		foreach ($here as [$kind, $ref]) {
			if ($kind === 'helppage') $ref = (string)(nu_db()->value('SELECT code FROM ' . NuUserHelp::T . ' WHERE zzzzsys_nunext_help_id = ?', [$ref]) ?: '');
			if ($ref === '' || !isset(NuProject::KINDS[$kind]) || isset($have[$kind][$ref])) continue;
			$out[$kind . '|' . $ref] = [$kind, $ref];
		}
		ksort($out, SORT_STRING);
		return array_values($out);
	}

	private static function partLabel(string $kind, string $ref): string
	{
		if ($kind === 'helppage') {
			$id = (string)(nu_db()->value('SELECT zzzzsys_nunext_help_id FROM ' . NuUserHelp::T . " WHERE code = ? AND lang = ''", [$ref]) ?: '');
			$d = $id !== '' ? NuProject::describe('helppage', $id) : null;
		} else $d = NuProject::describe($kind, $ref);
		return (string)($d['label'] ?? $ref) . ' (' . $ref . ')';
	}

	/**
	 * What still uses a part that would be deleted – forms (not deleted with it) whose objects open it as sub-form, lookup or run
	 * target, access levels that start with it. @param array<string, bool> $gone forms deleted in the same import @return list<string>
	 */
	private static function usedBy(string $kind, string $ref, array $gone): array
	{
		if (!in_array($kind, ['form', 'procedure', 'report'], true)) return [];
		$db = nu_db();
		$out = [];
		$cols = $kind === 'form' ? ['sob_subform_zzzzsys_form_id', 'sob_lookup_zzzzsys_form_id', 'sob_run_zzzzsys_form_id'] : ['sob_run_zzzzsys_form_id'];
		foreach ($db->all('SELECT DISTINCT o.sob_all_zzzzsys_form_id AS f FROM zzzzsys_object o WHERE (' . implode(' = ? OR ', $cols) . ' = ?) AND o.sob_all_zzzzsys_form_id <> ?',
			array_merge(array_fill(0, count($cols), $ref), [$ref])) as $r) {
			$f = (string)$r['f'];
			if (!isset($gone[$f])) $out[] = (string)(nu_form($f)['code'] ?? $f);
		}
		if ($kind === 'form') foreach ($db->all('SELECT sal_code FROM zzzzsys_access WHERE sal_zzzzsys_form_id = ?', [$ref]) as $r) $out[] = (string)$r['sal_code'];
		return array_values(array_unique($out));
	}

	/**
	 * Access rights here on the forms, procedures and reports of the package for levels the package gives no right on that part (only
	 * packages that carry rights). @return list<array{0: string, 1: string}> [table, row id]
	 */
	private static function extraRights(array $pkg): array
	{
		if (!empty($pkg['restore_point']) || !array_key_exists('rights', $pkg) || !is_array($pkg['rights'])) return [];
		$have = self::pkgRefs($pkg);
		$given = [];
		foreach ($pkg['rights'] as $x) $given[(string)($x['kind'] ?? '') . '|' . ($x['ref'] ?? '') . '|' . ($x['level'] ?? '')] = true;
		$codes = NuAcl::levelCodes();
		$out = [];
		foreach (self::RIGHTS as $kind => [$tab, $lc, $oc]) {
			foreach (array_keys($have[$kind] ?? []) as $ref) {
				foreach (nu_db()->all("SELECT {$tab}_id AS id, $lc AS lv FROM $tab WHERE $oc = ?", [(string)$ref]) as $r) {
					$code = (string)($codes[(string)$r['lv']] ?? '');
					if ($code !== '' && !isset($given[$kind . '|' . $ref . '|' . $code])) $out[] = [$tab, (string)$r['id']];
				}
			}
		}
		return $out;
	}

	/** Deletes the parts chosen "Delete" (forms still used by kept forms are left). @param array<string, string> $plan */
	private static function deleteParts(array $pkg, array $plan, array &$res): void
	{
		$db = nu_db();
		$del = [];
		foreach ($plan as $key => $a) if ($a === 'delete') $del[] = explode('|', $key, 2) + [1 => ''];
		if (!$del) return;
		$gone = [];
		foreach ($del as [$k, $r]) if ($k === 'form') $gone[$r] = true;
		foreach ($del as [$kind, $ref]) {
			try {
				if ($kind === 'rights') {
					$n = 0;
					foreach (self::extraRights($pkg) as [$tab, $id]) { $db->delete($tab, $tab . '_id', $id); $n++; }
					$res['deleted']['rights'] = $n;
					continue;
				}
				if ($users = self::usedBy($kind, $ref, $gone)) { $res['errors'][] = self::partLabel($kind, $ref) . ': ' . t('prj_only_used', implode(', ', $users)); continue; }
				if ($kind === 'form') {
					NuFormHistory::snapshot($ref, 'before project import (deleted)');
					$db->transaction(static fn(NuDB $db) => NuFormSnapshot::remove($db, $ref));
					NuPage::forget($ref);
					NuFormMeta::forget($ref);
				} elseif ($kind === 'helppage') {
					$id = (string)($db->value('SELECT zzzzsys_nunext_help_id FROM ' . NuUserHelp::T . " WHERE code = ? AND lang = ''", [$ref]) ?: '');
					if ($id !== '') NuUserHelp::delete($id);
				} elseif ($kind === 'select') {
					$db->transaction(static function (NuDB $db) use ($ref): void {
						$db->delete('zzzzsys_select_clause', 'ssc_zzzzsys_select_id', $ref);
						$db->delete('zzzzsys_select', 'zzzzsys_select_id', $ref);
					});
				} elseif (isset(self::ROWS[$kind])) {
					[$tab, $idc] = self::ROWS[$kind];
					$db->transaction(static function (NuDB $db) use ($kind, $tab, $idc, $ref): void {
						if (isset(self::RIGHTS[$kind])) { [$rt, , $oc] = self::RIGHTS[$kind]; $db->delete($rt, $oc, $ref); }
						$db->delete($tab, $idc, $ref);
					});
				} else continue;
				if (NuProject::ready()) $db->run('DELETE FROM ' . NuProject::I . ' WHERE kind = ? AND ref = ?', [$kind, $ref]);
				$res['deleted'][$kind] = ($res['deleted'][$kind] ?? 0) + 1;
			} catch (Throwable $e) {
				$res['errors'][] = t('prj_kind_' . $kind) . ' ' . $ref . ': ' . $e->getMessage();
			}
		}
		NuLog::write('project_parts_deleted', NuProject::T, (string)($pkg['project']['code'] ?? ''), ['deleted' => $res['deleted']]);
	}

	/** The help page code of an exported help item id (the id differs in another installation). */
	private static function helpCode(array $pkg, string $ref): string
	{
		foreach ((array)($pkg['help_ids'] ?? []) as $id => $code) if ((string)$id === $ref) return (string)$code;
		return $ref;
	}
}
