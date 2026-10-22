<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Reports through the REST API (0.8.340) – core/nuapi.php routes, scope reports:run:
 *   GET  /v1/reports                     reports the service user may run, with the hash variables they use
 *   POST /v1/reports/{id|code}/run       {params, filename, expires_minutes, max_downloads, url, email, async}
 *                                        → 201 {url, expires_at, max_downloads, size, sha256, emailed …}
 *                                        Accept: application/pdf → the PDF itself; async → 202 {job}
 *   GET  /v1/reports/jobs/{job}          status of an async run (runs a queued job when the server could not run it in the background)
 * Download links: index.php?p=reportfile&t=<token> (no login – a random token, only its hash is stored in zzzzsys_nunext_token,
 * kind 'report_file'), valid expires_minutes (1–1440, default 15) for max_downloads downloads (1–100, default 1). The PDF waits in the
 * protected temp folder and is deleted after the last download / by the housekeeping. Rights: the scope AND the report right of the
 * service user's access level (NuReport::canRun). Data-source procedures run as in the browser.
 */
final class NuReportApi
{
	public const KIND = 'report_file';
	public const RUNS_PER_MINUTE = 10;           // per client, on top of the client's request limit
	private const JOB_RE = '/^[a-f0-9]{16}$/';
	private const FILE_RE = '/^[a-f0-9]{32}$/';

	// ================================================================= reports

	/** Reports the current (service) user may run. */
	public static function list(): array
	{
		if (!NuReport::available()) return [];
		$out = [];
		foreach (nu_db()->all('SELECT * FROM zzzzsys_report ORDER BY sre_code') as $r) {
			if (!NuReport::canRun($r)) continue;
			$out[] = ['id' => (string)$r['zzzzsys_report_id'], 'code' => (string)$r['sre_code'], 'description' => (string)$r['sre_description'],
				'source' => NuReport::sourceLabel((string)$r['sre_zzzzsys_php_id']), 'params' => self::params($r)];
		}
		return $out;
	}

