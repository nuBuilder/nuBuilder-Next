<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Database set-up (0.8.645, core/nudbstate.php): nuconfig.php exists, but the database is not ready – written by hand (or copied from
 * nuBuilder Forte) for an empty database, tables missing, or a nuBuilder Forte database older than 4.9.
 * Who: the globeadmin (signed in with nuconfig.php's name and password – that works without tables) or whoever knows the setup token
 * (_install/setup-token.txt) / created install.allow, as in the installer. Public route; it only works while the database is not ready.
 *   empty   → content: nuBuilder Next's install scripts, or (MySQL) a nuBuilder Forte SQL dump of any 4.x (upload / file in _install/)
 *   partial → "Create missing tables" (the install scripts add only what is missing)
 *   old     → explained (what to do), nothing changed
 */
NuBranding::$off = true;                                  // the database is not ready: the default look
NuCustomCode::$context = 'none';

$user = NuAuth::user();
$isGa = $user && !empty($user['globeadmin']) && empty($user['demo']);
$done = $_SESSION['nu_dbs_done'] ?? null;
if (!is_array($done) && NuDbState::ready()) nu_redirect(nu_url($user ? 'home' : 'login'));
$st = NuDbState::check();
if (!is_array($done) && $st['error'] !== '') throw new RuntimeException($st['error']);

$auth = $isGa || !empty($_SESSION['nu_dbs_auth']);
$needsToken = !NuInstall::isLocal();
if (!$auth && !$needsToken) $auth = true;
$errors = [];

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'token' && !$auth) {
		if (NuInstall::locked()) $errors['token'] = t('ins_err_locked');
		else {
			$entered = trim(nu_post('token'));
			$ok = NuInstall::allowFileExists() || ($entered !== '' && NuInstall::checkToken($entered));
			if ($ok) { session_regenerate_id(true); $_SESSION['nu_dbs_auth'] = true; nu_redirect(nu_url('dbsetup')); }
			$errors['token'] = t($entered === '' ? 'ins_err_token_empty' : 'ins_err_token');
		}
	} elseif ($auth && in_array($action, ['setup', 'repair'], true) && !is_array($done)) {
		try {
			// an existing database (tables missing): the integrity check first (0.8.698) – problems need the tick "go on anyway"
			if ($action === 'repair' && NuIntegrity::blocking(NuIntegrity::run(), ['tables']) && nu_check('integrity_ack') !== '1') throw new RuntimeException(t('ic_ack_needed'));
			$choice = $action === 'setup' && nu_post('choice') === 'upload' ? 'upload' : 'nunext';
			$file = null;
			if ($choice === 'upload') {
				$path = null;
				if (($_FILES['dump']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
					[$path, $upErr] = NuInstall::storeUpload($_FILES['dump']);
					if ($upErr !== '') throw new RuntimeException($upErr);
				} elseif (($pick = nu_post('dump_pick')) !== '' && isset(NuInstall::dumpCandidates()[$pick])) {
					$path = NuInstall::dumpCandidates()[$pick];
				}
				if ($path === null) throw new RuntimeException(t('ins_dump_err_none'));
				$sql = NuInstall::dumpSql($path);
				if ($sql === null) throw new RuntimeException(t('ins_dump_err_read'));
				$file = (string)preg_replace('/\.(sql|sql\.gz|zip)$/i', '', $path) . '.checked.sql';
				if (@file_put_contents($file, $sql) === false) throw new RuntimeException(t('ins_dump_err_read'));
			}
			$r = NuDbState::setUp($choice, $file);
			foreach (glob(NU_ROOT . '/_install/nunext-lib-*.zip') ?: [] as $f) @rename($f, NuUpdate::folderDir() . '/' . basename($f));   // library packs stay offered
			NuInstall::cleanup();                                 // token, uploads, install.allow
			$_SESSION['nu_dbs_done'] = ['state' => $st['state'], 'imported' => $r['imported'], 'upgraded' => $r['upgraded'], 'steps' => count($r['steps'])];
			unset($_SESSION['nu_dbs_auth']);
		} catch (Throwable $e) {
			error_log('nuBuilder Next database set-up: ' . $e->getMessage());
			nu_flash('danger', t('dbs_err_failed') . ' ' . $e->getMessage());
		}
		nu_redirect(nu_url('dbsetup'));
	}
}
if (is_array($done) && !nu_is_post()) unset($_SESSION['nu_dbs_done']);   // shown once

