<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Setup wizard (0.8.312) – globeadmin, opens by itself after the login while nuconfig.php has no $nuConfigShowInstaller = false
 * (logic: core/nusetupwizard.php). Admin tools → Setup wizard opens it again. Steps basics · look · security · samples · summary;
 * the answers stay in the session until Apply. POST action next | back | skip | apply.
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('setupwizard', 'change');

$_SESSION['nu_setup_wiz_seen'] = 1;                    // the redirect after the login happens once per session
$steps = NuSetupWizard::STEPS;
$step = in_array($_GET['step'] ?? '', $steps, true) ? (string)$_GET['step'] : $steps[0];
$cur = NuSetupWizard::current();
$state = is_array($_SESSION['nu_setup_wiz'] ?? null) ? $_SESSION['nu_setup_wiz'] + $cur : $cur;
$errors = [];
$i = (int)array_search($step, $steps, true);

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'skip') {
		unset($_SESSION['nu_setup_wiz']);
		$err = NuSetupWizard::markDone(true);
		nu_flash($err === '' ? 'success' : 'warning', $err === '' ? t('wiz_skipped') : t('wiz_skipped_db') . ' ' . $err);
		nu_redirect(nu_url('home'));
	}
	if ($action === 'apply') {
		$res = NuSetupWizard::apply($state);
		unset($_SESSION['nu_setup_wiz']);
		$state['pw1'] = $state['pw2'] = '';
		if ($res['manual'] !== '') {
			$_SESSION['nu_setup_wiz_manual'] = $res['manual'];
			nu_flash('warning', t('wiz_manual'));
		} else {
			foreach ($res['errors'] as $e) nu_flash('danger', $e);
			nu_flash('success', t('wiz_done'));
		}
		if ($res['sample'] !== null) $_SESSION['nu_sample_pw'] = $res['sample'];
		$_SESSION['nu_tut_offer'] = 1;                         // the start page offers the tutorial (0.8.328)
		if ($res['relogin']) {
			NuAuth::logout();
			nu_flash('success', t('wiz_relogin'));
			nu_redirect(nu_url('login'));
		}
		nu_redirect($res['manual'] !== '' ? nu_url('setupwizard', ['step' => 'done']) : ($res['sample'] !== null ? nu_url('sample') : nu_url('home')));
	}
	$state = NuSetupWizard::take($step, $state, $_POST);
	if ($action === 'lang') {                                      // language picked (0.8.543): the wizard switches to it at once (session language)
		$_SESSION['nu_setup_wiz'] = $state;
		if (isset(nu_lang_choices()[(string)$state['lang']])) nu_set_lang((string)$state['lang']);
		nu_redirect(nu_url('setupwizard', ['step' => $step]));
	}
	if ($action === 'next') $errors = NuSetupWizard::validate($step, $state);
	$_SESSION['nu_setup_wiz'] = $state;
	if (!$errors) {
		$to = $action === 'back' ? max(0, $i - 1) : min(count($steps) - 1, $i + 1);
		nu_redirect(nu_url('setupwizard', ['step' => $steps[$to]]));
	}
}

$manual = '';
if (($_GET['step'] ?? '') === 'done') {
	$manual = (string)($_SESSION['nu_setup_wiz_manual'] ?? '');
	unset($_SESSION['nu_setup_wiz_manual']);
	if ($manual === '') nu_redirect(nu_url('home'));
}

$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
$inv = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$fb = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . h($errors[$k]) . '</div>' : '';
$icons = ['basics' => 'bi-sliders', 'look' => 'bi-palette', 'security' => 'bi-shield-lock', 'samples' => 'bi-box-seam', 'summary' => 'bi-check2-square'];
$writable = NuConfigFile::writable();
$v = $state;

