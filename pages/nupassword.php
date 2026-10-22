<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

$u = NuAuth::user();
if ($u['globeadmin']) {
	nu_flash('info', t('pw_err_globeadmin'));
	nu_redirect(nu_url('home'));
}

$min    = max(1, (int)nu_cfg('nuConfigPasswordMinLength'));
$errors = [];

if (nu_is_post()) {
	$current = (string)($_POST['current'] ?? '');
	$new     = (string)($_POST['new'] ?? '');
	$confirm = (string)($_POST['confirm'] ?? '');

	if ($current === '')                $errors['current'] = t('err_required');
	if (mb_strlen($new) < $min)         $errors['new']     = t('pw_err_min', $min);
	elseif (strlen($new) > 72)          $errors['new']     = t('err_too_long', 72);
	if ($new !== $confirm)              $errors['confirm'] = t('pw_err_confirm');

	if (!$errors) {
		$err = NuAuth::changeOwnPassword($current, $new);
		if ($err === 'pw_err_current') $errors['current'] = t($err);
		elseif ($err)                  $errors['new']     = t($err);
		else {
			nu_flash('success', t('pw_changed'));
			nu_redirect(nu_url('home'));
		}
	}
}

nu_layout_start(t('pw_title'));
?>
<div class="row justify-content-center">
	<div class="col-md-8 col-lg-6">
		<h1 class="h3 mb-3"><?= h(t('pw_title')) ?></h1>
		<?php if (!$u['change_password']): ?><div class="mb-3"><?= nu_back_button() ?></div><?php endif; ?>
		<?php if ($u['change_password']): ?>
			<div class="alert alert-warning d-flex gap-2"><i class="bi bi-shield-exclamation mt-1"></i><div><?= h(t('pw_forced')) ?></div></div>
		<?php endif; ?>
		<div class="card shadow-sm">
			<div class="card-body p-4">
				<form method="post" action="<?= h(nu_url('password')) ?>" novalidate data-nu-guard>
					<?= nu_csrf_field() ?>
					<input type="text" class="d-none" name="username" value="<?= h($u['login']) ?>" autocomplete="username" readonly>
					<?php foreach (['current' => 'current-password', 'new' => 'new-password', 'confirm' => 'new-password'] as $f => $ac): ?>
						<div class="mb-3">
							<label class="form-label" for="pw_<?= $f ?>"><?= h(t('pw_' . $f)) ?></label>
							<div class="input-group<?= isset($errors[$f]) ? ' has-validation' : '' ?>">
								<input type="password" class="form-control<?= nu_invalid($errors, $f) ?>" id="pw_<?= $f ?>" name="<?= $f ?>" autocomplete="<?= $ac ?>" required>
								<button class="btn btn-outline-secondary" type="button" data-pw-toggle="#pw_<?= $f ?>" aria-label="<?= h(t('show_password')) ?>"><i class="bi bi-eye"></i></button>
								<?= nu_feedback($errors, $f) ?>
							</div>
							<?php if ($f === 'new'): ?><div class="form-text"><?= h(t('pw_min_hint', $min)) ?></div><?php endif; ?>
						</div>
					<?php endforeach; ?>
					<div class="d-flex gap-2 justify-content-end">
						<?php if (!$u['change_password']): ?><a class="btn btn-outline-secondary" href="<?= h(nu_url('home')) ?>"><?= h(t('cancel')) ?></a><?php endif; ?>
						<button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<?php
nu_layout_end();
