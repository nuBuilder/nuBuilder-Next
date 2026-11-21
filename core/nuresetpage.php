<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nureset.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuResetPage
{
	/** The POST actions of pages/nureset.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$min = $nuIn['min'] ?? null; $errors = $nuIn['errors'] ?? null; $uid = $nuIn['uid'] ?? null; $row = $nuIn['row'] ?? null; $user = $nuIn['user'] ?? null;
		if (nu_is_post()) {
			$new     = (string)($_POST['new'] ?? '');
			$confirm = (string)($_POST['confirm'] ?? '');
			if (mb_strlen($new) < $min)  $errors['new'] = t('pw_err_min', $min);
			elseif (strlen($new) > 72)   $errors['new'] = t('err_too_long', 72);
			if ($new !== $confirm)       $errors['confirm'] = t('pw_err_confirm');
			if (!$errors && ($policy = NuAuth::passwordPolicy($new, $uid)) !== null) $errors['new'] = $policy;   // nu_check_password_policy

			if (!$errors) {
				nu_db()->update('zzzzsys_user', ['sus_login_password' => NuAuth::hash($new), 'sus_change_password' => '0'], 'zzzzsys_user_id', $uid);
				NuToken::deleteFor('pw_reset', $uid);
				NuUserJson::passwordChanged($uid, 'reset');
				NuLog::write('password_reset', 'zzzzsys_user', $uid, ['via' => (string)($row['data'] ?? '') ?: 'welcome'], ['id' => $uid, 'login' => (string)$user['sus_login_name']]);
				NuPwReset::endSessions($uid);                       // the new hash ends them anyway – their rows go too
				if (in_array((string)($row['data'] ?? ''), ['forgot', NuPwReset::DATA], true)) {
					$full = nu_db()->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$uid]);
					if ($full) NuPwReset::confirm($full);
				}
				nu_flash('success', t('reset_done'));
				nu_redirect(nu_url('login'));
			}
		}
		return compact('errors', 'uid', 'user');
	}
}
