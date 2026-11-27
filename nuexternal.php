<?php
declare(strict_types=1);

/**
 * nuBuilder Next – who is signed in, for your own PHP apps.
 *
 * For PHP files that are not part of nuBuilder Next (no custom page) but should use its sign-in:
 *
 *     require __DIR__. '/../../nuexternal.php';          // the path to this file in nuBuilder Next's folder
 *     $me = NuNextUser::require();                        // signed in – else to the sign-in page and back here
 *     if (!NuNextUser::hasPermission('my_tool') && !NuNextUser::inTeam('Sales')) NuNextUser::deny();
 *     $pdo = NuNextUser::pdo();                           // the database of nuBuilder Next (optional)
 *
 * NuNextUser::current() → null or [id, login, name, first_name, last_name, email, team, department, position, code, language,
 *   globeadmin, access_id, access_code, access_levels (main + active additional ones: [{id, code, description}]), permissions].
 * Checks: hasPermission(), inTeam(), inDepartment(), hasPosition(), hasAccessLevel(), canOpenForm(), isGlobeadmin().
 *
 * Rules:
 * - The app's files must be in a sub-folder of nuBuilder Next's folder (not custom/): the browser sends nuBuilder Next's
 *   sign-in cookie only for that folder.
 * - This file only reads. It never starts, changes or ends a session (the app keeps its own session_start() if it has one),
 *   defines nothing but the class NuNextUser, and sends headers only in require() and deny().
 * - Signed in = the session row of nuBuilder Next exists (sign-out, "Log out" of the globeadmin and the housekeeping remove it)
 *   and was active within the session timeout. Check on every request – do not keep "authenticated" in your own session.
 * - Not applied here: IP access rules and the field / form rights of nuBuilder Next pages. Check what your app needs.
 * The class is a stable contract: methods are only added, never changed or removed (VERSION).
 */

if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {   // never a page of its own
	http_response_code(404);
	exit;
}
if (class_exists('NuNextUser', false)) return;

final class NuNextUser
{
	public const VERSION = 1;
	/** Folders of nuBuilder Next itself – an app is never one of them. */
	private const OWN_DIRS = ['core', 'pages', 'lang', 'assets', 'vendor', 'install', 'docs', 'custom', 'dev', 'apps', 'temp', '_backup', '_update', '_install', '_libsrc'];

	private static ?PDO $pdo = null;
	private static ?array $cfg = null;
	/** @var array<string, mixed>|null|false false = not read yet */
	private static array|null|false $user = false;

	/** The signed-in user of nuBuilder Next, or null. */
	public static function current(): ?array
	{
		if (self::$user !== false) return self::$user;
		return self::$user = self::read();
	}

	/** The signed-in user; without one: to nuBuilder Next's sign-in page, which returns to this page afterwards. */
	public static function require(): array
	{
		$u = self::current();
		if ($u !== null) return $u;
		if (!headers_sent()) {
			header('Cache-Control: no-store');
			header('Location: ' . self::loginUrl(), true, 302);
		}
		exit;
	}

