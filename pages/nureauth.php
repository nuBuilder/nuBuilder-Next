<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Password confirmation before dangerous actions (NuSecurity::requireRecentAuth): update, backups, nuconfig.php,
 * settings, App cloner, API secrets, phpMyAdmin / File Manager, "Act as user" with full access, Security check.
 * Valid for NuSecurity 'reauth_minutes' (default 15). Wrong passwords count for the login rate limit; too many log out.
 */

$u    = NuAuth::user();
$next = (string)($_SESSION[NuSess::REAUTH_NEXT] ?? nu_url('home'));
if (NuSecurity::recentAuth()) nu_redirect($next);

$error = '';
['u' => $u, 'error' => $error]
	= NuReauthPage::post(get_defined_vars());   // the POST actions (code review R7, nureauthpage.php)

nu_center_start(t('reauth_title'), t('reauth_subtitle', NuSecurity::reauthMinutes()), 'bi-shield-lock');
?>
<form method="post" action="<?= h(nu_url('reauth')) ?>" novalidate>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="confirm">
	<input type="text" class="d-none" name="username" value="<?= h((string)$u['login']) ?>" autocomplete="username" readonly>
	<label class="form-label" for="reauthPassword"><?= h(t('reauth_password', (string)$u['login'])) ?></label>
	<div class="input-group<?= $error !== '' ? ' has-validation' : '' ?> mb-3">
		<input type="password" class="form-control<?= $error !== '' ? ' is-invalid' : '' ?>" id="reauthPassword" name="password" autocomplete="current-password" required autofocus>
		<button class="btn btn-outline-secondary" type="button" data-pw-toggle="#reauthPassword" aria-label="<?= h(t('show_password')) ?>"><i class="bi bi-eye"></i></button>
		<?php if ($error !== ''): ?><div class="invalid-feedback"><?= h($error) ?></div><?php endif; ?>
	</div>
	<button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-check-lg me-2"></i><?= h(t('reauth_submit')) ?></button>
</form>
<?php if ($pkOk = (NuPasskey::has((string)$u['id']) && !NuImpersonate::active())):   // passkeys: confirm with one instead of the password – never while acting as a user ?>
	<div class="nuLoginPasskey"><div class="nuLoginOr"><span><?= h(t('login_or')) ?></span></div><?= NuPasskey::button('reauth', t('pk_confirm'), 'btn btn-outline-primary w-100 py-2', 'nuPkReauth') ?></div>
<?php endif; ?>
<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('home')) ?>"><?= h(t('cancel')) ?></a></div>
<?php
nu_center_end($pkOk ? NuPasskey::script() : '');
