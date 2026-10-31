<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * CSV import (globeadmin) – wizard in assets/js/nuimport.js, logic in core/nuimport.php.
 * POST (CSRF-checked in index.php), JSON answers: action = upload | preview | columns | sample | check | import | discard
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php);
// users (0.8.327): also whoever may add users – then only the target "Users" (NuImport::targets)
if (!NuImport::canUsers()) NuAdmin::require('import', 'change');

// template of the user import (0.8.330): the column titles as CSV – fill in with Excel, save as CSV, upload in step 1
if (!nu_is_post() && ($_GET['action'] ?? '') === 'template' && ($_GET['target'] ?? '') === 'users') {
	if (!NuImport::canUsers()) NuAcl::require(NuAcl::FORM_USER, 'add');
	header('Cache-Control: no-store');
	if (($zip = NuImport::userTemplateZip()) !== null) {            // 0.8.752: CSV + readme.txt
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="users-import-template.zip"');
		header('Content-Length: ' . strlen($zip));
		exit($zip);
	}
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="users-import-template.csv"');
	exit(NuImport::userTemplate());
}
if (NuImpersonate::active()) { nu_flash('warning', t('imp_err_route')); nu_redirect(nu_url('home')); }

if (nu_is_post()) {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	$out = static fn(array $a) => exit(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	try {
		nu_time_limit(600);
		$action = nu_post('action');
		$read = NuImport::readOptions($_POST);
		switch ($action) {
			case 'upload':
				$info = NuImport::upload($_FILES['file'] ?? []);
				$read = NuImport::readOptions(['delimiter' => $info['delimiter'], 'header' => $_POST['header'] ?? '1', 'quote' => $_POST['quote'] ?? '"']);
				$out(['ok' => true, 'file' => ['name' => $info['name'], 'size' => $info['size'], 'encoding' => $info['encoding']],
					'delimiter' => $info['delimiter'] === "\t" ? 'tab' : $info['delimiter'], 'sheets' => $info['sheets'] ?? [], 'sheet' => $info['sheet'] ?? 0] + NuImport::preview($read));
			case 'sheet':                                                 // another sheet of the uploaded spreadsheet (0.8.755)
				$info = NuImport::chooseSheet((int)nu_post('sheet'));
				$read = NuImport::readOptions(['delimiter' => ',', 'header' => $_POST['header'] ?? '1', 'quote' => '"']);
				$out(['ok' => true, 'sheets' => $info['sheets'], 'sheet' => $info['sheet'], 'delimiter' => ','] + NuImport::preview($read));
			case 'preview':
				$out(['ok' => true] + NuImport::preview($read));
			case 'columns':
				$out(['ok' => true, 'columns' => array_values(NuImport::columns(nu_post('table')))]);
			case 'sample':
				$table = nu_post('table');
				$cols = NuImport::columns($table);
				$width = count(NuImport::preview($read)['headers']);
				$map = NuImport::mapping(json_decode((string)($_POST['mapping'] ?? '{}'), true) ?: [], $cols, $width);
				$out(['ok' => true, 'sample' => NuImport::sample($read, $cols, $map, NuImport::options($_POST))]);
			case 'check':
			case 'import':
				$table = nu_post('table');
				$opt = NuImport::options($_POST);
				$res = NuImport::run($table, $read, json_decode((string)($_POST['mapping'] ?? '{}'), true) ?: [], $opt, $action === 'check');
				if ($action === 'import') {
					$file = NuImport::current();
					NuLog::write($res['rolled_back'] ? 'csv_import_failed' : 'csv_imported', $table, '', [
						'file' => $file['name'] ?? '', 'mode' => $opt['mode'], 'inserted' => $res['inserted'], 'updated' => $res['updated'],
						'failed' => $res['failed'], 'rows' => $res['rows'], 'rolled_back' => $res['rolled_back']] + ($table === NuImport::USERS ? ['skipped' => $res['skipped'], 'welcome' => $res['welcome']] : []));
					if (!$res['rolled_back']) NuImport::discard();       // done: the temp file is deleted
				}
				$out(['ok' => true] + $res);
			case 'discard':
				NuImport::discard();
				$out(['ok' => true]);
		}
		$out(['ok' => false, 'error' => t('err_not_found')]);
	} catch (Throwable $e) {
		if (!$e instanceof RuntimeException) error_log('nuBuilder Next CSV import: ' . $e->getMessage());
		$out(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : t('imp_err_general') . (nu_debug() ? ' ' . $e->getMessage() : '')]);
	}
}

