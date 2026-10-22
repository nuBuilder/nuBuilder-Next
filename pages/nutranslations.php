<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Translations (zzzzsys_translate) – nuBuilder's form "nutranslate". Rights of that form.
 *   list (?lang, ?q, ?src = own|nu|all) · ?view=missing&lang=… texts of the app's forms without a translation
 *   ?id=<id> | ?id=new[&lang=…&en=…] edit. nuBuilder's own rows ("nu…") are read-only; "Override" creates an own row.
 */

NuAcl::require(NuAcl::FORM_TRANSLATE);
$db     = nu_db();
$rights = NuAcl::rights(NuAcl::FORM_TRANSLATE);
$T      = NuTranslate::TABLE;
NuTranslate::ensureTable();
$langs  = NuTranslate::languages();

$id    = (string)($_GET['id'] ?? '');
$isNew = $id === 'new';
$row   = null;
if ($id !== '' && !$isNew) {
	$row = $db->one("SELECT * FROM $T WHERE zzzzsys_translate_id = ?", [$id]);
	if (!$row) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
$isNu = $row && str_starts_with((string)$row['zzzzsys_translate_id'], 'nu');
$errors = [];

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save' && !$isNu) {
		NuAcl::require(NuAcl::FORM_TRANSLATE, $row ? 'save' : 'create');
		$in = ['trl_language' => nu_post('trl_language'), 'trl_english' => (string)($_POST['trl_english'] ?? ''), 'trl_translation' => (string)($_POST['trl_translation'] ?? '')];
		$in = array_map(static fn($v) => str_replace("\r\n", "\n", (string)$v), $in);
		[$newId, $errors] = NuTranslate::save($in, $row ? $id : '');
		if (!$errors) {
			NuLog::write($row ? 'translation_changed' : 'translation_created', $T, (string)$newId, ['language' => $in['trl_language'], 'english' => mb_substr($in['trl_english'], 0, 100)]);
			nu_flash('success', t('saved'));
			$back = nu_post('back');
			nu_redirect($back === 'missing' ? nu_url('translations', ['view' => 'missing', 'lang' => $in['trl_language']]) : nu_url('translations', ['id' => $newId]));
		}
	}
	if ($action === 'delete' && $row && !$isNu) {
		NuAcl::require(NuAcl::FORM_TRANSLATE, 'delete');
		$db->delete($T, 'zzzzsys_translate_id', $id);
		NuLog::write('translation_deleted', $T, $id, ['language' => (string)$row['trl_language'], 'english' => mb_substr((string)$row['trl_english'], 0, 100)]);
		nu_flash('success', t('trl_deleted'));
		nu_redirect(nu_url('translations', ['lang' => (string)$row['trl_language']]));
	}
}

$view = $id === '' && ($_GET['view'] ?? '') === 'missing' ? 'missing' : 'list';
// ------------------------------------------------------------------ list (also Print / CSV)
if ($id === '' && $view === 'list') {
	// list: columns, filters and sort of the chosen list view (0.8.334, core/nulistdefs.php → translations; the view is ?lv=, ?view= is the tab)
	$lang = (string)($_GET['lang'] ?? '');
	NuListView::page('translations', [
		'before' => NuListView::crumbs([[t('nav_forms'), nu_url('forms')], [t('trl_title')]])
			. '<ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link active" aria-current="page" href="' . h(nu_url('translations')) . '"><i class="bi bi-translate me-1"></i>' . h(t('trl_tab_all')) . '</a></li>'
			. '<li class="nav-item"><a class="nav-link" href="' . h(nu_url('translations', ['view' => 'missing', 'lang' => $lang ?: null])) . '"><i class="bi bi-exclamation-circle me-1"></i>' . h(t('trl_tab_missing')) . '</a></li></ul>',
		'active' => 'forms', 'title_extra' => NuListView::infoTip(t('trl_hint', NuTranslate::language() !== '' ? NuTranslate::language() : 'English')),
		'buttons' => $rights['add'] ? '<a class="btn nuAddBtn" title="' . h(t('trl_new')) . '" href="' . h(nu_url('translations', ['id' => 'new', 'lang' => $lang ?: null])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '',
	]);
	return;
}

nu_layout_start(t('trl_title'), 'forms');
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
		<?php if ($id !== ''): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('translations')) ?>"><?= h(t('trl_title')) ?></a></li>
			<li class="breadcrumb-item active" aria-current="page"><?= h($row ? mb_strimwidth(strip_tags((string)$row['trl_english']), 0, 60, '…') : t('trl_new')) ?></li>
		<?php else: ?>
			<li class="breadcrumb-item active" aria-current="page"><?= h(t('trl_title')) ?></li>
		<?php endif; ?>
	</ol>
