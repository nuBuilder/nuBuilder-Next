<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Runs a nuBuilder form: browse list (no r) or edit view (r = record id, -1 = new).
 *   f=<form id or code>  r=<record id>  iframe=1  lookup=<target element id>&lo=<lookup object id>
 *   ps=<property scope key: nuSetProperty values of the opening form, kept in the session – NuProps>
 *   q/sort/dir/pg = browse state  layout=<preview override>
 */

NuTranslate::activate();   // texts in the reader's language (zzzzsys_translate)
// form drafts: draft=<id> starts the preview of a draft in this session, draft=off ends it
if (is_string($_GET['draft'] ?? null) && $_GET['draft'] !== '') {
	try { $_GET['draft'] === 'off' ? NuFormDraft::stopPreview() : NuFormDraft::startPreview($_GET['draft']); }
	catch (Throwable $e) { nu_flash('warning', $e->getMessage()); }
}
$fParam = (string)($_GET['f'] ?? '');
$meta   = NuFormMeta::load($fParam) ?? NuFormMeta::byCode($fParam);
if (!$meta) {
	http_response_code(404);
	nu_fatal(t('form_err_not_found'), $fParam, true);
}
NuAcl::require($meta->id);
// design mode (core/form/nudesign.php): an open draft of the form is shown while designing
$designReq = ($_GET['design'] ?? '') === '1' && NuDesign::allowed($meta);
if ($designReq && NuDesign::previewOpenDraft($meta)) {
	NuFormMeta::forget($meta->id);
	$meta = NuFormMeta::load($meta->id) ?? $meta;
}
// a form never published (status Draft) opens for designers only
if ((string)($meta->form['sfo_status'] ?? '') === '0' && !$meta->isDraft() && !NuAcl::can(NuAcl::FORM_FORM, 'view')) {
	http_response_code(403);
	nu_fatal(t('draft_not_published'), $meta->description(), true);
}
// Maintenance / Archived (core/nuformstatus.php): users get a message page; the globeadmin and form designers open the form
$formStatus = NuFormStatus::norm($meta->form['sfo_status'] ?? '');
if (!$meta->isDraft()) NuFormStatus::gate($meta->id, $formStatus);
// form type "page": an iframe inside nuBuilder Next or a redirect to the page's own output (core/nupage.php)
if ($meta->type() === 'page') NuPage::wrapper($meta);

$u        = NuAuth::user();
$rights   = NuAcl::rights($meta->id);
$iframe   = ($_GET['iframe'] ?? '') === '1';
// the detail form of a list's row details (core/form/nurowdetails.php): read only in a frame – no save, delete, clone or new
$rdView   = $iframe && ($_GET['rdv'] ?? '') === '1';
if ($rdView) foreach (['save', 'delete', 'clone', 'new', 'create', 'add'] as $k) $rights[$k] = false;
$lookupTo = preg_replace('/[^\w]/', '', (string)($_GET['lookup'] ?? ''));
NuHash::$state = ['iframe' => $iframe ? '1' : '0', 'lookup_id' => $lookupTo];   // nuBuilder Forte's breadcrumb keys in nuHash()
$psIn    = is_string($_GET['ps'] ?? null) ? $_GET['ps'] : '';
[$props, $locked] = NuProps::load($psIn);     // properties of the opening form; names set in PHP are locked
$caller = NuHash::$caller = NuProps::caller($psIn);   // fields of the edit form it was opened from
$bare     = $iframe || $lookupTo !== '' || ($_GET['print'] ?? '') === '1';
$r        = isset($_GET['r']) ? (string)$_GET['r'] : null;
$btKey    = $bare ? null : (is_string($_GET['bt'] ?? null) ? $_GET['bt'] : null);
$trail    = NuTrail::get($btKey);                  // breadcrumbs of the forms this one was opened from (run objects, nuForm)

// browse-only form opened with a record → its redirect form (nuBuilder sfo_browse_redirect_form_id)
if ($r !== null && $r !== '' && !$meta->hasEdit()) {
	$redirect = trim((string)$meta->form['sfo_browse_redirect_form_id']);
	if ($redirect !== '') nu_redirect(nu_url('form', ['f' => $redirect, 'r' => $r, 'iframe' => $iframe ? '1' : null, 'bt' => $trail !== null ? $btKey : null]));
	$r = null;
}
if ($r === null || $r === '') {
	if (!$meta->hasBrowse()) $r = '-1';          // edit / launch forms open directly
}
$mode = ($r === null || $r === '') ? 'browse' : 'edit';
$design = $designReq && ($mode === 'edit' || $mode === 'browse') && !$bare && empty($_GET['print']);   // browse: the list's columns
$safe   = $design && NuDesign::safe();       // design mode without the form's own code: to fix a form whose code breaks it
if ($design && $_SERVER['REQUEST_METHOD'] === 'GET') NuOpened::touch('form', (string)$meta->id);   // "Last opened" of the forms list
// access level form type (as nuBuilder 4.9): "browse only" opens no record (also no new one), "edit only" no browse list
if ($meta->type() !== 'launch' && !$rights[$mode]) {
	http_response_code(403);
	nu_fatal(t('acl_form_type_denied'), $meta->description(), true);
}

// forms run admin-authored JavaScript (like nuBuilder): this page needs inline scripts and eval.
// Charts (chart objects or nuChart() in the form's code) use Google Charts: www.gstatic.com is allowed only on those forms.
$usesCharts = (bool)array_filter($meta->objects, static fn($o) => $o['sob_all_type'] === 'chart' || preg_match('/nuChart|google\.(charts|visualization)/', (string)($o['sob_html_code'] ?? '')))
	|| preg_match('/nuChart|google\.(charts|visualization)/', $meta->form['sfo_javascript'] . $meta->form['sfo_browse_javascript'] . $meta->form['sfo_edit_javascript'] . ($meta->php('BE') ?? ''));
// form code that calls google.charts itself needs Google's loader on the page – nuBuilder Forte loads it with $nuConfigIncludeGoogleCharts
$googleLoader = $usesCharts
	&& (nu_cfg('nuConfigIncludeGoogleCharts')
		|| preg_match('/google\.(charts|visualization)/', $meta->form['sfo_javascript'] . $meta->form['sfo_browse_javascript']
			. $meta->form['sfo_edit_javascript'] . ($meta->php('BE') ?? '')
			. implode('', array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects))));
$gs = $usesCharts ? ' https://www.gstatic.com' : '';
$inc = NuIncludes::cspSources();   // $nuConfigIncludeJS / CSS from other hosts
$fr = NuRender::urlFrameSources($meta->objects);   // run objects of type URL shown as iframe: their hosts
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline'$gs{$inc['style']}; script-src 'self' 'unsafe-inline' 'unsafe-eval'$gs{$inc['script']}; font-src 'self' data:; connect-src 'self'$gs; frame-src 'self'$fr; object-src 'self' data: blob:; frame-ancestors 'self'; form-action 'self'; base-uri 'none'");
header('X-Frame-Options: SAMEORIGIN');

$layout = $meta->layout();
if ($u['globeadmin'] && in_array($_GET['layout'] ?? '', ['absolute', 'auto', 'grid'], true)) $layout = $_GET['layout'];

$js     = [];       // JS from hooks (nuAddJavascript)
$errors = [];
$breadcrumbs = [['title' => t('nav_home'), 'url' => nu_url('home')]];

// ===================================================================== browse
$state = [];                                             // set by the list branch below; empty on edit pages (PHPStan)
$data = null;
if ($mode === 'browse') ['hash' => $hash, 'js' => $js, 'errors' => $errors, 'props' => $props, 'locked' => $locked, 'pageScope' => $pageScope,
	'state' => $state, 'printMode' => $printMode, 'data' => $data, 'browseErr' => $browseErr, 'title' => $title, 'breadcrumbs' => $breadcrumbs]
	= NuFormPage::browse(compact('meta', 'props', 'locked', 'caller', 'rights', 'lookupTo', 'u', 'errors', 'breadcrumbs'));   // core/form/nuformpage.php (code review R7)

// ===================================================================== edit
$dbRow = [];
$isNew = false;
if ($mode === 'edit') ['isNew' => $isNew, 'dbRow' => $dbRow, 'hash' => $hash, 'js' => $js, 'errors' => $errors, 'props' => $props,
	'locked' => $locked, 'pageScope' => $pageScope, 'render' => $render, 'body' => $body, 'title' => $title, 'breadcrumbs' => $breadcrumbs]
	= NuFormPage::edit(compact('meta', 'r', 'rights', 'safe', 'design', 'props', 'locked', 'caller', 'layout', 'mode', 'errors', 'u', 'breadcrumbs'));


/** Query parameters that keep the browse state (search, sort, filters, props …) in links. */
$keep = $mode === 'browse' ? array_filter([
	'f' => $meta->id, 'q' => $state['q'], 'sort' => $state['sort'], 'dir' => $state['sort'] !== null ? $state['dir'] : null,
	'flt' => $state['flt'] ?: null, 'adv' => NuAdvSearch::encode($state['adv']) ?: null, 'iframe' => $iframe ? '1' : null, 'lookup' => $lookupTo ?: null, 'lo' => $_GET['lo'] ?? null, 'ps' => $pageScope,
	'rf' => $state['rf'], 'bt' => $trail !== null ? $btKey : null, 'per' => $state['per'] ?: null,
	'nsc' => $state['nsc'] ? implode(',', $state['nsc']) : null, 'sc' => $state['sc'] ? implode(',', $state['sc']) : null,
], static fn($v) => $v !== null && $v !== '') : [];

// opened from another form: Home + that trail + this page
if ($trail !== null) $breadcrumbs = array_merge([$breadcrumbs[0]], $trail, [end($breadcrumbs)]);
// trails for forms opened from here: child = add a breadcrumb, same = replace this one
$selfTrail = array_slice($breadcrumbs, 1);
$selfTrail[count($selfTrail) - 1]['url'] = NuTrail::currentUrl();
$bt = $bare ? ['child' => '', 'same' => ''] : ['child' => NuTrail::store($selfTrail), 'same' => NuTrail::store(array_slice($selfTrail, 0, -1))];

// a form opens instead of the tile page (start page "Home form" of the access level / $nuConfigGlobeadminHome): the first
// breadcrumb is that form, as in nuBuilder Forte – "Home" would only lead back to it. On the form itself (or below it) no extra crumb.
if (!$bare && $u) {
	$homeForm = !empty($u['globeadmin']) ? NuHome::globeadminForm() : NuHome::startForm($u);
	if ($homeForm !== null) {
		$first = $breadcrumbs[1]['url'] ?? '';
		parse_str((string)parse_url($first, PHP_URL_QUERY), $fq);
		$fromHome = $trail !== null && (string)($fq['f'] ?? '') === $homeForm;
		if (($homeForm === (string)$meta->id && $trail === null) || $fromHome) array_shift($breadcrumbs);
		else {
			$hf = nu_forms()[$homeForm] ?? null;
			$breadcrumbs[0] = ['title' => $hf ? ($hf['description'] !== '' ? $hf['description'] : $hf['code']) : t('nav_home'), 'url' => nu_url('form', ['f' => $homeForm])];
		}
	}
}
// iframes and lookup popups have their own trail, starting with their form (nuGetBreadcrumb(0) = reload)
if ($bare) $breadcrumbs = array_values(array_slice($breadcrumbs, 1));

// ===================================================================== client data

// click actions of list cells: not in lookups (a click chooses the row there), not in print view
$cellActs = [];
if ($mode === 'browse' && $lookupTo === '' && empty($printMode)) foreach ((array)($data['columns'] ?? []) as $c) {
	if (is_array($c) && !empty($c['action']) && ($ca = NuRender::cellAction($c['action'])) !== null) $cellActs[$c['index']] = $ca;
}
// row details (core/form/nurowdetails.php): a ▸ column at the start of each row – not in lookups, print view or design mode
$rowDetails = $mode === 'browse' && $lookupTo === '' && empty($printMode) && !$design ? NuRowDetails::client($meta) : null;
// "Edit browse SQL" next to the Design button of a list: the SQL in a side pane, saved with builderapi form.save
$browseSqlEdit = $mode === 'browse' && $lookupTo === '' && !$design && !$bare && empty($printMode) && NuDesign::allowed($meta);
// the edit form's code in a side pane (the owner picked mockup B of the canvas "Edit form code pane"): the parts of design mode's
// Code tab as a grouped list beside one editor, saved with builderapi form.save; Design ▾ → Code, Ctrl+Shift+B
$codeEdit = $mode === 'edit' && $lookupTo === '' && !$design && !$bare && empty($printMode) && NuDesign::allowed($meta);
$codePane = static function () use ($meta): array {
	$f = $meta->form;
	$it = static fn(string $key, string $label, string $lang, string $grp, ?string $val): array => [$key, $label, $lang, $grp, (string)($val ?? '')];
	if ($meta->isUserHome()) $items = [$it('php:BE', t('ecp_php_BE'), 'PHP', 'php', $meta->php('BE'))];   // User Home: Before Edit only, as design mode (was returned without 'items' / 'm')
	else {
		$items = [$it('sfo_javascript', t('ecp_js_both'), 'JS', 'js', $f['sfo_javascript'] ?? ''), $it('sfo_edit_javascript', t('ecp_js_edit'), 'JS', 'js', $f['sfo_edit_javascript'] ?? ''),
			$it('sfo_style', t('builder_css'), 'CSS', 'style', $f['sfo_style'] ?? '')];
		foreach (['BE', 'BS', 'AS', 'BD', 'AD'] as $h) $items[] = $it('php:' . $h, t('ecp_php_' . $h), 'PHP', 'php', $meta->php($h));
		if ($meta->hasBrowse()) {                                 // the list's parts – greyed at the end, they act on the list only
			$items[] = $it('sfo_browse_javascript', t('ecp_js_list'), 'JS', 'list', $f['sfo_browse_javascript'] ?? '');
			$items[] = $it('php:BB', t('ecp_php_BB'), 'PHP', 'list', $meta->php('BB'));
			$items[] = $it('sfo_browse_sql', t('builder_browse_sql'), 'SQL', 'list', $f['sfo_browse_sql'] ?? '');
		}
	}
	return ['items' => $items, 'm' => ['title' => t('ecp_title'), 'hint' => t('ecp_hint'), 'save' => t('save'), 'close' => t('close'), 'unsaved' => t('bsq_unsaved'),
		'error' => t('form_error'), 'resize' => t('bsq_resize'), 'code_has' => t('code_has'),
		'groups' => ['js' => t('bsq_tab_js'), 'style' => t('ecp_grp_style'), 'php' => t('bsq_tab_php'), 'list' => t('ecp_grp_list')]]];
};
const NU_BRUN_TEXTS = ['title', 'ro', 'list_only', 'rows', 'prev', 'next', 'none', 'rows_of', 'rows_range', 'capped', 'n_rows', 'line', 'failed',
	'csv', 'show_sql', 'copied', 'run', 'run_tip'];   // texts brun_* of the result window
