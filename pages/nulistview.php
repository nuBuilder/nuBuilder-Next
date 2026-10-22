<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * List views (0.8.329) – actions of the "View" menu and the editor (core/nulistview.php, assets/js/nulistview.js).
 *   POST action save (JSON answer) | delete (JSON) | my_default | list_default | share | import (JSON)
 *   GET  action=export&list=…  → JSON download of the shared views
 * Every action needs the view right on the list's form; shared views, the list default, share / export / import: NuListView::canManage().
 */

$json = static fn(array $a) => exit(json_encode($a, JSON_UNESCAPED_UNICODE));
$action = nu_is_post() ? nu_post('action') : (string)($_GET['action'] ?? '');
$list = nu_is_post() ? nu_post('list') : (string)($_GET['list'] ?? '');
try {
	$L = NuListView::def($list);
} catch (Throwable) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}
if (empty($L['public'])) NuAcl::require($L['form']);
$back = static function () use ($L): string {
	$b = nu_post('back');
	return str_starts_with($b, 'index.php?p=' . $L['route']) ? $b : NuListView::url($L);
};

if (!nu_is_post()) {
	if ($action === 'export') {
		if (!NuListView::canManage()) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
		$data = NuListView::export($L);
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="list-views-' . $L['key'] . '-' . date('Ymd') . '.json"');
		header('Cache-Control: no-store');
		exit(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
	nu_redirect(NuListView::url($L));
}

$id = nu_post('id');
if (in_array($action, ['save', 'delete', 'import'], true)) {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	try {
		switch ($action) {
			case 'save':
				$in = json_decode((string)($_POST['view'] ?? ''), true);
				if (!is_array($in)) throw new RuntimeException(t('err_invalid'));
				$saved = NuListView::save($L, $id !== '' && !in_array($id, NuListView::BUILTIN, true) ? $id : null, $in);
				$json(['ok' => true, 'id' => $saved, 'url' => NuListView::url($L, [NuListView::viewParam($L) => $saved])]);
			case 'delete':
				NuListView::delete($L, $id);
				$json(['ok' => true, 'url' => NuListView::url($L)]);
			case 'import':
				$f = $_FILES['file'] ?? null;
				if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']) || (int)$f['size'] > 1048576) throw new RuntimeException(t('lv_err_import'));
				$data = json_decode((string)file_get_contents((string)$f['tmp_name']), true);
				if (!is_array($data)) throw new RuntimeException(t('lv_err_import'));
				$n = NuListView::import($L, $data);
				nu_flash('success', t('lv_imported', $n));
				$json(['ok' => true, 'url' => NuListView::url($L)]);
		}
	} catch (Throwable $e) {
		if (!$e instanceof RuntimeException) error_log('nuBuilder Next list views: ' . $e->getMessage());
		$json(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : t('lv_err_general')]);
	}
}

try {
	$views = NuListView::views($L);
	switch ($action) {
		case 'my_default':
			if (nu_post('clear') === '1') { NuListView::setOwnDefault($L, ''); nu_flash('success', t('lv_my_default_cleared')); break; }
			if (!isset($views[$id])) throw new RuntimeException(t('err_not_found'));
			NuListView::setOwnDefault($L, $id);
			nu_flash('success', t('lv_my_default_set', $views[$id]['name']));
			break;
		case 'list_default':
			if (!isset($views[$id]) || in_array($views[$id]['group'], ['mine', 'others'], true)) throw new RuntimeException(t('err_not_found'));
			NuListView::setListDefault($L, $id);
			nu_flash('success', t('lv_list_default_set', $views[$id]['name']));
			break;
		case 'share':
			$new = NuListView::share($L, $id);
			nu_flash('success', t('lv_shared'));
			nu_redirect(NuListView::url($L, [NuListView::viewParam($L) => $new]));
	}
} catch (RuntimeException $e) {
	nu_flash('danger', $e->getMessage());
}
nu_redirect($back());
