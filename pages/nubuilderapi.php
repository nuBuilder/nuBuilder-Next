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




if (!nu_is_post()) NuBuilderApi::err('POST required', 405);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) NuBuilderApi::err('Invalid request');

// reading needs view rights on "nuform", every change needs save rights
$readOnly = in_array($in['action'] ?? '', ['state', 'tables', 'columns', 'files', 'history.list', 'history.diff', 'draft.diff', 'draft.conflict'], true);
// publishing (form drafts) needs the right "Publish forms" – checked in NuFormDraft – and view rights on the builder
$publish  = in_array($in['action'] ?? '', ['draft.publish', 'form.publishnew'], true);
if (!NuAcl::can(NuAcl::FORM_FORM, $readOnly || $publish ? 'view' : 'save')) NuBuilderApi::err(t('err_forbidden'), 403);
if (!$readOnly && NuDemo::active()) NuBuilderApi::err(t('demo_not_available'), 403);      // demo mode: the builder only shows (core/nudemo.php)

$db     = nu_db();
$action = (string)($in['action'] ?? '');
$formId = (string)($in['form_id'] ?? '');

const NB_FORM_FIELDS = ['sfo_type', 'sfo_code', 'sfo_description', 'sfo_group', 'sfo_status', 'sfo_breadcrumb_title', 'sfo_table', 'sfo_primary_key',
	'sfo_browse_redirect_form_id', 'sfo_browse_rows_per_page', 'sfo_browse_row_height', 'sfo_browse_title_multiline', 'sfo_browse_autoresize_columns',
		'sfo_browse_target', 'sfo_browse_sql', 'sfo_javascript', 'sfo_browse_javascript', 'sfo_edit_javascript', 'sfo_style'];

const NB_OBJECT_FIELDS = ['sob_all_type', 'sob_all_id', 'sob_all_label', 'sob_all_zzzzsys_tab_id', 'sob_all_order', 'sob_all_top', 'sob_all_left',
	'sob_all_width', 'sob_all_height', 'sob_all_cloneable', 'sob_all_align', 'sob_all_validate', 'sob_all_access', 'sob_all_access_condition', 'sob_all_style_type', 'sob_all_style',
	'sob_calc_formula', 'sob_calc_format', 'sob_run_zzzzsys_form_id', 'sob_run_method', 'sob_run_type', 'sob_run_target', 'sob_run_id', 'sob_run_filter',
		'sob_display_sql', 'sob_display_procedure', 'sob_select_multiple', 'sob_select_sql',
	'sob_lookup_code', 'sob_lookup_description', 'sob_lookup_description_width', 'sob_lookup_zzzzsys_form_id', 'sob_lookup_javascript', 'sob_lookup_table',
	'sob_subform_zzzzsys_form_id', 'sob_subform_foreign_key', 'sob_subform_add', 'sob_subform_delete', 'sob_subform_type', 'sob_subform_table',
	'sob_input_format', 'sob_input_type', 'sob_input_icon', 'sob_input_javascript', 'sob_input_datalist', 'sob_input_attribute', 'sob_input_count', 'sob_input_file_target', 'sob_html_code',
	'sob_html_chart_type', 'sob_html_javascript', 'sob_html_title', 'sob_html_vertical_label', 'sob_html_horizontal_label',
	'sob_select_2', 'sob_image_zzzzsys_file_id', 'nunext_run_url', 'nunext_run_pivot', 'nunext_help', 'nunext_fit', 'nunext_phone_table', 'nunext_calendar', 'nunext_suggest'];

const NB_INT_FIELDS = ['sob_all_order', 'sob_all_top', 'sob_all_left', 'sob_all_width', 'sob_all_height', 'sfo_browse_rows_per_page'];
const NB_TYPES      = ['input', 'select', 'textarea', 'editor', 'display', 'calc', 'lookup', 'subform', 'html', 'chart', 'word', 'contentbox', 'run', 'image'];
const NB_HOOKS      = ['BB', 'BE', 'BS', 'AS', 'BD', 'AD'];

