const NU_BSQ_TEXTS = ['ds_list_settings', 'builder_rows_per_page', 'ds_list_rowh', 'ds_list_rowh_tip', 'ds_list_target', 'ds_list_target_0',
	'ds_list_target_1', 'ds_list_target_2', 'ds_list_target_3', 'builder_redirect', 'ds_list_multi', 'ds_list_autofit', 'builder_sec_columns', 'builder_col_title',
	'builder_col_display', 'builder_col_width', 'builder_col_align', 'ds_col_hidden', 'ds_col_show', 'ds_col_left', 'ds_col_right', 'delete', 'ds_list_nocols',
	'builder_add_column', 'ds_col_new', 'om_left', 'om_center', 'om_right', 'please_select', 'ds_col_drag',
	'bsq_tab_php', 'bsq_tab_js', 'code_has', 'bsq_col_more', 'bsq_col_access', 'builder_col_format', 'builder_col_filter', 'ds_col_badge', 'ds_col_badge_tip', 'bsq_badge_rules',
	'ds_col_action', 'ds_col_action_tip', 'ds_act_none', 'ds_act_form', 'ds_act_list', 'ds_act_proc', 'ds_act_report', 'ds_act_js', 'ds_act_target_form', 'ds_act_target_list',
	'builder_run_procedure', 'builder_run_report', 'ds_act_fn', 'ds_act_fn_tip', 'ds_act_rec', 'ds_act_rec_row', 'ds_act_rec_value', 'ds_act_rec_new', 'ds_act_open',
	'ds_act_open_same', 'ds_act_open_tab', 'ds_act_open_popup', 'ds_act_pass', 'ds_act_pass_tip', 'ds_act_look', 'ds_act_look_link', 'ds_act_look_button', 'ds_act_look_icon',
	'ds_act_icon', 'ds_act_icon_tip', 'ds_act_label', 'ds_act_label_tip', 'ds_act_need_target', 'ds_rd_title', 'ds_rd_cols', 'ds_rd_cols_tip',
		'ds_rd_none', 'ds_rd_lists', 'ds_rd_no_lists', 'ds_rd_own', 'ds_rd_own_tip', 'ds_rd_form', 'ds_rd_form_tip', 'ds_rd_mode', 'ds_rd_mode_many',
		'ds_rd_mode_one',
	'fmt_type_date', 'fmt_type_number', 'fmt_type_text', 'ds_col_total', 'ds_col_total_tip', 'ds_tot_none', 'ds_tot_sum', 'ds_tot_avg', 'ds_tot_min',
		'ds_tot_max', 'ds_tot_count', 'ds_col_group', 'ds_col_group_tip',
	'bsq_written', 'bsq_as_run', 'bsq_run_mode', 'bsq_run_note', 'bsq_run_vars', 'bsq_run_none', 'bsq_run_filled', 'bsq_run_unset', 'fi_hash_unset', 'copy'];
