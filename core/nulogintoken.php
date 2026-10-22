<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder's single-use login token (wiki "Single-User Password Token", 0.8.339).
 *
 *   zzzzsys_user.sus_json LOGIN_TOKEN = "<at least 20 characters>"   – written by nuBuilder Next (user form, nuCreateLoginToken(),
 *                                                                    REST API) or by another system, exactly as in nuBuilder
 *   sus_json LOGIN_TOKEN_EXPIRES = <Unix time>                        – optional, nuBuilder Next only (nuBuilder ignores the key)
 *   index.php?u=<login name or user id>&p=<token>[&f=<form>&r=<record>] – logs in once (pages/nuautologin.php → NuAuth::loginToken)
 *
 * Like nuBuilder: after a successful login the token is replaced by a new random one (so it works once) and no second factor is
 * asked. Unlike nuBuilder: exact comparison in constant time (no LIKE), the token is replaced in one conditional UPDATE (two clicks
 * cannot both log in), never the globeadmin / API service users / expired users, failures count for the login rate limit, every
 * use is logged, optional expiry. Security setting 'login_token' switches it off.
 */
final class NuLoginToken
{
	public const KEY = 'LOGIN_TOKEN';
	public const EXPIRES = 'LOGIN_TOKEN_EXPIRES';
	public const MIN_LEN = 20;                       // nuBuilder: strlen >= 20
	public const LEN = 32;                           // tokens made by nuBuilder Next

	public static function enabled(): bool
	{
		return (bool)NuSecurity::get('login_token') && NuUserJson::available();
	}

	/** Could $p be a token (and not nuBuilder Next's own 64-hex auto-login hash)? */
	public static function looksLike(string $p): bool
	{
		return strlen($p) >= self::MIN_LEN && strlen($p) <= 200 && !preg_match('/[\x00-\x20"\\\\]/', $p);
	}

