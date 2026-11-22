<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * JSON API of the form runtime (POST, X-CSRF-Token header):
 *   save, delete, lookupid, lookupcode, php (nuRunPHPHidden), refresh,
 *   props (property scope for a form opened from here), global (hash cookie set / delete), hash (hash viewer, globeadmin)
 * Properties: ps = the page's scope key (NuProps); values set in PHP are kept there and win over the browser's copy.
 */

header('Content-Type: application/json; charset=utf-8');



if (!nu_is_post()) NuFormApiPage::error('POST required', 405);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) NuFormApiPage::error('Invalid request');

NuTranslate::activate();   // texts in the reader's language (zzzzsys_translate)
$meta = NuFormMeta::load((string)($in['form_id'] ?? ''));
if (!$meta) NuFormApiPage::error(t('form_err_not_found'), 404);
if (!NuAcl::can($meta->id)) NuFormApiPage::error(t('err_forbidden'), 403);
// form drafts: a form never published (status Draft) is for designers only
if ((string)($meta->form['sfo_status'] ?? '') === '0' && !$meta->isDraft() && !NuAcl::can(NuAcl::FORM_FORM, 'view')) NuFormApiPage::error(t('draft_not_published'), 403);
// Maintenance / Archived: also a record that was open before is not saved – the answer says why
if (!$meta->isDraft()) NuFormStatus::gate($meta->id, NuFormStatus::norm($meta->form['sfo_status'] ?? ''), true);


$rights = NuAcl::rights($meta->id);
$ps     = is_string($in['ps'] ?? null) ? $in['ps'] : '';
$props  = NuProps::merge($ps, $in['props'] ?? []);      // browser values; names set in PHP keep the server's value
NuHash::$caller = NuProps::caller($ps);                 // fields of the edit form this page was opened from
NuHash::$state = NuHash::cleanState($in['crumb'] ?? null);   // nuBuilder Forte's breadcrumb keys in nuHash()
// nuHash()['browse_sql'] from a list: the SQL the server showed for this form (pages/nuform.php), never what the browser sent
if (isset(NuHash::$state['browse_sql']) && NuHash::$state['browse_sql'] !== '0') {   // '0' = an edit page (no list)
	NuHash::$state['browse_sql'] = (string)($_SESSION[NuSess::BROWSE_SQL][$meta->id] ?? '');
}
$in['props'] = $props;
$values = is_array($in['values'] ?? null) ? $in['values'] : [];
$record = (string)($in['record_id'] ?? '');



