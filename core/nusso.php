<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Single sign-on with OpenID Connect (0.8.863, the owner's choice A · 2.3 · 3.2 · 4.1): Microsoft Entra ID, Google Workspace, Keycloak,
 * Okta, Authentik, ADFS … – any provider with a discovery document (<issuer>/.well-known/openid-configuration).
 *
 *   flow      authorization code + PKCE (S256), state + nonce in the session (10 minutes, once); the provider sends the browser back to
 *             oauth.php (the address of the OAuth mail login – a state starting with "sso." routes it to ?p=sso).
 *   token     the id_token is checked here: signature (RS256 / RS384 / RS512 with the provider's JWKS, keys by kid), iss = the discovery
 *             issuer, aud = the client id, exp / iat / nbf, nonce. Verified e-mail only (email_verified false is refused).
 *   accounts  the provider's e-mail → zzzzsys_user by sus_email or sus_login_name (one match only); groups (claim "groups" or another)
 *             → access levels by the map; new users (option, off by default) get the mapped level or the default level.
 *   sign-in   NuAuth::loginSso: no own second factor (the provider's MFA), IP access applies; "SSO only" per access level refuses the
 *             password for those users (NuAuth::attempt); the globeadmin (nuconfig.php) always signs in with the password.
 *   settings  row 'sso' (secret sealed with $nuConfigSecretKey, as the OAuth mail login), page ?p=sso (globeadmin), discovery and keys
 *             cached in row 'sso_cache' for a day (fetched again when a kid is unknown).
 */
final class NuSso
{
	public const KEY = 'sso';
	public const CACHE = 'sso_cache';
	public const STATE_PREFIX = 'sso.';
	public const KINDS = ['entra', 'google', 'keycloak', 'other'];
	/** @var callable|null test hook: fn(string $url, ?array $post): array{0:int,1:string} */
	public static $transport = null;
	private static ?array $cache = null;

	public static function defaults(): array
	{
		return ['on' => false, 'kind' => 'entra', 'label' => '', 'issuer' => '', 'client_id' => '', 'client_secret' => '', 'scopes' => 'openid email profile',
			'domains' => [], 'create' => false, 'default_access' => '', 'group_claim' => 'groups', 'group_map' => [], 'sync' => true, 'sso_only' => []];
	}

	public static function settings(): array
	{
		if (self::$cache !== null) return self::$cache;
		$s = null;
		try {
			$v = nu_setting_value(self::KEY);
			$s = is_string($v) ? json_decode($v, true) : null;
		} catch (Throwable) {
		}
		return self::$cache = self::clean(is_array($s) ? $s : []);
	}

	public static function clean(array $s): array
	{
		$d = self::defaults();
		$o = [];
		$o['on'] = !empty($s['on']);
		$o['kind'] = in_array($s['kind'] ?? '', self::KINDS, true) ? (string)$s['kind'] : $d['kind'];
		$o['label'] = mb_substr(trim((string)($s['label'] ?? '')), 0, 60);
		$o['issuer'] = rtrim(trim((string)($s['issuer'] ?? '')), '/');
		$o['client_id'] = mb_substr(trim((string)($s['client_id'] ?? '')), 0, 255);
		$o['client_secret'] = (string)($s['client_secret'] ?? '');
		$o['scopes'] = trim((string)preg_replace('/\s+/', ' ', (string)($s['scopes'] ?? $d['scopes']))) ?: $d['scopes'];
		if (!preg_match('/(^| )openid( |$)/', $o['scopes'])) $o['scopes'] = 'openid ' . $o['scopes'];
		$dom = is_array($s['domains'] ?? null) ? $s['domains'] : preg_split('/[\s,;]+/', (string)($s['domains'] ?? ''));
		$o['domains'] = array_values(array_unique(array_filter(array_map(static fn($x) => strtolower(ltrim(trim((string)$x), '@')), (array)$dom),
			static fn($x) => $x !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $x))));
		$o['create'] = !empty($s['create']);
		$o['default_access'] = (string)($s['default_access'] ?? '');
		$o['group_claim'] = preg_match('/^[A-Za-z0-9_.:\/-]{1,100}$/', (string)($s['group_claim'] ?? '')) ? (string)$s['group_claim'] : $d['group_claim'];
		$map = [];
		foreach ((array)($s['group_map'] ?? []) as $m) {
			$g = trim((string)($m['group'] ?? ''));
			$a = (string)($m['access'] ?? '');
			if ($g !== '' && $a !== '') $map[] = ['group' => mb_substr($g, 0, 255), 'access' => $a];
		}
		$o['group_map'] = $map;
		$o['sync'] = array_key_exists('sync', $s) ? !empty($s['sync']) : $d['sync'];
		$o['sso_only'] = array_values(array_unique(array_filter(array_map('strval', (array)($s['sso_only'] ?? [])))));
		return $o;
	}

	/** Stores the settings; an empty secret keeps the stored one. @return array the stored settings */
	public static function save(array $in, string $by): array
	{
		$old = self::settings();
		$s = self::clean($in);
		$s['client_secret'] = (string)($in['client_secret'] ?? '') !== '' ? self::seal((string)$in['client_secret']) : $old['client_secret'];
		NuSchema::ensure();
		nu_setting_save(self::KEY, ['setting_value' => json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $by]);
		if ($old['issuer'] !== $s['issuer']) self::forgetCache();
		self::$cache = null;
		return self::settings();
	}

	/** Errors of posted settings (field => text). */
	public static function errors(array $s, bool $hasSecret): array
	{
		$e = [];
		if (!$s['on']) return $e;
		if (!self::validIssuer($s['issuer'])) $e['issuer'] = t('sso_err_issuer');
		if ($s['client_id'] === '') $e['client_id'] = t('err_required');
		if (!$hasSecret) $e['client_secret'] = t('err_required');
		if ($s['create'] && $s['default_access'] === '' && !$s['group_map']) $e['default_access'] = t('sso_err_create_level');
		return $e;
	}

	/** https, or http only to this computer (a test provider). */
	public static function validIssuer(string $u): bool
	{
		$p = parse_url($u);
		if (!$p || empty($p['host']) || !empty($p['query']) || !empty($p['fragment']) || !empty($p['user'])) return false;
		$scheme = strtolower((string)($p['scheme'] ?? ''));
		if ($scheme === 'https') return true;
		return $scheme === 'http' && in_array(strtolower((string)$p['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
	}

	public static function on(): bool
	{
		return self::settings()['on'];
	}

	/** On and complete: the sign-in page offers the button. */
	public static function ready(): bool
	{
		return self::settings()['on'] && self::complete();
	}

	/** Provider, client id and secret are set: Test sign-in works, also while switched off. */
	public static function complete(): bool
	{
		$s = self::settings();
		return $s['issuer'] !== '' && $s['client_id'] !== '' && $s['client_secret'] !== '';
	}

	public static function buttonLabel(): string
	{
		$s = self::settings();
		if ($s['label'] !== '') return $s['label'];
		return t('sso_button_' . $s['kind']);
	}

	public static function redirectUri(): string
	{
		return nu_base_url() . '/oauth.php';
	}

	/** The access level asks for SSO only (main level or an active additional one). Never the globeadmin. */
	public static function requiredFor(string $userId, string $accessId): bool
	{
		$s = self::settings();
		if (!$s['on'] || !$s['sso_only']) return false;
		$ids = $userId !== '' ? NuUserAccess::levels(['id' => $userId, 'access_id' => $accessId]) : [$accessId];
		return (bool)array_intersect($ids, $s['sso_only']);
	}

	// ================================================================= the flow

	/** Address of the provider's sign-in page; state, nonce and the PKCE verifier go into the session. */
	public static function start(bool $test = false): string
	{
		$s = self::settings();
		$meta = self::discovery();
		$state = self::STATE_PREFIX . bin2hex(random_bytes(20));
		$nonce = bin2hex(random_bytes(16));
		$verifier = self::b64url(random_bytes(48));
		$_SESSION['nunext_sso'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'at' => time(), 'test' => $test];
		$q = ['client_id' => $s['client_id'], 'response_type' => 'code', 'redirect_uri' => self::redirectUri(), 'scope' => $s['scopes'],
			'state' => $state, 'nonce' => $nonce, 'code_challenge' => self::b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256'];
		if ($s['kind'] === 'entra') $q += ['response_mode' => 'query', 'prompt' => 'select_account'];
		if ($s['kind'] === 'google') $q += ['prompt' => 'select_account'] + (count($s['domains']) === 1 ? ['hd' => $s['domains'][0]] : []);
		$auth = (string)$meta['authorization_endpoint'];
		return $auth . (str_contains($auth, '?') ? '&' : '?') . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Back from the provider: state checked (once), code exchanged, id_token verified. @return array{claims: array, test: bool}
	 * @throws RuntimeException with a text for the person (and a reason for the log in getCode() === 0)
	 */
	public static function finish(array $get): array
	{
		$st = $_SESSION['nunext_sso'] ?? null;
		unset($_SESSION['nunext_sso']);
		if (!is_array($st) || !is_string($get['state'] ?? null) || !hash_equals((string)$st['state'], (string)$get['state']) || (int)$st['at'] < time() - 600) {
			throw new RuntimeException(t('sso_err_state'));
		}
		if (isset($get['error'])) throw new RuntimeException(t('sso_err_provider', mb_substr(trim((string)$get['error'] . ' – ' . (string)($get['error_description'] ?? ''), ' –'), 0, 300)));
		$code = (string)($get['code'] ?? '');
		if ($code === '') throw new RuntimeException(t('sso_err_provider', 'no code'));
		$s = self::settings();
		$meta = self::discovery();
		$j = self::post((string)$meta['token_endpoint'], ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri(),
			'client_id' => $s['client_id'], 'client_secret' => self::open($s['client_secret']), 'code_verifier' => (string)$st['verifier']]);
		$idt = (string)($j['id_token'] ?? '');
		if ($idt === '') throw new RuntimeException(t('sso_err_provider', 'no id_token'));
		$claims = self::verify($idt, (string)$meta['issuer'], $s['client_id'], (string)$st['nonce']);
		return ['claims' => $claims, 'test' => !empty($st['test'])];
	}

	/** Checks the id_token and returns its claims. */
	public static function verify(string $jwt, string $issuer, string $clientId, string $nonce, ?array $jwks = null, ?int $now = null): array
	{
		$p = explode('.', $jwt);
		if (count($p) !== 3) throw new RuntimeException(t('sso_err_token', 'format'));
		$h = json_decode(self::b64urlDecode($p[0]), true);
		$c = json_decode(self::b64urlDecode($p[1]), true);
		if (!is_array($h) || !is_array($c)) throw new RuntimeException(t('sso_err_token', 'format'));
		$alg = (string)($h['alg'] ?? '');
		$algos = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
		if (!isset($algos[$alg])) throw new RuntimeException(t('sso_err_token', 'alg ' . $alg));
		$kid = (string)($h['kid'] ?? '');
		$key = self::findKey($jwks ?? self::jwks(), $kid);
		if ($key === null && $jwks === null) $key = self::findKey(self::jwks(true), $kid);   // keys rotated: fetch once more
		if ($key === null) throw new RuntimeException(t('sso_err_token', 'unknown key ' . $kid));
		$pem = self::jwkToPem($key);
		$ok = $pem !== '' && openssl_verify($p[0] . '.' . $p[1], self::b64urlDecode($p[2]), $pem, $algos[$alg]) === 1;
		if (!$ok) throw new RuntimeException(t('sso_err_token', 'signature'));
		$now ??= time();
		$aud = $c['aud'] ?? null;
		if ((string)($c['iss'] ?? '') !== $issuer) throw new RuntimeException(t('sso_err_token', 'issuer'));
		if (!(is_array($aud) ? in_array($clientId, $aud, true) : (string)$aud === $clientId)) throw new RuntimeException(t('sso_err_token', 'audience'));
		if (is_array($aud) && count($aud) > 1 && (string)($c['azp'] ?? '') !== $clientId) throw new RuntimeException(t('sso_err_token', 'azp'));
		if ((int)($c['exp'] ?? 0) < $now - 60) throw new RuntimeException(t('sso_err_token', 'expired'));
		if (isset($c['nbf']) && (int)$c['nbf'] > $now + 60) throw new RuntimeException(t('sso_err_token', 'not yet valid'));
		if ((int)($c['iat'] ?? 0) > $now + 300) throw new RuntimeException(t('sso_err_token', 'issued in the future'));
		if (!hash_equals($nonce, (string)($c['nonce'] ?? ''))) throw new RuntimeException(t('sso_err_token', 'nonce'));
		return $c;
	}

	/** E-mail of the claims: email, else preferred_username / upn holding an @ (Entra). '' = none or not verified. */
	public static function email(array $c): string
	{
		if (array_key_exists('email_verified', $c) && ($c['email_verified'] === false || $c['email_verified'] === 'false')) return '';
		foreach (['email', 'preferred_username', 'upn'] as $k) {
			$v = strtolower(trim((string)($c[$k] ?? '')));
			if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) return $v;
		}
		return '';
	}

	/** Groups of the claims (the configured claim; a string or a list). */
	public static function groups(array $c): array
	{
		$v = $c[self::settings()['group_claim']] ?? [];
		$v = is_array($v) ? $v : ($v === '' || $v === null ? [] : [$v]);
		return array_values(array_map('strval', array_filter($v, 'is_scalar')));
	}

	/** Access level from the group map (first matching entry, case-insensitive), '' = no match. */
	public static function mappedAccess(array $groups, ?array $s = null): string
	{
		$s ??= self::settings();
		$lower = array_map('strtolower', $groups);
		foreach ($s['group_map'] as $m) if (in_array(strtolower($m['group']), $lower, true)) return $m['access'];
		return '';
	}

	/**
	 * What a sign-in with these claims does – used by the sign-in and by "Test sign-in".
	 * @return array{ok: bool, error: string, email: string, name: string, groups: array, user: ?array, access: string, create: bool, change: bool}
	 */
	public static function resolve(array $c): array
	{
		$s = self::settings();
		$out = ['ok' => false, 'error' => '', 'email' => self::email($c), 'name' => trim((string)($c['name'] ?? '')), 'groups' => self::groups($c),
			'user' => null, 'access' => '', 'create' => false, 'change' => false];
		$email = $out['email'];
		if ($email === '') { $out['error'] = 'sso_err_no_email'; return $out; }
		if ($s['domains'] && !in_array(substr($email, strrpos($email, '@') + 1), $s['domains'], true)) { $out['error'] = 'sso_err_domain'; return $out; }
		$db = nu_db();
		$rows = $db->all('SELECT zzzzsys_user_id, sus_login_name, sus_login_password, sus_email, sus_name, sus_expires_on, sus_zzzzsys_access_id, sus_language FROM zzzzsys_user
			WHERE LOWER(sus_email) = ? OR LOWER(sus_login_name) = ?', [$email, $email]);
		$rows = array_values(array_filter($rows, static fn($r) => !(class_exists('NuApiClient') && NuApiClient::isServiceUser((string)$r['zzzzsys_user_id']))));
		if (count($rows) > 1) {
			$exact = array_values(array_filter($rows, static fn($r) => strtolower((string)$r['sus_email']) === $email));
			$rows = count($exact) === 1 ? $exact : $rows;
		}
		if (count($rows) > 1) { $out['error'] = 'sso_err_ambiguous'; return $out; }
		$mapped = self::mappedAccess($out['groups'], $s);
		if (!$rows) {
			if (!$s['create']) { $out['error'] = 'sso_err_no_user'; return $out; }
			$out['access'] = $mapped !== '' ? $mapped : $s['default_access'];
			$out['create'] = true;
		} else {
			$out['user'] = $rows[0];
			$cur = (string)$rows[0]['sus_zzzzsys_access_id'];
			$out['access'] = $s['sync'] && $mapped !== '' ? $mapped : $cur;
			$out['change'] = $out['access'] !== $cur;
		}
		if ($out['access'] === '' || !$db->one('SELECT zzzzsys_access_id FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$out['access']])) {
			$out['error'] = 'login_err_no_access';
			return $out;
		}
		$out['ok'] = true;
		return $out;
	}

	/** Creates the user of a resolved sign-in (option "create users"). @return array the new row */
	public static function createUser(array $r): array
	{
		$db = nu_db();
		$email = $r['email'];
		$login = mb_strlen($email) <= 50 ? $email : mb_substr(strstr($email, '@', true) ?: 'sso', 0, 40) . '_' . bin2hex(random_bytes(4));
		if ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name = ?', [$login])) $login = mb_substr($login, 0, 40) . '_' . bin2hex(random_bytes(4));
		$id = nu_id();
		$name = $r['name'] !== '' ? $r['name'] : $email;
		$db->insert('zzzzsys_user', ['zzzzsys_user_id' => $id, 'sus_login_name' => $login, 'sus_email' => mb_substr($email, 0, 255), 'sus_name' => mb_substr($name, 0, 50),
			'sus_zzzzsys_access_id' => $r['access'], 'sus_login_password' => NuAuth::hash(bin2hex(random_bytes(32))), 'sus_change_password' => '0']);
		NuUserJson::stamp($id, true, 'sso');
		NuLog::write('sso_user_created', 'zzzzsys_user', $id, ['login' => $login, 'access' => $r['access'], 'groups' => implode(', ', array_slice($r['groups'], 0, 20))]);
		return (array)$db->one('SELECT zzzzsys_user_id, sus_login_name, sus_login_password, sus_email, sus_name, sus_expires_on, sus_zzzzsys_access_id, sus_language FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$id]);
	}

	/** The access level of a signed-in user follows the group map (option "keep in step"). */
	public static function syncAccess(array $row, string $access): void
	{
		nu_db()->update('zzzzsys_user', ['sus_zzzzsys_access_id' => $access], 'zzzzsys_user_id', $row['zzzzsys_user_id']);
		NuLog::write('sso_access_changed', 'zzzzsys_user', (string)$row['zzzzsys_user_id'], ['from' => (string)$row['sus_zzzzsys_access_id'], 'to' => $access]);
	}

	// ================================================================= provider documents

	public static function discovery(bool $fresh = false): array
	{
		$s = self::settings();
		if (!self::validIssuer($s['issuer'])) throw new RuntimeException(t('sso_err_issuer'));
		$c = self::cache();
		if (!$fresh && ($c['issuer'] ?? '') === $s['issuer'] && is_array($c['meta'] ?? null) && (int)($c['at'] ?? 0) > time() - 86400) return $c['meta'];
		$m = self::getJson($s['issuer'] . '/.well-known/openid-configuration');
		foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) if (empty($m[$k]) || !is_string($m[$k])) throw new RuntimeException(t('sso_err_discovery', $k));
		foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) if (!self::validIssuer(preg_replace('/\?.*$/', '', $m[$k]) ?? '')) throw new RuntimeException(t('sso_err_discovery', $k));
		self::storeCache(['issuer' => $s['issuer'], 'at' => time(), 'meta' => $m, 'jwks' => null]);
		return $m;
	}

	public static function jwks(bool $fresh = false): array
	{
		$meta = self::discovery();
		$c = self::cache();
		if (!$fresh && is_array($c['jwks'] ?? null) && (int)($c['jwks_at'] ?? 0) > time() - 86400) return $c['jwks'];
		$j = self::getJson((string)$meta['jwks_uri']);
		$keys = is_array($j['keys'] ?? null) ? $j['keys'] : [];
		self::storeCache(array_merge(self::cache(), ['jwks' => $keys, 'jwks_at' => time()]));
		return $keys;
	}

	private static function findKey(array $keys, string $kid): ?array
	{
		$rsa = array_values(array_filter($keys, static fn($k) => is_array($k) && ($k['kty'] ?? '') === 'RSA' && !empty($k['n']) && !empty($k['e']) && ($k['use'] ?? 'sig') === 'sig'));
		foreach ($rsa as $k) if ($kid !== '' && (string)($k['kid'] ?? '') === $kid) return $k;
		return $kid === '' && count($rsa) === 1 ? $rsa[0] : null;
	}

	/** RSA public key of a JWK as PEM (SubjectPublicKeyInfo). */
	public static function jwkToPem(array $k): string
	{
		$n = self::b64urlDecode((string)($k['n'] ?? ''));
		$e = self::b64urlDecode((string)($k['e'] ?? ''));
		if ($n === '' || $e === '') return '';
		$int = static function (string $b): string {
			$b = ltrim($b, "\0");
			if ($b === '' || ord($b[0]) > 0x7f) $b = "\0" . $b;
			return "\x02" . self::derLen(strlen($b)) . $b;
		};
		$seq = static fn(string $b): string => "\x30" . self::derLen(strlen($b)) . $b;
		$rsaKey = $seq($int($n) . $int($e));
		$algId = $seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");   // rsaEncryption, NULL
		$bits = "\x03" . self::derLen(strlen($rsaKey) + 1) . "\0" . $rsaKey;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($seq($algId . $bits)), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	private static function derLen(int $l): string
	{
		if ($l < 0x80) return chr($l);
		$b = ltrim(pack('N', $l), "\0");
		return chr(0x80 | strlen($b)) . $b;
	}

	private static function cache(): array
	{
		try {
			$v = nu_setting_value(self::CACHE);
			$c = is_string($v) ? json_decode($v, true) : null;
			return is_array($c) ? $c : [];
		} catch (Throwable) {
			return [];
		}
	}

	private static function storeCache(array $c): void
	{
		try {
			NuSchema::ensure();
			nu_setting_save(self::CACHE, ['setting_value' => json_encode($c, JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => 'sso']);
		} catch (Throwable $e) {
			error_log('nuBuilder Next SSO cache: ' . $e->getMessage());
		}
	}

	public static function forgetCache(): void
	{
		self::storeCache([]);
	}

	// ================================================================= HTTP

	private static function getJson(string $url): array
	{
		[$code, $body] = self::http($url, null);
		$j = json_decode($body, true);
		if ($code >= 400 || !is_array($j)) throw new RuntimeException(t('sso_err_fetch', (string)parse_url($url, PHP_URL_HOST), (string)$code));
		return $j;
	}

	private static function post(string $url, array $params): array
	{
		[$code, $body] = self::http($url, $params);
		$j = json_decode($body, true);
		if (!is_array($j)) throw new RuntimeException(t('sso_err_fetch', (string)parse_url($url, PHP_URL_HOST), (string)$code));
		if ($code >= 400 || isset($j['error'])) throw new RuntimeException(t('sso_err_provider', mb_substr(trim((string)($j['error'] ?? 'HTTP ' . $code) . ' – ' . (string)($j['error_description'] ?? ''), ' –'), 0, 300)));
		return $j;
	}

	/** @return array{0:int,1:string} */
	private static function http(string $url, ?array $post): array
	{
		if (self::$transport) return (self::$transport)($url, $post);
		if (!function_exists('curl_init')) throw new RuntimeException(t('sso_err_curl'));
		$ch = curl_init($url);
		$opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP];
		if ($post !== null) $opt += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post, '', '&')];
		curl_setopt_array($ch, $opt);
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($body === false) throw new RuntimeException(t('sso_err_fetch', (string)parse_url($url, PHP_URL_HOST), $err ?: '0'));
		return [$code, (string)$body];
	}

	// ================================================================= helpers

	public static function b64url(string $b): string
	{
		return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
	}

	public static function b64urlDecode(string $s): string
	{
		$d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
		return $d === false ? '' : $d;
	}

	/** Secret storage as the OAuth mail login (libsodium with $nuConfigSecretKey, else "plain:"). */
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

	public static function plainStored(): bool
	{
		return str_starts_with(self::settings()['client_secret'], 'plain:');
	}
}
