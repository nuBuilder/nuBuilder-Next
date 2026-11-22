<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Housekeeping: expired tokens and API tokens, ended sessions and tool logins, old API rate counters, the log beyond
 * "Keep the log", temporary files (saved reports, update uploads, nuconfig.php checks, CSV imports).
 *
 * Run it by cron:   php <nuBuilder Next>/nucron.php      (e.g. every 15 minutes – see Help → Security)
 * Without cron a page view now and then does the quick part; once cron has run in the last 25 hours,
 * page views leave it to cron. The last run is stored in zzzzsys_nunext_setting 'housekeeping'.
 */
final class NuHousekeeping
{
	public const SETTING = 'housekeeping';
	public const CRON_SETTING = 'housekeeping_cron';

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
		$step('userhelp_images', static fn() => NuUserHelp::purgeImages());   // pictures of help pages no page uses
		$step('project_tasks', static fn() => NuProject::dueHousekeeping());   // to-dos of projects due today or earlier: one notification each
		$step('notifications', static fn() => NuNotify::housekeeping());   // due e-mails of scheduled notifications, old ones deleted               // audit trail beyond "Keep"
		$step('ai_calls', static fn() => NuAiProvider::purge());            // usage rows of AI requests older than 400 days
		$step('recycle', static fn() => NuRecycle::purge());                // recycle bin entries past their date
		$step('opened', static fn() => NuOpened::purge());                  // "Last opened" rows not touched for 400 days
		$step('webhooks', static fn() => NuWebhook::housekeeping());       // due retries of webhook calls, old calls deleted
		$step('report_files', static fn() => NuReportApi::purge());
		$step('dbreset_files', static fn() => NuDbReset::purgeUploads());   // uploads of Reset database left behind       // PDFs of the REST API whose link expired or was used up
		$step('demo_reset', static fn() => NuDemoData::resetIfDue());
		$step('field_access', static fn() => NuFieldAccess::purgeOrphans());   // rules of deleted objects
		$step('permission_types', static fn() => NuPermType::purgeOrphans());
		$step('passkeys', static fn() => NuPasskey::purgeOrphans());                     // passkeys of deleted users
		$step('form_maintenance', static fn() => NuFormStatus::purge());   // messages of forms no longer in maintenance
		$step('form_everyone', static fn() => NuFormEveryone::purgeOrphans());
		$step('plugins', static function () use ($source): int {      // the plugins' own clean-up
			$n = 0;
			foreach (NuPlugin::loaded() as $id => $api) foreach ($api->hooks('housekeeping') as [, $fn]) $n += (int)NuPlugin::call($id, $fn, [['source' => $source]]);
			return $n;
		});   // "Everyone" switches of deleted forms   // types of deleted permission items, also deleted in nuBuilder Forte
		if ($source === 'cron') $step('github',
			static fn() => NuGitHub::autoCheck());   // a newer release on GitHub? once a day – page views: see NuHome::badges()     // demo mode: tables of the allowed forms back to the snapshot
		$step('files', static function (): int {
			$n = 0;
			$old = time() - 86400;
			$globs = [NU_ROOT . '/temp/*', NU_ROOT . '/temp/tcpdf/__tcpdf_*', NuUpdate::backupDir() . '/upload-*.zip', NuUpdate::backupDir() . '/nuconfig-check-*.php'];   // TCPDF's leftovers
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
			if ($source === 'cron') nu_setting_save(self::CRON_SETTING, ['setting_value' => (string)time(), 'updated_at' => $now, 'updated_by' => 'cron'], $db);   // health check
		} catch (Throwable $ig) { nu_ignored($ig); }
		return $done;
	}

	/** Unix time of the last run of nucron.php (page views do the housekeeping too – only cron counts here), null = never. */
	public static function lastCron(): ?int
	{
		try {
			$v = nu_setting_value(self::CRON_SETTING);
		} catch (Throwable) {
			return null;
		}
		if (is_string($v) && ctype_digit($v)) return (int)$v;
		$l = self::last();
		return $l && $l['source'] === 'cron' ? $l['time'] : null;
	}
}
