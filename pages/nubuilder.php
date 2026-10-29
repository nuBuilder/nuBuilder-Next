<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * "New form" wizard (?new=1, assets/js/nubuilder.js). ?f=<form id>: page forms open their own editor (pages/nubuilder_page.php), every
 * other form is designed in design mode on the form page (0.8.670 – the builder's editor was replaced by it, core/form/nudesign.php).
 */

NuAcl::require(NuAcl::FORM_FORM);
$canSave = NuAcl::can(NuAcl::FORM_FORM, 'save');
$db      = nu_db();
$isNew   = ($_GET['new'] ?? '') === '1' || (string)($_GET['f'] ?? '') === '';
$meta    = null;

if (!$isNew) {
	$meta = NuFormMeta::load((string)($_GET['f'] ?? ''), true);     // the live form – a draft is loaded by the builder API (0.8.386)
	if (!$meta) {
		http_response_code(404);
		nu_fatal(t('form_err_not_found'), '', true);
	}
}

// form type "page" (0.8.387): its own builder – form fields and the page settings, no objects (pages/nubuilder_page.php)
if ($meta && $meta->isPage()) {
	require __DIR__ . '/nubuilder_page.php';
	exit;
}

// every other form is designed where it runs (0.8.670: the builder was replaced by design mode, core/form/nudesign.php) – old links and
// bookmarks (?obj=, ?open=, ?colchg=, ?delete=1) lead to the matching place in design mode
if ($meta) nu_redirect(NuDesign::fromBuilder($meta, $_GET));

// labels for builder.js
$keys = ['save', 'delete', 'cancel', 'add', 'remove', 'remove_undo', 'remove_pending', 'saved', 'clone', 'back', 'please_select', 'search', 'yes', 'no', 'no_records'];
foreach ([__DIR__ . '/../lang/en.php'] as $f) foreach (array_keys(require $f) as $k) if (str_starts_with($k, 'builder_') || str_starts_with($k, 'bf_') || str_starts_with($k, 'form_type_') || str_starts_with($k, 'form_layout')) $keys[] = $k;
$labels = [];
foreach ($keys as $k) $labels[$k] = t($k);

$data = ['csrf' => nu_csrf_token(), 'labels' => $labels, 'can_save' => $canSave, 'new' => true];

$head = '<link rel="stylesheet" href="' . nu_asset('assets/css/nubuilder.css') . '">'
	. '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">';   // searchable selects: Table (new form), form lists (0.8.535)
$foot = '<script type="application/json" id="nuBuilderData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
	. '<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nubuilder.js') . '"></script>';

nu_layout_start(t('builder_new_form'), 'forms', ['head' => $head, 'container' => 'container-fluid nuBuilderContainer']);
$crumb = '<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="' . h(nu_url('forms')) . '">' . h(t('nav_forms')) . '</a></li>'
	. '<li class="breadcrumb-item active" aria-current="page">' . h(t('builder_new_form')) . '</li></ol></nav>';
