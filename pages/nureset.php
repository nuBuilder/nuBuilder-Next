<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* Sets a new password from a reset link (Forgot password: 1 hour; administrator's reset: NuPwReset::HOURS; welcome e-mail: $nuConfigWelcomeLinkHours),
   single use. After a reset (not after the welcome link) the user gets a confirmation e-mail and every other session ends. */

$uid   = (string)($_GET['u'] ?? '');
$token = (string)($_GET['t'] ?? '');
$row   = (preg_match('/^[\w.-]{1,25}$/', $uid) && preg_match('/^[a-f0-9]{64}$/', $token)) ? NuToken::find('pw_reset', $uid, $token) : null;
$user  = $row ? nu_db()->one('SELECT zzzzsys_user_id, sus_login_name, sus_expires_on FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$uid]) : null;

if (!$row || !$user || nu_is_expired($user['sus_expires_on'])) {
	nu_center_start(t('reset_title'), '', 'bi-key');
	echo '<div class="alert alert-warning d-flex gap-2"><i class="bi bi-exclamation-triangle mt-1"></i><div>' . h(t('reset_invalid')) . '</div></div>';
	if (NuMail::configured()) echo '<a class="btn btn-primary w-100 mb-2" href="' . h(nu_url('forgot')) . '">' . h(t('reset_request_new')) . '</a>';
	echo '<a class="btn btn-outline-secondary w-100" href="' . h(nu_url('login')) . '">' . h(t('back_to_login')) . '</a>';
	nu_center_end();
	exit;
}

$min    = NuPwPolicy::minLength();
$errors = [];

['errors' => $errors, 'uid' => $uid, 'user' => $user]
	= NuResetPage::post(get_defined_vars());   // the POST actions (code review R7, nuresetpage.php)

nu_center_start(t('reset_title'), t('reset_subtitle', (string)$user['sus_login_name']), 'bi-key');
?>
<form method="post" action="<?= h(nu_url('reset', ['u' => $uid, 't' => $token])) ?>" novalidate>
	<?= nu_csrf_field() ?>
	<input type="text" class="d-none" name="username" value="<?= h($user['sus_login_name']) ?>" autocomplete="username" readonly>
	<?php foreach (['new' => 'pw_new', 'confirm' => 'pw_confirm'] as $f => $label): ?>
		<div class="mb-3">
			<label class="form-label" for="pw_<?= $f ?>"><?= h(t($label)) ?></label>
			<div class="input-group<?= isset($errors[$f]) ? ' has-validation' : '' ?>">
				<input type="password" class="form-control<?= nu_invalid($errors, $f) ?>" id="pw_<?= $f ?>" name="<?= $f ?>" autocomplete="new-password" required<?= $f === 'new' ? ' autofocus' : '' ?>>
				<button class="btn btn-outline-secondary" type="button" data-pw-toggle="#pw_<?= $f ?>" aria-label="<?= h(t('show_password')) ?>"><i class="bi bi-eye"></i></button>
				<?= nu_feedback($errors, $f) ?>
			</div>
			<?php if ($f === 'new'): ?><?= NuPwPolicy::rulesHtml('pw_new') ?><?php endif; ?>
		</div>
	<?php endforeach; ?>
	<button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-check-lg me-2"></i><?= h(t('reset_submit')) ?></button>
</form>
<?php
nu_center_end();
