<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Projects (0.8.1035): a project groups the parts of one piece of the application – forms, procedures, reports, SQL builder
 * queries, tables / views, e-mail templates, code snippets, help pages – and holds its own notes (Markdown) and to-dos.
 * A tool for developers / administrators: admin right "projects" (view / change), or the save right on the Forms form.
 * Own tables only (nuBuilder Forte is not touched); an item can be in several projects.
 *  - members:   items(), addItems(), removeItem(), candidates() (Add dialog), suggest() (Suggest related: scan of the members)
 *  - notes:     notes(), saveNote(), deleteNote()
 *  - to-dos:    tasks(), saveTask() (the assignee gets a notification), setTaskStatus(), deleteTask(), dueHousekeeping() (due today)
 *  - working on: working() / setWorking() – the forms / procedures / reports lists show only the project's items (filter 'project')
 *  - package:   NuProjectPackage (core/nuprojectpackage.php) – export / import of a project with its items
 */
final class NuProject
{
	public const T = 'zzzzsys_nunext_project';
	public const I = 'zzzzsys_nunext_project_item';
	public const N = 'zzzzsys_nunext_project_note';
	public const K = 'zzzzsys_nunext_project_task';
	/** Kinds of members: kind => [table, id column, label column, code column, icon]. 'table' has no table of its own. */
	public const KINDS = [
		'form'          => ['zzzzsys_form', 'zzzzsys_form_id', 'sfo_description', 'sfo_code', 'bi-window'],
		'procedure'     => ['zzzzsys_php', 'zzzzsys_php_id', 'sph_description', 'sph_code', 'bi-filetype-php'],
		'report'        => ['zzzzsys_report', 'zzzzsys_report_id', 'sre_description', 'sre_code', 'bi-file-earmark-richtext'],
		'select'        => ['zzzzsys_select', 'zzzzsys_select_id', 'sse_description', 'sse_code', 'bi-diagram-3'],
		'table'         => ['', '', '', '', 'bi-table'],
		'emailtemplate' => ['zzzzsys_email_template', 'zzzzsys_email_template_id', 'emt_description', 'emt_code', 'bi-envelope'],
		'snippet'       => ['zzzzsys_code_snippet', 'zzzzsys_code_snippet_id', 'cot_description', 'cot_code', 'bi-braces'],
		'helppage'      => ['zzzzsys_nunext_help', 'zzzzsys_nunext_help_id', 'title', 'code', 'bi-journal-bookmark'],
	];
	public const CODE_RE = '/^[A-Za-z0-9_.-]{1,60}$/';
	private const SESSION = 'nu_project';
	private static array $cache = [];

	// ------------------------------------------------------------------ rights, tables

	public static function canView(): bool
	{
		$u = NuAuth::user();
		return $u && (!empty($u['globeadmin']) || NuAdmin::can('projects', 'view') || NuAcl::can(NuAcl::FORM_FORM, 'save'));
	}

	public static function canChange(): bool
	{
		$u = NuAuth::user();
		return $u && !NuImpersonate::readOnly() && (!empty($u['globeadmin']) || NuAdmin::can('projects', 'change') || NuAcl::can(NuAcl::FORM_FORM, 'save'));
	}

	public static function ready(): bool
	{
		try { return nu_db()->tableExists(self::T) && nu_db()->tableExists(self::I); } catch (Throwable) { return false; }
	}

	private static function now(): string { return date('Y-m-d H:i:s'); }

	private static function by(): string { return mb_substr((string)(NuAuth::currentRaw()['login'] ?? ''), 0, 50); }

	// ------------------------------------------------------------------ projects

	/** Projects with their counts, archived ones only with $archived. @return list<array> */
	public static function all(bool $archived = true): array
	{
		if (!self::ready()) return [];
		$db = nu_db();
		$rows = $db->all('SELECT * FROM ' . self::T . ($archived ? '' : " WHERE COALESCE(status, 'active') <> 'archived'") . ' ORDER BY name');
		$items = $tasks = [];
		foreach ($db->all('SELECT project_id, COUNT(*) AS n FROM ' . self::I . ' GROUP BY project_id') as $r) $items[(string)$r['project_id']] = (int)$r['n'];
		if ($db->tableExists(self::K)) foreach ($db->all('SELECT project_id, COUNT(*) AS n FROM ' . self::K . " WHERE status <> 'done' GROUP BY project_id") as $r) $tasks[(string)$r['project_id']] = (int)$r['n'];
		foreach ($rows as &$r) { $id = (string)$r['zzzzsys_nunext_project_id']; $r['items'] = $items[$id] ?? 0; $r['open_tasks'] = $tasks[$id] ?? 0; }
		return $rows;
	}

