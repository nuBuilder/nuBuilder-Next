<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Settings centre (0.8.702, core/nusettingshub.php): ?p=settings = overview with the search; ?p=settings&s=<section> = a section whose
 * simple settings are edited here (general, look, data, updates); s=forte = nuBuilder Forte's settings list (pages/nusettings_forte.php).
 * The big editors (branding, themes, e-mail, security check, global code, configuration file) keep their pages inside the same frame.
 * ?p=settings&index=1 = the search index as JSON. POST actions: langs, audit_set, ntf_set, bk_keep, bk_rule, gh_set.
 */

$sec = is_string($_GET['s'] ?? null) ? (string)$_GET['s'] : '';
if ($sec === 'forte') { require __DIR__ . '/nusettings_forte.php'; return; }
if (!NuSettingsHub::allowed()) NuAdmin::require('settings');          // nothing to open: the usual "no right" page
$pages = NuSettingsHub::pages();
if (!in_array($sec, ['', 'general', 'look', 'data', 'updates'], true)) $sec = '';
if ($sec !== '' && !isset($pages[$sec])) NuAdmin::require('settings');

if (isset($_GET['index'])) {                                             // search index (assets/js/nusettingshub.js)
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode(NuSettingsHub::index(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}
NuSecurity::requireRecentAuth(nu_url('settings', ['s' => $sec ?: null]));   // as before: password again after 15 minutes

$back = static fn(string $s, string $anchor) => nu_redirect(nu_url('settings', ['s' => $s]) . '#' . $anchor);
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'langs') {                         // languages of the installation (0.8.701, NuLanguages)
		NuAdmin::require('settings');
		$want = array_map('strval', is_array($_POST['langs'] ?? null) ? $_POST['langs'] : []);
		if (!array_intersect($want, array_keys(NU_LANGS))) {
			nu_flash('danger', t('lng_err_none'));
		} else {
			$counts = NuLanguages::userCounts();
			$res = NuLanguages::set($want, (string)(NuAuth::user()['login'] ?? ''));
			NuLog::write('languages_changed', 'zzzzsys_nunext_setting', NuLanguages::KEY, ['languages' => NuLanguages::installed(), 'added' => $res['added'], 'removed' => $res['removed']]);
			$name = static fn(string $c): string => NU_LANGS[$c][0];
			nu_flash('success', t('lng_saved') . ($res['added'] ? ' ' . t('lng_added', implode(', ', array_map($name, $res['added']))) : ''));
			$affected = array_sum(array_map(static fn($c) => $counts[$c] ?? 0, $res['removed']));
			if ($res['removed']) nu_flash('info', t('lng_removed', implode(', ', array_map($name, $res['removed'])), $affected, $name(NuLanguages::fallback())));
		}
		$back('general', 'setLangs');
	}
	if ($action === 'audit_set') {                     // audit trail (also on the audit page)
		NuAdmin::require('audit');
		$changed = NuAudit::saveSettings(['on' => nu_check('on') === '1', 'keep_days' => (int)nu_post('keep_days'), 'exclude' => nu_post('exclude')]);
		nu_flash('success', $changed ? t('aud_set_saved') : t('aud_set_nochange'));
		$back('data', 'shAudit');
	}
	if ($action === 'ntf_set') {                       // notification centre (also under Notifications → Manage)
		if (!NuSettingsHub::ga() && !NuAdmin::can('notifications', 'change')) NuAcl::require('-');
		NuNotify::saveSettings((int)nu_post('keep_days'));
		nu_flash('success', t('ntf_settings_saved'));
		$back('data', 'shNotify');
	}
	if ($action === 'bk_keep' || $action === 'bk_rule') {   // backups (also on the update page, tab Backups)
		NuAdmin::require('update');
		NuUpdate::setKeepSql((int)nu_post('keep'));
		$mode = nu_post('mode') === 'age' ? 'age' : 'keep';
		NuUpdate::setCleanupRule($mode, max(1, min(9999, (int)nu_post($mode === 'age' ? 'days' : 'keep_n'))), nu_check('auto') === '1');
		NuLog::write('backup_rules', 'zzzzsys_nunext_setting', '', ['keep_sql' => NuUpdate::keepSql(), 'cleanup' => NuUpdate::cleanupRule()]);
		nu_flash('success', t('saved'));
		$back('data', 'shBackups');
	}
	if ($action === 'gh_set') {                        // update from GitHub (also on the update page)
		NuAdmin::require('update');
		$changed = NuGitHub::saveSettings(nu_post('repo'), nu_check('pre') === '1', nu_post('every'));
		nu_flash('success', $changed ? t('gh_saved') : t('aud_set_nochange'));
		$back('updates', 'shGitHub');
	}
}

