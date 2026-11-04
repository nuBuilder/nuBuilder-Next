<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Passkeys (WebAuthn, 0.8.877) – a second factor besides the authenticator app and the e-mail code, and (setting passkey_login)
 * signing in without a password. Our own checker, no library: the browser's navigator.credentials.create / get (assets/js/nupasskey.js)
 * send the authenticator's answer, we check the client data (type, challenge, origin), the authenticator data (RP id hash, flags,
 * counter) and the signature with the public key stored at registration – ES256 / RS256 with openssl, Ed25519 with sodium.
 * Attestation "none": the maker's certificate is not checked (normal for company applications).
 * Table zzzzsys_nunext_passkey (pk = sha256 of the raw credential id, user_id = zzzzsys_user_id or 'globeadmin').
 * Ceremonies: one challenge per kind (reg / 2fa / login / reauth) in the session, used up by the first answer, TTL seconds.
 * The relying party is the host of nu_base_url() ($nuConfigBaseURL) – a changed address makes the stored passkeys useless.
 */
final class NuPasskey
{
	public const TABLE = 'zzzzsys_nunext_passkey';
	public const MAX_PER_USER = 20;
	public const TTL = 300;
	public const ALGS = [-7 => 'ES256', -8 => 'EdDSA', -257 => 'RS256'];
	private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

	/** Test seam: fixed origin / RP id (run.php). */
	public static ?string $testOrigin = null;

	// ================================================================= availability

	/** Users may add passkeys and use them as a second factor (Security settings "passkeys"). */
	public static function on(): bool
	{
		return (bool)NuSecurity::get('passkeys');
	}

	/** Signing in with a passkey alone (Security settings "passkey_login"). */
	public static function loginOn(): bool
	{
		return self::on() && (bool)NuSecurity::get('passkey_login');
	}

	public static function origin(): string
	{
		if (self::$testOrigin !== null) return self::$testOrigin;
		$u = parse_url(nu_base_url());
		if (!is_array($u) || empty($u['host'])) return '';
		return strtolower((string)($u['scheme'] ?? 'https')) . '://' . strtolower((string)$u['host']) . (isset($u['port']) ? ':' . $u['port'] : '');
	}

	public static function rpId(): string
	{
		return strtolower((string)parse_url(self::origin(), PHP_URL_HOST));
	}

