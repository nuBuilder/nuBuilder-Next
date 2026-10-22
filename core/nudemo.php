<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Demo mode (0.8.140), like nuBuilder's – but with real users:
 *   $nuConfigDemoUser = "demo";                       login name or user id; several separated by commas (e.g. one per role)
 *   $nuConfigDemoSavingAllowedIds = "form1,form2";    nuBuilder's setting: forms (id or code) where saving is still allowed
 * Demo users are ordinary zzzzsys_user rows (login name, password, access level). They log in like anybody – or with
 * "Try the demo" on the login page, without a password – and then see everything the globeadmin sees, read only:
 * every button is there, but whatever would change something answers "Not available in the demo". Deleting is never possible,
 * saving only in the allowed forms. Procedures run only with "Demo access" (sph_demo) and cannot write either. No e-mails.
 *
 * Enforced in four places, so that a page added later is read only as well:
 *   1. index.php → guardRequest(): form posts are refused (READ_POSTS lists the few that only read), backups cannot be downloaded;
 *   2. the JSON APIs: formapi (save only in the allowed forms, never delete), builderapi and formatapi (read actions only);
 *   3. NuDB → check(): no INSERT / UPDATE / DELETE / DDL, except nuBuilder Next's session, log, token and debug tables,
 *      nuBuilder's temporary tables (___nu…, #…) and system tables that are created on first use – also for code in forms
 *      and procedures (Before Browse / Before Edit hooks that write fail with the demo message);
 *   4. NuMailer::send(), NuImpersonate::start(), NuPhp::procedure().
 */
final class NuDemo
{
	/** Tables a demo session may still write: its own session row, the log, login tokens / rate limits, nuDebug() results. */
	private const WRITABLE = ['zzzzsys_nunext_session', 'zzzzsys_nunext_log', 'zzzzsys_nunext_token', 'zzzzsys_debug'];

	/** Posts that only read (page => actions); every other post of a demo session is refused. */
	private const READ_POSTS = [
		'procedures'     => ['run'],
		'notifications'  => ['state', 'readall', 'count'],   // own read / hidden state (the session only), number of recipients          // runs a procedure: only with "Demo access", and it cannot write (NuPhp::procedure, NuDB)
		'prompts'        => ['build'],        // prompt generator: builds the text, saves nothing
		'emailtemplates' => ['preview'],      // renders a template with a record
		'reportdesigner' => ['preview', 'fields'],   // report designer: PDF preview of the layout, field list of the data source
		'sqlbuilder'     => ['preview'],      // SQL builder: runs the SELECT (NuDB lets only reading statements through)
		'impersonate'    => ['stop'],
		'tutorial'       => ['done', 'undo', 'hide', 'show', 'reset'],   // own progress – kept in the session for demo users         // the globeadmin acting as the demo user goes back
	];

	private static bool $active = false;     // this request belongs to a demo session
	private static int $permit = 0;          // > 0 while an allowed form saves (NuRecord::save)
	private static ?array $users = null;

	// ----------------------------------------------------------------- who

	/** Configured demo users that exist: [user id => login name]. The globeadmin can never be one. */
	public static function users(): array
	{
		if (self::$users !== null) return self::$users;
		self::$users = [];
		$admin = (string)nu_cfg('nuConfigDBGlobeadminUsername');
		foreach (self::names() as $name) {
			if ($name === 'globeadmin' || ($admin !== '' && $name === $admin)) continue;
			$row = nu_db()->one('SELECT zzzzsys_user_id, sus_login_name FROM zzzzsys_user WHERE zzzzsys_user_id = ? OR sus_login_name = ?', [$name, $name]);
			if ($row) self::$users[(string)$row['zzzzsys_user_id']] = (string)$row['sus_login_name'];
		}
		return self::$users;
	}

