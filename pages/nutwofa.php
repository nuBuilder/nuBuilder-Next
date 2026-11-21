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
$hasPk   = NuPasskey::has($userId);                        // passkeys: offered first when the user has one
$canMail = trim((string)$p['email']) !== '' && NuMail::configured();
$error   = null;

// choose the method once: authenticator if set up, else e-mail code, else set up an authenticator now
if (empty($p['method'])) {
	$p['method'] = $hasPk ? 'passkey' : ($hasTotp ? 'totp' : ($canMail ? 'mail' : 'enroll'));
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

['action' => $action, 'canMail' => $canMail, 'p' => $p, 'hasPk' => $hasPk, 'hasTotp' => $hasTotp, 'error' => $error]
	= NuTwofaPage::post(get_defined_vars());   // the POST actions (code review R7, nutwofapage.php)

$method = $p['method'];
if ($method === 'passkey' && !$hasPk) $method = $hasTotp ? 'totp' : ($canMail ? 'mail' : 'enroll');   // passkeys switched off meanwhile
$foot   = $method === 'enroll'
	? '<script src="' . nu_asset(NU_QRCODE . '/qrcode.js') . '"></script><script src="' . nu_asset('assets/js/nuqr.js') . '"></script>'
	: ($method === 'passkey' ? NuPasskey::script() : '');

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

<?php if ($method === 'passkey'): ?>
	<?= nu_csrf_field() ?>
	<div class="mb-3"><?= NuPasskey::button('2fa', t('pk_use'), 'btn btn-primary w-100 py-2', 'nuPk2fa') ?></div>
	<?php if (NuTwoFA::trustDays() > 0): ?>
		<div class="form-check mb-3">
			<input class="form-check-input" type="checkbox" id="trust" name="trust" value="1">
			<label class="form-check-label small" for="trust"><?= h(t('twofa_trust', NuTwoFA::trustDays())) ?></label>
		</div>
	<?php endif; ?>
<?php else: ?>
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
<?php endif; ?>

<?php
// links below the form, one separator between each (built as a list)
$link = static fn(string $action, string $label, string $extra = '', string $cls = 'btn btn-link btn-sm p-0'): string => '<form method="post" action="' . h(nu_url('twofa')) . '">' . nu_csrf_field()
	. '<input type="hidden" name="action" value="' . $action . '"><button class="' . $cls . '" type="submit"' . $extra . '>' . $label . '</button></form>';
$links = [];
if ($hasPk && $method !== 'passkey') $links[] = $link('use_passkey', '<i class="bi bi-fingerprint me-1" aria-hidden="true"></i>' . h(t('pk_use')), ' id="nuPkUse2fa"');
if ($method === 'mail') $links[] = $link('resend', h(t('twofa_resend')));
if ($hasTotp && $method !== 'totp') $links[] = $link('use_totp', h(t('twofa_use_app')));
if ($canMail && $method !== 'mail') $links[] = $link('use_mail', h(t('twofa_use_mail')));
$links[] = $link('cancel', h(t('cancel')), '', 'btn btn-link btn-sm p-0 text-body-secondary');
?>
<div class="d-flex flex-wrap justify-content-center gap-2 mt-3 small">
	<?= implode('<span class="text-body-tertiary">·</span>', $links) ?>
</div>
<?php
nu_center_end($foot);
