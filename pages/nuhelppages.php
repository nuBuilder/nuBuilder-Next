<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * User help pages – list and editor (0.8.1023, core/nuuserhelp.php). Admin right "userhelp": view = list + read, change = write.
 *   ?p=helppages                       list (NuListDefs 'helppages'), Export / Import
 *   ?p=helppages&id=new[&parent=<code>] new page
 *   ?p=helppages&id=<id>               a page or a translation
 *   ?p=helppages&id=new&tr=<code>&lang=<l>  new translation of a page
 *   ?p=helppages&export=1              all pages as JSON (nunext-help-1)
 * POST actions: save, delete, import, ai_prompt (JSON: the prompt for an AI chat, nothing stored), md_html (JSON: Markdown → HTML).
 */
NuAdmin::require('userhelp');
NuSchema::ensure();

$db = nu_db();
$id = (string)($_GET['id'] ?? '');
$canEdit = NuUserHelp::canEdit();

if (($_GET['export'] ?? '') === '1') {
	session_write_close();
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="nunext-help-' . date('Ymd') . '.json"');
	echo NuUserHelp::export();
	NuLog::write('userhelp_exported', NuUserHelp::T, '', ['pages' => NuUserHelp::count()]);
	exit;
}

$row = $id !== '' && $id !== 'new' ? NuUserHelp::get($id) : null;
if ($id !== '' && $id !== 'new' && !$row) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('helppages')); }
$trOf = $id === 'new' ? trim((string)($_GET['tr'] ?? '')) : '';
$base = $row && (string)$row['lang'] !== '' ? NuUserHelp::row((string)$row['code'], '') : ($trOf !== '' ? NuUserHelp::row($trOf, '') : null);
if ($trOf !== '' && !$base) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('helppages')); }
$isTr = $base !== null;
$lang = $row ? (string)$row['lang'] : ($isTr ? (string)($_GET['lang'] ?? '') : '');
$errors = [];

