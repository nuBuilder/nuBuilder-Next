<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Library packs (0.8.405): the six libraries nuBuilder Next does not ship in its package – TCPDF, PHPMailer, TinyMCE, phpMyAdmin
 * (nuBuilder's "nudb" with its config), Adminer, Tiny File Manager – come as signed zips of their own.
 *
 *   Pack       nunext-lib-<slot>-<version>.zip: nunext-lib.json at the root (format nunext-lib-1, name = slot, version, title,
 *              built, files {rel: {sha256, size}}, signature) + the library below lib/. Signature: Ed25519 (the key of
 *              nunext.zip, NuUpdate::SIGN_PUBLIC) over payload() = "nunext-lib|<slot>|<version>|sha256(sorted 'rel sha256 size' lines)".
 *   Folders    vendor/packs/<dir>-<version>/ (0.8.409; dir = SLOTS[..]['dir'], e.g. nudb-5.2.3) – versions side by side; the active
 *              one is in vendor/packs/nunext-libs.php ("<?php exit; ?>" + JSON: slot => {active, prev, source, at, by}). vendor/packs/
 *              itself is deny-all (.htaccess written on first use); the browser packs (TinyMCE, phpMyAdmin, Tiny File Manager) get an
 *              allow .htaccess, the others (TCPDF, PHPMailer, Adminer) a deny-all one. The active version plus KEEP older ones stay
 *              (roll back). An update of nuBuilder Next never touches vendor/packs/ (NuUpdate::isProtected).
 *              Packs of 0.8.405–0.8.408 stay where they are (third_party/<dir>-<version>/, third_party/nunext-libs.php) and are read
 *              as an old place for a slot that has no pack in vendor/packs/ yet – until the upgrade baseline moves.
 *   Sources    zips in _update/ (and _install/ while installing), an upload on the update page, the GitHub release with the
 *              tag "libraries" of the update repository (asset libraries.json = signed index {format nunext-libs-1, built,
 *              libs [{name, version, title, file, size, sha256}], signature over "nunext-libs|sha256(json of libs)"} + the zips).
 *              A pack is only installed with a valid signature – wherever it comes from.
 *   Lookup     NuReport::tcpdfDir, NuMailer::phpMailerCandidates, NuLibs::tinymce(), NuVendor::dir – the active pack first, the
 *              old places (packs in third_party/, hand copies in third_party/<dir>/, assets/vendor/tinymce, lib/PHPMailer, nuBuilder's
 *              copies, $nuConfig…Path) only while none is installed.
 *   Building   developer mode: page ?p=libpack (globeadmin, password again, key uploaded per request – never stored) or
 *              php dev/libpack.php (CLI). Both use build() / buildIndex().
 */
final class NuLibs
{
	public const FORMAT = 'nunext-lib-1';
	public const INDEX_FORMAT = 'nunext-libs-1';
	public const MANIFEST = 'nunext-lib.json';
	public const INDEX = 'libraries.json';
	public const TAG = 'libraries';
	public const PACKS = 'vendor/packs';                 // = NU_PACKS (also used without core/nuapp.php: dev/libpack.php)
	public const STATE = self::PACKS . '/nunext-libs.php';
	public const LEGACY = 'third_party';                // packs of 0.8.405–0.8.408 (old place)
	/** .htaccess of a browser pack inside the deny-all vendor/packs/ */
	public const ALLOW = "# nuBuilder Next – library pack opened by the browser (vendor/packs/ itself is closed)\n<IfModule mod_authz_core.c>\n\tRequire all granted\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tAllow from all\n</IfModule>\n";
	public const SETTING = 'github_libs';
	public const REQUIRED_FILE = 'install/libraries-required.json';
	public const KEEP = 2;
	private const MAX_FILES = 30000;
	private const MAX_BYTES = 600 * 1048576;
	private const MAX_DOWNLOAD = 200 * 1048576;
	/** Never part of a pack. */
	private const SKIP = '~(^|/)(\.git|\.svn|\.hg|\.idea|\.vscode|node_modules|\.DS_Store|Thumbs\.db|nuconfig\.php|nunext-lib\.json|[^/]*\.log)(/|$)~i';

	/**
	 * slot => title, dir (folder name in vendor/packs/ without version), web (opened by the browser), entry (one of them must exist),
	 * rec (ticked in the installer), use (upd_libuse_* key), min (lowest safe version, '' = none).
	 */
	public const SLOTS = [
		'tcpdf'           => ['title' => 'TCPDF', 'dir' => 'tcpdf', 'web' => false, 'entry' => ['tcpdf.php', 'vendor/tecnickcom/tcpdf/tcpdf.php'], 'rec' => true, 'use' => 'pdf'],
		'phpmailer'       => ['title' => 'PHPMailer', 'dir' => 'phpmailer', 'web' => false, 'entry' => ['PHPMailer.php', 'src/PHPMailer.php'], 'rec' => true, 'use' => 'mail'],
		'tinymce'         => ['title' => 'TinyMCE', 'dir' => 'tinymce', 'web' => true, 'entry' => ['tinymce.min.js'], 'rec' => true, 'use' => 'wysiwyg'],
		'phpmyadmin'      => ['title' => 'phpMyAdmin', 'dir' => 'nudb', 'web' => true, 'entry' => ['index.php'], 'rec' => false, 'use' => 'db'],
		'adminer'         => ['title' => 'Adminer', 'dir' => 'adminer', 'web' => false, 'entry' => ['adminer.php', 'adminer-*.php'], 'rec' => false, 'use' => 'db'],
		// nuBuilder's Uppy (0.8.459): file objects with target "File System" upload with it (uppy.min.js / .css + locales/)
		'uppy'            => ['title' => 'Uppy', 'dir' => 'uppy', 'web' => true, 'entry' => ['uppy.min.js'], 'rec' => false, 'use' => 'upload'],
	];
	/** NuSecurity::libraries() name => slot */
	public const BY_NAME = ['TCPDF' => 'tcpdf', 'PHPMailer' => 'phpmailer', 'TinyMCE' => 'tinymce', 'phpMyAdmin' => 'phpmyadmin', 'Adminer' => 'adminer', 'Uppy' => 'uppy'];
	/** Slots that were removed (slot => folder name): their packs are deleted by retire() – Tiny File Manager since 0.8.479 (nuFiles). */
	public const RETIRED = ['tinyfilemanager' => 'tinyfilemanager'];

	private static ?array $state = null;
	private static ?array $legacy = null;
	private static array $inspected = [];
	/** Folder that holds vendor/packs/ – tests point it elsewhere (and switch the log off: no database there). */
	public static string $root = NU_ROOT;
	public static bool $log = true;

	// ================================================================== state

	public static function slot(string $slot): ?array
	{
		return self::SLOTS[$slot] ?? null;
	}

	public static function validVersion(string $v): bool
	{
		return (bool)preg_match('/^[0-9][0-9A-Za-z.+_-]{0,40}$/', $v) && !str_contains($v, '..');
	}

	/** Folder name part of a version (2.6+git.4149143.nu1 → 2.6_git.4149143.nu1). */
	public static function safeVersion(string $v): string
	{
		return (string)preg_replace('/[^0-9A-Za-z._-]/', '_', $v);
	}

	public static function baseDir(): string
	{
		return self::$root . '/' . self::PACKS;
	}

	/** Creates vendor/packs/ (deny-all .htaccess, empty index.html) – it is not in the package: an update never writes there. */
	private static function ensureBase(): string
	{
		$dir = self::baseDir();
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException(t('lp_err_write', self::PACKS . '/'));
		if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', NuSecurity::DENY);
		if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
		return $dir;
	}

	public static function folder(string $slot, string $version): string
	{
		return self::baseDir() . '/' . self::SLOTS[$slot]['dir'] . '-' . self::safeVersion($version);
	}

	/** slot => {active, prev, source, at, by} */
	public static function state(): array
	{
		return self::$state ??= self::readState(self::$root . '/' . self::STATE);
	}

	/** State of the packs of 0.8.405–0.8.408 in third_party/ (read only). */
	private static function legacyState(): array
	{
		return self::$legacy ??= self::readState(self::$root . '/' . self::LEGACY . '/nunext-libs.php');
	}

	private static function readState(string $file): array
	{
		$raw = (string)@file_get_contents($file);
		$raw = (string)preg_replace('/^<\?php[^\n]*\n/', '', $raw);
		$j = json_decode($raw, true);
		$out = [];
		foreach (is_array($j) ? $j : [] as $slot => $s) {
			if (!isset(self::SLOTS[$slot]) || !is_array($s) || !self::validVersion((string)($s['active'] ?? ''))) continue;
			$out[$slot] = ['active' => (string)$s['active'], 'prev' => self::validVersion((string)($s['prev'] ?? '')) ? (string)$s['prev'] : '',
				'source' => (string)($s['source'] ?? ''), 'at' => (string)($s['at'] ?? ''), 'by' => (string)($s['by'] ?? '')];
		}
		return $out;
	}

	private static function saveState(array $s): void
	{
		self::ensureBase();
		$file = self::$root . '/' . self::STATE;
		$tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
		$data = "<?php exit; ?>\n" . json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (@file_put_contents($tmp, $data) === false || !@rename($tmp, $file)) {
			@unlink($tmp);
			throw new RuntimeException(t('lp_err_write', self::STATE));
		}
		self::$state = null;
		if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
	}

	/** Active pack in vendor/packs/: [folder, version], null = none (or its folder is gone). */
	private static function current(string $slot): ?array
	{
		$v = self::state()[$slot]['active'] ?? '';
		if ($v === '' || !isset(self::SLOTS[$slot])) return null;
		$d = self::folder($slot, $v);
		return self::hasEntry($slot, $d) ? [$d, $v] : null;
	}

	/** Pack of 0.8.405–0.8.408 in third_party/ – only for a slot without an entry in vendor/packs/nunext-libs.php. */
	private static function legacy(string $slot): ?array
	{
		if (!isset(self::SLOTS[$slot]) || isset(self::state()[$slot])) return null;
		$v = self::legacyState()[$slot]['active'] ?? '';
		if ($v === '') return null;
		$d = self::$root . '/' . self::LEGACY . '/' . self::SLOTS[$slot]['dir'] . '-' . self::safeVersion($v);
		return self::hasEntry($slot, $d) ? [$d, $v] : null;
	}

	/** Absolute folder of the pack in use for $slot (vendor/packs/, else an old pack in third_party/), null = none. */
	public static function activeDir(string $slot): ?string
	{
		return (self::current($slot) ?? self::legacy($slot))[0] ?? null;
	}

	public static function activeVersion(string $slot): string
	{
		return (string)((self::current($slot) ?? self::legacy($slot))[1] ?? '');
	}

	/** Is the pack in use an old one in third_party/ (installed by 0.8.405–0.8.408)? */
	public static function isLegacy(string $slot): bool
	{
		return self::current($slot) === null && self::legacy($slot) !== null;
	}

	/** URL path of the pack in use relative to nuBuilder Next ('vendor/packs/nudb-5.2.3'), '' = none. */
	public static function webPath(string $slot): string
	{
		$d = self::activeDir($slot);
		return $d === null ? '' : substr(str_replace('\\', '/', $d), strlen(str_replace('\\', '/', self::$root)) + 1);
	}

	public static function hasEntry(string $slot, string $dir): bool
	{
		if ($dir === '' || !is_dir($dir)) return false;
		foreach (self::SLOTS[$slot]['entry'] as $e) {
			if (str_contains($e, '*') ? (bool)glob($dir . '/' . $e) : is_file($dir . '/' . $e)) return true;
		}
		return false;
	}

	/** TinyMCE's folder for the browser (relative to nuBuilder Next): the pack, else assets/vendor/tinymce; '' = not installed. */
	public static function tinymce(): string
	{
		$w = self::webPath('tinymce');
		if ($w !== '') return $w;
		return is_file(NU_ROOT . '/' . NU_TINYMCE . '/tinymce.min.js') ? NU_TINYMCE : '';
	}

	/** Uppy's folder for the browser (relative to nuBuilder Next, 0.8.459): the library pack; '' = not installed. */
	public static function uppy(): string
	{
		return self::webPath('uppy');
	}

	/** Installed versions of $slot (folders with a pack manifest), newest first. @return list<array{version: string, dir: string, at: string, source: string}> */
	public static function installed(string $slot): array
	{
		$out = [];
		foreach (glob(self::baseDir() . '/' . self::SLOTS[$slot]['dir'] . '-*', GLOB_ONLYDIR) ?: [] as $d) {
			$m = json_decode((string)@file_get_contents($d . '/' . self::MANIFEST), true);
			if (!is_array($m) || ($m['name'] ?? '') !== $slot || !self::validVersion((string)($m['version'] ?? ''))) continue;
			if (self::folder($slot, (string)$m['version']) !== $d) continue;
			$out[] = ['version' => (string)$m['version'], 'dir' => $d, 'at' => (string)($m['installed_at'] ?? ''), 'source' => (string)($m['installed_from'] ?? '')];
		}
		usort($out, static fn($a, $b) => version_compare(self::numeric($b['version']), self::numeric($a['version'])) ?: strcmp($b['version'], $a['version']));
		return $out;
	}

	/** "2.6+git.4149143.nu1" → "2.6" for comparisons. */
	public static function numeric(string $v): string
	{
		return preg_match('/^[0-9]+(\.[0-9]+)*/', $v, $m) ? $m[0] : '0';
	}

	/** Is version $a newer than $b? Same number: the longer / later spelling (2.6+git…nu2 after nu1). */
	public static function newer(string $a, string $b): bool
	{
		$c = version_compare(self::numeric($a), self::numeric($b));
		return $c > 0 || ($c === 0 && strcmp($a, $b) > 0);
	}

	// ================================================================== packs

	/** Text the signature covers. */
	public static function payload(array $m): string
	{
		$files = (array)($m['files'] ?? []);
		ksort($files, SORT_STRING);
		$lines = [];
		foreach ($files as $rel => $f) $lines[] = $rel . ' ' . (string)($f['sha256'] ?? '') . ' ' . (int)($f['size'] ?? 0);
		return 'nunext-lib|' . (string)($m['name'] ?? '') . '|' . (string)($m['version'] ?? '') . '|' . hash('sha256', implode("\n", $lines));
	}

	/** 'valid' | 'invalid' | 'unsigned' | 'unchecked' (no libsodium). $key: base64 public key (tests), default NuUpdate::SIGN_PUBLIC. */
	public static function verify(string $payload, string $sig, ?string $key = null): string
	{
		if ($sig === '') return 'unsigned';
		if (!function_exists('sodium_crypto_sign_verify_detached')) return 'unchecked';
		$raw = base64_decode($sig, true);
		$pk = base64_decode($key ?? NuUpdate::SIGN_PUBLIC, true);
		if ($raw === false || $pk === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return 'invalid';
		return sodium_crypto_sign_verify_detached($raw, $payload, $pk) ? 'valid' : 'invalid';
	}

	public static function validRel(string $rel): bool
	{
		return $rel !== '' && strlen($rel) < 400 && !str_starts_with($rel, '/') && !str_contains($rel, '\\') && !str_contains($rel, ':') && !str_contains($rel, "\0")
			&& !in_array('..', explode('/', $rel), true) && !in_array('.', explode('/', $rel), true) && !str_contains($rel, '//');
	}

	public static function packName(string $slot, string $version): string
	{
		return 'nunext-lib-' . $slot . '-' . self::safeVersion($version) . '.zip';
	}

	public static function isPackName(string $name): bool
	{
		return (bool)preg_match('/^nunext-lib-[a-z]+-[0-9][0-9A-Za-z._-]{0,40}\.zip$/', $name);
	}

	/**
	 * Reads and checks a pack: manifest, slot, version, every zip entry below lib/ listed (and nothing else), sizes, signature.
	 * File contents are checked against their sha256 while installing. @return array{ok: bool, errors: list<string>, name: string,
	 * version: string, title: string, signature: string, files: int, bytes: int, built: string, manifest: array}
	 */
	public static function inspect(string $zip, ?string $key = null): array
	{
		$ck = $zip . '|' . @filesize($zip) . '|' . @filemtime($zip) . '|' . $key;
		if (isset(self::$inspected[$ck])) return self::$inspected[$ck];
		$out = ['ok' => false, 'errors' => [], 'name' => '', 'version' => '', 'title' => '', 'signature' => '', 'files' => 0, 'bytes' => 0, 'built' => '', 'manifest' => []];
		if (!class_exists('ZipArchive')) { $out['errors'][] = t('upd_req_zip'); return $out; }
		$z = new ZipArchive();
		if (!is_file($zip) || $z->open($zip) !== true) { $out['errors'][] = t('upd_err_zip'); return $out; }
		try {
			$m = json_decode((string)$z->getFromName(self::MANIFEST), true);
			if (!is_array($m) || ($m['format'] ?? '') !== self::FORMAT || !is_array($m['files'] ?? null)) { $out['errors'][] = t('lp_err_manifest'); return $out; }
			$slot = (string)($m['name'] ?? '');
			$ver = (string)($m['version'] ?? '');
			if (!isset(self::SLOTS[$slot])) { $out['errors'][] = t('lp_err_slot', $slot); return $out; }
			if (!self::validVersion($ver)) { $out['errors'][] = t('lp_err_version', $ver); return $out; }
			$out['name'] = $slot;
			$out['version'] = $ver;
			$out['title'] = self::SLOTS[$slot]['title'];
			$out['built'] = (string)($m['built'] ?? '');
			$out['manifest'] = $m;
			if (count($m['files']) > self::MAX_FILES) { $out['errors'][] = t('upd_err_size'); return $out; }
			$bytes = 0;
			foreach ($m['files'] as $rel => $f) {
				if (!self::validRel((string)$rel) || !is_array($f) || !preg_match('/^[a-f0-9]{64}$/', (string)($f['sha256'] ?? ''))) { $out['errors'][] = t('lp_err_file', (string)$rel); return $out; }
				$st = $z->statName('lib/' . $rel);
				if ($st === false || (int)$st['size'] !== (int)($f['size'] ?? -1)) { $out['errors'][] = t('lp_err_file', (string)$rel); return $out; }
				$bytes += (int)$f['size'];
			}
			if ($bytes > self::MAX_BYTES) { $out['errors'][] = t('upd_err_size'); return $out; }
			for ($i = 0; $i < $z->numFiles; $i++) {
				$n = (string)$z->getNameIndex($i);
				if ($n === self::MANIFEST || str_ends_with($n, '/')) continue;
				if (!str_starts_with($n, 'lib/') || !isset($m['files'][substr($n, 4)])) { $out['errors'][] = t('lp_err_extra', $n); return $out; }
			}
			$out['files'] = count($m['files']);
			$out['bytes'] = $bytes;
			$entryOk = false;
			foreach (self::SLOTS[$slot]['entry'] as $e) {
				foreach (array_keys($m['files']) as $rel) if (fnmatch($e, (string)$rel, FNM_PATHNAME)) { $entryOk = true; break 2; }
			}
			if (!$entryOk) { $out['errors'][] = t('lp_err_entry', implode(', ', self::SLOTS[$slot]['entry'])); return $out; }
			$out['signature'] = self::verify(self::payload($m), (string)($m['signature'] ?? ''), $key);
			if ($out['signature'] !== 'valid') { $out['errors'][] = t('lp_err_sig_' . $out['signature']); return $out; }
			$out['ok'] = true;
		} finally {
			$z->close();
		}
		return self::$inspected[$ck] = $out;
	}

	/**
	 * Installs a pack and makes it the active version. The files are written to a temporary folder first (each checked against its
	 * sha256), then that folder is renamed – a failed install leaves the installed versions as they were.
	 * @return array{slot: string, version: string, prev: string}
	 */
	public static function install(string $zip, string $source, ?string $key = null): array
	{
		$i = self::inspect($zip, $key);
		if (!$i['ok']) throw new RuntimeException(implode(' ', $i['errors']) ?: t('lp_err_manifest'));
		nu_time_limit(0);
		$slot = $i['name'];
		$ver = $i['version'];
		$base = self::ensureBase();
		$tmp = $base . '/.nunext-tmp-' . bin2hex(random_bytes(6));
		$z = new ZipArchive();
		if ($z->open($zip) !== true) throw new RuntimeException(t('upd_err_zip'));
		try {
			if (!@mkdir($tmp, 0755)) throw new RuntimeException(t('lp_err_write', self::PACKS . '/'));
			foreach ($i['manifest']['files'] as $rel => $f) {
				$dest = $tmp . '/' . $rel;
				if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) throw new RuntimeException(t('lp_err_write', (string)$rel));
				$in = $z->getStream('lib/' . $rel);
				$outF = @fopen($dest, 'wb');
				if ($in === false || $outF === false) throw new RuntimeException(t('lp_err_write', (string)$rel));
				$h = hash_init('sha256');
				$n = 0;
				while (!feof($in)) {
					$buf = (string)fread($in, 262144);
					if ($buf === '') { if (feof($in)) break; continue; }
					hash_update($h, $buf);
					$n += strlen($buf);
					if (fwrite($outF, $buf) !== strlen($buf)) { fclose($in); fclose($outF); throw new RuntimeException(t('lp_err_write', (string)$rel)); }
				}
				fclose($in);
				fclose($outF);
				if ($n !== (int)$f['size'] || !hash_equals((string)$f['sha256'], hash_final($h))) throw new RuntimeException(t('lp_err_hash', (string)$rel));
			}
			$m = $i['manifest'];
			$m['installed_at'] = date('Y-m-d H:i:s');
			$m['installed_from'] = mb_substr($source, 0, 200);
			file_put_contents($tmp . '/' . self::MANIFEST, json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			if (!self::SLOTS[$slot]['web']) {                       // TCPDF, PHPMailer, Adminer: never opened by the browser
				file_put_contents($tmp . '/.htaccess', NuSecurity::DENY);
				if (!is_file($tmp . '/index.html')) file_put_contents($tmp . '/index.html', '');
			} else {                                                 // TinyMCE, phpMyAdmin, Tiny File Manager: open inside the closed vendor/packs/
				$own = is_file($tmp . '/.htaccess') ? "\n" . (string)file_get_contents($tmp . '/.htaccess') : '';
				file_put_contents($tmp . '/.htaccess', self::ALLOW . $own);
			}
			$final = self::folder($slot, $ver);
			$old = '';
			if (is_dir($final)) {                                    // the same version again (repair): swap
				$old = $base . '/.nunext-old-' . bin2hex(random_bytes(6));
				if (!@rename($final, $old)) throw new RuntimeException(t('lp_err_write', basename($final)));
			}
			if (!@rename($tmp, $final)) {
				if ($old !== '') @rename($old, $final);
				throw new RuntimeException(t('lp_err_write', basename($final)));
			}
			if ($old !== '') self::rmTree($old);
		} catch (Throwable $e) {
			self::rmTree($tmp);
			throw $e;
		} finally {
			$z->close();
		}
		$prev = self::activate($slot, $ver, $source);
		self::prune($slot);
		if (self::$log) NuLog::write('nunext_lib_install', self::PACKS, $slot, ['version' => $ver, 'previous' => $prev, 'source' => mb_substr($source, 0, 200)]);
		return ['slot' => $slot, 'version' => $ver, 'prev' => $prev];
	}

	/** Makes an installed version the active one. @return string the version active before ('' = none) */
	public static function activate(string $slot, string $version, string $source = ''): string
	{
		if (!self::hasEntry($slot, self::folder($slot, $version))) throw new RuntimeException(t('lp_err_gone', self::SLOTS[$slot]['title'] . ' ' . $version));
		$s = self::state();
		$was = (string)(self::current($slot)[1] ?? '');          // an old pack in third_party/ is no roll back target
		$prev = $was !== '' && $was !== $version ? $was : (string)($s[$slot]['prev'] ?? '');
		if ($prev === $version) $prev = '';
		$s[$slot] = ['active' => $version, 'prev' => $prev, 'source' => $source !== '' ? $source : (string)($s[$slot]['source'] ?? ''), 'at' => date('Y-m-d H:i:s'),
			'by' => mb_substr((string)(NuAuth::user()['login'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'installer')), 0, 50)];
		self::saveState($s);
		return $was;
	}

	/** Back to the version that was active before. @return string that version */
	public static function rollback(string $slot): string
	{
		$prev = (string)(self::state()[$slot]['prev'] ?? '');
		if ($prev === '' || !self::hasEntry($slot, self::folder($slot, $prev))) throw new RuntimeException(t('lp_err_noprev', self::SLOTS[$slot]['title']));
		$from = self::activate($slot, $prev);
		if (self::$log) NuLog::write('nunext_lib_rollback', self::PACKS, $slot, ['version' => $prev, 'from' => $from]);
		return $prev;
	}

	/** Removes all versions of $slot (back to the old places, if any). */
	public static function remove(string $slot): void
	{
		$vers = array_column(self::installed($slot), 'version');
		$s = self::state();
		unset($s[$slot]);
		self::saveState($s);
		foreach ($vers as $v) self::rmTree(self::folder($slot, $v));
		if (self::$log) NuLog::write('nunext_lib_remove', self::PACKS, $slot, ['versions' => $vers]);
	}

	/**
	 * Deletes every pack of a RETIRED slot – vendor/packs/<dir>-*, third_party/<dir>-* (packs of 0.8.405–0.8.408) – and its entry in
	 * nunext-libs.php (readState() skips unknown slots, so saving the state drops it). @return list<string> removed folders
	 */
	public static function retire(string $slot): array
	{
		$dir = self::RETIRED[$slot] ?? null;
		if ($dir === null) return [];
		$out = [];
		foreach ([self::baseDir(), self::$root . '/' . self::LEGACY] as $base) {
			foreach (glob($base . '/' . $dir . '-*', GLOB_ONLYDIR) ?: [] as $d) {
				self::rmTree($d);
				$out[] = substr(str_replace('\\', '/', $d), strlen(str_replace('\\', '/', self::$root)) + 1);
			}
		}
		$raw = (string)@file_get_contents(self::$root . '/' . self::STATE);
		if (str_contains($raw, '"' . $slot . '"')) self::saveState(self::state());
		if ($out && self::$log) NuLog::write('nunext_lib_remove', self::PACKS, $slot, ['versions' => $out]);
		return $out;
	}

	/** Keeps the active and the previous version and KEEP older ones in all; removes the rest. */
	public static function prune(string $slot): void
	{
		$s = self::state()[$slot] ?? [];
		$keep = array_filter([(string)($s['active'] ?? ''), (string)($s['prev'] ?? '')]);
		$others = 0;
		foreach (self::installed($slot) as $v) {
			if (in_array($v['version'], $keep, true)) continue;
			if (++$others + count($keep) <= self::KEEP + 1) continue;
			self::rmTree($v['dir']);
		}
	}

	private static function rmTree(string $dir): void
	{
		if ($dir === '' || !is_dir($dir) || is_link($dir)) { if (is_link($dir)) @unlink($dir); return; }
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			if ($f->isDir() && !$f->isLink()) @rmdir($f->getPathname());
			else @unlink($f->getPathname());
		}
		@rmdir($dir);
	}

	// ================================================================== where packs come from

	/** Folders searched for pack zips: _update/ always, _install/ while the installer runs. */
	public static function localDirs(bool $installer = false): array
	{
		$d = [NuUpdate::folderDir()];
		if ($installer && is_dir(NU_ROOT . '/_install')) array_unshift($d, NU_ROOT . '/_install');
		return $d;
	}

	/** Pack zips in those folders (checked): list of inspect() + file, where 'local'. */
	public static function localPacks(bool $installer = false): array
	{
		$out = [];
		foreach (self::localDirs($installer) as $dir) {
			foreach (glob($dir . '/nunext-lib-*.zip') ?: [] as $f) {
				if (!self::isPackName(basename($f))) continue;
				$i = self::inspect($f);
				// the file name must be the pack's own (nunext-lib-<slot>-<version>.zip) – a renamed copy is not offered
				if ($i['ok'] && basename($f) !== self::packName($i['name'], $i['version'])) { $i['ok'] = false; $i['errors'][] = t('lp_err_manifest'); }
				$out[] = $i + ['file' => $f, 'where' => basename($dir)];
			}
		}
		return $out;
	}

	/** GitHub index stored on the update page (setting github_libs): {repo, checked_at, built, libs, error}. */
	public static function ghState(): array
	{
		$s = ['repo' => '', 'checked_at' => 0, 'built' => '', 'libs' => [], 'error' => ''];
		try {
			NuSchema::ensure();
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$j = is_string($v) ? json_decode($v, true) : null;
			if (is_array($j)) $s = array_merge($s, array_intersect_key($j, $s));
		} catch (Throwable) { /* none */ }
		if (!is_array($s['libs'])) $s['libs'] = [];
		return $s;
	}

	private static function ghSave(array $s): void
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

	/** Text the index signature covers. */
	public static function indexPayload(array $libs): string
	{
		return 'nunext-libs|' . hash('sha256', (string)json_encode(array_values($libs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Checks an index (libraries.json): format, signature, entries. @return list<array{name, version, title, file, size, sha256}>
	 * @throws RuntimeException
	 */
	public static function parseIndex(string $json, ?string $key = null): array
	{
		$j = json_decode($json, true);
		if (!is_array($j) || ($j['format'] ?? '') !== self::INDEX_FORMAT || !is_array($j['libs'] ?? null)) throw new RuntimeException(t('lp_err_index'));
		if (self::verify(self::indexPayload($j['libs']), (string)($j['signature'] ?? ''), $key) !== 'valid') throw new RuntimeException(t('lp_err_index_sig'));
		$out = [];
		foreach ($j['libs'] as $l) {
			if (!is_array($l) || !isset(self::SLOTS[(string)($l['name'] ?? '')]) || !self::validVersion((string)($l['version'] ?? ''))
				|| !self::isPackName((string)($l['file'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/', (string)($l['sha256'] ?? ''))) continue;
			$out[] = ['name' => (string)$l['name'], 'version' => (string)$l['version'], 'title' => self::SLOTS[(string)$l['name']]['title'],
				'file' => (string)$l['file'], 'size' => (int)($l['size'] ?? 0), 'sha256' => (string)$l['sha256']];
		}
		return $out;
	}

	/**
	 * Asks GitHub for the release with the tag "libraries" of the update repository and reads its signed index. $save = false: the
	 * installer (no settings table yet). @return array ghState() form, libs with download url
	 */
	public static function ghCheck(?string $repo = null, bool $save = true): array
	{
		$repo ??= NuGitHub::settings()['repo'];
		$s = ['repo' => $repo, 'checked_at' => time(), 'built' => '', 'libs' => [], 'error' => ''];
		try {
			if (!NuGitHub::validRepo($repo)) throw new RuntimeException(t('gh_err_repo'));
			try {
				$rel = json_decode(NuGitHub::get('https://api.github.com/repos/' . $repo . '/releases/tags/' . self::TAG, 1048576, 15), true);
			} catch (RuntimeException $e) {
				throw $e->getMessage() === t('gh_err_404') ? new RuntimeException(t('lp_err_no_release', self::TAG, $repo)) : $e;
			}
			if (!is_array($rel)) throw new RuntimeException(t('gh_err_answer'));
			$assets = [];
			foreach ((array)($rel['assets'] ?? []) as $a) {
				if (is_array($a) && NuGitHub::hostAllowed((string)($a['browser_download_url'] ?? ''))) $assets[(string)$a['name']] = (string)$a['browser_download_url'];
			}
			if (!isset($assets[self::INDEX])) throw new RuntimeException(t('lp_err_no_index'));
			$idxJson = NuGitHub::get($assets[self::INDEX], 1048576, 20, null, true);
			$j = json_decode($idxJson, true);
			foreach (self::parseIndex($idxJson) as $l) {
				if (!isset($assets[$l['file']])) continue;
				$s['libs'][] = $l + ['url' => $assets[$l['file']]];
			}
			$s['built'] = mb_substr((string)($j['built'] ?? ''), 0, 40);
		} catch (Throwable $e) {
			$s['error'] = mb_substr($e->getMessage(), 0, 300);
		}
		if ($save) {
			try { self::ghSave($s); } catch (Throwable) { /* shown anyway */ }
		}
		return $s;
	}

	/** Downloads a pack of the GitHub index into _update/ – kept only when its size, sha256 and signature are right. @return string file */
	public static function download(array $entry): string
	{
		if (!NuGitHub::hostAllowed((string)($entry['url'] ?? '')) || !self::isPackName((string)($entry['file'] ?? ''))) throw new RuntimeException(t('gh_err_host', (string)parse_url((string)($entry['url'] ?? ''), PHP_URL_HOST)));
		$dir = NuUpdate::folderDir();
		$dest = $dir . '/' . $entry['file'];
		$tmp = $dir . '/.download-' . bin2hex(random_bytes(6)) . '.part';
		nu_time_limit(0);
		try {
			NuGitHub::get((string)$entry['url'], self::MAX_DOWNLOAD, 600, $tmp);
			if (!empty($entry['size']) && (int)filesize($tmp) !== (int)$entry['size']) throw new RuntimeException(t('gh_err_size'));
			if (!hash_equals((string)$entry['sha256'], (string)hash_file('sha256', $tmp))) throw new RuntimeException(t('lp_err_download_hash'));
			$i = self::inspect($tmp);
			if (!$i['ok'] || $i['name'] !== $entry['name'] || $i['version'] !== $entry['version']) throw new RuntimeException(implode(' ', $i['errors']) ?: t('gh_err_signature'));
			if (!@rename($tmp, $dest)) throw new RuntimeException(t('gh_err_write'));
		} finally {
			if (is_file($tmp)) @unlink($tmp);
		}
		if (self::$log) NuLog::write('nunext_lib_download', self::PACKS, (string)$entry['name'], ['version' => (string)$entry['version'], 'file' => (string)$entry['file']]);
		return $dest;
	}

	/**
	 * What can be installed per slot: the best local pack and the best GitHub pack (newest version; a local one wins at the same
	 * version). @return array slot => list of {version, where 'local'|'github', file|entry, ok, errors}
	 */
	public static function available(bool $installer = false, ?array $gh = null): array
	{
		$out = array_fill_keys(array_keys(self::SLOTS), []);
		foreach (self::localPacks($installer) as $p) {
			$slot = $p['name'] !== '' ? $p['name'] : (preg_match('/^nunext-lib-([a-z]+)-/', basename($p['file']), $m) && isset(self::SLOTS[$m[1]]) ? $m[1] : '');
			if ($slot === '') continue;
			$out[$slot][] = ['version' => $p['version'], 'where' => 'local', 'file' => $p['file'], 'ok' => $p['ok'], 'errors' => $p['errors'], 'folder' => $p['where']];
		}
		foreach (($gh ?? self::ghState())['libs'] as $l) {
			if (!isset($out[$l['name']])) continue;
			foreach ($out[$l['name']] as $x) if ($x['ok'] && $x['version'] === $l['version']) continue 2;     // already here
			$out[$l['name']][] = ['version' => $l['version'], 'where' => 'github', 'entry' => $l, 'ok' => true, 'errors' => []];
		}
		foreach ($out as &$list) usort($list, static fn($a, $b) => ($b['ok'] <=> $a['ok']) ?: (self::newer($b['version'], $a['version']) ? 1 : (self::newer($a['version'], $b['version']) ? -1 : 0)));
		return $out;
	}

	/** Puts a candidate of available() in place and installs it. @return array install() */
	public static function installCandidate(array $c, string $by = 'update'): array
	{
		if (!$c['ok']) throw new RuntimeException(implode(' ', $c['errors']));
		if ($c['where'] === 'github') {
			$file = self::download($c['entry']);
			$r = self::install($file, 'github');
			@unlink($file);
			return $r;
		}
		$r = self::install((string)$c['file'], $c['folder'] === '_install' ? 'installer' : 'folder');
		@unlink((string)$c['file']);                                   // used: no longer offered
		return $r;
	}

	// ================================================================== overview

	/**
	 * One row per slot for the update page / installer: in use (version, where from), installed packs, roll back target, safe
	 * version state, best candidate. @return array slot => array
	 */
	public static function overview(bool $installer = false, ?array $gh = null): array
	{
		$sec = [];
		// the installer has no nuconfig.php yet (nu_cfg would stop): there only the packs count
		if (!$installer) foreach (NuSecurity::libraries() as $l) if (isset(self::BY_NAME[$l['name']])) $sec[self::BY_NAME[$l['name']]] = $l;
		$avail = self::available($installer, $gh);
		$state = self::state();
		$req = self::required();
		$unmet = $installer ? [] : self::unmet($req, null, $avail);
		$out = [];
		foreach (self::SLOTS as $slot => $def) {
			$l = $sec[$slot] ?? ['version' => '', 'path' => '', 'min' => '', 'status' => 'missing', 'note' => '', 'local' => ''];
			$act = self::activeVersion($slot);
			$nb = $installer ? '' : NuBuilderLib::path();
			$from = $act !== '' ? 'pack' : ((string)$l['path'] === '' ? '' : ($nb !== '' && str_starts_with((string)$l['path'], $nb . '/') && !str_starts_with((string)$l['path'], NU_ROOT . '/') ? 'nubuilder' : 'manual'));
			$best = null;
			foreach ($avail[$slot] as $c) if ($c['ok']) { $best = $c; break; }
			$cur = $act !== '' ? $act : (string)$l['version'];
			$prev = (string)($state[$slot]['prev'] ?? '');
			$out[$slot] = ['slot' => $slot, 'title' => $def['title'], 'use' => $def['use'], 'rec' => $def['rec'], 'web' => $def['web'],
				'version' => (string)$l['version'], 'pack' => $act, 'from' => $from, 'path' => (string)$l['path'], 'status' => (string)$l['status'],
				'min' => (string)$l['min'], 'note' => (string)$l['note'], 'installed' => self::installed($slot),
				'prev' => $prev !== '' && self::hasEntry($slot, self::folder($slot, $prev)) ? $prev : '',
				'best' => $best, 'rejected' => array_values(array_filter($avail[$slot], static fn($c) => !$c['ok'])),
				'update' => $best !== null && ($cur === '' || $act === '' || self::newer($best['version'], $cur)),
				'req' => $req[$slot] ?? null, 'unmet' => isset($unmet[$slot])];
		}
		return $out;
	}

	// ================================================================== versions nuBuilder Next needs (0.8.406)

	/**
	 * What a nuBuilder Next version needs: slot => {min, needed} – of a package (manifest "requires_libs", written by dev/release.py from
	 * install/libraries-required.json) or, without $manifest, of the installed version. needed = must be there; otherwise the minimum
	 * only counts when the library is installed (phpMyAdmin, Adminer, Tiny File Manager).
	 * @return array<string, array{min: string, needed: bool}>
	 */
	public static function required(?array $manifest = null): array
	{
		$j = $manifest !== null ? ($manifest['requires_libs'] ?? null) : json_decode((string)@file_get_contents(NU_ROOT . '/' . self::REQUIRED_FILE), true);
		$out = [];
		foreach (is_array($j) ? $j : [] as $slot => $r) {
			if (!isset(self::SLOTS[$slot]) || !is_array($r) || !self::validVersion((string)($r['min'] ?? ''))) continue;
			$out[$slot] = ['min' => (string)$r['min'], 'needed' => !empty($r['needed'])];
		}
		return $out;
	}

	/** Version of each library in use: the active pack, else the old place ('' = none). @return array<string, string> */
	public static function inUse(): array
	{
		$out = array_fill_keys(array_keys(self::SLOTS), '');
		foreach (NuSecurity::libraries() as $l) if (isset(self::BY_NAME[$l['name']])) $out[self::BY_NAME[$l['name']]] = (string)$l['version'];
		foreach (array_keys(self::SLOTS) as $slot) if (($v = self::activeVersion($slot)) !== '') $out[$slot] = $v;
		return $out;
	}

	/**
	 * Requirements that are not met, with the best pack that meets them (null = none available).
	 * @return array<string, array{slot: string, title: string, min: string, needed: bool, have: string, best: ?array}>
	 */
	public static function unmet(array $required, ?array $inUse = null, ?array $avail = null): array
	{
		$inUse ??= self::inUse();
		$out = [];
		foreach ($required as $slot => $r) {
			$have = (string)($inUse[$slot] ?? '');
			if ($have === '' && !$r['needed']) continue;
			if ($have !== '' && $have !== '?' && version_compare(self::numeric($have), $r['min'], '>=')) continue;
			$best = null;
			foreach (($avail[$slot] ?? []) as $c) if ($c['ok'] && version_compare(self::numeric((string)$c['version']), $r['min'], '>=')) { $best = $c; break; }
			$out[$slot] = ['slot' => $slot, 'title' => self::SLOTS[$slot]['title'], 'min' => $r['min'], 'needed' => $r['needed'], 'have' => $have, 'best' => $best];
		}
		return $out;
	}

	/** Libraries in use below their safe version or with a known problem (badge on the update page). */
	public static function warnings(): int
	{
		$n = 0;
		$unmet = self::unmet(self::required());
		foreach (NuSecurity::libraries() as $l) if (in_array($l['status'], ['old', 'warn'], true) || isset($unmet[self::BY_NAME[$l['name']] ?? ''])) $n++;
		return $n;
	}

	// ================================================================== self-test

	/** Quick check of the library in use. @return array{0: string, 1: string} status ok|warn|fail, text */
	public static function selfTest(string $slot): array
	{
		try {
			switch ($slot) {
				case 'tcpdf':
					NuReport::loadTcpdf();
					$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
					if (method_exists($pdf, 'setPrintHeader')) { $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); }
					$pdf->AddPage();
					$pdf->SetFont('helvetica', '', 12);
					$pdf->Write(0, 'nuBuilder Next');
					$s = (string)$pdf->Output('selftest.pdf', 'S');
					return str_starts_with($s, '%PDF') ? ['ok', t('lp_test_pdf', NuReport::tcpdfVersion() ?: '?', number_format(strlen($s) / 1024, 1))] : ['fail', t('lp_test_pdf_fail')];
				case 'phpmailer':
					if (!NuMailer::loadPHPMailer()) return ['fail', t('lp_test_missing')];
					$m = new \PHPMailer\PHPMailer\PHPMailer(true);
					$ok = $m->validateAddress('test@example.com') && !$m->validateAddress('no address');
					return $ok ? ['ok', t('lp_test_mail', \PHPMailer\PHPMailer\PHPMailer::VERSION)] : ['fail', t('lp_test_mail_fail')];
				case 'tinymce':
					$w = self::tinymce();
					if ($w === '') return ['fail', t('lp_test_missing')];
					$src = (string)@file_get_contents(NU_ROOT . '/' . $w . '/tinymce.min.js', false, null, 0, 400000);
					$v = preg_match('/TinyMCE version ([0-9.]+)/', $src, $mm) ? $mm[1] : '';
					foreach (['themes/silver', 'icons/default', 'models/dom'] as $part) {
						if ($v !== '' && version_compare($v, '6', '>=') && !is_dir(NU_ROOT . '/' . $w . '/' . $part)) return ['fail', t('lp_test_tiny_part', $part)];
					}
					return $v !== '' ? ['ok', t('lp_test_tiny', $v, $w . '/')] : ['warn', t('lp_test_tiny', '?', $w . '/')];
				case 'phpmyadmin':
					if (!NuVendor::available('PMA')) return ['fail', t('lp_test_missing')];
					$cfg = NuVendor::dir('PMA') . '/config.inc.php';
					$src = (string)@file_get_contents($cfg);
					if ($src === '' || !str_contains($src, 'nu_PMA')) return ['fail', t('lp_test_cfg', basename($cfg))];
					return ['ok', t('lp_test_vendor', NuVendor::baseUrl('PMA'))];
				case 'uppy':
					$w = self::uppy();
					if ($w === '') return ['fail', t('lp_test_missing')];
					$v = self::uppyVersion(NU_ROOT . '/' . $w . '/uppy.min.js');
					if (!is_file(NU_ROOT . '/' . $w . '/uppy.min.css')) return ['fail', t('lp_test_tiny_part', 'uppy.min.css')];
					return $v !== '' ? ['ok', t('lp_test_tiny', $v, $w . '/')] : ['warn', t('lp_test_tiny', '?', $w . '/')];
				case 'adminer':
					$f = NuVendor::adminerFile();
					if ($f === '') return ['fail', t('lp_test_missing')];
					if (!is_file(dirname($f) . '/.htaccess')) return ['warn', t('lp_test_adminer_open')];
					return ['ok', t('lp_test_adminer', NuVendor::adminerVersion() ?: '?')];
			}
			return ['fail', '?'];
		} catch (Throwable $e) {
			return ['fail', $e->getMessage()];
		}
	}

	// ================================================================== building (developer mode)

	/** Version of a library folder: NUNEXT-VERSION.txt first, else read from the library's own files ('' = not found). */
	public static function detectVersion(string $slot, string $dir): string
	{
		$local = NuSecurity::localVersion($dir);
		if ($local && $local['version'] !== '') return $local['version'];
		$read = static function (string $file, string $re): string {
			if (!is_file($file)) return '';
			return preg_match($re, (string)@file_get_contents($file, false, null, 0, 400000), $m) ? $m[1] : '';
		};
		$v = '';
		switch ($slot) {
			case 'tcpdf':
				foreach (["$dir/VERSION", "$dir/vendor/tecnickcom/tcpdf/VERSION"] as $f) if ($v === '' && is_file($f)) $v = trim((string)file_get_contents($f));
				if ($v === '') $v = $read("$dir/tcpdf.php", '/Version\s*:\s*([0-9][0-9.]*)/');
				break;
			case 'phpmailer':
				if (is_file("$dir/VERSION")) $v = trim((string)file_get_contents("$dir/VERSION"));
				if ($v === '') $v = $read("$dir/PHPMailer.php", "/const VERSION = '([0-9.]+)'/") ?: $read("$dir/src/PHPMailer.php", "/const VERSION = '([0-9.]+)'/");
				break;
			case 'tinymce':
				$v = $read("$dir/tinymce.min.js", '/TinyMCE version ([0-9.]+)/');
				break;
			case 'phpmyadmin':
				$v = $read("$dir/libraries/classes/Version.php", "/VERSION = '([0-9.]+)'/") ?: $read("$dir/src/Version.php", "/VERSION = '([0-9.]+)'/");
				if ($v === '') $v = $read("$dir/package.json", '/"version"\s*:\s*"([0-9.]+)"/');
				break;
			case 'adminer':
				foreach (array_merge(glob("$dir/adminer.php") ?: [], glob("$dir/adminer-*.php") ?: []) as $f) if ($v === '') $v = $read($f, '/@version\s+([0-9][0-9.]*)/');
				break;
			case 'uppy':
				$v = self::uppyVersion("$dir/uppy.min.js");
				break;
		}
		return self::validVersion($v) ? $v : '';
	}

	/**
	 * Version of an Uppy bundle (uppy.min.js): the version of Uppy's core class – the bundle has no banner. Its minified form is
	 * `var X={version:"4.2.0"},Y=Z("callbacks")` (core) – the other {version:"…"} objects belong to the plugins. '' = not found.
	 */
	public static function uppyVersion(string $file): string
	{
		if (!is_file($file)) return '';
		$src = (string)@file_get_contents($file);
		if (preg_match('/\{version:"([0-9][0-9.]*)"\},\w+=\w+\("callbacks"\)/', $src, $m)) return $m[1];
		return preg_match('/Uppy v?([0-9]+\.[0-9]+\.[0-9]+)/', $src, $m) ? $m[1] : '';
	}

	/** Sources of the packs next to the project (0.8.410): <parent of nuBuilder Next>/libsrc/<slot>/ – outside the project and the web. */
	public const LIBSRC = 'libsrc';
	/** Inside nuBuilder Next (0.8.412): for hosts whose open_basedir allows only the web folder – closed for the web (deny-all .htaccess). */
	public const LIBSRC_IN = '_libsrc';

	/** ../libsrc/<slot>/ when it exists (phpMyAdmin also ../libsrc/nudb/; capitals do not matter: PHPMailer = phpmailer), '' = not there. */
	public static function libsrcDir(string $slot, ?string $root = null): string
	{
		$want = array_unique([$slot, self::SLOTS[$slot]['dir']]);
		foreach (self::libsrcBases($root) as $base) {
			foreach ($want as $name) if (@is_dir("$base/$name")) return "$base/$name";
			foreach (@scandir($base) ?: [] as $e) {                // other spelling (0.8.411)
				if ($e[0] !== '.' && in_array(strtolower($e), $want, true) && @is_dir("$base/$e")) return "$base/$e";
			}
		}
		return '';
	}

	/**
	 * Folders with sources, in this order: ../libsrc/ next to nuBuilder Next (when PHP may read it), _libsrc/ inside it (0.8.412, for
	 * hosts whose open_basedir allows only the web folder – closed for the web with a deny-all .htaccess as soon as it is used).
	 * @return list<string>
	 */
	public static function libsrcBases(?string $root = null): array
	{
		$root ??= NU_ROOT;
		$out = [];
		if (self::libsrcBlocked($root) === '' && @is_dir(dirname($root) . '/' . self::LIBSRC)) $out[] = dirname($root) . '/' . self::LIBSRC;
		$in = $root . '/' . self::LIBSRC_IN;
		if (@is_dir($in)) {
			if ($root === NU_ROOT && class_exists('NuSecurity', false) && method_exists('NuSecurity', 'protectDir')) NuSecurity::protectDir($in);
			$out[] = $in;
		}
		return $out;
	}

	/**
	 * Why ../libsrc/ cannot be read (0.8.411): '' = fine or simply not there; otherwise the open_basedir list of the host (PHP then sees no
	 * folder outside the allowed ones – is_dir() just says false).
	 */
	public static function libsrcBlocked(?string $root = null): string
	{
		$ob = trim(function_exists('nu_ini_get') ? nu_ini_get('open_basedir') : (string)ini_get('open_basedir'));
		if ($ob === '') return '';
		$base = str_replace('\\', '/', dirname($root ?? NU_ROOT) . '/' . self::LIBSRC);
		foreach (explode(PATH_SEPARATOR, $ob) as $allowed) {
			$a = rtrim(str_replace('\\', '/', trim($allowed)), '/');
			if ($a !== '' && ($base === $a || str_starts_with($base . '/', $a . '/'))) return '';
		}
		return $ob;
	}

	/** Source folder of a pack: ../libsrc/<slot>/, else the library in use. @return array{0: string, 1: string} path, kind 'libsrc' | 'inuse' | '' */
	public static function source(string $slot): array
	{
		$l = self::libsrcDir($slot);
		if ($l !== '') return [$l, 'libsrc'];
		$u = self::sourceDir($slot);
		return [$u, $u !== '' ? 'inuse' : ''];
	}

	/** Folder of the library in use: the active pack, else where nuBuilder Next finds it now ('' = none). */
	public static function sourceDir(string $slot): string
	{
		$a = self::activeDir($slot);
		if ($a !== null) return $a;
		foreach (NuSecurity::libraries() as $l) {
			if ((self::BY_NAME[$l['name']] ?? '') !== $slot || (string)$l['path'] === '') continue;
			$p = rtrim((string)$l['path'], '/');
			if ($slot === 'tcpdf' && str_ends_with($p, '/vendor/tecnickcom/tcpdf')) $p = dirname($p, 3);      // the whole Composer folder of TCPDF 7
			return $p;
		}
		return '';
	}

	/** A path as the developer types it: relative to nuBuilder Next (vendor/packs/…, ../libsrc/…) when it is near, else absolute. */
	public static function shortPath(string $p): string
	{
		$p = str_replace('\\', '/', $p);
		$root = str_replace('\\', '/', NU_ROOT);
		if (str_starts_with($p, $root . '/')) return substr($p, strlen($root) + 1);
		if (str_starts_with($p, dirname($root) . '/')) return '../' . substr($p, strlen(dirname($root)) + 1);
		return $p;
	}

	/**
	 * Files of a source folder for a pack: rel => absolute path. Links are refused (a pack is self-contained).
	 * @throws RuntimeException
	 */
	public static function collect(string $dir): array
	{
		$dir = rtrim(str_replace('\\', '/', $dir), '/');
		if ($dir === '' || !is_dir($dir)) throw new RuntimeException(t('lp_err_src', $dir));
		$out = [];
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $f) {
			$rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($dir) + 1);
			if (preg_match(self::SKIP, $rel)) continue;
			if ($f->isLink()) throw new RuntimeException(t('lp_err_link', $rel));
			if ($f->isDir()) continue;
			// what install() added to a pack folder (deny-all .htaccess, empty index.html) is not part of the library
			if (($rel === '.htaccess' && in_array((string)@file_get_contents($f->getPathname()), [NuSecurity::DENY, self::ALLOW], true)) || ($rel === 'index.html' && $f->getSize() === 0)) continue;
			if (!self::validRel($rel)) throw new RuntimeException(t('lp_err_file', $rel));
			$out[$rel] = $f->getPathname();
			if (count($out) > self::MAX_FILES) throw new RuntimeException(t('upd_err_size'));
		}
		ksort($out, SORT_STRING);
		return $out;
	}

	/** Problems that stop a pack: phpMyAdmin / Tiny File Manager without nuBuilder's login check, a secret in phpMyAdmin's config. */
	public static function buildProblems(string $slot, array $files): array
	{
		$p = [];
		if (!self::hasEntryList($slot, array_keys($files))) $p[] = t('lp_err_entry', implode(', ', self::SLOTS[$slot]['entry']));
		if (self::SLOTS[$slot]['web'] && isset($files['.htaccess'])) $p[] = t('lp_err_htaccess');      // installing writes its own (vendor/packs/ is closed)
		$cfg = ['phpmyadmin' => ['config.inc.php', 'nu_PMA']][$slot] ?? null;
		if ($cfg) {
			$src = isset($files[$cfg[0]]) ? (string)file_get_contents($files[$cfg[0]]) : '';
			if (!str_contains($src, $cfg[1])) $p[] = t('lp_test_cfg', $cfg[0]);
			if ($slot === 'phpmyadmin' && preg_match("/\\\$cfg\\[\\s*['\"]blowfish_secret['\"]\\s*\\]\\s*=\\s*['\"][^'\"]+['\"]/", $src)) $p[] = t('lp_err_blowfish');
		}
		return $p;
	}

	private static function hasEntryList(string $slot, array $rels): bool
	{
		foreach (self::SLOTS[$slot]['entry'] as $e) foreach ($rels as $r) if (fnmatch($e, (string)$r, FNM_PATHNAME)) return true;
		return false;
	}

	/** The secret key of a key file (last non-empty line, base64) – checked against the public key. @throws RuntimeException */
	public static function secretKey(string $keyFileText, ?string $publicKey = null): string
	{
		$lines = preg_split('/\R/', trim($keyFileText)) ?: [];
		$sk = base64_decode(trim((string)end($lines)), true);
		if ($sk === false || !function_exists('sodium_crypto_sign_publickey_from_secretkey') || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException(t('lp_err_key'));
		if (!hash_equals((string)base64_decode($publicKey ?? NuUpdate::SIGN_PUBLIC, true), sodium_crypto_sign_publickey_from_secretkey($sk))) {
			sodium_memzero($sk);
			throw new RuntimeException(t('lp_err_key_other'));
		}
		return $sk;
	}

	/**
	 * Builds and signs one pack into $outDir and checks the written zip again. @return array{file: string, name: string, version: string,
	 * files: int, bytes: int, size: int, sha256: string}
	 * @throws RuntimeException
	 */
	public static function build(string $slot, string $srcDir, string $sk, string $outDir, string $version = '', ?string $publicKey = null): array
	{
		if (!isset(self::SLOTS[$slot])) throw new RuntimeException(t('lp_err_slot', $slot));
		if (!class_exists('ZipArchive')) throw new RuntimeException(t('upd_req_zip'));
		nu_time_limit(0);
		if ($srcDir === '' || !is_dir($srcDir)) throw new RuntimeException(t('lp_err_src', $srcDir));
		$version = $version !== '' ? $version : self::detectVersion($slot, $srcDir);
		if (!self::validVersion($version)) throw new RuntimeException(t('lp_err_version', $version));
		$files = self::collect($srcDir);
		$probs = self::buildProblems($slot, $files);
		if ($probs) throw new RuntimeException(implode(' ', $probs));
		$list = [];
		$bytes = 0;
		foreach ($files as $rel => $abs) {
			$size = (int)filesize($abs);
			$list[$rel] = ['sha256' => (string)hash_file('sha256', $abs), 'size' => $size];
			$bytes += $size;
		}
		if ($bytes > self::MAX_BYTES) throw new RuntimeException(t('upd_err_size'));
		$m = ['format' => self::FORMAT, 'name' => $slot, 'version' => $version, 'title' => self::SLOTS[$slot]['title'], 'built' => gmdate('Y-m-d\TH:i:s\Z'),
			'nunext' => defined('NU_VERSION') ? NU_VERSION : '', 'files' => $list];
		$m['signature'] = base64_encode(sodium_crypto_sign_detached(self::payload($m), $sk));
		if (self::verify(self::payload($m), $m['signature'], $publicKey) !== 'valid') throw new RuntimeException(t('lp_err_key_other'));
		if (!is_dir($outDir) && !@mkdir($outDir, 0755, true)) throw new RuntimeException(t('lp_err_write', basename($outDir)));
		$zipFile = rtrim($outDir, '/') . '/' . self::packName($slot, $version);
		@unlink($zipFile);
		$z = new ZipArchive();
		if ($z->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException(t('lp_err_write', basename($zipFile)));
		$z->addFromString(self::MANIFEST, (string)json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		foreach ($files as $rel => $abs) $z->addFile($abs, 'lib/' . $rel);
		if (!$z->close()) throw new RuntimeException(t('lp_err_write', basename($zipFile)));
		unset(self::$inspected[$zipFile . '|' . @filesize($zipFile) . '|' . @filemtime($zipFile) . '|' . $publicKey]);
		$chk = self::inspect($zipFile, $publicKey);
		if (!$chk['ok']) { @unlink($zipFile); throw new RuntimeException(implode(' ', $chk['errors'])); }
		// every file once more out of the zip (what an install checks)
		$z = new ZipArchive();
		$z->open($zipFile);
		foreach ($list as $rel => $f) {
			if (!hash_equals($f['sha256'], hash('sha256', (string)$z->getFromName('lib/' . $rel)))) { $z->close(); @unlink($zipFile); throw new RuntimeException(t('lp_err_hash', $rel)); }
		}
		$z->close();
		return ['file' => $zipFile, 'name' => $slot, 'version' => $version, 'files' => count($list), 'bytes' => $bytes, 'size' => (int)filesize($zipFile),
			'sha256' => (string)hash_file('sha256', $zipFile)];
	}

	/**
	 * The signed index for the GitHub release. $packs: build() results (or index entries kept from the current index – those count
	 * only for slots not built now). @return string JSON
	 */
	public static function buildIndex(array $packs, string $sk, array $keep = []): string
	{
		$libs = [];
		foreach ($packs as $p) $libs[$p['name']] = ['name' => $p['name'], 'version' => $p['version'], 'title' => self::SLOTS[$p['name']]['title'],
			'file' => basename((string)($p['file'] ?? '')), 'size' => (int)$p['size'], 'sha256' => (string)$p['sha256']];
		foreach ($keep as $k) if (!isset($libs[$k['name']]) && isset(self::SLOTS[$k['name']])) $libs[$k['name']] = ['name' => $k['name'], 'version' => $k['version'],
			'title' => self::SLOTS[$k['name']]['title'], 'file' => $k['file'], 'size' => (int)$k['size'], 'sha256' => $k['sha256']];
		$order = array_flip(array_keys(self::SLOTS));
		uksort($libs, static fn($a, $b) => $order[$a] <=> $order[$b]);
		$libs = array_values($libs);
		$idx = ['format' => self::INDEX_FORMAT, 'built' => gmdate('Y-m-d\TH:i:s\Z'), 'libs' => $libs,
			'signature' => base64_encode(sodium_crypto_sign_detached(self::indexPayload($libs), $sk))];
		return (string)json_encode($idx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	}
}
