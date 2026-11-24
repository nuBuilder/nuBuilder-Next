<?php
declare(strict_types=1);

require __DIR__ . '/core/nuapp.php';

// nuBuilder's Font Awesome files: served without session (parallel, cacheable)
if (($_GET['p'] ?? '') === 'fa') {
	require __DIR__ . '/pages/nufa.php';
	exit;
}

// installable app: manifest, icons, service worker, offline page – WITHOUT nu_boot's session: the browser fetches
// them on its own (worker script, update checks, cache filling) – a fresh session cookie in such an answer replaced the user's
// session and signed them out. The manifest reads an existing session read-only for the favourites, and never sends a cookie.
if (($_GET['p'] ?? '') === 'pwa' && !NuInstall::needed()) {
	nu_config();
	nu_security_headers();
	header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
	$sid = (string)($_COOKIE['nunext'] ?? '');
	if (($_GET['a'] ?? '') === 'manifest' && preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sid)) {
		session_name('nunext');
		session_id($sid);
		session_start(['read_and_close' => true, 'use_cookies' => false, 'use_strict_mode' => false]);   // the id set above wins without use_only_cookies (deprecated in PHP 8.4)
	}
	nu_apply_timezone();
	require __DIR__ . '/pages/nupwa.php';
	exit;
}

// Health / status for monitoring tools (NuHealth): JSON WITHOUT nu_boot's session – a monitor calling every minute must not
// create sessions or get a cookie; the details only with the key (header X-Health-Key, Authorization: Bearer or ?key=)
if (($_GET['p'] ?? '') === 'health' && !NuInstall::needed()) {
	nu_config();
	nu_security_headers();
	NuHealth::serve();   // the time zone only once the database answers (nu_timezone may read the shared settings)
	exit;
}

