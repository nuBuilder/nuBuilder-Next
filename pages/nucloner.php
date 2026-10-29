<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Form cloner (globeadmin): ?p=cloner[&f=<source form>] – copy a form (tabs, browse, PHP, objects, events, subforms, iframe
 * forms, access rights) as a new form or into an existing one, or show it as an SQL dump. See core/nucloner.php.
 * ?p=cloner&info=<form id> → JSON with the tabs, subforms and iframe forms of a form (assets/js/nucloner.js).
 * Saved settings (0.8.276): nuBuilder's table zzzzsys_cloner (core/nuclonerconfig.php) – ?p=cloner = list, ?id=<id> / ?id=new,
 * ?f=<form> = new settings for that form; POST action=save|clone|delete. Run works with the values on the page, saved or not.
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('forms', 'change');
require_once NU_ROOT . '/core/nucloner.php';
$db = nu_db();

if (isset($_GET['info'])) {
	header('Content-Type: application/json');
	$info = NuCloner::info($db, (string)$_GET['info']);
	exit(json_encode($info ?? ['error' => t('cl_err_source')], JSON_UNESCAPED_UNICODE));
}

NuClonerConfig::ensureTable();
$CT = NuClonerConfig::TABLE;
$id = (string)($_GET['id'] ?? '');
$row = null;
if ($id !== '' && $id !== 'new') {
	$row = NuClonerConfig::get($id);
	if (!$row) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
// clone (0.8.680, as nuBuilder Forte): ?id=new&clone=<id> opens a copy of saved settings that is saved only with Save
$cloneId = $id === 'new' ? (string)($_GET['clone'] ?? '') : '';
$src = $cloneId !== '' ? NuClonerConfig::get($cloneId) : null;
if ($cloneId !== '' && !$src) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
$listMode = $id === '' && !isset($_GET['f']) && !nu_is_post();

$def = ['source' => (string)($_GET['f'] ?? ''), 'dest_mode' => 'new', 'dest' => '', 'code' => '', 'description' => '', 'tabs' => [], 'objects' => true,
	'subforms' => false, 'subform_ids' => [], 'iframes' => false, 'iframe_ids' => [], 'access' => false, 'dump' => true, 'keep_pks' => false, 'replace' => false, 'notes' => ''];   // dump: ticked by default (0.8.633)
if (nu_is_post()) {
	$in = [
		'source' => nu_post('source'),
		'dest_mode' => nu_post('dest_mode') === 'existing' ? 'existing' : 'new', 'dest' => nu_post('dest'), 'code' => nu_post('code'), 'description' => nu_post('description'),
		'tabs' => array_map('strval', (array)($_POST['tabs'] ?? [])), 'objects' => !empty($_POST['objects']),
		'subforms' => !empty($_POST['subforms']), 'subform_ids' => array_map('strval', (array)($_POST['subform_ids'] ?? [])),
		'iframes' => !empty($_POST['iframes']), 'iframe_ids' => array_map('strval', (array)($_POST['iframe_ids'] ?? [])),
		'access' => !empty($_POST['access']), 'dump' => !empty($_POST['dump']), 'keep_pks' => !empty($_POST['keep_pks']), 'replace' => !empty($_POST['replace']),
		'notes' => (string)($_POST['notes'] ?? ''),
	];
} else {
	$in = $row || $src ? NuClonerConfig::toInput($row ?? $src, $def) : $def;
}
$errors = [];
$action = nu_is_post() ? nu_post('action') : '';

// ------------------------------------------------------------------ saved settings: save / clone / delete
if ($action === 'save') {
	[$newId, $errors] = NuClonerConfig::save($in, $row ? $id : '');
	if (!$errors) {
		NuLog::write($row ? 'cloner_changed' : 'cloner_created', $CT, (string)$newId, $row
			? ['fields' => NuLog::diff($row, NuClonerConfig::get((string)$newId) ?? [])] : ['source' => $in['source']] + ($src ? ['clone_of' => $cloneId] : []));
		nu_flash('success', t('saved'));
		nu_redirect(nu_url('cloner', ['id' => $newId]));
	}
}
if ($action === 'delete' && $row) {
	$db->delete($CT, 'zzzzsys_cloner_id', $id);
	NuLog::write('cloner_deleted', $CT, $id, ['source' => (string)$row['clo_form_source']]);
	nu_flash('success', t('ac_deleted'));
	nu_redirect(nu_url('cloner'));
}

$result = null; $error = '';
if ($action === 'run') {
	try {
		$result = (new NuCloner($db, [
			'source' => $in['source'], 'dest' => $in['dest_mode'] === 'existing' ? $in['dest'] : '', 'code' => $in['code'], 'description' => $in['description'],
			'tabs' => $in['tabs'], 'objects' => $in['objects'], 'subforms' => $in['subforms'], 'subform_ids' => $in['subform_ids'],
			'iframes' => $in['iframes'], 'iframe_ids' => $in['iframe_ids'], 'access' => $in['access'],
			'dump' => $in['dump'], 'new_pks' => !$in['keep_pks'], 'replace' => $in['replace'], 'notes' => $in['notes'],
		]))->run();
		if (!$result['sql']) $in['code'] = '';   // the next clone gets a new suggestion
	} catch (Throwable $e) {
		error_log('nuBuilder Next cloner: ' . $e->getMessage());
		$error = $e instanceof RuntimeException ? $e->getMessage() : t('cl_err_failed') . (nu_cfg('nuConfigDebug') ? ' ' . $e->getMessage() : '');
	}
}

$forms = nu_forms();
$options = static function (string $selected, bool $withSystem = true) use ($forms): string {
	$out = '';
	foreach ([false, true] as $system) {
		if ($system && !$withSystem) continue;
		$html = '';
		foreach ($forms as $fid => $f) if ($f['system'] === $system) $html .= '<option value="' . h($fid) . '"' . ((string)$fid === $selected ? ' selected' : '') . '>' . h(nu_form_label((string)$fid)) . '</option>';
		if ($html !== '') $out .= '<optgroup label="' . h(t($system ? 'forms_system' : 'forms_app')) . '">' . $html . '</optgroup>';
	}
	return $out;
};
$tableLabels = ['zzzzsys_form' => t('cl_t_form'), 'zzzzsys_tab' => t('cl_t_tab'), 'zzzzsys_browse' => t('cl_t_browse'), 'zzzzsys_object' => t('cl_t_object'),
	'zzzzsys_event' => t('cl_t_event'), 'zzzzsys_php' => t('cl_t_php'), 'zzzzsys_select' => t('cl_t_select'), 'zzzzsys_select_clause' => t('cl_t_select'), 'zzzzsys_access_form' => t('cl_t_access')];

nu_layout_start(t('cl_title'), 'forms', ['head' => nu_ts_head()]);
$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
$heading = $row ? nu_form_label((string)$row['clo_form_source']) : t('cl_new_settings');
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
	<?php if ($listMode): ?><li class="breadcrumb-item active" aria-current="page"><?= h(t('cl_title')) ?></li>
	<?php else: ?><li class="breadcrumb-item"><a href="<?= h(nu_url('cloner')) ?>"><?= h(t('cl_title')) ?></a></li><li class="breadcrumb-item active" aria-current="page"><?= h($heading) ?></li><?php endif; ?>
</ol></nav>
<?php if ($listMode):
	// ------------------------------------------------------------------ list of saved settings
	$q = trim((string)($_GET['q'] ?? ''));
	$rows = NuClonerConfig::all($q);
	$yes = static fn($v) => (string)$v === '1' ? '<i class="bi bi-check-lg" role="img" aria-label="' . h(t('yes')) . '"></i>' : '';
	?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-files me-2"></i><?= h(t('cl_title')) ?><?= $tip(t('cl_configs_info')) ?></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?></div>
	</div>
	<?php if (count($rows) > 10 || $q !== ''): // the search only when there is something to search (0.8.690) ?>
	<form class="card card-body shadow-sm mb-3 py-3" method="get" action="index.php" role="search">
		<input type="hidden" name="p" value="cloner">
		<div class="row g-2 align-items-end"><div class="col-md-6"><?= nu_list_search($q, '', '', false, $q !== '' ? nu_url('cloner') : null) ?></div></div>
	</form>
	<?php endif; ?>
	<div class="card shadow-sm">
		<div class="card-header nuTitleBar">
			<span class="small text-body-secondary"><?= h(t('n_records', count($rows))) ?></span>
			<div class="d-flex flex-wrap gap-2"><a class="btn nuAddBtn" href="<?= h(nu_url('cloner', ['id' => 'new'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></a></div>
		</div>
		<div class="table-responsive">
			<table class="table table-hover align-middle mb-0 nu-table">
				<thead><tr><th><?= h(t('cl_source')) ?></th><th><?= h(t('cl_dest')) ?></th><th><?= h(t('cl_dump_short')) ?></th><th><?= h(t('cl_without_objects')) ?></th><th><?= h(t('cl_notes')) ?></th></tr></thead>
				<tbody>
				<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-body-secondary py-5"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
				<?php foreach ($rows as $r): $edit = nu_url('cloner', ['id' => (string)$r['zzzzsys_cloner_id']]); ?>
					<tr data-href="<?= h($edit) ?>" tabindex="0">
						<td class="fw-semibold nuMainCol"><a class="text-decoration-none" href="<?= h($edit) ?>"><?= h((string)$r['source_code'] !== '' ? $r['source_code'] : ((string)$r['clo_form_source'] !== '' ? $r['clo_form_source'] : '–')) ?></a>
							<?php if ((string)$r['source_description'] !== ''): ?><div class="small text-body-secondary fw-normal"><?= h($r['source_description']) ?></div><?php endif; ?></td>
						<td class="small"><?= (string)$r['clo_form_dest'] !== '' ? h((string)($r['dest_code'] ?: $r['clo_form_dest'])) : '<span class="text-body-secondary">' . h(t('cl_dest_new')) . '</span>' ?></td>
						<td><?= $yes($r['clo_dump']) ?></td>
						<td><?= $yes($r['clo_objects']) ?></td>
						<td class="small text-break"><?= h(mb_strimwidth((string)$r['clo_notes'], 0, 80, '…')) ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php nu_layout_end();
	return;
endif; ?>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-files me-2"></i><?= h(t('cl_title')) ?></h1>
	<div class="d-flex flex-wrap gap-2"><?= nu_back_button('cloner', nu_url('cloner')) ?></div>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $m): ?><div><?= h($m) ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if ($error !== ''): ?><div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-2"></i><?= h($error) ?></div><?php endif; ?>
