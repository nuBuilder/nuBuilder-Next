<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Settings → Security → IP access (0.8.722, the globeadmin): from which addresses users may sign in and work – a default list for all
 * access levels, exceptions per level (allow all / own ranges) and the globeadmin's own rule (core/nuipaccess.php NuIpAccess).
 * POST action=save (password confirmed recently; refused when the new rules would lock out the globeadmin's current address).
 * GET ?test=<address> shows who could work from that address.
 */

$u = NuAuth::user();
if (!$u || empty($u['globeadmin']) || !empty($u['demo'])) {
	http_response_code(403);
	nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true);
}

$levels = NuIpAccess::accessLevels();
$levelIds = array_column($levels, 'id');
$s = NuIpAccess::settings();
$errors = [];
$form = null;              // posted values shown again after an error

if (nu_is_post() && nu_post('action') === 'save') {
	NuSecurity::requireRecentAuth(nu_url('ipaccess'));
	$bad = static function (string $field, array $wrong) use (&$errors): void {
		if ($wrong) $errors[$field] = t('ipa_err_rule', implode(', ', array_slice($wrong, 0, 5)));
	};
	[$def, $wrong] = NuIpAccess::parse((string)nu_post('default'));
	$bad('default', $wrong);
	$gaMode = in_array(nu_post('ga_mode'), NuIpAccess::GA_MODES, true) ? (string)nu_post('ga_mode') : 'default';
	[$gaRanges, $wrong] = NuIpAccess::parse((string)nu_post('ga_ranges'));
	$bad('ga_ranges', $wrong);
	if ($gaMode === 'ranges' && !$gaRanges && !isset($errors['ga_ranges'])) $errors['ga_ranges'] = t('ipa_err_empty');
	$new = ['on' => nu_check('on') === '1', 'default' => $def, 'levels' => [], 'ga' => ['mode' => $gaMode, 'ranges' => $gaRanges]];
	$modes = is_array($_POST['lv_mode'] ?? null) ? $_POST['lv_mode'] : [];
	$texts = is_array($_POST['lv_ranges'] ?? null) ? $_POST['lv_ranges'] : [];
	foreach ($levelIds as $id) {
		$m = (string)($modes[$id] ?? 'default');
		if ($m === 'any') $new['levels'][$id] = ['mode' => 'any', 'ranges' => []];
		elseif ($m === 'ranges') {
			[$r, $wrong] = NuIpAccess::parse((string)($texts[$id] ?? ''));
			$bad('lv_' . $id, $wrong);
			if (!$r && !isset($errors['lv_' . $id])) $errors['lv_' . $id] = t('ipa_err_empty');
			$new['levels'][$id] = ['mode' => 'ranges', 'ranges' => $r];
		}
	}
	// the globeadmin saving must not lock himself out
	if (!$errors && $new['on'] && !NuIpAccess::gaAllows(NuIpAccess::ip(), NuIpAccess::clean($new))) $errors['_self'] = t('ipa_err_self', NuIpAccess::ip());
	if ($errors) {
		$form = $_POST;
		nu_flash('danger', t('err_check_form'));
	} else {
		$before = $s;
		$saved = NuIpAccess::save($new, (string)$u['login']);
		if ($saved !== $before) NuLog::write('ip_access_changed', 'zzzzsys_nunext_setting', NuIpAccess::KEY, ['on' => $saved['on'] ? 1 : 0,
			'default' => implode(' ', $saved['default']), 'exceptions' => count($saved['levels']), 'ga' => $saved['ga']['mode']]);
		nu_flash('success', $saved !== $before ? t('ipa_saved') : t('set_saved_none'));
		nu_redirect(nu_url('ipaccess'));
	}
}

