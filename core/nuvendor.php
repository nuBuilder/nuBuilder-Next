<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * nuBuilder's admin tools for the globeadmin: phpMyAdmin ("nudb") and Tiny File Manager. Both are NOT shipped
 * with nuBuilder Next; copy them from nuBuilder:
 *   <nuBuilder>/third_party/nudb/              → <nuBuilder Next>/third_party/nudb/
 *   <nuBuilder>/third_party/tinyfilemanager/   → <nuBuilder Next>/third_party/tinyfilemanager/
 * Their nuBuilder config files (nudb/config.inc.php, tinyfilemanager/config.php) include "../../nuconfig.php",
 * which is nuBuilder Next's nuconfig.php at that location (same variables as nuBuilder), and accept a request when the
 * cookie nu_PMA / nu_TFM holds the id of a zzzzsys_session row whose sss_access has session.global_access = 1.
 *
 * Launch (same as nuBuilder's core/nuvendorlogin.php): nuBuilder Next writes such a row for the current nuBuilder Next session,
 * sets the cookie (HttpOnly, SameSite=Strict, only for the tool's folder – 0.8.264) and redirects. The row lives as long as the nuBuilder Next session: sss_time is
 * refreshed with the session activity, the row is removed at logout, when the globeadmin ends the session, or
 * after the session timeout.
 *
 * Other location (e.g. the tools inside nuBuilder): $nuConfigPMAURL / $nuConfigTFMURL in nuconfig.php.
 *
 * Adminer (0.8.282, app 'ADM', NOT shipped): copy adminer.php (or adminer-<version>.php, 4.8.1 or newer) to
 * <nuBuilder Next>/third_party/adminer/. It is never opened directly (that folder gets a deny-all .htaccess) but through
 * nuBuilder Next's dbadmin.php, which checks the globeadmin session and logs Adminer in with the database account of
 * nuconfig.php (MySQL / MariaDB and SQL Server). $nuConfigPrimaryDatabaseManagement = 'phpMyAdmin' | 'Adminer' chooses the tool
 * the database buttons open (app 'DB'); not set = phpMyAdmin when it is there, else Adminer. With both, ▾ offers the other one.
 */
final class NuVendor
{
	public const APPS = [
		'PMA' => ['dir' => 'nudb', 'entry' => 'index.php', 'cfg' => 'nuConfigPMAURL'],
		'TFM' => ['dir' => 'tinyfilemanager', 'entry' => 'tinyfilemanager.php', 'cfg' => 'nuConfigTFMURL'],
		'ADM' => ['dir' => 'adminer', 'entry' => 'adminer.php', 'cfg' => ''],
	];
	/** Database tools: app => product name (labels, $nuConfigPrimaryDatabaseManagement). */
	public const DB_TOOLS = ['PMA' => 'phpMyAdmin', 'ADM' => 'Adminer'];
	public const MARK = 'nunext_vendor';   // sss_access.session.nunext_vendor = nuBuilder Next session key

	/** Folder of the tool inside nuBuilder Next (default location). */
	public static function dir(string $app): string
	{
		return NU_ROOT . '/third_party/' . self::APPS[$app]['dir'];
	}

	/** Base URL of the tool with trailing slash: the configured URL, or "third_party/<dir>/" relative to nuBuilder Next. */
	public static function baseUrl(string $app): string
	{
		$cfg = trim((string)nu_cfg(self::APPS[$app]['cfg']));
		return $cfg !== '' ? rtrim($cfg, '/') . '/' : 'third_party/' . self::APPS[$app]['dir'] . '/';
	}

	/**
	 * URL path the tool's cookies are limited to: its folder below nuBuilder Next, or the path of $nuConfigPMAURL / $nuConfigTFMURL
	 * (an own URL on another host gets "/" – the cookie could not reach it anyway).
	 */
	public static function cookiePath(string $app): string
	{
		$base = self::baseUrl($app);
		if (preg_match('~^https?://~i', $base)) {
			$host = strtolower((string)parse_url($base, PHP_URL_HOST));
			$here = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
			$path = (string)(parse_url($base, PHP_URL_PATH) ?? '/');
			return $host === $here && str_starts_with($path, '/') ? rtrim($path, '/') . '/' : '/';
		}
		if (str_starts_with($base, '/')) return rtrim($base, '/') . '/';
		return NuSecurity::cookiePath() . ltrim($base, '/');           // relative: below this nuBuilder Next folder
	}

	/** Adminer's file in third_party/adminer/: adminer.php, else the newest adminer-<version>*.php; '' = not there. */
	public static function adminerFile(): string
	{
		static $f = null;
		if ($f !== null) return $f;
		$dir = self::dir('ADM');
		if (is_file($dir . '/adminer.php')) return $f = $dir . '/adminer.php';
		$best = ''; $bestV = '';
		foreach (glob($dir . '/adminer-*.php') ?: [] as $c) {
			$v = preg_match('/adminer-([0-9][0-9.]*[0-9])/', basename($c), $m) ? $m[1] : '0';
			if ($best === '' || version_compare($v, $bestV, '>')) { $best = $c; $bestV = $v; }
		}
		return $f = $best;
	}

	/** Adminer's file has Windows line endings (copied in text mode) – dbadmin.php then uses a repaired copy (0.8.287). */
	public static function adminerConverted(): bool
	{
		$f = self::adminerFile();
		$h = $f !== '' ? @fopen($f, 'rb') : false;
		if (!$h) return false;
		$head = (string)fread($h, 8192);
		fclose($h);
		return str_contains($head, "\r");
	}

	/** Adminer's version from its file header ("@version 4.8.1"); '' = unknown. */
	public static function adminerVersion(): string
	{
		$f = self::adminerFile();
		if ($f === '') return '';
		$head = (string)@file_get_contents($f, false, null, 0, 2000);
		return preg_match('/@version\s+([0-9][0-9.]*)/', $head, $m) ? $m[1] : '';
	}

	/** Database tools that can be opened, the primary one first ($nuConfigPrimaryDatabaseManagement). @return string[] apps */
	public static function dbTools(): array
	{
		$have = array_values(array_filter(array_keys(self::DB_TOOLS), static fn($a) => self::available($a)));
		$want = strtolower(trim((string)nu_cfg('nuConfigPrimaryDatabaseManagement')));
		foreach ($have as $i => $a) {
			if (strtolower(self::DB_TOOLS[$a]) === $want) { array_splice($have, $i, 1); array_unshift($have, $a); break; }
		}
		return $have;
	}

	/**
	 * Makes $app ('PMA' | 'ADM') the primary database tool (0.8.284, star in the ▾ menus): $nuConfigPrimaryDatabaseManagement in the
	 * settings table (row added when missing) and – unless the settings come from the table – in nuconfig.php. @return string error ('' = ok)
	 */
	public static function setPrimaryDb(string $app): string
	{
		if (!isset(self::DB_TOOLS[$app])) return t('vendor_failed');
		$name = self::DB_TOOLS[$app];
		if (NuSettings::available()) {
			NuSettings::addOwn();
			nu_db()->run('UPDATE ' . NuSettings::TABLE . ' SET cfg_value = ? WHERE cfg_setting = ?', [$name, '$nuConfigPrimaryDatabaseManagement']);
		}
		if (!NuSettings::fromDb()) {
			$err = NuConfigFile::setVariable('nuConfigPrimaryDatabaseManagement', '"' . $name . '"');
			if ($err !== '') return $err;
		}
		NuLog::write('vendor_db_primary', 'nuconfig.php', '', ['nuConfigPrimaryDatabaseManagement' => $name]);
		return '';
	}

	/**
	 * Entries of the ▾ menus of the Database tile / card (0.8.284): each tool opens in a new tab; the star makes it the default
	 * (filled = the current one). $back: page to return to after the star ('home' | 'vendor').
	 */
	public static function dbMenuItems(string $back): string
	{
		$tools = self::dbTools();
		$out = '';
		foreach ($tools as $i => $a) {
			$label = t('vendor_db_with', self::DB_TOOLS[$a]);
			$out .= '<li class="d-flex align-items-center nuDbItem"><form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="flex-grow-1">' . nu_csrf_field()
				. '<input type="hidden" name="app" value="' . $a . '"><button type="submit" class="dropdown-item"><i class="bi bi-database me-2 text-body-secondary"></i>' . h($label) . '</button></form>';
			if ($i === 0) {
				$out .= '<span class="nuDbStar nuDbStarOn" title="' . h(t('vendor_db_is_default')) . '" aria-label="' . h(t('vendor_db_is_default')) . '" role="img"><i class="bi bi-star-fill"></i></span>';
			} else {
				$tip = t('vendor_db_make_default', self::DB_TOOLS[$a]);
				$out .= '<form method="post" action="' . h(nu_url('vendor')) . '" class="m-0">' . nu_csrf_field() . '<input type="hidden" name="action" value="db_primary">'
					. '<input type="hidden" name="app" value="' . $a . '"><input type="hidden" name="back" value="' . h($back) . '">'
					. '<button type="submit" class="nuDbStar" title="' . h($tip) . '" aria-label="' . h($tip) . '"><i class="bi bi-star"></i></button></form>';
			}
			$out .= '</li>';
		}
		return $out;
	}

	/** The app the database buttons open ('' = none). */
	public static function primaryDb(): string
	{
		return self::dbTools()[0] ?? '';
	}

	/** Usable: MySQL database (Adminer: also SQL Server), and the tool is copied (or a URL is configured). */
	public static function available(string $app): bool
	{
		if ($app === 'ADM') return in_array(nu_db()->type, ['mysql', 'sqlsrv'], true) && self::adminerFile() !== '';
		if (!isset(self::APPS[$app]) || nu_db()->type !== 'mysql') return false;
		if (trim((string)nu_cfg(self::APPS[$app]['cfg'])) !== '') return true;
		return is_file(self::dir($app) . '/' . self::APPS[$app]['entry']);
	}

	/** URL of the tool after login: phpMyAdmin opens the database structure (or a table), TFM its start page. */
	public static function target(string $app, string $table = ''): string
	{
		$base = self::baseUrl($app);
		if ($app === 'TFM') return $base . self::APPS[$app]['entry'];
		$db = (string)nu_cfg('nuConfigDBName');
		$q = $table !== '' && preg_match('/^[A-Za-z0-9_$]+$/', $table)
			? ['route' => '/sql', 'pos' => 0, 'db' => $db, 'table' => $table]
			: ['route' => '/database/structure', 'server' => 1, 'db' => $db];
		return $base . 'index.php?' . http_build_query($q + [(string)time() => time()]);
	}

	/** Creates / refreshes the zzzzsys_session row of this nuBuilder Next session and sets the cookie. Returns the redirect URL. */
	public static function launch(string $app, string $table = ''): string
	{
		if ($app === 'DB') $app = self::primaryDb();
		if ($app === 'ADM') {
			// Adminer: through dbadmin.php (checks the row + cookie like nuBuilder's configs of phpMyAdmin / TFM)
			NuSecurity::protectDir(self::dir('ADM'));                  // the copied adminer.php is never opened directly
			$sid = self::ensureRow();
			setcookie('nu_ADM', $sid, ['path' => NuSecurity::cookiePath() . 'dbadmin.php', 'httponly' => true, 'samesite' => 'Strict'] + (nu_https() ? ['secure' => true] : []));
			NuLog::write('vendor_open', 'zzzzsys_session', '', ['app' => 'ADM']);
			return 'dbadmin.php' . ($table !== '' && preg_match('/^[A-Za-z0-9_$]+$/', $table) ? '?nu_table=' . rawurlencode($table) : '');
		}
		$sid = self::ensureRow();
		$https = nu_https() ? ['secure' => true] : [];
		// 0.8.264: only for the tool's folder (other apps on the domain never get it) and SameSite=Strict (always opened from here)
		$opt = ['path' => self::cookiePath($app), 'httponly' => true, 'samesite' => 'Strict'] + $https;
		setcookie('nu_' . $app, $sid, $opt);
		setcookie('nu_timezone', date_default_timezone_get(), $opt);
		NuLog::write('vendor_open', 'zzzzsys_session', '', ['app' => $app]);
		return self::target($app, $table);
	}

	/** One row per nuBuilder Next session (shared by both tools). */
	private static function ensureRow(): string
	{
		$db = nu_db();
		self::ensureTable();
		self::purge();
		$key = NuSessions::key();
		$sid = (string)($_SESSION['nu_vendor'] ?? '');
		if (!preg_match('/^nx[0-9a-f]{23}$/', $sid)) $sid = 'nx' . substr(bin2hex(random_bytes(12)), 0, 23);   // varchar(25)
		$u = NuAuth::user();
		$access = json_encode(['session' => [
			'zzzzsys_user_id' => 'globeadmin', 'global_access' => '1', 'user_name' => (string)$u['login'],
			'ip_address' => nu_client_ip(), self::MARK => $key,
		]], JSON_UNESCAPED_UNICODE);
		$db->delete('zzzzsys_session', 'zzzzsys_session_id', $sid);
		$db->insert('zzzzsys_session', ['zzzzsys_session_id' => $sid, 'sss_access' => $access, 'sss_time' => time()]);
		$_SESSION['nu_vendor'] = $sid;
		return $sid;
	}

	/** zzzzsys_session normally exists (nuBuilder table); created with nuBuilder's columns if not. */
	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists('zzzzsys_session')) return;
		if ($db->type === 'sqlsrv') {                          // Adminer on SQL Server (0.8.282)
			$db->run('CREATE TABLE zzzzsys_session (zzzzsys_session_id NVARCHAR(25) NOT NULL PRIMARY KEY, sss_access NVARCHAR(MAX) NULL, sss_hashcookies NVARCHAR(MAX) NULL,'
				. ' sss_time INT NULL, sss_login_time DATETIME2 NULL DEFAULT SYSDATETIME(), sss_json NVARCHAR(MAX) NULL)');
			return;
		}
		$db->run('CREATE TABLE zzzzsys_session (zzzzsys_session_id varchar(25) NOT NULL DEFAULT \'\', sss_access mediumtext, sss_hashcookies mediumtext,'
			. ' sss_time int DEFAULT NULL, sss_login_time timestamp NULL DEFAULT CURRENT_TIMESTAMP, sss_json mediumtext, PRIMARY KEY (zzzzsys_session_id)) '
			. NuDDL::mysqlTableOptions());
	}

	private static function like(string $key = ''): string
	{
		return '%"' . self::MARK . '":"' . ($key === '' ? '' : $key . '"') . '%';
	}

	/** Called with the session activity (NuSessions::touch): keeps the row alive. */
	public static function touch(): void
	{
		$sid = (string)($_SESSION['nu_vendor'] ?? '');
		if ($sid === '') return;
		try {
			nu_db()->run('UPDATE zzzzsys_session SET sss_time = ? WHERE zzzzsys_session_id = ?', [time(), $sid]);
		} catch (Throwable) {
		}
	}

	/** Removes the row(s) of a nuBuilder Next session (logout / ended by the globeadmin). */
	public static function forget(string $key): void
	{
		if ($key === '') return;
		try {
			if (nu_db()->tableExists('zzzzsys_session')) nu_db()->run('DELETE FROM zzzzsys_session WHERE sss_access LIKE ?', [self::like($key)]);
		} catch (Throwable) {
		}
	}

	/** Rows of nuBuilder Next sessions without activity for longer than the session timeout. */
	public static function purge(): void
	{
		try {
			if (!nu_db()->tableExists('zzzzsys_session')) return;
			$limit = nu_session_timeout(true);   // tool logins belong to the globeadmin
			nu_db()->run('DELETE FROM zzzzsys_session WHERE sss_access LIKE ? AND (sss_time IS NULL OR sss_time < ?)', [self::like(), time() - $limit]);
		} catch (Throwable) {
		}
	}

	public static function isVendorRow(string $access): bool
	{
		return str_contains($access, '"' . self::MARK . '"');
	}
}