$tip = static fn(string $text): string => NuListView::infoTip($text);
$card = static function (string $id, string $icon, string $title, string $body, string $tipText = ''): string {
	return '<div class="card shadow-sm mb-3 nuSetCard" id="' . h($id) . '"><div class="card-header fw-semibold"><i class="bi ' . h($icon) . ' me-2" aria-hidden="true"></i>'
		. h($title) . ($tipText !== '' ? NuListView::infoTip($tipText) : '') . '</div><div class="card-body">' . $body . '</div></div>';
};
$linkCard = static function (string $icon, string $title, string $desc, string $url, string $id = ''): string {
	return '<a class="list-group-item list-group-item-action d-flex align-items-start gap-3 nuSetLink"' . ($id !== '' ? ' id="' . h($id) . '"' : '') . ' href="' . h($url) . '">'
		. '<i class="bi ' . h($icon) . ' fs-5 text-body-secondary" aria-hidden="true"></i><span><span class="fw-semibold d-block">' . h($title) . '</span>'
		. '<span class="small text-body-secondary">' . h($desc) . '</span></span><i class="bi bi-chevron-right ms-auto align-self-center text-body-secondary" aria-hidden="true"></i></a>';
};
$keepOpts = static function (int $cur, array $list): string {
	if (!in_array($cur, $list, true)) $list[] = $cur;
	sort($list);
	$o = '';
	foreach ($list as $d) $o .= '<option value="' . $d . '"' . ($d === $cur ? ' selected' : '') . '>' . h($d === 0 ? t('aud_keep_ever') : t('aud_keep_days', $d)) . '</option>';
	return $o;
};

$title = $sec === '' ? t('set_title') : t(NuSettingsHub::SECTIONS[$sec][1]);
nu_layout_start($title, 'home', ['container' => 'nuContainer', 'settings' => $sec, 'settings_page' => $sec]);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<?php if ($sec === ''): ?><li class="breadcrumb-item active" aria-current="page"><?= h(t('set_title')) ?></li>
	<?php else: ?><li class="breadcrumb-item"><a href="<?= h(nu_url('settings')) ?>"><?= h(t('set_title')) ?></a></li><li class="breadcrumb-item active" aria-current="page"><?= h($title) ?></li><?php endif; ?>
</ol></nav>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><?php if ($sec !== ''): ?><i class="bi <?= h(NuSettingsHub::SECTIONS[$sec][0]) ?> me-2" aria-hidden="true"></i><?php endif; ?><?= h($title) ?></h1>
	<div class="nuTitleSub text-body-secondary small"><?= h($sec === '' ? t('sh_overview_hint') : t(NuSettingsHub::SECTIONS[$sec][2])) ?></div>
	<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?></div>
</div>

<?php if ($sec === ''): // ------------------------------------------------------------------ overview ?>
<div class="row g-3" id="shOverview">
	<?php foreach ($pages as $s => $list): [$icon, $tKey, $dKey] = NuSettingsHub::SECTIONS[$s]; ?>
	<div class="col-md-6 col-xl-4">
		<div class="card shadow-sm h-100 nuSetSecCard" data-sh="<?= h($s) ?>">
			<div class="card-body">
				<a class="stretched-link text-decoration-none text-reset" href="<?= h($list[0][2]) ?>"><h2 class="h6 fw-semibold mb-1"><i class="bi <?= h($icon) ?> me-2" aria-hidden="true"></i><?= h(t($tKey)) ?></h2></a>
				<p class="small text-body-secondary mb-2"><?= h(t($dKey)) ?></p>
				<?php if (count($list) > 1): ?><div class="small nuSetSecPages"><?= h(implode(' · ', array_column(array_slice($list, 1), 1))) ?></div><?php endif; ?>
			</div>
		</div>
	</div>
	<?php endforeach; ?>
</div>

