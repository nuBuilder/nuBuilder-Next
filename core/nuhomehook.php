<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Start page hooks (0.8.378): the procedure with the reserved code nunext_home runs every time the tile start page (globeadmin and
 * admin roles) is built. Its PHP asks for changes of THIS page view through nuHomeSetBadge / nuHomeSetHint / nuHomeHideTile /
 * nuHomeAddGroup / nuHomeAddTile / nuHomeBanner / nuHomeSetData / nuHomeCache (core/form/nuphp_api.php) – collected here and
 * merged by pages/nuhome.php while drawing; nothing is ever saved into a layout.
 *   - dynamic groups and tiles use keys x_…; tiles are checked like own tiles of the tile editor (NuHome::cleanTile) and drawn by
 *     the same code (every text escaped); they are not part of Customize and cannot be favourites
 *   - a failing hook: the page is drawn without its changes; the globeadmin sees the message, admin roles see nothing; every error
 *     and every echo goes to Debug results (zzzzsys_debug)
 *   - off: $nuConfigHomeHooks = false (nuconfig.php or Settings) · one page view: ?p=home&safe=1 (globeadmin only)
 *   - nuHomeCache(seconds): the result is kept in the session per user, layout and code
 */
final class NuHomeHook
{
	public const CODE = 'nunext_home';
	public const COLORS = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
	public const LEVELS = ['info', 'success', 'warning', 'danger'];
	private const MAX = 100;                                  // tiles, groups, banners, data entries per page view
	private const CACHE_MAX = 3600;

	/** What the running hook asked for. */
	public static array $badges = [];
	public static array $hints = [];
	public static array $hidden = [];
	public static array $groups = [];                         // list of ['id', 'title', 'tab']
	public static array $tiles = [];                          // key => ['group' => …, 'tile' => cleaned own tile]
	public static array $banners = [];                        // list of [text, level]
	public static array $data = [];
	public static int $cache = 0;
	public static array $problems = [];                       // refused calls (shown to the globeadmin like errors)

	/** The result of this page view (run()): error text for the globeadmin ('' = none), ran = the hook ran (or came from the cache). */
	public static string $error = '';
	public static bool $ran = false;

	public static function reset(): void
	{
		self::$badges = self::$hints = self::$hidden = self::$groups = self::$tiles = self::$banners = self::$data = self::$problems = [];
		self::$cache = 0;
	}

	/** $nuConfigHomeHooks (nuconfig.php or Settings) – on unless set to false. */
	public static function enabled(): bool
	{
		$v = nu_cfg('nuConfigHomeHooks');
		return !($v === false || $v === 0 || (is_string($v) && in_array(strtolower(trim($v)), ['0', 'false', 'no', 'off'], true)));
	}

	/** ?p=home&safe=1 – only the globeadmin: hooks skipped for this page view. */
	public static function safeMode(): bool
	{
		$u = NuAuth::user();
		return ($_GET['safe'] ?? '') === '1' && $u && $u['globeadmin'];
	}

	/** The procedure nunext_home (or null): code, PHP, JavaScript (when active), status. */
	public static function procedure(): ?array
	{
		static $p = false;
		if ($p !== false) return $p;
		$p = null;
		try {
			$db = nu_db();
			if (!$db->tableExists('zzzzsys_php')) return null;
			$row = $db->one('SELECT * FROM zzzzsys_php WHERE sph_code = ?', [self::CODE]);
			if (!$row) return null;
			if (in_array((string)($row['sph_status'] ?? '1'), ['0', '3'], true)) return null;     // draft / archived: not run
			$js = (string)($row['sph_javascript_active'] ?? '1') !== '0' ? (string)($row['sph_javascript'] ?? '') : '';
			$p = ['id' => (string)$row['zzzzsys_php_id'], 'php' => (string)($row['sph_php'] ?? ''), 'js' => trim($js) !== '' ? $js : ''];
		} catch (Throwable) {
			$p = null;
		}
		return $p;
	}

	/** Is a hook active (Security check, System status)? */
	public static function active(): bool
	{
		$p = self::procedure();
		return self::enabled() && $p !== null && (trim($p['php']) !== '' || $p['js'] !== '');
	}

	/** Hash variables of the hook: the user's ones + ADMIN_ROLE, HOME_LAYOUT, HOME_SIZE, HOME_DISPLAY. */
	public static function hash(array $layout): array
	{
		$role = NuAdmin::role();
		return NuHash::plain([], ['ADMIN_ROLE' => (string)($role['name'] ?? ''), 'HOME_LAYOUT' => (string)(NuHome::current()['name'] ?? ''),
			'HOME_SIZE' => (string)$layout['size'], 'HOME_DISPLAY' => (string)$layout['display'], 'PROCEDURE_CODE' => self::CODE]);
	}

