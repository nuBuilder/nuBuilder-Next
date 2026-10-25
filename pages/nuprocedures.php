<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Procedures (zzzzsys_php) – like nuBuilder's form "nuprocedure", rights of that form. Form events are edited in the builder.
 *   list · ?id=<id> | ?id=new edit (tabs PHP · JS · Access levels · Usage · History) · POST action=run (JSON, test run of the saved code)
 */

NuAcl::require(NuAcl::FORM_PROCEDURE);
$db     = nu_db();
$rights = NuAcl::rights(NuAcl::FORM_PROCEDURE);
$T      = NuProcedure::TABLE;

$id    = (string)($_GET['id'] ?? '');
$isNew = $id === 'new';
$proc  = null;
if ($id !== '' && !$isNew) {
	$proc = NuProcedure::load($id);
	if (!$proc || ((string)$proc['sph_system'] === '1' && !NuHash::devMode())) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
$locked = ($proc && NuProcedure::locked($proc)) || !($proc ? $rights['save'] : $rights['create']);
$errors = [];

// ------------------------------------------------------------------ test run (JSON)
if (nu_is_post() && nu_post('action') === 'run') {
	header('Content-Type: application/json; charset=utf-8');
	if (!$proc) { echo json_encode(['ok' => false, 'errors' => [['message' => t('record_not_found')]]]); exit; }
	$props = json_decode((string)($_POST['props'] ?? '{}'), true);
	$res = NuProcedure::run($proc, is_array($props) ? $props : []);
	NuLog::write('procedure_run', $T, $id, ['code' => (string)$proc['sph_code'], 'ok' => $res['ok']]);
	echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

// ------------------------------------------------------------------ save / clone / delete
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		NuAcl::require(NuAcl::FORM_PROCEDURE, $proc ? 'save' : 'create');
		if ($proc && NuProcedure::locked($proc)) NuAcl::require('__globeadmin_only__');
		$in = [];
		foreach (NuProcedure::FIELDS as $f) $in[$f] = $_POST[$f] ?? '';
		foreach (['sph_javascript_active', 'sph_global', 'sph_demo'] as $f) $in[$f] = nu_check($f) === '1';
		[$newId, $errors] = NuProcedure::save($in, $proc ? $id : '');
		if (!$errors) {
			$after = $db->one("SELECT * FROM $T WHERE zzzzsys_php_id = ?", [$newId]) ?? [];
			$acc = isset($_POST['access_sent']) ? NuProcedure::setAccess($newId, array_filter((array)($_POST['access'] ?? []), 'is_string')) : ['added' => [], 'removed' => []];
			$details = ['code' => (string)$after['sph_code'], 'fields' => NuProcedure::diff($proc, $after)];
			if ($acc['added']) $details['access_added'] = implode(', ', $acc['added']);
			if ($acc['removed']) $details['access_removed'] = implode(', ', $acc['removed']);
			if ($details['fields'] || count($details) > 2 || !$proc) NuLog::write($proc ? 'procedure_changed' : 'procedure_created', $T, $newId, $details);
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('procedures', ['id' => $newId]));
		}
	}
	if ($action === 'clone' && $proc) {
		NuAcl::require(NuAcl::FORM_PROCEDURE, 'clone');
		$code = preg_replace('/_template$/', '', (string)$proc['sph_code']) ?: 'procedure';
		$new = $code;
		for ($n = 2; $db->value("SELECT COUNT(*) FROM $T WHERE sph_code = ? OR zzzzsys_php_id = ?", [$new, $new]); $n++) $new = mb_substr($code, 0, 290) . '_' . $n;
		$in = $proc;
		$in['sph_code'] = $new;
		foreach (['sph_javascript_active', 'sph_global', 'sph_demo'] as $f) $in[$f] = (string)($proc[$f] ?? '') === '1';
		[$newId, $e] = NuProcedure::save($in);
		if (!$e) {
			NuProcedure::setAccess($newId, array_column(array_filter(NuProcedure::access($id), static fn($a) => $a['granted']), 'zzzzsys_access_id'));
			NuLog::write('procedure_created', $T, $newId, ['code' => $new, 'clone_of' => (string)$proc['sph_code'], 'fields' => NuProcedure::diff(null, $db->one("SELECT * FROM $T WHERE zzzzsys_php_id = ?", [$newId]) ?? [])]);
			nu_flash('success', t('proc_cloned'));
			nu_redirect(nu_url('procedures', ['id' => $newId]));
		}
		$errors = ['_' => implode(' ', $e)];
	}
	if ($action === 'delete' && $proc) {
		NuAcl::require(NuAcl::FORM_PROCEDURE, 'delete');
		if (NuProcedure::locked($proc)) NuAcl::require('__globeadmin_only__');
		$objects = array_filter(NuProcedure::usage($id, (string)$proc['sph_code']), static fn($u) => $u['kind'] === 'object' || $u['kind'] === 'report');
		if ($objects) {
			$errors['_'] = t('proc_err_used', implode(', ', array_map(static fn($u) => trim($u['form'] . ' · ' . $u['where'], ' ·'), $objects)));
		} else {
			$db->transaction(static function (NuDB $db) use ($id, $T) {
				$db->delete('zzzzsys_access_php', 'slp_zzzzsys_php_id', $id);
				$db->delete($T, 'zzzzsys_php_id', $id);
			});
			NuLog::write('procedure_deleted', $T, $id, ['code' => (string)$proc['sph_code'], 'fields' => ['sph_php' => [(string)$proc['sph_php'], null]]]);
			nu_flash('success', t('proc_deleted'));
			nu_redirect(nu_url('procedures'));
		}
	}
}

