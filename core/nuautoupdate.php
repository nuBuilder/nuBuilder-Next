<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Unattended updates (0.8.301): the update page's steps without a browser – for the command line (php cron.php update …) and the
 * REST API (POST api.php/v1/system/update, scope system:update). Uses NuUpdate (checks, signature, backup, maintenance, install,
 * restore when copying fails) and the same options as the page.
 *
 *   options: url (https; http only for localhost), zip (a file on the server – command line only) or github (the newest release of the
 *            repository set on the update page, 0.8.374 – NuGitHub), sha256 (optional checksum),
 *            backup files|nubuilder|sql|sql_nologs|auto (auto = nubuilder: the zzzzsys_* tables without logs, 0.8.615), schema none|missing|safe|all, sample auto|yes|no, reinstall, dry_run
 *
 * Two phases, like the page: run() checks and installs; finish() runs the database steps and the sample refresh with the NEW code,
 * so it must run in a new PHP process – the command line starts one itself, the API's status request (GET …/update/{job}) does it,
 * and cron.php (housekeeping) and the update page finish a job that is still pending. Jobs are JSON files in the backup folder.
 * Only packages with a valid signature are installed; $nuConfigEnableDatabaseUpdate = false blocks everything; one update at a time.
 */
final class NuAutoUpdate
{
	public const BACKUPS = ['auto', 'files', 'nubuilder', 'sql', 'sql_nologs'];
	public const SCHEMAS = ['none', 'missing', 'safe', 'all'];
	public const SAMPLES = ['auto', 'yes', 'no'];
	private const MAX_DOWNLOAD = 64 * 1048576;
	private const JOB_RE = '/^[a-f0-9]{16}$/';

	/** Checked options (unknown values → error). @return array{0: array, 1: array} [options, errors] */
	public static function options(array $in, bool $cli): array
	{
		$e = [];
		$o = [
			'url' => trim((string)($in['url'] ?? '')), 'zip' => trim((string)($in['zip'] ?? '')), 'sha256' => strtolower(trim((string)($in['sha256'] ?? ''))),
			'backup' => (string)($in['backup'] ?? 'auto'), 'schema' => (string)($in['schema'] ?? 'safe'), 'sample' => (string)($in['sample'] ?? 'auto'),
			'reinstall' => self::bool($in['reinstall'] ?? false), 'dry_run' => self::bool($in['dry_run'] ?? false), 'github' => self::bool($in['github'] ?? false),
			'ignore_integrity' => self::bool($in['ignore_integrity'] ?? false),
		];
		if ((int)($o['url'] !== '') + (int)($o['zip'] !== '') + (int)$o['github'] !== 1) $e['url'] = 'Give either url, zip or github.';
		if ($o['zip'] !== '' && !$cli) $e['zip'] = 'zip (a file on the server) is for the command line only – use url.';
		if ($o['url'] !== '' && !self::urlAllowed($o['url'])) $e['url'] = 'url must be https:// (http:// only for localhost).';
		if ($o['sha256'] !== '' && !preg_match('/^[a-f0-9]{64}$/', $o['sha256'])) $e['sha256'] = 'sha256 must be 64 hex characters.';
		foreach (['backup' => self::BACKUPS, 'schema' => self::SCHEMAS, 'sample' => self::SAMPLES] as $k => $allowed) {
			if (!in_array($o[$k], $allowed, true)) $e[$k] = $k . ' must be one of: ' . implode(', ', $allowed) . '.';
		}
		return [$o, $e];
	}

	private static function bool(mixed $v): bool
	{
		return is_bool($v) ? $v : in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
	}

