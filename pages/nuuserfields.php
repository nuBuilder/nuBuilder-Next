<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Custom user fields (core/nuuserfields.php): the list of fields and one field's settings (?id=<id> | ?id=new).
 * Admin right userfields: view on GET, change on POST (NuAdmin::require). Adding / deleting a field changes the table
 * zzzzsys_nunext_user_ext – password confirmation for the changes. ?csv=<id>: the values of a field (before deleting it).
 */

NuAdmin::require('userfields');
NuUserfieldsPage::post1(get_defined_vars());   // the POST actions (code review R7, nuuserfieldspage.php)
$canChange = NuAdmin::can('userfields', 'change') && empty(NuAuth::user()['demo']);
$id = (string)($_GET['id'] ?? '');

if (isset($_GET['csv'])) {
	$f = NuUserFields::byId((string)$_GET['csv']);
	if (!$f) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('userfields')); }
	NuLog::write('user_field_csv', NuUserFields::TABLE, (string)$f['zzzzsys_nunext_user_field_id'], ['code' => $f['code']]);
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="user-field-' . $f['code'] . '-' . date('Ymd') . '.csv"');
	echo NuUserFields::csv($f);
	exit;
}

$errors = [];
$in = [];
['id' => $id, 'in' => $in, 'errors' => $errors, 'e' => $e]
	= NuUserfieldsPage::post2(get_defined_vars());   // the POST actions (code review R7, nuuserfieldspage.php)

$all = NuUserFields::all();
$typeName = static fn(string $t) => t('uf_type_' . $t);
$editName = static fn(string $e) => t('uf_edit_' . $e);

