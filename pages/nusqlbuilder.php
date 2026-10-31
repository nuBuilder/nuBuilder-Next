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
// clone (0.8.680, as nuBuilder Forte): ?id=new&clone=<id> opens a copy (tables, columns, clauses, SQL) that is saved only with Save
$cloneId = $isNew ? (string)($_GET['clone'] ?? '') : '';
$src = null;
if ($cloneId !== '') {
	NuAcl::require(NuAcl::FORM_SELECT, 'clone');
	$src = NuSqlSelect::load($cloneId);
	if (!$src) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
	$code = (string)$src['sse_code'];
	$new = $code;
	if ($code !== '') for ($n = 2; $db->value("SELECT COUNT(*) FROM $T WHERE sse_code = ?", [$new]); $n++) $new = mb_substr($code, 0, 45) . '_' . $n;
	$src['sse_code'] = $new;
	$src['sse_description'] = mb_substr((string)$src['sse_description'] . ' (2)', 0, 300);
	$src['sse_system'] = '';
}
$locked = !($q ? $rights['save'] : $rights[$src ? 'clone' : 'create']) || ($q && (string)$q['sse_system'] === '1' && !NuHash::devMode());
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
		$msg = $own || ($u['globeadmin'] && empty($u['demo'])) || nu_debug() ? $e->getMessage() : t('sqb_err_failed_generic');
		echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}
	exit;
}

// ------------------------------------------------------------------ save / clone / delete
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		NuAcl::require(NuAcl::FORM_SELECT, $q ? 'save' : ($src ? 'clone' : 'create'));
		if ($locked) NuAcl::require('__globeadmin_only__');
		$in = ['sse_code' => $_POST['sse_code'] ?? '', 'sse_description' => $_POST['sse_description'] ?? '', 'sse_json' => $_POST['sse_json'] ?? '',
			'sse_sql' => $_POST['sse_sql'] ?? '', 'sse_edit' => nu_check('sse_edit'), 'clauses' => json_decode((string)($_POST['clauses'] ?? '[]'), true) ?: []];
		[$newId, $errors] = NuSqlSelect::save($in, $q ? $id : '');
		if (!$errors) {
			NuLog::write($q ? 'sql_changed' : 'sql_created', $T, $newId, ['code' => (string)$in['sse_code'], 'description' => (string)$in['sse_description']]
				+ ($src ? ['clone_of' => (string)$db->value("SELECT sse_code FROM $T WHERE zzzzsys_select_id = ?", [$cloneId])] : []));
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('sqlbuilder', ['id' => $newId]));
		}
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

// ------------------------------------------------------------------ list: a system list (0.8.688, core/nulistdefs.php → sqlbuilder)
if ($id === '') {
	NuListView::page('sqlbuilder', [
		'before' => NuListView::crumbs(array_values(array_filter([[t('nav_forms'), nu_url('forms')], NuAcl::can(NuAcl::FORM_REPORT) ? [t('rpt_title_list'), nu_url('reports')] : null, [t('sqb_title')]]))),
		'active' => 'forms', 'title_extra' => NuListView::infoTip(t('sqb_hint')),
		'buttons' => $rights['add'] ? '<a class="btn nuAddBtn" href="' . h(nu_url('sqlbuilder', ['id' => 'new'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '',
	]);
	return;
}

$foot = '';
if ($id !== '') {
	$clauses = $q ? NuSqlSelect::clauses($id) : ($src ? NuSqlSelect::clauses($cloneId) : []);
	if (nu_is_post() && nu_post('action') === 'save') $clauses = json_decode((string)($_POST['clauses'] ?? '[]'), true) ?: [];
	$schema = NuSqlSelect::schema(true);
	$data = ['schema' => $schema, 'clauses' => $clauses, 'readonly' => $locked, 'url' => nu_url('sqlbuilder', ['id' => $id ?: 'new', 'clone' => $cloneId]), 'csrf' => nu_csrf_token(),
		'msg' => array_combine(array_map(static fn($k) => $k, $keys = ['tables', 'search', 'system', 'alias', 'all', 'remove', 'join', 'left_join', 'join_toggle', 'join_delete',
			'clauses', 'clause_add', 'type', 'field', 'clause', 'sort', 'order', 'sql', 'manual', 'manual_hint', 'preview', 'rows', 'more', 'no_rows', 'missing_table',
			'drag_hint', 'empty', 'run_failed', 'columns', 'add_table']), array_map(static fn($k) => t('sqb_' . $k), $keys))];
	$foot = '<script type="application/json" id="sqbData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
		. '<script src="' . nu_asset('assets/js/nusqlbuilder.js') . '"></script>';
}
nu_layout_start(t('sqb_title'), 'forms', ['head' => '<link rel="stylesheet" href="' . nu_asset('assets/css/nusqlbuilder.css') . '">', 'container' => $id !== '' ? 'container-fluid' : 'nuContainer']);
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


	<?php
	$posted = nu_is_post() && nu_post('action') === 'save';
	$rec = $q ?? $src;                                      // a clone shows the values of its source
	$v = static fn(string $k) => $posted ? (string)($_POST[$k] ?? '') : (string)($rec[$k] ?? '');
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$used = $q ? NuSqlSelect::usage($id) : [];
	?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-2"></i><?= h($q ? ((string)$q['sse_code'] ?: (string)$q['sse_description']) : t('sqb_new')) ?></h1>
		<div class="d-flex flex-wrap gap-2 align-items-center">   <!-- buttons in their own row (0.8.474) -->
		<a class="btn nuBtnAct" href="<?= h(nu_url('sqlbuilder')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" form="nuRecordForm" id="sqbSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<?php if ($q && $rights['clone']): ?>
			<a class="btn nuBtnAct" href="<?= h(nu_url('sqlbuilder', ['id' => 'new', 'clone' => $id])) ?>" id="sqbClone"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></a>
		<?php endif; ?>
		<?php if ($q && $rights['delete'] && !$locked): ?>
			<form method="post" action="<?= h(nu_url('sqlbuilder', ['id' => $id])) ?>" data-confirm="<?= h(t('sqb_delete_confirm', (string)($q['sse_code'] ?: $q['sse_description']))) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
				<button class="btn nuBtnAct nuBtnDel" type="submit" id="sqbDelete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button></form>
		<?php endif; ?>
		</div>
	</div>
	<?php if ($used): ?><div class="small text-body-secondary mb-2"><i class="bi bi-file-earmark-richtext me-1"></i><?= h(t('sqb_used_by', implode(', ', array_column($used, 'sre_code')))) ?></div><?php endif; ?>
	<form method="post" action="<?= h(nu_url('sqlbuilder', ['id' => $id, 'clone' => $cloneId])) ?>" id="nuRecordForm"<?= $src ? ' data-nu-clone="' . h(t('form_clone_badge')) . '"' : '' ?> data-nu-guard novalidate>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
		<input type="hidden" name="sse_json" id="sse_json" value="<?= h($v('sse_json')) ?>">
		<input type="hidden" name="clauses" id="sqbClauses" value="">
		<fieldset<?= $locked ? ' disabled' : '' ?>>
		<div class="row g-3 mb-3">
			<div class="col-md-3"><label class="form-label fw-semibold small" for="sse_code"><?= h(t('sqb_code')) ?><?= nu_unique_mark() ?></label>
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

<?php
nu_layout_end($foot);
