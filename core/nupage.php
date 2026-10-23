<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Form type "page" (0.8.387): a form whose content is a PHP file in custom/pages/<form code>/index.php. nuBuilder Next checks the
 * login and the form's view right, then includes the file with one object, $nu (NuPageContext – the stable page API).
 *
 *   index.php?p=form&f=<code>   → NuPage::wrapper(): iframe inside nuBuilder Next, or a redirect to the raw URL
 *   index.php?p=page&f=<code>   → NuPage::run(): the page's own output (HTML, JSON, files)
 *   index.php?p=pageasset&f=<code>&file=assets/…  → NuPage::asset(): css / js / images of the page folder
 *
 * Settings per page: zzzzsys_nunext_page (primary key = zzzzsys_form_id). Files: NuPage::dir() (custom/pages or $nuConfigCustomPagesDir),
 * never touched by updates. Secrets: secrets.php in the page folder (NuPageContext::secret).
 */
final class NuPage
{
	public const TABLE = 'zzzzsys_nunext_page';
	public const MODES = ['tab', 'iframe', 'self'];
	/** Folder name = form code: letters, digits, _ and -, starting with a letter or digit, at most 64 characters. */
	public const CODE_RE = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';
	public const GUARD = "defined('NU_PAGE') || exit;";
	public const ASSET_TYPES = [
		'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8', 'map' => 'application/json',
		'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
		'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'json' => 'application/json',
		'txt' => 'text/plain; charset=utf-8', 'pdf' => 'application/pdf',
	];

	/**
	 * Page API versions (0.8.388). A page declares the version it is written for with $nu->api(<n>) (none = 1). Rules:
	 * within one version methods are only added, never removed or changed; a breaking change raises the version, and the previous
	 * one keeps running for at least 6 months / 20 releases through an adapter class NuPageContextV<n> (extends the current one).
	 * Deprecated methods: API_DEPRECATED (method => [since, replacement]) – a call writes a notice to the debug log once a day per
	 * page. The update manifest carries apiInfo() as page_api, so "Check package" lists the pages a new version would break.
	 */
	public const API_SUPPORTED = [1];
	/** @var array<string, array{0:string, 1:string}> $nu methods: name => [since version, replacement] */
	public const API_DEPRECATED = [];
	/** NuPage.* of assets/js/nupage.js (dev/tests/run.php checks the list against the file). */
	public const JS_API = ['csrf', 'inFrame', 'apiVersion', 'version', 'fetch', 'resize', 'close', 'refreshParent', 'openForm', 'toast', 'setTitle'];
	/** @var array<string, array{0:string, 1:string}> NuPage.* (JavaScript): name => [since version, replacement] */
	public const JS_DEPRECATED = [];

	/** Output buffer level of the page (NuPageContext::json etc. clear down to it). */
	public static int $obLevel = 0;

	// ================================================================= folder and settings

	/** The folder that holds the page folders (absolute, without trailing slash). */
	public static function dir(): string
	{
		$d = trim((string)(nu_cfg('nuConfigCustomPagesDir') ?? ''));
		return rtrim($d !== '' ? $d : NU_ROOT . '/custom/pages', '/\\');
	}

	/** The form code is a valid folder name (4.2 of the spec). */
	public static function validCode(string $code): bool
	{
		return (bool)preg_match(self::CODE_RE, $code);
	}

	/** Folder of a page form ('' when the code is no valid folder name). Does not check that it exists. */
	public static function folder(string $code): string
	{
		return self::validCode($code) ? self::dir() . '/' . $code : '';
	}

	/** Shown path of a page folder: relative to nuBuilder Next when it lies inside, else absolute. */
	public static function shownFolder(string $code): string
	{
		$f = self::dir() . '/' . $code . '/';
		$root = rtrim(str_replace('\\', '/', NU_ROOT), '/') . '/';
		$f = str_replace('\\', '/', $f);
		return str_starts_with($f, $root) ? substr($f, strlen($root)) : $f;
	}

	/** The entry file of a page, confined to the pages folder (realpath); null when missing or outside. */
	public static function file(string $code): ?string
	{
		$folder = self::folder($code);
		if ($folder === '') return null;
		$base = realpath(self::dir());
		$file = realpath($folder . '/index.php');
		if ($base === false || $file === false || !is_file($file)) return null;
		return str_starts_with($file, $base . DIRECTORY_SEPARATOR) ? $file : null;
	}

	/** Builder information about the page folder. */
	public static function fileInfo(string $code): array
	{
		$valid = self::validCode($code);
		$file  = $valid ? self::file($code) : null;
		$head  = $file ? (string)@file_get_contents($file, false, null, 0, 2048) : '';
		$folder = self::folder($code);
		return [
			'valid' => $valid, 'folder' => self::shownFolder($code), 'exists' => $file !== null,
			'mtime' => $file ? date('Y-m-d H:i:s', (int)filemtime($file)) : '', 'size' => $file ? (int)filesize($file) : 0,
			'guard' => $file === null || self::hasGuard($head),
			'secrets' => $folder !== '' && is_file($folder . '/secrets.php'),
			'folder_exists' => $folder !== '' && is_dir($folder),
			'writable' => is_dir(self::dir()) && is_writable(self::dir()),
			'template' => is_file(self::dir() . '/_template/index.php'),
		];
	}

	/** The NU_PAGE guard right after the opening tag (comments and blank lines before it are fine). */
	public static function hasGuard(string $code): bool
	{
		$code = preg_replace('~^\xEF\xBB\xBF~', '', $code) ?? '';
		if (!preg_match('~^<\?php\b~', $code)) return false;
		$rest = substr($code, 5);
		$rest = preg_replace('~^(\s+|//[^\n]*\n|#(?!\[)[^\n]*\n|/\*.*?\*/|declare\s*\([^)]*\)\s*;)*~s', '', $rest) ?? '';
		return (bool)preg_match('~^defined\s*\(\s*[\'"]NU_PAGE[\'"]\s*\)\s*(\|\||or)\s*(exit|die)\b~i', $rest);
	}

	public static function defaults(): array
	{
		return ['open_mode' => 'tab', 'iframe_height' => 'auto', 'settings' => '{}', 'csp' => '', 'note' => '', 'tables' => null];
	}

	/** Settings of a page form (defaults when it has no row yet). */
	public static function settings(string $formId): array
	{
		static $cache = [];
		if (isset($cache[$formId])) return $cache[$formId];
		$row = null;
		try {
			if (nu_db()->tableExists(self::TABLE)) $row = nu_db()->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_page_id = ?', [$formId]);
		} catch (Throwable) {}
		$d = self::defaults();
		if ($row) {
			$d['open_mode'] = in_array((string)$row['open_mode'], self::MODES, true) ? (string)$row['open_mode'] : 'tab';
			$d['iframe_height'] = self::validHeight((string)$row['iframe_height']) ? (string)$row['iframe_height'] : 'auto';
			$d['settings'] = (string)($row['settings'] ?? '') !== '' ? (string)$row['settings'] : '{}';
			$d['csp'] = (string)($row['csp'] ?? '');
			$d['note'] = (string)($row['note'] ?? '');
			$d['tables'] = $row['tables_json'] ?? null;
		}
		return $cache[$formId] = $d;
	}

	public static function validHeight(string $h): bool
	{
		return $h === 'auto' || (bool)preg_match('/^\d{2,5}(px|vh|%)$/', $h);
	}

