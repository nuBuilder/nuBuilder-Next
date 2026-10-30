<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Webhooks (0.8.715, core/nuwebhook.php): list · ?tab=calls the calls · ?id=new | ?id=<webhook> edit · ?call=<id> one call with its
 * attempts. Admin right "webhooks" (full access: record data leaves the server). The secret is shown once after create / New secret.
 */
NuAdmin::require('webhooks');
NuSecurity::requireRecentAuth(nu_url('webhooks'));
NuSchema::ensure();

$db = nu_db();
$id = (string)($_GET['id'] ?? '');
$callId = (string)($_GET['call'] ?? '');
$tab = ($_GET['tab'] ?? '') === 'calls' ? 'calls' : '';
$hook = $id !== '' && $id !== 'new' ? NuWebhook::get($id) : null;
if ($id !== '' && $id !== 'new' && !$hook) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('webhooks')); }
$call = $callId !== '' ? $db->one('SELECT * FROM ' . NuWebhook::CALL . ' WHERE ' . NuWebhook::CALL . '_id = ?', [$callId]) : null;
if ($callId !== '' && !$call) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('webhooks', ['tab' => 'calls'])); }
$errors = [];
$test = null;

// ------------------------------------------------------------------ actions
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'settings') {
		NuWebhook::setSettings(nu_check('allow_private') === '1');
		nu_flash('success', t('saved'));
		nu_redirect(nu_url('webhooks'));
	}
	if ($call && $action === 'resend') {
		$r = NuWebhook::resend($callId);
		nu_flash($r && $r['ok'] ? 'success' : 'warning', $r && $r['ok'] ? t('wh_test_ok', $r['code'], $r['ms']) : t('wh_test_fail', (string)($r['error'] ?? '')));
		nu_redirect(nu_url('webhooks', ['call' => $callId]));
	}
	if ($action === 'save') {
		$in = ['name' => nu_post('name'), 'active' => nu_check('active') === '1', 'forms' => (array)($_POST['forms'] ?? []), 'events' => (array)($_POST['events'] ?? []),
			'url' => nu_post('url'), 'fields' => nu_post('fields'), 'template' => nu_post('template'), 'new_secret' => nu_post('new_secret') === '1'];
		[$newId, $errors, $secret] = NuWebhook::save($in, $hook ? $id : '');
		if (!$errors) {
			if ($secret !== null) $_SESSION['nu_wh_secret'] = ['id' => $newId, 'secret' => $secret];
			if (nu_post('do_test') === '1') {                       // "Send test": save, then one sample call now
				$r = NuWebhook::test((array)NuWebhook::get($newId));
				$_SESSION['nu_wh_test'] = ['id' => $newId] + $r;
			} else {
				nu_flash('success', t('saved'));
			}
			nu_redirect(nu_url('webhooks', ['id' => $newId]));
		}
		nu_flash('danger', t('err_check_form'));
	}
	if ($hook && $action === 'delete') {
		NuWebhook::delete($id);
		nu_flash('success', t('wh_deleted', $hook['name']));
		nu_redirect(nu_url('webhooks'));
	}
}

$secret = $_SESSION['nu_wh_secret'] ?? null;
if ($secret && (string)$secret['id'] !== $id) $secret = null;
unset($_SESSION['nu_wh_secret']);                                   // shown once
$test = $_SESSION['nu_wh_test'] ?? null;
if ($test && (string)$test['id'] !== $id) $test = null;
unset($_SESSION['nu_wh_test']);

$tabs = static function (string $cur): string {
	return '<ul class="nav nav-tabs mb-3" id="whTabs">'
		. '<li class="nav-item"><a class="nav-link' . ($cur === '' ? ' active" aria-current="page' : '') . '" href="' . h(nu_url('webhooks')) . '"><i class="bi bi-broadcast me-1"></i>' . h(t('wh_title')) . '</a></li>'
		. '<li class="nav-item"><a class="nav-link' . ($cur === 'calls' ? ' active" aria-current="page' : '') . '" href="' . h(nu_url('webhooks', ['tab' => 'calls'])) . '" id="whTabCalls"><i class="bi bi-list-ul me-1"></i>' . h(t('wh_calls')) . '</a></li></ul>';
};
$crumbs = static fn(array $items) => NuListView::crumbs(array_merge([[t('wh_title'), nu_url('webhooks')]], $items));

