<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Logged-in users (zzzzsys_nunext_session): one row per nuBuilder Next session with user, IP, browser, login time and last activity.
 * Updated at most every 30 s per session. The globeadmin can end a session (the user is logged out on the next request).
 * The row key is a hash of the PHP session id – the id itself is never stored.
 */
final class NuSessions
{
	public const TOUCH_EVERY = 30;

	public static function key(): string
	{
		return hash('sha256', session_id());
	}

	private static function now(): string
	{
		return nu_db()->type === 'sqlsrv' ? date('Y-m-d\TH:i:s') : date('Y-m-d H:i:s');
	}

	/**
	 * Called for every authenticated request. Returns false when the session was ended by the globeadmin.
	 */
	public static function touch(array $user): bool
	{
		$key = self::key();
		$known = $_SESSION['nu_sess'] ?? null;            // ['key' => …, 'seen' => time]
		if (is_array($known) && $known['key'] === $key && time() - (int)$known['seen'] < self::TOUCH_EVERY) {
			try {   // between two activity updates only check that the globeadmin has not ended the session (one indexed read)
				return (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id = ?', [$key]);
			} catch (Throwable) {
				return true;
			}
		}
		try {
			NuSchema::ensure();
			$db = nu_db();
			if (is_array($known) && $known['key'] === $key) {
				$n = $db->update('zzzzsys_nunext_session', ['last_seen' => self::now(), 'ip' => mb_substr(nu_client_ip(), 0, 45)], 'zzzzsys_nunext_session_id', $key);
				if ($n === 0 && !$db->value('SELECT COUNT(*) FROM zzzzsys_nunext_session WHERE zzzzsys_nunext_session_id = ?', [$key])) return false;   // ended by the globeadmin
				NuVendor::touch();
			} else {
				if (is_array($known) && $known['key'] !== '') {   // id regenerated
					$db->delete('zzzzsys_nunext_session', 'zzzzsys_nunext_session_id', (string)$known['key']);
					NuVendor::forget((string)$known['key']);
					unset($_SESSION['nu_vendor']);
				}
				$db->delete('zzzzsys_nunext_session', 'zzzzsys_nunext_session_id', $key);
				$db->insert('zzzzsys_nunext_session', [
					'zzzzsys_nunext_session_id' => $key, 'user_id' => mb_substr((string)$user['id'], 0, 25), 'login' => mb_substr((string)$user['login'], 0, 50),
					'display' => mb_substr((string)($user['display'] ?? ''), 0, 100), 'via' => mb_substr((string)($user['via'] ?? ''), 0, 20),
					'ip' => mb_substr(nu_client_ip(), 0, 45), 'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
					'login_at' => date(nu_db()->type === 'sqlsrv' ? 'Y-m-d\TH:i:s' : 'Y-m-d H:i:s', (int)($_SESSION['nu_auth']['since'] ?? time())),
					'last_seen' => self::now(),
				]);
			}
			$_SESSION['nu_sess'] = ['key' => $key, 'seen' => time()];
			if (random_int(1, 40) === 1) self::purge();
		} catch (Throwable $e) {
			error_log('nuBuilder Next sessions: ' . $e->getMessage());
		}
		return true;
	}

	/** Removes the row of the current session (logout). */
	public static function forget(): void
	{
		$known = $_SESSION['nu_sess'] ?? null;
		if (!is_array($known) || ($known['key'] ?? '') === '') return;
		try {
			nu_db()->delete('zzzzsys_nunext_session', 'zzzzsys_nunext_session_id', (string)$known['key']);
			NuVendor::forget((string)$known['key']);
			unset($_SESSION['nu_vendor']);
		} catch (Throwable) {
		}
	}

	/** Sessions without activity for longer than the session timeout are gone (the globeadmin's: globeadmin timeout). */
	public static function purge(): void
	{
		$fmt = nu_db()->type === 'sqlsrv' ? 'Y-m-d\TH:i:s' : 'Y-m-d H:i:s';
		nu_db()->run('DELETE FROM zzzzsys_nunext_session WHERE user_id <> ? AND last_seen < ?', ['globeadmin', date($fmt, time() - nu_session_timeout(false))]);
		nu_db()->run('DELETE FROM zzzzsys_nunext_session WHERE user_id = ? AND last_seen < ?', ['globeadmin', date($fmt, time() - nu_session_timeout(true))]);
		NuVendor::purge();
	}

	public static function end(string $key): int
	{
		NuVendor::forget($key);
		return nu_db()->delete('zzzzsys_nunext_session', 'zzzzsys_nunext_session_id', $key);
	}

	/** Short browser / system description of a user agent. */
	public static function agent(string $ua): string
	{
		$b = match (true) {
			str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
			str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => '',
		};
		$o = match (true) {
			str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
			str_contains($ua, 'Android') => 'Android', str_contains($ua, 'Mac OS X') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => '',
		};
		return trim($b . ($b && $o ? ' · ' : '') . $o) ?: mb_strimwidth($ua, 0, 40, '…');
	}
}
