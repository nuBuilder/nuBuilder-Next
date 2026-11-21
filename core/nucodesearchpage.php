<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nucodesearch.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuCodesearchPage
{
	/** The POST actions of pages/nucodesearch.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$selfQ = $nuIn['selfQ'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save') {
				parse_str(nu_post('search'), $sq);
				$err = NuCodeSearch::save(nu_post('name'), NuCodeSearch::request($sq));
				nu_flash($err === '' ? 'success' : 'danger', $err === '' ? t('cs_saved_ok') : $err);
				nu_redirect(nu_url('codesearch', $sq));
			}
			if ($action === 'delete_saved') NuCodeSearch::delete(nu_post('id'));
			// search and replace: the globeadmin, password confirmed within the last minutes
			if ($action === 'replace' || $action === 'undo') {
				parse_str(nu_post('search'), $sq);
				$back = nu_url('codesearch', $sq ?: $selfQ);
				if (!NuCodeSearch::canReplace()) { http_response_code(403); exit; }
				if (!NuSecurity::recentAuth()) NuSecurity::requireRecentAuth($back);
				if ($action === 'undo') {
					$r = NuCodeSearch::undo(nu_post('id'));
					nu_flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? t('cs_rp_undone', $r['done'], $r['skipped']) : $r['error']);
					nu_redirect($back);
				}
				$ticks = is_array($_POST['t'] ?? null) ? $_POST['t'] : [];
				$hashes = is_array($_POST['h'] ?? null) ? array_map('strval', $_POST['h']) : [];
				$r = NuCodeSearch::apply(NuCodeSearch::request($sq), (string)($_POST['rp'] ?? ''), $ticks, $hashes);
				if ($r['error'] !== '') nu_flash('danger', $r['error']);
				elseif ($r['parts']) nu_flash('success', t('cs_rp_done', $r['lines'], $r['parts']));
				if ($r['skipped']) nu_flash('warning', t('cs_rp_skipped') . ' ' . implode(' · ', array_slice($r['skipped'], 0, 20)));
				unset($sq['rmode']);
				nu_redirect(nu_url('codesearch', $sq));
			}
			nu_redirect(nu_url('codesearch', $selfQ));
		}
		return compact('selfQ');
	}
}
