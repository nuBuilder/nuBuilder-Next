<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Files (zzzzsys_file) – nuBuilder's form "nufile": small images and documents kept in the database, used by image
 * objects, reports and custom code. Rights of the form "nufile". With nuBuilder's "set_denied" = 1 its own files
 * ("nu…") are hidden; system files (sfi_system = 1 or "nu…") can only be changed by the globeadmin.
 *   list · ?id=<id> | ?id=new edit · ?raw=<id> the file itself (images inline, everything else as download; &dl=1 forces download)
 */

NuAcl::require(NuAcl::FORM_FILE);
$db     = nu_db();
$rights = NuAcl::rights(NuAcl::FORM_FILE);
$T      = NuFile::TABLE;
NuFile::ensureTable();
$ga     = (bool)NuAuth::user()['globeadmin'];
$denied = (string)(NuMailer::setupRow()['set_denied'] ?? '') === '1';
$hideNu = $denied && !$ga ? " AND zzzzsys_file_id NOT LIKE 'nu%'" : '';
$isSystem = static fn(array $f) => (string)($f['sfi_system'] ?? '') === '1' || str_starts_with((string)$f['zzzzsys_file_id'], 'nu');

// ------------------------------------------------------------------ the file itself
if (isset($_GET['raw'])) {
	$f = $db->one("SELECT zzzzsys_file_id, sfi_code, sfi_json FROM $T WHERE zzzzsys_file_id = ?$hideNu", [(string)$_GET['raw']]);
	NuFile::serve($f, !empty($_GET['dl']));
}

$id    = (string)($_GET['id'] ?? '');
$isNew = $id === 'new';
$file  = null;
if ($id !== '' && !$isNew) {
	$file = $db->one("SELECT * FROM $T WHERE zzzzsys_file_id = ?$hideNu", [$id]);
	if (!$file) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
$sysLocked = $file && $isSystem($file) && !$ga;
$errors = [];

/** Uploaded file of this request (field "file"), or null. Errors go to $errors['file']. */
$upload = static function (array &$errors): ?array {
	$f = $_FILES['file'] ?? null;
	if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
	if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) { $errors['file'] = t('file_err_size', (int)(NuFile::maxBytes() / 1024)); return null; }
	if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $errors['file'] = t('file_err_upload'); return null; }
	return ['content' => (string)file_get_contents($f['tmp_name']), 'name' => (string)$f['name']];
};

if (nu_is_post()) {
	$action = nu_post('action');
	if ($sysLocked && in_array($action, ['save', 'delete'], true)) NuAcl::require('__globeadmin_only__');
	if ($action === 'save') {
		NuAcl::require(NuAcl::FORM_FILE, $file ? 'save' : 'create');
		$in = ['sfi_code' => nu_post('sfi_code'), 'sfi_description' => nu_post('sfi_description'), 'sfi_group' => nu_post('sfi_group')];
		$up = $upload($errors);
		if (!$errors) [$newId, $errors] = NuFile::save($in, $up, $file ? $id : '', $file['sfi_json'] ?? null);
		if (!$errors) {
			NuLog::write($file ? 'file_changed' : 'file_created', $T, (string)$newId, ['code' => $in['sfi_code']] + ($up ? ['name' => NuFile::cleanName($up['name']), 'size' => strlen($up['content'])] : []));
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('files', ['id' => $newId]));
		}
	}
	if ($action === 'clone' && $file) {
		NuAcl::require(NuAcl::FORM_FILE, 'clone');
		$code = (string)$file['sfi_code'];
		for ($n = 2; $db->value("SELECT COUNT(*) FROM $T WHERE sfi_code = ?", [$code . '_' . $n]); $n++);
		[$newId, $e] = NuFile::save(['sfi_code' => mb_substr($code, 0, 290) . '_' . $n] + $file, null, '', (string)$file['sfi_json']);
		if ($newId) {
			NuLog::write('file_created', $T, $newId, ['clone_of' => $id]);
			nu_flash('success', t('file_cloned'));
			nu_redirect(nu_url('files', ['id' => $newId]));
		}
		$errors = $e;
	}
	if ($action === 'delete' && $file) {
		NuAcl::require(NuAcl::FORM_FILE, 'delete');
		$used = NuFile::usage($id);
		if ($used) {
			$errors['_'] = t('file_err_used', implode(', ', array_map(static fn($u) => trim(($u['sfo_code'] ?? '') . ' · ' . $u['sob_all_id'], ' ·'), $used)));
		} else {
			$db->delete($T, 'zzzzsys_file_id', $id);
			NuLog::write('file_deleted', $T, $id, ['code' => (string)$file['sfi_code']]);
			nu_flash('success', t('file_deleted'));
			nu_redirect(nu_url('files'));
		}
	}
}