	public static function urlAllowed(string $url): bool
	{
		$p = parse_url($url);
		if (!is_array($p) || empty($p['host'])) return false;
		$scheme = strtolower((string)($p['scheme'] ?? ''));
		return $scheme === 'https' || ($scheme === 'http' && in_array(strtolower((string)$p['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true));
	}

	// ------------------------------------------------------------------ phase 1: check (and install)

	/**
	 * Checks the package and – without dry_run – installs it. $by: 'cli' or the API client's name.
	 * @return array report: ok, dry_run, job, status, from, to, signature, errors, requirements, files, schema, sample, backup …
	 */
	public static function run(array $o, string $by): array
	{
		$r = ['ok' => false, 'dry_run' => $o['dry_run'], 'status' => 'failed', 'from' => NU_VERSION, 'to' => '', 'errors' => []];
		if (!NuUpdate::enabled()) { $r['errors'][] = 'Updates are switched off ($nuConfigEnableDatabaseUpdate = false).'; return $r; }
		$lock = self::lock();
		if (!$lock) { $r['errors'][] = 'Another update is running.'; return $r; }
		$tmp = null;
		try {
			// --------------------------------------------------- the package
			if ($o['github']) {                                         // newest release on GitHub (0.8.374): its download address
				$gs = NuGitHub::check();
				if ($gs['error'] !== '') throw new RuntimeException('GitHub: ' . $gs['error']);
				if (!$gs['latest']) throw new RuntimeException('GitHub: no release with ' . NuGitHub::ASSET . ' in ' . $gs['repo'] . '.');
				$r['github'] = ['repo' => $gs['repo'], 'tag' => $gs['latest']['tag'], 'pre' => $gs['latest']['pre']];
				if (!version_compare((string)$gs['latest']['version'], NU_VERSION, '>') && !$o['reinstall']) throw new RuntimeException('GitHub: the newest release (' . $gs['latest']['version'] . ') is not newer than the installed version (' . NU_VERSION . ').');
				$o['url'] = (string)$gs['latest']['url'];
			}
			if ($o['url'] !== '') {
				$tmp = self::download($o['url']);
				$zip = $tmp;
			} else {
				$zip = $o['zip'];
				if (!is_file($zip) || !is_readable($zip)) throw new RuntimeException('zip not found: ' . $zip);
			}
			$r['sha256'] = hash_file('sha256', $zip);
			if ($o['sha256'] !== '' && !hash_equals($o['sha256'], (string)$r['sha256'])) throw new RuntimeException('The package does not match sha256.');
			$info = NuUpdate::inspect($zip);
			$r['to'] = (string)$info['version'];
			$r['signature'] = (string)($info['signature'] ?? '');
			foreach ($info['errors'] as $err) $r['errors'][] = $err;
			if ($info['ok'] && $r['signature'] !== 'valid') $r['errors'][] = 'Unattended updates need a package with a valid signature (signature: ' . $r['signature'] . ').';
			if ($info['ok'] && !version_compare($r['to'], NU_VERSION, '>') && !$o['reinstall']) $r['errors'][] = 'The package (' . $r['to'] . ') is not newer than the installed version (' . NU_VERSION . ') – use reinstall.';
			$r['requirements'] = [];
			foreach (NuUpdate::requirements() as [$label, $ok, $detail]) {
				if ($label === t('upd_req_upload')) continue;                 // no upload here
				$r['requirements'][] = ['check' => $label, 'ok' => (bool)$ok, 'detail' => (string)$detail];
				if (!$ok) $r['errors'][] = 'Requirement not met: ' . $label . ' (' . $detail . ')';
			}
			if (!$info['ok']) return $r;

			// --------------------------------------------------- integrity of the database (0.8.698): problems stop the update
			$ic = NuIntegrity::forPackage($zip, $info['prefix']);
			$r['integrity'] = NuIntegrity::summary($ic);
			if ($ic['errors'] && !$o['ignore_integrity']) {
				foreach ($ic['items'] as $it) if ($it['level'] === 'error') $r['errors'][] = 'Integrity check: ' . NuIntegrity::title($it) . ' – ' . implode(' · ', array_slice($it['list'], 0, 5));
				$r['errors'][] = 'Fix these first, or add ignore_integrity (--ignore-integrity) to install anyway.';
			}

			// --------------------------------------------------- what would change
			$r['files'] = self::diff($info);
			$exp = NuSchemaCheck::fromZip($zip, $info['prefix']);
			$cmp = $exp ? NuSchemaCheck::compare($exp) : null;
			$keys = $cmp ? self::schemaKeys($cmp, $o['schema']) : [];
			$r['schema'] = $cmp === null ? null : [
				'missing' => array_map(static fn($c) => $c['table'] . '.' . $c['column'] . ' ' . $c['type'], $cmp['missing']),
				'changed' => array_map(static fn($c) => $c['table'] . '.' . $c['column'] . ' ' . $c['actual'] . ' → ' . $c['expected'] . ($c['safe'] ? '' : ' (not safe)'), $cmp['changed']),
				'apply' => $keys,
			];
			$st = NuSample::status();
			$sampleTo = $st['installed'] ? NuSample::versionInZip($zip, $info['prefix']) : 0;
			$refresh = $st['installed'] && ($o['sample'] === 'yes' || ($o['sample'] === 'auto' && $sampleTo > (int)$st['version']));
			$r['sample'] = ['installed' => $st['installed'], 'from' => $st['installed'] ? (int)$st['version'] : null, 'to' => $sampleTo ?: null, 'refresh' => $refresh];
			// database changes of this update grouped by table, with the SQL (0.8.346)
			$r['database'] = NuDbChanges::report(NuDbChanges::preview(NuDbChanges::fromZip($zip, $info['prefix']), $exp, $cmp,
				$refresh ? ['from' => (int)$st['version'], 'to' => $sampleTo] : null));
			// library packs the new version needs (0.8.406) – reported only; installed on Update → Libraries
			$r['libraries'] = array_values(array_map(static fn($x) => ['library' => $x['slot'], 'min' => $x['min'], 'needed' => $x['needed'], 'installed' => $x['have'],
				'available' => $x['best']['version'] ?? null], NuLibs::unmet(NuLibs::required((array)$info['manifest']), null, NuLibs::available())));
			$scope = $o['backup'];
			if ($scope === 'auto') $scope = 'nubuilder';                      // what an update can change: nuBuilder's zzzzsys_* tables (0.8.615)
			if ($scope !== 'files' && !(NuUpdate::loadDump() && NuSqlDump::available())) $scope = 'files';
			$r['backup_mode'] = $scope;
			if ($r['errors']) return $r;
			if ($o['dry_run']) { $r['ok'] = true; $r['status'] = 'checked'; return $r; }

			// --------------------------------------------------- install (as the page: structure first, then the files)
			$job = bin2hex(random_bytes(8));
			$r['job'] = $job;
			if ($keys && $exp) {
				$sr = NuSchemaCheck::apply($exp, $keys);
				$r['schema']['done'] = $sr['done'];
				if ($sr['errors']) throw new RuntimeException('Database structure: ' . implode(' · ', $sr['errors']));
			}
			$backup = NuUpdate::install($zip, $info, $scope === 'files' ? 'none' : ($scope === 'nubuilder' ? 'nubuilder' : 'all'), $scope !== 'sql');
			unset($_SESSION['nu_update']);                                      // the page's finish step is not needed – this job does it
			$r['backup'] = $backup;
			$r['ok'] = true;
			$r['status'] = 'installed';
			self::saveJob($job, ['job' => $job, 'status' => 'installed', 'from' => NU_VERSION, 'to' => $r['to'], 'backup' => $backup, 'by' => $by,
				'started' => date('Y-m-d H:i:s'), 'schema_done' => $r['schema']['done'] ?? [], 'sample_refresh' => $refresh, 'options' => array_diff_key($o, ['zip' => 1])]);
			NuLog::write('update_auto', '', $job, ['from' => NU_VERSION, 'to' => $r['to'], 'by' => $by, 'backup' => $backup], ['id' => '', 'login' => $by]);
			return $r;
		} catch (Throwable $e) {
			$r['errors'][] = $e->getMessage();
			$r['ok'] = false;
			return $r;
		} finally {
			if ($tmp !== null) @unlink($tmp);
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** Schema keys to apply: none, missing columns only, safe (= the page's preselection: missing + larger types), all. */
	public static function schemaKeys(array $cmp, string $mode): array
	{
		return match ($mode) {
			'missing' => array_column($cmp['missing'], 'key'),
			'safe' => array_merge(array_column($cmp['missing'], 'key'), array_column(array_filter($cmp['changed'], static fn($c) => $c['safe']), 'key')),
			'all' => array_merge(array_column($cmp['missing'], 'key'), array_column($cmp['changed'], 'key')),
			default => [],
		};
	}

	/** New / changed / unchanged / removed files – as the page shows them. */
	private static function diff(array $info): array
	{
		$cur = NuUpdate::installedManifest();
		$d = ['new' => 0, 'changed' => 0, 'same' => 0, 'removed' => 0];
		foreach ($info['manifest']['files'] as $rel => $f) {
			$path = NU_ROOT . '/' . $rel;
			if (!is_file($path)) $d['new']++;
			elseif (hash_file('sha256', $path) === $f['sha256']) $d['same']++;
			else $d['changed']++;
		}
		foreach (array_unique(array_merge($cur ? array_keys($cur['files']) : [], (array)($info['manifest']['obsolete'] ?? []))) as $rel) {
			if (!isset($info['manifest']['files'][$rel]) && is_file(NU_ROOT . '/' . $rel) && !NuUpdate::isProtected((string)$rel)
				&& !NuUpdate::isLibrary((string)$rel)) $d['removed']++;
		}
		return $d;
	}

	/** Downloads the package to a temporary file (size limit, https, no redirects to http). */
	private static function download(string $url): string
	{
		$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/nunext-download-' . bin2hex(random_bytes(6)) . '.zip';
		if (function_exists('curl_init')) {
			$fh = fopen($tmp, 'wb');
			$ch = curl_init($url);
			$size = 0;
			curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 300,
				CURLOPT_FAILONERROR => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_USERAGENT => 'nuBuilder Next ' . NU_VERSION, CURLOPT_NOPROGRESS => false,
				nu_curl_progress_opt() => static function ($c, $dlTotal, $dl) use (&$size): int { $size = $dl; return $dl > self::MAX_DOWNLOAD ? 1 : 0; }]);
			$ok = curl_exec($ch);
			$err = curl_error($ch);
			curl_close($ch);
			fclose($fh);
			if (!$ok) { @unlink($tmp); throw new RuntimeException('Download failed: ' . ($size > self::MAX_DOWNLOAD ? 'larger than 64 MB' : $err)); }
			return $tmp;
		}
		$ctx = stream_context_create(['http' => ['timeout' => 300, 'follow_location' => 1, 'user_agent' => 'nuBuilder Next ' . NU_VERSION], 'ssl' => ['verify_peer' => true]]);
		$in = @fopen($url, 'rb', false, $ctx);
		if (!$in) throw new RuntimeException('Download failed: ' . $url);
		$out = fopen($tmp, 'wb');
		$n = 0;
		while (!feof($in)) {
			$chunk = (string)fread($in, 65536);
			$n += strlen($chunk);
			if ($n > self::MAX_DOWNLOAD) { fclose($in); fclose($out); @unlink($tmp); throw new RuntimeException('Download failed: larger than 64 MB'); }
			fwrite($out, $chunk);
		}
		fclose($in);
		fclose($out);
		return $tmp;
	}

	/** @return resource|false */
	private static function lock()
	{
		$f = @fopen(NuUpdate::backupDir() . '/update.lock', 'c');
		if (!$f) return false;
		if (!flock($f, LOCK_EX | LOCK_NB)) { fclose($f); return false; }
		return $f;
	}

	// ------------------------------------------------------------------ phase 2: finish with the new code

	/** Runs the database steps (and the sample refresh) of an installed job. Must run in a new PHP process after run(). */
	public static function finish(string $job): array
	{
		$j = self::job($job);
		if (!$j) return ['ok' => false, 'status' => 'unknown', 'errors' => ['Unknown job.']];
		if ($j['status'] !== 'installed') return $j + ['ok' => $j['status'] === 'done'];
		if ((string)$j['to'] !== NU_VERSION) {                                // still the old code in this process
			return $j + ['ok' => true, 'pending' => 'The database steps run in the next request (new code).'];
		}
		$lock = self::lock();
		if (!$lock) return $j + ['ok' => true, 'pending' => 'Another update step is running.'];
		try {
			$j = self::job($job) ?? $j;                                         // finished meanwhile by another process?
			if ($j['status'] !== 'installed') return $j + ['ok' => $j['status'] === 'done'];
			try {
				unset($_SESSION['nu_schema']);
				$j['steps'] = NuMigrate::run();
				if (($hookErr = NuUpdate::afterUpdateHook()) !== '') $j['hook_error'] = $hookErr;   // nu_after_update (0.8.462)
				if (!empty($j['sample_refresh'])) {
					try {
						NuSample::install();                                     // new passwords: Admin tools → Sample data → New passwords
						$j['sample'] = NuSample::VERSION;
					} catch (Throwable $e) {
						$j['sample_error'] = $e->getMessage();
					}
				}
				$j['status'] = 'done';
			} catch (Throwable $e) {
				$j['status'] = 'failed';
				$j['errors'] = ['Database steps: ' . $e->getMessage()];
			}
			NuUpdate::maintenanceOff();
			$j['finished'] = date('Y-m-d H:i:s');
			self::saveJob($job, $j);
			NuLog::write('update_auto_done', '', $job, ['to' => $j['to'], 'status' => $j['status'], 'steps' => $j['steps'] ?? [], 'sample' => $j['sample'] ?? null], ['id' => '', 'login' => (string)$j['by']]);
			return $j + ['ok' => $j['status'] === 'done'];
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** Finishes every job that is installed but not finished (cron.php, the update page, the API). @return list of job ids finished */
	public static function finishPending(): array
	{
		$done = [];
		foreach (self::jobs() as $j) {
			if (($j['status'] ?? '') === 'installed' && (string)$j['to'] === NU_VERSION) { self::finish((string)$j['job']); $done[] = (string)$j['job']; }
		}
		return $done;
	}

	// ------------------------------------------------------------------ jobs

	private static function jobFile(string $job): string
	{
		return NuUpdate::backupDir() . '/update-job-' . $job . '.json';
	}

	public static function job(string $job): ?array
	{
		if (!preg_match(self::JOB_RE, $job) || !is_file(self::jobFile($job))) return null;
		$d = json_decode((string)file_get_contents(self::jobFile($job)), true);
		return is_array($d) ? $d : null;
	}

	private static function saveJob(string $job, array $data): void
	{
		file_put_contents(self::jobFile($job), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
	}

	/** Jobs, newest first (at most 20 files are kept). */
	public static function jobs(): array
	{
		$files = glob(NuUpdate::backupDir() . '/update-job-*.json') ?: [];
		usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
		foreach (array_slice($files, 20) as $old) @unlink($old);
		$out = [];
		foreach (array_slice($files, 0, 20) as $f) {
			$d = json_decode((string)file_get_contents($f), true);
			if (is_array($d)) $out[] = $d;
		}
		return $out;
	}
}