// ------------------------------------------------------------------ page
$texts = [];
foreach (['imp_col_n', 'imp_none', 'imp_const', 'imp_nuid', 'imp_detected', 'imp_auto_mapped', 'imp_nothing_mapped', 'imp_cleared', 'imp_rows_found', 'imp_required',
	'imp_pk', 'imp_auto', 'imp_default', 'imp_sum_insert', 'imp_sum_update', 'imp_sum_unchanged', 'imp_sum_failed', 'imp_check_ok', 'imp_check_errors', 'imp_done',
	'imp_rolled_back', 'imp_config_loaded', 'imp_config_bad', 'imp_config_applied', 'imp_more_errors', 'imp_line', 'imp_column', 'imp_value', 'imp_message',
	'imp_fmt_auto', 'imp_fmt_timestamp', 'imp_fmt_excel', 'imp_working', 'imp_err_network', 'imp_choose_table', 'imp_key_needed', 'imp_bytes',
	'imp_u_target', 'imp_sum_skipped', 'imp_u_skipped', 'imp_u_welcome_n', 'imp_u_random_n', 'imp_u_done', 'imp_u_choose_level'] as $k) $texts[$k] = t($k);
$canTables = NuImport::canTables();
$target = ($_GET['target'] ?? '') === 'users' && NuImport::canUsers() ? NuImport::USERS : '';
$formats = NuImport::dateFormats();
nu_layout_start(t('imp_title'), 'home', ['head' => '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">']);
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
		<?php if ($target !== ''): ?><li class="breadcrumb-item"><a href="<?= h(nu_url('users')) ?>"><?= h(t('nav_users')) ?></a></li><?php endif; ?>
		<li class="breadcrumb-item active" aria-current="page"><?= h(t('imp_title')) ?></li>
	</ol>