$bsqList = static function () use ($meta, $browseSqlEdit): array {
	if (!$browseSqlEdit) return [];
	$f = $meta->form;
	$forms = array_map(static fn($x) => [(string)$x['zzzzsys_form_id'], $x['sfo_code'] . ' – ' . $x['sfo_description']],
		nu_db()->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' AND sfo_type IN ('edit', 'browseedit') ORDER BY sfo_code"));
	$cols = array_map(static function ($b) {
		$j = json_decode((string)($b['sbr_json'] ?? ''), true);
		$badge = $j['nunext']['badge'] ?? null;
		$act = $j['nunext']['action'] ?? null;
		return [(string)$b['zzzzsys_browse_id'], (string)($b['sbr_title'] ?? ''), (string)($b['sbr_display'] ?? ''), (string)($b['sbr_align'] ?? '') ?: 'l', (string)($b['sbr_format'] ?? ''),
			($b['sbr_width'] ?? '') === '' || $b['sbr_width'] === null ? 100 : (int)$b['sbr_width'], !empty($j['nunext']['filter']),
			$badge ? (is_array($badge) ? array_values($badge) : true) : null, is_array($act) && !empty($act['t']) ? $act : null,
				NuFormMeta::totalOf($b['sbr_json'] ?? null), NuFormMeta::groupOf($b['sbr_json'] ?? null)];
	}, $meta->browse);
	// more of the list in the pane: targets of a column's click action, formats, row details, the code tabs
	[$allForms, $procs, $reports] = NuDesign::lists();
	$subs = array_values(array_map(static fn($o) => [(string)$o['sob_all_id'], trim(strip_tags((string)$o['sob_all_label'])) ?: (string)$o['sob_all_id']],
		array_filter($meta->objects, static fn($o) => $o['sob_all_type'] === 'subform')));
	$det = NuRowDetails::of($meta) ?? [];
	return ['rows' => (string)($f['sfo_browse_rows_per_page'] ?? ''), 'rowh' => (string)($f['sfo_browse_row_height'] ?? ''), 'target' => (string)($f['sfo_browse_target'] ?? '') ?: '0',
		'redirect' => (string)($f['sfo_browse_redirect_form_id'] ?? ''), 'multi' => (string)($f['sfo_browse_title_multiline'] ?? '') === '1',
		'autofit' => (string)($f['sfo_browse_autoresize_columns'] ?? '') === '1', 'table' => (string)($f['sfo_table'] ?? ''), 'forms' => $forms, 'cols' => $cols,
		'targets' => ['forms' => $allForms, 'procs' => $procs, 'reports' => $reports], 'formats' => NuFormat::choices(), 'subs' => $subs,
		'details' => ['cols' => !empty($det['cols']), 'own' => !empty($det['own']), 'subs' => $det['subs'] ?? [], 'form' => (string)($det['form'] ?? ''), 'mode' => $det['mode'] ?? 'many'],
		'detail_lists' => NuRowDetails::candidates($meta), 'access' => $meta->isSystem() ? '' : nu_url('dataaccess', ['f' => $meta->id]),
		'php' => (string)($meta->php('BB') ?? ''), 'js' => (string)($f['sfo_browse_javascript'] ?? '')];
};
$client = [
	// tab Columns – settings of the list ($bsqList) and its columns [id, title, display, align, format, width, filter]
	'browse_sql' => $browseSqlEdit ? ['sql' => (string)($meta->form['sfo_browse_sql'] ?? ''), 'list' => $bsqList(),
		'run' => NuBrowse::$shown ? ['parts' => NuBrowse::$shown['parts'] ?? [], 'vars' => NuBrowse::$shown['vars']] : null,   // As run
		'm' => ['title' => t('builder_browse_sql'), 'hint' => t('bsq_hint'),
		'save' => t('save'), 'close' => t('close'), 'unsaved' => t('bsq_unsaved'), 'pin' => t('bsq_pin'), 'error' => t('form_error'), 'resize' => t('bsq_resize')]
		+ array_combine(NU_BSQ_TEXTS, array_map('t', NU_BSQ_TEXTS))] : null,
	// ▶ Run of the browse SQL editors (pages/nubrowserun.php, nuruntime.js N.browseRun): those who may design the form
	'browse_run' => ($browseSqlEdit || $design) && NuDesign::allowed($meta) ? ['url' => nu_url('browserun'),
		'm' => array_combine(NU_BRUN_TEXTS, array_map(static fn($k) => t('brun_' . $k), NU_BRUN_TEXTS)) + ['close' => t('close')]] : null,
	'save_target' => $codeEdit || $browseSqlEdit ? NuFormDraft::saveTarget($meta->id) : null,   // label in the title of the code side panels
	'code_pane' => $codeEdit ? $codePane() : null,                            // Design ▾ → Code on edit forms
	'base' => 'index.php', 'csrf' => nu_csrf_token(), 'bt' => $bt, 'mode' => $mode, 'iframe' => $iframe, 'rdv' => $rdView, 'lookup' => $lookupTo,
	'form' => [
		'id' => $meta->id, 'code' => $meta->code(), 'description' => $meta->description(), 'type' => $meta->type(),
		'table' => $meta->table(), 'primary_key' => $meta->primaryKey(), 'layout' => $layout,
		'redirect' => trim((string)$meta->form['sfo_browse_redirect_form_id']),
		// nuBuilder Forte's list settings: 0 new breadcrumb, 1 current breadcrumb, 2 new tab, 3 popup
		'target' => in_array((string)($meta->form['sfo_browse_target'] ?? ''), ['1', '2', '3'], true) ? (string)$meta->form['sfo_browse_target'] : '0',
	],
	'builder' => (($_GET['builder'] ?? '') === '1' && NuAcl::can(NuAcl::FORM_FORM)) || ($design && $mode === 'edit'),
	'design' => $design ? NuDesign::client($meta, (string)$r) : null,          // design mode: assets/js/nu/nudesign.js
	'draft' => NuFormDraft::previewInfo($meta->id),          // form drafts: the draft shown (label menu, Save in the preview)
	// launch form of a report (?p=report&c=…): its fields are the criteria, a button runs the report
	'report' => $mode === 'edit' && is_string($_GET['rpt'] ?? null) && ($rp = NuReport::load((string)$_GET['rpt'])) && NuReport::canRun($rp)
		? ['code' => (string)$rp['sre_code'], 'description' => (string)$rp['sre_description']] : null,
	'record_id' => $mode === 'edit' ? $r : '', 'is_new' => $mode === 'edit' && $r === '-1', 'saved' => ($_GET['saved'] ?? '') === '1',
	'rights' => $rights,
	// concurrent edits (core/form/nuconflict.php): signatures of the record as opened; the "also open by …" hint
	'cc' => $mode === 'edit' && !$isNew && $dbRow && $rights['save'] && NuConflict::enabled() && NuConflict::$formCheck !== false ? NuConflict::forRecord($meta, (string)$r, $dbRow) : null,
	'presence' => $mode === 'edit' && !$isNew && $dbRow && NuConflict::presenceEnabled() && NuConflict::$formPresence !== false,
	'fileMaxKB' => max(1, (int)(nu_cfg('nuConfigFileMaxKB') ?: 300)),
	'ux' => (object)NuSettings::uxOptions(),     // nuBuilder's nuUXOptions (settings table or nuconfig.php)
	'user' => [
		'id' => $u['id'], 'login' => $u['login'], 'display' => $u['display'],
		'globeadmin' => $u['globeadmin'], 'access_level_id' => $u['access_id'], 'access_level_code' => NuHash::base()['ACCESS_LEVEL_CODE'],
		'language' => NuTranslate::language(), 'permissions' => NuHash::permissions((string)$u['id']),
		'fields' => (object)NuUserFields::mine(),                 // custom user fields: nuNextUserField(code)
		'access_levels' => NuUserAccess::listOf(),                // main + active additional levels: nuNextAccessLevels()
		'home_id' => (string)(NuHash::base()['HOME_ID'] ?? ''),     // nuSERVERRESPONSE.home_id as nuBuilder Forte
	] + NuFormPage::userDetails($u),   // 'name' = the user's name (sus_name) – '' for the globeadmin, as nuBuilder Forte's nuUserName()
	// nuBuilder Forte's folder for relative images of form code (img src="custom/…" written for Forte): '' = none
	'forte_base' => NuFormPage::forteBase(),
	// nuBuilder Forte's browser tab title (shared settings nuShowBrowserTabTitle / nuBrowserTabTitlePrefix) – only when one is set
	'tab_title' => (static function (): ?array {
		$ux = NuSettings::uxOptions();
		if (!array_key_exists('nuBrowserTabTitlePrefix', $ux) && !array_key_exists('nuShowBrowserTabTitle', $ux)) return null;
		return ['prefix' => (string)($ux['nuBrowserTabTitlePrefix'] ?? 'nuBuilder'), 'show' => ($ux['nuShowBrowserTabTitle'] ?? true) !== false];
	})(),
	'translations' => (object)NuTranslate::dictionary(),
	'props' => (object)$props,
	'ps' => $pageScope, 'caller' => $caller !== [], 'locked' => array_keys($locked), 'globals' => (object)NuHashCookies::all(),
	'objects' => $mode === 'edit' ? (object)$render->clientObjects : (object)[],
	'subforms' => $mode === 'edit' ? (object)$render->clientSubforms : (object)[],
	'events' => $mode === 'edit' ? NuFormPage::clientEvents($meta) : (object)[],
	'breadcrumbs' => $breadcrumbs, 'title' => $title, 'errors' => $errors,
	// hl: the words / phrases of the search to highlight (NuBrowse::terms), nsc / sc: columns left out of / limited to by the search
	'browse' => $mode === 'browse' ? ['q' => $state['q'], 'hl' => NuBrowse::terms($state['q'])['in'], 'nsc' => array_values((array)$state['nsc']),
		'sc' => array_values((array)$state['sc']), 'sort' => $state['sort'], 'dir' => $state['dir'], 'page' => $data['page'] ?? 1, 'pages' => $data['pages']
		?? 1, 'total' => (int)($data['total'] ?? 0),
		// badges set in design mode: column index => rules of nuBrowseStyleBadge ([] = the default look), applied before nuOnLoad
		'details' => $rowDetails,
		// row of totals: column index => {fn, label, value, text} over all matching rows (form code: nuNext.browse.totals)
		'totals' => (object)($data['totals'] ?? []),
		'formats' => (object)array_map(static fn($c) => (string)$c['format'], array_intersect_key((array)($data['columns'] ?? []), (array)($data['totals'] ?? []))),
			// of the total columns: totals of selected rows
		'badges' => (object)array_map(static fn($c) => $c['badge'], array_filter((array)($data['columns'] ?? []), static fn($c) => is_array($c) && ($c['badge'] ?? null) !== null && !isset($cellActs[$c['index']]))),
		// click actions of cells: column index => NuRender::cellAction (only those the user may open; none in lookups)
		'actions' => (object)$cellActs,
		// nuCurrentProperties().browse_rows as nuBuilder Forte: the values as the browse SQL returned them, not the formatted cells
		'rows' => !empty($printMode) ? [] : array_map(static fn($r) => array_values(array_replace(array_fill(0, max(array_keys($r['raw'] ?? [0 => ''])) + 1, null), $r['raw'] ?? [])), (array)($data['rows'] ?? []))] : null,
	'adv' => $mode === 'browse' && $data && empty($printMode) ? NuAdvSearch::client($meta, $data['columns'], $state['adv']) : null,   // advanced search
	'rules' => $mode === 'edit' ? NuFormRules::client($meta, $hash) : null,   // validation rules (assets/js/nu/nurules.js)
	// keyboard shortcuts / options menu (nuBuilder Ctrl+Shift+…): only what exists in nuBuilder Next and the user may use
	'tools' => [
		'design' => !$meta->isSystem() && NuAcl::can(NuAcl::FORM_FORM),
		'debug' => NuAcl::can(NuAcl::FORM_DEBUG),
		'password' => !$u['globeadmin'] && ($u['via'] ?? '') !== 'nubuilder' && !NuImpersonate::active(),
		'pma' => NuAdmin::can('dbtool', 'change') && NuVendor::primaryDb() !== '',     // ⋮ → Database: phpMyAdmin or Adminer
		'tfm' => NuFileManager::visible(),                                      // File Manager = the page nuFiles
		'sessions' => NuAdmin::can('sessions'),
		'browsesql' => $mode === 'browse' && NuDesign::allowed($meta) ? nu_url('form', ['f' => $meta->id, 'design' => '1', 'dswork' => 'code', 'dsitem' => 'sfo_browse_sql']) : '',   // ⋮ → Browse SQL
		'recycle' => NuRecycle::days($meta) > 0 && $rights['delete'] ? nu_url('recyclebin', ['form' => $meta->id]) : '',   // ⋮ → Recycle bin
		'formaccess' => !$meta->isPage() && NuAcl::can(NuAcl::FORM_ACCESS) && empty($u['demo']),   // ⋮ → Access levels… (assets/js/nu/nuformaccess.js)
		// ⋮ → Find in this form's code: the code search with this form as filter
		'codesearch' => NuCodeSearch::allowed() ? nu_url('codesearch', ['f' => [$meta->id], 'go' => '1', 'sys' => $meta->isSystem() ? '1' : null, 'drafts' => '1', 'pages' => '1']) : '',
	],
	'shortcuts' => NuFormPage::shortcutLabels(), 'dev_mode' => NuHash::devMode(), 'db_type' => nu_db()->type, 'db_name' => $u['globeadmin']
		&& empty($u['demo']) ? (string)nu_cfg('nuConfigDBName') : '', 'hashCatalog' => $mode === 'edit' || $design ? NuHash::catalog() : null,
	'formats' => (object)NuFormat::clientRegistry(), 'tinymceBase' => NuLibs::tinymce() ?: NU_TINYMCE,
	// audit trail: "History of this record" in the ⋮ menu
	'audit' => $mode === 'edit' && $r !== null && $r !== '-1' && NuAudit::on() && NuAudit::audited($meta->table())
		? ['url' => nu_url('audithistory', ['f' => $meta->id, 'r' => (string)$r]), 'label' => t('aud_history')] : null,
	// nuBuilder's <table>_nulog of the record: "Added … / Last edited …" in the ⋮ menu
	'nulog' => $mode === 'edit' && $r !== null && $r !== '-1' ? (static function () use ($meta, $r): ?array {
		$i = NuRecord::nulogInfo($meta, (string)$r);
		if ($i === null) return null;
		$fmt = static fn(?array $e, string $key) => $e ? t($key, $e['name'] === 'unknown' ? t('nulog_unknown') : $e['name'], $e['when'] !== '' ? $e['when'] : '–') : null;
		return ['column' => $i['column'], 'added' => $fmt($i['added'], 'nulog_added'), 'edited' => $fmt($i['edited'], 'nulog_edited'), 'none' => t('nulog_none')];
	})() : null,
	// globeadmin: menu on the labels of the edit form for quick changes of an object (nuBuilder's label context menu); also on User Home,
	// whose objects the builder edits
	// browse: the same menu on a right-click of a column title – [id, title, display, align, format, width, filter] of every column
	'objmenu' => ($mode === 'edit' || ($mode === 'browse' && !$bare)) && ($u['globeadmin'] || $design) && (!$meta->isSystem() || $meta->isUserHome()) && NuAcl::can(NuAcl::FORM_FORM, 'save') ? [
		'cols' => $mode === 'browse' ? array_map(static function ($b) {
			$j = json_decode((string)($b['sbr_json'] ?? ''), true);
			return [(string)$b['zzzzsys_browse_id'], (string)($b['sbr_title'] ?? ''), (string)($b['sbr_display'] ?? ''), (string)($b['sbr_align'] ?? '') ?: 'l', (string)($b['sbr_format'] ?? ''),
				($b['sbr_width'] ?? '') === '' || $b['sbr_width'] === null ? 100 : (int)$b['sbr_width'], !empty($j['nunext']['filter'])];
		}, $meta->browse) : [],
		// [id, title as text, stored title, access 0 / 2 / 3] – the tab menu on a right-click of a tab saves the whole list
		'tabs' => array_map(static fn($t) => [(string)$t['zzzzsys_tab_id'], trim(strip_tags((string)$t['syt_title'])), (string)$t['syt_title'], (string)($t['syt_access'] ?? '0') ?: '0'], $meta->tabs),
		'design' => NuDesign::allowed($meta) ? nu_url('form', ['f' => $meta->id, 'r' => $r, 'design' => '1']) : '',
		// button settings dialog: lists of run targets, only when the form has a run object
		'run_lists' => $mode === 'edit' && array_filter($meta->objects, static fn($o) => $o['sob_all_type'] === 'run') ? (static function () {
			[$forms, $procs, $reports] = NuDesign::lists();
			return ['forms' => array_map(static fn($f) => [$f[0], $f[1]], $forms), 'procs' => $procs, 'reports' => $reports, 'pivots' => NuPivot::forRunButtons()];
		})() : null,
		'm' => ['object' => t('om_object'), 'copied' => t('om_copied'), 'properties' => t('om_properties'), 'rename' => t('om_rename'), 'rename_prompt' => t('om_rename_prompt'),
			'actions' => t('om_actions'), 'clone' => t('om_clone'), 'delete' => t('delete'), 'delete_confirm' => t('om_delete_confirm'), 'move' => t('om_move_tab'), 'access' => t('om_access'),
			'align' => t('om_align'), 'left' => t('om_left'), 'center' => t('om_center'), 'right' => t('om_right'), 'validation' => t('om_validation'),
			'unsaved' => t('om_unsaved'), 'error' => t('form_error'), 'sf_form' => t('om_sf_form'),
			'access_v' => array_map(static fn($i) => t('bf_access_' . $i), range(0, 4)), 'validate_v' => array_map(static fn($i) => t('bf_validate_' . $i), range(0, 3)),
			'tab' => t('om_tab'), 'tab_rename' => t('ds_tab_rename'), 'tab_rename_prompt' => t('om_tab_rename_prompt'), 'tab_access' => t('om_tab_access'),
			'tab_visible' => t('om_tab_visible'), 'tab_hide' => t('ds_tab_hide'), 'tab_hide_users' => t('ds_tab_hide_users'), 'tab_left' => t('ds_tab_left'),
			'tab_right' => t('ds_tab_right'), 'tab_delete' => t('ds_tab_delete'), 'tab_delete_confirm' => t('ds_tab_delete_confirm'),
			'tab_delete_confirm_n' => t('ds_tab_delete_confirm_n'), 'tab_design' => t('om_tab_design'),
			'col_width' => t('builder_col_width'), 'col_filter' => t('builder_col_filter'), 'col_left' => t('ds_col_left'), 'col_right' => t('ds_col_right'),
			'col_hide' => t('ds_col_hide'), 'col_delete_confirm' => t('ds_col_delete_confirm'),
			'settings' => t('om_settings'), 'settings_tip' => t('om_settings_tip'), 'set_run' => t('builder_run_type'), 'set_events' => t('builder_sec_events'), 'set_look' => t('om_set_look'),
			'add_event' => t('builder_add_event'), 'event' => t('builder_event'), 'label' => t('builder_obj_label'), 'icon' => t('builder_obj_icon'), 'icon_tip' => t('builder_obj_icon_tip'),
			'r_form' => t('builder_run_form'), 'r_proc' => t('builder_run_procedure'), 'r_report' => t('builder_run_report'), 'r_url' => t('builder_run_url'), 'r_pivot' => t('builder_run_pivot'),
			'r_record' => t('builder_run_record'), 'r_record_tip' => t('builder_run_record_help'), 'r_filter' => t('builder_run_filter'), 'r_filter_tip' => t('builder_run_filter_help'),
			'r_method' => t('builder_run_method'), 'r_b' => t('builder_run_b'), 'r_i' => t('builder_run_i'), 'r_open' => t('builder_run_target'),
			'r_types' => ['F' => t('builder_run_type_F'), 'P' => t('builder_run_type_P'), 'R' => t('builder_run_type_R'), 'U' => t('builder_run_type_U'), 'V' => t('builder_run_type_V')],
			'r_targets' => array_map(static fn($i) => t('builder_run_target_' . $i), range(0, 3)), 'r_url_same' => t('builder_run_url_same'), 'r_url_popup' => t('builder_run_url_popup'),
			'open_target' => t('om_open_target'), 'save' => t('save'), 'cancel' => t('cancel'), 'please_select' => t('please_select'),
			'geo_top' => t('builder_top'), 'geo_left' => t('builder_left'), 'geo_width' => t('builder_width'), 'geo_height' => t('builder_height')],
	] : null,
	'messages' => [
		'mask_incomplete' => t('fmt_err_incomplete'), 'saved' => t('saved'), 'deleted' => t('form_deleted'), 'cloned' => t('form_cloned'),
			'clone_badge' => t('form_clone_badge'), 'unsaved' => t('form_unsaved'), 'report_running' => t('rpt_running'), 'report_emailed' => t('rpt_emailed'),
			'delete_confirm' => t('form_delete_confirm'), 'no_results' => t('eff_none'), 'delete_title' => t('form_delete_title'),
			'delete_named' => t('form_delete_named'), 'delete_with' => t('form_delete_with'),
			'delete_undo' => NuRecycle::days($meta) > 0 ? t('rb_delete_note', NuRecycle::days($meta)) : t('form_delete_undo'), 'rb_undo' => t('rb_undo'),
			'rb_in_bin' => t('rb_in_bin'), 'error' => t('form_error'), 'php_warn' => t('php_warn'), 'php_warn_more' => t('php_warn_more'),
		'ok' => t('ok'), 'cancel' => t('cancel'), 'code_fullscreen' => t('form_code_fullscreen'), 'ta_full_exit' => t('form_ta_full_exit'), 'ph_fold_edit' => t('form_ph_fold_edit'), 'ph_fold_hide' => t('form_ph_fold_hide'), 'code_hint' => t('form_code_hint'),
			'code_readonly' => t('form_code_readonly'), 'code_discard' => t('form_code_discard'), 'code_dblclick' => t('form_code_dblclick'),
			'delete' => t('delete'), 'lookup' => t('form_lookup_title'), 'lk_clear' => t('lk_clear'), 'lk_clear_tip' => t('lk_clear_tip'),
			'lk_keys' => t('lk_keys'), 'js_errors' => t('form_js_errors'),
		'not_supported' => t('form_not_supported'), 'saving' => t('form_saving'), 'all' => t('all'), 'close' => t('close'), 'help' => t('sc_help'),
			'chart_no_data' => t('chart_no_data'), 'chart_load_failed' => t('chart_load_failed'), 'chart_no_type' => t('chart_no_type'),
		'hash_name' => t('hash_name'), 'hash_value' => t('hash_value'), 'hash_source' => t('hash_source'), 'hash_filter' => t('hash_filter'), 'hash_locked' => t('hash_locked'),
		'hash_src' => ['system' => t('hash_src_system'), 'user' => t('hash_src_user'), 'record' => t('hash_src_record'), 'property' => t('hash_src_property'), 'global' => t('hash_src_global'), 'caller' => t('hash_src_caller')],
		'file_too_large' => t('file_too_large'), 'empty' => t('form_filter_empty'),
		// concurrent edits
		'rd_show' => t('rd_show'), 'rd_hide' => t('rd_hide'), 'rd_open' => t('rd_open'), 'rd_more' => t('rd_more'), 'rd_empty' => t('rd_empty'),
			'rd_loading' => t('uh_loading'), 'rd_error' => t('rd_error'), 'rd_none_rows' => t('rd_none_rows'), 'rd_form' => t('rd_form'),
			'rd_expand_all' => t('rd_expand_all'), 'rd_collapse_all' => t('rd_collapse_all'),
		'cc_title' => t('form_cc_title'), 'cc_by' => t('form_cc_by'), 'cc_anon' => t('form_cc_anon'), 'cc_field' => t('form_cc_field'),
			'cc_original' => t('form_cc_original'), 'cc_yours' => t('form_cc_yours'), 'cc_theirs' => t('form_cc_theirs'),
			'cc_keep_mine' => t('form_cc_keep_mine'), 'cc_keep_theirs' => t('form_cc_keep_theirs'), 'cc_row' => t('form_cc_row'),
			'cc_row_gone' => t('form_cc_row_gone'), 'cc_row_changed' => t('form_cc_row_changed'), 'cc_delete_row' => t('form_cc_delete_row'),
			'cc_keep_row' => t('form_cc_keep_row'), 'cc_kept' => t('form_cc_kept'), 'cc_save' => t('form_cc_save'), 'cc_reload' => t('form_cc_reload'),
			'cc_record_gone' => t('form_cc_record_gone'), 'cc_delete_by' => t('form_cc_delete_by'), 'cc_delete_anon' => t('form_cc_delete_anon'),
			'cc_empty' => t('form_cc_empty'), 'pr_open' => t('form_pr_open'), 'pr_editing' => t('form_pr_editing'),
	],
];




// ===================================================================== output
$setupHeader = ''; $setupStyle = '';
try {
	if (nu_db()->tableExists('zzzzsys_setup')) {
		$setup = NuMailer::setupRow();                        // the row every page reads once
		$setupHeader = NuFormPage::setupHeader((string)($setup['set_header'] ?? ''), '');
		// Setup → Style in the head, as nuBuilder Forte (index.php): <style> elements of HTML objects come later and win (it
		// stood after the form, so a grid layout of the Setup style replaced the one an HTML object writes for itself)
		$setupStyle = NuFormPage::setupHeader('', (string)($setup['set_style'] ?? ''));
	}
} catch (Throwable $ig) { nu_ignored($ig); }



$head = NuBuilderLib::fontAwesomeTag() . '<link rel="stylesheet" href="' . nu_asset(NU_JQCONFIRM . '/jquery-confirm.min.css') . '">'
	. '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">'
	. '<link rel="stylesheet" href="' . nu_asset('assets/css/nuform.css') . '">'
	. ($design ? '<link rel="stylesheet" href="' . nu_asset('assets/css/nudesign.css') . '">' : '')
	. NuIncludes::cssTags()                                     // $nuConfigIncludeCSS
	. ($safe ? '' : $setupStyle)
	. (!$safe && trim((string)$meta->form['sfo_style']) !== '' ? '<style data-nu-source="sfo_style">' . str_ireplace('</style', '<\/style', (string)$meta->form['sfo_style']) . '</style>' : '');

// nuBuilder Forte is one page: the form's JavaScript ran already for the list, so a method it adds to a built-in prototype
// (Date.prototype.x = function …) exists when the record's code calls it before that line. Here every record is a new page –
// such definitions are run first (nuruntime.js nuNextHoistPrototypes, Acorn), only when the code has one
$hoistJs = '';
if (!$safe && preg_match('/^[ \t]*[A-Za-z_$][\w$]*\.prototype\.[A-Za-z_$][\w$]*\s*=/m', (string)$meta->form['sfo_javascript'])) {
	$hoistJs = '<script src="' . nu_asset(NU_ACORN . '/acorn.min.js') . '"></script>'
		. '<script>nuNextHoistPrototypes(' . json_encode((string)$meta->form['sfo_javascript'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ');</script>';
}

$custom = $safe ? '' : NuFormPage::script((string)$meta->form['sfo_javascript'], 'sfo_javascript')
	. NuFormPage::script((string)$meta->form[$mode === 'browse' ? 'sfo_browse_javascript' : 'sfo_edit_javascript'], $mode === 'browse' ? 'sfo_browse_javascript' : 'sfo_edit_javascript');

// browse lists refreshed in place (nuruntime.js browseGo): only for forms without code of their own (form / browse JavaScript,
// script includes – a stylesheet in Setup → Include is fine); hooks of Setup → Header are checked in the browser (empty ones, as in nuBuilder Forte's default header, do not count);
// nuNextSmoothBrowse(true) in form code turns it on anyway
if ($mode === 'browse' && is_array($client['browse'] ?? null)) {
	// nuFORM.getCurrent().browse_sql: nuBuilder Forte sends the browse SQL with its hash variables filled in – form code checks whether a
	// #name# is still in it (property not set yet). Only those unset #names# are sent, never the SQL itself
	$client['browse']['unset'] = array_values(array_map(static fn($n) => '#' . $n . '#', array_keys(array_filter(NuBrowse::$shown['vars'] ?? [], static fn($v) => $v === null))));
	$client['browse']['smooth'] = $custom === '' && empty($printMode) && !$design && NuIncludes::jsTags() === '' && stripos(NuIncludes::setupTags(), '<script') === false;
}
// nuCurrentProperties() / nuFORM.getCurrent() as nuBuilder Forte's breadcrumb (nuform.php nuGetFormObject + nuformclass.js
// addBreadcrumb): the form's list settings as Forte sends them (rows 20 / row height 18 when 0, the raw columns), the list's columns
// as stored (display = the column expression; '' for a column hidden or masked by field data access) – read by nuruntime.js crumbProps()
$crumbShown = $mode === 'browse' ? (array)($data['columns'] ?? []) : [];   // $data exists only for lists
$client['crumb'] = (static function () use ($meta, $mode, $crumbShown, $hash, $lookupTo, $u, $rights, $client): array {
	$f = $meta->form;
	$redirect = trim((string)($f['sfo_browse_redirect_form_id'] ?? ''));
	$c = [
		'form_group' => (string)($f['sfo_group'] ?? ''),
		'browse_title_multiline' => $f['sfo_browse_title_multiline'] ?? false,
		'browse_autoresize_columns' => $f['sfo_browse_autoresize_columns'] ?? null,
		'mobile_view' => $f['sfo_mobile_view'] ?? null,
		'redirect_form_id' => $redirect === '' ? $meta->id : $redirect, 'redirect_other_form_id' => $redirect,
		'browse_target' => (string)($f['sfo_browse_target'] ?? ''),
		'row_height' => (int)($f['sfo_browse_row_height'] ?? 0) ?: 18,
		'rows' => (int)($f['sfo_browse_rows_per_page'] ?? 0) ?: 20,
		'data_mode' => $u['globeadmin'] ? null : (!empty($rights['no_edits']) ? '0' : '1'),
		'browse_table_id' => (string)($hash['TABLE_ID'] ?? NuHash::tableId()),
		'session_id' => (string)($hash['SESSION_ID'] ?? ''),
		'lookup_id' => $lookupTo,
		'run_code' => (string)($client['report']['code'] ?? ''), 'run_description' => (string)($client['report']['description'] ?? ''),
		'columns' => [], 'pad' => defined('NU_BROWSE_CELL_PAD') ? NU_BROWSE_CELL_PAD : 10,
	];
	if ($mode === 'browse') {
		$shown = $crumbShown;
		foreach ($meta->browse as $i => $b) {
			$s = $shown[$i] ?? null;
			$c['columns'][] = ['title' => (string)$b['sbr_title'], 'display' => $s && $s['mask'] === null ? (string)$b['sbr_display'] : '',
				'align' => (string)($b['sbr_align'] ?? ''), 'width' => $s ? (int)$b['sbr_width'] : 0, 'order' => (int)($b['sbr_order'] ?? 0),
				'format' => (string)($b['sbr_format'] ?? ''), 'id' => (string)$b['zzzzsys_browse_id']];
		}
	}
	return $c;
})();
// the list's SQL as nuBuilder Forte (nuBrowseRows: $S->SQL – primary key and the column expressions in front of the list's SQL with its
// hash variables filled in, wrapped as Forte 4.9 does; a #name# without a value stays as text; columns hidden or masked by field data
// access left out). Kept in the session for nuHash()['browse_sql'] of procedures started from this list (pages/nuformapi.php – the
// browser's value is never trusted); sent to the browser (nuCurrentProperties().browse_sql) only when the form asks for it
// (design mode → Browse list, sfo_json nunext.browse_sql_client) – else only the unset #names#, as before
if ($mode === 'browse' && is_array($client['browse'] ?? null) && isset(NuBrowse::$shown['sql'])) {
	$bsCols = [$meta->primaryKey()];
	foreach ($client['crumb']['columns'] as $bc) if (trim((string)$bc['display']) !== '') $bsCols[] = trim((string)$bc['display']);
	$bsSql = rtrim(trim((string)preg_replace('/\s+/', ' ', (string)NuBrowse::$shown['sql'])), ';');
	$bsFull = 'SELECT ' . implode(',', array_filter($bsCols, 'strlen')) . ' FROM (SELECT * FROM (' . $bsSql . ') AS wrapped) AS wrapped_query WHERE 1';
	if (session_status() === PHP_SESSION_ACTIVE) {
		$bsKeep = (array)($_SESSION[NuSess::BROWSE_SQL] ?? []);
		unset($bsKeep[$meta->id]);
		$bsKeep[$meta->id] = $bsFull;
		$_SESSION[NuSess::BROWSE_SQL] = array_slice($bsKeep, -20, null, true);   // the last 20 lists
	}
	if (NuFormMeta::browseSqlToClient($meta->form['sfo_json'] ?? null)) $client['browse']['sql'] = $bsFull;
}
// WYSIWYG editor objects need TinyMCE (not bundled – a library pack, or the old hand copy in assets/vendor/tinymce)
// – and form code that starts TinyMCE itself (tinymce.init on a textarea): nuBuilder Forte loads it on every form page
$hasEditor = $mode === 'edit' && (array_filter($meta->objects, static fn($o) => $o['sob_all_type'] === 'editor')
	|| preg_match('/\btinymce\s*\.\s*(init|get|remove|activeEditor|EditorManager)\b/i', $meta->form['sfo_javascript'] . $meta->form['sfo_edit_javascript'] . ($meta->php('BE') ?? '')
		. implode('', array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects))));
$tinyDir   = $hasEditor ? NuLibs::tinymce() : '';            // library pack or assets/vendor/tinymce
$tinymce   = $tinyDir !== '';
$notice    = $hasEditor && !$tinymce && $u['globeadmin'] ? t('form_tinymce_missing') . ' ' . t('lp_get_pack') : '';

// ApexCharts (nuBuilder: $nuConfigIncludeApexCharts loads third_party/apexcharts on every page) – bundled, loaded on form pages when switched on.
// A form whose code uses ApexCharts while it is off: the globeadmin gets a hint with a "Switch on" button.
$apex = (bool)nu_cfg('nuConfigIncludeApexCharts');
$apexNotice = '';
if (!$apex && $u['globeadmin'] && empty($u['demo'])) {
	$apexCode = $meta->form['sfo_javascript'] . $meta->form['sfo_browse_javascript'] . $meta->form['sfo_edit_javascript'] . ($meta->php('BE') ?? '')
		. implode("\n", array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects));
	if (preg_match('/\bApexCharts\b/', $apexCode)) $apexNotice = t('form_apex_off');
}
// dashboards: <div data-nu-dashboard="CODE"> in an HTML object, or nuNextDashboard('CODE', …) in the form's JavaScript
$dashboards = $mode === 'edit' && preg_match('/data-nu-dashboard|nuNextDashboard/', implode("\n", array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects))
	. $meta->form['sfo_javascript'] . $meta->form['sfo_edit_javascript']);
