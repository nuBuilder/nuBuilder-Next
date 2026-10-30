<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Settings centre → nuBuilder Forte (0.8.702: ?p=settings&s=forte, included by pages/nusettings.php)
 * nuBuilder's settings (Setup → Settings, zzzzsys_config) – globeadmin. Logic in core/nusettings.php, JavaScript in assets/js/nusettings.js.
 * POST action save (values[<zzzzsys_config_id>]) | import (values of nuconfig.php into the table).
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('settings');
NuSecurity::requireRecentAuth(nu_url('settings', ['s' => 'forte']));   // password again when the last confirmation is older than 15 minutes (0.8.207)

$errors = [];
$posted = [];

// table missing (database installed without nuBuilder's SQL, older nuBuilder) → create it with nuBuilder's defaults
if (nu_is_post() && nu_post('action') === 'create') {
	try {
		$n = NuSettings::create();
		NuLog::write('settings_created', NuSettings::TABLE, '', ['rows' => $n]);
		nu_flash('success', t('set_created', $n));
	} catch (Throwable $e) {
		error_log('nuBuilder Next settings: ' . $e->getMessage());
		nu_flash('danger', t('set_create_failed') . ' ' . $e->getMessage());
	}
	nu_redirect(nu_url('settings', ['s' => 'forte']));
}
$available = NuSettings::available();

if ($available && nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'import') {
		$changed = NuSettings::import();
		nu_flash('success', $changed ? t('set_imported', count($changed), implode(', ', array_keys($changed))) : t('set_imported_none'));
		nu_redirect(nu_url('settings', ['s' => 'forte']));
	}
	if ($action === 'use_db') {                            // "Use these settings": $nuConfigSettingsFromDB = true in nuconfig.php
		$err = NuConfigFile::setVariable('nuConfigSettingsFromDB', 'true');
		if ($err === '') {
			NuLog::write('settings_from_db', 'nuconfig.php', '', ['nuConfigSettingsFromDB' => true]);
			nu_flash('success', t('set_use_db_done'));
		} else {
			nu_flash('danger', t('set_use_db_failed') . ' ' . $err);
		}
		nu_redirect(nu_url('settings', ['s' => 'forte']));
	}
	if ($action === 'save') {
		$posted = array_filter(is_array($_POST['values'] ?? null) ? $_POST['values'] : [], 'is_string');
		[$changed, $errors, $effective] = NuSettings::save($posted);
		if (!$errors) {
			nu_flash('success', $changed ? t('set_saved', count($changed)) : t('set_saved_none'));
			if ($changed && $effective >= 2) nu_flash('info', t($effective === 2 ? 'set_effective_login' : 'set_effective_browser'));
			nu_redirect(nu_url('settings', ['s' => 'forte']));
		}
	}
}

$rows = $available ? NuSettings::rows() : [];
$categories = array_values(array_unique(array_filter(array_map(static fn($r) => (string)$r['cfg_category'], $rows))));
$fromDb = NuSettings::fromDb();
$aceThemes = NuSettings::ACE_THEMES;

/** Value control by cfg_type: 2 true/false · 3 number · 9 Ace theme · otherwise text (with suggestions). */
$control = static function (array $r, string $value, bool $invalid) use ($aceThemes): string {
	if (NuDemo::active() && NuDemo::secretSetting((string)$r['cfg_setting'])) $value = $r['cfg_value'] = '••••••••';   // demo mode: no passwords / keys
	$id = 'v_' . preg_replace('/\W/', '_', (string)$r['zzzzsys_config_id']);
	$attr = ' class="%s setValue' . ($invalid ? ' is-invalid' : '') . '" id="' . h($id) . '" name="values[' . h((string)$r['zzzzsys_config_id']) . ']"'
		. ' data-org="' . h((string)($r['cfg_value'] ?? '')) . '" aria-label="' . h((string)$r['cfg_title']) . '"';
	switch ((int)$r['cfg_type']) {
		case 2:
			$o = in_array($value, ['true', 'false'], true) ? '' : '<option value="' . h($value) . '" selected>' . h($value) . '</option>';
			foreach (['true', 'false'] as $b) $o .= '<option value="' . $b . '"' . ($value === $b ? ' selected' : '') . '>' . $b . '</option>';
			return sprintf('<select' . $attr . '>', 'form-select form-select-sm') . $o . '</select>';
		case 3:
			return sprintf('<input type="number" step="1"' . $attr . ' value="' . h($value) . '">', 'form-control form-control-sm');
		case 9:
			$o = '<option value=""' . ($value === '' ? ' selected' : '') . '></option>';
			foreach ($aceThemes as $group => $themes) {
				$o .= '<optgroup label="' . h($group) . '">';
				foreach ($themes as $k => $l) $o .= '<option value="' . h($k) . '"' . ($value === $k ? ' selected' : '') . '>' . h($l) . '</option>';
				$o .= '</optgroup>';
			}
			return sprintf('<select' . $attr . '>', 'form-select form-select-sm') . $o . '</select>';
		default:
			$list = NuSettings::CHOICES[(string)$r['cfg_setting']] ?? null;
			$dl = $list ? '<datalist id="' . h($id) . '_list">' . implode('', array_map(static fn($c) => '<option value="' . h($c) . '">', $list)) . '</datalist>' : '';
			return sprintf('<input type="text" maxlength="1000"' . $attr . ($list ? ' list="' . h($id) . '_list" autocomplete="off"' : '') . ' value="' . h($value) . '">', 'form-control form-control-sm') . $dl;
	}
};

