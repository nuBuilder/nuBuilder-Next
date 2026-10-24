<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Third-party libraries (0.8.229): the ones in the package (vendor/bundled/libraries.json since 0.8.409 – also copied into nunext-manifest.json
 * by dev/release.py as "libraries") and the ones copied by hand or used from nuBuilder (NuSecurity::libraries(): TCPDF, PHPMailer,
 * TinyMCE, phpMyAdmin, Tiny File Manager). Update page: card "Libraries" and, for a checked package, which libraries change.
 */
final class NuLibraries
{
	public const REGISTRY = NU_VENDOR . '/libraries.json';

	/** @return list<array{name: string, version: string, dir: string, file: string, detect: string, license: string, use: string}> */
	public static function registry(string $root = NU_ROOT): array
	{
		$j = json_decode((string)@file_get_contents($root . '/' . self::REGISTRY), true);
		return is_array($j) ? array_values(array_filter($j, 'is_array')) : [];
	}

	/** Version read from the library file itself ('' = no pattern or not found, null = file missing). */
	public static function detect(array $lib, string $root = NU_ROOT): ?string
	{
		$f = $root . '/' . NU_VENDOR . '/' . $lib['dir'] . '/' . $lib['file'];
		if (!is_file($f)) return null;
		if (($lib['detect'] ?? '') === '') return '';
		$src = (string)@file_get_contents($f, false, null, 0, 400000);
		return preg_match('/' . str_replace('/', '\/', (string)$lib['detect']) . '/', $src, $m) ? $m[1] : '';
	}

	/**
	 * All libraries for the card: bundled ones (status ok / missing / differs) and the external ones (ok / missing / old / warn).
	 * @return list<array{name: string, version: string, path: string, license: string, use: string, kind: string, status: string, note: string, local: string}>
	 */
	public static function all(): array
	{
		$out = [];
		foreach (self::registry() as $l) {
			$found = self::detect($l);
			$ver = $found === null ? '' : ($found !== '' ? $found : (string)$l['version']);
			$out[] = ['name' => (string)$l['name'], 'version' => $ver, 'path' => NU_VENDOR . '/' . $l['dir'] . '/', 'license' => (string)$l['license'],
				'use' => t('upd_libuse_' . $l['use']), 'kind' => 'bundled', 'note' => '', 'local' => '',
				'status' => $found === null ? 'missing' : ($found !== '' && $found !== (string)$l['version'] ? 'differs' : 'ok')];
		}
		foreach (NuSecurity::libraries() as $l) {
			$path = (string)$l['path'];
			if ($path !== '' && str_starts_with($path, NU_ROOT . '/')) $path = substr($path, strlen(NU_ROOT) + 1) . '/';
			elseif ($path !== '' && str_starts_with($path, dirname(NU_ROOT) . '/')) $path = '../' . substr($path, strlen(dirname(NU_ROOT)) + 1) . '/';   // nuBuilder's copy
			$use = ['TCPDF' => 'pdf', 'PHPMailer' => 'mail', 'TinyMCE' => 'wysiwyg', 'phpMyAdmin' => 'db', 'Tiny File Manager' => 'files', 'Uppy' => 'upload'][(string)$l['name']] ?? '';
			$out[] = ['name' => (string)$l['name'], 'version' => (string)$l['version'], 'path' => $path, 'license' => '', 'use' => $use !== '' ? t('upd_libuse_' . $use) : '', 'kind' => 'external',
				'status' => (string)$l['status'], 'note' => $l['status'] === 'old' ? (string)$l['min'] : (string)$l['note'], 'local' => (string)($l['local'] ?? '')];
		}
		return $out;
	}

	/**
	 * What a package changes: its manifest "libraries" against the installed registry.
	 * @return list<array{name: string, from: string, to: string}>  from '' = new, to '' = no longer in the package
	 */
	public static function changes(array $manifest): array
	{
		if (!isset($manifest['libraries']) || !is_array($manifest['libraries'])) return [];
		$now = [];
		foreach (self::registry() as $l) $now[(string)$l['name']] = (string)$l['version'];
		$out = [];
		foreach ($manifest['libraries'] as $l) {
			if (!is_array($l) || !isset($l['name'])) continue;
			$n = (string)$l['name'];
			$v = (string)($l['version'] ?? '');
			if (($now[$n] ?? '') !== $v) $out[] = ['name' => $n, 'from' => $now[$n] ?? '', 'to' => $v];
			unset($now[$n]);
		}
		foreach ($now as $n => $v) $out[] = ['name' => $n, 'from' => $v, 'to' => ''];
		return $out;
	}
}
