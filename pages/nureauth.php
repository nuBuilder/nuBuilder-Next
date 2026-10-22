<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Password confirmation before dangerous actions (0.8.207, NuSecurity::requireRecentAuth): update, backups, nuconfig.php,
 * settings, App cloner, API secrets, phpMyAdmin / File Manager, "Act as user" with full access, Security check.
 * Valid for NuSecurity 'reauth_minutes' (default 15). Wrong passwords count for the login rate limit; too many log out.
 */

$u    = NuAuth::user();
$next = (string)($_SESSION['nu_reauth_next'] ?? nu_url('home'));
if (NuSecurity::recentAuth()) nu_redirect($next);

$error = '';
if (nu_is_post() && nu_post('action') === 'confirm') {
	$login = (string)$u['login'];
	if (NuSecurity::loginBlocked($login)) {
		NuLog::write('session_ended', '', '', ['reason' => 'password confirmation locked']);
		NuAuth::logout();
		nu_flash('danger', t('login_err_locked'));
		nu_redirect(nu_url('login'));
	}
	if (NuAuth::checkOwnPassword((string)($_POST['password'] ?? ''))) {
		NuSecurity::confirmed();
		unset($_SESSION['nu_reauth_next']);
		NuLog::write('reauth_ok');
		nu_redirect($next);
	}
	usleep(random_int(250_000, 600_000));
	NuLog::write('reauth_failed');                         // counts for the login rate limit (NuSecurity::loginBlocked)
	$error = t('reauth_wrong');
}

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
<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('home')) ?>"><?= h(t('cancel')) ?></a></div>
<?php
nu_center_end();
