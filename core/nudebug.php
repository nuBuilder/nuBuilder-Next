<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Debug messages (zzzzsys_debug, nuBuilder's "Debug Results"): nuDebug(…) in PHP code writes a row,
 * deb_added = unix timestamp, deb_user_id = user, deb_flag = nuDebug::setFlag('x')->log(…).
 * Same functions and message format as nuBuilder (core/nudatabase.php).
 */
final class NuDebugLog
{
	public const TABLE = 'zzzzsys_debug';
	/** Label of the PHP code that is running (hook / procedure), set by NuPhp::run(). */
	public static string $context = '';

	public static function ensureTable(): void
	{
		static $done = false;
		if ($done) return;
		$done = true;
		$db = nu_db();
		if ($db->tableExists(self::TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$db->run('CREATE TABLE ' . self::TABLE . ' (zzzzsys_debug_id ' . $T('id') . ' NOT NULL PRIMARY KEY, deb_message ' . $T('longtext') . ' NULL, '
			. 'deb_flag ' . $T('text', '50') . ' NULL, deb_added ' . $T('int') . ' NULL, deb_user_id ' . $T('id') . ' NULL, deb_json ' . $T('longtext') . ' NULL)'
			. ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
	}

	/** nuBuilder's message: "<date> - <code> line <n>\n\n<br>\n" + "[i] : value" per argument. */
	public static function createOutput(array $args, int $line = 0): string
	{
		$msg = sprintf("%s - %s line %d\n\n<br>\n", date('Y-m-d H:i:s'), self::$context, $line);
		foreach (array_values($args) as $i => $arg) {
			if (is_string($arg) && str_starts_with($arg, ' <html>')) $msg .= $arg;
			else $msg .= sprintf("\n[%d] : ", $i) . (is_array($arg) || is_object($arg) ? print_r($arg, true) : (is_bool($arg) ? ($arg ? 'true' : 'false') : (string)$arg));
			$msg .= "\n";
		}
		return $msg;
	}

	public static function write(mixed $message, ?string $flag = null): ?string
	{
		if (is_object($message) || is_array($message)) $message = print_r($message, true);
		$id = nu_id();
		try {
			self::ensureTable();
			$u = NuAuth::currentRaw();
			$row = ['zzzzsys_debug_id' => $id, 'deb_message' => (string)$message, 'deb_flag' => $flag !== null && $flag !== '' ? mb_substr($flag, 0, 50) : null,
				'deb_added' => time(), 'deb_user_id' => ($u['id'] ?? '') !== '' ? mb_substr((string)$u['id'], 0, 25) : null];
			$cols = nu_db()->columns(self::TABLE);
			nu_db()->insert(self::TABLE, $cols ? array_intersect_key($row, $cols) : $row);
		} catch (Throwable $e) {
			error_log('nuDebug: ' . $message . ' (' . $e->getMessage() . ')');
			return null;
		}
		return $id;
	}
}

if (!function_exists('nuDebug')) {
	function nuDebug(mixed ...$args): void
	{
		$line = (int)(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? 0);
		NuDebugLog::write(NuDebugLog::createOutput($args, $line));
	}

	function nuDebugCreateOutput(mixed ...$args): string
	{
		$line = (int)(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? 0);
		return NuDebugLog::createOutput($args, $line);
	}

	function nuDebugResult($message, $flag = null): ?string
	{
		return NuDebugLog::write($message, $flag === null ? null : (string)$flag);
	}

	/** nuDebug::setFlag('import')->log($a, $b) – PHP class names ignore case, so this is "nuDebug" as in nuBuilder. */
	final class nuDebug
	{
		public static string $flag = '';

		public static function setFlag($flag): static
		{
			static::$flag = (string)$flag;
			return new static();
		}

		public static function log(mixed ...$args): void
		{
			$line = (int)(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? 0);
			NuDebugLog::write(NuDebugLog::createOutput($args, $line), static::$flag);
		}
	}
}
