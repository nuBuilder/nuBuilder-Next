<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nugettingready.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuGettingreadyPage
{
	/** The POST actions of pages/nugettingready.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$groups = $nuIn['groups'] ?? null; $mode = $nuIn['mode'] ?? null;
		if (nu_is_post()) {
			$act = (string)nu_post('action');
			if ($act === 'mark') {
				NuReady::mark((string)nu_post('id'), (string)nu_post('mark'));
				$gb = (string)nu_post('g');
				nu_redirect(nu_url('gettingready', ['g' => isset($groups[$gb]) ? $gb : null]) . '#gr_' . preg_replace('~[^a-z0-9]~', '', (string)nu_post('id')));
			}
			if ($act === 'hide') {
				$mode = (string)nu_post('mode');
				NuReady::hide(in_array($mode, ['session', 'later', 'forever'], true) ? $mode : '');
				if ($mode !== '') nu_flash('info', t('gr_hidden_msg'));
				$back = NuAuth::validNext((string)nu_post('back'));
				nu_redirect($back !== null ? $back : nu_url('gettingready'));
			}
			nu_redirect(nu_url('gettingready'));
		}
		return compact('mode');
	}
}
