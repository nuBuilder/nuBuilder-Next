<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder Next's own tables (prefix zzzzsys_nunext_, primary key <table>_id like nuBuilder's tables), created on first use
 * on MySQL, MSSQL and SQLite. nuBuilder never reads them; its update keeps them (it only rebuilds the tables it knows),
 * and its app cloner / backup treat them as system tables.
 */
final class NuSchema
{
	public const VERSION = 17;

	private const TABLES = [
		'zzzzsys_nunext_log' => [
			'pk' => 'zzzzsys_nunext_log_id',
			'cols' => [['log_time', 'datetime'], ['user_id', 'text', '25'], ['login', 'text', '50'], ['ip', 'text', '45'],
				['event', 'text', '40'], ['object', 'text', '100'], ['record_id', 'text', '100'], ['details', 'longtext']],
			'index' => ['log_time', 'event'],
		],
		'zzzzsys_nunext_form_history' => [
			'pk' => 'zzzzsys_nunext_form_history_id',
			'cols' => [['form_id', 'text', '25'], ['created_at', 'datetime'], ['login', 'text', '50'], ['action', 'text', '60'], ['snapshot', 'longtext']],
			'index' => ['form_id'],
		],
		'zzzzsys_nunext_form_draft' => [                          // form drafts (0.8.386, core/form/nuformdraft.php)
			'pk' => 'zzzzsys_nunext_form_draft_id',
			'cols' => [['form_id', 'text', '25'], ['changeset_id', 'text', '25'], ['status', 'text', '12'], ['note', 'text', '1000'],
				['base_hash', 'text', '64'], ['base_snapshot', 'longtext'], ['snapshot', 'longtext'], ['undo_list', 'longtext'], ['rev', 'int'],
				['preview_saves', 'int'], ['testers', 'text', '4000'], ['ddl', 'longtext'],
				['created_at', 'datetime'], ['created_by', 'text', '50'], ['updated_at', 'datetime'], ['updated_by', 'text', '50'],
				['submitted_at', 'datetime'], ['submitted_by', 'text', '50'], ['review_note', 'text', '1000'],
				['published_at', 'datetime'], ['published_by', 'text', '50'], ['version', 'int'], ['history_id', 'text', '25']],
			'index' => ['form_id', 'status'],
		],
		'zzzzsys_nunext_page' => [                                // form type "page" (0.8.387, core/nupage.php)
			'pk' => 'zzzzsys_nunext_page_id',                  // = zzzzsys_form_id of the page form (one row per form)
			'cols' => [['open_mode', 'text', '10'], ['iframe_height', 'text', '10'], ['settings', 'longtext'], ['tables_json', 'longtext'],
				['csp', 'text', '2000'], ['note', 'longtext'], ['updated_at', 'datetime'], ['updated_by', 'text', '50']],
			'index' => [],
		],
		'zzzzsys_nunext_user_2fa' => [
			'pk' => 'zzzzsys_nunext_user_2fa_id',              // = zzzzsys_user_id or 'globeadmin'
			'cols' => [['totp_secret', 'text', '255'], ['created_at', 'datetime'], ['last_step', 'bigint']],
			'index' => [],
		],
		'zzzzsys_nunext_session' => [
			'pk' => 'zzzzsys_nunext_session_id',               // sha256 of the PHP session id
			'cols' => [['user_id', 'text', '25'], ['login', 'text', '50'], ['display', 'text', '100'], ['via', 'text', '20'], ['ip', 'text', '45'],
				['user_agent', 'text', '255'], ['login_at', 'datetime'], ['last_seen', 'datetime']],
			'index' => ['last_seen'],
		],
		'zzzzsys_nunext_token' => [
			'pk' => 'zzzzsys_nunext_token_id',                 // sha256 of the token
			'cols' => [['kind', 'text', '20'], ['user_id', 'text', '25'], ['created_at', 'datetime'], ['expires_at', 'datetime'],
				['attempts', 'int'], ['data', 'text', '255']],
			'index' => ['user_id'],
		],
		'zzzzsys_nunext_api_client' => [                          // REST API clients (core/nuapi.php)
			'pk' => 'zzzzsys_nunext_api_client_id',
			'cols' => [['name', 'text', '100'], ['client_id', 'text', '64'], ['secret_hash', 'text', '64'], ['secret_hash_old', 'text', '64'],
				['secret_old_until', 'datetime'], ['scopes', 'text', '1000'], ['user_id', 'text', '25'], ['ip_allow', 'text', '1000'],
				['rate_limit', 'int'], ['active', 'text', '1'], ['expires_on', 'datetime'], ['created_at', 'datetime'], ['created_by', 'text', '50'],
				['last_used_at', 'datetime'], ['fails', 'int'], ['locked_until', 'datetime']],
			'index' => ['client_id', 'user_id'],
		],
		'zzzzsys_nunext_api_token' => [                           // access tokens: HMAC of the token, never the token itself
			'pk' => 'zzzzsys_nunext_api_token_id',
			'cols' => [['client_ref', 'text', '25'], ['scopes', 'text', '1000'], ['created_at', 'datetime'], ['expires_at', 'datetime']],
			'index' => ['client_ref', 'expires_at'],
		],
		'zzzzsys_nunext_api_rate' => [                            // requests per client and minute (rate limit)
			'pk' => 'zzzzsys_nunext_api_rate_id',                  // <client>|<YmdHi>
			'cols' => [['hits', 'int'], ['minute_at', 'datetime']],
			'index' => ['minute_at'],
		],
		'zzzzsys_nunext_setting' => [                             // settings of the installation as JSON (home_layout = globeadmin start page)
			'pk' => 'zzzzsys_nunext_setting_id',
			'cols' => [['setting_value', 'longtext'], ['updated_at', 'datetime'], ['updated_by', 'text', '50']],
			'index' => [],
		],
		'zzzzsys_nunext_dashboard' => [                           // dashboards (0.8.298): start page per access level and HTML objects of forms
			'pk' => 'zzzzsys_nunext_dashboard_id',
			'cols' => [['code', 'text', '50'], ['title', 'text', '200'], ['description', 'text', '1000'], ['on_home', 'text', '1'], ['access', 'text', '4000'],
				['date_range', 'text', '20'], ['sort_order', 'int'], ['updated_at', 'datetime'], ['updated_by', 'text', '50']],
			'index' => ['code'],
		],
		'zzzzsys_nunext_widget' => [                              // widgets of a dashboard: KPI tile, chart, small table
			'pk' => 'zzzzsys_nunext_widget_id',
			'cols' => [['dashboard_id', 'text', '25'], ['sort_order', 'int'], ['type', 'text', '10'], ['title', 'text', '200'], ['source', 'text', '10'],
				['query', 'longtext'], ['options', 'longtext']],
			'index' => ['dashboard_id'],
		],
		'zzzzsys_nunext_admin_role' => [                          // admin roles (0.8.323): a part of the globeadmin's rights (core/nuadmin.php)
			'pk' => 'zzzzsys_nunext_admin_role_id',
			'cols' => [['code', 'text', '50'], ['name', 'text', '100'], ['description', 'text', '1000'], ['rights', 'text', '4000'], ['sort_order', 'int'],
				['updated_at', 'datetime'], ['updated_by', 'text', '50']],
			'index' => ['code'],
		],
		'zzzzsys_nunext_admin_user' => [                          // the admin role of a user (key = zzzzsys_user_id)
			'pk' => 'zzzzsys_nunext_admin_user_id',
			'cols' => [['role_id', 'text', '25'], ['updated_at', 'datetime'], ['updated_by', 'text', '50']],
			'index' => ['role_id'],
		],
		'zzzzsys_nunext_list_view' => [                           // list views (0.8.329): layouts of nuBuilder Next's own lists (core/nulistview.php)
			'pk' => 'zzzzsys_nunext_list_view_id',
			'cols' => [['list_key', 'text', '100'], ['scope', 'text', '10'], ['owner', 'text', '50'], ['name', 'text', '100'], ['access_ids', 'text', '4000'],
				['definition', 'longtext'], ['sort_order', 'int'], ['updated_at', 'datetime'], ['updated_by', 'text', '50']],
			'index' => ['list_key'],
		],
		'zzzzsys_nunext_audit' => [                               // audit trail (0.8.350, core/nuaudit.php): one row per changed column
			'pk' => 'zzzzsys_nunext_audit_id',
			'cols' => [['change_id', 'text', '25'], ['changed_at', 'datetime'], ['user_id', 'text', '25'], ['login', 'text', '50'], ['ip', 'text', '45'],
				['action', 'text', '10'], ['source', 'text', '10'], ['table_name', 'text', '100'], ['record_id', 'text', '100'], ['form_id', 'text', '25'],
				['form_code', 'text', '100'], ['parent_table', 'text', '100'], ['parent_id', 'text', '100'], ['column_name', 'text', '100'],
				['old_value', 'text', '4000'], ['new_value', 'text', '4000']],
			'index' => ['change_id', 'changed_at', 'table_name', 'record_id', 'parent_id', 'user_id'],
		],
		'zzzzsys_nunext_notification' => [                        // notification centre (0.8.357, core/nunotify.php)
			'pk' => 'zzzzsys_nunext_notification_id',
			'cols' => [['title', 'text', '200'], ['body', 'text', '4000'], ['format', 'text', '10'], ['level', 'text', '10'], ['link', 'text', '1000'], ['audience', 'text', '4000'],
				['mode', 'text', '10'], ['publish_at', 'datetime'], ['expires_at', 'datetime'], ['pinned', 'text', '1'], ['must_confirm', 'text', '1'], ['email', 'text', '1'],
				['sent_mail_at', 'datetime'], ['status', 'text', '10'], ['created_at', 'datetime'], ['created_by', 'text', '50'], ['created_by_id', 'text', '25'], ['updated_at', 'datetime']],
			'index' => ['status', 'publish_at', 'created_by_id'],
		],
		'zzzzsys_nunext_user_field' => [                          // custom user fields (0.8.454, core/nuuserfields.php); values: zzzzsys_nunext_user_ext
			'pk' => 'zzzzsys_nunext_user_field_id',
			'cols' => [['code', 'text', '64'], ['label', 'text', '200'], ['type', 'text', '20'], ['options', 'longtext'], ['required', 'text', '1'],
				['is_unique', 'text', '1'], ['default_value', 'text', '1000'], ['hint', 'text', '1000'], ['edit_by', 'text', '20'], ['in_list', 'text', '1'],
				['sort', 'int'], ['created_at', 'datetime'], ['created_by', 'text', '50'], ['updated_at', 'datetime'], ['updated_by', 'text', '50']],
			'index' => ['code'],
		],
		'zzzzsys_nunext_recent' => [                              // side bar (0.8.460, core/nusidebar.php): pages / forms a user used last
			'pk' => 'zzzzsys_nunext_recent_id',
			'cols' => [['user_id', 'text', '25'], ['item', 'text', '100'], ['used_at', 'datetime'], ['stamp', 'text', '20']],   // stamp: YmdHis + microseconds (order)
			'index' => ['user_id'],
		],
		'zzzzsys_nunext_presence' => [                            // concurrent edits (0.8.401, core/form/nuconflict.php): who has which record open
			'pk' => 'zzzzsys_nunext_presence_id',
			'cols' => [['form_id', 'text', '25'], ['record_id', 'text', '100'], ['user_id', 'text', '25'], ['login', 'text', '50'], ['name', 'text', '100'],
				['edited', 'text', '1'], ['opened_at', 'datetime'], ['seen_at', 'datetime']],
			'index' => ['record_id', 'seen_at'],
		],
		'zzzzsys_nunext_notification_user' => [                   // per notification and user: on the fixed list, read / hidden / confirmed
			'pk' => 'zzzzsys_nunext_notification_user_id',
			'cols' => [['notification_id', 'text', '25'], ['user_id', 'text', '25'], ['recipient', 'text', '1'], ['read_at', 'datetime'], ['hidden_at', 'datetime'], ['confirmed_at', 'datetime']],
			'index' => ['notification_id', 'user_id'],
		],
		'zzzzsys_nunext_api_request' => [                         // API playground: saved requests (kind 'request') and settings per login (kind 'settings')
			'pk' => 'zzzzsys_nunext_api_request_id',
			'cols' => [['kind', 'text', '10'], ['name', 'text', '100'], ['folder', 'text', '100'], ['method', 'text', '10'], ['path', 'text', '1000'],
				['headers', 'text', '4000'], ['body', 'longtext'], ['settings', 'text', '4000'], ['created_by', 'text', '50'], ['created_at', 'datetime'],
				['updated_at', 'datetime']],
			'index' => ['kind', 'created_by'],
		],
	];

