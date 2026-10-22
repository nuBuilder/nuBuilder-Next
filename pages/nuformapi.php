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

function nu_api_out(array $data, int $status = 200): never
{
	global $ps;
	if (class_exists('NuPhp', false)) {                // properties set / deleted in PHP stay with the page's scope
		if (NuPhp::$props) NuProps::lock($ps ?? '', NuPhp::$props);
		if (NuPhp::$deleted) NuProps::forget($ps ?? '', array_keys(NuPhp::$deleted));
	}
	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

function nu_api_error(string $msg, int $status = 400): never
{
	nu_api_out(['ok' => false, 'errors' => [['message' => $msg, 'field' => '']]], $status);
}

if (!nu_is_post()) nu_api_error('POST required', 405);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) nu_api_error('Invalid request');

NuTranslate::activate();   // texts in the reader's language (zzzzsys_translate)
$meta = NuFormMeta::load((string)($in['form_id'] ?? ''));
if (!$meta) nu_api_error(t('form_err_not_found'), 404);
if (!NuAcl::can($meta->id)) nu_api_error(t('err_forbidden'), 403);
// form drafts (0.8.386): a form never published (status Draft) is for designers only
if ((string)($meta->form['sfo_status'] ?? '') === '0' && !$meta->isDraft() && !NuAcl::can(NuAcl::FORM_FORM, 'view')) nu_api_error(t('draft_not_published'), 403);

/**
 * Save / Delete in a draft preview (0.8.386): refused unless the draft allows it; then the user confirms once per session
 * ("writes to the live data") – the browser asks on code preview_confirm and sends preview_confirm.
 */
function nu_api_preview_guard(NuFormMeta $meta, array $in): void
{
	if (!$meta->isDraft() || NuFormDraft::previewSaves($meta->id)) return;
	$d = NuFormDraft::previewDraft($meta->id);
	if (!$d || (int)$d['preview_saves'] !== 1) nu_api_error(t('draft_preview_no_save'), 403);
	if (empty($in['preview_confirm'])) nu_api_out(['ok' => false, 'code' => 'preview_confirm', 'errors' => [['message' => t('builder_draft_saves_confirm'), 'field' => '']]], 409);
	NuFormDraft::confirmPreviewSaves($meta->id);
}

$rights = NuAcl::rights($meta->id);
$ps     = is_string($in['ps'] ?? null) ? $in['ps'] : '';
$props  = NuProps::merge($ps, $in['props'] ?? []);      // browser values; names set in PHP keep the server's value
$in['props'] = $props;
$values = is_array($in['values'] ?? null) ? $in['values'] : [];
$record = (string)($in['record_id'] ?? '');

/** The object (of this form or one of its subforms) a lookup request refers to. */
function nu_api_object(NuFormMeta $meta, string $objectId): array
{
	// from the form's metadata (and its sub-forms') – live, or the draft in a preview (0.8.386); only objects of these forms
	$o = $meta->object($objectId);
	if (!$o) {
		foreach ($meta->objects as $so) {
			if ($so['sob_all_type'] !== 'subform') continue;
			$o = NuFormMeta::load((string)$so['sob_subform_zzzzsys_form_id'])?->object($objectId);
			if ($o) break;
		}
	}
	if (!$o) {
		$exists = (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [$objectId]);
		$exists ? nu_api_error(t('err_forbidden'), 403) : nu_api_error('Unknown object', 404);
	}
	return $o;
}

/** sob_all_id of the subform object that shows form $formId ('' for the form itself). */
function nu_api_subform_id(NuFormMeta $meta, string $formId): string
{
	if ($formId === $meta->id) return '';
	foreach ($meta->objects as $so) if ($so['sob_all_type'] === 'subform' && $so['sob_subform_zzzzsys_form_id'] === $formId) return (string)$so['sob_all_id'];
	return '';
}