	/** nuBuilder's nuGenerateToken alphabet (A–Z a–z 0–9). */
	public static function generate(int $len = self::LEN): string
	{
		$a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$s = '';
		for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, 61)];
		return $s;
	}

	/** User row by login name (first) or user id, with the raw sus_json. */
	public static function findUser(string $user): ?array
	{
		if ($user === '' || !NuUserJson::available()) return null;
		$rows = nu_db()->all('SELECT zzzzsys_user_id, sus_login_name, sus_login_password, sus_expires_on, sus_zzzzsys_access_id, sus_language, sus_email, sus_name, sus_json
			FROM zzzzsys_user WHERE sus_login_name = ? OR zzzzsys_user_id = ?', [$user, $user]);
		foreach ($rows as $r) if ((string)$r['sus_login_name'] === $user) return $r;
		return $rows[0] ?? null;
	}

	/** 'ok' | 'expired' | 'invalid' for the token of $row. */
	public static function check(array $row, string $token): string
	{
		$j = json_decode((string)($row['sus_json'] ?? ''), true);
		$t = is_array($j) ? ($j[self::KEY] ?? null) : null;
		if (!is_string($t) || strlen($t) < self::MIN_LEN || !hash_equals($t, $token)) return 'invalid';
		$exp = (int)($j[self::EXPIRES] ?? 0);
		return $exp > 0 && $exp < time() ? 'expired' : 'ok';
	}

	/**
	 * Replaces the token by a new random one (nuBuilder) and drops the expiry – only if sus_json is still what was read
	 * (compare and swap: of two simultaneous clicks only one wins). false = somebody else was faster.
	 */
	public static function rotate(array $row): bool
	{
		$old = (string)($row['sus_json'] ?? '');
		$j = json_decode($old, true);
		if (!is_array($j)) return false;
		$j[self::KEY] = self::generate();
		unset($j[self::EXPIRES]);
		$st = nu_db()->run('UPDATE zzzzsys_user SET sus_json = ? WHERE zzzzsys_user_id = ? AND sus_json = ?',
			[json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)$row['zzzzsys_user_id'], $old]);
		return $st->rowCount() === 1;
	}

	/**
	 * New token for a user ($minutes 0 = no expiry); returns the link. $form / $record: form and record to open after the login.
	 * Throws RuntimeException (translated) when the user cannot get a token.
	 */
	public static function create(string $userId, int $minutes = 0, string $form = '', string $record = '', string $via = 'user form'): string
	{
		$row = nu_db()->one('SELECT zzzzsys_user_id, sus_login_name FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]);
		if (!$row) throw new RuntimeException(t('err_not_found'));
		if (!NuUserJson::available()) throw new RuntimeException(t('user_autologin_no_json'));
		if (class_exists('NuApiClient') && NuApiClient::isServiceUser($userId)) throw new RuntimeException(t('ltok_err_service'));
		$token = self::generate();
		$minutes = max(0, min(525600, $minutes));
		$j = NuUserJson::all($userId);
		$j[self::KEY] = $token;
		if ($minutes > 0) $j[self::EXPIRES] = time() + $minutes * 60; else unset($j[self::EXPIRES]);
		nu_db()->update('zzzzsys_user', ['sus_json' => json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'zzzzsys_user_id', $userId);
		NuLog::write('login_token_created', 'zzzzsys_user', $userId, ['login' => $row['sus_login_name'], 'valid_minutes' => $minutes ?: '∞', 'via' => $via]);
		return self::link((string)$row['sus_login_name'], $token, $form, $record);
	}

	/** index.php?u=<login name>&p=<token>[&f=&r=] (the format of nuBuilder's wiki – works in nuBuilder too). */
	public static function link(string $login, string $token, string $form = '', string $record = ''): string
	{
		$q = ['u' => $login, 'p' => $token];
		if ($form !== '' && preg_match('/^[\w-]{1,25}$/', $form)) {
			$q['f'] = $form;
			if ($record !== '' && preg_match('/^-?[\w-]{1,25}$/', $record)) $q['r'] = $record;
		}
		return nu_base_url() . '/index.php?' . http_build_query($q);
	}

	/** Expiry of the user's open token: 0 = none / no expiry; null = no token. */
	public static function state(string $userId): ?int
	{
		$j = NuUserJson::all($userId);
		$t = $j[self::KEY] ?? null;
		if (!is_string($t) || strlen($t) < self::MIN_LEN) return null;
		return (int)($j[self::EXPIRES] ?? 0);
	}

	public static function clear(string $userId, string $via = 'user form'): bool
	{
		$j = NuUserJson::all($userId);
		if (!array_key_exists(self::KEY, $j) && !array_key_exists(self::EXPIRES, $j)) return false;
		unset($j[self::KEY], $j[self::EXPIRES]);
		nu_db()->update('zzzzsys_user', ['sus_json' => $j ? json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null], 'zzzzsys_user_id', $userId);
		NuLog::write('login_token_cleared', 'zzzzsys_user', $userId, ['via' => $via]);
		return true;
	}

	/** Ids of the users that have a token (expired ones included – they are still stored). */
	public static function holders(): array
	{
		if (!NuUserJson::available()) return [];
		$out = [];
		foreach (nu_db()->all("SELECT zzzzsys_user_id, sus_json FROM zzzzsys_user WHERE sus_json LIKE '%LOGIN_TOKEN%'") as $r) {
			$j = json_decode((string)$r['sus_json'], true);
			if (is_array($j) && is_string($j[self::KEY] ?? null) && strlen($j[self::KEY]) >= self::MIN_LEN) $out[] = (string)$r['zzzzsys_user_id'];
		}
		return $out;
	}

	/** Removes all tokens (Security check). @return int number of users */
	public static function clearAll(): int
	{
		$n = 0;
		foreach (self::holders() as $id) if (self::clear($id, 'security check')) $n++;
		return $n;
	}

	/** Sends the link to the user's e-mail address, in the user's language. null = sent, otherwise the error. */
	public static function mail(string $userId, string $link, int $minutes): ?string
	{
		$u = nu_db()->one('SELECT zzzzsys_user_id, sus_login_name, sus_name, sus_email, sus_language FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]);
		$email = trim((string)($u['sus_email'] ?? ''));
		if (!$u || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return t('welcome_err_email');
		if (!NuMail::configured()) return t('welcome_err_mail');
		$lang = nu_lang_from_db((string)$u['sus_language']) ?? nu_lang();
		$prev = $_SESSION['nu_lang'] ?? null;
		$_SESSION['nu_lang'] = $lang;
		try {
			$name = trim((string)$u['sus_name']) ?: (string)$u['sus_login_name'];
			$p = static fn(string $s) => '<p style="margin:0 0 14px">' . $s . '</p>';
			$valid = $minutes > 0 ? t('ltok_mail_valid', self::duration($minutes)) : t('ltok_mail_once');
			$body = $p(h(t('welcome_hello', $name))) . $p(h(t('ltok_mail_intro')))
				. '<p style="margin:22px 0"><a href="' . h($link) . '" style="background:#2f6fd6;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">'
				. h(t('ltok_mail_button')) . '</a></p>'
				. $p('<span style="font-size:13px;color:#7a8394">' . h($valid) . '</span>')
				. $p('<span style="font-size:13px;color:#7a8394">' . h(t('ltok_mail_ignore')) . '</span>');
			$subject = t('ltok_mail_subject');
			$html = NuMail::layout($subject, $body);
		} finally {
			if ($prev === null) unset($_SESSION['nu_lang']); else $_SESSION['nu_lang'] = $prev;
		}
		$err = NuMail::send($email, $subject, $html, 'nunext_login_token');
		NuLog::write($err === null ? 'login_token_mailed' : 'login_token_mail_failed', 'zzzzsys_user', $userId, ['to' => $email] + ($err !== null ? ['error' => $err] : []));
		return $err;
	}

	/** "2 hours", "7 days" … */
	public static function duration(int $minutes): string
	{
		if ($minutes % 1440 === 0) return $minutes === 1440 ? t('user_autologin_day') : t('user_autologin_days', intdiv($minutes, 1440));
		if ($minutes % 60 === 0) return $minutes === 60 ? t('ltok_hour') : t('ltok_hours', intdiv($minutes, 60));
		return t('ltok_minutes', $minutes);
	}

	/** Choices of the user form (minutes; 0 = no expiry). */
	public const CHOICES = [60, 1440, 10080, 43200, 0];
}
