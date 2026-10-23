<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* Second login step: authenticator code, e-mail code, or first-time authenticator setup. */

if (NuAuth::user()) nu_redirect(nu_url('home'));

$p = NuAuth::pending();
if (!$p) {
	nu_flash('warning', t('twofa_expired'));
	nu_redirect(nu_url('login'));
}

$userId  = (string)$p['auth']['id'];
$hasTotp = NuTwoFA::hasTotp($userId);
$canMail = trim((string)$p['email']) !== '' && NuMail::configured();
$error   = null;

// choose the method once: authenticator if set up, else e-mail code, else set up an authenticator now
if (empty($p['method'])) {
	$p['method'] = $hasTotp ? 'totp' : ($canMail ? 'mail' : 'enroll');
	if ($p['method'] === 'mail') {
		$err = NuTwoFA::sendMailCode($p);
		if ($err !== null) { $p['method'] = 'enroll'; nu_flash('warning', t($err)); }
	}
	NuAuth::updatePending($p);
}
if ($p['method'] === 'enroll' && empty($p['secret'])) {
	$p['secret'] = NuTwoFA::newSecret();
	NuAuth::updatePending($p);
}

if (nu_is_post()) {
	$action = nu_post('action');

	if ($action === 'cancel') {
		unset($_SESSION['nu_2fa']);
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
			NuAuth::completePending($p['method'] === 'enroll' ? 'totp' : $p['method'], $trust);
			nu_redirect(NuAuth::takeNext());
		}

		$p['fails'] = (int)$p['fails'] + 1;
		NuLog::write('2fa_failed', '', '', ['method' => $p['method']], $p['auth']);
		if ($p['fails'] >= NuTwoFA::MAX_FAILS) {
			unset($_SESSION['nu_2fa']);
			nu_flash('danger', t('twofa_too_many'));
			nu_redirect(nu_url('login'));
		}
		NuAuth::updatePending($p);
		$error = t('twofa_err_code');
	}
}

$method = $p['method'];
$foot   = $method === 'enroll'
	? '<script src="' . nu_asset(NU_QRCODE . '/qrcode.js') . '"></script><script src="' . nu_asset('assets/js/nuqr.js') . '"></script>'
	: '';

nu_center_start(t('twofa_title'), t('twofa_subtitle_' . $method), 'bi-shield-lock');
?>
<?php if ($error): ?>
	<div class="alert alert-danger d-flex gap-2 py-2" role="alert"><i class="bi bi-exclamation-octagon mt-1"></i><div><?= h($error) ?></div></div>
<?php endif; ?>

<?php if ($method === 'mail'): ?>
	<p class="small text-body-secondary text-center"><?= h(t('twofa_mail_sent', NuTwoFA::maskEmail((string)$p['email']))) ?></p>
<?php elseif ($method === 'enroll'): ?>
	<ol class="small ps-3 mb-3">
		<li><?= h(t('twofa_enroll_step1')) ?></li>
		<li><?= h(t('twofa_enroll_step2')) ?></li>
	</ol>
	<div class="nuQr mx-auto mb-2" data-qr="<?= h(NuTwoFA::otpauthUri((string)$p['secret'], (string)$p['auth']['login'])) ?>" role="img" aria-label="<?= h(t('twofa_qr')) ?>"></div>
	<p class="small text-center text-body-secondary mb-1"><?= h(t('twofa_manual')) ?></p>
	<p class="text-center"><code class="nuSecret user-select-all"><?= h(trim(chunk_split((string)$p['secret'], 4, ' '))) ?></code></p>
<?php endif; ?>

<form method="post" action="<?= h(nu_url('twofa')) ?>" novalidate>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="verify">
	<div class="form-floating mb-3">
		<input type="text" class="form-control nuOtp" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
			   autocomplete="one-time-code" placeholder="123456" required autofocus>
		<label for="code"><?= h(t('twofa_code')) ?></label>
	</div>
	<?php if (NuTwoFA::trustDays() > 0): ?>
		<div class="form-check mb-3">
			<input class="form-check-input" type="checkbox" id="trust" name="trust" value="1">
			<label class="form-check-label small" for="trust"><?= h(t('twofa_trust', NuTwoFA::trustDays())) ?></label>
		</div>
	<?php endif; ?>
	<button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-check-lg me-2"></i><?= h(t('twofa_verify')) ?></button>
</form>

<div class="d-flex flex-wrap justify-content-center gap-2 mt-3 small">
	<?php if ($method === 'mail'): ?>
		<form method="post" action="<?= h(nu_url('twofa')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="resend">
			<button class="btn btn-link btn-sm p-0" type="submit"><?= h(t('twofa_resend')) ?></button></form>
		<?php if ($hasTotp): ?><span class="text-body-tertiary">·</span>
		<form method="post" action="<?= h(nu_url('twofa')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="use_totp">
			<button class="btn btn-link btn-sm p-0" type="submit"><?= h(t('twofa_use_app')) ?></button></form>
		<?php endif; ?>
	<?php elseif ($canMail): ?>
		<form method="post" action="<?= h(nu_url('twofa')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="use_mail">
			<button class="btn btn-link btn-sm p-0" type="submit"><?= h(t('twofa_use_mail')) ?></button></form>
	<?php endif; ?>
	<span class="text-body-tertiary">·</span>
	<form method="post" action="<?= h(nu_url('twofa')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="cancel">
		<button class="btn btn-link btn-sm p-0 text-body-secondary" type="submit"><?= h(t('cancel')) ?></button></form>
</div>
<?php
nu_center_end($foot);
