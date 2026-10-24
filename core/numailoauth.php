<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * SMTP login with OAuth 2 (XOAUTH2, 0.8.445) – Google, Microsoft 365 or another provider, without Composer / League libraries.
 *   Settings: row 'mail_oauth' of zzzzsys_nunext_setting (JSON) – provider, tenant (Microsoft), client id, client secret, refresh token,
 *             account (the mailbox), connected time, cached access token + expiry, and for "other" the authorize / token address and scope.
 *             Client secret, refresh and access token are encrypted with $nuConfigSecretKey (libsodium; "enc:"), never sent to the browser.
 *   Connect:  pages/numailoauth.php (reached as oauth.php – a redirect address without a query string, as Google / Microsoft want it):
 *             start → the provider's consent page (state in the session) → back with ?code → exchange() stores the refresh token and the
 *             mailbox (from the id_token).
 *   Sending:  NuMailer::send / check with auth type XOAUTH2 → tokenProvider() (PHPMailer's OAuthTokenProvider, core/numailoauthtoken.php):
 *             accessToken() refreshes with the refresh token when the cached one runs out (a new refresh token from the answer is kept).
 */
final class NuMailOAuth
{
	public const SETTING = 'mail_oauth';
	public const PROVIDERS = ['google', 'microsoft', 'other'];
	/** provider => [authorize address, token address, scope]; {tenant} for Microsoft. */
	private const ENDPOINTS = [
		'google'    => ['https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'https://mail.google.com/ openid email'],
		'microsoft' => ['https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize', 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
			'offline_access https://outlook.office.com/SMTP.Send openid email'],
	];
	/** Suggested SMTP server per provider (E-mail settings: "Use …"). */
	public const SERVERS = ['google' => ['smtp.gmail.com', 587], 'microsoft' => ['smtp.office365.com', 587]];
	private const SECRET = ['client_secret', 'refresh_token', 'access_token'];

	private static ?array $cache = null;

	/** The stored settings, secrets decrypted. */
	public static function settings(): array
	{
		if (self::$cache !== null) return self::$cache;
		$v = [];
		try {
			$raw = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$v = is_string($raw) ? (array)(json_decode($raw, true) ?: []) : [];
		} catch (Throwable) {
		}
		foreach (self::SECRET as $k) $v[$k] = isset($v[$k]) ? self::open((string)$v[$k]) : '';
		$v += ['provider' => 'google', 'tenant' => 'common', 'client_id' => '', 'account' => '', 'connected_at' => '', 'access_expires' => 0,
			'auth_url' => '', 'token_url' => '', 'scope' => ''];
		if (!in_array($v['provider'], self::PROVIDERS, true)) $v['provider'] = 'google';
		return self::$cache = $v;
	}

	/** Writes the settings (secrets encrypted). */
	private static function store(array $v): void
	{
		$out = $v;
		foreach (self::SECRET as $k) $out[$k] = (string)($v[$k] ?? '') === '' ? '' : self::seal((string)$v[$k]);
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
			'updated_by' => (string)(NuAuth::user()['login'] ?? '')];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
		self::$cache = null;
	}

	/**
	 * Saves the fields of the E-mail settings: provider, tenant, client id, client secret (empty = keep), for "other" the addresses and scope.
	 * Another provider, client or tenant forgets the connection (its refresh token belongs to the old one). @return list<string> changed fields
	 */
	public static function save(array $in): array
	{
		$v = self::settings();
		$new = $v;
		$new['provider'] = in_array($in['provider'] ?? '', self::PROVIDERS, true) ? (string)$in['provider'] : $v['provider'];
		$new['tenant'] = preg_replace('/[^A-Za-z0-9.\-]/', '', trim((string)($in['tenant'] ?? $v['tenant']))) ?: 'common';
		$new['client_id'] = trim((string)($in['client_id'] ?? $v['client_id']));
		if (trim((string)($in['client_secret'] ?? '')) !== '') $new['client_secret'] = trim((string)$in['client_secret']);
		foreach (['auth_url', 'token_url'] as $k) {
			$u = trim((string)($in[$k] ?? $v[$k]));
			$new[$k] = preg_match('~^https?://~i', $u) ? $u : '';
		}
		$new['scope'] = trim((string)($in['scope'] ?? $v['scope']));
		$changed = [];
		foreach (['provider', 'tenant', 'client_id', 'client_secret', 'auth_url', 'token_url', 'scope'] as $k) if ((string)$new[$k] !== (string)$v[$k]) $changed[] = $k;
		if (!$changed) return [];
		if (array_intersect($changed, ['provider', 'tenant', 'client_id', 'auth_url', 'token_url'])) self::forget($new);
		self::store($new);
		return $changed;
	}

	private static function forget(array &$v): void
	{
		$v['refresh_token'] = $v['access_token'] = $v['account'] = $v['connected_at'] = '';
		$v['access_expires'] = 0;
	}

	/** Removes the connection (refresh token, mailbox); the client settings stay. */
	public static function disconnect(): void
	{
		$v = self::settings();
		self::forget($v);
		self::store($v);
	}

	public static function connected(): bool
	{
		$v = self::settings();
		return $v['refresh_token'] !== '' && $v['client_id'] !== '';
	}

	/** Client id, secret and (for "other") the addresses are there – "Connect account" can start. */
	public static function ready(): bool
	{
		$v = self::settings();
		if ($v['client_id'] === '' || $v['client_secret'] === '') return false;
		return $v['provider'] !== 'other' || ($v['auth_url'] !== '' && $v['token_url'] !== '');
	}

	/** [authorize address, token address, scope] of the stored provider. */
	public static function endpoints(?array $v = null): array
	{
		$v ??= self::settings();
		if ($v['provider'] === 'other') return [(string)$v['auth_url'], (string)$v['token_url'], (string)$v['scope']];
		[$a, $t, $s] = self::ENDPOINTS[$v['provider']];
		$tenant = (string)($v['tenant'] ?: 'common');
		return [str_replace('{tenant}', rawurlencode($tenant), $a), str_replace('{tenant}', rawurlencode($tenant), $t), $s];
	}

	/** The address the provider sends the browser back to – to be entered in the Google / Azure console. */
	public static function redirectUri(): string
	{
		return nu_base_url() . '/oauth.php';
	}

	/** Consent page of the provider (state: random, kept in the session by the caller). */
	public static function authorizeUrl(string $state): string
	{
		$v = self::settings();
		[$auth, , $scope] = self::endpoints($v);
		$q = ['client_id' => $v['client_id'], 'redirect_uri' => self::redirectUri(), 'response_type' => 'code', 'scope' => $scope, 'state' => $state];
		if ($v['provider'] === 'google') $q += ['access_type' => 'offline', 'prompt' => 'consent'];   // a refresh token every time
		if ($v['provider'] === 'microsoft') $q += ['response_mode' => 'query', 'prompt' => 'select_account'];
		return $auth . (str_contains($auth, '?') ? '&' : '?') . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
	}

	/** Code from the consent page → refresh token + mailbox stored. @return string the mailbox ('' when the provider did not say) */
	public static function exchange(string $code): string
	{
		$v = self::settings();
		$j = self::tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()], $v);
		if (empty($j['refresh_token'])) throw new RuntimeException('no refresh token in the answer (Google: remove the app\'s access at myaccount.google.com/permissions and connect again)');
		$v['refresh_token'] = (string)$j['refresh_token'];
		$v['access_token'] = (string)($j['access_token'] ?? '');
		$v['access_expires'] = $v['access_token'] !== '' ? time() + max(60, (int)($j['expires_in'] ?? 3600)) - 60 : 0;
		$v['account'] = self::accountOf((string)($j['id_token'] ?? ''));
		$v['connected_at'] = date('Y-m-d H:i:s');
		self::store($v);
		return $v['account'];
	}

	/** E-mail address in an id_token (taken straight from the token endpoint over TLS – the signature is not checked). */
	private static function accountOf(string $idToken): string
	{
		$p = explode('.', $idToken);
		if (count($p) < 2) return '';
		$c = json_decode((string)base64_decode(strtr($p[1], '-_', '+/') . str_repeat('=', (4 - strlen($p[1]) % 4) % 4)), true);
		if (!is_array($c)) return '';
		foreach (['email', 'preferred_username', 'upn'] as $k) if (!empty($c[$k]) && str_contains((string)$c[$k], '@')) return (string)$c[$k];
		return '';
	}

	/** A valid access token – the cached one, or a new one from the refresh token. */
	public static function accessToken(bool $force = false): string
	{
		$v = self::settings();
		if ($v['refresh_token'] === '') throw new RuntimeException('OAuth 2: no account connected (E-mail → Settings → Connect account)');
		if (!$force && $v['access_token'] !== '' && (int)$v['access_expires'] > time()) return $v['access_token'];
		$j = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $v['refresh_token']], $v);
		if (empty($j['access_token'])) throw new RuntimeException('OAuth 2: no access token in the answer');
		$v['access_token'] = (string)$j['access_token'];
		$v['access_expires'] = time() + max(60, (int)($j['expires_in'] ?? 3600)) - 60;
		if (!empty($j['refresh_token'])) $v['refresh_token'] = (string)$j['refresh_token'];   // Microsoft hands out a new one now and then
		self::store($v);
		return $v['access_token'];
	}

	/** POST to the token address (form encoded), JSON answer; errors as RuntimeException with the provider's message. */
	private static function tokenRequest(array $params, array $v): array
	{
		[, $url, $scope] = self::endpoints($v);
		if ($url === '') throw new RuntimeException('OAuth 2: no token address');
		$params += ['client_id' => $v['client_id'], 'client_secret' => $v['client_secret']];
		if ($v['provider'] === 'microsoft' && $scope !== '') $params['scope'] = $scope;
		if (!function_exists('curl_init')) throw new RuntimeException('OAuth 2: the PHP extension curl is missing');
		$ch = curl_init($url);
		curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params, '', '&'), CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($body === false) throw new RuntimeException('OAuth 2: ' . ($err ?: 'no answer from ' . parse_url($url, PHP_URL_HOST)));
		$j = json_decode((string)$body, true);
		if (!is_array($j)) throw new RuntimeException('OAuth 2: unexpected answer (HTTP ' . $code . ')');
		if ($code >= 400 || isset($j['error'])) {
			throw new RuntimeException('OAuth 2: ' . trim((string)($j['error'] ?? 'HTTP ' . $code) . ' – ' . (string)($j['error_description'] ?? '')), $code);
		}
		return $j;
	}

	/** PHPMailer's token provider for the mailbox $user (the connected account when empty). */
	public static function tokenProvider(string $user = ''): object
	{
		NuMailer::loadPHPMailer();
		require_once NU_ROOT . '/core/numailoauthtoken.php';
		return new NuMailOAuthToken($user !== '' ? $user : self::settings()['account']);
	}

	// ================================================================= secret storage (as NuTwoFA: libsodium with $nuConfigSecretKey)

	private static function seal(string $s): string
	{
		$key = (string)nu_cfg('nuConfigSecretKey');
		if ($key === '' || !function_exists('sodium_crypto_secretbox')) return 'plain:' . $s;
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($s, $nonce, hash('sha256', $key, true)));
	}

	private static function open(string $s): string
	{
		if (str_starts_with($s, 'plain:')) return substr($s, 6);
		if (!str_starts_with($s, 'enc:')) return $s;
		$key = (string)nu_cfg('nuConfigSecretKey');
		$raw = base64_decode(substr($s, 4), true);
		if ($key === '' || $raw === false || !function_exists('sodium_crypto_secretbox_open') || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
		$out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash('sha256', $key, true));
		return $out === false ? '' : $out;
	}

	/** The secrets are stored without encryption ($nuConfigSecretKey empty) – Security check. */
	public static function plainStored(): bool
	{
		try {
			$raw = (string)nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
		} catch (Throwable) {
			return false;
		}
		return str_contains($raw, '"plain:');
	}
}
