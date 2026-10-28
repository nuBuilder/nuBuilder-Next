<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Themes (0.8.632, Admin tools → Themes, pages/nuthemes.php): the look of the whole application – nuBuilder Next's pages and forms –
 * as a set of values (colours, corner radius, shadow, font, size, density, button / field / tab / list / top bar looks, form buttons).
 *   - Five built-in themes (BUILTIN, read-only, ids "b:<name>"); own themes in zzzzsys_nunext_theme (vals JSON {v: {...}, d: {...}}:
 *     v = values, d = colours for dark mode). An empty colour = nuBuilder Next's own (its dark mode stays as it is).
 *   - Which theme: setting 'theme' {default, levels: {access id: theme id}, user_choice, allowed: [ids]}; a user's own pick
 *     (sus_json NUNEXT_THEME, globeadmin: setting theme_ga, demo: session) when user_choice is on and the theme allowed.
 *   - Served as a stylesheet ?p=brand&a=theme&t=<id>&v=<rev> (public: sign-in pages use the default theme), after the branding CSS
 *     and before Global code CSS – own CSS still wins. Mostly CSS variables that nuapp.css / nuform.css read.
 *   - Editor preview: the draft values in the session (?p=brand&a=theme&t=draft).
 */
final class NuTheme
{
	public const TABLE = 'zzzzsys_nunext_theme';
	public const SETTING = 'theme';
	public const SETTING_GA = 'theme_ga';
	public const JSON = 'NUNEXT_THEME';

	/** Editable values: key => [group, type, options]. Types: color (light + dark), select (options => text key suffix), range [min, max]. */
	public const KEYS = [
		'accent'       => ['general', 'color'],
		'page_bg'      => ['general', 'color'],
		'surface'      => ['general', 'color'],
		'text'         => ['general', 'color'],
		'muted'        => ['general', 'color'],
		'border'       => ['general', 'color'],
		'radius'       => ['general', 'range', [0, 16]],
		'shadow'       => ['general', 'select', ['', 'none', 'light', 'medium']],
		'font'         => ['font', 'select', ['', 'system', 'segoe', 'arial', 'helvetica', 'verdana', 'tahoma', 'georgia', 'times', 'mono']],
		'font_size'    => ['font', 'range', [12, 16]],
		'density'      => ['font', 'select', ['', 'compact', 'comfortable']],
		'nav_bg'       => ['nav', 'color'],
		'nav_text'     => ['nav', 'color'],
		'btn_bg'       => ['buttons', 'color'],
		'btn_text'     => ['buttons', 'color'],
		'btn_border'   => ['buttons', 'color'],
		'btn_weight'   => ['buttons', 'select', ['', '400', '500', '600']],
		'input_bg'     => ['fields', 'color'],
		'input_text'   => ['fields', 'color'],
		'input_border' => ['fields', 'color'],
		'focus'        => ['fields', 'color'],
		'label'        => ['fields', 'color'],
		'label_weight' => ['fields', 'select', ['', '400', '500', '600']],
		'req'          => ['fields', 'color'],
		'tab_style'    => ['tabs', 'select', ['', 'boxes', 'pills']],
		'tab_text'     => ['tabs', 'color'],
		'tab_active'   => ['tabs', 'color'],
		'th_bg'        => ['lists', 'color'],
		'th_text'      => ['lists', 'color'],
		'row_hover'    => ['lists', 'color'],
		'stripe'       => ['lists', 'color'],
		'fbtn_bg'      => ['forms', 'color'],
		'fbtn_text'    => ['forms', 'color'],
		'f_label'      => ['forms', 'color'],
	];
	public const GROUPS = ['general', 'font', 'nav', 'buttons', 'fields', 'tabs', 'lists', 'forms'];
	public const FONTS = [
		'system' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
		'segoe' => '"Segoe UI", Tahoma, Geneva, Verdana, sans-serif', 'arial' => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
		'helvetica' => '"Helvetica Neue", Helvetica, Arial, sans-serif', 'verdana' => 'Verdana, Geneva, sans-serif',
		'tahoma' => 'Tahoma, Geneva, Verdana, sans-serif', 'georgia' => 'Georgia, "Times New Roman", serif',
		'times' => '"Times New Roman", Times, serif', 'mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
	];
	/** The five built-in themes: name text key theme_b_<id>, values, dark colours. */
	public const BUILTIN = [
		'next' => ['v' => [], 'd' => []],
		'forte' => ['v' => ['accent' => '#378bd5', 'page_bg' => '#f4f6f9', 'radius' => '3', 'shadow' => 'none', 'font' => 'segoe', 'font_size' => '13',
			'btn_bg' => '#f9fafb', 'btn_text' => '#1f2937', 'btn_border' => '#e5e7eb', 'btn_weight' => '500', 'tab_style' => 'boxes', 'tab_text' => '#333333',
			'tab_active' => '#2d84d1', 'th_bg' => '#f8f9fa', 'th_text' => '#4b5563', 'fbtn_bg' => '#378bd5', 'fbtn_text' => '#ffffff', 'label' => '#4b5563'],
			'd' => ['tab_text' => '#c4ccd4', 'btn_bg' => '#262c34', 'btn_text' => '#e5e7eb', 'btn_border' => '#3a4552', 'th_bg' => '#1c2027', 'th_text' => '#a7b0ba', 'label' => '#a7b0ba']],
		'corporate' => ['v' => ['accent' => '#1f4e8c', 'page_bg' => '#eef1f5', 'border' => '#cfd6df', 'radius' => '2', 'shadow' => 'none', 'font' => 'arial',
			'density' => 'compact', 'nav_bg' => '#1b365d', 'nav_text' => '#ffffff', 'btn_bg' => '#ffffff', 'btn_text' => '#1b365d', 'btn_border' => '#9fb0c6',
			'btn_weight' => '600', 'label_weight' => '600', 'th_bg' => '#e3e9f1', 'th_text' => '#1b365d', 'tab_active' => '#1f4e8c', 'fbtn_bg' => '#1f4e8c'],
			'd' => ['page_bg' => '#10151c', 'border' => '#334155', 'nav_bg' => '#0d1b2e', 'btn_bg' => '#1a2433', 'btn_text' => '#dbe4f0', 'btn_border' => '#3b4a5e',
				'th_bg' => '#18212d', 'th_text' => '#b9c7d8', 'tab_active' => '#7aa7e0']],
		'soft' => ['v' => ['accent' => '#6d5ce7', 'page_bg' => '#f6f5fb', 'border' => '#e4e1f2', 'radius' => '12', 'shadow' => 'medium', 'font' => 'system',
			'density' => 'comfortable', 'btn_bg' => '#efecfd', 'btn_text' => '#3d3280', 'btn_border' => '#e0dbfa', 'tab_style' => 'pills',
			'input_border' => '#dcd7f0', 'th_bg' => '#f6f5fb', 'th_text' => '#6b6590', 'row_hover' => '#f0edfd', 'fbtn_bg' => '#6d5ce7'],
			'd' => ['page_bg' => '#15131f', 'surface' => '#1e1b2b', 'border' => '#332e4a', 'btn_bg' => '#2a2540', 'btn_text' => '#d9d3ff', 'btn_border' => '#3d3660',
				'input_border' => '#3d3660', 'th_bg' => '#1e1b2b', 'th_text' => '#a9a2d0', 'row_hover' => '#2a2540']],
		'contrast' => ['v' => ['accent' => '#0040a8', 'page_bg' => '#ffffff', 'surface' => '#ffffff', 'text' => '#000000', 'muted' => '#333333', 'border' => '#000000',
			'radius' => '4', 'font' => 'verdana', 'font_size' => '16', 'nav_bg' => '#000000', 'nav_text' => '#ffffff', 'btn_bg' => '#ffffff', 'btn_text' => '#000000',
			'btn_border' => '#000000', 'btn_weight' => '600', 'input_border' => '#000000', 'focus' => '#ffbf00', 'label' => '#000000', 'label_weight' => '600',
			'req' => '#b00020', 'tab_text' => '#000000', 'tab_active' => '#0040a8', 'th_bg' => '#f0f0f0', 'th_text' => '#000000', 'row_hover' => '#fff3bf',
			'fbtn_bg' => '#0040a8', 'fbtn_text' => '#ffffff', 'f_label' => '#000000'],
			'd' => ['accent' => '#7fb2ff', 'page_bg' => '#000000', 'surface' => '#000000', 'text' => '#ffffff', 'muted' => '#e0e0e0', 'border' => '#ffffff',
				'nav_bg' => '#000000', 'btn_bg' => '#000000', 'btn_text' => '#ffffff', 'btn_border' => '#ffffff', 'input_border' => '#ffffff', 'label' => '#ffffff',
				'req' => '#ff8a80', 'tab_text' => '#ffffff', 'tab_active' => '#7fb2ff', 'th_bg' => '#1a1a1a', 'th_text' => '#ffffff', 'row_hover' => '#3a3000',
				'fbtn_bg' => '#7fb2ff', 'fbtn_text' => '#000000', 'f_label' => '#ffffff']],
	];