// ------------------------------------------------------------------ lists
if ($id === '' && $callId === '') {
	$st = NuListView::prepare($tab === 'calls' ? 'webhookcalls' : 'webhooks');   // Print / CSV end here
	nu_layout_start(t('wh_title'), 'home', ['head' => NuListView::head() . nu_ts_head()]);
	echo NuListView::crumbs([[t('wh_title')]]) . $tabs($tab);
	if (!function_exists('curl_init')) echo '<div class="alert alert-warning small">' . h(t('wh_err_curl')) . '</div>';
	if ((string)nu_cfg('nuConfigSecretKey') === '') echo '<div class="alert alert-warning small"><i class="bi bi-key me-1"></i>' . h(t('wh_no_key')) . '</div>';
	echo NuListView::card($st, $tab === 'calls' ? ['title_extra' => NuListView::infoTip(t('wh_calls_tip'))] : [
		'title_extra' => NuListView::infoTip(t('wh_hint')),
		'buttons' => '<a class="btn nuAddBtn" href="' . h(nu_url('webhooks', ['id' => 'new'])) . '" id="whNew"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>',
	]);
	if ($tab === '') {
		$set = NuWebhook::settings(); ?>
		<form method="post" action="<?= h(nu_url('webhooks')) ?>" class="card shadow-sm mt-4" id="whSettings">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="settings">
			<div class="card-body d-flex flex-wrap align-items-center gap-3">
				<h2 class="h6 mb-0"><i class="bi bi-gear me-1"></i><?= h(t('wh_settings')) ?></h2>
				<div class="form-check form-switch mb-0">
					<input class="form-check-input" type="checkbox" role="switch" name="allow_private" value="1" id="whAllowPrivate"<?= $set['allow_private'] ? ' checked' : '' ?>>
					<label class="form-check-label" for="whAllowPrivate"><?= h(t('wh_allow_private')) ?></label>
				</div>
				<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('wh_allow_private_tip')) ?>" title="<?= h(t('wh_allow_private_tip')) ?>"><i class="bi bi-info-circle"></i></span>
				<button class="btn btn-outline-secondary btn-sm ms-auto" type="submit"><i class="bi bi-check-lg me-1"></i><?= h(t('save')) ?></button>
			</div>
		</form>
	<?php }
	nu_layout_end(NuListView::foot() . nu_ts_foot());
	return;
}

