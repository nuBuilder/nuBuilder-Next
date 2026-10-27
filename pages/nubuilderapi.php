<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Form builder API (POST JSON, X-CSRF-Token). Writes straight into nuBuilder's metadata tables:
 * zzzzsys_form, zzzzsys_tab, zzzzsys_object, zzzzsys_event, zzzzsys_browse, zzzzsys_php.
 * nuBuilder system forms (ids starting with "nu") are read-only.
 */

header('Content-Type: application/json; charset=utf-8');

require_once NU_ROOT . '/core/form/nubuilder_gen.php';
require_once NU_ROOT . '/core/form/nuhistory.php';

function nb_out(array $d, int $status = 200): never
{
	http_response_code($status);
	echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

function nb_err(string $msg, int $status = 400): never
{
	nb_out(['ok' => false, 'error' => $msg], $status);
}

if (!nu_is_post()) nb_err('POST required', 405);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) nb_err('Invalid request');

// reading needs view rights on "nuform", every change needs save rights
$readOnly = in_array($in['action'] ?? '', ['state', 'tables', 'columns', 'files', 'history.list', 'draft.diff', 'draft.conflict'], true);
// publishing (form drafts, 0.8.386) needs the right "Publish forms" – checked in NuFormDraft – and view rights on the builder
$publish  = in_array($in['action'] ?? '', ['draft.publish', 'form.publishnew'], true);
if (!NuAcl::can(NuAcl::FORM_FORM, $readOnly || $publish ? 'view' : 'save')) nb_err(t('err_forbidden'), 403);
if (!$readOnly && NuDemo::active()) nb_err(t('demo_not_available'), 403);      // demo mode: the builder only shows (core/nudemo.php)

$db     = nu_db();
$action = (string)($in['action'] ?? '');
$formId = (string)($in['form_id'] ?? '');

const NB_FORM_FIELDS = ['sfo_type', 'sfo_code', 'sfo_description', 'sfo_group', 'sfo_status', 'sfo_breadcrumb_title', 'sfo_table', 'sfo_primary_key',
	'sfo_browse_redirect_form_id', 'sfo_browse_rows_per_page', 'sfo_browse_sql', 'sfo_javascript', 'sfo_browse_javascript', 'sfo_edit_javascript', 'sfo_style'];

const NB_OBJECT_FIELDS = ['sob_all_type', 'sob_all_id', 'sob_all_label', 'sob_all_zzzzsys_tab_id', 'sob_all_order', 'sob_all_top', 'sob_all_left',
	'sob_all_width', 'sob_all_height', 'sob_all_cloneable', 'sob_all_align', 'sob_all_validate', 'sob_all_access', 'sob_all_access_condition', 'sob_all_style_type', 'sob_all_style',
	'sob_calc_formula', 'sob_calc_format', 'sob_run_zzzzsys_form_id', 'sob_run_method', 'sob_run_type', 'sob_run_target', 'sob_run_id', 'sob_run_filter', 'sob_display_sql', 'sob_display_procedure', 'sob_select_multiple', 'sob_select_sql',
	'sob_lookup_code', 'sob_lookup_description', 'sob_lookup_description_width', 'sob_lookup_zzzzsys_form_id', 'sob_lookup_javascript', 'sob_lookup_table',
	'sob_subform_zzzzsys_form_id', 'sob_subform_foreign_key', 'sob_subform_add', 'sob_subform_delete', 'sob_subform_type', 'sob_subform_table',
	'sob_input_format', 'sob_input_type', 'sob_input_icon', 'sob_input_javascript', 'sob_input_datalist', 'sob_input_attribute', 'sob_input_count', 'sob_input_file_target', 'sob_html_code',
	'sob_html_chart_type', 'sob_html_javascript', 'sob_html_title', 'sob_html_vertical_label', 'sob_html_horizontal_label',
	'sob_select_2', 'sob_image_zzzzsys_file_id'];

const NB_INT_FIELDS = ['sob_all_order', 'sob_all_top', 'sob_all_left', 'sob_all_width', 'sob_all_height', 'sfo_browse_rows_per_page'];
const NB_TYPES      = ['input', 'select', 'textarea', 'editor', 'display', 'calc', 'lookup', 'subform', 'html', 'chart', 'word', 'contentbox', 'run', 'image'];
const NB_HOOKS      = ['BB', 'BE', 'BS', 'AS', 'BD', 'AD'];

/**
 * The form's table as offered in "Delete form" (0.8.245; the globeadmin only, like deleting the form itself), only a real table
 * (no view, no zzzzsys table) that no other form uses. Deleting it also needs a recent password confirmation.
 */
function nb_table_info(NuFormMeta $meta): array
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
		try { $r['rows'] = (int)$db->value('SELECT COUNT(*) FROM ' . NuDB::ident($t)); } catch (Throwable) {}
		$r['reauth'] = !NuSecurity::recentAuth();
	}
	return $r;
}

