<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * AI providers (0.8.1099): configurations that let the AI dialogues (Form with AI, Ask AI in design mode, Reports with AI, help
 * pages, translations, the prompt generator) send their prompt to an AI service and fetch the answer – in addition to copy and paste.
 *
 * - Table zzzzsys_nunext_ai_provider: name, kind (anthropic | openai = OpenAI-compatible | gemini), base_url, api_key (sealed with
 *   $nuConfigSecretKey), model, temperature, max_tokens, timeout, system (extra instruction), params (JSON merged into the request),
 *   active, access (all | levels | password), levels (JSON of access level ids), month_limit (tokens, 0 = none).
 * - Password-protected configurations (0.8.1101): the API key is ALSO encrypted with a key derived from the password (Argon2id,
 *   pw_salt) – api_key = "pw:" + secretbox(server-sealed key). No password hash is stored: a wrong password fails to decrypt.
 *   After unlock() the derived key lives in the session, encrypted with a random value kept only in the cookie nunext_aik – the
 *   session file alone opens nothing. Forgotten password = enter the API key again. Rows of 0.8.1099 / 0.8.1100 (bcrypt hash in
 *   password, key sealed by the server only) convert on their first unlock.
 * - Table zzzzsys_nunext_ai_call: one row per request – who, configuration, feature, model, tokens in / out, ms, status. Never the content.
 * - The request runs on the server (the key never reaches the browser): ask() → request() → transport (curl; self::$transport in tests).
 * - Who may use a configuration: mayUse() – the feature's own right (FEATURES) and the configuration's access rule; a password is
 *   asked once per session (unlock(), 5 tries in 15 minutes).
 * - Addresses: https and public only, unless the setting 'ai' {allow_private} is on (local models) – NuWebhook::checkUrl with it.
 */
final class NuAiProvider
{
	public const T = 'zzzzsys_nunext_ai_provider';
	public const CALL = 'zzzzsys_nunext_ai_call';
	public const SETTING = 'ai';
	public const KEEP_DAYS = 400;
	public const MAX_PROMPT = 2000000;      // characters of one request
	public const UNLOCK_TRIES = 5;
	public const UNLOCK_WINDOW = 900;
	public const PW_MIN = 12;                // characters of a configuration's password (the API key is encrypted with it)
	public const COOKIE = 'nunext_aik';
	/** kind => [text key, default base URL, placeholder model]. The model names are examples – "Fetch models" lists the real ones. */
	public const KINDS = [
		'anthropic' => ['aip_kind_anthropic', 'https://api.anthropic.com', 'claude-sonnet-4-5'],
		'openai'    => ['aip_kind_openai', 'https://api.openai.com/v1', 'gpt-4.1'],
		'gemini'    => ['aip_kind_gemini', 'https://generativelanguage.googleapis.com/v1beta', 'gemini-2.5-pro'],
	];
	public const ACCESS = ['all', 'levels', 'password'];
	/** feature => text key (shown in the usage list). Who may use a feature: featureAllowed(). */
	public const FEATURES = ['form' => 'aip_f_form', 'design' => 'aip_f_design', 'report' => 'aip_f_report', 'help' => 'aip_f_help', 'translate' => 'aip_f_translate',
		'prompt' => 'aip_f_prompt', 'test' => 'aip_f_test'];

	private static ?array $defs = null;
	/** Test hook: callable(string $method, string $url, array $headers, string $body, int $timeout) → [code, body, error]. */
	public static $transport = null;

	// ================================================================= definitions

	public static function available(): bool
	{
		try { return nu_db()->tableExists(self::T); } catch (Throwable) { return false; }
	}

	/** @return array<string, array> all configurations by id */
	public static function all(): array
	{
		if (self::$defs !== null) return self::$defs;
		self::$defs = [];
		if (!self::available()) return self::$defs;
		foreach (nu_db()->all('SELECT * FROM ' . self::T . ' ORDER BY name') as $r) {
			$lv = json_decode((string)($r['levels'] ?? ''), true);
			self::$defs[(string)$r[self::T . '_id']] = ['id' => (string)$r[self::T . '_id'], 'name' => (string)$r['name'], 'kind' => (string)$r['kind'],
				'base_url' => (string)$r['base_url'], 'api_key' => (string)$r['api_key'], 'model' => (string)$r['model'],
				'temperature' => (string)($r['temperature'] ?? ''), 'max_tokens' => (int)($r['max_tokens'] ?? 0), 'timeout' => (int)($r['timeout'] ?? 0) ?: 180,
				'system' => (string)($r['system_text'] ?? ''), 'params' => (string)($r['params'] ?? ''), 'active' => (string)$r['active'] === '1',
				'access' => in_array((string)$r['access'], self::ACCESS, true) ? (string)$r['access'] : 'all',
				'levels' => is_array($lv) ? array_values(array_map('strval', $lv)) : [], 'password' => (string)($r['password'] ?? ''), 'salt' => (string)($r['pw_salt'] ?? ''),
				'month_limit' => (int)($r['month_limit'] ?? 0), 'updated_at' => $r['updated_at'] ?? null, 'updated_by' => (string)($r['updated_by'] ?? '')];
		}
		return self::$defs;
	}

