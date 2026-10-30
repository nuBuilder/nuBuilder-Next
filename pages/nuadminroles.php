<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Admin roles (0.8.323) – who may do which part of the globeadmin's work (core/nuadmin.php). The globeadmin and users with the
 * right "Admin roles" manage them; the role of a user is chosen on the user form.
 *   ?p=adminroles              list
 *   ?p=adminroles&id=<id>      edit · id=new (&copy=<id>: as a copy)
 * POST action save | delete.
 */

NuAdmin::require('adminroles', 'change');
NuAdmin::ensure();

$id = (string)($_GET['id'] ?? '');
$copy = (string)($_GET['copy'] ?? '');
$errors = [];
$role = $id !== '' && $id !== 'new' ? NuAdmin::get($id) : null;
if ($id !== '' && $id !== 'new' && !$role) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
$mine = NuAdmin::role();
$own = $role && $mine && $mine['id'] === $role['id'];       // nobody edits his own role

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'delete' && $role) {
		$err = NuAdmin::delete($role['id']);
		if ($err === '') { nu_flash('success', t('adm_deleted', $role['name'])); nu_redirect(nu_url('adminroles')); }
		nu_flash('danger', $err);
		nu_redirect(nu_url('adminroles', ['id' => $role['id']]));
	}
	if ($action === 'save') {
		$rights = [];
		foreach (NuAdmin::RIGHTS as $k => $_) {
			$v = (string)($_POST['r'][$k] ?? '');
			if ($v === 'view' || $v === 'change') $rights[$k] = $v;
		}
		[$newId, $errors] = NuAdmin::save($role['id'] ?? null, ['code' => nu_post('code'), 'name' => nu_post('name'), 'description' => nu_post('description'), 'rights' => $rights]);
		if (!$errors) { nu_flash('success', t('saved')); nu_redirect(nu_url('adminroles', ['id' => $newId])); }
		$role = ['id' => $role['id'] ?? '', 'code' => nu_post('code'), 'stored_name' => nu_post('name'), 'name' => nu_post('name'), 'description' => nu_post('description'), 'rights' => $rights];
		nu_flash('danger', t('err_check_form'));
	}
}

$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
$fullBadge = '<span class="badge text-bg-danger ms-1 nuAdmFull" title="' . h(t('adm_full_tip')) . '">' . h(t('adm_full')) . '</span>';

// ------------------------------------------------------------------ list
if ($id === '') {                                          // a system list (0.8.687): search, views, export, filters (core/nulistdefs.php)
	NuListView::page('adminroles', [
		'before' => NuListView::crumbs([[t('nav_users'), nu_url('users')], [t('adm_title')]]), 'active' => 'home',
		'title_extra' => NuListView::infoTip(t('adm_title_tip')),
		'buttons' => '<a class="btn nuAddBtn" href="' . h(nu_url('adminroles', ['id' => 'new'])) . '" id="admNew"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>',
	]);
	return;
}

