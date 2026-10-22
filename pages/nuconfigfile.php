<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Admin tools → nuconfig.php (globeadmin, never in demo mode – NuDemo::guardRequest): edit nuconfig.php in a code editor.
 * POST action check (test only) | save (backup, write). ?p=configfile&backup=<name> loads a backup into the editor (saving restores it).
 * Checks and backups: core/nuconfigfile.php.
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('configfile', 'change');
NuSecurity::requireRecentAuth(nu_url('configfile'));   // password again when the last confirmation is older than 15 minutes (0.8.207)

$current  = NuConfigFile::read();
$text     = $current;
$hash     = NuConfigFile::hash($current);
$writable = NuConfigFile::writable();
$result   = null;                     // [type, message] of check / save
$loaded   = '';                       // backup shown in the editor

// "Switch on" buttons of other pages (NuConfigFile::enableButton): set one variable to true, back to that page
if (nu_is_post() && nu_post('action') === 'enable' && isset(NuConfigFile::SWITCHES[nu_post('var')])) {
	[$event, $done, $routes] = NuConfigFile::SWITCHES[nu_post('var')];
	$back = in_array(nu_post('return'), $routes, true) ? nu_post('return') : $routes[0];
	$err = NuConfigFile::setVariable(nu_post('var'), 'true');
	if ($err === '') {
		NuLog::write($event, 'nuconfig.php', '', ['$' . nu_post('var') => true]);
		nu_flash('success', t($done));
	} else {
		nu_flash('danger', t('set_use_db_failed') . ' ' . $err);
	}
	$rid = nu_post('return_id');
	$q = $rid !== '' && preg_match('/^[\w.-]{1,64}$/', $rid) ? ['id' => $rid] : [];
	foreach (['f', 'r'] as $k) {                         // back to a form page (?p=form&f=…&r=…)
		$v = (string)($_POST['return_q'][$k] ?? '');
		if ($v !== '' && preg_match('/^[\w.-]{1,64}$/', $v)) $q[$k] = $v;
	}
	nu_redirect(nu_url($back, $q));
}

// "Generate key" (API clients page): $nuConfigSecretKey while it is empty
if (nu_is_post() && nu_post('action') === 'secretkey') {
	$err = NuConfigFile::setSecretKey(nu_post('key'));
	if ($err === '') {
		$sealed = NuTwoFA::sealPlain(nu_post('key'));     // authenticator secrets stored before the key existed: encrypt them now
		NuLog::write('secret_key_set', 'nuconfig.php', '', ['length' => strlen(nu_post('key')), 'encrypted_2fa' => $sealed]);   // never the key itself
		nu_flash('success', t('key_saved'));
	} else {
		nu_flash('danger', t('set_use_db_failed') . ' ' . $err);
	}
	nu_redirect(nu_url(nu_post('return') === 'securitycheck' ? 'securitycheck' : 'apiclients'));
}

// Save in the browser checks first (assets/js/nuconfigfile.js): JSON {ok, error, line} – nothing is written here
if (nu_is_post() && nu_post('action') === 'precheck') {
	header('Content-Type: application/json; charset=utf-8');
	[$err, , $line] = NuConfigFile::check(str_replace("\r\n", "\n", (string)($_POST['content'] ?? ''))) + [1 => [], 2 => 0];
	exit(json_encode(['ok' => $err === '', 'error' => $err, 'line' => (int)$line], JSON_UNESCAPED_UNICODE));
}

if (nu_is_post()) {
	$action = nu_post('action');
	$text   = str_replace("\r\n", "\n", (string)($_POST['content'] ?? ''));
	$posted = (string)($_POST['hash'] ?? '');
	if ($action === 'save') {
		$err = NuConfigFile::save($text, $posted);
		if ($err === '') {
			nu_flash('success', t('cfg_saved'));
			nu_redirect(nu_url('configfile'));
		}
		$result = ['danger', $err];
	} else {
		[$err] = NuConfigFile::check($text);
		$result = $err === '' ? ['success', t('cfg_check_ok')] : ['danger', $err];
	}
	if (!hash_equals($hash, $posted)) $result = ['warning', t('cfg_err_changed')];   // the file changed meanwhile: keep the text, new base
} elseif (is_string($_GET['backup'] ?? null) && ($b = NuConfigFile::backup((string)$_GET['backup'])) !== '') {
	$text = $b;
	$loaded = (string)$_GET['backup'];
}
$backups = NuConfigFile::backups();

