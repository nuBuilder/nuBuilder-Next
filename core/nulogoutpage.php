<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nulogout.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuLogoutPage
{
	/** The POST actions of pages/nulogout.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		if (nu_is_post()) {
			if (NuAuth::user()) NuLog::write('logout');
			NuAuth::logout();
			nu_flash('success', t('logout_done'));
		}
		return [];
	}
}
