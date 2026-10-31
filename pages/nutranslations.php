<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Translations (zzzzsys_translate) – nuBuilder's form "nutranslate". Rights of that form.
 *   list (?lang, ?q, ?src = own|nu|all) · ?view=missing&lang=… texts of the app's forms without a translation
 *   ?view=missing&f=<form> (0.8.754): one form and its sub-forms in every target language + translating with AI
 *   (POST ai_prompt = JSON with the prompt, ai_check = preview of a pasted answer, ai_save = the picked rows; NuTranslate::ai*)
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
			$bf = nu_post('back_f');
			nu_redirect($back === 'missing' ? nu_url('translations', ['view' => 'missing', 'lang' => $bf !== '' ? null : $in['trl_language'], 'f' => $bf ?: null]) : nu_url('translations', ['id' => $newId]));
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

// ------------------------------------------------------------------ one form: translating with AI (0.8.754)
$aiForm = (string)($_GET['f'] ?? '');
if ($aiForm !== '' && !isset(NuTranslate::forms()[$aiForm])) $aiForm = '';
$targets = NuTranslate::targetLanguages();
$aiPick = static fn(): array => array_values(array_intersect($targets, array_map('strval', (array)($_POST['ai_lang'] ?? []))));
$aiPreview = null;
if (nu_is_post() && $id === '' && $aiForm !== '' && in_array(nu_post('action'), ['ai_prompt', 'ai_check', 'ai_save'], true)) {
	if (!$rights['create'] && !$rights['save']) NuAcl::require(NuAcl::FORM_TRANSLATE, 'create');
	$action = nu_post('action');
	if ($action === 'ai_prompt') {                                // JSON: the prompt for the chosen languages (nothing stored)
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		$pl = $aiPick();
		if (!$pl) exit(json_encode(['ok' => false, 'message' => t('trl_ai_pick_lang')]));
		$r = NuTranslate::aiPrompt($aiForm, $pl, nu_check('ai_only') === '1');
		exit(json_encode(['ok' => $r['count'] > 0, 'prompt' => $r['prompt'], 'count' => $r['count'], 'message' => $r['count'] ? t('trl_ai_copied', $r['count']) : t('trl_ai_nothing')], JSON_UNESCAPED_UNICODE));
	}
	$answer = (string)($_POST['ai_answer'] ?? '');
	$aiPreview = NuTranslate::aiCheck($answer, $aiForm, $targets);
	if ($action === 'ai_save' && $aiPreview['error'] === '') {
		$n = NuTranslate::aiSave($aiPreview['rows'], (array)($_POST['pick'] ?? []), (bool)$rights['create'], (bool)$rights['save']);
		NuLog::write('translations_ai', $T, $aiForm, $n);
		nu_flash('success', t('trl_ai_saved', $n['created'], $n['changed'], $n['skipped']));
		nu_redirect(nu_url('translations', ['view' => 'missing', 'f' => $aiForm]));
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

nu_layout_start(t('trl_title'), 'forms', ['head' => $view === 'missing' && $id === '' ? nu_ts_head() : '']);
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
		if ($aiForm === '') $missing = $lang !== '' ? NuTranslate::missing($lang) : [];
		$forms = NuTranslate::forms();
		?>
		<form class="card card-body shadow-sm mb-3 py-3" method="get" action="index.php" id="trlFilter">
			<input type="hidden" name="p" value="translations"><input type="hidden" name="view" value="missing">
			<div class="row g-2 align-items-end">
				<div class="col-sm-6 col-md-4"><label class="form-label small mb-1" for="m_f"><?= h(t('trl_form')) ?></label><?= NuListView::infoTip(t('trl_form_hint')) ?>
					<select class="form-select nuListTomSelect js-autosubmit" id="m_f" name="f"><option value=""><?= h(t('trl_form_all')) ?></option>
					<?php foreach ($forms as $fid => $fl): ?><option value="<?= h($fid) ?>"<?= $fid === $aiForm ? ' selected' : '' ?>><?= h($fl) ?></option><?php endforeach; ?></select></div>
				<?php if ($aiForm === ''): ?>
				<div class="col-sm-6 col-md-3"><label class="form-label small mb-1" for="m_l"><?= h(t('trl_language')) ?></label>
					<select class="form-select js-autosubmit" id="m_l" name="lang"><?php foreach ($langs as $l): ?><option<?= $l === $lang ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
				<div class="col-md-5 small text-body-secondary"><?= h(t('trl_missing_hint')) ?></div>
				<?php endif; ?>
			</div>
		</form>
		<?php if ($aiForm !== ''): ?>
			<?php
			$mx = NuTranslate::matrix($aiForm, $targets);
			$kindT = static fn(string $k) => t('trl_kind_' . $k);
			$gaps = array_filter($mx['missing']);
			?>
			<?php if (!$targets): ?>
				<div class="alert alert-info"><?= h(t('trl_no_language')) ?></div>
			<?php else: ?>
			<?php if (($rights['create'] || $rights['save']) && $mx['rows']): ?>
			<div class="card shadow-sm mb-3" id="trlAi">
				<div class="card-header fw-semibold"><i class="bi bi-stars me-2" aria-hidden="true"></i><?= h(t('trl_ai_title')) ?></div>
				<div class="card-body">
					<p class="small text-body-secondary"><?= h(t('trl_ai_intro')) ?></p>
					<form method="post" id="trlAiPromptForm" data-url="<?= h(nu_url('translations', ['view' => 'missing', 'f' => $aiForm])) ?>">
						<?= nu_csrf_field() ?><input type="hidden" name="action" value="ai_prompt">
						<div class="mb-2"><span class="fw-semibold small me-2"><?= h(t('trl_ai_langs')) ?></span>
						<?php foreach ($targets as $i => $l): $on = !empty($gaps[$l]) || !$gaps; ?>
							<div class="form-check form-check-inline"><input class="form-check-input trlAiLang" type="checkbox" name="ai_lang[]" value="<?= h($l) ?>" id="trlAiL<?= $i ?>"<?= $on ? ' checked' : '' ?>><label class="form-check-label" for="trlAiL<?= $i ?>"><?= h($l) ?><?= !empty($gaps[$l]) ? ' <span class="badge text-bg-warning">' . (int)$gaps[$l] . '</span>' : '' ?></label></div>
						<?php endforeach; ?></div>
						<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="ai_only" value="1" id="trlAiOnly" checked><label class="form-check-label" for="trlAiOnly"><?= h(t('trl_ai_only_missing')) ?></label></div>
						<button class="btn btn-outline-primary" type="submit" id="trlAiCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('trl_ai_copy')) ?></button>
						<span class="small ms-2" id="trlAiMsg" role="status" aria-live="polite"></span>
						<textarea class="form-control font-monospace small mt-2 d-none" id="trlAiPromptOut" rows="6" readonly data-fail="<?= h(t('trl_ai_copy_fail')) ?>" aria-label="<?= h(t('trl_ai_copy')) ?>"></textarea>
					</form>
					<hr>
					<form method="post" id="trlAiCheckForm" action="<?= h(nu_url('translations', ['view' => 'missing', 'f' => $aiForm])) ?>">
						<?= nu_csrf_field() ?><input type="hidden" name="action" value="ai_check">
						<label class="form-label fw-semibold small" for="trlAiAnswer"><?= h(t('trl_ai_answer')) ?></label>
						<textarea class="form-control font-monospace small mb-2" name="ai_answer" id="trlAiAnswer" rows="6" spellcheck="false"><?= h((string)($_POST['ai_answer'] ?? '')) ?></textarea>
						<button class="btn btn-primary" type="submit" id="trlAiCheck"><i class="bi bi-check2-square me-1"></i><?= h(t('trl_ai_check')) ?></button>
					</form>
					<?php if ($aiPreview !== null): ?>
						<?php if ($aiPreview['error'] !== ''): ?>
							<div class="alert alert-danger mt-3 mb-0" id="trlAiError"><?= h($aiPreview['error']) ?></div>
						<?php else: ?>
						<form method="post" class="mt-3" id="trlAiSaveForm" action="<?= h(nu_url('translations', ['view' => 'missing', 'f' => $aiForm])) ?>">
							<?= nu_csrf_field() ?><input type="hidden" name="action" value="ai_save">
							<textarea class="d-none" name="ai_answer" aria-hidden="true"><?= h((string)($_POST['ai_answer'] ?? '')) ?></textarea>
							<div class="small text-body-secondary mb-2"><?= h(t('trl_ai_preview', count($aiPreview['rows']))) ?></div>
							<div class="table-responsive"><table class="table table-sm align-middle nu-table" id="trlAiPreview">
								<thead><tr><th><input class="form-check-input" type="checkbox" id="trlAiAll" aria-label="<?= h(t('trl_ai_save')) ?>" checked></th><th><?= h(t('trl_language')) ?></th><th><?= h(t('trl_english')) ?></th><th><?= h(t('trl_translation')) ?></th><th><?= h(t('trl_ai_current')) ?></th><th></th></tr></thead>
								<tbody>
								<?php foreach ($aiPreview['rows'] as $r): $ok = in_array($r['status'], ['new', 'change'], true); ?>
									<tr data-status="<?= h($r['status']) ?>">
										<td><?php if ($ok): ?><input class="form-check-input trlAiPick" type="checkbox" name="pick[]" value="<?= (int)$r['i'] ?>" checked aria-label="<?= h($r['en']) ?>"><?php endif; ?></td>
										<td class="small"><?= h($r['lang']) ?></td>
										<td class="small text-break"><?= h($r['en']) ?></td>
										<td class="small text-break"><?= h($r['tr']) ?></td>
										<td class="small text-break text-body-secondary"><?= h((string)$r['current']) ?></td>
										<td class="small"><span class="badge <?= ['new' => 'text-bg-success', 'change' => 'text-bg-primary', 'same' => 'text-bg-light border', 'skip' => 'text-bg-secondary'][$r['status']] ?>"><?= h(t('trl_ai_st_' . $r['status'])) ?></span><?php if ($r['note'] !== ''): ?><div class="<?= $r['status'] === 'skip' ? 'text-body-secondary' : 'text-warning-emphasis' ?>"><?= h($r['note']) ?></div><?php endif; ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table></div>
							<button class="btn btn-success nuBtnSave" type="submit" id="trlAiSave"><i class="bi bi-floppy me-1"></i><?= h(t('trl_ai_save')) ?></button>
						</form>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>
			<div class="card shadow-sm mb-3">
				<div class="card-header small text-body-secondary" id="trlMatrixHead"><?= h(t('trl_matrix_count', count($mx['rows']), $gaps ? implode(', ', array_map(static fn($l, $n) => $l . ' ' . $n, array_keys($gaps), $gaps)) : '0')) ?></div>
				<div class="table-responsive">
					<table class="table align-middle mb-0 nu-table" id="trlMatrix">
						<thead><tr><th><?= h(t('trl_english')) ?></th><th><?= h(t('trl_used_in')) ?></th><?php foreach ($targets as $l): ?><th data-lang="<?= h($l) ?>"><?= h($l) ?></th><?php endforeach; ?></tr></thead>
						<tbody>
						<?php if (!$mx['rows']): ?><tr><td colspan="<?= 2 + count($targets) ?>" class="text-center text-body-secondary py-5"><?= h(t('trl_matrix_none')) ?></td></tr><?php endif; ?>
						<?php foreach ($mx['rows'] as $text => $r): ?>
							<tr data-text="<?= h($text) ?>">
								<td class="text-break"><?= h($text) ?><div><?php foreach ($r['kinds'] as $k): ?><span class="badge text-bg-light border me-1 fw-normal"><?= h($kindT($k)) ?></span><?php endforeach; ?></div></td>
								<td class="small text-body-secondary"><?= h(implode(', ', $r['where'])) ?></td>
								<?php foreach ($targets as $l): $v = $r['tr'][$l]; ?>
									<td class="small<?= $v === null ? ' trlGap' : '' ?>" data-lang="<?= h($l) ?>"><?php if ($v !== null): ?><?= h(mb_strimwidth($v, 0, 80, '…')) ?><?php elseif ($rights['create']): ?><a class="btn btn-sm btn-outline-warning py-0 px-1 trlGapBtn" href="<?= h(nu_url('translations', ['id' => 'new', 'lang' => $l, 'en' => $text, 'back' => 'missing', 'f' => $aiForm])) ?>" title="<?= h(t('trl_translate') . ' · ' . $l) ?>" aria-label="<?= h(t('trl_translate') . ' · ' . $l) ?>"><i class="bi bi-plus-lg"></i></a><?php else: ?><span class="badge text-bg-warning"><?= h(t('trl_missing_cell')) ?></span><?php endif; ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>
		<?php elseif ($lang === ''): ?>
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
	$backF = (string)($_GET['f'] ?? nu_post('back_f'));
	$override = $isNu ? $db->value("SELECT zzzzsys_translate_id FROM $T WHERE trl_language = ? AND trl_english = ? AND zzzzsys_translate_id NOT LIKE 'nu%'", [(string)$row['trl_language'], (string)$row['trl_english']]) : null;
	?>
	<form method="post" data-nu-focus-first data-nu-guard id="trlForm" novalidate>
		<?= nu_csrf_field() ?>
		<input type="hidden" name="action" value="save"><input type="hidden" name="back" value="<?= h($back) ?>"><input type="hidden" name="back_f" value="<?= h($backF) ?>">
		<div class="nuTitleBar mb-3">
			<h1 class="h4 mb-0"><?= h($row ? t('trl_edit') : t('trl_new')) ?>
				<?php if ($isNu): ?><span class="badge bg-info-subtle text-info-emphasis fs-6 align-middle">nuBuilder Forte</span><?php endif; ?></h1>
			<div class="d-flex flex-wrap gap-2 align-items-center">   <!-- buttons in their own row (0.8.474) -->
			<a class="btn nuBtnAct" href="<?= h(($back === 'missing' ? nu_url('translations', ['view' => 'missing', 'lang' => $backF !== '' ? null : $curLang, 'f' => $backF ?: null]) : nu_url('translations'))) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
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
			<?php if ($row): ?><?= NuFormInfo::record($T, (string)$id) /* (i) record info (0.8.501) */ ?><?php endif; ?>
			</div>
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
nu_layout_end($id !== '' ? '<script src="' . nu_asset('assets/js/nuitems.js') . '"></script>' : ($view === 'missing' ? nu_ts_foot() . '<script src="' . nu_asset('assets/js/nutranslations.js') . '"></script>' : ''));
