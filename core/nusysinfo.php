<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * System info (0.8.1237, the owner picked variation 1 of the canvas "System info dialog"): user menu → "System info…" (globeadmin and
 * admin right update) opens a dialog with nuBuilder Next, server, database – from the JSON route ?p=sysinfo (pages/nusysinfo.php) –
 * and the browser (assets/js/nusysinfo.js). "Copy for support" copies everything as text. Never shown: passwords, keys, the
 * database host or user.
 */
final class NuSysInfo
{
	/** PHP extensions nuBuilder Next needs or uses (missing ones are listed). */
	private const EXT = ['pdo', 'mbstring', 'json', 'openssl', 'sodium', 'zip', 'gd', 'intl', 'curl', 'fileinfo'];

	public static function allowed(): bool
	{
		return NuAdmin::can('update');
	}

	/** @return list<array{id: string, title: string, rows: list<array{0: string, 1: string, 2?: string}>}> rows [label, value, state ok|warn|''] */
	public static function sections(): array
	{
		return [
			['id' => 'next', 'title' => 'nuBuilder Next', 'rows' => self::next()],
			['id' => 'server', 'title' => t('si_server'), 'rows' => self::server()],
			['id' => 'db', 'title' => t('si_database'), 'rows' => self::database()],
		];
	}

	private static function next(): array
	{
		$m = NuUpdate::installedManifest() ?? [];
		$rows = [[t('si_version'), NU_VERSION . (($m['built'] ?? '') !== '' ? ' · ' . t('si_built', (string)$m['built']) : ''),
			'']];
		if (array_key_exists('gated', $m)) $rows[] = [t('si_tested'), !empty($m['gated']) ? t('si_tested_yes') : t('si_tested_no', (string)($m['last_gate'] ?? '–')), !empty($m['gated']) ? 'ok' : 'warn'];
		try {
			$g = NuGitHub::settings();
			$lv = (string)($g['latest']['version'] ?? '');
			if ($lv !== '') $rows[] = [t('si_github'), $lv . ' · ' . (version_compare($lv, NU_VERSION, '>') ? t('si_newer') : t('si_uptodate')) . ($g['checked_at'] ? ' · ' . date(nu_dfmt('d.m.Y H:i'), (int)$g['checked_at']) : ''),
				version_compare($lv, NU_VERSION, '>') ? 'warn' : 'ok'];
		} catch (Throwable) { /* no GitHub setting */ }
		$rows[] = [t('si_devmode'), t(NuHash::devMode() ? 'sh_on' : 'sh_off'), ''];
		$f = self::forteVersion();
		if ($f !== '') $rows[] = ['nuBuilder Forte', $f, ''];
		return $rows;
	}

	/** First line of nuBuilder Forte's version.txt ("nuBuilder Forte 4.9.2"), '' when not found. */
	public static function forteVersion(): string
	{
		try {
			$p = NuBuilderLib::path();
			$f = $p !== '' ? rtrim($p, '/\\') . '/version.txt' : '';
			if ($f === '' || !is_file($f) || !is_readable($f)) return '';
			$first = trim((string)strtok((string)file_get_contents($f, false, null, 0, 2000), "\n"));
			return mb_substr(preg_replace('/^nuBuilder\s+Forte\s*/i', '', $first) ?? $first, 0, 60);
		} catch (Throwable) {
			return '';
		}
	}

	private static function server(): array
	{
		$ini = static fn(string $k): string => (string)nu_ini_get($k);
		$rows = [['PHP', PHP_VERSION . ' (' . PHP_SAPI . ')', version_compare(PHP_VERSION, '8.2', '<') ? 'warn' : ''],
			[t('si_limits'), t('si_limits_v', $ini('memory_limit'), $ini('upload_max_filesize'), $ini('post_max_size'), $ini('max_execution_time')), '']];
		$sw = trim((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
		if ($sw !== '') $rows[] = [t('si_webserver'), mb_substr($sw, 0, 80), ''];
		$os = PHP_OS_FAMILY . (function_exists('php_uname') ? ' ' . php_uname('r') . ' · ' . php_uname('m') : '');
		$rows[] = [t('si_os'), $os, ''];
		$rows[] = [t('si_timezone'), date_default_timezone_get() . ' (UTC' . date('P') . ')', ''];
		$missing = array_values(array_filter(self::EXT, static fn($e) => !extension_loaded($e)));
		$db = nu_db()->type === 'sqlsrv' ? 'pdo_sqlsrv' : 'pdo_mysql';
		if (!extension_loaded($db)) $missing[] = $db;
		$rows[] = [t('si_ext'), $missing ? t('si_ext_missing', implode(', ', $missing)) : t('si_ext_ok'), $missing ? 'warn' : 'ok'];
		return $rows;
	}

	private static function database(): array
	{
		$db = nu_db();
		$rows = [];
		try {
			if ($db->type === 'mysql') {
				$v = (string)$db->value('SELECT VERSION()');
				$name = (string)$db->value('SELECT DATABASE()');
				$rows[] = [t('si_db_server'), (stripos($v, 'mariadb') !== false ? 'MariaDB ' : 'MySQL ') . preg_replace('/-MariaDB.*$/i', '', $v), ''];
				$rows[] = [t('si_db_name'), $name . ' · ' . (string)$db->value('SELECT @@collation_database'), ''];
				$s = $db->one('SELECT COUNT(*) n, COALESCE(SUM(data_length + index_length), 0) b FROM information_schema.tables WHERE table_schema = DATABASE()');
				$rows[] = [t('si_db_size'), t('si_db_size_v', self::mb((float)($s['b'] ?? 0)), (int)($s['n'] ?? 0)), ''];
			} elseif ($db->type === 'sqlsrv') {
				$v = (string)$db->value("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(50)) + ' ' + CAST(SERVERPROPERTY('Edition') AS nvarchar(100))");
				$rows[] = [t('si_db_server'), 'SQL Server ' . $v, ''];
				$rows[] = [t('si_db_name'), (string)$db->value('SELECT DB_NAME()') . ' · ' . (string)$db->value("SELECT CAST(DATABASEPROPERTYEX(DB_NAME(), 'Collation') AS nvarchar(100))"), ''];
				$rows[] = [t('si_db_size'), t('si_db_size_v', self::mb((float)$db->value('SELECT SUM(size) * 8192.0 FROM sys.database_files')), (int)$db->value('SELECT COUNT(*) FROM sys.tables')), ''];
			} else {
				$rows[] = [t('si_db_server'), $db->type, ''];
			}
		} catch (Throwable $e) {
			$rows[] = [t('si_db_server'), $db->type, ''];
		}
		$steps = 0;
		try { if ($db->tableExists(NuMigrate::TABLE)) $steps = (int)$db->value('SELECT COUNT(*) FROM ' . NuMigrate::TABLE); } catch (Throwable) { /* none */ }
		$rows[] = [t('si_own_tables'), t('si_own_tables_v', NuSchema::VERSION, $steps), ''];
		return $rows;
	}

	private static function mb(float $bytes): string
	{
		return $bytes >= 1073741824 ? number_format($bytes / 1073741824, 1) . ' GB' : number_format($bytes / 1048576, $bytes < 10485760 ? 1 : 0) . ' MB';
	}
}