/** The form being edited – must exist and not be a nuBuilder system form. */
function nb_form(string $id): NuFormMeta
{
	$meta = NuFormMeta::load($id, true);                 // the builder edits the live form (drafts: NuDraftStore)
	if (!$meta) nb_err(t('form_err_not_found'), 404);
	if ($meta->isSystem()) nb_err(t('builder_err_system'), 403);
	return $meta;
}

function nb_clean(array $fields, array $allowed): array
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

function nb_upsert_php(string $id, ?string $code): void
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
function nb_missing_column(NuFormMeta $meta, string $objectId): ?array
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
function nb_state(string $formId): array
{
	NuFormMeta::forget($formId);
	$meta = NuFormMeta::load($formId, true);
	$db   = nu_db();
	$php  = [];
	foreach (NB_HOOKS as $h) $php[$h] = (string)$db->value('SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$formId . '_' . $h]);
	$events = [];
	foreach ($meta->objects as $o) {
		$events[$o['zzzzsys_object_id']] = $db->all('SELECT sev_event AS event, sev_javascript AS js FROM zzzzsys_event WHERE sev_zzzzsys_object_id = ? ORDER BY sev_event', [$o['zzzzsys_object_id']]);
	}
	$ab = [];
	foreach ($meta->objects as $o) {
		if ($o['sob_all_type'] === 'lookup') $ab[$o['zzzzsys_object_id']] = (string)$db->value('SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$o['zzzzsys_object_id'] . '_AB']);
	}
	$objects = array_map(static function ($o) { unset($o['tab_index']); return $o; }, $meta->objects);
	return [
		'form' => $meta->form + ['layout' => $meta->layout()], 'tabs' => $meta->tabs, 'objects' => $objects, 'browse' => $meta->browse,
		'events' => (object)$events, 'php' => $php, 'lookup_php' => (object)$ab, 'readonly' => $meta->isSystem(),
		'draft' => null, 'live_draft' => $meta->isSystem() ? null : nb_live_draft($formId),   // form drafts (0.8.386)
	];
}

/**
 * Form drafts (0.8.386): the builder's actions on the open draft $store (draft_id + rev from the builder) – the same rules as the live
 * actions below, on the draft's snapshot (NuDraftStore). Every change is a new revision of the draft (undo list); nothing reaches
 * nuBuilder's tables before draft.publish.
 */
function nb_draft(NuDraftStore $store, string $action, array $in): never
{
	$out = static fn(array $extra = []) => nb_out(['ok' => true] + $extra + $store->state(NB_HOOKS));
	switch ($action) {
		case 'state':
			$out();

		case 'history.list':
			nb_out(['ok' => true, 'history' => NuFormHistory::list($store->formId)]);

		case 'history.restore':
		case 'history.undo':
			nb_err(t('builder_draft_use_undo'));

		case 'form.save':
			$fields = nb_clean(is_array($in['fields'] ?? null) ? $in['fields'] : [], NB_FORM_FIELDS);
			$layout = null;
			if (array_key_exists('layout', $in['fields'] ?? [])) $layout = in_array($in['fields']['layout'], ['absolute', 'auto', 'grid'], true) ? (string)$in['fields']['layout'] : 'absolute';
			$store->formSave($fields, $layout, is_array($in['php'] ?? null) ? $in['php'] : null, NB_HOOKS);
			$store->commit('form.save');
			$out();

		case 'object.save':
			$fields = nb_clean(is_array($in['fields'] ?? null) ? $in['fields'] : [], NB_OBJECT_FIELDS);
			foreach (['sob_input_format', 'sob_calc_format'] as $fk) {
				if (isset($fields[$fk]) && mb_strlen((string)$fields[$fk]) > NuFormat::MAX_KEY) nb_err(t('fmt_err_length', NuFormat::MAX_KEY - 2));
				NuFormat::ensureSaved($fields[$fk] ?? null);       // shared format row – harmless while drafting
			}
			$oid = $store->objectSave((string)($in['object_id'] ?? ''), $fields, is_array($in['grid'] ?? null) ? $in['grid'] : null,
				is_array($in['events'] ?? null) ? $in['events'] : null, array_key_exists('lookup_php', $in), ($in['lookup_php'] ?? null) === null ? null : (string)$in['lookup_php']);
			$store->commit('object.save ' . (string)($store->object($oid)['sob_all_id'] ?? ''));
			$store->subformIndex($oid);
			$out(['object_id' => $oid, 'missing_column' => $store->missingColumn($oid)]);

		case 'column.add':
			// DDL at once (additive, nullable) – the draft lists it for the publish summary
			$oid = (string)($in['object_id'] ?? '');
			$o = $store->object($oid);
			if (!$o) nb_err(t('record_not_found'), 404);
			if ($store->missingColumn($oid) === null) nb_err(t('builder_err_column_exists'));
			$type = (string)($in['type'] ?? '');
			$length = trim((string)($in['length'] ?? ''));
			if (!in_array($type, NuDDL::TYPES, true)) nb_err(t('builder_err_column_type', (string)$o['sob_all_id']));
			if ($length !== '' && !preg_match('/^\d{1,5}([,.]\d{1,2})?$/', $length)) nb_err(t('builder_err_column_length', (string)$o['sob_all_id']));
			$table = trim((string)($store->snapshot()['form']['sfo_table'] ?? ''));
			NuDDL::addColumn($table, (string)$o['sob_all_id'], $type, $length);
			NuFormDraft::addDdl($store->draftId, $table, (string)$o['sob_all_id'], $type, $length);
			$out();

		case 'object.delete':
			$label = (string)($store->object((string)($in['object_id'] ?? ''))['sob_all_id'] ?? '');
			$store->objectDelete((string)($in['object_id'] ?? ''));
			$store->commit('object.delete ' . $label);
			$out();

		case 'object.order':
			$store->objectOrder(is_array($in['ids'] ?? null) ? $in['ids'] : []);
			$store->commit('object.order');
			$out();

		case 'tabs.save':
			$rows = is_array($in['tabs'] ?? null) ? $in['tabs'] : [];
			if (!$rows) nb_err(t('builder_err_one_tab'));
			if (empty(NuAuth::user()['globeadmin'])) {               // only the globeadmin renames or deletes tabs (0.8.247) – also in a draft
				$sent = [];
				foreach ($rows as $t) if (is_array($t) && (string)($t['id'] ?? '') !== '') $sent[(string)$t['id']] = trim((string)($t['title'] ?? ''));
				foreach ($store->snapshot()['tabs'] ?? [] as $cur) {
					$id = (string)$cur['zzzzsys_tab_id'];
					if (!array_key_exists($id, $sent) || $sent[$id] !== trim((string)$cur['syt_title'])) nb_err(t('builder_tabs_globeadmin'), 403);
				}
			}
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
			nb_out(['ok' => true] + NuDraftStore::open($store->draftId, $store->formId, -1)->state(NB_HOOKS));

		case 'draft.settings':
			NuFormDraft::setPreviewSaves($store->draftId, !empty($in['preview_saves']));
			nb_out(['ok' => true] + NuDraftStore::open($store->draftId, $store->formId, -1)->state(NB_HOOKS));
	}
	nb_err('Unknown action');
}

/** Info of the open draft of a form for live mode (the builder's info bar), null when there is none. */
function nb_live_draft(string $formId): ?array
{
	return NuFormDraft::info(NuFormDraft::openFor($formId));
}


const NB_SNAPSHOT_ACTIONS = ['form.save', 'object.save', 'object.delete', 'object.order', 'tabs.save', 'browse.save', 'layout.convert'];
/** Live changes – refused or confirmed while a draft is open (form drafts, 0.8.386). */
const NB_WRITE_ACTIONS = ['form.save', 'object.save', 'object.delete', 'object.order', 'tabs.save', 'browse.save', 'layout.convert', 'column.add', 'history.restore', 'history.undo'];

try {
	// form drafts (0.8.386): with draft_id the action works on the draft; without it on the live form – refused or confirmed while
	// a draft is open or changes only go through drafts (NuFormDraft::liveEditRefused)
	$draftId = (string)($in['draft_id'] ?? '');
	if ($draftId !== '' && !str_starts_with($action, 'draft.publish') && $action !== 'draft.discard' && $action !== 'draft.diff' && $action !== 'draft.conflict') {
		nb_form($formId);
		nb_draft(NuDraftStore::open($draftId, $formId, (int)($in['rev'] ?? -1)), $action, $in);
	}
	if (in_array($action, NB_WRITE_ACTIONS, true) && $formId !== '' && ($refused = NuFormDraft::liveEditRefused($formId, !empty($in['live_ok'])))) {
		nb_out(['ok' => false, 'error' => $refused['message'], 'code' => $refused['code']], 409);
	}

	// tabs (0.8.247): only the globeadmin renames or deletes existing tabs – others may add tabs, reorder them and set their visibility
	if ($action === 'tabs.save' && empty(NuAuth::user()['globeadmin'])) {
		$sent = [];
		foreach (is_array($in['tabs'] ?? null) ? $in['tabs'] : [] as $t) if (is_array($t) && (string)($t['id'] ?? '') !== '') $sent[(string)$t['id']] = trim((string)($t['title'] ?? ''));
		foreach ($db->all('SELECT zzzzsys_tab_id, syt_title FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ?', [$formId]) as $cur) {
			$id = (string)$cur['zzzzsys_tab_id'];
			if (!array_key_exists($id, $sent) || $sent[$id] !== trim((string)$cur['syt_title'])) nb_err(t('builder_tabs_globeadmin'), 403);
		}
	}
	if (in_array($action, NB_SNAPSHOT_ACTIONS, true)) {
		nb_form($formId);
		NuFormHistory::snapshot($formId, $action . (isset($in['object_id']) && $in['object_id'] !== '' ? ' ' . (string)($db->value('SELECT sob_all_id FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [(string)$in['object_id']]) ?? '') : ''));
	}

	switch ($action) {
		case 'history.list':
			nb_out(['ok' => true, 'history' => NuFormHistory::list($formId)]);

		case 'history.restore':
			nb_form($formId);
			NuFormHistory::restore($formId, (string)($in['id'] ?? ''));
			nb_out(['ok' => true] + nb_state($formId));

		case 'history.undo':
			nb_form($formId);
			$last = NuFormHistory::list($formId)[0] ?? null;
			if (!$last) nb_err(t('builder_history_empty'));
			NuFormHistory::restore($formId, (string)$last['id']);
			nb_out(['ok' => true, 'restored' => $last] + nb_state($formId));


		// --------------------------------------------------------------- form drafts (0.8.386)
		case 'draft.start':
			nb_form($formId);
			$d = NuFormDraft::start($formId, (string)($in['note'] ?? ''));
			nb_out(['ok' => true] + NuDraftStore::open((string)$d['zzzzsys_nunext_form_draft_id'], $formId, -1)->state(NB_HOOKS));

		case 'draft.diff':
		case 'draft.conflict':
			nb_form($formId);
			$d = NuFormDraft::get($draftId);
			if (!$d || (string)$d['form_id'] !== $formId) nb_err(t('record_not_found'), 404);
			$live = NuFormSnapshot::capture($formId) ?? [];
			$snap = NuFormDraft::snapshotOf($d);
			$base = NuFormDraft::snapshotOf($d, 'base_snapshot');
			if ($action === 'draft.conflict') nb_out(['ok' => true, 'conflict' => NuFormSnapshot::hash($live, $base) !== (string)$d['base_hash'], 'changes' => NuFormSnapshot::diff($base, $live)]);
			$from = ($in['against'] ?? 'live') === 'base' ? $base : $live;
			nb_out(['ok' => true, 'diff' => NuFormSnapshot::diff($from, $snap), 'ddl' => json_decode((string)($d['ddl'] ?? '[]'), true) ?: [],
				'validation' => NuFormDraft::validate($formId, $snap, $live)]);

		case 'draft.publish':
			nb_form($formId);
			$d = NuFormDraft::get($draftId);
			if (!$d || (string)$d['form_id'] !== $formId) nb_err(t('record_not_found'), 404);
			if (!NuFormDraft::canPublish()) nb_err(t('err_forbidden'), 403);
			$force = !empty($in['force']);
			if ($force && trim((string)($in['confirm_code'] ?? '')) !== (string)(NuFormDraft::snapshotOf($d)['form']['sfo_code'] ?? '')) nb_err(t('builder_draft_overwrite_type', (string)(NuFormDraft::snapshotOf($d)['form']['sfo_code'] ?? '')));
			$r = NuFormDraft::publish($draftId, (string)($in['note'] ?? ''), $force, !empty($in['warnings_ok']));
			nb_out($r + ($r['ok'] ? nb_state($formId) : []));

		case 'draft.discard':
			nb_form($formId);
			$d = NuFormDraft::get($draftId);
			if (!$d || (string)$d['form_id'] !== $formId) nb_err(t('record_not_found'), 404);
			NuFormDraft::discard($draftId);
			nb_out(['ok' => true] + nb_state($formId));

		case 'form.publishnew':
			nb_form($formId);
			$v = NuFormDraft::publishNewForm($formId);
			nb_out(['ok' => true, 'version' => $v] + nb_state($formId));

		// --------------------------------------------------------------- read
		case 'state':
			nb_out(['ok' => true] + nb_state($formId));

		case 'tables':
			$tables = match ($db->type) {
				'mysql'  => $db->all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME NOT LIKE 'zzzzsys%' ORDER BY TABLE_NAME"),
				'sqlsrv' => $db->all("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME NOT LIKE 'zzzzsys%' ORDER BY TABLE_NAME"),
				default  => $db->all("SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'zzzzsys%' ORDER BY name"),
			};
			nb_out(['ok' => true, 'tables' => array_column($tables, 't')]);

		case 'files':
			$files = $db->tableExists('zzzzsys_file') ? $db->all('SELECT zzzzsys_file_id, sfi_code, sfi_description FROM zzzzsys_file ORDER BY sfi_code') : [];
			nb_out(['ok' => true, 'files' => array_map(static fn($f) => [$f['zzzzsys_file_id'], $f['sfi_code'] . ($f['sfi_description'] ? ' – ' . $f['sfi_description'] : '')], $files)]);

		case 'columns':
			$cols = $db->columns(NuDB::ident((string)($in['table'] ?? '')));
			nb_out(['ok' => true, 'columns' => array_map(static fn($n, $c) => ['name' => $n] + $c, array_keys($cols), $cols)]);

		// --------------------------------------------------------------- form
		case 'form.create':
			nb_out(['ok' => true, 'form_id' => NuBuilderGen::create($in)]);

		case 'form.save':
			$meta   = nb_form($formId);
			$fields = nb_clean(is_array($in['fields'] ?? null) ? $in['fields'] : [], NB_FORM_FIELDS);
			// required as in nuBuilder's form properties (nuform: description, code, type – 0.8.564); nb_clean turned '' into null
			foreach (['sfo_description', 'sfo_code', 'sfo_type'] as $rk) if (array_key_exists($rk, $fields) && trim((string)$fields[$rk]) === '') nb_err(t('builder_err_required'));
			if (isset($fields['sfo_code']) && (int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ? AND zzzzsys_form_id <> ?', [$fields['sfo_code'], $formId])) {
				nb_err(t('builder_err_code_taken'));
			}
			if (array_key_exists('layout', $in['fields'] ?? [])) {
				$layout = in_array($in['fields']['layout'], ['absolute', 'auto', 'grid'], true) ? $in['fields']['layout'] : 'absolute';
				$fields['sfo_json'] = NuFormMeta::mergeNunextJson($meta->form['sfo_json'] ?? null, ['layout' => $layout === 'absolute' ? null : $layout]);
			}
			if ($fields) $db->update('zzzzsys_form', $fields, 'zzzzsys_form_id', $formId);
			if (is_array($in['php'] ?? null)) {
				foreach (NB_HOOKS as $h) if (array_key_exists($h, $in['php'])) nb_upsert_php($formId . '_' . $h, $in['php'][$h] === null ? null : (string)$in['php'][$h]);
			}
			nb_out(['ok' => true] + nb_state($formId));

		case 'form.deleteinfo':
			if (empty(NuAuth::user()['globeadmin'])) nb_err(t('builder_delete_globeadmin'), 403);
			if (NuFormDraft::openFor($formId)) nb_err(t('builder_draft_delete_first'));
			$info = nb_table_info(nb_form($formId));
			if ($info['reauth']) $_SESSION['nu_reauth_next'] = nu_url('builder', ['f' => $formId, 'delete' => '1']);
			nb_out(['ok' => true] + $info + ($info['reauth'] ? ['reauth_url' => nu_url('reauth')] : []));

		case 'form.delete':
			// a whole form (objects, tabs, events, PHP, rights) is deleted by the globeadmin only (0.8.246)
			if (empty(NuAuth::user()['globeadmin'])) nb_err(t('builder_delete_globeadmin'), 403);
			if (NuFormDraft::openFor($formId)) nb_err(t('builder_draft_delete_first'));
			$meta = nb_form($formId);
			// with the table: the typed name must match exactly; checked again here (rights, not a view, not used elsewhere, password)
			$drop = (string)($in['drop_table'] ?? '');
			if ($drop !== '') {
				$info = nb_table_info($meta);
				if (!$info['can_drop']) nb_err(t('builder_drop_not_allowed'), 403);
				if ($drop !== $info['table']) nb_err(t('builder_drop_mismatch'));
				if ($info['reauth']) nb_err(t('reauth_needed'), 403);
			}
			$db->transaction(static function (NuDB $db) use ($formId) {
				$ids = array_column($db->all('SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$formId]), 'zzzzsys_object_id');
				foreach ($ids as $oid) {
					$db->delete('zzzzsys_event', 'sev_zzzzsys_object_id', $oid);
					$db->delete('zzzzsys_php', 'zzzzsys_php_id', $oid . '_AB');
				}
				$db->delete('zzzzsys_object', 'sob_all_zzzzsys_form_id', $formId);
				$db->delete('zzzzsys_tab', 'syt_zzzzsys_form_id', $formId);
				$db->delete('zzzzsys_browse', 'sbr_zzzzsys_form_id', $formId);
				$db->delete('zzzzsys_access_form', 'slf_zzzzsys_form_id', $formId);
				foreach (NB_HOOKS as $h) $db->delete('zzzzsys_php', 'zzzzsys_php_id', $formId . '_' . $h);
				$db->delete('zzzzsys_form', 'zzzzsys_form_id', $formId);
			});
			NuPage::forget($formId);                       // settings of a page form (0.8.387)
			NuLog::write('form_deleted', $meta->code(), $formId, ['table' => $meta->table()]);
			if ($drop === '') nb_out(['ok' => true]);
			// DDL ends a transaction on MySQL – the table goes after the form; if it fails, the form is gone and the table stays
			try {
				$db->run('DROP TABLE ' . NuDB::ident($drop));
				NuDB::forgetColumns($drop);
			} catch (Throwable $e) {
				nb_out(['ok' => true, 'warning' => t('builder_drop_failed', $drop) . (empty(NuAuth::user()['globeadmin']) ? '' : ' ' . $e->getMessage())]);
			}
			NuLog::write('table_dropped', $drop, $formId, ['form' => $meta->code(), 'rows' => $info['rows']]);
			nb_out(['ok' => true, 'dropped' => $drop]);

		// --------------------------------------------------------------- objects
		case 'object.save':
			$meta     = nb_form($formId);
			$objectId = (string)($in['object_id'] ?? '');
			$fields   = nb_clean(is_array($in['fields'] ?? null) ? $in['fields'] : [], NB_OBJECT_FIELDS);
			$existing = $objectId !== '' ? $db->one('SELECT * FROM zzzzsys_object WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [$objectId, $formId]) : null;
			if ($objectId !== '' && !$existing) nb_err(t('record_not_found'), 404);
			foreach (['sob_input_format', 'sob_calc_format'] as $fk) {
				if (isset($fields[$fk]) && mb_strlen((string)$fields[$fk]) > NuFormat::MAX_KEY) nb_err(t('fmt_err_length', NuFormat::MAX_KEY - 2));
				NuFormat::ensureSaved($fields[$fk] ?? null);           // built-in format → zzzzsys_format, so nuBuilder knows it
			}

			if (isset($fields['sob_all_id'])) $fields['sob_all_id'] = trim((string)$fields['sob_all_id']);   // spaces at the ends (0.8.536)
			if (isset($fields['sob_input_icon']) && mb_strlen((string)$fields['sob_input_icon']) > 50) nb_err(t('builder_obj_icon') . ': ' . t('err_too_long', 50));   // VARCHAR(50) in nuBuilder (0.8.559)
			$type = (string)($fields['sob_all_type'] ?? $existing['sob_all_type'] ?? '');
			$fid  = (string)($fields['sob_all_id'] ?? $existing['sob_all_id'] ?? '');
			if (!in_array($type, NB_TYPES, true)) nb_err(t('builder_err_type'));
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fid)) nb_err(t('builder_err_id'));
			if ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_id = ? AND zzzzsys_object_id <> ?', [$formId, $fid, $objectId])) {
				nb_err(t('builder_err_id_taken', $fid));
			}
			if (isset($fields['sob_all_zzzzsys_tab_id']) && $fields['sob_all_zzzzsys_tab_id'] !== null
				&& !(int)$db->value('SELECT COUNT(*) FROM zzzzsys_tab WHERE zzzzsys_tab_id = ? AND syt_zzzzsys_form_id = ?', [$fields['sob_all_zzzzsys_tab_id'], $formId])) {
				nb_err(t('builder_err_tab'));
			}

			if (is_array($in['grid'] ?? null)) {
				$g = $in['grid'];
				$fields['sob_all_json'] = NuFormMeta::mergeNunextJson($existing['sob_all_json'] ?? null, [
					'row' => isset($g['row']) && $g['row'] !== '' ? max(1, (int)$g['row']) : null,
					'col' => isset($g['col']) && $g['col'] !== '' ? (int)$g['col'] : null,
					'span' => isset($g['span']) && $g['span'] !== '' ? max(1, min(12, (int)$g['span'])) : null,
					'box' => isset($g['box']) && preg_match('/^\w+$/', (string)$g['box']) ? (string)$g['box'] : null,
				]);
			}

			$fields = array_intersect_key($fields, $db->columns('zzzzsys_object') ?: $fields);   // older nuBuilder versions lack sob_run_target / sob_run_type
			$db->transaction(static function (NuDB $db) use (&$objectId, $existing, $fields, $formId, $meta, $in) {
				if ($existing) {
					$db->update('zzzzsys_object', $fields, 'zzzzsys_object_id', $objectId);
				} else {
					$objectId = nu_id();
					$db->insert('zzzzsys_object', ['zzzzsys_object_id' => $objectId, 'sob_all_zzzzsys_form_id' => $formId, 'sob_all_table' => $meta->table(),
						'sob_all_cloneable' => '1', 'sob_all_access' => '0', 'sob_all_validate' => '0'] + $fields);
				}
				if (is_array($in['events'] ?? null)) {
					$new = [];
					foreach ($in['events'] as $e) {
						$ev = strtolower(trim((string)($e['event'] ?? '')));
						$js = (string)($e['js'] ?? '');
						if ($ev === '' || trim($js) === '' || !preg_match('/^[a-z]+$/', $ev)) continue;
						$new[] = [$ev, $js];
					}
					// unchanged events keep their rows (and ids) – as nuBuilder's sub-form (0.8.366)
					$old = array_map(static fn($r) => [(string)$r['sev_event'], (string)$r['sev_javascript']],
						$db->all('SELECT sev_event, sev_javascript FROM zzzzsys_event WHERE sev_zzzzsys_object_id = ? ORDER BY zzzzsys_event_id', [$objectId]));
					$key = static fn(array $l) => json_encode(array_map(static fn($x) => $x[0] . "\n" . $x[1], $l));
					$sort = static function (array $l) { usort($l, static fn($a, $b) => strcmp($a[0] . $a[1], $b[0] . $b[1])); return $l; };
					if ($key($sort($old)) !== $key($sort($new))) {
						$db->delete('zzzzsys_event', 'sev_zzzzsys_object_id', $objectId);
						foreach ($new as [$ev, $js]) $db->insert('zzzzsys_event', ['zzzzsys_event_id' => nu_id(), 'sev_zzzzsys_object_id' => $objectId, 'sev_event' => $ev, 'sev_javascript' => $js]);
					}
				}
				if (array_key_exists('lookup_php', $in)) nb_upsert_php($objectId . '_AB', $in['lookup_php'] === null ? null : (string)$in['lookup_php']);
			});
			if ($type === 'subform') {                                // the sub-form's link column gets an index (0.8.366)
				$so = $db->one('SELECT o.sob_subform_foreign_key, f.sfo_table FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_subform_zzzzsys_form_id WHERE o.zzzzsys_object_id = ?', [$objectId]);
				if ($so && trim((string)$so['sfo_table']) !== '' && trim((string)$so['sob_subform_foreign_key']) !== '') {
					try { NuDDL::ensureIndex((string)$so['sfo_table'], (string)$so['sob_subform_foreign_key']); } catch (Throwable $e) { error_log('nuBuilder Next index: ' . $e->getMessage()); }
				}
			}
			nb_out(['ok' => true, 'object_id' => $objectId, 'missing_column' => nb_missing_column($meta, $objectId)] + nb_state($formId));

		case 'column.add':
			// adds the column an object needs to its form's table (confirmed by the user in the builder)
			$meta     = nb_form($formId);
			$objectId = (string)($in['object_id'] ?? '');
			$o        = $db->one('SELECT * FROM zzzzsys_object WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [$objectId, $formId]);
			if (!$o) nb_err(t('record_not_found'), 404);
			if (nb_missing_column($meta, $objectId) === null) nb_err(t('builder_err_column_exists'));
			$type   = (string)($in['type'] ?? '');
			$length = trim((string)($in['length'] ?? ''));
			if (!in_array($type, NuDDL::TYPES, true)) nb_err(t('builder_err_column_type', (string)$o['sob_all_id']));
			if ($length !== '' && !preg_match('/^\d{1,5}([,.]\d{1,2})?$/', $length)) nb_err(t('builder_err_column_length', (string)$o['sob_all_id']));
			NuDDL::addColumn($meta->table(), (string)$o['sob_all_id'], $type, $length);
			nb_out(['ok' => true] + nb_state($formId));

		case 'object.delete':
			nb_form($formId);
			$objectId = (string)($in['object_id'] ?? '');
			$db->transaction(static function (NuDB $db) use ($objectId, $formId) {
				$db->run('DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [$objectId, $formId]);
				$db->delete('zzzzsys_event', 'sev_zzzzsys_object_id', $objectId);
				$db->delete('zzzzsys_php', 'zzzzsys_php_id', $objectId . '_AB');
			});
			nb_out(['ok' => true] + nb_state($formId));

		case 'object.order':
			nb_form($formId);
			$ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
			$db->transaction(static function (NuDB $db) use ($ids, $formId) {
				foreach (array_values($ids) as $i => $oid) {
					$db->run('UPDATE zzzzsys_object SET sob_all_order = ? WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [($i + 1) * 10, (string)$oid, $formId]);
				}
			});
			nb_out(['ok' => true] + nb_state($formId));

		// --------------------------------------------------------------- tabs & browse columns (whole list replaced)
		case 'tabs.save':
			nb_form($formId);
			$rows = is_array($in['tabs'] ?? null) ? $in['tabs'] : [];
			if (!$rows) nb_err(t('builder_err_one_tab'));
			$db->transaction(static function (NuDB $db) use ($rows, $formId) {
				$keep = [];
				foreach (array_values($rows) as $i => $t) {
					$data = ['syt_title' => trim((string)($t['title'] ?? '')) ?: 'Tab', 'syt_order' => ($i + 1) * 10,
						'syt_access' => in_array((string)($t['access'] ?? '0'), ['0', '2', '3'], true) ? (string)$t['access'] : '0'];
					$id = (string)($t['id'] ?? '');
					if ($id !== '' && (int)$db->value('SELECT COUNT(*) FROM zzzzsys_tab WHERE zzzzsys_tab_id = ? AND syt_zzzzsys_form_id = ?', [$id, $formId])) {
						$db->update('zzzzsys_tab', $data, 'zzzzsys_tab_id', $id);
					} else {
						$id = nu_id();
						$db->insert('zzzzsys_tab', ['zzzzsys_tab_id' => $id, 'syt_zzzzsys_form_id' => $formId] + $data);
					}
					$keep[] = $id;
				}
				$in = implode(',', array_fill(0, count($keep), '?'));
				$first = $keep[0];
				// objects of removed tabs move to the first tab instead of disappearing
				$db->run("UPDATE zzzzsys_object SET sob_all_zzzzsys_tab_id = ? WHERE sob_all_zzzzsys_form_id = ? AND (sob_all_zzzzsys_tab_id IS NULL OR sob_all_zzzzsys_tab_id NOT IN ($in))", [$first, $formId, ...$keep]);
				$db->run("DELETE FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ? AND zzzzsys_tab_id NOT IN ($in)", [$formId, ...$keep]);
			});
			nb_out(['ok' => true] + nb_state($formId));

		case 'browse.save':
			nb_form($formId);
			$rows = is_array($in['columns'] ?? null) ? $in['columns'] : [];
			foreach ($rows as $c) NuFormat::ensureSaved(is_array($c) ? (string)($c['format'] ?? '') : '');
			$db->transaction(static function (NuDB $db) use ($rows, $formId) {
				$keep = [];
				foreach (array_values($rows) as $i => $c) {
					$data = ['sbr_title' => (string)($c['title'] ?? ''), 'sbr_display' => (string)($c['display'] ?? ''),
						'sbr_align' => in_array($c['align'] ?? 'l', ['l', 'c', 'r'], true) ? $c['align'] : 'l',
						'sbr_format' => trim((string)($c['format'] ?? '')) ?: null, 'sbr_order' => ($i + 1) * 10, 'sbr_width' => max(0, (int)($c['width'] ?? 100))];
					$existingJson = ($c['id'] ?? '') !== '' ? $db->value('SELECT sbr_json FROM zzzzsys_browse WHERE zzzzsys_browse_id = ? AND sbr_zzzzsys_form_id = ?', [(string)$c['id'], $formId]) : null;
					// filter off is stored as false where nuBuilder Next has a default filter (NuFormMeta::DEFAULT_FILTERS), otherwise left out
					$defFilter = in_array(strtolower(trim((string)($c['display'] ?? ''))), NuFormMeta::DEFAULT_FILTERS[$formId] ?? [], true);
					$data['sbr_json'] = NuFormMeta::mergeNunextJson($existingJson, ['filter' => !empty($c['filter']) ? true : ($defFilter ? false : null)]);
					if (trim($data['sbr_display']) === '') continue;
					$id = (string)($c['id'] ?? '');
					if ($id !== '' && (int)$db->value('SELECT COUNT(*) FROM zzzzsys_browse WHERE zzzzsys_browse_id = ? AND sbr_zzzzsys_form_id = ?', [$id, $formId])) {
						$db->update('zzzzsys_browse', $data, 'zzzzsys_browse_id', $id);
					} else {
						$id = nu_id();
						$db->insert('zzzzsys_browse', ['zzzzsys_browse_id' => $id, 'sbr_zzzzsys_form_id' => $formId] + $data);
					}
					$keep[] = $id;
				}
				if ($keep) {
					$in = implode(',', array_fill(0, count($keep), '?'));
					$db->run("DELETE FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = ? AND zzzzsys_browse_id NOT IN ($in)", [$formId, ...$keep]);
				} else {
					$db->delete('zzzzsys_browse', 'sbr_zzzzsys_form_id', $formId);
				}
			});
			nb_out(['ok' => true] + nb_state($formId));

		// --------------------------------------------------------------- layout
		case 'layout.convert':
			$meta = nb_form($formId);
			$db->transaction(static function (NuDB $db) use ($meta, $formId) {
				$tabs = $meta->tabs ?: [['zzzzsys_tab_id' => '']];
				foreach ($tabs as $t) {
					foreach (NuLayout::placement($meta->objectsOfTab((string)$t['zzzzsys_tab_id'])) as $oid => $pl) {
						$json = $db->value('SELECT sob_all_json FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [$oid]);
						$db->update('zzzzsys_object', ['sob_all_json' => NuFormMeta::mergeNunextJson($json, ['row' => $pl['row'], 'col' => $pl['col'], 'span' => $pl['span'], 'box' => $pl['box'] ?: null])], 'zzzzsys_object_id', (string)$oid);
					}
				}
				$db->update('zzzzsys_form', ['sfo_json' => NuFormMeta::mergeNunextJson($meta->form['sfo_json'] ?? null, ['layout' => 'grid'])], 'zzzzsys_form_id', $formId);
			});
			nb_out(['ok' => true] + nb_state($formId));
	}
	nb_err('Unknown action');
} catch (NuDraftRevException $e) {
	nb_out(['ok' => false, 'error' => $e->getMessage(), 'code' => 'draft_rev'], 409);          // the builder reloads the draft
} catch (Throwable $e) {
	error_log('nuBuilder Next builder: ' . $e->getMessage());
	nb_err($e->getMessage(), 500);
}
