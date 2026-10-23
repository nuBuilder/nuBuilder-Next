<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Form builder: object list + properties panel + live preview (?f=<form id>), or the "new form" wizard (?new=1).
 * All behaviour lives in assets/js/nubuilder.js; this page only renders the frame and hands over the data.
 */

NuAcl::require(NuAcl::FORM_FORM);
$canSave = NuAcl::can(NuAcl::FORM_FORM, 'save');
$canDeleteForm = $canSave && !empty(NuAuth::user()['globeadmin']);     // deleting a whole form: globeadmin only (0.8.246)
$db      = nu_db();
$isNew   = ($_GET['new'] ?? '') === '1';
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

// labels for builder.js
$keys = ['save', 'delete', 'cancel', 'add', 'remove', 'remove_undo', 'remove_pending', 'saved', 'clone', 'back', 'please_select', 'search', 'yes', 'no', 'no_records'];
foreach ([__DIR__ . '/../lang/en.php'] as $f) foreach (array_keys(require $f) as $k) if (str_starts_with($k, 'builder_') || str_starts_with($k, 'bf_') || str_starts_with($k, 'form_type_') || str_starts_with($k, 'form_layout')) $keys[] = $k;
$labels = [];
foreach ($keys as $k) $labels[$k] = t($k);

$data = ['csrf' => nu_csrf_token(), 'labels' => $labels, 'can_save' => $canSave, 'new' => $isNew,
	'formats' => NuFormat::choices(), 'formatDesigner' => nu_format_designer_data()];
if ($meta) {
	$preview = '-1';
	if ($meta->table() !== '' && $meta->primaryKey() !== '' && $meta->type() !== 'launch') {
		try {
			$pk = $db->page('SELECT ' . NuDB::ident($meta->primaryKey()) . ' AS pk FROM ' . NuDB::ident($meta->table()), [], NuDB::ident($meta->primaryKey()), 1, 0);
			if ($pk) $preview = (string)$pk[0]['pk'];
		} catch (Throwable) {
		}
	}
	$data['form_id'] = $meta->id;
	$data['preview_record'] = $preview;
	$data['hash_catalog'] = NuHash::catalog();
	$data['db_type'] = $db->type;
	$data['readonly'] = $meta->isSystem() || !$canSave;
	$data['globeadmin'] = !empty(NuAuth::user()['globeadmin']);      // tabs: only the globeadmin renames / deletes existing ones
	// form drafts (0.8.386): the open draft and the rules; ?draft=1 opens the builder on the draft
	$openDraft = $meta->isSystem() ? null : NuFormDraft::openFor($meta->id);
	$data['draft'] = NuFormDraft::info($openDraft);
	$data['draft_mode'] = $openDraft !== null && ($_GET['draft'] ?? '') === '1';
	$data['draft_policy'] = ['required' => (bool)NuSecurity::get('form_drafts_required'), 'can_publish' => NuFormDraft::canPublish(),
		'unpublished' => (string)($meta->form['sfo_status'] ?? '') === '0', 'live_url' => nu_url('builder', ['f' => $meta->id]),
		'draft_url' => nu_url('builder', ['f' => $meta->id, 'draft' => '1'])];
	$data['procedures'] = array_map(static fn($p) => [$p['zzzzsys_php_id'], $p['sph_code'] . ($p['sph_description'] ? ' – ' . $p['sph_description'] : '')],
		array_values(array_filter($db->all("SELECT zzzzsys_php_id, sph_code, sph_description FROM zzzzsys_php WHERE zzzzsys_php_id NOT LIKE 'nu%' AND COALESCE(sph_system, '') <> '1' ORDER BY sph_code"),
			static fn($p) => !preg_match('/_(BB|BE|BS|AS|BD|AD|AB)$/', (string)$p['zzzzsys_php_id']))));
	$data['reports'] = NuReport::available() ? array_map(static fn($r) => [(string)$r['zzzzsys_report_id'], $r['sre_code'] . ($r['sre_description'] ? ' – ' . $r['sre_description'] : '')],
		$db->all('SELECT zzzzsys_report_id, sre_code, sre_description FROM zzzzsys_report ORDER BY sre_code')) : [];
	$data['forms'] = array_map(static fn($f) => [$f['zzzzsys_form_id'], $f['sfo_code'] . ' – ' . $f['sfo_description'], $f['sfo_type']],
		$db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_type FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' ORDER BY sfo_code"));
}

$head = NuBuilderLib::fontAwesomeTag() . '<link rel="stylesheet" href="' . nu_asset(NU_CODEMIRROR . '/codemirror.css') . '">'
	. '<link rel="stylesheet" href="' . nu_asset('assets/css/nubuilder.css') . '">'
	. ($isNew ? '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">' : '');   // new form: searchable Table select
$foot = '<script type="application/json" id="nuBuilderData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
	. ($isNew ? '<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script>' : '<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-bundle.js') . '"></script>'
		. '<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-addons.js') . '"></script>'
		. '<script src="' . nu_asset('assets/js/nu/nucodetools.js') . '"></script>')
	. '<script src="' . nu_asset('assets/js/nu/nuformat.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nuformatdesigner.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nu/nuhashvars.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nuattreditor.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nubuilder.js') . '"></script>';