<?php elseif ($sec === 'general'): // ------------------------------------------------------- general ?>
	<?php if (NuAdmin::can('settings', 'view')):
		$lngOn = NuLanguages::installed(); $lngUsers = NuLanguages::userCounts();
		ob_start(); ?>
		<form method="post" action="<?= h(nu_url('settings', ['s' => 'general'])) ?>" id="setLangsForm" class="d-flex flex-wrap align-items-center gap-3">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="langs">
			<?php foreach (NU_LANGS as $lc => [$native]): $lu = (int)($lngUsers[$lc] ?? 0); ?>
				<div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="langs[]" value="<?= h($lc) ?>" id="setLang_<?= h($lc) ?>"<?= in_array($lc, $lngOn, true) ? ' checked' : '' ?>>
					<label class="form-check-label" for="setLang_<?= h($lc) ?>" lang="<?= h($lc) ?>"><?= h($native) ?></label><?php if ($lu): ?>
					<span class="badge rounded-pill text-bg-light border ms-1" title="<?= h(t('lng_users', $lu)) ?>"><?= $lu ?></span><?php endif; ?></div>
			<?php endforeach; ?>
			<button class="btn btn-sm btn-primary ms-auto" type="submit" id="setLangsSave"><i class="bi bi-floppy me-1"></i><?= h(t('lng_save')) ?></button>
		</form>
		<?= $card('setLangs', 'bi-translate', t('lng_title'), (string)ob_get_clean(), t('lng_tip')) ?>
	<?php endif; ?>
	<div class="list-group shadow-sm mb-3">
		<?php if (NuAdmin::can('setupwizard', 'change')) echo $linkCard('bi-magic', t('wiz_title'), t('sh_wizard_d'), nu_url('setupwizard'), 'shWizard'); ?>
		<?php if (NuAdmin::can('settings', 'view')) echo $linkCard('bi-gear-wide-connected', t('sh_forte'), t('sh_forte_d'), nu_url('settings', ['s' => 'forte'])); ?>
		<?php if (NuAdmin::can('configfile', 'change')) echo $linkCard('bi-file-earmark-code', 'nuconfig.php', t('sh_configfile_d'), nu_url('configfile')); ?>
	</div>

<?php elseif ($sec === 'look'): // ---------------------------------------------------------- look ?>
	<?php if (NuSettingsHub::ga()): $sbDef = NuSidebar::defaultOn(); ob_start(); ?>
		<form method="post" action="<?= h(nu_url('sidebar')) ?>" class="d-flex flex-wrap align-items-center gap-3"><?= nu_csrf_field() ?>
			<input type="hidden" name="action" value="default"><input type="hidden" name="back" value="<?= h(nu_url('settings', ['s' => 'look'])) ?>">
			<span class="badge <?= $sbDef ? 'text-bg-success' : 'text-bg-secondary' ?>" id="shSidebarState"><?= h(t($sbDef ? 'sh_on' : 'sh_off')) ?></span>
			<span class="small text-body-secondary flex-grow-1"><?= h(t('sh_sb_default_tip')) ?></span>
			<button class="btn btn-sm btn-outline-primary" type="submit" id="nuSidebarDefault" aria-pressed="<?= $sbDef ? 'true' : 'false' ?>"><i class="bi bi-layout-sidebar me-1"></i><?= h(t($sbDef ? 'sh_switch_off' : 'sh_switch_on')) ?></button>
		</form>
		<?= $card('shSidebar', 'bi-layout-sidebar', t('sb_menu_default'), (string)ob_get_clean()) ?>
	<?php endif; ?>
	<div class="list-group shadow-sm mb-3">
		<?php if (NuAdmin::can('branding', 'view')) { echo $linkCard('bi-brush', t('brand_title'), t('sh_kw_branding'), nu_url('branding')); echo $linkCard('bi-palette2', t('theme_title'), t('sh_kw_themes'), nu_url('themes')); } ?>
		<?php if (NuAdmin::can('homelayout', 'view')) echo $linkCard('bi-grid-1x2', t('sh_home_layouts'), t('sh_home_layouts_d'), nu_url('home')); ?>
		<?php if (NuAdmin::can('access', 'view')) echo $linkCard('bi-people', t('home_u_title'), t('sh_user_home_d'), nu_url('home', ['for' => NuHome::ALL_LEVELS])); ?>
	</div>