// ------------------------------------------------------------------ edit
if (!$role) {
	$src = $copy !== '' ? NuAdmin::get($copy) : null;
	$role = ['id' => '', 'code' => $src ? $src['code'] . '_copy' : '', 'stored_name' => $src ? $src['name'] . ' (' . t('adm_copy') . ')' : '', 'name' => '',
		'description' => $src['description'] ?? '', 'rights' => $src['rights'] ?? []];
}
$isNew = $role['id'] === '';
$users = $isNew ? [] : NuAdmin::usersOf($role['id']);
$grant = NuAdmin::grantable();
$title = $isNew ? t('adm_new') : $role['name'];
nu_layout_start(t('adm_title'), 'home');
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item"><a href="<?= h(nu_url('adminroles')) ?>"><?= h(t('adm_title')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h($title) ?></li>
</ol></nav>
<form method="post" action="<?= h(nu_url('adminroles', ['id' => $isNew ? 'new' : $role['id']])) ?>" id="admForm" data-nu-guard novalidate>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<div class="nuTitleBar mb-3">
		<h1 class="h3 mb-0 d-flex align-items-center gap-2"><i class="bi bi-person-badge"></i><?= h($title) ?></h1>
		<div class="d-flex flex-wrap gap-2 align-items-center">
			<a class="btn nuBtnAct" href="<?= h(nu_url('adminroles')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
			<?php if (!$own): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" id="admSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
			<?php if (!$isNew): ?>
				<a class="btn nuBtnAct" href="<?= h(nu_url('adminroles', ['id' => 'new', 'copy' => $role['id']])) ?>"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></a>
				<?php if (!$users && !$own): ?><button class="btn nuBtnAct nuBtnDel" type="submit" form="admDelete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button><?php endif; ?>
				<?= NuFormInfo::record(NuAdmin::ROLE_TABLE, (string)$role['id'], ['code' => (string)($role['code'] ?? '')]) /* (i) record info (0.8.501) */ ?>
			<?php endif; ?>
		</div>
	</div>
	<?php if ($own): ?><div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i><?= h(t('adm_err_own_role')) ?></div><?php endif; ?>
	<div class="card shadow-sm mb-3"><div class="card-body">
		<div class="row g-3">
			<div class="col-md-4"><label class="form-label" for="code"><?= h(t('adm_code')) ?> <span class="text-danger">*</span><?= nu_unique_mark(true) ?></label>
				<input class="form-control<?= isset($errors['code']) ? ' is-invalid' : '' ?>" id="code" name="code" maxlength="50" value="<?= h($role['code']) ?>" pattern="[a-z][a-z0-9_]*" required>
				<?php if (isset($errors['code'])): ?><div class="invalid-feedback"><?= h($errors['code']) ?></div><?php endif; ?></div>
			<div class="col-md-8"><label class="form-label" for="name"><?= h(t('adm_name')) ?></label><?= $tip(t('adm_name_tip')) ?>
				<input class="form-control" id="name" name="name" maxlength="100" value="<?= h($role['stored_name']) ?>" placeholder="<?= h($role['code'] !== '' ? t('adm_role_' . $role['code']) : '') ?>"></div>
			<div class="col-12"><label class="form-label" for="description"><?= h(t('adm_description')) ?></label>
				<textarea class="form-control" id="description" name="description" rows="2" maxlength="1000"><?= h($role['description']) ?></textarea></div>
		</div>
	</div></div>

	<div class="card shadow-sm mb-3">
		<div class="card-header d-flex flex-wrap align-items-center gap-2"><span class="fw-semibold"><?= h(t('adm_rights')) ?></span><?= $tip(t('adm_rights_tip')) ?></div>
		<?php if (isset($errors['rights'])): ?><div class="alert alert-danger m-3 mb-0"><?= h($errors['rights']) ?></div><?php endif; ?>
		<div class="table-responsive">
			<table class="table nu-table mb-0 align-middle" id="admRights">
				<?php // sortable titles (0.8.739, assets/js/nuadminroles.js): a sorted list is flat (no group rows), a third click goes back to the groups ?>
				<thead><tr><th class="admSort" data-sort="name"><button type="button" class="btn btn-link p-0 userPermSortBtn" title="<?= h(t('adm_sort_tip')) ?>"><?= h(t('adm_right')) ?></button></th>
					<?php foreach (['' => 'adm_none', 'view' => 'adm_view', 'change' => 'adm_change'] as $lv => $lk): ?><th class="text-center admSort" data-sort="lvl:<?= $lv ?>"><button type="button" class="btn btn-link p-0 userPermSortBtn" title="<?= h(t('adm_sort_tip')) ?>"><?= h(t($lk)) ?></button></th><?php endforeach; ?></tr></thead>
				<tbody>
				<?php foreach (NuAdmin::GROUPS as $g => $gLabel): ?>
					<tr class="nuAdmGroup"><th colspan="4" scope="rowgroup"><i class="bi <?= h(['system' => 'bi-gear', 'data' => 'bi-database', 'dev' => 'bi-code-slash', 'users' => 'bi-people', 'look' => 'bi-palette', 'comm' => 'bi-envelope', 'integration' => 'bi-plug', 'logs' => 'bi-journal-text'][$g] ?? 'bi-folder') ?> me-2" aria-hidden="true"></i><?= h(t($gLabel)) ?></th></tr>
					<?php foreach (NuAdmin::RIGHTS as $k => [$grp, $label, $full, $levels]): if ($grp !== $g) continue;
						$cur = $role['rights'][$k] ?? '';
						$may = $grant[$k] ?? '';                       // what the editing user may give
						$dis = static fn(string $lvl) => $own || ($lvl !== '' && $may !== 'change' && !($may === 'view' && $lvl === 'view') && $cur !== $lvl) ? ' disabled' : ''; ?>
						<tr class="nuAdmRight" data-group="<?= h($g) ?>" data-name="<?= h(t($label)) ?>">
							<td><?php if (isset(NuAdmin::PAGES[$k])): [$pr, $pp] = NuAdmin::PAGES[$k]; ?><a href="<?= h(nu_url($pr, $pp)) ?>" target="_blank" rel="noopener" class="admRightLink" title="<?= h(t('adm_open_page')) ?>"><?= h(t($label)) ?><i class="bi bi-box-arrow-up-right ms-1 small" aria-hidden="true"></i></a><?php else: ?><?= h(t($label)) ?><?php endif; ?><?= $full ? $fullBadge : '' ?>
								<span class="admGrpTag small text-body-secondary ms-1"><?= h(t($gLabel)) ?></span></td>
							<td class="text-center"><input class="form-check-input" type="radio" name="r[<?= h($k) ?>]" value="" aria-label="<?= h(t($label) . ': ' . t('adm_none')) ?>"<?= $cur === '' ? ' checked' : '' ?><?= $own ? ' disabled' : '' ?>></td>
							<td class="text-center"><?php if ($levels === 'vc'): ?><input class="form-check-input" type="radio" name="r[<?= h($k) ?>]" value="view" aria-label="<?= h(t($label) . ': ' . t('adm_view')) ?>"<?= $cur === 'view' ? ' checked' : '' ?><?= $dis('view') ?>><?php endif; ?></td>
							<td class="text-center"><input class="form-check-input" type="radio" name="r[<?= h($k) ?>]" value="change" aria-label="<?= h(t($label) . ': ' . t('adm_change')) ?>"<?= $cur === 'change' ? ' checked' : '' ?><?= $dis('change') ?>></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</form>
<?php if (!$isNew): ?>
<form method="post" action="<?= h(nu_url('adminroles', ['id' => $role['id']])) ?>" id="admDelete" data-confirm="<?= h(t('adm_delete_confirm', $role['name'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
</form>
<div class="card shadow-sm">
	<div class="card-header fw-semibold"><?= h(t('nav_users')) ?> <span class="badge text-bg-secondary ms-1"><?= count($users) ?></span><?= $tip(t('adm_users_tip')) ?></div>
	<ul class="list-group list-group-flush" id="admUsers">
		<?php foreach ($users as $us): ?><li class="list-group-item"><a href="<?= h(nu_url('user', ['id' => $us['id']])) ?>"><?= h((string)$us['login']) ?></a> <span class="text-body-secondary small"><?= h((string)$us['name']) ?></span></li><?php endforeach; ?>
		<?php if (!$users): ?><li class="list-group-item text-body-secondary small"><?= h(t('adm_no_users')) ?></li><?php endif; ?>
	</ul>
</div>
<?php endif; ?>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuadminroles.js') . '"></script>');
