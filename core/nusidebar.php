<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Side bar (0.8.460): an optional left column on every page with the user's favourites and the pages / forms he used last.
 *   - On / off per user (default off): sus_json NUNEXT_SIDEBAR '1' / '0', the globeadmin's in the setting row sidebar_ga, demo
 *     visitors in the session; without an own choice the default for everyone counts (setting row sidebar_default, the globeadmin).
 *   - Favourites = the ☆ tiles of the start page the user sees (NuHome: the admin layout, or the user start page with his own
 *     favourites) – the same list, same order. A ☆ in the side bar adds / removes a tile there (favToggle).
 *   - Recently used = pages that are start page tiles and forms, recorded on the server while the side bar is on (table
 *     zzzzsys_nunext_recent, the last KEEP per user; demo visitors: session); shown only while the user still has the right.
 * Drawn by nu_layout_start() (core/nulayout.php) between the top bar and <main>; behaviour in assets/js/nusidebar.js.
 */
final class NuSidebar
{
	public const JSON        = 'NUNEXT_SIDEBAR';
	public const SETTING_GA  = 'sidebar_ga';
	public const SETTING_DEF = 'sidebar_default';
	public const TABLE       = 'zzzzsys_nunext_recent';
	public const KEEP        = 10;
	/** Pages recorded under their start page tile key (the key is the route). */
	public const PAGE_KEYS   = ['users', 'access', 'permissions', 'sessions', 'adminroles', 'userfields', 'forms', 'procedures', 'reports', 'prompts',
		'apiclients', 'email', 'notifications', 'debug', 'update', 'settings', 'securitycheck', 'configfile', 'setupcode', 'setupwizard', 'branding',
		'dashboards', 'import', 'appcloner', 'sample', 'compat', 'vendor', 'tutorial', 'todo', 'selftest'];

	/** True while the side bar is drawn (nu_layout_end closes its wrapper). */
	public static bool $drawn = false;
	private static ?bool $on = null;

	// ------------------------------------------------------------------ on / off

	public static function enabled(): bool
	{
		if (self::$on !== null) return self::$on;
		$u = NuAuth::user();
		if (!$u) return self::$on = false;
		try {
			if (!empty($u['demo'])) $v = $_SESSION['nu_sidebar'] ?? '';
			elseif ($u['globeadmin']) $v = (string)(self::setting(self::SETTING_GA) ?? '');
			else $v = (string)NuUserJson::get((string)$u['id'], self::JSON);
		} catch (Throwable) {
			$v = '';
		}
		return self::$on = $v === '' ? self::defaultOn() : $v === '1';
	}

	public static function set(bool $on): void
	{
		$u = NuAuth::user();
		if (!$u) return;
		if (!empty($u['demo'])) $_SESSION['nu_sidebar'] = $on ? '1' : '0';
		elseif ($u['globeadmin']) self::setSetting(self::SETTING_GA, $on ? '1' : '0');
		else NuUserJson::set((string)$u['id'], self::JSON, $on ? '1' : '0');
		self::$on = $on;
	}

	/** The default for users without an own choice (the globeadmin sets it; off when never set). */
	public static function defaultOn(): bool
	{
		try {
			return self::setting(self::SETTING_DEF) === '1';
		} catch (Throwable) {
			return false;
		}
	}

	public static function setDefault(bool $on): void
	{
		self::setSetting(self::SETTING_DEF, $on ? '1' : '0');
		NuLog::write('sidebar_default', 'zzzzsys_nunext_setting', self::SETTING_DEF, ['on' => $on]);
		self::$on = null;
	}