$mine = NuIpAccess::ip();
$test = isset($_GET['test']) ? NuIpAccess::test(trim((string)$_GET['test'])) : null;
$proxyHint = !nu_cfg('nuConfigTrustProxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR']);
$tip = static fn(string $text): string => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$inv = static fn(string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
// values shown: the posted ones after an error, else the stored ones
$val = static fn(array $rules): string => implode("\n", $rules);
$on = $form ? !empty($form['on']) : $s['on'];
$defText = $form ? (string)($form['default'] ?? '') : $val($s['default']);
$gaMode = $form ? (string)($form['ga_mode'] ?? 'default') : $s['ga']['mode'];
$gaText = $form ? (string)($form['ga_ranges'] ?? '') : $val($s['ga']['ranges']);
$lvMode = static fn(string $id): string => $form ? (string)($form['lv_mode'][$id] ?? 'default') : (string)($s['levels'][$id]['mode'] ?? 'default');
$lvText = static fn(string $id): string => $form ? (string)($form['lv_ranges'][$id] ?? '') : $val($s['levels'][$id]['ranges'] ?? []);
$nExc = count(array_filter($levelIds, static fn($id) => $lvMode($id) !== 'default'));

nu_layout_start(t('ipa_title'), 'home', ['container' => 'nuContainer', 'settings' => 'security', 'settings_page' => 'ipaccess']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item"><a href="<?= h(nu_url('settings')) ?>"><?= h(t('sh_title')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h(t('ipa_title')) ?></li>
</ol></nav>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><i class="bi bi-router me-2"></i><?= h(t('ipa_title')) ?></h1>
	<div class="nuTitleSub text-body-secondary small"><?= h(t('ipa_hint')) ?></div>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?>
		<button class="btn nuBtnAct nuBtnSave" type="submit" form="ipaForm" data-nu-save><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button></div>
</div>

<?php if (isset($errors['_self'])): ?>
	<div class="alert alert-danger d-flex gap-2" role="alert" id="ipaSelfErr"><i class="bi bi-shield-exclamation mt-1"></i><div><?= h($errors['_self']) ?></div></div>
<?php endif; ?>

<form method="post" action="<?= h(nu_url('ipaccess')) ?>" id="ipaForm" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">

	<div class="card shadow-sm mb-3" id="ipaMain">
		<div class="card-body">
			<div class="form-check form-switch mb-2">
				<input class="form-check-input" type="checkbox" role="switch" id="ipaOn" name="on" value="1"<?= $on ? ' checked' : '' ?>>
				<label class="form-check-label fw-semibold" for="ipaOn"><?= h(t('ipa_on')) ?></label><?= $tip(t('ipa_on_tip')) ?>
			</div>
			<div class="small"><?= h(t('ipa_mine')) ?> <code id="ipaMine"><?= h($mine) ?></code></div>
			<?php if ($proxyHint): ?><div class="alert alert-warning py-2 px-3 small mt-2 mb-0" id="ipaProxy"><?= h(t('ipa_proxy_hint')) ?></div><?php endif; ?>
		</div>
	</div>

	<div class="card shadow-sm mb-3" id="ipaDefaultCard">
		<div class="card-header fw-semibold"><?= h(t('ipa_default')) ?><?= $tip(t('ipa_rules_tip')) ?></div>
		<div class="card-body">
			<label class="form-label small text-body-secondary" for="ipaDefault"><?= h(t('ipa_default_d')) ?></label>
			<textarea class="form-control font-monospace<?= $inv('default') ?>" id="ipaDefault" name="default" rows="4" spellcheck="false" placeholder="10.0.0.0/8&#10;203.0.113.7"><?= h($defText) ?></textarea>
			<?= $err('default') ?>
		</div>
	</div>

	<div class="card shadow-sm mb-3" id="ipaGaCard">
		<div class="card-header fw-semibold"><?= h(t('ipa_ga')) ?><?= $tip(t('ipa_ga_tip')) ?></div>
		<div class="card-body">
			<div class="row g-2 align-items-start">
				<div class="col-sm-4">
					<label class="visually-hidden" for="ipaGaMode"><?= h(t('ipa_ga')) ?></label>
					<select class="form-select ipaMode" id="ipaGaMode" name="ga_mode" data-ipa-target="ipaGaRanges">
						<?php foreach (NuIpAccess::GA_MODES as $m): ?><option value="<?= $m ?>"<?= $gaMode === $m ? ' selected' : '' ?>><?= h(t('ipa_mode_' . $m)) ?></option><?php endforeach; ?>
					</select>
				</div>
				<div class="col-sm-8" id="ipaGaRangesWrap"<?= $gaMode === 'ranges' ? '' : ' hidden' ?>>
					<label class="visually-hidden" for="ipaGaRanges"><?= h(t('ipa_ranges')) ?></label>
					<textarea class="form-control font-monospace<?= $inv('ga_ranges') ?>" id="ipaGaRanges" name="ga_ranges" rows="3" spellcheck="false"><?= h($gaText) ?></textarea>
					<?= $err('ga_ranges') ?>
				</div>
			</div>
		</div>
	</div>

	<div class="card shadow-sm mb-3" id="ipaLevelsCard">
		<div class="card-header d-flex flex-wrap gap-2 align-items-center">
			<span class="fw-semibold me-auto"><?= h(t('ipa_levels')) ?><?= $tip(t('ipa_levels_tip')) ?>
				<span class="badge rounded-pill text-bg-secondary ms-1" id="ipaExcCount"><?= $nExc ?></span></span>
			<?php if (count($levels) > 8): ?>
				<input type="search" class="form-control form-control-sm nuIpaFilter" id="ipaLvFilter" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('search')) ?>">
			<?php endif; ?>
		</div>
		<div class="table-responsive">
			<table class="table nu-table align-middle mb-0" id="ipaLevels">
				<thead><tr><th><?= h(t('ipa_level')) ?></th><th class="nuIpaModeCol"><?= h(t('ipa_rule')) ?></th><th><?= h(t('ipa_ranges')) ?></th></tr></thead>
				<tbody>
				<?php foreach ($levels as $l): $id = $l['id']; $m = $lvMode($id); $fid = 'ipaLv_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $id); ?>
					<tr data-ipa-level="<?= h($id) ?>" data-ipa-text="<?= h(mb_strtolower($l['code'] . ' ' . $l['name'] . ' ' . $l['group'])) ?>">
						<td><span class="fw-semibold"><?= h($l['code']) ?></span><?php if ($l['name'] !== ''): ?><div class="small text-body-secondary"><?= h($l['name']) ?></div><?php endif; ?></td>
						<td><label class="visually-hidden" for="<?= h($fid) ?>"><?= h(t('ipa_rule') . ' ' . $l['code']) ?></label>
							<select class="form-select form-select-sm ipaMode" id="<?= h($fid) ?>" name="lv_mode[<?= h($id) ?>]" data-ipa-target="<?= h($fid) ?>_r">
								<?php foreach (NuIpAccess::LEVEL_MODES as $mm): ?><option value="<?= $mm ?>"<?= $m === $mm ? ' selected' : '' ?>><?= h(t('ipa_lmode_' . $mm)) ?></option><?php endforeach; ?>
							</select></td>
						<td><div id="<?= h($fid) ?>_rWrap"<?= $m === 'ranges' ? '' : ' hidden' ?>>
							<label class="visually-hidden" for="<?= h($fid) ?>_r"><?= h(t('ipa_ranges') . ' ' . $l['code']) ?></label>
							<textarea class="form-control form-control-sm font-monospace<?= $inv('lv_' . $id) ?>" id="<?= h($fid) ?>_r" name="lv_ranges[<?= h($id) ?>]" rows="2" spellcheck="false"><?= h($lvText($id)) ?></textarea>
							<?= $err('lv_' . $id) ?></div></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</form>

<div class="card shadow-sm mb-3" id="ipaTestCard">
	<div class="card-header fw-semibold"><?= h(t('ipa_test')) ?><?= $tip(t('ipa_test_tip')) ?></div>
	<div class="card-body">
		<form method="get" action="<?= h(nu_url('ipaccess')) ?>" class="d-flex flex-wrap gap-2 mb-2" id="ipaTestForm">
			<input type="hidden" name="p" value="ipaccess">
			<label class="visually-hidden" for="ipaTestIp"><?= h(t('ipa_test')) ?></label>
			<input class="form-control font-monospace nuIpaTestIp" id="ipaTestIp" name="test" value="<?= h($test['ip'] ?? $mine) ?>" spellcheck="false">
			<button class="btn nuBtnAct" type="submit" id="ipaTestBtn"><i class="bi bi-play me-1"></i><?= h(t('ipa_test_run')) ?></button>
		</form>
		<?php if ($test): ?>
			<div id="ipaTestResult">
			<?php if (!$test['valid']): ?>
				<div class="text-danger small"><?= h(t('ipa_test_invalid')) ?></div>
			<?php else: ?>
				<?php if (!$s['on']): ?><div class="small text-body-secondary mb-1"><?= h(t('ipa_test_off')) ?></div><?php endif; ?>
				<div class="mb-1"><i class="bi <?= $test['ga'] ? 'bi-check-circle-fill text-success' : 'bi-x-octagon-fill text-danger' ?> me-1" aria-hidden="true"></i>
					<?= h(t('ipa_ga')) ?>: <span id="ipaTestGa" data-ok="<?= $test['ga'] ? '1' : '0' ?>"><?= h(t($test['ga'] ? 'ipa_allowed' : 'ipa_refused')) ?></span></div>
				<div class="mb-1"><i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i><?= h(t('ipa_test_allowed', count($test['allowed']))) ?>
					<span class="small text-body-secondary" id="ipaTestAllowed"><?= h(implode(', ', array_column($test['allowed'], 'code'))) ?></span></div>
				<div><i class="bi bi-x-octagon-fill text-danger me-1" aria-hidden="true"></i><?= h(t('ipa_test_refused', count($test['refused']))) ?>
					<span class="small text-body-secondary" id="ipaTestRefused"><?= h(implode(', ', array_column($test['refused'], 'code'))) ?></span></div>
				<div class="small text-body-secondary mt-1"><?= h(t('ipa_test_note')) ?></div>
			<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

<div class="small text-body-secondary mb-4" id="ipaCli"><i class="bi bi-terminal me-1" aria-hidden="true"></i><?= h(t('ipa_cli')) ?> <code>php cron.php iprules off</code></div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuipaccess.js') . '"></script>');