<?php elseif ($sec === 'data'): // ---------------------------------------------------------- data, logs, backups ?>
	<?php if (NuAdmin::can('audit', 'view')): $a = NuAudit::settings(); $canA = NuAdmin::can('audit', 'change'); ob_start(); ?>
		<form method="post" action="<?= h(nu_url('settings', ['s' => 'data'])) ?>" class="row g-3 align-items-end"><?= nu_csrf_field() ?><input type="hidden" name="action" value="audit_set">
			<fieldset class="contents"<?= $canA ? '' : ' disabled' ?>>
			<div class="col-sm-4 d-flex align-items-center pb-1"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" name="on" value="1" id="shAudOn"<?= $a['on'] ? ' checked' : '' ?>>
				<label class="form-check-label" for="shAudOn"><?= h(t('aud_set_on')) ?></label></div><?= $tip(t('aud_set_on_tip')) ?></div>
			<div class="col-sm-4"><label class="form-label small mb-1" for="shAudKeep"><?= h(t('aud_set_keep')) ?></label><?= $tip(t('aud_set_keep_tip')) ?>
				<select class="form-select form-select-sm" name="keep_days" id="shAudKeep"><?= $keepOpts((int)$a['keep_days'], [0, 30, 90, 180, 365, 730, 1825]) ?></select></div>
			<div class="col-12"><label class="form-label small mb-1" for="shAudExcl"><?= h(t('aud_set_exclude')) ?></label><?= $tip(t('aud_set_exclude_tip')) ?>
				<input class="form-control form-control-sm" name="exclude" id="shAudExcl" maxlength="2000" value="<?= h($a['exclude']) ?>"></div>
			<div class="col-12 text-end"><button class="btn btn-sm btn-primary" type="submit" id="shAudSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button></div>
			</fieldset>
		</form>
		<?= $card('shAudit', 'bi-clock-history', t('aud_title'), (string)ob_get_clean()) ?>
	<?php endif; ?>
	<?php if (NuSettingsHub::ga() || NuAdmin::can('notifications', 'change')): $nk = NuNotify::settings()['keep_days']; ob_start(); ?>
		<form method="post" action="<?= h(nu_url('settings', ['s' => 'data'])) ?>" class="d-flex flex-wrap align-items-end gap-3"><?= nu_csrf_field() ?><input type="hidden" name="action" value="ntf_set">
			<div><label class="form-label small mb-1" for="shNtfKeep"><?= h(t('ntf_keep')) ?></label><?= $tip(t('ntf_keep_tip')) ?>
				<select class="form-select form-select-sm" name="keep_days" id="shNtfKeep"><?= $keepOpts((int)$nk, [0, 30, 90, 180, 365, 730]) ?></select></div>
			<button class="btn btn-sm btn-primary ms-auto" type="submit" id="shNtfSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
		</form>
		<?= $card('shNotify', 'bi-bell', t('ntf_title'), (string)ob_get_clean()) ?>
	<?php endif; ?>
	<?php if (NuAdmin::can('update', 'view')): $rule = NuUpdate::cleanupRule(); ob_start(); ?>
		<form method="post" action="<?= h(nu_url('settings', ['s' => 'data'])) ?>" class="row g-3 align-items-start"><?= nu_csrf_field() ?><input type="hidden" name="action" value="bk_rule">
			<fieldset class="contents shBkFields"<?= NuAdmin::can('update', 'change') ? '' : ' disabled' ?>>
			<div class="col-sm-6"><label class="form-label small mb-1" for="shBkKeep"><?= h(t('upd_keep_label')) ?></label><?= $tip(t('upd_keep_hint')) ?>
				<div class="input-group input-group-sm shBkKeepGrp"><input type="number" class="form-control" id="shBkKeep" name="keep" min="0" max="999" value="<?= NuUpdate::keepSql() ?>"><span class="input-group-text"><?= h(t('upd_keep_suffix')) ?></span></div></div>
			<div class="col-sm-6"><span class="form-label small mb-1 d-block"><?= h(t('sh_bk_rule')) ?><?= $tip(t('sh_bk_rule_d')) ?></span>
				<div class="d-flex flex-wrap gap-2 align-items-center small">
					<div class="form-check mb-0"><input class="form-check-input" type="radio" name="mode" value="keep" id="shBkModeKeep"<?= $rule['mode'] === 'keep' ? ' checked' : '' ?>><label class="form-check-label" for="shBkModeKeep"><?= h(t('upd_bk_clean_keep')) ?></label></div>
					<input type="number" class="form-control form-control-sm shBkNum" name="keep_n" min="1" max="9999" value="<?= $rule['mode'] === 'keep' ? $rule['n'] : 10 ?>" aria-label="<?= h(t('upd_bk_clean_keep')) ?>"><span><?= h(t('upd_bk_clean_keep_suffix')) ?></span>
				</div>
				<div class="d-flex flex-wrap gap-2 align-items-center small mt-1">
					<div class="form-check mb-0"><input class="form-check-input" type="radio" name="mode" value="age" id="shBkModeAge"<?= $rule['mode'] === 'age' ? ' checked' : '' ?>><label class="form-check-label" for="shBkModeAge"><?= h(t('upd_bk_clean_age')) ?></label></div>
					<input type="number" class="form-control form-control-sm shBkNum" name="days" min="1" max="9999" value="<?= $rule['mode'] === 'age' ? $rule['n'] : 90 ?>" aria-label="<?= h(t('upd_bk_clean_age')) ?>"><span><?= h(t('upd_bk_clean_age_suffix')) ?></span>
				</div>
				<div class="form-check form-switch mt-2 mb-0"><input class="form-check-input" type="checkbox" role="switch" name="auto" value="1" id="shBkAuto"<?= $rule['auto'] ? ' checked' : '' ?>><label class="form-check-label small" for="shBkAuto"><?= h(t('upd_bk_clean_auto')) ?></label></div></div>
			<div class="col-12 d-flex flex-wrap gap-2 align-items-center"><a class="small me-auto" href="<?= h(nu_url('update', ['tab' => 'backups'])) ?>"><?= h(t('sh_backups_link')) ?></a>
				<button class="btn btn-sm btn-primary" type="submit" id="shBkSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button></div>
			</fieldset>
		</form>
		<?= $card('shBackups', 'bi-archive', t('sh_backups'), (string)ob_get_clean()) ?>
	<?php endif; ?>
	<div class="list-group shadow-sm mb-3">
		<?php if (NuAdmin::can('securitycheck', 'view')) echo $linkCard('bi-journal-text', t('secchk_set_log_keep_days'), t('secchk_set_log_keep_days_tip'), nu_url('securitycheck') . '#sec_log_keep_days', 'shLogKeep'); ?>
		<?php if (NuDemo::configured() && NuAdmin::can('status', 'view')) echo $linkCard('bi-easel', t('demo_data_title'), t('demo_data_tip'), nu_url('vendor') . '#demo'); ?>
	</div>