// Uppy (library pack – nuBuilder loads it on every page): forms with a file object of target "File System" or code that uses Uppy
$uppyUse = $mode === 'edit' && (array_filter($meta->objects, static fn($o) => NuRender::isUppy($o))
	|| preg_match('/\bUppy\b|nuUppyCreate/', $setupHeader . $meta->form['sfo_javascript'] . $meta->form['sfo_edit_javascript']
		. implode("\n", array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects))));
$uppyDir = $uppyUse ? NuLibs::uppy() : '';
$uppyNotice = $uppyUse && $uppyDir === '' && $u['globeadmin'] ? t('form_uppy_missing') . ' ' . t('lp_get_pack') : '';
if ($uppyDir !== '') $head .= '<link rel="stylesheet" href="' . nu_asset($uppyDir . '/uppy.min.css') . '">';
$client['php_warnings'] = NuPhp::warningsShown();                   // Before Browse / Before Edit / display procedures: notice for the globeadmin
$client['codetools'] = nu_asset('assets/js/nu/nucodetools.js');      // loaded with CodeMirror when a code editor opens (review B3)
$client['uppyBase'] = $uppyDir;                               // nuUppySetLanguage loads <uppyBase>/locales/<code>.min.js
if ($googleLoader) $head .= '<script src="https://www.gstatic.com/charts/loader.js"></script>';   // as nuBuilder Forte
if ($apex) $head .= '<script src="' . nu_asset(NU_APEXCHARTS . '/apexcharts.min.js') . '"></script>';   // in the head, like nuBuilder: HTML objects' own scripts may use it

