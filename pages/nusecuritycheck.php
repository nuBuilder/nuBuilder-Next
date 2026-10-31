<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Admin tools → Security check (globeadmin, 0.8.207): the checks of NuSecurity::checks() as traffic lights, a web test of package
 * files that must never be served (in the browser: assets/js/nusecuritycheck.js), and the security settings (NuSecurity).
 * POST action=save (password confirmed recently).
 */

$u = NuAuth::user();
NuAdmin::require('securitycheck');

// one-time login tokens of all users removed (0.8.339)
if (nu_is_post() && nu_post('action') === 'clear_tokens') {
	NuSecurity::requireRecentAuth(nu_url('securitycheck'));
	$n = NuLoginToken::clearAll();
	nu_flash('success', t('secchk_logintoken_cleared', $n));
	nu_redirect(nu_url('securitycheck') . '#sec_logintoken');
}

if (nu_is_post() && nu_post('action') === 'save') {
	NuSecurity::requireRecentAuth(nu_url('securitycheck'));
	$in = [];
	foreach (NuSecurity::DEFAULTS as $k => $d) $in[$k] = is_bool($d) ? nu_check($k) === '1' : (int)nu_post($k);
	$changed = NuSecurity::save($in);
	if ($changed) NuLog::write('security_saved', 'zzzzsys_nunext_setting', NuSecurity::SETTING, ['fields' => array_map(static fn($c) => [json_encode($c[0]), json_encode($c[1])], $changed)]);
	nu_flash('success', $changed ? t('secchk_saved') : t('set_saved_none'));
	nu_redirect(nu_url('securitycheck', ['tab' => 'settings']) . '#settings');
}

$checks = NuSecurity::checks();
$count = static fn(string $st) => count(array_filter($checks, static fn($c) => $c['status'] === $st));
$set = NuSecurity::settings();
$icon = ['ok' => 'bi-check-circle-fill text-success', 'warn' => 'bi-exclamation-triangle-fill text-warning', 'bad' => 'bi-x-octagon-fill text-danger', 'info' => 'bi-info-circle-fill text-info'];
$tip = static fn(string $text): string => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';

$secTab = ($_GET['tab'] ?? '') === 'settings' ? 'settings' : 'checks';
nu_layout_start(t('secchk_title'), 'home', ['container' => 'nuContainer', 'settings' => 'security']);
?>
<?= nu_page_header(t('secchk_title'), ['icon' => 'bi-shield-check', 'sub' => t('secchk_hint'),
	'buttons' => empty($u['demo']) ? '<button class="btn nuBtnAct nuBtnSave" type="submit" form="secSettings" id="secSaveBtn" data-nu-save' . ($secTab === 'settings' ? '' : ' hidden') . '><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button>' : '']) ?>
<?php // two tabs (review E2): the report (checks + file test) and the settings with their Save in the title bar – before, the switches sat far below ?>
<ul class="nav nav-tabs mb-3" id="secTabs" role="tablist" data-nu-hash-tabs data-nu-tab-restore>
	<li class="nav-item" role="presentation"><button class="nav-link<?= $secTab === 'checks' ? ' active' : '' ?>" id="secTabBtn-checks" data-bs-toggle="tab" data-bs-target="#secPane-checks" data-nu-tab="checks" type="button" role="tab" aria-controls="secPane-checks" aria-selected="<?= $secTab === 'checks' ? 'true' : 'false' ?>"><i class="bi bi-list-check me-1" aria-hidden="true"></i><?= h(t('secchk_checks')) ?></button></li>
	<li class="nav-item" role="presentation"><button class="nav-link<?= $secTab === 'settings' ? ' active' : '' ?>" id="secTabBtn-settings" data-bs-toggle="tab" data-bs-target="#secPane-settings" data-nu-tab="settings" type="button" role="tab" aria-controls="secPane-settings" aria-selected="<?= $secTab === 'settings' ? 'true' : 'false' ?>"><i class="bi bi-toggles me-1" aria-hidden="true"></i><?= h(t('secchk_settings')) ?></button></li>
