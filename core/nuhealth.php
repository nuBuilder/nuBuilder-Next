<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Health / status endpoint for monitoring tools: index.php?p=health – served in index.php BEFORE nu_boot (no session, no
 * cookie), JSON, never cached.
 *   Without a key   {"status": "ok" | "fail"} only – the database answers or not (liveness; nothing about the installation).
 *   With the key    (header X-Health-Key, Authorization: Bearer <key> or ?key=): every check – database (reachable, ms), disk (free space
 *                   of the program folder and the backup folder), backup (age of the newest backup), cron (age of the last cron run of
 *                   nucron.php), maintenance mode, version. status = fail (database) | warn (a threshold) | ok.
 *   HTTP 200 for ok / warn, 503 for fail; ?strict=1 also 503 for warn (tools that only look at the code, e.g. a simple uptime check).
 *   Setting 'health' {key (sha256 of the key – the key itself is shown once), backup_hours, cron_hours, disk_mb}; Settings → Data & logs,
 *   card #shHealth (globeadmin). Command line: php nucron.php health [--json].
 */
final class NuHealth
{
	public const SETTING = 'health';
	public const DEFAULTS = ['key' => '', 'backup_hours' => 48, 'cron_hours' => 2, 'disk_mb' => 500];

	public static function settings(): array
	{
		$v = null;
		try { $v = nu_setting_value(self::SETTING); } catch (Throwable $ig) { nu_ignored($ig, 'no table yet'); }
		$j = is_string($v) ? json_decode($v, true) : null;
		$j = is_array($j) ? $j : [];
		return ['key' => is_string($j['key'] ?? null) ? $j['key'] : '',
			'backup_hours' => max(0, min(8760, (int)($j['backup_hours'] ?? self::DEFAULTS['backup_hours']))),
			'cron_hours' => max(0, min(8760, (int)($j['cron_hours'] ?? self::DEFAULTS['cron_hours']))),
			'disk_mb' => max(0, min(10000000, (int)($j['disk_mb'] ?? self::DEFAULTS['disk_mb'])))];
	}