	/** Saves the page settings of the builder. @return array<string,string> errors (field => message) */
	public static function save(string $formId, array $in): array
	{
		$e = [];
		$mode = (string)($in['open_mode'] ?? 'tab');
		if (!in_array($mode, self::MODES, true)) $e['open_mode'] = t('err_invalid');
		$height = trim((string)($in['iframe_height'] ?? 'auto')) ?: 'auto';
		if (!self::validHeight($height)) $e['iframe_height'] = t('page_err_height');
		$json = trim((string)($in['settings'] ?? '')) ?: '{}';
		$dec = json_decode($json, true);
		if (!is_array($dec) || json_last_error() !== JSON_ERROR_NONE) $e['settings'] = t('page_err_json', json_last_error_msg());
		$csp = trim(str_replace(["\r", "\n"], ' ', (string)($in['csp'] ?? '')));
		if (mb_strlen($csp) > 2000) $e['csp'] = t('page_err_csp');
		if ($e) return $e;
		NuSchema::ensure();
		$row = ['open_mode' => $mode, 'iframe_height' => $height, 'settings' => $json, 'csp' => $csp !== '' ? $csp : null,
			'note' => (string)($in['note'] ?? ''), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		$db = nu_db();
		$old = $db->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_page_id = ?', [$formId]);
		if ($old) $db->update(self::TABLE, $row, 'zzzzsys_nunext_page_id', $formId);
		else $db->insert(self::TABLE, ['zzzzsys_nunext_page_id' => $formId] + $row);
		NuLog::write('page_settings', self::TABLE, $formId, NuLog::diff($old ? array_intersect_key($old, $row) : [], array_diff_key($row, ['updated_at' => 1, 'updated_by' => 1])));
		return [];
	}

	/** Removes the settings row (form deleted). The files stay. */
	public static function forget(string $formId): void
	{
		try { if (nu_db()->tableExists(self::TABLE)) nu_db()->delete(self::TABLE, 'zzzzsys_nunext_page_id', $formId); } catch (Throwable) {}
	}

	/** Deletes a page form (form row, tabs, objects, rights, settings row) – the files stay. */
	public static function deleteForm(NuFormMeta $meta): void
	{
		$formId = $meta->id;
		nu_db()->transaction(static function (NuDB $db) use ($formId) {
			foreach (array_column($db->all('SELECT zzzzsys_object_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$formId]), 'zzzzsys_object_id') as $oid) {
				$db->delete('zzzzsys_event', 'sev_zzzzsys_object_id', (string)$oid);
			}
			$db->delete('zzzzsys_object', 'sob_all_zzzzsys_form_id', $formId);
			$db->delete('zzzzsys_tab', 'syt_zzzzsys_form_id', $formId);
			$db->delete('zzzzsys_browse', 'sbr_zzzzsys_form_id', $formId);
			$db->delete('zzzzsys_access_form', 'slf_zzzzsys_form_id', $formId);
			foreach (['BB', 'BE', 'BS', 'AS', 'BD', 'AD', 'AB'] as $h) $db->delete('zzzzsys_php', 'zzzzsys_php_id', $formId . '_' . $h);
			$db->delete('zzzzsys_form', 'zzzzsys_form_id', $formId);
		});
		self::forget($formId);
		NuLog::write('form_deleted', $meta->code(), $formId, ['type' => 'page']);
	}

	/** Copies the settings row to another form (form clone, App cloner). */
	public static function copy(string $fromId, string $toId): void
	{
		try {
			$db = nu_db();
			if (!$db->tableExists(self::TABLE)) return;
			$row = $db->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_page_id = ?', [$fromId]);
			if (!$row || $db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_page_id = ?', [$toId])) return;
			$row['zzzzsys_nunext_page_id'] = $toId;
			$db->insert(self::TABLE, $row);
		} catch (Throwable $e) {
			error_log('nuBuilder Next page copy: ' . $e->getMessage());
		}
	}

	/** Open mode of a form when it is a page form, else null (form id or code). */
	public static function modeOf(string $form): ?string
	{
		$f = nu_forms()[$form] ?? null;
		$id = $form;
		if (!$f) {
			foreach (nu_forms() as $fid => $x) if ($x['code'] === $form) { $f = $x; $id = (string)$fid; break; }
		}
		return $f && $f['type'] === 'page' ? self::settings($id)['open_mode'] : null;
	}

	public static function opensInTab(string $form): bool
	{
		return self::modeOf($form) === 'tab';
	}

	// ================================================================= requests

	/** The request wants JSON (fetch / XHR of the page). */
	public static function wantsJson(): bool
	{
		$acc = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
		return str_contains($acc, 'application/json') || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
	}

	/** Ends a page request with an error – JSON or a small HTML page. */
	public static function fail(int $status, string $code, string $message = '', string $detail = '', array $extra = []): never
	{
		while (ob_get_level() > self::$obLevel && ob_get_level() > 0) ob_end_clean();
		if (!headers_sent()) http_response_code($status);
		if (self::wantsJson()) {
			if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['error' => $code] + ($message !== '' ? ['message' => $message] : []) + ($detail !== '' ? ['detail' => $detail] : []) + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			exit;
		}
		nu_fatal($message !== '' ? $message : t('err_unexpected'), $detail, true);
	}

	/** The page form of a request (?f = id or code) with the view right – stops otherwise. */
	public static function form(): NuFormMeta
	{
		$f = (string)($_GET['f'] ?? '');
		$meta = $f !== '' ? (NuFormMeta::load($f) ?? NuFormMeta::byCode($f)) : null;
		if (!$meta || $meta->type() !== 'page') self::fail(404, 'not_found', t('err_not_found'));
		if (!NuAcl::can($meta->id)) self::fail(403, 'forbidden', t('err_forbidden'), t('err_forbidden_hint'));
		// a form never published (status Draft) opens for designers only – as other forms (0.8.386)
		if ((string)($meta->form['sfo_status'] ?? '') === '0' && !NuAcl::can(NuAcl::FORM_FORM)) self::fail(403, 'forbidden', t('draft_not_published'), $meta->description());
		return $meta;
	}

	/** Context of the URL that is carried from the wrapper to the page: r, ps, pf, pr, bt, cbt (0.8.422), iframe. */
	public static function carried(): array
	{
		$out = [];
		foreach (['r', 'ps', 'pf', 'pr', 'bt', 'cbt', 'iframe'] as $k) {
			$v = $_GET[$k] ?? null;
			if (is_string($v) && $v !== '' && strlen($v) <= 200) $out[$k] = $v;
		}
		return $out;
	}

	/** Raw URL of a page form (its own output). */
	public static function rawUrl(NuFormMeta $meta, array $params = []): string
	{
		return nu_url('page', ['f' => $meta->code() !== '' ? $meta->code() : $meta->id] + $params);
	}

	/**
	 * pages/nuform.php for a page form: open mode iframe = nuBuilder Next page with the page in an iframe; tab / self = redirect
	 * to the raw URL (a run object or tile opens tab pages in a new tab itself).
	 */
	public static function wrapper(NuFormMeta $meta): never
	{
		$s = self::settings($meta->id);
		$carry = self::carried();
		$bare = ($carry['iframe'] ?? '') === '1';
		unset($carry['iframe'], $carry['cbt']);
		$title = $meta->description() !== '' ? $meta->description() : $meta->code();
		// trail for the forms the page opens (0.8.422): this page's breadcrumbs + the page itself, so their Back returns here
		if (!$bare) $carry['cbt'] = NuTrail::store(array_merge(NuTrail::get(is_string($_GET['bt'] ?? null) ? $_GET['bt'] : null) ?? [], [['title' => $title, 'url' => NuTrail::currentUrl()]]));
		$src = self::rawUrl($meta, $carry);
		if ($s['open_mode'] !== 'iframe' && !$bare) nu_redirect($src);

		$height = $s['iframe_height'];
		$frame = '<iframe class="nuPageFrame" id="nuPageFrame" src="' . h($src) . '" title="' . h($title) . '" data-nu-height="' . h($height) . '"></iframe>';
		$host = self::hostScript();
		if ($bare) {
			nu_layout_start($title, '', ['bare' => true, 'container' => 'container-fluid nuPageWrap p-0', 'body_class' => 'nuPageBody']);
			echo $frame;
			nu_layout_end($host, true);
			exit;
		}
		$trail = NuTrail::get(is_string($_GET['bt'] ?? null) ? $_GET['bt'] : null);
		$crumbs = $trail ?? [['title' => t('nav_home'), 'url' => nu_url('home')]];
		nu_layout_start($title, '', ['container' => 'container-fluid nuPageWrap', 'body_class' => 'nuPageBody']);
		echo '<nav aria-label="breadcrumb" class="nuPageCrumbs"><ol class="breadcrumb mb-2">';
		foreach ($crumbs as $c) echo '<li class="breadcrumb-item"><a href="' . h((string)$c['url']) . '">' . h((string)$c['title']) . '</a></li>';
		echo '<li class="breadcrumb-item active" aria-current="page" id="nuPageTitle">' . h($title) . '</li></ol></nav>';
		echo $frame;
		nu_layout_end($host);
		exit;
	}

	/** <script> of the host side (iframe heights, messages of nupage.js) – page wrapper and form pages with page iframes. */
	public static function hostScript(): string
	{
		return '<script src="' . nu_asset('assets/js/nupagehost.js') . '" data-close="' . h(t('close')) . '"></script>';
	}

	/** Headers of the page routes: framing by the same origin only, the strict CSP replaced (6.2). */
	public static function headers(string $extraCsp = ''): void
	{
		if (headers_sent()) return;
		header_remove('Content-Security-Policy');
		$extra = trim(implode(';', array_filter(array_map('trim', explode(';', $extraCsp)), static fn($d) => $d !== '' && !preg_match('/^frame-ancestors\b/i', $d))));
		header("Content-Security-Policy: frame-ancestors 'self'" . ($extra !== '' ? '; ' . $extra : ''));
		header('X-Frame-Options: SAMEORIGIN');
		header('Cache-Control: no-store, private');
	}

	/** Runs the page file of the request's form (route p=page). */
	public static function run(): never
	{
		$meta = self::form();
		$code = $meta->code();
		$s = self::settings($meta->id);
		self::headers((string)$s['csp']);
		$u = NuAuth::user();
		$ga = !empty($u['globeadmin']) && empty($u['demo']);
		if (!self::validCode($code)) self::fail(500, 'page_code', t('page_err_code_title'), $ga ? t('page_err_code', $code) : '');
		$file = self::file($code);
		if ($file === null) {
			if ($ga) self::fail(404, 'page_missing', t('page_err_missing_title'), t('page_err_missing', self::shownFolder($code) . 'index.php'));
			self::fail(404, 'not_found', t('err_not_found'));
		}
		// API version (0.8.388): an unsupported version stops before the page runs; an older supported one gets its adapter class
		$declared = self::declaredApi((string)@file_get_contents($file));
		if ($declared !== null && !in_array($declared, self::API_SUPPORTED, true)) {
			self::fail(500, 'page_api', t('page_api_title'), $ga ? t('page_api_unsupported', (string)$declared, implode(', ', self::API_SUPPORTED)) : '');
		}
		$class = self::contextClass($declared);
		$ctx = new $class($meta, dirname($file), $s);
		defined('NU_PAGE') || define('NU_PAGE', 1);
		set_exception_handler(null);
		register_shutdown_function(static function () use ($ga): void {
			$e = error_get_last();
			if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
			while (ob_get_level() > self::$obLevel && ob_get_level() > 0) ob_end_clean();
			error_log('nuBuilder Next page: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
			if (!headers_sent()) http_response_code(500);
			$msg = $ga ? $e['message'] . ' – ' . self::relPath((string)$e['file']) . ':' . $e['line'] : '';
			if (self::wantsJson()) { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'server'] + ($msg !== '' ? ['message' => $msg] : [])); return; }
			echo '<!doctype html><meta charset="utf-8"><title>' . h(t('err_unexpected')) . '</title><body><h1>' . h(t('err_unexpected')) . '</h1><p>' . h($msg !== '' ? $msg : t('err_unexpected_hint')) . '</p></body>';
		});
		$cwd = getcwd();
		chdir(dirname($file));
		ob_start();
		self::$obLevel = ob_get_level() - 1;
		try {
			(static function (NuPageContext $nu, string $__file): void { require $__file; })($ctx, $file);
		} catch (NuDemoBlocked) {
			self::fail(403, 'demo', t('demo_not_available'), t('demo_blocked_hint'));
		} catch (Throwable $e) {
			error_log('nuBuilder Next page ' . $code . ': ' . $e);
			$detail = $ga ? get_class($e) . ': ' . $e->getMessage() . ' – ' . self::relPath($e->getFile()) . ':' . $e->getLine() : '';
			self::fail(500, 'server', t('page_err_exception'), $detail);
		} finally {
			if ($cwd !== false) @chdir($cwd);
		}
		while (ob_get_level() > self::$obLevel + 1) ob_end_flush();
		if (ob_get_level() > self::$obLevel) ob_end_flush();
		exit;
	}

	/** A path for error messages: relative to nuBuilder Next or the pages folder. */
	public static function relPath(string $f): string
	{
		foreach ([self::dir() => 'pages/', NU_ROOT => ''] as $base => $pre) {
			$b = rtrim(str_replace('\\', '/', $base), '/') . '/';
			$x = str_replace('\\', '/', $f);
			if (str_starts_with($x, $b)) return ($base === self::dir() ? 'custom/pages/' : '') . substr($x, strlen($b));
		}
		return basename($f);
	}

	/** Serves a file of the page's assets/ folder (route p=pageasset). */
	public static function asset(): never
	{
		$meta = self::form();
		$code = $meta->code();
		$rel = (string)($_GET['file'] ?? '');
		$folder = self::folder($code);
		$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
		if ($folder === '' || !self::validAssetPath($rel) || !isset(self::ASSET_TYPES[$ext])) self::fail(404, 'not_found', t('err_not_found'));
		$base = realpath($folder . '/assets');
		$file = realpath($folder . '/' . $rel);
		if ($base === false || $file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) self::fail(404, 'not_found', t('err_not_found'));
		session_write_close();
		$mtime = (int)filemtime($file);
		$size = (int)filesize($file);
		$etag = '"' . substr(sha1($file . '|' . $mtime . '|' . $size), 0, 20) . '"';
		header_remove('Content-Security-Policy');
		header_remove('Pragma');
		header_remove('Expires');
		header('Content-Type: ' . self::ASSET_TYPES[$ext]);
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, max-age=3600');
		header('ETag: ' . $etag);
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
		if ($ext === 'svg') header('Content-Security-Policy: sandbox');
		if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
		header('Content-Length: ' . $size);
		readfile($file);
		exit;
	}

	/** assets/… relative path without .., absolute parts, backslashes or hidden files. */
	public static function validAssetPath(string $rel): bool
	{
		if ($rel === '' || strlen($rel) > 300 || !str_starts_with($rel, 'assets/')) return false;
		if (str_contains($rel, '\\') || str_contains($rel, "\0") || str_contains($rel, '//') || str_contains($rel, ':')) return false;
		foreach (explode('/', $rel) as $part) if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) return false;
		return (bool)preg_match('~^[A-Za-z0-9._/ -]+$~', $rel);
	}

	// ================================================================= API versions (0.8.388)

	/** What this nuBuilder Next offers to pages – also written into the update manifest (page_api) by dev/release.py. */
	public static function apiInfo(): array
	{
		$methods = [];
		foreach ((new ReflectionClass(NuPageContext::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
			if (!$m->isStatic() && !str_starts_with($m->getName(), '__')) $methods[] = $m->getName();
		}
		sort($methods);
		return ['current' => NuPageContext::API_VERSION, 'supported' => self::API_SUPPORTED, 'methods' => $methods, 'deprecated' => self::API_DEPRECATED,
			'js' => self::JS_API, 'js_deprecated' => self::JS_DEPRECATED];
	}

	/** The version a page source declares with $nu->api(<n>); null = none (counts as 1). */
	public static function declaredApi(string $src): ?int
	{
		return preg_match('/\$nu\s*->\s*api\s*\(\s*(\d{1,3})\s*\)/', $src, $m) ? (int)$m[1] : null;
	}

	/**
	 * What a page folder uses: declared version, $nu->… methods (PHP files) and NuPage.… names (PHP and JS files) with the files they are in.
	 * @return array{declared:?int, php:array<string,list<string>>, js:array<string,list<string>>, files:int}
	 */
	public static function scan(string $code): array
	{
		$out = ['declared' => null, 'php' => [], 'js' => [], 'files' => 0];
		$folder = self::folder($code);
		if ($folder === '' || !is_dir($folder)) return $out;
		$entry = self::file($code);
		if ($entry !== null) $out['declared'] = self::declaredApi((string)@file_get_contents($entry));
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			$ext = strtolower($f->getExtension());
			if (!in_array($ext, ['php', 'js', 'mjs'], true) || $f->getSize() > 4_000_000 || $f->getFilename() === 'secrets.php') continue;
			if (++$out['files'] > 500) break;
			$src = (string)@file_get_contents($f->getPathname());
			$rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $folder)) + 1);
			if ($ext === 'php' && preg_match_all('/\$nu\s*->\s*([A-Za-z_]\w*)\s*\(/', $src, $m)) {
				foreach (array_unique($m[1]) as $name) $out['php'][$name][] = $rel;
			}
			if (preg_match_all('/\bNuPage\s*\.\s*([A-Za-z_]\w*)/', $src, $m)) {
				foreach (array_unique($m[1]) as $name) $out['js'][$name][] = $rel;
			}
		}
		return $out;
	}

