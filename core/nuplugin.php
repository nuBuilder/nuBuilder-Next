<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Plugins: third-party extensions in plugins/<id>/ (plugin.json + plugin.php + own files). The plugin's PHP talks to nuBuilder Next only
 * through NuPluginApi ($plugin in plugin.php) – a fixed contract with a version (API_VERSION); a version is only dropped after it was
 * marked deprecated, and the update page lists plugins a new release would stop (report()).
 *
 * plugin.json: {id, name, version, description, author, url, api, requires: {nunext, php, extensions: [], db: ["mysql", "sqlsrv"]},
 *               tables: [short names], network: bool, files: {rel: {sha256, size}}, signature}
 * The signature (Ed25519, base64) covers payload() – id, version and the hashes of every file but plugin.json. Keys: nuBuilder Next's
 * own (NuUpdate::SIGN_PUBLIC) and the trusted keys of Update → Plugins. Signed with php dev/plugin_sign.php.
 *
 * State in the settings row 'plugins': {upload: bool, keys: [{name, key}], on: {id: {at, by, version}}, failed: {id: {at, error}}}.
 * Loaded on first use (boot()) – not with ?safe=1 of the globeadmin, never in the installer. A plugin that throws (or ends PHP with a
 * fatal error inside its folder) is switched off and logged; the rest of the request carries on without it.
 */
final class NuPlugin
{
	public const API_VERSION = 1;
	public const API_SUPPORTED = [1];
	public const API_DEPRECATED = [];
	public const SETTING = 'plugins';
	public const ID_RE = '/^[a-z][a-z0-9_]{1,19}$/';          // short: the settings row plg:<id> has 25 characters
	public const NAME_RE = '/^[a-z][a-z0-9_]{0,23}$/';        // routes, tiles, rights, tables, tasks of a plugin
	public const EVENTS = ['record.before_save', 'record.after_save', 'record.before_delete', 'record.after_delete', 'login', 'mail.before_send',
		'housekeeping', 'list.def'];
	public const ASSET_TYPES = ['js' => 'text/javascript', 'css' => 'text/css', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'woff2' => 'font/woff2', 'json' => 'application/json'];
	public const MAX_ZIP = 20 * 1024 * 1024;

	/** @var array<string, NuPluginApi> plugins loaded in this request */
	private static array $loaded = [];
	private static bool $booted = false;
	/** id of the plugin whose code runs now – a fatal error inside it switches it off */
	private static ?string $running = null;
	private static bool $shutdown = false;
	/** test seam: the time / user written into the state */
	public static ?string $by = null;

	// ================================================================== folders and manifests

	public static function dir(): string
	{
		return NU_ROOT . '/plugins';
	}

	public static function folder(string $id): string
	{
		return self::dir() . '/' . $id;
	}

