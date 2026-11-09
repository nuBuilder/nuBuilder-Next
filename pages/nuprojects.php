<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Projects (0.8.1035, core/nuproject.php + core/nuprojectpackage.php)
 *   ?p=projects                         list (NuListDefs 'projects'), Import
 *   ?p=projects&id=new                  new project
 *   ?p=projects&id=<id>[&tab=…]         project page: overview / items / notes / tasks / settings (&task=<id>, &note=<id> = edit)
 *   ?p=projects&export=<id>[&data=1]    package (JSON, nunext-project-1); data = rows of the tables (globeadmin)
 *   ?p=projects&import=<token>          check of an uploaded package → choices → import (globeadmin)
 * JSON POST actions: candidates (Add dialog), suggest (Suggest related).
 */
NuAcl::require(NuAcl::FORM_PROJECT);
NuSchema::ensure();

$db = nu_db();
$canChange = NuProject::canChange();
$ga = !empty(NuAuth::user()['globeadmin']);
$id = (string)($_GET['id'] ?? '');
$tab = in_array($_GET['tab'] ?? '', ['overview', 'items', 'notes', 'tasks', 'settings'], true) ? (string)$_GET['tab'] : 'overview';
$tmpOf = static fn(string $tok): string => preg_match('/^[a-f0-9]{24}$/', $tok) ? NuReport::tempDir() . '/nunext-project-' . $tok . '.json' : '';
$back = static fn(string $pid, string $t = '') => nu_url('projects', ['id' => $pid, 'tab' => $t !== '' && $t !== 'overview' ? $t : null]);

// ------------------------------------------------------------------ export
if (isset($_GET['export'])) {
	if (!$canChange) { nu_flash('danger', t('prj_err_rights')); nu_redirect(nu_url('projects')); }
	$withData = ($_GET['data'] ?? '') === '1' && $ga;
	try {
		$pkg = NuProjectPackage::export((string)$_GET['export'], $withData);
	} catch (Throwable $e) {
		nu_flash('danger', $e->getMessage());
		nu_redirect(nu_url('projects'));
	}
	session_write_close();
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . NuProjectPackage::fileName($pkg) . '"');
	header('X-Content-Type-Options: nosniff');
	echo NuProjectPackage::json($pkg);
	exit;
}

// ------------------------------------------------------------------ actions
if (nu_is_post()) {
	$action = nu_post('action');
	$pid = nu_post('project');
	if ($action === 'candidates' || $action === 'suggest') {                  // JSON, read only
		header('Content-Type: application/json; charset=utf-8');
		if ($action === 'candidates') {
			$kind = nu_post('kind');
			$have = array_flip(NuProject::refs($pid, $kind));
			$list = array_map(static fn($c) => ['ref' => $c[0], 'label' => $c[1], 'sub' => $c[2], 'member' => isset($have[$c[0]])], NuProject::candidates($kind, mb_substr(nu_post('q'), 0, 100)));
			echo json_encode(['ok' => true, 'items' => $list], JSON_UNESCAPED_UNICODE);
		} else {
			$kinds = [];
			foreach (array_keys(NuProject::KINDS) as $k) $kinds[$k] = t('prj_kind_' . $k);
			echo json_encode(['ok' => true, 'kinds' => $kinds, 'items' => NuProject::load($pid) ? NuProject::suggest($pid) : []], JSON_UNESCAPED_UNICODE);
		}
		exit;
	}
	if ($action === 'work') {                                                  // Working on: session only
		$on = nu_post('on') === '1' && NuProject::load($pid);
		NuProject::setWorking($on ? $pid : '');
		nu_flash('success', $on ? t('prj_working_on', (string)(NuProject::load($pid)['name'] ?? '')) : t('prj_working_off'));
		$to = nu_post('to');
		nu_redirect($to !== '' && str_starts_with($to, 'index.php') ? $to : ($pid !== '' ? $back($pid) : nu_url('projects')));
	}
	if ($action === 'import_upload' || $action === 'import_run' || $action === 'import_cancel') {
		if (!$ga) { nu_flash('danger', t('uf_edit_globeadmin')); nu_redirect(nu_url('projects')); }
		if ($action === 'import_upload') {
			$f = $_FILES['file'] ?? null;
			if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) { nu_flash('danger', t('upd_err_upload')); nu_redirect(nu_url('projects')); }
			[$pkg, $err] = NuProjectPackage::read((string)file_get_contents((string)$f['tmp_name']));
			if (!$pkg) { nu_flash('danger', $err); nu_redirect(nu_url('projects')); }
			$tok = bin2hex(random_bytes(12));
			file_put_contents($tmpOf($tok), NuProjectPackage::json($pkg));
			@chmod($tmpOf($tok), 0600);
			nu_redirect(nu_url('projects', ['import' => $tok]));
		}
		$tok = nu_post('token');
		$file = $tmpOf($tok);
		if ($action === 'import_cancel') { if ($file !== '') @unlink($file); nu_redirect(nu_url('projects')); }
		NuSecurity::requireRecentAuth(nu_url('projects', ['import' => $tok]));
		[$pkg, $err] = $file !== '' && is_file($file) ? NuProjectPackage::read((string)file_get_contents($file)) : [null, t('prj_err_package')];
		if (!$pkg) { nu_flash('danger', $err); nu_redirect(nu_url('projects')); }
		$acts = is_array($_POST['act'] ?? null) ? array_map('strval', $_POST['act']) : [];
		try {
			$r = NuProjectPackage::import($pkg, $acts);
		} catch (Throwable $e) {
			nu_flash('danger', $e->getMessage());
			nu_redirect(nu_url('projects', ['import' => $tok]));
		}
		@unlink($file);
		$parts = [];
		foreach ($r['done'] as $k => $n) $parts[] = $n . ' × ' . t('prj_kind_' . $k);
		nu_flash($r['errors'] ? 'warning' : 'success', t('prj_imp_done', $parts ? implode(', ', $parts) : '0', $r['skipped']));
		foreach (array_slice($r['errors'], 0, 10) as $e) nu_flash('danger', $e);
		nu_flash('info', t('prj_imp_rights'));
		nu_redirect($r['project'] !== '' ? $back($r['project']) : nu_url('projects'));
	}
	if (!$canChange) { nu_flash('danger', t('prj_err_rights')); nu_redirect(nu_url('projects')); }
	if ($action === 'save') {
		[$newId, $errors] = NuProject::save($pid, $_POST);
		if ($errors) {
			$_SESSION['nu_prj_form'] = ['in' => $_POST, 'errors' => $errors];
			nu_redirect($pid !== '' ? $back($pid, 'settings') : nu_url('projects', ['id' => 'new']));
		}
		nu_flash('success', t('saved'));
		nu_redirect($back($newId, $pid !== '' ? 'settings' : ''));
	}
	if (!NuProject::load($pid)) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('projects')); }
	switch ($action) {
		case 'delete':
			NuProject::delete($pid);
			nu_flash('success', t('prj_deleted'));
			nu_redirect(nu_url('projects'));
		case 'add_items':
			$list = [];
			foreach ((array)($_POST['items'] ?? []) as $x) if (is_string($x) && str_contains($x, '|')) $list[] = explode('|', $x, 2);
			$n = NuProject::addItems($pid, $list);
			nu_flash($n ? 'success' : 'info', t('prj_items_added', $n));
			nu_redirect($back($pid, 'items'));
		case 'remove_item':
			NuProject::removeItem($pid, nu_post('item'));
			nu_flash('success', t('prj_item_removed'));
			nu_redirect($back($pid, 'items'));
		case 'note_save':
			[, $e] = NuProject::saveNote($pid, nu_post('note'), $_POST);
			nu_flash($e ? 'danger' : 'success', $e ? implode(' ', $e) : t('saved'));
			nu_redirect($back($pid, 'notes'));
		case 'note_delete':
			NuProject::deleteNote($pid, nu_post('note'));
			nu_redirect($back($pid, 'notes'));
		case 'task_save':
			[, $e] = NuProject::saveTask($pid, nu_post('task'), $_POST);
			nu_flash($e ? 'danger' : 'success', $e ? implode(' ', $e) : t('saved'));
			nu_redirect($back($pid, 'tasks'));
		case 'task_done':
			NuProject::setTaskStatus($pid, nu_post('task'), nu_check('done') === '1');
			nu_redirect(nu_post('from') === 'overview' ? $back($pid) : $back($pid, 'tasks'));
		case 'task_delete':
			NuProject::deleteTask($pid, nu_post('task'));
			nu_redirect($back($pid, 'tasks'));
	}
	nu_redirect($back($pid));
}