	/**
	 * Problems of a scanned page against an API (apiInfo() of this or of a new version): errors break the page, warnings are deprecated calls.
	 * @return list<array{level:string, text:string}>
	 */
	public static function check(array $scan, array $api): array
	{
		$issues = [];
		$v = $scan['declared'] ?? 1;
		if (!in_array($v, array_map('intval', (array)($api['supported'] ?? [])), true)) {
			$issues[] = ['level' => 'error', 'text' => t('page_api_unsupported', (string)$v, implode(', ', (array)($api['supported'] ?? [])))];
		}
		$methods = array_flip((array)($api['methods'] ?? []));
		foreach ($scan['php'] as $name => $files) {
			$where = implode(', ', array_slice(array_unique($files), 0, 3));
			if (!isset($methods[$name])) $issues[] = ['level' => 'error', 'text' => t('page_api_unknown', '$nu->' . $name . '()', $where)];
			elseif (isset($api['deprecated'][$name])) $issues[] = ['level' => 'warning', 'text' => t('page_api_deprecated', '$nu->' . $name . '()', (string)$api['deprecated'][$name][0], (string)$api['deprecated'][$name][1], $where)];
		}
		$js = array_flip((array)($api['js'] ?? []));
		foreach ($scan['js'] as $name => $files) {
			$where = implode(', ', array_slice(array_unique($files), 0, 3));
			if (!isset($js[$name])) $issues[] = ['level' => 'error', 'text' => t('page_api_unknown', 'NuPage.' . $name, $where)];
			elseif (isset($api['js_deprecated'][$name])) $issues[] = ['level' => 'warning', 'text' => t('page_api_deprecated', 'NuPage.' . $name, (string)$api['js_deprecated'][$name][0], (string)$api['js_deprecated'][$name][1], $where)];
		}
		return $issues;
	}

