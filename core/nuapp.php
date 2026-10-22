<?php
declare(strict_types=1);

define('NU_ROOT', dirname(__DIR__));
define('NU_VERSION', '0.8.380');
define('NU_BS', 'assets/vendor/bootstrap-5.3.8');
define('NU_BI', 'assets/vendor/bootstrap-icons-1.13.2');
define('NU_TINYMCE', 'assets/vendor/tinymce');      // not bundled: copy <nuBuilder>/third_party/tinymce here
define('NU_APEXCHARTS', 'assets/vendor/apexcharts-4.4.0');   // bundled, loaded on form pages when $nuConfigIncludeApexCharts is on (as nuBuilder)
define('NU_FEATURE_ACCESSIBILITY', false);   // user form switch "Accessibility features" hidden until accessibility is defined (TODO.md)

/**
 * Classes loaded on first use (0.8.207): feature modules that most requests never need. Files with functions or classes that are
 * checked with class_exists(…, false) stay in the require list below. dev/tests/run.php checks that every class of core/ is found.
 */
const NU_AUTOLOAD = [
	'NuCompat' => 'core/nucompat.php', 'NuSchemaCheck' => 'core/nuschemacheck.php', 'NuSettings' => 'core/nusettings.php', 'NuBranding' => 'core/nubranding.php', 'NuDashboard' => 'core/nudashboard.php', 'NuAutoUpdate' => 'core/nuautoupdate.php', 'NuGitHub' => 'core/nugithub.php', 'NuHomeHook' => 'core/nuhomehook.php',
	'NuHome' => 'core/nuhome.php', 'NuApi' => 'core/nuapi.php', 'NuApiError' => 'core/nuapi.php', 'NuApiClient' => 'core/nuapiclient.php',
	'NuApiPlayground' => 'core/nuapiplayground.php', 'NuInstall' => 'core/nuinstall.php', 'NuConfigFile' => 'core/nuconfigfile.php', 'NuSetupWizard' => 'core/nusetupwizard.php', 'NuPalette' => 'core/nupalette.php', 'NuAdmin' => 'core/nuadmin.php', 'NuTutorial' => 'core/nututorial.php', 'NuListView' => 'core/nulistview.php', 'NuListDefs' => 'core/nulistdefs.php', 'NuLoginToken' => 'core/nulogintoken.php', 'NuReportApi' => 'core/nureportapi.php', 'NuDemoData' => 'core/nudemodata.php', 'NuDbChanges' => 'core/nudbchanges.php', 'NuAudit' => 'core/nuaudit.php', 'NuNotify' => 'core/nunotify.php', 'NuSelfTest' => 'core/nuselftest.php',
	'NuSample' => 'core/nusample.php', 'NuListExport' => 'core/nulistexport.php', 'NuProcedure' => 'core/nuprocedure.php',
	'NuReport' => 'core/nureport.php', 'NuReportEngine' => 'core/nureportengine.php', 'NuReportSection' => 'core/nureportengine.php',
	'NuSqlSelect' => 'core/nusqlselect.php', 'NuPrompt' => 'core/nuprompt.php', 'NuImport' => 'core/nuimport.php',
	'NuPermission' => 'core/nupermission.php', 'NuAppCloner' => 'core/nuappcloner.php', 'NuAppClonerConfig' => 'core/nuappclonerconfig.php', 'NuClonerConfig' => 'core/nuclonerconfig.php', 'NuSqlDump' => 'core/nusqldump.php',
	'NuInspect' => 'core/nuinspect.php', 'NuCloner' => 'core/nucloner.php', 'NuFormHistory' => 'core/form/nuhistory.php',
	'NuBuilderGen' => 'core/form/nubuilder_gen.php', 'NuLibraries' => 'core/nulibraries.php',
];
spl_autoload_register(static function (string $class): void {
	if (isset(NU_AUTOLOAD[$class])) require_once NU_ROOT . '/' . NU_AUTOLOAD[$class];
});