	public static function get(string $id): ?array
	{
		return self::all()[$id] ?? null;
	}

	public static function baseUrl(array $d): string
	{
		return rtrim(trim($d['base_url']) !== '' ? trim($d['base_url']) : self::KINDS[$d['kind']][1], '/');
	}

	/**
	 * Saves a configuration. $in: name, kind, base_url, api_key ('' = keep), model, temperature, max_tokens, timeout, system, params,
	 * active, access, levels[], password ('' = keep), month_limit. @return array{0: string, 1: array<string, string>} id, errors by field
	 */
	public static function save(array $in, string $id = ''): array
	{
		NuSchema::ensure();
		$old = $id !== '' ? self::get($id) : null;
		$errors = [];
		$name = trim((string)($in['name'] ?? ''));
		if ($name === '') $errors['name'] = t('err_required');
		else foreach (self::all() as $o) if ($o['id'] !== $id && mb_strtolower($o['name']) === mb_strtolower($name)) $errors['name'] = t('aip_err_name');
		$kind = (string)($in['kind'] ?? '');
		if (!isset(self::KINDS[$kind])) $errors['kind'] = t('err_required');
		$url = trim((string)($in['base_url'] ?? ''));
		if ($url !== '' && ($e = self::checkUrl($url, false)) !== '') $errors['base_url'] = $e;
		$key = trim((string)($in['api_key'] ?? ''));
		$model = trim((string)($in['model'] ?? ''));
		if ($model === '') $errors['model'] = t('err_required');
		$temp = trim(str_replace(',', '.', (string)($in['temperature'] ?? '')));
		if ($temp !== '' && (!is_numeric($temp) || (float)$temp < 0 || (float)$temp > 2)) $errors['temperature'] = t('aip_err_temperature');
		$max = (int)($in['max_tokens'] ?? 0);
		if ($max < 0 || $max > 1000000) $errors['max_tokens'] = t('aip_err_number');
		$timeout = (int)($in['timeout'] ?? 0) ?: 180;
		if ($timeout < 10 || $timeout > 900) $errors['timeout'] = t('aip_err_timeout');
		$params = trim((string)($in['params'] ?? ''));
		if ($params !== '') {
			$pj = json_decode($params, true);
			if (!is_array($pj) || ($pj && array_is_list($pj))) $errors['params'] = t('aip_err_params');
		}
		$access = in_array((string)($in['access'] ?? ''), self::ACCESS, true) ? (string)$in['access'] : 'all';
		$levels = array_values(array_unique(array_filter(array_map('strval', (array)($in['levels'] ?? [])), 'strlen')));
		if ($access === 'levels' && !$levels) $errors['levels'] = t('aip_err_levels');
		$pw = (string)($in['password'] ?? '');
		$wasPw = $old && self::pwMode($old);
		$legacy = $old && $old['access'] === 'password' && !$wasPw && $old['password'] !== '';      // 0.8.1099 / 0.8.1100: converts on its first unlock
		$plainKey = null;                                                    // the API key in clear, when this save has to encrypt it again
		if ($access === 'password') {
			if (!self::canDerive()) $errors['password'] = t('aip_err_sodium');
			elseif ($pw !== '' && mb_strlen($pw) < self::PW_MIN) $errors['password'] = t('aip_err_password', self::PW_MIN);
			elseif ($pw === '' && !$wasPw && !$legacy) $errors['password'] = t('err_required');
			elseif ($pw !== '') {                                             // a new password: the key is encrypted with it
				if ($key !== '') $plainKey = $key;
				elseif (!$old) $plainKey = '';
				elseif (!$wasPw) $plainKey = self::open($old['api_key']);
				elseif (($plainKey = self::apiKey($old)) === null) $errors['password'] = t('aip_err_rekey');
			} elseif ($wasPw && $key !== '' && self::sessionKey($old) === null) $errors['api_key'] = t('aip_err_need_unlock');
		} elseif ($wasPw) {                                                   // the password goes: the key must be readable once more
			if ($key !== '') $plainKey = $key;
			elseif (($plainKey = self::apiKey($old)) === null) $errors['access'] = t('aip_err_need_unlock');
		}
		$limit = max(0, (int)($in['month_limit'] ?? 0));
		if ($errors) return [$id, $errors];

		$row = ['name' => mb_substr($name, 0, 100), 'kind' => $kind, 'base_url' => mb_substr($url, 0, 500), 'model' => mb_substr($model, 0, 200), 'temperature' => $temp,
			'max_tokens' => $max, 'timeout' => $timeout, 'system_text' => trim((string)($in['system'] ?? '')), 'params' => $params,
			'active' => !empty($in['active']) ? '1' : '0', 'access' => $access, 'levels' => json_encode($levels), 'month_limit' => $limit,
			'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => self::who()];
		$newK = null;
		if ($access === 'password') {
			if ($pw !== '') {                                                 // new salt + key from the password
				$salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
				$newK = self::derive($pw, $salt);
				$row['pw_salt'] = base64_encode($salt);
				$row['api_key'] = self::lock((string)$plainKey, $newK);
				$row['password'] = '';
			} elseif ($wasPw && $key !== '') {
				$row['api_key'] = self::lock($key, (string)self::sessionKey($old));
			} elseif ($legacy && $key !== '') {
				$row['api_key'] = self::seal($key);
			}
		} else {
			$row['password'] = '';
			$row['pw_salt'] = '';
			if ($plainKey !== null) $row['api_key'] = $plainKey !== '' ? self::seal($plainKey) : '';
			elseif ($key !== '') $row['api_key'] = self::seal($key);
		}
		$db = nu_db();
		if ($old) $db->update(self::T, $row, self::T . '_id', $id);
		else { $id = nu_id(); $db->insert(self::T, [self::T . '_id' => $id] + $row + ['api_key' => $row['api_key'] ?? '']); }
		self::$defs = null;
		if ($newK !== null) self::remember($id, $newK);                       // whoever set the password stays unlocked
		NuLog::write('ai_provider_saved', self::T, $id, ['name' => $name, 'kind' => $kind, 'model' => $model, 'url' => NuWebhook::urlForLog($url), 'access' => $access,
			'key' => $key !== '' ? 'new' : null, 'password' => $access === 'password' && $pw !== '' ? 'new' : null, 'active' => $row['active']]);
		return [$id, []];
	}

	public static function delete(string $id): void
	{
		$d = self::get($id);
		if (!$d) return;
		$db = nu_db();
		$db->delete(self::CALL, 'provider_id', $id);
		$db->delete(self::T, self::T . '_id', $id);
		self::$defs = null;
		NuLog::write('ai_provider_deleted', self::T, $id, ['name' => $d['name'], 'kind' => $d['kind']]);
	}

	public static function settings(): array
	{
		try { $j = json_decode((string)nu_setting_value(self::SETTING), true); } catch (Throwable) { $j = null; }
		return ['allow_private' => is_array($j) && !empty($j['allow_private'])];
	}

	public static function setSettings(bool $allowPrivate): void
	{
		NuSchema::ensure();
		nu_setting_save(self::SETTING, ['setting_value' => json_encode(['allow_private' => $allowPrivate]), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => self::who()], nu_db());
		NuLog::write('ai_provider_settings', 'zzzzsys_nunext_setting', self::SETTING, ['allow_private' => $allowPrivate]);
	}

	public static function checkUrl(string $url, bool $resolve, ?string &$pin = null): string
	{
		return NuWebhook::checkUrl($url, $resolve, $pin, self::settings()['allow_private']);
	}

	// ================================================================= who may use what

	/** May the signed-in user use AI requests for this feature at all? (the rights of the page the dialogue sits on) */
	public static function featureAllowed(string $feature): bool
	{
		$u = NuAuth::user();
		if (!$u || !empty($u['demo']) || !isset(self::FEATURES[$feature])) return false;
		if (!empty($u['globeadmin'])) return true;
		return match ($feature) {
			'form', 'design' => NuAcl::can(NuAcl::FORM_FORM, 'save'),
			'report' => NuAcl::can(NuAcl::FORM_REPORT, 'save'),
			'help' => NuAdmin::can('userhelp', 'change'),
			'translate' => NuAcl::can(NuAcl::FORM_TRANSLATE, 'save'),
			'prompt' => NuAcl::can(NuAcl::FORM_PROMPT, 'view'),
			'test' => NuAdmin::can('aiproviders', 'change'),
			default => false,
		};
	}

	/** May the user use this configuration (leaving the password aside)? */
	public static function mayUse(array $d): bool
	{
		$u = NuAuth::user();
		if (!$u || !$d['active']) return false;
		if ($d['access'] !== 'levels' || !empty($u['globeadmin'])) return true;
		return (bool)array_intersect($d['levels'], NuUserAccess::levels($u));
	}

	/** Is the API key of this configuration encrypted with its password? */
	public static function pwMode(array $d): bool
	{
		return $d['access'] === 'password' && str_starts_with($d['api_key'], 'pw:') && ($d['salt'] ?? '') !== '';
	}

	/** Is a password-protected configuration open in this session? (a new password locks it again) */
	public static function unlocked(array $d): bool
	{
		if ($d['access'] !== 'password') return true;
		return self::pwMode($d) && self::sessionKey($d) !== null;
	}

	/** '' = unlocked, else the message. $admin: the configuration's own page (also while it is inactive). */
	public static function unlock(string $id, string $password, bool $admin = false): string
	{
		$d = self::get($id);
		if (!$d || $d['access'] !== 'password' || !($admin || self::mayUse($d))) return t('record_not_found');
		if (!self::canDerive()) return t('aip_err_sodium');
		$tries = array_values(array_filter((array)($_SESSION['nu_ai_tries'] ?? []), static fn($t) => (int)$t > time() - self::UNLOCK_WINDOW));
		if (count($tries) >= self::UNLOCK_TRIES) return t('aip_err_tries');
		$k = null;
		if (self::pwMode($d)) {
			$try = self::derive($password, (string)base64_decode($d['salt'], true));
			if (self::unlockKey($d['api_key'], $try) !== null) $k = $try;
		} elseif ($d['password'] !== '' && password_verify($password, $d['password'])) {
			// a row of 0.8.1099 / 0.8.1100: the password is here now – encrypt the key with it, drop the hash
			$salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
			$k = self::derive($password, $salt);
			nu_db()->update(self::T, ['api_key' => self::lock(self::open($d['api_key']), $k), 'pw_salt' => base64_encode($salt), 'password' => ''], self::T . '_id', $id);
			self::$defs = null;
		}
		if ($k === null) {
			$tries[] = time();
			$_SESSION['nu_ai_tries'] = $tries;
			NuLog::write('ai_unlock_failed', self::T, $id, ['name' => $d['name']]);
			return t('aip_err_wrong_password');
		}
		self::remember($id, $k);
		return '';
	}

	// ----------------------------------------------------------------- the key behind a password

	public static function canDerive(): bool
	{
		return function_exists('sodium_crypto_pwhash') && function_exists('sodium_crypto_secretbox') && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
	}

	/** The 32-byte key of a password (Argon2id, interactive limits: ~64 MB, well under a second). */
	public static function derive(string $password, string $salt): string
	{
		if (strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) return str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
		return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $password, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
	}

	/** "pw:" + the server-sealed API key, encrypted with the password's key. */
	public static function lock(string $apiKey, string $k): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return 'pw:' . base64_encode($nonce . sodium_crypto_secretbox(self::seal($apiKey), $nonce, $k));
	}

