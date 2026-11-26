<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuhashfix.php: its POST actions (put the ticked hash variables in quotes, undo a run). */
final class NuHashfixPage
{
	/** The POST actions of pages/nuhashfix.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		if (nu_is_post()) {
			$back = nu_url('hashfix');
			if (!NuCodeSearch::canReplace()) { http_response_code(403); exit; }
			if (!NuSecurity::recentAuth()) NuSecurity::requireRecentAuth($back);
			$action = nu_post('action');
			if ($action === 'undo') {
				$r = NuCodeSearch::undo(nu_post('id'));
				nu_flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? t('cs_rp_undone', $r['done'], $r['skipped']) : $r['error']);
				nu_redirect($back);
			}
			if ($action === 'fix') {
				$ticks = is_array($_POST['t'] ?? null) ? $_POST['t'] : [];
				$hashes = is_array($_POST['h'] ?? null) ? array_map('strval', $_POST['h']) : [];
				$r = NuHashFix::fix($ticks, $hashes);
				if ($r['error'] !== '') nu_flash($r['ok'] ? 'info' : 'danger', $r['error']);
				elseif ($r['parts']) nu_flash('success', t('hqf_done', $r['spots'], $r['parts']));
				if ($r['skipped']) nu_flash('warning', t('cs_rp_skipped') . ' ' . implode(' · ', array_slice($r['skipped'], 0, 20)));
			}
			nu_redirect($back);
		}
		return $nuIn;
	}
}