require NU_ROOT . '/core/nudb.php';
require NU_ROOT . '/core/nudemo.php';
require NU_ROOT . '/core/nuinfra.php';
require NU_ROOT . '/core/nusecurity.php';
require NU_ROOT . '/core/nuhousekeeping.php';
require NU_ROOT . '/core/numail.php';
require NU_ROOT . '/core/nuitems.php';
require NU_ROOT . '/core/nufiles.php';
require NU_ROOT . '/core/nutranslate.php';
require NU_ROOT . '/core/nudebug.php';
require NU_ROOT . '/core/nusessions.php';
require NU_ROOT . '/core/nuvendor.php';
require NU_ROOT . '/core/nuupdate.php';
require NU_ROOT . '/core/nuauth.php';
require NU_ROOT . '/core/nuimpersonate.php';
require NU_ROOT . '/core/nutwofa.php';
require NU_ROOT . '/core/nuacl.php';
require NU_ROOT . '/core/nulayout.php';
require NU_ROOT . '/core/form/nuformat.php';
require NU_ROOT . '/core/form/nuhash.php';
require NU_ROOT . '/core/form/nuprops.php';
require NU_ROOT . '/core/form/numeta.php';
require NU_ROOT . '/core/form/nuphp.php';
require NU_ROOT . '/core/form/nubrowse.php';
require NU_ROOT . '/core/form/nurecord.php';
require NU_ROOT . '/core/form/nulookup.php';
require NU_ROOT . '/core/form/nulayout.php';
require NU_ROOT . '/core/form/nurender.php';
require NU_ROOT . '/core/form/nuddl.php';
require NU_ROOT . '/core/form/nutrail.php';

// ===================================================================== config & db

function nu_config_path(): string
{
	return getenv('NU_CONFIG') ?: NU_ROOT . '/nuconfig.php';
}

/** The variables of nuconfig.php as written there (no defaults, no values from zzzzsys_config). */
function nu_config_file(): array
{
	static $vars = null;
	if ($vars !== null) return $vars;
	$file = nu_config_path();
	if (!is_file($file)) nu_fatal(t('err_no_config'), t('err_no_config_hint'));
	return $vars = (static function (string $__file): array {
		require $__file;
		unset($__file);
		return get_defined_vars();
	})($file);
}

/** nuconfig.php with nuBuilder Next's defaults (database connection and other values read before the database). */
function nu_config(): array
{
	static $cfg = null;
	if ($cfg !== null) return $cfg;
	return $cfg = nu_config_file() + [
		'nuConfigDBType'                   => 'mysql',
		'nuConfigDBPort'                   => '',
		'nuConfigDBCharacterSet'           => 'utf8mb4',
		'nuConfigDBCollate'                => '',
		'nuConfigDBOptions'                => [],
		'nuConfigDBTrustServerCertificate' => false,
		'nuConfigDBGlobeadminUsername'     => 'globeadmin',
		'nuConfigDBGlobeadminPassword'     => '',
		'nuConfigPasswordUpgrade'          => true,
		'nuConfigSessionTimeout'           => 120,
		'nuConfigSessionTimeoutGlobeadmin' => 720,
		'nuConfigPasswordMinLength'        => 8,
		'nuConfigDebug'                    => false,
		'nuConfigIncludePHP'               => [],
		'nuConfig2FAAdmin'                 => false,
		'nuConfig2FAUser'                  => false,
		'nuConfig2FATrustDays'             => 30,
		'nuConfigSharedLogin'              => false,
		'nuConfigLogRecordChanges'         => true,
	];
}

/**
 * The time zone PHP uses: [name, source] – source 'config' ($nuConfigTimezone), 'setup' (nuBuilder's Setup → Time zone,
 * zzzzsys_setup / zzzzsys_timezone – what nuBuilder itself uses) or 'server' (PHP's default, e.g. the host's zone).
 * nuBuilder's Setup is read once per session (it changes rarely); the API reads it per request.
 */