	/** The server-sealed key inside a "pw:" value, or null with the wrong key. */
	private static function unlockKey(string $stored, string $k): ?string
	{
		$raw = base64_decode(substr($stored, 3), true);
		if (!str_starts_with($stored, 'pw:') || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return null;
		$out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $k);
		return $out === false ? null : $out;
	}

	/** Test seam: the cookie value instead of $_COOKIE / setcookie (unit tests have no browser). */
	public static ?string $cookieTest = null;

	/** The random value only the browser keeps – the session holds the password's key encrypted with it. */
	private static function cookieKey(bool $create): ?string
	{
		$c = self::$cookieTest ?? (string)($_COOKIE[self::COOKIE] ?? '');
		$raw = $c !== '' ? base64_decode($c, true) : false;
		if (is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $raw;
		if (!$create) return null;
		$raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
		$v = base64_encode($raw);
		if (self::$cookieTest !== null) self::$cookieTest = $v;
		elseif (!headers_sent()) {
			setcookie(self::COOKIE, $v, ['expires' => 0, 'path' => NuSecurity::cookiePath(), 'secure' => nu_https(), 'httponly' => true, 'samesite' => 'Strict']);
			$_COOKIE[self::COOKIE] = $v;
		} else return null;
		return $raw;
	}

	private static function remember(string $id, string $k): void
	{
		$c = self::cookieKey(true);
		if ($c === null) return;
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$_SESSION['nu_ai_unlocked'][$id] = base64_encode($nonce . sodium_crypto_secretbox($k, $nonce, $c));
	}

	/** The password's key of an unlocked configuration (checked against the stored key), or null. */
	private static function sessionKey(array $d): ?string
	{
		$v = (string)($_SESSION['nu_ai_unlocked'][$d['id']] ?? '');
		$c = $v !== '' && self::canDerive() ? self::cookieKey(false) : null;
		$raw = $c !== null ? base64_decode($v, true) : false;
		if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
		$k = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $c);
		return is_string($k) && self::unlockKey($d['api_key'], $k) !== null ? $k : null;
	}

