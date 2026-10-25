<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Globeadmin only – System status (0.8.316; before: Admin tools, whose tools are tiles of the start page now): time zone, missing
 * database tool / File Manager (where to copy them), storage engine (InnoDB), demo mode. Also opens phpMyAdmin / Adminer (app=PMA /
 * ADM / DB, optional table) – see core/nuvendor.php: POST (CSRF-checked in index.php) logs in and redirects. app=TFM (nuBuilder's
 * nuVendorLogin('TFM')) opens the File Manager page nuFiles since 0.8.479.
 */

// admin rights (0.8.323): opening a tool needs its right, converting tables "System status: change", the page "System status: view"
if (nu_is_post()) {
	$reqApp = strtoupper((string)($_POST['app'] ?? ''));
	if (nu_post('action') === 'db_primary' || in_array($reqApp, ['PMA', 'ADM', 'DB'], true)) NuAdmin::require('dbtool', 'change');
	elseif ($reqApp === 'TFM') { NuAdmin::require('filemanager', 'change'); nu_redirect(NuFileManager::url()); }
	else NuAdmin::require('status', 'change');
} else {
	NuAdmin::require('status', 'view');
}
if (nu_is_post()) NuSecurity::requireRecentAuth(nu_url('vendor'));   // phpMyAdmin / File Manager / InnoDB: password confirmed recently (0.8.207)

// demo mode: snapshot / reset of the tables of the allowed forms, reset interval (core/nudemodata.php, 0.8.341)
if (nu_is_post() && in_array(nu_post('action'), ['demo_snapshot', 'demo_reset', 'demo_interval'], true)) {
	$by = (string)(NuAuth::user()['login'] ?? '');
	try {
		switch (nu_post('action')) {
			case 'demo_snapshot':
				$info = NuDemoData::snapshot($by);
				nu_flash('success', t('demo_snap_done', count($info['tables']), array_sum($info['tables'])));
				break;
			case 'demo_reset':
				nu_flash('success', t('demo_reset_done', NuDemoData::reset($by)));
				break;
			case 'demo_interval':
				$hrs = (int)nu_post('hours');
				NuDemoData::saveSetting(['hours' => in_array($hrs, NuDemoData::HOURS, true) ? $hrs : 24] + NuDemoData::setting());
				nu_flash('success', t('set_saved', 1));
				break;
		}
	} catch (RuntimeException $e) {
		nu_flash('danger', $e->getMessage());
	}
	nu_redirect(nu_url('vendor') . '#demodata');
}

// star in the ▾ menu of the Database tile / card: this tool becomes the default (0.8.284)
if (nu_is_post() && nu_post('action') === 'db_primary') {
	$pa = strtoupper(nu_post('app'));
	$err = NuVendor::available($pa) ? NuVendor::setPrimaryDb($pa) : t('vendor_failed');
	if ($err === '') nu_flash('success', t('vendor_db_default_saved', NuVendor::DB_TOOLS[$pa]));
	else nu_flash('danger', t('vendor_db_default_failed') . ' ' . $err);
	nu_redirect(nu_url(nu_post('back') === 'home' ? 'home' : 'vendor'));
}
$app = strtoupper((string)($_POST['app'] ?? $_GET['app'] ?? ''));
if ($app === 'DB') $app = NuVendor::primaryDb();            // the primary database tool ($nuConfigPrimaryDatabaseManagement, 0.8.282)
$table = (string)($_POST['table'] ?? '');

if (nu_is_post() && isset(NuVendor::APPS[$app]) && NuVendor::available($app)) {
	try {
		nu_redirect(NuVendor::launch($app, $table));
	} catch (Throwable $e) {
		error_log('nuBuilder Next vendor login: ' . $e->getMessage());
		nu_flash('danger', t('vendor_failed') . (nu_cfg('nuConfigDebug') ? ' ' . $e->getMessage() : ''));
	}
}

$mysql = nu_db()->type === 'mysql';

