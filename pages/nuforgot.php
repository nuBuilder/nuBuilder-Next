<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* "Forgot password": sends a reset link to the user's e-mail. The answer is always the same (no user enumeration). */

if (NuAuth::user()) nu_redirect(nu_url('home'));
if (!NuMail::configured()) nu_redirect(nu_url('login'));

$done  = false;
$value = '';

['value' => $value, 'done' => $done]
	= NuForgotPage::post(get_defined_vars());   // the POST actions (code review R7, nuforgotpage.php)

nu_center_start(t('forgot_title'), t('forgot_subtitle'), 'bi-key');
?>
<?php if ($done): ?>
	<div class="alert alert-success d-flex gap-2" role="status"><i class="bi bi-envelope-check mt-1"></i><div><?= h(t('forgot_sent')) ?></div></div>
	<a class="btn btn-outline-secondary w-100" href="<?= h(nu_url('login')) ?>"><?= h(t('back_to_login')) ?></a>
<?php else: ?>
	<form method="post" action="<?= h(nu_url('forgot')) ?>" novalidate>
		<?= nu_csrf_field() ?>
		<div class="form-floating mb-3">
			<input type="text" class="form-control" id="identifier" name="identifier" value="<?= h($value) ?>" placeholder="x"
				   autocomplete="username" autocapitalize="off" spellcheck="false" maxlength="255" required autofocus>
			<label for="identifier"><?= h(t('forgot_identifier')) ?></label>
		</div>
		<button class="btn btn-primary w-100 py-2" type="submit"><i class="bi bi-send me-2"></i><?= h(t('forgot_submit')) ?></button>
	</form>
	<div class="text-center mt-3"><a class="small text-decoration-none" href="<?= h(nu_url('login')) ?>"><?= h(t('back_to_login')) ?></a></div>
<?php endif; ?>
<?php
nu_center_end();