// ------------------------------------------------------------------ one call
if ($call) {
	$d = NuWebhook::get((string)$call['webhook_id']);
	$tries = $db->all('SELECT * FROM ' . NuWebhook::TRY . ' WHERE call_id = ? ORDER BY attempt', [$callId]);
	$body = (string)$call['body'];
	$pretty = json_decode($body, true);
	if (is_array($pretty)) $body = (string)json_encode($pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$stCls = ['sent' => 'text-bg-success', 'failed' => 'text-bg-danger', 'pending' => 'text-bg-warning'];
	$dt = static fn($v) => $v ? date('d.m.Y H:i:s', (int)strtotime((string)$v)) : '';
	nu_layout_start(t('wh_title'), 'home', ['container' => 'container']);
	echo $crumbs([[t('wh_calls'), nu_url('webhooks', ['tab' => 'calls'])], [t('wh_call_title')]]); ?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-send me-2"></i><?= h(($d['name'] ?? '?') . ' · ' . t('wh_ev_' . $call['event'])) ?> <span class="badge <?= $stCls[(string)$call['status']] ?? 'text-bg-secondary' ?> align-middle" id="whCallStatus"><?= h(t('wh_st_' . $call['status'])) ?></span></h1>
		<div class="d-flex flex-wrap gap-2">
			<a class="btn nuBtnAct" href="<?= h(nu_url('webhooks', ['tab' => 'calls'])) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
			<?php if ($d && NuAdmin::can('webhooks', 'change')): ?>
				<form method="post" action="<?= h(nu_url('webhooks', ['call' => $callId])) ?>" data-nu-once><?= nu_csrf_field() ?><input type="hidden" name="action" value="resend">
					<button class="btn nuBtnAct" type="submit" id="whResend" data-nu-busy="<?= h(t('wh_resend')) ?>"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('wh_resend')) ?></button></form>
				<a class="btn nuBtnAct" href="<?= h(nu_url('webhooks', ['id' => $d['id']])) ?>"><i class="bi bi-broadcast me-1"></i><?= h($d['name']) ?></a>
			<?php endif; ?>
		</div>
	</div>
	<div class="row g-4">
		<div class="col-lg-5">
			<div class="card shadow-sm"><div class="card-body small">
				<dl class="row mb-0">
					<dt class="col-5"><?= h(t('wh_time')) ?></dt><dd class="col-7"><?= h($dt($call['created_at'])) ?></dd>
					<dt class="col-5"><?= h(t('wh_form')) ?></dt><dd class="col-7"><?= h((string)(nu_forms()[$call['form_id']]['code'] ?? $call['form_id'])) ?></dd>
					<dt class="col-5"><?= h(t('wh_record')) ?></dt><dd class="col-7 font-monospace text-break"><?= h((string)$call['record_id']) ?></dd>
					<dt class="col-5"><?= h(t('wh_source')) ?></dt><dd class="col-7"><?= h(t('wh_src_' . ((string)$call['source'] ?: 'form'))) ?></dd>
					<dt class="col-5"><?= h(t('user')) ?></dt><dd class="col-7"><?= h((string)$call['created_by']) ?></dd>
					<dt class="col-5"><?= h(t('wh_target')) ?></dt><dd class="col-7 text-break"><?= h($d ? NuWebhook::urlForLog($d['url']) : '–') ?></dd>
					<?php if ((string)$call['status'] === 'pending'): ?><dt class="col-5"><?= h(t('wh_next')) ?></dt><dd class="col-7"><?= h($dt($call['next_at'])) ?></dd><?php endif; ?>
					<?php if ($call['sent_at']): ?><dt class="col-5"><?= h(t('wh_st_sent')) ?></dt><dd class="col-7"><?= h($dt($call['sent_at'])) ?></dd><?php endif; ?>
				</dl>
			</div></div>
			<div class="card shadow-sm mt-4"><div class="card-body">
				<h2 class="h6"><?= h(t('wh_attempts')) ?></h2>
				<?php if (!$tries): ?><p class="small text-body-secondary mb-0"><?= h(t('wh_none_tries')) ?></p><?php else: ?>
				<table class="table nu-table table-sm small mb-0" id="whTries"><thead><tr><th>#</th><th><?= h(t('wh_time')) ?></th><th><?= h(t('wh_answer')) ?></th><th class="text-end"><?= h(t('wh_ms')) ?></th></tr></thead><tbody>
				<?php foreach ($tries as $tr): ?>
					<tr><td><?= (int)$tr['attempt'] ?></td><td class="text-nowrap"><?= h($dt($tr['tried_at'])) ?></td>
						<td class="text-break"><?= (int)$tr['http_code'] ? '<span class="badge ' . ((int)$tr['http_code'] < 300 ? 'text-bg-success' : 'text-bg-danger') . '">' . (int)$tr['http_code'] . '</span> ' : '' ?><?= h((string)$tr['error'] !== 'HTTP ' . (int)$tr['http_code'] ? (string)$tr['error'] : '') ?>
							<?php if ((string)$tr['response'] !== ''): ?><details><summary class="text-body-secondary"><?= h(t('wh_response')) ?></summary><pre class="small mb-0 text-wrap"><?= h((string)$tr['response']) ?></pre></details><?php endif; ?></td>
						<td class="text-end"><?= (int)$tr['ms'] ?></td></tr>
				<?php endforeach; ?></tbody></table>
				<?php endif; ?>
			</div></div>
		</div>
		<div class="col-lg-7"><div class="card shadow-sm"><div class="card-body">
			<h2 class="h6"><?= h(t('wh_body')) ?></h2>
			<pre class="small mb-0 nuWhBody" id="whBody"><?= h($body) ?></pre>
		</div></div></div>
	</div>
	<?php nu_layout_end();
	return;
}

