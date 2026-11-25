<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Reset database (core/nudbreset.php): the globeadmin on MySQL / MariaDB or SQL Server
 * (SQL Server: clean installation only – nuBuilder Next's install scripts).
 * 1. action=check: a file chosen (nubuilder4.sql of nuBuilder Forte, _install/, _update/) or uploaded – read and checked, nothing changes.
 * 2. action=run: the database name typed, password confirmed recently – all tables deleted, the file imported, nuBuilder Next's tables
 *    created (optionally a backup first and the sample application afterwards). Then: the setup wizard.
 */
if (!NuDbReset::allowed()) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}

$S = &$_SESSION[NuSess::DB_RESET];
if (!is_array($S)) $S = [];
if (($_GET['fresh'] ?? '') === '1' && ($fr = NuDbReset::finishFresh()) !== null) {   // second half of a fresh installation (new code)
	$S = ['fresh' => $fr];
	nu_redirect(nu_url('dbreset'));
}
['S' => $S]
	= NuDbresetPage::post(get_defined_vars());   // the POST actions (code review R7, nudbresetpage.php)

$job = null;
if (!empty($S['job'])) {                                            // a reset in steps
	$job = NuDbReset::job((string)$S['job']);
	if ($job && $job['phase'] === 'done') { $S = ['done' => $job['result']]; $job = null; }
	elseif (!$job || $job['phase'] === 'error') {
		nu_flash('danger', $job ? t('dbr_err_failed', (string)$job['error']) : t('dbr_err_no_job'));
		$S = [];
		nu_redirect(nu_url('dbreset'));
	}
}
$chk = $S['check'] ?? null;
$done = $S['done'] ?? null;
$fresh = $S['fresh'] ?? null;
if ($done || $fresh) $S = [];                                       // the result is shown once
$dbName = NuDbReset::dbName();
nu_layout_start(t('dbr_title'), 'dbreset');
?>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('dbr_title')) ?><?= NuListView::infoTip(t('dbr_hint')) ?></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?></div>
	</div>
	<div class="card-body">
	<?php if (!$job): ?>
		<div class="alert alert-danger d-flex gap-2 align-items-start nuDangerZone" id="dbrDanger" role="note">
			<i class="bi bi-exclamation-octagon-fill fs-5 lh-1" aria-hidden="true"></i>
			<div><div class="fw-semibold"><?= h(t('dbr_danger_title')) ?></div><div class="small"><?= h(t('dbr_danger_text', $dbName)) ?></div></div>
		</div>
	<?php endif; ?>
	<?php if ($job): ?>
		<div class="alert alert-warning" id="dbrJobRunning"><i class="bi bi-hourglass-split me-1"></i><?= h(t('dbr_job_running', (string)$job['database'])) ?></div>
		<?php // the page goes through the steps (assets/js/nudbreset.js): the token is the right of ?p=dbresetstep ?>
		<div class="modal fade" id="dbrProgress" tabindex="-1" aria-labelledby="dbrProgressTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false"
			data-nu-dbr-job="<?= h((string)$S['job']) ?>" data-nu-dbr-step="<?= h(nu_url('dbresetstep')) ?>" data-nu-dbr-done="<?= h(nu_url('dbreset')) ?>"
			data-nu-dbr-lost="<?= h(t('dbr_job_lost')) ?>">
			<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
				<div class="modal-body p-4 text-center">
					<div class="spinner-border text-danger mb-3 nuUpdSpinner" role="status" aria-hidden="true"></div>
					<h2 class="h5 mb-3" id="dbrProgressTitle"><?= h(t('dbr_progress_title', (string)$job['database'])) ?></h2>
					<div class="progress mb-2" role="progressbar" aria-labelledby="dbrPhase" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
						id="dbrBar"><div class="progress-bar bg-danger" id="dbrBarFill"></div></div>
					<p class="small mb-3" id="dbrPhase" aria-live="polite"><?= h(t('dbr_ph_' . ($job['phase'] === 'import' ? 'drop' : $job['phase']))) ?></p>
					<div class="alert alert-warning text-start small mb-2 py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('dbr_progress_keep')) ?></div>
					<p class="small text-body-secondary text-start mb-0"><?= h(t('dbr_progress_info')) ?></p>
				</div>
			</div></div>
		</div>
	<?php elseif ($fresh): ?>
		<div class="alert <?= empty($fresh['error']) ? 'alert-success' : 'alert-warning' ?>" id="dbrFreshDone">
			<p class="fw-semibold mb-1"><?= h(t('dbr_fresh_done', $fresh['version'])) ?></p>
			<ul class="mb-0 small">
				<li><?= h(t('dbr_fresh_from', $fresh['from'], $fresh['version'])) ?></li>
				<li><?= h(t('dbr_done_counts', $fresh['dropped'], $fresh['statements'], count($fresh['steps']))) ?></li>
				<?php foreach ($fresh['packs'] as [$lib, $ok, $err]): ?><li class="<?= $ok ? '' : 'text-danger' ?>"><?= h($ok ? t('dbr_fresh_pack', $lib) : t('dbr_fresh_pack_err', $lib, $err)) ?></li><?php endforeach; ?>
				<?php if ($fresh['backup'] !== ''): ?><li><?= h(t('dbr_done_backup', $fresh['backup'])) ?></li><?php endif; ?>
				<?php if ($fresh['sample'] === 'ok'): ?><li><?= h(t('dbr_done_sample')) ?></li>
				<?php elseif ($fresh['sample'] !== ''): ?><li class="text-danger"><?= h(t('dbr_done_sample_err', $fresh['sample'])) ?></li><?php endif; ?>
				<?php if (!empty($fresh['error'])): ?><li class="text-danger"><?= h($fresh['error']) ?></li><?php endif; ?>
			</ul>
		</div>
		<div class="d-flex flex-wrap gap-2">
			<a class="btn btn-primary" id="dbrWizard" href="<?= h(nu_url('setupwizard')) ?>"><i class="bi bi-magic me-1"></i><?= h(t('dbr_open_wizard')) ?></a>
			<a class="btn btn-outline-secondary" href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a>
		</div>
	<?php elseif ($done): ?>
		<div class="alert alert-success" id="dbrDone">
			<p class="fw-semibold mb-1"><?= h(t('dbr_done')) ?></p>
			<ul class="mb-0 small">
				<li><?= h(t('dbr_done_counts', $done['dropped'], $done['statements'], count($done['steps']))) ?></li>
				<?php if (!empty($done['skipped'])): ?><li class="text-warning-emphasis" id="dbrSkipped"><?= h(t('dbr_done_skipped', count($done['skipped']), implode(', ', $done['skipped']))) ?></li><?php endif; ?>
				<?php if (!empty($done['skipped_sql'])): ?><li class="text-warning-emphasis"><?= nu_skipped_views_box((array)$done['skipped_sql'], 'dbr') ?></li><?php endif; ?>
				<?php if ($done['backup'] !== ''): ?><li><?= h(t('dbr_done_backup', $done['backup'])) ?></li><?php endif; ?>
				<?php if ($done['sample'] === 'ok'): ?><li><?= h(t('dbr_done_sample')) ?></li>
				<?php elseif ($done['sample'] !== ''): ?><li class="text-danger"><?= h(t('dbr_done_sample_err', $done['sample'])) ?></li><?php endif; ?>
			</ul>
		</div>
		<div class="d-flex flex-wrap gap-2">
			<a class="btn btn-primary" id="dbrWizard" href="<?= h(nu_url('setupwizard')) ?>"><i class="bi bi-magic me-1"></i><?= h(t('dbr_open_wizard')) ?></a>
			<a class="btn btn-outline-secondary" href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a>
		</div>
	<?php elseif ($chk && $chk['ok']):
		$cur = $chk['current'];
		$pkg = ($chk['kind'] ?? '') === 'package';
		$clean = ($chk['kind'] ?? '') === 'clean'; ?>
		<div class="alert alert-danger" id="dbrWarn">
			<p class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= h(t('dbr_warn_title', $chk['database'])) ?></p>
			<p class="mb-0 small"><?= h(t('dbr_warn', count($cur))) ?><?= $pkg ? ' ' . h(t('dbr_fresh_warn', $chk['version'])) : '' ?></p>
		</div>
		<dl class="row small mb-3" id="dbrCheck"<?= $pkg ? ' data-kind="package"' : ($clean ? ' data-kind="clean"' : '') ?>>
			<dt class="col-sm-3"><?= h(t('dbr_file')) ?></dt><dd class="col-sm-9 text-break"><?= h($chk['file']) ?><?= $clean ? '' : ' (' . h(number_format($chk['bytes'] / 1048576, 1) . ' MB') . ')' ?></dd>
			<?php if ($clean): ?>
			<dt class="col-sm-3"><?= h(t('dbr_creates')) ?></dt><dd class="col-sm-9" id="dbrCleanCreates"><?= h(t('dbr_clean_creates', $chk['tables'])) ?></dd>
			<?php elseif ($pkg): ?>
			<dt class="col-sm-3"><?= h(t('dbr_fresh_version')) ?></dt><dd class="col-sm-9"
				id="dbrPkgVersion"><?= h(t('dbr_fresh_from', NU_VERSION, $chk['version'])) ?> <span class="badge text-bg-success"><?= h(t('dbr_fresh_signed')) ?></span></dd>
			<dt class="col-sm-3"><?= h(t('dbr_creates')) ?></dt><dd class="col-sm-9"><?= h(t('dbr_fresh_creates')) ?></dd>
			<dt class="col-sm-3"><?= h(t('dbr_fresh_packs')) ?></dt><dd class="col-sm-9"><?= h(t('dbr_fresh_packs_val', $chk['installed_packs'] ? implode(', ', $chk['installed_packs']) : '–', $chk['packs'] ? implode(', ', $chk['packs']) : '–')) ?></dd>
			<dt class="col-sm-3"><?= h(t('dbr_fresh_keeps')) ?></dt><dd class="col-sm-9"><?= h(t('dbr_fresh_keeps_val')) ?></dd>
			<?php else: ?>
			<dt class="col-sm-3"><?= h(t('dbr_creates')) ?></dt><dd class="col-sm-9"><?= h(t('dbr_creates_val', $chk['tables'], $chk['app'], $chk['forms'], $chk['users'])) ?></dd>
			<?php endif; ?>
			<dt class="col-sm-3"><?= h(t('dbr_deletes')) ?></dt><dd class="col-sm-9">
				<details><summary><?= h(t('dbr_deletes_val', count($cur))) ?></summary>
					<div class="table-responsive mt-2 dbrTables"><table class="table table-sm nu-table mb-0" id="dbrTables">
						<thead><tr><th><?= h(t('dbr_table')) ?></th><th class="text-end"><?= h(t('dbr_rows')) ?></th></tr></thead>
						<tbody><?php foreach ($cur as [$tn, $kind, $rows]): ?><tr><td><code><?= h($tn) ?></code><?= $kind === 'VIEW' ? ' <span class="badge text-bg-secondary">VIEW</span>' : '' ?></td><td class="text-end"><?= $rows === null ? '' : (int)$rows ?></td></tr><?php endforeach; ?></tbody>
					</table></div>
				</details></dd>
		</dl>
		<?php if (!empty($chk['views_blocked'])): ?>
		<div class="alert alert-warning small" id="dbrViewsWarn">
			<p class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('dbr_views_title', count($chk['views']))) ?></p>
			<p class="mb-1"><?= h(t('dbr_views_text')) ?></p>
			<p class="mb-0 text-break" id="dbrViewsList"><?= h(implode(', ', $chk['views'])) ?></p>
		</div>
		<?php endif; ?>
		<form method="post" id="dbrRunForm" data-nu-once data-nu-progress="#dbrProgress">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="run">
			<?php if (!empty($chk['views_blocked'])): ?>
			<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="skip_views" value="1" id="dbrSkipViews" required>
				<label class="form-check-label fw-semibold" for="dbrSkipViews"><?= h(t('dbr_views_ack')) ?></label></div>
			<?php endif; ?>
			<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="backup" value="1" id="dbrBackup"<?= !empty($S['backup']) ? ' checked' : '' ?>>
				<label class="form-check-label" for="dbrBackup"><?= h(t('dbr_opt_backup')) ?></label></div>
			<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="sample" value="1" id="dbrSample"<?= !empty($S['sample']) ? ' checked' : '' ?>>
				<label class="form-check-label" for="dbrSample"><?= h(t('dbr_opt_sample')) ?></label></div>
			<label class="form-label" for="dbrConfirm"><?= h(t('dbr_confirm', $chk['database'])) ?></label>
			<input class="form-control mb-3 dbrNarrow" name="confirm" id="dbrConfirm" autocomplete="off" spellcheck="false" required>
			<div class="d-flex flex-wrap gap-2">
				<button type="submit" class="btn btn-danger" id="dbrRun" data-nu-busy="<?= h(t('dbr_running')) ?>"><i class="bi bi-trash3 me-1"></i><?= h(t($pkg ? 'dbr_fresh_run' : 'dbr_run')) ?></button>
				<button type="submit" class="btn btn-outline-secondary" form="dbrCancel"><?= h(t('cancel')) ?></button>
			</div>
		</form>
		<form method="post" id="dbrCancel"><?= nu_csrf_field() ?><input type="hidden" name="action" value="cancel"></form>
		<?php // shown while the reset runs (the owner: as on the update page) – no close button, no Escape ?>
		<div class="modal fade" id="dbrProgress" tabindex="-1" aria-labelledby="dbrProgressTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
			<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
				<div class="modal-body p-4 text-center">
					<div class="spinner-border text-danger mb-3 nuUpdSpinner" role="status" aria-hidden="true"></div>
					<h2 class="h5 mb-2" id="dbrProgressTitle"><?= h(t('dbr_progress_title', $chk['database'])) ?></h2>
					<p class="mb-3 small text-body-secondary"><?= h(t($pkg ? 'dbr_progress_steps_pkg' : 'dbr_progress_steps')) ?></p>
					<div class="alert alert-warning text-start small mb-2 py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('dbr_progress_keep')) ?></div>
					<p class="small text-body-secondary text-start mb-0"><?= h(t('dbr_progress_info')) ?></p>
				</div>
			</div></div>
		</div>
	<?php else:
		$src = NuDbReset::sources(); ?>
		<details class="mb-3 nuMoreHint"><summary class="small"><?= h(t('more_info')) ?></summary><p class="mb-0 mt-1"><?= h(t('dbr_intro', $dbName)) ?></p></details><?php /* review M3 */ ?>
		<?php if ($chk && !$chk['ok']): ?><div class="alert alert-danger small text-break" id="dbrBad"><?= h(t('dbr_err_check', $chk['error'])) ?></div><?php endif; ?>
		<form method="post" enctype="multipart/form-data" id="dbrForm" data-nu-once>
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="check">
			<label class="form-label" for="dbrSource"><?= h(t('dbr_source')) ?></label>
			<select class="form-select mb-2 dbrWide" name="source" id="dbrSource">
				<?php if (!$src): ?><option value=""><?= h(t('dbr_source_none')) ?></option><?php endif; ?>
				<?php foreach ($src as $label => $p): ?><option value="<?= h($label) ?>"><?= h($label) ?></option><?php endforeach; ?>
			</select>
			<?php if (NuDbReset::filesAllowed()): ?>
			<label class="form-label" for="dbrUpload"><?= h(t('dbr_upload', NuUpdate::uploadLimitText())) ?></label>
			<input class="form-control mb-1 dbrWide" type="file" name="upload" id="dbrUpload" accept=".sql,.gz,.zip">
			<div class="form-text mb-3" id="dbrFreshHint"><?= h(t('dbr_fresh_hint')) ?></div>
			<?php else: ?>
			<div class="form-text mb-3" id="dbrMssqlHint"><?= h(t('dbr_mssql_hint')) ?></div>
			<?php endif; ?>
			<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="backup" value="1" id="dbrBackup0" checked>
				<label class="form-check-label" for="dbrBackup0"><?= h(t('dbr_opt_backup')) ?></label></div>
			<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="sample" value="1" id="dbrSample0">
				<label class="form-check-label" for="dbrSample0"><?= h(t('dbr_opt_sample')) ?></label></div>
			<button type="submit" class="btn btn-primary" id="dbrCheckBtn" data-nu-busy="<?= h(t('dbr_checking')) ?>"><i class="bi bi-search me-1"></i><?= h(t('dbr_check')) ?></button>
		</form>
		<p class="small text-body-secondary mt-3 mb-0"><?= h(t('dbr_cli')) ?> <code>php nucron.php reset --sql=&lt;file&gt;|--clean [--backup] [--sample] [--skip-views] --yes</code></p>
	<?php endif; ?>
	</div>
</div>
<?php
nu_layout_end($job ? '<script src="' . nu_asset('assets/js/nudbreset.js') . '"></script>' : '');
