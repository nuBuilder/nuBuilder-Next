<?php
defined('NU_PAGE') || exit;

/**
 * nuFiles – file system operations. All paths given here are already resolved by NufGuard (realpaths inside a share root),
 * except where a method takes a share + relative path itself. Nothing here trusts the browser.
 */
final class NufFs
{
	/** Text types the editor opens (others: if the content is valid UTF-8 without NUL bytes). */
	public const TEXT_EXT = ['txt', 'md', 'csv', 'tsv', 'log', 'json', 'xml', 'html', 'htm', 'css', 'scss', 'less', 'js', 'mjs', 'ts', 'php', 'phtml', 'inc',
		'sql', 'ini', 'conf', 'cfg', 'yml', 'yaml', 'env', 'htaccess', 'sh', 'py', 'rb', 'pl', 'java', 'c', 'h', 'cpp', 'cs', 'go', 'vue', 'svg', 'tpl', 'twig', 'bat', 'ps1', 'properties', 'gitignore'];
	/** Inline previews: sniffed MIME types that a browser shows without running code. */
	public const INLINE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif', 'image/x-icon', 'image/vnd.microsoft.icon',
		'application/pdf', 'video/mp4', 'video/webm', 'video/ogg', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/mp4', 'audio/flac', 'text/plain'];

	// ============================================================ listing and search

	public static function entry(array $share, string $abs, string $name): array
	{
		$isLink = is_link($abs);
		$real = $isLink ? realpath($abs) : $abs;
		$outside = $isLink && ($real === false || !NufGuard::inside($share['root'], $real));
		$isDir = !$outside && is_dir($abs);
		$st = @lstat($abs) ?: [];
		$size = $outside ? 0 : ($isDir ? 0 : (int)@filesize($abs));
		return [
			'name' => $name, 'type' => $isDir ? 'dir' : 'file', 'link' => $isLink, 'outside' => $outside,
			'size' => $size, 'mtime' => (int)($st['mtime'] ?? 0), 'ext' => $isDir ? '' : NufGuard::lastExtension($name),
			'writable' => !$outside && is_writable($abs), 'perms' => isset($st['mode']) ? substr(sprintf('%o', $st['mode']), -4) : '',
		];
	}

	public static function listDir(array $share, string $rel, int $max): array
	{
		$dir = NufGuard::folder($share, $rel);
		$seg = NufGuard::segments($rel);
		$items = [];
		$truncated = false;
		$h = @opendir($dir);
		if (!$h) throw new NufError('err_read', 500);
		while (($n = readdir($h)) !== false) {
			if ($n === '.' || $n === '..') continue;
			$abs = $dir . '/' . $n;
			$real = is_link($abs) ? (realpath($abs) ?: null) : $abs;
			if (NufGuard::denied($share, array_merge($seg, [$n]), $real)) continue;
			if (count($items) >= $max) { $truncated = true; break; }
			$items[] = self::entry($share, $abs, $n);
		}
		closedir($h);
		usort($items, static fn($a, $b) => [$a['type'] !== 'dir', mb_strtolower($a['name'])] <=> [$b['type'] !== 'dir', mb_strtolower($b['name'])]);
		return ['path' => NufGuard::join($seg), 'items' => $items, 'truncated' => $truncated,
			'writable' => is_writable($dir), 'free' => @disk_free_space($dir) ?: null];
	}