// Reset database in steps (NuDbReset::step): WITHOUT nu_boot's session and sign-in – the job's random token is the right;
// between the steps the database has no sessions table, and a step must not wait for the session lock of the page's other requests
if (($_GET['p'] ?? '') === 'dbresetstep' && !NuInstall::needed()) {
	nu_config();
	nu_security_headers();
	nu_apply_timezone();
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	$tok = (string)($_POST['job'] ?? '');
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !preg_match('/^[0-9a-f]{48}$/', $tok)) { http_response_code(400); exit('{"ok":false}'); }
	exit(json_encode(NuDbReset::step($tok), JSON_UNESCAPED_UNICODE));
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
	'dbsetup'     => ['public' => true],   // database not set up yet (NuDbState): globeadmin or setup token
	'lang'        => ['public' => true],
	'logout'      => ['public' => true],
	'twofa'       => ['public' => true],
	'forgot'      => ['public' => true],
	'reset'       => ['public' => true],
	'reportfile'  => ['public' => true],   // PDF of a report run through the REST API (token link)
	'brand'       => ['public' => true],
	'pwa'         => ['public' => true],   // installable app: manifest, icons, service worker, offline page (NuPwa)
	'branding'    => [],
	'setupwizard' => [],
	'adminroles'  => [],
	'dashboards'  => [],
	'dashboardapi' => ['json' => true],
	'palette'     => ['json' => true],
	'security'    => [],
	'securitycheck' => [],
	'gettingready' => [],   // Getting ready checklist after the installation (NuReady)
	'ipaccess'    => [],   // IP access (pages/nuipaccess.php)
	'sso'         => ['public' => true],   // single sign-on (pages/nusso.php: settings, start, return from the provider)
	'passkey'     => ['public' => true, 'json' => true],   // passkeys (pages/nupasskey.php): add, second factor, sign-in, confirmation
	'pwpolicy'    => [],   // password policy (pages/nupwpolicy.php)
	'reauth'      => [],
	'log'         => [],
	'notifications' => [],
	'notifyapi'   => ['json' => true],
	'debugbell'   => ['json' => true],
	'homejs'      => ['json' => true],
	'globalcodefile' => ['json' => true, 'public' => true],
		// Global JS & CSS as files (NuCustomCode::serve; ?p=customcode until 0.8.573)   // JavaScript of the start page hook nu_home (NuHomeHook::serveJs)
	'audit'       => [],
	'audithistory' => [],
	'debug'       => [],
	'sessions'    => [],
	'inspect'     => [],
	'cloner'      => [],
	'appcloner'   => [],
	'vendor'      => [],
	'filemanager' => [],                      // File Manager = the page nuFiles (pages/nufilemanager.php)
	'apiclients'  => [],
	'aiproviders' => [],   // AI providers (pages/nuaiproviders.php)
	'aiask'       => ['json' => true],   // "Ask AI" of the AI dialogues (pages/nuaiask.php)
	'webhooks'    => [],
	'recyclebin'  => [],   // recycle bin for records (pages/nurecyclebin.php)   // webhooks (pages/nuwebhooks.php)
	'helppages'   => [],   // user help pages: list / editor (pages/nuhelppages.php)
	'codesearch'  => [],   // code search (pages/nucodesearch.php)
	'projects'    => [],   // projects: list, project page, export / import (pages/nuprojects.php)
	'userhelp'    => [],   // user help pages: reader + JSON for the side panel (pages/nuuserhelp.php)
	'userhelpimg' => [],   // pictures of the user help pages (pages/nuuserhelpimg.php)
	'apiplayground' => [],
	'settings'    => [],
	'configfile'  => [],
	'globalcode'   => [],
	'jquery'       => [],                    // jQuery version + Migrate (NuJquery)
	'jqfile'       => ['json' => true],      // a jQuery file of custom/jquery/
	'jqwarn'       => ['json' => true],      // jQuery Migrate messages of a form page (assets/js/nu/nujqmigrate.js)
	'userfields'  => [],   // custom user fields
	'account'     => [],   // My account: the custom fields a user fills in himself
	'todo'        => [],
	'selftest'    => [],
	'dbreset'     => [],
	'themes'      => [],                     // themes (core/nutheme.php)                     // Reset database (developer mode)
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
	'reportai'    => [],                     // reports with AI: prompt, check, preview, apply – POST only
	'reportpdf'   => [],
	'runphp'      => [],                     // output page of nuRunPHP (pages/nurunphp.php, token from formapi action runphp)
	'sqlbuilder'  => [],
	'prompts'     => [],
	'import'      => [],
	'dataexport'  => [],   // data export (pages/nudataexport.php)
	'email'       => [],
	'mailoauth'   => [],   // OAuth 2 for the SMTP login: to the provider and back (oauth.php)
	'emailtemplates' => [],
	'formexport'  => [],
	'home'        => [],
	'pageno'      => [],                 // page numbers P001 … (core/nupageno.php)
	'password'    => [],
	'users'       => [],
	'user'        => [],
	'access'      => [],
	'accesslevel' => [],
	'form'        => [],
	'formapi'     => ['json' => true],
	'upload'      => ['json' => true],   // file upload of nuBuilder's Uppy objects (pages/nuupload.php)
	'forms'       => [],
	'formats'     => [],
	'items'       => [],
	'permissions' => [],
	'impersonate' => [],
	'sidebar'     => [],                 // side bar on / off, default, recently used, ☆ (pages/nusidebar.php)
	'files'       => [],
	'file'        => [],
	'translations' => [],
	'formatapi'   => ['json' => true],
	'fnlist'      => ['json' => true],
	'codenames'   => ['json' => true],             // tables, columns, hash variables for the code editors' suggestions
	'sysinfo'     => ['json' => true],
	'homeforms'   => ['json' => true],   // Home forms of the access levels, ▾ next to Home (NuHomeForms)   // System info dialog
	'codecheck'   => ['json' => true],             // PHP syntax check of the code editors
	'browserun'   => ['json' => true],             // run the browse SQL from its code editor (pages/nubrowserun.php)
	'snippetlist' => ['json' => true],
	'advsearch'   => ['json' => true],   // "My searches" of the advanced search of form lists (pages/nuadvsearch.php)   // code snippets of one code editor (pages/nusnippetlist.php)
	'pivot'       => ['json' => true],   // pivot of a form list (pages/nupivot.php; GET fmt=xlsx|csv = file)
	'calendar'    => ['json' => true],   // calendars: list view, calendar objects, moving events (pages/nucalendar.php)
	'viewfirst'   => ['json' => true],   // view mode: "Always open in view mode" of a form, for me (pages/nuviewfirst.php)
	'formstatus'  => ['json' => true],   // maintenance of forms on / off (pages/nuformstatus.php)
	'formaccess'  => ['json' => true],   // access levels of one form – dialog of the form's ⋮ menu / design mode (pages/nuformaccess.php)   // function list of the code editors (pages/nufnlist.php)
	'builder'     => [],
	'formai'      => [],                     // form with AI
	'dataaccess'  => [],                     // field data access + column visibility of a form
	'libpack'     => [],                     // library packs: build and sign (developer mode)
	'builderapi'  => ['json' => true],
	'plugins'     => [],                 // plugins: managed on Update → Plugins (pages/nuplugins.php)
	'plugin'      => [],                 // a page of a plugin (NuPlugin::route)
	'pluginasset' => [],                 // scripts, styles, pictures of a plugin's assets/ folder
	'page'        => [],                 // form type "page": the page file's own output (core/nupage.php)
	'pageasset'   => [],                 // css / js / images of a page folder
];