	/** The API key in clear – null while a password-protected configuration is locked. */
	public static function apiKey(array $d): ?string
	{
		if (!str_starts_with($d['api_key'], 'pw:')) return self::open($d['api_key']);
		$k = self::sessionKey($d);
		$inner = $k !== null ? self::unlockKey($d['api_key'], $k) : null;
		return $inner !== null ? self::open($inner) : null;
	}

	/** The configurations the dialogue of a feature offers: [{id, name, model, locked}] – [] = no "Ask AI" there. */
	public static function usable(string $feature): array
	{
		if (!self::featureAllowed($feature)) return [];
		$out = [];
		foreach (self::all() as $d) if (self::mayUse($d)) $out[] = ['id' => $d['id'], 'name' => $d['name'], 'model' => $d['model'], 'locked' => !self::unlocked($d)];
		return $out;
	}

	/** Data of the "Ask AI" control (assets/js/nuaiask.js) for a feature, or null when no configuration can be used. */
	public static function client(string $feature): ?array
	{
		$list = self::usable($feature);
		if (!$list) return null;
		$msg = [];
		foreach (['aip_ask', 'aip_ask_tip', 'aip_waiting', 'aip_cancelled', 'aip_done', 'aip_fixing', 'aip_fixed', 'aip_still_errors', 'aip_password', 'aip_unlock',
			'aip_config', 'aip_failed', 'aip_sent_note'] as $k) $msg[$k] = t($k);
		$msg['aip_cancel'] = t('cancel');
		return ['url' => nu_url('aiask'), 'csrf' => nu_csrf_token(), 'feature' => $feature, 'configs' => $list, 'msg' => $msg];
	}

