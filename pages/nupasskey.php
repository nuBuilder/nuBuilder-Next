<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Passkeys (0.8.877, core/nupasskey.php) – JSON answers for assets/js/nupasskey.js. POST action (+ _csrf, cred = the browser's
 * PublicKeyCredential as JSON):
 *   reg_options / reg        signed in: add a passkey (password confirmed recently, not in demo mode or while acting as a user)
 *   twofa_options / twofa    the pending second factor of a sign-in (pages/nutwofa.php)
 *   login_options / login    signing in without a password (Security setting passkey_login)
 *   reauth_options / reauth  password confirmation before dangerous actions (pages/nureauth.php)
 * Answers {ok, options} / {ok, go: <next page>} / {ok: false, message, reauth?}.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = static function (array $a, int $code = 200): never {
	http_response_code($code);
	echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
};
if (!nu_is_post()) $out(['ok' => false, 'message' => 'POST'], 405);
$action = nu_post('action');
if (!NuPasskey::usable()) $out(['ok' => false, 'message' => t(NuPasskey::problem())]);
$cred = json_decode((string)($_POST['cred'] ?? ''), true);
$cred = is_array($cred) ? $cred : [];
$u = NuAuth::user();
$msg = static fn(string $k): string => str_starts_with($k, 'login_') || str_starts_with($k, 'pk_') || str_starts_with($k, 'twofa_') ? t($k) : $k;

switch ($action) {
	case 'reg_options':
	case 'reg':
		if (!$u) $out(['ok' => false, 'message' => t('login_session_timeout'), 'go' => NuAuth::loginUrl(nu_url('security'))], 401);
		if (!empty($u['demo']) || ($u['via'] ?? '') === 'demo_ga' || NuImpersonate::active()) $out(['ok' => false, 'message' => t('demo_not_available')], 403);
		if (!NuSecurity::recentAuth()) {
			$_SESSION['nu_reauth_next'] = nu_url('security');
			$out(['ok' => false, 'message' => t('reauth_needed'), 'go' => nu_url('reauth')]);
		}
		if ($action === 'reg_options') $out(['ok' => true, 'options' => NuPasskey::registrationOptions($u)]);
		$r = NuPasskey::register($u, $cred, nu_post('name'));
		if ($r['ok']) nu_flash('success', t('pk_added'));
		$out($r['ok'] ? ['ok' => true, 'go' => nu_url('security')] : ['ok' => false, 'message' => t($r['error'])]);

	case 'twofa_options':
	case 'twofa':
		$p = NuAuth::pending();
		if (!$p) $out(['ok' => false, 'message' => t('twofa_expired'), 'go' => nu_url('login')]);
		$uid = (string)$p['auth']['id'];
		if (NuPasskey::count($uid) === 0) $out(['ok' => false, 'message' => t('pk_err_none')]);
		if ($action === 'twofa_options') $out(['ok' => true, 'options' => NuPasskey::assertionOptions('2fa', $uid)]);
		$row = NuPasskey::verify('2fa', $cred, $err);
		if (!$row || (string)$row['user_id'] !== $uid) {
			$p['fails'] = (int)$p['fails'] + 1;
			NuLog::write('2fa_failed', '', '', ['method' => 'passkey'], $p['auth']);
			if ($p['fails'] >= NuTwoFA::MAX_FAILS) {
				unset($_SESSION['nu_2fa']);
				nu_flash('danger', t('twofa_too_many'));
				$out(['ok' => false, 'message' => t('twofa_too_many'), 'go' => nu_url('login')]);
			}
			NuAuth::updatePending($p);
			$out(['ok' => false, 'message' => t($err ?? 'pk_err_check')]);
		}
		$refused = NuAuth::completePending('passkey', nu_check('trust') === '1');
		if ($refused !== null) { nu_flash('danger', $refused); $out(['ok' => false, 'message' => $refused, 'go' => nu_url('login')]); }
		$out(['ok' => true, 'go' => NuAuth::takeNext()]);

	case 'login_options':
	case 'login':
		if (!NuPasskey::loginReady()) $out(['ok' => false, 'message' => t('pk_login_off')]);
		if ($u) $out(['ok' => true, 'go' => NuAuth::takeNext()]);
		if ($action === 'login_options') $out(['ok' => true, 'options' => NuPasskey::assertionOptions('login', null)]);
		$row = NuPasskey::verify('login', $cred, $err);
		if (!$row) $out(['ok' => false, 'message' => t($err ?? 'pk_err_check')]);
		$res = NuAuth::loginPasskey((string)$row['user_id']);
		if ($res !== null) $out(['ok' => false, 'message' => $msg($res)]);
		$out(['ok' => true, 'go' => NuAuth::takeNext()]);

	case 'reauth_options':
	case 'reauth':
		if (!$u) $out(['ok' => false, 'message' => t('login_session_timeout'), 'go' => NuAuth::loginUrl(null)], 401);
		if (NuImpersonate::active()) $out(['ok' => false, 'message' => t('pk_err_unknown')], 403);   // the acting globeadmin confirms with his own password
		$uid = (string)$u['id'];
		if (NuPasskey::count($uid) === 0) $out(['ok' => false, 'message' => t('pk_err_none')]);
		if ($action === 'reauth_options') $out(['ok' => true, 'options' => NuPasskey::assertionOptions('reauth', $uid)]);
		$row = NuPasskey::verify('reauth', $cred, $err);
		if (!$row || (string)$row['user_id'] !== $uid) {
			NuLog::write('reauth_failed', '', '', ['via' => 'passkey']);
			$out(['ok' => false, 'message' => t($err ?? 'pk_err_check')]);
		}
		NuSecurity::confirmed();
		$next = (string)($_SESSION['nu_reauth_next'] ?? nu_url('home'));
		unset($_SESSION['nu_reauth_next']);
		NuLog::write('reauth_ok', '', '', ['via' => 'passkey']);
		$out(['ok' => true, 'go' => $next]);
}
$out(['ok' => false, 'message' => 'action'], 400);
