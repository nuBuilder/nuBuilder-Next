<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Housekeeping (0.8.207): expired tokens and API tokens, ended sessions and tool logins, old API rate counters, the log beyond
 * "Keep the log", temporary files (saved reports, update uploads, nuconfig.php checks, CSV imports).
 *
 * Run it by cron:   php <nuBuilder Next>/cron.php      (e.g. every 15 minutes – see Help → Security)
 * Without cron a page view now and then does the quick part (as before 0.8.207); once cron has run in the last 25 hours,
 * page views leave it to cron. The last run is stored in zzzzsys_nunext_setting 'housekeeping'.
 */
final class NuHousekeeping
{
	public const SETTING = 'housekeeping';

	/** @return array{time: int, source: string}|null */
	public static function last(): ?array
	{
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$j = is_string($v) ? json_decode($v, true) : null;
			return is_array($j) && isset($j['time']) ? ['time' => (int)$j['time'], 'source' => (string)($j['source'] ?? 'web')] : null;
		} catch (Throwable) {
			return null;
		}
	}

	/** A page view: the quick part now and then, unless cron does it. */
	public static function maybe(): void
	{
		if (random_int(1, 50) !== 1) return;
		$last = self::last();
		if ($last && $last['source'] === 'cron' && $last['time'] > time() - 25 * 3600) return;
		self::run('web');
	}

	/** @return array<string, int> what was removed */
	public static function run(string $source): array
	{
		$done = [];
		$step = static function (string $name, callable $fn) use (&$done) {
			try {
				$done[$name] = (int)$fn();
			} catch (Throwable $e) {
				error_log("nuBuilder Next housekeeping $name: " . $e->getMessage());
				$done[$name] = -1;
			}
		};
		NuSchema::ensure();
		$db = nu_db();
		$now = date('Y-m-d H:i:s');
		$step('tokens', static fn() => $db->run('DELETE FROM zzzzsys_nunext_token WHERE expires_at < ?', [$now])->rowCount());
		$step('sessions', static function () { NuSessions::purge(); return 0; });
		if ($db->tableExists(NuApiClient::TOKEN)) $step('api_tokens', static fn() => $db->run('DELETE FROM ' . NuApiClient::TOKEN . ' WHERE expires_at < ?', [$now])->rowCount());
		if ($db->tableExists(NuApiClient::RATE)) $step('api_rate', static fn() => $db->run('DELETE FROM ' . NuApiClient::RATE . ' WHERE minute_at < ?', [date('Y-m-d H:i:s', time() - 3600)])->rowCount());
		$step('log', static fn() => NuLog::purge());
		$step('audit', static fn() => NuAudit::purge());
		$step('notifications', static fn() => NuNotify::housekeeping());   // due e-mails of scheduled notifications, old ones deleted (0.8.357)               // audit trail beyond "Keep" (0.8.350)
		$step('report_files', static fn() => NuReportApi::purge());       // PDFs of the REST API whose link expired or was used up (0.8.340)
		$step('demo_reset', static fn() => NuDemoData::resetIfDue());     // demo mode: tables of the allowed forms back to the snapshot (0.8.341)
		$step('files', static function (): int {
			$n = 0;
			$old = time() - 86400;
			$globs = [NU_ROOT . '/temp/*', NuUpdate::backupDir() . '/upload-*.zip', NuUpdate::backupDir() . '/nuconfig-check-*.php'];
			foreach ($globs as $g) {
				foreach (glob($g) ?: [] as $f) {
					if (!is_file($f) || in_array(basename($f), ['.htaccess', 'index.html'], true)) continue;
					if ((int)@filemtime($f) < $old && @unlink($f)) $n++;
				}
			}
			return $n;
		});
		try {
			$row = ['setting_value' => json_encode(['time' => time(), 'source' => $source, 'done' => $done]), 'updated_at' => $now, 'updated_by' => $source];
			if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) {
				$db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
			} else {
				$db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
			}
		} catch (Throwable) {
		}
		return $done;
	}
}
