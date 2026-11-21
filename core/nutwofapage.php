<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nutwofa.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuTwofaPage
{
	/** The POST actions of pages/nutwofa.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$canMail = $nuIn['canMail'] ?? null;
		$p = $nuIn['p'] ?? null;
		$hasPk = $nuIn['hasPk'] ?? null;
		$hasTotp = $nuIn['hasTotp'] ?? null;
		$userId = $nuIn['userId'] ?? null;
		$action = $nuIn['action'] ?? null;
		$error = $nuIn['error'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');

			if ($action === 'cancel') {
				unset($_SESSION[NuSess::TWOFA]);
				nu_redirect(nu_url('login'));
			}

			if ($action === 'use_mail' && $canMail) {
				$p['method'] = 'mail';
				$err = NuTwoFA::sendMailCode($p);
				NuAuth::updatePending($p);
				if ($err !== null && $err !== 'twofa_err_wait') nu_flash('danger', t($err));
				elseif ($err === null) nu_flash('success', t('twofa_mail_sent', NuTwoFA::maskEmail((string)$p['email'])));
				nu_redirect(nu_url('twofa'));
			}

			if ($action === 'use_passkey' && $hasPk) {
				$p['method'] = 'passkey';
				NuAuth::updatePending($p);
				nu_redirect(nu_url('twofa'));
			}

			if ($action === 'use_totp' && $hasTotp) {
				$p['method'] = 'totp';
				NuAuth::updatePending($p);
				nu_redirect(nu_url('twofa'));
			}

			if ($action === 'resend' && $p['method'] === 'mail') {
				$err = NuTwoFA::sendMailCode($p);
				NuAuth::updatePending($p);
				nu_flash($err === null ? 'success' : 'warning', $err === null ? t('twofa_mail_sent', NuTwoFA::maskEmail((string)$p['email'])) : t($err, NuTwoFA::RESEND_AFTER));
				nu_redirect(nu_url('twofa'));
			}

			if ($action === 'verify') {
				$code  = nu_post('code');
				$trust = nu_check('trust') === '1';
				$ok    = false;
				if ($p['method'] === 'totp') {
					$ok = NuTwoFA::verifyTotp($userId, $code);
				} elseif ($p['method'] === 'mail') {
					$ok = NuTwoFA::verifyMailCode($userId, $code);
				} elseif ($p['method'] === 'enroll') {
					$step = NuTwoFA::matchCode((string)$p['secret'], $code);
					if ($step !== null) {
						NuTwoFA::saveTotp($userId, (string)$p['secret'], $step);
						NuLog::write('2fa_app_enabled', '', '', [], $p['auth']);
						$ok = true;
					}
				}

				if ($ok) {
					$refused = NuAuth::completePending($p['method'] === 'enroll' ? 'totp' : $p['method'], $trust);
					if ($refused !== null) { nu_flash('danger', $refused); nu_redirect(nu_url('login')); }   // nu_startup said no
					nu_redirect(NuAuth::takeNext());
				}

				$p['fails'] = (int)$p['fails'] + 1;
				NuLog::write('2fa_failed', '', '', ['method' => $p['method']], $p['auth']);
				if ($p['fails'] >= NuTwoFA::MAX_FAILS) {
					unset($_SESSION[NuSess::TWOFA]);
					nu_flash('danger', t('twofa_too_many'));
					nu_redirect(nu_url('login'));
				}
				NuAuth::updatePending($p);
				$error = t('twofa_err_code');
			}
		}
		return compact('action', 'canMail', 'p', 'hasPk', 'hasTotp', 'error');
	}
}
