<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nurecyclebin.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuRecyclebinPage
{
	/** The POST actions of pages/nurecyclebin.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$json = $nuIn['json'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			$id = nu_post('id');
			if ($action === 'restore') {
				$res = NuRecycle::restore($id);
				if ($json) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($res, JSON_UNESCAPED_UNICODE); exit; }
				if ($res['ok']) $_SESSION[NuSess::FLASH][] = ['success', $res['message'], false, [(string)$res['url'], t('rb_open')]];
				else nu_flash('danger', $res['message']);
				nu_redirect($res['ok'] ? nu_url('recyclebin') : nu_url('recyclebin', ['id' => $id]));
			}
			if ($action === 'remove') {
				$n = NuRecycle::remove([$id]);
				nu_flash($n ? 'success' : 'danger', $n ? t('rb_removed', $n) : t('err_forbidden'));
				nu_redirect(nu_url('recyclebin'));
			}
			if ($action === 'empty') {
				$form = nu_post('form');
				$ids = [];
				foreach (nu_db()->all('SELECT zzzzsys_nunext_recycle_id AS i FROM ' . NuRecycle::TABLE . ' WHERE form_id = ?', [$form]) as $r) $ids[] = (string)$r['i'];
				$n = NuRecycle::remove($ids);
				nu_flash('success', t('rb_removed', $n));
				nu_redirect(nu_url('recyclebin'));
			}
			nu_redirect(nu_url('recyclebin'));
		}
		return [];
	}
}