<?php if ($result && $result['sql'] === null): ?>
	<div class="alert alert-success">
		<div class="fw-semibold mb-1"><i class="bi bi-check-circle me-2"></i><?= h(t('cl_done', $result['code'])) ?></div>
		<div class="small mb-2"><?= h(implode(' · ', array_map(static fn($t, $n) => ($tableLabels[$t] ?? $t) . ': ' . $n, array_keys($result['counts']), $result['counts']))) ?></div>
		<?php foreach (array_slice($result['renamed'], 0, 3) as [$a, $b]): ?><div class="small"><i class="bi bi-arrow-right-short"></i><?= h(t('cl_renamed', $a, $b)) ?></div><?php endforeach; ?>
		<?php if (count($result['renamed']) > 3): ?>
			<details class="small"><summary><?= h(t('cl_renamed_more', count($result['renamed']) - 3)) ?></summary>
				<?php foreach (array_slice($result['renamed'], 3) as [$a, $b]): ?><div><i class="bi bi-arrow-right-short"></i><?= h(t('cl_renamed', $a, $b)) ?></div><?php endforeach; ?>
			</details>
		<?php endif; ?>
		<?php foreach ($result['warnings'] as $w): ?><div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><?= h($w) ?></div><?php endforeach; ?>
		<div class="d-flex flex-wrap gap-2 mt-2">
			<a class="btn btn-sm btn-success" href="<?= h(nu_url('form', ['f' => $result['form_id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('cl_open')) ?></a>
			<a class="btn btn-sm btn-outline-success" href="<?= h(nu_url('builder', ['f' => $result['form_id']])) ?>"><i class="bi bi-tools me-1"></i><?= h(t('builder_title')) ?></a>
		</div>
	</div>
