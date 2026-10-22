<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Second factor: authenticator app (TOTP, RFC 6238) or a code by e-mail, plus "trusted devices".
 * Required for globeadmin when $nuConfig2FAAdmin is true and for users whose access level has
 * sal_use_2fa = '1' when $nuConfig2FAUser is true (same switches as nuBuilder).
 */
final class NuTwoFA
{
	public const CODE_TTL = 600;        // e-mail code: 10 minutes
	public const RESEND_AFTER = 30;
	public const MAX_FAILS = 5;

	// ================================================================= TOTP

	public static function newSecret(): string
	{
		return self::base32Encode(random_bytes(20));
	}

	public static function hasTotp(string $userId): bool
	{
		NuSchema::ensure();
		$s = nu_db()->value('SELECT totp_secret FROM zzzzsys_nunext_user_2fa WHERE zzzzsys_nunext_user_2fa_id = ?', [$userId]);
		return $s !== null && (string)$s !== '';
	}

	public static function saveTotp(string $userId, string $secret, int $step): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['totp_secret' => self::seal($secret), 'created_at' => date('Y-m-d H:i:s'), 'last_step' => $step];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_user_2fa WHERE zzzzsys_nunext_user_2fa_id = ?', [$userId])) {
			$db->update('zzzzsys_nunext_user_2fa', $row, 'zzzzsys_nunext_user_2fa_id', $userId);
		} else {
			$db->insert('zzzzsys_nunext_user_2fa', ['zzzzsys_nunext_user_2fa_id' => $userId] + $row);
		}
	}

	public static function removeTotp(string $userId): void
	{
		NuSchema::ensure();
		nu_db()->delete('zzzzsys_nunext_user_2fa', 'zzzzsys_nunext_user_2fa_id', $userId);
	}

	/** Step of a matching code (±1 step clock drift) or null. */
	public static function matchCode(string $secret, string $code, int $minStep = 0): ?int
	{
		$code = preg_replace('/\s+/', '', $code);
		if (!preg_match('/^\d{6}$/', $code)) return null;
		$now = intdiv(time(), 30);
		for ($s = $now - 1; $s <= $now + 1; $s++) {
			if ($s > $minStep && hash_equals(self::code($secret, $s), $code)) return $s;
		}
		return null;
	}

	/** Checks a TOTP code of a user; a code can only be used once (replay protection). */
	public static function verifyTotp(string $userId, string $code): bool
	{
		NuSchema::ensure();
		$row = nu_db()->one('SELECT totp_secret, last_step FROM zzzzsys_nunext_user_2fa WHERE zzzzsys_nunext_user_2fa_id = ?', [$userId]);
		if (!$row) return false;
		$secret = self::open((string)$row['totp_secret']);
		if ($secret === '') return false;
		$step = self::matchCode($secret, $code, (int)$row['last_step']);
		if ($step === null) return false;
		nu_db()->update('zzzzsys_nunext_user_2fa', ['last_step' => $step], 'zzzzsys_nunext_user_2fa_id', $userId);
		return true;
	}

	public static function code(string $secret, int $step): string
	{
		$key  = self::base32Decode($secret);
		$hash = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
		$o    = ord($hash[19]) & 0xf;
		$num  = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
		return str_pad((string)($num % 1000000), 6, '0', STR_PAD_LEFT);
	}

	public static function otpauthUri(string $secret, string $account): string
	{
		$issuer = (string)(nu_cfg('nuConfig2FAIssuer') ?: 'nuBuilder Next');
		return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
	}

	// ================================================================= e-mail code

	/** @return string|null error key/message, null = sent */
	public static function sendMailCode(array &$pending): ?string
	{
		$email = trim((string)$pending['email']);
		if ($email === '' || !NuMail::configured()) return 'twofa_err_no_mail';
		if (time() - (int)$pending['sent'] < self::RESEND_AFTER) return 'twofa_err_wait';

		$userId = (string)$pending['auth']['id'];
		$code   = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
		NuToken::deleteFor('2fa_mail', $userId);
		NuToken::create('2fa_mail', $userId, self::CODE_TTL, $code);

		$err = NuMail::send($email, t('twofa_mail_subject'), NuMail::layout(t('twofa_mail_subject'),
			'<p>' . h(t('twofa_mail_text')) . '</p><p style="font-size:30px;font-weight:700;letter-spacing:6px;margin:18px 0">' . $code . '</p>'
			. '<p style="color:#7a8394;font-size:13px">' . h(t('twofa_mail_hint', (int)(self::CODE_TTL / 60))) . '</p>'), 'nunext_2fa');
		if ($err !== null) return $err;

		$pending['sent'] = time();
		NuLog::write('2fa_code_sent', '', '', ['to' => self::maskEmail($email)], $pending['auth']);
		return null;
	}

	public static function verifyMailCode(string $userId, string $code): bool
	{
		$code = preg_replace('/\s+/', '', $code);
		if (!preg_match('/^\d{6}$/', $code)) return false;
		$row = NuToken::find('2fa_mail', $userId, $code);
		if (!$row) return false;
		NuToken::delete($row['zzzzsys_nunext_token_id']);
		return true;
	}

	public static function maskEmail(string $email): string
	{
		[$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
		$keep = mb_substr($local, 0, min(2, mb_strlen($local)));
		return $keep . str_repeat('•', max(1, mb_strlen($local) - mb_strlen($keep))) . ($domain !== '' ? '@' . $domain : '');
	}

	// ================================================================= trusted devices

	public static function trustDays(): int
	{
		$d = nu_cfg('nuConfig2FATrustDays');
		return $d === null ? 30 : max(0, (int)$d);
	}

	private static function deviceCookie(string $userId): string
	{
		return 'nunext_td_' . substr(hash('sha256', $userId), 0, 12);
	}

	public static function trustDevice(string $userId): void
	{
		$days = self::trustDays();
		if ($days <= 0) return;
		$token = NuToken::create('device', $userId, $days * 86400);
		setcookie(self::deviceCookie($userId), $token, ['expires' => time() + $days * 86400, 'path' => NuSecurity::cookiePath(), 'secure' => nu_https(), 'httponly' => true, 'samesite' => 'Lax']);
	}

	public static function trustedDevice(string $userId): bool
	{
		if (self::trustDays() <= 0) return false;
		$token = $_COOKIE[self::deviceCookie($userId)] ?? '';
		return is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) && NuToken::find('device', $userId, $token) !== null;
	}

	public static function forgetDevices(string $userId): void
	{
		NuToken::deleteFor('device', $userId);
	}

	public static function deviceCount(string $userId): int
	{
		NuSchema::ensure();
		return (int)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_nunext_token WHERE kind = ? AND user_id = ? AND expires_at > ?', ['device', $userId, date('Y-m-d H:i:s')]);
	}

	// ================================================================= secret storage

	/** Encrypts the TOTP secret when $nuConfigSecretKey is set (libsodium), otherwise stores it as is. */
	private static function seal(string $secret): string
	{
		$key = (string)nu_cfg('nuConfigSecretKey');
		if ($key === '' || !function_exists('sodium_crypto_secretbox')) return $secret;
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, hash('sha256', $key, true)));
	}

	/** Encrypts authenticator secrets stored as plain text (saved while $nuConfigSecretKey was empty) with the new $key. @return int count */
	public static function sealPlain(string $key): int
	{
		if ($key === '' || !function_exists('sodium_crypto_secretbox')) return 0;
		NuSchema::ensure();
		$n = 0;
		foreach (nu_db()->all('SELECT zzzzsys_nunext_user_2fa_id, totp_secret FROM zzzzsys_nunext_user_2fa') as $r) {
			$s = (string)$r['totp_secret'];
			if ($s === '' || str_starts_with($s, 'enc:')) continue;
			$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$enc = 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($s, $nonce, hash('sha256', $key, true)));
			nu_db()->update('zzzzsys_nunext_user_2fa', ['totp_secret' => $enc], 'zzzzsys_nunext_user_2fa_id', (string)$r['zzzzsys_nunext_user_2fa_id']);
			$n++;
		}
		return $n;
	}

	/** Number of authenticator secrets stored without encryption (Security check). */
	public static function plainCount(): int
	{
		try {
			NuSchema::ensure();
			return (int)nu_db()->value("SELECT COUNT(*) FROM zzzzsys_nunext_user_2fa WHERE totp_secret IS NOT NULL AND totp_secret <> '' AND totp_secret NOT LIKE 'enc:%'");
		} catch (Throwable) {
			return 0;
		}
	}

	private static function open(string $stored): string
	{
		if (!str_starts_with($stored, 'enc:')) return $stored;
		$key = (string)nu_cfg('nuConfigSecretKey');
		if ($key === '' || !function_exists('sodium_crypto_secretbox_open')) return '';
		$raw = base64_decode(substr($stored, 4), true);
		if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
		$plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash('sha256', $key, true));
		return $plain === false ? '' : $plain;
	}

	// ================================================================= base32

	public static function base32Encode(string $bin): string
	{
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits = '';
		foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
		$out = '';
		foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
		return $out;
	}

	public static function base32Decode(string $b32): string
	{
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
		$bits = '';
		foreach (str_split($b32) as $c) $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
		$out = '';
		foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
		return $out;
	}
}
