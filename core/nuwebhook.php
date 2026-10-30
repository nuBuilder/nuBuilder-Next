<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Webhooks (0.8.715): after a record of a chosen form is inserted, updated or deleted, nuBuilder Next calls a URL (n8n, Zapier,
 * Teams, other systems).
 *   zzzzsys_nunext_webhook       the definitions: name, forms (JSON list of form ids), events (JSON: insert / update / delete), url,
 *                                secret (sealed with $nuConfigSecretKey), fields (empty = all), template (empty = the standard JSON)
 *   zzzzsys_nunext_webhook_call  the outbox: one row per call – written in the save's transaction (rolled back with it), sent right after
 *                                the page has answered (shutdown, after fastcgi_finish_request), retried by the housekeeping (cron.php)
 *                                after 1 min, 5 min, 30 min, 2 h, 12 h (6 attempts, then failed)
 *   zzzzsys_nunext_webhook_try   one row per attempt: HTTP status, duration, the start of the answer or the error
 * Sources: form saves / deletes (NuRecord) and CSV imports (NuImport – webhooks of forms on the imported table). nuBuilder Forte's own
 * saves fire nothing. Every call is signed: X-NuNext-Signature: sha256=HMAC(secret, "<timestamp>.<body>"), X-NuNext-Timestamp.
 * Targets: https only and no private / reserved addresses, no redirects, 5 s – unless the setting allows http and private networks
 * (setting row 'webhook' {allow_private}). The resolved address is pinned (no DNS rebinding between the check and the call).
 */
final class NuWebhook
{
	public const T = 'zzzzsys_nunext_webhook';
	public const CALL = 'zzzzsys_nunext_webhook_call';
	public const TRY = 'zzzzsys_nunext_webhook_try';
	public const SETTING = 'webhook';
	public const EVENTS = ['insert', 'update', 'delete'];
	public const BACKOFF = [60, 300, 1800, 7200, 43200];   // seconds before attempt 2 … 6
	public const MAX_TRIES = 6;
	public const KEEP_DAYS = 30;
	public const TIMEOUT = 5;
	/** Hash variables of the template (besides #<column>#). */
	public const VARS = ['EVENT', 'FORM_ID', 'FORM_CODE', 'TABLE', 'RECORD_ID', 'USER', 'TIME', 'CHANGED', 'PAYLOAD'];

	private static ?array $defs = null;
	/** @var string[] calls queued by this request, sent at shutdown */
	private static array $queued = [];
	private static bool $shutdown = false;
	/** Test hook: a callable(array $call, string $url, array $headers, string $body) → [code, body, error] instead of curl. */
	public static $transport = null;

	// ================================================================= definitions

	public static function available(): bool
	{
		try { return nu_db()->tableExists(self::T); } catch (Throwable) { return false; }
	}

	/** @return array<string, array> all definitions by id (forms / events decoded) */
	public static function all(): array
	{
		if (self::$defs !== null) return self::$defs;
		self::$defs = [];
		if (!self::available()) return self::$defs;
		foreach (nu_db()->all('SELECT * FROM ' . self::T . ' ORDER BY name') as $r) self::$defs[(string)$r[self::T . '_id']] = self::decode($r);
		return self::$defs;
	}

	public static function get(string $id): ?array
	{
		return self::all()[$id] ?? null;
	}

	private static function decode(array $r): array
	{
		$list = static function ($v): array { $j = json_decode((string)$v, true); return is_array($j) ? array_values(array_filter(array_map('strval', $j), 'strlen')) : []; };
		return ['id' => (string)$r[self::T . '_id'], 'name' => (string)$r['name'], 'active' => (string)$r['active'] === '1',
			'forms' => $list($r['forms']), 'events' => array_values(array_intersect(self::EVENTS, $list($r['events']))), 'url' => (string)$r['url'],
			'secret' => (string)$r['secret'], 'fields' => (string)($r['fields'] ?? ''), 'template' => (string)($r['template'] ?? ''),
			'updated_at' => $r['updated_at'] ?? null, 'updated_by' => (string)($r['updated_by'] ?? '')];
	}

