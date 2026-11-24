<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * A project package (NuProjectPackage, format nunext-project-1) as a folder for git: one folder per form, procedure, report …, the
 * item as item.json and every long piece of code beside it as its own file (code.php, browse_sql.sql …), referenced in item.json as
 * {"$file": "<name>"}. The same database gives the same files byte for byte – no export date, no version, sorted lists – so a git
 * diff shows only what changed. write() empties the folders it manages first (a form deleted in the database disappears from the
 * folder); everything else in the folder (README, .git …) is left alone.
 */
final class NuAppFolder
{
	public const MAIN = 'nunext-app.json';
	/** package list => folder, and the field whose value names an entry. */
	public const DIRS = [
		'forms'         => ['forms', 'form.sfo_code'],
		'procedure'     => ['procedures', 'sph_code'],
		'report'        => ['reports', 'sre_code'],
		'select'        => ['selects', 'sse_code'],
		'emailtemplate' => ['emailtemplates', 'emt_code'],
		'snippet'       => ['snippets', 'cot_code'],
		'permission'    => ['permissions', 'pme_ident'],
		'formats'       => ['formats', 'srm_format'],
		'file'          => ['files', 'sfi_code'],
		'dataexport'    => ['dataexports', 'code'],
		'webhook'       => ['webhooks', 'name'],
		'help'          => ['help', 'code'],
		'tables'        => ['tables', 'name'],
		'levels'        => ['accesslevels', 'code'],
		'settings'      => ['settings', 'part'],
		'pages'         => ['pages', 'code'],
	];
	/** A text this long with a line break goes into a file of its own. */
	private const CODE_MIN = 60;
	/** Keys that are left out of the folder: they change with every export and would make every file "changed". */
	private const VOLATILE = ['exported', 'version', 'by'];