// ------------------------------------------------------------------ actions
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'related') {                               // JSON (0.8.1025): sub-forms, lookups and buttons of a form for the AI prompt
		header('Content-Type: application/json; charset=utf-8');
		$kinds = ['subform' => t('uh_rel_subform'), 'lookup' => t('uh_rel_lookup'), 'button' => t('uh_rel_button')];
		echo json_encode(['ok' => true, 'related' => array_map(static fn($r) => $r + ['kind_text' => $kinds[$r['kind']]], NuUserHelp::related((string)($_POST['form'] ?? '')))]);
		exit;
	}
	if ($action === 'md_html') {                               // JSON (0.8.1038): an AI answer in Markdown → HTML for the visual editor
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['ok' => true, 'html' => NuHtmlClean::doc(NuMarkdown::toWeb(mb_substr((string)($_POST['text'] ?? ''), 0, 200000)))]);
		exit;
	}
	if ($action === 'ai_prompt') {                             // JSON: nothing stored
		header('Content-Type: application/json; charset=utf-8');
		$l = (string)($_POST['lang'] ?? '');
		if (($_POST['mode'] ?? '') === 'translate') {
			$src = NuUserHelp::row((string)($_POST['code'] ?? ''), '');
			$prompt = $src ? "Translate this help page into " . (NU_LANGS[$l][1] ?? $l) . ".\n\nRules:\n- Keep the HTML tags, the markers in double square brackets (like [[level: …]], [[else]], [[/level]]) and the links starting with #help= exactly as they are.\n- Keep words between # signs (like #USER_NAME#) unchanged.\n- Answer with the translated title on the first line, then an empty line, then the translated HTML only.\n\nTitle: "
				. (string)$src['title'] . "\n\n" . (string)$src['body'] : '';
		} else {
			$prompt = NuUserHelp::aiPrompt((string)($_POST['form'] ?? ''), $l !== '' ? $l : nu_lang(), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000),
				array_map('strval', (array)($_POST['related'] ?? [])));
		}
		echo json_encode(['ok' => $prompt !== '', 'prompt' => $prompt]);
		exit;
	}
	if (!$canEdit) { nu_flash('danger', t('uh_err_rights')); nu_redirect(nu_url('helppages')); }
	if ($action === 'upload_image') {                          // a picture of the editor (0.8.1024): stored as a file, JSON {location}
		header('Content-Type: application/json; charset=utf-8');
		$f = $_FILES['file'] ?? null;
		$url = is_array($f) && (int)($f['error'] ?? 1) === 0 && (int)$f['size'] <= NuUserHelp::IMG_MAX ? NuUserHelp::storeImage((string)file_get_contents((string)$f['tmp_name'])) : null;
		if ($url === null) { http_response_code(400); echo json_encode(['error' => t('uh_err_image')]); exit; }
		NuLog::write('userhelp_image', NuUserHelp::T, '', ['file' => basename($url)]);
		echo json_encode(['location' => $url]);
		exit;
	}
	if ($action === 'import') {
		$f = $_FILES['file'] ?? null;
		$json = is_array($f) && (int)($f['error'] ?? 1) === 0 && (int)$f['size'] <= 30 * 1024 * 1024 ? (string)file_get_contents((string)$f['tmp_name']) : '';
		$r = NuUserHelp::import($json, nu_check('replace') === '1');
		nu_flash($r['errors'] ? 'warning' : 'success', t('uh_import_done', $r['added'], $r['replaced'], $r['skipped'])
			. ($r['unknown'] ? ' ' . t('uh_import_unknown', implode(', ', $r['unknown'])) : '') . ($r['errors'] ? ' ' . implode(' · ', array_slice($r['errors'], 0, 5)) : ''));
		nu_redirect(nu_url('helppages'));
	}
	if ($action === 'save') {
		$in = ['code' => nu_post('code'), 'lang' => $isTr ? (string)($_POST['lang'] ?? '') : '', 'title' => nu_post('title'), 'body' => (string)($_POST['body'] ?? ''),
			'format' => nu_post('format'), 'parent' => nu_post('parent'), 'sort_order' => nu_post('sort_order'), 'access' => (array)($_POST['access'] ?? []),
			'keywords' => nu_post('keywords'), 'active' => nu_check('active') === '1'];
		if ($isTr && !$row) $in['code'] = (string)$base['code'];
		[$newId, $errors] = NuUserHelp::save($in, $row ? $id : '');
		if (!$errors) {
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('helppages', ['id' => $newId]));
		}
		nu_flash('danger', t('err_check_form'));
	}
	if ($row && $action === 'delete') {
		NuUserHelp::delete($id);
		nu_flash('success', t('uh_deleted', (string)$row['title'] ?: (string)$row['code']));
		nu_redirect($isTr && $base ? nu_url('helppages', ['id' => (string)$base['id']]) : nu_url('helppages'));
	}
}

