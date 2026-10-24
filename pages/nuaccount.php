<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * My account (0.8.454): the custom user fields a user fills in himself (edit_by "user", core/nuuserfields.php). Shown in the user menu
 * when there are such fields; not for the globeadmin (no user record) and read only while acting as a user or in the demo.
 */

$me = NuAuth::user();
$own = NuUserFields::editable('self');
if (!empty($me['globeadmin']) || !$own) {
	nu_flash('info', t('acc_none'));
	nu_redirect(nu_url('home'));
}
$readOnly = !empty($me['demo']) || NuImpersonate::readOnly();
$id = (string)$me['id'];
$vals = NuUserFields::values($id);
$errors = [];

if (nu_is_post() && nu_post('action') === 'save' && !$readOnly) {
	$post = is_array($_POST['uf'] ?? null) ? $_POST['uf'] : [];
	[$new, $errors] = NuUserFields::validate($post, $id, 'self');
	if (!$errors) {
		$diff = NuUserFields::save($id, $new);
		if ($diff) NuLog::write('user_updated', 'zzzzsys_user', $id, ['fields' => $diff, 'via' => 'account']);
		nu_flash('success', t('saved'));
		nu_redirect(nu_url('account'));
	}
	$vals = array_merge($vals, array_map(static fn($x) => is_scalar($x) ? (string)$x : '', array_intersect_key($post, $own)));
	nu_flash('danger', t('err_check_form'));
}

nu_layout_start(t('acc_title'), 'home');
?>
<form method="post" action="<?= h(nu_url('account')) ?>" id="accForm" data-nu-guard novalidate>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-person-lines-fill me-2"></i><?= h(t('acc_title')) ?></h1>
		<div class="nuTitleSub text-body-secondary small"><?= h((string)$me['display']) ?> · <?= h((string)$me['login']) ?></div>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?>
			<?php if (!$readOnly): ?><button class="btn nuBtnAct nuBtnSave" type="submit" data-nu-save id="accSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		</div>
	</div>
	<div class="card shadow-sm nu-card-narrow"><div class="card-body"><div class="row g-3">
		<?php foreach ($own as $code => $f) echo NuUserFields::inputHtml($f, (string)($vals[$code] ?? ''), $errors[$code] ?? null, $readOnly); ?>
	</div></div></div>
</form>
<?php
nu_layout_end();