$kindText = static fn(string $k): string => t('prj_kind_' . $k);
$kindIcon = static fn(string $k): string => '<i class="bi ' . h(NuProject::KINDS[$k][4] ?? 'bi-dot') . ' text-body-secondary" aria-hidden="true"></i>';
$workBtn = static function (array $p, string $to = '') : string {
	$on = NuProject::working() === (string)$p['zzzzsys_nunext_project_id'];
	return '<form method="post" action="' . h(nu_url('projects')) . '" class="m-0" id="prjWorkForm">' . nu_csrf_field() . '<input type="hidden" name="action" value="work">'
		. '<input type="hidden" name="project" value="' . h((string)$p['zzzzsys_nunext_project_id']) . '"><input type="hidden" name="on" value="' . ($on ? '0' : '1') . '">'
		. '<input type="hidden" name="to" value="' . h($to) . '">'
		. '<button type="submit" class="btn ' . ($on ? 'btn-primary' : 'nuBtnAct') . '" id="prjWork" title="' . h(t('prj_working_tip')) . '"><i class="bi ' . ($on ? 'bi-pin-angle-fill' : 'bi-pin-angle') . ' me-1"></i>'
		. h(t($on ? 'prj_working_stop' : 'prj_working_start')) . '</button></form>';
};

// ------------------------------------------------------------------ import: check and choices
if (isset($_GET['import'])) {
	if (!$ga) { nu_flash('danger', t('uf_edit_globeadmin')); nu_redirect(nu_url('projects')); }
	$tok = (string)$_GET['import'];
	$file = $tmpOf($tok);
	[$pkg, $err] = $file !== '' && is_file($file) ? NuProjectPackage::read((string)file_get_contents($file)) : [null, t('prj_err_package')];
	if (!$pkg) { nu_flash('danger', $err); nu_redirect(nu_url('projects')); }
	$plan = NuProjectPackage::check($pkg);
	$stateBadge = ['new' => 'text-bg-success', 'same' => 'text-bg-light border', 'different' => 'text-bg-warning', 'conflict' => 'text-bg-danger', 'exists' => 'text-bg-light border', 'unsupported' => 'text-bg-secondary'];
	nu_layout_start(t('prj_import'), 'home');
	echo nu_page_header(t('prj_import'), ['icon' => 'bi-box-arrow-in-down', 'crumbs' => [[t('prj_title'), nu_url('projects')]], 'back' => nu_url('projects'),
		'sub' => t('prj_imp_from', (string)$pkg['project']['name'], (string)($pkg['version'] ?? '?'), (string)($pkg['exported'] ?? ''))]);
	?>
	<form method="post" action="<?= h(nu_url('projects')) ?>" id="prjImportForm" data-nu-once data-confirm="<?= h(t('prj_imp_confirm')) ?>" data-confirm-ok="<?= h(t('prj_import')) ?>" data-confirm-class="btn btn-primary">
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="import_run"><input type="hidden" name="token" value="<?= h($tok) ?>">
		<div class="card shadow-sm mb-3"><div class="card-body small">
			<p class="mb-2"><?= h(t('prj_imp_intro')) ?></p>
			<div class="table-responsive"><table class="table table-sm align-middle nu-table mb-0" id="prjImportTable">
				<thead><tr><th><?= h(t('prj_kind')) ?></th><th><?= h(t('prj_item')) ?></th><th><?= h(t('prj_imp_state')) ?></th><th><?= h(t('prj_imp_action')) ?></th></tr></thead>
				<tbody>
				<?php foreach ($plan as $c): ?>
					<tr data-key="<?= h($c['key']) ?>" data-state="<?= h($c['state']) ?>">
						<td class="text-nowrap"><?= $c['kind'] === 'project' ? '<i class="bi bi-kanban text-body-secondary"></i>' : $kindIcon($c['kind']) ?> <?= h($c['kind'] === 'project' ? t('prj_title_one') : $kindText($c['kind'])) ?></td>
						<td><?= h($c['label']) ?><?= $c['note'] !== '' ? '<div class="text-body-secondary">' . h($c['note']) . '</div>' : '' ?></td>
						<td><span class="badge <?= $stateBadge[$c['state']] ?? 'text-bg-light' ?>"><?= h(t('prj_st_' . $c['state'])) ?></span></td>
						<td><?php if (count($c['actions']) > 1): ?>
							<select class="form-select form-select-sm prjImpAct" name="act[<?= h($c['key']) ?>]" aria-label="<?= h(t('prj_imp_action')) ?>">
								<?php foreach ($c['actions'] as $a): ?><option value="<?= h($a) ?>"<?= $a === $c['action'] ? ' selected' : '' ?>><?= h(t('prj_act_' . $a)) ?></option><?php endforeach; ?>
							</select>
						<?php else: ?><span class="text-body-secondary"><?= h(t('prj_act_skip')) ?></span><input type="hidden" name="act[<?= h($c['key']) ?>]" value="skip"><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p class="mt-2 mb-0 text-body-secondary"><i class="bi bi-info-circle me-1"></i><?= h(t('prj_imp_rights')) ?></p>
		</div></div>
		<div class="d-flex gap-2"><button type="submit" class="btn btn-primary" id="prjImportRun" data-nu-busy="<?= h(t('prj_importing')) ?>"><i class="bi bi-box-arrow-in-down me-1"></i><?= h(t('prj_import')) ?></button>
			<button type="submit" class="btn btn-outline-secondary" name="action" value="import_cancel" form="prjImportCancel"><?= h(t('cancel')) ?></button></div>
	</form>
	<form method="post" action="<?= h(nu_url('projects')) ?>" id="prjImportCancel"><?= nu_csrf_field() ?><input type="hidden" name="action" value="import_cancel"><input type="hidden" name="token" value="<?= h($tok) ?>"></form>
	<?php
	nu_layout_end();
	return;
}