	/** nuBuilder Next's own colours (no theme) – light / dark, as the browser shows them (0.8.643): exports of a theme are complete with them. */
	public const DEFAULTS = [
		'v' => ['accent' => '#2f6fd6', 'page_bg' => '#f4f6f9', 'surface' => '#ffffff', 'text' => '#212529', 'muted' => '#595c5f', 'border' => '#dee2e6',
			'nav_bg' => '#ffffff', 'nav_text' => '#212529', 'btn_bg' => '#f8f9fa', 'btn_text' => '#212529', 'btn_border' => '#dee2e6',
			'input_bg' => '#ffffff', 'input_text' => '#212529', 'input_border' => '#dee2e6', 'focus' => '#0d6efd', 'label' => '#212529', 'req' => '#dc3545',
			'tab_text' => '#5c6670', 'tab_active' => '#2d84d1', 'th_bg' => '#ffffff', 'th_text' => '#6b7785', 'row_hover' => '#eef3fb', 'stripe' => '#f3f5f6',
			'fbtn_bg' => '#2f6fd6', 'fbtn_text' => '#ffffff', 'f_label' => '#212529'],
		'd' => ['accent' => '#2f6fd6', 'page_bg' => '#14171c', 'surface' => '#1c2027', 'text' => '#dee2e6', 'muted' => '#aeb2b6', 'border' => '#495057',
			'nav_bg' => '#1c2027', 'nav_text' => '#dee2e6', 'btn_bg' => '#2b3035', 'btn_text' => '#dee2e6', 'btn_border' => '#495057',
			'input_bg' => '#212529', 'input_text' => '#dee2e6', 'input_border' => '#495057', 'focus' => '#0d6efd', 'label' => '#dee2e6', 'req' => '#ff6b6b',
			'tab_text' => '#a7b0ba', 'tab_active' => '#5ea4e8', 'th_bg' => '#212529', 'th_text' => '#9aa5b1', 'row_hover' => '#222f41', 'stripe' => '#252a31',
			'fbtn_bg' => '#2f6fd6', 'fbtn_text' => '#ffffff', 'f_label' => '#dee2e6'],
	];