<?php endif; ?>

<form method="post" action="<?= h(nu_url('cloner', $row ? ['id' => $id] : ['id' => 'new', 'clone' => $cloneId])) ?>" id="clForm"<?= $src ? ' data-nu-clone="' . h(t('form_clone_badge')) . '"' : '' ?> data-nu-guard data-info-url="<?= h(nu_url('cloner', ['info' => '__ID__'])) ?>"
	  data-sel-tabs="<?= h(json_encode($in['tabs'])) ?>" data-sel-sub="<?= h(json_encode($in['subform_ids'])) ?>" data-sel-ifr="<?= h(json_encode($in['iframe_ids'])) ?>"
	  data-posted="<?= nu_is_post() || $row || $src ? '1' : '0' ?>" data-copy="<?= h(t('cl_copy')) ?>" data-objects="<?= h(t('cl_objects_n')) ?>" data-none="<?= h(t('cl_none')) ?>">
	<?= nu_csrf_field() ?>
	<div class="nuTitleBar mb-3">
		<h2 class="h5 mb-0"><?= h($heading) ?></h2>
		<div class="d-flex flex-wrap gap-2">
			<button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" name="action" value="save" formnovalidate><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
			<?php if ($row): ?>
				<a class="btn nuBtnAct" href="<?= h(nu_url('cloner', ['id' => 'new', 'clone' => $id])) ?>" id="clClone"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></a>
				<button class="btn nuBtnAct nuBtnDel" type="submit" name="action" value="delete" formnovalidate data-confirm-submit="<?= h(t('ac_delete_confirm')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
		</div>
	</div>
	<div class="row g-3">
		<div class="col-xl-7">
			<div class="card shadow-sm mb-3">
				<div class="card-header fw-semibold"><?= h(t('cl_source')) ?></div>
				<div class="card-body">
					<label class="form-label" for="clSource"><?= h(t('form')) ?> <span class="text-danger">*</span></label>
					<select class="form-select nuListTomSelect" id="clSource" name="source" required>
						<option value=""><?= h(t('please_select')) ?></option><?= $options($in['source']) ?>
					</select>
					<div class="form-text" id="clSourceInfo" aria-live="polite"></div>

					<div class="row g-3 mt-1">
						<div class="col-md-4">
							<div class="fw-semibold small mb-1"><?= h(t('cl_tabs')) ?></div>
							<div class="nuClList border rounded p-2" id="clTabs"></div>
						</div>
						<div class="col-md-4">
							<label class="form-check form-switch small fw-semibold mb-1"><input class="form-check-input" type="checkbox" name="subforms" value="1" id="clSubOn"<?= $in['subforms'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('cl_subforms')) ?></span></label>
							<div class="nuClList border rounded p-2" id="clSub"></div>
						</div>
						<div class="col-md-4">
							<label class="form-check form-switch small fw-semibold mb-1"><input class="form-check-input" type="checkbox" name="iframes" value="1" id="clIfrOn"<?= $in['iframes'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('cl_iframes')) ?></span></label>
							<div class="nuClList border rounded p-2" id="clIfr"></div>
						</div>
					</div>
					<div class="form-text"><?= h(t('cl_lists_hint')) ?></div>
				</div>
			</div>

			<div class="card shadow-sm mb-3">
				<div class="card-header fw-semibold"><?= h(t('cl_dest')) ?></div>
				<div class="card-body">
					<div class="d-flex flex-wrap gap-3 mb-2">
						<label class="form-check"><input class="form-check-input" type="radio" name="dest_mode" value="new"<?= $in['dest_mode'] !== 'existing' ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('cl_dest_new')) ?></span></label>
						<label class="form-check"><input class="form-check-input" type="radio" name="dest_mode" value="existing"<?= $in['dest_mode'] === 'existing' ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('cl_dest_existing')) ?></span></label>
					</div>
					<div class="row g-2" id="clDestNew">
						<div class="col-md-5"><label class="form-label small" for="clCode"><?= h(t('cl_code')) ?></label><input class="form-control" id="clCode" name="code" maxlength="300" value="<?= h($in['code']) ?>" autocomplete="off"></div>
						<div class="col-md-7"><label class="form-label small" for="clDesc"><?= h(t('cl_description')) ?></label><input class="form-control" id="clDesc" name="description" maxlength="300" value="<?= h($in['description']) ?>" autocomplete="off"></div>
					</div>
					<div id="clDestExisting">
						<label class="form-label small" for="clDest"><?= h(t('form')) ?></label>
						<select class="form-select nuListTomSelect" id="clDest" name="dest"><option value=""><?= h(t('please_select')) ?></option><?= $options($in['dest'], false) ?></select>
						<div class="form-text"><?= h(t('cl_dest_existing_hint')) ?></div>
					</div>
				</div>
			</div>
		</div>

		<div class="col-xl-5">
			<div class="card shadow-sm mb-3">
				<div class="card-header fw-semibold"><?= h(t('cl_options')) ?></div>
				<div class="card-body">
					<label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="objects" value="1" id="clObjects"<?= $in['objects'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('cl_with_objects')) ?></span></label>
					<label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="access" value="1"<?= $in['access'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('cl_access')) ?></span></label>
					<hr>
					<label class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="dump" value="1" id="clDump"<?= $in['dump'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('cl_dump')) ?></span></label>
					<div class="ps-4" id="clDumpOpts">
						<label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="keep_pks" value="1"<?= $in['keep_pks'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('cl_keep_pks')) ?></span></label>
						<?php if ($db->type !== 'sqlsrv'): ?><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="replace" value="1"<?= $in['replace'] ? ' checked' : '' ?>> <span class="form-check-label">REPLACE INTO</span></label><?php endif; ?>
						<label class="form-label small mb-1" for="clNotes"><?= h(t('cl_notes')) ?></label>
						<textarea class="form-control form-control-sm" id="clNotes" name="notes" rows="2"><?= h($in['notes']) ?></textarea>
					</div>
				</div>
				<div class="card-footer d-flex justify-content-end">
					<button class="btn btn-primary" type="submit" name="action" value="run" id="clRun" data-label-clone="<?= h(t('cl_run')) ?>" data-label-dump="<?= h(t('cl_run_dump')) ?>"><i class="bi bi-files me-1"></i><span><?= h(t('cl_run')) ?></span></button>
				</div>
			</div>
			<div class="small text-body-secondary px-1"><?= h(t('cl_hint')) ?></div>
		</div>
	</div>
