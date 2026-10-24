<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * First-run installer (pages/nuinstall.php): runs only while nuconfig.php is missing; afterwards it does not exist.
 *
 * Who may install: whoever can read <nuBuilder Next>/_install/setup-token.txt (written on the first visit) – proof of access to the
 * server's files, like Grafana / Jenkins. Visitors from the server itself (localhost, no proxy headers) skip the token.
 * Or: an empty file <nuBuilder Next>/install.allow (created by FTP / SFTP / file manager) proves access as well – always accepted,
 * also when the token exists (the token stays the only way when the folder is not writable for the FTP user).
 * The token file is readable for every account on the server (0644): PHP writes it as the web server's user (e.g. www-data), and
 * the FTP / SFTP user must be able to download it. The web is kept out by _install/.htaccess (Apache) as before.
 *
 * Steps: language + server check → database (test, optionally create; an existing nuBuilder database is refused since 0.8.380 –
 * see ORIGIN below) → content (empty: nuBuilder's complete system from <nuBuilder>/nubuilder4.sql, or nuBuilder Next's own install scripts) → administrator
 * (plain-text password in nuconfig.php, like nuBuilder) → options → summary → install (writes nuconfig.php, runs the database
 * steps, removes the token). Every step can be repeated; an interrupted install can simply be started again.
 */
final class NuInstall
{
	public const MAX_TOKEN_ATTEMPTS = 10;

	/*
	 * Existing nuBuilder databases (0.8.380): while nuBuilder Next is being tested it is installed only on a new, empty database.
	 * $nuConfigAllowExistingNuDB = true (nuconfig.php, written by hand – deliberately not in nuconfig-sample.php / README) allows an
	 * existing one. Setting 'db_origin' (zzzzsys_nunext_setting, step 0014): 'nunext' = set up by the installer on an empty database,
	 * 'legacy' = nuBuilder Next was already installed before 0.8.380 (keeps working, the globeadmin gets a warning), 'nubuilder' =
	 * installed by hand on an existing nuBuilder database since 0.8.380 (blocked without the variable).
	 */
	public const ORIGIN = 'db_origin';