</nav>

<?php if ($id === ''): ?>
	<?php $lang = (string)($_GET['lang'] ?? ''); ?>
	<div class="nuTitleBar mb-3">
		<h1 class="h3 mb-0"><?= h(t('trl_title')) ?></h1>
		<div class="nuTitleSub text-body-secondary small"><?= h(t('trl_hint', NuTranslate::language() !== '' ? NuTranslate::language() : 'English')) ?></div>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?><?php if ($rights['add']): ?><a class="btn nuAddBtn" title="<?= h(t('trl_new')) ?>" href="<?= h(nu_url('translations', ['id' => 'new', 'lang' => $lang ?: null])) ?>"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></a><?php endif; ?>
		</div>
	</div>
	<ul class="nav nav-tabs mb-3">
		<li class="nav-item"><a class="nav-link<?= $view === 'list' ? ' active' : '' ?>" href="<?= h(nu_url('translations', ['lang' => $lang ?: null])) ?>"><i class="bi bi-translate me-1"></i><?= h(t('trl_tab_all')) ?></a></li>
		<li class="nav-item"><a class="nav-link<?= $view === 'missing' ? ' active' : '' ?>" href="<?= h(nu_url('translations', ['view' => 'missing', 'lang' => $lang ?: null])) ?>"><i class="bi bi-exclamation-circle me-1"></i><?= h(t('trl_tab_missing')) ?></a></li>
	</ul>

	<?php if ($view === 'list'): ?>
	<?php else: ?>
		<?php
		if ($lang === '') $lang = NuTranslate::language() ?: ($langs[0] ?? '');
		$missing = $lang !== '' ? NuTranslate::missing($lang) : [];
		?>
		<form class="card card-body shadow-sm mb-3 py-3" method="get" action="index.php">
			<input type="hidden" name="p" value="translations"><input type="hidden" name="view" value="missing">
			<div class="row g-2 align-items-end">
				<div class="col-sm-6 col-md-4"><label class="form-label small mb-1" for="m_l"><?= h(t('trl_language')) ?></label>
					<select class="form-select js-autosubmit" id="m_l" name="lang"><?php foreach ($langs as $l): ?><option<?= $l === $lang ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
				<div class="col-md-8 small text-body-secondary"><?= h(t('trl_missing_hint')) ?></div>
			</div>
		</form>
		<?php if ($lang === ''): ?>
			<div class="alert alert-info"><?= h(t('trl_no_language')) ?></div>
		<?php else: ?>
			<div class="card shadow-sm">
				<div class="card-header small text-body-secondary"><?= h(t('trl_missing_count', count($missing), $lang)) ?></div>
				<div class="table-responsive">
					<table class="table align-middle mb-0 nu-table" id="trlMissing">
						<thead><tr><th><?= h(t('trl_english')) ?></th><th><?= h(t('trl_used_in')) ?></th><th></th></tr></thead>
						<tbody>
						<?php if (!$missing): ?><tr><td colspan="3" class="text-center text-success py-5"><i class="bi bi-check-circle me-1"></i><?= h(t('trl_all_done')) ?></td></tr><?php endif; ?>
						<?php foreach ($missing as $text => $where): ?>
							<tr>
								<td class="text-break"><?= h($text) ?></td>
								<td class="small text-body-secondary"><?= h(implode(', ', $where)) ?></td>
								<td class="text-end"><?php if ($rights['create']): ?><a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= h(nu_url('translations', ['id' => 'new', 'lang' => $lang, 'en' => $text, 'back' => 'missing'])) ?>"><i class="bi bi-translate me-1"></i><?= h(t('trl_translate')) ?></a><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>

