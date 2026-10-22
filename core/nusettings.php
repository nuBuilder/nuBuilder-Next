<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder's settings (Setup → Settings, table zzzzsys_config) – shared with nuBuilder.
 *
 *   cfg_setting  "$nuConfigX" = PHP variable of nuconfig.php, otherwise a JavaScript option nuUXOptions['nuX']
 *   cfg_type     1 text · 2 true/false · 3 number · 9 Ace theme (nuBuilder's code editor)
 *   cfg_effective 1 at once · 2 after a new login · 3 after restarting the browser (nuBuilder)
 *
 * With $nuConfigSettingsFromDB = true (nuconfig.php) the values of the table replace the ones of nuconfig.php, as in nuBuilder –
 * applied as typed values (nu_cfg(), window.nuUXOptions), never by eval. Import copies the values set in nuconfig.php into the
 * table: PHP variables are read by including the file, nuUXOptions['…'] = …; lines (also inside nuBuilder's $nuJSOptions) by a
 * regular expression – commented lines are ignored.
 */
final class NuSettings
{
	public const TABLE = 'zzzzsys_config';

	/**
	 * What each of nuBuilder's settings means for nuBuilder Next (0.8.279) – shown as a tag on the page, mainly for the developer:
	 *   'next' = nuBuilder Next reads it · 'open' = not built yet (only nuBuilder uses it for now) · 'na' = not needed (reason key
	 *   set_r_*: nuBuilder Next has its own solution or it is always on). Settings not listed (own rows) get no tag.
	 * Keep it up to date when a setting is implemented: move it to 'next'.
	 */
	public const STATUS = [
		// read by nuBuilder Next
		'nuDebugMode' => ['next', 'next'], '$nuConfigGlobeadminHome' => ['next', 'next'], 'nuHideTabTitleIfOnlyOne' => ['next', 'next'],
		'$nuConfigTitle' => ['next', 'next'], '$nuConfigIncludeApexCharts' => ['next', 'next'], '$nuConfig2FAAdmin' => ['next', 'next'],
		'$nuConfig2FAUser' => ['next', 'next'], '$nuConfigDemoSavingAllowedIds' => ['next', 'next'], '$nuConfigEnableDatabaseUpdate' => ['next', 'next'], '$nuConfigPrimaryDatabaseManagement' => ['next', 'next'],
		'$nuUseMd5PasswordHash' => ['next', 'next'], '$nuConfigHomeHooks' => ['next', 'next'],
		// not built yet
		'$nuConfigDBGlobeadminUsers' => ['open', 'open'], '$nuConfigUserAdditional1Label' => ['open', 'open'], '$nuConfigUserAdditional2Label' => ['open', 'open'],
		'$nuConfigUserCodeLabel' => ['open', 'open'], 'nuStickyHeaders' => ['open', 'open'], 'nuAutosizeBrowseColumns' => ['open', 'open'],
		'nuBrowsePaginationInfo' => ['open', 'open'], 'nuPrintButton' => ['open', 'open'], 'nuEditCloseAfterSave' => ['open', 'open'],
		'nuBrowserTabTitlePrefix' => ['open', 'open'], 'nuShowBrowserTabTitle' => ['open', 'open'], 'nuPreventButtonDblClick' => ['open', 'open'], 'nuShowJSErrors' => ['open', 'open'],
		'nuCalendarStartOfWeek' => ['open', 'open'], 'nuCalendarWeekNumbers' => ['open', 'open'], '$nuConfig2FAShowRememberMe' => ['open', 'open'],
		// not needed in nuBuilder Next
		'nuDebugIcon' => ['na', 'menu'], 'nuDatabaseIcon' => ['na', 'menu'], 'nuToolsIcon' => ['na', 'menu'], 'nuObjectsIcon' => ['na', 'menu'],
		'nuPHPIcon' => ['na', 'menu'], 'nuPropertiesIcon' => ['na', 'menu'], 'nuRefreshIcon' => ['na', 'menu'],
		'$nuConfigBackupLocation' => ['na', 'update'],
		'nuMobileView' => ['na', 'always'], 'nuShowBackButton' => ['na', 'always'], 'nuEnableBrowserBackButton' => ['na', 'always'],
		'nuShowURLPermaLink' => ['na', 'always'], 'nuShowLoggedInUser' => ['na', 'always'],
		'$nuConfigIncludeGoogleCharts' => ['na', 'auto'], '$nuConfigIncludeTinyMCE' => ['na', 'auto'],
		'$nuConfigTimeOut' => ['na', 'session'], '$nuConfigKeepSessionAlive' => ['na', 'keepalive'], '$nuConfigKeepSessionAliveInterval' => ['na', 'keepalive'], 
		'$nuConfig2FAFormID' => ['na', '2fa'], '$nuConfig2FATokenValidityTime' => ['na', '2fa'],
		'$nuConfigIsDemo' => ['next', 'next'], '$nuConfigDemoDBGlobeadminPassword' => ['next', 'next'], '$nuConfigDemoDBGlobeadminUsername' => ['next', 'next'],
		'nuAceTheme' => ['na', 'editor'], 'nuSelect2Theme' => ['na', 'editor'],
		'nuShowNuBuilderLink' => ['na', 'nub_ui'], 'nuShowPropertiesOnMiddleClick' => ['na', 'nub_ui'],
	];

	/** Never taken from the table: database access, keys and this switch itself. */
	private const PROTECTED = '/^nuConfig(DB(?!GlobeadminUsers$)|SecretKey$|SettingsFromDB$)/';

	/**
	 * nuBuilder Next's own rows in the settings table (0.8.282) – added when missing (update step 0005, "Create" on the page);
	 * nuBuilder shows them in its Settings too and ignores them.
	 */
	public const OWN = [
		['zzzzsys_config_id' => 'nunextprimarydbm', 'zzzzsys_setup_id' => '1', 'cfg_order' => 115, 'cfg_category' => 'Admin', 'cfg_title' => 'Primary Database Tool',
			'cfg_setting' => '$nuConfigPrimaryDatabaseManagement', 'cfg_value' => '',
			'cfg_description' => 'nuBuilder Next: phpMyAdmin or Adminer – opened by the Database buttons (the other one under ▾). Empty: phpMyAdmin when installed, else Adminer.',
			'cfg_type' => 1, 'cfg_effective' => '1'],
		['zzzzsys_config_id' => 'nunexthomehooks', 'zzzzsys_setup_id' => '1', 'cfg_order' => 116, 'cfg_category' => 'Admin', 'cfg_title' => 'Start Page Hooks',
			'cfg_setting' => '$nuConfigHomeHooks', 'cfg_value' => 'true',
			'cfg_description' => 'nuBuilder Next: false = the procedure nunext_home (PHP and JavaScript) does not run on the tile start page.',
			'cfg_type' => 2, 'cfg_effective' => '1'],
	];

	/** Adds the rows of OWN that are missing (by setting name). @return int rows added */
	public static function addOwn(?NuDB $db = null): int
	{
		$db ??= nu_db();
		if (!$db->tableExists(self::TABLE)) return 0;
		$settings = array_flip(array_map('strval', array_column($db->all('SELECT cfg_setting FROM ' . self::TABLE), 'cfg_setting')));
		$n = 0;
		foreach (self::OWN as $row) {
			if (isset($settings[$row['cfg_setting']]) || $db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE zzzzsys_config_id = ?', [$row['zzzzsys_config_id']])) continue;
			$db->insert(self::TABLE, $row);
			$n++;
		}
		return $n;
	}

	/** Suggestions for text settings (nuBuilder's datalists). */
	public const CHOICES = [
		'$nuConfigGlobeadminHome' => ['nuhome'],
		'$nuConfigPrimaryDatabaseManagement' => ['phpMyAdmin', 'Adminer'],
		'nuShowJSErrors'          => ['none', 'globeadmin', 'everyone'],
		'nuHideTabTitleIfOnlyOne' => ['none', 'globeadmin', 'user', 'everyone'],
		'nuEditCloseAfterSave'    => ['None', 'AllForms', 'UserForms', 'SystemForms'],
		'nuCalendarWeekNumbers'   => ['None', 'ISO 8601', 'Western traditional', 'Middle Eastern'],
		'nuSelect2Theme'          => ['default', 'classic'],
	];

	/** Ace themes for cfg_type 9: value => label, by group. */
	public const ACE_THEMES = [
		'Bright' => ['chrome' => 'Chrome', 'clouds' => 'Clouds', 'crimson_editor' => 'Crimson Editor', 'dawn' => 'Dawn', 'dreamweaver' => 'Dreamweaver',
			'eclipse' => 'Eclipse', 'github' => 'GitHub', 'iplastic' => 'IPlastic', 'solarized_light' => 'Solarized Light', 'textmate' => 'TextMate',
			'tomorrow' => 'Tomorrow', 'xcode' => 'XCode', 'kuroir' => 'Kuroir', 'katzenmilch' => 'KatzenMilch', 'sqlserver' => 'SQL Server'],
		'Dark' => ['ambiance' => 'Ambiance', 'chaos' => 'Chaos', 'clouds_midnight' => 'Clouds Midnight', 'dracula' => 'Dracula', 'cobalt' => 'Cobalt',
			'gruvbox' => 'Gruvbox', 'gob' => 'Green on Black', 'idle_fingers' => 'idle Fingers', 'kr_theme' => 'krTheme', 'merbivore' => 'Merbivore',
			'merbivore_soft' => 'Merbivore Soft', 'mono_industrial' => 'Mono Industrial', 'monokai' => 'Monokai', 'nord_dark' => 'Nord Dark',
			'one_dark' => 'One Dark', 'pastel_on_dark' => 'Pastel on dark', 'solarized_dark' => 'Solarized Dark', 'terminal' => 'Terminal',
			'tomorrow_night' => 'Tomorrow Night', 'tomorrow_night_blue' => 'Tomorrow Night Blue', 'tomorrow_night_bright' => 'Tomorrow Night Bright',
			'tomorrow_night_eighties' => 'Tomorrow Night 80s', 'twilight' => 'Twilight', 'vibrant_ink' => 'Vibrant Ink', 'github_dark' => 'GitHub Dark'],
	];

	private static ?array $cache = null;
	private static bool $loading = false;

	/** Why the table cannot be read ('' = it can) – shown to the globeadmin on the page. */
	public static string $problem = '';

	/** The table can be read: a direct query (does not depend on information_schema, its rights or name case). */
	public static function available(): bool
	{
		static $ok = null;
		if ($ok !== null) return $ok;
		try {
			nu_db()->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE 1 = 0');
			self::$problem = '';
			return $ok = true;
		} catch (Throwable $e) {
			self::$problem = $e->getMessage();
			error_log('nuBuilder Next settings: ' . $e->getMessage());
			return $ok = false;
		}
	}

	/**
	 * Creates the table like nuBuilder (when it is missing) and adds nuBuilder's default settings that are not there yet
	 * (install/settings_defaults.json, from nubuilder4.sql). Existing rows and values are never changed. @return int rows added
	 */
	public static function create(?NuDB $db = null): int
	{
		$db ??= nu_db();
		if (!$db->tableExists(self::TABLE)) {
			$sql = match ($db->type) {
				'mysql' => 'CREATE TABLE zzzzsys_config (zzzzsys_config_id varchar(25) NOT NULL PRIMARY KEY, zzzzsys_setup_id varchar(25) NOT NULL DEFAULT \'1\','
					. ' cfg_order int NULL, cfg_category varchar(50) NULL, cfg_title varchar(50) NULL, cfg_setting varchar(1000) NULL, cfg_value varchar(1000) NULL,'
					. ' cfg_description varchar(1000) NULL, cfg_type tinyint NOT NULL DEFAULT 1, cfg_effective varchar(1) NOT NULL DEFAULT \'1\', cfg_json mediumtext NULL)'
					. NuDDL::mysqlTableOptions('zzzzsys_object', $db),
				'sqlsrv' => 'CREATE TABLE zzzzsys_config (zzzzsys_config_id nvarchar(25) NOT NULL PRIMARY KEY, zzzzsys_setup_id nvarchar(25) NOT NULL DEFAULT \'1\','
					. ' cfg_order int NULL, cfg_category nvarchar(50) NULL, cfg_title nvarchar(50) NULL, cfg_setting nvarchar(1000) NULL, cfg_value nvarchar(1000) NULL,'
					. ' cfg_description nvarchar(1000) NULL, cfg_type tinyint NOT NULL DEFAULT 1, cfg_effective nvarchar(1) NOT NULL DEFAULT \'1\', cfg_json nvarchar(max) NULL)',
				default => 'CREATE TABLE zzzzsys_config (zzzzsys_config_id TEXT NOT NULL PRIMARY KEY, zzzzsys_setup_id TEXT NOT NULL DEFAULT \'1\', cfg_order INTEGER,'
					. ' cfg_category TEXT, cfg_title TEXT, cfg_setting TEXT, cfg_value TEXT, cfg_description TEXT, cfg_type INTEGER NOT NULL DEFAULT 1,'
					. ' cfg_effective TEXT NOT NULL DEFAULT \'1\', cfg_json TEXT)',
			};
			$db->run($sql);
		}
		$defaults = json_decode((string)@file_get_contents(NU_ROOT . '/install/settings_defaults.json'), true);
		if (!is_array($defaults)) return 0;
		$have = array_flip(array_map('strval', array_column($db->all('SELECT zzzzsys_config_id FROM ' . self::TABLE), 'zzzzsys_config_id')));
		$settings = array_flip(array_map('strval', array_column($db->all('SELECT cfg_setting FROM ' . self::TABLE), 'cfg_setting')));
		$n = 0;
		foreach ($defaults as $row) {
			if (!is_array($row) || isset($have[(string)$row['zzzzsys_config_id']]) || isset($settings[(string)$row['cfg_setting']])) continue;
			$db->insert(self::TABLE, $row + ['zzzzsys_setup_id' => '1']);
			$n++;
		}
		return $n + self::addOwn($db);
	}

	/** Name of the connected database (for the notice when the table is missing). */
	public static function database(): string
	{
		try {
			$db = nu_db();
			return (string)match ($db->type) {
				'mysql'  => $db->value('SELECT DATABASE()'),
				'sqlsrv' => $db->value('SELECT DB_NAME()'),
				default  => (string)nu_cfg('nuConfigDBName'),
			};
		} catch (Throwable) {
			return (string)nu_cfg('nuConfigDBName');
		}
	}

	/** $nuConfigSettingsFromDB in nuconfig.php (nuBuilder: off when not set). */
	public static function fromDb(): bool
	{
		return !empty(nu_config()['nuConfigSettingsFromDB']);
	}

	public static function rows(): array
	{
		return nu_db()->all('SELECT * FROM ' . self::TABLE . ' ORDER BY cfg_order, cfg_setting');
	}

	/** Text of the table → PHP / JS value, as nuBuilder's nuCleanConfigValue(): true / false, numbers, otherwise the text. */
	public static function typed(?string $v): mixed
	{
		$v = (string)$v;
		$l = strtolower(trim($v));
		if ($l === 'true' || $l === 'false') return $l === 'true';
		if (is_numeric($v)) return str_contains($v, '.') || stripos($v, 'e') !== false ? (float)$v : (int)$v;
		return $v;
	}

	/** @return array{php: array, js: array} values of the table for nu_cfg() and nuUXOptions (empty without $nuConfigSettingsFromDB). */
	public static function values(): array
	{
		if (self::$cache !== null) return self::$cache;
		if (self::$loading) return ['php' => [], 'js' => []];      // nu_cfg() while the table is read
		self::$loading = true;
		$out = ['php' => [], 'js' => []];
		try {
			if (self::fromDb() && self::available()) {
				foreach (nu_db()->all('SELECT cfg_setting, cfg_value FROM ' . self::TABLE . ' ORDER BY cfg_setting, cfg_order') as $r) {
					$s = trim((string)$r['cfg_setting']);
					if ($s === '') continue;
					if ($s[0] === '$') {
						$name = substr($s, 1);
						if (preg_match('/^\w+$/', $name) && !preg_match(self::PROTECTED, $name)) $out['php'][$name] = self::typed($r['cfg_value']);
					} elseif (preg_match('/^\w+$/', $s)) {
						$out['js'][$s] = self::typed($r['cfg_value']);
					}
				}
			}
		} catch (Throwable $e) {
			error_log('nuBuilder Next settings: ' . $e->getMessage());
		}
		self::$loading = false;
		return self::$cache = $out;
	}

	/** nuUXOptions for the form runtime: nuconfig.php ($nuJSOptions / nuUXOptions lines), replaced by the table when it is used. */
	public static function uxOptions(): array
	{
		$file = [];
		foreach (self::fileValues()['js'] as $k => $v) $file[$k] = self::typed($v);
		return self::values()['js'] + $file;
	}

	// ------------------------------------------------------------------ nuconfig.php → table (Import)

	/** @return array{php: array<string,string>, js: array<string,string>} values set in nuconfig.php, as the table stores them */
	public static function fileValues(): array
	{
		static $cache = null;
		if ($cache !== null) return $cache;
		$php = [];
		foreach (nu_config_file() as $k => $v) {
			if (is_bool($v)) $php[$k] = $v ? 'true' : 'false';
			elseif (is_int($v) || is_float($v) || is_string($v)) $php[$k] = (string)$v;
		}
		$js = [];
		$path = nu_config_path();
		$lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
		foreach ($lines as $line) {
			$t = ltrim($line);
			if (str_starts_with($t, '//') || str_starts_with($t, '#') || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
			// nuUXOptions['nuDebugIcon'] = true;   also $nuUXOptions[…] and inside the $nuJSOptions string
			if (preg_match('/\bnuUXOptions\s*\[\s*([\'"])(\w+)\1\s*\]\s*=\s*(\'[^\']*\'|"[^"]*"|[^;\r\n]*?)\s*;/', $t, $m)) {
				$v = trim($m[3]);
				if ($v !== '' && ($v[0] === "'" || $v[0] === '"')) $v = substr($v, 1, -1);
				$js[$m[2]] = $v;
			}
		}
		return $cache = ['php' => $php, 'js' => $js];
	}

	/** Copies the values of nuconfig.php into the table. @return array<string, array{0:string,1:string}> setting => [old, new] */
	public static function import(): array
	{
		$file = self::fileValues();
		$db = nu_db();
		$changed = [];
		foreach (self::rows() as $r) {
			$s = trim((string)$r['cfg_setting']);
			$new = $s !== '' && $s[0] === '$' ? ($file['php'][substr($s, 1)] ?? null) : ($file['js'][$s] ?? null);
			if ($new === null) continue;                                  // not set in nuconfig.php: unchanged
			if ($s === 'nuCalendarStartOfWeek') $new = strtr($new, ['Monday' => '1', 'Sunday' => '0']);
			$new = mb_substr($new, 0, 1000);
			$old = (string)($r['cfg_value'] ?? '');
			if ($new === $old) continue;
			$db->update(self::TABLE, ['cfg_value' => $new], 'zzzzsys_config_id', (string)$r['zzzzsys_config_id']);
			$changed[$s] = [$old, $new];
		}
		NuLog::write('settings_imported', self::TABLE, '', ['changed' => $changed]);
		self::$cache = null;
		return $changed;
	}

	// ------------------------------------------------------------------ page → table (Save)

	/**
	 * @param array<string,string> $values zzzzsys_config_id => value
	 * @return array{0: array, 1: array, 2: int} [changed setting => [old, new], errors id => message, highest cfg_effective of the changes]
	 */
	public static function save(array $values): array
	{
		$changed = $errors = $updates = [];
		$effective = 1;
		foreach (self::rows() as $r) {
			$id = (string)$r['zzzzsys_config_id'];
			if (!isset($values[$id]) || !is_string($values[$id])) continue;
			$new = trim($values[$id]);
			$old = (string)($r['cfg_value'] ?? '');
			if ($new === $old) continue;
			$err = self::check((int)$r['cfg_type'], $new);
			if ($err !== null) { $errors[$id] = $err; continue; }
			$updates[$id] = $new;
			$changed[(string)$r['cfg_setting']] = [$old, $new];
			$effective = max($effective, (int)$r['cfg_effective']);
		}
		if ($errors) return [[], $errors, 1];
		if ($updates) {
			nu_db()->transaction(static function (NuDB $db) use ($updates) {
				foreach ($updates as $id => $v) $db->update(self::TABLE, ['cfg_value' => $v], 'zzzzsys_config_id', $id);
			});
			NuLog::write('settings_changed', self::TABLE, '', ['changed' => $changed]);
			self::$cache = null;
		}
		return [$changed, [], $effective];
	}

	private static function check(int $type, string $v): ?string
	{
		if (mb_strlen($v) > 1000) return t('err_too_long', 1000);
		if ($type === 2 && !in_array($v, ['true', 'false'], true)) return t('set_err_bool');
		if ($type === 3 && $v !== '' && !preg_match('/^-?\d+$/', $v)) return t('set_err_number');
		if ($type === 9 && $v !== '' && !array_key_exists($v, array_merge(...array_values(self::ACE_THEMES)))) return t('set_err_theme');
		return null;
	}
}
