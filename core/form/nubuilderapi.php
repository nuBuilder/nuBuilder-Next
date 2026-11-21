<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nubuilderapi.php (code review R7: a page defines no global function). */
final class NuBuilderApi
{
	public static function out(array $d, int $status = 200): never
	{
		nu_json($d, $status);
	}

	public static function err(string $msg, int $status = 400): never
	{
		NuBuilderApi::out(['ok' => false, 'error' => $msg], $status);
	}

	/**
	 * Unique tab names: a tab that is new or renamed may not take the name of another tab of the form (compared without HTML,
	 * spaces and case). Tabs a nuBuilder Forte form already has with the same name stay as they are, so such a form can still be saved.
	 * @param array<int, mixed> $rows the list sent (id, title)
	 * @param array<int, array<string, mixed>> $cur the form's tabs now (zzzzsys_tab_id, syt_title)
	 */
	public static function tabDupe(array $rows, array $cur): void
	{
		$norm = static fn($v): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$v), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''));
		$was = [];
		foreach ($cur as $c) $was[(string)$c['zzzzsys_tab_id']] = trim((string)$c['syt_title']);
		$names = [];
		foreach (array_values($rows) as $i => $t) $names[$i] = $norm(trim((string)(is_array($t) ? ($t['title'] ?? '') : '')) ?: 'Tab');
		foreach (array_values($rows) as $i => $t) {
			if (!is_array($t)) continue;
			$id = (string)($t['id'] ?? '');
			$title = trim((string)($t['title'] ?? '')) ?: 'Tab';
			if ($id !== '' && array_key_exists($id, $was) && $was[$id] === $title) continue;   // unchanged
			foreach ($names as $k => $n) if ($k !== $i && $n === $names[$i]) NuBuilderApi::err(sprintf(t('ds_tab_dupe'), trim(strip_tags($title))), 409);
		}
	}

	/**
	 * A view picked in the New form wizard: null for a table, else {updatable: true | false | null (unknown), key: suggested
	 * key column, unique: true | false | null (not checked)}. Updatable: MySQL / MariaDB say so in information_schema.VIEWS.IS_UPDATABLE;
	 * SQL Server from the view's text (GROUP BY, DISTINCT, UNION, TOP, aggregates → not). Key: <view>_id, else the first column ending in
	 * _id, else id, else the first column; unique = no value twice (read only, errors → null).
	 */
	public static function viewInfo(string $t, array $cols): ?array
	{
		$db = nu_db();
		if ($t === '' || !$cols || !preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $t)) return null;
		try {
			$type = match ($db->type) {
				'mysql'  => $db->value('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]),
				'sqlsrv' => $db->value('SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?', [$t]),
				default  => $db->value("SELECT CASE type WHEN 'table' THEN 'BASE TABLE' ELSE 'VIEW' END FROM sqlite_master WHERE name = ?", [$t]),
			};
		} catch (Throwable) { return null; }
		if (strtoupper((string)$type) !== 'VIEW') return null;
		$upd = null;
		try {
			if ($db->type === 'mysql') {
				$v = $db->value('SELECT IS_UPDATABLE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
				if ($v !== null) $upd = strtoupper((string)$v) === 'YES';
			} elseif ($db->type === 'sqlsrv') {
				$def = (string)$db->value('SELECT m.definition FROM sys.sql_modules m WHERE m.object_id = OBJECT_ID(?)', [$t]);
				if ($def !== '') $upd = NuBuilderApi::viewTextUpdatable($def);
			} else {
				$upd = false;                                                     // SQLite views are read only
			}
		} catch (Throwable $ig) { nu_ignored($ig); }
		$lc  = array_map('strtolower', $cols);
		$key = null;
		foreach ([strtolower($t) . '_id', 'id'] as $want) if (($i = array_search($want, $lc, true)) !== false) { $key = $cols[$i]; break; }
		if ($key === null) foreach ($cols as $c) if (preg_match('/_id$/i', $c)) { $key = $c; break; }
		$key ??= $cols[0];
		$unique = null;
		try {
			$k = NuDB::ident($key);
			$unique = (int)$db->readOnly(static fn(NuDB $d) => $d->value('SELECT COUNT(*) - COUNT(DISTINCT ' . $k . ') FROM ' . NuDB::ident($t))) === 0;   // COUNT(DISTINCT) skips NULLs too
		} catch (Throwable $ig) { nu_ignored($ig); }
		return ['updatable' => $upd, 'key' => $key, 'unique' => $unique];
	}

	/** SQL Server view text → can it be written to? Not with GROUP BY, DISTINCT, UNION / EXCEPT / INTERSECT, TOP or aggregates. */
	public static function viewTextUpdatable(string $def): bool
	{
		$sql = (string)preg_replace(['/--[^\n]*/', '~/\*.*?\*/~s', "/'(?:[^']|'')*'/"], ' ', $def);
		return !preg_match('/\b(GROUP\s+BY|DISTINCT|UNION|EXCEPT|INTERSECT|PIVOT)\b|\bTOP\s*[\d(]|\b(SUM|COUNT|AVG|MIN|MAX)\s*\(/i', $sql);
	}

	/**
	 * The form's table as offered in "Delete form" (the globeadmin only, like deleting the form itself), only a real table
	 * (no view, no zzzzsys table) that no other form uses. Deleting it also needs a recent password confirmation.
	 */
	public static function tableInfo(NuFormMeta $meta): array
	{
		$db = nu_db();
		$t  = $meta->table();
		$r  = ['table' => $t, 'exists' => false, 'rows' => null, 'used_by' => [], 'can_drop' => false, 'reason' => '', 'reauth' => false];
		if ($t === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $t)) return $r;
		$type = match ($db->type) {
			'mysql'  => $db->value('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]),
			'sqlsrv' => $db->value('SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?', [$t]),
			default  => $db->value("SELECT CASE type WHEN 'table' THEN 'BASE TABLE' ELSE 'VIEW' END FROM sqlite_master WHERE name = ?", [$t]),
		};
		if ($type === null) return $r;
		$r['exists'] = true;
		$r['used_by'] = array_column($db->all('SELECT sfo_code FROM zzzzsys_form WHERE sfo_table = ? AND zzzzsys_form_id <> ? ORDER BY sfo_code', [$t, $meta->id]), 'sfo_code');
		if (strtoupper((string)$type) !== 'BASE TABLE') $r['reason'] = 'view';
		elseif (stripos($t, 'zzzzsys') === 0) $r['reason'] = 'system';
		elseif ($r['used_by']) $r['reason'] = 'used';
		elseif (empty(NuAuth::user()['globeadmin'])) $r['reason'] = 'globeadmin';     // not reached: form.delete is globeadmin only
		else {
			$r['can_drop'] = true;
			try { $r['rows'] = (int)$db->value('SELECT COUNT(*) FROM ' . NuDB::ident($t)); } catch (Throwable $ig) { nu_ignored($ig); }
			$r['reauth'] = !NuSecurity::recentAuth();
		}
		return $r;
	}

	/**
	 * Several objects moved / resized at once in the preview (Arrange panel): [{object_id, top, left, width, height}] → columns
	 * of zzzzsys_object (whole pixels, never below 0 / 10). @return array<string, array<string, int>> object id => fields
	 */
	public static function geometry(array $items): array
	{
		$out = [];
		foreach (array_slice($items, 0, 500) as $it) {
			if (!is_array($it) || (string)($it['object_id'] ?? '') === '') continue;
			$f = [];
			foreach (['top' => 'sob_all_top', 'left' => 'sob_all_left'] as $k => $col) if (isset($it[$k]) && is_numeric($it[$k])) $f[$col] = max(0, (int)round((float)$it[$k]));
			foreach (['width' => 'sob_all_width', 'height' => 'sob_all_height'] as $k => $col) if (isset($it[$k]) && is_numeric($it[$k])) $f[$col] = max(10, (int)round((float)$it[$k]));
			if ($f) $out[(string)$it['object_id']] = $f;
		}
		return $out;
	}

	/** Design mode: where the password confirmation leads back to – only a form page of this installation (no other site). */
	public static function back(array $in): ?string
	{
		$b = (string)($in['back'] ?? '');
		return preg_match('/^index\.php\?p=form&[A-Za-z0-9_&=%.\-]*$/', $b) ? $b : null;
	}

	/** The form being edited – must exist and not be a nuBuilder system form. */
	/** Validation rules: parse an expression / test rules against a saved record – read only, the same on live forms and drafts. */
	public static function rules(NuFormMeta $meta, string $action, array $in): never
	{
		if ($action === 'rules.parse') {
			$p = NuFormRules::parse((string)($in['expr'] ?? ''));
			NuBuilderApi::out(is_string($p) ? ['ok' => false, 'error' => $p] : ['ok' => true, 'tree' => $p, 'text' => NuFormRules::text($p)]);
		}
		$cl = NuFormRules::clean(is_array($in['rules'] ?? null) ? $in['rules'] : [], $meta);
		if ($cl['errors']) NuBuilderApi::out(['ok' => false, 'error' => implode("\n", $cl['errors']), 'errors' => $cl['errors']]);
		$rid = trim((string)($in['record_id'] ?? ''));
		if ($rid === '') {
			$pk = $meta->primaryKey();
			$rid = $meta->table() !== ''
				&& $pk !== '' ? (string)(nu_db()->page('SELECT ' . NuDB::ident($pk) . ' AS k FROM ' . NuDB::ident($meta->table()), [], NuDB::ident($pk) . ' DESC', 1, 0)[0]['k'] ?? '') : '';
		}
		if ($rid === '') NuBuilderApi::err(t('vr_test_none'));
		NuBuilderApi::out(['record_id' => $rid] + NuFormRules::test($meta, $cl['rules'], $rid));
	}

	public static function form(string $id): NuFormMeta
	{
		$meta = NuFormMeta::load($id, true);                 // the builder edits the live form (drafts: NuDraftStore)
		if (!$meta) NuBuilderApi::err(t('form_err_not_found'), 404);
		if ($meta->isSystem() && !$meta->isUserHome()) NuBuilderApi::err(t('builder_err_system'), 403);   // User Home: tabs, objects, Before Edit
		return $meta;
	}

	public static function clean(array $fields, array $allowed): array
	{
		$out = [];
		foreach ($allowed as $k) {
			if (!array_key_exists($k, $fields)) continue;
			$v = $fields[$k];
			if (is_bool($v)) $v = $v ? '1' : '0';
			if (is_array($v)) continue;
			$v = $v === null ? null : (string)$v;
			if (in_array($k, NB_INT_FIELDS, true)) $v = ($v === null || trim($v) === '') ? null : (string)(int)$v;
			elseif ($v !== null && trim($v) === '') $v = null;
			$out[$k] = $v;
		}
		return $out;
	}

	public static function upsertPhp(string $id, ?string $code): void
	{
		$db = nu_db();
		$exists = (bool)$db->value('SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$id]);
		$code = ($code !== null && trim($code) !== '') ? $code : null;
		if ($exists) {
			$db->update('zzzzsys_php', ['sph_php' => $code], 'zzzzsys_php_id', $id);
		} elseif ($code !== null) {
			$db->insert('zzzzsys_php', ['zzzzsys_php_id' => $id, 'sph_code' => $id, 'sph_description' => 'System PHP', 'sph_group' => 'nubuilder',
				'sph_php' => $code, 'sph_system' => '1', 'sph_global' => '0', 'sph_hide' => '1']);
		}
	}

	/**
	 * Column proposal when a saved object stores a value but its form's table has no such column:
	 * ['name' => …, 'type' => …, 'length' => …, 'table' => …] or null.
	 */
	public static function missingColumn(NuFormMeta $meta, string $objectId): ?array
	{
		$table = $meta->table();
		if ($table === '' || $meta->type() === 'launch') return null;
		$o = nu_db()->one('SELECT * FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [$objectId]);
		if (!$o) return null;
		$proposal = NuDDL::typeForObject($o);
		if ($proposal === null) return null;
		$cols = nu_db()->columns($table);
		if (!$cols || NuRecord::column($cols, (string)$o['sob_all_id']) !== null) return null;
		return ['name' => (string)$o['sob_all_id'], 'type' => $proposal[0], 'length' => $proposal[1], 'table' => $table];
	}

	/** Builder view of a form: everything the client needs to render lists and panels. */
	public static function state(string $formId): array
	{
		NuFormMeta::forget($formId);
		$meta = NuFormMeta::load($formId, true);
		$db   = nu_db();
		// three queries instead of one per hook / object / lookup (the state is fetched after every change in design mode)
		$php  = array_fill_keys(NB_HOOKS, '');
		$ab   = [];
		$objIds = array_map(static fn($o) => (string)$o['zzzzsys_object_id'], $meta->objects);
		foreach ($meta->objects as $o) if ($o['sob_all_type'] === 'lookup') $ab[$o['zzzzsys_object_id']] = '';
		$phpIds = array_merge(array_map(static fn($h) => $formId . '_' . $h, NB_HOOKS), array_map(static fn($id) => $id . '_AB', array_keys($ab)));
		foreach (array_chunk($phpIds, 200) as $chunk) {
			foreach ($db->all('SELECT zzzzsys_php_id AS id, sph_php FROM zzzzsys_php WHERE zzzzsys_php_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
				$pid = (string)$r['id'];
				if (str_ends_with($pid, '_AB') && isset($ab[substr($pid, 0, -3)])) $ab[substr($pid, 0, -3)] = (string)$r['sph_php'];
				elseif (str_starts_with($pid, $formId . '_')) $php[substr($pid, strlen($formId) + 1)] = (string)$r['sph_php'];
			}
		}
		$events = array_fill_keys($objIds, []);
		foreach (array_chunk($objIds, 200) as $chunk) {
			foreach ($db->all('SELECT sev_zzzzsys_object_id AS oid, sev_event AS event, sev_javascript AS js FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN ('
				. implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY sev_event', $chunk) as $r) {
				$events[(string)$r['oid']][] = ['event' => $r['event'], 'js' => $r['js']];
			}
		}
		$objects = array_map(static function ($o) { unset($o['tab_index']); return $o; }, $meta->objects);
		return [
			'form' => $meta->form + ['layout' => $meta->layout()], 'tabs' => $meta->tabs, 'objects' => $objects, 'browse' => $meta->browse,
			'events' => (object)$events, 'php' => $php, 'lookup_php' => (object)$ab, 'readonly' => $meta->isSystem(),
			'draft' => null, 'live_draft' => $meta->isSystem() ? null : NuBuilderApi::liveDraft($formId),   // form drafts
		];
	}

	/**
	 * Form drafts: the builder's actions on the open draft $store (draft_id + rev from the builder) – the same rules as the live
	 * actions below, on the draft's snapshot (NuDraftStore). Every change is a new revision of the draft (undo list); nothing reaches
	 * nuBuilder's tables before draft.publish.
	 */
	public static function draft(NuDraftStore $store, string $action, array $in): never
	{
		$out = static fn(array $extra = []) => NuBuilderApi::out(['ok' => true] + $extra + $store->state(NB_HOOKS));
		switch ($action) {
			case 'state':
				$out();

			case 'history.list':
				NuBuilderApi::out(['ok' => true, 'history' => NuFormHistory::list($store->formId)]);

			case 'history.diff':           // design mode: what changed from a history entry to the draft
				NuBuilderApi::out(['ok' => true, 'diff' => NuFormSnapshot::diff(NuFormHistory::get($store->formId, (string)($in['id'] ?? '')), $store->snapshot())]);

			case 'history.restore':
			case 'history.undo':
				NuBuilderApi::err(t('builder_draft_use_undo'));

			case 'form.save':
				$fields = NuBuilderApi::clean(is_array($in['fields'] ?? null) ? $in['fields'] : [], NB_FORM_FIELDS);
				$layout = null;
				if (array_key_exists('layout', $in['fields'] ?? [])) $layout = in_array($in['fields']['layout'], ['absolute', 'auto', 'grid'], true) ? (string)$in['fields']['layout'] : 'absolute';
				$store->formSave($fields, $layout, is_array($in['php'] ?? null) ? $in['php'] : null, NB_HOOKS,
					NuFormMeta::tagsIn(is_array($in['fields'] ?? null) ? $in['fields'] : []) + NuFormMeta::recordCheckIn(is_array($in['fields']
						?? null) ? $in['fields'] : []) + NuUserHelp::formKeysIn(is_array($in['fields']
						?? null) ? $in['fields'] : []) + NuRowDetails::keysIn(is_array($in['fields']
						?? null) ? $in['fields'] : []) + NuCalendar::keysIn(is_array($in['fields'] ?? null) ? $in['fields'] : []) + NuRecycle::keysIn(is_array($in['fields']
						?? null) ? $in['fields'] : []));
				$store->commit('form.save');
				$out();

			case 'rules.save':             // validation rules
				$cl = NuFormRules::clean(is_array($in['rules'] ?? null) ? $in['rules'] : [], $store->meta());
				if ($cl['errors']) NuBuilderApi::out(['ok' => false, 'error' => implode("\n", $cl['errors']), 'errors' => $cl['errors']], 400);
				$store->rulesSave($cl['rules']);
				$store->commit('rules.save');
				$out(['rules' => $cl['rules']]);

			case 'rules.parse':
			case 'rules.test':
				NuBuilderApi::rules($store->meta(), $action, $in);

			case 'object.clone':                                   // object menu → Actions → Clone, then as object.save
				if ($action === 'object.clone') {
					$cid = (string)($in['object_id'] ?? '');
					$snap = $store->snapshot();
					NuBuilderApi::clonePrep($in, $store->object($cid), array_map(static fn($o) => (string)$o['sob_all_id'], $snap['objects'] ?? []),
						array_values(array_filter($snap['events'] ?? [], static fn($e) => (string)$e['sev_zzzzsys_object_id'] === $cid)), $store->php($cid . '_AB'));
				}
				// no break: the copy is saved as a new object
			case 'object.save':
				$fields = NuBuilderApi::clean(is_array($in['fields'] ?? null) ? $in['fields'] : [], NB_OBJECT_FIELDS);
				foreach (['sob_input_format', 'sob_calc_format'] as $fk) {
					if (isset($fields[$fk]) && mb_strlen((string)$fields[$fk]) > NuFormat::MAX_KEY) NuBuilderApi::err(t('fmt_err_length', NuFormat::MAX_KEY - 2));
					NuFormat::ensureSaved($fields[$fk] ?? null);       // shared format row – harmless while drafting
				}
				// the same checks as the live save (design mode saves into drafts)
				if (isset($fields['sob_all_id'])) $fields['sob_all_id'] = trim((string)$fields['sob_all_id']);
				if (isset($fields['sob_input_icon']) && mb_strlen((string)$fields['sob_input_icon']) > 50) NuBuilderApi::err(t('builder_obj_icon') . ': ' . t('err_too_long', 50));
				$oid = $store->objectSave((string)($in['object_id'] ?? ''), $fields, is_array($in['grid'] ?? null) ? $in['grid'] : null,
					is_array($in['events'] ?? null) ? $in['events'] : null, array_key_exists('lookup_php', $in), ($in['lookup_php'] ?? null) === null ? null : (string)$in['lookup_php']);
				if (!empty($in['clone_extra'])) {                     // object.clone: the columns object.save does not take
					$x = $in['clone_extra'];
					$store->objectSave($oid, $x + ['sob_all_type' => (string)($store->object($oid)['sob_all_type'] ?? '')], null, null, false, null);
				}
				$store->commit('object.save ' . (string)($store->object($oid)['sob_all_id'] ?? ''));
				$store->subformIndex($oid);
				$out(['object_id' => $oid, 'missing_column' => $store->missingColumn($oid)]);

			case 'column.add':
				// DDL at once (additive, nullable) – the draft lists it for the publish summary
				$oid = (string)($in['object_id'] ?? '');
				$o = $store->object($oid);
				if (!$o) NuBuilderApi::err(t('record_not_found'), 404);
				if ($store->missingColumn($oid) === null) NuBuilderApi::err(t('builder_err_column_exists'));
				$type = (string)($in['type'] ?? '');
				$length = trim((string)($in['length'] ?? ''));
				if (!in_array($type, NuDDL::TYPES, true)) NuBuilderApi::err(t('builder_err_column_type', (string)$o['sob_all_id']));
				if ($length !== '' && !preg_match('/^\d{1,5}([,.]\d{1,2})?$/', $length)) NuBuilderApi::err(t('builder_err_column_length', (string)$o['sob_all_id']));
				$table = trim((string)($store->snapshot()['form']['sfo_table'] ?? ''));
				NuDDL::addColumn($table, (string)$o['sob_all_id'], $type, $length);
				NuFormDraft::addDdl($store->draftId, $table, (string)$o['sob_all_id'], $type, $length);
				$out();

			case 'object.delete':
				$label = (string)($store->object((string)($in['object_id'] ?? ''))['sob_all_id'] ?? '');
				$store->objectDelete((string)($in['object_id'] ?? ''));
				$store->commit('object.delete ' . $label);
				$out();

			case 'objects.geometry':
				foreach (NuBuilderApi::geometry(is_array($in['items'] ?? null) ? $in['items'] : []) as $oid => $f) {
					if ($store->object($oid)) $store->objectSave($oid, $f, null, null, false, null);
				}
				$store->commit('objects.geometry');
				$out();

			case 'object.order':
				$store->objectOrder(is_array($in['ids'] ?? null) ? $in['ids'] : []);
				$store->commit('object.order');
				$out();

			case 'tabs.save':
				$rows = is_array($in['tabs'] ?? null) ? $in['tabs'] : [];
				if (!$rows) NuBuilderApi::err(t('builder_err_one_tab'));
				if (empty(NuAuth::user()['globeadmin'])) {               // only the globeadmin renames or deletes tabs – also in a draft
					$sent = [];
					foreach ($rows as $t) if (is_array($t) && (string)($t['id'] ?? '') !== '') $sent[(string)$t['id']] = trim((string)($t['title'] ?? ''));
					foreach ($store->snapshot()['tabs'] ?? [] as $cur) {
						$id = (string)$cur['zzzzsys_tab_id'];
						if (!array_key_exists($id, $sent) || $sent[$id] !== trim((string)$cur['syt_title'])) NuBuilderApi::err(t('builder_tabs_globeadmin'), 403);
					}
				}
				NuBuilderApi::tabDupe($rows, $store->snapshot()['tabs'] ?? []);
				$store->tabsSave($rows);
				$store->commit('tabs.save');
				$out();

			case 'browse.save':
				$rows = is_array($in['columns'] ?? null) ? $in['columns'] : [];
				foreach ($rows as $c) NuFormat::ensureSaved(is_array($c) ? (string)($c['format'] ?? '') : '');
				$store->browseSave($rows);
				$store->commit('browse.save');
				$out();

			case 'layout.convert':
				$store->layoutConvert();
				$store->commit('layout.convert');
				$out();

			case 'draft.undo':
				NuFormDraft::undo($store->draftId, $store->rev);
				NuBuilderApi::out(['ok' => true] + NuDraftStore::open($store->draftId, $store->formId, -1)->state(NB_HOOKS));

			case 'draft.redo':                                    // review H2
				NuFormDraft::redo($store->draftId, $store->rev);
				NuBuilderApi::out(['ok' => true] + NuDraftStore::open($store->draftId, $store->formId, -1)->state(NB_HOOKS));

			case 'draft.settings':
				NuFormDraft::setPreviewSaves($store->draftId, !empty($in['preview_saves']));
				NuBuilderApi::out(['ok' => true] + NuDraftStore::open($store->draftId, $store->formId, -1)->state(NB_HOOKS));
		}
		NuBuilderApi::err('Unknown action');
	}

	/**
	 * Change the database column of an object (NuColumnChange): column.info → the column as it is; column.check → the ALTER TABLE
	 * for a type / length (or the globeadmin's own statement) and the data check; column.change → runs it (recent password; in a draft also
	 * listed for the publish summary). Works on the table, so the same in live mode and in a draft.
	 */
	public static function column(string $formId, string $action, array $in): never
	{
		$meta = NuBuilderApi::form($formId);
		$table = $meta->table();
		$column = trim((string)($in['column'] ?? ''));
		if ($table === '' || $column === '') NuBuilderApi::err(t('builder_colchg_err_missing', $column, $table));
		try {
			$info = NuColumnChange::info($table, $column);
		} catch (Throwable $e) {
			NuBuilderApi::err($e->getMessage());
		}
		$ga = !empty(NuAuth::user()['globeadmin']);
		$public = array_diff_key($info, ['raw' => 1]);
		$usedBy = array_column(nu_db()->all('SELECT sfo_code FROM zzzzsys_form WHERE sfo_table = ? AND zzzzsys_form_id <> ? ORDER BY sfo_code', [$table, $formId]), 'sfo_code');
		if ($action === 'column.info') {
			$reauth = !NuSecurity::recentAuth();
			if ($reauth) $_SESSION[NuSess::REAUTH_NEXT] = NuBuilderApi::back($in) ?? nu_url('builder', ['f' => $formId, 'colchg' => $column]);
			NuBuilderApi::out(['ok' => true, 'info' => $public, 'free_sql' => $ga, 'used_by' => $usedBy, 'reauth' => $reauth] + ($reauth ? ['reauth_url' => nu_url('reauth')] : []));
		}
		$free = trim((string)($in['sql'] ?? ''));
		try {
			if ($free !== '') {
				if (!$ga) NuBuilderApi::err(t('err_forbidden'), 403);
				$plan = ['sql' => NuColumnChange::checkFreeSql($free, $table), 'checks' => [], 'same' => false, 'target' => '', 'free' => true];
			} else {
				$plan = NuColumnChange::plan($info, (string)($in['type'] ?? ''), trim((string)($in['length'] ?? ''))) + ['free' => false];
			}
		} catch (Throwable $e) {
			NuBuilderApi::err($e->getMessage());
		}
		if ($action === 'column.check') NuBuilderApi::out(['ok' => true, 'plan' => $plan, 'info' => $public]);
		// column.change
		if ($plan['same']) NuBuilderApi::err(t('builder_colchg_err_same'));
		if (!NuSecurity::recentAuth()) NuBuilderApi::out(['ok' => false, 'error' => t('reauth_needed'), 'code' => 'reauth', 'reauth_url' => nu_url('reauth')], 403);
		if ($plan['checks'] && empty($in['accept_loss'])) NuBuilderApi::out(['ok' => false, 'error' => t('builder_colchg_err_accept'), 'code' => 'accept', 'plan' => $plan], 409);
		try {
			NuColumnChange::run($info, $plan['sql'], $plan['free'] ? 'SQL' : $plan['target']);
		} catch (Throwable $e) {
			NuBuilderApi::err(t('builder_colchg_err_run', $e->getMessage()));
		}
		$draftId = (string)($in['draft_id'] ?? '');
		if ($draftId !== '') NuFormDraft::addDdl($draftId, $table, $info['column'], (string)($in['type'] ?? ''), trim((string)($in['length'] ?? '')),
			$info['sql_type'] . ' → ' . ($plan['free'] ? 'SQL' : $plan['target']));
		$after = NuColumnChange::info($table, $column);
		NuBuilderApi::out(['ok' => true, 'info' => array_diff_key($after, ['raw' => 1])]);
	}

	/** Info of the open draft of a form for live mode (the builder's info bar), null when there is none. */
	public static function liveDraft(string $formId): ?array
	{
		return NuFormDraft::info(NuFormDraft::openFor($formId));
	}

	/**
	 * Object menu → Actions → Clone: the request of object.save for a copy of $src – every sob_ column, a free field id
	 * (<id>_2, _3 …), placed below the original, the same events and lookup PHP. $in is changed in place.
	 */
	public static function clonePrep(array &$in, ?array $src, array $takenIds, array $events, ?string $lookupPhp): void
	{
		if (!$src) NuBuilderApi::err(t('record_not_found'), 404);
		$fields = [];
		foreach ($src as $k => $v) if (str_starts_with((string)$k, 'sob_') && !in_array($k, ['sob_all_zzzzsys_form_id', 'sob_all_json', 'sob_json'], true)) $fields[$k] = $v;
		$base = (string)$src['sob_all_id'];
		$n = 1; $id = $base . '_' . (++$n);
		while (in_array($id, $takenIds, true)) $id = $base . '_' . (++$n);
		$fields['sob_all_id'] = $id;
		$fields['sob_all_top'] = (string)((int)($src['sob_all_top'] ?? 0) + max(20, (int)($src['sob_all_height'] ?? 0)) + 8);
		$fields['sob_all_order'] = (string)((int)($src['sob_all_order'] ?? 0) + 1);
		$in['object_id'] = '';
		$in['fields'] = array_intersect_key($fields, array_flip(NB_OBJECT_FIELDS));
		// what object.save does not take (other sob_ columns, nuBuilder Next's settings in sob_all_json) – written after the save
		$extra = array_diff_key($fields, array_flip(NB_OBJECT_FIELDS));
		foreach (['sob_all_json', 'sob_json'] as $j) if (array_key_exists($j, $src)) $extra[$j] = $src[$j];
		$in['clone_extra'] = $extra;
		$in['events'] = array_map(static fn($e) => ['event' => (string)$e['sev_event'], 'js' => (string)$e['sev_javascript']], $events);
		if ($lookupPhp !== null && trim($lookupPhp) !== '') $in['lookup_php'] = $lookupPhp;
		$in['clone_of'] = (string)$src['zzzzsys_object_id'];
	}
}
