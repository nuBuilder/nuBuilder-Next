<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * REST API (api.php/v1/…), phase 1: users, access levels with their form rights, permission items.
 *
 * Authentication: OAuth 2.0 client credentials. A client (Admin tools → API clients) has a public client_id and a secret that is
 * shown once; POST /v1/token exchanges them for a short-lived opaque access token, sent as "Authorization: Bearer …".
 * Secrets and tokens are stored only as HMAC-SHA256 with $nuConfigSecretKey and compared in constant time.
 *
 * Permissions, two layers: the client's scopes (what it may do at all) AND the rights of its service user's access level
 * (zzzzsys_access_form, the same rights as in the browser). A service user cannot log in interactively, is never the globeadmin,
 * and neither it nor its access level can be changed through the API (no privilege escalation).
 *
 * Switched on with $nuConfigApi = true; HTTPS only (except localhost, or $nuConfigApiAllowHttp = true for tests).
 */
/** Error that becomes a problem+json response (RFC 9457). */
final class NuApiError extends RuntimeException
{
	public function __construct(public readonly int $status, string $title, public readonly array $errors = [], public readonly array $headers = [])
	{
		parent::__construct($title);
	}
}

final class NuApi
{
	private static array $ctx = [];
	/** @var (callable(): void)|null work after the answer was sent (async report runs) */
	private static $after = null;

	// ================================================================= entry point

