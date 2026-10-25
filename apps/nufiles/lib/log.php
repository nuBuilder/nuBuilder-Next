<?php
defined('NU_PAGE') || exit;

/**
 * nuFiles – change log in an own table (default nufiles_log, setting log_table). Created on first use; logging never stops an
 * operation. Read accesses (list, download, preview) are logged only with the setting log_reads = true.
 */
final class NufLog
{
	public static function table(NuPageContext $nu): string
	{
		$t = (string)$nu->setting('log_table', 'nufiles_log');
		return preg_match('/^[A-Za-z0-9_]{1,60}$/', $t) ? $t : 'nufiles_log';
	}

	public static function ensure(NuPageContext $nu): bool
	{
		$s = &$nu->session();
		$t = self::table($nu);
		if (($s['nuf_log_ok'] ?? '') === $t) return true;
		try {
			if ($nu->dbType() === 'sqlsrv') {
				$nu->exec("IF OBJECT_ID(N'$t', N'U') IS NULL CREATE TABLE [$t] ([{$t}_id] NVARCHAR(25) NOT NULL PRIMARY KEY, [log_at] DATETIME2 NOT NULL,
					[user_id] NVARCHAR(25) NULL, [login] NVARCHAR(100) NULL, [real_login] NVARCHAR(100) NULL, [share_id] NVARCHAR(64) NULL,
					[action] NVARCHAR(30) NULL, [path] NVARCHAR(2000) NULL, [target] NVARCHAR(2000) NULL, [bytes] BIGINT NULL, [ok] TINYINT NULL,
					[detail] NVARCHAR(1000) NULL, [ip] NVARCHAR(64) NULL)");
			} else {
				$nu->exec("CREATE TABLE IF NOT EXISTS `$t` (`{$t}_id` VARCHAR(25) NOT NULL PRIMARY KEY, `log_at` DATETIME NOT NULL,
					`user_id` VARCHAR(25) NULL, `login` VARCHAR(100) NULL, `real_login` VARCHAR(100) NULL, `share_id` VARCHAR(64) NULL,
					`action` VARCHAR(30) NULL, `path` VARCHAR(2000) NULL, `target` VARCHAR(2000) NULL, `bytes` BIGINT NULL, `ok` TINYINT NULL,
					`detail` VARCHAR(1000) NULL, `ip` VARCHAR(64) NULL, KEY `{$t}_at` (`log_at`), KEY `{$t}_share` (`share_id`))
					ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
			}
			$s['nuf_log_ok'] = $t;
			return true;
		} catch (Throwable $e) {
			$nu->log('nuFiles: log table not available: ' . $e->getMessage());
			return false;
		}
	}

	public static function write(NuPageContext $nu, string $action, string $share, string $path, string $target = '', ?int $bytes = null, bool $ok = true, string $detail = ''): void
	{
		try {
			if (!self::ensure($nu)) return;
			$u = $nu->user();
			$real = $nu->realUser();
			$nu->insert(self::table($nu), [
				'log_at' => date('Y-m-d H:i:s'), 'user_id' => $u['id'], 'login' => mb_substr($u['login'] !== '' ? $u['login'] : $u['id'], 0, 100),
				'real_login' => $real ? mb_substr((string)($real['login'] ?? $real['id'] ?? ''), 0, 100) : null,
				'share_id' => mb_substr($share, 0, 64), 'action' => mb_substr($action, 0, 30), 'path' => mb_substr($path, 0, 2000),
				'target' => $target !== '' ? mb_substr($target, 0, 2000) : null, 'bytes' => $bytes, 'ok' => $ok ? 1 : 0,
				'detail' => $detail !== '' ? mb_substr($detail, 0, 1000) : null, 'ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
			]);
		} catch (Throwable $e) {
			$nu->log('nuFiles: log write failed: ' . $e->getMessage());
		}
	}

	/** Latest entries for the globeadmin, optional filters. */
	public static function latest(NuPageContext $nu, int $limit, string $share, string $q): array
	{
		if (!self::ensure($nu)) return [];
		$t = self::table($nu);
		$where = []; $p = [];
		if ($share !== '') { $where[] = 'share_id = ?'; $p[] = $share; }
		if ($q !== '') { $where[] = '(path LIKE ? OR target LIKE ? OR login LIKE ? OR action LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
		$limit = max(1, min(2000, $limit));
		$sql = $nu->dbType() === 'sqlsrv'
			? "SELECT TOP $limit * FROM [$t]" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY log_at DESC'
			: "SELECT * FROM `$t`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY log_at DESC LIMIT $limit";
		return $nu->all($sql, $p);
	}
}