	/** Browsers offer passkeys only on https (or localhost) and for a domain name (not an IP address). */
	public static function secure(): bool
	{
		$host = self::rpId();
		if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) return false;
		return str_starts_with(self::origin(), 'https://') || $host === 'localhost' || str_ends_with($host, '.localhost');
	}

	/** Why passkeys cannot be used here ('' = they can): a text key. */
	public static function problem(): string
	{
		if (!self::on()) return 'pk_off';
		if (!self::secure()) return 'pk_need_https';
		if (!function_exists('openssl_verify')) return 'pk_need_openssl';
		return '';
	}

	public static function usable(): bool
	{
		return self::problem() === '';
	}

	public static function loginReady(): bool
	{
		try {
			return self::loginOn() && self::usable() && NuSchema::present() && nu_db()->tableExists(self::TABLE);
		} catch (Throwable) {
			return false;                                       // no database yet (installer, broken nuconfig.php)
		}
	}

	/** A passkey button (assets/js/nupasskey.js) with its texts, followed by its message box. */
	public static function button(string $kind, string $label, string $cls = 'btn btn-outline-primary w-100 py-2', string $id = ''): string
	{
		$err = 'nuPkErr_' . $kind;
		return '<button type="button" class="' . h($cls) . '"' . ($id !== '' ? ' id="' . h($id) . '"' : '') . ' data-nu-pk="' . h($kind) . '" data-url="' . h(nu_url('passkey')) . '"'
			. ' data-err="#' . $err . '" data-t-cancel="' . h(t('pk_js_cancel')) . '" data-t-unsupported="' . h(t('pk_js_unsupported')) . '"'
			. ' data-t-exists="' . h(t('pk_js_exists')) . '" data-t-failed="' . h(t('pk_js_failed')) . '">'
			. '<i class="bi bi-fingerprint me-2" aria-hidden="true"></i>' . h($label) . '</button>'
			. '<div class="small text-danger mt-2" id="' . $err . '" role="alert" hidden></div>';
	}

	public static function script(): string
	{
		return '<script src="' . nu_asset('assets/js/nupasskey.js') . '"></script>';
	}

	// ================================================================= stored passkeys

	/** @return list<array> the passkeys of a user, newest first */
	public static function list(string $userId): array
	{
		if (!nu_db()->tableExists(self::TABLE)) return [];
		return nu_db()->all('SELECT * FROM ' . self::TABLE . ' WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
	}

	public static function count(string $userId): int
	{
		if (!nu_db()->tableExists(self::TABLE)) return 0;
		return (int)nu_db()->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE user_id = ?', [$userId]);
	}

	public static function has(string $userId): bool
	{
		return self::usable() && self::count($userId) > 0;
	}

	/** Removes one passkey of the user; true when it was there. */
	public static function remove(string $userId, string $id): bool
	{
		if (!nu_db()->tableExists(self::TABLE)) return false;
		return nu_db()->run('DELETE FROM ' . self::TABLE . ' WHERE ' . self::TABLE . '_id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0;
	}

	/** Removes every passkey of a user (lost device, nucron.php passkeys off <login>); the number removed. */
	public static function removeAll(string $userId): int
	{
		if (!nu_db()->tableExists(self::TABLE)) return 0;
		return nu_db()->run('DELETE FROM ' . self::TABLE . ' WHERE user_id = ?', [$userId])->rowCount();
	}

	/** Housekeeping: passkeys of users that were deleted (also in nuBuilder Forte). */
	public static function purgeOrphans(): int
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return 0;
		$ids = array_column($db->all('SELECT DISTINCT user_id FROM ' . self::TABLE . " WHERE user_id <> 'globeadmin'"), 'user_id');
		$n = 0;
		foreach ($ids as $id) {
			if (!$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$id])) $n += self::removeAll((string)$id);
		}
		return $n;
	}

	// ================================================================= registration

	/** Options for navigator.credentials.create() (binary values base64url); starts the ceremony 'reg'. */
	public static function registrationOptions(array $u): array
	{
		$challenge = random_bytes(32);
		$handle = self::userHandle((string)$u['id']);
		$_SESSION['nu_passkey']['reg'] = ['c' => self::b64($challenge), 'user' => (string)$u['id'], 't' => time(), 'h' => $handle];
		$exclude = [];
		foreach (self::list((string)$u['id']) as $r) {
			$exclude[] = ['type' => 'public-key', 'id' => (string)$r['cred_id']] + ((string)$r['transports'] !== '' ? ['transports' => explode(',', (string)$r['transports'])] : []);
		}
		$title = function_exists('nu_app_title') ? nu_app_title() : '';
		return [
			'challenge' => self::b64($challenge),
			'rp' => ['id' => self::rpId(), 'name' => $title !== '' ? $title : 'nuBuilder Next'],
			'user' => ['id' => $handle, 'name' => (string)$u['login'], 'displayName' => (string)($u['display'] ?? $u['login'])],
			'pubKeyCredParams' => array_map(static fn($a) => ['type' => 'public-key', 'alg' => $a], array_keys(self::ALGS)),
			'timeout' => 120000,
			'attestation' => 'none',
			'authenticatorSelection' => ['residentKey' => 'preferred', 'requireResidentKey' => false, 'userVerification' => 'preferred'],
			'excludeCredentials' => $exclude,
		];
	}

	/**
	 * The browser's answer to create(): checked and stored. @return array{ok: bool, error?: string, id?: string}
	 * $cred = PublicKeyCredential as JSON {id, rawId, type, response: {clientDataJSON, attestationObject, transports}}.
	 */
	public static function register(array $u, array $cred, string $name): array
	{
		$cer = self::take('reg');
		if (!$cer || $cer['user'] !== (string)$u['id']) return ['ok' => false, 'error' => 'pk_err_expired'];
		try {
			$raw = self::b64d((string)($cred['rawId'] ?? $cred['id'] ?? ''));
			$resp = is_array($cred['response'] ?? null) ? $cred['response'] : [];
			$cdRaw = self::b64d((string)($resp['clientDataJSON'] ?? ''));
			self::checkClientData($cdRaw, 'webauthn.create', $cer['c']);
			$att = NuCbor::decode(self::b64d((string)($resp['attestationObject'] ?? '')));
			if (!is_array($att) || !is_string($att['authData'] ?? null)) throw new RuntimeException('attestation object');
			$ad = self::authData($att['authData']);
			if (!$ad['at']) throw new RuntimeException('no credential data');
			if ($raw === '' || !hash_equals($ad['credId'], $raw)) throw new RuntimeException('credential id');
			if (strlen($raw) > 1023) throw new RuntimeException('credential id too long');
			[$alg, $key] = self::coseKey($ad['cose']);
		} catch (Throwable $e) {
			NuLog::write('passkey_failed', self::TABLE, '', ['step' => 'register', 'reason' => $e->getMessage()]);
			return ['ok' => false, 'error' => 'pk_err_check'];
		}
		$id = hash('sha256', $raw);
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) NuSchema::ensure();
		if ($db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . self::TABLE . '_id = ?', [$id])) return ['ok' => false, 'error' => 'pk_err_exists'];
		if (self::count((string)$u['id']) >= self::MAX_PER_USER) return ['ok' => false, 'error' => 'pk_err_max'];
		$name = mb_substr(trim(preg_replace('/\s+/', ' ', $name) ?? ''), 0, 100);
		$transports = array_values(array_filter((array)($resp['transports'] ?? []), static fn($t) => is_string($t) && preg_match('/^[a-z-]{2,20}$/', $t)));
		$db->insert(self::TABLE, [
			self::TABLE . '_id' => $id, 'user_id' => (string)$u['id'], 'cred_id' => self::b64($raw), 'public_key' => $key, 'alg' => $alg,
			'sign_count' => $ad['count'], 'user_handle' => (string)$cer['h'], 'name' => $name !== '' ? $name : self::defaultName(),
			'aaguid' => self::uuid($ad['aaguid']), 'transports' => implode(',', array_slice($transports, 0, 6)), 'backup' => $ad['be'] ? '1' : '0',
			'created_at' => date('Y-m-d H:i:s'), 'last_used_at' => null,
		]);
		NuLog::write('passkey_added', self::TABLE, $id, ['name' => $name, 'alg' => self::ALGS[$alg] ?? (string)$alg, 'synced' => $ad['be']]);
		return ['ok' => true, 'id' => $id];
	}

	private static function defaultName(): string
	{
		$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
		foreach (['iPhone', 'iPad', 'Android', 'Windows', 'Macintosh' => 'Mac', 'Linux'] as $k => $v) {
			$needle = is_string($k) ? $k : $v;
			if (stripos($ua, $needle) !== false) return t('pk_default_name', $v);
		}
		return t('pk_default_name', date('Y-m-d'));
	}

	// ================================================================= sign-in (second factor, passwordless, confirmation)

	/**
	 * Options for navigator.credentials.get(); starts the ceremony $kind ('2fa', 'login', 'reauth').
	 * $userId: the passkeys allowed (second factor, confirmation); null = any passkey of this site (passwordless – discoverable).
	 */
	public static function assertionOptions(string $kind, ?string $userId): array
	{
		$challenge = random_bytes(32);
		$_SESSION['nu_passkey'][$kind] = ['c' => self::b64($challenge), 'user' => $userId ?? '', 't' => time()];
		$allow = [];
		if ($userId !== null) {
			foreach (self::list($userId) as $r) {
				$allow[] = ['type' => 'public-key', 'id' => (string)$r['cred_id']] + ((string)$r['transports'] !== '' ? ['transports' => explode(',', (string)$r['transports'])] : []);
			}
		}
		return ['challenge' => self::b64($challenge), 'rpId' => self::rpId(), 'timeout' => 120000, 'allowCredentials' => $allow,
			'userVerification' => $kind === '2fa' ? 'preferred' : 'required'];
	}

	/**
	 * The browser's answer to get(): the passkey's row when everything is right, else null ($error = text key).
	 * $cred = {id, rawId, type, response: {clientDataJSON, authenticatorData, signature, userHandle}}.
	 */
	public static function verify(string $kind, array $cred, ?string &$error = null): ?array
	{
		$error = 'pk_err_check';
		$cer = self::take($kind);
		if (!$cer) { $error = 'pk_err_expired'; return null; }
		$db = nu_db();
		$row = null;
		try {
			$raw = self::b64d((string)($cred['rawId'] ?? $cred['id'] ?? ''));
			if ($raw === '' || !$db->tableExists(self::TABLE)) throw new RuntimeException('unknown passkey');
			$row = $db->one('SELECT * FROM ' . self::TABLE . ' WHERE ' . self::TABLE . '_id = ?', [hash('sha256', $raw)]);
			if (!$row) { $error = 'pk_err_unknown'; throw new RuntimeException('unknown passkey'); }
			if ($cer['user'] !== '' && $cer['user'] !== (string)$row['user_id']) { $error = 'pk_err_unknown'; throw new RuntimeException('passkey of another user'); }
			$resp = is_array($cred['response'] ?? null) ? $cred['response'] : [];
			$uh = (string)($resp['userHandle'] ?? '');
			if ($uh !== '' && !hash_equals((string)$row['user_handle'], self::b64(self::b64d($uh)))) throw new RuntimeException('user handle');
			$cdRaw = self::b64d((string)($resp['clientDataJSON'] ?? ''));
			self::checkClientData($cdRaw, 'webauthn.get', $cer['c']);
			$adRaw = self::b64d((string)($resp['authenticatorData'] ?? ''));
			$ad = self::authData($adRaw);
			if ($kind !== '2fa' && !$ad['uv']) { $error = 'pk_err_uv'; throw new RuntimeException('user not verified'); }
			$sig = self::b64d((string)($resp['signature'] ?? ''));
			if (!self::verifySignature((int)$row['alg'], (string)$row['public_key'], $adRaw . hash('sha256', $cdRaw, true), $sig)) throw new RuntimeException('signature');
			$stored = (int)$row['sign_count'];
			if (($ad['count'] > 0 || $stored > 0) && $ad['count'] <= $stored) { $error = 'pk_err_clone'; throw new RuntimeException('counter did not grow (copied passkey?)'); }
		} catch (Throwable $e) {
			NuLog::write('passkey_failed', self::TABLE, (string)($row[self::TABLE . '_id'] ?? ''), ['step' => $kind, 'reason' => $e->getMessage()],
				$row ? ['id' => (string)$row['user_id'], 'login' => ''] : null);
			return null;
		}
		$db->run('UPDATE ' . self::TABLE . ' SET sign_count = ?, last_used_at = ?, backup = ? WHERE ' . self::TABLE . '_id = ?',
			[$ad['count'], date('Y-m-d H:i:s'), $ad['be'] ? '1' : '0', $row[self::TABLE . '_id']]);
		$error = null;
		return $row;
	}

	/** One use: the ceremony's challenge leaves the session with the first answer. */
	private static function take(string $kind): ?array
	{
		$c = $_SESSION['nu_passkey'][$kind] ?? null;
		unset($_SESSION['nu_passkey'][$kind]);
		if (!is_array($c) || time() - (int)($c['t'] ?? 0) > self::TTL) return null;
		return $c;
	}

	/** Test seam (run.php): puts a ceremony into the session as the options call would. */
	public static function testCeremony(string $kind, string $challengeB64, string $user, string $handle = ''): void
	{
		$_SESSION['nu_passkey'][$kind] = ['c' => $challengeB64, 'user' => $user, 't' => time(), 'h' => $handle];
	}

	// ================================================================= checks

	private static function checkClientData(string $raw, string $type, string $challenge): void
	{
		$cd = json_decode($raw, true);
		if (!is_array($cd)) throw new RuntimeException('client data');
		if (($cd['type'] ?? '') !== $type) throw new RuntimeException('client data type');
		if (!is_string($cd['challenge'] ?? null) || !hash_equals($challenge, self::b64(self::b64d($cd['challenge'])))) throw new RuntimeException('challenge');
		if (!is_string($cd['origin'] ?? null) || strtolower(rtrim($cd['origin'], '/')) !== self::origin()) throw new RuntimeException('origin ' . substr((string)($cd['origin'] ?? ''), 0, 100));
		if (!empty($cd['crossOrigin'])) throw new RuntimeException('cross origin');
	}

	/**
	 * Authenticator data: rpIdHash (32) · flags (1) · counter (4) [· aaguid (16) · id length (2) · credential id · COSE key].
	 * @return array{up: bool, uv: bool, be: bool, at: bool, count: int, aaguid: string, credId: string, cose: mixed}
	 */
	public static function authData(string $ad): array
	{
		if (strlen($ad) < 37) throw new RuntimeException('authenticator data too short');
		if (!hash_equals(hash('sha256', self::rpId(), true), substr($ad, 0, 32))) throw new RuntimeException('rp id');
		$f = ord($ad[32]);
		$out = ['up' => (bool)($f & 0x01), 'uv' => (bool)($f & 0x04), 'be' => (bool)($f & 0x08), 'at' => (bool)($f & 0x40),
			'count' => (int)unpack('N', substr($ad, 33, 4))[1], 'aaguid' => '', 'credId' => '', 'cose' => null];
		if (!$out['up']) throw new RuntimeException('user not present');
		if ($out['at']) {
			if (strlen($ad) < 55) throw new RuntimeException('credential data too short');
			$out['aaguid'] = substr($ad, 37, 16);
			$len = (int)unpack('n', substr($ad, 53, 2))[1];
			$out['credId'] = substr($ad, 55, $len);
			if (strlen($out['credId']) !== $len) throw new RuntimeException('credential id');
			$pos = 55 + $len;
			$out['cose'] = NuCbor::decodeAt($ad, $pos);
		}
		return $out;
	}

	/** COSE public key → [alg, stored key]: a PEM for ES256 / RS256, "ed25519:<base64>" for EdDSA. */
	public static function coseKey(mixed $k): array
	{
		if (!is_array($k)) throw new RuntimeException('public key');
		$kty = $k[1] ?? null;
		$alg = $k[3] ?? null;
		if (!is_int($alg) || !isset(self::ALGS[$alg])) throw new RuntimeException('algorithm ' . json_encode($alg));
		if ($alg === -7) {
			if ($kty !== 2 || ($k[-1] ?? null) !== 1 || !is_string($k[-2] ?? null) || !is_string($k[-3] ?? null) || strlen($k[-2]) !== 32 || strlen($k[-3]) !== 32) throw new RuntimeException('EC key');
			$der = (string)hex2bin(self::P256_SPKI) . "\x04" . $k[-2] . $k[-3];
			return [$alg, "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n"];
		}
		if ($alg === -257) {
			if ($kty !== 3 || !is_string($k[-1] ?? null) || !is_string($k[-2] ?? null) || strlen($k[-1]) < 256) throw new RuntimeException('RSA key');
			$pem = NuSso::jwkToPem(['n' => self::b64($k[-1]), 'e' => self::b64($k[-2])]);
			if ($pem === '') throw new RuntimeException('RSA key');
			return [$alg, $pem];
		}
		if ($kty !== 1 || ($k[-1] ?? null) !== 6 || !is_string($k[-2] ?? null) || strlen($k[-2]) !== 32) throw new RuntimeException('Ed25519 key');
		if (!function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('sodium missing');
		return [$alg, 'ed25519:' . base64_encode($k[-2])];
	}

	public static function verifySignature(int $alg, string $key, string $data, string $sig): bool
	{
		if ($sig === '') return false;
		if ($alg === -8) {
			if (!str_starts_with($key, 'ed25519:') || !function_exists('sodium_crypto_sign_verify_detached') || strlen($sig) !== 64) return false;
			$pk = (string)base64_decode(substr($key, 8), true);
			return strlen($pk) === 32 && sodium_crypto_sign_verify_detached($sig, $data, $pk);
		}
		if (!isset(self::ALGS[$alg]) || !function_exists('openssl_verify')) return false;
		return openssl_verify($data, $sig, $key, OPENSSL_ALGO_SHA256) === 1;
	}

	// ================================================================= helpers

	/** The user handle of a user: the one of the first passkey, else new random bytes (never the user id or a name). */
	private static function userHandle(string $userId): string
	{
		if (nu_db()->tableExists(self::TABLE)) {
			$h = nu_db()->value('SELECT user_handle FROM ' . self::TABLE . ' WHERE user_id = ? ORDER BY created_at', [$userId]);
			if (is_string($h) && $h !== '') return $h;
		}
		return self::b64(random_bytes(32));
	}

	private static function uuid(string $b): string
	{
		if (strlen($b) !== 16) return '';
		$h = bin2hex($b);
		return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
	}

	public static function b64(string $bin): string
	{
		return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
	}

	public static function b64d(string $s): string
	{
		if ($s === '' || !preg_match('~^[A-Za-z0-9_\-+/]*={0,2}$~', $s)) return '';
		return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
	}
}

