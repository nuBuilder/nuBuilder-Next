<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nusecuritycheck.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSecuritycheckPage
{
	/** The POST actions of pages/nusecuritycheck.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$c = $nuIn['c'] ?? null;
		if (nu_is_post() && nu_post('action') === 'clear_tokens') {
			NuSecurity::requireRecentAuth(nu_url('securitycheck'));
			$n = NuLoginToken::clearAll();
			nu_flash('success', t('secchk_logintoken_cleared', $n));
			nu_redirect(nu_url('securitycheck') . '#sec_logintoken');
		}

		// secrets stored as plain text: encrypt them with $nuConfigSecretKey
		if (nu_is_post() && nu_post('action') === 'seal_secrets') {
			NuSecurity::requireRecentAuth(nu_url('securitycheck'));
			$n = NuSecret::sealPlain();
			NuLog::write('secrets_sealed', 'zzzzsys_nunext_setting', '', ['count' => $n]);
			nu_flash('success', t('secchk_secrets_sealed', $n));
			nu_redirect(nu_url('securitycheck') . '#sec_secrets');
		}

		if (nu_is_post() && nu_post('action') === 'save') {
			NuSecurity::requireRecentAuth(nu_url('securitycheck'));
			$in = [];
			foreach (NuSecurity::DEFAULTS as $k => $d) $in[$k] = is_bool($d) ? nu_check($k) === '1' : (int)nu_post($k);
			$changed = NuSecurity::save($in);
			if ($changed) NuLog::write('security_saved', 'zzzzsys_nunext_setting', NuSecurity::SETTING, ['fields' => array_map(static fn($c) => [json_encode($c[0]), json_encode($c[1])], $changed)]);
			nu_flash('success', $changed ? t('secchk_saved') : t('set_saved_none'));
			nu_redirect(nu_url('securitycheck', ['tab' => 'settings']) . '#settings');
		}
		return compact('c');
	}
}