function nu_timezone(): array
{
	static $r = null;
	if ($r !== null) return $r;
	$valid = static fn(string $tz): bool => $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
	$tz = trim((string)nu_cfg('nuConfigTimezone'));
	if ($valid($tz)) return $r = [$tz, 'config'];
	if (session_status() === PHP_SESSION_ACTIVE && is_string($_SESSION['nu_setup_tz'] ?? null)) {
		$tz = $_SESSION['nu_setup_tz'];
	} else {
		$tz = trim((string)(NuMailer::setupRow()['set_timezone'] ?? ''));
		if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['nu_setup_tz'] = $tz;
	}
	if ($valid($tz)) return $r = [$tz, 'setup'];
	return $r = [date_default_timezone_get(), 'server'];
}

function nu_apply_timezone(): void
{
	date_default_timezone_set(nu_timezone()[0]);
}

/** A setting: with $nuConfigSettingsFromDB = true the value of nuBuilder's settings table (zzzzsys_config) wins, as in nuBuilder. */
function nu_cfg(string $key): mixed
{
	$c = nu_config();
	if (!empty($c['nuConfigSettingsFromDB'])) {
		$db = NuSettings::values()['php'];
		if (array_key_exists($key, $db)) return $db[$key];
	}
	return $c[$key] ?? null;
}

function nu_db(): NuDB
{
	static $db = null;
	if ($db) return $db;

	$c = nu_config();
	try {
		return $db = new NuDB([
			'type'       => $c['nuConfigDBType'],
			'host'       => $c['nuConfigDBHost'] ?? '127.0.0.1',
			'port'       => $c['nuConfigDBPort'],
			'name'       => $c['nuConfigDBName'] ?? '',
			'user'       => $c['nuConfigDBUser'] ?? '',
			'password'   => $c['nuConfigDBPassword'] ?? '',
			'charset'    => $c['nuConfigDBCharacterSet'],
			'collate'    => $c['nuConfigDBCollate'],
			'options'    => (array)$c['nuConfigDBOptions'],
			'trust_cert' => $c['nuConfigDBTrustServerCertificate'],
		]);
	} catch (Throwable $e) {
		error_log('nuBuilder Next DB connect: ' . $e->getMessage());
		nu_fatal(t('err_db_connect'), $c['nuConfigDebug'] ? $e->getMessage() : t('err_db_connect_hint'));
	}
}

/** Session timeout in seconds: $nuConfigSessionTimeoutGlobeadmin for the globeadmin, $nuConfigSessionTimeout for everybody else (minutes in nuconfig.php). */
/*
 * Functions a host may switch off with disable_functions (0.8.273): calling one that is switched off ends the request with
 * "Call to undefined function" – "@" does not help. These wrappers do nothing (or return '') instead.
 */
function nu_time_limit(int $seconds): void
{
	if (function_exists('set_time_limit')) @set_time_limit($seconds);
}

function nu_ignore_abort(): void
{
	if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
}

function nu_ini_get(string $key): string
{
	return function_exists('ini_get') ? (string)ini_get($key) : '';
}

function nu_ini_set(string $key, string $value): void
{
	if (function_exists('ini_set')) @ini_set($key, $value);
}

function nu_session_timeout(bool $globeadmin = false): int
{
	return max(1, (int)nu_cfg($globeadmin ? 'nuConfigSessionTimeoutGlobeadmin' : 'nuConfigSessionTimeout')) * 60;
}

// ===================================================================== boot

/** Security headers of every page (nu_boot; the installer and the API send their own). */
function nu_security_headers(): void
{
	header('X-Frame-Options: DENY');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: same-origin');
	header('Cross-Origin-Opener-Policy: same-origin');
	header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), serial=(), interest-cohort=()');
	if (nu_https()) header('Strict-Transport-Security: max-age=31536000');
}

