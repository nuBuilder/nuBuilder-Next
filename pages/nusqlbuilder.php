<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * SQL builder (zzzzsys_select) – nuBuilder's form "nuselect", rights of that form. Queries for reports (data source SQL:<id>).
 *   list · ?id=<id> | ?id=new edit: tables as boxes, columns to select, joins by dragging a column onto another, clauses, SQL, preview
 *   POST action=save (form) · clone · delete · preview (background: runs the SELECT, first 50 rows as JSON)
 */

NuAcl::require(NuAcl::FORM_SELECT);
if (!NuSqlSelect::available()) {
	try { NuReport::ensureTables(); } catch (Throwable $e) { nu_fatal(t('rpt_err_tables'), NuAuth::user()['globeadmin'] ? $e->getMessage() : '', true); }
}
$db     = nu_db();
$rights = NuAcl::rights(NuAcl::FORM_SELECT);
$T      = NuSqlSelect::TABLE;

$id    = (string)($_GET['id'] ?? '');
$isNew = $id === 'new';
$q     = null;
if ($id !== '' && !$isNew) {
	$q = NuSqlSelect::load($id);
	if (!$q) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
$locked = !($q ? $rights['save'] : $rights['create']) || ($q && (string)$q['sse_system'] === '1' && !NuHash::devMode());
$errors = [];

// ------------------------------------------------------------------ preview (JSON)
// The preview runs the SQL typed here, so it needs the right to change queries (save or create) – before 0.8.207 viewing was enough.
if (nu_is_post() && nu_post('action') === 'preview') {
	header('Content-Type: application/json; charset=utf-8');
	if (!$rights['save'] && !$rights['create']) {
		echo json_encode(['ok' => false, 'message' => t('sqb_err_preview_right')], JSON_UNESCAPED_UNICODE);
		exit;
	}
	try {
		echo json_encode(['ok' => true] + NuSqlSelect::preview((string)($_POST['sql'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	} catch (Throwable $e) {
		// details (table and column names in SQL errors) only for the globeadmin or with $nuConfigDebug
		$u = NuAuth::user();
		$own = $e instanceof RuntimeException && !($e instanceof PDOException);
		$msg = $own || ($u['globeadmin'] && empty($u['demo'])) || nu_cfg('nuConfigDebug') ? $e->getMessage() : t('sqb_err_failed_generic');
		echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}
	exit;
}

// ------------------------------------------------------------------ save / clone / delete
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		NuAcl::require(NuAcl::FORM_SELECT, $q ? 'save' : 'create');
		if ($locked) NuAcl::require('__globeadmin_only__');
		$in = ['sse_code' => $_POST['sse_code'] ?? '', 'sse_description' => $_POST['sse_description'] ?? '', 'sse_json' => $_POST['sse_json'] ?? '',
			'sse_sql' => $_POST['sse_sql'] ?? '', 'sse_edit' => nu_check('sse_edit'), 'clauses' => json_decode((string)($_POST['clauses'] ?? '[]'), true) ?: []];
		[$newId, $errors] = NuSqlSelect::save($in, $q ? $id : '');
		if (!$errors) {
			NuLog::write($q ? 'sql_changed' : 'sql_created', $T, $newId, ['code' => (string)$in['sse_code'], 'description' => (string)$in['sse_description']]);
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('sqlbuilder', ['id' => $newId]));
		}
	}
	if ($action === 'clone' && $q) {
		NuAcl::require(NuAcl::FORM_SELECT, 'clone');
		$code = (string)$q['sse_code'];
		$new = $code;
		if ($code !== '') for ($n = 2; $db->value("SELECT COUNT(*) FROM $T WHERE sse_code = ?", [$new]); $n++) $new = mb_substr($code, 0, 45) . '_' . $n;
		$in = $q + ['clauses' => NuSqlSelect::clauses($id)];
		$in['sse_code'] = $new;
		$in['sse_description'] = mb_substr((string)$q['sse_description'] . ' (2)', 0, 300);
		[$newId, $e] = NuSqlSelect::save($in);
		if (!$e) {
			NuLog::write('sql_created', $T, $newId, ['code' => $new, 'clone_of' => (string)$q['sse_code']]);
			nu_flash('success', t('sqb_cloned'));
			nu_redirect(nu_url('sqlbuilder', ['id' => $newId]));
		}
		$errors = ['_' => implode(' ', $e)];
	}
	if ($action === 'delete' && $q) {
		NuAcl::require(NuAcl::FORM_SELECT, 'delete');
		$used = NuSqlSelect::usage($id);
		if ($used) {
			$errors['_'] = t('sqb_err_used', implode(', ', array_column($used, 'sre_code')));
		} else {
			NuSqlSelect::delete($id);
			NuLog::write('sql_deleted', $T, $id, ['code' => (string)$q['sse_code'], 'description' => (string)$q['sse_description']]);
			nu_flash('success', t('sqb_deleted'));
			nu_redirect(nu_url('sqlbuilder'));
		}
	}
}

// ------------------------------------------------------------------ list
if ($id === '') {
	$search = trim((string)($_GET['q'] ?? ''));
	$where = ["COALESCE(s.sse_system, '') = ''"];
	$params = [];
	if ($search !== '') {
		$where[] = "(s.sse_code LIKE ? ESCAPE '!' OR s.sse_description LIKE ? ESCAPE '!' OR s.sse_sql LIKE ? ESCAPE '!')";
		array_push($params, NuDB::like($search), NuDB::like($search), NuDB::like($search));
	}
	$rows = $db->all("SELECT s.zzzzsys_select_id, s.sse_code, s.sse_description, s.sse_edit, s.sse_json FROM $T s WHERE " . implode(' AND ', $where) . ' ORDER BY s.sse_code, s.sse_description', $params);
	$uses = [];
	if (NuReport::available()) foreach ($db->all("SELECT sre_zzzzsys_php_id AS p, COUNT(*) AS n FROM zzzzsys_report WHERE sre_zzzzsys_php_id LIKE 'SQL:%' GROUP BY sre_zzzzsys_php_id") as $u) $uses[substr((string)$u['p'], 4)] = (int)$u['n'];
}

$foot = '';
if ($id !== '') {
	$clauses = $q ? NuSqlSelect::clauses($id) : [];
	if (nu_is_post() && nu_post('action') === 'save') $clauses = json_decode((string)($_POST['clauses'] ?? '[]'), true) ?: [];
	$schema = NuSqlSelect::schema(true);
	$data = ['schema' => $schema, 'clauses' => $clauses, 'readonly' => $locked, 'url' => nu_url('sqlbuilder', ['id' => $id ?: 'new']), 'csrf' => nu_csrf_token(),
		'msg' => array_combine(array_map(static fn($k) => $k, $keys = ['tables', 'search', 'system', 'alias', 'all', 'remove', 'join', 'left_join', 'join_toggle', 'join_delete',
			'clauses', 'clause_add', 'type', 'field', 'clause', 'sort', 'order', 'sql', 'manual', 'manual_hint', 'preview', 'rows', 'more', 'no_rows', 'missing_table',
			'drag_hint', 'empty', 'run_failed', 'columns', 'add_table']), array_map(static fn($k) => t('sqb_' . $k), $keys))];
	$foot = '<script type="application/json" id="sqbData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
		. '<script src="' . nu_asset('assets/js/nusqlbuilder.js') . '"></script>';
}
nu_layout_start(t('sqb_title'), 'forms', ['head' => '<link rel="stylesheet" href="' . nu_asset('assets/css/nusqlbuilder.css') . '">', 'container' => $id !== '' ? 'container-fluid' : 'container-xl']);
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
		<?php if (NuAcl::can(NuAcl::FORM_REPORT)): ?><li class="breadcrumb-item"><a href="<?= h(nu_url('reports')) ?>"><?= h(t('rpt_title_list')) ?></a></li><?php endif; ?>
		<?php if ($id !== ''): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('sqlbuilder')) ?>"><?= h(t('sqb_title')) ?></a></li>
			<li class="breadcrumb-item active" aria-current="page"><?= h($q ? ((string)$q['sse_code'] ?: (string)$q['sse_description']) : t('sqb_new')) ?></li>
		<?php else: ?>
			<li class="breadcrumb-item active" aria-current="page"><?= h(t('sqb_title')) ?></li>
		<?php endif; ?>
	</ol>
</nav>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php endif; ?>

<?php if ($id === ''): ?>
	<div class="card shadow-sm nuListCard">
		<div class="card-header nuListHead nuTitleBar">
			<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('sqb_title')) ?>
				<button type="button" class="btn btn-link btn-sm p-0 lh-1 text-body-secondary nuInfoBtn" data-bs-toggle="collapse" data-bs-target="#sqbHint" aria-expanded="false" aria-controls="sqbHint" title="<?= h(t('info')) ?>" aria-label="<?= h(t('info')) ?>"><i class="bi bi-info-circle"></i></button></h1>
			<div class="collapse nuTitleSub text-body-secondary small" id="sqbHint"><?= h(t('sqb_hint')) ?></div>
			<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?><?php if ($rights['add']): ?><a class="btn nuAddBtn" href="<?= h(nu_url('sqlbuilder', ['id' => 'new'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></a><?php endif; ?></div>
		</div>
		<form class="nuListFilter" method="get" action="index.php" role="search">
			<input type="hidden" name="p" value="sqlbuilder">
			<div class="row g-2 align-items-end"><div class="col-md-6"><?= nu_list_search($search, '', '', false, $search !== '' ? nu_url('sqlbuilder') : null) ?></div></div>
		</form>
		<div class="table-responsive">
			<table class="table table-hover align-middle mb-0 nu-table" id="sqbList">
				<thead><tr><th><?= h(t('sqb_code')) ?></th><th><?= h(t('sqb_description')) ?></th><th><?= h(t('sqb_tables')) ?></th><th><?= h(t('sqb_mode')) ?></th><th class="text-end"><?= h(t('sqb_used')) ?></th></tr></thead>
				<tbody>
				<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-body-secondary py-5"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
				<?php foreach ($rows as $r): $edit = nu_url('sqlbuilder', ['id' => (string)$r['zzzzsys_select_id']]); $j = json_decode((string)$r['sse_json'], true); ?>
					<tr class="nu-row-link" data-href="<?= h($edit) ?>">
						<td class="fw-semibold"><a class="text-decoration-none" href="<?= h($edit) ?>"><?= h((string)$r['sse_code'] !== '' ? $r['sse_code'] : '–') ?></a></td>
						<td class="small text-break"><?= h($r['sse_description']) ?></td>
						<td class="small"><?= h(implode(', ', array_map(static fn($t) => (string)($t['tablename'] ?? ''), (array)($j['tables'] ?? [])))) ?></td>
						<td class="small"><?= h(t((string)$r['sse_edit'] === '1' ? 'sqb_mode_manual' : 'sqb_mode_builder')) ?></td>
						<td class="text-end small"><?= (int)($uses[(string)$r['zzzzsys_select_id']] ?? 0) ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php else: ?>
	<?php
	$posted = nu_is_post() && nu_post('action') === 'save';
	$v = static fn(string $k) => $posted ? (string)($_POST[$k] ?? '') : (string)($q[$k] ?? '');
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$used = $q ? NuSqlSelect::usage($id) : [];
	?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-2"></i><?= h($q ? ((string)$q['sse_code'] ?: (string)$q['sse_description']) : t('sqb_new')) ?></h1>
		<a class="btn nuBtnAct" href="<?= h(nu_url('sqlbuilder')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuRecordForm" id="sqbSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<?php if ($q && $rights['clone']): ?>
			<form method="post" action="<?= h(nu_url('sqlbuilder', ['id' => $id])) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="clone">
				<button class="btn nuBtnAct" type="submit" id="sqbClone"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></button></form>
		<?php endif; ?>
		<?php if ($q && $rights['delete'] && !$locked): ?>
			<form method="post" action="<?= h(nu_url('sqlbuilder', ['id' => $id])) ?>" data-confirm="<?= h(t('sqb_delete_confirm', (string)($q['sse_code'] ?: $q['sse_description']))) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
				<button class="btn nuBtnAct nuBtnDel" type="submit" id="sqbDelete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button></form>
		<?php endif; ?>
	</div>
	<?php if ($used): ?><div class="small text-body-secondary mb-2"><i class="bi bi-file-earmark-richtext me-1"></i><?= h(t('sqb_used_by', implode(', ', array_column($used, 'sre_code')))) ?></div><?php endif; ?>
	<form method="post" action="<?= h(nu_url('sqlbuilder', ['id' => $id])) ?>" id="nuRecordForm" data-nu-guard novalidate>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
		<input type="hidden" name="sse_json" id="sse_json" value="<?= h($v('sse_json')) ?>">
		<input type="hidden" name="clauses" id="sqbClauses" value="">
		<fieldset<?= $locked ? ' disabled' : '' ?>>
		<div class="row g-3 mb-3">
			<div class="col-md-3"><label class="form-label fw-semibold small" for="sse_code"><?= h(t('sqb_code')) ?></label>
				<input class="form-control<?= $err('sse_code') ?>" id="sse_code" name="sse_code" maxlength="50" value="<?= h($v('sse_code')) ?>" spellcheck="false" autocomplete="off"><div class="invalid-feedback"><?= h($errors['sse_code'] ?? '') ?></div></div>
			<div class="col-md-9"><label class="form-label fw-semibold small" for="sse_description"><?= h(t('sqb_description')) ?> <span class="text-danger">*</span></label>
				<input class="form-control<?= $err('sse_description') ?>" id="sse_description" name="sse_description" maxlength="300" required value="<?= h($v('sse_description')) ?>"<?= $isNew ? ' autofocus' : '' ?>><div class="invalid-feedback"><?= h($errors['sse_description'] ?? '') ?></div></div>
		</div>
		<div id="sqbApp" class="sqbApp"></div>
		<div class="sqbSqlRow mt-3">
			<div class="d-flex flex-wrap gap-3 align-items-center mb-1">
				<label class="form-label fw-semibold small mb-0 me-auto" for="sse_sql">SQL <span class="text-danger">*</span></label>
				<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="sse_edit" name="sse_edit" value="1"<?= $v('sse_edit') === '1' ? ' checked' : '' ?>>
					<label class="form-check-label small" for="sse_edit"><?= h(t('sqb_manual')) ?></label></div>
				<?php if ($rights['save'] || $rights['create']): // the preview runs the SQL – only with the right to change queries ?>
				<button type="button" class="btn btn-sm btn-outline-primary" id="sqbPreview"><i class="bi bi-play-fill me-1"></i><?= h(t('sqb_preview')) ?></button>
				<?php endif; ?>
			</div>
			<textarea class="form-control font-monospace sqbSql<?= $err('sse_sql') ?>" id="sse_sql" name="sse_sql" rows="9" spellcheck="false"><?= h($v('sse_sql')) ?></textarea>
			<div class="invalid-feedback"><?= h($errors['sse_sql'] ?? '') ?></div>
			<div class="form-text"><?= h(t('sqb_manual_hint')) ?></div>
		</div>
		</fieldset>
	</form>
	<div id="sqbResult" class="sqbResult mt-3" aria-live="polite"></div>
<?php endif; ?>
<?php
nu_layout_end($foot);
