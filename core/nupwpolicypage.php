<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nupwpolicy.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuPwpolicyPage
{
	/** The POST actions of pages/nupwpolicy.php (part 1) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post1(array $nuIn): array
	{
		if (nu_is_post() && nu_post('action') === 'test') {          // JSON: the policy's verdict on a typed password (never stored or logged)
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			$v = NuPwPolicy::violations((string)($_POST['pw'] ?? ''), ['login' => (string)($_POST['login'] ?? '')]);
			echo json_encode(['ok' => !$v, 'message' => $v ? NuPwPolicy::message($v) : t('pwp_test_ok')], JSON_UNESCAPED_UNICODE);
			exit;
		}
		return [];
	}

	/** The POST actions of pages/nupwpolicy.php (part 2) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post2(array $nuIn): array
	{
		$id = $nuIn['id'] ?? null;
		if (nu_is_post() && nu_post('action') === 'save') {
			NuAdmin::require('securitycheck', 'change');
			$lv = is_array($_POST['lv'] ?? null) ? $_POST['lv'] : [];
			$levels = [];
			foreach ($lv as $id => $d) if (is_string($id) && $d !== '' && $d !== null) $levels[$id] = (int)$d;
			$changed = NuPwPolicy::save(['min_len' => (int)nu_post('min_len'), 'upper' => nu_check('upper') === '1', 'lower' => nu_check('lower') === '1',
				'digit' => nu_check('digit') === '1', 'symbol' => nu_check('symbol') === '1', 'not_name' => nu_check('not_name') === '1', 'leak' => nu_post('leak'),
				'leak_login' => nu_check('leak_login') === '1', 'expire' => (int)nu_post('expire'), 'warn' => (int)nu_post('warn'), 'ga_expire' => (int)nu_post('ga_expire'),
				'levels' => $levels]);
			nu_flash('success', $changed ? t('saved') : t('aud_set_nochange'));
			nu_redirect(nu_url('pwpolicy'));
		}
		return compact('id');
	}
}