// nuBuilder Forte's nuGetURLParams(): parameters of an entry address (no route – index.php?country=CA, the auto login ?u=…,
// a permalink) are kept for the session, newer values replace older ones; p (the password hash of the auto login) never
if (!nu_is_post() && $_GET && !isset($routes[(string)($_GET['p'] ?? '')])) {
	$keep = (array)($_SESSION[NuSess::URL_PARAMS] ?? []);
	foreach ($_GET as $k => $v) if (is_string($k) && $k !== 'p' && is_string($v) && strlen($k) <= 64 && strlen($v) <= 2000 && count($keep) < 50) $keep[$k] = $v;
	$_SESSION[NuSess::URL_PARAMS] = $keep;
}

// nuBuilder's auto login: index.php?u=<login>[&p=<password hash>][&f=<form>&r=<record>][&h=<home>] – here p is the hash, not a page
if (is_string($_GET['u'] ?? null) && !isset($routes[(string)($_GET['p'] ?? '')]) && !nu_is_post()) {
	require __DIR__ . '/pages/nuautologin.php';
	exit;
}

// nuBuilder's permalink (nuSetURLPermaLink, "Copy permalink"): index.php?f=<form>&r=<record>[&h=<home>] – the same form here
// (also on F5 of such an address). h (nuBuilder's home form) is not used; "undefined" / "null" count as empty.
if (!isset($_GET['p']) && is_string($_GET['f'] ?? null) && $_GET['f'] !== '' && !nu_is_post()) {
	$clean = static fn($v) => is_string($v) && !in_array(strtolower(trim($v)), ['', 'undefined', 'null'], true) ? trim($v) : null;
	if (($pf = $clean($_GET['f'])) !== null && preg_match('/^[A-Za-z0-9_\-]{1,50}$/', $pf)) {
		$pr = $clean($_GET['r'] ?? null);
		// the other parameters stay: a list reloaded from such an address (code that rewrites the address bar the way nuBuilder
		// Forte does, then nuSearchAction) keeps its scope of properties (ps), search, page and sort
		$keep = [];
		foreach ($_GET as $k => $v) if (is_string($k) && is_string($v) && !in_array($k, ['f', 'r', 'h', 'p', 'u'], true)) $keep[$k] = $v;
		nu_redirect(nu_url('form', ['f' => $pf, 'r' => $pr !== null && preg_match('/^[A-Za-z0-9_\-.]{1,100}$/', $pr) ? $pr : null] + $keep));
	}
}

$p = $_GET['p'] ?? 'home';
if (!is_string($p) || !isset($routes[$p])) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}