nu_layout_start($meta ? $meta->description() . ' · ' . t('builder_title') : t('builder_new_form'), 'forms', ['head' => $head, 'container' => 'container-fluid nuBuilderContainer']);
$crumb = '<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="' . h(nu_url('forms')) . '">' . h(t('nav_forms')) . '</a></li>'
	. '<li class="breadcrumb-item active" aria-current="page">' . h($meta ? $meta->description() : t('builder_new_form')) . '</li></ol></nav>';
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
					<div class="row g-3">
						<div class="col-sm-7">
							<label class="form-label" for="nb_desc"><?= h(t('builder_description')) ?> <span class="text-danger">*</span></label>
							<input class="form-control" id="nb_desc" name="description" required maxlength="300">
						</div>
						<div class="col-sm-5">
							<label class="form-label" for="nb_code"><?= h(t('builder_code')) ?> <span class="text-danger">*</span></label>
							<input class="form-control" id="nb_code" name="code" required maxlength="300" pattern="[A-Za-z0-9_]+" autocomplete="off" spellcheck="false">
						</div>
						<div class="col-sm-6 col-lg-4">
							<label class="form-label" for="nb_type"><?= h(t('builder_type')) ?></label>
							<select class="form-select" id="nb_type" name="type">
								<?php foreach (['browseedit', 'edit', 'browse', 'subform', 'launch', 'page'] as $tp): ?><option value="<?= $tp ?>"><?= h(t('form_type_' . $tp)) ?></option><?php endforeach; ?>
							</select>
						</div>
						<div class="col-sm-6 col-lg-4">
							<label class="form-label" for="nb_layout"><?= h(t('form_layout')) ?></label>
							<select class="form-select" id="nb_layout" name="layout">
								<option value="auto"><?= h(t('form_layout_auto')) ?></option>
								<option value="absolute"><?= h(t('form_layout_absolute')) ?></option>
								<option value="grid"><?= h(t('form_layout_grid')) ?></option>
							</select>
						</div>
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
						</div>

						<!-- new table -->
						<div class="col-sm-7 nbNew d-none">
							<label class="form-label" for="nb_new_table"><?= h(t('builder_new_table_name')) ?> <span class="text-danger">*</span></label>
							<input class="form-control" id="nb_new_table" maxlength="64" pattern="[A-Za-z_][A-Za-z0-9_]*" autocomplete="off" spellcheck="false">
						</div>
						<div class="col-sm-5 nbNew d-none">
							<label class="form-label" for="nb_new_pk"><?= h(t('builder_primary_key')) ?></label>
							<input class="form-control" id="nb_new_pk" maxlength="64" autocomplete="off" spellcheck="false">
							<div class="form-text"><?= h(t('builder_new_pk_help')) ?></div>
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
					<div class="alert alert-danger mt-3 mb-0 d-none" id="nbNewError" role="alert"></div>
				</div>
				<div class="card-footer d-flex justify-content-end gap-2">
					<a class="btn btn-outline-secondary" href="<?= h(nu_url('forms')) ?>"><?= h(t('cancel')) ?></a>
					<button class="btn btn-primary" type="submit"<?= $canSave ? '' : ' disabled' ?>><i class="bi bi-check-lg me-1"></i><?= h(t('builder_create')) ?></button>
				</div>
			</form>
		</div>
	</div>