// ------------------------------------------------------------------ list
if ($id === '') {
	$st = NuListView::prepare('helppages');
	nu_layout_start(t('uh_admin_title'), 'home', ['head' => NuListView::head() . nu_ts_head()]);
	echo NuListView::crumbs([[t('uh_admin_title')]]);
	$btns = '<a class="btn nuBtnAct" href="' . h(nu_url('userhelp')) . '" id="uhRead"><i class="bi bi-book me-1"></i>' . h(t('uh_read')) . '</a>'
		. '<div class="dropdown"><button class="btn nuBtnAct dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="uhMore"><i class="bi bi-arrow-down-up me-1"></i>' . h(t('uh_exchange')) . '</button>'
		. '<ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="' . h(nu_url('helppages', ['export' => '1'])) . '" id="uhExport"><i class="bi bi-download me-2"></i>' . h(t('uh_export')) . '</a></li>'
		. ($canEdit ? '<li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#uhImportDlg" id="uhImportBtn"><i class="bi bi-upload me-2"></i>' . h(t('uh_import')) . '</button></li>' : '') . '</ul></div>'
		. ($canEdit ? '<a class="btn nuAddBtn" href="' . h(nu_url('helppages', ['id' => 'new'])) . '" id="uhNew"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '');
	echo NuListView::card($st, ['title_extra' => NuListView::infoTip(t('uh_admin_tip')), 'buttons' => $btns]);
	if ($canEdit): ?>
	<div class="modal fade" id="uhImportDlg" tabindex="-1" aria-labelledby="uhImportTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post" enctype="multipart/form-data" action="<?= h(nu_url('helppages')) ?>" id="uhImportForm">
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="import">
		<div class="modal-header"><h2 class="modal-title h5" id="uhImportTitle"><?= h(t('uh_import')) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
		<div class="modal-body"><p class="small text-body-secondary"><?= h(t('uh_import_tip')) ?></p>
			<input class="form-control mb-3" type="file" name="file" accept=".json,application/json" required id="uhImportFile">
			<div class="form-check"><input class="form-check-input" type="checkbox" name="replace" value="1" id="uhImportReplace"><label class="form-check-label" for="uhImportReplace"><?= h(t('uh_import_replace')) ?></label></div></div>
		<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button><button type="submit" class="btn btn-primary" id="uhImportGo"><?= h(t('uh_import')) ?></button></div>
	</form></div></div>
	<?php endif;
	nu_layout_end(NuListView::foot() . nu_ts_foot());
	return;
}

// ------------------------------------------------------------------ editor
$posted = nu_is_post() && $errors;
$v = static function (string $k, string $def = '') use ($posted, $row): string {
	if ($posted) return (string)($_POST[$k] ?? $def);
	return $row ? (string)($row[$k] ?? $def) : $def;
};
$format = $v('format', 'html') === 'md' ? 'md' : 'html';
$access = $posted ? array_map('strval', (array)($_POST['access'] ?? [])) : ($row && !$isTr ? NuUserHelp::accessOf($row) : []);
$active = $posted ? nu_check('active') === '1' : ($row ? (string)$row['active'] === '1' : true);
$parentDef = $row ? (string)$row['parent'] : (string)($_GET['parent'] ?? '');
$code = $isTr ? (string)$base['code'] : $v('code', (string)($_GET['code'] ?? ''));
$title = $row ? ((string)$row['title'] ?: (string)$row['code']) : ($isTr ? t('uh_new_tr', NU_LANGS[$lang][0] ?? $lang) : t('uh_new'));
$err = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$tip = static fn(string $text) => NuListView::infoTip($text);
$levels = $db->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code');
$bases = NuUserHelp::bases();
$usage = NuUserHelp::usage()[$code] ?? [];
$langs = array_values(array_filter(NuLanguages::installed(), static fn($l) => $l !== ''));
$trs = !$isTr && $row ? NuUserHelp::langsOf($code) : [];
$tinyDir = $canEdit ? NuLibs::tinymce() : '';
$tiny = $tinyDir !== '';
if ($tiny) header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$linkList = [];
foreach (NuUserHelp::codeList() as [$c, $tt]) if ($c !== $code) $linkList[] = ['title' => $tt . ' (' . $c . ')', 'value' => '#help=' . $c];
$formOpts = array_filter(nu_forms(), static fn($f, $fid) => !str_starts_with((string)$fid, 'nu') && in_array($f['type'], ['browseedit', 'edit', 'browse', 'launch'], true), ARRAY_FILTER_USE_BOTH);

nu_layout_start(t('uh_admin_title'), 'home', ['container' => 'container-xl', 'head' => nu_ts_head()]);
$crumbs = [[t('uh_admin_title'), nu_url('helppages')]];
if ($isTr) $crumbs[] = [(string)$base['title'], nu_url('helppages', ['id' => (string)$base['id']])];
$crumbs[] = [$title];
echo NuListView::crumbs($crumbs); ?>
<div class="nuTitleBar nuRecHead mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-journal-bookmark me-2"></i><?= h($title) ?><?php if ($isTr): ?> <span class="badge text-bg-info ms-1"><?= h(NU_LANGS[$lang][0] ?? $lang) ?></span><?php endif; ?></h1>
	<div class="d-flex flex-wrap gap-2">
		<a class="btn nuBtnAct" href="<?= h($isTr ? nu_url('helppages', ['id' => (string)$base['id']]) : nu_url('helppages')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<?php if ($canEdit): ?><button class="btn nuBtnAct nuBtnSave" type="submit" form="uhForm" data-nu-save id="uhSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<?php if ($row): ?><a class="btn nuBtnAct" href="<?= h(nu_url('userhelp', ['c' => $code])) ?>" target="_blank" rel="noopener" id="uhView"><i class="bi bi-eye me-1"></i><?= h(t('uh_view')) ?></a><?php endif; ?>
		<?php if ($row && !$isTr && $canEdit && $langs): ?>
			<div class="dropdown"><button class="btn nuBtnAct dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="uhTrBtn"><i class="bi bi-translate me-1"></i><?= h(t('uh_translations')) ?><?= $trs ? ' <span class="badge text-bg-secondary">' . count($trs) . '</span>' : '' ?></button>
				<ul class="dropdown-menu dropdown-menu-end" id="uhTrMenu">
					<?php foreach ($langs as $l): $tr = in_array($l, $trs, true) ? NuUserHelp::row($code, $l) : null; ?>
						<li><a class="dropdown-item d-flex justify-content-between gap-3" data-lang="<?= h($l) ?>" href="<?= h($tr ? nu_url('helppages', ['id' => (string)$tr['id']]) : nu_url('helppages', ['id' => 'new', 'tr' => $code, 'lang' => $l])) ?>">
							<span><?= h(NU_LANGS[$l][0] ?? $l) ?></span><span class="text-body-secondary small"><?= h($tr ? t('edit') : t('uh_tr_add')) ?></span></a></li>
					<?php endforeach; ?>
				</ul></div>
		<?php endif; ?>
		<?php if ($row && $canEdit): ?>
			<form method="post" action="<?= h(nu_url('helppages', ['id' => $id])) ?>" data-confirm="<?= h(t($isTr ? 'uh_delete_tr_confirm' : 'uh_delete_confirm', $title)) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
				<button class="btn nuBtnAct nuBtnDel" type="submit" id="uhDelete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button></form>
		<?php endif; ?>
	</div>
</div>
<form method="post" action="<?= h(nu_url('helppages', ['id' => $row ? $id : 'new'] + ($isTr && !$row ? ['tr' => $code, 'lang' => $lang] : []))) ?>" id="uhForm" data-nu-guard novalidate
	data-tiny="<?= $tiny ? h(nu_asset($tinyDir . '/tinymce.min.js')) : '' ?>" data-base="<?= h($tinyDir) ?>" data-links="<?= h((string)json_encode($linkList)) ?>"
	data-ai-url="<?= h(nu_url('helppages')) ?>" data-readonly="<?= $canEdit ? '0' : '1' ?>">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($isTr): ?><input type="hidden" name="lang" value="<?= h($lang) ?>"><?php endif; ?>
	<fieldset<?= $canEdit ? '' : ' disabled' ?>>
	<div class="row g-3">
		<div class="col-lg-8">
			<div class="card shadow-sm"><div class="card-body">
				<div class="mb-3"><label class="form-label" for="uhTitle"><?= h(t('uh_f_title')) ?><?= $isTr ? '' : ' <span class="text-danger">*</span>' ?></label>
					<input class="form-control<?= $inv('title') ?>" id="uhTitle" name="title" maxlength="200" value="<?= h($v('title')) ?>"<?= $isTr ? ' placeholder="' . h((string)$base['title']) . '"' : ' required' ?>><?= $err('title') ?></div>
				<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
					<label class="form-label mb-0 me-auto" for="uhBody"><?= h(t('uh_f_body')) ?></label>
					<div class="btn-group nuSeg" role="group" aria-label="<?= h(t('uh_f_format')) ?>">
						<input type="radio" class="btn-check" name="format" id="uhFmtHtml" value="html"<?= $format === 'html' ? ' checked' : '' ?>><label class="btn btn-outline-secondary btn-sm" for="uhFmtHtml"><?= h(t('uh_fmt_html')) ?></label>
						<input type="radio" class="btn-check" name="format" id="uhFmtMd" value="md"<?= $format === 'md' ? ' checked' : '' ?>><label class="btn btn-outline-secondary btn-sm" for="uhFmtMd"><?= h(t('uh_fmt_md')) ?></label>
					</div>
					<div class="dropdown"><button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="uhInsert"><i class="bi bi-plus-square me-1"></i><?= h(t('uh_insert')) ?></button>
						<ul class="dropdown-menu dropdown-menu-end">
							<li><button class="dropdown-item" type="button" data-uh-insert="level"><i class="bi bi-person-check me-2"></i><?= h(t('uh_ins_level')) ?></button></li>
							<li><button class="dropdown-item" type="button" data-uh-insert="levelelse"><i class="bi bi-signpost-split me-2"></i><?= h(t('uh_ins_levelelse')) ?></button></li>
							<li><button class="dropdown-item" type="button" data-uh-insert="not"><i class="bi bi-person-x me-2"></i><?= h(t('uh_ins_not')) ?></button></li>
							<li><hr class="dropdown-divider"></li>
							<li><button class="dropdown-item" type="button" data-uh-insert="link"><i class="bi bi-link-45deg me-2"></i><?= h(t('uh_ins_link')) ?></button></li>
							<li><button class="dropdown-item" type="button" data-uh-insert="hash"><i class="bi bi-hash me-2"></i><?= h(t('uh_ins_hash')) ?></button></li>
						</ul></div>
					<button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#uhAiDlg" id="uhAiBtn"><i class="bi bi-stars me-1"></i><?= h(t($isTr ? 'uh_ai_tr' : 'uh_ai')) ?></button>
					<?= $tip(t('uh_body_tip')) ?>
				</div>
				<textarea class="form-control font-monospace<?= $inv('body') ?><?= $tiny && $format === 'html' ? ' nuTmceWait' : '' ?>" id="uhBody" name="body" rows="22" spellcheck="true"><?= h($v('body')) ?></textarea><?= $err('body') ?>
				<?php if ($canEdit && !$tiny): ?><div class="form-text" id="uhNoTiny"><?= h(t('uh_no_tiny')) ?> <?= nu_lp_hint('lp_get_pack') ?></div><?php endif; ?>
				<?php if ($isTr): ?><details class="mt-3" id="uhOriginal"><summary class="small"><?= h(t('uh_original')) ?></summary>
					<div class="nuUhArticle border rounded p-3 mt-2"><?= NuUserHelp::render((string)$base['body'], (string)$base['format']) ?></div></details><?php endif; ?>
			</div></div>
		</div>
		<div class="col-lg-4">
			<div class="card shadow-sm"><div class="card-body">
				<div class="mb-3"><label class="form-label" for="uhCode"><?= h(t('uh_f_code')) ?> <span class="text-danger">*</span></label><?= $tip(t('uh_code_tip')) ?>
					<input class="form-control font-monospace<?= $inv('code') ?>" id="uhCode" name="code" maxlength="60" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]*" value="<?= h($code) ?>"<?= $isTr ? ' readonly' : ' required' ?>><?= $err('code') ?><?= $err('lang') ?></div>
				<?php if (!$isTr): ?>
				<div class="row g-2 mb-3">
					<div class="col-8"><label class="form-label" for="uhParent"><?= h(t('uh_f_parent')) ?></label>
						<select class="form-select<?= $inv('parent') ?>" id="uhParent" name="parent"><option value=""><?= h(t('uh_parent_none')) ?></option>
							<?php foreach ($bases as $bc => $b): if ($row && (string)$bc === $code) continue; ?><option value="<?= h((string)$bc) ?>"<?= ($posted ? (string)($_POST['parent'] ?? '') : $parentDef) === (string)$bc ? ' selected' : '' ?>><?= h((string)$b['title']) ?> (<?= h((string)$bc) ?>)</option><?php endforeach; ?>
						</select><?= $err('parent') ?></div>
					<div class="col-4"><label class="form-label" for="uhOrder"><?= h(t('uh_f_order')) ?></label>
						<input class="form-control" type="number" id="uhOrder" name="sort_order" min="-9999" max="99999" step="1" value="<?= h($v('sort_order', '0')) ?>"></div>
				</div>
				<div class="mb-3"><label class="form-label" for="uhAccess"><?= h(t('uh_f_access')) ?></label><?= $tip(t('uh_access_tip')) ?>
					<select class="form-select nuListTomSelect" id="uhAccess" name="access[]" multiple data-placeholder="<?= h(t('uh_access_all')) ?>">
						<?php foreach ($levels as $a): ?><option value="<?= h((string)$a['zzzzsys_access_id']) ?>" data-sub="<?= h((string)$a['sal_description']) ?>"<?= in_array((string)$a['zzzzsys_access_id'], $access, true) ? ' selected' : '' ?>><?= h((string)$a['sal_code']) ?></option><?php endforeach; ?>
					</select></div>
				<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" id="uhActive" name="active" value="1"<?= $active ? ' checked' : '' ?>>
					<label class="form-check-label" for="uhActive"><?= h(t('uh_active')) ?></label><?= $tip(t('uh_active_tip')) ?></div>
				<?php endif; ?>
				<div class="mb-3"><label class="form-label" for="uhKeywords"><?= h(t('uh_f_keywords')) ?></label><?= $tip(t('uh_keywords_tip')) ?>
					<input class="form-control" id="uhKeywords" name="keywords" maxlength="500" value="<?= h($v('keywords')) ?>"></div>
				<?php if (!$isTr): ?>
				<div class="mb-1 fw-semibold small"><?= h(t('uh_used')) ?><?= $tip(t('uh_used_tip')) ?></div>
				<?php if ($usage): ?><ul class="list-unstyled small mb-0" id="uhUsage">
					<?php foreach ($usage as [$fid, $fname, $place]): ?><li><a href="<?= h(nu_url('form', ['f' => $fid, 'design' => '1'])) ?>" target="_blank" rel="noopener"><?= h($fname) ?></a> <span class="text-body-secondary">– <?= h($place) ?></span></li><?php endforeach; ?>
				</ul><?php else: ?><div class="small text-body-secondary" id="uhUsage"><?= h(t('uh_used_none')) ?></div><?php endif; ?>
				<?php endif; ?>
				<?php if ($row): ?><div class="small text-body-secondary mt-3"><?= h(t('fi_changed')) ?>: <?= h(date(nu_dfmt('d.m.Y H:i'), (int)strtotime((string)$row['updated_at']))) ?><?= (string)$row['updated_by'] !== '' ? ' · ' . h((string)$row['updated_by']) : '' ?></div><?php endif; ?>
			</div></div>
		</div>
	</div>
	</fieldset>
