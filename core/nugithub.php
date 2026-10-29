<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Updates from GitHub (0.8.374): the newest release of the repository (default nuBuilder/nuBuilder-Next) is looked up through
 * GitHub's REST API (public repository, no token), its asset nunext.zip is downloaded into the folder _update/ and installed from
 * there like any other package (update page → "Update from the folder"). The download is only kept when its signature is valid –
 * the Ed25519 signature, not GitHub, is what makes a package trusted.
 *   setting 'github_update' (JSON): repo, pre (pre-releases count, default on – nuBuilder Next is alpha), every (automatic check: never / day / week / month, default day; auto = every ≠ never, older rows only had auto –
 *   cron.php, or the start page / update page of a user with the update right; never in the page view of anybody else; default on), checked_at, latest {version, tag, name, url, size, published, page, pre} | null, error
 * Downloads only from https://github.com and GitHub's download hosts (HOSTS), 64 MB at most. Used by the update page, the start
 * page badge, cron.php update --github and POST api.php/v1/system/update {"github": true}.
 */
final class NuGitHub
{
	public const SETTING = 'github_update';
	public const DEFAULT_REPO = 'nuBuilder/nuBuilder-Next';
	public const ASSET = 'nunext.zip';
	private const API = 'https://api.github.com/repos/%s/releases?per_page=30';
	private const HOSTS = ['github.com', 'api.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com', 'github-releases.githubusercontent.com', 'codeload.github.com'];
	private const MAX_DOWNLOAD = 64 * 1048576;
	/** Automatic check (0.8.673; before: on / off = once a day): never, once a day, a week or a month – value = days. */
	public const EVERY = ['never' => 0, 'day' => 1, 'week' => 7, 'month' => 30];

	/** @return array{repo: string, pre: bool, auto: bool, every: string, checked_at: int, latest: ?array, error: string} */
	public static function settings(): array
	{
		$s = ['repo' => self::DEFAULT_REPO, 'pre' => true, 'auto' => true, 'every' => 'day', 'checked_at' => 0, 'latest' => null, 'error' => ''];
		try {
			NuSchema::ensure();
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$j = is_string($v) ? json_decode($v, true) : null;
			if (is_array($j)) {
				if (self::validRepo((string)($j['repo'] ?? ''))) $s['repo'] = (string)$j['repo'];
				if (isset($j['pre'])) $s['pre'] = (bool)$j['pre'];
				if (isset(self::EVERY[(string)($j['every'] ?? '')])) $s['every'] = (string)$j['every'];
				elseif (isset($j['auto'])) $s['every'] = $j['auto'] ? 'day' : 'never';            // stored before 0.8.673
				$s['auto'] = $s['every'] !== 'never';
				$s['checked_at'] = (int)($j['checked_at'] ?? 0);
				$s['latest'] = is_array($j['latest'] ?? null) ? $j['latest'] : null;
				$s['error'] = (string)($j['error'] ?? '');
			}
		} catch (Throwable) { /* defaults */ }
		return $s;
	}

