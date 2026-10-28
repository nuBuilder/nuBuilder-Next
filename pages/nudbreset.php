<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Reset database (0.8.628, core/nudbreset.php): only in developer mode (globeadmin + $nuConfigDevMode) on MySQL / MariaDB.
 * 1. action=check: a file chosen (nubuilder4.sql of nuBuilder Forte, _install/, _update/) or uploaded – read and checked, nothing changes.
 * 2. action=run: the database name typed, password confirmed recently – all tables deleted, the file imported, nuBuilder Next's tables
 *    created (optionally a backup first and the sample application afterwards). Then: the setup wizard.
 */
if (!NuHash::devMode() || !NuDbReset::available()) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}

$S = &$_SESSION['nu_dbreset'];
if (!is_array($S)) $S = [];
if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'check') {
		try {
			if (!empty($_FILES['upload']['name'])) $path = NuDbReset::storeUpload($_FILES['upload']);
			else {
				$src = NuDbReset::sources();
				$path = $src[nu_post('source')] ?? null;
				if ($path === null) throw new RuntimeException(t('dbr_err_nofile'));
			}
			$S = ['path' => $path, 'check' => NuDbReset::check($path), 'backup' => nu_post('backup') === '1', 'sample' => nu_post('sample') === '1'];
		} catch (Throwable $e) {
			$S = [];
			nu_flash('danger', $e->getMessage());
		}
	} elseif ($action === 'run' && !empty($S['check']['ok'])) {
		NuSecurity::requireRecentAuth(nu_url('dbreset'));
		if (trim(nu_post('confirm')) !== (string)$S['check']['database']) {
			nu_flash('danger', t('dbr_err_confirm'));
		} else {
			try {
				$r = NuDbReset::run((string)$S['path'], nu_post('backup') === '1', nu_post('sample') === '1');
				$S = ['done' => $r];
			} catch (Throwable $e) {
				error_log('nuBuilder Next reset database: ' . $e->getMessage());
				nu_flash('danger', t('dbr_err_failed', $e->getMessage()));
			}
		}
	} elseif ($action === 'cancel') {
		$S = [];
	}
	nu_redirect(nu_url('dbreset'));
}