$calList = $mode === 'browse' && empty($printMode) && $lookupTo === '' && !$design ? NuCalendar::ofForm($meta) : null;   // calendar view of the list
$calObjects = $mode === 'edit' && str_contains((string)$body, 'nuCalHost');
$uHelpAny = !$bare && empty($printMode) && NuUserHelp::any();     // user help pages
$uHelp = $uHelpAny ? NuUserHelp::forForm($meta) : null;
$foot = '<script>window.nuNext = ' . json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';</script>'
	. NuJquery::tags((string)$meta->form['zzzzsys_form_id'])                  // jQuery 3 / 4 / own file + Migrate
	. '<script src="' . nu_asset(NU_JQCONFIRM . '/jquery-confirm.min.js') . '"></script>'
	. '<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nu/nuinfobar.js') . '"></script>'
	. ($tinymce ? '<script src="' . nu_asset($tinyDir . '/tinymce.min.js') . '"></script>' : '')
	. ($uppyDir !== '' ? '<script src="' . nu_asset($uppyDir . '/uppy.min.js') . '"></script>' : '')
	. '<script src="' . nu_asset('assets/js/nu/nuformat.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nu/nuhashvars.js') . '"></script>'
	. ($design ? '<script src="' . nu_asset('assets/js/nu/nucodetools.js') . '"></script>' : '')   // design mode: its code windows; else loaded on first use (review B3)
	. '<script src="' . nu_asset('assets/js/nu/nuruntime.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nu/nucompat.js') . '"></script>'
	. ($mode === 'edit' ? '<script src="' . nu_asset('assets/js/nu/nusuggest.js') . '"></script>' : '')   // suggestions while typing
	. ($mode === 'browse' ? '<script src="' . nu_asset('assets/js/nu/nuadvsearch.js') . '"></script>' : '')
	. ($mode === 'browse' && $rights['print'] && empty($printMode) ? '<script src="' . nu_asset('assets/js/nu/nupivot.js') . '" data-apex="'
		. h(nu_asset(NU_APEXCHARTS . '/apexcharts.min.js')) . '"></script>' : '')   // pivot
	. ($calList || $calObjects ? NuCalendar::scriptTag() : '')   // calendars
	. ($mode === 'browse' && !empty($printMode) ? '<script src="' . nu_asset('assets/js/nuprintlist.js') . '"></script>' : '')   // print view: hide columns
	. ($mode === 'edit' && NuFormRules::of($meta) ? '<script src="' . nu_asset('assets/js/nu/nurules.js') . '"></script>' : '')
	. '<script src="' . nu_asset('assets/js/nu/nutooltip.js') . '"></script>'
	. (!$bare && !$design ? NuRecordView::script() : '')           // view mode; on lists: the order of the records for ← / →
	. ($uHelpAny ? NuUserHelp::foot() : '')                       // user help pages: side panel, F1, (?) of fields
	. (NuAcl::can(NuAcl::FORM_ACCESS) ? '<script src="' . nu_asset('assets/js/nu/nuformaccess.js') . '"></script>' : '')
	. (str_contains((string)($body ?? ''), 'nuPageFrame') ? NuPage::hostScript() : '')   // page forms in iframe run objects
	. ($dashboards ? NuDashboard::scriptTag() : '')   // dashboards in HTML objects or the form's JavaScript
	. NuIncludes::jsTags()                                       // $nuConfigIncludeJS: before Setup → Header and the form's code
	. nu_plugin_tags('js')                                       // scripts of the switched-on plugins
	. NuCustomCode::jsTag()                                      // Global JS: before Setup → Header and the form's code – a form's nuOnLoad wins
	. ($safe ? '' : NuIncludes::setupTags())                     // Setup → Include: its <link> / <script> / <style> elements
	. ($safe ? '' : $setupHeader) . "\n"
	. ($safe ? '' : '<script>nuNextLoadGuard(true);</script>')   // handlers of form code waiting for the page are dropped as in Forte
	. ($mode === 'edit' && !$safe ? '<script>nuNextRunDeferred(document);</script>' : '')   // scripts of HTML objects (NuRender::deferScripts)
	// as nuBuilder Forte (EXTRAJS): nuAddJavascript of the PHP events and the form's JavaScript in ONE script – function declarations of the
	// form's code are hoisted, so code added in Before Edit / Before Browse may call them; after an error only the PHP part
	. $hoistJs
	. ($errors || $safe ? NuFormPage::script(implode("\n", $js), 'php: nuAddJavascript')
		: NuFormPage::script(implode("\n\n", array_filter([implode("\n", $js), (string)$meta->form['sfo_javascript'], (string)$meta->form[$mode === 'browse' ? 'sfo_browse_javascript' : 'sfo_edit_javascript']], static fn($x) => trim($x) !== '')),
			'php: nuAddJavascript + form JavaScript'))
	. ($safe ? '' : '<script>nuNextLoadGuard(false);</script>')
	. '<script>nuNextStart();</script>'
	. (!empty($client['builder']) ? '<script src="' . nu_asset('assets/js/nu/nudesigner.js') . '" data-labels="' . h((string)json_encode(array_combine(   // Arrange panel
		['title', 'hint', 'up', 'down', 'top', 'bottom', 'left', 'right', 'vertical', 'horizontal', 'shortest', 'tallest', 'thinnest', 'widest', 'drag', 'hgap', 'vgap', 'apply', 'gap_tip'],
		array_map(static fn($k) => t('dsg_' . $k), ['title', 'hint', 'up', 'down', 'top', 'bottom', 'left', 'right', 'vertical', 'horizontal', 'shortest', 'tallest', 'thinnest', 'widest', 'drag', 'hgap', 'vgap', 'apply', 'gap_tip'])),
			JSON_UNESCAPED_UNICODE))
			. '"></script>' : '')
	. ($design ? '<script src="' . nu_asset('assets/js/nuformatdesigner.js') . '"></script>'     // format designer + attribute editor of the panel
		. '<script src="' . nu_asset('assets/js/nuattreditor.js') . '"></script>'
		. '<script src="' . nu_asset('assets/js/nuaiask.js') . '"></script><script src="' . nu_asset('assets/js/nu/nudesign.js') . '"></script>' : '')
	;

// labels of the form cannot be selected, as in nuBuilder Forte – except for those who may design the form (copy an object's label)
if ($mode === 'edit' && !NuDesign::allowed($meta)) nu_body_class('nuLabelNoSelect');
// view mode that opens by itself: the page stays hidden until the sheet covers it (no glimpse of the edit form)
if ($mode === 'edit' && !$client['is_new'] && $meta->type() !== 'launch' && !$bare && !$design && empty($printMode) && NuRecordView::pending($meta->id)) {
	nu_body_class('nuRvPending');
}
nu_layout_start($title, $meta->id === 'nunotes' || $meta->id === 'nunotescategroy' ? 'notes' : 'forms',
	['head' => $head, 'bare' => $bare, 'nu_form' => [$mode, (string)$meta->form['sfo_code']], 'container' => 'container-fluid nuFormContainer',
		// nuLookupPage = the lookup's list only: a record opened from it (+) keeps its header with Save
		// nuBrowseBody / nuEditBody: nuBuilder Forte's body classes (nuform.js) – form code styles or selects with them
		'body_class' => 'nuForm nuForm-' . $mode . ' ' . ($mode === 'browse' ? 'nuBrowseBody' : 'nuEditBody') . ($lookupTo !== '' && $mode === 'browse' ? ' nuLookupPage' : '') . (!empty($printMode) ? ' nuPrintView' : '')
		. ($design ? ' nuDesignMode' : '')]);