// ------------------------------------------------------------------ list
if ($id === '') {
	$st = NuListView::prepare('projects');
	nu_layout_start(t('prj_title'), 'home', ['head' => NuListView::head()]);
	echo NuListView::crumbs([[t('prj_title')]]);
	$w = NuProject::working();
	$btns = ($ga ? '<button class="btn nuBtnAct" type="button" data-bs-toggle="modal" data-bs-target="#prjImportDlg" id="prjImportBtn"><i class="bi bi-box-arrow-in-down me-1"></i>' . h(t('prj_import')) . '</button>' : '')
		. ($canChange ? '<a class="btn nuAddBtn" href="' . h(nu_url('projects', ['id' => 'new'])) . '" id="prjNew"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '');
	$above = '';
	if ($w !== '' && ($wp = NuProject::load($w))) {
		$above = '<div class="alert alert-primary small py-2 mx-3 mt-3 d-flex flex-wrap gap-2 align-items-center" id="prjWorkingNote"><i class="bi bi-pin-angle-fill"></i><span>' . h(t('prj_working_on', (string)$wp['name'])) . '</span>'
			. '<a class="ms-auto" href="' . h(nu_url('projects', ['id' => $w])) . '">' . h(t('open')) . '</a></div>';
	}
	$mine = NuProject::myTasks();
	if ($mine) {
		$above .= '<div class="mx-3 mt-3 small" id="prjMyTasks"><div class="fw-semibold mb-1"><i class="bi bi-check2-square me-1"></i>' . h(t('prj_my_tasks', count($mine))) . '</div><ul class="mb-0 ps-3">';
		foreach (array_slice($mine, 0, 5) as $k) {
			$due = (string)$k['due'] !== '' ? substr((string)$k['due'], 0, 10) : '';
			$late = $due !== '' && $due < date('Y-m-d');
			$above .= '<li><a href="' . h(nu_url('projects', ['id' => (string)$k['project_id'], 'tab' => 'tasks'])) . '">' . h((string)$k['title']) . '</a> <span class="text-body-secondary">· ' . h((string)$k['project_name'])
				. ($due !== '' ? ' · <span class="' . ($late ? 'text-danger fw-semibold' : '') . '">' . h(t('prj_due_on', date(nu_dfmt('d.m.Y'), (int)strtotime($due)))) . '</span>' : '') . '</span></li>';
		}
		$above .= '</ul></div>';
	}
	echo NuListView::card($st, ['title_extra' => NuListView::infoTip(t('prj_list_tip')), 'buttons' => $btns, 'above_rows' => $above]);
	if ($ga): ?>
	<div class="modal fade" id="prjImportDlg" tabindex="-1" aria-labelledby="prjImportTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post" enctype="multipart/form-data" action="<?= h(nu_url('projects')) ?>" id="prjImportUpload">
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="import_upload">
		<div class="modal-header"><h2 class="modal-title h5" id="prjImportTitle"><?= h(t('prj_import')) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
		<div class="modal-body"><p class="small text-body-secondary"><?= h(t('prj_import_tip')) ?></p>
			<input class="form-control" type="file" name="file" accept=".json,application/json" required id="prjImportFile"></div>
		<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button><button type="submit" class="btn btn-primary" id="prjImportCheck"><?= h(t('prj_imp_check')) ?></button></div>
	</form></div></div>
	<?php endif;
	nu_layout_end(NuListView::foot());
	return;
}

// ------------------------------------------------------------------ new project
$p = $id !== 'new' ? NuProject::load($id) : null;
if ($id !== 'new' && !$p) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('projects')); }
$form = $_SESSION['nu_prj_form'] ?? null;
unset($_SESSION['nu_prj_form']);
$fv = static fn(string $k): string => (string)($form['in'][$k] ?? ($p[$k] ?? ''));
$fe = (array)($form['errors'] ?? []);
$settingsForm = static function () use ($p, $fv, $fe, $canChange): string {
	$dis = $canChange ? '' : ' disabled';
	$err = static fn(string $k) => isset($fe[$k]) ? '<div class="invalid-feedback d-block">' . h($fe[$k]) . '</div>' : '';
	$inv = static fn(string $k) => isset($fe[$k]) ? ' is-invalid' : '';
	return '<form method="post" action="' . h(nu_url('projects')) . '" id="prjForm" data-nu-guard class="card shadow-sm"><div class="card-body">' . nu_csrf_field()
		. '<input type="hidden" name="action" value="save"><input type="hidden" name="project" value="' . h((string)($p['zzzzsys_nunext_project_id'] ?? '')) . '">'
		. '<div class="row g-3"><div class="col-md-6"><label class="form-label" for="prjName">' . h(t('prj_f_name')) . '</label><input class="form-control' . $inv('name') . '" id="prjName" name="name" maxlength="200" required value="' . h($fv('name')) . '"' . $dis . '>' . $err('name') . '</div>'
		. '<div class="col-md-4"><label class="form-label" for="prjCode">' . h(t('prj_f_code')) . '</label>' . NuListView::infoTip(t('prj_f_code_tip')) . '<input class="form-control font-monospace' . $inv('code') . '" id="prjCode" name="code" maxlength="60" pattern="[A-Za-z0-9_.\-]+" value="' . h($fv('code')) . '"' . $dis . '>' . $err('code') . '</div>'
		. '<div class="col-md-2"><label class="form-label" for="prjStatus">' . h(t('status')) . '</label><select class="form-select" id="prjStatus" name="status"' . $dis . '>'
		. '<option value="active"' . ($fv('status') !== 'archived' ? ' selected' : '') . '>' . h(t('prj_active')) . '</option><option value="archived"' . ($fv('status') === 'archived' ? ' selected' : '') . '>' . h(t('prj_archived')) . '</option></select></div>'
		. '<div class="col-12"><label class="form-label" for="prjDesc">' . h(t('prj_f_description')) . '</label><textarea class="form-control" id="prjDesc" name="description" rows="3" maxlength="2000"' . $dis . '>' . h($fv('description')) . '</textarea></div></div>'
		. ($canChange ? '<div class="mt-3"><button type="submit" class="btn btn-primary nuBtnSave" data-nu-save id="prjSave"><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button></div>' : '')
		. '</div></form>';
};
if (!$p) {
	if (!$canChange) { nu_flash('danger', t('prj_err_rights')); nu_redirect(nu_url('projects')); }
	nu_layout_start(t('prj_new'), 'home');
	echo nu_page_header(t('prj_new'), ['icon' => 'bi-kanban', 'crumbs' => [[t('prj_title'), nu_url('projects')]], 'back' => nu_url('projects')]);
	echo $settingsForm();
	nu_layout_end();
	return;
}

