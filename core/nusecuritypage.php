<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nusecurity.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSecurityPage
{
	/** The POST actions of pages/nusecurity.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$errors = $nuIn['errors'] ?? null; $uid = $nuIn['uid'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');

			if ($action === 'totp_start') {
				$_SESSION[NuSess::TOTP_SETUP] = NuTwoFA::newSecret();
				nu_redirect(nu_url('security'));
			}
			if ($action === 'totp_cancel') {
				unset($_SESSION[NuSess::TOTP_SETUP]);
				nu_redirect(nu_url('security'));
			}
			if ($action === 'totp_confirm' && !empty($_SESSION[NuSess::TOTP_SETUP])) {
				$step = NuTwoFA::matchCode((string)$_SESSION[NuSess::TOTP_SETUP], nu_post('code'));
				if ($step === null) {
					$errors['code'] = t('twofa_err_code');
				} else {
					NuTwoFA::saveTotp($uid, (string)$_SESSION[NuSess::TOTP_SETUP], $step);
					unset($_SESSION[NuSess::TOTP_SETUP]);
					NuLog::write('2fa_app_enabled');
					nu_flash('success', t('sec_totp_enabled'));
					nu_redirect(nu_url('security'));
				}
			}
			if ($action === 'totp_remove') {
				if (!NuTwoFA::verifyTotp($uid, nu_post('code'))) {
					$errors['remove_code'] = t('twofa_err_code');
				} else {
					NuTwoFA::removeTotp($uid);
					NuLog::write('2fa_app_removed');
					nu_flash('success', t('sec_totp_removed'));
					nu_redirect(nu_url('security'));
				}
			}
			if ($action === 'pk_remove') {
				NuSecurity::requireRecentAuth(nu_url('security'));
				$pkId = nu_post('pk');
				$pkName = (string)(array_column(NuPasskey::list($uid), 'name', 'zzzzsys_nunext_passkey_id')[$pkId] ?? '');
				if (NuPasskey::remove($uid, $pkId)) {
					NuLog::write('passkey_removed', NuPasskey::TABLE, $pkId, ['name' => $pkName]);
					nu_flash('success', t('pk_removed', $pkName));
				}
				nu_redirect(nu_url('security') . '#secPasskeys');
			}
			if ($action === 'devices_forget') {
				NuTwoFA::forgetDevices($uid);
				NuLog::write('2fa_devices_forgotten');
				nu_flash('success', t('sec_devices_forgotten'));
				nu_redirect(nu_url('security'));
			}
		}
		return compact('errors', 'uid');
	}
}