	public static function existingAllowed(): bool
	{
		$v = nu_cfg('nuConfigAllowExistingNuDB');
		return $v === true || (is_string($v) && in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true)) || $v === 1;
	}

	/** 'nunext' | 'legacy' | 'nubuilder' | '' (not known yet). */
	private static ?string $origin = null;

	public static function origin(): string
	{
		if (self::$origin !== null) return self::$origin;
		try {
			$db = nu_db();
			return self::$origin = $db->tableExists('zzzzsys_nunext_setting') ? (string)($db->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::ORIGIN]) ?? '') : '';
		} catch (Throwable) {
			return '';
		}
	}

	public static function setOrigin(string $origin, ?NuDB $db = null): void
	{
		$db ??= nu_db();
		if (!$db->tableExists('zzzzsys_nunext_setting') || $db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::ORIGIN])) return;
		$db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::ORIGIN, 'setting_value' => $origin, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => 'install']);
		self::$origin = $origin;
	}

	/** Step 0014: the origin, once ($earlier = steps applied by an earlier request: nuBuilder Next was installed before). */
	public static function markOrigin(bool $earlier): void
	{
		if (self::origin() !== '') return;
		$db = nu_db();
		$nub = $db->tableExists('zzzzsys_form') && $db->tableExists('zzzzsys_user');
		self::setOrigin($earlier ? 'legacy' : ($nub ? 'nubuilder' : 'nunext'), $db);
	}

	/** Installed by hand on an existing nuBuilder database without $nuConfigAllowExistingNuDB: nobody may use it (index.php). */
	public static function existingBlocked(): bool
	{
		if (self::existingAllowed()) return false;
		if (self::origin() === 'nubuilder') return true;
		// not set up yet on a database with nuBuilder's tables: step 0014 would mark it 'nubuilder' – blocked before anything is
		// written, so the globeadmin's sign-in does not create the own tables first (0.8.462, gap analysis B2)
		if (self::origin() !== '' || NuSchema::present()) return false;
		try {
			return nu_db()->tableExists('zzzzsys_form') && nu_db()->tableExists('zzzzsys_user');
		} catch (Throwable) {
			return false;
		}
	}

	/** Older installation on an existing nuBuilder database: works, the globeadmin sees a warning (start page, Security check). */
	public static function existingWarn(): bool
	{
		return self::origin() === 'legacy' && !self::existingAllowed() && nu_db()->tableExists('zzzzsys_form');
	}

	public static function configPath(): string
	{
		return getenv('NU_CONFIG') ?: NU_ROOT . '/nuconfig.php';
	}

	public static function needed(): bool
	{
		return !is_file(self::configPath());
	}

	// ------------------------------------------------------------------ access

	private static function dir(): string
	{
		$d = NU_ROOT . '/_install';
		NuSecurity::protectDir($d);
		@chmod($d, 0755);
		return $d;
	}

	/** The setup token (created on the first call); null when it cannot be written. */
	public static function token(): ?string
	{
		$f = self::dir() . '/setup-token.txt';
		if (!is_file($f)) {
			$tok = implode('-', str_split(bin2hex(random_bytes(12)), 6));
			if (@file_put_contents($f, $tok . "\n") === false) return null;
		}
		// 0644: the FTP / SFTP user is not the web server's user – 0600 would make the file unreadable for him.
		if ((@fileperms($f) & 0777) !== 0644) @chmod($f, 0644);
		$t = trim((string)@file_get_contents($f));
		return $t !== '' ? $t : null;
	}

	public static function tokenFile(): string
	{
		return NU_ROOT . '/_install/setup-token.txt';
	}

	/**
	 * Request from the server itself to PHP's built-in web server (php -S, development). Since 0.8.207 every other server asks for
	 * the token: a reverse proxy on the same host that sends no X-Forwarded-For would make every visitor look local.
	 */
	public static function isLocal(): bool
	{
		if (PHP_SAPI !== 'cli-server') return false;
		$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		if (!in_array($ip, ['127.0.0.1', '::1'], true)) return false;
		foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $h) if (!empty($_SERVER[$h])) return false;
		return true;
	}

	public static function allowFileExists(): bool
	{
		return is_file(NU_ROOT . '/install.allow');
	}

	private static function attemptsFile(): string
	{
		return self::dir() . '/attempts-' . substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16) . '.json';
	}

	public static function locked(): bool
	{
		$a = json_decode((string)@file_get_contents(self::attemptsFile()), true);
		return is_array($a) && (int)$a['n'] >= self::MAX_TOKEN_ATTEMPTS && (int)$a['t'] > time() - 900;
	}

	public static function checkToken(string $entered): bool
	{
		$tok = self::token();
		$norm = static fn($s) => strtolower(preg_replace('/[^a-f0-9]/i', '', $s) ?? '');
		$ok = $tok !== null && $norm($entered) !== '' && hash_equals($norm($tok), $norm($entered));
		$f = self::attemptsFile();
		if ($ok) { @unlink($f); return true; }
		$a = json_decode((string)@file_get_contents($f), true);
		$a = is_array($a) && (int)$a['t'] > time() - 900 ? $a : ['n' => 0, 't' => time()];
		$a['n']++; $a['t'] = time();
		@file_put_contents($f, json_encode($a));
		return false;
	}

	/** Removes token, attempt counters and install.allow after a successful install. */
	public static function cleanup(): void
	{
		foreach (glob(NU_ROOT . '/_install/*') ?: [] as $f) if (is_file($f) && basename($f) !== '.htaccess' && basename($f) !== 'index.html') @unlink($f);
		@unlink(NU_ROOT . '/install.allow');
	}

	// ------------------------------------------------------------------ server check

	public static function requirements(): array
	{
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		$cfgDir = dirname(self::configPath());
		return [
			['PHP ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION . (PHP_VERSION_ID < 80200 ? ' – ' . t('secchk_php_old') : '') . (NuSecurity::phpFixNote() !== '' ? ' – ' . NuSecurity::phpFixNote() : ''), true],
			['PDO MySQL / MariaDB', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'ok' : t('ins_missing'), false],
			['PDO MSSQL', extension_loaded('pdo_sqlsrv'), extension_loaded('pdo_sqlsrv') ? 'ok' : t('ins_optional'), false],
			['mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'ok' : t('ins_missing'), true],
			['openssl', extension_loaded('openssl'), extension_loaded('openssl') ? 'ok' : t('ins_missing'), true],
			['zip', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'ok' : t('ins_zip_hint'), false],
			[t('ins_writable'), is_writable($cfgDir), is_writable($cfgDir) ? 'ok' : t('ins_writable_hint'), false],
			['HTTPS', $https, $https ? 'ok' : t('ins_https_hint'), false],
		];
	}

	public static function requirementsOk(): bool
	{
		foreach (self::requirements() as [, $ok, , $must]) if ($must && !$ok) return false;
		return extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlsrv');
	}

	// ------------------------------------------------------------------ nuBuilder next door

	/** The folder above nuBuilder Next when it holds nuBuilder (nuconfig.php + core/nuform.php). */
	public static function nuBuilderPath(?string $given = null): ?string
	{
		$p = rtrim($given !== null && $given !== '' ? $given : dirname(NU_ROOT), '/\\');
		return is_file($p . '/core/nuform.php') || is_file($p . '/nubuilder4.sql') ? $p : null;
	}

	/** Settings of nuBuilder's nuconfig.php (to suggest them). */
	public static function nuBuilderConfig(?string $path): array
	{
		if (!$path || !is_file($path . '/nuconfig.php')) return [];
		try {
			return (static function (string $__f): array { require $__f; unset($__f); return get_defined_vars(); })($path . '/nuconfig.php');
		} catch (Throwable) {
			return [];
		}
	}

	// ------------------------------------------------------------------ database

	/** @throws InvalidArgumentException|PDOException */
	public static function connect(array $db, bool $withName = true): NuDB
	{
		$type = $db['type'] === 'sqlsrv' ? 'sqlsrv' : 'mysql';
		if (!preg_match('/^[A-Za-z0-9.\-_:\\\\\[\]]+$/', (string)$db['host'])) throw new InvalidArgumentException(t('ins_err_host'));
		if ((string)$db['port'] !== '' && !ctype_digit((string)$db['port'])) throw new InvalidArgumentException(t('ins_err_port'));
		if (!preg_match('/^[A-Za-z0-9_\-$]{1,64}$/', (string)$db['name'])) throw new InvalidArgumentException(t('ins_err_name'));
		return new NuDB(['type' => $type, 'host' => $db['host'], 'port' => $db['port'], 'name' => $withName ? $db['name'] : '',
			'user' => $db['user'], 'password' => $db['password'], 'charset' => $type === 'mysql' ? 'utf8mb4' : '', 'collate' => '',
			'options' => $type === 'mysql' ? [PDO::ATTR_TIMEOUT => 8] : [], 'trust_cert' => !empty($db['trust'])]);
	}

	public static function createDatabase(array $db): void
	{
		if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', (string)$db['name'])) throw new RuntimeException(t('ins_err_db_name'));
		$srv = self::connect($db, false);
		if ($srv->type === 'mysql') $srv->exec('CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
		else $srv->exec("IF DB_ID('" . $db['name'] . "') IS NULL CREATE DATABASE [" . $db['name'] . ']');
	}

	/** 'nubuilder' (nuBuilder tables there) · 'empty' · 'other' (tables, but not nuBuilder's) */
	public static function detect(NuDB $db): array
	{
		$tables = $db->type === 'mysql'
			? array_column($db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'), 't')
			: array_column($db->all("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'"), 't');
		$tables = array_map('strval', $tables);
		$nu = array_values(array_filter($tables, static fn($t) => str_starts_with($t, 'zzzzsys_')));
		$kind = !$tables ? 'empty' : (count(array_intersect(['zzzzsys_user', 'zzzzsys_access', 'zzzzsys_form'], $tables)) === 3 ? 'nubuilder' : 'other');
		$users = $kind === 'nubuilder' ? (int)$db->value('SELECT COUNT(*) FROM zzzzsys_user') : 0;
		$forms = $kind === 'nubuilder' ? (int)$db->value('SELECT COUNT(*) FROM zzzzsys_form') : 0;
		return ['kind' => $kind, 'tables' => count($tables), 'nu' => count($nu), 'users' => $users, 'forms' => $forms];
	}

	/** SQL files for an empty database, by choice: 'nubuilder' (complete nuBuilder system) or 'nunext' (nuBuilder Next's scripts). */
	public static function schemaFiles(string $choice, string $type, ?string $nubPath): array
	{
		if ($choice === 'nubuilder' && $type === 'mysql' && $nubPath && is_file($nubPath . '/nubuilder4.sql')) return [$nubPath . '/nubuilder4.sql'];
		return $type === 'sqlsrv'
			? [NU_ROOT . '/install/schema_mssql.sql']
			: [NU_ROOT . '/install/schema_mysql.sql', NU_ROOT . '/install/schema_mysql_forms.sql', NU_ROOT . '/install/notes_snippets_mysql.sql'];
	}

	/** Runs an SQL file statement by statement (all tables as InnoDB). @return int statements */
	public static function importFile(NuDB $db, string $file): int
	{
		nu_time_limit(600);
		$n = 0;
		foreach (self::splitSql((string)file_get_contents($file)) as $stmt) {
			if ($db->type === 'mysql') $stmt = (string)preg_replace('/\bENGINE\s*=\s*MyISAM\b/i', 'ENGINE=InnoDB', $stmt);
			try {
				$db->exec($stmt);
			} catch (PDOException $e) {
				throw new RuntimeException(basename($file) . ', ' . t('ins_err_statement', $n + 1) . ': ' . $e->getMessage() . ' — ' . mb_substr(trim($stmt), 0, 160), 0, $e);
			}
			$n++;
		}
		return $n;
	}

	/** Splits SQL on ";" outside strings, quoted names and comments (MySQL's /*! … *\/ stays code). */
	public static function splitSql(string $sql): array
	{
		$out = [];
		$buf = '';
		$len = strlen($sql);
		for ($i = 0; $i < $len; $i++) {
			$c = $sql[$i];
			$n = $i + 1 < $len ? $sql[$i + 1] : '';
			if ($c === "'" || $c === '"' || $c === '`') {                 // string / quoted name
				$q = $c; $buf .= $c;
				for ($i++; $i < $len; $i++) {
					$buf .= $sql[$i];
					if ($sql[$i] === '\\' && $q !== '`' && $i + 1 < $len) { $buf .= $sql[++$i]; continue; }
					if ($sql[$i] === $q) {
						if ($i + 1 < $len && $sql[$i + 1] === $q) { $buf .= $sql[++$i]; continue; }   // doubled quote
						break;
					}
				}
				continue;
			}
			if ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) { $e = strpos($sql, "\n", $i); $i = $e === false ? $len : $e; $buf .= "\n"; continue; }
			if ($c === '#') { $e = strpos($sql, "\n", $i); $i = $e === false ? $len : $e; $buf .= "\n"; continue; }
			if ($c === '/' && $n === '*' && ($i + 2 >= $len || $sql[$i + 2] !== '!')) { $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $len : $e + 1; $buf .= ' '; continue; }
			if ($c === ';') { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; continue; }
			$buf .= $c;
		}
		if (trim($buf) !== '') $out[] = trim($buf);
		return $out;
	}

	// ------------------------------------------------------------------ nubuilder4.sql of any 4.x version (0.8.384)

	/** Largest dump the installer reads (unpacked). nuBuilder 4.9's nubuilder4.sql has about 1 MB. */
	public const DUMP_MAX = 64 * 1024 * 1024;
	/** Tables a nubuilder4.sql must create. */
	public const DUMP_CORE = ['zzzzsys_access', 'zzzzsys_form', 'zzzzsys_object', 'zzzzsys_php', 'zzzzsys_user'];

	/** nubuilder4.sql files copied into _install/ by FTP / SFTP or uploaded in the wizard: [file name => path]. */
	public static function dumpCandidates(): array
	{
		$out = [];
		foreach (glob(self::dir() . '/*') ?: [] as $f) {
			$b = basename($f);
			// files of the wizard itself (an upload, the checked copy) are not offered again
			if (is_file($f) && preg_match('/\.(sql|sql\.gz|zip)$/i', $b) && !str_ends_with(strtolower($b), '.checked.sql') && !str_starts_with($b, 'upload-')) $out[$b] = $f;
		}
		ksort($out);
		return $out;
	}

	/** Saves an uploaded file ($_FILES entry) into _install/. @return array{0: ?string, 1: string} path or null, error text */
	public static function storeUpload(array $f): array
	{
		$err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return [null, t('ins_dump_err_size', (string)nu_ini_get('upload_max_filesize'))];
		if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) return [null, t('ins_dump_err_upload')];
		if (!preg_match('/\.(sql|sql\.gz|zip)$/i', (string)($f['name'] ?? ''), $m)) return [null, t('ins_dump_err_type')];
		$dest = self::dir() . '/upload-' . bin2hex(random_bytes(4)) . '.' . strtolower($m[1]);
		if (!@move_uploaded_file((string)$f['tmp_name'], $dest)) return [null, t('ins_dump_err_upload')];
		@chmod($dest, 0640);
		return [$dest, ''];
	}

	/** The SQL text of a .sql, .sql.gz or .zip (its nubuilder4.sql, otherwise its only .sql file); null when it cannot be read. */
	public static function dumpSql(string $path): ?string
	{
		$name = strtolower($path);
		if (str_ends_with($name, '.sql.gz')) {
			if (!function_exists('gzdecode')) return null;
			$raw = @file_get_contents($path, false, null, 0, self::DUMP_MAX);
			$sql = is_string($raw) ? @gzdecode($raw, self::DUMP_MAX) : false;
			return is_string($sql) ? $sql : null;
		}
		if (str_ends_with($name, '.zip')) {
			if (!class_exists('ZipArchive')) return null;
			$z = new ZipArchive();
			if ($z->open($path) !== true) return null;
			$pick = null; $sqls = [];
			for ($i = 0; $i < $z->numFiles; $i++) {
				$n = (string)$z->getNameIndex($i);
				if (!preg_match('/\.sql$/i', $n)) continue;
				$sqls[] = $i;
				if (strtolower(basename($n)) === 'nubuilder4.sql') $pick = $i;
			}
			if ($pick === null && count($sqls) === 1) $pick = $sqls[0];
			$st = $pick !== null ? $z->statIndex($pick) : false;
			$sql = $st && (int)$st['size'] <= self::DUMP_MAX ? $z->getFromIndex((int)$pick) : false;
			$z->close();
			return is_string($sql) ? $sql : null;
		}
		$sz = @filesize($path);
		if ($sz === false || $sz > self::DUMP_MAX) return null;
		$sql = @file_get_contents($path);
		return is_string($sql) ? $sql : null;
	}

	/**
	 * Checks a nubuilder4.sql before it runs: only nuBuilder's own tables (zzzzsys_…) are created, filled, changed or dropped, besides
	 * SET, transactions, LOCK TABLES and nuBuilder 4.5's views. Refused: other tables, other databases (USE, CREATE / DROP DATABASE),
	 * users and rights, files (INTO OUTFILE, LOAD DATA), triggers, routines, events and an explicit DEFINER.
	 * @return array{ok: bool, error: string, tables: int, forms: int}
	 */
	public static function checkDump(string $sql): array
	{
		$res = ['ok' => false, 'error' => '', 'tables' => 0, 'forms' => 0];
		$created = [];
		$tbl = '`?(zzzzsys_\w+)`?';
		$insert = '/^(INSERT|REPLACE)\s+INTO\s+' . $tbl . '\s*(\([^)]*\)\s*)?VALUES\b/i';
		foreach (self::splitSql($sql) as $stmt) {
			$s = trim((string)preg_replace('#^/\*!\d*\s*(.*?)\s*\*/$#s', '$1', trim($stmt)));
			if ($s === '') continue;
			$bad = static fn() => ['ok' => false, 'error' => t('ins_dump_err_stmt', mb_substr((string)preg_replace('/\s+/', ' ', $s), 0, 80)), 'tables' => 0, 'forms' => 0];
			if (preg_match($insert, $s, $m)) {
				// VALUES with literals only (strings, numbers, NULL, hex) – no sub-queries or functions such as LOAD_FILE()
				$vals = (string)preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", substr($s, strlen($m[0])));
				$vals = (string)preg_replace('/\b(NULL|TRUE|FALSE|0x[0-9a-f]+|_(binary|utf8mb4|utf8mb3|utf8|latin1))\b|-?\d+(\.\d+)?(e[+-]?\d+)?|\'\'/i', '', $vals);
				if (!preg_match('/^[\s(),]*$/', $vals)) return $bad();
				if (strtolower($m[2]) === 'zzzzsys_form') $res['forms'] += preg_match_all('/\)\s*,\s*\(/', $s) + 1;
				continue;
			}
			if (preg_match('/\b(INTO\s+(OUT|DUMP)FILE|LOAD_FILE\s*\(|DEFINER\s*=)/i', $s)) return $bad();
			if (preg_match('/^(START\s+TRANSACTION|BEGIN|COMMIT|UNLOCK\s+TABLES)$/i', $s)) continue;
			if (preg_match('/^SET\s/i', $s) && !preg_match('/^SET\s+(GLOBAL|PASSWORD|ROLE|DEFAULT\s+ROLE|@@GLOBAL)\b/i', $s)) continue;
			if (preg_match('/^LOCK\s+TABLES\s+' . $tbl . '\s+WRITE$/i', $s)) continue;
			if (preg_match('/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?' . $tbl . '\s*\(/i', $s, $m)) { $created[strtolower($m[2])] = 1; continue; }
			if (preg_match('/^(ALTER\s+TABLE|DROP\s+TABLE(\s+IF\s+EXISTS)?|DROP\s+VIEW(\s+IF\s+EXISTS)?)\s+' . $tbl . '(\s|$)/i', $s)) continue;
			// nuBuilder 4.5: zzzzsys_object_list / zzzzsys_report_data / zzzzsys_run_list as views (upgradeTo49 replaces them as 4.9 does)
			if (preg_match('/^CREATE\s+(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(SQL\s+SECURITY\s+(DEFINER|INVOKER)\s+)?VIEW\s+' . $tbl . '\s+AS\s+SELECT\b/i', $s)) continue;
			return $bad();
		}
		$missing = array_diff(self::DUMP_CORE, array_keys($created));
		if ($missing) { $res['error'] = t('ins_dump_err_core', implode(', ', $missing)); return $res; }
		$res['ok'] = true;
		$res['tables'] = count($created);
		return $res;
	}

	/** Column definitions of nuBuilder 4.9 (install/nubuilder49_tables.sql): [table => [column => definition]], in table order. */
	public static function tables49(): array
	{
		$out = [];
		foreach (self::splitSql((string)@file_get_contents(NU_ROOT . '/install/nubuilder49_tables.sql')) as $s) {
			if (!preg_match('/^CREATE TABLE IF NOT EXISTS `(\w+)`\s*\((.*)\)\s*ENGINE/is', $s, $m)) continue;
			foreach (preg_split('/,\s*\n\s*/', trim($m[2])) ?: [] as $line) {
				if (preg_match('/^`(\w+)`\s+(.+)$/s', trim($line), $c)) $out[$m[1]][$c[1]] = trim($c[2]);
			}
		}
		return $out;
	}

	/**
	 * Brings a database made from the nubuilder4.sql of an older 4.x version to the structure of nuBuilder 4.9 (MySQL) – what
	 * nuBuilder's own update does with nuAlterSystemTables(), nuCreateJSONColumns() and nuCopySystemTables():
	 *  1. nuBuilder 4.5's views zzzzsys_object_list / zzzzsys_run_list are dropped, zzzzsys_report_data becomes 4.9's table;
	 *  2. missing tables are created from install/nubuilder49_tables.sql;
	 *  3. nuBuilder 4.9's ALTER list (ALTER_49) adapted to 4.9's own nubuilder4.sql, the zzzzsys_setup defaults and nuBuilder's renames
	 *     of system procedure codes (UPDATE_49);
	 *  4. every column of 4.9 that is still missing (the *_json columns …) with 4.9's definition.
	 * A 4.9 dump comes out unchanged. nuBuilder's own system records (its forms) stay those of the dump – nuBuilder updates them
	 * with its own update. @return array{tables: int, columns: int, changed: int}
	 */
	public static function upgradeTo49(NuDB $db): array
	{
		$sum = ['tables' => 0, 'columns' => 0, 'changed' => 0];
		if ($db->type !== 'mysql') return $sum;
		$read = static function () use ($db): array {
			$out = [];
			foreach ($db->all('SELECT TABLE_NAME AS t, TABLE_TYPE AS k FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $r) $out[(string)$r['t']] = ['_kind' => (string)$r['k']];
			foreach ($db->all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $r) {
				if (isset($out[(string)$r['t']])) $out[(string)$r['t']][strtolower((string)$r['c'])] = true;
			}
			return $out;
		};
		$have = $read();
		foreach (['zzzzsys_object_list', 'zzzzsys_run_list', 'zzzzsys_report_data'] as $v) {
			if (!isset($have[$v])) continue;
			if ($have[$v]['_kind'] === 'VIEW') $db->exec('DROP VIEW `' . $v . '`');
			elseif ($v !== 'zzzzsys_report_data' || !isset($have[$v]['zzzzsys_report_data_id'])) $db->exec('DROP TABLE `' . $v . '`');
		}
		$count = static fn(array $h) => count(array_filter(array_keys($h), static fn($t) => str_starts_with($t, 'zzzzsys_') && !str_starts_with($t, 'zzzzsys_nunext_')));
		$before = $count($read());
		foreach (self::splitSql((string)file_get_contents(NU_ROOT . '/install/nubuilder49_tables.sql')) as $s) $db->exec($s);
		$have = $read();
		$sum['tables'] = $count($have) - $before;
		$add = static function (string $t, string $c, string $def) use ($db, &$have, &$sum): void {
			if (!isset($have[$t]) || isset($have[$t][strtolower($c)])) return;
			if (preg_match('/\sAFTER\s+`?(\w+)`?$/i', $def, $m) && !isset($have[$t][strtolower($m[1])])) $def = (string)preg_replace('/\sAFTER\s+`?\w+`?$/i', '', $def);
			$db->exec("ALTER TABLE `$t` ADD `$c` $def");
			$have[$t][strtolower($c)] = true;
			$sum['columns']++;
		};
		foreach (self::ALTER_49 as [$op, $t, $c, $def]) {
			if ($op === 'add') { $add($t, $c, $def); continue; }
			if (!isset($have[$t][strtolower($c)])) continue;
			if ($op === 'change') {
				// only when the type differs from 4.9's (a 4.9 dump stays as it is)
				$want = strtolower((string)preg_replace('/\s.*$/s', '', trim((string)preg_replace('/^`?\w+`?\s+/', '', $def))));
				$is = strtolower((string)$db->value('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]));
				$newName = (string)preg_replace('/^`?(\w+)`?\s.*$/s', '$1', $def);
				if ($newName === $c && preg_replace('/\(\d+\)$/', '', $is) === preg_replace('/\(\d+\)$/', '', $want) && (str_contains($want, '(') ? $is === $want : true)) continue;
				$db->exec("ALTER TABLE `$t` CHANGE `$c` $def");
				if ($newName !== $c) { unset($have[$t][strtolower($c)]); $have[$t][strtolower($newName)] = true; }
			} else {
				$db->exec("ALTER TABLE `$t` DROP `$c`");
				unset($have[$t][strtolower($c)]);
			}
			$sum['changed']++;
		}
		// zzzzsys_setup: nuBuilder fills the three columns it adds
		foreach (self::SETUP_49 as $c => [$def, $value]) {
			if (!isset($have['zzzzsys_setup']) || isset($have['zzzzsys_setup'][$c])) continue;
			$add('zzzzsys_setup', $c, $def);
			$db->run("UPDATE zzzzsys_setup SET `$c` = ?", [$value]);
		}
		foreach (self::UPDATE_49 as [$t, $sql]) if (isset($have[$t])) $db->exec($sql);
		foreach (self::tables49() as $t => $defs) {
			$prev = null;
			foreach ($defs as $c => $def) {
				$add($t, $c, $def . ($prev !== null ? ' AFTER `' . $prev . '`' : ''));
				$prev = $c;
			}
		}
		return $sum;
	}

	/**
	 * nuBuilder 4.9's nuAlterSystemTables() (core/nusystemupdatelibs.php), adapted to 4.9's own nubuilder4.sql: sal_group VARCHAR(80)
	 * (the update: 100), set_include TEXT (the update: LONGTEXT), zzzzsys_email_log keeps DATETIME (the update changes it to TIMESTAMP),
	 * the DROP of zzzzsys_prompt_generator.sph_template and the pdf_temp columns only where they exist. [op, table, column, definition]
	 */
	public const ALTER_49 = [
		['add', 'zzzzsys_debug', 'deb_flag', 'VARCHAR(50) NULL DEFAULT NULL AFTER deb_message'],
		['add', 'zzzzsys_debug', 'deb_user_id', 'VARCHAR(25) NULL DEFAULT NULL AFTER deb_added'],
		['change', 'zzzzsys_object', 'sob_input_count', 'sob_input_count VARCHAR(15) NULL DEFAULT NULL'],
		['change', 'zzzzsys_object', 'sob_all_order', "sob_all_order INT(11) NULL DEFAULT '0'"],
		['add', 'zzzzsys_object', 'sob_select_2', "VARCHAR(1) NULL DEFAULT '0' AFTER sob_select_multiple"],
		['add', 'zzzzsys_object', 'sob_input_datalist', 'TEXT NULL DEFAULT NULL AFTER sob_input_javascript'],
		['add', 'zzzzsys_object', 'sob_input_attribute', 'VARCHAR(1000) NULL DEFAULT NULL AFTER sob_input_datalist'],
		['add', 'zzzzsys_object', 'sob_input_icon', 'VARCHAR(50) NULL DEFAULT NULL AFTER sob_input_type'],
		['add', 'zzzzsys_object', 'sob_input_file_target', "VARCHAR(1) NULL DEFAULT '0' AFTER sob_input_attribute"],
		['add', 'zzzzsys_object', 'sob_run_target', 'VARCHAR(1) NULL DEFAULT NULL AFTER sob_run_method'],
		['add', 'zzzzsys_object', 'sob_run_type', 'VARCHAR(1) NULL DEFAULT NULL AFTER sob_run_target'],
		['add', 'zzzzsys_object', 'sob_display_procedure', 'VARCHAR(25) NULL DEFAULT NULL AFTER sob_display_sql'],
		['add', 'zzzzsys_object', 'sob_all_event', 'VARCHAR(1) NULL DEFAULT NULL AFTER sob_all_access'],
		['add', 'zzzzsys_object', 'sob_all_style_type', 'VARCHAR(15) NULL DEFAULT NULL AFTER sob_all_event'],
		['add', 'zzzzsys_object', 'sob_all_style', 'VARCHAR(1000) NULL DEFAULT NULL AFTER sob_all_style_type'],
		['add', 'zzzzsys_object', 'sob_all_json', 'MEDIUMTEXT NULL DEFAULT NULL AFTER sob_image_zzzzsys_file_id'],
		['add', 'zzzzsys_object', 'sob_all_access_condition', 'VARCHAR(1000) NULL DEFAULT NULL AFTER sob_all_access'],
		['add', 'zzzzsys_object', 'sob_select_procedure', 'VARCHAR(25) NULL DEFAULT NULL AFTER sob_select_sql'],
		['add', 'zzzzsys_form', 'sfo_browse_title_multiline', "VARCHAR(1) NULL DEFAULT '0' AFTER sfo_browse_rows_per_page"],
		['add', 'zzzzsys_form', 'sfo_browse_autoresize_columns', 'VARCHAR(1) NULL DEFAULT NULL AFTER sfo_browse_title_multiline'],
		['add', 'zzzzsys_form', 'sfo_breadcrumb_title', 'VARCHAR(100) NULL DEFAULT NULL AFTER sfo_description'],
		['add', 'zzzzsys_form', 'sfo_browse_javascript', 'MEDIUMTEXT NULL DEFAULT NULL AFTER sfo_javascript'],
		['add', 'zzzzsys_form', 'sfo_edit_javascript', 'MEDIUMTEXT NULL DEFAULT NULL AFTER sfo_browse_javascript'],
		['add', 'zzzzsys_form', 'sfo_style', 'MEDIUMTEXT NULL DEFAULT NULL AFTER sfo_edit_javascript'],
		['add', 'zzzzsys_form', 'sfo_mobile_view', 'VARCHAR(1) NULL DEFAULT NULL AFTER sfo_style'],
		['add', 'zzzzsys_form', 'sfo_group', 'VARCHAR(100) NULL DEFAULT NULL AFTER sfo_description'],
		['add', 'zzzzsys_form', 'sfo_status', 'VARCHAR(1) NULL DEFAULT NULL AFTER sfo_group'],
		['add', 'zzzzsys_form', 'sfo_browse_target', 'VARCHAR(1) NULL DEFAULT NULL AFTER sfo_browse_redirect_form_id'],
		['add', 'zzzzsys_session', 'sss_hashcookies', 'MEDIUMTEXT NULL DEFAULT NULL AFTER sss_access'],
		['add', 'zzzzsys_session', 'sss_login_time', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER sss_time'],
		['add', 'zzzzsys_tab', 'syt_access', 'VARCHAR(1) NULL DEFAULT NULL AFTER syt_help'],
		['add', 'zzzzsys_user', 'sus_code', 'VARCHAR(50) DEFAULT NULL AFTER sus_name'],
		['add', 'zzzzsys_user', 'sus_position', 'VARCHAR(50) DEFAULT NULL AFTER sus_code'],
		['add', 'zzzzsys_user', 'sus_department', 'VARCHAR(50) DEFAULT NULL AFTER sus_position'],
		['add', 'zzzzsys_user', 'sus_team', 'VARCHAR(50) DEFAULT NULL AFTER sus_department'],
		['add', 'zzzzsys_user', 'sus_additional1', 'VARCHAR(100) DEFAULT NULL AFTER sus_email'],
		['add', 'zzzzsys_user', 'sus_additional2', 'VARCHAR(100) DEFAULT NULL AFTER sus_additional1'],
		['add', 'zzzzsys_user', 'sus_expires_on', 'DATETIME DEFAULT NULL AFTER sus_login_password'],
		['add', 'zzzzsys_user', 'sus_json', 'MEDIUMTEXT NULL DEFAULT NULL AFTER sus_expires_on'],
		['add', 'zzzzsys_user', 'sus_accessibility_features', 'VARCHAR(1) NULL DEFAULT NULL AFTER sus_expires_on'],
		['add', 'zzzzsys_user', 'sus_change_password', 'VARCHAR(1) NULL DEFAULT NULL AFTER sus_expires_on'],
		['add', 'zzzzsys_user', 'sus_permission', 'VARCHAR(1000) NULL DEFAULT NULL AFTER sus_additional2'],
		['add', 'zzzzsys_user', 'sus_last_name', 'VARCHAR(30) NULL DEFAULT NULL AFTER sus_name'],
		['add', 'zzzzsys_user', 'sus_first_name', 'VARCHAR(30) NULL DEFAULT NULL AFTER sus_name'],
		['add', 'zzzzsys_access', 'sal_use_2fa', 'VARCHAR(1) NULL DEFAULT NULL AFTER sal_zzzzsys_form_id'],
		['add', 'zzzzsys_access', 'sal_group', 'VARCHAR(80) NULL DEFAULT NULL AFTER sal_description'],
		['add', 'zzzzsys_access_form', 'slf_data_mode', 'VARCHAR(2) DEFAULT NULL AFTER slf_print_button'],
		['add', 'zzzzsys_access_form', 'slf_form_type', 'VARCHAR(2) DEFAULT NULL AFTER slf_data_mode'],
		['add', 'zzzzsys_php', 'sph_global', "VARCHAR(1) NULL DEFAULT '0' AFTER sph_system"],
		['add', 'zzzzsys_php', 'sph_template', "VARCHAR(1) NULL DEFAULT '0' AFTER sph_global"],
		['add', 'zzzzsys_php', 'sph_demo', "VARCHAR(1) NULL DEFAULT '1' AFTER sph_global"],
		['add', 'zzzzsys_php', 'sph_status', 'VARCHAR(1) NULL DEFAULT NULL AFTER sph_group'],
		['add', 'zzzzsys_php', 'sph_category', 'VARCHAR(100) NULL DEFAULT NULL AFTER sph_group'],
		['add', 'zzzzsys_php', 'sph_javascript', 'TEXT NULL DEFAULT NULL AFTER sph_php'],
		['add', 'zzzzsys_php', 'sph_javascript_active', 'VARCHAR(1) NULL DEFAULT NULL AFTER sph_javascript'],
		['add', 'zzzzsys_setup', 'set_smtp_use_ssl', "VARCHAR(1) NULL DEFAULT '1' AFTER set_smtp_use_authentication"],
		['add', 'zzzzsys_format', 'srm_default', 'VARCHAR(1) NULL DEFAULT NULL AFTER srm_type'],
		['change', 'zzzzsys_report', 'sre_zzzzsys_php_id', 'sre_zzzzsys_php_id VARCHAR(200) NULL DEFAULT NULL'],
		['add', 'zzzzsys_select', 'sse_code', 'VARCHAR(50) NULL DEFAULT NULL AFTER zzzzsys_select_id'],
		['change', 'zzzzsys_code_snippet', 'cot_updated_on', 'cot_updated_on TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
		['add', 'zzzzsys_code_snippet', 'cot_group', 'VARCHAR(80) NULL DEFAULT NULL AFTER cot_description'],
		['add', 'zzzzsys_code_snippet', 'cot_template', 'VARCHAR(1) NULL DEFAULT NULL AFTER cot_scope'],
		['add', 'zzzzsys_cloner', 'clo_tables_include', 'MEDIUMTEXT NULL DEFAULT NULL AFTER clo_iframe_forms'],
		['add', 'zzzzsys_cloner', 'clo_tables_exclude', 'MEDIUMTEXT NULL DEFAULT NULL AFTER clo_tables_include'],
		['change', 'zzzzsys_app_cloner', 'cla_create_database', 'cla_target_db_mode VARCHAR(10)'],
		['add', 'zzzzsys_app_cloner', 'cla_copy_files_mode', 'VARCHAR(10) NULL DEFAULT NULL AFTER cla_copy_files'],
		['add', 'zzzzsys_email_template', 'emt_template', "VARCHAR(1) NULL DEFAULT '0' AFTER emt_group"],
		['add', 'zzzzsys_email_template', 'emt_category', 'VARCHAR(100) NULL DEFAULT NULL AFTER emt_group'],
		['add', 'pdf_temp', 'pdf_code', 'VARCHAR(100) NULL DEFAULT NULL AFTER pdf_added_by'],
		['add', 'pdf_temp', 'pdf_tag', 'VARCHAR(100) NULL DEFAULT NULL AFTER pdf_code'],
		['add', 'zzzzsys_config', 'cfg_title', 'VARCHAR(50) NULL DEFAULT NULL AFTER cfg_category'],
		['add', 'zzzzsys_prompt_generator', 'pge_tag', 'VARCHAR(3000) NULL DEFAULT NULL AFTER pge_prompt'],
		['add', 'zzzzsys_prompt_generator', 'pge_template', 'VARCHAR(1) NULL DEFAULT NULL AFTER pge_tag'],
		['drop', 'zzzzsys_prompt_generator', 'sph_template', ''],
		['add', 'zzzzsys_prompt_generator', 'pge_instruction', 'VARCHAR(3000) NULL DEFAULT NULL AFTER pge_template'],
		['add', 'zzzzsys_item', 'itm_group', 'VARCHAR(100) NULL DEFAULT NULL AFTER itm_description'],
		['add', 'zzzzsys_note', 'not_text', 'LONGTEXT NULL DEFAULT NULL AFTER not_content'],
	];

	/** zzzzsys_setup columns nuBuilder adds and fills (nuAlterSystemTables). [column => [definition, value]] */
	public const SETUP_49 = [
		'set_languages_included' => ['VARCHAR(1000) NULL DEFAULT NULL AFTER set_language', '["Afrikaans","Arabic","Armenian","Catalan","Chinese","Czech","Danish","Dutch","French","German","Greek","Hindi","Hungarian","Italian","Japanese","Malay","Norwegian","Polish","Portuguese (Brazil)","Portuguese","Romanian","Russian","Slovak","Spanish","Tamil","Turkish","Vietnamese"]'],
		'set_style' => ['LONGTEXT NULL DEFAULT NULL AFTER set_header', "/* Define your own styles, override styles from nubuilder4.css */\r\n\r\n/*\r\n .nuActionButton {\r\n background-color: #579cb7\r\n}\r\n\r\n*/"],
		'set_include' => ['TEXT NULL DEFAULT NULL AFTER set_header', "// Add external CSS/JS file here\r\n\r\n/*\r\n<link rel=\"stylesheet\" href=\"path_to_css.css\"/>\r\n\r\n<script src=\"path_to_js.js\"></script>\r\n*/"],
	];

	/** nuBuilder 4.9's renames of system procedure codes and html → chart objects (nuAlterSystemTables). [table, SQL] */
	public const UPDATE_49 = [
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = REPLACE(sph_code, 'NUAUTHENTICATION2FA', 'nu_authentication_2fa') WHERE COALESCE(sph_code, '') LIKE '%NUAUTHENTICATION2FA%'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = REPLACE(sph_code, 'NUCHECKPASSWORDPOLICY', 'nu_check_password_policy') WHERE COALESCE(sph_code, '') LIKE '%NUCHECKPASSWORDPOLICY%'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = REPLACE(sph_code, 'NUDEBUGRESULTADDED', 'nu_debug_result_added') WHERE COALESCE(sph_code, '') LIKE '%NUDEBUGRESULTADDED%'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = REPLACE(sph_code, 'NUSENDWELCOMEEMAIL', 'nu_send_welcome_email') WHERE COALESCE(sph_code, '') LIKE '%NUSENDWELCOMEEMAIL%'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = REPLACE(sph_code, 'NUUPLOADFILE', 'nu_upload_file') WHERE COALESCE(sph_code, '') LIKE '%NUUPLOADFILE%'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_before_save' WHERE sph_code = 'nuBeforeSave'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_after_save' WHERE sph_code = 'nuAfterSave'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_before_edit' WHERE sph_code = 'nuBeforeEdit'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_before_browse' WHERE sph_code = 'nuBeforeBrowse'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_before_delete' WHERE sph_code = 'nuBeforeDelete'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_after_delete' WHERE sph_code = 'nuAfterDelete'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_startup' WHERE sph_code = 'nuStartup'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_check_password_policy' WHERE sph_code = 'nuCheckPasswordPolicy'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_invalid_login' WHERE sph_code = 'nuInvalidLogin'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_after_update' WHERE sph_code = 'nuAfterUpdate'"],
		['zzzzsys_php', "UPDATE zzzzsys_php SET sph_code = 'nu_send_welcome_email' WHERE sph_code = 'nuSendWelcomeEmail'"],
		['zzzzsys_object', "UPDATE zzzzsys_object SET sob_html_code = REPLACE(sob_html_code, 'procedure: ''NUUPLOADFILE_TEMPLATE''', 'procedure: ''nu_upload_file_template''') WHERE COALESCE(sob_html_code, '') LIKE '%NUUPLOADFILE_TEMPLATE%'"],
		['zzzzsys_object', "UPDATE zzzzsys_object SET sob_html_code = REPLACE(sob_html_code, 'procedure: ''NUUPLOADFILE''', 'procedure: ''nu_upload_file''') WHERE COALESCE(sob_html_code, '') LIKE '%NUUPLOADFILE%'"],
		['zzzzsys_object', "UPDATE zzzzsys_object SET sob_all_type = 'chart' WHERE sob_all_type = 'html' AND COALESCE(sob_html_chart_type, '') <> ''"],
	];

	// ------------------------------------------------------------------ nuconfig.php

	/** nuconfig.php from nuconfig-sample.php with the chosen values (same variables as nuBuilder). */
	public static function buildConfig(array $s): string
	{
		$tpl = (string)file_get_contents(NU_ROOT . '/nuconfig-sample.php');
		$x = static fn($v) => var_export($v, true);
		$db = $s['db'];
		$dbBlock = '$nuConfigDBType = ' . $x($db['type']) . ";\t\t\t\t\t\t\t\t//-- mysql (MySQL, MariaDB) or sqlsrv (MSSQL)\n\n"
			. '$nuConfigDBHost						= ' . $x((string)$db['host']) . ";\n"
			. '$nuConfigDBPort						= ' . ((string)$db['port'] === '' ? '""' : (int)$db['port']) . ";\n"
			. '$nuConfigDBName						= ' . $x((string)$db['name']) . ";\n"
			. '$nuConfigDBUser						= ' . $x((string)$db['user']) . ";\n"
			. '$nuConfigDBPassword					= ' . $x((string)$db['password']) . ";\n"
			. ($db['type'] === 'sqlsrv' ? '$nuConfigDBTrustServerCertificate	= ' . (!empty($db['trust']) ? 'true' : 'false') . ";\n" : '');
		$start = strpos($tpl, '$nuConfigDBType');
		$end = strpos($tpl, "}\n", (int)strpos($tpl, '} else {')) + 2;
		if ($start === false || $end <= $start) throw new RuntimeException('nuconfig-sample.php: database block not found');
		$tpl = substr($tpl, 0, $start) . $dbBlock . substr($tpl, $end);
		$set = static function (string $text, string $name, string $literal) {
			$re = '/^([ \t]*)(?:\/\/)?(\$' . preg_quote($name, '/') . '\s*=\s*).*?;/m';
			if (preg_match($re, $text)) return (string)preg_replace($re, '${1}${2}' . str_replace(['\\', '$'], ['\\\\', '\\$'], $literal) . ';', $text, 1);
			return rtrim($text) . "\n\$" . $name . ' = ' . $literal . ";\n";
		};
		$a = $s['admin'];
		$o = $s['opt'];
		$tpl = $set($tpl, 'nuConfigDBGlobeadminUsername', $x((string)$a['user']));
		$tpl = $set($tpl, 'nuConfigDBGlobeadminPassword', $x((string)$a['password']));
		$tpl = $set($tpl, 'nuConfigGlobeadminEmail', $x((string)$a['email']));
		$tpl = $set($tpl, 'nuConfig2FAAdmin', !empty($a['twofa']) ? 'true' : 'false');
		$tpl = $set($tpl, 'nuConfigSecretKey', $x(bin2hex(random_bytes(32))));
		$tpl = $set($tpl, 'nuConfigSessionTimeout', (string)max(5, (int)$o['timeout']));
		$tpl = $set($tpl, 'nuConfigTimezone', $x((string)$o['timezone']));
		$tpl = $set($tpl, 'nuConfigBaseURL', $x((string)$o['base_url']));
		$tpl = $set($tpl, 'nuConfigSharedLogin', !empty($o['shared']) ? 'true' : 'false');
		if ((string)$o['nub_path'] !== '' && rtrim((string)$o['nub_path'], '/\\') !== dirname(NU_ROOT)) $tpl = $set($tpl, 'nuConfigNuBuilderPath', $x((string)$o['nub_path']));
		$tpl = (string)preg_replace('/^\/\/ -> Rename nuconfig-sample\.php to nuconfig\.php\n/m', '// -> Written by the nuBuilder Next installer on ' . date('Y-m-d H:i') . ". Edit as needed.\n", $tpl, 1);
		return $tpl;
	}

	/** Writes nuconfig.php (temporary file → rename → read back). @return bool written */
	public static function writeConfig(string $content): bool
	{
		$path = self::configPath();
		if (!is_writable(dirname($path))) return false;
		$tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
		if (@file_put_contents($tmp, $content) === false) return false;
		// 0644: PHP writes the file as the web server's user (e.g. www-data); the FTP / SFTP user must be able to read / download it
		// (0640 before 0.8.206). Changes by FTP: download, edit, upload – or Admin tools → nuconfig.php.
		@chmod($tmp, 0644);
		if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
		return self::validConfig($path);
	}

	/** The file exists and defines a database name (a syntax check without running PHP code twice). */
	public static function validConfig(string $path): bool
	{
		if (!is_file($path)) return false;
		$src = (string)file_get_contents($path);
		try {
			token_get_all($src, TOKEN_PARSE);
		} catch (Throwable) {
			return false;
		}
		return str_contains($src, '$nuConfigDBName');
	}
}
