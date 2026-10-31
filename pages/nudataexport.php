<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Data export (0.8.758, core/nudataexport.php): list of the saved exports (NuListDefs 'exports') · ?id=new | ?id=<id> the editor ·
 * ?run=<id> runs one (asks for parameters first when the SQL has #placeholders#) · ?json=<id> downloads the definition as JSON ·
 * POST import_json uploads one. Admin right dataexport: view = run, change = edit. JSON actions of the editor (POST, nothing stored):
 * tables, columns, preview, count, check.
 */
NuAdmin::require('dataexport', 'view');                               // view = run; the editor's actions ask for change below
NuSecurity::requireRecentAuth(nu_url('dataexport'));
NuDataExport::ensure();

$json = static function (array $data): never {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	exit((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
};
$canChange = NuDataExport::can('change');
$id = (string)($_GET['id'] ?? '');
$runId = (string)($_GET['run'] ?? '');
$export = $id !== '' && $id !== 'new' ? NuDataExport::get($id) : null;
if ($id !== '' && $id !== 'new' && !$export) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('dataexport')); }
if ($id === 'new' && !$canChange) NuAdmin::require('dataexport', 'change');
$errors = [];

// ------------------------------------------------------------------ JSON actions of the editor
if (nu_is_post() && in_array(nu_post('action'), ['tables', 'columns', 'preview', 'count', 'check'], true)) {
	NuAdmin::require('dataexport', 'change');
	$action = nu_post('action');
	try {
		if ($action === 'tables') {
			$tables = [];
			foreach (NuDataExport::tables() as $n => $t) $tables[] = ['name' => $n, 'view' => $t['view'], 'system' => $t['system']];
			$selects = [];
			if (NuSqlSelect::available()) foreach (nu_db()->all('SELECT zzzzsys_select_id, sse_code, sse_description FROM zzzzsys_select ORDER BY sse_code') as $r) $selects[] = ['id' => (string)$r['zzzzsys_select_id'], 'code' => (string)$r['sse_code'], 'name' => (string)$r['sse_description']];
			$json(['ok' => true, 'tables' => $tables, 'selects' => $selects]);
		}
		if ($action === 'columns') {
			$cols = [];
			foreach (NuDataExport::columns(nu_post('table')) as $n => $c) $cols[] = ['name' => $n, 'kind' => $c['kind'], 'pk' => $c['pk'], 'secret' => $c['secret'], 'type' => $c['type']];
			$json(['ok' => true, 'columns' => $cols]);
		}
		$d = NuDataExport::cleanDataset(json_decode((string)($_POST['dataset'] ?? ''), true));
		$values = json_decode((string)($_POST['values'] ?? ''), true);
		$values = is_array($values) ? array_map('strval', array_filter($values, 'is_scalar')) : [];
		if ($action === 'check') {
			$e = NuDataExport::checkDataset($d);
			$json(['ok' => $e === '', 'message' => $e, 'params' => $e === '' ? NuDataExport::params(['datasets' => [$d]]) : []]);
		}
		if ($action === 'count') $json(['ok' => true, 'count' => NuDataExport::count($d, $values)]);
		$e = NuDataExport::checkDataset($d);
		if ($e !== '') $json(['ok' => false, 'message' => $e]);
		$p = NuDataExport::preview($d, $values);
		$json(['ok' => true, 'cols' => $p['cols'], 'rows' => $p['rows'], 'params' => NuDataExport::params(['datasets' => [$d]])]);
	} catch (Throwable $x) {
		$msg = $x->getMessage();
		if (!NuAuth::user()['globeadmin'] && $x instanceof PDOException) $msg = t('dxp_err_query');
		$json(['ok' => false, 'message' => $msg]);
	}
}

// ------------------------------------------------------------------ running
$runExport = $runId !== '' ? NuDataExport::get($runId) : null;
if ($runId !== '' && !$runExport) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('dataexport')); }
if ($runExport) {
	$params = NuDataExport::params($runExport);
	$values = [];
	$ready = !$params;
	if (nu_is_post() && nu_post('action') === 'run') {
		foreach ($params as $p) $values[$p] = (string)($_POST['prm'][$p] ?? '');
		$ready = true;
	}
	if ($ready) {
		try {
			$r = NuDataExport::run($runExport, $values, 'page');
		} catch (Throwable $x) {
			$msg = !NuAuth::user()['globeadmin'] && $x instanceof PDOException ? t('dxp_err_query') : $x->getMessage();
			nu_flash('danger', t('dxp_run_failed', $msg));
			nu_redirect(nu_url('dataexport', ['id' => $runExport['id']]));
		}
		NuDataExport::sendFile($r['path'], $r['name'], $r['mime']);   // unlinks the file
	}
	nu_layout_start(t('dxp_title'), 'home');
	echo NuListView::crumbs([[t('dxp_title'), nu_url('dataexport')], [$runExport['name']]]);
	?>
	<div class="nuTitleBar">
		<h1 class="h4 mb-0"><i class="bi bi-file-earmark-arrow-down me-2"></i><?= h($runExport['name']) ?></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button('dataexport') ?></div>
	</div>
	<form method="post" action="<?= h(nu_url('dataexport', ['run' => $runExport['id']])) ?>" class="card shadow-sm" id="dxpParamForm" data-nu-once>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="run">
		<div class="card-body">
			<p class="small text-body-secondary"><?= h(t('dxp_params_hint')) ?></p>
			<?php foreach ($params as $p): ?>
			<div class="mb-3 dxpParam">
				<label class="form-label" for="prm_<?= h($p) ?>"><code>#<?= h($p) ?>#</code></label>
				<input class="form-control" id="prm_<?= h($p) ?>" name="prm[<?= h($p) ?>]" value="">
			</div>
			<?php endforeach; ?>
			<button class="btn btn-primary" type="submit" id="dxpRunGo" data-nu-busy="<?= h(t('dxp_running')) ?>"><i class="bi bi-download me-1"></i><?= h(t('dxp_run')) ?></button>
		</div>
	</form>
	<?php
	nu_layout_end();
	return;
}

