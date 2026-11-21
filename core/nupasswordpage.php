<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nupassword.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuPasswordPage
{
	/** The POST actions of pages/nupassword.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$errors = $nuIn['errors'] ?? null; $min = $nuIn['min'] ?? null;
		if (nu_is_post()) {
			$current = (string)($_POST['current'] ?? '');
			$new     = (string)($_POST['new'] ?? '');
			$confirm = (string)($_POST['confirm'] ?? '');

			if ($current === '')                $errors['current'] = t('err_required');
			if (mb_strlen($new) < $min)         $errors['new']     = t('pw_err_min', $min);
			elseif (strlen($new) > 72)          $errors['new']     = t('err_too_long', 72);
			if ($new !== $confirm)              $errors['confirm'] = t('pw_err_confirm');

			if (!$errors) {
				$err = NuAuth::changeOwnPassword($current, $new);
				if ($err === 'pw_err_current') $errors['current'] = t($err);
				elseif ($err)                  $errors['new']     = t($err);
				else {
					unset($_SESSION[NuSess::PW_REASON]);
					nu_flash('success', t('pw_changed'));
					nu_redirect(nu_url('home'));
				}
			}
		}
		return compact('errors');
	}
}