// ------------------------------------------------------------------ project page
$pid = (string)$p['zzzzsys_nunext_project_id'];
$items = NuProject::items($pid);
$notes = NuProject::notes($pid);
$tasks = NuProject::tasks($pid);
$people = NuProject::people();
$openTasks = array_values(array_filter($tasks, static fn($k) => (string)$k['status'] !== 'done'));
$byKind = [];
foreach ($items as $it) $byKind[$it['kind']][] = $it;
$itemLabel = [];
foreach ($items as $it) $itemLabel[$it['kind'] . '|' . $it['ref']] = $it['label'];
$fmtDate = static fn(string $d): string => $d !== '' ? date(nu_dfmt('d.m.Y'), (int)strtotime($d)) : '';
$fmtTime = static fn(string $d): string => $d !== '' ? date(nu_dfmt('d.m.Y H:i'), (int)strtotime($d)) : '';
$tabs = ['overview' => ['bi-speedometer2', t('prj_tab_overview'), ''], 'items' => ['bi-boxes', t('prj_tab_items'), (string)count($items)], 'notes' => ['bi-journal-text', t('prj_tab_notes'), (string)count($notes)],
	'tasks' => ['bi-check2-square', t('prj_tab_tasks'), (string)count($openTasks)], 'settings' => ['bi-gear', t('prj_tab_settings'), '']];
$exportMenu = $canChange ? '<div class="dropdown"><button class="btn nuBtnAct dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="prjExportBtn"><i class="bi bi-box-arrow-up me-1"></i>' . h(t('prj_export')) . '</button>'
	. '<ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="' . h(nu_url('projects', ['export' => $pid])) . '" id="prjExport"><i class="bi bi-filetype-json me-2"></i>' . h(t('prj_export_plain')) . '</a></li>'
	. ($ga ? '<li><a class="dropdown-item" href="' . h(nu_url('projects', ['export' => $pid, 'data' => '1'])) . '" id="prjExportData"><i class="bi bi-database me-2"></i>' . h(t('prj_export_data')) . '</a></li>' : '')
	. '<li><hr class="dropdown-divider"></li><li><span class="dropdown-item-text small text-body-secondary nuPrjExportTip">' . h(t('prj_export_tip')) . '</span></li></ul></div>' : '';
nu_layout_start((string)$p['name'], 'home', ['head' => nu_ts_head()]);
echo nu_page_header((string)$p['name'], ['icon' => 'bi-kanban', 'crumbs' => [[t('prj_title'), nu_url('projects')]], 'back' => nu_url('projects'),
	'sub' => (string)$p['code'] . ((string)$p['status'] === 'archived' ? ' · ' . t('prj_archived') : ''), 'buttons' => $workBtn($p) . $exportMenu]);