/** A small CBOR decoder (RFC 8949) for WebAuthn: definite lengths only, byte and text strings both as PHP strings. */
final class NuCbor
{
	public static function decode(string $data): mixed
	{
		$pos = 0;
		$v = self::decodeAt($data, $pos);
		if ($pos !== strlen($data)) throw new RuntimeException('CBOR: trailing bytes');
		return $v;
	}

	public static function decodeAt(string $data, int &$pos, int $depth = 0): mixed
	{
		if ($depth > 16) throw new RuntimeException('CBOR: too deep');
		$n = strlen($data);
		if ($pos >= $n) throw new RuntimeException('CBOR: end of data');
		$b = ord($data[$pos++]);
		$major = $b >> 5;
		$ai = $b & 0x1f;
		if ($major === 7) {
			return match ($ai) {
				20 => false, 21 => true, 22, 23 => null,
				25 => self::half(self::bytes($data, $pos, 2)),
				26 => unpack('G', self::bytes($data, $pos, 4))[1],
				27 => unpack('E', self::bytes($data, $pos, 8))[1],
				default => throw new RuntimeException('CBOR: simple value ' . $ai),
			};
		}
		$len = self::length($data, $pos, $ai);
		switch ($major) {
			case 0: return $len;
			case 1: return -1 - $len;
			case 2:
			case 3: return self::bytes($data, $pos, $len);
			case 4:
				if ($len > 10000) throw new RuntimeException('CBOR: array too long');
				$a = [];
				for ($i = 0; $i < $len; $i++) $a[] = self::decodeAt($data, $pos, $depth + 1);
				return $a;
			case 5:
				if ($len > 10000) throw new RuntimeException('CBOR: map too long');
				$m = [];
				for ($i = 0; $i < $len; $i++) {
					$k = self::decodeAt($data, $pos, $depth + 1);
					if (!is_int($k) && !is_string($k)) throw new RuntimeException('CBOR: map key');
					$m[$k] = self::decodeAt($data, $pos, $depth + 1);
				}
				return $m;
			case 6: return self::decodeAt($data, $pos, $depth + 1);   // a tag: the tagged value
		}
		throw new RuntimeException('CBOR: major type');
	}