// ------------------------------------------------------------------ list (also Print / CSV)
if ($id === '') {
	// list: columns, filters and sort of the chosen list view (0.8.334, core/nulistdefs.php)
	NuListView::page('procedures', [
		'before' => NuListView::crumbs([[t('nav_forms'), nu_url('forms')], [t('proc_title')]]), 'active' => 'forms',
		'title_extra' => NuListView::infoToggle('procHint'), 'title_sub' => '<div class="collapse nuTitleSub text-body-secondary small" id="procHint">' . h(t('proc_hint')) . '</div>',
		'buttons' => $rights['add'] ? '<a class="btn nuAddBtn" title="' . h(t('proc_new')) . '" href="' . h(nu_url('procedures', ['id' => 'new'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '',
	]);
	return;
}

// ------------------------------------------------------------------ view
$head = '';
$foot = '';
if ($id !== '') {
	// nuOnProcedureRun() of the JS tab is run with eval (as nuBuilder) – this page allows it
	header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-eval'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
	$snippets = $db->tableExists('zzzzsys_code_snippet')
		? array_map(static fn($s) => [(string)$s['cot_code'], (string)$s['cot_description'], (string)$s['cot_source_code'], (string)$s['cot_language']],
			$db->all("SELECT cot_code, cot_description, cot_source_code, cot_language FROM zzzzsys_code_snippet WHERE LOWER(COALESCE(cot_language,'')) IN ('php', 'javascript') ORDER BY cot_code"))
		: [];
	$data = ['db' => $db->type, 'catalog' => NuHash::catalog(), 'snippets' => $snippets, 'id' => $proc ? $id : '', 'url' => nu_url('procedures', ['id' => $id]),
		'msg' => ['unsaved' => t('proc_run_unsaved'), 'running' => t('proc_running'), 'done' => t('proc_run_done'), 'failed' => t('proc_run_failed'),
			'js' => t('proc_run_js'), 'output' => t('proc_run_output'), 'debug' => t('proc_run_debug'), 'cancelled' => t('proc_run_cancelled'),
			'snippet' => t('proc_snippet'), 'search' => t('search'), 'none' => t('no_records')]];
	$head = '<link rel="stylesheet" href="' . nu_asset(NU_CODEMIRROR . '/codemirror.css') . '">';
	$foot = '<script type="application/json" id="procData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
		. '<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-bundle.js') . '"></script>'
		. '<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-addons.js') . '"></script>'
		. '<script src="' . nu_asset('assets/js/nu/nucodetools.js') . '"></script>'
		. '<script src="' . nu_asset('assets/js/nu/nuhashvars.js') . '"></script>'
		. '<script src="' . nu_asset('assets/js/nuprocedures.js') . '"></script>';
}
nu_layout_start(t('proc_title'), 'forms', ['head' => $head]);
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
		<?php if ($id !== ''): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('procedures')) ?>"><?= h(t('proc_title')) ?></a></li>
			<li class="breadcrumb-item active" aria-current="page"><?= h($proc ? (string)$proc['sph_code'] : t('proc_new')) ?></li>
		<?php else: ?>
			<li class="breadcrumb-item active" aria-current="page"><?= h(t('proc_title')) ?></li>
		<?php endif; ?>
	</ol>