</ul>
<div class="tab-content">
<div class="tab-pane fade<?= $secTab === 'checks' ? ' show active' : '' ?>" id="secPane-checks" role="tabpanel" aria-labelledby="secTabBtn-checks" tabindex="0">
<div class="card shadow-sm mb-4" id="secChecks">
	<div class="card-header d-flex flex-wrap gap-2 align-items-center">
		<span class="fw-semibold me-auto"><?= h(t('secchk_checks')) ?></span>
		<?php // a click on a badge shows only the checks of that state, a second click all of them (0.8.693, assets/js/nusecuritycheck.js) ?>
		<?php foreach (['bad' => 'text-bg-danger', 'warn' => 'text-bg-warning', 'ok' => 'text-bg-success'] as $st => $cls): ?>
			<button type="button" class="badge <?= $cls ?> border-0 secFlt" data-sec-filter="<?= $st ?>" aria-pressed="false" title="<?= h(t('secchk_filter_tip')) ?>"><?= $count($st) ?> <?= h(t('secchk_' . $st)) ?></button>
		<?php endforeach; ?>
	</div>
	<ul class="list-group list-group-flush">
		<?php foreach ($checks as $c): ?>
			<li class="list-group-item d-flex gap-3 align-items-start sec-<?= h($c['status']) ?>" id="sec_<?= h($c['id']) ?>">
				<i class="bi <?= $icon[$c['status']] ?> mt-1" role="img" aria-label="<?= h(t('secchk_' . $c['status'])) ?>"></i>
				<div class="flex-grow-1">
					<div class="fw-semibold"><?= h(t($c['title'])) ?></div>
					<?php if ($c['detail'] !== ''): ?><div class="small text-body-secondary text-break"><?= h($c['detail']) ?></div><?php endif; ?>
				</div>
				<?php if ($c['link'] !== '' && $c['status'] !== 'ok'): ?><a class="btn btn-sm btn-outline-secondary text-nowrap" href="<?= h($c['link']) ?>"><?= h(t('secchk_fix')) ?> <i class="bi bi-chevron-right"></i></a><?php endif; ?>
				<?php if ($c['id'] === 'logintoken' && $c['status'] === 'info' && empty($u['demo'])): ?>
					<form method="post" action="<?= h(nu_url('securitycheck')) ?>" data-confirm="<?= h(t('secchk_logintoken_clear_confirm')) ?>" data-confirm-ok="<?= h(t('secchk_logintoken_clear')) ?>" data-confirm-class="btn btn-danger">
						<?= nu_csrf_field() ?><input type="hidden" name="action" value="clear_tokens">
						<button class="btn btn-sm btn-outline-danger text-nowrap" type="submit"><i class="bi bi-x-octagon me-1"></i><?= h(t('secchk_logintoken_clear')) ?></button>
					</form>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>

<div class="card shadow-sm mb-4" id="secProbe" data-urls="<?= h(json_encode(NuSecurity::probeUrls())) ?>" data-marks="<?= h((string)json_encode(NuSecurity::probeMarks(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"
	 data-ok="<?= h(t('secchk_probe_ok')) ?>" data-empty="<?= h(t('secchk_probe_empty')) ?>" data-open="<?= h(t('secchk_probe_open')) ?>" data-error="<?= h(t('secchk_probe_error')) ?>" data-link="<?= h(t('secchk_probe_link')) ?>" data-other="<?= h(t('secchk_probe_other')) ?>">
	<div class="card-header d-flex flex-wrap gap-2 align-items-center">
		<span class="fw-semibold me-auto"><?= h(t('secchk_probe_title')) ?><?= $tip(t('secchk_probe_tip')) ?></span>
		<span class="badge text-bg-secondary js-probe-summary">…</span>
	</div>
	<ul class="list-group list-group-flush js-probe-list"></ul>
	<div class="card-body small text-body-secondary border-top js-probe-help" hidden>
		<p class="mb-1"><?= h(t('secchk_probe_help')) ?></p>
		<pre dir="ltr" class="mb-0 small bg-body-tertiary p-2 rounded"><code><?= h(NuSecurity::serverRules(dirname(NU_ROOT), rtrim(NuSecurity::cookiePath(), '/'))) ?></code></pre>
	</div>
</div>

</div>
<div class="tab-pane fade<?= $secTab === 'settings' ? ' show active' : '' ?>" id="secPane-settings" role="tabpanel" aria-labelledby="secTabBtn-settings" tabindex="0">
<form method="post" action="<?= h(nu_url('securitycheck')) ?>" class="card shadow-sm mb-4" id="secSettings" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save">
	<div class="card-header d-flex flex-wrap gap-2 align-items-center">
		<span class="fw-semibold me-auto"><?= h(t('secchk_settings')) ?></span>
	</div>
	<div class="card-body">
		<fieldset<?= !empty($u['demo']) ? ' disabled' : '' ?>>
		<?php foreach (['rights_globeadmin_only', 'protect_fields', 'record_check', 'field_access_ga', 'md5_login', 'login_token', 'form_drafts_required', 'form_preview_saves'] as $k): ?>
			<div class="d-flex align-items-center mb-3">
				<div class="form-check form-switch mb-0">
					<input class="form-check-input" type="checkbox" role="switch" id="sec_<?= $k ?>" name="<?= $k ?>" value="1"<?= $set[$k] ? ' checked' : '' ?>>
					<label class="form-check-label" for="sec_<?= $k ?>"><?= h(t('secchk_set_' . $k)) ?></label>
				</div>
				<?= $tip(t('secchk_set_' . $k . '_tip')) ?>
			</div>
		<?php endforeach; ?>
		<div class="row g-3">
			<?php foreach (['log_keep_days' => 36500, 'reauth_minutes' => 1440, 'reauth_minutes_dev' => 1440] as $k => $max): ?>
				<div class="col-sm-6 col-lg-4 d-flex flex-column">
					<div class="form-label"><label for="sec_<?= $k ?>"><?= h(t('secchk_set_' . $k)) ?></label><?= $tip(t('secchk_set_' . $k . '_tip')) ?></div>
					<input class="form-control mt-auto" type="number" min="0" max="<?= $max ?>" step="1" id="sec_<?= $k ?>" name="<?= $k ?>" value="<?= (int)$set[$k] ?>">
				</div>
			<?php endforeach; ?>
		</div>
		</fieldset>
	</div>
</form>
</div>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nusecuritycheck.js') . '"></script>');