// ------------------------------------------------------------------ POST: save / delete / duplicate / import
if (nu_is_post()) {
	$action = nu_post('action');
	if (in_array($action, ['save', 'delete', 'duplicate', 'import_json'], true)) NuAdmin::require('dataexport', 'change');
	if ($action === 'save') {
		$in = ['name' => nu_post('name'), 'code' => nu_post('code'), 'description' => nu_post('fi_description'), 'format' => nu_post('format'), 'filename' => nu_post('filename'),
			'options' => ['csv' => ['sep' => nu_post('csv_sep'), 'eol' => nu_post('csv_eol'), 'bom' => nu_check('csv_bom') === '1', 'head' => nu_check('csv_head') === '1', 'null' => nu_post('csv_null'),
				'date' => nu_post('csv_date'), 'datetime' => nu_post('csv_datetime'), 'decimal' => nu_post('csv_decimal'), 'protect' => nu_check('csv_protect') === '1', 'bool' => nu_post('csv_bool')]],
			'datasets' => (string)($_POST['datasets'] ?? '[]')];
		[$newId, $errors] = NuDataExport::save($in, $export ? $export['id'] : '');
		if (!$errors) {
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('dataexport', ['id' => $newId]));
		}
		nu_flash('danger', t('err_check_form'));
		$export = ['id' => $export ? $export['id'] : '', 'code' => (string)$in['code'], 'name' => (string)$in['name'], 'description' => (string)$in['description'], 'format' => (string)$in['format'] === 'csv' ? 'csv' : 'xlsx',
			'filename' => (string)$in['filename'], 'options' => NuDataExport::cleanOptions($in['options']), 'datasets' => array_map([NuDataExport::class, 'cleanDataset'], json_decode($in['datasets'], true) ?: []),
			'last_run_at' => '', 'runs' => 0, 'updated_at' => '', 'updated_by' => ''];
		$id = $export['id'] !== '' ? $export['id'] : 'new';
	} elseif ($export && $action === 'delete') {
		NuDataExport::delete($export['id']);
		nu_flash('success', t('dxp_deleted', $export['name']));
		nu_redirect(nu_url('dataexport'));
	} elseif ($export && $action === 'duplicate') {
		$newId = NuDataExport::duplicate($export['id']);
		nu_flash('success', t('saved'));
		nu_redirect(nu_url('dataexport', ['id' => $newId]));
	} elseif ($action === 'import_json') {
		$f = $_FILES['json_file'] ?? null;
		$txt = $f && (int)($f['error'] ?? 1) === 0 && (int)$f['size'] <= 2000000 ? (string)file_get_contents((string)$f['tmp_name']) : '';
		[$in, $err] = $txt !== '' ? NuDataExport::fromJson($txt) : [null, t('dxp_err_json')];
		if ($in) [$newId, $errs] = NuDataExport::save($in);
		if ($in && !$errs) { nu_flash('success', t('dxp_imported', $in['name'])); nu_redirect(nu_url('dataexport', ['id' => $newId])); }
		nu_flash('danger', $err !== '' ? $err : implode(' ', $errs ?? []));
		nu_redirect(nu_url('dataexport'));
	}
}