?>
<ul class="nav nav-tabs mb-3" id="prjTabs" role="tablist">
	<?php foreach ($tabs as $k => [$ic, $lab, $n]): ?>
	<li class="nav-item" role="presentation"><a class="nav-link<?= $k === $tab ? ' active' : '' ?>" id="prjTab-<?= $k ?>" data-bs-toggle="tab" href="#prjPane-<?= $k ?>" role="tab" aria-controls="prjPane-<?= $k ?>" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>"
		data-nu-tabfield="prjTab" data-tab="<?= $k ?>"><i class="bi <?= $ic ?> me-1"></i><?= h($lab) ?><?= $n !== '' && $n !== '0' ? ' <span class="badge rounded-pill text-bg-light border">' . h($n) . '</span>' : '' ?></a></li>
	<?php endforeach; ?>
</ul>
<div class="tab-content" id="prjPanes" data-project="<?= h($pid) ?>" data-url="<?= h(nu_url('projects')) ?>" data-csrf="<?= h(nu_csrf_token()) ?>"
	data-t-none="<?= h(t('prj_none_found')) ?>" data-t-member="<?= h(t('prj_already_member')) ?>" data-t-why="<?= h(t('prj_used_by')) ?>" data-t-loading="<?= h(t('loading')) ?>">

<?php // ------------------------------------------------------------ overview ?>
<div class="tab-pane fade<?= $tab === 'overview' ? ' show active' : '' ?>" id="prjPane-overview" role="tabpanel" aria-labelledby="prjTab-overview" tabindex="0">
	<div class="row g-3">
		<div class="col-lg-7">
			<?php if ((string)$p['description'] !== ''): ?><div class="card shadow-sm mb-3"><div class="card-body" id="prjDescription"><?= nl2br(h((string)$p['description'])) ?></div></div><?php endif; ?>
			<div class="card shadow-sm mb-3"><div class="card-header fw-semibold"><?= h(t('prj_tab_items')) ?></div><div class="card-body" id="prjCounts">
				<?php if (!$items): ?><p class="text-body-secondary mb-0"><?= h(t('prj_no_items')) ?> <a href="<?= h($back($pid, 'items')) ?>"><?= h(t('prj_add_items')) ?></a></p>
				<?php else: ?><div class="d-flex flex-wrap gap-2"><?php foreach ($byKind as $k => $list): ?>
					<a class="btn btn-sm btn-outline-secondary" href="<?= h($back($pid, 'items')) ?>#prjKind-<?= h($k) ?>"><?= $kindIcon($k) ?> <?= h($kindText($k)) ?> <span class="badge rounded-pill text-bg-light border"><?= count($list) ?></span></a>
				<?php endforeach; ?></div><?php endif; ?>
			</div></div>
			<?php $ch = NuProject::changes($pid); ?>
			<div class="card shadow-sm mb-3"><div class="card-header fw-semibold"><?= h(t('prj_changes')) ?></div><div class="card-body small" id="prjChanges">
				<?php foreach ($ch['drafts'] as $d): ?><div class="mb-1"><span class="badge text-bg-warning me-1"><?= h(t('forms_col_draft')) ?></span><a href="<?= h(nu_url('builder', ['f' => (string)$d['form_id']])) ?>"><?= h($itemLabel['form|' . $d['form_id']] ?? (string)$d['form_id']) ?></a> · <?= h((string)$d['created_by']) ?></div><?php endforeach; ?>
				<?php if (!$ch['changes'] && !$ch['drafts']): ?><p class="text-body-secondary mb-0"><?= h(t('prj_no_changes')) ?></p><?php endif; ?>
				<?php if ($ch['changes']): ?><table class="table table-sm nu-table mb-0"><tbody><?php foreach ($ch['changes'] as $c): ?>
					<tr><td class="text-nowrap text-body-secondary"><?= h($fmtTime((string)$c['created_at'])) ?></td><td><a href="<?= h(nu_url('builder', ['f' => (string)$c['form_id']])) ?>"><?= h($itemLabel['form|' . $c['form_id']] ?? (string)$c['form_id']) ?></a></td><td><?= h((string)$c['action']) ?></td><td class="text-body-secondary"><?= h((string)$c['login']) ?></td></tr>
				<?php endforeach; ?></tbody></table><?php endif; ?>
			</div></div>
		</div>
		<div class="col-lg-5">
			<div class="card shadow-sm mb-3"><div class="card-header fw-semibold d-flex"><span class="me-auto"><?= h(t('prj_open_tasks')) ?></span><a class="small" href="<?= h($back($pid, 'tasks')) ?>"><?= h(t('prj_all')) ?></a></div><div class="card-body small" id="prjOverviewTasks">
				<?php if (!$openTasks): ?><p class="text-body-secondary mb-0"><?= h(t('prj_no_tasks')) ?></p><?php endif; ?>
				<?php foreach (array_slice($openTasks, 0, 8) as $k): $due = substr((string)$k['due'], 0, 10); ?>
					<form method="post" action="<?= h(nu_url('projects')) ?>" class="d-flex gap-2 align-items-start mb-1"><?= nu_csrf_field() ?><input type="hidden" name="action" value="task_done"><input type="hidden" name="project" value="<?= h($pid) ?>"><input type="hidden" name="task" value="<?= h((string)$k['zzzzsys_nunext_project_task_id']) ?>"><input type="hidden" name="from" value="overview">
						<input class="form-check-input mt-1 js-autosubmit" type="checkbox" name="done" value="1" aria-label="<?= h(t('prj_task_done')) ?>"<?= $canChange ? '' : ' disabled' ?>>
						<span><?= h((string)$k['title']) ?><?= (string)$k['assignee'] !== '' ? ' <span class="text-body-secondary">· ' . h($people[(string)$k['assignee']] ?? (string)$k['assignee']) . '</span>' : '' ?><?= $due !== '' ? ' <span class="' . ($due < date('Y-m-d') ? 'text-danger fw-semibold' : 'text-body-secondary') . '">· ' . h($fmtDate($due)) . '</span>' : '' ?></span></form>
				<?php endforeach; ?>
			</div></div>
			<?php $pinned = array_values(array_filter($notes, static fn($n) => (string)$n['pinned'] === '1')); if ($pinned): ?>
			<div class="card shadow-sm mb-3"><div class="card-header fw-semibold"><i class="bi bi-pin-fill me-1"></i><?= h(t('prj_pinned_notes')) ?></div><div class="card-body small nuPrjMd" id="prjPinned">
				<?php foreach ($pinned as $n): ?><div class="fw-semibold"><?= h((string)$n['title']) ?></div><div class="mb-2"><?= NuMarkdown::toHtml((string)$n['body']) ?></div><?php endforeach; ?>
			</div></div><?php endif; ?>
		</div>
	</div>
