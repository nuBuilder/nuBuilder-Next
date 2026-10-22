<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Permission items (zzzzsys_permission_item) – nuBuilder's form "nupermissionitem", rights of that form.
 * Users get items here (Users tab) or in the user form (Permission items). Code checks them with nuUserHasPermission('ident').
 *   list, ?id=<id> | ?id=new edit
 */

NuAcl::require(NuAcl::FORM_PERMISSION);
NuPermission::ensureTables();
$db       = nu_db();
$rights   = NuAcl::rights(NuAcl::FORM_PERMISSION);
$userEdit = NuAcl::can(NuAcl::FORM_USER, 'save');
$T        = NuPermission::ITEM;

$id    = (string)($_GET['id'] ?? '');
$isNew = $id === 'new';
$item  = null;
if ($id !== '' && !$isNew) {
	$item = $db->one("SELECT * FROM $T WHERE zzzzsys_permission_item_id = ?", [$id]);
	if (!$item) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('permissions')); }
}
if ($isNew) NuAcl::require(NuAcl::FORM_PERMISSION, 'create');
$errors = [];

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		NuAcl::require(NuAcl::FORM_PERMISSION, $item ? 'save' : 'create');
		$in = ['pme_ident' => nu_post('pme_ident'), 'pme_group' => nu_post('pme_group'), 'pme_description' => (string)($_POST['pme_description'] ?? '')];
		[$newId, $errors] = NuPermission::save($in, $item ? $id : '');
		if (!$errors) {
			$after = $db->one("SELECT * FROM $T WHERE zzzzsys_permission_item_id = ?", [$newId]) ?? [];
			NuLog::write($item ? 'permission_changed' : 'permission_created', $T, (string)$newId, $item ? ['fields' => NuLog::diff($item, $after)] : ['ident' => $after['pme_ident'] ?? '']);
			if ($userEdit && isset($_POST['users_sent'])) {
				$users = is_array($_POST['users'] ?? null) ? $_POST['users'] : [];
				[$add, $rem] = NuPermission::setItemUsers((string)$newId, $users);
				if ($add || $rem) NuLog::write('permission_users', $T, (string)$newId, ['ident' => $after['pme_ident'] ?? '', 'added' => $add, 'removed' => $rem]);
			}
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('permissions', ['id' => $newId]));
		}
		nu_flash('danger', t('err_check_form'));
	}
	if ($action === 'clone' && $item) {
		NuAcl::require(NuAcl::FORM_PERMISSION, 'clone');
		$base = (string)$item['pme_ident'];
		$n = 2;
		do { $ident = mb_substr($base, 0, 46) . '_' . $n++; } while ((int)$db->value("SELECT COUNT(*) FROM $T WHERE pme_ident = ?", [$ident]));
		[$newId, $e] = NuPermission::save(['pme_ident' => $ident, 'pme_group' => $item['pme_group'], 'pme_description' => $item['pme_description']]);
		if ($newId) {
			NuLog::write('permission_created', $T, $newId, ['ident' => $ident, 'clone_of' => $id]);
			nu_flash('success', t('perm_cloned'));
			nu_redirect(nu_url('permissions', ['id' => $newId]));
		}
		$errors = $e;
	}
	if ($action === 'delete' && $item) {
		NuAcl::require(NuAcl::FORM_PERMISSION, 'delete');
		NuPermission::delete($id);
		NuLog::write('permission_deleted', $T, $id, ['ident' => (string)$item['pme_ident']]);
		nu_flash('success', t('perm_deleted', (string)$item['pme_ident']));
		nu_redirect(nu_url('permissions'));
	}
}

