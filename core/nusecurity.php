<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Security settings and helpers (0.8.207, from the security review in TODO.md).
 *
 * Settings: one JSON row 'security' in zzzzsys_nunext_setting, edited on Admin tools → Security check (pages/nusecuritycheck.php).
 * Helpers: HTTPS detection behind a proxy, login rate limit (counted in the log, so it works without cookies), admin-equivalent
 * rights, protected folders, masking of secrets (log, demo mode), recent password confirmation for dangerous actions,
 * the installation id that binds a session to this folder and database.
 */
final class NuSecurity
{
	public const SETTING = 'security';

	public const DEFAULTS = [
		'rights_globeadmin_only' => true,   // only the globeadmin grants rights on ADMIN_FORMS; nobody changes his own access level / rights
		'protect_fields'         => false,  // read-only / hidden objects are not saved from the browser
		'record_check'           => false,  // a record must be found through the form's browse SQL before it is opened, saved or deleted
		'field_access_ga'        => false,  // field data access / column visibility rules apply to the globeadmin too (testing, 0.8.480)
		'md5_login'              => true,   // legacy md5 passwords (nuBuilder before 4.5) are still accepted
		'login_token'            => true,   // nuBuilder's single-use login token (sus_json LOGIN_TOKEN, ?u=…&p=<token>) – core/nulogintoken.php (0.8.339)
		'form_drafts_required'   => false,  // form builder (0.8.386): published forms change only through drafts (the globeadmin: live after a confirmation)
		'form_preview_saves'     => false,  // default of new drafts: Save / Delete allowed in the draft preview (writes live data)
		'log_keep_days'          => 0,      // 0 = keep the log for ever
		'reauth_minutes'         => 15,     // password again for dangerous actions when the last confirmation is older (0 = never ask)
		'reauth_minutes_dev'     => 480,    // the same for the globeadmin while $nuConfigDevMode is on (0.8.338: development without live data)
	];

	/**
	 * nuBuilder's system forms whose save / add / new / delete rights give full control: PHP code (form builder, procedures),
	 * SQL on every table (SQL builder), the mail server (setup), passwords and access levels of all users.
	 */
	public const ADMIN_FORMS = ['nuform', 'nuprocedure', 'nuselect', 'nusetup', 'nuuser', 'nuaccess'];
	public const ADMIN_RIGHTS = ['slf_save_button', 'slf_add_button', 'slf_new_button', 'slf_delete_button', 'slf_clone_button'];

	// login rate limit: failed logins (and wrong second-factor codes) in the last FAIL_WINDOW seconds
	public const FAIL_WINDOW       = 900;
	public const FAIL_PER_LOGIN_IP = 5;     // this name from this address
	public const FAIL_PER_LOGIN    = 20;    // this name from anywhere (slows down distributed guessing)
	public const FAIL_PER_IP       = 30;    // this address, any name

	private static ?array $settings = null;

	// ================================================================= settings

	public static function settings(): array
	{
		if (self::$settings !== null) return self::$settings;
		$stored = [];
		try {
			NuSchema::ensure();
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$stored = is_string($v) ? (json_decode($v, true) ?: []) : [];
		} catch (Throwable) {
		}
		$out = self::DEFAULTS;
		foreach (self::DEFAULTS as $k => $d) {
			if (!array_key_exists($k, $stored)) continue;
			$out[$k] = is_bool($d) ? (bool)$stored[$k] : max(0, (int)$stored[$k]);
		}
		return self::$settings = $out;
	}

	public static function get(string $key): mixed
	{
		return self::settings()[$key] ?? self::DEFAULTS[$key] ?? null;
	}