nu_layout_start(t('sh_forte'), 'home', ['container' => 'nuContainer', 'settings' => 'forte']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item"><a href="<?= h(nu_url('settings')) ?>"><?= h(t('set_title')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h(t('sh_forte')) ?></li>
</ol></nav>

<?php if (!$available): ?>
	<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('set_missing', NuSettings::database())) ?>
		<?php if (NuSettings::$problem !== ''): ?><div class="small mt-2 font-monospace text-break"><?= h(NuSettings::$problem) ?></div><?php endif; ?>
		<form method="post" action="<?= h(nu_url('settings', ['s' => 'forte'])) ?>" class="mt-3" data-confirm="<?= h(t('set_create_confirm', NuSettings::database())) ?>" data-confirm-ok="<?= h(t('set_create')) ?>">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="create">
			<button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i><?= h(t('set_create')) ?></button>
			<span class="small ms-2"><?= h(t('set_create_hint')) ?></span>
		</form></div>
<?php else: ?>
<form method="post" action="<?= h(nu_url('settings', ['s' => 'forte'])) ?>" id="setForm" novalidate data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
</form>
<form method="post" action="<?= h(nu_url('settings', ['s' => 'forte'])) ?>" id="setImport" data-confirm="<?= h(t('set_import_confirm')) ?>" data-confirm-ok="<?= h(t('set_import')) ?>">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="import">
</form>