	/** Writes the package into $dir (made when missing). @return array{files: int, dirs: list<string>} */
	public static function write(string $dir, array $pkg): array
	{
		$dir = rtrim($dir, '/\\');
		if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Cannot create ' . $dir);
		$files = 0;
		$used = [];
		foreach (self::DIRS as $list => [$sub, $nameKey]) {
			self::rmTree($dir . '/' . $sub);
			$rows = array_values(array_filter((array)($pkg[$list] ?? []), 'is_array'));
			if (!$rows) continue;
			$names = [];
			foreach ($rows as $r) $names[] = [self::entryName($r, $nameKey, $list), $r];
			usort($names, static fn($a, $b) => strcmp($a[0], $b[0]));
			foreach ($names as [$name, $r]) {
				$n = $name;
				for ($i = 2; isset($used[$sub][$n]); $i++) $n = $name . '~' . $i;
				$used[$sub][$n] = true;
				$edir = $dir . '/' . $sub . '/' . $n;
				if (!@mkdir($edir, 0775, true) && !is_dir($edir)) throw new RuntimeException('Cannot create ' . $edir);
				$codeFiles = [];
				if ($list === 'pages') {                       // a custom page: its files as they are, below files/
					foreach ((array)($r['files'] ?? []) as $rel => $f) {
						if (!NuPageVersion::safePath((string)$rel) || !is_array($f)) continue;
						$dst = $edir . '/files/' . $rel;
						if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0775, true)) throw new RuntimeException('Cannot create ' . dirname($dst));
						if (@file_put_contents($dst, NuPageVersion::bytes($f)) === false) throw new RuntimeException('Cannot write ' . $dst);
						$files++;
					}
					$r = ['code' => (string)($r['code'] ?? ''), 'files' => '$folder files'];
				}
				$item = self::extract($r, '', $codeFiles);
				foreach ($codeFiles as $fn => $text) { self::put($edir . '/' . $fn, $text); $files++; }
				self::put($edir . '/item.json', self::json($item));
				$files++;
			}
		}
		$main = [];
		foreach ($pkg as $k => $v) {
			if (isset(self::DIRS[$k]) || in_array($k, self::VOLATILE, true)) continue;
			$main[$k] = $v;
		}
		foreach (['items', 'rights', 'translations', 'left_out'] as $k) {
			if (is_array($main[$k] ?? null)) usort($main[$k], static fn($a, $b) => strcmp(self::json($a), self::json($b)));
		}
		if (is_array($main['help_ids'] ?? null)) ksort($main['help_ids'], SORT_STRING);
		self::put($dir . '/' . self::MAIN, self::json($main));
		return ['files' => $files + 1, 'dirs' => array_values(array_unique(array_column(self::DIRS, 0)))];
	}

	/** Reads a folder written by write() back into a package (format checked by NuProjectPackage::read). */
	public static function read(string $dir): array
	{
		$dir = rtrim($dir, '/\\');
		$main = is_file($dir . '/' . self::MAIN) ? json_decode((string)file_get_contents($dir . '/' . self::MAIN), true) : null;
		if (!is_array($main)) throw new RuntimeException('Not a nuBuilder Next application folder (no ' . self::MAIN . '): ' . $dir);
		$pkg = $main + ['version' => NU_VERSION, 'exported' => date('c'), 'by' => ''];
		foreach (self::DIRS as $list => [$sub]) {
			$pkg[$list] = [];
			$entries = is_dir($dir . '/' . $sub) ? (glob($dir . '/' . $sub . '/*/item.json') ?: []) : [];
			sort($entries, SORT_STRING);
			foreach ($entries as $f) {
				$item = json_decode((string)file_get_contents($f), true);
				if (!is_array($item)) throw new RuntimeException('Not valid JSON: ' . $f);
				if ($list === 'pages') { $pkg[$list][] = self::readPage($item, dirname($f) . '/files'); continue; }
				$pkg[$list][] = self::resolve($item, dirname($f));
			}
		}
		[$p, $err] = NuProjectPackage::read((string)json_encode($pkg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
		if (!$p) throw new RuntimeException($err);
		return $p;
	}

	// ------------------------------------------------------------------ helpers

	/** A custom page of the folder: the files below files/ as the package keeps them. */
	private static function readPage(array $item, string $dir): array
	{
		$files = [];
		if (is_dir($dir)) {
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if (!$f->isFile() || $f->isLink()) continue;
				$rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
				if (!NuPageVersion::safePath($rel)) continue;
				$b = (string)file_get_contents($f->getPathname());
				$files[$rel] = mb_check_encoding($b, 'UTF-8') && !str_contains($b, "\0") ? ['t' => $b] : ['b64' => base64_encode($b)];
			}
		}
		ksort($files, SORT_STRING);
		return ['code' => (string)($item['code'] ?? ''), 'files' => $files];
	}

	/** Folder name of an entry: the value of its name field, reduced to safe characters. */
	private static function entryName(array $r, string $key, string $list): string
	{
		$v = $r;
		foreach (explode('.', $key) as $part) $v = is_array($v) ? ($v[$part] ?? null) : null;
		$name = is_scalar($v) ? (string)$v : '';
		if ($name === '') {                               // no name: the id (first column ending in _id)
			$src = $list === 'forms' ? (array)($r['form'] ?? []) : $r;
			foreach ($src as $k => $x) if (str_ends_with((string)$k, '_id') && is_scalar($x)) { $name = (string)$x; break; }
		}
		$name = trim((string)preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name), '._');
		return $name === '' ? 'item' : substr($name, 0, 80);
	}

	/** Moves long multi-line strings out of $v into $files (name => text). */
	private static function extract(mixed $v, string $path, array &$files): mixed
	{
		if (is_array($v)) {
			$out = [];
			foreach ($v as $k => $x) {
				// rows of a list are named by their id, not their position: a new event does not rename the files of the others
				$seg = is_int($k) && is_array($x) ? self::rowName($x, $k) : (string)$k;
				$out[$k] = self::extract($x, $path === '' ? $seg : $path . '.' . $seg, $files);
			}
			return $out;
		}
		if (!is_string($v) || strlen($v) < self::CODE_MIN || !str_contains($v, "\n")) return $v;
		$base = trim((string)preg_replace('/[^A-Za-z0-9_.-]+/', '_', $path), '._') ?: 'text';
		$name = substr($base, 0, 120) . '.' . self::ext($path, $v);
		for ($i = 2; isset($files[$name]); $i++) $name = substr($base, 0, 120) . '~' . $i . '.' . self::ext($path, $v);
		$files[$name] = $v;
		return ['$file' => $name];
	}

	/** Name of a row inside a list: its object id (sob_all_id), else the value of its first *_id column, else the position. */
	private static function rowName(array $row, int $pos): string
	{
		if (is_string($row['sob_all_id'] ?? null) && $row['sob_all_id'] !== '') return $row['sob_all_id'];
		foreach ($row as $k => $x) if (str_ends_with((string)$k, '_id') && is_scalar($x) && (string)$x !== '') return (string)$x;
		return (string)$pos;
	}

	/** File type of a code text by its field name (and a first look at the text). */
	private static function ext(string $path, string $v): string
	{
		$p = strtolower($path);
		$last = (string)substr($p, (int)strrpos('.' . $p, '.'));
		return match (true) {
			str_contains($last, 'sql') || $last === 'create' => 'sql',
			str_contains($last, 'javascript') || str_ends_with($last, '_js') => 'js',
			str_contains($last, 'style') || str_ends_with($last, 'css') => 'css',
			str_contains($last, 'php') || str_starts_with($p, 'php.') => 'php',
			str_contains($last, 'html') || str_contains($last, 'body') => 'html',
			str_starts_with(ltrim($v), '{') || str_starts_with(ltrim($v), '[') => 'json',
			default => 'txt',
		};
	}

	/** Puts the texts of {"$file": name} back (names inside the entry's own folder only). */
	private static function resolve(mixed $v, string $edir): mixed
	{
		if (!is_array($v)) return $v;
		if (count($v) === 1 && isset($v['$file']) && is_string($v['$file'])) {
			$n = $v['$file'];
			if (!preg_match('/^[A-Za-z0-9_.~-]{1,200}$/', $n) || str_contains($n, '..') || !is_file($edir . '/' . $n)) {
				throw new RuntimeException('Missing file ' . $n . ' in ' . $edir);
			}
			return (string)file_get_contents($edir . '/' . $n);
		}
		foreach ($v as $k => $x) $v[$k] = self::resolve($x, $edir);
		return $v;
	}

	private static function json(mixed $v): string
	{
		return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
	}

	private static function put(string $file, string $text): void
	{
		if (@file_put_contents($file, $text) === false) throw new RuntimeException('Cannot write ' . $file);
	}

	/** Removes a folder that write() manages (no links followed). */
	private static function rmTree(string $d): void
	{
		if (is_link($d)) { @unlink($d); return; }
		if (!is_dir($d)) return;
		foreach (scandir($d) ?: [] as $f) {
			if ($f === '.' || $f === '..') continue;
			$p = $d . '/' . $f;
			if (is_dir($p) && !is_link($p)) self::rmTree($p); else @unlink($p);
		}
		@rmdir($d);
	}
}