	/** The host element the page scripts mount the control in ('' without a usable configuration). */
	public static function host(string $feature, string $id, string $class = ''): string
	{
		$c = self::client($feature);
		return $c ? '<div class="nuAiAskHost ' . h($class) . '" id="' . h($id) . '" data-nu-ai="' . h((string)json_encode($c, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . '"></div>' : '';
	}

	// ================================================================= usage

	/** Tokens (in + out) of a configuration in the current month. */
	public static function monthTokens(string $id): int
	{
		try {
			return (int)nu_db()->value('SELECT COALESCE(SUM(tokens_in + tokens_out), 0) FROM ' . self::CALL . ' WHERE provider_id = ? AND created_at >= ?', [$id, date('Y-m-01 00:00:00')]);
		} catch (Throwable) { return 0; }
	}

	/** @return array{calls: int, tokens_in: int, tokens_out: int, errors: int} of the current month */
	public static function monthUsage(string $id): array
	{
		$r = nu_db()->one('SELECT COUNT(*) n, COALESCE(SUM(tokens_in), 0) ti, COALESCE(SUM(tokens_out), 0) tout, COALESCE(SUM(CASE WHEN status = \'error\' THEN 1 ELSE 0 END), 0) e FROM '
			. self::CALL . ' WHERE provider_id = ? AND created_at >= ?', [$id, date('Y-m-01 00:00:00')]) ?: [];
		return ['calls' => (int)($r['n'] ?? 0), 'tokens_in' => (int)($r['ti'] ?? 0), 'tokens_out' => (int)($r['tout'] ?? 0), 'errors' => (int)($r['e'] ?? 0)];
	}

	public static function lastCalls(string $id, int $n = 20): array
	{
		return nu_db()->page('SELECT * FROM ' . self::CALL . ' WHERE provider_id = ?', [$id], 'created_at DESC', $n, 0);
	}

	public static function purge(): int
	{
		if (!self::available()) return 0;
		return nu_db()->run('DELETE FROM ' . self::CALL . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)])->rowCount();
	}

