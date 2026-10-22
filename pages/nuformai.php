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

if (nu_is_post()) {
	header('Content-Type: application/json; charset=utf-8');
	$out = static function (array $d): never { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
	$action = nu_post('action');
	try {
		if ($action === 'prompt') {
			$req = trim(nu_post('request'));
			if ($req === '') $out(['ok' => false, 'error' => t('fai_err_request')]);
			$out(['ok' => true, 'text' => NuFormSpec::prompt($req, $formId)]);
		}
		if ($action === 'check' || $action === 'create') {
			$spec = NuFormSpec::parse(nu_post('answer'));
			if (is_string($spec)) $out(['ok' => false, 'errors' => [$spec], 'warnings' => []]);
			if ($action === 'check') {
				$r = NuFormSpec::check($spec, $formId);
				unset($r['plan']['snapshot']);
				$out(['ok' => !$r['errors'], 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'plan' => $r['plan']]);
			}
			if (!$canCreate) $out(['ok' => false, 'errors' => [t('err_forbidden')]]);
			if ($openDraft) $out(['ok' => false, 'errors' => [t('builder_draft_exists', (string)$openDraft['created_by'])]]);
			$res = NuFormSpec::create($spec, $formId, nu_post('ddl_ok') === '1', nu_post('note'));
			$out(['ok' => true, 'url' => nu_url('builder', $res['draft_id'] !== '' ? ['f' => $res['form_id'], 'draft' => '1'] : ['f' => $res['form_id']])]);
		}
	} catch (Throwable $e) {
		$out(['ok' => false, 'errors' => explode("\n", $e->getMessage()), 'warnings' => []]);
	}
	$out(['ok' => false, 'errors' => ['?']]);
}

$labels = [];
foreach (['fai_copied', 'fai_copy_failed', 'fai_ok', 'fai_errors', 'fai_warnings', 'fai_plan', 'fai_plan_new_table', 'fai_plan_columns', 'fai_plan_added', 'fai_plan_changed',
	'fai_plan_removed', 'fai_plan_moved', 'fai_plan_form', 'fai_plan_browse', 'fai_plan_tabs', 'fai_plan_none', 'fai_ddl_ok', 'fai_create', 'fai_creating', 'fai_note'] as $k) $labels[$k] = t($k);

nu_layout_start(t($meta ? 'fai_title_change' : 'fai_title_new'), 'forms');
?>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><i class="bi bi-stars me-2"></i><?= h(t($meta ? 'fai_title_change' : 'fai_title_new')) ?></h1>
	<?php if ($meta): ?><div class="nuTitleSub text-body-secondary"><?= h($meta->description()) ?> · <code><?= h($meta->code()) ?></code></div><?php endif; ?>
	<div class="d-flex flex-wrap gap-2"><?= nu_back_button(null, $meta ? nu_url('builder', ['f' => $formId]) : nu_url('forms')) ?></div>
</div>
<?php if ($openDraft): ?>
	<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('builder_draft_exists', (string)$openDraft['created_by'])) ?>
		<a class="alert-link ms-1" href="<?= h(nu_url('builder', ['f' => $formId, 'draft' => '1'])) ?>"><?= h(t('fai_open_draft')) ?></a></div>
<?php endif; ?>
<form id="faForm" class="row g-3" autocomplete="off" data-labels="<?= h(json_encode($labels, JSON_UNESCAPED_UNICODE)) ?>">
	<?= nu_csrf_field() ?>
	<div class="col-lg-6">
		<div class="card shadow-sm h-100"><div class="card-body">
			<h2 class="h5"><span class="badge rounded-pill text-bg-primary me-2">1</span><?= h(t('fai_step1')) ?></h2>
			<p class="small text-body-secondary"><?= h(t($meta ? 'fai_step1_help_change' : 'fai_step1_help_new')) ?></p>
			<label class="form-label visually-hidden" for="faRequest"><?= h(t('fai_step1')) ?></label>
			<textarea class="form-control mb-2" id="faRequest" rows="7" placeholder="<?= h(t($meta ? 'fai_request_ph_change' : 'fai_request_ph_new')) ?>"></textarea>
			<div class="d-flex flex-wrap gap-2 align-items-center">
				<button type="button" class="btn btn-primary" id="faCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('fai_copy')) ?></button>
				<span class="small text-body-secondary" id="faCopyMsg" aria-live="polite"></span>
			</div>
			<details class="mt-3" id="faPromptBox" hidden><summary class="small"><?= h(t('fai_show_prompt')) ?></summary>
				<textarea class="form-control form-control-sm font-monospace mt-2" id="faPrompt" rows="8" readonly aria-label="<?= h(t('fai_show_prompt')) ?>"></textarea></details>
		</div></div>
	</div>
	<div class="col-lg-6">
		<div class="card shadow-sm h-100"><div class="card-body">
			<h2 class="h5"><span class="badge rounded-pill text-bg-primary me-2">2</span><?= h(t('fai_step2')) ?></h2>
			<p class="small text-body-secondary"><?= h(t('fai_step2_help')) ?></p>
			<label class="form-label visually-hidden" for="faAnswer"><?= h(t('fai_step2')) ?></label>
			<textarea class="form-control font-monospace small mb-2" id="faAnswer" rows="10" placeholder="```json …```"></textarea>
			<button type="button" class="btn btn-outline-primary" id="faCheck"><i class="bi bi-check2-circle me-1"></i><?= h(t('fai_check')) ?></button>
		</div></div>
	</div>
	<div class="col-12" id="faResult" hidden>
		<div class="card shadow-sm"><div class="card-body">
			<h2 class="h5"><span class="badge rounded-pill text-bg-primary me-2">3</span><?= h(t('fai_step3')) ?></h2>
			<div id="faMessages"></div>
			<div id="faPlan" class="small"></div>
			<div id="faCreateBox" hidden>
				<div class="form-check mb-2" id="faDdlBox" hidden><input class="form-check-input" type="checkbox" id="faDdl"><label class="form-check-label" for="faDdl"><?= h(t('fai_ddl_ok')) ?></label></div>
				<div class="mb-2"><label class="form-label small" for="faNote"><?= h(t('fai_note')) ?></label><input type="text" class="form-control" id="faNote" maxlength="200"></div>
				<?php if ($canCreate && !$openDraft): ?>
					<button type="button" class="btn nuBtnAct nuBtnSave" id="faCreate"><i class="bi bi-file-earmark-plus me-1"></i><?= h(t('fai_create')) ?></button>
					<div class="form-text"><?= h(t($meta ? 'fai_create_help_change' : 'fai_create_help_new')) ?></div>
				<?php endif; ?>
			</div>
		</div></div>
	</div>
</form>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuformai.js') . '"></script>');
