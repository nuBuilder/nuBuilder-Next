<?php
declare(strict_types=1);

require __DIR__ . '/core/nuapp.php';

// nuBuilder's Font Awesome files: served without session (parallel, cacheable)
if (($_GET['p'] ?? '') === 'fa') {
	require __DIR__ . '/pages/nufa.php';
	exit;
}

// first run: no nuconfig.php yet → installer (core/nuinstall.php)
// (also right after it: the installer finishes when nuconfig.php was copied by hand – see pages/nuinstall.php)
if (NuInstall::needed() || (($_GET['p'] ?? '') === 'install' && isset($_COOKIE['nunext_install']))) {
	require __DIR__ . '/pages/nuinstall.php';
	exit;
}

nu_boot();

$routes = [
	'login'       => ['public' => true],
	'lang'        => ['public' => true],
	'logout'      => ['public' => true],
	'twofa'       => ['public' => true],
	'forgot'      => ['public' => true],
	'reset'       => ['public' => true],
	'reportfile'  => ['public' => true],   // PDF of a report run through the REST API (token link, 0.8.340)
	'brand'       => ['public' => true],
	'branding'    => [],
	'setupwizard' => [],
	'adminroles'  => [],
	'dashboards'  => [],
	'dashboardapi' => ['json' => true],
	'palette'     => ['json' => true],
	'security'    => [],
	'securitycheck' => [],
	'reauth'      => [],
	'log'         => [],
	'notifications' => [],
	'notifyapi'   => ['json' => true],
	'homejs'      => ['json' => true],   // JavaScript of the start page hook nu_home (0.8.379, NuHomeHook::serveJs)
	'audit'       => [],
	'audithistory' => [],
	'debug'       => [],
	'sessions'    => [],
	'inspect'     => [],
	'cloner'      => [],
	'appcloner'   => [],
	'vendor'      => [],
	'apiclients'  => [],
	'apiplayground' => [],
	'settings'    => [],
	'configfile'  => [],
	'setupcode'   => [],
	'userfields'  => [],   // custom user fields (0.8.454)
	'account'     => [],   // My account: the custom fields a user fills in himself (0.8.454)
	'todo'        => [],
	'selftest'    => [],
	'help'        => [],
	'tutorial'    => [],
	'listview'    => [],
	'compat'      => [],
	'update'      => [],
	'sample'      => [],
	'procedures'  => [],
	'reports'     => [],
	'report'      => [],
	'reportdesigner' => [],
	'reportpdf'   => [],
	'sqlbuilder'  => [],
	'prompts'     => [],
	'import'      => [],
	'email'       => [],
	'mailoauth'   => [],   // OAuth 2 for the SMTP login: to the provider and back (oauth.php, 0.8.445)
	'emailtemplates' => [],
	'formexport'  => [],
	'home'        => [],
	'password'    => [],
	'users'       => [],
	'user'        => [],
	'access'      => [],
	'accesslevel' => [],
	'form'        => [],
	'formapi'     => ['json' => true],
	'upload'      => ['json' => true],   // file upload of nuBuilder's Uppy objects (0.8.459, pages/nuupload.php)
	'forms'       => [],
	'formats'     => [],
	'items'       => [],
	'permissions' => [],
	'impersonate' => [],
	'sidebar'     => [],                 // side bar on / off, default, recently used, ☆ (0.8.460, pages/nusidebar.php)
	'files'       => [],
	'file'        => [],
	'translations' => [],
	'formatapi'   => ['json' => true],
	'builder'     => [],
	'formai'      => [],                     // form with AI (0.8.403)
	'libpack'     => [],                     // library packs: build and sign (0.8.405, developer mode)
	'builderapi'  => ['json' => true],
	'page'        => [],                 // form type "page": the page file's own output (0.8.387, core/nupage.php)
	'pageasset'   => [],                 // css / js / images of a page folder
];

// nuBuilder's auto login: index.php?u=<login>[&p=<password hash>][&f=<form>&r=<record>][&h=<home>] – here p is the hash, not a page
if (is_string($_GET['u'] ?? null) && !isset($routes[(string)($_GET['p'] ?? '')]) && !nu_is_post()) {
	require __DIR__ . '/pages/nuautologin.php';
	exit;
}

$p = $_GET['p'] ?? 'home';
if (!is_string($p) || !isset($routes[$p])) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}