	private static function record(array $d, string $feature, array $r): void
	{
		try {
			nu_db()->insert(self::CALL, [self::CALL . '_id' => nu_id(), 'provider_id' => $d['id'], 'user_login' => self::who(), 'feature' => $feature, 'model' => mb_substr($d['model'], 0, 200),
				'tokens_in' => (int)$r['in'], 'tokens_out' => (int)$r['out'], 'ms' => (int)$r['ms'], 'status' => $r['ok'] ? 'ok' : 'error',
				'error' => mb_substr((string)$r['error'], 0, 300), 'created_at' => date('Y-m-d H:i:s')]);
		} catch (Throwable $e) { error_log('nuBuilder Next AI call log: ' . $e->getMessage()); }
	}

	// ================================================================= the request

	/**
	 * Sends a conversation and returns the answer. $messages: [['user' | 'assistant', text], …] – the first is the prompt.
	 * @return array{ok: bool, text: string, error: string, in: int, out: int, ms: int, code: int}
	 */
	public static function ask(string $id, array $messages, string $feature): array
	{
		$fail = static fn(string $e) => ['ok' => false, 'text' => '', 'error' => $e, 'in' => 0, 'out' => 0, 'ms' => 0, 'code' => 0];
		$d = self::get($id);
		if (!$d || !self::featureAllowed($feature) || !self::mayUse($d)) return $fail(t('aip_err_not_allowed'));
		if (!self::unlocked($d)) return $fail(t('aip_err_locked'));
		$messages = array_values(array_filter($messages, static fn($m) => is_array($m) && trim((string)($m[1] ?? '')) !== ''));
		if (!$messages || $messages[0][0] !== 'user') return $fail(t('aip_err_empty'));
		if (array_sum(array_map(static fn($m) => strlen((string)$m[1]), $messages)) > self::MAX_PROMPT) return $fail(t('aip_err_too_long'));
		if ($d['month_limit'] > 0 && self::monthTokens($id) >= $d['month_limit']) return $fail(t('aip_err_limit', number_format($d['month_limit'], 0, '.', "'")));
		$ak = self::apiKey($d);
		if ($ak === null) return $fail(t('aip_err_locked'));
		if ($ak === '' && $d['api_key'] !== '' && !str_starts_with($d['api_key'], 'pw:')) return $fail(t('aip_err_key'));
		$r = self::request($d, $messages);
		self::record($d, $feature, $r);
		return $r;
	}

	/** "Test": one short request. */
	public static function test(array $d): array
	{
		$r = self::apiKey($d) === null ? ['ok' => false, 'text' => '', 'error' => t('aip_err_locked'), 'in' => 0, 'out' => 0, 'ms' => 0, 'code' => 0]
			: self::request($d, [['user', 'Answer with the single word: OK']], 60);
		self::record($d, 'test', $r);
		NuLog::write('ai_provider_tested', self::T, $d['id'], ['ok' => $r['ok'], 'code' => $r['code'], 'error' => mb_substr($r['error'], 0, 200)]);
		return $r;
	}