/** Base tables of this database that are not InnoDB (MyISAM, Aria …): [name, engine, rows]. */
$nonInnodb = static function (): array {
	return nu_db()->all("SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND COALESCE(ENGINE, '') <> 'InnoDB' ORDER BY TABLE_NAME");
};

// Convert every table to InnoDB (globeadmin, POST). One ALTER per table; errors are collected, the rest goes on.
if ($mysql && nu_is_post() && nu_post('action') === 'innodb') {
	nu_time_limit(0);
	$ok = []; $failed = [];
	foreach ($nonInnodb() as $t) {
		try {
			nu_db()->run('ALTER TABLE ' . NuDB::ident((string)$t['name']) . ' ENGINE=InnoDB');
			$ok[] = (string)$t['name'];
		} catch (Throwable $e) {
			$failed[] = $t['name'] . ': ' . $e->getMessage();
		}
	}
	NuLog::write('innodb_converted', '', '', ['tables' => $ok, 'failed' => $failed]);
	if ($ok) nu_flash('success', t('vendor_innodb_done', count($ok)));
	if ($failed) nu_flash('danger', t('vendor_innodb_failed') . ' ' . implode(' · ', $failed));
	nu_redirect(nu_url('vendor'));
}
$engines = $mysql ? $nonInnodb() : [];
$warnings = NuHome::statusWarnings(true);            // badge of the System status tile, fresh after a visit here
nu_layout_start(t('status_title'), 'home');
?>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><i class="bi bi-activity me-2"></i><?= h(t('status_title')) ?></h1>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?></div>
</div>
<?php if ($warnings === 0): ?><div class="alert alert-success" id="statusOk"><i class="bi bi-check-circle me-1"></i><?= h(t('status_all_ok')) ?></div><?php endif; ?>
<div class="nuVendorFull">
<?php if (!$mysql): ?><div class="alert alert-warning"><?= h(t('vendor_mysql_only')) ?></div><?php endif; ?>
<?php if (NuHomeHook::active()): // custom code on the start page (0.8.378) – information, not a warning ?>
	<div class="alert alert-info d-flex flex-wrap align-items-center gap-2" id="statusHomeHook"><span class="flex-grow-1"><i class="bi bi-code-slash me-1"></i><?= h(t('secchk_chk_homehook_on')) ?></span>
		<a class="btn btn-sm btn-outline-secondary text-nowrap" href="<?= h(nu_url('procedures', ['id' => (string)(NuHomeHook::procedure()['id'] ?? '')])) ?>"><?= h(t('hook_error_open')) ?></a></div>
<?php endif; ?>
<?php [$tzName, $tzSource] = nu_timezone(); if ($tzSource === 'server'): // no $nuConfigTimezone and no Setup time zone: times follow the host ?>
	<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><span class="flex-grow-1"><i class="bi bi-clock me-1"></i><?= h(t('tz_server_default', $tzName, date('H:i'))) ?></span>
		<a class="btn btn-sm btn-warning text-nowrap" href="<?= h(nu_url('configfile')) ?>"><i class="bi bi-filetype-php me-1"></i><?= h(t('cfg_title')) ?></a></div>
