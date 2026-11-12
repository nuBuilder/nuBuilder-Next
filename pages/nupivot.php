<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Pivot of a form list (0.8.1151, core/form/nupivot.php, assets/js/nu/nupivot.js). POST action (X-CSRF-Token, form fields):
 *   fields | run (spec) | values (c, g) | drill (spec, rk, ck) | saved | save (name, spec) | delete (id) → JSON
 *   export (fmt xlsx | csv, head, lines – the table as shown) → file
 * f = form id; the list's search state comes along as on the list page (q, rf, flt[], adv, nsc, sc, ps). Rights: the list's view + print.
 */
NuTranslate::activate();
$json = static function (array $d, int $code = 200): never {
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
};
$meta = NuFormMeta::load((string)($_GET['f'] ?? $_POST['f'] ?? ''));
if (!nu_is_post() || !$meta || !$meta->hasBrowse() || !NuAcl::can($meta->id, 'browse') || !NuAcl::can($meta->id, 'print')) $json(['ok' => false, 'error' => t('err_forbidden')], 403);
session_write_close();
nu_time_limit(120);
$action = (string)($_POST['action'] ?? '');

if ($action === 'export') {
	$head = json_decode((string)($_POST['head'] ?? '[]'), true);
	$lines = json_decode((string)($_POST['lines'] ?? '[]'), true);
	if (!is_array($head) || !is_array($lines)) $json(['ok' => false, 'error' => t('err_invalid')], 400);
	$head = array_map(static fn($x) => (string)$x, $head);
	$lines = array_map(static fn($l) => array_map(static fn($x) => is_scalar($x) || $x === null ? (string)$x : '', (array)$l), array_slice($lines, 0, 20000));
	NuLog::write('pivot_exported', $meta->code(), '', ['rows' => count($lines)]);
	$base = ($meta->code() ?: 'pivot') . '-pivot';
	if (($_POST['fmt'] ?? '') === 'xlsx') NuListExport::writeXlsx($base, t('pv_title') . ' – ' . ($meta->description() ?: $meta->code()), $head, $lines);
	NuListExport::writeCsv($base, $head, $lines);
}

if ($action === 'saved') $json(['ok' => true, 'saved' => NuPivot::saved($meta->id)]);
if ($action === 'save' || $action === 'delete') {
	$err = $action === 'save' ? NuPivot::save($meta->id, (string)($_POST['name'] ?? ''), (array)(json_decode((string)($_POST['spec'] ?? ''), true) ?: [])) : '';
	if ($action === 'delete') NuPivot::delete($meta->id, (string)($_POST['id'] ?? ''));
	$json(['ok' => $err === '', 'error' => $err, 'saved' => NuPivot::saved($meta->id)]);
}

// the list as the user sees it: Before Browse, hash, search / filters of the page
$hash = NuHash::forForm($meta, '', [], NuRender::props());
NuPhp::reset();
if (!NuProcedure::hook('nu_before_save', $hash) || !NuPhp::run($meta->php('BB'), $hash, 'Before Browse')) $json(['ok' => false, 'error' => t('form_error')]);
$hash = NuPhp::$hash + $hash;
$st = nu_browse_request_from($_POST);
$opt = ['search' => $st['q'], 'runfilter' => $st['rf'], 'filters' => $st['flt'], 'adv' => $st['adv'], 'nosearch' => $st['nsc'], 'searchonly' => $st['sc']];
$spec = $_POST['spec'] ?? '';
try {
	$out = match ($action) {
		'fields' => ['fields' => NuPivot::fields(NuBrowse::columns($meta, $hash))],
		'run'    => NuPivot::run($meta, $hash, $opt, $spec),
		'values' => ['values' => NuPivot::values($meta, $hash, $opt, (int)($_POST['c'] ?? -1), (string)($_POST['g'] ?? ''))],
		'drill'  => NuPivot::drill($meta, $hash, $opt, $spec, (array)(json_decode((string)($_POST['rk'] ?? '[]'), true) ?: []), (array)(json_decode((string)($_POST['ck'] ?? '[]'), true) ?: [])),
		default  => null,
	};
} catch (Throwable $e) {
	NuBrowse::dropTempTable();
	if (class_exists('NuDebugLog')) { try { NuDebugLog::write("Pivot:\n" . $e->getMessage()); } catch (Throwable) {} }
	$json(['ok' => false, 'error' => t('pv_err_query') . (nu_debug() || !empty(NuAuth::user()['globeadmin']) ? ' ' . $e->getMessage() : '')]);
}
NuBrowse::dropTempTable();
if ($out === null) $json(['ok' => false, 'error' => t('err_invalid')], 400);
$json(['ok' => empty($out['error'])] + $out);