function nu_boot(): void
{
	nu_config();                // fails early with a setup hint when nuconfig.php is missing
	nu_security_headers();
	header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

	// PHP must keep idle session files at least as long as the longer of the two timeouts (PHP's default is 24 minutes)
	nu_ini_set('session.gc_maxlifetime', (string)max(nu_session_timeout(false), nu_session_timeout(true), (int)nu_ini_get('session.gc_maxlifetime')));
	nu_ini_set('session.use_strict_mode', '1');            // no session ids made up by the client
	session_name('nunext');
	// cookie only for this folder (since 0.8.207): another copy of nuBuilder Next on the same domain has its own session
	session_set_cookie_params(['lifetime' => 0, 'path' => NuSecurity::cookiePath(), 'secure' => nu_https(), 'httponly' => true, 'samesite' => 'Lax']);
	session_start();
	nu_apply_timezone();

	NuHousekeeping::maybe();                           // without cron: tokens, sessions, log retention … now and then (core/nuhousekeeping.php)

	set_exception_handler(static function (Throwable $e) {
		if ($e instanceof NuDemoBlocked) nu_fatal(t('demo_not_available'), t('demo_blocked_hint'), true);   // demo mode: code that writes
		error_log('nuBuilder Next: ' . $e);
		nu_fatal(t('err_unexpected'), nu_cfg('nuConfigDebug') ? $e->getMessage() : t('err_unexpected_hint'));
	});
}

// ===================================================================== helpers

