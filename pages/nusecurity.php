<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* Own account security: authenticator app (set up / remove), trusted devices, password. */

$u      = NuAuth::user();
$uid    = (string)$u['id'];
$errors = [];

if (nu_is_post()) {
	$action = nu_post('action');

	if ($action === 'totp_start') {
		$_SESSION['nu_totp_setup'] = NuTwoFA::newSecret();
		nu_redirect(nu_url('security'));
	}
	if ($action === 'totp_cancel') {
		unset($_SESSION['nu_totp_setup']);
		nu_redirect(nu_url('security'));
	}
	if ($action === 'totp_confirm' && !empty($_SESSION['nu_totp_setup'])) {
		$step = NuTwoFA::matchCode((string)$_SESSION['nu_totp_setup'], nu_post('code'));
		if ($step === null) {
			$errors['code'] = t('twofa_err_code');
		} else {
			NuTwoFA::saveTotp($uid, (string)$_SESSION['nu_totp_setup'], $step);
			unset($_SESSION['nu_totp_setup']);
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
	if ($action === 'devices_forget') {
		NuTwoFA::forgetDevices($uid);
		NuLog::write('2fa_devices_forgotten');
		nu_flash('success', t('sec_devices_forgotten'));
		nu_redirect(nu_url('security'));
	}
}

$hasTotp  = NuTwoFA::hasTotp($uid);
$setup    = $_SESSION['nu_totp_setup'] ?? null;
$devices  = NuTwoFA::deviceCount($uid);
$required = $u['globeadmin'] ? (bool)nu_cfg('nuConfig2FAAdmin')
	: ((bool)nu_cfg('nuConfig2FAUser') && NuAuth::levels2fa((string)nu_db()->value('SELECT sal_use_2fa FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$u['access_id']]) === '1', (string)$u['id']));
$email    = $u['globeadmin'] ? (string)(nu_cfg('nuConfigGlobeadminEmail') ?: NuMail::settings()['from']) : $u['email'];

$foot = $setup ? '<script src="' . nu_asset(NU_QRCODE . '/qrcode.js') . '"></script><script src="' . nu_asset('assets/js/nuqr.js') . '"></script>' : '';

nu_layout_start(t('sec_title'));
?>
<div class="row justify-content-center">
	<div class="col-lg-8 col-xl-7">
		<h1 class="h3 mb-3"><i class="bi bi-shield-lock me-2"></i><?= h(t('sec_title')) ?></h1>
		<div class="mb-3"><?= nu_back_button() ?></div>

		<div class="alert <?= $required ? 'alert-info' : 'alert-secondary' ?> d-flex gap-2">
			<i class="bi bi-info-circle mt-1"></i>
			<div><?= h($required ? t('sec_2fa_required') : t('sec_2fa_optional')) ?></div>
		</div>

		<div class="card shadow-sm mb-3">
			<div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-phone"></i><?= h(t('sec_totp')) ?>
				<span class="badge ms-auto <?= $hasTotp ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h($hasTotp ? t('sec_active') : t('sec_inactive')) ?></span></div>
			<div class="card-body">
				<?php if ($setup): ?>
					<ol class="small ps-3"><li><?= h(t('twofa_enroll_step1')) ?></li><li><?= h(t('twofa_enroll_step2')) ?></li></ol>
					<div class="d-flex flex-wrap gap-4 align-items-center">
						<div class="nuQr" data-qr="<?= h(NuTwoFA::otpauthUri((string)$setup, $u['login'])) ?>" role="img" aria-label="<?= h(t('twofa_qr')) ?>"></div>
						<div class="flex-grow-1">
							<p class="small text-body-secondary mb-1"><?= h(t('twofa_manual')) ?></p>
							<p><code class="nuSecret user-select-all"><?= h(trim(chunk_split((string)$setup, 4, ' '))) ?></code></p>
							<form method="post" action="<?= h(nu_url('security')) ?>" class="d-flex gap-2 align-items-start" novalidate>
								<?= nu_csrf_field() ?><input type="hidden" name="action" value="totp_confirm">
								<div>
									<input type="text" class="form-control nuOtp<?= nu_invalid($errors, 'code') ?>" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="123456" aria-label="<?= h(t('twofa_code')) ?>" required autofocus>
									<?= nu_feedback($errors, 'code') ?>
								</div>
								<button class="btn btn-primary" type="submit"><?= h(t('sec_activate')) ?></button>
							</form>
							<form method="post" action="<?= h(nu_url('security')) ?>" class="mt-2"><?= nu_csrf_field() ?><input type="hidden" name="action" value="totp_cancel">
								<button class="btn btn-link btn-sm p-0" type="submit"><?= h(t('cancel')) ?></button></form>
						</div>
					</div>
				<?php elseif ($hasTotp): ?>
					<p class="mb-3"><?= h(t('sec_totp_on')) ?></p>
					<form method="post" action="<?= h(nu_url('security')) ?>" class="d-flex flex-wrap gap-2 align-items-start" novalidate>
						<?= nu_csrf_field() ?><input type="hidden" name="action" value="totp_remove">
						<div>
							<input type="text" class="form-control<?= nu_invalid($errors, 'remove_code') ?>" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="<?= h(t('twofa_code')) ?>" aria-label="<?= h(t('twofa_code')) ?>">
							<?= nu_feedback($errors, 'remove_code') ?>
						</div>
						<button class="btn btn-outline-danger" type="submit"><i class="bi bi-x-circle me-1"></i><?= h(t('sec_totp_remove')) ?></button>
					</form>
				<?php else: ?>
					<p class="mb-3"><?= h(t('sec_totp_off')) ?></p>
					<form method="post" action="<?= h(nu_url('security')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="totp_start">
						<button class="btn btn-primary" type="submit"><i class="bi bi-qr-code me-1"></i><?= h(t('sec_totp_setup')) ?></button></form>
				<?php endif; ?>
			</div>
		</div>

		<div class="card shadow-sm mb-3">
			<div class="card-header fw-semibold"><i class="bi bi-envelope me-2"></i><?= h(t('sec_mail')) ?></div>
			<div class="card-body">
				<?php if ($email !== '' && NuMail::configured()): ?>
					<p class="mb-0"><?= h(t('sec_mail_on', NuTwoFA::maskEmail($email))) ?></p>
				<?php else: ?>
					<p class="mb-0 text-body-secondary"><?= h(t('sec_mail_off')) ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="card shadow-sm mb-3">
			<div class="card-header fw-semibold"><i class="bi bi-laptop me-2"></i><?= h(t('sec_devices')) ?></div>
			<div class="card-body d-flex flex-wrap gap-2 align-items-center">
				<span class="me-auto"><?= h(t('sec_devices_count', $devices)) ?></span>
				<?php if ($devices > 0): ?>
					<form method="post" action="<?= h(nu_url('security')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="devices_forget">
						<button class="btn btn-outline-secondary btn-sm" type="submit"><?= h(t('sec_devices_forget')) ?></button></form>
				<?php endif; ?>
			</div>
		</div>

		<?php if (!$u['globeadmin'] && $u['via'] !== 'nubuilder'): ?>
			<a class="btn btn-outline-secondary" href="<?= h(nu_url('password')) ?>"><i class="bi bi-key me-1"></i><?= h(t('nav_change_password')) ?></a>
		<?php endif; ?>
	</div>
</div>
<?php
nu_layout_end($foot);