</div>

<?php // ------------------------------------------------------------ members ?>
<div class="tab-pane fade<?= $tab === 'items' ? ' show active' : '' ?>" id="prjPane-items" role="tabpanel" aria-labelledby="prjTab-items" tabindex="0">
	<?php if ($canChange): ?><div class="d-flex flex-wrap gap-2 mb-3">
		<button class="btn nuAddBtn" type="button" data-bs-toggle="modal" data-bs-target="#prjAddDlg" id="prjAddBtn"><i class="bi bi-plus-lg me-1"></i><?= h(t('prj_add_items')) ?></button>
		<button class="btn nuBtnAct" type="button" data-bs-toggle="modal" data-bs-target="#prjSuggestDlg" id="prjSuggestBtn"><i class="bi bi-magic me-1"></i><?= h(t('prj_suggest')) ?></button>
	</div><?php endif; ?>
	<?php if (!$items): ?><div class="card shadow-sm"><div class="card-body text-body-secondary"><?= h(t('prj_no_items')) ?> <?= NuListView::infoTip(t('prj_items_tip')) ?></div></div><?php endif; ?>
	<?php foreach ($byKind as $k => $list): ?>
	<div class="card shadow-sm mb-3" id="prjKind-<?= h($k) ?>"><div class="card-header fw-semibold"><?= $kindIcon($k) ?> <?= h($kindText($k)) ?> <span class="badge rounded-pill text-bg-light border"><?= count($list) ?></span></div>
		<div class="table-responsive"><table class="table table-sm align-middle nu-table mb-0 prjItems"><tbody>
		<?php foreach ($list as $it): ?>
			<tr data-kind="<?= h($it['kind']) ?>" data-ref="<?= h($it['ref']) ?>"<?= $it['missing'] ? ' class="table-warning"' : '' ?>>
				<td><?php if ($it['url'] !== '' && !$it['missing']): ?><a href="<?= h($it['url']) ?>" target="_blank" rel="noopener" class="prjItemLink"><?= h($it['label']) ?> <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i></a><?php else: ?><?= h($it['label']) ?><?php endif; ?>
					<?= $it['sub'] !== '' ? '<div class="small text-body-secondary font-monospace">' . h($it['sub']) . '</div>' : '' ?><?= $it['missing'] ? '<div class="small text-danger">' . h(t('prj_item_missing')) . '</div>' : '' ?></td>
				<td class="small text-body-secondary text-nowrap d-none d-md-table-cell"><?= h($fmtDate($it['added_at'])) ?> · <?= h($it['added_by']) ?></td>
				<td class="text-end"><?php if ($canChange): ?><form method="post" action="<?= h(nu_url('projects')) ?>" class="d-inline m-0"><?= nu_csrf_field() ?><input type="hidden" name="action" value="remove_item"><input type="hidden" name="project" value="<?= h($pid) ?>"><input type="hidden" name="item" value="<?= h($it['id']) ?>">
					<button type="submit" class="btn btn-sm btn-outline-secondary nuRowBtn prjRemove" title="<?= h(t('prj_remove_item')) ?>" aria-label="<?= h(t('prj_remove_item')) ?>"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table></div></div>
	<?php endforeach; ?>
</div>

<?php // ------------------------------------------------------------ notes ?>
<div class="tab-pane fade<?= $tab === 'notes' ? ' show active' : '' ?>" id="prjPane-notes" role="tabpanel" aria-labelledby="prjTab-notes" tabindex="0">
	<?php $noteForm = static function (?array $n) use ($pid): string {
		$nid = $n ? (string)$n['zzzzsys_nunext_project_note_id'] : '';
		return '<form method="post" action="' . h(nu_url('projects')) . '" class="prjNoteForm" data-nu-guard>' . nu_csrf_field() . '<input type="hidden" name="action" value="note_save"><input type="hidden" name="project" value="' . h($pid) . '"><input type="hidden" name="note" value="' . h($nid) . '">'
			. '<input class="form-control mb-2" name="title" maxlength="200" required placeholder="' . h(t('prj_note_title')) . '" aria-label="' . h(t('prj_note_title')) . '" value="' . h((string)($n['title'] ?? '')) . '">'
			. '<textarea class="form-control font-monospace mb-2" name="body" rows="8" placeholder="' . h(t('prj_note_body_ph')) . '" aria-label="' . h(t('prj_note_body')) . '">' . h((string)($n['body'] ?? '')) . '</textarea>'
			. '<div class="d-flex flex-wrap gap-2 align-items-center"><div class="form-check me-auto mb-0"><input class="form-check-input" type="checkbox" name="pinned" value="1" id="prjPin' . h($nid ?: 'new') . '"' . (($n['pinned'] ?? '') === '1' ? ' checked' : '') . '><label class="form-check-label" for="prjPin' . h($nid ?: 'new') . '">' . h(t('prj_note_pinned')) . '</label></div>'
			. '<span class="small text-body-secondary">' . h(t('prj_md_hint')) . '</span><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button></div></form>';
	}; ?>
	<?php if ($canChange): ?><details class="card shadow-sm mb-3" id="prjNoteNew"<?= !$notes ? ' open' : '' ?>><summary class="card-header fw-semibold"><i class="bi bi-plus-lg me-1"></i><?= h(t('prj_note_new')) ?></summary><div class="card-body"><?= $noteForm(null) ?></div></details><?php endif; ?>
	<?php if (!$notes): ?><p class="text-body-secondary"><?= h(t('prj_no_notes')) ?></p><?php endif; ?>
	<?php foreach ($notes as $n): $nid = (string)$n['zzzzsys_nunext_project_note_id']; ?>
	<div class="card shadow-sm mb-3 prjNote" id="prjNote-<?= h($nid) ?>">
		<div class="card-header d-flex flex-wrap gap-2 align-items-center"><span class="fw-semibold me-auto"><?= (string)$n['pinned'] === '1' ? '<i class="bi bi-pin-fill me-1" title="' . h(t('prj_note_pinned')) . '"></i>' : '' ?><?= h((string)$n['title']) ?></span>
			<span class="small text-body-secondary"><?= h($fmtTime((string)$n['updated_at'])) ?> · <?= h((string)$n['updated_by']) ?></span>
			<?php if ($canChange): ?><button type="button" class="btn btn-sm btn-outline-secondary nuRowBtn prjNoteEdit" data-note="<?= h($nid) ?>" title="<?= h(t('edit')) ?>" aria-label="<?= h(t('edit')) ?>"><i class="bi bi-pencil"></i></button>
			<form method="post" action="<?= h(nu_url('projects')) ?>" class="m-0" data-confirm="<?= h(t('prj_note_delete_confirm', (string)$n['title'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="note_delete"><input type="hidden" name="project" value="<?= h($pid) ?>"><input type="hidden" name="note" value="<?= h($nid) ?>">
				<button type="submit" class="btn btn-sm btn-outline-danger nuRowBtn" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete')) ?>"><i class="bi bi-trash"></i></button></form><?php endif; ?></div>
		<div class="card-body nuPrjMd prjNoteView"><?= NuMarkdown::toHtml((string)$n['body']) ?></div>
		<?php if ($canChange): ?><div class="card-body prjNoteEditBox" hidden><?= $noteForm($n) ?></div><?php endif; ?>
	</div>
	<?php endforeach; ?>
