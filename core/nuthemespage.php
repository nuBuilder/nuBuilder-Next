<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuthemes.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuThemesPage
{
	/** The POST actions of pages/nuthemes.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$e = $nuIn['e'] ?? null;
		if (nu_is_post() && nu_post('action') === 'pick') {
			try { NuTheme::pick(nu_post('theme')); } catch (Throwable $e) { nu_flash('danger', $e->getMessage()); }
			$back = NuAuth::validNext(nu_post('back')) ?? nu_url('home');
			nu_redirect($back);
		}
		return compact('e');
	}
}
