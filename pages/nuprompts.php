<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Prompt generator (zzzzsys_prompt_generator, nuBuilder's "Prompt Generator") – builds AI prompts with table schemas and
 * nuBuilder context for copying; nothing is sent to an AI. Rights of the form "nupromptgenerator" (globeadmin always).
 *   list · ?id=<id> | ?id=new edit (prefill: &table=…&scope=…&lang=…) · POST action=build → JSON {prompt, chars}
 */

NuAcl::require(NuAcl::FORM_PROMPT);
$db     = nu_db();
$rights = NuAcl::rights(NuAcl::FORM_PROMPT);
$T      = NuPrompt::TABLE;
NuPrompt::ensureTable();

$id    = (string)($_GET['id'] ?? '');
$isNew = $id === 'new';
$rec   = null;
if ($id !== '' && !$isNew) {
	$rec = $db->one("SELECT * FROM $T WHERE zzzzsys_prompt_generator_id = ?", [$id]);
	if (!$rec) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
// clone (0.8.680, as nuBuilder Forte): ?id=new&clone=<id> opens a copy with a free code that is saved only with Save
$cloneId = $isNew ? (string)($_GET['clone'] ?? '') : '';
$src = null;
if ($cloneId !== '') {
	NuAcl::require(NuAcl::FORM_PROMPT, 'clone');
	$src = $db->one("SELECT * FROM $T WHERE zzzzsys_prompt_generator_id = ?", [$cloneId]);
	if (!$src) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
	$base = mb_substr((string)$src['pge_code'], 0, 290);
	for ($n = 2; (int)$db->value("SELECT COUNT(*) FROM $T WHERE pge_code = ?", [$base . '_' . $n]); $n++);
	$src['pge_code'] = $base . '_' . $n;
	$src['pge_template'] = '0';
	foreach (NuPrompt::LEN as $k => $_) $src[$k] = (string)($src[$k] ?? '');
}
$errors = [];

if (nu_is_post()) {
	$action = nu_post('action');
	// live preview of the complete prompt (editor, while typing)
	if ($action === 'build') {
		$row = NuPrompt::fromInput($_POST);
		$prompt = NuPrompt::build($row);
		header('Content-Type: application/json; charset=utf-8');
		exit(json_encode(['prompt' => $prompt, 'chars' => mb_strlen($prompt)], JSON_UNESCAPED_UNICODE));
	}
	if ($action === 'save') {
		NuAcl::require(NuAcl::FORM_PROMPT, $rec ? 'save' : ($src ? 'clone' : 'create'));
		$row = NuPrompt::fromInput($_POST);
		[$newId, $errors] = NuPrompt::save($row, $rec ? $id : '');
		if (!$errors) {
			$after = $db->one("SELECT * FROM $T WHERE zzzzsys_prompt_generator_id = ?", [$newId]) ?? [];
			NuLog::write($rec ? 'prompt_changed' : 'prompt_created', $T, (string)$newId, $rec ? ['fields' => NuLog::diff($rec, $after)] : ['code' => $row['pge_code']] + ($src ? ['clone_of' => $cloneId] : []));
			nu_flash('success', t('saved'));
			nu_redirect(nu_url('prompts', ['id' => $newId]));
		}
	}
	if ($action === 'delete' && $rec) {
		NuAcl::require(NuAcl::FORM_PROMPT, 'delete');
		$db->delete($T, 'zzzzsys_prompt_generator_id', $id);
		NuLog::write('prompt_deleted', $T, $id, ['code' => (string)$rec['pge_code']]);
		nu_flash('success', t('pg_deleted'));
		nu_redirect(nu_url('prompts'));
	}
}

$labels = static function (mixed $v, array $catalog): array {
	return array_map(static fn($c) => $catalog[$c]['label'] ?? $c, NuPrompt::list($v));
};

// ------------------------------------------------------------------ list (also Print / CSV)
if ($id === '') {
	// list: columns, filters and sort of the chosen list view (0.8.334, core/nulistdefs.php)
	NuListView::page('prompts', [
		'before' => NuListView::crumbs([[t('nav_forms'), nu_url('forms')], [t('pg_title')]]), 'active' => 'forms',
		'buttons' => $rights['add'] ? '<a class="btn nuAddBtn" title="' . h(t('pg_new')) . '" href="' . h(nu_url('prompts', ['id' => 'new'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '',
	]);
	return;
}

$head = $id !== '' ? '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">' : '';
nu_layout_start(t('pg_title'), 'forms', ['head' => $head]);
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
		<?php if ($id !== ''): ?>
			<li class="breadcrumb-item"><a href="<?= h(nu_url('prompts')) ?>"><?= h(t('pg_title')) ?></a></li>
			<li class="breadcrumb-item active" aria-current="page"><?= h($rec ? (string)$rec['pge_code'] : t('pg_new')) ?></li>
		<?php else: ?>
			<li class="breadcrumb-item active" aria-current="page"><?= h(t('pg_title')) ?></li>
		<?php endif; ?>
	</ol>
</nav>

<?php if ($id === ''): ?>
<?php else: ?>
	<?php
	// ------------------------------------------------------------------ editor
	$posted = nu_is_post() && nu_post('action') === 'save';
	if ($posted) $val = NuPrompt::fromInput($_POST);
	elseif ($rec) $val = $rec;
	elseif ($src) $val = $src;                                // a clone shows the values of its source
	else {                                                    // new: defaults, optional prefill from the URL
		$val = ['pge_code' => '', 'pge_description' => '', 'pge_instruction' => NuPrompt::defaultInstruction(), 'pge_prompt' => '', 'pge_template' => '0',
			'pge_language' => json_encode(NuPrompt::list($_GET['lang'] ?? 'nuphp')), 'pge_scope' => json_encode(NuPrompt::list($_GET['scope'] ?? 'nuphp_procedure')),
			'pge_tag' => '', 'pge_table' => json_encode(NuPrompt::list($_GET['table'] ?? ''))];
	}
	$locked = !($rec ? $rights['save'] : $rights[$src ? 'clone' : 'create']);
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$fb  = static fn(string $k) => '<div class="invalid-feedback">' . h($errors[$k] ?? '') . '</div>';
	$row = static function (string $k, string $label, string $control, bool $req = false): string {
		return '<div class="row g-2 mb-3 align-items-start"><label class="col-sm-3 col-form-label text-sm-end" for="' . $k . '">' . h($label)
			. ($req ? ' <span class="text-danger">*</span>' : '') . ($k === 'pge_code' ? nu_unique_mark($req) : '') . '</label><div class="col-sm-9">' . $control . '</div></div>';   // key: unique (0.8.627)
	};
	$multi = static function (string $k, array $options, array $selected, string $extra = '') use ($err): string {
		$h = '<select class="form-select pgMulti' . $err($k) . '" id="' . $k . '" name="' . $k . '[]" multiple placeholder="' . h(t('pg_select_ph')) . '"' . $extra . '>';
		foreach ($options as $code => $label) $h .= '<option value="' . h((string)$code) . '"' . (in_array((string)$code, $selected, true) ? ' selected' : '') . '>' . h($label) . '</option>';
		foreach (array_diff($selected, array_map('strval', array_keys($options))) as $code) $h .= '<option value="' . h($code) . '" selected>' . h($code) . '</option>';   // unknown codes stay
		return $h . '</select>';
	};
	$labelsOf = static fn(array $cat) => array_map(static fn($e) => $e['label'], $cat);
	$tables = NuPrompt::tables();
	?>
	<form method="post" data-nu-focus-first data-nu-guard id="pgForm" action="<?= h(nu_url('prompts', ['id' => $rec ? $id : 'new', 'clone' => $cloneId])) ?>"<?= $src ? ' data-nu-clone="' . h(t('form_clone_badge')) . '"' : '' ?> novalidate data-catalog="<?= h(NuPrompt::catalogJson()) ?>"
		  data-copied="<?= h(t('pg_copied')) ?>" data-stats="<?= h(t('pg_stats')) ?>" data-suggest-none="<?= h(t('pg_suggest_none')) ?>" data-suggest-added="<?= h(t('pg_suggest_added')) ?>">
		<?= nu_csrf_field() ?>
		<input type="hidden" name="action" value="save">
		<div class="nuTitleBar mb-3">
			<h1 class="h4 mb-0"><i class="bi bi-stars me-2"></i><?= h($rec ? (string)$rec['pge_code'] : t('pg_new')) ?></h1>
			<a class="btn nuBtnAct" href="<?= h(nu_url('prompts')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
			<?php if (!$locked): ?><button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" name="action" value="save" id="pgSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button><?php endif; ?>
			<?php if ($rec && $rights['clone']): ?>
				<a class="btn nuBtnAct" href="<?= h(nu_url('prompts', ['id' => 'new', 'clone' => $id])) ?>" id="pgClone"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></a>
			<?php endif; ?>
			<?php if ($rec && $rights['delete']): ?>
				<button class="btn nuBtnAct nuBtnDel" type="submit" name="action" value="delete" formnovalidate data-confirm-submit="<?= h(t('pg_delete_confirm')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
		</div>
		<div class="row g-3">
			<div class="col-xl-7">
				<fieldset class="card shadow-sm card-body"<?= $locked ? ' disabled' : '' ?>>
					<?= $row('pge_instruction', t('pg_instruction'), '<textarea class="form-control pgInput' . $err('pge_instruction') . '" id="pge_instruction" name="pge_instruction" rows="3" maxlength="3000">' . h((string)$val['pge_instruction']) . '</textarea>' . $fb('pge_instruction')) ?>
					<?= $row('pge_prompt', t('pg_prompt'), '<textarea class="form-control pgInput' . $err('pge_prompt') . '" id="pge_prompt" name="pge_prompt" rows="6" placeholder="' . h(t('pg_prompt_ph')) . '"' . ($rec ? '' : ' autofocus') . '>' . h((string)$val['pge_prompt']) . '</textarea>' . $fb('pge_prompt'), true) ?>
					<?= $row('pge_language', t('pg_languages'), $multi('pge_language', $labelsOf(NuPrompt::languages()), NuPrompt::list($val['pge_language']))) ?>
					<?= $row('pge_scope', t('pg_scopes'), $multi('pge_scope', $labelsOf(NuPrompt::scopes()), NuPrompt::list($val['pge_scope']))) ?>
					<?= $row('pge_tag', t('pg_topics'), '<div class="d-flex gap-2"><div class="flex-grow-1 min-w-0">' . $multi('pge_tag', $labelsOf(NuPrompt::topics()), NuPrompt::list($val['pge_tag']))
						. '</div><button class="btn btn-outline-secondary nuSearchBtn flex-none align-self-start" type="button" id="pgSuggest" title="' . h(t('pg_suggest')) . '" aria-label="' . h(t('pg_suggest')) . '"><i class="bi bi-magic"></i></button></div>'
						. '<div class="form-text" id="pgSuggestInfo" aria-live="polite"></div>') ?>
					<?= $row('pge_table', t('pg_tables'), $multi('pge_table', array_combine($tables, $tables) ?: [], NuPrompt::list($val['pge_table']), ' data-max="' . NuPrompt::MAX_TABLES . '"')
						. '<div class="form-text">' . h(t('pg_tables_hint', NuPrompt::MAX_TABLES)) . '</div>') ?>
					<hr class="mt-0">
					<?= $row('pge_code', t('pg_code'), '<input class="form-control' . $err('pge_code') . '" id="pge_code" name="pge_code" maxlength="300" value="' . h((string)$val['pge_code']) . '" placeholder="' . h(t('pg_code_ph')) . '">' . $fb('pge_code'), true) ?>
					<?= $row('pge_description', t('pg_description'), '<input class="form-control' . $err('pge_description') . '" id="pge_description" name="pge_description" maxlength="300" value="' . h((string)$val['pge_description']) . '">' . $fb('pge_description')) ?>
					<?= $row('pge_template', t('pg_template'), '<div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="pge_template" name="pge_template" value="1"' . ((string)$val['pge_template'] === '1' ? ' checked' : '') . '>'
						. '<label class="form-check-label small text-body-secondary" for="pge_template">' . h(t('pg_template_hint')) . '</label></div>') ?>
				</fieldset>
			</div>
			<div class="col-xl-5">
				<div class="card shadow-sm pgResultCard">
					<div class="card-header d-flex align-items-center gap-2">
						<span class="fw-semibold me-auto"><i class="bi bi-file-earmark-text me-2"></i><?= h(t('pg_result')) ?></span>
						<span class="small text-body-secondary" id="pgStats"></span>
					</div>
					<div class="card-body">
						<textarea class="form-control font-monospace small pgResult" id="pgResult" readonly rows="18" aria-label="<?= h(t('pg_result')) ?>"><?= h(NuPrompt::build($val)) ?></textarea>
						<div class="d-flex flex-wrap gap-2 align-items-center mt-2">
							<button class="btn btn-primary" type="button" id="pgCopy"><i class="bi bi-clipboard me-1"></i><span><?= h(t('pg_copy')) ?></span></button>
							<span class="small text-body-secondary"><i class="bi bi-shield-lock me-1"></i><?= h(t('pg_result_hint')) ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</form>
<?php endif; ?>
<?php
nu_layout_end($id !== '' ? '<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nuprompts.js') . '"></script>' : '');