	private static function length(string $data, int &$pos, int $ai): int
	{
		if ($ai < 24) return $ai;
		$v = match ($ai) {
			24 => ord(self::bytes($data, $pos, 1)),
			25 => (int)unpack('n', self::bytes($data, $pos, 2))[1],
			26 => (int)unpack('N', self::bytes($data, $pos, 4))[1],
			27 => (int)unpack('J', self::bytes($data, $pos, 8))[1],
			default => throw new RuntimeException('CBOR: indefinite length'),
		};
		if ($v < 0) throw new RuntimeException('CBOR: length');
		return $v;
	}

	private static function bytes(string $data, int &$pos, int $len): string
	{
		if ($len < 0 || $pos + $len > strlen($data)) throw new RuntimeException('CBOR: end of data');
		$s = substr($data, $pos, $len);
		$pos += $len;
		return $s;
	}

	private static function half(string $b): float
	{
		$h = (int)unpack('n', $b)[1];
		$exp = ($h >> 10) & 0x1f;
		$mant = $h & 0x3ff;
		$v = $exp === 0 ? $mant * 2 ** -24 : ($exp !== 31 ? ($mant + 1024) * 2 ** ($exp - 25) : ($mant === 0 ? INF : NAN));
		return ($h & 0x8000) ? -$v : $v;
	}
}