?>
<?php if ($design): // design mode hides the header: the page's heading for screen readers (usability review G8) ?><h1 class="visually-hidden"><?= h($title) ?></h1><?php endif; ?>
<?php $launchHead = $mode === 'edit' && $meta->type() === 'launch' && !$bare && !$design; // as Forte: a launch form shows no title ?>
<div class="nuFormHeader nuTitleBar mb-3<?= $launchHead ? ' nuLaunchHead' : '' ?><?= !$bare ? ' nuPhHead' : '' /* holds .nuPhTitle (nuform.css phone header) */ ?>">
	<?php if (!$bare): // phones (the owner picked mockup B): "‹ <parent>" + the title instead of the breadcrumb and Back; the buttons sit right of the title
		$phCur = (string)(end($breadcrumbs)['title'] ?? $title); $phBack = null;
		for ($bi = count($breadcrumbs) - 2; $bi >= 0; $bi--) if (($breadcrumbs[$bi]['url'] ?? '') !== '') { $phBack = $breadcrumbs[$bi]; break; } ?>
	<?php if ($phBack && empty($printMode)): ?><a class="nuPhBack" href="<?= h($phBack['url']) ?>"><i class="bi bi-chevron-left"
		aria-hidden="true"></i><span><?= h($phBack['title']) ?></span></a><?php endif; ?>
	<h1 class="nuPhTitle"><?= h($phCur) ?></h1>
	<?php endif; ?>
	<?php if (!$bare): ?>
	<nav aria-label="breadcrumb">
		<ol class="breadcrumb mb-0 nuBreadcrumbHolder" id="nuBreadcrumbHolder">
			<?php foreach ($breadcrumbs as $i => $b): ?>
				<li class="breadcrumb-item<?= $b['url'] === '' ? ' active' : '' ?>">
					<?php if ($b['url'] !== ''): ?><a class="nuBreadcrumb" id="nuBreadcrumb<?= (int)$i ?>" href="<?= h($b['url']) ?>"><?= h($b['title']) ?></a>
					<?php elseif ($i === count($breadcrumbs) - 1): /* usability review G8: the page's heading */ ?><h1 class="nuBreadcrumb nuCrumbH1 fw-semibold"
						id="nuBreadcrumb<?= (int)$i ?>"><?= h($b['title']) ?></h1>
					<?php else: ?><span class="nuBreadcrumb fw-semibold" id="nuBreadcrumb<?= (int)$i ?>"><?= h($b['title']) ?></span><?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</nav>
	<?php else: ?>
	<div class="nuBreadcrumbHolder" id="nuBreadcrumbHolder"><span class="fw-semibold nuBreadcrumb" id="nuBreadcrumb0"><?= h($title) ?></span></div>
	<?php endif; ?>

	<div class="nuActionHolder" id="nuActionHolder">
		<?php
		// Back: to the previous breadcrumb – the list, the Forms list or the start page; not in iframes / lookup windows
		$backIdx = null;
		if (!$bare) for ($bi = count($breadcrumbs) - 2; $bi >= 0; $bi--) if (($breadcrumbs[$bi]['url'] ?? '') !== '') { $backIdx = $bi; break; }
		?>
		<?php if ($backIdx !== null && empty($printMode)): ?>
			<a class="btn nuBtnAct nuActionButton" id="nuBackButton" data-nu-crumb="<?= (int)$backIdx ?>"
				href="<?= h($breadcrumbs[$backIdx]['url']) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php endif; ?>
		<?php if ($mode === 'browse'): ?>
			<?php if ($rights['add'] && $rights['edit'] && $meta->hasEdit()): // "Add" right only (not on a "browse only" form) (as nuBuilder; "New" is the button on the edit screen) – also in a lookup window: the new record is chosen right after saving ?>
				<button type="button" class="btn nuAddBtn nuActionButton" id="nuAddButton"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></button>
			<?php endif; ?>
			<?php if (empty($printMode) && (int)($data['total'] ?? 0) > 0): // phones: rows as cards or as a table – as on the system lists (nuruntime.js phoneStack) ?>
				<button type="button" class="btn nuBtnAct nuToolBtn d-sm-none nuLvPhoneBtn" id="nuPhoneCardsBtn" aria-pressed="false"
					title="<?= h(t('lv_phone_toggle')) ?>" aria-label="<?= h(t('lv_phone_toggle')) ?>"><i class="bi bi-table nuLvPhoneToTable"
					aria-hidden="true"></i><i class="bi bi-card-list nuLvPhoneToCards" aria-hidden="true"></i></button>
			<?php endif; ?>
			<?php if ($meta->id === 'nunotes' && $lookupTo === '' && empty($printMode) && isset(nu_forms()['nunotescategroy']) && NuAcl::can('nunotescategroy')): // notes: categories one click away ?>
				<a class="btn btn-outline-secondary nuActionButton" id="nuNoteCategoriesButton"
					href="<?= h(nu_url('form', ['f' => 'nunotescategroy', 'bt' => $bt['child'] ?: null])) ?>"><i class="bi bi-tags me-1"></i><?= h(t('notes_categories')) ?></a>
			<?php endif; ?>
			<?php if ($rights['print'] && empty($printMode) && $lookupTo === '' && (int)($data['total'] ?? 0) > 0): // nothing to export without records ?>
				<?php $expKeep = array_diff_key($keep, ['p' => 1, 'print' => 1, 'iframe' => 1, 'lookup' => 1, 'lo' => 1]); // Export menu: HTML page, CSV …, Excel ?>
				<?= NuListExport::menu(nu_url('form', ['print' => '1'] + array_diff_key($keep, ['iframe' => 1])), nu_url('formexport', ['fmt' => 'xlsx'] + $expKeep),
					NuListExport::csvDialog(['p' => 'formexport'] + $expKeep, $meta->code() ?: 'export', false), 'nuPrintButton', 'nuExportButton', false, 'btn-outline-secondary nuActionButton', '',
					// pivot of the list (assets/js/nu/nupivot.js): the list's search state goes along
					'<li><hr class="dropdown-divider"></li><li><button class="dropdown-item" type="button" id="nuPivotOpen" data-nu-pivot="' . h(json_encode(['f' => $meta->id] + $expKeep, JSON_UNESCAPED_UNICODE)) . '" data-nu-pivot-texts="' . h(json_encode(NuPivot::texts(), JSON_UNESCAPED_UNICODE)) . '"><i class="bi bi-grid-3x3-gap me-2" aria-hidden="true"></i>' . h(t('pv_open')) . '</button></li>') ?>
			<?php endif; ?>
			<?php if ($calList): // calendar view of the list (assets/js/nu/nucalendar.js): the list's search state goes along ?>
				<button type="button" class="btn btn-outline-secondary nuActionButton nuIconPhone" id="nuCalendarOpen"
					data-nu-cal="<?= h(json_encode(NuCalendar::listClient($meta, $calList, array_diff_key($keep, ['p' => 1, 'print' => 1, 'iframe' => 1, 'lookup' => 1, 'lo' => 1, 'pg' => 1, 'sort' => 1, 'dir' => 1])), JSON_UNESCAPED_UNICODE)) ?>"><i class="bi bi-calendar3 me-1"
					aria-hidden="true"></i><?= h(t('cal_view')) ?></button>
			<?php endif; ?>
		<?php elseif (!$errors): ?>
			<?php if (($client['is_new'] && $rights['create']) || (!$client['is_new'] && $rights['save'])): ?>
				<?php if ($meta->type() !== 'launch'): ?>
					<button type="button" class="btn nuBtnAct nuBtnSave nuActionButton" id="nuSaveButton"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
				<?php endif; ?>
			<?php endif; ?>
			<?php if (!empty($client['report'])): ?>
				<button type="button" class="btn btn-primary nuActionButton" id="nuRunReportButton"
					data-nu-report="<?= h($client['report']['code']) ?>"><i class="bi bi-file-earmark-pdf me-1"></i><?= h(t('rpt_run')) ?></button>
				<button type="button" class="btn btn-outline-secondary nuActionButton" id="nuRunReportXlsx" data-nu-report="<?= h($client['report']['code']) ?>"
					title="<?= h(t('rpt_xlsx_tip')) ?>"><i class="bi bi-file-earmark-excel me-1"></i><?= h(t('rpt_xlsx')) ?></button>
			<?php endif; ?>
			<?php if (!$client['is_new'] && $rights['clone'] && $meta->type() !== 'launch'): ?>
				<button type="button" class="btn nuBtnAct nuActionButton" id="nuCloneButton"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></button>
			<?php endif; ?>
			<?php if (!$client['is_new'] && $rights['new'] && $meta->type() !== 'launch'): ?>
				<button type="button" class="btn btn-outline-secondary nuActionButton nuIconPhone" id="nuNewButton"><i class="bi bi-plus-lg me-1"></i><?= h(t('form_new')) ?></button>
			<?php endif; ?>
			<?php if (!$client['is_new'] && $rights['delete'] && $meta->type() !== 'launch'): ?>
				<button type="button" class="btn nuBtnAct nuBtnDel nuActionButton" id="nuDeleteButton"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
			<?php if (!$client['is_new'] && $meta->type() !== 'launch' && !$bare && !$design && empty($printMode)): // view mode: the record as a read-only data sheet ?>
				<?= NuRecordView::button(['title' => (string)(end($breadcrumbs)['title'] ?? $title), 'kind' => $meta->description(), 'head' => NuRecordView::head($meta),
					'list' => $meta->id, 'edit' => $rights['save']]) ?>
			<?php endif; ?>
		<?php endif; ?>
		<?php if (($mode === 'edit' || ($mode === 'browse' && $lookupTo === '')) && !$design && !$bare && empty($printMode) && NuDesign::allowed($meta)): // design mode (the list) ?>
			<?php $dsBtn = '<a class="btn btn-outline-secondary nuActionButton" id="nuDesignButton" href="' . h(nu_url('form', ['f' => $meta->id, 'r' => $mode === 'edit' ? $r : null, 'design' => '1'])) . '" title="' . h(t('ds_design_tip')) . '"><i class="bi bi-pencil-square me-1 nuDsPen" aria-hidden="true"></i>' . h(t('ds_design')) . '</a>'; ?>
			<?php $layoutInMenu = $mode === 'edit' && $u['globeadmin'] && !$bare;   // review M6: the layout switch lives in Design ▾ ?>
			<?php if ($browseSqlEdit || $codeEdit || $layoutInMenu): ?>
				<div class="btn-group" id="nuDesignGroup"><?= $dsBtn ?><button type="button"
					class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" id="nuDesignMore" data-bs-toggle="dropdown" aria-expanded="false"
					title="<?= h(t('bsq_more')) ?>"><span class="visually-hidden"><?= h(t('bsq_more')) ?></span></button>
					<ul class="dropdown-menu dropdown-menu-end">
					<?php if ($browseSqlEdit): /* one heading carries the verb, the entries are the names of the pane's tabs; data-nu-full = the whole sentence for the phone ⋮ menu */ ?><li><h6 class="dropdown-header"
						id="nuDesignEditHead"><?= h(t('edit')) ?></h6></li>
						<li><button type="button" class="dropdown-item d-flex align-items-center gap-2" id="nuBrowseSqlEdit"
							data-nu-full="<?= h(t('bsq_edit')) ?>"><i class="bi bi-filetype-sql" aria-hidden="true"></i><?= h(t('builder_browse_sql')) ?></button></li>
						<li><button type="button" class="dropdown-item d-flex align-items-center gap-2" id="nuBrowsePhpEdit"
							data-nu-full="<?= h(t('bsq_edit_php')) ?>"><i class="bi bi-filetype-php"
							aria-hidden="true"></i><span><?= h(t('bsq_tab_php')) ?> <span class="text-body-secondary">(Before Browse)</span></span></button></li>
						<li><button type="button" class="dropdown-item d-flex align-items-center gap-2" id="nuBrowseJsEdit"
							data-nu-full="<?= h(t('bsq_edit_js')) ?>"><i class="bi bi-filetype-js" aria-hidden="true"></i><?= h(t('bsq_tab_js')) ?></button></li>
						<li><button type="button" class="dropdown-item d-flex align-items-center gap-2" id="nuBrowseColsEdit"
							data-nu-full="<?= h(t('bsq_edit_cols')) ?>"><i class="bi bi-layout-three-columns"
							aria-hidden="true"></i><?= h(t('lv_tab_columns')) ?></button></li><?php endif; ?>
					<?php if ($codeEdit): ?><li><h6 class="dropdown-header"><?= h(t('edit')) ?></h6></li>
						<li><button type="button" class="dropdown-item d-flex align-items-center gap-2" id="nuEditCodeEdit"
							data-nu-full="<?= h(t('ecp_menu_full')) ?>"><i class="bi bi-code-slash" aria-hidden="true"></i><?= h(t('ecp_title')) ?></button></li><?php endif; ?>
					<?php if ($layoutInMenu): ?><li><h6 class="dropdown-header"><?= h(t('form_layout')) ?></h6></li>
						<?php foreach (['absolute', 'auto', 'grid'] as $l): ?><li><a class="dropdown-item d-flex align-items-center gap-2 nuLayoutItem<?= $layout === $l ? ' active' : '' ?>"
							data-nu-layout="<?= h($l) ?>"<?= $layout === $l ? ' aria-current="true"' : '' ?>
							href="<?= h(nu_url('form', ['f' => $meta->id, 'r' => $r, 'layout' => $l])) ?>"><i class="bi <?= $layout === $l ? 'bi-check2' : 'bi-dot' ?>"
							aria-hidden="true"></i><?= h(t('form_layout_' . $l)) ?></a></li><?php endforeach; ?>
					<?php endif; ?></ul></div>
			<?php else: ?><?= $dsBtn ?><?php endif; ?>
		<?php endif; ?>
		<?php if (!$bare && empty($printMode) && !$design && $uHelp) echo NuUserHelp::button($uHelp);   // user help pages: ? of the form / tab ?>
		<?php if ($u['globeadmin'] && !$bare): ?>
			<?= str_replace('<div class="dropdown">', '<div class="dropdown nuFormInfoWrap">', NuFormInfo::html($meta, $mode, $r)) /* (i) form info for the globeadmin */ ?>
			<div class="btn-group nuSeg<?= !empty($layoutInMenu) ? ' d-none' : '' ?>" id="nuLayoutSeg" role="group" aria-label="<?= h(t('form_layout')) ?>">
				<?php if ($mode === 'edit'): foreach (['absolute', 'auto', 'grid'] as $l): ?>
					<a class="btn btn-outline-secondary<?= $layout === $l ? ' active' : '' ?>"
						href="<?= h(nu_url('form', ['f' => $meta->id, 'r' => $r, 'layout' => $l])) ?>"
						title="<?= h(t('form_layout_' . $l)) ?>"><?= h(t('form_layout_' . $l)) ?></a>
				<?php endforeach; endif; ?>
			</div>
			<button type="button" class="btn btn-outline-danger d-none" id="nuJsErrors"><i class="bi bi-bug me-1"></i><span></span></button>
		<?php endif; ?>
	</div>
</div>

<?php if ($notice !== ''): ?><div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h($notice) ?></div><?php endif; ?>
<?php // Maintenance / Archived: who may still open the form sees why users cannot – with Change… / End maintenance
if (!$bare && in_array($formStatus, [NuFormStatus::MAINT, NuFormStatus::ARCHIVED], true)):
	$fmsInfo = NuFormStatus::notice($meta->id); $fmsCan = NuFormStatus::canChange() && !$meta->isSystem(); ?>
<div class="alert alert-<?= $formStatus === NuFormStatus::MAINT ? 'danger' : 'secondary' ?> small py-2 d-flex flex-wrap align-items-center gap-2" id="nuFormMaint" role="status"
	data-form="<?= h($meta->id) ?>" data-label="<?= h($meta->description()) ?>" data-text="<?= h((string)($fmsInfo['text'] ?? '')) ?>" data-until="<?= h((string)($fmsInfo['until'] ?? '')) ?>">
	<i class="bi <?= $formStatus === NuFormStatus::MAINT ? 'bi-cone-striped' : 'bi-archive' ?>" aria-hidden="true"></i>
	<span class="flex-grow-1"><strong><?= h(t($formStatus === NuFormStatus::MAINT ? 'fms_notice' : 'fms_notice_archived')) ?></strong>
		<?php if ($fmsInfo && $fmsInfo['text'] !== ''): ?> – <?= h($fmsInfo['text']) ?><?php endif; ?>
		<?php if ($fmsInfo && $fmsInfo['until_text'] !== ''): ?> · <?= h(t('form_st_maint_until', $fmsInfo['until_text'])) ?><?php endif; ?></span>
	<?php if ($fmsCan && $formStatus === NuFormStatus::MAINT): ?>
		<button type="button" class="btn btn-sm btn-outline-secondary nuFormMaintEdit"><?= h(t('fms_change')) ?></button>
		<button type="button" class="btn btn-sm btn-outline-danger nuFormMaintEnd"><?= h(t('fms_end')) ?></button>
	<?php endif; ?>
</div>
<?php endif; ?>
<?php if (!$bare && NuFormStatus::canChange() && !$meta->isSystem() && ($design || $formStatus === NuFormStatus::MAINT)) echo NuFormStatus::clientHtml(); ?>
<?php if ($uppyNotice !== ''): ?><div class="alert alert-warning small py-2" id="nuUppyMissing"><i class="bi bi-exclamation-triangle me-1"></i><?= h($uppyNotice) ?></div><?php endif; ?>
<?php if ($apexNotice !== ''): ?><div class="alert alert-warning small py-2 d-flex flex-wrap align-items-center gap-2"><span class="flex-grow-1"><i class="bi bi-exclamation-triangle me-1"></i><?= h($apexNotice) ?></span><?= NuSettings::fromDb() ? '<a href="' . h(nu_url('settings')) . '">' . h(t('access_2fa_cfg_settings')) . '</a>' : NuConfigFile::enableButton('nuConfigIncludeApexCharts', t('apex_on'), t('apex_on_confirm'), 'form', ['f' => $meta->id, 'r' => (string)($r ?? '')]) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?>
	<div class="alert alert-danger d-flex gap-2"><i class="bi bi-exclamation-octagon mt-1"></i><div><?= nl2br(h($e['message'])) ?></div></div>