</nav>
<div id="impApp" data-texts="<?= h(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>" data-formats="<?= h(json_encode($formats, JSON_UNESCAPED_UNICODE)) ?>"
	 data-tables="<?= h(json_encode($canTables ? NuImport::tables() : [], JSON_UNESCAPED_UNICODE)) ?>" data-users="<?= NuImport::canUsers() ? '1' : '0' ?>"
	 data-target="<?= h($target) ?>" data-max-mb="<?= NuImport::maxBytes() >> 20 ?>" data-lang="<?= h(nu_lang()) ?>">
	<?= nu_csrf_field() ?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-file-earmark-arrow-up me-2"></i><?= h(t('imp_title')) ?></h1>
		<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?>
		<ol class="impSteps list-unstyled d-flex gap-2 mb-0 small ms-auto" aria-label="<?= h(t('imp_steps')) ?>">
			<li data-step="1" class="active"><span>1</span><?= h(t('imp_step_file')) ?></li>
			<li data-step="2"><span>2</span><?= h(t('imp_step_map')) ?></li>
			<li data-step="3"><span>3</span><?= h(t('imp_step_check')) ?></li>
		</ol></div>
	</div>
	<div id="impMsg" aria-live="polite"></div>

	<!-- step 1: file -->
	<section class="impStep" data-step="1">
		<div class="card shadow-sm mb-3"><div class="card-body">
			<label class="impDrop" id="impDrop" for="impFile">
				<i class="bi bi-cloud-arrow-up fs-2 d-block mb-1"></i>
				<?php $pssOk = NuSpreadsheet::available(); ?>
				<span class="fw-semibold"><?= h(t($pssOk ? 'imp_drop_xlsx' : 'imp_drop')) ?></span>
				<span class="small text-body-secondary d-block"><?= h($pssOk ? t('imp_drop_hint_xlsx', NuImport::maxBytes() >> 20, min(NuImport::maxBytes() >> 20, NuSpreadsheet::MAX_MB)) : t('imp_drop_hint', NuImport::maxBytes() >> 20)) ?></span>
				<input type="file" id="impFile" accept=".csv,.txt,.tsv,.tab,text/csv,text/plain<?= $pssOk ? ',.xlsx,.xls,.ods' : '' ?>" class="visually-hidden">
			</label>
			<?php if (!$pssOk): // Excel files need the PhpSpreadsheet library pack (0.8.755) ?>
				<div class="small text-body-secondary mt-2" id="impXlsxHint"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i><?= h(NuSpreadsheet::problem() ?: t('pss_missing')) ?>
					<?php if (NuAdmin::can('update')): ?> <a href="<?= h(nu_url('update', ['tab' => 'libraries'])) ?>#libraries"><?= h(t('pss_install_link')) ?></a><?php endif; ?></div>
			<?php endif; ?>
			<div class="d-flex flex-wrap gap-2 align-items-center mt-2">
				<label class="btn btn-sm btn-outline-secondary mb-0" for="impConfigFile"><i class="bi bi-folder2-open me-1"></i><?= h(t('imp_config_load')) ?></label>
				<input type="file" id="impConfigFile" accept=".json,application/json" class="visually-hidden">
				<?php if ($target === NuImport::USERS): ?>
					<a class="btn btn-sm btn-outline-secondary" href="<?= h(nu_url('import', ['target' => 'users', 'action' => 'template'])) ?>" download><i class="bi bi-file-earmark-arrow-down me-1"></i><?= h(t('imp_tpl_download')) ?></a>
					<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('imp_tpl_zip_tip')) ?>" title="<?= h(t('imp_tpl_zip_tip')) ?>"><i class="bi bi-info-circle"></i></span>
				<?php endif; ?>
				<span class="small text-body-secondary" id="impConfigInfo"><?= h(t('imp_config_hint')) ?></span>
			</div>
		</div></div>
		<div class="card shadow-sm d-none" id="impFileCard">
			<div class="card-header d-flex flex-wrap gap-2 align-items-center">
				<span class="fw-semibold me-auto" id="impFileInfo"></span>
				<span class="small text-body-secondary" id="impRowCount"></span>
			</div>
			<div class="card-body">
				<div class="row g-2 mb-3">
					<div class="col-sm-6 col-lg-6 d-none" id="impSheetWrap"><!-- spreadsheets: the sheet instead of separator and quote (0.8.755) -->
						<label class="form-label small mb-1" for="impSheet"><?= h(t('imp_sheet')) ?></label>
						<select class="form-select" id="impSheet"></select>
					</div>
					<div class="col-sm-6 col-lg-3 impCsvOnly">
						<label class="form-label small mb-1" for="impDelimiter"><?= h(t('imp_delimiter')) ?></label>
						<div class="input-group">
							<select class="form-select" id="impDelimiter">
								<?php foreach (['semicolon' => ';', 'comma' => ',', 'tab' => 'tab', 'pipe' => '|', 'custom' => 'custom'] as $k => $v): ?>
									<option value="<?= h($v) ?>"><?= h(t('imp_delim_' . $k)) ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" class="form-control text-center impCustomDelim d-none" id="impDelimiterCustom" maxlength="1" aria-label="<?= h(t('imp_delim_custom')) ?>">
						</div>
					</div>
					<div class="col-sm-6 col-lg-3 impCsvOnly">
						<label class="form-label small mb-1" for="impQuote"><?= h(t('imp_quote')) ?></label>
						<select class="form-select" id="impQuote"><option value='"'>" (<?= h(t('imp_quote_double')) ?>)</option><option value="'">' (<?= h(t('imp_quote_single')) ?>)</option></select>
					</div>
					<div class="col-lg-6 d-flex align-items-end">
						<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="impHeader" checked>
							<label class="form-check-label" for="impHeader"><?= h(t('imp_header')) ?></label></div>
					</div>
				</div>
				<div class="table-responsive impPreview"><table class="table table-sm table-bordered mb-0" id="impPreview"></table></div>
				<div class="small text-body-secondary mt-1"><?= h(t('imp_preview_hint')) ?></div>
			</div>
			<div class="card-footer d-flex gap-2 justify-content-end">
				<button class="btn btn-outline-secondary" type="button" data-imp="restart"><?= h(t('imp_other_file')) ?></button>
				<button class="btn btn-primary" type="button" data-imp="to2"><?= h(t('imp_next')) ?> <i class="bi bi-arrow-right ms-1"></i></button>
			</div>
		</div>
	</section>

	<!-- step 2: table and mapping -->
	<section class="impStep d-none" data-step="2">
		<div class="card shadow-sm mb-3"><div class="card-body">
			<div class="row g-3 align-items-end">
				<div class="col-md-6 col-lg-4">
					<label class="form-label" for="impTable"><?= h(t('imp_table')) ?> <span class="text-danger">*</span></label>
					<select id="impTable" placeholder="<?= h(t('imp_choose_table')) ?>"></select>
				</div>
				<div class="col-md-6 col-lg-8 d-flex flex-wrap gap-2">
					<button class="btn btn-outline-primary" type="button" data-imp="automap"><i class="bi bi-magic me-1"></i><?= h(t('imp_automap')) ?></button>
					<button class="btn btn-outline-secondary" type="button" data-imp="clear"><i class="bi bi-eraser me-1"></i><?= h(t('imp_clear')) ?></button>
				</div>
			</div>
		</div></div>
		<div class="card shadow-sm mb-3 d-none" id="impMapCard">
			<div class="table-responsive">
				<table class="table align-middle mb-0 nu-table impMap">
					<thead><tr><th><?= h(t('imp_db_column')) ?></th><th><?= h(t('imp_source')) ?></th><th><?= h(t('imp_format')) ?></th><th><?= h(t('imp_preview')) ?></th></tr></thead>
					<tbody id="impMapBody"></tbody>
				</table>
			</div>
		</div>
		<div class="card shadow-sm mb-3 d-none" id="impOptCard">
			<div class="card-header fw-semibold"><i class="bi bi-sliders me-2"></i><?= h(t('imp_options')) ?></div>
			<div class="card-body">
				<div class="row g-3">
					<div class="col-md-6 col-lg-4 impTableOnly">
						<label class="form-label small mb-1" for="impMode"><?= h(t('imp_mode')) ?></label>
						<select class="form-select" id="impMode">
							<option value="insert"><?= h(t('imp_mode_insert')) ?></option>
							<option value="update"><?= h(t('imp_mode_update')) ?></option>
							<option value="upsert"><?= h(t('imp_mode_upsert')) ?></option>
						</select>
					</div>
					<div class="col-md-6 col-lg-4 d-none" id="impKeyGroup">
						<label class="form-label small mb-1" for="impKey"><?= h(t('imp_key')) ?></label>
						<select class="form-select" id="impKey"></select>
					</div>
					<div class="col-md-6 col-lg-4">
						<label class="form-label small mb-1" for="impDecimal"><?= h(t('imp_decimal')) ?></label>
						<select class="form-select" id="impDecimal"><option value=".">1,234.50 / 1'234.50</option><option value=",">1.234,50</option></select>
					</div>
					<div class="col-md-6 col-lg-4">
						<label class="form-label small mb-1" for="impEmpty"><?= h(t('imp_empty')) ?></label>
						<select class="form-select" id="impEmpty"><option value="null"><?= h(t('imp_empty_null')) ?></option><option value="keep"><?= h(t('imp_empty_keep')) ?></option></select>
					</div>
					<div class="col-md-6 col-lg-4">
						<label class="form-label small mb-1" for="impOnError"><?= h(t('imp_on_error')) ?></label>
						<select class="form-select" id="impOnError"><option value="stop"><?= h(t('imp_on_error_stop')) ?></option><option value="skip"><?= h(t('imp_on_error_skip')) ?></option></select>
					</div>
					<div class="col-md-6 col-lg-4 d-flex flex-column justify-content-end gap-1">
						<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="impTrim" checked><label class="form-check-label" for="impTrim"><?= h(t('imp_trim')) ?></label></div>
						<div class="form-check form-switch impTableOnly"><input class="form-check-input" type="checkbox" role="switch" id="impAudit" checked><label class="form-check-label" for="impAudit"><?= h(t('imp_audit')) ?></label></div>
					</div>
					<div class="col-12 impUsersOnly d-none">
						<?php $mailOk = NuMail::configured(); ?>
						<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="impWelcome"<?= $mailOk ? ' checked' : ' disabled' ?>>
							<label class="form-check-label" for="impWelcome"><?= h(t('imp_u_welcome')) ?></label>
							<?php $tipText = ($mailOk ? '' : t('welcome_err_mail') . ' ') . t('imp_u_welcome_hint', NuPwPolicy::minLength(), NuWelcome::hours()); ?>
							<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h($tipText) ?>" title="<?= h($tipText) ?>"><i class="bi bi-info-circle"></i></span></div>
					</div>
				</div>
			</div>
		</div>
		<div class="d-flex flex-wrap gap-2 justify-content-end">
			<button class="btn btn-outline-secondary me-auto" type="button" data-imp="to1"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></button>
			<button class="btn btn-outline-secondary" type="button" data-imp="saveconfig"><i class="bi bi-download me-1"></i><?= h(t('imp_config_save')) ?></button>
			<button class="btn btn-primary" type="button" data-imp="check"><i class="bi bi-clipboard-check me-1"></i><?= h(t('imp_check')) ?></button>
		</div>
	</section>

	<!-- step 3: check and import -->
	<section class="impStep d-none" data-step="3">
		<div class="row g-3 mb-3" id="impSummary"></div>
		<div class="alert d-none" id="impResult"></div>
		<div class="alert alert-info d-none" id="impSkipped"></div>
		<div class="card shadow-sm mb-3 d-none" id="impErrCard">
			<div class="card-header d-flex align-items-center gap-2"><span class="fw-semibold me-auto"><i class="bi bi-exclamation-triangle me-2 text-danger"></i><?= h(t('imp_errors')) ?></span>
				<button class="btn btn-sm btn-outline-secondary" type="button" data-imp="errcsv"><i class="bi bi-download me-1"></i><?= h(t('imp_errors_csv')) ?></button></div>
			<div class="table-responsive impErrors"><table class="table table-sm mb-0 nu-table"><thead><tr><th><?= h(t('imp_line')) ?></th><th><?= h(t('imp_column')) ?></th><th><?= h(t('imp_value')) ?></th><th><?= h(t('imp_message')) ?></th></tr></thead><tbody id="impErrBody"></tbody></table></div>
		</div>
		<div class="d-flex flex-wrap gap-2 justify-content-end">
			<button class="btn btn-outline-secondary me-auto" type="button" data-imp="to2"><i class="bi bi-arrow-left me-1"></i><?= h(t('imp_back_map')) ?></button>
			<button class="btn btn-outline-secondary d-none" type="button" data-imp="saveconfig" id="impSaveAfter"><i class="bi bi-download me-1"></i><?= h(t('imp_config_save')) ?></button>
			<button class="btn btn-outline-secondary d-none" type="button" data-imp="restart" id="impNew"><i class="bi bi-plus-lg me-1"></i><?= h(t('imp_new')) ?></button>
			<button class="btn btn-success" type="button" data-imp="import" id="impGo"><i class="bi bi-database-add me-1"></i><?= h(t('imp_import')) ?></button>
		</div>
	</section>
</div>
<?php
nu_layout_end('<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nuimport.js') . '"></script>');
