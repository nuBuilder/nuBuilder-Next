<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * First-run installer – loaded by index.php for every request while nuconfig.php is missing (see core/nuinstall.php).
 */

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
session_name('nunext_install');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$p = (string)($_GET['p'] ?? '');
if ($p === 'fa') { http_response_code(404); exit; }
if ($p === 'lang') {                                   // language menu
	nu_set_lang(is_string($_GET['l'] ?? null) ? $_GET['l'] : 'de');
	parse_str((string)($_GET['r'] ?? ''), $back);
	nu_redirect(nu_url('install', ['step' => is_string($back['step'] ?? null) ? $back['step'] : null]));
}
if (nu_is_post()) nu_csrf_verify();

// nuconfig.php exists already: only an installer session that waits for a hand-copied nuconfig.php may finish here
$late = !NuInstall::needed();
if ($late && !(is_array($_SESSION['nu_install'] ?? null) && !empty($_SESSION['nu_install']['auth']) && !empty($_SESSION['nu_install']['pending_config']))) {
	nu_redirect(nu_url('login'));
}

const NU_INS_STEPS = ['start', 'db', 'content', 'admin', 'options', 'summary'];

// ------------------------------------------------------------------ state
$S = &$_SESSION['nu_install'];
if (!is_array($S)) {
	$nubPath = NuInstall::nuBuilderPath();
	$nc = NuInstall::nuBuilderConfig($nubPath);
	$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	$dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
	$S = [
		'auth' => false, 'reached' => 0, 'from_nub' => (bool)$nc,
		'db' => [
			'type' => ($nc['nuConfigDBType'] ?? 'mysql') === 'sqlsrv' ? 'sqlsrv' : 'mysql',
			'host' => (string)($nc['nuConfigDBHost'] ?? '127.0.0.1'), 'port' => (string)($nc['nuConfigDBPort'] ?? '3306'),
			'name' => (string)($nc['nuConfigDBName'] ?? ''), 'user' => (string)($nc['nuConfigDBUser'] ?? ''), 'password' => (string)($nc['nuConfigDBPassword'] ?? ''),
			'trust' => true, 'create' => false,
		],
		'content' => ['choice' => $nubPath && is_file($nubPath . '/nubuilder4.sql') ? 'nubuilder' : 'nunext', 'confirm_other' => false],
		'admin' => ['user' => (string)($nc['nuConfigDBGlobeadminUsername'] ?? 'globeadmin'), 'password' => (string)($nc['nuConfigDBGlobeadminPassword'] ?? ''), 'email' => '', 'twofa' => false],
		'opt' => [
			'base_url' => ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir,
			'timezone' => in_array(date_default_timezone_get(), ['UTC', ''], true) ? 'Europe/Zurich' : date_default_timezone_get(),
			'timeout' => 120, 'nub_path' => (string)($nubPath ?? ''), 'shared' => false,
		],
		'detect' => null, 'pending_config' => null,
	];
}
$step = (string)($_GET['step'] ?? NU_INS_STEPS[min($S['reached'], count(NU_INS_STEPS) - 1)]);
if (!in_array($step, NU_INS_STEPS, true)) $step = 'start';
if (array_search($step, NU_INS_STEPS, true) > $S['reached'] || (!$S['auth'] && $step !== 'start')) $step = $S['auth'] ? NU_INS_STEPS[$S['reached']] : 'start';

$errors = [];
$notice = '';
$go = static function (string $to) use (&$S) {
	$S['reached'] = max($S['reached'], (int)array_search($to, NU_INS_STEPS, true));
	nu_redirect(nu_url('install', ['step' => $to]));
};
$needsToken = !NuInstall::isLocal();
$token = $needsToken ? NuInstall::token() : null;