nu_layout_start(t('wiz_title'), 'home', ['head' => nu_ts_head()]);
?>
<div class="card shadow-sm nuListCard nuWizCard" id="nuSetupWizard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold"><i class="bi bi-magic me-2"></i><?= h(t('wiz_title')) ?><?= $tip(t('wiz_title_tip')) ?></h1>
		<div class="d-flex flex-wrap gap-2">
			<?= nu_back_button() ?>
			<?php if ($manual === ''): ?>
			<form method="post" action="<?= h(nu_url('setupwizard', ['step' => $step])) ?>" data-confirm="<?= h(t('wiz_skip_confirm')) ?>" data-confirm-ok="<?= h(t('wiz_skip')) ?>">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="skip">
				<button class="btn nuBtnAct" type="submit" id="wizSkip"><i class="bi bi-x-circle"></i> <?= h(t('wiz_skip')) ?></button>
			</form>
			<?php endif; ?>
		</div>
	</div>
	<?php if ($manual !== ''): ?>
	<div class="card-body">
		<div class="alert alert-warning" id="wizManual"><?= h(t('wiz_manual_text', NuConfigFile::path())) ?></div>
		<textarea class="form-control font-monospace small" rows="<?= min(16, substr_count($manual, "\n") + 2) ?>" readonly id="wizManualText"><?= h($manual) ?></textarea>
		<a class="btn btn-primary mt-3" href="<?= h(nu_url(!empty($_SESSION['nu_sample_pw']) ? 'sample' : 'home')) ?>"><?= h(t('wiz_finish')) ?></a>
		<a class="btn btn-outline-primary mt-3 ms-1" href="<?= h(nu_url('tutorial')) ?>"><i class="bi bi-mortarboard me-1"></i><?= h(t('tut_start')) ?></a>
	</div>
	<?php else: ?>
	<div class="card-body pb-0">
		<ul class="nav nav-tabs nuWizSteps" role="list">
			<?php foreach ($steps as $n => $s): ?>
			<li class="nav-item" role="listitem"><span class="nav-link<?= $s === $step ? ' active' : ($n > $i ? ' disabled' : '') ?>"<?= $s === $step ? ' aria-current="step"' : '' ?>><i class="bi <?= $icons[$s] ?>"></i> <span class="d-none d-sm-inline"><?= h(t('wiz_step_' . $s)) ?></span></span></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<form method="post" action="<?= h(nu_url('setupwizard', ['step' => $step])) ?>" class="card-body" novalidate autocomplete="off" id="wizForm"<?= $step === 'summary' ? ' data-nu-once' : '' ?>>
		<?= nu_csrf_field() ?>
		<?php if ($i === 0): ?><p class="text-body-secondary small" id="wizIntro"><?= h(t('wiz_intro')) ?></p><?php endif; ?>
		<?php // the same settings later in the settings centre (0.8.702)
		$wizSec = ['basics' => 'general', 'look' => 'look', 'security' => 'security'][$step] ?? '';
		$wizPages = $wizSec !== '' ? (NuSettingsHub::pages()[$wizSec] ?? null) : null;
		if ($wizPages): ?><p class="small text-end mb-2"><a href="<?= h($wizPages[0][2]) ?>" id="wizInSettings"><i class="bi bi-sliders me-1"></i><?= h(t('sh_in_settings')) ?></a></p><?php endif; ?>
		<?php if (!$writable): ?><div class="alert alert-warning small" id="wizNotWritable"><?= h(t('wiz_not_writable', NuConfigFile::path())) ?></div><?php endif; ?>

		<?php if ($step === 'basics'): ?>
		<div class="row g-3">
			<div class="col-sm-auto"><label class="form-label" for="lang"><?= h(t('wiz_lang')) ?></label><?= $tip(t('wiz_lang_tip')) ?>
				<select class="form-select<?= $inv('lang') ?>" id="lang" name="lang" data-wiz-lang><?php foreach (nu_lang_choices() as $code => $own): ?><option value="<?= h($code) ?>"<?= $code === $v['lang'] ? ' selected' : '' ?>><?= h($own) ?></option><?php endforeach; ?></select><?= $fb('lang') ?></div>
			<div class="col-sm-auto"><label class="form-label" for="timezone"><?= h(t('ins_opt_timezone')) ?></label><?= $tip(t('wiz_timezone_tip')) ?>
				<select class="form-select nuListTomSelect<?= $inv('timezone') ?>" id="timezone" name="timezone"><option value=""><?= h(t('wiz_tz_default', date_default_timezone_get())) ?></option><?php foreach (timezone_identifiers_list() as $tz): ?><option<?= $tz === $v['timezone'] ? ' selected' : '' ?>><?= h($tz) ?></option><?php endforeach; ?></select><?= $fb('timezone') ?></div>
			<div class="col-12"><label class="form-label" for="base_url"><?= h(t('ins_opt_url')) ?></label><?= $tip(t('ins_opt_url_hint')) ?>
				<input class="form-control<?= $inv('base_url') ?>" id="base_url" name="base_url" value="<?= h($v['base_url']) ?>" placeholder="<?= h(nu_base_url()) ?>"><?= $fb('base_url') ?></div>
			<div class="col-sm-auto"><label class="form-label" for="timeout"><?= h(t('ins_opt_timeout')) ?></label>
				<input class="form-control<?= $inv('timeout') ?>" type="number" min="5" max="1440" id="timeout" name="timeout" value="<?= (int)$v['timeout'] ?>"><?= $fb('timeout') ?></div>
			<div class="col-sm-auto"><label class="form-label" for="timeout_ga"><?= h(t('wiz_timeout_ga')) ?></label>
				<input class="form-control<?= $inv('timeout_ga') ?>" type="number" min="5" max="1440" id="timeout_ga" name="timeout_ga" value="<?= (int)$v['timeout_ga'] ?>"><?= $fb('timeout_ga') ?></div>
			<div class="col-12"><label class="form-label" for="nub_path"><?= h(t('ins_opt_nub')) ?></label><?= $tip(t('ins_opt_nub_hint')) ?>
				<input class="form-control<?= $inv('nub_path') ?>" id="nub_path" name="nub_path" value="<?= h($v['nub_path']) ?>" placeholder="<?= h(NuBuilderLib::path()) ?>"><?= $fb('nub_path') ?></div>
			<div class="col-12"><div class="form-check form-switch"><input class="form-check-input<?= $inv('shared') ?>" type="checkbox" role="switch" id="shared" name="shared" value="1"<?= $v['shared'] ? ' checked' : '' ?>><label class="form-check-label" for="shared"><?= h(t('ins_opt_shared')) ?></label><?= $fb('shared') ?></div></div>
		</div>

		<?php elseif ($step === 'look'): $tools = NuVendor::dbTools(); ?>
		<div class="row g-3">
			<div class="col-12"><label class="form-label" for="title"><?= h(t('wiz_app_title')) ?></label><?= $tip(t('wiz_app_title_tip')) ?>
				<input class="form-control<?= $inv('title') ?>" id="title" name="title" maxlength="100" value="<?= h($v['title']) ?>" placeholder="nuBuilder Next"><?= $fb('title') ?></div>
			<div class="col-auto"><label class="form-label" for="env_label"><?= h(t('brand_env')) ?></label><?= $tip(t('brand_env_field_tip')) ?>
				<input class="form-control<?= $inv('env_label') ?>" id="env_label" name="env_label" maxlength="<?= NuBranding::ENV_LEN ?>" value="<?= h($v['env_label']) ?>" placeholder="TEST" list="wizEnvList"><?= $fb('env_label') ?>
				<datalist id="wizEnvList"><option value="TEST"><option value="DEV"><option value="STAGING"></datalist></div>
			<div class="col-auto"><label class="form-label" for="env_color"><?= h(t('brand_env_color')) ?></label>
				<input class="form-control form-control-color" type="color" id="env_color" name="env_color" value="<?= h($v['env_color']) ?>"></div>
			<?php if (count($tools) > 1): ?>
			<div class="col-sm-6"><label class="form-label" for="dbtool"><?= h(t('wiz_dbtool')) ?></label><?= $tip(t('wiz_dbtool_tip')) ?>
				<select class="form-select" id="dbtool" name="dbtool"><?php foreach ($tools as $a): ?><option value="<?= h($a) ?>"<?= $a === $v['dbtool'] ? ' selected' : '' ?>><?= h(NuVendor::DB_TOOLS[$a]) ?></option><?php endforeach; ?></select></div>
			<?php endif; ?>
			<div class="col-12"><div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" role="switch" id="api" name="api" value="1"<?= $v['api'] ? ' checked' : '' ?>><label class="form-check-label" for="api"><?= h(t('wiz_api')) ?></label></div><?= $tip(t('wiz_api_tip')) ?></div>
		</div>

		<?php elseif ($step === 'security'): $weak = NuSecurity::weakAdminPassword(); $https = nu_https(); ?>
		<div class="row g-3">
			<div class="col-12"><h2 class="h6 mb-0"><?= h(t('wiz_pw')) ?><?= $tip(t('wiz_pw_tip')) ?></h2>
				<?php if ($weak): ?><div class="alert alert-warning small mt-2 mb-0" id="wizWeak"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('wiz_pw_weak_note')) ?></div><?php endif; ?></div>
			<div class="col-sm-auto"><label class="form-label" for="pw1"><?= h(t('wiz_pw_new_label')) ?></label>
				<input class="form-control<?= $inv('pw1') ?>" type="password" id="pw1" name="pw1" autocomplete="new-password"<?= $v['pw1'] === '' ? ' placeholder="' . h(t('wiz_pw_unchanged')) . '"' : (!$errors ? ' placeholder="' . h(t('wiz_pw_keep')) . '"' : '') ?>><?= $fb('pw1') ?></div>
			<div class="col-sm-auto"><label class="form-label" for="pw2"><?= h(t('wiz_pw_repeat')) ?></label>
				<input class="form-control<?= $inv('pw2') ?>" type="password" id="pw2" name="pw2" autocomplete="new-password"<?= $v['pw1'] === '' ? ' placeholder="' . h(t('wiz_pw_unchanged')) . '"' : '' ?>><?= $fb('pw2') ?></div>
			<div class="col-12"><hr class="my-1"></div>
			<div class="col-12"><div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" role="switch" id="twofa" name="twofa" value="1"<?= $v['twofa'] ? ' checked' : '' ?>><label class="form-check-label" for="twofa"><?= h(t('wiz_twofa')) ?></label></div><?= $tip(t('wiz_twofa_tip')) ?></div>
			<div class="col-sm-8"><label class="form-label" for="admin_email"><?= h(t('wiz_admin_email')) ?></label>
				<input class="form-control<?= $inv('admin_email') ?>" type="email" id="admin_email" name="admin_email" value="<?= h($v['admin_email']) ?>"><?= $fb('admin_email') ?></div>
			<div class="col-12"><hr class="my-1"></div>
			<div class="col-12" id="wizHttps"><h2 class="h6"><?= h(t('wiz_https')) ?></h2>
				<div class="small <?= $https ? 'text-success' : 'text-danger' ?>"><i class="bi <?= $https ? 'bi-check-circle' : 'bi-exclamation-octagon' ?> me-1"></i><?= h(t($https ? 'wiz_https_ok' : 'wiz_https_no')) ?></div></div>
			<div class="col-12"><hr class="my-1"></div>
			<div class="col-12"><h2 class="h6"><?= h(t('wiz_cron')) ?><?= $tip(t('wiz_cron_tip')) ?></h2>
				<div class="input-group"><input class="form-control font-monospace small" readonly id="wizCron" value="<?= h(NuSetupWizard::cronLine()) ?>">
				<button class="btn btn-outline-secondary" type="button" data-nu-copy="#wizCron" aria-label="<?= h(t('copy')) ?>"><i class="bi bi-clipboard"></i></button></div></div>
		</div>

		<?php elseif ($step === 'samples'): $inst = NuSetupWizard::sampleInstalled(); ?>
		<p class="small text-body-secondary"><?= h(t('wiz_sample_text')) ?></p>
		<div class="form-check"><input class="form-check-input" type="radio" name="sample" id="sampleNone" value="none"<?= $v['sample'] !== 'install' ? ' checked' : '' ?>><label class="form-check-label" for="sampleNone"><?= h(t($inst ? 'wiz_sample_keep' : 'wiz_sample_none')) ?></label></div>
		<div class="form-check"><input class="form-check-input" type="radio" name="sample" id="sampleInstall" value="install"<?= $v['sample'] === 'install' ? ' checked' : '' ?>><label class="form-check-label" for="sampleInstall"><?= h(t($inst ? 'wiz_sample_refresh' : 'smp_install')) ?></label><?= $tip(t('wiz_sample_tip')) ?></div>

		<?php elseif ($step === 'summary'): $ch = NuSetupWizard::changes($v, $cur); ?>
		<?php if ($ch): ?>
		<div class="table-responsive"><table class="table nu-table mb-0" id="wizChanges">
			<thead><tr><th><?= h(t('wiz_setting')) ?></th><th><?= h(t('wiz_old')) ?></th><th><?= h(t('wiz_new')) ?></th></tr></thead>
			<tbody><?php foreach ($ch as [$l, $o, $n]): ?><tr><td><?= h($l) ?></td><td class="text-body-secondary"><?= h($o) ?></td><td class="fw-semibold"><?= h($n) ?></td></tr><?php endforeach; ?></tbody>
		</table></div>
		<?php else: ?><p class="text-body-secondary" id="wizNoChanges"><?= h(t('wiz_nochange')) ?></p><?php endif; ?>
		<?php if ($v['pw1'] !== ''): ?><div class="alert alert-info small mt-3 mb-0"><?= h(t('wiz_pw_relogin_note')) ?></div><?php endif; ?>
		<?php endif; ?>

		<div class="d-flex flex-wrap mt-4 gap-3"><?php // Next right next to Back, with some space (0.8.542) ?>
			<?php if ($i > 0): ?><button class="btn btn-outline-secondary" type="submit" name="action" value="back" formnovalidate><i class="bi bi-arrow-left"></i> <?= h(t('ins_back')) ?></button><?php endif; ?>
			<?php if ($step === 'summary'): ?>
			<button class="btn btn-primary" type="submit" name="action" value="apply" id="wizApply" data-nu-busy="<?= h(t('wiz_applying')) ?>"><i class="bi bi-check2"></i> <?= h(t('wiz_apply')) ?></button>
			<?php else: ?>
			<button class="btn btn-primary" type="submit" name="action" value="next" id="wizNext"><?= h(t('ins_next')) ?> <i class="bi bi-arrow-right"></i></button>
			<?php endif; ?>
		</div>
	</form>
	<?php endif; ?>
</div>
<?php
nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nusetupwizard.js') . '"></script>');   // time zone: searchable (0.8.536); language switch (0.8.543)