// ------------------------------------------------------------------ one field
if ($id !== '') {
	$f = $id === 'new' ? null : NuUserFields::byId($id);
	if ($id !== 'new' && !$f) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('userfields')); }
	$val = static function (string $k, string $def = '') use ($in, $f): string {
		if ($in) return is_scalar($in[$k] ?? null) ? (string)$in[$k] : '';
		return $f ? (string)($f[$k] ?? $def) : $def;
	};
	$chk = static fn(string $k, bool $def = false) => $in ? !empty($in[$k]) : ($f ? (string)($f[$k] ?? '') === '1' : $def);
	$fld = static function (string $k, string $html) use ($errors): string {
		return $html . (isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '');
	};
	$inv = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$type = $f ? (string)$f['type'] : ($val('type') ?: 'text');
	$values = 0;
	if ($f && nu_db()->tableExists(NuUserFields::EXT)) {
		try { $values = (int)nu_db()->value('SELECT COUNT(*) FROM ' . NuUserFields::EXT . ' WHERE ' . NuDB::ident((string)$f['code']) . ' IS NOT NULL'); } catch (Throwable) { $values = 0; }
	}
	nu_body_class('nuRecPage');
	nu_layout_start($f ? NuUserFields::label($f) : t('uf_new'), 'users');
	?>
	<form method="post" action="<?= h(nu_url('userfields', ['id' => $id])) ?>" id="ufForm" data-nu-guard novalidate>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
		<div class="nuTitleBar nuRecHead mb-3">
			<h1 class="h4 mb-0"><i class="bi bi-ui-checks me-2"></i><?= h($f ? NuUserFields::label($f) : t('uf_new')) ?></h1>
			<div class="nuTitleSub text-body-secondary small"><?= h(t('uf_title')) ?></div>
			<div class="d-flex flex-wrap gap-2"><?= nu_back_button(null, nu_url('userfields')) ?>
				<?php if ($canChange): ?><button class="btn nuBtnAct nuBtnSave" type="submit" data-nu-save id="ufSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
				<?php if ($canChange && $f): ?><button class="btn nuBtnAct nuBtnDel" type="submit" form="ufDelete" id="ufDeleteBtn"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button><?php endif; ?>
				<?php if ($f): ?><?= NuFormInfo::record(NuUserFields::TABLE, (string)$id, ['code' => (string)($f['code'] ?? '')]) /* (i) record info */ ?><?php endif; ?>
			</div>
		</div>
		<fieldset<?= $canChange ? '' : ' disabled' ?>>
		<div class="card shadow-sm nu-card-narrow ufCard"><div class="card-body"><div class="row g-3">
			<div class="col-sm-6">
				<label class="form-label" for="ufLabel"><?= h(t('uf_label')) ?> <span class="text-danger">*</span></label>
				<?= $fld('label', '<input class="form-control' . $inv('label') . '" id="ufLabel" name="label" maxlength="200" value="' . h($val('label')) . '" required>') ?>
			</div>
			<div class="col-sm-6">
				<label class="form-label" for="ufCode"><?= h(t('uf_code')) ?><?= $f ? '' : ' <span class="text-danger">*</span>' ?><?= nu_unique_mark(!$f) ?></label>
				<?= nu_info_tip(t('uf_code_tip')) ?>
				<?= $fld('code', '<input class="form-control font-monospace' . $inv('code') . '" id="ufCode" name="code" maxlength="40" pattern="[a-z][a-z0-9_]*" autocomplete="off" spellcheck="false" value="' . h($val('code')) . '"' . ($f ? ' readonly' : ' required') . '>') ?>
			</div>
			<div class="col-sm-6 col-lg-4">
				<label class="form-label" for="ufType"><?= h(t('uf_type')) ?></label>
				<?php if ($f): ?>
					<input class="form-control" id="ufType" value="<?= h($typeName($type)) ?>" readonly data-type="<?= h($type) ?>">
				<?php else: ?>
					<select class="form-select<?= $inv('type') ?>" id="ufType" name="type">
						<?php foreach (array_keys(NuUserFields::TYPES) as $tk): ?><option value="<?= h($tk) ?>"<?= $type === $tk ? ' selected' : '' ?>><?= h($typeName($tk)) ?></option><?php endforeach; ?>
					</select>
				<?php endif; ?>
			</div>
			<div class="col-sm-6 col-lg-8">
				<label class="form-label" for="ufEditBy"><?= h(t('uf_edit_by')) ?></label>
				<select class="form-select ufEditBy" id="ufEditBy" name="edit_by">
					<?php foreach (NuUserFields::EDIT_BY as $e): ?><option value="<?= h($e) ?>"<?= ($val('edit_by') ?: 'admin') === $e ? ' selected' : '' ?>><?= h($editName($e)) ?></option><?php endforeach; ?>
				</select>
			</div>
			<div class="col-12 ufOnlySelect">
				<label class="form-label" for="ufOptions"><?= h(t('uf_options')) ?> <span class="text-danger">*</span></label>
				<?= nu_info_tip(t('uf_options_tip')) ?>
				<?= $fld('options', '<textarea class="form-control font-monospace' . $inv('options') . '" id="ufOptions" name="options" rows="5" spellcheck="false" placeholder="ZH|Zurich&#10;BE|Bern">' . h($val('options')) . '</textarea>') ?>
			</div>
			<div class="col-sm-6">
				<label class="form-label" for="ufDefault"><?= h(t('uf_default')) ?></label>
				<?= nu_info_tip(t('uf_default_tip')) ?>
				<?php
					$ins = [['today', 'ufd_today'], ['yesterday', 'ufd_yesterday'], ['tomorrow', 'ufd_tomorrow'], ['today+30d', 'ufd_in30'], ['month_end', 'ufd_month_end'], ['year_start', 'ufd_year_start'], ['now', 'ufd_now'], null,
						['next:{0000}', 'ufd_counter'], ['{first_name}.{last_name}', 'ufd_fields'], ['{email}', 'ufd_email'], ['#USER_NAME#', 'ufd_admin'], null];
					if (!empty(NuAuth::user()['globeadmin'])) { $ins[] = ['= SELECT ', 'ufd_sql']; $ins[] = ['proc:', 'ufd_proc']; }
					$menu = '';
					foreach ($ins as $it) $menu .= $it === null ? '<li><hr class="dropdown-divider"></li>' : '<li><button type="button" class="dropdown-item d-flex justify-content-between gap-3 ufdIns" data-ins="'
						. h($it[0]) . '"><span>' . h(t($it[1])) . '</span><code class="small">' . h($it[0]) . '</code></button></li>';
				?>
				<?= $fld('default_value', '<div class="input-group"><input class="form-control font-monospace' . $inv('default_value') . '" id="ufDefault" name="default_value" maxlength="1000" value="' . h($val('default_value')) . '" data-preview="' . h(nu_url('userfields', ['id' => $id])) . '">'
					. '<button type="button" class="btn btn-outline-secondary dropdown-toggle" id="ufDefaultIns" data-bs-toggle="dropdown" aria-expanded="false">' . h(t('ufd_insert')) . '</button>'
					. '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' . $menu . '</ul></div>'
					. '<div class="form-text" id="ufDefaultEx" aria-live="polite"></div>') ?>
			</div>
			<div class="col-sm-6">
				<label class="form-label" for="ufHint"><?= h(t('uf_hint')) ?></label>
				<input class="form-control" id="ufHint" name="hint" maxlength="1000" value="<?= h($val('hint')) ?>">
			</div>
			<div class="col-12 d-flex flex-wrap gap-4">
				<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="ufRequired" name="required"
					value="1"<?= $chk('required') ? ' checked' : '' ?>><label class="form-check-label" for="ufRequired"><?= h(t('uf_required')) ?></label></div>
				<div class="form-check form-switch ufOnlyUnique"><input class="form-check-input" type="checkbox" role="switch" id="ufUnique" name="is_unique"
					value="1"<?= $chk('is_unique') ? ' checked' : '' ?>><label class="form-check-label" for="ufUnique"><?= h(t('uf_unique')) ?></label></div>
				<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="ufInList" name="in_list"
					value="1"<?= $chk('in_list', true) ? ' checked' : '' ?>><label class="form-check-label" for="ufInList"><?= h(t('uf_in_list')) ?></label>
					<?= nu_info_tip(t('uf_in_list_tip')) ?></div>
			</div>
			<?php if ($f): ?>
				<div class="col-12 small text-body-secondary"><i class="bi bi-database me-1"></i><?= h(t('uf_sql_hint', (string)$f['code'])) ?></div>
			<?php endif; ?>
		</div></div></div>
		</fieldset>
	</form>
	<?php if ($canChange && $f): ?>
		<form method="post" action="<?= h(nu_url('userfields', ['id' => $id])) ?>" id="ufDelete" data-confirm="<?= h(t('uf_delete_confirm', NuUserFields::label($f), $values)) ?>"
			data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action"
				value="delete"><input type="hidden" name="id" value="<?= h($id) ?>"></form>
		<p class="small mt-3"><a href="<?= h(nu_url('userfields', ['csv' => $id])) ?>" id="ufCsv"><i class="bi bi-filetype-csv me-1"></i><?= h(t('uf_csv', $values)) ?></a></p>
	<?php endif; ?>
	<?php
	nu_layout_end('<script src="' . nu_asset('assets/js/nuuserfields.js') . '"></script>');
	return;
}

// ------------------------------------------------------------------ the list (a system list – views, columns, filters, print / export, core/nulistdefs.php)
$tip = t('uf_sub', t('user_tab_more')) . ' ' . t('uf_list_hint');
NuListView::page('userfields', [
	'before' => NuListView::crumbs([[t('nav_users'), nu_url('users')], [t('uf_title')]]), 'active' => 'users',
	'title_extra' => NuListView::infoTip($tip),
	'buttons' => $canChange ? '<a class="btn nuAddBtn" href="' . h(nu_url('userfields', ['id' => 'new'])) . '" id="ufAdd"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '',
]);