?>
<?php if ($isNew): ?>
	<div class="row justify-content-center">
		<div class="col-lg-10 col-xl-8">
			<?= $crumb   // inside the centred column, so it lines up with the card (0.8.249) ?>
			<div class="d-flex flex-wrap align-items-center gap-2 mb-3"><h1 class="h3 mb-0 me-auto"><i class="bi bi-magic me-2"></i><?= h(t('builder_new_form')) ?></h1>
				<a class="btn btn-outline-secondary" id="nbAiNew" href="<?= h(nu_url('formai')) ?>"><i class="bi bi-stars me-1"></i><?= h(t('fai_button_new')) ?></a></div>
			<form class="card shadow-sm" id="nbNewForm" novalidate>
				<div class="card-body p-4">
					<div class="alert alert-warning nbStale" id="nbStale" role="alert"><i class="bi bi-arrow-clockwise me-1"></i><?= h(t('builder_stale_assets')) ?></div>
					<?php // three steps (0.8.663): Form · Table and fields · Layout and access – nubuilder.js shows one at a time ?>
					<ol class="nbSteps" id="nbSteps" aria-label="<?= h(t('nf_steps')) ?>">
						<?php foreach (['nf_step_form', 'nf_step_table', 'nf_step_layout'] as $si => $sk): ?>
							<li class="nbStepMark<?= $si === 0 ? ' active' : '' ?>" data-step="<?= $si + 1 ?>"<?= $si === 0 ? ' aria-current="step"' : '' ?>><span class="nbStepNo"><?= h(t('nf_step_n', $si + 1)) ?></span><?= h(t($sk)) ?></li>
						<?php endforeach; ?>
					</ol>
					<div class="row g-3 nbStep" data-step="1">
						<div class="col-sm-7">
							<label class="form-label" for="nb_desc"><?= h(t('builder_description')) ?> <span class="text-danger">*</span></label>
							<input class="form-control" id="nb_desc" name="description" required maxlength="300">
						</div>
						<div class="col-sm-5">
							<label class="form-label" for="nb_code"><?= h(t('builder_code')) ?> <span class="text-danger">*</span><?= nu_unique_mark(true) ?></label>
							<input class="form-control" id="nb_code" name="code" required maxlength="300" pattern="[A-Za-z0-9_]+" autocomplete="off" spellcheck="false">
						</div>
						<div class="col-sm-6 col-lg-4">
							<label class="form-label" for="nb_type"><?= h(t('builder_type')) ?></label>
							<select class="form-select" id="nb_type" name="type">
								<?php foreach (['browseedit', 'edit', 'browse', 'subform', 'launch', 'page'] as $tp): ?><option value="<?= $tp ?>"><?= h(t('form_type_' . $tp)) ?></option><?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="row g-3 nbStep" data-step="2" hidden>
						<div class="col-12 nbNoTable d-none"><p class="text-body-secondary mb-0"><?= h(t('nf_no_table')) ?></p></div>
						<div class="col-12 nbTableSection">
							<fieldset>
								<legend class="form-label fs-6 mb-1"><?= h(t('builder_table')) ?></legend>
								<div class="btn-group" role="group">
									<input type="radio" class="btn-check" name="table_mode" id="nb_mode_existing" value="existing" checked>
									<label class="btn btn-outline-primary" for="nb_mode_existing"><i class="bi bi-table me-1"></i><?= h(t('builder_table_existing')) ?></label>
									<input type="radio" class="btn-check" name="table_mode" id="nb_mode_new" value="new">
									<label class="btn btn-outline-primary" for="nb_mode_new"><i class="bi bi-plus-square me-1"></i><?= h(t('builder_table_new')) ?></label>
								</div>
							</fieldset>
						</div>

						<!-- existing table -->
						<div class="col-sm-7 nbExisting">
							<label class="form-label" for="nb_table"><?= h(t('builder_table')) ?> <span class="text-danger">*</span></label>
							<select class="form-select" id="nb_table"><option value=""><?= h(t('please_select')) ?></option></select>
						</div>
						<div class="col-sm-5 nbExisting">
							<label class="form-label" for="nb_pk"><?= h(t('builder_primary_key')) ?></label>
							<select class="form-select" id="nb_pk"><option value=""><?= h(t('builder_pk_auto')) ?></option></select>
						</div>
						<div class="col-12 nbExisting">
							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" role="switch" id="nb_generate" value="1" checked>
								<label class="form-check-label" for="nb_generate"><?= h(t('builder_generate')) ?></label>
							</div>
							<div class="form-text" id="nbColumnInfo"></div>
							<?php // chosen columns (0.8.675): which columns get an object / a list column, label, order – filled by nubuilder.js pickBuild() ?>
							<div class="nbPickWrap mt-2" id="nbPickWrap" hidden>
								<button class="btn btn-link px-0 text-decoration-none nuChevToggle collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#nbPick" aria-expanded="false" aria-controls="nbPick" id="nbPickBtn"><i class="bi bi-chevron-right me-1"></i><?= h(t('builder_pick_toggle')) ?> <span class="badge rounded-pill text-bg-secondary ms-1" id="nbPickCount"></span></button>
								<div class="collapse" id="nbPick">
									<p class="small text-body-secondary mb-2"><?= h(t('builder_pick_hint')) ?></p>
									<div class="table-responsive border rounded nbPickBox">
										<table class="table table-sm align-middle mb-0 nbPickGrid">
											<thead><tr>
												<th scope="col" class="nbPickC"><input class="form-check-input" type="checkbox" id="nbPickAll" aria-label="<?= h(t('builder_pick_all')) ?>" title="<?= h(t('builder_pick_all')) ?>"></th>
												<th scope="col" class="small"><?= h(t('builder_pick_col')) ?></th>
												<th scope="col" class="small"><?= h(t('builder_obj_label')) ?></th>
												<th scope="col" class="small text-center text-nowrap"><input class="form-check-input me-1" type="checkbox" id="nbPickAllList" aria-label="<?= h(t('builder_pick_all_list')) ?>" title="<?= h(t('builder_pick_all_list')) ?>"><?= h(t('builder_pick_list')) ?></th>
												<th scope="col"><span class="visually-hidden"><?= h(t('actions')) ?></span></th>
											</tr></thead>
											<tbody id="nbPickRows"></tbody>
										</table>
									</div>
									<button class="btn btn-sm btn-outline-secondary mt-2" type="button" id="nbPickReset"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('builder_pick_reset')) ?></button>
								</div>
							</div>
						</div>

						<!-- new table -->
						<div class="col-sm-7 nbNew d-none">
							<label class="form-label" for="nb_new_table"><?= h(t('builder_new_table_name')) ?> <span class="text-danger">*</span></label>
							<input class="form-control" id="nb_new_table" maxlength="64" pattern="[A-Za-z_][A-Za-z0-9_]*" autocomplete="off" spellcheck="false">
						</div>
						<div class="col-sm-5 nbNew d-none">
							<label class="form-label" for="nb_new_pk"><?= h(t('builder_primary_key')) ?></label><?php // the help as (i) next to the label (0.8.656, was a line below the field) ?>
							<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('builder_new_pk_help')) ?>" title="<?= h(t('builder_new_pk_help')) ?>"><i class="bi bi-info-circle"></i></span>
							<div class="input-group">
								<input class="form-control" id="nb_new_pk" maxlength="64" autocomplete="off" spellcheck="false">
								<select class="form-select flex-grow-0 w-auto" id="nb_new_pk_type" aria-label="<?= h(t('builder_pk_type')) ?>">
									<option value="id"><?= h(t('builder_pk_type_id')) ?></option>
									<option value="uuid"><?= h(t('builder_pk_type_uuid')) ?></option>
								</select>
							</div>
						</div>
						<div class="col-12 nbNew d-none">
							<label class="form-label mb-1"><?= h(t('builder_new_columns')) ?></label>
							<div class="table-responsive">
								<table class="table table-sm align-middle mb-2 nbColGrid">
									<thead><tr>
										<th scope="col" class="small"><?= h(t('builder_col_name')) ?></th>
										<th scope="col" class="small"><?= h(t('builder_obj_label')) ?></th>
										<th scope="col" class="small"><?= h(t('builder_col_type')) ?></th>
										<th scope="col" class="small"><?= h(t('builder_col_length')) ?></th>
										<th scope="col" class="small text-center"><?= h(t('builder_col_required')) ?></th>
										<th scope="col"><span class="visually-hidden"><?= h(t('actions')) ?></span></th>
									</tr></thead>
									<tbody id="nbColRows"></tbody>
								</table>
							</div>
							<button type="button" class="btn btn-outline-primary btn-sm" id="nbAddCol"><i class="bi bi-plus-lg me-1"></i><?= h(t('builder_add_column')) ?></button>
							<div class="form-check form-switch mt-3">
								<input class="form-check-input" type="checkbox" role="switch" id="nb_audit" value="1">
								<label class="form-check-label" for="nb_audit"><?= h(t('builder_audit')) ?></label>
								<div class="form-text"><?= h(t('builder_audit_help')) ?></div>
							</div>
							<div class="form-check form-switch mt-2">
								<input class="form-check-input" type="checkbox" role="switch" id="nb_nulog" value="1">
								<label class="form-check-label" for="nb_nulog"><?= h(t('builder_nulog')) ?></label>
								<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('builder_nulog_tip')) ?>" title="<?= h(t('builder_nulog_tip')) ?>"><i class="bi bi-info-circle"></i></span>
							</div>
						</div>
					</div>
					<div class="nbStep" data-step="3" hidden>
					<div class="row g-3">
						<div class="col-sm-6 col-lg-4">
							<label class="form-label" for="nb_layout"><?= h(t('form_layout')) ?></label>
							<select class="form-select" id="nb_layout" name="layout">
								<option value="auto"><?= h(t('form_layout_auto')) ?></option>
								<option value="absolute"><?= h(t('form_layout_absolute')) ?></option>
								<option value="grid"><?= h(t('form_layout_grid')) ?></option>
							</select>
						</div>
					</div>
					<?php // after creating (0.8.602): run button on nuBuilder Forte's User Home, rights + start page tile for access levels
					$uhOk = NuUserHome::available();
					$nbLevels = NuAcl::can(NuAcl::FORM_ACCESS, 'save') ? nu_db()->all('SELECT zzzzsys_access_id, sal_code, sal_description, sal_group FROM zzzzsys_access ORDER BY sal_code') : null;
					if ($uhOk || $nbLevels !== null): ?>
					<fieldset class="nbAfter border-top mt-4 pt-3">
						<legend class="form-label fs-6 fw-semibold mb-2"><?= h(t('builder_after')) ?></legend>
						<?php if ($uhOk): ?>
						<div class="form-check form-switch mb-3">
							<input class="form-check-input" type="checkbox" role="switch" id="nb_userhome" value="1" checked>
							<label class="form-check-label" for="nb_userhome"><?= h(t('builder_userhome')) ?></label>
							<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('builder_userhome_tip')) ?>" title="<?= h(t('builder_userhome_tip')) ?>"><i class="bi bi-info-circle"></i></span>
						</div>
						<?php endif; ?>
						<?php if ($nbLevels !== null): ?>
						<div class="mb-1"><span class="form-label"><?= h(t('builder_levels')) ?></span><span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('builder_levels_tip')) ?>" title="<?= h(t('builder_levels_tip')) ?>"><i class="bi bi-info-circle"></i></span></div>
						<?php if (!$nbLevels): ?>
							<div class="small text-body-secondary"><?= h(t('builder_levels_none')) ?></div>
						<?php else: ?>
						<?php // select all + filter by sal_group (0.8.674); "select all" works on the levels shown, hidden ones keep their tick
						$nbGroups = array_values(array_unique(array_map(static fn($x) => trim((string)($x['sal_group'] ?? '')), $nbLevels)));
						usort($nbGroups, static fn($a, $b) => $a === '' ? 1 : ($b === '' ? -1 : strcasecmp($a, $b))); ?>
						<div class="d-flex flex-wrap align-items-center gap-3 mb-2 nbLevelsBar">
							<div class="form-check mb-0">
								<input class="form-check-input" type="checkbox" id="nbLvAll">
								<label class="form-check-label" for="nbLvAll"><?= h(t('builder_levels_all')) ?></label>
							</div>
							<?php if (count($nbGroups) > 1 || ($nbGroups[0] ?? '') !== ''): ?>
							<label class="visually-hidden" for="nbLvGroup"><?= h(t('builder_levels_group')) ?></label>
							<select class="form-select form-select-sm w-auto" id="nbLvGroup">
								<option value="*"><?= h(t('builder_levels_groups')) ?></option>
								<?php foreach ($nbGroups as $g): ?><option value="<?= h($g) ?>"><?= h($g === '' ? t('builder_levels_nogroup') : $g) ?></option><?php endforeach; ?>
							</select>
							<?php endif; ?>
							<span class="small text-body-secondary ms-auto" id="nbLvCount" aria-live="polite"><?= h(t('builder_levels_count', '0', (string)count($nbLevels))) ?></span>
						</div>
						<div class="nbLevels border rounded px-3 py-2" id="nbLevels">
							<?php foreach ($nbLevels as $lv): $lid = 'nb_lv_' . substr(md5((string)$lv['zzzzsys_access_id']), 0, 10); ?>
							<div class="form-check nbLevelRow" data-group="<?= h(trim((string)($lv['sal_group'] ?? ''))) ?>">
								<input class="form-check-input nbLevel" type="checkbox" id="<?= h($lid) ?>" value="<?= h((string)$lv['zzzzsys_access_id']) ?>">
								<label class="form-check-label" for="<?= h($lid) ?>"><?= h((string)$lv['sal_code']) ?><?php if ((string)$lv['sal_description'] !== ''): ?> <span class="text-body-secondary">– <?= h((string)$lv['sal_description']) ?></span><?php endif; ?></label>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
						<?php endif; ?>
					</fieldset>
					<?php endif; ?>
					</div>
					<div class="alert alert-danger mt-3 mb-0 d-none" id="nbNewError" role="alert"></div>
				</div>
				<div class="card-footer d-flex flex-wrap justify-content-end gap-2">
					<a class="btn btn-outline-secondary me-auto" href="<?= h(nu_url('forms')) ?>"><?= h(t('cancel')) ?></a>
					<button class="btn btn-outline-secondary" type="button" id="nbStepBack" hidden><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></button>
					<button class="btn btn-primary" type="button" id="nbStepNext"><?= h(t('nf_next')) ?><i class="bi bi-arrow-right ms-1"></i></button>
					<button class="btn btn-primary" type="submit" id="nbCreate" hidden<?= $canSave ? '' : ' disabled' ?>><i class="bi bi-check-lg me-1"></i><?= h(t('builder_create')) ?></button>
				</div>
			</form>
		</div>
	</div>
<?php endif; ?>
<?php
nu_layout_end($foot);