<?php else: ?>
	<?= $crumb ?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0 d-flex align-items-center gap-2"><i class="bi bi-tools"></i><span id="nbTitle"><?= h($meta->description()) ?></span>
			<span class="badge bg-secondary-subtle text-secondary-emphasis fs-6 fw-normal" id="nbType"><?= h(t('form_type_' . $meta->type())) ?></span>
			<?php if (!$meta->isSystem()): ?><span class="badge fs-6 fw-normal nbModePill" id="nbModePill"></span><?php endif; ?></h1>
		<?php if ($meta->isSystem()): ?><span class="badge text-bg-warning"><i class="bi bi-lock me-1"></i><?= h(t('builder_readonly_system')) ?></span><?php endif; ?>
		<a class="btn btn-sm nuBtnAct" href="<?= h(nu_url('forms')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<div class="d-flex flex-wrap gap-2 nbTopActions" id="nbTopActions"></div>
		<?php if (!$meta->isSystem() && $canSave): ?>
			<button class="btn btn-sm btn-outline-secondary" type="button" id="nbUndo" title="<?= h(t('builder_undo_hint')) ?>"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('builder_undo')) ?></button>
		<?php endif; ?>
		<a class="btn btn-sm btn-outline-secondary" href="<?= h(nu_url('form', ['f' => $meta->id])) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('builder_open_form')) ?></a>
		<?php if (!$meta->isSystem()): ?><a class="btn btn-sm btn-outline-secondary" id="nbAi" href="<?= h(nu_url('formai', ['f' => $meta->id])) ?>"><i class="bi bi-stars me-1"></i><?= h(t('fai_button_change')) ?></a><?php endif; ?>
		<?php if (!$meta->isSystem() && $canDeleteForm): ?>
			<button class="btn btn-sm btn-outline-danger" type="button" id="nbDeleteForm"><i class="bi bi-trash me-1"></i><?= h(t('builder_delete_form')) ?></button>
		<?php endif; ?>
	</div>

	<?php if (!$meta->isSystem()): ?><div class="nbDraftBar mb-3" id="nbDraftBar" hidden></div><?php endif; /* form drafts (0.8.386) */ ?>
	<div class="row g-3 nbWorkspace" id="nbWorkspace">
		<div class="col-xl-3 col-lg-4">
			<div class="card shadow-sm nbSidebar">
				<div class="list-group list-group-flush nbSections" role="list">
					<button type="button" class="list-group-item list-group-item-action" data-nb-section="form"><i class="bi bi-sliders me-2"></i><?= h(t('builder_sec_form')) ?></button>
					<button type="button" class="list-group-item list-group-item-action" data-nb-section="code"><i class="bi bi-code-slash me-2"></i><?= h(t('builder_sec_code')) ?></button>
					<button type="button" class="list-group-item list-group-item-action" data-nb-section="tabs"><i class="bi bi-folder2 me-2"></i><?= h(t('builder_sec_tabs')) ?></button>
					<button type="button" class="list-group-item list-group-item-action" data-nb-section="browse"><i class="bi bi-table me-2"></i><?= h(t('builder_sec_browse')) ?></button>
					<button type="button" class="list-group-item list-group-item-action" data-nb-section="history"><i class="bi bi-clock-history me-2"></i><?= h(t('builder_sec_history')) ?></button>
				</div>
				<div class="card-body border-top pb-2">
					<div class="d-flex align-items-center justify-content-between mb-2">
						<h2 class="h6 mb-0"><?= h(t('builder_objects')) ?> <span class="badge text-bg-secondary" id="nbObjCount">0</span></h2>
						<div class="dropdown">
							<button class="btn btn-sm nuAddBtn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="nbAddObject"<?= $meta->isSystem() || !$canSave ? ' disabled' : '' ?>><i class="bi bi-plus-lg"></i> <?= h(t('add')) ?></button>
							<ul class="dropdown-menu dropdown-menu-end nbAddMenu"></ul>
						</div>
					</div>
					<div class="d-flex gap-2 mb-2">
						<select class="form-select form-select-sm" id="nbTabFilter" aria-label="<?= h(t('builder_sec_tabs')) ?>"></select>
						<input type="search" class="form-control form-control-sm" id="nbObjSearch" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>">
					</div>
				</div>
				<ul class="list-group list-group-flush nbObjectList" id="nbObjectList" role="listbox" aria-label="<?= h(t('builder_objects')) ?>"></ul>
			</div>
		</div>

		<div class="col-xl-4 col-lg-8 nbPanelCol" id="nbPanelCol">
			<div class="card shadow-sm nbPanel">
				<div class="card-header d-flex align-items-center gap-2">
					<span class="fw-semibold me-auto" id="nbPanelTitle"></span>
					<span class="small text-success d-none" id="nbSavedHint"><i class="bi bi-check-circle me-1"></i><?= h(t('saved')) ?></span>
				</div>
				<div class="card-body" id="nbPanel"></div>
			</div>
		</div>

		<div class="col-xl-5 nbPreviewCol" id="nbPreviewCol">
			<div class="card shadow-sm nbPreviewCard">
				<div class="card-header d-flex flex-wrap align-items-center gap-2">
					<button class="btn btn-sm btn-outline-secondary nbPreviewToggle" type="button" id="nbPreviewToggle" aria-controls="nbPreviewBody" aria-expanded="true"
						data-show="<?= h(t('builder_preview_show')) ?>" data-hide="<?= h(t('builder_preview_hide')) ?>" title="<?= h(t('builder_preview_hide')) ?>" aria-label="<?= h(t('builder_preview_hide')) ?>"><i class="bi bi-chevron-bar-right"></i></button>
					<span class="fw-semibold me-auto nbPreviewTitle"><i class="bi bi-eye me-1"></i><?= h(t('builder_preview')) ?></span>
					<label class="small text-body-secondary" for="nbPreviewRecord"><?= h(t('builder_record')) ?></label>
					<input class="form-control form-control-sm nbPreviewRecord" id="nbPreviewRecord" value="">
					<select class="form-select form-select-sm nbPreviewLayout" id="nbPreviewLayout" aria-label="<?= h(t('form_layout')) ?>">
						<option value=""><?= h(t('builder_layout_saved')) ?></option>
						<option value="absolute"><?= h(t('form_layout_absolute')) ?></option>
						<option value="auto"><?= h(t('form_layout_auto')) ?></option>
						<option value="grid"><?= h(t('form_layout_grid')) ?></option>
					</select>
					<button class="btn btn-sm btn-outline-secondary" type="button" id="nbPreviewReload" title="<?= h(t('builder_reload')) ?>" aria-label="<?= h(t('builder_reload')) ?>"><i class="bi bi-arrow-clockwise"></i></button>
				</div>
				<div class="nbPreviewBody" id="nbPreviewBody">
					<?php if (!$meta->isSystem() && $canSave): ?><div class="small text-body-secondary px-3 py-1 border-bottom"><i class="bi bi-arrows-move me-1"></i><?= h(t('builder_designer_hint')) ?></div><?php endif; ?>
					<iframe class="nbPreview" id="nbPreview" title="<?= h(t('builder_preview')) ?>"></iframe>
				</div>
			</div>
		</div>
	</div>
	<?php if (!$meta->isSystem() && $canDeleteForm):   // "Delete form" (0.8.245, globeadmin only since 0.8.246): optionally with its table – then the table name must be typed (assets/js/nubuilder.js) ?>
	<div class="modal fade" id="nbDeleteModal" tabindex="-1" aria-labelledby="nbDeleteTitle" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h1 class="modal-title fs-5" id="nbDeleteTitle"><i class="bi bi-trash me-2"></i><?= h(t('builder_delete_form')) ?></h1>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
				</div>
				<div class="modal-body">
					<p><?= h(t('builder_delete_form_text')) ?></p>
					<div class="small text-body-secondary d-none" id="nbDelTableNote"></div>
					<div class="d-none" id="nbDelTableBox">
						<div class="form-check">
							<input class="form-check-input" type="checkbox" id="nbDelTable">
							<label class="form-check-label" for="nbDelTable" id="nbDelTableLabel"></label>
						</div>
						<div class="d-none mt-2" id="nbDelConfirmBox">
							<div class="alert alert-danger py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('builder_drop_warning')) ?></div>
							<label class="form-label small" for="nbDelTyped" id="nbDelTypedLabel"></label>
							<input class="form-control font-monospace" type="text" id="nbDelTyped" autocomplete="off" autocapitalize="off" spellcheck="false">
						</div>
						<div class="alert alert-warning py-2 small mt-2 mb-0 d-none" id="nbDelReauth"><i class="bi bi-shield-lock me-1"></i><?= h(t('builder_drop_reauth')) ?>
							<a class="alert-link" href="#" id="nbDelReauthLink"><?= h(t('builder_drop_reauth_link')) ?></a></div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
					<button type="button" class="btn btn-danger" id="nbDelOk"><i class="bi bi-trash me-1"></i><span id="nbDelOkText"><?= h(t('builder_delete_form')) ?></span></button>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>
<?php endif; ?>
<?php
nu_layout_end($foot);
