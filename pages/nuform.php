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
// form drafts (0.8.386): draft=<id> starts the preview of a draft in this session, draft=off ends it
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
// design mode (0.8.662, core/form/nudesign.php): an open draft of the form is shown while designing
$designReq = ($_GET['design'] ?? '') === '1' && NuDesign::allowed($meta);
if ($designReq && NuDesign::previewOpenDraft($meta)) {
	NuFormMeta::forget($meta->id);
	$meta = NuFormMeta::load($meta->id) ?? $meta;
}
// a form never published (status Draft, 0.8.386) opens for designers only
if ((string)($meta->form['sfo_status'] ?? '') === '0' && !$meta->isDraft() && !NuAcl::can(NuAcl::FORM_FORM, 'view')) {
	http_response_code(403);
	nu_fatal(t('draft_not_published'), $meta->description(), true);
}
// Maintenance / Archived (0.8.808, core/nuformstatus.php): users get a message page; the globeadmin and form designers open the form
$formStatus = NuFormStatus::norm($meta->form['sfo_status'] ?? '');
if (!$meta->isDraft()) NuFormStatus::gate($meta->id, $formStatus);
// form type "page" (0.8.387): an iframe inside nuBuilder Next or a redirect to the page's own output (core/nupage.php)
if ($meta->type() === 'page') NuPage::wrapper($meta);

$u        = NuAuth::user();
$rights   = NuAcl::rights($meta->id);
$iframe   = ($_GET['iframe'] ?? '') === '1';
$lookupTo = preg_replace('/[^\w]/', '', (string)($_GET['lookup'] ?? ''));
NuHash::$state = ['iframe' => $iframe ? '1' : '0', 'lookup_id' => $lookupTo];   // nuBuilder Forte's breadcrumb keys in nuHash() (0.8.984)
$psIn    = is_string($_GET['ps'] ?? null) ? $_GET['ps'] : '';
[$props, $locked] = NuProps::load($psIn);     // properties of the opening form; names set in PHP are locked
$caller = NuHash::$caller = NuProps::caller($psIn);   // fields of the edit form it was opened from (0.8.976)
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
$design = $designReq && ($mode === 'edit' || $mode === 'browse') && !$bare && empty($_GET['print']);   // browse (0.8.669): the list's columns
$safe   = $design && NuDesign::safe();       // design mode without the form's own code (0.8.667): to fix a form whose code breaks it
// access level form type (0.8.461, as nuBuilder 4.9): "browse only" opens no record (also no new one), "edit only" no browse list
if ($meta->type() !== 'launch' && !$rights[$mode]) {
	http_response_code(403);
	nu_fatal(t('acl_form_type_denied'), $meta->description(), true);
}

// forms run admin-authored JavaScript (like nuBuilder): this page needs inline scripts and eval.
// Charts (chart objects or nuChart() in the form's code) use Google Charts: www.gstatic.com is allowed only on those forms.
$usesCharts = (bool)array_filter($meta->objects, static fn($o) => $o['sob_all_type'] === 'chart' || preg_match('/nuChart|google\.(charts|visualization)/', (string)($o['sob_html_code'] ?? '')))
	|| preg_match('/nuChart|google\.(charts|visualization)/', $meta->form['sfo_javascript'] . $meta->form['sfo_browse_javascript'] . $meta->form['sfo_edit_javascript'] . ($meta->php('BE') ?? ''));
// form code that calls google.charts itself needs Google's loader on the page – nuBuilder Forte loads it with $nuConfigIncludeGoogleCharts (0.8.957)
$googleLoader = $usesCharts && (nu_cfg('nuConfigIncludeGoogleCharts') || preg_match('/google\.(charts|visualization)/', $meta->form['sfo_javascript'] . $meta->form['sfo_browse_javascript'] . $meta->form['sfo_edit_javascript'] . ($meta->php('BE') ?? '') . implode('', array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects))));
$gs = $usesCharts ? ' https://www.gstatic.com' : '';
$inc = NuIncludes::cspSources();   // $nuConfigIncludeJS / CSS from other hosts (0.8.443)
$fr = NuRender::urlFrameSources($meta->objects);   // run objects of type URL shown as iframe (0.8.830): their hosts
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline'$gs{$inc['style']}; script-src 'self' 'unsafe-inline' 'unsafe-eval'$gs{$inc['script']}; font-src 'self' data:; connect-src 'self'$gs; frame-src 'self'$fr; object-src 'self' data: blob:; frame-ancestors 'self'; form-action 'self'; base-uri 'none'");
header('X-Frame-Options: SAMEORIGIN');

$layout = $meta->layout();
if ($u['globeadmin'] && in_array($_GET['layout'] ?? '', ['absolute', 'auto', 'grid'], true)) $layout = $_GET['layout'];

$js     = [];       // JS from hooks (nuAddJavascript)
$errors = [];
$breadcrumbs = [['title' => t('nav_home'), 'url' => nu_url('home')]];

// ===================================================================== browse
if ($mode === 'browse') {
	// nuBuilder Forte's breadcrumb keys in nuHash() of Before Browse: the list's search, page and sort (0.8.984)
	NuHash::$state += nu_browse_hash_state();
	$hash = NuHash::forForm($meta, '', [], $props);
	$hash['ADV_SEARCH'] = NuAdvSearch::encode(NuAdvSearch::parse($_GET['adv'] ?? ''));   // advanced search (0.8.719) for Before Browse
	NuPhp::reset();
	// nuBuilder's global hook nu_before_save runs before every Before Browse too (0.8.462); an error stops the list's own hook
	$okBB = NuProcedure::hook('nu_before_save', $hash) && NuPhp::run($meta->php('BB'), $hash, 'Before Browse');
	$hash = NuPhp::$hash + $hash;
	$js   = NuPhp::$js;
	if (!$okBB) $errors = NuPhp::$errors ?: [['message' => NuPhp::$error, 'field' => '']];
	nu_form_php_props($props, $locked);
	$pageScope = NuRender::$scope = NuProps::create($props, $locked, $caller);

	$state = nu_browse_request();
	$printMode = ($_GET['print'] ?? '') === '1' && $rights['print'];
	$data = null;
	$browseErr = '';                                         // browse SQL failed: shown to the globeadmin above the empty list
	if (!$errors) {
		try {
			$data = NuBrowse::query($meta, $hash, ['search' => $state['q'], 'runfilter' => $state['rf'], 'sort' => $state['sort'], 'dir' => $state['dir'], 'page' => $state['pg'], 'per' => $state['per'],
				'filters' => $state['flt'], 'adv' => $state['adv'], 'nosearch' => $state['nsc'], 'searchonly' => $state['sc'], 'all' => $printMode ? 5000 : 0,
				'process' => NuOnProcess::rowsHook($meta->php('BB'), $hash), 'raw' => $printMode && NuPrintView::raw($hash)]);   // nuOnProcessBrowseRows(), print settings (0.8.958)
			if ($printMode) $data['columns'] = NuPrintView::columns($data['columns'], $hash);   // nuPrintIncludeColumns() …
			foreach ($data['columns'] as &$col) {
				if ($col['filter'] && !$printMode) $col['values'] = NuBrowse::distinctValues($meta, $hash, $col);
			}
			unset($col);
		} catch (Throwable $e) {
			error_log('nuBuilder Next browse ' . $meta->code() . ': ' . $e->getMessage());
			// as nuBuilder Forte: the titles with no rows, the error in Debug results – the message only for the globeadmin / debug mode
			if (class_exists('NuDebugLog')) NuDebugLog::write('Browse SQL of ' . $meta->code() . ":\n" . $e->getMessage());
			$data = NuBrowse::emptyResult($meta, $hash, (int)$state['per'] ?: ((int)$meta->form['sfo_browse_rows_per_page'] ?: 20));
			if ($u['globeadmin'] || nu_debug()) $browseErr = t('form_err_browse_sql') . ' ' . $e->getMessage();
		}
		NuBrowse::dropTempTable();
	}
	$title = $meta->description();
	$breadcrumbs[] = ['title' => $title, 'url' => ''];
}