	/** The editor's preview page: the draft stylesheet instead of the current theme. */
	public static bool $preview = false;
	private static ?array $settings = null;
	private static ?array $own = null;
	private static ?string $current = null;

	// ------------------------------------------------------------------ themes

	public static function ensureTable(): void
	{
		NuSchema::ensure();
	}

	/** All themes: id => [id, name, builtin, base, v, d, updated_at, updated_by]. Built-in first. */
	public static function all(): array
	{
		$out = [];
		foreach (self::BUILTIN as $k => $t) $out['b:' . $k] = ['id' => 'b:' . $k, 'name' => t('theme_b_' . $k), 'builtin' => true, 'base' => '', 'v' => $t['v'], 'd' => $t['d'], 'updated_at' => '', 'updated_by' => ''];
		return $out + self::own();
	}

	private static function own(): array
	{
		if (self::$own !== null) return self::$own;
		$out = [];
		try {
			if (nu_db()->tableExists(self::TABLE)) {
				foreach (nu_db()->all('SELECT * FROM ' . self::TABLE . ' ORDER BY name') as $r) {
					$j = json_decode((string)$r['vals'], true) ?: [];
					[$v, $d] = self::clean((array)($j['v'] ?? []), (array)($j['d'] ?? []));
					$id = (string)$r[self::TABLE . '_id'];
					$out[$id] = ['id' => $id, 'name' => (string)$r['name'], 'builtin' => false, 'base' => (string)($r['base'] ?? ''), 'v' => $v, 'd' => $d,
						'updated_at' => (string)($r['updated_at'] ?? ''), 'updated_by' => (string)($r['updated_by'] ?? '')];
				}
			}
		} catch (Throwable) {}
		return self::$own = $out;
	}

	public static function get(string $id): ?array
	{
		return self::all()[$id] ?? null;
	}

	/** Only known keys with valid values; colours as #rrggbb. @return array{0: array, 1: array} */
	public static function clean(array $v, array $d): array
	{
		$ov = []; $od = [];
		foreach (self::KEYS as $k => $def) {
			$x = trim((string)($v[$k] ?? ''));
			if ($def[1] === 'color') {
				if (($c = NuBranding::hex($x)) !== '') $ov[$k] = $c;
				if (($c = NuBranding::hex(trim((string)($d[$k] ?? '')))) !== '') $od[$k] = $c;
			} elseif ($def[1] === 'select') {
				if ($x !== '' && in_array($x, $def[2], true)) $ov[$k] = $x;
			} elseif ($x !== '' && preg_match('/^\d{1,2}$/', $x) && (int)$x >= $def[2][0] && (int)$x <= $def[2][1]) {
				$ov[$k] = (string)(int)$x;
			}
		}
		return [$ov, $od];
	}