	/** Name search below $rel: case-insensitive part of the name, or a pattern with * and ?. No symlinks followed. */
	public static function search(array $share, string $rel, string $q, int $max): array
	{
		$start = NufGuard::folder($share, $rel);
		$q = trim($q);
		if ($q === '' || mb_strlen($q) > 200) throw new NufError('err_search');
		$glob = strpbrk($q, '*?') !== false;
		$needle = mb_strtolower($q);
		$found = [];
		$stack = [[$start, NufGuard::segments($rel), 0]];
		$t0 = microtime(true);
		$scanned = 0;
		$partial = false;
		while ($stack) {
			[$dir, $seg, $depth] = array_pop($stack);
			$h = @opendir($dir);
			if (!$h) continue;
			while (($n = readdir($h)) !== false) {
				if ($n === '.' || $n === '..') continue;
				$abs = $dir . '/' . $n;
				$s = array_merge($seg, [$n]);
				if (NufGuard::denied($share, $s, $abs)) continue;
				if (++$scanned % 500 === 0 && microtime(true) - $t0 > 8) { $partial = true; break 2; }
				$hit = $glob ? fnmatch($needle, mb_strtolower($n)) : str_contains(mb_strtolower($n), $needle);
				if ($hit) {
					$e = self::entry($share, $abs, $n);
					$e['dir'] = NufGuard::join($seg);
					$found[] = $e;
					if (count($found) >= $max) { $partial = true; break 2; }
				}
				if (!is_link($abs) && is_dir($abs) && $depth < 25) $stack[] = [$abs, $s, $depth + 1];
			}
			closedir($h);
		}
		return ['items' => $found, 'partial' => $partial, 'scanned' => $scanned];
	}

	// ============================================================ create, rename, move, copy

	public static function mkdir(array $share, string $relDir, string $name): string
	{
		$abs = NufGuard::fresh($share, $relDir, $name, true);
		if (!@mkdir($abs, 0775)) throw new NufError('err_write', 500);
		return $abs;
	}

	public static function newFile(array $share, string $relDir, string $name): string
	{
		$abs = NufGuard::fresh($share, $relDir, $name, false);
		$h = @fopen($abs, 'x');             // x = fails when it exists (no race)
		if (!$h) throw new NufError('err_write', 500);
		fclose($h);
		return $abs;
	}

	/** Rename in place. */
	public static function rename(array $share, string $rel, string $newName): array
	{
		$src = NufGuard::existing($share, $rel, false);
		$isDir = is_dir($src) && !is_link($src);
		$seg = NufGuard::segments($rel);
		array_pop($seg);
		$dst = NufGuard::fresh($share, NufGuard::join($seg), $newName, $isDir);
		if (!@rename($src, $dst)) throw new NufError('err_write', 500);
		return [$src, $dst];
	}

	/**
	 * Moves or copies $rels (relative to $from) into the folder $toRel of share $to. Existing names get " (2)".
	 * @return list<array{0:string,1:string}> [source rel, target rel]
	 */
	public static function transfer(array $from, array $rels, array $to, string $toRel, bool $copy, array $limits): array
	{
		$target = NufGuard::folder($to, $toRel);
		$done = [];
		$budget = ['files' => 0, 'bytes' => 0, 'max_files' => $limits['zip_max_entries'] * 10, 'max_bytes' => $limits['copy_max_mb'] * 1048576];
		$cross = $from['id'] !== $to['id'];                                           // into another share: its content rules apply too
		foreach ($rels as $rel) {
			$src = NufGuard::existing($from, (string)$rel, false);
			$name = basename($src);
			$isDir = is_dir($src) && !is_link($src);
			NufGuard::checkName($name, $to, $isDir);
			if ($isDir && NufGuard::inside($src, $target)) throw new NufError('err_into_self');
			if (!$copy && dirname($src) === $target) continue;                         // already there
			$dstName = NufGuard::unique($target, $name);
			$dst = $target . '/' . $dstName;
			if (!$isDir && $cross) NufGuard::checkContent($src, $to);
			if ($copy) {
				if ($isDir) self::checkTree($src, $to, $budget, $cross);
				self::copyAny($src, $dst, $budget);
			} else {
				$free = ['files' => 0, 'bytes' => 0, 'max_files' => PHP_INT_MAX, 'max_bytes' => PHP_INT_MAX];
				if ($isDir) self::checkTree($src, $to, $free, $cross);
				if (!@rename($src, $dst)) {                                             // other file system: copy, then delete
					self::copyAny($src, $dst, $budget);
					self::removeTree($src);
				}
			}
			$done[] = [NufGuard::rel($from, $src), NufGuard::rel($to, $dst)];
		}
		return $done;
	}