</div>

<?php // ------------------------------------------------------------ to-dos ?>
<div class="tab-pane fade<?= $tab === 'tasks' ? ' show active' : '' ?>" id="prjPane-tasks" role="tabpanel" aria-labelledby="prjTab-tasks" tabindex="0">
	<?php $editTask = null; foreach ($tasks as $k) if ((string)$k['zzzzsys_nunext_project_task_id'] === (string)($_GET['task'] ?? '')) $editTask = $k; ?>
	<?php if ($canChange): ?>
	<details class="card shadow-sm mb-3" id="prjTaskEdit"<?= $editTask || !$tasks ? ' open' : '' ?>><summary class="card-header fw-semibold"><i class="bi <?= $editTask ? 'bi-pencil' : 'bi-plus-lg' ?> me-1"></i><?= h(t($editTask ? 'prj_task_edit' : 'prj_task_new')) ?></summary>
		<form method="post" action="<?= h(nu_url('projects')) ?>" class="card-body" id="prjTaskForm" data-nu-guard><?= nu_csrf_field() ?><input type="hidden" name="action" value="task_save"><input type="hidden" name="project" value="<?= h($pid) ?>"><input type="hidden" name="task" value="<?= h((string)($editTask['zzzzsys_nunext_project_task_id'] ?? '')) ?>">
			<div class="row g-2">
				<div class="col-md-6"><label class="form-label small" for="prjTaskTitle"><?= h(t('prj_task_title')) ?></label><input class="form-control" id="prjTaskTitle" name="title" maxlength="300" required value="<?= h((string)($editTask['title'] ?? '')) ?>"></div>
				<div class="col-md-3"><label class="form-label small" for="prjTaskAssignee"><?= h(t('prj_task_assignee')) ?></label><?= NuListView::infoTip(t('prj_task_assignee_tip')) ?>
					<select class="form-select nuListTomSelect" id="prjTaskAssignee" name="assignee"><option value=""><?= h(t('prj_nobody')) ?></option><?php foreach ($people as $uid => $nm): ?><option value="<?= h((string)$uid) ?>"<?= (string)($editTask['assignee'] ?? '') === (string)$uid ? ' selected' : '' ?>><?= h($nm) ?></option><?php endforeach; ?></select></div>
				<div class="col-md-3"><label class="form-label small" for="prjTaskDue"><?= h(t('prj_task_due')) ?></label><input class="form-control" type="date" id="prjTaskDue" name="due" value="<?= h(substr((string)($editTask['due'] ?? ''), 0, 10)) ?>"></div>
				<div class="col-md-6"><label class="form-label small" for="prjTaskItem"><?= h(t('prj_task_item')) ?></label><select class="form-select" id="prjTaskItem" name="item"><option value=""></option>
					<?php foreach ($items as $it): $key = $it['kind'] . '|' . $it['ref']; ?><option value="<?= h($key) ?>"<?= (string)($editTask['item'] ?? '') === $key ? ' selected' : '' ?>><?= h($kindText($it['kind']) . ': ' . $it['label']) ?></option><?php endforeach; ?></select></div>
				<div class="col-md-6"><label class="form-label small" for="prjTaskBody"><?= h(t('prj_task_body')) ?></label><textarea class="form-control" id="prjTaskBody" name="body" rows="2" maxlength="4000"><?= h((string)($editTask['body'] ?? '')) ?></textarea></div>
			</div>
			<div class="mt-2 d-flex gap-2"><button type="submit" class="btn btn-primary btn-sm" id="prjTaskSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php if ($editTask): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($back($pid, 'tasks')) ?>"><?= h(t('cancel')) ?></a><?php endif; ?></div>
		</form>
	</details>
	<?php endif; ?>
	<?php if (!$tasks): ?><p class="text-body-secondary"><?= h(t('prj_no_tasks')) ?></p><?php else: ?>
	<div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm align-middle nu-table mb-0" id="prjTasks">
		<thead><tr><th class="text-center" style="width:1%"><span class="visually-hidden"><?= h(t('prj_task_done')) ?></span></th><th><?= h(t('prj_task_title')) ?></th><th><?= h(t('prj_task_assignee')) ?></th><th><?= h(t('prj_task_due')) ?></th><th class="d-none d-md-table-cell"><?= h(t('prj_task_item')) ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ($tasks as $k): $kid = (string)$k['zzzzsys_nunext_project_task_id']; $done = (string)$k['status'] === 'done'; $due = substr((string)$k['due'], 0, 10); ?>
			<tr data-task="<?= h($kid) ?>"<?= $done ? ' class="text-body-secondary prjTaskDone"' : '' ?>>
				<td class="text-center"><form method="post" action="<?= h(nu_url('projects')) ?>" class="m-0"><?= nu_csrf_field() ?><input type="hidden" name="action" value="task_done"><input type="hidden" name="project" value="<?= h($pid) ?>"><input type="hidden" name="task" value="<?= h($kid) ?>">
					<input class="form-check-input js-autosubmit prjTaskCheck" type="checkbox" name="done" value="1"<?= $done ? ' checked' : '' ?> aria-label="<?= h(t('prj_task_done')) ?>"<?= $canChange ? '' : ' disabled' ?>></form></td>
				<td><?= $done ? '<s>' . h((string)$k['title']) . '</s>' : h((string)$k['title']) ?><?= (string)$k['body'] !== '' ? '<div class="small text-body-secondary">' . nl2br(h((string)$k['body'])) . '</div>' : '' ?></td>
				<td class="small"><?= h($people[(string)$k['assignee']] ?? (string)$k['assignee']) ?></td>
				<td class="small text-nowrap<?= !$done && $due !== '' && $due < date('Y-m-d') ? ' text-danger fw-semibold' : '' ?>"><?= h($fmtDate($due)) ?></td>
				<td class="small d-none d-md-table-cell"><?php if ((string)$k['item'] !== '' && isset($itemLabel[(string)$k['item']])): [$ik, $ir] = explode('|', (string)$k['item'], 2); ?><?= $kindIcon($ik) ?> <a href="<?= h(NuProject::url($ik, $ir)) ?>" target="_blank" rel="noopener"><?= h($itemLabel[(string)$k['item']]) ?></a><?php endif; ?></td>
				<td class="text-end text-nowrap"><?php if ($canChange): ?><a class="btn btn-sm btn-outline-secondary nuRowBtn" href="<?= h(nu_url('projects', ['id' => $pid, 'tab' => 'tasks', 'task' => $kid])) ?>#prjTaskEdit" title="<?= h(t('edit')) ?>" aria-label="<?= h(t('edit')) ?>"><i class="bi bi-pencil"></i></a>
					<form method="post" action="<?= h(nu_url('projects')) ?>" class="d-inline m-0" data-confirm="<?= h(t('prj_task_delete_confirm', (string)$k['title'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="task_delete"><input type="hidden" name="project" value="<?= h($pid) ?>"><input type="hidden" name="task" value="<?= h($kid) ?>">
					<button type="submit" class="btn btn-sm btn-outline-danger nuRowBtn" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete')) ?>"><i class="bi bi-trash"></i></button></form><?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table></div></div>
	<?php endif; ?>