<?php elseif ($sec === 'updates'): // ------------------------------------------------------- updates ?>
	<?php $gs = NuGitHub::settings(); $canU = NuAdmin::can('update', 'change'); ob_start(); ?>
		<form method="post" action="<?= h(nu_url('settings', ['s' => 'updates'])) ?>" class="row g-3 align-items-end"><?= nu_csrf_field() ?><input type="hidden" name="action" value="gh_set">
			<fieldset class="contents"<?= $canU ? '' : ' disabled' ?>>
			<div class="col-md-5"><label class="form-label small mb-1" for="shGhRepo"><?= h(t('gh_repo')) ?></label><?= $tip(t('gh_repo_tip')) ?>
				<input type="text" class="form-control form-control-sm" name="repo" id="shGhRepo" value="<?= h($gs['repo']) ?>" maxlength="140" required pattern="[A-Za-z0-9][A-Za-z0-9\-]*/[A-Za-z0-9._\-]+" spellcheck="false" autocomplete="off"></div>
			<div class="col-md-4"><label class="form-label small mb-1" for="shGhEvery"><?= h(t('gh_set_every')) ?></label>
				<select class="form-select form-select-sm" name="every" id="shGhEvery"><?php foreach (array_keys(NuGitHub::EVERY) as $ev): ?><option value="<?= h($ev) ?>"<?= $gs['every'] === $ev ? ' selected' : '' ?>><?= h(t('gh_every_' . $ev)) ?></option><?php endforeach; ?></select></div>
			<div class="col-md-3"><div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" name="pre" value="1" id="shGhPre"<?= $gs['pre'] ? ' checked' : '' ?>><label class="form-check-label small" for="shGhPre"><?= h(t('gh_set_pre')) ?></label></div></div>
			<div class="col-12 text-end"><button class="btn btn-sm btn-primary" type="submit" id="shGhSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button></div>
			</fieldset>
		</form>
	<?= $card('shGitHub', 'bi-github', t('gh_title'), (string)ob_get_clean(), t('gh_tip')) ?>
	<div class="list-group shadow-sm mb-3">
		<?= $linkCard('bi-cloud-arrow-up', t('upd_title'), t('sh_update_d'), nu_url('update')) ?>
		<?= $linkCard('bi-boxes', t('sh_libraries'), t('sh_libraries_d'), nu_url('update', ['tab' => 'libraries'])) ?>
		<?= $linkCard('bi-clipboard2-pulse', t('ic_title'), t('ic_intro'), nu_url('update', ['tab' => 'system', 'integrity' => 1]) . '#updIntegrityNow') ?>
	</div>
<?php endif; ?>
<?php
nu_layout_end();