// ===================================================================== edit
$dbRow = [];
$isNew = false;
if ($mode === 'edit') {
	$isNew = $r === '-1';
	if ($isNew && !$rights['create'] && $meta->type() !== 'launch') NuAcl::require($meta->id, 'create');
	$row = [];
	if (!$isNew) {
		try {
			$row = NuRecord::load($meta, $r) ?? [];
		} catch (Throwable $e) {
			error_log('nuBuilder Next load ' . $meta->code() . ': ' . $e->getMessage());
			nu_fatal(t('form_err_load'), $u['globeadmin'] ? $e->getMessage() : '', true);
		}
		if (!$row || !NuRecord::visible($meta, $r)) {        // Security check: "Records only through the form's list"
			http_response_code(404);
			nu_fatal(t('record_not_found'), '', true);
		}
		if (!$design) NuRecord::nulogViewed($meta, $r);         // <table>_nulog "viewed", as nuBuilder Forte (0.8.965)
	}
	$dbRow = $row;                                          // as stored – for the check of concurrent edits (0.8.401)
	$hash = NuHash::forForm($meta, $isNew ? '-1' : $r, $row, $props);
	NuPhp::reset();
	NuConflict::$formCheck = NuConflict::$formPresence = null;
	// global hook nu_before_edit first (0.8.462, as nuBuilder 4.9); an error stops the form's Before Edit
	if (!NuProcedure::hook('nu_before_edit', $hash) || (!$safe && !NuPhp::run($meta->php('BE'), $hash, 'Before Edit'))) $errors = NuPhp::$errors ?: [['message' => NuPhp::$error, 'field' => '']];
	$js   = NuPhp::$js;
	$hash = NuPhp::$hash + $hash;
	foreach (NuPhp::$formValues as $k => $v) $row[$k] = $v;          // nuSetFormValue in BE
	// nuBuilder Forte's nuOnProcessObjects() in Before Edit (0.8.958): its body runs with $f->objects, the changes are taken back
	if (!$errors && !$safe && !NuOnProcess::objects($meta, $meta->php('BE'), $row, $hash)) $errors = NuPhp::$errors ?: [['message' => NuPhp::$error, 'field' => '']];
	nu_form_php_props($props, $locked);
	$pageScope = NuRender::$scope = NuProps::create($props, $locked, $caller);

	$render = new NuRender($meta, $isNew ? '-1' : $r, $row, $hash, $layout);
	$render->setReadonly(!$isNew && !$rights['save']);
	$body  = $errors ? '' : $render->tabs();
	$title = $meta->breadcrumb($isNew, NuFieldAccess::scrubHash($meta, $hash));   // no withheld / masked values in the title (0.8.480)
	if ($meta->hasBrowse()) $breadcrumbs[] = ['title' => $meta->description(), 'url' => nu_url('form', ['f' => $meta->id])];
	$breadcrumbs[] = ['title' => $title, 'url' => ''];
}

/** Properties set in PHP (BB / BE) join the page's properties and are locked; nuDeleteProperty removes them. */
function nu_form_php_props(array &$props, array &$locked): void
{
	foreach (NuPhp::$props as $k => $v) { $props[$k] = $v; $locked[$k] = true; }
	foreach (array_keys(NuPhp::$deleted) as $k) unset($props[$k], $locked[$k]);
}

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

// iframes and lookup popups have their own trail, starting with their form (nuGetBreadcrumb(0) = reload)
if ($bare) $breadcrumbs = array_values(array_slice($breadcrumbs, 1));

// ===================================================================== client data
/** Content of a list cell: the value (with its class), or – with a click action (0.8.872) – a real button: link look, button or icon. */
if (!function_exists('nu_browse_cell')) {
function nu_browse_cell(string $v, string $cls, array $c, bool $act): string
{
	$html = NuHtmlClean::cell($v);                                       // HTML as nuBuilder Forte, cleaned (0.8.945)
	$inner = $cls !== '' ? '<span class="' . h($cls) . '">' . $html . '</span>' : $html;
	$v = NuHtmlClean::cellText($v);
	if (!$act) return $inner;
	$a = $c['action'];
	$lbl = (string)($a['label'] ?? '');
	$ic = !empty($a['icon']) ? '<i class="bi ' . h($a['icon']) . '" aria-hidden="true"></i>' : '';
	$d = ' data-nu-act="' . (int)$c['index'] . '" data-nu-v="' . h($v) . '"';
	return match ($a['look']) {
		'icon' => '<button type="button" class="btn btn-sm btn-outline-primary nuCellAct nuCellActIcon"' . $d . ' title="' . h($lbl !== '' ? $lbl : $v) . '" aria-label="'
			. h($lbl !== '' ? $lbl : ($v !== '' ? $v : (string)$c['title'])) . '">' . ($ic ?: '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>') . '</button>',
		'button' => '<button type="button" class="btn btn-sm btn-outline-primary nuCellAct nuCellActBtn"' . $d . '>' . $ic . ($ic && ($lbl !== '' || $v !== '') ? ' ' : '') . ($lbl !== '' ? h($lbl) : $inner) . '</button>',
		default => $v === '' ? '' : '<button type="button" class="btn btn-link p-0 nuCellAct nuCellActLink"' . $d . '>' . $inner . '</button>',
	};
}
}

// click actions of list cells (0.8.872): not in lookups (a click chooses the row there), not in print view
$cellActs = [];
if ($mode === 'browse' && $lookupTo === '' && empty($printMode)) foreach ((array)($data['columns'] ?? []) as $c) {
	if (is_array($c) && !empty($c['action']) && ($ca = NuRender::cellAction($c['action'])) !== null) $cellActs[$c['index']] = $ca;
}
// row details (0.8.1027, core/form/nurowdetails.php): a ▸ column at the start of each row – not in lookups, print view or design mode
$rowDetails = $mode === 'browse' && $lookupTo === '' && empty($printMode) && !$design ? NuRowDetails::client($meta) : null;
// "Edit browse SQL" next to the Design button of a list (0.8.930): the SQL in a side pane, saved with builderapi form.save
$browseSqlEdit = $mode === 'browse' && $lookupTo === '' && !$design && !$bare && empty($printMode) && NuDesign::allowed($meta);
const NU_BSQ_TEXTS = ['ds_list_settings', 'builder_rows_per_page', 'ds_list_rowh', 'ds_list_rowh_tip', 'ds_list_target', 'ds_list_target_0',
	'ds_list_target_1', 'ds_list_target_2', 'ds_list_target_3', 'builder_redirect', 'ds_list_multi', 'ds_list_autofit', 'builder_sec_columns', 'builder_col_title',
	'builder_col_display', 'builder_col_width', 'builder_col_align', 'ds_col_hidden', 'ds_col_show', 'ds_col_left', 'ds_col_right', 'delete', 'ds_list_nocols',
	'builder_add_column', 'ds_col_new', 'om_left', 'om_center', 'om_right', 'please_select', 'ds_col_drag'];
