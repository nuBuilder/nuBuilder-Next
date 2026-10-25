<?php
defined('NU_PAGE') || exit;

/**
 * nuFiles – the JSON / file actions of the page (?action=…). Before the page runs, nuBuilder Next has checked the login, the
 * form's view right and (for every POST of a fetch) the CSRF token, and refuses POSTs while the globeadmin views as another user.
 * Here: share rights, read-only mode, the password confirmation for code shares, the log.
 */
final class NufApi
{
	/** GET actions (a POST works too); the POST-only actions change something. */
	private const GET = ['boot', 'list', 'search', 'info', 'read', 'versions', 'version', 'trash', 'download', 'raw', 'logview', 'reauth_done'];
	private const POST = ['save', 'newfile', 'mkdir', 'rename', 'move', 'copy', 'delete', 'restore', 'purge', 'upload_init', 'upload_chunk', 'zip', 'unzip'];

	public static function handle(NuPageContext $nu, array $S): never
	{
		$a = $nu->action();
		try {
			if (!in_array($a, self::GET, true) && !in_array($a, self::POST, true)) throw new NufError('err_bad_request', 404);
			if (in_array($a, self::POST, true) && !$nu->isPost()) throw new NufError('err_bad_request', 405);
			$out = match ($a) {
				'boot' => self::boot($nu, $S),
				'list' => self::listDir($nu),
				'search' => self::search($nu),
				'info' => self::info($nu),
				'read' => self::read($nu),
				'versions' => self::versions($nu),
				'version' => self::version($nu),
				'save' => self::save($nu, $S),
				'newfile' => self::newFile($nu),
				'mkdir' => self::mkdir($nu),
				'rename' => self::rename($nu),
				'move' => self::transfer($nu, false),
				'copy' => self::transfer($nu, true),
				'delete' => self::delete($nu),
				'trash' => self::trash($nu),
				'restore' => self::restore($nu),
				'purge' => self::purge($nu),
				'upload_init' => self::uploadInit($nu),
				'upload_chunk' => self::uploadChunk($nu),
				'zip' => self::zip($nu),
				'unzip' => self::unzip($nu),
				'download' => self::download($nu),
				'raw' => self::raw($nu),
				'logview' => self::logView($nu),
				'reauth_done' => self::reauthDone($nu, $S),
			};
			$nu->json(['ok' => true] + $out);
		} catch (NufError $e) {
			$body = ['ok' => false, 'error' => $e->key, 'message' => NufI18n::t($S, $e->key, $e->args), 'args' => $e->args];
			if ($e->key === 'err_reauth') $body['reauth'] = self::reauthUrl($nu);
			$nu->json($body, $e->status);
		} catch (Throwable $e) {
			$nu->log('nuFiles ' . $a . ': ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
			$nu->json(['ok' => false, 'error' => 'err_server', 'message' => NufI18n::t($S, 'err_server')
				. ($nu->isGlobeadmin() ? ' ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : '')], 500);
		}
	}

	// ------------------------------------------------------------------ helpers

	private static function p(NuPageContext $nu, string $name, string $default = ''): string
	{
		return (string)($nu->param($name, $default) ?? $default);
	}

	/** JSON list parameter (paths=["a","b"]). */
	private static function names(NuPageContext $nu, string $name): array
	{
		$v = json_decode(self::p($nu, $name, '[]'), true);
		if (!is_array($v) || count($v) > 5000) throw new NufError('err_bad_request');
		$v = array_values(array_filter($v, 'is_string'));
		if (!$v) throw new NufError('err_bad_request');
		return $v;
	}

	/**
	 * A method of a later page API (see the core change list in README.md) – called through a variable, so nuBuilder Next's page
	 * check does not report it as unknown while it does not exist yet. null when the method is not there.
	 */
	public static function future(NuPageContext $nu, string $method, mixed ...$args): mixed
	{
		if (!method_exists($nu, $method)) return null;
		return $nu->{$method}(...$args);
	}

	/** reauth setting: "code" (default: any access to code shares), "code_write" (changes in code shares), "all_write", "none". */
	private static function needsReauth(NuPageContext $nu, array $share, bool $write): bool
	{
		$mode = (string)$nu->setting('reauth', 'code');
		return match ($mode) {
			'none' => false,
			'all_write' => $write || $share['type'] === 'code',
			'code_write' => $write && $share['type'] === 'code',
			default => $share['type'] === 'code',
		};
	}

	/** Was the password entered recently? Core change C3 (page method "recentAuth"); until then nuBuilder Next's NuSecurity directly. */
	private static function recentAuth(NuPageContext $nu): bool
	{
		$r = self::future($nu, 'recentAuth');
		if ($r !== null) return (bool)$r;
		if (class_exists('NuSecurity') && method_exists('NuSecurity', 'recentAuth')) return NuSecurity::recentAuth();
		return true;                                       // no way to ask: as before (the page form's own access rules)
	}

	/** URL of the password confirmation that comes back to ?action=reauth_done (opened in a small window by app.js). */
	private static function reauthUrl(NuPageContext $nu): string
	{
		$back = $nu->url(['action' => 'reauth_done']);
		$u = self::future($nu, 'reauthUrl', $back);
		if ($u !== null) return (string)$u;
		if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['nu_reauth_next'] = $back;   // as pages/nubuilderapi.php does (interim)
		return 'index.php?p=reauth';
	}

	/** A share for reading (view right, password confirmation for code shares). */
	private static function read_(NuPageContext $nu, string $id, string $right = 'view'): array
	{
		$share = NufConfig::share($id, $right);
		if (self::needsReauth($nu, $share, false) && !self::recentAuth($nu)) throw new NufError('err_reauth', 403);
		return $share;
	}

	/** A share for a change: read-only mode, the right, the password confirmation. */
	private static function write(NuPageContext $nu, string $id, string $right): array
	{
		if ($nu->readOnly()) throw new NufError('err_readonly_mode', 403);
		$share = NufConfig::share($id, $right);
		if (self::needsReauth($nu, $share, true) && !self::recentAuth($nu)) throw new NufError('err_reauth', 403);
		return $share;
	}

	// ------------------------------------------------------------------ read

	public static function boot(NuPageContext $nu, array $S): array
	{
		$u = $nu->user();
		$ga = $nu->isGlobeadmin();
		return [
			'strings' => $S,
			'shares' => NufConfig::publicShares($ga),
			'user' => ['display' => $u['display'] !== '' ? $u['display'] : $u['login'], 'login' => $u['login'], 'globeadmin' => $ga],
			'limits' => ['chunk' => NufConfig::$limits['chunk_mb'] * 1048576, 'editor_max' => NufConfig::$limits['editor_max_kb'] * 1024,
				'search_max' => NufConfig::$limits['search_max']],
			'readonly' => $nu->readOnly(),
			'problems' => $ga ? NufConfig::$problems : [],
			'features' => ['zip' => class_exists('ZipArchive'), 'trash' => NufConfig::$dataDir !== '', 'log' => $ga],
			'base' => $nu->url(),
			'home' => $nu->homeUrl(),
			'lang' => $nu->lang(),
			'version' => NUF_VERSION,
		];
	}

	private static function listDir(NuPageContext $nu): array
	{
		$share = self::read_($nu, self::p($nu, 'share'));
		$nu->releaseSession();
		$r = NufFs::listDir($share, self::p($nu, 'path'), NufConfig::$limits['list_max']);
		if ($nu->setting('log_reads', false)) NufLog::write($nu, 'list', $share['id'], $r['path']);
		return $r;
	}

	private static function search(NuPageContext $nu): array
	{
		$share = self::read_($nu, self::p($nu, 'share'));
		$nu->releaseSession();
		return NufFs::search($share, self::p($nu, 'path'), self::p($nu, 'q'), NufConfig::$limits['search_max']);
	}

	private static function info(NuPageContext $nu): array
	{
		$share = self::read_($nu, self::p($nu, 'share'));
		$abs = NufGuard::existing($share, self::p($nu, 'path'));
		$e = NufFs::entry($share, $abs, $abs === $share['root'] ? $share['label'] : basename($abs));
		$e['path'] = NufGuard::rel($share, $abs);
		if ($e['type'] === 'dir') $e += NufFs::treeSize($abs, 20000); else $e['mime'] = NufFs::mime($abs);
		if ($nu->isGlobeadmin()) $e['absolute'] = $abs;
		return ['info' => $e];
	}

	private static function read(NuPageContext $nu): array
	{
		$share = self::read_($nu, self::p($nu, 'share'));
		$abs = NufGuard::existing($share, self::p($nu, 'path'), false);
		if (!is_file($abs)) throw new NufError('err_not_file');
		[$ok, $data] = NufFs::isText($abs, NufConfig::$limits['editor_max_kb'] * 1024);
		if (!$ok) return ['text' => false, 'reason' => $data, 'size' => (int)filesize($abs)];
		if ($nu->setting('log_reads', false)) NufLog::write($nu, 'read', $share['id'], NufGuard::rel($share, $abs));
		return ['text' => true, 'content' => $data, 'sha' => sha1($data), 'size' => strlen($data), 'mtime' => (int)filemtime($abs),
			'editable' => NufConfig::can($share, 'edit') && is_writable($abs) && !$nu->readOnly()];
	}

	private static function versions(NuPageContext $nu): array
	{
		$share = self::read_($nu, self::p($nu, 'share'), 'edit');
		$abs = NufGuard::existing($share, self::p($nu, 'path'), false);
		return ['versions' => NufFs::versions($share, $abs)];
	}

	private static function version(NuPageContext $nu): array
	{
		$share = self::read_($nu, self::p($nu, 'share'), 'edit');
		$abs = NufGuard::existing($share, self::p($nu, 'path'), false);
		return ['content' => NufFs::versionContent($share, $abs, self::p($nu, 'id'))];
	}

	// ------------------------------------------------------------------ change

	private static function save(NuPageContext $nu, array $S): array
	{
		$share = self::write($nu, self::p($nu, 'share'), 'edit');
		$abs = NufGuard::existing($share, self::p($nu, 'path'), false);
		if (!is_file($abs)) throw new NufError('err_not_file');
		// a files share: no saving into names it would refuse for an upload (an old .php, .html …); the allow-list is not applied
		NufGuard::checkName(basename($abs), array_merge($share, ['extensions' => []]), false);
		// the text arrives as a file (a Blob): nuBuilder Next rewrites CRLF in every $_POST string, files reach the page unchanged
		$f = $_FILES['content'] ?? null;
		if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new NufError('err_bad_request');
		if ((int)$f['size'] > NufConfig::$limits['editor_max_kb'] * 1024) throw new NufError('err_too_big');
		$content = (string)file_get_contents((string)$f['tmp_name']);
		if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) throw new NufError('err_not_text');
		NufGuard::checkText($content, $share);
		if (self::p($nu, 'force_lint') !== '1' && ($lint = NufFs::lint(basename($abs), $content))) {
			$nu->json(['ok' => false, 'error' => 'err_lint', 'message' => NufI18n::t($S, 'lint_text', [$lint['line'], $lint['message']]), 'lint' => $lint], 422);
		}
		$res = NufFs::save($share, $abs, $content, self::p($nu, 'sha'), self::p($nu, 'force') === '1', NufConfig::$limits['versions_keep']);
		NufLog::write($nu, 'save', $share['id'], NufGuard::rel($share, $abs), '', $res['size']);
		return $res;
	}

	private static function newFile(NuPageContext $nu): array
	{
		$share = self::write($nu, self::p($nu, 'share'), 'edit');
		$abs = NufFs::newFile($share, self::p($nu, 'path'), self::p($nu, 'name'));
		NufLog::write($nu, 'newfile', $share['id'], NufGuard::rel($share, $abs));
		return ['path' => NufGuard::rel($share, $abs)];
	}

	private static function mkdir(NuPageContext $nu): array
	{
		$share = self::write($nu, self::p($nu, 'share'), 'mkdir');
		$abs = NufFs::mkdir($share, self::p($nu, 'path'), self::p($nu, 'name'));
		NufLog::write($nu, 'mkdir', $share['id'], NufGuard::rel($share, $abs));
		return ['path' => NufGuard::rel($share, $abs)];
	}

	private static function rename(NuPageContext $nu): array
	{
		$share = self::write($nu, self::p($nu, 'share'), 'rename');
		[$src, $dst] = NufFs::rename($share, self::p($nu, 'path'), self::p($nu, 'name'));
		NufLog::write($nu, 'rename', $share['id'], NufGuard::rel($share, $src), NufGuard::rel($share, $dst));
		return ['path' => NufGuard::rel($share, $dst)];
	}

	/**
	 * move inside a share: "rename"; into another share: "delete" on the source and "upload" on the target.
	 * copy inside a share: "copy"; into another share: "view" on the source and "upload" on the target.
	 */
	private static function transfer(NuPageContext $nu, bool $copy): array
	{
		$fromId = self::p($nu, 'share');
		$toId = self::p($nu, 'to_share', $fromId);
		$same = $fromId === $toId;
		$from = $copy ? self::read_($nu, $fromId) : self::write($nu, $fromId, $same ? 'rename' : 'delete');
		$to = self::write($nu, $toId, $same ? ($copy ? 'copy' : 'rename') : 'upload');
		$done = NufFs::transfer($from, self::names($nu, 'paths'), $to, self::p($nu, 'to_path'), $copy, NufConfig::$limits);
		foreach ($done as [$s, $t]) NufLog::write($nu, $copy ? 'copy' : 'move', $from['id'], $s, ($same ? '' : $to['id'] . ':') . $t);
		return ['done' => count($done)];
	}

	private static function delete(NuPageContext $nu): array
	{
		$share = self::write($nu, self::p($nu, 'share'), 'delete');
		$n = 0;
		$user = $nu->user();
		foreach (self::names($nu, 'paths') as $rel) {
			$id = NufFs::delete($share, $rel, $user);
			NufLog::write($nu, $id !== '' ? 'trash' : 'delete', $share['id'], $rel, $id);
			$n++;
		}
		return ['done' => $n, 'trash' => $share['trash'] && NufConfig::$dataDir !== ''];
	}

	/** The recycle bin: the globeadmin sees everything, users the entries of shares where they may delete. */
	private static function trash(NuPageContext $nu): array
	{
		$ids = [];
		foreach (NufConfig::$shares as $s) if (NufConfig::can($s, 'delete')) $ids[] = $s['id'];
		$labels = array_column(NufConfig::$shares, 'label', 'id');
		$items = NufFs::trashList($nu->isGlobeadmin() ? null : $ids);
		foreach ($items as &$i) $i['share_label'] = $labels[$i['share'] ?? ''] ?? (string)($i['share'] ?? '');
		unset($i);
		return ['items' => $items, 'can_purge' => $nu->isGlobeadmin() && !$nu->readOnly(), 'days' => NufConfig::$limits['trash_days']];
	}

	private static function restore(NuPageContext $nu): array
	{
		$meta = NufFs::trashMeta(self::p($nu, 'id'));
		$share = self::write($nu, (string)$meta['share'], 'delete');
		$dst = NufFs::restore($share, $meta);
		NufLog::write($nu, 'restore', $share['id'], (string)$meta['path'], NufGuard::rel($share, $dst));
		return ['path' => NufGuard::rel($share, $dst), 'share' => $share['id']];
	}

	/** Final deletion from the recycle bin: globeadmin only (the bin also empties itself after trash_days). */
	private static function purge(NuPageContext $nu): array
	{
		if (!$nu->isGlobeadmin()) throw new NufError('err_admin', 403);
		if ($nu->readOnly()) throw new NufError('err_readonly_mode', 403);
		$ids = self::p($nu, 'all') === '1' ? array_column(NufFs::trashList(null), 'id') : [self::p($nu, 'id')];
		foreach ($ids as $id) {
			$m = NufFs::trashMeta((string)$id);
			NufFs::purge((string)$id);
			NufLog::write($nu, 'purge', (string)$m['share'], (string)$m['path']);
		}
		return ['done' => count($ids)];
	}

	private static function uploadInit(NuPageContext $nu): array
	{
		$share = self::write($nu, self::p($nu, 'share'), 'upload');
		$overwrite = self::p($nu, 'overwrite') === '1' && NufConfig::can($share, 'delete');   // replacing = deleting the old file
		$size = (int)self::p($nu, 'size', '-1');
		$r = NufFs::uploadInit($share, self::p($nu, 'path'), self::p($nu, 'name'), $size, $overwrite, $nu->user());
		if ($size !== 0) return $r + ['done' => false];
		$res = NufFs::uploadFinish($share, $r['token'], NufFs::ticket($r['token'], $nu->user()));
		NufLog::write($nu, 'upload', $share['id'], $res['path'], '', 0, true, $res['replaced'] ? 'replaced' : '');
		return $res;
	}

	private static function uploadChunk(NuPageContext $nu): array
	{
		$token = self::p($nu, 'token');
		$ticket = NufFs::ticket($token, $nu->user());
		$share = self::write($nu, (string)$ticket['share'], 'upload');
		$nu->releaseSession();                                                  // chunks of several files may run side by side
		$res = NufFs::uploadChunk($share, $token, (int)self::p($nu, 'offset', '-1'), $_FILES['chunk'] ?? [], $ticket, NufConfig::$limits['chunk_mb'] * 1048576);
		if (!empty($res['done'])) NufLog::write($nu, 'upload', $share['id'], (string)$res['path'], '', (int)$ticket['size'], true, !empty($res['replaced']) ? 'replaced' : '');
		return $res;
	}

	private static function zip(NuPageContext $nu): array
	{
		$share = self::write($nu, self::p($nu, 'share'), 'zip');
		$dst = NufFs::zip($share, self::p($nu, 'path'), self::names($nu, 'paths'), self::p($nu, 'name', 'archive.zip'), NufConfig::$limits);
		NufLog::write($nu, 'zip', $share['id'], NufGuard::rel($share, $dst), '', (int)filesize($dst));
		return ['path' => NufGuard::rel($share, $dst), 'name' => basename($dst)];
	}

	private static function unzip(NuPageContext $nu): array
	{
		$share = self::write($nu, self::p($nu, 'share'), 'zip');
		$r = NufFs::unzip($share, self::p($nu, 'path'), NufConfig::$limits);
		NufLog::write($nu, 'unzip', $share['id'], self::p($nu, 'path'), $r['folder'], null, true, $r['skipped'] ? count($r['skipped']) . ' skipped' : '');
		return $r;
	}

	// ------------------------------------------------------------------ files out

	/** One file as it is; a folder or several entries (paths=[…]) as a temporary zip. */
	private static function download(NuPageContext $nu): array
	{
		$share = self::read_($nu, self::p($nu, 'share'));
		$rels = self::p($nu, 'paths') !== '' ? self::names($nu, 'paths') : null;
		$abs = $rels === null ? NufGuard::existing($share, self::p($nu, 'path'), false) : null;
		$nu->releaseSession();
		if ($abs !== null && is_file($abs)) {
			NufLog::write($nu, 'download', $share['id'], NufGuard::rel($share, $abs), '', (int)filesize($abs));
			NufFs::send($abs, basename($abs), false);
		}
		if ($rels === null) $rels = [NufGuard::rel($share, (string)$abs)];
		if (!class_exists('ZipArchive')) throw new NufError('err_no_zip', 500);
		if (NufConfig::$dataDir === '') throw new NufError('err_data_dir', 500);
		$tmp = NufConfig::$dataDir . '/tmp/dl-' . bin2hex(random_bytes(8)) . '.zip';
		register_shutdown_function(static function () use ($tmp): void { @unlink($tmp); });
		NufFs::zipTo($share, $rels, $tmp, NufConfig::$limits);
		NufLog::write($nu, 'download', $share['id'], implode(', ', array_slice($rels, 0, 20)) . (count($rels) > 20 ? ' …' : ''), '', (int)filesize($tmp));
		$name = count($rels) === 1 ? basename($rels[0]) . '.zip' : $share['id'] . '-' . date('Ymd-His') . '.zip';
		NufFs::send($tmp, $name, false);
	}

	/** Inline view for the preview (images, PDF, audio, video, plain text) – other types come as a download. */
	private static function raw(NuPageContext $nu): array
	{
		$share = self::read_($nu, self::p($nu, 'share'));
		$abs = NufGuard::existing($share, self::p($nu, 'path'), false);
		if (!is_file($abs)) throw new NufError('err_not_file');
		$nu->releaseSession();
		if ($nu->setting('log_reads', false)) NufLog::write($nu, 'preview', $share['id'], NufGuard::rel($share, $abs));
		NufFs::send($abs, basename($abs), true);
	}

	private static function logView(NuPageContext $nu): array
	{
		if (!$nu->isGlobeadmin()) throw new NufError('err_admin', 403);
		return ['items' => NufLog::latest($nu, (int)self::p($nu, 'limit', '500'), self::p($nu, 'share'), trim(self::p($nu, 'q')))];
	}

	/** End of the password confirmation (in its own small window): tell the page and close. */
	private static function reauthDone(NuPageContext $nu, array $S): never
	{
		while (ob_get_level() > 0) ob_end_clean();
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><html lang="' . $nu->h(substr($nu->lang(), 0, 2)) . '"><meta charset="utf-8"><title>' . $nu->h($S['reauth_ok']) . '</title>'
			. '<body style="font-family:system-ui,sans-serif;padding:2rem"><p>' . $nu->h($S['reauth_ok_close']) . '</p>'
			. '<script src="' . $nu->h($nu->asset('assets/reauth.js')) . '"></script></body></html>';
		exit;
	}
}