$head = '<link rel="stylesheet" href="' . nu_asset('assets/vendor/codemirror-5.65.21/codemirror.css') . '">';
$foot = '<script src="' . nu_asset('assets/vendor/codemirror-5.65.21/codemirror-bundle.js') . '"></script>'
	. '<script src="' . nu_asset('assets/vendor/codemirror-5.65.21/codemirror-addons.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nu/nucodetools.js') . '"></script>'           // undo/redo, search, comment, invisibles, format, full screen – no hash variables
	. '<script src="' . nu_asset('assets/js/nuconfigfile.js') . '"></script>';
nu_layout_start('nuconfig.php', 'home', ['container' => 'container-xl', 'head' => $head]);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page">nuconfig.php</li>
</ol></nav>

<?php if ($result): ?>
	<div class="alert alert-<?= $result[0] ?> text-break" role="<?= $result[0] === 'success' ? 'status' : 'alert' ?>">
		<i class="bi <?= $result[0] === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle' ?> me-1"></i><?= h($result[1]) ?></div>
<?php endif; ?>
<?php if ($loaded !== ''): ?>
	<div class="alert alert-info"><i class="bi bi-clock-history me-1"></i><?= h(t('cfg_backup_loaded', $loaded)) ?>
		<a class="alert-link ms-1" href="<?= h(nu_url('configfile')) ?>"><?= h(t('cfg_backup_back')) ?></a></div>
<?php endif; ?>
<?php if (!$writable): ?>
	<div class="alert alert-warning"><i class="bi bi-lock me-1"></i><?= h(t('cfg_err_not_writable', NuConfigFile::path())) ?></div>
<?php endif; ?>

<form method="post" action="<?= h(nu_url('configfile')) ?>" id="cfgForm" data-nu-guard>
	<?= nu_csrf_field() ?>
	<input type="hidden" name="hash" value="<?= h($hash) ?>">
	<div class="card shadow-sm nuListCard">
		<div class="card-header nuListHead nuTitleBar">
			<h1 class="h5 mb-0 fw-semibold d-inline-flex align-items-center gap-1">nuconfig.php
				<button type="button" class="btn btn-link btn-sm p-0 lh-1 text-body-secondary nuInfoBtn" data-bs-toggle="collapse" data-bs-target="#cfgHint" aria-expanded="false" aria-controls="cfgHint"
					title="<?= h(t('info')) ?>" aria-label="<?= h(t('info')) ?>"><i class="bi bi-info-circle"></i></button></h1>
			<div class="nuTitleSub text-body-secondary small"><code class="text-break"><?= h(NuConfigFile::path()) ?></code></div>
			<div class="collapse nuTitleSub text-body-secondary small" id="cfgHint"><?= h(t('cfg_hint', NuConfigFile::KEEP)) ?></div>
			<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?>
				<?php if ($writable): ?>
					<button class="btn nuBtnAct nuBtnSave" type="submit" name="action" value="save" id="cfgSave" data-nu-save><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
				<?php endif; ?>
				<button class="btn btn-outline-secondary" type="submit" name="action" value="check" id="cfgCheck" title="<?= h(t('cfg_check_hint')) ?>"><i class="bi bi-shield-check me-1"></i><?= h(t('cfg_check')) ?></button>
				<div class="dropdown">
					<button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"<?= $backups ? '' : ' disabled' ?>>
						<i class="bi bi-clock-history me-1"></i><?= h(t('cfg_backups')) ?></button>
					<ul class="dropdown-menu shadow-sm">
						<li><h6 class="dropdown-header"><?= h(t('cfg_backups_hint')) ?></h6></li>
						<?php foreach ($backups as $name => $time): ?>
							<li><a class="dropdown-item<?= $name === $loaded ? ' active' : '' ?>" href="<?= h(nu_url('configfile', ['backup' => $name])) ?>"><?= h(date('d.m.Y H:i:s', $time)) ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</div>
		<div class="alert alert-warning small rounded-0 border-start-0 border-end-0 mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('cfg_warning')) ?></div>
		<div class="alert alert-danger rounded-0 border-start-0 border-end-0 mb-0 text-break" id="cfgLive" role="alert" hidden></div>
		<div class="cfgEditor">
			<div class="d-flex flex-wrap gap-2 align-items-center px-3 py-2 border-bottom cfgToolbar">
				<label class="form-label small fw-semibold mb-0 me-auto" for="cfgContent">PHP</label>
				<span class="cfgCodeTools d-flex gap-2"></span>
			</div>
			<textarea class="form-control font-monospace border-0 rounded-0 cfgCode" name="content" id="cfgContent" rows="28" spellcheck="false" aria-label="nuconfig.php"
				<?= $writable ? '' : 'readonly' ?>><?= h($text) ?></textarea>
		</div>
	</div>
</form>
<?php nu_layout_end($foot);
