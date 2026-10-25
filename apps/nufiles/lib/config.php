<?php
defined('NU_PAGE') || exit;

/**
 * nuFiles – configuration from the page settings JSON (form builder → Settings), see settings.example.json and README.md.
 *
 * shares[]: id, label, path, type ("files" | "code"), readonly, extensions, max_upload_mb, show_hidden, web_active_types,
 *           content_check, editor, zip, trash, deny[], grants[] {permission | access, rights[]}
 * Paths may start with {DOCUMENT_ROOT}, {NUNEXT}, {NUBUILDER} or {PAGE}; the folder must exist.
 * Rights: view, upload, mkdir, edit, rename, copy, delete, zip. Every right includes view.
 *   - globeadmin: every right on every share (a readonly share stays read-only)
 *   - type "code": globeadmin only, grants are ignored (editing code = full server access)
 *   - other users: the union of the grants whose permission item (or access level code) they hold
 */
final class NufConfig
{
	public const RIGHTS = ['view', 'upload', 'mkdir', 'edit', 'rename', 'copy', 'delete', 'zip'];
	public const WRITES = ['upload', 'mkdir', 'edit', 'rename', 'copy', 'delete', 'zip'];

	/** Absolute data folder (trash, versions, tmp) – set by load(). */
	public static string $dataDir = '';
	/** @var array<string, array> share id => share with root (realpath) and rights of the current user */
	public static array $shares = [];
	/** Problems in the settings, shown to the globeadmin only. */
	public static array $problems = [];
	public static array $limits = [];

	public static function load(NuPageContext $nu): void
	{
		self::$problems = [];
		self::$limits = [
			'max_upload_mb'   => max(1, (int)$nu->setting('max_upload_mb', 200)),
			'chunk_mb'        => max(1, min(32, (int)$nu->setting('chunk_mb', 2))),
			'zip_max_mb'      => max(1, (int)$nu->setting('zip_max_mb', 500)),
			'zip_max_entries' => max(1, (int)$nu->setting('zip_max_entries', 5000)),
			'copy_max_mb'     => max(1, (int)$nu->setting('copy_max_mb', 2000)),
			'editor_max_kb'   => max(16, (int)$nu->setting('editor_max_kb', 2048)),
			'search_max'      => max(10, min(5000, (int)$nu->setting('search_max_results', 500))),
			'trash_days'      => max(0, (int)$nu->setting('trash_days', 30)),
			'versions_keep'   => max(0, (int)$nu->setting('versions_keep', 10)),
			'list_max'        => max(100, (int)$nu->setting('list_max_entries', 10000)),
		];
		self::$dataDir = self::dataDir($nu);

		$user = $nu->user();
		$ga = $nu->isGlobeadmin();
		$ro = $nu->readOnly();
		$perms = array_map('strtolower', $nu->permissions());
		$access = strtolower((string)($user['access_code'] ?? ''));

		self::$shares = [];
		$list = $nu->setting('shares', []);
		if (!is_array($list)) { self::$problems[] = 'shares must be a list'; $list = []; }
		foreach ($list as $i => $s) {
			if (!is_array($s)) { self::$problems[] = "shares[$i] is not an object"; continue; }
			$id = (string)($s['id'] ?? '');
			if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) || isset(self::$shares[$id])) { self::$problems[] = "shares[$i]: invalid or duplicate id \"$id\""; continue; }
			$type = ($s['type'] ?? 'files') === 'code' ? 'code' : 'files';
			$root = self::resolvePath($nu, (string)($s['path'] ?? ''));
			$share = [
				'id' => $id,
				'label' => trim((string)($s['label'] ?? '')) !== '' ? trim((string)$s['label']) : $id,
				'type' => $type,
				'readonly' => !empty($s['readonly']),
				'extensions' => self::extList($s['extensions'] ?? ''),
				'max_upload' => (int)(max(1, (int)($s['max_upload_mb'] ?? self::$limits['max_upload_mb'])) * 1048576),
				'show_hidden' => array_key_exists('show_hidden', $s) ? !empty($s['show_hidden']) : $type === 'code',
				'web_active_types' => !empty($s['web_active_types']),
				'content_check' => array_key_exists('content_check', $s) ? !empty($s['content_check']) : true,
				'editor' => array_key_exists('editor', $s) ? !empty($s['editor']) : true,
				'zip' => array_key_exists('zip', $s) ? !empty($s['zip']) : true,
				'trash' => array_key_exists('trash', $s) ? !empty($s['trash']) : true,
				'deny' => array_values(array_filter(array_map('strval', is_array($s['deny'] ?? null) ? $s['deny'] : []), 'strlen')),
				'root' => $root ?? '',
				'ok' => $root !== null,
			];
			if ($root === null) self::$problems[] = "share \"$id\": folder not found or not readable (" . (string)($s['path'] ?? '') . ')';
			elseif (self::$dataDir !== '' && NufGuard::inside(self::$dataDir, $root)) { self::$problems[] = "share \"$id\": lies inside the nuFiles data folder"; $share['ok'] = false; }