	/** id => name of the active projects (filters, Working on). */
	public static function choices(): array
	{
		$o = [];
		foreach (self::all(false) as $r) $o[(string)$r['zzzzsys_nunext_project_id']] = (string)$r['name'];
		return $o;
	}

	public static function load(string $id): ?array
	{
		if ($id === '' || !self::ready()) return null;
		return nu_db()->one('SELECT * FROM ' . self::T . ' WHERE zzzzsys_nunext_project_id = ?', [$id]) ?: null;
	}

	public static function byCode(string $code): ?array
	{
		if ($code === '' || !self::ready()) return null;
		return nu_db()->one('SELECT * FROM ' . self::T . ' WHERE code = ?', [$code]) ?: null;
	}

	/** Creates / changes a project. @return array{0: string, 1: array<string, string>} [id, errors by field] */
	public static function save(string $id, array $in): array
	{
		NuSchema::ensure();
		$e = [];
		$name = mb_substr(trim((string)($in['name'] ?? '')), 0, 200);
		$code = trim((string)($in['code'] ?? ''));
		if ($name === '') $e['name'] = t('err_required');
		if ($code === '') $code = self::codeFrom($name, $id);
		if (!preg_match(self::CODE_RE, $code)) $e['code'] = t('prj_err_code');
		elseif (($o = self::byCode($code)) && (string)$o['zzzzsys_nunext_project_id'] !== $id) $e['code'] = t('prj_err_code_used');
		$status = ($in['status'] ?? '') === 'archived' ? 'archived' : 'active';
		if ($e) return [$id, $e];
		$row = ['code' => $code, 'name' => $name, 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 2000), 'status' => $status,
			'updated_at' => self::now(), 'updated_by' => self::by()];
		$db = nu_db();
		$old = $id !== '' ? self::load($id) : null;
		if ($old) $db->update(self::T, $row, 'zzzzsys_nunext_project_id', $id);
		else {
			$id = nu_id();
			$db->insert(self::T, ['zzzzsys_nunext_project_id' => $id, 'created_at' => self::now(), 'created_by' => self::by()] + $row);
		}
		NuLog::write($old ? 'project_changed' : 'project_created', self::T, $id, ['code' => $code, 'name' => $name, 'status' => $status]);
		return [$id, []];
	}

	/** A free code from a name (lower case letters, digits, _). */
	public static function codeFrom(string $name, string $id = ''): string
	{
		$base = trim((string)preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), '_');
		$base = substr($base !== '' ? $base : 'project', 0, 50);
		$code = $base;
		for ($i = 2; ($o = self::byCode($code)) && (string)$o['zzzzsys_nunext_project_id'] !== $id; $i++) $code = $base . '_' . $i;
		return $code;
	}

	public static function delete(string $id): void
	{
		$p = self::load($id);
		if (!$p) return;
		$db = nu_db();
		foreach ([self::I, self::N, self::K] as $t) if ($db->tableExists($t)) $db->delete($t, 'project_id', $id);
		$db->delete(self::T, 'zzzzsys_nunext_project_id', $id);
		if (self::working() === $id) self::setWorking('');
		NuLog::write('project_deleted', self::T, $id, ['code' => (string)$p['code'], 'name' => (string)$p['name']]);
	}

	// ------------------------------------------------------------------ members

	/** The members of a project with label, second line, link and whether the item still exists. @return list<array> */
	public static function items(string $projectId): array
	{
		if (!self::ready()) return [];
		$out = [];
		foreach (nu_db()->all('SELECT * FROM ' . self::I . ' WHERE project_id = ? ORDER BY kind, ref', [$projectId]) as $r) {
			$d = self::describe((string)$r['kind'], (string)$r['ref']);
			$out[] = ['id' => (string)$r['zzzzsys_nunext_project_item_id'], 'kind' => (string)$r['kind'], 'ref' => (string)$r['ref'], 'note' => (string)($r['note'] ?? ''),
				'label' => $d['label'] ?? (string)$r['ref'], 'sub' => $d['sub'] ?? '', 'url' => $d['url'] ?? '', 'missing' => $d === null,
				'added_at' => (string)($r['added_at'] ?? ''), 'added_by' => (string)($r['added_by'] ?? '')];
		}
		$order = array_flip(array_keys(self::KINDS));
		usort($out, static fn($a, $b) => [$order[$a['kind']] ?? 99, mb_strtolower($a['label'])] <=> [$order[$b['kind']] ?? 99, mb_strtolower($b['label'])]);
		return $out;
	}

	/** Refs of one kind in a project. @return list<string> */
	public static function refs(string $projectId, string $kind): array
	{
		if (!self::ready()) return [];
		$k = $projectId . '|' . $kind;
		return self::$cache['refs'][$k] ??= array_map('strval', array_column(nu_db()->all('SELECT ref FROM ' . self::I . ' WHERE project_id = ? AND kind = ?', [$projectId, $kind]), 'ref'));
	}

	/** Label, second line and link of an item; null when it does not exist (any more). */
	public static function describe(string $kind, string $ref): ?array
	{
		$k = $kind . '|' . $ref;
		if (array_key_exists($k, self::$cache['desc'] ?? [])) return self::$cache['desc'][$k];
		$db = nu_db();
		$d = null;
		try {
			if ($kind === 'table') {
				if (in_array(strtolower($ref), array_map('strtolower', $db->tableNames()), true)) $d = ['label' => $ref, 'sub' => '', 'url' => ''];
			} elseif (isset(self::KINDS[$kind])) {
				[$tab, $idc, $lab, $code] = self::KINDS[$kind];
				if ($db->tableExists($tab)) {
					$extra = $kind === 'helppage' ? " AND lang = ''" : '';
					$r = $db->one("SELECT $lab AS l, $code AS c FROM $tab WHERE $idc = ?$extra", [$ref]);
					if ($r) {
						$label = trim(strip_tags((string)$r['l']));
						$d = ['label' => $label !== '' ? $label : (string)$r['c'], 'sub' => $label !== '' ? (string)$r['c'] : '', 'url' => self::url($kind, $ref)];
					}
				}
			}
		} catch (Throwable) {
			$d = null;
		}
		return self::$cache['desc'][$k] = $d;
	}

	/** Where an item is opened. */
	public static function url(string $kind, string $ref): string
	{
		return match ($kind) {
			'form' => nu_url('builder', ['f' => $ref]),
			'procedure' => nu_url('procedures', ['id' => $ref]),
			'report' => nu_url('reports', ['id' => $ref]),
			'select' => nu_url('sqlbuilder', ['id' => $ref]),
			'emailtemplate' => nu_url('emailtemplates', ['id' => $ref]),
			'snippet' => nu_url('form', ['f' => 'nucodesnippet', 'r' => $ref]),
			'helppage' => nu_url('helppages', ['id' => $ref]),
			default => '',
		};
	}

	/** Adds items (kind, ref) that are not members yet. @param list<array{0: string, 1: string}> $list @return int added */
	public static function addItems(string $projectId, array $list): int
	{
		if (!self::load($projectId)) return 0;
		$db = nu_db();
		$have = [];
		foreach ($db->all('SELECT kind, ref FROM ' . self::I . ' WHERE project_id = ?', [$projectId]) as $r) $have[$r['kind'] . '|' . $r['ref']] = true;
		$n = 0;
		foreach ($list as [$kind, $ref]) {
			$kind = (string)$kind; $ref = mb_substr(trim((string)$ref), 0, 100);
			if (!isset(self::KINDS[$kind]) || $ref === '' || isset($have[$kind . '|' . $ref]) || self::describe($kind, $ref) === null) continue;
			$db->insert(self::I, ['zzzzsys_nunext_project_item_id' => nu_id(), 'project_id' => $projectId, 'kind' => $kind, 'ref' => $ref, 'note' => '',
				'added_at' => self::now(), 'added_by' => self::by()]);
			$have[$kind . '|' . $ref] = true;
			$n++;
		}
		unset(self::$cache['refs']);
		if ($n) { self::touch($projectId); NuLog::write('project_items_added', self::T, $projectId, ['count' => $n]); }
		return $n;
	}

	public static function removeItem(string $projectId, string $itemId): bool
	{
		$r = nu_db()->one('SELECT kind, ref FROM ' . self::I . ' WHERE zzzzsys_nunext_project_item_id = ? AND project_id = ?', [$itemId, $projectId]);
		if (!$r) return false;
		nu_db()->delete(self::I, 'zzzzsys_nunext_project_item_id', $itemId);
		unset(self::$cache['refs']);
		self::touch($projectId);
		NuLog::write('project_item_removed', self::T, $projectId, ['kind' => (string)$r['kind'], 'ref' => (string)$r['ref']]);
		return true;
	}

	private static function touch(string $projectId): void
	{
		nu_db()->update(self::T, ['updated_at' => self::now(), 'updated_by' => self::by()], 'zzzzsys_nunext_project_id', $projectId);
	}

	/** The projects an item belongs to: id => name. */
	public static function forItem(string $kind, string $ref): array
	{
		if (!self::ready()) return [];
		$o = [];
		foreach (nu_db()->all('SELECT p.zzzzsys_nunext_project_id AS id, p.name FROM ' . self::I . ' i JOIN ' . self::T . ' p ON p.zzzzsys_nunext_project_id = i.project_id WHERE i.kind = ? AND i.ref = ? ORDER BY p.name', [$kind, $ref]) as $r) $o[(string)$r['id']] = (string)$r['name'];
		return $o;
	}

	/**
	 * Items of a kind for the Add dialog: [ref, label, sub] matching $q (code / name), at most $max. Forms: not nuBuilder Forte's own
	 * (nu…); procedures: not the form hooks; tables: not the zzzzsys_ tables.
	 */
	public static function candidates(string $kind, string $q = '', int $max = 200): array
	{
		$db = nu_db();
		$out = [];
		$like = '%' . $q . '%';
		try {
			if ($kind === 'table') {
				foreach ($db->tableNames() as $n) {
					if (str_starts_with(strtolower($n), 'zzzzsys_') || str_starts_with($n, '___nu')) continue;
					if ($q !== '' && stripos($n, $q) === false) continue;
					$out[] = [$n, $n, ''];
				}
				sort($out);
				return array_slice($out, 0, $max);
			}
			if (!isset(self::KINDS[$kind])) return [];
			[$tab, $idc, $lab, $code] = self::KINDS[$kind];
			if (!$db->tableExists($tab)) return [];
			$where = ["($code LIKE ? OR $lab LIKE ?)"];
			$par = [$like, $like];
			if ($kind === 'form') $where[] = "$idc NOT LIKE 'nu%'";
			if ($kind === 'procedure') $where[] = NuProcedure::listCondition();
			if ($kind === 'select') $where[] = "COALESCE(sse_system, '') = ''";
			if ($kind === 'helppage') $where[] = "lang = ''";
			foreach ($db->page("SELECT $idc AS id, $lab AS l, $code AS c FROM $tab WHERE " . implode(' AND ', $where), $par, "$code", $max, 0) as $r) {
				$l = trim(strip_tags((string)$r['l']));
				$out[] = [(string)$r['id'], $l !== '' ? $l : (string)$r['c'], $l !== '' ? (string)$r['c'] : ''];
			}
		} catch (Throwable) {
		}
		return $out;
	}

	// ------------------------------------------------------------------ Suggest related

	/**
	 * Items the members use and that are not members yet: [kind, ref, label, sub, why] (why = text naming the member). Forms: sub-forms,
	 * lookups, run buttons (form / procedure / report), the table and the tables of the list SQL, procedures / reports named in the code;
	 * procedures / reports: their launch form, the report's data source; SQL builder queries: their tables.
	 */
	public static function suggest(string $projectId): array
	{
		$members = [];
		foreach (self::items($projectId) as $it) $members[$it['kind'] . '|' . $it['ref']] = $it;
		$out = [];
		foreach ($members as $m) {
			foreach (self::related($m['kind'], $m['ref']) as [$kind, $ref]) {
				$key = $kind . '|' . $ref;
				if (isset($members[$key])) continue;
				$d = self::describe($kind, $ref);
				if ($d === null) continue;
				$out[$key] ??= ['kind' => $kind, 'ref' => $ref, 'label' => $d['label'], 'sub' => $d['sub'], 'why' => []];
				if (!in_array($m['label'], $out[$key]['why'], true)) $out[$key]['why'][] = $m['label'];
			}
		}
		$order = array_flip(array_keys(self::KINDS));
		$out = array_values($out);
		usort($out, static fn($a, $b) => [$order[$a['kind']] ?? 99, mb_strtolower($a['label'])] <=> [$order[$b['kind']] ?? 99, mb_strtolower($b['label'])]);
		return $out;
	}

	/** What one item uses. @return list<array{0: string, 1: string}> [kind, ref] */
	public static function related(string $kind, string $ref): array
	{
		$db = nu_db();
		$out = [];
		$add = static function (string $k, string $r) use (&$out): void { if ($r !== '') $out[$k . '|' . $r] = [$k, $r]; };
		try {
			if ($kind === 'form') {
				$f = $db->one('SELECT sfo_table, sfo_browse_sql, sfo_javascript, sfo_browse_javascript, sfo_edit_javascript FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$ref]);
				if (!$f) return [];
				$add('table', (string)$f['sfo_table']);
				foreach (self::tablesIn((string)$f['sfo_browse_sql']) as $t) $add('table', $t);
				$code = (string)$f['sfo_javascript'] . "\n" . $f['sfo_browse_javascript'] . "\n" . $f['sfo_edit_javascript'];
				$objs = $db->all('SELECT zzzzsys_object_id, sob_all_type, sob_subform_zzzzsys_form_id, sob_lookup_zzzzsys_form_id, sob_run_zzzzsys_form_id, sob_run_type, sob_html_code, sob_input_javascript FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$ref]);
				$ids = [];
				foreach ($objs as $o) {
					$ids[] = (string)$o['zzzzsys_object_id'];
					$code .= "\n" . $o['sob_html_code'] . "\n" . $o['sob_input_javascript'];
					match ((string)$o['sob_all_type']) {
						'subform' => $add('form', (string)$o['sob_subform_zzzzsys_form_id']),
						'lookup' => $add('form', (string)$o['sob_lookup_zzzzsys_form_id']),
						'run' => $add(['P' => 'procedure', 'R' => 'report'][strtoupper(trim((string)$o['sob_run_type']))] ?? 'form', (string)$o['sob_run_zzzzsys_form_id']),
						default => null,
					};
				}
				foreach (array_chunk($ids, 400) as $chunk) {
					$in = implode(',', array_fill(0, count($chunk), '?'));
					foreach ($db->all("SELECT sev_javascript FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN ($in)", $chunk) as $e) $code .= "\n" . $e['sev_javascript'];
				}
				foreach ($db->all("SELECT zzzzsys_php_id, sph_php FROM zzzzsys_php WHERE zzzzsys_php_id LIKE ?", [$ref . '\_%']) as $p) $code .= "\n" . $p['sph_php'];
				foreach (self::calledIn($code) as $kr) $add($kr[0], $kr[1]);
				foreach ($db->all('SELECT zzzzsys_report_id FROM zzzzsys_report WHERE sre_zzzzsys_form_id = ?', [$ref]) as $r) $add('report', (string)$r['zzzzsys_report_id']);
				foreach ($db->all('SELECT zzzzsys_php_id FROM zzzzsys_php WHERE sph_zzzzsys_form_id = ?', [$ref]) as $r) $add('procedure', (string)$r['zzzzsys_php_id']);
				if ($db->tableExists('zzzzsys_email_template')) foreach ($db->all('SELECT zzzzsys_email_template_id FROM zzzzsys_email_template WHERE emt_form_id = ?', [$ref]) as $r) $add('emailtemplate', (string)$r['zzzzsys_email_template_id']);
			} elseif ($kind === 'procedure') {
				$p = $db->one('SELECT sph_php, sph_javascript, sph_zzzzsys_form_id FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$ref]);
				if (!$p) return [];
				$add('form', (string)$p['sph_zzzzsys_form_id']);
				foreach (self::calledIn((string)$p['sph_php'] . "\n" . $p['sph_javascript']) as $kr) $add($kr[0], $kr[1]);
				foreach (self::tablesIn((string)$p['sph_php']) as $t) $add('table', $t);
			} elseif ($kind === 'report') {
				$r = $db->one('SELECT sre_zzzzsys_php_id, sre_zzzzsys_form_id FROM zzzzsys_report WHERE zzzzsys_report_id = ?', [$ref]);
				if (!$r) return [];
				$add('form', (string)$r['sre_zzzzsys_form_id']);
				$src = (string)$r['sre_zzzzsys_php_id'];
				if (preg_match('/^(TABLE|SQL|PROCEDURE):(.+)$/', $src, $m)) $add(['TABLE' => 'table', 'SQL' => 'select', 'PROCEDURE' => 'procedure'][$m[1]], $m[2]);
				elseif ($src !== '') { if ($db->value('SELECT COUNT(*) FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$src])) $add('select', $src); else $add('procedure', $src); }
			} elseif ($kind === 'select') {
				$s = $db->one('SELECT sse_sql FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$ref]);
				if ($s) foreach (self::tablesIn((string)$s['sse_sql']) as $t) $add('table', $t);
			} elseif ($kind === 'emailtemplate') {
				$e = $db->one('SELECT emt_form_id FROM zzzzsys_email_template WHERE zzzzsys_email_template_id = ?', [$ref]);
				if ($e) $add('form', (string)$e['emt_form_id']);
			}
		} catch (Throwable) {
		}
		return array_values($out);
	}

	/** Existing tables / views named after FROM / JOIN / INTO / UPDATE in SQL text (not zzzzsys_). @return list<string> */
	public static function tablesIn(string $sql): array
	{
		if (!preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $sql, $m)) return [];
		$lower = array_change_key_case(array_flip(nu_db()->tableNames()), CASE_LOWER);
		$out = [];
		foreach (array_unique($m[1]) as $t) {
			$l = strtolower($t);
			if (isset($lower[$l]) && !str_starts_with($l, 'zzzzsys_')) $out[] = self::tableName($t);
		}
		return array_values(array_unique($out));
	}

	/** The table's name as the database spells it. */
	private static function tableName(string $t): string
	{
		foreach (nu_db()->tableNames() as $n) if (strcasecmp($n, $t) === 0) return $n;
		return $t;
	}

	/** Procedures / reports called by code or id in JavaScript / PHP. @return list<array{0: string, 1: string}> */
	public static function calledIn(string $code): array
	{
		$out = [];
		if (!preg_match_all('/\b(nuRunPHP(?:Hidden)?(?:WithParams)?|nuRunProcedure|nuProcedure|nuRunReport(?:Save)?|nuEval)\s*\(\s*([\'"])([^\'"]{1,100})\2/', $code, $m, PREG_SET_ORDER)) return [];
		$db = nu_db();
		foreach ($m as $x) {
			$name = $x[3];
			if (str_starts_with($x[1], 'nuRunReport')) {
				$id = $db->value('SELECT zzzzsys_report_id FROM zzzzsys_report WHERE sre_code = ? OR zzzzsys_report_id = ?', [$name, $name]);
				if ($id) $out[] = ['report', (string)$id];
			} else {
				$id = $db->value('SELECT zzzzsys_php_id FROM zzzzsys_php WHERE sph_code = ? OR zzzzsys_php_id = ?', [$name, $name]);
				if ($id && !preg_match('/_[A-Z]{2,3}$/', (string)$id)) $out[] = ['procedure', (string)$id];
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------ notes (Markdown)

	public static function notes(string $projectId): array
	{
		if (!nu_db()->tableExists(self::N)) return [];
		return nu_db()->all('SELECT * FROM ' . self::N . ' WHERE project_id = ? ORDER BY pinned DESC, updated_at DESC', [$projectId]);
	}

	/** @return array{0: string, 1: array} */
	public static function saveNote(string $projectId, string $id, array $in): array
	{
		$title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
		$body = mb_substr((string)($in['body'] ?? ''), 0, 200000);
		if ($title === '') return [$id, ['title' => t('err_required')]];
		$row = ['title' => $title, 'body' => str_replace("\r\n", "\n", $body), 'pinned' => !empty($in['pinned']) && $in['pinned'] !== '0' ? '1' : '0', 'updated_at' => self::now(), 'updated_by' => self::by()];
		$db = nu_db();
		$old = $id !== '' ? $db->one('SELECT zzzzsys_nunext_project_note_id FROM ' . self::N . ' WHERE zzzzsys_nunext_project_note_id = ? AND project_id = ?', [$id, $projectId]) : null;
		if ($old) $db->update(self::N, $row, 'zzzzsys_nunext_project_note_id', $id);
		else { $id = nu_id(); $db->insert(self::N, ['zzzzsys_nunext_project_note_id' => $id, 'project_id' => $projectId, 'created_at' => self::now(), 'created_by' => self::by()] + $row); }
		self::touch($projectId);
		NuLog::write('project_note_saved', self::T, $projectId, ['title' => $title]);
		return [$id, []];
	}

	public static function deleteNote(string $projectId, string $id): bool
	{
		$n = nu_db()->run('DELETE FROM ' . self::N . ' WHERE zzzzsys_nunext_project_note_id = ? AND project_id = ?', [$id, $projectId])->rowCount();
		if ($n) { self::touch($projectId); NuLog::write('project_note_deleted', self::T, $projectId, ['id' => $id]); }
		return $n > 0;
	}

	// ------------------------------------------------------------------ to-dos

	public static function tasks(string $projectId): array
	{
		if (!nu_db()->tableExists(self::K)) return [];
		return nu_db()->all('SELECT * FROM ' . self::K . " WHERE project_id = ? ORDER BY CASE WHEN status = 'done' THEN 1 ELSE 0 END, CASE WHEN due IS NULL THEN 1 ELSE 0 END, due, created_at", [$projectId]);
	}

	/** Open to-dos of the signed-in user in all projects (start page / project list). */
	public static function myTasks(): array
	{
		$u = NuAuth::user();
		if (!$u || !self::ready() || !nu_db()->tableExists(self::K)) return [];
		return nu_db()->all('SELECT k.*, p.name AS project_name FROM ' . self::K . ' k JOIN ' . self::T . " p ON p.zzzzsys_nunext_project_id = k.project_id WHERE k.assignee = ? AND k.status <> 'done' ORDER BY CASE WHEN k.due IS NULL THEN 1 ELSE 0 END, k.due", [(string)$u['id']]);
	}

	/** People a to-do can be assigned to: id => name ('globeadmin' = the globeadmin). */
	public static function people(): array
	{
		$o = ['globeadmin' => t('prj_globeadmin')];
		foreach (nu_db()->all("SELECT zzzzsys_user_id, sus_name, sus_login_name FROM zzzzsys_user WHERE COALESCE(sus_login_name, '') <> '' ORDER BY sus_name, sus_login_name") as $r) {
			$o[(string)$r['zzzzsys_user_id']] = trim((string)$r['sus_name']) !== '' ? trim((string)$r['sus_name']) . ' (' . $r['sus_login_name'] . ')' : (string)$r['sus_login_name'];
		}
		return $o;
	}

	/** @return array{0: string, 1: array} — a new / changed assignee gets a notification (not when it is the one saving). */
	public static function saveTask(string $projectId, string $id, array $in): array
	{
		$title = mb_substr(trim((string)($in['title'] ?? '')), 0, 300);
		if ($title === '') return [$id, ['title' => t('err_required')]];
		$due = trim((string)($in['due'] ?? ''));
		if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) return [$id, ['due' => t('ntf_err_date')]];
		$assignee = (string)($in['assignee'] ?? '');
		if ($assignee !== '' && !isset(self::people()[$assignee])) $assignee = '';
		$item = (string)($in['item'] ?? '');
		if ($item !== '' && !preg_match('/^[a-z]+\|.{1,100}$/', $item)) $item = '';
		$row = ['title' => $title, 'body' => mb_substr(trim((string)($in['body'] ?? '')), 0, 4000), 'assignee' => $assignee, 'due' => $due !== '' ? $due . ' 00:00:00' : null,
			'item' => $item, 'updated_at' => self::now(), 'updated_by' => self::by()];
		$db = nu_db();
		$old = $id !== '' ? $db->one('SELECT * FROM ' . self::K . ' WHERE zzzzsys_nunext_project_task_id = ? AND project_id = ?', [$id, $projectId]) : null;
		if ($old) {
			if ((string)$old['assignee'] !== $assignee || (string)substr((string)$old['due'], 0, 10) !== $due) $row['notified_at'] = null;
			$db->update(self::K, $row, 'zzzzsys_nunext_project_task_id', $id);
		} else {
			$id = nu_id();
			$db->insert(self::K, ['zzzzsys_nunext_project_task_id' => $id, 'project_id' => $projectId, 'status' => 'open', 'created_at' => self::now(), 'created_by' => self::by()] + $row);
		}
		self::touch($projectId);
		if ($assignee !== '' && (!$old || (string)$old['assignee'] !== $assignee) && $assignee !== (string)(NuAuth::user()['id'] ?? '')) self::notify($projectId, $id, 'prj_ntf_assigned');
		NuLog::write('project_task_saved', self::T, $projectId, ['title' => $title, 'assignee' => $assignee, 'due' => $due]);
		return [$id, []];
	}

	public static function setTaskStatus(string $projectId, string $id, bool $done): bool
	{
		$n = nu_db()->run('UPDATE ' . self::K . ' SET status = ?, done_at = ?, done_by = ?, updated_at = ? WHERE zzzzsys_nunext_project_task_id = ? AND project_id = ?',
			[$done ? 'done' : 'open', $done ? self::now() : null, $done ? self::by() : null, self::now(), $id, $projectId])->rowCount();
		if ($n) { self::touch($projectId); NuLog::write($done ? 'project_task_done' : 'project_task_reopened', self::T, $projectId, ['id' => $id]); }
		return $n > 0;
	}

	public static function deleteTask(string $projectId, string $id): bool
	{
		$n = nu_db()->run('DELETE FROM ' . self::K . ' WHERE zzzzsys_nunext_project_task_id = ? AND project_id = ?', [$id, $projectId])->rowCount();
		if ($n) { self::touch($projectId); NuLog::write('project_task_deleted', self::T, $projectId, ['id' => $id]); }
		return $n > 0;
	}

	/** A notification to the assignee of a to-do (notification centre). */
	private static function notify(string $projectId, string $taskId, string $textKey): void
	{
		try {
			$p = self::load($projectId);
			$k = nu_db()->one('SELECT * FROM ' . self::K . ' WHERE zzzzsys_nunext_project_task_id = ?', [$taskId]);
			if (!$p || !$k || (string)$k['assignee'] === '' || !NuNotify::ready()) return;
			$due = (string)$k['due'] !== '' ? date(nu_dfmt('d.m.Y'), (int)strtotime((string)$k['due'])) : '';
			NuNotify::save('', ['title' => t($textKey, (string)$k['title']), 'body' => t('prj_ntf_body', (string)$p['name']) . ($due !== '' ? ' · ' . t('prj_due_on', $due) : ''),
				'level' => $textKey === 'prj_ntf_due' ? 'warning' : 'info', 'link' => 'index.php?p=projects&id=' . rawurlencode($projectId) . '&tab=tasks',
				'audience' => ['users' => [(string)$k['assignee']]], 'mode' => 'fixed'], true, ['id' => '', 'login' => 'projects'], false);
		} catch (Throwable $e) {
			error_log('nuBuilder Next projects, notification: ' . $e->getMessage());
		}
	}

	/** Housekeeping: a notification for to-dos due today or earlier, once per to-do (and again after the due date or assignee changed). */
	public static function dueHousekeeping(): int
	{
		if (!self::ready() || !nu_db()->tableExists(self::K)) return 0;
		$n = 0;
		$rows = nu_db()->all('SELECT zzzzsys_nunext_project_task_id AS id, project_id FROM ' . self::K . " WHERE status <> 'done' AND assignee <> '' AND due IS NOT NULL AND due <= ? AND notified_at IS NULL", [date('Y-m-d 23:59:59')]);
		foreach ($rows as $r) {
			self::notify((string)$r['project_id'], (string)$r['id'], 'prj_ntf_due');
			nu_db()->update(self::K, ['notified_at' => self::now()], 'zzzzsys_nunext_project_task_id', (string)$r['id']);
			$n++;
		}
		return $n;
	}

	// ------------------------------------------------------------------ overview: recent changes

	/** Recent changes of the member forms (form history), open drafts. @return array{changes: list<array>, drafts: list<array>} */
	public static function changes(string $projectId, int $limit = 30): array
	{
		$forms = self::refs($projectId, 'form');
		$out = ['changes' => [], 'drafts' => []];
		if (!$forms) return $out;
		$db = nu_db();
		$in = implode(',', array_fill(0, count($forms), '?'));
		if ($db->tableExists('zzzzsys_nunext_form_history')) {
			$out['changes'] = $db->page("SELECT h.form_id, h.created_at, h.login, h.action FROM zzzzsys_nunext_form_history h WHERE h.form_id IN ($in)", $forms, 'h.created_at DESC', $limit, 0);
		}
		if (NuFormDraft::ready()) {
			$out['drafts'] = $db->all("SELECT form_id, created_by, created_at FROM zzzzsys_nunext_form_draft WHERE status IN ('open', 'review') AND form_id IN ($in)", $forms);
		}
		return $out;
	}

	// ------------------------------------------------------------------ working on a project

	/** The project the user works on ('' = none) – kept for the session. */
	public static function working(): string
	{
		$id = (string)($_SESSION[self::SESSION] ?? '');
		if ($id === '') return '';
		if (!isset(self::$cache['working'])) self::$cache['working'] = self::canView() && self::load($id) ? $id : '';
		return self::$cache['working'];
	}

	public static function setWorking(string $id): void
	{
		if ($id !== '' && !self::load($id)) $id = '';
		if ($id === '') unset($_SESSION[self::SESSION]); else $_SESSION[self::SESSION] = $id;
		unset(self::$cache['working']);
	}

	/**
	 * List filter "Project" for the forms / procedures / reports lists. While the user works on a project and the address has no
	 * filter of its own, the list starts filtered on it (the chip removes it). Refs are read first: no join across collations.
	 */
	public static function listFilter(string $kind, string $idExpr): ?array
	{
		if (!self::canView() || !self::ready()) return null;
		$w = self::working();
		if ($w !== '' && !array_key_exists('project', $_GET)) $_GET['project'] = $w;
		return ['label' => t('prj_title_one'), 'type' => 'select', 'options' => static fn() => self::choices(),
			'where' => static function (string $v) use ($kind, $idExpr): ?array {
				if ($v === '') return null;
				$refs = self::refs($v, $kind);
				if (!$refs) return ['1 = 0', []];
				return [$idExpr . ' IN (' . implode(', ', array_fill(0, count($refs), '?')) . ')', $refs];
			}];
	}
}