// ------------------------------------------------------------------ JSON download
if ($export && isset($_GET['json'])) {
	$name = $export['code'] . '.export.json';
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	header('Cache-Control: no-store');
	exit(NuDataExport::toJson($export));
}

// ------------------------------------------------------------------ the list
if ($id === '') {
	$st = NuListView::prepare('exports');
	nu_layout_start(t('dxp_title'), 'home', ['head' => NuListView::head() . nu_ts_head()]);
	echo NuListView::crumbs([[t('dxp_title')]]);
	$buttons = '';
	if ($canChange) {
		$buttons = '<a class="btn nuAddBtn" href="' . h(nu_url('dataexport', ['id' => 'new'])) . '" id="dxpNew"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>'
			. '<form method="post" action="' . h(nu_url('dataexport')) . '" enctype="multipart/form-data" class="d-inline" id="dxpImportForm">' . nu_csrf_field() . '<input type="hidden" name="action" value="import_json">'
			. '<input type="file" name="json_file" accept=".json,application/json" class="d-none" id="dxpImportFile">'
			. '<button type="button" class="btn btn-outline-secondary" id="dxpImportBtn" title="' . h(t('dxp_import_json_tip')) . '"><i class="bi bi-upload me-1"></i>' . h(t('dxp_import_json')) . '</button></form>';
	}
	echo NuListView::card($st, ['title_extra' => NuListView::infoTip(t('dxp_hint')), 'buttons' => $buttons]);
	nu_layout_end(NuListView::foot() . nu_ts_foot() . '<script src="' . nu_asset('assets/js/nudataexport.js') . '"></script>');
	return;
}

// ------------------------------------------------------------------ the editor
$e = $export ?? ['id' => '', 'code' => '', 'name' => '', 'description' => '', 'format' => 'xlsx', 'filename' => '', 'options' => NuDataExport::cleanOptions([]), 'datasets' => [],
	'last_run_at' => '', 'runs' => 0, 'updated_at' => '', 'updated_by' => ''];
$csv = $e['options']['csv'];
$ro = !$canChange;
$data = ['export' => ['id' => $e['id'], 'format' => $e['format'], 'datasets' => $e['datasets']], 'url' => nu_url('dataexport', ['id' => $id]), 'db' => nu_db()->type, 'ro' => $ro,
	'ops' => NuDataExport::OPS_BY_KIND, 'formats' => NuDataExport::FORMATS, 'maxDatasets' => NuDataExport::MAX_DATASETS,
	'texts' => array_combine($k = ['dxp_dataset', 'dxp_src_table', 'dxp_src_sql', 'dxp_src_select', 'dxp_add_dataset', 'dxp_remove_dataset', 'dxp_columns', 'dxp_column', 'dxp_heading', 'dxp_format_col',
		'dxp_all_cols', 'dxp_none_cols', 'dxp_conditions', 'dxp_add_condition', 'dxp_match_all', 'dxp_match_any', 'dxp_sort', 'dxp_add_sort', 'dxp_asc', 'dxp_desc', 'dxp_preview', 'dxp_count',
		'dxp_rows', 'dxp_no_rows', 'dxp_secret', 'dxp_pk', 'dxp_view', 'dxp_system', 'dxp_reimport', 'dxp_reimport_tip', 'dxp_sql_hint', 'dxp_select_pick', 'dxp_params_in', 'dxp_name_ds',
		'dxp_table', 'dxp_err_table', 'dxp_up', 'dxp_down', 'dxp_type', 'dxp_loading', 'dxp_fmt_by_type', 'dxp_fmt_text', 'dxp_fmt_custom', 'dxp_preview_hint', 'dxp_check', 'dxp_check_ok',
		'op_eq', 'op_neq', 'op_contains', 'op_ncontains', 'op_starts', 'op_ends', 'op_lt', 'op_le', 'op_gt', 'op_ge', 'op_between', 'op_in', 'op_empty', 'op_nempty', 'op_true', 'op_false',
		'kind_text', 'kind_int', 'kind_decimal', 'kind_bool', 'kind_date', 'kind_datetime', 'kind_time', 'remove', 'cancel', 'search'], array_map('t', $k))];