<?php endif; ?>
<?php
// Database (0.8.282): the primary tool (phpMyAdmin or Adminer, $nuConfigPrimaryDatabaseManagement), ▾ = the other one; a card that
// explains where to copy them when none is there.
$dbTools = NuVendor::dbTools();
?>
<?php if (!$dbTools && in_array(nu_db()->type, ['mysql', 'sqlsrv'], true)): ?>
<div class="row g-3 mb-4">
	<?php if (!$dbTools): ?>
		<div class="col-md-6">
			<div class="card shadow-sm h-100" id="vendorDbMissing">
				<div class="card-body">
					<h2 class="h5"><i class="bi bi-database me-2"></i><?= h(t('vendor_pma')) ?> <span class="badge ms-1 text-bg-secondary"><?= h(t('vendor_missing')) ?></span></h2>
					<?php if ($mysql): ?>
						<p class="small text-body-secondary mb-1"><strong>phpMyAdmin</strong> – <?= h(t('vendor_copy', NuVendor::APPS['PMA']['dir'])) ?> <?= nu_lp_hint() ?></p>
						<code class="small d-block text-break mb-3"><?= h(NuVendor::dir('PMA')) ?>/</code>
					<?php endif; ?>
					<p class="small text-body-secondary mb-1"><strong>Adminer</strong> – <?= h(t('vendor_adminer_copy')) ?> <?= nu_lp_hint() ?></p>
					<code class="small d-block text-break"><?= h(NuVendor::dir('ADM')) ?>/adminer.php</code>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($mysql): ?>
	<div class="card shadow-sm mt-4" id="innodb">
		<div class="card-body">
			<h2 class="h5"><i class="bi bi-hdd-stack me-2"></i><?= h(t('vendor_innodb_title')) ?>
				<span class="badge ms-1 <?= $engines ? 'text-bg-warning' : 'text-bg-success' ?>"><?= $engines ? h(t('vendor_innodb_count', count($engines))) : h(t('vendor_innodb_ok')) ?></span></h2>
			<p class="small text-body-secondary"><?= h(t('vendor_innodb_hint')) ?></p>
			<?php if ($engines): ?>
				<div class="table-responsive mb-3 nuEngineList">
					<table class="table table-sm nu-table mb-0">
						<thead><tr><th><?= h(t('vendor_innodb_table')) ?></th><th><?= h(t('vendor_innodb_engine')) ?></th><th class="text-end"><?= h(t('vendor_innodb_rows')) ?></th></tr></thead>
						<tbody><?php foreach ($engines as $e): ?><tr><td><code><?= h($e['name']) ?></code></td><td><?= h((string)$e['engine']) ?></td><td class="text-end"><?= h(number_format((int)$e['row_count'], 0, '.', "'")) ?></td></tr><?php endforeach; ?></tbody>
					</table>
				</div>
				<form method="post" action="<?= h(nu_url('vendor')) ?>" data-confirm="<?= h(t('vendor_innodb_confirm', count($engines))) ?>" data-confirm-ok="<?= h(t('vendor_innodb_convert')) ?>" data-confirm-class="btn btn-primary">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="innodb">
					<button class="btn btn-warning btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('vendor_innodb_convert')) ?></button>
				</form>
			<?php endif; ?>
		</div>
	</div>