// ------------------------------------------------------------------ edit
$v = static fn(string $k, string $def = '') => nu_is_post() ? (string)($_POST[$k] ?? $def) : (string)($hook[$k] ?? $def);
$selForms = nu_is_post() ? array_map('strval', (array)($_POST['forms'] ?? [])) : ($hook['forms'] ?? []);
$selEvents = nu_is_post() ? array_map('strval', (array)($_POST['events'] ?? [])) : ($hook['events'] ?? ['insert', 'update']);
$active = nu_is_post() ? nu_check('active') === '1' : ($hook ? $hook['active'] : true);
$err = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
$formOpts = array_filter(nu_forms(), static fn($f, $fid) => !str_starts_with((string)$fid, 'nu') && in_array($f['type'], ['browseedit', 'edit', 'browse'], true), ARRAY_FILTER_USE_BOTH);
$title = $hook ? $hook['name'] : t('wh_new');
nu_layout_start(t('wh_title'), 'home', ['container' => 'container', 'head' => nu_ts_head()]);
echo $crumbs([[$title]]); ?>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-broadcast me-2"></i><?= h($title) ?></h1>
	<div class="d-flex flex-wrap gap-2">
		<a class="btn nuBtnAct" href="<?= h(nu_url('webhooks')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<button class="btn nuBtnAct nuBtnSave" type="submit" form="whForm" data-nu-save><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
		<button class="btn nuBtnAct" type="submit" form="whForm" name="do_test" value="1" id="whTest" title="<?= h(t('wh_test_tip')) ?>"><i class="bi bi-send me-1"></i><?= h(t('wh_test')) ?></button>
		<?php if ($hook): ?>
			<a class="btn nuBtnAct" href="<?= h(nu_url('webhooks', ['tab' => 'calls', 'wh' => $id])) ?>" id="whCallsOf"><i class="bi bi-list-ul me-1"></i><?= h(t('wh_calls')) ?></a>
			<form method="post" action="<?= h(nu_url('webhooks', ['id' => $id])) ?>" data-confirm="<?= h(t('wh_delete_confirm', $hook['name'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
				<button class="btn nuBtnAct nuBtnDel" type="submit" id="whDelete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button></form>
		<?php endif; ?>
	</div>
</div>
<?php if ($secret): ?>
	<div class="card border-success shadow-sm mb-4" id="whSecretBox"><div class="card-body">
		<h2 class="h6 text-success"><i class="bi bi-shield-lock me-1"></i><?= h(t('wh_secret')) ?></h2>
		<p class="small mb-2"><?= h(t('wh_secret_once')) ?></p>
		<div class="input-group input-group-sm"><input class="form-control font-monospace" id="whSecret" readonly value="<?= h((string)$secret['secret']) ?>">
			<button class="btn btn-outline-secondary" type="button" id="whSecretCopy" data-copy="#whSecret" title="<?= h(t('copy')) ?>" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button></div>
	</div></div>
<?php endif; ?>
<?php if ($test): ?>
	<div class="alert <?= $test['ok'] ? 'alert-success' : 'alert-danger' ?> small" id="whTestResult">
		<div class="fw-semibold"><?= h($test['ok'] ? t('wh_test_ok', $test['code'], $test['ms']) : t('wh_test_fail', (string)$test['error'])) ?></div>
		<?php if ((string)$test['response'] !== ''): ?><details class="mt-1"><summary><?= h(t('wh_response')) ?></summary><pre class="mb-0 text-wrap"><?= h((string)$test['response']) ?></pre></details><?php endif; ?>
		<details class="mt-1"><summary><?= h(t('wh_body')) ?></summary><pre class="mb-0 text-wrap"><?= h((string)$test['body']) ?></pre></details>
	</div>