</form>

<?php if ($result && $result['sql'] !== null): ?>
<div class="card shadow-sm mt-3" id="clDumpCard">
	<div class="card-header d-flex flex-wrap align-items-center gap-2">
		<span class="fw-semibold me-auto"><i class="bi bi-filetype-sql me-2"></i><?= h(t('cl_dump_result')) ?></span>
		<span class="small text-body-secondary"><?= h(implode(' · ', array_map(static fn($t, $n) => ($tableLabels[$t] ?? $t) . ': ' . $n, array_keys($result['counts']), $result['counts']))) ?></span>
		<button type="button" class="btn btn-sm btn-outline-secondary" id="clCopySql" data-copied="<?= h(t('insp_copied')) ?>"><i class="bi bi-clipboard me-1"></i><?= h(t('cl_copy_sql')) ?></button>
		<button type="button" class="btn btn-sm btn-outline-secondary" id="clDownloadSql" data-name="<?= h(preg_replace('/[^A-Za-z0-9_-]+/', '_', $result['code'] ?: 'form') . '_clone.sql') ?>"><i class="bi bi-download me-1"></i><?= h(t('cl_download')) ?></button>
	</div>
	<textarea class="form-control font-monospace small border-0 rounded-0 nuClSql" id="clSql" rows="18" readonly spellcheck="false"><?= h($result['sql']) ?></textarea>
</div>
<?php endif;
nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nucloner.js') . '"></script>');   // forms: searchable (0.8.536)