<?php endif; ?>
<?php
// demo mode (core/nudemo.php): who the demo users are and what keeps them from working
$demo = NuDemo::status();
$demoProblems = array_sum(array_map(static fn($d) => count($d['problems']), $demo));
$demoForms = trim((string)(nu_cfg('nuConfigDemoSavingAllowedIds') ?? ''));
?>
<div class="card shadow-sm mt-4" id="demomode">
	<div class="card-body">
		<h2 class="h5"><i class="bi bi-eye me-2"></i><?= h(t('demo_title')) ?>
			<span class="badge ms-1 <?= !$demo ? 'text-bg-secondary' : ($demoProblems ? 'text-bg-warning' : 'text-bg-success') ?>"><?= h(t($demo ? 'demo_state_on' : 'demo_state_off')) ?></span></h2>
		<?php if (!$demo): ?>
			<p class="small text-body-secondary mb-0"><?= h(t('demo_off')) ?></p>
		<?php else: ?>
			<p class="small text-body-secondary mb-2"><?= h(t('demo_on')) ?></p>
			<ul class="list-unstyled small mb-2">
				<?php foreach ($demo as $d): ?>
					<li class="mb-1"><i class="bi bi-person me-1"></i>
						<?php if ($d['id'] !== ''): ?><a href="<?= h(nu_url('user', ['id' => $d['id']])) ?>"><?= h($d['login']) ?></a><?php else: ?><code><?= h($d['name']) ?></code><?php endif; ?>
						<?php foreach ($d['problems'] as $pr): ?><span class="text-danger ms-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h($pr) ?></span><?php endforeach; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="small text-body-secondary"><?= h($demoForms !== '' ? t('demo_allowed_forms', $demoForms) : t('demo_allowed_none')) ?></div>
			<?php $dp = NuDemo::procedures(); ?>
			<div class="small text-body-secondary"><?= h($dp ? t('demo_procs', implode(', ', $dp)) : t('demo_procs_none')) ?></div>
			<div class="small text-body-secondary"><?= h(t('demo_save_limit_info', NuDemo::SAVES_PER_HOUR, NuDemo::SAVES_PER_HOUR_ALL)) ?></div>
			<?php if ($demoForms !== ''):
				$dTables = NuDemoData::tables(); $dInfo = NuDemoData::info(); $dSet = NuDemoData::setting(); ?>
				<h3 class="h6 mt-3" id="demodata"><?= h(t('demo_data_title')) ?>
					<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('demo_data_tip')) ?>" title="<?= h(t('demo_data_tip')) ?>"><i class="bi bi-info-circle"></i></span></h3>
				<div class="small text-body-secondary mb-1"><?= h($dTables ? t('demo_data_tables', implode(', ', $dTables)) : t('demo_snap_no_tables')) ?></div>
				<div class="small mb-2" id="demoSnapState"><?php if ($dInfo): ?><i class="bi bi-camera me-1"></i><?= h(t('demo_snap_info', date('d.m.Y H:i', (int)$dInfo['created']), (string)$dInfo['by'], array_sum((array)$dInfo['tables']))) ?>
					<?php if ((int)$dSet['last'] > 0): ?> · <?= h(t('demo_reset_last', date('d.m.Y H:i', (int)$dSet['last']))) ?><?php endif; ?>
					<?php else: ?><span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('demo_snap_none')) ?></span><?php endif; ?></div>
				<?php if (empty(NuAuth::user()['demo'])): ?>
				<div class="d-flex flex-wrap gap-2 align-items-center">
					<form method="post" action="<?= h(nu_url('vendor')) ?>"<?= $dInfo ? ' data-confirm="' . h(t('demo_snap_confirm')) . '"' : '' ?>><?= nu_csrf_field() ?><input type="hidden" name="action" value="demo_snapshot">
						<button class="btn btn-sm btn-outline-secondary" type="submit"<?= $dTables ? '' : ' disabled' ?>><i class="bi bi-camera me-1"></i><?= h(t('demo_snap_take')) ?></button></form>
					<?php if ($dInfo): ?>
					<form method="post" action="<?= h(nu_url('vendor')) ?>" data-confirm="<?= h(t('demo_reset_confirm')) ?>" data-confirm-ok="<?= h(t('demo_reset_now')) ?>" data-confirm-class="btn btn-danger"><?= nu_csrf_field() ?><input type="hidden" name="action" value="demo_reset">
						<button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('demo_reset_now')) ?></button></form>
					<?php endif; ?>
					<form method="post" action="<?= h(nu_url('vendor')) ?>" class="d-flex gap-2 align-items-center"><?= nu_csrf_field() ?><input type="hidden" name="action" value="demo_interval">
						<label class="small text-body-secondary" for="demoHours"><?= h(t('demo_reset_every')) ?></label>
						<select class="form-select form-select-sm w-auto js-autosubmit" id="demoHours" name="hours">
							<?php foreach (NuDemoData::HOURS as $hh): ?><option value="<?= $hh ?>"<?= (int)$dSet['hours'] === $hh ? ' selected' : '' ?>><?= h($hh === 0 ? t('demo_reset_never') : ($hh % 24 === 0 ? ($hh === 24 ? t('user_autologin_day') : t('user_autologin_days', intdiv($hh, 24))) : t('ltok_hours', $hh))) ?></option><?php endforeach; ?>
						</select></form>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
</div>
<?php
nu_layout_end();
