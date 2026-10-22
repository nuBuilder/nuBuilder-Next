<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Form cloner (globeadmin, Forms → Cloner) – nuBuilder's "cloner" procedure, adapted.
 * Copies a form with its tabs, browse columns, form PHP (BB/BE/BS/AS/BD/AD …), select SQL builder rows, objects, object events
 * and object PHP (e.g. lookup After Browse) – as a new form, or as new tabs into an existing form; optionally with its subforms
 * and iframe (run method i) forms, and the access level rights. Or, instead of cloning, the same as an SQL dump.
 *
 * Improvements over nuBuilder's cloner:
 *   - everything in one transaction: an error leaves the database as it was;
 *   - the new form's code and description can be chosen (default <code>_clone_<n>, the first free number); the code is checked;
 *   - tabs are chosen by id, not by their order number; objects of tabs that are not cloned are reported;
 *   - subform / iframe references are changed in the cloned objects only (nuBuilder changed every form that used the subform);
 *     a subform used twice is cloned once;
 *   - into an existing form: new tabs after its own, object ids that exist there get a suffix (_2, _3 …) – reported;
 *   - object PHP keeps its code (nuBuilder's cloner lost it), events / PHP / select rows follow the new object ids;
 *   - optional: copy the rights of the access levels for the cloned forms;
 *   - the dump has a header, comments per table, NULL for empty columns, and runs on MySQL / MariaDB, MSSQL and SQLite
 *     (REPLACE INTO only for MySQL / SQLite); the result lists how many rows were copied per table.
 */
require_once NU_ROOT . '/core/form/nuhistory.php';

final class NuCloner
{
	/** Form PHP / object PHP: <form or object id>_<event> */
	private const HOOK_RE = '/^[A-Z]{2,3}$/';

	private NuDB $db;
	private array $o;
	private array $sql = [];
	private array $counts = [];
	private array $renamed = [];
	private array $warnings = [];
	private array $cloned = [];          // source form id => new form id (subforms used twice)
	private bool $hasSelect;
	private string $lastTable = '';

	/**
	 * Options: source, dest ('' = new form), code, description, tabs (tab ids, [] = all), objects, subforms (bool), subform_ids
	 * (object ids, [] = all), iframes (bool), iframe_ids, access (copy rights), dump, new_pks, replace, notes.
	 */
	public function __construct(NuDB $db, array $options)
	{
		$this->db = $db;
		$this->o  = $options + ['dest' => '', 'code' => '', 'description' => '', 'tabs' => [], 'objects' => true, 'subforms' => false, 'subform_ids' => [],
			'iframes' => false, 'iframe_ids' => [], 'access' => false, 'dump' => false, 'new_pks' => true, 'replace' => false, 'notes' => ''];
		if (!$this->o['dump']) { $this->o['new_pks'] = true; $this->o['replace'] = false; }   // keeping keys would collide with the source
		if ($db->type === 'sqlsrv') $this->o['replace'] = false;
		$this->hasSelect = $db->tableExists('zzzzsys_select') && $db->tableExists('zzzzsys_select_clause');
	}

	// ================================================================== information for the page

	/** Tabs (with object count), subform and iframe objects of a form. */
	public static function info(NuDB $db, string $formId): ?array
	{
		$f = $db->one('SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_type, sfo_table FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]);
		if (!$f) return null;
		$tabs = [];
		foreach ($db->all('SELECT zzzzsys_tab_id, syt_title, syt_order FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ? ORDER BY syt_order', [$formId]) as $t) {
			$tabs[] = ['id' => (string)$t['zzzzsys_tab_id'], 'title' => (string)$t['syt_title'],
				'objects' => (int)$db->value('SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_tab_id = ?', [$t['zzzzsys_tab_id']])];
		}
		$sub = $ifr = [];
		foreach ($db->all("SELECT zzzzsys_object_id, sob_all_id, sob_all_label, sob_all_type, sob_subform_zzzzsys_form_id, sob_run_zzzzsys_form_id, sob_run_method, sob_all_zzzzsys_tab_id
			FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_type IN ('subform', 'run') ORDER BY sob_all_order", [$formId]) as $o) {
			if ($o['sob_all_type'] === 'subform' && (string)$o['sob_subform_zzzzsys_form_id'] !== '') {
				$sub[] = ['id' => (string)$o['zzzzsys_object_id'], 'field' => (string)$o['sob_all_id'], 'tab' => (string)$o['sob_all_zzzzsys_tab_id'],
					'form' => (string)$o['sob_subform_zzzzsys_form_id'], 'label' => nu_form_label((string)$o['sob_subform_zzzzsys_form_id'])];
			} elseif ($o['sob_all_type'] === 'run' && $o['sob_run_method'] === 'i' && (string)$o['sob_run_zzzzsys_form_id'] !== ''
				&& $db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$o['sob_run_zzzzsys_form_id']])) {
				$ifr[] = ['id' => (string)$o['zzzzsys_object_id'], 'field' => (string)$o['sob_all_id'], 'tab' => (string)$o['sob_all_zzzzsys_tab_id'],
					'form' => (string)$o['sob_run_zzzzsys_form_id'], 'label' => nu_form_label((string)$o['sob_run_zzzzsys_form_id'])];
			}
		}
		return ['id' => (string)$f['zzzzsys_form_id'], 'code' => (string)$f['sfo_code'], 'description' => (string)$f['sfo_description'], 'type' => (string)$f['sfo_type'],
			'table' => (string)$f['sfo_table'], 'tabs' => $tabs, 'subforms' => $sub, 'iframes' => $ifr,
			'objects' => (int)$db->value('SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$formId]),
			'suggest' => self::freeCode($db, (string)$f['sfo_code'])];
	}

	/** <code>_clone_<n> with the first n that is not used yet. */
	public static function freeCode(NuDB $db, string $code): string
	{
		$base = preg_replace('/_clone_\d+$/', '', $code) ?? $code;
		for ($n = 1; $n < 1000; $n++) {
			$c = $base . '_clone_' . $n;
			if (!(int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ?', [$c])) return $c;
		}
		return $base . '_clone_' . substr(nu_id(), -6);
	}

	// ================================================================== run

	/** @return array{form_id: string, code: string, sql: ?string, counts: array, renamed: array, warnings: array} */
	public function run(): array
	{
		$src = (string)$this->o['source'];
		$f1  = $this->db->one('SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$src]);
		if (!$f1) throw new RuntimeException(t('cl_err_source'));
		$dest = (string)$this->o['dest'];
		if ($dest !== '' && !$this->db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$dest])) throw new RuntimeException(t('cl_err_dest'));
		if ($dest === $src) throw new RuntimeException(t('cl_err_same'));
		if ($dest === '' && $this->o['new_pks']) {
			$code = trim((string)$this->o['code']) ?: self::freeCode($this->db, (string)$f1['sfo_code']);
			if ((int)$this->db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ?', [$code])) throw new RuntimeException(t('cl_err_code', $code));
			$this->o['code'] = $code;
		}

		$work = function () use ($src, $dest): string {
			if ($this->o['dump']) $this->header($src);
			if ($dest === '') {
				$f2 = $this->cloneForm($src, $this->o['code'] !== '' ? (string)$this->o['code'] : null, trim((string)$this->o['description']) ?: null);
				$tabMap = $this->cloneTabs($src, $f2, $this->o['tabs'], '', 0);
			} else {
				$f2 = $dest;
				$after = (int)$this->db->value('SELECT COALESCE(MAX(syt_order), 0) FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ?', [$dest]);
				$tabMap = $this->cloneTabs($src, $f2, $this->o['tabs'], ' (' . t('cl_copy') . ')', $after);
			}
			if ($this->o['objects']) {
				$objMap = $this->cloneObjects($src, $f2, $tabMap, $dest !== '');
				$this->followForms($objMap, 'subform', 'sob_subform_zzzzsys_form_id', (bool)$this->o['subforms'], (array)$this->o['subform_ids']);
				$this->followForms($objMap, 'run', 'sob_run_zzzzsys_form_id', (bool)$this->o['iframes'], (array)$this->o['iframe_ids']);
			}
			if ($this->o['access']) $this->cloneAccess();
			return $f2;
		};
		$f2 = $this->o['dump'] ? $work() : $this->db->transaction(static fn() => $work());

		if (!$this->o['dump']) {                // after the commit: a problem here must not report the clone as failed
			try { NuFormHistory::snapshot($f2, 'cloned from ' . $f1['sfo_code']); } catch (Throwable $e) { error_log('nuBuilder Next cloner history: ' . $e->getMessage()); }
			NuLog::write('form_cloned', 'zzzzsys_form', $f2, ['from' => (string)$f1['sfo_code'], 'counts' => $this->counts]);
		}
		$code = (string)($this->db->value('SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$f2]) ?? $this->o['code']);
		return ['form_id' => $f2, 'code' => $code !== '' ? $code : (string)$this->o['code'], 'sql' => $this->o['dump'] ? implode("\n", $this->sql) . "\n" : null,
			'counts' => $this->counts, 'renamed' => $this->renamed, 'warnings' => $this->warnings];
	}

	/** LIKE pattern "<id>_…" (use with ESCAPE '!'). */
	private static function prefix(string $id): string { return str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $id) . '!_%'; }

	private function pk(string $old): string { return $this->o['new_pks'] ? nu_id() : $old; }

	/** Insert (or add to the dump). $label: comment above the first row of a table. */
	private function write(string $table, array $row, string $label = ''): void
	{
		$this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
		if (!$this->o['dump']) { $this->db->insert($table, $row); return; }
		if ($this->lastTable !== $table . $label) {
			$this->sql[] = "\n-- " . $table . ($label !== '' ? ': ' . $label : '');
			$this->lastTable = $table . $label;
		}
		$vals = array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $this->db->pdo()->quote((string)$v)), array_values($row));
		$this->sql[] = ($this->o['replace'] ? 'REPLACE INTO ' : 'INSERT INTO ') . $table . ' (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', $vals) . ');';
	}

	private function header(string $src): void
	{
		$f = $this->db->one('SELECT sfo_code, sfo_description, sfo_table, sfo_type FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$src]);
		$this->sql[] = '-- nuBuilder Next cloner – SQL dump';
		$this->sql[] = '-- Created: ' . date('Y-m-d H:i:s') . ' · nuBuilder Next ' . NU_VERSION . ' · database type ' . $this->db->type;
		$this->sql[] = '-- Form: ' . $f['sfo_code'] . ' – ' . $f['sfo_description'] . ' · table ' . $f['sfo_table'] . ' · type ' . $f['sfo_type'];
		$this->sql[] = '-- New primary keys: ' . ($this->o['new_pks'] ? 'yes' : 'no (same ids as the source)');
		if (trim((string)$this->o['notes']) !== '') foreach (preg_split('/\R/', trim((string)$this->o['notes'])) as $l) $this->sql[] = '-- Notes: ' . $l;
	}

	// ------------------------------------------------------------------ form, tabs, browse, PHP, selects

	private function cloneForm(string $f1, ?string $code, ?string $description): string
	{
		if (isset($this->cloned[$f1])) return $this->cloned[$f1];
		$row = $this->db->one('SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$f1]);
		$f2  = $this->pk($f1);
		$row['zzzzsys_form_id'] = $f2;
		if ($this->o['new_pks']) $row['sfo_code'] = $code ?? self::freeCode($this->db, (string)$row['sfo_code']);
		if ($description !== null) $row['sfo_description'] = $description;
		$this->write('zzzzsys_form', $row, (string)$row['sfo_code']);
		$this->cloned[$f1] = $f2;

		foreach ($this->db->all('SELECT * FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = ? ORDER BY sbr_order', [$f1]) as $b) {
			$b['zzzzsys_browse_id'] = $this->pk((string)$b['zzzzsys_browse_id']);
			$b['sbr_zzzzsys_form_id'] = $f2;
			$this->write('zzzzsys_browse', $b);
		}
		$this->cloneHooks($f1, $f2);
		$this->cloneSelects($f1, $f2);
		return $f2;
	}

	/** zzzzsys_php rows <old>_<EV> → <new>_<EV> (form hooks, object PHP such as lookup After Browse). */
	private function cloneHooks(string $old, string $new): void
	{
		foreach ($this->db->all('SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id LIKE ? ESCAPE \'!\'', [self::prefix($old)]) as $p) {
			$ev = substr((string)$p['zzzzsys_php_id'], strlen($old) + 1);
			if (!preg_match(self::HOOK_RE, $ev)) continue;
			$p['zzzzsys_php_id'] = $new . '_' . $ev;
			if ((string)$p['sph_code'] === $old . '_' . $ev || $this->o['new_pks']) $p['sph_code'] = $new . '_' . $ev;
			$this->write('zzzzsys_php', $p);
		}
	}

	/** nuBuilder's SQL builder (zzzzsys_select / _clause) of a form or object: ids <old>_<EV>. Not in every nuBuilder version. */
	private function cloneSelects(string $old, string $new): void
	{
		if (!$this->hasSelect) return;
		foreach ($this->db->all('SELECT * FROM zzzzsys_select WHERE zzzzsys_select_id LIKE ? ESCAPE \'!\'', [self::prefix($old)]) as $s) {
			$oldId = (string)$s['zzzzsys_select_id'];
			$ev = substr($oldId, strlen($old) + 1);
			if (!preg_match(self::HOOK_RE, $ev)) continue;
			$s['zzzzsys_select_id'] = $new . '_' . $ev;
			$this->write('zzzzsys_select', $s);
			foreach ($this->db->all('SELECT * FROM zzzzsys_select_clause WHERE ssc_zzzzsys_select_id = ?', [$oldId]) as $c) {
				$c['zzzzsys_select_clause_id'] = $this->pk((string)$c['zzzzsys_select_clause_id']);
				$c['ssc_zzzzsys_select_id'] = $new . '_' . $ev;
				$this->write('zzzzsys_select_clause', $c);
			}
		}
	}

	/** @return array<string, string> old tab id => new tab id */
	private function cloneTabs(string $f1, string $f2, array $only, string $suffix, int $after): array
	{
		$map = [];
		$n = 0;
		foreach ($this->db->all('SELECT * FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ? ORDER BY syt_order', [$f1]) as $t) {
			if ($only && !in_array((string)$t['zzzzsys_tab_id'], $only, true)) continue;
			$new = $this->pk((string)$t['zzzzsys_tab_id']);
			$map[(string)$t['zzzzsys_tab_id']] = $new;
			$t['zzzzsys_tab_id'] = $new;
			$t['syt_zzzzsys_form_id'] = $f2;
			$t['syt_title'] = $t['syt_title'] . $suffix;
			if ($after > 0) $t['syt_order'] = $after + 10 * (++$n);
			$this->write('zzzzsys_tab', $t);
		}
		return $map;
	}

	// ------------------------------------------------------------------ objects, events, object PHP

	/** @return array<string, array> new object id => source object row (for subforms / iframes) */
	private function cloneObjects(string $f1, string $f2, array $tabMap, bool $intoExisting): array
	{
		$taken = $intoExisting ? array_fill_keys(array_map('strtolower', array_column($this->db->all('SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$f2]), 'sob_all_id')), true) : [];
		$out = [];
		$skipped = 0;
		foreach ($this->db->all('SELECT * FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? ORDER BY sob_all_order', [$f1]) as $o) {
			$oldId = (string)$o['zzzzsys_object_id'];
			if (!isset($tabMap[(string)$o['sob_all_zzzzsys_tab_id']])) { $skipped++; continue; }
			$new = $this->pk($oldId);
			$src = $o;
			$o['zzzzsys_object_id'] = $new;
			$o['sob_all_zzzzsys_form_id'] = $f2;
			$o['sob_all_zzzzsys_tab_id'] = $tabMap[(string)$o['sob_all_zzzzsys_tab_id']];
			$field = (string)$o['sob_all_id'];
			if (isset($taken[strtolower($field)])) {
				for ($i = 2; isset($taken[strtolower($field . '_' . $i)]); $i++);
				$o['sob_all_id'] = $field . '_' . $i;
				$this->renamed[] = [$field, $o['sob_all_id']];
			}
			$taken[strtolower((string)$o['sob_all_id'])] = true;
			$this->write('zzzzsys_object', $o);
			foreach ($this->db->all('SELECT * FROM zzzzsys_event WHERE sev_zzzzsys_object_id = ?', [$oldId]) as $e) {
				$e['zzzzsys_event_id'] = $this->pk((string)$e['zzzzsys_event_id']);
				$e['sev_zzzzsys_object_id'] = $new;
				$this->write('zzzzsys_event', $e);
			}
			$this->cloneHooks($oldId, $new);
			$this->cloneSelects($oldId, $new);
			$out[$new] = $src;
		}
		if ($skipped) $this->warnings[] = t('cl_warn_skipped', $skipped);
		return $out;
	}

	/**
	 * Subform / iframe objects of the cloned objects: clone their forms too (each once) and point the cloned objects – only those –
	 * to the copy.
	 */
	private function followForms(array $objMap, string $type, string $col, bool $on, array $onlyObjects): void
	{
		if (!$on) return;
		foreach ($objMap as $newObj => $src) {
			if ($src['sob_all_type'] !== $type || (string)$src[$col] === '') continue;
			if ($type === 'run' && $src['sob_run_method'] !== 'i') continue;
			if ($onlyObjects && !in_array((string)$src['zzzzsys_object_id'], $onlyObjects, true)) continue;
			$form = (string)$src[$col];
			if (!$this->db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$form])) continue;
			$isNew = !isset($this->cloned[$form]);
			$copy = $this->cloneForm($form, null, null);
			if ($isNew) {
				$tabs = $this->cloneTabs($form, $copy, [], '', 0);
				$this->cloneObjects($form, $copy, $tabs, false);
			}
			if (!$this->o['new_pks']) continue;                     // same ids: the reference stays right
			if ($this->o['dump']) {
				$this->sql[] = "\n-- zzzzsys_object: the cloned " . $type . ' object points to the copy of ' . $form;
				$this->sql[] = 'UPDATE zzzzsys_object SET ' . $col . ' = ' . $this->db->pdo()->quote($copy) . ' WHERE zzzzsys_object_id = ' . $this->db->pdo()->quote((string)$newObj) . ';';
			} else {
				$this->db->update('zzzzsys_object', [$col => $copy], 'zzzzsys_object_id', (string)$newObj);
			}
		}
	}

	/** Rights of the access levels: every level that may use a source form may use its copy (same buttons). */
	private function cloneAccess(): void
	{
		foreach ($this->cloned as $old => $new) {
			foreach ($this->db->all('SELECT * FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = ?', [$old]) as $r) {
				$r['zzzzsys_access_form_id'] = $this->pk((string)$r['zzzzsys_access_form_id']);
				$r['slf_zzzzsys_form_id'] = $new;
				$this->write('zzzzsys_access_form', $r);
			}
		}
	}
}