// ------------------------------------------------------------------ list (also Print / CSV)
if ($id === '') {
	// list: columns, filters and sort of the chosen list view (0.8.334, core/nulistdefs.php)
	NuListView::page('permissions', [
		'before' => NuListView::crumbs([[t('nav_users'), nu_url('users')], [t('perm_title')]]), 'active' => 'users',
		'title_extra' => NuListView::infoToggle('permHint'), 'title_sub' => '<div class="collapse nuTitleSub text-body-secondary small" id="permHint">' . h(t('perm_intro')) . '</div>',
		'buttons' => $rights['add'] ? '<a class="btn nuAddBtn" title="' . h(t('perm_new')) . '" href="' . h(nu_url('permissions', ['id' => 'new'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '',
	]);
	return;
}

$title = $id === '' ? t('perm_title') : ($item ? (string)$item['pme_ident'] : t('perm_new'));
$head = $id !== '' ? '<link rel="stylesheet" href="' . nu_asset('assets/vendor/tom-select-2.6.2/tom-select.bootstrap5.min.css') . '">' : '';
nu_layout_start($title, 'users', ['head' => $head]);
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('users')) ?>"><?= h(t('nav_users')) ?></a></li>
		<?php if ($id !== ''): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('permissions')) ?>"><?= h(t('perm_title')) ?></a></li>
		<?php endif; ?>
		<li class="breadcrumb-item active" aria-current="page"><?= h($title) ?></li>
	</ol>
</nav>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php endif; ?>

<?php if ($id === ''): ?>
<?php else: ?>
	<?php
	$posted = nu_is_post() && nu_post('action') === 'save';
	$v = static fn(string $k) => $posted ? (string)($_POST[$k] ?? '') : (string)($item[$k] ?? '');
	$locked = !($item ? $rights['save'] : $rights['create']);
	$assigned = $item ? NuPermission::users($id) : [];
	$selUsers = $posted ? array_map('strval', is_array($_POST['users'] ?? null) ? $_POST['users'] : []) : array_column($assigned, 'zzzzsys_user_id');
	$allUsers = $userEdit && !$locked ? $db->all('SELECT u.zzzzsys_user_id, u.sus_login_name, u.sus_name, u.sus_first_name, u.sus_last_name, a.sal_code FROM zzzzsys_user u
		LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id WHERE COALESCE(u.sus_login_name, \'\') <> \'\' ORDER BY u.sus_login_name') : [];
	$uname = static fn(array $u) => trim((string)($u['sus_name'] ?: trim($u['sus_first_name'] . ' ' . $u['sus_last_name'])));
	$usage = $item ? NuPermission::usage((string)$item['pme_ident']) : [];
	$groups = array_column($db->all("SELECT DISTINCT pme_group FROM $T WHERE pme_group IS NOT NULL AND pme_group <> '' ORDER BY pme_group"), 'pme_group');
	?>
	<div class="nuTitleBar mb-3">
		<h1 class="h3 mb-0 d-flex align-items-center gap-2"><i class="bi bi-key"></i><span class="text-break"><?= h($title) ?></span></h1>
		<div class="d-flex flex-wrap gap-2 align-items-center">
			<a class="btn nuBtnAct" href="<?= h(nu_url('permissions')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
			<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuRecordForm"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
			<?php if ($item && $rights['clone']): ?>
				<form method="post" action="<?= h(nu_url('permissions', ['id' => $id])) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="clone">
					<button class="btn nuBtnAct" type="submit"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></button></form>
			<?php endif; ?>
			<?php if ($item && $rights['delete']): ?>
				<form method="post" action="<?= h(nu_url('permissions', ['id' => $id])) ?>" data-confirm="<?= h(t('perm_delete_confirm', (string)$item['pme_ident'], count($assigned))) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
					<button class="btn nuBtnAct nuBtnDel" type="submit"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
				</form>
			<?php endif; ?>
		</div>
	</div>
	<?php if ($locked): ?><div class="alert alert-info d-flex gap-2"><i class="bi bi-eye mt-1"></i><div><?= h(t('read_only')) ?></div></div><?php endif; ?>

	<form method="post" data-nu-focus-first data-nu-guard id="nuRecordForm" action="<?= h(nu_url('permissions', ['id' => $item ? $id : 'new'])) ?>" novalidate>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
		<fieldset<?= $locked ? ' disabled' : '' ?>>
		<div class="row g-3">
			<div class="col-lg-6">
				<div class="card shadow-sm h-100">
					<div class="card-header fw-semibold"><i class="bi bi-key me-2"></i><?= h(t('perm_item')) ?></div>
					<div class="card-body">
						<div class="mb-3">
							<label class="form-label" for="pme_ident"><?= h(t('perm_ident')) ?> <span class="text-danger">*</span></label>
							<input class="form-control font-monospace<?= nu_invalid($errors, 'pme_ident') ?>" id="pme_ident" name="pme_ident" maxlength="50" required spellcheck="false" autocomplete="off" value="<?= h($v('pme_ident')) ?>"<?= $isNew ? ' autofocus' : '' ?>>
							<?= nu_feedback($errors, 'pme_ident') ?>
							<div class="form-text"><?= h(t('perm_ident_hint')) ?></div>
						</div>
						<div class="mb-3">
							<label class="form-label" for="pme_group"><?= h(t('perm_group')) ?></label>
							<input class="form-control<?= nu_invalid($errors, 'pme_group') ?>" id="pme_group" name="pme_group" maxlength="50" list="permGroups" value="<?= h($v('pme_group')) ?>">
							<?= nu_feedback($errors, 'pme_group') ?>
							<datalist id="permGroups"><?php foreach ($groups as $g): ?><option value="<?= h($g) ?>"><?php endforeach; ?></datalist>
						</div>
						<div>
							<label class="form-label" for="pme_description"><?= h(t('perm_description')) ?></label>
							<textarea class="form-control" id="pme_description" name="pme_description" rows="4"><?= h($v('pme_description')) ?></textarea>
						</div>
						<?php if ($item): ?>
							<div class="mt-3 small text-body-secondary"><?= h(t('perm_code_hint')) ?></div>
							<pre class="small bg-body-tertiary border rounded p-2 mb-0 mt-1"><code>if (nuUserHasPermission('<?= h((string)$item['pme_ident']) ?>')) { … }</code></pre>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<div class="col-lg-6">
				<div class="card shadow-sm mb-3">
					<div class="card-header fw-semibold d-flex align-items-center"><i class="bi bi-people me-2"></i><span class="me-auto"><?= h(t('perm_users')) ?></span><span class="badge text-bg-secondary"><?= count($assigned) ?></span></div>
					<div class="card-body">
						<?php if ($allUsers): ?>
							<input type="hidden" name="users_sent" value="1">
							<label class="form-label visually-hidden" for="permUsers"><?= h(t('perm_users')) ?></label>
							<select id="permUsers" name="users[]" multiple class="permUsers" placeholder="<?= h(t('perm_users_ph')) ?>">
								<?php foreach ($allUsers as $u): $n = $uname($u); ?>
									<option value="<?= h($u['zzzzsys_user_id']) ?>"<?= in_array((string)$u['zzzzsys_user_id'], $selUsers, true) ? ' selected' : '' ?> data-sub="<?= h(trim($n . ($u['sal_code'] ? ' · ' . $u['sal_code'] : ''))) ?>"><?= h($u['sus_login_name']) ?></option>
								<?php endforeach; ?>
							</select>
							<div class="form-text"><?= h(t('perm_users_hint')) ?></div>
						<?php elseif ($assigned): ?>
							<ul class="list-unstyled mb-0">
								<?php foreach ($assigned as $u): ?>
									<li><?php if (NuAcl::can(NuAcl::FORM_USER)): ?><a href="<?= h(nu_url('user', ['id' => $u['zzzzsys_user_id']])) ?>"><?= h($u['sus_login_name']) ?></a><?php else: ?><?= h($u['sus_login_name']) ?><?php endif; ?>
										<span class="small text-body-secondary"><?= h($uname($u)) ?></span></li>
								<?php endforeach; ?>
							</ul>
						<?php else: ?>
							<div class="text-body-secondary small"><?= h(t('perm_no_users')) ?></div>
						<?php endif; ?>
					</div>
				</div>
				<?php if ($item): ?>
				<div class="card shadow-sm">
					<div class="card-header fw-semibold"><i class="bi bi-code-slash me-2"></i><?= h(t('perm_usage')) ?></div>
					<div class="card-body">
						<?php if (!$usage): ?><div class="text-body-secondary small"><?= h(t('perm_usage_none')) ?></div><?php else: ?>
							<ul class="list-unstyled small mb-0">
								<?php foreach ($usage as $u): ?>
									<li><i class="bi bi-dot"></i><?php if ($u['proc_id'] !== '' && NuAcl::can(NuAcl::FORM_PROCEDURE)): ?><a href="<?= h(nu_url('procedures', ['id' => $u['proc_id']])) ?>"><?= h($u['label']) ?></a><?php elseif ($u['form_id'] !== '' && NuAcl::can(NuAcl::FORM_FORM)): ?><a href="<?= h(nu_url('builder', ['f' => $u['form_id']])) ?>"><?= h($u['label']) ?></a><?php else: ?><?= h($u['label']) ?><?php endif; ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		</fieldset>
	</form>
<?php endif; ?>
<?php
nu_layout_end($id !== '' ? '<script src="' . nu_asset('assets/vendor/tom-select-2.6.2/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nupermissions.js') . '"></script>' : '');