try {
	switch ((string)($in['action'] ?? '')) {

		case 'save':
			$isNew = $record === '' || $record === '-1';
			if (!$rights[$isNew ? 'create' : 'save']) nu_api_error(t('err_forbidden'), 403);
			if (NuSecurity::runtimeBlocked($meta)) nu_api_error(t('secchk_err_system_form'), 403);   // users, rights, code: globeadmin only
			nu_api_preview_guard($meta, $in);
			if (NuDemo::active()) {
				// demo mode: saving only in $nuConfigDemoSavingAllowedIds, and never deleting – also no subform rows (core/nudemo.php)
				if (!NuDemo::savingAllowed($meta->id, $meta->code())) nu_api_error(t('demo_not_available'), 403);
				foreach ((array)($in['subforms'] ?? []) as $sf) {
					foreach ((array)($sf['rows'] ?? []) as $r) if (is_array($r) && !empty($r['deleted']) && (string)($r['pk'] ?? '') !== '') nu_api_error(t('demo_no_delete'), 403);
				}
				if (NuDemo::saveLimitReached()) nu_api_error(t('demo_save_limit', NuDemo::SAVES_PER_HOUR), 429);   // 0.8.341: public demos get spammed
				$res = NuDemo::permitted(static fn() => NuRecord::save($meta, $in));
				if (!empty($res['ok'])) NuLog::write('demo_save', (string)$meta->table(), (string)($res['record_id'] ?? $record), ['form' => $meta->code() ?: $meta->id]);
				nu_api_out($res);
			}
			nu_api_out(NuRecord::save($meta, $in));

		case 'delete':
			if (!$rights['delete']) nu_api_error(t('err_forbidden'), 403);
			if (NuSecurity::runtimeBlocked($meta)) nu_api_error(t('secchk_err_system_form'), 403);
			nu_api_preview_guard($meta, $in);
			if (NuDemo::active()) nu_api_error(t('demo_no_delete'), 403);
			nu_api_out(NuRecord::delete($meta, $record, $props, $in));

		case 'presence':                                   // concurrent edits (0.8.401): "also open by …" (passive – see index.php)
			if (!NuConflict::presenceEnabled()) nu_api_out(['ok' => true, 'others' => []]);
			nu_api_out(['ok' => true, 'others' => NuConflict::ping($meta->id, $record, !empty($in['edited']), !empty($in['leave']))]);

		case 'lookupid':
		case 'lookupcode':
			$o  = nu_api_object($meta, (string)($in['object_id'] ?? ''));
			$lf = NuLookup::form($o);
			if (!$lf) nu_api_error(t('form_err_not_found'));
			if (!NuAcl::can($lf->id)) nu_api_error(t('form_err_lookup_denied'), 403);
			$hash = NuHash::forForm($meta, $record, $values, $props);

			if ($in['action'] === 'lookupcode') {
				$matches = NuLookup::byCode($o, (string)($in['code'] ?? ''), $hash);
				if (count($matches) !== 1) nu_api_out(['ok' => true, 'count' => count($matches), 'lookup_form' => $lf->id]);
				$rec = $matches[0];
			} else {
				$rec = NuLookup::byId($o, (string)($in['pk'] ?? ''), $hash);
				if (!$rec) nu_api_out(['ok' => true, 'count' => 0, 'lookup_form' => $lf->id]);
			}
			// nuBuilder: #ID# / #CODE# of the chosen record, #SUBFORM_ID# when the lookup is in a subform row
			$hash = NuHash::forForm($meta, $record, $values, $props, ['ID' => (string)$rec['pk'], 'CODE' => (string)$rec['code'], 'SUBFORM_ID' => nu_api_subform_id($meta, (string)$o['sob_all_zzzzsys_form_id'])]);
			$res = NuLookup::afterBrowse($o, $rec, $hash);
			nu_api_out(['ok' => true, 'count' => 1, 'pk' => $rec['pk'], 'code' => $rec['code'], 'description' => $rec['description'], 'lookup_form' => $lf->id] + $res);

		case 'refresh':
			// nuRefreshSelectObject / nuRefreshDisplayObject: options or value of a select / display object, re-run with the
			// current values (main form + the subform row). The object belongs to this form or one of its subforms.
			$objForm = (string)($in['object_form'] ?? '') ?: $meta->id;
			$row = nu_db()->one('SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_id = ?', [$objForm, (string)($in['object'] ?? '')]);
			if (!$row) nu_api_out(['ok' => true, 'found' => false]);
			$o = nu_api_object($meta, (string)$row['zzzzsys_object_id']);
			$rowValues = is_array($in['row'] ?? null) ? $in['row'] : [];
			$hash = NuHash::forForm($meta, $record, $rowValues + $values, $props, ['SUBFORM_ID' => nu_api_subform_id($meta, $objForm)]);
			$r = new NuRender($meta, $record, [], $hash, 'absolute');
			if ($o['sob_all_type'] === 'display') nu_api_out(['ok' => true, 'found' => true, 'value' => $r->display($o)]);
			if ($o['sob_all_type'] === 'select') nu_api_out(['ok' => true, 'found' => true, 'options' => $r->options($o)]);
			nu_api_out(['ok' => true, 'found' => false]);

		case 'props':
			nu_api_out(['ok' => true, 'ps' => NuProps::fork($ps, $in['props'] ?? [])]);

		case 'global':
			$name = (string)($in['name'] ?? '');
			if (!preg_match('/^\w{1,100}$/', $name) || NuHash::isSystem($name)) nu_api_error(t('form_err_property_name', $name));
			if (!empty($in['delete'])) NuHashCookies::delete($name); else NuHashCookies::set($name, $in['value'] ?? '');
			nu_api_out(['ok' => true, 'globals' => (object)NuHashCookies::all()]);

		case 'hashfields':                                   // "#" menu of the code editor: fields of the form the code belongs to
			$target = NuFormMeta::load((string)($in['target'] ?? ''));
			if (!$target || !(NuAcl::can(NuAcl::FORM_FORM) || NuAcl::can($target->id))) nu_api_out(['ok' => true, 'fields' => []]);
			$fields = [];
			foreach ($target->objects as $o) {
				if (!in_array($o['sob_all_type'], ['input', 'select', 'lookup', 'textarea', 'calc', 'display', 'editor'], true) || ($o['sob_input_type'] ?? '') === 'button') continue;
				$fields[] = [(string)$o['sob_all_id'], trim(strip_tags((string)$o['sob_all_label']))];
			}
			nu_api_out(['ok' => true, 'fields' => $fields]);

		case 'hash':
			if (!NuAuth::user()['globeadmin']) nu_api_error(t('err_forbidden'), 403);
			$hv = array_map(static fn($x) => ['value' => (string)NuSecurity::demoMask($x['name'], $x['value'])] + $x, NuHash::explain($meta, $record, $values, $props));
			nu_api_out(['ok' => true, 'hash' => $hv, 'locked' => NuProps::locked($ps)]);

		case 'report':
			// nuRunReport / nuRunReportSave / nuEmailReportPDF: the report (code or id) with this form's hash variables → a run token for ?p=reportpdf
			$report = NuReport::load((string)($in['code'] ?? ''));
			if (!$report) nu_api_error(t('rpt_err_missing', (string)($in['code'] ?? '')), 404);
			if (!NuReport::canRun($report)) nu_api_error(t('rpt_err_denied', (string)$report['sre_code']), 403);
			$hash = NuHash::forForm($meta, $record, $values, $props);
			nu_api_out(['ok' => true, 'id' => NuReport::token($report, $hash), 'code' => (string)$report['sre_code']]);

		case 'php':
			$hash = NuHash::forForm($meta, $record, $values, $props);
			NuPhp::reset();
			$r = NuPhp::procedure((string)($in['code'] ?? ''), $hash);
			if (!$r['ok']) nu_api_error($r['error'], 403);
			$out = NuPhp::result();
			$out['ok'] = !$out['errors'];
			$out['output'] = NuPhp::$output;
			nu_api_out($out);
	}
	nu_api_error('Unknown action');
} catch (Throwable $e) {
	error_log('nuBuilder Next api: ' . $e->getMessage());
	nu_api_error(NuAuth::user()['globeadmin'] || nu_cfg('nuConfigDebug') ? $e->getMessage() : t('err_unexpected'), 500);
}
