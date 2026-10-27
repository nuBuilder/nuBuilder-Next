<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Draft and versions of a page form's files (0.8.599). The page folder custom/pages/<code>/ holds the live files; a draft is a copy
 * in <folder>/_draft/ that the designers edit (File Manager, FTP, git) and see when they switch to it (?p=page&f=<code>&nudraft=1,
 * remembered in the session; other users always get the live files). Publish moves the live files into <folder>/_versions/<stamp>/
 * and the draft's files into the folder; Restore copies a version back (the current files become a version first, so a restore can
 * be undone too). KEEP versions are kept.
 * Never moved or copied: _data (the page's data – shared by draft and live), _draft, _versions, secrets.php (the draft reads the live
 * one) and names starting with a dot (.git, .htaccess). Built-in pages in apps/ have no draft (an own copy in custom/pages/ has).
 */
final class NuPageVersion
{
	public const DRAFT = '_draft';
	public const VERSIONS = '_versions';
	public const KEEP = 10;
	private const SKIP = ['_data', self::DRAFT, self::VERSIONS, 'secrets.php'];
	private const STAMP_RE = '/^\d{8}-\d{6}(-\d+)?$/';

	/** The page has its own folder with index.php (no built-in page in apps/, valid code). */
	public static function available(string $code): bool
	{
		if (!NuPage::validCode($code)) return false;
		$f = NuPage::folder($code);
		return $f === NuPage::dir() . '/' . $code && is_file($f . '/index.php');
	}

	public static function writable(string $code): bool
	{
		$f = NuPage::folder($code);
		return $f !== '' && is_dir($f) && is_writable($f);
	}

	public static function draftDir(string $code): string
	{
		return NuPage::folder($code) . '/' . self::DRAFT;
	}

	public static function hasDraft(string $code): bool
	{
		return self::available($code) && is_file(self::draftDir($code) . '/index.php');
	}

	/** May the signed-in user see drafts (designers: right on the form builder)? */
	public static function designer(): bool
	{
		return NuAcl::can(NuAcl::FORM_FORM);
	}

	/**
	 * Draft view of the request: ?nudraft=1 / 0 switches it for this page (session), designers only, while a draft exists.
	 */
	public static function viewing(string $code): bool
	{
		if (!self::designer()) return false;
		$sw = $_GET['nudraft'] ?? null;
		if (($sw === '1' || $sw === '0') && session_status() === PHP_SESSION_ACTIVE) {
			if ($sw === '1') $_SESSION['nu_page_draft'][$code] = 1;
			else unset($_SESSION['nu_page_draft'][$code]);
		}
		return !empty($_SESSION['nu_page_draft'][$code]) && self::hasDraft($code);
	}

