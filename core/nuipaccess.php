<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * IP access (0.8.722): from which addresses users may sign in and work – Settings → Security → IP access (?p=ipaccess, the globeadmin).
 * Settings row 'ip_access' = {on, default: [rules], levels: {access id: {mode: any | ranges, ranges: [rules]}}, ga: {mode: default |
 * any | ranges, ranges: [rules]}}; a rule is an address (203.0.113.7, 2001:db8::1) or a network with a prefix (10.0.0.0/8). An empty
 * default list = every address. A user may work when ANY of his active access levels (main + additional, NuUserAccess) allows the
 * address – a level without an entry uses the default. Checked when signing in (password, auto-login link, one-time login link –
 * before the second factor) and on every request (NuAuth::user: a session from another address ends). Not checked: REST API clients
 * (their own "Allowed IPs"), demo sessions; a globeadmin acting as a user is checked by the globeadmin's rule. The address is
 * nu_client_ip() – behind a proxy $nuConfigTrustProxy. Off from the shell: php cron.php iprules off.
 */
final class NuIpAccess
{
	public const KEY = 'ip_access';
	public const GA_MODES = ['default', 'any', 'ranges'];
	public const LEVEL_MODES = ['default', 'any', 'ranges'];
	private static ?array $cache = null;

	/** The stored settings (defaults while there is no row / no table). */
	public static function settings(): array
	{
		if (self::$cache !== null) return self::$cache;
		$s = null;
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::KEY]);
			$s = is_string($v) ? json_decode($v, true) : null;
		} catch (Throwable) {
		}
		return self::$cache = self::clean(is_array($s) ? $s : []);
	}

	/** Settings in their stored shape: known keys only, valid rules only, levels without an entry dropped. */
	public static function clean(array $s): array
	{
		$rules = static fn($l): array => array_values(array_unique(array_filter(array_map(static fn($r) => trim((string)$r), is_array($l) ? $l : []), [self::class, 'validRule'])));
		$levels = [];
		foreach ((array)($s['levels'] ?? []) as $id => $l) {
			$mode = (string)($l['mode'] ?? '');
			if ((string)$id === '' || !in_array($mode, ['any', 'ranges'], true)) continue;
			$levels[(string)$id] = ['mode' => $mode, 'ranges' => $mode === 'ranges' ? $rules($l['ranges'] ?? []) : []];
		}
		$gm = (string)($s['ga']['mode'] ?? 'default');
		if (!in_array($gm, self::GA_MODES, true)) $gm = 'default';
		return ['on' => !empty($s['on']), 'default' => $rules($s['default'] ?? []), 'levels' => $levels,
			'ga' => ['mode' => $gm, 'ranges' => $gm === 'ranges' ? $rules($s['ga']['ranges'] ?? []) : []]];
	}

	public static function on(): bool
	{
		return self::settings()['on'];
	}

	public static function save(array $s, string $by): array
	{
		$s = self::clean($s);
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => json_encode($s, JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $by];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::KEY])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::KEY);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::KEY] + $row);
		self::$cache = null;
		return $s;
	}

	/** An address or a network with a prefix (IPv4 /0–32, IPv6 /0–128). */
	public static function validRule(string $r): bool
	{
		if (filter_var($r, FILTER_VALIDATE_IP)) return true;
		if (!preg_match('~^(.+)/(\d{1,3})$~', $r, $m) || !filter_var($m[1], FILTER_VALIDATE_IP)) return false;
		return (int)$m[2] <= (str_contains($m[1], ':') ? 128 : 32);
	}

	/**
	 * Text of a rules field → [rules, invalid entries]: one per line or separated by spaces / commas / semicolons, "# …" = a comment.
	 * @return array{0: list<string>, 1: list<string>}
	 */
	public static function parse(string $text): array
	{
		$ok = $bad = [];
		foreach (preg_split('/\R/', $text) ?: [] as $line) {
			$line = trim((string)preg_replace('/#.*$/', '', $line));
			foreach (preg_split('/[\s,;]+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $r) {
				if (self::validRule($r)) $ok[] = $r; else $bad[] = $r;
			}
		}
		return [array_values(array_unique($ok)), $bad];
	}

	/** The client's address; IPv4 mapped into IPv6 (::ffff:1.2.3.4) as IPv4. */
	public static function ip(?string $ip = null): string
	{
		$ip = trim($ip ?? nu_client_ip());
		if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) $ip = $m[1];
		return $ip;
	}

	public static function matches(array $rules, string $ip): bool
	{
		if (!$rules) return false;
		return @inet_pton($ip) !== false && NuApiClient::ipAllowed(implode(' ', $rules), $ip);
	}

	/** One access level's rule allows the address (no entry = the default list; an empty default = every address). */
	public static function levelAllows(string $accessId, string $ip, ?array $s = null): bool
	{
		$s ??= self::settings();
		$l = $s['levels'][$accessId] ?? null;
		if ($l && $l['mode'] === 'any') return true;
		$rules = $l ? $l['ranges'] : $s['default'];
		if (!$l && !$rules) return true;
		return self::matches($rules, $ip);
	}

	public static function gaAllows(string $ip, ?array $s = null): bool
	{
		$s ??= self::settings();
		return match ($s['ga']['mode']) {
			'any'    => true,
			'ranges' => self::matches($s['ga']['ranges'], $ip),
			default  => !$s['default'] || self::matches($s['default'], $ip),
		};
	}

	/** Access levels allow the address when any of them does (a user without a level: the default list). */
	public static function levelsAllow(array $levels, string $ip, ?array $s = null): bool
	{
		$s ??= self::settings();
		if (!$levels) return !$s['default'] || self::matches($s['default'], $ip);
		foreach ($levels as $a) if (self::levelAllows((string)$a, $ip, $s)) return true;
		return false;
	}

	/** Main level + active additional levels of a user id. @return list<string> */
	public static function levelsOf(string $userId): array
	{
		$main = (string)nu_db()->value('SELECT sus_zzzzsys_access_id FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]);
		return NuUserAccess::levels(['id' => $userId, 'globeadmin' => false, 'access_id' => $main]);
	}

	/** Signing in ($auth of NuAuth: id, globeadmin): allowed from this address? Refusals are logged. */
	public static function allowsLogin(array $auth, string $via = ''): bool
	{
		if (!self::on()) return true;
		$ip = self::ip();
		$ok = !empty($auth['globeadmin']) ? self::gaAllows($ip) : self::levelsAllow(self::levelsOf((string)$auth['id']), $ip);
		if (!$ok) self::logRefused($auth, $ip, 'login' . ($via !== '' ? ' ' . $via : ''));
		return $ok;
	}

	/** Every request (NuAuth::user, $user as built there, $via = the session's way in). Refusals are logged. */
	public static function allowsRequest(array $user, string $via): bool
	{
		if (!self::on() || !empty($user['demo']) || in_array($via, ['demo', 'demo_ga', 'api'], true)) return true;
		$ip = self::ip();
		if (!empty($user['globeadmin']) || ($via === 'impersonated' && NuImpersonate::active())) $ok = self::gaAllows($ip);
		else $ok = self::levelsAllow(NuUserAccess::levels($user), $ip);
		if (!$ok) self::logRefused($user, $ip, 'session');
		return $ok;
	}

	private static function logRefused(array $who, string $ip, string $when): void
	{
		try {
			NuLog::write('ip_refused', '', '', ['ip' => $ip, 'when' => $when], ['id' => (string)($who['id'] ?? ''), 'login' => (string)($who['login'] ?? '')]);
		} catch (Throwable) {
		}
	}

	/**
	 * Which access levels and the globeadmin could work from an address with these settings.
	 * @return array{ip: string, valid: bool, ga: bool, allowed: list<array{id: string, code: string, name: string}>, refused: list<array{id: string, code: string, name: string}>}
	 */
	public static function test(string $ip, ?array $s = null): array
	{
		$s ??= self::settings();
		$ip = self::ip($ip);
		$r = ['ip' => $ip, 'valid' => filter_var($ip, FILTER_VALIDATE_IP) !== false, 'ga' => false, 'allowed' => [], 'refused' => []];
		if (!$r['valid']) return $r;
		$r['ga'] = !$s['on'] || self::gaAllows($ip, $s);
		foreach (self::accessLevels() as $l) $r[!$s['on'] || self::levelAllows($l['id'], $ip, $s) ? 'allowed' : 'refused'][] = $l;
		return $r;
	}

	/** @return list<array{id: string, code: string, name: string, group: string}> */
	public static function accessLevels(): array
	{
		try {
			return array_map(static fn($r) => ['id' => (string)$r['zzzzsys_access_id'], 'code' => (string)$r['sal_code'], 'name' => (string)$r['sal_description'], 'group' => (string)($r['sal_group'] ?? '')],
				nu_db()->all('SELECT * FROM zzzzsys_access ORDER BY sal_code'));
		} catch (Throwable) {
			return [];
		}
	}

	/** Switched off (cron.php iprules off): the rules stay stored. @return bool was on */
	public static function switchOff(string $by): bool
	{
		$s = self::settings();
		if (!$s['on']) return false;
		self::save(['on' => false] + $s, $by);
		NuLog::write('ip_access_off', 'zzzzsys_nunext_setting', self::KEY, ['by' => $by], ['id' => '', 'login' => $by]);
		return true;
	}

	/** For tests: forget the cached settings. */
	public static function reset(): void
	{
		self::$cache = null;
	}
}
