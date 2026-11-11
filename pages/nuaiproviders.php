<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * AI providers (0.8.1099, core/nuaiprovider.php): list · ?id=new | ?id=<provider> edit (Save, Test, Fetch models, usage of the month,
 * the last requests). Admin right "aiproviders" (full access: API keys, and prompts leave the server). The key is never shown again.
 */
NuAdmin::require('aiproviders');
NuSecurity::requireRecentAuth(nu_url('aiproviders'));
NuSchema::ensure();

$id = (string)($_GET['id'] ?? '');
$prov = $id !== '' && $id !== 'new' ? NuAiProvider::get($id) : null;
if ($id !== '' && $id !== 'new' && !$prov) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('aiproviders')); }
$errors = [];

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'settings') {
		NuAiProvider::setSettings(nu_check('allow_private') === '1');
		nu_flash('success', t('saved'));
		nu_redirect(nu_url('aiproviders'));
	}
	if ($action === 'models') {                                      // JSON: the models of the service, with the posted (unsaved) values
		header('Content-Type: application/json; charset=utf-8');
		$kind = nu_post('kind');
		$stored = $prov ? NuAiProvider::apiKey($prov) : '';             // null = encrypted with the password and still locked
		$d = ['id' => $id, 'kind' => isset(NuAiProvider::KINDS[$kind]) ? $kind : 'openai', 'base_url' => trim(nu_post('base_url')),
			'api_key' => 'plain:' . (trim(nu_post('api_key')) !== '' ? trim(nu_post('api_key')) : (string)$stored)];
		$e = $d['base_url'] !== '' ? NuAiProvider::checkUrl($d['base_url'], false) : '';
		if ($e === '' && $stored === null && trim(nu_post('api_key')) === '') $e = t('aip_err_locked');
		[$models, $err] = $e !== '' ? [[], $e] : NuAiProvider::models($d);
		echo json_encode(['ok' => $err === '', 'models' => $models, 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	}
	if ($action === 'save') {
		$in = ['name' => nu_post('name'), 'kind' => nu_post('kind'), 'base_url' => nu_post('base_url'), 'api_key' => nu_post('api_key'), 'model' => nu_post('model'),
			'temperature' => nu_post('temperature'), 'max_tokens' => nu_post('max_tokens'), 'timeout' => nu_post('timeout'), 'system' => nu_post('system'), 'params' => nu_post('params'),
			'active' => nu_check('active') === '1', 'access' => nu_post('access'), 'levels' => (array)($_POST['levels'] ?? []), 'password' => (string)($_POST['password'] ?? ''),
			'month_limit' => nu_post('month_limit')];
		[$newId, $errors] = NuAiProvider::save($in, $prov ? $id : '');
		if (!$errors) {
			if (nu_post('do_test') === '1') {
				$r = NuAiProvider::test((array)NuAiProvider::get($newId));
				$_SESSION['nu_aip_test'] = ['id' => $newId] + $r;
			} else {
				nu_flash('success', t('saved'));
			}
			nu_redirect(nu_url('aiproviders', ['id' => $newId]));
		}
		nu_flash('danger', t('err_check_form'));
	}
	if ($prov && $action === 'unlock') {                              // the password of a protected configuration, for this session
		$e = NuAiProvider::unlock($id, (string)($_POST['unlock_password'] ?? ''), true);
		nu_flash($e === '' ? 'success' : 'danger', $e === '' ? t('aip_unlocked') : $e);
		nu_redirect(nu_url('aiproviders', ['id' => $id]));
	}
	if ($prov && $action === 'delete') {
		NuAiProvider::delete($id);
		nu_flash('success', t('aip_deleted', $prov['name']));
		nu_redirect(nu_url('aiproviders'));
	}
}
$test = $_SESSION['nu_aip_test'] ?? null;
if ($test && (string)$test['id'] !== $id) $test = null;
unset($_SESSION['nu_aip_test']);
$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';

// ------------------------------------------------------------------ list
if ($id === '') {
	$st = NuListView::prepare('aiproviders');
	nu_layout_start(t('aip_title'), 'home', ['head' => NuListView::head()]);
	echo NuListView::crumbs([[t('aip_title')]]);
	if (!function_exists('curl_init')) echo '<div class="alert alert-warning small">' . h(t('aip_err_curl')) . '</div>';
	if ((string)nu_cfg('nuConfigSecretKey') === '') echo '<p class="small text-body-secondary nuKeyHint mb-2"><i class="bi bi-key me-1"></i>' . h(t('aip_no_key')) . ' <a href="' . h(nu_url('securitycheck')) . '">' . h(t('secchk_title')) . '</a></p>';
	echo NuListView::card($st, ['title_extra' => NuListView::infoTip(t('aip_hint')),
		'buttons' => '<a class="btn nuAddBtn" href="' . h(nu_url('aiproviders', ['id' => 'new'])) . '" id="aipNew"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>']);
	$set = NuAiProvider::settings(); ?>
	<form method="post" action="<?= h(nu_url('aiproviders')) ?>" class="card shadow-sm mt-4" id="aipSettings">
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="settings">
		<div class="card-body d-flex flex-wrap align-items-center gap-3">
			<h2 class="h6 mb-0"><i class="bi bi-gear me-1"></i><?= h(t('wh_settings')) ?></h2>
			<div class="form-check form-switch mb-0">
				<input class="form-check-input js-autosubmit" type="checkbox" role="switch" name="allow_private" value="1" id="aipAllowPrivate"<?= $set['allow_private'] ? ' checked' : '' ?>>
				<label class="form-check-label" for="aipAllowPrivate"><?= h(t('aip_allow_private')) ?></label>
			</div><?= $tip(t('aip_allow_private_tip')) ?>
		</div>
	</form>
	<?php nu_layout_end(NuListView::foot());
	return;
}

// ------------------------------------------------------------------ edit
$v = static fn(string $k, string $def = '') => nu_is_post() ? (string)($_POST[$k] ?? $def) : (string)($prov[$k] ?? $def);
$active = nu_is_post() ? nu_check('active') === '1' : ($prov ? $prov['active'] : true);
$kind = $v('kind', 'anthropic');
if (!isset(NuAiProvider::KINDS[$kind])) $kind = 'anthropic';
$access = $v('access', 'all');
$selLevels = nu_is_post() ? array_map('strval', (array)($_POST['levels'] ?? [])) : ($prov['levels'] ?? []);
$err = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$levels = nu_db()->all('SELECT zzzzsys_access_id id, sal_code code, sal_description d FROM zzzzsys_access ORDER BY sal_code');
$title = $prov ? $prov['name'] : t('aip_new');
$num = static fn($n) => number_format((int)$n, 0, '.', "'");
$kinds = [];
foreach (NuAiProvider::KINDS as $k => [$tk, $url, $model]) $kinds[$k] = ['url' => $url, 'model' => $model];
nu_layout_start(t('aip_title'), 'home', ['container' => 'container', 'head' => nu_ts_head()]);
echo NuListView::crumbs([[t('aip_title'), nu_url('aiproviders')], [$title]]); ?>
<div class="nuTitleBar nuRecHead mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-stars me-2"></i><?= h($title) ?></h1>
	<div class="d-flex flex-wrap gap-2">
		<a class="btn nuBtnAct" href="<?= h(nu_url('aiproviders')) ?>"><i class="bi bi-arrow-left me-1"></i><?= h(t('back')) ?></a>
		<button class="btn nuBtnAct nuBtnSave" type="submit" form="aipForm" data-nu-save><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
		<button class="btn nuBtnAct" type="submit" form="aipForm" name="do_test" value="1" id="aipTest" title="<?= h(t('aip_test_tip')) ?>"><i class="bi bi-send me-1"></i><?= h(t('aip_test')) ?></button>
		<?php if ($prov): ?>
			<form method="post" action="<?= h(nu_url('aiproviders', ['id' => $id])) ?>" data-confirm="<?= h(t('aip_delete_confirm', $prov['name'])) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete">
				<button class="btn nuBtnAct nuBtnDel" type="submit" id="aipDelete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button></form>
		<?php endif; ?>
	</div>
</div>
<?php if ($test): ?>
	<div class="alert <?= $test['ok'] && $test['error'] === '' ? 'alert-success' : 'alert-danger' ?> small" id="aipTestResult">
		<div class="fw-semibold"><?= h($test['ok'] && $test['error'] === '' ? t('aip_test_ok', $test['ms'], $test['in'], $test['out']) : t('aip_test_fail', (string)$test['error'])) ?></div>
		<?php if ((string)$test['text'] !== ''): ?><div class="mt-1 text-break"><?= h(mb_substr((string)$test['text'], 0, 300)) ?></div><?php endif; ?>
	</div>
<?php endif; ?>
<?php if ($prov && $prov['access'] === 'password'): $isOpen = NuAiProvider::unlocked($prov); ?>
	<form method="post" action="<?= h(nu_url('aiproviders', ['id' => $id])) ?>" class="alert <?= $isOpen ? 'alert-success' : 'alert-warning' ?> small d-flex flex-wrap align-items-center gap-2" id="aipLock" data-open="<?= $isOpen ? '1' : '0' ?>" autocomplete="off">
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="unlock">
		<i class="bi <?= $isOpen ? 'bi-unlock' : 'bi-lock' ?>" aria-hidden="true"></i><span class="me-auto"><?= h(t($isOpen ? 'aip_unlocked' : 'aip_locked_note')) ?></span>
		<?php if (!$isOpen): ?><div class="input-group input-group-sm nuAiAskPwBox"><input class="form-control" type="password" name="unlock_password" id="aipUnlockPw" autocomplete="off" aria-label="<?= h(t('aip_password')) ?>" placeholder="<?= h(t('aip_password')) ?>" required>
			<button class="btn btn-outline-primary" type="submit" id="aipUnlock"><i class="bi bi-unlock me-1"></i><?= h(t('aip_unlock')) ?></button></div><?php endif; ?>
	</form>
<?php endif; ?>
<div class="row g-4">
<div class="col-lg-8">
<form method="post" action="<?= h(nu_url('aiproviders', ['id' => $prov ? $id : 'new'])) ?>" id="aipForm" class="card shadow-sm" novalidate data-nu-guard autocomplete="off"
	data-kinds="<?= h((string)json_encode($kinds)) ?>" data-msg="<?= h((string)json_encode(['none' => t('aip_models_none'), 'n' => t('aip_models_n')])) ?>">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<div class="card-body"><div class="row g-3">
		<div class="col-md-8"><label class="form-label" for="aipName"><?= h(t('aip_name')) ?></label>
			<input class="form-control<?= $inv('name') ?>" id="aipName" name="name" maxlength="100" required value="<?= h($v('name')) ?>"><?= $err('name') ?></div>
		<div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2">
			<input class="form-check-input" type="checkbox" role="switch" id="aipActive" name="active" value="1"<?= $active ? ' checked' : '' ?>>
			<label class="form-check-label" for="aipActive"><?= h(t('wh_on')) ?></label></div></div>
		<div class="col-md-5"><label class="form-label" for="aipKind"><?= h(t('aip_kind')) ?></label><?= $tip(t('aip_kind_tip')) ?>
			<select class="form-select<?= $inv('kind') ?>" id="aipKind" name="kind"><?php foreach (NuAiProvider::KINDS as $k => [$tk]): ?><option value="<?= $k ?>"<?= $k === $kind ? ' selected' : '' ?>><?= h(t($tk)) ?></option><?php endforeach; ?></select><?= $err('kind') ?></div>
		<div class="col-md-7"><label class="form-label" for="aipUrl"><?= h(t('aip_url')) ?></label><?= $tip(t('aip_url_tip')) ?>
			<input class="form-control font-monospace<?= $inv('base_url') ?>" id="aipUrl" name="base_url" type="url" maxlength="500" placeholder="<?= h(NuAiProvider::KINDS[$kind][1]) ?>" value="<?= h($v('base_url')) ?>"><?= $err('base_url') ?></div>
		<div class="col-12"><label class="form-label" for="aipKey"><?= h(t('aip_key')) ?></label><?= $tip(t('aip_key_tip')) ?>
			<input class="form-control font-monospace" id="aipKey" name="api_key" type="password" autocomplete="new-password" maxlength="500" placeholder="<?= h($prov && NuAiProvider::hasKey($prov) ? t('aip_key_keep') : '') ?>" value=""><?= $err('api_key') ?></div>
		<div class="col-md-8"><label class="form-label" for="aipModel"><?= h(t('aip_model')) ?></label><?= $tip(t('aip_model_tip')) ?>
			<div class="input-group"><input class="form-control font-monospace<?= $inv('model') ?>" id="aipModel" name="model" maxlength="200" list="aipModels" placeholder="<?= h(NuAiProvider::KINDS[$kind][2]) ?>" required value="<?= h($v('model')) ?>" spellcheck="false">
				<button class="btn btn-outline-secondary" type="button" id="aipFetch" data-url="<?= h(nu_url('aiproviders', ['id' => $prov ? $id : 'new'])) ?>"><i class="bi bi-cloud-download me-1"></i><?= h(t('aip_models_fetch')) ?></button></div>
			<datalist id="aipModels"></datalist><?= $err('model') ?><div class="form-text" id="aipFetchMsg" aria-live="polite"></div></div>
		<div class="col-md-4"><label class="form-label" for="aipTemp"><?= h(t('aip_temperature')) ?></label><?= $tip(t('aip_temperature_tip')) ?>
			<input class="form-control<?= $inv('temperature') ?>" id="aipTemp" name="temperature" inputmode="decimal" maxlength="5" placeholder="<?= h(t('aip_default')) ?>" value="<?= h($v('temperature')) ?>"><?= $err('temperature') ?></div>
		<div class="col-md-4"><label class="form-label" for="aipMax"><?= h(t('aip_max_tokens')) ?></label><?= $tip(t('aip_max_tokens_tip')) ?>
			<input class="form-control<?= $inv('max_tokens') ?>" id="aipMax" name="max_tokens" type="number" min="0" step="1" placeholder="<?= h(t('aip_default')) ?>" value="<?= h((int)$v('max_tokens') > 0 ? $v('max_tokens') : '') ?>"><?= $err('max_tokens') ?></div>
		<div class="col-md-4"><label class="form-label" for="aipTimeout"><?= h(t('aip_timeout')) ?></label><?= $tip(t('aip_timeout_tip')) ?>
			<input class="form-control<?= $inv('timeout') ?>" id="aipTimeout" name="timeout" type="number" min="10" max="900" step="1" value="<?= h($v('timeout', '180')) ?>"><?= $err('timeout') ?></div>
		<div class="col-md-4"><label class="form-label" for="aipLimit"><?= h(t('aip_month_limit')) ?></label><?= $tip(t('aip_month_limit_tip')) ?>
			<input class="form-control" id="aipLimit" name="month_limit" type="number" min="0" step="1000" placeholder="<?= h(t('aip_no_limit')) ?>" value="<?= h((int)$v('month_limit') > 0 ? $v('month_limit') : '') ?>"></div>
		<div class="col-12"><label class="form-label" for="aipSystem"><?= h(t('aip_system')) ?></label><?= $tip(t('aip_system_tip')) ?>
			<textarea class="form-control" id="aipSystem" name="system" rows="2"><?= h($v('system')) ?></textarea></div>
		<div class="col-12"><label class="form-label" for="aipParams"><?= h(t('aip_params')) ?></label><?= $tip(t('aip_params_tip')) ?>
			<textarea class="form-control font-monospace small<?= $inv('params') ?>" id="aipParams" name="params" rows="3" spellcheck="false" placeholder='{"top_p": 0.9}'><?= h($v('params')) ?></textarea><?= $err('params') ?></div>
		<div class="col-12"><hr class="my-1"></div>
		<div class="col-12"><span class="form-label d-block"><?= h(t('aip_access')) ?><?= $tip(t('aip_access_tip')) ?></span><?= $err('access') ?>
			<?php foreach (NuAiProvider::ACCESS as $a): ?><div class="form-check">
				<input class="form-check-input aipAccess" type="radio" name="access" value="<?= $a ?>" id="aipAcc_<?= $a ?>"<?= $access === $a ? ' checked' : '' ?>>
				<label class="form-check-label" for="aipAcc_<?= $a ?>"><?= h(t('aip_access_' . $a)) ?></label></div><?php endforeach; ?></div>
		<div class="col-12" id="aipLevelsBox"<?= $access === 'levels' ? '' : ' hidden' ?>><label class="form-label" for="aipLevels"><?= h(t('nav_access')) ?></label>
			<select class="form-select nuListTomSelect<?= $inv('levels') ?>" id="aipLevels" name="levels[]" multiple>
				<?php foreach ($levels as $l): ?><option value="<?= h((string)$l['id']) ?>" data-sub="<?= h((string)$l['d']) ?>"<?= in_array((string)$l['id'], $selLevels, true) ? ' selected' : '' ?>><?= h((string)$l['code']) ?></option><?php endforeach; ?>
			</select><?= $err('levels') ?></div>
		<div class="col-md-6" id="aipPwBox"<?= $access === 'password' ? '' : ' hidden' ?>><label class="form-label" for="aipPw"><?= h(t('aip_password')) ?></label><?= $tip(t('aip_password_tip', NuAiProvider::PW_MIN)) ?>
			<input class="form-control<?= $inv('password') ?>" id="aipPw" name="password" type="password" autocomplete="new-password" maxlength="200" minlength="<?= NuAiProvider::PW_MIN ?>" placeholder="<?= h($prov && $prov['access'] === 'password' ? t('aip_key_keep') : '') ?>"><?= $err('password') ?></div>
	</div></div>
</form>
</div>
<div class="col-lg-4">
	<?php if ($prov): $u = NuAiProvider::monthUsage($id); $calls = NuAiProvider::lastCalls($id, 15); ?>
	<div class="card shadow-sm mb-4" id="aipUsage"><div class="card-body small">
		<h2 class="h6"><i class="bi bi-bar-chart me-1"></i><?= h(t('aip_usage_month')) ?></h2>
		<dl class="row mb-0">
			<dt class="col-7"><?= h(t('aip_calls')) ?></dt><dd class="col-5 text-end" id="aipUsageCalls"><?= $num($u['calls']) ?><?= $u['errors'] ? ' <span class="text-danger">(' . $num($u['errors']) . ' ✕)</span>' : '' ?></dd>
			<dt class="col-7"><?= h(t('aip_tokens_in')) ?></dt><dd class="col-5 text-end"><?= $num($u['tokens_in']) ?></dd>
			<dt class="col-7"><?= h(t('aip_tokens_out')) ?></dt><dd class="col-5 text-end"><?= $num($u['tokens_out']) ?></dd>
			<?php if ($prov['month_limit'] > 0): ?><dt class="col-7"><?= h(t('aip_month_limit')) ?></dt><dd class="col-5 text-end"><?= $num($prov['month_limit']) ?></dd><?php endif; ?>
		</dl>
	</div></div>
	<div class="card shadow-sm"><div class="card-body small">
		<h2 class="h6"><i class="bi bi-list-ul me-1"></i><?= h(t('aip_last_calls')) ?><?= $tip(t('aip_last_calls_tip')) ?></h2>
		<?php if (!$calls): ?><p class="text-body-secondary mb-0"><?= h(t('aip_no_calls')) ?></p><?php else: ?>
		<table class="table table-sm nu-table mb-0" id="aipCalls"><tbody>
		<?php foreach ($calls as $c): ?><tr<?= (string)$c['status'] === 'error' ? ' class="table-danger"' : '' ?> title="<?= h((string)$c['error']) ?>">
			<td class="text-nowrap"><?= h(date(nu_dfmt('d.m. H:i'), (int)strtotime((string)$c['created_at']))) ?></td><td class="text-break"><?= h((string)$c['user_login']) ?></td>
			<td><?= h(t(NuAiProvider::FEATURES[(string)$c['feature']] ?? 'aip_f_test')) ?></td><td class="text-end text-nowrap"><?= $num((int)$c['tokens_in'] + (int)$c['tokens_out']) ?></td></tr>
		<?php endforeach; ?></tbody></table><?php endif; ?>
	</div></div>
	<?php else: ?><div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i><?= h(t('aip_new_hint')) ?></div><?php endif; ?>
</div>
</div>
<?php nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nuaiproviders.js') . '"></script>');
