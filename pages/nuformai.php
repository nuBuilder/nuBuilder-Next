<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Form with AI (0.8.403, core/form/nuformspec.php): ?p=formai = new form, ?p=formai&f=<form id> = change a form.
 * 1. describe it → "Copy prompt" (format, tables, forms, the current form) → paste into any AI chat;
 * 2. paste the answer → "Check" (errors, warnings, what happens) → "Create draft": a new form with status Draft or a form draft –
 *    the user previews it and publishes it in the builder. POST actions answer JSON: prompt, check (read only), create.
 */

NuAcl::require(NuAcl::FORM_FORM);
$formId = (string)($_GET['f'] ?? '');
$meta = null;
if ($formId !== '') {
	$meta = NuFormMeta::load($formId, true);
	if (!$meta) { http_response_code(404); nu_fatal(t('form_err_not_found'), '', true); }
	if ($meta->isSystem() || $meta->isPage()) nu_fatal(t('fai_err_form_kind'), '', true);
	$formId = $meta->id;
}
$canCreate = NuFormDraft::canEdit();
$openDraft = $meta ? NuFormDraft::openFor($formId) : null;
// an open draft (0.8.810): the change goes into it – design mode's Ask AI works step by step; the prompt describes the draft
$draftSnap = $openDraft ? NuFormDraft::snapshotOf($openDraft) : null;
$appMode = !$meta;                                                 // a new form: one form or a small app (several forms)