	/** Saves the known keys. @return array changed keys => [old, new] */
	public static function save(array $in): array
	{
		$old = self::settings();
		$new = $old;
		foreach (self::DEFAULTS as $k => $d) {
			if (!array_key_exists($k, $in)) continue;
			$new[$k] = is_bool($d) ? (bool)$in[$k] : max(0, min($k === 'log_keep_days' ? 36500 : 1440, (int)$in[$k]));
		}
		$changed = [];
		foreach ($new as $k => $v) if ($v !== $old[$k]) $changed[$k] = [$old[$k], $v];
		if (!$changed) return [];
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => json_encode($new), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) {
			$db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
		} else {
			$db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
		}
		self::$settings = $new;
		return $changed;
	}

	// ================================================================= request

	/** Folder of nuBuilder Next in the URL, with trailing slash ('/nunext/') – the path of its cookies. */
	public static function cookiePath(): string
	{
		$dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
		return ($dir === '' || $dir === '.') ? '/' : $dir . '/';
	}

	/** Binds a login to this installation (folder + database): a session of another copy on the same domain does not count here. */
	public static function installId(): string
	{
		static $id = null;
		return $id ??= substr(hash('sha256', NU_ROOT . '|' . (string)(nu_config()['nuConfigDBHost'] ?? '') . '|' . (string)(nu_config()['nuConfigDBName'] ?? '')), 0, 16);
	}

	// ================================================================= login rate limit

	/** Too many failed logins for this name / this address in the last 15 minutes? (counted in zzzzsys_nunext_log) */
	public static function loginBlocked(string $login): bool
	{
		try {
			NuSchema::ensure();
			$db = nu_db();
			$since = date('Y-m-d H:i:s', time() - self::FAIL_WINDOW);
			$ip = mb_substr(nu_client_ip(), 0, 45);
			$login = mb_substr($login, 0, 50);
			$base = "SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE log_time > ? AND event IN ('login_failed', '2fa_failed', 'reauth_failed')";
			if ((int)$db->value("$base AND login = ? AND ip = ?", [$since, $login, $ip]) >= self::FAIL_PER_LOGIN_IP) return true;
			if ((int)$db->value("$base AND login = ?", [$since, $login]) >= self::FAIL_PER_LOGIN) return true;
			return $ip !== '' && (int)$db->value("$base AND ip = ?", [$since, $ip]) >= self::FAIL_PER_IP;
		} catch (Throwable $e) {
			error_log('nuBuilder Next login limit: ' . $e->getMessage());
			return false;
		}
	}

	/** Requests of one kind from this address in the last $seconds (log events), e.g. "forgot password". */
	public static function recentEvents(string $event, int $seconds): int
	{
		try {
			return (int)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE log_time > ? AND event = ? AND ip = ?',
				[date('Y-m-d H:i:s', time() - $seconds), $event, mb_substr(nu_client_ip(), 0, 45)]);
		} catch (Throwable) {
			return 0;
		}
	}

	// ================================================================= admin-equivalent rights

	/** Admin forms an access level has save / add / new / delete / clone rights on: [form id => [columns]]. */
	public static function adminRightsOf(string $accessId): array
	{
		if ($accessId === '') return [];
		$out = [];
		$rows = nu_db()->all('SELECT slf_zzzzsys_form_id, ' . implode(', ', self::ADMIN_RIGHTS) . ' FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ?', [$accessId]);
		foreach ($rows as $r) {
			$f = (string)$r['slf_zzzzsys_form_id'];
			if (!in_array($f, self::ADMIN_FORMS, true)) continue;
			$cols = array_values(array_filter(self::ADMIN_RIGHTS, static fn($c) => (string)$r[$c] === '1'));
			if ($cols) $out[$f] = $cols;
		}
		return $out;
	}

	/** Restricted actor: not the globeadmin and the setting is on. */
	public static function restricted(): bool
	{
		$u = NuAuth::user();
		return $u && !$u['globeadmin'] && (bool)self::get('rights_globeadmin_only');
	}

	/** Tables behind the admin-equivalent system forms: written through the form runtime only by the globeadmin (when restricted). */
	public const ADMIN_TABLES = ['zzzzsys_user', 'zzzzsys_access', 'zzzzsys_access_form', 'zzzzsys_access_php', 'zzzzsys_access_report', 'zzzzsys_php',
		'zzzzsys_form', 'zzzzsys_object', 'zzzzsys_event', 'zzzzsys_browse', 'zzzzsys_tab', 'zzzzsys_select', 'zzzzsys_select_clause',
		'zzzzsys_setup', 'zzzzsys_config', 'zzzzsys_user_permission'];

	/** A form on one of ADMIN_TABLES (or with such a subform) saved / deleted by a restricted user? */
	public static function runtimeBlocked(NuFormMeta $meta): bool
	{
		if (!self::restricted()) return false;
		if (in_array(strtolower($meta->table()), self::ADMIN_TABLES, true)) return true;
		foreach ($meta->objects as $o) {
			if ($o['sob_all_type'] !== 'subform') continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
			if ($sub && in_array(strtolower($sub->table()), self::ADMIN_TABLES, true)) return true;
		}
		return false;
	}

	// ================================================================= password confirmation (sudo)

	/** Minutes a password confirmation is valid: reauth_minutes, in nuBuilder's developer mode (globeadmin + $nuConfigDevMode) reauth_minutes_dev (0.8.338). */
	public static function reauthMinutes(): int
	{
		return (int)self::get(NuHash::devMode() ? 'reauth_minutes_dev' : 'reauth_minutes');
	}

	/** The password was entered (login or confirmation) less than reauthMinutes() ago. */
	public static function recentAuth(): bool
	{
		$min = self::reauthMinutes();
		if ($min <= 0) return true;
		$at = (int)($_SESSION['nu_auth']['confirmed'] ?? $_SESSION['nu_auth']['since'] ?? 0);
		$via = (string)($_SESSION['nu_auth']['via'] ?? '');
		if ($via !== '' && !isset($_SESSION['nu_auth']['confirmed'])) $at = 0;   // shared login / acting: confirm once
		return time() - $at <= $min * 60;
	}

	public static function confirmed(): void
	{
		if (isset($_SESSION['nu_auth'])) $_SESSION['nu_auth']['confirmed'] = time();
	}

	/**
	 * Dangerous page or action: without a recent password confirmation go to ?p=reauth, then back to $back (GET).
	 * A POST that arrives too late is not carried out (it is sent again from the page after the confirmation).
	 */
	public static function requireRecentAuth(string $back): void
	{
		if (self::recentAuth()) return;
		if (!empty(NuAuth::user()['demo'])) return;            // demo mode refuses these actions anyway
		if (nu_is_post()) nu_flash('warning', t('reauth_needed'));
		$_SESSION['nu_reauth_next'] = NuAuth::validNext($back) ?? nu_url('home');
		nu_redirect(nu_url('reauth'));
	}

	// ================================================================= secrets

	/** Column / setting names that hold passwords, keys or tokens. */
	public static function secretName(string $name): bool
	{
		return preg_match('/pass(word|wd)?\b|password|passwort|secret|token|api_?key|credential|_hash\b|totp/i', $name) === 1
			&& !preg_match('/PasswordMinLength|PasswordUpgrade|TokenValidity|token_type|UseMd5PasswordHash/i', $name);
	}

	/** Values that are password hashes (bcrypt, argon2, crypt). */
	public static function hashValue(mixed $v): bool
	{
		return is_string($v) && preg_match('/^\$(2[aby]|argon2i|argon2id|[156])\$/', $v) === 1;
	}

	/** Demo session: password hashes and values of secret columns are shown as •••. */
	public static function demoMask(string $column, mixed $value): mixed
	{
		if (!NuDemo::active() || $value === null || $value === '') return $value;
		if (self::hashValue($value) || self::secretName($column)) return '•••';
		if (strcasecmp($column, 'sus_json') === 0 && is_string($value)) {      // 0.8.341: login token, 2FA / OTP secrets, auto-login epoch
			$j = json_decode($value, true);
			if (!is_array($j)) return '•••';
			foreach ($j as $k => $v) if (preg_match('/^(LOGIN_TOKEN|2FA_|OTP_|PASSWORD_|NUNEXT_AUTOLOGIN)/i', (string)$k)) $j[$k] = '•••';
			return json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		return $value;
	}

	// ================================================================= folders and files

	public const DENY = "# nuBuilder Next – never served directly\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	/** Creates $dir (0755) with a deny-all .htaccess (when missing) and an empty index.html. */
	public static function protectDir(string $dir): void
	{
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		if (!is_dir($dir)) return;
		$ht = $dir . '/.htaccess';
		if (!is_file($ht)) @file_put_contents($ht, self::DENY);
		if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
	}

	/** Files of the package that must never be served (Admin tools → Security check requests them from the browser). */
	public static function probeUrls(): array
	{
		$urls = ['README.md', 'nunext-manifest.json', 'install/schema_mysql.sql', 'lang/en.php', 'core/nuauth.php', 'docs/toc.json'];
		$bd = NuUpdate::backupDir();
		if (str_starts_with(str_replace('\\', '/', $bd), str_replace('\\', '/', NU_ROOT) . '/')) $urls[] = substr($bd, strlen(NU_ROOT) + 1) . '/index.html';
		if (is_dir(NU_ROOT . '/temp')) $urls[] = 'temp/index.html';
		if (is_file(NU_ROOT . '/_libsrc/index.html')) $urls[] = '_libsrc/index.html';     // library pack sources inside (0.8.412)
		// library packs (0.8.409): vendor/packs/ is closed – its state file and the server-only packs (TCPDF, PHPMailer, Adminer)
		if (is_file(NU_ROOT . '/' . NU_PACKS . '/index.html')) $urls[] = NU_PACKS . '/index.html';
		if (is_file(NU_ROOT . '/' . NuLibs::STATE)) $urls[] = NuLibs::STATE;
		foreach (['tcpdf', 'phpmailer'] as $sl) {
			$d = NuLibs::activeDir($sl);
			if ($d !== null && is_file($d . '/' . NuLibs::MANIFEST) && !NuLibs::isLegacy($sl)) $urls[] = NuLibs::webPath($sl) . '/' . NuLibs::MANIFEST;
		}
		// page forms (0.8.387): custom/ is reachable only through the router (nginx needs its own rule)
		foreach (['custom/PAGE_GUIDE.md', 'custom/pages/_template/index.php', 'custom/pages/_template/assets/app.css', 'apps/nufiles/README.md', 'apps/nufiles/lib/api.php'] as $f) if (is_file(NU_ROOT . '/' . $f)) $urls[] = $f;
		foreach (['PMA'] as $a) {                             // a local version note of a copied tool (0.8.231) may name open CVEs
			$f = NuVendor::dir($a) . '/' . self::LOCAL_VERSION;
			if (is_file($f) && str_starts_with(str_replace('\\', '/', $f), str_replace('\\', '/', NU_ROOT) . '/')) $urls[] = substr($f, strlen(NU_ROOT) + 1);
		}
		// Adminer (0.8.282) must only open through dbadmin.php – served directly it would offer its own login form
		$adm = NuVendor::adminerFile();
		if ($adm !== '' && str_starts_with(str_replace('\\', '/', $adm), str_replace('\\', '/', NU_ROOT) . '/')) $urls[] = substr(str_replace('\\', '/', $adm), strlen(NU_ROOT) + 1);
		return $urls;
	}

	/**
	 * What each probed file really starts with (0.8.503): the Security check counts a file as readable only when the answer contains
	 * this – a host's own page (bot protection, error page) with HTTP 200 is not the file. '' = an empty file (index.html of a closed
	 * folder). @return array<string, string> url => the first 80 characters (line ends as \n)
	 */
	public static function probeMarks(): array
	{
		$out = [];
		foreach (self::probeUrls() as $u) {
			$c = (string)@file_get_contents(NU_ROOT . '/' . $u, false, null, 0, 400);
			$out[$u] = mb_substr(ltrim(str_replace("\r", '', $c)), 0, 80);
		}
		return $out;
	}

	// ================================================================= libraries taken from nuBuilder

	/**
	 * Third-party code nuBuilder Next uses from the nuBuilder installation, a library pack (vendor/packs/) or an old copy: [name, version, path, min,
	 * status ok|old|warn|missing, note key]. Minimum = first version without the publicly known vulnerabilities at the time of this
	 * release (TCPDF CVE-2024-32489 → 6.7.4, PHPMailer CVE-2021-34551 → 6.5.0, phpMyAdmin PMASA-2025-1…3 → 5.2.2, TinyMCE
	 * CVE-2024-38356/38357 → 6.8.4 / 7.2.0). Tiny File Manager was removed in 0.8.479 (File Manager = the page nuFiles).
	 */
	/** Version note of a library you build / patch yourself (0.8.231), in the library's folder – see localVersion(). */
	public const LOCAL_VERSION = 'NUNEXT-VERSION.txt';

	/**
	 * Reads NUNEXT-VERSION.txt of a copied library: "key: value" lines (version, upstream, commit, downloaded, CVE-…: open|fixed …) and
	 * free lists (additions:, fixes:, changes: with "- " lines). @return ?array{version: string, cves: array<string, string>, text: string}
	 */
	/** A CVE line of NUNEXT-VERSION.txt that says it is dealt with: starts with "fixed" or "patched" (0.8.417), any case. */
	public static function cveFixed(string $status): bool
	{
		return (bool)preg_match('/^\s*(fixed|patched)\b/i', $status);
	}

	public static function localVersion(string $dir): ?array
	{
		$f = rtrim($dir, '/\\') . '/' . self::LOCAL_VERSION;
		if ($dir === '' || !is_file($f)) return null;
		$text = trim((string)@file_get_contents($f, false, null, 0, 20000));
		$out = ['version' => '', 'cves' => [], 'text' => $text];
		foreach (preg_split('/\R/', $text) ?: [] as $line) {
			if (!preg_match('/^\s*([A-Za-z][\w.-]*)\s*:\s*(.*)$/', $line, $m)) continue;
			if (strtolower($m[1]) === 'version') $out['version'] = trim($m[2]);
			elseif (preg_match('/^CVE-\d{4}-\d+$/i', $m[1])) $out['cves'][strtoupper($m[1])] = trim($m[2]);
		}
		return $out;
	}

	public static function libraries(): array
	{
		$read = static function (string $file, string $re): string {
			if (!is_file($file)) return '';
			$src = (string)@file_get_contents($file, false, null, 0, 400000);
			return preg_match($re, $src, $m) ? $m[1] : '?';
		};
		$out = [];
		$tc = NuReport::tcpdfDir();
		$v = $tc ? $read($tc . '/tcpdf.php', '/Version\s*:\s*([0-9][0-9.]*)/') : '';
		$out[] = ['TCPDF', $v, $tc ?? '', '6.7.4', ''];
		$pm = '';
		foreach (NuMailer::phpMailerCandidates() as $d) if (is_file("$d/PHPMailer.php")) { $pm = $d; break; }
		$out[] = ['PHPMailer', $pm !== '' ? $read("$pm/PHPMailer.php", "/const VERSION = '([0-9.]+)'/") : '', $pm, '6.5.0', ''];
		$tm = NU_ROOT . '/' . (NuLibs::tinymce() ?: NU_TINYMCE);
		$v = $read($tm . '/tinymce.min.js', '/TinyMCE version ([0-9.]+)/');
		$out[] = ['TinyMCE', $v, is_dir($tm) ? $tm : '', $v !== '' && version_compare($v, '7', '>=') ? '7.2.0' : '6.8.4', ''];
		$pma = NuVendor::dir('PMA');
		$out[] = ['phpMyAdmin', $read($pma . '/libraries/classes/Version.php', "/VERSION = '([0-9.]+)'/"), is_dir($pma) ? $pma : '', '5.2.2', ''];
		$adm = NuVendor::adminerFile();                           // 0.8.282 – 4.x works but is not PHP 8 clean and no longer maintained
		$out[] = ['Adminer', NuVendor::adminerVersion(), $adm !== '' ? dirname($adm) : '', '5.0.0', NuVendor::adminerConverted() ? 'secchk_lib_adminer_crlf' : ''];
		$up = NuLibs::uppy();                                     // 0.8.459: file objects with target "File System" (library pack only)
		$out[] = ['Uppy', $up !== '' ? (NuLibs::uppyVersion(NU_ROOT . '/' . $up . '/uppy.min.js') ?: '?') : '', $up !== '' ? NU_ROOT . '/' . $up : '', '', ''];
		foreach ($out as &$l) {
			[$name, $ver, $path, $min, $note] = $l;
			// own build / patched copy: NUNEXT-VERSION.txt gives the version (e.g. 2.6+git.4149143.nu1) and the state of known CVEs
			$local = $ver !== '' ? self::localVersion($path) : null;
			if ($local && $local['version'] !== '') $ver = $local['version'];
			$base = preg_match('/^[0-9][0-9.]*[0-9]/', $ver, $bm) ? $bm[0] : '?';     // "2.6+git…" is compared as 2.6
			$st = $ver === '' ? 'missing' : ($note !== '' ? 'warn' : ($base === '?' || ($min !== '' && version_compare($base, $min, '<')) ? 'old' : 'ok'));
			$l = ['name' => $name, 'version' => $ver, 'path' => $path, 'min' => $min, 'status' => $st, 'note' => $note, 'local' => $local ? $local['text'] : ''];
		}
		return $out;
	}

	// ================================================================= checks (Admin tools → Security check)

	/** Weak globeadmin password: one of nuBuilder's examples or shorter than 8 characters. */
	public static function weakAdminPassword(): bool
	{
		$p = (string)nu_cfg('nuConfigDBGlobeadminPassword');
		return in_array(strtolower($p), ['nu', 'globeadmin', 'admin', 'password', '1234', '12345678', 'changeme'], true) || strlen($p) < 8;
	}

	/** Users whose password is still a legacy md5 hash. */
	public static function md5Users(): int
	{
		try {
			$n = 0;
			foreach (nu_db()->all('SELECT sus_login_password FROM zzzzsys_user') as $r) if (preg_match('/^[a-f0-9]{32}$/i', trim((string)$r['sus_login_password']))) $n++;
			return $n;
		} catch (Throwable) {
			return 0;
		}
	}

	/** Access levels with admin-equivalent rights: [code => [form ids]]. */
	public static function adminLevels(): array
	{
		$out = [];
		foreach (nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access ORDER BY sal_code') as $a) {
			$r = self::adminRightsOf((string)$a['zzzzsys_access_id']);
			if ($r) $out[(string)$a['sal_code']] = array_keys($r);
		}
		return $out;
	}

	/** PHP2Shell (CVE-2026-109618 / CVE-2026-109619, 21.10.2026): first fixed release per PHP branch; older branches got no official fix. */
	public const PHP_FIXED = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];

	/**
	 * The PHP release with the PHP2Shell fix this server needs: '' when $v has it (or is a newer branch), else the version to update to
	 * (0.8.362). Distributions / hosts can backport the fix without changing the version number – the texts say so.
	 */
	public static function phpFixNeeded(string $v = PHP_VERSION): string
	{
		if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $v, $m)) return '';
		$branch = $m[1] . '.' . $m[2];
		$num = $m[1] . '.' . $m[2] . '.' . $m[3];
		if (isset(self::PHP_FIXED[$branch])) return version_compare($num, self::PHP_FIXED[$branch], '<') ? self::PHP_FIXED[$branch] : '';
		return version_compare($branch, '8.2', '<') ? self::PHP_FIXED['8.2'] : '';
	}

	/** Text for the security check, the self-test and the installer: '' when this PHP has the PHP2Shell fix. */
	public static function phpFixNote(string $v = PHP_VERSION): string
	{
		$need = self::phpFixNeeded($v);
		if ($need === '') return '';
		return version_compare($v, '8.2', '<') ? t('secchk_php2shell_eol', $need) : t('secchk_php2shell', $need);
	}

	/**
	 * Server-side checks: list of ['id', 'status' ok|warn|bad|info, 'title' (text key), 'detail' (text), 'link' (url or '')].
	 * The web access test of package files runs in the browser (assets/js/nusecuritycheck.js, probeUrls()).
	 */
	public static function checks(): array
	{
		$c = [];
		$add = static function (string $id, string $status, string $title, string $detail = '', string $link = '') use (&$c) {
			$c[] = ['id' => $id, 'status' => $status, 'title' => $title, 'detail' => $detail, 'link' => $link];
		};
		$add('https', nu_https() ? 'ok' : 'bad', 'secchk_chk_https', nu_https() ? '' : t('secchk_chk_https_no'));
		$base = trim((string)nu_cfg('nuConfigBaseURL'));
		$seen = self::mailBaseUrl();
		$add('baseurl', $base !== '' ? 'ok' : ($seen !== '' ? 'warn' : 'bad'), 'secchk_chk_baseurl',
			$base !== '' ? $base : ($seen !== '' ? t('secchk_chk_baseurl_seen', $seen) : t('secchk_chk_baseurl_none')), nu_url('configfile'));
		$key = (string)nu_cfg('nuConfigSecretKey') !== '';
		$add('secretkey', $key ? 'ok' : 'bad', 'secchk_chk_key', $key ? '' : t('secchk_chk_key_none'), $key ? '' : nu_url('apiclients'));
		$plain = NuTwoFA::plainCount();
		if ($plain > 0) $add('totp_plain', 'warn', 'secchk_chk_totp_plain', t('secchk_chk_totp_plain_n', $plain), nu_url('apiclients'));
		$add('adminpw', self::weakAdminPassword() ? 'bad' : 'ok', 'secchk_chk_adminpw', self::weakAdminPassword() ? t('secchk_chk_adminpw_weak') : '', nu_url('configfile'));
		$add('admin2fa', nu_cfg('nuConfig2FAAdmin') ? 'ok' : 'warn', 'secchk_chk_admin2fa', nu_cfg('nuConfig2FAAdmin') ? '' : t('secchk_chk_admin2fa_off'), nu_url('configfile'));
		$ipa = NuIpAccess::settings();   // IP access (0.8.722)
		$add('ipaccess', $ipa['on'] ? 'ok' : 'info', 'secchk_chk_ipaccess', $ipa['on'] ? t('secchk_chk_ipaccess_on', count($ipa['levels'])) : t('secchk_chk_ipaccess_off'), nu_url('ipaccess'));
		$add('debug', nu_cfg('nuConfigDebug') ? 'bad' : 'ok', 'secchk_chk_debug', nu_cfg('nuConfigDebug') ? t('secchk_chk_debug_on') : '');
		// $nuConfigIncludeJS / CSS / PHP (0.8.443): files that are not found are left out – say which
		if (NuIncludes::entries('js') || NuIncludes::entries('css') || NuIncludes::entries('php')) {
			$miss = NuIncludes::missing();
			$add('includes', $miss ? 'warn' : 'ok', 'secchk_chk_includes',
				$miss ? t('secchk_chk_includes_missing', implode(' · ', array_map(static fn($m) => $m['var'] . ': ' . $m['file'], $miss))) : '', nu_url('configfile'));
		}
		// admin roles (0.8.323): who has a part of the globeadmin's rights – roles with "full access" rights are named
		try {
			$adm = [];
			$full = false;
			foreach (NuAdmin::roles() as $r) {
				if (!$r['users']) continue;
				$f = NuAdmin::hasFull($r['rights']);
				$full = $full || $f;
				foreach (NuAdmin::usersOf($r['id']) as $au) $adm[] = $au['login'] . ' – ' . $r['name'] . ($f ? ' (' . t('adm_full') . ')' : '');
			}
			$add('adminroles', !$adm ? 'ok' : ($full ? 'warn' : 'ok'), 'secchk_chk_adminroles', $adm ? implode(' · ', $adm) : t('secchk_chk_adminroles_none'), nu_url('adminroles'));
		} catch (Throwable) { /* no tables yet */ }
		$de = filter_var(nu_ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || in_array(strtolower((string)nu_ini_get('display_errors')), ['stdout', 'stderr'], true);
		$add('display_errors', $de ? 'warn' : 'ok', 'secchk_chk_display_errors', $de ? t('secchk_chk_display_errors_on') : '');
		$fix = self::phpFixNote();                         // 0.8.362: PHP2Shell – a version without the fix is a red point
		$add('php', $fix !== '' ? 'bad' : (PHP_VERSION_ID >= 80200 ? 'ok' : 'warn'), 'secchk_chk_php',
			PHP_VERSION . (PHP_VERSION_ID < 80200 && $fix === '' ? ' – ' . t('secchk_php_old') : '') . ($fix !== '' ? ' – ' . $fix : ''));
		// updates (0.8.645): packages are zips with an Ed25519 signature – without zip nothing can be read, without sodium the signature is not checked
		$zip = class_exists('ZipArchive'); $sod = function_exists('sodium_crypto_sign_verify_detached');
		$add('php_update_ext', $zip && $sod ? 'ok' : ($zip ? 'warn' : 'bad'), 'secchk_chk_update_ext', $zip && $sod ? 'zip · sodium' : implode(' ', array_filter([$zip ? '' : t('secchk_chk_nozip'), $sod ? '' : t('secchk_chk_nosodium')])));
		if (nu_cfg('nuConfigApiAllowHttp')) $add('api_http', 'bad', 'secchk_chk_api_http', t('secchk_chk_api_http_on'));
		if (NuDemo::configured()) $add('demo', 'warn', 'secchk_chk_demo', t('secchk_chk_demo_on'), nu_url('vendor') . '#demomode');
		if (nu_cfg('nuConfigAutoLogin')) $add('autologin', 'info', 'secchk_chk_autologin', t('secchk_chk_autologin_on'));
		if (NuInstall::existingWarn()) $add('existingdb', 'warn', 'existing_db_title', t('existing_db_warn'));   // 0.8.380
		if (NuHomeHook::active()) $add('homehook', 'info', 'secchk_chk_homehook', t('secchk_chk_homehook_on'), nu_url('procedures', ['id' => (string)(NuHomeHook::procedure()['id'] ?? '')]));   // 0.8.378
		if (!empty(nu_cfg('nuConfigDevMode'))) {             // 0.8.338: developer mode relaxes the password confirmation of the globeadmin
			$dm = (int)self::get('reauth_minutes_dev');
			$add('devmode', 'info', 'secchk_chk_devmode', $dm > 0 ? t('secchk_chk_devmode_on', $dm) : t('secchk_chk_devmode_never'), nu_url('securitycheck') . '#sec_reauth_minutes_dev');
		}
		if (NuLoginToken::enabled()) {                        // nuBuilder's single-use login tokens (0.8.339)
			$lt = count(NuLoginToken::holders());
			$add('logintoken', $lt > 0 ? 'info' : 'ok', 'secchk_chk_logintoken', $lt > 0 ? t('secchk_chk_logintoken_n', $lt) : t('secchk_chk_logintoken_none'));
		} else {
			$add('logintoken', 'ok', 'secchk_chk_logintoken', t('secchk_chk_logintoken_off'));
		}
		$md5 = self::md5Users();
		if (NuAuth::md5Storage()) {                            // nuBuilder's $nuUseMd5PasswordHash = true: new passwords are md5 too (0.8.336)
			$add('md5store', 'bad', 'secchk_chk_md5store', t('secchk_chk_md5store_on'), nu_cfg('nuConfigSettingsFromDB') ? nu_url('settings', ['q' => 'nuUseMd5PasswordHash']) : nu_url('configfile'));
			$add('md5', $md5 === 0 ? 'ok' : 'info', 'secchk_chk_md5', $md5 === 0 ? '' : t('secchk_chk_md5_kept', $md5));
		} else {
			$add('md5', $md5 === 0 ? 'ok' : (self::get('md5_login') ? 'warn' : 'info'), 'secchk_chk_md5', $md5 === 0 ? '' : t('secchk_chk_md5_n', $md5));
		}
		// 0.8.478 (security assessment C2 / K1 / C1): row access, field protection, the upload template's folder
		$rc = (bool)self::get('record_check');
		$add('recordcheck', $rc ? 'ok' : 'warn', 'secchk_chk_recordcheck', $rc ? '' : t('secchk_chk_recordcheck_off'), $rc ? '' : nu_url('securitycheck') . '#sec_record_check');
		$pf = (bool)self::get('protect_fields');
		$add('protectfields', $pf ? 'ok' : 'warn', 'secchk_chk_protectfields', $pf ? '' : t('secchk_chk_protectfields_off'), $pf ? '' : nu_url('securitycheck') . '#sec_protect_fields');
		$web = NuUpload::procedureInWebRoot();
		if ($web !== null) {
			$pid = (string)(nu_db()->value('SELECT zzzzsys_php_id FROM zzzzsys_php WHERE sph_code = ?', [NuUpload::PROCEDURE_CODE]) ?? '');
			$add('uploadtpl', $web ? 'bad' : 'ok', 'secchk_chk_uploadtpl', $web ? t('secchk_chk_uploadtpl_web') : '', $web ? nu_url('procedures', ['id' => $pid]) : '');
		}
		// field data access (0.8.480): forms with rules – the values of withheld / masked fields still reach nuBuilder 4.9 on the same database
		$fa = NuFieldAccess::counts();
		if ($fa) $add('fieldaccess', 'info', 'secchk_chk_fieldaccess', t('secchk_chk_fieldaccess_n', array_sum($fa), count($fa)));
		// 0.8.479: Tiny File Manager is gone – a copy the update could not delete is still reachable on the web
		$tfmLeft = array_values(array_filter([NU_ROOT . '/third_party/tinyfilemanager', ...(glob(NU_ROOT . '/' . NU_PACKS . '/tinyfilemanager-*', GLOB_ONLYDIR) ?: [])], 'is_dir'));
		if ($tfmLeft) $add('tfm_left', 'bad', 'secchk_chk_tfm_left', t('secchk_chk_tfm_left_n', implode(' · ', array_map(static fn($d) => substr(str_replace('\\', '/', $d), strlen(NU_ROOT) + 1), $tfmLeft))));
		$levels = self::adminLevels();
		$add('adminlevels', $levels ? 'info' : 'ok', 'secchk_chk_adminlevels',
			$levels ? implode(' · ', array_map(static fn($code, $forms) => $code . ' (' . implode(', ', $forms) . ')', array_keys($levels), $levels)) : '', nu_url('access'));
		try {
			$unq = count(NuCompat::unquotedHash());
			$add('hashvars', $unq ? 'warn' : 'ok', 'secchk_chk_hashvars', $unq ? t('secchk_chk_hashvars_n', $unq) : '', nu_url('compat') . '#compat_hash');
		} catch (Throwable $e) {
			error_log('nuBuilder Next security check: ' . $e->getMessage());
		}
		$hk = NuHousekeeping::last();
		$cronOk = $hk && $hk['source'] === 'cron' && $hk['time'] > time() - 2 * 86400;
		$add('cron', $cronOk ? 'ok' : 'info', 'secchk_chk_cron', $hk ? t('secchk_chk_cron_last', date('d.m.Y H:i', $hk['time']), $hk['source']) : t('secchk_chk_cron_never'));
		$days = (int)self::get('log_keep_days');
		$add('logkeep', $days > 0 ? 'ok' : 'info', 'secchk_chk_logkeep', $days > 0 ? t('secchk_chk_logkeep_days', $days) : t('secchk_chk_logkeep_forever'));
		$bp = trim((string)nu_cfg('nuConfigBackupPath'));
		$add('backuppath', $bp !== '' ? 'ok' : 'info', 'secchk_chk_backuppath', $bp !== '' ? $bp : t('secchk_chk_backuppath_root'));
		foreach (self::libraries() as $l) {
			if ($l['status'] === 'missing') continue;
			$st = ['ok' => 'ok', 'old' => 'bad', 'warn' => 'warn'][$l['status']];
			$det = $l['version'] . ($l['min'] !== '' && $l['status'] === 'old' ? ' – ' . t('secchk_lib_min', $l['min']) : '') . ($l['note'] !== '' ? ' – ' . t($l['note']) : '');
			$add('lib_' . strtolower(str_replace(' ', '', $l['name'])), $st, 'secchk_chk_lib', $l['name'] . ' ' . $det);
		}
		return $c;
	}

	// ================================================================= uploads

	/**
	 * Extensions a web server may run or obey (0.8.459, upload route ?p=upload): checked on every dot part after the name, because
	 * Apache with "AddHandler" runs report.php.pdf too. Names starting with a dot (.htaccess, .user.ini) are refused as well.
	 */
	public const UPLOAD_DANGEROUS = ['php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'pht', 'phtm', 'phtml', 'phar', 'inc',
		'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'ashx', 'asmx', 'cer', 'jsp', 'jspx', 'shtml', 'shtm', 'stm', 'htaccess', 'htpasswd', 'ini', 'exe', 'com', 'bat', 'cmd', 'dll'];

	/** Would a web server possibly run this uploaded file name (or treat it as its configuration)? */
	public static function dangerousUploadName(string $name): bool
	{
		$base = strtolower(trim(basename(str_replace('\\', '/', $name))));
		if ($base === '' || str_starts_with($base, '.') || str_contains($base, "\0") || preg_match('/[\x00-\x1f]/', $base)) return true;
		$parts = explode('.', rtrim($base, '. '));
		array_shift($parts);
		foreach ($parts as $p) if (in_array(trim($p), self::UPLOAD_DANGEROUS, true)) return true;
		return false;
	}

	// ================================================================= base URL for e-mail links

	/**
	 * URL for links in e-mails that anybody can trigger (forgot password): $nuConfigBaseURL, otherwise the URL the globeadmin last
	 * used (remembered while logged in) – never the Host header of an anonymous request. '' = unknown (no link is sent).
	 */
	public static function mailBaseUrl(): string
	{
		$cfg = trim((string)nu_cfg('nuConfigBaseURL'));
		if ($cfg !== '') return rtrim($cfg, '/');
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', ['base_url_seen']);
			return is_string($v) && preg_match('~^https?://[^\s/]+(/[^\s]*)?$~', $v) ? rtrim($v, '/') : '';
		} catch (Throwable) {
			return '';
		}
	}

	/** Remembers the URL of this installation from a globeadmin request (once per session). */
	public static function rememberBaseUrl(): void
	{
		if (!empty($_SESSION['nu_base_seen']) || trim((string)nu_cfg('nuConfigBaseURL')) !== '') return;
		$_SESSION['nu_base_seen'] = 1;
		$host = (string)($_SERVER['HTTP_HOST'] ?? '');
		if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$|^\[[0-9a-fA-F:]+\](:\d+)?$/', $host)) return;
		$url = (nu_https() ? 'https' : 'http') . '://' . $host . rtrim(self::cookiePath(), '/');
		try {
			NuSchema::ensure();
			$db = nu_db();
			$cur = $db->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', ['base_url_seen']);
			$row = ['setting_value' => $url, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => 'globeadmin'];
			if ($cur === null) $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => 'base_url_seen'] + $row);
			elseif ($cur !== $url) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', 'base_url_seen');
		} catch (Throwable) {
		}
	}
}