	/** Installed plugins (folders with a plugin.json): id => [dir, manifest|null, error]. */
	public static function installed(): array
	{
		$out = [];
		foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
			$id = basename($d);
			if (!preg_match(self::ID_RE, $id)) continue;            // .tmp-…, _example, other names: not a plugin
			[$m, $err] = self::manifest($d);
			if ($m !== null && $m['id'] !== $id) [$m, $err] = [null, t('plg_err_id_folder', $m['id'], $id)];
			$out[$id] = ['dir' => $d, 'manifest' => $m, 'error' => $err];
		}
		ksort($out);
		return $out;
	}

	/** plugin.json of a folder: [manifest, ''] or [null, error]. */
	public static function manifest(string $dir): array
	{
		$f = $dir . '/plugin.json';
		if (!is_file($f)) return [null, t('plg_err_manifest', 'plugin.json')];
		$m = json_decode((string)file_get_contents($f), true);
		if (!is_array($m)) return [null, t('plg_err_manifest', json_last_error_msg())];
		return self::cleanManifest($m);
	}

	/** Checks the shape of a manifest: [manifest, ''] or [null, error]. */
	public static function cleanManifest(array $m): array
	{
		$id = (string)($m['id'] ?? '');
		if (!preg_match(self::ID_RE, $id)) return [null, t('plg_err_id', $id)];
		if (!preg_match('/^\d+(\.\d+){0,3}([-+][\w.]+)?$/', (string)($m['version'] ?? ''))) return [null, t('plg_err_manifest', 'version')];
		if (trim((string)($m['name'] ?? '')) === '') return [null, t('plg_err_manifest', 'name')];
		$m['api'] = (int)($m['api'] ?? 0);
		$m['requires'] = is_array($m['requires'] ?? null) ? $m['requires'] : [];
		$m['tables'] = array_values(array_filter(array_map('strval', (array)($m['tables'] ?? [])), static fn($t) => (bool)preg_match(self::NAME_RE, $t)));
		$m['network'] = !empty($m['network']);
		$m['files'] = is_array($m['files'] ?? null) ? $m['files'] : [];
		$m['signature'] = (string)($m['signature'] ?? '');
		foreach (['description', 'author', 'url', 'entry'] as $k) $m[$k] = (string)($m[$k] ?? '');
		if ($m['entry'] === '' || !preg_match('/^[\w\-\/]+\.php$/', $m['entry']) || str_contains($m['entry'], '..')) $m['entry'] = 'plugin.php';
		if ($m['url'] !== '' && !preg_match('~^https://~i', $m['url'])) $m['url'] = '';
		return [$m, ''];
	}

	/** What this installation lacks for the plugin (texts); [] = it can run here. */
	public static function requirements(array $m): array
	{
		$r = $m['requires'] ?? [];
		$bad = [];
		if (!in_array((int)$m['api'], self::API_SUPPORTED, true)) $bad[] = t('plg_req_api', (string)$m['api'], implode(', ', self::API_SUPPORTED));
		if (($v = (string)($r['nunext'] ?? '')) !== '' && version_compare(NU_VERSION, $v, '<')) $bad[] = t('plg_req_nunext', $v);
		if (($v = (string)($r['php'] ?? '')) !== '' && version_compare(PHP_VERSION, $v, '<')) $bad[] = t('plg_req_php', $v);
		foreach ((array)($r['extensions'] ?? []) as $e) if (is_string($e) && $e !== '' && !extension_loaded($e)) $bad[] = t('plg_req_ext', $e);
		$dbs = array_values(array_filter((array)($r['db'] ?? []), 'is_string'));
		if ($dbs && NuDB::$connected && !in_array(nu_db()->type, $dbs, true)) $bad[] = t('plg_req_db', implode(' / ', $dbs));
		return $bad;
	}

	/** The signed text: id, version and the hashes of the listed files. */
	public static function payload(array $m): string
	{
		$files = (array)($m['files'] ?? []);
		ksort($files, SORT_STRING);
		$lines = [];
		foreach ($files as $rel => $f) $lines[] = $rel . ' ' . (string)($f['sha256'] ?? '') . ' ' . (int)($f['size'] ?? 0);
		return 'nunext-plugin|' . (string)($m['id'] ?? '') . '|' . (string)($m['version'] ?? '') . '|' . hash('sha256', implode("\n", $lines));
	}

	/** Files of a plugin folder (relative, sorted) – every file but plugin.json. */
	public static function files(string $dir): array
	{
		$out = [];
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if (!$f->isFile()) continue;
			$rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
			if ($rel !== 'plugin.json') $out[] = $rel;
		}
		sort($out, SORT_STRING);
		return $out;
	}

	/**
	 * State of the signature: ['state' => official | trusted | unsigned | unknown | modified | unchecked, 'by' => key name].
	 * modified = signed, but the files on disk are not the ones that were signed.
	 */
	public static function signature(array $m, string $dir): array
	{
		if ($m['signature'] === '') return ['state' => 'unsigned', 'by' => ''];
		if (!function_exists('sodium_crypto_sign_verify_detached')) return ['state' => 'unchecked', 'by' => ''];
		$keys = [['nuBuilder Next', NuUpdate::SIGN_PUBLIC, 'official']];
		foreach ((array)(self::state()['keys'] ?? []) as $k) $keys[] = [(string)$k['name'], (string)$k['key'], 'trusted'];
		$payload = self::payload($m);
		$hit = null;
		foreach ($keys as [$name, $key, $kind]) if (NuLibs::verify($payload, $m['signature'], $key) === 'valid') { $hit = [$name, $kind]; break; }
		if ($hit === null) return ['state' => 'unknown', 'by' => ''];   // no trusted key verifies it
		$listed = array_map('strval', array_keys($m['files']));
		sort($listed, SORT_STRING);
		if (self::files($dir) !== $listed) return ['state' => 'modified', 'by' => $hit[0]];   // a file added or missing
		foreach ($m['files'] as $rel => $f) {
			$p = $dir . '/' . $rel;
			if (!is_file($p) || filesize($p) !== (int)($f['size'] ?? -1) || hash_file('sha256', $p) !== (string)($f['sha256'] ?? '')) return ['state' => 'modified', 'by' => $hit[0]];
		}
		return ['state' => $hit[1], 'by' => $hit[0]];
	}

	// ================================================================== state

	public static function state(): array
	{
		$s = nu_setting_json(self::SETTING);
		return $s + ['upload' => false, 'keys' => [], 'on' => [], 'failed' => []];
	}

	private static function saveState(array $s): void
	{
		nu_setting_json_save(self::SETTING, $s, self::by());
	}

	private static function by(): string
	{
		return self::$by ?? (PHP_SAPI === 'cli' ? 'cli' : (string)(NuAuth::user()['login'] ?? ''));
	}

	public static function enabled(string $id): bool
	{
		return isset(self::state()['on'][$id]);
	}

	/** Switched off for this request: ?safe=1 of the globeadmin. */
	public static function safe(): bool
	{
		if (($_GET['safe'] ?? '') !== '1' || PHP_SAPI === 'cli') return false;
		$u = NuAuth::user();
		return $u !== null && !empty($u['globeadmin']);
	}

	// ================================================================== loading

	/** Loads the switched-on plugins once per request. */
	public static function boot(): void
	{
		if (self::$booted) return;
		self::$booted = true;
		if (NuInstall::needed() || !NuDB::$connected && PHP_SAPI !== 'cli') return;
		try {
			$on = (array)self::state()['on'];
		} catch (Throwable $ig) {
			nu_ignored($ig, 'no settings table yet');
			return;
		}
		if (!$on || self::safe()) return;
		if (!self::$shutdown) {
			self::$shutdown = true;
			register_shutdown_function([self::class, 'afterFatal']);
		}
		foreach ($on as $id => $_) {
			if (!is_string($id) || !preg_match(self::ID_RE, $id)) continue;
			[$m] = self::manifest(self::folder($id));
			if ($m === null || self::requirements($m)) continue;      // folder gone, not compatible any more: not loaded (stays "on")
			self::load($m);
		}
	}

	/** Includes plugin.php with $plugin = the plugin's API object; true when it ran without an error. */
	private static function load(array $m): bool
	{
		$id = $m['id'];
		$file = self::folder($id) . '/' . $m['entry'];
		if (!is_file($file)) { self::fail($id, t('plg_err_manifest', $m['entry'])); return false; }
		$api = new NuPluginApi($m);
		self::$loaded[$id] = $api;
		$ok = self::call($id, static function () use ($file, $api): void {
			(static function (string $nuPluginFile, NuPluginApi $plugin): void { require $nuPluginFile; })($file, $api);
		}) !== false;
		if (!$ok) unset(self::$loaded[$id]);
		return $ok;
	}

	/** Runs plugin code: its result, or false after an error (the plugin is switched off). */
	public static function call(string $id, callable $fn, array $args = []): mixed
	{
		$prev = self::$running;
		self::$running = $id;
		try {
			return $fn(...$args);
		} catch (Throwable $e) {
			self::fail($id, $e);
			return false;
		} finally {
			self::$running = $prev;
		}
	}

	/** Switches a plugin off after an error and logs it. */
	public static function fail(string $id, Throwable|string $e): void
	{
		$msg = is_string($e) ? $e : get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
		error_log("nuBuilder Next plugin $id: $msg");
		unset(self::$loaded[$id]);
		try {
			$s = self::state();
			unset($s['on'][$id]);
			$s['failed'][$id] = ['at' => date('Y-m-d H:i:s'), 'error' => mb_substr($msg, 0, 500)];
			self::saveState($s);
			NuLog::write('plugin_failed', 'plugin', $id, ['error' => mb_substr($msg, 0, 500)]);
		} catch (Throwable $ig) { nu_ignored($ig, 'plugin state not written'); }
	}

	/** Shutdown: a fatal error in a file of a plugin's folder switches that plugin off. */
	public static function afterFatal(): void
	{
		$e = error_get_last();
		if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) return;
		$file = str_replace('\\', '/', (string)$e['file']);
		$base = str_replace('\\', '/', self::dir()) . '/';
		$id = self::$running;
		if (str_starts_with($file, $base)) $id = explode('/', substr($file, strlen($base)))[0];
		if ($id !== null && preg_match(self::ID_RE, $id)) self::fail($id, $e['message'] . ' (' . basename($file) . ':' . $e['line'] . ')');
	}

	/** @return array<string, NuPluginApi> */
	public static function loaded(): array
	{
		self::boot();
		return self::$loaded;
	}

	public static function get(string $id): ?NuPluginApi
	{
		return self::loaded()[$id] ?? null;
	}

	// ================================================================== events

	/**
	 * Runs the listeners of an event. Events that may refuse (record.before_*, mail.before_send): the first text a listener returns
	 * is the refusal and stops the others. $ctx goes to every listener as its argument.
	 */
	public static function emit(string $event, array $ctx = []): ?string
	{
		foreach (self::listeners($event) as [$id, $fn]) {
			$r = self::call($id, $fn, [$ctx]);
			if (is_string($r) && trim($r) !== '') return $r;
		}
		return null;
	}

	/** Filter events (list.def): each listener gets the value and returns the changed one (null / a wrong type = unchanged). */
	public static function filter(string $event, mixed $value, mixed ...$args): mixed
	{
		foreach (self::listeners($event) as [$id, $fn]) {
			$r = self::call($id, $fn, array_merge([$value], $args));
			if ($r !== null && $r !== false && gettype($r) === gettype($value)) $value = $r;
		}
		return $value;
	}

	/** [plugin id, callable] of an event, by priority (lower first), then by plugin id. */
	private static function listeners(string $event): array
	{
		$all = [];
		foreach (self::loaded() as $id => $api) foreach ($api->hooks($event) as [$prio, $fn]) $all[] = [$prio, $id, $fn];
		usort($all, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
		return array_map(static fn($x) => [$x[1], $x[2]], $all);
	}

	// ================================================================== what plugins add to the pages

	/** Admin rights of the plugins in NuAdmin::RIGHTS's format: plg_<id>_<key> => ['plugins', label, false, levels, []]. */
	public static function rights(): array
	{
		$out = [];
		foreach (self::loaded() as $api) foreach ($api->rightsDef() as $k => [$label, $levels]) $out[$k] = ['plugins', $label, false, $levels, []];
		return $out;
	}

	/** REST scopes of the plugins that have routes: plugin:<id> => description. */
	public static function scopes(): array
	{
		$out = [];
		foreach (self::loaded() as $id => $api) if ($api->apiRoutes()) $out['plugin:' . $id] = t('plg_scope', $api->name());
		return $out;
	}

	/** Start page tiles: key plg_<id>_<key> => catalog entry of NuHome::catalog(). */
	public static function tiles(): array
	{
		$out = [];
		foreach (self::loaded() as $id => $api) foreach ($api->tilesDef() as $k => $t) {
			$route = $t['route'] !== '' ? ['plugin', ['id' => $id, 'r' => $t['route']]] : ['url' => $t['url']];
			$out[$k] = [$route, $t['icon'], $t['label'], $t['hint']];
		}
		return $out;
	}

	/** Visibility of a plugin tile for a user who is not the globeadmin: null = not a plugin tile. */
	public static function tileVisible(string $key): ?bool
	{
		if (!str_starts_with($key, 'plg_')) return null;
		foreach (self::loaded() as $api) {
			$t = $api->tilesDef()[$key] ?? null;
			if ($t === null) continue;
			return $t['right'] !== '' ? NuAdmin::can($t['right'], 'view') : NuAdmin::any();
		}
		return null;
	}

	/** Entries of the user menu: [label, icon, url] the signed-in user may see. */
	public static function menuItems(): array
	{
		$out = [];
		foreach (self::loaded() as $id => $api) foreach ($api->menuDef() as $mi) {
			if ($mi['right'] !== '' && !NuAdmin::can($mi['right'], 'view')) continue;
			$out[] = [$mi['label'], $mi['icon'], $mi['route'] !== '' ? nu_url('plugin', ['id' => $id, 'r' => $mi['route']]) : $mi['url']];
		}
		return $out;
	}

	/** <script> tags of the plugins for app pages ('app') or form pages ('form'). */
	public static function jsTags(string $where): string
	{
		$out = '';
		foreach (self::loaded() as $api) foreach ($api->assetsOf('js', $where) as $url) $out .= '<script src="' . h($url) . '"></script>' . "\n";
		return $out;
	}

	public static function cssTags(string $where): string
	{
		$out = '';
		foreach (self::loaded() as $api) foreach ($api->assetsOf('css', $where) as $url) $out .= '<link rel="stylesheet" href="' . h($url) . '">' . "\n";
		return $out;
	}

	// ================================================================== requests

	/** ?p=plugin&id=<id>&r=<route>: a page or JSON answer of a plugin. */
	public static function route(): void
	{
		$id = (string)($_GET['id'] ?? '');
		$api = preg_match(self::ID_RE, $id) ? self::get($id) : null;
		$r = $api ? ($api->routesDef()[(string)($_GET['r'] ?? '')] ?? null) : null;
		if ($r === null) { http_response_code(404); nu_fatal(t('err_not_found'), '', true); }
		if ($r['right'] === 'globeadmin') {
			if (empty(NuAuth::user()['globeadmin'])) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
		} elseif ($r['right'] !== '') {
			NuAdmin::require($r['right']);
		}
		$req = ['method' => nu_is_post() ? 'POST' : 'GET', 'get' => $_GET, 'post' => $_POST];
		$out = self::call($id, $r['fn'], [$req]);
		if ($out === false && !isset(self::$loaded[$id])) {           // the plugin failed and is off now
			http_response_code(500);
			nu_fatal(t('plg_failed_msg', $api->name(), ''), '', true);
		}
		if (is_array($out)) nu_json($out);
		if ($out === null) exit;                                      // the handler answered itself (a download …)
		nu_layout_start((string)($r['title'] !== '' ? $r['title'] : $api->name()));
		echo nu_page_header((string)($r['title'] !== '' ? $r['title'] : $api->name()), ['icon' => $r['icon']]);
		echo (string)$out;
		nu_layout_end();
	}

	/** ?p=pluginasset&id=&f=: a file of plugins/<id>/assets/ (signed-in users). */
	public static function serveAsset(): void
	{
		$id = (string)($_GET['id'] ?? '');
		$f = (string)($_GET['f'] ?? '');
		$api = preg_match(self::ID_RE, $id) ? self::get($id) : null;
		$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
		$base = $api ? realpath(self::folder($id) . '/assets') : false;
		$path = $base ? realpath($base . '/' . $f) : false;
		if (!$api || !isset(self::ASSET_TYPES[$ext]) || str_contains($f, '..') || $path === false || !is_file($path) || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
			http_response_code(404);
			exit;
		}
		nu_session_release();
		header('Content-Type: ' . self::ASSET_TYPES[$ext] . ($ext === 'js' || $ext === 'css' ? '; charset=utf-8' : ''));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: ' . (isset($_GET['v']) ? 'private, max-age=31536000, immutable' : 'private, max-age=300'));
		if ($ext === 'svg') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
		header('Content-Length: ' . filesize($path));
		readfile($path);
		exit;
	}

	/** REST: /v1/plugins/<id>/<path> – [status, body, headers] or null (no such plugin). */
	public static function api(string $method, string $id, string $path, array $scopes): ?array
	{
		$api = preg_match(self::ID_RE, $id) ? self::get($id) : null;
		if (!$api) return null;
		if (!in_array('plugin:' . $id, $scopes, true)) throw new NuApiError(403, "Scope 'plugin:$id' is required.");
		$allowed = [];
		foreach ($api->apiRoutes() as [$m, $re, $fn]) {
			if (!preg_match($re, $path, $mm)) continue;
			if ($m !== $method) { $allowed[] = $m; continue; }
			$args = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
			$body = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? NuApi::body() : [];
			$out = self::call($id, $fn, [$args, $body]);
			if ($out === false && !isset(self::$loaded[$id])) throw new NuApiError(500, 'The plugin failed and was switched off.');
			if (is_array($out) && isset($out[0], $out[1]) && is_int($out[0]) && count($out) <= 3 && array_is_list($out)) return [$out[0], $out[1], (array)($out[2] ?? [])];
			return [200, $out ?? new stdClass(), []];
		}
		if ($allowed) throw new NuApiError(405, 'Method not allowed.', [], ['Allow' => implode(', ', array_unique($allowed))]);
		throw new NuApiError(404, 'Not found.');
	}

	/** php nucron.php plugin <id> <task> [name=value …]: a task of a plugin; exit code 0 / 1. */
	public static function runTask(string $id, string $task, array $args): array
	{
		$api = preg_match(self::ID_RE, $id) ? self::get($id) : null;
		if (!$api) return [false, "Plugin '$id' is not installed or not switched on."];
		$fn = $api->tasksDef()[$task] ?? null;
		if ($fn === null) return [false, "Plugin '$id' has no task '$task' (tasks: " . implode(', ', array_keys($api->tasksDef())) . ').'];
		$r = self::call($id, $fn, [$args]);
		if ($r === false && !isset(self::$loaded[$id])) return [false, "The task failed and the plugin was switched off – see Update → Plugins."];
		return [true, is_string($r) ? $r : ''];
	}

	// ================================================================== managing (globeadmin)

	/** Switches a plugin on: requirements, signature, then plugin.php and its onEnable() – null or the error. */
	public static function enable(string $id): ?string
	{
		$all = self::installed();
		$p = $all[$id] ?? null;
		if (!$p || !$p['manifest']) return $p['error'] ?? t('err_not_found');
		$m = $p['manifest'];
		if ($req = self::requirements($m)) return t('plg_needs', implode(', ', $req));
		$sig = self::signature($m, $p['dir']);
		if ($sig['state'] === 'modified') return t('plg_sig_' . $sig['state']);
		self::boot();
		if (!isset(self::$loaded[$id]) && !self::load($m)) return t('plg_err_enable', (string)(self::state()['failed'][$id]['error'] ?? ''));
		foreach (self::$loaded[$id]->lifecycle('enable') as $fn) {
			if (self::call($id, $fn) === false && !isset(self::$loaded[$id])) return t('plg_err_enable', (string)(self::state()['failed'][$id]['error'] ?? ''));
		}
		$s = self::state();
		$s['on'][$id] = ['at' => date('Y-m-d H:i:s'), 'by' => self::by(), 'version' => $m['version'], 'sig' => $sig['state']];
		unset($s['failed'][$id]);
		self::saveState($s);
		NuLog::write('plugin_enabled', 'plugin', $id, ['version' => $m['version'], 'signature' => $sig['state']]);
		return null;
	}

	public static function disable(string $id): void
	{
		if ($api = self::get($id)) foreach ($api->lifecycle('disable') as $fn) self::call($id, $fn);
		$s = self::state();
		unset($s['on'][$id], $s['failed'][$id]);
		self::saveState($s);
		unset(self::$loaded[$id]);
		NuLog::write('plugin_disabled', 'plugin', $id);
	}

	/** Removes a plugin's folder; $data: also its tables, stored values and data folder. null or the error. */
	public static function remove(string $id, bool $data): ?string
	{
		if (!preg_match(self::ID_RE, $id) || !is_dir(self::folder($id))) return t('err_not_found');
		[$m] = self::manifest(self::folder($id));
		if ($data && $m && !self::requirements($m)) {                 // its own clean-up first, while its code is there
			self::boot();
			if (!isset(self::$loaded[$id])) self::load($m);
			if (isset(self::$loaded[$id])) foreach (self::$loaded[$id]->lifecycle('remove') as $fn) self::call($id, $fn);
		}
		if (self::enabled($id)) self::disable($id);
		if (!is_writable(self::dir()) || !self::rmTree(self::folder($id))) return t('plg_err_remove');
		$dropped = [];
		if ($data) {
			$db = nu_db();
			$prefix = NuPluginApi::tablePrefix($id);
			foreach ($db->tableNames() as $t) {
				if (str_starts_with(strtolower($t), $prefix)) { $db->exec($db->dropTableSql($t)); $dropped[] = $t; }
			}
			nu_db()->run('DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', ['plg:' . $id]);
			self::rmTree(NuPluginApi::dataBase() . '/' . $id);
		}
		$s = self::state();
		unset($s['on'][$id], $s['failed'][$id]);
		self::saveState($s);
		NuLog::write('plugin_removed', 'plugin', $id, ['data' => $data, 'tables' => $dropped]);
		return null;
	}

	private static function rmTree(string $dir): bool
	{
		if (!is_dir($dir)) return true;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			$ok = $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
			if (!$ok) return false;
		}
		return @rmdir($dir);
	}

	public static function setUpload(bool $on): void
	{
		$s = self::state();
		$s['upload'] = $on;
		self::saveState($s);
		NuLog::write('plugin_settings', 'plugin', '', ['upload' => $on]);
	}

	/** Adds a trusted signing key – null or the error. */
	public static function addKey(string $name, string $key): ?string
	{
		$name = trim($name);
		$raw = base64_decode(trim($key), true);
		if ($name === '' || mb_strlen($name) > 60) return t('plg_err_key_name');
		if ($raw === false || strlen($raw) !== 32) return t('plg_err_key');
		$s = self::state();
		foreach ($s['keys'] as $k) if ($k['key'] === base64_encode($raw)) return null;
		$s['keys'][] = ['name' => $name, 'key' => base64_encode($raw)];
		self::saveState($s);
		NuLog::write('plugin_settings', 'plugin', '', ['key_added' => $name]);
		return null;
	}

	public static function removeKey(int $i): void
	{
		$s = self::state();
		$name = $s['keys'][$i]['name'] ?? '';
		array_splice($s['keys'], $i, 1);
		self::saveState($s);
		NuLog::write('plugin_settings', 'plugin', '', ['key_removed' => $name]);
	}

	// ================================================================== packages (upload)

	/**
	 * Checks an uploaded plugin package: ['ok', 'error', 'manifest', 'sig', 'needs', 'installed' => version|'', 'root' => prefix in the zip].
	 * The zip holds plugin.json at its top or in ONE folder; no absolute paths, no "..", no links.
	 */
	public static function checkZip(string $zip): array
	{
		$res = ['ok' => false, 'error' => '', 'manifest' => null, 'sig' => ['state' => 'unsigned', 'by' => ''], 'needs' => [], 'installed' => '', 'root' => ''];
		if (!class_exists('ZipArchive')) { $res['error'] = t('plg_err_zip'); return $res; }
		if (!is_file($zip) || filesize($zip) > self::MAX_ZIP) { $res['error'] = t('plg_err_zip_size', NuFile::size(self::MAX_ZIP)); return $res; }
		$z = new ZipArchive();
		if ($z->open($zip) !== true) { $res['error'] = t('plg_err_zip'); return $res; }
		try {
			$root = null;
			$names = [];
			for ($i = 0; $i < $z->numFiles; $i++) {
				$n = str_replace('\\', '/', (string)$z->getNameIndex($i));
				$st = $z->statIndex($i);
				$attr = 0;
				if ($z->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
					$res['error'] = t('plg_err_zip_entry', $n);           // a symbolic link
					return $res;
				}
				if ($n === '' || $n[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $n) || preg_match('/^[a-zA-Z]:/', $n) || str_contains($n, "\0")) {
					$res['error'] = t('plg_err_zip_entry', $n);
					return $res;
				}
				if (str_ends_with($n, 'plugin.json') && substr_count(rtrim($n, '/'), '/') <= 1) {
					$cand = substr($n, 0, -strlen('plugin.json'));
					if ($root === null || strlen($cand) < strlen($root)) $root = $cand;
				}
				if (!str_ends_with($n, '/')) $names[] = [$n, (int)($st['size'] ?? 0)];
			}
			if ($root === null) { $res['error'] = t('plg_err_manifest', 'plugin.json'); return $res; }
			$m = json_decode((string)$z->getFromName($root . 'plugin.json'), true);
			[$m, $err] = is_array($m) ? self::cleanManifest($m) : [null, t('plg_err_manifest', json_last_error_msg())];
			if ($m === null) { $res['error'] = $err; return $res; }
			foreach ($names as [$n]) if (!str_starts_with($n, $root)) { $res['error'] = t('plg_err_zip_entry', $n); return $res; }
			$res['manifest'] = $m;
			$res['root'] = $root;
			$res['needs'] = self::requirements($m);
			$res['installed'] = is_dir(self::folder($m['id'])) ? (string)(self::manifest(self::folder($m['id']))[0]['version'] ?? '?') : '';
			$res['sig'] = self::zipSignature($z, $root, $m);
			$res['ok'] = true;
		} finally {
			$z->close();
		}
		return $res;
	}

	/** Signature state of a package (like signature(), over the files in the zip). */
	private static function zipSignature(ZipArchive $z, string $root, array $m): array
	{
		if ($m['signature'] === '') return ['state' => 'unsigned', 'by' => ''];
		if (!function_exists('sodium_crypto_sign_verify_detached')) return ['state' => 'unchecked', 'by' => ''];
		$keys = [['nuBuilder Next', NuUpdate::SIGN_PUBLIC, 'official']];
		foreach ((array)(self::state()['keys'] ?? []) as $k) $keys[] = [(string)$k['name'], (string)$k['key'], 'trusted'];
		$hit = null;
		foreach ($keys as [$name, $key, $kind]) if (NuLibs::verify(self::payload($m), $m['signature'], $key) === 'valid') { $hit = [$name, $kind]; break; }
		if ($hit === null) return ['state' => 'unknown', 'by' => ''];   // no trusted key verifies it
		$have = [];
		for ($i = 0; $i < $z->numFiles; $i++) {
			$n = str_replace('\\', '/', (string)$z->getNameIndex($i));
			if (str_ends_with($n, '/') || $n === $root . 'plugin.json') continue;
			$rel = substr($n, strlen($root));
			$data = (string)$z->getFromIndex($i);
			$f = $m['files'][$rel] ?? null;
			if ($f === null || strlen($data) !== (int)($f['size'] ?? -1) || hash('sha256', $data) !== (string)($f['sha256'] ?? '')) return ['state' => 'modified', 'by' => $hit[0]];
			$have[] = $rel;
		}
		if (count($have) !== count($m['files'])) return ['state' => 'modified', 'by' => $hit[0]];
		return ['state' => $hit[1], 'by' => $hit[0]];
	}

	/** Installs (or replaces) a checked package – null or the error. A replaced plugin that was on stays on. */
	public static function installZip(string $zip): ?string
	{
		$c = self::checkZip($zip);
		if (!$c['ok']) return $c['error'];
		if ($c['sig']['state'] === 'modified') return t('plg_sig_' . $c['sig']['state']);
		$id = $c['manifest']['id'];
		NuSecurity::protectDir(self::dir());
		if (!is_writable(self::dir())) return t('plg_err_write');
		$tmp = self::dir() . '/.tmp-' . bin2hex(random_bytes(6));
		@mkdir($tmp, 0755);
		$z = new ZipArchive();
		if ($z->open($zip) !== true) return t('plg_err_zip');
		try {
			for ($i = 0; $i < $z->numFiles; $i++) {
				$n = str_replace('\\', '/', (string)$z->getNameIndex($i));
				$rel = substr($n, strlen($c['root']));
				if ($rel === '' || str_ends_with($n, '/')) continue;
				$to = $tmp . '/' . $rel;
				if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true);
				$in = $z->getStream($n);
				$out = $in ? @fopen($to, 'wb') : false;
				if (!$in || !$out) { self::rmTree($tmp); return t('plg_err_write'); }
				stream_copy_to_stream($in, $out);
				fclose($in);
				fclose($out);
			}
		} finally {
			$z->close();
		}
		$target = self::folder($id);
		$old = null;
		if (is_dir($target)) {
			$old = self::dir() . '/.old-' . $id . '-' . bin2hex(random_bytes(4));
			if (!@rename($target, $old)) { self::rmTree($tmp); return t('plg_err_write'); }
		}
		if (!@rename($tmp, $target)) {
			if ($old !== null) @rename($old, $target);
			self::rmTree($tmp);
			return t('plg_err_write');
		}
		if ($old !== null) self::rmTree($old);
		NuLog::write('plugin_installed', 'plugin', $id, ['version' => $c['manifest']['version'], 'replaced' => $c['installed'], 'signature' => $c['sig']['state']]);
		return null;
	}

	// ================================================================== update check

	/** The plugin API of this version for the release manifest (dev/release.py): Check package compares it with the installed plugins. */
	public static function apiInfo(): array
	{
		return ['current' => self::API_VERSION, 'supported' => self::API_SUPPORTED, 'deprecated' => self::API_DEPRECATED];
	}

	/** Installed plugins against a new version's plugin API: [{id, name, version, on, issue: '' | 'breaks' | 'deprecated'}]. */
	public static function report(array $newApi): array
	{
		$sup = array_map('intval', (array)($newApi['supported'] ?? []));
		$dep = array_map('intval', (array)($newApi['deprecated'] ?? []));
		$out = [];
		foreach (self::installed() as $id => $p) {
			if (!$p['manifest']) continue;
			$v = (int)$p['manifest']['api'];
			$out[] = ['id' => $id, 'name' => (string)$p['manifest']['name'], 'version' => (string)$p['manifest']['version'], 'on' => self::enabled($id),
				'issue' => !in_array($v, $sup, true) ? 'breaks' : (in_array($v, $dep, true) ? 'deprecated' : '')];
		}
		return $out;
	}
}
