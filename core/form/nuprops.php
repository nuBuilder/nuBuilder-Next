<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Form properties (nuSetProperty) kept on the server.
 *
 * Every form page gets a scope key (ps). The URL of a form opened from it carries only that key – the property values stay in
 * the session, so they cannot be edited in the address bar, bookmarked or leaked into logs. Values set in PHP (BB, BE,
 * procedures, BS/AS …) are "locked": the browser cannot override them in later requests of that page or of forms opened
 * from it. Values set in JavaScript stay browser values, as in nuBuilder (hash values are escaped either way).
 *
 *   $_SESSION['nu_ps'][key] = ['p' => [name => value], 'l' => [name => true], 't' => time]
 */
final class NuProps
{
	private const MAX = 300;          // scopes per session (oldest are dropped)
	private const NAME = '/^\w{1,100}$/';

	/** [props, locked] of a scope; empty for an unknown key. */
	public static function load(?string $key): array
	{
		$key = (string)$key;
		$s = preg_match('/^[a-f0-9]{16}$/', $key) ? ($_SESSION['nu_ps'][$key] ?? null) : null;
		return is_array($s) ? [(array)$s['p'], (array)$s['l']] : [[], []];
	}

	/** Stores a scope and returns its key. */
	public static function create(array $props, array $locked = []): string
	{
		$key = bin2hex(random_bytes(8));
		$_SESSION['nu_ps'][$key] = ['p' => self::clean($props), 'l' => array_intersect_key($locked, $props), 't' => time()];
		if (count($_SESSION['nu_ps']) > self::MAX) {
			uasort($_SESSION['nu_ps'], static fn($a, $b) => $a['t'] <=> $b['t']);
			$_SESSION['nu_ps'] = array_slice($_SESSION['nu_ps'], -self::MAX, null, true);
		}
		return $key;
	}

	/** Adds values set in PHP to a scope (locked). */
	public static function lock(?string $key, array $phpProps): void
	{
		$key = (string)$key;
		if ($phpProps === [] || !isset($_SESSION['nu_ps'][$key])) return;
		foreach (self::clean($phpProps) as $k => $v) {
			$_SESSION['nu_ps'][$key]['p'][$k] = $v;
			$_SESSION['nu_ps'][$key]['l'][$k] = true;
		}
		$_SESSION['nu_ps'][$key]['t'] = time();
	}

	/** Properties of a request: stored values + values sent by the browser; locked names keep the stored value. */
	public static function merge(?string $key, mixed $client): array
	{
		[$p, $l] = self::load($key);
		$c = is_array($client) ? self::clean($client) : [];
		return array_merge($p, array_diff_key($c, $l));
	}

	/** Removes names from a scope (nuDeleteProperty). */
	public static function forget(?string $key, array $names): void
	{
		$key = (string)$key;
		if (!isset($_SESSION['nu_ps'][$key])) return;
		foreach ($names as $n) unset($_SESSION['nu_ps'][$key]['p'][$n], $_SESSION['nu_ps'][$key]['l'][$n]);
	}

	/** A copy of a scope with the browser's current values (navigation to another form). */
	public static function fork(?string $key, mixed $client): string
	{
		[, $l] = self::load($key);
		$p = self::merge($key, $client);
		return self::create($p, array_intersect_key($l, $p));
	}

	public static function locked(?string $key): array
	{
		return array_keys(self::load($key)[1]);
	}

	public const MAX_NAMES = 200;      // properties per scope / hash cookies per session (0.8.207: the session stays small)
	public const MAX_VALUE = 65536;    // bytes per value

	/** Valid names, scalar values (arrays / objects as JSON, like nuBuilder's hash); at most MAX_NAMES, values cut at MAX_VALUE. */
	public static function clean(array $a): array
	{
		$out = [];
		foreach ($a as $k => $v) {
			if (!is_string($k) || !preg_match(self::NAME, $k)) continue;
			if (count($out) >= self::MAX_NAMES) break;
			$v = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
			$out[$k] = is_string($v) && strlen($v) > self::MAX_VALUE ? mb_strcut($v, 0, self::MAX_VALUE) : $v;
		}
		return $out;
	}
}

/**
 * Global hash cookies: nuSetProperty(name, value, true) in JavaScript or PHP. They live until logout and are part of the hash
 * of every form (after system variables, field values and form properties).
 * Stored in the nuBuilder Next session; when the user came through nuBuilder's login (shared login) also in nuBuilder's
 * zzzzsys_session.sss_hashcookies, so both see the same values.
 */
final class NuHashCookies
{
	private static ?array $cache = null;

	public static function all(): array
	{
		if (self::$cache !== null) return self::$cache;
		$own = is_array($_SESSION['nu_hashcookies'] ?? null) ? $_SESSION['nu_hashcookies'] : [];
		$nb  = self::nuBuilderRow();
		return self::$cache = $nb !== null ? array_merge($own, $nb) : $own;
	}

	public static function get(string $name): mixed
	{
		return self::all()[$name] ?? null;
	}

	public static function set(string $name, mixed $value): void
	{
		if (!preg_match('/^\w{1,100}$/', $name)) return;
		if (!isset($_SESSION['nu_hashcookies'][$name]) && count((array)($_SESSION['nu_hashcookies'] ?? [])) >= NuProps::MAX_NAMES) return;
		$value = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
		if (is_string($value) && strlen($value) > NuProps::MAX_VALUE) $value = mb_strcut($value, 0, NuProps::MAX_VALUE);
		$_SESSION['nu_hashcookies'][$name] = $value;
		self::writeNuBuilder(static function (array $j) use ($name, $value) { $j[$name] = $value; return $j; });
		self::$cache = null;
	}

	public static function delete(string $name): void
	{
		unset($_SESSION['nu_hashcookies'][$name]);
		self::writeNuBuilder(static function (array $j) use ($name) { unset($j[$name]); return $j; });
		self::$cache = null;
	}

	/** nuBuilder's session id when the user logged in through nuBuilder (shared login), else null. */
	public static function nuBuilderSession(): ?string
	{
		$a = $_SESSION['nu_auth'] ?? null;
		return is_array($a) && ($a['via'] ?? '') === 'nubuilder' && (string)($a['nb_session'] ?? '') !== '' ? (string)$a['nb_session'] : null;
	}

	private static function hasColumn(): bool
	{
		static $has = null;
		return $has ??= isset(nu_db()->columns('zzzzsys_session')['sss_hashcookies']);
	}

	private static function nuBuilderRow(): ?array
	{
		$sid = self::nuBuilderSession();
		if ($sid === null || !self::hasColumn()) return null;
		try {
			$j = json_decode((string)nu_db()->value('SELECT sss_hashcookies FROM zzzzsys_session WHERE zzzzsys_session_id = ?', [$sid]), true);
			return is_array($j) ? $j : [];
		} catch (Throwable) {
			return null;
		}
	}

	private static function writeNuBuilder(callable $change): void
	{
		$sid = self::nuBuilderSession();
		if ($sid === null || !self::hasColumn()) return;
		try {
			$db = nu_db();
			$j = json_decode((string)$db->value('SELECT sss_hashcookies FROM zzzzsys_session WHERE zzzzsys_session_id = ?', [$sid]), true);
			$j = $change(is_array($j) ? $j : []);
			$db->run('UPDATE zzzzsys_session SET sss_hashcookies = ? WHERE zzzzsys_session_id = ?', [json_encode($j ?: (object)[], JSON_UNESCAPED_UNICODE), $sid]);
		} catch (Throwable $e) {
			error_log('nuBuilder Next hash cookies: ' . $e->getMessage());
		}
	}
}