</nav>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php endif; ?>

<?php if ($id === ''): ?>
<?php else: ?>
	<?php
	$posted = nu_is_post() && nu_post('action') === 'save';
	$v = static fn(string $k) => $posted ? (string)($_POST[$k] ?? '') : (string)($proc[$k] ?? ($k === 'sph_status' ? '1' : ''));
	$chk = static fn(string $k) => $posted ? nu_check($k) === '1' : (string)($proc[$k] ?? ($k === 'sph_demo' ? '1' : '0')) === '1';
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$fb = static fn(string $k) => '<div class="invalid-feedback">' . h($errors[$k] ?? '') . '</div>';
	// label above the field (full width on phones, two columns from md)
	$row = static fn(string $k, string $label, string $control, bool $req = false) =>
		'<div class="procField"><label class="form-label procLabel" for="' . $k . '">' . h($label)
		. ($req ? ' <span class="text-danger">*</span>' : '') . '</label>' . $control . '</div>';
	$distinct = static fn(string $c) => array_column($db->all("SELECT DISTINCT $c AS v FROM $T WHERE $c IS NOT NULL AND $c <> '' AND " . NuProcedure::listCondition() . " ORDER BY $c"), 'v');
	$forms = $db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE COALESCE(sfo_type,'') <> 'subform' ORDER BY sfo_code");
	$access = NuProcedure::access($proc ? $id : '');
	if ($posted) { $sent = array_flip(array_filter((array)($_POST['access'] ?? []), 'is_string')); foreach ($access as &$a) $a['granted'] = isset($sent[$a['zzzzsys_access_id']]); unset($a); }
	$usage = $proc ? NuProcedure::usage($id, (string)$proc['sph_code']) : [];
	$history = $proc ? NuProcedure::history($id) : [];
	$nAccess = count(array_filter($access, static fn($a) => $a['granted']));
	$fieldLabel = static fn(string $f) => ['sph_code' => t('proc_code'), 'sph_description' => t('proc_description'), 'sph_group' => t('proc_group'), 'sph_category' => t('proc_category'),
		'sph_status' => t('proc_status'), 'sph_zzzzsys_form_id' => t('proc_launch'), 'sph_run' => t('proc_run'), 'sph_php' => 'PHP', 'sph_javascript' => 'JavaScript',
		'sph_javascript_active' => t('proc_js_active_short'), 'sph_global' => t('proc_everyone'), 'sph_demo' => t('proc_demo')][$f] ?? $f;
	/** History value as the user knows it: status / run labels, yes / no, form code. */
	$fieldValue = static function (string $f, ?string $val) use ($forms): string {
		if ($val === null) return '∅';
		return match ($f) {
			'sph_status' => t(NuProcedure::STATUS[$val] ?? 'proc_status_active'),
			'sph_run' => t(NuProcedure::RUN[$val] ?? 'proc_run_default'),
			'sph_javascript_active', 'sph_global', 'sph_demo' => t($val === '1' ? 'yes' : 'no'),
			'sph_zzzzsys_form_id' => $val === '' ? '–' : (string)(array_column($forms, 'sfo_code', 'zzzzsys_form_id')[$val] ?? $val),
			default => $val === '' ? '–' : $val,
		};
	};
	?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-filetype-php me-2"></i><?= h($proc ? (string)$proc['sph_code'] : t('proc_new')) ?>
			<?php if ($proc && (string)$proc['sph_template'] === '1'): ?><span class="badge text-bg-light border fs-6 align-middle"><?= h(t('proc_template')) ?></span><?php endif; ?></h1>
		<?php if ($proc): ?><div class="nuTitleSub text-body-secondary small">ID <?= h($id) ?></div><?php endif; ?>
		<div class="d-flex flex-wrap gap-2 align-items-center">   <!-- buttons in their own row, as on every page (0.8.474) -->
		<a class="btn nuBtnAct" href="<?= h(nu_url('procedures')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuRecordForm" id="procSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<?php if ($proc && $rights['clone']): ?>
			<form method="post" action="<?= h(nu_url('procedures', ['id' => $id])) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="clone">
				<button class="btn nuBtnAct" type="submit" id="procClone"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></button></form>
		<?php endif; ?>
		<?php if ($proc && $rights['delete'] && !NuProcedure::locked($proc)): ?>
			<form method="post" action="<?= h(nu_url('procedures', ['id' => $id])) ?>" data-confirm="<?= h(t('proc_delete_confirm', (string)$proc['sph_code'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
				<button class="btn nuBtnAct nuBtnDel" type="submit" id="procDelete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button></form>
		<?php endif; ?>
		</div>
	</div>
	<?php if ($proc && NuProcedure::locked($proc)): ?><div class="alert alert-info py-2 small"><?= h(t('proc_template_locked')) ?></div><?php endif; ?>

	<form method="post" action="<?= h(nu_url('procedures', ['id' => $id])) ?>" id="nuRecordForm" data-nu-focus-first data-nu-guard novalidate>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="access_sent" value="1">
		<ul class="nav nav-tabs" role="tablist" id="procTabs" data-nu-tab-restore>
			<li class="nav-item" role="presentation"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#procTabPhp" role="tab" data-tab="php">PHP</button></li>
			<li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#procTabJs" role="tab" data-tab="js">JS<?= trim($v('sph_javascript')) !== '' ? ' <span class="badge text-bg-secondary">1</span>' : '' ?></button></li>
			<li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#procTabAccess" role="tab" data-tab="access"><?= h(t('nav_access')) ?> <span class="badge text-bg-secondary" id="procAccessCount" data-everyone="<?= h(t('proc_everyone')) ?>"><?= $chk('sph_global') ? h(t('proc_everyone')) : $nAccess ?></span></button></li>
			<?php if ($proc): ?>
				<li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#procTabUsage" role="tab" data-tab="usage"><?= h(t('proc_usage')) ?> <span class="badge text-bg-secondary"><?= count($usage) ?></span></button></li>
				<li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#procTabHistory" role="tab" data-tab="history"><?= h(t('proc_history')) ?> <span class="badge text-bg-secondary"><?= count($history) ?></span></button></li>
			<?php endif; ?>
		</ul>
		<fieldset class="card shadow-sm border-top-0 rounded-top-0"<?= $locked ? ' disabled' : '' ?>>
		<div class="card-body tab-content" data-nu-tab-restore>
			<!-- ============================================================ PHP -->
			<div class="tab-pane fade show active" id="procTabPhp" role="tabpanel">
				<?php // code, description, group, category, status, launch form (0.8.225): collapsed for a saved procedure, open for a new one or after an error
				$fieldsOpen = $isNew || array_intersect_key($errors, array_flip(['sph_code', 'sph_description', 'sph_status', 'sph_zzzzsys_form_id']));
				$sumDesc = trim($v('sph_description')); ?>
				<details class="procFields mb-3" id="procFields"<?= $fieldsOpen ? ' open' : '' ?>>
				<summary class="procFieldsSum"><span class="fw-semibold"><?= h(t('proc_fields')) ?></span><?php if (!$isNew): ?>
					<span class="text-body-secondary ms-2 procFieldsSumText"><?= h($v('sph_code')) ?><?= $sumDesc !== '' ? ' – ' . h($sumDesc) : '' ?><?= $v('sph_group') !== '' ? ' · ' . h($v('sph_group')) : '' ?></span><?php endif; ?></summary>
				<div class="procFieldsBody">
				<?= $row('sph_code', t('proc_code'), '<input class="form-control' . $err('sph_code') . '" id="sph_code" name="sph_code" maxlength="300" required value="' . h($v('sph_code')) . '"' . ($isNew ? ' autofocus' : '') . ' spellcheck="false" autocomplete="off" list="sphCodeList">' . $fb('sph_code')
					. '<datalist id="sphCodeList">' . implode('', array_map(static fn($c) => '<option value="' . h($c) . '">', NuProcedure::HOOK_CODES)) . '</datalist>', true) ?>
				<?= $row('sph_description', t('proc_description'), '<input class="form-control' . $err('sph_description') . '" id="sph_description" name="sph_description" maxlength="300" required value="' . h($v('sph_description')) . '">' . $fb('sph_description'), true) ?>
				<div class="row g-3 procFieldRow">
					<div class="col-md-6"><?= $row('sph_group', t('proc_group'), '<input class="form-control" id="sph_group" name="sph_group" maxlength="300" list="procGroups" value="' . h($v('sph_group')) . '">'
						. '<datalist id="procGroups">' . implode('', array_map(static fn($g) => '<option value="' . h($g) . '">', $distinct('sph_group'))) . '</datalist>') ?></div>
					<div class="col-md-6"><?= $row('sph_category', t('proc_category'), '<input class="form-control" id="sph_category" name="sph_category" maxlength="300" list="procCats" value="' . h($v('sph_category')) . '">'
						. '<datalist id="procCats">' . implode('', array_map(static fn($g) => '<option value="' . h($g) . '">', $distinct('sph_category'))) . '</datalist>') ?></div>
				</div>
				<div class="row g-3 procFieldRow">
					<div class="col-md-6"><?= $row('sph_status', t('proc_status'), '<select class="form-select" id="sph_status" name="sph_status">'
						. implode('', array_map(static fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $v('sph_status') ? ' selected' : '') . '>' . h(t($l)) . '</option>', array_keys(NuProcedure::STATUS), NuProcedure::STATUS)) . '</select>', true) ?></div>
					<div class="col-md-6"><?= $row('sph_zzzzsys_form_id', t('proc_launch'), '<select class="form-select' . $err('sph_zzzzsys_form_id') . '" id="sph_zzzzsys_form_id" name="sph_zzzzsys_form_id"><option value=""></option>'
						. implode('', array_map(static fn($f) => '<option value="' . h($f['zzzzsys_form_id']) . '"' . ($f['zzzzsys_form_id'] === $v('sph_zzzzsys_form_id') ? ' selected' : '') . '>' . h($f['sfo_code'] . ($f['sfo_description'] ? ' – ' . $f['sfo_description'] : '')) . '</option>', $forms))
						. '</select>' . $fb('sph_zzzzsys_form_id')) ?></div>
				</div>
				<div class="form-text procHint"><?= h(t('proc_launch_hint')) ?></div>
				</div>
				</details>
				<div class="procRunRow d-flex flex-wrap gap-2 align-items-center">
					<label class="form-label procLabel mb-0 me-2" for="sph_run"><?= h(t('proc_run')) ?></label>
						<select class="form-select w-auto" id="sph_run" name="sph_run">
							<?php foreach (NuProcedure::RUN as $k => $l): ?><option value="<?= h($k) ?>"<?= $k === $v('sph_run') ? ' selected' : '' ?>><?= h(t($l)) ?></option><?php endforeach; ?>
						</select>
						<?php if ($proc): ?>
							<span class="small text-body-secondary ms-2"><?= h(t('proc_run_hint')) ?></span>
							<button class="btn btn-outline-secondary procRunBtn" type="button" data-run="hide" id="procRunHidden"><i class="bi bi-eye-slash me-1"></i><?= h(t('proc_run_hidden')) ?></button>
							<button class="btn btn-outline-secondary procRunBtn" type="button" data-run="window" id="procRunWindow"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('proc_run_window')) ?></button>
						<?php else: ?><span class="small text-body-secondary"><?= h(t('proc_run_after_save')) ?></span><?php endif; ?>
				</div>
				<div class="procResult mb-2" id="procResult" aria-live="polite" hidden></div>
				<div class="procEditor">
				<div class="d-flex flex-wrap gap-2 align-items-center mb-1">
					<label class="form-label small fw-semibold mb-0 me-auto" for="sph_php">PHP <span class="text-danger">*</span></label>
					<span class="procCodeTools d-flex gap-2" data-for="sph_php"></span>
				</div>
				<textarea class="form-control font-monospace procCode<?= $err('sph_php') ?>" id="sph_php" name="sph_php" rows="18" spellcheck="false" data-mode="php"><?= h($v('sph_php')) ?></textarea><?= $fb('sph_php') ?>
				</div>
			</div>

			<!-- ============================================================ JS -->
			<div class="tab-pane fade" id="procTabJs" role="tabpanel">
				<div class="form-check form-switch mb-2">
					<input class="form-check-input" type="checkbox" role="switch" id="sph_javascript_active" name="sph_javascript_active" value="1"<?= $chk('sph_javascript_active') ? ' checked' : '' ?>>
					<label class="form-check-label" for="sph_javascript_active"><?= h(t('proc_js_active')) ?></label>
				</div>
				<p class="small text-body-secondary"><?= h(t('proc_js_hint')) ?></p>
				<div class="procEditor">
				<div class="d-flex flex-wrap gap-2 align-items-center mb-1">
					<label class="form-label small fw-semibold mb-0 me-auto" for="sph_javascript">JavaScript</label>
					<button class="btn btn-sm btn-outline-secondary" type="button" id="procJsExample"><i class="bi bi-magic me-1"></i><?= h(t('proc_js_example')) ?></button>
					<span class="procCodeTools d-flex gap-2" data-for="sph_javascript"></span>
				</div>
				<textarea class="form-control font-monospace procCode" id="sph_javascript" name="sph_javascript" rows="14" spellcheck="false" data-mode="javascript"><?= h($v('sph_javascript')) ?></textarea>
				</div>
			</div>

			<!-- ============================================================ access levels -->
			<div class="tab-pane fade" id="procTabAccess" role="tabpanel">
				<div class="d-flex flex-wrap gap-4 mb-3">
					<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="sph_global" name="sph_global" value="1"<?= $chk('sph_global') ? ' checked' : '' ?>>
						<label class="form-check-label" for="sph_global"><?= h(t('proc_everyone_long')) ?></label></div>
					<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="sph_demo" name="sph_demo" value="1"<?= $chk('sph_demo') ? ' checked' : '' ?>>
						<label class="form-check-label" for="sph_demo"><?= h(t('proc_demo_long')) ?></label></div>
				</div>
				<div class="d-flex flex-wrap gap-2 mb-2">
					<input type="search" class="form-control form-control-sm w-auto" id="procAccessSearch" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>">
					<button class="btn btn-sm btn-outline-secondary" type="button" data-access-all="1"><?= h(t('proc_access_all')) ?></button>
					<button class="btn btn-sm btn-outline-secondary" type="button" data-access-all="0"><?= h(t('proc_access_none')) ?></button>
				</div>
				<div class="list-group procAccessList" id="procAccessList">
					<?php if (!$access): ?><div class="text-body-secondary small"><?= h(t('no_records')) ?></div><?php endif; ?>
					<?php foreach ($access as $a): ?>
						<label class="list-group-item d-flex gap-2 align-items-center" data-text="<?= h(strtolower($a['sal_code'] . ' ' . $a['sal_description'] . ' ' . $a['sal_group'])) ?>">
							<input class="form-check-input mt-0" type="checkbox" name="access[]" value="<?= h($a['zzzzsys_access_id']) ?>"<?= $a['granted'] ? ' checked' : '' ?>>
							<span class="fw-semibold"><?= h($a['sal_code']) ?></span><span class="small text-body-secondary me-auto"><?= h($a['sal_description']) ?></span>
							<?php if ((string)$a['sal_group'] !== ''): ?><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h($a['sal_group']) ?></span><?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="small text-body-secondary mt-2 mb-0"><?= h(t('proc_access_hint')) ?></p>
			</div>

			<?php if ($proc): ?>
			<!-- ============================================================ usage -->
			<div class="tab-pane fade" id="procTabUsage" role="tabpanel">
				<?php if (!$usage): ?><p class="text-body-secondary mb-0"><?= h(t('proc_usage_none')) ?></p><?php else: ?>
					<table class="table table-sm align-middle mb-0" id="procUsage">
						<thead><tr><th><?= h(t('proc_usage_kind')) ?></th><th><?= h(t('nav_forms')) ?></th><th><?= h(t('proc_usage_where')) ?></th></tr></thead>
						<tbody><?php foreach ($usage as $u): ?>
							<tr><td><span class="badge <?= $u['kind'] !== 'code' ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>"><?= h(t('proc_usage_' . $u['kind'])) ?></span></td>
								<td><?php if ($u['form_id'] !== '' && NuAcl::can(NuAcl::FORM_FORM) && !str_starts_with($u['form_id'], 'nu')): ?><a href="<?= h(nu_url('builder', ['f' => $u['form_id']])) ?>"><?= h($u['form'] ?: $u['form_id']) ?></a><?php else: ?><?= h($u['form'] ?: $u['form_id']) ?><?php endif; ?></td>
								<td class="small"><?php if (!empty($u['proc_id'])): ?><a href="<?= h(nu_url('procedures', ['id' => $u['proc_id']])) ?>"><?= h($u['where']) ?></a><?php elseif (!empty($u['report_id']) && NuAcl::can(NuAcl::FORM_REPORT)): ?><a href="<?= h(nu_url('reports', ['id' => $u['report_id']])) ?>"><?= h($u['where']) ?></a><?php else: ?><?= h($u['where']) ?><?php endif; ?></td></tr>
						<?php endforeach; ?></tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- ============================================================ history -->
			<div class="tab-pane fade" id="procTabHistory" role="tabpanel">
				<?php if (!$history): ?><p class="text-body-secondary mb-0"><?= h(t('proc_history_none')) ?></p><?php endif; ?>
				<div class="procHistory" id="procHistory">
				<?php foreach ($history as $hst): $d = json_decode((string)$hst['details'], true) ?: []; ?>
					<div class="border-bottom py-2">
						<div class="d-flex flex-wrap gap-2 align-items-baseline">
							<span class="small fw-semibold"><?= h(date('d.m.Y H:i:s', strtotime((string)$hst['log_time']))) ?></span>
							<span class="small"><?= h($hst['login']) ?></span>
							<span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h(($l = t('ev_' . $hst['event'])) === 'ev_' . $hst['event'] ? $hst['event'] : $l) ?></span>
							<?php if (isset($d['clone_of'])): ?><span class="small text-body-secondary"><?= h(t('proc_history_clone', $d['clone_of'])) ?></span><?php endif; ?>
							<?php if (isset($d['ok'])): ?><span class="small <?= $d['ok'] ? 'text-success' : 'text-danger' ?>"><?= h(t($d['ok'] ? 'proc_run_done' : 'proc_run_failed')) ?></span><?php endif; ?>
						</div>
						<?php foreach (['access_added' => 'proc_history_access_added', 'access_removed' => 'proc_history_access_removed'] as $k => $l): ?>
							<?php if (!empty($d[$k])): ?><div class="small"><?= h(t($l, $d[$k])) ?></div><?php endif; ?>
						<?php endforeach; ?>
						<?php foreach ((array)($d['fields'] ?? []) as $f => $ch): [$old, $new] = array_pad((array)$ch, 2, null); ?>
							<?php if (in_array($f, ['sph_php', 'sph_javascript'], true)): ?>
								<details class="small mt-1"><summary><?= h($fieldLabel((string)$f)) ?></summary>
									<div class="row g-2 mt-1">
										<div class="col-md-6"><div class="text-body-secondary"><?= h(t('proc_history_before')) ?></div><pre class="procHistCode"><?= h((string)$old) ?></pre></div>
										<div class="col-md-6"><div class="text-body-secondary"><?= h(t('proc_history_after')) ?></div><pre class="procHistCode"><?= h((string)$new) ?></pre></div>
									</div></details>
							<?php else: ?>
								<div class="small"><span class="fw-semibold"><?= h($fieldLabel((string)$f)) ?></span>: <span class="text-body-secondary text-decoration-line-through"><?= h($fieldValue((string)$f, $old === null ? null : (string)$old)) ?></span> → <?= h($fieldValue((string)$f, $new === null ? null : (string)$new)) ?></div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		</fieldset>
	</form>
<?php endif; ?>
<?php
nu_layout_end($foot);
