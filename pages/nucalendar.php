<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Calendars (0.8.1165, core/form/nucalendar.php, assets/js/nu/nucalendar.js). POST (X-CSRF-Token, form fields) → JSON:
 *   action events: start, end (the shown range) and
 *     - f = a form with a list calendar (sfo_json nunext.calendar) + the list's search state (q, rf, flt[], adv, nsc, sc) – the list view, or
 *     - f + o (+ r) = the calendar object o of form f on record r – its source "form" (that form's list calendar, run filter with hash
 *       variables) or "sql" (columns id, start, end, title, color, group, allday)
 *   action move: the same identification + id, start, end, allday – the record is saved through the edit form (NuRecord::save)
 * The settings are read here, never taken from the browser. Rights: the list's browse right; moving: the edit form's save right.
 */
NuTranslate::activate();
$json = static function (array $d, int $code = 200): never {
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
};
if (!nu_is_post()) $json(['ok' => false, 'error' => t('err_forbidden')], 405);
$meta = NuFormMeta::load((string)($_POST['f'] ?? ''));
if (!$meta || !NuAcl::can($meta->id)) $json(['ok' => false, 'error' => t('err_forbidden')], 403);
nu_time_limit(60);
$action = (string)($_POST['action'] ?? '');
[$from, $to] = NuCalendar::rangeOf((string)($_POST['start'] ?? ''), (string)($_POST['end'] ?? ''));
$props = NuRender::props();

/** The list calendar of $m: settings, hash after Before Browse – or an error answer. */
$listOf = static function (NuFormMeta $m) use ($json, $props): array {
	$cfg = NuCalendar::ofForm($m);
	if (!$cfg) $json(['ok' => false, 'error' => t('cal_err_none')]);
	if (!NuAcl::can($m->id, 'browse')) $json(['ok' => false, 'error' => t('err_forbidden')], 403);
	$hash = NuHash::forForm($m, '', [], $props);
	NuPhp::reset();
	if (!NuPhp::run($m->php('BB'), $hash, 'Before Browse')) $json(['ok' => false, 'error' => t('form_error')]);
	return [$cfg, NuPhp::$hash + $hash];
};

$objId = (string)($_POST['o'] ?? '');
$opt = [];
$sqlCfg = null;
$hash = [];
if ($objId === '') {                                             // the list view of form f
	$list = $meta;
	[$cfg, $hash] = $listOf($list);
	$st = nu_browse_request_from($_POST);
	$opt = ['search' => $st['q'], 'runfilter' => $st['rf'], 'filters' => $st['flt'], 'adv' => $st['adv'], 'nosearch' => $st['nsc'], 'searchonly' => $st['sc']];
} else {                                                         // a calendar object of form f
	$o = NuCalendar::object($meta, $objId);
	if (!$o) $json(['ok' => false, 'error' => t('err_invalid')], 404);
	$oc = (array)NuCalendar::ofObject($o);
	$r = (string)($_POST['r'] ?? '');
	$row = $r !== '' && $r !== '-1' && NuRecord::visible($meta, $r) ? (NuRecord::load($meta, $r) ?? []) : [];
	$host = NuHash::forForm($meta, $row ? $r : '-1', $row, $props);
	if ($oc['src'] === 'sql') {
		$sqlCfg = $oc + ['hash' => $host];
		$list = $oc['edit_form'] !== '' ? NuFormMeta::load($oc['edit_form']) : null;
		$cfg = $oc;
	} else {
		$list = NuFormMeta::load($oc['form']);
		if (!$list) $json(['ok' => false, 'error' => t('cal_err_none')]);
		[$cfg, $hash] = $listOf($list);
		$cfg = array_replace($cfg, array_intersect_key($oc, ['edit' => 1, 'drag' => 1]));
		if ($oc['filter'] !== '') $opt['runfilter'] = NuHash::replace($oc['filter'], $host, NuHash::RAW);
	}
}
session_write_close();

try {
	if ($action === 'events') {
		$out = $sqlCfg ? NuCalendar::sqlEvents((string)$sqlCfg['sql'], $sqlCfg['hash'], $from, $to, $sqlCfg['allday'])
			: NuCalendar::listEvents($list, $cfg, $hash, $opt, $from, $to);
		NuBrowse::dropTempTable();
		$json(['ok' => empty($out['error'])] + $out);
	}
	if ($action === 'move') {
		if (!$list || empty($cfg['drag'])) $json(['ok' => false, 'error' => t('err_forbidden')], 403);
		if ($sqlCfg) { $sf = $list->object($sqlCfg['start_field']) ? $sqlCfg['start_field'] : ''; $ef = $sqlCfg['end_field'] !== '' && $list->object($sqlCfg['end_field']) ? $sqlCfg['end_field'] : ''; }
		else { $sf = NuCalendar::fieldOf($list, $cfg['start']); $ef = isset($cfg['end']) ? NuCalendar::fieldOf($list, $cfg['end']) : ''; }
		$id = (string)($_POST['id'] ?? '');
		// the record must be one the calendar shows (list source: inside the list's query with its search; SQL: visible on the edit form)
		if ($id === '' || !NuRecord::visible($list, $id)) $json(['ok' => false, 'error' => t('record_not_found')]);
		$res = NuCalendar::move($list, $id, $sf, $ef, (string)($_POST['start'] ?? ''), (string)($_POST['end'] ?? ''), ($_POST['allday'] ?? '') === '1');
		$json($res);
	}
} catch (Throwable $e) {
	NuBrowse::dropTempTable();
	if (class_exists('NuDebugLog')) { try { NuDebugLog::write("Calendar:\n" . $e->getMessage()); } catch (Throwable) {} }
	$json(['ok' => false, 'error' => t('cal_err_query') . (nu_debug() || !empty(NuAuth::user()['globeadmin']) ? ' ' . $e->getMessage() : '')]);
}
$json(['ok' => false, 'error' => t('err_invalid')], 400);
