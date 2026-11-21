<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nudashboards.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuDashboardsPage
{
	/** The POST actions of pages/nudashboards.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$errors = $nuIn['errors'] ?? null; $d = $nuIn['d'] ?? null; $id = $nuIn['id'] ?? null; $w = $nuIn['w'] ?? null; $wid = $nuIn['wid'] ?? null; $x = $nuIn['x'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save') {
				[$newId, $errors] = NuDashboard::save(['code' => nu_post('code'), 'title' => nu_post('title'), 'description' => nu_post('dash_description'),
					'on_home' => nu_check('on_home') === '1', 'access' => (array)($_POST['access'] ?? []), 'date_range' => nu_post('date_range'), 'sort_order' => nu_post('sort_order')], $d ? $id : '');
				if (!$errors) { nu_flash('success', t('dash_saved')); nu_redirect(nu_url('dashboards', ['id' => $newId, 'tab' => nu_post('tab') === 'access' ? 'access' : null])); }
			} elseif ($action === 'copy' && $d) {
				$new = NuDashboard::copy($id);
				nu_flash('success', t('dash_copied'));
				nu_redirect(nu_url('dashboards', ['id' => $new]));
			} elseif ($action === 'delete' && $d) {
				NuDashboard::delete($id);
				nu_flash('success', t('dash_deleted'));
				nu_redirect(nu_url('dashboards'));
			} elseif ($action === 'wsave' && $d) {
				$in = ['type' => nu_post('type'), 'title' => nu_post('title'), 'source' => nu_post('source'), 'sql' => (string)($_POST['sql'] ?? ''),
					'select_id' => nu_post('select_id'), 'php_id' => nu_post('php_id'), 'opt' => is_array($_POST['opt'] ?? null) ? $_POST['opt'] : []];
				[$newW, $errors] = NuDashboard::saveWidget($id, $in, $w ? $wid : '');
				if (!$errors) { nu_flash('success', t('dash_w_saved')); nu_redirect(nu_url('dashboards', ['id' => $id])); }
			} elseif ($action === 'wdelete' && $d) {
				NuDashboard::deleteWidget($id, nu_post('widget'));
				nu_flash('success', t('dash_w_deleted'));
				nu_redirect(nu_url('dashboards', ['id' => $id]));
			} elseif ($action === 'wmove' && $d) {
				$ids = array_map(static fn($x) => (string)$x['zzzzsys_nunext_widget_id'], NuDashboard::widgets($id));
				$i = array_search(nu_post('widget'), $ids, true);
				$j = $i === false ? false : (nu_post('dir') === 'up' ? $i - 1 : $i + 1);
				if ($i !== false && $j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; NuDashboard::reorder($id, $ids); }
				nu_redirect(nu_url('dashboards', ['id' => $id]) . '#dashWidgets');
			}
		}
		return compact('errors', 'd', 'id', 'w', 'wid', 'x');
	}
}
