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
		return self::origin() === 'nubuilder' && !self::existingAllowed();
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