	/**
	 * Settings keys a page reads (0.8.391): every $nu->setting('key'[, default]) in its PHP files – key => [defaults, files, dynamic].
	 * A key followed by a concatenation ($nu->setting('clients.' . $c)) is a dynamic key: its literal part ends with '.'.
	 * all = the page also calls $nu->settings() (reads everything – no key can be called unused).
	 * @return array{keys: array<string, array{defaults: list<string>, files: list<string>, dynamic: bool}>, all: bool}
	 */
	public static function settingKeys(string $code): array
	{
		$out = ['keys' => [], 'all' => false];
		$folder = self::folder($code);
		if ($folder === '' || !is_dir($folder)) return $out;
		$n = 0;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if (strtolower($f->getExtension()) !== 'php' || $f->getFilename() === 'secrets.php' || $f->getSize() > 4_000_000 || ++$n > 500) continue;
			$src = (string)@file_get_contents($f->getPathname());
			$rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $folder)) + 1);
			if (preg_match('/\$nu\s*->\s*settings\s*\(\s*\)/', $src)) $out['all'] = true;
			if (!preg_match_all('/\$nu\s*->\s*setting\s*\(\s*([\'"])([A-Za-z0-9_.\-]{1,120})\1\s*(?:(\.)[^)]*|,\s*((?:[^()]|\((?:[^()]|\([^()]*\))*\))*?))?\s*\)/', $src, $m, PREG_SET_ORDER)) continue;
			foreach ($m as $x) {
				$key = $x[2];
				$dyn = ($x[3] ?? '') === '.';
				$def = !$dyn && isset($x[4]) ? trim(preg_replace('/\s+/', ' ', $x[4]) ?? '') : '';
				$e = $out['keys'][$key] ?? ['defaults' => [], 'files' => [], 'dynamic' => false];
				if ($def !== '' && !in_array($def, $e['defaults'], true)) $e['defaults'][] = mb_strimwidth($def, 0, 80, '…');
				if (!in_array($rel, $e['files'], true)) $e['files'][] = $rel;
				$e['dynamic'] = $e['dynamic'] || $dyn;
				$out['keys'][$key] = $e;
			}
		}
		ksort($out['keys']);
		return $out;
	}

	/** Built-in examples of the settings JSON (0.8.394, form builder → Examples): label key => [JSON, how a page reads it]. */
	public const SETTING_SAMPLES = [
		'page_sample_lists' => ['{"title": "Order board", "statuses": ["open", "in progress", "done"], "colours": {"open": "#1d6fd8", "done": "#2e7d32"}}',
			"\$nu->setting('statuses', [])  ·  \$nu->setting('colours.done', '#333')"],
		'page_sample_limits' => ['{"rows_per_page": 50, "max_upload_mb": 10, "show_archived": false, "refresh_seconds": 300}',
			"\$nu->setting('rows_per_page', 20)  ·  \$nu->setting('show_archived', false)"],
		'page_sample_clients' => ['{"default_client": "north", "clients": {"north": {"name": "North", "logo": "assets/north.svg", "colour": "#1d6fd8"}, "south": {"name": "South", "logo": "assets/south.svg", "colour": "#2e7d32"}}}',
			"\$nu->setting('clients.' . \$client . '.colour')  ·  \$nu->setting('default_client')"],
	];

	/**
	 * A settings JSON built from the keys a page reads (settingKeys): dot paths become nested objects, literal defaults their values
	 * (numbers, strings, true / false / null, [] / {}), other defaults and keys without one null. '' when there are no keys.
	 */
	public static function settingsFromKeys(array $keys): string
	{
		$out = [];
		foreach ($keys as $key => $e) {
			if (!empty($e['dynamic'])) continue;
			$val = null;
			$d = trim((string)($e['defaults'][0] ?? ''));
			if (preg_match('/^-?\d+(\.\d+)?$/', $d)) $val = str_contains($d, '.') ? (float)$d : (int)$d;
			elseif (preg_match('/^([\'"])(.*)\1$/s', $d, $m)) $val = stripcslashes($m[2]);
			elseif (in_array(strtolower($d), ['true', 'false'], true)) $val = strtolower($d) === 'true';
			elseif ($d === '[]' || strtolower($d) === 'array()') $val = [];
			elseif ($d === '{}') $val = new stdClass();
			$ref = &$out;
			$parts = explode('.', (string)$key);
			foreach ($parts as $i => $part) {
				if ($i === count($parts) - 1) { if (!isset($ref[$part]) || !is_array($ref[$part])) $ref[$part] = $val; }
				else { if (!isset($ref[$part]) || !is_array($ref[$part])) $ref[$part] = []; $ref = &$ref[$part]; }
			}
			unset($ref);
		}
		return $out ? (string)json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	}

	/** settings.example.json of a page folder (valid JSON object, at most 64 KB), pretty-printed – '' when there is none. */
	public static function settingsExample(string $code): string
	{
		$folder = self::folder($code);
		$f = $folder !== '' ? $folder . '/settings.example.json' : '';
		if ($f === '' || !is_file($f) || filesize($f) > 65536) return '';
		$d = json_decode((string)file_get_contents($f), true);
		return is_array($d) ? (string)json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	}

	/** Problems of one page form's folder with this version (cached per request). */
	public static function issues(string $code): array
	{
		static $cache = [];
		return $cache[$code] ??= self::check(self::scan($code), self::apiInfo());
	}

	/**
	 * All page forms checked against an API – the update page with the page_api of a new package (null = this version).
	 * @return list<array{id:string, code:string, description:string, declared:?int, issues:list<array{level:string,text:string}>}>
	 */
	public static function report(?array $api = null): array
	{
		$api ??= self::apiInfo();
		$out = [];
		try {
			foreach (nu_db()->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE sfo_type = 'page' ORDER BY sfo_code") as $f) {
				$scan = self::scan((string)$f['sfo_code']);
				$out[] = ['id' => (string)$f['zzzzsys_form_id'], 'code' => (string)$f['sfo_code'], 'description' => (string)$f['sfo_description'],
					'declared' => $scan['declared'], 'exists' => self::file((string)$f['sfo_code']) !== null, 'issues' => self::check($scan, $api)];
			}
		} catch (Throwable $e) {
			error_log('nuBuilder Next page report: ' . $e->getMessage());
		}
		return $out;
	}

	/** A deprecated method was called: a notice in the debug log, at most once a day per page and method. */
	public static function noteDeprecated(string $code, string $what): void
	{
		$key = $code . '|' . $what . '|' . date('Y-m-d');
		if (isset($_SESSION['nu_page_dep'][$key])) return;
		try {
			$msg = 'Deprecated in page ' . $code . ': ' . $what;
			$since = strtotime('today');
			if (nu_db()->tableExists(NuDebugLog::TABLE) && (int)nu_db()->value('SELECT COUNT(*) FROM ' . NuDebugLog::TABLE . ' WHERE deb_message = ? AND deb_added >= ?', [$msg, $since]) === 0) {
				NuDebugLog::write($msg, 'page:' . $code);
			}
			if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['nu_page_dep'][$key] = 1;
		} catch (Throwable) {}
	}

	/** The $nu class for a declared version: the current one, or the adapter NuPageContextV<n> of an older version that is still supported. */
	public static function contextClass(?int $declared): string
	{
		$v = $declared ?? 1;
		if ($v !== NuPageContext::API_VERSION && in_array($v, self::API_SUPPORTED, true) && class_exists('NuPageContextV' . $v)) return 'NuPageContextV' . $v;
		return NuPageContext::class;
	}

	// ================================================================= builder helpers

	/** Copies _template/ into the folder of the form code. @return array{ok:bool, message:string} */
	public static function createFromTemplate(string $code): array
	{
		if (!self::validCode($code)) return ['ok' => false, 'message' => t('page_err_code', $code)];
		$src = self::dir() . '/_template';
		$dst = self::dir() . '/' . $code;
		if (!is_dir($src)) return ['ok' => false, 'message' => t('page_tpl_missing', self::shownFolder('_template'))];
		if (file_exists($dst)) return ['ok' => false, 'message' => t('page_tpl_exists', self::shownFolder($code))];
		if (!is_writable(self::dir())) return ['ok' => false, 'message' => t('page_tpl_not_writable', self::shownFolder($code))];
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		if (!@mkdir($dst, 0755)) return ['ok' => false, 'message' => t('page_tpl_not_writable', self::shownFolder($code))];
		foreach ($it as $f) {
			$to = $dst . '/' . substr($f->getPathname(), strlen($src) + 1);
			if ($f->isDir()) @mkdir($to, 0755);
			elseif (!@copy($f->getPathname(), $to)) return ['ok' => false, 'message' => t('page_tpl_not_writable', self::shownFolder($code))];
		}
		NuLog::write('page_template', self::TABLE, $code, ['folder' => self::shownFolder($code)]);
		return ['ok' => true, 'message' => t('page_tpl_done', self::shownFolder($code))];
	}

	/** Text for "Copy AI prompt": the guide, the page's data and the application tables. */
	public static function aiPrompt(NuFormMeta $meta): string
	{
		$s = self::settings($meta->id);
		$guide = (string)@file_get_contents(NU_ROOT . '/custom/PAGE_GUIDE.md');
		$out = "You are writing a nuBuilder Next page (form type \"page\"). Follow the guide exactly.\n\n"
			. "===== PAGE_GUIDE.md =====\n" . trim($guide) . "\n\n===== This page =====\n"
			. 'Form code / folder: ' . $meta->code() . ' (custom/pages/' . $meta->code() . "/)\n"
			. 'Title: ' . $meta->description() . "\n"
			. 'Open mode: ' . $s['open_mode'] . "\n"
			. 'Page API version: ' . NuPageContext::API_VERSION . ' – put $nu->api(' . NuPageContext::API_VERSION . "); as the second line of index.php\n"
			. "Settings JSON (read with \$nu->setting()):\n" . $s['settings'] . "\n\n===== Database tables =====\n";
		$db = nu_db();
		$keep = ['zzzzsys_user', 'zzzzsys_access', 'zzzzsys_permission_item', 'zzzzsys_user_permission'];
		try {
			$tables = $db->type === 'sqlsrv'
				? $db->all("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
				: $db->all("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
			$n = 0;
			foreach ($tables as $row) {
				$t = (string)$row['t'];
				if (str_starts_with(strtolower($t), 'zzzzsys_') && !in_array(strtolower($t), $keep, true)) continue;
				if (str_starts_with(strtolower($t), 'zzzzsys_') && in_array(strtolower($t), $keep, true)) {
					$cols = array_filter(array_keys($db->columns($t)), static fn($c) => !in_array($c, ['sus_login_password', 'sus_json'], true));
				} else {
					$cols = [];
					foreach ($db->columns($t) as $c => $info) $cols[] = $c . ' ' . ($info['type'] ?? '');
				}
				$out .= $t . ': ' . implode(', ', $cols) . "\n";
				if (++$n >= 300) { $out .= "…\n"; break; }
			}
		} catch (Throwable $e) {
			$out .= "(tables could not be read)\n";
		}
		return $out . "\n===== The app =====\nDescribe the app here: ...\n";
	}
}

/**
 * $nu – the page API (API_VERSION 1). The only contract page files rely on: internal classes may change between versions, this
 * class only with a new API version. Every method is listed in custom/PAGE_GUIDE.md.
 */
class NuPageContext
{
	public const API_VERSION = 1;

	private ?array $user = null;
	private ?array $secrets = null;
	private array $set;

	public function __construct(protected NuFormMeta $meta, protected string $dir, array $settings)
	{
		$d = json_decode((string)$settings['settings'], true);
		$this->set = is_array($d) ? $d : [];
	}

	// ================================================================= who is logged in

	/**
	 * The signed-in user: id, login, name, first_name, last_name, display, email, team, department, position, permissions (idents),
	 * code, language, access_id, access_code, access_name, globeadmin (bool), impersonated (bool). Strings are '' when empty.
	 */
	public function user(): array
	{
		if ($this->user !== null) return $this->user;
		$u = NuAuth::user() ?? [];
		$out = ['id' => (string)($u['id'] ?? ''), 'login' => (string)($u['login'] ?? ''), 'name' => '', 'first_name' => '', 'last_name' => '',
			'display' => (string)($u['display'] ?? ''), 'email' => (string)($u['email'] ?? ''), 'team' => '', 'department' => '', 'position' => '',
			'permissions' => [], 'code' => '', 'language' => nu_lang_choice(), 'access_id' => (string)($u['access_id'] ?? ''), 'access_code' => '',
			'access_name' => '', 'globeadmin' => !empty($u['globeadmin']), 'impersonated' => NuImpersonate::active() !== null];
		if (!$out['globeadmin'] && $out['id'] !== '') {
			$db = nu_db();
			$row = $db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$out['id']]) ?? [];
			foreach (['name' => 'sus_name', 'first_name' => 'sus_first_name', 'last_name' => 'sus_last_name', 'email' => 'sus_email', 'team' => 'sus_team',
					  'department' => 'sus_department', 'position' => 'sus_position', 'code' => 'sus_code'] as $k => $col) {
				$out[$k] = trim((string)($row[$col] ?? ''));
			}
			$acc = $out['access_id'] !== '' ? $db->one('SELECT sal_code, sal_description FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$out['access_id']]) : null;
			$out['access_code'] = (string)($acc['sal_code'] ?? '');
			$out['access_name'] = (string)($acc['sal_description'] ?? '');
			$out['permissions'] = array_values(array_map('strval', NuPermission::idents($out['id'])));
		}
		return $this->user = $out;
	}

	public function userId(): string { return $this->user()['id']; }

	public function isGlobeadmin(): bool { return $this->user()['globeadmin']; }

	/** The user who really acts while impersonating (id, login, globeadmin), else null. */
	public function realUser(): ?array
	{
		$i = NuImpersonate::active();
		if (!$i) return null;
		return ['id' => (string)($i['orig']['id'] ?? ''), 'login' => (string)$i['by'], 'globeadmin' => !empty($i['orig']['globeadmin'])];
	}

	/** Holds ANY of these permission items (the globeadmin: always). */
	public function hasPermission(string ...$idents): bool
	{
		if ($this->isGlobeadmin()) return true;
		$mine = array_map('strtolower', $this->permissions());
		foreach ($idents as $i) if (in_array(strtolower(trim($i)), $mine, true)) return true;
		return false;
	}

	/** Permission item idents of the user. */
	public function permissions(): array { return $this->user()['permissions']; }

	public function inTeam(string ...$teams): bool { return self::matches($this->user()['team'], $teams); }

	public function inDepartment(string ...$deps): bool { return self::matches($this->user()['department'], $deps); }

	public function hasPosition(string ...$pos): bool { return self::matches($this->user()['position'], $pos); }

	private static function matches(string $value, array $list): bool
	{
		$v = mb_strtolower(trim($value));
		if ($v === '') return false;
		foreach ($list as $x) if (mb_strtolower(trim((string)$x)) === $v) return true;
		return false;
	}

	/** A right on any form (id or code): view, add, save, delete, clone, new, print, create. */
	public function can(string $form, string $right = 'view'): bool
	{
		$id = self::formId($form);
		return $id !== null && NuAcl::can($id, $right);
	}

	private static function formId(string $form): ?string
	{
		if (isset(nu_forms()[$form])) return $form;
		foreach (nu_forms() as $id => $f) if ($f['code'] === $form) return (string)$id;
		return null;
	}

	/** Rights on THIS page form (view, add, save, delete, clone, new, print, create) – the access level as the app's roles. */
	public function rights(): array { return NuAcl::rights($this->meta->id); }

	/** 403 (HTML or JSON) unless $ok. */
	public function require(bool $ok, string $message = ''): void
	{
		if (!$ok) $this->deny($message);
	}

	/**
	 * Other users for pickers and lists: id, login, name, first_name, last_name, display, email, team, department, position.
	 * Filter keys: team, department, position, permission (ident), active (true = not expired). Never passwords or sus_json.
	 */
	public function users(array $filter = []): array
	{
		$db = nu_db();
		$rows = $db->all('SELECT zzzzsys_user_id, sus_login_name, sus_name, sus_first_name, sus_last_name, sus_email, sus_team, sus_department, sus_position, sus_expires_on
			FROM zzzzsys_user ORDER BY sus_login_name');
		$perm = null;
		if (isset($filter['permission'])) {
			$item = (string)($db->value('SELECT zzzzsys_permission_item_id FROM zzzzsys_permission_item WHERE pme_ident = ?', [(string)$filter['permission']]) ?? '');
			$perm = array_flip(array_map('strval', array_column($item !== '' ? NuPermission::users($item) : [], 'zzzzsys_user_id')));
		}
		$out = [];
		foreach ($rows as $r) {
			if (isset($filter['team']) && !self::matches((string)$r['sus_team'], (array)$filter['team'])) continue;
			if (isset($filter['department']) && !self::matches((string)$r['sus_department'], (array)$filter['department'])) continue;
			if (isset($filter['position']) && !self::matches((string)$r['sus_position'], (array)$filter['position'])) continue;
			if (!empty($filter['active']) && nu_is_expired($r['sus_expires_on'])) continue;
			if ($perm !== null && !isset($perm[(string)$r['zzzzsys_user_id']])) continue;
			$full = trim($r['sus_first_name'] . ' ' . $r['sus_last_name']);
			$out[] = ['id' => (string)$r['zzzzsys_user_id'], 'login' => (string)$r['sus_login_name'], 'name' => (string)$r['sus_name'],
				'first_name' => (string)$r['sus_first_name'], 'last_name' => (string)$r['sus_last_name'],
				'display' => $full !== '' ? $full : ((string)$r['sus_name'] !== '' ? (string)$r['sus_name'] : (string)$r['sus_login_name']),
				'email' => (string)$r['sus_email'], 'team' => (string)$r['sus_team'], 'department' => (string)$r['sus_department'], 'position' => (string)$r['sus_position']];
		}
		return $out;
	}

	// ================================================================= context

	/** id, code, description of the page form. */
	public function form(): array
	{
		return ['id' => $this->meta->id, 'code' => $this->meta->code(), 'description' => $this->meta->description()];
	}

	/** Folder name (= form code). */
	public function slug(): string { return $this->meta->code(); }

	/** Absolute folder path. */
	public function dir(): string { return $this->dir; }

	/** r of the URL (null when absent; '-1' = new). Not an access check. */
	public function recordId(): ?string
	{
		$r = $_GET['r'] ?? null;
		return is_string($r) && $r !== '' ? $r : null;
	}

	/** The opening form: form_id, form_code, record_id – only with the view right on it; else null. */
	public function parent(): ?array
	{
		$pf = $_GET['pf'] ?? null;
		if (!is_string($pf) || $pf === '') return null;
		$id = self::formId($pf);
		if ($id === null || !NuAcl::can($id)) return null;
		$pr = $_GET['pr'] ?? null;
		return ['form_id' => $id, 'form_code' => (string)(nu_forms()[$id]['code'] ?? $id), 'record_id' => is_string($pr) && $pr !== '' ? $pr : null];
	}

	/** nuSetProperty values of the opening form. */
	public function props(): array
	{
		$ps = $_GET['ps'] ?? null;
		return NuProps::load(is_string($ps) ? $ps : '')[0];
	}

	/** Replaces #USER_ID#, #RECORD_ID#, #LOGIN_NAME#, #FORM_CODE# … like nuBuilder. */
	public function hash(string $text): string
	{
		$p = $this->parent();
		$extra = ['FORM_ID' => $this->meta->id, 'FORM_CODE' => $this->meta->code(), 'RECORD_ID' => (string)($this->recordId() ?? ''),
			'PARENT_FORM_ID' => (string)($p['form_id'] ?? ''), 'PARENT_RECORD_ID' => (string)($p['record_id'] ?? '')];
		return NuHash::replace($text, NuHash::plain($this->props(), $extra));
	}

	/** A value of the page settings JSON; dot paths: setting('clients.north.colour'). */
	public function setting(string $key, mixed $default = null): mixed
	{
		$v = $this->set;
		foreach (explode('.', $key) as $part) {
			if (!is_array($v) || !array_key_exists($part, $v)) return $default;
			$v = $v[$part];
		}
		return $v;
	}

	public function settings(): array { return $this->set; }

	/** A GET / POST value as string (POST wins); null when absent or an array. */
	public function param(string $name, ?string $default = null): ?string
	{
		$v = $_POST[$name] ?? $_GET[$name] ?? null;
		return is_string($v) ? $v : $default;
	}

	public function isPost(): bool { return nu_is_post(); }

	public function isAjax(): bool { return NuPage::wantsJson(); }

	/** ?action= of the URL ('' when absent). */
	public function action(): string
	{
		$a = $_GET['action'] ?? '';
		return is_string($a) ? $a : '';
	}

	/** The user's language, e.g. en-GB or de-CH. */
	public function lang(): string { return nu_lang_choice(); }

	/** light | dark | auto – the user's choice in nuBuilder Next. */
	public function theme(): string
	{
		$t = (string)($_COOKIE['nunext_theme'] ?? 'light');
		return in_array($t, ['light', 'dark', 'auto'], true) ? $t : 'light';
	}

	/** true in demo mode and while the globeadmin views as another user: hide write actions. */
	public function readOnly(): bool
	{
		return NuDemo::active() || NuImpersonate::readOnly();
	}

	// ================================================================= data

	/** nuBuilder Next's database connection (ERRMODE_EXCEPTION, associative fetch). */
	public function db(): PDO { return nu_db()->pdo(); }

	/** mysql | sqlsrv */
	public function dbType(): string { return nu_db()->type; }

	public function all(string $sql, array $params = []): array { return nu_db()->all($sql, $params); }

	public function one(string $sql, array $params = []): ?array { return nu_db()->one($sql, $params); }

	public function value(string $sql, array $params = []): mixed { return nu_db()->value($sql, $params); }

	/** Runs a statement, returns the affected rows. */
	public function exec(string $sql, array $params = []): int { return nu_db()->run($sql, $params)->rowCount(); }

	/** Inserts a row; <table>_id is filled with a new id when missing. Returns the id. */
	public function insert(string $table, array $row): string
	{
		self::name($table);
		foreach (array_keys($row) as $c) self::name((string)$c);
		$pk = $table . '_id';
		if (!array_key_exists($pk, $row) || $row[$pk] === null || $row[$pk] === '') $row = [$pk => nu_id()] + $row;
		nu_db()->insert($table, $row);
		return (string)$row[$pk];
	}

	/** Updates the row with <table>_id = $id; returns the affected rows. */
	public function update(string $table, string $id, array $row): int
	{
		self::name($table);
		foreach (array_keys($row) as $c) self::name((string)$c);
		return nu_db()->update($table, $row, $table . '_id', $id);
	}

	/** Deletes the row with <table>_id = $id. */
	public function delete(string $table, string $id): int
	{
		self::name($table);
		return nu_db()->delete($table, $table . '_id', $id);
	}

	private static function name(string $n): void
	{
		if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $n)) throw new InvalidArgumentException('Invalid table or column name: ' . mb_substr($n, 0, 80));
	}

	/** Runs $fn($nu) in a transaction (rolled back on an exception). */
	public function transaction(callable $fn): mixed
	{
		return nu_db()->transaction(fn() => $fn($this));
	}

	public function newId(): string { return nu_id(); }

	/** A change in nuBuilder Next's audit trail ($action insert | update | delete). The globeadmin acting as a user is noted. */
	public function audit(string $table, string $id, string $action, array $before = [], array $after = []): void
	{
		if (!in_array($action, ['insert', 'update', 'delete'], true)) throw new InvalidArgumentException('audit action: insert, update or delete');
		NuAudit::record($action, $table, $id, $before, $after, ['source' => 'page', 'form_id' => $this->meta->id,
			'form_code' => $this->meta->code() . (($r = $this->realUser()) ? ' (' . $r['login'] . ')' : '')]);
	}

	// ================================================================= output and links

	/** HTML escape. */
	public function h(mixed $s): string { return h($s); }

	public function csrfToken(): string { return nu_csrf_token(); }

	/** <input type="hidden" name="_csrf" …> for POST forms. */
	public function csrfField(): string { return nu_csrf_field(); }

	/** URL of this page (keeps f, r, pf, pr, ps): url(['action' => 'save']). */
	public function url(array $params = []): string
	{
		$keep = array_intersect_key(NuPage::carried(), array_flip(['r', 'pf', 'pr', 'ps']));
		return nu_url('page', ['f' => $this->meta->code()] + array_merge($keep, $params));
	}

	/** URL of a file in assets/ of the page folder, with a version stamp. */
	public function asset(string $path): string
	{
		$path = ltrim($path, '/');
		$m = @filemtime($this->dir . '/' . $path);
		return nu_url('pageasset', ['f' => $this->meta->code(), 'file' => $path, 'v' => $m ? base_convert((string)$m, 10, 36) : null]);
	}

	/** URL of a form (id or code) – list, or a record ('-1' = new); with the breadcrumb trail back to this page (0.8.422). */
	public function formUrl(string $form, ?string $record = null): string
	{
		return nu_url('form', ['f' => $form, 'r' => $record, 'pf' => $this->meta->id, 'pr' => $this->recordId(), 'bt' => $this->trailKey() ?: null]);
	}

	private ?string $trailKey = null;

	/**
	 * Breadcrumb trail of the forms this page opens (0.8.422) – their Back returns: on its own page (the wrapper passes cbt) to this
	 * page; in a launch form's iframe to that form (its description); else to this page after the trail it was opened with.
	 */
	protected function trailKey(): string
	{
		if ($this->trailKey !== null) return $this->trailKey;
		$cbt = $_GET['cbt'] ?? null;
		if (is_string($cbt) && NuTrail::get($cbt) !== null) return $this->trailKey = $cbt;
		$p = $this->parent();
		if ($p !== null) {
			$pm = NuFormMeta::load($p['form_id']);
			$title = $pm && $pm->description() !== '' ? $pm->description() : $p['form_code'];
			return $this->trailKey = NuTrail::store([['title' => $title, 'url' => nu_url('form', ['f' => $p['form_id'], 'r' => $p['record_id']])]]);
		}
		$title = $this->meta->description() !== '' ? $this->meta->description() : $this->meta->code();
		$back = nu_url('form', ['f' => $this->meta->code() !== '' ? $this->meta->code() : $this->meta->id, 'r' => $this->recordId()]);
		return $this->trailKey = NuTrail::store(array_merge(NuTrail::get(is_string($_GET['bt'] ?? null) ? $_GET['bt'] : null) ?? [], [['title' => $title, 'url' => $back]]));
	}

	public function homeUrl(): string { return nu_url('home'); }

	/** <script> tag of nupage.js (NuPage.fetch, resize, toast …), with the CSRF token as data attribute. */
	public function sdk(): string
	{
		return '<script src="' . h(nu_asset('assets/js/nupage.js')) . '" data-csrf="' . h(nu_csrf_token()) . '" data-home="' . h(nu_url('home')) . '" data-bt="' . h($this->trailKey()) . '"></script>';
	}

	/**
	 * A new Excel workbook (NuXlsx, 0.8.398): $x = $nu->excel('Orders'); $x->sheet('Orders')->header([...])->rows($rows);
	 * $x->download('orders.xlsx') – see custom/PAGE_GUIDE.md "Excel export".
	 */
	public function excel(string $title = ''): NuXlsx { return NuXlsx::create($title !== '' ? $title : $this->meta->description()); }

	/** Sends JSON and ends the page. */
	public function json(mixed $data, int $status = 200): never
	{
		self::clear();
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	}

	/** Redirects (same origin only) and ends the page. */
	public function redirect(string $url): never
	{
		if (preg_match('~[\r\n]~', $url) || str_starts_with($url, '//') || str_contains($url, '\\')) throw new InvalidArgumentException('redirect: invalid URL');
		if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
			$p = parse_url($url);
			$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
			$h = strtolower((string)($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
			if (!in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || $h !== $host) throw new InvalidArgumentException('redirect: other origin');
		}
		self::clear();
		header('Location: ' . $url, true, 303);
		exit;
	}

	/** 404 – HTML or JSON by request type. */
	public function notFound(string $msg = ''): never
	{
		NuPage::fail(404, 'not_found', $msg !== '' ? $msg : t('err_not_found'));
	}

	/** 403 – HTML or JSON by request type. */
	public function deny(string $msg = ''): never
	{
		NuPage::fail(403, 'forbidden', $msg !== '' ? $msg : t('err_forbidden'), $msg !== '' ? '' : t('err_forbidden_hint'));
	}

	/** A notification of the notification centre to users (ids or logins). */
	public function notify(string|array $userIds, string $title, string $text = '', ?string $link = null): void
	{
		if (!NuNotify::ready()) return;
		[, $e] = NuNotify::save('', ['title' => $title, 'body' => $text, 'link' => $link ?? '', 'audience' => ['users' => (array)$userIds]], true, null, false);
		if ($e) throw new InvalidArgumentException('notify: ' . implode(' ', $e));
	}

	/** A line in nuBuilder's debug results (Log → Debug), flagged with the page code. */
	public function log(string $message, array $data = []): void
	{
		NuDebugLog::write($message . ($data ? "\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''), 'page:' . $this->meta->code());
	}

	/** The page's own session store ($_SESSION['nu_page'][<form code>]), by reference. Read-only after releaseSession(). */
	public function &session(): array
	{
		$k = $this->meta->code();
		if (!isset($_SESSION['nu_page'][$k]) || !is_array($_SESSION['nu_page'][$k])) $_SESSION['nu_page'][$k] = [];
		return $_SESSION['nu_page'][$k];
	}

	/** Ends the session lock for slow work (other tabs of the user go on); $nu->session() is read-only afterwards. */
	public function releaseSession(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
	}

	public function apiVersion(): int { return self::API_VERSION; }

	/**
	 * Declares the API version the page is written for – the second line of index.php: $nu->api(1);
	 * (pages without it count as version 1). An unsupported version stops the page with a clear message (also checked before the file runs).
	 */
	public function api(int $version): void
	{
		if (!in_array($version, NuPage::API_SUPPORTED, true)) {
			$u = NuAuth::user();
			NuPage::fail(500, 'page_api', t('page_api_title'), !empty($u['globeadmin']) && empty($u['demo']) ? t('page_api_unsupported', (string)$version, implode(', ', NuPage::API_SUPPORTED)) : '');
		}
	}

	/** For deprecated methods (NuPage::API_DEPRECATED): call at their start – a debug log notice once a day per page. */
	protected function deprecated(string $method): void
	{
		NuPage::noteDeprecated($this->meta->code(), '$nu->' . $method . '()');
	}

	/** A value of secrets.php in the page folder (read once per request). */
	public function secret(string $key, mixed $default = null): mixed
	{
		if ($this->secrets === null) {
			$f = $this->dir . '/secrets.php';
			$v = is_file($f) ? (static fn(string $__f) => require $__f)($f) : [];
			$this->secrets = is_array($v) ? $v : [];
		}
		return array_key_exists($key, $this->secrets) ? $this->secrets[$key] : $default;
	}

	private static function clear(): void
	{
		while (ob_get_level() > NuPage::$obLevel && ob_get_level() > 0) ob_end_clean();
	}
}