// ------------------------------------------------------------------ list (also Print / CSV)
if ($id === '') {
	// list: columns, filters and sort of the chosen list view (0.8.334, core/nulistdefs.php)
	NuListView::page('files', [
		'before' => NuListView::crumbs([[t('nav_forms'), nu_url('forms')], [t('file_title')]]), 'active' => 'forms',
		'title_extra' => NuListView::infoTip(t('file_hint', (int)(NuFile::maxBytes() / 1024))),
		'buttons' => $rights['add'] ? '<a class="btn nuAddBtn" title="' . h(t('file_new')) . '" href="' . h(nu_url('files', ['id' => 'new'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '',
	]);
	return;
}

nu_layout_start(t('file_title'), 'forms');
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
		<?php if ($id !== ''): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('files')) ?>"><?= h(t('file_title')) ?></a></li>
			<li class="breadcrumb-item active" aria-current="page"><?= h($file ? (string)$file['sfi_code'] : t('file_new')) ?></li>
		<?php else: ?>
			<li class="breadcrumb-item active" aria-current="page"><?= h(t('file_title')) ?></li>
		<?php endif; ?>
	</ol>
</nav>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php endif; ?>

<?php if ($id === ''): ?>
<?php else: ?>
	<?php
	// ------------------------------------------------------------------ edit
	$v = static fn(string $k) => nu_is_post() && nu_post('action') === 'save' ? (string)($_POST[$k] ?? '') : (string)($file[$k] ?? '');
	$locked = $sysLocked || !($file ? $rights['save'] : $rights['create']);
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$fb = static fn(string $k) => '<div class="invalid-feedback">' . h($errors[$k] ?? '') . '</div>';
	$info = $file ? NuFile::info((string)$file['sfi_json']) : [];
	$used = $file ? NuFile::usage($id) : [];
	$row = static fn(string $k, string $label, string $control, bool $req = false) =>
		'<div class="row g-2 mb-3 align-items-start"><label class="col-sm-3 col-form-label text-sm-end" for="' . $k . '">' . h($label)
		. ($req ? ' <span class="text-danger">*</span>' : '') . '</label><div class="col-sm-9">' . $control . '</div></div>';
	$groupList = array_column($db->all("SELECT DISTINCT sfi_group FROM $T WHERE sfi_group IS NOT NULL AND sfi_group <> '' ORDER BY sfi_group"), 'sfi_group');
	?>
	<form method="post" data-nu-focus-first data-nu-guard id="fileForm" enctype="multipart/form-data" novalidate data-max="<?= NuFile::maxBytes() ?>" data-too-big="<?= h(t('file_err_size', (int)(NuFile::maxBytes() / 1024))) ?>">
		<?= nu_csrf_field() ?>
		<input type="hidden" name="MAX_FILE_SIZE" value="<?= NuFile::maxBytes() ?>">
		<input type="hidden" name="action" value="save">
		<div class="nuTitleBar mb-3">
			<h1 class="h4 mb-0"><?= h($file ? (string)$file['sfi_code'] : t('file_new')) ?>
				<?php if ($file && $isSystem($file)): ?><span class="badge bg-info-subtle text-info-emphasis fs-6 align-middle"><?= h(t('file_system')) ?></span><?php endif; ?></h1>
			<div class="d-flex flex-wrap gap-2 align-items-center">   <!-- buttons in their own row, as on every page -->
			<a class="btn nuBtnAct" href="<?= h(nu_url('files')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
			<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" id="fileSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
			<?php if ($file && $rights['clone']): ?>
				<button class="btn nuBtnAct" type="submit" name="action" value="clone" formnovalidate><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></button>
			<?php endif; ?>
			<?php if ($file && $rights['delete'] && !$sysLocked): ?>
				<button class="btn nuBtnAct nuBtnDel" type="submit" name="action" value="delete" formnovalidate data-confirm-submit="<?= h(t('file_delete_confirm')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
			<?php if ($file): ?><?= NuFormInfo::record($T, (string)$id, ['code' => (string)$file['sfi_code']]) /* (i) record info (0.8.501) */ ?><?php endif; ?>
			</div>
		</div>
		<?php if ($sysLocked): ?><div class="alert alert-info py-2 small"><?= h(t('file_system_locked')) ?></div><?php endif; ?>
		<div class="row g-3">
			<div class="col-lg-7">
				<fieldset class="card card-body shadow-sm"<?= $locked ? ' disabled' : '' ?>>
					<?= $row('sfi_code', t('file_code'), '<input class="form-control' . $err('sfi_code') . '" id="sfi_code" name="sfi_code" maxlength="300" required value="' . h($v('sfi_code')) . '"' . ($isNew ? ' autofocus' : '') . '>' . $fb('sfi_code')
						. '<div class="form-text">' . h(t('file_code_hint')) . '</div>', true) ?>
					<?= $row('sfi_description', t('file_description'), '<input class="form-control' . $err('sfi_description') . '" id="sfi_description" name="sfi_description" maxlength="300" value="' . h($v('sfi_description')) . '">' . $fb('sfi_description')) ?>
					<?= $row('sfi_group', t('file_group'), '<input class="form-control' . $err('sfi_group') . '" id="sfi_group" name="sfi_group" maxlength="300" list="fileGroups" value="' . h($v('sfi_group')) . '">' . $fb('sfi_group')
						. '<datalist id="fileGroups">' . implode('', array_map(static fn($g) => '<option value="' . h($g) . '">', $groupList)) . '</datalist>') ?>
					<?= $row('file', t('file_file'), '<div class="nuFileDrop' . $err('file') . '" id="fileDrop" tabindex="0">'
						. '<input class="form-control' . $err('file') . '" type="file" id="file" name="file"' . ($isNew ? ' required' : '') . '>'
						. '<div class="small text-body-secondary mt-1">' . h(t('file_drop_hint', (int)(NuFile::maxBytes() / 1024))) . '</div></div>' . $fb('file'), $isNew) ?>
				</fieldset>
			</div>
			<div class="col-lg-5">
				<div class="card shadow-sm">
					<div class="card-header small fw-semibold"><?= h(t('file_preview')) ?></div>
					<div class="card-body">
						<div class="nuFilePreview mb-2" id="filePreview">
							<?php if ($info['image'] ?? false): ?><img src="<?= h(nu_url('files', ['raw' => $id])) ?>" alt="<?= h($info['name']) ?>">
							<?php elseif ($info): ?><i class="bi bi-file-earmark display-3 text-body-secondary"></i>
							<?php else: ?><span class="text-body-secondary small"><?= h(t('file_none')) ?></span><?php endif; ?>
						</div>
						<div id="fileMeta" class="small">
							<?php if ($info): ?>
								<div class="fw-semibold text-break"><?= h($info['name']) ?></div>
								<div class="text-body-secondary"><?= h($info['type']) ?> · <?= h(NuFile::size($info['size'])) ?></div>
								<a class="btn btn-sm btn-outline-secondary mt-2" href="<?= h(nu_url('files', ['raw' => $id, 'dl' => '1'])) ?>"><i class="bi bi-download me-1"></i><?= h(t('file_download')) ?></a>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php if ($file): ?>
					<div class="card shadow-sm mt-3">
						<div class="card-header small fw-semibold"><?= h(t('file_used_by')) ?></div>
						<div class="card-body small">
							<?php if (!$used): ?><span class="text-body-secondary"><?= h(t('file_unused')) ?></span>
							<?php else: ?><ul class="mb-0 ps-3"><?php foreach ($used as $u): ?><li><?= h(trim(($u['sfo_code'] ?? '') . ' · ' . $u['sob_all_id'], ' ·')) ?></li><?php endforeach; ?></ul><?php endif; ?>
							<div class="text-body-secondary mt-2"><?= h(t('file_used_hint', (string)$file['sfi_code'])) ?></div>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</form>
<?php endif; ?>
<?php
nu_layout_end($id !== '' ? '<script src="' . nu_asset('assets/js/nufiles.js') . '"></script>' : '');