	/** Saves an own theme (new: $id ''). @return array{0: string, 1: array} id, errors */
	public static function save(string $id, string $name, array $v, array $d, string $base = ''): array
	{
		$name = trim($name);
		$errors = [];
		if ($name === '') $errors['name'] = t('err_required');
		elseif (mb_strlen($name) > 100) $errors['name'] = t('err_too_long', 100);
		if ($id !== '' && (str_starts_with($id, 'b:') || !isset(self::own()[$id]))) $errors['_'] = t('err_not_found');
		if ($errors) return ['', $errors];
		[$v, $d] = self::clean($v, $d);
		self::ensureTable();
		$db = nu_db();
		$row = ['name' => $name, 'vals' => json_encode(['v' => (object)$v, 'd' => (object)$d], JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s'),
			'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($id === '') {
			$id = nu_id();
			$db->insert(self::TABLE, [self::TABLE . '_id' => $id, 'base' => mb_substr($base, 0, 25)] + $row);
		} else {
			$db->update(self::TABLE, $row, self::TABLE . '_id', $id);
		}
		self::$own = null;
		self::bump();
		NuLog::write('theme_saved', self::TABLE, $id, ['name' => $name]);
		return [$id, []];
	}

	public static function delete(string $id): void
	{
		if (str_starts_with($id, 'b:') || !isset(self::own()[$id])) throw new RuntimeException(t('err_not_found'));
		$s = self::settings();
		if ($s['default'] === $id || in_array($id, $s['levels'], true)) throw new RuntimeException(t('theme_err_in_use'));
		nu_db()->delete(self::TABLE, self::TABLE . '_id', $id);
		$s['allowed'] = array_values(array_diff($s['allowed'], [$id]));
		self::saveSettings($s);
		self::$own = null;
		NuLog::write('theme_deleted', self::TABLE, $id);
	}

	/**
	 * Every colour of a theme, light and dark (0.8.643): the theme's own values, the rest as nuBuilder Next shows them. Colours that follow the
	 * accent (focus, active tab, form buttons) follow the theme's accent when it has one; the dark accent is the light one unless set.
	 * @return array{0: array, 1: array}
	 */
	public static function full(array $t): array
	{
		$v = $t['v']; $d = $t['d'];
		$accL = $v['accent'] ?? ''; $accD = $d['accent'] ?? $accL;
		$depL = $accL === '' ? [] : ['focus' => $accL, 'tab_active' => $accL, 'fbtn_bg' => $accL];
		$depD = $accD === '' ? [] : ['accent' => $accD, 'focus' => $accD, 'tab_active' => NuBranding::mix($accD, '#ffffff', .3), 'fbtn_bg' => $accD];
		foreach (self::KEYS as $k => $def) {
			if ($def[1] !== 'color') continue;
			if (!isset($v[$k])) $v[$k] = $depL[$k] ?? self::DEFAULTS['v'][$k];
			if (!isset($d[$k])) $d[$k] = $depD[$k] ?? self::DEFAULTS['d'][$k];
		}
		if (!isset($t['v']['fbtn_text'])) $v['fbtn_text'] = NuBranding::onColor($v['fbtn_bg']);
		if (!isset($t['d']['fbtn_text'])) $d['fbtn_text'] = NuBranding::onColor($d['fbtn_bg']);
		return [$v, $d];
	}

	/** Export as JSON (type nunext-theme): all colours (light + dark), the other values as far as the theme sets them. */
	public static function export(string $id): string
	{
		$t = self::get($id);
		if (!$t) throw new RuntimeException(t('err_not_found'));
		[$v, $d] = self::full($t);
		return json_encode(['type' => 'nunext-theme', 'version' => 1, 'name' => $t['name'], 'values' => (object)$v, 'dark' => (object)$d],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	}

	/** Import a JSON of export() as a new own theme. @return string id */
	public static function import(string $json): string
	{
		$j = json_decode(trim($json), true);
		if (!is_array($j) || ($j['type'] ?? '') !== 'nunext-theme' || !is_array($j['values'] ?? null)) throw new RuntimeException(t('theme_err_import'));
		$name = trim((string)($j['name'] ?? '')) ?: t('theme_imported');
		[$id, $err] = self::save('', mb_substr($name, 0, 100), (array)$j['values'], (array)($j['dark'] ?? []), 'import');
		if ($err) throw new RuntimeException(implode(' ', $err));
		return $id;
	}

	/** Step 0027: the table; an accent colour of Branding becomes the own theme "My theme", the default of everyone. */
	public static function migrateBranding(): void
	{
		NuSchema::create();
		$db = nu_db();
		$v = $db->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [NuBranding::SETTING]);
		$b = is_string($v) ? (json_decode($v, true) ?: []) : [];
		$accent = NuBranding::hex((string)($b['accent'] ?? ''));
		if ($accent === '') return;
		$id = nu_id();
		$db->insert(self::TABLE, [self::TABLE . '_id' => $id, 'name' => t('theme_my'), 'base' => 'b:next', 'vals' => json_encode(['v' => ['accent' => $accent], 'd' => (object)[]]),
			'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => 'nunext']);
		self::$own = null;
		self::saveSettings(['default' => $id] + self::settings());
		$b['accent'] = '';
		$b['rev'] = substr(md5(uniqid('', true)), 0, 8);
		$db->update('zzzzsys_nunext_setting', ['setting_value' => json_encode($b)], 'zzzzsys_nunext_setting_id', NuBranding::SETTING);
	}

	// ------------------------------------------------------------------ who uses which theme

	public static function settings(): array
	{
		if (self::$settings !== null) return self::$settings;
		$s = ['default' => 'b:next', 'levels' => [], 'user_choice' => false, 'allowed' => [], 'rev' => ''];
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$j = is_string($v) ? (json_decode($v, true) ?: []) : [];
			if (is_string($j['default'] ?? null)) $s['default'] = $j['default'];
			if (is_array($j['levels'] ?? null)) foreach ($j['levels'] as $a => $t) if (is_string($t) && $t !== '') $s['levels'][(string)$a] = $t;
			$s['user_choice'] = !empty($j['user_choice']);
			if (is_array($j['allowed'] ?? null)) $s['allowed'] = array_values(array_filter(array_map('strval', $j['allowed'])));
			$s['rev'] = (string)($j['rev'] ?? '');
		} catch (Throwable) {}
		return self::$settings = $s;
	}

	public static function saveSettings(array $s): void
	{
		$known = self::all();
		$out = ['default' => isset($known[(string)($s['default'] ?? '')]) ? (string)$s['default'] : 'b:next', 'levels' => [], 'user_choice' => !empty($s['user_choice']),
			'allowed' => array_values(array_intersect(array_map('strval', (array)($s['allowed'] ?? [])), array_keys($known))), 'rev' => substr(md5(uniqid('', true)), 0, 8)];
		foreach ((array)($s['levels'] ?? []) as $a => $t) if (is_string($t) && isset($known[$t])) $out['levels'][(string)$a] = $t;
		self::putSetting(self::SETTING, json_encode($out));
		self::$settings = null;
	}

	/** New stylesheet versions after a theme changed. */
	private static function bump(): void
	{
		$s = self::settings();
		self::saveSettings($s);
	}

	private static function putSetting(string $id, string $value): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', $id);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $id] + $row);
	}

	/** Themes a user may pick (user_choice on): the allowed ones; none ticked = all. Globeadmin: always all. */
	public static function choices(): array
	{
		$s = self::settings();
		$u = NuAuth::user();
		if ($u && !empty($u['globeadmin']) && empty($u['demo'])) return self::all();   // globeadmin: any theme for himself (0.8.643)
		if (!$s['user_choice']) return [];
		$all = self::all();
		$ids = $s['allowed'] ?: array_keys($all);
		return array_intersect_key($all, array_flip($ids));
	}

	/** The user's own pick ('' = none). */
	public static function userPick(): string
	{
		$u = NuAuth::user();
		if (!$u) return '';
		try {
			if (!empty($u['demo'])) return (string)($_SESSION['nu_theme'] ?? '');
			if ($u['globeadmin']) return (string)(nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING_GA]) ?? '');
			return (string)NuUserJson::get((string)$u['id'], self::JSON);
		} catch (Throwable) {
			return '';
		}
	}

	/** The user picks a theme ('' = back to the one of the access level / installation). */
	public static function pick(string $id): void
	{
		$u = NuAuth::user();
		if (!$u) return;
		if ($id !== '' && !isset(self::choices()[$id])) throw new RuntimeException(t('err_not_found'));
		if (!empty($u['demo'])) $_SESSION['nu_theme'] = $id;
		elseif ($u['globeadmin']) self::putSetting(self::SETTING_GA, $id);
		else NuUserJson::set((string)$u['id'], self::JSON, $id);
		self::$current = null;
	}

	/** Theme of this request: own pick (allowed) > the access level's > the installation's. */
	public static function current(): string
	{
		if (self::$current !== null) return self::$current;
		$s = self::settings();
		$id = $s['default'];
		try {
			$u = NuAuth::user();
			if ($u) {
				$acc = (string)($u['access_id'] ?? '');
				if ($acc !== '' && isset($s['levels'][$acc])) $id = $s['levels'][$acc];
				$p = self::userPick();
				if ($p !== '' && isset(self::choices()[$p])) $id = $p;
			}
		} catch (Throwable) {}
		if (!self::get($id)) $id = 'b:next';
		return self::$current = $id;
	}

	/** <link> of the current theme ('' for a theme without values). */
	public static function headTag(): string
	{
		if (NuBranding::$off) return '';
		if (self::$preview) return '<link rel="stylesheet" id="nuThemeCss" href="' . h(nu_url('brand', ['a' => 'theme', 't' => 'draft', 'v' => substr(md5(uniqid('', true)), 0, 8)])) . '">' . "\n\t";
		try {
			$id = self::current();
			$t = self::get($id);
			if (!$t || (!$t['v'] && !$t['d'])) return '';
			$v = substr(md5(self::settings()['rev'] . '|' . $t['updated_at'] . '|' . NU_VERSION . '|' . json_encode([$t['v'], $t['d']])), 0, 10);
			return '<link rel="stylesheet" id="nuThemeCss" href="' . h(nu_url('brand', ['a' => 'theme', 't' => $id, 'v' => $v])) . '">' . "\n\t";
		} catch (Throwable) {
			return '';
		}
	}

	/** Who uses a theme (default / access levels / allowed for users), for the list. */
	public static function usage(string $id): array
	{
		$s = self::settings();
		return ['default' => $s['default'] === $id, 'levels' => array_keys(array_filter($s['levels'], static fn($t) => $t === $id)),
			'allowed' => $s['user_choice'] && (in_array($id, $s['allowed'], true) || !$s['allowed'])];
	}

	// ------------------------------------------------------------------ CSS

	/** ?p=brand&a=theme&t=<id>|draft */
	public static function serve(string $id): never
	{
		header('Content-Type: text/css; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		if ($id === 'draft') {
			header('Cache-Control: no-store');
			$dr = $_SESSION['nu_theme_draft'] ?? null;
			echo is_array($dr) ? self::css((array)($dr['v'] ?? []), (array)($dr['d'] ?? [])) : '';
			exit;
		}
		header('Cache-Control: public, max-age=31536000, immutable');     // the URL carries the version
		$t = self::get($id);
		echo $t ? self::css($t['v'], $t['d']) : '';
		exit;
	}

	/** Contrast warnings: [text key of the pair, ratio, mode] for pairs below 4.5 : 1 (3 : 1 for buttons). */
	public static function warnings(array $v, array $d): array
	{
		$pairs = [['text', 'page_bg', 4.5], ['text', 'surface', 4.5], ['btn_text', 'btn_bg', 4.5], ['fbtn_text', 'fbtn_bg', 3], ['nav_text', 'nav_bg', 4.5],
			['th_text', 'th_bg', 4.5], ['input_text', 'input_bg', 4.5], ['label', 'surface', 4.5]];
		$out = [];
		foreach (['light' => $v, 'dark' => $d + $v] as $mode => $vals) {
			foreach ($pairs as [$a, $b, $min]) {
				if (empty($vals[$a]) || empty($vals[$b])) continue;
				if ($mode === 'dark' && empty($d[$a]) && empty($d[$b])) continue;
				$r = NuBranding::contrast($vals[$a], $vals[$b]);
				if ($r < $min) $out[] = [$a, $b, round($r, 1), $mode];
			}
		}
		return $out;
	}

	/** The stylesheet of a theme's values. */
	public static function css(array $v, array $d): string
	{
		[$v, $d] = self::clean($v, $d);
		$o = "/* nuBuilder Next – theme (Admin tools → Themes) */\n";
		if (!empty($v['accent'])) $o .= NuBranding::accentCss($v['accent']);
		if (!empty($d['accent'])) $o .= self::darkOnly(NuBranding::accentCss($d['accent']));
		$o .= ":root:not([data-bs-theme=\"dark\"]), [data-bs-theme=\"light\"] {\n" . self::vars($v, false) . "}\n";   // light mode only (not :root – it would win over the dark values of Bootstrap / nuapp.css); :not() = the specificity of nuapp.css's light-only rules
		if ($d) $o .= "[data-bs-theme=\"dark\"] {\n" . self::vars($d, true) . "}\n";
		$o .= self::rules($v, $d);
		return $o;
	}

	/** Only the dark parts of an accent stylesheet. */
	private static function darkOnly(string $css): string
	{
		$p = strpos($css, '[data-bs-theme="dark"]');
		if ($p === false) return '';
		$end = strpos($css, "}\n", $p);
		return substr($css, $p, $end - $p + 2);
	}

	/** CSS variables of the colours (one mode). */
	private static function vars(array $c, bool $dark): string
	{
		$o = '';
		$set = static function (string $name, string $val) use (&$o): void { $o .= "\t$name: $val;\n"; };
		$rgb = static fn(string $hex) => implode(', ', NuBranding::rgb($hex));
		if (!empty($c['page_bg'])) $set('--nu-page', $c['page_bg']);
		if (!empty($c['surface'])) { $set('--nu-surface', $c['surface']); $set('--bs-body-bg', $c['surface']); $set('--bs-body-bg-rgb', $rgb($c['surface'])); $set('--nu-th-bg', $c['surface']); }
		if (!empty($c['text'])) { $set('--bs-body-color', $c['text']); $set('--bs-body-color-rgb', $rgb($c['text'])); $set('--bs-emphasis-color', $c['text']); $set('--nu-tab-hover-color', $c['text']); }
		if (!empty($c['muted'])) { $set('--bs-secondary-color', $c['muted']); $set('--nu-tab-color', $c['muted']); }
		if (!empty($c['border'])) { $set('--bs-border-color', $c['border']); $set('--bs-border-color-translucent', $c['border']); $set('--nu-tab-line', $c['border']);
			$set('--nu-tab-border', $c['border']); $set('--nu-th-line', $c['border']); }
		if (!empty($c['nav_bg'])) $set('--nu-nav-bg', $c['nav_bg']);
		if (!empty($c['nav_text'])) $set('--nu-nav-text', $c['nav_text']);
		if (!empty($c['btn_bg'])) { $set('--nu-btn-bg', $c['btn_bg']); $set('--nu-btn-hover-bg', NuBranding::mix($c['btn_bg'], $dark ? '#ffffff' : '#000000', .07)); }
		if (!empty($c['btn_text'])) $set('--nu-btn-text', $c['btn_text']);
		if (!empty($c['btn_border'])) $set('--nu-btn-border', $c['btn_border']);
		if (!empty($c['input_bg'])) $set('--nu-input-bg', $c['input_bg']);
		if (!empty($c['input_text'])) $set('--nu-input-text', $c['input_text']);
		if (!empty($c['input_border'])) $set('--nu-input-border', $c['input_border']);
		if (!empty($c['focus'])) { $set('--nu-focus', $c['focus']); $set('--nu-focus-rgb', $rgb($c['focus'])); $set('--bs-focus-ring-color', 'rgba(' . $rgb($c['focus']) . ', .35)'); }
		if (!empty($c['label'])) $set('--nu-label', $c['label']);
		if (!empty($c['req'])) $set('--nu-mark-required', $c['req']);
		if (!empty($c['tab_text'])) $set('--nu-tab-color', $c['tab_text']);
		if (!empty($c['tab_active'])) { $set('--nu-tab-active', $c['tab_active']); $set('--nu-tab-active-color', $c['tab_active']); $set('--nu-tab-on', NuBranding::onColor($c['tab_active'])); }
		if (!empty($c['th_bg'])) $set('--nu-th-bg', $c['th_bg']);
		if (!empty($c['th_text'])) $set('--nu-th-color', $c['th_text']);
		if (!empty($c['row_hover'])) $set('--nu-row-hover', $c['row_hover']);
		if (!empty($c['stripe'])) $set('--nu-row-stripe', $c['stripe']);
		if (!empty($c['fbtn_bg'])) { $set('--nu-fbtn-bg', $c['fbtn_bg']); if (empty($c['fbtn_text'])) $set('--nu-fbtn-ink', NuBranding::onColor($c['fbtn_bg'])); }
		if (!empty($c['fbtn_text'])) $set('--nu-fbtn-ink', $c['fbtn_text']);
		if (!empty($c['f_label'])) $set('--nu-f-label', $c['f_label']);
		return $o;
	}

	/** Rules for the values that are not colours (and for the colour variables nuBuilder Next's CSS does not read itself). */
	private static function rules(array $v, array $d): string
	{
		$o = '';
		$label = !empty($v['label']) || !empty($d['label']);
		$fLabel = !empty($v['f_label']) || !empty($d['f_label']);
		// buttons of nuBuilder Next's pages (record / toolbar buttons .nuBtnAct) and nuBuilder Forte's nuActionButton
		$o .= ".btn.nuBtnAct { --bs-btn-color: var(--nu-btn-text, var(--bs-body-color)); --bs-btn-bg: var(--nu-btn-bg, var(--bs-tertiary-bg)); --bs-btn-border-color: var(--nu-btn-border, var(--bs-border-color));"
			. " --bs-btn-hover-color: var(--nu-btn-text, var(--bs-emphasis-color)); --bs-btn-hover-bg: var(--nu-btn-hover-bg, var(--bs-secondary-bg)); --bs-btn-hover-border-color: var(--nu-btn-border, var(--bs-border-color));"
			. " --bs-btn-active-color: var(--nu-btn-text, var(--bs-emphasis-color)); --bs-btn-active-bg: var(--nu-btn-hover-bg, var(--bs-secondary-bg)); --bs-btn-active-border-color: var(--nu-btn-border, var(--bs-border-color)); }\n";
		$o .= ".nuActionButton:where(:not(.btn)) { color: var(--nu-btn-text, var(--bs-body-color)); background-color: var(--nu-btn-bg, var(--bs-tertiary-bg)); border-color: var(--nu-btn-border, var(--bs-border-color)); }\n";
		// fields
		$o .= ".form-control, .form-select, .ts-wrapper .ts-control { background-color: var(--nu-input-bg, var(--bs-body-bg)); color: var(--nu-input-text, var(--bs-body-color)); border-color: var(--nu-input-border, var(--bs-border-color)); }\n";
		$o .= ".form-control:focus, .form-select:focus, .form-check-input:focus { border-color: var(--nu-focus, var(--bs-primary)); box-shadow: 0 0 0 .2rem rgba(var(--nu-focus-rgb, var(--bs-primary-rgb)), .3); }\n";
		if ($label) $o .= ".form-label, .form-check-label { color: var(--nu-label, var(--bs-body-color)); }\n.nuLabel, .nuLabelLeft, .nuLabelTop, .nuCell .form-label { color: var(--nu-label, var(--bs-secondary-color)); }\n";
		if ($fLabel) $o .= ".nuEditForm .nuLabel, .nuEditForm .nuLabelLeft, .nuEditForm .nuLabelTop, .nuEditForm .form-label { color: var(--nu-f-label, var(--nu-label, var(--bs-secondary-color))); }\n";
		// top bar
		$o .= ".nu-navbar { background: var(--nu-nav-bg, var(--nu-surface)); }\n";
		if (!empty($v['nav_text'])) $o .= ".nu-navbar, .nu-navbar .nav-link, .nu-navbar .nu-brand-title, .nu-navbar .navbar-brand { color: var(--nu-nav-text) !important; }\n"
			. ".nu-navbar .btn-outline-secondary { --bs-btn-color: var(--nu-nav-text); --bs-btn-border-color: color-mix(in srgb, var(--nu-nav-text) 40%, transparent); --bs-btn-hover-color: var(--nu-nav-text);"
			. " --bs-btn-hover-bg: color-mix(in srgb, var(--nu-nav-text) 15%, transparent); --bs-btn-hover-border-color: var(--nu-nav-text); --bs-btn-active-color: var(--nu-nav-text); --bs-btn-active-bg: color-mix(in srgb, var(--nu-nav-text) 20%, transparent); }\n"
			. ".nu-navbar .nav-link.active { background: color-mix(in srgb, var(--nu-nav-text) 15%, transparent); }\n.nu-navbar .navbar-toggler-icon { filter: invert(1) grayscale(1); }\n"
			. ".nu-navbar .btn, .nu-navbar .btn .bi { color: var(--nu-nav-text); }\n";
		// nuBuilder Next's logo on a dark top bar in light mode (or a light one in dark mode): the other version of the logo
		$logo = static fn(string $sel, bool $dark) => "$sel .nu-navbar .nu-brand .nu-brand-" . ($dark ? 'light' : 'dark') . " { display: none; }\n$sel .nu-navbar .nu-brand .nu-brand-" . ($dark ? 'dark' : 'light') . " { display: inline; }\n";
		if (!empty($v['nav_bg']) && NuBranding::luminance($v['nav_bg']) < .3) $o .= $logo(':root:not([data-bs-theme="dark"])', true);
		$dn = $d['nav_bg'] ?? '';
		if ($dn !== '' && NuBranding::luminance($dn) > .5) $o .= $logo('[data-bs-theme="dark"]', false);
		// form buttons
		$o .= ".btn.nuFormBtn:not([class*=\"btn-\"]) { --nu-btn-ink: var(--nu-fbtn-ink, var(--nu-on-accent, #fff)); --bs-btn-bg: var(--nu-fbtn-bg, var(--nu-accent)); --bs-btn-hover-bg: var(--nu-fbtn-bg, var(--nu-accent));"
			. " --bs-btn-active-bg: var(--nu-fbtn-bg, var(--nu-accent)); --bs-btn-disabled-bg: var(--nu-fbtn-bg, var(--nu-accent)); }\n";
		if (!empty($v['btn_weight'])) $o .= '.btn.nuBtnAct, .btn.nuFormBtn:not([class*="btn-"]), .nuActionButton:where(:not(.btn)) { --bs-btn-font-weight: ' . $v['btn_weight'] . '; font-weight: ' . $v['btn_weight'] . "; }\n";
		if (!empty($v['label_weight'])) $o .= '.form-label, .nuLabel, .form-check-label { font-weight: ' . $v['label_weight'] . "; }\n";
		// corners
		if (isset($v['radius'])) {
			$r = (int)$v['radius'];
			$o .= ":root { --bs-border-radius: {$r}px; --bs-border-radius-sm: " . max(0, $r - 2) . 'px; --bs-border-radius-lg: ' . ($r + 2) . 'px; --bs-border-radius-xl: ' . ($r + 4) . "px; }\n"
				. ".btn.nuBtnAct, .nuActionButton:where(:not(.btn)), .form-control, .form-select, .ts-wrapper .ts-control, .dropdown-menu { border-radius: {$r}px; }\n"
				. ".btn.nuFormBtn:not([class*=\"btn-\"]) { --bs-btn-border-radius: {$r}px; }\n.btn { --bs-btn-border-radius: {$r}px; }\n"
				. '.nuListCard, .nuEditForm, .card { border-radius: ' . ($r + 2) . 'px; --bs-card-border-radius: ' . ($r + 2) . 'px; --bs-card-inner-border-radius: ' . max(0, $r + 1) . "px; }\n"
				. '.nuListCard > .nuListHead { border-radius: ' . ($r + 2) . 'px ' . ($r + 2) . "px 0 0; }\n"
				. '.nuAbs .form-control, .nuAbs .form-select { border-radius: ' . min($r, 6) . "px; }\n";
		}
		// shadow
		if (($v['shadow'] ?? '') === 'none') $o .= ".shadow-sm, .shadow, .card.shadow-sm { box-shadow: none !important; }\n.btn.nuFormBtn:not([class*=\"btn-\"]) { box-shadow: none; }\n";
		if (($v['shadow'] ?? '') === 'light') $o .= ".shadow-sm, .card.shadow-sm { box-shadow: 0 1px 2px rgba(0, 0, 0, .05) !important; }\n";
		if (($v['shadow'] ?? '') === 'medium') $o .= ".shadow-sm, .card.shadow-sm { box-shadow: 0 .35rem 1rem rgba(15, 23, 42, .09) !important; }\n[data-bs-theme=\"dark\"] .shadow-sm { box-shadow: 0 .35rem 1rem rgba(0, 0, 0, .45) !important; }\n";
		// font
		if (!empty($v['font'])) $o .= ':root { --bs-body-font-family: ' . self::FONTS[$v['font']] . "; --bs-font-sans-serif: var(--bs-body-font-family); }\n";
		if (!empty($v['font_size'])) $o .= ':root { --bs-body-font-size: ' . $v['font_size'] . "px; }\n.btn, .form-control, .form-select { font-size: " . $v['font_size'] . "px; }\n"
			. '.btn.nuFormBtn:not([class*="btn-"]) { --bs-btn-font-size: ' . round((int)$v['font_size'] * .875, 1) . "px; }\n";
		// density
		if (($v['density'] ?? '') === 'compact') $o .= ".btn { --bs-btn-padding-y: .25rem; --bs-btn-padding-x: .6rem; }\n.form-control, .form-select { padding-top: .25rem; padding-bottom: .25rem; }\n"
			. ".table > :not(caption) > * > * { padding: .3rem .45rem; }\n.card-body { padding: .85rem; }\n";
		if (($v['density'] ?? '') === 'comfortable') $o .= ".btn { --bs-btn-padding-y: .5rem; --bs-btn-padding-x: 1rem; }\n.form-control, .form-select { padding-top: .55rem; padding-bottom: .55rem; }\n"
			. ".table > :not(caption) > * > * { padding: .7rem .75rem; }\n.card-body { padding: 1.5rem; }\n";
		// tabs
		if (($v['tab_style'] ?? '') === 'boxes') $o .= ".nav-tabs { gap: .15rem; }\n.nav-tabs .nav-link { border: 1px solid var(--nu-tab-border); border-bottom: 0; border-radius: var(--bs-border-radius-sm) var(--bs-border-radius-sm) 0 0; background: var(--nu-tab-hover-bg); margin-bottom: 0; }\n"
			. ".nav-tabs .nav-link:hover, .nav-tabs .nav-link:focus { background: var(--nu-tab-surface); }\n"
			. ".nav-tabs .nav-link.active, .nav-tabs .nav-item.show .nav-link { background: var(--nu-tab-surface); border-top: 3px solid var(--nu-tab-active); border-bottom: 1px solid var(--nu-tab-surface); margin-bottom: -1px; font-weight: 600; color: var(--nu-tab-active-color); }\n";
		if (($v['tab_style'] ?? '') === 'pills') $o .= ".nav-tabs { border-bottom: 0; gap: .35rem; }\n.nav-tabs .nav-link { border: 0; border-radius: 999px; padding: .4rem 1rem; }\n"
			. ".nav-tabs .nav-link:hover, .nav-tabs .nav-link:focus { background: var(--nu-tab-hover-bg); }\n"
			. ".nav-tabs .nav-link.active, .nav-tabs .nav-item.show .nav-link { background: var(--nu-tab-active); color: var(--nu-tab-on, #fff); border: 0; }\n";
		// lists: stripes only when a stripe colour is set (its rows already use --nu-row-stripe where nuBuilder Next stripes)
		return $o;
	}
}