	/** Top-level entries of a folder that belong to the page's code (not SKIP, no dot names). @return list<string> */
	private static function entries(string $dir): array
	{
		$out = [];
		foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $n) {
			if ($n === '.' || $n === '..' || str_starts_with($n, '.') || in_array($n, self::SKIP, true)) continue;
			$out[] = $n;
		}
		return $out;
	}

	/** Files of the page's code: relative path => sha1. @return array<string, string> */
	public static function files(string $dir): array
	{
		$out = [];
		foreach (self::entries($dir) as $n) {
			$p = $dir . '/' . $n;
			if (is_file($p)) { $out[$n] = (string)sha1_file($p); continue; }
			if (!is_dir($p)) continue;
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if (!$f->isFile() || count($out) >= 5000) continue;
				$out[str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1))] = (string)sha1_file($f->getPathname());
			}
		}
		ksort($out);
		return $out;
	}

	/** Differences draft ↔ live. @return array{added: list<string>, changed: list<string>, removed: list<string>} */
	public static function diff(string $code): array
	{
		$live = self::files(NuPage::folder($code));
		$draft = self::files(self::draftDir($code));
		$out = ['added' => [], 'changed' => [], 'removed' => []];
		foreach ($draft as $f => $h) {
			if (!isset($live[$f])) $out['added'][] = $f;
			elseif ($live[$f] !== $h) $out['changed'][] = $f;
		}
		foreach ($live as $f => $h) if (!isset($draft[$f])) $out['removed'][] = $f;
		return $out;
	}

	/** Copies the live files into _draft/. @return array{ok: bool, message: string} */
	public static function createDraft(string $code): array
	{
		if (!self::available($code)) return ['ok' => false, 'message' => t('page_draft_unavailable')];
		if (self::hasDraft($code)) return ['ok' => false, 'message' => t('page_draft_exists')];
		if (!self::writable($code)) return ['ok' => false, 'message' => t('page_tpl_not_writable', NuPage::shownFolder($code))];
		$dir = self::draftDir($code);
		if (is_dir($dir)) self::remove($dir);                 // a left-over without index.php
		if (!self::copyEntries(NuPage::folder($code), $dir)) { self::remove($dir); return ['ok' => false, 'message' => t('page_tpl_not_writable', NuPage::shownFolder($code))]; }
		NuLog::write('page_draft_created', NuPage::TABLE, $code, ['folder' => NuPage::shownFolder($code) . self::DRAFT . '/']);
		return ['ok' => true, 'message' => t('page_draft_created', NuPage::shownFolder($code) . self::DRAFT . '/')];
	}

	/** Deletes the draft. */
	public static function discard(string $code): array
	{
		if (!self::available($code) || !is_dir(self::draftDir($code))) return ['ok' => false, 'message' => t('page_draft_none')];
		self::remove(self::draftDir($code));
		NuLog::write('page_draft_discarded', NuPage::TABLE, $code, []);
		return ['ok' => true, 'message' => t('page_draft_discarded')];
	}

	/** The draft becomes live; the live files are kept as a version. */
	public static function publish(string $code): array
	{
		if (!self::hasDraft($code)) return ['ok' => false, 'message' => t('page_draft_none')];
		if (!self::writable($code)) return ['ok' => false, 'message' => t('page_tpl_not_writable', NuPage::shownFolder($code))];
		$folder = NuPage::folder($code);
		$diff = self::diff($code);
		$stamp = self::backup($code, 'publish');
		if ($stamp === null) return ['ok' => false, 'message' => t('page_tpl_not_writable', NuPage::shownFolder($code))];
		$draft = self::draftDir($code);
		foreach (self::entries($draft) as $n) {
			if (!@rename($draft . '/' . $n, $folder . '/' . $n)) {
				self::restoreFrom($code, $folder . '/' . self::VERSIONS . '/' . $stamp);   // put the live files back
				return ['ok' => false, 'message' => t('page_tpl_not_writable', NuPage::shownFolder($code))];
			}
		}
		self::remove($draft);
		self::flush($folder);
		self::prune($code);
		NuLog::write('page_draft_published', NuPage::TABLE, $code, ['version' => $stamp, 'changed' => count($diff['added']) + count($diff['changed']) + count($diff['removed'])]);
		return ['ok' => true, 'message' => t('page_draft_published')];
	}

	/** Previous versions, newest first. @return list<array{id: string, at: string, by: string, kind: string, files: int}> */
	public static function versions(string $code): array
	{
		$base = NuPage::folder($code) . '/' . self::VERSIONS;
		$out = [];
		foreach (is_dir($base) ? (scandir($base) ?: []) : [] as $n) {
			if (!preg_match(self::STAMP_RE, $n) || !is_dir($base . '/' . $n)) continue;
			$m = json_decode((string)@file_get_contents($base . '/' . $n . '/.version.json'), true);
			$m = is_array($m) ? $m : [];
			$out[] = ['id' => $n, 'at' => (string)($m['at'] ?? ''), 'by' => (string)($m['by'] ?? ''), 'kind' => (string)($m['kind'] ?? ''),
				'files' => count(self::files($base . '/' . $n))];
		}
		usort($out, static fn($a, $b) => strcmp($b['id'], $a['id']));
		return $out;
	}

	/** Copies a version back into the folder; the current files become a version first. */
	public static function restore(string $code, string $id): array
	{
		$src = NuPage::folder($code) . '/' . self::VERSIONS . '/' . $id;
		if (!self::available($code) || !preg_match(self::STAMP_RE, $id) || !is_dir($src)) return ['ok' => false, 'message' => t('err_not_found')];
		if (!self::writable($code)) return ['ok' => false, 'message' => t('page_tpl_not_writable', NuPage::shownFolder($code))];
		$stamp = self::backup($code, 'restore');
		if ($stamp === null || !self::restoreFrom($code, $src)) return ['ok' => false, 'message' => t('page_tpl_not_writable', NuPage::shownFolder($code))];
		self::flush(NuPage::folder($code));
		self::prune($code);
		NuLog::write('page_version_restored', NuPage::TABLE, $code, ['version' => $id, 'saved_as' => $stamp]);
		return ['ok' => true, 'message' => t('page_version_restored', self::label($id))];
	}

	/** "2026-10-27 06:15:02" of a version id. */
	public static function label(string $id): string
	{
		return preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/', $id, $m) ? "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]" : $id;
	}

	// ------------------------------------------------------------------ helpers

	/** Moves the live files into _versions/<stamp>/ (with .version.json). @return string|null the stamp */
	private static function backup(string $code, string $kind): ?string
	{
		$folder = NuPage::folder($code);
		$base = $folder . '/' . self::VERSIONS;
		if (!is_dir($base) && !@mkdir($base, 0755)) return null;
		NuSecurity::protectDir($base);
		$stamp = date('Ymd-His');
		for ($i = 2; file_exists($base . '/' . $stamp); $i++) $stamp = date('Ymd-His') . '-' . $i;
		$dst = $base . '/' . $stamp;
		if (!@mkdir($dst, 0755)) return null;
		foreach (self::entries($folder) as $n) {
			if (!@rename($folder . '/' . $n, $dst . '/' . $n)) {
				foreach (self::entries($dst) as $back) @rename($dst . '/' . $back, $folder . '/' . $back);
				self::remove($dst);
				return null;
			}
		}
		@file_put_contents($dst . '/.version.json', (string)json_encode(['at' => date('Y-m-d H:i:s'), 'kind' => $kind,
			'by' => mb_substr((string)(NuAuth::currentRaw()['login'] ?? ''), 0, 50)]));
		return $stamp;
	}

	/** Copies the entries of a version folder into the page folder (which holds no code entries at this point). */
	private static function restoreFrom(string $code, string $src): bool
	{
		$folder = NuPage::folder($code);
		foreach (self::entries($folder) as $n) self::remove($folder . '/' . $n);
		return self::copyEntries($src, $folder);
	}

	private static function copyEntries(string $from, string $to): bool
	{
		if (!is_dir($to) && !@mkdir($to, 0755, true)) return false;
		foreach (self::entries($from) as $n) {
			$p = $from . '/' . $n;
			if (is_file($p)) { if (!@copy($p, $to . '/' . $n)) return false; continue; }
			if (!is_dir($p) || is_link($p)) continue;
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
			if (!is_dir($to . '/' . $n) && !@mkdir($to . '/' . $n, 0755)) return false;
			foreach ($it as $f) {
				$dst = $to . '/' . $n . '/' . substr($f->getPathname(), strlen($p) + 1);
				if ($f->isDir()) { if (!is_dir($dst) && !@mkdir($dst, 0755)) return false; }
				elseif ($f->isFile() && !@copy($f->getPathname(), $dst)) return false;
			}
		}
		return true;
	}

	private static function remove(string $path): void
	{
		if (is_link($path) || is_file($path)) { @unlink($path); return; }
		if (!is_dir($path)) return;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		@rmdir($path);
	}

	/** Keeps the newest KEEP versions. */
	private static function prune(string $code): void
	{
		$base = NuPage::folder($code) . '/' . self::VERSIONS;
		foreach (array_slice(self::versions($code), self::KEEP) as $v) self::remove($base . '/' . $v['id']);
	}

	/** OPcache would keep the old code of the moved files. */
	private static function flush(string $folder): void
	{
		if (!function_exists('opcache_invalidate')) return;
		foreach (array_keys(self::files($folder)) as $f) if (str_ends_with($f, '.php')) @opcache_invalidate($folder . '/' . $f, true);
	}
}