</div>

<?php // ------------------------------------------------------------ settings ?>
<div class="tab-pane fade<?= $tab === 'settings' ? ' show active' : '' ?>" id="prjPane-settings" role="tabpanel" aria-labelledby="prjTab-settings" tabindex="0">
	<?= $settingsForm() ?>
	<?php if ($canChange): ?>
	<form method="post" action="<?= h(nu_url('projects')) ?>" class="mt-3" data-confirm="<?= h(t('prj_delete_confirm', (string)$p['name'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="project" value="<?= h($pid) ?>">
		<button type="submit" class="btn btn-outline-danger" id="prjDelete"><i class="bi bi-trash me-1"></i><?= h(t('prj_delete')) ?></button> <span class="small text-body-secondary ms-2"><?= h(t('prj_delete_tip')) ?></span></form>
	<?php endif; ?>
</div>
</div>

<?php if ($canChange): ?>
<div class="modal fade" id="prjAddDlg" tabindex="-1" aria-labelledby="prjAddTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" method="post" action="<?= h(nu_url('projects')) ?>" id="prjAddForm">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="add_items"><input type="hidden" name="project" value="<?= h($pid) ?>">
	<div class="modal-header"><h2 class="modal-title h5" id="prjAddTitle"><?= h(t('prj_add_items')) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
	<div class="modal-body">
		<div class="d-flex flex-wrap gap-2 mb-2">
			<select class="form-select w-auto" id="prjAddKind" aria-label="<?= h(t('prj_kind')) ?>"><?php foreach (array_keys(NuProject::KINDS) as $k): ?><option value="<?= h($k) ?>"><?= h($kindText($k)) ?></option><?php endforeach; ?></select>
			<input class="form-control flex-grow-1 w-auto" type="search" id="prjAddQ" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>">
		</div>
		<div id="prjAddList" class="list-group small" role="group" aria-label="<?= h(t('prj_add_items')) ?>"></div>
	</div>
	<div class="modal-footer"><span class="me-auto small text-body-secondary" id="prjAddCount" data-t="<?= h(t('prj_selected_n')) ?>"></span><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button><button type="submit" class="btn btn-primary" id="prjAddGo"><?= h(t('add')) ?></button></div>
</form></div></div>
<div class="modal fade" id="prjSuggestDlg" tabindex="-1" aria-labelledby="prjSuggestTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" method="post" action="<?= h(nu_url('projects')) ?>" id="prjSuggestForm">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="add_items"><input type="hidden" name="project" value="<?= h($pid) ?>">
	<div class="modal-header"><h2 class="modal-title h5" id="prjSuggestTitle"><?= h(t('prj_suggest')) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
	<div class="modal-body"><p class="small text-body-secondary"><?= h(t('prj_suggest_tip')) ?></p>
		<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="prjSuggestAll"><label class="form-check-label" for="prjSuggestAll"><?= h(t('prj_select_all')) ?></label></div>
		<div id="prjSuggestList" class="list-group small"></div></div>
	<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button><button type="submit" class="btn btn-primary" id="prjSuggestGo"><?= h(t('add')) ?></button></div>
</form></div></div>
<?php endif; ?>
<?php
nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nuprojects.js') . '"></script>');