// ------------------------------------------------------------------ actions
if ($late) {
	$step = 'summary';
	if (NuInstall::validConfig(NuInstall::configPath())) $done = nu_install_finish($S);
	else $errors['_'] = t('ins_err_still_missing', NuInstall::configPath());
} elseif (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'back') {
		$i = max(0, (int)array_search($step, NU_INS_STEPS, true) - 1);
		nu_redirect(nu_url('install', ['step' => NU_INS_STEPS[$i]]));
	}
	switch ($step) {
		case 'start':
			if (!NuInstall::requirementsOk()) { $errors['_'] = t('ins_err_requirements'); break; }
			if ($needsToken && !$S['auth']) {
				if (NuInstall::locked()) { $errors['token'] = t('ins_err_locked'); break; }
				// install.allow (empty file created by FTP / SFTP) is always accepted; otherwise the token
				$entered = trim(nu_post('token'));
				$ok = NuInstall::allowFileExists() || ($token !== null && $entered !== '' && NuInstall::checkToken($entered));
				if (!$ok) { $errors['token'] = t($token === null ? 'ins_err_allow' : ($entered === '' ? 'ins_err_token_empty' : 'ins_err_token')); break; }
			}
			$S['auth'] = true;
			session_regenerate_id(true);
			$go('db');

		case 'db':
			$d = ['type' => nu_post('type') === 'sqlsrv' ? 'sqlsrv' : 'mysql', 'host' => trim(nu_post('host')), 'port' => trim(nu_post('port')),
				'name' => trim(nu_post('name')), 'user' => trim(nu_post('user')), 'password' => (string)($_POST['password'] ?? ''),
				'trust' => nu_check('trust') === '1', 'create' => nu_check('create') === '1'];
			if ($d['password'] === '' && nu_check('keep_password') === '1') $d['password'] = $S['db']['password'];
			$S['db'] = $d;
			try {
				try {
					$db = NuInstall::connect($d);
				} catch (PDOException $e) {
					if (!$d['create']) throw $e;
					NuInstall::createDatabase($d);
					$db = NuInstall::connect($d);
					$notice = t('ins_db_created', $d['name']);
				}
				$S['detect'] = NuInstall::detect($db);
				if ($action === 'test') { $notice = trim($notice . ' ' . t('ins_db_ok')); break; }
				$go('content');
			} catch (Throwable $e) {
				$errors['_'] = t('ins_err_connect') . ' ' . $e->getMessage();
			}
			break;

		case 'content':
			$S['content'] = ['choice' => nu_post('choice') === 'nubuilder' ? 'nubuilder' : 'nunext', 'confirm_other' => nu_check('confirm_other') === '1'];
			if (isset($_POST['nub_path'])) {                       // nuBuilder folder for nubuilder4.sql (also on the options step)
				$np = rtrim(trim(nu_post('nub_path')), '/\\');
				if ($np !== '' && !NuInstall::nuBuilderPath($np)) { $errors['nub_path'] = t('ins_err_nub_path'); break; }
				$S['opt']['nub_path'] = $np;
			}
			if ($action === 'check') { if ($S['opt']['nub_path'] !== '' && is_file($S['opt']['nub_path'] . '/nubuilder4.sql')) $S['content']['choice'] = 'nubuilder'; break; }
			if ($S['content']['choice'] === 'nubuilder' && !is_file($S['opt']['nub_path'] . '/nubuilder4.sql')) $S['content']['choice'] = 'nunext';
			if (($S['detect']['kind'] ?? '') === 'other' && !$S['content']['confirm_other']) { $errors['confirm_other'] = t('ins_err_confirm_other'); break; }
			$go('admin');

		case 'admin':
			$a = ['user' => trim(nu_post('user')), 'password' => (string)($_POST['password'] ?? ''), 'email' => trim(nu_post('email')), 'twofa' => nu_check('twofa') === '1'];
			$keep = $a['password'] === '' && nu_check('keep_password') === '1';
			if ($keep) $a['password'] = $S['admin']['password'];
			if (!preg_match('/^[A-Za-z0-9._@\-]{3,50}$/', $a['user'])) $errors['user'] = t('ins_err_admin_user');
			if (strlen($a['password']) < 10) $errors['password'] = t('ins_err_admin_pw');
			elseif (!$keep && $a['password'] !== (string)($_POST['password2'] ?? '')) $errors['password2'] = t('ins_err_admin_pw2');
			if ($a['email'] !== '' && !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = t('ins_err_email');
			if ($a['twofa'] && $a['email'] === '') $errors['email'] = t('ins_err_twofa_email');
			$S['admin'] = $a;
			if (!$errors) $go('options');
			break;

		case 'options':
			$o = ['base_url' => rtrim(trim(nu_post('base_url')), '/'), 'timezone' => nu_post('timezone'), 'timeout' => (int)nu_post('timeout'),
				'nub_path' => trim(nu_post('nub_path')), 'shared' => nu_check('shared') === '1'];
			if ($o['base_url'] !== '' && !preg_match('#^https?://[^\s"\'<>]+$#', $o['base_url'])) $errors['base_url'] = t('ins_err_url');
			if (!in_array($o['timezone'], timezone_identifiers_list(), true)) $errors['timezone'] = t('ins_err_timezone');
			if ($o['timeout'] < 5 || $o['timeout'] > 1440) $errors['timeout'] = t('ins_err_timeout');
			if ($o['nub_path'] !== '' && !NuInstall::nuBuilderPath($o['nub_path'])) $errors['nub_path'] = t('ins_err_nub_path');
			if ($o['shared'] && $o['nub_path'] === '') $errors['shared'] = t('ins_err_shared');
			$S['opt'] = $o;
			if (!$errors) $go('summary');
			break;

		case 'summary':
			if ($action === 'download' && $S['pending_config']) {
				header('Content-Type: application/octet-stream');
				header('Content-Disposition: attachment; filename="nuconfig.php"');
				echo $S['pending_config'];
				exit;
			}
			if ($action === 'recheck') {
				if (NuInstall::validConfig(NuInstall::configPath())) { $done = nu_install_finish($S); break; }
				$errors['_'] = t('ins_err_still_missing', NuInstall::configPath());
				break;
			}
			if ($action !== 'install') break;
			try {
				nu_time_limit(600);
				$d = $S['db'];
				try {
					$db = NuInstall::connect($d);
				} catch (PDOException $e) {
					if (!$d['create']) throw $e;
					NuInstall::createDatabase($d);
					$db = NuInstall::connect($d);
				}
				$det = NuInstall::detect($db);
				$imported = 0;
				if ($det['kind'] === 'empty' || ($det['kind'] === 'other' && $S['content']['confirm_other'])) {
					foreach (NuInstall::schemaFiles($S['content']['choice'], $db->type, $S['opt']['nub_path'] ?: null) as $f) $imported += NuInstall::importFile($db, $f);
					try { NuSettings::create($db); } catch (Throwable $e) { error_log('nuBuilder Next install, settings table: ' . $e->getMessage()); }   // nuBuilder's settings (zzzzsys_config)
				}
				$S['imported'] = $imported;
				$cfg = NuInstall::buildConfig($S);
				if (!NuInstall::writeConfig($cfg)) {
					$S['pending_config'] = $cfg;              // write it yourself: download / copy, then "Check again"
					break;
				}
				$done = nu_install_finish($S);
			} catch (Throwable $e) {
				error_log('nuBuilder Next install: ' . $e->getMessage());
				$errors['_'] = t('ins_err_install') . ' ' . $e->getMessage();
			}
			break;
	}
}

