<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Housekeeping (0.8.207): expired tokens and API tokens, ended sessions and tool logins, old API rate counters, the log beyond
 * "Keep the log", temporary files (saved reports, update uploads, nuconfig.php checks, CSV imports).
 *
 * Run it by cron:   php <nuBuilder Next>/nucron.php      (e.g. every 15 minutes – see Help → Security)
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
			$v = nu_setting_value(self::SETTING);
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
		$step('userhelp_images', static fn() => NuUserHelp::purgeImages());   // pictures of help pages no page uses (0.8.1024)
		$step('project_tasks', static fn() => NuProject::dueHousekeeping());   // to-dos of projects due today or earlier: one notification each (0.8.1035)
		$step('notifications', static fn() => NuNotify::housekeeping());   // due e-mails of scheduled notifications, old ones deleted (0.8.357)               // audit trail beyond "Keep" (0.8.350)
		$step('ai_calls', static fn() => NuAiProvider::purge());            // usage rows of AI requests older than 400 days (0.8.1099)
		$step('opened', static fn() => NuOpened::purge());                  // "Last opened" rows not touched for 400 days (0.8.1117)
		$step('webhooks', static fn() => NuWebhook::housekeeping());       // due retries of webhook calls, old calls deleted (0.8.715)
		$step('report_files', static fn() => NuReportApi::purge());
		$step('dbreset_files', static fn() => NuDbReset::purgeUploads());   // uploads of Reset database left behind (0.8.847)       // PDFs of the REST API whose link expired or was used up (0.8.340)
		$step('demo_reset', static fn() => NuDemoData::resetIfDue());
		$step('field_access', static fn() => NuFieldAccess::purgeOrphans());   // rules of deleted objects (0.8.480)
		$step('permission_types', static fn() => NuPermType::purgeOrphans());
		$step('passkeys', static fn() => NuPasskey::purgeOrphans());                     // passkeys of deleted users (0.8.877)
		$step('form_maintenance', static fn() => NuFormStatus::purge());   // messages of forms no longer in maintenance (0.8.808)
		$step('form_everyone', static fn() => NuFormEveryone::purgeOrphans());   // "Everyone" switches of deleted forms (0.8.784)   // types of deleted permission items, also deleted in nuBuilder Forte (0.8.773)
		if ($source === 'cron') $step('github', static fn() => NuGitHub::autoCheck());   // a newer release on GitHub? once a day (0.8.374) – page views: see NuHome::badges()     // demo mode: tables of the allowed forms back to the snapshot (0.8.341)
		$step('files', static function (): int {
			$n = 0;
			$old = time() - 86400;
			$globs = [NU_ROOT . '/temp/*', NU_ROOT . '/temp/tcpdf/__tcpdf_*', NuUpdate::backupDir() . '/upload-*.zip', NuUpdate::backupDir() . '/nuconfig-check-*.php'];   // TCPDF's leftovers (0.8.770)
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
			nu_setting_save(self::SETTING, $row, $db);
		} catch (Throwable) {
		}
		return $done;
	}
}