	/** The request body of a kind: [url, headers, body array]. Public for the unit tests. */
	public static function build(array $d, array $messages): array
	{
		$base = self::baseUrl($d);
		$key = (string)self::apiKey($d);
		$temp = $d['temperature'] !== '' ? (float)$d['temperature'] : null;
		$system = trim($d['system']);
		$extra = json_decode($d['params'] !== '' ? $d['params'] : '{}', true);
		$extra = is_array($extra) ? $extra : [];
		$h = ['Content-Type: application/json', 'User-Agent: nuBuilderNext/' . NU_VERSION];
		if ($d['kind'] === 'anthropic') {
			$url = $base . (preg_match('~/v\d+$~', $base) ? '' : '/v1') . '/messages';
			$h[] = 'x-api-key: ' . $key;
			$h[] = 'anthropic-version: 2023-06-01';
			$body = ['model' => $d['model'], 'max_tokens' => $d['max_tokens'] > 0 ? $d['max_tokens'] : 8192,
				'messages' => array_map(static fn($m) => ['role' => $m[0] === 'assistant' ? 'assistant' : 'user', 'content' => (string)$m[1]], $messages)];
			if ($system !== '') $body['system'] = $system;
			if ($temp !== null) $body['temperature'] = $temp;
		} elseif ($d['kind'] === 'gemini') {
			$url = $base . '/models/' . rawurlencode(preg_replace('~^models/~', '', $d['model'])) . ':generateContent';
			$h[] = 'x-goog-api-key: ' . $key;
			$body = ['contents' => array_map(static fn($m) => ['role' => $m[0] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => (string)$m[1]]]], $messages)];
			if ($system !== '') $body['systemInstruction'] = ['parts' => [['text' => $system]]];
			$gc = [];
			if ($temp !== null) $gc['temperature'] = $temp;
			if ($d['max_tokens'] > 0) $gc['maxOutputTokens'] = $d['max_tokens'];
			if ($gc) $body['generationConfig'] = $gc;
		} else {
			$url = $base . '/chat/completions';
			$host = strtolower((string)parse_url($base, PHP_URL_HOST));
			if ($key !== '') { $h[] = 'Authorization: Bearer ' . $key; if (str_ends_with($host, '.azure.com')) $h[] = 'api-key: ' . $key; }
			$msgs = array_map(static fn($m) => ['role' => $m[0] === 'assistant' ? 'assistant' : 'user', 'content' => (string)$m[1]], $messages);
			if ($system !== '') array_unshift($msgs, ['role' => 'system', 'content' => $system]);
			$body = ['model' => $d['model'], 'messages' => $msgs];
			if ($temp !== null) $body['temperature'] = $temp;
			// OpenAI's own newer models take max_completion_tokens; compatible servers (Ollama, LM Studio, Mistral …) max_tokens
			if ($d['max_tokens'] > 0) $body[str_ends_with($host, 'openai.com') || str_ends_with($host, '.azure.com') ? 'max_completion_tokens' : 'max_tokens'] = $d['max_tokens'];
		}
		foreach ($extra as $k => $v) { if ($v === null) unset($body[$k]); else $body[$k] = $v; }      // extra parameters win; null removes one
		return [$url, $h, $body];
	}

	/** Reads the answer of a kind: [text, tokens in, tokens out, error]. Public for the unit tests. */
	public static function parse(string $kind, int $code, string $raw): array
	{
		$j = json_decode($raw, true);
		$err = '';
		if (!is_array($j)) return ['', 0, 0, $code >= 400 || $code === 0 ? 'HTTP ' . $code : t('aip_err_answer')];
		$e = $j['error'] ?? null;
		if ($e !== null || $code >= 400) $err = trim('HTTP ' . $code . ' ' . (is_array($e) ? (string)($e['message'] ?? ($e['type'] ?? '')) : (is_string($e) ? $e : '')));
		$text = ''; $in = 0; $out = 0;
		if ($kind === 'anthropic') {
			foreach ((array)($j['content'] ?? []) as $c) if (is_array($c) && ($c['type'] ?? '') === 'text') $text .= (string)($c['text'] ?? '');
			$in = (int)($j['usage']['input_tokens'] ?? 0); $out = (int)($j['usage']['output_tokens'] ?? 0);
			if ($err === '' && ($j['stop_reason'] ?? '') === 'max_tokens') $err = t('aip_err_cut');
		} elseif ($kind === 'gemini') {
			foreach ((array)($j['candidates'][0]['content']['parts'] ?? []) as $p) if (is_array($p) && empty($p['thought'])) $text .= (string)($p['text'] ?? '');
			$in = (int)($j['usageMetadata']['promptTokenCount'] ?? 0); $out = (int)($j['usageMetadata']['candidatesTokenCount'] ?? 0) + (int)($j['usageMetadata']['thoughtsTokenCount'] ?? 0);
			if ($err === '' && ($j['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS') $err = t('aip_err_cut');
		} else {
			$c = $j['choices'][0]['message']['content'] ?? '';
			if (is_array($c)) { foreach ($c as $p) if (is_array($p)) $text .= (string)($p['text'] ?? ''); } else $text = (string)$c;
			$in = (int)($j['usage']['prompt_tokens'] ?? 0); $out = (int)($j['usage']['completion_tokens'] ?? 0);
			if ($err === '' && ($j['choices'][0]['finish_reason'] ?? '') === 'length') $err = t('aip_err_cut');
		}
		if ($err === '' && trim($text) === '') $err = t('aip_err_answer');
		return [$text, $in, $out, $err];
	}

	private static function request(array $d, array $messages, int $timeout = 0): array
	{
		$t0 = microtime(true);
		$timeout = $timeout ?: $d['timeout'];
		[$url, $headers, $body] = self::build($d, $messages);
		[$code, $raw, $e] = self::http('POST', $url, $headers, (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $timeout);
		$ms = (int)round((microtime(true) - $t0) * 1000);
		if ($e !== '') return ['ok' => false, 'text' => '', 'error' => $e, 'in' => 0, 'out' => 0, 'ms' => $ms, 'code' => $code];
		[$text, $in, $out, $err] = self::parse($d['kind'], $code, $raw);
		// an answer cut at the length limit is still handed over (with the note) – the check will say what is missing
		$ok = $err === '' || ($text !== '' && $code >= 200 && $code < 300);
		return ['ok' => $ok, 'text' => $text, 'error' => $err, 'in' => $in, 'out' => $out, 'ms' => $ms, 'code' => $code];
	}

	/** The models the service lists (for the model field). @return array{0: string[], 1: string} names, error */
	public static function models(array $d): array
	{
		$base = self::baseUrl($d);
		$key = (string)self::apiKey($d);
		$h = ['User-Agent: nuBuilderNext/' . NU_VERSION];
		if ($d['kind'] === 'anthropic') { $url = $base . (preg_match('~/v\d+$~', $base) ? '' : '/v1') . '/models?limit=200'; $h[] = 'x-api-key: ' . $key; $h[] = 'anthropic-version: 2023-06-01'; }
		elseif ($d['kind'] === 'gemini') { $url = $base . '/models?pageSize=200'; $h[] = 'x-goog-api-key: ' . $key; }
		else { $url = $base . '/models'; if ($key !== '') $h[] = 'Authorization: Bearer ' . $key; }
		[$code, $raw, $e] = self::http('GET', $url, $h, '', 20);
		if ($e !== '') return [[], $e];
		$j = json_decode($raw, true);
		if (!is_array($j) || $code >= 400) return [[], trim('HTTP ' . $code . ' ' . (is_array($j) && is_array($j['error'] ?? null) ? (string)($j['error']['message'] ?? '') : ''))];
		$out = [];
		foreach ((array)($j['data'] ?? $j['models'] ?? []) as $m) {
			$n = is_array($m) ? (string)($m['id'] ?? $m['name'] ?? '') : '';
			if ($d['kind'] === 'gemini' && is_array($m) && isset($m['supportedGenerationMethods']) && !in_array('generateContent', (array)$m['supportedGenerationMethods'], true)) continue;
			if ($n !== '') $out[] = preg_replace('~^models/~', '', $n);
		}
		sort($out);
		return [array_values(array_unique($out)), ''];
	}

	/** One HTTP call. @return array{0: int, 1: string, 2: string} code, body, error ('' = the server answered) */
	private static function http(string $method, string $url, array $headers, string $body, int $timeout): array
	{
		if (is_callable(self::$transport)) return (self::$transport)($method, $url, $headers, $body, $timeout);
		$err = self::checkUrl($url, true, $pin);
		if ($err !== '') return [0, '', $err];
		if (!function_exists('curl_init')) return [0, '', t('aip_err_curl')];
		$ch = curl_init($url);
		$opts = [CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false];
		if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $body; }
		if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
		if ($pin) $opts[CURLOPT_RESOLVE] = [$pin];
		curl_setopt_array($ch, $opts);
		$raw = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$e = $raw === false ? curl_error($ch) : '';
		curl_close($ch);
		return [$code, $raw === false ? '' : (string)$raw, (string)$e];
	}

	/** The follow-up of the automatic correction round: the check's errors, sent back once. */
	public static function fixMessage(array $errors): string
	{
		return "The answer was checked and has these problems:\n\n- " . implode("\n- ", array_map(static fn($e) => trim((string)$e), array_slice($errors, 0, 40)))
			. "\n\nReturn the complete corrected answer in the same format as before – the whole answer again, not only the changed parts, and no other text.";
	}

	// ================================================================= helpers

	private static function who(): string
	{
		$u = NuAuth::user();
		return $u ? (!empty($u['globeadmin']) ? 'globeadmin' : (string)($u['login'] ?? '')) : 'cron';
	}

	public static function hasKey(array $d): bool
	{
		return $d['api_key'] !== '';
	}

	private static function seal(string $s): string
	{
		$key = (string)nu_cfg('nuConfigSecretKey');
		if ($key === '' || !function_exists('sodium_crypto_secretbox')) return 'plain:' . $s;
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($s, $nonce, hash('sha256', 'ai|' . $key, true)));
	}

	private static function open(string $s): string
	{
		if (str_starts_with($s, 'plain:')) return substr($s, 6);
		if (!str_starts_with($s, 'enc:')) return $s;
		$key = (string)nu_cfg('nuConfigSecretKey');
		$raw = base64_decode(substr($s, 4), true);
		if ($key === '' || $raw === false || !function_exists('sodium_crypto_secretbox_open') || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
		$out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash('sha256', 'ai|' . $key, true));
		return $out === false ? '' : $out;
	}
}
