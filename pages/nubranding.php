<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Branding of this installation (0.8.297) – globeadmin, Admin tools → Branding. Logic in core/nubranding.php, live preview in
 * assets/js/nubranding.js. POST action save (multipart: logo_light, logo_dark …) | reset.
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('branding');

$errors = [];
$upload = static function (string $field, array &$errors): ?array {
	$f = $_FILES[$field] ?? null;
	if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
	if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) { $errors[$field] = t('brand_err_size', (int)(NuBranding::MAX_BYTES / 1024)); return null; }
	if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $errors[$field] = t('file_err_upload'); return null; }
	return ['content' => (string)file_get_contents($f['tmp_name']), 'name' => (string)$f['name']];
};

$b0 = NuBranding::get();
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'hide_logo') {                          // right-click on the logo in the top bar (0.8.734): sign-in pages keep it
		$changed = NuBranding::hideTopLogo();
		nu_flash('success', $changed ? t('brand_hidden_msg', t('sh_title') . ' → ' . t('sh_look') . ' → ' . t('brand_title') . ' (' . t('brand_logo_show') . ')') : t('set_saved_none'));
		nu_redirect(NuAuth::validNext(nu_post('back')) ?? nu_url('home'));
	}
	if ($action === 'reset') {
		NuBranding::reset();
		nu_flash('success', t('brand_reset_done'));
		nu_redirect(nu_url('branding'));
	}
	if ($action === 'save') {
		$files = ['light' => $upload('logo_light', $errors), 'dark' => $upload('logo_dark', $errors)];
		if (!$errors) {
			[$errors, $warn] = NuBranding::save([
				'accent_on' => $b0['accent'] !== '', 'accent' => $b0['accent'],   // the accent colour is part of the themes (0.8.632) – an older one is kept
				 'show_title' => nu_check('show_title') === '1', 'title' => nu_post('title'),
				'favicon' => nu_check('favicon') === '1', 'logo_show' => nu_post('logo_show'), 'env_label' => nu_post('env_label'), 'env_color' => nu_post('env_color'),
			], $files, ['light' => nu_check('remove_light') === '1', 'dark' => nu_check('remove_dark') === '1']);
			if (!$errors) {
				nu_flash('success', t('brand_saved'));
				foreach ($warn as $w) nu_flash('warning', $w);
				nu_redirect(nu_url('branding'));
			}
		}
	}
}

$b = NuBranding::get();
$envCfg = NuBranding::envFromConfig();
$appTitle = nu_app_title(true);
$cfgTitle = is_file(nu_config_path()) ? trim((string)(nu_cfg('nuConfigTitle') ?? '')) : '';   // nuBuilder Forte's title – used while Title is empty
$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
$inv = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$fb = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . h($errors[$k]) . '</div>' : '';
$accept = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml,.svg';
$accent = $b['accent'] !== '' ? $b['accent'] : '#2f6fd6';