function h(mixed $s): string
{
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL of a local asset with a version stamp, so browsers load the new file after an update. */
function nu_asset(string $path): string
{
	static $cache = [];
	if (!isset($cache[$path])) {
		$mtime = @filemtime(NU_ROOT . '/' . $path);
		$cache[$path] = $path . '?v=' . ($mtime ? base_convert((string)$mtime, 10, 36) : NU_VERSION);
	}
	return $cache[$path];
}

function nu_url(string $page, array $params = []): string
{
	$q = http_build_query(['p' => $page] + array_filter($params, static fn($v) => $v !== null && $v !== ''));
	return 'index.php?' . $q;
}

function nu_redirect(string $url): never
{
	// "Save" in the leave dialog (assets/js/nuapp.js): after a successful save go to the link that was clicked instead of the record.
	// Only local paths (/… or index.php?…), and only when the last notice is a success (errors keep the redirect of the page).
	$then = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? nu_post('_nu_then') : '';
	if ($then !== '' && !preg_match('/[\r\n\\\\]/', $then) && !str_starts_with($then, '//') && (str_starts_with($then, '/') || str_starts_with($then, 'index.php?'))) {
		$fl = $_SESSION['nu_flash'] ?? [];
		$last = $fl ? end($fl) : null;
		if ($last && $last[0] === 'success') $url = $then;
	}
	header('Location: ' . $url, true, 303);
	exit;
}

/** nuBuilder-style primary key: uniqid() + 2-digit counter (15 chars, fits varchar(25)). */
function nu_id(): string
{
	static $n = 0;
	$n = ($n + 1) % 100;
	return uniqid() . sprintf('%02d', $n);
}

function nu_post(string $key, string $default = ''): string
{
	$v = $_POST[$key] ?? $default;
	return is_string($v) ? trim($v) : $default;
}

function nu_check(string $key): string
{
	return (isset($_POST[$key]) && $_POST[$key] === '1') ? '1' : '0';
}

function nu_is_post(): bool
{
	return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// ----------------------------------------------------------------- flash messages

/** $toast (0.8.377): shown as a toast at the top right (closes by itself) instead of an alert at the top of the page. */
function nu_flash(string $type, string $msg, bool $toast = false): void
{
	$_SESSION['nu_flash'][] = [$type, $msg, $toast];
}

function nu_flashes(): array
{
	$f = $_SESSION['nu_flash'] ?? [];
	unset($_SESSION['nu_flash']);
	return $f;
}

// ----------------------------------------------------------------- CSRF

function nu_csrf_token(): string
{
	return $_SESSION['nu_csrf'] ??= bin2hex(random_bytes(32));
}

function nu_csrf_field(): string
{
	return '<input type="hidden" name="_csrf" value="' . h(nu_csrf_token()) . '">';
}

function nu_csrf_verify(): void
{
	$sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
	if (!is_string($sent) || !hash_equals(nu_csrf_token(), $sent)) {
		// login page open while the session was gone (idle tab): show it again – with the same page to return to
		if (($_GET['p'] ?? '') === 'login') {
			nu_flash('warning', t('login_form_expired'));
			nu_redirect(NuAuth::loginUrl(is_string($_POST['next'] ?? null) ? $_POST['next'] : (is_string($_GET['next'] ?? null) ? $_GET['next'] : null)));
		}
		http_response_code(400);
		nu_fatal(t('err_csrf'), t('err_csrf_hint'), true);
	}
}

// ===================================================================== i18n

/** Languages of nuBuilder Next: code => [own name, nuBuilder's name in sus_language / zzzzsys_translate]. */
const NU_LANGS = [
	'de' => ['Deutsch', 'German'],
	'en' => ['English', 'English'],
	'fr' => ['Français', 'French'],
	'it' => ['Italiano', 'Italian'],
	'es' => ['Español', 'Spanish'],
	'pt' => ['Português', 'Portuguese'],
	'ru' => ['Русский', 'Russian'],
	'zh' => ['简体中文', 'Chinese'],
	'nl' => ['Nederlands', 'Dutch'],
	'pl' => ['Polski', 'Polish'],
	'ar' => ['العربية', 'Arabic'],
];

/** Languages written right to left (html dir="rtl", Bootstrap's RTL stylesheet). */
const NU_LANGS_RTL = ['ar'];

function nu_lang_rtl(): bool
{
	return in_array(nu_lang(), NU_LANGS_RTL, true);
}

/**
 * Language variants (0.8.314 English, 0.8.315 German): lang/en.php is British English (en-GB, the base), lang/en-AU.php and
 * lang/en-US.php hold only the texts that differ; lang/de.php is German German (de-DE, the base), lang/de-CH.php holds the Swiss
 * texts (ss, «»). en-US.php and de-CH.php are generated by dev/lang_variants.php at every release.
 * nu_lang() stays the base code ('en' / 'de' – nuBuilder only knows "English" / "German"); nu_lang_variant() is the chosen variant.
 * Variant code => [base, own name, Intl locale].
 */
const NU_LANG_VARIANTS = [
	'de-DE' => ['de', 'Deutsch (DE)', 'de-DE'],
	'de-CH' => ['de', 'Deutsch (CH)', 'de-CH'],
	'en-GB' => ['en', 'English (UK)', 'en-GB'],
	'en-AU' => ['en', 'English (Australia)', 'en-AU'],
	'en-US' => ['en', 'English (US)', 'en-US'],
];
/** Variant of a base language when nothing else decides. */
const NU_LANG_VARIANT_DEFAULT = ['de' => 'de-DE', 'en' => 'en-GB'];

/** Supported code for any code / locale (de-CH, zh_Hans, pt-BR …), or null. */
function nu_lang_code(string $v): ?string
{
	$v = strtolower(trim($v));
	if ($v === '') return null;
	$c = substr($v, 0, 2);
	return isset(NU_LANGS[$c]) && (strlen($v) === 2 || in_array($v[2], ['-', '_'], true)) ? $c : null;
}

/**
 * Variant for a browser / locale tag: en-US, en-CA and plain "en" → en-US, en-AU and en-NZ → en-AU, other English → en-GB;
 * de-CH → de-CH, other German (plain "de", de-AT …) → de-DE.
 */
function nu_lang_variant_of(string $tag): ?string
{
	$t = strtolower(str_replace('_', '-', trim($tag)));
	foreach (NU_LANG_VARIANTS as $code => $_) if ($t === strtolower($code)) return $code;
	$base = nu_lang_code($t);
	$region = substr($t, 3, 2);
	if ($base === 'de') return $region === 'ch' ? 'de-CH' : 'de-DE';
	if ($base !== 'en') return null;
	if ($region === '' || in_array($region, ['us', 'ca'], true)) return 'en-US';
	if (in_array($region, ['au', 'nz'], true)) return 'en-AU';
	return 'en-GB';
}

/** The browser's preferred supported language: [base code, tag as sent] or null. */
function nu_lang_browser(): ?array
{
	static $r = false;
	if ($r !== false) return $r;
	$best = null; $bestQ = -1.0;
	foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $i => $part) {
		$bits = explode(';', trim($part));
		$code = nu_lang_code($bits[0]);
		if ($code === null) continue;
		$q = 1.0;
		foreach (array_slice($bits, 1) as $b) if (preg_match('/^\s*q=([0-9.]+)/', $b, $m)) $q = (float)$m[1];
		$q -= $i / 1000;                                   // equal q: the earlier one wins
		if ($q > $bestQ) { $best = [$code, trim($bits[0])]; $bestQ = $q; }
	}
	return $r = $best;
}

function nu_lang(): string
{
	if (isset($_SESSION['nu_lang']) && isset(NU_LANGS[$_SESSION['nu_lang']])) return $_SESSION['nu_lang'];
	// browser languages in order of preference (q values), first supported one; none supported: German (as before)
	return nu_lang_browser()[0] ?? 'de';
}

/** The chosen variant of the current language ('' = the language has no variants). */
function nu_lang_variant(): string
{
	$lang = nu_lang();
	if (!isset(NU_LANG_VARIANT_DEFAULT[$lang])) return '';
	$v = $_SESSION['nu_lang_variant'] ?? '';
	if (is_string($v) && (NU_LANG_VARIANTS[$v][0] ?? '') === $lang) return $v;
	$b = nu_lang_browser();
	if (!isset($_SESSION['nu_lang']) && $b !== null && $b[0] === $lang) return nu_lang_variant_of($b[1]) ?? NU_LANG_VARIANT_DEFAULT[$lang];
	return NU_LANG_VARIANT_DEFAULT[$lang];
}

/** Language as chosen: the variant (en-US …) or the code (de …) – for menus, <html lang> and links. */
function nu_lang_choice(): string
{
	return nu_lang_variant() ?: nu_lang();
}

/** Entries of the language menu: choice code => own name (English as its variants). */
function nu_lang_choices(): array
{
	$out = [];
	foreach (NU_LANGS as $code => [$own]) {
		if (isset(NU_LANG_VARIANT_DEFAULT[$code])) {
			foreach (NU_LANG_VARIANTS as $vc => [$base, $vName]) if ($base === $code) $out[$vc] = $vName;
		} else {
			$out[$code] = $own;
		}
	}
	return $out;
}

/** Sets the language: a code (de) or a variant (en-US). A plain 'en' keeps a variant chosen before. */
function nu_set_lang(string $lang): void
{
	if (isset(NU_LANG_VARIANTS[$lang])) {
		$_SESSION['nu_lang'] = NU_LANG_VARIANTS[$lang][0];
		$_SESSION['nu_lang_variant'] = $lang;
		return;
	}
	$_SESSION['nu_lang'] = isset(NU_LANGS[$lang]) ? $lang : 'de';
	if ((NU_LANG_VARIANTS[$_SESSION['nu_lang_variant'] ?? ''][0] ?? '') !== $_SESSION['nu_lang']) unset($_SESSION['nu_lang_variant']);
}

/** nuBuilder stores language names ('German', 'French', … '' = default); unknown names count as English (as nuBuilder). */
function nu_lang_from_db(?string $value): ?string
{
	$v = strtolower(trim((string)$value));
	if ($v === '') return null;
	foreach (NU_LANGS as $code => [$own, $db]) if ($v === strtolower($db) || $v === mb_strtolower($own)) return $code;
	$alias = ['deutsch' => 'de', 'francais' => 'fr', 'espanol' => 'es', 'portugues' => 'pt', 'chinese (simplified)' => 'zh', 'simplified chinese' => 'zh', '中文' => 'zh',
		'nederlands' => 'nl', 'flemish' => 'nl', 'polski' => 'pl', 'arabic (standard)' => 'ar', 'عربي' => 'ar'];
	return $alias[$v] ?? nu_lang_code($v) ?? 'en';
}

function nu_lang_to_db(string $lang): string
{
	return NU_LANGS[$lang][1] ?? 'English';
}

/** Locale for number / date formatting in the browser (Intl), e.g. de-CH. */
function nu_locale(): string
{
	$v = nu_lang_variant();
	if ($v !== '') return NU_LANG_VARIANTS[$v][2];
	return ['de' => 'de-DE', 'en' => 'en-GB', 'fr' => 'fr-CH', 'it' => 'it-CH', 'es' => 'es-ES', 'pt' => 'pt-PT', 'ru' => 'ru-RU', 'zh' => 'zh-CN',
		'nl' => 'nl-NL', 'pl' => 'pl-PL', 'ar' => 'ar-u-nu-latn'][nu_lang()] ?? 'en';   // Arabic with Western digits (as in the data)
}

function t(string $key, mixed ...$args): string
{
	static $dict = [];
	$lang = nu_lang();
	$dict[$lang] ??= require NU_ROOT . "/lang/$lang.php";
	$dict['en']  ??= require NU_ROOT . '/lang/en.php';
	$var = nu_lang_variant();                               // en-AU / en-US: only the texts that differ, over lang/en.php
	if ($var !== '' && $var !== NU_LANG_VARIANT_DEFAULT[$lang]) {
		$dict[$var] ??= is_file(NU_ROOT . "/lang/$var.php") ? (require NU_ROOT . "/lang/$var.php") : [];
		if (isset($dict[$var][$key])) return $args ? vsprintf($dict[$var][$key], $args) : $dict[$var][$key];
	}
	$s = $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
	return $args ? vsprintf($s, $args) : $s;
}

// ===================================================================== errors

function nu_fatal(string $title, string $detail = '', bool $withHome = false): never
{
	if (!headers_sent()) http_response_code(http_response_code() >= 400 ? http_response_code() : 500);
	nu_page_minimal($title, $detail, $withHome);
	exit;
}

// ===================================================================== form list (zzzzsys_form)

/**
 * All forms an access level can be given rights to: [id => ['code','description','type','system']].
 * Falls back to the two system forms this app uses when zzzzsys_form is absent.
 */
function nu_forms(): array
{
	static $forms = null;
	if ($forms !== null) return $forms;

	$forms = [];
	$db    = nu_db();
	if ($db->tableExists('zzzzsys_form')) {
		foreach ($db->all('SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_type FROM zzzzsys_form ORDER BY sfo_code') as $r) {
			$forms[$r['zzzzsys_form_id']] = [
				'code'        => (string)$r['sfo_code'],
				'description' => (string)$r['sfo_description'],
				'type'        => (string)$r['sfo_type'],
				'system'      => str_starts_with((string)$r['zzzzsys_form_id'], 'nu'),
			];
		}
	}
	foreach ([NuAcl::FORM_USER => 'Users', NuAcl::FORM_ACCESS => 'Access Levels', NuAcl::FORM_FORM => 'Forms', NuAcl::FORM_LOG => 'nuBuilder Next Log', NuAcl::FORM_SETUP => 'Setup', NuAcl::FORM_EMAILLOG => 'E-Mail Log', NuAcl::FORM_FORMAT => 'Formats', NuAcl::FORM_EMAILTEMPLATE => 'Email Templates', NuAcl::FORM_ITEM => 'Items', NuAcl::FORM_FILE => 'Files', NuAcl::FORM_TRANSLATE => 'Translation', NuAcl::FORM_DEBUG => 'Debug Results', NuAcl::FORM_PROCEDURE => 'Procedures', NuAcl::FORM_PERMISSION => 'Permission Items'] as $id => $desc) {
		$forms[$id] ??= ['code' => $id, 'description' => $desc, 'type' => 'browseedit', 'system' => true];
	}
	return $forms;
}

function nu_form_label(string $id): string
{
	$f = nu_forms()[$id] ?? null;
	if (!$f) return $id;
	return $f['description'] !== '' ? $f['code'] . ' – ' . $f['description'] : $f['code'];
}

/** Rights on formats (zzzzsys_format) for the browser. */
function nu_format_rights(): array
{
	return ['add' => NuAcl::can(NuAcl::FORM_FORMAT, 'add'), 'create' => NuAcl::can(NuAcl::FORM_FORMAT, 'create'), 'save' => NuAcl::can(NuAcl::FORM_FORMAT, 'save'), 'delete' => NuAcl::can(NuAcl::FORM_FORMAT, 'delete')];
}

/** Labels and data the format designer (assets/js/nuformatdesigner.js) needs. */
function nu_format_designer_data(): array
{
	$labels = [];
	foreach (array_keys(require NU_ROOT . '/lang/en.php') as $k) if (str_starts_with($k, 'fmt_')) $labels[$k] = t($k);
	foreach (['save', 'cancel', 'delete', 'yes', 'no'] as $k) $labels[$k] = t($k);
	return ['csrf' => nu_csrf_token(), 'labels' => $labels, 'rights' => nu_format_rights(), 'max' => NuFormat::MAX_KEY - 2,
		'client' => (object)NuFormat::clientRegistry()];
}

// ===================================================================== validation helpers

/** Adds an error when $value exceeds the column length. */
function nu_maxlen(array &$errors, string $field, string $value, int $max): void
{
	if (mb_strlen($value) > $max) $errors[$field] = t('err_too_long', $max);
}

function nu_valid_date(string $ymd): bool
{
	$d = DateTime::createFromFormat('!Y-m-d', $ymd);
	return $d && $d->format('Y-m-d') === $ymd;
}

/** Normalises DB datetime values ('2026-10-14 00:00:00.000', DateTime, '0000-00-00…') to 'Y-m-d' or ''. */
function nu_date_only(mixed $v): string
{
	if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
	$v = trim((string)$v);
	if ($v === '' || str_starts_with($v, '0000')) return '';
	return substr($v, 0, 10);
}

function nu_is_expired(mixed $expiresOn): bool
{
	$d = nu_date_only($expiresOn);
	return $d !== '' && $d < date('Y-m-d');      // valid through the stored day
}

/**
 * Delete / Archive dialog for users (users list and user form). Opened by a button with data-bs-toggle="modal"
 * data-bs-target="#nuUserRemove" data-choice-action (user form URL), data-choice-name, data-choice-archived ("1" = already expired).
 * $hidden: extra hidden fields (return = list query to go back to, stay = 1: back to the user form after archiving).
 */
function nu_user_remove_modal(array $rights, array $hidden = []): string
{
	if (!$rights['delete'] && !$rights['save']) return '';
	ob_start(); ?>
<div class="modal fade nuRowChoice" id="nuUserRemove" tabindex="-1" aria-labelledby="nuUserRemoveTitle" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form class="modal-content" method="post" action="">
			<?= nu_csrf_field() ?><?php foreach ($hidden as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
			<div class="modal-header">
				<h2 class="modal-title fs-5" id="nuUserRemoveTitle"><?= h(t('user_remove')) ?>: <span class="js-choice-name"></span></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
			</div>
			<div class="modal-body">
				<?php if ($rights['save']): ?>
					<p class="mb-2"><i class="bi bi-archive me-2 text-warning"></i><strong><?= h(t('user_archive')) ?>:</strong> <?= h(t('user_archive_hint')) ?></p>
					<p class="small text-body-secondary js-choice-archived-note" hidden><?= h(t('user_archive_already')) ?></p>
				<?php endif; ?>
				<?php if ($rights['delete']): ?>
					<p class="mb-0"><i class="bi bi-trash me-2 text-danger"></i><strong><?= h(t('delete')) ?>:</strong> <?= h(t('user_delete_hint')) ?></p>
				<?php endif; ?>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
				<?php if ($rights['save']): ?><button type="submit" class="btn btn-warning js-choice-archive" name="action" value="archive"><i class="bi bi-archive me-1"></i><?= h(t('user_archive')) ?></button><?php endif; ?>
				<?php if ($rights['delete']): ?><button type="submit" class="btn btn-danger" name="action" value="delete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button><?php endif; ?>
			</div>
		</form>
	</div>
</div>
<?php
	return (string)ob_get_clean();
}