$dbName = (string)nu_cfg('nuConfigDBName');
// integrity check of an existing database (0.8.698): tables missing (before "Create missing tables") or older than nuBuilder Forte 4.9
$icR = !is_array($done) && $auth && in_array($st['state'], ['partial', 'old'], true) ? NuIntegrity::run() : null;
$icHtml = static fn(?string $form): string => $icR === null ? '' : '<div class="border rounded p-2 mb-3' . ($icR['errors'] ? ' border-danger' : '') . '" id="dbsIntegrity">'
	. '<div class="fw-semibold small mb-2"><i class="bi bi-clipboard2-pulse me-1"></i>' . h(t('ic_title')) . NuListView::infoTip(t('ic_tip')) . '</div>'
	. NuIntegrity::html($icR, $form) . '</div>';
$isMy = nu_db()->type === 'mysql';
nu_center_start(t('dbs_title'), $dbName !== '' ? t('dbs_subtitle', $dbName) : '', 'bi-database-gear');
?>
<div id="dbsPage" data-state="<?= h(is_array($done) ? 'done' : $st['state']) ?>">
<?php if (is_array($done)): ?>
	<div class="text-center" id="dbsDone">
		<i class="bi bi-check-circle text-success display-6"></i>
		<p class="mt-2 mb-1 fw-semibold"><?= h(t($done['state'] === 'partial' ? 'dbs_done_repair' : 'dbs_done_setup')) ?></p>
		<?php if ($done['imported']): ?><p class="small text-body-secondary mb-1"><?= h(t('ins_done_imported', $done['imported'])) ?></p><?php endif; ?>
		<?php $up = $done['upgraded']; if ($up && ($up['tables'] + $up['columns'] + $up['changed'])): ?><p class="small text-body-secondary mb-1"><?= h(t('ins_done_upgraded', $up['tables'], $up['columns'] + $up['changed'])) ?></p><?php endif; ?>
		<a class="btn btn-primary mt-3" id="dbsContinue" href="<?= h(nu_url($isGa ? 'home' : 'login')) ?>"><?= h(t($isGa ? 'dbs_continue' : 'ins_login')) ?></a>
	</div>

<?php elseif ($st['state'] === 'old'): ?>
	<div class="alert alert-warning small" id="dbsOld"><?= h(t('dbs_old_text')) ?></div>
	<p class="small mb-1"><?= h(t('dbs_old_missing', count($st['columns']))) ?></p>
	<ul class="small text-body-secondary mb-3 dbsList"><?php foreach (array_slice($st['columns'], 0, 12) as $c): ?><li><code><?= h($c) ?></code></li><?php endforeach; ?><?php if (count($st['columns']) > 12): ?><li>…</li><?php endif; ?></ul>
	<?= $icHtml(null) ?>
	<p class="small mb-1 fw-semibold"><?= h(t('dbs_old_what')) ?></p>
	<ol class="small mb-0">
		<li><?= h(t('dbs_old_opt_new')) ?></li>
		<li><?= h(t('dbs_old_opt_forte')) ?></li>
	</ol>
	<?php if ($user): ?><form method="post" action="<?= h(nu_url('logout')) ?>" class="mt-3"><?= nu_csrf_field() ?><button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-box-arrow-right me-1"></i><?= h(t('nav_logout')) ?></button></form><?php endif; ?>

<?php elseif (!$auth): ?>
	<p class="small"><?= h(t($st['state'] === 'partial' ? 'dbs_partial_text' : 'dbs_empty_text')) ?></p>
	<p class="small text-body-secondary"><?= h(t('dbs_who')) ?></p>
	<a class="btn btn-primary w-100 mb-3" href="<?= h(nu_url('login')) ?>" id="dbsLogin"><i class="bi bi-box-arrow-in-right me-1"></i><?= h(t('dbs_sign_in_ga')) ?></a>
	<form method="post" action="<?= h(nu_url('dbsetup')) ?>" novalidate>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="token">
		<label class="form-label small fw-semibold" for="dbsToken"><?= h(t('ins_token')) ?></label>
		<p class="small text-body-secondary mb-2"><?= h(t('ins_token_hint')) ?> <code class="text-break"><?= h(NuInstall::tokenFile()) ?></code></p>
		<?php NuInstall::token(); ?>
		<div class="input-group has-validation">
			<input class="form-control font-monospace<?= isset($errors['token']) ? ' is-invalid' : '' ?>" id="dbsToken" name="token" autocomplete="off" spellcheck="false">
			<button class="btn btn-outline-primary" type="submit" id="dbsTokenBtn"><?= h(t('ins_next')) ?></button>
			<?php if (isset($errors['token'])): ?><div class="invalid-feedback"><?= h($errors['token']) ?></div><?php endif; ?>
		</div>
		<p class="small text-body-secondary mt-2 mb-0"><?= h(t('ins_allow_alt')) ?> <code class="text-break"><?= h(NU_ROOT . '/install.allow') ?></code></p>
	</form>

