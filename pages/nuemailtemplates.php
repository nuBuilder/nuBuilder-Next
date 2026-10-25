<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * E-mail templates (zzzzsys_email_template) – rights of nuBuilder's form "nuemailtemplate".
 *   list, ?id=<id> edit, ?id=new new; JSON: ?fields=<form id> (placeholders), POST action=preview / test
 * Used from PHP with nuGetEmailTemplateData() + nuSendEmailFromTemplate() (like nuBuilder).
 */

NuAcl::require(NuAcl::FORM_EMAILTEMPLATE);
$db = nu_db();
NuEmailTemplate::ensureTable();
$rights = NuAcl::rights(NuAcl::FORM_EMAILTEMPLATE);
$T = NuEmailTemplate::TABLE;

function nu_emt_json(array $d, int $status = 200): never
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

// ------------------------------------------------------------------ placeholders of a form (JSON)
if (isset($_GET['fields'])) {
	nu_emt_json(['ok' => true, 'fields' => NuEmailTemplate::fields((string)$_GET['fields'])]);
}

$id     = (string)($_GET['id'] ?? '');
$isNew  = $id === 'new';
$tpl    = null;
if ($id !== '' && !$isNew) {
	$tpl = $db->one("SELECT * FROM $T WHERE zzzzsys_email_template_id = ?", [$id]);
	if (!$tpl) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
$errors = [];

// ------------------------------------------------------------------ POST
if (nu_is_post()) {
	$action = nu_post('action');
	$in = [];
	foreach (array_keys(NuEmailTemplate::LEN) as $k) $in[$k] = nu_post($k);
	$in['emt_body'] = is_string($_POST['emt_body'] ?? null) ? str_replace("\r\n", "\n", $_POST['emt_body']) : '';
	$in['emt_template'] = false;                                        // set only by nuBuilder (its shipped templates), never here

	if ($action === 'preview' || $action === 'test') {
		// a record of the template's form: only with rights on that form (0.8.207 – before, any record of any form could be shown)
		$fid = (string)$in['emt_form_id'];
		if ($fid !== '' && trim(nu_post('record')) !== '' && !NuAcl::can($fid)) nu_emt_json(['ok' => false, 'error' => t('emt_err_form_right')]);
		if ($action === 'test') {
			// a test e-mail sends any text to any address: right to change templates, at most 10 per hour and user
			if (!NuAcl::can(NuAcl::FORM_EMAILTEMPLATE, $tpl ? 'save' : 'create')) nu_emt_json(['ok' => false, 'error' => t('err_forbidden')]);
			$sent = (int)$db->value('SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = ? AND user_id = ? AND log_time > ?',
				['email_template_test', (string)NuAuth::user()['id'], date('Y-m-d H:i:s', time() - 3600)]);
			if ($sent >= 10) nu_emt_json(['ok' => false, 'error' => t('emt_err_test_limit', 10)]);
		}
		$out = NuEmailTemplate::render($in, trim(nu_post('record')));
		if ($action === 'preview') nu_emt_json(['ok' => true] + $out);
		$to = trim(nu_post('test_to'));
		if (!NuMailer::validAddress($to)) nu_emt_json(['ok' => false, 'error' => t('user_err_email')]);
		$r = nuSendEmailEx(['to' => $to, 'subject' => $out['subject'], 'body' => $out['body'], 'html' => true],
			['tag' => 'nunext_template_test', 'form_id' => $in['emt_form_id'] ?: null, 'record_id' => trim(nu_post('record')) ?: null,
			 'json' => ['template' => $in['emt_code']]]);
		NuLog::write('email_template_test', $T, (string)($tpl['zzzzsys_email_template_id'] ?? ''), ['to' => $to, 'code' => (string)$in['emt_code'], 'ok' => (bool)$r[0]]);
		nu_emt_json(['ok' => (bool)$r[0], 'error' => $r[0] ? '' : trim(strip_tags($r[1] . ' ' . $r[2]))]);
	}

	if ($action === 'save') {
		if (!NuAcl::can(NuAcl::FORM_EMAILTEMPLATE, $tpl ? 'save' : 'create')) NuAcl::require(NuAcl::FORM_EMAILTEMPLATE, $tpl ? 'save' : 'create');
		if ($tpl && (string)$tpl['emt_template'] === '1') {                // nuBuilder's templates are read-only – clone them
			$errors['_'] = t('emt_err_template');
		} else {
			[$newId, $errors] = NuEmailTemplate::save($in, $tpl ? $id : '');
			if (!$errors) {
				NuLog::write($tpl ? 'email_template_changed' : 'email_template_created', $T, (string)$newId,
					$tpl ? ['fields' => NuLog::diff($tpl, $db->one("SELECT * FROM $T WHERE zzzzsys_email_template_id = ?", [$newId]) ?? [])] : ['code' => $in['emt_code']]);
				nu_flash('success', t('saved'));
				nu_redirect(nu_url('emailtemplates', ['id' => $newId]));
			}
		}
	}

	if ($action === 'clone' && $tpl) {
		NuAcl::require(NuAcl::FORM_EMAILTEMPLATE, 'clone');
		$copy = $tpl;
		$copy['emt_template'] = '0';
		$code = (string)$tpl['emt_code'];
		if (str_ends_with($code, '_template')) $code = substr($code, 0, -9);          // nuBuilder templates end with "_template"
		$n = 0;
		do { $try = $code . ($n ? '_' . $n : ''); $n++; }
		while ((int)$db->value("SELECT COUNT(*) FROM $T WHERE COALESCE(emt_code,'') = ? AND COALESCE(emt_group,'') = ? AND COALESCE(emt_language,'') = ?",
			[$try, (string)$tpl['emt_group'], (string)$tpl['emt_language']]) && $n < 50);
		$copy['emt_code'] = $try;
		[$newId, $e] = NuEmailTemplate::save($copy);
		if ($newId) {
			NuLog::write('email_template_created', $T, $newId, ['code' => $try, 'clone_of' => $id]);
			nu_flash('success', t('emt_cloned'));
			nu_redirect(nu_url('emailtemplates', ['id' => $newId]));
		}
		nu_flash('danger', implode(' ', array_unique(array_values($e))));
		nu_redirect(nu_url('emailtemplates', ['id' => $id]));
	}

	if ($action === 'delete' && $tpl) {
		NuAcl::require(NuAcl::FORM_EMAILTEMPLATE, 'delete');
		if ((string)$tpl['emt_template'] === '1') { nu_flash('danger', t('emt_err_template')); nu_redirect(nu_url('emailtemplates', ['id' => $id])); }
		$db->delete($T, 'zzzzsys_email_template_id', $id);
		NuLog::write('email_template_deleted', $T, $id, ['code' => (string)$tpl['emt_code']]);
		nu_flash('success', t('emt_deleted'));
		nu_redirect(nu_url('emailtemplates'));
	}
}

$forms = $db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' ORDER BY sfo_code");

// ------------------------------------------------------------------ list (also Print / CSV)
if ($id === '') {
	$q = trim((string)($_GET['q'] ?? ''));
	$group = (string)($_GET['group'] ?? '');
	$cat = (string)($_GET['category'] ?? '');
	$where = []; $params = [];
	if ($group !== '') { $where[] = "COALESCE(emt_group,'') = ?"; $params[] = $group; }
	if ($cat !== '') { $where[] = "COALESCE(emt_category,'') = ?"; $params[] = $cat; }
	if ($q !== '') { $where[] = "(emt_code LIKE ? ESCAPE '!' OR emt_description LIKE ? ESCAPE '!' OR emt_subject LIKE ? ESCAPE '!' OR emt_to LIKE ? ESCAPE '!')"; array_push($params, ...array_fill(0, 4, NuDB::like($q))); }
	$rows = $db->all("SELECT t.*, f.sfo_code FROM $T t LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = t.emt_form_id" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
		. " ORDER BY emt_group, emt_category, emt_code, emt_language", $params);
	$groups = array_column($db->all("SELECT DISTINCT emt_group FROM $T WHERE emt_group IS NOT NULL AND emt_group <> '' ORDER BY emt_group"), 'emt_group');
	$cats = array_column($db->all("SELECT DISTINCT emt_category FROM $T WHERE emt_category IS NOT NULL AND emt_category <> '' ORDER BY emt_category"), 'emt_category');
	$keep = ['q' => $q, 'group' => $group, 'category' => $cat];
	NuListExport::handle(NuAcl::FORM_EMAILTEMPLATE, t('emt_title'), [
		t('emt_code')     => static fn($r) => $r['emt_code'],
		t('emt_description') => static fn($r) => $r['emt_description'],
		t('emt_group')    => static fn($r) => $r['emt_group'],
		t('emt_category') => static fn($r) => $r['emt_category'],
		t('emt_language') => static fn($r) => $r['emt_language'],
		t('emt_form')     => static fn($r) => $r['sfo_code'],
		t('mail_subject') => static fn($r) => $r['emt_subject'],
		t('mail_to')      => static fn($r) => $r['emt_to'],
	], static fn(int $limit) => array_slice($rows, 0, $limit), NuListExport::filterText([t('search') => $q, t('emt_group') => $group, t('emt_category') => $cat]));
}

nu_layout_start(t('emt_title'), 'email', ['head' => '']);
?>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><?= h(t('nav_email')) ?></h1>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?></div>
</div>
<ul class="nav nav-tabs mb-3">
	<?php if (NuAcl::can(NuAcl::FORM_SETUP)): ?>
		<li class="nav-item"><a class="nav-link" href="<?= h(nu_url('email', ['tab' => 'settings'])) ?>"><i class="bi bi-gear me-1"></i><?= h(t('mail_tab_settings')) ?></a></li>
		<li class="nav-item"><a class="nav-link" href="<?= h(nu_url('email', ['tab' => 'test'])) ?>"><i class="bi bi-send me-1"></i><?= h(t('mail_tab_test')) ?></a></li>
	<?php endif; ?>
	<li class="nav-item"><a class="nav-link active" aria-current="page" href="<?= h(nu_url('emailtemplates')) ?>"><i class="bi bi-file-earmark-richtext me-1"></i><?= h(t('emt_title')) ?></a></li>
	<?php if (NuAcl::can(NuAcl::FORM_EMAILLOG)): ?>
		<li class="nav-item"><a class="nav-link" href="<?= h(nu_url('email', ['tab' => 'log'])) ?>"><i class="bi bi-journal-text me-1"></i><?= h(t('mail_tab_log')) ?></a></li>
	<?php endif; ?>
</ul>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php endif; ?>

<?php if ($id === ''): ?>
	<form class="card card-body shadow-sm mb-3 py-3" method="get" action="index.php" role="search">
		<input type="hidden" name="p" value="emailtemplates">
		<div class="row g-2 align-items-end">
			<?php $filtered = $group !== '' || $cat !== ''; ?>
			<div class="col-md-6"><?= nu_list_search($q, '', 'nuEmtFilters', $filtered, $q !== '' || $filtered ? nu_url('emailtemplates') : null) ?></div>
			<div class="col-md-5 collapse<?= $filtered ? ' show' : '' ?>" id="nuEmtFilters"><div class="row g-2">
				<div class="col-sm-6"><label class="form-label small mb-1" for="f_g"><?= h(t('emt_group')) ?></label>
					<select class="form-select js-autosubmit" id="f_g" name="group"><option value=""><?= h(t('all')) ?></option><?php foreach ($groups as $g): ?><option<?= $g === $group ? ' selected' : '' ?>><?= h($g) ?></option><?php endforeach; ?></select></div>
				<div class="col-sm-6"><label class="form-label small mb-1" for="f_c"><?= h(t('emt_category')) ?></label>
					<select class="form-select js-autosubmit" id="f_c" name="category"><option value=""><?= h(t('all')) ?></option><?php foreach ($cats as $c): ?><option<?= $c === $cat ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></div>
			</div></div>
		</div>
	</form>
	<div class="card shadow-sm">
		<div class="card-header nuTitleBar">
			<span class="small text-body-secondary"><?= h(t('n_records', count($rows))) ?></span>
			<div class="d-flex flex-wrap gap-2"><?php if ($rights['add']): ?><a class="btn btn-sm nuAddBtn" title="<?= h(t('emt_new')) ?>" aria-label="<?= h(t('emt_new')) ?>" href="<?= h(nu_url('emailtemplates', ['id' => 'new'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></a><?php endif; ?>
						<?= NuListExport::buttons(NuAcl::FORM_EMAILTEMPLATE, 'emailtemplates', $keep, true) ?></div>
		</div>
		<div class="table-responsive">
			<table class="table table-hover align-middle mb-0 nu-table">
				<thead><tr><th><?= h(t('emt_code')) ?></th><th><?= h(t('emt_group')) ?></th><th><?= h(t('emt_category')) ?></th><th><?= h(t('emt_language')) ?></th>
					<th><?= h(t('emt_form')) ?></th><th><?= h(t('mail_subject')) ?></th><th><?= h(t('mail_to')) ?></th></tr></thead>
				<tbody>
				<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-body-secondary py-5"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
				<?php foreach ($rows as $r): ?>
					<tr data-href="<?= h(nu_url('emailtemplates', ['id' => (string)$r['zzzzsys_email_template_id']])) ?>" tabindex="0">
						<td class="fw-semibold"><?= h($r['emt_code']) ?><?php if ((string)$r['emt_template'] === '1'): ?> <span class="badge text-bg-light border"><?= h(t('emt_template_badge')) ?></span><?php endif; ?>
							<?php if ((string)$r['emt_description'] !== ''): ?><div class="small text-body-secondary fw-normal"><?= h($r['emt_description']) ?></div><?php endif; ?></td>
						<td><?php if ((string)$r['emt_group'] !== ''): ?><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= h($r['emt_group']) ?></span><?php endif; ?></td>
						<td class="small"><?= h($r['emt_category']) ?></td>
						<td class="small"><?= h($r['emt_language']) ?></td>
						<td class="small"><?= h($r['sfo_code']) ?></td>
						<td class="small text-break"><?= h(mb_strimwidth((string)$r['emt_subject'], 0, 80, '…')) ?></td>
						<td class="small text-break"><?= h(mb_strimwidth((string)$r['emt_to'], 0, 50, '…')) ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

<?php else: ?>
	<?php
	// ------------------------------------------------------------------ edit
	$v = static fn(string $k) => nu_is_post() ? (string)($_POST[$k] ?? '') : (string)($tpl[$k] ?? '');
	$isTemplate = (string)($tpl['emt_template'] ?? '') === '1';
	$locked = $isTemplate || !($tpl ? $rights['save'] : $rights['create']);   // nuBuilder's templates: read-only view
	$langs = NuEmailTemplate::languages();
	$data = ['toLabel' => t('mail_to'), 'csrf' => nu_csrf_token(), 'system' => NuEmailTemplate::SYSTEM_FIELDS, 'fieldsUrl' => nu_url('emailtemplates') . '&fields=',
		'labels' => ['insert' => t('emt_insert_hint'), 'no_fields' => t('emt_no_fields'), 'system' => t('emt_system_fields'), 'sent' => t('mail_test_ok'),
			'not_found' => t('emt_record_not_found')]];
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$fb = static fn(string $k) => '<div class="invalid-feedback">' . h($errors[$k] ?? '') . '</div>';
	?>
	<?php if ($isTemplate): ?><div class="alert alert-info small py-2"><i class="bi bi-info-circle me-1"></i><?= h(t('emt_is_template')) ?><?php
		$cloneCode = preg_replace('/_template$/', '', (string)$tpl['emt_code']);
		if ($cloneCode !== (string)$tpl['emt_code']): ?> <?= h(t('emt_clone_code', $cloneCode)) ?><?php endif; ?></div><?php endif; ?>
	<?php if ($tpl && !$isTemplate && (string)$tpl['emt_code'] === NuWelcome::TEMPLATE): ?>
		<div class="alert alert-secondary small py-2"><i class="bi bi-envelope-check me-1"></i><?= h(t('emt_welcome_hint')) ?></div>
	<?php endif; ?>
	<form method="post" data-nu-focus-first data-nu-guard id="emtForm" novalidate>
		<?= nu_csrf_field() ?>
		<input type="hidden" name="action" value="save">
		<div class="nuTitleBar mb-3">
			<?php // the code is in the field below (and in the folded line) – only screen readers get it as heading (0.8.434) ?>
			<h2 class="h5 mb-0<?= $tpl ? ' visually-hidden' : '' ?>"><?= h($tpl ? (string)$tpl['emt_code'] : t('emt_new')) ?></h2>
			<div class="d-flex flex-wrap gap-2"><?php // Back to the list + record buttons in one row (0.8.508) ?>
			<a class="btn nuBtnAct nuBackBtn" href="<?= h(nu_url('emailtemplates')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
			<?php if (!$locked): ?>
				<button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" id="emtSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
			<?php endif; ?>
			<?php if ($tpl && $rights['clone']): ?>
				<button class="btn nuBtnAct" type="submit" name="action" value="clone" formnovalidate><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></button>
			<?php endif; ?>
			<?php if ($tpl && $rights['delete'] && !$locked): ?>
				<button class="btn nuBtnAct nuBtnDel" type="submit" name="action" value="delete" formnovalidate data-confirm-submit="<?= h(t('emt_delete_confirm')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
			</div>
		</div>
		<div class="row g-3">
			<div class="col-xl-8">
				<div class="card shadow-sm mb-3"><div class="card-body"><fieldset<?= $locked ? ' disabled' : '' ?>><div class="row g-3">
					<?php // code, group, category, language, description and recipients can be folded away (0.8.432): the summary line shows code, description
					// and To; open until folded, then as last left in this browser (nuemailtemplates.js) – always open when one of these fields has an error
					$headErr = (bool)array_intersect_key($errors, array_flip(['emt_code', 'emt_group', 'emt_category', 'emt_description', 'emt_to', 'emt_cc', 'emt_bcc']));
					$headInfo = implode(' · ', array_filter([$v('emt_code'), $v('emt_description'), $v('emt_to') !== '' ? t('mail_to') . ': ' . $v('emt_to') : ''], static fn($x) => $x !== '')); ?>
					<details class="col-12 nuEmtHead" id="emtHead" open<?= $headErr ? ' data-nu-force-open="1"' : '' ?>>
						<summary class="nuEmtHeadSum"><i class="bi bi-chevron-right nuEmtChev" aria-hidden="true"></i><span class="fw-semibold text-nowrap"><?= h(t('emt_head')) ?></span>
							<span class="nuEmtHeadInfo small text-body-secondary text-truncate" id="emtHeadInfo"><?= h($headInfo) ?></span></summary>
					<div class="row g-3 pt-2">
					<div class="col-sm-4"><label class="form-label" for="emt_code"><?= h(t('emt_code')) ?> <span class="text-danger">*</span></label>
						<input class="form-control<?= $err('emt_code') ?>" id="emt_code" name="emt_code" maxlength="80" value="<?= h($v('emt_code')) ?>" required><?= $fb('emt_code') ?></div>
					<div class="col-sm-4"><label class="form-label" for="emt_group"><?= h(t('emt_group')) ?></label>
						<input class="form-control<?= $err('emt_group') ?>" id="emt_group" name="emt_group" maxlength="100" value="<?= h($v('emt_group')) ?>" list="emtGroups"><?= $fb('emt_group') ?>
						<datalist id="emtGroups"><?php foreach (array_column($db->all("SELECT DISTINCT emt_group FROM $T WHERE emt_group IS NOT NULL ORDER BY emt_group"), 'emt_group') as $g): ?><option value="<?= h($g) ?>"><?php endforeach; ?></datalist></div>
					<div class="col-sm-4"><label class="form-label" for="emt_category"><?= h(t('emt_category')) ?></label>
						<input class="form-control<?= $err('emt_category') ?>" id="emt_category" name="emt_category" maxlength="100" value="<?= h($v('emt_category')) ?>" list="emtCats"><?= $fb('emt_category') ?>
						<datalist id="emtCats"><?php foreach (array_column($db->all("SELECT DISTINCT emt_category FROM $T WHERE emt_category IS NOT NULL ORDER BY emt_category"), 'emt_category') as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist></div>
					<div class="col-sm-4"><label class="form-label" for="emt_language"><?= h(t('emt_language')) ?></label>
						<select class="form-select" id="emt_language" name="emt_language"><option value=""><?= h(t('emt_language_any')) ?></option>
							<?php foreach ($langs as $l): ?><option<?= $l === $v('emt_language') ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
					<div class="col-sm-8"><label class="form-label" for="emt_description"><?= h(t('emt_description')) ?></label>
						<input class="form-control<?= $err('emt_description') ?>" id="emt_description" name="emt_description" maxlength="200" value="<?= h($v('emt_description')) ?>"><?= $fb('emt_description') ?></div>
					<div class="col-12"><hr class="my-1"></div>
					<div class="col-12"><label class="form-label" for="emt_to"><?= h(t('mail_to')) ?></label>
						<input class="form-control emtTarget<?= $err('emt_to') ?>" id="emt_to" name="emt_to" maxlength="1000" value="<?= h($v('emt_to')) ?>" placeholder="name@example.ch, #email_field#"><?= $fb('emt_to') ?></div>
					<div class="col-sm-6"><label class="form-label" for="emt_cc">Cc</label>
						<input class="form-control emtTarget<?= $err('emt_cc') ?>" id="emt_cc" name="emt_cc" maxlength="1000" value="<?= h($v('emt_cc')) ?>"><?= $fb('emt_cc') ?></div>
					<div class="col-sm-6"><label class="form-label" for="emt_bcc">Bcc</label>
						<input class="form-control emtTarget<?= $err('emt_bcc') ?>" id="emt_bcc" name="emt_bcc" maxlength="1000" value="<?= h($v('emt_bcc')) ?>"><?= $fb('emt_bcc') ?></div>
					</div></details>
					<div class="col-12 nuEmtHeadEnd"><hr class="my-0"></div>
					<div class="col-12"><label class="form-label" for="emt_subject"><?= h(t('mail_subject')) ?></label>
						<input class="form-control emtTarget<?= $err('emt_subject') ?>" id="emt_subject" name="emt_subject" maxlength="1000" value="<?= h($v('emt_subject')) ?>"><?= $fb('emt_subject') ?></div>
					<div class="col-12">
						<div class="d-flex flex-wrap align-items-end gap-2 mb-1">
							<label class="form-label mb-0 me-auto" for="emt_body"><?= h(t('emt_body')) ?></label>
							<div class="btn-group btn-group-sm" role="group" aria-label="<?= h(t('emt_format')) ?>">
								<button type="button" class="btn btn-outline-secondary fw-bold" data-wrap="b" title="<b>">B</button>
								<button type="button" class="btn btn-outline-secondary fst-italic" data-wrap="i" title="<i>">I</button>
								<button type="button" class="btn btn-outline-secondary text-decoration-underline" data-wrap="u" title="<u>">U</button>
								<button type="button" class="btn btn-outline-secondary" data-wrap="a" title="<a href>"><i class="bi bi-link-45deg"></i></button>
								<button type="button" class="btn btn-outline-secondary" data-wrap="ul" title="<ul><li>"><i class="bi bi-list-ul"></i></button>
							</div>
						</div>
						<textarea class="form-control emtTarget nfdMono" id="emt_body" name="emt_body" rows="14"><?= h($v('emt_body')) ?></textarea>
						<div class="form-text"><?= h(t('emt_body_help')) ?></div>
					</div>
				</div></fieldset></div></div>

				<div class="card shadow-sm">
					<div class="card-header d-flex flex-wrap gap-2 align-items-center">
						<span class="fw-semibold me-auto"><i class="bi bi-eye me-1"></i><?= h(t('fmt_preview')) ?></span>
						<label class="small text-body-secondary" for="emtRecord"><?= h(t('builder_record')) ?></label>
						<input class="form-control form-control-sm emtRecord" id="emtRecord" placeholder="<?= h(t('emt_record_ph')) ?>">
						<button type="button" class="btn btn-sm btn-outline-primary" id="emtPreviewBtn"><i class="bi bi-arrow-clockwise me-1"></i><?= h(t('fmt_preview')) ?></button>
					</div>
					<div class="card-body small" id="emtPreviewHead"></div>
					<iframe class="nuMailBody emtPreview" id="emtPreview" sandbox="" title="<?= h(t('fmt_preview')) ?>"></iframe>
					<?php if ($tpl ? $rights['save'] : $rights['create']): // test e-mails need the right to change templates (0.8.207) ?>
					<div class="card-footer d-flex flex-wrap gap-2 align-items-center">
						<label class="small text-body-secondary" for="emtTestTo"><?= h(t('mail_send_test')) ?></label>
						<input type="email" class="form-control form-control-sm emtTestTo" id="emtTestTo" value="<?= h((string)(NuAuth::user()['email'] ?? '')) ?>">
						<button type="button" class="btn btn-sm btn-outline-secondary" id="emtTestBtn"><i class="bi bi-send me-1"></i><?= h(t('emt_send')) ?></button>
						<span class="small" id="emtTestResult" role="status"></span>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="col-xl-4">
				<div class="card shadow-sm emtFieldsCard">
					<div class="card-body pb-2">
						<label class="form-label" for="emt_form_id"><?= h(t('emt_form')) ?></label>
						<select class="form-select<?= $err('emt_form_id') ?>" id="emt_form_id" name="emt_form_id"<?= $locked ? ' disabled' : '' ?>>
							<option value="">—</option>
							<?php $curForm = $v('emt_form_id'); if ($curForm !== '' && !in_array($curForm, array_column($forms, 'zzzzsys_form_id'), true)): ?><option value="<?= h($curForm) ?>" selected><?= h($curForm) ?></option><?php endif; ?>
							<?php foreach ($forms as $f): ?><option value="<?= h($f['zzzzsys_form_id']) ?>"<?= (string)$f['zzzzsys_form_id'] === $v('emt_form_id') ? ' selected' : '' ?>><?= h($f['sfo_code'] . ' – ' . $f['sfo_description']) ?></option><?php endforeach; ?>
						</select><?= $fb('emt_form_id') ?>
						<input type="search" class="form-control form-control-sm mt-2" id="emtFieldSearch" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>">
						<div class="form-text" id="emtInsertHint"><?= h(t('emt_insert_hint')) ?></div>
					</div>
					<div class="list-group list-group-flush emtFields" id="emtFields" role="list"></div>
				</div>
			</div>
		</div>
	</form>
	<script type="application/json" id="emtData"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?php
nu_layout_end($id !== '' ? '<script src="' . nu_asset('assets/js/nuemailtemplates.js') . '"></script>' : '');
