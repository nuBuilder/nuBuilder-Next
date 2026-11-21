<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nureauth.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuReauthPage
{
	/** The POST actions of pages/nureauth.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$u = $nuIn['u'] ?? null; $next = $nuIn['next'] ?? null; $error = $nuIn['error'] ?? null;
		if (nu_is_post() && nu_post('action') === 'confirm') {
			$login = (string)$u['login'];
			if (NuSecurity::loginBlocked($login)) {
				NuLog::write('session_ended', '', '', ['reason' => 'password confirmation locked']);
				NuAuth::logout();
				nu_flash('danger', t('login_err_locked'));
				nu_redirect(nu_url('login'));
			}
			if (NuAuth::checkOwnPassword((string)($_POST['password'] ?? ''))) {
				NuSecurity::confirmed();
				unset($_SESSION[NuSess::REAUTH_NEXT]);
				NuLog::write('reauth_ok');
				nu_redirect($next);
			}
			usleep(random_int(250_000, 600_000));
			NuLog::write('reauth_failed');                         // counts for the login rate limit (NuSecurity::loginBlocked)
			$error = t('reauth_wrong');
		}
		return compact('u', 'error');
	}
}