	private static function setting(string $id): ?string
	{
		NuSchema::ensure();
		$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id]);
		return $v === null ? null : (string)$v;
	}

	private static function setSetting(string $id, string $value): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', $id);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $id] + $row);
	}

	// ------------------------------------------------------------------ recently used

	/** Item of the page being shown: a start page tile key, 'form:<id>', or '' (not recorded – the start page, actions, records of lists …). */
	public static function currentKey(): string
	{
		$p = $_GET['p'] ?? 'home';
		if (!is_string($p)) return '';
		if ($p === 'form') {
			$f = $_GET['f'] ?? '';
			if (!is_string($f) || $f === '' || !isset(nu_forms()[$f])) return '';
			return $f === 'nunotes' ? 'notes' : 'form:' . $f;
		}
		return in_array($p, self::PAGE_KEYS, true) ? $p : '';
	}

	private static function userId(): string
	{
		$u = NuAuth::user();
		return $u ? mb_substr((string)$u['id'], 0, 25) : '';
	}

	/**
	 * Notes the page being shown – also while the side bar is off (0.8.492): the start page's row "Recently used" shows the same list.
	 * Not while the globeadmin acts as another user.
	 */
	public static function record(string $key): void
	{
		$u = NuAuth::user();
		if ($key === '' || !$u || NuImpersonate::active()) return;
		if (!empty($u['demo'])) {
			$list = array_values(array_diff((array)($_SESSION['nu_recent'] ?? []), [$key]));
			array_unshift($list, $key);
			$_SESSION['nu_recent'] = array_slice($list, 0, self::KEEP);
			return;
		}
		try {
			NuSchema::ensure();
			$db = nu_db();
			$uid = self::userId();
			$now = date('Y-m-d H:i:s');
			$mt = microtime(true);
			$stamp = date('YmdHis', (int)$mt) . sprintf('%06d', (int)(($mt - floor($mt)) * 1000000));   // several pages in one second keep their order
			$id = $db->value('SELECT ' . self::TABLE . '_id FROM ' . self::TABLE . ' WHERE user_id = ? AND item = ?', [$uid, $key]);
			if ($id !== null) {
				$db->update(self::TABLE, ['used_at' => $now, 'stamp' => $stamp], self::TABLE . '_id', (string)$id);
				return;
			}
			$db->insert(self::TABLE, [self::TABLE . '_id' => nu_id(), 'user_id' => $uid, 'item' => mb_substr($key, 0, 100), 'used_at' => $now, 'stamp' => $stamp]);
			$rows = $db->all('SELECT ' . self::TABLE . '_id AS id FROM ' . self::TABLE . ' WHERE user_id = ? ORDER BY stamp DESC', [$uid]);
			foreach (array_slice($rows, self::KEEP) as $r) $db->delete(self::TABLE, self::TABLE . '_id', (string)$r['id']);
		} catch (Throwable $e) {
			error_log('nuBuilder Next side bar: ' . $e->getMessage());
		}
	}

	/**
	 * A start page tile was opened (POST action used, 0.8.492): a form tile counts as its form (as when the form page is shown), a tile
	 * of a page as that page, other tiles (reports, links, tools) under their own key. Unknown or hidden tiles are ignored.
	 */
	public static function recordTile(string $key): void
	{
		if ($key === '' || strlen($key) > 100) return;
		[$layout] = self::startPage();
		if (!NuHome::visible($key, $layout) || ($r = NuHome::resolve($key, $layout)) === null || $r['proc'] !== null) return;
		$c = (array)($layout['custom'][$key] ?? []);
		if (($r['kind'] ?? '') === 'form' && ($c['target'] ?? '') !== '') {
			$f = (string)$c['target'];
			self::record($f === 'nunotes' ? 'notes' : 'form:' . $f);
			return;
		}
		self::record($key);
	}

	/** @return list<string> item keys, the last used first */
	public static function recentKeys(): array
	{
		$u = NuAuth::user();
		if (!$u) return [];
		if (!empty($u['demo'])) return array_values(array_map('strval', (array)($_SESSION['nu_recent'] ?? [])));
		try {
			NuSchema::ensure();
			return array_map(static fn($r) => (string)$r['item'],
				nu_db()->all('SELECT item FROM ' . self::TABLE . ' WHERE user_id = ? ORDER BY stamp DESC', [self::userId()]));
		} catch (Throwable) {
			return [];
		}
	}

	/** One entry out of "Recently used" (right-click / long press → Remove from recently used, 0.8.498). */
	public static function forget(string $key): void
	{
		$u = NuAuth::user();
		if (!$u || $key === '') return;
		if (!empty($u['demo'])) { $_SESSION['nu_recent'] = array_values(array_diff((array)($_SESSION['nu_recent'] ?? []), [$key])); return; }
		NuSchema::ensure();
		nu_db()->run('DELETE FROM ' . self::TABLE . ' WHERE user_id = ? AND item = ?', [self::userId(), $key]);
	}

	public static function clearRecent(): void
	{
		$u = NuAuth::user();
		if (!$u) return;
		if (!empty($u['demo'])) { $_SESSION['nu_recent'] = []; return; }
		NuSchema::ensure();
		nu_db()->run('DELETE FROM ' . self::TABLE . ' WHERE user_id = ?', [self::userId()]);
	}

	// ------------------------------------------------------------------ what is drawn

	/** The start page the user sees (as pages/nuhome.php): admin layout, or the user start page of his access level. */
	private static function startPage(): array
	{
		$u = NuAuth::user();
		if (!NuAdmin::any() && NuHome::target() === null) NuHome::forLevel((string)($u['access_id'] ?? ''), false);
		$userPage = NuHome::target() !== null;
		$layout = NuHome::layout();
		$mine = $userPage ? (NuHome::overlay((string)NuHome::current()['id']) ?? []) : [];
		return [$layout, $userPage, $mine];
	}

	/** Can the ☆ in the side bar change the favourites? Admins: an editable layout; users: their start page (not view only). */
	public static function canFav(): bool
	{
		if (NuImpersonate::readOnly()) return false;
		if (NuAdmin::any()) return empty(NuAuth::user()['demo']) && (bool)NuHome::current()['editable'];   // demo: never the shared layout
		return true;
	}

	/**
	 * Favourites and recently used items: [key, label, icon, href|null, post app|null, color, newtab, fav, current, canFav].
	 * @return array{fav: list<array>, recent: list<array>, canFav: bool}
	 */
	public static function data(int $recentMax = 8): array
	{
		[$layout, $userPage, $mine] = self::startPage();
		$favList = array_key_exists('fav', $mine) ? (array)$mine['fav'] : (array)($layout['fav'] ?? []);
		$hide = (array)($mine['hide'] ?? []);
		$cur = self::currentKey();
		$canFav = self::canFav();
		$placed = [];                                          // tile key => colour of its group (the tiles of the start page)
		foreach ($layout['groups'] as $gi => $g) {
			if (!NuHome::condOk((array)($g['cond'] ?? []))) continue;
			$color = (string)($g['color'] ?? '') !== '' ? (string)$g['color'] : NuHome::GROUP_COLORS[$gi % count(NuHome::GROUP_COLORS)];
			foreach ($g['tiles'] as $k) if (!in_array($k, $hide, true)) $placed[(string)$k] = $color;
		}
		$item = static function (string $key) use ($layout, $favList, $cur, $canFav, $placed): ?array {
			if (str_starts_with($key, 'form:')) {
				$f = substr($key, 5);
				if (!isset(nu_forms()[$f]) || !NuAcl::can($f)) return null;
				$fd = nu_forms()[$f];                              // the form's description, as in its breadcrumbs (the code when it has none)
				$label = trim((string)$fd['description']) !== '' ? NuTranslate::text((string)$fd['description']) : (string)$fd['code'];
				return ['key' => $key, 'label' => $label, 'icon' => 'bi-window', 'href' => nu_url('form', ['f' => $f]), 'post' => null, 'color' => '',
					'newtab' => false, 'fav' => false, 'current' => $key === $cur, 'canFav' => false];
			}
			if (!NuHome::visible($key, $layout) || ($r = NuHome::resolve($key, $layout)) === null || $r['proc'] !== null) return null;
			if (!NuHome::condOk((array)($r['cond'] ?? []))) return null;
			return ['key' => $key, 'label' => (string)$r['label'], 'icon' => (string)$r['icon'], 'href' => $r['href'], 'post' => $r['post'],
				'color' => (string)$r['color'] !== '' ? (string)$r['color'] : ($placed[$key] ?? ''), 'newtab' => (bool)$r['newtab'],
				'fav' => in_array($key, $favList, true), 'current' => $key === $cur, 'canFav' => $canFav && isset($placed[$key])];
		};
		$fav = [];
		foreach (array_keys($placed) as $k) if (in_array($k, $favList, true) && ($it = $item($k)) !== null) $fav[] = $it;
		$recent = [];
		foreach (self::recentKeys() as $k) {
			if (count($recent) >= $recentMax) break;
			if (($it = $item($k)) !== null) $recent[] = $it;
		}
		return ['fav' => $fav, 'recent' => $recent, 'canFav' => $canFav];
	}

	/** ☆ in the side bar: a start page tile becomes a favourite or stops being one. @return ?bool the new state, null = not possible */
	public static function favToggle(string $key): ?bool
	{
		[$layout, $userPage, $mine] = self::startPage();
		if (!self::canFav()) return null;
		$placed = false;
		foreach ($layout['groups'] as $g) if (in_array($key, (array)$g['tiles'], true)) $placed = true;
		if (!$placed || !NuHome::visible($key, $layout)) return null;
		if ($userPage) {
			$fav = array_key_exists('fav', $mine) ? (array)$mine['fav'] : (array)($layout['fav'] ?? []);
			$on = !in_array($key, $fav, true);
			$fav = $on ? array_merge($fav, [$key]) : array_values(array_diff($fav, [$key]));
			return NuHome::setMine((string)NuHome::current()['id'], $fav, (array)($mine['hide'] ?? [])) ? $on : null;
		}
		$fav = (array)($layout['fav'] ?? []);
		$on = !in_array($key, $fav, true);
		$layout['fav'] = $on ? array_merge($fav, [$key]) : array_values(array_diff($fav, [$key]));
		$errors = NuHome::save($layout, (string)NuAuth::user()['login'], 'home_layout_saved', ['favourite' => $key, 'on' => $on, 'via' => 'side bar']);
		return $errors ? null : $on;
	}
}