if (nu_is_post()) {
	header('Content-Type: application/json; charset=utf-8');
	$out = static function (array $d): never { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
	$action = nu_post('action');
	try {
		if ($action === 'prompt') {
			$req = trim(nu_post('request'));
			if ($req === '') $out(['ok' => false, 'error' => t('fai_err_request')]);
			$tables = array_values(array_filter(array_map('strval', (array)($_POST['tables'] ?? [])), static fn($t) => $t !== ''));
			$text = $appMode && nu_post('kind') === 'app' ? NuFormSpec::promptApp($req, $tables) : NuFormSpec::prompt($req, $formId, $tables, $draftSnap);
			$out(['ok' => true, 'text' => $text]);
		}
		if ($action === 'publish_all') {                    // forms of an app just created (status Draft) → Active
			if (!NuFormDraft::canPublish()) $out(['ok' => false, 'errors' => [t('err_forbidden')]]);
			$n = 0;
			foreach (array_slice(array_map('strval', (array)($_POST['forms'] ?? [])), 0, NuFormSpec::MAX_APP_FORMS) as $fid) {
				if ((string)nu_db()->value('SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$fid]) !== '0') continue;
				NuFormDraft::publishNewForm($fid);
				$n++;
			}
			$out(['ok' => true, 'published' => $n]);
		}
		if ($action === 'check' || $action === 'create') {
			$spec = NuFormSpec::parse(nu_post('answer'));
			if (is_string($spec)) $out(['ok' => false, 'errors' => [$spec], 'warnings' => []]);
			$isApp = NuFormSpec::isApp($spec);
			if ($isApp && !$appMode) $out(['ok' => false, 'errors' => [t('fai_err_app_change')], 'warnings' => []]);
			if ($action === 'check') {
				if ($isApp) {
					$r = NuFormSpec::checkApp($spec);
					$out(['ok' => !$r['errors'], 'app' => true, 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'plan' => $r['plan']]);
				}
				$r = NuFormSpec::check($spec, $formId, $draftSnap);
				unset($r['plan']['snapshot']);
				$out(['ok' => !$r['errors'], 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'plan' => $r['plan'], 'into_draft' => (bool)$openDraft]);
			}
			if (!$canCreate) $out(['ok' => false, 'errors' => [t('err_forbidden')]]);
			if ($isApp) {
				$made = NuFormSpec::createApp($spec, nu_post('ddl_ok') === '1', nu_post('note'));
				foreach ($made as &$m) { $mm = NuFormMeta::load($m['id'], true); $m['url'] = $mm ? NuDesign::fromBuilder($mm, []) : nu_url('forms'); }
				unset($m);
				$out(['ok' => true, 'app' => true, 'forms' => $made, 'can_publish' => NuFormDraft::canPublish()]);
			}
			$res = NuFormSpec::create($spec, $formId, nu_post('ddl_ok') === '1', nu_post('note'), $openDraft);
			$newMeta = NuFormMeta::load((string)$res['form_id'], true);            // design mode (0.8.670): the new form or the draft of the change
			$out(['ok' => true, 'url' => $newMeta ? NuDesign::fromBuilder($newMeta, []) : nu_url('forms')]);
		}
	} catch (Throwable $e) {
		$out(['ok' => false, 'errors' => explode("\n", $e->getMessage()), 'warnings' => []]);
	}
	$out(['ok' => false, 'errors' => ['?']]);
}

$labels = [];
foreach (['fai_copied', 'fai_copy_failed', 'fai_ok', 'fai_errors', 'fai_warnings', 'fai_plan', 'fai_plan_new_table', 'fai_plan_columns', 'fai_plan_added', 'fai_plan_changed',
	'fai_plan_removed', 'fai_plan_moved', 'fai_plan_form', 'fai_plan_browse', 'fai_plan_tabs', 'fai_plan_none', 'fai_ddl_ok', 'fai_create', 'fai_creating', 'fai_note',
	'fai_plan_code', 'fai_plan_app', 'fai_plan_objects', 'fai_create_app', 'fai_made', 'fai_made_open', 'fai_publish_all', 'fai_published', 'fai_paste_failed', 'fai_into_draft',
	'builder_status_0', 'form_type_browseedit', 'form_type_browse', 'form_type_edit', 'form_type_launch', 'form_type_subform'] as $k) $labels[$k] = t($k);
$reqIn = mb_substr(trim((string)($_GET['req'] ?? '')), 0, 4000);   // "Describe it" of the New form wizard (0.8.810)
$kindIn = ($_GET['kind'] ?? '') === 'app' ? 'app' : 'form';

nu_layout_start(t($meta ? 'fai_title_change' : 'fai_title_new'), 'forms', ['head' => nu_ts_head()]);
?>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><i class="bi bi-stars me-2"></i><?= h(t($meta ? 'fai_title_change' : 'fai_title_new')) ?></h1>
	<?php if ($meta): ?><div class="nuTitleSub text-body-secondary"><?= h($meta->description()) ?> · <code><?= h($meta->code()) ?></code></div><?php endif; ?>
	<div class="d-flex flex-wrap gap-2"><?= nu_back_button(null, $meta ? nu_url('builder', ['f' => $formId]) : nu_url('forms')) ?></div>
</div>
<?php if ($openDraft): ?>
	<div class="alert alert-info" id="faDraftNote"><i class="bi bi-info-circle me-1"></i><?= h(t('fai_draft_continue', (string)$openDraft['created_by'])) ?>
		<a class="alert-link ms-1" href="<?= h(nu_url('builder', ['f' => $formId, 'draft' => '1'])) ?>"><?= h(t('fai_open_draft')) ?></a></div>
<?php endif; ?>
<?php $appTables = []; try { $appTables = NuFormSpec::appTables(); } catch (Throwable) {} $formTable = $meta ? (string)$meta->table() : '';
$tmode = $formTable !== '' ? 'pick' : 'none';   // wizard step 2 (0.8.843): new form = no existing table, change = the form's own table
$startStep = 1; ?>
<form id="faForm" autocomplete="off" data-labels="<?= h(json_encode($labels, JSON_UNESCAPED_UNICODE)) ?>" data-step="<?= $startStep ?>">
	<?= nu_csrf_field() ?>
	<?php // wizard (0.8.843): 1 what (kind + description) · 2 existing tables + copy prompt · 3 answer, check, create ?>
	<ol class="nuFaSteps nav nav-pills mb-3" id="faSteps" role="tablist">
		<?php foreach ([1 => $meta ? 'fai_wz_step1_change' : 'fai_wz_step1', 2 => 'fai_wz_step2', 3 => 'fai_wz_step3'] as $n => $k): ?>
		<li class="nav-item" role="presentation"><button type="button" class="nav-link<?= $n === $startStep ? ' active' : '' ?>" id="faStepNav<?= $n ?>" data-step="<?= $n ?>" role="tab"
			aria-controls="faStep<?= $n ?>" aria-selected="<?= $n === $startStep ? 'true' : 'false' ?>"><span class="badge rounded-pill me-2"><?= $n ?></span><?= h(t($k)) ?></button></li>
		<?php endforeach; ?>
	</ol>

	<section class="card shadow-sm nuFaStep" id="faStep1" role="tabpanel" aria-labelledby="faStepNav1"<?= $startStep !== 1 ? ' hidden' : '' ?>><div class="card-body">
		<?php if ($appMode): // one form or a small app (0.8.810) ?>
		<div class="btn-group mb-3" role="radiogroup" aria-label="<?= h(t('fai_kind')) ?>" id="faKind">
			<input type="radio" class="btn-check" name="faKind" id="faKindForm" value="form" autocomplete="off"<?= $kindIn === 'form' ? ' checked' : '' ?>>
			<label class="btn btn-outline-secondary" for="faKindForm"><i class="bi bi-ui-checks me-1" aria-hidden="true"></i><?= h(t('fai_kind_form')) ?></label>
			<input type="radio" class="btn-check" name="faKind" id="faKindApp" value="app" autocomplete="off"<?= $kindIn === 'app' ? ' checked' : '' ?>>
			<label class="btn btn-outline-secondary" for="faKindApp"><i class="bi bi-grid-3x2-gap me-1" aria-hidden="true"></i><?= h(t('fai_kind_app')) ?></label>
		</div>
		<?php endif; ?>
		<label class="form-label fw-semibold d-block" for="faRequest"><?= h(t('fai_wz_describe')) ?></label>
		<p class="small text-body-secondary mb-2"><?= h(t($meta ? 'fai_step1_help_change' : 'fai_wz_help_new')) ?></p>
		<textarea class="form-control mb-1" id="faRequest" rows="9" placeholder="<?= h(t($meta ? 'fai_request_ph_change' : 'fai_request_ph_new')) ?>" data-ph-app="<?= h(t('fai_request_ph_app')) ?>"><?= h($reqIn) ?></textarea>
		<div class="invalid-feedback" id="faRequestErr"><?= h(t('fai_err_request')) ?></div>
		<div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-primary" data-go="2" id="faNext1"><?= h(t('fai_wz_next')) ?><i class="bi bi-arrow-right ms-1"></i></button></div>
	</div></section>

	<section class="card shadow-sm nuFaStep" id="faStep2" role="tabpanel" aria-labelledby="faStepNav2" hidden><div class="card-body">
		<p class="small text-body-secondary mb-2"><?= h(t('fai_wz_tables_help')) ?></p>
		<div class="d-flex flex-column gap-1 mb-2" role="radiogroup" aria-label="<?= h(t('fai_wz_step2')) ?>" id="faTablesMode">
			<?php foreach (['none' => 'fai_tables_none', 'all' => 'fai_tables_mode_all', 'pick' => 'fai_tables_mode_pick'] as $m => $k): ?>
			<div class="form-check"><input class="form-check-input" type="radio" name="faTablesMode" id="faTm_<?= $m ?>" value="<?= $m ?>"<?= $m === $tmode ? ' checked' : '' ?>>
				<label class="form-check-label" for="faTm_<?= $m ?>"><?= h(t($k)) ?></label></div>
			<?php endforeach; ?>
		</div>
		<div class="nuFaPick mb-3" id="faPickBox"<?= $tmode !== 'pick' ? ' hidden' : '' ?>>
			<label class="form-label visually-hidden" for="faTables"><?= h(t('fai_tables_mode_pick')) ?></label>
			<select class="nuListTomSelect" id="faTables" name="tables[]" multiple placeholder="<?= h(t('fai_tables_pick_ph')) ?>">
				<?php foreach ($appTables as $tb): ?><option value="<?= h($tb) ?>"<?= $tb === $formTable ? ' selected' : '' ?>><?= h($tb) ?></option><?php endforeach; ?>
			</select>
		</div>
		<p class="small text-body-secondary mb-2"><?= h(t('fai_wz_copy_help')) ?></p>
		<div class="d-flex flex-wrap gap-2 align-items-center">
			<button type="button" class="btn btn-outline-secondary" data-go="1"><i class="bi bi-arrow-left me-1"></i><?= h(t('fai_wz_back')) ?></button>
			<button type="button" class="btn btn-primary" id="faCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('fai_copy')) ?></button>
			<button type="button" class="btn btn-outline-primary" data-go="3" id="faNext2"><?= h(t('fai_wz_next')) ?><i class="bi bi-arrow-right ms-1"></i></button>
			<span class="small text-body-secondary" id="faCopyMsg" aria-live="polite"></span>
		</div>
		<details class="mt-3" id="faPromptBox" hidden><summary class="small"><?= h(t('fai_show_prompt')) ?></summary>
			<textarea class="form-control form-control-sm font-monospace mt-2" id="faPrompt" rows="8" readonly aria-label="<?= h(t('fai_show_prompt')) ?>"></textarea></details>
	</div></section>

	<section class="card shadow-sm nuFaStep" id="faStep3" role="tabpanel" aria-labelledby="faStepNav3" hidden><div class="card-body">
		<label class="form-label fw-semibold" for="faAnswer"><?= h(t('fai_step2')) ?></label>
		<p class="small text-body-secondary mb-2"><?= h(t('fai_step2_help')) ?></p>
		<textarea class="form-control font-monospace small mb-2" id="faAnswer" rows="10" placeholder="```json …```"></textarea>
		<div class="d-flex flex-wrap gap-2">
			<button type="button" class="btn btn-outline-secondary" data-go="2"><i class="bi bi-arrow-left me-1"></i><?= h(t('fai_wz_back')) ?></button>
			<button type="button" class="btn btn-outline-secondary" id="faPaste" title="<?= h(t('fai_paste_tip')) ?>"><i class="bi bi-clipboard-check me-1"></i><?= h(t('fai_paste')) ?></button>
			<button type="button" class="btn btn-primary" id="faCheck"><i class="bi bi-check2-circle me-1"></i><?= h(t('fai_check')) ?></button>
		</div>
		<div class="mt-3" id="faResult" hidden>
			<h2 class="h6 mt-3"><?= h(t('fai_step3')) ?></h2>
			<div id="faMessages"></div>
			<div id="faPlan" class="small"></div>
			<div id="faCreateBox" hidden>
				<div class="form-check mb-2" id="faDdlBox" hidden><input class="form-check-input" type="checkbox" id="faDdl"><label class="form-check-label" for="faDdl"><?= h(t('fai_ddl_ok')) ?></label></div>
				<div class="mb-2"><label class="form-label small" for="faNote"><?= h(t('fai_note')) ?></label><input type="text" class="form-control" id="faNote" maxlength="200"></div>
				<?php if ($canCreate): ?>
					<button type="button" class="btn nuBtnAct nuBtnSave" id="faCreate"><i class="bi bi-file-earmark-plus me-1"></i><?= h(t($openDraft ? 'fai_apply' : 'fai_create')) ?></button>
					<div class="form-text" id="faCreateHelp" data-app="<?= h(t('fai_create_help_app')) ?>"><?= h(t($meta ? ($openDraft ? 'fai_create_help_draft' : 'fai_create_help_change') : 'fai_create_help_new')) ?></div>
				<?php endif; ?>
			</div>
			<div id="faMade" hidden></div>
		</div>
	</div></section>
</form>
<?php
nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nuformai.js') . '"></script>');