<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold"><?= h(t('sh_forte')) ?></h1>
		<div class="nuTitleSub text-body-secondary small"><?= h(t('set_hint')) ?></div>
		<div class="d-flex gap-2"><?= nu_back_button() ?>
			<button class="btn nuBtnAct nuBtnSave" type="submit" form="setForm" id="setSave" data-nu-save><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
			<button class="btn btn-outline-secondary" type="submit" form="setImport" id="setImportBtn" title="<?= h(t('set_import_hint')) ?>"><i class="bi bi-box-arrow-in-down me-1"></i><?= h(t('set_import')) ?></button>
		</div>
	</div>
	<?php if (!$fromDb): ?>
		<div class="alert alert-warning small rounded-0 border-start-0 border-end-0 mb-0 d-flex flex-wrap align-items-center gap-2">
			<span class="flex-grow-1"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('set_not_used')) ?></span>
			<?php if (NuConfigFile::writable()): ?>
				<form method="post" action="<?= h(nu_url('settings', ['s' => 'forte'])) ?>" class="m-0" data-confirm="<?= h(t('set_use_db_confirm')) ?>" data-confirm-ok="<?= h(t('set_use_db')) ?>">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="use_db">
					<button class="btn btn-sm btn-warning text-nowrap" type="submit" id="setUseDb"><i class="bi bi-toggle-on me-1"></i><?= h(t('set_use_db')) ?></button>
				</form>
			<?php else: ?>
				<span class="text-body-secondary"><?= h(t('cfg_err_not_writable', NuConfigFile::path())) ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php if ($errors): ?>
		<div class="alert alert-danger small rounded-0 border-start-0 border-end-0 mb-0"><?= h(t('err_check_form')) ?></div>
	<?php endif; ?>
	<div class="nuListFilter">
		<div class="row g-2 align-items-end">
			<div class="col-md-5"><input type="search" class="form-control" id="setFind" placeholder="<?= h(t('search_ph')) ?>" aria-label="<?= h(t('search')) ?>" data-nu-autofocus></div>
			<div class="col-sm-6 col-md-3">
				<select class="form-select" id="setCategory" aria-label="<?= h(t('set_category')) ?>">
					<option value=""><?= h(t('set_all_categories')) ?></option>
					<?php foreach ($categories as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?>
				</select>
			</div>
			<div class="col-12">   <?php /* filter by the nuBuilder Next tag, with counts (0.8.280) */ ?>
				<div class="d-flex flex-wrap align-items-center gap-2" id="setStatus" role="radiogroup" aria-label="<?= h(t('set_st_filter')) ?>">
					<span class="small text-body-secondary me-1"><?= h(t('set_st_filter')) ?>:</span>
					<?php // opens on "Used" (0.8.575); after a save with errors on "All", so no row with an error is hidden
					$stDefault = $errors ? '' : 'next';
					$stCount = array_count_values(array_map(static fn($r) => (NuSettings::STATUS[(string)$r['cfg_setting']] ?? [''])[0], $rows));
					foreach (['' => 'all', 'next' => 'next', 'open' => 'open', 'na' => 'na'] as $v => $k): $rid = 'setSt_' . ($v ?: 'all'); ?>
						<input type="radio" class="btn-check" name="setStatus" id="<?= $rid ?>" value="<?= $v ?>" autocomplete="off"<?= $v === $stDefault ? ' checked' : '' ?>>
						<label class="btn btn-sm btn-outline-secondary setStBtn<?= $v !== '' ? ' setStBtn-' . $v : '' ?>" for="<?= $rid ?>"><?= h($v === '' ? t('all') : t('set_st_' . $k)) ?>
							<span class="badge rounded-pill text-bg-light border ms-1"><?= $v === '' ? count($rows) : (int)($stCount[$v] ?? 0) ?></span></label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
	<div class="table-responsive">
		<table class="table align-middle mb-0 nu-table setTable" id="setTable">
			<thead><tr><th><?= h(t('set_category')) ?></th><th><?= h(t('set_name')) ?></th><th><?= h(t('set_setting')) ?></th><th class="setValueCol"><?= h(t('set_value')) ?></th><th class="setDescCol"><?= h(t('set_description')) ?></th></tr></thead>
			<tbody>
			<?php foreach ($rows as $r):
				$id = (string)$r['zzzzsys_config_id'];
				$value = array_key_exists($id, $posted) ? trim($posted[$id]) : (string)($r['cfg_value'] ?? '');
				[$st, $why] = NuSettings::STATUS[(string)$r['cfg_setting']] ?? ['', ''];
				$eff = (string)$r['cfg_effective'];
				$desc = (string)$r['cfg_description']; ?>
				<tr data-category="<?= h((string)$r['cfg_category']) ?>" data-status="<?= h($st) ?>">
					<td class="small text-body-secondary text-nowrap"><?= h((string)$r['cfg_category']) ?></td>
					<td class="fw-semibold"><?= h((string)$r['cfg_title']) ?>
						<?php if ($desc !== ''): // narrow screens (< 800 px): the description column is hidden – (i) shows it on tap / click (0.8.242) ?><span class="nuInfoTip setDescTip ms-1" tabindex="0" role="button" data-bs-toggle="tooltip" data-bs-trigger="hover focus click" data-bs-custom-class="nuTipWide" aria-label="<?= h($desc) ?>" title="<?= h($desc) ?>"><i class="bi bi-info-circle"></i></span><?php endif; ?>
						<?php if ($st !== ''): // what the setting means for nuBuilder Next (0.8.279) ?><span class="badge border fw-normal ms-1 setSt setSt-<?= $st ?>" tabindex="0" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" title="<?= h(t('set_r_' . $why)) ?>"><?= h(t('set_st_' . $st)) ?></span><?php endif; ?>
						<?php if ($eff === '2' || $eff === '3'): ?><i class="bi bi-arrow-repeat text-body-secondary ms-1" role="img" title="<?= h(t($eff === '2' ? 'set_effective_login' : 'set_effective_browser')) ?>" aria-label="<?= h(t($eff === '2' ? 'set_effective_login' : 'set_effective_browser')) ?>"></i><?php endif; ?></td>
					<td><code class="small text-break"><?= h((string)$r['cfg_setting']) ?></code></td>
					<td class="setValueCol"><?= $control($r, $value, isset($errors[$id])) ?><?php if (isset($errors[$id])): ?><div class="invalid-feedback d-block"><?= h($errors[$id]) ?></div><?php endif; ?></td>
					<td class="small text-body-secondary setDesc setDescCol" title="<?= h($desc) ?>"><?= h($desc) ?></td>
				</tr>
			<?php endforeach; ?>
			<tr class="setNone" hidden><td colspan="5" class="text-center text-body-secondary py-4"><?= h(t('no_records')) ?></td></tr>
			</tbody>
		</table>
	</div>
	<div class="card-footer small text-body-secondary d-flex flex-wrap gap-3 justify-content-between">
		<span id="setCount" data-of="<?= h(t('set_count')) ?>"><?= h(t('set_count', count($rows), count($rows))) ?></span>
		<span class="d-flex flex-wrap gap-3 align-items-center"><?php foreach (['next', 'open', 'na'] as $st): ?><span><span class="badge border fw-normal setSt setSt-<?= $st ?>"><?= h(t('set_st_' . $st)) ?></span> <?= h(t('set_st_' . $st . '_legend')) ?></span><?php endforeach; ?></span>
		<span><i class="bi bi-arrow-repeat me-1"></i><?= h(t('set_effective_legend')) ?></span>
	</div>
</div>
<?php endif; ?>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nusettings.js') . '"></script>');
