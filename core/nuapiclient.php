<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** REST API clients (Admin tools → API clients): credentials, scopes, service users, tokens, IP list, rate limit – see core/nuapi.php. */
final class NuApiClient
{
	public const CLIENT = 'zzzzsys_nunext_api_client';
	public const TOKEN  = 'zzzzsys_nunext_api_token';
	public const RATE   = 'zzzzsys_nunext_api_rate';

	/** scope => [nuBuilder form whose rights apply, right needed] (the right is checked again per action) */
	public const SCOPES = [
		'users:read'        => 'Read users',
		'users:write'       => 'Create, change, archive and delete users',
		'users:password'    => 'Set passwords / send welcome e-mails',
		'users:login_link'  => 'Create one-time login links (nuBuilder Forte\'s LOGIN_TOKEN)',
		'reports:run'       => 'Run reports (PDF download links, PDF, e-mail)',
		'exports:run'       => 'Run saved data exports (Excel / CSV files; the service user needs the admin right Data export)',
		'notifications:write' => 'Send notifications to users (bell) and read their read receipts',
		'access:read'       => 'Read access levels and their form rights',
		'access:write'      => 'Create, change and delete access levels and their form rights',
		'permissions:read'  => 'Read permission items and users\' permissions',
		'permissions:write' => 'Change permission items and users\' permissions',
		'system:update'     => 'Update nuBuilder Next (check and install a signed package – admin-level)',
	];

	public const MAX_FAILS = 10;

	public static function ensure(): void
	{
		if (!nu_db()->tableExists(self::CLIENT) || !nu_db()->tableExists(self::TOKEN) || !nu_db()->tableExists(self::RATE)) NuSchema::create();
	}

	public static function hash(string $secret): string
	{
		return hash_hmac('sha256', 'nunext-api|' . $secret, (string)nu_cfg('nuConfigSecretKey'));
	}

	/** Random value with a readable prefix (nk_… client id, ns_… secret, nt_… token). */
	private static function random(string $prefix, int $bytes): string
	{
		return $prefix . rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
	}