try {
	switch ((string)($in['action'] ?? '')) {

		case 'save':
			$isNew = $record === '' || $record === '-1';
			// access level (as nuBuilder 4.9): "no changes to existing records" / "browse only"
			if (!$isNew && !empty($rights['no_edits'])) NuFormApiPage::error(t('acl_no_edits'), 403);
			if (empty($rights['edit']) && $meta->type() !== 'launch') NuFormApiPage::error(t('acl_form_type_denied'), 403);
			if (!$rights[$isNew ? 'create' : 'save']) NuFormApiPage::error(t('err_forbidden'), 403);
			if (NuSecurity::runtimeBlocked($meta)) NuFormApiPage::error(t('secchk_err_system_form'), 403);   // users, rights, code: globeadmin only
			NuFormApiPage::previewGuard($meta, $in);
			if (NuDemo::active()) {
				// demo mode: saving only in $nuConfigDemoSavingAllowedIds, and never deleting – also no subform rows (core/nudemo.php)
				if (!NuDemo::savingAllowed($meta->id, $meta->code())) NuFormApiPage::error(t('demo_not_available'), 403);
				foreach ((array)($in['subforms'] ?? []) as $sf) {
					foreach ((array)($sf['rows'] ?? []) as $r) if (is_array($r) && !empty($r['deleted']) && (string)($r['pk'] ?? '') !== '') NuFormApiPage::error(t('demo_no_delete'), 403);
				}
				if (NuDemo::saveLimitReached()) NuFormApiPage::error(t('demo_save_limit', NuDemo::SAVES_PER_HOUR), 429);   // public demos get spammed
				$res = NuDemo::permitted(static fn() => NuRecord::save($meta, $in));
				if (!empty($res['ok'])) NuLog::write('demo_save', (string)$meta->table(), (string)($res['record_id'] ?? $record), ['form' => $meta->code() ?: $meta->id]);
				NuFormApiPage::out($res);
			}
			NuFormApiPage::out(NuRecord::save($meta, $in));

		case 'delete':
			if (!$rights['delete']) NuFormApiPage::error(t('err_forbidden'), 403);
			if (NuSecurity::runtimeBlocked($meta)) NuFormApiPage::error(t('secchk_err_system_form'), 403);
			NuFormApiPage::previewGuard($meta, $in);
			if (NuDemo::active()) NuFormApiPage::error(t('demo_no_delete'), 403);
			NuFormApiPage::out(NuRecord::delete($meta, $record, $props, $in));

		case 'details':                                    // row details of the list: the related list of one record – reading only
			NuFormApiPage::out(['ok' => true] + NuRowDetails::related($meta, $record, is_string($in['list'] ?? null) ? $in['list'] : ''));

		case 'presence':                                   // concurrent edits: "also open by …" (passive – see index.php)
			if (!NuConflict::presenceEnabled()) NuFormApiPage::out(['ok' => true, 'others' => []]);
			NuFormApiPage::out(['ok' => true, 'others' => NuConflict::ping($meta->id, $record, !empty($in['edited']), !empty($in['leave']))]);

		case 'lookupid':
		case 'lookupcode':
			$o  = NuFormApiPage::object($meta, (string)($in['object_id'] ?? ''));
			if (NuFieldAccess::withheld($o) || NuFieldAccess::masked($o) || NuFieldAccess::readonly($o, $record === '-1')) NuFormApiPage::out(['ok' => true, 'count' => 0]);   // field data access
			$lf = NuLookup::form($o);
			if (!$lf) NuFormApiPage::error(t('form_err_not_found'));
			if (!NuAcl::can($lf->id)) NuFormApiPage::error(t('form_err_lookup_denied'), 403);
			$hash = NuHash::forForm($meta, $record, $values, $props);

			if ($in['action'] === 'lookupcode') {
				$matches = NuLookup::byCode($o, (string)($in['code'] ?? ''), $hash);
				if (count($matches) !== 1) NuFormApiPage::out(['ok' => true, 'count' => count($matches), 'lookup_form' => $lf->id]);
				$rec = $matches[0];
			} else {
				$rec = NuLookup::byId($o, (string)($in['pk'] ?? ''), $hash);
				if (!$rec) NuFormApiPage::out(['ok' => true, 'count' => 0, 'lookup_form' => $lf->id]);
			}
			// nuBuilder: #ID# / #CODE# of the chosen record, #SUBFORM_ID# when the lookup is in a subform row
			$hash = NuHash::forForm($meta, $record, $values, $props,
				['ID' => (string)$rec['pk'], 'CODE' => (string)$rec['code'], 'SUBFORM_ID' => NuFormApiPage::subformId($meta, (string)$o['sob_all_zzzzsys_form_id'])]);
			$res = NuLookup::afterBrowse($o, $rec, $hash);
			NuFormApiPage::out(['ok' => true, 'count' => 1, 'pk' => $rec['pk'], 'code' => $rec['code'], 'description' => $rec['description'], 'lookup_form' => $lf->id] + $res);

		case 'refresh':
			// nuRefreshSelectObject / nuRefreshDisplayObject: options or value of a select / display object, re-run with the
			// current values (main form + the subform row). The object belongs to this form or one of its subforms.
			$objForm = (string)($in['object_form'] ?? '') ?: $meta->id;
			$row = nu_db()->one('SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_id = ?', [$objForm, (string)($in['object'] ?? '')]);
			if (!$row) NuFormApiPage::out(['ok' => true, 'found' => false]);
			$o = NuFormApiPage::object($meta, (string)$row['zzzzsys_object_id']);
			$rowValues = is_array($in['row'] ?? null) ? $in['row'] : [];
			$hash = NuHash::forForm($meta, $record, $rowValues + $values, $props, ['SUBFORM_ID' => NuFormApiPage::subformId($meta, $objForm)]);
			$r = new NuRender($meta, $record, [], $hash, 'absolute');
			if (NuFieldAccess::withheld($o)) NuFormApiPage::out(['ok' => true, 'found' => false]);   // field data access
			if ($o['sob_all_type'] === 'display') NuFormApiPage::out(['ok' => true, 'found' => true, 'value' => (string)NuFieldAccess::shown($o, $r->display($o))]);
			if ($o['sob_all_type'] === 'select') NuFormApiPage::out(['ok' => true, 'found' => true, 'options' => $r->options($o)]);
			NuFormApiPage::out(['ok' => true, 'found' => false]);

		case 'suggest':
			// suggestions while typing (NuSuggest): rows of the input's query for the typed text. The browser names the
			// object; the query stays on the server. The object belongs to this form or one of its sub-forms.
			$o = NuFormApiPage::object($meta, (string)($in['object_id'] ?? ''));
			if (NuRecord::hiddenFor($o) || NuFieldAccess::withheld($o)) NuFormApiPage::out(['ok' => true, 'cols' => [], 'rows' => []]);
			$rowValues = is_array($in['row'] ?? null) ? $in['row'] : [];
			$hash = NuHash::forForm($meta, $record, $rowValues + $values, $props, ['SUBFORM_ID' => NuFormApiPage::subformId($meta, (string)$o['sob_all_zzzzsys_form_id'])]);
			session_write_close();
			NuFormApiPage::out(['ok' => true] + NuSuggest::rows($o, (string)($in['term'] ?? ''), $hash));

		case 'props':
			NuFormApiPage::out(['ok' => true, 'ps' => NuProps::fork($ps, $in['props'] ?? [], $in['caller'] ?? null)]);

		case 'global':
			$name = (string)($in['name'] ?? '');
			if (!preg_match('/^\w{1,100}$/', $name) || NuHash::isSystem($name)) NuFormApiPage::error(t('form_err_property_name', $name));
			if (!empty($in['delete'])) NuHashCookies::delete($name); else NuHashCookies::set($name, $in['value'] ?? '');
			NuFormApiPage::out(['ok' => true, 'globals' => (object)NuHashCookies::all()]);

		case 'hashfields':                                   // "#" menu of the code editor: fields of the form the code belongs to
			$target = NuFormMeta::load((string)($in['target'] ?? ''));
			if (!$target || !(NuAcl::can(NuAcl::FORM_FORM) || NuAcl::can($target->id))) NuFormApiPage::out(['ok' => true, 'fields' => []]);
			$fields = [];
			foreach ($target->objects as $o) {
				if (!in_array($o['sob_all_type'], ['input', 'select', 'lookup', 'textarea', 'calc', 'display', 'editor'], true) || ($o['sob_input_type'] ?? '') === 'button') continue;
				$fields[] = [(string)$o['sob_all_id'], trim(strip_tags((string)$o['sob_all_label']))];
			}
			NuFormApiPage::out(['ok' => true, 'fields' => $fields]);

		case 'hash':
			if (!NuAuth::user()['globeadmin']) NuFormApiPage::error(t('err_forbidden'), 403);
			$hv = array_map(static fn($x) => ['value' => (string)NuSecurity::demoMask($x['name'], $x['value'])] + $x, NuHash::explain($meta, $record, $values, $props));
			NuFormApiPage::out(['ok' => true, 'hash' => $hv, 'locked' => NuProps::locked($ps)]);

		case 'report':
			// nuRunReport / nuRunReportSave / nuEmailReportPDF: the report (code or id) with this form's hash variables → a run token for ?p=reportpdf
			$report = NuReport::load((string)($in['code'] ?? ''));
			if (!$report) NuFormApiPage::error(t('rpt_err_missing', (string)($in['code'] ?? '')), 404);
			if (!NuReport::canRun($report)) NuFormApiPage::error(t('rpt_err_denied', (string)$report['sre_code']), 403);
			$hash = NuHash::forForm($meta, $record, $values, $props);
			NuFormApiPage::out(['ok' => true, 'id' => NuReport::token($report, $hash), 'code' => (string)$report['sre_code']]);

		case 'php':
			$hash = NuHash::forForm($meta, $record, $values, $props);
			// nuBuilder Forte sends the edit form's data with a procedure call: nuHash()['nuFORMdata'] = the form and its sub-forms
			if (is_array($in['formdata'] ?? null) && array_is_list($in['formdata'])) {
				$fd = json_decode((string)json_encode(array_slice(array_filter($in['formdata'], 'is_array'), 0, 50)), false);
				if (is_array($fd)) $hash['nuFORMdata'] = $fd;
			}
			NuPhp::reset();
			// nuRunPHP (window): the procedure runs as if on its output page – $_SERVER['REQUEST_URI'] / $_GET are that page's
			$t = !empty($in['window']) ? NuRunPhp::token($meta->id, (string)($in['code'] ?? ''), $hash) : '';
			$r = $t !== '' ? NuRunPhp::asPage($t, static fn() => NuPhp::procedure((string)($in['code'] ?? ''), $hash))
				: NuPhp::procedure((string)($in['code'] ?? ''), $hash);
			if (!$r['ok']) NuFormApiPage::error($r['error'], 403);
			$out = NuPhp::result();
			$out['ok'] = !$out['errors'];
			// nuRunPHP (window): the output gets an address of its own, ?p=runphp&i=<token> (pages/nurunphp.php, as nuBuilder Forte's
			// nurunphp.php?i=) – it shows this output first; a reload or a link with more parameters runs the procedure again
			if ($t !== '' && strlen(NuPhp::$output) <= NuRunPhp::MAX_OUTPUT) {
				NuRunPhp::keepOutput($t, NuPhp::$output);
				$out['url'] = NuRunPhp::url($t);
			} else {
				$out['output'] = NuPhp::$output;
			}
			NuFormApiPage::out($out);
	}
	NuFormApiPage::error('Unknown action');
} catch (Throwable $e) {
	error_log('nuBuilder Next api: ' . $e->getMessage());
	NuFormApiPage::error(NuAuth::user()['globeadmin'] || nu_debug() ? $e->getMessage() : t('err_unexpected'), 500);
}