	/** 403 with a short page (or JSON for fetch requests) and end. */
	public static function deny(string $message = 'Access denied.'): never
	{
		if (!headers_sent()) {
			http_response_code(403);
			header('Cache-Control: no-store');
		}
		$json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
		if ($json) {
			if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['ok' => false, 'error' => $message]);
		} else {
			if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
			echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>403</title></head>'
				. '<body style="font-family:system-ui,sans-serif;padding:3rem 1rem;text-align:center;color:#333"><p>'
				. htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="' . htmlspecialchars(self::homeUrl(), ENT_QUOTES, 'UTF-8') . '">nuBuilder Next</a></p></body></html>';
		}
		exit;
	}

	public static function isGlobeadmin(): bool
	{
		return !empty(self::current()['globeadmin']);
	}

	/** Holds ANY of these permission items (pme_ident, case-insensitive; the globeadmin: always). */
	public static function hasPermission(string ...$idents): bool
	{
		$u = self::current();
		if ($u === null) return false;
		if ($u['globeadmin']) return true;
		$mine = array_map('strtolower', $u['permissions']);
		foreach ($idents as $i) if (in_array(strtolower(trim($i)), $mine, true)) return true;
		return false;
	}

	public static function inTeam(string ...$names): bool { return self::matches('team', $names); }

	public static function inDepartment(string ...$names): bool { return self::matches('department', $names); }

	public static function hasPosition(string ...$names): bool { return self::matches('position', $names); }

	/** Main or an active additional access level has ANY of these codes (sal_code, case-insensitive; the globeadmin: always). */
	public static function hasAccessLevel(string ...$codes): bool
	{
		$u = self::current();
		if ($u === null) return false;
		if ($u['globeadmin']) return true;
		$want = array_map(static fn($c) => mb_strtolower(trim($c)), $codes);
		foreach ($u['access_levels'] as $l) if (in_array(mb_strtolower($l['code']), $want, true)) return true;
		return false;
	}

	/** One of the user's access levels may open this form (form id or code; the globeadmin: always). */
	public static function canOpenForm(string $form): bool
	{
		$u = self::current();
		if ($u === null) return false;
		if ($u['globeadmin']) return true;
		$ids = array_column($u['access_levels'], 'id');
		if (!$ids) return false;
		try {
			$id = (string)(self::value('SELECT zzzzsys_form_id FROM zzzzsys_form WHERE zzzzsys_form_id = ? OR sfo_code = ?', [$form, $form]) ?? '');
			if ($id === '') return false;
			$in = implode(', ', array_fill(0, count($ids), '?'));
			return (int)self::value('SELECT COUNT(*) FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id = ? AND slf_zzzzsys_access_id IN (' . $in . ')', array_merge([$id], $ids)) > 0;
		} catch (Throwable) {
			return false;
		}
	}

	/** The database connection of nuBuilder Next (nuconfig.php of its folder). */
	public static function pdo(): PDO
	{
		if (self::$pdo !== null) return self::$pdo;
		$c = self::config();
		$type = (string)$c['nuConfigDBType'];
		$port = trim((string)$c['nuConfigDBPort']);
		$host = (string)($c['nuConfigDBHost'] ?? '127.0.0.1');
		$name = (string)($c['nuConfigDBName'] ?? '');
		if ($type === 'sqlite') {                               // one database file: $nuConfigDBName is its path
			$p = new PDO('sqlite:' . $name, null, null, (array)$c['nuConfigDBOptions'] + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
			$p->exec('PRAGMA busy_timeout = 15000');
			return self::$pdo = $p;
		}
		if ($type === 'sqlsrv') {
			$dsn = 'sqlsrv:Server=' . $host . ($port !== '' ? ',' . (int)$port : '') . ';Database=' . $name;
			if (!empty($c['nuConfigDBTrustServerCertificate'])) $dsn .= ';TrustServerCertificate=1';
		} else {
			$dsn = 'mysql:host=' . $host . ';dbname=' . $name . ($port !== '' ? ';port=' . (int)$port : '');
			$cs = preg_replace('/[^A-Za-z0-9_]/', '', (string)$c['nuConfigDBCharacterSet']);
			if ($cs !== '') $dsn .= ';charset=' . $cs;
		}
		$opt = (array)$c['nuConfigDBOptions'] + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
		if ($type !== 'sqlsrv') $opt += [PDO::ATTR_EMULATE_PREPARES => false];
		return self::$pdo = new PDO($dsn, (string)($c['nuConfigDBUser'] ?? ''), (string)($c['nuConfigDBPassword'] ?? ''), $opt);
	}

	/** nuBuilder Next's sign-in page; afterwards back to $back (default: this page – only pages inside nuBuilder Next's folder). */
	public static function loginUrl(?string $back = null): string
	{
		$next = $back ?? self::here();
		return self::homeUrl() . 'index.php?p=login' . ($next !== null && $next !== '' ? '&next=' . rawurlencode($next) : '');
	}

	/** URL path of nuBuilder Next's folder (with a slash at the end), worked out from the running script's place in it. */
	public static function homeUrl(): string
	{
		$rel = self::relScript();
		$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
		if ($rel !== null && $script !== '' && str_ends_with($script, '/' . $rel)) return substr($script, 0, -strlen($rel));
		return '/';
	}

	// ------------------------------------------------------------------ internals

	private static function read(): ?array
	{
		$sid = $_COOKIE['nunext'] ?? '';
		if (!is_string($sid) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sid)) return null;
		try {
			$row = self::row('SELECT user_id, login, seen_ts FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id = ?', [hash('sha256', $sid)]);
			if (!$row) return null;
			$ga = (string)$row['user_id'] === 'globeadmin';
			$limit = max(1, (int)self::setting($ga ? 'nuConfigSessionTimeoutGlobeadmin' : 'nuConfigSessionTimeout')) * 60;
			$seen = (int)($row['seen_ts'] ?? 0);
			if ($seen > 0 && $seen + $limit + 60 < time()) return null;            // idle longer than the timeout (+ the 30 s between updates)
			$user = ['id' => (string)$row['user_id'], 'login' => (string)$row['login'], 'name' => '', 'first_name' => '', 'last_name' => '',
				'email' => '', 'team' => '', 'department' => '', 'position' => '', 'code' => '', 'language' => '', 'globeadmin' => $ga,
				'access_id' => '', 'access_code' => '', 'access_levels' => [], 'permissions' => []];
			if ($ga) return $user;
			$u = self::row('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$user['id']]);
			if (!$u) return null;
			$exp = substr(trim((string)($u['sus_expires_on'] ?? '')), 0, 10);
			if ($exp !== '' && $exp !== '0000-00-00' && $exp < date('Y-m-d')) return null;   // expired account
			foreach (['name' => 'sus_name', 'first_name' => 'sus_first_name', 'last_name' => 'sus_last_name', 'email' => 'sus_email', 'team' => 'sus_team',
					  'department' => 'sus_department', 'position' => 'sus_position', 'code' => 'sus_code', 'language' => 'sus_language'] as $k => $col) {
				$user[$k] = trim((string)($u[$col] ?? ''));
			}
			$main = (string)($u['sus_zzzzsys_access_id'] ?? '');
			$ids = $main !== '' ? [$main] : [];
			try {
				foreach (self::all('SELECT access_id, valid_until FROM zzzzsys_nunext_user_access WHERE user_id = ?', [$user['id']]) as $r) {
					$until = trim((string)($r['valid_until'] ?? ''));
					if ((string)$r['access_id'] !== '' && ($until === '' || $until >= date('Y-m-d')) && !in_array((string)$r['access_id'], $ids, true)) $ids[] = (string)$r['access_id'];
				}
			} catch (Throwable) {
				// no additional access levels (older installation)
			}
			$user['access_id'] = $main;
			foreach ($ids as $id) {
				$a = self::row('SELECT sal_code, sal_description FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$id]);
				if (!$a) continue;
				$user['access_levels'][] = ['id' => $id, 'code' => (string)$a['sal_code'], 'description' => (string)$a['sal_description']];
				if ($id === $main) $user['access_code'] = (string)$a['sal_code'];
			}
			try {
				$user['permissions'] = array_map('strval', array_column(self::all("SELECT pme_ident FROM zzzzsys_user_permission
					LEFT JOIN zzzzsys_permission_item ON zzzzsys_permission_item_id = upe_ident
					WHERE upe_zzzzsys_user_id = ? AND COALESCE(upe_active, '0') = '1' AND pme_ident IS NOT NULL ORDER BY pme_ident", [$user['id']]), 'pme_ident'));
			} catch (Throwable) {
				// no permission items
			}
			return $user;
		} catch (Throwable $e) {
			error_log('nuBuilder Next nuexternal.php: ' . $e->getMessage());
			return null;
		}
	}

	private static function matches(string $key, array $names): bool
	{
		$v = mb_strtolower(trim((string)(self::current()[$key] ?? '')));
		if ($v === '') return false;
		foreach ($names as $n) if (mb_strtolower(trim((string)$n)) === $v) return true;
		return false;
	}

	private static function config(): array
	{
		if (self::$cfg !== null) return self::$cfg;
		$file = getenv('NU_CONFIG') ?: __DIR__ . '/nuconfig.php';
		if (!is_file($file)) throw new RuntimeException('nuconfig.php of nuBuilder Next not found');
		$vars = (static function (string $__file): array {
			require $__file;
			unset($__file);
			return get_defined_vars();
		})($file);
		return self::$cfg = $vars + ['nuConfigDBType' => 'mysql', 'nuConfigDBPort' => '', 'nuConfigDBCharacterSet' => 'utf8mb4', 'nuConfigDBOptions' => [],
			'nuConfigDBTrustServerCertificate' => false, 'nuConfigSessionTimeout' => 120, 'nuConfigSessionTimeoutGlobeadmin' => 720];
	}

	/** A setting as nuBuilder Next reads it: with $nuConfigSettingsFromDB the table zzzzsys_config wins over nuconfig.php. */
	private static function setting(string $name): mixed
	{
		$c = self::config();
		if (!empty($c['nuConfigSettingsFromDB'])) {
			try {
				$v = self::value('SELECT cfg_value FROM zzzzsys_config WHERE cfg_setting = ?', ['$' . $name]);
				if ($v !== null && trim((string)$v) !== '') return trim((string)$v, " \t\n\r\"'");
			} catch (Throwable) {
				// table missing: nuconfig.php
			}
		}
		return $c[$name] ?? null;
	}

	/** The running script relative to nuBuilder Next's folder (null: not inside it). */
	private static function relScript(): ?string
	{
		$root = realpath(__DIR__);
		$file = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
		if ($root === false || $file === false) return null;
		$root = str_replace('\\', '/', $root) . '/';
		$file = str_replace('\\', '/', $file);
		return str_starts_with($file, $root) ? substr($file, strlen($root)) : null;
	}

	/** This page relative to nuBuilder Next's folder (for the return after the sign-in), or null. */
	private static function here(): ?string
	{
		$rel = self::relScript();
		if ($rel === null || !str_contains($rel, '/') || in_array(explode('/', $rel)[0], self::OWN_DIRS, true)) return null;
		$q = (string)($_SERVER['QUERY_STRING'] ?? '');
		return $rel . ($q !== '' ? '?' . $q : '');
	}

	private static function row(string $sql, array $p): ?array
	{
		$st = self::pdo()->prepare($sql);
		$st->execute($p);
		$r = $st->fetch(PDO::FETCH_ASSOC);
		return $r === false ? null : $r;
	}

	private static function all(string $sql, array $p): array
	{
		$st = self::pdo()->prepare($sql);
		$st->execute($p);
		return $st->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function value(string $sql, array $p): mixed
	{
		$st = self::pdo()->prepare($sql);
		$st->execute($p);
		$v = $st->fetchColumn();
		return $v === false ? null : $v;
	}
}
