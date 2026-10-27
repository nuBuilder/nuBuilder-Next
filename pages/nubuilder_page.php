<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Form builder of a page form (form type "page", 0.8.387): form fields (description, code, group, status) and the page settings
 * (open mode, iframe height, settings JSON, CSP, notes), folder status, Create from template, Copy AI prompt, Delete.
 * Included by pages/nubuilder.php ($meta = the live form, $canSave, $canDeleteForm). Not a route of its own.
 */

/** @var NuFormMeta $meta */
$formId = $meta->id;
$errors = [];

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'prompt') {                       // "Copy AI prompt": the text as JSON
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['ok' => true, 'text' => NuPage::aiPrompt($meta)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}
	if (!$canSave) NuAcl::require(NuAcl::FORM_FORM, 'save');
	if ($action === 'template') {
		$r = NuPage::createFromTemplate($meta->code());
		nu_flash($r['ok'] ? 'success' : 'warning', $r['message']);
		nu_redirect(nu_url('builder', ['f' => $formId]));
	}
	// draft and versions of the page files (0.8.599, NuPageVersion)
	$verAct = ['draft_create' => 'createDraft', 'draft_publish' => 'publish', 'draft_discard' => 'discard'];
	if (isset($verAct[$action]) || $action === 'version_restore') {
		$r = $action === 'version_restore' ? NuPageVersion::restore($meta->code(), nu_post('version')) : NuPageVersion::{$verAct[$action]}($meta->code());
		nu_flash($r['ok'] ? 'success' : 'warning', $r['message']);
		nu_redirect(nu_url('builder', ['f' => $formId]) . '#pgDraft');
	}
	if ($action === 'delete') {
		if (!$canDeleteForm) { http_response_code(403); nu_fatal(t('builder_delete_globeadmin'), '', true); }
		NuPage::deleteForm($meta);
		nu_flash('success', t('page_deleted', $meta->code()));
		nu_redirect(nu_url('forms'));
	}
	if ($action === 'save') {
		$db = nu_db();
		$desc = nu_post('description');
		$code = nu_post('code');
		$status = in_array(nu_post('status'), ['0', '1', '3'], true) ? nu_post('status') : '1';
		$group = nu_post('group');
		if ($desc === '') $errors['description'] = t('err_required');
		nu_maxlen($errors, 'description', $desc, 300);
		if (!NuPage::validCode($code)) $errors['code'] = t('page_err_code', $code);
		elseif ($code !== $meta->code() && (int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ? AND zzzzsys_form_id <> ?', [$code, $formId])) $errors['code'] = t('builder_err_code_taken');
		nu_maxlen($errors, 'group', $group, 100);
		$pageIn = ['open_mode' => nu_post('open_mode'), 'iframe_height' => nu_post('iframe_height'), 'settings' => (string)($_POST['settings'] ?? ''),
			'csp' => nu_post('csp'), 'note' => (string)($_POST['note'] ?? '')];
		if (!$errors) $errors = NuPage::save($formId, $pageIn);
		if (!$errors) {
			$row = ['sfo_description' => $desc, 'sfo_code' => $code, 'sfo_status' => $status, 'sfo_group' => $group !== '' ? $group : null,
				'sfo_breadcrumb_title' => '|' . $desc];
			$old = array_intersect_key($meta->form, $row);
			$db->update('zzzzsys_form', $row, 'zzzzsys_form_id', $formId);
			NuFormMeta::forget($formId);
			NuLog::write('form_saved', $code, $formId, NuLog::diff($old, $row));
			nu_flash('success', t('saved'), true);
			if ($code !== $meta->code()) nu_flash('warning', t('page_code_renamed', NuPage::shownFolder($meta->code()), NuPage::shownFolder($code)));
			nu_redirect(nu_url('builder', ['f' => $formId]));
		}
	}
}

$s = NuPage::settings($formId);
$keys = NuPage::settingKeys($meta->code());          // keys the page reads (0.8.391)
$example = NuPage::settingsExample($meta->code());   // settings.example.json of the page folder
$fromKeys = NuPage::settingsFromKeys($keys['keys']); // a settings JSON from the keys the page reads (0.8.394)
$v = static fn(string $k, string $def) => array_key_exists($k, $_POST) && nu_is_post() ? (string)$_POST[$k] : $def;
$info = NuPage::fileInfo($meta->code());
$ro = !$canSave;
$openUrl = nu_url('form', ['f' => $meta->code()]);
$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';

nu_layout_start($meta->description() . ' · ' . t('builder_title'), 'forms', ['head' => '<link rel="stylesheet" href="' . nu_asset('assets/css/nubuilder.css') . '">']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h($meta->description()) ?></li></ol></nav>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0 d-flex align-items-center gap-2"><i class="bi bi-filetype-php"></i><span><?= h($meta->description()) ?></span>
		<span class="badge nuFormType nuFormType-page fs-6 fw-normal"><?= h(t('form_type_page')) ?></span></h1>
	<div class="d-flex flex-wrap gap-2">
		<?= nu_back_button(null, nu_url('forms')) ?>
		<?php if (!$ro): ?><button class="btn nuBtnAct nuBtnSave" type="submit" form="pgForm" data-nu-save id="pgSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
		<a class="btn nuBtnAct pgNeedsSaved" href="<?= h($openUrl) ?>" target="_blank" rel="noopener" id="pgOpen"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('page_open')) ?></a>
		<button class="btn nuBtnAct pgNeedsSaved" type="button" id="pgPrompt" data-done="<?= h(t('page_prompt_copied')) ?>" data-fail="<?= h(t('page_prompt_failed')) ?>"><i class="bi bi-robot me-1"></i><?= h(t('page_prompt')) ?></button>
		<?php if ($canDeleteForm): ?>
			<form method="post" class="d-inline" data-confirm="<?= h(t('page_delete_confirm', NuPage::shownFolder($meta->code()))) ?>" data-confirm-ok="<?= h(t('builder_delete_form')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
				<button class="btn nuBtnAct nuBtnDel" type="submit"><i class="bi bi-trash me-1"></i><?= h(t('builder_delete_form')) ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
<div class="small text-warning-emphasis mb-2 d-none" id="pgSaveFirst" role="status"><i class="bi bi-info-circle me-1"></i><?= h(t('page_save_first')) ?></div>

<div class="row g-3">
	<div class="col-xl-7">
		<form method="post" class="card shadow-sm" data-nu-guard id="pgForm">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
			<div class="card-header fw-semibold"><i class="bi bi-sliders me-1"></i><?= h(t('page_sec_settings')) ?></div>
			<div class="card-body">
				<div class="row g-3">
					<div class="col-md-7">
						<label class="form-label" for="pg_desc"><?= h(t('builder_description')) ?> <span class="text-danger">*</span></label>
						<input class="form-control<?= nu_invalid($errors, 'description') ?>" id="pg_desc" name="description" maxlength="300" required value="<?= h($v('description', $meta->description())) ?>"<?= $ro ? ' readonly' : '' ?>>
						<?= nu_feedback($errors, 'description') ?>
					</div>
					<div class="col-md-5">
						<label class="form-label" for="pg_code"><?= h(t('builder_code')) ?> <span class="text-danger">*</span></label><?= $tip(t('page_code_tip')) ?>
						<input class="form-control font-monospace<?= nu_invalid($errors, 'code') ?>" id="pg_code" name="code" maxlength="64" required spellcheck="false" autocomplete="off"
							pattern="[A-Za-z0-9][A-Za-z0-9_\-]{0,63}" value="<?= h($v('code', $meta->code())) ?>" data-old="<?= h($meta->code()) ?>" data-warn="<?= h(t('page_code_rename_hint')) ?>"<?= $ro ? ' readonly' : '' ?>>
						<?= nu_feedback($errors, 'code') ?>
						<div class="form-text text-warning-emphasis d-none" id="pgCodeWarn"></div>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="pg_group"><?= h(t('builder_group')) ?></label>
						<input class="form-control<?= nu_invalid($errors, 'group') ?>" id="pg_group" name="group" maxlength="100" value="<?= h($v('group', (string)($meta->form['sfo_group'] ?? ''))) ?>"<?= $ro ? ' readonly' : '' ?>>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="pg_status"><?= h(t('builder_status')) ?></label>
						<select class="form-select" id="pg_status" name="status"<?= $ro ? ' disabled' : '' ?>>
							<?php $st = $v('status', (string)($meta->form['sfo_status'] ?? '') ?: '1'); foreach (['1', '0', '3'] as $o): ?>
								<option value="<?= $o ?>"<?= $st === $o ? ' selected' : '' ?>><?= h(t('builder_status_' . $o)) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="pg_mode"><?= h(t('page_open_mode')) ?></label>
						<select class="form-select<?= nu_invalid($errors, 'open_mode') ?>" id="pg_mode" name="open_mode"<?= $ro ? ' disabled' : '' ?>>
							<?php $m = $v('open_mode', $s['open_mode']); foreach (NuPage::MODES as $o): ?>
								<option value="<?= $o ?>"<?= $m === $o ? ' selected' : '' ?>><?= h(t('page_mode_' . $o)) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-6 pgIframeOnly">
						<label class="form-label" for="pg_height"><?= h(t('page_iframe_height')) ?></label><?= $tip(t('page_iframe_height_tip')) ?>
						<input class="form-control font-monospace<?= nu_invalid($errors, 'iframe_height') ?>" id="pg_height" name="iframe_height" maxlength="10" value="<?= h($v('iframe_height', $s['iframe_height'])) ?>"<?= $ro ? ' readonly' : '' ?>>
						<?= nu_feedback($errors, 'iframe_height') ?>
					</div>
					<div class="col-12">
						<div class="d-flex align-items-end gap-2 mb-1">
							<label class="form-label mb-0 me-auto" for="pg_settings"><?= h(t('page_settings_json')) ?></label><?= $tip(t('page_settings_tip')) ?>
							<?php if (!$ro): // examples of the settings JSON (0.8.394): from this page's keys, its settings.example.json, built-in samples ?>
							<div class="dropdown" id="pgExamples" data-confirm-text="<?= h(t('page_example_confirm')) ?>">
								<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="pgExamplesBtn"><i class="bi bi-file-earmark-code me-1"></i><?= h(t('page_examples')) ?></button>
								<ul class="dropdown-menu dropdown-menu-end nuPgExamples">
									<?php if ($fromKeys !== ''): ?><li><button class="dropdown-item" type="button" data-example="<?= h($fromKeys) ?>" id="pgExampleKeys"><span class="d-block"><?= h(t('page_example_keys')) ?></span><small class="text-body-secondary"><?= h(t('page_example_keys_sub')) ?></small></button></li><?php endif; ?>
									<?php if ($example !== ''): ?><li><button class="dropdown-item" type="button" data-example="<?= h($example) ?>" id="pgExample"><span class="d-block"><?= h(t('page_example')) ?></span><small class="text-body-secondary">settings.example.json</small></button></li><?php endif; ?>
									<?php if ($fromKeys !== '' || $example !== ''): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
									<li><h6 class="dropdown-header"><?= h(t('page_examples_samples')) ?></h6></li>
									<?php foreach (NuPage::SETTING_SAMPLES as $lk => [$json, $read]): ?>
										<li><button class="dropdown-item pgSample" type="button" data-example="<?= h((string)json_encode(json_decode($json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"><span class="d-block"><?= h(t($lk)) ?></span><small class="text-body-secondary font-monospace"><?= h($read) ?></small></button></li>
									<?php endforeach; ?>
								</ul>
							</div>
							<?php endif; ?>
							<?php if (!$ro): ?><button class="btn btn-sm btn-outline-secondary" type="button" id="pgPretty" data-bad="<?= h(t('page_err_json', '')) ?>"><i class="bi bi-braces me-1"></i><?= h(t('page_pretty')) ?></button><?php endif; ?>
						</div>
						<textarea class="form-control font-monospace<?= nu_invalid($errors, 'settings') ?>" id="pg_settings" name="settings" rows="10" spellcheck="false"<?= $ro ? ' readonly' : '' ?>><?= h($v('settings', $s['settings'])) ?></textarea>
						<?= nu_feedback($errors, 'settings') ?>
						<div class="invalid-feedback" id="pgJsonError"></div>
						<?php if ($info['exists']): // keys the page reads with $nu->setting() (0.8.391) ?>
						<div class="mt-2 small" id="pgKeys" data-all="<?= $keys['all'] ? '1' : '0' ?>" data-set="<?= h(t('page_keys_set')) ?>" data-unset="<?= h(t('page_keys_unset')) ?>" data-unused="<?= h(t('page_keys_unused', '%s')) ?>">
							<div class="fw-semibold mb-1"><?= h(t('page_keys_title')) ?></div>
							<?php if (!$keys['keys']): ?>
								<div class="text-body-secondary" id="pgKeysNone"><?= h(t('page_keys_none')) ?></div>
							<?php else: ?>
								<div class="table-responsive"><table class="table table-sm nu-table mb-1 align-middle">
									<thead><tr><th><?= h(t('page_keys_col_key')) ?></th><th><?= h(t('page_keys_col_default')) ?></th><th><?= h(t('page_keys_col_where')) ?></th><th></th></tr></thead>
									<tbody>
									<?php foreach ($keys['keys'] as $k => $e): $show = $e['dynamic'] ? rtrim($k, '.') . '.…' : $k; ?>
										<tr class="pgKey" data-key="<?= h(rtrim($k, '.')) ?>" data-dynamic="<?= $e['dynamic'] ? '1' : '0' ?>">
											<td><code><?= h($show) ?></code><?= $e['dynamic'] ? ' <span class="text-body-secondary">' . h(t('page_keys_dynamic')) . '</span>' : '' ?></td>
											<td><?= $e['defaults'] ? '<code>' . h(implode(' | ', $e['defaults'])) . '</code>' : '<span class="text-body-secondary">–</span>' ?></td>
											<td class="text-body-secondary"><?= h(implode(', ', $e['files'])) ?></td>
											<td class="text-end"><span class="badge pgKeyState"></span></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table></div>
							<?php endif; ?>
							<?php if ($keys['all']): ?><div class="text-body-secondary"><?= h(t('page_keys_all')) ?></div><?php endif; ?>
							<div class="text-warning-emphasis d-none" id="pgKeysUnused"></div>
						</div>
						<?php endif; ?>
					</div>
					<div class="col-12">
						<label class="form-label" for="pg_csp"><?= h(t('page_csp')) ?></label><?= $tip(t('page_csp_tip')) ?>
						<input class="form-control font-monospace<?= nu_invalid($errors, 'csp') ?>" id="pg_csp" name="csp" maxlength="2000" spellcheck="false" placeholder="script-src 'self' https://cdn.example.org" value="<?= h($v('csp', $s['csp'])) ?>"<?= $ro ? ' readonly' : '' ?>>
						<?= nu_feedback($errors, 'csp') ?>
					</div>
					<div class="col-12">
						<label class="form-label" for="pg_note"><?= h(t('page_note')) ?></label>
						<textarea class="form-control" id="pg_note" name="note" rows="3"<?= $ro ? ' readonly' : '' ?>><?= h($v('note', $s['note'])) ?></textarea>
					</div>
				</div>
			</div>
		</form>
	</div>

	<div class="col-xl-5">
		<div class="card shadow-sm mb-3" id="pgFolder">
			<div class="card-header fw-semibold"><i class="bi bi-folder2-open me-1"></i><?= h(t('page_sec_folder')) ?></div>
			<div class="card-body">
				<p class="mb-2"><code id="pgFolderPath"><?= h($info['folder']) ?></code></p>
				<ul class="list-unstyled small mb-3">
					<?php if (!$info['valid']): ?>
						<li class="text-danger"><i class="bi bi-x-circle me-1"></i><?= h(t('page_err_code', $meta->code())) ?></li>
					<?php elseif ($info['exists']): ?>
						<li class="text-success" id="pgFileFound"><i class="bi bi-check-circle me-1"></i><?= h(t('page_file_found', $info['mtime'], number_format($info['size'] / 1024, 1))) ?></li>
					<?php else: ?>
						<li class="text-warning-emphasis" id="pgFileMissing"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('page_file_missing')) ?></li>
					<?php endif; ?>
					<?php if ($info['exists'] && !$info['guard']): ?>
						<li class="text-danger" id="pgNoGuard"><i class="bi bi-shield-exclamation me-1"></i><?= h(t('page_file_no_guard')) ?> <code><?= h(NuPage::GUARD) ?></code></li>
					<?php endif; ?>
					<li><i class="bi bi-key me-1"></i><?= h(t('page_secrets', $info['secrets'] ? t('yes') : t('no'))) ?></li>
					<?php if ($info['exists']): $scan = NuPage::scan($meta->code()); $apiIssues = NuPage::check($scan, NuPage::apiInfo()); // API versions (0.8.388) ?>
						<li id="pgApi"><i class="bi bi-plug me-1"></i><?= h($scan['declared'] !== null ? t('page_api_declared', (string)$scan['declared'], implode(', ', NuPage::API_SUPPORTED)) : t('page_api_none', implode(', ', NuPage::API_SUPPORTED))) ?></li>
						<?php if ($apiIssues): foreach ($apiIssues as $i): ?>
							<li class="<?= $i['level'] === 'error' ? 'text-danger' : 'text-warning-emphasis' ?> pgApiIssue"><i class="bi <?= $i['level'] === 'error' ? 'bi-x-circle' : 'bi-exclamation-triangle' ?> me-1"></i><?= h($i['text']) ?></li>
						<?php endforeach; else: ?>
							<li class="text-success" id="pgApiOk"><i class="bi bi-check-circle me-1"></i><?= h(t('page_api_ok')) ?></li>
						<?php endif; ?>
					<?php endif; ?>
				</ul>
				<?php if (!$ro && $info['valid'] && !$info['folder_exists']): ?>
					<?php if ($info['writable'] && $info['template']): ?>
						<form method="post"><?= nu_csrf_field() ?><input type="hidden" name="action" value="template">
							<button class="btn nuBtnAct pgNeedsSaved" type="submit" id="pgTemplate"><i class="bi bi-file-earmark-plus me-1"></i><?= h(t('page_template')) ?></button></form>
					<?php else: ?>
						<div class="small text-body-secondary"><?= h(t('page_template_ftp', NuPage::shownFolder('_template'), $info['folder'])) ?></div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php if ($info['exists'] && NuPageVersion::available($meta->code())): // draft and versions (0.8.599)
			$pc = $meta->code();
			$hasDraft = NuPageVersion::hasDraft($pc);
			$diff = $hasDraft ? NuPageVersion::diff($pc) : null;
			$vers = NuPageVersion::versions($pc);
			$canWrite = !$ro && NuPageVersion::writable($pc);
			$draftPath = NuPage::shownFolder($pc) . NuPageVersion::DRAFT . '/'; ?>
		<div class="card shadow-sm mb-3" id="pgDraft">
			<div class="card-header fw-semibold d-flex align-items-center gap-2"><i class="bi bi-pencil-square"></i><span class="me-auto"><?= h(t('page_sec_draft')) ?></span>
				<?php if ($hasDraft): ?><span class="badge text-bg-warning" id="pgDraftBadge"><?= h(t('page_draft_bar')) ?></span><?php endif; ?></div>
			<div class="card-body small">
				<?php if (!$hasDraft): ?>
					<p class="mb-2" id="pgDraftIntro"><?= h(t('page_draft_intro', $draftPath)) ?></p>
					<?php if ($canWrite): ?>
						<form method="post"><?= nu_csrf_field() ?><input type="hidden" name="action" value="draft_create">
							<button class="btn nuBtnAct" type="submit" id="pgDraftCreate"><i class="bi bi-files me-1"></i><?= h(t('page_draft_create')) ?></button></form>
					<?php elseif (!$ro): ?>
						<div class="text-warning-emphasis"><?= h(t('page_tpl_not_writable', NuPage::shownFolder($pc))) ?></div>
					<?php endif; ?>
				<?php else: ?>
					<p class="mb-2"><?= h(t('page_draft_state')) ?> <code><?= h($draftPath) ?></code></p>
					<?php $n = count($diff['added']) + count($diff['changed']) + count($diff['removed']); ?>
					<?php if ($n === 0): ?>
						<p class="text-body-secondary mb-2" id="pgDraftSame"><?= h(t('page_draft_same')) ?></p>
					<?php else: ?>
						<ul class="list-unstyled mb-2" id="pgDraftDiff">
							<?php foreach (['changed' => 'bi-pencil text-primary', 'added' => 'bi-plus-circle text-success', 'removed' => 'bi-dash-circle text-danger'] as $k => $ic):
								if (!$diff[$k]) continue;
								$shown = array_slice($diff[$k], 0, 8); ?>
								<li class="pgDraft-<?= $k ?>"><i class="bi <?= $ic ?> me-1"></i><?= h(t('page_draft_' . $k, implode(', ', $shown))) ?><?= count($diff[$k]) > 8 ? ' ' . h(t('page_draft_files_more', count($diff[$k]) - 8)) : '' ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<div class="d-flex flex-wrap gap-2">
						<a class="btn nuBtnAct" href="<?= h(nu_url('page', ['f' => $pc, 'nudraft' => '1'])) ?>" target="_blank" rel="noopener" id="pgDraftOpen"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('page_draft_open')) ?></a>
						<?php if ($canWrite): ?>
							<form method="post" data-confirm="<?= h(t('page_draft_publish_confirm')) ?>" data-confirm-ok="<?= h(t('page_draft_publish')) ?>">
								<?= nu_csrf_field() ?><input type="hidden" name="action" value="draft_publish">
								<button class="btn btn-primary" type="submit" id="pgDraftPublish"><i class="bi bi-upload me-1"></i><?= h(t('page_draft_publish')) ?></button></form>
							<form method="post" data-confirm="<?= h(t('page_draft_discard_confirm')) ?>" data-confirm-ok="<?= h(t('page_draft_discard')) ?>" data-confirm-class="btn btn-danger">
								<?= nu_csrf_field() ?><input type="hidden" name="action" value="draft_discard">
								<button class="btn nuBtnAct nuBtnDel" type="submit" id="pgDraftDiscard"><i class="bi bi-trash me-1"></i><?= h(t('page_draft_discard')) ?></button></form>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<hr>
				<div class="fw-semibold mb-1"><?= h(t('page_versions')) ?><?= $tip(t('page_versions_tip', NuPageVersion::KEEP)) ?></div>
				<?php if (!$vers): ?>
					<div class="text-body-secondary" id="pgVersionsNone"><?= h(t('page_versions_none')) ?></div>
				<?php else: ?>
					<div class="table-responsive"><table class="table table-sm nu-table mb-0 align-middle" id="pgVersions">
						<tbody>
						<?php foreach ($vers as $vv): $lbl = NuPageVersion::label($vv['id']); ?>
							<tr data-version="<?= h($vv['id']) ?>">
								<td class="text-nowrap"><?= h($lbl) ?></td>
								<td class="text-body-secondary"><?= h(trim($vv['by'] . ($vv['kind'] !== '' ? ' · ' . t('page_version_kind_' . ($vv['kind'] === 'restore' ? 'restore' : 'publish')) : ''), ' ·')) ?></td>
								<td class="text-end">
									<?php if ($canWrite): ?>
									<form method="post" class="d-inline" data-confirm="<?= h(t('page_version_restore_confirm', $lbl)) ?>" data-confirm-ok="<?= h(t('page_version_restore')) ?>">
										<?= nu_csrf_field() ?><input type="hidden" name="action" value="version_restore"><input type="hidden" name="version" value="<?= h($vv['id']) ?>">
										<button class="btn btn-sm btn-outline-secondary pgRestore" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('page_version_restore')) ?></button></form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>
		<div class="card shadow-sm">
			<div class="card-body small">
				<p class="mb-2"><?= h(t('page_help_intro')) ?></p>
				<p class="mb-0"><a href="<?= h(nu_url('help')) ?>#pages" target="_blank" rel="noopener"><i class="bi bi-question-circle me-1"></i><?= h(t('page_help_link')) ?></a></p>
			</div>
		</div>
	</div>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nubuilderpage.js') . '"></script>');