$bsqList = static function () use ($meta, $browseSqlEdit): array {
	if (!$browseSqlEdit) return [];
	$f = $meta->form;
	$forms = array_map(static fn($x) => [(string)$x['zzzzsys_form_id'], $x['sfo_code'] . ' – ' . $x['sfo_description']],
		nu_db()->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' AND sfo_type IN ('edit', 'browseedit') ORDER BY sfo_code"));
	$cols = array_map(static function ($b) {
		$j = json_decode((string)($b['sbr_json'] ?? ''), true);
		return [(string)$b['zzzzsys_browse_id'], (string)($b['sbr_title'] ?? ''), (string)($b['sbr_display'] ?? ''), (string)($b['sbr_align'] ?? '') ?: 'l', (string)($b['sbr_format'] ?? ''),
			($b['sbr_width'] ?? '') === '' || $b['sbr_width'] === null ? 100 : (int)$b['sbr_width'], !empty($j['nunext']['filter'])];
	}, $meta->browse);
	return ['rows' => (string)($f['sfo_browse_rows_per_page'] ?? ''), 'rowh' => (string)($f['sfo_browse_row_height'] ?? ''), 'target' => (string)($f['sfo_browse_target'] ?? '') ?: '0',
		'redirect' => (string)($f['sfo_browse_redirect_form_id'] ?? ''), 'multi' => (string)($f['sfo_browse_title_multiline'] ?? '') === '1',
		'autofit' => (string)($f['sfo_browse_autoresize_columns'] ?? '') === '1', 'table' => (string)($f['sfo_table'] ?? ''), 'forms' => $forms, 'cols' => $cols];
};
$client = [
	// 0.8.942: tab Columns – settings of the list ($bsqList) and its columns [id, title, display, align, format, width, filter]
	'browse_sql' => $browseSqlEdit ? ['sql' => (string)($meta->form['sfo_browse_sql'] ?? ''), 'list' => $bsqList(), 'm' => ['title' => t('builder_browse_sql'), 'hint' => t('bsq_hint'),
		'save' => t('save'), 'close' => t('close'), 'unsaved' => t('bsq_unsaved'), 'pin' => t('bsq_pin'), 'error' => t('form_error'), 'resize' => t('bsq_resize')]
		+ array_combine(NU_BSQ_TEXTS, array_map('t', NU_BSQ_TEXTS))] : null,
	'base' => 'index.php', 'csrf' => nu_csrf_token(), 'bt' => $bt, 'mode' => $mode, 'iframe' => $iframe, 'lookup' => $lookupTo,
	'form' => [
		'id' => $meta->id, 'code' => $meta->code(), 'description' => $meta->description(), 'type' => $meta->type(),
		'table' => $meta->table(), 'primary_key' => $meta->primaryKey(), 'layout' => $layout,
		'redirect' => trim((string)$meta->form['sfo_browse_redirect_form_id']),
		// nuBuilder Forte's list settings (0.8.820): 0 new breadcrumb, 1 current breadcrumb, 2 new tab, 3 popup
		'target' => in_array((string)($meta->form['sfo_browse_target'] ?? ''), ['1', '2', '3'], true) ? (string)$meta->form['sfo_browse_target'] : '0',
	],
	'builder' => (($_GET['builder'] ?? '') === '1' && NuAcl::can(NuAcl::FORM_FORM)) || ($design && $mode === 'edit'),
	'design' => $design ? NuDesign::client($meta, (string)$r) : null,          // design mode (0.8.662): assets/js/nu/nudesign.js
	'draft' => NuFormDraft::previewInfo($meta->id),          // form drafts (0.8.386): the draft shown (label menu, Save in the preview)
	// launch form of a report (?p=report&c=…): its fields are the criteria, a button runs the report
	'report' => $mode === 'edit' && is_string($_GET['rpt'] ?? null) && ($rp = NuReport::load((string)$_GET['rpt'])) && NuReport::canRun($rp)
		? ['code' => (string)$rp['sre_code'], 'description' => (string)$rp['sre_description']] : null,
	'record_id' => $mode === 'edit' ? $r : '', 'is_new' => $mode === 'edit' && $r === '-1', 'saved' => ($_GET['saved'] ?? '') === '1',
	'rights' => $rights,
	// concurrent edits (0.8.401, core/form/nuconflict.php): signatures of the record as opened; the "also open by …" hint
	'cc' => $mode === 'edit' && !$isNew && $dbRow && $rights['save'] && NuConflict::enabled() && NuConflict::$formCheck !== false ? NuConflict::forRecord($meta, (string)$r, $dbRow) : null,
	'presence' => $mode === 'edit' && !$isNew && $dbRow && NuConflict::presenceEnabled() && NuConflict::$formPresence !== false,
	'fileMaxKB' => max(1, (int)(nu_cfg('nuConfigFileMaxKB') ?: 300)),
	'ux' => (object)NuSettings::uxOptions(),     // nuBuilder's nuUXOptions (settings table or nuconfig.php)
	'user' => [
		'id' => $u['id'], 'login' => $u['login'], 'display' => $u['display'],
		'globeadmin' => $u['globeadmin'], 'access_level_id' => $u['access_id'], 'access_level_code' => NuHash::base()['ACCESS_LEVEL_CODE'],
		'language' => NuTranslate::language(), 'permissions' => NuHash::permissions((string)$u['id']),
		'fields' => (object)NuUserFields::mine(),                 // custom user fields (0.8.454): nuNextUserField(code)
		'access_levels' => NuUserAccess::listOf(),                // main + active additional levels (0.8.867): nuNextAccessLevels()
		'home_id' => (string)(NuHash::base()['HOME_ID'] ?? ''),     // nuSERVERRESPONSE.home_id as nuBuilder Forte (0.8.965)
	] + nu_form_user_details($u),   // 'name' = the user's name (sus_name) – '' for the globeadmin, as nuBuilder Forte's nuUserName()
	// nuBuilder Forte's folder for relative images of form code (img src="custom/…" written for Forte, 0.8.965): '' = none
	'forte_base' => nu_forte_base(),
	// nuBuilder Forte's browser tab title (shared settings nuShowBrowserTabTitle / nuBrowserTabTitlePrefix, 0.8.965) – only when one is set
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
	'events' => $mode === 'edit' ? nu_form_client_events($meta) : (object)[],
	'breadcrumbs' => $breadcrumbs, 'title' => $title, 'errors' => $errors,
	// hl: the words / phrases of the search to highlight (NuBrowse::terms, 0.8.427), nsc / sc: columns left out of / limited to by the search
	'browse' => $mode === 'browse' ? ['q' => $state['q'], 'hl' => NuBrowse::terms($state['q'])['in'], 'nsc' => array_values((array)$state['nsc']), 'sc' => array_values((array)$state['sc']), 'sort' => $state['sort'], 'dir' => $state['dir'], 'page' => $data['page'] ?? 1, 'pages' => $data['pages'] ?? 1, 'total' => (int)($data['total'] ?? 0),
		// badges set in design mode (0.8.688): column index => rules of nuBrowseStyleBadge ([] = the default look), applied before nuOnLoad
		'details' => $rowDetails,
		'badges' => (object)array_map(static fn($c) => $c['badge'], array_filter((array)($data['columns'] ?? []), static fn($c) => is_array($c) && ($c['badge'] ?? null) !== null && !isset($cellActs[$c['index']]))),
		// click actions of cells (0.8.872): column index => NuRender::cellAction (only those the user may open; none in lookups)
		'actions' => (object)$cellActs,
		// nuCurrentProperties().browse_rows as nuBuilder Forte: the values as the browse SQL returned them, not the formatted cells (0.8.965)
		'rows' => !empty($printMode) ? [] : array_map(static fn($r) => array_values(array_replace(array_fill(0, max(array_keys($r['raw'] ?? [0 => ''])) + 1, null), $r['raw'] ?? [])), (array)($data['rows'] ?? []))] : null,
	'adv' => $mode === 'browse' && $data && empty($printMode) ? NuAdvSearch::client($meta, $data['columns'], $state['adv']) : null,   // advanced search (0.8.719)
	'rules' => $mode === 'edit' ? NuFormRules::client($meta, $hash) : null,   // validation rules (0.8.812, assets/js/nu/nurules.js)
	// keyboard shortcuts / options menu (nuBuilder Ctrl+Shift+…): only what exists in nuBuilder Next and the user may use
	'tools' => [
		'design' => !$meta->isSystem() && NuAcl::can(NuAcl::FORM_FORM),
		'debug' => NuAcl::can(NuAcl::FORM_DEBUG),
		'password' => !$u['globeadmin'] && ($u['via'] ?? '') !== 'nubuilder' && !NuImpersonate::active(),
		'pma' => NuAdmin::can('dbtool', 'change') && NuVendor::primaryDb() !== '',     // ⋮ → Database: phpMyAdmin or Adminer (0.8.282)
		'tfm' => NuFileManager::visible(),                                      // File Manager = the page nuFiles (0.8.479)
		'sessions' => NuAdmin::can('sessions'),
		'browsesql' => $mode === 'browse' && NuDesign::allowed($meta) ? nu_url('form', ['f' => $meta->id, 'design' => '1', 'dswork' => 'code', 'dsitem' => 'sfo_browse_sql']) : '',   // ⋮ → Browse SQL (0.8.818)
		'formaccess' => !$meta->isPage() && NuAcl::can(NuAcl::FORM_ACCESS) && empty($u['demo']),   // ⋮ → Access levels… (0.8.683, assets/js/nu/nuformaccess.js)
		'version' => $u['globeadmin'] ? 'nuBuilder Next ' . NU_VERSION . ' · PHP ' . PHP_VERSION . ' · ' . nu_db()->type : '',
	],
	'shortcuts' => nu_shortcut_labels(), 'dev_mode' => NuHash::devMode(), 'db_type' => nu_db()->type, 'db_name' => $u['globeadmin'] && empty($u['demo']) ? (string)nu_cfg('nuConfigDBName') : '', 'hashCatalog' => $mode === 'edit' || $design ? NuHash::catalog() : null,
	'formats' => (object)NuFormat::clientRegistry(), 'tinymceBase' => NuLibs::tinymce() ?: NU_TINYMCE,
	// audit trail (0.8.350): "History of this record" in the ⋮ menu
	'audit' => $mode === 'edit' && $r !== null && $r !== '-1' && NuAudit::on() && NuAudit::audited($meta->table())
		? ['url' => nu_url('audithistory', ['f' => $meta->id, 'r' => (string)$r]), 'label' => t('aud_history')] : null,
	// nuBuilder's <table>_nulog of the record: "Added … / Last edited …" in the ⋮ menu (0.8.349)
	'nulog' => $mode === 'edit' && $r !== null && $r !== '-1' ? (static function () use ($meta, $r): ?array {
		$i = NuRecord::nulogInfo($meta, (string)$r);
		if ($i === null) return null;
		$fmt = static fn(?array $e, string $key) => $e ? t($key, $e['name'] === 'unknown' ? t('nulog_unknown') : $e['name'], $e['when'] !== '' ? $e['when'] : '–') : null;
		return ['column' => $i['column'], 'added' => $fmt($i['added'], 'nulog_added'), 'edited' => $fmt($i['edited'], 'nulog_edited'), 'none' => t('nulog_none')];
	})() : null,
	// globeadmin: menu on the labels of the edit form for quick changes of an object (nuBuilder's label context menu); also on User Home,
	// whose objects the builder edits (0.8.605) – 0.8.659
	// browse (0.8.929): the same menu on a right-click of a column title – [id, title, display, align, format, width, filter] of every column
	'objmenu' => ($mode === 'edit' || ($mode === 'browse' && !$bare)) && ($u['globeadmin'] || $design) && (!$meta->isSystem() || $meta->isUserHome()) && NuAcl::can(NuAcl::FORM_FORM, 'save') ? [
		'cols' => $mode === 'browse' ? array_map(static function ($b) {
			$j = json_decode((string)($b['sbr_json'] ?? ''), true);
			return [(string)$b['zzzzsys_browse_id'], (string)($b['sbr_title'] ?? ''), (string)($b['sbr_display'] ?? ''), (string)($b['sbr_align'] ?? '') ?: 'l', (string)($b['sbr_format'] ?? ''),
				($b['sbr_width'] ?? '') === '' || $b['sbr_width'] === null ? 100 : (int)$b['sbr_width'], !empty($j['nunext']['filter'])];
		}, $meta->browse) : [],
		// [id, title as text, stored title, access 0 / 2 / 3] – the tab menu on a right-click of a tab (0.8.919) saves the whole list
		'tabs' => array_map(static fn($t) => [(string)$t['zzzzsys_tab_id'], trim(strip_tags((string)$t['syt_title'])), (string)$t['syt_title'], (string)($t['syt_access'] ?? '0') ?: '0'], $meta->tabs),
		'design' => NuDesign::allowed($meta) ? nu_url('form', ['f' => $meta->id, 'r' => $r, 'design' => '1']) : '',
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
			'geo_top' => t('builder_top'), 'geo_left' => t('builder_left'), 'geo_width' => t('builder_width'), 'geo_height' => t('builder_height')],
	] : null,
	'messages' => [
		'mask_incomplete' => t('fmt_err_incomplete'), 'saved' => t('saved'), 'deleted' => t('form_deleted'), 'cloned' => t('form_cloned'), 'clone_badge' => t('form_clone_badge'), 'unsaved' => t('form_unsaved'), 'report_running' => t('rpt_running'), 'report_emailed' => t('rpt_emailed'), 'delete_confirm' => t('form_delete_confirm'), 'no_results' => t('eff_none'), 'delete_title' => t('form_delete_title'), 'delete_named' => t('form_delete_named'), 'delete_with' => t('form_delete_with'), 'delete_undo' => t('form_delete_undo'), 'error' => t('form_error'),
		'ok' => t('ok'), 'cancel' => t('cancel'), 'code_fullscreen' => t('form_code_fullscreen'), 'code_hint' => t('form_code_hint'), 'code_readonly' => t('form_code_readonly'), 'code_discard' => t('form_code_discard'), 'code_dblclick' => t('form_code_dblclick'), 'delete' => t('delete'), 'lookup' => t('form_lookup_title'), 'js_errors' => t('form_js_errors'),
		'not_supported' => t('form_not_supported'), 'saving' => t('form_saving'), 'all' => t('all'), 'close' => t('close'), 'help' => t('sc_help'), 'chart_no_data' => t('chart_no_data'), 'chart_load_failed' => t('chart_load_failed'), 'chart_no_type' => t('chart_no_type'),
		'hash_name' => t('hash_name'), 'hash_value' => t('hash_value'), 'hash_source' => t('hash_source'), 'hash_filter' => t('hash_filter'), 'hash_locked' => t('hash_locked'),
		'hash_src' => ['system' => t('hash_src_system'), 'user' => t('hash_src_user'), 'record' => t('hash_src_record'), 'property' => t('hash_src_property'), 'global' => t('hash_src_global'), 'caller' => t('hash_src_caller')],
		'file_too_large' => t('file_too_large'), 'empty' => t('form_filter_empty'),
		// concurrent edits (0.8.401)
		'rd_show' => t('rd_show'), 'rd_hide' => t('rd_hide'), 'rd_open' => t('rd_open'), 'rd_more' => t('rd_more'), 'rd_empty' => t('rd_empty'), 'rd_loading' => t('uh_loading'), 'rd_error' => t('rd_error'), 'rd_none_rows' => t('rd_none_rows'),
		'cc_title' => t('form_cc_title'), 'cc_by' => t('form_cc_by'), 'cc_anon' => t('form_cc_anon'), 'cc_field' => t('form_cc_field'), 'cc_original' => t('form_cc_original'), 'cc_yours' => t('form_cc_yours'), 'cc_theirs' => t('form_cc_theirs'), 'cc_keep_mine' => t('form_cc_keep_mine'), 'cc_keep_theirs' => t('form_cc_keep_theirs'), 'cc_row' => t('form_cc_row'), 'cc_row_gone' => t('form_cc_row_gone'), 'cc_row_changed' => t('form_cc_row_changed'), 'cc_delete_row' => t('form_cc_delete_row'), 'cc_keep_row' => t('form_cc_keep_row'), 'cc_kept' => t('form_cc_kept'), 'cc_save' => t('form_cc_save'), 'cc_reload' => t('form_cc_reload'), 'cc_record_gone' => t('form_cc_record_gone'), 'cc_delete_by' => t('form_cc_delete_by'), 'cc_delete_anon' => t('form_cc_delete_anon'), 'cc_empty' => t('form_cc_empty'), 'pr_open' => t('form_pr_open'), 'pr_editing' => t('form_pr_editing'),
	],
];