	private static function save(array $s): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
			'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')), 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) {
			$db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
		} else {
			$db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
		}
	}

	public static function validRepo(string $repo): bool
	{
		return (bool)preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$#', $repo) && !str_contains($repo, '..');
	}

	/** Saves repository and switches; a changed repository forgets the last result. @return bool changed */
	public static function saveSettings(string $repo, bool $pre, string $every): bool
	{
		$repo = trim($repo);
		if (!self::validRepo($repo)) throw new RuntimeException(t('gh_err_repo'));
		$s = self::settings();
		if (!isset(self::EVERY[$every])) $every = $s['every'];                                     // not sent / unknown: unchanged
		$auto = $every !== 'never';
		$new = ['repo' => $repo, 'pre' => $pre, 'auto' => $auto, 'every' => $every] + $s;
		if (strcasecmp($repo, $s['repo']) !== 0 || $pre !== $s['pre']) { $new['latest'] = null; $new['checked_at'] = 0; $new['error'] = ''; }
		if ($new === $s) return false;
		self::save($new);
		NuLog::write('nunext_github_settings', 'zzzzsys_nunext_setting', self::SETTING, ['repo' => $repo, 'pre' => $pre, 'every' => $every]);
		return true;
	}

	/** The newest release (only when it is newer than the installed version). */
	public static function newer(): ?array
	{
		$l = self::settings()['latest'];
		return is_array($l) && version_compare((string)($l['version'] ?? '0'), NU_VERSION, '>') ? $l : null;
	}

	// ------------------------------------------------------------------ look up

	/**
	 * Asks GitHub for the releases and keeps the one with the highest version that has the asset nunext.zip (drafts never,
	 * pre-releases when the setting allows them). Errors are stored too (shown on the update page). @return array settings
	 */
	public static function check(): array
	{
		$s = self::settings();
		$s['checked_at'] = time();
		try {
			$json = self::get(sprintf(self::API, $s['repo']), 1048576, 15);
			$list = json_decode($json, true);
			if (!is_array($list) || !array_is_list($list)) throw new RuntimeException(t('gh_err_answer'));
			$s['latest'] = self::pick($list, $s['pre']);
			$s['error'] = '';
		} catch (Throwable $e) {
			$s['error'] = mb_substr($e->getMessage(), 0, 300);
		}
		self::save($s);
		return $s;
	}

	/** The best release of GitHub's answer (public for the unit test). */
	public static function pick(array $list, bool $pre): ?array
	{
		$best = null;
		foreach ($list as $r) {
			if (!is_array($r) || !empty($r['draft']) || (!$pre && !empty($r['prerelease']))) continue;
			if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', (string)($r['tag_name'] ?? ''), $m)) continue;
			$asset = null;
			foreach ((array)($r['assets'] ?? []) as $a) if (is_array($a) && ($a['name'] ?? '') === self::ASSET) $asset = $a;
			$url = (string)($asset['browser_download_url'] ?? '');
			if (!$asset || !self::hostAllowed($url)) continue;
			if ($best !== null && version_compare($m[1], $best['version'], '<=')) continue;
			$best = ['version' => $m[1], 'tag' => (string)$r['tag_name'], 'name' => mb_substr((string)($r['name'] ?? ''), 0, 200), 'url' => $url,
				'size' => (int)($asset['size'] ?? 0), 'published' => (string)($r['published_at'] ?? ''), 'pre' => !empty($r['prerelease']),
				'page' => self::hostAllowed((string)($r['html_url'] ?? '')) ? (string)$r['html_url'] : ''];
		}
		return $best;
	}

	/** Looks again when the automatic check is on and the last look is older than its interval (housekeeping). @return int 1 = looked */
	public static function autoCheck(): int
	{
		$s = self::settings();
		$days = self::EVERY[$s['every']] ?? 1;
		if (!$days || $s['checked_at'] > time() - $days * 86400) return 0;
		self::check();
		try { NuLibs::ghCheck($s['repo']); } catch (Throwable) { /* shown on the update page */ }    // library packs (0.8.405)
		return 1;
	}

	// ------------------------------------------------------------------ download

	/**
	 * Downloads the newest release into _update/ (file nunext-<version>.zip). The file is kept only when it is a nuBuilder Next
	 * package with a valid signature. @return string the file name in the folder
	 */
	public static function download(?array $rel = null): string
	{
		$rel ??= self::settings()['latest'];
		if (!is_array($rel) || empty($rel['url'])) throw new RuntimeException(t('gh_err_none'));
		if (!self::hostAllowed((string)$rel['url'])) throw new RuntimeException(t('gh_err_host', (string)parse_url((string)$rel['url'], PHP_URL_HOST)));
		$name = 'nunext-' . preg_replace('/[^0-9.]/', '', (string)$rel['version']) . '.zip';
		$dir = NuUpdate::folderDir();
		$tmp = $dir . '/.download-' . bin2hex(random_bytes(6)) . '.part';
		nu_time_limit(0);
		try {
			self::get((string)$rel['url'], self::MAX_DOWNLOAD, 300, $tmp);
			if (!empty($rel['size']) && (int)filesize($tmp) !== (int)$rel['size']) throw new RuntimeException(t('gh_err_size'));
			if (!@rename($tmp, $dir . '/' . $name)) throw new RuntimeException(t('gh_err_write'));
		} finally {
			if (is_file($tmp)) @unlink($tmp);
		}
		$p = array_values(array_filter(NuUpdate::folderPackages(), static fn($x) => $x['name'] === $name))[0] ?? null;
		// the reason (0.8.645 – before: always "no valid signature"); a package whose signature PHP cannot check (no sodium) is kept,
		// as for an uploaded one – Check package shows the warning
		$why = !class_exists('ZipArchive') ? 'gh_err_nozip' : (!$p || $p['version'] === '' ? 'gh_err_notpkg'
			: ($p['signature'] === 'unchecked' ? '' : ($p['signature'] === 'unsigned' ? 'gh_err_unsigned' : ($p['ok'] ? '' : 'gh_err_signature'))));
		if ($why !== '') {
			@unlink($dir . '/' . $name);
			throw new RuntimeException(t($why));
		}
		NuLog::write('nunext_github_download', '', '', ['version' => $p['version'], 'file' => $name, 'url' => (string)$rel['url']]);
		return $name;
	}

	public static function hostAllowed(string $url): bool
	{
		$p = parse_url($url);
		return is_array($p) && strtolower((string)($p['scheme'] ?? '')) === 'https' && in_array(strtolower((string)($p['host'] ?? '')), self::HOSTS, true)
			&& !isset($p['user']) && !isset($p['port']);
	}

	/**
	 * GET over https with GitHub's headers (also used by NuLibs – library packs, 0.8.405); redirects are followed only to the allowed hosts. Without $file the body is returned.
	 * curl when available, otherwise PHP's stream wrapper (redirects followed by hand).
	 */
	public static function get(string $url, int $max, int $timeout, ?string $file = null, bool $raw = false): string
	{
		$headers = ['Accept: ' . ($file || $raw ? 'application/octet-stream' : 'application/vnd.github+json'), 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: nuBuilder-Next/' . NU_VERSION];
		for ($hop = 0; $hop < 6; $hop++) {
			if (!self::hostAllowed($url)) throw new RuntimeException(t('gh_err_host', (string)parse_url($url, PHP_URL_HOST)));
			[$status, $location, $body] = function_exists('curl_init') ? self::curl($url, $headers, $max, $timeout, $file) : self::stream($url, $headers, $max, $timeout, $file);
			if ($status >= 300 && $status < 400 && $location !== '') { $url = $location; continue; }
			if ($status === 404) throw new RuntimeException(t('gh_err_404'));
			if ($status === 403 || $status === 429) throw new RuntimeException(t('gh_err_limit'));
			if ($status < 200 || $status >= 300) throw new RuntimeException(t('gh_err_http', $status));
			return $body;
		}
		throw new RuntimeException(t('gh_err_http', 310));
	}

	/** @return array{0: int, 1: string, 2: string} status, Location, body ('' when written to $file) */
	private static function curl(string $url, array $headers, int $max, int $timeout, ?string $file): array
	{
		$ch = curl_init($url);
		$fh = $file ? fopen($file, 'wb') : null;
		$location = '';
		$size = 0;
		$opt = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_NOPROGRESS => false,
			nu_curl_progress_opt() => static function ($c, $total, $now) use (&$size, $max): int { $size = $now; return $now > $max ? 1 : 0; },
			CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$location): int { if (stripos($h, 'location:') === 0) $location = trim(substr($h, 9)); return strlen($h); }];
		if ($fh) $opt[CURLOPT_FILE] = $fh; else $opt[CURLOPT_RETURNTRANSFER] = true;
		curl_setopt_array($ch, $opt);
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($fh) fclose($fh);
		if ($body === false) throw new RuntimeException($size > $max ? t('gh_err_big') : t('gh_err_connect', $err));
		if ($status >= 300 && $status < 400 && $location !== '') $location = self::absolute($url, $location);
		return [$status, $location, $fh ? '' : (string)$body];
	}

	/** @return array{0: int, 1: string, 2: string} */
	private static function stream(string $url, array $headers, int $max, int $timeout, ?string $file): array
	{
		$ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'follow_location' => 0,
			'ignore_errors' => true], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
		$in = @fopen($url, 'rb', false, $ctx);
		if (!$in) throw new RuntimeException(t('gh_err_connect', (string)(error_get_last()['message'] ?? '')));
		$meta = stream_get_meta_data($in);
		$status = 0;
		$location = '';
		foreach ((array)($meta['wrapper_data'] ?? []) as $h) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $m)) $status = (int)$m[1];
			elseif (stripos((string)$h, 'location:') === 0) $location = trim(substr((string)$h, 9));
		}
		if ($status >= 300 && $status < 400) { fclose($in); return [$status, $location !== '' ? self::absolute($url, $location) : '', '']; }
		$out = $file ? fopen($file, 'wb') : null;
		$body = '';
		$n = 0;
		while (!feof($in)) {
			$chunk = (string)fread($in, 65536);
			$n += strlen($chunk);
			if ($n > $max) { fclose($in); if ($out) fclose($out); throw new RuntimeException(t('gh_err_big')); }
			if ($out) fwrite($out, $chunk); else $body .= $chunk;
		}
		fclose($in);
		if ($out) fclose($out);
		return [$status, '', $body];
	}

	private static function absolute(string $base, string $loc): string
	{
		if (preg_match('#^https?://#i', $loc)) return $loc;
		$p = parse_url($base);
		return 'https://' . ($p['host'] ?? '') . (str_starts_with($loc, '/') ? $loc : '/' . $loc);
	}
}