	/** Runs the hook for the start page (or takes the cached result). Errors leave the state empty. */
	public static function run(array $layout): void
	{
		self::reset();
		self::$error = '';
		self::$ran = false;
		if (!self::enabled() || self::safeMode()) return;
		$p = self::procedure();
		if (!$p || trim($p['php']) === '') return;
		$u = NuAuth::user();
		$ck = hash('sha256', (string)$u['id'] . "\x1f" . (string)(NuHome::current()['id'] ?? '') . "\x1f" . $p['php']);
		$c = $_SESSION['nu_home_hook'] ?? null;
		if (is_array($c) && ($c['key'] ?? '') === $ck && (int)($c['until'] ?? 0) > time() && is_array($c['state'] ?? null)) {
			self::restore($c['state']);
			self::$ran = true;
			return;
		}
		unset($_SESSION['nu_home_hook']);
		$hash = self::hash($layout);
		try {
			$r = NuPhp::isolated(static fn() => NuPhp::run($p['php'], $hash, self::CODE));
			$errors = array_column($r['errors'], 'message');
			if (trim($r['output']) !== '') NuDebugLog::write(self::CODE . " – output:\n" . $r['output']);
		} catch (Throwable $e) {
			$errors = [self::CODE . ': ' . $e->getMessage()];
		}
		$errors = array_merge($errors, self::$problems);
		if ($errors) {
			self::reset();
			self::$error = implode(' · ', $errors);
			NuDebugLog::write(self::CODE . " – error:\n" . self::$error);
			return;
		}
		self::$ran = true;
		if (self::$cache > 0) $_SESSION['nu_home_hook'] = ['key' => $ck, 'until' => time() + self::$cache, 'state' => self::state()];
	}

	private static function state(): array
	{
		return ['badges' => self::$badges, 'hints' => self::$hints, 'hidden' => self::$hidden, 'groups' => self::$groups, 'tiles' => self::$tiles,
			'banners' => self::$banners, 'data' => self::$data];
	}

	private static function restore(array $s): void
	{
		foreach (['badges', 'hints', 'hidden', 'groups', 'tiles', 'banners', 'data'] as $k) self::${$k} = is_array($s[$k] ?? null) ? $s[$k] : [];
	}

	private static function problem(string $msg): void
	{
		self::$problems[] = self::CODE . ': ' . $msg;
	}