	public static function all(): array
	{
		self::ensure();
		$db = nu_db();
		return $db->all('SELECT c.*, u.sus_login_name, u.sus_zzzzsys_access_id, a.sal_code FROM ' . self::CLIENT . ' c
			LEFT JOIN zzzzsys_user u ON ' . $db->sameText('u.zzzzsys_user_id', 'c.user_id') . ' LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id ORDER BY c.name');
	}

	public static function get(string $id): ?array
	{
		self::ensure();
		return nu_db()->one('SELECT * FROM ' . self::CLIENT . ' WHERE zzzzsys_nunext_api_client_id = ?', [$id]);
	}

	/** True when the user is the service user of an API client (no interactive login, not changeable through the API). */
	public static function isServiceUser(string $userId): bool
	{
		try {
			$db = nu_db();
			return $userId !== '' && $db->tableExists(self::CLIENT) && (bool)$db->value('SELECT COUNT(*) FROM ' . self::CLIENT . ' WHERE user_id = ?', [$userId]);
		} catch (Throwable) {
			return false;
		}
	}

	/** Access levels of service users (not changeable through the API). */
	public static function serviceAccessLevels(): array
	{
		self::ensure();
		$db = nu_db();
		return array_values(array_filter(array_map('strval', array_column($db->all('SELECT u.sus_zzzzsys_access_id AS a FROM ' . self::CLIENT . ' c
			JOIN zzzzsys_user u ON ' . $db->sameText('u.zzzzsys_user_id', 'c.user_id')), 'a'))));
	}

	public static function scopesOf(array $client): array
	{
		$s = json_decode((string)$client['scopes'], true);
		return is_array($s) ? array_values(array_intersect(array_keys(self::SCOPES), $s)) : [];
	}

	/** Validates and saves name, scopes, service user, IP list, rate limit, expiry. @return array{0: ?string, 1: array, 2: ?array} [id, errors, credentials of a new client] */
	public static function save(array $in, string $id = ''): array
	{
		self::ensure();
		$db = nu_db();
		$errors = [];
		$name = trim((string)($in['name'] ?? ''));
		if ($name === '') $errors['name'] = t('err_required');
		nu_maxlen($errors, 'name', $name, 100);
		$scopes = array_values(array_intersect(array_keys(self::SCOPES), array_map('strval', (array)($in['scopes'] ?? []))));
		$userId = trim((string)($in['user_id'] ?? ''));
		$user = $userId !== '' ? $db->one('SELECT zzzzsys_user_id, sus_zzzzsys_access_id FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId]) : null;
		if (!$user) $errors['user_id'] = t('err_required');
		elseif ((string)$user['sus_zzzzsys_access_id'] === '') $errors['user_id'] = t('api_err_user_access');
		elseif ((int)$db->value('SELECT COUNT(*) FROM ' . self::CLIENT . ' WHERE user_id = ? AND zzzzsys_nunext_api_client_id <> ?', [$userId, $id])) $errors['user_id'] = t('api_err_user_taken');
		$ips = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($in['ip_allow'] ?? '')) ?: [])));
		foreach ($ips as $ip) if (!self::validIpRule($ip)) { $errors['ip_allow'] = t('api_err_ip', $ip); break; }
		$rate = max(0, min(100000, (int)($in['rate_limit'] ?? 120)));
		$exp = trim((string)($in['expires_on'] ?? ''));
		if ($exp !== '' && !nu_valid_date($exp)) $errors['expires_on'] = t('err_invalid');
		if ($errors) return [null, $errors, null];

		$row = ['name' => $name, 'scopes' => json_encode($scopes), 'user_id' => $userId, 'ip_allow' => $ips ? implode(' ', $ips) : null,
			'rate_limit' => $rate, 'active' => !empty($in['active']) ? '1' : '0', 'expires_on' => $exp === '' ? null : $db->datetime($exp)];
		$cred = null;
		if ($id === '') {
			$id = nu_id();
			$cred = ['client_id' => self::random('nk_', 12), 'client_secret' => self::random('ns_', 32)];
			$db->insert(self::CLIENT, ['zzzzsys_nunext_api_client_id' => $id, 'client_id' => $cred['client_id'], 'secret_hash' => self::hash($cred['client_secret']),
				'created_at' => date('Y-m-d H:i:s'), 'created_by' => (string)(NuAuth::user()['login'] ?? ''), 'fails' => 0] + $row);
			NuLog::write('api_client_created', self::CLIENT, $id, ['name' => $name, 'scopes' => $scopes, 'user_id' => $userId]);
		} else {
			$db->update(self::CLIENT, $row, 'zzzzsys_nunext_api_client_id', $id);
			if ($row['active'] === '0') self::revokeTokens($id);
			NuLog::write('api_client_changed', self::CLIENT, $id, ['name' => $name, 'scopes' => $scopes, 'active' => $row['active']]);
		}
		return [$id, [], $cred];
	}

	/** New secret; the old one stays valid for $graceHours (0 = at once invalid). @return string the new secret (shown once) */
	public static function rotate(string $id, int $graceHours = 24): string
	{
		$c = self::get($id);
		if (!$c) throw new RuntimeException(t('record_not_found'));
		$secret = self::random('ns_', 32);
		nu_db()->update(self::CLIENT, [
			'secret_hash' => self::hash($secret),
			'secret_hash_old' => $graceHours > 0 ? $c['secret_hash'] : null,
			'secret_old_until' => $graceHours > 0 ? date('Y-m-d H:i:s', time() + $graceHours * 3600) : null,
			'fails' => 0, 'locked_until' => null,
		], 'zzzzsys_nunext_api_client_id', $id);
		NuLog::write('api_secret_rotated', self::CLIENT, $id, ['grace_hours' => $graceHours]);
		return $secret;
	}

	public static function revokeTokens(string $id): int
	{
		self::ensure();
		$n = nu_db()->run('DELETE FROM ' . self::TOKEN . ' WHERE client_ref = ?', [$id])->rowCount();
		NuLog::write('api_tokens_revoked', self::CLIENT, $id, ['tokens' => $n]);
		return $n;
	}

	public static function delete(string $id): void
	{
		$db = nu_db();
		$db->run('DELETE FROM ' . self::TOKEN . ' WHERE client_ref = ?', [$id]);
		$db->delete(self::CLIENT, 'zzzzsys_nunext_api_client_id', $id);
		NuLog::write('api_client_deleted', self::CLIENT, $id);
	}

	/** Service user for a client: a user that can only be used by the API (random unknown password). */
	public static function createServiceUser(string $login, string $name, string $accessId): array
	{
		$db = nu_db();
		$errors = [];
		if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $login)) $errors['su_login'] = t('api_err_login');
		elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name = ?', [$login]) || strcasecmp($login, (string)nu_cfg('nuConfigDBGlobeadminUsername')) === 0) $errors['su_login'] = t('user_err_login_taken');
		if (!(int)$db->value('SELECT COUNT(*) FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$accessId])) $errors['su_access'] = t('err_required');
		if ($errors) return [null, $errors];
		$id = nu_id();
		$db->insert('zzzzsys_user', ['zzzzsys_user_id' => $id, 'sus_login_name' => $login, 'sus_name' => $name !== '' ? mb_substr($name, 0, 50) : $login,
			'sus_zzzzsys_access_id' => $accessId, 'sus_login_password' => NuAuth::hash(bin2hex(random_bytes(32))), 'sus_change_password' => '0']);
		NuLog::write('user_created', 'zzzzsys_user', $id, ['login' => $login, 'api_service_user' => true]);
		return [$id, []];
	}

	private static function validIpRule(string $r): bool
	{
		if (filter_var($r, FILTER_VALIDATE_IP)) return true;
		if (!preg_match('~^(.+)/(\d{1,3})$~', $r, $m) || !filter_var($m[1], FILTER_VALIDATE_IP)) return false;
		return (int)$m[2] <= (str_contains($m[1], ':') ? 128 : 32);
	}

	public static function ipAllowed(?string $rules, string $ip): bool
	{
		$rules = trim((string)$rules);
		if ($rules === '') return true;
		$bin = @inet_pton($ip);
		if ($bin === false) return false;
		foreach (preg_split('/\s+/', $rules) ?: [] as $r) {
			[$net, $bits] = array_pad(explode('/', $r, 2), 2, null);
			$nb = @inet_pton($net);
			if ($nb === false || strlen($nb) !== strlen($bin)) continue;
			$bits = $bits === null ? strlen($nb) * 8 : (int)$bits;
			$bytes = intdiv($bits, 8);
			if (substr($nb, 0, $bytes) !== substr($bin, 0, $bytes)) continue;
			$rest = $bits % 8;
			if ($rest === 0 || ((ord($nb[$bytes]) ^ ord($bin[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) return true;
		}
		return false;
	}

	// ----------------------------------------------------------------- tokens

	public static function tokenMinutes(): int
	{
		return max(1, min(1440, (int)(nu_cfg('nuConfigApiTokenMinutes') ?? 15) ?: 15));
	}

	/**
	 * Client credentials grant. @return array{0: ?array, 1: ?string} [token response, OAuth error code]
	 */
	public static function issue(string $clientId, string $secret, string $scope, string $ip): array
	{
		self::ensure();
		$db = nu_db();
		$c = $clientId !== '' ? $db->one('SELECT * FROM ' . self::CLIENT . ' WHERE client_id = ?', [$clientId]) : null;
		$fail = static function (string $why) use ($c, $clientId, $db): array {
			if ($c) {
				$f = (int)$c['fails'] + 1;
				$db->update(self::CLIENT, ['fails' => $f >= self::MAX_FAILS ? 0 : $f, 'locked_until' => $f >= self::MAX_FAILS ? date('Y-m-d H:i:s', time() + 900) : $c['locked_until']],
					'zzzzsys_nunext_api_client_id', $c['zzzzsys_nunext_api_client_id']);
			}
			NuLog::write('api_token_failed', self::CLIENT, (string)($c['zzzzsys_nunext_api_client_id'] ?? ''), ['client_id' => mb_substr($clientId, 0, 40), 'reason' => $why],
				['id' => (string)($c['user_id'] ?? ''), 'login' => 'api:' . mb_substr($clientId, 0, 40)]);
			usleep(random_int(200_000, 500_000));
			return [null, $why === 'invalid_scope' ? 'invalid_scope' : 'invalid_client'];
		};
		if (!$c) { self::hash($secret); return $fail('unknown client'); }
		if ($c['locked_until'] && strtotime((string)$c['locked_until']) > time()) return $fail('locked');
		$h = self::hash($secret);
		$ok = hash_equals((string)$c['secret_hash'], $h)
			|| ($c['secret_hash_old'] && $c['secret_old_until'] && strtotime((string)$c['secret_old_until']) > time() && hash_equals((string)$c['secret_hash_old'], $h));
		if (!$ok) return $fail('wrong secret');
		if ((string)$c['active'] !== '1') return $fail('inactive');
		if ($c['expires_on'] && nu_is_expired($c['expires_on'])) return $fail('expired');
		if (!self::ipAllowed($c['ip_allow'], $ip)) return $fail('ip not allowed');

		$granted = self::scopesOf($c);
		$want = $scope === '' ? $granted : array_values(array_unique(preg_split('/\s+/', trim($scope)) ?: []));
		if (array_diff($want, $granted)) return $fail('invalid_scope');
		return [self::grant($c, $want), null];
	}

	/**
	 * API playground: a token for a client without its secret (globeadmin only – the secret is never stored readable).
	 * Same checks as the token request except the secret; logged with via = playground.
	 * @return array{0: ?array, 1: ?string} [token response, error text]
	 */
	public static function issueFor(string $ref, string $scope, string $by): array
	{
		self::ensure();
		$c = self::get($ref);
		if (!$c) return [null, 'unknown client'];
		if ((string)$c['active'] !== '1') return [null, 'inactive'];
		if ($c['expires_on'] && nu_is_expired($c['expires_on'])) return [null, 'expired'];
		$granted = self::scopesOf($c);
		$want = trim($scope) === '' ? $granted : array_values(array_unique(preg_split('/\s+/', trim($scope)) ?: []));
		if (array_diff($want, $granted)) return [null, 'invalid_scope'];
		return [self::grant($c, $want, ['via' => 'playground', 'by' => $by]), null];
	}

	/** Stores a new access token (only its HMAC) and returns the OAuth token response. */
	private static function grant(array $c, array $want, array $log = []): array
	{
		$db = nu_db();
		$token = self::random('nt_', 32);
		$min = self::tokenMinutes();
		$db->insert(self::TOKEN, ['zzzzsys_nunext_api_token_id' => self::hash($token), 'client_ref' => $c['zzzzsys_nunext_api_client_id'],
			'scopes' => json_encode(array_values($want)), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + $min * 60)]);
		$db->update(self::CLIENT, ($log ? [] : ['fails' => 0, 'locked_until' => null]) + ['last_used_at' => date('Y-m-d H:i:s')],   // playground: lock stays
			'zzzzsys_nunext_api_client_id', $c['zzzzsys_nunext_api_client_id']);
		if (random_int(1, 20) === 1) $db->run('DELETE FROM ' . self::TOKEN . ' WHERE expires_at < ?', [date('Y-m-d H:i:s')]);
		NuLog::write('api_token_issued', self::CLIENT, (string)$c['zzzzsys_nunext_api_client_id'], ['scopes' => $want] + $log,
			['id' => (string)$c['user_id'], 'login' => 'api:' . $c['name']]);
		return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $min * 60, 'scope' => implode(' ', $want)];
	}

	/** Client, scopes and service user of a bearer token; null when invalid / expired / revoked. */
	public static function authenticate(string $token, string $ip): ?array
	{
		if (!preg_match('/^nt_[A-Za-z0-9_\-]{20,}$/', $token)) return null;
		self::ensure();
		$db = nu_db();
		$t = $db->one('SELECT * FROM ' . self::TOKEN . ' WHERE zzzzsys_nunext_api_token_id = ?', [self::hash($token)]);
		if (!$t || strtotime((string)$t['expires_at']) <= time()) return null;
		$c = self::get((string)$t['client_ref']);
		if (!$c || (string)$c['active'] !== '1' || ($c['expires_on'] && nu_is_expired($c['expires_on'])) || !self::ipAllowed($c['ip_allow'], $ip)) return null;
		$u = $db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [(string)$c['user_id']]);
		if (!$u || nu_is_expired($u['sus_expires_on']) || (string)$u['sus_zzzzsys_access_id'] === '') return null;
		$scopes = json_decode((string)$t['scopes'], true);
		return ['client' => $c, 'scopes' => array_values(array_intersect(is_array($scopes) ? $scopes : [], self::scopesOf($c))), 'user' => $u];
	}

	/** Counts a request; false when the client is over its limit for this minute. */
	public static function hit(array $client): bool
	{
		$limit = (int)$client['rate_limit'];
		if ($limit <= 0) return true;
		$db = nu_db();
		$key = $client['zzzzsys_nunext_api_client_id'] . '|' . date('YmdHi');
		$n = $db->run('UPDATE ' . self::RATE . ' SET hits = hits + 1 WHERE zzzzsys_nunext_api_rate_id = ?', [$key])->rowCount();
		if ($n === 0) {
			try { $db->insert(self::RATE, ['zzzzsys_nunext_api_rate_id' => $key, 'hits' => 1, 'minute_at' => date('Y-m-d H:i:00')]); }
			catch (Throwable) { $db->run('UPDATE ' . self::RATE . ' SET hits = hits + 1 WHERE zzzzsys_nunext_api_rate_id = ?', [$key]); }
			if (random_int(1, 50) === 1) $db->run('DELETE FROM ' . self::RATE . ' WHERE minute_at < ?', [date('Y-m-d H:i:s', time() - 3600)]);
		}
		return (int)$db->value('SELECT hits FROM ' . self::RATE . ' WHERE zzzzsys_nunext_api_rate_id = ?', [$key]) <= $limit;
	}
}