$head = '<link rel="stylesheet" href="' . nu_asset(NU_CODEMIRROR . '/codemirror.css') . '">' . nu_ts_head();
$foot = '<script type="application/json" id="dxpData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
	. '<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-bundle.js') . '"></script>'
	. '<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-addons.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nu/nucodetools.js') . '"></script>'
	. nu_ts_foot()
	. '<script src="' . nu_asset('assets/js/nudataexport.js') . '"></script>';
$params = $export ? NuDataExport::params($export) : [];
nu_layout_start(t('dxp_title'), 'home', ['head' => $head]);
echo NuListView::crumbs([[t('dxp_title'), nu_url('dataexport')], [$export ? $e['name'] : t('dxp_new')]]);
$fieldErr = static fn(string $f) => isset($errors[$f]) ? '<div class="invalid-feedback d-block">' . h($errors[$f]) . '</div>' : '';
?>
<form method="post" action="<?= h(nu_url('dataexport', ['id' => $id])) ?>" id="dxpForm" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="datasets" id="dxpDatasets" value="<?= h((string)json_encode($e['datasets'], JSON_UNESCAPED_UNICODE)) ?>">
	<div class="nuTitleBar">
		<h1 class="h4 mb-0"><i class="bi bi-file-earmark-arrow-down me-2"></i><?= h($export ? $e['name'] : t('dxp_new')) ?></h1>
		<?php if ($export): ?><div class="nuTitleSub small text-body-secondary"><code><?= h($e['code']) ?></code> · <?= h(t('dxp_runs')) ?>: <?= (int)$e['runs'] ?><?= $e['last_run_at'] !== '' ? ' · ' . h(t('dxp_last_run')) . ' ' . h(date(nu_dfmt('d.m.Y H:i'), (int)strtotime($e['last_run_at']))) : '' ?></div><?php endif; ?>
		<div class="d-flex flex-wrap gap-2">
			<?= nu_back_button('dataexport') ?>
			<?php if (!$ro): ?><button class="btn nuBtnAct nuBtnSave" type="submit" data-nu-save id="dxpSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
			<?php if ($export): ?>
			<a class="btn nuBtnAct" href="<?= h(nu_url('dataexport', ['run' => $e['id']])) ?>" id="dxpRunBtn" title="<?= h(t('dxp_run_tip')) ?>"><i class="bi bi-download me-1"></i><?= h(t('dxp_run')) ?></a>
			<div class="dropdown">
				<button class="btn nuBtnAct dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="dxpMore"><i class="bi bi-three-dots"></i></button>
				<ul class="dropdown-menu dropdown-menu-end">
					<li><a class="dropdown-item" href="<?= h(nu_url('dataexport', ['id' => $e['id'], 'json' => '1'])) ?>" id="dxpJson"><i class="bi bi-filetype-json me-2"></i><?= h(t('dxp_export_json')) ?></a></li>
					<?php if (!$ro): ?>
					<li><button class="dropdown-item" type="submit" form="dxpDupForm" id="dxpDup"><i class="bi bi-copy me-2"></i><?= h(t('dxp_duplicate')) ?></button></li>
					<li><hr class="dropdown-divider"></li>
					<li><button class="dropdown-item text-danger" type="submit" form="dxpDelForm" id="dxpDel"><i class="bi bi-trash me-2"></i><?= h(t('delete')) ?></button></li>
					<?php endif; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php if ($params): ?><div class="alert alert-info small py-2" id="dxpParamsNote"><i class="bi bi-braces me-1"></i><?= h(t('dxp_params_note', implode(', ', array_map(static fn($p) => "#$p#", $params)))) ?></div><?php endif; ?>
	<div class="row g-3">
		<div class="col-lg-4">
			<div class="card shadow-sm mb-3">
				<div class="card-body">
					<div class="mb-3">
						<label class="form-label" for="dxpName"><?= h(t('dxp_name')) ?></label>
						<input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="dxpName" name="name" value="<?= h($e['name']) ?>" maxlength="100" required<?= $ro ? ' readonly' : '' ?>>
						<?= $fieldErr('name') ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="dxpCode"><?= h(t('dxp_code')) ?></label>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('dxp_code_tip')) ?>" title="<?= h(t('dxp_code_tip')) ?>"><i class="bi bi-info-circle"></i></span>
						<input class="form-control font-monospace<?= isset($errors['code']) ? ' is-invalid' : '' ?>" id="dxpCode" name="code" value="<?= h($e['code']) ?>" maxlength="50" pattern="[a-z0-9_]*"<?= $ro ? ' readonly' : '' ?>>
						<?= $fieldErr('code') ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="dxpDesc"><?= h(t('fi_description')) ?></label>
						<textarea class="form-control" id="dxpDesc" name="description" rows="2" maxlength="1000"<?= $ro ? ' readonly' : '' ?>><?= h($e['description']) ?></textarea>
					</div>
					<div class="mb-3">
						<label class="form-label" for="dxpFormat"><?= h(t('dxp_format')) ?></label>
						<select class="form-select" id="dxpFormat" name="format"<?= $ro ? ' disabled' : '' ?>>
							<option value="xlsx"<?= $e['format'] === 'xlsx' ? ' selected' : '' ?>><?= h(t('dxp_format_xlsx')) ?></option>
							<option value="csv"<?= $e['format'] === 'csv' ? ' selected' : '' ?>><?= h(t('dxp_format_csv')) ?></option>
						</select>
					</div>
					<div class="mb-0">
						<label class="form-label" for="dxpFilename"><?= h(t('dxp_filename')) ?></label>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('dxp_filename_tip')) ?>" title="<?= h(t('dxp_filename_tip')) ?>"><i class="bi bi-info-circle"></i></span>
						<input class="form-control" id="dxpFilename" name="filename" value="<?= h($e['filename']) ?>" maxlength="100" placeholder="{code}_{date}"<?= $ro ? ' readonly' : '' ?>>
					</div>
				</div>
			</div>
			<div class="card shadow-sm mb-3" id="dxpCsvCard"<?= $e['format'] === 'csv' ? '' : ' hidden' ?>>
				<div class="card-body">
					<h2 class="h6"><?= h(t('dxp_csv_options')) ?></h2>
					<div class="row g-2">
						<div class="col-6"><label class="form-label small" for="dxpCsvSep"><?= h(t('csv_separator')) ?></label>
							<select class="form-select form-select-sm" id="dxpCsvSep" name="csv_sep"<?= $ro ? ' disabled' : '' ?>><?php foreach (NuDataExport::CSV_SEPS as $k => $v): ?><option value="<?= h($k) ?>"<?= $csv['sep'] === $k ? ' selected' : '' ?>><?= h(t('csv_sep_' . $k)) ?></option><?php endforeach; ?></select></div>
						<div class="col-6"><label class="form-label small" for="dxpCsvEol"><?= h(t('dxp_csv_eol')) ?></label>
							<select class="form-select form-select-sm" id="dxpCsvEol" name="csv_eol"<?= $ro ? ' disabled' : '' ?>><option value="crlf"<?= $csv['eol'] === 'crlf' ? ' selected' : '' ?>>CRLF (Windows / Excel)</option><option value="lf"<?= $csv['eol'] === 'lf' ? ' selected' : '' ?>>LF</option></select></div>
						<div class="col-6"><label class="form-label small" for="dxpCsvDate"><?= h(t('dxp_csv_date')) ?></label>
							<input class="form-control form-control-sm" id="dxpCsvDate" name="csv_date" value="<?= h($csv['date']) ?>" list="dxpDateFmts"<?= $ro ? ' readonly' : '' ?>>
							<datalist id="dxpDateFmts"><option value="Y-m-d"><option value="d.m.Y"><option value="d/m/Y"><option value="m/d/Y"></datalist></div>
						<div class="col-6"><label class="form-label small" for="dxpCsvDatetime"><?= h(t('dxp_csv_datetime')) ?></label>
							<input class="form-control form-control-sm" id="dxpCsvDatetime" name="csv_datetime" value="<?= h($csv['datetime']) ?>" list="dxpDatetimeFmts"<?= $ro ? ' readonly' : '' ?>>
							<datalist id="dxpDatetimeFmts"><option value="Y-m-d H:i:s"><option value="Y-m-d H:i"><option value="d.m.Y H:i"><option value="d/m/Y H:i"></datalist></div>
						<div class="col-6"><label class="form-label small" for="dxpCsvDecimal"><?= h(t('dxp_csv_decimal')) ?></label>
							<select class="form-select form-select-sm" id="dxpCsvDecimal" name="csv_decimal"<?= $ro ? ' disabled' : '' ?>><option value="."<?= $csv['decimal'] === '.' ? ' selected' : '' ?>>1234.50</option><option value=","<?= $csv['decimal'] === ',' ? ' selected' : '' ?>>1234,50</option></select></div>
						<div class="col-6"><label class="form-label small" for="dxpCsvBool"><?= h(t('dxp_csv_bool')) ?></label>
							<select class="form-select form-select-sm" id="dxpCsvBool" name="csv_bool"<?= $ro ? ' disabled' : '' ?>><?php foreach (['1/0', 'true/false', 'yes/no'] as $b): ?><option value="<?= h($b) ?>"<?= $csv['bool'] === $b ? ' selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?></select></div>
						<div class="col-6"><label class="form-label small" for="dxpCsvNull"><?= h(t('dxp_csv_null')) ?></label>
							<input class="form-control form-control-sm" id="dxpCsvNull" name="csv_null" value="<?= h($csv['null']) ?>" maxlength="20" placeholder="<?= h(t('dxp_csv_null_ph')) ?>"<?= $ro ? ' readonly' : '' ?>></div>
						<div class="col-12 pt-1">
							<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="dxpCsvHead" name="csv_head" value="1"<?= $csv['head'] ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>><label class="form-check-label small" for="dxpCsvHead"><?= h(t('csv_header')) ?></label></div>
							<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="dxpCsvBom" name="csv_bom" value="1"<?= $csv['bom'] ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>><label class="form-check-label small" for="dxpCsvBom"><?= h(t('csv_bom')) ?></label></div>
							<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="dxpCsvProtect" name="csv_protect" value="1"<?= $csv['protect'] ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>><label class="form-check-label small" for="dxpCsvProtect"><?= h(t('dxp_csv_protect')) ?></label>
								<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('dxp_csv_protect_tip')) ?>" title="<?= h(t('dxp_csv_protect_tip')) ?>"><i class="bi bi-info-circle"></i></span></div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<div class="col-lg-8">
			<div class="card shadow-sm">
				<div class="card-body" id="dxpEditor">
					<div class="d-flex align-items-center flex-wrap gap-2 mb-2">
						<h2 class="h6 mb-0"><?= h(t('dxp_datasets')) ?></h2>
						<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('dxp_datasets_tip')) ?>" title="<?= h(t('dxp_datasets_tip')) ?>"><i class="bi bi-info-circle"></i></span>
						<ul class="nav nav-tabs ms-2 flex-grow-1" id="dxpDsTabs"></ul>
						<?php if (!$ro): ?><button type="button" class="btn btn-sm btn-outline-secondary" id="dxpDsAdd"><i class="bi bi-plus-lg me-1"></i><?= h(t('dxp_add_dataset')) ?></button><?php endif; ?>
					</div>
					<?= $fieldErr('datasets') ?>
					<div id="dxpDs"></div>
				</div>
			</div>
		</div>
	</div>
</form>
<?php if ($export && !$ro): ?>
<form method="post" action="<?= h(nu_url('dataexport', ['id' => $e['id']])) ?>" id="dxpDupForm" class="d-none"><?= nu_csrf_field() ?><input type="hidden" name="action" value="duplicate"></form>
<form method="post" action="<?= h(nu_url('dataexport', ['id' => $e['id']])) ?>" id="dxpDelForm" class="d-none" data-confirm="<?= h(t('dxp_delete_confirm', $e['name'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="delete"></form>
<?php endif; ?>
<div class="modal fade" id="dxpPreviewDlg" tabindex="-1" aria-labelledby="dxpPreviewTitle" aria-hidden="true">
	<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header"><h2 class="modal-title h6" id="dxpPreviewTitle"><?= h(t('dxp_preview')) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
		<div class="modal-body" id="dxpPreviewBody"></div>
	</div></div>
</div>
<?php
nu_layout_end($foot);