</form>

<div class="modal fade" id="uhAiDlg" tabindex="-1" aria-labelledby="uhAiTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
	<div class="modal-header"><h2 class="modal-title h5" id="uhAiTitle"><i class="bi bi-stars me-1"></i><?= h(t($isTr ? 'uh_ai_tr' : 'uh_ai')) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
	<div class="modal-body" id="uhAiBody" data-mode="<?= $isTr ? 'translate' : 'draft' ?>" data-code="<?= h($code) ?>" data-lang="<?= h($isTr ? $lang : nu_lang()) ?>">
		<p class="small text-body-secondary"><?= h(t($isTr ? 'uh_ai_tr_tip' : 'uh_ai_tip')) ?></p>
		<?php if (!$isTr): ?>
		<div class="row g-2 mb-2">
			<div class="col-md-5"><label class="form-label" for="uhAiForm"><?= h(t('uh_ai_form')) ?></label>
				<select class="form-select nuListTomSelect" id="uhAiForm"><option value=""></option>
					<?php foreach ($formOpts as $fid => $f): ?><option value="<?= h((string)$fid) ?>" data-sub="<?= h((string)$f['code']) ?>"<?= isset($usage[0]) && $usage[0][0] === (string)$fid ? ' selected' : '' ?>><?= h((string)$f['description'] ?: (string)$f['code']) ?></option><?php endforeach; ?>
				</select></div>
			<div class="col-md-3"><label class="form-label" for="uhAiLang"><?= h(t('uh_ai_lang')) ?></label><?= NuListView::infoTip(t('uh_ai_lang_tip')) ?>
				<select class="form-select" id="uhAiLang" data-default="<?= h(nu_lang()) ?>">
					<?php foreach (NuLanguages::installed() as $lc): ?><option value="<?= h($lc) ?>"<?= $lc === nu_lang() ? ' selected' : '' ?>><?= h((string)(NU_LANGS[$lc][0] ?? $lc)) ?></option><?php endforeach; ?>
				</select></div>
			<div class="col-md-4"><label class="form-label" for="uhAiNote"><?= h(t('uh_ai_note')) ?></label><input class="form-control" id="uhAiNote" maxlength="1000"></div>
		</div>
		<fieldset class="border rounded p-2 mb-3 d-none" id="uhAiRelBox"><legend class="float-none w-auto px-1 mb-0 small fw-semibold"><?= h(t('uh_rel_title')) ?></legend>
			<div class="small text-body-secondary mb-1"><?= h(t('uh_rel_tip')) ?></div><div id="uhAiRel"></div></fieldset>
		<?php endif; ?>
		<button class="btn btn-outline-primary btn-sm mb-3" type="button" id="uhAiCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('uh_ai_copy')) ?></button> <span class="small text-success d-none" id="uhAiCopied"><?= h(t('copy_done')) ?></span>
		<textarea class="form-control font-monospace small d-none mb-3" id="uhAiPrompt" rows="6" readonly></textarea>
		<label class="form-label d-block" for="uhAiAnswer"><?= h(t('uh_ai_answer')) ?></label>
		<textarea class="form-control font-monospace small" id="uhAiAnswer" rows="8"></textarea>
	</div>
	<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button><button type="button" class="btn btn-primary" id="uhAiInsert"><?= h(t('uh_ai_insert')) ?></button></div>
</div></div></div>

<div id="uhTexts" hidden data-hash="<?= h(t('uh_ins_hash_ph')) ?>" data-pick="<?= h(t('uh_ins_link_pick')) ?>" data-level-ph="<?= h(t('uh_ins_level_ph')) ?>"
	data-only="<?= h(t('uh_ins_only_txt')) ?>" data-other="<?= h(t('uh_ins_other_txt')) ?>" data-copy-failed="<?= h(t('copy_failed')) ?>" data-replace="<?= h(t('uh_ai_replace')) ?>"
	data-levels="<?= h(implode(', ', array_map(static fn($a) => (string)$a['sal_code'], $levels))) ?>"></div>
<?php nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nuhelppages.js') . '"></script>');