<?php endif; ?>
<form method="post" action="<?= h(nu_url('webhooks', ['id' => $hook ? $id : 'new'])) ?>" id="whForm" class="card shadow-sm" novalidate data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<div class="card-body"><div class="row g-3">
		<div class="col-md-8"><label class="form-label" for="whName"><?= h(t('wh_name')) ?></label>
			<input class="form-control<?= $inv('name') ?>" id="whName" name="name" maxlength="100" required value="<?= h($v('name')) ?>"><?= $err('name') ?></div>
		<div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2">
			<input class="form-check-input" type="checkbox" role="switch" id="whActive" name="active" value="1"<?= $active ? ' checked' : '' ?>>
			<label class="form-check-label" for="whActive"><?= h(t('wh_on')) ?></label></div></div>
		<div class="col-12"><label class="form-label" for="whForms"><?= h(t('wh_forms')) ?></label>
			<select class="form-select nuListTomSelect<?= $inv('forms') ?>" id="whForms" name="forms[]" multiple>
				<?php foreach ($formOpts as $fid => $f): ?><option value="<?= h((string)$fid) ?>" data-sub="<?= h($f['description']) ?>"<?= in_array((string)$fid, $selForms, true) ? ' selected' : '' ?>><?= h($f['code']) ?></option><?php endforeach; ?>
			</select><?= $err('forms') ?></div>
		<div class="col-12"><span class="form-label d-block"><?= h(t('wh_events')) ?></span>
			<?php foreach (NuWebhook::EVENTS as $e): ?><div class="form-check form-check-inline">
				<input class="form-check-input" type="checkbox" name="events[]" value="<?= $e ?>" id="whEv_<?= $e ?>"<?= in_array($e, $selEvents, true) ? ' checked' : '' ?>>
				<label class="form-check-label" for="whEv_<?= $e ?>"><?= h(t('wh_ev_' . $e)) ?></label></div><?php endforeach; ?><?= $err('events') ?></div>
		<div class="col-12"><label class="form-label" for="whUrl"><?= h(t('wh_url')) ?></label><?= $tip(t('wh_url_tip')) ?>
			<input class="form-control font-monospace<?= $inv('url') ?>" id="whUrl" name="url" type="url" maxlength="2000" placeholder="https://" required value="<?= h($v('url')) ?>"><?= $err('url') ?></div>
		<?php if ($hook): ?><div class="col-12"><div class="form-check">
			<input class="form-check-input" type="checkbox" name="new_secret" value="1" id="whNewSecret">
			<label class="form-check-label" for="whNewSecret"><?= h(t('wh_secret_new')) ?></label><?= $tip(t('wh_secret_tip')) ?></div></div>
		<?php else: ?><div class="col-12 small text-body-secondary"><i class="bi bi-shield-lock me-1"></i><?= h(t('wh_secret_tip')) ?></div><?php endif; ?>
		<div class="col-12"><label class="form-label" for="whFields"><?= h(t('wh_fields')) ?></label><?= $tip(t('wh_fields_tip')) ?>
			<input class="form-control font-monospace" id="whFields" name="fields" maxlength="2000" placeholder="<?= h(t('wh_fields_ph')) ?>" value="<?= h($v('fields')) ?>"></div>
		<div class="col-12"><label class="form-label" for="whTemplate"><?= h(t('wh_template')) ?></label><?= $tip(t('wh_template_tip')) ?>
			<textarea class="form-control font-monospace<?= $inv('template') ?>" id="whTemplate" name="template" rows="7" spellcheck="false" placeholder="<?= h(t('wh_template_ph')) ?>"><?= h($v('template')) ?></textarea><?= $err('template') ?>
			<div class="form-text"><?= h(t('wh_vars')) ?> <?php foreach (NuWebhook::VARS as $var): ?><code class="me-1">#<?= $var ?>#</code><?php endforeach; ?><code>#&lt;column&gt;#</code></div></div>
	</div></div>
</form>
<?php nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nuwebhooks.js') . '"></script>');