	/** Hash variables (#name#) the report's data source and layout use, without nuBuilder's system variables. */
	public static function params(array $r): array
	{
		$text = (string)$r['sre_layout'];
		[$kind, $id] = array_pad(explode(':', (string)$r['sre_zzzzsys_php_id'], 2), 2, '');
		$db = nu_db();
		try {
			if ($kind === 'PROCEDURE') $text .= (string)$db->value('SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$id]);
			if ($kind === 'SQL' && $db->tableExists('zzzzsys_select')) $text .= (string)$db->value('SELECT sse_sql FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$id]);
		} catch (Throwable) {
		}
		preg_match_all('/#([A-Za-z_][\w]{0,63})#/', $text, $m);
		$system = array_change_key_case(NuReport::runHash($r, NuHash::plain()), CASE_LOWER);
		$out = [];
		foreach (array_unique($m[1]) as $p) if (!isset($system[strtolower($p)]) && !in_array(strtolower($p), ['table_id', 'user_id', 'record_id', 'form_id', 'page', 'pages', 'description', 'code', 'year', 'month', 'day', 'hour', 'hour24', 'minute', 'second'], true)) $out[] = $p;
		sort($out);
		return $out;
	}

	/** nuBuilder's hash of a run without a form (user, date, TABLE_ID) plus the request's params. Throws RuntimeException on bad params. */
	public static function hash(mixed $params): array
	{
		if ($params !== null && !is_array($params)) throw new RuntimeException('params must be an object');
		$params = (array)$params;
		if (count($params) > 200) throw new RuntimeException('at most 200 params');
		$clean = [];
		foreach ($params as $k => $v) {
			if (!is_string($k) || !preg_match('/^[A-Za-z_][\w]{0,63}$/', $k)) throw new RuntimeException("invalid name '$k'");
			if ($v !== null && !is_scalar($v)) throw new RuntimeException("'$k' must be a string, number or boolean");
			$v = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
			if (strlen($v) > 10000) throw new RuntimeException("'$k' is too long");
			$clean[$k] = $v;
		}
		// params may set what a form would set (RECORD_ID, FORM_ID, own variables), never who runs it: user, access level,
		// session and TABLE_ID stay the service user's (a procedure may filter by #USER_ID# / #ACCESS_LEVEL_CODE#)
		$fixed = NuHash::base() + ['TABLE_ID' => ''];
		foreach ($clean as $k => $v) if (array_key_exists(strtoupper($k), $fixed) || array_key_exists($k, $fixed)) throw new RuntimeException("'$k' is set by nuBuilder Next and cannot be passed");
		return $clean + NuHash::plain();
	}

	/** Runs the report. @return string the PDF; throws RuntimeException with a message for the client. */
	public static function render(array $r, array $hash): string
	{
		nu_time_limit(300);
		try {
			$pdf = NuReport::pdf($r, $hash);
		} catch (Throwable $e) {
			error_log('nuBuilder Next API report ' . $r['sre_code'] . ': ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
			throw new RuntimeException(t('rpt_err_run', (string)$r['sre_code']) . (nu_cfg('nuConfigDebug') ? ' ' . $e->getMessage() : ''));
		}
		NuLog::write('report_run', NuReport::TABLE, (string)$r['zzzzsys_report_id'], ['code' => (string)$r['sre_code'], 'bytes' => strlen($pdf), 'via' => 'api']);
		return $pdf;
	}

	/** Report runs of this user in the last minute (rate limit). */
	public static function runsLastMinute(string $userId): int
	{
		return (int)nu_db()->value("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'report_run' AND user_id = ? AND log_time > ?",
			[$userId, date('Y-m-d H:i:s', time() - 60)]);
	}

	public static function fileName(string $name, array $r): string
	{
		$name = trim(basename(str_replace('\\', '/', $name)));
		if ($name === '') $name = ((string)$r['sre_code'] ?: 'report') . '.pdf';
		$name = (string)preg_replace('/[^\w.\- ]+/u', '_', $name);
		if (!str_ends_with(strtolower($name), '.pdf')) $name .= '.pdf';
		return mb_substr($name, -80);
	}

	/** Options of a run from the request body; throws RuntimeException (field => message as JSON) on errors. */
	public static function options(array $in): array
	{
		$err = [];
		$min = array_key_exists('expires_minutes', $in) ? $in['expires_minutes'] : 15;
		if (!is_int($min) || $min < 1 || $min > 1440) $err['expires_minutes'] = '1 … 1440';
		$max = array_key_exists('max_downloads', $in) ? $in['max_downloads'] : 1;
		if (!is_int($max) || $max < 1 || $max > 100) $err['max_downloads'] = '1 … 100';
		$email = $in['email'] ?? null;
		if ($email !== null) {
			if (!is_array($email) || trim((string)($email['to'] ?? '')) === '') $err['email'] = '{"to": "…", "cc", "bcc", "subject", "body"}';
			elseif ($bad = array_diff(array_keys($email), ['to', 'cc', 'bcc', 'subject', 'body'])) $err['email'] = 'unknown: ' . implode(', ', $bad);
		}
		$url = array_key_exists('url', $in) ? (bool)$in['url'] : true;
		if (!$url && $email === null) $err['url'] = 'url false needs email';
		if ($err) throw new RuntimeException(json_encode($err, JSON_UNESCAPED_UNICODE));
		return ['minutes' => (int)$min, 'max' => (int)$max, 'email' => $email, 'url' => $url, 'async' => !empty($in['async']), 'filename' => (string)($in['filename'] ?? '')];
	}

	/** The run after the PDF exists: download link and / or e-mail. @return array the API answer */
	public static function deliver(array $r, string $pdf, array $o, string $clientRef): array
	{
		$name = self::fileName($o['filename'], $r);
		$res = ['report' => (string)$r['sre_code'], 'filename' => $name, 'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf)];
		if ($o['url']) $res += self::store($pdf, $name, $o['minutes'], $o['max'], $r, $clientRef);
		if ($o['email'] !== null) {
			[$ok, $msg] = self::mail($r, $pdf, $name, $o['email']);
			$res['emailed'] = $ok;
			if (!$ok) $res['email_error'] = $msg;
		}
		return $res;
	}

	// ================================================================= files and download links

	private static function dir(): string
	{
		return NuReport::tempDir();
	}

	/** Stores the PDF and makes the download link. */
	public static function store(string $pdf, string $name, int $minutes, int $max, array $r, string $clientRef): array
	{
		$file = bin2hex(random_bytes(16));
		if (@file_put_contents(self::dir() . '/api-report-' . $file . '.pdf', $pdf, LOCK_EX) === false) throw new RuntimeException('The PDF could not be stored (temp folder not writable).');
		$data = json_encode(['f' => $file, 'n' => $name, 'm' => $max, 'r' => mb_substr((string)$r['sre_code'], 0, 40), 'i' => (string)$r['zzzzsys_report_id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$token = NuToken::create(self::KIND, '', $minutes * 60, bin2hex(random_bytes(24)), (string)$data);
		$exp = time() + $minutes * 60;
		return ['url' => nu_base_url() . '/index.php?' . http_build_query(['p' => 'reportfile', 't' => $token]),
			'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp), 'max_downloads' => $max];
	}

	/** index.php?p=reportfile&t=… – sends the PDF (no login). Never returns. */
	public static function download(string $token): never
	{
		header('Referrer-Policy: no-referrer');
		header('X-Robots-Tag: noindex, nofollow');
		$row = preg_match('/^[a-f0-9]{48}$/', $token) ? NuToken::find(self::KIND, '', $token) : null;
		$d = $row ? json_decode((string)$row['data'], true) : null;
		$path = is_array($d) && preg_match(self::FILE_RE, (string)($d['f'] ?? '')) ? self::dir() . '/api-report-' . $d['f'] . '.pdf' : '';
		if (!$row || $path === '' || !is_file($path)) {
			if ($row) NuToken::delete((string)$row['zzzzsys_nunext_token_id']);
			NuReport::fail(t('rptapi_link_gone'), 410);
		}
		$max = max(1, (int)($d['m'] ?? 1));
		// count the download first, in one statement: of two simultaneous requests for the last download only one wins
		$won = nu_db()->run('UPDATE zzzzsys_nunext_token SET attempts = attempts + 1 WHERE zzzzsys_nunext_token_id = ? AND attempts < ?',
			[(string)$row['zzzzsys_nunext_token_id'], $max])->rowCount() === 1;
		if (!$won) NuReport::fail(t('rptapi_link_gone'), 410);
		$n = (int)$row['attempts'] + 1;
		$pdf = (string)file_get_contents($path);
		if ($n >= $max) {
			NuToken::delete((string)$row['zzzzsys_nunext_token_id']);
			@unlink($path);
		}
		NuLog::write('report_downloaded', NuReport::TABLE, (string)($d['i'] ?? ''), ['code' => (string)($d['r'] ?? ''), 'download' => $n . '/' . $max], ['id' => '', 'login' => '']);
		NuReport::send($pdf, (string)preg_replace('/\.pdf$/i', '', (string)$d['n']), ($_GET['dl'] ?? '') === '1');
	}

	/** Housekeeping: PDFs whose link is gone (expired, used up). @return int deleted files */
	public static function purge(): int
	{
		$n = 0;
		$live = [];
		try {
			foreach (nu_db()->all('SELECT data FROM zzzzsys_nunext_token WHERE kind = ?', [self::KIND]) as $t) {
				$d = json_decode((string)$t['data'], true);
				if (is_array($d)) $live[(string)($d['f'] ?? '')] = true;
			}
		} catch (Throwable) {
			return 0;
		}
		foreach (glob(self::dir() . '/api-report-*.pdf') ?: [] as $f) {
			if (preg_match('/api-report-([a-f0-9]{32})\.pdf$/', $f, $m) && !isset($live[$m[1]]) && (int)@filemtime($f) < time() - 60 && @unlink($f)) $n++;
		}
		return $n;
	}

	// ================================================================= e-mail

	/** nuBuilder's nuEmailReportPDF: the PDF as attachment. @return array{0: bool, 1: string} */
	public static function mail(array $r, string $pdf, string $name, array $e): array
	{
		if (!NuMail::configured()) return [false, t('welcome_err_mail')];
		require_once NU_ROOT . '/core/form/nuphp_api.php';
		$res = nuSendEmail(['to' => (string)$e['to'], 'cc' => (string)($e['cc'] ?? ''), 'bcc' => (string)($e['bcc'] ?? ''),
			'subject' => trim((string)($e['subject'] ?? '')) !== '' ? (string)$e['subject'] : (string)$r['sre_description'],
			'body' => (string)($e['body'] ?? ''), 'html' => true, 'attachments' => [$name => ['data' => $pdf, 'type' => 'application/pdf']]]);
		$ok = !empty($res[0]);
		NuLog::write('report_emailed', NuReport::TABLE, (string)$r['zzzzsys_report_id'], ['code' => (string)$r['sre_code'], 'to' => (string)$e['to'], 'ok' => $ok, 'via' => 'api']);
		return [$ok, $ok ? '' : (string)($res[1] ?? 'not sent')];
	}

	// ================================================================= async jobs (temp/api-report-job-<id>.json)

	private static function jobFile(string $job): string
	{
		return self::dir() . '/api-report-job-' . $job . '.json';
	}

	public static function createJob(array $r, array $hash, array $o, string $clientRef): string
	{
		$job = bin2hex(random_bytes(8));
		self::saveJob($job, ['job' => $job, 'status' => 'queued', 'client' => $clientRef, 'report' => (string)$r['zzzzsys_report_id'], 'code' => (string)$r['sre_code'],
			'hash' => $hash, 'options' => $o, 'created_at' => gmdate('Y-m-d\TH:i:s\Z')]);
		return $job;
	}

	/** The job of this client, or null. */
	public static function job(string $job, string $clientRef): ?array
	{
		if (!preg_match(self::JOB_RE, $job) || !is_file(self::jobFile($job))) return null;
		$j = json_decode((string)file_get_contents(self::jobFile($job)), true);
		return is_array($j) && (string)($j['client'] ?? '') === $clientRef ? $j : null;
	}

	private static function saveJob(string $job, array $data): void
	{
		file_put_contents(self::jobFile($job), json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
	}

	/** Runs a queued job (once – a lock keeps a second process out). @return array the job afterwards */
	public static function runJob(string $job, string $clientRef): ?array
	{
		$j = self::job($job, $clientRef);
		if (!$j || $j['status'] !== 'queued') return $j;
		$lock = @fopen(self::jobFile($job) . '.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $j;                      // running in another process
		try {
			$j = self::job($job, $clientRef) ?? $j;
			if ($j['status'] !== 'queued') return $j;
			$j['status'] = 'running';
			$j['started_at'] = gmdate('Y-m-d\TH:i:s\Z');
			self::saveJob($job, $j);
			$r = NuReport::load((string)$j['report']);
			try {
				if (!$r || !NuReport::canRun($r)) throw new RuntimeException('The report is gone or may no longer be run.');
				$j['result'] = self::deliver($r, self::render($r, (array)$j['hash']), (array)$j['options'], $clientRef);
				$j['status'] = 'done';
			} catch (RuntimeException $e) {
				$j['status'] = 'failed';
				$j['error'] = $e->getMessage();
			}
			$j['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
			unset($j['hash']);                                                            // params may be personal data
			self::saveJob($job, $j);
			return $j;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
			@unlink(self::jobFile($job) . '.lock');
		}
	}

	/** What the client sees of a job. */
	public static function jobView(array $j): array
	{
		return array_intersect_key($j, array_flip(['job', 'status', 'code', 'created_at', 'started_at', 'finished_at', 'result', 'error']));
	}
}
