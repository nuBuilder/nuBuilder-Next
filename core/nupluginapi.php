<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * $plugin in a plugin's plugin.php – the plugin API, version NuPlugin::API_VERSION. The contract plugins are written against:
 * within a version, methods are only added, never changed or removed (a change = a new version, the old one kept for a while and
 * marked deprecated). Documented in the help, chapter Plugins.
 */
final class NuPluginApi
{
	private array $m;
	/** @var array<string, list<array{0: int, 1: callable}>> */
	private array $hooks = [];
	private array $routes = [];
	private array $api = [];
	private array $tiles = [];
	private array $menu = [];
	private array $rights = [];
	private array $assets = [];
	private array $tasks = [];
	private array $life = ['enable' => [], 'disable' => [], 'remove' => []];
	/** @var array{0: callable, 1: callable}|null */
	private ?array $settings = null;
	private ?array $texts = null;

	public function __construct(array $manifest)
	{
		$this->m = $manifest;
	}

	// ================================================================== about the plugin

	public function id(): string { return (string)$this->m['id']; }
	public function version(): string { return (string)$this->m['version']; }
	public function name(): string { return (string)$this->m['name']; }
	public function manifest(): array { return $this->m; }

	/** The plugin's folder, or a file in it. */
	public function path(string $rel = ''): string
	{
		$base = NuPlugin::folder($this->id());
		return $rel === '' ? $base : $base . '/' . ltrim(str_replace('\\', '/', $rel), '/');
	}

	/** nuBuilder Next's version and the plugin API version. */
	public function nunext(): array
	{
		return ['version' => NU_VERSION, 'api' => NuPlugin::API_VERSION];
	}

	// ================================================================== events

	/**
	 * Listens to an event (NuPlugin::EVENTS). The listener gets one array with the event's data:
	 *   record.before_save {form, form_id, table, record_id, new, values}  – return a text to refuse the save (shown as the error)
	 *   record.after_save {form, form_id, table, record_id, new}
	 *   record.before_delete {form, form_id, table, record_id, row}         – return a text to refuse the delete
	 *   record.after_delete {form, form_id, table, record_id}
	 *   login {user_id, login, via}
	 *   mail.before_send {to, cc, bcc, subject, tag}                        – return a text to stop the e-mail
	 *   housekeeping {source: cron | page}                                  – return a number (rows cleaned) or nothing
	 *   list.def: a filter – the listener gets the definition of a system list and its key, and returns the (changed) definition
	 * Lower priority runs first.
	 */
	public function on(string $event, callable $fn, int $priority = 10): self
	{
		if (!in_array($event, NuPlugin::EVENTS, true)) throw new InvalidArgumentException("Unknown event '$event'.");
		$this->hooks[$event][] = [$priority, $fn];
		return $this;
	}

	/** @return list<array{0: int, 1: callable}> */
	public function hooks(string $event): array
	{
		return $this->hooks[$event] ?? [];
	}

	/** Code for "switched on" (create tables …), "switched off" and "removed with its data" (drop tables …). */
	public function onEnable(callable $fn): self { $this->life['enable'][] = $fn; return $this; }
	public function onDisable(callable $fn): self { $this->life['disable'][] = $fn; return $this; }
	public function onRemove(callable $fn): self { $this->life['remove'][] = $fn; return $this; }

	public function lifecycle(string $kind): array
	{
		return $this->life[$kind] ?? [];
	}

	// ================================================================== pages, tiles, menu, rights

	/**
	 * A page of the plugin: index.php?p=plugin&id=<id>&r=<name>. The handler gets ['method', 'get', 'post'] and returns
	 *   a string  – HTML shown in nuBuilder Next's page frame (title bar from 'title'; strict CSP: no inline scripts or styles),
	 *   an array  – sent as JSON,
	 *   null      – the handler sent the answer itself (a download …).
	 * Options: title, icon (Bootstrap Icons class), right (a right of this plugin – view on GET, change on POST – or 'globeadmin';
	 * empty = every signed-in user). Forms post back with $plugin->csrfField().
	 */
	public function route(string $name, callable $fn, array $opt = []): self
	{
		$this->checkName($name);
		$right = (string)($opt['right'] ?? '');
		$this->routes[$name] = ['fn' => $fn, 'title' => (string)($opt['title'] ?? ''), 'icon' => (string)($opt['icon'] ?? 'bi-puzzle'),
			'right' => $right === '' || $right === 'globeadmin' ? $right : $this->rightKey($right)];
		return $this;
	}