// page forms: a fetch / XHR of the page gets JSON answers (401 login, 400 csrf …) instead of HTML pages
$pageJson = ($p === 'page' || $p === 'pageasset') && NuPage::wantsJson();
if ($pageJson && nu_is_post()) {
	$sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
	if (!nu_csrf_valid($sent)) NuPage::fail(400, 'csrf', t('err_csrf'), t('err_csrf_hint'));
}
// Uppy upload: a wrong token gets nuBuilder's upload answer {error, message} – Uppy's template shows message
if ($p === 'upload' && nu_is_post() && !nu_csrf_valid($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
	http_response_code(400);
	header('Content-Type: application/json; charset=utf-8');
	exit(json_encode(['error' => t('err_csrf'), 'message' => t('err_csrf') . ' ' . t('err_csrf_hint')], JSON_UNESCAPED_UNICODE));
}
if (nu_is_post()) nu_csrf_verify();
// Browsers post the lines of a <textarea> with CRLF; nuBuilder saves what its JavaScript reads (LF). Same line ends as nuBuilder,
// so code, SQL and texts saved here are not changed by a save without a change (dev/tests/compat.js meta:sqlbuilder)
if (nu_is_post()) array_walk_recursive($_POST, static function (&$v): void { if (is_string($v) && str_contains($v, "\r")) $v = str_replace(["\r\n", "\r"], "\n", $v); });
// the bell asks every minute: that alone must not keep an idle session alive (NuAuth::user)
if (($p === 'notifyapi' || $p === 'debugbell') && !nu_is_post()) define('NU_PASSIVE', true);
if ($p === 'formapi' && ($_GET['presence'] ?? '') === '1') define('NU_PASSIVE', true);   // the "also open" ping is no activity
// a page's background poll (?passive=1) does not count as activity either
if ($p === 'page' && ($_GET['passive'] ?? '') === '1' && !defined('NU_PASSIVE')) define('NU_PASSIVE', true);

if (!empty($_SESSION[NuSess::DB_RESET]['job'])) NuDbReset::afterJob();   // signed in again after a reset in steps
if (empty($routes[$p]['public'])) {
	$user = NuAuth::user();
	if (!$user) {
		// back here after the login – for a form post or a background request: the page it came from
		$next = nu_is_post() || !empty($routes[$p]['json']) || $pageJson ? NuAuth::nextFromReferer() : NuAuth::validNext('index.php?' . (string)($_SERVER['QUERY_STRING'] ?? ''));
		if ($next !== null) NuAuth::setNext($next);
		if (array_key_exists('redirect', $_GET) && !nu_is_post()) NuAuth::keepReturn((string)($_SERVER['HTTP_REFERER'] ?? ''));   // opened from another website: back there after the sign-in
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

	// database not set up (nuconfig.php written by hand for an empty database, tables missing, nuBuilder Forte older than 4.9,
	// NuDbState): the globeadmin goes to the set-up page, everybody else gets a notice
	if (!NuDbState::ready() && $p !== 'logout') {
		if (!empty($routes[$p]['json'])) {
			http_response_code(503);
			header('Content-Type: application/json');
			exit(json_encode(['ok' => false, 'errors' => [['message' => t('dbs_not_ready'), 'field' => '']]]));
		}
		if ($user['globeadmin'] && empty($user['demo'])) nu_redirect(nu_url('dbsetup'));
		http_response_code(503);
		nu_page_minimal(t('dbs_title'), t('dbs_not_ready'), false, true);
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
		if (!empty($_SESSION[NuSess::WEAK_ADMIN])) {        // globeadmin password "nu" or shorter than 8 characters
			unset($_SESSION[NuSess::WEAK_ADMIN]);
			nu_flash('warning', t('secchk_weak_admin_flash'));
		}
		// setup wizard: once per login, until nuconfig.php says $nuConfigShowInstaller = false (or "done" is in the database)
		if (!nu_is_post() && empty($routes[$p]['json']) && !in_array($p, NuSetupWizard::FREE_ROUTES, true) && !NuImpersonate::active()
			&& empty($_SESSION[NuSess::SETUP_WIZARD_SEEN]) && NuSetupWizard::pending()) {
			nu_redirect(nu_url('setupwizard'));
		}
	}
}

if (empty($routes[$p]['json'])) nu_compress_start();   // HTML pages compressed
require __DIR__ . "/pages/nu$p.php";
