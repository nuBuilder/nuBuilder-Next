<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * JSON API of the dashboards (POST with X-CSRF-Token) – assets/js/nudashboard.js:
 *   meta  {code, form_id?, record_id?}                       → title, widgets (without their source), date filter, texts
 *   data  {code, widget, form_id?, record_id?, values?, range?, from?, to?, force?} → columns and rows of one widget
 *   test  {w: widget fields, range?, record_id?}             → globeadmin: runs a widget of the editor before it is saved
 * Without form_id the dashboard must be on the start page; with form_id the user must be allowed to open the form and the record, and
 * the dashboard must be placed on that form (HTML object or the form's JavaScript names its code). The globeadmin may see every one.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');


if (!nu_is_post()) NuDashboardApiPage::out(['ok' => false, 'error' => 'POST required'], 405);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) NuDashboardApiPage::out(['ok' => false, 'error' => 'Invalid request'], 400);
NuTranslate::activate();
$u = NuAuth::user();
$ga = (bool)$u['globeadmin'];
$action = (string)($in['action'] ?? '');
$range = (string)($in['range'] ?? '');
$dates = NuDashboard::range(in_array($range, NuDashboard::RANGES, true) ? $range : '30d', (string)($in['from'] ?? ''), (string)($in['to'] ?? ''));
$errMsg = static fn(Throwable $e) => $ga || nu_debug() ? $e->getMessage() : t('dash_error');

try {
	if ($action === 'test') {
		if (!$ga) NuDashboardApiPage::out(['ok' => false, 'error' => t('err_forbidden')], 403);
		[$row, $errors] = NuDashboard::widgetInput(is_array($in['w'] ?? null) ? $in['w'] : []);
		if ($errors) NuDashboardApiPage::out(['ok' => false, 'error' => implode(' ', array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($errors), $errors))]);
		$w = ['zzzzsys_nunext_widget_id' => 'test'] + $row;
		$w['opt'] = NuDashboard::options(json_decode($row['options'], true) ?: [], $row['type']);
		$hash = NuDashboard::hash(null, '', [], $dates);
		$rec = trim((string)($in['record_id'] ?? ''));
		if ($rec !== '') $hash['RECORD_ID'] = $rec;
		$t0 = microtime(true);
		$data = NuDashboard::run($w, $hash);
		NuDashboardApiPage::out(['ok' => true, 'widget' => NuDashboard::clientWidget($w), 'data' => $data, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'msgs' => NuDashboard::messages()]);
	}

	$d = NuDashboard::byCode((string)($in['code'] ?? ''));
	if (!$d) NuDashboardApiPage::out(['ok' => false, 'error' => t('dash_err_missing', (string)($in['code'] ?? ''))], 404);
	if (!NuDashboard::visible($d)) NuDashboardApiPage::out(['ok' => false, 'error' => t('err_forbidden'), 'hide' => true]);   // hidden, not an error: no 403 in the browser console

	$meta = null;
	$record = '';
	$formId = (string)($in['form_id'] ?? '');
	if ($formId !== '') {
		$meta = NuFormMeta::load($formId);
		if (!$meta || !NuAcl::can($meta->id)) NuDashboardApiPage::out(['ok' => false, 'error' => t('err_forbidden')], 403);
		if (!$ga
			&& !NuDashboard::placed($meta, (string)$d['code'])) NuDashboardApiPage::out(['ok' => false, 'error' => t('dash_err_not_placed'),
				'hide' => true]);   // hidden, not an error: no 403 in the browser console
		$record = (string)($in['record_id'] ?? '');
		if ($record !== '' && $record !== '-1' && !NuRecord::visible($meta, $record)) NuDashboardApiPage::out(['ok' => false, 'error' => t('err_forbidden')], 403);
	} elseif (!$ga && (string)$d['on_home'] !== '1') {
		NuDashboardApiPage::out(['ok' => false, 'error' => t('err_forbidden'), 'hide' => true]);   // hidden, not an error: no 403 in the browser console
	}
	$widgets = NuDashboard::widgets((string)$d['zzzzsys_nunext_dashboard_id']);
	$isNew = $meta !== null && $meta->table() !== '' && ($record === '-1' || $record === '');

	if ($action === 'meta') {
		$list = [];
		foreach ($widgets as $w) $list[] = NuDashboard::clientWidget($w) + ['wait' => $isNew && NuDashboard::usesRecord($w, $meta)];
		NuDashboardApiPage::out(['ok' => true, 'code' => (string)$d['code'], 'title' => (string)$d['title'], 'dates' => NuDashboard::usesDates($widgets),
			'range' => (string)($d['date_range'] ?: '30d'), 'widgets' => $list, 'msgs' => NuDashboard::messages(),
			'edit' => $ga ? nu_url('dashboards', ['id' => $d['zzzzsys_nunext_dashboard_id']]) : '']);
	}

	if ($action === 'data') {
		$wid = (string)($in['widget'] ?? '');
		$w = null;
		foreach ($widgets as $x) if ((string)$x['zzzzsys_nunext_widget_id'] === $wid) $w = $x;
		if (!$w) NuDashboardApiPage::out(['ok' => false, 'error' => t('dash_error')], 404);
		if ($isNew && NuDashboard::usesRecord($w, $meta)) NuDashboardApiPage::out(['ok' => true, 'wait' => true]);
		$hash = NuDashboard::hash($meta, $record, is_array($in['values'] ?? null) ? $in['values'] : [], $dates);
		try {
			$data = NuDashboard::data($w, $hash, !empty($in['force']), true);   // true: no session lock while a query runs
		} catch (Throwable $e) {
			if (!$e instanceof RuntimeException) error_log('nuBuilder Next dashboard ' . $d['code'] . ': ' . $e->getMessage());
			NuDashboardApiPage::out(['ok' => false, 'error' => $errMsg($e)]);
		}
		NuDashboardApiPage::out(['ok' => true, 'data' => $data, 'title' => NuHash::replace((string)$w['title'], $hash, NuHash::RAW)]);   // #cus_name# in a title
	}
	NuDashboardApiPage::out(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
	error_log('nuBuilder Next dashboard api: ' . $e->getMessage());
	NuDashboardApiPage::out(['ok' => false, 'error' => $errMsg($e)], 500);
}
