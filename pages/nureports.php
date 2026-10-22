<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Reports (zzzzsys_report) – nuBuilder's form "nubuildreport", rights of that form.
 *   list · ?id=<id> | ?id=new edit (tabs Report · Access levels · Usage · History) · the layout is edited in the designer (?p=reportdesigner&id=…)
 */

NuAcl::require(NuAcl::FORM_REPORT);
if (!NuReport::available()) {
	try { NuReport::ensureTables(); } catch (Throwable $e) { nu_fatal(t('rpt_err_tables'), NuAuth::user()['globeadmin'] ? $e->getMessage() : '', true); }
}
$db     = nu_db();
$rights = NuAcl::rights(NuAcl::FORM_REPORT);
$T      = NuReport::TABLE;

$id     = (string)($_GET['id'] ?? '');
$isNew  = $id === 'new';
$report = null;
if ($id !== '' && !$isNew) {
	$report = $db->one("SELECT * FROM $T WHERE zzzzsys_report_id = ?", [$id]);
	if (!$report) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
$locked = !($report ? $rights['save'] : $rights['create']);
$errors = [];

// ------------------------------------------------------------------ save / clone / delete
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		NuAcl::require(NuAcl::FORM_REPORT, $report ? 'save' : 'create');
		$in = [];
		foreach (['sre_code', 'sre_description', 'sre_group', 'sre_zzzzsys_form_id', 'sre_zzzzsys_php_id'] as $f) $in[$f] = $_POST[$f] ?? '';
		[$newId, $errors] = NuReport::save($in, $report ? $id : '');
		if (!$errors) {
			$after = $db->one("SELECT * FROM $T WHERE zzzzsys_report_id = ?", [$newId]) ?? [];
			$acc = isset($_POST['access_sent']) ? NuReport::setAccess($newId, array_filter((array)($_POST['access'] ?? []), 'is_string')) : ['added' => [], 'removed' => []];
			$details = ['code' => (string)$after['sre_code'], 'fields' => NuReport::diff($report, $after)];
			if ($acc['added']) $details['access_added'] = implode(', ', $acc['added']);
			if ($acc['removed']) $details['access_removed'] = implode(', ', $acc['removed']);
			if ($details['fields'] || count($details) > 2 || !$report) NuLog::write($report ? 'report_changed' : 'report_created', $T, $newId, $details);
			nu_flash('success', t('saved'));
			nu_redirect(nu_post('then') === 'design' ? nu_url('reportdesigner', ['id' => $newId]) : nu_url('reports', ['id' => $newId]));
		}
	}
	if ($action === 'clone' && $report) {
		NuAcl::require(NuAcl::FORM_REPORT, 'clone');
		$code = (string)$report['sre_code'];
		$new = $code;
		for ($n = 2; $db->value("SELECT COUNT(*) FROM $T WHERE sre_code = ?", [$new]); $n++) $new = mb_substr($code, 0, 290) . '_' . $n;
		$in = $report;
		$in['sre_code'] = $new;
		[$newId, $e] = NuReport::save($in);
		if (!$e) {
			NuReport::setAccess($newId, array_column(array_filter(NuReport::access($id), static fn($a) => $a['granted']), 'zzzzsys_access_id'));
			NuLog::write('report_created', $T, $newId, ['code' => $new, 'clone_of' => $code]);
			nu_flash('success', t('rpt_cloned'));
			nu_redirect(nu_url('reports', ['id' => $newId]));
		}
		$errors = ['_' => implode(' ', $e)];
	}
	if ($action === 'delete' && $report) {
		NuAcl::require(NuAcl::FORM_REPORT, 'delete');
		$objects = array_filter(NuReport::usage($id, (string)$report['sre_code']), static fn($u) => $u['kind'] === 'object');
		if ($objects) {
			$errors['_'] = t('rpt_err_used', implode(', ', array_map(static fn($u) => trim($u['form'] . ' · ' . $u['where'], ' ·'), $objects)));
		} else {
			$db->transaction(static function (NuDB $db) use ($id, $T) {
				$db->delete(NuReport::ACCESS, 'sre_zzzzsys_report_id', $id);
				$db->delete($T, 'zzzzsys_report_id', $id);
			});
			NuLog::write('report_deleted', $T, $id, ['code' => (string)$report['sre_code']]);
			nu_flash('success', t('rpt_deleted'));
			nu_redirect(nu_url('reports'));
		}
	}
}