			// rights of the current user
			$rights = [];
			if ($ga) {
				$rights = self::RIGHTS;
			} elseif ($type === 'files') {
				foreach (is_array($s['grants'] ?? null) ? $s['grants'] : [] as $g) {
					if (!is_array($g)) continue;
					$match = false;
					foreach ((array)($g['permission'] ?? []) as $p) if (in_array(strtolower(trim((string)$p)), $perms, true)) $match = true;
					foreach ((array)($g['access'] ?? []) as $a) if ($access !== '' && strtolower(trim((string)$a)) === $access) $match = true;
					if ($match) $rights = array_merge($rights, array_intersect(self::RIGHTS, array_map('strval', (array)($g['rights'] ?? []))));
				}
				if ($rights) $rights[] = 'view';
			}
			if ($share['readonly'] || $ro) $rights = array_intersect($rights, ['view']);
			if (!$share['editor']) $rights = array_diff($rights, ['edit']);
			if (!$share['zip']) $rights = array_diff($rights, ['zip']);
			$share['rights'] = array_values(array_unique($rights));
			if (!in_array('view', $share['rights'], true)) continue;     // invisible
			if (!$share['ok'] && !$ga) continue;
			self::$shares[$id] = $share;
		}
	}

	/** The share of a request with the right $right – throws 404 / 403. */
	public static function share(string $id, string $right = 'view'): array
	{
		$s = self::$shares[$id] ?? null;
		if (!$s) throw new NufError('err_share', 404);
		if (!$s['ok']) throw new NufError('err_share_broken', 500);
		if (!in_array($right, $s['rights'], true)) throw new NufError($s['readonly'] && $right !== 'view' ? 'err_readonly' : 'err_right', 403, [$right]);
		return $s;
	}

	public static function can(array $share, string $right): bool
	{
		return in_array($right, $share['rights'], true);
	}

	/** {DOCUMENT_ROOT}, {NUNEXT}, {NUBUILDER}, {PAGE} + rest → realpath of an existing readable folder, or null. */
	public static function resolvePath(NuPageContext $nu, string $path): ?string
	{
		$path = trim($path);
		if ($path === '' || str_contains($path, "\0")) return null;
		$map = [
			'{DOCUMENT_ROOT}' => (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
			'{NUNEXT}' => defined('NU_ROOT') ? NU_ROOT : '',
			'{NUBUILDER}' => defined('NU_ROOT') ? dirname(NU_ROOT) : '',
			'{PAGE}' => $nu->dir(),
		];
		foreach ($map as $k => $v) {
			if (str_starts_with($path, $k)) { if ($v === '') return null; $path = rtrim($v, '/') . substr($path, strlen($k)); break; }
		}
		if (!str_starts_with($path, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $path)) return null;   // absolute only
		$real = realpath($path);
		if ($real === false || !is_dir($real) || !is_readable($real)) return null;
		return rtrim(str_replace('\\', '/', $real), '/') ?: '/';
	}

	/** Data folder: setting data_dir or <page>/_data; created with deny-all protection. '' when it cannot be created. */
	private static function dataDir(NuPageContext $nu): string
	{
		$d = trim((string)$nu->setting('data_dir', ''));
		if ($d !== '') {
			foreach (['{NUNEXT}' => defined('NU_ROOT') ? NU_ROOT : '', '{NUBUILDER}' => defined('NU_ROOT') ? dirname(NU_ROOT) : '', '{PAGE}' => $nu->dir()] as $k => $v) {
				if (str_starts_with($d, $k)) { $d = rtrim($v, '/') . substr($d, strlen($k)); break; }
			}
		} else {
			$d = (string)(NufApi::future($nu, 'dataDir') ?? $nu->dir() . '/_data');   // core change C4 when it exists
		}
		foreach (['', '/trash', '/versions', '/tmp'] as $sub) {
			if (!is_dir($d . $sub) && !@mkdir($d . $sub, 0770, true)) { self::$problems[] = 'data folder cannot be created: ' . $d . $sub; return ''; }
		}
		if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
		if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
		$real = realpath($d);
		return $real === false ? '' : rtrim(str_replace('\\', '/', $real), '/');
	}

	/** "pdf, .DOCX" or ["pdf","docx"] → ['pdf', 'docx'] */
	private static function extList(mixed $v): array
	{
		$items = is_array($v) ? $v : explode(',', (string)$v);
		return array_values(array_unique(array_filter(array_map(static fn($e) => strtolower(ltrim(trim((string)$e), '.')), $items), 'strlen')));
	}

	/** Public description of the shares for the browser (no absolute paths for users). */
	public static function publicShares(bool $ga): array
	{
		$out = [];
		foreach (self::$shares as $s) {
			$out[] = ['id' => $s['id'], 'label' => $s['label'], 'type' => $s['type'], 'readonly' => $s['readonly'], 'rights' => $s['rights'],
				'ok' => $s['ok'], 'extensions' => $s['extensions'], 'max_upload' => $s['max_upload'], 'trash' => $s['trash'],
				'root' => $ga ? $s['root'] : null];
		}
		return $out;
	}
}