<?php endforeach; ?>
<?php if (($browseErr ?? '') !== ''): ?><div class="alert alert-danger d-flex gap-2"
	id="nuBrowseSqlErr"><i class="bi bi-exclamation-octagon mt-1"></i><div><?= nl2br(h($browseErr)) ?></div></div><?php endif; ?>

<?php if ($mode === 'browse' && $data): ?>
	<?php if (!empty($printMode)): ?>
		<div id="nuPrintOpts" hidden data-opts="<?= h((string)json_encode((object)NuPrintView::csvOptions($hash))) ?>"></div><?php // nuPrintCSVExportOptions() ?>
		<div class="nuPrintHead nuTitleBar mb-2"><h1 class="h5 mb-0"><?= h($meta->description()) ?></h1>
			<div class="nuTitleSub small text-body-secondary"><?= h(date(nu_dfmt('d.m.Y H:i'))) ?> · <?= h(t('n_records', $data['total'])) ?><?= $state['q'] !== '' ? ' · ' . h(t('search')) . ': ' . h($state['q']) : '' ?></div>
			<div class="d-flex flex-wrap gap-2 d-print-none">
				<button class="btn btn-sm btn-primary" type="button" data-nu-copy-table="#nuBrowseTable" data-nu-copied="<?= h(t('copy_done')) ?>"
					data-nu-copy-failed="<?= h(t('copy_failed')) ?>"><i class="bi bi-clipboard me-1"></i><span><?= h(t('copy_table')) ?></span></button>
				<?= NuListExport::csvDialog(['p' => 'formexport'] + array_diff_key($keep, ['p' => 1, 'print' => 1, 'iframe' => 1, 'lookup' => 1, 'lo' => 1]), $meta->code() ?: 'export') ?>
				<?= NuListExport::xlsxButton(['p' => 'formexport', 'fmt' => 'xlsx'] + array_diff_key($keep, ['p' => 1, 'print' => 1, 'iframe' => 1, 'lookup' => 1, 'lo' => 1])) ?>
				<?php // × in a column title hides it, Undo / Reset view as on the system lists (assets/js/nuprintlist.js) ?>
				<button class="btn btn-sm btn-outline-secondary" type="button" id="nuPrintUndo" disabled><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('print_undo')) ?></button>
				<button class="btn btn-sm btn-outline-secondary" type="button" id="nuPrintReset" disabled><i class="bi bi-arrow-repeat me-1"></i><?= h(t('print_reset_view')) ?></button>
				<span class="small text-body-secondary align-self-center d-none" id="nuPrintHiddenInfo" data-text="<?= h(t('print_cols_hidden', '%s')) ?>" data-move="<?= h(t('print_col_move')) ?>"></span>
			</div></div>
	<?php endif; ?>
	<div class="card shadow-sm nuListCard nuBrowseCard">
		<?php if (empty($printMode)): ?>
		<form class="nuBrowseSearch nuListFilter" method="get" action="index.php" role="search">
			<input type="hidden" name="p" value="form"><input type="hidden" name="f" value="<?= h($meta->id) ?>">
			<?php foreach (array_diff_key($keep, ['f' => 1, 'q' => 1, 'flt' => 1]) as $k => $v): ?>
				<input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
			<?php endforeach; ?>
			<?php // column filters: labelled selects next to the search, like the system lists (users …) – not a row below the header
			$fltCols = array_values(array_filter($data['columns'], static fn($c) => !empty($c['values']) && $c['width'] > 0));
			$fltIdx = array_column($fltCols, 'index');
			foreach ($state['flt'] as $fi => $fv): if (!in_array($fi, $fltIdx, true)): ?><input type="hidden" name="flt[<?= (int)$fi ?>]" value="<?= h($fv) ?>"><?php endif; endforeach; ?>
			<div class="row g-2 align-items-end"><div class="col-md-6">
			<div class="d-flex gap-2 nuListSearch"><?php if ($rights['add'] && $rights['edit'] && $meta->hasEdit()): // phones: Add next to the search, as the mockup B – clicks #nuAddButton ?><button type="button"
				class="btn btn-primary nuPhAdd order-last" title="<?= h(t('add')) ?>" aria-label="<?= h(t('add')) ?>"><i class="bi bi-plus-lg"
				aria-hidden="true"></i></button><?php endif; ?><div class="input-group nuSearchGroup">
				<input type="search" class="form-control" id="nuSearchField" name="q" value="<?= h($state['q']) ?>" placeholder="<?= h(t('search_ph')) ?>"
					aria-label="<?= h(t('search')) ?>" autocomplete="off"<?= $lookupTo !== '' ? ' autofocus' : '' ?>>
				<button class="btn btn-outline-secondary nuSearchBtn" id="nuSearchButton" type="submit" title="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>"><i class="bi bi-search"></i></button>
				<?php $advOn = NuAdvSearch::valid($data['columns'] ?? [], $state['adv']) !== []; // advanced search: ▾ opens the panel (assets/js/nu/nuadvsearch.js) ?>
				<button class="btn btn-outline-secondary nuSearchBtn nuAdvBtn<?= $advOn ? ' active' : '' ?>" id="nuAdvButton" type="button"
					data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="<?= h(t('as_title')) ?>"
					aria-label="<?= h(t('as_title')) ?>"><i class="bi bi-chevron-down"></i></button>
				<div class="dropdown-menu dropdown-menu-end p-3 nuAdvMenu" id="nuAdvPanel" aria-labelledby="nuAdvButton"></div>
			</div>
			<?php if ($state['q'] !== '' || $state['flt'] || $advOn): // reset: search words, column filters and the advanced search go, sort / page size / filter of form code stay ?>
				<a class="btn btn-outline-secondary nuSearchBtn nuListResetBtn" id="nuSearchReset"
					href="<?= h(nu_url('form', array_diff_key($keep, ['q' => 1, 'flt' => 1, 'adv' => 1]))) ?>" title="<?= h(t('reset')) ?>"
					aria-label="<?= h(t('reset')) ?>"><i class="bi bi-x-lg"></i></a>
			<?php endif; ?>
			<?php if ($fltCols): $fltOn = (bool)array_intersect($fltIdx, array_keys($state['flt'])); ?>
				<button class="btn btn-outline-secondary nuSearchBtn nuFilterToggle<?= $fltOn ? ' active' : '' ?>" type="button" data-bs-toggle="collapse"
					data-bs-target="#nuBrowseFilters" aria-expanded="<?= $iframe && !$fltOn ? 'false' : 'true' ?>" aria-controls="nuBrowseFilters"
					title="<?= h(t('filter')) ?>" aria-label="<?= h(t('filter')) ?>"><i class="bi bi-funnel<?= $fltOn ? '-fill' : '' ?>"></i></button>
			<?php endif; ?>
			</div></div>
			<?php if ($fltCols): $n = count($fltCols); ?>
			<?php // embedded in a form (run object, review F3): the filters stay folded behind the funnel until one is set ?>
			<div class="<?= $n > 3 ? 'col-12' : 'col-md' ?> collapse<?= $iframe && !$fltOn ? '' : ' show' ?><?= !$fltOn ? ' nuFltPhoneHide' . ($n > 3 ? ' nuFltTabHide' : '') : '' /* folded on phones (review H2) – more than three also on tablets – until the funnel is pressed */ ?>"
				id="nuBrowseFilters"><div class="row g-2">
				<?php foreach ($fltCols as $c): ?>
					<div class="<?= $n > 3 ? 'col-sm-6 col-lg-3' : ($n === 1 ? 'col-12' : ($n === 2 ? 'col-sm-6' : 'col-sm-6 col-lg-4')) ?>">
						<label class="form-label small mb-1" for="nuFlt<?= (int)$c['index'] ?>"><?= h(NuHtmlClean::cellText((string)$c['title'])) ?></label>
						<select class="form-select nuColumnFilter<?= isset($state['flt'][$c['index']]) ? ' nuFilterActive' : '' ?>" id="nuFlt<?= (int)$c['index'] ?>" name="flt[<?= (int)$c['index'] ?>]">
							<option value=""><?= h(t('all')) ?></option>
							<?php $fltCur = $state['flt'][$c['index']] ?? null; $fltSeen = false;
							foreach ($c['values'] as [$v, $l]): $fltSeen = $fltSeen || $fltCur === $v; ?><option value="<?= h($v) ?>"<?= $fltCur === $v ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
							<?php if ($fltCur !== null && !$fltSeen): // the chosen value is beyond the first values (review B2): keep it ?><option value="<?= h((string)$fltCur) ?>"
								selected><?= h((string)$fltCur) ?></option><?php endif; ?>
							<?php if (count($c['values']) >= 300): ?><option value="" disabled><?= h(t('form_flt_more', 300)) ?></option><?php endif; ?>
						</select>
					</div>
				<?php endforeach; ?>
			</div></div>
			<?php endif; ?>
			</div>
			<?php if ($advOn): // the conditions as chips – ✕ removes one ?>
				<div class="d-flex flex-wrap gap-1 mt-2 nuAdvChips" id="nuAdvChips">
					<?php foreach (NuAdvSearch::chips($data['columns'], $state['adv']) as [$chip, $rest]): ?>
						<a class="badge rounded-pill text-bg-light border text-decoration-none fw-normal nuAdvChip"
							href="<?= h(nu_url('form', array_diff_key($keep, ['adv' => 1]) + ['adv' => $rest ?: null])) ?>"
							title="<?= h(t('as_remove')) ?>"><?= h($chip) ?> <i class="bi bi-x" aria-hidden="true"></i></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</form>
		<?php endif; ?>
		<div class="table-responsive">
			<?php // Notes: a delete button at the very left of each row – with the delete right, not in lookups, print or demo mode
			$rowDel = $meta->id === 'nunotes' && !empty($rights['delete']) && $lookupTo === '' && empty($printMode) && !NuDemo::active(); ?>
			<?php // nuBuilder Forte's list settings: multi-line titles, columns fitted to the width, row height
			$rowH = (int)($meta->form['sfo_browse_row_height'] ?? 0);
			$browseCls = ((string)($meta->form['sfo_browse_title_multiline'] ?? '') === '1' ? ' nuBrowseMultiline' : '')
				. ((string)($meta->form['sfo_browse_autoresize_columns'] ?? '') === '1' ? ' nuBrowseAutoFit' : '')
				// rows hidden until the form's own code ran (it often rewrites the cells – raw values must not flash); nuNextStart
				// removes the class after nuOnLoad, a CSS animation shows the rows after 3 s anyway
				. (!$safe && empty($printMode) && !$design && trim((string)$meta->form['sfo_browse_javascript'] . (string)$meta->form['sfo_javascript']) !== '' ? ' nuBrowseJsWait' : ''); ?>
			<?php
			// column widths dragged in this browser (cookie nunext_bw of nuruntime.js brCookie): drawn by the server, so the page does
			// not show the stored widths first and jump when the script restores the dragged ones
			$bw = [];
			if ($lookupTo === '' && empty($printMode)) {
				$bwAll = json_decode((string)($_COOKIE['nunext_bw'] ?? ''), true);
				foreach (is_array($bwAll) && is_array($bwAll[$meta->id] ?? null) ? $bwAll[$meta->id] : [] as $k => $v) {
					if (ctype_digit((string)$k) && is_numeric($v)) $bw[(int)$k] = max(40, min(4000, (int)round((float)$v)));
				}
			}
			$bwSum = 0;
			// a column's stored width is its content's width as in nuBuilder Forte: the title / cell padding (.3rem each side, nuform.css) comes on top
			defined('NU_BROWSE_CELL_PAD') || define('NU_BROWSE_CELL_PAD', 10);
			// nuBuilder Forte fits the columns to the window only when the form says so (Fit columns = 1) or, form setting empty, the shared
			// setting nuAutosizeBrowseColumns is on (its default); otherwise the list keeps the stored widths and scrolls sideways
			$fitSet = (string)($meta->form['sfo_browse_autoresize_columns'] ?? '');
			$fitCols = $fitSet === '1' || ($fitSet !== '0' && (NuSettings::uxOptions()['nuAutosizeBrowseColumns'] ?? true) !== false);
			$colSum = 0;
			// totals of selected rows: a checkbox column on lists with a total (not in lookups, the print view or design mode)
			$selCol = !empty($data['totals']) && $data['rows'] && $lookupTo === '' && empty($printMode) && !$design;
			$nCols = max(1, count($data['columns'])) + ($rowDel ? 1 : 0) + ($rowDetails ? 1 : 0) + ($selCol ? 1 : 0);
			if (!$bw && !$fitCols && $lookupTo === '' && empty($printMode)) {
				foreach ($data['columns'] as $c) if ($c['width'] > 0) $colSum += (int)$c['width'] + NU_BROWSE_CELL_PAD;
				$colSum += ($rowDel ? 44 : 0) + ($rowDetails ? 36 : 0) + ($selCol ? 36 : 0);
			}
			if ($bw) {
				foreach ($data['columns'] as $c) if ($c['width'] > 0) $bwSum += $bw[$c['index']] ?? (int)$c['width'] + NU_BROWSE_CELL_PAD;
				$bwSum += ($rowDel ? 44 : 0) + ($rowDetails ? 36 : 0) + ($selCol ? 36 : 0);
			}
			?>
			<table class="table table-hover align-middle mb-0 nu-table nuBrowseTable<?= $rowDel ? ' nuBrowseRowDel' : '' ?><?= $browseCls ?><?= $bw ? ' nuColFixed nuBrowseFixed' : '' ?>"
				id="nuBrowseTable"
				data-nu-sticky-head<?= $rowH > 0 ? ' data-nu-row-height="' . min(400, $rowH) . '"' : '' ?><?= $bw ? ' style="width:' . $bwSum . 'px"' : ($colSum > 0 ? ' style="width:' . $colSum . 'px;min-width:100%"' : '') ?>>
				<thead><tr>
					<?php if ($selCol): ?><th scope="col" class="nuSelCol"><input type="checkbox" class="form-check-input" id="nuRowSelAll"
						aria-label="<?= h(t('ds_sel_all')) ?>" title="<?= h(t('ds_sel_all')) ?>"></th><?php endif; ?>
					<?php if ($rowDetails): ?><th scope="col" class="nuDetCol"><span class="visually-hidden"><?= h(t('rd_details')) ?></span></th><?php endif; ?>
					<?php if ($rowDel): ?><th scope="col" class="nuRowDelCol"<?= $bw ? ' style="width:44px"' : '' ?>><span class="visually-hidden"><?= h(t('delete')) ?></span></th><?php endif; ?>
					<?php foreach ($data['columns'] as $c):
						$hidden = $c['width'] <= 0;
						$isSort = $state['sort'] === $c['index'];
						$nextDir = $isSort && $state['dir'] === 'asc' ? 'desc' : 'asc';
						$link = nu_url('form', ['sort' => $c['index'], 'dir' => $nextDir] + $keep + (!empty($printMode) ? ['print' => '1'] : []));   // the print view stays the print view
					?>
						<th scope="col" id="nuBrowseTitle<?= (int)$c['index'] ?>" class="nuBrowseTitle text-<?= ['l' => 'start', 'c' => 'center', 'r' => 'end'][$c['align']] ?? 'start' ?>"
							data-nu-col="<?= (int)$c['index'] ?>" data-nu-browse-id="<?= h($c['id']) ?>" data-nu-title-id="<?= h($c['id']) ?>"
								style="<?= $hidden ? 'display:none;' : 'width:' . (int)($bw[$c['index']] ?? (int)$c['width'] + NU_BROWSE_CELL_PAD) . 'px;' ?>"<?= $isSort ? ' aria-sort="' . ($state['dir'] === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
							<a class="nu-sort" id="nusort_<?= (int)$c['index'] ?>"
								href="<?= h($link) ?>"><?php /* id as nuBuilder Forte's title text (form code styles $('#nusort_2')) */ ?><?= NuHtmlClean::cell((string)$c['title']) ?><?= $isSort ? ' <i class="bi bi-caret-' . ($state['dir'] === 'asc' ? 'up' : 'down') . '-fill"></i>' : '' ?></a><?php if (!empty($printMode)):
								$hdTxt = NuHtmlClean::cellText((string)$c['title']); ?><button type="button" class="nuColHide d-print-none"
								aria-label="<?= h(t('print_col_hide', $hdTxt)) ?>" title="<?= h(t('print_col_hide', $hdTxt)) ?>"><i class="bi bi-x"></i></button><?php endif; ?>
						</th>
					<?php endforeach; ?>
				</tr>
				</thead>
				<tbody id="nuRECORD"><?php /* id as nuBuilder Forte's browse (code that loops '#nuRECORD .nuCell') */ ?>
				<?php if (!$data['rows']): ?>
					<tr><td colspan="<?= $nCols ?>" class="text-center text-body-secondary py-4 <?= trim($state['q']) !== '' ? 'nuBrowseNoResults' : 'nuBrowseNoData' ?>"><?= h(t('no_records')) ?></td></tr>
				<?php endif; ?>
				<?php
				// grouped list: a heading row before each group, a row of subtotals (whole group, all pages) after it
				$grpIdx = $data['group'] ?? null; $grpCur = null; $grpOpen = false;
				$grpFoot = static function (?array $g) use ($data, $rowDetails, $rowDel, $selCol): string {
					if (!$g || empty($g['totals'])) return '';
					$h = '<tr class="nuGroupTotalRow">' . ($selCol ? '<td class="nuSelCol"></td>' : '') . ($rowDetails ? '<td class="nuDetCol"></td>' : '') . ($rowDel ? '<td class="nuRowDelCol"></td>' : '');
					foreach ($data['columns'] as $c) {
						$tt = $g['totals'][$c['index']] ?? null;
						$h .= '<td class="nuTotalCell text-' . (['l' => 'start', 'c' => 'center', 'r' => 'end'][$c['align']] ?? 'start') . '"'
							. ($tt ? ' data-nu-total="' . h($tt['fn']) . '" data-nu-label="' . h(NuHtmlClean::cellText((string)$c['title'])) . '"' : '')
							. ($c['width'] <= 0 ? ' style="display:none"' : '') . '>'
								. ($tt ? '<span class="nuTotalFn">' . h($tt['label']) . '</span> <span class="nuTotalVal">' . h($tt['text']) . '</span>' : '') . '</td>';
					}
					return $h . '</tr>';
				};
				?>
				<?php foreach ($data['rows'] as $ri => $row): ?>
					<?php if ($grpIdx !== null && (!$grpOpen || $row['group'] !== $grpCur)):
						if ($grpOpen) echo $grpFoot($data['groups'][$grpCur] ?? null);
						$grpCur = $row['group']; $grpOpen = true; $g = $data['groups'][$grpCur] ?? ['label' => '', 'count' => null]; ?>
					<tr class="nuGroupRow" data-nu-group="<?= h((string)$grpCur) ?>"><th colspan="<?= $nCols ?>"
						scope="colgroup"><span class="nuGroupTitle"><?= h(NuHtmlClean::cellText((string)($data['columns'][$grpIdx]['title'] ?? ''))) ?></span> <span class="nuGroupName"><?= trim((string)$g['label']) !== '' ? h(NuHtmlClean::cellText((string)$g['label'])) : h(t('ds_grp_empty')) ?></span><?php if ($g['count'] !== null): ?> <span class="nuGroupCount"><?= (int)$g['count'] ?></span><?php endif; ?></th></tr>
					<?php endif; ?>
					<tr class="nuBrowseRow" data-nu-primary-key="<?= h($row['pk']) ?>" data-nu-row="<?= $ri ?>" tabindex="0"<?= $rowH > 0 ? ' style="height:' . min(400, $rowH) . 'px"' : '' ?>>
						<?php if ($selCol): ?><td class="nuSelCol"><input type="checkbox" class="form-check-input nuRowSel" tabindex="-1" aria-label="<?= h(t('ds_sel_row')) ?>"></td><?php endif; ?>
						<?php if ($rowDetails): ?><td class="nuDetCol"><button type="button" class="btn btn-sm btn-link nuDetBtn" aria-expanded="false" tabindex="-1"
							title="<?= h(t('rd_show')) ?>" aria-label="<?= h(t('rd_show')) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td><?php endif; ?>
						<?php if ($rowDel): ?><td class="nuRowDelCol"><button type="button" class="btn btn-sm btn-outline-danger nuRowDel"
							data-nu-pk="<?= h($row['pk']) ?>" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete')) ?>"><i class="bi bi-trash"
							aria-hidden="true"></i></button></td><?php endif; ?>
						<?php foreach ($data['columns'] as $ci => $c): ?>
							<?php /* the cell = a div as in nuBuilder Forte (id nucell_<row>_<column>, data-nu-*) inside the table cell – form code that
							   selects div[id^=nucell_] or [data-nu-column] finds it */ ?>
							<td class="nuCellTd text-<?= ['l' => 'start', 'c' => 'center', 'r' => 'end'][$c['align']] ?? 'start' ?>"
								data-nu-label="<?= h(NuHtmlClean::cellText((string)$c['title'])) ?>"<?= $c['width'] <= 0 ? ' style="display:none"' : '' ?>><div id="nucell_<?= $ri ?>_<?= (int)$c['index'] ?>"
								class="nuCell nuBrowseTable nuCell<?= $ri % 2 ? 'Odd' : 'Even' ?>"
								data-nu-column="<?= (int)$c['index'] ?>" data-nu-column-id="<?= h((string)($c['id'] ?? '')) ?>" data-nu-row="<?= $ri ?>"
									data-nu-primary-key="<?= h($row['pk']) ?>"<?= $rowH > 0 ? ' style="max-height:' . min(400, $rowH) . 'px"' : '' /* Forte: a cell keeps the row height, longer content is cut */ ?>><?= NuFormPage::browseCell((string)$row['cells'][$ci], (string)($row['classes'][$ci] ?? ''), $c, isset($cellActs[$c['index']])) ?></div></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				<?php if ($grpIdx !== null && $grpOpen) echo $grpFoot($data['groups'][$grpCur] ?? null); ?>
				</tbody>
				<?php $totals = array_intersect_key((array)($data['totals'] ?? []), $data['columns']); if ($totals && $data['rows']): /* totals over ALL matching rows, not the page */ ?>
				<tfoot id="nuBrowseTotals"><tr class="nuTotalRow" aria-label="<?= h(t('ds_tot_row')) ?>">
					<?php if ($selCol): ?><td class="nuSelCol"><button type="button" class="nuSelClear" id="nuSelClear" hidden title="<?= h(t('ds_sel_clear')) ?>"
						aria-label="<?= h(t('ds_sel_clear')) ?>" data-nu-text="<?= h(t('ds_sel_n')) ?>"></button></td><?php endif; ?>
					<?php if ($rowDetails): ?><td class="nuDetCol"></td><?php endif; ?>
					<?php if ($rowDel): ?><td class="nuRowDelCol"></td><?php endif; ?>
					<?php foreach ($data['columns'] as $c): $tt = $totals[$c['index']] ?? null; ?>
						<td class="nuTotalCell text-<?= ['l' => 'start', 'c' => 'center', 'r' => 'end'][$c['align']] ?? 'start' ?>"
							data-nu-column="<?= (int)$c['index'] ?>"<?= $tt ? ' data-nu-total="' . h($tt['fn']) . '" data-nu-label="' . h(NuHtmlClean::cellText((string)$c['title'])) . '"' : '' ?><?= $c['width'] <= 0 ? ' style="display:none"' : '' ?>><?php if ($tt): ?><span class="nuTotalFn"><?= h($tt['label']) ?></span> <span class="nuTotalVal"><?= h($tt['text']) ?></span><?php endif; ?></td>
					<?php endforeach; ?>
				</tr></tfoot>
				<?php endif; ?>
			</table>
		</div>
		<?php nu_list_footer((int)$data['total'], (int)$data['page'], (int)$data['per'], 'form', $keep, 'card-footer', 'pg', '', empty($printMode), 'nuBrowseFooter'); ?>
	</div>
<?php elseif ($mode === 'edit'): ?>
	<div class="nuEditForm" id="nuEditForm"><?= $body ?></div>
<?php endif; ?>

<div class="modal fade" id="nuLookupModal" tabindex="-1" aria-hidden="true" aria-labelledby="nuLookupTitle">
	<div class="modal-dialog modal-xl nuLookupDialog"><?php /* width / height fitted to the list by nuruntime.js lookupFit */ ?>
		<div class="modal-content">
			<div class="modal-header py-2"><h2 class="modal-title fs-6" id="nuLookupTitle"><?= h(t('form_lookup_title')) ?></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
			<div class="modal-body p-0"><iframe class="nuLookupFrame" title="<?= h(t('form_lookup_title')) ?>"></iframe></div>
		</div>
	</div>
</div>
<div class="modal fade" id="nuPopupModal" tabindex="-1" aria-hidden="true" aria-label="<?= h(t('form_popup_title')) ?>">
	<div class="modal-dialog modal-xl modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header py-2"><h2 class="modal-title fs-6"><?= h(t('form_popup_title')) ?></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
			<div class="modal-body p-0"><iframe class="nuLookupFrame nuPopupFrame" title="<?= h(t('form_popup_title')) ?>"></iframe></div>
		</div>
	</div>
</div>
<div class="modal fade" id="nuReportModal" tabindex="-1" aria-hidden="true" aria-label="<?= h(t('rpt_title')) ?>">
	<div class="modal-dialog modal-xl modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header py-2"><h2 class="modal-title fs-6 me-auto"><i class="bi bi-file-earmark-pdf me-1"></i><?= h(t('rpt_title')) ?></h2>
				<a class="btn btn-sm btn-outline-secondary me-2 nuReportOpen" href="#" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('rpt_open_tab')) ?></a>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
			<div class="modal-body p-0"><iframe class="nuLookupFrame nuReportFrame" title="<?= h(t('rpt_title')) ?>"></iframe></div>
		</div>
	</div>
</div>
<div class="modal fade" id="nuMessageModal" tabindex="-1" aria-hidden="true" aria-labelledby="nuMessageTitle">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content" id="nuMessageDiv">
			<div class="modal-header py-2"><h2 class="modal-title fs-6" id="nuMessageTitle"></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
			<div class="modal-body nuMessageBody"></div>
			<div class="modal-footer py-2"><button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal"><?= h(t('ok')) ?></button></div>
		</div>
	</div>
</div>
<?php
nu_layout_end($foot, $bare);