	public function routesDef(): array { return $this->routes; }

	/** The address of a page of this plugin. */
	public function url(string $route, array $params = []): string
	{
		return nu_url('plugin', ['id' => $this->id(), 'r' => $route] + $params);
	}

	/**
	 * A REST route: api.php/v1/plugins/<id>/<pattern> – pattern with {name} parts (items/{item}). The client needs the scope
	 * plugin:<id>. The handler gets the named parts and the JSON body and returns the answer (an array → JSON, status 200) or
	 * [status, body, headers]. It runs as the API client's service user (rights as usual).
	 */
	public function api(string $method, string $pattern, callable $fn): self
	{
		$method = strtoupper($method);
		if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) throw new InvalidArgumentException("Method '$method'.");
		$re = '#^/' . preg_replace_callback('/\\\\\{([a-z][a-z0-9_]*)\\\\\}/i', static fn($mm) => '(?P<' . $mm[1] . '>[^/]+)', preg_quote(trim($pattern, '/'), '#')) . '$#';
		$this->api[] = [$method, $re, $fn];
		return $this;
	}

	public function apiRoutes(): array { return $this->api; }

	/**
	 * A tile on the start page (admin start page): label, icon, hint, route (a page of this plugin) or url, right (a right of this
	 * plugin; empty = users with any admin right). New tiles go to the last group, like new tiles of nuBuilder Next.
	 */
	public function tile(string $key, array $t): self
	{
		$this->checkName($key);
		$this->tiles['plg_' . $this->id() . '_' . $key] = ['label' => (string)($t['label'] ?? $this->name()), 'icon' => self::icon($t['icon'] ?? ''),
			'hint' => (string)($t['hint'] ?? ''), 'route' => (string)($t['route'] ?? ''), 'url' => self::safeUrl((string)($t['url'] ?? '')),
			'right' => ($t['right'] ?? '') !== '' ? $this->rightKey((string)$t['right']) : ''];
		return $this;
	}

	public function tilesDef(): array { return $this->tiles; }

	/** An entry of the user menu: label, icon, route or url, right (empty = everybody). */
	public function menu(string $key, array $mi): self
	{
		$this->checkName($key);
		$this->menu[$key] = ['label' => (string)($mi['label'] ?? $this->name()), 'icon' => self::icon($mi['icon'] ?? ''), 'route' => (string)($mi['route'] ?? ''),
			'url' => self::safeUrl((string)($mi['url'] ?? '')), 'right' => ($mi['right'] ?? '') !== '' ? $this->rightKey((string)$mi['right']) : ''];
		return $this;
	}

	public function menuDef(): array { return $this->menu; }

	/** An admin right of this plugin (group Plugins of the admin roles): levels 'vc' = view and change, 'c' = one level. */
	public function right(string $key, string $label, string $levels = 'vc'): string
	{
		$this->checkName($key);
		$full = $this->rightKey($key);
		$this->rights[$full] = [$label, $levels === 'c' ? 'c' : 'vc'];
		return $full;
	}

	public function rightsDef(): array { return $this->rights; }

	/** May the signed-in user use this right of the plugin? (the globeadmin always) */
	public function can(string $key, string $level = 'view'): bool
	{
		return NuAdmin::can($this->rightKey($key), $level);
	}

	private function rightKey(string $key): string
	{
		return 'plg_' . $this->id() . '_' . $key;
	}

	// ================================================================== scripts, styles, texts

	/** A script of plugins/<id>/assets/ on every page ('app'), the form pages ('form') or both ('all'). */
	public function js(string $file, string $where = 'app'): self { return $this->addAsset('js', $file, $where); }

	public function css(string $file, string $where = 'app'): self { return $this->addAsset('css', $file, $where); }

	private function addAsset(string $kind, string $file, string $where): self
	{
		if (!in_array($where, ['app', 'form', 'all'], true)) throw new InvalidArgumentException("Where '$where'.");
		$this->assets[] = [$kind, $file, $where];
		return $this;
	}

	/** Addresses of the scripts / styles for a kind of page. */
	public function assetsOf(string $kind, string $where): array
	{
		$out = [];
		foreach ($this->assets as [$k, $file, $w]) if ($k === $kind && ($w === 'all' || $w === $where)) $out[] = $this->asset($file);
		return $out;
	}

	/** The address of a file of plugins/<id>/assets/ (with a version stamp). */
	public function asset(string $file): string
	{
		$p = $this->path('assets/' . $file);
		return nu_url('pluginasset', ['id' => $this->id(), 'f' => $file, 'v' => is_file($p) ? substr(md5((string)filemtime($p) . $this->version()), 0, 10) : null]);
	}

	/** A text of plugins/<id>/lang/<language>.php (English when missing there, the key when missing everywhere); sprintf arguments. */
	public function t(string $key, mixed ...$args): string
	{
		if ($this->texts === null) {
			$this->texts = [];
			foreach (array_unique(['en', nu_lang()]) as $l) {          // English first, the user's language over it
				$f = $this->path('lang/' . $l . '.php');
				$d = is_file($f) ? (static fn(string $nuF) => require $nuF)($f) : [];
				if (is_array($d)) $this->texts = array_merge($this->texts, $d);
			}
		}
		$s = (string)($this->texts[$key] ?? $key);
		return $args ? vsprintf($s, $args) : $s;
	}

	/** The form token field for forms of plugin pages (every POST is checked). */
	public function csrfField(): string
	{
		return nu_csrf_field();
	}

	// ================================================================== data

	/** A stored value of the plugin (settings row plg:<id>). */
	public function get(string $key, mixed $default = null): mixed
	{
		$all = nu_setting_json('plg:' . $this->id());
		return array_key_exists($key, $all) ? $all[$key] : $default;
	}

	public function set(string $key, mixed $value): void
	{
		$all = nu_setting_json('plg:' . $this->id());
		if ($value === null) unset($all[$key]); else $all[$key] = $value;
		nu_setting_json_save('plg:' . $this->id(), $all, 'plugin:' . $this->id());
	}

	public function all(): array
	{
		return nu_setting_json('plg:' . $this->id());
	}

	/**
	 * A settings card (Update → Plugins → Settings): $render(array $values) returns the HTML of the fields (named plg[<key>]; escape
	 * with h()), $save(array $posted) stores them and returns null or an error text.
	 */
	public function settings(callable $render, callable $save): self
	{
		$this->settings = [$render, $save];
		return $this;
	}

	public function settingsDef(): ?array { return $this->settings; }

	/** The database connection (NuDB: run / all / one / value / insert / update / delete …). */
	public function db(): NuDB
	{
		return nu_db();
	}

	/** Name of a table of the plugin: zzzzsys_nunext_plg_<id>_<name> – removed with the plugin when its data is removed. */
	public function table(string $name): string
	{
		$this->checkName($name);
		return self::tablePrefix($this->id()) . $name;
	}

	public static function tablePrefix(string $id): string
	{
		return 'zzzzsys_nunext_plg_' . $id . '_';
	}

	/** A folder for the plugin's files (custom/plugindata/<id>/, closed to the web; kept by updates). */
	public function dataDir(): string
	{
		$d = self::dataBase() . '/' . $this->id();
		NuSecurity::protectDir(self::dataBase());
		NuSecurity::protectDir($d);
		return $d;
	}

	public static function dataBase(): string
	{
		return NU_ROOT . '/custom/plugindata';
	}

	/** An entry in the log (shown as "Plugin event"). */
	public function log(string $event, array $details = []): void
	{
		NuLog::write('plugin_event', 'plugin', $this->id(), ['event' => mb_substr($event, 0, 60)] + $details);
	}

	/** The signed-in user (id, login, name, globeadmin …) or null. */
	public function user(): ?array
	{
		return NuAuth::user();
	}

	/** A task for the server's cron: php nucron.php plugin <id> <name> [name=value …]. The handler gets the name=value pairs. */
	public function task(string $name, callable $fn): self
	{
		$this->checkName($name);
		$this->tasks[$name] = $fn;
		return $this;
	}

	public function tasksDef(): array { return $this->tasks; }

	// ================================================================== helpers

	private function checkName(string $n): void
	{
		if (!preg_match(NuPlugin::NAME_RE, $n)) throw new InvalidArgumentException("Name '$n': lower-case letters, digits and _, starting with a letter, up to 24.");
	}

	private static function icon(mixed $i): string
	{
		$i = (string)$i;
		return preg_match('/^bi-[a-z0-9-]{1,40}$/', $i) ? $i : 'bi-puzzle';
	}

	private static function safeUrl(string $u): string
	{
		return $u !== '' && preg_match('~^(https://|index\.php\?)~i', $u) ? $u : '';
	}
}