const NB_SNAPSHOT_ACTIONS = ['form.save', 'rules.save', 'object.save', 'object.clone', 'objects.geometry', 'object.delete', 'object.order', 'tabs.save', 'browse.save', 'layout.convert'];
/** Live changes – refused or confirmed while a draft is open (form drafts). */
const NB_WRITE_ACTIONS = ['form.save', 'rules.save', 'object.save', 'object.clone', 'objects.geometry', 'object.delete', 'object.order', 'tabs.save',
	'browse.save', 'layout.convert', 'column.add', 'history.restore', 'history.undo'];

try {
	// form drafts: with draft_id the action works on the draft; without it on the live form – refused or confirmed while
	// a draft is open or changes only go through drafts (NuFormDraft::liveEditRefused)
	// nuBuilder Forte's User Home: its form row is replaced by Forte's updates – no form settings, browse, layout change or
	// delete; form.save keeps only the Before Edit PHP (Forte keeps nuuserhome_BE, its tabs and objects)
	if ($formId === NuUserHome::FORM) {
		if (in_array($action, ['browse.save', 'layout.convert', 'form.delete', 'form.deleteinfo', 'column.add'], true)) NuBuilderApi::err(t('builder_userhome_part'), 403);
		if ($action === 'form.save') {
			$in['fields'] = [];
			$in['php'] = is_array($in['php'] ?? null) ? array_intersect_key($in['php'], ['BE' => 1]) : null;
		}
	}
	if (in_array($action, ['column.info', 'column.check', 'column.change'], true)) NuBuilderApi::column($formId, $action, $in);   // table-level
	$draftId = (string)($in['draft_id'] ?? '');
	if ($draftId !== '' && !str_starts_with($action, 'draft.publish') && $action !== 'draft.discard' && $action !== 'draft.diff' && $action !== 'draft.conflict') {
		NuBuilderApi::form($formId);
		NuBuilderApi::draft(NuDraftStore::open($draftId, $formId, (int)($in['rev'] ?? -1)), $action, $in);
	}
	if (in_array($action, NB_WRITE_ACTIONS, true) && $formId !== '' && ($refused = NuFormDraft::liveEditRefused($formId, !empty($in['live_ok'])))) {
		NuBuilderApi::out(['ok' => false, 'error' => $refused['message'], 'code' => $refused['code']], 409);
	}

	// tabs: only the globeadmin renames or deletes existing tabs – others may add tabs, reorder them and set their visibility
	if ($action === 'tabs.save' && empty(NuAuth::user()['globeadmin'])) {
		$sent = [];
		foreach (is_array($in['tabs'] ?? null) ? $in['tabs'] : [] as $t) if (is_array($t) && (string)($t['id'] ?? '') !== '') $sent[(string)$t['id']] = trim((string)($t['title'] ?? ''));
		foreach ($db->all('SELECT zzzzsys_tab_id, syt_title FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ?', [$formId]) as $cur) {
			$id = (string)$cur['zzzzsys_tab_id'];
			if (!array_key_exists($id, $sent) || $sent[$id] !== trim((string)$cur['syt_title'])) NuBuilderApi::err(t('builder_tabs_globeadmin'), 403);
		}
	}
	if (in_array($action, NB_SNAPSHOT_ACTIONS, true)) {
		NuBuilderApi::form($formId);
		NuFormHistory::snapshot($formId, $action
			. (isset($in['object_id']) && $in['object_id'] !== '' ? ' ' . (string)($db->value('SELECT sob_all_id FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [(string)$in['object_id']]) ?? '') : ''));
	}

	switch ($action) {
		case 'history.list':
			NuBuilderApi::out(['ok' => true, 'history' => NuFormHistory::list($formId)]);

		case 'history.diff':           // design mode: what changed from a history entry to the live form
			NuBuilderApi::form($formId);
			NuBuilderApi::out(['ok' => true, 'diff' => NuFormSnapshot::diff(NuFormHistory::get($formId, (string)($in['id'] ?? '')), NuFormSnapshot::capture($formId) ?? [])]);

		case 'history.restore':
			NuBuilderApi::form($formId);
			NuFormHistory::restore($formId, (string)($in['id'] ?? ''));
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		case 'history.undo':
			NuBuilderApi::form($formId);
			$last = NuFormHistory::list($formId)[0] ?? null;
			if (!$last) NuBuilderApi::err(t('builder_history_empty'));
			NuFormHistory::restore($formId, (string)$last['id']);
			NuBuilderApi::out(['ok' => true, 'restored' => $last] + NuBuilderApi::state($formId));


		// --------------------------------------------------------------- form drafts
		case 'draft.start':
			NuBuilderApi::form($formId);
			$d = NuFormDraft::start($formId, (string)($in['note'] ?? ''));
			if (!empty($in['preview'])) NuFormDraft::startPreview((string)$d['zzzzsys_nunext_form_draft_id']);   // design mode: this session shows the draft
			NuBuilderApi::out(['ok' => true] + NuDraftStore::open((string)$d['zzzzsys_nunext_form_draft_id'], $formId, -1)->state(NB_HOOKS));

		case 'draft.diff':
		case 'draft.conflict':
			NuBuilderApi::form($formId);
			$d = NuFormDraft::get($draftId);
			if (!$d || (string)$d['form_id'] !== $formId) NuBuilderApi::err(t('record_not_found'), 404);
			$live = NuFormSnapshot::capture($formId) ?? [];
			$snap = NuFormDraft::snapshotOf($d);
			$base = NuFormDraft::snapshotOf($d, 'base_snapshot');
			if ($action === 'draft.conflict') NuBuilderApi::out(['ok' => true, 'conflict' => NuFormSnapshot::hash($live, $base) !== (string)$d['base_hash'], 'changes' => NuFormSnapshot::diff($base, $live)]);
			$from = ($in['against'] ?? 'live') === 'base' ? $base : $live;
			NuBuilderApi::out(['ok' => true, 'diff' => NuFormSnapshot::diff($from, $snap), 'ddl' => json_decode((string)($d['ddl'] ?? '[]'), true) ?: [],
				'validation' => NuFormDraft::validate($formId, $snap, $live)]);

		case 'draft.publish':
			NuBuilderApi::form($formId);
			$d = NuFormDraft::get($draftId);
			if (!$d || (string)$d['form_id'] !== $formId) NuBuilderApi::err(t('record_not_found'), 404);
			if (!NuFormDraft::canPublish()) NuBuilderApi::err(t('err_forbidden'), 403);
			$force = !empty($in['force']);
			if ($force
				&& trim((string)($in['confirm_code'] ?? '')) !== (string)(NuFormDraft::snapshotOf($d)['form']['sfo_code']
					?? '')) NuBuilderApi::err(t('builder_draft_overwrite_type', (string)(NuFormDraft::snapshotOf($d)['form']['sfo_code'] ?? '')));
			$r = NuFormDraft::publish($draftId, (string)($in['note'] ?? ''), $force, !empty($in['warnings_ok']));
			NuBuilderApi::out($r + ($r['ok'] ? NuBuilderApi::state($formId) : []));

		case 'draft.discard':
			NuBuilderApi::form($formId);
			$d = NuFormDraft::get($draftId);
			if (!$d || (string)$d['form_id'] !== $formId) NuBuilderApi::err(t('record_not_found'), 404);
			NuFormDraft::discard($draftId);
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		case 'form.publishnew':
			NuBuilderApi::form($formId);
			$v = NuFormDraft::publishNewForm($formId);
			NuBuilderApi::out(['ok' => true, 'version' => $v] + NuBuilderApi::state($formId));

		// --------------------------------------------------------------- read
		case 'state':
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		case 'tables':
			$tables = match ($db->type) {
				'mysql'  => $db->all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME NOT LIKE 'zzzzsys%' ORDER BY TABLE_NAME"),
				'sqlsrv' => $db->all("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME NOT LIKE 'zzzzsys%' ORDER BY TABLE_NAME"),
				default  => $db->all("SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'zzzzsys%' ORDER BY name"),
			};
			// views: marked in the New form wizard; 'tables' stays a plain list of names (design mode uses it too)
			$views = match ($db->type) {
				'mysql'  => $db->all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW' AND TABLE_NAME NOT LIKE 'zzzzsys%'"),
				'sqlsrv' => $db->all("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'VIEW' AND TABLE_NAME NOT LIKE 'zzzzsys%'"),
				default  => $db->all("SELECT name AS t FROM sqlite_master WHERE type = 'view' AND name NOT LIKE 'zzzzsys%'"),
			};
			if ($db->type !== 'mysql' && $db->type !== 'sqlsrv') {
				$tables = array_merge($tables, $views);
				usort($tables, static fn($a, $b) => strcasecmp((string)$a['t'], (string)$b['t']));
			}
			NuBuilderApi::out(['ok' => true, 'tables' => array_column($tables, 't'), 'views' => array_column($views, 't')]);

		case 'files':
			$files = $db->tableExists('zzzzsys_file') ? $db->all('SELECT zzzzsys_file_id, sfi_code, sfi_description FROM zzzzsys_file ORDER BY sfi_code') : [];
			NuBuilderApi::out(['ok' => true, 'files' => array_map(static fn($f) => [$f['zzzzsys_file_id'], $f['sfi_code'] . ($f['sfi_description'] ? ' – ' . $f['sfi_description'] : '')], $files)]);

		case 'columns':
			$cols = $db->columns(NuDB::ident((string)($in['table'] ?? '')));
			$lbl  = NuBuilderGen::defaultLabels(array_map('strval', array_keys($cols)));     // placeholder labels of the wizard's column list
			$table = (string)($in['table'] ?? '');
			NuBuilderApi::out(['ok' => true, 'columns' => array_map(static fn($n, $c) => ['name' => $n, 'label' => $lbl[(string)$n] ?? ''] + $c, array_keys($cols), $cols),
				'view' => NuBuilderApi::viewInfo($table, array_map('strval', array_keys($cols)))]);

		// --------------------------------------------------------------- form
		case 'form.create':
			NuBuilderApi::out(['ok' => true, 'form_id' => NuBuilderGen::create($in)]);

		case 'form.save':
			$meta   = NuBuilderApi::form($formId);
			$fields = NuBuilderApi::clean(is_array($in['fields'] ?? null) ? $in['fields'] : [], NB_FORM_FIELDS);
			// required as in nuBuilder's form properties (nuform: description, code, type); nb_clean turned '' into null
			foreach (['sfo_description', 'sfo_code', 'sfo_type'] as $rk) if (array_key_exists($rk, $fields) && trim((string)$fields[$rk]) === '') NuBuilderApi::err(t('builder_err_required'));
			if (isset($fields['sfo_code']) && (int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ? AND zzzzsys_form_id <> ?', [$fields['sfo_code'], $formId])) {
				NuBuilderApi::err(t('builder_err_code_taken'));
			}
			if (array_key_exists('layout', $in['fields'] ?? [])) {
				$layout = in_array($in['fields']['layout'], ['absolute', 'auto', 'grid'], true) ? $in['fields']['layout'] : 'absolute';
				$fields['sfo_json'] = NuFormMeta::mergeNunextJson($meta->form['sfo_json'] ?? null, ['layout' => $layout === 'absolute' ? null : $layout]);
			}
			if ($tags = NuFormMeta::tagsIn(is_array($in['fields'] ?? null) ? $in['fields'] : []) + NuFormMeta::browseSqlIn($in['fields'] ?? null)
					+ NuFormMeta::recordCheckIn(is_array($in['fields']
				?? null) ? $in['fields'] : []) + NuUserHelp::formKeysIn(is_array($in['fields']
				?? null) ? $in['fields'] : []) + NuRowDetails::keysIn(is_array($in['fields']
				?? null) ? $in['fields'] : []) + NuCalendar::keysIn(is_array($in['fields'] ?? null) ? $in['fields'] : []) + NuRecycle::keysIn(is_array($in['fields']
				?? null) ? $in['fields'] : []) + NuRecordView::keysIn(is_array($in['fields'] ?? null) ? $in['fields'] : [])
					+ NuFormMeta::auditIn(is_array($in['fields'] ?? null) ? $in['fields'] : [])) {      // department / team, help pages: sfo_json nunext
				$fields['sfo_json'] = NuFormMeta::mergeNunextJson($fields['sfo_json'] ?? ($meta->form['sfo_json'] ?? null), $tags);
			}
			if ($fields) $db->update('zzzzsys_form', $fields, 'zzzzsys_form_id', $formId);
			if (is_array($in['php'] ?? null)) {
				foreach (NB_HOOKS as $h) if (array_key_exists($h, $in['php'])) NuBuilderApi::upsertPhp($formId . '_' . $h, $in['php'][$h] === null ? null : (string)$in['php'][$h]);
			}
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		case 'rules.save':             // validation rules: sfo_json nunext.rules
			$meta = NuBuilderApi::form($formId);
			$cl = NuFormRules::clean(is_array($in['rules'] ?? null) ? $in['rules'] : [], $meta);
			if ($cl['errors']) NuBuilderApi::out(['ok' => false, 'error' => implode("\n", $cl['errors']), 'errors' => $cl['errors']], 400);
			$db->update('zzzzsys_form', ['sfo_json' => NuFormMeta::mergeNunextJson($meta->form['sfo_json'] ?? null, ['rules' => $cl['rules'] ?: null])], 'zzzzsys_form_id', $formId);
			NuFormMeta::forget($formId);
			NuBuilderApi::out(['ok' => true, 'rules' => $cl['rules']] + NuBuilderApi::state($formId));

		case 'rules.parse':
		case 'rules.test':
			NuBuilderApi::rules(NuBuilderApi::form($formId), $action, $in);

		case 'form.deleteinfo':
			if (empty(NuAuth::user()['globeadmin'])) NuBuilderApi::err(t('builder_delete_globeadmin'), 403);
			if (NuFormDraft::openFor($formId)) NuBuilderApi::err(t('builder_draft_delete_first'));
			$info = NuBuilderApi::tableInfo(NuBuilderApi::form($formId));
			if ($info['reauth']) $_SESSION[NuSess::REAUTH_NEXT] = NuBuilderApi::back($in) ?? nu_url('builder', ['f' => $formId, 'delete' => '1']);
			NuBuilderApi::out(['ok' => true] + $info + ($info['reauth'] ? ['reauth_url' => nu_url('reauth')] : []));

		case 'form.delete':
			// a whole form (objects, tabs, events, PHP, rights) is deleted by the globeadmin only
			if (empty(NuAuth::user()['globeadmin'])) NuBuilderApi::err(t('builder_delete_globeadmin'), 403);
			if (NuFormDraft::openFor($formId)) NuBuilderApi::err(t('builder_draft_delete_first'));
			$meta = NuBuilderApi::form($formId);
			// with the table: the typed name must match exactly; checked again here (rights, not a view, not used elsewhere, password)
			$drop = (string)($in['drop_table'] ?? '');
			if ($drop !== '') {
				$info = NuBuilderApi::tableInfo($meta);
				if (!$info['can_drop']) NuBuilderApi::err(t('builder_drop_not_allowed'), 403);
				if ($drop !== $info['table']) NuBuilderApi::err(t('builder_drop_mismatch'));
				if ($info['reauth']) NuBuilderApi::err(t('reauth_needed'), 403);
			}
			$db->transaction(static function (NuDB $db) use ($formId) {
				NuFormSnapshot::remove($db, $formId);
			});
			NuPage::forget($formId);                       // settings of a page form
			NuLog::write('form_deleted', $meta->code(), $formId, ['table' => $meta->table()]);
			if ($drop === '') NuBuilderApi::out(['ok' => true]);
			// DDL ends a transaction on MySQL – the table goes after the form; if it fails, the form is gone and the table stays
			try {
				$db->run('DROP TABLE ' . NuDB::ident($drop));
				NuDB::forgetColumns($drop);
			} catch (Throwable $e) {
				NuBuilderApi::out(['ok' => true, 'warning' => t('builder_drop_failed', $drop) . (empty(NuAuth::user()['globeadmin']) ? '' : ' ' . $e->getMessage())]);
			}
			NuLog::write('table_dropped', $drop, $formId, ['form' => $meta->code(), 'rows' => $info['rows']]);
			NuBuilderApi::out(['ok' => true, 'dropped' => $drop]);

		// --------------------------------------------------------------- objects
		case 'object.clone':                                   // object menu → Actions → Clone, then as object.save
			if ($action === 'object.clone') {
				$cid = (string)($in['object_id'] ?? '');
				NuBuilderApi::clonePrep($in, $db->one('SELECT * FROM zzzzsys_object WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [$cid, $formId]) ?: null,
					array_map('strval', array_column($db->all('SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$formId]), 'sob_all_id')),
					$db->all('SELECT sev_event, sev_javascript FROM zzzzsys_event WHERE sev_zzzzsys_object_id = ? ORDER BY zzzzsys_event_id', [$cid]),
					$db->value('SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$cid . '_AB']));
			}
			// no break: the copy is saved as a new object
		case 'object.save':
			$meta     = NuBuilderApi::form($formId);
			$objectId = (string)($in['object_id'] ?? '');
			$fields   = NuBuilderApi::clean(is_array($in['fields'] ?? null) ? $in['fields'] : [], NB_OBJECT_FIELDS);
			$existing = $objectId !== '' ? $db->one('SELECT * FROM zzzzsys_object WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [$objectId, $formId]) : null;
			if ($objectId !== '' && !$existing) NuBuilderApi::err(t('record_not_found'), 404);
			foreach (['sob_input_format', 'sob_calc_format'] as $fk) {
				if (isset($fields[$fk]) && mb_strlen((string)$fields[$fk]) > NuFormat::MAX_KEY) NuBuilderApi::err(t('fmt_err_length', NuFormat::MAX_KEY - 2));
				NuFormat::ensureSaved($fields[$fk] ?? null);           // built-in format → zzzzsys_format, so nuBuilder knows it
			}

			if (isset($fields['sob_all_id'])) $fields['sob_all_id'] = trim((string)$fields['sob_all_id']);   // spaces at the ends
			if (isset($fields['sob_input_icon']) && mb_strlen((string)$fields['sob_input_icon']) > 50) NuBuilderApi::err(t('builder_obj_icon') . ': ' . t('err_too_long', 50));   // VARCHAR(50) in nuBuilder
			$type = (string)($fields['sob_all_type'] ?? $existing['sob_all_type'] ?? '');
			$fid  = (string)($fields['sob_all_id'] ?? $existing['sob_all_id'] ?? '');
			if (!in_array($type, NB_TYPES, true)) NuBuilderApi::err(t('builder_err_type'));
			// an id nuBuilder Forte allowed (umlauts …) is kept as long as it is not changed
			if ($fid !== (string)($existing['sob_all_id'] ?? "\0") && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fid)) NuBuilderApi::err(t('builder_err_id'));
			if ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_id = ? AND zzzzsys_object_id <> ?', [$formId, $fid, $objectId])) {
				NuBuilderApi::err(t('builder_err_id_taken', $fid));
			}
			if (isset($fields['sob_all_zzzzsys_tab_id']) && $fields['sob_all_zzzzsys_tab_id'] !== null
				&& !(int)$db->value('SELECT COUNT(*) FROM zzzzsys_tab WHERE zzzzsys_tab_id = ? AND syt_zzzzsys_form_id = ?', [$fields['sob_all_zzzzsys_tab_id'], $formId])) {
				NuBuilderApi::err(t('builder_err_tab'));
			}

			if (is_array($in['grid'] ?? null)) {
				$g = $in['grid'];
				$fields['sob_all_json'] = NuFormMeta::mergeNunextJson($existing['sob_all_json'] ?? null, NuResponsive::gridKeys($g, (string)($fields['sob_all_type'] ?? $existing['sob_all_type'] ?? '')));
			}

			try { $fields = NuFormMeta::objectNunextFields($fields, $existing ?: null); } catch (RuntimeException $x) { NuBuilderApi::err($x->getMessage()); }
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
					// unchanged events keep their rows (and ids) – as nuBuilder's sub-form
					$old = array_map(static fn($r) => [(string)$r['sev_event'], (string)$r['sev_javascript']],
						$db->all('SELECT sev_event, sev_javascript FROM zzzzsys_event WHERE sev_zzzzsys_object_id = ? ORDER BY zzzzsys_event_id', [$objectId]));
					$key = static fn(array $l) => json_encode(array_map(static fn($x) => $x[0] . "\n" . $x[1], $l));
					$sort = static function (array $l) { usort($l, static fn($a, $b) => strcmp($a[0] . $a[1], $b[0] . $b[1])); return $l; };
					if ($key($sort($old)) !== $key($sort($new))) {
						$db->delete('zzzzsys_event', 'sev_zzzzsys_object_id', $objectId);
						foreach ($new as [$ev, $js]) $db->insert('zzzzsys_event', ['zzzzsys_event_id' => nu_id(), 'sev_zzzzsys_object_id' => $objectId, 'sev_event' => $ev, 'sev_javascript' => $js]);
					}
				}
				if (array_key_exists('lookup_php', $in)) NuBuilderApi::upsertPhp($objectId . '_AB', $in['lookup_php'] === null ? null : (string)$in['lookup_php']);
				if (!empty($in['clone_extra'])) {                     // object.clone: the columns object.save does not take
					$x = array_intersect_key($in['clone_extra'], $db->columns('zzzzsys_object') ?: $in['clone_extra']);
					if ($x) $db->update('zzzzsys_object', $x, 'zzzzsys_object_id', $objectId);
				}
			});
			if ($type === 'subform') {                                // the sub-form's link column gets an index
				$so = $db->one('SELECT o.sob_subform_foreign_key, f.sfo_table FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_subform_zzzzsys_form_id WHERE o.zzzzsys_object_id = ?', [$objectId]);
				if ($so && trim((string)$so['sfo_table']) !== '' && trim((string)$so['sob_subform_foreign_key']) !== '') {
					try { NuDDL::ensureIndex((string)$so['sfo_table'], (string)$so['sob_subform_foreign_key']); } catch (Throwable $e) { error_log('nuBuilder Next index: ' . $e->getMessage()); }
				}
			}
			NuBuilderApi::out(['ok' => true, 'object_id' => $objectId, 'missing_column' => NuBuilderApi::missingColumn($meta, $objectId)] + NuBuilderApi::state($formId));

		case 'column.add':
			// adds the column an object needs to its form's table (confirmed by the user in the builder)
			$meta     = NuBuilderApi::form($formId);
			$objectId = (string)($in['object_id'] ?? '');
			$o        = $db->one('SELECT * FROM zzzzsys_object WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [$objectId, $formId]);
			if (!$o) NuBuilderApi::err(t('record_not_found'), 404);
			if (NuBuilderApi::missingColumn($meta, $objectId) === null) NuBuilderApi::err(t('builder_err_column_exists'));
			$type   = (string)($in['type'] ?? '');
			$length = trim((string)($in['length'] ?? ''));
			if (!in_array($type, NuDDL::TYPES, true)) NuBuilderApi::err(t('builder_err_column_type', (string)$o['sob_all_id']));
			if ($length !== '' && !preg_match('/^\d{1,5}([,.]\d{1,2})?$/', $length)) NuBuilderApi::err(t('builder_err_column_length', (string)$o['sob_all_id']));
			NuDDL::addColumn($meta->table(), (string)$o['sob_all_id'], $type, $length);
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		case 'object.delete':
			NuBuilderApi::form($formId);
			$objectId = (string)($in['object_id'] ?? '');
			$db->transaction(static function (NuDB $db) use ($objectId, $formId) {
				$db->run('DELETE FROM zzzzsys_object WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [$objectId, $formId]);
				$db->delete('zzzzsys_event', 'sev_zzzzsys_object_id', $objectId);
				$db->delete('zzzzsys_php', 'zzzzsys_php_id', $objectId . '_AB');
			});
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		case 'objects.geometry':
			NuBuilderApi::form($formId);
			$geo = NuBuilderApi::geometry(is_array($in['items'] ?? null) ? $in['items'] : []);
			$db->transaction(static function (NuDB $db) use ($geo, $formId) {
				foreach ($geo as $oid => $f) {
					if ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_object WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [$oid, $formId])) $db->update('zzzzsys_object',
						$f, 'zzzzsys_object_id', $oid);
				}
			});
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		case 'object.order':
			NuBuilderApi::form($formId);
			$ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
			$db->transaction(static function (NuDB $db) use ($ids, $formId) {
				foreach (array_values($ids) as $i => $oid) {
					$db->run('UPDATE zzzzsys_object SET sob_all_order = ? WHERE zzzzsys_object_id = ? AND sob_all_zzzzsys_form_id = ?', [($i + 1) * 10, (string)$oid, $formId]);
				}
			});
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		// --------------------------------------------------------------- tabs & browse columns (whole list replaced)
		case 'tabs.save':
			NuBuilderApi::form($formId);
			$rows = is_array($in['tabs'] ?? null) ? $in['tabs'] : [];
			if (!$rows) NuBuilderApi::err(t('builder_err_one_tab'));
			NuBuilderApi::tabDupe($rows, $db->all('SELECT zzzzsys_tab_id, syt_title FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ?', [$formId]));
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
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		case 'browse.save':
			NuBuilderApi::form($formId);
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
					if (array_key_exists('badge', $c)) $data['sbr_json'] = NuFormMeta::mergeNunextJson($data['sbr_json'], ['badge' => NuFormMeta::cleanBadge($c['badge'])]);
				if (array_key_exists('action', $c)) $data['sbr_json'] = NuFormMeta::mergeNunextJson($data['sbr_json'], ['action' => NuFormMeta::cleanAction($c['action'])]);   // On click
					if (array_key_exists('total', $c)) $data['sbr_json'] = NuFormMeta::mergeNunextJson($data['sbr_json'], ['total' => NuFormMeta::cleanTotal($c['total'])]);   // column total
					if (array_key_exists('group', $c)) $data['sbr_json'] = NuFormMeta::mergeNunextJson($data['sbr_json'], ['group' => !empty($c['group']) ? true : null]);   // group the list by this column
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
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));

		// --------------------------------------------------------------- layout
		case 'layout.convert':
			$meta = NuBuilderApi::form($formId);
			$db->transaction(static function (NuDB $db) use ($meta, $formId) {
				$tabs = $meta->tabs ?: [['zzzzsys_tab_id' => '']];
				foreach ($tabs as $t) {
					foreach (NuLayout::placement($meta->objectsOfTab((string)$t['zzzzsys_tab_id'])) as $oid => $pl) {
						$json = $db->value('SELECT sob_all_json FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [$oid]);
						$db->update('zzzzsys_object',
							['sob_all_json' => NuFormMeta::mergeNunextJson($json, ['row' => $pl['row'], 'col' => $pl['col'], 'span' => $pl['span'], 'box' => $pl['box'] ?: null])],
							'zzzzsys_object_id', (string)$oid);
					}
				}
				$db->update('zzzzsys_form', ['sfo_json' => NuFormMeta::mergeNunextJson($meta->form['sfo_json'] ?? null, ['layout' => 'grid'])], 'zzzzsys_form_id', $formId);
			});
			NuBuilderApi::out(['ok' => true] + NuBuilderApi::state($formId));
	}
	NuBuilderApi::err('Unknown action');
} catch (NuDraftRevException $e) {
	NuBuilderApi::out(['ok' => false, 'error' => $e->getMessage(), 'code' => 'draft_rev'], 409);          // the builder reloads the draft
} catch (Throwable $e) {
	error_log('nuBuilder Next builder: ' . $e->getMessage());
	NuBuilderApi::err($e->getMessage(), 500);
}
