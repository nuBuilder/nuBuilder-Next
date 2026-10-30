<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Password policy (0.8.745): settings row 'pw_policy' – rules for new passwords (minimum length at least $nuConfigPasswordMinLength,
 * optional character classes, not the login / name, known leaked passwords: Have I Been Pwned range API with k-anonymity – only the first
 * 5 characters of the SHA-1 hash leave the server – and / or the offline list install/common-passwords.txt), the same check at sign-in
 * (remembered 30 days per password; a hit = forced change), optional regular change (users: global + per access level, the shortest
 * interval of a user's levels counts; the globeadmin: a reminder only, the password lives in nuconfig.php). Page ?p=pwpolicy
 * (settings centre → Sign-in & security). Used by NuAuth::passwordPolicy (every place a password is set) and NuAuth::attempt.
 */
final class NuPwPolicy
{
	public const KEY = 'pw_policy';
	public const GA_KEY = 'pw_policy_ga';                 // {fp, since, leak: {fp, at, hit}} of the globeadmin password
	public const DEFAULTS = ['min_len' => 0, 'upper' => false, 'lower' => false, 'digit' => false, 'symbol' => false, 'not_name' => true,
		'leak' => 'both', 'leak_login' => true, 'expire' => 0, 'warn' => 14, 'ga_expire' => 0, 'levels' => [], 'leak_url' => ''];
	public const LEAK_MODES = ['both', 'online', 'offline', 'off'];
	public const EVERY = [0, 30, 60, 90, 180, 365];
	public const WARN = [7, 14, 30];
	public const RECHECK_DAYS = 30;                        // a password found clean is checked again after …
	public const HIBP = 'https://api.pwnedpasswords.com/range/';
	public const MAX_LEN = 72;                             // bcrypt

	/** Test hook (unit tests): fn(string $prefix): ?string = the range answer, null = not reachable. */
	public static $transport = null;
	private static ?array $cache = null;
	private static ?array $common = null;

	/** @return array<string, mixed> */
	public static function settings(): array
	{
		if (self::$cache !== null) return self::$cache;
		$s = [];
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::KEY]);
			$s = is_string($v) ? (json_decode($v, true) ?: []) : [];
		} catch (Throwable) { /* no settings table yet */ }
		return self::$cache = self::clean($s);
	}

	/** @param array<string, mixed> $s */
	public static function clean(array $s): array
	{
		$d = self::DEFAULTS;
		$out = [];
		$out['min_len'] = max(0, min(self::MAX_LEN, (int)($s['min_len'] ?? 0)));
		foreach (['upper', 'lower', 'digit', 'symbol', 'not_name', 'leak_login'] as $k) $out[$k] = array_key_exists($k, $s) ? (bool)$s[$k] : $d[$k];
		$out['leak'] = in_array($s['leak'] ?? '', self::LEAK_MODES, true) ? (string)$s['leak'] : $d['leak'];
		$e = (int)($s['expire'] ?? 0); $ge = (int)($s['ga_expire'] ?? 0); $w = (int)($s['warn'] ?? 14);
		$out['expire'] = in_array($e, self::EVERY, true) ? $e : 0;
		$out['ga_expire'] = in_array($ge, self::EVERY, true) ? $ge : 0;
		$out['warn'] = in_array($w, self::WARN, true) ? $w : 14;
		$out['levels'] = [];
		foreach ((array)($s['levels'] ?? []) as $id => $days) {
			if (is_string($id) && preg_match('/^[\w.-]{1,64}$/', $id) && in_array((int)$days, self::EVERY, true) && $days !== '' && $days !== null) $out['levels'][$id] = (int)$days;
		}
		$url = (string)($s['leak_url'] ?? '');
		$out['leak_url'] = preg_match('~^https?://[^\s]+/$~', $url) ? $url : '';   // tests only (developer mode), never in the form
		return $out;
	}

	public static function save(array $s): bool
	{
		$old = self::settings();
		$new = self::clean($s + ['leak_url' => $old['leak_url']]);
		if ($new === $old) return false;
		self::write(self::KEY, $new);
		self::$cache = $new;
		NuLog::write('pw_policy_changed', 'zzzzsys_nunext_setting', self::KEY, ['from' => $old, 'to' => $new]);
		return true;
	}

	private static function write(string $key, array $value): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$key])) {
			$db->update('zzzzsys_nunext_setting', ['setting_value' => $json], 'zzzzsys_nunext_setting_id', $key);
		} else {
			$db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $key, 'setting_value' => $json]);
		}
	}

	private static function readJson(string $key): array
	{
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$key]);
			$a = is_string($v) ? json_decode($v, true) : [];
			return is_array($a) ? $a : [];
		} catch (Throwable) { return []; }
	}

	/** Minimum length in force: nuconfig.php's $nuConfigPasswordMinLength (nuBuilder Forte) or the policy's, whichever is higher. */
	public static function minLength(): int
	{
		$cfg = 8;
		try { $cfg = max(1, (int)nu_cfg('nuConfigPasswordMinLength')); } catch (Throwable) { /* installer */ }
		return max($cfg, (int)self::settings()['min_len']);
	}

	/** The rules shown next to a password field (client check in assets/js/nupwrules.js). */
	public static function clientRules(): array
	{
		$s = self::settings();
		return ['min' => self::minLength(), 'max' => self::MAX_LEN, 'upper' => $s['upper'], 'lower' => $s['lower'], 'digit' => $s['digit'], 'symbol' => $s['symbol']];
	}

	private static bool $jsDone = false;

	/** The rules as a checklist below a password field; assets/js/nupwrules.js ticks them while typing (leak / name: on saving). */
	public static function rulesHtml(string $forId): string
	{
		$r = self::clientRules();
		$items = ['len' => t('pw_pol_len', $r['min'])];
		foreach (['upper', 'lower', 'digit', 'symbol'] as $k) if ($r[$k]) $items[$k] = t('pw_pol_' . $k);
		$s = self::settings();
		$server = [];
		if ($s['not_name']) $server[] = t('pw_pol_name');
		if ($s['leak'] !== 'off') $server[] = t('pw_rules_leak');
		$html = '<div class="form-text nuPwRules" data-for="' . h($forId) . '" data-min="' . (int)$r['min'] . '"><span>' . h(t('pw_rules_title')) . '</span><ul class="list-unstyled mb-0 mt-1">';
		foreach ($items as $k => $txt) $html .= '<li data-rule="' . h($k) . '"><i class="bi bi-dot" aria-hidden="true"></i> ' . h($txt) . '</li>';
		foreach ($server as $txt) $html .= '<li class="nuPwRuleServer"><i class="bi bi-dot" aria-hidden="true"></i> ' . h($txt) . '</li>';
		$html .= '</ul></div>';
		if (!self::$jsDone) { self::$jsDone = true; $html .= '<script src="' . h(nu_asset('assets/js/nupwrules.js')) . '"></script>'; }
		return $html;
	}

	/**
	 * What a new password breaks. $ctx: login, name, email (for not_name), leak = false to skip the leak check.
	 * @return list<array{0: string, 1?: int|string}> [text key, argument]
	 */
	public static function violations(string $pw, array $ctx = []): array
	{
		$s = self::settings();
		$v = [];
		$min = self::minLength();
		if (mb_strlen($pw) < $min) $v[] = ['pw_pol_len', $min];
		if (strlen($pw) > self::MAX_LEN) $v[] = ['pw_pol_max', self::MAX_LEN];
		if ($s['upper'] && !preg_match('/\p{Lu}/u', $pw)) $v[] = ['pw_pol_upper'];
		if ($s['lower'] && !preg_match('/\p{Ll}/u', $pw)) $v[] = ['pw_pol_lower'];
		if ($s['digit'] && !preg_match('/\d/', $pw)) $v[] = ['pw_pol_digit'];
		if ($s['symbol'] && !preg_match('/[^\p{L}\p{N}]/u', $pw)) $v[] = ['pw_pol_symbol'];
		if ($s['not_name'] && self::containsName($pw, $ctx)) $v[] = ['pw_pol_name'];
		if (($ctx['leak'] ?? true) && $s['leak'] !== 'off' && $pw !== '') {
			$hit = self::leaked($pw);
			if ($hit === -1) $v[] = ['pw_pol_common'];
			elseif ($hit > 0) $v[] = ['pw_pol_leaked', number_format($hit, 0, '.', "\u{2019}")];
		}
		return $v;
	}

	/** One message for the form: intro + the broken rules. */
	public static function message(array $violations): string
	{
		$parts = array_map(static fn($x) => isset($x[1]) ? t($x[0], $x[1]) : t($x[0]), $violations);
		return t('pw_pol_intro') . ' ' . implode('; ', $parts) . '.';
	}

	private static function containsName(string $pw, array $ctx): bool
	{
		$p = mb_strtolower($pw);
		$words = [];
		foreach (['login', 'name', 'email'] as $k) {
			$val = mb_strtolower(trim((string)($ctx[$k] ?? '')));
			if ($val === '') continue;
			if ($k === 'email') $val = (string)strstr($val . '@', '@', true);
			$words[] = $val;
			foreach (preg_split('/[\s._@-]+/u', $val) ?: [] as $w) $words[] = $w;
		}
		foreach (array_unique($words) as $w) if (mb_strlen($w) >= 4 && str_contains($p, $w)) return true;
		return false;
	}

	/**
	 * Known leak: > 0 = times in Have I Been Pwned, -1 = in the offline list, 0 = not found (or no source reachable).
	 */
	public static function leaked(string $pw, ?string $mode = null): int
	{
		$mode ??= (string)self::settings()['leak'];
		if ($mode === 'off') return 0;
		if ($mode === 'online' || $mode === 'both') {
			$n = self::online($pw);
			if ($n !== null) return $n > 0 ? $n : ($mode === 'both' && self::offline($pw) ? -1 : 0);
			if ($mode === 'online') return 0;
		}
		return self::offline($pw) ? -1 : 0;
	}

	/** Have I Been Pwned range API: null = not reachable. Only the first 5 hex characters of SHA-1 are sent (Add-Padding: true). */
	public static function online(string $pw): ?int
	{
		$sha = strtoupper(sha1($pw));
		$prefix = substr($sha, 0, 5);
		$suffix = substr($sha, 5);
		$body = null;
		if (is_callable(self::$transport)) $body = (self::$transport)($prefix);
		else {
			if (!function_exists('curl_init')) return null;
			$base = self::settings()['leak_url'] !== '' && NuHash::devMode() ? (string)self::settings()['leak_url'] : self::HIBP;
			$ch = curl_init($base . $prefix);
			curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_HTTPHEADER => ['Add-Padding: true', 'User-Agent: nuBuilder-Next-password-policy']]);
			$r = curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$body = is_string($r) && $code === 200 ? $r : null;
		}
		if (!is_string($body)) return null;
		foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
			[$suf, $cnt] = array_pad(explode(':', trim($line), 2), 2, '0');
			if (strtoupper($suf) === $suffix) return (int)$cnt;   // padding lines have count 0
		}
		return 0;
	}

	public static function offline(string $pw): bool
	{
		if (self::$common === null) {
			$f = NU_ROOT . '/install/common-passwords.txt';
			$lines = is_file($f) ? (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
			self::$common = array_flip($lines);
		}
		return isset(self::$common[mb_strtolower($pw)]);
	}

	// ================================================================== regular change

	/** Days between changes for a user (0 = never): the shortest override of the user's access levels, else the global value. */
	public static function expireDays(string $userId): int
	{
		$s = self::settings();
		$over = [];
		try { $levels = NuUserAccess::levels($userId); } catch (Throwable) { $levels = []; }
		foreach ($levels as $l) if (isset($s['levels'][$l])) $over[] = (int)$s['levels'][$l];
		if (!$over) return (int)$s['expire'];
		$pos = array_filter($over, static fn($d) => $d > 0);
		return $pos ? min($pos) : 0;
	}

	/** Last change (Unix time) – PASSWORD_CHANGED_TIME of sus_json, as nuBuilder Forte; none yet: now (the clock starts). */
	public static function lastChange(string $userId): int
	{
		$t = (int)NuUserJson::get($userId, 'PASSWORD_CHANGED_TIME');
		if ($t <= 0) { $t = time(); NuUserJson::set($userId, 'PASSWORD_CHANGED_TIME', $t); }
		return $t;
	}

	/** @return array{days: int, left: ?int} days = interval (0 never), left = days until expiry (negative = expired) */
	public static function expiry(string $userId): array
	{
		$days = self::expireDays($userId);
		if ($days === 0) return ['days' => 0, 'left' => null];
		$left = (int)floor((self::lastChange($userId) + $days * 86400 - time()) / 86400);
		return ['days' => $days, 'left' => $left];
	}

	/**
	 * Sign-in of a user with the correct password (NuAuth::attempt): expired or leaked → sus_change_password = 1 (the forced change page),
	 * reason in the session; expiring soon → a reminder after the sign-in.
	 */
	public static function onLogin(string $userId, string $password, string $pwHash): void
	{
		try {
			$s = self::settings();
			$reason = '';
			if ($s['leak'] !== 'off' && $s['leak_login']) {
				$fp = substr(hash('sha256', $pwHash), 0, 16);
				$seen = (string)NuUserJson::get($userId, 'NUNEXT_PW_LEAK_CHECK');
				[$sfp, $sat] = array_pad(explode('|', $seen, 2), 2, '0');
				if ($sfp !== $fp || (int)$sat < time() - self::RECHECK_DAYS * 86400) {
					$hit = self::leaked($password);
					NuUserJson::set($userId, 'NUNEXT_PW_LEAK_CHECK', $hit !== 0 ? '' : $fp . '|' . time());
					if ($hit !== 0) $reason = 'leaked';
				}
			}
			$e = self::expiry($userId);
			if ($reason === '' && $e['left'] !== null && $e['left'] < 0) $reason = 'expired';
			if ($reason !== '') {
				nu_db()->update('zzzzsys_user', ['sus_change_password' => '1'], 'zzzzsys_user_id', $userId);
				$_SESSION['nu_pw_reason'] = $reason;
				NuLog::write('pw_forced', 'zzzzsys_user', $userId, ['reason' => $reason], ['id' => $userId]);
			} elseif ($e['left'] !== null && $e['left'] <= (int)$s['warn']) {
				$_SESSION['nu_pw_soon'] = max(0, $e['left']);
			}
		} catch (Throwable $x) {
			error_log('nuBuilder Next password policy: ' . $x->getMessage());
		}
	}

	/** The globeadmin's sign-in: the age of the nuconfig.php password (since it was first seen) and the leak check – reminders only. */
	public static function onGaLogin(string $password): void
	{
		try {
			$s = self::settings();
			$g = self::readJson(self::GA_KEY);
			$fp = substr(hash('sha256', 'ga|' . $password), 0, 16);
			if (($g['fp'] ?? '') !== $fp) $g = ['fp' => $fp, 'since' => time(), 'leak' => null];
			if ($s['leak'] !== 'off' && $s['leak_login'] && (!is_array($g['leak'] ?? null) || (int)$g['leak']['at'] < time() - self::RECHECK_DAYS * 86400)) {
				$g['leak'] = ['at' => time(), 'hit' => self::leaked($password) !== 0];
			}
			self::write(self::GA_KEY, $g);
			$msgs = [];
			if (!empty($g['leak']['hit'])) $msgs[] = t('ga_pw_leaked');
			if ($s['ga_expire'] > 0 && (int)$g['since'] + $s['ga_expire'] * 86400 < time()) $msgs[] = t('ga_pw_expired', $s['ga_expire']);
			if ($msgs) $_SESSION['nu_pw_ga'] = $msgs;
		} catch (Throwable $x) {
			error_log('nuBuilder Next password policy: ' . $x->getMessage());
		}
	}

	/** Reminders after the sign-in (nu_layout_start → a flash once): expiring soon, globeadmin reminders. */
	public static function flashes(): void
	{
		if (isset($_SESSION['nu_pw_soon'])) {
			$d = (int)$_SESSION['nu_pw_soon'];
			unset($_SESSION['nu_pw_soon']);
			$_SESSION['nu_flash'][] = ['warning', t('pw_expires_soon', $d), false, [nu_url('password'), t('pw_change_now')]];
		}
		if (isset($_SESSION['nu_pw_ga'])) {
			$m = (array)$_SESSION['nu_pw_ga'];
			unset($_SESSION['nu_pw_ga']);
			$_SESSION['nu_flash'][] = ['warning', implode(' ', $m), false, NuAdmin::can('configfile', 'change') ? [nu_url('configfile'), t('cfg_title')] : null];
		}
	}

	/** Users with an expired password / expiring within the reminder time (settings page). @return array{0: int, 1: int} */
	public static function counts(): array
	{
		$exp = 0; $soon = 0;
		$s = self::settings();
		if ($s['expire'] === 0 && !array_filter($s['levels'])) return [0, 0];
		try {
			foreach (nu_db()->all("SELECT zzzzsys_user_id FROM zzzzsys_user WHERE sus_login_password <> ''") as $r) {
				$days = self::expireDays((string)$r['zzzzsys_user_id']);
				if ($days === 0) continue;
				$t = (int)NuUserJson::get((string)$r['zzzzsys_user_id'], 'PASSWORD_CHANGED_TIME');
				if ($t <= 0) continue;                                // the clock starts at the next sign-in
				$left = (int)floor(($t + $days * 86400 - time()) / 86400);
				if ($left < 0) $exp++; elseif ($left <= $s['warn']) $soon++;
			}
		} catch (Throwable) { /* no user table */ }
		return [$exp, $soon];
	}
}