	/** Set by the globeadmin's sign-in and the installer: this request may create the own tables (0.8.462, gap analysis B2). */
	private static bool $allowed = false;
	private static ?bool $present = null;

	public static function allow(): void
	{
		self::$allowed = true;
	}

	/** nuBuilder Next's own tables exist (zzzzsys_nunext_migration is the first one of NuSchema::create). */
	public static function present(): bool
	{
		if (self::$present) return true;
		try {
			return self::$present = nu_db()->tableExists('zzzzsys_nunext_migration');
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * May this request change the database structure? (0.8.462, B2) Copying nuBuilder Next next to a live nuBuilder must not change
	 * its database: the own tables are created by the installer, cron.php or the globeadmin's first sign-in – never by an anonymous
	 * request (login page, failed login, public links) and never while the database is blocked (NuInstall::existingBlocked). Once
	 * they exist, signed-in requests apply the database steps of an update, as before.
	 */
	private static function mayChange(): bool
	{
		if (self::$allowed || PHP_SAPI === 'cli') return true;
		if (!self::present()) return false;
		if (empty($_SESSION['nu_auth']['id'])) return false;
		try {
			return !NuInstall::existingBlocked();
		} catch (Throwable) {
			return false;
		}
	}

	public static function ensure(): void
	{
		static $done = false;
		if ($done) return;
		if (($_SESSION['nu_schema'] ?? 0) === self::VERSION . '/' . NU_VERSION) { $done = true; return; }
		if (!self::mayChange()) return;                   // asked again later in this request (e.g. after the globeadmin's sign-in)
		$done = true;

		self::create();
		try {
			NuMigrate::run();                              // database steps of an update (also after a manual one)
		} catch (Throwable $e) {
			error_log('nuBuilder Next migration: ' . $e->getMessage());
		}
		$_SESSION['nu_schema'] = self::VERSION . '/' . NU_VERSION;
	}

	/** Definitions of nuBuilder Next's own tables (install/db_changes.json of a package, 0.8.346). */
	public static function definitions(): array
	{
		return self::TABLES;
	}

	private const HASH_PK = ['zzzzsys_nunext_token', 'zzzzsys_nunext_session', 'zzzzsys_nunext_api_token', 'zzzzsys_nunext_api_rate'];

	/** CREATE TABLE (+ CREATE INDEX) statements of an own table for the current database. @return string[] */
	public static function createSql(string $table, array $def): array
	{
		$db = nu_db();
		NuDB::ident($table);
		$lines = [NuDB::ident((string)$def['pk']) . ' ' . (in_array($table, self::HASH_PK, true) ? NuDDL::sqlType('text', '64') : NuDDL::sqlType('id')) . ' NOT NULL PRIMARY KEY'];
		foreach ((array)$def['cols'] as $c) $lines[] = NuDB::ident((string)$c[0]) . ' ' . NuDDL::sqlType((string)$c[1], (string)($c[2] ?? '')) . ' NULL';
		$sql = "CREATE TABLE $table (\n  " . implode(",\n  ", $lines) . "\n)";
		if ($db->type === 'mysql') $sql .= NuDDL::mysqlTableOptions();
		$out = [$sql];
		foreach ((array)($def['index'] ?? []) as $col) $out[] = 'CREATE INDEX ix_' . $table . '_' . NuDB::ident((string)$col) . " ON $table ($col)";
		return $out;
	}

	/** ALTER TABLE … ADD for a column of an own table (always NULL, so existing rows keep working). */
	public static function addColumnSql(string $table, array $c): string
	{
		$t = NuDDL::sqlType((string)$c[1], (string)($c[2] ?? ''));
		$col = NuDB::ident((string)$c[0]);
		return nu_db()->type === 'sqlsrv' ? 'ALTER TABLE ' . NuDB::ident($table) . " ADD $col $t NULL" : 'ALTER TABLE ' . NuDB::ident($table) . " ADD COLUMN $col $t NULL";
	}

	/** Columns of $def that $table does not have yet. @return list<array> */
	public static function missingColumns(string $table, array $def): array
	{
		$have = array_change_key_case(nu_db()->columns($table));
		return array_values(array_filter((array)$def['cols'], static fn($c) => !isset($have[strtolower((string)$c[0])])));
	}

	/** Creates missing tables and, since 0.8.346, adds missing columns to existing ones. Safe to repeat. */
	public static function create(): void
	{
		$db = nu_db();
		foreach (self::TABLES as $table => $def) {
			if ($db->tableExists($table)) {
				try {
					foreach (self::missingColumns($table, $def) as $c) { $db->run(self::addColumnSql($table, $c)); NuDB::forgetColumns($table); }
				} catch (Throwable $e) {
					error_log("nuBuilder Next: column of $table not added: " . $e->getMessage());
				}
				continue;
			}
			try {
				foreach (self::createSql($table, $def) as $sql) $db->run($sql);
			} catch (Throwable $e) {
				if (!$db->tableExists($table)) throw $e;   // created concurrently by another request
			}
		}
	}
}

/** Login and change log (zzzzsys_nunext_log). Never throws – logging must not break the action it records. */
final class NuLog
{
	public static function write(string $event, string $object = '', string $recordId = '', array $details = [], ?array $user = null): void
	{
		try {
			NuSchema::ensure();
			$u = $user ?? NuAuth::currentRaw();
			if ($user === null && class_exists('NuImpersonate', false) && ($by = NuImpersonate::by()) !== '') $details += ['acting_globeadmin' => $by];
			nu_db()->insert('zzzzsys_nunext_log', [
				'zzzzsys_nunext_log_id' => nu_id(),
				'log_time'      => date('Y-m-d H:i:s'),
				'user_id'       => mb_substr((string)($u['id'] ?? ''), 0, 25),
				'login'         => mb_substr((string)($u['login'] ?? ''), 0, 50),
				'ip'            => mb_substr(nu_client_ip(), 0, 45),
				'event'         => mb_substr($event, 0, 40),
				'object'        => mb_substr($object, 0, 100),
				'record_id'     => mb_substr($recordId, 0, 100),
				'details'       => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
			]);
		} catch (Throwable $e) {
			error_log('nuBuilder Next log: ' . $e->getMessage());
		}
	}

	/**
	 * Field changes between two rows: ['field' => [old, new]] (values shortened). Passwords, keys, tokens and password hashes
	 * are written as ••• (0.8.207) – the log keeps that they changed, not the values.
	 */
	public static function diff(array $old, array $new): array
	{
		$out = [];
		foreach ($new as $k => $v) {
			$o = $old[$k] ?? null;
			if ((string)$o === (string)$v) continue;
			if (NuSecurity::secretName((string)$k) || NuSecurity::hashValue($o) || NuSecurity::hashValue($v)) {
				$out[$k] = [$o === null || $o === '' ? $o : '•••', $v === null || $v === '' ? $v : '•••'];
				continue;
			}
			$out[$k] = [self::short($o), self::short($v)];
		}
		return $out;
	}

	/** Deletes log rows older than the Security check setting "Keep the log" (days; 0 = keep). @return int rows deleted */
	public static function purge(): int
	{
		$days = (int)NuSecurity::get('log_keep_days');
		if ($days <= 0) return 0;
		try {
			return nu_db()->run('DELETE FROM zzzzsys_nunext_log WHERE log_time < ?', [date('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
		} catch (Throwable $e) {
			error_log('nuBuilder Next log purge: ' . $e->getMessage());
			return 0;
		}
	}

	private static function short(mixed $v): ?string
	{
		if ($v === null) return null;
		$v = (string)$v;
		if (str_starts_with($v, '{"file":')) return '[file]';
		return mb_strlen($v) > 200 ? mb_substr($v, 0, 200) . '…' : $v;
	}
}

/**
 * One-time tokens (zzzzsys_nunext_token): 2FA e-mail codes, password reset links, trusted devices.
 * Only a SHA-256 hash of the token is stored.
 */
final class NuToken
{
	public static function create(string $kind, string $userId, int $ttlSeconds, string $token = '', string $data = ''): string
	{
		NuSchema::ensure();
		$token = $token !== '' ? $token : bin2hex(random_bytes(32));
		nu_db()->insert('zzzzsys_nunext_token', [
			'zzzzsys_nunext_token_id' => hash('sha256', $kind . '|' . $userId . '|' . $token),
			'kind' => $kind, 'user_id' => $userId, 'created_at' => date('Y-m-d H:i:s'),
			'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds), 'attempts' => 0, 'data' => $data,
		]);
		return $token;
	}

	/** Row of a valid (not expired) token, or null. */
	public static function find(string $kind, string $userId, string $token): ?array
	{
		NuSchema::ensure();
		$row = nu_db()->one('SELECT * FROM zzzzsys_nunext_token WHERE zzzzsys_nunext_token_id = ? AND kind = ?', [hash('sha256', $kind . '|' . $userId . '|' . $token), $kind]);
		if (!$row) return null;
		if (strtotime((string)$row['expires_at']) < time()) {
			self::delete($row['zzzzsys_nunext_token_id']);
			return null;
		}
		return $row;
	}

	/** Reset tokens are looked up without knowing the user: the token itself carries the user id ("<user>.<secret>"). */
	public static function delete(string $id): void
	{
		nu_db()->delete('zzzzsys_nunext_token', 'zzzzsys_nunext_token_id', $id);
	}

	public static function deleteFor(string $kind, string $userId): void
	{
		NuSchema::ensure();
		nu_db()->run('DELETE FROM zzzzsys_nunext_token WHERE kind = ? AND user_id = ?', [$kind, $userId]);
	}

	public static function latest(string $kind, string $userId): ?array
	{
		NuSchema::ensure();
		$rows = nu_db()->page('SELECT * FROM zzzzsys_nunext_token WHERE kind = ? AND user_id = ?', [$kind, $userId], 'created_at DESC', 1, 0);
		return $rows[0] ?? null;
	}

	public static function countSince(string $kind, string $userId, int $seconds): int
	{
		NuSchema::ensure();
		return (int)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_nunext_token WHERE kind = ? AND user_id = ? AND created_at > ?',
			[$kind, $userId, date('Y-m-d H:i:s', time() - $seconds)]);
	}

	public static function purgeExpired(): void
	{
		try {
			nu_db()->run('DELETE FROM zzzzsys_nunext_token WHERE expires_at < ?', [date('Y-m-d H:i:s')]);
		} catch (Throwable) {
		}
	}
}

/** Files that nuBuilder Next takes from the nuBuilder installation (PHPMailer, Font Awesome). */
final class NuBuilderLib
{
	/** nuBuilder's root folder: $nuConfigNuBuilderPath, or the parent folder of nuBuilder Next (nuBuilder Next installed as a subfolder). */
	public static function path(): string
	{
		$cfg = trim((string)nu_cfg('nuConfigNuBuilderPath'));
		return rtrim($cfg !== '' ? self::absPath($cfg) : dirname(NU_ROOT), '/\\');
	}

	public static function absPath(string $p): string
	{
		$p = rtrim($p, '/\\');
		return (str_starts_with($p, '/') || preg_match('~^[A-Za-z]:[/\\\\]~', $p)) ? $p : NU_ROOT . '/' . $p;
	}

	/**
	 * Folder with css/all.min.css: $nuConfigFontAwesomePath, else <nuBuilder>/third_party/fontawesome (the same icons as nuBuilder), else
	 * the copy bundled with nuBuilder Next (vendor/bundled/fontawesome-<version>, 0.8.409).
	 */
	public static function fontAwesomeDir(): ?string
	{
		static $dir = false;
		if ($dir !== false) return $dir;
		$cfg = trim((string)nu_cfg('nuConfigFontAwesomePath'));
		foreach ([$cfg !== '' ? self::absPath($cfg) : '', self::path() . '/third_party/fontawesome', NU_ROOT . '/' . NU_FONTAWESOME] as $d) {
			if ($d !== '' && is_file("$d/css/all.min.css")) return $dir = $d;
		}
		return $dir = null;
	}

	/** <link> for Font Awesome: the bundled copy directly, nuBuilder's (maybe outside the web root) through ?p=fa; '' = none. */
	public static function fontAwesomeTag(): string
	{
		$dir = self::fontAwesomeDir();
		if ($dir === null) return '';
		if ($dir === NU_ROOT . '/' . NU_FONTAWESOME) return '<link rel="stylesheet" href="' . h(nu_asset(NU_FONTAWESOME . '/css/all.min.css')) . '">';
		$v = base_convert((string)(int)filemtime("$dir/css/all.min.css"), 10, 36);
		return '<link rel="stylesheet" href="index.php?p=fa&amp;f=css/all.min.css&amp;v=' . $v . '">';
	}
}

/**
 * Client IP: REMOTE_ADDR; with $nuConfigTrustProxy the *last* X-Forwarded-For entry – the one the proxy added. The first entries
 * come from the client and can be anything (before 0.8.207 the first one was used).
 */
function nu_client_ip(): string
{
	$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	if (nu_cfg('nuConfigTrustProxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$list = array_values(array_filter(array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])), static fn($s) => (string)$s !== ''));
		$last = $list ? (string)end($list) : '';
		if (filter_var($last, FILTER_VALIDATE_IP) !== false) $ip = $last;
	}
	return $ip;
}

/**
 * Request over HTTPS – also behind a proxy that ends TLS ($nuConfigTrustProxy and X-Forwarded-Proto: https).
 * Reads nuconfig.php directly (no settings table): it decides the cookie flags before the session starts.
 */
function nu_https(): bool
{
	if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
	if (strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) !== 'https') return false;
	return is_file(nu_config_path()) && !empty(nu_config()['nuConfigTrustProxy']);
}

/** Absolute URL of this installation (links in pages and in e-mails sent by a logged-in user; see NuSecurity::mailBaseUrl()). */
function nu_base_url(): string
{
	$cfg = trim((string)nu_cfg('nuConfigBaseURL'));
	if ($cfg !== '') return rtrim($cfg, '/');
	$https = nu_https();
	$dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
	return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}