$forms = $db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_type FROM zzzzsys_form WHERE COALESCE(sfo_type,'') <> 'subform' ORDER BY CASE WHEN sfo_type = 'launch' THEN 0 ELSE 1 END, sfo_code");
$formCode = array_column($forms, 'sfo_code', 'zzzzsys_form_id');

// ------------------------------------------------------------------ list (also Print / CSV)
if ($id === '') {
	// list: columns, filters and sort of the chosen list view (0.8.334, core/nulistdefs.php)
	NuListView::page('reports', [
		'before' => NuListView::crumbs([[t('nav_forms'), nu_url('forms')], [t('rpt_title_list')]]), 'active' => 'forms',
		'title_extra' => NuListView::infoToggle('rptHint'), 'title_sub' => '<div class="collapse nuTitleSub text-body-secondary small" id="rptHint">' . h(t('rpt_hint')) . '</div>',
		'buttons' => ($rights['add'] ? '<a class="btn nuAddBtn" title="' . h(t('rpt_new')) . '" href="' . h(nu_url('reports', ['id' => 'new'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '')
			. (NuAcl::can(NuAcl::FORM_SELECT) ? '<a class="btn btn-outline-secondary" href="' . h(nu_url('sqlbuilder')) . '"><i class="bi bi-diagram-3 me-1"></i>' . h(t('sqb_title')) . '</a>' : ''),
	]);
	return;
}

// ------------------------------------------------------------------ view
$foot = $id !== '' ? '<script src="' . nu_asset('assets/js/nureports.js') . '"></script>' : '';
nu_layout_start(t('rpt_title_list'), 'forms');
$tcpdf = NuReport::tcpdfDir();
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
		<?php if ($id !== ''): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('reports')) ?>"><?= h(t('rpt_title_list')) ?></a></li>
			<li class="breadcrumb-item active" aria-current="page"><?= h($report ? (string)$report['sre_code'] : t('rpt_new')) ?></li>
		<?php else: ?>
			<li class="breadcrumb-item active" aria-current="page"><?= h(t('rpt_title_list')) ?></li>
		<?php endif; ?>
	</ol>
</nav>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php endif; ?>
<?php if ($tcpdf === null): ?>
	<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('rpt_tcpdf_missing')) ?></div>
<?php endif; ?>

<?php if ($id === ''): ?>
<?php else: ?>
	<?php
	$posted = nu_is_post() && nu_post('action') === 'save';
	$v = static fn(string $k) => $posted ? (string)($_POST[$k] ?? '') : (string)($report[$k] ?? '');
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$fb = static fn(string $k) => '<div class="invalid-feedback">' . h($errors[$k] ?? '') . '</div>';
	$row = static fn(string $k, string $label, string $control, bool $req = false) =>
		'<div class="procField mb-3"><label class="form-label procLabel" for="' . $k . '">' . h($label) . ($req ? ' <span class="text-danger">*</span>' : '') . '</label>' . $control . '</div>';
	$access = NuReport::access($report ? $id : '');
	if ($posted) { $sent = array_flip(array_filter((array)($_POST['access'] ?? []), 'is_string')); foreach ($access as &$a) $a['granted'] = isset($sent[$a['zzzzsys_access_id']]); unset($a); }
	$usage = $report ? NuReport::usage($id, (string)$report['sre_code']) : [];
	$history = $report ? NuReport::history($id) : [];
	$nAccess = count(array_filter($access, static fn($a) => $a['granted']));
	$sources = NuReport::sources();
	$current = $v('sre_zzzzsys_php_id');
	if ($current !== '' && !in_array($current, array_column($sources, 'id'), true)) $sources[] = ['id' => $current, 'kind' => explode(':', $current)[0], 'code' => $current, 'description' => ''];
	$groupsDist = array_column($db->all("SELECT DISTINCT sre_group AS v FROM $T WHERE sre_group IS NOT NULL AND sre_group <> '' ORDER BY sre_group"), 'v');
	$layout = $report ? NuReport::layout((string)$report['sre_layout']) : null;
	$nObjects = 0;
	if ($layout) foreach ($layout->groups as $g) foreach ($g->sections as $s) $nObjects += count($s->objects);
	$fieldLabel = static fn(string $f) => ['sre_code' => t('rpt_code'), 'sre_description' => t('rpt_description'), 'sre_group' => t('rpt_group'),
		'sre_zzzzsys_form_id' => t('rpt_launch'), 'sre_zzzzsys_php_id' => t('rpt_source'), 'sre_layout' => t('rpt_layout')][$f] ?? $f;
	?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-file-earmark-richtext me-2"></i><?= h($report ? (string)$report['sre_code'] : t('rpt_new')) ?></h1>
		<?php if ($report): ?><span class="small text-body-secondary">ID <?= h($id) ?></span><?php endif; ?>
		<a class="btn nuBtnAct" href="<?= h(nu_url('reports')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuRecordForm" id="rptSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<?php if ($report): ?>
			<a class="btn btn-outline-primary" href="<?= h(nu_url('reportdesigner', ['id' => $id])) ?>" id="rptDesign"><i class="bi bi-vector-pen me-1"></i><?= h(t('rpt_designer')) ?></a>
			<a class="btn btn-outline-secondary" href="<?= h(nu_url('report', ['c' => (string)$report['sre_code']])) ?>" target="_blank" rel="noopener" id="rptRun"><i class="bi bi-file-earmark-pdf me-1"></i><?= h(t('rpt_run')) ?></a>
		<?php endif; ?>
		<?php if ($report && $rights['clone']): ?>
			<form method="post" action="<?= h(nu_url('reports', ['id' => $id])) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="clone">
				<button class="btn nuBtnAct" type="submit" id="rptClone"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></button></form>
		<?php endif; ?>
		<?php if ($report && $rights['delete']): ?>
			<form method="post" action="<?= h(nu_url('reports', ['id' => $id])) ?>" data-confirm="<?= h(t('rpt_delete_confirm', (string)$report['sre_code'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
				<button class="btn nuBtnAct nuBtnDel" type="submit" id="rptDelete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button></form>
		<?php endif; ?>
	</div>

	<form method="post" action="<?= h(nu_url('reports', ['id' => $id])) ?>" id="nuRecordForm" data-nu-focus-first data-nu-guard novalidate>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="access_sent" value="1">
		<ul class="nav nav-tabs" role="tablist" id="rptTabs">
			<li class="nav-item" role="presentation"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#rptTabMain" role="tab"><?= h(t('rpt_tab_report')) ?></button></li>
			<li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#rptTabAccess" role="tab"><?= h(t('nav_access')) ?> <span class="badge text-bg-secondary" id="rptAccessCount"><?= $nAccess ?></span></button></li>
			<?php if ($report): ?>
				<li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#rptTabUsage" role="tab"><?= h(t('proc_usage')) ?> <span class="badge text-bg-secondary"><?= count($usage) ?></span></button></li>
				<li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#rptTabHistory" role="tab"><?= h(t('proc_history')) ?> <span class="badge text-bg-secondary"><?= count($history) ?></span></button></li>
			<?php endif; ?>
		</ul>
		<fieldset class="card shadow-sm border-top-0 rounded-top-0"<?= $locked ? ' disabled' : '' ?>>
		<div class="card-body tab-content">
			<div class="tab-pane fade show active" id="rptTabMain" role="tabpanel">
				<div class="row g-3">
					<div class="col-md-4"><?= $row('sre_code', t('rpt_code'), '<input class="form-control' . $err('sre_code') . '" id="sre_code" name="sre_code" maxlength="300" required value="' . h($v('sre_code')) . '"' . ($isNew ? ' autofocus' : '') . ' spellcheck="false" autocomplete="off">' . $fb('sre_code'), true) ?></div>
					<div class="col-md-8"><?= $row('sre_description', t('rpt_description'), '<input class="form-control' . $err('sre_description') . '" id="sre_description" name="sre_description" maxlength="300" required value="' . h($v('sre_description')) . '">' . $fb('sre_description'), true) ?></div>
				</div>
				<div class="row g-3">
					<div class="col-md-4"><?= $row('sre_group', t('rpt_group'), '<input class="form-control" id="sre_group" name="sre_group" maxlength="100" list="rptGroups" value="' . h($v('sre_group')) . '">'
						. '<datalist id="rptGroups">' . implode('', array_map(static fn($g) => '<option value="' . h($g) . '">', $groupsDist)) . '</datalist>') ?></div>
					<div class="col-md-8"><?= $row('sre_zzzzsys_form_id', t('rpt_launch'), '<select class="form-select' . $err('sre_zzzzsys_form_id') . '" id="sre_zzzzsys_form_id" name="sre_zzzzsys_form_id"><option value="">' . h(t('rpt_launch_none')) . '</option>'
						. implode('', array_map(static fn($f) => '<option value="' . h($f['zzzzsys_form_id']) . '"' . ($f['zzzzsys_form_id'] === $v('sre_zzzzsys_form_id') ? ' selected' : '') . '>' . h($f['sfo_code'] . ($f['sfo_description'] ? ' – ' . $f['sfo_description'] : '') . ($f['sfo_type'] === 'launch' ? ' · launch' : '')) . '</option>', $forms))
						. '</select>' . $fb('sre_zzzzsys_form_id') . '<div class="form-text">' . h(t('rpt_launch_hint')) . '</div>') ?></div>
				</div>
				<?php
				$opt = '<option value=""></option>';
				foreach (['PROCEDURE' => 'rpt_src_procedures', 'SQL' => 'rpt_src_sqls', 'TABLE' => 'rpt_src_tables'] as $kind => $lbl) {
					$items = array_filter($sources, static fn($s) => $s['kind'] === $kind);
					if (!$items) continue;
					$opt .= '<optgroup label="' . h(t($lbl)) . '">';
					foreach ($items as $s) $opt .= '<option value="' . h($s['id']) . '"' . ($s['id'] === $current ? ' selected' : '') . '>' . h($s['code'] . ($s['description'] !== '' ? ' – ' . $s['description'] : '')) . '</option>';
					$opt .= '</optgroup>';
				}
				?>
				<?= $row('sre_zzzzsys_php_id', t('rpt_source'), '<select class="form-select' . $err('sre_zzzzsys_php_id') . '" id="sre_zzzzsys_php_id" name="sre_zzzzsys_php_id" required>' . $opt . '</select>' . $fb('sre_zzzzsys_php_id')
					. '<div class="form-text">' . h(t('rpt_source_hint')) . (NuAcl::can(NuAcl::FORM_SELECT) ? ' <a href="' . h(nu_url('sqlbuilder', ['id' => 'new'])) . '">' . h(t('sqb_new')) . '</a>' : '')
					. (NuAcl::can(NuAcl::FORM_PROCEDURE) ? ' · <a href="' . h(nu_url('procedures', ['id' => 'new'])) . '">' . h(t('proc_new')) . '</a>' : '') . '</div>', true) ?>
				<?php if ($report && $layout): ?>
					<div class="border rounded p-3 bg-body-tertiary d-flex flex-wrap gap-3 align-items-center">
						<div class="me-auto">
							<div class="fw-semibold"><i class="bi bi-layout-text-window-reverse me-1"></i><?= h(t('rpt_layout')) ?></div>
							<div class="small text-body-secondary"><?= h(t('rpt_layout_summary', $layout->paper, t($layout->orientation === 'L' ? 'rpt_landscape' : 'rpt_portrait'), $nObjects,
								count(array_filter(NuReport::SORT_GROUPS, static fn($g) => (string)$layout->groups[$g]->sortField !== '')))) ?></div>
						</div>
						<a class="btn btn-primary" href="<?= h(nu_url('reportdesigner', ['id' => $id])) ?>"><i class="bi bi-vector-pen me-1"></i><?= h(t('rpt_designer_open')) ?></a>
					</div>
				<?php elseif (!$report && !$locked): ?>
					<button class="btn btn-outline-primary" type="submit" name="then" value="design"><i class="bi bi-vector-pen me-1"></i><?= h(t('rpt_save_design')) ?></button>
				<?php endif; ?>
			</div>

			<div class="tab-pane fade" id="rptTabAccess" role="tabpanel">
				<div class="d-flex flex-wrap gap-2 mb-2">
					<input type="search" class="form-control form-control-sm w-auto" id="rptAccessSearch" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>">
					<button class="btn btn-sm btn-outline-secondary" type="button" data-access-all="1"><?= h(t('proc_access_all')) ?></button>
					<button class="btn btn-sm btn-outline-secondary" type="button" data-access-all="0"><?= h(t('proc_access_none')) ?></button>
				</div>
				<div class="list-group procAccessList" id="rptAccessList">
					<?php if (!$access): ?><div class="text-body-secondary small"><?= h(t('no_records')) ?></div><?php endif; ?>
					<?php foreach ($access as $a): ?>
						<label class="list-group-item d-flex gap-2 align-items-center" data-text="<?= h(strtolower($a['sal_code'] . ' ' . $a['sal_description'] . ' ' . $a['sal_group'])) ?>">
							<input class="form-check-input mt-0" type="checkbox" name="access[]" value="<?= h($a['zzzzsys_access_id']) ?>"<?= $a['granted'] ? ' checked' : '' ?>>
							<span class="fw-semibold"><?= h($a['sal_code']) ?></span><span class="small text-body-secondary me-auto"><?= h($a['sal_description']) ?></span>
							<?php if ((string)$a['sal_group'] !== ''): ?><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h($a['sal_group']) ?></span><?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="small text-body-secondary mt-2 mb-0"><?= h(t('rpt_access_hint')) ?></p>
			</div>

			<?php if ($report): ?>
			<div class="tab-pane fade" id="rptTabUsage" role="tabpanel">
				<?php if (!$usage): ?><p class="text-body-secondary mb-0"><?= h(t('rpt_usage_none')) ?></p><?php else: ?>
					<table class="table table-sm align-middle mb-0" id="rptUsage">
						<thead><tr><th><?= h(t('proc_usage_kind')) ?></th><th><?= h(t('nav_forms')) ?></th><th><?= h(t('proc_usage_where')) ?></th></tr></thead>
						<tbody><?php foreach ($usage as $u): ?>
							<tr><td><span class="badge <?= $u['kind'] === 'object' ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>"><?= h(t('proc_usage_' . $u['kind'])) ?></span></td>
								<td><?php if ($u['form_id'] !== '' && NuAcl::can(NuAcl::FORM_FORM) && !str_starts_with($u['form_id'], 'nu')): ?><a href="<?= h(nu_url('builder', ['f' => $u['form_id']])) ?>"><?= h($u['form'] ?: $u['form_id']) ?></a><?php else: ?><?= h($u['form'] ?: $u['form_id']) ?><?php endif; ?></td>
								<td class="small"><?php if (!empty($u['proc_id'])): ?><a href="<?= h(nu_url('procedures', ['id' => $u['proc_id']])) ?>"><?= h($u['where']) ?></a><?php else: ?><?= h($u['where']) ?><?php endif; ?></td></tr>
						<?php endforeach; ?></tbody>
					</table>
				<?php endif; ?>
			</div>
			<div class="tab-pane fade" id="rptTabHistory" role="tabpanel">
				<?php if (!$history): ?><p class="text-body-secondary mb-0"><?= h(t('proc_history_none')) ?></p><?php endif; ?>
				<?php foreach ($history as $hst): $d = json_decode((string)$hst['details'], true) ?: []; ?>
					<div class="border-bottom py-2">
						<div class="d-flex flex-wrap gap-2 align-items-baseline">
							<span class="small fw-semibold"><?= h(date('d.m.Y H:i:s', strtotime((string)$hst['log_time']))) ?></span>
							<span class="small"><?= h($hst['login']) ?></span>
							<span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h(($l = t('ev_' . $hst['event'])) === 'ev_' . $hst['event'] ? $hst['event'] : $l) ?></span>
							<?php if (isset($d['clone_of'])): ?><span class="small text-body-secondary"><?= h(t('proc_history_clone', $d['clone_of'])) ?></span><?php endif; ?>
						</div>
						<?php foreach (['access_added' => 'proc_history_access_added', 'access_removed' => 'proc_history_access_removed'] as $k => $l): ?>
							<?php if (!empty($d[$k])): ?><div class="small"><?= h(t($l, $d[$k])) ?></div><?php endif; ?>
						<?php endforeach; ?>
						<?php foreach ((array)($d['fields'] ?? []) as $f => $ch): [$old, $new] = array_pad((array)$ch, 2, null); ?>
							<div class="small"><span class="fw-semibold"><?= h($fieldLabel((string)$f)) ?></span><?php if ($f !== 'sre_layout'): ?>: <span class="text-body-secondary text-decoration-line-through"><?= h($old === null || $old === '' ? '–' : (string)$old) ?></span> → <?= h($new === null || $new === '' ? '–' : (string)$new) ?><?php else: ?>: <?= h(t('rpt_layout_changed')) ?><?php endif; ?></div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		</fieldset>
	</form>
<?php endif; ?>
<?php
nu_layout_end($foot);