	private static function save(array $s): void
	{
		NuSchema::ensure();
		nu_setting_save(self::SETTING, ['setting_value' => json_encode($s, JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s'),
			'updated_by' => (string)(NuAuth::user()['login'] ?? 'cli')]);
	}

	/** Thresholds (0 = no warning). @return bool changed */
	public static function saveLimits(int $backupHours, int $cronHours, int $diskMb): bool
	{
		$s = self::settings();
		$n = ['backup_hours' => max(0, min(8760, $backupHours)), 'cron_hours' => max(0, min(8760, $cronHours)), 'disk_mb' => max(0, min(10000000, $diskMb))];
		if (array_intersect_key($s, $n) === $n) return false;
		self::save($n + $s);
		return true;
	}

	/** A new key (returned once; only its hash is stored) – or none ($remove). */
	public static function newKey(bool $remove = false): string
	{
		$key = $remove ? '' : bin2hex(random_bytes(24));
		self::save(['key' => $key === '' ? '' : hash('sha256', $key)] + self::settings());
		NuLog::write($remove ? 'health_key_removed' : 'health_key_set', 'zzzzsys_nunext_setting', self::SETTING, []);
		return $key;
	}

	public static function keyOk(string $given): bool
	{
		$h = self::settings()['key'];
		return $h !== '' && $given !== '' && hash_equals($h, hash('sha256', $given));
	}

	/** The key of the request: header X-Health-Key, Authorization: Bearer, or ?key=. */
	public static function requestKey(): string
	{
		$k = (string)($_SERVER['HTTP_X_HEALTH_KEY'] ?? '');
		if ($k === '' && preg_match('/^Bearer\s+(\S+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) $k = $m[1];
		if ($k === '') $k = (string)($_GET['key'] ?? '');
		return substr(trim($k), 0, 200);
	}

	/** The database answers? [ok, ms, error] – never nu_fatal (nu_db() would print an HTML page). */
	private static function database(): array
	{
		$t = microtime(true);
		try {
			$c = nu_config();
			$db = new NuDB(['type' => $c['nuConfigDBType'], 'host' => $c['nuConfigDBHost'] ?? '127.0.0.1', 'port' => $c['nuConfigDBPort'],
				'name' => $c['nuConfigDBName'] ?? '', 'user' => $c['nuConfigDBUser'] ?? '', 'password' => $c['nuConfigDBPassword'] ?? '',
				'charset' => $c['nuConfigDBCharacterSet'], 'collate' => $c['nuConfigDBCollate'], 'options' => (array)$c['nuConfigDBOptions'],
				'trust_cert' => $c['nuConfigDBTrustServerCertificate']]);
			$db->value('SELECT 1');
			NuDB::$override = $db;                                // the other checks read settings through it
			try { nu_apply_timezone(); } catch (Throwable $ig) { nu_ignored($ig, 'times in the server\'s zone'); }
			return [true, (int)round((microtime(true) - $t) * 1000), ''];
		} catch (Throwable $e) {
			error_log('nuBuilder Next health: ' . $e->getMessage());
			return [false, (int)round((microtime(true) - $t) * 1000), 'no connection'];
		}
	}

	/** Seconds as hours with one decimal. */
	private static function hours(int $sec): float
	{
		return round(max(0, $sec) / 3600, 1);
	}

	/**
	 * The checks. $full = false: status only.
	 * @return array{status: string, checks?: array<string, array>, version?: string, time?: string}
	 */
	public static function report(bool $full): array
	{
		[$dbOk, $ms, $err] = self::database();
		if (!$full) return ['status' => $dbOk ? 'ok' : 'fail'];
		$checks = ['database' => ['status' => $dbOk ? 'ok' : 'fail', 'ms' => $ms] + ($err !== '' ? ['error' => $err] : [])];
		$s = $dbOk ? self::settings() : self::DEFAULTS;
		$now = time();
		// disk: the program folder and the backup folder (often another disk)
		$disk = [];
		$low = false;
		$dirs = ['program' => NU_ROOT];
		try { $dirs['backups'] = NuUpdate::backupDir(); } catch (Throwable $ig) { nu_ignored($ig, 'no backup folder'); }
		foreach ($dirs as $k => $dir) {
			$free = function_exists('disk_free_space') && is_dir($dir) ? @disk_free_space($dir) : false;
			if ($free === false) { $disk[$k] = null; continue; }
			$mb = (int)floor($free / 1048576);
			$disk[$k] = $mb;
			if ($s['disk_mb'] > 0 && $mb < $s['disk_mb']) $low = true;
		}
		$checks['disk'] = ['status' => $low ? 'warn' : (array_filter($disk, static fn($v) => $v !== null) ? 'ok' : 'unknown'), 'free_mb' => $disk, 'min_mb' => $s['disk_mb']];
		// backup: the newest one
		$newest = null;
		try { $b = NuUpdate::backups(); $newest = $b ? (int)strtotime((string)$b[0]['created']) : null; } catch (Throwable $ig) { nu_ignored($ig, 'none'); }
		$bAge = $newest ? self::hours($now - $newest) : null;
		$checks['backup'] = ['status' => $newest === null ? 'warn' : ($s['backup_hours'] > 0 && $bAge > $s['backup_hours'] ? 'warn' : 'ok'),
			'last' => $newest ? date('c', $newest) : null, 'age_hours' => $bAge, 'max_hours' => $s['backup_hours']];
		// cron: the last run of nucron.php (a page view also does the housekeeping now and then – only cron counts here)
		$last = $dbOk ? NuHousekeeping::lastCron() : null;
		$cAge = $last ? self::hours($now - $last) : null;
		$checks['cron'] = ['status' => $last === null ? 'warn' : ($s['cron_hours'] > 0 && $cAge > $s['cron_hours'] ? 'warn' : 'ok'),
			'last' => $last ? date('c', $last) : null, 'age_hours' => $cAge, 'max_hours' => $s['cron_hours']];
		$maint = false;
		try { $maint = NuUpdate::maintenance() !== null; } catch (Throwable $ig) { nu_ignored($ig, 'not readable'); }
		$checks['maintenance'] = ['status' => $maint ? 'warn' : 'ok', 'on' => $maint];
		$st = array_column($checks, 'status');
		$status = in_array('fail', $st, true) ? 'fail' : (in_array('warn', $st, true) ? 'warn' : 'ok');
		return ['status' => $status, 'version' => NU_VERSION, 'time' => date('c', $now), 'checks' => $checks];
	}

	/** The answer of ?p=health. */
	public static function serve(): void
	{
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Robots-Tag: noindex');
		$full = false;
		$key = self::requestKey();
		if ($key !== '') {
			[$ok] = self::database();
			$full = $ok ? self::keyOk($key) : true;               // the database is down: the key cannot be checked – the details say so
			if ($ok && !$full) { http_response_code(401); echo json_encode(['status' => 'unauthorized']); return; }
		}
		$r = self::report($full);
		$code = $r['status'] === 'fail' || ($r['status'] === 'warn' && ($_GET['strict'] ?? '') === '1') ? 503 : 200;
		http_response_code($code);
		echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	}
}
