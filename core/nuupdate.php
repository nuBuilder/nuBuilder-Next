<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Updating nuBuilder Next from the browser (Admin tools → Update, globeadmin).
 *
 * Every package contains nunext-manifest.json: version, sha256 of every file, files removed in earlier versions ("obsolete")
 * and what changed. Installing a package:
 *   1. check   – zip readable, manifest present, paths safe, checksums correct, version newer (or "reinstall"), folder writable
 *   2. stage   – unpack into a temporary folder (outside the web root)
 *   3. backup  – the current program files + nuBuilder Next's own tables (zzzzsys_nunext_*) into _backup/nunext-<version>-<time>.zip
 *   4. switch  – maintenance mode on; copy the staged files over (each file written next to its target, then renamed);
 *                delete files of the old version that the new one no longer has; OPcache reset
 *   5. finish  – next request, with the new code: database steps (NuMigrate), maintenance mode off, "what's new"
 * A failure while copying puts the backup back automatically. "Rollback" restores the program files of a backup later.
 *
 * Never touched: nuconfig.php, third_party/ (phpMyAdmin, Tiny File Manager), _backup/. assets/vendor/ and lib/ are only
 * replaced when a package contains them and never deleted (TinyMCE and other libraries you copied stay).
 */
final class NuUpdate
{
	public const MANIFEST = 'nunext-manifest.json';
	private const MAX_FILES = 3000;
	private const MAX_BYTES = 80 * 1024 * 1024;     // unpacked
	private const MAINTENANCE_MINUTES = 15;

	// ------------------------------------------------------------------ paths

	/** Folder for backups and the maintenance flag: $nuConfigBackupPath or <nuBuilder Next>/_backup (blocked for the web). */
	public static function backupDir(): string
	{
		$cfg = trim((string)nu_cfg('nuConfigBackupPath'));
		$dir = rtrim($cfg !== '' ? $cfg : NU_ROOT . '/_backup', '/\\');
		NuSecurity::protectDir($dir);                        // deny-all .htaccess (Apache 2.2 and 2.4) + empty index.html
		return $dir;
	}

	/**
	 * Public Ed25519 key of the nuBuilder Next releases (0.8.207). dev/release.py signs signedPayload() of the manifest with the
	 * matching private key (kept outside the project); inspect() checks it. Unsigned packages can still be installed (warning).
	 */
	public const SIGN_PUBLIC = 'LiYkLwQop/lalch3M/Kk07lL2MdPQxTI2lPJKELpsLk=';

	/** What a release signature covers: the version and every file with its SHA-256 and size (the files are checked against these). */
	public static function signedPayload(array $manifest): string
	{
		$files = (array)($manifest['files'] ?? []);
		ksort($files, SORT_STRING);
		$lines = [];
		foreach ($files as $rel => $f) $lines[] = $rel . ' ' . ($f['sha256'] ?? '') . ' ' . (int)($f['size'] ?? 0);
		return 'nunext-release|' . (string)($manifest['version'] ?? '') . '|' . hash('sha256', implode("\n", $lines));
	}

	/** 'valid' | 'invalid' | 'unsigned' | 'unchecked' (no libsodium). */
	public static function signatureState(array $manifest): string
	{
		$sig = (string)($manifest['signature'] ?? '');
		if ($sig === '') return 'unsigned';
		if (!function_exists('sodium_crypto_sign_verify_detached')) return 'unchecked';
		$raw = base64_decode($sig, true);
		$key = base64_decode(self::SIGN_PUBLIC, true);
		if ($raw === false || $key === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) return 'invalid';
		return sodium_crypto_sign_verify_detached($raw, self::signedPayload($manifest), $key) ? 'valid' : 'invalid';
	}

	/** Paths an update may never write or delete. */
	public static function isProtected(string $rel): bool
	{
		return $rel === 'nuconfig.php' || str_starts_with($rel, 'third_party/') || str_starts_with($rel, '_backup/') || str_starts_with($rel, '_update/') || str_starts_with($rel, '_install/') || str_starts_with($rel, 'temp/') || $rel === 'install.allow'
			|| (str_starts_with($rel, 'custom/') && !self::shippedCustom($rel));
	}

	/**
	 * Page forms (0.8.387): of custom/ only these files come with nuBuilder Next – the rest (custom/pages/<form code>/, custom/lib/) belongs
	 * to the installation and is never written, deleted or restored by an update.
	 */
	public static function shippedCustom(string $rel): bool
	{
		return in_array($rel, ['custom/.htaccess', 'custom/PAGE_GUIDE.md'], true) || str_starts_with($rel, 'custom/pages/_template/');
	}