nu_layout_start(t('brand_title'), 'home', ['settings' => 'look', 'settings_page' => 'branding']);
?>
<form method="post" action="<?= h(nu_url('branding')) ?>" id="brandReset" data-confirm="<?= h(t('brand_reset_confirm')) ?>" data-confirm-ok="<?= h(t('brand_reset')) ?>" data-confirm-class="btn btn-danger">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="reset">
</form>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold"><?= h(t('brand_title')) ?></h1>
		<div class="nuTitleSub text-body-secondary small"><?= h(t('brand_hint')) ?></div>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?>
			<button class="btn nuBtnAct nuBtnSave" type="submit" form="brandForm" id="brandSave" data-nu-save><i class="bi bi-floppy"></i><?= h(t('save')) ?></button>
			<button class="btn nuBtnAct" type="submit" form="brandReset" id="brandResetBtn"><i class="bi bi-arrow-counterclockwise"></i><?= h(t('brand_reset')) ?></button>
		</div>
	</div>
	<div class="card-body">
		<form method="post" action="<?= h(nu_url('branding')) ?>" id="brandForm" enctype="multipart/form-data" novalidate data-nu-guard>
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
			<div class="row g-4">
				<div class="col-lg-7">
					<?php foreach (['light' => [$b['logo'], 'brand_logo', 'brand_logo_tip'], 'dark' => [$b['logo_dark'], 'brand_logo_dark', 'brand_logo_dark_tip']] as $k => [$has, $lbl, $tp]): ?>
					<div class="mb-4">
						<label class="form-label fw-semibold" for="logo_<?= $k ?>"><?= h(t($lbl)) ?></label><?= $tip(t($tp, (int)(NuBranding::MAX_BYTES / 1024))) ?>
						<?php if ($has): ?>
							<div class="mb-2"><img class="brandLogoPrev<?= $k === 'dark' ? ' brandLogoPrevDark' : '' ?>" src="<?= h(NuBranding::logoUrl($k === 'dark')) ?>" alt=""></div>
						<?php endif; ?>
						<input class="form-control<?= $inv('logo_' . $k) ?>" type="file" id="logo_<?= $k ?>" name="logo_<?= $k ?>" accept="<?= h($accept) ?>" data-brand-logo="<?= $k ?>">
						<?= $fb('logo_' . $k) ?>
						<?php if ($has): ?>
							<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="remove_<?= $k ?>" name="remove_<?= $k ?>" value="1">
								<label class="form-check-label" for="remove_<?= $k ?>"><?= h(t('brand_remove')) ?></label></div>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>

					<div class="mb-3">
						<label class="form-label fw-semibold" for="logo_show"><?= h(t('brand_logo_show')) ?></label><?= $tip(t('brand_logo_show_tip')) ?>
						<select class="form-select brandLogoShow" id="logo_show" name="logo_show">
							<?php foreach (NuBranding::LOGO_SHOW as $w): ?><option value="<?= $w ?>"<?= $b['logo_show'] === $w ? ' selected' : '' ?>><?= h(t('brand_logo_show_' . $w)) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="mb-2">
						<label class="form-label fw-semibold" for="title"><?= h(t('brand_app_title')) ?></label><?= $tip(t('brand_app_title_tip', $cfgTitle !== '' ? $cfgTitle : t('brand_title_unset'))) ?>
						<input class="form-control" type="text" id="title" name="title" maxlength="<?= NuBranding::TITLE_LEN ?>" value="<?= h($b['title']) ?>" placeholder="<?= h($cfgTitle !== '' ? $cfgTitle : 'nuBuilder Next') ?>" data-cfg="<?= h($cfgTitle) ?>">
					</div>
					<div class="form-check form-switch mb-2 d-flex align-items-center gap-1">
						<input class="form-check-input me-1" type="checkbox" role="switch" id="show_title" name="show_title" value="1"<?= $b['show_title'] ? ' checked' : '' ?>>
						<?php // the label follows "Show logo" (0.8.736): next to the logo, or alone in the top bar ?>
						<label class="form-check-label" for="show_title" id="show_title_lbl" data-logo="<?= h(t('brand_show_title')) ?>" data-bar="<?= h(t('brand_show_title_bar')) ?>"><?= h(t($b['logo_show'] === 'all' ? 'brand_show_title' : 'brand_show_title_bar')) ?></label><?= $tip(t('brand_show_title_tip2')) ?>
					</div>
					<div class="form-check form-switch mb-4 d-flex align-items-center gap-1">
						<input class="form-check-input me-1" type="checkbox" role="switch" id="favicon" name="favicon" value="1"<?= $b['favicon'] ? ' checked' : '' ?>>
						<label class="form-check-label" for="favicon"><?= h(t('brand_favicon')) ?></label><?= $tip(t('brand_favicon_tip')) ?>
					</div>

					<div class="alert alert-light border small d-flex align-items-center gap-2 mb-4" id="brandThemeNote"><i class="bi bi-brush"></i>
						<span><?= h(t('brand_theme_note')) ?> <a href="<?= h(nu_url('themes')) ?>" id="brandThemeLink"><?= h(t('theme_title')) ?></a></span></div>

					<div class="mb-2">
						<label class="form-label fw-semibold" for="env_label"><?= h(t('brand_env')) ?></label><?= $tip(t('brand_env_field_tip')) ?>
						<div class="d-flex align-items-center gap-2">
							<input class="form-control" type="text" id="env_label" name="env_label" maxlength="<?= NuBranding::ENV_LEN ?>" placeholder="TEST" value="<?= h($b['env_label']) ?>"<?= $envCfg ? ' readonly' : '' ?>>
							<input class="form-control form-control-color flex-shrink-0" type="color" id="env_color" name="env_color" value="<?= h($b['env_color']) ?>" aria-label="<?= h(t('brand_env_color')) ?>" title="<?= h(t('brand_env_color')) ?>">
						</div>
						<?php if ($envCfg): ?><div class="form-text"><i class="bi bi-filetype-php me-1"></i><?= h(t('brand_env_config')) ?></div><?php endif; ?>
					</div>
				</div>

				<div class="col-lg-5">
					<div class="fw-semibold mb-2"><?= h(t('brand_preview')) ?></div>
					<div class="brandPreviewBox" id="brandPreview" data-default-logo="<?= h(nu_asset('assets/img/nunext-logo.png')) ?>" data-own-logo="<?= $b['logo'] ? h(NuBranding::logoUrl(false)) : '' ?>">
						<div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom">
							<img class="brandLogoPrev" id="prevLogo" src="<?= h($b['logo'] ? NuBranding::logoUrl(false) : nu_asset('assets/img/nunext-logo.png')) ?>" alt="">
							<span class="nu-brand-title" id="prevTitle"<?= $b['show_title'] && $appTitle !== '' ? '' : ' hidden' ?>><?= h($appTitle) ?></span>
							<span class="nuEnvLabel ms-auto" id="prevEnv"<?= $b['env_label'] !== '' ? '' : ' hidden' ?>><?= h($b['env_label']) ?></span>
						</div>
						<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
							<button type="button" class="btn btn-primary" id="prevBtn" tabindex="-1"><?= h(t('brand_preview_button')) ?></button>
							<button type="button" class="btn btn-outline-primary" id="prevBtnOutline" tabindex="-1"><?= h(t('brand_preview_button')) ?></button>
							<a href="#" class="ms-2" id="prevLink" tabindex="-1"><?= h(t('brand_preview_link')) ?></a>
						</div>
						<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="prevCheck" checked tabindex="-1"><label class="form-check-label" for="prevCheck"><?= h(t('brand_preview_check')) ?></label></div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nubranding.js') . '"></script>');