$chk = $S['check'] ?? null;
$done = $S['done'] ?? null;
if ($done) $S = [];                                       // the result is shown once
$dbName = (string)nu_db()->value('SELECT DATABASE()');
nu_layout_start(t('dbr_title'), 'dbreset');
?>
<div class="card shadow-sm nuListCard">
	<div class="card-header nuListHead nuTitleBar">
		<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1"><?= h(t('dbr_title')) ?><?= NuListView::infoTip(t('dbr_hint')) ?></h1>
		<div class="nuTitleSub"><span class="badge text-bg-secondary">Dev</span></div>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?></div>
	</div>
	<div class="card-body">
	<?php if ($done): ?>
		<div class="alert alert-success" id="dbrDone">
			<p class="fw-semibold mb-1"><?= h(t('dbr_done')) ?></p>
			<ul class="mb-0 small">
				<li><?= h(t('dbr_done_counts', $done['dropped'], $done['statements'], count($done['steps']))) ?></li>
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
		$cur = $chk['current']; ?>
		<div class="alert alert-danger" id="dbrWarn">
			<p class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= h(t('dbr_warn_title', $chk['database'])) ?></p>
			<p class="mb-0 small"><?= h(t('dbr_warn', count($cur))) ?></p>
		</div>
		<dl class="row small mb-3" id="dbrCheck">
			<dt class="col-sm-3"><?= h(t('dbr_file')) ?></dt><dd class="col-sm-9 text-break"><?= h($chk['file']) ?> (<?= h(number_format($chk['bytes'] / 1048576, 1) . ' MB') ?>)</dd>
			<dt class="col-sm-3"><?= h(t('dbr_creates')) ?></dt><dd class="col-sm-9"><?= h(t('dbr_creates_val', $chk['tables'], $chk['app'], $chk['forms'], $chk['users'])) ?></dd>
			<dt class="col-sm-3"><?= h(t('dbr_deletes')) ?></dt><dd class="col-sm-9">
				<details><summary><?= h(t('dbr_deletes_val', count($cur))) ?></summary>
					<div class="table-responsive mt-2 dbrTables"><table class="table table-sm nu-table mb-0" id="dbrTables">
						<thead><tr><th><?= h(t('dbr_table')) ?></th><th class="text-end"><?= h(t('dbr_rows')) ?></th></tr></thead>
						<tbody><?php foreach ($cur as [$tn, $kind, $rows]): ?><tr><td><code><?= h($tn) ?></code><?= $kind === 'VIEW' ? ' <span class="badge text-bg-secondary">VIEW</span>' : '' ?></td><td class="text-end"><?= $rows === null ? '' : (int)$rows ?></td></tr><?php endforeach; ?></tbody>
					</table></div>
				</details></dd>
		</dl>
		<form method="post" id="dbrRunForm" data-nu-once>
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="run">
			<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="backup" value="1" id="dbrBackup"<?= !empty($S['backup']) ? ' checked' : '' ?>>
				<label class="form-check-label" for="dbrBackup"><?= h(t('dbr_opt_backup')) ?></label></div>
			<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="sample" value="1" id="dbrSample"<?= !empty($S['sample']) ? ' checked' : '' ?>>
				<label class="form-check-label" for="dbrSample"><?= h(t('dbr_opt_sample')) ?></label></div>
			<label class="form-label" for="dbrConfirm"><?= h(t('dbr_confirm', $chk['database'])) ?></label>
			<input class="form-control mb-3 dbrNarrow" name="confirm" id="dbrConfirm" autocomplete="off" spellcheck="false" required>
			<div class="d-flex flex-wrap gap-2">
				<button type="submit" class="btn btn-danger" id="dbrRun" data-nu-busy="<?= h(t('dbr_running')) ?>"><i class="bi bi-trash3 me-1"></i><?= h(t('dbr_run')) ?></button>
				<button type="submit" class="btn btn-outline-secondary" form="dbrCancel"><?= h(t('cancel')) ?></button>
			</div>
		</form>
		<form method="post" id="dbrCancel"><?= nu_csrf_field() ?><input type="hidden" name="action" value="cancel"></form>
	<?php else:
		$src = NuDbReset::sources(); ?>
		<p class="mb-3"><?= h(t('dbr_intro', $dbName)) ?></p>
		<?php if ($chk && !$chk['ok']): ?><div class="alert alert-danger small text-break" id="dbrBad"><?= h(t('dbr_err_check', $chk['error'])) ?></div><?php endif; ?>
		<form method="post" enctype="multipart/form-data" id="dbrForm" data-nu-once>
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="check">
			<label class="form-label" for="dbrSource"><?= h(t('dbr_source')) ?></label>
			<select class="form-select mb-2 dbrWide" name="source" id="dbrSource">
				<?php if (!$src): ?><option value=""><?= h(t('dbr_source_none')) ?></option><?php endif; ?>
				<?php foreach ($src as $label => $p): ?><option value="<?= h($label) ?>"><?= h($label) ?></option><?php endforeach; ?>
			</select>
			<label class="form-label" for="dbrUpload"><?= h(t('dbr_upload', NuUpdate::uploadLimitText())) ?></label>
			<input class="form-control mb-3 dbrWide" type="file" name="upload" id="dbrUpload" accept=".sql,.gz,.zip">
			<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="backup" value="1" id="dbrBackup0" checked>
				<label class="form-check-label" for="dbrBackup0"><?= h(t('dbr_opt_backup')) ?></label></div>
			<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="sample" value="1" id="dbrSample0">
				<label class="form-check-label" for="dbrSample0"><?= h(t('dbr_opt_sample')) ?></label></div>
			<button type="submit" class="btn btn-primary" id="dbrCheckBtn" data-nu-busy="<?= h(t('dbr_checking')) ?>"><i class="bi bi-search me-1"></i><?= h(t('dbr_check')) ?></button>
		</form>
		<p class="small text-body-secondary mt-3 mb-0"><?= h(t('dbr_cli')) ?> <code>php cron.php reset --sql=&lt;file&gt; [--backup] [--sample] --yes</code></p>
	<?php endif; ?>
	</div>
</div>
<?php
nu_layout_end();