<?php elseif ($st['state'] === 'partial'): ?>
	<p class="small"><?= h(t('dbs_partial_text')) ?></p>
	<p class="small mb-1"><?= h(t('dbs_partial_missing', count($st['missing']))) ?></p>
	<ul class="small text-body-secondary mb-3 dbsList" id="dbsMissing"><?php foreach ($st['missing'] as $m): ?><li><code><?= h($m) ?></code></li><?php endforeach; ?></ul>
	<?= $icHtml($icR && NuIntegrity::blocking($icR, ['tables']) ? 'dbsRepairForm' : null) ?>
	<form method="post" action="<?= h(nu_url('dbsetup')) ?>" data-nu-once id="dbsRepairForm">
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="repair">
		<button class="btn btn-primary w-100" type="submit" id="dbsRepair" data-nu-busy="<?= h(t('dbs_working')) ?>"><i class="bi bi-wrench-adjustable me-1"></i><?= h(t('dbs_repair')) ?></button>
	</form>
	<p class="small text-body-secondary mt-2 mb-0"><?= h(t('dbs_repair_hint')) ?></p>

<?php else: $dumps = $isMy ? NuInstall::dumpCandidates() : []; ?>
	<p class="small"><?= h(t('dbs_empty_text')) ?></p>
	<form method="post" action="<?= h(nu_url('dbsetup')) ?>" enctype="multipart/form-data" data-nu-once id="dbsForm">
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="setup">
		<div class="form-check mb-2"><input class="form-check-input" type="radio" name="choice" id="dbsChoiceNn" value="nunext" checked>
			<label class="form-check-label" for="dbsChoiceNn"><b><?= h(t('ins_choice_nunext')) ?></b><br><span class="small text-body-secondary"><?= h(t('ins_choice_nunext_hint')) ?></span></label></div>
		<div class="form-check mb-2"><input class="form-check-input" type="radio" name="choice" id="dbsChoiceUp" value="upload"<?= $isMy ? '' : ' disabled' ?>>
			<label class="form-check-label" for="dbsChoiceUp"><b><?= h(t('ins_choice_upload')) ?></b><br><span class="small text-body-secondary"><?= h($isMy ? t('ins_choice_upload_hint', (string)nu_ini_get('upload_max_filesize')) : t('ins_choice_upload_mssql')) ?></span></label></div>
		<?php if ($isMy): ?>
		<div class="ms-4 mb-3">
			<input class="form-control form-control-sm" type="file" id="dbsDump" name="dump" accept=".sql,.gz,.zip" aria-label="<?= h(t('ins_choice_upload')) ?>">
			<?php if ($dumps): ?>
				<label class="form-label small mt-2 mb-1" for="dbsDumpPick"><?= h(t('ins_dump_pick')) ?></label>
				<select class="form-select form-select-sm" id="dbsDumpPick" name="dump_pick"><option value="">–</option>
					<?php foreach ($dumps as $dn => $dp): ?><option value="<?= h($dn) ?>"><?= h($dn) ?></option><?php endforeach; ?></select>
			<?php else: ?><div class="form-text"><?= h(t('ins_dump_folder', '_install/')) ?></div><?php endif; ?>
		</div>
		<?php endif; ?>
		<button class="btn btn-success w-100" type="submit" id="dbsSetup" data-nu-busy="<?= h(t('dbs_working')) ?>"><i class="bi bi-database-add me-1"></i><?= h(t('dbs_setup')) ?></button>
	</form>
<?php endif; ?>
</div>
<?php
nu_center_end();