	/** Every name inside a folder that goes to another share must pass that share's rules (no .php smuggled in via a folder). */
	private static function checkTree(string $dir, array $share, array &$budget, bool $content = false): void
	{
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $f) {
			/** @var SplFileInfo $f */
			if ($f->isLink()) throw new NufError('err_link_in_tree');
			NufGuard::checkName($f->getFilename(), $share, $f->isDir());
			if ($content && $f->isFile()) NufGuard::checkContent($f->getPathname(), $share);
			if (++$budget['files'] > $budget['max_files']) throw new NufError('err_too_many');
		}
	}

	private static function copyAny(string $src, string $dst, array &$budget): void
	{
		if (is_link($src)) throw new NufError('err_link_in_tree');
		if (is_dir($src)) {
			if (!@mkdir($dst, 0775)) throw new NufError('err_write', 500);
			foreach (scandir($src) ?: [] as $n) {
				if ($n === '.' || $n === '..') continue;
				self::copyAny($src . '/' . $n, $dst . '/' . $n, $budget);
			}
			return;
		}
		$budget['bytes'] += (int)filesize($src);
		if ($budget['bytes'] > $budget['max_bytes']) throw new NufError('err_too_big');
		if (!@copy($src, $dst)) throw new NufError('err_write', 500);
	}

	public static function removeTree(string $path): void
	{
		if (is_link($path) || !is_dir($path)) { if (!@unlink($path)) throw new NufError('err_write', 500); return; }
		foreach (scandir($path) ?: [] as $n) {
			if ($n === '.' || $n === '..') continue;
			self::removeTree($path . '/' . $n);
		}
		if (!@rmdir($path)) throw new NufError('err_write', 500);
	}

	public static function treeSize(string $path, int $cap = 100000): array
	{
		if (!is_dir($path) || is_link($path)) return ['files' => 1, 'bytes' => (int)@filesize($path)];
		$files = 0; $bytes = 0;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) { $files++; $bytes += (int)$f->getSize(); if ($files > $cap) break; }
		return ['files' => $files, 'bytes' => $bytes];
	}

	// ============================================================ trash

	/**
	 * Deletes: into the trash (data folder) when the share has trash on, else for good.
	 * @return string the trash id ('' when deleted for good)
	 */
	public static function delete(array $share, string $rel, array $user): string
	{
		$src = NufGuard::existing($share, $rel, false);
		if (!$share['trash'] || NufConfig::$dataDir === '') { self::removeTree($src); return ''; }
		$id = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
		$box = NufConfig::$dataDir . '/trash/' . $id;
		if (!@mkdir($box, 0770)) throw new NufError('err_write', 500);
		$size = self::treeSize($src);
		$meta = ['id' => $id, 'share' => $share['id'], 'path' => NufGuard::rel($share, $src), 'name' => basename($src),
			'type' => is_dir($src) && !is_link($src) ? 'dir' : 'file', 'deleted_at' => time(), 'user' => $user['login'] ?: $user['id'],
			'files' => $size['files'], 'bytes' => $size['bytes']];
		$dst = $box . '/item';
		if (!@rename($src, $dst)) {
			$b = ['files' => 0, 'bytes' => 0, 'max_files' => PHP_INT_MAX, 'max_bytes' => PHP_INT_MAX];
			self::copyAny($src, $dst, $b);
			self::removeTree($src);
		}
		file_put_contents($box . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		return $id;
	}

	/** Trash entries of the given share ids (null = all). */
	public static function trashList(?array $shareIds): array
	{
		$out = [];
		if (NufConfig::$dataDir === '') return $out;
		foreach (glob(NufConfig::$dataDir . '/trash/*/meta.json') ?: [] as $m) {
			$meta = json_decode((string)@file_get_contents($m), true);
			if (!is_array($meta)) continue;
			if ($shareIds !== null && !in_array($meta['share'] ?? '', $shareIds, true)) continue;
			$out[] = $meta;
		}
		usort($out, static fn($a, $b) => ($b['deleted_at'] ?? 0) <=> ($a['deleted_at'] ?? 0));
		return $out;
	}

	public static function trashMeta(string $id): array
	{
		if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{12}$/', $id)) throw new NufError('err_not_found', 404);
		$meta = json_decode((string)@file_get_contents(NufConfig::$dataDir . '/trash/' . $id . '/meta.json'), true);
		if (!is_array($meta)) throw new NufError('err_not_found', 404);
		return $meta;
	}

	/** Back to the original folder (or the share root if that is gone); a taken name gets " (2)". */
	public static function restore(array $share, array $meta): string
	{
		$box = NufConfig::$dataDir . '/trash/' . $meta['id'];
		$seg = NufGuard::segments((string)$meta['path']);
		$name = array_pop($seg);
		try { $dir = NufGuard::folder($share, NufGuard::join($seg)); } catch (NufError) { $dir = $share['root']; }
		NufGuard::checkName((string)$name, $share, ($meta['type'] ?? '') === 'dir');
		$dst = $dir . '/' . NufGuard::unique($dir, (string)$name);
		if (!@rename($box . '/item', $dst)) {
			$b = ['files' => 0, 'bytes' => 0, 'max_files' => PHP_INT_MAX, 'max_bytes' => PHP_INT_MAX];
			self::copyAny($box . '/item', $dst, $b);
			self::removeTree($box . '/item');
		}
		self::removeTree($box);
		return $dst;
	}

	public static function purge(string $id): void
	{
		self::trashMeta($id);
		self::removeTree(NufConfig::$dataDir . '/trash/' . $id);
	}

	/** Housekeeping, at most once per hour: trash older than trash_days, versions beyond versions_keep, upload chunks older than 1 day. */
	public static function housekeeping(array $limits): void
	{
		$d = NufConfig::$dataDir;
		if ($d === '') return;
		$stamp = $d . '/.nufiles-housekeeping';
		if (is_file($stamp) && filemtime($stamp) > time() - 3600) return;
		@touch($stamp);
		if ($limits['trash_days'] > 0) {
			foreach (self::trashList(null) as $m) {
				if (($m['deleted_at'] ?? time()) < time() - $limits['trash_days'] * 86400) { try { self::purge((string)$m['id']); } catch (Throwable) {} }
			}
		}
		foreach (glob($d . '/tmp/*') ?: [] as $f) if (@filemtime($f) < time() - 86400) @unlink($f);
	}

	// ============================================================ text editor

	public static function isText(string $abs, int $maxBytes): array
	{
		$size = (int)filesize($abs);
		if ($size > $maxBytes) return [false, 'too_big'];
		$data = (string)file_get_contents($abs);
		if (str_contains($data, "\0")) return [false, 'binary'];
		if (!mb_check_encoding($data, 'UTF-8')) return [false, 'encoding'];
		return [true, $data];
	}

	/**
	 * Saves text atomically: the old version goes to versions/ first, the new content is written to a temporary file in the same
	 * folder and renamed over the old one (keeps the permissions). $expectSha = sha1 of the content the editor loaded ('' = new).
	 */
	public static function save(array $share, string $abs, string $content, string $expectSha, bool $force, int $keep): array
	{
		clearstatcache(true, $abs);
		$exists = is_file($abs);
		if ($exists) {
			$current = (string)file_get_contents($abs);
			if (!$force && $expectSha !== '' && sha1($current) !== $expectSha) throw new NufError('err_conflict', 409);
			if ($keep > 0) self::keepVersion($share, $abs, $current, $keep);
		}
		$dir = dirname($abs);
		$tmp = $dir . '/.nufiles-' . bin2hex(random_bytes(6)) . '.tmp';
		if (@file_put_contents($tmp, $content, LOCK_EX) === false) throw new NufError('err_write', 500);
		if ($exists) @chmod($tmp, fileperms($abs) & 0777);
		if (!@rename($tmp, $abs)) { @unlink($tmp); throw new NufError('err_write', 500); }
		clearstatcache(true, $abs);
		return ['sha' => sha1($content), 'size' => strlen($content), 'mtime' => (int)filemtime($abs)];
	}

	private static function keepVersion(array $share, string $abs, string $current, int $keep): void
	{
		if (NufConfig::$dataDir === '') return;
		$dir = NufConfig::$dataDir . '/versions/' . $share['id'] . '/' . sha1(NufGuard::rel($share, $abs));
		if (!is_dir($dir) && !@mkdir($dir, 0770, true)) return;
		$us = sprintf('%06d', (int)(fmod(microtime(true), 1) * 1000000));      // two saves in one second keep their order
		@file_put_contents($dir . '/' . date('Ymd-His') . '-' . $us . '-' . substr(sha1($current), 0, 8) . '.bak', $current);
		@file_put_contents($dir . '/path.txt', NufGuard::rel($share, $abs));
		$all = glob($dir . '/*.bak') ?: [];
		sort($all);
		while (count($all) > $keep) @unlink(array_shift($all));
	}

	public static function versions(array $share, string $abs): array
	{
		$dir = NufConfig::$dataDir . '/versions/' . $share['id'] . '/' . sha1(NufGuard::rel($share, $abs));
		$out = [];
		foreach (array_reverse(glob($dir . '/*.bak') ?: []) as $f) $out[] = ['id' => basename($f, '.bak'), 'size' => (int)filesize($f), 'mtime' => (int)filemtime($f)];
		return $out;
	}

	public static function versionContent(array $share, string $abs, string $id): string
	{
		if (!preg_match('/^\d{8}-\d{6}-\d{6}-[0-9a-f]{8}$/', $id)) throw new NufError('err_not_found', 404);
		$f = NufConfig::$dataDir . '/versions/' . $share['id'] . '/' . sha1(NufGuard::rel($share, $abs)) . '/' . $id . '.bak';
		if (!is_file($f)) throw new NufError('err_not_found', 404);
		return (string)file_get_contents($f);
	}

	/** PHP syntax check without exec(): token_get_all with TOKEN_PARSE throws ParseError. JSON: json_decode. */
	public static function lint(string $name, string $content): ?array
	{
		$ext = NufGuard::lastExtension($name);
		if (in_array($ext, ['php', 'phtml', 'inc', 'php8', 'php7'], true)) {
			try { token_get_all($content, TOKEN_PARSE); } catch (ParseError $e) { return ['line' => $e->getLine(), 'message' => $e->getMessage()]; }
		}
		if ($ext === 'json' && trim($content) !== '') {
			json_decode($content);
			if (json_last_error() !== JSON_ERROR_NONE) return ['line' => 0, 'message' => json_last_error_msg()];
		}
		return null;
	}

	// ============================================================ uploads in chunks

	/** Starts an upload: checks name, size and rights, writes a ticket into tmp/. */
	public static function uploadInit(array $share, string $relDir, string $name, int $size, bool $overwrite, array $user): array
	{
		$dir = NufGuard::folder($share, $relDir);
		NufGuard::checkName($name, $share, false);
		if (NufGuard::denied($share, array_merge(NufGuard::segments($relDir), [$name]))) throw new NufError('err_name_reserved');
		if ($size < 0 || $size > $share['max_upload']) throw new NufError('err_too_big_upload', 413, [round($share['max_upload'] / 1048576)]);
		$free = @disk_free_space($dir);
		if ($free !== false && $size > $free - 50 * 1048576) throw new NufError('err_disk_full', 507);
		if (NufConfig::$dataDir === '') throw new NufError('err_data_dir', 500);
		$token = bin2hex(random_bytes(16));
		$ticket = ['share' => $share['id'], 'dir' => NufGuard::rel($share, $dir), 'name' => $name, 'size' => $size, 'overwrite' => $overwrite,
			'user' => $user['id'], 'created' => time()];
		file_put_contents(NufConfig::$dataDir . '/tmp/' . $token . '.json', json_encode($ticket));
		touch(NufConfig::$dataDir . '/tmp/' . $token . '.part');
		return ['token' => $token];
	}

	public static function ticket(string $token, array $user): array
	{
		if (!preg_match('/^[0-9a-f]{32}$/', $token)) throw new NufError('err_upload_ticket', 400);
		$t = json_decode((string)@file_get_contents(NufConfig::$dataDir . '/tmp/' . $token . '.json'), true);
		if (!is_array($t) || ($t['user'] ?? null) !== $user['id']) throw new NufError('err_upload_ticket', 400);
		return $t;
	}

	/**
	 * Appends one chunk (uploaded file field "chunk") at $offset. When the last byte is in, the file is checked and moved into place.
	 * @return array{done:bool, received:int, path?:string, name?:string, replaced?:bool}
	 */
	public static function uploadChunk(array $share, string $token, int $offset, array $file, array $ticket, int $chunkMax): array
	{
		$part = NufConfig::$dataDir . '/tmp/' . $token . '.part';
		clearstatcache(true, $part);
		if (!is_file($part)) throw new NufError('err_upload_ticket');
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new NufError('err_upload_php', 400, [(int)($file['error'] ?? -1)]);
		$len = (int)$file['size'];
		if ($len <= 0 || $len > $chunkMax) throw new NufError('err_upload_chunk');
		if ($offset !== (int)filesize($part)) throw new NufError('err_upload_offset', 409, [(int)filesize($part)]);
		if ($offset + $len > (int)$ticket['size']) throw new NufError('err_upload_chunk');
		$in = fopen((string)$file['tmp_name'], 'rb');
		$out = fopen($part, 'ab');
		if (!$in || !$out) throw new NufError('err_write', 500);
		stream_copy_to_stream($in, $out);
		fclose($in); fclose($out);
		clearstatcache(true, $part);
		$received = (int)filesize($part);
		if ($received < (int)$ticket['size']) return ['done' => false, 'received' => $received];
		return self::uploadFinish($share, $token, $ticket);
	}

	/** Empty files have no chunk: finish right after init. */
	public static function uploadFinish(array $share, string $token, array $ticket): array
	{
		$part = NufConfig::$dataDir . '/tmp/' . $token . '.part';
		try {
			NufGuard::checkContent($part, $share);
			$dir = NufGuard::folder($share, (string)$ticket['dir']);                      // again: the folder may be gone / moved
			NufGuard::checkName((string)$ticket['name'], $share, false);
			$name = (string)$ticket['name'];
			$replaced = false;
			$dst = $dir . '/' . $name;
			if (file_exists($dst) || is_link($dst)) {
				if ($ticket['overwrite'] && is_file($dst) && !is_link($dst)) {
					$replaced = true;
				} else {
					$name = NufGuard::unique($dir, $name);
					$dst = $dir . '/' . $name;
				}
			}
			if ($replaced && $share['trash'] && NufConfig::$dataDir !== '') {
				$tmpCopy = $dst . '.nufiles-old';
				@copy($dst, $tmpCopy);
			}
			if (!@rename($part, $dst)) { if (!@copy($part, $dst)) throw new NufError('err_write', 500); @unlink($part); }
			@chmod($dst, 0664);
			if (isset($tmpCopy) && is_file($tmpCopy)) {
				// keep the replaced file in the trash
				try { self::trashFile($share, $tmpCopy, NufGuard::rel($share, $dst)); } catch (Throwable) { @unlink($tmpCopy); }
			}
			return ['done' => true, 'received' => (int)$ticket['size'], 'path' => NufGuard::rel($share, $dst), 'name' => $name, 'replaced' => $replaced];
		} finally {
			@unlink(NufConfig::$dataDir . '/tmp/' . $token . '.json');
			if (is_file($part)) @unlink($part);
		}
	}

	/** A loose file (the old version of an overwritten upload) into the trash under the path it had. */
	private static function trashFile(array $share, string $file, string $rel): void
	{
		$id = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
		$box = NufConfig::$dataDir . '/trash/' . $id;
		mkdir($box, 0770);
		rename($file, $box . '/item');
		file_put_contents($box . '/meta.json', json_encode(['id' => $id, 'share' => $share['id'], 'path' => $rel, 'name' => basename($rel),
			'type' => 'file', 'deleted_at' => time(), 'user' => 'upload (replaced)', 'files' => 1, 'bytes' => (int)filesize($box . '/item')]));
	}

	// ============================================================ zip

	/** Zips $rels (relative to the share) into a new archive $name in the folder $relDir. */
	public static function zip(array $share, string $relDir, array $rels, string $name, array $limits): string
	{
		if (!class_exists('ZipArchive')) throw new NufError('err_no_zip', 500);
		if (!str_ends_with(strtolower($name), '.zip')) $name .= '.zip';
		$dir = NufGuard::folder($share, $relDir);
		NufGuard::checkName($name, $share, false);
		$dst = $dir . '/' . NufGuard::unique($dir, $name);
		self::zipTo($share, $rels, $dst, $limits);
		return $dst;
	}

	/** Builds a zip file at $dst from share entries (skips symlinks and denied entries). */
	public static function zipTo(array $share, array $rels, string $dst, array $limits): void
	{
		$zip = new ZipArchive();
		if ($zip->open($dst, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new NufError('err_write', 500);
		$files = 0; $bytes = 0;
		$add = function (string $abs, string $inZip, array $seg) use (&$add, $zip, $share, &$files, &$bytes, $limits): void {
			if (is_link($abs) || NufGuard::denied($share, $seg, $abs)) return;
			if (++$files > $limits['zip_max_entries']) throw new NufError('err_too_many');
			if (is_dir($abs)) {
				$zip->addEmptyDir($inZip);
				foreach (scandir($abs) ?: [] as $n) {
					if ($n === '.' || $n === '..') continue;
					$add($abs . '/' . $n, $inZip . '/' . $n, array_merge($seg, [$n]));
				}
				return;
			}
			$bytes += (int)filesize($abs);
			if ($bytes > $limits['zip_max_mb'] * 1048576) throw new NufError('err_too_big');
			$zip->addFile($abs, $inZip);
		};
		try {
			foreach ($rels as $rel) {
				$abs = NufGuard::existing($share, (string)$rel, false);
				$add($abs, basename($abs), NufGuard::segments((string)$rel));
			}
		} catch (Throwable $e) {
			$zip->close();
			@unlink($dst);
			throw $e;
		}
		if (!$zip->close()) { @unlink($dst); throw new NufError('err_write', 500); }
	}

	/**
	 * Extracts an archive into a NEW folder next to it ("name" or "name (2)"). Zip-slip safe: every entry name goes through the
	 * guard (no absolute paths, no '..', no backslashes); symlink entries are skipped; total size and entry count are capped
	 * BEFORE anything is written; names a "files" share refuses are skipped and reported.
	 */
	public static function unzip(array $share, string $rel, array $limits): array
	{
		if (!class_exists('ZipArchive')) throw new NufError('err_no_zip', 500);
		$src = NufGuard::existing($share, $rel, false);
		if (!is_file($src)) throw new NufError('err_not_file');
		$zip = new ZipArchive();
		if ($zip->open($src, ZipArchive::RDONLY) !== true) throw new NufError('err_zip_open');
		$n = $zip->numFiles;
		if ($n > $limits['zip_max_entries']) { $zip->close(); throw new NufError('err_too_many'); }
		$total = 0;
		$plan = [];
		$skipped = [];
		for ($i = 0; $i < $n; $i++) {
			$st = $zip->statIndex($i);
			if (!$st) continue;
			$name = (string)$st['name'];
			$opsys = 0; $attr = 0;
			$zip->getExternalAttributesIndex($i, $opsys, $attr);
			$isLink = $opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000);
			$isDir = str_ends_with($name, '/');
			try {
				if ($isLink) throw new NufError('err_link_in_tree');
				if (str_starts_with($name, '/') || preg_match('~^[A-Za-z]:~', $name)) throw new NufError('err_path');
				$seg = NufGuard::segments(rtrim($name, '/'));
				if (!$seg) continue;
				foreach ($seg as $k => $s) NufGuard::checkName($s, $share, $isDir || $k < count($seg) - 1);
				if (NufGuard::denied($share, $seg)) throw new NufError('err_name_reserved');
			} catch (NufError $e) {
				$skipped[] = ['name' => mb_substr($name, 0, 200), 'reason' => $e->key];
				continue;
			}
			$total += (int)$st['size'];
			if ($total > $limits['zip_max_mb'] * 1048576) { $zip->close(); throw new NufError('err_too_big'); }
			$plan[] = [$i, $seg, $isDir, (int)$st['size']];
		}
		$dir = dirname($src);
		$base = preg_replace('/\.zip$/i', '', basename($src)) ?: 'archive';
		try { NufGuard::checkName($base, $share, true); } catch (NufError) { $base = 'archive'; }
		$target = $dir . '/' . NufGuard::unique($dir, $base);
		if (!@mkdir($target, 0775)) { $zip->close(); throw new NufError('err_write', 500); }
		$written = 0;
		foreach ($plan as [$i, $seg, $isDir, $declared]) {
			$path = $target . '/' . NufGuard::join($seg);
			if ($isDir) { if (!is_dir($path)) @mkdir($path, 0775, true); continue; }
			$parent = dirname($path);
			if (!is_dir($parent)) @mkdir($parent, 0775, true);
			$realParent = realpath($parent);
			if ($realParent === false || !NufGuard::inside($target, $realParent)) { $skipped[] = ['name' => NufGuard::join($seg), 'reason' => 'err_path']; continue; }
			if (file_exists($path)) { $skipped[] = ['name' => NufGuard::join($seg), 'reason' => 'err_exists']; continue; }
			$in = $zip->getStream($zip->getNameIndex($i));
			$out = @fopen($path, 'xb');
			if (!$in || !$out) { $skipped[] = ['name' => NufGuard::join($seg), 'reason' => 'err_write']; continue; }
			$copied = stream_copy_to_stream($in, $out, $declared + 1);      // never more than the archive declared (zip bomb with a lying size)
			fclose($in); fclose($out);
			if ($copied !== $declared) { @unlink($path); $skipped[] = ['name' => NufGuard::join($seg), 'reason' => 'err_zip_size']; continue; }
			try { NufGuard::checkContent($path, $share); } catch (NufError $e) { @unlink($path); $skipped[] = ['name' => NufGuard::join($seg), 'reason' => $e->key]; continue; }
			$written++;
		}
		$zip->close();
		return ['folder' => NufGuard::rel($share, $target), 'files' => $written, 'skipped' => $skipped];
	}

	// ============================================================ sending files

	public static function mime(string $abs): string
	{
		if (!function_exists('finfo_open')) return 'application/octet-stream';
		$m = (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $abs);
		return $m !== '' ? $m : 'application/octet-stream';
	}

	/**
	 * Streams a file and ends the request. $inline only for INLINE types (sniffed, not by name); everything else is an attachment.
	 * Supports one byte range (video / audio seeking).
	 */
	public static function send(string $abs, string $name, bool $inline): never
	{
		$size = (int)filesize($abs);
		$mime = self::mime($abs);
		if ($inline && $mime === 'text/plain') $mime = 'text/plain; charset=utf-8';
		$inline = $inline && in_array(explode(';', $mime)[0], self::INLINE, true);
		while (ob_get_level() > 0) ob_end_clean();
		@set_time_limit(0);
		header_remove('Content-Security-Policy');
		header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; media-src \'self\'; style-src \'unsafe-inline\'; frame-ancestors \'self\'' . ($mime === 'application/pdf' ? '' : '; sandbox'));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');
		header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
		$ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name) ?: 'download';
		header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
		header('Accept-Ranges: bytes');
		$start = 0; $end = $size - 1;
		if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && $size > 0) {
			if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
			else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
			if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
			http_response_code(206);
			header("Content-Range: bytes $start-$end/$size");
		}
		header('Content-Length: ' . ($size === 0 ? 0 : $end - $start + 1));
		if ($_SERVER['REQUEST_METHOD'] === 'HEAD' || $size === 0) exit;
		$h = fopen($abs, 'rb');
		if ($start) fseek($h, $start);
		$left = $end - $start + 1;
		while ($left > 0 && !feof($h) && !connection_aborted()) {
			$buf = fread($h, (int)min(1048576, $left));
			if ($buf === false) break;
			echo $buf;
			flush();
			$left -= strlen($buf);
		}
		fclose($h);
		exit;
	}
}