	/** Files of the installation's own pages (custom/ without the shipped files) – for "Include custom pages" in a backup. */
	public static function customFiles(): array
	{
		$out = [];
		$dirs = [NU_ROOT . '/custom' => 'custom/'];
		$pages = class_exists('NuPage') ? NuPage::dir() : NU_ROOT . '/custom/pages';
		if (realpath($pages) !== false && !str_starts_with((string)realpath($pages), (string)realpath(NU_ROOT . '/custom'))) $dirs[$pages] = 'custom/pages/';   // $nuConfigCustomPagesDir outside
		foreach ($dirs as $dir => $prefix) {
			if (!is_dir($dir)) continue;
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if (!$f->isFile()) continue;
				$rel = $prefix . substr(str_replace('\\', '/', $f->getPathname()), strlen(rtrim(str_replace('\\', '/', $dir), '/')) + 1);
				if (!self::shippedCustom($rel)) $out[$rel] = $f->getPathname();
			}
		}
		ksort($out);
		return $out;
	}

	/** Libraries: replaced when a package brings them, never deleted. */
	private static function isLibrary(string $rel): bool
	{
		return str_starts_with($rel, 'assets/vendor/') || str_starts_with($rel, 'lib/');
	}

	private static function safePath(string $rel): bool
	{
		return $rel !== '' && !str_contains($rel, '..') && !str_starts_with($rel, '/') && !str_contains($rel, '\\') && !str_contains($rel, ':')
			&& preg_match('#^[A-Za-z0-9._\-/]+$#', $rel) === 1 && !preg_match('#(^|/)\.(?!htaccess$)#', $rel);
	}

	/** The manifest of the installed version, or null (versions before 0.8.0 have none). */
	public static function installedManifest(): ?array
	{
		$f = NU_ROOT . '/' . self::MANIFEST;
		$m = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
		return is_array($m) && isset($m['files']) ? $m : null;
	}

	// ------------------------------------------------------------------ maintenance mode

	private static function flagFile(): string
	{
		return self::backupDir() . '/maintenance.json';
	}

	/** Active maintenance flag (it expires by itself after 15 minutes, so a broken update never locks users out for good). */
	public static function maintenance(): ?array
	{
		$f = NU_ROOT . '/_backup/maintenance.json';
		$cfg = trim((string)nu_cfg('nuConfigBackupPath'));
		if ($cfg !== '') $f = rtrim($cfg, '/\\') . '/maintenance.json';
		if (!is_file($f)) return null;
		$d = json_decode((string)@file_get_contents($f), true);
		if (!is_array($d) || (int)($d['until'] ?? 0) < time()) { @unlink($f); return null; }
		// an update that is complete (new code running, its manifest written last) but whose "finish" step did not run – e.g. the
		// update logged the globeadmin out and the session lost it: end maintenance mode instead of waiting for the time-out
		if (preg_match('/^update .* → (\S+)$/u', (string)($d['reason'] ?? ''), $m) && $m[1] === NU_VERSION
			&& (string)(self::installedManifest()['version'] ?? '') === NU_VERSION) {
			@unlink($f);
			return null;
		}
		return $d;
	}

	public static function maintenanceOn(string $reason): void
	{
		file_put_contents(self::flagFile(), json_encode(['since' => time(), 'until' => time() + self::MAINTENANCE_MINUTES * 60, 'reason' => $reason,
			'by' => (string)(NuAuth::currentRaw()['login'] ?? '')]));
	}

	public static function maintenanceOff(): void
	{
		@unlink(self::flagFile());
	}

	// ------------------------------------------------------------------ checks

	/** Server requirements: [label, ok, detail]. */
	/** php.ini size ("2M", "1G", "512K", "-1") in bytes; 0 = no limit. */
	public static function iniBytes(string $key): int
	{
		$v = trim((string)nu_ini_get($key));
		if ($v === '' || $v === '-1' || $v === '0') return 0;
		$n = (int)$v;
		return match (strtoupper(substr($v, -1))) { 'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n };
	}

	/** Largest file PHP accepts in an upload (the smaller of upload_max_filesize and post_max_size), 0 = no limit. */
	/**
	 * nuBuilder's setting $nuConfigEnableDatabaseUpdate (nuconfig.php, or zzzzsys_config with $nuConfigSettingsFromDB): set to
	 * false, updates are not allowed – no upload / install of a package, no rollback, no "Apply" of the database structure
	 * (0.8.281). Not set or true = allowed. Backups, downloads and the automatic database steps of the installed version stay.
	 */
	public static function enabled(): bool
	{
		$v = nu_cfg('nuConfigEnableDatabaseUpdate');
		if ($v === null || $v === '') return true;
		if (is_bool($v)) return $v;
		return !in_array(strtolower(trim((string)$v)), ['false', '0', 'no', 'off'], true);
	}

	public static function uploadLimit(): int
	{
		$l = array_filter([self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')]);
		return $l ? min($l) : 0;
	}

	/** Upload limits as shown to the user: "upload_max_filesize 2M · post_max_size 8M". */
	public static function uploadLimitText(): string
	{
		return 'upload_max_filesize ' . nu_ini_get('upload_max_filesize') . ' · post_max_size ' . nu_ini_get('post_max_size');
	}

	/** The account PHP runs as (e.g. www-data) – for the "Folder writable" help; '' when unknown. */
	public static function phpUser(): string
	{
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$u = @posix_getpwuid(posix_geteuid());
			if (is_array($u) && !empty($u['name'])) return (string)$u['name'];
		}
		return function_exists('get_current_user') ? (string)(get_current_user() ?: '') : '';
	}

	/** Help for a red "Folder writable": why, and how to fix it by SSH or with the SFTP client. */
	public static function writableHelp(): string
	{
		$u = self::phpUser() ?: 'www-data';
		return t('upd_req_writable_help', $u, NU_ROOT);
	}

	public static function requirements(): array
	{
		$dirs = [NU_ROOT];
		foreach (['core', 'core/form', 'pages', 'assets/js', 'assets/js/nu', 'assets/css', 'lang', 'docs', 'install'] as $d) if (is_dir(NU_ROOT . '/' . $d)) $dirs[] = NU_ROOT . '/' . $d;
		$notWritable = array_values(array_filter($dirs, static fn($d) => !is_writable($d)));
		$free = function_exists('disk_free_space') ? @disk_free_space(NU_ROOT) : false;   // may be disabled (disable_functions)
		$bdir = self::backupDir();
		return [
			['PHP ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION],
			['ZipArchive', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'ok' : t('upd_req_zip')],
			[t('upd_req_writable'), !$notWritable, $notWritable ? implode(', ', array_map(static fn($d) => substr($d, strlen(NU_ROOT)) ?: '/', $notWritable)) : 'ok'],
			[t('upd_req_backup'), is_dir($bdir) && is_writable($bdir), $bdir],
			[t('upd_req_space'), $free === false || $free > 50 * 1024 * 1024, $free === false ? '?' : self::spaceText((float)$free)],
			// packages are about 2 MB; PHP's default upload_max_filesize is 2M – below 16 MB the upload may fail (not a blocker: the
			// check passes once a package was uploaded)
			[t('upd_req_upload'), self::uploadLimit() === 0 || self::uploadLimit() >= 16 * 1048576 || isset($_GET['check']), self::uploadLimitText()
				. (self::uploadLimit() !== 0 && self::uploadLimit() < 16 * 1048576 ? ' – ' . t('upd_req_upload_low') : '')],
			['OPcache', true, function_exists('opcache_get_status') && @opcache_get_status(false) ? (nu_ini_get('opcache.validate_timestamps') === '0' ? t('upd_req_opcache_reset') : 'ok') : t('upd_req_opcache_off')],
		];
	}

	/** Free disk space for the requirements: "1.8 TB free of 1.9 TB (92 %)" – GB / TB instead of thousands of MB (0.8.272). */
	private static function spaceText(float $free): string
	{
		$total = function_exists('disk_total_space') ? @disk_total_space(NU_ROOT) : false;   // 0.8.273: hosts may disable it
		$f = NuFile::size((int)$free);
		if (!$total || $total <= 0) return $f;
		return t('upd_req_space_of', $f, NuFile::size((int)$total), (int)round($free / $total * 100));
	}

	/**
	 * Reads and checks a package. @return array{ok: bool, errors: string[], version: string, manifest: array, prefix: string}
	 */
	/**
	 * Packages put into <nuBuilder Next>/_update/ by FTP (0.8.372): installed from there instead of an upload (big packages, upload
	 * limits). The folder is never part of a package nor touched by an update (isProtected), the web is kept out (deny-all .htaccess).
	 */
	public static function folderDir(): string
	{
		$dir = NU_ROOT . '/_update';
		NuSecurity::protectDir($dir);
		return $dir;
	}

	/** Allowed file name of a package in the folder (no path, no hidden file). */
	public static function folderName(string $name): bool
	{
		return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._()+\-]{0,150}\.zip$/i', $name) && basename($name) === $name;
	}

	/**
	 * The zips of the folder, newest version first: name, size, time, version, signature ('valid' | 'unsigned' | 'invalid' | 'unchecked'
	 * | '' = no nuBuilder Next package), ok (installable from the folder: a package with a valid signature).
	 * @return list<array{name: string, size: int, time: int, version: string, signature: string, ok: bool}>
	 */
	public static function folderPackages(): array
	{
		$dir = self::folderDir();
		$out = [];
		foreach (glob($dir . '/*.zip') ?: [] as $f) {
			$name = basename($f);
			if (!self::folderName($name) || !is_file($f)) continue;
			$p = ['name' => $name, 'size' => (int)@filesize($f), 'time' => (int)@filemtime($f), 'version' => '', 'signature' => '', 'ok' => false];
			if (class_exists('ZipArchive')) {
				$z = new ZipArchive();
				if ($z->open($f) === true) {
					$prefix = $z->locateName(self::MANIFEST) !== false ? '' : ($z->locateName('nunext/' . self::MANIFEST) !== false ? 'nunext/' : null);
					$m = $prefix !== null ? json_decode((string)$z->getFromName($prefix . self::MANIFEST), true) : null;
					$z->close();
					if (is_array($m) && ($m['product'] ?? '') === 'nuNext' && isset($m['version'])) {
						$p['version'] = (string)$m['version'];
						$p['signature'] = self::signatureState($m);
						$p['ok'] = $p['signature'] === 'valid';
					}
				}
			}
			$out[] = $p;
		}
		usort($out, static fn($a, $b) => version_compare($b['version'] ?: '0', $a['version'] ?: '0') ?: strcmp($a['name'], $b['name']));
		return $out;
	}

	public static function inspect(string $zipFile): array
	{
		$out = ['ok' => false, 'errors' => [], 'version' => '', 'manifest' => [], 'prefix' => ''];
		if (!class_exists('ZipArchive')) { $out['errors'][] = t('upd_req_zip'); return $out; }
		$z = new ZipArchive();
		if ($z->open($zipFile) !== true) { $out['errors'][] = t('upd_err_zip'); return $out; }
		try {
			$prefix = $z->locateName(self::MANIFEST) !== false ? '' : ($z->locateName('nunext/' . self::MANIFEST) !== false ? 'nunext/' : null);
			if ($prefix === null) { $out['errors'][] = t('upd_err_manifest'); return $out; }
			$m = json_decode((string)$z->getFromName($prefix . self::MANIFEST), true);
			if (!is_array($m) || !isset($m['version'], $m['files']) || !is_array($m['files']) || ($m['product'] ?? '') !== 'nuNext') { $out['errors'][] = t('upd_err_manifest'); return $out; }
			if (count($m['files']) > self::MAX_FILES) { $out['errors'][] = t('upd_err_size'); return $out; }
			$bytes = 0;
			foreach ($m['files'] as $rel => $info) {
				$rel = (string)$rel;
				if (!self::safePath($rel)) { $out['errors'][] = t('upd_err_path', $rel); continue; }
				if (self::isProtected($rel)) { $out['errors'][] = t('upd_err_protected', $rel); continue; }
				$st = $z->statName($prefix . $rel);
				if ($st === false) { $out['errors'][] = t('upd_err_missing', $rel); continue; }
				$bytes += (int)$st['size'];
				if (!preg_match('/^[a-f0-9]{64}$/', (string)($info['sha256'] ?? ''))) $out['errors'][] = t('upd_err_manifest');
			}
			if ($bytes > self::MAX_BYTES) $out['errors'][] = t('upd_err_size');
			foreach (['index.php', 'core/nuapp.php'] as $must) if (!isset($m['files'][$must])) $out['errors'][] = t('upd_err_missing', $must);
			if (version_compare(PHP_VERSION, (string)($m['requires']['php'] ?? '8.1'), '<')) $out['errors'][] = t('upd_err_php', (string)$m['requires']['php']);
			$out['signature'] = self::signatureState($m);
			if ($out['signature'] === 'invalid') $out['errors'][] = t('upd_err_signature');
			$out['errors'] = array_values(array_unique($out['errors']));
			$out['ok'] = !$out['errors'];
			$out['version'] = (string)$m['version'];
			$out['manifest'] = $m;
			$out['prefix'] = $prefix;
		} finally {
			$z->close();
		}
		return $out;
	}

	// ------------------------------------------------------------------ install

	/**
	 * Installs a checked package. Returns the backup file name. The database steps run on the next request (new code).
	 * @throws RuntimeException with a readable message; files are restored from the backup when copying failed half-way
	 */
	public static function install(string $zipFile, array $info, string $sqlScope = 'none', bool $withoutLogs = true, bool $withPages = false): string
	{
		nu_time_limit($sqlScope === 'none' ? 300 : 0);
		$m = $info['manifest'];
		$stage = self::stage($zipFile, $info);
		try {
			$backup = self::backup('update to ' . $m['version'], $sqlScope, $withoutLogs, $withPages);
			self::maintenanceOn('update ' . NU_VERSION . ' → ' . $m['version']);
			$old = self::installedManifest();
			try {
				foreach ($m['files'] as $rel => $_) self::put($stage . '/' . $rel, (string)$rel);
				self::removeObsolete($old, $m);
				file_put_contents($stage . '/' . self::MANIFEST, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
				self::put($stage . '/' . self::MANIFEST, self::MANIFEST);   // last: from now on this is the installed version
			} catch (Throwable $e) {
				self::restoreFiles(self::backupDir() . '/' . $backup, $m);
				self::maintenanceOff();
				throw new RuntimeException(t('upd_err_copy', $e->getMessage()) . ' ' . t('upd_restored'), 0, $e);
			}
			self::resetCaches();
			$_SESSION['nu_update'] = ['from' => NU_VERSION, 'to' => (string)$m['version'], 'backup' => $backup, 'changes' => $m['changes'] ?? []];
			NuLog::write('nunext_update', '', '', ['from' => NU_VERSION, 'to' => $m['version'], 'backup' => $backup]);
			return $backup;
		} finally {
			self::rmTree($stage);
		}
	}

	/** Unpacks the manifest's files into a temporary folder and checks every checksum. */
	private static function stage(string $zipFile, array $info): string
	{
		$stage = rtrim(sys_get_temp_dir(), '/\\') . '/nunext-update-' . bin2hex(random_bytes(6));
		if (!@mkdir($stage, 0700, true)) throw new RuntimeException(t('upd_err_stage'));
		$z = new ZipArchive();
		if ($z->open($zipFile) !== true) throw new RuntimeException(t('upd_err_zip'));
		try {
			foreach ($info['manifest']['files'] as $rel => $f) {
				$data = $z->getFromName($info['prefix'] . $rel);
				if ($data === false || !hash_equals((string)$f['sha256'], hash('sha256', $data))) throw new RuntimeException(t('upd_err_checksum', (string)$rel));
				$dest = $stage . '/' . $rel;
				if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0700, true);
				file_put_contents($dest, $data);
			}
		} catch (Throwable $e) {
			self::rmTree($stage);
			throw $e;
		} finally {
			$z->close();
		}
		return $stage;
	}

	/** Writes one file: next to the target first, then renamed over it (a reader never sees half a file). */
	private static function put(string $src, string $rel): void
	{
		$dest = NU_ROOT . '/' . $rel;
		if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0775, true)) throw new RuntimeException(t('upd_err_write', $rel));
		$tmp = $dest . '.nunew';
		if (@copy($src, $tmp) === false) throw new RuntimeException(t('upd_err_write', $rel));
		if (!@rename($tmp, $dest)) {                     // Windows: rename does not replace
			@unlink($dest);
			if (!@rename($tmp, $dest)) { @unlink($tmp); throw new RuntimeException(t('upd_err_write', $rel)); }
		}
	}

	/** Files of the old version that the new one does not have, plus names removed in earlier versions. */
	private static function removeObsolete(?array $old, array $new): array
	{
		$gone = [];
		$candidates = array_merge($old ? array_keys($old['files']) : [], (array)($new['obsolete'] ?? []));
		foreach (array_unique($candidates) as $rel) {
			$rel = (string)$rel;
			if (isset($new['files'][$rel]) || !self::safePath($rel) || self::isProtected($rel) || self::isLibrary($rel)) continue;
			$f = NU_ROOT . '/' . $rel;
			if (is_file($f) && @unlink($f)) $gone[] = $rel;
		}
		return $gone;
	}

	public static function resetCaches(): void
	{
		if (function_exists('opcache_reset')) @opcache_reset();
		clearstatcache(true);
	}

	// ------------------------------------------------------------------ backups

	/** Program files (what an update may change) – from the manifest, or the known folders for versions without one. */
	private static function programFiles(): array
	{
		$files = [];
		$m = self::installedManifest();
		if ($m) foreach (array_keys($m['files']) as $rel) if (is_file(NU_ROOT . '/' . $rel)) $files[] = (string)$rel;
		foreach (['core', 'pages', 'assets/js', 'assets/css', 'lang', 'docs', 'install'] as $dir) {
			if (!is_dir(NU_ROOT . '/' . $dir)) continue;
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(NU_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				$rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(NU_ROOT) + 1);
				if ($f->isFile() && !self::isProtected($rel) && !self::isLibrary($rel)) $files[] = $rel;
			}
		}
		foreach (['index.php', 'api.php', 'cron.php', '.htaccess', 'nuconfig-sample.php', 'README.md', 'CHANGELOG.md', 'LICENSE', 'TODO.md', self::MANIFEST, 'docs/.htaccess'] as $f) if (is_file(NU_ROOT . '/' . $f)) $files[] = $f;
		$files = array_values(array_unique($files));
		sort($files);
		return $files;
	}

	/** Result of the database part of the last backup() (null: none) – for the message after "Back up now". */
	public static ?array $lastSql = null;

	public const KEEP_SQL_SETTING = 'backup_keep_sql';
	public const KEEP_SQL_DEFAULT = 5;
	private const RUNNING = '.backup-running.json';

	/**
	 * Backup zip: program files + nuBuilder Next's tables (JSON) and, optionally, an SQL dump (db/database.sql.gz, see core/nusqldump.php):
	 * $sqlScope 'none' | 'nunext' (nuBuilder Next's tables) | 'all' (the whole database); $withoutLogs leaves out the rows of log tables.
	 * Never two at the same time (lock); while it runs a marker file says so – if the server stops the script (time limit), the next
	 * page view reports it (interrupted()). Returns the file name inside backupDir().
	 */
	public static function backup(string $reason, string $sqlScope = 'none', bool $withoutLogs = true, bool $withPages = false): string
	{
		$dir = self::backupDir();
		$lock = @fopen($dir . '/.backup.lock', 'c');
		if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException(t('upd_backup_busy')); }
		self::$lastSql = null;
		// a long random part: even where the web server ignores .htaccess (nginx), the name cannot be guessed
		$name = 'nunext-' . NU_VERSION . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.zip';
		$path = $dir . '/' . $name;
		$tmp = '';
		try {
			$withSql = in_array($sqlScope, ['nunext', 'all'], true) && self::loadDump() && NuSqlDump::available();
			@file_put_contents($dir . '/' . self::RUNNING, json_encode(['name' => $name, 'since' => time(), 'reason' => $reason, 'sql' => $withSql ? $sqlScope : 'none']));
			$sql = null;
			if ($withSql) {
				nu_time_limit(0);
				nu_ignore_abort();
				$tmp = $dir . '/.' . $name . '.sql.gz.tmp';
				$sql = NuSqlDump::write($tmp, $sqlScope, $withoutLogs);
			}
			$z = new ZipArchive();
			if ($z->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException(t('upd_err_backup'));
			$files = self::programFiles();
			foreach ($files as $rel) $z->addFile(NU_ROOT . '/' . $rel, 'files/' . $rel);
			// "Include custom pages" (0.8.387): the installation's page folders as a copy (custom/…) – never restored by a rollback
			$pages = [];
			if ($withPages) foreach (self::customFiles() as $rel => $abs) { $z->addFile($abs, 'custom/' . substr($rel, 7)); $pages[] = $rel; }
			$tables = [];
			$db = nu_db();
			foreach ($db->type === 'sqlite' ? [] : self::nunextTables() as $t) {
				try {
					$z->addFromString('db/' . $t . '.json', json_encode($db->all('SELECT * FROM ' . NuDB::ident($t)), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
					$tables[] = $t;
				} catch (Throwable) {
				}
			}
			if ($sql !== null && is_file($tmp)) {
				$z->addFile($tmp, self::SQL_ENTRY);
				$z->setCompressionName(self::SQL_ENTRY, ZipArchive::CM_STORE);   // already gzip
			}
			$z->addFromString('backup.json', json_encode(['product' => 'nuNext', 'version' => NU_VERSION, 'created' => date('c'), 'reason' => $reason,
				'by' => (string)(NuAuth::currentRaw()['login'] ?? ''), 'files' => $files, 'tables' => $tables, 'sql' => $sql, 'custom' => $pages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			if (!$z->close()) throw new RuntimeException(t('upd_err_backup'));
			@chmod($path, 0600);
			self::$lastSql = $sql;
		} finally {
			if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
			@unlink($dir . '/' . self::RUNNING);
			if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
		}
		if (self::$lastSql !== null) self::pruneSql();
		return $name;
	}

	public const SQL_ENTRY = 'db/database.sql.gz';

	public static function loadDump(): bool
	{
		require_once NU_ROOT . '/core/nuappcloner.php';
		require_once NU_ROOT . '/core/nusqldump.php';
		return true;
	}

	/**
	 * A backup that did not finish (the server stopped the script – time limit, memory): its marker is still there although no backup
	 * runs. Reported once; the marker and the half-written dump are removed. @return ?array{since: int, reason: string, sql: string}
	 */
	public static function interrupted(): ?array
	{
		$dir = self::backupDir();
		$f = $dir . '/' . self::RUNNING;
		if (!is_file($f)) return null;
		$lock = @fopen($dir . '/.backup.lock', 'c');
		if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }   // still running
		try {
			$d = json_decode((string)@file_get_contents($f), true);
			@unlink($f);
			foreach (glob($dir . '/.nunext-*.sql.gz.tmp') ?: [] as $t) @unlink($t);
			if (is_array($d) && isset($d['name']) && is_file($dir . '/' . basename((string)$d['name']))) @unlink($dir . '/' . basename((string)$d['name']));   // never a half zip
			return is_array($d) ? $d : ['since' => 0, 'reason' => '', 'sql' => ''];
		} finally {
			if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
		}
	}

	/** How many of the newest backups keep their SQL dump (0 = all). Setting in zzzzsys_nunext_setting. */
	public static function keepSql(): int
	{
		try {
			NuSchema::ensure();
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::KEEP_SQL_SETTING]);
			return $v === null ? self::KEEP_SQL_DEFAULT : max(0, (int)$v);
		} catch (Throwable) {
			return self::KEEP_SQL_DEFAULT;
		}
	}

	public static function setKeepSql(int $n): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => (string)max(0, min(999, $n)), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::KEEP_SQL_SETTING])) {
			$db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::KEEP_SQL_SETTING);
		} else {
			$db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::KEEP_SQL_SETTING] + $row);
		}
		self::pruneSql();
	}

	/**
	 * Older backups lose their SQL dump: only the newest keepSql() backups with a dump keep it (the program files stay, so they can
	 * still be rolled back). @return int number of dumps removed
	 */
	public static function pruneSql(): int
	{
		$keep = self::keepSql();
		if ($keep === 0) return 0;
		$n = 0;
		$with = array_values(array_filter(self::backups(), static fn($b) => !empty($b['sql']) && empty($b['sql']['removed'])));
		foreach (array_slice($with, $keep) as $b) {
			$f = self::backupPath($b['name']);
			$z = new ZipArchive();
			if (!$f || $z->open($f) !== true) continue;
			$meta = json_decode((string)$z->getFromName('backup.json'), true) ?: [];
			if ($z->locateName(self::SQL_ENTRY) !== false) $z->deleteName(self::SQL_ENTRY);
			$meta['sql'] = (array)($meta['sql'] ?? []) + ['removed' => ''];
			$meta['sql']['removed'] = date('c');
			$z->addFromString('backup.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			if ($z->close()) $n++;
			@chmod($f, 0600);
		}
		return $n;
	}

	private static function nunextTables(): array
	{
		$out = [];
		foreach (['zzzzsys_nunext_log', 'zzzzsys_nunext_form_history', 'zzzzsys_nunext_form_draft', 'zzzzsys_nunext_page', 'zzzzsys_nunext_user_2fa', 'zzzzsys_nunext_token', 'zzzzsys_nunext_session', NuMigrate::TABLE] as $t) {
			if (nu_db()->tableExists($t)) $out[] = $t;
		}
		return $out;
	}

	/** [name, version, created, reason, size] newest first. */
	public static function backups(): array
	{
		$out = [];
		foreach (glob(self::backupDir() . '/nunext-*.zip') ?: [] as $f) {
			$meta = [];
			$z = new ZipArchive();
			if ($z->open($f) === true) { $meta = json_decode((string)$z->getFromName('backup.json'), true) ?: []; $z->close(); }
			if (($meta['product'] ?? '') !== 'nuNext') continue;
			$out[] = ['name' => basename($f), 'version' => (string)($meta['version'] ?? '?'), 'created' => (string)($meta['created'] ?? ''), 'reason' => (string)($meta['reason'] ?? ''),
				'by' => (string)($meta['by'] ?? ''), 'size' => (int)filesize($f), 'tables' => (array)($meta['tables'] ?? []),
				'sql' => is_array($meta['sql'] ?? null) ? $meta['sql'] : null];
		}
		usort($out, static fn($a, $b) => ((int)strtotime($b['created']) <=> (int)strtotime($a['created'])) ?: strcmp($b['name'], $a['name']));   // real time, not text (time zones)
		return $out;
	}

	public static function backupPath(string $name): ?string
	{
		if (!preg_match('/^nunext-[0-9a-z.\-]+\.zip$/', $name)) return null;
		$f = self::backupDir() . '/' . $name;
		return is_file($f) ? $f : null;
	}

	/** Program files of a backup back in place; files of the current version that the backup does not have are removed. */
	public static function restoreFiles(string $backupFile, ?array $current = null): array
	{
		$z = new ZipArchive();
		if ($z->open($backupFile) !== true) throw new RuntimeException(t('upd_err_zip'));
		$meta = json_decode((string)$z->getFromName('backup.json'), true);
		if (!is_array($meta) || ($meta['product'] ?? '') !== 'nuNext') { $z->close(); throw new RuntimeException(t('upd_err_backup_invalid')); }
		$keep = array_flip((array)$meta['files']);
		$current ??= self::installedManifest();
		$stage = rtrim(sys_get_temp_dir(), '/\\') . '/nunext-restore-' . bin2hex(random_bytes(6));
		mkdir($stage, 0700, true);
		try {
			foreach ((array)$meta['files'] as $rel) {
				$rel = (string)$rel;
				if (!self::safePath($rel) || self::isProtected($rel)) continue;
				$data = $z->getFromName('files/' . $rel);
				if ($data === false) continue;
				if (!is_dir(dirname($stage . '/' . $rel))) mkdir(dirname($stage . '/' . $rel), 0700, true);
				file_put_contents($stage . '/' . $rel, $data);
			}
			foreach ((array)$meta['files'] as $rel) if (is_file($stage . '/' . $rel)) self::put($stage . '/' . $rel, (string)$rel);
			$removed = [];
			foreach (array_keys((array)($current['files'] ?? [])) as $rel) {
				$rel = (string)$rel;
				if (isset($keep[$rel]) || self::isProtected($rel) || self::isLibrary($rel) || !self::safePath($rel)) continue;
				if (is_file(NU_ROOT . '/' . $rel) && @unlink(NU_ROOT . '/' . $rel)) $removed[] = $rel;
			}
			if (!isset($keep[self::MANIFEST])) @unlink(NU_ROOT . '/' . self::MANIFEST);   // the old version had none
			self::resetCaches();
			return ['version' => (string)$meta['version'], 'removed' => $removed];
		} finally {
			$z->close();
			self::rmTree($stage);
		}
	}

	private static function rmTree(string $dir): void
	{
		if ($dir === '' || !is_dir($dir)) return;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		@rmdir($dir);
	}

	/** The "what's new" entries of a manifest that are newer than $from. */
	/** The whole change history of the installed version (nunext-manifest.json), newest first. */
	public static function history(): array
	{
		$list = array_values(array_filter((array)(self::installedManifest()['changes'] ?? []), static fn($c) => is_array($c) && (string)($c['version'] ?? '') !== ''));
		usort($list, static fn($a, $b) => version_compare((string)$b['version'], (string)$a['version']));
		return $list;
	}

	/** Change text for the page: escaped, line breaks kept, **bold** shown bold. */
	public static function changeHtml(string $text): string
	{
		$text = str_replace(["\r\n", "\r"], "\n", trim($text));
		$text = (string)preg_replace('/[ \t]*\n(?![ \t]*(?:[-*•] |\n))[ \t]*/', ' ', $text); // reflow hard-wrapped lines, keep bullets and blank lines
		$html = '';
		foreach (explode("\n", $text) as $line) {
			if (preg_match('/^#{2,4}\s+(.+)$/', $line, $m)) $html .= '<div class="fw-semibold mt-2">' . h($m[1]) . '</div>'; // ### heading (older entries)
			else $html .= '<div>' . h($line) . '</div>';
		}
		return (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', str_replace('<div></div>', '<div class="mt-2"></div>', $html));
	}

	public static function changesSince(array $changes, string $from): array
	{
		return array_values(array_filter($changes, static fn($c) => is_array($c) && version_compare((string)($c['version'] ?? '0'), $from, '>')));
	}
}

/*
 * Database steps of nuBuilder Next, each applied once and recorded in zzzzsys_nunext_migration (id, when, version).
 * They run after an update (Update page) and, for manual updates, with the first request of the globeadmin's
 * next session (NuSchema::ensure). Steps must be safe to repeat: they check what is there before changing it.
 */
final class NuMigrate
{
	public const TABLE = 'zzzzsys_nunext_migration';
	/** Were steps applied before this run (an existing installation)? Set by run(). */
	public static bool $earlier = false;

	/** id => [description, function] – append new steps at the end, never change or reorder existing ones. */
	private static function steps(): array
	{
		return [
			'0001-own-tables' => ['nuBuilder Next tables: log, form history, 2FA, tokens, sessions, API, dashboards …', static fn() => NuSchema::create()],
			'0002-report-tables' => ['nuBuilder report and SQL builder tables (zzzzsys_report, zzzzsys_access_report, zzzzsys_select, zzzzsys_select_clause) when missing', static fn() => NuReport::ensureTables()],
			'0003-translate-table' => ['nuBuilder translation table (zzzzsys_translate) when missing – new installations have it, older ones got it only with the first saved translation', static fn() => NuTranslate::ensureTable()],
			'0004-cloner-tables' => ['nuBuilder tables of saved Cloner and App cloner settings (zzzzsys_cloner, zzzzsys_app_cloner) when missing', static function (): void { NuClonerConfig::ensureTable(); NuAppClonerConfig::ensureTable(); }],
			'0005-setting-primary-db' => ['Settings table: row $nuConfigPrimaryDatabaseManagement (phpMyAdmin or Adminer) when missing', static function (): void { if (NuSettings::available()) NuSettings::addOwn(); }],
			// steps of nuBuilder Next itself start again after the upgrade baseline 0.8.321 (no paths from earlier versions)
			'0007-drop-vendor-layout' => ['Settings of nuBuilder Next: row vendor_layout of the former Admin tools view removed', static function (): void {
				if (nu_db()->tableExists('zzzzsys_nunext_setting')) nu_db()->run('DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', ['vendor_layout']);
			}],
			'0008-admin-roles' => ['nuBuilder Next admin roles (zzzzsys_nunext_admin_role, zzzzsys_nunext_admin_user) with the five default roles', static fn() => NuAdmin::ensure()],
			'0009-list-views' => ['nuBuilder Next list views (zzzzsys_nunext_list_view): layouts of the users and access level lists', static fn() => NuSchema::create()],
			'0010-home-layouts' => ['Start page: the layout becomes the shared layout "Standard" (saved layouts, zzzzsys_nunext_list_view)', static fn() => NuHome::migrateSingle()],
			'0011-audit' => ['Audit trail (zzzzsys_nunext_audit): table created, the record changes of the log copied into it', static fn() => NuAudit::copyFromLog()],
			'0012-notifications' => ['Notification centre (zzzzsys_nunext_notification, zzzzsys_nunext_notification_user); permission items NUNEXT_NOTIFY and NUNEXT_NOTIFY_OWN', static fn() => NuNotify::install()],
			'0013-setting-home-hooks' => ['Settings table: row $nuConfigHomeHooks (start page hooks on / off) when missing', static function (): void { if (NuSettings::available()) NuSettings::addOwn(); }],
			'0014-database-origin' => ['Settings of nuBuilder Next: row db_origin (new database, existing nuBuilder database, or installed before 0.8.380)', static fn() => NuInstall::markOrigin(self::$earlier)],
			'0015-form-drafts' => ['Form drafts (zzzzsys_nunext_form_draft)', static fn() => NuSchema::create()],
			'0016-pages' => ['Page forms (zzzzsys_nunext_page)', static fn() => NuSchema::create()],
			'0017-presence' => ['Who has a record open (zzzzsys_nunext_presence)', static fn() => NuSchema::create()],
		];
	}

	/**
	 * What each step changes, for the preview of an update (install/db_changes.json, 0.8.346): id => list of [table, kind, what];
	 * kind: table (created when missing), column, type, data. Add an entry for every new step.
	 */
	public const CHANGES = [
		'0001-own-tables' => [['zzzzsys_nunext_*', 'table', 'nuBuilder Next tables created when missing (log, form history, 2FA, tokens, sessions, settings, API, dashboards …)']],
		'0002-report-tables' => [['zzzzsys_report', 'table', 'created when missing'], ['zzzzsys_access_report', 'table', 'created when missing'],
			['zzzzsys_select', 'table', 'created when missing'], ['zzzzsys_select_clause', 'table', 'created when missing']],
		'0003-translate-table' => [['zzzzsys_translate', 'table', 'created when missing']],
		'0004-cloner-tables' => [['zzzzsys_cloner', 'table', 'created when missing'], ['zzzzsys_app_cloner', 'table', 'created when missing']],
		'0005-setting-primary-db' => [['zzzzsys_config', 'data', 'row $nuConfigPrimaryDatabaseManagement added when missing']],
		'0007-drop-vendor-layout' => [['zzzzsys_nunext_setting', 'data', 'row vendor_layout deleted']],
		'0008-admin-roles' => [['zzzzsys_nunext_admin_role', 'table', 'created when missing'], ['zzzzsys_nunext_admin_user', 'table', 'created when missing'],
			['zzzzsys_nunext_admin_role', 'data', 'five default roles inserted when the table is empty']],
		'0009-list-views' => [['zzzzsys_nunext_list_view', 'table', 'created when missing']],
		'0010-home-layouts' => [['zzzzsys_nunext_list_view', 'data', 'the start page layout inserted as shared layout "Standard" (list_key home) and set as default'],
			['zzzzsys_nunext_setting', 'data', 'row home_layout moved there and deleted']],
		'0011-audit' => [['zzzzsys_nunext_audit', 'table', 'created when missing'],
			['zzzzsys_nunext_audit', 'data', 'record changes of zzzzsys_nunext_log (record_inserted, record_updated, record_deleted) copied, one row per column']],
		'0012-notifications' => [['zzzzsys_nunext_notification', 'table', 'created when missing'], ['zzzzsys_nunext_notification_user', 'table', 'created when missing'],
			['zzzzsys_permission_item', 'data', 'items NUNEXT_NOTIFY and NUNEXT_NOTIFY_OWN added when missing (group "nuBuilder Next")']],
		'0013-setting-home-hooks' => [['zzzzsys_config', 'data', 'row $nuConfigHomeHooks added when missing']],
		'0014-database-origin' => [['zzzzsys_nunext_setting', 'data', 'row db_origin added when missing']],
		'0015-form-drafts' => [['zzzzsys_nunext_form_draft', 'table', 'created when missing']],
		'0016-pages' => [['zzzzsys_nunext_page', 'table', 'created when missing']],
		'0017-presence' => [['zzzzsys_nunext_presence', 'table', 'created when missing']],
	];

	/** Steps with their descriptions and changes (for install/db_changes.json). @return list<array{id: string, description: string, changes: list<array>}> */
	public static function catalog(): array
	{
		$out = [];
		foreach (self::steps() as $id => [$desc]) {
			$out[] = ['id' => $id, 'description' => $desc, 'changes' => array_map(static fn($c) => ['table' => $c[0], 'kind' => $c[1], 'what' => $c[2]], self::CHANGES[$id] ?? [])];
		}
		return $out;
	}

	/** Ids of the steps already applied in this database. @return string[] */
	public static function appliedIds(): array
	{
		self::ensureTable();
		return array_map('strval', array_column(nu_db()->all('SELECT ' . self::TABLE . '_id AS id FROM ' . self::TABLE), 'id'));
	}

	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists(self::TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$db->run('CREATE TABLE ' . self::TABLE . ' (' . self::TABLE . '_id ' . $T('text', '50') . ' NOT NULL PRIMARY KEY, applied_at ' . $T('datetime') . ' NULL, '
			. 'version ' . $T('text', '20') . ' NULL, description ' . $T('text', '255') . ' NULL)' . ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
	}

	/** @return array<int, array{id: string, description: string, applied: ?string, version: ?string}> */
	public static function status(): array
	{
		self::ensureTable();
		$done = [];
		foreach (nu_db()->all('SELECT * FROM ' . self::TABLE) as $r) $done[(string)$r[self::TABLE . '_id']] = $r;
		$out = [];
		foreach (self::steps() as $id => [$desc]) {
			$out[] = ['id' => $id, 'description' => $desc, 'applied' => isset($done[$id]) ? (string)$done[$id]['applied_at'] : null, 'version' => $done[$id]['version'] ?? null];
		}
		return $out;
	}

	/** Runs the steps not applied yet. @return string[] ids applied now */
	public static function run(): array
	{
		self::ensureTable();
		$db = nu_db();
		$done = array_flip(array_map('strval', array_column($db->all('SELECT ' . self::TABLE . '_id AS id FROM ' . self::TABLE), 'id')));
		self::$earlier = $done !== [];                       // steps of an earlier request: nuBuilder Next was installed before (step 0014)
		$applied = [];
		foreach (self::steps() as $id => [$desc, $fn]) {
			if (isset($done[$id])) continue;
			$fn();
			$db->insert(self::TABLE, [self::TABLE . '_id' => $id, 'applied_at' => date('Y-m-d H:i:s'),
				'version' => NU_VERSION, 'description' => mb_substr($desc, 0, 255)]);
			$applied[] = $id;
		}
		if ($applied) NuLog::write('nunext_migration', self::TABLE, '', ['steps' => $applied]);
		return $applied;
	}
}