	/** Active webhooks of a form and event. */
	public static function of(string $formId, string $event): array
	{
		return array_values(array_filter(self::all(), static fn($d) => $d['active'] && in_array($event, $d['events'], true) && in_array($formId, $d['forms'], true)));
	}

	/**
	 * Saves a definition. @return array{0: string, 1: array<string, string>, 2: ?string} id, errors by field, the secret when it is new
	 * (shown once). $in: name, active, forms[], events[], url, fields, template, new_secret (bool).
	 */
	public static function save(array $in, string $id = ''): array
	{
		NuSchema::ensure();
		$errors = [];
		$name = trim((string)($in['name'] ?? ''));
		if ($name === '') $errors['name'] = t('err_required');
		$forms = array_values(array_unique(array_filter(array_map('strval', (array)($in['forms'] ?? [])), static fn($f) => $f !== '' && isset(nu_forms()[$f]))));
		if (!$forms) $errors['forms'] = t('wh_err_forms');
		$events = array_values(array_intersect(self::EVENTS, array_map('strval', (array)($in['events'] ?? []))));
		if (!$events) $errors['events'] = t('wh_err_events');
		$url = trim((string)($in['url'] ?? ''));
		$uErr = self::checkUrl($url, false);
		if ($uErr !== '') $errors['url'] = $uErr;
		$template = trim((string)($in['template'] ?? ''));
		if ($template !== '' && ($tErr = self::checkTemplate($template)) !== '') $errors['template'] = $tErr;
		$fields = implode(', ', array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($in['fields'] ?? '')) ?: []), 'strlen')));
		if ($errors) return [$id, $errors, null];

		$db = nu_db();
		$old = $id !== '' ? self::get($id) : null;
		$secret = null;
		$row = ['name' => mb_substr($name, 0, 100), 'active' => !empty($in['active']) ? '1' : '0', 'forms' => json_encode($forms), 'events' => json_encode($events),
			'url' => $url, 'fields' => mb_substr($fields, 0, 2000), 'template' => $template, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => self::who()];
		if (!$old || !empty($in['new_secret'])) {
			$secret = bin2hex(random_bytes(24));
			$row['secret'] = self::seal($secret);
		}
		if ($old) {
			$db->update(self::T, $row, self::T . '_id', $id);
		} else {
			$id = nu_id();
			$db->insert(self::T, [self::T . '_id' => $id] + $row);
		}
		self::$defs = null;
		NuLog::write('webhook_saved', self::T, $id, ['name' => $name, 'forms' => $forms, 'events' => $events, 'url' => self::urlForLog($url), 'secret' => $secret !== null ? 'new' : null]);
		return [$id, [], $secret];
	}

	public static function delete(string $id): void
	{
		$d = self::get($id);
		if (!$d) return;
		$db = nu_db();
		$db->transaction(function (NuDB $db) use ($id) {
			$db->run('DELETE FROM ' . self::TRY . ' WHERE call_id IN (SELECT ' . self::CALL . '_id FROM ' . self::CALL . ' WHERE webhook_id = ?)', [$id]);
			$db->delete(self::CALL, 'webhook_id', $id);
			$db->delete(self::T, self::T . '_id', $id);
		});
		self::$defs = null;
		NuLog::write('webhook_deleted', self::T, $id, ['name' => $d['name'], 'url' => self::urlForLog($d['url'])]);
	}

	public static function settings(): array
	{
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$j = is_string($v) ? json_decode($v, true) : null;
		} catch (Throwable) { $j = null; }
		return ['allow_private' => is_array($j) && !empty($j['allow_private'])];
	}

	public static function setSettings(bool $allowPrivate): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => json_encode(['allow_private' => $allowPrivate]), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => self::who()];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
		NuLog::write('webhook_settings', 'zzzzsys_nunext_setting', self::SETTING, ['allow_private' => $allowPrivate]);
	}

	// ================================================================= queueing (in the save's transaction)

	/**
	 * A record of $meta was written: queues a call for every active webhook of the form and event. Runs inside the save's transaction;
	 * $row = the record as stored now (insert / update) or before (delete), $old = before an update.
	 */
	public static function queue(string $formId, string $formCode, string $table, string $event, string $recordId, array $row, array $old = [], string $source = 'form'): void
	{
		if (NuDemo::active()) return;                         // demo visitors never reach other systems
		$hooks = self::of($formId, $event);
		if (!$hooks) return;
		$db = nu_db();
		foreach ($hooks as $d) {
			$id = nu_id();
			$payload = self::payload($d, $id, $event, $formId, $formCode, $table, $recordId, $row, $old, $source);
			$db->insert(self::CALL, [self::CALL . '_id' => $id, 'webhook_id' => $d['id'], 'event' => $event, 'form_id' => $formId, 'record_id' => mb_substr($recordId, 0, 100),
				'body' => self::body($d, $payload, $row), 'status' => 'pending', 'attempts' => 0, 'next_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
				'created_by' => self::who(), 'source' => $source]);
			self::$queued[] = $id;
		}
		self::atShutdown();
	}

	/** Webhooks of the forms on a table (CSV import). @return array<int, array{id: string, code: string}> */
	public static function formsOfTable(string $table): array
	{
		$out = [];
		$ids = [];
		foreach (self::all() as $d) if ($d['active']) foreach ($d['forms'] as $f) $ids[$f] = true;
		foreach (array_keys($ids) as $f) {
			$m = NuFormMeta::load($f);
			if ($m && strcasecmp($m->table(), $table) === 0) $out[] = ['id' => $m->id, 'code' => $m->code()];
		}
		return $out;
	}

	/** The standard JSON of a call. */
	public static function payload(array $d, string $callId, string $event, string $formId, string $formCode, string $table, string $recordId, array $row, array $old, string $source): array
	{
		$u = NuAuth::user();
		$p = [
			'event' => 'record.' . ['insert' => 'inserted', 'update' => 'updated', 'delete' => 'deleted'][$event],
			'webhook' => $d['name'], 'delivery' => $callId, 'time' => date('c'), 'source' => $source,
			'form' => ['id' => $formId, 'code' => $formCode, 'table' => $table], 'record_id' => $recordId,
			'user' => $u ? ['id' => (string)($u['id'] ?? ''), 'login' => !empty($u['globeadmin']) ? 'globeadmin' : (string)($u['login'] ?? '')] : null,
			'data' => self::fields($d, $row),
		];
		if ($event === 'update') {
			$ch = [];
			foreach (self::fields($d, $row) as $k => $v) {
				if (!array_key_exists($k, $old)) continue;
				$o = $old[$k];
				if ((string)$o !== (string)$v || ($o === null) !== ($v === null)) $ch[$k] = ['old' => self::scalar($o), 'new' => $v];
			}
			$p['changed'] = (object)$ch;
		}
		return $p;
	}

	/** The columns sent: the chosen ones (or all), secrets masked, values as text / null. */
	private static function fields(array $d, array $row): array
	{
		$want = $d['fields'] !== '' ? array_map('strtolower', preg_split('/[\s,;]+/', $d['fields']) ?: []) : null;
		$out = [];
		foreach ($row as $k => $v) {
			$k = (string)$k;
			if ($want !== null && !in_array(strtolower($k), $want, true)) continue;
			$out[$k] = NuSecurity::secretName($k) && $v !== null && $v !== '' ? '***' : self::scalar($v);
		}
		return $out;
	}

	private static function scalar(mixed $v): ?string
	{
		return $v === null ? null : (is_scalar($v) ? (string)$v : json_encode($v));
	}

	/** The body: the standard JSON, or the template with its hash variables (values JSON-escaped, without quotes). */
	public static function body(array $d, array $payload, array $row = []): string
	{
		$json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($d['template'] === '') return $json;
		$esc = static fn(?string $v): string => substr((string)json_encode((string)$v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1);
		$changed = [];
		foreach ((array)($payload['changed'] ?? []) as $k => $c) $changed[] = $k . ': ' . ($c['old'] ?? '–') . ' → ' . ($c['new'] ?? '–');
		$vars = ['EVENT' => $payload['event'], 'FORM_ID' => $payload['form']['id'], 'FORM_CODE' => $payload['form']['code'], 'TABLE' => $payload['form']['table'],
			'RECORD_ID' => $payload['record_id'], 'USER' => (string)($payload['user']['login'] ?? ''), 'TIME' => $payload['time'], 'CHANGED' => implode("\n", $changed)];
		$out = (string)preg_replace_callback('/#([A-Za-z0-9_]+)#/', static function ($m) use ($vars, $payload, $esc, $json) {
			if ($m[1] === 'PAYLOAD') return $json;
			if (isset($vars[$m[1]])) return $esc((string)$vars[$m[1]]);
			foreach ($payload['data'] as $k => $v) if (strcasecmp((string)$k, $m[1]) === 0) return $esc($v);
			return $m[0];
		}, $d['template']);
		return $out;
	}

	/** '' = the template gives valid JSON with sample values; else the message. */
	public static function checkTemplate(string $template): string
	{
		$d = ['name' => 'test', 'template' => $template, 'fields' => ''];
		$p = ['event' => 'record.updated', 'form' => ['id' => 'f', 'code' => 'c', 'table' => 't'], 'record_id' => '1', 'user' => ['login' => 'u'], 'time' => date('c'),
			'data' => [], 'changed' => []];
		$body = preg_replace('/#[A-Za-z0-9_]+#/', 'x', self::body($d, $p)) ?? '';
		json_decode($body);
		return json_last_error() === JSON_ERROR_NONE ? '' : t('wh_err_template', json_last_error_msg());
	}

	// ================================================================= sending

	private static function atShutdown(): void
	{
		if (self::$shutdown) return;
		self::$shutdown = true;
		register_shutdown_function(static function () {
			try {
				if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
				if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();   // the page has answered – the calls do not keep the user waiting
				nu_ignore_abort();
				nu_time_limit(60);
				$ids = array_slice(self::$queued, 0, 20);
				self::$queued = [];
				foreach ($ids as $id) self::attempt($id);
				self::retryDue(5);                                    // a few overdue ones too – also without cron
			} catch (Throwable $e) {
				error_log('nuBuilder Next webhooks: ' . $e->getMessage());
			}
		});
	}

	/** Housekeeping (cron.php / page views): calls whose next attempt is due; old calls deleted. @return int calls tried */
	public static function retryDue(int $max = 50): int
	{
		if (!self::available()) return 0;
		$db = nu_db();
		$ids = array_column($db->page('SELECT ' . self::CALL . '_id AS id FROM ' . self::CALL . " WHERE status = 'pending' AND next_at <= ?", [date('Y-m-d H:i:s')], 'next_at', $max, 0), 'id');
		foreach ($ids as $id) self::attempt((string)$id);
		return count($ids);
	}

	public static function purge(): int
	{
		if (!self::available()) return 0;
		$db = nu_db();
		$before = date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400);
		$db->run('DELETE FROM ' . self::TRY . ' WHERE call_id IN (SELECT ' . self::CALL . '_id FROM ' . self::CALL . " WHERE status <> 'pending' AND created_at < ?)", [$before]);
		return $db->run('DELETE FROM ' . self::CALL . " WHERE status <> 'pending' AND created_at < ?", [$before])->rowCount();
	}

	/** Housekeeping step: due retries, then the clean-up. */
	public static function housekeeping(): int
	{
		$n = self::retryDue();
		self::purge();
		return $n;
	}

	/** Resend: the call goes back to pending and is tried at once. */
	public static function resend(string $callId): ?array
	{
		$db = nu_db();
		if (!$db->value('SELECT COUNT(*) FROM ' . self::CALL . ' WHERE ' . self::CALL . '_id = ?', [$callId])) return null;
		$db->update(self::CALL, ['status' => 'pending', 'attempts' => 0, 'next_at' => date('Y-m-d H:i:s')], self::CALL . '_id', $callId);
		NuLog::write('webhook_resent', self::CALL, $callId, []);
		return self::attempt($callId);
	}

	/**
	 * One attempt of a call (claimed with a conditional UPDATE, so two runs never send it twice). @return array|null the result
	 * {ok, code, ms, error}
	 */
	public static function attempt(string $callId): ?array
	{
		$db = nu_db();
		$c = $db->one('SELECT * FROM ' . self::CALL . ' WHERE ' . self::CALL . '_id = ?', [$callId]);
		if (!$c || (string)$c['status'] !== 'pending') return null;
		$n = (int)$c['attempts'] + 1;
		// claim: next_at moves on, so a parallel run does not pick it up
		$claimed = $db->run('UPDATE ' . self::CALL . ' SET attempts = ?, next_at = ? WHERE ' . self::CALL . "_id = ? AND status = 'pending' AND attempts = ?",
			[$n, date('Y-m-d H:i:s', time() + 120), $callId, (int)$c['attempts']])->rowCount();
		if (!$claimed) return null;
		$d = self::get((string)$c['webhook_id']);
		$r = $d ? self::send($d, $callId, (string)$c['event'], (string)$c['body']) : ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => t('wh_err_gone'), 'response' => ''];
		$db->insert(self::TRY, [self::TRY . '_id' => nu_id(), 'call_id' => $callId, 'tried_at' => date('Y-m-d H:i:s'), 'attempt' => $n, 'http_code' => $r['code'], 'ms' => $r['ms'],
			'error' => mb_substr($r['error'], 0, 255), 'response' => mb_substr($r['response'], 0, 1000)]);
		$upd = ['last_code' => $r['code'], 'last_error' => mb_substr($r['error'], 0, 255)];
		if ($r['ok']) $upd += ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')];
		elseif (!$d || $n >= self::MAX_TRIES || !empty($r['final'])) $upd += ['status' => 'failed'];
		else $upd += ['next_at' => date('Y-m-d H:i:s', time() + self::BACKOFF[min($n, count(self::BACKOFF)) - 1])];
		$db->update(self::CALL, $upd, self::CALL . '_id', $callId);
		return $r;
	}

	/** "Send test": a sample call now, not queued. @return array{ok: bool, code: int, ms: int, error: string, response: string, body: string} */
	public static function test(array $d): array
	{
		$meta = $d['forms'] ? NuFormMeta::load($d['forms'][0]) : null;
		$row = [];
		if ($meta && $meta->table() !== '') {
			try { $row = (array)(nu_db()->page('SELECT * FROM ' . NuDB::ident($meta->table()), [], '1', 1, 0)[0] ?? []); } catch (Throwable) { $row = []; }
		}
		$event = $d['events'][0] ?? 'update';
		$pk = $meta ? $meta->primaryKey() : '';
		$payload = self::payload($d, 'test-' . nu_id(), $event, $meta ? $meta->id : '', $meta ? $meta->code() : '', $meta ? $meta->table() : '', (string)($row[$pk] ?? 'test'), $row, $event === 'update' ? $row : [], 'test');
		$body = self::body($d, $payload, $row);
		$r = self::send($d, (string)$payload['delivery'], $event, $body);
		NuLog::write('webhook_tested', self::T, $d['id'], ['code' => $r['code'], 'error' => $r['error']]);
		return $r + ['body' => $body];
	}

	/** One HTTP POST. @return array{ok: bool, code: int, ms: int, error: string, response: string, final?: bool} */
	private static function send(array $d, string $callId, string $event, string $body): array
	{
		$t0 = microtime(true);
		$fail = static fn(string $e, bool $final = false) => ['ok' => false, 'code' => 0, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'error' => $e, 'response' => '', 'final' => $final];
		$err = self::checkUrl($d['url'], true, $pin);
		if ($err !== '') return $fail($err, true);
		$secret = self::open($d['secret']);
		$ts = (string)time();
		$headers = ['Content-Type: application/json; charset=utf-8', 'User-Agent: nuBuilderNext/' . NU_VERSION, 'X-NuNext-Event: ' . $event, 'X-NuNext-Delivery: ' . $callId,
			'X-NuNext-Timestamp: ' . $ts, 'X-NuNext-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret)];
		if (is_callable(self::$transport)) {
			[$code, $resp, $e] = (self::$transport)(['id' => $callId], $d['url'], $headers, $body);
		} else {
			if (!function_exists('curl_init')) return $fail(t('wh_err_curl'), true);
			$ch = curl_init($d['url']);
			$resp = '';
			$opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_RETURNTRANSFER => false, CURLOPT_HEADER => false,
				CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$resp): int { if (strlen($resp) < 4096) $resp .= substr($chunk, 0, 4096 - strlen($resp)); return strlen($chunk); }];
			if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
			if ($pin) $opts[CURLOPT_RESOLVE] = [$pin];               // the checked address, not a second DNS answer
			curl_setopt_array($ch, $opts);
			$okRun = curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$e = $okRun === false ? curl_error($ch) : '';
			curl_close($ch);
		}
		$ms = (int)round((microtime(true) - $t0) * 1000);
		$ok = $e === '' && $code >= 200 && $code < 300;
		if (!$ok && $e === '') $e = 'HTTP ' . $code;
		// 4xx except 408 / 429: the receiver refuses this call – trying again does not help
		$final = !$ok && $code >= 400 && $code < 500 && !in_array($code, [408, 429], true);
		return ['ok' => $ok, 'code' => (int)$code, 'ms' => $ms, 'error' => $ok ? '' : (string)$e, 'response' => (string)$resp, 'final' => $final];
	}

	/**
	 * '' = the URL may be called; else the reason. $resolve: also look up the host and check its addresses ($pin = "host:port:ip"
	 * for CURLOPT_RESOLVE). https only, no private / reserved / loopback addresses – unless the setting allows them.
	 */
	public static function checkUrl(string $url, bool $resolve, ?string &$pin = null): string
	{
		$pin = null;
		$p = parse_url($url);
		if ($url === '' || !is_array($p) || empty($p['host']) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) return t('wh_err_url');
		$allow = self::settings()['allow_private'];
		$scheme = strtolower((string)$p['scheme']);
		if ($scheme !== 'https' && !$allow) return t('wh_err_https');
		$host = trim((string)$p['host'], '[]');
		if (!$resolve) {
			if (!$allow && (strcasecmp($host, 'localhost') === 0 || (filter_var($host, FILTER_VALIDATE_IP) && !self::publicIp($host)))) return t('wh_err_private');
			return '';
		}
		$ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::lookup($host);
		if (!$ips) return t('wh_err_dns', $host);
		if (!$allow) foreach ($ips as $ip) if (!self::publicIp($ip)) return t('wh_err_private');
		$port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
		if (!filter_var($host, FILTER_VALIDATE_IP)) $pin = $host . ':' . $port . ':' . (str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0]);
		return '';
	}

	private static function publicIp(string $ip): bool
	{
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
			&& !preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|::ffff:|fe80:|fc|fd)/i', $ip);
	}

	/** @return string[] IPv4 + IPv6 addresses of a host */
	private static function lookup(string $host): array
	{
		$ips = function_exists('gethostbynamel') ? (gethostbynamel($host) ?: []) : [];
		if (function_exists('dns_get_record')) {
			$aaaa = @dns_get_record($host, DNS_AAAA);
			foreach (is_array($aaaa) ? $aaaa : [] as $r) if (!empty($r['ipv6'])) $ips[] = (string)$r['ipv6'];
		}
		return array_values(array_unique($ips));
	}

	// ================================================================= helpers

	/** Scheme + host of a URL for the log (paths may carry tokens, e.g. Teams / Zapier). */
	public static function urlForLog(string $url): string
	{
		$p = parse_url($url);
		return is_array($p) && isset($p['host']) ? ($p['scheme'] ?? 'https') . '://' . $p['host'] . '/…' : '';
	}

	private static function who(): string
	{
		$u = NuAuth::user();
		return $u ? (!empty($u['globeadmin']) ? 'globeadmin' : (string)($u['login'] ?? '')) : 'cron';
	}

	/** The secret in plain text (shown once after it was made). */
	public static function secretOf(array $d): string
	{
		return self::open($d['secret']);
	}

	private static function seal(string $s): string
	{
		$key = (string)nu_cfg('nuConfigSecretKey');
		if ($key === '' || !function_exists('sodium_crypto_secretbox')) return 'plain:' . $s;
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($s, $nonce, hash('sha256', 'webhook|' . $key, true)));
	}

	private static function open(string $s): string
	{
		if (str_starts_with($s, 'plain:')) return substr($s, 6);
		if (!str_starts_with($s, 'enc:')) return $s;
		$key = (string)nu_cfg('nuConfigSecretKey');
		$raw = base64_decode(substr($s, 4), true);
		if ($key === '' || $raw === false || !function_exists('sodium_crypto_secretbox_open') || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
		$out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash('sha256', 'webhook|' . $key, true));
		return $out === false ? '' : $out;
	}
}
