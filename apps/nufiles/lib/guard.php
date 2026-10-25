<?php
defined('NU_PAGE') || exit;

/**
 * nuFiles – path and name guard. Every path that comes from the browser goes through here before any file system call.
 *
 *  - Paths are RELATIVE to a share root, '/' separated. Rejected: null bytes, control characters, backslashes, '..', more than
 *    64 levels or 4096 bytes. '.' and empty parts are dropped.
 *  - An existing path is resolved with realpath() and must lie inside the share root (symlinks pointing outside are refused).
 *  - A new name is checked on its own (length, characters, reserved names) and against the share's rules: in a "files" share no
 *    names a web server could run or obey (same list as nuBuilder Next's upload guard, every dot part checked), no names starting
 *    with a dot, optionally no HTML / SVG / JS (web_active_types = false) and an optional extension allow-list.
 *  - The data folder of nuFiles (trash, versions, upload chunks) and the share's deny patterns are never reachable.
 */
final class NufError extends RuntimeException
{
	public function __construct(public string $key, public int $status = 400, public array $args = [])
	{
		parent::__construct($key, $status);
	}
}

final class NufGuard
{
	/** nuBuilder Next 0.8.459 NuSecurity::UPLOAD_DANGEROUS – kept in sync; the core list is used when it exists. */
	public const DANGEROUS = ['php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'pht', 'phtm', 'phtml', 'phar', 'inc',
		'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'ashx', 'asmx', 'cer', 'jsp', 'jspx', 'shtml', 'shtm', 'stm', 'htaccess', 'htpasswd',
		'ini', 'exe', 'com', 'bat', 'cmd', 'dll'];
	/** Served by a web server as active content on the portal's origin (stored XSS) – refused unless web_active_types = true. */
	public const WEB_ACTIVE = ['html', 'htm', 'xhtml', 'xht', 'svg', 'svgz', 'xml', 'xsl', 'xslt', 'js', 'mjs', 'swf', 'hta', 'vbs', 'wasm'];
	/** Names Windows / Samba cannot store (shares are sometimes mounted). */
	private const RESERVED = ['con', 'prn', 'aux', 'nul', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
		'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];

	/** @return list<string> the parts of a relative path ('' = the share root) */
	public static function segments(string $rel): array
	{
		if (strlen($rel) > 4096) throw new NufError('err_path');
		if (str_contains($rel, "\0") || preg_match('/[\x00-\x1f\x7f]/', $rel) || str_contains($rel, '\\')) throw new NufError('err_path');
		$out = [];
		foreach (explode('/', $rel) as $p) {
			if ($p === '' || $p === '.') continue;
			if ($p === '..') throw new NufError('err_path');
			$out[] = $p;
		}
		if (count($out) > 64) throw new NufError('err_path');
		return $out;
	}

	public static function join(array $segments): string
	{
		return implode('/', $segments);
	}

	/** Is $real (a realpath) the root itself or inside it? */
	public static function inside(string $root, string $real): bool
	{
		$root = rtrim($root, '/');
		return $real === $root || str_starts_with($real, $root . '/');
	}

	/** Lower-case dot parts after the first one: "a.tar.gz" → ['tar', 'gz']. */
	public static function extensions(string $name): array
	{
		$parts = explode('.', strtolower(rtrim($name, '. ')));
		array_shift($parts);
		return array_values(array_filter(array_map('trim', $parts), 'strlen'));
	}

	public static function lastExtension(string $name): string
	{
		$e = self::extensions($name);
		return $e ? (string)end($e) : '';
	}

	/** Would a web server run this name (or treat it as its configuration)? Uses nuBuilder Next's guard when it is there. */
	public static function dangerous(string $name): bool
	{
		if (class_exists('NuSecurity', false) && method_exists('NuSecurity', 'dangerousUploadName')) {
			if (NuSecurity::dangerousUploadName($name)) return true;
		}
		$base = strtolower(trim($name));
		if ($base === '' || str_starts_with($base, '.')) return true;
		foreach (self::extensions($base) as $p) if (in_array($p, self::DANGEROUS, true)) return true;
		return false;
	}

	/**
	 * A name for a new or renamed file / folder in $share. Throws NufError with the reason.
	 * $isDir: folders skip the extension rules (but not the dot and length rules).
	 */
	public static function checkName(string $name, array $share, bool $isDir): void
	{
		if ($name === '' || $name === '.' || $name === '..') throw new NufError('err_name_empty');
		if (strlen($name) > 255) throw new NufError('err_name_long');
		if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new NufError('err_name_chars');
		if ($name !== trim($name) || str_ends_with($name, '.')) throw new NufError('err_name_edges');
		if (!mb_check_encoding($name, 'UTF-8')) throw new NufError('err_name_chars');
		// direction marks turn "invoice‮fdp.exe" into a name that reads "invoiceexe.pdf"; invisible characters hide parts of a name
		if (preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', $name)) throw new NufError('err_name_chars');
		if (str_starts_with(strtolower($name), '.nufiles')) throw new NufError('err_name_reserved');
		if (in_array(strtolower(explode('.', $name)[0]), self::RESERVED, true)) throw new NufError('err_name_reserved');
		if (($share['type'] ?? 'files') === 'code') return;   // globeadmin only: everything a file system allows
		if (str_starts_with($name, '.')) throw new NufError('err_name_dot');
		if ($isDir) return;
		if (self::dangerous($name)) throw new NufError('err_name_dangerous', 400, [self::lastExtension($name)]);
		if (empty($share['web_active_types'])) {
			foreach (self::extensions($name) as $p) if (in_array($p, self::WEB_ACTIVE, true)) throw new NufError('err_name_web', 400, [$p]);
		}
		$allow = $share['extensions'] ?? [];
		if ($allow && !in_array(self::lastExtension($name), $allow, true)) throw new NufError('err_name_ext', 400, [self::lastExtension($name) ?: '–', implode(', ', $allow)]);
	}

	/** Is this relative path hidden by the share's rules (deny patterns, dot files) or inside the nuFiles data folder? */
	public static function denied(array $share, array $segments, ?string $real = null): bool
	{
		if ($real !== null && NufConfig::$dataDir !== '' && self::inside(NufConfig::$dataDir, $real)) return true;
		$rel = strtolower(self::join($segments));
		foreach ($segments as $s) {
			if (str_starts_with(strtolower($s), '.nufiles')) return true;
			if (empty($share['show_hidden']) && str_starts_with($s, '.')) return true;
		}
		foreach ($share['deny'] ?? [] as $pat) {
			$pat = strtolower((string)$pat);
			if ($pat === '') continue;
			if (fnmatch($pat, $rel, FNM_PATHNAME)) return true;
			foreach ($segments as $s) if (fnmatch($pat, strtolower($s))) return true;
		}
		return false;
	}

	/**
	 * Resolves an existing path of a share. Returns the realpath; throws when it does not exist, is outside, or is denied.
	 * $allowRoot = false refuses the share root itself (rename / delete / move of the root).
	 */
	public static function existing(array $share, string $rel, bool $allowRoot = true): string
	{
		$seg = self::segments($rel);
		if (!$allowRoot && !$seg) throw new NufError('err_root');
		if (self::denied($share, $seg)) throw new NufError('err_not_found', 404);
		$abs = $share['root'] . ($seg ? '/' . self::join($seg) : '');
		clearstatcache(true, $abs);
		if (!file_exists($abs) && !is_link($abs)) throw new NufError('err_not_found', 404);
		$real = realpath($abs);
		if ($real === false || !self::inside($share['root'], $real)) throw new NufError('err_outside', 403);
		if (self::denied($share, $seg, $real)) throw new NufError('err_not_found', 404);
		return $real;
	}

	/** An existing folder of a share (realpath). */
	public static function folder(array $share, string $rel): string
	{
		$real = self::existing($share, $rel);
		if (!is_dir($real)) throw new NufError('err_not_folder');
		return $real;
	}

	/** Absolute path for a NEW entry $name in the existing folder $relDir (name checked, must not exist yet). */
	public static function fresh(array $share, string $relDir, string $name, bool $isDir): string
	{
		$dir = self::folder($share, $relDir);
		self::checkName($name, $share, $isDir);
		$seg = array_merge(self::segments($relDir), [$name]);
		if (self::denied($share, $seg)) throw new NufError('err_name_reserved');
		$abs = $dir . '/' . $name;
		if (file_exists($abs) || is_link($abs)) throw new NufError('err_exists', 409, [$name]);
		return $abs;
	}

	/** Relative path of an absolute path inside a share ('' = the root). */
	public static function rel(array $share, string $abs): string
	{
		$root = rtrim($share['root'], '/');
		return $abs === $root ? '' : ltrim(substr($abs, strlen($root)), '/');
	}

	/** "name (2).ext" – the first free name in $dir. */
	public static function unique(string $dir, string $name): string
	{
		if (!file_exists($dir . '/' . $name) && !is_link($dir . '/' . $name)) return $name;
		$dot = strpos($name, '.', 1);
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);
		for ($i = 2; $i < 10000; $i++) {
			$try = $stem . ' (' . $i . ')' . $ext;
			if (!file_exists($dir . '/' . $try) && !is_link($dir . '/' . $try)) return $try;
		}
		throw new NufError('err_exists', 409, [$name]);
	}

	/**
	 * Content check for uploads and unzip into a "files" share: PHP code hidden in a harmless-looking file (polyglots), and
	 * HTML / SVG when web_active_types is off. Reads the first 64 KB.
	 */
	public static function checkContent(string $file, array $share): void
	{
		if (($share['type'] ?? 'files') === 'code' || empty($share['content_check'])) return;
		$h = @fopen($file, 'rb');
		if (!$h) return;
		$head = (string)fread($h, 65536);
		fclose($h);
		self::checkText($head, $share);
	}

	/**
	 * The same check for text that is about to be saved (editor). "<?php" always; the short "<?=" only in text (no NUL byte) – three
	 * bytes turn up by chance in every few hundred binary files (images, PDFs), so binaries would be refused at random.
	 */
	public static function checkText(string $text, array $share): void
	{
		if (($share['type'] ?? 'files') === 'code' || empty($share['content_check'])) return;
		if (stripos($text, '<?php') !== false) throw new NufError('err_content_php');
		if (!str_contains(substr($text, 0, 65536), "\0") && str_contains($text, '<?=')) throw new NufError('err_content_php');
		if (empty($share['web_active_types']) && function_exists('finfo_buffer')) {
			$mime = (string)finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), substr($text, 0, 65536));
			if (in_array($mime, ['text/html', 'image/svg+xml', 'application/xhtml+xml', 'text/xml', 'application/xml'], true)) throw new NufError('err_content_web', 400, [$mime]);
		}
	}
}