/** After nuconfig.php exists: nuBuilder Next's tables and database steps, token removed. Returns the done data. */
function nu_install_finish(?array &$S): array
{
	$out = ['imported' => (int)($S['imported'] ?? 0), 'steps' => [], 'error' => ''];
	try {
		NuSchema::create();
		$out['steps'] = NuMigrate::run();
	} catch (Throwable $e) {
		$out['error'] = $e->getMessage();
	}
	NuInstall::cleanup();
	$out['admin'] = $S['admin']['user'];
	$S = null;
	unset($_SESSION['nu_install']);
	return $out;
}

// ------------------------------------------------------------------ view
$title = t('ins_title');
$reqs = NuInstall::requirements();
$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$fb = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . h($errors[$k]) . '</div>' : '';
NuBranding::$off = true;                              // no branding in the installer (database not ready)
nu_head($title);
?>
<body class="nu-center nu-login">
<main class="nu-install">
	<div class="d-flex justify-content-between align-items-center gap-2 mb-3">
		<div class="d-flex align-items-center gap-2"><?= nu_brand_logo() ?><span class="small text-body-secondary"><?= h(NU_VERSION) ?></span></div>
		<div class="d-flex gap-2"><?php nu_theme_menu(); ?><?php nu_lang_menu(); ?></div>
	</div>
	<?php if (!isset($done)): ?>
		<ol class="nuInsSteps mb-3" aria-label="<?= h(t('ins_progress')) ?>">
			<?php foreach (NU_INS_STEPS as $i => $s):
				$cur = $s === $step; $can = $S && $S['auth'] && $i <= $S['reached']; ?>
				<li class="<?= $cur ? 'active' : ($i < (int)array_search($step, NU_INS_STEPS, true) ? 'done' : '') ?>"<?= $cur ? ' aria-current="step"' : '' ?>>
					<?php if ($can && !$cur): ?><a href="<?= h(nu_url('install', ['step' => $s])) ?>"><?= h(t('ins_step_' . $s)) ?></a><?php else: ?><span><?= h(t('ins_step_' . $s)) ?></span><?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
	<div class="card shadow-sm border-0">
		<div class="card-body p-4">
		<?php if (isset($errors['_'])): ?><div class="alert alert-danger small text-break"><?= h($errors['_']) ?></div><?php endif; ?>
		<?php if ($notice !== ''): ?><div class="alert alert-success small" id="insNotice"><?= h($notice) ?></div><?php endif; ?>

		<?php if (isset($done)): ?>
			<!-- ================================================================ done -->
			<div class="text-center py-2" id="insDone">
				<i class="bi bi-check-circle text-success display-5"></i>
				<h1 class="h4 mt-2"><?= h(t('ins_done_title')) ?></h1>
				<p class="text-body-secondary"><?= h(t('ins_done_text', $done['admin'])) ?></p>
				<?php if ($done['imported']): ?><p class="small mb-1"><?= h(t('ins_done_imported', $done['imported'])) ?></p><?php endif; ?>
				<?php if ($done['error'] !== ''): ?><div class="alert alert-warning small text-start"><?= h(t('ins_done_db_warning')) ?> <?= h($done['error']) ?></div><?php endif; ?>
				<a class="btn btn-primary btn-lg my-3" href="<?= h(nu_url('login')) ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?= h(t('ins_login')) ?></a>
			</div>
			<h2 class="h6"><?= h(t('ins_next_title')) ?></h2>
			<ul class="small mb-0">
				<li><?= h(t('ins_next_libs')) ?></li>
				<li><?= h(t('ins_next_mail')) ?></li>
				<li><?= h(t('ins_next_docs')) ?></li>
				<li><?= h(t('ins_next_backup')) ?></li>
			</ul>

		<?php elseif ($step === 'start'): ?>
			<!-- ================================================================ start -->
			<h1 class="h4"><?= h(t('ins_welcome')) ?></h1>
			<p class="text-body-secondary small"><?= h(t('ins_welcome_text')) ?></p>
			<h2 class="h6 mt-3"><?= h(t('ins_server')) ?></h2>
			<ul class="list-group small mb-3" id="insReq">
				<?php foreach ($reqs as [$label, $ok, $detail, $must]): ?>
					<li class="list-group-item d-flex gap-2"><i class="bi <?= $ok ? 'bi-check-circle text-success' : ($must ? 'bi-x-circle text-danger' : 'bi-exclamation-circle text-warning') ?>"></i>
						<span class="me-auto"><?= h($label) ?></span><span class="text-body-secondary text-end"><?= h($detail) ?></span></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?= h(nu_url('install', ['step' => 'start'])) ?>" novalidate>
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="next">
				<?php if ($needsToken && !$S['auth']): ?>
					<?php if ($token !== null): ?>
						<label class="form-label fw-semibold" for="token"><?= h(t('ins_token')) ?></label>
						<p class="small text-body-secondary mb-2"><?= h(t('ins_token_hint')) ?> <code class="text-break"><?= h(NuInstall::tokenFile()) ?></code></p>
						<input class="form-control font-monospace<?= $err('token') ?>" id="token" name="token" autocomplete="off" spellcheck="false" autofocus><?= $fb('token') ?>
						<p class="small text-body-secondary mt-2 mb-0" id="insAllowAlt"><?= h(t('ins_allow_alt')) ?> <code class="text-break"><?= h(NU_ROOT . '/install.allow') ?></code></p>
					<?php else: ?>
						<div class="alert alert-info small<?= isset($errors['token']) ? ' border-danger' : '' ?>"><?= h(t('ins_allow_hint')) ?> <code class="text-break"><?= h(NU_ROOT . '/install.allow') ?></code></div>
						<?php if (isset($errors['token'])): ?><div class="text-danger small mb-2"><?= h($errors['token']) ?></div><?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
				<div class="d-flex justify-content-end mt-3"><button class="btn btn-primary" type="submit" id="insNext"<?= NuInstall::requirementsOk() ? '' : ' disabled' ?>><?= h(t('ins_start')) ?> <i class="bi bi-arrow-right"></i></button></div>
			</form>

		<?php elseif ($step === 'db'): $d = $S['db']; ?>
			<!-- ================================================================ database -->
			<h1 class="h4"><?= h(t('ins_step_db')) ?></h1>
			<p class="text-body-secondary small"><?= h(t($S['from_nub'] ? 'ins_db_from_nub' : 'ins_db_text')) ?></p>
			<form method="post" action="<?= h(nu_url('install', ['step' => 'db'])) ?>" id="insDbForm" novalidate>
				<?= nu_csrf_field() ?>
				<div class="row g-3">
					<div class="col-sm-5"><label class="form-label" for="type"><?= h(t('ins_db_type')) ?></label>
						<select class="form-select" id="type" name="type">
							<option value="mysql"<?= $d['type'] === 'mysql' ? ' selected' : '' ?>>MySQL / MariaDB</option>
							<option value="sqlsrv"<?= $d['type'] === 'sqlsrv' ? ' selected' : '' ?><?= extension_loaded('pdo_sqlsrv') ? '' : ' disabled' ?>>Microsoft SQL Server</option>
						</select></div>
					<div class="col-sm-5"><label class="form-label" for="host"><?= h(t('ins_db_host')) ?></label><input class="form-control" id="host" name="host" value="<?= h($d['host']) ?>" required></div>
					<div class="col-sm-2"><label class="form-label" for="port"><?= h(t('ins_db_port')) ?></label><input class="form-control" id="port" name="port" inputmode="numeric" value="<?= h($d['port']) ?>"></div>
					<div class="col-sm-4"><label class="form-label" for="name"><?= h(t('ins_db_name')) ?></label><input class="form-control" id="name" name="name" value="<?= h($d['name']) ?>" required></div>
					<div class="col-sm-4"><label class="form-label" for="user"><?= h(t('ins_db_user')) ?></label><input class="form-control" id="user" name="user" value="<?= h($d['user']) ?>" autocomplete="off"></div>
					<div class="col-sm-4"><label class="form-label" for="password"><?= h(t('ins_db_password')) ?></label>
						<input class="form-control" type="password" id="password" name="password" autocomplete="new-password" placeholder="<?= $d['password'] !== '' ? '••••••••' : '' ?>">
						<?php if ($d['password'] !== ''): ?><input type="hidden" name="keep_password" value="1"><div class="form-text"><?= h(t('ins_keep_password')) ?></div><?php endif; ?></div>
					<div class="col-12 d-flex flex-wrap gap-4">
						<div class="form-check"><input class="form-check-input" type="checkbox" id="create" name="create" value="1"<?= $d['create'] ? ' checked' : '' ?>><label class="form-check-label" for="create"><?= h(t('ins_db_create')) ?></label></div>
						<div class="form-check insMssqlOnly"><input class="form-check-input" type="checkbox" id="trust" name="trust" value="1"<?= $d['trust'] ? ' checked' : '' ?>><label class="form-check-label" for="trust"><?= h(t('ins_db_trust')) ?></label></div>
					</div>
				</div>
				<?php if ($S['detect']): $k = $S['detect']['kind']; ?>
					<div class="small mt-3 text-body-secondary" id="insDetect"><i class="bi bi-info-circle me-1"></i><?= h(t('ins_detect_' . $k, $S['detect']['tables'], $S['detect']['forms'], $S['detect']['users'])) ?></div>
				<?php endif; ?>
				<div class="d-flex justify-content-between mt-4">
					<button class="btn btn-outline-secondary" type="submit" name="action" value="back" formnovalidate><i class="bi bi-arrow-left"></i> <?= h(t('ins_back')) ?></button>
					<div class="d-flex gap-2"><button class="btn btn-outline-primary" type="submit" name="action" value="test" id="insTest"><?= h(t('ins_db_test')) ?></button>
						<button class="btn btn-primary" type="submit" name="action" value="next" id="insNext"><?= h(t('ins_next')) ?> <i class="bi bi-arrow-right"></i></button></div>
				</div>
			</form>

		<?php elseif ($step === 'content'): $det = $S['detect'] ?? ['kind' => 'empty', 'tables' => 0, 'forms' => 0, 'users' => 0]; $nubSql = $S['opt']['nub_path'] !== '' && is_file($S['opt']['nub_path'] . '/nubuilder4.sql') && $S['db']['type'] === 'mysql'; ?>
			<!-- ================================================================ content -->
			<h1 class="h4"><?= h(t('ins_step_content')) ?></h1>
			<form method="post" action="<?= h(nu_url('install', ['step' => 'content'])) ?>" novalidate>
				<?= nu_csrf_field() ?>
				<?php if ($det['kind'] === 'nubuilder'): ?>
					<div class="alert alert-success" id="insKind"><i class="bi bi-database-check me-1"></i><?= h(t('ins_content_nubuilder', $det['forms'], $det['users'])) ?></div>
					<input type="hidden" name="choice" value="nunext">
				<?php else: ?>
					<div class="alert <?= $det['kind'] === 'other' ? 'alert-warning' : 'alert-info' ?>" id="insKind"><?= h(t('ins_content_' . $det['kind'], $det['tables'])) ?></div>
					<div class="form-check mb-2"><input class="form-check-input" type="radio" name="choice" id="choice_nub" value="nubuilder"<?= $S['content']['choice'] === 'nubuilder' && $nubSql ? ' checked' : '' ?><?= $nubSql ? '' : ' disabled' ?>>
						<label class="form-check-label" for="choice_nub"><b><?= h(t('ins_choice_nubuilder')) ?></b><br><span class="small text-body-secondary"><?= h($nubSql ? t('ins_choice_nubuilder_hint') : t('ins_choice_nubuilder_missing')) ?></span></label></div>
					<div class="form-check mb-2"><input class="form-check-input" type="radio" name="choice" id="choice_nn" value="nunext"<?= $S['content']['choice'] === 'nunext' || !$nubSql ? ' checked' : '' ?>>
						<label class="form-check-label" for="choice_nn"><b><?= h(t('ins_choice_nunext')) ?></b><br><span class="small text-body-secondary"><?= h(t('ins_choice_nunext_hint')) ?></span></label></div>
					<div class="input-group input-group-sm has-validation mt-1 mb-3 ms-4 insNubPath">
						<span class="input-group-text"><?= h(t('ins_opt_nub')) ?></span>
						<input class="form-control<?= $err('nub_path') ?>" id="nub_path" name="nub_path" value="<?= h($S['opt']['nub_path']) ?>" placeholder="<?= h(dirname(NU_ROOT)) ?>" aria-label="<?= h(t('ins_opt_nub')) ?>">
						<button class="btn btn-outline-secondary" type="submit" name="action" value="check" formnovalidate id="insCheckNub"><?= h(t('ins_check')) ?></button><?= $fb('nub_path') ?>
					</div>
					<?php if ($det['kind'] === 'other'): ?>
						<div class="form-check mt-3"><input class="form-check-input<?= $err('confirm_other') ?>" type="checkbox" id="confirm_other" name="confirm_other" value="1"<?= $S['content']['confirm_other'] ? ' checked' : '' ?>>
							<label class="form-check-label" for="confirm_other"><?= h(t('ins_confirm_other')) ?></label><?= $fb('confirm_other') ?></div>
					<?php endif; ?>
				<?php endif; ?>
				<div class="d-flex justify-content-between mt-4">
					<button class="btn btn-outline-secondary" type="submit" name="action" value="back" formnovalidate><i class="bi bi-arrow-left"></i> <?= h(t('ins_back')) ?></button>
					<button class="btn btn-primary" type="submit" name="action" value="next" id="insNext"><?= h(t('ins_next')) ?> <i class="bi bi-arrow-right"></i></button>
				</div>
			</form>

		<?php elseif ($step === 'admin'): $a = $S['admin']; ?>
			<!-- ================================================================ administrator -->
			<h1 class="h4"><?= h(t('ins_step_admin')) ?></h1>
			<p class="text-body-secondary small"><?= h(t('ins_admin_text')) ?></p>
			<form method="post" action="<?= h(nu_url('install', ['step' => 'admin'])) ?>" novalidate>
				<?= nu_csrf_field() ?>
				<div class="row g-3">
					<div class="col-sm-6"><label class="form-label" for="user"><?= h(t('ins_admin_user')) ?></label><input class="form-control<?= $err('user') ?>" id="user" name="user" value="<?= h($a['user']) ?>" autocomplete="username" required><?= $fb('user') ?></div>
					<div class="col-sm-6"><label class="form-label" for="email"><?= h(t('ins_admin_email')) ?></label><input class="form-control<?= $err('email') ?>" type="email" id="email" name="email" value="<?= h($a['email']) ?>"><?= $fb('email') ?></div>
					<div class="col-sm-6"><label class="form-label" for="password"><?= h(t('ins_admin_password')) ?></label>
						<input class="form-control<?= $err('password') ?>" type="password" id="password" name="password" autocomplete="new-password" minlength="10" placeholder="<?= $a['password'] !== '' ? '••••••••' : '' ?>"><?= $fb('password') ?>
						<div class="progress mt-2 insStrength" role="presentation"><div class="progress-bar" id="insStrength" data-labels="<?= h(json_encode([t('ins_pw_weak'), t('ins_pw_fair'), t('ins_pw_good'), t('ins_pw_strong')])) ?>" data-short="<?= h(t('ins_err_admin_pw')) ?>"></div></div>
						<div class="form-text" id="insStrengthText"><?= h(t('ins_pw_rules')) ?></div>
						<?php if ($a['password'] !== ''): ?><input type="hidden" name="keep_password" value="1"><div class="form-text"><?= h(t('ins_keep_password')) ?></div><?php endif; ?></div>
					<div class="col-sm-6"><label class="form-label" for="password2"><?= h(t('ins_admin_password2')) ?></label>
						<input class="form-control<?= $err('password2') ?>" type="password" id="password2" name="password2" autocomplete="new-password" data-mismatch="<?= h(t('ins_err_admin_pw2')) ?>"><?= $fb('password2') ?></div>
					<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="twofa" name="twofa" value="1"<?= $a['twofa'] ? ' checked' : '' ?>><label class="form-check-label" for="twofa"><?= h(t('ins_admin_twofa')) ?></label></div></div>
					<div class="col-12 small text-body-secondary"><i class="bi bi-info-circle me-1"></i><?= h(t('ins_admin_plain')) ?></div>
				</div>
				<div class="d-flex justify-content-between mt-4">
					<button class="btn btn-outline-secondary" type="submit" name="action" value="back" formnovalidate><i class="bi bi-arrow-left"></i> <?= h(t('ins_back')) ?></button>
					<button class="btn btn-primary" type="submit" name="action" value="next" id="insNext"><?= h(t('ins_next')) ?> <i class="bi bi-arrow-right"></i></button>
				</div>
			</form>

		<?php elseif ($step === 'options'): $o = $S['opt']; ?>
			<!-- ================================================================ options -->
			<h1 class="h4"><?= h(t('ins_step_options')) ?></h1>
			<p class="text-body-secondary small"><?= h(t('ins_options_text')) ?></p>
			<form method="post" action="<?= h(nu_url('install', ['step' => 'options'])) ?>" novalidate>
				<?= nu_csrf_field() ?>
				<div class="row g-3">
					<div class="col-12"><label class="form-label" for="base_url"><?= h(t('ins_opt_url')) ?></label><input class="form-control<?= $err('base_url') ?>" id="base_url" name="base_url" value="<?= h($o['base_url']) ?>"><?= $fb('base_url') ?>
						<div class="form-text"><?= h(t('ins_opt_url_hint')) ?></div></div>
					<div class="col-sm-8"><label class="form-label" for="timezone"><?= h(t('ins_opt_timezone')) ?></label>
						<select class="form-select<?= $err('timezone') ?>" id="timezone" name="timezone"><?php foreach (timezone_identifiers_list() as $tz): ?><option<?= $tz === $o['timezone'] ? ' selected' : '' ?>><?= h($tz) ?></option><?php endforeach; ?></select><?= $fb('timezone') ?></div>
					<div class="col-sm-4"><label class="form-label" for="timeout"><?= h(t('ins_opt_timeout')) ?></label><input class="form-control<?= $err('timeout') ?>" type="number" min="5" max="1440" id="timeout" name="timeout" value="<?= (int)$o['timeout'] ?>"><?= $fb('timeout') ?></div>
					<div class="col-12"><label class="form-label" for="nub_path"><?= h(t('ins_opt_nub')) ?></label><input class="form-control<?= $err('nub_path') ?>" id="nub_path" name="nub_path" value="<?= h($o['nub_path']) ?>" placeholder="<?= h(dirname(NU_ROOT)) ?>"><?= $fb('nub_path') ?>
						<div class="form-text"><?= h(t('ins_opt_nub_hint')) ?></div></div>
					<div class="col-12"><div class="form-check"><input class="form-check-input<?= $err('shared') ?>" type="checkbox" id="shared" name="shared" value="1"<?= $o['shared'] ? ' checked' : '' ?>><label class="form-check-label" for="shared"><?= h(t('ins_opt_shared')) ?></label><?= $fb('shared') ?></div></div>
				</div>
				<div class="d-flex justify-content-between mt-4">
					<button class="btn btn-outline-secondary" type="submit" name="action" value="back" formnovalidate><i class="bi bi-arrow-left"></i> <?= h(t('ins_back')) ?></button>
					<button class="btn btn-primary" type="submit" name="action" value="next" id="insNext"><?= h(t('ins_next')) ?> <i class="bi bi-arrow-right"></i></button>
				</div>
			</form>

		<?php elseif ($step === 'summary'): $d = $S['db']; $det = $S['detect'] ?? ['kind' => 'empty']; ?>
			<!-- ================================================================ summary / install -->
			<h1 class="h4"><?= h(t('ins_step_summary')) ?></h1>
			<?php if ($S['pending_config']): ?>
				<div class="alert alert-warning" id="insManual"><?= h(t('ins_manual_config', NuInstall::configPath())) ?></div>
				<textarea class="form-control font-monospace small mb-2" rows="10" readonly id="insConfigText"><?= h($S['pending_config']) ?></textarea>
				<div class="d-flex flex-wrap gap-2">
					<form method="post" action="<?= h(nu_url('install', ['step' => 'summary'])) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="download">
						<button class="btn btn-outline-primary" type="submit"><i class="bi bi-download me-1"></i><?= h(t('ins_download')) ?></button></form>
					<form method="post" action="<?= h(nu_url('install', ['step' => 'summary'])) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="recheck">
						<button class="btn btn-primary" type="submit" id="insRecheck"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('ins_recheck')) ?></button></form>
				</div>
			<?php else: ?>
				<dl class="row small mb-3" id="insSummary">
					<dt class="col-sm-4"><?= h(t('ins_step_db')) ?></dt><dd class="col-sm-8"><?= h(($d['type'] === 'sqlsrv' ? 'MSSQL' : 'MySQL') . ' · ' . $d['user'] . '@' . $d['host'] . ($d['port'] !== '' ? ':' . $d['port'] : '') . ' / ' . $d['name']) ?><?= $d['create'] ? ' · ' . h(t('ins_db_create_short')) : '' ?></dd>
					<dt class="col-sm-4"><?= h(t('ins_step_content')) ?></dt><dd class="col-sm-8"><?= h($det['kind'] === 'nubuilder' ? t('ins_sum_connect') : t($S['content']['choice'] === 'nubuilder' ? 'ins_choice_nubuilder' : 'ins_choice_nunext')) ?></dd>
					<dt class="col-sm-4"><?= h(t('ins_step_admin')) ?></dt><dd class="col-sm-8"><?= h($S['admin']['user']) ?><?= $S['admin']['email'] !== '' ? ' · ' . h($S['admin']['email']) : '' ?><?= $S['admin']['twofa'] ? ' · 2FA' : '' ?></dd>
					<dt class="col-sm-4"><?= h(t('ins_opt_url')) ?></dt><dd class="col-sm-8 text-break"><?= h($S['opt']['base_url']) ?></dd>
					<dt class="col-sm-4"><?= h(t('ins_opt_timezone')) ?></dt><dd class="col-sm-8"><?= h($S['opt']['timezone']) ?> · <?= (int)$S['opt']['timeout'] ?> min</dd>
					<dt class="col-sm-4">nuBuilder</dt><dd class="col-sm-8 text-break"><?= h($S['opt']['nub_path'] ?: '–') ?><?= $S['opt']['shared'] ? ' · ' . h(t('ins_opt_shared_short')) : '' ?></dd>
					<dt class="col-sm-4"><?= h(t('ins_config_file')) ?></dt><dd class="col-sm-8 text-break"><code><?= h(NuInstall::configPath()) ?></code></dd>
				</dl>
				<form method="post" action="<?= h(nu_url('install', ['step' => 'summary'])) ?>" class="d-flex justify-content-between" id="insInstallForm">
					<?= nu_csrf_field() ?>
					<button class="btn btn-outline-secondary" type="submit" name="action" value="back" formnovalidate><i class="bi bi-arrow-left"></i> <?= h(t('ins_back')) ?></button>
					<button class="btn btn-success" type="submit" name="action" value="install" id="insInstall" data-busy="<?= h(t('ins_installing')) ?>"><i class="bi bi-rocket-takeoff me-1"></i><?= h(t('ins_install')) ?></button>
				</form>
			<?php endif; ?>
		<?php endif; ?>
		</div>
	</div>
	<p class="text-center small text-body-secondary mt-3 mb-0"><?= h(t('ins_footer')) ?></p>
</main>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuinstall.js') ?>"></script>
</body>
</html>