<?php else: ?>
	<?php
	$post = nu_is_post() && nu_post('action') === 'save';
	$v = static fn(string $k, string $get = '') => $post ? (string)($_POST[$k] ?? '') : (string)($row[$k] ?? ($get !== '' ? ($_GET[$get] ?? '') : ''));
	$locked = $isNu || !($row ? $rights['save'] : $rights['create']);
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$fb = static fn(string $k) => '<div class="invalid-feedback">' . h($errors[$k] ?? '') . '</div>';
	$curLang = $v('trl_language', 'lang') ?: NuTranslate::language();
	$back = (string)($_GET['back'] ?? nu_post('back'));
	$override = $isNu ? $db->value("SELECT zzzzsys_translate_id FROM $T WHERE trl_language = ? AND trl_english = ? AND zzzzsys_translate_id NOT LIKE 'nu%'", [(string)$row['trl_language'], (string)$row['trl_english']]) : null;
	?>
	<form method="post" data-nu-focus-first data-nu-guard id="trlForm" novalidate>
		<?= nu_csrf_field() ?>
		<input type="hidden" name="action" value="save"><input type="hidden" name="back" value="<?= h($back) ?>">
		<div class="nuTitleBar mb-3">
			<h1 class="h4 mb-0"><?= h($row ? t('trl_edit') : t('trl_new')) ?>
				<?php if ($isNu): ?><span class="badge bg-info-subtle text-info-emphasis fs-6 align-middle">nuBuilder</span><?php endif; ?></h1>
			<a class="btn nuBtnAct" href="<?= h(($back === 'missing' ? nu_url('translations', ['view' => 'missing', 'lang' => $curLang]) : nu_url('translations'))) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
			<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" id="trlSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
			<?php if ($isNu && $rights['create']): ?>
				<?php if ($override): ?>
					<a class="btn btn-outline-primary" href="<?= h(nu_url('translations', ['id' => (string)$override])) ?>"><i class="bi bi-pencil me-1"></i><?= h(t('trl_open_override')) ?></a>
				<?php else: ?>
					<a class="btn btn-outline-primary" href="<?= h(nu_url('translations', ['id' => 'new', 'lang' => (string)$row['trl_language'], 'en' => (string)$row['trl_english'], 'tr' => (string)$row['trl_translation']])) ?>"><i class="bi bi-pencil-square me-1"></i><?= h(t('trl_override')) ?></a>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ($row && !$isNu && $rights['delete']): ?>
				<button class="btn nuBtnAct nuBtnDel" type="submit" name="action" value="delete" formnovalidate data-confirm-submit="<?= h(t('trl_delete_confirm')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
		</div>
		<?php if ($isNu): ?><div class="alert alert-info py-2 small"><?= h(t('trl_nu_locked')) ?></div><?php endif; ?>
		<fieldset class="card card-body shadow-sm"<?= $locked ? ' disabled' : '' ?>>
			<div class="row g-2 mb-3">
				<label class="col-sm-3 col-form-label text-sm-end" for="trl_language"><?= h(t('trl_language')) ?> <span class="text-danger">*</span></label>
				<div class="col-sm-9 col-md-5">
					<input class="form-control<?= $err('trl_language') ?>" id="trl_language" name="trl_language" maxlength="20" list="trlLangs" required value="<?= h($curLang) ?>">
					<datalist id="trlLangs"><?php foreach ($langs as $l): ?><option value="<?= h($l) ?>"><?php endforeach; ?></datalist><?= $fb('trl_language') ?>
				</div>
			</div>
			<div class="row g-2 mb-3">
				<label class="col-sm-3 col-form-label text-sm-end" for="trl_english"><?= h(t('trl_english')) ?> <span class="text-danger">*</span></label>
				<div class="col-sm-9">
					<textarea class="form-control font-monospace<?= $err('trl_english') ?>" id="trl_english" name="trl_english" rows="2" maxlength="500" required<?= $row ? '' : ' autofocus' ?>><?= h($v('trl_english', 'en')) ?></textarea><?= $fb('trl_english') ?>
					<div class="form-text"><?= h(t('trl_english_hint')) ?></div>
				</div>
			</div>
			<div class="row g-2">
				<label class="col-sm-3 col-form-label text-sm-end" for="trl_translation"><?= h(t('trl_translation')) ?> <span class="text-danger">*</span></label>
				<div class="col-sm-9">
					<textarea class="form-control<?= $err('trl_translation') ?>" id="trl_translation" name="trl_translation" rows="2" maxlength="500" required<?= $row || $v('trl_english', 'en') === '' ? '' : ' autofocus' ?>><?= h($v('trl_translation', 'tr')) ?></textarea><?= $fb('trl_translation') ?>
				</div>
			</div>
		</fieldset>
	</form>
<?php endif; ?>
<?php
nu_layout_end($id !== '' ? '<script src="' . nu_asset('assets/js/nuitems.js') . '"></script>' : '');