	/** A tile key a hook may change: built-in, own (c_), pinned, dynamic (x_). */
	public static function validKey(string $key): bool
	{
		return (bool)preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key);
	}

	// ------------------------------------------------------------------ the PHP functions (core/form/nuphp_api.php)

	public static function setBadge(string $key, string $text, string $color = 'secondary'): void
	{
		if (!self::validKey($key)) { self::problem(t('hook_err_key', $key)); return; }
		$color = in_array($color, self::COLORS, true) ? $color : 'secondary';
		self::$badges[$key] = [mb_substr(trim($text), 0, 40), $color];
		if (self::$badges[$key][0] === '') unset(self::$badges[$key]);
	}

	public static function setHint(string $key, string $text): void
	{
		if (!self::validKey($key)) { self::problem(t('hook_err_key', $key)); return; }
		self::$hints[$key] = mb_substr(trim($text), 0, 120);
	}

	public static function hideTile(string $key): void
	{
		if (!self::validKey($key)) { self::problem(t('hook_err_key', $key)); return; }
		self::$hidden[$key] = true;
	}

	public static function addGroup(string $id, string $title, string $tab = ''): void
	{
		if (!preg_match('/^x_[a-z0-9_]{1,37}$/', $id)) { self::problem(t('hook_err_xkey', $id)); return; }
		if (count(self::$groups) >= self::MAX) return;
		foreach (self::$groups as $g) if ($g['id'] === $id) return;
		self::$groups[] = ['id' => $id, 'title' => mb_substr(trim($title), 0, 60), 'tab' => preg_match('/^[a-z][a-z0-9_]{0,39}$/', $tab) ? $tab : ''];
	}

	/**
	 * A dynamic tile: group, label, hint, icon, color and exactly one target – form (+ record), page, procedure (+ run), url (+ newtab).
	 * Checked like an own tile of the tile editor; a refused tile is a problem of the hook (the page then shows none of its changes).
	 */
	public static function addTile(string $key, array $tile): void
	{
		if (!preg_match('/^x_[a-z0-9_]{1,37}$/', $key)) { self::problem(t('hook_err_xkey', $key)); return; }
		if (count(self::$tiles) >= self::MAX) return;
		$targets = array_values(array_filter(['form', 'page', 'procedure', 'url'], static fn($k) => trim((string)($tile[$k] ?? '')) !== ''));
		if (count($targets) !== 1) { self::problem(t('hook_err_target', $key)); return; }
		$kind = $targets[0];
		$target = trim((string)$tile[$kind]);
		if ($kind === 'form' && !isset(nu_forms()[$target])) {                        // a form code instead of its id
			foreach (nu_forms() as $id => $f) if (strcasecmp((string)$f['code'], $target) === 0) { $target = (string)$id; break; }
		}
		if ($kind === 'procedure') {                                                   // a procedure code instead of its id
			$id = nu_db()->value('SELECT zzzzsys_php_id FROM zzzzsys_php WHERE zzzzsys_php_id = ? OR sph_code = ?', [$target, $target]);
			if ($id !== null) $target = (string)$id;
		}
		[$clean, $err] = NuHome::cleanTile(['label' => $tile['label'] ?? '', 'hint' => $tile['hint'] ?? '', 'icon' => $tile['icon'] ?? '', 'color' => $tile['color'] ?? '',
			'kind' => $kind, 'target' => $target, 'record' => (string)($tile['record'] ?? ''), 'run' => (string)($tile['run'] ?? ''), 'newtab' => !empty($tile['newtab'])], true);
		if ($err !== '') { self::problem($key . ': ' . $err); return; }
		self::$tiles[$key] = ['group' => (string)($tile['group'] ?? ''), 'tile' => $clean];
	}

	public static function banner(string $text, string $level = 'info'): void
	{
		if (count(self::$banners) >= self::MAX || trim($text) === '') return;
		self::$banners[] = [mb_substr(trim($text), 0, 500), in_array($level, self::LEVELS, true) ? $level : 'info'];
	}

	public static function setData(string $name, mixed $value): void
	{
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,59}$/', $name)) { self::problem(t('hook_err_data', $name)); return; }
		if (count(self::$data) >= self::MAX && !isset(self::$data[$name])) return;
		$json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
		self::$data[$name] = $json === false ? null : json_decode($json, true);
	}

	public static function cacheFor(int $seconds): void
	{
		self::$cache = max(0, min(self::CACHE_MAX, $seconds));
	}

	// ------------------------------------------------------------------ merge into the page (pages/nuhome.php)

	/**
	 * The groups to draw ($groups: [id, title, tab, items] of the layout) with the hook's changes: hidden tiles taken out, hints and
	 * badges set, dynamic groups added at the end, dynamic tiles added to their group (a layout group or a dynamic one).
	 */
	public static function apply(array $groups, array $layout): array
	{
		if (!self::$ran) return $groups;
		$firstTab = (string)($layout['tabs'][0]['id'] ?? '');
		$tabIds = array_column((array)$layout['tabs'], 'id');
		foreach (self::$groups as $g) {
			$groups[] = ['id' => $g['id'], 'title' => $g['title'], 'tab' => in_array($g['tab'], $tabIds, true) ? $g['tab'] : $firstTab, 'items' => [], 'dynamic' => true];
		}
		foreach (self::$tiles as $key => $d) {
			$i = array_search($d['group'], array_column($groups, 'id'), true);
			if ($i === false) {                                                      // no such group: a group "More" of its own at the end
				$groups[] = ['id' => 'x_more', 'title' => t('home_group_more'), 'tab' => $firstTab, 'items' => [], 'dynamic' => true];
				$i = count($groups) - 1;
			}
			$probe = ['custom' => [$key => $d['tile']]];
			if (!NuHome::visible($key, $probe) || ($r = NuHome::resolve($key, $probe)) === null) continue;
			$groups[$i]['items'][$key] = $r + ['fav' => false, 'source' => 'dynamic'];
		}
		foreach ($groups as &$g) {
			foreach ($g['items'] as $k => &$it) {
				if (isset(self::$hidden[$k])) { unset($g['items'][$k]); continue; }
				if (isset(self::$hints[$k])) $it['hint'] = self::$hints[$k];
				if (isset(self::$badges[$k])) $it['badge'] = self::$badges[$k];
			}
			unset($it);
		}
		unset($g);
		return array_values(array_filter($groups, static fn($g) => $g['items'] !== []));
	}
}