// page forms (0.8.387): a fetch / XHR of the page gets JSON answers (401 login, 400 csrf …) instead of HTML pages
$pageJson = ($p === 'page' || $p === 'pageasset') && NuPage::wantsJson();
if ($pageJson && nu_is_post()) {
	$sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
	if (!is_string($sent) || !hash_equals(nu_csrf_token(), $sent)) NuPage::fail(400, 'csrf', t('err_csrf'), t('err_csrf_hint'));
}
// Uppy upload (0.8.459): a wrong token gets nuBuilder's upload answer {error, message} – Uppy's template shows message
if ($p === 'upload' && nu_is_post() && !hash_equals(nu_csrf_token(), is_string($sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) ? $sent : '')) {
	http_response_code(400);
	header('Content-Type: application/json; charset=utf-8');
	exit(json_encode(['error' => t('err_csrf'), 'message' => t('err_csrf') . ' ' . t('err_csrf_hint')], JSON_UNESCAPED_UNICODE));
}
if (nu_is_post()) nu_csrf_verify();
// Browsers post the lines of a <textarea> with CRLF; nuBuilder saves what its JavaScript reads (LF). Same line ends as nuBuilder,
// so code, SQL and texts saved here are not changed by a save without a change (0.8.364, dev/tests/compat.js meta:sqlbuilder)
if (nu_is_post()) array_walk_recursive($_POST, static function (&$v): void { if (is_string($v) && str_contains($v, "\r")) $v = str_replace(["\r\n", "\r"], "\n", $v); });
// the bell asks every minute (0.8.357): that alone must not keep an idle session alive (NuAuth::user)
if ($p === 'notifyapi' && !nu_is_post()) define('NU_PASSIVE', true);
if ($p === 'formapi' && ($_GET['presence'] ?? '') === '1') define('NU_PASSIVE', true);   // the "also open" ping (0.8.401) is no activity
// a page's background poll (?passive=1) does not count as activity either (0.8.387)
if ($p === 'page' && ($_GET['passive'] ?? '') === '1' && !defined('NU_PASSIVE')) define('NU_PASSIVE', true);

if (empty($routes[$p]['public'])) {
	$user = NuAuth::user();
	if (!$user) {
		// back here after the login – for a form post or a background request: the page it came from
		$next = nu_is_post() || !empty($routes[$p]['json']) || $pageJson ? NuAuth::nextFromReferer() : NuAuth::validNext('index.php?' . (string)($_SERVER['QUERY_STRING'] ?? ''));
		if ($next !== null) NuAuth::setNext($next);
		if ($pageJson) NuPage::fail(401, 'login', t('login_session_timeout'), '', ['login' => NuAuth::loginUrl($next)]);
		if (!empty($routes[$p]['json'])) {
			http_response_code(401);
			header('Content-Type: application/json');
			exit(json_encode(['ok' => false, 'login' => NuAuth::loginUrl($next), 'errors' => [['message' => t('login_session_timeout'), 'field' => '']]]));
		}
		nu_redirect(NuAuth::loginUrl($next));
	}

	// update in progress: only the globeadmin works, everybody else gets a short notice
	if (!$user['globeadmin'] && NuUpdate::maintenance() && !NuAdmin::can('update')) {   // admin role with "Update": works on, like the globeadmin
		http_response_code(503);
		header('Retry-After: 120');
		if (!empty($routes[$p]['json'])) {
			header('Content-Type: application/json');
			exit(json_encode(['ok' => false, 'errors' => [['message' => t('upd_maintenance'), 'field' => '']]]));
		}
		nu_page_minimal(t('upd_maintenance'), t('upd_maintenance_hint'));
		exit;
	}

	// demo mode (core/nudemo.php): everything visible, read only – form posts and background saves answer "Not available in the demo"
	if (!empty($user['demo'])) NuDemo::guardRequest($p, !empty($routes[$p]['json']));

	// existing nuBuilder database without $nuConfigAllowExistingNuDB (0.8.380, NuInstall::existingBlocked): nothing but a notice
	if (NuInstall::existingBlocked()) {
		http_response_code(503);
		if (!empty($routes[$p]['json'])) {
			header('Content-Type: application/json');
			exit(json_encode(['ok' => false, 'errors' => [['message' => t('existing_db_blocked'), 'field' => '']]]));
		}
		nu_page_minimal(t('existing_db_title'), t('existing_db_blocked'), false, true);
		exit;
	}

	// globeadmin acting as another user: not the user's own login settings; view only = no form posts
	if (NuImpersonate::active()) {
		if (NuImpersonate::blockedRoute($p)) { nu_flash('warning', t('imp_err_route')); nu_redirect(nu_url('home')); }
		if (NuImpersonate::readOnly() && nu_is_post() && $pageJson) NuPage::fail(403, 'read_only', t('imp_err_view_only'));
		if (NuImpersonate::readOnly() && nu_is_post() && empty($routes[$p]['json']) && !NuImpersonate::allowedPost($p)) {
			nu_flash('warning', t('imp_err_view_only'));
			nu_redirect('index.php?' . (string)($_SERVER['QUERY_STRING'] ?? 'p=home'));   // same page, read again
		}
	}

	// forced password change (sus_change_password = '1')
	if ($user['change_password'] && $p !== 'password') nu_redirect(nu_url('password'));

	if ($user['globeadmin'] && empty($user['demo'])) {
		NuSecurity::rememberBaseUrl();                  // address for "Forgot password" links while $nuConfigBaseURL is empty
		if (!empty($_SESSION['nu_weak_admin'])) {        // globeadmin password "nu" or shorter than 8 characters
			unset($_SESSION['nu_weak_admin']);
			nu_flash('warning', t('secchk_weak_admin_flash'));
		}
		// setup wizard (0.8.312): once per login, until nuconfig.php says $nuConfigShowInstaller = false (or "done" is in the database)
		if (!nu_is_post() && empty($routes[$p]['json']) && !in_array($p, NuSetupWizard::FREE_ROUTES, true) && !NuImpersonate::active() && empty($_SESSION['nu_setup_wiz_seen']) && NuSetupWizard::pending()) {
			nu_redirect(nu_url('setupwizard'));
		}
	}
}

require __DIR__ . "/pages/nu$p.php";