	/** The names in $nuConfigDemoUser as written (login names or ids). */
	public static function names(): array
	{
		$cfg = nu_cfg('nuConfigDemoUser');
		if (!is_string($cfg) || trim($cfg) === '') return [];
		return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;]+/', $cfg) ?: []), static fn($n) => $n !== '')));
	}

	public static function configured(): bool
	{
		return self::names() !== [] || self::gaDemo() !== null;
	}

	/**
	 * nuBuilder's demo globeadmin (0.8.341): $nuConfigIsDemo = true with $nuConfigDemoDBGlobeadminUsername / …Password – that login
	 * opens a read-only demo session as the globeadmin, as in nuBuilder. @return array{0: string, 1: string}|null [name, password]
	 */
	public static function gaDemo(): ?array
	{
		$on = nu_cfg('nuConfigIsDemo');
		if (!($on === true || in_array(strtolower(trim((string)$on)), ['1', 'true', 'yes', 'on'], true))) return null;
		$name = trim((string)(nu_cfg('nuConfigDemoDBGlobeadminUsername') ?? ''));
		$pass = (string)(nu_cfg('nuConfigDemoDBGlobeadminPassword') ?? '');
		return $name !== '' && $pass !== '' ? [$name, $pass] : null;
	}

	/** Fingerprint of the demo globeadmin's name and password: changing them ends running demo globeadmin sessions. */
	public static function gaFingerprint(): string
	{
		$ga = self::gaDemo();
		return $ga ? NuAuth::fingerprint('demo-globeadmin|' . $ga[0] . '|' . $ga[1]) : '';
	}

	public static function isDemoUser(string $userId): bool
	{
		return $userId !== '' && isset(self::users()[$userId]);
	}

	/** Called by NuAuth::user() for a demo session: from now on this request is read only. */
	public static function activate(): void
	{
		self::$active = true;
	}

	public static function active(): bool
	{
		return self::$active;
	}

	/** Demo users that can log in now (exist, not expired, with an access level, no API service user), for "Try the demo". */
	public static function loginable(): array
	{
		$out = [];
		foreach (self::users() as $id => $login) {
			$r = nu_db()->one('SELECT u.zzzzsys_user_id, u.sus_login_name, u.sus_name, u.sus_first_name, u.sus_last_name, u.sus_expires_on, u.sus_login_password, a.zzzzsys_access_id
				FROM zzzzsys_user u LEFT JOIN zzzzsys_access a ON a.zzzzsys_access_id = u.sus_zzzzsys_access_id WHERE u.zzzzsys_user_id = ?', [$id]);
			if (!$r || nu_is_expired($r['sus_expires_on']) || (string)($r['zzzzsys_access_id'] ?? '') === '') continue;
			if (class_exists('NuApiClient') && NuApiClient::isServiceUser($id)) continue;
			$full = trim($r['sus_first_name'] . ' ' . $r['sus_last_name']);
			$out[$id] = ['id' => $id, 'login' => $login, 'display' => $full !== '' ? $full : ((string)$r['sus_name'] !== '' ? (string)$r['sus_name'] : $login),
				'hash' => (string)$r['sus_login_password']];
		}
		if ($ga = self::gaDemo()) $out['globeadmin'] = ['id' => 'globeadmin', 'login' => $ga[0], 'display' => 'Globeadmin', 'hash' => ''];
		return $out;
	}

	/**
	 * Status for the globeadmin (Settings page, user form): per configured name the user and what keeps it from working.
	 * @return array<int, array{name:string, id:string, login:string, problems:string[]}>
	 */
	public static function status(): array
	{
		$out = [];
		$admin = (string)nu_cfg('nuConfigDBGlobeadminUsername');
		$ok = self::loginable();
		if ($ga = self::gaDemo()) $out[] = ['name' => $ga[0] . ' ($nuConfigIsDemo)', 'id' => '', 'login' => $ga[0], 'problems' => []];
		foreach (self::names() as $name) {
			$e = ['name' => $name, 'id' => '', 'login' => '', 'problems' => []];
			if ($name === 'globeadmin' || ($admin !== '' && $name === $admin)) { $e['problems'][] = t('demo_warn_globeadmin'); $out[] = $e; continue; }
			$row = nu_db()->one('SELECT zzzzsys_user_id, sus_login_name, sus_expires_on, sus_zzzzsys_access_id FROM zzzzsys_user WHERE zzzzsys_user_id = ? OR sus_login_name = ?', [$name, $name]);
			if (!$row) { $e['problems'][] = t('demo_warn_missing'); $out[] = $e; continue; }
			$e['id'] = (string)$row['zzzzsys_user_id'];
			$e['login'] = (string)$row['sus_login_name'];
			if (!isset($ok[$e['id']])) {
				if (nu_is_expired($row['sus_expires_on'])) $e['problems'][] = t('demo_warn_expired');
				elseif (class_exists('NuApiClient') && NuApiClient::isServiceUser($e['id'])) $e['problems'][] = t('demo_warn_api');
				else $e['problems'][] = t('demo_warn_noaccess');
			}
			$out[] = $e;
		}
		return $out;
	}

	/** Codes of the procedures a demo user may run ("Demo access", like nuBuilder's run list – no system procedures, no hooks). */
	public static function procedures(): array
	{
		try {
			$db = nu_db();
			if (!$db->tableExists('zzzzsys_php')) return [];
			$demo = array_key_exists('sph_demo', $db->columns('zzzzsys_php')) ? " AND COALESCE(sph_demo, '1') = '1'" : '';
			return array_map('strval', array_column($db->all('SELECT sph_code FROM zzzzsys_php WHERE ' . NuProcedure::listCondition() . $demo . ' ORDER BY sph_code'), 'sph_code'));
		} catch (Throwable $e) {
			return [];
		}
	}

	// ----------------------------------------------------------------- saving in allowed forms

	/** $nuConfigDemoSavingAllowedIds: may a demo user save records of this form (id or code)? */
	public static function savingAllowed(string $formId, string $formCode = ''): bool
	{
		$cfg = nu_cfg('nuConfigDemoSavingAllowedIds');
		if (!is_string($cfg) || trim($cfg) === '') return false;
		$ids = array_filter(array_map('trim', preg_split('/[,;]+/', $cfg) ?: []), static fn($n) => $n !== '');
		return in_array($formId, $ids, true) || ($formCode !== '' && in_array($formCode, $ids, true));
	}

	/** Saves in the allowed forms per visitor (IP address) and hour, and for all demo visitors together (0.8.341). */
	public const SAVES_PER_HOUR = 10;
	public const SAVES_PER_HOUR_ALL = 100;

	/** Has this visitor (or all demo visitors together) saved too often in the last hour? Counted in the log (event demo_save). */
	public static function saveLimitReached(): bool
	{
		$since = date('Y-m-d H:i:s', time() - 3600);
		$db = nu_db();
		if ((int)$db->value("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'demo_save' AND log_time > ?", [$since]) >= self::SAVES_PER_HOUR_ALL) return true;
		return (int)$db->value("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'demo_save' AND log_time > ? AND ip = ?", [$since, nu_client_ip()]) >= self::SAVES_PER_HOUR;
	}

	/** Runs $fn with database writes allowed (an allowed form saves, with its own hooks). */
	public static function permitted(callable $fn): mixed
	{
		self::$permit++;
		try {
			return $fn();
		} finally {
			self::$permit--;
		}
	}

	// ----------------------------------------------------------------- 1. requests (index.php)

	public static function guardRequest(string $page, bool $jsonRoute): void
	{
		if ($page === 'configfile') self::refuse('demo_not_available', nu_url('vendor'));   // nuconfig.php holds the database passwords
		if (!nu_is_post()) {
			if ($page === 'update' && isset($_GET['download'])) self::refuse('demo_not_available', nu_url('update'));   // a backup holds the whole database
			return;
		}
		if ($jsonRoute) return;                                                  // formapi / builderapi / formatapi decide per action
		if (in_array((string)($_POST['action'] ?? ''), self::READ_POSTS[$page] ?? [], true)) return;
		self::refuse();
	}

	/** "Not available in the demo": JSON for background requests, otherwise a message on the same page (read again) or on $to. */
	public static function refuse(string $key = 'demo_not_available', ?string $to = null): never
	{
		$msg = t($key);
		if (self::wantsJson()) {
			http_response_code(403);
			header('Content-Type: application/json; charset=utf-8');
			exit(json_encode(['ok' => false, 'demo' => true, 'message' => $msg, 'error' => $msg, 'errors' => [['message' => $msg, 'field' => '']]], JSON_UNESCAPED_UNICODE));
		}
		nu_flash('warning', $msg);
		nu_redirect($to ?? 'index.php?' . (string)($_SERVER['QUERY_STRING'] ?? 'p=home'));
	}

	private static function wantsJson(): bool
	{
		return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
			|| str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')
			|| isset($_SERVER['HTTP_X_CSRF_TOKEN']) || isset($_SERVER['HTTP_X_REQUESTED_WITH']);
	}

	// ----------------------------------------------------------------- 3. database (NuDB::run / exec)

	public static function check(string $sql): void
	{
		if (!self::$active || self::$permit > 0) return;
		if (!self::sqlAllowed($sql)) throw new NuDemoBlocked(t('demo_not_available'));
	}

	/** Does this statement (or batch) only read – or write a table a demo session may write? */
	public static function sqlAllowed(string $sql): bool
	{
		foreach (self::statements($sql) as $s) {
			if (!self::statementAllowed($s)) return false;
		}
		return true;
	}

	/**
	 * Only reading statements – SELECT / WITH … SELECT without INTO, OUTFILE, LOAD_FILE, locks (FOR UPDATE, LOCK IN SHARE MODE) and without
	 * a write after the common table expressions. Stricter than sqlAllowed() (no writable tables at all): for queries typed by a user.
	 */
	public static function readOnlySql(string $sql): bool
	{
		$list = self::statements($sql);
		if (count($list) !== 1) return false;                            // exactly one statement
		$s = ltrim($list[0], " \t\r\n(");
		if (!preg_match('/^(SELECT|WITH)\b/i', $s)) return false;
		$s = (string)preg_replace('/`[^`]*`|\[[^\]]*\]/', 'x', $s);              // quoted names (`update`, [delete]) are no keywords
		if (preg_match('/\bINTO\b|\bLOAD_FILE\s*\(|\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\bFOR\s+SHARE\b|\bOPENROWSET\b|\bOPENDATASOURCE\b|\bxp_\w+/i', $s)) return false;
		return !preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|EXEC|EXECUTE|CALL|DO|HANDLER|LOAD|SET|DECLARE|USE)\b/i', $s);
	}

	/** Tables typed SQL of a demo session may never read: users (hashes, sus_json with login tokens / 2FA secrets), sessions, mail and config secrets. */
	public const HIDDEN_TABLES = ['zzzzsys_user', 'zzzzsys_session', 'zzzzsys_setup', 'zzzzsys_config'];
	/** Schemas / prefixes with the same effect: nuBuilder Next's own tables, the database server's catalogs (mysql.user, sys.sql_logins …). */
	private const HIDDEN_WORDS = ['information_schema', 'mysql', 'performance_schema', 'sys'];

	/**
	 * Does typed SQL of a demo session (SQL builder, dashboards) mention a hidden table – anywhere, not only after FROM / JOIN
	 * (comma joins, STRAIGHT_JOIN, parentheses, sub-queries, schema names)? Every word outside strings and comments counts (0.8.341).
	 */
	public static function touchesHidden(string $sql): bool
	{
		foreach (self::statements($sql) as $s) {
			preg_match_all('/[A-Za-z_][\w$]*/', str_replace(['`', '[', ']'], ' ', $s), $m);
			foreach ($m[0] as $w) {
				$w = strtolower($w);
				if (in_array($w, self::HIDDEN_TABLES, true) || in_array($w, self::HIDDEN_WORDS, true) || str_starts_with($w, 'zzzzsys_nunext_')) return true;
			}
		}
		return false;
	}

	/** Names of the tables a statement mentions (after FROM / JOIN), lower case without quotes and schema. */
	public static function tablesOf(string $sql): array
	{
		$out = [];
		foreach (self::statements($sql) as $s) {
			if (preg_match_all('/\b(?:FROM|JOIN)\s+([`"\[\]\w.$#]+)/i', $s, $m)) foreach ($m[1] as $t) $out[] = self::name($t);
		}
		return array_values(array_unique($out));
	}

	/** Statements without comments and string contents ('…' → '', "…" → `…` for names, '' otherwise), split at ";". */
	private static function statements(string $sql): array
	{
		$out = '';
		$n = strlen($sql);
		for ($i = 0; $i < $n; $i++) {
			$c = $sql[$i];
			$next = $i + 1 < $n ? $sql[$i + 1] : '';
			if ($c === '-' && $next === '-' || $c === '#' && ($i === 0 || ctype_space($sql[$i - 1]) || $sql[$i - 1] === ';') && !preg_match('/^#\w/', substr($sql, $i, 2))) {
				$e = strpos($sql, "\n", $i);                                        // -- comment / # comment (not a #temp table)
				$i = $e === false ? $n : $e;
				$out .= ' ';
				continue;
			}
			if ($c === '/' && $next === '*') {
				$e = strpos($sql, '*/', $i + 2);
				$i = $e === false ? $n : $e + 1;
				$out .= ' ';
				continue;
			}
			if ($c === "'" || $c === '"') {
				$j = $i + 1;
				$buf = '';
				while ($j < $n) {
					if ($sql[$j] === '\\') { $buf .= substr($sql, $j, 2); $j += 2; continue; }
					if ($sql[$j] === $c) {
						if ($j + 1 < $n && $sql[$j + 1] === $c) { $buf .= $c; $j += 2; continue; }   // '' or "" inside
						break;
					}
					$buf .= $sql[$j++];
				}
				$out .= $c === '"' && preg_match('/^[\w.]+$/', $buf) ? '`' . $buf . '`' : "''";
				$i = $j;
				continue;
			}
			$out .= $c;
		}
		return array_values(array_filter(array_map('trim', explode(';', $out)), static fn($s) => $s !== ''));
	}

	private static function statementAllowed(string $s): bool
	{
		$s = ltrim($s, " \t\r\n(");
		if (!preg_match('/^([A-Za-z]+)/', $s, $m)) return false;
		$verb = strtoupper($m[1]);
		switch ($verb) {
			case 'SELECT':
				if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $s)) return false;
				if (preg_match_all('/\bINTO\s+([^\s,(]+)/i', $s, $mm)) {                // MSSQL SELECT … INTO new_table
					foreach ($mm[1] as $t) if ($t[0] !== '@' && !self::isTemp($t)) return false;
				}
				return true;
			case 'WITH':                                                           // common table expressions in front of a SELECT
				return !preg_match('/\)\s*(INSERT|UPDATE|DELETE|MERGE|REPLACE)\b/i', $s) && !preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $s);
			case 'SHOW': case 'DESCRIBE': case 'DESC': case 'EXPLAIN': case 'PRAGMA': case 'VALUES':
			case 'BEGIN': case 'START': case 'COMMIT': case 'ROLLBACK': case 'SAVEPOINT': case 'RELEASE': case 'LOCK': case 'UNLOCK': case 'DECLARE': case 'USE':
				return true;
			case 'SET':
				return !preg_match('/^SET\s+(GLOBAL|PERSIST|PERSIST_ONLY|PASSWORD|ROLE|DEFAULT\s+ROLE)\b/i', $s);
			case 'INSERT': case 'REPLACE':
				return preg_match('/^(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?([^\s(]+)/i', $s, $t) === 1 && self::writable($t[1]);
			case 'UPDATE':
				return preg_match('/^UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*([^\s,]+)/i', $s, $t) === 1 && self::writable($t[1]);
			case 'DELETE':
				return preg_match('/^DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*FROM\s+([^\s,]+)/i', $s, $t) === 1 && self::writable($t[1]);
			case 'TRUNCATE':
				return preg_match('/^TRUNCATE\s+(?:TABLE\s+)?([^\s;]+)/i', $s, $t) === 1 && self::isTemp($t[1]);
			case 'CREATE':
				if (preg_match('/^CREATE\s+(TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([^\s(]+)/i', $s, $t)) {
					// temporary tables, and nuBuilder / nuBuilder Next system tables that are created when first used
					return $t[1] !== '' || self::isTemp($t[2]) || str_starts_with(self::name($t[2]), 'zzzzsys_');
				}
				if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+\S+\s+ON\s+([^\s(]+)/i', $s, $t)) return self::isTemp($t[1]) || str_starts_with(self::name($t[1]), 'zzzzsys_nunext_');
				return false;
			case 'DROP':
				return preg_match('/^DROP\s+(TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?([^\s,;]+)/i', $s, $t) === 1 && ($t[1] !== '' || self::isTemp($t[2]));
			case 'ALTER': case 'RENAME':
				// nuBuilder Next's own tables: renamed / extended automatically after an update
				return preg_match('/^(?:ALTER|RENAME)\s+TABLE\s+([^\s,;]+)/i', $s, $t) === 1 && (self::isTemp($t[1]) || preg_match('/^(zzzzsys_)?nunext_/', self::name($t[1])) === 1);
			default:
				return false;                                                       // CALL, EXEC, MERGE, GRANT, LOAD, …
		}
	}

	/** Table name without quotes, brackets and schema, lower case. */
	private static function name(string $t): string
	{
		$t = str_replace(['`', '"', '[', ']'], '', $t);
		$p = strrpos($t, '.');
		return strtolower($p === false ? $t : substr($t, $p + 1));
	}

	/** nuBuilder's temporary tables (#TABLE_ID# = ___nu…) and MSSQL temp tables (#…). */
	private static function isTemp(string $t): bool
	{
		$n = self::name($t);
		return str_starts_with($n, '___nu') || str_starts_with($n, '#');
	}

	private static function writable(string $t): bool
	{
		return self::isTemp($t) || in_array(self::name($t), self::WRITABLE, true);
	}

	// ----------------------------------------------------------------- what a demo visitor does not see

	/** IP addresses of sessions and log entries (other visitors' personal data). */
	public static function ip(?string $ip): string
	{
		return self::$active && (string)$ip !== '' ? '•••' : (string)$ip;
	}

	/** Settings whose value is a password or key. */
	public static function secretSetting(string $name): bool
	{
		return preg_match('/pass(word)?|secret|apikey|api_key|token|credential/i', $name) === 1 && !preg_match('/TokenValidity|UseMd5PasswordHash|PasswordMinLength|PasswordUpgrade/i', $name);
	}
}

/** A database change refused in a demo session (message: "Not available in the demo."). */
final class NuDemoBlocked extends RuntimeException
{
}