	public static function handle(): void
	{
		header('Content-Type: application/json; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: no-store');
		header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
		header('Referrer-Policy: no-referrer');
		$_SESSION = [];
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$path = '/' . trim((string)($_SERVER['PATH_INFO'] ?? ($_GET['path'] ?? '')), '/');
		$status = 200;
		try {
			nu_config();
			nu_apply_timezone();
			if (!nu_cfg('nuConfigApi')) throw new NuApiError(404, 'The API is switched off ($nuConfigApi).');
			if (!self::secure()) throw new NuApiError(403, 'HTTPS is required.');
			if ($method === 'OPTIONS') throw new NuApiError(405, 'Cross-origin requests are not allowed.');

			if ($path === '/v1/token') {
				if ($method !== 'POST') throw new NuApiError(405, 'Method not allowed.', [], ['Allow' => 'POST']);
				self::token();
				return;
			}
			$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '')));
			if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) throw new NuApiError(401, 'Missing access token.', [], ['WWW-Authenticate' => 'Bearer']);
			$ctx = NuApiClient::authenticate($m[1], nu_client_ip());
			if (!$ctx) throw new NuApiError(401, 'Invalid or expired access token.', [], ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
			self::$ctx = $ctx;
			NuAuth::actAs($ctx['user']);
			if (!NuApiClient::hit($ctx['client'])) throw new NuApiError(429, 'Too many requests.', [], ['Retry-After' => (string)(60 - (int)date('s'))]);
			nu_db()->update(NuApiClient::CLIENT, ['last_used_at' => date('Y-m-d H:i:s')], 'zzzzsys_nunext_api_client_id', $ctx['client']['zzzzsys_nunext_api_client_id']);

			[$code, $body, $headers] = self::route($method, $path);
			$status = $code;
			foreach ($headers as $k => $v) header("$k: $v");
			http_response_code($code);
			if (is_string($body)) { header('Content-Length: ' . strlen($body)); echo $body; }    // a file (headers set by the route, e.g. a report PDF)
			elseif ($code !== 204) echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		} catch (NuApiError $e) {
			$status = $e->status;
			self::problem($e->status, $e->getMessage(), $e->errors, $e->headers);
		} catch (Throwable $e) {
			$status = 500;
			error_log('nuBuilder Next API: ' . $e);
			self::problem(500, nu_cfg('nuConfigDebug') ? $e->getMessage() : 'Internal error.');
		} finally {
			if (self::$ctx && $path !== '/v1/token') {
				$c = self::$ctx['client'];
				NuLog::write($method === 'GET' ? 'api_read' : 'api_write', 'api', mb_substr($path, 0, 100),
					['method' => $method, 'status' => $status, 'client' => $c['name']], ['id' => (string)self::$ctx['user']['zzzzsys_user_id'], 'login' => (string)self::$ctx['user']['sus_login_name']]);
			}
			// work after the answer (async report runs): only where PHP can end the response first (PHP-FPM); otherwise the job runs
			// when the client asks for its status
			if (self::$after && function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
				nu_ignore_abort();
				try { (self::$after)(); } catch (Throwable $e) { error_log('nuBuilder Next API (after the answer): ' . $e->getMessage()); }
			}
		}
	}

	private static function secure(): bool
	{
		if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
		if (nu_cfg('nuConfigTrustProxy') && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
		if (in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) return true;
		return (bool)nu_cfg('nuConfigApiAllowHttp');
	}

	private static function problem(int $status, string $title, array $errors = [], array $headers = []): void
	{
		if (!headers_sent()) {
			http_response_code($status);
			header('Content-Type: application/problem+json; charset=utf-8');
			foreach ($headers as $k => $v) header("$k: $v");
		}
		$b = ['type' => 'about:blank', 'title' => $title, 'status' => $status];
		if ($errors) $b['errors'] = array_map(static fn($f, $m) => ['field' => (string)$f, 'message' => (string)$m], array_keys($errors), array_values($errors));
		echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/** POST /v1/token – grant_type=client_credentials; credentials as HTTP Basic or in the body (form or JSON). */
	private static function token(): void
	{
		$in = self::body(false);
		$id = (string)($in['client_id'] ?? '');
		$secret = (string)($in['client_secret'] ?? '');
		if (isset($_SERVER['PHP_AUTH_USER'])) { $id = (string)$_SERVER['PHP_AUTH_USER']; $secret = (string)($_SERVER['PHP_AUTH_PW'] ?? ''); }
		elseif (preg_match('/^Basic\s+(\S+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m) && ($d = base64_decode($m[1], true)) !== false && str_contains($d, ':')) {
			[$id, $secret] = explode(':', $d, 2);
			$id = rawurldecode($id); $secret = rawurldecode($secret);
		}
		if (($in['grant_type'] ?? '') !== 'client_credentials') {
			http_response_code(400);
			echo json_encode(['error' => 'unsupported_grant_type']);
			return;
		}
		[$res, $err] = NuApiClient::issue($id, $secret, (string)($in['scope'] ?? ''), nu_client_ip());
		if ($err) {
			http_response_code($err === 'invalid_scope' ? 400 : 401);
			if ($err === 'invalid_client') header('WWW-Authenticate: Basic realm="nuBuilder Next API"');
			echo json_encode(['error' => $err]);
			return;
		}
		header('Pragma: no-cache');
		echo json_encode($res, JSON_UNESCAPED_SLASHES);
	}

	/** Request body: JSON (or form fields for the token request). */
	private static function body(bool $jsonOnly = true): array
	{
		$raw = (string)file_get_contents('php://input');
		$type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
		if (str_contains($type, 'application/json')) {
			if (strlen($raw) > 1_000_000) throw new NuApiError(413, 'Request too large.');
			$d = $raw === '' ? [] : json_decode($raw, true);
			if (!is_array($d)) throw new NuApiError(400, 'Invalid JSON body.');
			return $d;
		}
		if ($jsonOnly) {
			if (trim($raw) === '') return [];
			throw new NuApiError(415, 'Content-Type must be application/json.');
		}
		return $_POST;
	}

	// ================================================================= routing

	/** @return array{0: int, 1: mixed, 2: array} */
	private static function route(string $method, string $path): array
	{
		$routes = [
			['GET',    '#^/v1/users$#',                          'usersList',      'users:read'],
			['POST',   '#^/v1/users$#',                          'userCreate',     'users:write'],
			['GET',    '#^/v1/users/([\w\-]+)$#',                'userGet',        'users:read'],
			['PATCH',  '#^/v1/users/([\w\-]+)$#',                'userPatch',      'users:write'],
			['DELETE', '#^/v1/users/([\w\-]+)$#',                'userDelete',     'users:write'],
			['POST',   '#^/v1/users/([\w\-]+)/password$#',       'userPassword',   'users:password'],
			['POST',   '#^/v1/users/([\w\-]+)/login-link$#',     'userLoginLink',  'users:login_link'],
			['DELETE', '#^/v1/users/([\w\-]+)/login-link$#',     'userLoginLinkClear', 'users:login_link'],
			['GET',    '#^/v1/users/([\w\-]+)/permissions$#',    'userPermsGet',   'permissions:read'],
			['PUT',    '#^/v1/users/([\w\-]+)/permissions$#',    'userPermsPut',   'permissions:write'],
			['GET',    '#^/v1/access-levels$#',                  'accessList',     'access:read'],
			['POST',   '#^/v1/access-levels$#',                  'accessCreate',   'access:write'],
			['GET',    '#^/v1/access-levels/([\w\-]+)$#',        'accessGet',      'access:read'],
			['PATCH',  '#^/v1/access-levels/([\w\-]+)$#',        'accessPatch',    'access:write'],
			['DELETE', '#^/v1/access-levels/([\w\-]+)$#',        'accessDelete',   'access:write'],
			['GET',    '#^/v1/access-levels/([\w\-]+)/forms$#',  'accessFormsGet', 'access:read'],
			['PUT',    '#^/v1/access-levels/([\w\-]+)/forms$#',  'accessFormsPut', 'access:write'],
			['GET',    '#^/v1/permission-items$#',               'permList',       'permissions:read'],
			['POST',   '#^/v1/permission-items$#',               'permCreate',     'permissions:write'],
			['GET',    '#^/v1/permission-items/([\w\-]+)$#',     'permGet',        'permissions:read'],
			['PATCH',  '#^/v1/permission-items/([\w\-]+)$#',     'permPatch',      'permissions:write'],
			['DELETE', '#^/v1/permission-items/([\w\-]+)$#',     'permDelete',     'permissions:write'],
			['POST',   '#^/v1/system/update$#',                  'updateRun',      'system:update'],
			['GET',    '#^/v1/system/update$#',                  'updateList',     'system:update'],
			['GET',    '#^/v1/system/update/([a-f0-9]{16})$#',   'updateGet',      'system:update'],
			['GET',    '#^/v1/reports$#',                        'reportsList',    'reports:run'],
			['GET',    '#^/v1/reports/jobs/([a-f0-9]{16})$#',    'reportJob',      'reports:run'],
			['POST',   '#^/v1/reports/([\w\-]+)/run$#',          'reportRun',      'reports:run'],
			['POST',   '#^/v1/notifications$#',                  'notifyCreate',   'notifications:write'],
			['GET',    '#^/v1/notifications/([\w\-]+)$#',        'notifyGet',      'notifications:write'],
			['DELETE', '#^/v1/notifications/([\w\-]+)$#',        'notifyDelete',   'notifications:write'],
			['GET',    '#^/v1/me$#',                             'me',             null],
		];
		$allowed = [];
		foreach ($routes as [$m, $re, $fn, $scope]) {
			if (!preg_match($re, $path, $mm)) continue;
			if ($m !== $method) { $allowed[] = $m; continue; }
			if ($scope !== null && !in_array($scope, self::$ctx['scopes'], true)) throw new NuApiError(403, "Scope '$scope' is required.");
			return self::$fn(...array_slice($mm, 1));
		}
		if ($allowed) throw new NuApiError(405, 'Method not allowed.', [], ['Allow' => implode(', ', array_unique($allowed))]);
		throw new NuApiError(404, 'Not found.');
	}

	// ================================================================= system: unattended update (0.8.301, core/nuautoupdate.php)

	/**
	 * POST /v1/system/update {url, sha256?, backup?, schema?, sample?, reinstall?, dry_run?} – checks the package (dry_run: only that)
	 * or installs it: 202 with the job; its database steps run with the new code on the next request – GET /v1/system/update/{job}.
	 */
	private static function updateRun(): array
	{
		[$o, $errors] = NuAutoUpdate::options(self::body(), false);
		if ($errors) throw new NuApiError(422, 'Invalid options.', $errors);
		nu_ignore_abort();
		nu_time_limit(0);
		$r = NuAutoUpdate::run($o, 'api:' . (string)self::$ctx['client']['name']);
		if (!$r['ok']) return [409, $r, []];
		if ($o['dry_run']) return [200, $r, []];
		$r['status_url'] = '/v1/system/update/' . $r['job'];
		return [202, $r, ['Location' => $r['status_url']]];
	}

	/** GET /v1/system/update – installed version and the last jobs (finishes a pending one). */
	private static function updateList(): array
	{
		NuAutoUpdate::finishPending();
		return [200, ['installed' => NU_VERSION, 'enabled' => NuUpdate::enabled(), 'jobs' => NuAutoUpdate::jobs()], []];
	}

	/** GET /v1/system/update/{job} – status; runs the database steps of an installed job (this request already has the new code). */
	private static function updateGet(string $job): array
	{
		if (!NuAutoUpdate::job($job)) throw new NuApiError(404, 'Unknown job.');
		$r = NuAutoUpdate::finish($job);
		return [200, $r + ['installed' => NU_VERSION], []];
	}

	private static function need(string $form, string $right): void
	{
		if (!NuAcl::can($form, $right)) throw new NuApiError(403, "The service user's access level has no '$right' right on $form.");
	}

	private static function etag(array $data): string
	{
		return '"' . substr(hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE)), 0, 32) . '"';
	}

	/** Updates and deletes must send the current ETag in If-Match (optimistic locking). */
	private static function ifMatch(array $current): void
	{
		$h = trim((string)($_SERVER['HTTP_IF_MATCH'] ?? ''));
		if ($h === '') throw new NuApiError(428, 'If-Match header with the ETag of the record is required.');
		if ($h !== '*' && !in_array(self::etag($current), array_map('trim', explode(',', $h)), true)) throw new NuApiError(412, 'The record was changed meanwhile (ETag does not match).');
	}

	private static function one(array $data): array
	{
		return [200, $data, ['ETag' => self::etag($data)]];
	}

	private static function page(): array
	{
		$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
		$offset = max(0, (int)($_GET['offset'] ?? 0));
		return [$limit, $offset];
	}

	/** Only the listed keys; other keys are an error (typos must not be ignored silently). */
	private static function fields(array $in, array $allowed): array
	{
		$unknown = array_diff(array_keys($in), $allowed);
		if ($unknown) throw new NuApiError(422, 'Unknown field(s).', array_fill_keys($unknown, 'not a field of this resource'));
		return $in;
	}

	private static function me(): array
	{
		$c = self::$ctx['client'];
		$u = self::$ctx['user'];
		return [200, ['client' => $c['name'], 'client_id' => $c['client_id'], 'scopes' => self::$ctx['scopes'],
			'service_user' => ['id' => $u['zzzzsys_user_id'], 'login' => $u['sus_login_name'], 'access_level_id' => $u['sus_zzzzsys_access_id']]], []];
	}

	// ================================================================= users

	private const USER_MAP = [
		'login' => ['sus_login_name', 50], 'name' => ['sus_name', 50], 'first_name' => ['sus_first_name', 30], 'last_name' => ['sus_last_name', 30],
		'code' => ['sus_code', 50], 'email' => ['sus_email', 255], 'position' => ['sus_position', 50], 'department' => ['sus_department', 50],
		'team' => ['sus_team', 50], 'additional1' => ['sus_additional1', 100], 'additional2' => ['sus_additional2', 100], 'language' => ['sus_language', 20],
	];

	private static function userOut(array $r): array
	{
		$o = ['id' => (string)$r['zzzzsys_user_id']];
		foreach (self::USER_MAP as $k => [$col]) if (array_key_exists($col, $r)) $o[$k] = $r[$col] === null ? null : (string)$r[$col];
		$exp = nu_date_only($r['sus_expires_on'] ?? '');
		$o += [
			'access_level_id' => (string)($r['sus_zzzzsys_access_id'] ?? '') ?: null,
			'access_level_code' => isset($r['sal_code']) ? (string)$r['sal_code'] : null,
			'expires_on' => $exp ?: null, 'expired' => nu_is_expired($r['sus_expires_on'] ?? null),
			'change_password' => (string)($r['sus_change_password'] ?? '') === '1',
			'service_user' => NuApiClient::isServiceUser((string)$r['zzzzsys_user_id']),
		];
		$pt = (int)NuUserJson::get((string)$r['zzzzsys_user_id'], 'PASSWORD_CHANGED_TIME');
		$o['password_changed_at'] = $pt > 0 ? date('c', $pt) : null;
		return $o;
	}

	private static function userRow(string $id): array
	{
		$r = nu_db()->one('SELECT u.*, a.sal_code FROM zzzzsys_user u LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id
			WHERE u.zzzzsys_user_id = ? AND COALESCE(u.sus_login_name, \'\') <> \'\'', [$id]);
		if (!$r) throw new NuApiError(404, 'User not found.');
		return $r;
	}

	private static function usersList(): array
	{
		self::need(NuAcl::FORM_USER, 'view');
		$db = nu_db();
		[$limit, $offset] = self::page();
		$where = ["COALESCE(u.sus_login_name, '') <> ''"];
		$p = [];
		$q = trim((string)($_GET['q'] ?? ''));
		foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) as $term) {
			$cols = ['u.sus_login_name', 'u.sus_name', 'u.sus_first_name', 'u.sus_last_name', 'u.sus_email', 'u.sus_code', 'u.sus_department', 'u.sus_team'];
			$where[] = '(' . implode(' OR ', array_map(static fn($c) => "$c LIKE ? ESCAPE '!'", $cols)) . ')';
			array_push($p, ...array_fill(0, count($cols), NuDB::like($term)));
		}
		if (isset($_GET['access_level_id'])) { $where[] = 'u.sus_zzzzsys_access_id = ?'; $p[] = (string)$_GET['access_level_id']; }
		if (isset($_GET['login'])) { $where[] = 'u.sus_login_name = ?'; $p[] = (string)$_GET['login']; }
		if (isset($_GET['email'])) { $where[] = 'u.sus_email = ?'; $p[] = (string)$_GET['email']; }
		$state = (string)($_GET['state'] ?? 'all');
		$today = $db->datetime(date('Y-m-d')); $zero = $db->datetime('1900-01-02');
		if ($state === 'active') { $where[] = '(u.sus_expires_on IS NULL OR u.sus_expires_on >= ? OR u.sus_expires_on < ?)'; array_push($p, $today, $zero); }
		if ($state === 'expired') { $where[] = '(u.sus_expires_on < ? AND u.sus_expires_on > ?)'; array_push($p, $today, $zero); }
		$sort = ['login' => 'u.sus_login_name', 'name' => 'u.sus_name', 'email' => 'u.sus_email'][(string)($_GET['sort'] ?? 'login')] ?? 'u.sus_login_name';
		$from = ' FROM zzzzsys_user u LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id WHERE ' . implode(' AND ', $where);
		$total = (int)$db->value('SELECT COUNT(*)' . $from, $p);
		$rows = $db->page('SELECT u.*, a.sal_code' . $from, $p, $sort, $limit, $offset);
		return [200, ['data' => array_map([self::class, 'userOut'], $rows), 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]], []];
	}

	private static function userGet(string $id): array
	{
		self::need(NuAcl::FORM_USER, 'view');
		return self::one(self::userOut(self::userRow($id)));
	}

	private static function guardUser(array $row): void
	{
		if (NuApiClient::isServiceUser((string)$row['zzzzsys_user_id'])) throw new NuApiError(403, 'API service users cannot be changed through the API.');
		if (NuSecurity::restricted() && NuSecurity::adminRightsOf((string)$row['sus_zzzzsys_access_id'])) {
			throw new NuApiError(403, 'Users with admin-equivalent rights can only be changed by the globeadmin.');
		}
	}

	/** Validates API fields and returns zzzzsys_user columns. */
	private static function userValidate(array $in, ?array $old): array
	{
		$db = nu_db();
		$allowed = array_merge(array_keys(self::USER_MAP), ['access_level_id', 'expires_on', 'change_password', 'password', 'permissions']);
		self::fields($in, $allowed);
		$errors = [];
		$row = [];
		foreach (self::USER_MAP as $k => [$col, $max]) {
			if (!array_key_exists($k, $in)) continue;
			if ($in[$k] !== null && !is_scalar($in[$k])) { $errors[$k] = 'must be a string'; continue; }
			$v = trim((string)$in[$k]);
			if (mb_strlen($v) > $max) $errors[$k] = "at most $max characters";
			$row[$col] = $v === '' ? null : $v;
		}
		$login = array_key_exists('sus_login_name', $row) ? (string)$row['sus_login_name'] : (string)($old['sus_login_name'] ?? '');
		if ($login === '') $errors['login'] = 'required';
		elseif (strcasecmp($login, (string)nu_cfg('nuConfigDBGlobeadminUsername')) === 0) $errors['login'] = 'not allowed';
		elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE sus_login_name = ? AND zzzzsys_user_id <> ?', [$login, (string)($old['zzzzsys_user_id'] ?? '')])) $errors['login'] = 'already used';
		$name = array_key_exists('sus_name', $row) ? (string)$row['sus_name'] : (string)($old['sus_name'] ?? '');
		if (trim($name) === '') $errors['name'] = 'required';
		if (!empty($row['sus_email']) && !filter_var($row['sus_email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'not a valid e-mail address';
		if (array_key_exists('access_level_id', $in) || !$old) {
			$a = (string)($in['access_level_id'] ?? '');
			if ($a === '') $errors['access_level_id'] = 'required';
			elseif (!(int)$db->value('SELECT COUNT(*) FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$a])) $errors['access_level_id'] = 'unknown access level';
			elseif (in_array($a, NuApiClient::serviceAccessLevels(), true)) $errors['access_level_id'] = 'access level of an API service user';
			elseif (NuSecurity::restricted() && $a !== (string)($old['sus_zzzzsys_access_id'] ?? '') && NuSecurity::adminRightsOf($a)) {
				$errors['access_level_id'] = 'access level with admin-equivalent rights – only the globeadmin assigns it';
			}
			$row['sus_zzzzsys_access_id'] = $a;
		}
		if (array_key_exists('expires_on', $in)) {
			$e = $in['expires_on'] === null ? '' : trim((string)$in['expires_on']);
			if ($e !== '' && !nu_valid_date($e)) $errors['expires_on'] = 'date YYYY-MM-DD or null';
			$row['sus_expires_on'] = $e === '' ? null : $db->datetime($e);
		}
		if (array_key_exists('change_password', $in)) $row['sus_change_password'] = $in['change_password'] ? '1' : '0';
		if (array_key_exists('password', $in)) {
			if (!in_array('users:password', self::$ctx['scopes'], true)) $errors['password'] = "scope 'users:password' required";
			else self::checkPassword((string)$in['password'], $errors);
		}
		if ($errors) throw new NuApiError(422, 'Validation failed.', $errors);
		return $row;
	}

	private static function checkPassword(string $pw, array &$errors): void
	{
		$min = max(1, (int)nu_cfg('nuConfigPasswordMinLength'));
		if (mb_strlen($pw) < $min) $errors['password'] = "at least $min characters";
		elseif (strlen($pw) > 72) $errors['password'] = 'at most 72 bytes';
	}

	private static function userCreate(): array
	{
		self::need(NuAcl::FORM_USER, 'create');
		$in = self::body();
		$row = self::userValidate($in, null);
		$db = nu_db();
		$id = nu_id();
		$pw = isset($in['password']) ? (string)$in['password'] : '';
		$row += ['sus_login_password' => NuAuth::hash($pw !== '' ? $pw : bin2hex(random_bytes(24))), 'sus_change_password' => $row['sus_change_password'] ?? ($pw === '' ? '1' : '0')];
		$db->insert('zzzzsys_user', ['zzzzsys_user_id' => $id] + $row);
		if ($pw !== '') NuUserJson::passwordChanged($id, 'admin');
		NuLog::write('user_created', 'zzzzsys_user', $id, ['fields' => NuLog::diff([], array_diff_key($row, ['sus_login_password' => 1])), 'via' => 'api']);
		if (isset($in['permissions']) && is_array($in['permissions'])) self::setPerms($id, $in['permissions']);
		[, $body, $h] = self::one(self::userOut(self::userRow($id)));
		return [201, $body, $h + ['Location' => nu_base_url() . '/api.php/v1/users/' . $id]];
	}

	private static function userPatch(string $id): array
	{
		self::need(NuAcl::FORM_USER, 'save');
		$old = self::userRow($id);
		self::guardUser($old);
		self::ifMatch(self::userOut($old));
		$in = self::body();
		if (isset($in['permissions'])) throw new NuApiError(422, 'Validation failed.', ['permissions' => 'use PUT /v1/users/{id}/permissions']);
		$row = self::userValidate($in, $old);
		if (isset($in['password']) && (string)$in['password'] !== '') {
			$row['sus_login_password'] = NuAuth::hash((string)$in['password']);
			if (!array_key_exists('sus_change_password', $row)) $row['sus_change_password'] = '0';
		}
		if ($row) {
			nu_db()->update('zzzzsys_user', $row, 'zzzzsys_user_id', $id);
			if (isset($row['sus_login_password'])) NuUserJson::passwordChanged($id, 'admin');
			$changes = NuLog::diff($old, array_diff_key($row, ['sus_login_password' => 1]));
			if (isset($row['sus_login_password'])) $changes['sus_login_password'] = ['…', '(' . t('log_new_password') . ')'];
			if ($changes) NuLog::write('user_updated', 'zzzzsys_user', $id, ['fields' => $changes, 'via' => 'api']);
		}
		return self::one(self::userOut(self::userRow($id)));
	}

	/** Archive (Valid until = yesterday) by default; ?hard=1 deletes (Delete right). */
	private static function userDelete(string $id): array
	{
		$old = self::userRow($id);
		self::guardUser($old);
		self::ifMatch(self::userOut($old));
		$db = nu_db();
		if (($_GET['hard'] ?? '') === '1') {
			self::need(NuAcl::FORM_USER, 'delete');
			$db->delete('zzzzsys_user', 'zzzzsys_user_id', $id);
			NuPermission::deleteUser($id);
			NuTwoFA::removeTotp($id);
			NuTwoFA::forgetDevices($id);
			NuLog::write('user_deleted', 'zzzzsys_user', $id, ['login' => $old['sus_login_name'], 'via' => 'api']);
			return [204, null, []];
		}
		self::need(NuAcl::FORM_USER, 'save');
		$until = date('Y-m-d', strtotime('-1 day'));
		if (!nu_is_expired($old['sus_expires_on'])) {
			$db->update('zzzzsys_user', ['sus_expires_on' => $db->datetime($until)], 'zzzzsys_user_id', $id);
			NuLog::write('user_archived', 'zzzzsys_user', $id, ['login' => $old['sus_login_name'], 'expires_on' => $until, 'via' => 'api']);
		}
		return self::one(self::userOut(self::userRow($id)));
	}

	/** {"password": "…"} sets it; {"welcome": true} sends the welcome e-mail with a link to set it. */
	private static function userPassword(string $id): array
	{
		self::need(NuAcl::FORM_USER, 'save');
		$old = self::userRow($id);
		self::guardUser($old);
		$in = self::fields(self::body(), ['password', 'change_password', 'welcome']);
		$db = nu_db();
		if (!empty($in['welcome'])) {
			$err = NuWelcome::send($db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$id]) ?? []);
			if ($err !== null) throw new NuApiError(422, 'Welcome e-mail not sent.', ['welcome' => $err]);
			return [200, ['sent' => true], []];
		}
		$errors = [];
		self::checkPassword((string)($in['password'] ?? ''), $errors);
		if ($errors) throw new NuApiError(422, 'Validation failed.', $errors);
		$db->update('zzzzsys_user', ['sus_login_password' => NuAuth::hash((string)$in['password']), 'sus_change_password' => !empty($in['change_password']) ? '1' : '0'], 'zzzzsys_user_id', $id);
		NuUserJson::passwordChanged($id, 'admin');
		NuLog::write('user_updated', 'zzzzsys_user', $id, ['fields' => ['sus_login_password' => ['…', '(' . t('log_new_password') . ')']], 'via' => 'api']);
		return [204, null, []];
	}

	/**
	 * POST /v1/users/{id}/login-link {"minutes": 1440, "form_id": "…", "record_id": "…", "email": false} – nuBuilder's single-use
	 * login token (core/nulogintoken.php, 0.8.339): 201 {url, expires_at (ISO 8601 or null), emailed}. minutes 0 = no expiry.
	 */
	private static function userLoginLink(string $id): array
	{
		self::need(NuAcl::FORM_USER, 'save');
		$old = self::userRow($id);
		self::guardUser($old);
		if (!NuLoginToken::enabled()) throw new NuApiError(409, 'One-time login tokens are switched off (Security check).');
		$in = self::fields(self::body(), ['minutes', 'form_id', 'record_id', 'email']);
		$min = isset($in['minutes']) ? (int)$in['minutes'] : 1440;
		if ($min < 0 || $min > 525600) throw new NuApiError(422, 'Validation failed.', ['minutes' => '0 … 525600']);
		try {
			$url = NuLoginToken::create($id, $min, (string)($in['form_id'] ?? ''), (string)($in['record_id'] ?? ''), 'api');
		} catch (RuntimeException $e) {
			throw new NuApiError(422, 'No login link.', ['user' => $e->getMessage()]);
		}
		$mailed = false;
		if (!empty($in['email'])) {
			$err = NuLoginToken::mail($id, $url, $min);
			if ($err !== null) throw new NuApiError(422, 'Login link created, e-mail not sent.', ['email' => $err]);
			$mailed = true;
		}
		return [201, ['url' => $url, 'expires_at' => $min > 0 ? gmdate('Y-m-d\TH:i:s\Z', time() + $min * 60) : null, 'emailed' => $mailed], []];
	}

	/** DELETE /v1/users/{id}/login-link – removes the user's token. */
	private static function userLoginLinkClear(string $id): array
	{
		self::need(NuAcl::FORM_USER, 'save');
		self::guardUser(self::userRow($id));
		NuLoginToken::clear($id, 'api');
		return [204, null, []];
	}

	// ================================================================= reports (core/nureportapi.php, 0.8.340)

	/** GET /v1/reports – reports the service user may run, with the hash variables they use (params). */
	/**
	 * POST /v1/notifications {"title", "body", "level", "link", "audience": {"all", "users" (ids or logins), "departments", "teams", "positions",
	 * "access_levels"}, "mode": "live"|"fixed", "publish_at", "expires_at", "pinned", "must_confirm", "email", "draft": false} (0.8.357)
	 */
	private static function notifyCreate(): array
	{
		$in = self::fields(self::body(), ['title', 'body', 'level', 'link', 'audience', 'mode', 'publish_at', 'expires_at', 'pinned', 'must_confirm', 'email', 'draft']);
		$u = self::$ctx['user'];
		[$id, $errors] = NuNotify::save('', $in, empty($in['draft']), ['id' => (string)$u['zzzzsys_user_id'], 'login' => (string)$u['sus_login_name']], false);
		if ($errors) throw new NuApiError(422, 'Validation failed.', $errors);
		return [201, self::notifyOut((array)NuNotify::load($id)), []];
	}

	private static function notifyGet(string $id): array
	{
		$n = NuNotify::load($id);
		if (!$n) throw new NuApiError(404, 'Notification not found.');
		return [200, self::notifyOut($n), []];
	}

	private static function notifyDelete(string $id): array
	{
		if (!NuNotify::load($id)) throw new NuApiError(404, 'Notification not found.');
		NuNotify::delete($id);
		return [204, [], []];
	}

	private static function notifyOut(array $n): array
	{
		$r = NuNotify::receipts($n, false);
		return ['id' => (string)$n['zzzzsys_nunext_notification_id'], 'title' => (string)$n['title'], 'level' => (string)$n['level'], 'state' => NuNotify::state($n),
			'mode' => (string)$n['mode'], 'audience' => $n['aud'], 'publish_at' => $n['publish_at'], 'expires_at' => $n['expires_at'], 'created_by' => (string)$n['created_by'],
			'recipients' => $r['total'], 'read' => $r['read'], 'confirmed' => $r['confirmed']];
	}

	private static function reportsList(): array
	{
		if (!NuReport::available()) throw new NuApiError(404, 'There are no report tables.');
		return [200, ['data' => NuReportApi::list()], []];
	}

	/**
	 * POST /v1/reports/{id or code}/run {"params": {…}, "filename": "…", "expires_minutes": 15, "max_downloads": 1, "url": true,
	 * "email": {"to", "cc", "bcc", "subject", "body"}, "async": false}. Accept: application/pdf → the PDF itself.
	 */
	private static function reportRun(string $ref): array
	{
		$r = NuReport::available() ? NuReport::load($ref) : null;
		if (!$r) throw new NuApiError(404, 'Report not found.');
		if (!NuReport::canRun($r)) throw new NuApiError(403, "The service user's access level may not run this report.");
		$in = self::fields(self::body(), ['params', 'filename', 'expires_minutes', 'max_downloads', 'url', 'email', 'async']);
		try {
			$o = NuReportApi::options($in);
			$hash = NuReportApi::hash($in['params'] ?? null);
		} catch (RuntimeException $e) {
			$err = json_decode($e->getMessage(), true);
			throw new NuApiError(422, 'Validation failed.', is_array($err) ? $err : ['params' => $e->getMessage()]);
		}
		$user = self::$ctx['user'];
		$clientRef = (string)self::$ctx['client']['zzzzsys_nunext_api_client_id'];
		if (NuReportApi::runsLastMinute((string)$user['zzzzsys_user_id']) >= NuReportApi::RUNS_PER_MINUTE) {
			throw new NuApiError(429, 'Too many report runs (' . NuReportApi::RUNS_PER_MINUTE . ' per minute).', [], ['Retry-After' => '60']);
		}
		$wantPdf = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/pdf');
		if ($o['async']) {
			if ($wantPdf) throw new NuApiError(422, 'Validation failed.', ['async' => 'not with Accept: application/pdf']);
			$job = NuReportApi::createJob($r, $hash, $o, $clientRef);
			self::$after = static fn() => NuReportApi::runJob($job, $clientRef);
			return [202, ['job' => $job, 'status' => 'queued', 'status_url' => nu_base_url() . '/api.php/v1/reports/jobs/' . $job], ['Location' => nu_base_url() . '/api.php/v1/reports/jobs/' . $job]];
		}
		try {
			$pdf = NuReportApi::render($r, $hash);
			if ($wantPdf) {
				$o['url'] = false;
				$res = $o['email'] !== null ? NuReportApi::deliver($r, $pdf, $o, $clientRef) : [];
				$name = NuReportApi::fileName($o['filename'], $r);
				$h = ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'X-Report-Sha256' => hash('sha256', $pdf)];
				if (isset($res['emailed'])) $h['X-Report-Emailed'] = $res['emailed'] ? 'true' : 'false';
				return [200, $pdf, $h];
			}
			$res = NuReportApi::deliver($r, $pdf, $o, $clientRef);
		} catch (RuntimeException $e) {
			throw new NuApiError(500, $e->getMessage());
		}
		if (!$o['url'] && empty($res['emailed'])) throw new NuApiError(422, 'E-mail not sent.', ['email' => (string)($res['email_error'] ?? '')]);
		return [201, $res, []];
	}

	/** GET /v1/reports/jobs/{job} – status of an async run; a job still queued runs now (servers without PHP-FPM). */
	private static function reportJob(string $job): array
	{
		$clientRef = (string)self::$ctx['client']['zzzzsys_nunext_api_client_id'];
		$j = NuReportApi::job($job, $clientRef);
		if (!$j) throw new NuApiError(404, 'Job not found.');
		if ($j['status'] === 'queued') $j = NuReportApi::runJob($job, $clientRef) ?? $j;
		return [200, NuReportApi::jobView($j), []];
	}

	// ----------------------------------------------------------------- permissions of a user

	private static function permsOf(string $id): array
	{
		$items = array_column(NuPermission::items(), null, 'zzzzsys_permission_item_id');
		$out = [];
		foreach (NuPermission::forUser($id) as $itemId => $active) {
			if ($active === '1' && isset($items[$itemId])) $out[] = ['id' => (string)$itemId, 'ident' => (string)$items[$itemId]['pme_ident']];
		}
		return $out;
	}

	private static function userPermsGet(string $id): array
	{
		self::need(NuAcl::FORM_USER, 'view');
		self::userRow($id);
		$d = ['user_id' => $id, 'items' => self::permsOf($id)];
		return self::one($d);
	}

	/** {"items": ["<item id or ident>", …]} – the complete list (replaces). */
	private static function userPermsPut(string $id): array
	{
		self::need(NuAcl::FORM_USER, 'save');
		$old = self::userRow($id);
		self::guardUser($old);
		self::ifMatch(['user_id' => $id, 'items' => self::permsOf($id)]);
		$in = self::fields(self::body(), ['items']);
		if (!is_array($in['items'] ?? null)) throw new NuApiError(422, 'Validation failed.', ['items' => 'array of item ids or idents']);
		self::setPerms($id, $in['items']);
		return self::one(['user_id' => $id, 'items' => self::permsOf($id)]);
	}

	private static function setPerms(string $userId, array $list): void
	{
		$items = NuPermission::items();
		$byId = array_column($items, 'zzzzsys_permission_item_id', 'zzzzsys_permission_item_id');
		$byIdent = array_column($items, 'zzzzsys_permission_item_id', 'pme_ident');
		$ids = [];
		$bad = [];
		foreach ($list as $x) {
			$x = (string)$x;
			if (isset($byId[$x])) $ids[] = $x; elseif (isset($byIdent[$x])) $ids[] = (string)$byIdent[$x]; else $bad[] = $x;
		}
		if ($bad) throw new NuApiError(422, 'Validation failed.', ['items' => 'unknown: ' . implode(', ', $bad)]);
		[$a, $r] = NuPermission::setUser($userId, $ids);
		if ($a || $r) NuLog::write('user_permissions', 'zzzzsys_user', $userId, ['added' => $a, 'removed' => $r, 'via' => 'api']);
	}

	// ================================================================= access levels

	private static function accessOut(array $r): array
	{
		$db = nu_db();
		return [
			'id' => (string)$r['zzzzsys_access_id'], 'code' => (string)$r['sal_code'], 'description' => $r['sal_description'] === null ? null : (string)$r['sal_description'],
			'group' => $r['sal_group'] === null ? null : (string)$r['sal_group'], 'home_form_id' => $r['sal_zzzzsys_form_id'] === null ? null : (string)$r['sal_zzzzsys_form_id'],
			'use_2fa' => (string)($r['sal_use_2fa'] ?? '') === '1',
			'users' => (int)$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE sus_zzzzsys_access_id = ?', [$r['zzzzsys_access_id']]),
			'forms' => (int)$db->value('SELECT COUNT(*) FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ?', [$r['zzzzsys_access_id']]),
			'service_level' => in_array((string)$r['zzzzsys_access_id'], NuApiClient::serviceAccessLevels(), true),
		];
	}

	private static function accessRow(string $id): array
	{
		$r = nu_db()->one('SELECT * FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$id]);
		if (!$r) throw new NuApiError(404, 'Access level not found.');
		return $r;
	}

	private static function guardAccess(string $id): void
	{
		if (in_array($id, NuApiClient::serviceAccessLevels(), true)) throw new NuApiError(403, 'The access level of an API service user cannot be changed through the API.');
	}

	private static function accessList(): array
	{
		self::need(NuAcl::FORM_ACCESS, 'view');
		$db = nu_db();
		[$limit, $offset] = self::page();
		$where = ['1 = 1'];
		$p = [];
		if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $where[] = "(sal_code LIKE ? ESCAPE '!' OR sal_description LIKE ? ESCAPE '!')"; array_push($p, NuDB::like($q), NuDB::like($q)); }
		if (isset($_GET['group'])) { $where[] = "COALESCE(sal_group, '') = ?"; $p[] = (string)$_GET['group']; }
		if (isset($_GET['code'])) { $where[] = 'sal_code = ?'; $p[] = (string)$_GET['code']; }
		$from = ' FROM zzzzsys_access WHERE ' . implode(' AND ', $where);
		$total = (int)$db->value('SELECT COUNT(*)' . $from, $p);
		$rows = $db->page('SELECT *' . $from, $p, 'sal_code', $limit, $offset);
		return [200, ['data' => array_map([self::class, 'accessOut'], $rows), 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]], []];
	}

	private static function accessGet(string $id): array
	{
		self::need(NuAcl::FORM_ACCESS, 'view');
		return self::one(self::accessOut(self::accessRow($id)));
	}

	private static function accessValidate(array $in, ?array $old): array
	{
		self::fields($in, ['code', 'description', 'group', 'home_form_id', 'use_2fa', 'forms']);
		$db = nu_db();
		$errors = [];
		$row = [];
		foreach (['code' => ['sal_code', 50], 'description' => ['sal_description', 200], 'group' => ['sal_group', 80]] as $k => [$col, $max]) {
			if (!array_key_exists($k, $in)) continue;
			$v = trim((string)$in[$k]);
			if (mb_strlen($v) > $max) $errors[$k] = "at most $max characters";
			$row[$col] = $v === '' ? null : $v;
		}
		$code = array_key_exists('sal_code', $row) ? (string)$row['sal_code'] : (string)($old['sal_code'] ?? '');
		if ($code === '') $errors['code'] = 'required';
		elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_access WHERE sal_code = ? AND zzzzsys_access_id <> ?', [$code, (string)($old['zzzzsys_access_id'] ?? '')])) $errors['code'] = 'already used';
		if (array_key_exists('home_form_id', $in)) {
			$f = (string)($in['home_form_id'] ?? '');
			if ($f !== '' && !isset(nu_forms()[$f])) $errors['home_form_id'] = 'unknown form';
			$row['sal_zzzzsys_form_id'] = $f === '' ? null : $f;
		}
		if (array_key_exists('use_2fa', $in)) $row['sal_use_2fa'] = $in['use_2fa'] ? '1' : '0';
		if ($errors) throw new NuApiError(422, 'Validation failed.', $errors);
		return $row;
	}

	private static function accessCreate(): array
	{
		self::need(NuAcl::FORM_ACCESS, 'create');
		$in = self::body();
		$row = self::accessValidate($in, null);
		$forms = isset($in['forms']) ? self::formsIn($in['forms']) : null;
		$id = nu_id();
		nu_db()->transaction(function (NuDB $db) use ($id, $row, $forms) {
			$db->insert('zzzzsys_access', ['zzzzsys_access_id' => $id, 'sal_use_2fa' => '0'] + $row);
			if ($forms !== null) self::writeForms($id, $forms);
		});
		NuLog::write('access_created', 'zzzzsys_access', $id, ['code' => $row['sal_code'] ?? '', 'forms' => count($forms ?? []), 'via' => 'api']);
		[, $body, $h] = self::one(self::accessOut(self::accessRow($id)));
		return [201, $body, $h + ['Location' => nu_base_url() . '/api.php/v1/access-levels/' . $id]];
	}

	private static function accessPatch(string $id): array
	{
		self::need(NuAcl::FORM_ACCESS, 'save');
		$old = self::accessRow($id);
		self::guardAccess($id);
		self::ifMatch(self::accessOut($old));
		$in = self::body();
		if (isset($in['forms'])) throw new NuApiError(422, 'Validation failed.', ['forms' => 'use PUT /v1/access-levels/{id}/forms']);
		$row = self::accessValidate($in, $old);
		if ($row) {
			nu_db()->update('zzzzsys_access', $row, 'zzzzsys_access_id', $id);
			NuLog::write('access_updated', 'zzzzsys_access', $id, ['code' => $row['sal_code'] ?? $old['sal_code'], 'fields' => NuLog::diff($old, $row), 'via' => 'api']);
		}
		return self::one(self::accessOut(self::accessRow($id)));
	}

	private static function accessDelete(string $id): array
	{
		self::need(NuAcl::FORM_ACCESS, 'delete');
		$old = self::accessRow($id);
		self::guardAccess($id);
		self::ifMatch(self::accessOut($old));
		$n = (int)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_user WHERE sus_zzzzsys_access_id = ?', [$id]);
		if ($n > 0) throw new NuApiError(409, "The access level is used by $n user(s).");
		nu_db()->transaction(static function (NuDB $db) use ($id) {
			$db->delete('zzzzsys_access_form', 'slf_zzzzsys_access_id', $id);
			if ($db->tableExists('zzzzsys_access_php')) $db->delete('zzzzsys_access_php', 'slp_zzzzsys_access_id', $id);
			if ($db->tableExists('zzzzsys_access_report')) $db->delete('zzzzsys_access_report', 'sre_zzzzsys_access_id', $id);
			$db->delete('zzzzsys_access', 'zzzzsys_access_id', $id);
		});
		NuLog::write('access_deleted', 'zzzzsys_access', $id, ['code' => $old['sal_code'], 'via' => 'api']);
		return [204, null, []];
	}

	// ----------------------------------------------------------------- form rights of an access level

	private static function formsOut(string $id): array
	{
		$rows = nu_db()->all('SELECT * FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ? ORDER BY slf_zzzzsys_form_id', [$id]);
		return array_map(static function ($r) {
			$o = ['form_id' => (string)$r['slf_zzzzsys_form_id']];
			foreach (NuAcl::RIGHTS as $k => $col) $o[$k] = (string)$r[$col] === '1';
			return $o;
		}, $rows);
	}

	private static function accessFormsGet(string $id): array
	{
		self::need(NuAcl::FORM_ACCESS, 'view');
		self::accessRow($id);
		return self::one(['access_level_id' => $id, 'forms' => self::formsOut($id)]);
	}

	/** [{form_id, add, save, delete, clone, new, print}] → validated list keyed by form id */
	private static function formsIn(mixed $list): array
	{
		if (!is_array($list)) throw new NuApiError(422, 'Validation failed.', ['forms' => 'array of {form_id, add, save, delete, clone, new, print}']);
		$known = nu_forms();
		$out = [];
		$errors = [];
		foreach (array_values($list) as $i => $f) {
			$fid = is_array($f) ? (string)($f['form_id'] ?? '') : '';
			if ($fid === '' || !isset($known[$fid])) { $errors["forms[$i].form_id"] = 'unknown form'; continue; }
			$extra = array_diff(array_keys($f), array_merge(['form_id'], array_keys(NuAcl::RIGHTS)));
			if ($extra) { $errors["forms[$i]"] = 'unknown field(s): ' . implode(', ', $extra); continue; }
			$r = [];
			foreach (NuAcl::RIGHTS as $k => $col) $r[$col] = !empty($f[$k]) ? '1' : '0';
			$out[$fid] = $r;
		}
		if ($errors) throw new NuApiError(422, 'Validation failed.', $errors);
		return $out;
	}

	/** Replaces the rights rows (existing rows keep data mode, form type and JSON). */
	private static function writeForms(string $id, array $forms): void
	{
		$db = nu_db();
		if (NuSecurity::restricted()) {                     // admin-equivalent rights are added by the globeadmin only (NuSecurity)
			$before = NuSecurity::adminRightsOf($id);
			foreach ($forms as $fid => $rights) {
				if (!in_array((string)$fid, NuSecurity::ADMIN_FORMS, true)) continue;
				foreach (NuSecurity::ADMIN_RIGHTS as $col) {
					if (($rights[$col] ?? '0') === '1' && !in_array($col, $before[$fid] ?? [], true)) {
						throw new NuApiError(403, "Admin-equivalent right $col on $fid – only the globeadmin grants it.");
					}
				}
			}
		}
		$have = [];
		foreach ($db->all('SELECT zzzzsys_access_form_id, slf_zzzzsys_form_id FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ?', [$id]) as $r) {
			$have[(string)$r['slf_zzzzsys_form_id']][] = (string)$r['zzzzsys_access_form_id'];
		}
		foreach ($forms as $fid => $rights) {
			if (isset($have[$fid])) {
				$rowId = array_shift($have[$fid]);
				$db->update('zzzzsys_access_form', $rights, 'zzzzsys_access_form_id', $rowId);
			} else {
				$db->insert('zzzzsys_access_form', ['zzzzsys_access_form_id' => nu_id(), 'slf_zzzzsys_access_id' => $id, 'slf_zzzzsys_form_id' => (string)$fid] + $rights);
			}
		}
		foreach ($have as $ids) foreach ($ids as $rowId) $db->delete('zzzzsys_access_form', 'zzzzsys_access_form_id', $rowId);   // not in the list (or duplicates)
	}

	private static function accessFormsPut(string $id): array
	{
		self::need(NuAcl::FORM_ACCESS, 'save');
		self::accessRow($id);
		self::guardAccess($id);
		self::ifMatch(['access_level_id' => $id, 'forms' => self::formsOut($id)]);
		$in = self::fields(self::body(), ['forms']);
		$forms = self::formsIn($in['forms'] ?? null);
		nu_db()->transaction(fn() => self::writeForms($id, $forms));
		NuLog::write('access_updated', 'zzzzsys_access', $id, ['forms' => count($forms), 'via' => 'api']);
		return self::one(['access_level_id' => $id, 'forms' => self::formsOut($id)]);
	}

	// ================================================================= permission items

	private static function permOut(array $r): array
	{
		return ['id' => (string)$r['zzzzsys_permission_item_id'], 'ident' => (string)$r['pme_ident'], 'group' => $r['pme_group'] === null ? null : (string)$r['pme_group'],
			'description' => $r['pme_description'] === null ? null : (string)$r['pme_description'],
			'users' => count(NuPermission::users((string)$r['zzzzsys_permission_item_id']))];
	}

	private static function permRow(string $id): array
	{
		if (!NuPermission::exists()) throw new NuApiError(404, 'Permission item not found.');
		$r = nu_db()->one('SELECT * FROM ' . NuPermission::ITEM . ' WHERE zzzzsys_permission_item_id = ?', [$id]);
		if (!$r) throw new NuApiError(404, 'Permission item not found.');
		return $r;
	}

	private static function permList(): array
	{
		self::need(NuAcl::FORM_PERMISSION, 'view');
		return [200, ['data' => array_map([self::class, 'permOut'], NuPermission::items())], []];
	}

	private static function permGet(string $id): array
	{
		self::need(NuAcl::FORM_PERMISSION, 'view');
		return self::one(self::permOut(self::permRow($id)));
	}

	private static function permIn(array $in, ?array $old): array
	{
		self::fields($in, ['ident', 'group', 'description']);
		return [
			'pme_ident' => array_key_exists('ident', $in) ? (string)$in['ident'] : (string)($old['pme_ident'] ?? ''),
			'pme_group' => array_key_exists('group', $in) ? (string)$in['group'] : (string)($old['pme_group'] ?? ''),
			'pme_description' => array_key_exists('description', $in) ? (string)$in['description'] : (string)($old['pme_description'] ?? ''),
		];
	}

	private static function permSave(array $row, string $id): string
	{
		[$newId, $errors] = NuPermission::save($row, $id);
		if ($errors) {
			$map = ['pme_ident' => 'ident', 'pme_group' => 'group', 'pme_description' => 'description'];
			$e = [];
			foreach ($errors as $k => $v) $e[$map[$k] ?? $k] = $v;
			throw new NuApiError(422, 'Validation failed.', $e);
		}
		return (string)$newId;
	}

	private static function permCreate(): array
	{
		self::need(NuAcl::FORM_PERMISSION, 'create');
		$id = self::permSave(self::permIn(self::body(), null), '');
		NuLog::write('permission_item_created', NuPermission::ITEM, $id, ['via' => 'api']);
		[, $body, $h] = self::one(self::permOut(self::permRow($id)));
		return [201, $body, $h + ['Location' => nu_base_url() . '/api.php/v1/permission-items/' . $id]];
	}

	private static function permPatch(string $id): array
	{
		self::need(NuAcl::FORM_PERMISSION, 'save');
		$old = self::permRow($id);
		self::ifMatch(self::permOut($old));
		self::permSave(self::permIn(self::body(), $old), $id);
		NuLog::write('permission_item_changed', NuPermission::ITEM, $id, ['via' => 'api']);
		return self::one(self::permOut(self::permRow($id)));
	}

	private static function permDelete(string $id): array
	{
		self::need(NuAcl::FORM_PERMISSION, 'delete');
		$old = self::permRow($id);
		self::ifMatch(self::permOut($old));
		NuPermission::delete($id);
		NuLog::write('permission_item_deleted', NuPermission::ITEM, $id, ['ident' => $old['pme_ident'], 'via' => 'api']);
		return [204, null, []];
	}
}