/** Labels of the options menu / shortcuts (keys: sc_*). */
function nu_shortcut_labels(): array
{
	$out = [];
	foreach (array_keys(require NU_ROOT . '/lang/en.php') as $k) if (str_starts_with($k, 'sc_')) $out[substr($k, 3)] = t($k);
	return $out;
}

/** nuBuilder's user details for nuUserCode(), nuUserTeam() … (blank for the globeadmin, like nuBuilder). */
/** Address of nuBuilder Forte's folder relative to nuBuilder Next's index.php ('../' when Next sits inside it), else $nuConfigNuBuilderURL, else ''. */
function nu_forte_base(): string
{
	$nub = NuBuilderLib::path();
	if ($nub !== '' && $nub === rtrim(dirname(NU_ROOT), '/\\')) return '../';
	$base = trim((string)nu_cfg('nuConfigNuBuilderURL'));
	return $base !== '' && preg_match('~^(https?:)?//|^/~i', $base) ? rtrim($base, '/') . '/' : '';
}

function nu_form_user_details(array $u): array
{
	$map = ['name' => 'sus_name', 'code' => 'sus_code', 'position' => 'sus_position', 'department' => 'sus_department', 'team' => 'sus_team', 'first_name' => 'sus_first_name',
		'last_name' => 'sus_last_name', 'additional1' => 'sus_additional1', 'additional2' => 'sus_additional2', 'email' => 'sus_email',
		'accessibility' => 'sus_accessibility_features'];
	$out = array_fill_keys(array_keys($map), '');
	if ($u['globeadmin']) return $out;
	try {
		$cols = nu_db()->columns('zzzzsys_user');
		$sel = array_values(array_filter($map, static fn($c) => isset($cols[$c])));
		$row = $sel ? (nu_db()->one('SELECT ' . implode(', ', $sel) . ' FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [(string)$u['id']]) ?? []) : [];
		foreach ($map as $k => $c) $out[$k] = (string)($row[$c] ?? '');
	} catch (Throwable) {
	}
	return $out;
}

/** Events of subform objects (afterinsertrow etc.) keyed by subform id. */
function nu_form_client_events(NuFormMeta $meta): object
{
	$out = [];
	foreach ($meta->objects as $o) {
		if ($o['sob_all_type'] === 'subform' && isset($meta->events[$o['zzzzsys_object_id']])) $out[$o['sob_all_id']] = $meta->events[$o['zzzzsys_object_id']];
		if ($o['sob_all_type'] === 'lookup' && trim((string)$o['sob_lookup_javascript']) !== '') $out[$o['sob_all_id']]['lookup'] = (string)$o['sob_lookup_javascript'];
	}
	foreach ($meta->objects as $o) {
		if ($o['sob_all_type'] !== 'subform') continue;
		$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
		foreach ($sub?->objects ?? [] as $so) {
			if ($so['sob_all_type'] === 'lookup' && trim((string)$so['sob_lookup_javascript']) !== '') $out[$o['sob_all_id'] . '.' . $so['sob_all_id']]['lookup'] = (string)$so['sob_lookup_javascript'];
		}
	}
	return (object)$out;
}

function nu_script(string $code, string $label): string
{
	if (trim($code) === '') return '';
	return '<script data-nu-source="' . h($label) . '">' . str_ireplace('</script', '<\/script', $code) . "\n</script>\n";
}

// ===================================================================== output
$setupHeader = ''; $setupStyle = '';
try {
	if (nu_db()->tableExists('zzzzsys_setup')) {
		$setup = nu_db()->one('SELECT * FROM zzzzsys_setup') ?? [];
		$setupHeader = nu_setup_header((string)($setup['set_header'] ?? ''), '');
		// Setup → Style in the head, as nuBuilder Forte (index.php): <style> elements of HTML objects come later and win (0.8.971 – it
		// stood after the form, so a grid layout of the Setup style replaced the one an HTML object writes for itself)
		$setupStyle = nu_setup_header('', (string)($setup['set_style'] ?? ''));
	}
} catch (Throwable) {
}

/**
 * Setup → Header as nuBuilder outputs it: JavaScript inside <script id="nuheader"> (HTML such as <link> is added by closing
 * the script with </script> … <script>), followed by Setup → Style. A header that starts with a tag (nuBuilder Next 0.8.3 and older
 * expected HTML) is output unchanged.
 */
function nu_setup_header(string $header, string $style): string
{
	$out = '';
	if (trim($header) !== '') {
		$out = preg_match('/^\s*<(script|link|style|meta|!--)/i', $header) ? $header : "<script id=\"nuheader\" data-nu-source=\"set_header\">\n" . $header . "\n</script>";
		$out = nu_setup_header_paths($out);
	}
	$style = trim($style);
	if ($style !== '') $out .= "\n" . (preg_match('#</style>$#i', $style) ? $style : '<style data-nu-source="set_style">' . $style . '</style>');
	return $out;
}

/**
 * Relative src / href of <script> and <link> tags in the Setup header (e.g. "libs/x.js") are written for nuBuilder Forte's folder: a file
 * that is not in nuBuilder Next's folder but in Forte's is linked there (NuIncludes::url, 0.8.965) – otherwise the browser asks
 * nuBuilder Next's folder and gets 404. Addresses, absolute paths, data: and files of nuBuilder Next stay unchanged.
 */
function nu_setup_header_paths(string $html): string
{
	return (string)preg_replace_callback('/<(?:script|link)\b[^>]*>/i', static function (array $t): string {
		return (string)preg_replace_callback('/(\s(?:src|href)\s*=\s*)(["\'])([^"\']*)\2/i', static function (array $a): string {
			$v = trim(html_entity_decode($a[3], ENT_QUOTES));
			if ($v === '' || NuIncludes::isUrl($v) || preg_match('~^(data|javascript|blob|mailto):|^[#?]|\.\.~i', $v)) return $a[0];
			$path = (string)preg_replace('/[?#].*$/', '', $v);
			if ($path === '' || is_file(NU_ROOT . '/' . $path)) return $a[0];
			$url = NuIncludes::url($v);
			return $url === null ? $a[0] : $a[1] . $a[2] . h($url) . $a[2];
		}, $t[0]);
	}, $html);
}

$head = NuBuilderLib::fontAwesomeTag() . '<link rel="stylesheet" href="' . nu_asset(NU_JQCONFIRM . '/jquery-confirm.min.css') . '">'
	. '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">'
	. '<link rel="stylesheet" href="' . nu_asset('assets/css/nuform.css') . '">'
	. ($design ? '<link rel="stylesheet" href="' . nu_asset('assets/css/nudesign.css') . '">' : '')
	. NuIncludes::cssTags()                                     // $nuConfigIncludeCSS (0.8.443)
	. ($safe ? '' : $setupStyle)
	. (!$safe && trim((string)$meta->form['sfo_style']) !== '' ? '<style data-nu-source="sfo_style">' . str_ireplace('</style', '<\/style', (string)$meta->form['sfo_style']) . '</style>' : '');

// nuBuilder Forte is one page: the form's JavaScript ran already for the list, so a method it adds to a built-in prototype
// (Date.prototype.x = function …) exists when the record's code calls it before that line. Here every record is a new page –
// such definitions are run first (nuruntime.js nuNextHoistPrototypes, Acorn), only when the code has one (0.8.974)
$hoistJs = '';
if (!$safe && preg_match('/^[ \t]*[A-Za-z_$][\w$]*\.prototype\.[A-Za-z_$][\w$]*\s*=/m', (string)$meta->form['sfo_javascript'])) {
	$hoistJs = '<script src="' . nu_asset(NU_ACORN . '/acorn.min.js') . '"></script>'
		. '<script>nuNextHoistPrototypes(' . json_encode((string)$meta->form['sfo_javascript'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ');</script>';
}

$custom = $safe ? '' : nu_script((string)$meta->form['sfo_javascript'], 'sfo_javascript')
	. nu_script((string)$meta->form[$mode === 'browse' ? 'sfo_browse_javascript' : 'sfo_edit_javascript'], $mode === 'browse' ? 'sfo_browse_javascript' : 'sfo_edit_javascript');

// browse lists refreshed in place (0.8.776, nuruntime.js browseGo): only for forms without code of their own (form / browse JavaScript,
// script includes – a stylesheet in Setup → Include is fine); hooks of Setup → Header are checked in the browser (empty ones, as in nuBuilder Forte's default header, do not count);
// nuNextSmoothBrowse(true) in form code turns it on anyway
if ($mode === 'browse' && is_array($client['browse'] ?? null)) {
	// nuFORM.getCurrent().browse_sql: nuBuilder Forte sends the browse SQL with its hash variables filled in – form code checks whether a
	// #name# is still in it (property not set yet). Only those unset #names# are sent, never the SQL itself (0.8.973)
	$client['browse']['unset'] = array_values(array_map(static fn($n) => '#' . $n . '#', array_keys(array_filter(NuBrowse::$shown['vars'] ?? [], static fn($v) => $v === null))));
	$client['browse']['smooth'] = $custom === '' && empty($printMode) && !$design && NuIncludes::jsTags() === '' && stripos(NuIncludes::setupTags(), '<script') === false;
}
// nuCurrentProperties() / nuFORM.getCurrent() as nuBuilder Forte's breadcrumb (0.8.981, nuform.php nuGetFormObject + nuformclass.js
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
// WYSIWYG editor objects need TinyMCE (not bundled – a library pack, or the old hand copy in assets/vendor/tinymce)
// – and form code that starts TinyMCE itself (tinymce.init on a textarea): nuBuilder Forte loads it on every form page
$hasEditor = $mode === 'edit' && (array_filter($meta->objects, static fn($o) => $o['sob_all_type'] === 'editor')
	|| preg_match('/\btinymce\s*\.\s*(init|get|remove|activeEditor|EditorManager)\b/i', $meta->form['sfo_javascript'] . $meta->form['sfo_edit_javascript'] . ($meta->php('BE') ?? '')
		. implode('', array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects))));
$tinyDir   = $hasEditor ? NuLibs::tinymce() : '';            // library pack or assets/vendor/tinymce (0.8.405)
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
// dashboards (0.8.298): <div data-nu-dashboard="CODE"> in an HTML object, or nuNextDashboard('CODE', …) in the form's JavaScript
$dashboards = $mode === 'edit' && preg_match('/data-nu-dashboard|nuNextDashboard/', implode("\n", array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects))
	. $meta->form['sfo_javascript'] . $meta->form['sfo_edit_javascript']);
// Uppy (0.8.459, library pack – nuBuilder loads it on every page): forms with a file object of target "File System" or code that uses Uppy
$uppyUse = $mode === 'edit' && (array_filter($meta->objects, static fn($o) => NuRender::isUppy($o))
	|| preg_match('/\bUppy\b|nuUppyCreate/', $setupHeader . $meta->form['sfo_javascript'] . $meta->form['sfo_edit_javascript'] . implode("\n", array_map(static fn($o) => (string)($o['sob_html_code'] ?? ''), $meta->objects))));
$uppyDir = $uppyUse ? NuLibs::uppy() : '';
$uppyNotice = $uppyUse && $uppyDir === '' && $u['globeadmin'] ? t('form_uppy_missing') . ' ' . t('lp_get_pack') : '';
if ($uppyDir !== '') $head .= '<link rel="stylesheet" href="' . nu_asset($uppyDir . '/uppy.min.css') . '">';
$client['codetools'] = nu_asset('assets/js/nu/nucodetools.js');      // loaded with CodeMirror when a code editor opens (review B3)
$client['uppyBase'] = $uppyDir;                               // nuUppySetLanguage loads <uppyBase>/locales/<code>.min.js
if ($googleLoader) $head .= '<script src="https://www.gstatic.com/charts/loader.js"></script>';   // as nuBuilder Forte (0.8.957)
if ($apex) $head .= '<script src="' . nu_asset(NU_APEXCHARTS . '/apexcharts.min.js') . '"></script>';   // in the head, like nuBuilder: HTML objects' own scripts may use it

$uHelpAny = !$bare && empty($printMode) && NuUserHelp::any();     // user help pages (0.8.1023)
$uHelp = $uHelpAny ? NuUserHelp::forForm($meta) : null;
$foot = '<script>window.nuNext = ' . json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';</script>'
	. NuJquery::tags((string)$meta->form['zzzzsys_form_id'])                  // jQuery 3 / 4 / own file + Migrate (0.8.741)
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
	. ($mode === 'browse' ? '<script src="' . nu_asset('assets/js/nu/nuadvsearch.js') . '"></script>' : '')
	. ($mode === 'edit' && NuFormRules::of($meta) ? '<script src="' . nu_asset('assets/js/nu/nurules.js') . '"></script>' : '')
	. '<script src="' . nu_asset('assets/js/nu/nutooltip.js') . '"></script>'
	. ($uHelpAny ? NuUserHelp::foot() : '')                       // user help pages (0.8.1023): side panel, F1, (?) of fields
	. (NuAcl::can(NuAcl::FORM_ACCESS) ? '<script src="' . nu_asset('assets/js/nu/nuformaccess.js') . '"></script>' : '')
	. (str_contains((string)($body ?? ''), 'nuPageFrame') ? NuPage::hostScript() : '')   // page forms in iframe run objects (0.8.387)
	. ($dashboards ? NuDashboard::scriptTag() : '')   // dashboards in HTML objects or the form's JavaScript (0.8.298)
	. NuIncludes::jsTags()                                       // $nuConfigIncludeJS (0.8.443): before Setup → Header and the form's code
	. NuCustomCode::jsTag()                                      // Global JS (0.8.523): before Setup → Header and the form's code – a form's nuOnLoad wins
	. ($safe ? '' : NuIncludes::setupTags())                     // Setup → Include (0.8.444): its <link> / <script> / <style> elements
	. ($safe ? '' : $setupHeader) . "\n"
	. ($safe ? '' : '<script>nuNextLoadGuard(true);</script>')   // handlers of form code waiting for the page are dropped as in Forte (0.8.983)
	. ($mode === 'edit' && !$safe ? '<script>nuNextRunDeferred(document);</script>' : '')   // scripts of HTML objects (0.8.383, NuRender::deferScripts)
	// as nuBuilder Forte (EXTRAJS): nuAddJavascript of the PHP events and the form's JavaScript in ONE script – function declarations of the
	// form's code are hoisted, so code added in Before Edit / Before Browse may call them (0.8.965); after an error only the PHP part
	. $hoistJs
	. ($errors || $safe ? nu_script(implode("\n", $js), 'php: nuAddJavascript')
		: nu_script(implode("\n\n", array_filter([implode("\n", $js), (string)$meta->form['sfo_javascript'], (string)$meta->form[$mode === 'browse' ? 'sfo_browse_javascript' : 'sfo_edit_javascript']], static fn($x) => trim($x) !== '')), 'php: nuAddJavascript + form JavaScript'))
	. ($safe ? '' : '<script>nuNextLoadGuard(false);</script>')
	. '<script>nuNextStart();</script>'
	. (!empty($client['builder']) ? '<script src="' . nu_asset('assets/js/nu/nudesigner.js') . '" data-labels="' . h((string)json_encode(array_combine(   // Arrange panel (0.8.606)
		['title', 'hint', 'up', 'down', 'top', 'bottom', 'left', 'right', 'vertical', 'horizontal', 'shortest', 'tallest', 'thinnest', 'widest', 'drag', 'hgap', 'vgap', 'apply', 'gap_tip'],
		array_map(static fn($k) => t('dsg_' . $k), ['title', 'hint', 'up', 'down', 'top', 'bottom', 'left', 'right', 'vertical', 'horizontal', 'shortest', 'tallest', 'thinnest', 'widest', 'drag', 'hgap', 'vgap', 'apply', 'gap_tip'])), JSON_UNESCAPED_UNICODE)) . '"></script>' : '')
	. ($design ? '<script src="' . nu_asset('assets/js/nuformatdesigner.js') . '"></script>'     // format designer + attribute editor of the panel (0.8.669)
		. '<script src="' . nu_asset('assets/js/nuattreditor.js') . '"></script>'
		. '<script src="' . nu_asset('assets/js/nu/nudesign.js') . '"></script>' : '')
	;

nu_layout_start($title, $meta->id === 'nunotes' || $meta->id === 'nunotescategroy' ? 'notes' : 'forms', ['head' => $head, 'bare' => $bare, 'nu_form' => [$mode, (string)$meta->form['sfo_code']], 'container' => 'container-fluid nuFormContainer', 'body_class' => 'nuForm nuForm-' . $mode . (!empty($printMode) ? ' nuPrintView' : '') . ($design ? ' nuDesignMode' : '')]);
?>
<div class="nuFormHeader nuTitleBar mb-3">
	<?php if (!$bare): // phones (0.8.1003, the owner picked mockup B): "‹ <parent>" + the title instead of the breadcrumb and Back; the buttons sit right of the title
		$phCur = (string)(end($breadcrumbs)['title'] ?? $title); $phBack = null;
		for ($bi = count($breadcrumbs) - 2; $bi >= 0; $bi--) if (($breadcrumbs[$bi]['url'] ?? '') !== '') { $phBack = $breadcrumbs[$bi]; break; } ?>
	<?php if ($phBack && empty($printMode)): ?><a class="nuPhBack" href="<?= h($phBack['url']) ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i><span><?= h($phBack['title']) ?></span></a><?php endif; ?>
	<h1 class="nuPhTitle"><?= h($phCur) ?></h1>
	<?php endif; ?>
	<?php if (!$bare): ?>
	<nav aria-label="breadcrumb">
		<ol class="breadcrumb mb-0 nuBreadcrumbHolder" id="nuBreadcrumbHolder">
			<?php foreach ($breadcrumbs as $i => $b): ?>
				<li class="breadcrumb-item<?= $b['url'] === '' ? ' active' : '' ?>">
					<?php if ($b['url'] !== ''): ?><a class="nuBreadcrumb" id="nuBreadcrumb<?= (int)$i ?>" href="<?= h($b['url']) ?>"><?= h($b['title']) ?></a>
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
		// Back (0.8.255): to the previous breadcrumb – the list, the Forms list or the start page; not in iframes / lookup windows
		$backIdx = null;
		if (!$bare) for ($bi = count($breadcrumbs) - 2; $bi >= 0; $bi--) if (($breadcrumbs[$bi]['url'] ?? '') !== '') { $backIdx = $bi; break; }
		?>
		<?php if ($backIdx !== null && empty($printMode)): ?>
			<a class="btn nuBtnAct nuActionButton" id="nuBackButton" data-nu-crumb="<?= (int)$backIdx ?>" href="<?= h($breadcrumbs[$backIdx]['url']) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php endif; ?>
		<?php if ($mode === 'browse'): ?>
			<?php if ($rights['add'] && $rights['edit'] && $meta->hasEdit()): // "Add" right only (0.8.461: not on a "browse only" form) (as nuBuilder; "New" is the button on the edit screen) – also in a lookup window: the new record is chosen right after saving ?>
				<button type="button" class="btn nuAddBtn nuActionButton" id="nuAddButton"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></button>
			<?php endif; ?>
			<?php if (empty($printMode) && (int)($data['total'] ?? 0) > 0): // phones (0.8.992): rows as cards or as a table – as on the system lists (nuruntime.js phoneStack) ?>
				<button type="button" class="btn nuBtnAct nuToolBtn d-sm-none nuLvPhoneBtn" id="nuPhoneCardsBtn" aria-pressed="false" title="<?= h(t('lv_phone_toggle')) ?>" aria-label="<?= h(t('lv_phone_toggle')) ?>"><i class="bi bi-table nuLvPhoneToTable" aria-hidden="true"></i><i class="bi bi-card-list nuLvPhoneToCards" aria-hidden="true"></i></button>
			<?php endif; ?>
			<?php if ($meta->id === 'nunotes' && $lookupTo === '' && empty($printMode) && isset(nu_forms()['nunotescategroy']) && NuAcl::can('nunotescategroy')): // notes: categories one click away ?>
				<a class="btn btn-outline-secondary nuActionButton" id="nuNoteCategoriesButton" href="<?= h(nu_url('form', ['f' => 'nunotescategroy', 'bt' => $bt['child'] ?: null])) ?>"><i class="bi bi-tags me-1"></i><?= h(t('notes_categories')) ?></a>
			<?php endif; ?>
			<?php if ($rights['print'] && empty($printMode) && $lookupTo === '' && (int)($data['total'] ?? 0) > 0): // nothing to export without records (0.8.710) ?>
				<?php $expKeep = array_diff_key($keep, ['p' => 1, 'print' => 1, 'iframe' => 1, 'lookup' => 1, 'lo' => 1]); // Export menu (0.8.398): HTML page, CSV …, Excel ?>
				<?= NuListExport::menu(nu_url('form', ['print' => '1'] + array_diff_key($keep, ['iframe' => 1])), nu_url('formexport', ['fmt' => 'xlsx'] + $expKeep),
					NuListExport::csvDialog(['p' => 'formexport'] + $expKeep, $meta->code() ?: 'export', false), 'nuPrintButton', 'nuExportButton', false, 'btn-outline-secondary nuActionButton') ?>
			<?php endif; ?>
		<?php elseif (!$errors): ?>
			<?php if (($client['is_new'] && $rights['create']) || (!$client['is_new'] && $rights['save'])): ?>
				<?php if ($meta->type() !== 'launch'): ?>
					<button type="button" class="btn nuBtnAct nuBtnSave nuActionButton" id="nuSaveButton"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
				<?php endif; ?>
			<?php endif; ?>
			<?php if (!empty($client['report'])): ?>
				<button type="button" class="btn btn-primary nuActionButton" id="nuRunReportButton" data-nu-report="<?= h($client['report']['code']) ?>"><i class="bi bi-file-earmark-pdf me-1"></i><?= h(t('rpt_run')) ?></button>
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
		<?php endif; ?>
		<?php if (($mode === 'edit' || ($mode === 'browse' && $lookupTo === '')) && !$design && !$bare && empty($printMode) && NuDesign::allowed($meta)): // design mode (0.8.662; the list 0.8.669) ?>
			<?php $dsBtn = '<a class="btn btn-outline-primary nuActionButton" id="nuDesignButton" href="' . h(nu_url('form', ['f' => $meta->id, 'r' => $mode === 'edit' ? $r : null, 'design' => '1'])) . '" title="' . h(t('ds_design_tip')) . '"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>' . h(t('ds_design')) . '</a>'; ?>
			<?php $layoutInMenu = $mode === 'edit' && $u['globeadmin'] && !$bare;   // review M6: the layout switch lives in Design ▾ ?>
			<?php if ($browseSqlEdit || $layoutInMenu): ?>
				<div class="btn-group" id="nuDesignGroup"><?= $dsBtn ?><button type="button" class="btn btn-outline-primary dropdown-toggle dropdown-toggle-split" id="nuDesignMore" data-bs-toggle="dropdown" aria-expanded="false" title="<?= h(t('bsq_more')) ?>"><span class="visually-hidden"><?= h(t('bsq_more')) ?></span></button>
					<ul class="dropdown-menu dropdown-menu-end">
					<?php if ($browseSqlEdit): ?><li><button type="button" class="dropdown-item d-flex align-items-center gap-2" id="nuBrowseSqlEdit"><i class="bi bi-filetype-sql" aria-hidden="true"></i><?= h(t('bsq_edit')) ?></button></li>
						<li><button type="button" class="dropdown-item d-flex align-items-center gap-2" id="nuBrowseColsEdit"><i class="bi bi-layout-three-columns" aria-hidden="true"></i><?= h(t('bsq_edit_cols')) ?></button></li><?php endif; ?>
					<?php if ($layoutInMenu): ?><li><h6 class="dropdown-header"><?= h(t('form_layout')) ?></h6></li>
						<?php foreach (['absolute', 'auto', 'grid'] as $l): ?><li><a class="dropdown-item d-flex align-items-center gap-2 nuLayoutItem<?= $layout === $l ? ' active' : '' ?>" data-nu-layout="<?= h($l) ?>"<?= $layout === $l ? ' aria-current="true"' : '' ?> href="<?= h(nu_url('form', ['f' => $meta->id, 'r' => $r, 'layout' => $l])) ?>"><i class="bi <?= $layout === $l ? 'bi-check2' : 'bi-dot' ?>" aria-hidden="true"></i><?= h(t('form_layout_' . $l)) ?></a></li><?php endforeach; ?>
					<?php endif; ?></ul></div>
			<?php else: ?><?= $dsBtn ?><?php endif; ?>
		<?php endif; ?>
		<?php if (!$bare && empty($printMode) && !$design && $uHelp) echo NuUserHelp::button($uHelp);   // user help pages (0.8.1023): ? of the form / tab ?>
		<?php if ($u['globeadmin'] && !$bare): ?>
			<?= str_replace('<div class="dropdown">', '<div class="dropdown nuFormInfoWrap">', NuFormInfo::html($meta, $mode, $r)) /* (i) form info for the globeadmin (0.8.435) */ ?>
			<div class="btn-group nuSeg<?= !empty($layoutInMenu) ? ' d-none' : '' ?>" id="nuLayoutSeg" role="group" aria-label="<?= h(t('form_layout')) ?>">
				<?php if ($mode === 'edit'): foreach (['absolute', 'auto', 'grid'] as $l): ?>
					<a class="btn btn-outline-secondary<?= $layout === $l ? ' active' : '' ?>" href="<?= h(nu_url('form', ['f' => $meta->id, 'r' => $r, 'layout' => $l])) ?>" title="<?= h(t('form_layout_' . $l)) ?>"><?= h(t('form_layout_' . $l)) ?></a>
				<?php endforeach; endif; ?>
			</div>
			<button type="button" class="btn btn-outline-danger d-none" id="nuJsErrors"><i class="bi bi-bug me-1"></i><span></span></button>
		<?php endif; ?>
	</div>
</div>

<?php if ($notice !== ''): ?><div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h($notice) ?></div><?php endif; ?>
<?php // Maintenance / Archived (0.8.808): who may still open the form sees why users cannot – with Change… / End maintenance
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
<?php if (($browseErr ?? '') !== ''): ?><div class="alert alert-danger d-flex gap-2" id="nuBrowseSqlErr"><i class="bi bi-exclamation-octagon mt-1"></i><div><?= nl2br(h($browseErr)) ?></div></div><?php endif; ?>

<?php if ($mode === 'browse' && $data): ?>
	<?php if (!empty($printMode)): ?>
		<div id="nuPrintOpts" hidden data-opts="<?= h((string)json_encode((object)NuPrintView::csvOptions($hash))) ?>"></div><?php // nuPrintCSVExportOptions() (0.8.958) ?>
		<div class="nuPrintHead nuTitleBar mb-2"><h1 class="h5 mb-0"><?= h($meta->description()) ?></h1>
			<div class="nuTitleSub small text-body-secondary"><?= h(date(nu_dfmt('d.m.Y H:i'))) ?> · <?= h(t('n_records', $data['total'])) ?><?= $state['q'] !== '' ? ' · ' . h(t('search')) . ': ' . h($state['q']) : '' ?></div>
			<div class="d-flex flex-wrap gap-2 d-print-none">
				<button class="btn btn-sm btn-primary" type="button" data-nu-copy-table="#nuBrowseTable" data-nu-copied="<?= h(t('copy_done')) ?>" data-nu-copy-failed="<?= h(t('copy_failed')) ?>"><i class="bi bi-clipboard me-1"></i><span><?= h(t('copy_table')) ?></span></button>
				<?= NuListExport::csvDialog(['p' => 'formexport'] + array_diff_key($keep, ['p' => 1, 'print' => 1, 'iframe' => 1, 'lookup' => 1, 'lo' => 1]), $meta->code() ?: 'export') ?>
				<?= NuListExport::xlsxButton(['p' => 'formexport', 'fmt' => 'xlsx'] + array_diff_key($keep, ['p' => 1, 'print' => 1, 'iframe' => 1, 'lookup' => 1, 'lo' => 1])) ?>
			</div></div>
	<?php endif; ?>
	<div class="card shadow-sm nuListCard nuBrowseCard">
		<?php if (empty($printMode)): ?>
		<form class="nuBrowseSearch nuListFilter" method="get" action="index.php" role="search">
			<input type="hidden" name="p" value="form"><input type="hidden" name="f" value="<?= h($meta->id) ?>">
			<?php foreach (array_diff_key($keep, ['f' => 1, 'q' => 1, 'flt' => 1]) as $k => $v): ?>
				<input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
			<?php endforeach; ?>
			<?php // column filters (0.8.537): labelled selects next to the search, like the system lists (users …) – not a row below the header
			$fltCols = array_values(array_filter($data['columns'], static fn($c) => !empty($c['values']) && $c['width'] > 0));
			$fltIdx = array_column($fltCols, 'index');
			foreach ($state['flt'] as $fi => $fv): if (!in_array($fi, $fltIdx, true)): ?><input type="hidden" name="flt[<?= (int)$fi ?>]" value="<?= h($fv) ?>"><?php endif; endforeach; ?>
			<div class="row g-2 align-items-end"><div class="col-md-6">
			<div class="d-flex gap-2 nuListSearch"><?php if ($rights['add'] && $rights['edit'] && $meta->hasEdit()): // phones (0.8.1003): Add next to the search, as the mockup B – clicks #nuAddButton ?><button type="button" class="btn btn-primary nuPhAdd order-last" title="<?= h(t('add')) ?>" aria-label="<?= h(t('add')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i></button><?php endif; ?><div class="input-group nuSearchGroup">
				<input type="search" class="form-control" id="nuSearchField" name="q" value="<?= h($state['q']) ?>" placeholder="<?= h(t('search_ph')) ?>" aria-label="<?= h(t('search')) ?>" autocomplete="off"<?= $lookupTo !== '' ? ' autofocus' : '' ?>>
				<button class="btn btn-outline-secondary nuSearchBtn" id="nuSearchButton" type="submit" title="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>"><i class="bi bi-search"></i></button>
				<?php $advOn = NuAdvSearch::valid($data['columns'] ?? [], $state['adv']) !== []; // advanced search (0.8.719): ▾ opens the panel (assets/js/nu/nuadvsearch.js) ?>
				<button class="btn btn-outline-secondary nuSearchBtn nuAdvBtn<?= $advOn ? ' active' : '' ?>" id="nuAdvButton" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="<?= h(t('as_title')) ?>" aria-label="<?= h(t('as_title')) ?>"><i class="bi bi-chevron-down"></i></button>
				<div class="dropdown-menu dropdown-menu-end p-3 nuAdvMenu" id="nuAdvPanel" aria-labelledby="nuAdvButton"></div>
			</div>
			<?php if ($state['q'] !== '' || $state['flt'] || $advOn): // reset (0.8.428): search words, column filters and the advanced search go, sort / page size / filter of form code stay ?>
				<a class="btn btn-outline-secondary nuSearchBtn nuListResetBtn" id="nuSearchReset" href="<?= h(nu_url('form', array_diff_key($keep, ['q' => 1, 'flt' => 1, 'adv' => 1]))) ?>" title="<?= h(t('reset')) ?>" aria-label="<?= h(t('reset')) ?>"><i class="bi bi-x-lg"></i></a>
			<?php endif; ?>
			<?php if ($fltCols): $fltOn = (bool)array_intersect($fltIdx, array_keys($state['flt'])); ?>
				<button class="btn btn-outline-secondary nuSearchBtn nuFilterToggle<?= $fltOn ? ' active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#nuBrowseFilters" aria-expanded="<?= $iframe && !$fltOn ? 'false' : 'true' ?>" aria-controls="nuBrowseFilters" title="<?= h(t('filter')) ?>" aria-label="<?= h(t('filter')) ?>"><i class="bi bi-funnel<?= $fltOn ? '-fill' : '' ?>"></i></button>
			<?php endif; ?>
			</div></div>
			<?php if ($fltCols): $n = count($fltCols); ?>
			<?php // embedded in a form (run object, review F3): the filters stay folded behind the funnel until one is set ?>
			<div class="<?= $n > 3 ? 'col-12' : 'col-md' ?> collapse<?= $iframe && !$fltOn ? '' : ' show' ?><?= !$fltOn ? ' nuFltPhoneHide' : '' /* review H2: folded on phones until the funnel is pressed */ ?>" id="nuBrowseFilters"><div class="row g-2">
				<?php foreach ($fltCols as $c): ?>
					<div class="<?= $n > 3 ? 'col-sm-6 col-lg-3' : ($n === 1 ? 'col-12' : ($n === 2 ? 'col-sm-6' : 'col-sm-6 col-lg-4')) ?>">
						<label class="form-label small mb-1" for="nuFlt<?= (int)$c['index'] ?>"><?= h(NuHtmlClean::cellText((string)$c['title'])) ?></label>
						<select class="form-select nuColumnFilter<?= isset($state['flt'][$c['index']]) ? ' nuFilterActive' : '' ?>" id="nuFlt<?= (int)$c['index'] ?>" name="flt[<?= (int)$c['index'] ?>]">
							<option value=""><?= h(t('all')) ?></option>
							<?php $fltCur = $state['flt'][$c['index']] ?? null; $fltSeen = false;
							foreach ($c['values'] as [$v, $l]): $fltSeen = $fltSeen || $fltCur === $v; ?><option value="<?= h($v) ?>"<?= $fltCur === $v ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
							<?php if ($fltCur !== null && !$fltSeen): // the chosen value is beyond the first values (review B2): keep it ?><option value="<?= h((string)$fltCur) ?>" selected><?= h((string)$fltCur) ?></option><?php endif; ?>
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
						<a class="badge rounded-pill text-bg-light border text-decoration-none fw-normal nuAdvChip" href="<?= h(nu_url('form', array_diff_key($keep, ['adv' => 1]) + ['adv' => $rest ?: null])) ?>" title="<?= h(t('as_remove')) ?>"><?= h($chip) ?> <i class="bi bi-x" aria-hidden="true"></i></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</form>
		<?php endif; ?>
		<div class="table-responsive">
			<?php // Notes (0.8.469): a delete button at the very left of each row – with the delete right, not in lookups, print or demo mode
			$rowDel = $meta->id === 'nunotes' && !empty($rights['delete']) && $lookupTo === '' && empty($printMode) && !NuDemo::active(); ?>
			<?php // nuBuilder Forte's list settings (0.8.820): multi-line titles, columns fitted to the width, row height
			$rowH = (int)($meta->form['sfo_browse_row_height'] ?? 0);
			$browseCls = ((string)($meta->form['sfo_browse_title_multiline'] ?? '') === '1' ? ' nuBrowseMultiline' : '') . ((string)($meta->form['sfo_browse_autoresize_columns'] ?? '') === '1' ? ' nuBrowseAutoFit' : ''); ?>
			<?php
			// column widths dragged in this browser (0.8.933, cookie nunext_bw of nuruntime.js brCookie): drawn by the server, so the page does
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
			// setting nuAutosizeBrowseColumns is on (its default); otherwise the list keeps the stored widths and scrolls sideways (0.8.965)
			$fitSet = (string)($meta->form['sfo_browse_autoresize_columns'] ?? '');
			$fitCols = $fitSet === '1' || ($fitSet !== '0' && (NuSettings::uxOptions()['nuAutosizeBrowseColumns'] ?? true) !== false);
			$colSum = 0;
			if (!$bw && !$fitCols && $lookupTo === '' && empty($printMode)) {
				foreach ($data['columns'] as $c) if ($c['width'] > 0) $colSum += (int)$c['width'] + NU_BROWSE_CELL_PAD;
				$colSum += ($rowDel ? 44 : 0) + ($rowDetails ? 36 : 0);
			}
			if ($bw) {
				foreach ($data['columns'] as $c) if ($c['width'] > 0) $bwSum += $bw[$c['index']] ?? (int)$c['width'] + NU_BROWSE_CELL_PAD;
				$bwSum += ($rowDel ? 44 : 0) + ($rowDetails ? 36 : 0);
			}
			?>
			<table class="table table-hover align-middle mb-0 nu-table nuBrowseTable<?= $rowDel ? ' nuBrowseRowDel' : '' ?><?= $browseCls ?><?= $bw ? ' nuColFixed nuBrowseFixed' : '' ?>" id="nuBrowseTable" data-nu-sticky-head<?= $rowH > 0 ? ' data-nu-row-height="' . min(400, $rowH) . '"' : '' ?><?= $bw ? ' style="width:' . $bwSum . 'px"' : ($colSum > 0 ? ' style="width:' . $colSum . 'px;min-width:100%"' : '') ?>>
				<thead><tr>
					<?php if ($rowDetails): ?><th scope="col" class="nuDetCol"><span class="visually-hidden"><?= h(t('rd_details')) ?></span></th><?php endif; ?>
					<?php if ($rowDel): ?><th scope="col" class="nuRowDelCol"<?= $bw ? ' style="width:44px"' : '' ?>><span class="visually-hidden"><?= h(t('delete')) ?></span></th><?php endif; ?>
					<?php foreach ($data['columns'] as $c):
						$hidden = $c['width'] <= 0;
						$isSort = $state['sort'] === $c['index'];
						$nextDir = $isSort && $state['dir'] === 'asc' ? 'desc' : 'asc';
						$link = nu_url('form', ['sort' => $c['index'], 'dir' => $nextDir] + $keep);
					?>
						<th scope="col" id="nuBrowseTitle<?= (int)$c['index'] ?>" class="nuBrowseTitle text-<?= ['l' => 'start', 'c' => 'center', 'r' => 'end'][$c['align']] ?? 'start' ?>"
							data-nu-col="<?= (int)$c['index'] ?>" data-nu-browse-id="<?= h($c['id']) ?>" style="<?= $hidden ? 'display:none;' : 'width:' . (int)($bw[$c['index']] ?? (int)$c['width'] + NU_BROWSE_CELL_PAD) . 'px;' ?>"<?= $isSort ? ' aria-sort="' . ($state['dir'] === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
							<a class="nu-sort" id="nusort_<?= (int)$c['index'] ?>" href="<?= h($link) ?>"><?php /* id as nuBuilder Forte's title text (form code styles $('#nusort_2'), 0.8.965) */ ?><?= NuHtmlClean::cell((string)$c['title']) ?><?= $isSort ? ' <i class="bi bi-caret-' . ($state['dir'] === 'asc' ? 'up' : 'down') . '-fill"></i>' : '' ?></a>
						</th>
					<?php endforeach; ?>
				</tr>
				</thead>
				<tbody id="nuRECORD"><?php /* id as nuBuilder Forte's browse (code that loops '#nuRECORD .nuCell') */ ?>
				<?php if (!$data['rows']): ?>
					<tr><td colspan="<?= max(1, count($data['columns'])) + ($rowDel ? 1 : 0) + ($rowDetails ? 1 : 0) ?>" class="text-center text-body-secondary py-4 <?= trim($state['q']) !== '' ? 'nuBrowseNoResults' : 'nuBrowseNoData' ?>"><?= h(t('no_records')) ?></td></tr>
				<?php endif; ?>
				<?php foreach ($data['rows'] as $ri => $row): ?>
					<tr class="nuBrowseRow" data-nu-primary-key="<?= h($row['pk']) ?>" data-nu-row="<?= $ri ?>" tabindex="0"<?= $rowH > 0 ? ' style="height:' . min(400, $rowH) . 'px"' : '' ?>>
						<?php if ($rowDetails): ?><td class="nuDetCol"><button type="button" class="btn btn-sm btn-link nuDetBtn" aria-expanded="false" tabindex="-1" title="<?= h(t('rd_show')) ?>" aria-label="<?= h(t('rd_show')) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td><?php endif; ?>
						<?php if ($rowDel): ?><td class="nuRowDelCol"><button type="button" class="btn btn-sm btn-outline-danger nuRowDel" data-nu-pk="<?= h($row['pk']) ?>" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete')) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></td><?php endif; ?>
						<?php foreach ($data['columns'] as $ci => $c): ?>
							<?php /* the cell = a div as in nuBuilder Forte (id nucell_<row>_<column>, data-nu-*) inside the table cell – form code that
							   selects div[id^=nucell_] or [data-nu-column] finds it (0.8.965) */ ?>
							<td class="nuCellTd text-<?= ['l' => 'start', 'c' => 'center', 'r' => 'end'][$c['align']] ?? 'start' ?>" data-nu-label="<?= h(NuHtmlClean::cellText((string)$c['title'])) ?>"<?= $c['width'] <= 0 ? ' style="display:none"' : '' ?>><div id="nucell_<?= $ri ?>_<?= (int)$c['index'] ?>" class="nuCell nuBrowseTable nuCell<?= $ri % 2 ? 'Odd' : 'Even' ?>"
								data-nu-column="<?= (int)$c['index'] ?>" data-nu-column-id="<?= h((string)($c['id'] ?? '')) ?>" data-nu-row="<?= $ri ?>" data-nu-primary-key="<?= h($row['pk']) ?>"<?= $rowH > 0 ? ' style="max-height:' . min(400, $rowH) . 'px"' : '' /* Forte: a cell keeps the row height, longer content is cut (0.8.974) */ ?>><?= nu_browse_cell((string)$row['cells'][$ci], (string)($row['classes'][$ci] ?? ''), $c, isset($cellActs[$c['index']])) ?></div></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php nu_list_footer((int)$data['total'], (int)$data['page'], (int)$data['per'], 'form', $keep, 'card-footer', 'pg', '', empty($printMode), 'nuBrowseFooter'); ?>
	</div>
<?php elseif ($mode === 'edit'): ?>
	<div class="nuEditForm" id="nuEditForm"><?= $body ?></div>
<?php endif; ?>

<div class="modal fade" id="nuLookupModal" tabindex="-1" aria-hidden="true" aria-labelledby="nuLookupTitle">
	<div class="modal-dialog modal-xl modal-dialog-scrollable">
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
